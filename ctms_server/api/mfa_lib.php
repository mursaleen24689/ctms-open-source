<?php
declare(strict_types=1);

/** Shared offline TOTP, login-ticket, and local authentication alert helpers. */
function ctms_mfa_key(): string
{
    return hash_hmac('sha256', 'ctms-mfa-secret-encryption-v1', (string)JWT_SECRET, true);
}

function ctms_mfa_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS ctms_user_mfa (
        user_id INT NOT NULL PRIMARY KEY,
        secret_ciphertext TEXT NOT NULL,
        enabled_at DATETIME NULL,
        last_counter BIGINT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $counterColumn = $pdo->query("SHOW COLUMNS FROM ctms_user_mfa LIKE 'last_counter'")->fetch(PDO::FETCH_ASSOC);
    if (!$counterColumn) $pdo->exec('ALTER TABLE ctms_user_mfa ADD COLUMN last_counter BIGINT NULL AFTER enabled_at');
    $pdo->exec("CREATE TABLE IF NOT EXISTS ctms_mfa_recovery_codes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_ctms_mfa_recovery_user (user_id, used_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ctms_login_challenges (
        ticket_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        user_id INT NOT NULL,
        purpose VARCHAR(16) NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_ctms_login_challenges_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ctms_auth_failures (
        account_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        window_started DATETIME NOT NULL,
        blocked_until DATETIME NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_ctms_auth_failures_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ctms_auth_security_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        identifier VARCHAR(190) NOT NULL DEFAULT '',
        ip_address VARCHAR(45) NOT NULL DEFAULT '',
        event_type VARCHAR(40) NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_ctms_auth_events_time (created_at),
        INDEX idx_ctms_auth_events_ip_time (ip_address, created_at),
        INDEX idx_ctms_auth_events_identifier_time (identifier, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = true;
}

function ctms_mfa_encrypt(string $plain): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', ctms_mfa_key(), OPENSSL_RAW_DATA, $iv, $tag, 'ctms-user-mfa-v1');
    if ($cipher === false) throw new RuntimeException('MFA secret encryption failed.');
    return base64_encode($iv . $tag . $cipher);
}

function ctms_mfa_decrypt(string $encoded): string
{
    $packed = base64_decode($encoded, true);
    if ($packed === false || strlen($packed) < 29) throw new RuntimeException('Stored MFA secret is invalid.');
    $plain = openssl_decrypt(substr($packed, 28), 'aes-256-gcm', ctms_mfa_key(), OPENSSL_RAW_DATA, substr($packed, 0, 12), substr($packed, 12, 16), 'ctms-user-mfa-v1');
    if ($plain === false) throw new RuntimeException('MFA secret could not be decrypted.');
    return $plain;
}

function ctms_mfa_base32_encode(string $raw): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($raw) as $char) $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function ctms_mfa_base32_decode(string $encoded): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(rtrim($encoded, '='))) as $char) {
        $index = strpos($alphabet, $char);
        if ($index === false) throw new InvalidArgumentException('Invalid MFA secret.');
        $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
    }
    $raw = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $raw .= chr(bindec($byte));
    return $raw;
}

function ctms_mfa_totp(string $secret, ?int $timestamp = null): string
{
    $counter = intdiv($timestamp ?? time(), 30);
    $counterBytes = pack('N2', 0, $counter);
    $hash = hash_hmac('sha1', $counterBytes, ctms_mfa_base32_decode($secret), true);
    $offset = ord($hash[19]) & 0x0f;
    $binary = ((ord($hash[$offset]) & 0x7f) << 24) | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8) | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string)($binary % 1000000), 6, '0', STR_PAD_LEFT);
}

function ctms_mfa_consume_totp(PDO $pdo, int $userId, string $secret, string $code): bool
{
    if (!preg_match('/^\d{6}$/', trim($code))) return false;
    $now = time();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT last_counter FROM ctms_user_mfa WHERE user_id=? FOR UPDATE');
        $stmt->execute([$userId]);
        $lastCounter = $stmt->fetchColumn();
        foreach ([0, -1, 1] as $drift) {
            $counter = intdiv($now + ($drift * 30), 30);
            if (($lastCounter === false || $lastCounter === null || $counter > (int)$lastCounter)
                && hash_equals(ctms_mfa_totp($secret, $now + ($drift * 30)), trim($code))) {
                $pdo->prepare('UPDATE ctms_user_mfa SET last_counter=?,updated_at=NOW() WHERE user_id=?')->execute([$counter,$userId]);
                $pdo->commit();
                return true;
            }
        }
        $pdo->commit();
        return false;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function ctms_mfa_new_ticket(PDO $pdo, int $userId, string $purpose): string
{
    ctms_mfa_ensure_schema($pdo);
    $ticket = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO ctms_login_challenges (ticket_hash,user_id,purpose,expires_at,created_at) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE),NOW())')
        ->execute([hash('sha256', $ticket), $userId, $purpose]);
    return $ticket;
}

function ctms_mfa_ticket(PDO $pdo, string $ticket, string $purpose, bool $consume = false): array|false
{
    if (!preg_match('/^[a-f0-9]{64}$/i', $ticket)) return false;
    ctms_mfa_ensure_schema($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT user_id,attempts,expires_at,used_at FROM ctms_login_challenges WHERE ticket_hash=? AND purpose=? FOR UPDATE');
        $stmt->execute([hash('sha256', strtolower($ticket)), $purpose]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['used_at'] || strtotime($row['expires_at']) <= time() || (int)$row['attempts'] >= 8) {
            $pdo->commit(); return false;
        }
        if ($consume) $pdo->prepare('UPDATE ctms_login_challenges SET used_at=NOW() WHERE ticket_hash=?')->execute([hash('sha256', strtolower($ticket))]);
        else $pdo->prepare('UPDATE ctms_login_challenges SET attempts=attempts+1 WHERE ticket_hash=?')->execute([hash('sha256', strtolower($ticket))]);
        $pdo->commit();
        return ['user_id' => (int)$row['user_id']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function ctms_auth_event(PDO $pdo, string $event, string $identifier = '', ?int $userId = null): void
{
    ctms_mfa_ensure_schema($pdo);
    $pdo->prepare('INSERT INTO ctms_auth_security_events (user_id,identifier,ip_address,event_type,created_at) VALUES (?,?,?,?,NOW())')
        ->execute([$userId, substr(strtolower(trim($identifier)), 0, 190), substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), substr($event, 0, 40)]);
}

/** Return remaining cooldown seconds, or zero. The delay is bounded and self-clears. */
function ctms_auth_cooldown(PDO $pdo, string $identifier): int
{
    ctms_mfa_ensure_schema($pdo);
    $key = hash_hmac('sha256', strtolower(trim($identifier)), (string)JWT_SECRET);
    $stmt = $pdo->prepare('SELECT failures,window_started,blocked_until FROM ctms_auth_failures WHERE account_key=?');
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return 0;
    if (strtotime($row['window_started']) < time() - 3600) {
        $pdo->prepare('DELETE FROM ctms_auth_failures WHERE account_key=?')->execute([$key]);
        return 0;
    }
    return !empty($row['blocked_until']) ? max(0, strtotime($row['blocked_until']) - time()) : 0;
}

function ctms_auth_failed(PDO $pdo, string $identifier, string $event, ?int $userId = null): int
{
    ctms_mfa_ensure_schema($pdo);
    ctms_auth_event($pdo, $event, $identifier, $userId);
    $key = hash_hmac('sha256', strtolower(trim($identifier)), (string)JWT_SECRET);
    $pdo->prepare('INSERT INTO ctms_auth_failures (account_key,failures,window_started,blocked_until,updated_at) VALUES (?,1,NOW(),NULL,NOW())
        ON DUPLICATE KEY UPDATE failures=IF(window_started < (NOW() - INTERVAL 1 HOUR),1,failures+1),
        window_started=IF(window_started < (NOW() - INTERVAL 1 HOUR),NOW(),window_started),updated_at=NOW()')
        ->execute([$key]);
    $stmt = $pdo->prepare('SELECT failures FROM ctms_auth_failures WHERE account_key=?');
    $stmt->execute([$key]);
    $failures = (int)$stmt->fetchColumn();
    $delays = [0, 0, 0, 5, 15, 45, 120, 300, 600, 900];
    $delay = $delays[min($failures, count($delays) - 1)];
    $pdo->prepare('UPDATE ctms_auth_failures SET blocked_until=IF(? > 0,DATE_ADD(NOW(),INTERVAL ? SECOND),NULL) WHERE account_key=?')
        ->execute([$delay, $delay, $key]);
    return $delay;
}

function ctms_auth_succeeded(PDO $pdo, string $identifier): void
{
    ctms_mfa_ensure_schema($pdo);
    $pdo->prepare('DELETE FROM ctms_auth_failures WHERE account_key=?')->execute([hash_hmac('sha256', strtolower(trim($identifier)), (string)JWT_SECRET)]);
}

function ctms_mfa_recovery_hash(string $code): string
{
    return hash_hmac('sha256', strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code)), (string)JWT_SECRET);
}

function ctms_mfa_issue_recovery_codes(PDO $pdo, int $userId): array
{
    $pdo->prepare('DELETE FROM ctms_mfa_recovery_codes WHERE user_id=?')->execute([$userId]);
    $codes = [];
    $stmt = $pdo->prepare('INSERT INTO ctms_mfa_recovery_codes (user_id,code_hash,created_at) VALUES (?,?,NOW())');
    for ($i = 0; $i < 10; $i++) {
        $code = strtoupper(bin2hex(random_bytes(5)));
        $codes[] = substr($code, 0, 5) . '-' . substr($code, 5);
        $stmt->execute([$userId, ctms_mfa_recovery_hash($code)]);
    }
    return $codes;
}

function ctms_mfa_use_recovery_code(PDO $pdo, int $userId, string $code): bool
{
    $normalized = preg_replace('/[^A-Z0-9]/i', '', $code);
    if (!preg_match('/^[A-F0-9]{10}$/i', $normalized)) return false;
    $stmt = $pdo->prepare('UPDATE ctms_mfa_recovery_codes SET used_at=NOW() WHERE user_id=? AND code_hash=? AND used_at IS NULL');
    $stmt->execute([$userId, ctms_mfa_recovery_hash($normalized)]);
    return $stmt->rowCount() === 1;
}
?>
