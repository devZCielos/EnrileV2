<?php
// ============================================================
//  LGU ENRILE HRMS — Employee Portal Shared Auth
//  File: Employee/_shared/auth.php
// ============================================================

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    // P3-5 — Harden session cookie before session is created
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// ── Security headers ──────────────────────────────────────────
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
// P3-6 — CSP
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'none';");

// ── Session timeout — 8 hours ──
$_sessionLifetime = 8 * 60 * 60;
if (isset($_SESSION['login_time']) && (time() - strtotime($_SESSION['login_time']) > $_sessionLifetime)) {
    $_isAjax = !empty($_GET['action']) || !empty($_POST['action']);
    session_unset(); session_destroy();
    if ($_isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'message'=>'Your session has expired. Please log in again.']);
        exit();
    }
    header('Location: ../Login/login.php?error=' . urlencode('Your session has expired. Please log in again.'));
    exit();
}

// ── Check if logged in ──
if (empty($_SESSION['user_id']) || empty($_SESSION['user_email'])) {
    $_isAjax = !empty($_GET['action']) || !empty($_POST['action']);
    if ($_isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'message'=>'Not logged in. Please refresh the page.']);
        exit();
    }
    header('Location: ../Login/login.php'); exit();
}

// ── Check if employee role ──
if ($_SESSION['user_role'] !== 'employee' && empty($_SESSION['switched_from_admin'])) {
    $_isAjax = !empty($_GET['action']) || !empty($_POST['action']);
    if ($_isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'message'=>'Access denied.']);
        exit();
    }
    header('Location: ../Admin/dashboard.php'); exit();
}

// ── Include configs ──
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/csrf.php';

// ── Get user ID ──
$userId = (int)$_SESSION['user_id'];

// ── Resolve employee_id from user_id ──
$empRow = $pdo->prepare("SELECT employee_id, employment_type FROM employees WHERE user_id = ?");
$empRow->execute([$userId]);
$empRowData = $empRow->fetch();
$employeeId = (int)($empRowData['employee_id'] ?? 0);
$employmentType = $empRowData['employment_type'] ?? 'Permanent';

// ── Helper: Check employment type for leave-related features ──
function checkEmploymentTypeForLeave() {
    global $employmentType;
    if ($employmentType !== 'Permanent') {
        return false;
    }
    return true;
}

// ── Helper: Get employment type ──
function getEmploymentType() {
    global $employmentType;
    return $employmentType;
}

// ── Helper: Get employee ID ──
function getEmployeeId() {
    global $employeeId;
    return $employeeId;
}

// ── Helper: Get user ID ──
function getUserId() {
    global $userId;
    return $userId;
}

// Upload configuration
define('UPLOAD_DIR', __DIR__ . '/../../uploads/documents/');
define('UPLOAD_URL', '../uploads/documents/');
if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

define('PHOTO_DIR', __DIR__ . '/../../uploads/profile_photos/');
define('PHOTO_URL', '../uploads/profile_photos/');
if (!is_dir(PHOTO_DIR)) mkdir(PHOTO_DIR, 0755, true);

// Allowed MIME types
$GLOBALS['allowedMimes'] = [
    'application/pdf'  => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
];

$GLOBALS['maxFileSize'] = 10 * 1024 * 1024; // 10 MB
?>