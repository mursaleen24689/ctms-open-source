<?php
// ============================================================
// api/audit.php — Audit Trail + Archive + Correction Actions
// ============================================================
require_once 'db.php';

$tokenData = require_auth();
$method    = $_SERVER['REQUEST_METHOD'];
$userId    = $tokenData['user_id'];
$userRole  = $tokenData['role'];
$ip        = $_SERVER['REMOTE_ADDR'] ?? '';
$ua        = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);
if (ctms_user_lot_access_restricted($pdo, (int)$userId)) {
    http_response_code(403); echo json_encode(['error' => 'The global audit trail is unavailable to accounts limited to selected lots.']); exit;
}

// ── GET — search audit trail ──────────────────────────────────
if ($method === 'GET') {
    // Z-3: the audit trail is an accountability record — management
    // and reporting roles only. (POST actions below are already
    // admin-only; this closes the read side.)
    ctms_require_role(['superadmin', 'admin', 'hod'], $tokenData,
        'The audit trail is restricted to management roles.');
    $search = trim($_GET['search'] ?? '');
    $lot_id = (int)($_GET['lot_id'] ?? 0);
    $recent = ($_GET['recent'] ?? '') === '1';

    // ── Recent activity browser — for cases like "show all 150
    // cheque entries made today by admin" where there's no single
    // search term, just a need to browse everything chronologically
    if ($recent) {
        $module = trim($_GET['module'] ?? '');
        $userFilter = (int)($_GET['user_id'] ?? 0);
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo   = trim($_GET['date_to'] ?? '');

        $where = ['1=1'];
        $params = [];
        if ($module)     { $where[] = 'a.module = ?';            $params[] = $module; }
        if ($userFilter) { $where[] = 'a.user_id = ?';           $params[] = $userFilter; }
        if ($dateFrom)    { $where[] = 'DATE(a.action_time) >= ?'; $params[] = $dateFrom; }
        if ($dateTo)      { $where[] = 'DATE(a.action_time) <= ?'; $params[] = $dateTo; }
        $whereStr = implode(' AND ', $where);

        $stmt = $pdo->prepare("
            SELECT a.*, a.action_time AS created_at,
                   u.full_name AS actor_name, u.role AS actor_role
            FROM audit_log a
            LEFT JOIN users u ON a.user_id = u.id
            WHERE $whereStr
            ORDER BY a.action_time DESC
            LIMIT 500
        ");
        $stmt->execute($params);
        echo json_encode(['success'=>true, 'entries'=>$stmt->fetchAll()]);
        exit();
    }

    if ($search !== '') {
        $term = '%' . $search . '%';

        // Defensive: try with new columns, fall back without them
        try {
            $sampleStmt = $pdo->prepare("
                SELECT s.id, s.lot_id, s.sd_number, s.reference_number, s.request_number,
                       s.client_name, s.status, s.extra_data,
                       s.created_at, s.updated_at,
                       l.lot_number, l.agency_type
                FROM samples s
                JOIN lots l ON s.lot_id = l.id
                WHERE s.is_deleted = 0
                  AND (s.sd_number LIKE ? OR s.reference_number LIKE ?
                       OR l.lot_number LIKE ? OR s.request_number LIKE ?)
                ORDER BY s.created_at DESC LIMIT 100
            ");
            $sampleStmt->execute([$term,$term,$term,$term]);
            $samples = $sampleStmt->fetchAll();
        } catch(\PDOException $e) {
            $samples = [];
        }

        // Also search audit_log directly by lot number
        // so lot-level entries (LOT_CREATED, LOT_ARCHIVED, etc.)
        // show up even when no samples match the search term
        $lotAuditDirect = [];
        try {
            $lotLookup = $pdo->prepare("SELECT id FROM lots WHERE lot_number LIKE ? AND is_deleted=0 LIMIT 20");
            $lotLookup->execute([$term]);
            $lotIds = array_column($lotLookup->fetchAll(), 'id');
            if (!empty($lotIds)) {
                $placeholders = implode(',', array_fill(0, count($lotIds), '?'));
                $laStmt = $pdo->prepare("
                    SELECT a.*, a.action_time AS created_at,
                           u.full_name AS actor_name, u.role AS actor_role,
                           l.lot_number AS context_lot
                    FROM audit_log a
                    LEFT JOIN users u ON a.user_id = u.id
                    LEFT JOIN lots l ON l.id = a.record_id AND a.module = 'lots'
                    WHERE a.module = 'lots' AND a.record_id IN ($placeholders)
                    ORDER BY a.action_time ASC
                ");
                $laStmt->execute($lotIds);
                $lotAuditDirect = $laStmt->fetchAll();
            }
        } catch(\PDOException $e) {}

        // ── Search cheque records too — admin can audit "150 cheques
        // made by admin" by searching a cheque number, or just
        // browsing CHEQUE_CREATED entries directly via audit_log
        $chequeAuditDirect = [];
        try {
            $chqLookup = $pdo->prepare("SELECT id, cheque_no, amount, bank_name, cheque_type FROM cheque_records WHERE cheque_no LIKE ? AND is_deleted=0 LIMIT 20");
            $chqLookup->execute([$term]);
            $chqRows = $chqLookup->fetchAll();
            $chqIds = array_column($chqRows, 'id');
            if (!empty($chqIds)) {
                $placeholders2 = implode(',', array_fill(0, count($chqIds), '?'));
                $caStmt = $pdo->prepare("
                    SELECT a.*, a.action_time AS created_at,
                           u.full_name AS actor_name, u.role AS actor_role
                    FROM audit_log a
                    LEFT JOIN users u ON a.user_id = u.id
                    WHERE a.module = 'cheque_records' AND a.record_id IN ($placeholders2)
                    ORDER BY a.action_time ASC
                ");
                $caStmt->execute($chqIds);
                $chequeAuditDirect = $caStmt->fetchAll();
            }
        } catch(\PDOException $e) {}

        $auditStmt = $pdo->prepare("
            SELECT a.*, a.action_time AS created_at,
                   u.full_name AS actor_name, u.role AS actor_role
            FROM audit_log a
            LEFT JOIN users u ON a.user_id = u.id
            WHERE (
                (a.module IN ('samples','sample_lab_assignments') AND a.record_id = ?)
                OR (a.module IN ('lots','results') AND a.record_id = ?)
            )
            ORDER BY a.action_time ASC
        ");

        foreach ($samples as &$s) {
            try {
                $auditStmt->execute([$s['id'], $s['lot_id']]);
                $s['audit_trail'] = $auditStmt->fetchAll();
            } catch(\PDOException $e) {
                $s['audit_trail'] = [];
            }
            if (!empty($s['extra_data'])) {
                $dec = json_decode($s['extra_data'], true);
                $s['extra_data'] = is_array($dec) ? $dec : [];
            }
        }
        unset($s);

        // If no samples found but lot-level audit entries exist,
        // synthesize a pseudo-sample entry to surface lot events
        if (empty($samples) && !empty($lotAuditDirect)) {
            $samples[] = [
                'id'             => 0,
                'lot_id'         => $lotAuditDirect[0]['record_id'] ?? 0,
                'sd_number'      => null,
                'reference_number'=> null,
                'request_number' => null,
                'client_name'    => 'Lot-level audit entry',
                'status'         => 'archived',
                'lot_number'     => $lotAuditDirect[0]['context_lot'] ?? $search,
                'agency_type'    => '',
                'extra_data'     => [],
                'created_at'     => $lotAuditDirect[0]['created_at'] ?? '',
                'audit_trail'    => $lotAuditDirect,
            ];
        }

        // Synthesize a pseudo-sample entry for cheque audit results too
        if (!empty($chequeAuditDirect)) {
            $samples[] = [
                'id'              => 0,
                'lot_id'          => 0,
                'sd_number'       => null,
                'reference_number'=> null,
                'request_number'  => null,
                'client_name'     => 'Cheque / payorder entry — ' . htmlspecialchars($search),
                'status'          => 'financial',
                'lot_number'      => '—',
                'agency_type'     => 'CHEQUE_RECORD',
                'extra_data'      => [],
                'created_at'      => $chequeAuditDirect[0]['created_at'] ?? '',
                'audit_trail'     => $chequeAuditDirect,
            ];
        }

        echo json_encode(['success'=>true,'results'=>$samples,'query'=>$search,'lot_audit'=>$lotAuditDirect,'cheque_audit'=>$chequeAuditDirect]);
        exit();
    }

    if ($lot_id) {
        try {
            $lotStmt = $pdo->prepare("SELECT l.*, uc.full_name AS creator_name FROM lots l LEFT JOIN users uc ON uc.id=l.created_by WHERE l.id=? AND l.is_deleted=0");
            $lotStmt->execute([$lot_id]);
            $lot = $lotStmt->fetch();
        } catch(\PDOException $e) {
            http_response_code(500); echo json_encode(['error' => ctms_db_error($e)]); exit();
        }
        if (!$lot) { http_response_code(404); echo json_encode(['error'=>'Lot not found']); exit(); }
        $lot['samples'] = [];
        try {
            $ss = $pdo->prepare("SELECT * FROM samples WHERE lot_id=? AND is_deleted=0 ORDER BY id ASC");
            $ss->execute([$lot_id]);
            $lot['samples'] = $ss->fetchAll();
        } catch(\PDOException $e) {}

        try {
            $la = $pdo->prepare("SELECT a.*, a.action_time AS created_at, u.full_name AS actor_name, u.role AS actor_role FROM audit_log a LEFT JOIN users u ON a.user_id=u.id WHERE a.module='lots' AND a.record_id=? ORDER BY a.action_time ASC");
            $la->execute([$lot_id]);
            $lot['audit_trail'] = $la->fetchAll();
        } catch(\PDOException $e) { $lot['audit_trail'] = []; }

        echo json_encode(['success'=>true,'lot'=>$lot]);
        exit();
    }

    http_response_code(400);
    echo json_encode(['error'=>'Provide search= or lot_id=']);
    exit();
}

// ── POST — archive / correction actions ───────────────────────
if ($method === 'POST') {
    if (!in_array($userRole,['admin','superadmin'])) { http_response_code(403); echo json_encode(['error'=>'Admin access required']); exit(); }
    $data   = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';

    if ($action === 'archive') {
        $lot_id = (int)($data['lot_id'] ?? 0);
        $note   = trim($data['note'] ?? '');
        if (!$lot_id) { http_response_code(400); echo json_encode(['error'=>'lot_id required']); exit(); }
        $archived = false;
        try {
            $stmt = $pdo->prepare("UPDATE lots SET status='archived',archived_at=NOW(),archived_by=?,archive_note=? WHERE id=? AND is_deleted=0");
            $stmt->execute([$userId,$note,$lot_id]);
            $archived = $stmt->rowCount() > 0;
        } catch(\PDOException $e) {
            $stmt = $pdo->prepare("UPDATE lots SET status='archived' WHERE id=? AND is_deleted=0");
            $stmt->execute([$lot_id]);
            $archived = $stmt->rowCount() > 0;
        }
        if (!$archived) {
            http_response_code(404);
            echo json_encode(['error'=>'Lot not found or could not be archived. Run schema_fixes_v3.sql if status ENUM is outdated.']);
            exit();
        }
        try { $pdo->prepare("UPDATE samples SET is_locked=1 WHERE lot_id=? AND is_deleted=0")->execute([$lot_id]); } catch(\PDOException $e) {}
        try { $pdo->prepare("INSERT INTO audit_log(user_id,action,module,record_id,new_value,ip_address,user_agent)VALUES(?,?,?,?,?,?,?)")->execute([$userId,'LOT_ARCHIVED','lots',$lot_id,json_encode(['note'=>$note]),$ip,$ua]); } catch(\Exception $e) {}
        echo json_encode(['success'=>true,'message'=>'Lot archived.','lot_id'=>$lot_id]);
        exit();
    }

    if ($action === 'unarchive') {
        $lot_id = (int)($data['lot_id'] ?? 0);
        if (!$lot_id) { http_response_code(400); echo json_encode(['error'=>'lot_id required']); exit(); }
        $pdo->prepare("UPDATE lots SET status='dispatched' WHERE id=?")->execute([$lot_id]);
        try { $pdo->prepare("INSERT INTO audit_log(user_id,action,module,record_id,ip_address)VALUES(?,?,?,?,?)")->execute([$userId,'LOT_UNARCHIVED','lots',$lot_id,$ip]); } catch(\Exception $e) {}
        echo json_encode(['success'=>true,'message'=>'Lot restored from archive.']);
        exit();
    }

    if ($action === 'flag_correction') {
        $sample_id  = (int)($data['sample_id']  ?? 0);
        $note       = trim($data['note']       ?? '');
        $lab_stream = trim($data['lab_stream'] ?? '');
        if (!$sample_id || !$lab_stream) { http_response_code(400); echo json_encode(['error'=>'sample_id and lab_stream required']); exit(); }
        try { $pdo->prepare("UPDATE samples SET is_locked=0,correction_flag=1,correction_note=?,correction_by=?,correction_at=NOW(),status='in_testing' WHERE id=?")->execute([$note,$userId,$sample_id]); }
        catch(\PDOException $e) { $pdo->prepare("UPDATE samples SET status='in_testing' WHERE id=?")->execute([$sample_id]); }
        try { $pdo->prepare("INSERT INTO sample_lab_assignments(sample_id,lab_stream,status,is_correction,correction_note,assigned_at)VALUES(?,?,'pending',1,?,NOW()) ON DUPLICATE KEY UPDATE status='pending',is_correction=1,correction_note=VALUES(correction_note),assigned_at=NOW()")->execute([$sample_id,$lab_stream,$note]); } catch(\PDOException $e) {}
        try { $pdo->prepare("INSERT INTO audit_log(user_id,action,module,record_id,new_value,ip_address)VALUES(?,?,?,?,?,?)")->execute([$userId,'SAMPLE_CORRECTION_FLAGGED','samples',$sample_id,json_encode(['lab_stream'=>$lab_stream,'note'=>$note]),$ip]); } catch(\Exception $e) {}
        echo json_encode(['success'=>true,'message'=>"Sample sent to $lab_stream for correction."]);
        exit();
    }

    if ($action === 'revert_correction') {
        $sample_id = (int)($data['sample_id'] ?? 0);
        if (!$sample_id) { http_response_code(400); echo json_encode(['error'=>'sample_id required']); exit(); }
        try { $pdo->prepare("UPDATE samples SET is_locked=1,correction_flag=0,correction_note=NULL,status='data_entry' WHERE id=?")->execute([$sample_id]); } catch(\PDOException $e) {}
        try { $pdo->prepare("UPDATE sample_lab_assignments SET status='complete' WHERE sample_id=? AND is_correction=1 AND status='pending'")->execute([$sample_id]); } catch(\PDOException $e) {}
        try { $pdo->prepare("INSERT INTO audit_log(user_id,action,module,record_id,ip_address)VALUES(?,?,?,?,?)")->execute([$userId,'SAMPLE_CORRECTION_REVERTED','samples',$sample_id,$ip]); } catch(\Exception $e) {}
        echo json_encode(['success'=>true,'message'=>'Correction cancelled.']);
        exit();
    }

    http_response_code(400);
    echo json_encode(['error'=>"Unknown action: $action"]);
    exit();
}

http_response_code(405);
echo json_encode(['error'=>'Method not allowed']);
?>
