<?php
// TIMEZONE (Phase-15 fix): PHP shipped with Europe/Berlin while MySQL
// runs UTC — a 3-hour skew that broke token-vs-DB timestamp
// comparisons and could shift any PHP-side date logic. The laboratory
// deployment time zone; every PHP clock function now agrees with MySQL.
//
// NOTE ON THE LONG TERM: pinning both clocks to local time is correct
// for a single-zone offline lab. If this system is ever migrated to a
// managed database (UTC by default) or serves users across timezones,
// the right model is: store UTC everywhere, convert to UTC
// only at render time. Both php.ini (date.timezone) and this pin must
// change together in that case.
$ctmsTimezone = getenv('CTMS_TIMEZONE') ?: 'UTC';
try {
    new DateTimeZone($ctmsTimezone);
} catch (Throwable $e) {
    $ctmsTimezone = 'UTC';
}
date_default_timezone_set($ctmsTimezone);

// ============================================================
// api/db.php — CTMS API Database Connection & Token Helpers
//
// This file is included by EVERY other API file.
// It handles:
//   1. CORS headers (allows React on localhost:5173 to call us)
//   2. MySQL connection via PDO
//   3. JWT token creation and verification
// ============================================================

// ── CORS Headers ─────────────────────────────────────────────
// We allow requests from the React dev server AND from the
// production build (same origin). The Vite proxy means in
// production these won't be needed, but they don't hurt.

$allowed_origins = [
    'http://localhost:5173',   // React dev server
    'http://localhost:5174',   // Vite sometimes uses this as fallback
    'http://localhost',        // XAMPP itself
    'http://127.0.0.1',
];

// SEC-013: only reflect the Origin when it is explicitly on the
// allow-list. Unknown or absent origins receive NO
// Access-Control-Allow-Origin header at all. (Previously we fell back
// to echoing the dev-server origin, which let unrelated sites read
// authenticated responses.) Same-origin production requests do not
// need the header.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $allowed_origins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Vary: Origin");
}

header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, Idempotency-Key");
header("Access-Control-Allow-Credentials: false");
header("Content-Type: application/json; charset=UTF-8");

// ── Security headers (SEC-017) ────────────────────────────────
// Applied to every API response. Pages that emit HTML
// (verify.php, report_generate.php) re-declare text/html themselves,
// so nosniff is safe for them too.
if (!headers_sent()) {
    // ZAP fix: single X-Frame-Options here — the .htaccess copy was removed
    // because double-setting produced a Medium alert.
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: no-referrer");
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    // ZAP fix: pragmatic CSP — everything self-hosted; inline styles/scripts
    // allowed (the report page and React build rely on them), no external
    // origins can ever inject resources.
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; font-src 'self'; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'");
}

// Handle browser preflight check (OPTIONS request)
// Browsers send this before every real API request to ask "is this allowed?"
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ── Database Connection ───────────────────────────────────────
// Credentials live in a private config file outside the web root. The file
// returns an array: db_host, db_name, db_user, db_pass, jwt_secret.
// The app runs on the limited 'ctms_user' account (ctms_db only),
// never on root.
$configFile = getenv('CTMS_CONFIG_PATH') ?: dirname(__DIR__, 3)
    . DIRECTORY_SEPARATOR . 'ctms_config' . DIRECTORY_SEPARATOR . 'ctms_config.php';
if (!is_readable($configFile)) {
    http_response_code(500);
    echo json_encode([
        "error" => "Server configuration missing. Contact the system administrator."
    ]);
    exit();
}
$ctmsConfig = require $configFile;
if (!is_array($ctmsConfig) || empty($ctmsConfig['db_user'])) {
    http_response_code(500);
    echo json_encode(["error" => "Server configuration invalid. Contact the system administrator."]);
    exit();
}

$host    = $ctmsConfig['db_host']   ?? '127.0.0.1';
$db      = $ctmsConfig['db_name']   ?? 'ctms_db';
$db_user = $ctmsConfig['db_user'];
$db_pass = $ctmsConfig['db_pass']   ?? '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (\PDOException $e) {
    // SEC-010: log the real reason privately; never disclose database
    // internals (host, user, schema, SQL text) to the client.
    error_log('[CTMS] DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "error" => "Database connection failed. Please contact the system administrator."
    ]);
    exit();
}

// ── JWT Token Helpers ─────────────────────────────────────────
// These are simple JWT-style tokens. Not full RFC 7519 but
// secure enough for a local/intranet system.

// The JWT secret comes from the external config file (loaded above).
// An environment variable may still override it for testing.
if (!defined('JWT_SECRET')) {
    $jwt_secret = trim((string)(getenv('JWT_SECRET') ?: ($ctmsConfig['jwt_secret'] ?? '')));
    if (!$jwt_secret) {
        try {
            // No configured secret: provision one stable random secret outside
            // the web root. Never fall back to a per-request or predictable key.
            $secret_file = 'C:/xampp/ctms_config/jwt_secret.txt';
            if (is_file($secret_file)) {
                if (!is_readable($secret_file)) {
                    throw new RuntimeException('JWT secret file is not readable.');
                }
                $jwt_secret = trim((string)file_get_contents($secret_file));
            } else {
                $new_secret = bin2hex(random_bytes(32));
                $handle = @fopen($secret_file, 'x');
                if ($handle === false) {
                    // Another request may have created it at the same time.
                    if (!is_file($secret_file) || !is_readable($secret_file)) {
                        throw new RuntimeException('JWT secret could not be created.');
                    }
                } else {
                    $written = fwrite($handle, $new_secret);
                    fflush($handle);
                    fclose($handle);
                    if ($written !== strlen($new_secret)) {
                        @unlink($secret_file);
                        throw new RuntimeException('JWT secret could not be saved completely.');
                    }
                }
                $jwt_secret = trim((string)file_get_contents($secret_file));
            }
            if ($jwt_secret === '') {
                throw new RuntimeException('JWT secret is empty.');
            }
        } catch (\Throwable $e) {
            error_log('[CTMS] JWT secret provisioning failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["error" => "Authentication configuration unavailable. Configure a persistent JWT_SECRET and try again."]);
            exit();
        }
    }
    define('JWT_SECRET', $jwt_secret);
}

function create_token(int $user_id, string $role): string {
    $header  = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $payload = base64url_encode(json_encode([
        'user_id' => $user_id,
        'role'    => $role,
        'exp'     => time() + (8 * 3600),  // 8-hour session
        // Password timestamps in the legacy schema have one-second
        // precision. A one-second offset lets the fresh post-rotation token
        // be distinguished from any token issued in the same database second.
        'iat'     => time() + 1,
    ]));
    $signature = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$signature";
}

function verify_token(string $token): array|false {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;

    [$header, $payload, $signature] = $parts;

    $expected_sig = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if (!hash_equals($expected_sig, $signature)) return false;

    $data = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $payload)), true);
    if (!$data || $data['exp'] < time()) return false;

    return $data;
}

function base64url_encode(string $data): string {
    return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
}

// ── Auth Guard Helper ─────────────────────────────────────────
// Call this at the top of any endpoint that needs a logged-in user.
// Returns the decoded token data (user_id, role, etc.)

function ctms_load_active_account(int $userId): array|false {
    global $pdo;
    $st = $pdo->prepare("SELECT id, role, profile_pic, lab_stream, is_active, is_deleted, must_change_password, pw_changed_at
                           FROM users WHERE id = ? LIMIT 1");
    $st->execute([$userId]);
    $account = $st->fetch(PDO::FETCH_ASSOC);
    if (!$account || (int)$account['is_active'] !== 1 || (int)$account['is_deleted'] === 1) return false;
    $account['user_id'] = (int)$account['id'];
    $account['role'] = (string)$account['role'];
    if ($account['role'] === 'chairman') $account['role'] = 'hod';
    if ($account['role'] === 'reception' && strtolower((string)($account['profile_pic'] ?? '')) === 'pa') {
        $account['role'] = 'pa';
    }
    return $account;
}

function require_auth(string $purpose = 'standard'): array {
    // apache_request_headers() unreliably exposes the Authorization
    // header on some XAMPP/Apache configurations — it can come back
    // empty even when the browser sent it correctly. We check every
    // possible source in order of reliability before giving up.
    $authHeader = '';

    // Source 1: $_SERVER superglobal (most reliable on Apache/XAMPP)
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    }
    // Source 2: Redirected header (some Apache/FastCGI/PHP-FPM setups)
    elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    // Source 3: getallheaders() (works on most Apache setups)
    elseif (function_exists('getallheaders')) {
        $h = getallheaders();
        $authHeader = $h['Authorization'] ?? $h['authorization'] ?? '';
    }
    // Source 4: apache_request_headers() (original method, last resort)
    elseif (function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        $authHeader = $h['Authorization'] ?? $h['authorization'] ?? '';
    }

    if (empty($authHeader) || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(["error" => "Unauthorized — no token provided"]);
        exit();
    }

    $tokenData = verify_token($matches[1]);
    if (!$tokenData) {
        http_response_code(401);
        echo json_encode(["error" => "Token is invalid or has expired. Please log in again."]);
        exit();
    }

    // Resolve account status and effective permissions on every request.
    // Token claims are not authoritative after an admin changes a role or
    // disables the account. The password-change route may bypass only the
    // must-change flag; it cannot bypass status or token revocation checks.
    try {
        $acct = ctms_load_active_account((int)($tokenData['user_id'] ?? 0));
        if (!$acct) {
            http_response_code(401);
            echo json_encode(["error" => "Account is inactive, deleted, or no longer exists. Please log in again."]);
            exit();
        }
        $tokenData['role'] = $acct['role'];
        $tokenData['user_id'] = $acct['user_id'];
        // Token revocation: tokens issued BEFORE the last credential
            // change are dead even if unexpired — a stolen pre-rotation
            // token cannot outlive the rotation (8 h → 0 h).
            //
        // pw_changed_at is MySQL NOW() in the pinned UTC zone.
        // Revoke tokens issued in or before that second. create_token()
        // gives newly-issued tokens the next-second iat, including the
        // response to a password change, so same-second old tokens cannot
        // slip through this comparison.
        if (!empty($acct['pw_changed_at']) && (int)($tokenData['iat'] ?? 0) <= strtotime((string)$acct['pw_changed_at'])) {
            http_response_code(401);
            echo json_encode(["error" => "Your session expired due to a password change. Please log in again."]);
            exit();
        }
        if ((int)$acct['must_change_password'] === 1 && $purpose !== 'password_change') {
            http_response_code(403);
            echo json_encode([
                "error" => "PASSWORD_CHANGE_REQUIRED",
                "message" => "Set a new password before continuing."
            ]);
            exit();
        }
    } catch (\Throwable $e) {
        error_log('[CTMS] account verification failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Session could not be verified. Please try again or log in again."]);
        exit();
    }

    return $tokenData;
}

// ============================================================
// Shared security helpers
// ============================================================

// Real client IP (used for logging + throttling).
function ctms_client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

// Role gate. Terminates the request with HTTP 403 when the token's
// role is not in the allow-list. Call this at the top of any
// state-changing endpoint.
function ctms_require_role(array $roles, array $tokenData, string $message = 'Permission denied.'): void {
    if (!in_array($tokenData['role'] ?? '', $roles, true)) {
        http_response_code(403);
        echo json_encode(['error' => $message]);
        exit();
    }
}

/** True when the account is explicitly scoped to a subset of lots. */
function ctms_user_lot_access_restricted(PDO $pdo, int $userId): bool {
    try {
        $st = $pdo->prepare("SELECT lot_access_restricted FROM users WHERE id = ? AND role <> 'superadmin'");
        $st->execute([$userId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        // Before the additive migration there is no lot scoping to enforce.
        return false;
    }
}

/** Existing role gates still apply; lot scope only narrows the records a role can reach. */
function ctms_user_has_lot_access(PDO $pdo, int $userId, int $lotId, string $minimum = 'view'): bool {
    if ($lotId <= 0) return false;
    try {
        $u = $pdo->prepare('SELECT role, lot_access_restricted FROM users WHERE id = ? AND is_active = 1 AND is_deleted = 0');
        $u->execute([$userId]);
        $user = $u->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Before the migration there are no restricted accounts to enforce.
        return true;
    }
    if (!$user) return false;
    if (($user['role'] ?? '') === 'superadmin' || !(int)$user['lot_access_restricted']) return true;
    $weights = ['view' => 1, 'edit' => 2, 'manage' => 3];
    $required = $weights[$minimum] ?? 1;
    try {
        $st = $pdo->prepare('SELECT access_level FROM user_lot_access WHERE user_id = ? AND lot_id = ?');
        $st->execute([$userId, $lotId]);
        $granted = $weights[(string)$st->fetchColumn()] ?? 0;
        return $granted >= $required;
    } catch (Throwable $e) {
        return false;
    }
}

function ctms_require_lot_access(PDO $pdo, int $userId, int $lotId, string $minimum = 'view'): void {
    if (!ctms_user_has_lot_access($pdo, $userId, $lotId, $minimum)) {
        http_response_code(403);
        echo json_encode(['error' => 'Your account is not assigned to this lot or lacks the required lot permission.']);
        exit;
    }
}

function ctms_require_sample_lot_access(PDO $pdo, int $userId, int $sampleId, string $minimum = 'view'): void {
    $st = $pdo->prepare('SELECT lot_id FROM samples WHERE id = ? AND is_deleted = 0');
    $st->execute([$sampleId]);
    $lotId = (int)$st->fetchColumn();
    if (!$lotId) {
        http_response_code(404);
        echo json_encode(['error' => 'Sample not found']);
        exit;
    }
    ctms_require_lot_access($pdo, $userId, $lotId, $minimum);
}

// SEC-010: log the real database error privately and return a safe,
// generic message for the client.
function ctms_db_error(\Throwable $e, string $public = 'An internal error occurred. Please contact the administrator.'): string {
    error_log('[CTMS] ' . $e->getMessage());
    return $public;
}

// SEC-007: neutralise spreadsheet formula triggers in exported cells.
// Values that a spreadsheet could evaluate as a formula are prefixed
// with an apostrophe, then XML-escaped for SpreadsheetML output.
function ctms_export_cell($s): string {
    $s = (string)$s;
    if ($s !== '' && preg_match('/^[=+\-@\t\r|%]/', $s)) {
        $s = "'" . $s;
    }
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

// SEC-006: lightweight DB-backed rate limiter. Returns true when the
// request is ALLOWED, false when the sliding window limit is exceeded.
// Fails open (returns true) if the limiter itself errors, so a broken
// limiter can never lock legitimate users out of the system.
function ctms_rate_limit(\PDO $pdo, string $key, int $max = 5, int $windowSeconds = 900): bool {
    static $ready = false;
    try {
        if (!$ready) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ctms_rate_limits (
                rl_key       VARCHAR(190) PRIMARY KEY,
                hits         INT NOT NULL DEFAULT 0,
                window_start DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $ready = true;
        }
        $pdo->prepare("DELETE FROM ctms_rate_limits WHERE window_start < (NOW() - INTERVAL ? SECOND)")
            ->execute([$windowSeconds * 2]);
        $pdo->prepare("INSERT INTO ctms_rate_limits (rl_key, hits, window_start) VALUES (?, 1, NOW())
                       ON DUPLICATE KEY UPDATE hits = hits + 1")
            ->execute([$key]);
        $st = $pdo->prepare("SELECT hits, window_start FROM ctms_rate_limits WHERE rl_key = ?");
        $st->execute([$key]);
        $row = $st->fetch();
        if (!$row) return true;
        if (time() - strtotime($row['window_start']) > $windowSeconds) {
            $pdo->prepare("UPDATE ctms_rate_limits SET hits = 1, window_start = NOW() WHERE rl_key = ?")
                ->execute([$key]);
            return true;
        }
        return ((int)$row['hits'] <= $max);
    } catch (\Throwable $e) {
        return true;
    }
}

// ── One-time download tickets (A-2 fix) ──────────────────────
// Replaces long-lived bearer tokens in download URLs. The
// authenticated frontend POSTs to a generator to receive a
// single-use ticket (valid 120 s, bound to one user + scope);
// the actual download URL carries only that ticket, so a token
// leaked into history/logs is worthless after one use.
function issue_download_ticket(\PDO $pdo, int $userId, string $scope, ?int $scopeId = null, int $ttlSeconds = 120): string {
    // Issuance itself is rate-limited (per user): tickets are useless
    // for brute-force (256-bit, single-use, 120 s) but the cap keeps
    // the table tidy and stops scripted spamming of the POST endpoint.
    if (!ctms_rate_limit($pdo, 'ticket:' . $userId, 60, 600)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many download requests. Wait a few minutes.']);
        exit();
    }
    $raw = bin2hex(random_bytes(32));   // 256-bit entropy, single-use, 120 s, DB-backed
    $ttlSeconds = max(30, min(600, $ttlSeconds));
    $st = $pdo->prepare("INSERT INTO download_tickets (ticket_hash, user_id, scope, scope_id, expires_at)
                         VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))");
    $st->execute([hash('sha256', $raw), $userId, $scope, $scopeId, $ttlSeconds]);
    // housekeeping: drop tickets older than an hour
    try { $pdo->exec("DELETE FROM download_tickets WHERE created_at < (NOW() - INTERVAL 1 HOUR)"); } catch (\Throwable $e) {}
    return $raw;
}

// Returns [ok => bool, user_id, scope, scope_id]; marks the ticket used
// atomically so a ticket can NEVER be replayed.
function validate_download_ticket(\PDO $pdo, string $raw, string $scope, bool $consume = true): array {
    if (!preg_match('/^[0-9a-f]{64}$/', $raw)) return ['ok' => false];
    $hash = hash('sha256', $raw);
    if ($consume) {
        $st = $pdo->prepare("UPDATE download_tickets
                             SET used_at = NOW()
                             WHERE ticket_hash = ? AND scope = ? AND used_at IS NULL
                               AND expires_at > NOW()");
        $st->execute([$hash, $scope]);
        if ($st->rowCount() === 0) return ['ok' => false];
        $row = $pdo->prepare("SELECT t.user_id, t.scope_id, u.role, u.profile_pic, u.must_change_password
                                FROM download_tickets t JOIN users u ON u.id = t.user_id
                               WHERE t.ticket_hash = ? AND u.is_active = 1 AND u.is_deleted = 0 AND u.must_change_password = 0");
        $row->execute([$hash]);
    } else {
        // IDM and other download managers issue HEAD and byte-range requests
        // before or during transfer. A short-lived archive ticket must survive
        // those metadata/resume requests while remaining bound to this archive.
        $row = $pdo->prepare("SELECT t.user_id, t.scope_id, u.role, u.profile_pic, u.must_change_password
                                FROM download_tickets t JOIN users u ON u.id = t.user_id
                               WHERE t.ticket_hash = ? AND t.scope = ? AND t.used_at IS NULL
                                 AND t.expires_at > NOW() AND u.is_active = 1
                                 AND u.is_deleted = 0 AND u.must_change_password = 0");
        $row->execute([$hash, $scope]);
    }
    $data = $row->fetch(PDO::FETCH_ASSOC);
    if (!$data) return ['ok' => false];
    $role = (string)$data['role'];
    if ($role === 'reception' && strtolower((string)($data['profile_pic'] ?? '')) === 'pa') $role = 'pa';
    return ['ok' => true, 'user_id' => (int)$data['user_id'], 'role' => $role,
            'scope_id' => $data['scope_id'] ? (int)$data['scope_id'] : null];
}

?>
