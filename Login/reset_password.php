<?php
// ============================================================
//  reset_password.php
//  File: Login/reset_password.php
//  Handles GET (validate token / show form) and POST (save pw)
// ============================================================
session_start();                                  // MUST be first — before any require
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';

$token     = trim($_GET['token'] ?? $_POST['token'] ?? '');
$email     = trim($_GET['email'] ?? $_POST['email'] ?? '');
$alertHtml = '';
$showForm  = false;

// ── GET: validate token and show form ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    if (empty($token) || empty($email)) {
        $alertHtml = '<div class="alert warning">⚠️ Invalid or missing reset link. Please request a new one.</div>';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT user_id, remember_token FROM user_accounts WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user || empty($user['remember_token'])) {
                $alertHtml = '<div class="alert error">❌ This reset link is invalid. Please request a new one.</div>';
            } else {
                [$storedToken, $expiresAt] = explode('|', $user['remember_token'] . '|', 2);
                if (!hash_equals(trim($storedToken), $token)) {
                    $alertHtml = '<div class="alert error">❌ This reset link is invalid.</div>';
                } elseif (strtotime($expiresAt) < time()) {
                    $alertHtml = '<div class="alert warning">⏰ This link has expired. Please request a new one.</div>';
                } else {
                    $showForm = true; // ✅ Token valid
                }
            }
        } catch (PDOException $e) {
            error_log('[HRMS] Reset check error: ' . $e->getMessage());
            $alertHtml = '<div class="alert error">❌ A system error occurred. Please try again.</div>';
        }
    }
}

// ── POST: save new password ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $newPw   = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (empty($token) || empty($email)) {
        $alertHtml = '<div class="alert error">❌ Invalid request. Use the link from your email.</div>';
    } elseif (strlen($newPw) < 8) {
        $alertHtml = '<div class="alert error">❌ Password must be at least 8 characters.</div>';
        $showForm  = true;
    } elseif ($newPw !== $confirm) {
        $alertHtml = '<div class="alert error">❌ Passwords do not match. Please try again.</div>';
        $showForm  = true;
    } else {
        try {
            $stmt = $pdo->prepare("SELECT user_id, remember_token FROM user_accounts WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user || empty($user['remember_token'])) {
                $alertHtml = '<div class="alert error">❌ Invalid reset request.</div>';
            } else {
                [$storedToken, $expiresAt] = explode('|', $user['remember_token'] . '|', 2);

                if (!hash_equals(trim($storedToken), $token) || strtotime($expiresAt) < time()) {
                    $alertHtml = '<div class="alert error">❌ This reset link has expired. Please request a new one.</div>';
                } else {
                    // ✅ Save new password and clear the token
                    $hash = password_hash($newPw, PASSWORD_BCRYPT);
                    $pdo->prepare("
                        UPDATE user_accounts
                        SET    password_hash  = ?,
                               remember_token = NULL
                        WHERE  user_id = ?
                    ")->execute([$hash, $user['user_id']]);

                    // Log it
                    $pdo->prepare("
                        INSERT INTO activity_logs (user_id, action, module, ip_address)
                        VALUES (?, 'Password reset successfully', 'Auth', ?)
                    ")->execute([$user['user_id'], $_SERVER['REMOTE_ADDR'] ?? null]);

                    // Redirect to login with success
                    header('Location: ../Login/login.php?success=' . urlencode(
                        'Password reset successful! Please log in with your new password.'
                    ));
                    exit();
                }
            }
        } catch (PDOException $e) {
            error_log('[HRMS] Reset save error: ' . $e->getMessage());
            $alertHtml = '<div class="alert error">❌ A system error occurred. Please try again.</div>';
            $showForm  = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Reset Password | LGU Enrile HRMS</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    :root { --primary:#2563eb; --secondary:#0f172a; --accent:#22c55e; --error:#ef4444; }
    * { box-sizing:border-box; margin:0; padding:0; font-family:'Nunito',sans-serif; }
    body { background:linear-gradient(135deg,#2563eb 0%,#1e40af 50%,#22c55e 100%); min-height:100vh; display:flex; flex-direction:column; }
    header { background:white; padding:15px 40px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 2px 6px rgba(0,0,0,0.05); position:sticky; top:0; z-index:100; }
    header h1 { font-size:1.5rem; font-family:'Playfair Display',serif; }
    nav a { margin-left:20px; text-decoration:none; color:var(--secondary); font-weight:700; transition:color 0.3s; }
    nav a:hover { color:var(--primary); }
    .page-wrapper { flex:1; display:flex; align-items:center; justify-content:center; padding:40px 20px 80px; }
    .reset-box { width:100%; max-width:440px; background:rgba(255,255,255,0.20); backdrop-filter:blur(14px); -webkit-backdrop-filter:blur(14px); border:1px solid rgba(255,255,255,0.35); border-radius:18px; padding:40px 36px; box-shadow:0 8px 32px rgba(0,0,0,0.18); animation:fadeUp 0.7s ease both; }
    .reset-header { text-align:center; margin-bottom:26px; }
    .reset-icon { font-size:2.4rem; margin-bottom:10px; }
    .reset-box h2 { font-family:'Playfair Display',serif; font-size:1.7rem; font-weight:700; background:linear-gradient(135deg,#ffffff,#d1fae5); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; margin-bottom:6px; }
    .reset-header p { color:rgba(255,255,255,0.72); font-size:0.88rem; }
    .form-group { margin-bottom:18px; }
    label { display:block; margin-bottom:7px; font-weight:700; color:rgba(255,255,255,0.95); font-size:0.90rem; }
    input[type="password"] { width:100%; padding:12px; border:2px solid rgba(255,255,255,0.38); border-radius:6px; font-size:1rem; font-family:'Nunito',sans-serif; background:rgba(255,255,255,0.14); color:#fff; transition:border-color 0.3s,background 0.3s; }
    input[type="password"]::placeholder { color:rgba(255,255,255,0.45); }
    input[type="password"]:focus { outline:none; border-color:rgba(255,255,255,0.80); background:rgba(255,255,255,0.22); box-shadow:0 0 0 3px rgba(255,255,255,0.13); }
    .strength-bar { height:4px; border-radius:4px; background:rgba(255,255,255,0.15); margin-top:8px; overflow:hidden; }
    .strength-fill { height:100%; border-radius:4px; width:0%; transition:width 0.3s,background 0.3s; }
    .strength-label { font-size:0.68rem; color:rgba(255,255,255,0.55); margin-top:4px; min-height:14px; }
    .reset-btn { width:100%; padding:13px; background:linear-gradient(to right,var(--primary),#1d4ed8); color:white; border:none; border-radius:8px; font-size:1rem; font-weight:700; font-family:'Nunito',sans-serif; cursor:pointer; margin-top:6px; transition:transform 0.2s,box-shadow 0.2s; display:flex; align-items:center; justify-content:center; gap:8px; }
    .reset-btn:hover { transform:translateY(-2px); box-shadow:0 4px 14px rgba(37,99,235,0.45); }
    .back-link { text-align:center; margin-top:20px; font-size:0.88rem; color:rgba(255,255,255,0.72); }
    .back-link a { color:#d1fae5; font-weight:700; text-decoration:none; }
    .back-link a:hover { color:#fff; }
    .alert { padding:12px 14px; border-radius:7px; margin-bottom:18px; font-size:0.86rem; font-weight:600; display:flex; align-items:flex-start; gap:8px; }
    .alert.error   { background:#fee2e2; color:#991b1b; border-left:4px solid #ef4444; }
    .alert.success { background:#dcfce7; color:#166534; border-left:4px solid #22c55e; }
    .alert.warning { background:#fef3c7; color:#92400e; border-left:4px solid #f59e0b; }
    footer { background:var(--secondary); color:white; text-align:center; padding:18px; position:fixed; bottom:0; width:100%; font-size:0.82rem; }
    @keyframes fadeUp { from { opacity:0; transform:translateY(24px); } to { opacity:1; transform:translateY(0); } }
    @media(max-width:480px) { .reset-box { padding:28px 20px; } header { padding:14px 20px; } }
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

  <div class="page-wrapper">
    <div class="reset-box">

      <div class="reset-header">
        <div class="reset-icon">🔐</div>
        <h2>Reset Password</h2>
        <p><?= $showForm ? 'Enter your new password below.' : 'LGU Enrile HR Management System' ?></p>
      </div>

      <?= $alertHtml ?>

      <?php if ($showForm): ?>
      <form method="POST" action="reset_password.php" id="resetForm">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>" />
        <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>" />

        <div class="form-group">
          <label for="new_password">New Password</label>
          <input type="password" id="new_password" name="new_password"
                 placeholder="Minimum 8 characters" required
                 oninput="checkStrength(this.value)" />
          <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
          <div class="strength-label" id="strengthLabel"></div>
        </div>

        <div class="form-group">
          <label for="confirm_password">Confirm New Password</label>
          <input type="password" id="confirm_password" name="confirm_password"
                 placeholder="Re-enter your new password" required />
        </div>

        <button type="submit" class="reset-btn">🔑 Reset My Password</button>
      </form>
      <?php endif; ?>

      <div class="back-link">
        <?= $showForm
            ? 'Remembered it? <a href="../Login/login.php">Back to Login</a>'
            : 'Need a new link? <a href="forgot_password.php">Request Reset</a>' ?>
      </div>

    </div>
  </div>

  <footer>
    © 2026 LGU Enrile HRMS. All rights reserved.
  </footer>

  <script>
    function checkStrength(pw) {
      const fill  = document.getElementById('strengthFill');
      const label = document.getElementById('strengthLabel');
      let strength = 0;
      if (pw.length >= 8) strength += 1;
      if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) strength += 1;
      if (/\d/.test(pw)) strength += 1;
      if (/[^a-zA-Z\d]/.test(pw)) strength += 1;
      const widths = ['0%','25%','50%','75%','100%'];
      const colors = ['#ef4444','#f97316','#eab308','#84cc16','#22c55e'];
      const texts  = ['Very weak','Weak','Fair','Good','Strong'];
      fill.style.width           = widths[strength];
      fill.style.backgroundColor = colors[strength];
      label.textContent          = texts[strength];
    }
  </script>

</body>
</html>