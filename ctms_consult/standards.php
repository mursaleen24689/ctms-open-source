<?php
declare(strict_types=1);
/**
 * Standards library — the laboratory's reference shelf, and the source of
 * every suggestion and check the system makes.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/engine.php';

require_login();

$view     = (string)get('view', 'standards');
$search   = trim((string)get('q', ''));
$stdId    = (int)get('id', 0);

$PAGE_TITLE = 'Standards library';
$PAGE_SUB   = 'What the system knows, and where its suggestions come from';
include __DIR__ . '/inc/layout_top.php';
?>

<div class="card">
  <div class="toolbar" style="margin:0">
    <div style="display:flex;border-radius:8px;overflow:hidden;border:1px solid var(--border)">
      <?php foreach (['standards' => 'Standards', 'tests' => 'Test types',
                      'measurements' => 'Measurements', 'grades' => 'Material grades',
                      'suggestions' => 'Test suggestions'] as $k => $lab): ?>
        <a class="btn btn-sm <?= $view === $k ? '' : 'btn-ghost' ?>" style="border-radius:0"
           href="<?= h(app_url('standards.php?view=' . $k)) ?>"><?= h($lab) ?></a>
      <?php endforeach; ?>
    </div>
    <form method="get" style="display:flex;gap:8px;margin-left:auto">
      <input type="hidden" name="view" value="<?= h($view) ?>">
      <input type="text" name="q" value="<?= h($search) ?>" placeholder="search" style="width:220px">
      <button class="btn btn-ghost" type="submit">Search</button>
    </form>
  </div>
</div>

<?php // ─────────────────────────────── STANDARDS ─────────────────────────────── ?>
<?php if ($view === 'standards'): ?>
  <?php
  $sql = 'SELECT s.*, tt.name AS used_by FROM c_standards s
          LEFT JOIN c_test_types tt ON tt.default_standard_id = s.id WHERE s.is_active = 1';
  $params = [];
  if ($search !== '') { $sql .= ' AND (s.code LIKE ? OR s.title LIKE ? OR s.scope_summary LIKE ?)';
                        array_push($params, "%$search%", "%$search%", "%$search%"); }
  $sql .= ' ORDER BY s.body, s.code';
  $st = $pdo->prepare($sql); $st->execute($params);
  $rows = $st->fetchAll();
  ?>
  <div class="card">
    <h2><?= count($rows) ?> standard(s)</h2>
    <p class="small muted" style="margin-bottom:10px">
      Revision years are shown where they are known. Where a year is blank the edition was not
      recorded — the laboratory should confirm it before a report cites it.
    </p>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Code</th><th>Revision</th><th>Title and scope</th><th>Used by</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="mono nowrap"><?= h($r['code']) ?></td>
            <td class="small nowrap"><?= h($r['revision'] ?: '—') ?>
              <?php if (!$r['revision']): ?><br><span class="muted" style="font-size:10px">confirm edition</span><?php endif; ?>
            </td>
            <td>
              <strong><?= h($r['title']) ?></strong>
              <?php if ($r['scope_summary']): ?>
                <div class="small muted" style="margin-top:3px"><?= h($r['scope_summary']) ?></div>
              <?php endif; ?>
            </td>
            <td class="small"><?= h($r['used_by'] ?: '—') ?><div class="muted"><?= h($r['body']) ?></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php // ─────────────────────────────── TEST TYPES ─────────────────────────────── ?>
<?php if ($view === 'tests'): ?>
  <?php
  $rows = $pdo->query(
      'SELECT tt.*, s.code AS std_code, s.title AS std_title,
              (SELECT COUNT(*) FROM c_test_measurements tm WHERE tm.test_type_id = tt.id) AS measures,
              (SELECT COUNT(*) FROM c_test_jobs j WHERE j.test_type_id = tt.id) AS jobs
         FROM c_test_types tt
         LEFT JOIN c_standards s ON s.id = tt.default_standard_id
        WHERE tt.is_active = 1 ORDER BY tt.sort_order, tt.name')->fetchAll();
  ?>
  <div class="card">
    <h2><?= count($rows) ?> test type(s)</h2>
    <p class="small muted" style="margin-bottom:10px">
      Each test type defines the shape of its results table and which measurements it produces.
      Adding a new test type later needs no programming.
    </p>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Test</th><th>Category</th><th>Results table shape</th>
                   <th class="right">Measurements</th><th class="right">Jobs done</th>
                   <th>Suggested standard</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><strong><?= h($r['name']) ?></strong><div class="small muted mono"><?= h($r['code']) ?></div></td>
            <td class="small"><?= h(ucfirst($r['category'])) ?></td>
            <td class="small"><?= h(str_replace('_', ' ', $r['result_style'])) ?>
              <div class="muted"><?= h($r['report_title'] ?: '—') ?></div></td>
            <td class="num"><?= (int)$r['measures'] ?></td>
            <td class="num"><?= (int)$r['jobs'] ?></td>
            <td class="small">
              <?php if ($r['std_code']): ?>
                <span class="mono"><?= h($r['std_code']) ?></span>
                <div class="muted"><?= h(mb_strimwidth((string)$r['std_title'], 0, 50, '…')) ?></div>
              <?php else: ?><span class="muted">none suggested</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php // ───────────────────────────── MEASUREMENTS ───────────────────────────── ?>
<?php if ($view === 'measurements'): ?>
  <?php
  $sql = 'SELECT m.*, (SELECT COUNT(*) FROM c_readings r WHERE r.measurement_id = m.id AND r.is_current = 1) AS uses
            FROM c_measurements m WHERE 1=1';
  $params = [];
  if ($search !== '') { $sql .= ' AND (m.code LIKE ? OR m.name LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
  $sql .= ' ORDER BY m.sort_order, m.code';
  $st = $pdo->prepare($sql); $st->execute($params);
  $rows = $st->fetchAll();
  ?>
  <div class="card">
    <h2><?= count($rows) ?> measurement(s)</h2>
    <p class="small muted" style="margin-bottom:10px">
      Every reading in the system points at one of these. This is what makes a value comparable
      across years and reports — a number without a definition is just a number.
    </p>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Code</th><th>Measurement</th><th>Stored in</th><th>Accepted units</th>
                   <th>Kind</th><th class="right">Values recorded</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
          $units = json_decode($r['accepted_units'] ?? '{}', true) ?: [];
        ?>
          <tr>
            <td class="mono nowrap"><?= h($r['code']) ?></td>
            <td><?= h($r['name']) ?><?= $r['symbol'] ? ' <span class="muted mono">' . h($r['symbol']) . '</span>' : '' ?></td>
            <td class="mono"><?= h($r['canonical_unit'] ?: '—') ?></td>
            <td class="small">
              <?php if ($units): foreach ($units as $un => $f): ?>
                <span class="pill"><?= h($un) ?><?= (float)$f != 1 ? ' ×' . h((string)(float)$f) : '' ?></span>
              <?php endforeach; else: ?><span class="muted">free text</span><?php endif; ?>
            </td>
            <td class="small"><?= h(str_replace('_', ' ', $r['value_kind'])) ?></td>
            <td class="num"><?= (int)$r['uses'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php // ───────────────────────────── GRADES ───────────────────────────── ?>
<?php if ($view === 'grades'): ?>
  <?php
  $rows = $pdo->query(
      'SELECT gl.grade_code, gl.grade_name, gl.material_group, gl.min_value, gl.max_value,
              gl.requirement_text, m.name AS m_name, m.code AS m_code, m.canonical_unit,
              s.code AS std_code
         FROM c_grade_limits gl
         LEFT JOIN c_measurements m ON m.id = gl.measurement_id
         LEFT JOIN c_standards s ON s.id = gl.standard_id
        ORDER BY gl.grade_code, m.sort_order')->fetchAll();
  $grouped = [];
  foreach ($rows as $r) $grouped[$r['grade_code']][] = $r;
  ?>
  <div class="card">
    <h2><?= count($grouped) ?> material grade(s)</h2>
    <p class="small muted" style="margin-bottom:10px">
      These are the published acceptance limits the system compares against when suggesting
      PASS or FAIL. The suggestion is always for a human to confirm — it is never applied on its own.
    </p>
    <?php foreach ($grouped as $code => $limits): ?>
      <h3><?= h($code) ?> — <?= h($limits[0]['grade_name']) ?>
        <span class="badge badge-grey"><?= h($limits[0]['material_group'] ?: 'unclassified') ?></span></h3>
      <div class="table-wrap" style="margin-bottom:14px">
        <table>
          <thead><tr><th>Measurement</th><th class="right">Minimum</th><th class="right">Maximum</th>
                     <th>Requirement as published</th><th>From</th></tr></thead>
          <tbody>
          <?php foreach ($limits as $l): ?>
            <tr>
              <td><?= h($l['m_name'] ?: '(whole grade)') ?>
                <?= $l['canonical_unit'] ? '<span class="muted small">' . h($l['canonical_unit']) . '</span>' : '' ?></td>
              <td class="num"><?= $l['min_value'] !== null ? h(fmt($l['min_value'], 2)) : '—' ?></td>
              <td class="num"><?= $l['max_value'] !== null ? h(fmt($l['max_value'], 2)) : '—' ?></td>
              <td class="small"><?= h($l['requirement_text'] ?: '—') ?></td>
              <td class="small mono"><?= h($l['std_code'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php // ───────────────────────────── SUGGESTIONS ───────────────────────────── ?>
<?php if ($view === 'suggestions'): ?>
  <?php
  $rows = $pdo->query(
      'SELECT ts.*, tt.name AS test_name, tt.category
         FROM c_test_suggestions ts
         JOIN c_test_types tt ON tt.id = ts.test_type_id
        ORDER BY ts.times_used DESC, ts.keyword, ts.confidence DESC')->fetchAll();
  $grouped = [];
  foreach ($rows as $r) $grouped[$r['keyword']][] = $r;
  ?>
  <div class="card">
    <h2><?= count($grouped) ?> keyword(s)</h2>
    <p class="small muted" style="margin-bottom:10px">
      When a technician describes a sample, these rules propose the tests usually ordered for it.
      The list started from your own 23 consultancy reports and grows as the laboratory uses the system —
      rows marked <span class="badge badge-blue">learned</span> came from real orders.
    </p>
    <div class="table-wrap">
      <table>
        <thead><tr><th style="width:180px">When the sample says…</th><th>Tests proposed</th><th style="width:100px">Source</th></tr></thead>
        <tbody>
        <?php foreach ($grouped as $kw => $items): ?>
          <tr>
            <td><strong><?= h($kw) ?></strong>
              <?php if ($items[0]['material_group']): ?>
                <div class="small muted"><?= h($items[0]['material_group']) ?></div>
              <?php endif; ?></td>
            <td>
              <?php foreach ($items as $it): ?>
                <span class="pill">
                  <?= h($it['test_name']) ?>
                  <span class="muted"><?= (int)round($it['confidence'] * 100) ?>%</span>
                  <?php if ((int)$it['times_used'] > 1): ?><span class="muted">·<?= (int)$it['times_used'] ?>×</span><?php endif; ?>
                </span>
              <?php endforeach; ?>
            </td>
            <td><?= $items[0]['source'] === 'learned' ? badge('learned', 'blue') : badge('seeded', 'grey') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
