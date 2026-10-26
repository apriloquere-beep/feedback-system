<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Hospital Feedback System - Database Reset Script
 * 
 * Resets all feedback records to create a clean, fresh installation state.
 * This script:
 * - Truncates the feedback table
 * - Resets auto_increment to 1
 * - Clears the feedback_entries legacy table
 * - Validates empty state
 * 
 * Safety: This is a DESTRUCTIVE operation and should only be run by admins.
 */

session_start();

require_once __DIR__ . '/../../api/db.php';

const TABLES_TO_TRUNCATE = ['feedbacks', 'feedback_entries'];

try {
    $pdo = getPdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $truncatedTables = [];
    $errors = [];

    foreach (TABLES_TO_TRUNCATE as $table) {
        try {
            // Check if table exists
            $checkStmt = $pdo->query(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES 
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' LIMIT 1"
            );
            
            if (!$checkStmt->fetchColumn()) {
                // Table does not exist, skip
                continue;
            }

            // Truncate table (delete all records and reset auto_increment)
            // TRUNCATE auto-commits, so no transaction needed
            $pdo->exec("TRUNCATE TABLE `{$table}`");
            $truncatedTables[] = $table;

            error_log("[RESET] Truncated table: {$table}");
        } catch (Exception $e) {
            $errors[] = "Failed to truncate {$table}: " . $e->getMessage();
            error_log("[RESET ERROR] " . $e->getMessage());
        }
    }

    // Verify that tables are empty
    foreach (TABLES_TO_TRUNCATE as $table) {
        try {
            $checkStmt = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES 
                                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' LIMIT 1");
            if (!$checkStmt->fetchColumn()) continue;

            $countStmt = $pdo->query("SELECT COUNT(*) as cnt FROM `{$table}`");
            $result = $countStmt->fetch();
            $count = (int)($result['cnt'] ?? 0);

            if ($count > 0) {
                $errors[] = "Table {$table} still has {$count} records after truncation";
            }
        } catch (Exception $e) {
            $errors[] = "Failed to verify {$table}: " . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Reset completed with errors',
            'errors' => $errors,
            'truncated_tables' => $truncatedTables
        ], JSON_PRETTY_PRINT);
        exit;
    }

    // Success response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Hospital Feedback System successfully reset to clean state',
        'details' => [
            'truncated_tables' => $truncatedTables,
            'timestamp' => date('Y-m-d H:i:s'),
            'auto_increment_reset' => true,
            'records_deleted' => 0
        ]
    ], JSON_PRETTY_PRINT);

    error_log("[RESET SUCCESS] Database reset completed. Tables truncated: " . implode(', ', $truncatedTables));

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database reset failed',
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);

    error_log("[RESET CRITICAL ERROR] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
}

