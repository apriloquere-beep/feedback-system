<?php
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/security/AdminSecurity.php';
sfmsStartSecureSession();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
sfmsRequireCsrf();

$_SESSION = [];
unset($_SESSION['admin_authenticated'], $_SESSION['admin_username'], $_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role']);
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();
header('Location: /Feedback/admin/login.php');
exit;
