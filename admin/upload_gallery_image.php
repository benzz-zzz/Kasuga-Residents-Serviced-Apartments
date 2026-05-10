<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.'], JSON_THROW_ON_ERROR);
    exit;
}

if (!verify_csrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid security token.'], JSON_THROW_ON_ERROR);
    exit;
}

if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No file uploaded.'], JSON_THROW_ON_ERROR);
    exit;
}

$file = $_FILES['file'];

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Upload failed.'], JSON_THROW_ON_ERROR);
    exit;
}

$maxBytes = 5 * 1024 * 1024;

if (($file['size'] ?? 0) > $maxBytes) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'File too large (max 5 MB).'], JSON_THROW_ON_ERROR);
    exit;
}

$tmp = (string) ($file['tmp_name'] ?? '');

if ($tmp === '' || !is_uploaded_file($tmp)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid upload.'], JSON_THROW_ON_ERROR);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);

$mime = $finfo->file($tmp) ?: '';

$mimeToExt = [

    'image/jpeg' => 'jpg',

    'image/png' => 'png',

    'image/gif' => 'gif',

    'image/webp' => 'webp',

];

if (!isset($mimeToExt[$mime])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Only JPEG, PNG, GIF, or WebP images are allowed.'], JSON_THROW_ON_ERROR);
    exit;
}

if (@getimagesize($tmp) === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'File is not a valid image.'], JSON_THROW_ON_ERROR);
    exit;
}

$ext = $mimeToExt[$mime];

$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'rooms';

if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not create upload directory.'], JSON_THROW_ON_ERROR);
    exit;
}

$name = 'g_' . bin2hex(random_bytes(12)) . '.' . $ext;

$dest = $dir . DIRECTORY_SEPARATOR . $name;

if (!move_uploaded_file($tmp, $dest)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not save file.'], JSON_THROW_ON_ERROR);
    exit;
}

$url = app_public_base_url() . '/uploads/rooms/' . $name;

echo json_encode(['ok' => true, 'url' => $url], JSON_THROW_ON_ERROR);
