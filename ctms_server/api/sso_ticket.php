<?php
// ============================================================
// api/sso_ticket.php — opens the Consultancy platform as the
// signed-in CTMS user, without asking for a password again.
//
// POST /auth-style request with the usual Bearer token.
// Returns: { success, url }  — a single-use link, valid 60 seconds.
//
// Why a ticket instead of passing the session token:
//   The session token is an 8-hour credential. Putting it in a URL means it
//   sits in browser history, in the address bar, and can leak as a Referer to
//   any site opened from that page. A single-use 60-second ticket gives the
//   same convenience with almost none of that exposure.
// ============================================================
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$tokenData = require_auth();
$userId = (int)($tokenData['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['error' => 'Not signed in']);
    exit();
}

// the account must still be usable right now
$st = $pdo->prepare("SELECT id, employee_id, full_name, role, is_active, is_deleted, consultancy_access
                       FROM users WHERE id = ? LIMIT 1");
$st->execute([$userId]);
$u = $st->fetch();
if (!$u || (int)$u['is_deleted'] === 1 || (int)$u['is_active'] !== 1 || (int)$u['consultancy_access'] !== 1) {
    http_response_code(403);
    echo json_encode(['error' => 'This account cannot open the consultancy platform.']);
    exit();
}

// optional: the working mode the user prefers, if the frontend sends one
$body = json_decode(file_get_contents('php://input'), true);
$mode = in_array(($body['mode'] ?? ''), ['guided', 'direct'], true) ? $body['mode'] : null;
$returnTo = trim((string)($body['return_to'] ?? ''));

try {
    // Housekeeping. Expired and used tickets have no value, so they are cleared
    // as we go rather than left to accumulate.
    $pdo->exec("DELETE FROM c_sso_tickets WHERE expires_at < NOW() - INTERVAL 1 DAY");

    $ticket = bin2hex(random_bytes(32));          // 64 hex characters
    $pdo->prepare("INSERT INTO c_sso_tickets (ticket_hash, ctms_user_id, mode, expires_at)
                   VALUES (?, ?, ?, NOW() + INTERVAL 60 SECOND)")
        ->execute([hash('sha256', $ticket), $userId, $mode]);

    /*
     * The link MUST be absolute, with its own host and scheme.
     *
     * A root-relative link such as "/ctms_consult/sso.php?t=…" looks harmless
     * but is wrong: this API is reached through the development server's proxy
     * (http://localhost:5173/ctms_api/…), so the browser resolves a relative
     * link against localhost:5173 — which has no /ctms_consult and answers with
     * the CTMS application instead. The tab then appears to open and vanish.
     *
     * The Consultancy platform runs on its own host over HTTPS, so the link is
     * built against the address this request arrived on.
     */
    $cfgFile = getenv('CTMS_CONSULT_CONFIG_PATH') ?: dirname(__DIR__, 3)
        . DIRECTORY_SEPARATOR . 'ctms_config' . DIRECTORY_SEPARATOR . 'ctms_consult_config.php';
    $projectPath = rtrim((string)(getenv('CTMS_PROJECT_PATH') ?: '/ctms'), '/');
    $consBase = $projectPath . '/ctms_consult';
    if (is_readable($cfgFile)) {
        $c = require $cfgFile;
        if (is_array($c) && !empty($c['app_url'])) $consBase = '/' . trim((string)$c['app_url'], '/');
    }
    $override = rtrim((string)getenv('CONSULTANCY_URL'), '/');
    if ($override !== '') {
        // explicit override wins and is used as given
        $link = $override . '/sso.php?t=' . $ticket;
    } else {
        $scheme = 'https';
        $rawHost = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        if (str_contains($rawHost, ':')) {
            $rawHost = explode(':', $rawHost)[0];        // drop the dev server's port
        }
        $rawHost = preg_replace('/[^A-Za-z0-9\.\-\[\]]/', '', $rawHost);
        if ($rawHost === '') $rawHost = 'localhost';

        // Validate against trusted server hosts to prevent host-header poisoning
        $trustedHosts = ['localhost', '127.0.0.1', '::1', 'ctms-server', 'ctms.local'];
        // On an intranet the same Apache installation may be reached by a
        // different bound interface (for example eduroam vs Ethernet). Trust
        // only the address Apache says accepted THIS request; never trust an
        // arbitrary Host header supplied by a client.
        $boundAddress = trim((string)($_SERVER['SERVER_ADDR'] ?? ''));
        if (filter_var($boundAddress, FILTER_VALIDATE_IP)) $trustedHosts[] = $boundAddress;
        if (!empty($ctmsConfig['allowed_hosts']) && is_array($ctmsConfig['allowed_hosts'])) {
            $trustedHosts = array_merge($trustedHosts, $ctmsConfig['allowed_hosts']);
        }
        $serverName = preg_replace('/[^A-Za-z0-9\.\-\[\]]/', '', (string)($_SERVER['SERVER_NAME'] ?? ''));
        if ($serverName !== '') $trustedHosts[] = $serverName;

        $host = in_array(strtolower($rawHost), array_map('strtolower', $trustedHosts), true)
            ? $rawHost
            : ($serverName ?: 'localhost');

        $link = $scheme . '://' . $host . $consBase . '/sso.php?t=' . $ticket;
    }

    // This is only a navigation hint, never an auth credential. The
    // Consultancy app independently validates it against trusted local hosts
    // before remembering it for the logout redirect.
    if ($returnTo !== '') $link .= '&return_to=' . rawurlencode($returnTo);

    echo json_encode([
        'success' => true,
        'url'     => $link,
        'expires_in' => 60,
    ]);
} catch (\Throwable $e) {
    error_log('[CTMS] sso_ticket failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not create the sign-in link. Please try again.']);
}
