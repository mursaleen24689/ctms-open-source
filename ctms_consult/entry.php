<?php
declare(strict_types=1);
/**
 * Data entry — workflow step 3 (the screen technicians live in).
 *
 * Built to the laboratory's own rules:
 *
 *  - NOTHING IS BLOCKED. Warnings are shown, recorded, and can be continued
 *    past with a reason. Instruments here are not always calibrated and many
 *    readings are judged by eye.
 *  - COPY/PASTE from a previous sample: when the same material arrives again
 *    the old readings are loaded in one click and only the changes are typed.
 *  - EDIT AND DELETE after saving, always with a reason, always audited.
 *  - Values that are not numbers ('Balance', '< 0.001', 'Max 0.23') are
 *    preserved exactly as typed.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/engine.php';

function entry_make_thumbnail(string $sourcePath, string $targetPath, string $mime, int $width, int $height): bool {
    if (!function_exists('imagecreatetruecolor')) return false;
    $source = match ($mime) {
        'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($sourcePath) : false,
        'image/png'  => function_exists('imagecreatefrompng') ? @imagecreatefrompng($sourcePath) : false,
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
        'image/gif'  => function_exists('imagecreatefromgif') ? @imagecreatefromgif($sourcePath) : false,
        default      => false,
    };
    if (!$source) return false;
    $scale = min(1, 420 / max($width, $height));
    $thumbWidth = max(1, (int)round($width * $scale));
    $thumbHeight = max(1, (int)round($height * $scale));
    $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
    $white = imagecolorallocate($thumb, 255, 255, 255);
    imagefill($thumb, 0, 0, $white);
    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);
    $saved = @imagejpeg($thumb, $targetPath, 82);
    imagedestroy($thumb);
    imagedestroy($source);
    return $saved;
}

$u = require_login();
$jobId = (int)(get('job') ?? post('job_id', 0));

// The sample screen links to entry.php?sample=N, which used to fail with
// "That test job does not exist" because the page only understood ?job=.
//  - one test waiting  -> go straight into it
//  - several waiting   -> ask which one, rather than guessing
if ($jobId <= 0 && (int)get('sample', 0) > 0) {
    $sid = (int)get('sample');
    $st = $pdo->prepare('SELECT j.id, tt.name AS test_name, j.status,
                                (SELECT COUNT(*) FROM c_readings r WHERE r.job_id = j.id AND r.is_current = 1) AS rd
                           FROM c_test_jobs j
                           JOIN c_test_types tt ON tt.id = j.test_type_id
                          WHERE j.sample_id = ?
                          ORDER BY j.id');
    $st->execute([$sid]);
    $openJobs = $st->fetchAll();

    if (count($openJobs) === 1) {
        redirect('entry.php?job=' . (int)$openJobs[0]['id']);
    }
    if (count($openJobs) > 1) {
        $st = $pdo->prepare('SELECT sample_code, description FROM c_samples WHERE id = ?');
        $st->execute([$sid]);
        $sm = $st->fetch();

        $PAGE_TITLE = 'Choose a test to enter';
        $PAGE_SUB   = ($sm['sample_code'] ?? '') . ' · ' . mb_strimwidth((string)($sm['description'] ?? ''), 0, 60, '…');
        include __DIR__ . '/inc/layout_top.php';
        echo '<div class="card"><h2>This sample has ' . count($openJobs) . ' tests — choose one</h2>'
           . '<p class="small muted" style="margin-bottom:12px">Each test is recorded on its own page, '
           . 'so the readings always belong to one test and cannot be mixed up.</p>'
           . '<div class="table-wrap"><table>'
           . '<thead><tr><th>Test</th><th class="right">Readings</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($openJobs as $j) {
            echo '<tr><td><strong>' . h($j['test_name']) . '</strong></td>'
               . '<td class="num">' . (int)$j['rd'] . '</td>'
               . '<td>' . status_badge((string)$j['status']) . '</td>'
               . '<td><a class="btn btn-sm btn-teal" href="' . h(app_url('entry.php?job=' . (int)$j['id'])) . '">'
               . ((int)$j['rd'] > 0 ? 'Review' : 'Enter') . '</a></td></tr>';
        }
        echo '</tbody></table></div>'
           . '<p style="margin-top:12px"><a class="btn btn-ghost" href="'
           . h(app_url('samples.php?action=view&id=' . $sid)) . '">Back to the sample</a></p></div>';
        include __DIR__ . '/inc/layout_bottom.php';
        exit;
    }
    flash('This sample has no tests attached yet.', 'err');
    redirect('samples.php?action=view&id=' . $sid);
}

$st = $pdo->prepare(
    'SELECT j.*, tt.name AS test_name, tt.code AS test_code, tt.category, tt.result_style,
            j.standard_text,
            st.code AS std_code, st.title AS std_title, st.revision AS std_rev, st.scope_summary,
            s.id AS sample_id, s.sample_code, s.description, s.company_name, s.grade_claimed,
            s.material_group, p.piece_code, p.dimension_kind, p.dim_a, p.dim_b,
            us.full_name AS assigned_name
       FROM c_test_jobs j
       JOIN c_test_types  tt ON tt.id = j.test_type_id
       JOIN c_samples     s  ON s.id  = j.sample_id
       LEFT JOIN c_standards st ON st.id = j.standard_id
       LEFT JOIN c_sample_pieces p ON p.id = j.piece_id
       LEFT JOIN c_users  us ON us.id = j.assigned_to
      WHERE j.id = ?');
$st->execute([$jobId]);
$job = $st->fetch();

if (!$job) {
    flash('That test job does not exist.', 'err');
    redirect('jobs.php');
}

// An approved job may still be opened and corrected. Refusing outright would
// lock a technician out of their own data the moment they clicked "submit for
// approval", which is not what approved is meant to mean. The only firm stop is
// a report that has already gone to the client: changing a value behind a
// dispatched report would make the paper in the client's hands wrong.
$jobDispatched = false;
if (!empty($job['sample_id'])) {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM c_reports r
           JOIN c_dispatches d ON d.report_id = r.id AND d.is_reversed = 0
          WHERE r.sample_id = ?');
    $st->execute([(int)$job['sample_id']]);
    $jobDispatched = ((int)$st->fetchColumn()) > 0;
}
if ($jobDispatched) {
    flash('This sample\'s report has already been dispatched to the client, so the readings '
        . 'cannot be changed. Raise a correction from the sample screen if a revision is needed.',
        'err');
    redirect('samples.php?action=view&id=' . (int)$job['sample_id']);
}
$jobWasApproved = ($job['status'] === 'approved');

$rules = engine_rules((int)$job['test_type_id']);

$isElementComposition = ($job['category'] === 'chemical' && $job['result_style'] === 'composition_multi');
if ($isElementComposition && is_post() && post('do') === 'manage_elements') {
    $removeId = (int)post('remove_measurement', 0);
    $editId = (int)post('edit_measurement', 0);
    if ($editId > 0) {
        $labels = (array)post('element_label', []);
        $label = trim((string)($labels[$editId] ?? ''));
        if ($label === '' || mb_strlen($label) > 120) {
            flash('Enter an element label up to 120 characters.', 'err');
        } else {
            $st = $pdo->prepare('UPDATE c_test_measurements tm JOIN c_measurements m ON m.id = tm.measurement_id SET tm.column_label = ? WHERE tm.test_type_id = ? AND tm.measurement_id = ? AND m.is_analyte = 1');
            $st->execute([$label, (int)$job['test_type_id'], $editId]);
            audit('TEST_ELEMENT_LABEL_UPDATED', 'results', $jobId, null, ['measurement_id' => $editId, 'label' => $label]);
            flash('Element label updated. The report will use this label.', 'ok');
            redirect('entry.php?job=' . $jobId);
        }
    }
    if ($removeId > 0) {
        $st = $pdo->prepare('DELETE tm FROM c_test_measurements tm JOIN c_measurements m ON m.id = tm.measurement_id WHERE tm.test_type_id = ? AND tm.measurement_id = ? AND m.is_analyte = 1');
        $st->execute([(int)$job['test_type_id'], $removeId]);
        audit('TEST_ELEMENT_REMOVED', 'results', $jobId, null, ['measurement_id' => $removeId], 'removed from this test');
        flash('Element removed from this test. Previously saved readings remain in history.', 'ok');
        redirect('entry.php?job=' . $jobId);
    }
    $measurementId = (int)post('library_element_id', 0);
    if ($measurementId > 0) {
        $st = $pdo->prepare('SELECT id, symbol FROM c_measurements WHERE id = ? AND is_analyte = 1');
        $st->execute([$measurementId]);
        $element = $st->fetch();
        if (!$element) flash('Choose a valid element from the list.', 'err');
        else {
            $pdo->prepare('INSERT IGNORE INTO c_test_measurements (test_type_id, measurement_id, column_label, is_required, is_computed, sort_order) VALUES (?, ?, ?, 0, 0, 999)')
                ->execute([(int)$job['test_type_id'], $measurementId, (string)$element['symbol']]);
            audit('TEST_ELEMENT_ADDED', 'results', $jobId, null, ['measurement_id' => $measurementId, 'symbol' => $element['symbol']]);
            flash('Element added to this test. Its readings will appear in the report.', 'ok');
            redirect('entry.php?job=' . $jobId);
        }
    } else {
        $elementName = trim((string)post('custom_element_name', ''));
        $elementSymbol = trim((string)post('custom_element_symbol', ''));
        if ($elementName === '' || $elementSymbol === '' || mb_strlen($elementName) > 160 || mb_strlen($elementSymbol) > 40) {
            flash('Enter both an element name and symbol (up to 160 and 40 characters).', 'err');
        } else {
            $code = 'EL_CUSTOM_' . strtoupper(substr(hash('sha256', mb_strtolower($elementSymbol)), 0, 16));
            $st = $pdo->prepare('SELECT id FROM c_measurements WHERE code = ?');
            $st->execute([$code]);
            $customId = (int)($st->fetchColumn() ?: 0);
            if (!$customId) {
                $pdo->prepare('INSERT INTO c_measurements (code, name, symbol, canonical_unit, accepted_units, value_kind, is_analyte, sort_order) VALUES (?, ?, ?, "%", ?, "numeric_or_text", 1, 999)')
                    ->execute([$code, $elementName . ' (' . $elementSymbol . ')', $elementSymbol, '{"%":1,"wt%":1}']);
                $customId = (int)$pdo->lastInsertId();
            }
            $pdo->prepare('INSERT IGNORE INTO c_test_measurements (test_type_id, measurement_id, column_label, is_required, is_computed, sort_order) VALUES (?, ?, ?, 0, 0, 999)')
                ->execute([(int)$job['test_type_id'], $customId, $elementSymbol]);
            audit('TEST_CUSTOM_ELEMENT_ADDED', 'results', $jobId, null, ['symbol' => $elementSymbol, 'name' => $elementName]);
            flash('Custom element added to this test and saved in the measurement library.', 'ok');
            redirect('entry.php?job=' . $jobId);
        }
    }
}

$availableElements = [];
if ($isElementComposition) {
    $st = $pdo->prepare('SELECT m.id, m.name, m.symbol FROM c_measurements m WHERE m.is_analyte = 1 AND NOT EXISTS (SELECT 1 FROM c_test_measurements tm WHERE tm.test_type_id = ? AND tm.measurement_id = m.id) ORDER BY m.sort_order, m.name');
    $st->execute([(int)$job['test_type_id']]);
    $availableElements = $st->fetchAll();
}

// How many physical rows does this test have?
//  - a depth profile (case depth): one row per depth step, entered by hand
//  - a composition test: one row per sample
//  - otherwise: one row per piece, or a single row
$pieceCount = (int)$pdo->query('SELECT COUNT(*) FROM c_sample_pieces
                                 WHERE sample_id = ' . (int)$job['sample_id'] . ' AND is_deleted = 0')->fetchColumn();
$defaultRows = match ($job['result_style']) {
    'depth_profile'      => 8,
    'composition_multi'  => max(1, min(20, $pieceCount)),
    default              => max(1, $pieceCount),
};
$pieces = $pdo->query('SELECT id, piece_code FROM c_sample_pieces
                        WHERE sample_id = ' . (int)$job['sample_id'] . ' AND is_deleted = 0
                        ORDER BY sort_order, id')->fetchAll();

/** Every reading on the form, as posted. */
function collectRows(array $post, array $rules): array {
    $rows = [];
    $in = $post['r'] ?? [];
    foreach ((array)$in as $ri => $vals) {
        $row = ['label' => trim((string)($vals['__label'] ?? '')), 'values' => []];
        foreach ($rules as $code => $_rule) {
            if (array_key_exists($code, (array)$vals)) {
                $row['values'][$code] = trim((string)$vals[$code]);
            }
        }
        // keep a row that has anything in it
        if (array_filter($row['values'], fn($v) => $v !== '') !== []) {
            $rows[(int)$ri] = $row;
        }
    }
    return $rows;
}

/** Validate one row and return parsed values plus messages. */
function validateRow(array $ruleSet, array $row): array {
    $parsed = []; $issues = []; $worst = 'ok';
    foreach ($ruleSet as $code => $rule) {
        if (!array_key_exists($code, $row['values'])) continue;
        $raw = $row['values'][$code];
        if ($raw === '') continue;
        $p = engine_parse($raw, $rule);
        $parsed[$code] = $p;
        $v = engine_validate($p, $rule);
        if ($v['state'] === 'warning' && $worst === 'ok') $worst = 'warning';
        if ($v['state'] === 'error') $worst = 'error';
        if (in_array($v['state'], ['warning', 'error'], true)) {
            $prefix = $v['state'] === 'error' ? 'Impossible: ' : 'Please check: ';
            $issues[] = ['code' => $code, 'state' => $v['state'], 'message' => $prefix . $v['note']];
        }
    }
    // cross-checks between values in the same row
    foreach (engine_crosscheck($parsed) as $x) {
        $issues[] = ['code' => 'cross', 'state' => $x['severity'], 'message' => $x['message']];
        if ($x['severity'] === 'error') $worst = 'error';
    }
    // mean checks: any <CODE>_MEAN must follow from its readings
    foreach ($ruleSet as $code => $rule) {
        if (!(int)$rule['is_computed']) continue;
        if (!isset($parsed[$code])) continue;
        $base = preg_match('/MEAN\((\w+)\)/', (string)$rule['formula'], $m) ? $m[1] : null;
        if (!$base) continue;
        $readings = [];
        foreach ($parsed as $pc => $pp) {
            if ($pc === $base || str_starts_with($pc, $base . '_')) $readings[] = $pp['num'];
        }
        $chk = engine_check_mean($readings, $parsed[$code]['num']);
        if ($chk) {
            $issues[] = ['code' => $code, 'state' => 'warning', 'message' => $chk['message']
                       . ' Suggested value: ' . $chk['suggestion']];
            if ($worst === 'ok') $worst = 'warning';
        }
    }
    return ['parsed' => $parsed, 'issues' => $issues, 'worst' => $worst];
}

// ════════════════════════════════════════════════════════════════════════════
//  SAVE
// ════════════════════════════════════════════════════════════════════════════
if (is_post() && post('do') === 'save') {
    $rowsIn   = collectRows($_POST, $rules);
    $override = trim((string)post('override_reason', ''));
    $saveMode = (string)post('save_mode', 'save');   // save | submit
    $guided   = in_guided_mode();

    $anyWarning = false; $anyError = false;
    foreach ($rowsIn as $row) {
        $v = validateRow($rules, $row);
        if ($v['worst'] === 'warning') $anyWarning = true;
        if ($v['worst'] === 'error')   $anyError = true;
    }

    // The laboratory rule: never block.
    //
    // In GUIDED mode an override reason is asked for when a warning is present,
    // so the decision is on the record.
    //
    // In DIRECT mode nothing is asked at all — the technician decides. The same
    // checks still ran a moment ago and their outcome is stored below, so the
    // audit trail knows exactly what was and was not flagged.
    if ($guided && ($anyWarning || $anyError) && $override === ''
        && setting('require_override_reason', '1') === '1') {
        flash('There ' . (count($rowsIn) === 1 ? 'is a warning' : 'are warnings') . ' on this entry. '
            . 'You may continue — please write a short note explaining why, so the decision is recorded.',
            'warn');
        $showOverride = true;
    } else {
        $pdo->beginTransaction();
        try {
            $jobRemarksIn = trim((string)post('job_remarks', ''));
            if ($jobRemarksIn !== '' || post('has_remarks') === '1') {
                $pdo->prepare('UPDATE c_test_jobs SET remarks = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$jobRemarksIn ?: null, $jobId]);
            }

            if (!$rowsIn && $jobRemarksIn === '' && empty($_FILES['job_images']['name'][0])) {
                throw new RuntimeException('Nothing was entered. Please enter readings, write observations, or attach micrographs.');
            }

            // Supersede the previous readings if any new readings were entered
            if ($rowsIn) {
                $st = $pdo->prepare('SELECT id FROM c_readings WHERE job_id = ? AND is_current = 1');
                $st->execute([$jobId]);
                foreach ($st->fetchAll() as $oldRow) {
                    $pdo->prepare('UPDATE c_readings SET is_current = 0 WHERE id = ?')->execute([(int)$oldRow['id']]);
                }
            }

            // Handle direct image uploads if attached
            if (!empty($_FILES['job_images']) && is_array($_FILES['job_images']['name'])) {
                $base = rtrim((string)$CFG['upload_dir'], '/\\');
                $originals = $base . '/original';
                $thumbs    = $base . '/thumb';
                foreach ([$base, $originals, $thumbs] as $d) {
                    if (!is_dir($d)) @mkdir($d, 0775, true);
                }

                // Find report if already attached, or upload against sample/job
                $repId = (int)$pdo->query("SELECT report_id FROM c_report_sections WHERE job_id = $jobId LIMIT 1")->fetchColumn() ?: 0;
                $imgCaption = trim((string)post('job_image_caption', '')) ?: 'Micrograph';
                $imgMag     = trim((string)post('job_image_mag', '')) ?: null;

                for ($fi = 0; $fi < count($_FILES['job_images']['name']); $fi++) {
                    $fName = (string)$_FILES['job_images']['name'][$fi];
                    $fTmp  = (string)$_FILES['job_images']['tmp_name'][$fi];
                    $fErr  = (int)$_FILES['job_images']['error'][$fi];
                    $fSize = (int)$_FILES['job_images']['size'][$fi];

                    if ($fErr !== UPLOAD_ERR_OK || !is_uploaded_file($fTmp)) continue;
                    if ($fSize <= 0 || $fSize > 20 * 1024 * 1024) continue;
                    $fInfo = @getimagesize($fTmp);
                    if (!$fInfo || empty($fInfo['mime'])) continue;
                    $fWidth = (int)($fInfo[0] ?? 0);
                    $fHeight = (int)($fInfo[1] ?? 0);
                    if ($fWidth < 1 || $fHeight < 1 || $fWidth > 12000 || $fHeight > 12000 || $fWidth * $fHeight > 12000000) continue;
                    $fMime = $fInfo['mime'];
                    $fExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$fMime] ?? null;
                    if (!$fExt) continue;

                    $fStored = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $fExt;
                    if (@move_uploaded_file($fTmp, $originals . '/' . $fStored)) {
                        @chmod($originals . '/' . $fStored, 0644);
                        $fThumbName = 't-' . pathinfo($fStored, PATHINFO_FILENAME) . '.jpg';
                        $fThumbPath = $thumbs . '/' . $fThumbName;
                        $fThumb = entry_make_thumbnail($originals . '/' . $fStored, $fThumbPath, $fMime, $fWidth, $fHeight)
                            ? $fThumbName : null;
                        if ($fThumb === null && is_file($fThumbPath)) @unlink($fThumbPath);
                        $fHash = hash_file('sha256', $originals . '/' . $fStored);
                        $pdo->prepare('INSERT INTO c_report_assets
                            (report_id, sample_id, job_id, asset_type, caption, magnification, stored_name, original_name, mime_type, byte_size, sha256, sort_order, uploaded_by, display_size, display_align, thumb_name)
                            VALUES (?, ?, ?, "micrograph", ?, ?, ?, ?, ?, ?, ?, 10, ?, "medium", "center", ?)')
                            ->execute([$repId ?: null, (int)$job['sample_id'], $jobId, $imgCaption, $imgMag, $fStored, $fName, $fMime, $fSize, $fHash, $u['id'], $fThumb]);
                    }
                }
            }

            // Handle pasted base64 images from clipboard (Ctrl+V)
            $pastedImages = (array)post('pasted_images', []);
            if ($pastedImages) {
                $base = rtrim((string)$CFG['upload_dir'], '/\\');
                $originals = $base . '/original';
                $thumbs    = $base . '/thumb';
                $repId = (int)$pdo->query("SELECT report_id FROM c_report_sections WHERE job_id = $jobId LIMIT 1")->fetchColumn() ?: 0;
                $imgCaption = trim((string)post('job_image_caption', '')) ?: 'Micrograph (Pasted)';
                $imgMag     = trim((string)post('job_image_mag', '')) ?: null;

                foreach (array_slice($pastedImages, 0, 10, true) as $idx => $b64) {
                    if (strlen((string)$b64) > 28 * 1024 * 1024) continue;
                    if (preg_match('/^data:image\/(png|jpeg|webp);base64,(.+)$/', (string)$b64, $m)) {
                        $pMime = 'image/' . ($m[1] === 'jpeg' ? 'jpeg' : $m[1]);
                        $pExt  = ($m[1] === 'jpeg') ? 'jpg' : $m[1];
                        $pData = base64_decode($m[2], true);
                        $pInfo = $pData !== false ? @getimagesizefromstring($pData) : false;
                        $pWidth = (int)($pInfo[0] ?? 0);
                        $pHeight = (int)($pInfo[1] ?? 0);
                        if ($pData !== false && strlen($pData) > 50 && strlen($pData) <= 20 * 1024 * 1024
                            && $pInfo && ($pInfo['mime'] ?? '') === $pMime
                            && $pWidth > 0 && $pHeight > 0 && $pWidth <= 12000 && $pHeight <= 12000
                            && $pWidth * $pHeight <= 12000000) {
                            $pStored = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $pExt;
                            $pPath   = $originals . '/' . $pStored;
                            if (@file_put_contents($pPath, $pData)) {
                                @chmod($pPath, 0644);
                                $pThumbName = 't-' . pathinfo($pStored, PATHINFO_FILENAME) . '.jpg';
                                $pThumbPath = $thumbs . '/' . $pThumbName;
                                $pThumb = entry_make_thumbnail($pPath, $pThumbPath, $pMime, $pWidth, $pHeight)
                                    ? $pThumbName : null;
                                if ($pThumb === null && is_file($pThumbPath)) @unlink($pThumbPath);
                                $pHash = hash('sha256', $pData);
                                $pSize = strlen($pData);
                                $pName = 'Pasted_Micrograph_' . ($idx + 1) . '.' . $pExt;

                                $pdo->prepare('INSERT INTO c_report_assets
                                    (report_id, sample_id, job_id, asset_type, caption, magnification, stored_name, original_name, mime_type, byte_size, sha256, sort_order, uploaded_by, display_size, display_align, thumb_name)
                                    VALUES (?, ?, ?, "micrograph", ?, ?, ?, ?, ?, ?, ?, 10, ?, "medium", "center", ?)')
                                    ->execute([$repId ?: null, (int)$job['sample_id'], $jobId, $imgCaption, $imgMag, $pStored, $pName, $pMime, $pSize, $pHash, $u['id'], $pThumb]);
                            }
                        }
                    }
                }
            }

            $eventType = (post('replace') === '1') ? 'update' : 'insert';
            $savedCount = 0; $warnCount = 0;

            foreach ($rowsIn as $ri => $row) {
                $v = validateRow($rules, $row);
                $label = $row['label'];

                foreach ($v['parsed'] as $code => $p) {
                    $rule = $rules[$code] ?? null;
                    if (!$rule) continue;

                    // per-cell validation state
                    $cellVerdict = engine_validate($p, $rule);
                    $state = $cellVerdict['state'];
                    $note  = $cellVerdict['note'];

                    // cross-check messages that concern this measurement
                    foreach ($v['issues'] as $iss) {
                        if ($state === 'ok' && $iss['state'] !== 'ok'
                            && (str_contains($iss['message'], $code) || $iss['code'] === $code
                                || $iss['code'] === 'cross')) {
                            $state = $iss['state'] === 'error' ? 'error' : 'warning';
                            $note  = $note ?: $iss['message'];
                        }
                    }
                    // In Guided mode, continuing past a warning turns its state into
                    // 'overridden' so the record shows a human decision was made.
                    // In Direct mode nothing was overridden — the technician simply
                    // entered a value — so the check outcome is stored as it stands.
                    if ($guided && $state !== 'ok' && $override !== '') $state = 'overridden';
                    if ($state === 'warning' || $state === 'error') $warnCount++;

                    [$canon, $convNote] = engine_to_canonical($p['num'], $p['unit'], $rule);
                    if ($convNote && !$note) $note = $convNote;

                    $pdo->prepare(
                        'INSERT INTO c_readings
                           (job_id, measurement_id, row_index, col_index, row_key,
                            raw_value, value_num, value_text, is_below_detection, is_reference,
                            unit, unit_canonical, value_canonical, entry_type, formula,
                            is_current, validation_state, validation_note, override_reason,
                            mode, entered_by, entered_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,?,?,?,NOW())')
                        ->execute([
                            $jobId, (int)$rule['measurement_id'], $ri, 0, $label ?: null,
                            $p['raw'], $p['num'], $p['text'],
                            $p['bdl'] ? 1 : 0, $p['is_ref'] ? 1 : 0,
                            $p['unit'], $rule['canonical_unit'], $canon,
                            (int)$rule['is_computed'] ? 'computed' : 'entered',
                            $rule['formula'] ?: null,
                            $state, $note ?: null, ($state === 'overridden' ? $override : null),
                            current_mode(),
                            $u['id'],
                        ]);
                    $readingId = (int)$pdo->lastInsertId();
                    $savedCount++;

                    $pdo->prepare('INSERT INTO c_reading_events
                        (reading_id, job_id, measurement_id, event_type, new_raw, reason, actor_id, actor_name)
                        VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([$readingId, $jobId, (int)$rule['measurement_id'], $eventType,
                                   $p['raw'], $override ?: null, $u['id'], $u['full_name']]);
                }
            }

            // Status: 'entered' on save, 'checked' when submitted for approval.
            $newStatus = ($saveMode === 'submit') ? 'checked' : 'entered';
            $pdo->prepare('UPDATE c_test_jobs SET status = ?, entered_by = ?, entered_at = NOW(),
                              updated_at = NOW() WHERE id = ?')
                ->execute([$newStatus, $u['id'], $jobId]);
            if ($job['status'] === 'pending' || $job['status'] === 'in_progress') {
                $pdo->prepare('UPDATE c_samples SET status = "in_testing", updated_at = NOW()
                                WHERE id = ? AND status = "received"')->execute([(int)$job['sample_id']]);
            }

            audit($eventType === 'update' ? 'READINGS_REPLACED' : 'READINGS_ENTERED', 'results', $jobId,
                  null, ['rows' => count($rowsIn), 'values' => $savedCount,
                         'flagged' => $warnCount, 'status' => $newStatus, 'mode' => current_mode()],
                  $override ?: null, (int)$job['sample_id']);

            $pdo->commit();
            if ($guided) {
                flash($savedCount . ' reading(s) stored' . ($warnCount ? ", {$warnCount} with a warning"
                      . ($override !== '' ? ' (you continued and the reason was recorded)' : '') : '') . '.',
                      $warnCount ? 'warn' : 'ok');
            } else {
                // Direct mode says nothing about checks. The count is still in the
                // audit trail for anyone who needs it.
                flash($savedCount . ' reading(s) stored.', 'ok');
            }
            redirect('entry.php?job=' . $jobId . '#attached-micrographs');
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[CTMS-CONSULT] reading save failed: ' . $e->getMessage());
            flash('Could not save: ' . h($e->getMessage()), 'err');
        }
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  COPY FROM A PREVIOUS SAMPLE
// ════════════════════════════════════════════════════════════════════════════
if (is_post() && post('do') === 'copy') {
    $fromJob = (int)post('from_job');
    $src = engine_job_readings($fromJob);
    $copied = 0;
    foreach ($src as $ri => $byCode) {
        foreach ($byCode as $code => $byCol) {
            foreach ($byCol as $col => $r) {
                $pdo->prepare(
                    'INSERT INTO c_readings (job_id, measurement_id, row_index, col_index, row_key,
                        raw_value, value_num, value_text, is_below_detection, is_reference,
                        unit, unit_canonical, value_canonical, entry_type, is_current,
                        validation_state, validation_note, mode, entered_by, entered_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,"copied",1,"not_checked","copied from an earlier job",?,?,NOW())')
                    ->execute([$jobId, (int)$r['measurement_id'], (int)$ri, (int)$col, $r['row_key'],
                               $r['raw_value'], $r['value_num'], $r['value_text'],
                               (int)$r['is_below_detection'], (int)$r['is_reference'],
                               $r['unit'], $r['unit_canonical'], $r['value_canonical'],
                               current_mode(), $u['id']]);
                $copied++;
            }
        }
    }
    audit('READINGS_COPIED', 'results', $jobId, null, ['from_job' => $fromJob, 'values' => $copied],
          'copied from a previous sample', (int)$job['sample_id']);
    flash($copied . ' value(s) copied from the earlier sample. '
        . '<strong>Check every one of them</strong> — they are not your measurements until you have looked at them.',
        'warn');
    redirect('entry.php?job=' . $jobId);
}

// ════════════════════════════════════════════════════════════════════════════
//  DELETE / EDIT A SINGLE SAVED READING
// ════════════════════════════════════════════════════════════════════════════
if (is_post() && post('do') === 'edit_reading') {
    $rid    = (int)post('reading_id');
    $reason = trim((string)post('reason', ''));
    $st = $pdo->prepare('SELECT * FROM c_readings WHERE id = ?');
    $st->execute([$rid]);
    $before = $st->fetch();
    if (!$before) { flash('Reading not found.', 'err'); redirect('entry.php?job=' . $jobId); }
    if ($reason === '') {
        flash('A reason is required to change a saved reading.', 'err');
        redirect('entry.php?job=' . $jobId);
    }
    $def = engine_measurement((int)$before['measurement_id']);
    $rule = $rules[$def['code'] ?? ''] ?? null;
    $newRaw = trim((string)post('new_value', ''));
    $p = $def ? engine_parse($newRaw, $def) : ['num' => null, 'text' => null, 'bdl' => false,
                                               'is_ref' => false, 'unit' => null, 'raw' => $newRaw];
    $verdict = $rule ? engine_validate($p, $rule) : ['state' => 'not_checked', 'note' => ''];
    [$canon] = $rule ? engine_to_canonical($p['num'], $p['unit'], $rule) : [null];

    $pdo->prepare('UPDATE c_readings SET raw_value=?, value_num=?, value_text=?, is_below_detection=?,
                      is_reference=?, unit=?, value_canonical=?, validation_state=?,
                      validation_note=?, override_reason=?, entry_type="entered",
                      entered_by=?, entered_at=NOW()
                    WHERE id=?')
        ->execute([$newRaw, $p['num'], $p['text'], $p['bdl'] ? 1 : 0, $p['is_ref'] ? 1 : 0,
                   $p['unit'], $canon, $verdict['state'],
                   $verdict['note'] ?: "changed: {$reason}", $reason, $u['id'], $rid]);
    $pdo->prepare('INSERT INTO c_reading_events (reading_id, job_id, measurement_id, event_type,
                      old_raw, new_raw, reason, actor_id, actor_name)
                   VALUES (?,?,?,"update",?,?,?,?,?)')
        ->execute([$rid, $jobId, (int)$before['measurement_id'], $before['raw_value'], $newRaw,
                   $reason, $u['id'], $u['full_name']]);
    audit('READING_EDITED', 'results', $rid, ['raw' => $before['raw_value']],
          ['raw' => $newRaw], $reason, $jobId);
    flash('Reading corrected. The previous value is kept in the history.', 'ok');
    redirect('entry.php?job=' . $jobId);
}

if (is_post() && post('do') === 'delete_reading') {
    require_login(['superadmin', 'admin', 'professor', 'technician']);
    $rid    = (int)post('reading_id');
    $reason = trim((string)post('reason', ''));
    $st = $pdo->prepare('SELECT * FROM c_readings WHERE id = ?');
    $st->execute([$rid]);
    $before = $st->fetch();
    if (!$before) { flash('Reading not found.', 'err'); redirect('entry.php?job=' . $jobId); }
    if ($reason === '') {
        flash('A reason is required to remove a reading.', 'err');
        redirect('entry.php?job=' . $jobId);
    }
    $pdo->prepare('UPDATE c_readings SET is_current = 0 WHERE id = ?')->execute([$rid]);
    $pdo->prepare('INSERT INTO c_reading_events (reading_id, job_id, measurement_id, event_type,
                      old_raw, reason, actor_id, actor_name)
                   VALUES (?,?,?,"delete",?,?,?,?)')
        ->execute([$rid, $jobId, (int)$before['measurement_id'], $before['raw_value'],
                   $reason, $u['id'], $u['full_name']]);
    audit('READING_DELETED', 'results', $rid, ['raw' => $before['raw_value']], null, $reason, $jobId);
    flash('Reading removed. It remains in the history permanently.', 'warn');
    redirect('entry.php?job=' . $jobId);
}

// ════════════════════════════════════════════════════════════════════════════
//  CONFIRM (or replace) THE SUGGESTED STANDARD
// ════════════════════════════════════════════════════════════════════════════
if (is_post() && post('do') === 'confirm_standard') {
    $stdChoice  = (string)post('standard_id', '');
    $customText = trim((string)post('custom_standard_text', ''));
    $note       = trim((string)post('note', ''));
    $oldStd     = $job['standard_id'] ? (int)$job['standard_id'] : null;

    if ($stdChoice === 'none') {
        // Explicitly working without standard
        $pdo->prepare('UPDATE c_test_jobs SET standard_id = NULL, standard_text = NULL,
                          standard_is_typed = 1, standard_approved = 1, standard_note = ?,
                          updated_at = NOW() WHERE id = ?')
            ->execute([$note ?: null, $jobId]);
        audit('STANDARD_CONFIRMED', 'jobs', $jobId, ['standard_id' => $oldStd],
              ['standard_id' => null, 'skipped' => true, 'note' => $note], $note ?: null, (int)$job['sample_id']);
        flash('Standard skipped. No standard will be printed on this test.', 'ok');
    } elseif ($stdChoice === 'custom' || $customText !== '') {
        // Technician provided company's own custom standard
        $pdo->prepare('UPDATE c_test_jobs SET standard_id = NULL, standard_text = ?,
                          standard_is_typed = 1, standard_approved = 1, standard_note = ?,
                          updated_at = NOW() WHERE id = ?')
            ->execute([mb_substr($customText, 0, 200), $note ?: null, $jobId]);
        audit('STANDARD_CONFIRMED', 'jobs', $jobId, ['standard_id' => $oldStd],
              ['standard_text' => $customText, 'note' => $note], $note ?: null, (int)$job['sample_id']);
        flash('Custom standard recorded as <strong>' . h($customText) . '</strong>.', 'ok');
    } else {
        $newStd = (int)$stdChoice ?: null;
        $pdo->prepare('UPDATE c_test_jobs SET standard_id = ?, standard_text = NULL,
                          standard_is_typed = 0, standard_approved = 1, standard_note = ?,
                          updated_at = NOW() WHERE id = ?')
            ->execute([$newStd, $note ?: null, $jobId]);
        audit('STANDARD_CONFIRMED', 'jobs', $jobId, ['standard_id' => $oldStd],
              ['standard_id' => $newStd, 'note' => $note], $note ?: null, (int)$job['sample_id']);
        flash($newStd === $oldStd
            ? 'Standard confirmed as <strong>' . h($job['std_code']) . '</strong>.'
            : 'A different standard was chosen for this test.', 'ok');
    }
    redirect('entry.php?job=' . $jobId);
}

// ════════════════════════════════════════════════════════════════════════════
//  DIRECT MODE — the technician writes the standard themselves
//
//  In Direct mode nothing is chosen for the technician. The standard is typed
//  here and printed verbatim. Leaving it blank prints nothing at all: the system
//  never fills in a standard that was not decided by a person.
// ════════════════════════════════════════════════════════════════════════════
if (is_post() && post('do') === 'save_standard_text') {
    $text = trim((string)post('standard_text', ''));
    $old  = (string)($job['standard_text'] ?? '');
    // standard_is_typed records that a PERSON decided this. Without it, clearing
    // the field would let the report quietly fall back to the library standard —
    // printing a standard the technician had deliberately removed.
    $pdo->prepare('UPDATE c_test_jobs SET standard_text = ?, standard_is_typed = 1,
                      standard_approved = 1, updated_at = NOW()
                    WHERE id = ?')
        ->execute([$text !== '' ? mb_substr($text, 0, 200) : null, $jobId]);
    audit('STANDARD_TYPED', 'jobs', $jobId, ['standard_text' => $old],
          ['standard_text' => $text], null, (int)$job['sample_id']);
    flash($text !== ''
        ? 'Standard recorded as <strong>' . h($text) . '</strong> — this is what will print.'
        : 'Standard cleared. Nothing will print on that line.', 'ok');
    redirect('entry.php?job=' . $jobId);
}

// ════════════════════════════════════════════════════════════════════════════
//  PAGE DATA
// ════════════════════════════════════════════════════════════════════════════
$saved = engine_job_readings($jobId);
$rowCount = max($defaultRows, $saved ? max(array_keys($saved)) + 1 : 1, (int)post('rows', 0));

// rows already stored, keyed for the form
$formRows = [];
for ($i = 0; $i < $rowCount; $i++) {
    $formRows[$i] = ['label' => '', 'values' => []];
    if (isset($pieces[$i]) && $job['result_style'] !== 'depth_profile') {
        $formRows[$i]['label'] = $pieces[$i]['piece_code'];
    }
    if (isset($saved[$i])) {
        foreach ($saved[$i] as $code => $byCol) {
            $r = $byCol[0] ?? null;
            if ($r) $formRows[$i]['values'][$code] = $r['raw_value'];
            if ($r && $r['row_key']) $formRows[$i]['label'] = $r['row_key'];
        }
    }
}

// CRITICAL: when a save is held back for a warning, the values the technician
// just typed must come straight back into the form. Re-reading them from the
// database would lose everything they entered and force them to type it again.
if (is_post() && post('do') === 'save') {
    $posted = collectRows($_POST, $rules);
    foreach ($posted as $ri => $row) {
        if (!isset($formRows[$ri])) $formRows[$ri] = ['label' => '', 'values' => []];
        if ($row['label'] !== '') $formRows[$ri]['label'] = $row['label'];
        foreach ($row['values'] as $code => $v) {
            $formRows[$ri]['values'][$code] = $v;
        }
    }
    $rowCount = max($rowCount, $posted ? max(array_keys($posted)) + 1 : 0);
}

// if the count of saved rows exceeds the default, honour that
if ($saved) $rowCount = max($rowCount, max(array_keys($saved)) + 1);
for ($i = 0; $i < $rowCount; $i++) if (!isset($formRows[$i])) $formRows[$i] = ['label' => '', 'values' => []];

// pre-validate what is on screen so warnings show immediately.
// In Direct mode these are still computed — the outcome is recorded on save —
// but they are never put in front of the technician, so the screen stays clean.
$rowChecks = [];
foreach ($formRows as $i => $row) {
    $rowChecks[$i] = validateRow($rules, $row);
}
$guided = in_guided_mode();
if (!$guided) {
    foreach ($rowChecks as $i => $c) {
        $rowChecks[$i]['issues'] = [];   // nothing shown; the checks still ran
    }
}

$similar = in_direct_mode() ? [] : engine_similar_jobs((int)$job['test_type_id'], (string)$job['description'], 8);

// the all-time record for each measurement, to show a reference range
$history = [];
foreach (array_keys($rules) as $code) {
    $def = engine_measurement_by_code($code);
    if (!$def) continue;
    $st = $pdo->prepare('SELECT MIN(value_num), MAX(value_num), COUNT(value_num)
                           FROM c_readings WHERE measurement_id = ? AND is_current = 1 AND value_num IS NOT NULL');
    $st->execute([(int)$def['id']]);
    $h = $st->fetch();
    if ($h && (int)$h['COUNT(value_num)'] > 0) {
        $history[$code] = ['min' => (float)$h['MIN(value_num)'], 'max' => (float)$h['MAX(value_num)'],
                           'n' => (int)$h['COUNT(value_num)']];
    }
}
$gradeVerdicts = $job['grade_claimed'] ? engine_grade_verdict($job['grade_claimed'], []) : [];

$PAGE_TITLE = 'Data entry — ' . $job['test_name'];
$PAGE_SUB   = $job['sample_code'] . ' · ' . ($job['company_name'] ?: 'no company') . ' · ' . $job['description'];
include __DIR__ . '/inc/layout_top.php';
?>

<div class="grid g3" style="margin-bottom:14px">
  <div class="kpi">
    <div class="l">Sample</div>
    <div class="v" style="font-size:16px;padding-top:6px" class="mono"><?= h($job['sample_code']) ?></div>
    <div class="s"><?= h($job['company_name'] ?: '—') ?></div>
  </div>
  <div class="kpi" style="border-left-color:var(--navy-2)">
    <div class="l">Test</div>
    <div class="v" style="font-size:16px;padding-top:6px"><?= h($job['test_name']) ?></div>
    <div class="s"><?= h($job['piece_code'] ? 'Piece ' . $job['piece_code'] : 'whole item') ?></div>
  </div>
  <div class="kpi" style="border-left-color:var(--teal)">
    <div class="l">Status</div>
    <div class="v" style="font-size:16px;padding-top:6px"><?= status_badge($job['status']) ?></div>
    <div class="s"><?= $saved ? count($saved) . ' row(s) saved' : 'not entered yet' ?></div>
  </div>
</div>

<?php if ($jobWasApproved): ?>
<div class="msg msg-warn">
  <strong>This test was already marked approved.</strong>
  You can still correct it — the previous values stay in the history and the change is recorded.
  Only a report that has been despatched to the client is locked.
</div>
<?php endif; ?>

<?php // Guided mode offers a suggested standard and asks for it to be confirmed.
      // Direct mode skips this entirely: the technician decides what was done. ?>
<?php if ($guided && $job['std_code']): ?>
<div class="card" style="border-left:4px solid var(--copper)">
  <h2>Suggested standard — please confirm it is the right one</h2>
  <p>
    <strong class="mono"><?= h($job['std_code']) ?><?= $job['std_rev'] ? ' (' . h($job['std_rev']) . ')' : '' ?></strong>
    — <?= h($job['std_title']) ?>
  </p>
  <?php if ($job['scope_summary']): ?>
    <p class="small muted" style="margin-top:6px"><?= h($job['scope_summary']) ?></p>
  <?php endif; ?>
  <p class="small muted" style="margin-top:8px">
    This was chosen from the standards library for a <strong><?= h($job['test_name']) ?></strong> test.
    If the laboratory is working to a different standard, note it below — the report will print what you decide.
  </p>
  <?php if (!$job['standard_approved']): ?>
  <form method="post" style="margin-top:10px;display:flex;gap:8px;align-items:end;flex-wrap:wrap">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="confirm_standard">
    <input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
    <div><label>Standard choice</label>
      <select name="standard_id" id="std_select" style="width:340px" onchange="document.getElementById('custom_std_wrap').style.display = (this.value === 'custom' ? 'block' : 'none');">
        <option value="<?= (int)$job['standard_id'] ?>"><?= h($job['std_code']) ?> — keep the suggestion</option>
        <option value="none">-- Work without standard (Skip / None) --</option>
        <option value="custom">-- Enter company's own custom standard --</option>
        <?php foreach ($pdo->query('SELECT id, code, title, revision FROM c_standards WHERE is_active=1 ORDER BY code') as $s): ?>
          <?php if ((int)$s['id'] === (int)$job['standard_id']) continue; ?>
          <option value="<?= (int)$s['id'] ?>"><?= h($s['code']) ?><?= $s['revision'] ? ' (' . h($s['revision']) . ')' : '' ?> — <?= h(mb_strimwidth($s['title'], 0, 60, '…')) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div id="custom_std_wrap" style="display:none"><label>Own / Company standard name</label>
      <input type="text" name="custom_standard_text" placeholder="e.g. In-house QC-04 or ISO/Internal" style="width:240px">
    </div>
    <div><label>Why / Note</label><input type="text" name="note" placeholder="optional" style="width:180px"></div>
    <button class="btn btn-sm btn-teal" type="submit">Confirm standard</button>
  </form>
  <?php else: ?>
    <div style="display:flex;align-items:center;gap:10px;margin-top:8px">
      <?= badge(!empty($job['standard_text']) ? 'Custom: ' . $job['standard_text'] : ($job['std_code'] ? 'Standard: ' . $job['std_code'] : 'No standard (skipped)'), 'green') ?>
      <button type="button" class="btn btn-sm btn-ghost" onclick="document.getElementById('change_std_form').style.display='flex'">Change standard</button>
    </div>
    <form id="change_std_form" method="post" style="display:none;margin-top:10px;gap:8px;align-items:end;flex-wrap:wrap">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="confirm_standard">
      <input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
      <div><label>Change standard</label>
        <select name="standard_id" style="width:340px" onchange="document.getElementById('change_custom_std_wrap').style.display = (this.value === 'custom' ? 'block' : 'none');">
          <option value="none">-- Work without standard (Skip / None) --</option>
          <option value="custom">-- Enter company's own custom standard --</option>
          <?php foreach ($pdo->query('SELECT id, code, title, revision FROM c_standards WHERE is_active=1 ORDER BY code') as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === (int)$job['standard_id'] ? 'selected' : '' ?>><?= h($s['code']) ?><?= $s['revision'] ? ' (' . h($s['revision']) . ')' : '' ?> — <?= h(mb_strimwidth($s['title'], 0, 60, '…')) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div id="change_custom_std_wrap" style="display:none"><label>Own / Company standard name</label>
        <input type="text" name="custom_standard_text" placeholder="e.g. In-house QC-04" style="width:240px">
      </div>
      <div><label>Why / Note</label><input type="text" name="note" placeholder="optional" style="width:180px"></div>
      <button class="btn btn-sm btn-teal" type="submit">Update standard</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php // DIRECT MODE: the standard is typed here, never chosen for the technician.
      // Blank means nothing prints — the system does not invent a standard. ?>
<?php if (!$guided): ?>
<div class="card" style="border-left:4px solid var(--navy-2)">
  <h2>Standard used for this test</h2>
  <p class="small muted" style="margin-bottom:10px">
    Type the standard this test was carried out to. <strong>Exactly what you type is what
    prints</strong> on the report. Leave it blank and that line prints nothing at all —
    nothing is filled in for you.
  </p>
  <form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save_standard_text">
    <input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
    <div><label for="standard_text">Standard</label>
      <input id="standard_text" name="standard_text" type="text" style="width:420px"
             value="<?= h((string)($job['standard_text'] ?? '')) ?>"
             placeholder="e.g. ASTM E572 (2022) — or leave blank"></div>
    <button class="btn btn-sm btn-teal" type="submit">Save standard</button>
    <?php if (!empty($job['standard_text'])): ?>
      <span class="small muted">Saved — this is what the report prints.</span>
    <?php endif; ?>
  </form>
</div>
<?php endif; ?>

<?php if ($similar): ?>
<div class="card">
  <h2>Copy values from a previous sample</h2>
  <p class="small muted" style="margin-bottom:10px">
    Same material, same test — load the earlier readings and change only what differs.
    Every copied value is marked as copied, and you must check each one before submitting.
  </p>
  <form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap"
        onsubmit="return confirm('Copy the earlier readings into this job? You must check every value afterwards.');">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="copy">
    <input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
    <div style="flex:1;min-width:420px">
      <label>Earlier jobs of this same test</label>
      <select name="from_job" required>
        <option value="">choose a previous job…</option>
        <?php foreach ($similar as $s): ?>
          <option value="<?= (int)$s['job_id'] ?>">
            <?= h($s['sample_code']) ?> · <?= h(mb_strimwidth((string)$s['description'], 0, 34, '…')) ?>
            · <?= h($s['company_name'] ?: '—') ?> · <?= (int)$s['readings'] ?> values
            · <?= $s['entered_at'] ? h(date('d M Y', strtotime($s['entered_at']))) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-copper" type="submit">Copy those values here</button>
  </form>
</div>
<?php endif; ?>

<?php if ($isElementComposition): ?>
<div class="card" style="border-left:4px solid var(--teal)">
  <h2>Elements measured in this XRF test</h2>
  <p class="small muted">Choose only the elements your instrument reported. Add an element here and its column will be saved and included in the report. Removing a column leaves any earlier readings in history.</p>
  <form method="post" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-top:10px">
    <?= csrf_field() ?><input type="hidden" name="do" value="manage_elements"><input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
    <div><label for="library_element_id">Add an element from the library</label>
      <select id="library_element_id" name="library_element_id" style="min-width:260px">
        <option value="0">Select element…</option>
        <?php foreach ($availableElements as $el): ?><option value="<?= (int)$el['id'] ?>"><?= h($el['name']) ?><?= $el['symbol'] ? ' · ' . h($el['symbol']) : '' ?></option><?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-sm btn-teal" type="submit" <?= !$availableElements ? 'disabled' : '' ?>>Add selected</button>
    <span class="small muted">or add a custom element:</span>
    <div><label for="custom_element_name">Element name</label><input id="custom_element_name" name="custom_element_name" maxlength="160" placeholder="e.g. Zirconium"></div>
    <div><label for="custom_element_symbol">Symbol / code</label><input id="custom_element_symbol" name="custom_element_symbol" maxlength="40" placeholder="e.g. Zr"></div>
    <button class="btn btn-sm btn-ghost" type="submit" onclick="document.getElementById('library_element_id').value='0'">Add custom</button>
    <div style="flex-basis:100%;display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:4px">
      <strong class="small">Selected:</strong>
      <?php foreach ($rules as $elementCode => $elementRule): if (empty($elementRule['is_analyte'])) continue; ?>
        <span class="badge" style="display:inline-flex;align-items:center;gap:5px;padding:5px">
          <input aria-label="Report label for <?= h($elementRule['m_name']) ?>" name="element_label[<?= (int)$elementRule['measurement_id'] ?>]" value="<?= h($elementRule['column_label'] ?: $elementRule['m_symbol'] ?: $elementRule['m_name']) ?>" maxlength="120" style="width:72px;padding:3px">
          <button type="submit" name="edit_measurement" value="<?= (int)$elementRule['measurement_id'] ?>" title="Save report label" style="border:0;background:none;color:var(--navy);cursor:pointer">Save</button>
          <button type="submit" name="remove_measurement" value="<?= (int)$elementRule['measurement_id'] ?>" title="Remove <?= h($elementRule['m_name']) ?>" style="border:0;background:none;color:#9b2c2c;cursor:pointer;font-weight:bold" onclick="return confirm('Remove this element from this test? Saved readings will remain in history.')">×</button>
        </span>
      <?php endforeach; ?>
    </div>
  </form>
</div>
<?php endif; ?>

<form method="post" id="entryForm" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="save">
  <input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
  <input type="hidden" name="rows" value="<?= (int)$rowCount ?>">
  <input type="hidden" name="replace" value="<?= $saved ? '1' : '0' ?>">
  <input type="hidden" name="has_remarks" value="1">

  <div class="card">
    <h2><?= $rules ? 'Readings' : 'Microstructure Observations & Remarks' ?></h2>
    <?php if (!$rules): ?>
      <p class="small muted" style="margin-bottom:10px">
        Microstructural examinations are visual and qualitative. Enter your metallographic findings,
        grain structure, inclusions, or phase distribution below, and attach micrograph images.
      </p>
      <div class="field">
        <label for="job_remarks"><strong>Microstructural Findings &amp; Observations</strong></label>
        <textarea id="job_remarks" name="job_remarks" rows="4" style="width:100%;font-family:inherit;padding:8px"
                  placeholder="e.g. Ferrite-pearlite structure observed with uniform grain size ASTM No. 7-8. No excessive non-metallic inclusions detected."><?= h($job['remarks'] ?? '') ?></textarea>
      </div>

      <div style="margin-top:14px;padding:12px;background:#f9fafb;border:1px dashed #cbd5e1;border-radius:6px" id="dropZoneMicrograph">
        <span id="micrograph-upload"></span>
        <h3 style="font-size:13px;margin-bottom:6px">Attach Micrograph Images</h3>
        <p class="small muted" style="margin-bottom:8px">
          Select files, drag and drop, or <strong>paste directly from your clipboard (Ctrl + V)</strong>.
        </p>
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end">
          <div style="flex:1;min-width:200px">
            <label class="small muted">Image file(s) (JPEG / PNG / WebP)</label>
            <input type="file" id="fileInputMicrograph" name="job_images[]" multiple accept="image/jpeg,image/png,image/webp">
          </div>
          <div>
            <label class="small muted">Magnification</label>
            <input type="text" name="job_image_mag" placeholder="e.g. 100X, 500X" style="width:120px">
          </div>
          <div style="flex:1;min-width:180px">
            <label class="small muted">Caption</label>
            <input type="text" name="job_image_caption" placeholder="e.g. Etched with 2% Nital" style="width:100%">
          </div>
        </div>
        <div id="pastedPreview" style="display:none;margin-top:10px;padding:8px;background:#fff;border:1px solid #cbd5e1;border-radius:4px">
          <span class="small" style="font-weight:600;color:#1B2A4A">Pasted image ready to save:</span>
          <div id="pastedThumbs" style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap"></div>
        </div>
        <div id="pastedInputs"></div>
      </div>

      <?php
        $stImg = $pdo->prepare('SELECT * FROM c_report_assets WHERE job_id = ? ORDER BY sort_order, id');
        $stImg->execute([$jobId]);
        $jobAssets = $stImg->fetchAll();
      ?>
      <?php if ($jobAssets): ?>
        <div style="margin-top:14px" id="attached-micrographs">
          <h3 style="font-size:13px;margin-bottom:6px">Attached Micrographs (<?= count($jobAssets) ?>)</h3>
          <p class="small muted" style="margin:0 0 8px">Select <strong>Mark up</strong> to add depth lines, labels, arrows, or crop a border. Save the mark-up to include it in the report.</p>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <?php foreach ($jobAssets as $ja): ?>
              <div style="border:1px solid #e2e8f0;padding:6px;border-radius:6px;background:#fff;text-align:center;width:140px">
                <img src="<?= h(app_url('uploads/original/' . rawurlencode((string)$ja['stored_name']))) ?>"
                     style="width:120px;height:80px;object-fit:cover;border-radius:4px" alt="">
                <div style="font-size:11px;font-weight:600;margin-top:4px"><?= h($ja['caption']) ?></div>
                <div class="small muted"><?= h($ja['magnification'] ?: '') ?></div>
                <a href="<?= h(app_url('images.php?id=' . (int)$ja['id'])) ?>" class="btn btn-sm btn-ghost" style="margin-top:4px;padding:2px 8px;font-size:10px">Mark up</a>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

    <?php else: ?>
      <p class="small muted" style="margin-bottom:10px">
        Type the value exactly as the instrument or your notes give it. Values like
        <span class="mono">&lt; 0.001</span>, <span class="mono">Balance</span> or
        <span class="mono">Max 0.23</span> are understood and stored as written.
        Warnings appear underneath and <strong>never stop you</strong>.
      </p>
      <div class="table-wrap">
        <table id="grid">
          <thead>
            <tr>
              <th style="width:120px"><?= $job['result_style'] === 'depth_profile' ? 'Depth point' : 'Piece / row' ?></th>
              <?php foreach ($rules as $code => $rule): ?>
                <th title="<?= h($rule['m_name']) ?><?= $rule['canonical_unit'] ? ' in ' . h($rule['canonical_unit']) : '' ?>">
                  <?= h($rule['column_label'] ?: $rule['m_name']) ?>
                  <?php if ($rule['canonical_unit']): ?><br><span style="font-weight:400;opacity:.75"><?= h($rule['canonical_unit']) ?></span><?php endif; ?>
                  <?php if ((int)$rule['is_computed']): ?><br><span style="font-weight:400;opacity:.75">calculated</span><?php endif; ?>
                </th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($formRows as $ri => $row): ?>
            <?php $chk = $rowChecks[$ri]; ?>
            <tr>
              <td>
                <input type="text" name="r[<?= $ri ?>][__label]" value="<?= h($row['label']) ?>"
                       placeholder="<?= $job['result_style'] === 'depth_profile' ? 'e.g. 0.05 mm' : 'piece' ?>"
                       style="font-family:var(--mono);font-size:12px">
              </td>
              <?php foreach ($rules as $code => $rule):
                $val = $row['values'][$code] ?? '';
                $cellState = 'ok';
                foreach ($chk['issues'] as $iss) {
                    if ($iss['state'] !== 'ok' &&
                        (str_contains($iss['message'], (string)$code) || $iss['code'] === $code)) {
                        $cellState = $iss['state'];
                    }
                }
                $cls = $cellState === 'error' ? 'cell-input err' : ($cellState === 'warning' ? 'cell-input warn' : 'cell-input');
                $isRef = isset($chk['parsed'][$code]) && $chk['parsed'][$code]['is_ref'];
              ?>
                <td>
                  <input type="text" inputmode="decimal" class="<?= $cls ?><?= $isRef ? ' ref' : '' ?>"
                         name="r[<?= $ri ?>][<?= h($code) ?>]" value="<?= h($val) ?>"
                         data-code="<?= h($code) ?>" data-row="<?= $ri ?>"
                         placeholder="<?= $rule['canonical_unit'] ? h($rule['canonical_unit']) : '' ?>">
                </td>
              <?php endforeach; ?>
            </tr>
            <?php if ($chk['issues']): ?>
              <tr><td colspan="<?= count($rules) + 1 ?>" style="background:#fff;border-bottom:1px solid var(--border)">
                <?php foreach ($chk['issues'] as $iss): ?>
                  <div class="chk chk-<?= $iss['state'] === 'error' ? 'error' : 'warning' ?>">
                    <strong><?= $iss['state'] === 'error' ? 'Impossible' : 'Check this' ?>:</strong>
                    <?= h($iss['message']) ?>
                  </div>
                <?php endforeach; ?>
              </td></tr>
            <?php endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="toolbar" style="margin-top:12px">
        <button type="button" class="btn btn-sm btn-ghost" id="addRow">+ Add a row</button>
        <button type="button" class="btn btn-sm btn-ghost" id="pasteHelp">Paste from Excel</button>
        <span class="small muted">Click any cell and press Ctrl+V to paste a column straight from Excel.</span>
      </div>
    <?php endif; ?>
  </div>

  <?php // The historical range is a sanity check, so it belongs to Guided mode only. ?>
  <?php if ($guided && !empty($history)): ?>
  <div class="card">
    <h2>What this laboratory has recorded before</h2>
    <p class="small muted" style="margin-bottom:10px">
      The full range of every value ever entered for this test. Useful as a sanity check —
      <strong>not</strong> a limit. Values outside this range are perfectly possible.
    </p>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Measurement</th><th class="right">Lowest ever</th><th class="right">Highest ever</th><th class="right">Times recorded</th></tr></thead>
        <tbody>
        <?php foreach ($history as $code => $h): ?>
          <tr>
            <td><?= h($rules[$code]['column_label'] ?: $rules[$code]['m_name']) ?></td>
            <td class="num"><?= h(fmt($h['min'], 3)) ?></td>
            <td class="num"><?= h(fmt($h['max'], 3)) ?></td>
            <td class="num"><?= (int)$h['n'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>Saving</h2>
    <?php if ($guided): ?>
    <div class="field">
      <label for="override_reason">If you are continuing past a warning, note why</label>
      <input id="override_reason" name="override_reason" type="text"
             value="<?= h(post('override_reason', '')) ?>"
             placeholder="e.g. machine reading confirmed by hand; sample surface irregular">
      <div class="hint">
        Needed once, if any warning is present. It is stored against every value it covers, so a
        reviewer can see exactly why the entry was accepted.
      </div>
    </div>
    <?php else: ?>
    <p class="small muted" style="margin-bottom:10px">
      Direct mode — enter what the test gave you. Nothing is flagged and no reason is asked for.
    </p>
    <?php endif; ?>
    <div class="toolbar">
      <button class="btn" type="submit" name="save_mode" value="save">Save (keep as draft)</button>
      <button class="btn btn-teal" type="submit" name="save_mode" value="submit">Save and submit for approval</button>
      <a class="btn btn-ghost" href="<?= h(app_url('samples.php?action=view&id=' . (int)$job['sample_id'])) ?>">Back to sample</a>
    </div>
  </div>
</form>

<?php if ($saved): ?>
<div class="card">
  <h2>Saved readings — correct or remove any of them</h2>
  <p class="small muted" style="margin-bottom:10px">
    Nothing is ever truly erased. A change or a removal is recorded with its reason and can be
    reviewed later, which is what an accredited laboratory needs.
  </p>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Row</th><th>Measurement</th><th class="right">Value</th><th>Unit</th>
                 <th>State</th><th>Note</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($saved as $ri => $byCode): foreach ($byCode as $code => $byCol): foreach ($byCol as $col => $r):
        $stateTone = ['ok' => 'green', 'warning' => 'amber', 'error' => 'red', 'overridden' => 'blue', 'not_checked' => 'grey'][$r['validation_state']] ?? 'grey';
      ?>
        <tr>
          <td class="mono"><?= h($r['row_key'] ?: $ri) ?></td>
          <td><?= h($r['m_name']) ?></td>
          <td class="num">
            <?php if ($r['is_below_detection']): ?>below detection
            <?php elseif ($r['value_text'] && $r['value_num'] === null): ?><em><?= h($r['value_text']) ?></em>
            <?php else: ?><?= h($r['raw_value']) ?><?php endif; ?>
          </td>
          <td class="small"><?= h($r['unit'] ?: $r['canonical_unit'] ?: '—') ?></td>
          <td><?= badge($r['validation_state'], $stateTone) ?></td>
          <td class="small muted"><?= h(mb_strimwidth((string)$r['validation_note'], 0, 70, '…')) ?></td>
          <td class="nowrap">
            <button type="button" class="btn btn-sm btn-ghost"
                    onclick="document.getElementById('ed<?= (int)$r['id'] ?>').style.display='table-row'">Correct</button>
            <button type="button" class="btn btn-sm btn-danger"
                    onclick="document.getElementById('rm<?= (int)$r['id'] ?>').style.display='table-row'">Remove</button>
          </td>
        </tr>
        <tr id="ed<?= (int)$r['id'] ?>" style="display:none;background:var(--amber-light)">
          <td colspan="7">
            <form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="edit_reading">
              <input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
              <input type="hidden" name="reading_id" value="<?= (int)$r['id'] ?>">
              <div><label>New value for <?= h($r['m_name']) ?></label>
                <input type="text" name="new_value" value="<?= h($r['raw_value']) ?>" style="width:160px"></div>
              <div><label>Reason *</label>
                <input type="text" name="reason" required style="width:280px" placeholder="e.g. transcription error, re-read"></div>
              <button class="btn btn-sm" type="submit">Save correction</button>
            </form>
          </td>
        </tr>
        <tr id="rm<?= (int)$r['id'] ?>" style="display:none;background:var(--rust-light)">
          <td colspan="7">
            <form method="post" style="display:flex;gap:8px;align-items:end"
                  onsubmit="return confirm('Remove this reading?');">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="delete_reading">
              <input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
              <input type="hidden" name="reading_id" value="<?= (int)$r['id'] ?>">
              <div><label>Reason *</label><input type="text" name="reason" required style="width:320px"></div>
              <button class="btn btn-sm btn-danger" type="submit">Confirm removal</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endforeach; endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  // ── add a row ──
  var body = document.querySelector('#grid tbody');
  document.getElementById('addRow')?.addEventListener('click', function () {
    var last = body.querySelector('tr:last-child');
    var tr = last.cloneNode(true);
    var n = body.querySelectorAll('tr').length;
    tr.querySelectorAll('input').forEach(function (el) {
      el.value = '';
      el.classList.remove('warn', 'err');
      el.name = el.name.replace(/r\[\d+\]/, 'r[' + n + ']');
    });
    body.appendChild(tr);
  });

  // ── paste clipboard image for micrographs OR paste column from Excel ──
  document.addEventListener('paste', function (e) {
    // 1. Check if an image is in the clipboard
    var items = (e.clipboardData || e.originalEvent?.clipboardData)?.items;
    if (items) {
      for (var i = 0; i < items.length; i++) {
        if (items[i].type.indexOf('image') !== -1) {
          var blob = items[i].getAsFile();
          var reader = new FileReader();
          reader.onload = function (ev) {
            var b64 = ev.target.result;
            var container = document.getElementById('pastedInputs');
            var preview = document.getElementById('pastedPreview');
            var thumbs = document.getElementById('pastedThumbs');
            if (container && preview && thumbs) {
              preview.style.display = 'block';
              var input = document.createElement('input');
              input.type = 'hidden';
              input.name = 'pasted_images[]';
              input.value = b64;
              container.appendChild(input);

              var img = document.createElement('img');
              img.src = b64;
              img.style.cssText = 'width:90px;height:65px;object-fit:cover;border:1px solid #C2703D;border-radius:4px;';
              thumbs.appendChild(img);
              dirty = true;
            }
          };
          reader.readAsDataURL(blob);
          return; // image handled
        }
      }
    }

    // 2. Otherwise handle table column paste
    var el = document.activeElement;
    if (!el || !el.dataset || !el.dataset.code) return;
    var text = (e.clipboardData || window.clipboardData).getData('text');
    if (!text || text.indexOf('\n') === -1) return;   // single value: let it paste normally
    e.preventDefault();
    var lines = text.replace(/\r/g, '').split('\n').filter(function (l) { return l.trim() !== ''; });
    var row = parseInt(el.dataset.row, 10);
    lines.forEach(function (val, i) {
      var target = document.querySelector('input[data-row="' + (row + i) + '"][data-code="' + el.dataset.code + '"]');
      if (target) { target.value = val.trim(); target.classList.add('warn'); }
    });
    alert(lines.length + ' value(s) pasted down the ' + el.dataset.code + ' column.\n\n'
        + 'They are marked in yellow until you save, so you can see what changed.');
  });

  // ── Drag & drop for micrograph zone ──
  var dz = document.getElementById('dropZoneMicrograph');
  if (dz) {
    ['dragenter', 'dragover'].forEach(function (evName) {
      dz.addEventListener(evName, function (e) { e.preventDefault(); dz.style.background = '#edf2f7'; });
    });
    ['dragleave', 'drop'].forEach(function (evName) {
      dz.addEventListener(evName, function (e) { e.preventDefault(); dz.style.background = '#f9fafb'; });
    });
    dz.addEventListener('drop', function (e) {
      var dt = e.dataTransfer;
      var fi = document.getElementById('fileInputMicrograph');
      if (dt && dt.files && fi) {
        fi.files = dt.files;
        alert(dt.files.length + ' file(s) dropped into micrograph upload.');
      }
    });
  }

  // ── Real-time calculation previews (Area, UTS) ──
  function updateLiveCalculations() {
    var rows = document.querySelectorAll('#grid tbody tr');
    rows.forEach(function (tr) {
      var wInput = tr.querySelector('input[data-code="DIM_WIDTH"]');
      var tInput = tr.querySelector('input[data-code="DIM_THICKNESS"]');
      var dInput = tr.querySelector('input[data-code="DIM_DIAMETER"]');
      var aInput = tr.querySelector('input[data-code="CROSS_SECTION_AREA"]');
      var ylInput = tr.querySelector('input[data-code="YIELD_LOAD"]');
      var mlInput = tr.querySelector('input[data-code="MAX_LOAD"]');
      var ysInput = tr.querySelector('input[data-code="YS"]');
      var utsInput = tr.querySelector('input[data-code="UTS"]');

      var w = wInput ? parseFloat(wInput.value) : NaN;
      var t = tInput ? parseFloat(tInput.value) : NaN;
      var d = dInput ? parseFloat(dInput.value) : NaN;

      var area = NaN;
      if (!isNaN(w) && !isNaN(t) && w > 0 && t > 0) area = w * t;
      else if (!isNaN(d) && d > 0) area = (Math.PI / 4) * d * d;

      if (!isNaN(area) && aInput && (!aInput.value || aInput.dataset.auto === '1')) {
        aInput.placeholder = 'calc: ' + area.toFixed(2);
      }

      var currentArea = (aInput && parseFloat(aInput.value)) ? parseFloat(aInput.value) : area;
      if (!isNaN(currentArea) && currentArea > 0) {
        if (mlInput && utsInput && (!utsInput.value || utsInput.dataset.auto === '1')) {
          var ml = parseFloat(mlInput.value);
          if (!isNaN(ml) && ml > 0) {
            var rawUTS = (ml * 1000) / currentArea;
            utsInput.placeholder = 'calc: ' + rawUTS.toFixed(1);
          }
        }
        if (ylInput && ysInput && (!ysInput.value || ysInput.dataset.auto === '1')) {
          var yl = parseFloat(ylInput.value);
          if (!isNaN(yl) && yl > 0) {
            var rawYS = (yl * 1000) / currentArea;
            ysInput.placeholder = 'calc: ' + rawYS.toFixed(1);
          }
        }
      }

      // Inline validation: UTS must not be below YS
      if (ysInput && utsInput && ysInput.value && utsInput.value) {
        var ysVal = parseFloat(ysInput.value);
        var utsVal = parseFloat(utsInput.value);
        if (!isNaN(ysVal) && !isNaN(utsVal) && utsVal < ysVal) {
          utsInput.classList.add('err');
          utsInput.title = 'Impossible: UTS is lower than Yield Strength!';
        } else {
          utsInput.classList.remove('err');
          utsInput.title = '';
        }
      }
    });
  }

  document.querySelector('#grid')?.addEventListener('input', updateLiveCalculations);
  updateLiveCalculations();

  document.getElementById('pasteHelp')?.addEventListener('click', function () {
    alert('Copy a column of values from Excel, click the first cell you want them in, and press Ctrl+V.\n\n'
        + 'The values fill downwards from that cell. They are highlighted so you can see what changed.');
  });

  // ── warn before leaving with unsaved changes ──
  var dirty = false;
  document.getElementById('entryForm')?.addEventListener('input', function () { dirty = true; });
  document.getElementById('entryForm')?.addEventListener('submit', function () { dirty = false; });
  window.addEventListener('beforeunload', function (e) {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
  });
})();
</script>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
