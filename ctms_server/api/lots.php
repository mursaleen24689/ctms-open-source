<?php
// ============================================================
// api/lots.php — Lot Management (v7 — archive/correction aware)
// ============================================================
require_once 'db.php';
require_once __DIR__ . '/notifications_lib.php';

$tokenData = require_auth();
$method    = $_SERVER['REQUEST_METHOD'];
$userId    = $tokenData['user_id'];
$ip        = $_SERVER['REMOTE_ADDR'] ?? '';

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    $includeArchived = ($_GET['include_archived'] ?? '0') === '1';
    $lot_id          = (int)($_GET['lot_id'] ?? 0);

    $where = "l.is_deleted = 0";
    if (!$includeArchived) {
        $where .= " AND (l.status IS NULL OR l.status != 'archived') AND l.archived_at IS NULL";
    }
    if (ctms_user_lot_access_restricted($pdo, (int)$userId)) {
        $where .= " AND EXISTS (SELECT 1 FROM user_lot_access ula WHERE ula.user_id = " . (int)$userId . " AND ula.lot_id = l.id)";
    }
    if ($lot_id)           $where .= " AND l.id = " . intval($lot_id);

    // Defensive SELECT — avoids crashing if archived_at/archived_by
    // columns don't exist yet because migration hasn't been run.
    // We try with those columns first; on error fall back to basics.
    try {
        $stmt = $pdo->query("
            SELECT l.id, l.lot_number, l.agency_type, l.status, l.lot_date,
                   l.promised_date, l.sla_days,
                   l.created_at,
                   l.archived_at, l.archive_note,
                   uc.full_name AS created_by_name,
                   ua.full_name AS archived_by_name,
                   (SELECT COUNT(*) FROM samples s WHERE s.lot_id=l.id AND s.is_deleted=0) AS total_samples
            FROM lots l
            LEFT JOIN users uc ON uc.id = l.created_by
            LEFT JOIN users ua ON ua.id = l.archived_by
            WHERE $where ORDER BY l.created_at DESC
        ");
    } catch (\PDOException $e) {
        // archived_at / archived_by columns may not exist yet — fallback
        $stmt = $pdo->query("
            SELECT l.id, l.lot_number, l.agency_type, l.status, l.lot_date,
                   l.created_at,
                   NULL AS archived_at, NULL AS archive_note,
                   uc.full_name AS created_by_name,
                   NULL AS archived_by_name,
                   (SELECT COUNT(*) FROM samples s WHERE s.lot_id=l.id AND s.is_deleted=0) AS total_samples
            FROM lots l
            LEFT JOIN users uc ON uc.id = l.created_by
            WHERE $where ORDER BY l.created_at DESC
        ");
    }
    $lots = $stmt->fetchAll();

    // F-1: bulk-load ALL samples and assignments for the result set in
    // two queries (the old per-lot/per-sample loops were N+1 queries —
    // hundreds of round-trips on large datasets).
    $lotIds = array_map(fn($l) => (int)$l['id'], $lots);
    $samplesByLot = [];
    $assignBySample = [];
    if ($lotIds) {
        $in = implode(',', $lotIds);
        foreach ($pdo->query("
            SELECT id, lot_id, sd_number, request_number, reference_number,
                   client_name, agency_type, sample_category, status,
                   delivery_type, notes, extra_data, created_at, updated_at
            FROM samples WHERE lot_id IN ($in) AND is_deleted=0 ORDER BY id ASC
        ") as $s) {
            $samplesByLot[(int)$s['lot_id']][] = $s;
        }
        try {
            foreach ($pdo->query("
                SELECT a.id, a.sample_id, a.lab_stream, a.status, a.result_data, a.assigned_at, a.completed_at
                FROM sample_lab_assignments a
                JOIN samples s ON s.id = a.sample_id
                WHERE s.lot_id IN ($in) AND s.is_deleted = 0
            ") as $a) {
                $assignBySample[(int)$a['sample_id']][] = [
                    'id' => $a['id'], 'lab_stream' => $a['lab_stream'], 'status' => $a['status'],
                    'result_data' => $a['result_data'], 'assigned_at' => $a['assigned_at'],
                    'completed_at' => $a['completed_at'],
                ];
            }
        } catch (\PDOException $e) { /* assignments table optional */ }
    }

    foreach ($lots as &$lot) {
        $samples = $samplesByLot[(int)$lot['id']] ?? [];
        foreach ($samples as &$s) {
            $dec = !empty($s['extra_data']) ? json_decode($s['extra_data'],true) : null;
            if (is_array($dec) && !empty($dec)) {
                $s['extra_data'] = $dec;
            } else {
                // Build fallback from columns that actually exist for this lot type.
                // CHEQUE lots never have SD No — injecting it would add a blank
                // column to the Excel sheet that confuses the peon.
                $lotType = strtoupper($s['agency_type'] ?? $lot['agency_type'] ?? 'CHEQUE');
                if ($lotType === 'CHEQUE') {
                    $s['extra_data'] = [
                        'Reference Number'    => $s['reference_number'] ?? '',
                        'Request No'          => $s['request_number']   ?? '',
                        'Material Description'=> $s['client_name']       ?? '',
                    ];
                } else {
                    // ONLINE / ELECTRONICS / MIXED — SD No is valid
                    $s['extra_data'] = [
                        'SD No'               => $s['sd_number']         ?? '',
                        'Reference Number'    => $s['reference_number']  ?? '',
                        'Request No'          => $s['request_number']    ?? '',
                        'Material Description'=> $s['client_name']       ?? '',
                    ];
                }
            }
            $s['lab_assignments'] = $assignBySample[(int)$s['id']] ?? [];
        }
        unset($s);
        $lot['samples'] = $samples;
    }
    unset($lot);
    echo json_encode($lots);
    exit();
}

// ── POST ──────────────────────────────────────────────────────
if ($method === 'POST') {
    if (!in_array($tokenData['role'] ?? '', ['superadmin', 'admin', 'reception', 'pa', 'mapping', 'hod'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Permission denied: Intake/Reception or Administrator privileges required to create lots.']);
        exit();
    }
    if (ctms_user_lot_access_restricted($pdo, (int)$userId)) {
        http_response_code(403);
        echo json_encode(['error' => 'This account is limited to assigned lots and cannot create a new lot.']);
        exit();
    }
    $data = json_decode(file_get_contents('php://input'), true);

    if (!empty($data['generate_number_only'])) {
        $type = strtoupper($data['lot_type'] ?? 'CHEQUE');
        $seqStmt = $pdo->prepare("SELECT COUNT(*) FROM lots WHERE YEAR(created_at)=? AND agency_type=? AND is_deleted=0");
        $seqStmt->execute([date('Y'), $type]);
        $seq = (int)$seqStmt->fetchColumn() + 1;
        // LOT-1-22-6-2026 — no zero padding, single-digit month, day as-is
        $lotNum = 'LOT-'.$seq.'-'.(int)date('d').'-'.(int)date('m').'-'.date('Y');
        echo json_encode(['success'=>true,'lot_number'=>$lotNum]);
        exit();
    }

    $lot_number = $data['lot_number'] ?? null;
    if (empty($lot_number)) { http_response_code(400); echo json_encode(['error'=>'lot_number required']); exit(); }
    $lot_type = strtoupper($data['lot_type'] ?? $data['agency_type'] ?? 'CHEQUE');
    $lot_date = $data['lot_date'] ?? date('Y-m-d');

    $check = $pdo->prepare("SELECT id FROM lots WHERE lot_number=? AND is_deleted=0 LIMIT 1");
    $check->execute([$lot_number]);
    if ($row = $check->fetch()) { echo json_encode(['success'=>true,'id'=>$row['id'],'existing'=>true]); exit(); }

    try {
        // TAT: optional promised delivery = lot date + SLA days. The
        // intake form sends it; overdue lots surface on the dashboard.
        $sla_days = (int)($data['sla_days'] ?? 0);
        if ($sla_days > 0 && $sla_days <= 365) {
            $pdo->prepare("INSERT INTO lots(lot_number,agency_type,lot_date,promised_date,sla_days,status,created_by) VALUES(?,?,?, DATE_ADD(?, INTERVAL ? DAY), ?, 'open', ?)")
                ->execute([$lot_number,$lot_type,$lot_date,$lot_date,$sla_days,$sla_days,$userId]);
        } else {
            $pdo->prepare("INSERT INTO lots(lot_number,agency_type,lot_date,status,created_by) VALUES(?,?,?,'open',?)")
                ->execute([$lot_number,$lot_type,$lot_date,$userId]);
        }
        $newId = (int)$pdo->lastInsertId();
        try {
            $pdo->prepare("INSERT INTO audit_log(user_id,action,module,record_id,new_value,ip_address) VALUES(?,?,?,?,?,?)")
                ->execute([$userId,'LOT_CREATED','lots',$newId,json_encode(['lot_number'=>$lot_number,'lot_type'=>$lot_type]),$ip]);
        } catch(\Exception $e) {}
        $notificationCount = 0;
        $notificationWarning = null;
        try { $notificationCount = ctms_notify_lot_created($pdo,$newId,(string)$lot_number,(string)$lot_type,(int)$userId); }
        catch (Throwable $e) {
            error_log('[CTMS notifications] lot-created alert failed: '.$e->getMessage());
            $notificationWarning = 'The lot was saved, but employee notifications could not be recorded.';
        }
        echo json_encode(['success'=>true,'id'=>$newId,'lot_number'=>$lot_number,'notifications_sent'=>$notificationCount,'notification_warning'=>$notificationWarning]);
    } catch(\PDOException $e) {
        http_response_code(500);
        // Friendly handling for the one realistic failure: a soft-deleted
        // lot still holds the unique lot_number (audit history preserved),
        // so that exact number can never be reused.
        if (strpos($e->getMessage(), '1062') !== false || strpos($e->getMessage(), 'Duplicate entry') !== false) {
            http_response_code(409);
            echo json_encode(['error' => "Lot number '$lot_number' already exists (it may be in a deleted lot). Generate a new number."]);
            exit();
        }
        echo json_encode(['error'=>'Could not create lot','details'=>ctms_db_error($e)]);
    }
    exit();
}

// ── PUT ───────────────────────────────────────────────────────
if ($method === 'PUT') {
    if (!in_array($tokenData['role'],['superadmin','admin'])) { http_response_code(403); echo json_encode(['error'=>'Admin only']); exit(); }
    $data   = json_decode(file_get_contents('php://input'), true);
    $lot_id = (int)($data['lot_id'] ?? 0);
    $status = $data['status'] ?? '';
    if (!$lot_id || !$status) { http_response_code(400); echo json_encode(['error'=>'lot_id and status required']); exit(); }
    ctms_require_lot_access($pdo, (int)$userId, $lot_id, 'manage');
    $allowed = ['open','active','dispatched','archived'];
    if (!in_array($status,$allowed)) { http_response_code(400); echo json_encode(['error'=>"Invalid status: $status"]); exit(); }
    $pdo->prepare("UPDATE lots SET status=?, updated_at=NOW() WHERE id=?")->execute([$status,$lot_id]);
    try { $pdo->prepare("INSERT INTO audit_log(user_id,action,module,record_id,new_value,ip_address) VALUES(?,?,?,?,?,?)")->execute([$userId,'LOT_STATUS_CHANGED','lots',$lot_id,json_encode(['status'=>$status]),$ip]); } catch(\Exception $e) {}
    echo json_encode(['success'=>true]);
    exit();
}

// ── DELETE ────────────────────────────────────────────────────
if ($method === 'DELETE') {
    if (!in_array($tokenData['role'],['superadmin','admin'])) { http_response_code(403); echo json_encode(['error'=>'Admin only']); exit(); }
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit(); }
    ctms_require_lot_access($pdo, (int)$userId, $id, 'manage');
    $pdo->prepare("UPDATE lots    SET is_deleted=1 WHERE id=?")->execute([$id]);
    $pdo->prepare("UPDATE samples SET is_deleted=1 WHERE lot_id=?")->execute([$id]);
    try { $pdo->prepare("INSERT INTO audit_log(user_id,action,module,record_id,ip_address) VALUES(?,?,?,?,?)")->execute([$userId,'LOT_DELETED','lots',$id,$ip]); } catch(\Exception $e) {}
    echo json_encode(['success'=>true]);
    exit();
}

http_response_code(405);
echo json_encode(['error'=>'Method not allowed']);
?>
