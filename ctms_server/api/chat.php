<?php
require_once 'db.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$me = require_auth();
$userId = (int)($me['user_id'] ?? 0);
$isChatAdmin = in_array((string)($me['role'] ?? ''), ['admin', 'superadmin'], true);

function chat_json($value, int $status = 200): void {
    http_response_code($status);
    echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
}

function chat_audit(PDO $pdo, int $userId, string $action, ?int $messageId = null, array $details = []): void {
    $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE);
    $pdo->prepare('INSERT INTO ctms_chat_audit_events (user_id, action, message_id, details_json) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $action, $messageId, $detailsJson]);
    try {
        $pdo->prepare("INSERT INTO audit_log (user_id, action, module, new_value, ip_address) VALUES (?, ?, 'secure_chat', ?, ?)")
            ->execute([$userId, $action, $detailsJson, (string)($_SERVER['REMOTE_ADDR'] ?? '')]);
    } catch (Throwable $e) {
        error_log('[CTMS] Secure chat audit mirror failed: ' . $e->getMessage());
    }
}

if ($method === 'GET') {
    $identityStmt = $pdo->prepare('SELECT key_id, public_key, encrypted_private_backup FROM ctms_chat_identities WHERE user_id = ?');
    $identityStmt->execute([$userId]);
    $identity = $identityStmt->fetch() ?: null;
    if ($identity) {
        $publicBytes = base64_decode((string)$identity['public_key'], true);
        $identity['key_valid'] = $publicBytes !== false && hash_equals((string)$identity['key_id'], hash('sha256', $publicBytes));
    }

    $keys = $pdo->query("SELECT u.id AS user_id, u.full_name, u.role, k.key_id, k.public_key
                          FROM users u LEFT JOIN ctms_chat_identities k ON k.user_id = u.id
                          WHERE u.is_active = 1 AND u.is_deleted = 0 ORDER BY u.full_name, u.id")
        ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($keys as &$member) {
        $publicBytes = $member['public_key'] ? base64_decode((string)$member['public_key'], true) : false;
        $member['key_valid'] = $publicBytes !== false && hash_equals((string)$member['key_id'], hash('sha256', $publicBytes));
    }
    unset($member);

    $afterId = max(0, (int)($_GET['after_id'] ?? 0));
    if ($afterId > 0) {
        $hiddenFilter = $isChatAdmin ? '' : 'LEFT JOIN ctms_chat_hidden_messages h ON h.message_id=m.id AND h.user_id=?';
        $hiddenWhere = $isChatAdmin ? '' : 'AND h.user_id IS NULL';
        $poll = $pdo->prepare("SELECT m.id, m.sender_id, m.sender_name_snapshot, m.ciphertext, m.nonce,
                                      m.envelopes_json, m.ciphertext_hash, m.created_at
                               FROM ctms_chat_messages m $hiddenFilter
                               WHERE m.room_key='general' $hiddenWhere AND m.id > ?
                               ORDER BY m.id ASC LIMIT 100");
        $poll->execute($isChatAdmin ? [$afterId] : [$userId, $afterId]);
        $newRows = $poll->fetchAll(PDO::FETCH_ASSOC);
        foreach ($newRows as &$row) {
            $allEnvelopes = json_decode((string)$row['envelopes_json'], true);
            $row['envelope'] = is_array($allEnvelopes) ? ($allEnvelopes[(string)$userId] ?? null) : null;
            unset($row['envelopes_json']);
        }
        unset($row);
        if ($isChatAdmin) chat_audit($pdo, $userId, 'CHAT_ADMIN_ARCHIVE_READ', null, ['scope' => 'general', 'returned' => count($newRows), 'after_id' => $afterId]);
        chat_json(['success' => true, 'messages' => $newRows, 'poll' => true]);
    }

    $beforeId = max(0, (int)($_GET['before_id'] ?? 0));
    $hiddenFilter = $isChatAdmin ? '' : 'LEFT JOIN ctms_chat_hidden_messages h ON h.message_id = m.id AND h.user_id = ?';
    $hiddenWhere = $isChatAdmin ? '' : 'AND h.user_id IS NULL';
    $stmt = $pdo->prepare("SELECT m.id, m.sender_id, m.sender_name_snapshot, m.ciphertext, m.nonce,
                                  m.envelopes_json, m.ciphertext_hash, m.created_at
                           FROM ctms_chat_messages m $hiddenFilter
                           WHERE m.room_key = 'general' $hiddenWhere AND (? = 0 OR m.id < ?)
                           ORDER BY m.id DESC LIMIT 101");
    $stmt->execute($isChatAdmin ? [$beforeId, $beforeId] : [$userId, $beforeId, $beforeId]);
    $descendingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($descendingRows) > 100;
    if ($hasMore) array_pop($descendingRows);
    $rows = array_reverse($descendingRows);
    foreach ($rows as &$row) {
        $allEnvelopes = json_decode((string)$row['envelopes_json'], true);
        $row['envelope'] = is_array($allEnvelopes) ? ($allEnvelopes[(string)$userId] ?? null) : null;
        unset($row['envelopes_json'], $row['hidden_for_me']);
    }
    unset($row);
    $auditEvents = [];
    if ($isChatAdmin) {
        chat_audit($pdo, $userId, 'CHAT_ADMIN_ARCHIVE_READ', null, ['scope' => 'general', 'returned' => count($rows), 'before_id' => $beforeId]);
        $auditStmt = $pdo->query("SELECT a.id, a.user_id, u.full_name, a.action, a.message_id, a.details_json, a.created_at
                                  FROM ctms_chat_audit_events a JOIN users u ON u.id=a.user_id
                                  ORDER BY a.id DESC LIMIT 100");
        $auditEvents = $auditStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    chat_json([
        'success' => true,
        'identity' => $identity,
        'users' => $keys,
        'messages' => $rows,
        'admin_archive' => $isChatAdmin,
        'audit_events' => $auditEvents,
        'has_more' => $hasMore,
        'retention' => 'Messages are append-only. Hiding a message only hides it from your account.',
    ]);
}

if ($method !== 'POST') chat_json(['error' => 'Method not allowed'], 405);
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) chat_json(['error' => 'Invalid request body.'], 400);
$action = (string)($body['action'] ?? '');

if ($action === 'register_identity') {
    if (!ctms_rate_limit($pdo, 'chat_identity:' . $userId, 8, 600)) chat_json(['error' => 'Too many chat key changes. Try again later.'], 429);
    $keyId = strtolower(trim((string)($body['key_id'] ?? '')));
    $publicKey = trim((string)($body['public_key'] ?? ''));
    $backup = trim((string)($body['encrypted_private_backup'] ?? ''));
    $publicBytes = base64_decode($publicKey, true);
    if (!preg_match('/^[a-f0-9]{64}$/', $keyId) || $publicBytes === false || strlen($publicBytes) < 300 || strlen($publicBytes) > 1024 || strlen($backup) > 20000) {
        chat_json(['error' => 'Secure chat key data is invalid.'], 400);
    }
    if (!hash_equals($keyId, hash('sha256', $publicBytes))) chat_json(['error' => 'The public key fingerprint does not match.'], 400);
    $backupJson = json_decode($backup, true);
    if (!is_array($backupJson) || ($backupJson['v'] ?? null) !== 1) chat_json(['error' => 'Encrypted recovery backup is invalid.'], 400);
    $salt = base64_decode((string)($backupJson['salt'] ?? ''), true);
    $backupIv = base64_decode((string)($backupJson['iv'] ?? ''), true);
    $backupCiphertext = base64_decode((string)($backupJson['ciphertext'] ?? ''), true);
    if ($salt === false || strlen($salt) !== 16 || $backupIv === false || strlen($backupIv) !== 12
        || $backupCiphertext === false || strlen($backupCiphertext) < 100 || strlen($backupCiphertext) > 4096) {
        chat_json(['error' => 'Encrypted recovery backup is malformed.'], 400);
    }
    try {
        $pdo->beginTransaction();
        $previousKeyStmt = $pdo->prepare('SELECT key_id FROM ctms_chat_identities WHERE user_id=? FOR UPDATE');
        $previousKeyStmt->execute([$userId]);
        $previousKeyId = $previousKeyStmt->fetchColumn();
        $pdo->prepare("INSERT INTO ctms_chat_identities (user_id, key_id, public_key, encrypted_private_backup)
                       VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE key_id=VALUES(key_id), public_key=VALUES(public_key),
                         encrypted_private_backup=VALUES(encrypted_private_backup), updated_at=NOW()")
            ->execute([$userId, $keyId, $publicKey, $backup]);
        chat_audit($pdo, $userId, $previousKeyId && !hash_equals((string)$previousKeyId, $keyId) ? 'CHAT_IDENTITY_ROTATED' : 'CHAT_IDENTITY_REGISTERED');
        $pdo->commit();
        chat_json(['success' => true, 'key_id' => $keyId]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[CTMS] Chat identity registration failed: ' . $e->getMessage());
        chat_json(['error' => 'Could not register the secure chat key.'], 500);
    }
}

if ($action === 'send') {
    if (!ctms_rate_limit($pdo, 'chat_send:' . $userId, 60, 60)) chat_json(['error' => 'You are sending messages too quickly. Wait a moment and retry.'], 429);
    $ciphertext = trim((string)($body['ciphertext'] ?? ''));
    $nonce = trim((string)($body['nonce'] ?? ''));
    $envelopes = $body['envelopes'] ?? null;
    $senderKeyId = strtolower(trim((string)($body['key_id'] ?? '')));
    $decodedCiphertext = base64_decode($ciphertext, true);
    $decodedNonce = base64_decode($nonce, true);
    if ($decodedCiphertext === false || strlen($decodedCiphertext) < 17 || strlen($decodedCiphertext) > 12000
        || $decodedNonce === false || strlen($decodedNonce) !== 12 || !is_array($envelopes) || count($envelopes) > 500) {
        chat_json(['error' => 'Encrypted message data is invalid or too large.'], 400);
    }
    $identityStmt = $pdo->prepare('SELECT key_id FROM ctms_chat_identities WHERE user_id = ?');
    $identityStmt->execute([$userId]);
    if (!hash_equals((string)$identityStmt->fetchColumn(), $senderKeyId)) chat_json(['error' => 'Register or unlock your secure chat key before sending.'], 403);

    $adminStatus = $pdo->query("SELECT COUNT(*) AS active_admins,
                                      SUM(CASE WHEN k.user_id IS NOT NULL THEN 1 ELSE 0 END) AS enrolled_admins
                                 FROM users u LEFT JOIN ctms_chat_identities k ON k.user_id=u.id
                                WHERE u.is_active=1 AND u.is_deleted=0 AND u.role IN ('admin','superadmin')")->fetch(PDO::FETCH_ASSOC);
    if ((int)($adminStatus['active_admins'] ?? 0) < 1 || (int)$adminStatus['active_admins'] !== (int)$adminStatus['enrolled_admins']) {
        chat_json(['error' => 'Secure chat is waiting for every active system administrator to enroll a recovery key. This lets authorized administrators review the retained archive.'], 409);
    }
    $activeKeys = $pdo->query("SELECT u.id, k.key_id FROM users u JOIN ctms_chat_identities k ON k.user_id=u.id
                               WHERE u.is_active=1 AND u.is_deleted=0")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($activeKeys as $recipientId => $registeredKeyId) {
        $recipientId = (string)$recipientId;
        $envelope = $envelopes[$recipientId] ?? null;
        if (!is_array($envelope) || !hash_equals((string)$registeredKeyId, (string)($envelope['key_id'] ?? ''))) {
            chat_json(['error' => 'Refresh secure chat: an active member key changed.'], 409);
        }
        $wrapped = base64_decode((string)($envelope['wrapped_key'] ?? ''), true);
        if ($wrapped === false || strlen($wrapped) < 300 || strlen($wrapped) > 1024) chat_json(['error' => 'A recipient key envelope is invalid.'], 400);
    }
    foreach ($envelopes as $recipientId => $_) {
        if (!isset($activeKeys[(int)$recipientId])) chat_json(['error' => 'A message recipient is no longer active. Refresh secure chat.'], 409);
    }
    $envelopeJson = json_encode($envelopes, JSON_UNESCAPED_UNICODE);
    if ($envelopeJson === false || strlen($envelopeJson) > 600000) chat_json(['error' => 'Recipient key envelopes are invalid or too large.'], 400);
    $hash = hash('sha256', $nonce . '.' . $ciphertext . '.' . $envelopeJson . '.' . $userId);
    try {
        $pdo->beginTransaction();
        $nameStmt = $pdo->prepare('SELECT full_name FROM users WHERE id=? AND is_active=1 AND is_deleted=0');
        $nameStmt->execute([$userId]);
        $senderName = (string)$nameStmt->fetchColumn();
        if ($senderName === '') { $pdo->rollBack(); chat_json(['error' => 'Your account is no longer active.'], 403); }
        $pdo->prepare("INSERT INTO ctms_chat_messages (sender_id, sender_name_snapshot, ciphertext, nonce, envelopes_json, ciphertext_hash)
                       VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$userId, $senderName, $ciphertext, $nonce, $envelopeJson, $hash]);
        $messageId = (int)$pdo->lastInsertId();
        chat_audit($pdo, $userId, 'CHAT_MESSAGE_SENT', $messageId, ['hash' => $hash]);
        $pdo->commit();
        chat_json(['success' => true, 'id' => $messageId]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[CTMS] Chat message write failed: ' . $e->getMessage());
        chat_json(['error' => 'Could not save the encrypted message.'], 500);
    }
}

if ($action === 'hide_for_me') {
    $messageId = (int)($body['message_id'] ?? 0);
    if ($messageId <= 0) chat_json(['error' => 'Message id is required.'], 400);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT IGNORE INTO ctms_chat_hidden_messages (user_id, message_id)
                               SELECT ?, id FROM ctms_chat_messages WHERE id=? AND room_key='general'");
        $stmt->execute([$userId, $messageId]);
        if ($stmt->rowCount() > 0) chat_audit($pdo, $userId, 'CHAT_MESSAGE_HIDDEN_FOR_SELF', $messageId);
        $pdo->commit();
        chat_json(['success' => true, 'hidden_for_me' => true, 'retained' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[CTMS] Chat hide failed: ' . $e->getMessage());
        chat_json(['error' => 'Could not hide this message.'], 500);
    }
}

chat_json(['error' => 'Unknown chat action.'], 400);
?>
