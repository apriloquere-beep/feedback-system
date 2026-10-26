<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sentiment.php';
require_once __DIR__ . '/security/AdminSecurity.php';

sfmsStartSecureSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

if (!isset($_SESSION['admin_id'])) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}
sfmsRequireCsrf();

$payload = readJsonBody();
$referenceId = (string) ($payload['referenceId'] ?? '');
$status = (string) ($payload['status'] ?? '');

if ($referenceId === '' || $status === '') {
    jsonResponse(['success' => false, 'message' => 'referenceId and status are required'], 422);
}

// Accept the professional lifecycle statuses and map common synonyms from older clients
$allowedStatuses = ['pending', 'in_review', 'resolved', 'archived', 'adjusted', 'verified'];
if (!in_array($status, $allowedStatuses, true)) {
    jsonResponse(['success' => false, 'message' => 'Invalid status'], 422);
}

// Normalize legacy/synonym statuses to the canonical enum values used by the DB
if ($status === 'adjusted') {
    $status = 'resolved';
}
if ($status === 'verified') {
    $status = 'in_review';
}

try {
    $pdo = getPdo();

    $pdo->beginTransaction();

    $select = $pdo->prepare(
        'SELECT id, status, subject, feedback, suggestions, sentiment_label, sentiment_score
         FROM feedback_entries
         WHERE reference_id = :reference_id
         LIMIT 1'
    );
    $select->execute([':reference_id' => $referenceId]);
    $entry = $select->fetch(PDO::FETCH_ASSOC);

    if (!$entry) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'Reference not found'], 404);
    }

    $oldStatus = (string) ($entry['status'] ?? 'pending');
    if ($oldStatus === $status) {
        $pdo->rollBack();
        jsonResponse(['success' => true, 'message' => 'Status unchanged', 'oldStatus' => $oldStatus, 'newStatus' => $status]);
    }

    $stmt = $pdo->prepare('UPDATE feedback_entries SET status = :status, updated_at = NOW() WHERE reference_id = :reference_id');
    $stmt->execute([
        ':status' => $status,
        ':reference_id' => $referenceId,
    ]);

    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'Reference not found'], 404);
    }

    // Keep the canonical and compatibility feedback rows in the same
    // lifecycle state. Both rows are created atomically at submission time.
    $canonicalUpdate = $pdo->prepare(
        'UPDATE feedbacks SET status = :status, updated_at = NOW() WHERE reference_id = :reference_id'
    );
    $canonicalUpdate->execute([
        ':status' => $status,
        ':reference_id' => $referenceId,
    ]);
    if ($canonicalUpdate->rowCount() === 0) {
        // MySQL may report zero affected rows when the paired row already
        // has this status and its timestamp does not change within the same
        // second. Distinguish that harmless case from a missing pair.
        $paired = $pdo->prepare('SELECT 1 FROM feedbacks WHERE reference_id = :reference_id LIMIT 1');
        $paired->execute([':reference_id' => $referenceId]);
        if (!$paired->fetchColumn()) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Paired feedback record not found'], 409);
        }
    }

    $userName = 'Super Admin';
    $userId = (int) $_SESSION['admin_id'];

    $audit = $pdo->prepare(
        'INSERT INTO audit_trail (feedback_id, user_id, user_name, action, notes, created_at)
         VALUES (:feedback_id, :user_id, :user_name, :action, :notes, NOW())'
    );
    $audit->execute([
        ':feedback_id' => (int) $entry['id'],
        ':user_id' => $userId,
        ':user_name' => $userName,
        ':action' => 'status_changed',
        ':notes' => sprintf('Status changed from %s to %s via admin dashboard', $oldStatus, $status),
    ]);

    $durationSeconds = 30;

    $undoPayload = [
        'referenceId' => $referenceId,
        'oldStatus' => $oldStatus,
        'newStatus' => $status,
        'userName' => $userName,
        'createdAt' => date('c'),
        'undoUntil' => date('c', time() + $durationSeconds),
    ];

    $pdo->commit();

    jsonResponse([
        'success' => true,
        'message' => 'Status updated',
        'oldStatus' => $oldStatus,
        'newStatus' => $status,
        'undo' => $undoPayload,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sfmsLogFailure('feedback_status.update');
    jsonResponse(['success' => false, 'message' => 'Unable to update feedback status.'], 500);
}
