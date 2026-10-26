<?php

declare(strict_types=1);

/**
 * SMTP settings are deliberately read only from the host environment. This
 * keeps Gmail credentials out of source control and out of API responses.
 */
function feedbackCopyMailConfig(): array
{
    $read = static function (string $name, string $default = ''): string {
        $value = getenv($name);
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    };

    $host = $read('MAIL_HOST', 'smtp.gmail.com');
    $port = (int) $read('MAIL_PORT', '587');
    $encryption = strtolower($read('MAIL_ENCRYPTION', 'tls'));
    $username = $read('MAIL_USERNAME');
    $password = $read('MAIL_PASSWORD');
    $fromAddress = $read('MAIL_FROM_ADDRESS', $username);
    $fromName = $read('MAIL_FROM_NAME', 'SFMS');

    // This receipt feature intentionally supports only the requested Gmail
    // STARTTLS setup. The authenticated Gmail account is also the sender,
    // so browser input can never influence the From address.
    $configured = strtolower($host) === 'smtp.gmail.com'
        && $port === 587
        && $encryption === 'tls'
        && filter_var($username, FILTER_VALIDATE_EMAIL)
        && $password !== ''
        && filter_var($fromAddress, FILTER_VALIDATE_EMAIL)
        && strcasecmp($fromAddress, $username) === 0;

    return [
        'configured' => $configured,
        'host' => $host,
        'port' => $port,
        'encryption' => $encryption,
        'username' => $username,
        'password' => $password,
        'fromAddress' => $fromAddress,
        'fromName' => $fromName,
    ];
}
