<?php
declare(strict_types=1);
/**
 * Switch between Guided and Direct working modes.
 *
 * A small endpoint of its own so the toggle works from any page without every
 * page needing to handle it. Records the change in the audit trail, because
 * which mode a value was entered under matters when the record is reviewed.
 */
require_once __DIR__ . '/inc/bootstrap.php';

$u = require_login();

$to   = (string)get('to', '');
$back = (string)get('back', 'index.php');

// only ever redirect back into this application
if ($back === '' || str_contains($back, '//') || str_starts_with($back, 'http')) {
    $back = 'index.php';
}

$mode = mode_valid($to);
$was  = current_mode();

if ($mode !== $was || post('remember') === '1') {
    set_mode($mode, true);
    audit('WORKING_MODE_CHANGED', 'settings', null,
          ['mode' => $was], ['mode' => $mode],
          $mode === 'direct'
            ? 'Switched to Direct: the technician decides, checks are recorded but not shown.'
            : 'Switched to Guided: values are checked as they are entered.');
    flash($mode === 'direct'
        ? 'Now in <strong>Direct</strong> mode — you decide. No warnings, no suggestions; '
          . 'the usual checks are still recorded quietly.'
        : 'Now in <strong>Guided</strong> mode — values are checked as you type them.',
        $mode === 'direct' ? 'warn' : 'ok');
} else {
    // already in that mode: treat this as "remember this choice"
    $pdo->prepare('UPDATE c_users SET report_mode = ? WHERE id = ?')->execute([$mode, (int)$u['id']]);
    flash('Saved: your account will start in <strong>' . h(mode_label($mode)) . '</strong> mode.', 'ok');
}

redirect($back);
