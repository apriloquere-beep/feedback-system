<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/security/FeedbackEncryption.php';
require_once __DIR__ . '/../api/feedback_copy_data.php';

$savedActiveId = getenv('SFMS_AES_ACTIVE_KEY_ID');
$savedKey = getenv('SFMS_AES_KEY_EMAIL_COPY_TEST');

try {
    putenv('SFMS_AES_ACTIVE_KEY_ID=email-copy-test');
    putenv('SFMS_AES_KEY_EMAIL_COPY_TEST=' . base64_encode(random_bytes(32)));

    $referenceId = 'PATFB-EMAIL-COPY-TEST';
    $encryption = new FeedbackEncryption();
    $values = [
        'respondent_type' => 'Pasyente/Kliyente',
        'age' => '34',
        'sex' => 'Babaye',
        'gender_preference' => 'Babaye',
        'gender_preference_other' => null,
        'religion' => 'Kristiyano',
        'religion_other' => null,
        'education_level' => 'Kolehiyo',
        'visit_frequency' => '1-3x',
        'service_other' => null,
        'service_subtype_other' => 'Other test service',
        'suggestions' => 'Please improve the waiting area.',
        'appreciation' => 'The nurse was helpful.',
    ];

    $row = [];
    foreach ($values as $field => $value) {
        $row[$field] = $value === null ? null : 'legacy value must not win';
        $row[$field . '_enc'] = $encryption->encryptNullable(
            $value,
            new EncryptionContext('feedbacks', $referenceId, $field)
        );
    }

    $decrypted = decryptFeedbackCopyRow($row, $referenceId);
    $assertions = 0;
    foreach ($values as $field => $expected) {
        if (($decrypted[$field] ?? null) !== $expected) {
            throw new RuntimeException("Email-copy field {$field} did not decrypt correctly.");
        }
        if (array_key_exists($field . '_enc', $decrypted)) {
            throw new RuntimeException("Email-copy encrypted alias {$field}_enc was not removed.");
        }
        $assertions += 2;
    }

    $failedClosed = false;
    $tampered = $row;
    $tampered['suggestions_enc'] = '{"v":1,"invalid":true}';
    try {
        decryptFeedbackCopyRow($tampered, $referenceId);
    } catch (EncryptionException $e) {
        $failedClosed = true;
    }
    if (!$failedClosed) {
        throw new RuntimeException('Invalid encrypted email-copy data did not fail closed.');
    }
    $assertions++;

    $bodyFragment = htmlspecialchars((string) $decrypted['suggestions'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . htmlspecialchars((string) $decrypted['appreciation'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if (!str_contains($bodyFragment, 'waiting area') || !str_contains($bodyFragment, 'nurse was helpful')) {
        throw new RuntimeException('Decrypted written feedback was unavailable for email body generation.');
    }
    $assertions++;

    echo "PASS ({$assertions} assertions)\n";
} finally {
    putenv($savedActiveId === false ? 'SFMS_AES_ACTIVE_KEY_ID' : 'SFMS_AES_ACTIVE_KEY_ID=' . $savedActiveId);
    putenv($savedKey === false ? 'SFMS_AES_KEY_EMAIL_COPY_TEST' : 'SFMS_AES_KEY_EMAIL_COPY_TEST=' . $savedKey);
}
