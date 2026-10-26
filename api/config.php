<?php

declare(strict_types=1);

define('DEBUG_MODE', false); // Set to true for development

// QR entry configuration. Replace the URL with the official hosted HTTPS URL
// and disable local URLs when production deployment is ready.
define('FEEDBACK_QR_ENTRY_URL', 'https://healthfeedbackmonitoring.dcsbisu.com/Feedback/qr-entry.php');
define('FEEDBACK_QR_ALLOW_LOCAL_URL', false);

// Store and generate application timestamps on one clearly defined clock.
// Asia/Manila has a fixed UTC+08:00 offset and does not observe DST.
date_default_timezone_set('Asia/Manila');

// Ensure errors are not printed raw by PHP
ini_set('display_errors', '0');
error_reporting(E_ALL);

function handleGlobalError($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}

function handleGlobalException(Throwable $e) {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    $logFile = $logDir . '/error.log';
    
    $timestamp = date('Y-m-d H:i:s');
    $errorMessage = "[{$timestamp}] " . get_class($e) . " in {$e->getFile()}:{$e->getLine()}\n";
    @file_put_contents($logFile, $errorMessage, FILE_APPEND);

    $isApi = false;
    if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
        $isApi = true;
    }
    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        $isApi = true;
    }

    if (!headers_sent()) {
        http_response_code(500);
    }

    if ($isApi) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        $response = ['success' => false, 'message' => 'Something went wrong. An unexpected error occurred.'];
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // HTML response
    if (!DEBUG_MODE) {
        $errorPage = __DIR__ . '/../500.php';
        if (file_exists($errorPage)) {
            include $errorPage;
        } else {
            echo "<h1>Something went wrong</h1><p>An unexpected error occurred. Please try again later.</p>";
        }
    } else echo '<h1>Something went wrong</h1><p>An unexpected error occurred.</p>';
    exit;
}

set_error_handler('handleGlobalError');
set_exception_handler('handleGlobalException');

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        // We create an exception out of it to handle it the same way
        $e = new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
        handleGlobalException($e);
    }
});
