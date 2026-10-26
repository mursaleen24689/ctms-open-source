<?php
declare(strict_types=1);
/**
 * Printable report.
 *
 * Standalone on purpose: it renders the same markup the builder previews, but
 * with no application chrome, so what is printed is exactly what was reviewed.
 * A draft can be opened by anyone signed in; an issued report is the record.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/engine.php';
require_once __DIR__ . '/inc/report.php';

require_login();

$id = (int)get('id', 0);
$model = ctms_build_report_model($id);
if (!$model) {
    http_response_code(404);
    exit('Report not found.');
}

// Every print of an issued report is worth knowing about.
if ($model['report']['status'] === 'issued') {
    audit('REPORT_PRINTED', 'reports', $id, null, ['number' => $model['report']['report_number']], null,
          (int)$model['report']['sample_id']);
}

echo ctms_render_report($model, true);
