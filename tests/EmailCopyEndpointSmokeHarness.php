<?php

declare(strict_types=1);

$case = $argv[1] ?? '';
session_set_save_handler(
    static fn (string $path, string $name): bool => true,
    static fn (): bool => true,
    static fn (string $id): string => '',
    static fn (string $id, string $data): bool => true,
    static fn (string $id): bool => true,
    static fn (int $maxLifetime): int|false => 0
);
session_id('email-copy-smoke-' . preg_replace('/[^a-z0-9-]/', '-', strtolower($case)));
session_start();
$_SERVER['REQUEST_METHOD'] = 'POST';

$_POST = match ($case) {
    'invalid-reference' => ['referenceId' => '!', 'email' => 'test@example.com', 'consent' => true],
    'invalid-recipient' => ['referenceId' => 'PATFB-SMOKE-1', 'email' => 'invalid', 'consent' => true],
    'missing-consent' => ['referenceId' => 'PATFB-SMOKE-1', 'email' => 'test@example.com', 'consent' => false],
    'expired-session' => ['referenceId' => 'PATFB-SMOKE-1', 'email' => 'test@example.com', 'consent' => true],
    'missing-record' => ['referenceId' => 'PATFB-SMOKE-1', 'email' => 'test@example.com', 'consent' => true],
    default => throw new InvalidArgumentException('Unknown smoke-test case.'),
};

if ($case === 'missing-record') {
    $_SESSION['feedback_copy_reference_id'] = 'PATFB-SMOKE-1';
    putenv('MAIL_HOST=smtp.gmail.com');
    putenv('MAIL_PORT=587');
    putenv('MAIL_ENCRYPTION=tls');
    putenv('MAIL_USERNAME=smoke@example.com');
    putenv('MAIL_PASSWORD=not-used');
    putenv('MAIL_FROM_ADDRESS=smoke@example.com');
}

require __DIR__ . '/../api/send_feedback_copy.php';
