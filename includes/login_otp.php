<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

const LOGIN_OTP_TTL_MINUTES = 10;
const LOGIN_OTP_MAX_ATTEMPTS = 5;

function mask_email_for_display(string $email): string
{
    $email = trim($email);
    $pos = strpos($email, '@');
    if ($pos === false || $pos < 1) {
        return '***';
    }
    $local = substr($email, 0, $pos);
    $domain = substr($email, $pos + 1);
    $show = min(2, max(1, strlen($local)));

    return substr($local, 0, $show) . '***@' . $domain;
}

function login_otp_expires_at(): string
{
    return date('Y-m-d H:i:s', time() + LOGIN_OTP_TTL_MINUTES * 60);
}

/**
 * Replaces any prior challenge for this user.
 *
 * @return array{plain:string, challenge_id:int}|null
 */
function login_otp_create_challenge(int $userId): ?array
{
    $pdo = db();
    $pdo->prepare('DELETE FROM login_otp_challenges WHERE user_id = ?')->execute([$userId]);
    $plain = (string) random_int(100000, 999999);
    $hash = password_hash($plain, PASSWORD_DEFAULT);
    $expires = login_otp_expires_at();
    $now = db_timestamp();
    $ins = $pdo->prepare('
        INSERT INTO login_otp_challenges (user_id, code_hash, expires_at, attempts, created_at)
        VALUES (?, ?, ?, 0, ?)
    ');
    $ins->execute([$userId, $hash, $expires, $now]);
    $id = (int) $pdo->lastInsertId();
    if ($id <= 0) {
        return null;
    }

    return ['plain' => $plain, 'challenge_id' => $id];
}

/**
 * @return array{challenge_id:int, user_id:int, email_masked:string, attempts:int}|null
 */
function login_otp_challenge_context(?int $challengeId): ?array
{
    if ($challengeId === null || $challengeId <= 0) {
        return null;
    }
    $stmt = db()->prepare('
        SELECT c.id, c.user_id, c.expires_at, c.attempts, u.email
        FROM login_otp_challenges c
        INNER JOIN users u ON u.id = c.user_id
        WHERE c.id = ?
        LIMIT 1
    ');
    $stmt->execute([$challengeId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $expires = strtotime((string) $row['expires_at']);
    if ($expires === false || $expires < time()) {
        return null;
    }

    return [
        'challenge_id' => (int) $row['id'],
        'user_id' => (int) $row['user_id'],
        'email_masked' => mask_email_for_display((string) $row['email']),
        'attempts' => (int) $row['attempts'],
    ];
}

/** @return positive-int on success, 0 on failure */
function login_otp_verify_and_consume(int $challengeId, string $code): int
{
    $pdo = db();
    $stmt = $pdo->prepare('
        SELECT id, user_id, code_hash, expires_at, attempts
        FROM login_otp_challenges
        WHERE id = ?
        LIMIT 1
    ');
    $stmt->execute([$challengeId]);
    $row = $stmt->fetch();
    if (!$row) {
        return 0;
    }
    $expires = strtotime((string) $row['expires_at']);
    if ($expires === false || $expires < time()) {
        $pdo->prepare('DELETE FROM login_otp_challenges WHERE id = ?')->execute([$challengeId]);

        return 0;
    }
    if ((int) $row['attempts'] >= LOGIN_OTP_MAX_ATTEMPTS) {
        $pdo->prepare('DELETE FROM login_otp_challenges WHERE id = ?')->execute([$challengeId]);

        return 0;
    }

    $digits = preg_replace('/\D/', '', trim($code)) ?? '';
    if (strlen($digits) !== 6) {
        $pdo->prepare('UPDATE login_otp_challenges SET attempts = attempts + 1 WHERE id = ?')->execute([$challengeId]);
        login_otp_delete_if_max_attempts($pdo, $challengeId);

        return 0;
    }
    if (!password_verify($digits, (string) $row['code_hash'])) {
        $pdo->prepare('UPDATE login_otp_challenges SET attempts = attempts + 1 WHERE id = ?')->execute([$challengeId]);
        login_otp_delete_if_max_attempts($pdo, $challengeId);

        return 0;
    }

    $userId = (int) $row['user_id'];
    $pdo->prepare('DELETE FROM login_otp_challenges WHERE id = ?')->execute([$challengeId]);

    return $userId;
}

function login_otp_delete_if_max_attempts(PDO $pdo, int $challengeId): void
{
    $stmt = $pdo->prepare('SELECT attempts FROM login_otp_challenges WHERE id = ?');
    $stmt->execute([$challengeId]);
    $r = $stmt->fetch();
    if ($r && (int) $r['attempts'] >= LOGIN_OTP_MAX_ATTEMPTS) {
        $pdo->prepare('DELETE FROM login_otp_challenges WHERE id = ?')->execute([$challengeId]);
    }
}
