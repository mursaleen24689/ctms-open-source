<?php
declare(strict_types=1);
/**
 * Test queue — everything issued to the laboratories.
 * This is the list a technician works from; entry.php is where they record.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/engine.php';

$u = require_login();

$status  = (string)get('status', 'open');
$search  = trim((string)get('q', ''));
$mineOnly= (string)get('mine', '') === '1';

$where = ['s.is_deleted = 0'];
$params = [];

switch ($status) {
    case 'open':     $where[] = "j.status IN ('pending','in_progress')"; break;
    case 'entered':  $where[] = "j.status IN ('entered','checked')";     break;
    case 'approved': $where[] = "j.status = 'approved'";                break;
    case 'all':      break;
    default:         $where[] = "j.status IN ('pending','in_progress')";
}

if ($search !== '') {
    $where[] = '(s.sample_code LIKE ? OR s.description LIKE ? OR s.company_name LIKE ? OR tt.name LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
if ($mineOnly) {
    $where[] = 'j.assigned_to = ?';
    $params[] = (int)$u['id'];
}

$sql = 'SELECT j.*, tt.name AS test_name, tt.category,
               s.sample_code, s.description, s.company_name,
               st.code AS std_code, st.revision AS std_rev,
               p.piece_code, us.full_name AS assigned_name,
               (SELECT COUNT(*) FROM c_readings r WHERE r.job_id = j.id AND r.is_current = 1) AS readings,
               (SELECT COUNT(*) FROM c_readings r WHERE r.job_id = j.id AND r.is_current = 1
                  AND r.validation_state IN ("warning","error")) AS warnings
          FROM c_test_jobs j
          JOIN c_test_types tt ON tt.id = j.test_type_id
          JOIN c_samples    s  ON s.id  = j.sample_id
          LEFT JOIN c_standards st ON st.id = j.standard_id
          LEFT JOIN c_sample_pieces p ON p.id = j.piece_id
          LEFT JOIN c_users us ON us.id = j.assigned_to
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY FIELD(j.priority,"urgent","normal"),
                  j.due_date IS NULL, j.due_date,
                  j.sample_id DESC, p.piece_code ASC, j.id ASC';

$st = $pdo->prepare($sql);
$st->execute($params);
$jobs = $st->fetchAll();

$tally = [];
foreach ($pdo->query("SELECT status, COUNT(*) c FROM c_test_jobs GROUP BY status") as $r) {
    $tally[$r['status']] = (int)$r['c'];
}
$total = array_sum($tally);

$PAGE_TITLE = 'Test queue';
$PAGE_SUB   = 'Workflow step 3 — tests waiting to be recorded, and what has been entered';
include __DIR__ . '/inc/layout_top.php';
?>

<div class="grid g4" style="margin-bottom:16px">
  <div class="kpi"><div class="l">Waiting</div>
    <div class="v"><?= (int)(($tally['pending'] ?? 0) + ($tally['in_progress'] ?? 0)) ?></div>
    <div class="s">not yet recorded</div></div>
  <div class="kpi" style="border-left-color:var(--blue)"><div class="l">Entered</div>
    <div class="v"><?= (int)(($tally['entered'] ?? 0) + ($tally['checked'] ?? 0)) ?></div>
    <div class="s">awaiting approval</div></div>
  <div class="kpi" style="border-left-color:var(--teal)"><div class="l">Approved</div>
    <div class="v"><?= (int)($tally['approved'] ?? 0) ?></div>
    <div class="s">ready for a report</div></div>
  <div class="kpi" style="border-left-color:var(--text-soft)"><div class="l">Total jobs</div>
    <div class="v"><?= $total ?></div>
    <div class="s">all time</div></div>
</div>

<div class="card">
  <div class="toolbar" style="margin:0">
    <div style="display:flex;border-radius:8px;overflow:hidden;border:1px solid var(--border)">
      <?php foreach (['open' => 'Waiting', 'entered' => 'Entered', 'approved' => 'Approved', 'all' => 'All'] as $k => $lab): ?>
        <a class="btn btn-sm <?= $status === $k ? '' : 'btn-ghost' ?>" style="border-radius:0"
           href="<?= h(app_url('jobs.php?status=' . $k . ($mineOnly ? '&mine=1' : ''))) ?>"><?= h($lab) ?></a>
      <?php endforeach; ?>
    </div>
    <label style="display:flex;gap:6px;align-items:center;text-transform:none;letter-spacing:0;font-weight:400;
                  font-size:12.5px;margin:0 0 0 6px;cursor:pointer">
      <input type="checkbox" style="width:auto" onchange="location.href='<?= h(app_url('jobs.php?status=' . $status . '&mine=' . ($mineOnly ? '0' : '1'))) ?>'"
             <?= $mineOnly ? 'checked' : '' ?>>
      only mine
    </label>
    <form method="get" style="display:flex;gap:8px;align-items:center;margin-left:auto">
      <input type="hidden" name="status" value="<?= h($status) ?>">
      <?php if ($mineOnly): ?><input type="hidden" name="mine" value="1"><?php endif; ?>
      <input type="text" name="q" value="<?= h($search) ?>" placeholder="sample, company or test" style="width:250px">
      <button class="btn btn-ghost" type="submit">Search</button>
    </form>
  </div>
</div>

<div class="card">
  <h2><?= count($jobs) ?> job(s)</h2>
  <?php if (!$jobs): ?>
    <div class="empty">
      Nothing here. Jobs appear once a sample is received and its tests are chosen —
      start from <a href="<?= h(app_url('samples.php')) ?>">Samples</a>.
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Sample</th><th>Test</th><th>Standard</th><th>Piece</th>
                   <th class="right">Readings</th><th>Flags</th><th>Assigned</th>
                   <th>Due</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($jobs as $j): ?>
          <tr>
            <td>
              <span class="mono"><?= h($j['sample_code']) ?></span>
              <div class="small muted"><?= h(mb_strimwidth((string)$j['company_name'], 0, 24, '…')) ?></div>
            </td>
            <td><?= h($j['test_name']) ?><div class="small muted"><?= h($j['category']) ?></div></td>
            <td class="small">
              <?php if ($j['std_code']): ?>
                <span class="mono"><?= h($j['std_code']) ?></span>
                <?= $j['standard_approved'] ? badge('ok', 'green') : badge('confirm', 'amber') ?>
              <?php else: ?><span class="muted">—</span><?php endif; ?>
            </td>
            <td class="mono small"><?= h($j['piece_code'] ?: '—') ?></td>
            <td class="num"><?= (int)$j['readings'] ?></td>
            <td>
              <?php if ((int)$j['warnings'] > 0): ?>
                <?= badge($j['warnings'] . ' warning', 'amber') ?>
              <?php else: ?><span class="muted small">—</span><?php endif; ?>
              <?php if ($j['priority'] === 'urgent'): ?><?= badge('urgent', 'red') ?><?php endif; ?>
            </td>
            <td class="small"><?= h($j['assigned_name'] ?: '—') ?></td>
            <td class="small nowrap"><?= h(fmt_date($j['due_date'])) ?></td>
            <td><?= status_badge($j['status']) ?></td>
            <td class="nowrap">
              <a class="btn btn-sm btn-teal" href="<?= h(app_url('entry.php?job=' . (int)$j['id'])) ?>">
                <?= (int)$j['readings'] > 0 ? 'Review' : 'Enter' ?>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
