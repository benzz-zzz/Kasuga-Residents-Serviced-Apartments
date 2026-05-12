<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/password_reset.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/captcha.php';

$errors = [];
$captchaErrors = [];
if (is_post()) {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $errors[] = 'Invalid request token.';
    } else {
        $captchaError = captcha_validate($_POST, $_SERVER['REMOTE_ADDR'] ?? null);
        if ($captchaError !== null) {
            $captchaErrors[] = $captchaError;
        }
        $email = trim((string)($_POST['email'] ?? ''));
        if ($captchaErrors !== []) {
            // CAPTCHA error shown inline below the widget.
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } else {
            $stmt = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user) {
                $raw = password_reset_issue((int) $user['id']);
                $resetUrl = app_public_base_url() . '/reset_password.php?token=' . rawurlencode($raw);
                if (!send_password_reset_email($email, $resetUrl)) {
                    $hint = mail_send_last_error();
                    $errors[] = $hint !== ''
                        ? ('We could not send the email. ' . $hint)
                        : 'We could not send the email. Check PHP mail / SMTP settings, or try again later.';
                } else {
                    $_SESSION['flash_success'] = 'Check your inbox for a reset link. It expires in one hour.';
                    redirect('login.php');
                }
            } else {
                $_SESSION['flash_success'] = 'If an account exists for that email, we sent reset instructions.';
                redirect('login.php');
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-solo">
    <div class="panel auth-panel auth-panel--solo panel--highlight">
        <h1 class="auth-solo__title">Forgot password</h1>
        <p class="form-note" style="margin-top:0;margin-bottom:1rem">Enter the email on your account. We will send a one-time link to set a new password.</p>
        <?php foreach ($errors as $error): ?><div class="alert error"><?= h($error) ?></div><?php endforeach; ?>
        <form class="form" method="post" autocomplete="on">
            <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" required autocomplete="email" value="<?= h(trim((string)($_POST['email'] ?? ''))) ?>">
            <?php if (captcha_is_enabled()): ?>
                <?php if (captcha_provider() === 'recaptcha'): ?>
                    <div class="g-recaptcha mt-2" data-sitekey="<?= h(RECAPTCHA_SITE_KEY) ?>"></div>
                <?php else: ?>
                    <div class="cf-turnstile mt-2" data-sitekey="<?= h(TURNSTILE_SITE_KEY) ?>"></div>
                <?php endif; ?>
                <?php foreach ($captchaErrors as $error): ?><div class="field-error"><?= h($error) ?></div><?php endforeach; ?>
            <?php endif; ?>
            <button type="submit" class="btn btn--primary btn--block">Send reset link</button>
        </form>
        <p class="form-footer"><a href="<?= h(app_url('login.php')) ?>">Back to sign in</a></p>
    </div>
</div>
<?php if (captcha_is_enabled()): ?>
    <?php if (captcha_provider() === 'recaptcha'): ?>
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php else: ?>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
