<?php

declare(strict_types=1);

final class EncryptionConfigurationException extends RuntimeException
{
}

final class EncryptionConfig
{
    private const ACTIVE_KEY_ID_ENV = 'SFMS_AES_ACTIVE_KEY_ID';
    private const KEY_ENV_PREFIX = 'SFMS_AES_KEY_';
    private const KEY_LENGTH_BYTES = 32;

    /** @var callable(string): (string|false) */
    private $environmentReader;

    public function __construct(?callable $environmentReader = null)
    {
        $this->environmentReader = $environmentReader ?? static function (string $name): string|false {
            $value = getenv($name);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
            return false;
        };
    }


    public function activeKeyId(): string
    {
        $keyId = $this->readRequired(self::ACTIVE_KEY_ID_ENV);
        $this->assertValidKeyId($keyId);
        return $keyId;
    }

    public function keyForId(string $keyId): string
    {
        $this->assertValidKeyId($keyId);
        $encodedKey = $this->readRequired($this->keyEnvironmentName($keyId));
        $key = base64_decode($encodedKey, true);

        if ($key === false || base64_encode($key) !== $encodedKey) {
            throw new EncryptionConfigurationException('The configured encryption key is not canonical Base64.');
        }

        if (strlen($key) !== self::KEY_LENGTH_BYTES) {
            throw new EncryptionConfigurationException('The configured encryption key must decode to exactly 32 bytes.');
        }

        return $key;
    }

    private function keyEnvironmentName(string $keyId): string
    {
        $suffix = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '_', $keyId));
        return self::KEY_ENV_PREFIX . $suffix;
    }

    private function readRequired(string $name): string
    {
        $value = ($this->environmentReader)($name);
        if (!is_string($value) || trim($value) === '') {
            throw new EncryptionConfigurationException('Required encryption configuration is unavailable.');
        }

        return trim($value);
    }

    private function assertValidKeyId(string $keyId): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $keyId) !== 1) {
            throw new EncryptionConfigurationException('The encryption key ID is invalid.');
        }
    }
}
