<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/validation.php';
require_login();

$me = current_user();
if (!$me) {
    $_SESSION['flash_error'] = 'Please login first.';
    redirect('login.php');
}

$errors = [];
if (is_post()) {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $errors[] = 'Invalid request token.';
    } else {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 80) {
            $errors[] = 'Full name must be 3 to 80 characters.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (!preg_match('/^09\d{9}$/', $phone)) {
            $errors[] = 'Phone must start with 09 and contain 11 digits.';
        }
        if ($newPassword !== '') {
            if ($currentPassword === '') {
                $errors[] = 'Please enter your current password to set a new password.';
            } else {
                $st = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
                $st->execute([(int) $me['id']]);
                $currentHash = (string) ($st->fetchColumn() ?: '');
                if ($currentHash === '' || !password_verify($currentPassword, $currentHash)) {
                    $errors[] = 'Current password is incorrect.';
                }
            }

            $pwdError = validate_password_strength($newPassword);
            if ($pwdError !== null) {
                $errors[] = str_replace('Password must', 'New password must', $pwdError);
            }
            if ($newPassword !== $confirmPassword) {
                $errors[] = 'Password confirmation does not match.';
            }
        }

        if ($errors === []) {
            $exists = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
            $exists->execute([$email, (int)$me['id']]);
            if ($exists->fetch()) {
                $errors[] = 'Email is already registered to another account.';
            }
        }

        if ($errors === []) {
            if ($newPassword !== '') {
                $update = db()->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, password_hash = ? WHERE id = ?');
                $update->execute([$fullName, $email, $phone, password_hash($newPassword, PASSWORD_DEFAULT), (int)$me['id']]);
            } else {
                $update = db()->prepare('UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?');
                $update->execute([$fullName, $email, $phone, (int)$me['id']]);
            }
            $_SESSION['flash_success'] = 'Profile updated successfully.';
            redirect('profile.php');
        }
    }
}

$fresh = db()->prepare('SELECT id, full_name, email, phone, role FROM users WHERE id = ?');
$fresh->execute([(int)$me['id']]);
$user = $fresh->fetch() ?: $me;

require_once __DIR__ . '/includes/header.php';
?>
<div class="panel panel--narrow">
    <header class="page-title">
        <p class="page-title__kicker">Account</p>
        <h1>Profile settings</h1>
        <p>Update your personal details and password.</p>
    </header>
    <?php foreach ($errors as $error): ?><div class="alert error"><?= h($error) ?></div><?php endforeach; ?>
    <form class="form form--wide" method="post" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
        <label for="full_name">Full name</label>
        <input id="full_name" name="full_name" maxlength="80" required value="<?= h((string)$user['full_name']) ?>">
        <div class="row">
            <div>
                <label for="email">Email</label>
                <input id="email" type="email" name="email" required value="<?= h((string)$user['email']) ?>">
            </div>
            <div>
                <label for="phone">Phone</label>
                <input id="phone" name="phone" maxlength="11" inputmode="numeric" required value="<?= h((string)$user['phone']) ?>">
            </div>
        </div>
        <label for="current_password">Current password</label>
        <div class="password-field">
            <input id="current_password" type="password" name="current_password" autocomplete="current-password" data-password-toggle-target>
            <button type="button" class="password-toggle" data-password-toggle aria-label="Show password" aria-pressed="false">
                <span class="visually-hidden">Show password</span>
            </button>
        </div>
        <label for="new_password">New password (optional)</label>
        <div class="password-field">
            <input id="new_password" type="password" name="new_password" autocomplete="new-password" data-password-toggle-target>
            <button type="button" class="password-toggle" data-password-toggle aria-label="Show password" aria-pressed="false">
                <span class="visually-hidden">Show password</span>
            </button>
        </div>
        <label for="confirm_password">Confirm new password</label>
        <div class="password-field">
            <input id="confirm_password" type="password" name="confirm_password" autocomplete="new-password" data-password-toggle-target>
            <button type="button" class="password-toggle" data-password-toggle aria-label="Show password" aria-pressed="false">
                <span class="visually-hidden">Show password</span>
            </button>
        </div>
        <p class="form-note">Role: <?= h((string)$user['role']) ?></p>
        <button type="submit" class="btn btn--primary btn--block">Save profile</button>
    </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
