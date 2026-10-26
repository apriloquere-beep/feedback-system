<?php
/**
 * ═══════════════════════════════════════════════════════════════
 *  Feedback Submission Confirmation Page
 *  Hospital Feedback System
 * ═══════════════════════════════════════════════════════════════
 */

// $_SESSION['selected_language'] is the single source of truth for the
// active language across the whole project; see lang/resolve.php. This
// also starts the session, so the language chosen before submission
// stays active on this receipt page.
require __DIR__ . '/lang/resolve.php';

// 🔒 Security: Check if user has actually submitted feedback
// Support both session-based (legacy) and query parameter (modern JS form) access
$referenceId = null;

// Try to get from query parameter first (modern JS form redirect)
if (!empty($_GET['ref'])) {
    $referenceId = preg_replace('/[^A-Z0-9\-]/', '', strtoupper($_GET['ref']));
}

$sessionReference = (string) ($_SESSION['feedback_copy_reference_id'] ?? '');
if ($referenceId && ($sessionReference === '' || !hash_equals($sessionReference, $referenceId))) {
    header('Location: /Feedback/form/feedback.php');
    exit;
}

// Fall back to session data (legacy form submission)
if (!$referenceId && (isset($_SESSION['submission_success']) && isset($_SESSION['submission_data']))) {
    $submission = $_SESSION['submission_data'];
    $referenceId = $submission['referenceId'] ?? null;
}

// If still no reference ID, redirect back
if (!$referenceId) {
    header('Location: /Feedback/form/feedback.php');
    exit;
}

require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/staff_role_labels.php';
require_once __DIR__ . '/api/security/FeedbackDecryption.php';
applyStaffRoleLabelOverrides($t);

function extractGroupRating(array $ratings, array $prefixes): float
{
    $scores = [];
    foreach ($ratings as $key => $value) {
        $keyString = (string) $key;
        $matched = false;
        foreach ($prefixes as $prefix) {
            if (strpos($keyString, $prefix) === 0) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            continue;
        }

        $score = null;
        if (is_array($value) && isset($value['score']) && is_numeric($value['score'])) {
            $score = (float) $value['score'];
        } elseif (is_numeric($value)) {
            $score = (float) $value;
        }

        if ($score !== null && $score >= 0.5 && $score <= 5) {
            $scores[] = $score;
        }
    }

    if ($scores === []) {
        return 0.0;
    }

    return round(array_sum($scores) / count($scores), 1);
}

// feedback2.php (the Client Experience Survey / PCF-CES form) uses a flat
// set of 29 rating keys (sqd4, a2, st0-st9, c17, ...) that never match the
// service_/staff_/facility_ prefixes above — those prefixes belong to the
// OLDER form/patient_feedback.php + form/visitor_feedback.php pipeline,
// which this receipt page also serves (js/feedback_form.js redirects here
// too). Rather than guessing which pipeline produced a record, this maps
// every feedback2.php key to the exact translation key already defined in
// lang/english.php / lang/bisaya.php (added when feedback2.php's i18n was
// implemented), in the same order the form itself presents them.
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

// True when $ratings uses feedback2.php's flat key scheme (none of the
// legacy service_/staff_/facility_ prefixed keys are present, but at least
// one recognized feedback2.php key is).
function isFlatRatingSet(array $ratings): bool
{
    foreach (array_keys($ratings) as $key) {
        if (array_key_exists((string) $key, FB2_RATING_KEY_MAP)) {
            return true;
        }
    }
    return false;
}

// Builds the itemized breakdown for a flat-style (feedback2.php) rating
// set: [[label, valueDisplay], ...] in the form's own question order.
// N/A stays N/A here too — never coerced into a number.
function buildFlatRatingBreakdown(array $ratings, array $t): array
{
    $rows = [];
    foreach (FB2_RATING_KEY_MAP as $key => $translationKey) {
        if (!array_key_exists($key, $ratings)) {
            continue;
        }
        $raw = $ratings[$key];
        $label = $t[$translationKey] ?? $key;
        if (is_string($raw) && strtoupper(trim($raw)) === 'N/A') {
            $valueDisplay = 'N/A';
        } elseif (is_numeric($raw)) {
            $valueDisplay = number_format((float) $raw, 1, '.', '') . '/5';
        } else {
            continue; // unanswered/garbage value — skip rather than show misleading data
        }
        $rows[] = [$label, $valueDisplay];
    }
    return $rows;
}

function mapSubmissionFromDb(array $row): array
{
    $ratings = [];
    if (!empty($row['ratings_json'])) {
        $decoded = json_decode((string) $row['ratings_json'], true);
        if (is_array($decoded)) {
            $ratings = $decoded;
        }
    }

    return [
        'isAnonymous' => (bool) ($row['is_anonymous'] ?? true),
        'name' => (string) ($row['full_name'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'service' => (string) ($row['service'] ?? ''),
        'feedbackType' => (string) ($row['feedback_type'] ?? ''),
        'respondentType' => (string) ($row['respondent_type'] ?? $row['respondent_category'] ?? ''),
        'respondent_type_other' => (string) ($row['respondent_type_other'] ?? ''),
        'feedback' => (string) ($row['feedback'] ?? ''),
        'suggestions' => (string) ($row['suggestions'] ?? ''),
        'staffRecognition' => (string) ($row['appreciation'] ?? $row['recognition'] ?? ''),
        'timestamp' => (string) ($row['submitted_at'] ?? ''),
        'visitDate' => (string) ($row['visit_date'] ?? ''),
        // The overall `rating` column is computed server-side in
        // api/feedback_submit.php::average_rating() across ALL submitted
        // rating keys regardless of naming scheme — unlike the group
        // extraction below, it's already correct for both pipelines.
        'overallRating' => isset($row['rating']) && is_numeric($row['rating']) ? (float) $row['rating'] : 0.0,
        'ratingsRaw' => $ratings,
        'isFlatRatingSet' => isFlatRatingSet($ratings),
        'serviceRating' => extractGroupRating($ratings, ['service_', 'vis_service_']),
        'staffRating' => extractGroupRating($ratings, ['staff_', 'vis_staff_']),
        'facilityRating' => extractGroupRating($ratings, ['facility_', 'vis_facility_']),
        // Demographic fields — see database/migrations/007_add_demographic_fields.sql
        'age' => isset($row['age']) && is_numeric($row['age']) ? (int) $row['age'] : null,
        'sex' => (string) ($row['sex'] ?? ''),
        'genderPreference' => (string) ($row['gender_preference'] ?? ''),
        'religion' => (string) ($row['religion'] ?? ''),
        'visitFrequency' => (string) ($row['visit_frequency'] ?? ''),
        // Education — see database/migrations/008_add_education_field.sql
        'educationLevel' => (string) ($row['education_level'] ?? ''),
    ];
}

// Start with session data, then overwrite with DB data when available.
$submission = $_SESSION['submission_data'] ?? [];
try {
    $pdo = getPdo();
    $columnStmt = $pdo->prepare("SHOW COLUMNS FROM feedbacks LIKE 'respondent_type'");
    $columnStmt->execute();
    $hasRespondentTypeColumn = (bool) $columnStmt->fetch();

    $demoColumnStmt = $pdo->prepare("SHOW COLUMNS FROM feedbacks LIKE 'age'");
    $demoColumnStmt->execute();
    $hasDemographicColumns = (bool) $demoColumnStmt->fetch();

    $educColumnStmt = $pdo->prepare("SHOW COLUMNS FROM feedbacks LIKE 'education_level'");
    $educColumnStmt->execute();
    $hasEducationColumn = (bool) $educColumnStmt->fetch();

    $selectColumns = [
        'reference_id',
        'is_anonymous',
        'full_name',
        'service',
        'feedback_type',
        'feedback',
        'suggestions',
        'appreciation',
        'ratings_json',
        'submitted_at',
        'visit_date',
        'rating',
        'full_name_enc',
        'feedback_enc',
        'suggestions_enc',
        'appreciation_enc',
        'respondent_type_enc',
        'respondent_type_other',
        'respondent_type_other_enc',
        'age_enc',
        'sex_enc',
        'gender_preference_enc',
        'religion_enc',
        'visit_frequency_enc',
        'education_level_enc',
    ];

    if ($hasRespondentTypeColumn) {
        $selectColumns[] = 'respondent_type';
    }

    if ($hasDemographicColumns) {
        $selectColumns = array_merge($selectColumns, ['age', 'sex', 'gender_preference', 'religion', 'visit_frequency']);
    }

    if ($hasEducationColumn) {
        $selectColumns[] = 'education_level';
    }

    $stmt = $pdo->prepare(
        'SELECT ' . implode(', ', $selectColumns) . '
         FROM feedbacks
         WHERE reference_id = :reference_id
         LIMIT 1'
    );
    $stmt->execute([':reference_id' => $referenceId]);
    $row = $stmt->fetch();
    if (is_array($row) && !empty($row)) {
        $row = (new FeedbackDecryption())->map($row, 'feedbacks', $referenceId, [
            'full_name' => ['full_name_enc', 'full_name'],
            'feedback' => ['feedback_enc', 'feedback'],
            'suggestions' => ['suggestions_enc', 'suggestions'],
            'appreciation' => ['appreciation_enc', 'appreciation'],
            'respondent_type' => ['respondent_type_enc', 'respondent_type'],
            'respondent_type_other' => ['respondent_type_other_enc', 'respondent_type_other'],
            'age' => ['age_enc', 'age'],
            'sex' => ['sex_enc', 'sex'],
            'gender_preference' => ['gender_preference_enc', 'gender_preference'],
            'religion' => ['religion_enc', 'religion'],
            'visit_frequency' => ['visit_frequency_enc', 'visit_frequency'],
            'education_level' => ['education_level_enc', 'education_level'],
        ]);
        $submission = array_merge($submission, mapSubmissionFromDb($row));
    }
} catch (Throwable $e) {
    // Keep page functional even if DB is temporarily unavailable.
}

// Get submission data from merged source
$isAnonymous = $submission['isAnonymous'] ?? true;
$name = $submission['name'] ?? 'Valued Visitor';
$feedback = $submission['feedback'] ?? '';
$suggestions = $submission['suggestions'] ?? '';
$feedbackType = $submission['feedbackType'] ?? 'general';
$serviceRating = $submission['serviceRating'] ?? 0;
$staffRating = $submission['staffRating'] ?? 0;
$facilityRating = $submission['facilityRating'] ?? 0;
$submitTime = $submission['timestamp'] ?? date('c');
$staffRecognition = $submission['staffRecognition'] ?? '';
$visitDateRaw = $submission['visitDate'] ?? '';
$overallRating = $submission['overallRating'] ?? 0.0;
$ratingsRaw = $submission['ratingsRaw'] ?? [];
$isFlatRatingSet = $submission['isFlatRatingSet'] ?? false;
$flatRatingBreakdown = $isFlatRatingSet ? buildFlatRatingBreakdown($ratingsRaw, $t) : [];

// Demographic fields — see database/migrations/007_add_demographic_fields.sql
$respondentAge = $submission['age'] ?? null;
$respondentSex = $submission['sex'] ?? '';
$respondentGenderPreference = $submission['genderPreference'] ?? '';
$respondentReligion = $submission['religion'] ?? '';
$respondentVisitFrequency = $submission['visitFrequency'] ?? '';
// Education — see database/migrations/008_add_education_field.sql
$respondentEducationLevel = $submission['educationLevel'] ?? '';
$hasDemographicInfo = $respondentAge !== null || $respondentSex !== '' || $respondentGenderPreference !== '' || $respondentReligion !== '' || $respondentVisitFrequency !== '' || $respondentEducationLevel !== '';

// Fallback-applied display values for the restored Submission Summary.
// Empty is genuinely empty ('' after the ?? '' coalesce above) — anything
// with real content is shown exactly as submitted.
$feedbackDisplay = $feedback !== '' ? $feedback : $t['fb2_receiptNoFeedback'];
$suggestionsDisplay = $suggestions !== '' ? $suggestions : $t['fb2_receiptNoSuggestions'];
$appreciationDisplay = $staffRecognition !== '' ? $staffRecognition : $t['fb2_receiptNoAppreciation'];

// Prefer form field names when present (session or DB may store different keys)
$respTypeRaw = $submission['resp_type'] ?? $submission['respondentType'] ?? $submission['respondent_type'] ?? '';
$respTypeOther = $submission['resp_type_other'] ?? $submission['respondent_type_other'] ?? '';
$serviceRaw = $submission['serbisyo'] ?? $submission['service'] ?? $submission['service_received'] ?? '';
$serviceOther = $submission['serbisyo_other'] ?? $submission['service_other'] ?? '';

// Normalize respondent display — feedback2.php submits internal values
// (Pasyente/Kliyente, Kauban sa Pasyente, Bisita, or a custom "other"
// description), not the display labels. Map back through the exact same
// translation keys the form itself uses (fb2_respType*) so the receipt
// shows the correct label in the current session language. This is the
// project's existing mechanism for translating submitted option labels —
// no new translation system, no automatic translation of free-text values.
$respondentTypeKeyMap = [
    'pasyente/kliyente' => 'fb2_respTypePatient',
    'patient / client' => 'fb2_respTypePatient',
    'kauban sa pasyente' => 'fb2_respTypeCompanion',
    "patient's companion (relative)" => 'fb2_respTypeCompanion',
    'bisita' => 'fb2_respTypeVisitor',
    'business (visited for business purposes)' => 'fb2_respTypeVisitor',
];
$respondentDisplay = 'Not Specified';
if (!empty($respTypeRaw)) {
    $trimmed = trim($respTypeRaw);
    $lower = strtolower($trimmed);
    if (in_array($lower, ['other', 'others', 'uban pa'], true)) {
        $respondentDisplay = $t['fb2_optOther'] ?? 'Other';
    } elseif (isset($respondentTypeKeyMap[$lower])) {
        $respondentDisplay = $t[$respondentTypeKeyMap[$lower]] ?? $trimmed;
    } else {
        // Older submissions stored a custom description in the type field.
        $respTypeOther = $respTypeOther ?: $trimmed;
        $respondentDisplay = $t['fb2_optOther'] ?? 'Other';
    }
}

// Normalize service display
$serviceDisplay = 'Not Specified';
if (!empty($serviceRaw)) {
    $trimmed = trim($serviceRaw);
    $lower = strtolower($trimmed);
    if (in_array($lower, ['other', 'others', 'uban pa'], true)) {
        $serviceDisplay = !empty(trim($serviceOther)) ? trim($serviceOther) : $trimmed;
    } else {
        $serviceDisplay = $trimmed;
    }
}

// Format timestamp
$submitDateTime = new DateTime($submitTime);
$formattedTime = $submitDateTime->format('F j, Y \a\t g:i A');

// Format the visit/consultation date the respondent entered on the form
// (visit_date) — distinct from $formattedTime, which is when the record
// was created.
$formattedVisitDate = '';
if (!empty($visitDateRaw)) {
    try {
        $formattedVisitDate = (new DateTime($visitDateRaw))->format('F j, Y');
    } catch (Throwable $e) {
        $formattedVisitDate = '';
    }
}

// Clear session data after displaying (prevent re-access)
// Keep submission_success flag, but clear other session data
unset($_SESSION['submission_data']);

// Helper function to generate star display
function displayStars($rating) {
    $rating = max(0, min(5, round(((float) $rating) * 2) / 2));
    $fullStars = (int) floor($rating);
    $hasHalfStar = abs($rating - $fullStars) >= 0.5;
    $stars = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $fullStars) {
            $stars .= '<span class="star-filled">★</span>';
        } elseif ($hasHalfStar && $i === $fullStars + 1) {
            $stars .= '<span class="star-half">★</span>';
        } else {
            $stars .= '<span class="star-empty">☆</span>';
        }
    }
    return $stars;
}

function formatRatingValue($rating) {
    return number_format((float) $rating, 1, '.', '');
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($htmlLang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title id="pageTitle"><?= htmlspecialchars($t['submissionTitle']) ?></title>
    <script>document.documentElement.classList.add('lang-loading');</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/Feedback/css/submit_header.css">
    <script src="/Feedback/assets/js/browser-deterrent.js" defer></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #4caf50;
            --primary-bright: #10b981;
            --secondary: #2e7d32;
            --success: #059669;
            --dark: #103f3b;
            --text: #475569;
            --bg: #f7fefb;
            --mint: #e8f5e9;
            --border: #cdefe1;
            --white: #ffffff;
            --gray-100: #f3fff9;
            --gray-200: #d8eee3;
            --gray-400: #94a3b8;
            --gray-600: #475569;
            --radius-md: 12px;
            --radius-lg: 20px;
            --shadow-md: 0 18px 48px rgba(6, 95, 70, 0.13);
        }

        html {
            min-height: 100%;
            background: var(--bg);
        }

        body {
            font-family: 'Poppins', sans-serif;
            background:
                radial-gradient(circle at 12% 18%, rgba(209, 250, 229, 0.75), transparent 48%),
                radial-gradient(circle at 85% 12%, rgba(167, 243, 208, 0.55), transparent 45%),
                radial-gradient(circle at 82% 78%, rgba(236, 253, 245, 0.95), transparent 46%),
                linear-gradient(145deg, #f7fefb 0%, #ffffff 48%, #effcf6 100%);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
        }

        html.lang-loading body {
            visibility: hidden;
        }

        /* MAIN CONTAINER */
        .container {
            flex: 1 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            max-width: 820px;
            width: 100%;
            margin: 0 auto;
            padding: clamp(32px, 5vw, 64px) 20px;
        }

        /* SUCCESS CARD */
        .success-card {
            width: 100%;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: clamp(34px, 5vw, 52px) clamp(24px, 5vw, 44px);
            box-shadow: var(--shadow-md);
            text-align: center;
            animation: slideUp 0.6s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* SUCCESS ICON */
        .success-icon {
            width: 76px;
            height: 76px;
            color: var(--white);
            background: linear-gradient(135deg, var(--primary-bright), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            font-size: 36px;
            font-weight: 700;
            box-shadow: 0 12px 28px rgba(46, 125, 50, 0.22);
        }

        /* SUCCESS MESSAGE */
        .success-title {
            font-size: clamp(28px, 4vw, 38px);
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 12px;
            line-height: 1.2;
            letter-spacing: -0.025em;
            text-wrap: balance;
        }

        .success-subtitle {
            max-width: 600px;
            margin-inline: auto;
            font-size: 15px;
            color: var(--gray-600);
            margin-bottom: 32px;
        }

        /* REFERENCE ID SECTION */
        .reference-section {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: var(--radius-md);
            padding: 16px;
            margin-bottom: 32px;
        }

        .reference-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .reference-id {
            font-size: 24px;
            font-weight: 700;
            font-family: 'Courier New', monospace;
            color: var(--primary);
            word-break: break-all;
        }

        .reference-note {
            font-size: 12px;
            color: var(--gray-600);
            margin-top: 8px;
        }

        /* SUMMARY SECTION */
        .summary-header {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
            text-align: left;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--gray-200);
        }

        .summary-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            text-align: left;
            margin-bottom: 32px;
        }

        @media (max-width: 600px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }

        .summary-item {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .summary-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .summary-value {
            font-size: 16px;
            color: var(--dark);
            font-weight: 500;
        }

        /* RATING DISPLAY */
        .rating-display {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .star-filled {
            color: #f59e0b;
            font-size: 18px;
        }

        .star-half {
            color: transparent;
            font-size: 18px;
            background: linear-gradient(90deg, #f59e0b 50%, #e5e7eb 50%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .star-empty {
            color: #e5e7eb;
            font-size: 18px;
        }

        .rating-value {
            font-size: 14px;
            color: var(--gray-600);
            font-weight: 500;
        }

        /* FULL WIDTH SUMMARY */
        .summary-item-full {
            grid-column: 1 / -1;
        }

        .feedback-content {
            background: var(--gray-100);
            border-left: 4px solid var(--primary);
            border-radius: var(--radius-md);
            padding: 16px;
            margin-top: 8px;
            font-size: 14px;
            line-height: 1.7;
            color: var(--text);
        }

        /* RATINGS BREAKDOWN LIST (reuses .feedback-content's box styling) */
        .ratings-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .ratings-list li {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            font-size: 13px;
            line-height: 1.5;
        }

        .ratings-list .rating-q {
            color: var(--text);
        }

        .ratings-list .rating-a {
            color: var(--dark);
            font-weight: 600;
            white-space: nowrap;
        }

        .email-copy-card {
            text-align: center;
            margin-top: 24px;
            padding: 16px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: linear-gradient(145deg, #fbfffd, var(--gray-100));
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .email-copy-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 10px;
        }

        .email-copy-explanation {
            font-size: 13px;
            color: var(--gray-600);
            margin: -4px 0 12px;
            max-width: 420px;
        }

        .email-copy-note {
            font-size: 12px;
            color: var(--gray-600);
            margin: 4px 0 0;
            width: 100%;
            max-width: 420px;
        }

        .email-copy-panel {
            margin-top: 12px;
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .email-copy-panel.is-hidden {
            display: none;
        }

        .email-copy-row {
            display: flex;
            flex-direction: column;
            gap: 8px;
            align-items: stretch;
            width: 100%;
            max-width: 420px;
        }

        .email-copy-actions {
            display: flex;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
            width: 100%;
            max-width: 420px;
            margin-top: 12px;
        }

        .btn-small,
        .email-copy-trigger {
            padding: 6px 12px;
            font-size: 12px;
        }

        .email-copy-status {
            margin-top: 8px;
            font-size: 13px;
            min-height: 20px;
            text-align: center;
        }

        .email-copy-input {
            width: 100%;
            border: 1px solid var(--gray-200);
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 14px;
            color: var(--dark);
            outline: none;
            background: var(--white);
        }

        .email-copy-input:focus {
            border-color: var(--success);
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.14);
        }

        .email-copy-consent {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            font-size: 13px;
            color: var(--gray-600);
            margin-top: 8px;
        }

        .email-copy-status {
            margin-top: 8px;
            font-size: 13px;
            min-height: 20px;
        }

        .email-copy-status.error {
            color: #b91c1c;
        }

        .email-copy-status.success {
            color: #166534;
        }

        /* ACTION BUTTONS */
        .button-group {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 32px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 28px;
            border-radius: var(--radius-md);
            border: none;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 44px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-bright), var(--secondary));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(16, 185, 129, 0.2);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--text);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--gray-200);
            border-color: var(--gray-400);
        }

        /* RESPONSIVE */
        @media (max-width: 640px) {
            .container {
                align-items: flex-start;
                padding: 28px 16px 36px;
            }

            .success-card {
                padding: 30px 18px;
                border-radius: 17px;
            }

            .success-icon {
                width: 64px;
                height: 64px;
                font-size: 32px;
                margin-bottom: 18px;
            }

            .success-title {
                font-size: clamp(24px, 7.4vw, 30px);
                line-height: 1.2;
            }

            .success-subtitle {
                font-size: 14px;
                margin-bottom: 24px;
            }

            .reference-section {
                padding: 14px;
                margin-bottom: 24px;
            }

            .reference-id {
                font-size: 16px;
                line-height: 1.35;
            }

            .summary-header {
                font-size: 16px;
                margin-bottom: 16px;
            }

            .summary-grid {
                grid-template-columns: 1fr;
                gap: 16px;
                margin-bottom: 24px;
            }

            .summary-label {
                font-size: 11px;
            }

            .summary-value {
                font-size: 15px;
                word-break: break-word;
            }

            .rating-display {
                flex-wrap: wrap;
                row-gap: 4px;
            }

            .star-filled,
            .star-half,
            .star-empty {
                font-size: 16px;
            }

            .feedback-content {
                padding: 14px;
                font-size: 13px;
            }

            .email-copy-card {
                padding: 14px;
                margin-top: 20px;
            }

            .button-group {
                flex-direction: column;
                margin-top: 24px;
            }

            .btn {
                width: 100%;
                justify-content: center;
                padding: 12px 20px;
            }
        }

        @media (max-width: 420px) {
            .container {
                padding: 22px 12px 30px;
            }

            .success-card {
                padding: 22px 14px;
            }

            .reference-id {
                font-size: 15px;
            }

            .button-group {
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- HEADER -->
    <?php include __DIR__ . '/includes/submit_header.php'; ?>

    <!-- MAIN CONTENT -->
    <div class="container">
        <div class="success-card">
            <!-- Success Icon & Message -->
            <div class="success-icon">✓</div>
            <h1 class="success-title" id="submissionTitle"><?= htmlspecialchars($t['submissionTitle']) ?></h1>
            <p class="success-subtitle"><?= htmlspecialchars($t['fb2_receiptSubtitle']) ?></p>

            <!-- Full submission summary (reference ID, respondent info, demographics,
                 education, service, visit/submit dates, all 29 ratings,
                 feedback, suggestions, appreciation) is intentionally no longer shown
                 on this public page. Every value above is still computed from the
                 database exactly as before and remains available to the email-copy
                 feature, the admin dashboard, reports, sentiment analysis, and the
                 audit trail — only the public display here was removed. -->

            <div class="email-copy-card">
                <div class="email-copy-title"><?= htmlspecialchars($t['fb2_receiptEmailHeading']) ?></div>
                <p class="email-copy-explanation"><?= htmlspecialchars($t['fb2_receiptEmailExplanation']) ?></p>
                <button type="button" class="btn btn-secondary email-copy-trigger btn-small" id="toggleEmailCopyBtn"><?= htmlspecialchars($t['fb2_receiptToggleButton']) ?></button>

                <div class="email-copy-panel is-hidden" id="emailCopyPanel">
                    <div class="email-copy-row">
                        <label class="summary-label" for="copyEmailInput"><?= htmlspecialchars($t['fb2_receiptEmailLabel']) ?></label>
                        <input
                            type="email"
                            id="copyEmailInput"
                            class="email-copy-input"
                            placeholder="email@example.com"
                            value=""
                            autocomplete="email"
                        >
                    </div>

                    <label class="email-copy-consent" for="copyConsent">
                        <input type="checkbox" id="copyConsent">
                        <span><?= htmlspecialchars($t['fb2_receiptConsentLabel']) ?></span>
                    </label>

                    <p class="email-copy-note"><?= htmlspecialchars($t['fb2_receiptEmailNote']) ?></p>

                    <div class="email-copy-actions">
                        <button type="button" class="btn btn-secondary btn-small email-copy-hide-btn" id="toggleEmailCopyHideBtn"><?= htmlspecialchars($t['fb2_receiptHideButton']) ?></button>
                        <button type="button" class="btn btn-primary btn-small" id="sendCopyBtn"><?= htmlspecialchars($t['fb2_receiptSendButton']) ?></button>
                    </div>
                    <div id="emailCopyStatus" class="email-copy-status"></div>
                </div>
            </div>

        </div>
    </div>

    <!-- FOOTER -->
    <?php include __DIR__ . '/includes/footer.php'; ?>

    <script>
        (function () {
            const referenceId = <?php echo json_encode($referenceId, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
            const RECEIPT_I18N = <?php echo json_encode([
                'emailRequired' => $t['fb2_receiptErrEmailRequired'],
                'emailInvalid' => $t['fb2_receiptErrEmailInvalid'],
                'consentRequired' => $t['fb2_receiptErrConsentRequired'],
                'sending' => $t['fb2_receiptSending'],
                'sendButton' => $t['fb2_receiptSendButton'],
                'genericError' => $t['fb2_receiptErrGeneric'],
                'defaultSuccess' => $t['fb2_receiptEmailSuccess'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;
            const toggleBtn = document.getElementById('toggleEmailCopyBtn');
            const hideBtn = document.getElementById('toggleEmailCopyHideBtn');
            const panel = document.getElementById('emailCopyPanel');
            const emailInput = document.getElementById('copyEmailInput');
            const consent = document.getElementById('copyConsent');
            const sendBtn = document.getElementById('sendCopyBtn');
            const statusEl = document.getElementById('emailCopyStatus');

            function setStatus(message, type) {
                statusEl.textContent = message || '';
                statusEl.classList.remove('error', 'success');
                if (type) {
                    statusEl.classList.add(type);
                }
            }

            function openEmailPanel() {
                panel.classList.remove('is-hidden');
                toggleBtn.style.display = 'none';
                if (emailInput) {
                    emailInput.focus();
                }
            }

            function closeEmailPanel() {
                panel.classList.add('is-hidden');
                toggleBtn.style.display = 'inline-flex';
            }

            toggleBtn.addEventListener('click', function () {
                openEmailPanel();
            });

            hideBtn.addEventListener('click', function () {
                closeEmailPanel();
            });

            sendBtn.addEventListener('click', async function () {
                const email = (emailInput.value || '').trim();
                if (!email) {
                    setStatus(RECEIPT_I18N.emailRequired, 'error');
                    return;
                }

                if (emailInput && !emailInput.checkValidity()) {
                    setStatus(RECEIPT_I18N.emailInvalid, 'error');
                    return;
                }

                if (!consent.checked) {
                    setStatus(RECEIPT_I18N.consentRequired, 'error');
                    return;
                }

                sendBtn.disabled = true;
                sendBtn.textContent = RECEIPT_I18N.sending;
                setStatus('');

                try {
                    const response = await fetch('/Feedback/api/send_feedback_copy.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            referenceId,
                            email,
                            consent: true
                        })
                    });

                    let payload = {};
                    try {
                        payload = await response.json();
                    } catch (e) {
                        payload = {};
                    }

                    if (!response.ok || !payload.success) {
                        setStatus(payload.message || RECEIPT_I18N.genericError, 'error');
                        return;
                    }

                    setStatus(payload.message || RECEIPT_I18N.defaultSuccess, 'success');
                } catch (error) {
                    setStatus(RECEIPT_I18N.genericError, 'error');
                } finally {
                    sendBtn.disabled = false;
                    sendBtn.textContent = RECEIPT_I18N.sendButton;
                }
            });
        })();
    </script>
    <script src="/Feedback/assets/js/language.js?v=20260826b"></script>
</body>
</html>
