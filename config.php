<?php
declare(strict_types=1);

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

if (class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
}

/**
 * 1) Copy .env.example to .env in the project root and set values.
 * 2) Open the site once; tables bootstrap automatically, or import sql/schema.sql.
 */
session_start();

/**
 * Read environment variable. Order: real process env first (Railway / Docker inject here via getenv),
 * then Dotenv-populated $_ENV / $_SERVER, then default.
 * Strips wrapping double quotes (common when pasting KEY="value" into Railway).
 */
function env_value(string $key, string $default = ''): string
{
    $raw = '';
    $g = getenv($key);
    if ($g !== false) {
        $raw = (string) $g;
    } elseif (array_key_exists($key, $_ENV)) {
        $raw = (string) $_ENV[$key];
    } elseif (array_key_exists($key, $_SERVER) && !str_starts_with($key, 'HTTP_')) {
        $raw = (string) $_SERVER[$key];
    } else {
        return $default;
    }

    $raw = trim($raw);
    if ($raw !== '' && strlen($raw) >= 2 && $raw[0] === '"' && $raw[strlen($raw) - 1] === '"') {
        $raw = stripcslashes(substr($raw, 1, -1));
    }

    return trim($raw);
}

/**
 * DB params: explicit DB_* / MYSQL* vars, or Railway-style MYSQL_URL (mysql://user:pass@host:port/db).
 *
 * @return array{host:string,port:int,name:string,user:string,pass:string}
 */
function mysql_connection_from_env(): array
{
    $url = env_value('MYSQL_URL', '');
    // Unresolved Railway reference (copy-paste / deploy order) — use discrete MYSQL_* / DB_* instead
    if ($url !== '' && str_contains($url, '${{')) {
        $url = '';
    }
    if ($url !== '') {
        $normalized = preg_replace('#^mysql2:#', 'mysql:', $url, 1) ?? $url;
        if (str_starts_with($normalized, 'mysql://')) {
            $u = parse_url($normalized);
            if (is_array($u) && !empty($u['host'])) {
                $path = isset($u['path']) ? trim((string) $u['path'], '/') : '';

                return [
                    'host' => (string) $u['host'],
                    'port' => isset($u['port']) ? (int) $u['port'] : 3306,
                    'name' => $path !== '' ? $path : env_value('MYSQLDATABASE', 'kasuga_residences'),
                    'user' => isset($u['user']) ? rawurldecode((string) $u['user']) : env_value('MYSQLUSER', 'root'),
                    'pass' => isset($u['pass']) ? rawurldecode((string) $u['pass']) : env_value('MYSQLPASSWORD', ''),
                ];
            }
        }
    }

    $dbPortRaw = env_value('DB_PORT', env_value('MYSQLPORT', '3306'));
    $dbPort = is_numeric($dbPortRaw) ? (int) $dbPortRaw : 3306;

    return [
        'host' => env_value('DB_HOST', env_value('MYSQLHOST', '127.0.0.1')),
        'port' => $dbPort,
        'name' => env_value('DB_NAME', env_value('MYSQLDATABASE', 'kasuga_residences')),
        'user' => env_value('DB_USER', env_value('MYSQLUSER', 'root')),
        'pass' => env_value('DB_PASS', env_value('MYSQLPASSWORD', '')),
    ];
}

date_default_timezone_set(env_value('APP_TIMEZONE', 'Asia/Manila'));

$appName = env_value('APP_NAME', '');
define('APP_NAME', $appName !== '' ? $appName : 'Kasuga Residences');

$dbConn = mysql_connection_from_env();
define('DB_HOST', $dbConn['host']);
define('DB_PORT', $dbConn['port']);
define('DB_NAME', $dbConn['name']);
define('DB_USER', $dbConn['user']);
define('DB_PASS', $dbConn['pass']);
define('DB_CHARSET', env_value('DB_CHARSET', 'utf8mb4'));

/** Web path to app root (no trailing slash). Empty = domain root (typical on Railway). */
$railwayDb = env_value('MYSQLHOST', '') !== '' || env_value('MYSQL_URL', '') !== '';
$onRailway = env_value('RAILWAY_ENVIRONMENT', '') !== '' || env_value('RAILWAY_PUBLIC_DOMAIN', '') !== '';
$defaultAppBase = ($railwayDb || $onRailway) ? '' : '/Apartment%20system';
define('APP_BASE', env_value('APP_BASE', $defaultAppBase));
define('APP_ADMIN', APP_BASE . '/admin');

define('APP_PUBLIC_URL', env_value('APP_PUBLIC_URL', ''));

/** Property location (public map) — values from .env */
$pName = env_value('PROPERTY_NAME', '');
define('PROPERTY_NAME', $pName !== '' ? $pName : APP_NAME);
$pAddr = env_value('PROPERTY_ADDRESS', '');
define('PROPERTY_ADDRESS', $pAddr);
define('PROPERTY_LAT', (float) env_value('PROPERTY_LAT', '14.5995'));
define('PROPERTY_LNG', (float) env_value('PROPERTY_LNG', '120.9842'));
define('PROPERTY_MAP_ZOOM', (int) env_value('PROPERTY_MAP_ZOOM', '13'));

define('MAIL_FROM', env_value('MAIL_FROM', ''));
define('MAIL_FROM_NAME', env_value('MAIL_FROM_NAME', ''));

/** Brevo transactional API — https://api.brevo.com/v3/smtp/email */
define('BREVO_API_KEY', env_value('BREVO_API_KEY', ''));

define('TURNSTILE_SITE_KEY', env_value('TURNSTILE_SITE_KEY', ''));
define('TURNSTILE_SECRET_KEY', env_value('TURNSTILE_SECRET_KEY', ''));

define('RECAPTCHA_SITE_KEY', env_value('RECAPTCHA_SITE_KEY', ''));
define('RECAPTCHA_SECRET_KEY', env_value('RECAPTCHA_SECRET_KEY', ''));

define('CAPTCHA_BYPASS_LOCAL', env_value('CAPTCHA_BYPASS_LOCAL', '0') === '1');

define('ROOM_GALLERY_MAX_IMAGES', max(1, min(50, (int) env_value('ROOM_GALLERY_MAX_IMAGES', '6'))));
define('ROOM_GALLERY_PUBLIC_MAX', max(1, min(20, (int) env_value('ROOM_GALLERY_PUBLIC_MAX', '3'))));

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
