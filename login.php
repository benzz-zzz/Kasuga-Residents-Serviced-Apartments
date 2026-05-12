<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
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
        $email = trim($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        if ($captchaErrors === []) {
            $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, (string)$user['password_hash'])) {
                $errors[] = 'Invalid email or password.';
            } else {
                if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
                    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([
                        password_hash($password, PASSWORD_DEFAULT),
                        (int) $user['id'],
                    ]);
                }
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['flash_success'] = 'Login successful.';
                redirect(app_url('index.php'));
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-solo">
    <div class="panel auth-panel auth-panel--solo panel--highlight">
        <h1 class="auth-solo__title">Sign in</h1>
        <?php foreach ($errors as $error): ?><div class="alert error"><?= h($error) ?></div><?php endforeach; ?>
        <form class="form" method="post" autocomplete="on">
            <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" required autocomplete="username">
            <label for="password">Password</label>
            <div class="password-field">
                <input id="password" type="password" name="password" required autocomplete="current-password" data-password-toggle-target>
                <button type="button" class="password-toggle" data-password-toggle aria-label="Show password" aria-pressed="false">
                    <span class="visually-hidden">Show password</span>
                </button>
            </div>
            
            <?php if (captcha_is_enabled()): ?>
                <?php if (captcha_provider() === 'recaptcha'): ?>
                    <div class="g-recaptcha mt-2" data-sitekey="<?= h(RECAPTCHA_SITE_KEY) ?>"></div>
                <?php else: ?>
                    <div class="cf-turnstile mt-2" data-sitekey="<?= h(TURNSTILE_SITE_KEY) ?>"></div>
                <?php endif; ?>
                <?php foreach ($captchaErrors as $error): ?><div class="field-error"><?= h($error) ?></div><?php endforeach; ?>
            <?php endif; ?>

            <p class="form-note"><a href="<?= h(app_url('forgot_password.php')) ?>">Forgot password?</a></p>
            <button type="submit" class="btn btn--primary btn--block">Sign in</button>
        </form>
        <p class="form-footer">No account yet? <a href="<?= h(app_url('register.php')) ?>">Create one</a></p>
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
