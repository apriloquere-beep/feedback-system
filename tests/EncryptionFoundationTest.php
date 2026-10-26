<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/security/FeedbackEncryption.php';

final class EncryptionFoundationTest
{
    private int $assertions = 0;
    private string|false $previousKeyId;
    private string|false $previousKey;

    public function run(): void
    {
        $this->previousKeyId = getenv('SFMS_AES_ACTIVE_KEY_ID');
        $this->previousKey = getenv('SFMS_AES_KEY_TEST_V1');

        try {
            putenv('SFMS_AES_ACTIVE_KEY_ID=test-v1');
            putenv('SFMS_AES_KEY_TEST_V1=' . base64_encode(random_bytes(32)));

            $service = new FeedbackEncryption();
            $context = new EncryptionContext('feedbacks', 'TEST-REFERENCE-1', 'suggestions');

            $plaintext = "Maayo nga serbisyo — thank you.\nSecond line.";
            $first = $service->encryptNullable($plaintext, $context);
            $second = $service->encryptNullable($plaintext, $context);

            $this->assert(is_string($first), 'Encryption returns an envelope.');
            $this->assert($first !== $second, 'Repeated encryption uses a unique nonce.');
            $this->assert($service->decryptNullable($first, $context) === $plaintext, 'Unicode plaintext round-trips.');
            $this->assert($service->decryptNullable($service->encryptNullable('', $context), $context) === '', 'Empty string round-trips.');
            $this->assert($service->encryptNullable(null, $context) === null, 'Null encryption remains null.');
            $this->assert($service->decryptNullable(null, $context) === null, 'Null decryption remains null.');

            $decoded = json_decode($first, true, 8, JSON_THROW_ON_ERROR);
            $this->assert($decoded['v'] === 1 && $decoded['kid'] === 'test-v1' && $decoded['alg'] === 'A256GCM', 'Envelope metadata is present.');
            $this->assert(strlen((string) base64_decode($decoded['iv'], true)) === 12, 'IV is 12 bytes.');
            $this->assert(strlen((string) base64_decode($decoded['tag'], true)) === 16, 'Authentication tag is 16 bytes.');

            $this->expectFailure(
                fn () => $service->decryptNullable($first, new EncryptionContext('feedbacks', 'TEST-REFERENCE-2', 'suggestions')),
                'Wrong AAD context is rejected.'
            );

            $tampered = $decoded;
            $ciphertext = base64_decode($tampered['ct'], true);
            $tampered['ct'] = base64_encode(chr(ord($ciphertext[0]) ^ 1) . substr($ciphertext, 1));
            $this->expectFailure(
                fn () => $service->decryptNullable(json_encode($tampered, JSON_THROW_ON_ERROR), $context),
                'Tampered ciphertext is rejected.'
            );

            $tampered = $decoded;
            $tag = base64_decode($tampered['tag'], true);
            $tampered['tag'] = base64_encode(chr(ord($tag[0]) ^ 1) . substr($tag, 1));
            $this->expectFailure(
                fn () => $service->decryptNullable(json_encode($tampered, JSON_THROW_ON_ERROR), $context),
                'Tampered authentication tag is rejected.'
            );

            $this->expectFailure(fn () => $service->decryptNullable('{"v":1}', $context), 'Malformed envelope is rejected.');

            putenv('SFMS_AES_KEY_TEST_V1=invalid');
            $this->expectFailure(fn () => (new FeedbackEncryption())->encryptNullable('test', $context), 'Invalid key is rejected.');

            $missingConfig = new EncryptionConfig(static fn (string $name): string|false => false);
            $this->expectFailure(
                fn () => (new FeedbackEncryption($missingConfig))->encryptNullable('test', $context),
                'Missing configuration is rejected.'
            );

            echo "PASS ({$this->assertions} assertions)\n";
        } finally {
            $this->restoreEnvironment('SFMS_AES_ACTIVE_KEY_ID', $this->previousKeyId);
            $this->restoreEnvironment('SFMS_AES_KEY_TEST_V1', $this->previousKey);
        }
    }

    private function assert(bool $condition, string $message): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    private function expectFailure(callable $operation, string $message): void
    {
        $failed = false;
        try {
            $operation();
        } catch (EncryptionException|EncryptionConfigurationException $e) {
            $failed = true;
        }
        $this->assert($failed, $message);
    }

    private function restoreEnvironment(string $name, string|false $value): void
    {
        putenv($value === false ? $name : $name . '=' . $value);
    }
}

(new EncryptionFoundationTest())->run();
