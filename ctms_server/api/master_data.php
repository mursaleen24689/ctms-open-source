<?php
// api/master_data.php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
$tokenData = require_auth();
if (ctms_user_lot_access_restricted($pdo, (int)$tokenData['user_id'])) {
    http_response_code(403); echo json_encode(['error' => 'Global master data is unavailable to accounts limited to selected lots.']); exit;
}

if ($method === 'GET') {
    // Fetch test methods (Dictionary)
    $stmt = $pdo->query("SELECT id, code, description FROM test_methods WHERE deleted_at IS NULL ORDER BY code ASC");
    echo json_encode($stmt->fetchAll());
} elseif ($method === 'POST') {
    // Only Admin or HoD can add master data (JWT carries `role`, not
    // the legacy numeric role_id — G-1 fix)
    if (!in_array($tokenData['role'] ?? '', ['superadmin', 'admin', 'hod'], true)) {
        http_response_code(403);
        echo json_encode(["error" => "Forbidden"]);
        exit();
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data['code'])) {
        http_response_code(400);
        echo json_encode(["error" => "Code is required"]);
        exit();
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO test_methods (code, description) VALUES (?, ?)");
        $stmt->execute([$data['code'], $data['description'] ?? null]);
        $newId = $pdo->lastInsertId();

        // Audit log
        $auditStmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, entity_type, entity_id, new_values, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
        $auditStmt->execute([$tokenData['user_id'], 'CREATE', 'Test_Method', $newId, json_encode($data), $_SERVER['REMOTE_ADDR']]);

        echo json_encode(["success" => true, "id" => $newId]);
    } catch (\PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Failed to insert master data", "details" => ctms_db_error($e)]);
    }
}
?>
