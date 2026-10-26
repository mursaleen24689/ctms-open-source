<?php
// ctms_server/api/report_templates.php
// CRUD for report_templates table. Admin-only (HOD/admin role gate via require_auth).
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
$user = require_auth();

$method = $_SERVER['REQUEST_METHOD'];

function require_admin($user) {
    if (!isset($user['role']) || !in_array($user['role'], ['superadmin', 'admin', 'hod'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        exit;
    }
}

switch ($method) {

case 'GET':
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM report_templates WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }
        $row['column_schema'] = json_decode($row['column_schema'], true);
        echo json_encode($row);
        exit;
    }
    $family = $_GET['family'] ?? null;
    if ($family) {
        $stmt = $pdo->prepare("SELECT * FROM report_templates WHERE family = ? AND active = 1 ORDER BY test_type");
        $stmt->execute([$family]);
    } else {
        $stmt = $pdo->query("SELECT * FROM report_templates ORDER BY family, test_type");
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['column_schema'] = json_decode($r['column_schema'], true); }
    echo json_encode($rows);
    break;

case 'POST':
    require_admin($user);
    $body = json_decode(file_get_contents('php://input'), true);
    $required = ['family','test_type','display_name','header_variant','column_schema'];
    foreach ($required as $f) {
        if (empty($body[$f])) {
            http_response_code(400);
            echo json_encode(['error' => "Missing field: $f"]);
            exit;
        }
    }
    $stmt = $pdo->prepare("INSERT INTO report_templates
        (family, test_type, display_name, orientation, header_variant, column_schema, requires_signature, notes_template_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    try {
        $stmt->execute([
            $body['family'],
            $body['test_type'],
            $body['display_name'],
            $body['orientation'] ?? 'landscape',
            $body['header_variant'],
            json_encode($body['column_schema']),
            !empty($body['requires_signature']) ? 1 : 0,
            $body['notes_template_id'] ?? null
        ]);
        echo json_encode(['id' => $pdo->lastInsertId(), 'status' => 'created']);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            http_response_code(409);
            echo json_encode(['error' => 'Template with this family+test_type already exists']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'DB error', 'detail' => ctms_db_error($e)]);
        }
    }
    break;

case 'PUT':
    require_admin($user);
    $body = json_decode(file_get_contents('php://input'), true);
    if (empty($body['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing id']);
        exit;
    }
    $fields = [];
    $params = [];
    foreach (['display_name','orientation','header_variant','requires_signature','notes_template_id','active'] as $f) {
        if (array_key_exists($f, $body)) {
            $fields[] = "$f = ?";
            $params[] = $body[$f];
        }
    }
    if (array_key_exists('column_schema', $body)) {
        $fields[] = "column_schema = ?";
        $params[] = json_encode($body['column_schema']);
    }
    if (empty($fields)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nothing to update']);
        exit;
    }
    $params[] = $body['id'];
    $sql = "UPDATE report_templates SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['status' => 'updated']);
    break;

case 'DELETE':
    require_admin($user);
    $id = $_GET['id'] ?? null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing id']);
        exit;
    }
    // soft delete — templates may be referenced by historical reports
    $stmt = $pdo->prepare("UPDATE report_templates SET active = 0 WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['status' => 'deactivated']);
    break;

default:
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
