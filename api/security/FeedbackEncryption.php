<?php

declare(strict_types=1);

require_once __DIR__ . '/EncryptionConfig.php';

final class EncryptionException extends RuntimeException
{
}

final class EncryptionContext
{
    public function __construct(
        public readonly string $recordType,
        public readonly string $referenceId,
        public readonly string $fieldName
    ) {
        self::assertPart('record type', $recordType, 64);
        self::assertPart('reference ID', $referenceId, 128);
        self::assertPart('field name', $fieldName, 64);
    }

    public function additionalAuthenticatedData(int $version): string
    {
        try {
            return json_encode([
                'v' => $version,
                'recordType' => $this->recordType,
                'referenceId' => $this->referenceId,
                'fieldName' => $this->fieldName,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new EncryptionException('Unable to construct encryption context.', 0, $e);
        }
    }

    private static function assertPart(string $label, string $value, int $maximumLength): void
    {
        if ($value === '' || strlen($value) > $maximumLength || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException("Encryption context {$label} is invalid.");
        }
    }
}

final class FeedbackEncryption
{
    private const VERSION = 1;
    private const ALGORITHM_LABEL = 'A256GCM';
    private const OPENSSL_CIPHER = 'aes-256-gcm';
    private const IV_LENGTH_BYTES = 12;
    private const TAG_LENGTH_BYTES = 16;
    private const MAX_ENVELOPE_BYTES = 16_777_216;
    private const ENVELOPE_KEYS = ['alg', 'ct', 'iv', 'kid', 'tag', 'v'];

    public function __construct(private readonly EncryptionConfig $config = new EncryptionConfig())
    {
        if (!extension_loaded('openssl') || !in_array(self::OPENSSL_CIPHER, openssl_get_cipher_methods(), true)) {
            throw new EncryptionConfigurationException('AES-256-GCM is unavailable in this PHP runtime.');
        }
    }

    public function encryptNullable(?string $plaintext, EncryptionContext $context): ?string
    {
        if ($plaintext === null) {
            return null;
        }

        $keyId = $this->config->activeKeyId();
        $key = $this->config->keyForId($keyId);
        $iv = random_bytes(self::IV_LENGTH_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::OPENSSL_CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $context->additionalAuthenticatedData(self::VERSION),
            self::TAG_LENGTH_BYTES
        );

        if ($ciphertext === false || strlen($tag) !== self::TAG_LENGTH_BYTES) {
            throw new EncryptionException('Encryption failed.');
        }

        try {
            return json_encode([
                'v' => self::VERSION,
                'kid' => $keyId,
                'alg' => self::ALGORITHM_LABEL,
                'iv' => base64_encode($iv),
                'tag' => base64_encode($tag),
                'ct' => base64_encode($ciphertext),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new EncryptionException('Unable to encode the encrypted envelope.', 0, $e);
        }
    }

    public function decryptNullable(?string $envelope, EncryptionContext $context): ?string
    {
        if ($envelope === null) {
            return null;
        }

        $payload = $this->decodeEnvelope($envelope);
        $key = $this->config->keyForId($payload['kid']);
        $plaintext = openssl_decrypt(
            $payload['ct'],
            self::OPENSSL_CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $payload['iv'],
            $payload['tag'],
            $context->additionalAuthenticatedData(self::VERSION)
        );

        if ($plaintext === false) {
            throw new EncryptionException('Decryption authentication failed.');
        }

        return $plaintext;
    }

    /** @return array{v:int,kid:string,alg:string,iv:string,tag:string,ct:string} */
    private function decodeEnvelope(string $envelope): array
    {
        if ($envelope === '' || strlen($envelope) > self::MAX_ENVELOPE_BYTES) {
            throw new EncryptionException('The encrypted envelope size is invalid.');
        }

        try {
            $payload = json_decode($envelope, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new EncryptionException('The encrypted envelope is not valid JSON.', 0, $e);
        }

        if (!is_array($payload)) {
            throw new EncryptionException('The encrypted envelope structure is invalid.');
        }

        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== self::ENVELOPE_KEYS) {
            throw new EncryptionException('The encrypted envelope fields are invalid.');
        }

        if ($payload['v'] !== self::VERSION || $payload['alg'] !== self::ALGORITHM_LABEL || !is_string($payload['kid'])) {
            throw new EncryptionException('The encrypted envelope version or algorithm is unsupported.');
        }

        foreach (['iv', 'tag', 'ct'] as $field) {
            if (!is_string($payload[$field])) {
                throw new EncryptionException('The encrypted envelope contains an invalid binary field.');
            }
            $decoded = base64_decode($payload[$field], true);
            if ($decoded === false || base64_encode($decoded) !== $payload[$field]) {
                throw new EncryptionException('The encrypted envelope contains invalid Base64.');
            }
            $payload[$field] = $decoded;
        }

        if (strlen($payload['iv']) !== self::IV_LENGTH_BYTES || strlen($payload['tag']) !== self::TAG_LENGTH_BYTES) {
            throw new EncryptionException('The encrypted envelope IV or authentication tag length is invalid.');
        }

        return $payload;
    }
}
