<?php
session_start();
require_once __DIR__ . '/../config/csrf.php';
// Retrieve reset link stored by process_forgot_password.php (for XAMPP where mail() isn't configured)
$resetLinkForDisplay = '';
if (!empty($_SESSION['reset_link']) && !empty($_SESSION['reset_email'])) {
    $resetLinkForDisplay = $_SESSION['reset_link'];
    $resetEmailForDisplay = $_SESSION['reset_email'];
    unset($_SESSION['reset_link'], $_SESSION['reset_email']); // consume once
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Forgot Password | My Web System</title>
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

    .forgot-wrapper {
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
       FORGOT PASSWORD CONTAINER
    ══════════════════════════════════ */
    .forgot-container {
      display: flex;
      justify-content: center;
      align-items: center;
      padding: 20px;
      flex: 0 0 50%;
      margin-left: 20px;
      animation: fadeSlideUp 0.8s 0.2s ease both;
    }

    .forgot-box {
      flex: 1;
      background: rgba(255, 255, 255, 0.20);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      border: 1px solid rgba(255, 255, 255, 0.35);
      border-radius: 18px;
      padding: 40px 36px;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.18);
    }

    .forgot-header { text-align: center; margin-bottom: 20px; }

    .forgot-box h2 {
      margin: 0 0 10px;
      background: linear-gradient(135deg, #ffffff, #d1fae5);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      font-family: 'Playfair Display', serif;
      font-weight: 700;
      font-size: 1.8rem;
    }

    .forgot-box > p,
    .forgot-header p {
      text-align: center;
      color: rgba(255, 255, 255, 0.80);
      margin-bottom: 30px;
      font-size: 0.9rem;
    }

    .form-group { margin-bottom: 20px; }

    label {
      display: block;
      margin-bottom: 8px;
      font-weight: 700;
      color: rgba(255, 255, 255, 0.95);
      font-size: 0.95rem;
    }

    input[type="email"] {
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

    input[type="email"]::placeholder { color: rgba(255,255,255,0.50); }

    input[type="email"]:focus {
      outline: none;
      border-color: rgba(255,255,255,0.80);
      background: rgba(255,255,255,0.22);
      box-shadow: 0 0 0 3px rgba(255,255,255,0.15);
    }

    .forgot-btn {
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
      transition: transform 0.2s, box-shadow 0.2s;
    }

    .forgot-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(37,99,235,0.4);
    }

    .forgot-btn:active { transform: translateY(0); }

    .back-link {
      text-align: center;
      margin-top: 20px;
      font-size: 0.9rem;
      color: rgba(255,255,255,0.80);
    }

    .back-link a {
      text-decoration: none;
      color: #d1fae5;
      font-weight: 700;
      transition: color 0.3s;
    }

    .back-link a:hover { color: #fff; }

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
      .forgot-wrapper {
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
      .forgot-container { flex: none; width: 100%; margin-left: 0; padding: 0; }
      .forgot-box { padding: 30px 20px; }
      header { flex-direction: column; gap: 15px; }
      nav { width: 100%; text-align: center; }
      nav a { margin: 0 10px; }
    }
  </style>
</head>
<body>
  <header>
    <h1>E-LEAVE MO</h1>
    <nav>
      <a href="landingpage.php">Home</a>
      <a href="../Login/login.php">Login</a>
    </nav>
  </header>

  <div class="content-wrapper">
    <div class="forgot-wrapper">

      <!-- BRANDING -->
      <div class="branding">
        <div class="logo-wrapper">
          <img src="../images/Enrile.png" alt="Enrile Logo" class="logo" />
        </div>
        <div class="tagline-block">
          <!-- <p class="tagline-eyebrow">Welcome to</p> -->
          <h2 class="tagline-main">#AnteraKang<br><span>Enrile</span></h2>
          <p class="tagline-sub">Tara na sa Enrile 🌿</p>
          <div class="tagline-dots">
            <span></span>
            <span></span>
            <span class="active"></span>
          </div>
        </div>
      </div>

      <!-- FORGOT PASSWORD BOX -->
      <div class="forgot-container">
        <div class="forgot-box">
          <div class="forgot-header">
            <h2>Forgot Password?</h2>
            <p>Enter your email address and we'll send you a link to reset your password.</p>
          </div>

          <div id="errorMessage" class="error-message"></div>
          <div id="successMessage" class="success-message"></div>
          <?php if ($resetLinkForDisplay): ?>
          <div id="resetLinkBox" style="background:#eff6ff;border:1.5px solid #93c5fd;border-radius:8px;padding:14px 16px;margin-bottom:16px;font-size:.85rem;color:#1e40af;">
            <strong>📧 Email could not be sent automatically.</strong><br>
            <span style="color:#475569;">Copy the link below to reset your password:</span><br><br>
            <a href="<?= htmlspecialchars($resetLinkForDisplay) ?>" style="word-break:break-all;color:#2563eb;font-weight:700;">
              <?= htmlspecialchars($resetLinkForDisplay) ?>
            </a>
          </div>
          <?php endif; ?>

          <form id="forgotForm" method="POST" action="../Login/process_forgot_password.php">
            <?= csrf_field() ?>
            <div class="form-group">
              <label for="email">Email Address</label>
              <input type="email" id="email" name="email" placeholder="Enter your email" required />
            </div>
            <button type="submit" class="forgot-btn">Send Reset Link</button>
          </form>

          <div class="back-link">
            Remember your password? <a href="../Login/login.php">Back to Login</a>
          </div>
        </div>
      </div>

    </div>
  </div>

  <footer>
    <p>&copy; 2026 My Web System. All rights reserved.</p>
  </footer>

  <script>
    const forgotForm = document.getElementById('forgotForm');
    const errorMessage = document.getElementById('errorMessage');
    const successMessage = document.getElementById('successMessage');

    const urlParams = new URLSearchParams(window.location.search);
    const error = urlParams.get('error');
    const success = urlParams.get('success');

    if (error) { errorMessage.textContent = decodeURIComponent(error); errorMessage.classList.add('show'); }
    if (success) { successMessage.textContent = decodeURIComponent(success); successMessage.classList.add('show'); }

    forgotForm.addEventListener('submit', function(e) {
      const email = document.getElementById('email').value.trim();
      if (!email) { e.preventDefault(); showError('Please enter your email address'); return; }
      if (!validateEmail(email)) { e.preventDefault(); showError('Please enter a valid email address'); return; }
    });

    function showError(msg) {
      errorMessage.textContent = msg;
      errorMessage.classList.add('show');
      successMessage.classList.remove('show');
    }

    function validateEmail(email) {
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    document.getElementById('email').addEventListener('input', clearMessages);

    function clearMessages() {
      errorMessage.classList.remove('show');
      successMessage.classList.remove('show');
    }
  </script>
</body>
</html>