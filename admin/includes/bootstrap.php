<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_admin();

/**
 * @return array{unread_count:int, items:list<array<string,mixed>>}
 */
function admin_notifications_payload(int $adminUserId, int $limit = 8): array
{
    $limit = max(1, min(20, $limit));
    $lastSeen = admin_notifications_last_seen($adminUserId);

    $events = db()->prepare("
        SELECT e.event_type, e.event_at, e.booking_id, e.room_code, e.guest_name, e.status
        FROM (
            SELECT
                'new_booking' AS event_type,
                b.created_at AS event_at,
                b.id AS booking_id,
                r.room_code AS room_code,
                u.full_name AS guest_name,
                b.status AS status
            FROM bookings b
            INNER JOIN users u ON u.id = b.user_id
            INNER JOIN rooms r ON r.id = b.room_id

            UNION ALL

            SELECT
                'payment_submitted' AS event_type,
                b.payment_submitted_at AS event_at,
                b.id AS booking_id,
                r.room_code AS room_code,
                u.full_name AS guest_name,
                b.status AS status
            FROM bookings b
            INNER JOIN users u ON u.id = b.user_id
            INNER JOIN rooms r ON r.id = b.room_id
            WHERE b.payment_submitted_at IS NOT NULL
        ) e
        ORDER BY e.event_at DESC
        LIMIT {$limit}
    ");
    $events->execute();
    $rows = $events->fetchAll();

    $countStmt = db()->prepare("
        SELECT COUNT(*) FROM (
            SELECT b.created_at AS event_at
            FROM bookings b
            UNION ALL
            SELECT b.payment_submitted_at AS event_at
            FROM bookings b
            WHERE b.payment_submitted_at IS NOT NULL
        ) e
        WHERE e.event_at > ?
    ");
    $countStmt->execute([$lastSeen]);
    $unread = (int) $countStmt->fetchColumn();

    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'event_type' => (string) ($row['event_type'] ?? ''),
            'event_at' => (string) ($row['event_at'] ?? ''),
            'booking_id' => (int) ($row['booking_id'] ?? 0),
            'room_code' => (string) ($row['room_code'] ?? ''),
            'guest_name' => (string) ($row['guest_name'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'is_unread' => ((string) ($row['event_at'] ?? '')) > $lastSeen,
        ];
    }

    return [
        'unread_count' => $unread,
        'items' => $items,
    ];
}

function admin_notifications_last_seen(int $adminUserId): string
{
    $q = db()->prepare('SELECT last_seen_at FROM admin_notification_reads WHERE user_id = ?');
    $q->execute([$adminUserId]);
    $v = $q->fetchColumn();
    if (!is_string($v) || $v === '') {
        return '1970-01-01 00:00:00';
    }

    return $v;
}

function admin_notifications_mark_seen(int $adminUserId): void
{
    $now = db_timestamp();
    db()->prepare("
        INSERT INTO admin_notification_reads (user_id, last_seen_at)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at)
    ")->execute([$adminUserId, $now]);
}
