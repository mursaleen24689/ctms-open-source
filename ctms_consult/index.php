<?php
declare(strict_types=1);
/**
 * Dashboard — the workflow at a glance.
 *
 * The order on this page mirrors how the laboratory actually works:
 *   invoice -> sample -> pieces -> test jobs -> data entry -> report -> dispatch
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/engine.php';

$u = require_login();

// Small helper: run a query and return its first column.
// NOTE: never name a loop variable $q in this file — it would overwrite this closure.
function ctms_dashboard_scalar(string $sql, array $params = []) {
    $st = $GLOBALS['pdo']->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
}

// ── counts for each stage of the workflow ────────────────────────────────────
$counts = [
    'clients'          => (int)ctms_dashboard_scalar('SELECT COUNT(*) FROM c_clients'),
    'invoices_open'    => (int)ctms_dashboard_scalar("SELECT COUNT(*) FROM c_billing WHERE status IN ('draft','issued','partial')"),
    'invoices_paid'    => (int)ctms_dashboard_scalar("SELECT COUNT(*) FROM c_billing WHERE status = 'paid'"),
    'samples_active'   => (int)ctms_dashboard_scalar("SELECT COUNT(*) FROM c_samples WHERE is_deleted = 0 AND status NOT IN ('dispatched')"),
    'samples_awaiting' => (int)ctms_dashboard_scalar("SELECT COUNT(*) FROM c_samples WHERE is_deleted = 0 AND status = 'awaiting_report'"),
    'pieces'           => (int)ctms_dashboard_scalar('SELECT COUNT(*) FROM c_sample_pieces WHERE is_deleted = 0'),
    'jobs_pending'     => (int)ctms_dashboard_scalar("SELECT COUNT(*) FROM c_test_jobs WHERE status IN ('pending','in_progress')"),
    'jobs_entered'     => (int)ctms_dashboard_scalar("SELECT COUNT(*) FROM c_test_jobs WHERE status IN ('entered','checked')"),
    'jobs_approved'    => (int)ctms_dashboard_scalar("SELECT COUNT(*) FROM c_test_jobs WHERE status = 'approved'"),
    'reports_draft'    => (int)ctms_dashboard_scalar("SELECT COUNT(*) FROM c_reports WHERE status IN ('draft','in_review')"),
    'reports_issued'   => (int)ctms_dashboard_scalar("SELECT COUNT(*) FROM c_reports WHERE status = 'issued'"),
    'reports_awaiting_dispatch' => (int)ctms_dashboard_scalar("SELECT COUNT(*) FROM c_reports r
                                             WHERE r.status = 'issued'
                                               AND NOT EXISTS (SELECT 1 FROM c_dispatches d
                                                               WHERE d.report_id = r.id AND d.is_reversed = 0)"),
    'readings'         => (int)ctms_dashboard_scalar('SELECT COUNT(*) FROM c_readings WHERE is_current = 1'),
    'warnings_open'    => (int)ctms_dashboard_scalar("SELECT COUNT(*) FROM c_readings WHERE is_current = 1 AND validation_state = 'warning'"),
    'overrides'        => (int)ctms_dashboard_scalar("SELECT COUNT(*) FROM c_readings WHERE is_current = 1 AND validation_state = 'overridden'"),
    'standards'        => (int)ctms_dashboard_scalar('SELECT COUNT(*) FROM c_standards WHERE is_active = 1'),
];

// ── work waiting on this person specifically ────────────────────────────────
$myJobs = [];
if (in_array($u['role'], ['technician', 'superadmin', 'admin'], true)) {
    $st = $pdo->prepare(
        "SELECT j.id, j.status, j.priority, j.due_date,
                tt.name AS test_name, s.sample_code, s.description, s.company_name
           FROM c_test_jobs j
           JOIN c_test_types tt ON tt.id = j.test_type_id
           JOIN c_samples   s  ON s.id  = j.sample_id
          WHERE j.status IN ('pending','in_progress')
            AND (j.assigned_to = ? OR ? IN ('superadmin','admin'))
            AND s.is_deleted = 0
          ORDER BY FIELD(j.priority,'urgent','normal'), j.due_date IS NULL, j.due_date, j.id
          LIMIT 12");
    $st->execute([(int)$u['id'], $u['role']]);
    $myJobs = $st->fetchAll();
}

// ── recent activity, straight from the audit trail ──────────────────────────
$recent = $pdo->query(
    'SELECT action, module, record_id, actor_name, created_at
       FROM c_audit
      ORDER BY id DESC LIMIT 12')->fetchAll();

// ── overdue jobs ────────────────────────────────────────────────────────────
$overdue = $pdo->query(
    "SELECT j.id, tt.name AS test_name, s.sample_code, j.due_date,
            DATEDIFF(CURDATE(), j.due_date) AS days_late
       FROM c_test_jobs j
       JOIN c_test_types tt ON tt.id = j.test_type_id
       JOIN c_samples   s  ON s.id  = j.sample_id
      WHERE j.due_date < CURDATE()
        AND j.status IN ('pending','in_progress')
        AND s.is_deleted = 0
      ORDER BY j.due_date LIMIT 8")->fetchAll();

$PAGE_TITLE = 'Dashboard';
$PAGE_SUB   = 'Invoice → sample → pieces → tests → data entry → report → dispatch';
include __DIR__ . '/inc/layout_top.php';
?>

<?php if ($counts['overrides'] > 0 || $counts['warnings_open'] > 0): ?>
<div class="msg msg-warn">
  <strong><?= (int)$counts['warnings_open'] ?> reading(s) carry an unresolved warning</strong>
  <?php if ($counts['overrides'] > 0): ?>
    and <strong><?= (int)$counts['overrides'] ?></strong> were deliberately overridden by a technician.
    Both are normal in this laboratory — the record exists so the decision can be reviewed later.
  <?php endif; ?>
</div>
<?php endif; ?>

<h2 style="font-size:12px;text-transform:uppercase;letter-spacing:.7px;color:var(--text-muted);margin-bottom:10px">
  Where the work is now
</h2>
<div class="grid g4" style="margin-bottom:22px">
  <div class="kpi">
    <div class="l">Invoices open</div><div class="v"><?= (int)$counts['invoices_open'] ?></div>
    <div class="s"><?= (int)$counts['invoices_paid'] ?> paid · <?= (int)$counts['clients'] ?> clients</div>
  </div>
  <div class="kpi" style="border-left-color:var(--navy-2)">
    <div class="l">Samples in hand</div><div class="v"><?= (int)$counts['samples_active'] ?></div>
    <div class="s"><?= (int)$counts['pieces'] ?> pieces cut</div>
  </div>
  <div class="kpi" style="border-left-color:var(--amber)">
    <div class="l">Tests to do</div><div class="v"><?= (int)$counts['jobs_pending'] ?></div>
    <div class="s"><?= (int)$counts['jobs_entered'] ?> entered · <?= (int)$counts['jobs_approved'] ?> approved</div>
  </div>
  <div class="kpi" style="border-left-color:var(--teal)">
    <div class="l">Awaiting dispatch</div><div class="v"><?= (int)$counts['reports_awaiting_dispatch'] ?></div>
    <div class="s"><?= (int)$counts['reports_draft'] ?> draft · <?= (int)$counts['reports_issued'] ?> issued</div>
  </div>
</div>

<h2 style="font-size:12px;text-transform:uppercase;letter-spacing:.7px;color:var(--text-muted);margin-bottom:10px">
  Start here
</h2>
<div class="card">
  <div class="toolbar" style="margin:0">
    <a class="btn btn-copper" href="<?= h(app_url('invoices.php?action=new')) ?>">+ New invoice</a>
    <a class="btn" href="<?= h(app_url('samples.php?action=new')) ?>">+ Receive a sample</a>
    <a class="btn btn-ghost" href="<?= h(app_url('jobs.php')) ?>">Test queue</a>
    <a class="btn btn-ghost" href="<?= h(app_url('standards.php')) ?>">Standards library
      <span class="badge badge-grey" style="margin-left:3px"><?= (int)$counts['standards'] ?></span></a>
    <span class="small muted" style="margin-left:auto">
      <?= (int)$counts['readings'] ?> readings stored · <?= (int)$counts['standards'] ?> standards available
    </span>
  </div>
</div>

<div class="grid g2">
  <div class="card">
    <h2>Tests waiting for you</h2>
    <?php if (!$myJobs): ?>
      <div class="empty">Nothing is waiting. Test jobs appear here once a sample is received and its
        tests are chosen.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Sample</th><th>Test</th><th>Client</th><th>Status</th><th>Due</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($myJobs as $j): ?>
            <tr>
              <td class="mono"><?= h($j['sample_code']) ?></td>
              <td><?= h($j['test_name']) ?></td>
              <td class="small"><?= h($j['company_name'] ?: '-') ?></td>
              <td><?= status_badge($j['status']) ?><?= $j['priority'] === 'urgent' ? ' ' . badge('urgent', 'red') : '' ?></td>
              <td class="small nowrap"><?= h(fmt_date($j['due_date'])) ?></td>
              <td><a class="btn btn-sm btn-teal" href="<?= h(app_url('entry.php?job=' . (int)$j['id'])) ?>">Enter data</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Overdue</h2>
    <?php if (!$overdue): ?>
      <div class="empty">Nothing is overdue.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Sample</th><th>Test</th><th>Was due</th><th class="right">Late by</th></tr></thead>
          <tbody>
          <?php foreach ($overdue as $o): ?>
            <tr>
              <td class="mono"><?= h($o['sample_code']) ?></td>
              <td><?= h($o['test_name']) ?></td>
              <td class="small nowrap"><?= h(fmt_date($o['due_date'])) ?></td>
              <td class="num" style="color:var(--rust);font-weight:700"><?= (int)$o['days_late'] ?> d</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>Recent activity</h2>
  <?php if (!$recent): ?>
    <div class="empty">No activity yet. Create an invoice or receive a sample to begin.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>When</th><th>Who</th><th>What happened</th><th>Area</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
          <tr>
            <td class="small nowrap"><?= h(date('d M H:i', strtotime($r['created_at']))) ?></td>
            <td class="small"><?= h($r['actor_name'] ?: 'system') ?></td>
            <td><?= h(str_replace('_', ' ', strtolower($r['action']))) ?></td>
            <td class="small muted"><?= h($r['module']) ?><?= $r['record_id'] ? ' #' . (int)$r['record_id'] : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
