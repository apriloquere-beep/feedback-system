<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security/AdminSecurity.php';

sfmsStartSecureSession();

function authJson(array $payload, int $statusCode = 200): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Check session status
    if (isset($_SESSION['admin_id'])) {
        authJson([
            'success' => true,
            'loggedIn' => true,
            'user' => [
                'id' => $_SESSION['admin_id'],
                'name' => $_SESSION['admin_name'] ?? 'Admin',
                'role' => $_SESSION['admin_role'] ?? 'admin',
            ]
        ]);
    } else {
        authJson(['success' => true, 'loggedIn' => false]);
    }
}

if ($method === 'POST') {
    $body = readJsonBody();
    
    $action = trim((string) ($body['action'] ?? 'login'));
    
    if ($action === 'logout') {
        sfmsRequireCsrf();
        session_unset();
        session_destroy();
        authJson(['success' => true, 'message' => 'Logged out successfully']);
    }

    if ($action === 'login') {
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($username === '' || $password === '') {
            authJson(['success' => false, 'message' => 'Username/Email and password are required'], 422);
        }

        try {
            $pdo = getPdo();
            
            // Check by username, email, or full_name for flexibility.
            // Use distinct placeholder names to avoid HY093 with native prepared statements.
            $stmt = $pdo->prepare('SELECT id, full_name, email, password_hash, role, status FROM admin_users WHERE username = :username_login OR email = :username_email OR full_name = :username_name LIMIT 1');
            $stmt->execute([
                ':username_login' => $username,
                ':username_email' => $username,
                ':username_name' => $username,
            ]);
            $user = $stmt->fetch();

            if (!$user) {
                authJson(['success' => false, 'message' => 'Invalid username or password'], 401);
            }

            if ($user['status'] !== 'active') {
                authJson(['success' => false, 'message' => 'Your account is currently deactivated'], 403);
            }

            if (!in_array($user['role'], ['admin', 'super_admin'], true)) {
                authJson(['success' => false, 'message' => 'Invalid username or password'], 401);
            }

            // Verify password
            if (!password_verify($password, $user['password_hash'])) {
                authJson(['success' => false, 'message' => 'Invalid username or password'], 401);
            }

            $pdo->beginTransaction();

            // Update last login and record this successful authentication as
            // one atomic audit/notification event.
            $update = $pdo->prepare('UPDATE admin_users SET last_login = NOW() WHERE id = :id');
            $update->execute([':id' => $user['id']]);

            $audit = $pdo->prepare(
                'INSERT INTO audit_trail (feedback_id, user_id, user_name, action, notes, created_at)
                 VALUES (0, :user_id, :user_name, :action, :notes, NOW())'
            );
            $audit->execute([
                ':user_id' => (int) $user['id'],
                ':user_name' => $user['full_name'],
                ':action' => 'admin_login',
                ':notes' => 'Successful admin login',
            ]);

            $notification = $pdo->prepare(
                'INSERT INTO notifications
                    (type, title, message, reference_id, is_read, created_by, created_at)
                 VALUES
                    (:type, :title, :message, NULL, 0, :created_by, NOW())'
            );
            $notification->execute([
                ':type' => 'info',
                ':title' => 'Admin login',
                ':message' => sprintf('%s successfully logged in.', $user['full_name']),
                ':created_by' => $user['full_name'],
            ]);

            $pdo->commit();

            // Set session
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $user['id'];
            $_SESSION['admin_name'] = $user['full_name'];
            $_SESSION['admin_email'] = $user['email'];
            $_SESSION['admin_role'] = $user['role'];
            sfmsCsrfToken();

            authJson([
                'success' => true,
                'message' => 'Login successful',
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['full_name'],
                    'role' => $user['role'],
                ]
            ]);

        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            sfmsLogFailure('auth.login');
            authJson(['success' => false, 'message' => 'Unable to sign in right now.'], 500);
        }
    }
}

authJson(['success' => false, 'message' => 'Method not allowed'], 405);
