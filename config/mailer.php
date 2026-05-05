<?php
// ============================================================
//  LGU ENRILE HRMS — Centralised Mailer  (P3-4)
//  File: config/mailer.php
//
//  Usage: hrms_send_email($to, $subject, $bodyText)
//
//  To enable SMTP, set environment variables (in .env or
//  Apache/Nginx virtual-host config):
//    MAIL_HOST      smtp.gmail.com
//    MAIL_PORT      587
//    MAIL_USERNAME  your@email.com
//    MAIL_PASSWORD  yourapppassword
//    MAIL_FROM      noreply@lgu-enrile.gov.ph
//    MAIL_FROM_NAME LGU Enrile HRMS
//
//  Install PHPMailer via: composer require phpmailer/phpmailer
// ============================================================

/**
 * Send a plain-text email.
 *
 * Tries PHPMailer (SMTP) when the vendor autoloader and MAIL_HOST env
 * var are present; otherwise falls back to PHP's built-in mail().
 * Always error_log()s if delivery cannot be confirmed.
 *
 * @param  string $to       Recipient email address
 * @param  string $subject  Email subject
 * @param  string $bodyText Plain-text body
 * @return bool  true if the email was handed off to the transport
 */
function hrms_send_email(string $to, string $subject, string $bodyText): bool
{
    $from     = getenv('MAIL_FROM')      ?: 'noreply@lgu-enrile.gov.ph';
    $fromName = getenv('MAIL_FROM_NAME') ?: 'LGU Enrile HRMS';

    // ── PHPMailer (SMTP) path ─────────────────────────────────────────────
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload) && getenv('MAIL_HOST')) {
        require_once $autoload;
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = getenv('MAIL_HOST');
            $mail->SMTPAuth   = true;
            $mail->Username   = (string)(getenv('MAIL_USERNAME') ?: '');
            $mail->Password   = (string)(getenv('MAIL_PASSWORD') ?: '');
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)(getenv('MAIL_PORT') ?: 587);
            $mail->setFrom($from, $fromName);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body    = $bodyText;
            $mail->send();
            return true;
        } catch (\Exception $e) {
            error_log('[HRMS Mailer] PHPMailer error to ' . $to . ': ' . $mail->ErrorInfo);
            // Fall through to mail() fallback
        }
    }

    // ── Fallback: PHP built-in mail() ────────────────────────────────────
    $headers  = "From: {$fromName} <{$from}>\r\n";
    $headers .= "Reply-To: hr@lgu-enrile.gov.ph\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8";

    $sent = @mail($to, $subject, $bodyText, $headers);
    if (!$sent) {
        error_log('[HRMS Mailer] mail() failed to deliver to ' . $to . '. Subject: ' . $subject);
    }
    return $sent;
}
