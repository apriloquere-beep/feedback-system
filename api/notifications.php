<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security/AdminSecurity.php';
require_once __DIR__ . '/security/FeedbackDecryption.php';
require_once __DIR__ . '/security/RespondentType.php';

sfmsStartSecureSession();

if (!isset($_SESSION['admin_id'])) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}
sfmsRequireCsrf();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $pdo = getPdo();

    if ($method === 'GET') {
        $stmt = $pdo->query(
            'SELECT n.id, n.type, n.title, n.message, n.reference_id AS referenceId, n.is_read AS isRead,
                    DATE_FORMAT(n.created_at, "%Y-%m-%dT%H:%i:%s") AS createdAt,
                    fe.user_type AS userType, fb.respondent_type AS respondentType,
                    fb.respondent_type_enc AS respondentTypeEnc
             FROM notifications n
             LEFT JOIN feedback_entries fe ON fe.reference_id = n.reference_id
             LEFT JOIN feedbacks fb ON fb.reference_id = n.reference_id
             ORDER BY n.created_at DESC, n.id DESC
             LIMIT 100'
        );
        $decryption = new FeedbackDecryption();
        $rows = array_map(static function (array $row) use ($decryption): array {
            if (!empty($row['referenceId'])) {
                $row = $decryption->map($row, 'feedbacks', (string) $row['referenceId'], [
                    'respondentType' => ['respondentTypeEnc', 'respondentType', 'respondent_type'],
                ]);
            }
            $respondentType = trim((string) ($row['respondentType'] ?? ''));
            $row['respondentCategory'] = empty($row['referenceId']) ? '' : RespondentType::category($respondentType);
            $row['respondentType'] = RespondentType::label($respondentType);
            $row['id'] = (int) $row['id'];
            $row['isRead'] = (bool) $row['isRead'];
            unset($row['respondentTypeEnc']);
            return $row;
        }, $stmt->fetchAll());

        jsonResponse(['success' => true, 'data' => $rows]);
    }

    if ($method === 'POST') {
        $payload = readJsonBody();
        $alerts = $payload['alerts'] ?? [];
        if (!is_array($alerts)) {
            jsonResponse(['success' => false, 'message' => 'Alerts must be an array'], 422);
        }

        $insert = $pdo->prepare(
            'INSERT INTO notifications (type, title, message, reference_id, is_read)
             SELECT :type, :title, :message, NULL, 0
             WHERE NOT EXISTS (
                 SELECT 1 FROM notifications
                 WHERE reference_id IS NULL AND type = :existing_type
                   AND title = :existing_title AND message = :existing_message
             )'
        );
        $allowedTypes = ['info', 'warning', 'danger', 'success'];
        foreach ($alerts as $alert) {
            if (!is_array($alert) || ($alert['title'] ?? '') === '' || ($alert['description'] ?? '') === '') {
                continue;
            }

            // A "good" alert (currently "System Normal") is dashboard status,
            // not an actionable notification. Keep it in the summary response
            // but never persist it or increment the unread badge.
            $alertType = strtolower(trim((string) ($alert['type'] ?? '')));
            if ($alertType === 'good') {
                continue;
            }

            $type = in_array($alertType, $allowedTypes, true)
                ? $alertType
                : 'info';
            if ($type === 'success') {
                continue;
            }
            $title = (string) $alert['title'];
            $message = (string) $alert['description'];
            $insert->execute([
                ':type' => $type,
                ':title' => $title,
                ':message' => $message,
                ':existing_type' => $type,
                ':existing_title' => $title,
                ':existing_message' => $message,
            ]);
        }
        jsonResponse(['success' => true]);
    }

    if ($method === 'PATCH') {
        $payload = readJsonBody();
        $markAll = (bool) ($payload['markAll'] ?? false);
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;

        if ($markAll) {
            $pdo->exec('UPDATE notifications SET is_read = 1 WHERE is_read = 0');
            jsonResponse(['success' => true, 'message' => 'All notifications marked as read']);
        }

        if ($id <= 0) {
            jsonResponse(['success' => false, 'message' => 'A valid notification id or markAll is required'], 422);
        }

        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id');
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            jsonResponse(['success' => false, 'message' => 'Notification not found'], 404);
        }

        jsonResponse(['success' => true, 'message' => 'Notification marked as read']);
    }

    if ($method === 'DELETE') {
        $payload = readJsonBody();
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($payload['ids'] ?? [])))));
        if (!$ids) {
            jsonResponse(['success' => false, 'message' => 'Select at least one notification to delete.'], 422);
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $check = $pdo->prepare("SELECT id, is_read FROM notifications WHERE id IN ($placeholders)");
        $check->execute($ids);
        $rows = $check->fetchAll();
        foreach ($rows as $row) {
            if (!(bool) $row['is_read']) {
                jsonResponse(['success' => false, 'message' => 'Please read the notification before deleting it.'], 409);
            }
        }
        $delete = $pdo->prepare("DELETE FROM notifications WHERE id IN ($placeholders) AND is_read = 1");
        $delete->execute($ids);
        jsonResponse(['success' => true, 'deleted' => $delete->rowCount()]);
    }

    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    sfmsLogFailure('notifications.request');
    jsonResponse(['success' => false, 'message' => 'Unable to process notifications.'], 500);
}
