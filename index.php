<?php
session_start();

// Determine application base URL (same logic)
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/';
if (str_contains($scriptName, '/foase_exam_report_system')) {
    $pos = strpos($scriptName, '/foase_exam_report_system');
    $baseUrl = substr($scriptName, 0, $pos + strlen('/foase_exam_report_system')) . '/';
} else {
    $baseUrl = rtrim(str_replace('\\', '/', dirname($scriptName)), '/') . '/';
    if ($baseUrl === '//') {
        $baseUrl = '/';
    }
}

if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'Admin':
            header("Location: " . $baseUrl . "admin/dashboard/dashboard.php");
            break;
        case 'Teacher':
            header("Location: " . $baseUrl . "teacher/modules/dashboard.php");
            break;
        case 'Student':
            header("Location: " . $baseUrl . "student/dashboard.php");
            break;
        default:
            header("Location: " . $baseUrl . "login.php");
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__.'/pwa-head.php'; ?>

  <meta charset="utf-8" />
  <title>Login | Exam Report System</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="<?= $baseUrl ?>favicon-96x96.png" sizes="96x96" />
  <link rel="icon" type="image/svg+xml" href="<?= $baseUrl ?>favicon.svg" />
  <link rel="shortcut icon" href="<?= $baseUrl ?>favicon.ico" />
  <link rel="apple-touch-icon" sizes="180x180" href="<?= $baseUrl ?>apple-touch-icon.png" />
  <link rel="manifest" href="<?= $baseUrl ?>site.webmanifest" />
  <link href="<?= $baseUrl ?>assets/css/admin-dashboard.css" rel="stylesheet" />
  <style>
    :root {
      --deep-blue: #0d47a1;
      --deep-blue-2: #1565c0;
      --white: #ffffff;
    }

    html,body{height:100%;box-sizing:border-box}
    img,video{max-width:100%;height:auto}
    .login-container{width:100%;max-width:980px;margin:0 auto;display:flex;flex-direction:column}
    @media(min-width:821px){ .login-container{flex-direction:row} }
    .login-left,.login-right{flex:1;min-width:0} /* allow flex children to shrink */
    .preloader{padding:1rem}
    .logo-spin{width:72px;height:72px}
    .btn-login{white-space:normal}

    html, body {
      height: 100%;
      margin: 0;
      font-family: 'Inter', system-ui, sans-serif;
      overflow: hidden;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }

    /* ---------- Preloader ---------- */
    .preloader {
      position: fixed;
      inset: 0;
    
      z-index: 9999;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 14px;
      opacity: 1;
      transition: opacity 0.6s ease, visibility 0.6s ease;
    }
    .preloader.hidden { opacity: 0; visibility: hidden; pointer-events: none; }

    .preloader.ready .logo-spin {
      box-shadow: 0 0 40px 10px rgba(21,101,192,0.7);
      animation: readyPulse 1.2s ease-in-out forwards;
    }

    @keyframes readyPulse {
      0% { box-shadow: 0 0 0 rgba(21,101,192,0); transform: scale(1); }
      50% { box-shadow: 0 0 40px 15px rgba(21,101,192,0.6); transform: scale(1.05); }
      100% { box-shadow: 0 0 0 rgba(21,101,192,0); transform: scale(1); }
    }

    .logo-spin {
      width: 96px;
      height: 96px;
      background: #fff;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      animation: spin 1.2s linear infinite;
      box-shadow: 0 10px 25px rgba(0,0,0,0.2);
    }
    .logo-spin img {
      width: 72px;
      height: 72px;
      object-fit: contain;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    .preloader .text { color: #fff; font-weight: 700; letter-spacing: .3px; font-size: 1rem; }
    .preloader .sub { color: rgba(255,255,255,0.85); font-weight: 500; font-size: .88rem; }

    /* ---------- Background video ---------- */
    .bg-video {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
      z-index: -2;
      background: linear-gradient(180deg,#0d47a1,#07204a);
    }

    .bg-overlay {
      position: fixed;
      inset: 0;
      background:rgba(0,0,0,0.55);
      z-index: -1;
      transition: background .4s ease;
    }

    /* ---------- Login layout ---------- */
    .login-area {
      display: flex;
      align-items: center;
      justify-content: center;
      height: 100vh;
      padding: 2rem;
      color: var(--white);
      transition: opacity 0.8s ease;
    }

    .login-container {
      display: flex;
      max-width: 980px;
      width: 100%;
      /* make the card background transparent so the video shows through */
      background: transparent;
      border-radius: 14px;
      overflow: hidden;
      box-shadow: 0 18px 50px rgba(2,22,60,0.28);
      backdrop-filter: blur(10px);
      animation: cardEnter 1s cubic-bezier(.2,.9,.2,1) both;
    }
    @keyframes cardEnter {
      from { opacity: 0; transform: translateY(30px) scale(.98); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }

    /* left */
    .login-left {
      flex: 1;
      background: linear-gradient(180deg, rgba(1, 11, 26, 0.95), rgba(21,101,192,0.9));
      padding: 32px 28px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 8px;
      color: #fff;
      text-align: center;
    }
    .login-left .logo {
      width: 110px;
      height: 110px;
      background: #fff;
      padding: 8px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 10px 30px rgba(2,22,60,0.25);
      animation: float 3.8s ease-in-out infinite;
    }
    .login-left .logo img {
      width: 84px;
      height: 84px;
      object-fit: contain;
    }
    @keyframes float { 0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)} }

    .login-left h4 { font-weight:800; letter-spacing:.6px; font-size:1.2rem; margin-top:8px; }
    .login-left p { opacity:.95; font-size:.95rem; line-height:1.35; color:rgba(255,255,255,0.95); }

    /* right */
    .login-right {
      flex: 1.4;
      /* semi-transparent dark panel so video remains visible but inputs are readable */
      background: rgba(3,8,20,0.45);
      padding: 34px 32px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      gap: 12px;
    }
    .login-right h5 {
      color: #fff;
      margin: 0 0 6px;
      font-weight: 800;
      text-align: center;
      letter-spacing: .3px;
    }

    /* inputs styled to work on the semi-transparent dark background */
    .form-control {
      border-radius: 10px;
      padding: 12px 14px;
      background: rgba(255,255,255,0.06);
      border: 1px solid rgba(255,255,255,0.12);
      color: #fff;
      transition: box-shadow .18s ease, border-color .18s ease, background .18s ease;
    }
    .form-control::placeholder { color: rgba(255,255,255,0.6); }
    .form-control:focus {
      box-shadow: 0 8px 26px rgba(13,71,161,0.12);
      border-color: rgba(21,101,192,0.9);
      background: rgba(255,255,255,0.08);
      outline: none;
    }

    .btn-login {
      display: inline-block;
      width: 100%;
      background: linear-gradient(90deg,var(--deep-blue),var(--deep-blue-2));
      color: #fff;
      border: 0;
      padding: 11px 14px;
      border-radius: 10px;
      font-weight: 700;
      position: relative;
      transition: transform .12s ease, box-shadow .12s ease;
      box-shadow: 0 8px 24px rgba(13,71,161,0.28);
    }
    .btn-login:active { transform: translateY(1px); }

    /* loading spinner */
    .btn-login.loading { pointer-events: none; color: transparent; }
    .btn-login.loading .btn-spinner { display: flex; }
    .btn-spinner {
      position: absolute;
      left: 50%;
      top: 50%;
      transform: translate(-50%,-50%);
      width: 30px;
      height: 30px;
      border-radius: 8px;
      background: #fff;
      padding: 4px;
      align-items: center;
      justify-content: center;
      box-shadow: 0 6px 14px rgba(2,22,60,0.15);
      animation: btnSpin 0.9s linear infinite;
      display: none;
    }
    .btn-spinner img { width: 18px; height: 18px; object-fit: contain; }
    @keyframes btnSpin { to { transform: translate(-50%,-50%) rotate(360deg); } }

    .small-muted { color: rgba(255,255,255,0.85); font-size: 0.9rem; }

    .card-footer-note {
      text-align: center;
      color: rgba(255,255,255,0.9);
      font-size: 0.88rem;
      margin-top: 14px;
    }

    .logout-toast {
      position: fixed;
      top: 18px;
      right: 18px;
      background: rgba(13,71,161,0.95);
      color: #fff;
      padding: 10px 14px;
      border-radius: 8px;
      box-shadow: 0 6px 22px rgba(2,22,60,0.22);
      z-index: 1100;
      animation: toastShow 4s ease forwards;
    }
    @keyframes toastShow {
      0%{opacity:0;transform:translateY(-6px)}
      10%{opacity:1;transform:translateY(0)}
      90%{opacity:1}
      100%{opacity:0;transform:translateY(-6px)}
    }

    @media (max-width: 820px) {
      .login-container { flex-direction: column; width: 92%; }
      .login-left { padding: 20px; }
      .login-right { padding: 20px; }
    }
  </style>
</head>
<body>

  <!-- Preloader -->
  <div id="preloader" class="preloader site-preloader">
    <div class="logo-spin"><img src="<?= $baseUrl ?>assets/images/logo.png" alt="logo"></div>
    <div class="text">Exam Report System</div>
    <div class="sub">Loading...</div>
  </div>

  <!-- Background -->
  <video class="bg-video" id="bgVideo" autoplay muted playsinline loop preload="auto" poster="<?= $baseUrl ?>assets/images/2503.jpg">
    <source src="<?= $baseUrl ?>assets\videos\login.mp4" type="video/mp4">
  </video>
  <div class="bg-overlay" id="bgOverlay"></div>

  <?php if (isset($_GET['logout'])): ?>
    <div class="logout-toast">✅ You have been logged out successfully.</div>
  <?php endif; ?>

  <div class="login-area" id="loginArea" style="visibility:hidden;opacity:0;">
    <div class="login-container">
      <div class="login-left">
        <div class="logo"><img src="<?= $baseUrl ?>assets/images/logo.png" alt="School Logo"></div>
        <h4>Exam Report System</h4>
        <p>FOASE M/A JUNIOR HIGH SCHOOL</p>
        <p class="small-muted">Manage reports, track performance, and simplify academic record keeping.</p>
      </div>

      <div class="login-right">
        <h5>Sign In to Continue</h5>
        <?php if (isset($_GET['error'])): ?>
          <div class="alert alert-danger"><?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>

        <form id="loginForm" action="process_login.php" method="POST" novalidate>
          <div class="mb-3">
            <label class="form-label small-muted">Username</label>
            <input type="text" name="username" id="username" class="form-control" placeholder="Enter username" required>
          </div>

          <div class="mb-3 position-relative">
            <label class="form-label small-muted">Password</label>
            <input type="password" name="password" id="password" class="form-control" placeholder="Enter password" required>
            <span id="togglePassword" style="position:absolute;right:12px;top:38px;cursor:pointer;font-size:18px;color:#8899aa">👁️</span>
          </div>

          <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="rememberMe">
              <label class="form-check-label small-muted" for="rememberMe">Remember me</label>
            </div>
          </div>

          <div class="d-grid mt-2">
            <button id="loginBtn" type="submit" class="btn-login">
              <span class="btn-text">Sign In</span>
              <span class="btn-spinner"><img src="<?= $baseUrl ?>assets/images/logo.png" alt="spin"></span>
            </button>
          </div>
        </form>

        <div class="mt-4 text-center">
          <small class="small-muted">Need help? Contact <strong>HEPAGK TECHNOLOGIES</strong> — 0594446074</small>
        </div>
      </div>
    </div>
  </div>

  <div class="card-footer-note">Developed by <strong>HEPAGK TECHNOLOGIES LIMITED</strong></div>

  <script>
  (function(){
    const preloader = document.getElementById('preloader');
    const loginArea = document.getElementById('loginArea');
    const bgVideo = document.getElementById('bgVideo');
    const bgOverlay = document.getElementById('bgOverlay');
    const loginForm = document.getElementById('loginForm');
    const loginBtn = document.getElementById('loginBtn');
    const btnSpinner = loginBtn.querySelector('.btn-spinner');
    const btnText = loginBtn.querySelector('.btn-text');
    const togglePassword = document.getElementById('togglePassword');
    const usernameInput = document.getElementById('username');
    const rememberMe = document.getElementById('rememberMe');

    function attemptPlayVideo() {
      if (!bgVideo) return Promise.resolve();
      bgVideo.muted = true;
      return bgVideo.play().catch(() => {});
    }

    function showLoginUI() {
      if (!preloader) return;
      preloader.classList.add('ready');
      setTimeout(() => {
        preloader.classList.add('hidden');
        preloader.setAttribute('aria-hidden', 'true');
        if (loginArea) {
          loginArea.style.visibility = 'visible';
          loginArea.style.opacity = '1';
        }
      }, 5000); // ensure at least 5s before hiding
    }

    const hasVisited = localStorage.getItem('hasVisitedLogin');
    if (hasVisited) {
      if (preloader) preloader.style.display = 'none';
      if (loginArea) {
        loginArea.style.visibility = 'visible';
        loginArea.style.opacity = '1';
      }
      attemptPlayVideo();
    } else {
      localStorage.setItem('hasVisitedLogin', 'true');
      let resolved = false;
      if (bgVideo) {
        bgVideo.addEventListener('canplay', () => {
          if (resolved) return;
          resolved = true;
          // allow the preloader / video to show for at least 5s before revealing UI
          setTimeout(showLoginUI, 5000);
        }, { once: true });
        bgVideo.addEventListener('error', () => {
          if (resolved) return;
          resolved = true;
          bgVideo.style.display = 'none';
          bgOverlay.style.background = 'linear-gradient(180deg, rgba(13,71,161,0.5), rgba(3,9,26,0.7))';
          setTimeout(showLoginUI, 5000);
        }, { once: true });
      }
      // fallback: ensure login reveals after 5s even if canplay/load didn't fire
      setTimeout(() => {
        if (resolved) return;
        resolved = true;
        attemptPlayVideo();
        showLoginUI();
      }, 5000);
    }
    
    if (togglePassword) {
      togglePassword.addEventListener('click', () => {
        const pass = document.getElementById('password');
        if (!pass) return;
        pass.type = pass.type === 'password' ? 'text' : 'password';
        togglePassword.textContent = pass.type === 'password' ? '👁️' : '🙈';
      });
    }

    try {
      if (localStorage.getItem('rememberedUsername')) {
        usernameInput.value = localStorage.getItem('rememberedUsername');
        if (rememberMe) rememberMe.checked = true;
      }
    } catch(e){}

    loginForm.addEventListener('submit', function(){
      loginBtn.classList.add('loading');
      btnText.style.visibility = 'hidden';
      btnSpinner.style.display = 'block';
      try {
        if (rememberMe && rememberMe.checked)
          localStorage.setItem('rememberedUsername', usernameInput.value || '');
        else localStorage.removeItem('rememberedUsername');
      } catch(e){}
    });

    window.addEventListener('load', () => {
      loginBtn.classList.remove('loading');
      btnText.style.visibility = 'visible';
      btnSpinner.style.display = 'none';
    });
  })();
  </script>

<?php include __DIR__.'/pwa-footer.php'; ?>\n</body>
</html>
