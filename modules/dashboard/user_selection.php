<?php
/**
 * User Selection page
 * Location: /modules/dashboard/user_selection.php
 * Sits between the homepage "Submit Feedback" button and form/feedback.php
 * so the respondent picks their type first; the choice is then passed to
 * the existing form via ?respondent=<key>, which form/feedback.php maps
 * back to the exact respondent values its own #resp_type select already
 * uses (see the whitelist near the top of form/feedback.php).
 *
 * Connected files:
 * - includes/header.php: shared site header
 * - lang/resolve.php: resolves $t from $_SESSION['selected_language']
 * - assets/css/user_selection.css: page styling
 * - form/feedback.php: destination of each respondent card
 */

// $_SESSION['selected_language'] is the single source of truth for the
// active language across the whole project; see lang/resolve.php.
require_once __DIR__ . '/../../includes/public_flow.php';
require_once __DIR__ . '/../../api/security/RespondentType.php';
sfmsRequireFeedbackJourney();

$respondentTypeWhitelist = [
    'patient' => RespondentType::PATIENT,
    'companion' => RespondentType::COMPANION,
    'business' => RespondentType::BUSINESS,
    'other' => RespondentType::OTHER,
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $providedToken = (string) ($_POST['csrf'] ?? '');
    $respondentKey = (string) ($_POST['respondent'] ?? '');
    if ($providedToken !== ''
        && hash_equals(sfmsCsrfToken(), $providedToken)
        && isset($respondentTypeWhitelist[$respondentKey])) {
        sfmsSelectFeedbackRespondent($respondentKey);
        header('Location: /Feedback/form/feedback.php');
        exit;
    }
}

require __DIR__ . '/../../lang/resolve.php';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($htmlLang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title id="pageTitle"><?= htmlspecialchars($t['userSelPageTitle']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/Feedback/assets/css/user_selection.css">
</head>
<body>
    <div class="bg-blur blur1"></div>
    <div class="bg-blur blur2"></div>

    <!-- Shared site header reused across public pages. -->
    <?php include __DIR__ . '/../../includes/header.php'; ?>

    <main class="hero selection-hero">
        <div class="container hero-content-centered">
            <div class="system-badge"><i class="fas fa-user-check"></i> <span><?= htmlspecialchars($t['userSelBadge']) ?></span></div>
            <div class="hero-text">
                <h1><?= htmlspecialchars($t['userSelHeading']) ?></h1>
                <p class="description-center"><?= htmlspecialchars($t['userSelDesc']) ?></p>
            </div>

            <div class="selection-grid">
                <div class="selection-card patient-card">
                    <div class="icon-wrap"><i class="fas fa-user-injured"></i></div>
                    <h3><?= htmlspecialchars($t['fb2_respTypePatient']) ?></h3>
                    <p><?= htmlspecialchars($t['userSelPatientDesc']) ?></p>
                    <form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars(sfmsCsrfToken()) ?>"><button type="submit" name="respondent" value="patient" class="btn-main"><?= htmlspecialchars($t['userSelPatientBtn']) ?></button></form>
                </div>

                <div class="selection-card companion-card">
                    <div class="icon-wrap"><i class="fas fa-user-friends"></i></div>
                    <h3><?= htmlspecialchars($t['fb2_respTypeCompanion']) ?></h3>
                    <p><?= htmlspecialchars($t['userSelCompanionDesc']) ?></p>
                    <form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars(sfmsCsrfToken()) ?>"><button type="submit" name="respondent" value="companion" class="btn-main"><?= htmlspecialchars($t['userSelCompanionBtn']) ?></button></form>
                </div>

                <div class="selection-card business-card">
                    <div class="icon-wrap"><i class="fas fa-briefcase"></i></div>
                    <h3><?= htmlspecialchars($t['fb2_respTypeVisitor']) ?></h3>
                    <p><?= htmlspecialchars($t['userSelBusinessDesc']) ?></p>
                    <form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars(sfmsCsrfToken()) ?>"><button type="submit" name="respondent" value="business" class="btn-main"><?= htmlspecialchars($t['userSelBusinessBtn']) ?></button></form>
                </div>

                <div class="selection-card other-card">
                    <div class="icon-wrap"><i class="fas fa-user"></i></div>
                    <h3><?= htmlspecialchars($t['fb2_optOther']) ?></h3>
                    <p><?= htmlspecialchars($t['userSelOtherDesc']) ?></p>
                    <form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars(sfmsCsrfToken()) ?>"><button type="submit" name="respondent" value="other" class="btn-main"><?= htmlspecialchars($t['userSelOtherBtn']) ?></button></form>
                </div>
            </div>

            <a href="/Feedback/modules/dashboard/home.php" class="back-link"><?= $t['btnReturnHome'] ?></a>
        </div>
    </main>

    <script src="/Feedback/assets/js/language.js?v=20260826b"></script>
</body>
</html>
