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

        // ── 1. Generate a cryptographically secure token ──────
        $rawToken  = bin2hex(random_bytes(32));          // 64-char hex — sent in email
        $hashToken = hash('sha256', $rawToken);          // stored in DB (never the raw value)
        $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour from now

        // Store the hashed token + expiry in dedicated columns.
        // remember_token (used by "Stay logged in") is left untouched.
        $pdo->prepare("
            UPDATE user_accounts
            SET    reset_token   = ?,
                   reset_expires = ?
            WHERE  user_id = ?
        ")->execute([$hashToken, $expiresAt, $user['user_id']]);

        // ── 2. Build the reset URL ────────────────────────────
        $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $resetUrl  = $protocol . '://' . $host
                   . '/enrile/Login/reset_password.php'
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

        // ── 4. Send via PHPMailer (SMTP) or fall back to mail() ──────────
        // Install PHPMailer with: composer require phpmailer/phpmailer
        // Then set MAIL_* values in your .env file.
        $sent = false;
        $phpMailerPath = __DIR__ . '/../vendor/autoload.php';

        if (file_exists($phpMailerPath) && getenv('MAIL_HOST')) {
            // ── PHPMailer (SMTP) path ─────────────────────────
            require_once $phpMailerPath;
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = getenv('MAIL_HOST');
                $mail->SMTPAuth   = true;
                $mail->Username   = getenv('MAIL_USERNAME');
                $mail->Password   = getenv('MAIL_PASSWORD');
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = (int)(getenv('MAIL_PORT') ?: 587);
                $mail->setFrom(
                    getenv('MAIL_FROM')      ?: 'noreply@lgu-enrile.gov.ph',
                    getenv('MAIL_FROM_NAME') ?: 'LGU Enrile HRMS'
                );
                $mail->addAddress($email);
                $mail->Subject = $subject;
                $mail->Body    = $bodyText;
                $mail->send();
                $sent = true;
            } catch (\Exception $e) {
                error_log('[HRMS] PHPMailer error: ' . $mail->ErrorInfo);
            }
        }

        if (!$sent) {
            // ── Fallback: PHP mail() (works on some servers, not XAMPP) ──
            $headers  = "From: noreply@lgu-enrile.gov.ph\r\n";
            $headers .= "Reply-To: hr@lgu-enrile.gov.ph\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8";
            $sent = @mail($email, $subject, $bodyText, $headers);
        }

        // Always store reset URL in session for XAMPP dev convenience
        // (visible on the forgot-password page when email cannot be sent)
        session_start();
        $_SESSION['reset_link']  = $resetUrl;
        $_SESSION['reset_email'] = $email;
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