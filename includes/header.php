<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
$user = current_user();
$userInitials = '';
if ($user) {
    $parts = preg_split('/\s+/', trim((string)$user['full_name'])) ?: [];
    $first = $parts[0] ?? '';
    $last = $parts[count($parts) - 1] ?? '';
    $userInitials = strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
    if ($userInitials === '') {
        $userInitials = 'U';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0c121c">
    <title><?= h(APP_NAME) ?> — Serviced apartments &amp; extended stays</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,500&family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;1,500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= h(asset_url('style.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset_url('future.css')) ?>">
    <?php
    if (isset($page_head_extras)) {
        if (is_callable($page_head_extras)) {
            echo (string) $page_head_extras();
        } elseif (is_string($page_head_extras) && $page_head_extras !== '') {
            echo $page_head_extras;
        }
    }
    ?>
</head>
<body class="site--future">
<header class="site-header" role="banner">
    <div class="container site-header__inner">
        <a class="brand" href="<?= h(app_url('index.php')) ?>">
            <span class="brand__mark" aria-hidden="true">K</span>
            <span class="brand__text">
                <span class="brand__name"><?= h(APP_NAME) ?></span>
                <span class="brand__tagline">Serviced apartments</span>
            </span>
        </a>
        <input type="checkbox" id="nav-open" class="nav-open" hidden aria-hidden="true" tabindex="-1">
        <label for="nav-open" class="nav-burger" aria-label="Open menu" id="nav-burger">
            <span class="nav-burger__line" aria-hidden="true"></span>
            <span class="nav-burger__line" aria-hidden="true"></span>
            <span class="nav-burger__line" aria-hidden="true"></span>
        </label>
        <div class="nav-wrap" id="nav-menu">
            <nav class="nav" aria-label="Primary">
                <a class="nav__link" href="<?= h(app_url('index.php')) ?>">Home</a>
                <a class="nav__link" href="<?= h(app_url('about.php')) ?>">About</a>
                <a class="nav__link" href="<?= h(app_url('rooms.php')) ?>">Rooms</a>
                <a class="nav__link" href="<?= h(app_url('services.php')) ?>">Services</a>
                <a class="nav__link" href="<?= h(app_url('contact.php')) ?>">Contact</a>
                <a class="nav__link nav__link--emphasis" href="<?= h(app_url('my_bookings.php')) ?>">Reservations</a>
                <?php if ($user): ?>
                    <span class="nav__account-group">
                        <a class="nav__profile" href="<?= h(app_url('profile.php')) ?>" title="<?= h($user['full_name']) ?> (<?= h($user['email']) ?>)" aria-label="Open profile">
                            <span class="nav__profile-initials" aria-hidden="true"><?= h($userInitials) ?></span>
                        </a>
                        <?php if ($user['role'] === 'admin'): ?>
                            <a class="nav__link" href="<?= h(defined('APP_ADMIN') ? APP_ADMIN . '/index.php' : app_url('admin/index.php')) ?>">Admin</a>
                        <?php endif; ?>
                        <a class="nav__link nav__link--logout" href="<?= h(app_url('logout.php')) ?>">Logout</a>
                    </span>
                <?php else: ?>
                    <a class="nav__link" href="<?= h(app_url('register.php')) ?>">Register</a>
                    <a class="btn btn--primary" href="<?= h(app_url('login.php')) ?>">Sign in</a>
                <?php endif; ?>
            </nav>
        </div>
    </div>
    <div class="nav-scrim" id="nav-scrim" aria-hidden="true"></div>
</header>
<main class="container app-main" id="content">
<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert error alert--animate" role="status"><?= h($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error'], $_SESSION['flash_success']); ?>
<?php elseif (!empty($_SESSION['flash_success'])): ?>
    <div class="alert success alert--animate" role="status"><?= h($_SESSION['flash_success']) ?></div>
    <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>
