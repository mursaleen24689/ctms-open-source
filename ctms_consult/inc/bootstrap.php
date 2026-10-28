<?php
declare(strict_types=1);
// FIX: buffer all output for the whole request. Any accidental echo (a
// stray space after the PHP closing tag, a warning printed before a redirect, a debug
// line) used to send headers early, which silently broke session_start,
// logout and every header() call. With buffering on, headers can always
// be sent and the buffer flushes when the page completes.
ob_start();
/**
 * CTMS Consultancy Platform - application bootstrap
 *
 * Included by every page. Provides:
 *   - the database connection (this isolated database only)
 *   - session handling
 *   - authentication and role gates, enforced SERVER-SIDE on every request
 *   - CSRF protection
 *   - the immutable audit trail
 *   - small view helpers
 *
 * Design note: the role checks here are the real security boundary. Hiding a
 * button is presentation; require_role() is the control.
 */


// ── configuration (from outside the web root) ────────────────────────────────
$CONFIG_FILE = getenv('CTMS_CONSULT_CONFIG_PATH') ?: dirname(__DIR__, 3)
    . DIRECTORY_SEPARATOR . 'ctms_config' . DIRECTORY_SEPARATOR . 'ctms_consult_config.php';
if (!is_readable($CONFIG_FILE)) {
    http_response_code(500);
    exit('Configuration missing. Expected: ' . htmlspecialchars($CONFIG_FILE));
}
$CFG = require $CONFIG_FILE;

// ── error reporting ──────────────────────────────────────────────────────────
//
// Errors are ALWAYS written to a log file, and NEVER shown in the page.
//
// This is deliberate. A page that prints a PHP error also prints the full file
// path, the line number and often the SQL — which tells anyone on the network
// how the system is built. Your existing CTMS proved this can happen: a fatal
// error there displayed C:\xampp\htdocs\... to whoever triggered it.
//
// `debug` therefore does NOT mean "show errors". It means "keep a detailed log".
// While it is true the log records every notice, which is useful while building.
// Set it to false for everyday use and the log keeps only real errors.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
$LOG_DIR = 'C:/xampp/ctms_config/logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
if (is_dir($LOG_DIR) && is_writable($LOG_DIR)) {
    ini_set('error_log', $LOG_DIR . '/ctms_consult.log');
}
unset($LOG_DIR);

// A fatal error must never show a stack trace to the user either.
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e === null) return;
    if (!in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;

    error_log(sprintf('[FATAL] %s in %s on line %d', $e['message'], $e['file'], $e['line']));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    // Only continue if this is a normal page request, not a download or an API call
    $isHtml = !isset($_SERVER['HTTP_ACCEPT']) || str_contains((string)$_SERVER['HTTP_ACCEPT'], 'text/html');
    if (!$isHtml) return;

    echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title>'
       . '<div style="font:15px/1.6 system-ui,Segoe UI,Arial;max-width:640px;margin:60px auto;padding:28px;'
       . 'border:1px solid #ddd;border-left:5px solid #8C2F24;border-radius:8px">'
       . '<h2 style="margin:0 0 10px;color:#1B2A4A">Something went wrong</h2>'
       . '<p>The page could not be completed. <strong>No data has been changed.</strong></p>'
       . '<p>It has been written to the log. Please tell whoever maintains the system '
       . 'roughly when this happened, so it can be found quickly:</p>'
       . '<p style="font-family:monospace;background:#f6f4f1;padding:9px 12px;border-radius:5px">'
       . htmlspecialchars(date('Y-m-d H:i:s'), ENT_QUOTES) . '</p>'
       . '<p style="color:#6b6b6b;font-size:13px">Nothing technical is shown here on purpose, '
       . 'so that the details of the system are not visible to everyone on the network.</p>'
       . '</div>';
});

date_default_timezone_set('UTC');

// ── database ─────────────────────────────────────────────────────────────────
//
// Which database, and which account, is decided by Database routing
// (`db_mode` in the config). Nothing else in the application knows the
// difference, so the same code runs either separately or merged into ctms_db.
require_once __DIR__ . '/db_route.php';

$__db = db_target($CFG);
try {
    $pdo = new PDO($__db['dsn'], $__db['user'], $__db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    error_log('[CTMS-CONSULT] DB connect failed (' . $__db['label'] . '): ' . $e->getMessage());
    http_response_code(500);
    $__msg = db_connect_error_message($__db['mode'], $__db['name'], $__db['user'], $e);
    exit('<pre style="font:14px/1.6 Consolas,monospace;max-width:900px;margin:50px auto;padding:24px;'
        . 'border:1px solid #ddd;border-left:5px solid #8C2F24;border-radius:8px;white-space:pre-wrap">'
        . htmlspecialchars($__msg, ENT_QUOTES) . '</pre>');
}
unset($__db);

// ── session ──────────────────────────────────────────────────────────────────
if (session_status() !== PHP_SESSION_ACTIVE) {
    // Work out whether this request arrived over HTTPS, behind a proxy or not.
    $__https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443')
            || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

    session_set_cookie_params([
        'lifetime' => (int)$CFG['session_lifetime'],
        'path'     => '/',
        // Marked secure only when actually on HTTPS. Sending a secure-only
        // cookie over plain HTTP would stop anyone logging in, which is worse
        // than the risk it removes.
        'secure'   => $__https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('ctms_consult_sid');
    session_start();

    // If the site is configured to insist on HTTPS, move the browser across
    // before anything is read or written. Session cookies are only sent after
    // this, so the login never travels in the clear.
    if (!$__https
        && ($CFG['force_https'] ?? false)
        && PHP_SAPI !== 'cli'
        && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
        && !headers_sent()) {
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        // never trust a Host header to build a redirect to another site
        $host = preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $host);
        $uri  = (string)($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: https://' . $host . $uri, true, 301);
        exit;
    }
    unset($__https);
}

// Settings are read from the database once per request and cached.
// Call setting_flush() after writing one if it must be read again this request.
$__settings_cache = null;

// ── tiny helpers ─────────────────────────────────────────────────────────────
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_url(string $path = ''): string {
    global $CFG;
    return rtrim($CFG['app_url'], '/') . '/' . ltrim($path, '/');
}

/** Use an existing thumbnail when available, otherwise preview the original image. */
function report_asset_preview_url(array $asset): string {
    global $CFG;
    $stored = basename(str_replace('\\', '/', (string)($asset['stored_name'] ?? '')));
    if ($stored === '') return '';

    $uploadDir = rtrim((string)($CFG['upload_dir'] ?? ''), '/\\');
    $thumb = basename(str_replace('\\', '/', (string)($asset['thumb_name'] ?? '')));
    if ($uploadDir !== '' && $thumb !== '' && is_file($uploadDir . '/thumb/' . $thumb)) {
        return app_url('uploads/thumb/' . rawurlencode($thumb));
    }
    if ($uploadDir !== '' && is_file($uploadDir . '/original/' . $stored)) {
        return app_url('uploads/original/' . rawurlencode($stored));
    }
    return '';
}

function redirect(string $path): never {
    header('Location: ' . (str_starts_with($path, 'http') ? $path : app_url($path)));
    exit;
}

/**
 * Queue a message for the next page.
 * Accepts any scalar because callers naturally write flash('Saved ' . $n . ' rows'),
 * where $n may be an int. Coercing here keeps strict typing happy at every call
 * site without scattering (string) casts through the pages.
 */
function flash(string|int|float $msg, string $type = 'ok'): void {
    $_SESSION['flash'][] = ['msg' => (string)$msg, 'type' => $type];
}

function take_flash(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

/** Any POST must carry a valid token. Checked centrally, not per-form. */
function csrf_verify(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('Session expired or the form was stale. Please go back and try again.');
    }
}

function post(string $k, $default = null) {
    return $_POST[$k] ?? $default;
}

function get(string $k, $default = null) {
    return $_GET[$k] ?? $default;
}

function is_post(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/** Trimmed, empty-string-to-null helper for optional numeric input. */
function num_or_null($v): ?float {
    if ($v === null) return null;
    $v = trim((string)$v);
    if ($v === '') return null;
    if (!is_numeric($v)) return null;
    return (float)$v;
}

// ── persistent rate limiting (database-backed) ──────────────────────────────
function rate_limit(string $key, int $max = 5, int $windowSeconds = 900): bool {
    global $pdo;
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
        if (time() - strtotime((string)$row['window_start']) > $windowSeconds) {
            $pdo->prepare("UPDATE ctms_rate_limits SET hits = 1, window_start = NOW() WHERE rl_key = ?")
                ->execute([$key]);
            return true;
        }
        return ((int)$row['hits'] <= $max);
    } catch (\Throwable $e) {
        return true;
    }
}

function rate_limit_reset(string $key): void {
    global $pdo;
    try {
        $pdo->prepare("DELETE FROM ctms_rate_limits WHERE rl_key = ?")->execute([$key]);
    } catch (\Throwable $e) {}
}

// ── authentication ───────────────────────────────────────────────────────────
function current_user(): ?array {
    static $cached = null;
    if ($cached !== null) return $cached;
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) return null;

    $st = $GLOBALS['pdo']->prepare(
        'SELECT id, employee_id, full_name, role, lab_stream, designation, report_mode,
                is_active, is_deleted, must_change_password, last_login, ctms_user_id
           FROM c_users WHERE id = ? LIMIT 1');
    $st->execute([(int)$uid]);
    $u = $st->fetch();

    // An account that has been disabled or removed loses access immediately,
    // on the very next request, without waiting for the session to expire.
    if (!$u || (int)$u['is_deleted'] === 1 || (int)$u['is_active'] !== 1) {
        session_unset();
        session_destroy();
        return null;
    }

    // Main CTMS-linked accounts use the main user record as the authority for
    // Consultancy permission and role. Re-check on each request so an admin's
    // access change takes effect for an already-open Consultancy session too.
    if (!empty($u['ctms_user_id'])) {
        try {
            $main = $GLOBALS['pdo']->prepare('SELECT is_active, is_deleted, role, consultancy_access, consultancy_role,
                                                     consultancy_can_create_invoice
                                                FROM users WHERE id = ? LIMIT 1');
            $main->execute([(int)$u['ctms_user_id']]);
            $access = $main->fetch();
        } catch (Throwable $e) {
            $access = false;
        }
        if (!$access || (int)$access['is_active'] !== 1 || (int)$access['is_deleted'] === 1
            || (int)$access['consultancy_access'] !== 1) {
            session_unset();
            session_destroy();
            return null;
        }
        $mainRole = (string)($access['role'] ?? '');
        $allowedRoles = ['superadmin', 'admin', 'professor', 'technician', 'reception', 'viewer'];
        if (in_array($mainRole, ['superadmin', 'admin'], true)) {
            // Main CTMS administrators retain full Consultancy access by default.
            $u['role'] = $mainRole;
        } elseif (in_array((string)$access['consultancy_role'], $allowedRoles, true)) {
            $u['role'] = (string)$access['consultancy_role'];
        }
        $u['consultancy_can_create_invoice'] = (int)$access['consultancy_can_create_invoice'];
    }
    return $cached = $u;
}

/**
 * Require a signed-in user, optionally restricted to certain roles.
 * Terminates the request when the check fails.
 */
function require_login(array $roles = []): array {
    $u = current_user();
    if (!$u) redirect('login.php');
    if ($roles && !in_array($u['role'], $roles, true)) {
        http_response_code(403);
        include __DIR__ . '/layout_top.php';
        echo '<div class="card"><h2>Not permitted</h2>'
           . '<p>Your role <strong>' . h($u['role']) . '</strong> does not have access to this page.</p>'
           . '<p><a class="btn" href="' . h(app_url('index.php')) . '">Back to dashboard</a></p></div>';
        include __DIR__ . '/layout_bottom.php';
        exit;
    }
    return $u;
}

/** Convenience: is the current user allowed to do X? Used only for display. */
function can_user(string ...$roles): bool {
    $u = current_user();
    return $u && in_array($u['role'], $roles, true);
}

/**
 * Invoice authoring is a named assignment, not a role.
 *
 * The Super Administrator always keeps it, so it can be handed to someone
 * covering for the PA, and taken back afterwards — the switch lives on the
 * main CTMS Users screen. Every other account needs the explicit flag; a
 * Consultancy administrator can run the whole workflow without being able to
 * raise invoices.
 */
function can_create_consult_invoice(): bool {
    $u = current_user();
    if (!$u) return false;
    if ((string)$u['role'] === 'superadmin') return true;
    return array_key_exists('consultancy_can_create_invoice', $u)
        && (int)$u['consultancy_can_create_invoice'] === 1;
}

function login_user(array $u): void {
    session_regenerate_id(true);            // prevent session fixation
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['login_at'] = time();
}

function logout_user(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
}

// ── working mode: Guided or Direct ───────────────────────────────────────────
/**
 * The laboratory runs two working modes.
 *
 *   GUIDED  Values are checked as the technician types them: implausible
 *           readings, area/dimension mismatches, means that do not add up, and
 *           a suggested standard to confirm. A warning can always be passed on,
 *           but the reason is recorded.
 *
 *   DIRECT  The technician decides. No warnings, no suggestions, no standard to
 *           confirm. The same checks still RUN and their outcome is written to
 *           the audit trail, but nothing is put in the way. The printed report,
 *           the barcode and every stored value are identical in both modes.
 *
 * The choice is remembered per person so nobody makes it twice, and can be
 * toggled from the header for a single session.
 */
function mode_valid(string $m): string {
    return $m === 'direct' ? 'direct' : 'guided';
}

function current_mode(): string {
    // 1. an explicit choice made this session
    if (isset($_SESSION['mode']) && is_string($_SESSION['mode']) && $_SESSION['mode'] !== '') {
        return mode_valid($_SESSION['mode']);
    }
    // 2. the person's own saved preference
    $u = current_user();
    if ($u && !empty($u['report_mode'])) {
        return mode_valid((string)$u['report_mode']);
    }
    // 3. the laboratory default — guided, so a new account is checked
    return mode_valid((string)setting('default_mode', 'guided'));
}

function in_guided_mode(): bool { return current_mode() === 'guided'; }
function in_direct_mode(): bool { return current_mode() === 'direct'; }

/** Switch mode for this session, and remember the choice on the account. */
function set_mode(string $mode, bool $remember = true): void {
    $mode = mode_valid($mode);
    $_SESSION['mode'] = $mode;
    $u = current_user();
    if ($remember && $u) {
        $GLOBALS['pdo']->prepare('UPDATE c_users SET report_mode = ? WHERE id = ?')
            ->execute([$mode, (int)$u['id']]);
    }
}

function mode_label(?string $m = null): string {
    return mode_valid($m ?? current_mode()) === 'guided' ? 'Guided' : 'Direct';
}

// ── audit trail (write-only; never updated, never deleted) ───────────────────
/**
 * Record something that happened. Called for every create, edit, delete and
 * override. The actor's name and role are snapshotted so the trail stays
 * readable even if the account is later renamed or removed.
 */
function audit(string $action, string $module, ?int $recordId = null,
               $oldValue = null, $newValue = null, ?string $reason = null,
               ?int $parentId = null): void {
    $u = current_user();
    try {
        $st = $GLOBALS['pdo']->prepare(
            'INSERT INTO c_audit
               (user_id, actor_name, actor_role, action, module, record_id, parent_id,
                old_value, new_value, reason, ip_address, user_agent)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([
            $u['id']        ?? null,
            $u['full_name'] ?? 'system',
            $u['role']      ?? 'system',
            $action,
            $module,
            $recordId,
            $parentId,
            $oldValue === null ? null : (is_string($oldValue) ? $oldValue : json_encode($oldValue, JSON_UNESCAPED_UNICODE)),
            $newValue === null ? null : (is_string($newValue) ? $newValue : json_encode($newValue, JSON_UNESCAPED_UNICODE)),
            $reason,
            $_SERVER['REMOTE_ADDR']    ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300),
        ]);
    } catch (Throwable $e) {
        // An audit failure must never destroy the user's work, but it must be
        // loud in the log so it can be found and fixed.
        error_log('[CTMS-CONSULT] AUDIT WRITE FAILED: ' . $e->getMessage());
    }
}

// ── atomic document numbering ────────────────────────────────────────────────
/**
 * Returns the next number in a series and advances it atomically, so two
 * people generating a document at the same moment can never collide.
 */
function next_number(string $key): int {
    $pdo = $GLOBALS['pdo'];
    $pdo->prepare('INSERT IGNORE INTO c_counters (counter_key, next_number) VALUES (?, 1)')
        ->execute([$key]);
    $pdo->prepare('UPDATE c_counters SET next_number = LAST_INSERT_ID(next_number) + 1 WHERE counter_key = ?')
        ->execute([$key]);
    return (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
}

/**
 * Read a setting.
 *
 * The whole table is read once per request, which is right for values that do
 * not change mid-request. After writing a setting, call setting_flush() so the
 * next read reflects what was just saved rather than the value from page load.
 */
function setting(string $key, $default = null) {
    global $__settings_cache;
    if ($__settings_cache === null) {
        $__settings_cache = [];
        foreach ($GLOBALS['pdo']->query('SELECT setting_key, setting_value FROM c_settings') as $r) {
            $__settings_cache[$r['setting_key']] = $r['setting_value'];
        }
    }
    return $__settings_cache[$key] ?? $default;
}

/** Forget the cached settings so the next setting() call re-reads them. */
function setting_flush(): void {
    global $__settings_cache;
    $__settings_cache = null;
}

/**
 * Read a setting straight from the database, ignoring the cache.
 * Used where a value is written and read back within one request.
 */
function setting_fresh(string $key, $default = null) {
    $st = $GLOBALS['pdo']->prepare('SELECT setting_value FROM c_settings WHERE setting_key = ? LIMIT 1');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return ($v === false || $v === null) ? $default : $v;
}

function setting_set(string $key, string|int|float $value): void {
    $GLOBALS['pdo']->prepare(
        'INSERT INTO c_settings (setting_key, setting_value, updated_by, updated_at)
         VALUES (?,?,?,NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()')
        ->execute([$key, (string)$value, current_user()['id'] ?? null]);
    // Keep the cache honest: the page may read this value again before it ends.
    setting_flush();
}

/** Current time as MySQL DATETIME, in the pinned timezone. */
function now(): string {
    return date('Y-m-d H:i:s');
}

function today(): string {
    return date('Y-m-d');
}

/** "12.5" / "" / null -> a decimal or null, without turning blanks into 0. */
function dec_or_null(mixed $v): ?string {
    if ($v === null) return null;
    if (is_array($v) || is_object($v)) return null;
    $v = trim((string)$v);
    return ($v === '' || !is_numeric($v)) ? null : $v;
}

function fmt(mixed $n, int $dp = 2): string {
    return $n === null || $n === '' ? '-' : number_format((float)$n, $dp);
}

function fmt_date(?string $d): string {
    if (!$d) return '-';
    $t = strtotime($d);
    return $t ? date('d M Y', $t) : h($d);
}

/**
 * A small coloured label.
 * Accepts scalars so callers can pass a count directly, e.g. badge($n . ' warnings').
 */
function badge(string|int|float $text, string $tone = 'grey'): string {
    return '<span class="badge badge-' . h($tone) . '">' . h($text) . '</span>';
}

/**
 * Human label + colour for each workflow status.
 *
 * Accepts null deliberately. A missing status column in a query is a coding
 * mistake, but it must degrade to a visible "unknown" badge rather than take
 * the whole page down with a fatal error while someone is mid-task.
 */
function status_badge(?string $s): string {
    if ($s === null || $s === '') {
        return badge('unknown', 'grey');
    }
    $map = [
        'draft'            => ['Draft', 'grey'],
        'issued'           => ['Issued', 'blue'],
        'partial'          => ['Part paid', 'amber'],
        'paid'             => ['Paid', 'green'],
        'cancelled'        => ['Cancelled', 'red'],
        'received'         => ['Received', 'blue'],
        'in_testing'       => ['In testing', 'amber'],
        'awaiting_report'  => ['Awaiting report', 'amber'],
        'reported'         => ['Reported', 'green'],
        'dispatched'       => ['Dispatched', 'green'],
        'on_hold'          => ['On hold', 'red'],
        'pending'          => ['Pending', 'grey'],
        'in_progress'      => ['In progress', 'amber'],
        'entered'          => ['Entered', 'blue'],
        'checked'          => ['Checked', 'blue'],
        'approved'         => ['Approved', 'green'],
        'in_review'        => ['In review', 'amber'],
        'superseded'       => ['Superseded', 'grey'],
    ];
    [$label, $tone] = $map[$s] ?? [ucfirst(str_replace('_', ' ', $s)), 'grey'];
    return badge($label, $tone);
}

// Every POST in this application is CSRF-checked centrally.
csrf_verify();
