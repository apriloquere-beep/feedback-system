<?php

declare(strict_types=1);

session_set_save_handler(
    static fn (string $path, string $name): bool => true,
    static fn (): bool => true,
    static fn (string $id): string => '',
    static fn (string $id, string $data): bool => true,
    static fn (string $id): bool => true,
    static fn (int $maxLifetime): int|false => 0
);

require_once __DIR__ . '/../includes/public_flow.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$_SESSION = [];
$assert(!sfmsOnboardingComplete(), 'A new session must not bypass onboarding.');
$assert(!sfmsFeedbackJourneyStarted(), 'A new session must not have an active journey.');

$_SESSION['selected_language'] = 'bisaya';
$_SESSION['language_preference_confirmed'] = true;
$assert(!sfmsOnboardingComplete(), 'Language selection alone must not bypass privacy.');

$_SESSION['privacy_accepted'] = true;
$assert(sfmsOnboardingComplete(), 'Language and privacy must complete onboarding.');
$assert($_SESSION['selected_language'] === 'bisaya', 'Onboarding must retain the selected language.');

sfmsBeginFeedbackJourney();
$assert(sfmsFeedbackJourneyStarted(), 'Start Feedback must activate the journey.');
$assert(sfmsSelectedFeedbackRespondent() === '', 'A new journey must clear an old respondent selection.');

sfmsSelectFeedbackRespondent('patient');
$assert(sfmsSelectedFeedbackRespondent() === 'patient', 'Respondent selection must be stored in the session.');

sfmsCompleteFeedbackJourney();
$assert(!sfmsFeedbackJourneyStarted(), 'Submission completion must clear the active journey.');
$assert(sfmsSelectedFeedbackRespondent() === '', 'Submission completion must clear the respondent.');
$assert(sfmsOnboardingComplete(), 'Submission completion must retain onboarding state.');
$assert($_SESSION['selected_language'] === 'bisaya', 'Submission completion must retain language preference.');

sfmsBeginFeedbackJourney();
sfmsSelectFeedbackRespondent('business');
sfmsBeginFeedbackJourney();
$assert(sfmsSelectedFeedbackRespondent() === '', 'Starting another journey must clear the prior respondent.');
$assert($_SESSION['selected_language'] === 'bisaya', 'Starting another journey must not reset language.');

$assert(!sfmsHasSubmittedFeedbackToday(), 'A new respondent must not start with a daily submission marker.');
sfmsMarkFeedbackSubmittedToday();
$assert(sfmsHasSubmittedFeedbackToday(), 'A successful submission must activate the daily limit.');
$_SESSION['feedback_submitted_date'] = date('Y-m-d', strtotime('-1 day'));
$_COOKIE['sfms_feedback_submitted_date'] = date('Y-m-d', strtotime('-1 day'));
$assert(!sfmsHasSubmittedFeedbackToday(), 'The daily limit must automatically expire on the next calendar day.');

sfmsSelectFeedbackRespondent('patient');
$_SESSION['unrelated_state'] = 'preserve-me';
$activeDestination = sfmsQrEntryDestination();
$assert($activeDestination === '/Feedback/form/feedback.php', 'QR entry must resume an active journey at the form.');
$assert(sfmsFeedbackJourneyStarted(), 'QR entry must not stop an active journey.');
$assert(sfmsSelectedFeedbackRespondent() === 'patient', 'QR entry must preserve the active respondent.');
$assert(sfmsOnboardingComplete(), 'QR entry must preserve onboarding during an active journey.');

unset($_SESSION['feedback_respondent_key']);
$selectionDestination = sfmsQrEntryDestination();
$assert($selectionDestination === '/Feedback/modules/dashboard/user_selection.php', 'QR entry must resume an active journey at user selection when needed.');
$assert(sfmsFeedbackJourneyStarted(), 'Resuming user selection must keep the journey active.');

sfmsCompleteFeedbackJourney();
$qrDestination = sfmsQrEntryDestination();
$assert($qrDestination === '/Feedback/', 'A new QR visit must return to onboarding.');
$assert(!sfmsOnboardingComplete(), 'A new QR visit must reset onboarding completion.');
$assert(!sfmsFeedbackJourneyStarted(), 'A new QR visit must not carry a completed journey.');
$assert($_SESSION['selected_language'] === 'bisaya', 'A new QR visit must preserve language preference separately.');
$assert($_SESSION['unrelated_state'] === 'preserve-me', 'A new QR visit must preserve unrelated session state.');

$onboardingKeys = [
    'onboardingLanguageTitle', 'onboardingLanguageText', 'onboardingPrivacyEyebrow',
    'onboardingPrivacyTitle', 'onboardingPrivacyIntro', 'onboardingPrivacyFullNotice',
    'onboardingPrivacyText', 'onboardingPrivacyConsent', 'onboardingPrivacyDecline',
    'onboardingPrivacyAccept', 'onboardingPrivacyRequired', 'onboardingSessionExpired',
];
foreach (['english', 'bisaya', 'tagalog'] as $languageFile) {
    $translations = require __DIR__ . '/../lang/' . $languageFile . '.php';
    foreach ($onboardingKeys as $key) {
        $assert(isset($translations[$key]) && $translations[$key] !== '', $languageFile . ' is missing ' . $key . '.');
    }
}

echo 'PASS (' . $assertions . " assertions)\n";
