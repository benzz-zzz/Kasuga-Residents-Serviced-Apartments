<?php
declare(strict_types=1);

/**
 * Minimal SMTP client (AUTH LOGIN, plain body). No external dependencies.
 * Used when MAIL_SMTP_HOST is set in config.
 */
function smtp_send_plain(string $toEmail, string $subject, string $plainBody, string $fromEmail, string $fromName): bool
{
    $host = (string) MAIL_SMTP_HOST;
    $port = (int) MAIL_SMTP_PORT;
    $user = (string) MAIL_SMTP_USER;
    $pass = (string) MAIL_SMTP_PASS;
    $enc = strtolower(trim((string) MAIL_SMTP_ENCRYPTION));

    if ($host === '' || $fromEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        smtp_log_error('Invalid SMTP/mail input values.');
        return false;
    }

    $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($remote, $errno, $errstr, 25, STREAM_CLIENT_CONNECT);
    if ($socket === false) {
        smtp_log_error('Socket connection failed: ' . $errstr . ' (' . $errno . ')');
        return false;
    }
    stream_set_timeout($socket, 30);

    $read = static function () use ($socket): string {
        $data = '';
        while (!feof($socket)) {
            $line = fgets($socket);
            if ($line === false) {
                break;
            }
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }

        return $data;
    };

    $expect = static function (string $buf, array $codes): bool {
        if ($buf === '') {
            return false;
        }
        $code = (int) substr($buf, 0, 3);

        return in_array($code, $codes, true);
    };

    $send = static function (string $line) use ($socket): void {
        fwrite($socket, $line . "\r\n");
    };

    $greeting = $read();
    if (!$expect($greeting, [220])) {
        smtp_log_error('SMTP greeting failed: ' . trim($greeting));
        fclose($socket);

        return false;
    }

    $ehloHost = 'localhost';
    $send('EHLO ' . $ehloHost);
    $ehloResp = $read();
    if (!$expect($ehloResp, [250])) {
        smtp_log_error('EHLO rejected: ' . trim($ehloResp));
        fclose($socket);

        return false;
    }

    if ($enc === 'tls' && $port !== 465) {
        $send('STARTTLS');
        $tlsResp = $read();
        if (!$expect($tlsResp, [220])) {
            smtp_log_error('STARTTLS rejected: ' . trim($tlsResp));
            fclose($socket);

            return false;
        }
        $cryptoMethod = smtp_tls_crypto_method();
        $cryptoOk = @stream_socket_enable_crypto($socket, true, $cryptoMethod);
        if ($cryptoOk !== true) {
            smtp_log_error('TLS negotiation failed (OpenSSL / crypto method mismatch).');
            fclose($socket);

            return false;
        }
        $send('EHLO ' . $ehloHost);
        $ehlo2 = $read();
        if (!$expect($ehlo2, [250])) {
            smtp_log_error('EHLO after STARTTLS rejected: ' . trim($ehlo2));
            fclose($socket);

            return false;
        }
    }

    if ($user !== '') {
        $send('AUTH LOGIN');
        $auth1 = $read();
        if (!$expect($auth1, [334])) {
            smtp_log_error('AUTH LOGIN not accepted: ' . trim($auth1));
            fclose($socket);

            return false;
        }
        $send(base64_encode($user));
        $auth2 = $read();
        if (!$expect($auth2, [334])) {
            smtp_log_error('SMTP username rejected: ' . trim($auth2));
            fclose($socket);

            return false;
        }
        $send(base64_encode($pass));
        $auth3 = $read();
        if (!$expect($auth3, [235])) {
            smtp_log_error('SMTP password/auth failed: ' . trim($auth3));
            fclose($socket);

            return false;
        }
    }

    $send('MAIL FROM:<' . $fromEmail . '>');
    $mf = $read();
    if (!$expect($mf, [250])) {
        smtp_log_error('MAIL FROM rejected: ' . trim($mf));
        fclose($socket);

        return false;
    }

    $send('RCPT TO:<' . $toEmail . '>');
    $rc = $read();
    if (!$expect($rc, [250, 251])) {
        smtp_log_error('RCPT TO rejected: ' . trim($rc));
        fclose($socket);

        return false;
    }

    $send('DATA');
    $dataIntro = $read();
    if (!$expect($dataIntro, [354])) {
        smtp_log_error('DATA command rejected: ' . trim($dataIntro));
        fclose($socket);

        return false;
    }

    $normBody = str_replace(["\r\n", "\r"], "\n", $plainBody);
    $normBody = str_replace("\n", "\r\n", $normBody);
    $normBody = preg_replace('/^\./m', '..', $normBody) ?? $normBody;

    $fromHeader = smtp_encode_mime_header($fromName) . ' <' . $fromEmail . '>';
    $subjectHeader = smtp_encode_mime_header($subject, true);

    $message =
        'From: ' . $fromHeader . "\r\n"
        . 'To: <' . $toEmail . ">\r\n"
        . 'Subject: ' . $subjectHeader . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n"
        . "\r\n"
        . $normBody . "\r\n";

    fwrite($socket, $message . ".\r\n");
    $dataEnd = $read();
    if (!$expect($dataEnd, [250])) {
        smtp_log_error('Message body rejected: ' . trim($dataEnd));
        fclose($socket);

        return false;
    }

    $send('QUIT');
    fclose($socket);

    return true;
}

function smtp_encode_mime_header(string $text, bool $isSubject = false): string
{
    if ($text === '') {
        return '';
    }
    if (preg_match('/[^\x20-\x7E]/', $text)) {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }
    if ($isSubject && (strpbrk($text, "\r\n") !== false)) {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }

    return $text;
}

function smtp_tls_crypto_method(): int
{
    $method = 0;
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
        $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
    }
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
        $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
    }
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT')) {
        $method |= STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
    }
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT')) {
        $method |= STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT;
    }

    return $method !== 0 ? $method : STREAM_CRYPTO_METHOD_ANY_CLIENT;
}

function smtp_log_error(string $message): void
{
    error_log('[smtp] ' . $message);
}
