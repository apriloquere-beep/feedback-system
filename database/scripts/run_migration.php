<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Add archived_at column to staff table
 */

try {
    $pdo = new PDO('mysql:host=localhost;dbname=web_system', 'root', '');
    
    // Check if column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM staff LIKE 'archived_at'");
    $exists = $stmt->fetch();
    
    if (!$exists) {
        $pdo->exec('ALTER TABLE staff ADD COLUMN archived_at DATETIME NULL AFTER is_active');
        echo "✓ Column archived_at added to staff table\n";
    } else {
        echo "✓ Column archived_at already exists\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
