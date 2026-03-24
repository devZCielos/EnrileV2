<?php
// ============================================================
//  LGU ENRILE HRMS — Switch Back to Admin
//  File: Admin/switch_back_admin.php
// ============================================================
session_start();

// Must have been switched from admin
if (empty($_SESSION['switched_from_admin']) || empty($_SESSION['original_role'])) {
    header('Location: ../Login/login.php'); exit();
}

// ── Restore original admin session ──────────────────────────
$_SESSION['user_role'] = $_SESSION['original_role'];
if (!empty($_SESSION['original_user_name'])) {
    $_SESSION['user_name'] = $_SESSION['original_user_name'];
}

// Clean up the switch flags
unset($_SESSION['switched_from_admin']);
unset($_SESSION['original_role']);
unset($_SESSION['original_user_name']);

header('Location: ../Admin/dashboard.php'); exit();