<?php
declare(strict_types=1);
/**
 * Charts, drawn as SVG on the server.
 *
 * No charting library and no JavaScript. That matters for a laboratory report:
 *   - the chart is part of the stored document, not something the browser draws
 *   - it cannot fail to appear because a script did not load
 *   - it renders identically in the preview and in the PDF
 *   - it regenerates from the stored readings, so correcting a value corrects the graph
 *
 * A chart is never stored as a picture. It is stored as a specification ("plot
 * UTS against elongation, grouped by piece"), so it can be redrawn at any size
 * and stays sharp in print.
 */


/**
 * Nice round axis bounds around a set of values.
 * Returns [min, max, step].
 */
function chart_scale(float $lo, float $hi, int $ticks = 5): array {
    if (!is_finite($lo) || !is_finite($hi)) { $lo = 0; $hi = 1; }
    if ($lo === $hi) { $lo -= 1; $hi += 1; }
    if ($lo > $hi) { [$lo, $hi] = [$hi, $lo]; }

    $span = $hi - $lo;
    $rawStep = $span / max(2, $ticks);
    $mag = pow(10, floor(log10($rawStep)));
    $norm = $rawStep / $mag;
    $step = ($norm <= 1 ? 1 : ($norm <= 2 ? 2 : ($norm <= 5 ? 5 : 10))) * $mag;

    $min = floor($lo / $step) * $step;
    $max = ceil($hi / $step) * $step;
    if ($max === $min) $max = $min + $step;

    return [$min, $max, $step];
}

function chart_num(float $v, int $dp = 2): string {
    if (!is_finite($v)) return '';
    $s = number_format($v, $dp, '.', '');
    return rtrim(rtrim($s, '0'), '.') ?: '0';
}

/** Escape for SVG text. */
function chart_esc(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Draw a chart.
 *
 * $spec = [
 *   'type'     => line | scatter | bar | control | depth
 *   'title'    => string
 *   'x_label'  => string, 'y_label' => string
 *   'x_unit'   => string, 'y_unit'  => string
 *   'series'   => [ ['name'=>..., 'points'=>[['x'=>float,'y'=>float,'label'=>...], ...]], ... ]
 *   'limits'   => ['lsl'=>?, 'usl'=>?, 'target'=>?]   (control charts and spec lines)
 *   'width_mm' => float, 'height_mm' => float
 * ]
 *
 * Returns inline SVG. Width and height are in millimetres so it prints at a
 * known size and cannot overflow the page.
 */
function chart_svg(array $spec): string {
    $wMm = max(40.0, min(200.0, (float)($spec['width_mm'] ?? 110)));
    $hMm = max(35.0, min(150.0, (float)($spec['height_mm'] ?? 70)));
    $type = (string)($spec['type'] ?? 'line');
    $series = $spec['series'] ?? [];
    if (!is_array($series)) $series = [];

    // padding in mm inside the plot frame
    $padL = 18.0; $padR = 4.0; $padT = 7.0; $padB = 14.0;
    $plotW = $wMm - $padL - $padR;
    $plotH = $hMm - $padT - $padB;
    if ($plotW < 10 || $plotH < 10) { $padL = 14; $padB = 12; $plotW = $wMm - $padL - 4; $plotH = $hMm - 6 - $padB; }

    // gather the data range
    $xs = []; $ys = [];
    foreach ($series as $sr) {
        foreach (($sr['points'] ?? []) as $p) {
            if (!is_array($p)) continue;
            if (isset($p['x']) && is_numeric($p['x'])) $xs[] = (float)$p['x'];
            if (isset($p['y']) && is_numeric($p['y'])) $ys[] = (float)$p['y'];
        }
    }
    foreach (['lsl','usl','target'] as $k) {
        if (isset($spec['limits'][$k]) && is_numeric($spec['limits'][$k])) $ys[] = (float)$spec['limits'][$k];
    }

    $empty = (!$xs && !$ys);
    if ($empty) { $xs = [0, 1]; $ys = [0, 1]; }

    [$xMin, $xMax, $xStep] = chart_scale(min($xs), max($xs), 5);
    // a bar chart should start at zero on the value axis, as readers expect
    if ($type === 'bar') { $yMin = 0.0; } else { $yMin = chart_scale(min($ys), max($ys), 5)[0]; }
    [$y0, $y1, $yStep] = chart_scale(min(array_merge($ys, [$yMin])), max($ys), 5);
    $yMin = $type === 'bar' ? 0.0 : $y0;
    $yMax = $y1;

    $sx = fn(float $x) => $padL + (($x - $xMin) / max(1e-9, $xMax - $xMin)) * $plotW;
    $sy = fn(float $y) => $padT + $plotH - (($y - $yMin) / max(1e-9, $yMax - $yMin)) * $plotH;

    $o = '';
    // frame and gridlines
    $o .= '<rect x="' . chart_num($padL) . '" y="' . chart_num($padT) . '" width="' . chart_num($plotW)
        . '" height="' . chart_num($plotH) . '" fill="#fff" stroke="#000" stroke-width="0.25"/>';
    for ($v = $yMin; $v <= $yMax + 1e-9; $v += $yStep) {
        $y = $sy((float)$v);
        if ($y < $padT - 0.01 || $y > $padT + $plotH + 0.01) continue;
        $o .= '<line x1="' . chart_num($padL) . '" y1="' . chart_num($y) . '" x2="' . chart_num($padL + $plotW)
            . '" y2="' . chart_num($y) . '" stroke="#c9c9c9" stroke-width="0.15"/>';
        $o .= '<text x="' . chart_num($padL - 1.4) . '" y="' . chart_num($y + 1.1)
            . '" font-size="2.5" text-anchor="end" font-family="Arial,Helvetica,sans-serif" fill="#000">'
            . chart_num((float)$v, 3) . '</text>';
    }
    for ($v = $xMin; $v <= $xMax + 1e-9; $v += $xStep) {
        $x = $sx((float)$v);
        if ($x < $padL - 0.01 || $x > $padL + $plotW + 0.01) continue;
        $o .= '<line x1="' . chart_num($x) . '" y1="' . chart_num($padT) . '" x2="' . chart_num($x)
            . '" y2="' . chart_num($padT + $plotH) . '" stroke="#e8e8e8" stroke-width="0.15"/>';
        $o .= '<text x="' . chart_num($x) . '" y="' . chart_num($padT + $plotH + 3.6)
            . '" font-size="2.5" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" fill="#000">'
            . chart_num((float)$v, 3) . '</text>';
    }

    // specification / control limits
    foreach (['lsl' => ['Lower limit', '#8C2F24'], 'usl' => ['Upper limit', '#8C2F24'],
              'target' => ['Target', '#1A7A6D']] as $k => [$lab, $col]) {
        if (!isset($spec['limits'][$k]) || !is_numeric($spec['limits'][$k])) continue;
        $y = $sy((float)$spec['limits'][$k]);
        if ($y < $padT || $y > $padT + $plotH) continue;
        $o .= '<line x1="' . chart_num($padL) . '" y1="' . chart_num($y) . '" x2="' . chart_num($padL + $plotW)
            . '" y2="' . chart_num($y) . '" stroke="' . $col . '" stroke-width="0.3" stroke-dasharray="2 1.4"/>'
            . '<text x="' . chart_num($padL + $plotW - 0.5) . '" y="' . chart_num($y - 0.9)
            . '" font-size="2.2" text-anchor="end" font-family="Arial,Helvetica,sans-serif" fill="' . $col . '">'
            . chart_esc($lab . ' ' . chart_num((float)$spec['limits'][$k], 3)) . '</text>';
    }

    // the series
    $palette = ['#C2703D', '#1B2A4A', '#1A7A6D', '#8C2F24', '#6A1B9A', '#B8860B', '#1F5D8C', '#4E342E'];
    $si = 0;
    $legend = [];
    $maxBarW = $plotW / max(1, count($xs) ? count($xs) : 1) * 0.6;

    foreach ($series as $sr) {
        $name = (string)($sr['name'] ?? ('Series ' . ($si + 1)));
        $col = (string)($sr['color'] ?? $palette[$si % count($palette)]);
        $pts = [];
        foreach (($sr['points'] ?? []) as $p) {
            if (!is_array($p)) continue;
            if (!isset($p['x'], $p['y']) || !is_numeric($p['x']) || !is_numeric($p['y'])) continue;
            $pts[] = ['x' => (float)$p['x'], 'y' => (float)$p['y'], 'label' => (string)($p['label'] ?? '')];
        }
        if (!$pts) { $si++; continue; }
        usort($pts, fn($a, $b) => $a['x'] <=> $b['x']);
        $legend[] = [$name, $col];

        if ($type === 'bar') {
            $bw = min(22.0, max(6.0, $plotW / max(1, count($pts) * 1.8)));
            foreach ($pts as $p) {
                $x = $sx($p['x']) - $bw / 2;
                $y = $sy($p['y']);
                $h = max(0.4, $sy($yMin) - $y);
                $barCol = $col ?: '#1B2A4A';
                $o .= '<rect x="' . chart_num($x) . '" y="' . chart_num($y) . '" width="' . chart_num($bw)
                    . '" height="' . chart_num($h) . '" rx="1.2" fill="' . chart_esc($barCol)
                    . '" fill-opacity="0.88" stroke="' . chart_esc($barCol) . '" stroke-width="0.3"/>';
                // value label on top of bar
                $o .= '<text x="' . chart_num($x + $bw / 2) . '" y="' . chart_num($y - 1.2)
                    . '" font-size="2.4" font-weight="bold" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" fill="#1B2A4A">'
                    . chart_num($p['y'], 2) . '</text>';
                // sample / element label under bar if available
                if (!empty($p['label'])) {
                    $o .= '<text x="' . chart_num($x + $bw / 2) . '" y="' . chart_num($padT + $plotH + 4.0)
                        . '" font-size="2.3" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" fill="#333">'
                        . chart_esc($p['label']) . '</text>';
                }
            }
        } elseif ($type === 'scatter') {
            foreach ($pts as $p) {
                $o .= '<circle cx="' . chart_num($sx($p['x'])) . '" cy="' . chart_num($sy($p['y']))
                    . '" r="1.1" fill="' . chart_esc($col) . '"/>';
            }
        } else {
            // line, control and depth profiles all draw as a joined line
            $d = '';
            foreach ($pts as $i => $p) {
                $d .= ($i === 0 ? 'M' : 'L') . chart_num($sx($p['x'])) . ',' . chart_num($sy($p['y'])) . ' ';
            }
            $o .= '<path d="' . trim($d) . '" fill="none" stroke="' . chart_esc($col) . '" stroke-width="0.45"/>';
            foreach ($pts as $p) {
                $o .= '<circle cx="' . chart_num($sx($p['x'])) . '" cy="' . chart_num($sy($p['y']))
                    . '" r="0.85" fill="' . chart_esc($col) . '"/>';
            }
            // a depth profile labels each point, which is how case-depth charts read
            if ($type === 'depth') {
                foreach ($pts as $p) {
                    $o .= '<text x="' . chart_num($sx($p['x'])) . '" y="' . chart_num($sy($p['y']) - 1.6)
                        . '" font-size="2.1" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" fill="#000">'
                        . chart_num($p['y'], 0) . '</text>';
                }
            }
        }
        $si++;
    }

    // axis titles
    $xLab = trim((string)($spec['x_label'] ?? '') . (($spec['x_unit'] ?? '') !== '' ? ' (' . $spec['x_unit'] . ')' : ''));
    $yLab = trim((string)($spec['y_label'] ?? '') . (($spec['y_unit'] ?? '') !== '' ? ' (' . $spec['y_unit'] . ')' : ''));
    if ($xLab !== '') {
        $o .= '<text x="' . chart_num($padL + $plotW / 2) . '" y="' . chart_num($hMm - 1.6)
            . '" font-size="2.7" text-anchor="middle" font-weight="bold" font-family="Arial,Helvetica,sans-serif" fill="#000">'
            . chart_esc($xLab) . '</text>';
    }
    if ($yLab !== '') {
        $cy = $padT + $plotH / 2;
        $o .= '<text x="3.2" y="' . chart_num($cy) . '" font-size="2.7" text-anchor="middle" font-weight="bold"'
            . ' font-family="Arial,Helvetica,sans-serif" fill="#000"'
            . ' transform="rotate(-90 3.2 ' . chart_num($cy) . ')">' . chart_esc($yLab) . '</text>';
    }

    // title
    $title = trim((string)($spec['title'] ?? ''));
    if ($title !== '') {
        $o .= '<text x="' . chart_num($wMm / 2) . '" y="3.4" font-size="3.4" text-anchor="middle" font-weight="bold"'
            . ' font-family="Arial,Helvetica,sans-serif" fill="#000">' . chart_esc($title) . '</text>';
    }

    // legend, only when there is more than one series
    if (count($legend) > 1) {
        $lx = $padL + 1; $ly = $padT + 2.4;
        foreach ($legend as [$nm, $cl]) {
            $o .= '<line x1="' . chart_num($lx) . '" y1="' . chart_num($ly - 0.8) . '" x2="' . chart_num($lx + 3.4)
                . '" y2="' . chart_num($ly - 0.8) . '" stroke="' . chart_esc($cl) . '" stroke-width="0.5"/>'
                . '<text x="' . chart_num($lx + 4.2) . '" y="' . chart_num($ly)
                . '" font-size="2.3" font-family="Arial,Helvetica,sans-serif" fill="#000">' . chart_esc($nm) . '</text>';
            $ly += 3.2;
        }
    }

    if ($empty) {
        $o .= '<text x="' . chart_num($wMm / 2) . '" y="' . chart_num($hMm / 2)
            . '" font-size="3" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" fill="#8C2F24">'
            . 'No values recorded yet</text>';
    }

    return '<svg class="chart" xmlns="http://www.w3.org/2000/svg" width="' . chart_num($wMm) . 'mm" height="'
         . chart_num($hMm) . 'mm" viewBox="0 0 ' . chart_num($wMm) . ' ' . chart_num($hMm) . '"'
         . ' role="img" aria-label="' . chart_esc($title !== '' ? $title : 'chart') . '">' . $o . '</svg>';
}

/**
 * Build the series for a chart from stored readings.
 *
 * $cfg = [
 *   'job_ids'  => [int, ...]           which tests to pull from
 *   'x_code'   => 'CASE_DEPTH_DISTANCE'  a measurement code, or 'ROW' for the row number
 *   'y_code'   => 'HV'
 *   'group_by' => 'piece' | 'job' | 'none'   how to split into series
 *   'y_reference' => bool   include reference rows (spec values) as points
 * ]
 */
function chart_build_series(array $cfg): array {
    $pdo = $GLOBALS['pdo'];
    $jobIds = array_values(array_filter(array_map('intval', $cfg['job_ids'] ?? [])));
    if (!$jobIds) return [];

    $in = implode(',', array_fill(0, count($jobIds), '?'));

    // measurement ids we need
    $need = [];
    foreach (['x_code', 'y_code'] as $k) {
        $code = (string)($cfg[$k] ?? '');
        if ($code === '' || $code === 'ROW') continue;
        $st = $pdo->prepare('SELECT id FROM c_measurements WHERE code = ? LIMIT 1');
        $st->execute([$code]);
        $id = $st->fetchColumn();
        if ($id) $need[$k] = (int)$id;
    }

    $groupBy = (string)($cfg['group_by'] ?? 'piece');
    $includeRef = !empty($cfg['y_reference']);

    $sql = "SELECT r.row_index, r.col_index, r.row_key, r.value_num, r.raw_value, r.is_reference,
                   r.measurement_id, r.job_id, j.piece_id, p.piece_code, tt.name AS test_name
              FROM c_readings r
              JOIN c_test_jobs  j ON j.id = r.job_id
              JOIN c_test_types tt ON tt.id = j.test_type_id
              LEFT JOIN c_sample_pieces p ON p.id = j.piece_id
             WHERE r.job_id IN ($in) AND r.is_current = 1";
    $st = $pdo->prepare($sql);
    $st->execute($jobIds);
    $rows = $st->fetchAll();

    // group into [seriesKey][row_index] => ['x'=>..,'y'=>..]
    $bucket = [];
    foreach ($rows as $r) {
        $mid = (int)$r['measurement_id'];
        $isX = isset($need['x_code']) && $mid === $need['x_code'];
        $isY = isset($need['y_code']) && $mid === $need['y_code'];
        if (!$isX && !$isY) continue;
        if ((int)$r['is_reference'] === 1 && !$includeRef) continue;

        $key = match ($groupBy) {
            'job'    => 'Test ' . $r['job_id'],
            'piece'  => $r['piece_code'] ? ('Piece ' . $r['piece_code']) : ('Test ' . $r['job_id']),
            default  => 'Values',
        };
        $ri = (int)$r['row_index'];
        if ($isX) {
            $bucket[$key][$ri]['x'] = $r['value_num'] !== null ? (float)$r['value_num'] : null;
            $bucket[$key][$ri]['xlabel'] = (string)$r['raw_value'];
        }
        if ($isY) {
            $bucket[$key][$ri]['y'] = $r['value_num'] !== null ? (float)$r['value_num'] : null;
        }
    }

    // When x is the row number (no explicit x measurement), number the rows in order.
    $out = [];
    foreach ($bucket as $key => $byRow) {
        ksort($byRow);
        $pts = [];
        $n = 0;
        foreach ($byRow as $ri => $v) {
            $n++;
            $y = $v['y'] ?? null;
            if ($y === null) continue;
            $x = array_key_exists('x', $v) && $v['x'] !== null ? $v['x'] : (float)$n;
            $pts[] = ['x' => (float)$x, 'y' => (float)$y, 'label' => (string)($v['xlabel'] ?? '')];
        }
        if ($pts) $out[] = ['name' => (string)$key, 'points' => $pts];
    }
    return $out;
}

/** A short label for a measurement code, for axis titles. */
function chart_measure_label(string $code): array {
    if ($code === 'ROW') return ['Reading number', ''];
    $st = $GLOBALS['pdo']->prepare('SELECT name, canonical_unit FROM c_measurements WHERE code = ? LIMIT 1');
    $st->execute([$code]);
    $r = $st->fetch();
    return $r ? [(string)$r['name'], (string)$r['canonical_unit']] : [$code, ''];
}
