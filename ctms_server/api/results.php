<?php
// ============================================================
// api/results.php — Test Results REST Endpoint
//
// POST /results.php  → Submit test results for a full lot batch
//   Body: {
//     lot_id:     int,
//     lot_name:   string,
//     lab_stream: "Tensile Lab (Mr. Zeeshan)" | "XRF ..." | "OES ..." | "Electronics ...",
//     results: [
//       { sample_id: int, values: { "Dia (mm)": "12", "Yield Strength (MPa)": "450", ... } }
//     ]
//   }
//
// GET /results.php?sample_id=X&type=utm|xrf|oes  → Fetch existing results
//
// v2 — Sprint 1 Patch B (D-08): the per-sample existence check now
//   also verifies the sample belongs to the posted lot (lot_id), and
//   skipped samples are reported back in the "errors" array.
// ============================================================
require_once 'db.php';
require_once __DIR__ . '/result_fields_lib.php';

 $tokenData = require_auth();
 $method    = $_SERVER['REQUEST_METHOD'];
 ctms_ensure_result_fields($pdo);

function ctms_result_stream_kind(string $stream): ?string {
    $matches = [];
    foreach (['xrf' => 'XRF', 'oes' => 'OES', 'electronics' => 'Electronics', 'tensile' => 'Tensile'] as $kind => $label) {
        if (stripos($stream, $label) !== false) $matches[] = $kind;
    }
    return count($matches) === 1 ? $matches[0] : null;
}

// ── GET — Fetch existing results for a sample ────────────────
if ($method === 'GET') {
    $sample_id = (int)($_GET['sample_id'] ?? 0);
    $type      = strtolower($_GET['type'] ?? 'utm');

    if (!$sample_id) {
        http_response_code(400);
        echo json_encode(["error" => "sample_id required"]);
        exit();
    }

    ctms_require_role(['superadmin', 'admin', 'hod', 'deo', 'tester', 'technician'], $tokenData,
        'Laboratory results are restricted to authorized staff.');
    $kindByRequest = [
        'utm' => 'tensile', 'tensile' => 'tensile', 'xrf' => 'xrf',
        'oes' => 'oes', 'spectroscopy' => 'oes', 'electronics' => 'electronics',
    ];
    if (!isset($kindByRequest[$type])) {
        http_response_code(400);
        echo json_encode(["error" => "Unsupported result type."]);
        exit();
    }
    $requestedKind = $kindByRequest[$type];
    if (!in_array($tokenData['role'], ['superadmin', 'admin', 'hod', 'deo'], true)) {
        $account = ctms_load_active_account((int)$tokenData['user_id']);
        $userKind = $account ? ctms_result_stream_kind(trim((string)($account['lab_stream'] ?? ''))) : null;
        if ($userKind === null || $userKind !== $requestedKind) {
            http_response_code(403);
            echo json_encode(["error" => "Your account is not assigned to this laboratory result type."]);
            exit();
        }
    }
    $sampleCheck = $pdo->prepare("SELECT id FROM samples WHERE id = ? AND is_deleted = 0");
    $sampleCheck->execute([$sample_id]);
    if (!$sampleCheck->fetchColumn()) {
        http_response_code(404);
        echo json_encode(["error" => "Sample not found."]);
        exit();
    }
    ctms_require_sample_lot_access($pdo, (int)$tokenData['user_id'], $sample_id, 'view');

    if ($type === 'utm') {
        $stmt = $pdo->prepare("SELECT * FROM test_tensile WHERE sample_id = ? AND is_deleted = 0 ORDER BY version DESC LIMIT 1");
        $stmt->execute([$sample_id]);
        echo json_encode($stmt->fetch() ?: (object)[]);
    } elseif ($type === 'xrf') {
        $stmt = $pdo->prepare("SELECT element, percentage FROM test_xrf WHERE sample_id = ? AND is_deleted = 0");
        $stmt->execute([$sample_id]);
        $rows = $stmt->fetchAll();
        $result = [];
        foreach ($rows as $r) $result[$r['element'] . ' %'] = $r['percentage'];
        echo json_encode($result);
    } elseif ($requestedKind === 'electronics') {
        $stmt = $pdo->prepare("SELECT parameter, specified_range, observed_value, remarks FROM test_electronics WHERE sample_id = ? AND is_deleted = 0");
        $stmt->execute([$sample_id]);
        echo json_encode($stmt->fetch() ?: (object)[]);
    } else {
        $stmt = $pdo->prepare("SELECT element, percentage FROM test_spectroscopy WHERE sample_id = ? AND is_deleted = 0");
        $stmt->execute([$sample_id]);
        $rows = $stmt->fetchAll();
        $result = [];
        foreach ($rows as $r) $result[$r['element'] . ' %'] = $r['percentage'];
        echo json_encode($result);
    }
    exit();
}

// ── POST — Batch submit results from React Phase 3 ───────────
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $lot_id     = (int)($data['lot_id']     ?? 0);
    $lab_stream = trim((string)($data['lab_stream'] ?? ''));
    $results    = $data['results']           ?? [];

    if (empty($results) || !is_array($results)) {
        http_response_code(400);
        echo json_encode(["error" => "results array is required"]);
        exit();
    }

    // Sprint 1 / D-08: the lot ownership check below compares each
    // sample against this lot_id, so it must be present and valid.
    if (!$lot_id) {
        http_response_code(400);
        echo json_encode(["error" => "lot_id is required"]);
        exit();
    }

    // A stream must identify exactly one supported lab. Unknown values must
    // never silently be treated as tensile work.
    $streamKind = ctms_result_stream_kind($lab_stream);
    if ($streamKind === null) {
        http_response_code(400);
        echo json_encode(["error" => "lab_stream must identify exactly one supported laboratory."]);
        exit();
    }
    $isXRF         = $streamKind === 'xrf';
    $isOES         = $streamKind === 'oes';
    $isElectronics = $streamKind === 'electronics';
    $isTensile     = $streamKind === 'tensile';
    $fieldType = $streamKind;
    $configuredFields = $fieldType ? ctms_result_fields($pdo, $fieldType) : [];

    // RBAC: Verify user role and assigned lab stream
    $userRole = $tokenData['role'] ?? '';
    if (!in_array($userRole, ['superadmin', 'admin', 'tester', 'technician'], true)) {
        http_response_code(403);
        echo json_encode(["error" => "You are not authorized to submit laboratory test results."]);
        exit();
    }

    if (!in_array($userRole, ['superadmin', 'admin'], true)) {
        $uStmt = $pdo->prepare("SELECT lab_stream FROM users WHERE id = ? AND is_deleted = 0");
        $uStmt->execute([$tokenData['user_id']]);
        $userStream = trim((string)$uStmt->fetchColumn());

        $userKind = ctms_result_stream_kind($userStream);
        if ($userKind === null || $userKind !== $streamKind) {
            http_response_code(403);
            echo json_encode(["error" => "Your account is not assigned to submit results for this laboratory."]);
            exit();
        }
    }

    $savedCount   = 0;
    $errors       = [];
    $auditSamples = [];   // SEC-009: before/after snapshot per sample

    $pdo->beginTransaction();
    try {
        foreach ($results as $resultRow) {
            $sample_id = (int)($resultRow['sample_id'] ?? 0);
            $values    = $resultRow['values'] ?? [];

            if (!$sample_id || empty($values)) continue;

            // Verify sample belongs to the lot (security check — Sprint 1 / D-08)
            // The old query only checked that the sample exists, so results
            // could be filed against a sample from another lot (or a stale
            // screen after re-mapping). lot_id is now part of the check.
            $check = $pdo->prepare("SELECT id, lot_number FROM samples WHERE id = ? AND lot_id = ? AND is_deleted = 0");
            $check->execute([$sample_id, $lot_id]);
            $sample = $check->fetch();
            if (!$sample) {
                $errors[] = [
                    "sample_id" => $sample_id,
                    "reason"    => "Sample not found in lot #" . $lot_id . " — skipped",
                ];
                continue;
            }
            ctms_require_lot_access($pdo, (int)$tokenData['user_id'], $lot_id, 'edit');

            // Issued reports are immutable. An administrator must reopen the
            // report with a reason before laboratory data can be corrected.
            try {
                $issued = $pdo->prepare("SELECT w.stage FROM reports r JOIN report_approvals w ON w.report_id = r.id WHERE r.sample_id = ? AND r.is_deleted = 0 AND w.stage IN ('technical_review','issued') LIMIT 1");
                $issued->execute([$sample_id]);
                $lockedStage = (string)($issued->fetchColumn() ?: '');
                if ($lockedStage !== '') {
                    $errors[] = ['sample_id' => $sample_id, 'reason' => $lockedStage === 'issued'
                        ? 'This sample has an Issued report. An administrator must reopen it with a correction reason before results can be changed.'
                        : 'This sample is under Technical Review. The reviewer must return it for correction before results can be changed.'];
                    continue;
                }
            } catch (PDOException $workflowUnavailable) {
                // The additive approval migration may not have been installed
                // yet; preserve legacy data entry until the workflow is enabled.
            }

            // B-2 correction gate: overwriting EXISTING results requires a
            // stated reason, and the audit entry records BOTH the old
            // values (snapshot before overwrite) and the new submission.
            // First-time submissions are unaffected.
            $hadResults = false;
            $oldSnapshot = null;
            if ($isTensile) {
                $c = $pdo->prepare("SELECT * FROM test_tensile WHERE sample_id = ? AND is_deleted = 0");
                $c->execute([$sample_id]); $oldRows = $c->fetchAll(PDO::FETCH_ASSOC);
                $hadResults = count($oldRows) > 0; $oldSnapshot = $oldRows;
                $customValues = [];
                foreach ($configuredFields as $field) {
                    if (!(int)$field['is_builtin'] && array_key_exists($field['field_key'], $values)) {
                        $raw = trim((string)$values[$field['field_key']]);
                        if ($raw !== '') $customValues[$field['field_key']] = $raw;
                    }
                }
                $values['custom_values'] = $customValues;

            } elseif ($isXRF) {
                $c = $pdo->prepare("SELECT * FROM test_xrf WHERE sample_id = ? AND is_deleted = 0");
                $c->execute([$sample_id]); $oldRows = $c->fetchAll(PDO::FETCH_ASSOC);
                $hadResults = count($oldRows) > 0; $oldSnapshot = $oldRows;
            } elseif ($isOES) {
                $c = $pdo->prepare("SELECT * FROM test_spectroscopy WHERE sample_id = ? AND is_deleted = 0");
                $c->execute([$sample_id]); $oldRows = $c->fetchAll(PDO::FETCH_ASSOC);
                $hadResults = count($oldRows) > 0; $oldSnapshot = $oldRows;
            } elseif ($isElectronics) {
                try {
                    $c = $pdo->prepare("SELECT * FROM test_electronics WHERE sample_id = ? AND is_deleted = 0");
                    $c->execute([$sample_id]); $oldRows = $c->fetchAll(PDO::FETCH_ASSOC);
                    $hadResults = count($oldRows) > 0; $oldSnapshot = $oldRows;
                } catch (\PDOException $e) { /* legacy table absent */ }
            }
            if ($hadResults) {
                $reason = trim((string)($data['reason'] ?? ''));
                if ($reason === '') {
                    $errors[] = [
                        "sample_id" => $sample_id,
                        "reason"    => "Sample #{$sample_id} already has results — a correction reason is required to overwrite them.",
                    ];
                    continue;
                }
                try {
                    $pdo->prepare("INSERT INTO audit_log (user_id, action, module, record_id, old_value, new_value, ip_address)
                                   VALUES (?, 'RESULT_CORRECTED', 'results', ?, ?, ?, ?)")
                        ->execute([$tokenData['user_id'], $sample_id,
                                   json_encode(['lab_stream' => $lab_stream, 'old_results' => $oldSnapshot]),
                                   json_encode(['reason' => $reason, 'new_results' => $values]), $_SERVER['REMOTE_ADDR'] ?? '']);
                } catch (\PDOException $e) {
                    try {
                        $pdo->prepare("INSERT INTO audit_log (user_id, action, module, record_id, new_value, ip_address)
                                       VALUES (?, 'RESULT_CORRECTED', 'results', ?, ?, ?)")
                            ->execute([$tokenData['user_id'], $sample_id,
                                       json_encode(['reason' => $reason, 'new_results' => $values]), $_SERVER['REMOTE_ADDR'] ?? '']);
                    } catch (\PDOException $e2) { /* never block the submission on audit failure */ }
                }
            }

            // SEC-009: snapshot of the reading this submission replaces
            // (populated by the branches that overwrite in place).
            $prevSnapshot = null;

            if ($isTensile) {
                // ── TENSILE ───────────────────────────────────────────
                $dia       = (float)($values['Dia (mm)']                       ?? 0);
                $size      = $values['Size']                                    ?? '';
                $gaugeLen  = (float)($values['Gauge Length (mm)']              ?? 0);
                $area      = (float)($values['Cross Section Area (mm²)']       ?? 0);
                $yieldStr  = (float)($values['Yield Strength (MPa)']           ?? 0);
                $uts       = (float)($values['Ultimate Tensile Strength (MPa)']?? 0);
                $elongation= (float)($values['Elongation %']                   ?? 0);

                // Auto-calculate area if not provided but dia is
                if ($area <= 0 && $dia > 0) {
                    $area = round((M_PI / 4) * $dia * $dia, 3);
                }

                // Get next version number for this sample
                $vStmt = $pdo->prepare("SELECT COALESCE(MAX(version), 0) FROM test_tensile WHERE sample_id = ?");
                $vStmt->execute([$sample_id]);
                $version = (int)$vStmt->fetchColumn() + 1;

                $stmt = $pdo->prepare("
                    INSERT INTO test_tensile (
                        sample_id, lot_number, specimen_no, gauge_length,
                        width, thickness, cross_section_area,
                        yield_strength, tensile_strength,
                        elongation, version, technician_id, test_date, entered_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                ");
                $stmt->execute([
                    $sample_id,
                    $sample['lot_number'],
                    $size ?: '1',
                    $gaugeLen ?: null,
                    $dia,      // width (using dia for round bar)
                    $dia,      // thickness
                    $area,
                    $yieldStr,
                    $uts,
                    $elongation ?: null,
                    $version,
                    $tokenData['user_id'],
                    $tokenData['user_id'],
                ]);

            } elseif ($isXRF) {
                // ── XRF ──────────────────────────────────────────────
                // SEC-009: capture the readings being replaced so the
                // audit trail records the actual before/after numbers.
                $prevStmt = $pdo->prepare("SELECT element, percentage FROM test_xrf WHERE sample_id = ? AND is_deleted = 0");
                $prevStmt->execute([$sample_id]);
                $prevSnapshot = $prevStmt->fetchAll(PDO::FETCH_KEY_PAIR);
                // Soft-delete previous readings for this sample
                $pdo->prepare("UPDATE test_xrf SET is_deleted = 1 WHERE sample_id = ?")->execute([$sample_id]);

                $xrfStmt = $pdo->prepare("
                    INSERT INTO test_xrf (sample_id, lot_number, element, percentage, technician_id, test_date, entered_by)
                    VALUES (?, ?, ?, ?, ?, NOW(), ?)
                ");
                foreach ($configuredFields as $field) {
                    $el = (string)$field['field_key'];
                    $val = $values[$el] ?? $values[$el . ' %'] ?? $values[$field['label']] ?? null;
                    if ($val !== null && $val !== '') {
                        $xrfStmt->execute([
                            $sample_id, $sample['lot_number'],
                            $el, (float)$val,
                            $tokenData['user_id'], $tokenData['user_id'],
                        ]);
                    }
                }

            } elseif ($isOES) {
                // ── OES / SPECTROSCOPY ────────────────────────────────
                // SEC-009: capture the readings being replaced.
                $prevStmt = $pdo->prepare("SELECT element, percentage FROM test_spectroscopy WHERE sample_id = ? AND is_deleted = 0");
                $prevStmt->execute([$sample_id]);
                $prevSnapshot = $prevStmt->fetchAll(PDO::FETCH_KEY_PAIR);
                $pdo->prepare("UPDATE test_spectroscopy SET is_deleted = 1 WHERE sample_id = ?")->execute([$sample_id]);

                $oesStmt = $pdo->prepare("
                    INSERT INTO test_spectroscopy (sample_id, lot_number, element, percentage, technician_id, test_date, entered_by)
                    VALUES (?, ?, ?, ?, ?, NOW(), ?)
                ");
                foreach ($configuredFields as $field) {
                    $el = (string)$field['field_key'];
                    $val = $values[$el] ?? $values[$el . ' %'] ?? $values[$field['label']] ?? null;
                    if ($val !== null && $val !== '') {
                        $oesStmt->execute([
                            $sample_id, $sample['lot_number'],
                            $el, (float)$val,
                            $tokenData['user_id'], $tokenData['user_id'],
                        ]);
                    }
                }

            } else {
                // ── ELECTRONICS — proper results table (audit fix B-1) ──
                // One row per test parameter in test_electronics, with
                // technician attribution. The notes-JSON copy is kept
                // only as a legacy mirror so older report snapshots
                // keep rendering.
                if (isset($values['params']) && is_array($values['params'])) {
                    $clean = [];
                    foreach ($values['params'] as $p) {
                        if (!is_array($p)) continue;
                        $tp = trim((string)($p['parameter'] ?? $p['Test Parameter'] ?? ''));
                        if ($tp === '') continue;   // skip empty rows
                        $clean[] = [
                            'Test Parameter'  => $tp,
                            'Specified Range' => (string)($p['range']    ?? $p['Specified Range'] ?? ''),
                            'Observed Value'  => (string)($p['observed'] ?? $p['Observed Value']  ?? ''),
                            'Remarks'         => (string)($p['remarks']  ?? $p['Remarks']         ?? ''),
                        ];
                    }
                    // Soft-delete previous parameter rows, then insert fresh
                    $pdo->prepare("UPDATE test_electronics SET is_deleted = 1 WHERE sample_id = ?")->execute([$sample_id]);
                    $et = $pdo->prepare("INSERT INTO test_electronics
                        (sample_id, parameter, specified_range, observed_value, remarks, technician_id, test_date)
                        VALUES (?,?,?,?,?,?,NOW())");
                    foreach ($clean as $row) {
                        $et->execute([$sample_id, $row['Test Parameter'], $row['Specified Range'],
                                      $row['Observed Value'], $row['Remarks'], $tokenData['user_id']]);
                    }
                    $values = ['params' => $clean]; // keep assignment.result_data in same shape
                }
                // B-1 closure: results live ONLY in test_electronics now.
                // samples.notes is no longer written (it was the old
                // storage); existing notes remain untouched purely as
                // historical data for pre-upgrade report snapshots.
            }

            // ── Report template resolution ─────────────────────────
            // report_template_id is optional. The report generator
            // (report_generate.php) detects the format family from
            // actual test data, so we safely skip the DB lookup
            // that previously referenced non-existent columns.
            $resolved_template_id = null;

            // Mark ONLY this lab's assignment as complete
            $pdo->prepare("
                UPDATE sample_lab_assignments
                SET status = 'complete', result_data = ?, completed_at = NOW(), completed_by = ?, report_template_id = ?
                WHERE sample_id = ? AND lab_stream = ?
            ")->execute([json_encode($values), $tokenData['user_id'], $resolved_template_id, $sample_id, $lab_stream]);

            // Roll up samples.status — only flip to 'data_entry'
            // once EVERY assignment for this sample is complete.
            $remainingStmt = $pdo->prepare("
                SELECT COUNT(*) FROM sample_lab_assignments
                WHERE sample_id = ? AND status != 'complete'
            ");
            $remainingStmt->execute([$sample_id]);
            $remaining = (int)$remainingStmt->fetchColumn();

            $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM sample_lab_assignments WHERE sample_id = ?");
            $totalStmt->execute([$sample_id]);
            $totalAssignments = (int)$totalStmt->fetchColumn();

            if ($remaining === 0) {
                // Either no assignments tracked (legacy) or all are done
                $pdo->prepare("UPDATE samples SET status = 'data_entry', updated_at = NOW() WHERE id = ?")
                    ->execute([$sample_id]);
                $pdo->prepare("INSERT INTO sample_custody_events (sample_id, actor_id, event_type, location, details, event_at) VALUES (?, ?, 'testing_completed', ?, ?, NOW())")
                    ->execute([$sample_id, $tokenData['user_id'], $lab_stream, 'Laboratory results submitted.']);
            }

            // SEC-009: record the exact values submitted, plus the prior
            // values for the result types that overwrite in place.
            $auditSamples[] = [
                'sample_id'  => $sample_id,
                'lab_stream' => $lab_stream,
                'previous'   => $prevSnapshot,
                'new'        => $values,
            ];

            $savedCount++;
        }

        $pdo->commit();

        // Audit log — written after the commit. If it fails the results are
        // already saved, so the failure must not turn the response into a 500.
        try {
            $pdo->prepare("
                INSERT INTO audit_log (user_id, action, module, record_id, new_value, ip_address)
                VALUES (?, 'TEST_RESULTS_SUBMITTED', 'results', ?, ?, ?)
            ")->execute([
                $tokenData['user_id'],
                $lot_id,
                json_encode(['lab_stream' => $lab_stream, 'samples_saved' => $savedCount, 'changes' => $auditSamples]),
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
        } catch (\Throwable $auditError) {
            error_log('[CTMS results] audit log write failed: ' . $auditError->getMessage());
        }

        echo json_encode([
            "success"      => true,
            "message"      => "Results saved for $savedCount sample(s). Forwarded to Phase 4 report queue.",
            "saved_count"  => $savedCount,
            "skipped"      => count($errors),
            "errors"       => $errors,
        ]);

    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[CTMS results] submission failed: ' . get_class($e) . ': ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "error"   => "Failed to save results",
            "details" => $e instanceof \PDOException
                ? ctms_db_error($e)
                : "Unexpected server error — no results were saved. Please retry.",
        ]);
    }
    exit();
}

http_response_code(405);
echo json_encode(["error" => "Method not allowed"]);
?>
