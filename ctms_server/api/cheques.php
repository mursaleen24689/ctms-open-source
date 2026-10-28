<?php
require_once 'db.php';
$method = $_SERVER['REQUEST_METHOD'];

// ── CREATE TABLES if not exist ────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS cheque_records (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    cheque_type ENUM('hmbl','other') NOT NULL DEFAULT 'other',
    serial_no   INT NOT NULL,
    cheque_no   VARCHAR(30) NOT NULL,
    cheque_date DATE NOT NULL,
    bank_name   VARCHAR(100) NOT NULL DEFAULT 'HABIB METROPOLITAN BANK',
    amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
    remarks     TEXT NULL,
    sheet_no    INT NOT NULL DEFAULT 1,
    entry_date  DATE NOT NULL,
    created_by  INT NULL,
    is_deleted  TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME DEFAULT NOW(),
    updated_at  DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Serial numbers are allocated per (cheque_type, entry_date). Keeping the
// next value in its own row lets the allocation statement lock it, so two
// clerks saving at the same moment can never receive the same serial.
$pdo->exec("CREATE TABLE IF NOT EXISTS cheque_serial_counters (
    cheque_type VARCHAR(20) NOT NULL,
    entry_date  DATE NOT NULL,
    next_serial INT NOT NULL DEFAULT 1,
    PRIMARY KEY (cheque_type, entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NULL,
    type       VARCHAR(50) NOT NULL DEFAULT 'info',
    title      VARCHAR(200) NOT NULL,
    message    TEXT NULL,
    is_read    TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── FORGOT PASSWORD notification — NO AUTH NEEDED ─────────────
// This is called from the login page before the user has a token
$action = $_GET['action'] ?? '';
if ($action === 'notification' && $method === 'POST') {
    $data  = json_decode(file_get_contents('php://input'), true);
    $email = trim($data['email'] ?? '');

    if (empty($email)) {
        http_response_code(400);
        echo json_encode(["error" => "Email required"]);
        exit();
    }

    // SEC-006: this endpoint is intentionally unauthenticated (used from
    // the login page), so it must validate input and be rate limited.
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        http_response_code(400);
        echo json_encode(["error" => "A valid email address is required"]);
        exit();
    }
    if (!ctms_rate_limit($pdo, 'pwreset_ip:' . ctms_client_ip(), 5, 3600)) {
        http_response_code(429);
        echo json_encode(["error" => "Too many requests. Please try again later."]);
        exit();
    }

    // user_id is explicitly NULL here — this notification isn't tied
    // to a specific admin account, it's a system-wide alert any admin
    // can see and act on. Omitting the column entirely (instead of
    // passing NULL) was causing MySQL to apply an implicit default
    // that violated the notifications -> users foreign key constraint.
    $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, is_read) VALUES (NULL, 'password_reset', 'Password Reset Request', ?, 0)")
        ->execute(["User '$email' has requested a password reset. Please reset their password in User Management."]);
    echo json_encode(["success" => true]);
    exit();
}

// ── ALL OTHER ROUTES REQUIRE AUTH ────────────────────────────
$tokenData = require_auth();
if (ctms_user_lot_access_restricted($pdo, (int)$tokenData['user_id'])) {
    http_response_code(403); echo json_encode(['error' => 'Global cheque records are unavailable to accounts limited to selected lots.']); exit;
}

if (!in_array($tokenData['role'], ['superadmin', 'admin'])) {
    http_response_code(403);
    echo json_encode(["error" => "Admin access required"]);
    exit();
}

if ($method === 'GET') {
    $type      = strtolower($_GET['type'] ?? 'other');
    $entryDate = $_GET['date'] ?? null;

    // Bank suggestions for the entry form's datalist: the master list
    // plus every bank actually used on a cheque, most-used first.
    if ($type === 'banks') {
        $banks = [];
        try {
            foreach ($pdo->query("SELECT bank_name FROM master_banks WHERE is_active = 1 ORDER BY bank_name") as $b) {
                $banks[] = $b['bank_name'];
            }
        } catch (\PDOException $e) { /* table optional */ }
        try {
            $used = $pdo->query("SELECT bank_name, COUNT(*) c FROM cheque_records
                                 WHERE is_deleted = 0 AND bank_name IS NOT NULL AND bank_name != ''
                                 GROUP BY bank_name ORDER BY c DESC, bank_name ASC LIMIT 30")->fetchAll();
            foreach ($used as $u) {
                if (!in_array($u['bank_name'], $banks, true)) $banks[] = $u['bank_name'];
            }
        } catch (\PDOException $e) { /* ignore */ }
        echo json_encode(["success" => true, "banks" => $banks]);
        exit();
    }

    $q      = "SELECT * FROM cheque_records WHERE cheque_type = ? AND is_deleted = 0";
    $params = [$type];
    if ($entryDate) { $q .= " AND entry_date = ?"; $params[] = $entryDate; }
    $q .= " ORDER BY entry_date DESC, sheet_no ASC, serial_no ASC";

    $stmt  = $pdo->prepare($q);
    $stmt->execute($params);
    $cheques = $stmt->fetchAll();

    // Unread notifications for admin
    $notifs = $pdo->query("SELECT id, type, title, message, created_at FROM notifications WHERE is_read = 0 ORDER BY created_at DESC LIMIT 20")->fetchAll();

    echo json_encode(["success" => true, "cheques" => $cheques, "notifications" => $notifs]);
    exit();
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    // Mark notification as read
    if (!empty($data['mark_read'])) {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?")->execute([$data['mark_read']]);
        echo json_encode(["success" => true]);
        exit();
    }

    $cheque_type = strtolower($data['cheque_type'] ?? 'other');
    if (!in_array($cheque_type, ['hmbl', 'other'], true)) $cheque_type = 'other';
    $cheque_no   = trim($data['cheque_no']    ?? '');
    $cheque_date = $data['cheque_date']        ?? date('Y-m-d');
    $bank_name   = trim($data['bank_name']    ?? '');
    $amount      = (float)($data['amount']    ?? 0);
    $remarks     = trim($data['remarks']      ?? '');
    $entry_date  = $data['entry_date']         ?? date('Y-m-d');

    if (empty($cheque_no) || $amount <= 0) {
        http_response_code(400);
        echo json_encode(["error" => "Cheque number and amount are required"]);
        exit();
    }
    if ($cheque_type === 'hmbl') $bank_name = 'HABIB METROPOLITAN BANK';

    // ── Auto-paginate: 40 entries per printed sheet ──────────────
    // Matches the physical submission sheet template — once a
    // sheet fills with 40 cheques, the next entry automatically
    // starts Sheet 2, and so on. No manual sheet number entry.
    $ENTRIES_PER_SHEET = 40;

    // H-09: allocate the serial with one locked UPDATE instead of a separate
    // MAX(serial_no)+1 read, so concurrent entries cannot share a serial.
    // LAST_INSERT_ID(expr) returns the value just stored (serial + 1).
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT IGNORE INTO cheque_serial_counters (cheque_type, entry_date, next_serial) VALUES (?, ?, 1)")
            ->execute([$cheque_type, $entry_date]);
        $pdo->prepare("UPDATE cheque_serial_counters
                          SET next_serial = LAST_INSERT_ID(GREATEST(next_serial,
                                COALESCE((SELECT MAX(serial_no) FROM cheque_records
                                           WHERE cheque_type = ? AND entry_date = ?), 0) + 1) + 1)
                        WHERE cheque_type = ? AND entry_date = ?")
            ->execute([$cheque_type, $entry_date, $cheque_type, $entry_date]);
        $next_serial = (int)$pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn() - 1;
        if ($next_serial < 1) throw new RuntimeException('Serial allocation failed.');

        $sheet_no = (int)ceil($next_serial / $ENTRIES_PER_SHEET);
        if ($sheet_no < 1) $sheet_no = 1;

        $pdo->prepare("INSERT INTO cheque_records (cheque_type,serial_no,cheque_no,cheque_date,bank_name,amount,remarks,sheet_no,entry_date,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$cheque_type,$next_serial,$cheque_no,$cheque_date,$bank_name,$amount,$remarks,$sheet_no,$entry_date,$tokenData['user_id']]);
        $newChequeId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[CTMS cheques] entry could not be saved: ' . get_class($e) . ': ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "The cheque entry could not be saved. Please retry."]);
        exit();
    }

    try {
        $pdo->prepare("INSERT INTO audit_log(user_id,action,module,record_id,new_value,ip_address) VALUES(?,?,?,?,?,?)")
            ->execute([
                $tokenData['user_id'], 'CHEQUE_CREATED', 'cheque_records', $newChequeId,
                json_encode(['cheque_no'=>$cheque_no,'amount'=>$amount,'bank_name'=>$bank_name,'cheque_type'=>$cheque_type,'sheet_no'=>$sheet_no]),
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
    } catch (\Exception $e) {}

    echo json_encode(["success" => true, "id" => $newChequeId, "serial_no" => $next_serial, "sheet_no" => $sheet_no]);
    exit();
}

if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($data['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(["error" => "id required"]); exit(); }

    $fields = []; $params = [];
    foreach (['cheque_no','cheque_date','bank_name','amount','remarks','sheet_no'] as $f) {
        if (isset($data[$f])) { $fields[] = "$f = ?"; $params[] = $data[$f]; }
    }
    if (empty($fields)) { http_response_code(400); echo json_encode(["error" => "Nothing to update"]); exit(); }
    $fields[] = "updated_at = NOW()"; $params[] = $id;
    $pdo->prepare("UPDATE cheque_records SET ".implode(', ',$fields)." WHERE id = ?")->execute($params);

    try {
        $pdo->prepare("INSERT INTO audit_log(user_id,action,module,record_id,new_value,ip_address) VALUES(?,?,?,?,?,?)")
            ->execute([$tokenData['user_id'], 'CHEQUE_UPDATED', 'cheque_records', $id, json_encode($data), $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (\Exception $e) {}

    echo json_encode(["success" => true]);
    exit();
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(["error" => "id required"]); exit(); }
    $pdo->prepare("UPDATE cheque_records SET is_deleted = 1 WHERE id = ?")->execute([$id]);

    try {
        $pdo->prepare("INSERT INTO audit_log(user_id,action,module,record_id,ip_address) VALUES(?,?,?,?,?)")
            ->execute([$tokenData['user_id'], 'CHEQUE_DELETED', 'cheque_records', $id, $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (\Exception $e) {}

    echo json_encode(["success" => true]);
    exit();
}

http_response_code(405);
echo json_encode(["error" => "Method not allowed"]);
?>
