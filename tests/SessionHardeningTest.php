<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/security/AdminSecurity.php';
session_set_save_handler(
    static fn (string $path, string $name): bool => true,
    static fn (): bool => true,
    static fn (string $id): string => '',
    static fn (string $id, string $data): bool => true,
    static fn (string $id): bool => true,
    static fn (int $maxLifetime): int|false => 0
);
sfmsStartSecureSession();
$params = session_get_cookie_params();
$checks = [
    ini_get('session.use_strict_mode') === '1',
    ini_get('session.use_only_cookies') === '1',
    ($params['httponly'] ?? false) === true,
    ($params['samesite'] ?? '') === 'Strict',
    strlen(sfmsCsrfToken()) === 64,
];
foreach ($checks as $check) if (!$check) throw new RuntimeException('Session hardening assertion failed.');
echo 'PASS (' . count($checks) . " assertions)\n";
