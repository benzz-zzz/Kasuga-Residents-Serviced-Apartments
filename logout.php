<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
session_unset();
session_destroy();
session_start();
$_SESSION['flash_success'] = 'You have been logged out securely.';
redirect(app_url('index.php'));
