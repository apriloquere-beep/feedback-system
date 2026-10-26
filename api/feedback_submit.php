<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sentiment.php';
require_once __DIR__ . '/security/FeedbackEncryption.php';
require_once __DIR__ . '/security/RespondentType.php';
require_once __DIR__ . '/../includes/public_flow.php';

// $_SESSION['selected_language'] is the single source of truth for the
// active language across the whole project; see lang/resolve.php. This
// API's user-visible messages follow the respondent's selected language
// instead of always being English.
require __DIR__ . '/../lang/resolve.php';
$apiLang = $t;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => $apiLang['fb2_submitErrMethodNotAllowed']], 405);
}

if (!sfmsFeedbackJourneyStarted() || sfmsSelectedFeedbackRespondent() === '') {
    jsonResponse([
        'success' => false,
        'message' => 'Please begin from the feedback home page and select your user type.',
    ], 403);
}

function normalize_text($value): string
{
    $text = trim((string) $value);
    $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim(strip_tags($text));
}

function normalize_email($value): ?string
{
    $email = normalize_text($value);
    if ($email === '') {
        return null;
    }

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : null;
}

function normalize_bool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return (int) $value === 1;
    }

    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function auto_resolve_positive_feedback(PDO $pdo): bool
{
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'admin_auto_resolve_positive_feedback' LIMIT 1");
    $stmt->execute();
    return (string) $stmt->fetchColumn() === '1';
}

function normalize_iso_date($value): ?string
{
    $date = trim((string) $value);
    if ($date === '') {
        return null;
    }

    foreach (['!Y-m-d', '!m/d/Y'] as $format) {
        $parsed = DateTimeImmutable::createFromFormat($format, $date);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed instanceof DateTimeImmutable && ($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0) {
            return $parsed->format('Y-m-d');
        }
    }

    return null;
}

/**
 * Reserve the next Manila-date feedback reference while holding a MySQL
 * advisory lock. The caller must keep the lock until its transaction commits
 * so concurrent submissions cannot allocate the same daily sequence.
 *
 * @return array{reference_id: string, lock_name: string}
 */
function allocate_daily_reference_id(PDO $pdo): array
{
    $datePart = date('Ymd');
    $lockName = 'sfms_reference_PATFB_' . $datePart;

    $lockStmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 10)');
    $lockStmt->execute([':lock_name' => $lockName]);
    if ((int) $lockStmt->fetchColumn() !== 1) {
        throw new RuntimeException('Unable to reserve a feedback reference number.');
    }

    try {
        $pattern = '^PATFB-' . $datePart . '-[0-9]{4}$';
        $sequenceStmt = $pdo->prepare(
            'SELECT COALESCE(MAX(sequence_number), 0)
             FROM (
                 SELECT CAST(RIGHT(reference_id, 4) AS UNSIGNED) AS sequence_number
                 FROM feedbacks
                 WHERE reference_id REGEXP :feedbacks_pattern
                 UNION ALL
                 SELECT CAST(RIGHT(reference_id, 4) AS UNSIGNED) AS sequence_number
                 FROM feedback_entries
                 WHERE reference_id REGEXP :entries_pattern
             ) AS daily_references'
        );
        $sequenceStmt->execute([
            ':feedbacks_pattern' => $pattern,
            ':entries_pattern' => $pattern,
        ]);

        $nextSequence = (int) $sequenceStmt->fetchColumn() + 1;
        if ($nextSequence > 9999) {
            throw new RuntimeException('The daily feedback reference sequence is exhausted.');
        }

        return [
            'reference_id' => sprintf('PATFB-%s-%04d', $datePart, $nextSequence),
            'lock_name' => $lockName,
        ];
    } catch (Throwable $e) {
        release_reference_lock($pdo, $lockName);
        throw $e;
    }
}

function release_reference_lock(PDO $pdo, string $lockName): void
{
    try {
        $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $releaseStmt->execute([':lock_name' => $lockName]);
    } catch (Throwable $e) {
        // The connection closing at request end also releases advisory locks.
        error_log('[feedback_submit] Unable to explicitly release reference lock.');
    }
}

function decode_payload(): array
{
    $payload = readJsonBody();
    if (!$payload) {
        $payload = $_POST;
    }

    if (isset($payload['ratings']) && is_string($payload['ratings'])) {
        $decoded = json_decode($payload['ratings'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $payload['ratings'] = $decoded;
        }
    }

    return is_array($payload) ? $payload : [];
}

function average_rating(array $ratings): float
{
    $scores = [];
    foreach ($ratings as $value) {
        if (is_array($value) && isset($value['score'])) {
            $score = (float) $value['score'];
        } elseif (is_numeric($value)) {
            $score = (float) $value;
        } else {
            $score = 0.0;
        }

        if ($score >= 0.5 && $score <= 5) {
            $scores[] = $score;
        }
    }

    if (!$scores) {
        return 0.0;
    }

    return round(array_sum($scores) / count($scores), 1);
}

function rating_score_value(mixed $value): ?float
{
    if (is_array($value) && isset($value['score']) && is_numeric($value['score'])) {
        $score = round((float) $value['score'], 1);
        return ($score >= 0.5 && $score <= 5.0) ? $score : null;
    }

    if (is_numeric($value)) {
        $score = round((float) $value, 1);
        return ($score >= 0.5 && $score <= 5.0) ? $score : null;
    }

    return null;
}

// A required rating question is "answered" if it has a valid numeric score
// OR the respondent explicitly chose N/A — N/A is a legitimate answer in
// the UI, not a missing one. This intentionally does NOT give N/A a
// numeric value; rating_score_value() (and everything downstream that
// averages/stores scores) is untouched, so N/A still correctly contributes
// nothing to rating calculations.
function rating_is_answered(mixed $value): bool
{
    if (rating_score_value($value) !== null) {
        return true;
    }

    return is_string($value) && strtoupper(trim($value)) === 'N/A';
}

function required_rating_keys(PDO $pdo): array
{
    $serviceKeys = [
        'sqd4', 'a2', 'a3', 'a4', 'sqd3', 'sqd2', 'a7', 'sqd1',
        'b9', 'b10', 'b11', 'b12', 'b13', 'sqd8', 'sqd5',
    ];
    $responseCultureKeys = ['c17', 'c18', 'sqd6', 'sqd0'];
    $staffKeys = $pdo
        ->query('SELECT role FROM staff WHERE is_active = 1 ORDER BY LENGTH(role), role')
        ->fetchAll(PDO::FETCH_COLUMN);
    if (!$staffKeys) {
        $staffKeys = ['st0', 'st1', 'st2', 'st3', 'st4', 'st5', 'st6', 'st7', 'st8', 'st9'];
    }

    return array_values(array_unique(array_merge($serviceKeys, array_map('strval', $staffKeys), $responseCultureKeys)));
}

// SFMS is service-only: a submitted service resolves to a catalog row by
// name alone, with no Department to go through first. This intentionally
// never references departments/department_id, so it keeps working
// unchanged whether or not those still exist on the live services table.
function resolve_service(PDO $pdo, ?int $requestedId): ?array
{
    if ($requestedId === null || $requestedId < 1) return null;
    $stmt = $pdo->prepare("SELECT id, name FROM services WHERE id = :id AND status = 'active' LIMIT 1");
    $stmt->execute([':id' => $requestedId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : ['id' => (int) $row['id'], 'name' => (string) $row['name']];
}

function resolve_subservice(PDO $pdo, ?int $serviceId, mixed $requestedId): ?array
{
    if ($serviceId === null || $requestedId === null || $requestedId === '') {
        return null;
    }

    $subserviceStmt = $pdo->prepare(
        "SELECT id, name FROM service_subtypes WHERE id = :id AND service_id = :service_id AND status = 'active' LIMIT 1"
    );
    $subserviceStmt->execute([
        ':id' => (int) $requestedId,
        ':service_id' => $serviceId,
    ]);
    $result = $subserviceStmt->fetch(PDO::FETCH_ASSOC);
    return $result === false ? null : ['id' => (int) $result['id'], 'name' => (string) $result['name']];
}

function service_has_subservices(PDO $pdo, int $serviceId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM service_subtypes WHERE service_id = :service_id AND status = 'active' LIMIT 1"
    );
    $stmt->execute([':service_id' => $serviceId]);
    return (bool) $stmt->fetchColumn();
}

function unique_reference_exists(PDO $pdo, string $referenceId): bool
{
    foreach (['feedbacks', 'feedback_entries'] as $table) {
        $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE reference_id = :reference_id LIMIT 1");
        $stmt->execute([':reference_id' => $referenceId]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }

    return false;
}

function table_has_column(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name
         LIMIT 1'
    );
    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);
    return (bool) $stmt->fetchColumn();
}

/**
 * @param array<string, ?string> $values
 * @return array<string, ?string>
 */
function encrypt_submission_fields(
    FeedbackEncryption $encryption,
    string $table,
    string $referenceId,
    array $values
): array {
    $encrypted = [];
    foreach ($values as $field => $value) {
        $encrypted[$field . '_enc'] = $encryption->encryptNullable(
            $value,
            new EncryptionContext($table, $referenceId, $field)
        );
    }
    return $encrypted;
}

$referenceLockName = null;

try {
    $payload = decode_payload();
    $pdo = getPdo();

    $respondentTypeRaw = normalize_text($payload['respondentType'] ?? $payload['resp_type'] ?? $payload['respondent_type'] ?? '');
    $respondentType = RespondentType::selectedLabel($respondentTypeRaw);
    if ($respondentType === null) {
        jsonResponse(['success' => false, 'message' => $apiLang['fb2_submitErrIncomplete']], 422);
    }
    $respondentTypeOther = $respondentType === RespondentType::OTHER
        ? normalize_text($payload['resp_type_other'] ?? $payload['respondent_type_other'] ?? '')
        : '';
    if ($respondentType === RespondentType::OTHER && $respondentTypeOther === '') {
        jsonResponse(['success' => false, 'message' => $apiLang['fb2_submitErrIncomplete']], 422);
    }

    // Source remains the legacy form pipeline flag; respondent_type is the
    // authoritative four-choice user type.
    $source = $respondentType === RespondentType::BUSINESS ? 'visitor' : 'patient';

    $userType = $source;
    $feedbackType = normalize_text($payload['feedbackType'] ?? 'general') ?: 'general';

    $requestedServiceId = is_numeric($payload['service_id'] ?? null) ? (int) $payload['service_id'] : null;
    $requestedSubtypeId = is_numeric($payload['service_subtype_id'] ?? null) ? (int) $payload['service_subtype_id'] : null;
    $serviceOther = normalize_text($payload['service_other'] ?? $payload['serviceOther'] ?? '');
    $isCustomService = false;
    $service = '';

    // Demographic fields (form/feedback.php Section 1: edad/sex/gender_pref/
    // relihiyon/freq) — required on the form but previously never reached
    // this endpoint (payload had no keys for them). genderPreference and
    // religion accept a free-text "other" companion, merged the same way
    // respondentType/service already are above.
    $age = null;
    if (is_numeric($payload['age'] ?? null)) {
        $ageInt = (int) round((float) $payload['age']);
        if ($ageInt >= 1 && $ageInt <= 99) {
            $age = $ageInt;
        }
    }

    $sex = normalize_text($payload['sex'] ?? '');

    $genderPreference = normalize_text($payload['gender_preference'] ?? $payload['gender_pref'] ?? '');
    $genderPreferenceOther = normalize_text($payload['gender_preference_other'] ?? $payload['gender_pref_other'] ?? '');
    $genderPreferenceIsOther = in_array(strtolower($genderPreference), ['other', 'others', 'uban pa'], true);
    if ($genderPreferenceIsOther && $genderPreferenceOther === '') {
        jsonResponse(['success' => false, 'message' => $apiLang['fb2_submitErrIncomplete']], 422);
    }
    if ($genderPreferenceIsOther) {
        $genderPreference = $genderPreferenceOther;
    }

    $religion = normalize_text($payload['religion'] ?? $payload['relihiyon'] ?? '');
    $religionOther = normalize_text($payload['religion_other'] ?? $payload['relihiyon_other'] ?? '');
    $religionIsOther = in_array(strtolower($religion), ['other', 'others', 'uban pa'], true);
    if ($religionIsOther && $religionOther === '') {
        jsonResponse(['success' => false, 'message' => $apiLang['fb2_submitErrIncomplete']], 422);
    }
    if ($religionIsOther) {
        $religion = $religionOther;
    }

    $visitFrequency = normalize_text($payload['visit_frequency'] ?? $payload['freq'] ?? '');

    // Education ("educ") — same previously-missing-from-payload bug as the
    // five fields above. No "other" option on this select, so no merge step.
    $educationLevel = normalize_text($payload['education_level'] ?? $payload['educ'] ?? '');
    $facility = normalize_text($payload['facility'] ?? '');
    $cc1 = normalize_text($payload['cc1'] ?? '');
    $cc2 = normalize_text($payload['cc2'] ?? '');
    $cc3 = normalize_text($payload['cc3'] ?? '');
    if (!in_array($cc1, ['1', '2', '3', '4'], true)) {
        jsonResponse(['success' => false, 'message' => $apiLang['fb2_submitErrIncomplete']], 422);
    }
    if ($cc1 === '4') {
        $cc2 = 'N/A';
        $cc3 = 'N/A';
    } elseif (!in_array($cc2, ['Sayon', 'Murag', 'Lisod', 'Dili', 'N/A'], true)
        || !in_array($cc3, ['Dako', 'Gamay', 'Walay', 'N/A'], true)
    ) {
        jsonResponse(['success' => false, 'message' => $apiLang['fb2_submitErrIncomplete']], 422);
    }

    // Required for patient/visitor sources, matching how the form marks
    // them required; reuses the existing "incomplete form" message rather
    // than adding new translation strings for each field.
    if (in_array($source, ['patient', 'visitor'], true)
        && ($age === null || $sex === '' || $genderPreference === '' || $religion === '' || $visitFrequency === '' || $educationLevel === '')
    ) {
        jsonResponse([
            'success' => false,
            'message' => $apiLang['fb2_submitErrIncomplete'],
        ], 422);
    }

    $feedback = normalize_text($payload['feedback'] ?? $payload['comment'] ?? '');
    $subject = normalize_text($payload['subject'] ?? '');
    $suggestions = normalize_text($payload['suggestions'] ?? $payload['suggestion'] ?? '');
    $staffRecognition = normalize_text($payload['appreciation'] ?? $payload['staff_recognition'] ?? $payload['recognition'] ?? $payload['pasalamat'] ?? '');
    if (mb_strlen($feedback, 'UTF-8') > 300) {
        jsonResponse(['success' => false, 'message' => $apiLang['fb2_submitErrCommentTooLong']], 422);
    }
    if (mb_strlen($staffRecognition, 'UTF-8') > 150) {
        jsonResponse(['success' => false, 'message' => $apiLang['fb2_submitErrStaffTooLong']], 422);
    }
    if (mb_strlen($suggestions, 'UTF-8') > 200) {
        jsonResponse(['success' => false, 'message' => $apiLang['fb2_submitErrSuggestionTooLong']], 422);
    }
    $consultationDate = normalize_iso_date($payload['consultation_date'] ?? $payload['consultationDate'] ?? '');
    if ($consultationDate === null) {
        jsonResponse(['success' => false, 'message' => $apiLang['fb2_submitErrInvalidDate']], 422);
    }

    $visitDate = $consultationDate;

    // SFMS is service-only: the survey (form/feedback.php) has no
    // Department question, only Service.
    if ($requestedServiceId === null || $requestedServiceId < 1) {
        jsonResponse(['success' => false, 'message' => $apiLang['fb2_submitErrDeptServiceRequired']], 422);
    }

    $consentGiven = normalize_bool($payload['consent'] ?? false);
    if (!$consentGiven) {
        jsonResponse(['success' => false, 'message' => $apiLang['fb2_submitErrConsentRequired']], 422);
    }

    $firstName = normalize_text($payload['firstName'] ?? '');
    $lastName = normalize_text($payload['lastName'] ?? '');
    $fullName = trim($firstName . ' ' . $lastName);
    $fullName = $fullName !== '' ? $fullName : null;
    $email = normalize_email($payload['email'] ?? null);
    $contactNumber = normalize_text($payload['phone'] ?? '');
    $contactNumber = $contactNumber !== '' ? $contactNumber : null;

    if (!empty($payload['email']) && $email === null) {
        jsonResponse(['success' => false, 'message' => $apiLang['fb2_submitErrInvalidEmail']], 422);
    }

    $ratings = $payload['ratings'] ?? [];
    if (!is_array($ratings)) {
        jsonResponse(['success' => false, 'message' => $apiLang['fb2_submitErrRatingsInvalid']], 422);
    }

    $requiredRatingKeys = required_rating_keys($pdo);

    // Defensive check: if no ratings were submitted at all, reject for patient/visitor sources
    // This prevents form POSTs (e.g. when JS is disabled) from bypassing required rating groups.
    if (empty($ratings) && in_array($source, ['patient', 'visitor'], true)) {
        jsonResponse([
            'success' => false,
            'message' => $apiLang['fb2_submitErrRatingsRequired']
        ], 422);
    }

    foreach ($requiredRatingKeys as $ratingKey) {
        if (!array_key_exists($ratingKey, $ratings) || !rating_is_answered($ratings[$ratingKey])) {
            jsonResponse([
                'success' => false,
                'message' => $apiLang['fb2_submitErrIncomplete'],
            ], 422);
        }
    }

    $rating = isset($payload['rating']) && is_numeric($payload['rating'])
        ? round(max(0.5, min(5, (float) $payload['rating'])), 1)
        : average_rating($ratings);

    $analysisText = trim(implode(' ', array_filter([$feedback, $suggestions], static fn(string $value): bool => $value !== '')));
    $sentiment = analyzeSentiment($analysisText);
    $sentimentLabel = $sentiment['label'] ?? 'Neutral';
    $sentimentScore = isset($sentiment['score']) ? (float) $sentiment['score'] : 0.0;
    $priority = computePriority($sentimentLabel, $rating);
    $status = computeDefaultStatus($sentimentLabel, false);
    if ($sentimentLabel === 'Positive' && auto_resolve_positive_feedback($pdo)) {
        $status = 'resolved';
    }

    $catalogService = resolve_service($pdo, $requestedServiceId);
    if ($catalogService === null) {
        jsonResponse(['success' => false, 'message' => 'Please select a valid service.'], 422);
    }
    $serviceId = $catalogService['id'];
    $service = $catalogService['name'];
    $isCustomService = $service === 'Others';
    if ($isCustomService && $serviceOther === '') {
        jsonResponse(['success' => false, 'message' => $apiLang['fb2_submitErrCustomServiceRequired']], 422);
    }
    $serviceSubtype = resolve_subservice($pdo, $serviceId, $requestedSubtypeId);
    $serviceSubtypeId = $serviceSubtype['id'] ?? null;
    if ($requestedSubtypeId === null && service_has_subservices($pdo, $serviceId)) {
        jsonResponse(['success' => false, 'message' => 'Please select a specific service.'], 422);
    }
    if ($requestedSubtypeId !== null && $serviceSubtypeId === null) {
        jsonResponse(['success' => false, 'message' => 'Please select a valid specific service.'], 422);
    }
    $serviceSubtypeOther = normalize_text($payload['service_subtype_other'] ?? '');
    if (($serviceSubtype['name'] ?? '') === 'Other' && $serviceSubtypeOther === '') {
        jsonResponse(['success' => false, 'message' => 'Please specify the selected service.'], 422);
    }

    $pdo->beginTransaction();

    $referenceAllocation = allocate_daily_reference_id($pdo);
    $referenceId = $referenceAllocation['reference_id'];
    $referenceLockName = $referenceAllocation['lock_name'];

    $ratingsJson = json_encode($ratings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $createdAt = date('Y-m-d H:i:s');
    $encryption = new FeedbackEncryption();

    // Sentiment analysis above intentionally runs before this encryption boundary.
    $feedbacksEncrypted = encrypt_submission_fields($encryption, 'feedbacks', $referenceId, [
        'respondent_type' => $respondentType !== '' ? $respondentType : null,
        'respondent_type_other' => $respondentTypeOther !== '' ? $respondentTypeOther : null,
        'age' => $age !== null ? (string) $age : null,
        'sex' => $sex !== '' ? $sex : null,
        'gender_preference' => $genderPreference !== '' ? $genderPreference : null,
        'gender_preference_other' => $genderPreferenceOther !== '' ? $genderPreferenceOther : null,
        'religion' => $religion !== '' ? $religion : null,
        'religion_other' => $religionOther !== '' ? $religionOther : null,
        'visit_frequency' => $visitFrequency !== '' ? $visitFrequency : null,
        'education_level' => $educationLevel !== '' ? $educationLevel : null,
        'full_name' => $fullName,
        'email' => $email,
        'contact_number' => $contactNumber,
        'service_subtype_other' => $serviceSubtypeOther !== '' ? $serviceSubtypeOther : null,
        'service_other' => $isCustomService ? $serviceOther : null,
        'staff_name' => null,
        'subject' => $subject !== '' ? $subject : null,
        'feedback' => $feedback,
        'suggestions' => $suggestions !== '' ? $suggestions : null,
        'appreciation' => $staffRecognition !== '' ? $staffRecognition : null,
    ]);
    $legacyEncrypted = encrypt_submission_fields($encryption, 'feedback_entries', $referenceId, [
        'gender_preference_other' => $genderPreferenceOther !== '' ? $genderPreferenceOther : null,
        'religion_other' => $religionOther !== '' ? $religionOther : null,
        'service_subtype_other' => $serviceSubtypeOther !== '' ? $serviceSubtypeOther : null,
        'service_other' => $isCustomService ? $serviceOther : null,
        'staff_name' => null,
        'subject' => $subject !== '' ? $subject : null,
        'feedback' => $feedback,
        'suggestions' => $suggestions !== '' ? $suggestions : null,
        'appreciation' => $staffRecognition !== '' ? $staffRecognition : null,
    ]);

    $feedbacksHasCustomColumn = table_has_column($pdo, 'feedbacks', 'is_custom_service');
    $feedbacksHasServiceOtherColumn = table_has_column($pdo, 'feedbacks', 'service_other');
    $feedbacksHasSubtypeOtherColumn = table_has_column($pdo, 'feedbacks', 'service_subtype_other');
    $feedbacksHasRespondentTypeColumn = table_has_column($pdo, 'feedbacks', 'respondent_type');
    $feedbacksHasRespondentTypeOtherColumn = table_has_column($pdo, 'feedbacks', 'respondent_type_other');
    // All five demographic columns are added together by
    // database/migrations/007_add_demographic_fields.sql, so checking one
    // (age) is a reliable proxy for all — avoids five separate SHOW COLUMNS
    // lookups per request.
    $feedbacksHasDemographicColumns = table_has_column($pdo, 'feedbacks', 'age');
    // Added separately by migration 008 — checked independently since a
    // deployment could have migration 007 applied without 008 (or vice versa).
    $feedbacksHasEducationColumn = table_has_column($pdo, 'feedbacks', 'education_level');
    $feedbacksHasDetailColumns = table_has_column($pdo, 'feedbacks', 'facility');
    $feedbacksColumns = [
        'reference_id', 'source', 'user_type', 'is_anonymous', 'feedback_type',
        'full_name', 'email', 'contact_number', 'service_id', 'service_subtype_id',
        'service', 'visit_date', 'subject', 'feedback',
        'suggestions', 'appreciation', 'rating', 'ratings_json', 'sentiment_label', 'sentiment_score',
        'sentiment', 'confidence', 'priority', 'status', 'submitted_at',
    ];
    $feedbacksColumns = array_merge($feedbacksColumns, array_keys($feedbacksEncrypted));
    if ($feedbacksHasCustomColumn) {
        $feedbacksColumns[] = 'is_custom_service';
    }
    if ($feedbacksHasSubtypeOtherColumn) {
        $feedbacksColumns[] = 'service_subtype_other';
    }
    if ($feedbacksHasServiceOtherColumn) {
        $feedbacksColumns[] = 'service_other';
    }
    if ($feedbacksHasRespondentTypeColumn) {
        $feedbacksColumns[] = 'respondent_type';
    }
    if ($feedbacksHasRespondentTypeOtherColumn) {
        $feedbacksColumns[] = 'respondent_type_other';
    }
    if ($feedbacksHasDemographicColumns) {
        $feedbacksColumns = array_merge($feedbacksColumns, ['age', 'sex', 'gender_preference', 'religion', 'visit_frequency']);
    }
    if ($feedbacksHasEducationColumn) {
        $feedbacksColumns[] = 'education_level';
    }
    if ($feedbacksHasDetailColumns) {
        $feedbacksColumns = array_merge($feedbacksColumns, [
            'gender_preference_other', 'religion_other', 'facility', 'cc1', 'cc2', 'cc3',
        ]);
    }
    $feedbacksValues = array_map(static fn(string $column): string => ':' . $column, $feedbacksColumns);
    $feedbacksStmt = $pdo->prepare(
        'INSERT INTO feedbacks (' . implode(', ', $feedbacksColumns) . ') VALUES (' . implode(', ', $feedbacksValues) . ')'
    );

    $feedbacksParams = [
        ':reference_id' => $referenceId,
        ':source' => $source,
        ':user_type' => $userType,
        ':is_anonymous' => 0,
        ':feedback_type' => $feedbackType,
        ':full_name' => null,
        ':email' => null,
        ':contact_number' => null,
        ':service_id' => $serviceId,
        ':service_subtype_id' => $serviceSubtypeId,
        ':service' => $service,
        ':visit_date' => $visitDate,
        ':subject' => null,
        // This legacy plaintext column is NOT NULL in the current schema.
        ':feedback' => '',
        ':suggestions' => null,
        ':appreciation' => null,
        ':rating' => $rating,
        ':ratings_json' => $ratingsJson,
        ':sentiment_label' => $sentimentLabel,
        ':sentiment_score' => $sentimentScore,
        ':sentiment' => $sentimentLabel,
        ':confidence' => $sentimentScore,
        ':priority' => $priority,
        ':status' => $status,
        ':submitted_at' => $createdAt,
    ];
    foreach ($feedbacksEncrypted as $column => $envelope) {
        $feedbacksParams[':' . $column] = $envelope;
    }
    if ($feedbacksHasCustomColumn) {
        $feedbacksParams[':is_custom_service'] = $isCustomService ? 1 : 0;
    }
    if ($feedbacksHasSubtypeOtherColumn) {
        $feedbacksParams[':service_subtype_other'] = null;
    }
    if ($feedbacksHasServiceOtherColumn) {
        $feedbacksParams[':service_other'] = null;
    }
    if ($feedbacksHasRespondentTypeColumn) {
        $feedbacksParams[':respondent_type'] = null;
    }
    if ($feedbacksHasRespondentTypeOtherColumn) {
        $feedbacksParams[':respondent_type_other'] = null;
    }
    if ($feedbacksHasDemographicColumns) {
        $feedbacksParams[':age'] = null;
        $feedbacksParams[':sex'] = null;
        $feedbacksParams[':gender_preference'] = null;
        $feedbacksParams[':religion'] = null;
        $feedbacksParams[':visit_frequency'] = null;
    }
    if ($feedbacksHasEducationColumn) {
        $feedbacksParams[':education_level'] = null;
    }
    if ($feedbacksHasDetailColumns) {
        $feedbacksParams[':gender_preference_other'] = null;
        $feedbacksParams[':religion_other'] = null;
        $feedbacksParams[':facility'] = $facility !== '' ? $facility : null;
        $feedbacksParams[':cc1'] = $cc1 !== '' ? $cc1 : null;
        $feedbacksParams[':cc2'] = $cc2 !== '' ? $cc2 : null;
        $feedbacksParams[':cc3'] = $cc3 !== '' ? $cc3 : null;
    }
    $feedbacksStmt->execute($feedbacksParams);

    $legacyStatus = in_array($status, ['pending', 'verified', 'resolved', 'adjusted'], true) ? $status : 'pending';
    $legacyHasCustomColumn = table_has_column($pdo, 'feedback_entries', 'is_custom_service');
    $legacyHasServiceOtherColumn = table_has_column($pdo, 'feedback_entries', 'service_other');
    $legacyHasSubtypeOtherColumn = table_has_column($pdo, 'feedback_entries', 'service_subtype_other');
    $legacyHasRespondentTypeColumn = table_has_column($pdo, 'feedback_entries', 'respondent_type');
    $legacyHasDetailColumns = table_has_column($pdo, 'feedback_entries', 'facility');
    $legacyColumns = [
        'reference_id', 'user_type', 'is_anonymous', 'feedback_type',
        'service_id', 'service_subtype_id',
        'service', 'visit_date',
        'subject', 'feedback', 'suggestions', 'appreciation',
        'ratings', 'status', 'priority',
        'sentiment_label', 'sentiment_score', 'sentiment', 'confidence',
        'created_at',
    ];
    $legacyColumns = array_merge($legacyColumns, array_keys($legacyEncrypted));
    if ($legacyHasCustomColumn) {
        $legacyColumns[] = 'is_custom_service';
    }
    if ($legacyHasSubtypeOtherColumn) {
        $legacyColumns[] = 'service_subtype_other';
    }
    if ($legacyHasServiceOtherColumn) {
        $legacyColumns[] = 'service_other';
    }
    if ($legacyHasRespondentTypeColumn) {
        $legacyColumns[] = 'respondent_type';
    }
    if ($legacyHasDetailColumns) {
        $legacyColumns = array_merge($legacyColumns, [
            'gender_preference_other', 'religion_other', 'facility', 'cc1', 'cc2', 'cc3',
        ]);
    }
    $legacyValues = array_map(static fn(string $column): string => ':' . $column, $legacyColumns);
    $legacyStmt = $pdo->prepare(
        'INSERT INTO feedback_entries (' . implode(', ', $legacyColumns) . ') VALUES (' . implode(', ', $legacyValues) . ')'
    );

    $legacyStmt->bindValue(':reference_id', $referenceId);
    $legacyStmt->bindValue(':user_type', $userType);
    $legacyStmt->bindValue(':is_anonymous', 0, PDO::PARAM_INT);
    $legacyStmt->bindValue(':feedback_type', $feedbackType);
    $legacyStmt->bindValue(':service_id', $serviceId);
    $legacyStmt->bindValue(':service_subtype_id', $serviceSubtypeId);
    $legacyStmt->bindValue(':service', $service);
    $legacyStmt->bindValue(':visit_date', $visitDate);
    $legacyStmt->bindValue(':subject', null);
    $legacyStmt->bindValue(':feedback', null);
    $legacyStmt->bindValue(':suggestions', null);
    $legacyStmt->bindValue(':appreciation', null);
    foreach ($legacyEncrypted as $column => $envelope) {
        $legacyStmt->bindValue(':' . $column, $envelope);
    }
    if ($legacyHasRespondentTypeColumn) {
        $legacyStmt->bindValue(':respondent_type', $respondentType !== '' ? $respondentType : null);
    }
    if ($legacyHasDetailColumns) {
        $legacyStmt->bindValue(':gender_preference_other', null);
        $legacyStmt->bindValue(':religion_other', null);
        $legacyStmt->bindValue(':facility', $facility !== '' ? $facility : null);
        $legacyStmt->bindValue(':cc1', $cc1 !== '' ? $cc1 : null);
        $legacyStmt->bindValue(':cc2', $cc2 !== '' ? $cc2 : null);
        $legacyStmt->bindValue(':cc3', $cc3 !== '' ? $cc3 : null);
    }
    $legacyStmt->bindValue(':ratings', $ratingsJson);
    $legacyStmt->bindValue(':status', $legacyStatus);
    $legacyStmt->bindValue(':priority', $priority);
    $legacyStmt->bindValue(':sentiment_label', $sentimentLabel);
    $legacyStmt->bindValue(':sentiment_score', $sentimentScore);
    $legacyStmt->bindValue(':sentiment', $sentimentLabel);
    $legacyStmt->bindValue(':confidence', $sentimentScore);
    $legacyStmt->bindValue(':created_at', $createdAt);
    if ($legacyHasCustomColumn) {
        $legacyStmt->bindValue(':is_custom_service', $isCustomService ? 1 : 0, PDO::PARAM_INT);
    }
    if ($legacyHasSubtypeOtherColumn) {
        $legacyStmt->bindValue(':service_subtype_other', null);
    }
    if ($legacyHasServiceOtherColumn) {
        $legacyStmt->bindValue(':service_other', null);
    }
    $legacyStmt->execute();

    $notificationStmt = $pdo->prepare(
        'INSERT INTO notifications (type, title, message, reference_id, is_read, created_by)
         VALUES (:type, :title, :message, :reference_id, 0, :created_by)'
    );
    $notificationStmt->execute([
        ':type' => $priority === 'High' ? 'warning' : 'info',
        ':title' => 'New feedback submitted',
        // Do not duplicate sensitive free text into the plaintext notification table.
        ':message' => trim(sprintf('%s · %s', $service, 'General Feedback')),
        ':reference_id' => $referenceId,
        ':created_by' => $respondentType,
    ]);

    $pdo->commit();
    release_reference_lock($pdo, $referenceLockName);
    $referenceLockName = null;

    // Bind the optional receipt-email request to this browser session and to
    // this exact newly-created record. The copy endpoint must never be an
    // arbitrary-reference mail relay.
    $_SESSION['feedback_copy_reference_id'] = $referenceId;
    sfmsCompleteFeedbackJourney();

    jsonResponse([
        'success' => true,
        'message' => $apiLang['fb2_submitSuccess'],
        'data' => [
            'referenceId' => $referenceId,
            'source' => $source,
            'rating' => $rating,
            'sentimentLabel' => $sentimentLabel,
            'sentimentScore' => $sentimentScore,
            'priority' => $priority,
            'status' => $status,
            'isCustomService' => $isCustomService,
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (isset($pdo) && $pdo instanceof PDO && is_string($referenceLockName)) {
        release_reference_lock($pdo, $referenceLockName);
        $referenceLockName = null;
    }

    error_log(sprintf('[feedback_submit] %s at line %d', get_class($e), $e->getLine()));

    jsonResponse([
        'success' => false,
        'message' => $apiLang['fb2_submitErrGeneric'] ?? 'Unable to save feedback right now. Please try again.',
    ], 500);
}
