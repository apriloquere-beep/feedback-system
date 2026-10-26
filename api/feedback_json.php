<?php

declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════
 * FEEDBACK JSON API — NLP Sentiment Edition
 * ═══════════════════════════════════════════════════════════════════
 *
 * GET  /api/feedback_json.php           → list + summary
 * GET  ?search=&status=&sentiment=&minRating=&maxRating=
 * PATCH /api/feedback_json.php          → update status/fields
 *
 * Summary now includes sentimentCounts instead of typeCounts for charts.
 * feedbackType filter retained for backward compatibility.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sentiment.php';
require_once __DIR__ . '/security/FeedbackDecryption.php';
require_once __DIR__ . '/security/FeedbackAnalytics.php';
require_once __DIR__ . '/security/RespondentType.php';
require_once __DIR__ . '/security/AdminSecurity.php';
require_once __DIR__ . '/feedback_update_encryption.php';

sfmsStartSecureSession();

if (!isset($_SESSION['admin_id'])) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}
sfmsRequireCsrf();

// ── Status normalization ─────────────────────────────────────────────
function normalizeStatus(string $status): string
{
    $status = strtolower(trim($status));
    // Map legacy 'verified' and 'adjusted' → 'in_review'
    if (in_array($status, ['verified', 'adjusted', 'reviewed'], true)) {
        return 'in_review';
    }
    return in_array($status, ['pending', 'in_review', 'resolved', 'archived'], true)
        ? $status
        : 'pending';
}

// ── Ratings decoder ──────────────────────────────────────────────────
function decodeRatings(string $ratingsJson): array
{
    $decoded = json_decode($ratingsJson, true);
    if (!is_array($decoded)) {
        return [];
    }
    $ratings = [];
    foreach ($decoded as $key => $value) {
        if (is_array($value) && isset($value[0]) && !array_key_exists('score', $value)) {
            $values = array_values(array_filter(
                array_map(static fn($item): float => is_numeric($item) ? (float) $item : 0.0, $value),
                static fn(float $item): bool => $item > 0
            ));
            if ($values) {
                $ratings[$key] = array_sum($values) / count($values);
            }
            continue;
        }

        if (is_array($value) && array_key_exists('score', $value)) {
            $ratings[$key] = (float) $value['score'];
            continue;
        }
        if (is_numeric($value)) {
            $ratings[$key] = (float) $value;
            continue;
        }

        // Preserve an explicit "N/A" answer as a distinct marker instead of
        // silently dropping it — N/A is a legitimate response (see
        // form/feedback.php's rating scale and api/feedback_submit.php's
        // rating_is_answered()), not a missing one, and callers that need a
        // per-question N/A count (e.g. the Staff Ratings page) need to be
        // able to tell "answered N/A" apart from "no response at all".
        // Every numeric-average consumer already treats a non-numeric value
        // as 0 / excluded, so this is safe everywhere ratings[] is read.
        if (is_string($value) && strtoupper(trim($value)) === 'N/A') {
            $ratings[$key] = 'N/A';
        }
    }
    return $ratings;
}

// ── Average rating ───────────────────────────────────────────────────
function computeAverageRating(array $ratings): float
{
    $values = array_values(array_filter(
        array_map(static fn ($v): float => is_numeric($v) ? (float) $v : 0.0, $ratings),
        static fn (float $v): bool => $v > 0
    ));
    if (!$values) {
        return 0.0;
    }
    return round(array_sum($values) / count($values), 1);
}

// ── ISO timestamp ────────────────────────────────────────────────────
function isoTimestamp(?string $value): string
{
    if (!$value) {
        return date('c');
    }
    $time = strtotime($value);
    return $time ? date('c', $time) : date('c');
}

/**
 * Apply filters to the entries array.
 * Supports: search, status, sentiment, feedbackType, minRating, maxRating
 */
function applyFilters(array $entries, array $filters): array
{
    return FeedbackAnalytics::filter($entries, $filters);
    /* Legacy inline implementation retained below as unreachable reference. */
    return array_values(array_filter($entries, static function (array $entry) use ($filters): bool {

        // ── Full-text search ─────────────────────────────────────────
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $haystack = implode(' ', array_map('strval', [
                $entry['referenceId']    ?? '',
                $entry['name']           ?? '',
                $entry['service']        ?? '',
                $entry['subject']        ?? '',
                $entry['feedback']       ?? '',
                $entry['suggestions']    ?? '',
                $entry['sentimentLabel'] ?? '',
            ]));
            if (stripos($haystack, $search) === false) {
                return false;
            }
        }

        // ── Status filter ────────────────────────────────────────────
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && normalizeStatus((string) ($entry['status'] ?? 'pending')) !== normalizeStatus($status)) {
            return false;
        }

        // ── Sentiment filter (NEW) ───────────────────────────────────
        $sentiment = trim((string) ($filters['sentiment'] ?? ''));
        if ($sentiment !== '' && strcasecmp((string) ($entry['sentimentLabel'] ?? 'Neutral'), $sentiment) !== 0) {
            return false;
        }

        // ── Priority filter (NEW) ────────────────────────────────────
        $priority = trim((string) ($filters['priority'] ?? ''));
        if ($priority !== '' && strcasecmp((string) ($entry['priority'] ?? 'Normal'), $priority) !== 0) {
            return false;
        }

        // ── Legacy feedbackType filter (backward compat) ─────────────
        $feedbackType = trim((string) ($filters['feedbackType'] ?? ''));
        if ($feedbackType !== '' && strcasecmp((string) ($entry['feedbackType'] ?? ''), $feedbackType) !== 0) {
            return false;
        }

        // ── Rating range filter ──────────────────────────────────────
        $minRating = isset($filters['minRating']) && is_numeric($filters['minRating'])
            ? (float) $filters['minRating'] : null;
        $maxRating = isset($filters['maxRating']) && is_numeric($filters['maxRating'])
            ? (float) $filters['maxRating'] : null;
        $rating = (float) ($entry['averageRating'] ?? 0.0);

        if ($minRating !== null && $rating < $minRating) {
            return false;
        }
        if ($maxRating !== null && $rating > $maxRating) {
            return false;
        }

        return true;
    }));
}

/**
 * Build dashboard summary from filtered entries.
 * Replaces old complaint/compliment distribution with sentiment counts.
 */
function buildSummary(array $entries): array
{
    $total         = count($entries);
    $pending       = 0;
    $inReview      = 0;
    $resolved      = 0;
    $highPriority  = 0;
    $ratingSum     = 0.0;
    $ratingCount   = 0;

    // Sentiment aggregation (replaces typeCounts / distribution)
    $sentimentCounts = ['Positive' => 0, 'Neutral' => 0, 'Negative' => 0];
    $sentimentAnalyzedCount = 0;
    $statusCounts    = [];
    $services        = [];
    $trend           = ['daily' => [], 'weekly' => [], 'monthly' => []];

    // Sentiment trend buckets: ['daily']['May 1'] = ['Positive'=>N,'Neutral'=>M,'Negative'=>K]
    $sentimentTrend = ['daily' => [], 'weekly' => [], 'monthly' => []];

    $recentComments  = [];
    $recentActivity  = [];

    $now     = time();
    $weekAgo = $now - (7 * 24 * 60 * 60);

    foreach ($entries as $entry) {
        // ── Status counts ────────────────────────────────────────────
        $status = normalizeStatus((string) ($entry['status'] ?? 'pending'));
        match ($status) {
            'pending'   => $pending++,
            'in_review' => $inReview++,
            'resolved'  => $resolved++,
            default     => null,
        };
        $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

        // ── Priority count ───────────────────────────────────────────
        if (($entry['priority'] ?? 'Normal') === 'High') {
            $highPriority++;
        }

        // ── Rating aggregation ───────────────────────────────────────
        $rating = (float) ($entry['averageRating'] ?? 0.0);
        if ($rating > 0) {
            $ratingSum += $rating;
            $ratingCount++;
        }

        // ── Sentiment aggregation ────────────────────────────────────
        $label = (string) ($entry['sentimentLabel'] ?? 'Neutral');
        if (array_key_exists($label, $sentimentCounts)) {
            $sentimentCounts[$label]++;
            $sentimentAnalyzedCount++;
        }

        // ── Service stats (replaces the old Department stats) ─────────
        $serviceId = $entry['serviceId'] !== null ? (int) $entry['serviceId'] : 0;
        $service = trim((string) ($entry['service'] ?? 'Unassigned')) ?: 'Unassigned';
        $serviceKey = $serviceId > 0 ? 'id:' . $serviceId : 'unassigned';
        if (!isset($services[$serviceKey])) {
            $services[$serviceKey] = [
                'serviceId' => $serviceId ?: null,
                'label' => $service,
                'count'     => 0,
                'sum'       => 0.0,
                'pending'   => 0,
                'positive'  => 0,
                'negative'  => 0,
            ];
        }
        $services[$serviceKey]['count']++;
        $services[$serviceKey]['sum'] += $rating;
        if ($status === 'pending') {
            $services[$serviceKey]['pending']++;
        }
        if ($label === 'Positive') {
            $services[$serviceKey]['positive']++;
        }
        if ($label === 'Negative') {
            $services[$serviceKey]['negative']++;
        }

        // ── Trend buckets ────────────────────────────────────────────
        $timestamp  = isoTimestamp((string) ($entry['timestamp'] ?? ''));
        $time       = strtotime($timestamp) ?: $now;
        $dayLabel   = date('M j', $time);
        $weekStart  = strtotime('monday this week', $time);
        $weekLabel  = date('M j', $weekStart) . ' – ' . date('M j', strtotime('sunday this week', $time));
        $monthLabel = date('M Y', $time);

        // Feedback volume trend
        $trend['daily'][$dayLabel]     = ($trend['daily'][$dayLabel] ?? 0) + 1;
        $trend['weekly'][$weekLabel]   = ($trend['weekly'][$weekLabel] ?? 0) + 1;
        $trend['monthly'][$monthLabel] = ($trend['monthly'][$monthLabel] ?? 0) + 1;

        // Sentiment trend (stacked by sentiment label)
        foreach (['daily' => $dayLabel, 'weekly' => $weekLabel, 'monthly' => $monthLabel] as $bucket => $key) {
            if (!isset($sentimentTrend[$bucket][$key])) {
                $sentimentTrend[$bucket][$key] = ['Positive' => 0, 'Neutral' => 0, 'Negative' => 0];
            }
            if (array_key_exists($label, $sentimentTrend[$bucket][$key])) {
                $sentimentTrend[$bucket][$key][$label]++;
            }
        }

        // ── Recent comments ──────────────────────────────────────────
        if (!empty($entry['feedback'])) {
            $recentComments[] = [
                'referenceId'    => $entry['referenceId'] ?? '',
                'timestamp'      => $timestamp,
                'name'           => $entry['name'] ?? 'Guest',
                'service'        => $service,
                'message'        => (string) $entry['feedback'],
                'sentimentLabel' => $label,
                'type'           => 'comment',
            ];
        }

        $recentActivity[] = [
            'referenceId' => $entry['referenceId'] ?? '',
            'timestamp'   => $timestamp,
            'name'        => $entry['name'] ?? 'Guest',
            'service'     => $service,
            'message'     => $status === 'pending' ? 'Awaiting review' : 'Marked as ' . $status,
            'type'        => 'status',
        ];
    }

    // ── Service stats (minimum 1 entry for display; replaces the old
    //    Department stats now that SFMS is service-only) ────────────────
    $serviceStats = [];
    foreach ($services as $group) {
        $count = (int) $group['count'];
        $serviceStats[] = [
            'serviceId'       => $group['serviceId'],
            'label'           => $group['label'],
            'count'           => $count,
            'averageRating'   => $count > 0 ? round((float) $group['sum'] / $count, 1) : 0.0,
            'pendingCount'    => (int) $group['pending'],
            'positiveCount'   => (int) $group['positive'],
            'negativeCount'   => (int) $group['negative'],
            'satisfactionPct' => $count > 0 ? round(((float) $group['positive'] / $count) * 100, 1) : 0.0,
        ];
    }
    usort($serviceStats, static fn ($a, $b) => $b['averageRating'] <=> $a['averageRating']);

    // ── Insights ──────────────────────────────────────────────────────
    $bestService = $serviceStats[0] ?? null;
    $lowService  = null;
    foreach (array_reverse($serviceStats) as $candidate) {
        if ($candidate['averageRating'] <= 3.0 && $candidate['count'] > 0) {
            $lowService = $candidate;
            break;
        }
    }

    // ── Smart alerts based on sentiment / priority ────────────────────
    $alerts = [];
    if ($highPriority > 0) {
        $alerts[] = [
            'type'        => 'danger',
            'title'       => 'High Priority Feedback Detected',
            'description' => "{$highPriority} feedback" . ($highPriority > 1 ? 's' : '') . ' marked High Priority (Negative + low rating).',
        ];
    }

    $negCount = $sentimentCounts['Negative'];
    $negPct   = $sentimentAnalyzedCount > 0 ? ($negCount / $sentimentAnalyzedCount) * 100 : 0;
    if ($negPct >= 30 && $negCount > 0) {
        $alerts[] = [
            'type'        => 'warn',
            'title'       => 'High Negative Sentiment Rate',
            'description' => round($negPct, 0) . '% of feedback is negative. Review and respond.',
        ];
    }

    if (!$alerts) {
        $alerts[] = [
            'type'        => 'good',
            'title'       => 'System Normal',
            'description' => 'No active issues. Sentiment levels and service quality are stable.',
        ];
    }

    // ── Format trend buckets ──────────────────────────────────────────
    foreach ($trend as $bucket => $values) {
        ksort($trend[$bucket]);
        $trend[$bucket] = array_map(
            static fn ($lbl, $cnt): array => ['label' => $lbl, 'count' => $cnt],
            array_keys($trend[$bucket]),
            array_values($trend[$bucket])
        );
    }

    foreach ($sentimentTrend as $bucket => $values) {
        ksort($sentimentTrend[$bucket]);
        $formatted = [];
        foreach ($sentimentTrend[$bucket] as $lbl => $counts) {
            $formatted[] = ['label' => $lbl] + $counts;
        }
        $sentimentTrend[$bucket] = $formatted;
    }

    $recentComments = array_slice(array_reverse($recentComments), 0, 5);
    $recentActivity = array_slice(array_reverse($recentActivity), 0, 5);

    // ── Patient Satisfaction Rate ─────────────────────────────────────
    // = Positive Feedbacks ÷ Total Feedbacks
    $satisfactionRate = $total > 0 ? round(($sentimentCounts['Positive'] / $total) * 100, 1) : 0.0;

    // ── Resolution Rate ───────────────────────────────────────────────
    $actioned       = $inReview + $resolved;
    $resolutionRate = $total > 0 ? round(($resolved / $total) * 100, 1) : 0.0;

    return [
        'total'            => $total,
        'pending'          => $pending,
        'inReview'         => $inReview,
        'resolved'         => $resolved,
        'actioned'         => $actioned,
        'highPriority'     => $highPriority,
        'averageRating'    => $ratingCount > 0 ? round($ratingSum / $ratingCount, 1) : 0.0,
        'resolutionRate'   => $resolutionRate,
        'satisfactionRate' => $satisfactionRate,    // Positive ÷ Total (%)
        'sentimentCounts'  => $sentimentCounts,     // replaces old typeCounts
        'sentimentAnalyzedCount' => $sentimentAnalyzedCount,
        'statusCounts'     => $statusCounts,
        'serviceStats'     => $serviceStats,
        'bestService'      => $bestService,
        'lowService'       => $lowService,
        'alerts'           => $alerts,
        'recentComments'   => $recentComments,
        'recentActivity'   => $recentActivity,
        'trend'            => $trend,
        'sentimentTrend'   => $sentimentTrend,
    ];
}

// ════════════════════════════════════════════════════════════════════
// GET — List feedbacks with optional filters
// ════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $pdo  = getPdo();
        // feedback_entries is the workflow/status table (status updates and
        // archiving write here). feedbacks is the newer, fuller table populated
        // by feedback2.php (respondent_type, submitter identity) that
        // feedback_entries does not have. Both are written together for every
        // submission and share reference_id, so a LEFT JOIN is safe here and
        // does not affect the authoritative status/workflow columns on fe.*.
        $stmt = $pdo->query(
            'SELECT
                fe.reference_id        AS referenceId,
                fe.user_type           AS userType,
                fe.is_anonymous        AS isAnonymous,
                fe.feedback_type       AS feedbackType,
                fe.service_id         AS serviceId,
                fe.service_subtype_id AS serviceSubtypeId,
                st.name               AS serviceSubtype,
                COALESCE(s.name, fe.service)      AS service,
                DATE_FORMAT(fe.visit_date, "%Y-%m-%d") AS visitDate,
                fe.subject, fe.subject_enc AS subjectEnc,
                fe.feedback, fe.feedback_enc AS feedbackEnc,
                fe.suggestions, fe.suggestions_enc AS suggestionsEnc,
                fe.appreciation, fe.appreciation_enc AS appreciationEnc,
                fe.ratings,
                fe.status,
                fe.priority,
                fe.sentiment_label     AS sentimentLabel,
                fe.sentiment_score     AS sentimentScore,
                DATE_FORMAT(fe.created_at,  "%Y-%m-%dT%H:%i:%s") AS timestamp,
                DATE_FORMAT(fe.updated_at,  "%Y-%m-%dT%H:%i:%s") AS updatedAt,
                fb.respondent_type     AS respondentType, fb.respondent_type_enc AS respondentTypeEnc,
                fb.respondent_type_other AS respondentTypeOther, fb.respondent_type_other_enc AS respondentTypeOtherEnc,
                fb.full_name           AS fullName, fb.full_name_enc AS fullNameEnc,
                fb.email               AS email, fb.email_enc AS emailEnc,
                fb.contact_number      AS contactNumber, fb.contact_number_enc AS contactNumberEnc
             FROM feedback_entries fe
             LEFT JOIN services    s  ON s.id  = fe.service_id
             LEFT JOIN service_subtypes st ON st.id = fe.service_subtype_id
             LEFT JOIN feedbacks   fb ON fb.reference_id = fe.reference_id
             ORDER BY fe.created_at DESC'
        );

        $rows        = $stmt->fetchAll();
        $entriesByRef = [];

        $decryption = new FeedbackDecryption();
        foreach ($rows as $row) {
            $referenceId = (string) ($row['referenceId'] ?? '');
            if ($referenceId === '' || isset($entriesByRef[$referenceId])) {
                continue;
            }

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

            $ratings       = decodeRatings((string) ($row['ratings'] ?? ''));
            $timestamp     = isoTimestamp((string) ($row['timestamp'] ?? ''));
            $averageRating = computeAverageRating($ratings);

            // ── Backfill sentiment for legacy rows without DB values ──
            $sentimentLabel = (string) ($row['sentimentLabel'] ?? '');
            $sentimentScore = (float)  ($row['sentimentScore'] ?? 0.0);
            $textForAnalysis = trim(implode(' ', array_filter([
                (string) ($row['feedback'] ?? ''),
                (string) ($row['suggestions'] ?? ''),
            ], static fn (string $text): bool => trim($text) !== '')));
            $sentimentLabel = sentimentNormalizeLabel($sentimentLabel, $textForAnalysis);
            if (!in_array((string) ($row['sentimentLabel'] ?? ''), ['Positive', 'Negative', 'Neutral'], true)) {
                $analyzed = analyzeSentiment($textForAnalysis);
                $sentimentScore = $analyzed['score'];
            }

            // ── Backfill priority for legacy rows ─────────────────────
            $priority = (string) ($row['priority'] ?? 'Normal');
            if (!in_array($priority, ['Normal', 'High'], true)) {
                $priority = computePriority($sentimentLabel, $averageRating);
            }

            $isAnonymous = (bool) ($row['isAnonymous'] ?? false);
            $respondentTypeRaw = trim((string) ($row['respondentType'] ?? ''));

            $entriesByRef[$referenceId] = [
                'referenceId'    => $referenceId,
                'userType'       => (string) ($row['userType']    ?? ''),
                'isAnonymous'    => $isAnonymous,
                'feedbackType'   => (string) ($row['feedbackType'] ?? ''),  // kept for compat
                'serviceId'      => $row['serviceId'] !== null ? (int) $row['serviceId'] : null,
                'serviceSubtypeId' => $row['serviceSubtypeId'] !== null ? (int) $row['serviceSubtypeId'] : null,
                'serviceSubtype' => (string) ($row['serviceSubtype'] ?? ''),
                'service'        => (string) ($row['service']     ?? 'Unassigned'),
                'visitDate'      => (string) ($row['visitDate']   ?? ''),
                'subject'        => (string) ($row['subject']     ?? ''),
                'feedback'       => (string) ($row['feedback']    ?? ''),
                'suggestions'    => (string) ($row['suggestions'] ?? ''),
                'appreciation'   => (string) ($row['appreciation'] ?? ''),
                'respondentType' => RespondentType::label($respondentTypeRaw),
                'respondentTypeOther' => $row['respondentTypeOther'] ?: RespondentType::legacyOtherDetail($respondentTypeRaw),
                // Personal identity is only meaningful (and should only be shown)
                // for non-anonymous submissions, even though the column may still
                // hold data (e.g. contact info kept for internal follow-up).
                'fullName'       => (!$isAnonymous && !empty($row['fullName'])) ? (string) $row['fullName'] : null,
                'email'          => (!$isAnonymous && !empty($row['email'])) ? (string) $row['email'] : null,
                'contactNumber'  => (!$isAnonymous && !empty($row['contactNumber'])) ? (string) $row['contactNumber'] : null,
                'ratings'        => $ratings,
                'serviceRating'  => (float) ($ratings['service']  ?? 0),
                'staffRating'    => (float) ($ratings['staff']    ?? 0),
                'facilityRating' => (float) ($ratings['facility'] ?? 0),
                'averageRating'  => $averageRating,
                'status'         => normalizeStatus((string) ($row['status'] ?? 'pending')),
                'priority'       => $priority,
                'sentimentLabel' => $sentimentLabel !== '' ? $sentimentLabel : 'Neutral',
                'sentimentScore' => $sentimentScore,
                'timestamp'      => $timestamp,
                'updatedAt'      => isoTimestamp((string) ($row['updatedAt'] ?? $timestamp)),
            ];
        }

        $entries = array_values($entriesByRef);
        $filters = [
            'search'      => $_GET['search']      ?? '',
            'status'      => $_GET['status']      ?? '',
            'sentiment'   => $_GET['sentiment']   ?? '',  // NEW
            'priority'    => $_GET['priority']    ?? '',  // NEW
            'feedbackType'=> $_GET['feedbackType']?? '',  // legacy compat
            'service'     => $_GET['service']     ?? '',
            'respondent'  => $_GET['respondent']  ?? '',
            'minRating'   => $_GET['minRating']   ?? null,
            'maxRating'   => $_GET['maxRating']   ?? null,
            'dateFrom'    => $_GET['dateFrom']    ?? '',
            'dateTo'      => $_GET['dateTo']      ?? '',
        ];

        $filtered = applyFilters($entries, $filters);
        $summary  = buildSummary($filtered);

        jsonResponse([
            'success' => true,
            'data'    => $filtered,
            'summary' => $summary,
        ]);

    } catch (Throwable $e) {
        sfmsLogFailure('feedback_json.read');
        jsonResponse(['success' => false, 'message' => 'Unable to load feedback.'], 500);
    }
}

// ════════════════════════════════════════════════════════════════════
// PATCH — Update feedback fields / status
// ════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $payload     = readJsonBody();
    $referenceId = trim((string) ($payload['referenceId'] ?? ''));

    if ($referenceId === '') {
        jsonResponse(['success' => false, 'message' => 'referenceId is required'], 422);
    }

    try {
        $pdo    = getPdo();
        $pdo->beginTransaction();
        $select = $pdo->prepare(
            'SELECT ratings, subject, subject_enc AS subjectEnc,
                    feedback, feedback_enc AS feedbackEnc,
                    suggestions, suggestions_enc AS suggestionsEnc,
                    appreciation, appreciation_enc AS appreciationEnc, feedback_type,
                    visit_date, status, priority,
                    sentiment_label, sentiment_score
             FROM feedback_entries
             WHERE reference_id = :reference_id
             LIMIT 1
             FOR UPDATE'
        );
        $select->execute([':reference_id' => $referenceId]);
        $entry = $select->fetch();

        if (!$entry) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Reference not found'], 404);
        }

        $paired = $pdo->prepare(
            'SELECT 1 FROM feedbacks WHERE reference_id = :reference_id LIMIT 1 FOR UPDATE'
        );
        $paired->execute([':reference_id' => $referenceId]);
        if (!$paired->fetchColumn()) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Paired feedback record not found'], 409);
        }

        $entry = (new FeedbackDecryption())->map($entry, 'feedback_entries', $referenceId, [
            'subject' => ['subjectEnc', 'subject'],
            'feedback' => ['feedbackEnc', 'feedback'],
            'suggestions' => ['suggestionsEnc', 'suggestions'],
            'appreciation' => ['appreciationEnc', 'appreciation'],
        ]);

        $updates = is_array($payload['updates'] ?? null) ? $payload['updates'] : [];
        $status  = normalizeStatus((string) ($payload['status'] ?? $entry['status'] ?? 'pending'));

        $ratings = decodeRatings((string) ($entry['ratings'] ?? '{}'));
        foreach (['service', 'staff', 'facility'] as $ratingKey) {
            if (array_key_exists($ratingKey, $updates) && is_numeric($updates[$ratingKey])) {
                $ratings[$ratingKey] = max(0, min(5, (float) $updates[$ratingKey]));
            }
        }

        $subject     = trim((string) ($updates['subject']     ?? $entry['subject']     ?? ''));
        $feedback    = trim((string) ($updates['feedback']    ?? $entry['feedback']    ?? ''));
        $suggestions = trim((string) ($updates['suggestions'] ?? $entry['suggestions'] ?? ''));
        $appreciation = trim((string) ($updates['appreciation'] ?? $entry['appreciation'] ?? ''));
        $feedbackType= trim((string) ($updates['feedbackType']?? $entry['feedback_type']?? ''));
        $visitDate   = trim((string) ($updates['visitDate']   ?? $entry['visit_date']  ?? ''));

        // ── Re-analyze sentiment if feedback text changed ─────────────
        $sentimentLabel = (string) ($entry['sentiment_label'] ?? '');
        $sentimentScore = (float)  ($entry['sentiment_score'] ?? 0.0);
        $textChanged = isset($updates['feedback']) || isset($updates['suggestions']);
        if ($textChanged) {
            $analysisText = trim(implode(' ', array_filter([$feedback, $suggestions], static fn (string $text): bool => $text !== '')));
            $analyzed = analyzeSentiment($analysisText);
            $sentimentLabel = $analyzed['label'];
            $sentimentScore = $analyzed['score'];
        }

        // ── Re-compute priority ───────────────────────────────────────
        $avgRating = computeAverageRating($ratings);
        $priority  = computePriority($sentimentLabel, $avgRating);
        $displaySentimentLabel = sentimentNormalizeLabel(
            $sentimentLabel,
            trim(implode(' ', array_filter([$feedback, $suggestions], static fn (string $text): bool => $text !== '')))
        );

        $updatedText = [
            'subject' => $subject !== '' ? $subject : null,
            'feedback' => $feedback,
            'suggestions' => $suggestions !== '' ? $suggestions : null,
            'appreciation' => $appreciation !== '' ? $appreciation : null,
        ];
        $encrypted = encryptFeedbackEntryUpdateFields($referenceId, $updatedText);
        $canonicalEncrypted = encryptCanonicalFeedbackUpdateFields($referenceId, $updatedText);
        $ratingsJson = json_encode($ratings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $update = $pdo->prepare(
            'UPDATE feedback_entries
             SET subject         = NULL,
                 feedback        = NULL,
                 suggestions     = NULL,
                 appreciation    = NULL,
                 subject_enc     = :subject_enc,
                 feedback_enc    = :feedback_enc,
                 suggestions_enc = :suggestions_enc,
                 appreciation_enc = :appreciation_enc,
                 feedback_type   = :feedback_type,
                 visit_date      = :visit_date,
                 ratings         = :ratings,
                 status          = :status,
                 priority        = :priority,
                 sentiment_label = :sentiment_label,
                 sentiment_score = :sentiment_score,
                 sentiment       = :sentiment,
                 confidence      = :confidence
             WHERE reference_id  = :reference_id'
        );
        $update->execute([
            ':subject_enc'     => $encrypted['subject_enc'],
            ':feedback_enc'    => $encrypted['feedback_enc'],
            ':suggestions_enc' => $encrypted['suggestions_enc'],
            ':appreciation_enc'=> $encrypted['appreciation_enc'],
            ':feedback_type'   => $feedbackType !== '' ? $feedbackType : null,
            ':visit_date'      => $visitDate   !== '' ? $visitDate   : null,
            ':ratings'         => $ratingsJson,
            ':status'          => $status,
            ':priority'        => $priority,
            ':sentiment_label' => $sentimentLabel !== '' ? $sentimentLabel : null,
            ':sentiment_score' => $sentimentScore,
            ':sentiment'       => $sentimentLabel !== '' ? $sentimentLabel : null,
            ':confidence'      => $sentimentScore,
            ':reference_id'    => $referenceId,
        ]);

        $canonicalUpdate = $pdo->prepare(
            'UPDATE feedbacks
             SET subject         = NULL,
                 feedback        = :feedback_plain,
                 suggestions     = NULL,
                 appreciation    = NULL,
                 subject_enc     = :subject_enc,
                 feedback_enc    = :feedback_enc,
                 suggestions_enc = :suggestions_enc,
                 appreciation_enc = :appreciation_enc,
                 feedback_type   = :feedback_type,
                 visit_date      = :visit_date,
                 ratings_json    = :ratings_json,
                 rating          = :rating,
                 status          = :status,
                 priority        = :priority,
                 sentiment_label = :sentiment_label,
                 sentiment_score = :sentiment_score,
                 sentiment       = :sentiment,
                 confidence      = :confidence,
                 updated_at      = NOW()
             WHERE reference_id  = :reference_id'
        );
        $canonicalUpdate->execute([
            // feedbacks.feedback is a legacy NOT NULL plaintext column. The
            // encrypted value remains authoritative, matching submission.
            ':feedback_plain'  => '',
            ':subject_enc'     => $canonicalEncrypted['subject_enc'],
            ':feedback_enc'    => $canonicalEncrypted['feedback_enc'],
            ':suggestions_enc' => $canonicalEncrypted['suggestions_enc'],
            ':appreciation_enc'=> $canonicalEncrypted['appreciation_enc'],
            ':feedback_type'   => $feedbackType !== '' ? $feedbackType : null,
            ':visit_date'      => $visitDate !== '' ? $visitDate : null,
            ':ratings_json'    => $ratingsJson,
            ':rating'          => $avgRating,
            ':status'          => $status,
            ':priority'        => $priority,
            ':sentiment_label' => $sentimentLabel !== '' ? $sentimentLabel : null,
            ':sentiment_score' => $sentimentScore,
            ':sentiment'       => $sentimentLabel !== '' ? $sentimentLabel : null,
            ':confidence'      => $sentimentScore,
            ':reference_id'    => $referenceId,
        ]);

        $pdo->commit();

        jsonResponse([
            'success'        => true,
            'message'        => 'Feedback updated',
            'referenceId'    => $referenceId,
            'status'         => $status,
            'priority'       => $priority,
            'sentimentLabel' => $displaySentimentLabel,
            'sentimentScore' => $sentimentScore,
        ]);

    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        sfmsLogFailure('feedback_json.update');
        jsonResponse(['success' => false, 'message' => 'Unable to update feedback.'], 500);
    }
}

jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
