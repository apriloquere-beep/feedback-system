<?php
/**
 * Streamlined Footer - Calape Primary Care Facility
 * Optimized for minimal height and essential links
 */
require_once __DIR__ . '/../api/security/AdminSecurity.php';
?>
<!-- Shared footer markup used across the site. -->
<footer class="app-footer">
    <div class="footer-container">
        <div class="footer-brand-min">
            <h4>Calape Primary Care Facility</h4>
            <p>Smart Feedback & Management System</p>
        </div>

        <div class="footer-links-min">
            <a href="/Feedback/modules/dashboard/home.php"><?= htmlspecialchars($t['navHome'] ?? 'Home') ?></a>
            <form method="post" action="/Feedback/modules/dashboard/home.php">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(sfmsCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="start_feedback">
                <button type="submit"><?= htmlspecialchars($t['navSubmitFeedback'] ?? 'Submit Feedback') ?></button>
            </form>
            <a href="/Feedback/modules/dashboard/home.php#how-it-works"><?= htmlspecialchars($t['lblHowItWorks'] ?? 'How It Works') ?></a>
            <a href="/Feedback/index.php?view=privacy"><?= htmlspecialchars($t['footerPrivacyLabel'] ?? 'Privacy') ?></a>
        </div>

        <div class="footer-contact-min">
            <a class="footer-contact-link" href="https://www.facebook.com/profile.php?id=61577984592339" target="_blank" rel="noopener noreferrer">
                <i class="fab fa-facebook-f" aria-hidden="true"></i> RHU-Calape
            </a>
            <a class="footer-contact-link footer-contact-text" href="tel:09525871911">
                <i class="fas fa-phone-alt" aria-hidden="true"></i> 0952 587 1911
            </a>
            <a class="footer-contact-link" href="mailto:rhu_calape@yahoo.com">
                <i class="fas fa-envelope" aria-hidden="true"></i> rhu_calape@yahoo.com
            </a>
        </div>
    </div>
    
    <div class="footer-copyright">
        &copy; <?php echo date('Y'); ?> Calape Primary Care Facility. All Rights Reserved.
    </div>
</footer>

<link rel="stylesheet" href="/Feedback/css/footer.css">
