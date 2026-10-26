<?php
/**
 * Global site header/navigation - Calape Primary Care Facility
 * Shared partial included mid-<body> by every page; callers provide their
 * own <!DOCTYPE>/<head>/<body>, so this file emits no document wrapper.
 */
require_once __DIR__ . '/../api/security/AdminSecurity.php';
$current_page = basename($_SERVER['PHP_SELF']);
$submit_feedback_pages = ['feedback.php'];
$is_submit_feedback_page = in_array($current_page, $submit_feedback_pages, true);
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="/Feedback/css/header.css">
<script src="/Feedback/assets/js/browser-deterrent.js" defer></script>
<header class="app-header">
    <div class="header-container">
        <a href="/Feedback/modules/dashboard/home.php" class="brand-logo">
            <i class="fas fa-heartbeat fa-2x"></i>
            <div class="brand-text-group">
                <b>Smart Feedback Management System</b>
                <small>Calape Primary Care Facility</small>
            </div>
        </a>
        <!-- Mobile hamburger (appears <=768px) -->
        <button class="hamburger-btn header-hamburger" aria-label="Toggle navigation" aria-expanded="false">
            <span></span>
            <span></span>
            <span></span>
        </button>

        <nav class="header-nav">
            <ul class="nav-list">
                <li><a href="/Feedback/modules/dashboard/home.php" class="nav-link <?php echo ($current_page == 'home.php') ? 'active' : ''; ?>" id="navHomeLink"><?= htmlspecialchars($t['navHome'] ?? 'Home') ?></a></li>
                <li>
                    <form method="post" action="/Feedback/modules/dashboard/home.php" class="nav-submit-form">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(sfmsCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="start_feedback">
                        <button type="submit" class="nav-link <?php echo $is_submit_feedback_page ? 'active' : ''; ?>" id="navSubmitFeedbackLink"><?= htmlspecialchars($t['navSubmitFeedback'] ?? 'Submit Feedback') ?></button>
                    </form>
                </li>

                <li class="dropdown" id="langDropdown">
                    <button class="lang-btn" onclick="toggleDropdown()">
                        <i class="fas fa-globe"></i>
                        <span id="navLanguageLabel"><?= htmlspecialchars($t['navLanguage'] ?? 'Language') ?></span>
                        <i class="fas fa-chevron-down" style="font-size: 10px;"></i>
                    </button>
                    <div class="dropdown-content">
                        <a href="<?php echo htmlspecialchars(strtok($_SERVER['REQUEST_URI'], '?')); ?>" onclick="setLanguage('english'); return false;">English</a>
                        <a href="?lang=bisaya" onclick="setLanguage('bisaya'); return false;">Cebuano(Bisaya)</a>
                        <a href="?lang=tagalog" onclick="setLanguage('tagalog'); return false;">Tagalog</a>
                    </div>
                </li>
            </ul>
        </nav>
    </div>
</header>

<script>
    // Mobile hamburger toggle for header nav
    (function() {
        const hamburger = document.querySelector('.header-hamburger');
        const navList = document.querySelector('.nav-list');

        function closeNav() {
            if (hamburger) {
                hamburger.classList.remove('active');
                hamburger.setAttribute('aria-expanded', 'false');
            }
            if (navList) navList.classList.remove('active');
        }

        if (hamburger && navList) {
            hamburger.addEventListener('click', function(e) {
                e.stopPropagation();
                const expanded = hamburger.getAttribute('aria-expanded') === 'true';
                hamburger.classList.toggle('active');
                hamburger.setAttribute('aria-expanded', (!expanded).toString());
                navList.classList.toggle('active');
            });

            // Close when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.header-container') && navList.classList.contains('active')) {
                    closeNav();
                }
            });

            // Close on resize to desktop
            window.addEventListener('resize', function() {
                if (window.innerWidth > 768) closeNav();
            });
        }
    })();

    // Logic to open/close the language menu
    function toggleDropdown() {
        document.getElementById("langDropdown").classList.toggle("active");
    }

    // Close the dropdown if the user clicks outside of it
    window.onclick = function(event) {
        if (!event.target.closest('.dropdown')) {
            var dropdowns = document.getElementsByClassName("dropdown");
            for (var i = 0; i < dropdowns.length; i++) {
                var openDropdown = dropdowns[i];
                if (openDropdown.classList.contains('active')) {
                    openDropdown.classList.remove('active');
                }
            }
        }
    }
</script>
