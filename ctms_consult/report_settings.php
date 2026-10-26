<?php
declare(strict_types=1);
/**
 * Report settings.
 *
 * The signature block and the report layout are the laboratory's decisions, not
 * the programmer's. Staff change; sometimes two people sign, sometimes three.
 * This screen lets an administrator, HOD, professor or technician adjust the
 * printed report without anyone editing a file.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/engine.php';
require_once __DIR__ . '/inc/report_settings.php';

$u = require_login();

// Who may change report settings (signatories and official layouts).
// Restricted to management roles to maintain separation of duties.
$canEdit = in_array($u['role'], ['superadmin', 'admin', 'professor'], true);

if (is_post() && post('do') === 'save_signatures') {
    if (!$canEdit) {
        flash('Your role cannot change report settings.', 'err');
        redirect('report_settings.php');
    }
    // A checkbox only posts when ticked, and a row that was deleted from the form
    // posts nothing at all. So: rows present in the form are updated from whether
    // 'active' was posted; rows absent from the form keep their stored value.
    // Nothing is ever silently removed.
    $posted = [];
    foreach ((array)post('sig', []) as $s) {
        $label = trim((string)($s['label'] ?? ''));
        if ($label === '') continue;
        $posted[$label] = array_key_exists('active', (array)$s);
    }
    $before = report_signatures();
    $list = [];
    foreach ($before as $s) {
        $label  = $s['label'];
        $active = array_key_exists($label, $posted) ? $posted[$label] : $s['active'];
        $list[] = ['label' => $label, 'active' => $active];
    }
    // A label posted by the form but not yet stored is a new line.
    foreach ($posted as $label => $active) {
        $known = false;
        foreach ($list as $s) {
            if ($s['label'] === $label) { $known = true; break; }
        }
        if (!$known) $list[] = ['label' => $label, 'active' => $active];
    }
    if (!$list) {
        flash('At least one signature line is needed.', 'err');
    } else {
        report_signatures_save($list);
        audit('REPORT_SIGNATURES_CHANGED', 'settings', null, $before, $list,
              trim((string)post('reason', '')) ?: null);
        $on = count(array_filter($list, fn($s) => $s['active']));
        flash('Signature lines updated — ' . $on . ' line(s) will print. '
            . 'Nothing was removed; unticked lines are kept and can be switched back on.', 'ok');
    }
    redirect('report_settings.php');
}

if (is_post() && post('do') === 'add_signature') {
    if (!$canEdit) { flash('Your role cannot change report settings.', 'err'); redirect('report_settings.php'); }
    $label = trim((string)post('new_label', ''));
    $list  = report_signatures();
    if ($label === '') {
        flash('Type the wording for the new line first.', 'err');
    } else {
        $duplicate = false;
        foreach ($list as $s) {
            if (strcasecmp($s['label'], $label) === 0) { $duplicate = true; break; }
        }
        if ($duplicate) {
            flash('A line reading <strong>' . h($label) . '</strong> already exists, '
                . 'so nothing was added. Switch that one on instead.', 'warn');
        } else {
            $list[] = ['label' => $label, 'active' => true];
            report_signatures_save($list);
            audit('REPORT_SIGNATURE_ADDED', 'settings', null, null, ['label' => $label]);
            flash('Signature line <strong>' . h($label) . '</strong> added and switched on.', 'ok');
        }
    }
    redirect('report_settings.php');
}

if (is_post() && post('do') === 'save_layout') {
    if (!$canEdit) { flash('Your role cannot change report settings.', 'err'); redirect('report_settings.php'); }
    $keys = ['report_orientation', 'report_show_barcode', 'report_show_summary', 'report_show_remarks', 'require_approval'];
    $before = [];
    foreach ($keys as $k) $before[$k] = setting($k, '');
    foreach ($keys as $k) {
        $val = (string)post($k, '');
        if (in_array($k, ['report_show_barcode','report_show_summary','report_show_remarks','require_approval'], true)) {
            $val = ($val === '1') ? '1' : '0';
        }
        if ($k === 'report_orientation' && !in_array($val, ['landscape','portrait'], true)) $val = 'landscape';
        setting_set($k, $val);
    }
    $prefix = strtoupper(trim((string)post('report_number_prefix', '')));
    if ($prefix !== '') setting_set('report_number_prefix', mb_substr($prefix, 0, 12));
    audit('REPORT_LAYOUT_CHANGED', 'settings', null, $before,
          ['orientation' => post('report_orientation'), 'barcode' => post('report_show_barcode')],
          trim((string)post('reason', '')) ?: null);
    flash('Report layout updated.', 'ok');
    redirect('report_settings.php');
}

$sigs = report_signatures();

$PAGE_TITLE = 'Report settings';
$PAGE_SUB   = 'Signature lines and layout of the printed report';
include __DIR__ . '/inc/layout_top.php';
?>

<?php if (!$canEdit): ?>
  <div class="msg msg-warn">
    You can view these settings but not change them. Changing them is limited to
    administrator, HOD, professor and technician roles.
  </div>
<?php endif; ?>

<div class="card">
  <h2>Signature lines printed at the foot of the report</h2>
  <p class="small muted" style="margin-bottom:12px">
    Tick a line to print it. <strong>Measured By</strong> ships switched off, because the
    technician records the readings in the system and the report is checked and issued.
    Switch it back on, rename a line, or add one for whoever is signing this month.
  </p>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save_signatures">
    <div class="table-wrap">
      <table>
        <thead><tr><th style="width:70px">Print</th><th>Text printed under the line</th>
                   <th style="width:120px">Currently</th></tr></thead>
        <tbody>
        <?php foreach ($sigs as $i => $s): ?>
          <tr>
            <td style="text-align:center">
              <!-- no hidden twin: a checkbox that is not ticked simply posts nothing -->
              <input type="checkbox" name="sig[<?= $i ?>][active]" value="1" style="width:auto"
                     <?= $s['active'] ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
            </td>
            <td>
              <input type="text" name="sig[<?= $i ?>][label]" value="<?= h($s['label']) ?>"
                     <?= $canEdit ? '' : 'readonly' ?>>
            </td>
            <td><?= $s['active'] ? badge('printed', 'green') : badge('hidden', 'grey') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($canEdit): ?>
      <div class="field" style="margin-top:14px">
        <label for="reason">Reason for the change</label>
        <input id="reason" name="reason" type="text"
               placeholder="e.g. second HOD appointed; Measured By removed as technicians record data directly">
        <div class="hint">Recorded in the audit trail, because it changes what clients receive.</div>
      </div>
      <div class="toolbar">
        <button class="btn btn-copper" type="submit">Save signature lines</button>
      </div>
    <?php endif; ?>
  </form>

  <?php if ($canEdit): ?>
    <h3>Add another line</h3>
    <form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="add_signature">
      <div style="flex:1;min-width:320px">
        <label for="new_label">Text for the new line</label>
        <input id="new_label" name="new_label" type="text"
               placeholder="e.g. Verified by Deputy HOD">
      </div>
      <button class="btn btn-ghost" type="submit">Add line</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Report layout</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save_layout">
    <div class="row r4">
      <div class="field">
        <label for="report_orientation">Page orientation</label>
        <select id="report_orientation" name="report_orientation" <?= $canEdit ? '' : 'disabled' ?>>
          <option value="landscape" <?= report_layout('report_orientation') === 'landscape' ? 'selected' : '' ?>>Landscape</option>
          <option value="portrait"  <?= report_layout('report_orientation') === 'portrait' ? 'selected' : '' ?>>Portrait</option>
        </select>
        <div class="hint">Default for new reports. A report can still override it.</div>
      </div>
      <div class="field">
        <label for="report_number_prefix">Report number prefix</label>
        <input id="report_number_prefix" name="report_number_prefix" type="text"
               value="<?= h(setting('report_number_prefix', 'RPT')) ?>" <?= $canEdit ? '' : 'readonly' ?>>
        <div class="hint">e.g. RPT gives RPT-2026-0001</div>
      </div>
      <div class="field">
        <label for="report_show_barcode">Barcode</label>
        <select id="report_show_barcode" name="report_show_barcode" <?= $canEdit ? '' : 'disabled' ?>>
          <option value="1" <?= report_layout('report_show_barcode') === '1' ? 'selected' : '' ?>>Print the barcode</option>
          <option value="0" <?= report_layout('report_show_barcode') === '0' ? 'selected' : '' ?>>Do not print it</option>
        </select>
      </div>
      <div class="field">
        <label for="report_show_summary">Summary block</label>
        <select id="report_show_summary" name="report_show_summary" <?= $canEdit ? '' : 'disabled' ?>>
          <option value="1" <?= report_layout('report_show_summary') === '1' ? 'selected' : '' ?>>Print when written</option>
          <option value="0" <?= report_layout('report_show_summary') === '0' ? 'selected' : '' ?>>Never print</option>
        </select>
      </div>
    </div>
    <div class="row r2">
      <div class="field">
        <label for="report_show_remarks">Remarks / Term &amp; Conditions</label>
        <select id="report_show_remarks" name="report_show_remarks" <?= $canEdit ? '' : 'disabled' ?>>
          <option value="1" <?= report_layout('report_show_remarks') === '1' ? 'selected' : '' ?>>Print</option>
          <option value="0" <?= report_layout('report_show_remarks') === '0' ? 'selected' : '' ?>>Do not print</option>
        </select>
      </div>
      <div class="field">
        <label for="require_approval">Approval before issuing</label>
        <select id="require_approval" name="require_approval" <?= $canEdit ? '' : 'disabled' ?>>
          <option value="1" <?= report_layout('require_approval') === '1' ? 'selected' : '' ?>>
            Required — a professor or administrator must approve first</option>
          <option value="0" <?= report_layout('require_approval') === '0' ? 'selected' : '' ?>>
            Not required — a report can be issued directly</option>
        </select>
        <div class="hint">
          With approval required, a report goes draft → sent for review → approved → issued.
          Reviewers can return it with a comment instead of approving.
        </div>
      </div>
    </div>
    <div class="row r2">
      <div class="field">
        <label for="layout_reason">Reason (if changing)</label>
        <input id="layout_reason" name="reason" type="text" <?= $canEdit ? '' : 'readonly' ?>>
      </div>
      <div class="field"></div>
    </div>
    <?php if ($canEdit): ?>
      <button class="btn btn-copper" type="submit">Save layout</button>
    <?php endif; ?>
  </form>
</div>

<div class="card">
  <h2>What the foot of the report will look like</h2>
  <?php $act = report_signatures_active(); ?>
  <?php if (!$act): ?>
    <div class="empty">No signature lines will print.</div>
  <?php else: ?>
    <table style="width:100%;border-collapse:collapse">
      <tr>
        <?php foreach ($act as $s): ?>
          <td style="text-align:center;padding-top:22px;width:<?= (int)floor(100 / count($act)) ?>%">
            <div style="border-top:1px solid #000;margin:0 8%;padding-top:3px;font-size:11px">
              <?= h($s['label']) ?>
            </div>
          </td>
        <?php endforeach; ?>
      </tr>
    </table>
    <p class="small muted" style="margin-top:10px">
      <?= count($act) ?> line(s) will print.
      <?= report_layout('report_show_barcode') === '1' ? 'The barcode prints beside them.' : 'The barcode is switched off.' ?>
    </p>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
