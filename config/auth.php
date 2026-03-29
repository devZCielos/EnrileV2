<?php
// ============================================================
//  LGU ENRILE HRMS — Admin Auth Guard
//  File: config/auth.php
//
//  Usage — replace the manual session/role block at the top
//  of every Admin page with one line:
//
//    require_once __DIR__ . '/../config/auth.php';
//    require_admin_auth(['admin', 'hr_staff', 'dept_head']);
//
//  The function handles:
//    1. Session presence check (redirects to login if missing)
//    2. 8-hour session timeout (redirects with expiry message)
//    3. Role whitelist check (redirects to Employee portal if wrong role)
//    4. AJAX-aware responses (returns JSON instead of redirect for XHR)
// ============================================================

// Session timeout — 8 hours (matches Employee portal)
define('SESSION_LIFETIME', 8 * 60 * 60);

/**
 * Enforce admin authentication and session timeout.
 *
 * @param string[] $allowedRoles  Roles permitted to access the page.
 *                                Defaults to all admin roles.
 */
function require_admin_auth(array $allowedRoles = ['admin', 'hr_staff', 'dept_head']): void
{
    // ── Security headers ──────────────────────────────────────────────────
    // Sent on every authenticated request so they can't be missed.
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

    // Detect AJAX: action param present (all admin AJAX pages use ?action=...)
    $isAjax = !empty($_GET['action']) || !empty($_POST['action']);

    // ── 1. Session presence ───────────────────────────────────────────────
    if (empty($_SESSION['user_id']) || empty($_SESSION['user_email'])) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Not logged in. Please refresh the page.']);
            exit();
        }
        header('Location: ../Login/login.php');
        exit();
    }

    // ── 2. Session timeout ────────────────────────────────────────────────
    if (isset($_SESSION['login_time'])) {
        if (time() - strtotime($_SESSION['login_time']) > SESSION_LIFETIME) {
            session_unset();
            session_destroy();
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Your session has expired. Please log in again.']);
                exit();
            }
            header('Location: ../Login/login.php?error=' . urlencode('Your session has expired. Please log in again.'));
            exit();
        }
    }

    // ── 3. Role check ─────────────────────────────────────────────────────
    if (!in_array($_SESSION['user_role'] ?? '', $allowedRoles)) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Access denied.']);
            exit();
        }
        header('Location: ../Employee/Employee_portal.php');
        exit();
    }
}