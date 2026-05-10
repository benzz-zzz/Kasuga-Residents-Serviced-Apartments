<?php

declare(strict_types=1);



require_once dirname(__DIR__) . '/config.php';



/** 'recaptcha', 'turnstile', or 'none'. Google takes priority when both keys are set. */

function captcha_provider(): string

{

    if (RECAPTCHA_SITE_KEY !== '' && RECAPTCHA_SECRET_KEY !== '') {

        return 'recaptcha';

    }

    if (TURNSTILE_SITE_KEY !== '' && TURNSTILE_SECRET_KEY !== '') {

        return 'turnstile';

    }



    return 'none';

}



function captcha_is_enabled(): bool

{

    if (captcha_should_bypass_for_local()) {

        return false;

    }



    return captcha_provider() !== 'none';

}



/**

 * Validate CAPTCHA from a submitted form (handles reCAPTCHA or Turnstile by configuration).

 *

 * @param array|null $post Typically $_POST

 * @return null when valid or CAPTCHA disabled, otherwise an error message

 */

function captcha_validate(?array $post, ?string $remoteIp = null): ?string

{

    if (captcha_should_bypass_for_local()) {

        return null;

    }



    if (!captcha_is_enabled()) {

        return null;

    }



    $post = $post ?? [];



    if (captcha_provider() === 'recaptcha') {

        return captcha_verify_recaptcha_post($post, $remoteIp);

    }



    return captcha_verify_turnstile_post($post, $remoteIp);

}



function captcha_http_post_form(string $url, string $payload): string

{

    if (function_exists('curl_init')) {

        $ch = curl_init($url);

        if ($ch === false) {

            return '';

        }

        curl_setopt_array($ch, [

            CURLOPT_POST => true,

            CURLOPT_POSTFIELDS => $payload,

            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_TIMEOUT => 10,

            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],

        ]);

        $resp = curl_exec($ch);

        curl_close($ch);



        return is_string($resp) ? $resp : '';

    }



    $ctx = stream_context_create([

        'http' => [

            'method' => 'POST',

            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",

            'content' => $payload,

            'timeout' => 10,

        ],

    ]);

    $resp = @file_get_contents($url, false, $ctx);



    return is_string($resp) ? $resp : '';

}



function captcha_verify_recaptcha_post(array $post, ?string $remoteIp): ?string

{

    $token = trim((string) ($post['g-recaptcha-response'] ?? ''));

    if ($token === '') {

        return 'Please complete the CAPTCHA challenge.';

    }



    $payload = http_build_query([

        'secret' => RECAPTCHA_SECRET_KEY,

        'response' => $token,

        'remoteip' => (string) ($remoteIp ?? ''),

    ]);



    $resultBody = captcha_http_post_form('https://www.google.com/recaptcha/api/siteverify', $payload);

    if ($resultBody === '') {

        return 'CAPTCHA verification failed. Please try again.';

    }



    $json = json_decode($resultBody, true);

    if (!is_array($json) || empty($json['success'])) {

        return 'CAPTCHA verification failed. Please try again.';

    }



    return null;

}



function captcha_verify_turnstile_post(array $post, ?string $remoteIp): ?string

{

    $token = trim((string) ($post['cf-turnstile-response'] ?? ''));

    if ($token === '') {

        return 'Please complete the CAPTCHA challenge.';

    }



    $payload = http_build_query([

        'secret' => TURNSTILE_SECRET_KEY,

        'response' => $token,

        'remoteip' => (string) ($remoteIp ?? ''),

    ]);



    $resultBody = captcha_http_post_form('https://challenges.cloudflare.com/turnstile/v0/siteverify', $payload);

    if ($resultBody === '') {

        return 'CAPTCHA verification failed. Please try again.';

    }



    $json = json_decode($resultBody, true);

    if (!is_array($json) || empty($json['success'])) {

        return 'CAPTCHA verification failed. Please try again.';

    }



    return null;

}



/** @deprecated Use captcha_validate($_POST, ...) */

function captcha_validate_turnstile(?string $token, ?string $remoteIp = null): ?string

{

    if (captcha_should_bypass_for_local() || captcha_provider() !== 'turnstile') {

        return null;

    }



    return captcha_verify_turnstile_post(['cf-turnstile-response' => (string) $token], $remoteIp);

}



function captcha_should_bypass_for_local(): bool

{

    if (!CAPTCHA_BYPASS_LOCAL) {

        return false;

    }



    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

    $host = preg_replace('/:\d+$/', '', $host) ?? $host;



    return $host === 'localhost'

        || $host === '127.0.0.1'

        || $host === '::1'

        || str_ends_with($host, '.local')

        || str_ends_with($host, '.test');

}

