<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/security/FeedbackDecryption.php';

$variables = [
    'SFMS_AES_ACTIVE_KEY_ID' => getenv('SFMS_AES_ACTIVE_KEY_ID'),
    'SFMS_AES_KEY_READ_TEST_V1' => getenv('SFMS_AES_KEY_READ_TEST_V1'),
];
$assertions = 0;

try {
    putenv('SFMS_AES_ACTIVE_KEY_ID=read-test-v1');
    putenv('SFMS_AES_KEY_READ_TEST_V1=' . base64_encode(random_bytes(32)));

    $encryption = new FeedbackEncryption();
    $decryption = new FeedbackDecryption($encryption);
    $reference = 'READ-TEST-1';
    $context = new EncryptionContext('feedback_entries', $reference, 'feedback');
    $envelope = $encryption->encryptNullable('Decrypted display text', $context);

    $row = $decryption->map([
        'referenceId' => $reference,
        'feedback' => 'must not win',
        'feedbackEnc' => $envelope,
    ], 'feedback_entries', $reference, [
        'feedback' => ['feedbackEnc', 'feedback'],
    ]);
    if (($row['feedback'] ?? null) !== 'Decrypted display text' || array_key_exists('feedbackEnc', $row)) {
        throw new RuntimeException('Encrypted display mapping failed.');
    }
    $assertions++;

    if ($decryption->value(null, 'Legacy display text', 'feedback_entries', $reference, 'feedback') !== 'Legacy display text') {
        throw new RuntimeException('Legacy fallback failed.');
    }
    $assertions++;

    if ($decryption->value(null, null, 'feedback_entries', $reference, 'feedback') !== null) {
        throw new RuntimeException('Nullable display mapping failed.');
    }
    $assertions++;

    $failedClosed = false;
    try {
        $decryption->value($envelope, 'must not leak', 'feedback_entries', 'WRONG-REFERENCE', 'feedback');
    } catch (EncryptionException $e) {
        $failedClosed = true;
    }
    if (!$failedClosed) {
        throw new RuntimeException('Authentication failure did not fail closed.');
    }
    $assertions++;

    echo "PASS ({$assertions} assertions)\n";
} finally {
    foreach ($variables as $name => $value) {
        putenv($value === false ? $name : $name . '=' . $value);
    }
}
