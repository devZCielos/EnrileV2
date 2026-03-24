<?php
// ── Remember Me — pre-fill email only (user still needs to enter password) ──
session_start();
require_once __DIR__ . '/../config/csrf.php';

// If already logged in, redirect immediately
if (!empty($_SESSION['user_id'])) {
    $dest = in_array($_SESSION['user_role'] ?? '', ['admin','hr_staff','dept_head'])
        ? '../Admin/dashboard.php'
        : '../Employee/Employee_portal.php';
    header('Location: ' . $dest); exit();
}

// Check remember_token cookie — pre-fill email field only (no auto-login)
$rememberedEmail = '';
if (!empty($_COOKIE['remember_token'])) {
    $possibleDb = [__DIR__.'/../config/db.php', __DIR__.'/config/db.php'];
    foreach ($possibleDb as $p) { if (file_exists($p)) { require_once $p; break; } }
    if (isset($pdo)) {
        try {
            // Hash the cookie value before querying — the DB stores hashes, never raw tokens
            $cookieHash = hash('sha256', $_COOKIE['remember_token']);
            $stmt = $pdo->prepare("SELECT email FROM user_accounts WHERE remember_token = ? LIMIT 1");
            $stmt->execute([$cookieHash]);
            $user = $stmt->fetch();
            if ($user) {
                $rememberedEmail = $user['email'];
            }
        } catch (Exception $ex) { /* ignore */ }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login | LGU Enrile HRMS</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #2563eb;
      --secondary: #0f172a;
      --accent: #22c55e;
      --light: #f8fafc;
      --gray: #64748b;
      --error: #ef4444;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Nunito', sans-serif; }

    body {
      background: linear-gradient(135deg, #2563eb 0%, #1e40af 50%, #22c55e 100%);
      color: var(--secondary);
      line-height: 1.6;
      min-height: 100vh;
    }

    header {
      background: white;
      padding: 15px 40px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: 0 2px 6px rgba(0,0,0,0.05);
      position: sticky;
      top: 0;
      z-index: 100;
    }

    header h1 {
      font-size: 1.5rem;
      font-family: 'Playfair Display', serif;
    }

    nav a {
      margin-left: 20px;
      text-decoration: none;
      color: var(--secondary);
      font-weight: 700;
      transition: color 0.3s;
    }

    nav a:hover { color: var(--primary); }

    .login-wrapper {
      display: flex;
      align-items: center;
      justify-content: space-between;
      min-height: calc(100vh - 80px);
      padding: 0 40px;
    }

    /* ══════════════════════════════════
       BRANDING SECTION
    ══════════════════════════════════ */
    .branding {
      flex: 0 0 42%;
      text-align: center;
      padding: 48px 32px;
      display: flex;
      flex-direction: column;
      align-items: center;
      animation: fadeSlideUp 0.8s ease both;
    }

    /* spinning glow ring around logo */
    .logo-wrapper {
      position: relative;
      width: 200px;
      height: 200px;
      margin: 0 auto 28px;
    }

    .logo-wrapper::before {
      content: '';
      position: absolute;
      inset: -8px;
      border-radius: 50%;
      background: conic-gradient(
        rgba(255,255,255,0.8) 0%,
        rgba(34,197,94,0.7) 30%,
        rgba(37,99,235,0.7) 60%,
        rgba(255,255,255,0.8) 100%
      );
      animation: spinRing 6s linear infinite;
      z-index: 0;
    }

    .logo-wrapper::after {
      content: '';
      position: absolute;
      inset: 0px;
      border-radius: 50%;
      background: linear-gradient(135deg, #1e40af, #2563eb);
      z-index: 1;
    }

    .branding .logo {
      position: relative;
      z-index: 2;
      width: 100%;
      height: 100%;
      border-radius: 50%;
      object-fit: cover;
      border: 4px solid rgba(255,255,255,0.9);
      box-shadow: 0 8px 32px rgba(0,0,0,0.25);
      display: block;
      animation: logoPulse 3s ease-in-out infinite;
    }

    /* tagline */
    .tagline-block {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
    }

   /*.tagline-eyebrow {
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.22em;
      text-transform: uppercase;
      color: rgba(255,255,255,0.60);
    }*/

    .tagline-main {
      font-family: 'Playfair Display', serif;
      font-size: 2rem;
      font-weight: 900;
      line-height: 1.15;
      color: #fff;
      text-shadow: 0 2px 20px rgba(0,0,0,0.25);
    }

    .tagline-main span {
      background: linear-gradient(90deg, #a7f3d0, #22c55e);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .tagline-sub {
      font-size: 0.92rem;
      color: rgba(255,255,255,0.72);
      font-style: italic;
      margin-top: 2px;
    }

    /* decorative pill-dots */
    .tagline-dots {
      display: flex;
      gap: 6px;
      margin-top: 16px;
      align-items: center;
    }

    .tagline-dots span {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: rgba(255,255,255,0.35);
    }

    .tagline-dots span.active {
      background: #22c55e;
      width: 24px;
      border-radius: 4px;
    }

    /* ══════════════════════════════════
       LOGIN CONTAINER
    ══════════════════════════════════ */
    .login-container {
      display: flex;
      justify-content: center;
      align-items: center;
      padding: 20px;
      flex: 0 0 50%;
      margin-left: 20px;
      animation: fadeSlideUp 0.8s 0.2s ease both;
    }

    .login-box {
      flex: 1;
      background: rgba(255, 255, 255, 0.20);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      border: 1px solid rgba(255, 255, 255, 0.35);
      border-radius: 18px;
      padding: 40px 36px;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.18);
    }

    .login-header { text-align: center; margin-bottom: 20px; }

    .login-box h2 {
      margin: 0 0 10px;
      background: linear-gradient(135deg, #ffffff, #d1fae5);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      font-family: 'Playfair Display', serif;
      font-weight: 700;
      font-size: 1.8rem;
      transition: all 0.25s;
    }

    .login-box > p,
    .login-header p {
      text-align: center;
      color: rgba(255, 255, 255, 0.80);
      margin-bottom: 0;
      font-size: 0.9rem;
      transition: all 0.25s;
    }

    /* ══════════════════════════════════
       ROLE TOGGLE  ← NEW
    ══════════════════════════════════ */
    .role-toggle {
      display: flex;
      background: rgba(255,255,255,0.10);
      border: 1px solid rgba(255,255,255,0.22);
      border-radius: 11px;
      padding: 4px;
      margin-bottom: 8px;
      gap: 4px;
    }

    .role-btn {
      flex: 1;
      padding: 10px 8px;
      border-radius: 8px;
      border: none;
      font-family: 'Nunito', sans-serif;
      font-size: 0.84rem;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.25s;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      color: rgba(255,255,255,0.55);
      background: transparent;
    }

    .role-btn:hover {
      color: rgba(255,255,255,0.88);
      background: rgba(255,255,255,0.08);
    }

    .role-btn.active {
      background: rgba(255,255,255,0.22);
      color: #fff;
      box-shadow: 0 2px 12px rgba(0,0,0,0.16);
    }

    .role-btn .rb-icon {
      width: 28px;
      height: 28px;
      border-radius: 7px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.95rem;
      background: rgba(255,255,255,0.10);
      transition: background 0.25s;
      flex-shrink: 0;
    }

    .role-btn.active .rb-icon {
      background: rgba(255,255,255,0.24);
    }

    /* small hint text under the toggle */
    .role-hint {
      text-align: center;
      font-size: 0.70rem;
      color: rgba(255,255,255,0.42);
      margin-bottom: 20px;
      min-height: 14px;
    }

    /* login button — swaps color per role */
    .login-btn {
      width: 100%;
      padding: 12px;
      background: linear-gradient(to right, var(--primary), #1d4ed8);
      color: white;
      border: none;
      border-radius: 6px;
      font-size: 1rem;
      font-weight: 700;
      font-family: 'Nunito', sans-serif;
      cursor: pointer;
      transition: transform 0.2s, box-shadow 0.2s, background 0.3s;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }

    .login-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(37,99,235,0.4);
    }

    .login-btn:active { transform: translateY(0); }

    /* green variant when Employee is selected */
    .login-btn.employee-mode {
      background: linear-gradient(to right, #16a34a, #15803d);
    }

    .login-btn.employee-mode:hover {
      box-shadow: 0 4px 12px rgba(22,163,74,0.45);
    }

    /* ══════════════════════════════════
       FORM — unchanged from your file
    ══════════════════════════════════ */
    .form-group { margin-bottom: 20px; }

    label {
      display: block;
      margin-bottom: 8px;
      font-weight: 700;
      color: rgba(255, 255, 255, 0.95);
      font-size: 0.95rem;
    }

    input[type="email"],
    input[type="password"],
    input[type="text"] {
      width: 100%;
      padding: 12px;
      border: 2px solid rgba(255, 255, 255, 0.40);
      border-radius: 6px;
      font-size: 1rem;
      font-family: 'Nunito', sans-serif;
      transition: border-color 0.3s, background 0.3s;
      background: rgba(255, 255, 255, 0.15);
      color: #fff;
    }

    input[type="email"]::placeholder,
    input[type="password"]::placeholder { color: rgba(255,255,255,0.50); }

    input[type="email"]:focus,
    input[type="password"]:focus {
      outline: none;
      border-color: rgba(255,255,255,0.80);
      background: rgba(255,255,255,0.22);
      box-shadow: 0 0 0 3px rgba(255,255,255,0.15);
    }

    .remember-forgot {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 25px;
      font-size: 0.9rem;
    }

    .remember-forgot a {
      text-decoration: none;
      color: rgba(255,255,255,0.90);
      transition: color 0.3s;
    }

    .remember-forgot a:hover { color: #fff; }

    .checkbox-wrapper { display: flex; align-items: center; }

    .checkbox-wrapper label {
      color: rgba(255,255,255,0.90);
      margin-bottom: 0;
      font-weight: normal;
    }

    input[type="checkbox"] { margin-right: 5px; cursor: pointer; }

    .signup-link {
      text-align: center;
      margin-top: 20px;
      font-size: 0.9rem;
      color: rgba(255,255,255,0.80);
    }

    .signup-link a {
      text-decoration: none;
      color: #d1fae5;
      font-weight: 700;
      transition: color 0.3s;
    }

    .signup-link a:hover { color: #fff; }

    .error-message {
      background: #fee2e2;
      color: var(--error);
      padding: 12px;
      border-radius: 6px;
      margin-bottom: 20px;
      border-left: 4px solid var(--error);
      display: none;
    }

    .error-message.show { display: block; }

    .success-message {
      background: #dcfce7;
      color: #166534;
      padding: 12px;
      border-radius: 6px;
      margin-bottom: 20px;
      border-left: 4px solid var(--accent);
      display: none;
    }

    .success-message.show { display: block; }

    footer {
      background: var(--secondary);
      color: white;
      text-align: center;
      padding: 20px;
      position: fixed;
      bottom: 0;
      width: 100%;
    }

    .content-wrapper { padding-bottom: 60px; }

    @keyframes fadeSlideUp {
      from { opacity: 0; transform: translateY(24px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    @keyframes spinRing {
      from { transform: rotate(0deg); }
      to   { transform: rotate(360deg); }
    }

    @keyframes logoPulse {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.05); }
    }

    @media(max-width: 768px) {
      .login-wrapper {
        flex-direction: column;
        justify-content: center;
        align-items: center;
        min-height: calc(100vh - 140px);
        padding: 40px 20px;
        gap: 30px;
      }

      .branding { flex: none; padding: 20px; }
      .logo-wrapper { width: 150px; height: 150px; }
      .tagline-main { font-size: 1.5rem; }
      .login-container { flex: none; width: 100%; margin-left: 0; padding: 0; }
      .login-box { padding: 30px 20px; }
      header { flex-direction: column; gap: 15px; }
      nav { width: 100%; text-align: center; }
      nav a { margin: 0 10px; }
      .remember-forgot { flex-direction: column; gap: 10px; }
      .role-btn { font-size: 0.78rem; padding: 9px 6px; }
    }

    /* on very small phones, hide text labels — show icons only */
    @media(max-width: 380px) {
      .rb-label { display: none; }
      .role-btn { gap: 0; }
    }
  </style>
</head>
<body>
  <header>
    <h1>LGU Enrile HRMS</h1>
    <nav>
      <a href="../Landingpage/landingpage.php">Home</a>
    </nav>
  </header>

  <div class="content-wrapper">
    <div class="login-wrapper">

      <!-- BRANDING — unchanged -->
      <div class="branding">
       <!-- <div class="tagline-eyebrow">Welcome to</div> -->
        <div class="logo-wrapper">
          <img src="../images/Enrile.png" alt="Enrile Logo" class="logo" />
        </div>
        <div class="tagline-block">
          <h2 class="tagline-main">#AnteraKang<br><span>Enrile</span></h2>
          <p class="tagline-sub">Tara na sa Enrile 🌿</p>
          <div class="tagline-dots">
            <span></span>
            <span class="active"></span>
            <span></span>
          </div>
        </div>
      </div>

      <!-- LOGIN BOX -->
      <div class="login-container">
        <div class="login-box">

          <!-- heading — updates dynamically via JS -->
          <div class="login-header">
            <h2 id="loginTitle">Admin / HR Login</h2>
            <p id="loginSubtitle">Sign in to access the HR Management System</p>
          </div>

          <!-- ══ ROLE TOGGLE ══ -->
          <div class="role-toggle">
            <button type="button" class="role-btn active" id="btnAdmin" onclick="setRole('admin')">
              <span class="rb-icon">🛡️</span>
              <span class="rb-label">Admin / HR Staff</span>
            </button>
            <button type="button" class="role-btn" id="btnEmployee" onclick="setRole('employee')">
              <span class="rb-icon">👤</span>
              <span class="rb-label">Employee</span>
            </button>
          </div>
          <!-- small context hint -->
          <p class="role-hint" id="roleHint">Redirects to the Admin Dashboard after login</p>

          <div id="errorMessage" class="error-message"></div>
          <div id="successMessage" class="success-message"></div>

          <form id="loginForm" method="POST" action="process_login.php">
            <?= csrf_field() ?>

            <!-- hidden field — process_login.php reads $_POST['role'] to redirect correctly -->
            <input type="hidden" id="roleField" name="role" value="admin" />

            <div class="form-group">
              <label for="email">Email Address</label>
              <input type="email" id="email" name="email" placeholder="Enter your email" required value="<?= htmlspecialchars($rememberedEmail) ?>" />
            </div>
            <div class="form-group">
              <label for="password">Password</label>
              <input type="password" id="password" name="password" placeholder="Enter your password" required />
            </div>
            <div class="remember-forgot">
              <div class="checkbox-wrapper">
                <input type="checkbox" id="remember" name="remember" <?= $rememberedEmail ? 'checked' : '' ?> />
                <label for="remember">Remember me</label>
              </div>
              <a href="../Landingpage/forgot_password.php">Forgot password?</a>
            </div>
            <button type="submit" class="login-btn" id="loginBtn">
              🛡️ Login as Admin / HR Staff
            </button>
          </form>

          <!--<div class="signup-link">
            Don't have an account? <a href="../Login/signup.php">Sign up here</a>
          </div> -->

        </div>
      </div>

    </div>
  </div>

  <footer>
    <p>&copy; 2026 LGU Enrile HRMS. All rights reserved.</p>
  </footer>

  <script>
    /* ══════════════════════════════════
       ROLE TOGGLE
    ══════════════════════════════════ */
    function setRole(role) {
      const isAdmin = role === 'admin';

      // toggle active class on the two buttons
      document.getElementById('btnAdmin').classList.toggle('active', isAdmin);
      document.getElementById('btnEmployee').classList.toggle('active', !isAdmin);

      // update heading & subtitle
      document.getElementById('loginTitle').textContent =
        isAdmin ? 'Admin / HR Login' : 'Employee Login';
      document.getElementById('loginSubtitle').textContent =
        isAdmin ? 'Sign in to access the HR Management System'
                : 'Sign in to access your Employee Portal';

      // update hint text
      document.getElementById('roleHint').textContent =
        isAdmin ? 'Redirects to the Admin Dashboard after login'
                : 'Redirects to the Employee Portal after login';

      // hidden field sent with the form — PHP reads this to decide redirect
      document.getElementById('roleField').value = role;

      // update button label + swap to green for Employee
      const btn = document.getElementById('loginBtn');
      btn.innerHTML = isAdmin
        ? '🛡️ Login as Admin / HR Staff'
        : '👤 Login as Employee';
      btn.classList.toggle('employee-mode', !isAdmin);

      clearMessages();
    }

    /* ══════════════════════════════════
       FORM VALIDATION — your original logic, unchanged
    ══════════════════════════════════ */
    const loginForm     = document.getElementById('loginForm');
    const errorMessage  = document.getElementById('errorMessage');
    const successMessage= document.getElementById('successMessage');

    const urlParams  = new URLSearchParams(window.location.search);
    const emailParam = urlParams.get('email');
    const error      = urlParams.get('error');
    const success    = urlParams.get('success');
    // ?role=employee in the URL auto-selects the Employee tab
    // (useful for a direct "Employee Login" link from your landing page)
    const roleParam  = urlParams.get('role');

    if (emailParam) document.getElementById('email').value = decodeURIComponent(emailParam);
    if (error)   { errorMessage.textContent   = decodeURIComponent(error);   errorMessage.classList.add('show'); }
    if (success) { successMessage.textContent = decodeURIComponent(success); successMessage.classList.add('show'); }
    if (roleParam === 'employee') setRole('employee');

    loginForm.addEventListener('submit', function(e) {
      const email    = document.getElementById('email').value.trim();
      const password = document.getElementById('password').value;
      if (!email)                { e.preventDefault(); showError('Please enter your email address'); return; }
      if (!validateEmail(email)) { e.preventDefault(); showError('Please enter a valid email address'); return; }
      if (!password)             { e.preventDefault(); showError('Please enter your password'); return; }
      if (password.length < 6)   { e.preventDefault(); showError('Password must be at least 6 characters long'); return; }
    });

    function showError(msg) {
      errorMessage.textContent = msg;
      errorMessage.classList.add('show');
      successMessage.classList.remove('show');
    }

    function validateEmail(email) {
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    document.getElementById('email').addEventListener('input',    clearMessages);
    document.getElementById('password').addEventListener('input', clearMessages);

    function clearMessages() {
      errorMessage.classList.remove('show');
      successMessage.classList.remove('show');
    }
  </script>
</body>
</html>