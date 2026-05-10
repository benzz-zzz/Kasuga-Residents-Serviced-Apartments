<?php
declare(strict_types=1);
/** @var string $adminPageTitle */
/** @var string $adminNav */
if (!function_exists('admin_url')) {
    require_once __DIR__ . '/bootstrap.php';
}
$cu = current_user() ?? ['full_name' => '', 'email' => ''];
$active = $adminNav ?? 'dashboard';
$title = $adminPageTitle ?? 'Admin';
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0c121c">
    <title><?= h($title) ?> — Admin · <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= h(admin_url('assets/admin.css')) ?>">
</head>
<body class="admin-body admin--future" id="admin-body">
<div class="admin-scrim" id="admin-scrim" aria-hidden="true"></div>
<div class="admin-layout">
    <aside class="admin-sidebar" id="admin-sidebar" role="navigation" aria-label="Admin">
        <a class="admin-sidebar__brand" href="<?= h(admin_url('index.php')) ?>">
            <small>Property management</small>
            <strong><?= h(APP_NAME) ?></strong>
        </a>
        <ul class="admin-nav">
            <li><a href="<?= h(admin_url('index.php')) ?>" class="<?= $active === 'dashboard' ? 'is-active' : '' ?>">Dashboard</a></li>
            <li><a href="<?= h(admin_url('bookings.php')) ?>" class="<?= $active === 'bookings' ? 'is-active' : '' ?>">Bookings</a></li>
            <li><a href="<?= h(admin_url('rooms.php')) ?>" class="<?= $active === 'rooms' ? 'is-active' : '' ?>">Rooms</a></li>
            <li><a href="<?= h(admin_url('users.php')) ?>" class="<?= $active === 'users' ? 'is-active' : '' ?>">Tenants &amp; users</a></li>
            <li><a href="<?= h(admin_url('reviews.php')) ?>" class="<?= $active === 'reviews' ? 'is-active' : '' ?>">Reviews</a></li>
            <li><a href="<?= h(admin_url('announcements.php')) ?>" class="<?= $active === 'announcements' ? 'is-active' : '' ?>">Announcements</a></li>
            <li><a href="<?= h(admin_url('archive.php')) ?>" class="<?= $active === 'archive' ? 'is-active' : '' ?>">Archive</a></li>
        </ul>
        <div class="admin-sidebar__foot">
            <p class="admin-user"><?= h($cu['full_name'] ?? '') ?><br><span class="admin-muted admin-muted--sm"><?= h($cu['email'] ?? '') ?></span></p>
            <a href="<?= h(APP_BASE) ?>/index.php">View public site</a>
            <a href="<?= h(APP_BASE) ?>/logout.php">Sign out</a>
        </div>
    </aside>
    <div class="admin-wrap">
        <header class="admin-bar">
            <button type="button" class="admin-menu-toggle" id="admin-menu-btn" aria-label="Open menu" aria-controls="admin-sidebar" aria-expanded="false">☰</button>
            <div>
                <p class="admin-breadcrumb">Admin</p>
                <h1><?= h($title) ?></h1>
            </div>
            <span class="admin-bar__spacer"></span>
            <div class="admin-notify" id="admin-notify" data-csrf="<?= h(generate_csrf()) ?>">
                <button type="button" class="admin-notify__btn" id="admin-notify-btn" aria-label="Notifications" aria-expanded="false">
                    <span aria-hidden="true">🔔</span>
                    <span class="admin-notify__badge" id="admin-notify-badge" hidden>0</span>
                </button>
                <div class="admin-notify__panel" id="admin-notify-panel" hidden>
                    <div class="admin-notify__head">
                        <strong>Notifications</strong>
                    </div>
                    <ul class="admin-notify__list" id="admin-notify-list">
                        <li class="admin-notify__empty">No notifications yet.</li>
                    </ul>
                </div>
            </div>
        </header>
        <div class="admin-main">
            <?php if (!empty($_SESSION['flash_error'])): ?>
                <div class="admin-alert admin-alert--error" role="status"><?= h($_SESSION['flash_error']) ?></div>
                <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>
            <?php if (!empty($_SESSION['flash_success'])): ?>
                <div class="admin-alert admin-alert--ok" role="status"><?= h($_SESSION['flash_success']) ?></div>
                <?php unset($_SESSION['flash_success']); ?>
            <?php endif; ?>
