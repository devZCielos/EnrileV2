<?php
// ============================================================
//  LGU ENRILE HRMS — Forgot Password Processor
//  File: Landingpage/process_forgot_password.php
//
//  Flow:
//  1. Validate the submitted email
//  2. Look up the email in user_accounts
//  3. Generate a secure token + store with a 1-hour expiry
//  4. Send a reset link via PHP mail()
//  5. Always show a success message (never reveal if email exists)
// ============================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../Landingpage/forgot_password.php');
    exit();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/mailer.php';
csrf_verify();

// ── Collect & validate input ──────────────────────────────────
$email = trim($_POST['email'] ?? '');

if (empty($email)) {
    header('Location: ../Landingpage/forgot_password.php?error=' . urlencode('Email address is required.'));
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: ../Landingpage/forgot_password.php?error=' . urlencode('Please enter a valid email address.'));
    exit();
}

// ── Look up email & send reset link ───────────────────────────
// We wrap everything in try/catch and ALWAYS show the same
// success message — this prevents email enumeration attacks.
try {

    $stmt = $pdo->prepare("SELECT user_id FROM user_accounts WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {

        // M-3 — Rate-limit: one reset request per 5 minutes per account
        $lastReset = $pdo->prepare('SELECT reset_requested_at FROM user_accounts WHERE user_id = ?');
        $lastReset->execute([$user['user_id']]);
        $lastRequestedAt = $lastReset->fetchColumn();
        if ($lastRequestedAt) {
            if ((time() - strtotime($lastRequestedAt)) < 300) {
                // Silently skip — don’t reveal rate-limit to prevent enumeration
                error_log('[HRMS] Reset rate-limited for user_id=' . $user['user_id']);
                // Fall through to show same success message
                $user = null;
            }
        }
    }

    if ($user) {
        $rawToken  = bin2hex(random_bytes(32));          // 64-char hex — sent in email
        $hashToken = hash('sha256', $rawToken);          // stored in DB (never the raw value)
        $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour from now

        // Store the hashed token + expiry in dedicated columns.
        // remember_token (used by "Stay logged in") is left untouched.
        $pdo->prepare("
            UPDATE user_accounts
            SET    reset_token   = ?,
                     reset_expires = ?,
                     reset_requested_at = NOW()
            WHERE  user_id = ?
        ")->execute([$hashToken, $expiresAt, $user['user_id']]);

        // ── 2. Build the reset URL ────────────────────────────
        // Use the hardcoded APP_URL constant — never $_SERVER['HTTP_HOST'],
        // which is attacker-controlled and enables Host Header Injection.
        if (!defined('APP_URL')) {
            require_once __DIR__ . '/../config/auth.php';
        }
        $resetUrl = rtrim(APP_URL, '/') . '/Login/reset_password.php'
                  . '?token=' . urlencode($rawToken)
                  . '&email=' . urlencode($email);

        // ── 3. Compose the email ──────────────────────────────
        $subject  = 'LGU Enrile HRMS — Password Reset Request';
        $bodyText = "Hello,\r\n\r\n"
                  . "We received a request to reset your password for the\r\n"
                  . "LGU Enrile HR Management System.\r\n\r\n"
                  . "Click the link below to reset your password.\r\n"
                  . "This link will expire in 1 hour.\r\n\r\n"
                  . $resetUrl . "\r\n\r\n"
                  . "If you did not request a password reset, you can safely\r\n"
                  . "ignore this email. Your password will not be changed.\r\n\r\n"
                  . "— LGU Enrile HR Administrator";

        // ── 4. Send via centralised mailer (SMTP or mail() fallback) ────────
        $sent = hrms_send_email($email, $subject, $bodyText);

        // Always log the reset link for XAMPP/dev environments where email cannot be sent.
        if (!$sent) {
            error_log('[HRMS] Reset email not sent. Link: ' . $resetUrl);
        }

        // ── 5. Log the action ─────────────────────────────────
        $pdo->prepare("
            INSERT INTO activity_logs (user_id, action, module, ip_address)
            VALUES (?, 'Password reset requested', 'Auth', ?)
        ")->execute([$user['user_id'], $_SERVER['REMOTE_ADDR'] ?? null]);
    }
    // If $user is false (email not found), we do nothing — same response

} catch (PDOException $e) {
    error_log('[HRMS] Forgot password DB error: ' . $e->getMessage());
    // Still fall through to the success redirect
}

// ── Always show success (don't reveal if email exists) ────────
header('Location: ../Landingpage/forgot_password.php?success=' . urlencode(
    'If an account exists with that email address, a password reset link has been sent. Please check your inbox (and spam folder).'
));
exit();