<?php
declare(strict_types=1);
/**
 * Dispatch register — workflow step 5.
 *
 * Records that a report left the laboratory and who took it. This is the
 * evidence that the document was handed over, and the point at which a report
 * becomes locked: once a copy is in a client's hands, changing the readings
 * behind it would make the paper wrong.
 *
 * A dispatch can be reversed if it was recorded in error, with a reason, and the
 * original record is kept.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/engine.php';

$u = require_login();

$reportId = (int)(get('report') ?? post('report_id', 0));

// ── record a dispatch ────────────────────────────────────────────────────────
if (is_post() && post('do') === 'dispatch') {
    require_login(['superadmin', 'admin', 'professor', 'reception']);
    $st = $pdo->prepare('SELECT * FROM c_reports WHERE id = ?');
    $st->execute([$reportId]);
    $rep = $st->fetch();

    $errors = [];
    if (!$rep) {
        $errors[] = 'That report does not exist.';
    } elseif ($rep['status'] !== 'issued') {
        $errors[] = 'Only an issued report can be dispatched. Issue it first.';
    }

    $method    = (string)post('method', 'hand');
    if (!in_array($method, ['hand','courier','email','post','other'], true)) $method = 'hand';
    $received  = trim((string)post('received_by', ''));
    $courier   = trim((string)post('courier_ref', ''));
    $copies    = max(1, min(50, (int)post('copies', 1)));
    $notes     = trim((string)post('notes', ''));
    $when      = trim((string)post('dispatched_at', '')) ?: now();

    if ($received === '') $errors[] = 'Record who received the report — that is the point of the register.';
    if (($method === 'courier' || $method === 'post') && $courier === '') {
        $errors[] = 'A courier or postal reference is needed for that method, so the delivery can be traced.';
    }

    if ($errors) {
        foreach ($errors as $e) flash('<strong>Not recorded:</strong> ' . h($e), 'err');
        redirect('dispatch.php?report=' . $reportId);
    }

    // already out?
    $st = $pdo->prepare('SELECT COUNT(*) FROM c_dispatches WHERE report_id = ? AND is_reversed = 0');
    $st->execute([$reportId]);
    if ((int)$st->fetchColumn() > 0) {
        flash('This report is already recorded as dispatched. Reverse that entry first if it was wrong.', 'err');
        redirect('dispatch.php?report=' . $reportId);
    }

    $pdo->prepare('INSERT INTO c_dispatches (report_id, dispatched_at, dispatched_by, received_by,
                     method, courier_ref, copies, notes)
                   VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$reportId, $when, $u['id'], $received, $method, $courier ?: null, $copies, $notes ?: null]);
    $dispId = (int)$pdo->lastInsertId();

    if ($rep['sample_id']) {
        $pdo->prepare('UPDATE c_samples SET status = "dispatched", updated_at = NOW() WHERE id = ?')
            ->execute([(int)$rep['sample_id']]);
    }

    audit('REPORT_DISPATCHED', 'reports', $reportId, null, [
        'dispatch_id' => $dispId, 'received_by' => $received, 'method' => $method,
        'courier_ref' => $courier, 'copies' => $copies,
    ], $notes ?: null, (int)($rep['sample_id'] ?? 0));

    flash('Dispatch recorded — <strong>' . h($rep['report_number']) . '</strong> handed to '
        . h($received) . '. The report is now locked.', 'ok');
    redirect('dispatch.php?report=' . $reportId);
}

// ── reverse a dispatch ───────────────────────────────────────────────────────
if (is_post() && post('do') === 'reverse') {
    require_login(['superadmin', 'admin', 'professor']);
    $dispId = (int)post('dispatch_id');
    $reason = trim((string)post('reason', ''));
    $st = $pdo->prepare('SELECT d.*, r.report_number, r.sample_id FROM c_dispatches d
                          JOIN c_reports r ON r.id = d.report_id WHERE d.id = ?');
    $st->execute([$dispId]);
    $d = $st->fetch();

    if (!$d) {
        flash('That dispatch record was not found.', 'err');
        redirect('dispatch.php');
    }
    if ((int)$d['is_reversed'] === 1) {
        flash('That dispatch has already been reversed.', 'warn');
        redirect('dispatch.php?report=' . (int)$d['report_id']);
    }
    if ($reason === '') {
        flash('A reason is required to reverse a dispatch — the record is evidence of handing over a report.', 'err');
        redirect('dispatch.php?report=' . (int)$d['report_id']);
    }

    $pdo->prepare('UPDATE c_dispatches SET is_reversed = 1, reversed_at = NOW(), reversed_by = ?,
                     reverse_reason = ? WHERE id = ?')
        ->execute([$u['id'], $reason, $dispId]);

    // if nothing else is outstanding, the sample goes back to "reported"
    $st = $pdo->prepare('SELECT COUNT(*) FROM c_dispatches WHERE report_id = ? AND is_reversed = 0');
    $st->execute([(int)$d['report_id']]);
    if ((int)$st->fetchColumn() === 0 && $d['sample_id']) {
        $pdo->prepare('UPDATE c_samples SET status = "reported", updated_at = NOW() WHERE id = ?')
            ->execute([(int)$d['sample_id']]);
    }

    audit('DISPATCH_REVERSED', 'reports', (int)$d['report_id'], [
        'dispatch_id' => $dispId, 'received_by' => $d['received_by'], 'method' => $d['method'],
    ], ['is_reversed' => 1], $reason, (int)($d['sample_id'] ?? 0));

    flash('Dispatch reversed. The original entry is kept and marked as reversed.', 'warn');
    redirect('dispatch.php?report=' . (int)$d['report_id']);
}

// ── page data ────────────────────────────────────────────────────────────────
$viewReport = null; $viewDispatches = [];
if ($reportId) {
    $st = $pdo->prepare('SELECT r.*, s.sample_code, s.description AS sample_description
                           FROM c_reports r LEFT JOIN c_samples s ON s.id = r.sample_id WHERE r.id = ?');
    $st->execute([$reportId]);
    $viewReport = $st->fetch() ?: null;
    if ($viewReport) {
        $st = $pdo->prepare('SELECT d.*, u.full_name AS by_name, ru.full_name AS reversed_by_name
                               FROM c_dispatches d
                               LEFT JOIN c_users u  ON u.id  = d.dispatched_by
                               LEFT JOIN c_users ru ON ru.id = d.reversed_by
                              WHERE d.report_id = ? ORDER BY d.id DESC');
        $st->execute([$reportId]);
        $viewDispatches = $st->fetchAll();
    }
}

// awaiting dispatch
$awaiting = $pdo->query(
    "SELECT r.id, r.report_number, r.company_name, r.issued_at, s.sample_code
       FROM c_reports r LEFT JOIN c_samples s ON s.id = r.sample_id
      WHERE r.status = 'issued'
        AND NOT EXISTS (SELECT 1 FROM c_dispatches d WHERE d.report_id = r.id AND d.is_reversed = 0)
      ORDER BY r.issued_at ASC")->fetchAll();

// everything that has gone out
$sent = $pdo->query(
    "SELECT d.*, r.report_number, r.company_name, u.full_name AS by_name, s.sample_code
       FROM c_dispatches d
       JOIN c_reports r ON r.id = d.report_id
       LEFT JOIN c_users u ON u.id = d.dispatched_by
       LEFT JOIN c_samples s ON s.id = r.sample_id
      ORDER BY d.dispatched_at DESC LIMIT 100")->fetchAll();

$PAGE_TITLE = $viewReport ? 'Dispatch — ' . $viewReport['report_number'] : 'Dispatch register';
$PAGE_SUB   = 'Workflow step 5 — records that a report left the laboratory, and to whom';
include __DIR__ . '/inc/layout_top.php';
?>

<?php if ($viewReport): ?>
  <?php
  $out = array_values(array_filter($viewDispatches, fn($d) => (int)$d['is_reversed'] === 0));
  $canDispatch = ($viewReport['status'] === 'issued' && !$out);
  ?>

  <div class="grid g3" style="margin-bottom:16px">
    <div class="kpi"><div class="l">Report</div>
      <div class="v" style="font-size:16px;padding-top:6px" class="mono"><?= h($viewReport['report_number']) ?></div>
      <div class="s"><?= h($viewReport['company_name']) ?></div></div>
    <div class="kpi" style="border-left-color:var(--teal)"><div class="l">Status</div>
      <div class="v" style="font-size:16px;padding-top:6px"><?= status_badge($viewReport['status']) ?></div>
      <div class="s"><?= $out ? 'handed over' : 'not yet dispatched' ?></div></div>
    <div class="kpi" style="border-left-color:var(--copper)"><div class="l">Sample</div>
      <div class="v" style="font-size:16px;padding-top:6px"><?= h($viewReport['sample_code'] ?: '—') ?></div>
      <div class="s"><?= h(mb_strimwidth((string)$viewReport['sample_description'], 0, 30, '…')) ?></div></div>
  </div>

  <div class="card">
    <div class="toolbar" style="margin:0">
      <a class="btn btn-ghost" href="<?= h(app_url('dispatch.php')) ?>">All dispatches</a>
      <a class="btn btn-teal" href="<?= h(app_url('report_view.php?id=' . (int)$viewReport['id'])) ?>" target="_blank">Printable report</a>
      <a class="btn btn-ghost" href="<?= h(app_url('reports.php?action=view&id=' . (int)$viewReport['id'])) ?>">Report details</a>
    </div>
  </div>

  <?php if ($canDispatch): ?>
    <div class="card" style="border-left:4px solid var(--teal)">
      <h2>Record the hand-over</h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="dispatch">
        <input type="hidden" name="report_id" value="<?= (int)$viewReport['id'] ?>">
        <div class="row r3">
          <div class="field">
            <label for="received_by">Received by *</label>
            <input id="received_by" name="received_by" type="text" required
                   placeholder="name of the person who collected it">
            <div class="hint">The register is only useful if it names who took the report.</div>
          </div>
          <div class="field">
            <label for="method">How</label>
            <select id="method" name="method">
              <option value="hand">Collected by hand</option>
              <option value="courier">Courier</option>
              <option value="email">Email</option>
              <option value="post">Post</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="field">
            <label for="dispatched_at">When</label>
            <input id="dispatched_at" name="dispatched_at" type="text" value="<?= h(now()) ?>">
            <div class="hint">YYYY-MM-DD HH:MM:SS</div>
          </div>
        </div>
        <div class="row r3">
          <div class="field">
            <label for="courier_ref">Courier / postal reference</label>
            <input id="courier_ref" name="courier_ref" type="text" placeholder="required for courier and post">
          </div>
          <div class="field">
            <label for="copies">Copies handed over</label>
            <input id="copies" name="copies" type="number" min="1" max="50" value="1">
          </div>
          <div class="field">
            <label for="notes">Notes</label>
            <input id="notes" name="notes" type="text" placeholder="e.g. collected by driver, signed receipt">
          </div>
        </div>
        <button class="btn btn-teal" type="submit">Record dispatch</button>
        <span class="small muted" style="margin-left:10px">
          Once recorded, the report and its readings are locked.
        </span>
      </form>
    </div>
  <?php elseif ($viewReport['status'] !== 'issued'): ?>
    <div class="msg msg-warn">
      This report has not been issued yet, so it cannot be dispatched.
      <a href="<?= h(app_url('reports.php?action=build&id=' . (int)$viewReport['id'])) ?>">Open the report</a>
      to issue it first.
    </div>
  <?php else: ?>
    <div class="msg msg-ok">
      This report was handed over on
      <strong><?= h(fmt_date($out[0]['dispatched_at'])) ?></strong>
      to <strong><?= h($out[0]['received_by']) ?></strong>.
      The readings are locked while that copy is with the client.
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>Dispatch history for this report</h2>
    <?php if (!$viewDispatches): ?>
      <div class="empty">Nothing recorded yet.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>When</th><th>Received by</th><th>Method</th><th>Reference</th>
                     <th class="right">Copies</th><th>Recorded by</th><th>State</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($viewDispatches as $d): ?>
            <tr>
              <td class="small nowrap"><?= h(date('d M Y H:i', strtotime((string)$d['dispatched_at']))) ?></td>
              <td><?= h($d['received_by']) ?></td>
              <td class="small"><?= h(ucfirst((string)$d['method'])) ?></td>
              <td class="small mono"><?= h($d['courier_ref'] ?: '—') ?></td>
              <td class="num"><?= (int)$d['copies'] ?></td>
              <td class="small"><?= h($d['by_name'] ?: '—') ?></td>
              <td><?= (int)$d['is_reversed'] === 1 ? badge('reversed', 'red') : badge('handed over', 'green') ?></td>
              <td class="small">
                <?php if ((int)$d['is_reversed'] === 1 && $d['reverse_reason']): ?>
                  <em class="muted"><?= h(mb_strimwidth((string)$d['reverse_reason'], 0, 40, '…')) ?></em>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($out && can_user('superadmin', 'admin', 'professor')): ?>
    <div class="card">
      <h2>Reverse the dispatch</h2>
      <p class="small muted" style="margin-bottom:10px">
        Use this if the hand-over was recorded against the wrong report, or did not happen.
        The entry is kept and marked as reversed; nothing is deleted.
      </p>
      <form method="post" onsubmit="return confirm('Reverse this dispatch record?');">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="reverse">
        <input type="hidden" name="dispatch_id" value="<?= (int)$out[0]['id'] ?>">
        <div class="field">
          <label for="reason">Reason *</label>
          <input id="reason" name="reason" type="text" required
                 placeholder="e.g. recorded against the wrong report">
        </div>
        <button class="btn btn-danger" type="submit">Reverse dispatch</button>
      </form>
    </div>
  <?php endif; ?>

<?php else: ?>

  <div class="card">
    <h2>Reports issued, waiting to go out <span class="badge badge-amber"><?= count($awaiting) ?></span></h2>
    <?php if (!$awaiting): ?>
      <div class="empty">
        Nothing is waiting. A report appears here once it has been issued.
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Report</th><th>Company</th><th>Sample</th><th>Issued</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($awaiting as $a): ?>
            <tr>
              <td class="mono"><?= h($a['report_number']) ?></td>
              <td><?= h($a['company_name']) ?></td>
              <td class="mono small"><?= h($a['sample_code'] ?: '—') ?></td>
              <td class="small nowrap"><?= h(fmt_date($a['issued_at'])) ?></td>
              <td><a class="btn btn-sm btn-teal" href="<?= h(app_url('dispatch.php?report=' . (int)$a['id'])) ?>">Record dispatch</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Dispatch register — last 100</h2>
    <?php if (!$sent): ?>
      <div class="empty">Nothing has been dispatched yet.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Report</th><th>Company</th><th>Received by</th><th>Method</th>
                     <th>When</th><th class="right">Copies</th><th>State</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($sent as $d): ?>
            <tr>
              <td class="mono"><?= h($d['report_number']) ?></td>
              <td class="small"><?= h(mb_strimwidth((string)$d['company_name'], 0, 26, '…')) ?></td>
              <td><?= h($d['received_by']) ?></td>
              <td class="small"><?= h(ucfirst((string)$d['method'])) ?>
                <?php if ($d['courier_ref']): ?><div class="muted mono" style="font-size:10.5px"><?= h($d['courier_ref']) ?></div><?php endif; ?>
              </td>
              <td class="small nowrap"><?= h(date('d M Y', strtotime((string)$d['dispatched_at']))) ?></td>
              <td class="num"><?= (int)$d['copies'] ?></td>
              <td><?= (int)$d['is_reversed'] === 1 ? badge('reversed', 'red') : badge('sent', 'green') ?></td>
              <td><a class="btn btn-sm btn-ghost" href="<?= h(app_url('dispatch.php?report=' . (int)$d['report_id'])) ?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php endif; ?>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
