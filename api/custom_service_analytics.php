<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security/AdminSecurity.php';

sfmsStartSecureSession();

if (!isset($_SESSION['admin_id'])) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

function hasCustomServiceColumn(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name
         LIMIT 1'
    );
    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => 'is_custom_service',
    ]);
    return (bool) $stmt->fetchColumn();
}

try {
    $pdo = getPdo();

    if (!hasCustomServiceColumn($pdo, 'feedbacks')) {
        jsonResponse([
            'success' => false,
            'message' => 'Schema not ready: missing is_custom_service column in feedbacks table.',
        ], 500);
    }

    $normalStmt = $pdo->query(
        "SELECT s.id AS service_id, s.name AS service, AVG(f.rating) AS avg_rating, COUNT(*) AS feedback_count
         FROM feedbacks f
         INNER JOIN services s ON s.id = f.service_id
         WHERE f.is_custom_service = 0
         GROUP BY s.id, s.name
         ORDER BY avg_rating DESC"
    );

    $customByServiceStmt = $pdo->query(
        "SELECT s.id AS service_id, s.name AS service, AVG(f.rating) AS avg_rating, COUNT(*) AS feedback_count
         FROM feedbacks f
         INNER JOIN services s ON s.id = f.service_id
         WHERE f.is_custom_service = 1
         GROUP BY s.id, s.name
         ORDER BY avg_rating DESC"
    );

    $overallCustomStmt = $pdo->query(
        'SELECT AVG(rating) AS avg_rating
         FROM feedbacks
         WHERE is_custom_service = 1'
    );

    jsonResponse([
        'success' => true,
        'data' => [
            'normalServices' => $normalStmt->fetchAll(PDO::FETCH_ASSOC),
            'customServices' => $customByServiceStmt->fetchAll(PDO::FETCH_ASSOC),
            'overallCustomService' => $overallCustomStmt->fetch(PDO::FETCH_ASSOC),
        ],
    ]);
} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'message' => 'Unable to load custom service analytics right now.',
    ], 500);
}
