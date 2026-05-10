<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
        $pdo->exec('SET SESSION group_concat_max_len = 131072');
    } catch (PDOException $e) {
        $code = $e->getCode();
        $msg = $e->getMessage();
        if ($code === '1049' || str_contains($msg, '1049') || str_contains($msg, "Unknown database")) {
            ensure_mysql_database_exists($opts);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
        } else {
            throw $e;
        }
        $pdo->exec('SET SESSION group_concat_max_len = 131072');
    }

    $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

    bootstrap_db($pdo);

    return $pdo;
}

function ensure_mysql_database_exists(array $opts): void
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', DB_NAME)) {
        throw new InvalidArgumentException('Invalid DB_NAME');
    }
    $adminDsn = sprintf('mysql:host=%s;port=%d;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
    $admin = new PDO($adminDsn, DB_USER, DB_PASS, $opts);
    $db = DB_NAME;
    $admin->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}


/**
 * MySQL: create tables if missing, then seed default admin + sample rooms.
 */
function bootstrap_db(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            full_name VARCHAR(80) NOT NULL,
            email VARCHAR(191) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'tenant',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_users_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rooms (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            room_code VARCHAR(32) NOT NULL,
            title VARCHAR(120) NOT NULL,
            description TEXT NOT NULL,
            monthly_rate DECIMAL(12,2) NOT NULL,
            capacity INT UNSIGNED NOT NULL,
            image_url VARCHAR(2000) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            occupancy_status VARCHAR(20) NOT NULL DEFAULT 'vacant',
            PRIMARY KEY (id),
            UNIQUE KEY uq_rooms_code (room_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ensure_room_capacity_column($pdo);
    ensure_room_occupancy_status_column($pdo);
    ensure_room_images_table($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bookings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            room_id INT UNSIGNED NOT NULL,
            guest_count INT UNSIGNED NOT NULL DEFAULT 1,
            check_in DATE NOT NULL,
            check_in_time TIME NULL DEFAULT NULL,
            check_out DATE NOT NULL,
            check_out_time TIME NULL DEFAULT NULL,
            early_check_out_date DATE NULL DEFAULT NULL,
            total_amount DECIMAL(12,2) NOT NULL,
            paid_amount DECIMAL(12,2) NULL DEFAULT NULL,
            receipt_reference VARCHAR(255) NOT NULL DEFAULT '',
            payment_submitted_at DATETIME NULL DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            notes VARCHAR(1000) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_bookings_user (user_id),
            KEY idx_bookings_room (room_id),
            KEY idx_bookings_status (status),
            CONSTRAINT fk_bookings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_bookings_room FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ensure_booking_time_columns($pdo);
    ensure_booking_guest_count_column($pdo);
    ensure_booking_early_checkout_column($pdo);
    ensure_booking_payment_columns($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reviews (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            room_id INT UNSIGNED NOT NULL,
            rating TINYINT UNSIGNED NOT NULL,
            comment VARCHAR(2000) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_reviews_room (room_id),
            KEY idx_reviews_user (user_id),
            CONSTRAINT fk_reviews_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_reviews_room FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS announcements (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(160) NOT NULL,
            body TEXT NOT NULL,
            audience VARCHAR(20) NOT NULL DEFAULT 'both',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_announcements_active (is_active, audience)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ensure_booking_removal_archive_table($pdo);
    ensure_password_reset_tokens_table($pdo);
    ensure_admin_notification_reads_table($pdo);

    seed_defaults($pdo);
    seed_room_images_from_legacy_if_empty($pdo);
    seed_announcements_if_empty($pdo);
}

/** Add check-in / check-out time columns for existing databases created before this migration. */
function ensure_booking_time_columns(PDO $pdo): void
{
    $row = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'check_in_time'")->fetch();
    if ($row) {
        return;
    }
    $pdo->exec('ALTER TABLE bookings ADD COLUMN check_in_time TIME NULL DEFAULT NULL AFTER check_in');
    $pdo->exec('ALTER TABLE bookings ADD COLUMN check_out_time TIME NULL DEFAULT NULL AFTER check_out');
}

/** Number of guests attached to each booking request. */
function ensure_booking_guest_count_column(PDO $pdo): void
{
    $row = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'guest_count'")->fetch();
    if ($row) {
        return;
    }
    $pdo->exec('ALTER TABLE bookings ADD COLUMN guest_count INT UNSIGNED NOT NULL DEFAULT 1 AFTER room_id');
}

/** Actual departure date when guest leaves before the booked check-out date (admin). */
function ensure_booking_early_checkout_column(PDO $pdo): void
{
    $row = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'early_check_out_date'")->fetch();
    if ($row) {
        return;
    }
    $pdo->exec('ALTER TABLE bookings ADD COLUMN early_check_out_date DATE NULL DEFAULT NULL AFTER check_out_time');
}

/** Payment proof fields used by admin confirmation flow. */
function ensure_booking_payment_columns(PDO $pdo): void
{
    $hasPaid = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'paid_amount'")->fetch();
    if (!$hasPaid) {
        $pdo->exec('ALTER TABLE bookings ADD COLUMN paid_amount DECIMAL(12,2) NULL DEFAULT NULL AFTER total_amount');
    }

    $hasReceipt = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'receipt_reference'")->fetch();
    if (!$hasReceipt) {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN receipt_reference VARCHAR(255) NOT NULL DEFAULT '' AFTER paid_amount");
    }

    $hasSubmittedAt = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'payment_submitted_at'")->fetch();
    if (!$hasSubmittedAt) {
        $pdo->exec('ALTER TABLE bookings ADD COLUMN payment_submitted_at DATETIME NULL DEFAULT NULL AFTER receipt_reference');
    }
}

/** Expand room capacity storage from tinyint to int for larger buildings. */
function ensure_room_capacity_column(PDO $pdo): void
{
    $row = $pdo->query("SHOW COLUMNS FROM rooms LIKE 'capacity'")->fetch();
    if (!$row) {
        return;
    }
    $type = strtolower((string)($row['Type'] ?? ''));
    if (str_starts_with($type, 'int')) {
        return;
    }
    $pdo->exec('ALTER TABLE rooms MODIFY COLUMN capacity INT UNSIGNED NOT NULL');
}

/** Extra photos per listing; rooms.image_url stays the primary (first) image for compatibility. */
function ensure_room_images_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS room_images (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            room_id INT UNSIGNED NOT NULL,
            image_url VARCHAR(2000) NOT NULL,
            sort_order SMALLINT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_room_images_room (room_id),
            CONSTRAINT fk_room_images_room FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/** One-time copy from rooms.image_url so existing installs get a gallery row per room. */
function seed_room_images_from_legacy_if_empty(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM room_images')->fetchColumn() > 0) {
        return;
    }
    $ins = $pdo->prepare('INSERT INTO room_images (room_id, image_url, sort_order) VALUES (?, ?, 0)');
    foreach ($pdo->query('SELECT id, image_url FROM rooms') as $row) {
        $url = trim((string) ($row['image_url'] ?? ''));
        if ($url !== '') {
            $ins->execute([(int) $row['id'], $url]);
        }
    }
}

/** Replace all gallery rows and sync rooms.image_url to the first URL. */
function room_persist_gallery(PDO $pdo, int $roomId, array $imageUrls): void
{
    $pdo->prepare('DELETE FROM room_images WHERE room_id = ?')->execute([$roomId]);
    $ins = $pdo->prepare('INSERT INTO room_images (room_id, image_url, sort_order) VALUES (?, ?, ?)');
    foreach (array_values($imageUrls) as $ord => $url) {
        $ins->execute([$roomId, $url, $ord]);
    }
    if ($imageUrls !== []) {
        $pdo->prepare('UPDATE rooms SET image_url = ? WHERE id = ?')->execute([$imageUrls[0], $roomId]);
    }
}

/** vacant = open; occupied = reserved on site; maintenance = not bookable. */
function ensure_room_occupancy_status_column(PDO $pdo): void
{
    $row = $pdo->query("SHOW COLUMNS FROM rooms LIKE 'occupancy_status'")->fetch();
    if ($row) {
        return;
    }
    $pdo->exec("ALTER TABLE rooms ADD COLUMN occupancy_status VARCHAR(20) NOT NULL DEFAULT 'vacant' AFTER is_active");
}

function seed_defaults(PDO $pdo): void
{
    $adminEmail = 'admin@kasuga.local';
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$adminEmail]);
    if (!$stmt->fetch()) {
        $insert = $pdo->prepare('
            INSERT INTO users (full_name, email, phone, password_hash, role, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $insert->execute([
            'System Admin',
            $adminEmail,
            '09123456789',
            password_hash('Admin@123', PASSWORD_DEFAULT),
            'admin',
            db_timestamp(),
        ]);
    }

    $count = (int) $pdo->query('SELECT COUNT(*) FROM rooms')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $rooms = [
        ['RM-101', 'Studio Unit', 'Good for solo professionals.', 8500, 2, 'https://picsum.photos/seed/room1/600/360'],
        ['RM-202', 'Family Unit', 'Comfortable setup for small families.', 12500, 4, 'https://picsum.photos/seed/room2/600/360'],
        ['RM-303', 'Premium Loft', 'Spacious loft with modern amenities.', 17500, 5, 'https://picsum.photos/seed/room3/600/360'],
    ];

    $insertRoom = $pdo->prepare('
        INSERT INTO rooms (room_code, title, description, monthly_rate, capacity, image_url, is_active, occupancy_status)
        VALUES (?, ?, ?, ?, ?, ?, 1, \'vacant\')
    ');

    foreach ($rooms as $room) {
        $insertRoom->execute($room);
    }
}

/** Snapshots of bookings when an admin removes them from the live list (checked out or cancelled only). */
function ensure_booking_removal_archive_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS booking_removal_archive (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            original_booking_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            room_id INT UNSIGNED NOT NULL,
            guest_count INT UNSIGNED NOT NULL DEFAULT 1,
            check_in DATE NOT NULL,
            check_in_time TIME NULL DEFAULT NULL,
            check_out DATE NOT NULL,
            check_out_time TIME NULL DEFAULT NULL,
            early_check_out_date DATE NULL DEFAULT NULL,
            total_amount DECIMAL(12,2) NOT NULL,
            paid_amount DECIMAL(12,2) NULL DEFAULT NULL,
            receipt_reference VARCHAR(255) NOT NULL DEFAULT '',
            payment_submitted_at DATETIME NULL DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            notes VARCHAR(1000) NOT NULL DEFAULT '',
            booking_created_at DATETIME NOT NULL,
            guest_full_name VARCHAR(80) NOT NULL DEFAULT '',
            guest_email VARCHAR(191) NOT NULL DEFAULT '',
            room_code VARCHAR(32) NOT NULL DEFAULT '',
            room_title VARCHAR(120) NOT NULL DEFAULT '',
            archived_at DATETIME NOT NULL,
            archived_by INT UNSIGNED NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_bra_original (original_booking_id),
            KEY idx_bra_archived (archived_at),
            CONSTRAINT fk_bra_archived_by FOREIGN KEY (archived_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * Copy a booking row (plus guest/room labels) into booking_removal_archive. Call before DELETE FROM bookings.
 * @return bool false if the booking row could not be loaded for archiving
 */
function insert_booking_removal_archive(int $bookingId, ?int $archivedByUserId): bool
{
    $stmt = db()->prepare('
        SELECT b.id AS original_booking_id, b.user_id, b.room_id, b.guest_count, b.check_in, b.check_in_time,
               b.check_out, b.check_out_time, b.early_check_out_date, b.total_amount, b.paid_amount,
               b.receipt_reference, b.payment_submitted_at, b.status, b.notes, b.created_at AS booking_created_at,
               u.full_name AS guest_full_name, u.email AS guest_email, r.room_code, r.title AS room_title
        FROM bookings b
        INNER JOIN users u ON u.id = b.user_id
        INNER JOIN rooms r ON r.id = b.room_id
        WHERE b.id = ?
    ');
    $stmt->execute([$bookingId]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }

    $now = db_timestamp();
    $ins = db()->prepare('
        INSERT INTO booking_removal_archive (
            original_booking_id, user_id, room_id, guest_count, check_in, check_in_time, check_out, check_out_time,
            early_check_out_date, total_amount, paid_amount, receipt_reference, payment_submitted_at, status, notes,
            booking_created_at, guest_full_name, guest_email, room_code, room_title, archived_at, archived_by
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ');
    $ins->execute([
        (int) $row['original_booking_id'],
        (int) $row['user_id'],
        (int) $row['room_id'],
        (int) ($row['guest_count'] ?? 1),
        (string) $row['check_in'],
        $row['check_in_time'] !== null && $row['check_in_time'] !== '' ? (string) $row['check_in_time'] : null,
        (string) $row['check_out'],
        $row['check_out_time'] !== null && $row['check_out_time'] !== '' ? (string) $row['check_out_time'] : null,
        $row['early_check_out_date'] !== null && $row['early_check_out_date'] !== '' ? (string) $row['early_check_out_date'] : null,
        (string) $row['total_amount'],
        $row['paid_amount'] !== null && $row['paid_amount'] !== '' ? (string) $row['paid_amount'] : null,
        (string) ($row['receipt_reference'] ?? ''),
        $row['payment_submitted_at'] !== null && $row['payment_submitted_at'] !== '' ? (string) $row['payment_submitted_at'] : null,
        (string) $row['status'],
        (string) ($row['notes'] ?? ''),
        (string) $row['booking_created_at'],
        (string) ($row['guest_full_name'] ?? ''),
        (string) ($row['guest_email'] ?? ''),
        (string) ($row['room_code'] ?? ''),
        (string) ($row['room_title'] ?? ''),
        $now,
        $archivedByUserId,
    ]);

    return true;
}

/** One-time tokens emailed for password reset (hashed at rest). */
function ensure_password_reset_tokens_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_password_reset_token_hash (token_hash),
            KEY idx_password_reset_user (user_id),
            KEY idx_password_reset_expires (expires_at),
            CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/** Per-admin marker for when notifications were last seen. */
function ensure_admin_notification_reads_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_notification_reads (
            user_id INT UNSIGNED NOT NULL,
            last_seen_at DATETIME NOT NULL,
            PRIMARY KEY (user_id),
            CONSTRAINT fk_admin_notification_reads_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function seed_announcements_if_empty(PDO $pdo): void
{
    $n = (int) $pdo->query('SELECT COUNT(*) FROM announcements')->fetchColumn();
    if ($n > 0) {
        return;
    }
    $now = db_timestamp();
    $pdo->prepare('
        INSERT INTO announcements (title, body, audience, is_active, sort_order, created_at, updated_at)
        VALUES (?, ?, ?, 1, 0, ?, ?)
    ')->execute([
        'Welcome to Kasuga Residences',
        'Smart access, quiet floors, and concierge-ready support. Book your stay through the resident portal—rates update in real time.',
        'both',
        $now,
        $now,
    ]);
}
