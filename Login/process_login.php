<?php
// ============================================================
//  LGU ENRILE HRMS — Login Processor
//  File: Login/process_login.php
// ============================================================

session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit();
}

csrf_verify(); // Reject forged cross-site POST requests

$email    = trim($_POST['email']    ?? '');
$password =      $_POST['password'] ?? '';
$role     = trim(strtolower($_POST['role'] ?? 'admin'));
$remember =      $_POST['remember'] ?? '';

if (empty($email) || empty($password)) {
    redirectBack('Email and password are required.', $email, $role);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirectBack('Please enter a valid email address.', $email, $role);
}
if (strlen($password) < 6) {
    redirectBack('Password must be at least 6 characters.', $email, $role);
}

try {
    $stmt = $pdo->prepare("
        SELECT user_id, email, password_hash, role, is_active
        FROM   user_accounts
        WHERE  email = ?
        LIMIT  1
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    error_log('[HRMS] Login DB error: ' . $e->getMessage());
    redirectBack('A system error occurred. Please try again later.', $email, $role);
}

if (!$user || !password_verify($password, $user['password_hash'])) {
    redirectBack('Incorrect email or password.', $email, $role);
}

if (!(bool)$user['is_active']) {
    redirectBack('Your account has been deactivated. Please contact the HR Administrator.', $email, $role);
}

// ── Role mismatch check ───────────────────────────────────────
// If someone clicks "Employee" toggle but their DB role is admin (or vice versa),
// warn them instead of silently redirecting to the wrong portal.
$dbRole      = $user['role'];
$isAdminRole = in_array($dbRole, ['admin', 'hr_staff', 'dept_head']);

if ($role === 'employee' && $isAdminRole) {
    redirectBack('This account is an Admin/HR account. Please use the Admin/HR login.', $email, $role);
}
if ($role !== 'employee' && $dbRole === 'employee') {
    redirectBack('This account is an Employee account. Please use the Employee login.', $email, $role);
}

// ── Regenerate session (clear any old admin session) ──────────
session_unset();
session_destroy();
session_start();
session_regenerate_id(true);

// ── Store session variables ───────────────────────────────────
$_SESSION['user_id']    = $user['user_id'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_role']  = $user['role'];
$_SESSION['login_time'] = date('Y-m-d H:i:s');

// ── Get display name: prefer employees table, fallback to email prefix ──
try {
    $empStmt = $pdo->prepare("
        SELECT CONCAT(first_name, ' ', last_name) AS full_name
        FROM   employees
        WHERE  user_id = ?
        LIMIT  1
    ");
    $empStmt->execute([$user['user_id']]);
    $empName = $empStmt->fetchColumn();
    if ($empName) {
        $_SESSION['user_name'] = $empName;
    } else {
        $prefix = explode('@', $user['email'])[0];
        $_SESSION['user_name'] = ucwords(str_replace(['.', '_', '-'], ' ', $prefix));
    }
} catch (PDOException $e) {
    $prefix = explode('@', $user['email'])[0];
    $_SESSION['user_name'] = ucwords(str_replace(['.', '_', '-'], ' ', $prefix));
}

// ── Remember Me ───────────────────────────────────────────────
if ($remember === 'on') {
    try {
        $token     = bin2hex(random_bytes(32));       // raw token — sent to browser cookie
        $tokenHash = hash('sha256', $token);           // hashed token — stored in DB only
        setcookie('remember_token', $token, [
            'expires'  => time() + (30 * 24 * 3600),
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $pdo->prepare("UPDATE user_accounts SET remember_token = ? WHERE user_id = ?")
            ->execute([$tokenHash, $user['user_id']]);
    } catch (Exception $e) {
        error_log('[HRMS] Remember token error: ' . $e->getMessage());
    }
}

// ── Update last_login ─────────────────────────────────────────
try {
    $pdo->prepare("UPDATE user_accounts SET last_login = NOW() WHERE user_id = ?")
        ->execute([$user['user_id']]);
} catch (PDOException $e) {
    error_log('[HRMS] last_login update error: ' . $e->getMessage());
}

// ── Activity log ──────────────────────────────────────────────
try {
    $pdo->prepare("
        INSERT INTO activity_logs (user_id, action, module, ip_address)
        VALUES (?, ?, 'Login', ?)
    ")->execute([
        $user['user_id'],
        'Logged in: ' . $user['email'],
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
} catch (PDOException $e) {
    error_log('[HRMS] Activity log error: ' . $e->getMessage());
}

// ── Redirect ──────────────────────────────────────────────────
if ($isAdminRole) {
    header('Location: ../Admin/dashboard.php');
} else {
    header('Location: ../Employee/Employee_portal.php');
}
exit();

// ── Helper ────────────────────────────────────────────────────
function redirectBack(string $message, string $email = '', string $role = 'admin'): void
{
    $qs = http_build_query([
        'error' => $message,
        'email' => $email,
        'role'  => $role,
    ]);
    header('Location: login.php?' . $qs);
    exit();
}