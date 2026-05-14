<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/login_otp.php';
require_once __DIR__ . '/includes/mail.php';

$errors = [];
if (is_post()) {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $errors[] = 'Invalid request token.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');
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
            $uid = (int) $user['id'];
            $issued = login_otp_create_challenge($uid);
            if ($issued === null) {
                $errors[] = 'Could not start sign-in verification. Please try again.';
            } elseif (!send_login_otp_email((string) $user['email'], $issued['plain'])) {
                $hint = mail_send_last_error();
                $errors[] = $hint !== ''
                    ? ('We could not send the verification code. ' . $hint)
                    : 'We could not send the verification code. Check mail settings and try again.';
            } else {
                session_regenerate_id(true);
                $_SESSION['login_otp_challenge_id'] = $issued['challenge_id'];
                $_SESSION['flash_success'] = 'We sent a 6-digit code to your email. Enter it to finish signing in.';
                redirect(app_url('login_otp.php'));
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

            <p class="form-note"><a href="<?= h(app_url('forgot_password.php')) ?>">Forgot password?</a></p>
            <button type="submit" class="btn btn--primary btn--block">Sign in</button>
        </form>
        <p class="form-footer">No account yet? <a href="<?= h(app_url('register.php')) ?>">Create one</a></p>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
