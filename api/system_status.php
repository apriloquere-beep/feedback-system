<?php
/**
 * API Test - Verify Feedback Data via API Endpoint
 */

header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security/FeedbackDecryption.php';
require_once __DIR__ . '/security/AdminSecurity.php';
sfmsStartSecureSession();

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $pdo = getPdo();
    
    // Simulate what the dashboard API would return
    $stmt = $pdo->query('
        SELECT 
            id,
            reference_id,
            service,
            visit_date,
            subject,
            subject_enc AS subjectEnc,
            feedback,
            feedback_enc AS feedbackEnc,
            suggestions,
            suggestions_enc AS suggestionsEnc,
            ratings,
            is_anonymous,
            status,
            created_at
        FROM feedback_entries
        ORDER BY created_at DESC
    ');
    
    $decryption = new FeedbackDecryption();
    $feedbacks = array_map(static function (array $row) use ($decryption): array {
        return $decryption->map($row, 'feedback_entries', (string) $row['reference_id'], [
            'subject' => ['subjectEnc', 'subject'],
            'feedback' => ['feedbackEnc', 'feedback'],
            'suggestions' => ['suggestionsEnc', 'suggestions'],
        ]);
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Return as JSON
    echo json_encode([
        'success' => true,
        'feedback_count' => count($feedbacks),
        'feedbacks' => $feedbacks,
        'system_status' => 'READY',
        'database_health' => 'VERIFIED'
    ], JSON_PRETTY_PRINT);
    
} catch (Throwable $e) {
    sfmsLogFailure('system_status.read');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to verify system status.'
    ]);
}
