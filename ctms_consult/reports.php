<?php
declare(strict_types=1);
/**
 * Reports — workflow step 4.
 *
 * A report is assembled from tests that have been entered and approved. The
 * builder chooses which tests appear and in what order, adds the professor's
 * summary and the standing remarks, then issues the document.
 *
 * Issuing freezes the report: it becomes read-only, and the barcode on the
 * printed page can only ever refer to that issued content.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/engine.php';
require_once __DIR__ . '/inc/report.php';

$u = require_login();
$action = (string)get('action', 'list');
$id     = (int)get('id', 0);

// ════════════════════════════════════════════════════════════════════════════
//  CREATE  a report from a sample's approved tests
// ════════════════════════════════════════════════════════════════════════════
if (is_post() && post('do') === 'create') {
    $sampleId = (int)post('sample_id');
    $st = $pdo->prepare('SELECT s.*, i.inv_number FROM c_samples s
                          LEFT JOIN c_billing i ON i.id = s.invoice_id WHERE s.id = ?');
    $st->execute([$sampleId]);
    $smp = $st->fetch();
    if (!$smp) { flash('Sample not found.', 'err'); redirect('reports.php'); }

    // which jobs to include
    $jobIds = array_values(array_filter(array_map('intval', (array)post('jobs', []))));
    if (!$jobIds) { flash('Choose at least one completed test for this report.', 'err'); redirect('reports.php?action=new&sample=' . $sampleId); }

    $pdo->beginTransaction();
    try {
        $seq  = next_number('report');
        $prefix = setting('report_prefix', 'RPT');
        $num  = sprintf('%s-%s-%04d', $prefix, date('Y'), $seq);
        $secret = setting('verify_secret', '') ?: 'ctms-consult';

        // test date: the latest date found among the selected tests
        $in = implode(',', array_fill(0, count($jobIds), '?'));
        $st = $pdo->prepare("SELECT MAX(entered_at) FROM c_test_jobs WHERE id IN ($in)");
        $st->execute($jobIds);
        $lastEntered = (string)$st->fetchColumn();

        // Which standards are in play? Printed in the information block so the
        // report states the method it was carried out under, as the lab's
        // existing reports do.
        //
        // When the technician decided the standard themselves (Direct mode,
        // standard_is_typed), only what they typed is used — and if they cleared it,
        // nothing is printed. The system never substitutes a library standard for a
        // decision a person made.
        $st = $pdo->prepare("SELECT DISTINCT tt.name AS test_name, st.code AS std_code, st.revision,
                                    j.standard_text, j.standard_is_typed
                               FROM c_test_jobs j
                               JOIN c_test_types tt ON tt.id = j.test_type_id
                               LEFT JOIN c_standards st ON st.id = j.standard_id
                              WHERE j.id IN ($in)
                              ORDER BY tt.sort_order");
        $st->execute($jobIds);
        $parts = [];
        foreach ($st->fetchAll() as $row) {
            if ((int)($row['standard_is_typed'] ?? 0) === 1) {
                $t = trim((string)($row['standard_text'] ?? ''));
                if ($t !== '') $parts[] = $t;
                continue;
            }
            $parts[] = $row['std_code']
                ? $row['std_code'] . ($row['revision'] ? ' (' . $row['revision'] . ')' : '')
                : $row['test_name'];
        }
        $stdNote = implode('; ', array_unique($parts));

        $header = [
            'orientation'     => 'landscape',
            'type_of_sample'  => $smp['description'],
            'item_description'=> $smp['description'],
            'grade_match'     => $smp['grade_confirmed'] ?: $smp['grade_claimed'],
            'test_date'       => $lastEntered ? date('d M Y', strtotime($lastEntered)) : date('d M Y'),
            'received'        => $smp['received_date'] ? date('d M Y', strtotime($smp['received_date'])) : '',
            'reference'       => $smp['inv_number'] ? 'Inv ' . $smp['inv_number'] : '',
            // Fill in the standards actually used, so the printed block is never blank.
            'standard_note'   => $stdNote,
        ];

        $pdo->prepare('INSERT INTO c_reports (report_number, sample_id, invoice_id, company_name, title,
                          status, header_json, remarks_html, revision_no, created_by)
                       VALUES (?,?,?,?,?,"draft",?,?,1,?)')
            ->execute([$num, $sampleId, $smp['invoice_id'], $smp['company_name'],
                       'Test Report — ' . $smp['description'], json_encode($header, JSON_UNESCAPED_UNICODE),
                       setting('report_default_remarks', ''), $u['id']]);
        $reportId = (int)$pdo->lastInsertId();

        $ord = 0;
        foreach ($jobIds as $jid) {
            $pdo->prepare('INSERT INTO c_report_sections (report_id, job_id, title, section_order, is_included)
                           VALUES (?,?,?,?,1)')
                ->execute([$reportId, $jid, null, $ord++]);
        }

        $vcode = ctms_verify_code($num, $secret);
        $pdo->prepare('UPDATE c_reports SET verify_code = ?, barcode_value = ? WHERE id = ?')
            ->execute([$vcode, ctms_barcode_value($num, $vcode), $reportId]);

        audit('REPORT_CREATED', 'reports', $reportId, null,
              ['report_number' => $num, 'sample' => $smp['sample_code'], 'sections' => count($jobIds)],
              null, $sampleId);
        $pdo->commit();
        flash('Report <strong>' . h($num) . '</strong> created as a draft.', 'ok');
        redirect('reports.php?action=build&id=' . $reportId);
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[CTMS-CONSULT] report create failed: ' . $e->getMessage());
        flash('Could not create the report: ' . h($e->getMessage()), 'err');
        redirect('reports.php');
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  SAVE  the builder (sections, summary, header, remarks)
// ════════════════════════════════════════════════════════════════════════════
if (is_post() && post('do') === 'save') {
    $reportId = (int)post('id');
    $st = $pdo->prepare('SELECT * FROM c_reports WHERE id = ?');
    $st->execute([$reportId]);
    $before = $st->fetch();
    if (!$before) { flash('Report not found.', 'err'); redirect('reports.php'); }
    if ($before['status'] === 'issued') {
        flash('An issued report is read-only. Create a revision to change it.', 'err');
        redirect('reports.php?action=view&id=' . $reportId);
    }

    $pdo->beginTransaction();
    try {
        $header = json_decode((string)$before['header_json'], true) ?: [];
        foreach (['orientation','type_of_sample','item_description','grade_match','test_date',
                  'received','reference','standard_note'] as $k) {
            if (array_key_exists($k, $_POST)) $header[$k] = trim((string)post($k, ''));
        }

        $pdo->prepare('UPDATE c_reports SET header_json = ?, summary_html = ?, remarks_html = ?,
                          title = ?, updated_at = NOW() WHERE id = ?')
            ->execute([json_encode($header, JSON_UNESCAPED_UNICODE),
                       trim((string)post('summary', '')),
                       trim((string)post('remarks', '')),
                       trim((string)post('title', '')) ?: $before['title'],
                       $reportId]);

        // sections: each posted row is an included test, in the given order
        $pdo->prepare('UPDATE c_report_sections SET is_included = 0 WHERE report_id = ?')->execute([$reportId]);
        $order = 0;
        foreach ((array)post('sections', []) as $sec) {
            $jid = (int)($sec['job_id'] ?? 0);
            if (!$jid) continue;
            $inc = !empty($sec['include']) ? 1 : 0;
            $st = $pdo->prepare('SELECT id FROM c_report_sections WHERE report_id = ? AND job_id = ?');
            $st->execute([$reportId, $jid]);
            $secId = $st->fetchColumn();
            if ($secId) {
                $pdo->prepare('UPDATE c_report_sections SET is_included = ?, title = ?, section_order = ?
                                WHERE id = ?')
                    ->execute([$inc, trim((string)($sec['title'] ?? '')) ?: null, $order++, (int)$secId]);
            }
        }

        audit('REPORT_UPDATED', 'reports', $reportId, ['title' => $before['title']],
              ['title' => post('title')], trim((string)post('reason', '')) ?: null, (int)$before['sample_id']);

        // Keep the printed "Standard Test Method for" line in step with the
        // sections that are actually included, so it can never be wrong or blank.
        //
        // When the technician decided the standard themselves in Direct mode
        // (standard_is_typed), ONLY what they typed is used. If they cleared it, the
        // line stays empty — the report must never print a library standard that a
        // person deliberately removed.
        $st = $pdo->prepare(
            'SELECT DISTINCT st.code AS lib_code, st.revision, tt.name AS test_name,
                    j.standard_text, j.standard_is_typed
               FROM c_report_sections rs
               JOIN c_test_jobs  j  ON j.id  = rs.job_id
               JOIN c_test_types tt ON tt.id = j.test_type_id
               LEFT JOIN c_standards st ON st.id = j.standard_id
              WHERE rs.report_id = ? AND rs.is_included = 1
              ORDER BY tt.sort_order');
        $st->execute([$reportId]);
        $labels = [];
        foreach ($st->fetchAll() as $row) {
            if ((int)($row['standard_is_typed'] ?? 0) === 1) {
                // a person decided: print their words, or nothing at all
                $t = trim((string)($row['standard_text'] ?? ''));
                if ($t !== '') $labels[] = $t;
                continue;
            }
            $code = (string)($row['lib_code'] ?: $row['test_name']);
            $labels[] = $row['revision'] ? $code . ' (' . $row['revision'] . ')' : $code;
        }
        $st = $pdo->prepare('SELECT header_json FROM c_reports WHERE id = ?');
        $st->execute([$reportId]);
        $hdNow = json_decode((string)$st->fetchColumn(), true) ?: [];
        $hdNow['standard_note'] = implode('; ', array_unique($labels));
        $pdo->prepare('UPDATE c_reports SET header_json = ? WHERE id = ?')
            ->execute([json_encode($hdNow, JSON_UNESCAPED_UNICODE), $reportId]);

        $pdo->commit();
        flash('Draft saved.', 'ok');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('Could not save: ' . h($e->getMessage()), 'err');
    }
    redirect('reports.php?action=build&id=' . $reportId);
}

// ════════════════════════════════════════════════════════════════════════════
//  REVIEW AND APPROVAL
//
//  A report is written by whoever did the work, then read and approved by
//  someone else before it is issued. The professor writing the Summary is
//  usually the approver. Nothing is issued without that step, and every
//  decision — including one sent back — is kept.
// ════════════════════════════════════════════════════════════════════════════
if (is_post() && in_array((string)post('do'), ['submit_review', 'approve', 'return_review'], true)) {
    $reportId = (int)post('id');
    $st = $pdo->prepare('SELECT * FROM c_reports WHERE id = ?');
    $st->execute([$reportId]);
    $rep = $st->fetch();
    if (!$rep) { flash('Report not found.', 'err'); redirect('reports.php'); }

    $action  = (string)post('do');
    $comment = trim((string)post('comment', ''));
    $back2   = 'reports.php?action=' . ($action === 'submit_review' ? 'build' : 'view') . '&id=' . $reportId;

    if ($rep['status'] === 'issued') {
        flash('An issued report cannot be reviewed again. Raise a revision instead.', 'err');
        redirect('reports.php?action=view&id=' . $reportId);
    }

    if ($action === 'submit_review') {
        // only worth submitting something that actually has readings
        $model = ctms_build_report_model($reportId);
        $withData = 0;
        foreach ($model['sections'] as $s) if ($s['has_readings']) $withData++;
        if ($withData === 0) {
            flash('There is nothing to review yet — no readings are attached to this report.', 'err');
            redirect($back2);
        }
        $pdo->prepare('UPDATE c_reports SET status = "in_review", submitted_by = ?, submitted_at = NOW(),
                          review_note = NULL, updated_at = NOW() WHERE id = ?')
            ->execute([$u['id'], $reportId]);
        $pdo->prepare('INSERT INTO c_report_reviews (report_id, decision, comment, actor_id, actor_name, actor_role)
                       VALUES (?, "submitted", ?, ?, ?, ?)')
            ->execute([$reportId, $comment ?: null, $u['id'], $u['full_name'], $u['role']]);
        audit('REPORT_SUBMITTED_FOR_REVIEW', 'reports', $reportId, ['status' => $rep['status']],
              ['status' => 'in_review', 'sections' => $withData], $comment ?: null, (int)$rep['sample_id']);
        flash('Report <strong>' . h($rep['report_number']) . '</strong> sent for review.', 'ok');
        redirect('reports.php?action=view&id=' . $reportId);
    }

    if ($action === 'approve') {
        require_login(['superadmin', 'admin', 'professor', 'hod']);
        if ($rep['status'] !== 'in_review') {
            flash('Only a report that has been sent for review can be approved.', 'err');
            redirect($back2);
        }
        $pdo->prepare('UPDATE c_reports SET status = "approved", approved_by = ?, approved_at = NOW(),
                          review_note = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$u['id'], $comment ?: null, $reportId]);
        $pdo->prepare('INSERT INTO c_report_reviews (report_id, decision, comment, actor_id, actor_name, actor_role)
                       VALUES (?, "approved", ?, ?, ?, ?)')
            ->execute([$reportId, $comment ?: null, $u['id'], $u['full_name'], $u['role']]);
        audit('REPORT_APPROVED', 'reports', $reportId, ['status' => 'in_review'],
              ['status' => 'approved'], $comment ?: null, (int)$rep['sample_id']);
        flash('Report approved. It can now be issued and dispatched.', 'ok');
        redirect('reports.php?action=view&id=' . $reportId);
    }

    // returned for correction
    require_login(['superadmin', 'admin', 'professor', 'hod']);
    if ($comment === '') {
        flash('Say what needs changing — a report cannot be returned without a comment.', 'err');
        redirect($back2);
    }
    $pdo->prepare('UPDATE c_reports SET status = "draft", review_note = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$comment, $reportId]);
    $pdo->prepare('INSERT INTO c_report_reviews (report_id, decision, comment, actor_id, actor_name, actor_role)
                   VALUES (?, "returned", ?, ?, ?, ?)')
        ->execute([$reportId, $comment, $u['id'], $u['full_name'], $u['role']]);
    audit('REPORT_RETURNED', 'reports', $reportId, ['status' => $rep['status']],
          ['status' => 'draft'], $comment, (int)$rep['sample_id']);
    flash('Report returned to the author with your comment.', 'warn');
    redirect('reports.php?action=view&id=' . $reportId);
}

// ════════════════════════════════════════════════════════════════════════════
//  ISSUE  — freezes the report and makes the barcode valid
// ════════════════════════════════════════════════════════════════════════════
if (is_post() && post('do') === 'issue') {
    require_login(['superadmin', 'admin', 'professor']);
    $reportId = (int)post('id');
    $st = $pdo->prepare('SELECT * FROM c_reports WHERE id = ?');
    $st->execute([$reportId]);
    $rep = $st->fetch();
    if (!$rep) { flash('Report not found.', 'err'); redirect('reports.php'); }
    if ($rep['status'] === 'issued') { flash('Already issued.', 'warn'); redirect('reports.php?action=view&id=' . $reportId); }

    $model = ctms_build_report_model($reportId);
    $withData = 0;
    foreach ($model['sections'] as $s) if ($s['has_readings']) $withData++;
    if ($withData === 0) {
        flash('This report contains no readings, so it cannot be issued.', 'err');
        redirect('reports.php?action=build&id=' . $reportId);
    }

    // A report should be read by someone else before it goes out. The laboratory
    // can switch this off in Report Settings if it prefers to issue directly.
    if (report_layout('require_approval') === '1' && $rep['status'] !== 'approved') {
        flash('This report has not been approved yet. Send it for review first, or switch off '
            . 'the approval step in Report Settings.', 'err');
        redirect('reports.php?action=view&id=' . $reportId);
    }

    $pdo->beginTransaction();
    try {
        $hash = hash('sha256', json_encode([
            $rep['report_number'], $model['header'], $model['sections'],
        ], JSON_UNESCAPED_UNICODE));

        $pdo->prepare('UPDATE c_reports SET status = "issued", issued_at = NOW(), issued_by = ?,
                          content_hash = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$u['id'], $hash, $reportId]);

        // the sample moves on in the workflow
        if ($rep['sample_id']) {
            $pdo->prepare('UPDATE c_samples SET status = "reported", report_number = ?, updated_at = NOW()
                            WHERE id = ?')->execute([$rep['report_number'], (int)$rep['sample_id']]);
        }
        audit('REPORT_ISSUED', 'reports', $reportId, ['status' => $rep['status']],
              ['status' => 'issued', 'hash' => substr($hash, 0, 16), 'sections' => $withData],
              trim((string)post('reason', '')) ?: null, (int)$rep['sample_id']);
        $pdo->commit();
        flash('Report <strong>' . h($rep['report_number']) . '</strong> issued. '
            . 'It is now read-only and its barcode is live.', 'ok');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('Could not issue: ' . h($e->getMessage()), 'err');
    }
} // ── end ISSUE ──

// ════════════════════════════════════════════════════════════════════════════
//  REVISE — Creates a new revision draft from an issued report
// ════════════════════════════════════════════════════════════════════════════
if (is_post() && post('do') === 'revise') {
    require_login(['superadmin', 'admin', 'professor']);
    $origId = (int)post('id');
    $st = $pdo->prepare('SELECT * FROM c_reports WHERE id = ?');
    $st->execute([$origId]);
    $orig = $st->fetch();
    if (!$orig) { flash('Report not found.', 'err'); redirect('reports.php'); }
    if ($orig['status'] !== 'issued') {
        flash('Only an issued report can be revised.', 'err');
        redirect('reports.php?action=view&id=' . $origId);
    }

    $reason = trim((string)post('revision_reason', ''));
    if ($reason === '') {
        flash('Please state the reason for raising this revision.', 'err');
        redirect('reports.php?action=view&id=' . $origId);
    }

    $pdo->beginTransaction();
    try {
        $nextRev = ((int)$orig['revision_no']) + 1;
        // Parse base report number and append /R2 or update revision
        $baseNum = preg_replace('/\/R\d+$/', '', $orig['report_number']);
        $newNum = $baseNum . '/R' . $nextRev;
        $secret = setting('verify_secret', '') ?: 'ctms-consult';

        $pdo->prepare('INSERT INTO c_reports (report_number, sample_id, invoice_id, company_name, title,
                          status, header_json, summary_html, remarks_html, revision_no, supersedes_id, created_by)
                       VALUES (?,?,?,?,?,"draft",?,?,?,?,?,?)')
            ->execute([$newNum, $orig['sample_id'], $orig['invoice_id'], $orig['company_name'],
                       $orig['title'] . ' (Rev ' . $nextRev . ')', $orig['header_json'],
                       $orig['summary_html'], $orig['remarks_html'], $nextRev, $origId, $u['id']]);
        $newReportId = (int)$pdo->lastInsertId();

        // Copy sections
        $stSec = $pdo->prepare('SELECT * FROM c_report_sections WHERE report_id = ?');
        $stSec->execute([$origId]);
        foreach ($stSec->fetchAll() as $sec) {
            $pdo->prepare('INSERT INTO c_report_sections (report_id, job_id, title, section_order, is_included)
                           VALUES (?, ?, ?, ?, ?)')
                ->execute([$newReportId, $sec['job_id'], $sec['title'], $sec['section_order'], $sec['is_included']]);
        }

        // Copy assets
        $stAst = $pdo->prepare('SELECT * FROM c_report_assets WHERE report_id = ?');
        $stAst->execute([$origId]);
        foreach ($stAst->fetchAll() as $ast) {
            $pdo->prepare('INSERT INTO c_report_assets (report_id, sample_id, job_id, asset_type, caption,
                              magnification, stored_name, original_name, mime_type, byte_size, sha256,
                              sort_order, uploaded_by, display_size, display_width_mm, display_align,
                              scale_value, scale_unit, annotations_json, thumb_name)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$newReportId, $ast['sample_id'], $ast['job_id'], $ast['asset_type'], $ast['caption'],
                           $ast['magnification'], $ast['stored_name'], $ast['original_name'], $ast['mime_type'],
                           $ast['byte_size'], $ast['sha256'], $ast['sort_order'], $u['id'], $ast['display_size'],
                           $ast['display_width_mm'], $ast['display_align'], $ast['scale_value'], $ast['scale_unit'],
                           $ast['annotations_json'], $ast['thumb_name']]);
        }

        $vcode = ctms_verify_code($newNum, $secret);
        $pdo->prepare('UPDATE c_reports SET verify_code = ?, barcode_value = ? WHERE id = ?')
            ->execute([$vcode, ctms_barcode_value($newNum, $vcode), $newReportId]);

        audit('REPORT_REVISION_CREATED', 'reports', $newReportId,
              ['supersedes_id' => $origId, 'old_report' => $orig['report_number']],
              ['report_number' => $newNum, 'revision_no' => $nextRev], $reason, (int)$orig['sample_id']);

        $pdo->commit();
        flash('Revision <strong>' . h($newNum) . '</strong> created as a draft. You can now edit readings and remarks.', 'ok');
        redirect('reports.php?action=build&id=' . $newReportId);
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('Could not create revision: ' . h($e->getMessage()), 'err');
        redirect('reports.php?action=view&id=' . $origId);
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  PAGE DATA
// ════════════════════════════════════════════════════════════════════════════
$viewRep = $buildRep = null; $sections = []; $candidates = []; $newSample = null;

if ($action === 'view' || $action === 'build') {
    $st = $pdo->prepare('SELECT r.*, s.sample_code, s.description AS sample_description
                           FROM c_reports r LEFT JOIN c_samples s ON s.id = r.sample_id
                          WHERE r.id = ?');
    $st->execute([$id]);
    $viewRep = $st->fetch() ?: null;
    if (!$viewRep) { flash('That report does not exist.', 'err'); redirect('reports.php'); }

    $st = $pdo->prepare(
        'SELECT rs.*, j.test_type_id, j.status AS job_status, tt.name AS test_name, tt.report_title,
                st.code AS std_code,
                (SELECT COUNT(*) FROM c_readings rd WHERE rd.job_id = j.id AND rd.is_current = 1) AS readings
           FROM c_report_sections rs
           JOIN c_test_jobs  j  ON j.id = rs.job_id
           JOIN c_test_types tt ON tt.id = j.test_type_id
           LEFT JOIN c_standards st ON st.id = j.standard_id
          WHERE rs.report_id = ? ORDER BY rs.section_order, rs.id');
    $st->execute([$id]);
    $sections = $st->fetchAll();
}

if ($action === 'new') {
    $sampleId = (int)get('sample', 0);
    if ($sampleId) {
        $st = $pdo->prepare('SELECT s.*, i.inv_number FROM c_samples s
                              LEFT JOIN c_billing i ON i.id = s.invoice_id WHERE s.id = ?');
        $st->execute([$sampleId]);
        $newSample = $st->fetch() ?: null;
        if ($newSample) {
            $st = $pdo->prepare(
                'SELECT j.id, j.status AS job_status, j.priority, j.remarks AS job_remarks, tt.name AS test_name, tt.report_title,
                        st.code AS std_code, p.piece_code,
                        (SELECT COUNT(*) FROM c_readings r WHERE r.job_id = j.id AND r.is_current = 1) AS readings,
                        (SELECT COUNT(*) FROM c_report_assets a WHERE a.job_id = j.id) AS assets_count,
                        (SELECT COUNT(*) FROM c_report_sections rs WHERE rs.job_id = j.id) AS already_in_report
                   FROM c_test_jobs j
                   JOIN c_test_types tt ON tt.id = j.test_type_id
                   LEFT JOIN c_standards st ON st.id = j.standard_id
                   LEFT JOIN c_sample_pieces p ON p.id = j.piece_id
                  WHERE j.sample_id = ? AND j.status <> "cancelled"
                  ORDER BY tt.sort_order, j.id');
            $st->execute([$sampleId]);
            $candidates = $st->fetchAll();
        }
    }
}

$list = [];
if ($action === 'list') {
    $status = (string)get('status', '');
    $search = trim((string)get('q', ''));
    $sql = 'SELECT r.*, s.sample_code,
                   (SELECT COUNT(*) FROM c_report_sections rs WHERE rs.report_id = r.id AND rs.is_included=1) AS sections,
                   (SELECT COUNT(*) FROM c_dispatches d WHERE d.report_id = r.id AND d.is_reversed = 0) AS dispatched
              FROM c_reports r LEFT JOIN c_samples s ON s.id = r.sample_id WHERE 1=1';
    $params = [];
    if ($status !== '') { $sql .= ' AND r.status = ?'; $params[] = $status; }
    if ($search !== '') { $sql .= ' AND (r.report_number LIKE ? OR r.company_name LIKE ? OR s.sample_code LIKE ?)';
                          array_push($params, "%$search%", "%$search%", "%$search%"); }
    $sql .= ' ORDER BY r.id DESC LIMIT 300';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $list = $st->fetchAll();

    // samples that have tests ready but no report yet
    $ready = $pdo->query(
        'SELECT s.id, s.sample_code, s.description, s.company_name,
                (SELECT COUNT(*) FROM c_test_jobs j WHERE j.sample_id = s.id
                   AND j.status IN ("entered","checked","approved")) AS ready_jobs,
                (SELECT COUNT(*) FROM c_test_jobs j WHERE j.sample_id = s.id AND j.status = "approved") AS approved_jobs
           FROM c_samples s
          WHERE s.is_deleted = 0
            AND s.status IN ("in_testing","awaiting_report")
            AND EXISTS (SELECT 1 FROM c_test_jobs j WHERE j.sample_id = s.id
                          AND j.status IN ("entered","checked","approved"))
            AND NOT EXISTS (SELECT 1 FROM c_reports r WHERE r.sample_id = s.id AND r.status <> "superseded")
          ORDER BY s.id DESC LIMIT 40')->fetchAll();
}

$PAGE_TITLE = match ($action) {
    'new'   => 'New report',
    'build' => 'Build report ' . ($viewRep['report_number'] ?? ''),
    'view'  => 'Report ' . ($viewRep['report_number'] ?? ''),
    default => 'Reports',
};
$PAGE_SUB = 'Workflow step 4 — assemble the finished document from approved tests';
include __DIR__ . '/inc/layout_top.php';
?>

<?php if ($action === 'list'): ?>

  <div class="card">
    <div class="toolbar" style="margin:0">
      <form method="get" style="display:flex;gap:8px;align-items:center;margin-left:auto">
        <input type="hidden" name="action" value="list">
        <input type="text" name="q" value="<?= h(get('q','')) ?>" placeholder="report no. or company" style="width:240px">
        <select name="status" style="width:160px">
          <option value="">All statuses</option>
          <?php foreach (['draft','in_review','approved','issued','superseded'] as $s): ?>
            <option value="<?= h($s) ?>" <?= get('status') === $s ? 'selected' : '' ?>><?= h(str_replace('_',' ',$s)) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-ghost" type="submit">Filter</button>
      </form>
    </div>
  </div>

  <?php if ($ready): ?>
  <div class="card" style="border-left:4px solid var(--copper)">
    <h2>Ready to become a report</h2>
    <p class="small muted" style="margin-bottom:10px">
      These samples have test results entered but no report yet.
    </p>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Sample</th><th>Company</th><th>Description</th>
                   <th class="right">Tests ready</th><th class="right">Approved</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($ready as $r): ?>
          <tr>
            <td class="mono"><?= h($r['sample_code']) ?></td>
            <td class="small"><?= h($r['company_name'] ?: '—') ?></td>
            <td><?= h(mb_strimwidth((string)$r['description'], 0, 40, '…')) ?></td>
            <td class="num"><?= (int)$r['ready_jobs'] ?></td>
            <td class="num"><?= (int)$r['approved_jobs'] ?></td>
            <td><a class="btn btn-sm btn-copper" href="<?= h(app_url('reports.php?action=new&sample=' . (int)$r['id'])) ?>">Build report</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2><?= count($list) ?> report(s)</h2>
    <?php if (!$list): ?>
      <div class="empty">No reports yet. Reports are built from a sample's completed tests.</div>
    <?php else: ?>
      <form method="post" action="<?= h(app_url('reports_archive_zip.php')) ?>" style="margin:12px 0 16px">
        <?= csrf_field() ?>
        <div class="toolbar" style="margin:0 0 10px">
          <label class="small" style="display:flex;align-items:center;gap:8px">Archive format
            <select name="format" style="width:190px"><option value="pdf">PDF reports</option><option value="word">Word reports</option><option value="both">PDF and Word reports</option></select>
          </label>
          <button class="btn btn-copper" type="submit" onclick="return document.querySelectorAll('.consult-report-select:checked').length > 0 || (alert('Select at least one report first.'), false)">Download selected as ZIP</button>
          <span class="small muted">Select up to 100 reports.</span>
        </div>
        <div class="table-wrap">
        <table>
          <thead><tr><th>Select</th><th>Report</th><th>Company</th><th>Sample</th><th class="right">Tests</th>
                     <th>Status</th><th>Issued</th><th>Dispatch</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($list as $r): ?>
            <tr>
              <td><input class="consult-report-select" type="checkbox" name="report_ids[]" value="<?= (int)$r['id'] ?>"></td>
              <td class="mono"><a href="<?= h(app_url('reports.php?action=view&id=' . (int)$r['id'])) ?>"><?= h($r['report_number']) ?></a></td>
              <td><?= h($r['company_name']) ?></td>
              <td class="mono small"><?= h($r['sample_code'] ?: '—') ?></td>
              <td class="num"><?= (int)$r['sections'] ?></td>
              <td><?= status_badge($r['status']) ?></td>
              <td class="small nowrap"><?= h($r['issued_at'] ? fmt_date($r['issued_at']) : '—') ?></td>
              <td><?= (int)$r['dispatched'] ? badge('sent', 'green') : '<span class="muted small">—</span>' ?></td>
              <td class="nowrap">
                <?php if ($r['status'] !== 'issued'): ?>
                  <a class="btn btn-sm btn-ghost" href="<?= h(app_url('reports.php?action=build&id=' . (int)$r['id'])) ?>">Build</a>
                <?php endif; ?>
                <a class="btn btn-sm btn-teal" href="<?= h(app_url('report_view.php?id=' . (int)$r['id'])) ?>" target="_blank">Print</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      </form>
    <?php endif; ?>
  </div>

<?php elseif ($action === 'new'): ?>

  <?php if (!$newSample): ?>
    <div class="card">
      <h2>Choose the sample</h2>
      <p class="small muted" style="margin-bottom:12px">
        A report is built from one sample and the tests recorded against it.
      </p>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Sample</th><th>Company</th><th>Description</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($pdo->query('SELECT id, sample_code, description, company_name FROM c_samples
                                       WHERE is_deleted = 0 ORDER BY id DESC LIMIT 40') as $r): ?>
            <tr>
              <td class="mono"><?= h($r['sample_code']) ?></td>
              <td class="small"><?= h($r['company_name'] ?: '—') ?></td>
              <td><?= h(mb_strimwidth((string)$r['description'], 0, 50, '…')) ?></td>
              <td><a class="btn btn-sm btn-copper" href="<?= h(app_url('reports.php?action=new&sample=' . (int)$r['id'])) ?>">Choose</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php else: ?>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="create">
      <input type="hidden" name="sample_id" value="<?= (int)$newSample['id'] ?>">

      <div class="card">
        <h2><?= h($newSample['sample_code']) ?> — <?= h($newSample['company_name'] ?: 'no company') ?></h2>
        <p class="small muted"><?= h($newSample['description']) ?>
          <?= $newSample['inv_number'] ? ' · Invoice ' . h($newSample['inv_number']) : '' ?></p>
      </div>

      <div class="card">
        <h2>Which tests belong on this report?</h2>
        <?php if (!$candidates): ?>
          <div class="empty">No tests recorded against this sample yet.</div>
        <?php else: ?>
          <p class="small muted" style="margin-bottom:10px">
            Tick the tests to include. Anything with no readings is marked, and can still be included
            as a section that prints “No readings recorded”.
          </p>
          <div class="table-wrap">
            <table>
              <thead><tr><th style="width:40px"></th><th>Test</th><th>Standard</th><th>Piece</th>
                         <th class="right">Readings</th><th>Job status</th><th>Note</th></tr></thead>
              <tbody>
              <?php foreach ($candidates as $c):
                $hasContent = (int)$c['readings'] > 0 || (int)$c['assets_count'] > 0 || trim((string)$c['job_remarks']) !== '';
              ?>
                <tr>
                  <td><input type="checkbox" name="jobs[]" value="<?= (int)$c['id'] ?>" style="width:auto"
                        <?= $hasContent ? 'checked' : '' ?>></td>
                  <td><strong><?= h($c['test_name']) ?></strong></td>
                  <td class="small mono"><?= h($c['std_code'] ?: '—') ?></td>
                  <td class="mono small"><?= h($c['piece_code'] ?: '—') ?></td>
                  <td class="num">
                    <?= (int)$c['readings'] ?>
                    <?php if ((int)$c['assets_count'] > 0): ?>
                      <span class="small muted" title="<?= (int)$c['assets_count'] ?> images attached">(+<?= (int)$c['assets_count'] ?> img)</span>
                    <?php endif; ?>
                  </td>
                  <td><?= status_badge((string)($c['job_status'] ?? 'pending')) ?></td>
                  <td class="small muted">
                    <?php if (!$hasContent): ?>no readings or images recorded
                    <?php elseif ((int)$c['already_in_report'] > 0): ?>already on another report
                    <?php elseif ($c['job_status'] !== 'approved'): ?>not yet approved — still usable
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <div class="toolbar">
        <button class="btn btn-copper" type="submit">Create the report draft</button>
        <a class="btn btn-ghost" href="<?= h(app_url('reports.php')) ?>">Cancel</a>
      </div>
    </form>

  <?php endif; ?>

<?php elseif ($action === 'build'): ?>

  <?php
  $hd = json_decode((string)$viewRep['header_json'], true) ?: [];
  $issued = $viewRep['status'] === 'issued';
  ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="id" value="<?= (int)$viewRep['id'] ?>">

    <div class="card">
      <h2>Report identity</h2>
      <div class="row r3">
        <div class="field">
          <label for="title">Report title</label>
          <input id="title" name="title" type="text" value="<?= h($viewRep['title']) ?>" <?= $issued ? 'readonly' : '' ?>>
        </div>
        <div class="field">
          <label>Report number</label>
          <input type="text" value="<?= h($viewRep['report_number']) ?>" readonly>
        </div>
        <div class="field">
          <label>Page orientation</label>
          <select name="orientation" <?= $issued ? 'disabled' : '' ?>>
            <option value="landscape" <?= ($hd['orientation'] ?? 'landscape') === 'landscape' ? 'selected' : '' ?>>Landscape (like the tensile reports)</option>
            <option value="portrait" <?= ($hd['orientation'] ?? '') === 'portrait' ? 'selected' : '' ?>>Portrait (like the Example Client reports)</option>
          </select>
        </div>
      </div>
    </div>

    <div class="card">
      <h2>The information block printed at the top</h2>
      <p class="small muted" style="margin-bottom:10px">
        These are the fields your existing reports carry. They are stored as separate values, so
        they can be searched and counted rather than being free text.
      </p>
      <div class="row r3">
        <div class="field">
          <label for="type_of_sample">Type of sample</label>
          <input id="type_of_sample" name="type_of_sample" type="text" value="<?= h($hd['type_of_sample'] ?? '') ?>" <?= $issued ? 'readonly' : '' ?>>
        </div>
        <div class="field">
          <label for="item_description">Item description</label>
          <input id="item_description" name="item_description" type="text" value="<?= h($hd['item_description'] ?? '') ?>" <?= $issued ? 'readonly' : '' ?>>
        </div>
        <div class="field">
          <label for="grade_match">Grade match</label>
          <input id="grade_match" name="grade_match" type="text" value="<?= h($hd['grade_match'] ?? '') ?>" <?= $issued ? 'readonly' : '' ?>>
        </div>
      </div>
      <div class="row r3">
        <div class="field">
          <label for="test_date">Test date</label>
          <input id="test_date" name="test_date" type="text" value="<?= h($hd['test_date'] ?? '') ?>" <?= $issued ? 'readonly' : '' ?>>
        </div>
        <div class="field">
          <label for="received">Received date</label>
          <input id="received" name="received" type="text" value="<?= h($hd['received'] ?? '') ?>" <?= $issued ? 'readonly' : '' ?>>
        </div>
        <div class="field">
          <label for="reference">Reference</label>
          <input id="reference" name="reference" type="text" value="<?= h($hd['reference'] ?? '') ?>" <?= $issued ? 'readonly' : '' ?>>
        </div>
      </div>
    </div>

    <div class="card">
      <h2>Sections — one per test, in the order they print</h2>
      <?php if (!$sections): ?>
        <div class="empty">No tests were attached to this report.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead><tr><th style="width:40px">In</th><th style="width:60px">Order</th><th>Test</th>
                       <th>Heading printed above the table</th><th>Standard</th>
                       <th class="right">Readings</th></tr></thead>
            <tbody>
            <?php foreach ($sections as $i => $s): ?>
              <tr>
                <td><input type="checkbox" name="sections[<?= $i ?>][include]" value="1" style="width:auto"
                      <?= (int)$s['is_included'] ? 'checked' : '' ?> <?= $issued ? 'disabled' : '' ?>></td>
                <td class="num"><?= $i + 1 ?></td>
                <td><strong><?= h($s['test_name']) ?></strong></td>
                <td><input type="text" name="sections[<?= $i ?>][title]"
                           value="<?= h($s['title'] ?: ($s['report_title'] ?: strtoupper($s['test_name']))) ?>"
                           <?= $issued ? 'readonly' : '' ?>></td>
                <td class="small mono"><?= h($s['std_code'] ?: '—') ?></td>
                <td class="num"><?= (int)$s['readings'] ?></td>
                <input type="hidden" name="sections[<?= $i ?>][job_id]" value="<?= (int)$s['job_id'] ?>">
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <?php
    // ── images for this report ────────────────────────────────────────────────
    $st = $pdo->prepare('SELECT a.*, j.test_type_id, tt.name AS test_name
                           FROM c_report_assets a
                           LEFT JOIN c_test_jobs  j  ON j.id = a.job_id
                           LEFT JOIN c_test_types tt ON tt.id = j.test_type_id
                          WHERE a.report_id = ? ORDER BY a.sort_order, a.id');
    $st->execute([(int)$viewRep['id']]);
    $assets = $st->fetchAll();
    $jobOptions = [];
    foreach ($sections as $s) { $jobOptions[(int)$s['job_id']] = $s['test_name']; }
    ?>
    <div class="card">
      <h2>Photographs and micrographs <span class="badge badge-grey"><?= count($assets) ?></span></h2>
      <p class="small muted" style="margin-bottom:12px">
        Upload the microscope images and specimen photographs, then open <strong>Mark up</strong> to draw the
        depth lines and the labels ("Mounting Resin", "Substrate") straight onto the picture.
        The mark-up is stored as vector data, so it prints sharply at any size and can be corrected later —
        the original photograph is never altered.
      </p>

      <?php if ($assets): ?>
        <div class="table-wrap" style="margin-bottom:14px">
          <table>
            <thead><tr><th style="width:96px">Preview</th><th>Caption</th><th>Belongs to</th>
                       <th>Printed size</th><th class="right">Mark-up</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($assets as $a):
              $isImage = trim((string)($a['stored_name'] ?? '')) !== '';
              $thumb = $isImage ? report_asset_preview_url($a) : '';
              $nShapes = count(json_decode((string)($a['annotations_json'] ?? '[]'), true) ?: []);
              $sizeLabel = $a['display_size'] === 'custom'
                  ? ((float)$a['display_width_mm'] . ' mm') : ucfirst((string)$a['display_size']);
            ?>
              <tr>
                <td><?php if ($isImage): ?><a href="<?= h(app_url('images.php?id=' . (int)$a['id'])) ?>">
                  <?php if ($thumb !== ''): ?><img src="<?= h($thumb) ?>" alt="" style="width:86px;border:1px solid var(--border);border-radius:4px">
                  <?php else: ?><span class="small muted">Image unavailable</span><?php endif; ?></a>
                  <?php else: ?><span class="badge badge-blue">Chart</span><?php endif; ?></td>
                <td>
                  <?= h($a['caption'] ?: '—') ?>
                  <?php if ($a['magnification']): ?><div class="small muted"><?= h($a['magnification']) ?></div><?php endif; ?>
                </td>
                <td class="small"><?= h($a['test_name'] ?: 'whole report') ?></td>
                <td class="small">
                  <select name="size" form="asset-size-<?= (int)$a['id'] ?>"
                          onchange="document.getElementById('asset-size-<?= (int)$a['id'] ?>').submit()"
                          <?= $issued ? 'disabled' : '' ?>>
                    <?php foreach (['small'=>'Small','medium'=>'Medium','large'=>'Large','full'=>'Full width'] as $k => $lab): ?>
                      <option value="<?= h($k) ?>" <?= $a['display_size'] === $k ? 'selected' : '' ?>><?= h($lab) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="small muted" style="margin-top:3px"><?= h($sizeLabel) ?> · <?= h(ucfirst((string)$a['display_align'])) ?></div>
                </td>
                <td class="num"><?= !$isImage ? '<span class="muted small">—</span>' : ($nShapes ? badge($nShapes . ' item' . ($nShapes === 1 ? '' : 's'), 'blue')
                                           : '<span class="muted small">none</span>') ?></td>
                <td class="nowrap">
                  <?php if ($isImage): ?><a class="btn btn-sm btn-copper" href="<?= h(app_url('images.php?id=' . (int)$a['id'])) ?>">Mark up</a><?php else: ?><span class="small muted">Charts cannot be marked up</span><?php endif; ?>
                  <button class="btn btn-sm btn-ghost" type="submit" form="asset-up-<?= (int)$a['id'] ?>" aria-label="Move image up">▲</button>
                  <button class="btn btn-sm btn-ghost" type="submit" form="asset-down-<?= (int)$a['id'] ?>" aria-label="Move image down">▼</button>
                  <?php if (!$issued): ?>
                    <button class="btn btn-sm btn-danger" type="submit" form="asset-del-<?= (int)$a['id'] ?>" aria-label="Remove image from report">✕</button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if (!$issued): ?>
        <form method="post" action="<?= h(app_url('upload_image.php')) ?>" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="upload">
          <input type="hidden" name="report_id" value="<?= (int)$viewRep['id'] ?>">
          <div class="toolbar" style="margin:0 0 12px">
            <a class="btn btn-teal" href="<?= h(app_url('charts.php?report=' . (int)$viewRep['id'])) ?>">
              + Add a chart from the readings</a>
            <span class="small muted">A chart is drawn from the stored values, so correcting a
              reading corrects the graph.</span>
          </div>
          <div class="row r3">
            <div class="field">
              <label for="images">Choose image files</label>
              <input id="images" name="images[]" type="file" multiple
                     accept="image/jpeg,image/png,image/webp,image/gif">
              <div class="hint">JPEG, PNG, WebP or GIF — up to 20&nbsp;MB each, several at once.
                TIFF from an instrument will not open in a browser; export it as JPEG first.</div>
            </div>
            <div class="field">
              <label for="asset_type">Kind</label>
              <select id="asset_type" name="asset_type">
                <option value="micrograph">Micrograph</option>
                <option value="photo">Photograph</option>
                <option value="specimen">Specimen as received</option>
                <option value="instrument_export">Instrument output</option>
                <option value="scan">Scan</option>
              </select>
            </div>
            <div class="field">
              <label for="job_id">Attach to a test section</label>
              <select id="job_id" name="job_id">
                <option value="">Whole report</option>
                <?php foreach ($jobOptions as $jid => $jname): ?>
                  <option value="<?= (int)$jid ?>"><?= h($jname) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="hint">Images print under that test's table, two to a row.</div>
            </div>
          </div>
          <div class="row r3">
            <div class="field">
              <label for="caption">Caption</label>
              <input id="caption" name="caption" type="text" placeholder="applied to every file in this upload">
            </div>
            <div class="field">
              <label for="magnification">Magnification</label>
              <input id="magnification" name="magnification" type="text" placeholder="e.g. 500X">
            </div>
            <div class="field">
              <label for="scale_value">Scale (optional)</label>
              <div style="display:flex;gap:6px">
                <input id="scale_value" name="scale_value" type="number" step="0.0001" placeholder="e.g. 500" style="flex:1">
                <select name="scale_unit" style="width:90px">
                  <option value="">-</option><option value="µm">µm</option><option value="mm">mm</option>
                </select>
              </div>
            </div>
          </div>
          <button class="btn btn-copper" type="submit">Upload</button>
        </form>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>Remarks / Term &amp; Conditions</h2>
      <p class="small muted" style="margin-bottom:8px">Pre-filled with the laboratory's standing wording.</p>
      <textarea name="remarks" rows="5" <?= $issued ? 'readonly' : '' ?>><?= h($viewRep['remarks_html']) ?></textarea>
    </div>

    <div class="card">
      <h2>The professor's summary</h2>
      <p class="small muted" style="margin-bottom:8px">
        Written by hand, as in your existing reports. Numbered points print exactly as typed.
      </p>
      <textarea name="summary" rows="7" placeholder="e.g.&#10;1. Optical emission testing was carried out to ascertain the chemical composition.&#10;2. Hardness testing was carried out to assess resistance to indentation."
                <?= $issued ? 'readonly' : '' ?>><?= h($viewRep['summary_html']) ?></textarea>
      <?php if (!$issued): ?>
        <div class="field" style="margin-top:12px">
          <label for="reason">Reason for this change</label>
          <input id="reason" name="reason" type="text" placeholder="optional, recorded in the audit trail">
        </div>
      <?php endif; ?>
    </div>

    <?php if (!$issued): ?>
      <div class="toolbar">
        <button class="btn" type="submit">Save draft</button>
        <button class="btn btn-teal" type="button"
                onclick="document.getElementById('issueForm').style.display='block'">Issue this report</button>
        <a class="btn btn-ghost" href="<?= h(app_url('report_view.php?id=' . (int)$viewRep['id'])) ?>" target="_blank">Preview / print</a>
      </div>
    <?php else: ?>
      <div class="msg msg-ok">
        <strong>Issued <?= h(fmt_date($viewRep['issued_at'])) ?>.</strong>
        This report is read-only. Its content hash is
        <span class="mono"><?= h(substr((string)$viewRep['content_hash'], 0, 16)) ?>…</span>,
        and the barcode is live.
      </div>
      <div class="toolbar">
        <a class="btn btn-ghost" href="<?= h(app_url('report_view.php?id=' . (int)$viewRep['id'])) ?>" target="_blank">Preview / print</a>
        <a class="btn btn-copper" href="<?= h(app_url('dispatch.php?report=' . (int)$viewRep['id'])) ?>">Record dispatch</a>
      </div>
    <?php endif; ?>
  </form>

  <?php foreach ($assets as $a): $assetIdForAction = (int)$a['id']; ?>
    <form id="asset-size-<?= $assetIdForAction ?>" method="post" action="<?= h(app_url('report_asset.php')) ?>" hidden>
      <?= csrf_field() ?><input type="hidden" name="do" value="size"><input type="hidden" name="id" value="<?= $assetIdForAction ?>">
    </form>
    <?php foreach (['up', 'down'] as $assetMove): ?>
      <form id="asset-<?= $assetMove ?>-<?= $assetIdForAction ?>" method="post" action="<?= h(app_url('report_asset.php')) ?>" hidden>
        <?= csrf_field() ?><input type="hidden" name="do" value="<?= $assetMove ?>"><input type="hidden" name="id" value="<?= $assetIdForAction ?>">
      </form>
    <?php endforeach; ?>
    <?php if (!$issued): ?>
      <form id="asset-del-<?= $assetIdForAction ?>" method="post" action="<?= h(app_url('report_asset.php')) ?>" hidden
            onsubmit="return confirm('Remove this image from the report? The file is kept, so it can be put back.')">
        <?= csrf_field() ?><input type="hidden" name="do" value="del"><input type="hidden" name="id" value="<?= $assetIdForAction ?>">
      </form>
    <?php endif; ?>
  <?php endforeach; ?>

  <?php if (!$issued): ?>
  <form method="post" id="issueForm" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="issue">
    <input type="hidden" name="id" value="<?= (int)$viewRep['id'] ?>">
    <div class="card" style="border-left:4px solid var(--teal)">
      <h2>Issuing makes this report final</h2>
      <p class="small" style="margin-bottom:10px">
        Once issued the report becomes read-only, a content hash is stored, and the barcode can be
        used to verify the printed paper. Any later change must be made as a revision, which is kept
        alongside the original rather than replacing it.
      </p>
      <div class="field">
        <label for="issue_reason">Note (optional)</label>
        <input id="issue_reason" name="reason" type="text">
      </div>
      <button class="btn btn-teal" type="submit">Confirm — issue the report</button>
      <button class="btn btn-ghost" type="button"
              onclick="document.getElementById('issueForm').style.display='none'">Cancel</button>
    </div>
  </form>
  <?php endif; ?>

<?php elseif ($action === 'view'): ?>

  <div class="grid g3" style="margin-bottom:16px">
    <div class="kpi"><div class="l">Report</div>
      <div class="v" style="font-size:16px;padding-top:6px" class="mono"><?= h($viewRep['report_number']) ?></div>
      <div class="s"><?= h($viewRep['company_name']) ?></div></div>
    <div class="kpi" style="border-left-color:var(--teal)"><div class="l">Status</div>
      <div class="v" style="font-size:16px;padding-top:6px"><?= status_badge($viewRep['status']) ?></div>
      <div class="s"><?= count($sections) ?> section(s)</div></div>
    <div class="kpi" style="border-left-color:var(--copper)"><div class="l">Verify code</div>
      <div class="v" style="font-size:16px;padding-top:6px" class="mono"><?= h($viewRep['verify_code']) ?></div>
      <div class="s">printed under the barcode</div></div>
  </div>

  <div class="card">
    <div class="toolbar" style="margin:0">
      <?php if ($viewRep['status'] !== 'issued'): ?>
        <a class="btn btn-copper" href="<?= h(app_url('reports.php?action=build&id=' . (int)$viewRep['id'])) ?>">Edit draft</a>
      <?php endif; ?>
      <a class="btn btn-teal" href="<?= h(app_url('report_view.php?id=' . (int)$viewRep['id'])) ?>" target="_blank">Open printable report</a>
      <?php if ($viewRep['status'] === 'issued'): ?>
        <a class="btn" href="<?= h(app_url('dispatch.php?report=' . (int)$viewRep['id'])) ?>">Record dispatch</a>
        <?php if (can_user('superadmin', 'admin', 'professor')): ?>
          <button type="button" class="btn btn-copper" onclick="document.getElementById('reviseBox').style.display='block'">Raise revision (Rev <?= ((int)$viewRep['revision_no']) + 1 ?>)</button>
        <?php endif; ?>
      <?php endif; ?>
      <a class="btn btn-ghost" href="<?= h(app_url('reports.php')) ?>">All reports</a>
    </div>

    <!-- Hidden form to prompt revision reason -->
    <div id="reviseBox" style="display:none;margin-top:14px;padding:14px;background:#fef9c3;border:1.5px solid #ca8a04;border-radius:6px">
      <h3 style="font-size:14px;color:#854d0e;margin-bottom:6px">Raise a new revision of this issued report</h3>
      <p class="small muted" style="margin-bottom:10px">
        This creates <strong><?= h(preg_replace('/\/R\d+$/', '', $viewRep['report_number']) . '/R' . (((int)$viewRep['revision_no']) + 1)) ?></strong> as a draft while keeping the original report frozen and audited.
      </p>
      <form method="post" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="revise">
        <input type="hidden" name="id" value="<?= (int)$viewRep['id'] ?>">
        <div style="flex:1;min-width:240px">
          <label style="font-size:11px;font-weight:700">Reason for revision *</label>
          <input type="text" name="revision_reason" required placeholder="e.g. Client requested re-testing of specimen 2 / amended thickness" style="width:100%">
        </div>
        <button class="btn btn-copper" type="submit">Create revision draft</button>
        <button class="btn btn-ghost" type="button" onclick="document.getElementById('reviseBox').style.display='none'">Cancel</button>
      </form>
    </div>
  </div>

  <?php if (!empty($viewRep['supersedes_id'])):
    $stOld = $pdo->prepare('SELECT * FROM c_reports WHERE id = ?');
    $stOld->execute([(int)$viewRep['supersedes_id']]);
    $oldRep = $stOld->fetch();
  ?>
  <?php if ($oldRep): ?>
    <div class="card" style="border-left:4px solid #C2703D">
      <h2>Revision Comparison &amp; Diff <span class="badge badge-copper">Rev <?= (int)$viewRep['revision_no'] ?> vs Rev <?= (int)$oldRep['revision_no'] ?></span></h2>
      <p class="small muted" style="margin-bottom:10px">
        This report is a revision superseding <strong><?= h($oldRep['report_number']) ?></strong>.
      </p>
      <table class="table-sm">
        <thead><tr><th style="width:180px">Field</th><th>Previous Version (<?= h($oldRep['report_number']) ?>)</th><th>This Revision (<?= h($viewRep['report_number']) ?>)</th></tr></thead>
        <tbody>
          <tr>
            <td><strong>Title</strong></td>
            <td><?= h($oldRep['title']) ?></td>
            <td><strong style="color:var(--navy)"><?= h($viewRep['title']) ?></strong></td>
          </tr>
          <tr>
            <td><strong>Remarks</strong></td>
            <td><span class="small muted"><?= h(strip_tags((string)$oldRep['remarks_html']) ?: 'None') ?></span></td>
            <td>
              <?php $remDiff = (strip_tags((string)$oldRep['remarks_html']) !== strip_tags((string)$viewRep['remarks_html'])); ?>
              <span class="small" style="<?= $remDiff ? 'color:#8C2F24;font-weight:bold' : '' ?>">
                <?= h(strip_tags((string)$viewRep['remarks_html']) ?: 'None') ?>
                <?= $remDiff ? ' (Modified)' : '' ?>
              </span>
            </td>
          </tr>
          <tr>
            <td><strong>Summary</strong></td>
            <td><span class="small muted"><?= h(strip_tags((string)$oldRep['summary_html']) ?: 'None') ?></span></td>
            <td>
              <?php $sumDiff = (strip_tags((string)$oldRep['summary_html']) !== strip_tags((string)$viewRep['summary_html'])); ?>
              <span class="small" style="<?= $sumDiff ? 'color:#8C2F24;font-weight:bold' : '' ?>">
                <?= h(strip_tags((string)$viewRep['summary_html']) ?: 'None') ?>
                <?= $sumDiff ? ' (Modified)' : '' ?>
              </span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <?php endif; ?>

  <?php
  // ── where this report is in the review cycle ──────────────────────────────
  $st = $pdo->prepare('SELECT rv.* FROM c_report_reviews rv WHERE rv.report_id = ? ORDER BY rv.id DESC LIMIT 12');
  $st->execute([(int)$viewRep['id']]);
  $reviews = $st->fetchAll();
  $apBy = null; $apAt = null;
  if (!empty($viewRep['approved_by'])) {
      $st = $pdo->prepare('SELECT full_name FROM c_users WHERE id = ?');
      $st->execute([(int)$viewRep['approved_by']]);
      $apBy = $st->fetchColumn();
      $apAt = $viewRep['approved_at'];
  }
  $needsApproval = report_layout('require_approval') === '1';
  ?>

  <?php if (!empty($viewRep['review_note']) && $viewRep['status'] === 'draft'): ?>
    <div class="msg msg-warn">
      <strong>Returned for correction:</strong> <?= h($viewRep['review_note']) ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>Review and approval</h2>
    <?php if ($viewRep['status'] === 'issued'): ?>
      <p>
        Issued <?= h(fmt_date($viewRep['issued_at'])) ?><?= $apBy ? ', approved by ' . h($apBy) : '' ?>.
        The report is read-only and its barcode is live.
      </p>
    <?php else: ?>
      <p class="small muted" style="margin-bottom:10px">
        A report is written by whoever did the work, then read and approved by someone else
        before it goes to the client.
        <?= $needsApproval
            ? 'The approval step is <strong>on</strong>, so this report cannot be issued until it has been approved.'
            : 'The approval step is <strong>off</strong> — this report can be issued directly.' ?>
      </p>
      <table style="width:auto;margin-bottom:12px">
        <tr><td class="muted" style="width:170px">Current step</td>
            <td><?= status_badge($viewRep['status']) ?></td></tr>
        <?php if ($viewRep['submitted_at']): ?>
          <tr><td class="muted">Sent for review</td><td><?= h(fmt_date($viewRep['submitted_at'])) ?></td></tr>
        <?php endif; ?>
        <?php if ($apBy): ?>
          <tr><td class="muted">Approved by</td><td><?= h($apBy) ?> · <?= h(fmt_date($apAt)) ?></td></tr>
        <?php endif; ?>
      </table>

      <div class="toolbar" style="margin:0">
        <?php if ($viewRep['status'] === 'draft'): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="submit_review">
            <input type="hidden" name="id" value="<?= (int)$viewRep['id'] ?>">
            <button class="btn btn-copper" type="submit">Send for review</button>
          </form>
        <?php endif; ?>

        <?php if ($viewRep['status'] === 'in_review' && can_user('superadmin','admin','professor','hod')): ?>
          <form method="post" style="display:inline-flex;gap:8px;align-items:end;flex-wrap:wrap">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$viewRep['id'] ?>">
            <div><label for="ap_comment">Comment (optional when approving)</label>
              <input id="ap_comment" name="comment" type="text" style="width:280px"></div>
            <button class="btn btn-teal" type="submit" name="do" value="approve">Approve</button>
            <button class="btn btn-danger" type="submit" name="do" value="return_review">Return for correction</button>
          </form>
        <?php elseif ($viewRep['status'] === 'in_review'): ?>
          <span class="small muted">Waiting for a professor or administrator to review it.</span>
        <?php endif; ?>

        <?php if ($viewRep['status'] === 'approved'): ?>
          <?php // the issue form lives on the build page, so send them somewhere useful ?>
          <a class="btn btn-teal" href="<?= h(app_url('reports.php?action=build&id=' . (int)$viewRep['id'])) ?>">
            Approved — open the report to issue it</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($reviews): ?>
      <h3>Review history</h3>
      <div class="table-wrap">
        <table>
          <thead><tr><th>When</th><th>Decision</th><th>By</th><th>Comment</th></tr></thead>
          <tbody>
          <?php foreach ($reviews as $rv): ?>
            <tr>
              <td class="small nowrap"><?= h(date('d M Y H:i', strtotime((string)$rv['created_at']))) ?></td>
              <td><?= badge(ucfirst((string)$rv['decision']),
                    $rv['decision'] === 'approved' ? 'green' : ($rv['decision'] === 'returned' ? 'red' : 'blue')) ?></td>
              <td class="small"><?= h($rv['actor_name'] ?: '—') ?><div class="muted"><?= h($rv['actor_role'] ?: '') ?></div></td>
              <td class="small"><?= h($rv['comment'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Sections on this report</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>#</th><th>Test</th><th>Heading printed</th><th>Standard</th>
                   <th class="right">Readings</th><th>State</th></tr></thead>
        <tbody>
        <?php foreach ($sections as $i => $s): ?>
          <tr>
            <td class="num"><?= $i + 1 ?></td>
            <td><strong><?= h($s['test_name']) ?></strong></td>
            <td class="small"><?= h($s['title'] ?: ($s['report_title'] ?: strtoupper($s['test_name']))) ?></td>
            <td class="small mono"><?= h($s['std_code'] ?: '—') ?></td>
            <td class="num"><?= (int)$s['readings'] ?></td>
            <td><?= (int)$s['is_included'] ? badge('included', 'green') : badge('omitted', 'grey') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($viewRep['content_hash']): ?>
  <div class="card">
    <h2>Integrity</h2>
    <table style="width:auto">
      <tr><td class="muted" style="width:180px">Issued</td><td><?= h(fmt_date($viewRep['issued_at'])) ?></td></tr>
      <tr><td class="muted">Content hash</td><td class="mono small"><?= h($viewRep['content_hash']) ?></td></tr>
      <tr><td class="muted">Barcode encodes</td><td class="mono"><?= h($viewRep['barcode_value']) ?></td></tr>
      <tr><td class="muted">Public verify page</td>
          <td class="mono small"><a href="<?= h(app_url('verify.php?r=' . urlencode((string)$viewRep['report_number']) . '&c=' . urlencode((string)$viewRep['verify_code']))) ?>" target="_blank">
            verify.php?r=<?= h($viewRep['report_number']) ?>&amp;c=<?= h($viewRep['verify_code']) ?></a></td></tr>
    </table>
  </div>
  <?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
