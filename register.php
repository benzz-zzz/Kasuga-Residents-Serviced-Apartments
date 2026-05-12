<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/includes/captcha.php';

$errors = [];
$fieldErrors = [
    'full_name' => [],
    'email' => [],
    'phone' => [],
    'password' => [],
    'captcha' => [],
];
$form = [
    'full_name' => '',
    'email' => '',
    'phone' => '',
];
if (is_post()) {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $errors[] = 'Invalid request token.';
    } else {
        $form = sanitize_registration_input($_POST);
        $fieldErrors = array_merge([
            'full_name' => [],
            'email' => [],
            'phone' => [],
            'password' => [],
            'captcha' => [],
        ], validate_registration_fields($form));
        $captchaError = captcha_validate($_POST, $_SERVER['REMOTE_ADDR'] ?? null);
        if ($captchaError !== null) {
            $fieldErrors['captcha'][] = $captchaError;
        }
        $hasFieldErrors = false;
        foreach ($fieldErrors as $messages) {
            if ($messages !== []) {
                $hasFieldErrors = true;
                break;
            }
        }
        if (!$hasFieldErrors) {
            $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$form['email']]);
            if ($stmt->fetch()) {
                $errors[] = 'Email is already registered.';
                $fieldErrors['email'][] = 'Email is already registered.';
            } else {
                $insert = db()->prepare('
                    INSERT INTO users (full_name, email, phone, password_hash, role, created_at)
                    VALUES (?, ?, ?, ?, ?, ?)
                ');
                $insert->execute([
                    $form['full_name'],
                    $form['email'],
                    $form['phone'],
                    password_hash($form['password'], PASSWORD_DEFAULT),
                    'tenant',
                    db_timestamp(),
                ]);
                $_SESSION['flash_success'] = 'Registration complete. Please login.';
                redirect('login.php');
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-solo auth-solo--wide">
    <div class="panel auth-panel auth-panel--solo panel--highlight">
        <h1 class="auth-solo__title">Create account</h1>
       
        <form class="form form--wide" method="post" autocomplete="on">
            <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
            <label for="full_name">Full name</label>
            <input
                id="full_name"
                name="full_name"
                maxlength="80"
                required
                value="<?= h($form['full_name']) ?>"
                class="<?= $fieldErrors['full_name'] !== [] ? 'field-input--error' : '' ?>"
                aria-invalid="<?= $fieldErrors['full_name'] !== [] ? 'true' : 'false' ?>"
            >
            <?php foreach ($fieldErrors['full_name'] as $error): ?><div class="field-error"><?= h($error) ?></div><?php endforeach; ?>
            <div class="row">
                <div>
                    <label for="email">Email</label>
                    <input
                        id="email"
                        type="email"
                        name="email"
                        required
                        value="<?= h($form['email']) ?>"
                        class="<?= $fieldErrors['email'] !== [] ? 'field-input--error' : '' ?>"
                        aria-invalid="<?= $fieldErrors['email'] !== [] ? 'true' : 'false' ?>"
                    >
                    <?php foreach ($fieldErrors['email'] as $error): ?><div class="field-error"><?= h($error) ?></div><?php endforeach; ?>
                </div>
                <div>
                    <label for="phone">Phone (09XXXXXXXXX)</label>
                    <input
                        id="phone"
                        name="phone"
                        maxlength="11"
                        inputmode="numeric"
                        required
                        value="<?= h($form['phone']) ?>"
                        class="<?= $fieldErrors['phone'] !== [] ? 'field-input--error' : '' ?>"
                        aria-invalid="<?= $fieldErrors['phone'] !== [] ? 'true' : 'false' ?>"
                    >
                    <?php foreach ($fieldErrors['phone'] as $error): ?><div class="field-error"><?= h($error) ?></div><?php endforeach; ?>
                </div>
            </div>
            <label for="password">Password</label>
            <div class="password-field">
                <input
                    id="password"
                    type="password"
                    name="password"
                    required
                    autocomplete="new-password"
                    class="<?= $fieldErrors['password'] !== [] ? 'field-input--error' : '' ?>"
                    aria-invalid="<?= $fieldErrors['password'] !== [] ? 'true' : 'false' ?>"
                    data-password-toggle-target
                >
                <button type="button" class="password-toggle" data-password-toggle aria-label="Show password" aria-pressed="false">
                    <span class="visually-hidden">Show password</span>
                </button>
            </div>
            <?php foreach ($fieldErrors['password'] as $error): ?><div class="field-error"><?= h($error) ?></div><?php endforeach; ?>
            <?php if (captcha_is_enabled()): ?>
                <?php if (captcha_provider() === 'recaptcha'): ?>
                    <div class="g-recaptcha mt-2" data-sitekey="<?= h(RECAPTCHA_SITE_KEY) ?>"></div>
                <?php else: ?>
                    <div class="cf-turnstile mt-2" data-sitekey="<?= h(TURNSTILE_SITE_KEY) ?>"></div>
                <?php endif; ?>
                <?php foreach ($fieldErrors['captcha'] as $error): ?><div class="field-error"><?= h($error) ?></div><?php endforeach; ?>
            <?php endif; ?>
            <button type="submit" class="btn btn--primary btn--block">Create account</button>
        </form>
        <p class="form-footer">Already have an account? <a href="<?= h(app_url('login.php')) ?>">Sign in</a></p>
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
