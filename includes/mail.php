<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

function send_password_reset_email(string $toEmail, string $resetUrl): bool
{
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

    return @mail($toEmail, $encodedSubject, $body, implode("\r\n", $headers));
}

function send_via_phpmailer(string $toEmail, string $subject, string $body, string $fromEmail, string $fromName): bool
{
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) MAIL_SMTP_HOST;
        $mail->Port = (int) MAIL_SMTP_PORT;

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
        error_log('[mail] PHPMailer failed: ' . $e->getMessage());

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
