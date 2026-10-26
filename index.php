<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/public_flow.php';
require_once __DIR__ . '/lang/normalize.php';

$readOnlyPrivacy = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && ($_GET['view'] ?? '') === 'privacy';

if (sfmsOnboardingComplete() && !$readOnlyPrivacy) {
    header('Location: /Feedback/modules/dashboard/home.php');
    exit;
}

$errorKey = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrf = (string) ($_POST['csrf'] ?? '');
    if ($csrf === '' || !hash_equals(sfmsCsrfToken(), $csrf)) {
        $errorKey = 'onboardingSessionExpired';
    } elseif (($_POST['action'] ?? '') === 'select_language') {
        $allowed = ['english', 'bisaya', 'tagalog'];
        $language = normalize_selected_language((string) ($_POST['language'] ?? ''));
        if (in_array($language, $allowed, true)) {
            $_SESSION['selected_language'] = $language;
            $_SESSION['language_preference_confirmed'] = true;
            header('Location: /Feedback/');
            exit;
        }
    } elseif (($_POST['action'] ?? '') === 'accept_privacy'
        && ($_SESSION['language_preference_confirmed'] ?? false) === true) {
        if (($_POST['privacy_ack'] ?? '') === '1') {
            $_SESSION['privacy_accepted'] = true;
            header('Location: /Feedback/modules/dashboard/home.php');
            exit;
        }
        $errorKey = 'onboardingPrivacyRequired';
    } elseif (($_POST['action'] ?? '') === 'decline_privacy') {
        unset($_SESSION['language_preference_confirmed'], $_SESSION['privacy_accepted']);
        header('Location: /Feedback/');
        exit;
    }
}

require __DIR__ . '/lang/resolve.php';
$showPrivacy = $readOnlyPrivacy || ($_SESSION['language_preference_confirmed'] ?? false) === true;
$error = $errorKey !== '' ? ($t[$errorKey] ?? '') : '';

$onboardingContent = [
    'english' => [
        'helper' => 'Your language can be changed anytime.',
        'privacyEyebrow' => 'Your privacy matters',
        'fullNoticeLabel' => 'Read full privacy notice',
    ],
    'bisaya' => [
        'helper' => 'Mahimo nimong usbon ang imong pinulongan bisan kanus-a.',
        'privacyEyebrow' => 'Importante ang imong privacy',
        'fullNoticeLabel' => 'Basaha ang tibuok pahibalo sa privacy',
    ],
    'tagalog' => [
        'helper' => 'Maaari mong baguhin ang iyong wika anumang oras.',
        'privacyEyebrow' => 'Mahalaga ang iyong privacy',
        'fullNoticeLabel' => 'Basahin ang buong abiso sa privacy',
    ],
];
$pageContent = $onboardingContent[$selectedLanguage];
$pageHeading = $showPrivacy ? $t['onboardingPrivacyTitle'] : $t['onboardingLanguageTitle'];
$headingWords = preg_split('/\s+(?=\S+$)/u', $pageHeading, 2);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($htmlLang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageHeading) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/Feedback/assets/css/onboarding.css?v=20261025-privacy-content">
</head>
<body>
    <header class="onboarding-brand">
        <div class="brand-inner">
            <svg class="brand-mark" viewBox="0 0 48 48" fill="none" aria-hidden="true"><path d="M24 40S5 29.1 5 16.9C5 9.9 10.3 5 16.8 5c3.2 0 5.6 1.5 7.2 3.6C25.6 6.5 28 5 31.2 5 37.7 5 43 9.9 43 16.9 43 29.1 24 40Z" stroke="currentColor" stroke-width="2.6" stroke-linejoin="round"/><path d="M9 23h9l3-6 5 12 3-6h10" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <div class="brand-copy"><strong>Smart Feedback Management System</strong><span>Calape Primary Care Facility</span></div>
        </div>
    </header>
    <main class="onboarding-shell">
        <div class="onboarding-orb onboarding-orb-one" aria-hidden="true"></div>
        <div class="onboarding-orb onboarding-orb-two" aria-hidden="true"></div>
        <section class="onboarding-content<?= $showPrivacy ? ' privacy-card' : ' language-content' ?>" aria-labelledby="page-title" aria-describedby="page-description">
            <?php if ($error !== ''): ?><p class="onboarding-error" role="alert"><?= htmlspecialchars($error) ?></p><?php endif; ?>
            <?php if (!$showPrivacy): ?>
                <p class="health-badge">Smart Community-Based Health System</p>
                <h1 id="page-title"><?= htmlspecialchars($headingWords[0]) ?> <span><?= htmlspecialchars($headingWords[1] ?? '') ?></span></h1>
                <p class="intro-copy" id="page-description"><?= htmlspecialchars($t['onboardingLanguageText']) ?></p>
                <form method="post" class="language-options">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(sfmsCsrfToken()) ?>">
                    <input type="hidden" name="action" value="select_language">
                    <button type="submit" name="language" value="english" class="language-card"><span class="language-card-icon" aria-hidden="true">EN</span><span class="language-card-copy"><strong>English</strong><small>English</small></span><span class="language-arrow" aria-hidden="true">&#8594;</span></button>
                    <button type="submit" name="language" value="bisaya" class="language-card"><span class="language-card-icon" aria-hidden="true">BI</span><span class="language-card-copy"><strong>Bisaya</strong><small>Cebuano</small></span><span class="language-arrow" aria-hidden="true">&#8594;</span></button>
                    <button type="submit" name="language" value="tagalog" class="language-card"><span class="language-card-icon" aria-hidden="true">TL</span><span class="language-card-copy"><strong>Tagalog</strong><small>Filipino</small></span><span class="language-arrow" aria-hidden="true">&#8594;</span></button>
                </form>
                <p class="language-helper"><?= htmlspecialchars($pageContent['helper']) ?></p>
            <?php else: ?>
                <div class="privacy-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 4.7 2.8 8.1 7 10 4.2-1.9 7-5.3 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></svg></div>
                <p class="privacy-eyebrow"><?= htmlspecialchars($pageContent['privacyEyebrow']) ?></p>
                <h1 id="page-title"><?= htmlspecialchars($headingWords[0]) ?> <span><?= htmlspecialchars($headingWords[1] ?? '') ?></span></h1>
                <p class="intro-copy" id="page-description"><?= htmlspecialchars($t['onboardingPrivacyIntro']) ?></p>
                <div class="privacy-sections">
                    <section class="privacy-section">
                        <span class="section-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 19V9m6 10V5m6 14v-7m4 7H2"/><path d="m3 7 5-4 5 4 7-5"/></svg></span>
                        <div><h2><?= htmlspecialchars($t['onboardingPrivacyUseTitle']) ?></h2><p><?= htmlspecialchars($t['onboardingPrivacyUseText']) ?></p></div>
                    </section>
                    <section class="privacy-section">
                        <span class="section-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 4.7 2.8 8.1 7 10 4.2-1.9 7-5.3 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></svg></span>
                        <div><h2><?= htmlspecialchars($t['onboardingPrivacyProtectionTitle']) ?></h2><p><?= htmlspecialchars($t['onboardingPrivacyProtectionText']) ?></p></div>
                    </section>
                    <section class="privacy-section">
                        <span class="section-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v2"/></svg></span>
                        <div><h2><?= htmlspecialchars($t['onboardingPrivacyMattersTitle']) ?></h2><p><?= htmlspecialchars($t['onboardingPrivacyMattersText']) ?></p></div>
                    </section>
                </div>
                <details class="privacy-notice" style="margin-top: 24px;"<?= $readOnlyPrivacy ? ' open' : '' ?>>
                    <summary><span class="notice-label"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h8l4 4v14H7z"/><path d="M15 3v5h4M10 12h6M10 16h6"/></svg><?= htmlspecialchars($pageContent['fullNoticeLabel']) ?></span><span class="details-chevron" aria-hidden="true"></span></summary>
                    <div class="privacy-copy"><?= $t['onboardingPrivacyTextUpdated'] ?></div>
                </details>
                <?php if (!$readOnlyPrivacy): ?>
                <form method="post" class="privacy-form">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(sfmsCsrfToken()) ?>">
                    <label class="privacy-consent"><input type="checkbox" name="privacy_ack" value="1" required><span class="consent-box" aria-hidden="true"><svg viewBox="0 0 16 16"><path d="m3 8 3 3 7-7"/></svg></span><span><?= htmlspecialchars($t['onboardingPrivacyConsent']) ?></span></label>
                    <div class="privacy-actions">
                        <button type="submit" name="action" value="decline_privacy" class="decline-action" formnovalidate><?= htmlspecialchars($t['onboardingPrivacyDecline']) ?></button>
                        <button type="submit" name="action" value="accept_privacy" class="primary-action" disabled><span><?= htmlspecialchars($t['onboardingPrivacyAccept']) ?></span><span aria-hidden="true">&#8594;</span></button>
                    </div>
                </form>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>
    <script>
        (function () {
            const languageForm = document.querySelector('.language-options');
            languageForm?.addEventListener('submit', function (event) {
                if (event.submitter) event.submitter.classList.add('is-submitting');
            });
            const privacyForm = document.querySelector('.privacy-form');
            if (!privacyForm) return;
            const checkbox = privacyForm.elements.privacy_ack;
            const continueButton = privacyForm.querySelector('[value="accept_privacy"]');
            const syncContinue = function () {
                continueButton.classList.remove('is-submitting');
                continueButton.removeAttribute('aria-busy');
                continueButton.disabled = !checkbox.checked;
            };
            checkbox.addEventListener('change', syncContinue);
            window.addEventListener('pageshow', syncContinue);
            syncContinue();
            privacyForm.addEventListener('submit', function (event) {
                if (event.submitter === continueButton && checkbox.checked) {
                    if (continueButton.classList.contains('is-submitting')) {
                        event.preventDefault();
                        return;
                    }
                    continueButton.classList.add('is-submitting');
                    continueButton.setAttribute('aria-busy', 'true');
                }
            });
        })();
    </script>
</body>
</html>
