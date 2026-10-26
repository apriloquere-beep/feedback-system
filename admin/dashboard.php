<?php
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
<title>Admin Dashboard — Smart Feedback Management System</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<script src="/Feedback/assets/js/qrcode.min.js"></script>
<link rel="stylesheet" href="../css/dashboard.css?v=<?php echo @filemtime(__DIR__ . '/../css/dashboard.css') ?: time(); ?>">
<script src="/Feedback/assets/js/browser-deterrent.js" defer></script>
<script src="/Feedback/assets/js/admin-ui.js" defer></script>
</head>
<body>

<div id="loadingSpinner" class="loading-spinner"><div class="spinner-core"></div></div>

<div class="admin-print-period-overlay" id="adminPrintPeriodOverlay" hidden>
  <section class="admin-print-period-dialog" role="dialog" aria-modal="true" aria-labelledby="adminPrintPeriodTitle">
    <h2 id="adminPrintPeriodTitle">Choose report period</h2>
    <p>All report figures will use this date range and the current non-date filters.</p>
    <label class="admin-print-period-label" for="adminPrintPeriod">Period</label>
    <select id="adminPrintPeriod" class="form-select" aria-controls="adminPrintSpecificDate adminPrintDateRange">
      <option value="all" selected>All time</option>
      <option value="today">Today</option>
      <option value="7">Last 7 days</option>
      <option value="30">Last 30 days</option>
      <option value="specific">Specific date</option>
      <option value="custom">Custom date range</option>
    </select>
    <div class="admin-print-date-fields" id="adminPrintSpecificDate" hidden>
      <label for="adminPrintDate">Date</label>
      <input class="form-input" id="adminPrintDate" type="date">
    </div>
    <div class="admin-print-date-fields admin-print-date-range" id="adminPrintDateRange" hidden>
      <div>
        <label for="adminPrintStartDate">Start Date</label>
        <input class="form-input" id="adminPrintStartDate" type="date">
      </div>
      <div>
        <label for="adminPrintEndDate">End Date</label>
        <input class="form-input" id="adminPrintEndDate" type="date">
      </div>
    </div>
    <p class="admin-print-period-error" id="adminPrintPeriodError" role="alert" tabindex="-1" hidden></p>
    <div class="admin-print-period-actions">
      <button type="button" class="btn-secondary" data-print-period-cancel>Cancel</button>
      <button type="button" class="btn-primary" data-print-period-confirm>Continue to print</button>
    </div>
  </section>
</div>
<div id="adminPrintableReport" aria-hidden="true"></div>

<!-- ═══ MODAL ═══ -->
<div class="modal-overlay" id="modalOverlay" onclick="if(event.target===this)closeModal()">
  <div class="modal" id="modal">
    <div class="modal-hd">
      <h3 id="modalTitle">Add Service</h3>
      <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="modalBody"></div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal()">Cancel</button>
      <button class="btn-primary" id="modalSave" onclick="handleModalSave()"><i class="fas fa-save"></i> Save</button>
      <button type="button" class="btn-primary" id="modalDelete" style="background:#dc2626;display:none" onclick="confirmDeleteStaffRole()"><i class="fas fa-trash"></i> Delete</button>
    </div>
  </div>
</div>

<div class="settings-confirm-overlay" id="settingsConfirmOverlay" role="dialog" aria-modal="true" aria-labelledby="settingsConfirmTitle" aria-describedby="settingsConfirmMessage" hidden>
  <div class="settings-confirm-modal">
    <div class="settings-confirm-icon" aria-hidden="true"><i class="fas fa-floppy-disk"></i></div>
    <div class="settings-confirm-copy">
      <h3 id="settingsConfirmTitle">Save setting?</h3>
      <p id="settingsConfirmMessage">Are you sure you want to save change?</p>
      <div class="settings-modal-feedback" id="settingsModalFeedback" role="alert" aria-live="assertive"></div>
    </div>
    <div class="settings-confirm-actions">
      <button class="btn-secondary" type="button" onclick="closeSettingsConfirmation()">Cancel</button>
      <button class="btn-primary" type="button" id="confirmSettingsSave" onclick="confirmSaveSettings()"><i class="fas fa-save"></i> Save Change</button>
    </div>
  </div>
</div>

<div class="settings-confirm-overlay" id="logoutConfirmOverlay" role="dialog" aria-modal="true" aria-labelledby="logoutConfirmTitle" aria-describedby="logoutConfirmMessage" hidden>
  <div class="settings-confirm-modal">
    <div class="settings-confirm-icon" aria-hidden="true"><i class="fas fa-right-from-bracket"></i></div>
    <div class="settings-confirm-copy">
      <h3 id="logoutConfirmTitle">Confirm Logout</h3>
      <p id="logoutConfirmMessage">Are you sure you want to logout?</p>
    </div>
    <div class="settings-confirm-actions">
      <button class="btn-secondary" type="button" onclick="closeLogoutConfirmation()">Cancel</button>
      <button class="btn-primary" type="button" onclick="confirmLogout()"><i class="fas fa-right-from-bracket"></i> Logout</button>
    </div>
  </div>
</div>

<div id="shell">

<!-- ═══ SIDEBAR ═══ -->
<nav id="sidebar">
  <div class="sb-brand">
    <div class="sb-logo"><i class="fas fa-heartbeat"></i></div>
    <div class="sb-brand-text">
      <div class="sb-name">SFMS</div>
      <div class="sb-sub">Admin Dashboard</div>
    </div>
  </div>

  <div class="sb-section">
    <div class="sb-section-label">Main</div>
    <a class="sb-link active" data-page="dashboard" onclick="nav(this)">
      <div class="si"><i class="fas fa-th-large"></i></div><span class="sl">Dashboard</span>
    </a>
  </div>

  <div class="sb-section">
    <div class="sb-section-label">Feedback</div>
    <a class="sb-link" data-page="all-feedbacks" onclick="nav(this)" role="button" aria-expanded="false" aria-controls="allFeedbackSubmenu">
      <div class="si"><i class="fas fa-comment-dots"></i></div><span class="sl">All Feedback</span>
      <span class="allfb-chevron" aria-hidden="true"><i class="fas fa-chevron-down"></i></span>
      <span class="sb-badge hide" id="badge-all">0</span>
    </a>
    <div class="sb-submenu" id="allFeedbackSubmenu">
      <a class="sb-link sub" data-page="respondent-patient" onclick="nav(this)">
        <div class="sub-dot"></div><span class="sl">Patient / Client</span>
      </a>
      <a class="sb-link sub" data-page="respondent-companion" onclick="nav(this)">
        <div class="sub-dot"></div><span class="sl">Patient's Companion (Relative)</span>
      </a>
      <a class="sb-link sub" data-page="respondent-business" onclick="nav(this)">
        <div class="sub-dot"></div><span class="sl">Business (visited for business purposes)</span>
      </a>
      <a class="sb-link sub" data-page="respondent-other" onclick="nav(this)">
        <div class="sub-dot"></div><span class="sl">Other</span>
      </a>
      <a class="sb-link sub" data-page="pending" onclick="nav(this)">
        <div class="sub-dot"></div><span class="sl">Pending Review</span>
        <span class="sb-badge hide" id="badge-pending">0</span>
      </a>
      <a class="sb-link sub" data-page="resolved" onclick="nav(this)">
        <div class="sub-dot"></div><span class="sl">Resolved</span>
      </a>
    </div>
  </div>

  <div class="sb-section">
    <div class="sb-section-label">Analytics</div>
    <a class="sb-link" data-page="reports" onclick="nav(this)">
      <div class="si"><i class="fas fa-chart-line"></i></div><span class="sl">Report</span>
    </a>
    <a class="sb-link" data-page="services" onclick="nav(this)">
      <div class="si"><i class="fas fa-hand-holding-medical"></i></div><span class="sl">Service</span>
    </a>
    <a class="sb-link" data-page="staff" onclick="nav(this)">
      <div class="si"><i class="fas fa-user-nurse"></i></div><span class="sl">Staff Rating</span>
    </a>
  </div>

  <div class="sb-section">
    <div class="sb-section-label">Administration</div>


    <!-- Sidebar notification moved to topnav; keep a hidden anchor so nav() can target notifications page -->
    <a class="sb-link" data-page="notifications" id="notifications_link" style="display:none"></a>
    <a class="sb-link" data-page="audit" onclick="nav(this)">
      <div class="si"><i class="fas fa-clipboard-list"></i></div><span class="sl">Audit Log</span>
    </a>
    <a class="sb-link" data-page="settings" onclick="nav(this)">
      <div class="si"><i class="fas fa-cog"></i></div><span class="sl">Setting</span>
    </a>
  </div>

  <div class="sb-spacer"></div>
  <div class="sb-section sb-footer">
    <form method="post" action="logout.php" id="logoutForm" style="margin:0" onsubmit="openLogoutConfirmation(event)">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(sfmsCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit" class="sb-link logout" style="width:100%;border:0;background:transparent;text-align:left">
      <div class="si"><i class="fas fa-sign-out-alt"></i></div><span class="sl">Logout</span>
    </button></form>
  </div>
</nav>

<!-- ═══ CONTENT ═══ -->
<div id="content">
  <nav class="topnav">
    <div class="topnav-title" id="pageTitle">Dashboard</div>
    <div class="search-wrap"><i class="fas fa-search"></i><input type="text" placeholder="Search feedback..." id="globalSearch" oninput="handleSearch(this.value)"></div>
    <div class="icon-btn" onclick="openNotificationPage()" role="button" tabindex="0" onkeydown="if(event.key==='Enter' || event.key===' ') openNotificationPage()" style="position:relative">
      <i class="fas fa-bell"></i>
      <div class="notif-dot hide" id="notifDot"></div>
      <span class="bell-count hide" id="bellCount"></span>
    </div>
    <div class="user-chip" id="userChip">
      <div class="uc-av">AD</div>
      <div>
        <div class="uc-name" id="ucName">Admin</div>
        <div class="uc-role">Administrator</div>
      </div>
    </div>
  </nav>

  <div class="scroll-area" id="scrollArea">

    <!-- ═══════════ DASHBOARD PAGE ═══════════ -->
    <div class="page active" id="page-dashboard">

      <div class="welcome">
        <div>
          <h2>Welcome back, Admin!</h2>
          <p>Smart operations overview — SFMS</p>
        </div>
        <div class="welcome-actions">
          <button type="button" class="btn-primary admin-print-button" data-admin-print><i class="fas fa-print" aria-hidden="true"></i><span>Print</span></button>
          <div class="welcome-date">
            <i class="far fa-calendar-alt"></i>
            <span id="currentDate"></span>
          </div>
        </div>
      </div>
      <!-- KPI Cards -->
      <div class="print-heading-row dashboard-print-content">
        <div class="sec-head"><div class="sec-pre">Overview</div><h3>Key Performance Indicator</h3></div>
      </div>
      <div class="kpi-grid dashboard-print-content">
        <div class="kpi-card kc-teal">
          <div class="kpi-top">
            <div class="kpi-icon ic-teal"><i class="fas fa-comments"></i></div>
            <div class="kpi-status ks-neutral" id="statusTotal">— New</div>
          </div>
          <div class="kpi-val" id="valTotal">0</div>
          <div class="kpi-lbl">Total Feedback</div>
          <div class="kpi-helper" id="helperTotal">No submission yet</div>
          <div class="mini-spark" id="sparkTotal" aria-hidden="true"></div>
        </div>
        <div class="kpi-card kc-amber">
          <div class="kpi-top">
            <div class="kpi-icon ic-amber"><i class="far fa-clock"></i></div>
            <div class="kpi-status ks-warn" id="statusPending">Review</div>
          </div>
          <div class="kpi-val" id="valPending">0</div>
          <div class="kpi-lbl">Pending Review</div>
          <div class="kpi-helper" id="helperPending">All caught up</div>
          <div class="kpi-trend t-neu" id="tPending">— No queue</div>
        </div>
        <div class="kpi-card kc-green">
          <div class="kpi-top">
            <div class="kpi-icon ic-green"><i class="far fa-check-circle"></i></div>
            <div class="kpi-status ks-ok" id="statusResolved">Good</div>
          </div>
          <div class="kpi-val" id="valResolved">0</div>
          <div class="kpi-lbl">Resolved</div>
          <div class="kpi-helper" id="helperResolved">Resolution rate</div>
          <div class="kpi-trend t-neu" id="tResolved">— 0% rate</div>
        </div>
        <div class="kpi-card kc-blue">
          <div class="kpi-top">
            <div class="kpi-icon ic-blue"><i class="fas fa-heart"></i></div>
            <div class="kpi-status ks-info" id="statusSat">Satisfaction</div>
          </div>
          <div class="kpi-val" id="valSat">—%</div>
          <div class="kpi-lbl">Overall Satisfaction</div>
          <div class="kpi-helper" id="helperSat">Positive ÷ Total feedback</div>
          <div class="kpi-trend t-neu" id="tSat">— Benchmark: 85%</div>
        </div>
        <div class="kpi-card kc-red">
          <div class="kpi-top">
            <div class="kpi-icon ic-red"><i class="fas fa-chart-pie"></i></div>
            <div class="kpi-status ks-neutral" id="statusResRate">Rate</div>
          </div>
          <div class="kpi-val" id="valResRate">—%</div>
          <div class="kpi-lbl">Resolution Rate</div>
          <div class="kpi-helper" id="helperResRate">Resolved ÷ Total feedback</div>
          <div class="kpi-trend t-neu" id="tResRate">— Target: 80%</div>
        </div>
      </div>

      <!-- Charts Row -->
      <div class="sec-head dashboard-print-content"><div class="sec-pre">Analytics</div><h3>Trend & Distribution</h3></div>
      <div class="charts-row dashboard-print-content">
        <div class="chart-card service-chart-card">
          <div class="chart-hd service-chart-header">
            <h4><i class="fas fa-chart-column" aria-hidden="true"></i> Feedback by Service</h4>
            <a class="service-view-all" href="#allFeedbackSubmenu" onclick="event.preventDefault(); nav(document.querySelector('[data-page=all-feedbacks]'));">Browse Feedback <span aria-hidden="true">→</span></a>
          </div>
          <div class="service-chart-wrap">
            <canvas id="serviceChart" role="img" aria-label="Feedback by service">Feedback by service chart.</canvas>
            <div class="service-empty-state" id="serviceEmptyState" hidden>No service data available yet</div>
          </div>
        </div>
        <div class="chart-card sentiment-chart-card">
          <div class="chart-hd sentiment-chart-header">
            <h4><i class="fas fa-chart-pie" aria-hidden="true"></i> Sentiment Analysis</h4>
          </div>
          <div class="sentiment-chart-body">
            <div class="sentiment-canvas-wrap">
              <canvas id="sentimentChart" role="img" aria-label="Sentiment analysis">Sentiment analysis chart.</canvas>
              <div class="sentiment-empty-state" id="sentimentEmptyState" hidden>No sentiment data available yet</div>
            </div>
            <div class="sentiment-legend" id="sentimentLegend" aria-label="Sentiment counts"></div>
          </div>
        </div>
        <div class="chart-card trend-chart-card">
          <div class="chart-hd trend-chart-header">
            <h4><i class="fas fa-chart-line" aria-hidden="true"></i> Feedback Trend</h4>
            <a class="trend-view-all" href="#allFeedbackSubmenu" onclick="event.preventDefault(); nav(document.querySelector('[data-page=all-feedbacks]'));">Browse Feedback <span aria-hidden="true">→</span></a>
          </div>
          <div class="trend-chart-wrap">
            <canvas id="trendChart" role="img" aria-label="Feedback trends over time">Feedback trend chart.</canvas>
            <div class="trend-empty-state" id="trendEmptyState" hidden>No feedback data available yet</div>
          </div>
        </div>
      </div>

      <!-- Key Insights -->
      <div class="sec-head"><div class="sec-pre">Intelligence</div><h3>Key Insights</h3></div>
      <div class="insight-grid">
        <div class="ins-card">
          <div class="ins-card-header">
            <div class="ins-label">Highest-Rated Service</div>
            <div class="ins-badge" style="background:var(--green-lt);color:#14532d"><i class="fas fa-trophy" style="font-size:9px"></i> Top</div>
          </div>
          <div class="ins-val" id="insBestDept">—</div>
          <div class="ins-meta" id="insBestDeptMeta">Not enough data yet</div>
          <div class="ins-arrow"><i class="fas fa-medal"></i></div>
        </div>
        <div class="ins-card">
          <div class="ins-card-header">
            <div class="ins-label">Most Submitted Service</div>
            <div class="ins-badge" style="background:var(--teal-lt);color:var(--teal-dk)"><i class="fas fa-hand-holding-medical" style="font-size:9px"></i> Top</div>
          </div>
          <div class="ins-val" id="insTopService">—</div>
          <div class="ins-meta" id="insTopServiceMeta">Service with the most feedback submission</div>
          <div class="ins-arrow"><i class="fas fa-prescription-bottle-medical"></i></div>
        </div>
        <div class="ins-card">
          <div class="ins-card-header">
            <div class="ins-label">Needs Attention</div>
            <div class="ins-badge" style="background:var(--red-lt);color:#7f1d1d"><i class="fas fa-exclamation-triangle" style="font-size:9px"></i> Alert</div>
          </div>
          <div class="ins-val" id="insLowArea">—</div>
          <div class="ins-meta" id="insLowAreaMeta">Service rated below 3.0</div>
          <div class="ins-arrow"><i class="fas fa-chart-line" style="transform:scaleY(-1)"></i></div>
        </div>
        <div class="ins-card">
          <div class="ins-card-header">
            <div class="ins-label">30-Day Trend</div>
            <div class="ins-badge" style="background:var(--blue-lt);color:#1e3a8a"><i class="fas fa-chart-line" style="font-size:9px"></i> Trend</div>
          </div>
          <div class="ins-val" id="insTrend">—</div>
          <div class="ins-meta" id="insTrendMeta">Current vs previous 30 days</div>
          <div class="ins-arrow"><i class="fas fa-arrow-trend-up"></i></div>
        </div>
      </div>

      <!-- Recent Feedback Table -->
      <div class="tbl-wrap">
        <div class="tbl-head">
          <h4>Recent Feedback</h4>
          <a class="service-view-all" href="#allFeedbackSubmenu" onclick="event.preventDefault(); nav(document.querySelector('[data-page=all-feedbacks]'))">Browse Feedback <span aria-hidden="true">→</span></a>
        </div>
        <table class="dtbl recent-feedback-table">
          <thead>
            <tr>
              <th>DATE</th><th>TYPE</th><th>SERVICE</th>
              <th>SENTIMENT</th><th>RATING</th><th>STATUS</th><th>ACTION</th>
            </tr>
          </thead>
          <tbody id="recentFbBody"></tbody>
        </table>
      </div>

      <!-- Bottom Grid -->
      <div class="sec-head"><div class="sec-pre">Workbench</div><h3>Activity & Queue</h3></div>
      <div class="bottom-grid">
        <div class="b-card">
          <div class="b-hd"><h4>Recent Comment</h4><i class="fas fa-comments"></i></div>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px">
              <div></div>
              <div style="display:flex;align-items:center;gap:8px"><input class="form-input" style="width:260px;padding:6px 10px;font-size:12px" placeholder="Search recent experience..." id="notifSearch" oninput="renderNotifs(loadEntries())"></div>
            </div>
            <div id="dashboardNotifList"><div class="empty-state"><i class="fas fa-bell"></i><p>No notification yet.</p></div></div>
        </div>
        <div class="b-card">
          <div class="b-hd"><h4>Top Service</h4><i class="fas fa-hand-holding-medical"></i></div>
          <div id="topDeptList"><div class="empty-state"><i class="fas fa-hand-holding-medical"></i><p>No data yet.</p></div></div>
        </div>
        <div class="b-card full">
          <div class="b-hd"><h4>Pending Queue</h4><i class="fas fa-clipboard-check"></i></div>
          <div id="queueList"><div class="empty-state"><i class="fas fa-check-circle"></i><p>Queue empty — all clear.</p></div></div>
        </div>
      </div>

    </div><!-- /dashboard -->

    <!-- ═══════════ PATIENT / CLIENT FEEDBACK ═══════════ -->
    <div class="page feedback-page" id="page-respondent-patient">
      <div class="tbl-wrap">
        <div class="tbl-head">
          <div style="display:flex;align-items:center;justify-content:space-between;width:100%">
            <div>
              <h4 style="margin:0">Patient / Client</h4>
              <div id="respPatientFbSummary"></div>
            </div>
          </div>
          <div class="feedback-filter-bar" data-feedback-filters="patient"></div>
        </div>
        <table class="dtbl">
          <thead><tr><th>Date</th><th>Service</th><th>Sentiment</th><th>Rating</th><th>Status</th><th>Action</th></tr></thead>
          <tbody id="respPatientFbBody"></tbody>
        </table>
      </div>
    </div>

    <!-- ═══════════ PATIENT'S COMPANION (RELATIVE) FEEDBACK ═══════════ -->
    <div class="page feedback-page" id="page-respondent-companion">
      <div class="tbl-wrap">
        <div class="tbl-head">
          <div style="display:flex;align-items:center;justify-content:space-between;width:100%">
            <div>
              <h4 style="margin:0">Patient's Companion (Relative)</h4>
              <div id="respCompanionFbSummary"></div>
            </div>
          </div>
          <div class="feedback-filter-bar" data-feedback-filters="companion"></div>
        </div>
        <table class="dtbl">
          <thead><tr><th>Date</th><th>Service</th><th>Sentiment</th><th>Rating</th><th>Status</th><th>Action</th></tr></thead>
          <tbody id="respCompanionFbBody"></tbody>
        </table>
      </div>
    </div>

    <!-- ═══════════ BUSINESS FEEDBACK ═══════════ -->
    <div class="page feedback-page" id="page-respondent-business">
      <div class="tbl-wrap">
        <div class="tbl-head">
          <div style="display:flex;align-items:center;justify-content:space-between;width:100%">
            <div>
              <h4 style="margin:0">Business (visited for business purposes)</h4>
              <div id="respBusinessFbSummary"></div>
            </div>
          </div>
          <div class="feedback-filter-bar" data-feedback-filters="business"></div>
        </div>
        <table class="dtbl">
          <thead><tr><th>Date</th><th>Service</th><th>Sentiment</th><th>Rating</th><th>Status</th><th>Action</th></tr></thead>
          <tbody id="respBusinessFbBody"></tbody>
        </table>
      </div>
    </div>

    <!-- ═══════════ OTHER FEEDBACK ═══════════ -->
    <div class="page feedback-page" id="page-respondent-other">
      <div class="tbl-wrap">
        <div class="tbl-head">
          <div style="display:flex;align-items:center;justify-content:space-between;width:100%">
            <div>
              <h4 style="margin:0">Other</h4>
              <div id="respOtherFbSummary"></div>
            </div>
          </div>
          <div class="feedback-filter-bar" data-feedback-filters="other"></div>
        </div>
        <table class="dtbl">
          <thead><tr><th>Date</th><th>Service</th><th>Sentiment</th><th>Rating</th><th>Status</th><th>Action</th></tr></thead>
          <tbody id="respOtherFbBody"></tbody>
        </table>
      </div>
    </div>

    <!-- ═══════════ PENDING ═══════════ -->
    <div class="page feedback-page" id="page-pending">
      <div class="tbl-wrap pending-wrap">
        <div class="pending-head">
          <div>
            <div class="sec-pre">Review Queue</div>
            <h4>Pending Review</h4>
            <p>Track open feedback in a card grid with quick actions for resolution.</p>
          </div>
          <div class="feedback-filter-bar" data-feedback-filters="pending"></div>
        </div>
        <div class="pending-grid" id="pendingList"><div class="empty-state"><i class="far fa-clock"></i><p>No pending feedback.</p></div></div>
      </div>
    </div>

    <!-- ═══════════ RESOLVED ═══════════ -->
    <div class="page feedback-page" id="page-resolved">
      <div class="tbl-wrap pending-wrap">
        <div class="pending-head">
          <div>
            <div class="sec-pre">Closed Queue</div>
            <h4>Resolved</h4>
            <p>Browse closed feedback in the same compact grid format for quick follow-up and review.</p>
          </div>
          <div class="feedback-filter-bar" data-feedback-filters="resolved"></div>
        </div>
        <div class="pending-grid" id="resolvedList"><div class="empty-state"><i class="fas fa-check-circle"></i><p>No resolved feedback yet.</p></div></div>
      </div>
    </div>

    <!-- ═══════════ REPORT ═══════════ -->
    <div class="page" id="page-reports">
      <div class="print-heading-row">
        <div class="sec-head"><div class="sec-pre">Analytic</div><h3>Reports</h3></div>
        <button type="button" class="btn-primary admin-print-button" data-admin-print><i class="fas fa-print" aria-hidden="true"></i><span>Print Report</span></button>
      </div>
      <div class="reports-kpi-row">
        <div class="report-sidebar">
          <div class="report-panel">
            <div class="report-status report-status-good" id="reportSatStatus">Good</div>
            <div class="report-value" id="reportSatValue">—%</div>
            <div class="report-label">Overall Satisfaction</div>
            <div class="report-detail" id="reportSatDetail">Satisfied: 0 of 0 rated feedback</div>
            <div class="report-meta" id="reportSatMeta">Benchmark: 80% | Current: —</div>
          </div>
          <div class="report-panel">
            <div class="report-status report-status-low" id="reportResStatus">Low</div>
            <div class="report-value" id="reportResValue">—%</div>
            <div class="report-label">Resolution Rate</div>
            <div class="report-detail" id="reportResDetail">Resolved: 0 of 0</div>
            <div class="report-meta" id="reportResMeta">Target: 80% | Current: —</div>
          </div>
        </div>
      </div>
      <div class="reports-top-charts">
        <div class="chart-card report-chart-card rating-distribution-card">
          <div class="chart-hd"><h4>Rating Distribution</h4></div>
          <div class="report-chart-area">
            <canvas id="rptRatingDistribution" role="img" aria-label="Number of feedback responses for ratings from one to five stars">Rating distribution chart.</canvas>
            <div class="trend-empty-state" id="ratingDistributionEmpty" hidden>No rated feedback available for the selected filters</div>
          </div>
        </div>
        <div class="chart-card report-chart-card rating-trend-card">
          <div class="chart-hd">
            <h4>Average Rating Over Time</h4>
            <select class="chart-sel" id="ratingTrendSel" onchange="renderRatingTrendChart()">
              <option value="daily">Day</option>
              <option value="weekly">Week</option>
              <option value="monthly" selected>Month</option>
            </select>
          </div>
          <div class="report-chart-area">
            <canvas id="rptRatingTrend" role="img" aria-label="Average feedback rating over time">Average rating trend chart.</canvas>
            <div class="trend-empty-state" id="ratingTrendEmpty" hidden>No rated feedback available for the selected filters</div>
          </div>
        </div>
        <div class="chart-card report-chart-card">
          <div class="chart-hd"><h4>Sentiment Distribution</h4></div>
          <div class="report-chart-area">
            <canvas id="rptSentiment" role="img" aria-label="Sentiment distribution pie chart">Sentiment chart.</canvas>
            <div class="trend-empty-state" id="sentimentReportEmptyState" hidden>No data recorded for this period.</div>
          </div>
        </div>
      </div>
      <div class="reports-trend-section">
        <div class="chart-card report-chart-card">
          <div class="chart-hd">
            <h4>Sentiment Trend Over Time</h4>
            <select class="chart-sel" id="sentTrendSel" onchange="renderSentimentTrendChart()">
              <option value="daily">Daily</option>
              <option value="weekly">Weekly</option>
              <option value="monthly" selected>Monthly</option>
            </select>
          </div>
          <div class="report-chart-area">
            <canvas id="rptSentimentTrend" role="img" aria-label="Sentiment trend over time">Sentiment trend chart.</canvas>
            <div class="trend-empty-state" id="sentimentTrendEmptyState" hidden>No data recorded for this period.</div>
          </div>
        </div>
      </div>
      <div class="chart-card report-chart-card sentiment-service-card">
        <div class="chart-hd"><h4>Sentiment by Service</h4></div>
        <div class="report-chart-area">
          <canvas id="rptSentimentByService" role="img" aria-label="Feedback sentiment counts grouped by service">Sentiment by service chart.</canvas>
          <div class="trend-empty-state" id="sentimentByServiceEmpty" hidden>No feedback available for the selected filters</div>
        </div>
      </div>
      <div class="reports-service-summary g-card">
        <div class="g-card-hd"><h4>Service Comparison</h4></div>
        <table class="dtbl">
          <thead><tr><th>#</th><th>Service</th><th>Feedback</th><th>Rated</th><th>Avg Rating</th><th>Satisfaction</th><th>Status</th></tr></thead>
          <tbody id="servicePerfBody"></tbody>
        </table>
      </div>
      <div class="reports-summary-row">
        <div class="report-panel report-summary-panel">
          <div class="report-status report-status-good">Summary</div>
          <div class="report-label">Automated keyword summary — Comment and Staff</div>
          <div class="report-detail" id="experienceSummaryText">No eligible SQD rating or written response yet.</div>
          <div class="report-meta" id="experienceSummaryMeta">SQD rating and sentiment analysis are reported separately from each written field.</div>
        </div>
        <div class="report-panel report-summary-panel">
          <div class="report-status report-status-good">Summary</div>
          <div class="report-label">Automated keyword summary — Suggestion</div>
          <div class="report-detail" id="suggestionsSummaryText">No written Suggestion yet.</div>
          <div class="report-meta" id="suggestionsSummaryMeta">Actual written Suggestion responses; no trends are inferred.</div>
        </div>
      </div>
    </div>

    <!-- ═══════════ DEPARTMENTS ═══════════ -->
    <!-- ═══════════ SERVICE ═══════════ -->
    <div class="page" id="page-services">
      <div class="sec-head"><div class="sec-pre">Administration</div><h3>Service</h3></div>
      <div class="g-card">
        <div class="g-card-hd service-management-header">
            <div class="service-management-layout">
              <div class="service-management-title">
                <div class="service-management-icon" aria-hidden="true"><i class="fas fa-hand-holding-medical"></i></div>
                <div><div class="service-management-kicker">Facility catalog</div><h4>Service Management</h4></div>
              </div>
              <div class="service-management-controls">
                <label class="service-search-control" for="serviceSearch"><i class="fas fa-search" aria-hidden="true"></i><span class="sr-only">Search services</span><input class="form-input" type="search" placeholder="Search services" id="serviceSearch" oninput="renderServices()"></label>
                <select class="form-select service-status-filter" id="serviceStatusFilter" aria-label="Filter services by status" onchange="renderServices()"><option value="active" selected>Active</option><option value="inactive">Inactive</option><option value="all">All statuses</option></select>
                <button class="btn-primary service-add-button" onclick="openServiceModal()"><i class="fas fa-plus"></i><span>Add Service</span></button>
              </div>
              <div class="tbl-filters service-filters">
                <button class="f-chip active" data-filter="all" onclick="filterServices('all',this)">All</button>
                <button class="f-chip" data-filter="high" onclick="filterServices('high',this)">High &ge;4.0</button>
                <button class="f-chip" data-filter="mid" onclick="filterServices('mid',this)">Moderate 3.0&ndash;3.9</button>
                <button class="f-chip" data-filter="low" onclick="filterServices('low',this)">Needs Attention &lt;3.0</button>
                <div class="info-tip">
                  <button type="button" class="info-tip-btn" onclick="toggleInfoTip(event,this)" aria-label="Rating information" title="Rating information" aria-haspopup="true" aria-expanded="false">?</button>
                  <div class="info-tip-pop" role="tooltip" style="display:none;position:fixed"><strong>Rating scale.</strong> High is 4.0 or above, Moderate is 3.0 to 3.9, and Needs Attention is below 3.0.</div>
                </div>
              </div>
            </div>
        </div>
        <div class="service-table-wrap">
          <table class="dtbl service-management-table">
            <thead><tr><th>Service Name</th><th style="text-align:center">Feedback</th><th style="text-align:center">Avg Rating</th><th>Action</th></tr></thead>
            <tbody id="servicesTableBody"></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ═══════════ STAFF RATING ═══════════ -->
    <div class="page" id="page-staff">
      <div class="sec-head"><div class="sec-pre">Analytics</div><h3>Staff Rating</h3></div>
      <div class="g-card staff-rating-card">
        <div class="g-card-hd staff-rating-header">
          <div class="staff-rating-layout">
            <div class="staff-rating-title">
              <div class="staff-rating-icon" aria-hidden="true"><i class="fas fa-user-doctor"></i></div>
              <div>
                <div class="staff-rating-kicker">Client feedback</div>
                <h4>Staff Role Rating</h4>
                <p>Staff rating overview based on client feedback, by staff role.</p>
              </div>
            </div>
            <div class="staff-rating-controls">
              <label class="staff-search-control" for="staffRoleSearch"><i class="fas fa-search" aria-hidden="true"></i><span class="sr-only">Search staff roles</span><input class="form-input" type="search" id="staffRoleSearch" placeholder="Search staff roles..." aria-label="Search staff roles" oninput="searchStaffRatings(this.value)"></label>
              <button type="button" class="btn-primary staff-add-button" onclick="openAddStaffModal()" title="Add a new staff role"><i class="fas fa-plus"></i><span>Add Staff</span></button>
            </div>
            <div class="tbl-filters staff-filters">
              <button class="f-chip active" data-filter="all" onclick="filterStaff('all',this)">All</button>
              <button class="f-chip" data-filter="high" onclick="filterStaff('high',this)">High &ge;4.0</button>
              <button class="f-chip" data-filter="mid" onclick="filterStaff('mid',this)">Moderate 3.0&ndash;3.9</button>
              <button class="f-chip" data-filter="low" onclick="filterStaff('low',this)">Needs Attention &lt;3.0</button>
              <div class="info-tip">
                <button type="button" class="info-tip-btn" onclick="toggleInfoTip(event,this)" aria-label="N/A information" title="N/A information" aria-haspopup="true" aria-expanded="false">?</button>
                <div class="info-tip-pop" role="tooltip" style="display:none;position:fixed"><strong>N/A = Not Applicable.</strong> The respondent did not encounter, communicate with, or receive service from this staff role. N/A responses are excluded from the average rating calculation.</div>
              </div>
            </div>
          </div>
        </div>
        <div class="staff-table-wrap">
          <table class="dtbl staff-rating-table">
            <thead><tr><th>Staff</th><th>Average Rating</th><th style="text-align:center">5</th><th style="text-align:center">4</th><th style="text-align:center">3</th><th style="text-align:center">2</th><th style="text-align:center">1</th><th style="text-align:center">N/A</th><th style="text-align:center">Response</th><th style="text-align:center;min-width:100px">Action</th></tr></thead>
            <tbody id="staffBody"></tbody>
          </table>
        </div>
        <div class="staff-rating-legend">
          <div>
            <strong style="color:var(--ink-2)">Rating Scale:</strong>
            5 &mdash; Strongly Agree &nbsp;&bull;&nbsp;
            4 &mdash; Agree &nbsp;&bull;&nbsp;
            3 &mdash; Neutral &nbsp;&bull;&nbsp;
            2 &mdash; Disagree &nbsp;&bull;&nbsp;
            1 &mdash; Strongly Disagree
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════════ RECENT EXPERIENCE ═══════════ -->
    <div class="page" id="page-notifications">
      <div class="sec-head"><div class="sec-pre">Admin</div><h3>Notification</h3></div>
      <div class="g-card">
          <div class="b-hd notification-toolbar">
            <h4>Notification</h4>
            <div class="notification-actions">
              <label class="notification-select-all"><input type="checkbox" id="notificationSelectAll" onchange="toggleAllNotifications(this.checked)"> <span>Select All</span></label>
              <button type="button" class="btn-secondary notification-delete-btn" onclick="deleteSelectedNotifications()"><i class="fas fa-trash"></i> Delete</button>
              <button type="button" class="btn-secondary notification-delete-btn" onclick="deleteAllReadNotifications()"><i class="fas fa-trash-can"></i> Delete All</button>
            </div>
          </div>
          <div class="notification-feedback" id="notificationFeedback" role="status" aria-live="polite"></div>
        <div id="notificationList"><div class="empty-state"><i class="fas fa-bell"></i><p>No new notification.</p></div></div>
      </div>
    </div>

    <!-- ═══════════ AUDIT ═══════════ -->
    <div class="page" id="page-audit">
      <div class="sec-head"><div class="sec-pre">Admin</div><h3>Audit Log</h3></div>
      <div class="audit-wrap">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
          <h4 style="margin:0">Audit Log</h4>
          <div style="display:flex;align-items:center;gap:8px"><input class="form-input" style="width:260px;padding:6px 10px;font-size:12px" placeholder="Search audit..." id="auditSearch" oninput="renderAudit()"></div>
        </div>
        <table class="audit-tbl">
          <thead><tr><th>Timestamp</th><th>Admin</th><th>Action</th><th>Reference</th><th>Detail</th></tr></thead>
          <tbody id="auditBody"><tr><td colspan="5" style="text-align:center;padding:20px;color:var(--ink-3)">No audit entry yet.</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- ═══════════ SETTINGS ═══════════ -->
    <div class="page" id="page-settings">
      <div class="sec-head"><div class="sec-pre">System</div><h3>Setting</h3></div>
      <div class="g-card settings-card" id="settingsCard">
        <div class="g-card-hd settings-card-hd"><div><h4>System Preference</h4><p>Manage the core setting that control system operation and monitoring.</p></div><i class="fas fa-sliders-h" aria-hidden="true"></i></div>
        <div class="settings-list">
          <section class="settings-item"><div class="settings-copy"><label class="form-label" for="settingFacility">Facility Name</label><p>Name of the healthcare facility where the SFMS is implemented.</p></div><div class="settings-control"><input class="form-input" value="Calape Primary Care Facility" id="settingFacility" maxlength="160" required></div></section>
          <section class="settings-item"><div class="settings-copy"><label class="form-label" for="settingBenchmark">Satisfaction Benchmark (%)</label><p>Defines the target percentage of feedback submission that meet the established satisfaction standard.</p></div><div class="settings-control settings-number"><input class="form-input" type="number" value="80" id="settingBenchmark" min="0" max="100" step="1" inputmode="numeric"><span>%</span></div></section>
          <section class="settings-item"><div class="settings-copy"><label class="form-label" for="settingThreshold">Pending Feedback Alert Threshold</label><p>Defines the number of pending feedback submission at which a workload alert is triggered.</p></div><div class="settings-control"><input class="form-input" type="number" value="10" id="settingThreshold" min="0" step="1" inputmode="numeric"></div></section>
          <section class="settings-item"><div class="settings-copy"><label class="form-label" for="settingNegPct">Negative Sentiment Alert Threshold (%)</label><p>Defines the percentage of feedback classified as Negative sentiment at which an alert is triggered.</p></div><div class="settings-control settings-number"><input class="form-input" type="number" value="20" id="settingNegPct" min="0" max="100" step="1" inputmode="numeric"><span>%</span></div></section>
          <section class="settings-item settings-toggle-item"><div class="settings-copy"><span class="form-label">Auto-Resolve Positive Feedback</span><p>Automatically marks feedback classified as Positive sentiment as Resolved upon submission.</p></div><label class="settings-toggle" for="settingAutoResolve"><input type="checkbox" id="settingAutoResolve" onchange="syncAutoResolveState()"><span class="settings-toggle-track" aria-hidden="true"><span></span></span><span class="settings-toggle-label" id="settingAutoResolveLabel">Disabled <b>OFF</b></span></label></section>
        </div>
        <div class="settings-note"><i class="fas fa-info-circle" aria-hidden="true"></i><div><strong>Note</strong><p>The Satisfaction Benchmark is a target used for dashboard and report monitoring only. Individual feedback is still classified as Satisfied when the average valid rating is ≥ 4.0, according to the existing satisfaction rule.</p></div></div>
        <div class="settings-actions"><div class="settings-feedback" id="settingsFeedback" role="status" aria-live="polite"></div><div class="settings-action-buttons"><button class="btn-secondary" type="button" onclick="resetSettings()"><i class="fas fa-rotate-left"></i> Reset to Default</button><button class="btn-primary" id="saveSettingsButton" type="button" onclick="saveSettings()"><i class="fas fa-save"></i> Save Setting</button></div></div>
      </div>
      <section class="g-card settings-card feedback-qr-settings" aria-labelledby="feedbackQrSettingsTitle">
        <div class="g-card-hd settings-card-hd"><div><h4 id="feedbackQrSettingsTitle">Feedback QR Code</h4><p>View or download the QR code used to open the public feedback entry flow.</p></div><i class="fas fa-qrcode" aria-hidden="true"></i></div>
        <div class="feedback-qr-body">
          <div class="feedback-qr-preview" id="adminFeedbackQrCode" aria-live="polite"><i class="fas fa-qrcode" aria-hidden="true"></i><span>QR preview unavailable</span></div>
          <div class="feedback-qr-copy">
            <p class="feedback-qr-destination">Configured destination: <code id="feedbackQrDestination"></code></p>
            <div class="feedback-qr-actions">
              <button class="btn-secondary" type="button" id="viewFeedbackQrButton" disabled><i class="fas fa-eye" aria-hidden="true"></i> Refresh Preview</button>
              <button class="btn-primary" type="button" id="downloadFeedbackQrButton" disabled><i class="fas fa-download" aria-hidden="true"></i> Download QR Code</button>
            </div>
          </div>
        </div>
      </section>
    </div>

  </div><!-- /scroll-area -->
</div><!-- /content -->
</div><!-- /shell -->

<script>
'use strict';
/* ══════════════════════════════
   GLOBAL STATE
══════════════════════════════ */
let catalogCache = { services: [] };
let trendChartInst = null, distChartInst = null;
let rptMonthlyInst = null, rptRatingInst = null, rptDeptInst = null;
let rptSentimentInst = null, rptSentimentTrendInst = null;
let modalAction = null;
let modalEditId = null;
let settings = { facility: 'Calape Primary Care Facility', benchmark: 80, threshold: 10, negPct: 20, autoResolvePositive: false };
let settingsDirty = false;
let pendingSettingsSave = null;

// Server-side configuration keeps the destination explicit and prevents the
// browser address from silently becoming the production QR destination.
const FEEDBACK_QR_ENTRY_URL = <?= json_encode(FEEDBACK_QR_ENTRY_URL, JSON_UNESCAPED_SLASHES) ?>;
const FEEDBACK_QR_ALLOW_LOCAL_URL = <?= FEEDBACK_QR_ALLOW_LOCAL_URL ? 'true' : 'false' ?>;

function isAllowedFeedbackQrUrl(value) {
  if (!value || typeof value !== 'string') return false;
  try {
    const url = new URL(value);
    const hostname = url.hostname.toLowerCase();
    const isLocal = hostname === 'localhost'
      || hostname === '::1'
      || hostname === '[::1]'
      || /^127\./.test(hostname)
      || /^10\./.test(hostname)
      || /^192\.168\./.test(hostname)
      || /^172\.(1[6-9]|2\d|3[01])\./.test(hostname);
    const expectedRoute = /\/Feedback\/qr-entry\.php\/?$/i.test(url.pathname);
    if (!expectedRoute) return false;
    if (FEEDBACK_QR_ALLOW_LOCAL_URL && isLocal) return ['http:', 'https:'].includes(url.protocol);
    return url.protocol === 'https:' && !isLocal;
  } catch (error) {
    return false;
  }
}

function renderFeedbackQr(container, size) {
  container.replaceChildren();
  new QRCode(container, {
    text: FEEDBACK_QR_ENTRY_URL,
    width: size,
    height: size,
    colorDark: '#17332b',
    colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.H
  });
}

function initializeFeedbackQrControls() {
  const container = document.getElementById('adminFeedbackQrCode');
  const viewButton = document.getElementById('viewFeedbackQrButton');
  const downloadButton = document.getElementById('downloadFeedbackQrButton');
  const destination = document.getElementById('feedbackQrDestination');
  if (!container || !viewButton || !downloadButton || typeof QRCode === 'undefined') return;
  if (destination) destination.textContent = FEEDBACK_QR_ENTRY_URL || 'Not configured';
  if (!isAllowedFeedbackQrUrl(FEEDBACK_QR_ENTRY_URL)) return;

  viewButton.disabled = false;
  downloadButton.disabled = false;

  renderFeedbackQr(container, 320);
  container.setAttribute('role', 'img');
  container.setAttribute('aria-label', 'QR code for the public feedback entry page');

  viewButton.addEventListener('click', () => {
    renderFeedbackQr(container, 320);
  });

  downloadButton.addEventListener('click', () => {
    const exportContainer = document.createElement('div');
    exportContainer.hidden = true;
    document.body.appendChild(exportContainer);
    renderFeedbackQr(exportContainer, 1024);
    const canvas = exportContainer.querySelector('canvas');
    const image = exportContainer.querySelector('img');
    const source = canvas?.toDataURL('image/png') || image?.src;
    exportContainer.remove();
    if (!source) return;
    const link = document.createElement('a');
    link.href = source;
    link.download = 'calape-pcf-feedback-qr.png';
    link.click();
  });
}

const PAGE_TITLES = {
  'dashboard':'Dashboard',
  'respondent-patient':'Patient / Client',
  'respondent-companion':"Patient's Companion (Relative)",
  'respondent-business':'Business (visited for business purposes)',
  'respondent-other':'Other',
  'pending':'Pending Review',
  'resolved':'Resolved','reports':'Reports',
  'services':'Services','staff':'Staff Ratings',
  'notifications':'Recent Experience','audit':'Audit Log','settings':'Settings'
};

/* ══════════════════════════════
   NAVIGATION
══════════════════════════════ */
function nav(el) {
  if (!el) return;
  const target = el.dataset.page;
  if (!target) return;
  const respondentPages = {
    'respondent-patient': 'patient',
    'respondent-companion': 'companion',
    'respondent-business': 'business',
    'respondent-other': 'other'
  };
  const isRespondentPage = Object.prototype.hasOwnProperty.call(respondentPages, target);
  const allFeedbackLink = document.querySelector('.sb-link[data-page="all-feedbacks"]');
  const allFeedbackSubmenu = document.getElementById('allFeedbackSubmenu');
  const isAllFeedback = target === 'all-feedbacks';
  const isFeedbackSub = el.classList.contains('sub');

  if (isAllFeedback) {
    const expanded = !allFeedbackSubmenu.classList.contains('open');
    allFeedbackSubmenu.classList.toggle('open', expanded);
    allFeedbackLink.classList.toggle('sb-open', expanded);
    allFeedbackLink.setAttribute('aria-expanded', String(expanded));
    allFeedbackSubmenu.style.maxHeight = expanded ? allFeedbackSubmenu.scrollHeight + 'px' : '0px';
    return;
  }

  if (allFeedbackSubmenu && allFeedbackLink) {
    if (isFeedbackSub) {
      allFeedbackSubmenu.classList.add('open');
      allFeedbackLink.classList.add('sb-open');
      allFeedbackLink.setAttribute('aria-expanded', 'true');
      allFeedbackSubmenu.style.maxHeight = allFeedbackSubmenu.scrollHeight + 'px';
    } else {
      allFeedbackSubmenu.classList.remove('open');
      allFeedbackLink.classList.remove('sb-open');
      allFeedbackLink.setAttribute('aria-expanded', 'false');
      allFeedbackSubmenu.style.maxHeight = '0px';
    }
  }

  // Clear active states. Be precise: clear sub-item active flags separately to avoid
  // accidentally leaving multiple sub-items active if other scripts interfere.
  document.querySelectorAll('.sb-link.sub.active').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.sb-link:not(.sub).active').forEach(s => s.classList.remove('active'));

  // Activate the clicked link
  el.classList.add('active');
  if (isFeedbackSub && allFeedbackLink) {
    allFeedbackLink.classList.add('active');
  }

  // Short cleanup: after other handlers run, ensure only the intended sub-item remains active.
  setTimeout(() => {
    if (isFeedbackSub) {
      document.querySelectorAll('.sb-link.sub.active').forEach(s => {
        if (s !== el) s.classList.remove('active');
      });
    }
  }, 40);
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  const pg = document.getElementById('page-' + target);
  if (pg) pg.classList.add('active');
  const isFeedbackPage = pg?.classList.contains('feedback-page') || false;
  document.body.classList.toggle('feedback-list-active', isFeedbackPage);
  if (isFeedbackPage) {
    const globalSearch = document.getElementById('globalSearch');
    const pageSearch = pg.querySelector('[data-filter="search"]');
    if (globalSearch?.value && pageSearch && !pageSearch.value) {
      pageSearch.value = globalSearch.value;
      globalSearch.value = '';
    }
  }
  document.getElementById('pageTitle').textContent = PAGE_TITLES[target] || target;
  document.getElementById('scrollArea').scrollTo({ top: 0, behavior: 'smooth' });
  if (target === 'reports') { renderReportCharts(); }
  if (target === 'services') renderServices();
  if (target === 'staff') renderStaffTable();
  if (isRespondentPage) renderRespondentCategoryTable(respondentPages[target]);
  if (target === 'pending' || target === 'resolved') renderFbLists();
  if (target === 'notifications' && typeof syncNotifications === 'function') {
    syncNotifications();
  }
}
const dashboardNav = nav;

let adminPrintCleanup = null;

function finalizePrintableCharts(scope) {
  if (!scope || typeof Chart === 'undefined') return;
  scope.querySelectorAll('canvas').forEach(canvas => {
    const chart = typeof Chart.getChart === 'function' ? Chart.getChart(canvas) : null;
    if (!chart) return;
    try {
      chart.stop();
      chart.resize();
      chart.update('none');
      chart.render();
    } catch (error) {
      console.warn('Unable to finalize a chart for printing.', error);
    }
  });
}

async function printAdminView(view, trigger) {
  const allowedViews = ['dashboard', 'reports'];
  if (!allowedViews.includes(view)) return;
  const page = document.getElementById('page-' + view);
  if (!page) {
    AdminUI.toast('The requested print view is unavailable.', 'error');
    return;
  }

  if (adminPrintCleanup) adminPrintCleanup();
  const originalLabel = trigger?.innerHTML || '';
  if (trigger) {
    trigger.disabled = true;
    trigger.setAttribute('aria-busy', 'true');
    trigger.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i><span>Preparing...</span>';
  }

  document.body.classList.add('admin-printing', 'print-' + view);
  page.classList.add('print-target');

  const cleanup = () => {
    document.body.classList.remove('admin-printing', 'print-dashboard', 'print-reports');
    document.querySelectorAll('.page.print-target').forEach(item => item.classList.remove('print-target'));
    if (trigger) {
      trigger.disabled = false;
      trigger.removeAttribute('aria-busy');
      trigger.innerHTML = originalLabel;
    }
    finalizePrintableCharts(page);
    adminPrintCleanup = null;
  };
  adminPrintCleanup = cleanup;

  try {
    if (document.fonts?.ready) await document.fonts.ready;
    finalizePrintableCharts(page);
    await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    await new Promise(resolve => setTimeout(resolve, 120));
    finalizePrintableCharts(page);
    window.print();
  } catch (error) {
    cleanup();
    AdminUI.toast('The print preview could not be opened.', 'error');
  }
}

window.addEventListener('beforeprint', () => {
  finalizePrintableCharts(document.querySelector('.page.print-target'));
});
window.addEventListener('afterprint', () => {
  if (adminPrintCleanup) adminPrintCleanup();
});

/* ══════════════════════════════
   ESCAPE
══════════════════════════════ */
function esc(v) {
  return String(v == null ? '' : v)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function currentCsrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function csrfHeaders(headers = {}) {
  return { ...headers, 'X-CSRF-Token': currentCsrfToken() };
}

/* ══════════════════════════════
   SETTINGS
══════════════════════════════ */
async function loadSettings() {
  try {
    const response = await fetch('/Feedback/api/settings.php', {headers:{'Accept':'application/json'}});
    const payload = await response.json();
    if (!response.ok || !payload.success) throw new Error(payload.message || 'Unable to load settings.');
    Object.assign(settings, payload.data || {});
  } catch(e) {
    // Retain defaults if the settings endpoint is temporarily unavailable.
  }
  const el = id => document.getElementById(id);
  if (el('settingFacility'))    el('settingFacility').value    = settings.facility;
  if (el('settingBenchmark'))   el('settingBenchmark').value   = settings.benchmark;
  if (el('settingThreshold'))   el('settingThreshold').value   = settings.threshold;
  if (el('settingNegPct'))      el('settingNegPct').value      = settings.negPct;
  if (el('settingAutoResolve')) el('settingAutoResolve').checked = !!settings.autoResolvePositive;
  syncAutoResolveState();
  settingsDirty = false;
  updateSettingsDirtyState();
}

function setSettingsFeedback(message, type = '') {
  const feedback = document.getElementById('settingsFeedback');
  if (!feedback) return;
  feedback.textContent = message;
  feedback.className = `settings-feedback ${type}`;
}

function syncAutoResolveState() {
  const enabled = !!document.getElementById('settingAutoResolve')?.checked;
  const label = document.getElementById('settingAutoResolveLabel');
  if (label) label.innerHTML = enabled ? 'Enabled <b>ON</b>' : 'Disabled <b>OFF</b>';
}

function updateSettingsDirtyState() {
  const button = document.getElementById('saveSettingsButton');
  if (button) button.classList.toggle('has-unsaved-changes', settingsDirty);
}

function markSettingsDirty() {
  settingsDirty = true;
  updateSettingsDirtyState();
  setSettingsFeedback('You have unsaved changes.', 'info');
}

function resetSettings() {
  document.getElementById('settingFacility').value = 'Calape Primary Care Facility';
  document.getElementById('settingBenchmark').value = 80;
  document.getElementById('settingThreshold').value = 10;
  document.getElementById('settingNegPct').value = 20;
  document.getElementById('settingAutoResolve').checked = false;
  syncAutoResolveState();
  settingsDirty = true;
  updateSettingsDirtyState();
  setSettingsFeedback('Default values restored. Click Save Settings to apply them.', 'info');
}

// Ensure All Feedback submenu is collapsed by default and wire a reliable click handler
(function initAllFeedbackSubmenu(){
  try {
    const allLink = document.querySelector('.sb-link[data-page="all-feedbacks"]');
    const submenu = document.getElementById('allFeedbackSubmenu');
    if (submenu) {
      submenu.classList.remove('open');
      submenu.style.maxHeight = '0px';
    }
  } catch (e) { /* ignore */ }
})();
function getValidatedSettings() {
  const field = id => document.getElementById(id);
  const facility = field('settingFacility').value.trim();
  const benchmarkValue = field('settingBenchmark').value.trim();
  const thresholdValue = field('settingThreshold').value.trim();
  const negPctValue = field('settingNegPct').value.trim();
  const next = {
    facility,
    benchmark: Number(benchmarkValue),
    threshold: Number(thresholdValue),
    negPct: Number(negPctValue),
    autoResolvePositive: field('settingAutoResolve').checked,
  };
  if (!facility || facility.length > 160 || !Number.isInteger(next.benchmark) || next.benchmark < 0 || next.benchmark > 100 || !Number.isInteger(next.threshold) || next.threshold < 0 || !Number.isInteger(next.negPct) || next.negPct < 0 || next.negPct > 100 || !benchmarkValue || !thresholdValue || !negPctValue) {
    setSettingsFeedback('Please provide a facility name and valid whole-number settings.', 'error');
    return null;
  }
  return next;
}

function saveSettings() {
  const next = getValidatedSettings();
  if (!next) return;
  pendingSettingsSave = next;
  const overlay = document.getElementById('settingsConfirmOverlay');
  const modalFeedback = document.getElementById('settingsModalFeedback');
  if (modalFeedback) modalFeedback.textContent = '';
  if (overlay) {
    overlay.hidden = false;
    requestAnimationFrame(() => overlay.classList.add('open'));
    document.getElementById('confirmSettingsSave')?.focus();
  }
}

function closeSettingsConfirmation() {
  const overlay = document.getElementById('settingsConfirmOverlay');
  if (!overlay) return;
  overlay.classList.remove('open');
  setTimeout(() => { overlay.hidden = true; }, 180);
  pendingSettingsSave = null;
}

function openLogoutConfirmation(event) {
  event.preventDefault();
  const overlay = document.getElementById('logoutConfirmOverlay');
  if (!overlay) return;
  overlay.hidden = false;
  requestAnimationFrame(() => overlay.classList.add('open'));
  overlay.querySelector('.btn-secondary')?.focus();
}

function closeLogoutConfirmation() {
  const overlay = document.getElementById('logoutConfirmOverlay');
  if (!overlay) return;
  overlay.classList.remove('open');
  setTimeout(() => { overlay.hidden = true; }, 180);
}

function confirmLogout() {
  document.getElementById('logoutForm')?.submit();
}

async function confirmSaveSettings() {
  const next = pendingSettingsSave || getValidatedSettings();
  if (!next) return;
  const button = document.getElementById('confirmSettingsSave');
  const modalFeedback = document.getElementById('settingsModalFeedback');
  if (button) { button.disabled = true; button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…'; }
  if (modalFeedback) modalFeedback.textContent = '';
  try {
    const response = await fetch('/Feedback/api/settings.php', {
      method: 'POST', headers: csrfHeaders({'Content-Type':'application/json','Accept':'application/json'}), body: JSON.stringify(next)
    });
    const payload = await response.json();
    if (!response.ok || !payload.success) throw new Error(payload.message || 'Unable to save settings.');
    Object.assign(settings, payload.data?.settings || next);
    if (window.AdminDashboardStore) await window.AdminDashboardStore.refreshModules(true);
    setSettingsFeedback(payload.data?.changed?.length ? 'Settings have been updated successfully.' : 'No settings were changed.', 'success');
    settingsDirty = false;
    updateSettingsDirtyState();
    closeSettingsConfirmation();
    renderDashboard();
  } catch (error) {
    const message = error.message || 'Unable to save settings.';
    setSettingsFeedback(message, 'error');
    if (modalFeedback) modalFeedback.textContent = message;
  } finally {
    if (button) { button.disabled = false; button.innerHTML = '<i class="fas fa-save"></i> Save Changes'; }
  }
}

/* ══════════════════════════════
   DATA HELPERS
══════════════════════════════ */
function getStoredCatalog() {
  try { return JSON.parse(localStorage.getItem('adminCatalogCache') || '{"services":[]}'); } catch(e) { return { services:[] }; }
}
function setStoredCatalog(c) { localStorage.setItem('adminCatalogCache', JSON.stringify(c)); }

function avgRating(entry) {
  if (typeof entry.rating === 'number' && !isNaN(entry.rating) && entry.rating > 0) return entry.rating;

  const values = [];
  const ratings = entry.ratings || {};

  Object.values(ratings).forEach(r => {
    if (Array.isArray(r)) {
      r.forEach(v => {
        const n = Number(v);
        if (!isNaN(n) && n > 0) values.push(n);
      });
      return;
    }

    if (r && typeof r === 'object' && r.score !== undefined) {
      const n = Number(r.score);
      if (!isNaN(n) && n > 0) values.push(n);
      return;
    }

    const n = Number(r);
    if (!isNaN(n) && n > 0) values.push(n);
  });

  return values.length ? values.reduce((a,b)=>a+b,0)/values.length : 0;
}

/* ══════════════════════════════
   CLIENT-SIDE SENTIMENT ANALYSIS (mirrors PHP fallback)
   Used for localStorage entries that lack sentimentLabel
══════════════════════════════ */
const POSITIVE_WORDS = new Set(['excellent','exceptional','outstanding','superb','fantastic','wonderful','amazing','great','good','nice','best','helpful','friendly','kind','caring','compassionate','attentive','professional','courteous','polite','respectful','patient','satisfied','happy','pleased','impressed','delighted','comfortable','efficient','fast','quick','prompt','timely','smooth','clean','organized','neat','thank','thanks','grateful','appreciate','appreciated','appreciation','recommend','recommended','commend','congratulate','praise','magaling','maganda','maayos','mabilis','mabuti','masaya','mahusay','matulungin','mabait','maayo','nindot','paspas','salamat','buotan','limpyo','hapsay','klaro','komportable','ganahan']);
const NEGATIVE_WORDS = new Set(['bad','poor','terrible','awful','horrible','worst','dreadful','unacceptable','unsatisfactory','inadequate','mediocre','substandard','rude','unfriendly','unkind','arrogant','dismissive','unhelpful','incompetent','negligent','careless','irresponsible','disrespectful','disappointed','dissatisfied','frustrated','upset','angry','ignored','neglected','waiting','delayed','slow','late','dirty','filthy','unclean','messy','disorganized','disgusting','shameful','outrageous','ridiculous','unprofessional','waste','wasted','useless','pointless','complaint','complain','matagal','tagal','bastos','pangit','ayaw','dugay','lisod','problema','masama','mabagal','kulang','sakit','hassle','kasakit','huot','gubot']);
const NEGATION_WORDS = new Set(['not','no','never',"don't","didn't","wasn't","weren't","isn't","aren't","couldn't","wouldn't","shouldn't",'without','none','hindi','dili','di']);

function analyzeSentimentJS(text) {
  if (!text || !text.trim()) return { label: 'Neutral', score: 0, confidence: 0, polarity: 0 };
  const neutralHints=new Set(['okay','ok','average','fair','fine','acceptable','satisfactory','ordinary','normal','moderate','moderately']);
  const requests=new Set(['please','kindly','add','provide','install','increase','extend','improve','improvement','need','needs','needed','request','suggest','suggestion','faster','cleaner','kailangan','pakilinis','pakilinisan','pakiayos','paspasan','dugangan','butangan','unta']);
  const fillers=new Set(['at','all','very','really','quite','that','so','too','much','gaano','gaanong','masyado','kaayo','gyud','jud','man']);
  const positivePhrases=['no problem','no problems','walang problema','walay problema','way problema','wala koy reklamo','no complaint','no complaints','well maintained','keep up the good work','job well done'];
  const negativePhrases=['not at all good','hindi gaanong maganda','dili kaayo maayo','long waiting time','long wait','needs improvement','need improvement','walang gamot','walay tambal','lack of medicine','out of stock','please clean the comfort room','please clean comfort room','please clean the cr','please clean cr','clean the cr','clean cr','improve waiting time','faster er response needed','walang malinaw na instructions'];
  const neutralPhrases=['add more chairs in the waiting area','add more chairs','maayo unta paspasan'];
  let working=text.toLowerCase().replace(/[-–—]/g,' ').replace(/[^\p{L}\p{N}\s']/gu,' ').replace(/\s+/g,' ').trim();
  let positiveMatches=0,negativeMatches=0,rawScore=0,neutralCount=0,requestCount=0,negation=0,intensified=false;
  const take=(phrases,positive)=>phrases.forEach(p=>{const escaped=p.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');const re=new RegExp(`(^|\\s)${escaped}(?=\\s|$)`,'gu');working=working.replace(re,m=>{positive?positiveMatches++:negativeMatches++;rawScore+=positive?1.5:-1.5;return ' ';});});
  take(positivePhrases,true);take(negativePhrases,false);neutralPhrases.forEach(p=>{if(working.includes(p)){working=working.replace(p,' ');requestCount++;}});
  const tokens=working.trim().split(/\s+/).filter(Boolean);
  tokens.forEach(tok=>{
    if(NEGATION_WORDS.has(tok)){negation=4;return;}
    if(['very','extremely','really','absolutely','totally','completely','highly','so','too','quite','super','sobrang','napaka','kaayo'].includes(tok)){intensified=true;if(negation>0)negation--;return;}
    if(fillers.has(tok)){if(negation>0)negation--;return;}
    if(requests.has(tok)||/^(improv|clean|fast|paspas|dugang|butang)/u.test(tok))requestCount++;
    if(neutralHints.has(tok))neutralCount++;
    let pol=POSITIVE_WORDS.has(tok)?1:NEGATIVE_WORDS.has(tok)?-1:0;
    if(!pol&&/^(helpful|friendly|clean|quick|fast|kind|professional)(er|est|ly)?$/u.test(tok))pol=1;
    if(!pol&&(/^(wait|delay|complain)(ed|ing|s|t)?$/u.test(tok)||/^(slow|dirty|rude|late)(er|est|ly|ness)?$/u.test(tok)||/^(matagal|tagal|mabagal|dugay|lisod|kulang|sakit)/u.test(tok)))pol=-1;
    if(pol){if(negation>0)pol*=-1;const weight=intensified?1.5:1;if(pol>0){positiveMatches++;rawScore+=weight;}else{negativeMatches++;rawScore-=weight;}negation=0;intensified=false;}else{if(negation>0)negation--;intensified=false;}
  });
  const wordCount=Math.max(1,text.trim().split(/\s+/).length), norm=Math.max(-1,Math.min(1,rawScore/Math.sqrt(wordCount))), evidence=positiveMatches+negativeMatches;
  let confidence=Math.min(1,.48+Math.min(.32,.12*evidence)+Math.min(.20,evidence/wordCount));
  let label=positiveMatches&&negativeMatches?(rawScore>0?'Positive':rawScore<0?'Negative':'Neutral'):positiveMatches?'Positive':negativeMatches?'Negative':'Neutral';
  if(label==='Neutral'&&(requestCount||neutralCount))confidence=Math.max(confidence,.60);
  return { label, score: Math.round(confidence * 10000) / 10000, confidence: Math.round(confidence * 10000) / 10000, polarity: Math.round(norm * 10000) / 10000 };
}

function normalize(e) {
  const anon   = typeof e.anonymous === 'boolean' ? e.anonymous : !!e.isAnonymous;
  const name   = e.name || [e.firstName,e.lastName].filter(Boolean).join(' ') || 'Guest';
  const rating = avgRating(e);
  const ts     = e.timestamp || (e.date ? new Date(e.date+'T00:00:00').toISOString() : new Date().toISOString());

  // ── Sentiment: accept only current categories; analyze written text otherwise ──
  const storedSentiment = String(e.sentimentLabel || e.sentiment_label || '').toLowerCase();
  let sentimentLabel = ({positive:'Positive',negative:'Negative',neutral:'Neutral'})[storedSentiment] || '';
  let sentimentScore = Number(e.sentimentScore || e.sentiment_score || 0);
  if (!sentimentLabel) {
    const text = [e.feedback, e.suggestions].map(value => String(value || '').trim()).filter(Boolean).join(' ');
    const ratingsOnly = /^(?:[a-z][a-z0-9_]*\s*:\s*(?:(?:[1-5](?:\.\d+)?)|n\/a)?\s*;?\s*)+$/i.test(text);
    if (storedSentiment || !text || ratingsOnly) {
      sentimentLabel = 'Neutral';
      sentimentScore = 0;
    } else {
      const result = analyzeSentimentJS(text);
      sentimentLabel = result.label;
      sentimentScore = result.score;
    }
  }

  // ── Source defaulting ──
  const source = e.source || (e.formType === 'visitor' ? 'Visitor' : 'Patient');

  // ── Priority: High if Negative sentiment AND rating <= 2 ──
  const priority = (e.priority === 'High') ||
    (sentimentLabel === 'Negative' && rating > 0 && rating <= 2)
    ? 'High' : 'Normal';

  return {
    ...e, anonymous: anon, isAnonymous: anon,
    name, rating, source,
    timestamp: ts, date: e.date || ts.slice(0,10),
    sentimentLabel, sentimentScore,
    priority,
    isHighPriority: priority === 'High',
    // Legacy compat (hidden)
    isUrgent: false,
  };
}

function loadEntries() {
  if (!window.AdminDashboardStore || typeof window.AdminDashboardStore.getEntries !== 'function') return [];
  return window.AdminDashboardStore.getEntries();
}

function isDuplicate(entries) {
  const seen = new Set();
  return entries.map(e => {
    const key = (e.feedback||'').trim().toLowerCase().slice(0,60) + '|' + (e.timestamp||'').slice(0,16);
    const dup = seen.has(key);
    seen.add(key);
    return { ...e, isDuplicate: dup };
  });
}

/* ══════════════════════════════
   API SYNC
══════════════════════════════ */
// Populates the `catalogCache` variable used by the Add/Edit Service modal
// (edit-value prefill). This is intentionally NOT named syncCatalog() —
// that name is reassigned by js/admin/bootstrap.js to load richer table
// data via api/admin_modules.php, and catalogCache needs api/catalog.php's
// simpler {services} shape instead. Falls back to the last real cached
// response (localStorage) on failure — never to fabricated service data.
async function syncCatalogCache() {
  try {
    const r = await fetch('/Feedback/api/catalog.php', { headers:{'Accept':'application/json'} });
    const p = await r.json();
    if (p.success && p.data) { catalogCache = p.data; setStoredCatalog(catalogCache); return true; }
  } catch(e) { console.warn('Catalog sync failed', e); }
  catalogCache = getStoredCatalog();
  return false;
}

let notificationCache = [];
const selectedNotificationIds = new Set();

function renderNotificationBadge(count) {
  const bellCount = document.getElementById('bellCount');
  const notifDot = document.getElementById('notifDot');
  const safeCount = Math.max(0, Number(count) || 0);
  if (notifDot) notifDot.classList.toggle('hide', safeCount === 0);
  if (!bellCount) return;
  bellCount.textContent = String(safeCount);
  bellCount.classList.toggle('hide', safeCount === 0);
}

async function openNotificationPage() {
  const notificationLink = document.querySelector('[data-page="notifications"]');
  if (notificationLink && typeof nav === 'function') nav(notificationLink);
  await syncNotifications();
}

function renderNotificationList(notifications) {
  const el = document.getElementById('notificationList');
  if (!el) return;
  if (!notifications.length) {
    el.innerHTML = '<div class="empty-state"><i class="fas fa-bell"></i><p>No notifications.</p></div>';
    return;
  }

  const entriesByReference = new Map(
    loadEntries().map(entry => [String(entry.referenceId || ''), entry.sentimentLabel])
  );
  el.innerHTML = notifications.map(notification => {
    const sentimentLabel = entriesByReference.get(String(notification.referenceId || ''));
    const isNegative = sentimentLabel === 'Negative';
    const icon = notification.type === 'danger'
      ? 'fas fa-triangle-exclamation'
      : notification.type === 'success'
        ? 'fas fa-circle-check'
        : notification.type === 'warning'
          ? 'fas fa-clock'
          : 'fas fa-bell';
    const checked = selectedNotificationIds.has(notification.id) ? ' checked' : '';
    const iconClass = isNegative
      ? 'ic-red'
      : notification.type === 'danger'
        ? 'ic-red'
        : notification.type === 'success'
          ? 'ic-green'
          : notification.type === 'warning'
            ? 'ic-amber'
            : 'ic-blue';
    return `<div class="notif-item${notification.isRead ? '' : ' is-unread'}${isNegative ? ' is-negative' : ''}" data-notification-id="${esc(notification.id)}" data-reference-id="${esc(notification.referenceId || '')}" role="button" tabindex="0" onclick="openNotificationItem(${notification.id})" onkeydown="if(event.key==='Enter' || event.key===' ') openNotificationItem(${notification.id})">
      <label class="notification-checkbox" onclick="event.stopPropagation()"><input type="checkbox"${checked} aria-label="Select notification" onchange="toggleNotificationSelection(${notification.id}, this.checked)"></label>
      <div class="notif-icon ${iconClass}"><i class="${icon}"></i></div>
      <div class="notif-body">
        <div class="notif-title">${esc(notification.title)}</div>
        <div class="notif-desc">${esc(notification.message)}</div>
        <div class="notif-desc" style="margin-top:4px;font-size:11px;color:var(--ink-3)">${esc(notification.referenceId ? 'Ref: ' + notification.referenceId : 'System notice')}</div>
      </div>
      <div class="notif-time">${esc(notification.createdAtLabel || notification.createdAt || '')}</div>
    </div>`;
  }).join('');
  updateNotificationSelectAllState();
}

function showNotificationFeedback(message, isError = true) {
  const feedback = document.getElementById('notificationFeedback');
  if (!feedback) return;
  feedback.textContent = message;
  feedback.className = `notification-feedback${isError ? ' is-error' : ' is-success'}`;
}

function toggleNotificationSelection(notificationId, selected) {
  if (selected) selectedNotificationIds.add(Number(notificationId));
  else selectedNotificationIds.delete(Number(notificationId));
  updateNotificationSelectAllState();
}

function toggleAllNotifications(selected) {
  document.getElementById('notificationList')?.classList.toggle('selection-mode', selected);
  notificationCache.forEach(notification => {
    if (selected) selectedNotificationIds.add(notification.id);
    else selectedNotificationIds.delete(notification.id);
  });
  renderNotificationList(notificationCache);
}

function updateNotificationSelectAllState() {
  const selectAll = document.getElementById('notificationSelectAll');
  if (!selectAll) return;
  selectAll.checked = notificationCache.length > 0 && notificationCache.every(notification => selectedNotificationIds.has(notification.id));
}

async function deleteNotifications(ids, deleteAllRead = false) {
  if (!ids.length && !deleteAllRead) {
    document.getElementById('notificationList')?.classList.add('selection-mode');
    showNotificationFeedback('Select at least one notification to delete.');
    return;
  }
  const selected = deleteAllRead ? notificationCache.filter(notification => notification.isRead).map(notification => notification.id) : ids;
  const unreadSelected = notificationCache.some(notification => ids.includes(notification.id) && !notification.isRead);
  if (unreadSelected) {
    showNotificationFeedback('Please read the notification before deleting it.');
    return;
  }
  if (!selected.length) {
    showNotificationFeedback('There are no read notifications to delete.');
    return;
  }
  try {
    const response = await fetch('/Feedback/api/notifications.php', {
      method: 'DELETE',
      headers: csrfHeaders({ 'Content-Type': 'application/json', 'Accept': 'application/json' }),
      body: JSON.stringify({ ids: selected }),
    });
    const payload = await response.json();
    if (!response.ok || !payload.success) throw new Error(payload.message || 'Unable to delete notifications.');
    selected.forEach(id => selectedNotificationIds.delete(id));
    showNotificationFeedback('Notifications deleted.', false);
    await syncNotifications();
  } catch (error) {
    showNotificationFeedback(error.message || 'Unable to delete notifications.');
  }
}

function deleteSelectedNotifications() {
  deleteNotifications(Array.from(selectedNotificationIds));
}

function deleteAllReadNotifications() {
  deleteNotifications([], true);
}

async function syncNotifications({ markAllRead = false } = {}) {
  try {
    const r = await fetch('/Feedback/api/notifications.php', {
      headers: { 'Accept': 'application/json' }
    });
    const p = await r.json();
    if (p.success && Array.isArray(p.data)) {
      notificationCache = p.data;
      const unread = p.data.filter(n => !n.isRead).length;
      renderNotificationBadge(unread);
      const activePage = document.querySelector('.page.active')?.id === 'page-notifications';
      renderNotificationList(notificationCache);

      if (markAllRead && unread > 0) {
        await fetch('/Feedback/api/notifications.php', {
          method: 'PATCH',
          headers: csrfHeaders({ 'Content-Type': 'application/json', 'Accept': 'application/json' }),
          body: JSON.stringify({ markAll: true })
        });
        notificationCache = notificationCache.map(n => ({ ...n, isRead: true }));
        renderNotificationBadge(0);
        if (activePage) renderNotificationList(notificationCache);
      }

      return true;
    }
  } catch (e) {
    console.warn('Notification sync failed', e);
  }
  renderNotificationBadge(0);
  if (document.getElementById('notificationList')) {
    document.getElementById('notificationList').innerHTML = '<div class="empty-state"><i class="fas fa-bell"></i><p>No notifications.</p></div>';
  }
  return false;
}

async function openNotificationItem(notificationId) {
  const numericId = Number(notificationId);
  const notification = notificationCache.find(item => item.id === numericId);
  if (numericId) {
    try {
      await fetch('/Feedback/api/notifications.php', {
        method: 'PATCH',
        headers: csrfHeaders({ 'Content-Type': 'application/json', 'Accept': 'application/json' }),
        body: JSON.stringify({ id: numericId })
      });
    } catch (e) {
      console.warn('Unable to mark notification read', e);
    }
    notificationCache = notificationCache.map(notification => notification.id === numericId
      ? { ...notification, isRead: true }
      : notification);
    renderNotificationBadge(notificationCache.filter(notification => !notification.isRead).length);
    renderNotificationList(notificationCache);
  }

  const category = notification?.respondentCategory;
  if (notification?.referenceId && ['patient', 'companion', 'business', 'other'].includes(category)) {
    const categoryLink = document.querySelector(`[data-page="respondent-${category}"]`);
    if (!categoryLink) return;
    try {
      await window.AdminDashboardStore.refreshFeedback(true);
    } catch (error) {
      console.warn('Unable to refresh feedback list', error);
    }
    nav(categoryLink);
    const filters = document.querySelector(`[data-feedback-filters="${category}"]`);
    if (filters) {
      filters.querySelectorAll('[data-filter]').forEach(input => {
        if (input.dataset.filter !== 'search') input.value = '';
      });
      const search = filters.querySelector('[data-filter="search"]');
      if (search) search.value = notification.referenceId;
    }
    window.renderFeedbackPage?.(category);
    return;
  }

  await syncNotifications();
}

async function markNotificationRead(notificationId) {
  return openNotificationItem(notificationId);
}

async function markAllNotificationsRead() {
  try {
    await fetch('/Feedback/api/notifications.php', {
      method: 'PATCH',
      headers: csrfHeaders({ 'Content-Type': 'application/json', 'Accept': 'application/json' }),
      body: JSON.stringify({ markAll: true })
    });
  } catch (e) {
    console.warn('Unable to mark notifications read', e);
  }
  await syncNotifications();
}

async function catalogPost(action, data) {
  const r = await fetch('/Feedback/api/catalog.php', {
    method:'POST',
    headers:csrfHeaders({'Content-Type':'application/json','Accept':'application/json'}),
    body: JSON.stringify({ action, ...data })
  });
  const p = await r.json();
  if (!p.success) throw new Error(p.message || 'Catalog update failed.');
  if (p.data) { catalogCache = p.data; setStoredCatalog(catalogCache); }
}

/* ══════════════════════════════
   FEEDBACK CRUD
══════════════════════════════ */
function showStatusUndoBanner(refId, previousStatus, nextStatus) {
  let banner = document.getElementById('statusUndoBanner');
  if (!banner) {
    banner = document.createElement('div');
    banner.id = 'statusUndoBanner';
    banner.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:9999;max-width:380px;background:#0f172a;color:#fff;padding:14px 16px;border-radius:14px;box-shadow:0 18px 40px rgba(15,23,42,.25);display:none;gap:10px;align-items:center;';
    banner.innerHTML = '<div style="flex:1"><div style="font-weight:700;font-size:13px">Status updated</div><div style="font-size:12px;opacity:.9" id="statusUndoText"></div></div><button type="button" class="q-btn view-btn" id="statusUndoBtn" style="background:#fff;color:#0f172a;border:none">Undo</button>';
    document.body.appendChild(banner);
  }
  const text = document.getElementById('statusUndoText');
  const button = document.getElementById('statusUndoBtn');
  if (text) text.textContent = `${previousStatus.replace('_', ' ')} → ${nextStatus.replace('_', ' ')}. Undo available for 30 seconds.`;
  banner.style.display = 'flex';
  const hideTimer = window.setTimeout(() => {
    banner.style.display = 'none';
  }, 30000);
  button.onclick = async () => {
    window.clearTimeout(hideTimer);
    banner.style.display = 'none';
    await updateStatus(refId, previousStatus, true);
  };
}

/* ══════════════════════════════
   AUDIT LOG
══════════════════════════════ */
function addAudit(admin, action, ref, detail) {
  try {
    const log = JSON.parse(localStorage.getItem('auditLog') || '[]');
    log.unshift({ ts: new Date().toISOString(), admin, action, ref, detail });
    localStorage.setItem('auditLog', JSON.stringify(log.slice(0,200)));
    renderAudit();
  } catch(e){}
}

function renderAudit() {
  const body = document.getElementById('auditBody');
  if (!body) return;
  try {
    let log = JSON.parse(localStorage.getItem('auditLog') || '[]');
    const q = (document.getElementById('auditSearch')?.value||'').toLowerCase();
    if (q) log = log.filter(l => [l.ts, l.admin, l.action, l.ref, l.detail].some(f => (f||'').toLowerCase().includes(q)));
    if (!log.length) { body.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--ink-3)">No audit entries found.</td></tr>'; return; }
    body.innerHTML = log.slice(0,50).map(e => `<tr>
      <td style="white-space:nowrap;font-family:DM Mono,monospace;font-size:11px">${esc(e.ts?.slice(0,19).replace('T',' '))}</td>
      <td>${esc(e.admin)}</td><td>${esc(e.action)}</td>
      <td style="font-size:11px;color:var(--ink-3)">${esc(e.ref||'—')}</td>
      <td>${esc(e.detail)}</td>
    </tr>`).join('');
  } catch(err){}
}

/* ══════════════════════════════
   SMART STATUS ENGINE (Banner) — Priority order:
   1. Critical (High Priority feedback)
   2. High Workload (pending > threshold)
   3. High Negative Sentiment Rate
   4. Queue Cleared
   5. System Stable (default)
══════════════════════════════ */
/* ══════════════════════════════
   SPARK BARS
══════════════════════════════ */
function renderSpark(containerId, values) {
  const el = document.getElementById(containerId);
  if (!el) return;
  if (!values.length) { el.innerHTML = ''; return; }
  const max = Math.max(...values, 1);
  el.innerHTML = values.map((v,i) => {
    const h = Math.max(10, Math.round((v/max)*32));
    const isLast = i === values.length - 1;
    return `<div class="spark-bar ${isLast?'highlight':''}" style="height:${h}px"></div>`;
  }).join('');
}

/* ══════════════════════════════
   CHART HELPERS
══════════════════════════════ */
function destroyChart(inst) { if (inst) { try { inst.destroy(); } catch(e){} } return null; }

function makeBarChart(canvasId, labels, data, label, color='#0f766e') {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return null;
  return new Chart(canvas, {
    type: 'bar',
    data: { labels, datasets: [{ label, data, backgroundColor: color+'88', borderColor: color, borderWidth: 1.5, borderRadius: 4 }] },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { display: false }, ticks: { font:{ size:11 }, maxRotation:45 } },
        y: { grid: { color:'#f1f5f9' }, ticks: { font:{ size:11 }, stepSize: 1 }, beginAtZero: true }
      }
    }
  });
}

function makeDoughnut(canvasId, labels, data, colors) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return null;
  return new Chart(canvas, {
    type: 'doughnut',
    data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth: 2, borderColor: '#fff' }] },
    options: {
      responsive: true, maintainAspectRatio: false, cutout: '60%',
      plugins: { legend: { position: 'right', labels: { font:{size:11}, padding:12, boxWidth:12, boxHeight:12 } } }
    }
  });
}

/* ══════════════════════════════
   TREND / DIST CHARTS (DASHBOARD)
══════════════════════════════ */
function bucketize(entries, mode) {
  const map = new Map();
  entries.forEach(e => {
    const d = new Date(e.timestamp);
    let key;
    if (mode==='daily') key = d.toLocaleDateString('en-US',{month:'short',day:'numeric'});
    else if (mode==='weekly') {
      const s=new Date(d); s.setDate(d.getDate()-d.getDay());
      key = s.toLocaleDateString('en-US',{month:'short',day:'numeric'});
    } else key = d.toLocaleDateString('en-US',{month:'short',year:'numeric'});
    map.set(key,(map.get(key)||0)+1);
  });
  return [...map.entries()].map(([l,c])=>({l,c})).slice(-10);
}

function renderTrendChart() {
  trendChartInst = destroyChart(trendChartInst);
  const entries = loadEntries();
  const mode = document.getElementById('trendSel')?.value || 'daily';
  const buckets = bucketize(entries, mode);
  const canvas = document.getElementById('trendChart');
  const emptyState = document.getElementById('trendEmptyState');
  const hasData = buckets.some(bucket => Number.isFinite(bucket.c) && bucket.c > 0 && bucket.l);
  if (canvas) canvas.style.display = hasData ? '' : 'none';
  if (emptyState) { emptyState.hidden = hasData; emptyState.style.display = hasData ? 'none' : 'grid'; }
  if (!hasData) return;
  trendChartInst = makeBarChart('trendChart', buckets.map(b=>b.l), buckets.map(b=>b.c), 'Feedbacks', '#0f766e');
  renderSpark('sparkTotal', buckets.slice(-7).map(b=>b.c));
}

function renderDistChart() {
  distChartInst = destroyChart(distChartInst);
  const entries = loadEntries();
  const canvas = document.getElementById('sentimentChart');
  const emptyState = document.getElementById('sentimentEmptyState');
  const legend = document.getElementById('sentimentLegend');
  const labels = ['Positive','Neutral','Negative'];
  const colors = ['#10b981','#f59e0b','#ef6a5b'];
  const counts = labels.map(label => entries.filter(e => e.sentimentLabel === label).length);
  const total = entries.length;
  const hasData = counts.some(count => count > 0);
  if (canvas) canvas.style.display = hasData ? '' : 'none';
  if (emptyState) { emptyState.hidden = hasData; emptyState.style.display = hasData ? 'none' : 'grid'; }
  if (legend) { legend.hidden = !hasData; legend.style.display = hasData ? 'grid' : 'none'; legend.innerHTML = labels.map((label, index) => `<div class="sentiment-legend-item"><span class="sentiment-legend-dot" style="background:${colors[index]}"></span><span class="sentiment-legend-name">${label}</span><span class="sentiment-legend-value">${counts[index]} <small>(${total ? Math.round(counts[index] / total * 100) : 0}%)</small></span></div>`).join(''); }
  if (!canvas || !hasData) return;
  distChartInst = new Chart(canvas, {
    type: 'doughnut',
    data: { labels, datasets: [{ data: counts, backgroundColor: colors, borderColor: '#fff', borderWidth: 3 }] },
    options: { responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: { legend: { display: false } } },
  });
}

/* ══════════════════════════════
   REPORT CHARTS
══════════════════════════════ */
function renderReportCharts() {
  const entries = loadEntries();
  rptMonthlyInst     = destroyChart(rptMonthlyInst);
  rptRatingInst      = destroyChart(rptRatingInst);
  rptDeptInst        = destroyChart(rptDeptInst);
  rptSentimentInst   = destroyChart(rptSentimentInst);
  rptSentimentTrendInst = destroyChart(rptSentimentTrendInst);

  // Monthly Feedback Trend
  const monthly = bucketize(entries, 'monthly');
  if (monthly.length) rptMonthlyInst = makeBarChart('rptMonthly', monthly.map(b=>b.l), monthly.map(b=>b.c), 'Feedbacks', '#2563eb');

  // Rating Distribution
  const rDist = {1:0,2:0,3:0,4:0,5:0};
  entries.forEach(e => { const r=Math.round(e.rating); if(r>=1&&r<=5) rDist[r]++; });
  const rCanvas = document.getElementById('rptRating');
  if (rCanvas) {
    rptRatingInst = new Chart(rCanvas, {
      type:'bar',
      data:{ labels:['★1','★2','★3','★4','★5'], datasets:[{ data:Object.values(rDist), backgroundColor:['#fee2e2','#fef3c7','#dbeafe','#dcfce7','#ccfbf1'], borderColor:['#dc2626','#d97706','#2563eb','#16a34a','#0f766e'], borderWidth:1.5, borderRadius:4 }] },
      options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{ x:{grid:{display:false}}, y:{grid:{color:'#f1f5f9'},beginAtZero:true} } }
    });
  }

  // By Department
  const deptC = {};
  entries.forEach(e => { const k=e.department||'Unassigned'; deptC[k]=(deptC[k]||0)+1; });
  const depts = Object.entries(deptC).sort((a,b)=>b[1]-a[1]).slice(0,8);
  if (depts.length) rptDeptInst = makeBarChart('rptDept', depts.map(d=>d[0]), depts.map(d=>d[1]), 'Feedbacks', '#d97706');

  // Sentiment Distribution Pie Chart (NEW — replaces old type pie)
  const sentCnt = { Positive:0, Neutral:0, Negative:0 };
  entries.forEach(e => { if (sentCnt.hasOwnProperty(e.sentimentLabel)) sentCnt[e.sentimentLabel]++; });
  const sentCanvas = document.getElementById('rptSentiment');
  if (sentCanvas && (sentCnt.Positive+sentCnt.Neutral+sentCnt.Negative > 0)) {
    rptSentimentInst = new Chart(sentCanvas, {
      type:'pie',
      data:{ labels:['Positive','Neutral','Negative'], datasets:[{ data:[sentCnt.Positive,sentCnt.Neutral,sentCnt.Negative], backgroundColor:['#16a34a','#64748b','#dc2626'], borderWidth:2, borderColor:'#fff' }] },
      options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'right', labels:{font:{size:11},padding:12,boxWidth:12} } } }
    });
  }

  // Sentiment Trend (stacked line chart — NEW)
  renderSentimentTrendChart();

  // Performance Table — Satisfaction = Positive / Total (new metric)
  const perfBody = document.getElementById('perfBody');
  if (perfBody) {
    const dMap = {};
    entries.forEach(e => {
      const k = e.department||'Unassigned';
      if (!dMap[k]) dMap[k] = {count:0, total:0, positive:0};
      dMap[k].count++; dMap[k].total += e.rating;
      if (e.sentimentLabel === 'Positive') dMap[k].positive++;
    });
    const rows = Object.entries(dMap).map(([l,d]) => ({ l, count:d.count, avg:d.count?d.total/d.count:0, sat:d.count?(d.positive/d.count)*100:0 })).sort((a,b)=>b.avg-a.avg);
    if (!rows.length) { perfBody.innerHTML='<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--ink-3)">No data yet.</td></tr>'; }
    else {
      perfBody.innerHTML = rows.map((r,i) => {
        const scoreClass = r.avg>=4?'hi':r.avg>=3?'mid':r.avg>0?'lo':'na';
        const status = r.avg>=4?'<span class="pill pill-green">High</span>':r.avg>=3?'<span class="pill pill-amber">Moderate</span>':r.avg>0?'<span class="pill pill-red">Low</span>':'<span class="pill pill-slate">—</span>';
        return `<tr><td style="color:var(--ink-3)">${i+1}</td><td><strong>${esc(r.l)}</strong></td>
          <td>${r.count}</td><td><span class="score ${scoreClass}">${r.avg?r.avg.toFixed(1):'—'}</span></td>
          <td><span class="score ${r.sat>=settings.benchmark?'hi':r.sat>=50?'mid':'lo'}">${r.sat.toFixed(0)}%</span></td>
          <td>${status}</td></tr>`;
      }).join('');
    }
  }

  const servicePerfBody = document.getElementById('servicePerfBody');
  if (servicePerfBody) {
    const sMap = {};
    entries.forEach(e => {
      const k = e.serviceId ? `id:${e.serviceId}` : 'unassigned';
      if (!sMap[k]) sMap[k] = {label:e.service||'Unassigned', count:0, ratingTotal:0, ratingCount:0, satisfiedCount:0};
      sMap[k].count++;
      const rating = Number(e.rating);
      if (Number.isFinite(rating) && rating >= 0.5 && rating <= 5) {
        sMap[k].ratingTotal += rating;
        sMap[k].ratingCount++;
        if (rating >= 4) sMap[k].satisfiedCount++;
      }
    });
    const sRows = Object.values(sMap).map(d => ({ l:d.label, count:d.count, avg:d.ratingCount?d.ratingTotal/d.ratingCount:null, sat:d.ratingCount?(d.satisfiedCount/d.ratingCount)*100:null })).sort((a,b)=>(b.avg??-1)-(a.avg??-1));
    if (!sRows.length) { servicePerfBody.innerHTML='<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--ink-3)">No data yet.</td></tr>'; }
    else {
      servicePerfBody.innerHTML = sRows.map((r,i) => {
        const scoreClass = r.avg===null?'na':r.avg>=4?'hi':r.avg>=3?'mid':'lo';
        const status = r.avg===null?'<span class="pill pill-slate">—</span>':r.avg>=4?'<span class="pill pill-green">High</span>':r.avg>=3?'<span class="pill pill-amber">Moderate</span>':'<span class="pill pill-red">Low</span>';
        const satisfactionClass = r.sat===null?'na':r.sat>=settings.benchmark?'hi':r.sat>=50?'mid':'lo';
        const satisfactionDisplay = r.sat===null?'—':`${r.sat.toFixed(0)}%`;
        return `<tr><td style="color:var(--ink-3)">${i+1}</td><td><strong>${esc(r.l)}</strong></td>
          <td>${r.count}</td><td><span class="score ${scoreClass}">${r.avg===null?'—':r.avg.toFixed(1)}</span></td>
          <td><span class="score ${satisfactionClass}">${satisfactionDisplay}</span></td>
          <td>${status}</td></tr>`;
      }).join('');
    }
  }
}

// Sentiment Trend Over Time (stacked bar — monthly/weekly/daily)
function renderSentimentTrendChart() {
  rptSentimentTrendInst = destroyChart(rptSentimentTrendInst);
  const entries = loadEntries();
  const mode = document.getElementById('sentTrendSel')?.value || 'monthly';

  // Build buckets
  const bucketMap = {};
  entries.forEach(e => {
    const d = new Date(e.timestamp);
    let key;
    if (mode==='daily') key = d.toLocaleDateString('en-US',{month:'short',day:'numeric'});
    else if (mode==='weekly') { const s=new Date(d); s.setDate(d.getDate()-d.getDay()); key=s.toLocaleDateString('en-US',{month:'short',day:'numeric'}); }
    else key = d.toLocaleDateString('en-US',{month:'short',year:'numeric'});
    if (!bucketMap[key]) bucketMap[key] = {Positive:0,Neutral:0,Negative:0};
    const lbl = e.sentimentLabel||'';
    if (bucketMap[key].hasOwnProperty(lbl)) bucketMap[key][lbl]++;
  });

  const labels = Object.keys(bucketMap).slice(-10);
  if (!labels.length) return;
  const canvas = document.getElementById('rptSentimentTrend');
  if (!canvas) return;
  rptSentimentTrendInst = new Chart(canvas, {
    type:'bar',
    data:{
      labels,
      datasets:[
        { label:'Positive', data:labels.map(k=>bucketMap[k].Positive), backgroundColor:'rgba(22,163,74,.7)', borderRadius:3 },
        { label:'Neutral',  data:labels.map(k=>bucketMap[k].Neutral),  backgroundColor:'rgba(100,116,139,.6)', borderRadius:3 },
        { label:'Negative', data:labels.map(k=>bucketMap[k].Negative), backgroundColor:'rgba(220,38,38,.7)',  borderRadius:3 },
      ]
    },
    options:{
      responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{ position:'top', labels:{font:{size:11},padding:12,boxWidth:12} } },
      scales:{
        x:{ stacked:true, grid:{display:false}, ticks:{font:{size:10}} },
        y:{ stacked:true, grid:{color:'#f1f5f9'}, beginAtZero:true, ticks:{stepSize:1} }
      }
    }
  });
}

/* ══════════════════════════════
   FEEDBACK TABLE ROW
══════════════════════════════ */
function sentimentPill(label, score = null) {
  const displayLabel = ['Positive', 'Neutral', 'Negative'].includes(label) ? label : 'Neutral';
  const map = { Positive:'pill-positive', Neutral:'pill-neutral', Negative:'pill-negative' };
  const icon = { Positive:'🟢', Neutral:'⚪', Negative:'🔴' };
  const cls = map[displayLabel];
  const pct = score === null || score === '' || Number.isNaN(Number(score)) ? '' : ` <span style="opacity:.85">(${Math.round(Number(score) * 100)}%)</span>`;
  return `<span class="pill ${cls}">${icon[displayLabel]||''} ${esc(displayLabel)}${pct}</span>`;
}

function statusPill(status) {
  const map = { pending:'pill-amber', in_review:'pill-in-review', resolved:'pill-green', archived:'pill-slate' };
  const lbl = { pending:'⏳ Pending', in_review:'🔄 In Review', resolved:'✅ Resolved', archived:'🗄 Archived' };
  return `<span class="pill ${map[status]||'pill-slate'}">${lbl[status]||esc(status)}</span>`;
}

function priorityBadge(priority) {
  return priority === 'High'
    ? `<span class="priority-badge"><i class="fas fa-fire" style="font-size:9px"></i> High</span>`
    : `<span style="font-size:11px;color:var(--ink-3)">Normal</span>`;
}

function starRatingHTML(rating, count = null) {
  if (!rating || rating <= 0) return '—';
  const scoreClass = rating >= 4 ? 'hi' : rating >= 3 ? 'mid' : 'lo';
  const stars = Math.round(rating);
  const starStr = '★'.repeat(stars) + '☆'.repeat(5 - stars);
  return `<span class="score ${scoreClass}" style="display:inline-flex;align-items:center;gap:4px"><span>${starStr}</span> <span>${rating.toFixed(1)}</span></span>${count ? ` <span style="font-size:10px;color:var(--ink-3)">(${count})</span>` : ''}`;
}

function ratingColorClass(rating) {
  if (!rating || rating <= 0) return 'na';
  return rating >= 4 ? 'hi' : rating >= 3 ? 'mid' : 'lo';
}

function sourceBadge(source, name) {
  const cls = source === 'Visitor' ? 'pill-visitor' : 'pill-patient';
  return `<div style="display:flex;flex-direction:column;gap:4px">
    <div><span class="pill ${cls}">${esc(source)}</span></div>
    <div style="font-size:11px;color:var(--ink-3);white-space:nowrap">${esc(name||'Guest')}</div>
  </div>`;
}

// Respondent-type label for the 4 current categories from the public form
// (see form/feedback.php's #resp_type whitelist / respondentTypeWhitelist).
// Records without a recognized respondentType (legacy pre-respondent_type
// rows) show as "Unspecified" rather than being guessed into a category.
const RESPONDENT_CATEGORY_LABELS_INLINE = {
  'Patient / Client': 'Patient / Client',
  'Pasyente/Kliyente': 'Patient / Client',
  "Patient's Companion (Relative)": "Patient's Companion (Relative)",
  'Kauban sa Pasyente': "Patient's Companion (Relative)",
  'Business (visited for business purposes)': 'Business (visited for business purposes)',
  'Bisita': 'Business (visited for business purposes)',
};
function respondentTypeLabel(respondentType) {
  const raw = (respondentType || '').trim();
  if (!raw) return 'Unspecified';
  if (raw.toLowerCase() === 'other') return 'Other';
  return RESPONDENT_CATEGORY_LABELS_INLINE[raw] || 'Other';
}
function respondentTypeBadge(respondentType, name) {
  const label = respondentTypeLabel(respondentType);
  const cls = label.startsWith('Business') ? 'pill-visitor' : label === 'Unspecified' ? 'pill-slate' : 'pill-patient';
  return `<div style="display:flex;flex-direction:column;gap:4px">
    <div><span class="pill ${cls}">${esc(label)}</span></div>
    <div style="font-size:11px;color:var(--ink-3);white-space:nowrap">${esc(name||'Guest')}</div>
  </div>`;
}

function toggleActions(btn) {
  document.querySelectorAll('.dropdown-menu.show').forEach(m => {
    if (m !== btn.nextElementSibling) m.classList.remove('show');
  });
  const menu = btn.nextElementSibling;
  if (menu) menu.classList.toggle('show');
}

document.addEventListener('click', (e) => {
  if (!e.target.closest('.actions-dropdown')) {
    document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
  }
});

function closeInfoTips() {
  document.querySelectorAll('.info-tip-pop.show').forEach(p => {
    p.classList.remove('show');
    p.style.display = 'none';
  });
  document.querySelectorAll('.info-tip-btn.active').forEach(b => {
    b.classList.remove('active');
    b.setAttribute('aria-expanded', 'false');
  });
}

function toggleInfoTip(evt, btn) {
  if (evt) { evt.preventDefault(); evt.stopPropagation(); }
  const pop = btn.nextElementSibling;
  const wasOpen = pop && pop.classList.contains('show');
  closeInfoTips();
  if (!pop || wasOpen) return;

  pop.style.position = 'fixed';
  pop.style.display = 'block';

  const rect = btn.getBoundingClientRect();
  const popWidth = Math.min(300, window.innerWidth - 32);
  pop.style.width = popWidth + 'px';
  let left = rect.right - popWidth;
  left = Math.max(16, Math.min(left, window.innerWidth - popWidth - 16));
  pop.style.left = left + 'px';
  pop.style.top = (rect.bottom + 8) + 'px';

  const arrowLeft = Math.max(12, Math.min(popWidth - 12, (rect.left + rect.width / 2) - left));
  pop.style.setProperty('--arrow-left', arrowLeft + 'px');

  pop.classList.add('show');
  btn.classList.add('active');
  btn.setAttribute('aria-expanded', 'true');
}

document.addEventListener('click', (e) => {
  if (!e.target.closest('.info-tip')) closeInfoTips();
});

window.addEventListener('scroll', () => closeInfoTips(), true);
window.addEventListener('resize', () => closeInfoTips());

function fbRow(e, mode = 'all') {
  const scoreClass = e.rating>=4?'hi':e.rating>=3?'mid':e.rating>0?'lo':'na';
  const ref = esc(e.referenceId||'');
  
  const actionsMenu = `<div style="display:flex;gap:6px;align-items:center">
    <button class="actions-btn" onclick="viewFeedback('${ref}')" style="background:var(--teal-lt);border-color:var(--teal);color:var(--teal-dk);font-weight:600" title="View details"><i class="fas fa-eye"></i></button>
    <div class="actions-dropdown">
      <button class="actions-btn" onclick="toggleActions(this)" title="More options"><i class="fas fa-ellipsis-v"></i></button>
      <div class="dropdown-menu">
        <button class="dropdown-item" onclick="updateStatus('${ref}','in_review')"><i class="fas fa-history"></i> Mark In Review</button>
        <button class="dropdown-item" onclick="updateStatus('${ref}','resolved')"><i class="fas fa-check-circle"></i> Mark Resolved</button>
      </div>
    </div>
  </div>`;

  if (mode === 'patient' || mode === 'visitor') {
    return `<tr>
      <td style="white-space:nowrap;font-size:12px">${esc(e.date||'—')}</td>
      <td>${esc(e.department||'—')}</td>
      <td style="font-size:12px">${esc(e.service||'—')}</td>
      <td>${sentimentPill(e.sentimentLabel, e.sentimentScore)}</td>
      <td><span class="score ${scoreClass}">${e.rating?e.rating.toFixed(1):'—'}</span></td>
      <td>${statusPill(e.status||'pending')}</td>
      <td>${actionsMenu}</td>
    </tr>`;
  }

  const sourceName = e.name || 'Guest';
  const sourceClass = respondentTypeLabel(e.respondentType).startsWith('Business') ? 'pill-visitor' : 'pill-patient';
  return `<tr>
    <td style="white-space:nowrap;font-size:12px">${esc(e.date||'—')}</td>
    <td><span class="pill ${sourceClass}">${esc(respondentTypeLabel(e.respondentType))}</span> <span style="font-size:11px;color:var(--ink-3)">${esc(sourceName)}</span></td>
    <td>${esc(e.department||'—')}</td>
    <td style="font-size:12px">${esc(e.service||'—')}</td>
    <td>${sentimentPill(e.sentimentLabel, e.sentimentScore)}</td>
    <td><span class="score ${scoreClass}">${e.rating?e.rating.toFixed(1):'—'}</span></td>
    <td>${statusPill(e.status||'pending')}</td>
    <td>${actionsMenu}</td>
  </tr>`;
}

function fbCard(e) {
  const sentLabel = e.sentimentLabel || 'Neutral';
  const icClass   = sentLabel==='Positive'?'ft-c': sentLabel==='Negative'?'ft-x':'ft-s';
  const icon      = sentLabel==='Positive'?'fas fa-thumbs-up': sentLabel==='Negative'?'fas fa-thumbs-down':'fas fa-comment';
  const ref     = esc(e.referenceId||'');
  const preview = (e.feedback||e.subject||e.suggestions||'No comment.').slice(0,180);
  const priTag  = e.isHighPriority ? `<span class="priority-badge"><i class="fas fa-fire" style="font-size:9px"></i> High Priority</span>` : '';
  return `<div class="fb-item">
    <div class="fb-ic ${icClass}"><i class="${icon}"></i></div>
    <div class="fb-body">
      <div class="fb-top">
        <span class="fb-dept">${esc(e.department||'Unassigned')}</span>
        ${sentimentPill(sentLabel, e.sentimentScore)}
        ${priTag}
      </div>
      <div class="fb-subj">${esc(e.subject||'General Feedback')}</div>
      <div class="fb-text">${esc(preview)}</div>
      <div class="fb-meta">Ref: ${esc(e.referenceId||'Local')} · ${esc(e.date||'—')}</div>
      <div class="fb-actions">
        ${ ['resolved','adjusted'].includes((e.status||'pending')) ? `
          <button class="q-btn resolved-btn" disabled>✅ Resolved</button>
          <button class="q-btn view-btn" onclick="viewFeedback('${ref}')">View</button>
          <button class="q-btn print-btn" onclick="printFeedback('${ref}')">Print</button>
        ` : `
          <button class="q-btn resolve-btn" onclick="updateStatus('${ref}','resolved')">✔ Resolve</button>
          <button class="q-btn edit-btn" onclick="updateStatus('${ref}','in_review')">🔄 In Review</button>
          <button class="q-btn pending-btn" onclick="updateStatus('${ref}','pending')">Re-open</button>
        ` }
      </div>
    </div>
  </div>`;
}

function handleSearch(q) {
  const active = document.querySelector('.page.active')?.id?.replace('page-respondent-', '');
  if (['patient', 'companion', 'business', 'other'].includes(active)) {
    renderRespondentCategoryTable(active);
  } else if (q) {
    const parent = document.querySelector('[data-page=all-feedbacks]');
    if (parent?.getAttribute('aria-expanded') !== 'true') nav(parent);
  }
}

function renderRecentTable() {
  const body = document.getElementById('recentFbBody');
  if (!body) return;
  let entries = loadEntries();
  entries = entries.slice(0,10);
  body.innerHTML = entries.length ? entries.map(e => fbRow(e, 'all')).join('') : '<tr><td colspan="10" style="text-align:center;padding:20px;color:var(--ink-3)">No feedback yet.</td></tr>';
}

/* ══════════════════════════════
   SERVICES
══════════════════════════════ */
function flatServices() {
  const modules = window.AdminDashboardStore?.getModules?.();
  return Array.isArray(modules?.services) ? modules.services : [];
}

let managedSubservices = [];
let subserviceStatusFilter = 'active';

function renderServices() {
  const body = document.getElementById('servicesTableBody');
  if (!body) { console.warn('servicesTableBody not found'); return; }
  const entries = loadEntries();
  const normS = s => (s||'').trim().toLowerCase();
  const svcStats = {};
  entries.forEach(e=>{
    if(e.service) {
      const k = normS(e.service);
      if(!svcStats[k])svcStats[k]={fbCount:0,total:0};
      svcStats[k].fbCount++;
      svcStats[k].total+=e.rating;
    }
  });
  let rows = flatServices();
  const status = document.getElementById('serviceStatusFilter')?.value || 'active';
  if (status !== 'all') rows = rows.filter(s => (s.status || 'active') === status);
  const q = (document.getElementById('serviceSearch')?.value||'').toLowerCase();
  if (q) rows = rows.filter(s => (s.name||'').toLowerCase().includes(q) || (s.deptName||'').toLowerCase().includes(q));
  console.log('renderServices - flatServices:', rows);
  if (!rows.length) { body.innerHTML='<tr class="service-empty-row"><td colspan="4"><i class="fas fa-hand-holding-medical" style="opacity:.5;margin-right:8px"></i>No services match your search.</td></tr>'; return; }
  body.innerHTML = rows.map(s=>{
    const stat = svcStats[normS(s.name)]||{fbCount:0,total:0};
    const avg = stat.fbCount?(stat.total/stat.fbCount):0;
    const avgHTML = starRatingHTML(avg, stat.fbCount);
    const subtypeHtml = Array.isArray(s.subservices) && s.subservices.length
      ? `<div style="font-size:11px;color:var(--ink-3);margin-top:4px">${s.subservices.length} subservices</div>`
      : '';
    return `<tr class="service-management-row">
      <td data-label="Service"><strong class="service-name">${esc(s.name)}</strong>${subtypeHtml}</td>
      <td data-label="Feedback" class="service-metric-cell"><span class="pill pill-blue service-feedback-count">${stat.fbCount}</span></td>
      <td data-label="Avg rating" class="service-metric-cell">${avgHTML}</td>
      <td data-label="Actions" class="service-action-cell"><div class="actions-dropdown">
        <button class="actions-btn" onclick="toggleActions(this)" aria-label="Actions for ${esc(s.name)}" aria-haspopup="true"><i class="fas fa-ellipsis-v"></i></button>
        <div class="dropdown-menu">
          <button class="dropdown-item" onclick="openServiceModal(${s.id})"><i class="fas fa-edit"></i> Edit</button>
          <button class="dropdown-item" onclick="manageSubservices(${s.id})"><i class="fas fa-list"></i> Manage Subservices</button>
          <button class="dropdown-item text-red" onclick="deleteService(${s.id})"><i class="fas fa-trash"></i> Delete</button>
        </div>
      </div></td>
    </tr>`;
  }).join('');
}

async function manageSubservices(serviceId) {
  const service = flatServices().find(item => Number(item.id) === Number(serviceId));
  if (!service) return;

  document.getElementById('modalBody').innerHTML = '<div style="padding:20px;text-align:center;color:var(--ink-3)">Loading subservices...</div>';
  openModal('Manage Subservices', true);

  try {
    const response = await fetch(`/Feedback/api/admin_modules.php?section=subservices&service_id=${encodeURIComponent(serviceId)}`, {
      headers: { Accept: 'application/json' },
    });
    const payload = await response.json();
    if (!response.ok || !payload.success) throw new Error(payload.message || 'Unable to load subservices.');
    const subservices = Array.isArray(payload.data) ? payload.data : [];
    managedSubservices = subservices;
    const visibleSubservices = subserviceStatusFilter === 'all' ? subservices : subservices.filter(subservice => subservice.status === subserviceStatusFilter);
    const statusFilters = ['active', 'inactive', 'all'].map(status => `<button type="button" class="f-chip ${subserviceStatusFilter === status ? 'active' : ''}" onclick="filterSubservices('${status}', ${Number(serviceId)})">${status === 'all' ? 'All' : status[0].toUpperCase() + status.slice(1)}</button>`).join('');
    const list = visibleSubservices.length
      ? `<div style="max-height:360px;overflow-y:auto;padding-right:6px">${visibleSubservices.map(subservice => { const inactive = subservice.status === 'inactive'; return `<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid var(--line)"><span>${esc(subservice.name)}${inactive ? ' <span class="pill pill-red" style="font-size:10px;padding:2px 8px">Inactive</span>' : ''}</span><div style="display:flex;gap:6px">${inactive ? `<button type="button" class="btn-secondary" onclick="reactivateSubservice(${Number(subservice.id)}, ${Number(serviceId)})">Reactivate</button>` : `<button type="button" class="btn-secondary" onclick="openEditSubserviceModal(${Number(subservice.id)}, ${Number(serviceId)})">Edit</button><button type="button" class="btn-secondary" style="color:#dc2626" onclick="deactivateSubservice(${Number(subservice.id)}, ${Number(serviceId)})">Deactivate</button>`}<button type="button" class="btn-secondary" style="color:#dc2626" onclick="permanentlyDeleteSubservice(${Number(subservice.id)}, ${Number(serviceId)})">Delete</button></div></div>`; }).join('')}</div>`
      : '<div style="padding:20px;text-align:center;color:var(--ink-3)">No subservices match this status.</div>';
    const addButton = service.status === 'active' ? `<button type="button" class="btn-primary" onclick="openAddSubserviceModal(${Number(serviceId)})"><i class="fas fa-plus"></i> Add Sub-service</button>` : '<span style="font-size:12px;color:var(--ink-3)">Reactivate this service before adding sub-services.</span>';
    document.getElementById('modalBody').innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px"><strong>${esc(service.name)}</strong>${addButton}</div><div class="tbl-filters" style="margin-bottom:12px">${statusFilters}</div>${list}`;
  } catch (error) {
    document.getElementById('modalBody').innerHTML = `<div style="padding:20px;text-align:center;color:#dc2626">${esc(error.message)}</div>`;
  }
}

function subserviceForm(serviceId, subservice = null) {
  const services = flatServices();
  const selectedServiceId = Number(subservice?.serviceId || serviceId);
  const options = services.map(service => `<option value="${Number(service.id)}" ${Number(service.id) === selectedServiceId ? 'selected' : ''}>${esc(service.name)}</option>`).join('');
  return `
    <div class="form-group"><label class="form-label" for="mSubserviceName">Sub-service Name *</label><input class="form-input" id="mSubserviceName" maxlength="120" value="${esc(subservice?.name || '')}" placeholder="Enter sub-service name"></div>
    <div class="form-group"><label class="form-label" for="mSubserviceService">Parent Service *</label><select class="form-select" id="mSubserviceService">${options}</select></div>`;
}

function openAddSubserviceModal(serviceId) {
  modalAction = 'createSubservice';
  modalEditId = null;
  document.getElementById('modalBody').innerHTML = subserviceForm(serviceId);
  openModal('Add Sub-service');
}

function openEditSubserviceModal(id, serviceId) {
  const subservice = managedSubservices.find(item => Number(item.id) === Number(id));
  if (!subservice) return AdminUI.toast('Sub-service not found.', 'error');
  modalAction = 'updateSubservice';
  modalEditId = Number(id);
  document.getElementById('modalBody').innerHTML = subserviceForm(serviceId, subservice);
  openModal('Edit Sub-service');
}

function filterSubservices(status, serviceId) {
  subserviceStatusFilter = status;
  manageSubservices(serviceId);
}

async function refreshSubserviceManager(serviceId) {
  await syncCatalogCache();
  await window.AdminDashboardStore?.refreshModules?.(true);
  renderServices();
  modalAction = null;
  modalEditId = null;
  await manageSubservices(serviceId);
}

async function deactivateSubservice(id, serviceId) {
  const subservice = managedSubservices.find(item => Number(item.id) === Number(id));
  const label = subservice?.name || `sub-service #${id}`;
  if (!(await AdminUI.confirm({
    title: 'Deactivate sub-service?',
    message: `Deactivate "${label}"? It will no longer be available for new feedback, but its history will be preserved.`,
    confirmLabel: 'Deactivate',
  }))) return;
  try {
    await catalogPost('deactivate_subservice', { id: Number(id) });
    addAudit('admin', 'Deactivated sub-service', String(id), 'Sub-service deactivated');
    await refreshSubserviceManager(serviceId);
    AdminUI.toast(`"${label}" was deactivated.`, 'success');
  } catch (e) {
    AdminUI.toast(e.message || 'Unable to deactivate sub-service.', 'error');
  }
}

async function reactivateSubservice(id, serviceId) {
  try {
    await catalogPost('reactivate_subservice', { id: Number(id) });
    addAudit('admin', 'Reactivated sub-service', String(id), 'Sub-service reactivated');
    await refreshSubserviceManager(serviceId);
    AdminUI.toast('Sub-service reactivated.', 'success');
  } catch (e) {
    AdminUI.toast(e.message || 'Unable to reactivate sub-service.', 'error');
  }
}

async function permanentlyDeleteSubservice(id, serviceId) {
  const subservice = managedSubservices.find(item => Number(item.id) === Number(id));
  const label = subservice?.name || `sub-service #${id}`;
  if (!(await AdminUI.confirm({
    title: 'Permanently delete sub-service?',
    message: `Permanently delete "${label}"? This cannot be undone. If it has feedback history, deletion will be blocked and you should deactivate it instead.`,
    confirmLabel: 'Delete permanently',
    tone: 'danger',
  }))) return;
  try {
    await catalogPost('delete_subservice_permanently', { id: Number(id) });
    addAudit('admin', 'Permanently deleted sub-service', String(id), label);
    await refreshSubserviceManager(serviceId);
    AdminUI.toast(`"${label}" was permanently deleted.`, 'success');
  } catch (e) {
    AdminUI.toast(e.message || 'Unable to permanently delete sub-service.', 'error');
  }
}

async function deleteService(id) {
  const service = flatServices().find(item => Number(item.id) === Number(id));
  const label = service?.name || `service #${id}`;
  if (!(await AdminUI.confirm({
    title: 'Deactivate service?',
    message: `Deactivate "${label}"? It will no longer be available for new feedback, but existing feedback will be preserved.`,
    confirmLabel: 'Deactivate',
  }))) return;
  try {
    await catalogPost('deleteService',{id});
    addAudit('admin','Deleted service',String(id),'Service removed');
    await window.AdminDashboardStore?.refreshModules?.(true);
    renderServices();
    AdminUI.toast(`"${label}" was deactivated.`, 'success');
  }
  catch(e) { AdminUI.toast(e.message || 'Unable to deactivate service.', 'error'); }
}

async function reactivateService(id) {
  try {
    await catalogPost('reactivateService',{id});
    addAudit('admin','Reactivated service',String(id),'Service reactivated');
    await window.AdminDashboardStore?.refreshModules?.(true);
    renderServices();
    AdminUI.toast('Service reactivated.', 'success');
  }
  catch(e) { AdminUI.toast(e.message || 'Unable to reactivate service.', 'error'); }
}

async function permanentlyDeleteService(id) {
  const service = flatServices().find(item => Number(item.id) === Number(id));
  const label = service?.name || `service #${id}`;
  if (!(await AdminUI.confirm({
    title: 'Permanently delete service?',
    message: `Permanently delete "${label}"? This cannot be undone. If it has feedback history or sub-services, deletion will be blocked and you should deactivate it instead.`,
    confirmLabel: 'Delete permanently',
    tone: 'danger',
  }))) return;
  try {
    await catalogPost('deleteServicePermanently',{id});
    addAudit('admin','Permanently deleted service',String(id),label);
    await window.AdminDashboardStore?.refreshModules?.(true);
    renderServices();
    AdminUI.toast(`"${label}" was permanently deleted.`, 'success');
  }
  catch(e) { AdminUI.toast(e.message || 'Unable to permanently delete service.', 'error'); }
}

/* ══════════════════════════════
   MODAL SYSTEM
══════════════════════════════ */
function openModal(title, hideSave = false) {
  document.getElementById('modalTitle').textContent = title;
  const saveBtn = document.getElementById('modalSave');
  if (saveBtn) saveBtn.style.display = hideSave ? 'none' : 'inline-flex';
  const delBtn = document.getElementById('modalDelete');
  if (delBtn) delBtn.style.display = 'none';
  document.getElementById('modalOverlay').classList.add('open');
}
function closeModal() {
  document.getElementById('modalOverlay').classList.remove('open');
  modalAction = null; modalEditId = null;
  const saveBtn = document.getElementById('modalSave');
  if (saveBtn) saveBtn.style.display = 'inline-flex';
  const delBtn = document.getElementById('modalDelete');
  if (delBtn) delBtn.style.display = 'none';
}

function legacyViewFeedback(refId) {
  const entry = loadEntries().find(e => e.referenceId === refId);
  if (!entry) return AdminUI.toast('Feedback not found.', 'error');
  
  const scoreClass = entry.rating>=4?'hi':entry.rating>=3?'mid':entry.rating>0?'lo':'na';
  
  document.getElementById('modalBody').innerHTML = `
    <div class="modal-detail-grid">
      <div class="detail-item"><div class="detail-lbl">Respondent Type</div><div class="detail-val">${respondentTypeBadge(entry.respondentType, entry.name || 'Guest')}</div></div>
      <div class="detail-item"><div class="detail-lbl">Date</div><div class="detail-val">${esc(entry.date||'—')}</div></div>
      <div class="detail-item"><div class="detail-lbl">Service</div><div class="detail-val">${esc(entry.service||'—')}</div></div>
      <div class="detail-item"><div class="detail-lbl">Rating</div><div class="detail-val"><span class="score ${scoreClass}">${entry.rating?entry.rating.toFixed(1):'—'}</span></div></div>
      <div class="detail-item"><div class="detail-lbl">Sentiment</div><div class="detail-val">${sentimentPill(entry.sentimentLabel, entry.sentimentScore)}</div></div>
      <div class="detail-item"><div class="detail-lbl">Confidence</div><div class="detail-val">${entry.sentimentScore !== null && entry.sentimentScore !== undefined ? Math.round(Number(entry.sentimentScore) * 100) + '%' : '—'}</div></div>
      <div class="detail-item"><div class="detail-lbl">Status</div><div class="detail-val">${statusPill(entry.status||'pending')}</div></div>
    </div>
    <div class="detail-item">
      <div class="detail-lbl">Message</div>
      <div class="detail-msg">${esc(entry.feedback||entry.subject||entry.suggestions||'No message provided.')}</div>
    </div>
  `;
  openModal('Feedback Details', true);
}

async function viewFeedback(refId) {
  if (!refId) return AdminUI.toast('Feedback not found.', 'error');
  document.getElementById('modalBody').innerHTML = '<p style="margin:8px 0;color:var(--ink-2)">Loading feedback details...</p>';
  openModal('Feedback Details', true);

  try {
    const response = await fetch('/Feedback/api/feedback_detail.php?referenceId=' + encodeURIComponent(refId), {
      headers: { 'Accept': 'application/json' }
    });
    const payload = await response.json();
    if (!response.ok || !payload.success || !payload.data?.entry) throw new Error(payload.message || 'Feedback not found.');

    const entry = payload.data.entry;
    const value = raw => raw === null || raw === undefined || String(raw).trim() === '' ? 'Not provided' : esc(raw);
    const item = (label, content) => `<div class="detail-item"><div class="detail-lbl">${label}</div><div class="detail-val">${content}</div></div>`;
    const section = (title, content) => `<section style="margin:0 0 18px"><h3 style="font-size:14px;margin:0 0 9px;color:var(--emerald-700,#047857)">${title}</h3>${content}</section>`;
    const grid = content => `<div class="modal-detail-grid">${content}</div>`;
    const score = Number(entry.rating || 0);
    const scoreClass = score >= 4 ? 'hi' : score >= 3 ? 'mid' : score > 0 ? 'lo' : 'na';
    const ratingRows = ratings => ratings.length ? `<div style="display:grid;gap:7px">${ratings.map(r =>
      `<div style="display:flex;justify-content:space-between;gap:18px;border-bottom:1px solid #e5e7eb;padding:6px 0"><span>${esc(r.label)}</span><strong>${esc(r.value)}</strong></div>`
    ).join('')}</div>` : '<div class="detail-val">Not provided</div>';
    const ratings = Array.isArray(entry.ratingsBreakdown) ? entry.ratingsBreakdown : [];
    const experienceRatings = ratings.filter(r => !/^st\\d+$/.test(String(r.key)));
    const staffRatings = ratings.filter(r => /^st\\d+$/.test(String(r.key)));
    const respondentDetails = [
      item('Respondent type', value(entry.respondentType)),
      entry.respondentTypeOther ? item('Other respondent type', value(entry.respondentTypeOther)) : '',
      item('Age', value(entry.age)), item('Sex', value(entry.sex)), item('Gender preference', value(entry.genderPreference)),
      entry.genderPreferenceOther ? item('Gender preference (Other)', value(entry.genderPreferenceOther)) : '',
      item('Religion', value(entry.religion)), entry.religionOther ? item('Religion (Other)', value(entry.religionOther)) : '',
      item('Education', value(entry.educationLevel)),
    ].join('');
    const visitDetails = [
      item('Facility', value(entry.facility)), item('Visit date', value(entry.visitDate)), item('Service', value(entry.service)),
      item('Sub-service', value(entry.subservice)), entry.serviceOther ? item('Other service', value(entry.serviceOther)) : '',
      entry.serviceSubtypeOther ? item('Other sub-service', value(entry.serviceSubtypeOther)) : '', item('Visit frequency', value(entry.visitFrequency)),
    ].join('');
    const charter = entry.citizensCharter || {};

    document.getElementById('modalBody').innerHTML = [
      section('Respondent Information', grid(respondentDetails)),
      section('Visit / Service Information', grid(visitDetails)),
      section('Citizen\'s Charter', grid(item('CC1', value(charter.cc1)) + item('CC2', value(charter.cc2)) + item('CC3', value(charter.cc3)))),
      section('Experience Ratings', ratingRows(experienceRatings)),
      section('Staff Role Ratings', ratingRows(staffRatings)),
      section('Written Feedback', grid(
        item('Comment', `<div class="detail-msg">${value(entry.feedback)}</div>`) +
        item('Name of Staff to Thank or Commend', `<div class="detail-msg">${value(entry.appreciation)}</div>`) +
        item('Suggestion', `<div class="detail-msg">${value(entry.suggestions)}</div>`)
      )),
      section('System Analysis', grid(
        item('Average rating', score > 0 ? `<span class="score ${scoreClass}">${score.toFixed(1)} / 5.0</span>` : 'Not provided') +
        item('Sentiment', sentimentPill(entry.sentimentLabel, entry.sentimentScore)) +
        item('Confidence', entry.sentimentScore !== null && entry.sentimentScore !== undefined ? Math.round(Number(entry.sentimentScore) * 100) + '%' : 'Not provided') +
        item('Status', statusPill(entry.status || 'pending'))
      )),
    ].join('');
  } catch (error) {
    document.getElementById('modalBody').innerHTML = `<p style="margin:8px 0;color:#b91c1c">${esc(error.message || 'Unable to load feedback details.')}</p>`;
  }
}

function printFeedback(refId) {
  if (!refId) return AdminUI.toast('No reference ID.', 'error');
  // Open feedback detail print view in a new window/tab
  const url = '/Feedback/admin/feedback_detail.php?ref=' + encodeURIComponent(refId) + '&print=1';
  window.open(url, '_blank');
}

function openServiceModal(id) {
  modalAction = 'service';
  modalEditId = id || null;
  const svc = id ? flatServices().find(s=>s.id===Number(id)) : null;
  document.getElementById('modalBody').innerHTML = `
    <div class="form-group"><label class="form-label" for="mSvcName">Service Name *</label><input class="form-input" id="mSvcName" value="${esc(svc?.name||'')}" placeholder="Enter service name"></div>`;
  openModal(id ? 'Edit Service' : 'Add Service');
}

async function staffRolesPost(action, data) {
  const r = await fetch('/Feedback/api/staff_roles.php', {
    method:'POST',
    headers:csrfHeaders({'Content-Type':'application/json','Accept':'application/json'}),
    body: JSON.stringify({ action, ...data })
  });
  const p = await r.json();
  if (!p.success) throw new Error(p.message || 'Staff role update failed.');
  return p.data ? p.data.staffRoles : null;
}

function getCachedStaffRole(id) {
  const roles = (window.AdminDashboardStore?.getModules().staffRoles) || [];
  return roles.find(r => r.id === Number(id)) || null;
}

async function refreshStaffRolesUI() {
  if (window.AdminDashboardStore) await window.AdminDashboardStore.refreshModules(true);
  if (window.renderStaffTable) window.renderStaffTable();
}

function staffRoleFormFields(label, isActive) {
  return `
    <div class="form-group"><label class="form-label" for="mStaffLabel">Staff Role *</label><input class="form-input" id="mStaffLabel" value="${esc(label||'')}" maxlength="120" placeholder="e.g. Physical Therapist"></div>
    <div class="form-group"><label class="form-label" for="mStaffActive">Status</label>
      <select class="form-select" id="mStaffActive">
        <option value="1" ${isActive!==false?'selected':''}>Active</option>
        <option value="0" ${isActive===false?'selected':''}>Inactive</option>
      </select>
    </div>`;
}

// Staff roles are the categories rated on every feedback form (st0, st1,
// ...). Unlike Services, the set isn't fixed — Add Staff creates a brand
// new rating category (see api/staff_roles.php's createRole, which mints
// the next stN key), so it immediately becomes ratable on NEW submissions.
function openAddStaffModal() {
  modalAction = 'addStaffRole';
  modalEditId = null;
  document.getElementById('modalBody').innerHTML = staffRoleFormFields('', true);
  openModal('Add Staff');
}

function openEditStaffRoleModal(id) {
  const role = getCachedStaffRole(id);
  if (!role) return AdminUI.toast('Staff role not found.', 'error');
  modalAction = 'editStaffRole';
  modalEditId = id;
  document.getElementById('modalBody').innerHTML = staffRoleFormFields(role.label, role.isActive);
  openModal('Edit Staff Role');
}

function openDeleteStaffRoleModal(id) {
  const role = getCachedStaffRole(id);
  if (!role) return AdminUI.toast('Staff role not found.', 'error');
  modalAction = 'deleteStaffRole';
  modalEditId = id;
  document.getElementById('modalBody').innerHTML = `
    <p style="font-size:13px;color:var(--ink-2);margin:0 0 16px;line-height:1.5">Are you sure you want to delete "<strong>${esc(role.label)}</strong>"? If it has existing feedback ratings, it will be safely deactivated instead — historical ratings are never lost.</p>`;
  openModal('Delete Staff Role?', true);
  const delBtn = document.getElementById('modalDelete');
  if (delBtn) delBtn.style.display = 'inline-flex';
}

async function confirmDeleteStaffRole(id) {
  const roleId = id || modalEditId;
  if (!roleId) return;
  try {
    await staffRolesPost('deleteRole', { id: Number(roleId) });
    addAudit('admin','Deleted staff role',String(roleId),'—');
    closeModal();
    await refreshStaffRolesUI();
    AdminUI.toast('Staff role updated.', 'success');
  } catch(e) { AdminUI.toast(e.message || 'Delete failed.', 'error'); }
}

async function handleModalSave() {
  if (!modalAction) return;
  try {
    if (modalAction==='service') {
      const input = document.getElementById('mSvcName');
      const name = input.value.trim();
      if (!name) {
        AdminUI.setFieldError(input, 'Service name is required.');
        input.focus();
        return;
      }
      const isEditing = Boolean(modalEditId);
      if (isEditing) { await catalogPost('updateService',{id:modalEditId,name}); addAudit('admin','Updated service',String(modalEditId),name); }
      else { await catalogPost('createService',{name}); addAudit('admin','Created service','—',name); }
      await window.AdminDashboardStore?.refreshModules?.(true);
      closeModal(); renderServices();
      AdminUI.toast(isEditing ? 'Service updated.' : 'Service added.', 'success');
    }
    if (modalAction==='createSubservice' || modalAction==='updateSubservice') {
      const nameInput = document.getElementById('mSubserviceName');
      const serviceInput = document.getElementById('mSubserviceService');
      const name = nameInput?.value.trim() || '';
      const serviceId = Number(serviceInput?.value || 0);
      if (!name || !Number.isInteger(serviceId) || serviceId < 1) {
        if (!name) AdminUI.setFieldError(nameInput, 'Sub-service name is required.');
        if (!Number.isInteger(serviceId) || serviceId < 1) AdminUI.setFieldError(serviceInput, 'Parent service is required.');
        (!name ? nameInput : serviceInput)?.focus();
        return;
      }
      const action = modalAction === 'createSubservice' ? 'create_subservice' : 'update_subservice';
      const data = modalAction === 'createSubservice'
        ? { name, service_id: serviceId }
        : { id: Number(modalEditId), name, service_id: serviceId };
      await catalogPost(action, data);
      addAudit('admin', modalAction === 'createSubservice' ? 'Created sub-service' : 'Updated sub-service', String(data.id || '—'), name);
      await refreshSubserviceManager(serviceId);
      AdminUI.toast(action === 'create_subservice' ? 'Sub-service added.' : 'Sub-service updated.', 'success');
    }
    if (modalAction==='addStaffRole' || modalAction==='editStaffRole') {
      const labelInput = document.getElementById('mStaffLabel');
      const label = labelInput.value.trim();
      const isActive = document.getElementById('mStaffActive').value === '1';
      if (!label) {
        AdminUI.setFieldError(labelInput, 'Staff role is required.');
        labelInput.focus();
        return;
      }
      const isAdding = modalAction === 'addStaffRole';
      if (isAdding) {
        await staffRolesPost('createRole', { label, isActive });
        addAudit('admin','Added staff role','—',label);
      } else {
        await staffRolesPost('updateRole', { id: Number(modalEditId), label, isActive });
        addAudit('admin','Updated staff role',String(modalEditId),label);
      }
      closeModal();
      await refreshStaffRolesUI();
      AdminUI.toast(isAdding ? 'Staff role added.' : 'Staff role updated.', 'success');
    }
    renderAll();
  } catch(e) {
    const message = e.message || 'Operation failed.';
    if (/already|duplicate|exists/i.test(message)) {
      const input = modalAction === 'service'
        ? document.getElementById('mSvcName')
        : modalAction === 'createSubservice' || modalAction === 'updateSubservice'
          ? document.getElementById('mSubserviceName')
          : document.getElementById('mStaffLabel');
      if (input) {
        AdminUI.setFieldError(input, message);
        input.focus();
        return;
      }
    }
    AdminUI.toast(message, 'error');
  }
}

/* ══════════════════════════════
   NOTIFICATIONS
══════════════════════════════ */
function renderNotifs(entries) {
  const el = document.getElementById('dashboardNotifList');
  if (!el) return;
  let notifs = [];
  const highPrio = entries.filter(e => e.isHighPriority);
  const pending  = entries.filter(e => (e.status||'pending')==='pending');
  const negative = entries.filter(e => e.sentimentLabel === 'Negative');
  const negPct   = entries.length ? (negative.length/entries.length)*100 : 0;

  if (highPrio.length) notifs.push({
    icon:'fas fa-fire', cls:'ic-red',
    title:`🔴 ${highPrio.length} High Priority Feedback${highPrio.length>1?'s':''}`,
    desc:'Negative sentiment + low rating. Immediate review needed.',
    time:'Now'
  });
  if (pending.length > 0 && pending.length >= settings.threshold) notifs.push({
    icon:'fas fa-clock', cls:'ic-amber',
    title:'⏳ High Pending Queue',
    desc:`${pending.length} feedbacks awaiting review (threshold: ${settings.threshold}).`,
    time:'Now'
  });
  if (negative.length > 0 && negPct >= settings.negPct && entries.length > 0) notifs.push({
    icon:'fas fa-exclamation-triangle', cls:'ic-amber',
    title:'⚠️ High Negative Sentiment Rate',
    desc:`${negPct.toFixed(0)}% negative feedback rate exceeds ${settings.negPct}% threshold.`,
    time:'Now'
  });

  if (!notifs.length) {
    el.innerHTML = '<div class="empty-state"><i class="fas fa-comment-dots"></i><p>No recent experience found.</p></div>';
    return;
  }
  const nq = (document.getElementById('notifSearch')?.value||'').toLowerCase();
  if (nq) {
    notifs = notifs.filter(n => ((n.title||'') + ' ' + (n.desc||'')).toLowerCase().includes(nq));
    if (!notifs.length) {
      el.innerHTML = '<div class="empty-state"><i class="fas fa-comment-dots"></i><p>No recent experience match your search.</p></div>';
      return;
    }
  }
  el.innerHTML = notifs.map(n => `<div class="notif-item"><div class="notif-icon ${n.cls}"><i class="${n.icon}"></i></div><div class="notif-body"><div class="notif-title">${esc(n.title)}</div><div class="notif-desc">${esc(n.desc)}</div></div><div class="notif-time">${n.time}</div></div>`).join('');
}

/* ══════════════════════════════
   MASTER RENDER
══════════════════════════════ */
function renderDashboard() {
  const entries = loadEntries();
  const total    = entries.length;
  const pending  = entries.filter(e=>(e.status||'pending')==='pending').length;
  const inRev    = entries.filter(e=>e.status==='in_review').length;
  const resolved = entries.filter(e=>e.status==='resolved').length;

  // Satisfaction = Positive Feedbacks / Total (replaces old compliment-based calc)
  const positive = entries.filter(e=>e.sentimentLabel==='Positive').length;
  const satPct   = total ? Math.round((positive/total)*100) : null;

  // Resolution Rate = Resolved / Total
  const resolvePct = total ? Math.round((resolved/total)*100) : null;

  const shortText = (value, limit = 120) => {
    const text = String(value || '').replace(/\s+/g, ' ').trim();
    if (!text) return '';
    return text.length > limit ? text.slice(0, limit - 1).trim() + '…' : text;
  };

  const latestByText = (list, extractor) => {
    const items = list
      .map(entry => ({ entry, time: new Date(entry.timestamp || 0).getTime() || 0, text: shortText(extractor(entry)) }))
      .filter(item => item.text);
    items.sort((a, b) => b.time - a.time);
    return items[0]?.text || '';
  };

  // Badges
  document.getElementById('badge-all').textContent = total; document.getElementById('badge-all').classList.toggle('hide',!total);
  document.getElementById('badge-pending').textContent = pending; document.getElementById('badge-pending').classList.toggle('hide',!pending);

  // KPI values
  document.getElementById('valTotal').textContent = total;
  document.getElementById('valPending').textContent = pending;
  document.getElementById('valResolved').textContent = resolved;
  document.getElementById('valSat').textContent = satPct !== null ? satPct+'%' : '—%';
  const rrEl = document.getElementById('valResRate');
  if (rrEl) rrEl.textContent = resolvePct !== null ? resolvePct+'%' : '—%';

  const reportSatValue = document.getElementById('reportSatValue');
  if (reportSatValue) reportSatValue.textContent = satPct !== null ? satPct + '%' : '—%';
  const reportSatStatus = document.getElementById('reportSatStatus');
  if (reportSatStatus) {
    reportSatStatus.className = 'report-status ' + (satPct === null ? '' : satPct >= settings.benchmark ? 'report-status-good' : 'report-status-low');
    reportSatStatus.textContent = satPct === null ? 'No Data' : satPct >= settings.benchmark ? 'Good' : 'Low';
  }
  const reportSatDetail = document.getElementById('reportSatDetail');
  if (reportSatDetail) reportSatDetail.textContent = total ? `Positive: ${positive} of ${total}` : 'Positive: 0 of 0';
  const reportSatMeta = document.getElementById('reportSatMeta');
  if (reportSatMeta) reportSatMeta.textContent = total ? `Benchmark: ${settings.benchmark}% | Current: ${satPct}%` : `Benchmark: ${settings.benchmark}% | Current: —`;

  const reportResValue = document.getElementById('reportResValue');
  if (reportResValue) reportResValue.textContent = resolvePct !== null ? resolvePct + '%' : '—%';
  const reportResStatus = document.getElementById('reportResStatus');
  if (reportResStatus) {
    reportResStatus.className = 'report-status ' + (resolvePct === null ? '' : resolvePct >= 80 ? 'report-status-good' : 'report-status-low');
    reportResStatus.textContent = resolvePct === null ? 'No Data' : resolvePct >= 80 ? 'On Track' : 'Low';
  }
  const reportResDetail = document.getElementById('reportResDetail');
  if (reportResDetail) reportResDetail.textContent = total ? `Resolved: ${resolved} of ${total}` : 'Resolved: 0 of 0';
  const reportResMeta = document.getElementById('reportResMeta');
  if (reportResMeta) reportResMeta.textContent = total ? `Target: 80% | Current: ${resolvePct}%` : 'Target: 80% | Current: —';

  const experienceEntries = entries.filter(e => String(e.feedback || e.subject || '').trim());
  const suggestionEntries = entries.filter(e => String(e.suggestions || '').trim());
  const experienceSummaryText = document.getElementById('experienceSummaryText');
  const experienceSummaryMeta = document.getElementById('experienceSummaryMeta');
  if (experienceSummaryText) {
    const latestExperience = latestByText(experienceEntries, entry => entry.feedback || entry.subject || '');
    experienceSummaryText.textContent = experienceEntries.length
      ? (latestExperience ? `Latest experience note: ${latestExperience}` : 'Experience comments are present, but no readable text was found.')
      : 'No experience entries yet.';
  }
  if (experienceSummaryMeta) {
    experienceSummaryMeta.textContent = experienceEntries.length
      ? `${experienceEntries.length} experience entr${experienceEntries.length === 1 ? 'y' : 'ies'} captured in recent submissions.`
      : 'Based on recent feedback comments.';
  }

  const suggestionsSummaryText = document.getElementById('suggestionsSummaryText');
  const suggestionsSummaryMeta = document.getElementById('suggestionsSummaryMeta');
  if (suggestionsSummaryText) {
    const latestSuggestion = latestByText(suggestionEntries, entry => entry.suggestions || '');
    suggestionsSummaryText.textContent = suggestionEntries.length
      ? (latestSuggestion ? `Latest suggestion: ${latestSuggestion}` : 'Suggestions are present, but no readable text was found.')
      : 'No suggestions yet.';
  }
  if (suggestionsSummaryMeta) {
    suggestionsSummaryMeta.textContent = suggestionEntries.length
      ? `${suggestionEntries.length} suggestion${suggestionEntries.length !== 1 ? 's' : ''} captured in recent submissions.`
      : 'Based on submitted improvement suggestions.';
  }

  document.getElementById('helperTotal').textContent = total ? `${total} patient submissions` : 'Awaiting first submission';
  document.getElementById('helperPending').textContent = `${pending} awaiting admin action${inRev?' | '+inRev+' in review':''}`;
  document.getElementById('helperResolved').textContent = total ? `${resolvePct}% resolution rate` : 'No resolutions yet';
  document.getElementById('helperSat').textContent = satPct !== null
    ? (satPct>=settings.benchmark ? `✓ Above ${settings.benchmark}% benchmark` : `↓ Below ${settings.benchmark}% benchmark`)
    : 'Awaiting positive feedback';
  const rrHelper = document.getElementById('helperResRate');
  if (rrHelper) rrHelper.textContent = resolvePct !== null ? `${resolved} of ${total} resolved` : 'No resolutions yet';

  const pendingStatus = pending===0?{cls:'ks-ok',txt:'Cleared'}:pending>settings.threshold?{cls:'ks-alert',txt:'Overloaded'}:{cls:'ks-warn',txt:'Review'};
  document.getElementById('statusPending').className='kpi-status '+pendingStatus.cls;
  document.getElementById('statusPending').textContent = pendingStatus.txt;

  const satStatus = satPct===null?{cls:'ks-neutral',txt:'—'}:satPct>=settings.benchmark?{cls:'ks-ok',txt:'Good'}:satPct>=50?{cls:'ks-warn',txt:'Fair'}:{cls:'ks-alert',txt:'Low'};
  document.getElementById('statusSat').className='kpi-status '+satStatus.cls;
  document.getElementById('statusSat').textContent = satStatus.txt;

  const tResolvedEl = document.getElementById('tResolved');
  if (tResolvedEl) { tResolvedEl.textContent=`${resolvePct??0}% rate`; tResolvedEl.className='kpi-trend '+(resolvePct>=80?'t-good':resolvePct>=40?'t-warn':'t-alert'); }

  const tSatEl = document.getElementById('tSat');
  if (tSatEl) { tSatEl.textContent=satPct!==null?`Benchmark: ${settings.benchmark}% | Current: ${satPct}%`:'Benchmark: '+settings.benchmark+'%'; tSatEl.className='kpi-trend '+(satPct===null?'t-neu':satPct>=settings.benchmark?'t-good':satPct>=50?'t-warn':'t-alert'); }

  const rrStatus = document.getElementById('statusResRate');
  if (rrStatus) { rrStatus.className='kpi-status '+(resolvePct>=80?'ks-ok':resolvePct>=40?'ks-warn':'ks-alert'); rrStatus.textContent=(resolvePct>=80?'On Track':resolvePct>=40?'Moderate':'Low'); }
  const tRR = document.getElementById('tResRate');
  if (tRR) { tRR.textContent=`Target: 80% | Current: ${resolvePct??0}%`; tRR.className='kpi-trend '+(resolvePct>=80?'t-good':resolvePct>=40?'t-warn':'t-alert'); }    // Insights
  const deptMap = {};
  entries.forEach(e=>{ const k=e.department||'Unassigned'; if(!deptMap[k])deptMap[k]={c:0,t:0}; deptMap[k].c++;deptMap[k].t+=e.rating; });
  const deptStats = Object.entries(deptMap).map(([l,d])=>({l,c:d.c,avg:d.c?d.t/d.c:0})).sort((a,b)=>b.avg-a.avg);
  const best = deptStats[0];
  document.getElementById('insBestDept').textContent = best ? `${best.l} (${best.avg.toFixed(1)}★)` : '—';
  document.getElementById('insBestDeptMeta').textContent = best ? `${best.c} submission${best.c!==1?'s':''}` : 'Not enough data yet';

  const serviceMap = {};
  entries.forEach(e=>{ const k=e.serviceId ? `id:${e.serviceId}` : 'unassigned'; if(!serviceMap[k]) serviceMap[k]={l:(e.service||'Unassigned').trim(),c:0,t:0}; serviceMap[k].c++; serviceMap[k].t+=e.rating; });
  const topService = Object.values(serviceMap).map(d=>({l:d.l,c:d.c,avg:d.c?d.t/d.c:0})).sort((a,b)=>b.c-a.c || b.avg-a.avg)[0];
  document.getElementById('insTopService').textContent = topService ? `${topService.l} (${topService.c})` : '—';
  document.getElementById('insTopServiceMeta').textContent = topService ? `${topService.c} submission${topService.c!==1?'s':''} across feedback entries` : 'No service submissions yet';

  const lowArea = deptStats.filter(d=>d.avg>0&&d.avg<3).sort((a,b)=>a.avg-b.avg)[0];
  document.getElementById('insLowArea').textContent = lowArea ? `${lowArea.l} (${lowArea.avg.toFixed(1)}★)` : 'None';
  document.getElementById('insLowAreaMeta').textContent = lowArea ? `${lowArea.c} entries rated below 3.0` : 'All departments performing well';

  const now = Date.now(), MS30 = 30*24*60*60*1000;
  const cur = entries.filter(e=>new Date(e.timestamp).getTime()>=(now-MS30)).length;
  const prev = entries.filter(e=>{const t=new Date(e.timestamp).getTime();return t>=(now-2*MS30)&&t<(now-MS30);}).length;
  const trendEl = document.getElementById('insTrend');
  const trendMeta = document.getElementById('insTrendMeta');
  if (total) {
    trendEl.textContent = cur>prev?`↑ Up (+${cur-prev} more)`:cur<prev?`↓ Down (${prev-cur} fewer)`:'→ Stable';
    trendMeta.textContent = `${cur} this month vs ${prev} previous`;
  } else { trendEl.textContent='—'; trendMeta.textContent='Last 30 vs previous 30 days'; }

  // Comments
  const commentEl = document.getElementById('commentList');
  if (commentEl) {
    const comments = entries.filter(e=>e.feedback?.trim()).slice(0,5);
    commentEl.innerHTML = comments.length ? comments.map(e=>`<div class="comment-item"><div class="c-who">${esc(e.name||'Guest')} <span class="c-dept">· ${esc(e.department||'Unassigned')}</span></div><div class="c-text">${esc(e.feedback.slice(0,120))}</div></div>`).join('') : '<div class="empty-state" style="padding:16px"><i class="far fa-comment-dots"></i><p>No comments yet.</p></div>';
  }

  // Top depts
  const topDeptEl = document.getElementById('topDeptList');
  if (topDeptEl) {
    const td = deptStats.slice(0,5);
    topDeptEl.innerHTML = td.length ? td.map((d,i)=>`<div class="comment-item"><div class="c-who" style="display:flex;align-items:center;justify-content:space-between"><span style="display:flex;align-items:center;gap:6px"><span style="font-size:11px;color:var(--ink-3)">#${i+1}</span> ${esc(d.l)}</span><span class="score ${d.avg>=4?'hi':d.avg>=3?'mid':d.avg>0?'lo':'na'}" style="font-size:11px">${d.avg.toFixed(1)}</span></div><div class="c-text">${d.c} feedback${d.c!==1?'s':''}</div></div>`).join('') : '<div class="empty-state" style="padding:16px"><i class="fas fa-hospital"></i><p>No data yet.</p></div>';
  }

  // Queue
  const queueEl = document.getElementById('queueList');
  if (queueEl) {
    const pQueue = entries.filter(e=>(e.status||'pending')==='pending').slice(0,6);
    queueEl.innerHTML = pQueue.length ? pQueue.map(e=>`<div class="queue-item"><div class="qi-info"><strong>${esc(e.department||'Unassigned')} · ${esc(e.service||'General')}</strong><p>${esc(e.subject||e.feedback||'Feedback pending review.')}</p></div><div class="qi-actions"><button class="q-btn resolve-btn" onclick="updateStatus('${esc(e.referenceId||'')}','resolved')">Resolve</button></div></div>`).join('') : '<div class="empty-state" style="padding:16px"><i class="fas fa-check-circle"></i><p>Queue empty — all clear.</p></div>';
  }

  renderRecentTable();
  renderNotifs(entries);
  renderTrendChart();
  renderDistChart();
  renderAudit();
}

function renderAll() {
  renderDashboard();
  const active = document.querySelector('.page.active')?.id?.replace('page-','');
  if (active==='reports') renderReportCharts();
  if (active==='services') renderServices();
  // Diagnostic: report page nesting to console (helps find unclosed wrappers)
  try { reportPageNesting(); } catch(e){}
  // Ensure any lingering feedback Delete buttons are removed from fb-item cards
  try { document.querySelectorAll('.fb-item .fb-actions .delete-btn').forEach(b => b.remove()); } catch(e) {}
}

function reportPageNesting() {
  const pages = Array.from(document.querySelectorAll('.page')).map(p => ({ id: p.id, parentId: p.parentElement?.id || null }));
  console.log('Page nesting report:', pages);
  const scrollArea = document.getElementById('scrollArea');
  pages.forEach(p => {
    const el = document.getElementById(p.id);
    if (!el) return;
    if (el.parentElement !== scrollArea) console.warn(`${p.id} is not a direct child of #scrollArea; parent:`, el.parentElement);
  });
}

/* ══════════════════════════════
   INIT
══════════════════════════════ */
document.addEventListener('DOMContentLoaded', async () => {
  const spinner = document.getElementById('loadingSpinner');

  // New submissions arrive from another browser session; keep the bell current.
  window.setInterval(() => {
    if (!document.hidden) syncNotifications();
  }, 5000);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) syncNotifications();
  });

  await loadSettings();
  initializeFeedbackQrControls();
  ['settingFacility','settingBenchmark','settingThreshold','settingNegPct'].forEach(id => {
    document.getElementById(id)?.addEventListener('input', markSettingsDirty);
  });
  document.getElementById('settingAutoResolve')?.addEventListener('change', markSettingsDirty);
  document.getElementById('settingsConfirmOverlay')?.addEventListener('click', event => {
    if (event.target === event.currentTarget) closeSettingsConfirmation();
  });
  document.getElementById('logoutConfirmOverlay')?.addEventListener('click', event => {
    if (event.target === event.currentTarget) closeLogoutConfirmation();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !document.getElementById('logoutConfirmOverlay')?.hidden) {
      closeLogoutConfirmation();
    }
  });
  catalogCache = getStoredCatalog();

  const d = new Date();
  const dateEl = document.getElementById('currentDate');
  if (dateEl) dateEl.textContent = d.toLocaleDateString('en-US',{weekday:'long',year:'numeric',month:'long',day:'numeric'});

  renderAll();
  try {
    await syncNotifications();
  } catch (error) {
    console.warn('Initial notification sync failed', error);
  }

  try {
    const results = await Promise.allSettled([syncCatalog(), syncFeedback(), syncCatalogCache()]);
    results.forEach((result, index) => {
      if (result.status === 'rejected') {
        console.warn(`Dashboard sync ${index + 1} failed`, result.reason);
      }
    });
    renderAll();
  } finally {
    spinner.style.transition='opacity .3s';
    spinner.style.opacity='0';
    setTimeout(()=>spinner.style.display='none',300);
  }
});

document.getElementById('userChip').addEventListener('click',()=>{
  nav(document.querySelector('[data-page=settings]'));
});
</script>
<!--
  The staff-role rating table is rendered by the modular dashboard scripts.
  Keep their cache versions together so a deployment cannot combine this
  page with a browser-cached bundle from before Staff Ratings was restored.
-->
<script src="../js/admin/formatters.js?v=20261021-three-sentiment-categories"></script>
<script src="../js/admin/feedbackService.js?v=20260922-status-csrf"></script>
<script src="../js/admin/store.js?v=20260913-service-subtypes"></script>
<script src="../js/admin/renderers.js?v=20261025-negative-feedback-notifications"></script>
<script src="../js/admin/bootstrap.js?v=20261021-a4-healthcare-report"></script>
<script src="../js/admin/printReport.js?v=20261021-period-select"></script>
<script>
  // Ensure inline nav logic wins and keep only one sub-item active at a time.
  try {
    if (typeof nav === 'function') {
      // Re-assign the global nav to our inline implementation (overrides other definitions)
      window.nav = dashboardNav;
    }

    // Delegate clicks on sub-links to enforce exclusive active state
    document.addEventListener('click', function (ev) {
      const sub = ev.target.closest && ev.target.closest('.sb-link.sub');
      if (!sub) return;
      // remove active from other sub links
      document.querySelectorAll('.sb-link.sub').forEach((s) => s.classList.remove('active'));
      sub.classList.add('active');
      // ensure parent retains 'active'
      const parent = document.querySelector('.sb-link[data-page="all-feedbacks"]');
      if (parent) parent.classList.add('active');
    }, true);
  } catch (e) { /* ignore */ }
</script>
</body>
</html>
