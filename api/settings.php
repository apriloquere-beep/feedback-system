<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security/AdminSecurity.php';

sfmsStartSecureSession();

if (!isset($_SESSION['admin_id'])) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}
sfmsRequireCsrf();
function settingsDefaults(): array
{
    return [
        'facility' => 'Calape Primary Care Facility',
        'benchmark' => 80,
        'threshold' => 10,
        'negPct' => 20,
        'autoResolvePositive' => false,
    ];
}

function readSettings(PDO $pdo): array
{
    $settings = settingsDefaults();
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('admin_facility_name', 'admin_satisfaction_benchmark', 'admin_pending_threshold', 'admin_negative_sentiment_alert_percent', 'admin_auto_resolve_positive_feedback')");
    foreach ($stmt->fetchAll() as $row) {
        $value = (string) $row['setting_value'];
        switch ($row['setting_key']) {
            case 'admin_facility_name':
                if (trim($value) !== '') $settings['facility'] = $value;
                break;
            case 'admin_satisfaction_benchmark':
                if (filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value >= 0 && (int) $value <= 100) $settings['benchmark'] = (int) $value;
                break;
            case 'admin_pending_threshold':
                if (filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value >= 0) $settings['threshold'] = (int) $value;
                break;
            case 'admin_negative_sentiment_alert_percent':
                if (filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value >= 0 && (int) $value <= 100) $settings['negPct'] = (int) $value;
                break;
            case 'admin_auto_resolve_positive_feedback':
                $settings['autoResolvePositive'] = $value === '1';
                break;
        }
    }
    return $settings;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    try {
        jsonResponse(['success' => true, 'data' => readSettings(getPdo())]);
    } catch (Throwable $e) {
        error_log('[settings] Unable to load settings.');
        jsonResponse(['success' => false, 'message' => 'Unable to load settings.'], 500);
    }
}
if ($method !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$payload = readJsonBody();
$facility = trim((string) ($payload['facility'] ?? ''));
$benchmark = filter_var($payload['benchmark'] ?? null, FILTER_VALIDATE_INT);
$threshold = filter_var($payload['threshold'] ?? null, FILTER_VALIDATE_INT);
$negPct = filter_var($payload['negPct'] ?? null, FILTER_VALIDATE_INT);
$autoResolve = filter_var($payload['autoResolvePositive'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

if ($facility === '' || mb_strlen($facility) > 160) {
    jsonResponse(['success' => false, 'message' => 'Facility name is required and must be 160 characters or fewer.'], 422);
}
if ($benchmark === false || $benchmark < 0 || $benchmark > 100
    || $threshold === false || $threshold < 0 || $threshold > 10000
    || $negPct === false || $negPct < 0 || $negPct > 100
    || $autoResolve === null) {
    jsonResponse(['success' => false, 'message' => 'One or more settings are invalid.'], 422);
}

$settings = [
    'admin_facility_name' => $facility,
    'admin_satisfaction_benchmark' => (string) $benchmark,
    'admin_pending_threshold' => (string) $threshold,
    'admin_negative_sentiment_alert_percent' => (string) $negPct,
    'admin_auto_resolve_positive_feedback' => $autoResolve ? '1' : '0',
];

try {
    $pdo = getPdo();
    $pdo->beginTransaction();

    $existingStmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :setting_key');
    $upsertStmt = $pdo->prepare(
        'INSERT INTO system_settings (setting_key, setting_value, updated_by)
         VALUES (:setting_key, :setting_value, :updated_by)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()'
    );
    $changedLabels = [];
    $labels = [
        'admin_facility_name' => 'Facility Name',
        'admin_satisfaction_benchmark' => 'Satisfaction Benchmark',
        'admin_pending_threshold' => 'Pending Feedback Alert Threshold',
        'admin_negative_sentiment_alert_percent' => 'Negative Sentiment Alert Threshold',
        'admin_auto_resolve_positive_feedback' => 'Auto-Resolve Positive Feedback',
    ];

    foreach ($settings as $key => $value) {
        $existingStmt->execute([':setting_key' => $key]);
        $current = $existingStmt->fetchColumn();
        if ($current !== false && (string) $current === $value) {
            continue;
        }
        $upsertStmt->execute([
            ':setting_key' => $key,
            ':setting_value' => $value,
            ':updated_by' => 'Super Admin',
        ]);
        $changedLabels[] = $labels[$key];
    }

    if ($changedLabels !== []) {
        $auditStmt = $pdo->prepare(
            'INSERT INTO audit_trail (feedback_id, user_id, user_name, action, notes, created_at)
             VALUES (0, :user_id, :user_name, :action, :notes, NOW())'
        );
        $auditStmt->execute([
            ':user_id' => (int) $_SESSION['admin_id'],
            ':user_name' => 'Super Admin',
            ':action' => 'settings_updated',
            ':notes' => 'Updated settings: ' . implode(', ', $changedLabels),
        ]);
    }

    $pdo->commit();
    jsonResponse([
        'success' => true,
        'message' => $changedLabels === [] ? 'No settings changed.' : 'Settings saved.',
        'data' => ['changed' => $changedLabels, 'settings' => readSettings($pdo)],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[settings] Unable to save settings.');
    jsonResponse(['success' => false, 'message' => 'Unable to save settings.'], 500);
}
