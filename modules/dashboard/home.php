<?php
/**
 * Home Page / Dashboard
 * Location: /modules/dashboard/home.php
 * Main landing page with statistics and system overview
 *
 * Connected files:
 * - api/db.php: database connection and PDO helper
 * - includes/header.php: shared site header
 * - includes/footer.php: shared site footer
 * - lang/resolve.php: resolves $t from $_SESSION['selected_language']
 * - assets/js/language.js: language dropdown -> lang/load.php -> reload
 * - assets/css/home.css: dashboard styling
 */

// $_SESSION['selected_language'] is the single source of truth for the
// active language across the whole project; see lang/resolve.php. This
// page's own visible text is now rendered from $t[], the same as
// form/feedback.php, instead of being patched in afterward by JavaScript.
require_once __DIR__ . '/../../includes/public_flow.php';
sfmsRequireOnboarding();
require __DIR__ . '/../../lang/resolve.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'start_feedback') {
    $providedToken = (string) ($_POST['csrf'] ?? '');
    if ($providedToken !== '' && hash_equals(sfmsCsrfToken(), $providedToken)) {
        sfmsBeginFeedbackJourney();
        header('Location: /Feedback/modules/dashboard/user_selection.php');
        exit;
    }
}

// Shared database connection used to build the dashboard statistics.
require_once dirname(dirname(dirname(__FILE__))) . '/api/db.php';

try {
    $pdo = getPdo();

    $currentMonth = date('m');
    $currentYear = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM feedback_entries WHERE MONTH(created_at) = ? AND YEAR(created_at) = ?");
    $stmt->execute([$currentMonth, $currentYear]);
    $monthlyReviews = $stmt->fetchColumn() ?: 0;

    $totalFeedback = $pdo->query("SELECT COUNT(*) FROM feedback_entries")->fetchColumn() ?: 0;
    $resolvedFeedback = $pdo->query("SELECT COUNT(*) FROM feedback_entries WHERE status = 'resolved'")->fetchColumn() ?: 0;

    $responseRate = 0;
    if ($totalFeedback > 0) {
        $responseRate = round(($resolvedFeedback / $totalFeedback) * 100);
    }
} catch (Exception $e) {
    error_log('Database error in home page: ' . $e->getMessage());
    $monthlyReviews = 0;
    $totalFeedback = 0;
    $responseRate = 0;
}

function formatNumber($num) {
    if ($num >= 1000) {
        return round($num / 1000, 1) . 'k+';
    }
    return $num;
}

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($htmlLang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title id="pageTitle"><?= htmlspecialchars($t['pageTitle']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/Feedback/assets/css/home.css">
</head>
<body class="home-page">
    <!-- Shared site header reused across dashboard pages. -->
    <?php include '../../includes/header.php'; ?>

    <main class="home-main">
        <!-- Hero Section -->
        <section class="hero-section">
            <div class="hero-background">
                <div class="gradient-blob blob-1"></div>
                <div class="gradient-blob blob-2"></div>
            </div>

            <div class="hero-container">
                <div class="hero-content">
                <div class="system-badge" id="lblSecurePortal">
                    <?= $t['lblSecurePortal'] ?>
                </div>

                <h1 class="hero-title" id="heroTitle">
                    <?= $t['heroTitle'] ?>
                </h1>

                <p class="hero-subtitle" id="heroDesc">
                    <?= htmlspecialchars($t['heroDesc']) ?>
                </p>

                <div class="hero-actions">
                    <form method="post" class="start-feedback-form">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(sfmsCsrfToken()) ?>">
                        <input type="hidden" name="action" value="start_feedback">
                    <button type="submit" class="btn-main" id="submit-feedback">
                        <i class="fas fa-plus"></i>
                        <span id="btnStartFeedback"><?= htmlspecialchars($t['btnStartFeedback']) ?></span>
                    </button>
                    </form>
                </div>

                <!-- Statistics Panel -->
                <div class="stats-panel">
                    <div class="stat-card">
                        <div class="stat-number" id="monthlyReviewsCount"><?php echo formatNumber($monthlyReviews); ?></div>
                        <div class="stat-label" id="lblMonthlyReviews"><?= htmlspecialchars($t['lblMonthlyReviews']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" id="responseRateCount"><?php echo $responseRate; ?>%</div>
                        <div class="stat-label" id="lblResponseRate"><?= htmlspecialchars($t['lblResponseRate']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" id="totalReviewsCount"><?php echo formatNumber($totalFeedback); ?></div>
                        <div class="stat-label" id="lblTotalReviews"><?= htmlspecialchars($t['lblTotalReviews']) ?></div>
                    </div>
                </div>
                </div>

                <button type="button" class="scroll-down" id="scrollToHowItWorks" aria-label="Scroll to How It Works section">
                    <span class="chevron" aria-hidden="true"></span>
                </button>
            </div>
        </section>

        <!-- How It Works Section -->
        <section class="features-section" id="how-it-works">
            <div class="section-container">
                <h2 class="section-title" id="lblHowItWorks"><?= htmlspecialchars($t['lblHowItWorks']) ?></h2>
                <div class="title-line"></div>
                <p class="hero-subtitle" style="text-align: center;"><?= htmlspecialchars($t['howItWorksIntro']) ?></p>
            </div>

            <div class="steps-grid">
                <div class="step-card animate__animated animate__fadeInUp">
                    <div class="step-number">1</div>
                    <div class="icon-wrap">
                        <i class="fas fa-language"></i>
                    </div>
                    <h4><?= htmlspecialchars($t['howStep1Title']) ?></h4>
                    <p><?= htmlspecialchars($t['howStep1Description']) ?></p>
                </div>

                <div class="step-card animate__animated animate__fadeInUp animate__delay-1s">
                    <div class="step-number">2</div>
                    <div class="icon-wrap">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <h4><?= htmlspecialchars($t['howStep2Title']) ?></h4>
                    <p><?= htmlspecialchars($t['howStep2Description']) ?></p>
                </div>

                <div class="step-card animate__animated animate__fadeInUp animate__delay-2s">
                    <div class="step-number">3</div>
                    <div class="icon-wrap">
                        <i class="fas fa-plus"></i>
                    </div>
                    <h4><?= htmlspecialchars($t['howStep3Title']) ?></h4>
                    <p><?= htmlspecialchars($t['howStep3Description']) ?></p>
                </div>

                <div class="step-card animate__animated animate__fadeInUp">
                    <div class="step-number">4</div>
                    <div class="icon-wrap">
                        <i class="fas fa-user-friends"></i>
                    </div>
                    <h4><?= htmlspecialchars($t['howStep4Title']) ?></h4>
                    <p><?= htmlspecialchars($t['howStep4Description']) ?></p>
                </div>

                <div class="step-card animate__animated animate__fadeInUp animate__delay-1s">
                    <div class="step-number">5</div>
                    <div class="icon-wrap">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <h4><?= htmlspecialchars($t['howStep5Title']) ?></h4>
                    <p><?= htmlspecialchars($t['howStep5Description']) ?></p>
                </div>

                <div class="step-card animate__animated animate__fadeInUp animate__delay-2s">
                    <div class="step-number">6</div>
                    <div class="icon-wrap">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h4><?= htmlspecialchars($t['howStep6Title']) ?></h4>
                    <p><?= htmlspecialchars($t['howStep6Description']) ?></p>
                </div>
            </div>
        </section>

    </main>

    <!-- Shared site footer reused across dashboard pages. -->
    <?php include '../../includes/footer.php'; ?>

    <!-- Loads the language dropdown's switching logic (header.php). This
         page's own text above is already server-rendered in the correct
         language, so no client-side translation patching runs here. -->
    <script src="/Feedback/assets/js/language.js?v=20260826b"></script>
    <script>
        (function () {
            const trigger = document.getElementById('scrollToHowItWorks');
            const target = document.getElementById('how-it-works');

            if (!trigger || !target) return;

            trigger.addEventListener('click', function () {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        })();

    </script>

</body>
</html>
