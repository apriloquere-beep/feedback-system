<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/security/FeedbackEncryption.php';
require_once __DIR__ . '/../api/security/FeedbackDecryption.php';
function normalizeStatus(string $status): string { return strtolower(trim($status)); }
require_once __DIR__ . '/../api/security/FeedbackAnalytics.php';

putenv('SFMS_AES_ACTIVE_KEY_ID=phase5-test');
putenv('SFMS_AES_KEY_PHASE5_TEST=' . base64_encode(random_bytes(32)));
$reference = 'PATFB-PHASE5-TEST';
$crypto = new FeedbackEncryption();
$decrypt = new FeedbackDecryption($crypto);
$row = $decrypt->map([
    'subject' => null,
    'subjectEnc' => $crypto->encryptNullable('Pharmacy waiting time', new EncryptionContext('feedback_entries', $reference, 'subject')),
    'feedback' => null,
    'feedbackEnc' => $crypto->encryptNullable('Staff were helpful', new EncryptionContext('feedback_entries', $reference, 'feedback')),
], 'feedback_entries', $reference, [
    'subject' => ['subjectEnc', 'subject'],
    'feedback' => ['feedbackEnc', 'feedback'],
]);
$entry = $row + [
    'referenceId' => $reference, 'serviceId' => 3, 'service' => 'Pharmacy',
    'respondentType' => 'Pasyente/Kliyente', 'status' => 'pending',
    'sentimentLabel' => 'Positive', 'priority' => 'Normal',
    'feedbackType' => 'compliment', 'averageRating' => 5.0,
];
$count = 0;
$assert = static function (bool $ok, string $message) use (&$count): void {
    $count++;
    if (!$ok) throw new RuntimeException($message);
};
$assert(count(FeedbackAnalytics::filter([$entry], ['search' => 'waiting'])) === 1, 'Encrypted subject search failed.');
$assert(count(FeedbackAnalytics::filter([$entry], ['search' => 'helpful'])) === 1, 'Encrypted feedback search failed.');
$assert(count(FeedbackAnalytics::filter([$entry], ['search' => 'missing'])) === 0, 'Non-match search failed.');
$assert(count(FeedbackAnalytics::filter([$entry], ['service' => '3', 'status' => 'pending'])) === 1, 'Operational filters failed.');
$assert(count(FeedbackAnalytics::filter([$entry], ['respondent' => 'patient'])) === 1, 'Respondent filter failed.');
$assert(count(FeedbackAnalytics::filter([$entry], ['sentiment' => 'Positive', 'minRating' => 4])) === 1, 'Analytics filters failed.');
$assert(!isset($row['subjectEnc']) && !isset($row['feedbackEnc']), 'Encrypted aliases leaked.');
putenv('SFMS_AES_ACTIVE_KEY_ID');
putenv('SFMS_AES_KEY_PHASE5_TEST');
echo "PASS ({$count} assertions)\n";
