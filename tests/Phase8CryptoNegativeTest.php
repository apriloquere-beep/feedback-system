<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/security/FeedbackEncryption.php';

$assertions = 0;
$fail = static function (callable $operation, string $label) use (&$assertions): void {
    try {
        $operation();
    } catch (EncryptionException|EncryptionConfigurationException $e) {
        $assertions++;
        return;
    }
    throw new RuntimeException($label . ' did not fail closed.');
};

$oldId = getenv('SFMS_AES_ACTIVE_KEY_ID');
$oldKey = getenv('SFMS_AES_KEY_AUDIT_V1');
$key = random_bytes(32);

try {
    putenv('SFMS_AES_ACTIVE_KEY_ID=audit-v1');
    putenv('SFMS_AES_KEY_AUDIT_V1=' . base64_encode($key));
    $service = new FeedbackEncryption();
    $context = new EncryptionContext('feedbacks', 'AUDIT-REF', 'feedback');
    $envelope = (string) $service->encryptNullable('controlled in-memory value', $context);
    $payload = json_decode($envelope, true, 8, JSON_THROW_ON_ERROR);

    foreach (['ct', 'tag', 'iv'] as $field) {
        $tampered = $payload;
        $bytes = base64_decode($tampered[$field], true);
        $tampered[$field] = base64_encode(chr(ord($bytes[0]) ^ 1) . substr($bytes, 1));
        $fail(fn () => $service->decryptNullable(json_encode($tampered, JSON_THROW_ON_ERROR), $context), $field . ' tampering');
    }

    $fail(fn () => $service->decryptNullable($envelope, new EncryptionContext('feedbacks', 'WRONG-REF', 'feedback')), 'wrong AAD');

    putenv('SFMS_AES_KEY_AUDIT_V1=' . base64_encode(random_bytes(32)));
    $fail(fn () => (new FeedbackEncryption())->decryptNullable($envelope, $context), 'wrong key');
    putenv('SFMS_AES_KEY_AUDIT_V1=' . base64_encode($key));

    $fail(fn () => $service->decryptNullable('{broken', $context), 'malformed envelope');
    $invalidBase64 = $payload;
    $invalidBase64['ct'] = '*not-base64*';
    $fail(fn () => $service->decryptNullable(json_encode($invalidBase64, JSON_THROW_ON_ERROR), $context), 'invalid Base64');

    foreach ([['v', 2], ['kid', 'unknown-key']] as [$field, $value]) {
        $unsupported = $payload;
        $unsupported[$field] = $value;
        $fail(fn () => $service->decryptNullable(json_encode($unsupported, JSON_THROW_ON_ERROR), $context), 'unsupported ' . $field);
    }

    putenv('SFMS_AES_ACTIVE_KEY_ID');
    $fail(fn () => (new FeedbackEncryption())->encryptNullable('value', $context), 'missing key configuration');
    putenv('SFMS_AES_ACTIVE_KEY_ID=audit-v1');
    putenv('SFMS_AES_KEY_AUDIT_V1=invalid');
    $fail(fn () => (new FeedbackEncryption())->encryptNullable('value', $context), 'invalid key length');

    echo "PASS ({$assertions} negative assertions)\n";
} finally {
    putenv($oldId === false ? 'SFMS_AES_ACTIVE_KEY_ID' : 'SFMS_AES_ACTIVE_KEY_ID=' . $oldId);
    putenv($oldKey === false ? 'SFMS_AES_KEY_AUDIT_V1' : 'SFMS_AES_KEY_AUDIT_V1=' . $oldKey);
}
