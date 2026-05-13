<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/** Last mail failure hint for UI (no secrets). */
function mail_send_last_error(): string
{
    return (string) ($GLOBALS['__mail_send_last_error'] ?? '');
}

function mail_send_set_last_error(string $message): void
{
    $GLOBALS['__mail_send_last_error'] = $message;
}

function send_password_reset_email(string $toEmail, string $resetUrl): bool
{
    $GLOBALS['__mail_send_last_error'] = '';

    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $domain = preg_replace('/^www\./i', '', $host) ?: 'localhost';
    $fromEmail = MAIL_FROM !== '' ? MAIL_FROM : '';
    if ($fromEmail === '' && MAIL_SMTP_HOST !== '' && MAIL_SMTP_USER !== '') {
        $fromEmail = MAIL_SMTP_USER;
    }
    if ($fromEmail === '') {
        $fromEmail = 'noreply@' . $domain;
    }

    $fromName = defined('MAIL_FROM_NAME') && is_string(MAIL_FROM_NAME) && MAIL_FROM_NAME !== ''
        ? MAIL_FROM_NAME
        : APP_NAME;

    $subject = APP_NAME . ' — Password reset';
    $lines = [
        'Someone requested a password reset for this email address at ' . APP_NAME . '.',
        '',
        'Open this link within 1 hour to choose a new password:',
        $resetUrl,
        '',
        'If you did not request this, you can ignore this email. Your password will not change.',
    ];
    $body = implode("\r\n", $lines);

    if (MAIL_SMTP_HOST !== '') {
        return send_via_phpmailer($toEmail, $subject, $body, $fromEmail, $fromName);
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . mime_encode_header($fromName) . ' <' . $fromEmail . '>',
    ];
    $encodedSubject = mime_encode_header($subject, true);

    $ok = mail($toEmail, $encodedSubject, $body, implode("\r\n", $headers));
    if (!$ok) {
        $err = error_get_last();
        $detail = is_array($err) && isset($err['message']) ? (string) $err['message'] : '';
        error_log('[mail] mail() failed: ' . $detail);
        $hint = 'Set MAIL_SMTP_HOST (e.g. smtp.gmail.com), MAIL_SMTP_USER, and MAIL_SMTP_PASS (Gmail App Password). PHP mail() is usually disabled on cloud hosts.';
        if ($detail !== '') {
            mail_send_set_last_error('PHP mail() failed: ' . $detail . ' ' . $hint);
        } else {
            mail_send_set_last_error($hint);
        }
    }

    return $ok;
}

function send_login_otp_email(string $toEmail, string $otpCode): bool
{
    $GLOBALS['__mail_send_last_error'] = '';

    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $domain = preg_replace('/^www\./i', '', $host) ?: 'localhost';
    $fromEmail = MAIL_FROM !== '' ? MAIL_FROM : '';
    if ($fromEmail === '' && MAIL_SMTP_HOST !== '' && MAIL_SMTP_USER !== '') {
        $fromEmail = MAIL_SMTP_USER;
    }
    if ($fromEmail === '') {
        $fromEmail = 'noreply@' . $domain;
    }

    $fromName = defined('MAIL_FROM_NAME') && is_string(MAIL_FROM_NAME) && MAIL_FROM_NAME !== ''
        ? MAIL_FROM_NAME
        : APP_NAME;

    $subject = APP_NAME . ' — Sign-in code';
    $lines = [
        'Use this one-time code to finish signing in to ' . APP_NAME . ':',
        '',
        $otpCode,
        '',
        'This code expires in 10 minutes. If you did not try to sign in, ignore this email.',
    ];
    $body = implode("\r\n", $lines);

    if (MAIL_SMTP_HOST !== '') {
        return send_via_phpmailer($toEmail, $subject, $body, $fromEmail, $fromName);
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . mime_encode_header($fromName) . ' <' . $fromEmail . '>',
    ];
    $encodedSubject = mime_encode_header($subject, true);

    $ok = mail($toEmail, $encodedSubject, $body, implode("\r\n", $headers));
    if (!$ok) {
        $err = error_get_last();
        $detail = is_array($err) && isset($err['message']) ? (string) $err['message'] : '';
        error_log('[mail] mail() failed: ' . $detail);
        $hint = 'Set MAIL_SMTP_HOST (e.g. smtp.gmail.com), MAIL_SMTP_USER, and MAIL_SMTP_PASS (Gmail App Password). PHP mail() is usually disabled on cloud hosts.';
        if ($detail !== '') {
            mail_send_set_last_error('PHP mail() failed: ' . $detail . ' ' . $hint);
        } else {
            mail_send_set_last_error($hint);
        }
    }

    return $ok;
}

function send_via_phpmailer(string $toEmail, string $subject, string $body, string $fromEmail, string $fromName): bool
{
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) MAIL_SMTP_HOST;
        $mail->Port = (int) MAIL_SMTP_PORT;
        // Default is 300s — nginx/Railway often returns 504 first; fail fast with a clear error.
        $mail->Timeout = 25;

        $enc = strtolower(trim((string) MAIL_SMTP_ENCRYPTION));
        if ($enc === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($enc === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
        }

        $smtpUser = trim((string) MAIL_SMTP_USER);
        $smtpPass = str_replace(' ', '', trim((string) MAIL_SMTP_PASS));
        if ($smtpUser !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;
        } else {
            $mail->SMTPAuth = false;
        }

        $mail->CharSet = 'UTF-8';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->isHTML(false);

        return $mail->send();
    } catch (PHPMailerException $e) {
        $msg = $e->getMessage();
        error_log('[mail] PHPMailer failed: ' . $msg);
        mail_send_set_last_error(
            'SMTP send failed. For Gmail use MAIL_SMTP_HOST=smtp.gmail.com, port 587, encryption tls, '
            . 'MAIL_SMTP_USER=your address, MAIL_SMTP_PASS=16-char App Password, and set MAIL_FROM to the same address. '
            . 'Details (server log): ' . substr($msg, 0, 400)
        );

        return false;
    } catch (\Throwable $e) {
        error_log('[mail] PHPMailer error: ' . $e->getMessage());
        mail_send_set_last_error('SMTP error: ' . substr($e->getMessage(), 0, 400));

        return false;
    }
}

function mime_encode_header(string $text, bool $isSubject = false): string
{
    if ($text === '') {
        return '';
    }
    if (preg_match('/[^\x20-\x7E]/', $text)) {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }
    if ($isSubject && strpbrk($text, "\r\n") !== false) {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }

    return $text;
}
