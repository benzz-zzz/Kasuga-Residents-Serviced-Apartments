<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $user = null;
    if (is_array($user) && (int)$user['id'] === (int)$_SESSION['user_id']) {
        return $user;
    }
    $stmt = db()->prepare('SELECT id, full_name, email, phone, role FROM users WHERE id = ?');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $result = $stmt->fetch();
    $user = $result ?: null;
    return $user;
}

function require_login(): void
{
    if (!current_user()) {
        $_SESSION['flash_error'] = 'Please login first.';
        redirect(app_url('login.php'));
    }
}

function require_admin(): void
{
    $user = current_user();
    if (!$user || $user['role'] !== 'admin') {
        $_SESSION['flash_error'] = 'Admin access only.';
        redirect(app_url('index.php'));
    }
}

