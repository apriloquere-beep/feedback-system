<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/security/AdminSecurity.php';

sfmsStartSecureSession();

function sfmsOnboardingComplete(): bool
{
    return ($_SESSION['language_preference_confirmed'] ?? false) === true
        && ($_SESSION['privacy_accepted'] ?? false) === true;
}

function sfmsRequireOnboarding(): void
{
    if (!sfmsOnboardingComplete()) {
        header('Location: /Feedback/');
        exit;
    }
}

function sfmsBeginFeedbackJourney(): void
{
    $_SESSION['feedback_journey_started'] = true;
    unset($_SESSION['feedback_respondent_key']);
}

function sfmsFeedbackJourneyStarted(): bool
{
    return sfmsOnboardingComplete()
        && ($_SESSION['feedback_journey_started'] ?? false) === true;
}

function sfmsRequireFeedbackJourney(): void
{
    sfmsRequireOnboarding();
    if (!sfmsFeedbackJourneyStarted()) {
        header('Location: /Feedback/modules/dashboard/home.php');
        exit;
    }
}

function sfmsSelectFeedbackRespondent(string $respondentKey): void
{
    $_SESSION['feedback_respondent_key'] = $respondentKey;
}

function sfmsSelectedFeedbackRespondent(): string
{
    return (string) ($_SESSION['feedback_respondent_key'] ?? '');
}

function sfmsRequireFeedbackRespondent(): void
{
    sfmsRequireFeedbackJourney();
    if (sfmsSelectedFeedbackRespondent() === '') {
        header('Location: /Feedback/modules/dashboard/user_selection.php');
        exit;
    }
}

function sfmsCompleteFeedbackJourney(): void
{
    unset($_SESSION['feedback_journey_started'], $_SESSION['feedback_respondent_key']);
}

function sfmsFeedbackDailyDate(): string
{
    return date('Y-m-d');
}

function sfmsHasSubmittedFeedbackToday(): bool
{
    $today = sfmsFeedbackDailyDate();

    return hash_equals($today, (string) ($_SESSION['feedback_submitted_date'] ?? ''))
        || hash_equals($today, (string) ($_COOKIE['sfms_feedback_submitted_date'] ?? ''));
}

/**
 * Remember a successful submission until the next Manila calendar day.
 * The persistent cookie keeps the limit in place if the browser is closed,
 * while the session value makes it immediately effective for this request.
 */
function sfmsMarkFeedbackSubmittedToday(): void
{
    $today = sfmsFeedbackDailyDate();
    $_SESSION['feedback_submitted_date'] = $today;

    $tomorrow = (new DateTimeImmutable('tomorrow'))->getTimestamp();
    setcookie('sfms_feedback_submitted_date', $today, [
        'expires' => $tomorrow,
        'path' => '/Feedback/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['sfms_feedback_submitted_date'] = $today;
}

/**
 * Handle the explicit entry encoded in the facility QR code.
 *
 * An in-progress feedback journey always wins: none of its session state is
 * changed, and the respondent resumes at the furthest server-known step.
 * Otherwise only public onboarding/journey flags are cleared. Language and
 * every unrelated session value remain intact.
 */
function sfmsQrEntryDestination(): string
{
    if (sfmsFeedbackJourneyStarted()) {
        return sfmsSelectedFeedbackRespondent() !== ''
            ? '/Feedback/form/feedback.php'
            : '/Feedback/modules/dashboard/user_selection.php';
    }

    unset(
        $_SESSION['language_preference_confirmed'],
        $_SESSION['privacy_accepted'],
        $_SESSION['feedback_journey_started'],
        $_SESSION['feedback_respondent_key']
    );

    return '/Feedback/';
}
