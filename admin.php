<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
header('Location: ' . (defined('APP_ADMIN') ? APP_ADMIN : '/Apartment%20system/admin') . '/index.php', true, 302);
exit;
