<?php
// ============================================================
// api/report_archive.php — Report Registry Search
//
// GET ?q=handfree&year=2026&limit=200
//   Searches every generated report (all families — EL-HF, EL-GS,
//   OES-T, X-T, T, legacy RPT) across:
//     report number, lot number, sample type / material,
//     SD number, reference number, client name
//   Optional year filter (YEAR(generated_at)); empty year = all years.
//   "Like Windows Explorer" — one search box, everything matches.
// ============================================================
require_once 'db.php';

$tokenData = require_auth();
ctms_require_role(['superadmin', 'admin', 'hod', 'deo'], $tokenData,
    'The report registry is restricted to reporting roles.');
$method = $_SERVER['REQUEST_METHOD'];

// ── POST actions — dispatch register ─────────────────────────
// do=dispatch   {id, notes} → report handed over to the client
// do=undispatch {id}        → undo a mis-tick
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $do   = $data['do'] ?? '';
    $id   = (int)($data['id'] ?? 0);
    if (!$id || !in_array($do, ['dispatch', 'undispatch'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'do (dispatch|undispatch) and id required']);
        exit();
    }
    if (!in_array($tokenData['role'] ?? '', ['superadmin', 'admin', 'deo'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Not allowed']);
        exit();
    }
    $scope = $pdo->prepare('SELECT s.lot_id FROM reports r JOIN samples s ON s.id = r.sample_id WHERE r.id = ? AND r.is_deleted = 0');
    $scope->execute([$id]);
    $scopeLotId = (int)$scope->fetchColumn();
    if (!$scopeLotId || !ctms_user_has_lot_access($pdo, (int)$tokenData['user_id'], $scopeLotId, 'manage')) {
        http_response_code(403);
        echo json_encode(['error' => 'Your lot assignment does not allow report dispatch changes.']);
        exit();
    }
    try {
        if ($do === 'dispatch') {
            $check = $pdo->prepare("SELECT COUNT(*) FROM reports r JOIN report_approvals w ON w.report_id = r.id WHERE r.id = ? AND r.is_deleted = 0 AND w.stage = 'issued'");
            $check->execute([$id]);
            if (!(int)$check->fetchColumn()) {
                http_response_code(409);
                echo json_encode(['error' => 'Only an Issued report can be dispatched. Complete its configured Technical Review first.']);
                exit();
            }
            $st = $pdo->prepare("UPDATE reports
                                 SET status = 'dispatched', dispatched_at = NOW(), dispatch_notes = ?
                                 WHERE id = ? AND is_deleted = 0 AND dispatched_at IS NULL");
            $st->execute([trim((string)($data['notes'] ?? '')), $id]);
        } else {
            $st = $pdo->prepare("UPDATE reports
                                 SET status = 'draft', dispatched_at = NULL, dispatch_notes = NULL
                                 WHERE id = ? AND is_deleted = 0");
            $st->execute([$id]);
        }
        if ($st->rowCount() > 0) {
            $sampleLookup = $pdo->prepare('SELECT sample_id FROM reports WHERE id = ?');
            $sampleLookup->execute([$id]);
            $sampleId = (int)$sampleLookup->fetchColumn();
            if ($sampleId > 0) {
                $eventType = $do === 'dispatch' ? 'dispatched' : 'dispatch_reversed';
                $pdo->prepare('INSERT INTO sample_custody_events (sample_id, actor_id, event_type, location, details, event_at) VALUES (?, ?, ?, ?, ?, NOW())')
                    ->execute([$sampleId, $tokenData['user_id'], $eventType, 'Client dispatch', trim((string)($data['notes'] ?? '')) ?: null]);
            }
        }
        echo json_encode(['success' => true, 'updated' => $st->rowCount()]);
        // B-7: dispatch/undo always leaves an audit trail entry
        try {
            $pdo->prepare("INSERT INTO audit_log (user_id, action, module, record_id, new_value, ip_address)
                           VALUES (?, ?, 'reports', ?, ?, ?)")
                ->execute([$tokenData['user_id'], 'REPORT_' . strtoupper($do), $id,
                           json_encode(['notes' => $data['notes'] ?? '']), $_SERVER['REMOTE_ADDR'] ?? '']);
        } catch (\PDOException $e) { /* audit must never block the action */ }
    } catch (\PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Dispatch update failed', 'details' => ctms_db_error($e)]);
    }
    exit();
}

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$q     = trim((string)($_GET['q'] ?? ''));
$year  = (int)($_GET['year'] ?? 0);
$limit = min(500, max(1, (int)($_GET['limit'] ?? 200)));
// dispatch filter: all (default) | open (still in lab) | done (handed over)
$disp  = $_GET['dispatch'] ?? 'all';

$where  = "r.is_deleted = 0";
$params = [];
if (ctms_user_lot_access_restricted($pdo, (int)$tokenData['user_id'])) {
    $where .= " AND EXISTS (SELECT 1 FROM user_lot_access ula WHERE ula.user_id = " . (int)$tokenData['user_id'] . " AND ula.lot_id = s.lot_id)";
}

if ($disp === 'open') $where .= " AND r.dispatched_at IS NULL";
if ($disp === 'done') $where .= " AND r.dispatched_at IS NOT NULL";

if ($q !== '') {
    // One box, Explorer-style: matches report family (EL-HF, OES-T,
    // X-T, T…), report no, lot no, sample type (handfree / HRC…),
    // SD / reference / identifier, client — and the printed Verify
    // Code (a client reads the code off the paper report over the
    // phone; matching the code proves authenticity). Exact-match on
    // code when the term looks like one, LIKE for everything else.
    if (preg_match('/^[0-9a-f]{8}$/i', $q)) {
        $where .= " AND r.checksum_hash = ?";
        $params[] = strtolower($q);
    } else {
        $where .= " AND (r.report_number LIKE ? OR r.lot_number LIKE ? OR r.sample_type LIKE ?
                    OR r.identifier LIKE ? OR r.client_name LIKE ? OR r.format_used LIKE ?
                    OR r.checksum_hash LIKE ?
                    OR s.sd_number LIKE ? OR s.reference_number LIKE ?
                    OR s.request_number LIKE ? OR s.sample_category LIKE ? OR s.notes LIKE ?)";
        $like = '%' . $q . '%';
        $params = array_fill(0, 12, $like);
    }
}
if ($year > 0) {
    $where .= " AND YEAR(r.generated_at) = ?";
    $params[] = $year;
}

try {
    $stmt = $pdo->prepare("
        SELECT r.id, r.sample_id, r.lot_number, r.report_number, r.format_used,
               r.sample_type, r.identifier, r.client_name, r.checksum_hash,
               r.generated_at, r.dispatched_at, r.dispatch_notes, w.stage AS workflow_stage,
               l.agency_type AS lot_type
        FROM reports r
        LEFT JOIN samples s ON s.id = r.sample_id
        LEFT JOIN lots l    ON l.id = s.lot_id
        LEFT JOIN report_approvals w ON w.report_id = r.id
        WHERE $where
        ORDER BY r.generated_at DESC, r.id DESC
        LIMIT $limit
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Year list for the filter dropdown — from what's actually in the registry
    $years = $pdo->query("SELECT DISTINCT YEAR(generated_at) y FROM reports
                          WHERE is_deleted = 0 AND generated_at IS NOT NULL
                          ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode(['success' => true, 'count' => count($rows), 'years' => array_map('intval', $years), 'results' => $rows]);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Search failed', 'details' => ctms_db_error($e)]);
}
