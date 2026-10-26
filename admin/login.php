<?php
require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/security/AdminSecurity.php';
sfmsStartSecureSession();
if (isset($_SESSION['admin_id'])) {
  header('Location: /Feedback/admin/dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login — Smart Feedback Management System</title>
  <meta name="description" content="Secure administrator login for the Smart Feedback Management System.">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="../css/login.css">
  <script src="/Feedback/assets/js/browser-deterrent.js" defer></script>
</head>
<body>

  <!-- PAGE TRANSITION OVERLAY -->
  <div class="page-transition" id="pageTransition"></div>

  <div class="login-root">
    <div class="login-card">

      <!-- ═══ LEFT PANEL — GREEN BRANDING ═══ -->
      <aside class="brand-panel" aria-hidden="true">
        <div class="brand-panel-inner">

          <!-- Logo / Seal -->
          <div class="brand-seal">
            <i class="fas fa-heartbeat"></i>
          </div>

          <!-- Institution -->
          <div class="brand-institution">
            <span class="institution-icon"><i class="fas fa-hospital"></i></span>
            <span class="institution-name">CALAPE PRIMARY CARE FACILITY</span>
          </div>

          <!-- Headline -->
          <div class="brand-headline">
            <h1>Smart Feedback Management System</h1>
            <p>Collect, manage, and analyze patient and visitor feedback to improve healthcare service quality and satisfaction.</p>
          </div>

          <!-- Feature Cards -->
          <div class="brand-features">
            <div class="feature-card">
              <div class="feature-card-icon">
                <i class="fas fa-chart-line"></i>
              </div>
              <div class="feature-card-text">
                <strong>Smart Feedback Collection</strong>
                <span>Submit structured service feedback</span>
              </div>
            </div>

            <div class="feature-card">
              <div class="feature-card-icon">
                <i class="fas fa-shield-halved"></i>
              </div>
              <div class="feature-card-text">
                <strong>Feedback Analytics</strong>
                <span>Track trends and service performance</span>
              </div>
            </div>

            <div class="feature-card">
              <div class="feature-card-icon">
                <i class="fas fa-file-medical"></i>
              </div>
              <div class="feature-card-text">
                <strong>Management & Monitoring</strong>
                <span>Organize, review, and respond to feedback</span>
              </div>
            </div>
          </div>

        </div>
      </aside>

      <!-- ═══ RIGHT PANEL — LOGIN FORM ═══ -->
      <main class="form-panel">
        <div class="form-panel-inner" id="formPanelInner">

          <div class="form-header">
            <div class="form-badge">
              <i class="fas fa-shield-alt"></i>
              <span>Secure Access</span>
            </div>
            <h2>Administrator Login</h2>
            <p>Sign in to your administrator account to manage the feedback system.</p>
          </div>

          <form class="login-form" id="loginForm" novalidate autocomplete="on">

            <!-- Username Field -->
            <div class="field-wrap" id="usernameWrap">
              <label class="field-label" for="username">
                <i class="fas fa-user"></i>
                Username
              </label>
              <div class="field-input-box">
                <input
                  type="text"
                  id="username"
                  name="username"
                  placeholder="e.g., admin"
                  autocomplete="off"
                  spellcheck="false"
                  required
                  aria-describedby="usernameError"
                >
              </div>
              <p class="field-error" id="usernameError" role="alert" aria-live="polite"></p>
            </div>

            <!-- Password Field -->
            <div class="field-wrap" id="passwordWrap">
              <label class="field-label" for="password">
                <i class="fas fa-lock"></i>
                Password
              </label>
              <div class="field-input-box has-toggle">
                <input
                  type="password"
                  id="password"
                  name="password"
                  placeholder="Enter your password"
                  autocomplete="new-password"
                  required
                  aria-describedby="passwordError"
                >
                <button type="button" class="toggle-pw" id="togglePassword" aria-label="Show password" tabindex="0">
                  <i class="fas fa-eye" id="toggleIcon"></i>
                </button>
              </div>
              <p class="field-error" id="passwordError" role="alert" aria-live="polite"></p>
            </div>

            <!-- Options Row -->
            <div class="form-options">
              <label class="checkbox-wrap">
                <input type="checkbox" id="rememberMe" checked>
                <span class="checkmark"></span>
                <span>Keep me signed in</span>
              </label>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="btn-login" id="loginBtn">
              <span class="btn-text" id="btnText">
                <i class="fas fa-right-to-bracket"></i>
                Sign In
              </span>
              <span class="btn-loading" id="btnLoading" aria-hidden="true">
                <span class="spinner"></span>
                Authenticating...
              </span>
            </button>

            <!-- Status Message -->
            <div class="login-alert" id="loginAlert" role="alert" aria-live="assertive"></div>

          </form>

        </div>
      </main>

    </div>
  </div>

  <script>
    (function () {
      const form        = document.getElementById('loginForm');
      const loginBtn    = document.getElementById('loginBtn');
      const btnText     = document.getElementById('btnText');
      const btnLoading  = document.getElementById('btnLoading');
      const loginAlert  = document.getElementById('loginAlert');
      const usernameEl  = document.getElementById('username');
      const passwordEl  = document.getElementById('password');
      const toggleBtn   = document.getElementById('togglePassword');
      const toggleIcon  = document.getElementById('toggleIcon');
      const transition  = document.getElementById('pageTransition');

      /* ── Password visibility toggle ── */
      toggleBtn.addEventListener('click', function () {
        const isHidden = passwordEl.type === 'password';
        passwordEl.type = isHidden ? 'text' : 'password';
        toggleIcon.className = isHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
        toggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        passwordEl.focus();
      });

      /* ── Field helpers ── */
      function setError(wrap, errorEl, message) {
        document.getElementById(wrap).classList.add('has-error');
        document.getElementById(errorEl).textContent = message;
      }

      function clearError(wrap, errorEl) {
        document.getElementById(wrap).classList.remove('has-error');
        document.getElementById(errorEl).textContent = '';
      }

      function clearAll() {
        clearError('usernameWrap', 'usernameError');
        clearError('passwordWrap', 'passwordError');
        loginAlert.textContent = '';
        loginAlert.className = 'login-alert';
      }

      /* Clear errors on input */
      usernameEl.addEventListener('input', () => clearError('usernameWrap', 'usernameError'));
      passwordEl.addEventListener('input', () => clearError('passwordWrap', 'passwordError'));

      /* ── Loading state ── */
      function setLoading(state) {
        loginBtn.disabled = state;
        btnText.style.display  = state ? 'none'  : 'flex';
        btnLoading.style.display = state ? 'flex' : 'none';
      }

      /* ── Smooth redirect ── */
      function redirectTo(url) {
        transition.classList.add('active');
        setTimeout(function () {
          window.location.href = url;
        }, 500);
      }

      /* ── Form submit ── */
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearAll();

        const username = usernameEl.value.trim();
        const password = passwordEl.value;
        let valid = true;

        if (!username) {
          setError('usernameWrap', 'usernameError', 'Please enter your username.');
          valid = false;
        }

        if (!password) {
          setError('passwordWrap', 'passwordError', 'Please enter your password.');
          valid = false;
        }

        if (!valid) return;

        setLoading(true);

        /* Authenticate via API */
        fetch('/Feedback/api/auth.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({ action: 'login', username, password })
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            loginAlert.className = 'login-alert success';
            loginAlert.innerHTML = '<i class="fas fa-circle-check"></i> ' + data.message + ' — redirecting to dashboard…';

            setTimeout(function () {
              redirectTo('/Feedback/admin/dashboard.php');
            }, 800);
          } else {
            setLoading(false);
            loginAlert.className = 'login-alert error';
            loginAlert.innerHTML = '<i class="fas fa-circle-xmark"></i> ' + (data.message || 'Invalid username or password.');

            form.classList.add('shake');
            form.addEventListener('animationend', function () {
              form.classList.remove('shake');
            }, { once: true });

            passwordEl.value = '';
            passwordEl.focus();
          }
        })
        .catch(err => {
          setLoading(false);
          loginAlert.className = 'login-alert error';
          loginAlert.innerHTML = '<i class="fas fa-circle-xmark"></i> Network error. Please try again.';
        });
      });

      /* ── Keyboard: Enter on username → focus password ── */
      usernameEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); passwordEl.focus(); }
      });

      /* Keep the focused field above the mobile on-screen keyboard. */
      const mobileLoginFields = [usernameEl, passwordEl];
      let keyboardScrollTimer;

      function keepActiveFieldVisible() {
        if (!window.matchMedia('(max-width: 768px)').matches) return;
        if (!mobileLoginFields.includes(document.activeElement)) return;

        clearTimeout(keyboardScrollTimer);
        keyboardScrollTimer = setTimeout(function () {
          document.activeElement.scrollIntoView({
            behavior: 'smooth',
            block: 'center',
            inline: 'nearest'
          });
        }, 250);
      }

      mobileLoginFields.forEach(function (field) {
        field.addEventListener('focus', keepActiveFieldVisible);
      });

      if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', keepActiveFieldVisible);
      }

      /* ── Animate form in on load ── */
      document.getElementById('formPanelInner').classList.add('animate-in');
    })();
  </script>
</body>
</html>
