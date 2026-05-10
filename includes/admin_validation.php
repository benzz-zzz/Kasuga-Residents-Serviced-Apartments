<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/**
 * @return list<string>
 */
function normalize_admin_room_image_urls(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $item) {
        if (!is_string($item)) {
            continue;
        }
        $t = trim($item);
        if ($t === '') {
            continue;
        }
        $out[] = $t;
        if (count($out) >= ROOM_GALLERY_MAX_IMAGES) {
            break;
        }
    }

    return $out;
}

/**
 * @return list<string>
 */
function validate_admin_room(array $in, ?int $excludeRoomId = null): array
{
    $errors = [];
    $code = strtoupper(trim($in['room_code'] ?? ''));
    if (!preg_match('/^[\w.\-]{2,32}$/', $code)) {
        $errors[] = 'Room code must be 2–32 characters (letters, numbers, dot, dash).';
    }
    $title = trim($in['title'] ?? '');
    if (mb_strlen($title) < 2 || mb_strlen($title) > 120) {
        $errors[] = 'Title must be 2–120 characters.';
    }
    $desc = trim($in['description'] ?? '');
    if (mb_strlen($desc) < 5 || mb_strlen($desc) > 2000) {
        $errors[] = 'Description must be 5–2000 characters.';
    }
    $rate = (float)($in['monthly_rate'] ?? 0);
    if ($rate < 1 || $rate > 9999999) {
        $errors[] = 'Monthly rate must be a positive number.';
    }
    $cap = (int)($in['capacity'] ?? 0);
    if ($cap < 1) {
        $errors[] = 'Capacity must be at least 1.';
    }
    $urls = normalize_admin_room_image_urls($in['image_urls'] ?? []);
    if (count($urls) < 1) {
        $errors[] = 'Add at least one photo URL (up to ' . ROOM_GALLERY_MAX_IMAGES . ').';
    }
    foreach ($urls as $i => $url) {
        if (strlen($url) > 2000) {
            $errors[] = 'Photo #' . ($i + 1) . ' URL is too long.';
        } elseif (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            $errors[] = 'Photo #' . ($i + 1) . ' must be a valid http(s) URL.';
        }
    }
    $occ = (string)($in['occupancy_status'] ?? 'vacant');
    if (!in_array($occ, room_occupancy_statuses(), true)) {
        $errors[] = 'Invalid room availability.';
    }
    if (empty($errors) && $code !== '') {
        $q = 'SELECT id FROM rooms WHERE UPPER(room_code) = ?';
        $p = [$code];
        if ($excludeRoomId) {
            $q .= ' AND id != ?';
            $p[] = $excludeRoomId;
        }
        $stmt = db()->prepare($q);
        $stmt->execute($p);
        if ($stmt->fetch()) {
            $errors[] = 'This room code is already in use.';
        }
    }
    return $errors;
}
