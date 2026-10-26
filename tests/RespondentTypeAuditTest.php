<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/security/RespondentType.php';
require_once __DIR__ . '/../api/security/FeedbackEncryption.php';
require_once __DIR__ . '/../api/security/FeedbackDecryption.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$choices = [
    'patient' => RespondentType::PATIENT,
    'companion' => RespondentType::COMPANION,
    'business' => RespondentType::BUSINESS,
    'other' => RespondentType::OTHER,
];
$testKey = base64_encode(str_repeat('x', 32));
$config = new EncryptionConfig(static fn (string $name): string|false => match ($name) {
    'SFMS_AES_ACTIVE_KEY_ID' => 'respondent-test',
    'SFMS_AES_KEY_RESPONDENT_TEST' => $testKey,
    default => false,
});
$encryption = new FeedbackEncryption($config);
$decryption = new FeedbackDecryption($encryption);
foreach ($choices as $category => $label) {
    check(RespondentType::selectedLabel($label) === $label, "$category choice changed");
    check(RespondentType::category($label) === $category, "$category filter changed");
    $referenceId = 'TEST-' . strtoupper($category);
    $ciphertext = $encryption->encryptNullable($label, new EncryptionContext('feedbacks', $referenceId, 'respondent_type'));
    $row = $decryption->map(
        ['respondent_type_enc' => $ciphertext, 'respondent_type' => null],
        'feedbacks',
        $referenceId,
        ['respondentType' => ['respondent_type_enc', 'respondent_type', 'respondent_type']]
    );
    check($row['respondentType'] === $label, "$category database round trip changed");
}

check(RespondentType::label('Bisita') === RespondentType::BUSINESS, 'Legacy Business record displays as Visitor');
check(RespondentType::category('Bisita') === 'business', 'Legacy Business record is in wrong sidebar category');
check(RespondentType::label('a custom type') === RespondentType::OTHER, 'Legacy Other record category changed');
check(RespondentType::legacyOtherDetail('a custom type') === 'a custom type', 'Legacy Other detail lost');

echo "Respondent type audit tests passed.\n";
