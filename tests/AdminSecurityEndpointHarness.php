<?php

declare(strict_types=1);

$mode = $argv[1] ?? 'missing';
session_set_save_handler(
    static fn (string $path, string $name): bool => true,
    static fn (): bool => true,
    static fn (string $id): string => '',
    static fn (string $id, string $data): bool => true,
    static fn (string $id): bool => true,
    static fn (int $maxLifetime): int|false => 0
);
session_id('security-endpoint-harness');
session_start();
$_SESSION['admin_id'] = 1;
$_SESSION['csrf_token'] = str_repeat('a', 64);
$_SERVER['REQUEST_METHOD'] = 'POST';
if ($mode === 'valid') $_SERVER['HTTP_X_CSRF_TOKEN'] = str_repeat('a', 64);
require __DIR__ . '/../api/feedback_delete.php';
