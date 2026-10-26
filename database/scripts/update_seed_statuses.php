<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../../api/db.php';

$resolved = [
    'FB-20260504-001',
    'FB-20260504-002',
    'FB-20260504-004',
    'FB-20260504-006',
    'FB-20260504-009',
    'FB-20260504-011',
    'FB-20260504-016',
    'FB-20260504-020',
];

$inReview = [
    'FB-20260504-003',
    'FB-20260504-005',
    'FB-20260504-007',
    'FB-20260504-008',
    'FB-20260504-012',
    'FB-20260504-018',
    'FB-20260504-023',
];

try {
    $pdo = getPdo();
    $pdo->beginTransaction();

    $pdo->exec("UPDATE feedbacks SET status = 'pending' WHERE reference_id LIKE 'FB-20260504-%'");
    $pdo->exec("UPDATE feedback_entries SET status = 'pending' WHERE reference_id LIKE 'FB-20260504-%'");

    $resolvedNew = $pdo->prepare("UPDATE feedbacks SET status = 'resolved' WHERE reference_id = ?");
    $resolvedLegacy = $pdo->prepare("UPDATE feedback_entries SET status = 'resolved' WHERE reference_id = ?");
    foreach ($resolved as $referenceId) {
        $resolvedNew->execute([$referenceId]);
        $resolvedLegacy->execute([$referenceId]);
    }

    $reviewNew = $pdo->prepare("UPDATE feedbacks SET status = 'in_review' WHERE reference_id = ?");
    $reviewLegacy = $pdo->prepare("UPDATE feedback_entries SET status = 'adjusted' WHERE reference_id = ?");
    foreach ($inReview as $referenceId) {
        $reviewNew->execute([$referenceId]);
        $reviewLegacy->execute([$referenceId]);
    }

    $pdo->commit();

    $pending = 25 - count($resolved) - count($inReview);
    echo "Updated statuses for FB-20260504 imports\n";
    echo "Resolved: " . count($resolved) . "\n";
    echo "In review: " . count($inReview) . "\n";
    echo "Pending: " . $pending . "\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Status update failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
