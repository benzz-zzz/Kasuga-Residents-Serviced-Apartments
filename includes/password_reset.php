<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';

function password_reset_token_hash(string $rawToken): string
{
    return hash('sha256', $rawToken);
}

/** Create a new token for this user (invalidates any previous tokens). @return string 64-char hex for the reset URL */
function password_reset_issue(int $userId): string
{
    db()->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$userId]);
    db()->exec('DELETE FROM password_reset_tokens WHERE expires_at < NOW()');

    $raw = bin2hex(random_bytes(32));
    $hash = password_reset_token_hash($raw);
    db()->prepare('
        INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at)
        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())
    ')->execute([$userId, $hash]);

    return $raw;
}

/** @return array{id:int,user_id:int}|null */
function password_reset_lookup(string $rawToken): ?array
{
    $rawToken = trim($rawToken);
    if ($rawToken === '' || !preg_match('/^[a-f0-9]{64}$/i', $rawToken)) {
        return null;
    }
    $hash = password_reset_token_hash($rawToken);
    $stmt = db()->prepare('
        SELECT id, user_id FROM password_reset_tokens
        WHERE token_hash = ? AND expires_at > NOW()
        LIMIT 1
    ');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id']];
}

function password_reset_consume_all_for_user(int $userId): void
{
    db()->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$userId]);
}
