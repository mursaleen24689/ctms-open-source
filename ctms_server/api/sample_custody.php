<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$actor = require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$userId = (int)$actor['user_id'];
$role = (string)($actor['role'] ?? '');
$canRecord = in_array($role, ['superadmin', 'admin', 'hod', 'pa', 'reception', 'mapping', 'technician'], true);

if ($method === 'GET') {
    $lotId = (int)($_GET['lot_id'] ?? 0);
    $sampleId = (int)($_GET['sample_id'] ?? 0);
    try {
        if ($lotId > 0) {
            ctms_require_lot_access($pdo, $userId, $lotId, 'view');
            $st = $pdo->prepare("SELECT id, sd_number, reference_number, request_number, client_name, status FROM samples WHERE lot_id = ? AND is_deleted = 0 ORDER BY id");
            $st->execute([$lotId]);
            echo json_encode(['success' => true, 'samples' => $st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }
        if ($sampleId > 0) {
            ctms_require_sample_lot_access($pdo, $userId, $sampleId, 'view');
            $exists = $pdo->prepare('SELECT id FROM samples WHERE id = ? AND is_deleted = 0');
            $exists->execute([$sampleId]);
            if (!$exists->fetchColumn()) { http_response_code(404); echo json_encode(['error' => 'Sample not found']); exit; }
            $st = $pdo->prepare('SELECT e.id, e.event_type, e.location, e.details, e.barcode_value, e.event_at, u.full_name AS actor_name FROM sample_custody_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.sample_id = ? ORDER BY e.event_at DESC, e.id DESC LIMIT 300');
            $st->execute([$sampleId]);
            echo json_encode(['success' => true, 'events' => $st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }
        http_response_code(400);
        echo json_encode(['error' => 'Provide lot_id or sample_id']);
    } catch (Throwable $e) {
        error_log('[CTMS custody] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Sample custody is unavailable. Apply the chain-of-custody database update first.']);
    }
    exit;
}

if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
if (!$canRecord) { http_response_code(403); echo json_encode(['error' => 'Your account can view custody history but cannot add custody events.']); exit; }
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$sampleId = (int)($data['sample_id'] ?? 0);
$type = trim((string)($data['event_type'] ?? ''));
$location = trim((string)($data['location'] ?? ''));
$details = trim((string)($data['details'] ?? ''));
$barcode = trim((string)($data['barcode_value'] ?? ''));
$allowedTypes = ['testing_started', 'stored', 'note', 'barcode_scanned'];
if ($sampleId <= 0 || !in_array($type, $allowedTypes, true)) {
    http_response_code(400); echo json_encode(['error' => 'A valid sample and custody event type are required']); exit;
}
if (strlen($location) > 200 || strlen($details) > 2000 || strlen($barcode) > 128) {
    http_response_code(400); echo json_encode(['error' => 'Location, details, or barcode value is too long']); exit;
}
if ($type === 'stored' && $location === '') { http_response_code(400); echo json_encode(['error' => 'Enter the storage location']); exit; }
if ($type === 'barcode_scanned' && $barcode === '') { http_response_code(400); echo json_encode(['error' => 'Enter or scan the barcode value']); exit; }

try {
    $check = $pdo->prepare('SELECT id FROM samples WHERE id = ? AND is_deleted = 0');
    $check->execute([$sampleId]);
    if (!$check->fetchColumn()) { http_response_code(404); echo json_encode(['error' => 'Sample not found']); exit; }
    ctms_require_sample_lot_access($pdo, $userId, $sampleId, 'edit');
    $st = $pdo->prepare('INSERT INTO sample_custody_events (sample_id, actor_id, event_type, location, details, barcode_value, event_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $st->execute([$sampleId, $userId, $type, $location ?: null, $details ?: null, $barcode ?: null]);
    try {
        $pdo->prepare("INSERT INTO audit_log (user_id, action, module, record_id, new_value, ip_address) VALUES (?, 'SAMPLE_CUSTODY_EVENT', 'samples', ?, ?, ?)")
            ->execute([$userId, $sampleId, json_encode(['event_type' => $type, 'location' => $location, 'details' => $details, 'barcode_value' => $barcode]), $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Throwable $ignored) { /* custody events themselves are append-only */ }
    echo json_encode(['success' => true, 'event_id' => (int)$pdo->lastInsertId(), 'message' => 'Custody event recorded.']);
} catch (Throwable $e) {
    error_log('[CTMS custody] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not record the custody event.']);
}
