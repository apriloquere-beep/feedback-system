<?php

declare(strict_types=1);

/**
 * One-time, CLI-only provisioner for an empty SFMS deployment database.
 *
 * Run this only after importing the clean database.sql into the intended
 * deployment database. It refuses to run once any administrator exists.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This provisioning tool can only run from the PHP CLI.\n");
}

require_once __DIR__ . '/../../api/db.php';

function provisionPrompt(string $label): string
{
    fwrite(STDOUT, $label);
    $value = fgets(STDIN);
    if ($value === false) {
        throw new RuntimeException('Input was not available.');
    }

    return trim($value);
}

function provisionHiddenPrompt(string $label): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        $command = 'powershell -NoProfile -Command "$secure = Read-Host \''
            . str_replace("'", "''", $label)
            . '\' -AsSecureString; $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure); '
            . 'try { [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }"';
        $value = shell_exec($command);
        if (!is_string($value)) {
            throw new RuntimeException('A secure password prompt is unavailable.');
        }

        return rtrim($value, "\r\n");
    }

    $sttyMode = shell_exec('stty -g 2>/dev/null');
    if (!is_string($sttyMode) || trim($sttyMode) === '') {
        throw new RuntimeException('A secure password prompt is unavailable.');
    }

    fwrite(STDOUT, $label);
    shell_exec('stty -echo');
    try {
        $value = fgets(STDIN);
    } finally {
        shell_exec('stty ' . escapeshellarg(trim($sttyMode)));
        fwrite(STDOUT, PHP_EOL);
    }

    if ($value === false) {
        throw new RuntimeException('Input was not available.');
    }

    return rtrim($value, "\r\n");
}

function provisionValidateUsername(string $username): void
{
    if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{2,49}\z/', $username) !== 1) {
        throw new InvalidArgumentException('Username must be 3-50 characters using letters, numbers, dots, underscores, or hyphens.');
    }
}

function provisionValidateDisplayName(string $displayName): void
{
    if ($displayName === '' || mb_strlen($displayName) > 120) {
        throw new InvalidArgumentException('Display name is required and must be at most 120 characters.');
    }
}

function provisionValidateEmail(string $email): void
{
    if (mb_strlen($email) > 160 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('A valid email address is required.');
    }
}

function provisionValidatePassword(string $password): void
{
    $classes = 0;
    $classes += preg_match('/[a-z]/', $password) === 1 ? 1 : 0;
    $classes += preg_match('/[A-Z]/', $password) === 1 ? 1 : 0;
    $classes += preg_match('/\d/', $password) === 1 ? 1 : 0;
    $classes += preg_match('/[^A-Za-z0-9]/', $password) === 1 ? 1 : 0;

    if (strlen($password) < 14 || $classes < 3) {
        throw new InvalidArgumentException('Password must be at least 14 characters and include at least three character types.');
    }
}

function provisionAuditTrailIsCompatible(PDO $pdo): bool
{
    $stmt = $pdo->query(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'audit_trail'
           AND COLUMN_NAME IN ('feedback_id', 'user_id', 'user_name', 'action', 'notes', 'created_at')"
    );

    return (int) $stmt->fetchColumn() === 6;
}

try {
    $username = provisionPrompt('Super Admin username: ');
    $displayName = provisionPrompt('Display name: ');
    $email = provisionPrompt('Email address: ');
    $password = provisionHiddenPrompt('Password: ');
    $passwordConfirmation = provisionHiddenPrompt('Confirm password: ');

    provisionValidateUsername($username);
    provisionValidateDisplayName($displayName);
    provisionValidateEmail($email);
    provisionValidatePassword($password);

    if (!hash_equals($password, $passwordConfirmation)) {
        throw new InvalidArgumentException('Password confirmation does not match.');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($passwordHash)) {
        throw new RuntimeException('Unable to securely hash the password.');
    }

    $pdo = getPdo();
    $pdo->beginTransaction();

    $existingAdmin = $pdo->query('SELECT id FROM admin_users LIMIT 1 FOR UPDATE')->fetchColumn();
    if ($existingAdmin !== false) {
        $pdo->rollBack();
        fwrite(STDERR, "Provisioning stopped: an administrator account already exists.\n");
        exit(1);
    }

    $insert = $pdo->prepare(
        'INSERT INTO admin_users (username, full_name, email, password_hash, role, status)
         VALUES (:username, :full_name, :email, :password_hash, "super_admin", "active")'
    );
    $insert->execute([
        ':username' => $username,
        ':full_name' => $displayName,
        ':email' => $email,
        ':password_hash' => $passwordHash,
    ]);
    $adminId = (int) $pdo->lastInsertId();

    if (provisionAuditTrailIsCompatible($pdo)) {
        $audit = $pdo->prepare(
            'INSERT INTO audit_trail (feedback_id, user_id, user_name, action, notes, created_at)
             VALUES (0, :user_id, :user_name, "initial_super_admin_provisioned", "Initial Super Admin provisioned", NOW())'
        );
        $audit->execute([
            ':user_id' => $adminId,
            ':user_name' => $displayName,
        ]);
    }

    $pdo->commit();
    fwrite(STDOUT, "Initial Super Admin provisioned successfully. This tool will refuse reuse while an administrator exists.\n");
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $message = $e instanceof InvalidArgumentException
        ? $e->getMessage()
        : 'Unable to provision the initial Super Admin.';
    fwrite(STDERR, "Provisioning failed: " . $message . PHP_EOL);
    exit(1);
}
