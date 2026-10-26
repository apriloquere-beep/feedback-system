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

try {
    $pdo = getPdo();

    // feedback_entries is the workflow/status table (status updates and archiving
    // write here). feedbacks is the newer, fuller table populated by feedback2.php
    // (respondent_type, scalar rating, submitter identity, sentiment) that
    // feedback_entries does not have. Both are written together for every
    // submission and share reference_id, so a LEFT JOIN is safe and does not
    // affect the authoritative status/workflow columns, which stay on fe.*.
    $stmt = $pdo->query(
        'SELECT
            fe.reference_id AS referenceId,
            fe.user_type AS userType,
            fe.is_anonymous AS isAnonymous,
            fe.feedback_type AS feedbackType,
            COALESCE(s.name, fe.service) AS service,
                DATE_FORMAT(fe.visit_date, "%Y-%m-%d") AS visitDate,
            fe.subject, fe.subject_enc AS subjectEnc,
            fe.feedback, fe.feedback_enc AS feedbackEnc,
            fe.suggestions, fe.suggestions_enc AS suggestionsEnc,
            fe.appreciation, fe.appreciation_enc AS appreciationEnc,
            fe.ratings AS ratings,
                fe.status AS status,
                fe.priority AS priority,
                fe.sentiment_label AS sentimentLabel,
                fe.sentiment_score AS sentimentScore,
                DATE_FORMAT(fe.created_at, "%Y-%m-%dT%H:%i:%s") AS timestamp,
            fb.respondent_type AS respondentType, fb.respondent_type_enc AS respondentTypeEnc,
            fb.respondent_type_other AS respondentTypeOther, fb.respondent_type_other_enc AS respondentTypeOtherEnc,
            fb.rating AS rating,
            fb.full_name AS fullName, fb.full_name_enc AS fullNameEnc,
            fb.email AS email, fb.email_enc AS emailEnc,
            fb.contact_number AS contactNumber, fb.contact_number_enc AS contactNumberEnc
         FROM feedback_entries fe
         LEFT JOIN services s ON s.id = fe.service_id
         LEFT JOIN feedbacks fb ON fb.reference_id = fe.reference_id
         ORDER BY fe.created_at DESC'
    );

    $rows = $stmt->fetchAll();

    $decryption = new FeedbackDecryption();
    $entries = array_map(static function (array $row) use ($decryption): array {
        $referenceId = (string) ($row['referenceId'] ?? '');
        $row = $decryption->map($row, 'feedback_entries', $referenceId, [
            'subject' => ['subjectEnc', 'subject'],
            'feedback' => ['feedbackEnc', 'feedback'],
            'suggestions' => ['suggestionsEnc', 'suggestions'],
            'appreciation' => ['appreciationEnc', 'appreciation'],
        ]);
        $row = $decryption->map($row, 'feedbacks', $referenceId, [
            'respondentType' => ['respondentTypeEnc', 'respondentType', 'respondent_type'],
            'respondentTypeOther' => ['respondentTypeOtherEnc', 'respondentTypeOther', 'respondent_type_other'],
            'fullName' => ['fullNameEnc', 'fullName', 'full_name'],
            'email' => ['emailEnc', 'email'],
            'contactNumber' => ['contactNumberEnc', 'contactNumber', 'contact_number'],
        ]);
        $row['isAnonymous'] = (bool) $row['isAnonymous'];
        $decodedRatings = json_decode((string) $row['ratings'], true);
        $row['ratings'] = is_array($decodedRatings) ? $decodedRatings : [];
        $row['rating'] = isset($row['rating']) && is_numeric($row['rating']) ? (float) $row['rating'] : 0.0;
        $analysisText = trim(implode(' ', array_filter([
            (string) ($row['feedback'] ?? ''),
            (string) ($row['suggestions'] ?? ''),
        ], static fn (string $text): bool => trim($text) !== '')));
        $storedSentiment = (string) ($row['sentimentLabel'] ?? '');
        $row['sentimentLabel'] = sentimentNormalizeLabel($storedSentiment, $analysisText);
        if (!in_array(strtolower(trim($storedSentiment)), ['positive', 'negative', 'neutral'], true)) {
            $row['sentimentScore'] = analyzeSentiment($analysisText)['score'];
        }
        $respondentTypeRaw = $row['respondentType'];
        $row['respondentType'] = RespondentType::label($respondentTypeRaw);
        $row['respondentTypeOther'] = $row['respondentTypeOther'] ?: RespondentType::legacyOtherDetail($respondentTypeRaw);
        // Personal identity is only meaningful (and should only be shown) for
        // non-anonymous submissions, even though the column may still hold data.
        if ($row['isAnonymous']) {
            $row['fullName'] = null;
            $row['email'] = null;
            $row['contactNumber'] = null;
        }
        return $row;
    }, $rows);

    jsonResponse(['success' => true, 'data' => $entries]);
} catch (Throwable $e) {
    sfmsLogFailure('feedback_list.read');
    jsonResponse(['success' => false, 'message' => 'Unable to load feedback.'], 500);
}
