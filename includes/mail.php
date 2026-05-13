<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

/** Last mail failure hint for UI (no secrets). */
function mail_send_last_error(): string
{
    return (string) ($GLOBALS['__mail_send_last_error'] ?? '');
}

function mail_send_set_last_error(string $message): void
{
    $GLOBALS['__mail_send_last_error'] = $message;
}

/**
 * @return array{email: string, name: string}
 */
function mail_sender_identity(): array
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $domain = preg_replace('/^www\./i', '', $host) ?: 'localhost';
    $fromEmail = MAIL_FROM !== '' ? MAIL_FROM : ('noreply@' . $domain);

    $fromName = defined('MAIL_FROM_NAME') && is_string(MAIL_FROM_NAME) && MAIL_FROM_NAME !== ''
        ? MAIL_FROM_NAME
        : APP_NAME;

    return ['email' => $fromEmail, 'name' => $fromName];
}

function send_password_reset_email(string $toEmail, string $resetUrl): bool
{
    $subject = APP_NAME . ' — Password reset';
    $lines = [
        'Someone requested a password reset for this email address at ' . APP_NAME . '.',
        '',
        'Open this link within 1 hour to choose a new password:',
        $resetUrl,
        '',
        'If you did not request this, you can ignore this email. Your password will not change.',
    ];

    return mail_send_plain_text($toEmail, $subject, implode("\r\n", $lines));
}

function send_login_otp_email(string $toEmail, string $otpCode): bool
{
    $subject = APP_NAME . ' — Sign-in code';
    $lines = [
        'Use this one-time code to finish signing in to ' . APP_NAME . ':',
        '',
        $otpCode,
        '',
        'This code expires in 10 minutes. If you did not try to sign in, ignore this email.',
    ];

    return mail_send_plain_text($toEmail, $subject, implode("\r\n", $lines));
}

function mail_send_plain_text(string $toEmail, string $subject, string $bodyPlain): bool
{
    $GLOBALS['__mail_send_last_error'] = '';
    $sender = mail_sender_identity();

    if (BREVO_API_KEY !== '') {
        return send_via_brevo_api($toEmail, $subject, $bodyPlain, $sender['email'], $sender['name']);
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . mime_encode_header($sender['name']) . ' <' . $sender['email'] . '>',
    ];
    $encodedSubject = mime_encode_header($subject, true);

    $ok = mail($toEmail, $encodedSubject, $bodyPlain, implode("\r\n", $headers));
    if (!$ok) {
        $err = error_get_last();
        $detail = is_array($err) && isset($err['message']) ? (string) $err['message'] : '';
        error_log('[mail] mail() failed: ' . $detail);
        $hint = 'Set BREVO_API_KEY (recommended for Railway). PHP mail() is often disabled on cloud hosts.';
        if ($detail !== '') {
            mail_send_set_last_error('PHP mail() failed: ' . $detail . ' ' . $hint);
        } else {
            mail_send_set_last_error($hint);
        }
    }

    return $ok;
}

function send_via_brevo_api(string $toEmail, string $subject, string $bodyPlain, string $fromEmail, string $fromName): bool
{
    $key = trim((string) BREVO_API_KEY);
    if ($key === '') {
        return false;
    }

    try {
        $payload = [
            'sender' => ['name' => $fromName, 'email' => $fromEmail],
            'to' => [['email' => $toEmail]],
            'subject' => $subject,
            'textContent' => $bodyPlain,
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    } catch (\JsonException $e) {
        error_log('[mail] Brevo JSON: ' . $e->getMessage());
        mail_send_set_last_error('Could not build email payload.');

        return false;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        if ($ch === false) {
            mail_send_set_last_error('Could not start email request.');

            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'content-type: application/json',
                'api-key: ' . $key,
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            error_log('[mail] Brevo curl: ' . $curlErr);
            mail_send_set_last_error('Email send failed (network). Check server logs.');

            return false;
        }

        return mail_brevo_handle_response($httpCode, is_string($response) ? $response : '');
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "accept: application/json\r\ncontent-type: application/json\r\napi-key: {$key}\r\n",
            'content' => $json,
            'timeout' => 25,
            'ignore_errors' => true,
        ],
    ]);
    $response = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $ctx);
    $httpCode = 0;
    if (isset($http_response_header) && isset($http_response_header[0]) && is_string($http_response_header[0])) {
        if (preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
            $httpCode = (int) $m[1];
        }
    }
    if ($response === false) {
        error_log('[mail] Brevo HTTP request failed');
        mail_send_set_last_error('Email send failed (enable curl or allow_url_fopen).');

        return false;
    }

    return mail_brevo_handle_response($httpCode, (string) $response);
}

function mail_brevo_handle_response(int $httpCode, string $responseBody): bool
{
    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    }

    error_log('[mail] Brevo HTTP ' . $httpCode . ': ' . substr($responseBody, 0, 500));
    $hint = 'Check BREVO_API_KEY, and verify MAIL_FROM as a sender/domain in Brevo.';
    if ($httpCode === 401) {
        mail_send_set_last_error('Brevo rejected the API key (HTTP 401). ' . $hint);
    } elseif ($httpCode === 400) {
        mail_send_set_last_error('Brevo rejected the request (HTTP 400). ' . $hint . ' Response: ' . substr($responseBody, 0, 200));
    } else {
        mail_send_set_last_error('Brevo send failed (HTTP ' . $httpCode . '). ' . $hint);
    }

    return false;
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
