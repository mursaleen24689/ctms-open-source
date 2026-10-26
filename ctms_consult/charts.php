<?php
declare(strict_types=1);
/**
 * Chart builder.
 *
 * A chart is bound to stored readings, not drawn by hand. So when a value is
 * corrected the graph follows, and a chart printed in five years' time is drawn
 * from the same numbers as the table beside it.
 *
 * Like images, a chart is stored as a SPECIFICATION (which measurements, which
 * rows, what limits) rather than as a picture, so it stays sharp at any size.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/engine.php';
require_once __DIR__ . '/inc/chart.php';

$u = require_login();

$reportId = (int)(get('report') ?? post('report', 0));
$assetId  = (int)(get('id') ?? post('id', 0));

$st = $pdo->prepare('SELECT * FROM c_reports WHERE id = ?');
$st->execute([$reportId]);
$rep = $st->fetch();
if (!$rep) {
    flash('That report does not exist.', 'err');
    redirect('reports.php');
}
$back = 'reports.php?action=build&id=' . $reportId;

if (($rep['status'] ?? '') === 'issued') {
    flash('This report has been issued, so its charts are locked. Raise a revision to change them.', 'err');
    redirect($back);
}

// which tests are on this report, and what can be plotted from them
$st = $pdo->prepare(
    'SELECT DISTINCT j.id AS job_id, tt.name AS test_name, tt.id AS test_type_id
       FROM c_report_sections rs
       JOIN c_test_jobs  j  ON j.id = rs.job_id
       JOIN c_test_types tt ON tt.id = j.test_type_id
      WHERE rs.report_id = ? AND rs.is_included = 1
      ORDER BY tt.sort_order');
$st->execute([$reportId]);
$jobs = $st->fetchAll();

// the measurement codes actually present in those jobs
$codes = [];
if ($jobs) {
    $ids = implode(',', array_map(fn($j) => (int)$j['job_id'], $jobs));
    foreach ($pdo->query(
        "SELECT DISTINCT m.code, m.name, m.canonical_unit, COUNT(*) AS n
           FROM c_readings r JOIN c_measurements m ON m.id = r.measurement_id
          WHERE r.job_id IN ($ids) AND r.is_current = 1 AND r.value_num IS NOT NULL
          GROUP BY m.code, m.name, m.canonical_unit
          ORDER BY m.sort_order") as $r) {
        $codes[$r['code']] = $r;
    }
}

// ── loading an existing chart ────────────────────────────────────────────────
$existing = null;
if ($assetId) {
    $st = $pdo->prepare('SELECT * FROM c_report_assets WHERE id = ? AND report_id = ?');
    $st->execute([$assetId, $reportId]);
    $existing = $st->fetch() ?: null;
    if ($existing && !empty($existing['chart_spec_json'])) {
        $existing['spec'] = json_decode((string)$existing['chart_spec_json'], true) ?: [];
    }
}

// ── save ─────────────────────────────────────────────────────────────────────
if (is_post() && post('do') === 'save') {
    $type   = (string)post('type', 'line');
    if (!in_array($type, ['line','scatter','bar','control','depth'], true)) $type = 'line';
    $xCode  = (string)post('x_code', 'ROW');
    $yCode  = (string)post('y_code', '');
    $group  = (string)post('group_by', 'piece');
    if (!in_array($group, ['piece','job','none'], true)) $group = 'piece';
    $title  = trim((string)post('title', ''));
    $wMm    = max(40, min(200, (float)post('width_mm', 110)));
    $hMm    = max(35, min(150, (float)post('height_mm', 70)));
    $lsl    = dec_or_null(post('lsl'));
    $usl    = dec_or_null(post('usl'));
    $target = dec_or_null(post('target'));

    // only allow jobs that really are on this report
    $allowed = array_map(fn($j) => (int)$j['job_id'], $jobs);
    $jobIds  = array_values(array_intersect(array_map('intval', (array)post('job_ids', [])), $allowed));

    if ($jobIds === []) { $jobIds = $allowed; }
    if ($yCode === '') {
        flash('Choose what to plot on the value axis.', 'err');
        redirect('charts.php?report=' . $reportId . ($assetId ? '&id=' . $assetId : ''));
    }

    [$xLab, $xUnit] = chart_measure_label($xCode);
    [$yLab, $yUnit] = chart_measure_label($yCode);

    $spec = [
        'type' => $type, 'title' => $title,
        'x_code' => $xCode, 'y_code' => $yCode, 'group_by' => $group,
        'job_ids' => $jobIds,
        'x_label' => trim((string)post('x_label', '')) ?: $xLab,
        'y_label' => trim((string)post('y_label', '')) ?: $yLab,
        'x_unit'  => $xUnit, 'y_unit' => $yUnit,
        'width_mm' => $wMm, 'height_mm' => $hMm,
    ];
    $limits = [];
    if ($lsl !== null) $limits['lsl'] = (float)$lsl;
    if ($usl !== null) $limits['usl'] = (float)$usl;
    if ($target !== null) $limits['target'] = (float)$target;
    if ($limits) $spec['limits'] = $limits;

    $pdo->beginTransaction();
    try {
        if ($existing) {
            $pdo->prepare('UPDATE c_report_assets SET chart_spec_json = ?, caption = ?, display_align = ? WHERE id = ?')
                ->execute([json_encode($spec, JSON_UNESCAPED_UNICODE),
                           $title !== '' ? $title : null,
                           in_array((string)post('display_align', 'center'), ['left','center','right'], true)
                               ? (string)post('display_align', 'center') : 'center',
                           (int)$existing['id']]);
        } else {
            $next = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM c_report_assets
                                       WHERE report_id = ' . (int)$reportId)->fetchColumn();
            $pdo->prepare('INSERT INTO c_report_assets
                (report_id, sample_id, asset_type, caption, display_size, display_align,
                 sort_order, uploaded_by, chart_spec_json)
                VALUES (?,?, "instrument_export", ?, "custom", "center", ?, ?, ?)')
                ->execute([$reportId, $rep['sample_id'],
                           $title !== '' ? $title : 'Chart',
                           $next, $u['id'], json_encode($spec, JSON_UNESCAPED_UNICODE)]);
        }
        $newId = $existing ? (int)$existing['id'] : (int)$pdo->lastInsertId();
        audit($existing ? 'CHART_UPDATED' : 'CHART_ADDED', 'reports', $newId,
              null, ['type' => $type, 'y' => $yCode, 'x' => $xCode, 'jobs' => count($jobIds)],
              null, $reportId);
        $pdo->commit();
        flash('Chart saved to the report. Each bar or point is drawn from the stored readings.', 'ok');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('Could not save the chart: ' . h($e->getMessage()), 'err');
    }
    redirect($back);
}

if (is_post() && post('do') === 'delete' && $existing) {
    $pdo->prepare('DELETE FROM c_report_assets WHERE id = ?')->execute([(int)$existing['id']]);
    audit('CHART_REMOVED', 'reports', (int)$existing['id'], ['caption' => $existing['caption']], null, null, $reportId);
    flash('Chart removed from the report.', 'warn');
    redirect($back);
}

// ── what the form should show ────────────────────────────────────────────────
$spec = $existing['spec'] ?? [];
$selType = (string)($spec['type'] ?? post('type', 'line'));
$selX    = (string)($spec['x_code'] ?? post('x_code', 'ROW'));
$selY    = (string)($spec['y_code'] ?? post('y_code', ''));
$selGrp  = (string)($spec['group_by'] ?? post('group_by', 'piece'));
$selJobs = $spec['job_ids'] ?? array_map(fn($j) => (int)$j['job_id'], $jobs);

// a live preview, computed from the current selection
$prevJobIds = array_values(array_intersect(
    array_map('intval', (array)(get('jobs') ? explode(',', (string)get('jobs')) : $selJobs)),
    array_map(fn($j) => (int)$j['job_id'], $jobs)));
if (!$prevJobIds) $prevJobIds = array_map(fn($j) => (int)$j['job_id'], $jobs);

$previewY = (string)(get('y') ?: $selY);
$previewX = (string)(get('x') ?: $selX);
$previewSeries = [];
if ($previewY !== '' && $prevJobIds) {
    $previewSeries = chart_build_series([
        'job_ids' => $prevJobIds, 'x_code' => $previewX, 'y_code' => $previewY,
        'group_by' => (string)(get('group') ?: $selGrp),
        'y_reference' => get('ref') === '1',
    ]);
}

$PAGE_TITLE = $existing ? 'Edit chart' : 'Add a chart';
$PAGE_SUB   = $rep['report_number'] . ' · ' . ($rep['company_name'] ?? '');
include __DIR__ . '/inc/layout_top.php';
?>

<?php if (!$jobs): ?>
  <div class="card"><div class="empty">
    This report has no test sections yet, so there is nothing to plot.
    <a href="<?= h($back) ?>">Back to the report</a>
  </div></div>
<?php else: ?>

<div class="grid" style="grid-template-columns:minmax(360px,1fr) minmax(420px,1.2fr);gap:16px">

  <div>
    <form method="post" id="chartForm">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <input type="hidden" name="report" value="<?= $reportId ?>">
      <input type="hidden" name="id" value="<?= $assetId ?>">
      <input type="hidden" name="job_ids[]" value="">

      <div class="card">
        <h2>What to plot</h2>
        <div class="row r2">
          <div class="field">
            <label for="y_code">Value axis *</label>
            <select id="y_code" name="y_code" required>
              <option value="">choose a measurement…</option>
              <?php foreach ($codes as $c => $m): ?>
                <option value="<?= h($c) ?>" <?= $selY === (string)$c ? 'selected' : '' ?>>
                  <?= h($m['name']) ?><?= $m['canonical_unit'] ? ' (' . h($m['canonical_unit']) . ')' : '' ?>
                  — <?= (int)$m['n'] ?> value(s)
                </option>
              <?php endforeach; ?>
            </select>
            <div class="hint">Only measurements that actually have values recorded are listed.</div>
          </div>
          <div class="field">
            <label for="x_code">Along the bottom</label>
            <select id="x_code" name="x_code">
              <option value="ROW" <?= $selX === 'ROW' ? 'selected' : '' ?>>Reading number (1, 2, 3 …)</option>
              <?php foreach ($codes as $c => $m): ?>
                <option value="<?= h($c) ?>" <?= $selX === (string)$c ? 'selected' : '' ?>>
                  <?= h($m['name']) ?><?= $m['canonical_unit'] ? ' (' . h($m['canonical_unit']) . ')' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="hint">For a case-depth profile choose the distance from the surface.</div>
          </div>
        </div>

        <div class="row r2">
          <div class="field">
            <label for="type">Chart type</label>
            <select id="type" name="type">
              <?php foreach ([
                  'line'    => 'Line — a trend, e.g. hardness against depth',
                  'scatter' => 'Scatter — separate readings against each other',
                  'bar'     => 'Bar — one reading per sample',
                  'control' => 'Control chart — with upper and lower limits',
                  'depth'   => 'Depth profile — one line per piece, values labelled',
              ] as $k => $lab): ?>
                <option value="<?= h($k) ?>" <?= $selType === $k ? 'selected' : '' ?>><?= h($lab) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="group_by">One line per</label>
            <select id="group_by" name="group_by">
              <option value="piece" <?= $selGrp === 'piece' ? 'selected' : '' ?>>Piece (recommended)</option>
              <option value="job"   <?= $selGrp === 'job' ? 'selected' : '' ?>>Test</option>
              <option value="none"  <?= $selGrp === 'none' ? 'selected' : '' ?>>Everything in one series</option>
            </select>
          </div>
        </div>

        <div class="field">
          <label for="title">Chart title</label>
          <input id="title" name="title" type="text" value="<?= h($spec['title'] ?? '') ?>"
                 placeholder="e.g. Case depth profile — Sample 02">
        </div>
      </div>

      <div class="card">
        <h2>Limits (optional)</h2>
        <p class="small muted" style="margin-bottom:10px">
          Draw the acceptance or control limits on the chart. They are marked as dashed lines
          and labelled, so a reader can see a value fall outside them.
        </p>
        <div class="row r3">
          <div class="field">
            <label for="lsl">Lower limit</label>
            <input id="lsl" name="lsl" type="number" step="any" value="<?= h($spec['limits']['lsl'] ?? '') ?>">
          </div>
          <div class="field">
            <label for="usl">Upper limit</label>
            <input id="usl" name="usl" type="number" step="any" value="<?= h($spec['limits']['usl'] ?? '') ?>">
          </div>
          <div class="field">
            <label for="target">Target</label>
            <input id="target" name="target" type="number" step="any" value="<?= h($spec['limits']['target'] ?? '') ?>">
          </div>
        </div>
      </div>

      <div class="card">
        <h2>Printed size</h2>
        <div class="row r3">
          <div class="field">
            <label for="width_mm">Width (mm)</label>
            <input id="width_mm" name="width_mm" type="number" min="40" max="200" step="5"
                   value="<?= h($spec['width_mm'] ?? 110) ?>">
          </div>
          <div class="field">
            <label for="height_mm">Height (mm)</label>
            <input id="height_mm" name="height_mm" type="number" min="35" max="150" step="5"
                   value="<?= h($spec['height_mm'] ?? 70) ?>">
          </div>
          <div class="field">
            <label for="display_align">Alignment</label>
            <select id="display_align" name="display_align">
              <?php foreach (['left'=>'Left','center'=>'Centre','right'=>'Right'] as $k => $lab): ?>
                <option value="<?= h($k) ?>" <?= ($existing['display_align'] ?? 'center') === $k ? 'selected' : '' ?>>
                  <?= h($lab) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="field">
          <label>Which tests feed this chart</label>
          <?php foreach ($jobs as $j): ?>
            <label style="display:flex;gap:7px;align-items:center;text-transform:none;letter-spacing:0;
                          font-weight:400;font-size:12.5px;margin-bottom:4px">
              <input type="checkbox" name="job_ids[]" value="<?= (int)$j['job_id'] ?>" style="width:auto"
                     <?= in_array((int)$j['job_id'], $selJobs, true) ? 'checked' : '' ?>>
              <?= h($j['test_name']) ?>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="toolbar" style="margin:0">
          <button class="btn btn-copper" type="submit"><?= $existing ? 'Save changes' : 'Add chart to report' ?></button>
          <a class="btn btn-ghost" href="<?= h($back) ?>">Back to the report</a>
        </div>
      </div>
    </form>

    <?php if ($existing): ?>
      <form method="post" onsubmit="return confirm('Remove this chart from the report?');">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="delete">
        <input type="hidden" name="report" value="<?= $reportId ?>">
        <input type="hidden" name="id" value="<?= $assetId ?>">
        <button class="btn btn-danger" type="submit">Remove this chart</button>
      </form>
    <?php endif; ?>
  </div>

  <div>
    <div class="card">
      <h2>Preview</h2>
      <p class="small muted" style="margin-bottom:10px">
        Drawn from the stored readings, exactly as it will print.
        <?php if (!$previewY): ?>Choose a value axis to see it.<?php endif; ?>
      </p>
      <div id="chartPreview" aria-live="polite" style="text-align:center;background:#fff;border:1px solid var(--border);border-radius:8px;padding:12px">
        <?php
        if ($previewY !== '' && $previewSeries) {
            $pv = get('preview');
            [$yl, $yu] = chart_measure_label($previewY);
            echo chart_svg([
                'type' => (string)(get('type') ?: $selType),
                'title' => trim((string)(get('title') ?: ($spec['title'] ?? ''))),
                'x_label' => chart_measure_label($previewX)[0],
                'y_label' => $yl,
                'x_unit' => chart_measure_label($previewX)[1],
                'y_unit' => $yu,
                'series' => $previewSeries,
                'limits' => array_filter([
                    'lsl' => dec_or_null(get('lsl') ?? ($spec['limits']['lsl'] ?? null)),
                    'usl' => dec_or_null(get('usl') ?? ($spec['limits']['usl'] ?? null)),
                    'target' => dec_or_null(get('target') ?? ($spec['limits']['target'] ?? null)),
                ], fn($v) => $v !== null),
                'width_mm' => (float)(get('width_mm') ?: ($spec['width_mm'] ?? 110)),
                'height_mm' => (float)(get('height_mm') ?: ($spec['height_mm'] ?? 70)),
            ]);
        } elseif ($previewY !== '') {
            echo '<div class="empty">No values found for that measurement in the chosen tests.</div>';
        } else {
            echo '<div class="empty">The chart appears here.</div>';
        }
        ?>
      </div>
      <?php if ($previewSeries): ?>
        <p class="small muted" style="margin-top:10px">
          <?= count($previewSeries) ?> series,
          <?= array_sum(array_map(fn($s) => count($s['points']), $previewSeries)) ?> plotted value(s).
        </p>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>What is available to plot</h2>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Measurement</th><th>Stored in</th><th class="right">Values</th></tr></thead>
          <tbody>
          <?php foreach ($codes as $c => $m): ?>
            <tr>
              <td class="mono small"><?= h($c) ?><div class="muted"><?= h($m['name']) ?></div></td>
              <td class="small"><?= h($m['canonical_unit'] ?: '—') ?></td>
              <td class="num"><?= (int)$m['n'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const form = document.getElementById('chartForm');
  const pane = document.getElementById('chartPreview');
  if (!form || !pane) return;
  let timer;
  const refreshPreview = async () => {
    const data = new FormData(form);
    const params = new URLSearchParams({
      report: data.get('report') || '', id: data.get('id') || '',
      x: data.get('x_code') || 'ROW', y: data.get('y_code') || '',
      type: data.get('type') || 'line', group: data.get('group_by') || 'piece',
      title: data.get('title') || '', lsl: data.get('lsl') || '',
      usl: data.get('usl') || '', target: data.get('target') || '',
      width_mm: data.get('width_mm') || '110', height_mm: data.get('height_mm') || '70',
      jobs: data.getAll('job_ids[]').filter(Boolean).join(',')
    });
    pane.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(location.pathname + '?' + params.toString(), {
        credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}
      });
      if (!response.ok) throw new Error('Preview request failed');
      const html = await response.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const updated = doc.getElementById('chartPreview');
      if (updated) pane.innerHTML = updated.innerHTML;
    } catch (_) {
      pane.innerHTML = '<div class="empty">Preview could not refresh. Check your connection and change a chart option to try again.</div>';
    } finally { pane.removeAttribute('aria-busy'); }
  };
  form.querySelectorAll('select, input').forEach((control) => {
    control.addEventListener('change', () => { clearTimeout(timer); timer = setTimeout(refreshPreview, 180); });
    if (control.matches('input[type="text"], input[type="number"]')) {
      control.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(refreshPreview, 350); });
    }
  });
})();
</script>

<?php endif; ?>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
