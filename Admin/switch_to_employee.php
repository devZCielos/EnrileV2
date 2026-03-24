<?php
// ============================================================
//  LGU ENRILE HRMS — Switch to Employee Account
//  File: Admin/switch_to_employee.php
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';

$allowedRoles = ['admin', 'hr_staff', 'dept_head'];
if (empty($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', $allowedRoles)) {
    header('Location: ../Login/login.php'); exit();
}

// Already switched — go straight to portal
if (!empty($_SESSION['switched_from_admin'])) {
    header('Location: ../Employee/Employee_portal.php'); exit();
}

$userId     = (int)$_SESSION['user_id'];
$adminName  = ucwords($_SESSION['user_name']  ?? 'Administrator');
$adminEmail = $_SESSION['user_email'] ?? '';
$roleLabels = ['admin'=>'Super Administrator','hr_staff'=>'HR Staff','dept_head'=>'Department Head'];
$roleLabel  = $roleLabels[$_SESSION['user_role']] ?? 'Admin';
$initials   = strtoupper(substr($adminName, 0, 2));

// ── Find linked employee record ─────────────────────────────
$empStmt = $pdo->prepare("
    SELECT e.employee_id, e.first_name, e.last_name,
           e.position_title, d.name AS dept_name
    FROM   employees e
    LEFT JOIN departments d ON d.department_id = e.department_id
    WHERE  e.user_id = ?
    LIMIT 1
");
$empStmt->execute([$userId]);
$emp = $empStmt->fetch();

$error = '';

require_once __DIR__ . '/../config/csrf.php';

// ── Handle POST (password confirmation) ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $password = $_POST['confirm_password'] ?? '';
    if (empty($password)) {
        $error = 'Please enter your password to continue.';
    } elseif (!$emp) {
        $error = 'No linked employee record found. Please link your account in the Employees page first.';
    } else {
        $pwStmt = $pdo->prepare("SELECT password_hash FROM user_accounts WHERE user_id = ?");
        $pwStmt->execute([$userId]);
        $pwRow = $pwStmt->fetch();
        if (!$pwRow || !password_verify($password, $pwRow['password_hash'])) {
            $error = 'Incorrect password. Please try again.';
        } else {
            // Perform the switch
            $_SESSION['switched_from_admin'] = true;
            $_SESSION['original_role']       = $_SESSION['user_role'];
            $_SESSION['original_user_name']  = $_SESSION['user_name'] ?? '';
            $_SESSION['user_role']           = 'employee';
            header('Location: ../Employee/Employee_portal.php'); exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Switch to Employee Account — LGU Enrile HRMS</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet"/>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body{min-height:100vh;font-family:'Nunito',sans-serif;background:linear-gradient(135deg,#1e3a8a 0%,#1e40af 35%,#2563eb 65%,#166534 100%);display:flex;align-items:center;justify-content:center;padding:20px;}
    .card{background:#fff;border-radius:22px;box-shadow:0 24px 64px rgba(0,0,0,.28);width:100%;max-width:480px;overflow:hidden;animation:slideUp .4s cubic-bezier(.4,0,.2,1) both;}
    @keyframes slideUp{from{opacity:0;transform:translateY(28px);}to{opacity:1;transform:translateY(0);}}
    .card-header{background:linear-gradient(135deg,#1e40af 0%,#2563eb 60%,#22c55e 100%);padding:28px 32px 24px;text-align:center;position:relative;}
    .back-link{position:absolute;top:16px;left:18px;color:rgba(255,255,255,.75);font-size:.80rem;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:5px;transition:color .2s;}
    .back-link:hover{color:#fff;}
    .header-icon{font-size:2.4rem;margin-bottom:10px;}
    .card-header h1{font-family:'Playfair Display',serif;font-size:1.45rem;color:#fff;margin-bottom:5px;}
    .card-header p{font-size:.80rem;color:rgba(255,255,255,.80);font-weight:600;}
    .identity-strip{display:flex;align-items:center;gap:14px;background:#f0f9ff;border-bottom:1px solid #e0f2fe;padding:16px 28px;}
    .id-avatar{width:46px;height:46px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,#2563eb,#22c55e);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.95rem;color:#fff;box-shadow:0 2px 8px rgba(37,99,235,.30);}
    .id-name{font-size:.90rem;font-weight:800;color:#1e293b;}
    .id-role{font-size:.72rem;font-weight:600;color:#64748b;margin-top:2px;}
    .id-email{font-size:.70rem;color:#94a3b8;margin-top:1px;}
    .card-body{padding:26px 32px 28px;}
    .emp-box{border-radius:14px;padding:16px 18px;margin-bottom:22px;display:flex;align-items:flex-start;gap:13px;}
    .emp-box.linked{background:#f0fdf4;border:1.5px solid #bbf7d0;}
    .emp-box.unlinked{background:#fff7ed;border:1.5px solid #fed7aa;}
    .emp-box-icon{font-size:1.6rem;margin-top:2px;flex-shrink:0;}
    .emp-box-title{font-size:.82rem;font-weight:800;color:#166534;margin-bottom:3px;}
    .emp-box.unlinked .emp-box-title{color:#9a3412;}
    .emp-box-name{font-size:.95rem;font-weight:800;color:#1e293b;}
    .emp-box-sub{font-size:.75rem;color:#64748b;margin-top:2px;font-weight:600;}
    .emp-box-steps{margin-top:10px;padding-left:16px;}
    .emp-box-steps li{font-size:.74rem;color:#92400e;font-weight:600;margin-bottom:4px;line-height:1.5;}
    .form-label{font-size:.82rem;font-weight:800;color:#374151;margin-bottom:6px;display:block;}
    .pw-wrap{position:relative;}
    .pw-input{width:100%;padding:11px 44px 11px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-family:'Nunito',sans-serif;font-size:.90rem;color:#1e293b;outline:none;transition:border-color .2s,box-shadow .2s;background:#f8fafc;}
    .pw-input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12);background:#fff;}
    .pw-input.err{border-color:#ef4444;box-shadow:0 0 0 3px rgba(239,68,68,.10);}
    .pw-input:disabled{opacity:.5;cursor:not-allowed;}
    .pw-toggle{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94a3b8;font-size:1rem;padding:4px;transition:color .2s;}
    .pw-toggle:hover{color:#2563eb;}
    .error-msg{background:#fef2f2;border:1.5px solid #fecaca;border-radius:10px;padding:10px 14px;font-size:.80rem;font-weight:700;color:#b91c1c;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
    .switch-btn{width:100%;padding:13px;background:linear-gradient(135deg,#d97706,#b45309);color:#fff;border:none;border-radius:12px;font-family:'Nunito',sans-serif;font-size:.95rem;font-weight:800;cursor:pointer;transition:all .2s;margin-top:18px;display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 4px 14px rgba(180,83,9,.35);}
    .switch-btn:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 6px 20px rgba(180,83,9,.45);}
    .switch-btn:disabled{background:linear-gradient(135deg,#94a3b8,#64748b);box-shadow:0 4px 14px rgba(100,116,139,.25);cursor:not-allowed;transform:none;opacity:.75;}
    .cancel-link{display:block;text-align:center;margin-top:14px;font-size:.80rem;font-weight:700;color:#94a3b8;text-decoration:none;transition:color .2s;}
    .cancel-link:hover{color:#475569;}
    .security-note{display:flex;align-items:center;gap:8px;font-size:.70rem;color:#94a3b8;font-weight:600;margin-top:18px;padding-top:16px;border-top:1px solid #f1f5f9;justify-content:center;}
  </style>
</head>
<body>
<div class="card">

  <div class="card-header">
    <a href="dashboard.php" class="back-link">← Back</a>
    <div class="header-icon">🔄</div>
    <h1>Switch to Employee Account</h1>
    <p>Access the Employee Portal using your personal employee record</p>
  </div>

  <div class="identity-strip">
    <div class="id-avatar"><?= $initials ?></div>
    <div>
      <div class="id-name"><?= htmlspecialchars($adminName) ?></div>
      <div class="id-role"><?= htmlspecialchars($roleLabel) ?></div>
      <div class="id-email"><?= htmlspecialchars($adminEmail) ?></div>
    </div>
  </div>

  <div class="card-body">

    <?php if ($emp): ?>
      <div class="emp-box linked">
        <div class="emp-box-icon">✅</div>
        <div>
          <div class="emp-box-title">Employee Record Found</div>
          <div class="emp-box-name"><?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) ?></div>
          <div class="emp-box-sub">
            <?= htmlspecialchars($emp['position_title'] ?? 'N/A') ?>
            <?php if (!empty($emp['dept_name'])): ?> &nbsp;·&nbsp; <?= htmlspecialchars($emp['dept_name']) ?><?php endif; ?>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="emp-box unlinked">
        <div class="emp-box-icon">⚠️</div>
        <div>
          <div class="emp-box-title">No Employee Record Linked</div>
          <div class="emp-box-sub" style="color:#9a3412;margin-bottom:4px;">Your admin account is not linked to an employee record yet.</div>
          <ol class="emp-box-steps">
            <li>Go to <strong>Employees</strong> page</li>
            <li>Find or create your employee record</li>
            <li>Click <strong>Edit</strong> → set <strong>User Account</strong> to your admin login</li>
            <li>Save, then come back here to switch</li>
          </ol>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="error-msg">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off" onsubmit="return handleSubmit()">
      <?= csrf_field() ?>
      <label class="form-label" for="confirm_password">🔐 Confirm Your Password</label>
      <div class="pw-wrap">
        <input
          type="password"
          id="confirm_password"
          name="confirm_password"
          class="pw-input<?= $error ? ' err' : '' ?>"
          placeholder="Enter your account password to continue…"
          <?= $emp ? 'autofocus' : 'disabled' ?>
        />
        <button type="button" class="pw-toggle" onclick="togglePw()" tabindex="-1">👁</button>
      </div>

      <button type="submit" class="switch-btn" id="switchBtn" <?= !$emp ? 'disabled' : '' ?>>
        <?= $emp ? '🔄 Switch to Employee Portal' : '🔒 Link Employee Record First' ?>
      </button>
    </form>

    <a href="dashboard.php" class="cancel-link">Cancel — Return to Dashboard</a>

    <div class="security-note">🔒 Your admin session is preserved and fully restored when you return.</div>
  </div>
</div>

<script>
function togglePw() {
  const inp = document.getElementById('confirm_password');
  inp.type = inp.type === 'password' ? 'text' : 'password';
}
function handleSubmit() {
  const pw  = document.getElementById('confirm_password');
  const btn = document.getElementById('switchBtn');
  if (!pw.value.trim()) {
    pw.classList.add('err');
    pw.focus();
    return false;
  }
  btn.disabled = true;
  btn.innerHTML = '⏳ Verifying…';
  return true;
}
document.getElementById('confirm_password')?.addEventListener('input', function(){
  this.classList.remove('err');
});
</script>
</body>
</html>