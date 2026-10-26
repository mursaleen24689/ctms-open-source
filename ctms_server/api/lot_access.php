<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
$actor = require_auth();
if (($actor['role'] ?? '') !== 'superadmin') { http_response_code(403); echo json_encode(['error' => 'Only the Super Administrator can assign lot access.']); exit; }
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $userId = (int)($_GET['user_id'] ?? 0);
    if ($userId <= 0) { http_response_code(400); echo json_encode(['error' => 'user_id is required']); exit; }
    try {
        $user = $pdo->prepare('SELECT id, role, lot_access_restricted FROM users WHERE id = ? AND is_deleted = 0');
        $user->execute([$userId]);
        $target = $user->fetch(PDO::FETCH_ASSOC);
        if (!$target) { http_response_code(404); echo json_encode(['error' => 'User not found']); exit; }
        $lots = $pdo->query("SELECT id, lot_number, agency_type, lot_date, status FROM lots WHERE is_deleted = 0 ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        $access = $pdo->prepare('SELECT lot_id, access_level FROM user_lot_access WHERE user_id = ? ORDER BY lot_id');
        $access->execute([$userId]);
        echo json_encode(['success' => true, 'user' => $target, 'lots' => $lots, 'assignments' => $access->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) {
        error_log('[CTMS lot access] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Lot access is unavailable. Apply the per-lot access database update first.']);
    }
    exit;
}

if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$userId = (int)($data['user_id'] ?? 0);
$restricted = !empty($data['restricted']) ? 1 : 0;
$assignments = $data['assignments'] ?? [];
if ($userId <= 0 || !is_array($assignments)) { http_response_code(400); echo json_encode(['error' => 'user_id and assignments are required']); exit; }
$normalized = [];
foreach ($assignments as $item) {
    if (!is_array($item)) continue;
    $lotId = (int)($item['lot_id'] ?? 0);
    $level = (string)($item['access_level'] ?? 'view');
    if ($lotId <= 0 || !in_array($level, ['view','edit','manage'], true)) { http_response_code(400); echo json_encode(['error' => 'Each lot assignment needs a valid lot and access level.']); exit; }
    $normalized[$lotId] = $level;
}
if (!$restricted) $normalized = [];
try {
    $target = $pdo->prepare('SELECT role FROM users WHERE id = ? AND is_deleted = 0');
    $target->execute([$userId]);
    $targetRole = $target->fetchColumn();
    if (!$targetRole) { http_response_code(404); echo json_encode(['error' => 'User not found']); exit; }
    if ($targetRole === 'superadmin' && ($restricted || $normalized)) { http_response_code(409); echo json_encode(['error' => 'The Super Administrator account cannot be restricted by lot.']); exit; }
    if ($normalized) {
        $marks = implode(',', array_fill(0, count($normalized), '?'));
        $check = $pdo->prepare("SELECT COUNT(*) FROM lots WHERE is_deleted = 0 AND id IN ($marks)");
        $check->execute(array_keys($normalized));
        if ((int)$check->fetchColumn() !== count($normalized)) { http_response_code(400); echo json_encode(['error' => 'One or more selected lots no longer exist.']); exit; }
    }
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE users SET lot_access_restricted = ?, updated_at = NOW() WHERE id = ?')->execute([$restricted, $userId]);
    $pdo->prepare('DELETE FROM user_lot_access WHERE user_id = ?')->execute([$userId]);
    if ($restricted) {
        $insert = $pdo->prepare('INSERT INTO user_lot_access (user_id, lot_id, access_level, granted_by) VALUES (?, ?, ?, ?)');
        foreach ($normalized as $lotId => $level) $insert->execute([$userId, $lotId, $level, $actor['user_id']]);
    }
    $pdo->commit();
    try {
        $pdo->prepare("INSERT INTO audit_log (user_id, action, module, record_id, new_value, ip_address) VALUES (?, 'USER_LOT_ACCESS_UPDATED', 'users', ?, ?, ?)")
            ->execute([$actor['user_id'], $userId, json_encode(['restricted' => (bool)$restricted, 'assignments' => $normalized]), $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Throwable $ignored) { /* permissions were saved successfully */ }
    echo json_encode(['success' => true, 'message' => $restricted ? count($normalized) . ' lot assignment(s) saved.' : 'The user can access all lots allowed by their CTMS role.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[CTMS lot access] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not update lot access.']);
}
