<?php
// ============================================================
// api/invoice.php — Invoice, Quotation, Client, Price Master
//
// GET  /invoice.php?resource=invoices[&client_id=X][&status=paid]
// GET  /invoice.php?resource=quotations[&client_id=X]
// GET  /invoice.php?resource=clients[&search=Example Client]
// GET  /invoice.php?resource=price_master
// GET  /invoice.php?resource=settings
// GET  /invoice.php?resource=analytics
// GET  /invoice.php?resource=client_history&company=Example Client
//
// POST /invoice.php { resource, ...data }
// PUT  /invoice.php { resource, id, ...data }
// DELETE /invoice.php?resource=X&id=Y
// ============================================================
require_once 'db.php';

$tokenData = require_auth();
$method    = $_SERVER['REQUEST_METHOD'];
$userId    = $tokenData['user_id'];
$userRole  = $tokenData['role'];
$ip        = $_SERVER['REMOTE_ADDR'] ?? '';
if (ctms_user_lot_access_restricted($pdo, (int)$userId)) {
    http_response_code(403); echo json_encode(['error' => 'Global client and invoice records are unavailable to accounts limited to selected lots.']); exit;
}

// ── helpers ──────────────────────────────────────────────────
function getSetting($pdo, $key, $default = '') {
    $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
    $s->execute([$key]);
    $r = $s->fetch();
    return $r ? $r['setting_value'] : $default;
}

function calcItems(array $items, float $taxRate, float $discountAmount): array {
    $subtotal = 0;
    foreach ($items as &$item) {
        $price = isset($item['override_price']) && $item['override_price'] !== null && $item['override_price'] !== ''
            ? (float)$item['override_price']
            : (float)($item['unit_price'] ?? 0);
        $item['line_total'] = $price * (float)($item['quantity'] ?? 1);
        $subtotal += $item['line_total'];
    }
        unset($item);
        // Accuracy fix: float accumulation leaves dust (200.01999999999998).
        // Round the subtotal to the stored precision (decimal 12,2).
        $subtotal   = round($subtotal, 2);
        $taxable    = max(0, $subtotal - $discountAmount);
        $taxAmount  = round($taxable * $taxRate / 100, 2);
        $grandTotal = round($taxable + $taxAmount, 2);
    return [
        'items'              => $items,
        'subtotal'           => $subtotal,
        'service_tax_amount' => $taxAmount,
        'grand_total'        => $grandTotal,
    ];
}

// SEC-005: only administrators / HOD may grant invoice-level discounts
// or per-line price overrides. For any other role we neutralise those
// client-supplied fields (fail closed) and report whether the caller
// is privileged so the approver column is only stored when legitimate.
function applyPriceGuard(float $discount, array $items, string $role): array {
    $privileged = in_array($role, ['superadmin', 'admin', 'hod'], true);
    if (!$privileged) {
        $discount = 0.0;
        foreach ($items as &$it) {
            unset($it['override_price'], $it['override_reason']);
        }
        unset($it);
    }
    return [$discount, $items, $privileged];
}

function nextNumber($pdo, $prefix, $table, $column) {
    $year = date('Y');
    $key = strtolower($prefix) . '_' . $year;
    try {
        // DDL implicitly commits in MySQL. In invoice creation the table is
        // ensured before the transaction so the invoice + idempotency record
        // stay atomic.
        if (!$pdo->inTransaction()) ensureInvoiceCounterTable($pdo);

        // Sync initial counter value if table is empty for this key
        $check = $pdo->prepare("SELECT next_number FROM invoice_counters WHERE counter_key = ?");
        $check->execute([$key]);
        if (!$check->fetch()) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $column LIKE ? AND is_deleted = 0");
            $stmt->execute(["$prefix-$year-%"]);
            $initial = (int)$stmt->fetchColumn() + 1;
            $pdo->prepare("INSERT IGNORE INTO invoice_counters (counter_key, next_number) VALUES (?, ?)")
                ->execute([$key, $initial]);
        }

        $pdo->prepare("UPDATE invoice_counters SET next_number = LAST_INSERT_ID(next_number) + 1 WHERE counter_key = ?")
            ->execute([$key]);
        $seq = (int)$pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn();
        if ($seq > 0) {
            return sprintf('%s-%s-%04d', $prefix, $year, $seq);
        }
    } catch (\Throwable $e) {
        // Fallback to COUNT(*) query if atomic counter fails
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM $table WHERE $column LIKE ? AND is_deleted = 0"
    );
    $stmt->execute(["$prefix-$year-%"]);
    $seq = (int)$stmt->fetchColumn() + 1;
    return sprintf('%s-%s-%04d', $prefix, $year, $seq);
}

function ensureInvoiceCounterTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS invoice_counters (
        counter_key VARCHAR(64) PRIMARY KEY,
        next_number INT NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function auditLog($pdo, $userId, $action, $module, $recordId, $newValue, $ip) {
    try {
        $pdo->prepare("
            INSERT INTO audit_log(user_id,action,module,record_id,new_value,ip_address)
            VALUES(?,?,?,?,?,?)
        ")->execute([$userId, $action, $module, $recordId, json_encode($newValue), $ip]);
    } catch (\Exception $e) {}
}

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    $resource = $_GET['resource'] ?? '';

    // ── Invoices ─────────────────────────────────────────────
    if ($resource === 'invoices') {
        $where = ['i.is_deleted = 0'];
        $params = [];
        if (!empty($_GET['client_id']))   { $where[] = 'i.client_id = ?';   $params[] = (int)$_GET['client_id']; }
        if (!empty($_GET['status']))      { $where[] = 'i.status = ?';       $params[] = $_GET['status']; }
        if (!empty($_GET['lot_id']))      { $where[] = 'i.lot_id = ?';       $params[] = (int)$_GET['lot_id']; }
        $whereStr = implode(' AND ', $where);

        $stmt = $pdo->prepare("
            SELECT i.*,
                   u.full_name AS created_by_name,
                   uv.full_name AS verified_by_name,
                   ud.full_name AS discount_approved_name
            FROM invoices i
            LEFT JOIN users u  ON u.id  = i.created_by
            LEFT JOIN users uv ON uv.id = i.payment_verified_by
            LEFT JOIN users ud ON ud.id = i.discount_approved_by
            WHERE $whereStr
            ORDER BY i.created_at DESC
            LIMIT 200
        ");
        $stmt->execute($params);
        $invoices = $stmt->fetchAll();

        // Attach items to each invoice
        $itemStmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order");
        foreach ($invoices as &$inv) {
            $itemStmt->execute([$inv['id']]);
            $inv['items'] = $itemStmt->fetchAll();
        }
        unset($inv);

        echo json_encode($invoices);
        exit();
    }

    // ── Quotations ───────────────────────────────────────────
    if ($resource === 'quotations') {
        $where = ['q.is_deleted = 0'];
        $params = [];
        if (!empty($_GET['client_id'])) { $where[] = 'q.client_id = ?'; $params[] = (int)$_GET['client_id']; }
        if (!empty($_GET['status']))    { $where[] = 'q.status = ?';    $params[] = $_GET['status']; }
        $whereStr = implode(' AND ', $where);

        $stmt = $pdo->prepare("
            SELECT q.*, u.full_name AS created_by_name
            FROM quotations q
            LEFT JOIN users u ON u.id = q.created_by
            WHERE $whereStr ORDER BY q.created_at DESC LIMIT 200
        ");
        $stmt->execute($params);
        $quotations = $stmt->fetchAll();

        $itemStmt = $pdo->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY sort_order");
        foreach ($quotations as &$q) {
            $itemStmt->execute([$q['id']]);
            $q['items'] = $itemStmt->fetchAll();
        }
        unset($q);

        echo json_encode($quotations);
        exit();
    }

    // ── Clients ──────────────────────────────────────────────
    if ($resource === 'clients') {
        $search = trim($_GET['search'] ?? '');
        if ($search) {
            $stmt = $pdo->prepare("
                SELECT * FROM clients
                WHERE is_deleted = 0 AND company_name LIKE ?
                ORDER BY company_name ASC LIMIT 20
            ");
            $stmt->execute(['%'.$search.'%']);
        } else {
            $stmt = $pdo->query("SELECT * FROM clients WHERE is_deleted=0 ORDER BY company_name ASC");
        }
        echo json_encode($stmt->fetchAll());
        exit();
    }

    // ── Client history — previous tests + suggested items ────
    if ($resource === 'client_history') {
        $company = trim($_GET['company'] ?? '');
        if (!$company) { echo json_encode([]); exit(); }

        // Find last invoice for this company name (fuzzy match)
        $stmt = $pdo->prepare("
            SELECT i.id, i.inv_number, i.grand_total, i.status, i.created_at,
                   i.company_name, i.contact_person
            FROM invoices i
            WHERE i.is_deleted = 0 AND i.company_name LIKE ?
            ORDER BY i.created_at DESC LIMIT 5
        ");
        $stmt->execute(['%'.$company.'%']);
        $lastInvoices = $stmt->fetchAll();

        // Get their most-used line items for auto-suggest
        $suggestStmt = $pdo->prepare("
            SELECT ii.service_code, ii.description,
                   ii.unit_price, ii.override_price,
                   COUNT(*) AS times_ordered
            FROM invoice_items ii
            JOIN invoices i ON i.id = ii.invoice_id
            WHERE i.is_deleted = 0 AND i.company_name LIKE ?
            GROUP BY ii.service_code, ii.description, ii.unit_price, ii.override_price
            ORDER BY times_ordered DESC LIMIT 10
        ");
        $suggestStmt->execute(['%'.$company.'%']);
        $suggestions = $suggestStmt->fetchAll();

        // Analytics summary for this company
        $analyticsStmt = $pdo->prepare("
            SELECT
                COUNT(*)                    AS total_invoices,
                SUM(grand_total)            AS lifetime_value,
                SUM(CASE WHEN status='paid' THEN grand_total ELSE 0 END) AS total_paid,
                MAX(created_at)             AS last_visit
            FROM invoices
            WHERE is_deleted = 0 AND company_name LIKE ?
        ");
        $analyticsStmt->execute(['%'.$company.'%']);
        $analytics = $analyticsStmt->fetch();

        echo json_encode([
            'last_invoices' => $lastInvoices,
            'suggestions'   => $suggestions,
            'analytics'     => $analytics,
        ]);
        exit();
    }

    // ── Price master ─────────────────────────────────────────
    if ($resource === 'price_master') {
        $stmt = $pdo->query("SELECT * FROM price_master WHERE is_active=1 ORDER BY category, service_name");
        echo json_encode($stmt->fetchAll());
        exit();
    }

    // ── System settings ──────────────────────────────────────
    if ($resource === 'settings') {
        $stmt = $pdo->query("SELECT * FROM system_settings ORDER BY setting_key");
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_value'];
        echo json_encode($out);
        exit();
    }

    // ── Client analytics view ────────────────────────────────
    if ($resource === 'analytics') {
        try {
            $stmt = $pdo->query("SELECT * FROM client_analytics ORDER BY total_paid DESC LIMIT 100");
            echo json_encode($stmt->fetchAll());
        } catch (\PDOException $e) {
            echo json_encode(['error' => ctms_db_error($e)]);
        }
        exit();
    }

    http_response_code(400);
    echo json_encode(['error' => "Unknown resource: $resource"]);
    exit();
}

// ── POST ─────────────────────────────────────────────────────
if ($method === 'POST') {
    $data     = json_decode(file_get_contents('php://input'), true);
    $resource = $data['resource'] ?? '';

    // ── Create client ────────────────────────────────────────
    if ($resource === 'client') {
        $company = trim($data['company_name'] ?? '');
        if (!$company) { http_response_code(400); echo json_encode(['error'=>'company_name required']); exit(); }
        $pdo->prepare("
            INSERT INTO clients(company_name,contact_person,phone,email,address,is_special_client,notes)
            VALUES(?,?,?,?,?,?,?)
        ")->execute([
            $company,
            trim($data['contact_person'] ?? ''),
            trim($data['phone'] ?? ''),
            trim($data['email'] ?? ''),
            trim($data['address'] ?? ''),
            (int)($data['is_special_client'] ?? 0),
            trim($data['notes'] ?? ''),
        ]);
        $newId = (int)$pdo->lastInsertId();
        auditLog($pdo, $userId, 'CLIENT_CREATED', 'clients', $newId, ['company_name'=>$company], $ip);
        echo json_encode(['success'=>true,'id'=>$newId]);
        exit();
    }

    // ── Create quotation ─────────────────────────────────────
    if ($resource === 'quotation') {
        $taxRate     = (float)getSetting($pdo, 'service_tax_rate', '13');
        $discount    = (float)($data['discount_amount'] ?? 0);
        $items       = $data['items'] ?? [];
        // SEC-005: strip discounts / price overrides for non-privileged users.
        [$discount, $items, $canDiscount] = applyPriceGuard($discount, $items, $userRole);
        $prefix      = getSetting($pdo, 'quotation_prefix', 'QUO');
        $quoNumber   = nextNumber($pdo, $prefix, 'quotations', 'quo_number');
        $calc        = calcItems($items, $taxRate, $discount);

        $pdo->prepare("
            INSERT INTO quotations(
                quo_number, client_id, lot_id, company_name, contact_person,
                created_by, status, valid_until,
                subtotal, service_tax_rate, service_tax_amount,
                discount_amount, discount_reason, discount_approved_by,
                grand_total, notes
            ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $quoNumber,
            $data['client_id'] ?? null,
            $data['lot_id'] ?? null,
            trim($data['company_name'] ?? ''),
            trim($data['contact_person'] ?? ''),
            $userId,
            'draft',
            $data['valid_until'] ?? null,
            $calc['subtotal'],
            $taxRate,
            $calc['service_tax_amount'],
            $discount,
            trim($data['discount_reason'] ?? ''),
            $canDiscount ? ($data['discount_approved_by'] ?? null) : null,
            $calc['grand_total'],
            trim($data['notes'] ?? ''),
        ]);
        $newId = (int)$pdo->lastInsertId();

        $itemStmt = $pdo->prepare("
            INSERT INTO quotation_items(quotation_id,service_code,description,quantity,unit_price,override_price,override_reason,line_total,sort_order)
            VALUES(?,?,?,?,?,?,?,?,?)
        ");
        foreach ($calc['items'] as $i => $item) {
            $itemStmt->execute([
                $newId,
                $item['service_code'] ?? null,
                $item['description'] ?? '',
                $item['quantity'] ?? 1,
                $item['unit_price'] ?? 0,
                isset($item['override_price']) && $item['override_price'] !== '' ? $item['override_price'] : null,
                $item['override_reason'] ?? null,
                $item['line_total'],
                $i,
            ]);
        }

        auditLog($pdo, $userId, 'QUOTATION_CREATED', 'quotations', $newId, [
            'quo_number' => $quoNumber, 'company' => $data['company_name'], 'grand_total' => $calc['grand_total']
        ], $ip);

        echo json_encode(['success'=>true, 'id'=>$newId, 'quo_number'=>$quoNumber, 'totals'=>$calc]);
        exit();
    }

    // ── Create invoice (or convert from quotation) ───────────
    if ($resource === 'invoice') {
        $idempotencyKey = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
        $requestHash = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        if ($idempotencyKey !== '' && !preg_match('/^[A-Za-z0-9._:-]{16,80}$/', $idempotencyKey)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid invoice request key. Please retry from the invoice form.']);
            exit();
        }
        $taxRate   = (float)getSetting($pdo, 'service_tax_rate', '13');
        $discount  = (float)($data['discount_amount'] ?? 0);
        $items     = $data['items'] ?? [];
        // SEC-005: strip discounts / price overrides for non-privileged users.
        [$discount, $items, $canDiscount] = applyPriceGuard($discount, $items, $userRole);
        if (empty($items) || !is_array($items)) {
            http_response_code(400);
            echo json_encode(['error' => 'At least one line item is required']);
            exit();
        }
        // Audit fix A7: server-side item validation — the frontend checks
        // are advisory. Quantity must be a positive number, unit price
        // non-negative, and every item needs a description.
        foreach ($items as $ix => $item) {
            $q = (float)($item['quantity'] ?? 0);
            $p = (float)($item['unit_price'] ?? 0);
            if (trim((string)($item['description'] ?? '')) === '') {
                http_response_code(400); echo json_encode(['error' => "Item #" . ($ix + 1) . ": description required"]); exit();
            }
            if ($q <= 0) {
                http_response_code(400); echo json_encode(['error' => "Item #" . ($ix + 1) . ": quantity must be greater than zero"]); exit();
            }
            if ($p < 0) {
                http_response_code(400); echo json_encode(['error' => "Item #" . ($ix + 1) . ": unit price cannot be negative"]); exit();
            }
        }
        // Audit fix A20: a quotation can only be converted ONCE, and only
        // from 'accepted'. Replays can no longer mint duplicate invoices.
        $quoId = (int)($data['quotation_id'] ?? $data['quo_id'] ?? 0);
        if ($quoId) {
            if (!in_array($userRole, ['admin', 'superadmin', 'hod'], true)) {
                http_response_code(403); echo json_encode(['error' => 'Permission denied: Only administrators can convert quotations.']); exit();
            }
        }
        $calc      = calcItems($items, $taxRate, $discount);

        $clientId  = !empty($data['client_id'])  ? (int)$data['client_id']  : null;
        $lotId     = !empty($data['lot_id'])     ? (int)$data['lot_id']     : null;
        $quoId     = (int)($data['quotation_id'] ?? $data['quo_id'] ?? 0) ?: null;
        $discAppr  = ($canDiscount && !empty($data['discount_approved_by'])) ? (int)$data['discount_approved_by'] : null;

        try {
            if ($idempotencyKey !== '') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS api_idempotency_keys (
                    user_id INT NOT NULL,
                    idempotency_key VARCHAR(80) NOT NULL,
                    request_hash CHAR(64) NOT NULL,
                    response_json LONGTEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id, idempotency_key),
                    KEY idx_api_idempotency_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
            // Ensure counter schema before beginning the atomic invoice
            // transaction; CREATE TABLE inside it would implicitly commit.
            ensureInvoiceCounterTable($pdo);
            $pdo->beginTransaction();
            if ($idempotencyKey !== '') {
                try {
                    $pdo->prepare('INSERT INTO api_idempotency_keys (user_id, idempotency_key, request_hash) VALUES (?, ?, ?)')
                        ->execute([$userId, $idempotencyKey, $requestHash]);
                } catch (PDOException $keyError) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    if ((string)$keyError->getCode() !== '23000') throw $keyError;
                    $saved = $pdo->prepare('SELECT request_hash, response_json FROM api_idempotency_keys WHERE user_id = ? AND idempotency_key = ?');
                    $saved->execute([$userId, $idempotencyKey]);
                    $prior = $saved->fetch(PDO::FETCH_ASSOC);
                    if (!$prior) throw $keyError;
                    if (!hash_equals((string)$prior['request_hash'], $requestHash)) {
                        http_response_code(409);
                        echo json_encode(['error' => 'This invoice submission key was already used for different data. Reopen the invoice form to start a new submission.']);
                        exit();
                    }
                    if (empty($prior['response_json'])) {
                        http_response_code(409);
                        echo json_encode(['error' => 'The earlier invoice submission is still being finalized. Check the invoice list before retrying.']);
                        exit();
                    }
                    echo (string)$prior['response_json'];
                    exit();
                }
            }

            // Lock and re-check accepted quotations inside the invoice transaction;
            // concurrent requests cannot convert the same quotation twice.
            if ($quoId) {
                $qs = $pdo->prepare('SELECT status FROM quotations WHERE id = ? AND is_deleted = 0 FOR UPDATE');
                $qs->execute([$quoId]);
                $qStatus = (string)$qs->fetchColumn();
                if ($qStatus !== 'accepted') {
                    $pdo->rollBack();
                    http_response_code(409);
                    echo json_encode(['error' => "Quotation is '$qStatus', not accepted — it may already have been converted."]);
                    exit();
                }
            }

            $prefix    = getSetting($pdo, 'invoice_prefix', 'INV');
            $invNumber = nextNumber($pdo, $prefix, 'invoices', 'inv_number');
            $pdo->prepare("
                INSERT INTO invoices(
                    inv_number, quotation_id, client_id, lot_id,
                    company_name, contact_person, created_by, status,
                    subtotal, service_tax_rate, service_tax_amount,
                    discount_amount, discount_reason, discount_approved_by,
                    grand_total, due_date, notes, issued_at
                ) VALUES(?,?,?,?,?,?,?,'issued',?,?,?,?,?,?,?,?,?,NOW())
            ")->execute([
                $invNumber,
                $quoId,
                $clientId,
                $lotId,
                trim($data['company_name'] ?? ''),
                trim($data['contact_person'] ?? ''),
                $userId,
                $calc['subtotal'],
                $taxRate,
                $calc['service_tax_amount'],
                $discount,
                trim($data['discount_reason'] ?? ''),
                $discAppr,
                $calc['grand_total'],
                !empty($data['due_date']) ? $data['due_date'] : null,
                trim($data['notes'] ?? ''),
            ]);
            $newId = (int)$pdo->lastInsertId();

            $itemStmt = $pdo->prepare("
                INSERT INTO invoice_items(invoice_id,service_code,description,quantity,unit_price,override_price,override_reason,line_total,sort_order)
                VALUES(?,?,?,?,?,?,?,?,?)
            ");
            foreach ($calc['items'] as $i => $item) {
                $itemStmt->execute([
                    $newId,
                    $item['service_code'] ?? null,
                    $item['description'] ?? '',
                    $item['quantity'] ?? 1,
                    $item['unit_price'] ?? 0,
                    isset($item['override_price']) && $item['override_price'] !== '' ? $item['override_price'] : null,
                    $item['override_reason'] ?? null,
                    $item['line_total'],
                    $i,
                ]);
            }

            if ($quoId) {
                $converted = $pdo->prepare("UPDATE quotations SET status='converted', converted_to_invoice=? WHERE id=? AND status='accepted'");
                $converted
                    ->execute([$newId, $quoId]);
                if ($converted->rowCount() !== 1) throw new RuntimeException('The quotation was already converted by another request.');
            }

            if ($clientId && !empty($data['contact_person'])) {
                $pdo->prepare("UPDATE clients SET contact_person=? WHERE id=?")
                    ->execute([trim($data['contact_person']), $clientId]);
            }

            $response = ['success'=>true, 'id'=>$newId, 'inv_number'=>$invNumber, 'totals'=>$calc];
            if ($idempotencyKey !== '') {
                $pdo->prepare('UPDATE api_idempotency_keys SET response_json = ? WHERE user_id = ? AND idempotency_key = ?')
                    ->execute([json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $userId, $idempotencyKey]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[CTMS invoice] create failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => $e instanceof PDOException ? ctms_db_error($e, 'Could not create invoice') : 'Could not create invoice. Check the invoice list before retrying.']);
            exit();
        }

        auditLog($pdo, $userId, 'INVOICE_CREATED', 'invoices', $newId, [
            'inv_number' => $invNumber, 'company' => $data['company_name'], 'grand_total' => $calc['grand_total']
        ], $ip);

        echo json_encode($response);
        exit();
    }

    // ── Update price master entry ─────────────────────────────
    if ($resource === 'price_master_update') {
        if (!in_array($userRole, ['admin','superadmin'])) { http_response_code(403); echo json_encode(['error'=>'Admin only']); exit(); }
        $id    = (int)($data['id'] ?? 0);
        $price = (float)($data['unit_price'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit(); }
        $pdo->prepare("UPDATE price_master SET unit_price=?, updated_by=? WHERE id=?")
            ->execute([$price, $userId, $id]);
        auditLog($pdo, $userId, 'PRICE_UPDATED', 'price_master', $id, ['unit_price'=>$price], $ip);
        echo json_encode(['success'=>true]);
        exit();
    }

    // ── Update system setting ─────────────────────────────────
    if ($resource === 'setting') {
        if (!in_array($userRole, ['admin','superadmin'])) { http_response_code(403); echo json_encode(['error'=>'Admin only']); exit(); }
        $key = trim($data['setting_key'] ?? '');
        $val = trim($data['setting_value'] ?? '');
        if (!$key) { http_response_code(400); echo json_encode(['error'=>'setting_key required']); exit(); }
        $pdo->prepare("UPDATE system_settings SET setting_value=?, updated_by=? WHERE setting_key=?")
            ->execute([$val, $userId, $key]);
        auditLog($pdo, $userId, 'SETTING_UPDATED', 'settings', 0, ['key'=>$key,'value'=>$val], $ip);
        echo json_encode(['success'=>true]);
        exit();
    }

    http_response_code(400);
    echo json_encode(['error' => "Unknown resource: $resource"]);
    exit();
}

// ── PUT — status changes, payment marking, etc. ───────────────
if ($method === 'PUT') {
    $data     = json_decode(file_get_contents('php://input'), true);
    $resource = $data['resource'] ?? '';
    $id       = (int)($data['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit(); }

    // ── Mark invoice paid ─────────────────────────────────────
    if ($resource === 'invoice_pay') {
        if (!in_array($userRole, ['admin', 'superadmin', 'hod'], true)) {
            http_response_code(403);
            echo json_encode(['error' => 'Permission denied: Only administrators can verify and mark invoices as paid.']);
            exit();
        }
        // Audit fix A1: a payment record needs a real method, reference
        // and date. 'credit' is not a payment — it is an unpaid sale.
        $method = trim($data['payment_method']    ?? 'bank_voucher');
        $ref    = trim($data['payment_reference'] ?? '');
        $pdate  = trim($data['payment_date']      ?? '');
        if ($method === '' || $method === 'credit') {
            http_response_code(400); echo json_encode(['error' => 'Select an actual payment method (credit is not a payment).']); exit();
        }
        if ($ref === '') {
            http_response_code(400); echo json_encode(['error' => 'Payment reference / voucher number is required.']); exit();
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pdate)) {
            http_response_code(400); echo json_encode(['error' => 'A valid payment date is required.']); exit();
        }
        $paymentUpdate = $pdo->prepare("
            UPDATE invoices SET
                status = 'paid',
                payment_method    = ?,
                payment_reference = ?,
                payment_date      = ?,
                payment_notes     = ?,
                payment_verified_by = ?,
                updated_at        = NOW()
            WHERE id = ? AND is_deleted = 0 AND status <> 'paid'
        ");
        $paymentUpdate->execute([
            $method,
            $ref,
            $pdate,
            $data['payment_notes']     ?? '',
            $userId,
            $id,
        ]);
        if ($paymentUpdate->rowCount() !== 1) {
            http_response_code(409);
            echo json_encode(['error' => 'This invoice is already paid or no longer available. Check the payment record before attempting another update.']);
            exit();
        }
        auditLog($pdo, $userId, 'INVOICE_PAID', 'invoices', $id, [
            'method' => $method, 'ref' => $ref
        ], $ip);
        echo json_encode(['success'=>true, 'message'=>'Invoice marked as paid.']);
        exit();
    }

    // ── Update quotation status ───────────────────────────────
    if ($resource === 'quotation_status') {
        // Audit fix: this was UNGATED — any authenticated user could
        // accept a quotation (a commercial commitment). Now gated the
        // same way as invoice_status.
        if (!in_array($userRole, ['admin', 'superadmin', 'hod'], true)) {
            http_response_code(403);
            echo json_encode(['error' => 'Permission denied: Only administrators can update quotation status.']);
            exit();
        }
        $status = $data['status'] ?? '';
        $allowed = ['draft','sent','accepted','rejected'];
        if (!in_array($status, $allowed)) { http_response_code(400); echo json_encode(['error'=>"Invalid status: $status"]); exit(); }
        $pdo->prepare("UPDATE quotations SET status=?, updated_at=NOW() WHERE id=?")->execute([$status, $id]);
        auditLog($pdo, $userId, 'QUOTATION_STATUS_CHANGED', 'quotations', $id, ['status'=>$status], $ip);
        echo json_encode(['success'=>true]);
        exit();
    }

    // ── Update invoice status (cancel, overdue, etc.) ─────────
    if ($resource === 'invoice_status') {
        if (!in_array($userRole, ['admin', 'superadmin', 'hod'], true)) {
            http_response_code(403);
            echo json_encode(['error' => 'Permission denied: Only administrators can update invoice status.']);
            exit();
        }
        $status  = $data['status'] ?? '';
        $allowed = ['draft','issued','paid','overdue','cancelled'];
        if (!in_array($status, $allowed)) { http_response_code(400); echo json_encode(['error'=>"Invalid status: $status"]); exit(); }
        $pdo->prepare("UPDATE invoices SET status=?, updated_at=NOW() WHERE id=?")->execute([$status, $id]);
        auditLog($pdo, $userId, 'INVOICE_STATUS_CHANGED', 'invoices', $id, ['status'=>$status], $ip);
        echo json_encode(['success'=>true]);
        exit();
    }

    http_response_code(400);
    echo json_encode(['error' => "Unknown resource: $resource"]);
    exit();
}

// ── DELETE — soft-delete ──────────────────────────────────────
if ($method === 'DELETE') {
    if (!in_array($userRole, ['admin', 'superadmin'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Permission denied: Administrator privileges required for deletion.']);
        exit();
    }
    $resource = $_GET['resource'] ?? '';
    $id       = (int)($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit(); }

    if ($resource === 'invoice') {
        $pdo->prepare("UPDATE invoices SET is_deleted=1 WHERE id=?")->execute([$id]);
        auditLog($pdo, $userId, 'INVOICE_DELETED', 'invoices', $id, [], $ip);
        echo json_encode(['success'=>true]);
        exit();
    }
    if ($resource === 'quotation') {
        $pdo->prepare("UPDATE quotations SET is_deleted=1 WHERE id=?")->execute([$id]);
        auditLog($pdo, $userId, 'QUOTATION_DELETED', 'quotations', $id, [], $ip);
        echo json_encode(['success'=>true]);
        exit();
    }
    if ($resource === 'client') {
        $pdo->prepare("UPDATE clients SET is_deleted=1 WHERE id=?")->execute([$id]);
        echo json_encode(['success'=>true]);
        exit();
    }

    http_response_code(400);
    echo json_encode(['error' => "Unknown resource: $resource"]);
    exit();
}

http_response_code(405);
echo json_encode(['error'=>'Method not allowed']);
?>
