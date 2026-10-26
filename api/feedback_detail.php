<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sentiment.php';
require_once __DIR__ . '/security/FeedbackDecryption.php';
require_once __DIR__ . '/security/RespondentType.php';
require_once __DIR__ . '/security/AdminSecurity.php';

sfmsStartSecureSession();

if (!isset($_SESSION['admin_id'])) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$referenceId = trim((string) ($_GET['referenceId'] ?? ''));
if ($referenceId === '') {
    jsonResponse(['success' => false, 'message' => 'referenceId is required'], 422);
}

// Same 29-key map used by feedback_submitted.php / api/send_feedback_copy.php
// for feedback2.php's flat rating-key scheme. The admin dashboard is
// English-only by design, so labels are always pulled from lang/english.php
// (no session-based language switching here).
const FB2_RATING_KEY_MAP = [
    'sqd4' => 'fb2_ratA_sqd4', 'a2' => 'fb2_ratA_a2', 'a3' => 'fb2_ratA_a3', 'a4' => 'fb2_ratA_a4',
    'sqd3' => 'fb2_ratA_sqd3', 'sqd2' => 'fb2_ratA_sqd2', 'a7' => 'fb2_ratA_a7', 'sqd1' => 'fb2_ratA_sqd1',
    'b9' => 'fb2_ratB_b9', 'b10' => 'fb2_ratB_b10', 'b11' => 'fb2_ratB_b11', 'b12' => 'fb2_ratB_b12',
    'b13' => 'fb2_ratB_b13', 'sqd8' => 'fb2_ratB_sqd8', 'sqd5' => 'fb2_ratB_sqd5',
    'st0' => 'fb2_staff_st0', 'st1' => 'fb2_staff_st1', 'st2' => 'fb2_staff_st2', 'st3' => 'fb2_staff_st3',
    'st4' => 'fb2_staff_st4', 'st5' => 'fb2_staff_st5', 'st6' => 'fb2_staff_st6', 'st7' => 'fb2_staff_st7',
    'st8' => 'fb2_staff_st8', 'st9' => 'fb2_staff_st9',
    'c17' => 'fb2_ratC_c17', 'c18' => 'fb2_ratC_c18', 'sqd6' => 'fb2_ratC_sqd6', 'sqd0' => 'fb2_ratC_sqd0',
];

// Older patient_feedback.php/visitor_feedback.php pipeline only stores three
// aggregate scores under these generic keys (no itemized questions).
const LEGACY_RATING_LABELS = [
    'service' => 'Service Rating',
    'staff' => 'Staff Rating',
    'facility' => 'Facility Rating',
];

/**
 * The public form deliberately keeps fixed option values stable while its
 * labels switch languages. Admin details are always English, so convert only
 * those known option values here. Free-text fields never pass through this
 * function and remain exactly as submitted.
 */
function standardizeAdminChoice(string $field, ?string $value): ?string
{
    if ($field === 'respondentType') {
        return RespondentType::label($value);
    }
    if ($value === null || trim($value) === '') {
        return null;
    }

    $maps = [
        'sex' => [
            'Babaye' => 'Female', 'Babae' => 'Female', 'Female' => 'Female', '♀ Babaye' => 'Female', '♀ Female' => 'Female',
            'Lalaki' => 'Male', 'Lalake' => 'Male', 'Male' => 'Male', '♂ Lalaki' => 'Male', '♂ Male' => 'Male',
        ],
        'genderPreference' => [
            'Babaye' => 'Female', 'Babae' => 'Female', 'Female' => 'Female',
            'Lalaki' => 'Male', 'Lalake' => 'Male', 'Male' => 'Male',
        ],
        'religion' => [
            'Kristiyano' => 'Christian', 'Christian' => 'Christian', 'Iglesia Ni Cristo' => 'Iglesia Ni Cristo',
            'Islam' => 'Islam', 'Roman Catholic' => 'Roman Catholic', 'None' => 'None',
            'Wala' => 'None', 'Walang Relihiyon' => 'None',
        ],
        'educationLevel' => [
            'Elementarya' => 'Elementary', 'Elementary' => 'Elementary',
            'Highskol' => 'High School', 'Hayskul' => 'High School', 'High School' => 'High School',
            'Kolehiyo' => 'College', 'College' => 'College',
            'Bokasyonal' => 'Vocational', 'Vocational' => 'Vocational',
            'Post-graduate' => 'Post-graduate',
            'No Formal Education' => 'No Formal Education', 'Walang Pormal na Edukasyon' => 'No Formal Education',
        ],
        'visitFrequency' => [
            'Never' => 'Never', 'Wala gyud' => 'Never', 'Hindi pa kailanman' => 'Never',
            '1-3x' => '1–3x / year', '1–3x / year' => '1–3x / year',
            '4-6x' => '4–6x / year', '4–6x / year' => '4–6x / year',
            '7-12x' => '7–12x / year', '7–12x / year' => '7–12x / year',
            '>12x' => '>12x / year', '>12x / year' => '>12x / year',
        ],
        'cc1' => [
            '1' => "I know what a CC is and I saw this office/agency's CC.",
            '2' => "I know what a CC is but I did NOT see this office's CC.",
            '3' => "I learned of the CC only when I saw this office's CC.",
            '4' => 'I do not know what a CC is and I did not see one in this office.',
        ],
        'cc2' => [
            'Sayon' => 'Easy to see', 'Madaling makita' => 'Easy to see',
            'Murag' => 'Somewhat easy to see', 'Medyo madaling makita' => 'Somewhat easy to see',
            'Lisod' => 'Difficult to see', 'Mahirap makita' => 'Difficult to see',
            'Dili' => 'Not visible at all', 'Hindi nakikita' => 'Not visible at all',
            'N/A' => 'N/A',
        ],
        'cc3' => [
            'Dako' => 'Helped very much', 'Sobrang nakatulong' => 'Helped very much',
            'Gamay' => 'Somewhat helped', 'Medyo nakatulong' => 'Somewhat helped',
            'Walay' => 'Did not help at all', 'Hindi nakatulong kahit kaunti' => 'Did not help at all',
            'N/A' => 'N/A',
        ],
    ];

    $normalized = trim($value);
    return $maps[$field][$normalized] ?? $normalized;
}

function buildRatingBreakdown(array $ratings, array $lang): array
{
    $breakdown = [];
    foreach ($ratings as $key => $value) {
        $keyString = (string) $key;
        if (isset(FB2_RATING_KEY_MAP[$keyString])) {
            $label = $lang[FB2_RATING_KEY_MAP[$keyString]] ?? $keyString;
        } elseif (isset(LEGACY_RATING_LABELS[$keyString])) {
            $label = LEGACY_RATING_LABELS[$keyString];
        } else {
            $label = ucwords(str_replace(['_', '-'], ' ', $keyString));
        }

        // The oldest rating shape (pre-feedback2.php) stores a list of scores
        // per group (e.g. "service":[4,3,4,4,4]) instead of one value; average
        // those the same way api/feedback_json.php's decodeRatings() does.
        if (is_array($value)) {
            $numeric = array_values(array_filter(
                array_map(static fn ($item): float => is_numeric($item) ? (float) $item : 0.0, $value),
                static fn (float $item): bool => $item > 0
            ));
            $isNA = false;
            $displayValue = $numeric !== [] ? round(array_sum($numeric) / count($numeric), 1) : 'N/A';
        } else {
            $isNA = is_string($value) && strtoupper(trim($value)) === 'N/A';
            $displayValue = $isNA ? 'N/A' : $value;
        }

        $breakdown[] = [
            'key' => $keyString,
            'label' => $label,
            'value' => $displayValue,
            'isNA' => $isNA || $displayValue === 'N/A',
        ];
    }
    return $breakdown;
}

try {
    $pdo = getPdo();
    $lang = require __DIR__ . '/../lang/english.php';
    require_once __DIR__ . '/staff_role_labels.php';
    applyStaffRoleLabelOverrides($lang);

    // feedback_entries is the workflow/status table (status updates and
    // archiving write here). feedbacks is the newer, fuller table populated
    // by feedback2.php (respondent_type, scalar rating, submitter identity)
    // that feedback_entries does not have. Both are written together for
    // every submission and share reference_id, so a LEFT JOIN is safe here
    // and does not affect the authoritative status/workflow columns on fe.*.
    $stmt = $pdo->prepare(
        'SELECT fe.id, fe.reference_id AS referenceId, fe.user_type AS userType, fe.is_anonymous AS isAnonymous, fe.feedback_type AS feedbackType,
            COALESCE(s.name, fe.service) AS service, ss.name AS subservice,
            COALESCE(fb.service_other, fe.service_other) AS serviceOther, fb.service_other_enc AS serviceOtherEnc,
            COALESCE(fb.service_subtype_other, fe.service_subtype_other) AS serviceSubtypeOther, fb.service_subtype_other_enc AS serviceSubtypeOtherEnc,
            COALESCE(fb.facility, fe.facility) AS facility,
            COALESCE(fb.gender_preference_other, fe.gender_preference_other) AS genderPreferenceOther, fb.gender_preference_other_enc AS genderPreferenceOtherEnc,
            COALESCE(fb.religion_other, fe.religion_other) AS religionOther, fb.religion_other_enc AS religionOtherEnc,
            COALESCE(fb.cc1, fe.cc1) AS cc1, COALESCE(fb.cc2, fe.cc2) AS cc2, COALESCE(fb.cc3, fe.cc3) AS cc3,
            DATE_FORMAT(fe.visit_date, "%Y-%m-%d") AS visitDate,
            fe.subject, fe.subject_enc AS subjectEnc,
            fe.feedback, fe.feedback_enc AS feedbackEnc,
            fe.suggestions, fe.suggestions_enc AS suggestionsEnc,
            fe.appreciation, fe.appreciation_enc AS appreciationEnc,
            fe.ratings AS ratings, fe.status,
            fe.sentiment_label AS sentimentLabel, fe.sentiment_score AS sentimentScore,
                DATE_FORMAT(fe.created_at, "%Y-%m-%dT%H:%i:%s") AS timestamp, DATE_FORMAT(fe.updated_at, "%Y-%m-%dT%H:%i:%s") AS updatedAt,
            fb.respondent_type AS respondentType, fb.respondent_type_enc AS respondentTypeEnc, fb.rating AS rating,
            fb.respondent_type_other AS respondentTypeOther, fb.respondent_type_other_enc AS respondentTypeOtherEnc,
            fb.full_name AS fullName, fb.full_name_enc AS fullNameEnc,
            fb.email AS email, fb.email_enc AS emailEnc,
            fb.contact_number AS contactNumber, fb.contact_number_enc AS contactNumberEnc,
            fb.age AS age, fb.age_enc AS ageEnc,
            fb.sex AS sex, fb.sex_enc AS sexEnc,
            fb.gender_preference AS genderPreference, fb.gender_preference_enc AS genderPreferenceEnc,
            fb.religion AS religion, fb.religion_enc AS religionEnc,
            fb.visit_frequency AS visitFrequency, fb.visit_frequency_enc AS visitFrequencyEnc,
            fb.education_level AS educationLevel, fb.education_level_enc AS educationLevelEnc
         FROM feedback_entries fe
         LEFT JOIN services s ON s.id = fe.service_id
         LEFT JOIN service_subtypes ss ON ss.id = fe.service_subtype_id
         LEFT JOIN feedbacks fb ON fb.reference_id = fe.reference_id
         WHERE fe.reference_id = :reference_id
         LIMIT 1'
    );
    $stmt->execute([':reference_id' => $referenceId]);
    $row = $stmt->fetch();
    if (!$row) {
        jsonResponse(['success' => false, 'message' => 'Feedback not found'], 404);
    }

    $decryption = new FeedbackDecryption();
    $row = $decryption->map($row, 'feedback_entries', $referenceId, [
        'subject' => ['subjectEnc', 'subject'],
        'feedback' => ['feedbackEnc', 'feedback'],
        'suggestions' => ['suggestionsEnc', 'suggestions'],
        'appreciation' => ['appreciationEnc', 'appreciation'],
    ]);
    $row = $decryption->map($row, 'feedbacks', $referenceId, [
        'serviceOther' => ['serviceOtherEnc', 'serviceOther', 'service_other'],
        'serviceSubtypeOther' => ['serviceSubtypeOtherEnc', 'serviceSubtypeOther', 'service_subtype_other'],
        'genderPreferenceOther' => ['genderPreferenceOtherEnc', 'genderPreferenceOther', 'gender_preference_other'],
        'religionOther' => ['religionOtherEnc', 'religionOther', 'religion_other'],
        'respondentType' => ['respondentTypeEnc', 'respondentType', 'respondent_type'],
        'respondentTypeOther' => ['respondentTypeOtherEnc', 'respondentTypeOther', 'respondent_type_other'],
        'fullName' => ['fullNameEnc', 'fullName', 'full_name'],
        'email' => ['emailEnc', 'email'],
        'contactNumber' => ['contactNumberEnc', 'contactNumber', 'contact_number'],
        'age' => ['ageEnc', 'age'],
        'sex' => ['sexEnc', 'sex'],
        'genderPreference' => ['genderPreferenceEnc', 'genderPreference', 'gender_preference'],
        'religion' => ['religionEnc', 'religion'],
        'visitFrequency' => ['visitFrequencyEnc', 'visitFrequency', 'visit_frequency'],
        'educationLevel' => ['educationLevelEnc', 'educationLevel', 'education_level'],
    ]);

    // Fetch audit trail for this feedback (if any).
    // audit_trail has no old_status/new_status columns, so status transitions
    // are recorded in `notes` (see api/feedback_update_status.php) and parsed
    // back out here for display / the "Undo" affordance.
    $auditStmt = $pdo->prepare(
        'SELECT id, action, notes, user_name AS userName, created_at AS timestamp
         FROM audit_trail
         WHERE feedback_id = :feedback_id
         ORDER BY created_at ASC'
    );
    $auditStmt->execute([':feedback_id' => (int) $row['id']]);
    $audits = array_map(function ($a) {
        $oldStatus = null;
        $newStatus = null;
        if (preg_match('/^Status changed from (\w+) to (\w+)/', (string) ($a['notes'] ?? ''), $matches)) {
            $oldStatus = $matches[1];
            $newStatus = $matches[2];
        }
        return [
            'id' => (int) $a['id'],
            'action' => (string) $a['action'],
            'oldStatus' => $oldStatus,
            'newStatus' => $newStatus,
            'notes' => $a['notes'] ?? null,
            'user' => $a['userName'] ?? null,
            'timestamp' => date('c', strtotime((string) $a['timestamp'] ?? '')),
        ];
    }, $auditStmt->fetchAll());

    // Decode ratings JSON
    $ratings = [];
    if (!empty($row['ratings'])) {
        $decoded = json_decode((string) $row['ratings'], true);
        if (is_array($decoded)) $ratings = $decoded;
    }

    $isAnonymous = (bool) $row['isAnonymous'];
    $respondentTypeRaw = trim((string) ($row['respondentType'] ?? ''));

    $analysisText = trim(implode(' ', array_filter([
        (string) ($row['feedback'] ?? ''),
        (string) ($row['suggestions'] ?? ''),
    ], static fn (string $text): bool => trim($text) !== '')));
    $storedSentiment = (string) ($row['sentimentLabel'] ?? '');
    $sentimentLabel = sentimentNormalizeLabel($storedSentiment, $analysisText);
    $sentimentScore = in_array(strtolower(trim($storedSentiment)), ['positive', 'negative', 'neutral'], true)
        ? (float) ($row['sentimentScore'] ?? 0.0)
        : (float) analyzeSentiment($analysisText)['score'];

    $entry = [
        'referenceId' => (string) $row['referenceId'],
        'userType' => (string) $row['userType'],
        'isAnonymous' => $isAnonymous,
        'feedbackType' => (string) $row['feedbackType'],
        'service' => (string) $row['service'],
        'subservice' => !empty($row['subservice']) ? (string) $row['subservice'] : null,
        'serviceOther' => !empty($row['serviceOther']) ? (string) $row['serviceOther'] : null,
        'serviceSubtypeOther' => !empty($row['serviceSubtypeOther']) ? (string) $row['serviceSubtypeOther'] : null,
        'facility' => !empty($row['facility']) ? (string) $row['facility'] : null,
        'visitDate' => (string) $row['visitDate'],
        'subject' => (string) $row['subject'],
        'feedback' => (string) $row['feedback'],
        'suggestions' => (string) $row['suggestions'],
        'appreciation' => (string) ($row['appreciation'] ?? ''),
        'respondentType' => standardizeAdminChoice('respondentType', $respondentTypeRaw),
        'respondentTypeOther' => $row['respondentTypeOther'] ?: RespondentType::legacyOtherDetail($respondentTypeRaw),
        // Demographic category data (like respondentType above) is not
        // identity-revealing the way name/email/phone are, so — matching
        // respondentType's existing treatment — it is shown regardless of
        // the isAnonymous flag. See database/migrations/007_add_demographic_fields.sql.
        'age' => isset($row['age']) && is_numeric($row['age']) ? (int) $row['age'] : null,
        'sex' => standardizeAdminChoice('sex', !empty($row['sex']) ? (string) $row['sex'] : null),
        'genderPreference' => standardizeAdminChoice('genderPreference', !empty($row['genderPreference']) ? (string) $row['genderPreference'] : null),
        'genderPreferenceOther' => !empty($row['genderPreferenceOther']) ? (string) $row['genderPreferenceOther'] : null,
        'religion' => standardizeAdminChoice('religion', !empty($row['religion']) ? (string) $row['religion'] : null),
        'religionOther' => !empty($row['religionOther']) ? (string) $row['religionOther'] : null,
        'visitFrequency' => standardizeAdminChoice('visitFrequency', !empty($row['visitFrequency']) ? (string) $row['visitFrequency'] : null),
        // Education — see database/migrations/008_add_education_field.sql;
        // same non-identity treatment as the fields above.
        'educationLevel' => standardizeAdminChoice('educationLevel', !empty($row['educationLevel']) ? (string) $row['educationLevel'] : null),
        'citizensCharter' => [
            'cc1' => standardizeAdminChoice('cc1', !empty($row['cc1']) ? (string) $row['cc1'] : null),
            'cc2' => standardizeAdminChoice('cc2', !empty($row['cc2']) ? (string) $row['cc2'] : null),
            'cc3' => standardizeAdminChoice('cc3', !empty($row['cc3']) ? (string) $row['cc3'] : null),
        ],
        // Personal identity is only meaningful (and should only be shown) for
        // non-anonymous submissions, even though the column may still hold data.
        'submitterName' => (!$isAnonymous && !empty($row['fullName'])) ? (string) $row['fullName'] : null,
        'submitterEmail' => (!$isAnonymous && !empty($row['email'])) ? (string) $row['email'] : null,
        'submitterContactNumber' => (!$isAnonymous && !empty($row['contactNumber'])) ? (string) $row['contactNumber'] : null,
        'ratings' => $ratings,
        'ratingsBreakdown' => buildRatingBreakdown($ratings, $lang),
        'rating' => isset($row['rating']) && is_numeric($row['rating']) ? (float) $row['rating'] : 0.0,
        'status' => (string) $row['status'],
        'sentimentLabel' => $sentimentLabel,
        'sentimentScore' => $sentimentScore,
        'timestamp' => (string) $row['timestamp'],
        'updatedAt' => (string) $row['updatedAt'],
    ];

    jsonResponse(['success' => true, 'data' => ['entry' => $entry, 'audit' => $audits]]);
} catch (Throwable $e) {
    sfmsLogFailure('feedback_detail.read');
    jsonResponse(['success' => false, 'message' => 'Unable to load feedback details.'], 500);
}
