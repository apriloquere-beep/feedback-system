<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/feedback_update_encryption.php';
require_once __DIR__ . '/../api/security/FeedbackDecryption.php';

$oldId = getenv('SFMS_AES_ACTIVE_KEY_ID');
$oldKey = getenv('SFMS_AES_KEY_REMEDIATION_TEST');
try {
    putenv('SFMS_AES_ACTIVE_KEY_ID=remediation-test');
    putenv('SFMS_AES_KEY_REMEDIATION_TEST=' . base64_encode(random_bytes(32)));
    $reference = 'SECURITY-REMEDIATION-TEST';
    $values = ['subject' => 'Private subject', 'feedback' => 'Private feedback', 'suggestions' => null, 'appreciation' => 'Thank you'];
    $encrypted = encryptFeedbackEntryUpdateFields($reference, $values);
    $decryption = new FeedbackDecryption();
    $assertions = 0;
    foreach ($values as $field => $expected) {
        $actual = $decryption->value($encrypted[$field . '_enc'], null, 'feedback_entries', $reference, $field);
        if ($actual !== $expected) throw new RuntimeException("Encrypted update mismatch for {$field}.");
        if ($encrypted[$field . '_enc'] !== null && str_contains($encrypted[$field . '_enc'], (string) $expected)) throw new RuntimeException("Plaintext leaked in {$field} envelope.");
        $assertions += 2;
    }
    $source = file_get_contents(__DIR__ . '/../api/feedback_json.php');
    if (!is_string($source) || !str_contains($source, 'SET subject         = NULL') || !str_contains($source, 'feedback_enc    = :feedback_enc')) {
        throw new RuntimeException('Sensitive update SQL does not clear plaintext and write encrypted columns.');
    }
    $assertions++;
    echo "PASS ({$assertions} assertions)\n";
} finally {
    putenv($oldId === false ? 'SFMS_AES_ACTIVE_KEY_ID' : 'SFMS_AES_ACTIVE_KEY_ID=' . $oldId);
    putenv($oldKey === false ? 'SFMS_AES_KEY_REMEDIATION_TEST' : 'SFMS_AES_KEY_REMEDIATION_TEST=' . $oldKey);
}
