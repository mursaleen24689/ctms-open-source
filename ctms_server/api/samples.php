<?php
// ============================================================
// api/samples.php — Samples REST Endpoint
//
// POST /samples.php  → Bulk-create samples for a lot
//                      Body: { lot_id, lot_type, samples: [{...}, {...}] }
//
// PUT  /samples.php  → Bulk-update sample statuses (routing)
//                      Body: { sample_ids: [1,2,3], status: "in_testing_tensile", lab_stream: "..." }
//
// GET  /samples.php?lot_id=X → Get samples for one specific lot
// ============================================================
require_once 'db.php';

$tokenData = require_auth();
$method    = $_SERVER['REQUEST_METHOD'];

// ── GET — Fetch samples for a lot ────────────────────────────
if ($method === 'GET') {
    $lot_id = (int)($_GET['lot_id'] ?? 0);
    if (!$lot_id) {
        http_response_code(400);
        echo json_encode(["error" => "lot_id required"]);
        exit();
    }
    ctms_require_lot_access($pdo, (int)$tokenData['user_id'], $lot_id, 'view');

    $stmt = $pdo->prepare("
        SELECT id, lot_id, sd_number, request_number, reference_number,
               client_name, agency_type, sample_category, status,
               delivery_type, notes, extra_data, created_at
        FROM samples
        WHERE lot_id = ? AND is_deleted = 0
        ORDER BY id ASC
    ");
    $stmt->execute([$lot_id]);
    $samples = $stmt->fetchAll();

    foreach ($samples as &$s) {
        if (!empty($s['extra_data'])) {
            $decoded = json_decode($s['extra_data'], true);
            $s['extra_data'] = is_array($decoded) ? $decoded : [];
        }
    }
    unset($s);

    echo json_encode($samples);
    exit();
}

// ── POST — Bulk-create samples for a lot ─────────────────────
if ($method === 'POST') {
    if (!in_array($tokenData['role'] ?? '', ['superadmin', 'admin', 'reception', 'pa', 'mapping', 'hod'], true)) {
        http_response_code(403);
        echo json_encode(["error" => "Permission denied: Intake/Reception or Administrator privileges required to create samples."]);
        exit();
    }
    $data = json_decode(file_get_contents('php://input'), true);

    $lot_id   = (int)($data['lot_id']   ?? 0);
    $lot_type = strtoupper($data['lot_type'] ?? 'CHEQUE');
    $samples  = $data['samples'] ?? [];

    if (!$lot_id) {
        http_response_code(400);
        echo json_encode(["error" => "lot_id is required"]);
        exit();
    }
    ctms_require_lot_access($pdo, (int)$tokenData['user_id'], $lot_id, 'manage');

    if (empty($samples) || !is_array($samples)) {
        http_response_code(400);
        echo json_encode(["error" => "samples array is required and must not be empty"]);
        exit();
    }

    // Verify the lot actually exists
    $lotCheck = $pdo->prepare("SELECT id, lot_number FROM lots WHERE id = ? AND is_deleted = 0");
    $lotCheck->execute([$lot_id]);
    $lot = $lotCheck->fetch();
    if (!$lot) {
        http_response_code(404);
        echo json_encode(["error" => "Lot not found"]);
        exit();
    }

    // Initial status depends on lot type
    // ELECTRONICS samples skip Phase 2 and go direct to electronics lab
    $initial_status = ($lot_type === 'ELECTRONICS')
        ? 'in_testing_electronics'
        : 'received';

    $insertStmt = $pdo->prepare("
        INSERT INTO samples (
            lot_id, lot_number, sd_number, request_number, reference_number,
            client_name, agency_type, sample_category, delivery_type,
            status, received_by, extra_data, created_at
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, NOW()
        )
    ");

    $createdIds = [];
    $pdo->beginTransaction();

    try {
        $custody = $pdo->prepare("INSERT INTO sample_custody_events (sample_id, actor_id, event_type, location, details, event_at) VALUES (?, ?, 'received', ?, ?, NOW())");
        foreach ($samples as $sample) {
            // Pull out known named fields, store everything as extra_data JSON
            $sd_number        = $sample['SD No']               ?? $sample['sd_number']        ?? '';
            $request_number   = $sample['Request No']          ?? $sample['request_number']    ?? '';
            $reference_number = $sample['Reference Number']    ?? $sample['reference_number']  ?? '';
            // Customer Name = the company that submitted the samples
            // (Example Client etc.). The Material Description is a sample-type
            // code (HRC, CRC …), NOT a customer — it used to be stored
            // here by mistake and printed as "Customer Name" on reports.
            // It stays in extra_data for the report's "Type of sample".
            $client_name      = $sample['Customer Name'] ?? $sample['customer_name'] ?? '';
            if (trim((string)$client_name) === '') {
                $legacy_desc = trim((string)($sample['Material Description'] ?? ''));
                $client_name = ($legacy_desc !== '') ? $legacy_desc : 'Private Client';
            }
            $sample_category  = $sample['sample_category']     ?? 'rebar';
            $delivery_type    = $sample['Mode']                ?? $sample['delivery_type']     ?? 'normal';

            // IMPORTANT: sd_number has a UNIQUE index in the database.
            // An empty string '' counts as a real value for uniqueness
            // purposes, so two rows with no SD No would collide and
            // crash the whole batch insert. NULL is exempt from unique
            // constraint collisions in MySQL (multiple NULLs are
            // allowed), so we convert blank values to NULL here.
            $sd_number = trim($sd_number) === '' ? null : trim($sd_number);

            $insertStmt->execute([
                $lot_id,
                $lot['lot_number'],
                $sd_number,
                $request_number,
                $reference_number,
                $client_name,
                $lot_type,
                $sample_category,
                strtolower($delivery_type) === 'online' ? 'online' : 'normal',
                $initial_status,
                $tokenData['user_id'],
                json_encode($sample),   // Store the full row as JSON so React can display all columns
            ]);
            $createdId = (int)$pdo->lastInsertId();
            $createdIds[] = $createdId;
            $custody->execute([$createdId, $tokenData['user_id'], 'Sample intake', 'Sample registered in CTMS.']);
        }

        $pdo->commit();

        // Audit log
        $pdo->prepare("
            INSERT INTO audit_log (user_id, action, module, record_id, new_value, ip_address)
            VALUES (?, 'SAMPLES_CREATED', 'samples', ?, ?, ?)
        ")->execute([
            $tokenData['user_id'],
            $lot_id,
            json_encode(['count' => count($createdIds), 'lot_type' => $lot_type]),
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);

        echo json_encode([
            "success"     => true,
            "message"     => count($createdIds) . " samples saved to database.",
            "created_ids" => $createdIds,
            "lot_id"      => $lot_id,
        ]);

    } catch (\PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(["error" => "Failed to save samples", "details" => ctms_db_error($e)]);
    }
    exit();
}

// ── PUT — Route samples to one or MORE labs at once ───────────
// Accepts either:
//   { action: 'edit_details', edits: [{id, sd_number, reference_number,
//      request_number, customer_name, material_description}, …] }   (admin fix-ups)
//   { sample_ids: [1,2], lab_stream: "Tensile Lab (...)" }       (single, legacy)
//   { sample_ids: [1,2], lab_streams: ["Tensile Lab (...)", "OES Lab (...)"] }  (multi)
if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);

    // ── Admin fix-ups: edit sample details after the lot is saved ──
    // Audit fix (issue: no way to correct sheet mistakes after save).
    // Admin-only, fully audited with old and new values. Allowed even
    // after routing — identifiers are denormalised nowhere that breaks.
    if (($data['action'] ?? '') === 'edit_details') {
        if (!in_array($tokenData['role'] ?? '', ['superadmin', 'admin'], true)) {
            http_response_code(403);
            echo json_encode(["error" => "Only administrators can edit sample details."]);
            exit();
        }
        $edits = $data['edits'] ?? [];
        if (empty($edits) || !is_array($edits)) {
            http_response_code(400);
            echo json_encode(["error" => "edits array is required"]);
            exit();
        }
        $updated = 0; $skipped = [];
        foreach ($edits as $e) {
            $sid = (int)($e['id'] ?? 0);
            if (!$sid) continue;
            $old = $pdo->prepare("SELECT * FROM samples WHERE id = ? AND is_deleted = 0");
            $old->execute([$sid]);
            $before = $old->fetch(PDO::FETCH_ASSOC);
            if (!$before) { $skipped[] = $sid; continue; }
            ctms_require_lot_access($pdo, (int)$tokenData['user_id'], (int)$before['lot_id'], 'manage');

            $sdNo  = trim((string)($e['sd_number'] ?? ''));
            $refNo = trim((string)($e['reference_number'] ?? ''));
            $reqNo = trim((string)($e['request_number'] ?? ''));
            $cust  = trim((string)($e['customer_name'] ?? ''));
            $mat   = trim((string)($e['material_description'] ?? ''));

            $newSd = $sdNo !== '' ? $sdNo : null;   // sd_number has a UNIQUE index — blanks must be NULL

            $extra = json_decode($before['extra_data'] ?? '{}', true) ?: [];
            if ($mat !== '')  $extra['Material Description'] = $mat;
            if ($cust !== '') $extra['Customer Name'] = $cust;

            $newClient = $cust !== '' ? $cust : $before['client_name'];

            $up = $pdo->prepare("UPDATE samples SET
                    sd_number = ?, reference_number = ?, request_number = ?,
                    client_name = ?, extra_data = ?, updated_at = NOW()
                 WHERE id = ? AND is_deleted = 0");
            $up->execute([$newSd, $refNo, $reqNo, $newClient, json_encode($extra, JSON_UNESCAPED_UNICODE), $sid]);

            $pdo->prepare("INSERT INTO audit_log (user_id, action, module, record_id, old_value, new_value, ip_address)
                           VALUES (?, 'SAMPLE_DETAILS_EDITED', 'samples', ?, ?, ?, ?)")
                ->execute([$tokenData['user_id'], $sid,
                    json_encode(['sd' => $before['sd_number'], 'ref' => $before['reference_number'],
                                 'req' => $before['request_number'], 'customer' => $before['client_name'],
                                 'material' => $extra['Material Description'] ?? '']),
                    json_encode(['sd' => $sdNo, 'ref' => $refNo, 'req' => $reqNo,
                                 'customer' => $newClient, 'material' => $mat]),
                    $_SERVER['REMOTE_ADDR'] ?? '']);
            $updated++;
        }
        echo json_encode(["success" => true, "updated" => $updated, "skipped" => $skipped]);
        exit();
    }

    if (!in_array($tokenData['role'] ?? '', ['superadmin', 'admin', 'mapping', 'pa', 'reception', 'hod'], true)) {
        http_response_code(403);
        echo json_encode(["error" => "Permission denied: Mapping Officer or Administrator privileges required to route samples."]);
        exit();
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $sample_ids  = $data['sample_ids']  ?? [];
    $lab_streams = $data['lab_streams'] ?? ($data['lab_stream'] ? [$data['lab_stream']] : []);

    if (empty($sample_ids) || !is_array($sample_ids)) {
        http_response_code(400);
        echo json_encode(["error" => "sample_ids array is required"]);
        exit();
    }
    $scopeCheck = $pdo->prepare('SELECT lot_id FROM samples WHERE id = ? AND is_deleted = 0');
    foreach ($sample_ids as $sampleIdToRoute) {
        $scopeCheck->execute([(int)$sampleIdToRoute]);
        $sampleLotId = (int)$scopeCheck->fetchColumn();
        if (!$sampleLotId) { http_response_code(404); echo json_encode(['error' => 'One or more selected samples were not found.']); exit(); }
        ctms_require_lot_access($pdo, (int)$tokenData['user_id'], $sampleLotId, 'manage');
    }
    if (empty($lab_streams) || !is_array($lab_streams)) {
        http_response_code(400);
        echo json_encode(["error" => "At least one lab_stream is required"]);
        exit();
    }

    $allowed_labs = [
        'Tensile Lab (Zeeshan Hassan)',
        'XRF Lab (Khurram Jahangeer)',
        'OES Lab (Jahangeer)',
        'Electronics Lab (Lab Technician)',
    ];
    foreach ($lab_streams as $ls) {
        if (!in_array($ls, $allowed_labs)) {
            http_response_code(400);
            echo json_encode(["error" => "Invalid lab_stream: $ls"]);
            exit();
        }
    }

    $assignStmt = $pdo->prepare("
        INSERT INTO sample_lab_assignments (sample_id, lab_stream, status, assigned_at)
        VALUES (?, ?, 'pending', NOW())
        ON DUPLICATE KEY UPDATE status = status
    ");

    $createdCount = 0;
    $pdo->beginTransaction();
    try {
        $custody = $pdo->prepare("INSERT INTO sample_custody_events (sample_id, actor_id, event_type, location, details, event_at) VALUES (?, ?, 'lab_assigned', ?, ?, NOW())");
        foreach ($sample_ids as $sid) {
            foreach ($lab_streams as $ls) {
                $assignStmt->execute([(int)$sid, $ls]);
                $createdCount++;
            }
            $custody->execute([(int)$sid, $tokenData['user_id'], 'Laboratory', json_encode(['lab_streams' => array_values($lab_streams)], JSON_UNESCAPED_UNICODE)]);
        }

        // Roll up samples.status to 'in_testing' so it leaves the
        // Phase 2 "Waiting for Mapping Routing" queue
        $placeholders = implode(',', array_fill(0, count($sample_ids), '?'));
        $params = array_map('intval', $sample_ids);
        $pdo->prepare("UPDATE samples SET status = 'in_testing', updated_at = NOW() WHERE id IN ($placeholders) AND is_deleted = 0")
            ->execute($params);

        $pdo->commit();
    } catch (\PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(["error" => "Failed to assign labs", "details" => ctms_db_error($e)]);
        exit();
    }

    $pdo->prepare("
        INSERT INTO audit_log (user_id, action, module, new_value, ip_address)
        VALUES (?, 'SAMPLES_LAB_ASSIGNED', 'samples', ?, ?)
    ")->execute([
        $tokenData['user_id'],
        json_encode(['sample_ids' => $sample_ids, 'lab_streams' => $lab_streams]),
        $_SERVER['REMOTE_ADDR'] ?? ''
    ]);

    echo json_encode([
        "success"   => true,
        "message"   => count($sample_ids) . " sample(s) routed to " . count($lab_streams) . " lab(s).",
        "assignments_created" => $createdCount,
    ]);
    exit();
}

http_response_code(405);
echo json_encode(["error" => "Method not allowed"]);
?>
