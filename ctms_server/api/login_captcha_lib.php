<?php
declare(strict_types=1);

/** Server-side, one-use CAPTCHA challenges for the CTMS login form. */
function ctms_login_captcha_secret(): string
{
    global $ctmsConfig;
    // db.php resolves the persistent secret from the external config,
    // environment, or its protected secret file before loading this helper.
    $secret = defined('JWT_SECRET') ? (string)JWT_SECRET : (string)($ctmsConfig['jwt_secret'] ?? '');
    if ($secret === '') throw new RuntimeException('The server authentication secret is unavailable.');
    return $secret;
}

function ctms_login_captcha_ip_hash(): string
{
    return hash_hmac('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), ctms_login_captcha_secret());
}

function ctms_login_captcha_ensure_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS ctms_login_captchas (
        challenge_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        answer_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_login_captcha_created (created_at),
        INDEX idx_login_captcha_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = true;
}

/** Create an image-only challenge; the answer is never sent to the browser. */
function ctms_create_login_captcha(PDO $pdo): array
{
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
        throw new RuntimeException('CAPTCHA image support is unavailable on the server.');
    }

    $secret = ctms_login_captcha_secret();
    $ipHash = ctms_login_captcha_ip_hash();
    if (!ctms_rate_limit($pdo, 'login_captcha_ip:' . $ipHash, 60, 900)) {
        throw new DomainException('Too many security checks. Please wait a few minutes and try again.');
    }
    ctms_login_captcha_ensure_table($pdo);

    // Avoid confusing pairs (0/O, 1/I/L) while keeping enough entropy.
    $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    $answer = '';
    for ($i = 0; $i < 6; $i++) $answer .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    $challengeId = bin2hex(random_bytes(32));

    $image = imagecreatetruecolor(220, 68);
    if (!$image) throw new RuntimeException('Could not create the CAPTCHA image.');
    imageantialias($image, true);
    $background = imagecolorallocate($image, 249, 247, 242);
    imagefill($image, 0, 0, $background);

    for ($i = 0; $i < 7; $i++) {
        $color = imagecolorallocate($image, random_int(145, 190), random_int(145, 190), random_int(145, 190));
        imageline($image, random_int(0, 220), random_int(0, 68), random_int(0, 220), random_int(0, 68), $color);
    }
    for ($i = 0; $i < 100; $i++) {
        $color = imagecolorallocate($image, random_int(150, 210), random_int(150, 210), random_int(150, 210));
        imagesetpixel($image, random_int(0, 219), random_int(0, 67), $color);
    }

    $fontCandidates = ['C:/Windows/Fonts/arialbd.ttf', 'C:/Windows/Fonts/arial.ttf'];
    $font = null;
    foreach ($fontCandidates as $candidate) if (is_file($candidate)) { $font = $candidate; break; }
    for ($i = 0; $i < 6; $i++) {
        $color = imagecolorallocate($image, random_int(22, 58), random_int(50, 92), random_int(72, 112));
        $x = 18 + ($i * 32) + random_int(-2, 2);
        if ($font && function_exists('imagettftext')) {
            imagettftext($image, random_int(23, 27), random_int(-13, 13), $x, random_int(47, 54), $color, $font, $answer[$i]);
        } else {
            imagestring($image, 5, $x, random_int(24, 32), $answer[$i], $color);
        }
    }

    ob_start();
    imagepng($image);
    $png = (string)ob_get_clean();
    imagedestroy($image);
    if ($png === '') throw new RuntimeException('Could not encode the CAPTCHA image.');

    // Keep the challenge short-lived and bound it to the client address.
    $pdo->prepare('DELETE FROM ctms_login_captchas WHERE created_at < (NOW() - INTERVAL 1 DAY)')->execute();
    $pdo->prepare('INSERT INTO ctms_login_captchas (challenge_hash, answer_hash, ip_hash, expires_at, created_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), NOW())')
        ->execute([
            hash('sha256', $challengeId),
            hash_hmac('sha256', $answer, $secret),
            $ipHash,
        ]);

    return ['challenge_id' => $challengeId, 'image' => 'data:image/png;base64,' . base64_encode($png)];
}

/** A challenge is consumed on its first verification, whether correct or not. */
function ctms_consume_login_captcha(PDO $pdo, string $challengeId, string $answer): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/i', $challengeId) || !preg_match('/^[A-Z2-9]{6}$/i', trim($answer))) return false;
    ctms_login_captcha_ensure_table($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT answer_hash, ip_hash, expires_at, used_at FROM ctms_login_captchas WHERE challenge_hash = ? FOR UPDATE');
        $stmt->execute([hash('sha256', strtolower($challengeId))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->commit();
            return false;
        }
        $valid = empty($row['used_at'])
            && strtotime((string)$row['expires_at']) > time()
            && hash_equals((string)$row['ip_hash'], ctms_login_captcha_ip_hash())
            && hash_equals((string)$row['answer_hash'], hash_hmac('sha256', strtoupper(trim($answer)), ctms_login_captcha_secret()));
        $pdo->prepare('UPDATE ctms_login_captchas SET used_at = NOW() WHERE challenge_hash = ? AND used_at IS NULL')->execute([hash('sha256', strtolower($challengeId))]);
        $pdo->commit();
        return $valid;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
