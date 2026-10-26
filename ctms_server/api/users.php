<?php
// ============================================================
// api/users.php — Users REST Endpoint
//
// GET    /users.php          → List all users (admin only)
// POST   /users.php          → Create a new user (admin only)
// PUT    /users.php          → Update user or reset password (admin only)
// DELETE /users.php?id=X    → Soft-delete a user (admin only)
//
// Uses the real ctms_db users table columns:
//   id, employee_id, full_name, password_hash, role,
//   is_active, is_deleted, last_login, profile_pic
// ============================================================
require_once 'db.php';

$tokenData = require_auth();
$method    = $_SERVER['REQUEST_METHOD'];
if (ctms_user_lot_access_restricted($pdo, (int)$tokenData['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'User administration is unavailable to accounts limited to selected lots.']);
    exit;
}

// Privilege model (audit fix): user MANAGEMENT (create/edit/delete,
// password resets, role changes) is superadmin/admin only — an HOD
// editing a superadmin account was a privilege-escalation path.
// HOD keeps read-only visibility of the staff list (GET below).
$canManage = in_array($tokenData['role'], ['superadmin', 'admin']);
$canView   = $canManage || in_array($tokenData['role'], ['hod']);
if (!$canView) {
    http_response_code(403);
    echo json_encode(["error" => "Admin access required to manage users"]);
    exit();
}

// ── GET — List all users ──────────────────────────────────────
if ($method === 'GET') {
    $stmt = $pdo->query("
        SELECT
            id,
            employee_id,
            full_name,
            role,
            is_active,
            consultancy_access,
            consultancy_role,
            consultancy_can_create_invoice,
            can_technical_review,
            lot_access_restricted,
            (SELECT COUNT(*) FROM user_lot_access ula WHERE ula.user_id = users.id) AS assigned_lot_count,
            last_login,
            profile_pic,
            created_at
        FROM users
        WHERE is_deleted = 0
        ORDER BY full_name ASC
    ");
    $users = $stmt->fetchAll();

    // Never send password hashes to the frontend
    echo json_encode($users);
    exit();
}

// ── POST — Create a new user account ─────────────────────────
if ($method === 'POST') {
    if (!$canManage) { http_response_code(403); echo json_encode(["error" => "Superadmin/Admin access required."]); exit(); }
    $data = json_decode(file_get_contents('php://input'), true);

    $employee_id = trim($data['employee_id'] ?? '');
    $full_name   = trim($data['full_name']   ?? '');
    $role        = trim($data['role']        ?? 'technician');
    $password    = $data['password']          ?? '';

    if (empty($employee_id) || empty($full_name) || !is_string($password) || strlen($password) < 8 || strlen($password) > 72) {
        http_response_code(400);
        echo json_encode(["error" => "employee_id, full_name, and a password of 8 to 72 bytes are required"]);
        exit();
    }

    // Check for duplicate employee_id
    $check = $pdo->prepare("SELECT id FROM users WHERE employee_id = ? AND is_deleted = 0");
    $check->execute([$employee_id]);
    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode(["error" => "An account with Employee ID '$employee_id' already exists."]);
        exit();
    }

    // Allowed roles — map React role names to DB role names
    $roleMap = [
        'admin'      => 'admin',
        'superadmin' => 'superadmin',
        'hod'        => 'hod',
        'chairman'   => 'chairman',
        'mapping'    => 'mapping',
        'reception'  => 'reception',
        'tester'     => 'technician',
        'technician' => 'technician',
        'deo'        => 'deo',
        'pa'         => 'reception',
    ];
    $db_role = $roleMap[$role] ?? 'technician';

    // SEC-004: HODs may manage staff but must NOT mint Superadmins.
    if ($db_role === 'superadmin' && ($tokenData['role'] ?? '') !== 'superadmin') {
        http_response_code(403);
        echo json_encode(["error" => "Permission denied: only a Superadmin may create or assign the Superadmin role."]);
        exit();
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO users (employee_id, full_name, password_hash, role, is_active, consultancy_access, consultancy_role, consultancy_can_create_invoice, can_technical_review, must_change_password, pw_changed_at, created_at)
            VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, 1, NOW(), NOW())
        ");
        $consultancyAccess = !empty($data['consultancy_access']) ? 1 : 0;
        $consultancyRole = (string)($data['consultancy_role'] ?? 'viewer');
        $allowedConsultancyRoles = ['superadmin', 'admin', 'professor', 'technician', 'reception', 'viewer'];
        if (!in_array($consultancyRole, $allowedConsultancyRoles, true)) $consultancyRole = 'viewer';
        if ($consultancyRole === 'superadmin' && ($tokenData['role'] ?? '') !== 'superadmin') {
            http_response_code(403);
            echo json_encode(["error" => "Only a CTMS Super Administrator may assign the Consultancy Super Administrator role."]);
            exit();
        }
        $consultancyCanInvoice = !empty($data['consultancy_can_create_invoice'])
            || in_array($db_role, ['admin', 'superadmin'], true)
            || in_array($consultancyRole, ['superadmin', 'admin'], true)
            || ($consultancyRole === 'reception' && !array_key_exists('consultancy_can_create_invoice', $data)) ? 1 : 0;
        $canReview = !empty($data['can_technical_review']) ? 1 : 0;
        if ($canReview && ($tokenData['role'] ?? '') !== 'superadmin') {
            http_response_code(403);
            echo json_encode(["error" => "Only a Super Administrator may assign the Technical Reviewer permission."]);
            exit();
        }
        $stmt->execute([$employee_id, $full_name, $hash, $db_role, $consultancyAccess, $consultancyRole, $consultancyCanInvoice, $canReview]);
        $newId = $pdo->lastInsertId();

        $pdo->prepare("
            INSERT INTO audit_log (user_id, action, module, record_id, new_value, ip_address)
            VALUES (?, 'USER_CREATED', 'users', ?, ?, ?)
        ")->execute([
            $tokenData['user_id'], $newId,
            json_encode(['employee_id' => $employee_id, 'role' => $db_role]),
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);

        echo json_encode([
            "success"     => true,
            "id"          => $newId,
            "message"     => "Account created for $full_name. The user must set a new password at first sign-in.",
            "employee_id" => $employee_id,
        ]);

    } catch (\PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Could not create user", "details" => ctms_db_error($e)]);
    }
    exit();
}

// ── PUT — Update user profile OR reset password ───────────────
if ($method === 'PUT') {
    if (!$canManage) { http_response_code(403); echo json_encode(["error" => "Superadmin/Admin access required."]); exit(); }
    $data = json_decode(file_get_contents('php://input'), true);

    $id = (int)($data['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(["error" => "User id is required"]);
        exit();
    }

    // Verify user exists
    $check = $pdo->prepare("SELECT id, full_name, employee_id, role FROM users WHERE id = ? AND is_deleted = 0");
    $check->execute([$id]);
    $existing = $check->fetch();
    if (!$existing) {
        http_response_code(404);
        echo json_encode(["error" => "User not found"]);
        exit();
    }

    // ── Password reset (separate from profile update) ─────────
    if (array_key_exists('new_password', $data)) {
        $newPassword = $data['new_password'];
        if (!is_string($newPassword) || strlen($newPassword) < 8 || strlen($newPassword) > 72) {
            http_response_code(400);
            echo json_encode(["error" => "Password must be between 8 and 72 bytes."]);
            exit();
        }
        // SEC-004: Only a Superadmin may reset another Superadmin's credentials
        if (($existing['role'] ?? '') === 'superadmin' && ($tokenData['role'] ?? '') !== 'superadmin') {
            http_response_code(403);
            echo json_encode(["error" => "Permission denied: Only Superadmins can reset a Superadmin password."]);
            exit();
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        // Reset by admin → the user must rotate again at next login,
        // and every token issued before this moment is revoked
        $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 1, pw_changed_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$newHash, $id]);

        $pdo->prepare("
            INSERT INTO audit_log (user_id, action, module, record_id, ip_address)
            VALUES (?, 'PASSWORD_RESET', 'users', ?, ?)
        ")->execute([$tokenData['user_id'], $id, $_SERVER['REMOTE_ADDR'] ?? '']);

        echo json_encode(["success" => true, "message" => "Password updated for {$existing['full_name']}."]);
        exit();
    }

    // ── Profile update ─────────────────────────────────────────
    $roleMap = [
        'admin' => 'admin', 'superadmin' => 'superadmin',
        'hod' => 'hod', 'chairman' => 'chairman',
        'mapping' => 'mapping', 'reception' => 'reception',
        'tester' => 'technician', 'technician' => 'technician',
        'deo' => 'deo', 'pa' => 'reception',
    ];

    $updates = [];
    $params  = [];

    if (!empty($data['full_name'])) {
        $updates[] = "full_name = ?";
        $params[]  = trim($data['full_name']);
    }
    if (!empty($data['employee_id'])) {
        $updates[] = "employee_id = ?";
        $params[]  = trim($data['employee_id']);
    }
    if (!empty($data['role'])) {
        $mappedRole = $roleMap[$data['role']] ?? 'technician';
        // SEC-004: only a Superadmin may promote/assign the Superadmin role.
        if ($mappedRole === 'superadmin' && ($tokenData['role'] ?? '') !== 'superadmin') {
            http_response_code(403);
            echo json_encode(["error" => "Permission denied: only a Superadmin may assign the Superadmin role."]);
            exit();
        }
        $updates[] = "role = ?";
        $params[]  = $mappedRole;
    }
    if (isset($data['is_active'])) {
        $updates[] = "is_active = ?";
        $params[]  = (int)(bool)$data['is_active'];
    }
    if (array_key_exists('consultancy_access', $data)) {
        $updates[] = "consultancy_access = ?";
        $params[]  = !empty($data['consultancy_access']) ? 1 : 0;
    }
    if (array_key_exists('consultancy_role', $data)) {
        $allowedConsultancyRoles = ['superadmin', 'admin', 'professor', 'technician', 'reception', 'viewer'];
        $consultancyRole = (string)$data['consultancy_role'];
        if (!in_array($consultancyRole, $allowedConsultancyRoles, true)) {
            http_response_code(400);
            echo json_encode(["error" => "Invalid Consultancy role"]);
            exit();
        }
        if ($consultancyRole === 'superadmin' && ($tokenData['role'] ?? '') !== 'superadmin') {
            http_response_code(403);
            echo json_encode(["error" => "Only a CTMS Super Administrator may assign the Consultancy Super Administrator role."]);
            exit();
        }
        $updates[] = "consultancy_role = ?";
        $params[]  = $consultancyRole;
    }
    if (array_key_exists('consultancy_can_create_invoice', $data)) {
        $consultancyRole = (string)($data['consultancy_role'] ?? '');
        if ($consultancyRole === '') {
            $roleStmt = $pdo->prepare('SELECT consultancy_role FROM users WHERE id = ?');
            $roleStmt->execute([$id]);
            $consultancyRole = (string)$roleStmt->fetchColumn();
        }
        $accountRoleStmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
        $accountRoleStmt->execute([$id]);
        $accountRole = (string)$accountRoleStmt->fetchColumn();
        $canInvoice = !empty($data['consultancy_can_create_invoice'])
            || in_array($accountRole, ['admin', 'superadmin'], true)
            || in_array($consultancyRole, ['superadmin', 'admin'], true) ? 1 : 0;
        $updates[] = "consultancy_can_create_invoice = ?";
        $params[] = $canInvoice;
    } elseif (array_key_exists('consultancy_role', $data)) {
        $consultancyRole = (string)$data['consultancy_role'];
        if (in_array($consultancyRole, ['superadmin', 'admin', 'reception'], true)) {
            $updates[] = "consultancy_can_create_invoice = 1";
        }
    }
    if (array_key_exists('can_technical_review', $data)) {
        if (($tokenData['role'] ?? '') !== 'superadmin') {
            http_response_code(403);
            echo json_encode(["error" => "Only a Super Administrator may change the Technical Reviewer permission."]);
            exit();
        }
        $updates[] = "can_technical_review = ?";
        $params[]  = !empty($data['can_technical_review']) ? 1 : 0;
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(["error" => "Nothing to update"]);
        exit();
    }

    $updates[] = "updated_at = NOW()";
    $params[]  = $id;

    try {
        $pdo->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);

        $pdo->prepare("
            INSERT INTO audit_log (user_id, action, module, record_id, new_value, ip_address)
            VALUES (?, 'USER_UPDATED', 'users', ?, ?, ?)
        ")->execute([
            $tokenData['user_id'], $id,
            json_encode(array_filter($data, fn($k) => $k !== 'id', ARRAY_FILTER_USE_KEY)),
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);

        echo json_encode(["success" => true, "message" => "Profile updated for {$existing['full_name']}."]);

    } catch (\PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Update failed", "details" => ctms_db_error($e)]);
    }
    exit();
}

// ── DELETE — Soft-delete a user ───────────────────────────────
if ($method === 'DELETE') {
    if (!$canManage) { http_response_code(403); echo json_encode(["error" => "Superadmin/Admin access required."]); exit(); }
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(["error" => "User id required"]);
        exit();
    }

    // Prevent deleting your own account
    if ($id === $tokenData['user_id']) {
        http_response_code(400);
        echo json_encode(["error" => "You cannot delete your own account"]);
        exit();
    }

    // SEC-004: An Admin cannot delete a Superadmin account
    $targetStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $targetStmt->execute([$id]);
    $targetRole = (string)$targetStmt->fetchColumn();
    if ($targetRole === 'superadmin' && ($tokenData['role'] ?? '') !== 'superadmin') {
        http_response_code(403);
        echo json_encode(["error" => "Permission denied: Only Superadmins can deactivate a Superadmin account."]);
        exit();
    }

    $pdo->prepare("UPDATE users SET is_deleted = 1, updated_at = NOW() WHERE id = ?")->execute([$id]);

    $pdo->prepare("
        INSERT INTO audit_log (user_id, action, module, record_id, ip_address)
        VALUES (?, 'USER_DELETED', 'users', ?, ?)
    ")->execute([$tokenData['user_id'], $id, $_SERVER['REMOTE_ADDR'] ?? '']);

    echo json_encode(["success" => true, "message" => "User account deactivated."]);
    exit();
}

http_response_code(405);
echo json_encode(["error" => "Method not allowed"]);
?>
