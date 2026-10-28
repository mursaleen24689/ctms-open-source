<?php
// ctms_server/api/report_templates.php
// CRUD for report_templates table. Admin-only writes; authenticated reads.
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

// Columns that exist on report_templates and may be written by a client.
$WRITABLE = [
    'template_id', 'template_name', 'display_name', 'sample_category',
    'tests_included', 'column_schema', 'requires_signature',
    'format_family', 'file_path', 'is_active',
];

function decode_schema(array $row): array {
    $schema = $row['column_schema'] ?? null;
    $decoded = (is_string($schema) && $schema !== '') ? json_decode($schema, true) : null;
    $row['column_schema'] = is_array($decoded) ? $decoded : [];
    return $row;
}

switch ($method) {

case 'GET':
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM report_templates WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }
        echo json_encode(decode_schema($row));
        exit;
    }
    $family = $_GET['family'] ?? ($_GET['format_family'] ?? null);
    if (is_string($family) && $family !== '') {
        $stmt = $pdo->prepare("SELECT * FROM report_templates WHERE format_family = ? AND is_active = 1 ORDER BY template_id");
        $stmt->execute([$family]);
    } else {
        $stmt = $pdo->query("SELECT * FROM report_templates ORDER BY is_active DESC, template_id");
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r = decode_schema($r); }
    unset($r);
    echo json_encode($rows);
    break;

case 'POST':
    require_admin($user);
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        exit;
    }
    foreach (['template_id', 'template_name'] as $f) {
        if (empty($body[$f])) {
            http_response_code(400);
            echo json_encode(['error' => "Missing field: $f"]);
            exit;
        }
    }
    $stmt = $pdo->prepare("INSERT INTO report_templates
        (template_id, template_name, display_name, sample_category, tests_included,
         column_schema, requires_signature, format_family, file_path, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    try {
        $stmt->execute([
            $body['template_id'],
            $body['template_name'],
            $body['display_name'] ?? null,
            $body['sample_category'] ?? 'other',
            isset($body['tests_included'])
                ? (is_string($body['tests_included']) ? $body['tests_included'] : json_encode($body['tests_included']))
                : null,
            isset($body['column_schema']) ? json_encode($body['column_schema']) : null,
            !empty($body['requires_signature']) ? 1 : 0,
            $body['format_family'] ?? null,
            $body['file_path'] ?? null,
            (isset($body['is_active']) && !$body['is_active']) ? 0 : 1,
        ]);
        echo json_encode(['id' => $pdo->lastInsertId(), 'status' => 'created']);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            http_response_code(409);
            echo json_encode(['error' => 'A template with this template_id already exists']);
        } else {
            error_log('[CTMS] report_templates insert failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Could not create the template']);
        }
    }
    break;

case 'PUT':
    require_admin($user);
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || empty($body['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing id']);
        exit;
    }
    $fields = [];
    $params = [];
    foreach ($WRITABLE as $f) {
        if (!array_key_exists($f, $body)) continue;
        if ($f === 'column_schema' || $f === 'tests_included') {
            $params[] = is_string($body[$f]) ? $body[$f] : json_encode($body[$f]);
        } elseif ($f === 'requires_signature' || $f === 'is_active') {
            $params[] = !empty($body[$f]) ? 1 : 0;
        } else {
            $params[] = $body[$f];
        }
        $fields[] = "$f = ?";
    }
    if (empty($fields)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nothing to update']);
        exit;
    }
    $params[] = $body['id'];
    try {
        $stmt = $pdo->prepare("UPDATE report_templates SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($params);
    } catch (PDOException $e) {
        error_log('[CTMS] report_templates update failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Could not update the template']);
        exit;
    }
    echo json_encode(['status' => 'updated', 'affected' => $stmt->rowCount()]);
    break;

case 'DELETE':
    require_admin($user);
    $id = $_GET['id'] ?? null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing id']);
        exit;
    }
    // soft delete — templates are referenced by historical reports
    $stmt = $pdo->prepare("UPDATE report_templates SET is_active = 0 WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['status' => 'deactivated']);
    break;

default:
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
