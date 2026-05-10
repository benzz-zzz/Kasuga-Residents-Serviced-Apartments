<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Forbidden']);
    exit;
}

$action = (string) ($_GET['action'] ?? '');
$adminUserId = (int) $user['id'];

if ($action === 'mark_seen') {
    if (!verify_csrf((string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    admin_notifications_mark_seen($adminUserId);
}

$payload = admin_notifications_payload($adminUserId, 8);

echo json_encode([
    'ok' => true,
    'unread_count' => (int) $payload['unread_count'],
    'items' => $payload['items'],
]);
