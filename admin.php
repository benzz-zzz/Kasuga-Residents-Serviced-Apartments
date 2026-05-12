<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
header('Location: ' . admin_url('index.php'), true, 302);
exit;
