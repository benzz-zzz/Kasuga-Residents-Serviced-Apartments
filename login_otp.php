<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/login_otp.php';

if (current_user()) {
    redirect(app_url('index.php'));
}

$challengeId = isset($_SESSION['login_otp_challenge_id']) ? (int) $_SESSION['login_otp_challenge_id'] : 0;
$ctx = login_otp_challenge_context($challengeId > 0 ? $challengeId : null);

if (!is_post()) {
    if ($challengeId <= 0) {
        $_SESSION['flash_error'] = 'Please sign in first.';
        redirect(app_url('login.php'));
    }
    if ($ctx === null) {
        unset($_SESSION['login_otp_challenge_id']);
        $_SESSION['flash_error'] = 'That sign-in step expired. Please sign in again.';
        redirect(app_url('login.php'));
    }
}

$errors = [];
if (is_post()) {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $errors[] = 'Invalid request token.';
    } elseif ($challengeId <= 0) {
        unset($_SESSION['login_otp_challenge_id']);
        $_SESSION['flash_error'] = 'Sign-in session expired. Please sign in again.';
        redirect(app_url('login.php'));
    } else {
        $code = (string) ($_POST['otp'] ?? '');
        $userId = login_otp_verify_and_consume($challengeId, $code);
        if ($userId > 0) {
            unset($_SESSION['login_otp_challenge_id']);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $userId;
            $_SESSION['flash_success'] = 'Login successful.';
            redirect(app_url('index.php'));
        }
        $errors[] = 'Invalid or expired code. Please try again or request a new sign-in from the login page.';
        $ctx = login_otp_challenge_context($challengeId);
        if ($ctx === null) {
            unset($_SESSION['login_otp_challenge_id']);
            $_SESSION['flash_error'] = 'Too many wrong attempts or the code expired. Please sign in again.';
            redirect(app_url('login.php'));
        }
    }
}

if ($ctx === null) {
    unset($_SESSION['login_otp_challenge_id']);
    $_SESSION['flash_error'] = 'That sign-in step expired. Please sign in again.';
    redirect(app_url('login.php'));
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-solo">
    <div class="panel auth-panel auth-panel--solo panel--highlight">
        <h1 class="auth-solo__title">Check your email</h1>
        <p class="form-note" style="margin-top:0;margin-bottom:1rem">
            Enter the 6-digit code we sent to <strong><?= h($ctx['email_masked'] ?? '') ?></strong>.
            Codes expire in <?= (int) LOGIN_OTP_TTL_MINUTES ?> minutes.
        </p>
        <?php foreach ($errors as $error): ?><div class="alert error"><?= h($error) ?></div><?php endforeach; ?>
        <form class="form" method="post" autocomplete="one-time-code">
            <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
            <label for="otp">Verification code</label>
            <input id="otp" name="otp" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="12" required autocomplete="one-time-code" autocapitalize="off" spellcheck="false" placeholder="000000" aria-describedby="otp-hint">
            <p id="otp-hint" class="form-note">Use only digits. <?= (int) LOGIN_OTP_MAX_ATTEMPTS ?> tries allowed before you must start over.</p>
            <button type="submit" class="btn btn--primary btn--block">Verify and continue</button>
        </form>
        <p class="form-footer"><a href="<?= h(app_url('login.php')) ?>">Back to sign in</a></p>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
