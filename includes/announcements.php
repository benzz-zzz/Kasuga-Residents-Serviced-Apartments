<?php
declare(strict_types=1);

if (!function_exists('db')) {
    require_once dirname(__DIR__) . '/db.php';
}

/**
 * @param 'public'|'tenant' $channel
 * @return list<array{id:int,title:string,body:string}>
 */
function active_announcements_for(string $channel): array
{
    if ($channel === 'public') {
        $sql = "SELECT id, title, body FROM announcements
                WHERE is_active = 1 AND audience IN ('public','both')
                ORDER BY sort_order ASC, id DESC LIMIT 15";
        return db()->query($sql)->fetchAll();
    }
    if ($channel === 'tenant') {
        $sql = "SELECT id, title, body FROM announcements
                WHERE is_active = 1 AND audience IN ('tenant','both')
                ORDER BY sort_order ASC, id DESC LIMIT 15";
        return db()->query($sql)->fetchAll();
    }
    return [];
}
