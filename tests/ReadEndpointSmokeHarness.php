<?php

declare(strict_types=1);

$endpoint = $argv[1] ?? '';
$allowed = [
    'feedback_list' => __DIR__ . '/../api/feedback_list.php',
    'feedback_json' => __DIR__ . '/../api/feedback_json.php',
    'feedback_detail' => __DIR__ . '/../api/feedback_detail.php',
    'admin_modules' => __DIR__ . '/../api/admin_modules.php',
    'system_status' => __DIR__ . '/../api/system_status.php',
];

if (!isset($allowed[$endpoint])) {
    fwrite(STDERR, "Unknown read endpoint.\n");
    exit(2);
}

session_save_path(sys_get_temp_dir());
session_id('sfms-phase4-' . str_replace('_', '-', $endpoint));
session_start();
$_SESSION['admin_id'] = 1;
session_write_close();

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/Feedback/api/' . $endpoint . '.php';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_GET = $endpoint === 'feedback_detail' ? ['referenceId' => 'PHASE4-NOT-FOUND'] : [];

require $allowed[$endpoint];
