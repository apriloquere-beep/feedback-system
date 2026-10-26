<?php

declare(strict_types=1);

function sfmsStartSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/Feedback',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function sfmsCsrfToken(): string
{
    sfmsStartSecureSession();
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) !== 64) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function sfmsRequireCsrf(): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return;
    $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
    $expected = sfmsCsrfToken();
    if ($provided === '' || !hash_equals($expected, $provided)) {
        jsonResponse(['success' => false, 'message' => 'Invalid security token. Refresh the page and try again.'], 403);
    }
}

function sfmsLogFailure(string $area): void
{
    error_log('[sfms] ' . preg_replace('/[^a-z0-9_.-]/i', '_', $area) . ' failed.');
}
