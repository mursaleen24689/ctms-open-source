<?php
declare(strict_types=1);
/**
 * Small actions on a report image: move it, resize it, or take it off the report.
 *
 * Removing an image does not delete the file. It detaches it, so a mistake can be
 * undone and the original photograph is never lost.
 */
require_once __DIR__ . '/inc/bootstrap.php';

$u = require_login(['superadmin', 'admin', 'professor', 'technician']);

if (!is_post()) {
    http_response_code(405);
    header('Allow: POST');
    exit('Use the report image form.');
}

// Validate CSRF token (must match session token)
$csrf = (string)post('csrf', '');
if ($csrf === '' || !hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
    http_response_code(403);
    flash('Security verification failed: request rejected. Please try again.', 'err');
    redirect('reports.php');
}

$id = (int)post('id', 0);
$do = (string)post('do', '');

$st = $pdo->prepare(
    'SELECT a.*, r.status AS report_status, r.report_number
       FROM c_report_assets a JOIN c_reports r ON r.id = a.report_id
      WHERE a.id = ?');
$st->execute([$id]);
$asset = $st->fetch();
if (!$asset) {
    flash('That image is not attached to any report.', 'err');
    redirect('reports.php');
}
$reportId = (int)$asset['report_id'];
$back = 'reports.php?action=build&id=' . $reportId;

if ((string)$asset['report_status'] === 'issued' && $do !== '') {
    flash('This report has been issued, so its images are locked. Raise a revision to change them.', 'err');
    redirect($back);
}

switch ($do) {
    case 'up':
    case 'down': {
        // swap sort_order with the neighbouring image
        $dir  = $do === 'up' ? 'ASC' : 'DESC';
        $cmp  = $do === 'up' ? '<' : '>';
        $st = $pdo->prepare(
            "SELECT id, sort_order FROM c_report_assets
              WHERE report_id = ? AND (sort_order $cmp ? OR (sort_order = ? AND id $cmp ?))
              ORDER BY sort_order DESC, id DESC LIMIT 1");
        $st->execute([$reportId, (int)$asset['sort_order'], (int)$asset['sort_order'], $id]);
        $other = $st->fetch();

        if ($other) {
            // if the orders match, nudge this one so the pair actually changes places
            $pdo->prepare('UPDATE c_report_assets SET sort_order = ? WHERE id = ?')
                ->execute([(int)$other['sort_order'], $id]);
            $pdo->prepare('UPDATE c_report_assets SET sort_order = ? WHERE id = ?')
                ->execute([(int)$asset['sort_order'], (int)$other['id']]);
        } else {
            $pdo->prepare('UPDATE c_report_assets SET sort_order = sort_order ' . ($do === 'up' ? '- 1' : '+ 1') . ' WHERE id = ?')
                ->execute([$id]);
        }
        audit('IMAGE_REORDERED', 'reports', $id, ['sort_order' => $asset['sort_order']],
              ['direction' => $do], null, $reportId);
        redirect($back);
    }

    case 'size': {
        $size = (string)post('size', 'medium');
        if (!in_array($size, ['small','medium','large','full','custom'], true)) $size = 'medium';
        $pdo->prepare('UPDATE c_report_assets SET display_size = ? WHERE id = ?')->execute([$size, $id]);
        audit('IMAGE_RESIZED', 'reports', $id, ['display_size' => $asset['display_size']],
              ['display_size' => $size], null, $reportId);
        redirect($back);
    }

    case 'del': {
        $pdo->prepare('DELETE FROM c_report_assets WHERE id = ?')->execute([$id]);
        audit('IMAGE_REMOVED_FROM_REPORT', 'reports', $id, [
            'caption' => $asset['caption'], 'stored' => $asset['stored_name'],
        ], null, 'taken off the report; the file is kept', $reportId);
        flash('Image taken off the report. The file is still in storage, so it can be added back.', 'warn');
        redirect($back);
    }

    default:
        redirect($back);
}
