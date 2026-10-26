<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/result_fields_lib.php';

$tokenData = require_auth();
$pdo = $pdo ?? null;
if (!$pdo instanceof PDO) { http_response_code(500); echo json_encode(['error' => 'Database unavailable']); exit; }
ctms_ensure_result_fields($pdo);

$type = strtolower(trim((string)($_GET['lab_type'] ?? '')));
if (!in_array($type, ['xrf','oes','tensile'], true)) {
    http_response_code(400); echo json_encode(['error' => 'lab_type must be xrf, oes, or tensile']); exit;
}

$role = (string)($tokenData['role'] ?? '');
$isAdmin = in_array($role, ['admin','superadmin'], true);
$userStream = '';
if (!$isAdmin) {
    if (!in_array($role, ['tester','technician'], true)) {
        http_response_code(403); echo json_encode(['error' => 'Not authorized to manage lab fields']); exit;
    }
    $st = $pdo->prepare('SELECT lab_stream FROM users WHERE id = ? AND is_deleted = 0');
    $st->execute([(int)($tokenData['user_id'] ?? 0)]);
    $userStream = (string)$st->fetchColumn();
    $authorizedType = ctms_result_field_type_for_stream($userStream);
    if ($authorizedType !== $type) {
        http_response_code(403); echo json_encode(['error' => 'Your account cannot manage fields for this lab']); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['fields' => ctms_result_fields($pdo, $type, false)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;
}
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($data['action'] ?? '');
try {
    if ($action === 'add') {
        $label = trim((string)($data['label'] ?? ''));
        $unit = trim((string)($data['unit'] ?? ''));
        $key = trim((string)($data['field_key'] ?? ''));
        if ($type === 'xrf' || $type === 'oes') {
            $key = preg_replace('/[^A-Za-z0-9+_.-]/', '', $key);
            if ($key === '' || strlen($key) > 10) throw new InvalidArgumentException('Enter an element symbol or short code (up to 10 characters).');
            $key = ucfirst(strtolower($key));
            $label = $key . ' %';
            $unit = '%';
        } else {
            if ($label === '' || mb_strlen($label) > 120) throw new InvalidArgumentException('Enter a field name up to 120 characters.');
            if ($key === '') $key = 'custom_' . substr(hash('sha256', mb_strtolower($label)), 0, 16);
            if (!preg_match('/^[A-Za-z0-9_ -]{1,80}$/', $key)) throw new InvalidArgumentException('Invalid field key.');
            if ($unit !== '' && mb_strlen($unit) > 30) throw new InvalidArgumentException('Unit is too long.');
        }
        $st = $pdo->prepare('SELECT id FROM lab_result_fields WHERE lab_type = ? AND field_key = ?');
        $st->execute([$type, $key]);
        $existing = (int)($st->fetchColumn() ?: 0);
        if ($existing) {
            $pdo->prepare('UPDATE lab_result_fields SET label = ?, unit = ?, is_active = 1 WHERE id = ?')
                ->execute([$label, $unit ?: null, $existing]);
        } else {
            $sort = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM lab_result_fields WHERE lab_type = ?');
            $sort->execute([$type]);
            $sortOrder = (int)$sort->fetchColumn();
            $pdo->prepare('INSERT INTO lab_result_fields (lab_type, field_key, label, unit, sort_order, is_active, is_builtin)
                VALUES (?, ?, ?, ?, ?, 1, 0)')
                ->execute([$type, $key, $label, $unit ?: null, $sortOrder]);
        }
    } elseif ($action === 'update') {
        $id = (int)($data['id'] ?? 0);
        $label = trim((string)($data['label'] ?? ''));
        $unit = trim((string)($data['unit'] ?? ''));
        if ($id < 1 || $label === '' || mb_strlen($label) > 120 || mb_strlen($unit) > 30) {
            throw new InvalidArgumentException('Provide a field label up to 120 characters and a valid unit.');
        }
        $check = $pdo->prepare('SELECT id FROM lab_result_fields WHERE id = ? AND lab_type = ?');
        $check->execute([$id, $type]);
        if (!$check->fetchColumn()) throw new InvalidArgumentException('Field not found.');
        $st = $pdo->prepare('UPDATE lab_result_fields SET label = ?, unit = ? WHERE id = ? AND lab_type = ?');
        $st->execute([$label, $unit ?: null, $id, $type]);
    } elseif ($action === 'delete') {
        $id = (int)($data['id'] ?? 0);
        $check = $pdo->prepare('SELECT id FROM lab_result_fields WHERE id = ? AND lab_type = ?');
        $check->execute([$id, $type]);
        if (!$check->fetchColumn()) throw new InvalidArgumentException('Field not found.');
        $st = $pdo->prepare('UPDATE lab_result_fields SET is_active = 0 WHERE id = ? AND lab_type = ?');
        $st->execute([$id, $type]);
    } elseif ($action === 'restore') {
        $id = (int)($data['id'] ?? 0);
        $check = $pdo->prepare('SELECT id FROM lab_result_fields WHERE id = ? AND lab_type = ?');
        $check->execute([$id, $type]);
        if (!$check->fetchColumn()) throw new InvalidArgumentException('Field not found.');
        $st = $pdo->prepare('UPDATE lab_result_fields SET is_active = 1 WHERE id = ? AND lab_type = ?');
        $st->execute([$id, $type]);
    } else {
        throw new InvalidArgumentException('Unknown field action.');
    }
    echo json_encode(['success' => true, 'fields' => ctms_result_fields($pdo, $type, false)]);
} catch (InvalidArgumentException $e) {
    http_response_code(422); echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[CTMS] result field update failed: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'Could not save lab fields']);
}
