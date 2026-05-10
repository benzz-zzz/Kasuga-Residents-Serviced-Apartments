<?php
declare(strict_types=1);

/**
 * 1) Create database in phpMyAdmin: CREATE DATABASE kasuga_residences CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 * 2) Edit DB_* below (XAMPP default is often root with empty password).
 * 3) Open the site once; tables are created automatically, or import sql/schema.sql manually.
 */
session_start();

/**
 * Read environment variable with fallback.
 */
function env_value(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }

    return trim((string) $value);
}

date_default_timezone_set(env_value('APP_TIMEZONE', 'Asia/Manila'));

define('APP_NAME', 'Kasuga Residences');
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'kasuga_residences');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

/** Web path to app root (no trailing slash) */
define('APP_BASE', env_value('APP_BASE', '/Apartment%20system'));
define('APP_ADMIN', APP_BASE . '/admin');

/** Absolute site URL for password-reset emails (no trailing slash). Leave empty to build from the current request. */
define('APP_PUBLIC_URL', '');

/** Property location (public map) */
define('PROPERTY_NAME', env_value('PROPERTY_NAME', APP_NAME));
define('PROPERTY_ADDRESS', env_value('PROPERTY_ADDRESS', 'Kasuga Residences, Northline District, Metro Manila'));
define('PROPERTY_LAT', (float) env_value('PROPERTY_LAT', '14.5995'));
define('PROPERTY_LNG', (float) env_value('PROPERTY_LNG', '120.9842'));
define('PROPERTY_MAP_ZOOM', (int) env_value('PROPERTY_MAP_ZOOM', '13'));

/** From address (required for SMTP; used as envelope sender). */
define('MAIL_FROM', env_value('MAIL_FROM', 'benspngit@gmail.com'));

/** Display name for the From header (password reset and other app mail). */
define('MAIL_FROM_NAME', env_value('MAIL_FROM_NAME', APP_NAME));

/**
 * SMTP (optional). If MAIL_SMTP_HOST is non-empty, app mail uses SMTP instead of PHP mail().
 * Examples: Gmail — host smtp.gmail.com, port 587, encryption tls, use an App Password.
 */
define('MAIL_SMTP_HOST', env_value('MAIL_SMTP_HOST', 'smtp.gmail.com'));
define('MAIL_SMTP_PORT', (int) env_value('MAIL_SMTP_PORT', '587'));
/** tls = STARTTLS (typical on 587), ssl = implicit TLS (typical on 465), '' = none (not recommended). */
define('MAIL_SMTP_ENCRYPTION', strtolower(env_value('MAIL_SMTP_ENCRYPTION', 'tls')));
define('MAIL_SMTP_USER', env_value('MAIL_SMTP_USER', 'benspngit@gmail.com'));
define('MAIL_SMTP_PASS', env_value('MAIL_SMTP_PASS', ''));

/** Cloudflare Turnstile CAPTCHA (optional). */
define('TURNSTILE_SITE_KEY', env_value('TURNSTILE_SITE_KEY', '1x00000000000000000000AA'));
define('TURNSTILE_SECRET_KEY', env_value('TURNSTILE_SECRET_KEY', '1x0000000000000000000000000000000AA'));

/**
 * Google reCAPTCHA v2 checkbox (optional).
 * If both site + secret keys are set, Google is used instead of Turnstile.
 * Prefer env vars RECAPTCHA_SITE_KEY / RECAPTCHA_SECRET_KEY; do not publish real secrets publicly.
 */
define('RECAPTCHA_SITE_KEY', env_value('RECAPTCHA_SITE_KEY', '6LfhT-MsAAAAALBSuOn_6LepmmKvxS-d0v_azzes'));
define('RECAPTCHA_SECRET_KEY', env_value('RECAPTCHA_SECRET_KEY', '6LfhT-MsAAAAADzdk_qVRqaNM6HYJkE_WwshA2wJ'));

define('CAPTCHA_BYPASS_LOCAL', env_value('CAPTCHA_BYPASS_LOCAL', '0') === '1');

/** Room listing gallery: max URLs in admin, max thumbnails on public cards / book page. */
define('ROOM_GALLERY_MAX_IMAGES', 6);
define('ROOM_GALLERY_PUBLIC_MAX', 3);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function app_url(string $path = ''): string
{
    $base = rtrim((string) APP_BASE, '/');
    $path = ltrim($path, '/');
    if ($path === '') {
        return $base !== '' ? $base : '/';
    }

    return ($base !== '' ? $base : '') . '/' . $path;
}

function admin_url(string $path = ''): string
{
    $base = rtrim((string) APP_ADMIN, '/');
    $path = ltrim($path, '/');
    if ($path === '') {
        return $base;
    }

    return $base . '/' . $path;
}

function asset_url(string $path): string
{
    return app_url('assets/' . ltrim($path, '/'));
}

function redirect(string $path): void
{
    if (!preg_match('#^(?:https?:)?//#i', $path) && !str_starts_with($path, '/')) {
        $path = app_url($path);
    }
    header("Location: {$path}");
    exit;
}

function generate_csrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool
{
    return is_string($token) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/** For MySQL DATETIME columns (created_at, etc.) */
function db_timestamp(): string
{
    return date('Y-m-d H:i:s');
}

/** Base URL for links in outbound email (e.g. http://localhost/Apartment%20system). */
function app_public_base_url(): string
{
    if (defined('APP_PUBLIC_URL') && is_string(APP_PUBLIC_URL) && APP_PUBLIC_URL !== '') {
        return rtrim(APP_PUBLIC_URL, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[a-zA-Z0-9.:_-]+$/', $host)) {
        $host = 'localhost';
    }

    return ($https ? 'https' : 'http') . '://' . $host . APP_BASE;
}

/** Human-readable reservation timestamp (server/local PHP timezone). */
function format_booking_datetime(?string $mysqlDatetime): string
{
    if ($mysqlDatetime === null || $mysqlDatetime === '') {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($mysqlDatetime);

        return $dt->format('M j, Y \a\t g:i A');
    } catch (Exception $e) {
        return $mysqlDatetime;
    }
}

/** Format MySQL TIME (HH:MM:SS) for display; empty string if null. */
function format_booking_time(?string $mysqlTime): string
{
    if ($mysqlTime === null || $mysqlTime === '') {
        return '';
    }
    $dt = DateTimeImmutable::createFromFormat('H:i:s', $mysqlTime)
        ?: DateTimeImmutable::createFromFormat('H:i', $mysqlTime);
    if (!$dt) {
        return '';
    }

    return $dt->format('g:i A');
}

/** One line: date plus optional time (e.g. check-in). */
function format_booking_date_time(string $date, ?string $mysqlTime): string
{
    $t = format_booking_time($mysqlTime);
    if ($t === '') {
        return $date;
    }

    return $date . ' · ' . $t;
}

/** Nights between check-in and check-out dates (min 1). Uses calendar days; e.g. 27→30 = 3 nights. */
function booking_nights_count(string $checkInYmd, string $checkOutYmd): int
{
    try {
        $in = new DateTimeImmutable($checkInYmd . ' 00:00:00');
        $out = new DateTimeImmutable($checkOutYmd . ' 00:00:00');
    } catch (Exception $e) {
        return 1;
    }
    if ($out <= $in) {
        return 1;
    }

    return max(1, (int) $in->diff($out)->days);
}

/** Daily booking rate (uses room's listed amount as per-day price). */
function booking_nightly_rate_from_monthly(float $monthlyRate): float
{
    return round($monthlyRate, 2);
}

/** Total = daily room amount × nights. */
function booking_total_from_monthly_and_nights(float $monthlyRate, int $nights): float
{
    return round($monthlyRate * max(1, $nights), 2);
}

/** Number of guests beyond room capacity (cannot be negative). */
function booking_extra_guest_count(int $guestCount, int $roomCapacity): int
{
    return max(0, $guestCount - max(0, $roomCapacity));
}

/** Extra guest fee = additional guests × fixed fee. */
function booking_extra_guest_fee(int $guestCount, int $roomCapacity): float
{
    return 0.0;
}

/** Final total = base room total only (extra guest fee removed). */
function booking_total_with_guest_fee(float $monthlyRate, int $nights, int $guestCount, int $roomCapacity): float
{
    return booking_total_from_monthly_and_nights($monthlyRate, $nights);
}

/** @return list<string> */
function room_occupancy_statuses(): array
{
    return ['vacant', 'occupied', 'maintenance'];
}

function room_occupancy_label(string $status): string
{
    return match ($status) {
        'occupied' => 'Occupied (Reserved)',
        'maintenance' => 'Maintenance / closed',
        default => 'Open for reservation',
    };
}

/** @return array<string, string> value => admin label */
function room_occupancy_options(): array
{
    return [
        'vacant' => 'Open for reservation',
        'occupied' => 'Occupied — show Reserved on site',
        'maintenance' => 'Maintenance / closed (Unavailable)',
    ];
}

/** Badge text for public catalog when not open. */
function room_public_status_badge(?string $status): ?string
{
    return match ($status ?: 'vacant') {
        'occupied' => 'Reserved',
        'maintenance' => 'Unavailable',
        default => null,
    };
}

/**
 * Scalar for SELECT lists: 1 if a pending/confirmed booking has the guest in-house today
 * (check-in through day before departure; honors early_check_out_date).
 */
function sql_room_has_active_guest_column(): string
{
    return '(SELECT EXISTS(
        SELECT 1 FROM bookings b
        WHERE b.room_id = r.id
          AND b.status IN (\'confirmed\')
          AND CURDATE() >= b.check_in
          AND CURDATE() < COALESCE(b.early_check_out_date, b.check_out)
    )) AS has_active_guest';
}

/** Concatenated gallery for listing queries (requires group_concat_max_len). */
function sql_room_gallery_concat_column(): string
{
    return "(SELECT GROUP_CONCAT(i.image_url ORDER BY i.sort_order ASC, i.id ASC SEPARATOR '|||')
        FROM room_images i WHERE i.room_id = r.id) AS gallery_concat";
}

/**
 * @param array{gallery_concat?:string|null, image_url?:string} $room
 * @return list<string>
 */
function room_gallery_urls_from_row(array $room): array
{
    $raw = $room['gallery_concat'] ?? null;
    if (is_string($raw) && $raw !== '') {
        $urls = array_values(array_filter(array_map('trim', explode('|||', $raw))));
        if ($urls !== []) {
            return array_slice($urls, 0, ROOM_GALLERY_MAX_IMAGES);
        }
    }
    $legacy = trim((string) ($room['image_url'] ?? ''));
    if ($legacy !== '') {
        return [$legacy];
    }

    return [];
}

/** Up to three images for catalog cards and booking preview. */
function room_gallery_public_preview(array $room): array
{
    return array_slice(room_gallery_urls_from_row($room), 0, ROOM_GALLERY_PUBLIC_MAX);
}

function room_primary_image_url(array $room): string
{
    $u = room_gallery_urls_from_row($room);

    return $u[0] ?? '';
}

/**
 * Public room card badge from listing row (occupancy_status + has_active_guest from sql_room_has_active_guest_column()).
 *
 * @param array{occupancy_status?:string, has_active_guest?:int|string|bool|null} $room
 */
function room_catalog_badge_text(array $room): ?string
{
    $occ = (string)($room['occupancy_status'] ?? 'vacant');
    if ($occ === 'maintenance') {
        return 'Unavailable';
    }
    $guest = !empty($room['has_active_guest']);
    if ($occ === 'occupied' || $guest) {
        return 'Reserved';
    }

    return null;
}

/** CSS modifier for room-card__status-badge (reserved vs closed). */
function room_catalog_badge_css_class(array $room): string
{
    return (($room['occupancy_status'] ?? '') === 'maintenance')
        ? 'room-card__status-badge--closed'
        : 'room-card__status-badge--reserved';
}

function room_is_open_for_booking(array $room): bool
{
    return !empty($room['is_active']) && (($room['occupancy_status'] ?? 'vacant') === 'vacant');
}

/**
 * In-house = confirmed stay where today is on or after check-in and before effective check-out
 * (honors early_check_out_date).
 */
function room_has_in_house_stay(int $roomId, ?PDO $pdo = null): bool
{
    $pdo = $pdo ?? db();
    $q = $pdo->prepare("
        SELECT EXISTS(
            SELECT 1 FROM bookings b
            WHERE b.room_id = ?
              AND b.status IN ('confirmed')
              AND CURDATE() >= b.check_in
              AND CURDATE() < COALESCE(b.early_check_out_date, b.check_out)
        )");
    $q->execute([$roomId]);

    return (bool) $q->fetchColumn();
}

/**
 * Set reservation status from active stays: occupied when a guest is in-house, else open (vacant).
 * Skips rooms in maintenance (admin hand-set).
 */
function sync_room_occupancy_from_stays(int $roomId, ?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare('SELECT occupancy_status FROM rooms WHERE id = ?');
    $st->execute([$roomId]);
    $row = $st->fetch();
    if (!$row) {
        return;
    }
    if ((string) ($row['occupancy_status'] ?? 'vacant') === 'maintenance') {
        return;
    }
    $target = room_has_in_house_stay($roomId, $pdo) ? 'occupied' : 'vacant';
    $pdo->prepare('UPDATE rooms SET occupancy_status = ? WHERE id = ? AND occupancy_status != ?')
        ->execute([$target, $roomId, 'maintenance']);
}

/** Reconcile every room: in-house stay → occupied, else open (skips maintenance). */
function sync_all_rooms_occupancy_from_stays(?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();
    $ids = $pdo->query('SELECT id FROM rooms')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        sync_room_occupancy_from_stays((int) $id, $pdo);
    }
}

/** Session key: book flow stores details here until checkout submit creates the row. */
function booking_checkout_draft_session_key(): string
{
    return 'booking_checkout_draft';
}

function booking_checkout_draft_max_age_seconds(): int
{
    return 2700;
}

/**
 * Valid in-session checkout draft for the logged-in user, or null if missing/expired.
 *
 * @return array<string, mixed>|null
 */
function booking_checkout_draft_peek(): ?array
{
    $k = booking_checkout_draft_session_key();
    $d = $_SESSION[$k] ?? null;
    if (!is_array($d)) {
        return null;
    }
    $user = current_user();
    if (!$user || (int) ($d['user_id'] ?? 0) !== (int) $user['id']) {
        return null;
    }
    foreach (['room_id', 'guest_count', 'check_in', 'check_out', 'payment_choice', 'notes'] as $f) {
        if (!array_key_exists($f, $d)) {
            return null;
        }
    }
    $saved = (int) ($d['saved_at'] ?? 0);
    if ($saved > 0 && time() - $saved > booking_checkout_draft_max_age_seconds()) {
        unset($_SESSION[$k]);

        return null;
    }

    return $d;
}

function booking_checkout_draft_clear(): void
{
    unset($_SESSION[booking_checkout_draft_session_key()]);
}
