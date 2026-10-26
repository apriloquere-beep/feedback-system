<?php
/**
 * Feedback Submission Confirmation Page
 * Location: /modules/feedback/confirmation.php
 */

session_start();

// Check if submission was successful
if (!isset($_SESSION['submission_success']) || !$_SESSION['submission_success']) {
    header('Location: /Feedback/');
    exit;
}

$submission = $_SESSION['submission_data'] ?? [];
$referenceId = $submission['referenceId'] ?? 'N/A';

$pageTitle = 'Feedback Submitted Successfully';
$breadcrumbs = [
    ['label' => 'Home', 'url' => '/Feedback/'],
    ['label' => 'Submission Confirmed', 'url' => '/Feedback/modules/feedback/confirmation.php', 'id' => 'pageTitle']
];

// Clear session data
unset($_SESSION['submission_success']);
unset($_SESSION['submission_data']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title id="pageTitle">Feedback Submitted Successfully</title>
    <script>document.documentElement.classList.add('lang-loading');</script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="/Feedback/assets/css/feedback.css">
    <style>html.lang-loading body { visibility: hidden; }</style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>

    <main class="page-main">
        <div class="page-container">
            <div class="confirmation-container">
                <!-- Success Message -->
                <div class="success-badge">
                    <i class="fas fa-check-circle"></i>
                </div>

                <h1 id="confirmationTitle">Thank You!</h1>
                <p id="confirmationMessage" class="confirmation-subtitle">Your feedback has been submitted successfully.</p>

                <!-- Reference ID -->
                <div class="reference-box">
                    <p id="referenceLabel">Your Reference ID:</p>
                    <div class="reference-id" onclick="copyToClipboard('<?php echo htmlspecialchars($referenceId); ?>')">
                        <span id="referenceIdDisplay"><?php echo htmlspecialchars($referenceId); ?></span>
                        <button type="button" class="copy-btn" title="Copy to clipboard">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                    <p id="referenceHint" class="reference-hint">Keep this ID for your records. You can use it to track your feedback status.</p>
                </div>

                <!-- Submission Details -->
                <?php if (!empty($submission)): ?>
                <div class="submission-details">
                    <h3 id="detailsTitle">Submission Details</h3>
                    <div class="details-grid">
                        <?php if (!empty($submission['firstName'])): ?>
                        <div class="detail-item">
                            <span class="detail-label" id="nameLabel">Name:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($submission['firstName'] . ' ' . ($submission['lastName'] ?? '')); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($submission['department'])): ?>
                        <div class="detail-item">
                            <span class="detail-label" id="departmentLabel">Department:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($submission['department']); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($submission['service'])): ?>
                        <div class="detail-item">
                            <span class="detail-label" id="serviceLabel">Service:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($submission['service']); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($submission['feedbackType'])): ?>
                        <div class="detail-item">
                            <span class="detail-label" id="feedbackTypeLabel">Feedback Type:</span>
                            <span class="detail-value"><?php echo ucfirst(htmlspecialchars($submission['feedbackType'])); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($submission['subject'])): ?>
                        <div class="detail-item">
                            <span class="detail-label" id="subjectLabel">Subject:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($submission['subject']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Ratings -->
                    <div class="ratings-summary">
                        <h4 id="ratingsTitle">Ratings</h4>
                        <div class="ratings-grid">
                            <?php if (!empty($submission['serviceRating'])): ?>
                            <div class="rating-item">
                                <span id="serviceRatingLabel">Service:</span>
                                <div class="stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star<?php echo $i <= $submission['serviceRating'] ? '' : ' empty'; ?>"></i>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($submission['staffRating'])): ?>
                            <div class="rating-item">
                                <span id="staffRatingLabel">Staff:</span>
                                <div class="stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star<?php echo $i <= $submission['staffRating'] ? '' : ' empty'; ?>"></i>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($submission['facilityRating'])): ?>
                            <div class="rating-item">
                                <span id="facilityRatingLabel">Facility:</span>
                                <div class="stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star<?php echo $i <= $submission['facilityRating'] ? '' : ' empty'; ?>"></i>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div class="confirmation-actions">
                    <a href="/Feedback/modules/dashboard/home.php" class="btn btn-secondary" id="backHomeBtn">
                        <i class="fas fa-home"></i> <span id="backHomeText">Back to Home</span>
                    </a>
                </div>

                <!-- Additional Info -->
                <div class="confirmation-info">
                    <p id="infoText">Thank you for taking the time to provide your feedback. We will review it carefully and use your insights to improve our services.</p>
                </div>
            </div>
        </div>
    </main>

    <?php include '../../includes/footer.php'; ?>

    <script>
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                const btn = document.querySelector('.copy-btn');
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i>';
                setTimeout(() => {
                    btn.innerHTML = originalHTML;
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy:', err);
            });
        }
    </script>

    <script src="/Feedback/assets/js/language.js?v=20260826b"></script>
</body>
</html>

<style>
    .confirmation-container {
        max-width: 700px;
        margin: 40px auto;
        text-align: center;
        padding: 20px;
    }

    .success-badge {
        font-size: 80px;
        color: #10b981;
        margin-bottom: 20px;
        animation: bounceIn 0.6s ease-out;
    }

    @keyframes bounceIn {
        0% {
            transform: scale(0.3);
            opacity: 0;
        }
        50% {
            opacity: 1;
        }
        70% {
            transform: scale(1.05);
        }
        100% {
            transform: scale(1);
        }
    }

    .confirmation-container h1 {
        font-size: 36px;
        margin: 0 0 10px 0;
        color: #1f2937;
    }

    .confirmation-subtitle {
        font-size: 18px;
        color: #6b7280;
        margin: 0 0 30px 0;
    }

    .reference-box {
        background: #f0fdf4;
        border: 2px solid #10b981;
        border-radius: 12px;
        padding: 20px;
        margin: 30px 0;
    }

    .reference-box p {
        margin: 0 0 12px 0;
        color: #374151;
        font-weight: 500;
    }

    .reference-id {
        background: white;
        border: 2px dashed #10b981;
        border-radius: 8px;
        padding: 15px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
        cursor: pointer;
        transition: background 0.2s;
    }

    .reference-id:hover {
        background: #f9fafb;
    }

    .reference-id span {
        font-size: 20px;
        font-weight: 700;
        color: #10b981;
        font-family: 'Courier New', monospace;
        letter-spacing: 1px;
    }

    .copy-btn {
        background: none;
        border: none;
        color: #10b981;
        font-size: 18px;
        cursor: pointer;
        padding: 5px;
        transition: transform 0.2s;
    }

    .copy-btn:hover {
        transform: scale(1.1);
    }

    .reference-hint {
        font-size: 13px;
        color: #6b7280;
        margin: 0;
    }

    .submission-details {
        background: white;
        border-radius: 12px;
        padding: 25px;
        margin: 30px 0;
        text-align: left;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }

    .submission-details h3 {
        margin: 0 0 20px 0;
        font-size: 18px;
        text-align: center;
        color: #1f2937;
    }

    .details-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 15px;
        margin-bottom: 25px;
    }

    .detail-item {
        display: flex;
        flex-direction: column;
        gap: 5px;
        padding: 12px;
        background: #f9fafb;
        border-radius: 8px;
    }

    .detail-label {
        font-weight: 600;
        color: #6b7280;
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .detail-value {
        color: #1f2937;
        font-weight: 500;
    }

    .ratings-summary {
        border-top: 1px solid #e5e7eb;
        padding-top: 20px;
    }

    .ratings-summary h4 {
        margin: 0 0 15px 0;
        color: #1f2937;
        text-align: center;
    }

    .ratings-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 15px;
    }

    .rating-item {
        display: flex;
        flex-direction: column;
        gap: 8px;
        padding: 12px;
        background: #f9fafb;
        border-radius: 8px;
        text-align: center;
    }

    .rating-item span {
        font-weight: 600;
        color: #6b7280;
        font-size: 13px;
    }

    .stars {
        display: flex;
        justify-content: center;
        gap: 4px;
        font-size: 16px;
    }

    .stars i {
        color: #fbbf24;
    }

    .stars i.empty {
        color: #d1d5db;
    }

    .confirmation-actions {
        display: flex;
        gap: 15px;
        margin: 30px 0;
        justify-content: center;
        flex-wrap: wrap;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 12px 28px;
        border-radius: 8px;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.2s;
        border: none;
        cursor: pointer;
        font-size: 16px;
    }

    .btn-primary {
        background: #10b981;
        color: white;
    }

    .btn-primary:hover {
        background: #059669;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }

    .btn-secondary {
        background: #667eea;
        color: white;
    }

    .btn-secondary:hover {
        background: #5568d3;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
    }

    .confirmation-info {
        background: #fffbeb;
        border-left: 4px solid #f59e0b;
        padding: 15px 20px;
        border-radius: 8px;
        margin-top: 30px;
    }

    .confirmation-info p {
        margin: 0;
        color: #92400e;
        font-size: 14px;
        line-height: 1.6;
    }

    @media (max-width: 768px) {
        .confirmation-container {
            margin: 20px auto;
        }

        .success-badge {
            font-size: 60px;
        }

        .confirmation-container h1 {
            font-size: 28px;
        }

        .reference-id {
            flex-direction: column;
            gap: 10px;
        }

        .details-grid,
        .ratings-grid {
            grid-template-columns: 1fr;
        }

        .confirmation-actions {
            flex-direction: column;
        }

        .btn {
            width: 100%;
        }
    }
</style>
