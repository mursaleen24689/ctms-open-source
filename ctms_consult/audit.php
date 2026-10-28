<?php
declare(strict_types=1);
/**
 * Audit Trail — every recorded action, newest first.
 *
 * The trail is written by audit() from every page and is never edited or
 * deleted, so this screen is read-only by design: there is nothing here to
 * change, only to look at. Managers use it to answer "who changed this, and
 * when", which is exactly the question a printed report cannot answer.
 */
require_once __DIR__ . '/inc/bootstrap.php';

// Same gate as the menu entry: anyone allowed to see it may look, nobody may alter.
$u = require_login(['superadmin', 'admin', 'professor']);

$action = (string)get('action', 'list');
$id     = (int)get('id', 0);

/** Readable, multi-line rendering of a stored JSON or text value. */
function audit_value($raw): string
{
    if ($raw === null || $raw === '') return '—';
    $raw = (string)$raw;
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return (string)json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return $raw;
}

/** Tone for the action badge, so the eye finds failures quickly. */
function audit_tone(string $a): string
{
    return match (true) {
        str_contains($a, 'FAIL')   => 'red',
        str_contains($a, 'DELETE'),
        str_contains($a, 'CANCEL'),
        str_contains($a, 'REVERS') => 'amber',
        str_starts_with($a, 'LOGIN'),
        str_contains($a, 'SIGNED_IN') => 'blue',
        default => 'grey',
    };
}

// ════════════════════════════════════════════════════════════════════════════
//  ONE EVENT
// ════════════════════════════════════════════════════════════════════════════
$event = null;
if ($action === 'view') {
    $st = $pdo->prepare('SELECT * FROM c_audit WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $event = $st->fetch() ?: null;
    if (!$event) {
        flash('That audit entry does not exist.', 'err');
        redirect('audit.php');
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  THE LIST
// ════════════════════════════════════════════════════════════════════════════
$modules = $pdo->query('SELECT DISTINCT module FROM c_audit ORDER BY module')->fetchAll(PDO::FETCH_COLUMN);

$fModule = (string)get('module', '');
$fActor  = trim((string)get('actor', ''));
$fText   = trim((string)get('q', ''));
$fFrom   = (string)get('from', '');
$fTo     = (string)get('to', '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFrom)) $fFrom = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fTo))   $fTo   = '';

$where  = ['1=1'];
$params = [];
if ($fModule !== '') { $where[] = 'module = ?'; $params[] = $fModule; }
if ($fActor !== '')  { $where[] = 'actor_name LIKE ?'; $params[] = "%$fActor%"; }
if ($fText !== '') {
    $where[] = '(action LIKE ? OR module LIKE ? OR reason LIKE ? OR CAST(record_id AS CHAR) = ?)';
    array_push($params, "%$fText%", "%$fText%", "%$fText%", $fText);
}
if ($fFrom !== '') { $where[] = 'created_at >= ?'; $params[] = $fFrom . ' 00:00:00'; }
if ($fTo !== '')   { $where[] = 'created_at < ?';  $params[] = date('Y-m-d', strtotime($fTo . ' +1 day')) . ' 00:00:00'; }
$whereSql = implode(' AND ', $where);

$st = $pdo->prepare('SELECT COUNT(*) FROM c_audit WHERE ' . $whereSql);
$st->execute($params);
$total = (int)$st->fetchColumn();

$perPage = 60;
$pages   = max(1, (int)ceil($total / $perPage));
$page    = min(max(1, (int)get('page', 1)), $pages);
$offset  = ($page - 1) * $perPage;

$st = $pdo->prepare('SELECT * FROM c_audit WHERE ' . $whereSql . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$st->execute($params);
$rows = $st->fetchAll();

/** Keep the current filters when moving between pages or opening an entry. */
function audit_qs(array $over = []): string
{
    $qs = array_filter(array_merge([
        'module' => get('module', ''),
        'actor'  => get('actor', ''),
        'q'      => get('q', ''),
        'from'   => get('from', ''),
        'to'     => get('to', ''),
        'page'   => get('page', ''),
    ], $over), static fn($v) => $v !== '' && $v !== null);
    return $qs ? '&' . http_build_query($qs) : '';
}

$todayCount = (int)$pdo->query('SELECT COUNT(*) FROM c_audit WHERE created_at >= CURDATE()')->fetchColumn();
$peopleCount = (int)$pdo->query('SELECT COUNT(DISTINCT actor_name) FROM c_audit')->fetchColumn();

$PAGE_TITLE = $event ? 'Audit entry #' . (int)$event['id'] : 'Audit Trail';
$PAGE_SUB   = 'Every recorded action, in the order it happened — read-only';
include __DIR__ . '/inc/layout_top.php';
?>

<?php if ($event): ?>

  <?php
    $back = 'audit.php' . (audit_qs() !== '' ? '?' . ltrim(audit_qs(), '&') : '');
  ?>
  <div class="card">
    <div class="toolbar" style="margin:0">
      <a class="btn btn-ghost" href="<?= h(app_url($back)) ?>">&larr; Back to the trail</a>
      <span class="small muted" style="margin-left:auto">Entry <?= (int)$event['id'] ?></span>
    </div>
  </div>

  <div class="card">
    <h2><?= badge((string)$event['action'], audit_tone((string)$event['action'])) ?> in <?= h((string)$event['module']) ?></h2>
    <div class="table-wrap">
      <table>
        <tbody>
          <tr><th style="width:180px">When</th><td class="mono"><?= h((string)$event['created_at']) ?></td></tr>
          <tr><th>Who</th><td><?= h((string)$event['actor_name']) ?> <span class="small muted">(<?= h((string)$event['actor_role']) ?><?= $event['user_id'] ? ' · account #' . (int)$event['user_id'] : '' ?>)</span></td></tr>
          <tr><th>Action</th><td class="mono"><?= h((string)$event['action']) ?></td></tr>
          <tr><th>Module</th><td><?= h((string)$event['module']) ?></td></tr>
          <tr><th>Record</th><td class="mono"><?= $event['record_id'] !== null ? (int)$event['record_id'] : '—' ?><?= $event['parent_id'] !== null ? ' <span class="small muted">(under #' . (int)$event['parent_id'] . ')</span>' : '' ?></td></tr>
          <tr><th>Reason</th><td><?= $event['reason'] !== null && $event['reason'] !== '' ? h((string)$event['reason']) : '<span class="muted">—</span>' ?></td></tr>
          <tr><th>From</th><td class="small"><?= h((string)($event['ip_address'] ?? '—')) ?></td></tr>
          <tr><th>Browser</th><td class="small"><?= h((string)($event['user_agent'] ?? '—')) ?></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="grid g2">
    <div class="card">
      <h2>Before</h2>
      <pre style="white-space:pre-wrap;word-break:break-word;font-family:var(--mono);font-size:11.5px;
                  background:var(--paper);border:1px solid var(--border);border-radius:7px;padding:10px;margin:0"><?= h(audit_value($event['old_value'])) ?></pre>
    </div>
    <div class="card">
      <h2>After</h2>
      <pre style="white-space:pre-wrap;word-break:break-word;font-family:var(--mono);font-size:11.5px;
                  background:var(--paper);border:1px solid var(--border);border-radius:7px;padding:10px;margin:0"><?= h(audit_value($event['new_value'])) ?></pre>
    </div>
  </div>

<?php else: ?>

  <div class="grid g3" style="margin-bottom:16px">
    <div class="kpi"><div class="l">All entries</div><div class="v"><?= $total ?></div><div class="s">matching the filters below</div></div>
    <div class="kpi" style="border-left-color:var(--blue)"><div class="l">Today</div><div class="v"><?= $todayCount ?></div><div class="s">recorded since midnight</div></div>
    <div class="kpi" style="border-left-color:var(--teal)"><div class="l">People</div><div class="v"><?= $peopleCount ?></div><div class="s">who have an entry</div></div>
  </div>

  <div class="card">
    <form method="get" class="toolbar" style="margin:0">
      <select name="module" style="width:170px">
        <option value="">All modules</option>
        <?php foreach ($modules as $m): ?>
          <option value="<?= h((string)$m) ?>" <?= $fModule === (string)$m ? 'selected' : '' ?>><?= h((string)$m) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="actor" value="<?= h($fActor) ?>" placeholder="Person" style="width:170px">
      <input type="text" name="q" value="<?= h($fText) ?>" placeholder="Action, module, reason or record no." style="width:260px">
      <label for="from" class="small muted" style="margin:0;text-transform:none;letter-spacing:0;font-weight:400">From</label>
      <input id="from" type="date" name="from" value="<?= h($fFrom) ?>" style="width:150px">
      <label for="to" class="small muted" style="margin:0;text-transform:none;letter-spacing:0;font-weight:400">To</label>
      <input id="to" type="date" name="to" value="<?= h($fTo) ?>" style="width:150px">
      <button class="btn btn-ghost" type="submit">Filter</button>
      <?php if ($fModule !== '' || $fActor !== '' || $fText !== '' || $fFrom !== '' || $fTo !== ''): ?>
        <a class="btn btn-sm btn-ghost" href="<?= h(app_url('audit.php')) ?>">Clear</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="card">
    <h2><?= $total ?> entry(s)<?= $pages > 1 ? ' — page ' . $page . ' of ' . $pages : '' ?></h2>
    <?php if (!$rows): ?>
      <div class="empty">Nothing matches those filters.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Module</th>
                     <th class="right">Record</th><th>Reason</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="small nowrap mono"><?= h((string)$r['created_at']) ?></td>
              <td class="small"><?= h((string)$r['actor_name']) ?><div class="muted" style="font-size:10.5px"><?= h((string)$r['actor_role']) ?></div></td>
              <td><?= badge((string)$r['action'], audit_tone((string)$r['action'])) ?></td>
              <td class="small"><?= h((string)$r['module']) ?></td>
              <td class="num"><?= $r['record_id'] !== null ? (int)$r['record_id'] : '—' ?></td>
              <td class="small"><?= $r['reason'] !== null && $r['reason'] !== ''
                    ? h(mb_strimwidth((string)$r['reason'], 0, 60, '…'))
                    : '<span class="muted">—</span>' ?></td>
              <td class="nowrap">
                <a class="btn btn-sm btn-ghost" href="<?= h(app_url('audit.php?action=view&id=' . (int)$r['id'] . audit_qs())) ?>">Open</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($pages > 1): ?>
        <div class="toolbar" style="margin:14px 0 0;justify-content:center">
          <?php if ($page > 1): ?>
            <a class="btn btn-sm btn-ghost" href="<?= h(app_url('audit.php?' . ltrim(audit_qs(['page' => $page - 1]), '&'))) ?>">&larr; Newer</a>
          <?php endif; ?>
          <span class="small muted">page <?= $page ?> of <?= $pages ?></span>
          <?php if ($page < $pages): ?>
            <a class="btn btn-sm btn-ghost" href="<?= h(app_url('audit.php?' . ltrim(audit_qs(['page' => $page + 1]), '&'))) ?>">Older &rarr;</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

<?php endif; ?>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
