<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/includes/password_reset.php';

$tokenIn = trim((string)($_GET['token'] ?? ''));
if (is_post()) {
    $tokenIn = trim((string)($_POST['reset_token'] ?? ''));
}

$lookup = $tokenIn !== '' ? password_reset_lookup($tokenIn) : null;
$errors = [];

if (is_post()) {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $errors[] = 'Invalid request token.';
    } elseif ($tokenIn === '') {
        $errors[] = 'Reset link is missing.';
    } elseif ($lookup === null) {
        $errors[] = 'This reset link is invalid or has expired. Request a new one from Forgot password.';
    } else {
        $pass = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['password_confirm'] ?? '');
        if ($pass !== $pass2) {
            $errors[] = 'Passwords do not match.';
        } else {
            $pwdErr = validate_password_strength($pass);
            if ($pwdErr !== null) {
                $errors[] = $pwdErr;
            } else {
                password_reset_consume_all_for_user($lookup['user_id']);
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([
                    password_hash($pass, PASSWORD_DEFAULT),
                    $lookup['user_id'],
                ]);
                $_SESSION['flash_success'] = 'Your password was updated. You can sign in now.';
                redirect('/Apartment%20system/login.php');
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-solo">
    <div class="panel auth-panel auth-panel--solo panel--highlight">
        <h1 class="auth-solo__title">Set new password</h1>
        <?php if ($tokenIn === '' && !is_post()): ?>
            <p class="form-note" style="margin-top:0">Open the link from your email, or <a href="/Apartment%20system/forgot_password.php">request a new reset</a>.</p>
        <?php elseif ($lookup === null): ?>
            <?php foreach ($errors as $error): ?><div class="alert error"><?= h($error) ?></div><?php endforeach; ?>
            <?php if ($errors === []): ?>
                <div class="alert error">This reset link is invalid or has expired.</div>
            <?php endif; ?>
            <p class="form-footer"><a href="/Apartment%20system/forgot_password.php">Request a new link</a> · <a href="/Apartment%20system/login.php">Sign in</a></p>
        <?php else: ?>
            <?php foreach ($errors as $error): ?><div class="alert error"><?= h($error) ?></div><?php endforeach; ?>
            <p class="form-note" style="margin-top:0;margin-bottom:1rem">Choose a strong password. This link stops working after you save or after one hour.</p>
            <form class="form" method="post" autocomplete="on">
                <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
                <input type="hidden" name="reset_token" value="<?= h($tokenIn) ?>">
                <label for="password">New password</label>
                <div class="password-field">
                    <input id="password" type="password" name="password" required autocomplete="new-password" minlength="8" maxlength="64" data-password-toggle-target>
                    <button type="button" class="password-toggle" data-password-toggle aria-label="Show password" aria-pressed="false">
                        <span class="visually-hidden">Show password</span>
                    </button>
                </div>
                <label for="password_confirm">Confirm password</label>
                <div class="password-field">
                    <input id="password_confirm" type="password" name="password_confirm" required autocomplete="new-password" minlength="8" maxlength="64" data-password-toggle-target>
                    <button type="button" class="password-toggle" data-password-toggle aria-label="Show password" aria-pressed="false">
                        <span class="visually-hidden">Show password</span>
                    </button>
                </div>
                <button type="submit" class="btn btn--primary btn--block">Update password</button>
            </form>
            <p class="form-footer"><a href="/Apartment%20system/login.php">Back to sign in</a></p>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
