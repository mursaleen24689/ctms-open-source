<?php
// ============================================================
// api/finance.php — Example Client lump-sum receipts, ageing, TAT board,
//                   lot templates, equipment calibration register.
//
// GET  ?resource=receipts                       → pool + allocation summary
// GET  ?resource=ageing                         → per-client outstanding w/ buckets
// GET  ?resource=tat       &overdue=1|0         → TAT board (due today/overdue/all)
// GET  ?resource=templates                     → saved lot templates
// GET  ?resource=equipment  &overdue=1|0        → calibration register
//
// POST { resource:'receipt',  ... }             → add lump-sum receipt
// POST { resource:'allocate', receipt_id, lot_id, samples, amount } → allocate
// POST { resource:'template', ... }             → save lot template
// POST { resource:'equipment', ... }            → add/update equipment
// POST { resource:'equipment_delete', id }      → soft-delete equipment
// ============================================================
require_once 'db.php';

$tokenData = require_auth();
$method    = $_SERVER['REQUEST_METHOD'];
$userId    = $tokenData['user_id'];
if (ctms_user_lot_access_restricted($pdo, (int)$userId)) {
    http_response_code(403); echo json_encode(['error' => 'Global finance and operations data is unavailable to accounts limited to selected lots.']); exit;
}

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    $resource = $_GET['resource'] ?? '';

    // QA fix: the receipts pool contains commercial amounts — finance
    // data is management-only. (Found by the automated role matrix.)
    if ($resource === 'receipts' || $resource === 'ageing') {
        ctms_require_role(['superadmin', 'admin', 'hod'], $tokenData,
            'Finance data is restricted to management roles.');
    }

    // ── Receipts pool ─────────────────────────────────────────
    if ($resource === 'receipts') {
        try {
            $receipts = $pdo->query("
                SELECT r.*,
                       (SELECT COUNT(*) FROM samples s JOIN lots l2 ON s.lot_id = l2.id
                        WHERE l2.paid_receipt_id = r.id AND s.is_deleted = 0 AND l2.is_deleted = 0) AS samples_used
                FROM lump_receipts r
                WHERE r.is_deleted = 0
                ORDER BY r.receipt_date DESC, r.id DESC
            ")->fetchAll(PDO::FETCH_ASSOC);

            // Example Client lots awaiting allocation (created, unpaid, not yet tied to a receipt)
            $pending = $pdo->query("
                SELECT l.id, l.lot_number, l.created_at,
                       (SELECT COUNT(*) FROM samples s WHERE s.lot_id = l.id AND s.is_deleted = 0) AS samples,
                       l.payment_status, l.paid_receipt_id
                FROM lots l
                WHERE l.agency_type IN ('ONLINE','MIXED') AND l.is_deleted = 0
                  AND l.payment_status = 'unpaid'
                ORDER BY l.created_at DESC LIMIT 200
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'receipts' => $receipts, 'pending_lots' => $pending]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed', 'details' => ctms_db_error($e)]);
        }
        exit();
    }

    // ── Outstanding ageing (invoice clients) ──────────────────
    if ($resource === 'ageing') {
        try {
            // Buckets by invoice creation date for unpaid invoices.
            $rows = $pdo->query("
                SELECT i.id, i.inv_number, i.company_name, i.grand_total, i.created_at, i.status,
                       DATEDIFF(CURDATE(), DATE(i.created_at)) AS age_days
                FROM invoices i
                WHERE i.is_deleted = 0 AND i.status NOT IN ('paid','cancelled')
                ORDER BY i.created_at ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            $clients = [];
            foreach ($rows as $r) {
                $c = $r['company_name'] ?: '—';
                if (!isset($clients[$c])) {
                    $clients[$c] = ['company' => $c, 'total' => 0.0, 'b0' => 0.0, 'b30' => 0.0, 'b60' => 0.0, 'b90' => 0.0, 'oldest' => null, 'invoices' => 0];
                }
                $amt = (float)$r['grand_total'];
                $age = (int)$r['age_days'];
                $clients[$c]['total']   += $amt;
                $clients[$c]['invoices'] += 1;
                if      ($age < 30) $clients[$c]['b0']  += $amt;
                else if ($age < 60) $clients[$c]['b30'] += $amt;
                else if ($age < 90) $clients[$c]['b60'] += $amt;
                else                $clients[$c]['b90'] += $amt;
                if ($clients[$c]['oldest'] === null || $age > $clients[$c]['oldest']) $clients[$c]['oldest'] = $age;
            }
            echo json_encode(['success' => true, 'clients' => array_values($clients)]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed', 'details' => ctms_db_error($e)]);
        }
        exit();
    }

    // ── TAT board ─────────────────────────────────────────────
    if ($resource === 'tat') {
        try {
            // Open lots with a promised date: due today or overdue are
            // highlighted; completed = all samples through data_entry.
            $rows = $pdo->query("
                SELECT l.id, l.lot_number, l.agency_type, l.lot_date, l.promised_date, l.sla_days, l.status,
                       (SELECT COUNT(*) FROM samples s WHERE s.lot_id = l.id AND s.is_deleted = 0) AS total_samples,
                       (SELECT COUNT(*) FROM samples s WHERE s.lot_id = l.id AND s.is_deleted = 0
                          AND s.status IN ('data_entry','dispatched')) AS done_samples,
                       DATEDIFF(CURDATE(), l.promised_date) AS days_over
                FROM lots l
                WHERE l.is_deleted = 0
                  AND (l.status IS NULL OR l.status NOT IN ('archived'))
                  AND l.promised_date IS NOT NULL
                ORDER BY l.promised_date ASC
                LIMIT 300
            ")->fetchAll(PDO::FETCH_ASSOC);
            $onlyOverdue = (($_GET['overdue'] ?? '0') === '1');
            if ($onlyOverdue) {
                $rows = array_values(array_filter($rows, function ($r) {
                    return ((int)$r['days_over'] >= 0) && ((int)$r['done_samples'] < (int)$r['total_samples']);
                }));
            }
            echo json_encode(['success' => true, 'lots' => $rows]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed', 'details' => ctms_db_error($e)]);
        }
        exit();
    }

    // ── Lot templates ─────────────────────────────────────────
    if ($resource === 'templates') {
        try {
            $rows = $pdo->query("SELECT * FROM lot_templates WHERE is_deleted = 0 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'templates' => $rows]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed', 'details' => ctms_db_error($e)]);
        }
        exit();
    }

    // ── Equipment calibration register ────────────────────────
    if ($resource === 'equipment') {
        try {
            $rows = $pdo->query("
                SELECT *, DATEDIFF(next_due, CURDATE()) AS days_to_due
                FROM equipment WHERE is_deleted = 0
                ORDER BY next_due IS NULL, next_due ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'equipment' => $rows]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed', 'details' => ctms_db_error($e)]);
        }
        exit();
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown resource']);
    exit();
}

// ── POST ──────────────────────────────────────────────────────
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $resource = $data['resource'] ?? '';

    $writeRolesByResource = [
        'receipt'          => ['superadmin', 'admin', 'hod'],
        'allocate'         => ['superadmin', 'admin', 'hod'],
        'template'         => ['superadmin', 'admin', 'hod'],
        'equipment'        => ['superadmin', 'admin', 'hod'],
        'equipment_delete' => ['superadmin', 'admin', 'hod'],
    ];
    $allowedWriteRoles = $writeRolesByResource[$resource] ?? [];
    if (!$allowedWriteRoles || !in_array($tokenData['role'] ?? '', $allowedWriteRoles, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'This finance or operations change is restricted to management roles.']);
        exit();
    }

    // Add a lump-sum receipt (e.g. Example Client Rs. 338,000 @ 5,200 → 65 samples)
    if ($resource === 'receipt') {
        $amount = (float)($data['amount'] ?? 0);
        if ($amount <= 0) { http_response_code(400); echo json_encode(['error' => 'amount required']); exit(); }
        $rate = (float)($data['rate_per_sample'] ?? 0);
        $covered = (int)($data['samples_covered'] ?? 0);
        if ($covered <= 0 && $rate > 0) $covered = (int)floor($amount / $rate);
        try {
            $st = $pdo->prepare("INSERT INTO lump_receipts (payer, amount, rate_per_sample, samples_covered, reference, receipt_date, note, created_by)
                                 VALUES (?,?,?,?,?,?,?,?)");
            $st->execute([
                trim($data['payer'] ?? 'Example Client') ?: 'Example Client',
                $amount, $rate, $covered,
                trim($data['reference'] ?? ''), $data['receipt_date'] ?? date('Y-m-d'),
                trim($data['note'] ?? ''), $userId,
            ]);
            echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'samples_covered' => $covered]);
        } catch (\PDOException $e) {
            http_response_code(500); echo json_encode(['error' => 'Failed', 'details' => ctms_db_error($e)]);
        }
        exit();
    }

    // Allocate a receipt's coverage to specific lots
    if ($resource === 'allocate') {
        $receipt_id = (int)($data['receipt_id'] ?? 0);
        $lot_id     = (int)($data['lot_id'] ?? 0);
        if (!$receipt_id || !$lot_id) { http_response_code(400); echo json_encode(['error' => 'receipt_id and lot_id required']); exit(); }
        try {
            // SEC-011: lock the receipt row for the whole
            // check-then-update so two concurrent allocations can never
            // both pass the capacity test (TOCTOU race).
            $pdo->beginTransaction();

            // A3: never allocate a lot that is already covered — a stale
            // pool display could otherwise double-count it.
            $st = $pdo->prepare("SELECT payment_status, paid_receipt_id FROM lots WHERE id = ? AND is_deleted = 0");
            $st->execute([$lot_id]);
            $lotRow = $st->fetch(PDO::FETCH_ASSOC);
            if (!$lotRow) { $pdo->rollBack(); http_response_code(404); echo json_encode(['error' => 'Lot not found']); exit(); }
            if ($lotRow['payment_status'] !== 'unpaid' || $lotRow['paid_receipt_id']) {
                $pdo->rollBack();
                http_response_code(409); echo json_encode(['error' => 'This lot is already covered by a receipt. Refresh the page.']); exit();
            }

            $st = $pdo->prepare("SELECT samples_covered FROM lump_receipts WHERE id = ? AND is_deleted = 0 FOR UPDATE");
            $st->execute([$receipt_id]);
            $covered = (int)$st->fetchColumn();
            if ($covered <= 0) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Receipt not found']);
                exit();
            }

            // How many samples does this lot hold?
            $st = $pdo->prepare("SELECT COUNT(*) FROM samples WHERE lot_id = ? AND is_deleted = 0");
            $st->execute([$lot_id]);
            $samples = (int)$st->fetchColumn();

            // How many samples of this receipt are still unallocated?
            $st = $pdo->prepare("SELECT COALESCE(SUM((SELECT COUNT(*) FROM samples s WHERE s.lot_id = l.id AND s.is_deleted = 0)),0)
                                 FROM lots l WHERE l.paid_receipt_id = ? AND l.is_deleted = 0");
            $st->execute([$receipt_id]);
            $used = (int)$st->fetchColumn();
            $free = $covered - $used;

            if ($samples > $free) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['error' => "Receipt only covers $free more sample(s); lot has $samples."]);
                exit();
            }
            $pdo->prepare("UPDATE lots SET payment_status = 'special_client_pool', paid_receipt_id = ? WHERE id = ?")
                ->execute([$receipt_id, $lot_id]);
            $pdo->commit();
            echo json_encode(['success' => true, 'allocated_samples' => $samples, 'remaining' => $free - $samples]);
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500); echo json_encode(['error' => ctms_db_error($e)]);
        }
        exit();
    }

    // Save a lot template
    if ($resource === 'template') {
        $name = trim($data['name'] ?? '');
        if ($name === '') { http_response_code(400); echo json_encode(['error' => 'name required']); exit(); }
        try {
            $st = $pdo->prepare("INSERT INTO lot_templates (name, lot_type, customer_name, item_desc, grade_match, sla_days, created_by)
                                 VALUES (?,?,?,?,?,?,?)");
            $st->execute([$name, strtoupper($data['lot_type'] ?? 'CHEQUE'),
                          trim($data['customer_name'] ?? ''), trim($data['item_desc'] ?? ''),
                          trim($data['grade_match'] ?? ''), (int)($data['sla_days'] ?? 0) ?: null, $userId]);
            echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        } catch (\PDOException $e) {
            http_response_code(500); echo json_encode(['error' => 'Failed', 'details' => ctms_db_error($e)]);
        }
        exit();
    }

    // Add / update equipment
    if ($resource === 'equipment') {
        $id = (int)($data['id'] ?? 0);
        $name = trim($data['name'] ?? '');
        if ($name === '') { http_response_code(400); echo json_encode(['error' => 'name required']); exit(); }
        // optional date fields — '' or missing become NULL
        $lastCal = trim((string)($data['last_calibrated'] ?? '')) ?: null;
        $nextDue = trim((string)($data['next_due'] ?? '')) ?: null;
        try {
            if ($id) {
                $st = $pdo->prepare("UPDATE equipment SET name=?, instrument_type=?, serial_no=?, location=?,
                                     last_calibrated=?, next_due=?, cert_no=?, notes=?, updated_at=NOW() WHERE id=?");
                $st->execute([$name, trim($data['instrument_type'] ?? ''), trim($data['serial_no'] ?? ''),
                              trim($data['location'] ?? ''), $lastCal, $nextDue,
                              trim($data['cert_no'] ?? ''), trim($data['notes'] ?? ''), $id]);
                echo json_encode(['success' => true, 'id' => $id]);
            } else {
                $st = $pdo->prepare("INSERT INTO equipment (name, instrument_type, serial_no, location, last_calibrated, next_due, cert_no, notes)
                                     VALUES (?,?,?,?,?,?,?,?)");
                $st->execute([$name, trim($data['instrument_type'] ?? ''), trim($data['serial_no'] ?? ''),
                              trim($data['location'] ?? ''), $lastCal, $nextDue,
                              trim($data['cert_no'] ?? ''), trim($data['notes'] ?? '')]);
                echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
            }
        } catch (\PDOException $e) {
            http_response_code(500); echo json_encode(['error' => 'Failed', 'details' => ctms_db_error($e)]);
        }
        exit();
    }

    if ($resource === 'equipment_delete') {
        $id = (int)($data['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); exit(); }
        try {
            $pdo->prepare("UPDATE equipment SET is_deleted = 1 WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (\PDOException $e) {
            http_response_code(500); echo json_encode(['error' => 'Failed', 'details' => ctms_db_error($e)]);
        }
        exit();
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown resource']);
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
