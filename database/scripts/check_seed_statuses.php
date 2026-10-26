<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../../api/db.php';

$pdo = getPdo();

$tables = ['feedback_entries', 'feedbacks'];
foreach ($tables as $table) {
    echo "Table: {$table}\n";
    $stmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM {$table} WHERE reference_id LIKE 'FB-20260504-%' GROUP BY status ORDER BY status");
    $rows = $stmt->fetchAll();
    if (!$rows) {
        echo "  (no rows)\n";
        continue;
    }
    foreach ($rows as $row) {
        echo "  {$row['status']}: {$row['cnt']}\n";
    }
}
