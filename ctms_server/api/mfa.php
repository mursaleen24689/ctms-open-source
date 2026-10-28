<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mfa_lib.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($data['action'] ?? $_GET['action'] ?? '');
$loginActions = ['setup_init', 'setup_confirm', 'login_verify'];
$tokenData = in_array($action, $loginActions, true) ? null : require_auth();
ctms_mfa_ensure_schema($pdo);

function ctms_mfa_login_response(PDO $pdo, int $userId, array $recoveryCodes = [])
{
    $stmt = $pdo->prepare('SELECT id,employee_id,full_name,role,lab_stream,profile_pic,consultancy_access,can_technical_review,must_change_password FROM users WHERE id=? AND is_active=1 AND is_deleted=0');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) { http_response_code(401); echo json_encode(['error'=>'Account is unavailable.']); exit; }
    $labStream = $user['lab_stream'] ?? '';
    if (!$labStream) {
        $legacy = ['tensile'=>'Tensile Lab (Zeeshan Hassan)','xrf'=>'XRF Lab (Khurram Jahangeer)','oes'=>'OES Lab (Jahangeer)','electronics'=>'Electronics Lab (Lab Technician)'];
        $labStream = $legacy[$user['profile_pic'] ?? ''] ?? '';
    }
    $role = $user['role'];
    if ($role === 'reception' && ($user['profile_pic'] ?? '') === 'pa') $role = 'pa';
    $pdo->prepare('UPDATE users SET last_login=NOW() WHERE id=?')->execute([$userId]);
    try { $pdo->prepare("INSERT INTO audit_log (user_id,action,module,ip_address) VALUES (?,'LOGIN_SUCCESS','auth',?)")
        ->execute([$userId, $_SERVER['REMOTE_ADDR'] ?? '']); } catch (Throwable $e) { }
    try { $pdo->prepare("INSERT INTO ctms_auth_security_events (user_id,identifier,ip_address,event_type,created_at) VALUES (?,?,?,'LOGIN_SUCCESS',NOW())")
        ->execute([$userId, strtolower($user['employee_id']), substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45)]); } catch (Throwable $e) { }
    $result = ['success'=>true,'token'=>create_token($userId,$role),'user_id'=>$userId,'employee_id'=>$user['employee_id'],
        'full_name'=>$user['full_name'],'role'=>$role,'lab_stream'=>$labStream,'consultancy_access'=>(bool)$user['consultancy_access'],
        'can_technical_review'=>(bool)$user['can_technical_review'],'must_change_password'=>(bool)$user['must_change_password']];
    if ($recoveryCodes) $result['recovery_codes'] = $recoveryCodes;
    echo json_encode($result);
    exit;
}

if ($action === 'setup_init') {
    $ticketRow = ctms_mfa_ticket($pdo, (string)($data['ticket'] ?? ''), 'setup');
    if (!$ticketRow) { http_response_code(401); echo json_encode(['error'=>'Sign-in step expired. Enter your password again.']); exit; }
    $userId = $ticketRow['user_id'];
    $u = $pdo->prepare('SELECT employee_id FROM users WHERE id=? AND is_active=1 AND is_deleted=0');
    $u->execute([$userId]);
    $employee = $u->fetchColumn();
    if (!$employee) { http_response_code(401); echo json_encode(['error'=>'Account is unavailable.']); exit; }
    $old = $pdo->prepare('SELECT enabled_at FROM ctms_user_mfa WHERE user_id=?');
    $old->execute([$userId]);
    if ($old->fetchColumn()) { http_response_code(409); echo json_encode(['error'=>'MFA is already enrolled.']); exit; }
    $secret = ctms_mfa_base32_encode(random_bytes(20));
    $pdo->prepare('INSERT INTO ctms_user_mfa (user_id,secret_ciphertext,enabled_at,updated_at) VALUES (?,?,NULL,NOW()) ON DUPLICATE KEY UPDATE secret_ciphertext=VALUES(secret_ciphertext),updated_at=NOW()')
        ->execute([$userId,ctms_mfa_encrypt($secret)]);
    $label = rawurlencode('CTMS:' . $employee);
    $issuer = rawurlencode('CTMS Materials Testing');
    $uri = "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    echo json_encode(['success'=>true,'secret'=>$secret,'otpauth_uri'=>$uri]); exit;
}

if ($action === 'setup_confirm') {
    $ticketRow = ctms_mfa_ticket($pdo, (string)($data['ticket'] ?? ''), 'setup');
    if (!$ticketRow) { http_response_code(401); echo json_encode(['error'=>'Sign-in step expired. Enter your password again.']); exit; }
    $userId = $ticketRow['user_id'];
    $q = $pdo->prepare('SELECT secret_ciphertext,enabled_at FROM ctms_user_mfa WHERE user_id=?');
    $q->execute([$userId]); $mfa = $q->fetch(PDO::FETCH_ASSOC);
    if (!$mfa || $mfa['enabled_at']) { http_response_code(409); echo json_encode(['error'=>'MFA setup is no longer pending.']); exit; }
    $secret = ctms_mfa_load_secret($pdo, (int)$userId, (string)$mfa['secret_ciphertext']);
    if (!ctms_mfa_consume_totp($pdo, $userId, $secret, (string)($data['code'] ?? ''))) {
        ctms_auth_failed($pdo, 'mfa-user:' . $userId, 'MFA_SETUP_FAILED', $userId);
        http_response_code(401); echo json_encode(['error'=>'That code did not match. Check your device clock and try its current code.']); exit;
    }
    if (!ctms_mfa_ticket($pdo, (string)($data['ticket'] ?? ''), 'setup', true)) { http_response_code(401); echo json_encode(['error'=>'Sign-in step expired.']); exit; }
    $pdo->prepare('UPDATE ctms_user_mfa SET enabled_at=NOW(),updated_at=NOW() WHERE user_id=?')->execute([$userId]);
    try { $pdo->prepare("INSERT INTO audit_log (user_id,action,module,ip_address) VALUES (?,'MFA_ENROLLED','auth',?)")
        ->execute([$userId,$_SERVER['REMOTE_ADDR'] ?? '']); } catch (Throwable $e) { }
    $codes = ctms_mfa_issue_recovery_codes($pdo, $userId);
    ctms_auth_succeeded($pdo, 'mfa-user:' . $userId);
    ctms_mfa_login_response($pdo, $userId, $codes);
}

if ($action === 'login_verify') {
    $ticket = (string)($data['ticket'] ?? '');
    $ticketRow = ctms_mfa_ticket($pdo, $ticket, 'login');
    if (!$ticketRow) { http_response_code(401); echo json_encode(['error'=>'Sign-in step expired or has too many incorrect codes. Start again.']); exit; }
    $userId = $ticketRow['user_id'];
    $identifierStmt = $pdo->prepare('SELECT employee_id FROM users WHERE id=? AND is_active=1 AND is_deleted=0');
    $identifierStmt->execute([$userId]); $identifier = $identifierStmt->fetchColumn();
    if (!$identifier) { http_response_code(401); echo json_encode(['error'=>'Account is unavailable.']); exit; }
    $remaining = ctms_auth_cooldown($pdo, (string)$identifier);
    if ($remaining > 0) { header('Retry-After: ' . $remaining); http_response_code(429); echo json_encode(['error'=>"Please wait {$remaining} seconds before trying again.",'retry_after'=>$remaining]); exit; }
    $q = $pdo->prepare('SELECT secret_ciphertext FROM ctms_user_mfa WHERE user_id=? AND enabled_at IS NOT NULL');
    $q->execute([$userId]); $ciphertext = $q->fetchColumn();
    $code = trim((string)($data['code'] ?? ''));
    $valid = false;
    $usedRecovery = false;
    if ($ciphertext && preg_match('/^\d{6}$/', $code)) $valid = ctms_mfa_consume_totp($pdo, $userId, ctms_mfa_load_secret($pdo, (int)$userId, (string)$ciphertext), $code);
    elseif ($code !== '') { $valid = ctms_mfa_use_recovery_code($pdo, $userId, $code); $usedRecovery = $valid; }
    if (!$valid) {
        $delay = ctms_auth_failed($pdo, (string)$identifier, 'MFA_LOGIN_FAILED', $userId);
        http_response_code(401); echo json_encode(['error'=>$delay ? "Incorrect code. Please wait {$delay} seconds before trying again." : 'Incorrect code. Try again.','retry_after'=>$delay]); exit;
    }
    if (!ctms_mfa_ticket($pdo, $ticket, 'login', true)) { http_response_code(401); echo json_encode(['error'=>'Sign-in step expired. Start again.']); exit; }
    ctms_auth_succeeded($pdo, (string)$identifier);
    if ($usedRecovery) {
        try { ctms_auth_event($pdo, 'MFA_RECOVERY_USED', (string)$identifier, $userId); } catch (Throwable $e) { }
    }
    ctms_mfa_login_response($pdo, $userId);
}

if ($action === 'security_alerts') {
    ctms_require_role(['superadmin','admin'], $tokenData, 'Administrator access is required.');
    $events = $pdo->query("SELECT id,user_id,identifier,ip_address,event_type,created_at FROM ctms_auth_security_events
        WHERE created_at >= (NOW() - INTERVAL 24 HOUR) AND event_type <> 'LOGIN_SUCCESS' ORDER BY created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    $ipAlerts = $pdo->query("SELECT ip_address,COUNT(*) AS attempts,COUNT(DISTINCT identifier) AS accounts,MAX(created_at) AS latest
        FROM ctms_auth_security_events WHERE created_at >= (NOW() - INTERVAL 15 MINUTE) AND event_type IN ('FAILED_LOGIN','FAILED_CAPTCHA','MFA_LOGIN_FAILED','LOGIN_RATE_LIMITED')
        GROUP BY ip_address HAVING COUNT(*) >= 10 OR COUNT(DISTINCT identifier) >= 4 ORDER BY latest DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    $accountAlerts = $pdo->query("SELECT identifier,COUNT(*) AS attempts,COUNT(DISTINCT ip_address) AS source_ips,MAX(created_at) AS latest
        FROM ctms_auth_security_events WHERE created_at >= (NOW() - INTERVAL 15 MINUTE) AND event_type IN ('FAILED_LOGIN','FAILED_CAPTCHA','MFA_LOGIN_FAILED','LOGIN_RATE_LIMITED')
        GROUP BY identifier HAVING COUNT(*) >= 5 ORDER BY latest DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success'=>true,'events'=>$events,'ip_alerts'=>$ipAlerts,'account_alerts'=>$accountAlerts]); exit;
}

if ($action === 'reset_user') {
    ctms_require_role(['superadmin'], $tokenData, 'Only the Super Administrator can reset MFA enrollment.');
    $target = (int)($data['user_id'] ?? 0);
    if (!$target || $target === (int)$tokenData['user_id']) { http_response_code(400); echo json_encode(['error'=>'Choose another valid account.']); exit; }
    $check = $pdo->prepare('SELECT enabled_at FROM ctms_user_mfa WHERE user_id=?');
    $check->execute([$target]);
    if (!$check->fetchColumn()) { http_response_code(404); echo json_encode(['error'=>'This user has no active MFA enrollment to reset.']); exit; }
    $pdo->prepare('DELETE FROM ctms_user_mfa WHERE user_id=?')->execute([$target]);
    $pdo->prepare('DELETE FROM ctms_mfa_recovery_codes WHERE user_id=?')->execute([$target]);
    $pdo->prepare('DELETE FROM ctms_login_challenges WHERE user_id=?')->execute([$target]);
    $pdo->prepare('UPDATE users SET pw_changed_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$target]);
    try { $pdo->prepare("INSERT INTO audit_log (user_id,action,module,record_id,new_value,ip_address) VALUES (?,?, 'auth', ?, ?, ?)")
        ->execute([(int)$tokenData['user_id'],'MFA_RESET',$target,json_encode(['target_user_id'=>$target]),$_SERVER['REMOTE_ADDR'] ?? '']); } catch (Throwable $e) { }
    echo json_encode(['success'=>true,'message'=>'MFA enrollment reset. The user must enroll again at the next sign-in.']); exit;
}

http_response_code(400);
echo json_encode(['error'=>'Unknown MFA action.']);
?>
