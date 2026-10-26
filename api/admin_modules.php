<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sentiment.php';
require_once __DIR__ . '/security/FeedbackDecryption.php';
require_once __DIR__ . '/security/AdminSecurity.php';

sfmsStartSecureSession();

if (!isset($_SESSION['admin_id'])) {
    adminJson(['success' => false, 'message' => 'Unauthorized'], 401);
}

function adminNormalizeStatus(string $status): string
{
    $status = strtolower(trim($status));

    if ($status === 'adjusted' || $status === 'reviewed') {
        return 'verified';
    }

    return in_array($status, ['pending', 'verified', 'resolved'], true) ? $status : 'pending';
}

function adminDecodeRatings(string $ratingsJson): array
{
    $decoded = json_decode($ratingsJson, true);
    if (!is_array($decoded)) {
        return [];
    }

    $ratings = [];
    foreach ($decoded as $key => $value) {
        if (is_array($value) && array_key_exists('score', $value)) {
            $ratings[$key] = (float) $value['score'];
            continue;
        }

        if (is_numeric($value)) {
            $ratings[$key] = (float) $value;
        }
    }

    return $ratings;
}

function adminAverageRating(array $ratings): float
{
    $values = array_values(array_filter(array_map(static fn ($value): float => (float) $value, $ratings), static fn (float $value): bool => $value > 0));

    if (!$values) {
        return 0.0;
    }

    return round(array_sum($values) / count($values), 1);
}

function adminIsoTimestamp(?string $value): string
{
    if (!$value) {
        return date('c');
    }

    $time = strtotime($value);
    return $time ? date('c', $time) : date('c');
}
sfmsRequireCsrf();

function adminAuditActionLabel(string $action): string
{
    return match ($action) {
        'status_changed' => 'Feedback Status Changed',
        'settings_updated' => 'Settings Updated',
        default => ucwords(str_replace('_', ' ', $action)),
    };
}

function adminAlertSettings(PDO $pdo): array
{
    $settings = ['pendingThreshold' => 10, 'negativeSentimentThreshold' => 20];
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('admin_pending_threshold', 'admin_negative_sentiment_alert_percent')");
    foreach ($stmt->fetchAll() as $row) {
        $value = filter_var($row['setting_value'], FILTER_VALIDATE_INT);
        if ($value === false) continue;
        if ($row['setting_key'] === 'admin_pending_threshold' && $value >= 0) $settings['pendingThreshold'] = $value;
        if ($row['setting_key'] === 'admin_negative_sentiment_alert_percent' && $value >= 0 && $value <= 100) $settings['negativeSentimentThreshold'] = $value;
    }
    return $settings;
}

function adminFeedbackRows(PDO $pdo, ?string $statusFilter = null): array
{
    $sql = 'SELECT
                fe.reference_id AS referenceId,
                fe.user_type AS userType,
                fe.is_anonymous AS isAnonymous,
                fe.feedback_type AS feedbackType,
                fe.service_id AS serviceId,
                fe.service_subtype_id AS serviceSubtypeId,
                COALESCE(s.name, fe.service) AS service,
                DATE_FORMAT(fe.visit_date, "%Y-%m-%d") AS visitDate,
                fe.subject, fe.subject_enc AS subjectEnc,
                fe.feedback, fe.feedback_enc AS feedbackEnc,
                fe.suggestions, fe.suggestions_enc AS suggestionsEnc,
                fe.ratings AS ratings,
                fe.sentiment_label AS sentimentLabel,
                fe.status,
                DATE_FORMAT(fe.created_at, "%Y-%m-%dT%H:%i:%s") AS timestamp,
                DATE_FORMAT(fe.updated_at, "%Y-%m-%dT%H:%i:%s") AS updatedAt
             FROM feedback_entries fe
             LEFT JOIN services s ON s.id = fe.service_id';

    $params = [];
    if ($statusFilter !== null) {
        $sql .= ' WHERE fe.status = :status';
        $params[':status'] = $statusFilter;
    }

    $sql .= ' ORDER BY fe.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $entriesByRef = [];
    $decryption = new FeedbackDecryption();
    foreach ($stmt->fetchAll() as $row) {
        $referenceId = (string) ($row['referenceId'] ?? '');
        if ($referenceId === '' || isset($entriesByRef[$referenceId])) {
            continue;
        }

        $row = $decryption->map($row, 'feedback_entries', $referenceId, [
            'subject' => ['subjectEnc', 'subject'],
            'feedback' => ['feedbackEnc', 'feedback'],
            'suggestions' => ['suggestionsEnc', 'suggestions'],
        ]);
        $sentimentText = trim(implode(' ', array_filter([
            (string) ($row['feedback'] ?? ''),
            (string) ($row['suggestions'] ?? ''),
        ], static fn (string $text): bool => trim($text) !== '')));
        $sentimentLabel = sentimentNormalizeLabel((string) ($row['sentimentLabel'] ?? ''), $sentimentText);

        $ratings = adminDecodeRatings((string) ($row['ratings'] ?? ''));
        $timestamp = adminIsoTimestamp((string) ($row['timestamp'] ?? ''));

        $entriesByRef[$referenceId] = [
            'referenceId' => $referenceId,
            'userType' => (string) ($row['userType'] ?? ''),
            'isAnonymous' => (bool) ($row['isAnonymous'] ?? false),
            'feedbackType' => (string) ($row['feedbackType'] ?? ''),
            'serviceId' => $row['serviceId'] !== null ? (int) $row['serviceId'] : null,
            'serviceSubtypeId' => $row['serviceSubtypeId'] !== null ? (int) $row['serviceSubtypeId'] : null,
            'service' => (string) ($row['service'] ?? 'Unassigned'),
            'visitDate' => (string) ($row['visitDate'] ?? ''),
            'subject' => (string) ($row['subject'] ?? ''),
            'feedback' => (string) ($row['feedback'] ?? ''),
            'suggestions' => (string) ($row['suggestions'] ?? ''),
            'ratings' => $ratings,
            'serviceRating' => (float) ($ratings['service'] ?? 0),
            'staffRating' => (float) ($ratings['staff'] ?? 0),
            'facilityRating' => (float) ($ratings['facility'] ?? 0),
            'averageRating' => adminAverageRating($ratings),
            'sentimentLabel' => $sentimentLabel,
            'status' => adminNormalizeStatus((string) ($row['status'] ?? 'pending')),
            'timestamp' => $timestamp,
            'updatedAt' => adminIsoTimestamp((string) ($row['updatedAt'] ?? $timestamp)),
        ];
    }

    return array_values($entriesByRef);
}

function adminSummary(array $entries, array $alertSettings = []): array
{
    $pendingThreshold = max(0, (int) ($alertSettings['pendingThreshold'] ?? 10));
    $negativeSentimentThreshold = min(100, max(0, (int) ($alertSettings['negativeSentimentThreshold'] ?? 20)));
    $total = count($entries);
    $pending = 0;
    $verified = 0;
    $resolved = 0;
    $ratingSum = 0.0;
    $ratingCount = 0;
    $serviceStatsMap = [];
    $trend = ['daily' => [], 'weekly' => [], 'monthly' => []];
    $recentComments = [];
    $recentActivity = [];
    $distribution = [
        'complaint' => 0,
        'compliment' => 0,
        'inquiry' => 0,
        'suggestion' => 0,
    ];

    $now = time();
    $weekAgo = $now - (7 * 24 * 60 * 60);
    $lowRatings = 0;
    $complaints = 0;
    $negativeSentimentCount = 0;
    $sentimentCount = 0;

    foreach ($entries as $entry) {
        $status = adminNormalizeStatus((string) ($entry['status'] ?? 'pending'));
        if ($status === 'pending') {
            $pending++;
        } elseif ($status === 'verified') {
            $verified++;
        } elseif ($status === 'resolved') {
            $resolved++;
        }

        $rating = (float) ($entry['averageRating'] ?? 0.0);
        if ($rating > 0) {
            $ratingSum += $rating;
            $ratingCount++;
        }

        $feedbackType = strtolower((string) ($entry['feedbackType'] ?? ''));
        if (array_key_exists($feedbackType, $distribution)) {
            $distribution[$feedbackType]++;
        }

        $sentimentLabel = (string) ($entry['sentimentLabel'] ?? '');
        if (in_array($sentimentLabel, ['Positive', 'Neutral', 'Negative'], true)) {
            $sentimentCount++;
            if ($sentimentLabel === 'Negative') $negativeSentimentCount++;
        }

        $service = trim((string) ($entry['service'] ?? 'Unassigned')) ?: 'Unassigned';
        $serviceId = $entry['serviceId'] !== null ? (int) $entry['serviceId'] : 0;
        $serviceKey = $serviceId > 0 ? 'id:' . $serviceId : 'unassigned';
        if (!isset($serviceStatsMap[$serviceKey])) {
            $serviceStatsMap[$serviceKey] = ['serviceId' => $serviceId ?: null, 'label' => $service, 'count' => 0, 'sum' => 0.0, 'pending' => 0];
        }
        $serviceStatsMap[$serviceKey]['count']++;
        $serviceStatsMap[$serviceKey]['sum'] += $rating;
        if ($status === 'pending') {
            $serviceStatsMap[$serviceKey]['pending']++;
        }

        $timestamp = adminIsoTimestamp((string) ($entry['timestamp'] ?? ''));
        $time = strtotime($timestamp) ?: $now;
        $dayLabel = date('M j', $time);
        $weekStart = strtotime('monday this week', $time);
        $weekEnd = strtotime('sunday this week', $time);
        $weekLabel = date('M j', $weekStart) . ' - ' . date('M j', $weekEnd);
        $monthLabel = date('M Y', $time);

        $trend['daily'][$dayLabel] = ($trend['daily'][$dayLabel] ?? 0) + 1;
        $trend['weekly'][$weekLabel] = ($trend['weekly'][$weekLabel] ?? 0) + 1;
        $trend['monthly'][$monthLabel] = ($trend['monthly'][$monthLabel] ?? 0) + 1;

        if (!empty($entry['feedback'])) {
            $recentComments[] = [
                'referenceId' => $entry['referenceId'] ?? '',
                'timestamp' => $timestamp,
                'name' => $entry['name'] ?? 'Guest',
                'service' => $service,
                'message' => (string) $entry['feedback'],
                'type' => 'comment',
            ];
        }

        $recentActivity[] = [
            'referenceId' => $entry['referenceId'] ?? '',
            'timestamp' => $timestamp,
            'name' => $entry['name'] ?? 'Guest',
            'service' => $service,
            'message' => $status === 'pending' ? 'Awaiting verification' : 'Marked as ' . $status,
            'type' => 'status',
        ];

        if ($time >= $weekAgo) {
            if ($rating <= 2.5) {
                $lowRatings++;
            }
            if ($feedbackType === 'complaint') {
                $complaints++;
            }
        }
    }

    $serviceStats = [];
    foreach ($serviceStatsMap as $group) {
        if ($group['count'] < 3) {
            continue;
        }

        $serviceStats[] = [
            'serviceId' => $group['serviceId'],
            'label' => $group['label'],
            'count' => (int) $group['count'],
            'averageRating' => round((float) $group['sum'] / (int) $group['count'], 1),
            'pendingCount' => (int) $group['pending'],
        ];
    }

    usort($serviceStats, static function (array $left, array $right): int {
        if ($left['averageRating'] === $right['averageRating']) {
            return $right['count'] <=> $left['count'];
        }

        return $right['averageRating'] <=> $left['averageRating'];
    });

    foreach ($trend as $bucket => $values) {
        ksort($trend[$bucket]);
        $trend[$bucket] = array_map(static fn (string $label, int $count): array => ['label' => $label, 'count' => $count], array_keys($trend[$bucket]), array_values($trend[$bucket]));
    }

    $bestService = $serviceStats[0] ?? null;
    $lowService = null;
    foreach (array_reverse($serviceStats) as $candidate) {
        if ($candidate['averageRating'] <= 3.0) {
            $lowService = $candidate;
            break;
        }
    }

    $alerts = [];
    if ($lowRatings > 0) {
        $alerts[] = [
            'type' => 'warn',
            'title' => 'Low ratings detected',
            'description' => $lowRatings . ' recent submission' . ($lowRatings === 1 ? '' : 's') . ' rated 2.5 or lower.',
        ];
    }

    if ($complaints >= 3) {
        $alerts[] = [
            'type' => 'danger',
            'title' => 'Complaint spike',
            'description' => $complaints . ' complaint' . ($complaints === 1 ? '' : 's') . ' recorded in the last 7 days.',
        ];
    }

    if ($pending > 0 && $pending >= $pendingThreshold) {
        $alerts[] = ['type' => 'warn', 'title' => 'High Pending Queue', 'description' => $pending . ' feedback submission' . ($pending === 1 ? '' : 's') . ' awaiting review (threshold: ' . $pendingThreshold . ').'];
    }
    $negativeSentimentPercent = $sentimentCount > 0 ? ($negativeSentimentCount / $sentimentCount) * 100 : 0.0;
    if ($negativeSentimentCount > 0 && $negativeSentimentPercent >= $negativeSentimentThreshold) {
        $alerts[] = ['type' => 'warn', 'title' => 'High Negative Sentiment Rate', 'description' => round($negativeSentimentPercent, 1) . '% negative feedback rate reached the ' . $negativeSentimentThreshold . '% threshold.'];
    }

    if (!$alerts) {
        $alerts[] = [
            'type' => 'good',
            'title' => 'System Normal',
            'description' => 'No active issues. Feedback levels and service quality are stable.',
        ];
    }

    return [
        'total' => $total,
        'pending' => $pending,
        'verified' => $verified,
        'resolved' => $resolved,
        'actioned' => $verified + $resolved,
        'averageRating' => $ratingCount > 0 ? round($ratingSum / $ratingCount, 1) : 0.0,
        'resolutionRate' => $total > 0 ? round((($verified + $resolved) / $total) * 100, 1) : 0.0,
        'distribution' => $distribution,
        'serviceStats' => $serviceStats,
        'bestService' => $bestService,
        'lowService' => $lowService,
        'alerts' => $alerts,
        'recentComments' => array_slice(array_reverse($recentComments), 0, 5),
        'recentActivity' => array_slice(array_reverse($recentActivity), 0, 5),
        'trend' => $trend,
    ];
}

function adminJson(array $payload, int $statusCode = 200): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$entity = strtolower(trim((string) ($_GET['entity'] ?? $_POST['entity'] ?? '')));
$action = strtolower(trim((string) ($_GET['action'] ?? $_POST['action'] ?? '')));
$section = strtolower(trim((string) ($_GET['section'] ?? 'all')));

try {
    $pdo = getPdo();

    if ($method === 'GET') {
        if ($section === 'subservices') {
            $serviceId = filter_var($_GET['service_id'] ?? null, FILTER_VALIDATE_INT);
            if ($serviceId === false || $serviceId === null || $serviceId < 1) {
                adminJson(['success' => false, 'message' => 'A valid service ID is required'], 422);
            }

            $subtypesStmt = $pdo->prepare(
                "SELECT id, service_id, name, status
                 FROM service_subtypes
                 WHERE service_id = :service_id
                 ORDER BY CASE WHEN name = 'Other' THEN 1 ELSE 0 END, id ASC"
            );
            $subtypesStmt->execute([':service_id' => $serviceId]);
            adminJson(['success' => true, 'data' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'serviceId' => (int) $row['service_id'],
                'name' => (string) $row['name'],
                'status' => (string) $row['status'],
            ], $subtypesStmt->fetchAll())]);
        }

        $feedbackEntries = adminFeedbackRows($pdo);
        $alertSettings = adminAlertSettings($pdo);
        $payload = [
            'feedbacks' => $feedbackEntries,
            'pending' => array_values(array_filter($feedbackEntries, static fn (array $entry): bool => ($entry['status'] ?? 'pending') === 'pending')),
            'resolved' => array_values(array_filter($feedbackEntries, static fn (array $entry): bool => ($entry['status'] ?? 'pending') === 'resolved')),
            'reports' => adminSummary($feedbackEntries, $alertSettings),
        ];

        // NOTE on rating source: the legacy patient_feedback.php/visitor_feedback.php
        // pipeline stores ratings under literal "service"/"staff"/"facility" JSON
        // keys, but feedback2.php (the current, canonical form) stores 29 flat
        // keys (sqd4, a2, st0, ...) and never those three — so JSON_EXTRACT on
        // those paths returns NULL for virtually all current data. feedbacks.rating
        // is a reliably-populated overall score for both pipelines (computed at
        // submission time), so aggregates are computed from that instead.
        //
        // Service aggregates use the canonical service_id relationship. The
        // service migration backfills IDs for historical rows whose text
        // already matched a canonical service name.
        $servicesStmt = $pdo->query(
            "SELECT s.id, s.name, s.status,
                    COUNT(fe.id) AS total_feedback,
                    COALESCE(ROUND(AVG(NULLIF(fb.rating, 0)), 1), 0) AS avg_rating
             FROM services s
             LEFT JOIN feedback_entries fe ON fe.service_id = s.id
             LEFT JOIN feedbacks fb ON fb.reference_id = fe.reference_id
             GROUP BY s.id, s.name, s.status
               ORDER BY s.id ASC"
        );
           $subtypesStmt = $pdo->query(
              "SELECT id, service_id, name
               FROM service_subtypes
               WHERE status = 'active'
               ORDER BY CASE WHEN name = 'Other' THEN 1 ELSE 0 END, id ASC"
           );
        $subtypesByService = [];
        foreach ($subtypesStmt->fetchAll() as $subtype) {
            $subtypesByService[(int) $subtype['service_id']][] = [
                'id' => (int) $subtype['id'],
                'name' => (string) $subtype['name'],
            ];
        }

        // Staff Ratings catalog — the 10 fixed st0-st9 role rows (full_name
        // is the editable display label, role is the stable st-key). See
        // api/staff_roles.php for the CRUD that maintains this table.
        $staffRolesStmt = $pdo->query('SELECT id, full_name, role, is_active, archived_at FROM staff ORDER BY LENGTH(role), role');

        $usersStmt = $pdo->query('SELECT id, full_name, email, role, status, last_login, created_at FROM admin_users ORDER BY created_at DESC');
        $notificationsStmt = $pdo->query('SELECT id, type, title, message, reference_id, is_read, created_by, created_at FROM notifications ORDER BY created_at DESC');
        // audit_trail is the single-admin audit source. Obsolete staff
        // management history is intentionally excluded; no staff-management
        // feature is part of this log.
        $auditStmt = $pdo->query(
            "SELECT at.id, 'Super Admin' AS admin_name, at.action,
                    fe.reference_id AS reference_id, at.notes AS detail, at.created_at
             FROM audit_trail at
             LEFT JOIN feedback_entries fe ON fe.id = at.feedback_id AND at.feedback_id > 0
             WHERE at.action NOT IN ('staff_created', 'staff_updated', 'staff_archived')
             ORDER BY at.created_at DESC"
        );
        $settingsStmt = $pdo->query('SELECT setting_key, setting_value, updated_by, updated_at FROM system_settings ORDER BY setting_key');

        $payload['services'] = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'status' => (string) $row['status'],
            'totalFeedback' => (int) $row['total_feedback'],
            'averageRating' => (float) $row['avg_rating'],
            'subservices' => $subtypesByService[(int) $row['id']] ?? [],
        ], $servicesStmt->fetchAll());

        $payload['staffRoles'] = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'key' => (string) $row['role'],
            'label' => (string) $row['full_name'],
            'isActive' => (bool) $row['is_active'],
            'archivedAt' => $row['archived_at'],
        ], $staffRolesStmt->fetchAll());

        $payload['users'] = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'fullName' => (string) $row['full_name'],
            'email' => (string) $row['email'],
            'role' => (string) $row['role'],
            'status' => (string) $row['status'],
            'lastLogin' => $row['last_login'] ? adminIsoTimestamp((string) $row['last_login']) : null,
            'createdAt' => adminIsoTimestamp((string) $row['created_at']),
        ], $usersStmt->fetchAll());

        $payload['notifications'] = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'type' => (string) $row['type'],
            'title' => (string) $row['title'],
            'message' => (string) $row['message'],
            'referenceId' => (string) ($row['reference_id'] ?? ''),
            'isRead' => (bool) $row['is_read'],
            'createdBy' => (string) ($row['created_by'] ?? ''),
            'timestamp' => adminIsoTimestamp((string) $row['created_at']),
        ], $notificationsStmt->fetchAll());

        $payload['audit'] = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'adminName' => 'Super Admin',
            'action' => adminAuditActionLabel((string) $row['action']),
            'referenceId' => (string) ($row['reference_id'] ?? ''),
            'detail' => (string) $row['detail'],
            'timestamp' => adminIsoTimestamp((string) $row['created_at']),
        ], $auditStmt->fetchAll());

        $settings = [];
        foreach ($settingsStmt->fetchAll() as $row) {
            $settings[(string) $row['setting_key']] = [
                'value' => (string) $row['setting_value'],
                'updatedBy' => (string) ($row['updated_by'] ?? ''),
                'updatedAt' => adminIsoTimestamp((string) $row['updated_at']),
            ];
        }
        $payload['settings'] = $settings;

        if ($section !== 'all' && array_key_exists($section, $payload)) {
            adminJson(['success' => true, 'data' => $payload[$section]]);
        }

        adminJson(['success' => true, 'data' => $payload]);
    }

    // The mutation routing that used to live here (entity=feedback/
    // service/user/notification/settings, plus a duplicate
    // entity=notification block) was removed after confirming it was
    // unreachable: nothing in the project ever sent an `entity` parameter
    // to this endpoint. The only function that would have
    // (js/admin/feedbackService.js's mutateAdminModule) has zero callers
    // anywhere, and even if it were called, it sends `entity` inside the
    // JSON request body while this file only ever read it from
    // $_GET/$_POST, which PHP never populates for a JSON body. The live
    // equivalents are: api/catalog.php (service CRUD),
    // api/feedback_update_status.php and api/feedback_delete.php
    // (feedback status/archive), and api/notifications.php
    // (notifications). Staff Management (staff_create/update/delete.php)
    // was removed entirely — Staff Ratings is role-based (st0-st9 in
    // ratings_json) and needs no staff CRUD.
    adminJson(['success' => false, 'message' => 'Unsupported entity or action'], 400);
} catch (Throwable $e) {
    sfmsLogFailure('admin_modules.request');
    adminJson(['success' => false, 'message' => 'Unable to process the admin request.'], 500);
}
