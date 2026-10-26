<?php

declare(strict_types=1);

function normalizeStatus(string $status): string
{
    return strtolower(trim($status));
}

require_once __DIR__ . '/../api/sentiment.php';
require_once __DIR__ . '/../api/security/FeedbackAnalytics.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$allowed = ['Positive', 'Negative', 'Neutral'];
$examples = [
    'The staff were helpful and kind.',
    'The service was good but the wait was slow.',
    'The room was clean and the staff were rude.',
    'Please add a ramp near the entrance.',
    'The service was ordinary.',
    'Nothing specific to report.',
];
foreach ($examples as $text) {
    $result = analyzeSentiment($text);
    $assert(in_array($result['label'], $allowed, true), 'Analyzer returned a non-category label.');
}

$assert(analyzeSentiment('The staff were helpful but the wait was slow.')['label'] === 'Negative', 'Contrast should follow the final evaluative clause.');
$assert(analyzeSentiment('The staff were helpful and rude.')['label'] === 'Neutral', 'Balanced conflicting evidence should resolve to Neutral.');
$assert(sentimentNormalizeLabel('', 'The nurse was helpful.') === 'Positive', 'Missing labels should be analyzed from written feedback at read time.');
$assert(analyzeSentiment('')['label'] === 'Neutral', 'Empty text must use the internal neutral fallback.');
$assert(analyzeSentiment('sqd4:5; a2:5;')['label'] === 'Neutral', 'Ratings-only text must use the internal neutral fallback.');
$assert(sentimentNormalizeLabel('Not Sure', 'The nurse was helpful.') === 'Positive', 'Unsupported stored labels must be derived from written feedback.');
$assert(sentimentNormalizeLabel('', 'sqd4:5; a2:5;') === 'Neutral', 'Ratings-only text must resolve to Neutral.');
$assert(sentimentNormalizeLabel('Mixed', 'sqd4:5; a2:5;') === 'Neutral', 'Unsupported labels without analyzable prose must resolve to Neutral.');
$assert(sentimentNormalizeLabel('Not Sure') === 'Neutral', 'Unsupported stored labels without written text must resolve to Neutral.');
$assert(analyzeSentiment('Maayo ang nurse pero dugay kaayo ang hulat.')['label'] === 'Negative', 'Mixed-language contrast must follow the final negative clause.');
$assert(analyzeSentiment('Please provide a clearer queue number.')['label'] === 'Neutral', 'Ambiguous requests must resolve to Neutral.');

$entries = [
    ['sentimentLabel' => 'Positive'],
    ['sentimentLabel' => 'Negative'],
    ['sentimentLabel' => 'Neutral'],
    ['sentimentLabel' => 'Neutral'],
];
$assert(count(FeedbackAnalytics::filter($entries, ['sentiment' => 'positive'])) === 1, 'Valid sentiment filtering failed.');
$assert(count(FeedbackAnalytics::filter($entries, ['sentiment' => 'Mixed'])) === 0, 'Unsupported sentiment filters must not match records.');
$assert(count(FeedbackAnalytics::filter($entries, ['sentiment' => 'Not Sure'])) === 0, 'Legacy sentiment filters must not match records.');

echo "PASS ({$assertions} assertions)\n";
