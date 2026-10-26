<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security/AdminSecurity.php';

sfmsStartSecureSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}
if (!isset($_SESSION['admin_id'])) jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
sfmsRequireCsrf();

$payload = readJsonBody();
$referenceId = trim((string) ($payload['referenceId'] ?? ''));

if ($referenceId === '') {
    jsonResponse(['success' => false, 'message' => 'referenceId is required'], 422);
}

try {
    $pdo = getPdo();

    $pdo->beginTransaction();

    $entry = $pdo->prepare(
        'SELECT id FROM feedback_entries WHERE reference_id = :reference_id LIMIT 1 FOR UPDATE'
    );
    $entry->execute([':reference_id' => $referenceId]);
    if (!$entry->fetchColumn()) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'Reference not found'], 404);
    }

    $canonical = $pdo->prepare(
        'SELECT id FROM feedbacks WHERE reference_id = :reference_id LIMIT 1 FOR UPDATE'
    );
    $canonical->execute([':reference_id' => $referenceId]);
    if (!$canonical->fetchColumn()) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'Paired feedback record not found'], 409);
    }

    // Permanent deletion is disallowed for audit compliance. Perform a soft-archive instead.
    // Both current feedback rows share workflow state, so archive them atomically.
    $stmt = $pdo->prepare('UPDATE feedback_entries SET status = :status, updated_at = NOW() WHERE reference_id = :reference_id');
    $stmt->execute([':status' => 'archived', ':reference_id' => $referenceId]);

    $canonicalUpdate = $pdo->prepare(
        'UPDATE feedbacks SET status = :status, updated_at = NOW() WHERE reference_id = :reference_id'
    );
    $canonicalUpdate->execute([':status' => 'archived', ':reference_id' => $referenceId]);

    $pdo->commit();

    jsonResponse(['success' => true, 'message' => 'Feedback archived (deletion disabled)']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sfmsLogFailure('feedback_delete.update');
    jsonResponse(['success' => false, 'message' => 'Unable to archive feedback.'], 500);
}
