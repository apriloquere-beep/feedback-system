<?php
/**
 * Feedback Detail View Page
 * Shows complete information about a single feedback entry
 * Includes: Full content, Status history, Internal notes
 */
require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/security/AdminSecurity.php';
sfmsStartSecureSession();
if (!isset($_SESSION['admin_id'])) {
    header('Location: /Feedback/admin/login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(sfmsCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <title>Feedback Details - Healthcare System</title>
    <link rel="stylesheet" href="/Feedback/css/admin_dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="/Feedback/css/feedback_detail.css">
    <script src="/Feedback/assets/js/admin-ui.js"></script>
    <script src="/Feedback/assets/js/browser-deterrent.js" defer></script>
</head>
<body>
    <div class="detail-container">
        <!-- HEADER -->
        <div class="detail-header">
            <div class="detail-header-top">
                <div class="detail-title">
                    <h1 id="feedbackSubject">Loading...</h1>
                    <p id="feedbackRef">Ref: —</p>
                </div>
                <span class="status-badge" id="statusBadge">Pending</span>
            </div>

            <div class="detail-meta">
                <div class="meta-item">
                    <span class="meta-label">Submitted</span>
                    <span class="meta-value" id="submittedDate">—</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Visit Date</span>
                    <span class="meta-value" id="visitDate">—</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Service</span>
                    <span class="meta-value" id="feedbackService">—</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Type</span>
                    <span class="meta-value" id="feedbackType">—</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Respondent Type</span>
                    <span class="meta-value" id="respondentType">—</span>
                </div>
                <div class="meta-item" id="respondentTypeOtherRow" hidden>
                    <span class="meta-label">Other respondent type</span>
                    <span class="meta-value" id="respondentTypeOther"></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Demographics</span>
                    <span class="meta-value" id="demographicsInfo">—</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Submitter</span>
                    <span class="meta-value" id="submitterInfo">Not provided</span>
                </div>
            </div>
        </div>

        <!-- FEEDBACK CONTENT -->
        <div class="detail-content">
            <h2>Feedback Content</h2>

            <div class="content-section" id="subjectSection" style="display:none;">
                <strong style="color: var(--text-3);">Subject:</strong>
                <div class="content-text" id="subjectText"></div>
            </div>

            <div class="content-section" id="feedbackSection" style="display:none;">
                <strong style="color: var(--text-3);">Message:</strong>
                <div class="content-text" id="feedbackText"></div>
            </div>

            <div class="content-section" id="suggestionsSection" style="display:none;">
                <strong style="color: var(--text-3);">Suggestions:</strong>
                <div class="content-text" id="suggestionsText"></div>
            </div>

            <div class="content-section" id="appreciationSection" style="display:none;">
                <strong style="color: var(--text-3);">Appreciation / Staff Recognition:</strong>
                <div class="content-text" id="appreciationText"></div>
            </div>

            <div class="content-section" id="ratingsSection" style="display:none;">
                <strong style="color: var(--text-3); display: block; margin-bottom: 12px;">Ratings:</strong>
                <div class="ratings-grid" id="ratingsGrid"></div>
            </div>
        </div>

        <!-- STATUS HISTORY -->
        <div class="status-history">
            <h2>Status History (Audit Trail)</h2>
            <div class="history-timeline" id="historyTimeline">
                <p style="color: var(--text-3); text-align: center; padding: 20px;">Loading history...</p>
            </div>
        </div>

        <!-- ACTIONS -->
        <div class="action-buttons">
            <button class="btn btn-secondary btn-sm" id="backBtn"><i class="fas fa-arrow-left"></i> Back</button>
            <button class="btn btn-primary btn-sm" id="printBtn"><i class="fas fa-print"></i> Print</button>
            <button class="btn btn-primary btn-sm" id="exportPdfBtn"><i class="fas fa-file-pdf"></i> Export PDF</button>
            <div style="flex: 1;"></div>
            <div id="statusActions"></div>
        </div>
    </div>

    <div class="print-area" id="printArea"></div>

    <script>
        // Parse URL parameters
        const params = new URLSearchParams(window.location.search);
        const referenceId = params.get('ref');

        if (!referenceId) {
            document.body.innerHTML = '<div style="padding: 40px; text-align: center; color: #999;"><i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 20px;"></i><p>No feedback reference provided.</p></div>';
        }

        // Respondent-type label for the 4 current categories from the public
        // form (see form/feedback.php's #resp_type whitelist). Records
        // without a recognized respondentType (legacy pre-respondent_type
        // rows) show as "Not Specified" rather than being guessed.
        const RESPONDENT_TYPE_LABELS = {
            'Pasyente/Kliyente': 'Patient / Client',
            'Kauban sa Pasyente': "Patient's Companion (Relative)",
            'Bisita': 'Business (visited for business purposes)',
        };
        function respondentTypeLabel(respondentType) {
            const raw = (respondentType || '').trim();
            if (!raw) return 'Not Specified';
            if (raw.toLowerCase() === 'other') return 'Other';
            if (['Patient / Client', "Patient's Companion (Relative)", 'Business (visited for business purposes)'].includes(raw)) return raw;
            return RESPONDENT_TYPE_LABELS[raw] || 'Other';
        }

        // Load feedback details
        async function loadFeedbackDetail() {
            try {
                // Fetch details from server API
                const resp = await fetch('/Feedback/api/feedback_detail.php?referenceId=' + encodeURIComponent(referenceId), { method: 'GET', headers: { 'Accept': 'application/json' } });
                const payload = await resp.json();
                if (!payload.success) {
                    document.body.innerHTML = '<div style="padding: 40px; text-align: center; color: #999;"><i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 20px;"></i><p>' + (payload.message || 'Feedback not found.') + '</p></div>';
                    return;
                }

                const entry = payload.data.entry;
                const audits = payload.data.audit || [];

                // Populate header
                document.getElementById('feedbackSubject').textContent = entry.subject || 'General Feedback';
                document.getElementById('feedbackRef').textContent = 'Ref: ' + escapeHtml(referenceId);
                document.getElementById('feedbackService').textContent = entry.service || '—';
                document.getElementById('submittedDate').textContent = formatDate(entry.timestamp || new Date().toISOString());
                document.getElementById('visitDate').textContent = entry.visitDate ? formatDateOnly(entry.visitDate) : '—';
                document.getElementById('feedbackType').textContent = capitalizeFirst(entry.feedbackType || 'suggestion');
                document.getElementById('respondentType').textContent = respondentTypeLabel(entry.respondentType);
                document.getElementById('respondentTypeOtherRow').hidden = !entry.respondentTypeOther;
                document.getElementById('respondentTypeOther').textContent = entry.respondentTypeOther || '';

                // Demographics — see database/migrations/007_add_demographic_fields.sql
                const demoParts = [
                    entry.age ? (entry.age + ' yrs old') : null,
                    entry.sex || null,
                    entry.genderPreference || null,
                    entry.religion || null,
                    entry.visitFrequency || null,
                    entry.educationLevel || null,
                ].filter(Boolean);
                document.getElementById('demographicsInfo').textContent = demoParts.length ? demoParts.join(' · ') : 'Not Specified';

                // Status badge
                const status = entry.status || 'pending';
                const badge = document.getElementById('statusBadge');
                badge.className = 'status-badge ' + status;
                badge.textContent = formatStatus(status);

                // Submitter info is only shown when present; no Anonymous mode is used.
                if (entry.submitterName || entry.submitterEmail) {
                    const contactParts = [entry.submitterName, entry.submitterEmail, entry.submitterContactNumber].filter(Boolean);
                    document.getElementById('submitterInfo').textContent = contactParts.join(' · ') || 'Guest';
                }

                // Content sections
                if (entry.subject) {
                    document.getElementById('subjectSection').style.display = 'block';
                    document.getElementById('subjectText').textContent = escapeHtml(entry.subject);
                }

                if (entry.feedback) {
                    document.getElementById('feedbackSection').style.display = 'block';
                    document.getElementById('feedbackText').textContent = escapeHtml(entry.feedback);
                }

                if (entry.suggestions) {
                    document.getElementById('suggestionsSection').style.display = 'block';
                    document.getElementById('suggestionsText').textContent = escapeHtml(entry.suggestions);
                }

                if (entry.appreciation) {
                    document.getElementById('appreciationSection').style.display = 'block';
                    document.getElementById('appreciationText').textContent = escapeHtml(entry.appreciation);
                }

                // Ratings — rendered from the server-resolved breakdown (readable
                // question labels, N/A shown as "N/A" rather than "N/A/5").
                const breakdown = Array.isArray(entry.ratingsBreakdown) ? entry.ratingsBreakdown : [];
                if (breakdown.length > 0) {
                    document.getElementById('ratingsSection').style.display = 'block';
                    const grid = document.getElementById('ratingsGrid');
                    grid.innerHTML = '';
                    breakdown.forEach(({ label, value, isNA }) => {
                        const item = document.createElement('div');
                        item.className = 'rating-item';
                        item.innerHTML = `
                            <div class="rating-item-label">${escapeHtml(label)}</div>
                            <div class="rating-item-value">${isNA ? 'N/A' : escapeHtml(String(value)) + '/5'}</div>
                        `;
                        grid.appendChild(item);
                    });
                }

                // Status actions
                const statusActions = document.getElementById('statusActions');
                statusActions.innerHTML = '';

                if (status === 'pending') {
                    statusActions.innerHTML += '<button class="btn btn-primary btn-sm" onclick="changeStatus(\'mark_review\')"><i class="fas fa-eye"></i> Mark Review</button>';
                    statusActions.innerHTML += '<button class="btn btn-primary btn-sm" onclick="changeStatus(\'mark_resolved\')"><i class="fas fa-check"></i> Mark Resolved</button>';
                } else if (status === 'in_review') {
                    statusActions.innerHTML += '<button class="btn btn-primary btn-sm" onclick="changeStatus(\'mark_resolved\')"><i class="fas fa-check"></i> Mark Resolved</button>';
                    statusActions.innerHTML += '<button class="btn btn-secondary btn-sm" onclick="changeStatus(\'reopen\')"><i class="fas fa-undo"></i> Reopen</button>';
                } else if (status === 'resolved') {
                    statusActions.innerHTML += '<button class="btn btn-secondary btn-sm" onclick="changeStatus(\'reopen\')"><i class="fas fa-undo"></i> Reopen</button>';
                    statusActions.innerHTML += '<button class="btn btn-danger btn-sm" onclick="changeStatus(\'archive\')"><i class="fas fa-archive"></i> Archive</button>';
                } else if (status === 'archived') {
                    statusActions.innerHTML += '<button class="btn btn-secondary btn-sm" onclick="changeStatus(\'unarchive\')"><i class="fas fa-undo"></i> Unarchive</button>';
                }

                // Render audit history returned by API
                const timeline = document.getElementById('historyTimeline');
                if (!audits || audits.length === 0) {
                    timeline.innerHTML = '<p style="color: var(--text-3); text-align: center; padding: 20px;">No history yet.</p>';
                } else {
                    timeline.innerHTML = '';
                    audits.forEach(item => {
                        const histItem = document.createElement('div');
                        histItem.className = 'history-item';
                        const canUndo = item.oldStatus && item.newStatus && (Date.now() - new Date(item.timestamp).getTime()) <= 30000;
                        histItem.innerHTML = `
                            <div class="history-time">${formatDate(item.timestamp)}</div>
                            <div class="history-action"><strong>${escapeHtml(item.action)}</strong>: ${escapeHtml(item.oldStatus || '—')} → ${escapeHtml(item.newStatus || '—')}</div>
                            <div class="history-user">By: ${escapeHtml(item.user || 'System')}</div>
                            ${item.notes ? '<div class="history-user" style="margin-top: 4px;"><em>' + escapeHtml(item.notes) + '</em></div>' : ''}
                            ${canUndo ? '<button class="btn btn-secondary btn-sm" style="margin-top:8px" onclick="revertStatus(\'' + escapeHtml(item.oldStatus) + '\')"><i class="fas fa-undo"></i> Undo</button>' : ''}
                        `;
                        timeline.appendChild(histItem);
                    });
                }

            } catch (error) {
                console.error('Error loading feedback:', error);
                document.body.innerHTML = '<div style="padding: 40px; text-align: center; color: red;"><i class="fas fa-exclamation" style="font-size: 48px; margin-bottom: 20px;"></i><p>Error loading feedback.</p></div>';
            }
        }

        async function revertStatus(status) {
            if (!status) return;
            const statusLabel = formatStatus(status);
            if (!(await AdminUI.confirm({
                title: 'Revert feedback status?',
                message: 'Revert this feedback to ' + statusLabel + '?',
                confirmLabel: 'Revert status'
            }))) return;
            try {
                const resp = await fetch('/Feedback/api/feedback_update_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                    body: JSON.stringify({ referenceId: referenceId, status: status })
                });
                const payload = await resp.json();
                if (!payload.success) throw new Error(payload.message || 'Unable to undo status.');
                AdminUI.toast('Status reverted successfully.', 'success');
                loadFeedbackDetail();
            } catch (error) {
                AdminUI.toast(error.message || 'Unable to undo status.', 'error');
            }
        }

        async function changeStatus(action) {
            const statusMap = {
                mark_review: 'in_review',
                mark_resolved: 'resolved',
                archive: 'archived',
                reopen: 'pending',
                unarchive: 'resolved',
            };
            const newStatus = statusMap[action];
            if (!newStatus) return;
            const statusLabel = formatStatus(newStatus);
            if (!(await AdminUI.confirm({
                title: 'Change feedback status?',
                message: 'Change status to ' + statusLabel + '?',
                confirmLabel: 'Change status'
            }))) return;

            try {
                const resp = await fetch('/Feedback/api/feedback_update_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                    body: JSON.stringify({ referenceId: referenceId, status: newStatus })
                });
                const payload = await resp.json();
                if (!payload.success) throw new Error(payload.message || 'Unable to update status.');
                AdminUI.toast('Status updated successfully.', 'success');
                loadFeedbackDetail();
            } catch (error) {
                AdminUI.toast(error.message || 'Unable to update status.', 'error');
            }
        }

        document.getElementById('backBtn').onclick = () => window.history.back();
        document.getElementById('printBtn').onclick = () => window.print();
        document.getElementById('exportPdfBtn').onclick = () => AdminUI.toast('PDF export requires server-side implementation.', 'info');

        // Utilities
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatDate(dateStr) {
            const date = new Date(dateStr);
            return date.toLocaleString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        // Visit Date only ever carries a date, no time — a plain date-only
        // format avoids showing a fake "12:00 AM" that was never recorded.
        function formatDateOnly(dateStr) {
            const date = new Date(dateStr + 'T00:00:00');
            if (isNaN(date.getTime())) return '—';
            return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
        }

        function capitalizeFirst(str) {
            return str.charAt(0).toUpperCase() + str.slice(1);
        }

        function formatStatus(status) {
            return status.replace('_', ' ').split(' ').map(capitalizeFirst).join(' ');
        }

        // Load on init
        loadFeedbackDetail();
    </script>
</body>
</html>
