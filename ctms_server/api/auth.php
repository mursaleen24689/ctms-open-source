<?php
// ============================================================
// api/auth.php — CTMS Authentication
//
// POST /auth.php
//   Body: { "employee_id": "user@example.edu" or "user",
//            "password": "the user's password" }
//
// Returns: { success, token, user_id, employee_id, full_name,
//             role, lab_stream }
//
// lab_stream is read directly from the dedicated `lab_stream`
// column (see add_lab_stream_column.sql migration).
// Token valid for 8 hours (full work shift).
// ============================================================
require_once 'db.php';
require_once __DIR__ . '/login_captcha_lib.php';
require_once __DIR__ . '/mfa_lib.php';

// SEC-009: record authentication security events (success/failure).
// Runs with user_id = NULL because the account may not exist.
function log_auth(PDO $pdo, string $action, string $employee_id): void {
    try {
        $pdo->prepare("INSERT INTO audit_log (user_id, action, module, new_value, ip_address)
                       VALUES (NULL, ?, 'auth', ?, ?)")
            ->execute([
                $action,
                json_encode(['employee_id' => $employee_id]),
                $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
    } catch (\Exception $e) { /* logging must never block the auth flow */ }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

// ── Self-service credential rotation (A-4 fix) ────────────────
// action=change_password: any authenticated user changes their OWN
// password after re-entering the current one. Clears the rotation
// flag. Available to every role — deliberately NOT in users.php,
// which is admin-gated.
if (($data['action'] ?? '') === 'change_password') {
    // This named purpose bypasses only the forced-rotation flag — require_auth's own
    // rotation lockout must not apply here.
    $tokenData = require_auth('password_change');
    $current = (string)($data['current_password'] ?? '');
    $new     = (string)($data['new_password'] ?? '');
    if (strlen($new) < 8 || strlen($new) > 72) {
        http_response_code(400); echo json_encode(["error" => "New password must be between 8 and 72 bytes."]); exit();
    }
    $st = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? AND is_deleted = 0 AND is_active = 1");
    $st->execute([(int)$tokenData['user_id']]);
    $row = $st->fetch();
    if (!$row || !password_verify($current, $row['password_hash'])) {
        log_auth($pdo, 'PASSWORD_CHANGE_FAILED', (string)$tokenData['user_id']);
        http_response_code(401); echo json_encode(["error" => "Current password is incorrect."]); exit();
    }
    $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
    // pw_changed_at revokes every token issued before this moment
    $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0, pw_changed_at = NOW(), updated_at = NOW() WHERE id = ?")
        ->execute([$hash, (int)$tokenData['user_id']]);
    log_auth($pdo, 'PASSWORD_CHANGED_SELF', (string)$tokenData['user_id']);
    // Issue a FRESH token: the old one is now revoked by pw_changed_at,
    // so the client must continue its session with this new one.
    $fresh = create_token((int)$tokenData['user_id'], (string)($tokenData['role'] ?? 'deo'));
    echo json_encode(["success" => true, "message" => "Password updated.", "token" => $fresh]);
    exit();
}

$employee_id = trim($data['employee_id'] ?? $data['email'] ?? $data['username'] ?? '');
$password    = $data['password'] ?? '';

if (empty($employee_id) || empty($password)) {
    http_response_code(400);
    echo json_encode(["error" => "Email address and password are required"]);
    exit();
}

// Every password-authentication attempt must solve a fresh, short-lived,
// single-use challenge. Failed CAPTCHA attempts do not reach password_verify.
if (!ctms_consume_login_captcha(
    $pdo,
    (string)($data['captcha_id'] ?? ''),
    (string)($data['captcha_answer'] ?? '')
)) {
    log_auth($pdo, 'FAILED_CAPTCHA', $employee_id);
    try { ctms_auth_event($pdo, 'FAILED_CAPTCHA', $employee_id); } catch (Throwable $e) { }
    http_response_code(400);
    echo json_encode(["error" => "Security check incorrect or expired. A new CAPTCHA has been loaded; please try again."]);
    exit();
}

// Allow a quick login with the local part of a work email (for example,
// "ctms.admin" for "ctms.admin@Example University.edu.pk"). Resolve it to the
// canonical stored email before throttling and auditing. If multiple non-deleted
// accounts share a local part, no alias is selected; the full email is needed.
if (strpos($employee_id, '@') === false) {
    try {
        $aliasStmt = $pdo->prepare("SELECT employee_id FROM users
            WHERE LOWER(SUBSTRING_INDEX(employee_id, '@', 1)) = LOWER(?) AND is_deleted = 0
            ORDER BY id LIMIT 2");
        $aliasStmt->execute([$employee_id]);
        $matches = $aliasStmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($matches) === 1) $employee_id = (string)$matches[0];
    } catch (Throwable $e) {
        error_log('[CTMS auth] username alias lookup failed: ' . $e->getMessage());
        http_response_code(503);
        echo json_encode(["error" => "Sign-in is temporarily unavailable. Please try again shortly."]);
        exit();
    }
} else {
    // Resolve casing to the canonical value as well, so full-email and short
    // name attempts share the same per-account cooldown and rate-limit key.
    try {
        $emailStmt = $pdo->prepare('SELECT employee_id FROM users WHERE LOWER(employee_id)=LOWER(?) ORDER BY id LIMIT 1');
        $emailStmt->execute([$employee_id]);
        $canonicalEmail = $emailStmt->fetchColumn();
        if ($canonicalEmail !== false) $employee_id = (string)$canonicalEmail;
    } catch (Throwable $e) {
        error_log('[CTMS auth] login identifier lookup failed: ' . $e->getMessage());
        http_response_code(503);
        echo json_encode(["error" => "Sign-in is temporarily unavailable. Please try again shortly."]);
        exit();
    }
}

// SEC-006: throttle login attempts per IP and per account to blunt
// brute-force / credential-stuffing attacks. The IP window is a hard
// backstop; the account window complements the escalating cooldown below.
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!ctms_rate_limit($pdo, 'login_ip:' . $ip, 60, 900)
    || !ctms_rate_limit($pdo, 'login_user:' . strtolower($employee_id), 30, 3600)) {
    try { ctms_auth_event($pdo, 'LOGIN_RATE_LIMITED', $employee_id); } catch (Throwable $e) { }
    http_response_code(429);
    echo json_encode(["error" => "Too many login attempts. Please wait a few minutes and try again."]);
    exit();
}

// Bounded account-level escalating delays. The maximum is 15 minutes and
// decays after one hour; this slows guessing without permanently locking users.
try {
    $cooldown = ctms_auth_cooldown($pdo, $employee_id);
    if ($cooldown > 0) {
        header('Retry-After: ' . $cooldown);
        http_response_code(429);
        echo json_encode(["error" => "Please wait {$cooldown} seconds before trying again.", "retry_after" => $cooldown]);
        exit();
    }
} catch (Throwable $e) {
    error_log('[CTMS auth] cooldown check failed: ' . $e->getMessage());
    http_response_code(503); echo json_encode(["error" => "Sign-in protection is temporarily unavailable. Please try again shortly."]); exit();
}

// Fetch user — employee_id is canonicalized to its stored work email above.
// lab_stream read directly from its own column (not overloaded profile_pic)
try {
    $stmt = $pdo->prepare("
        SELECT id, employee_id, full_name, password_hash, role, is_active, is_deleted,
               consultancy_access, can_technical_review,
               lab_stream, profile_pic, must_change_password
        FROM users WHERE employee_id = ? LIMIT 1
    ");
    $stmt->execute([$employee_id]);
    $user = $stmt->fetch();
} catch (PDOException $migrationPending) {
    // Keep sign-in available during deployment until the additive approval
    // schema is applied; no user receives reviewer permission in this mode.
    $stmt = $pdo->prepare("SELECT id, employee_id, full_name, password_hash, role, is_active, is_deleted,
                                  consultancy_access, lab_stream, profile_pic, must_change_password
                           FROM users WHERE employee_id = ? LIMIT 1");
    $stmt->execute([$employee_id]);
    $user = $stmt->fetch();
    if ($user) $user['can_technical_review'] = 0;
}

if (!$user || !empty($user['is_deleted'])) {
    log_auth($pdo, 'FAILED_LOGIN', $employee_id);
    try { ctms_auth_failed($pdo, $employee_id, 'FAILED_LOGIN'); } catch (Throwable $e) { }
    http_response_code(401);
    echo json_encode(["error" => "Invalid username/email or password"]);
    exit();
}

if (empty($user['is_active'])) {
    log_auth($pdo, 'FAILED_LOGIN_INACTIVE', $employee_id);
    try { ctms_auth_failed($pdo, $employee_id, 'FAILED_LOGIN'); } catch (Throwable $e) { }
    http_response_code(403);
    echo json_encode(["error" => "Your account has been deactivated. Contact the System Administrator."]);
    exit();
}

if (!password_verify($password, $user['password_hash'])) {
    log_auth($pdo, 'FAILED_LOGIN', $employee_id);
    try { ctms_auth_failed($pdo, $employee_id, 'FAILED_LOGIN', (int)$user['id']); } catch (Throwable $e) { }
    http_response_code(401);
    echo json_encode(["error" => "Invalid username/email or password."]);
    exit();
}

// Passwords alone never create a CTMS session. Existing users enroll on their
// next sign-in; enrolled users must verify a current TOTP/recovery code.
try {
    ctms_mfa_ensure_schema($pdo);
    $mfaStmt = $pdo->prepare('SELECT enabled_at FROM ctms_user_mfa WHERE user_id=?');
    $mfaStmt->execute([(int)$user['id']]);
    $mfaEnabled = (bool)$mfaStmt->fetchColumn();
    $purpose = $mfaEnabled ? 'login' : 'setup';
    $ticket = ctms_mfa_new_ticket($pdo, (int)$user['id'], $purpose);
    echo json_encode([
        'success' => false,
        'mfa_required' => $mfaEnabled,
        'mfa_setup_required' => !$mfaEnabled,
        'login_ticket' => $ticket,
        'full_name' => $user['full_name'],
        'message' => $mfaEnabled ? 'Enter the current code from your authenticator app.' : 'Set up an offline authenticator to finish signing in.'
    ]);
    exit();
} catch (Throwable $e) {
    error_log('[CTMS auth] MFA challenge setup failed: ' . $e->getMessage());
    http_response_code(503); echo json_encode(["error" => "MFA sign-in could not be prepared. Please contact the CTMS administrator."]); exit();
}

// ── Lab stream — read from dedicated column ───────────────────
// Falls back to legacy profile_pic mapping if lab_stream is empty
// (covers the case where the migration hasn't been run yet)
$lab_stream = $user['lab_stream'] ?? '';
if (empty($lab_stream)) {
    $legacyMap = [
        'tensile'     => 'Tensile Lab (Zeeshan Hassan)',
        'xrf'         => 'XRF Lab (Khurram Jahangeer)',
        'oes'         => 'OES Lab (Jahangeer)',
        'electronics' => 'Electronics Lab (Lab Technician)',
    ];
    $lab_stream = $legacyMap[$user['profile_pic'] ?? ''] ?? '';
}

// ── Resolve display role ──────────────────────────────────────
// 'reception' and 'mapping' are separate roles (Reception Officer
// handles Phase 1 intake; Mapping Officer handles Phase 2 routing).
// Legacy accounts that predate the split may still flag a PA
// sub-type via profile_pic.
$display_role = $user['role'];
if ($user['role'] === 'reception' && ($user['profile_pic'] ?? '') === 'pa') {
    $display_role = 'pa';
}

// Update last login
$pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
    ->execute([$user['id']]);

// Create 8-hour token
$token = create_token($user['id'], $display_role);

// Audit log
try {
    $pdo->prepare("
        INSERT INTO audit_log (user_id, action, module, ip_address)
        VALUES (?, 'LOGIN_SUCCESS', 'auth', ?)
    ")->execute([$user['id'], $_SERVER['REMOTE_ADDR'] ?? '']);
} catch (\Exception $e) { /* don't block login */ }

echo json_encode([
    "success"     => true,
    "token"       => $token,
    "user_id"     => $user['id'],
    "employee_id" => $user['employee_id'],
    "full_name"   => $user['full_name'],
    "role"        => $display_role,
    "lab_stream"  => $lab_stream,
    "consultancy_access" => (bool)($user['consultancy_access'] ?? 0),
    "can_technical_review" => (bool)($user['can_technical_review'] ?? 0),
    // A-4: when set, the frontend must force a password change before use
    "must_change_password" => (bool)($user['must_change_password'] ?? 0),
]);
?>
