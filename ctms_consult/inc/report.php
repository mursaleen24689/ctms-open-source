<?php
declare(strict_types=1);
/**
 * Report builder and renderer.
 *
 * The output deliberately reproduces the laboratory's existing Word reports:
 *
 *   - landscape A4, navy masthead, logo left / title centre / contact right
 *   - the 3x2 information block (customer, report no, type of sample,
 *     standard test method, item description, grade match, test date)
 *   - one section per test, each with its own title and result table
 *   - the Remarks / Terms & Conditions block
 *   - the professor's Summary, as numbered paragraphs
 *   - the signature row: Measured By / Checked By / Issued by HOD
 *   - a Code 128 barcode and verify code for authenticity
 *
 * Column headings are taken from the measurements themselves, so a new test
 * type prints correctly without touching this file.
 */


require_once __DIR__ . '/barcode.php';
require_once __DIR__ . '/report_settings.php';

/**
 * Work out the unit to print for one measurement within one test type.
 * Returns [unit, decimals].
 */
function ctms_unit_for(array $rule, string $testName, string $mCode): array {
    $u = (string)($rule['canonical_unit'] ?? '');
    $n = strtolower($testName);
    // The existing reports use N/mm2 for tensile in some clients' formats and
    // MPa in others. Print what the standard for that test uses.
    if (in_array($mCode, ['YS','UTS'], true)) {
        if (str_contains($n, 'plastic')) return ['MPa', 2];
        return ['N/mm²', 2];
    }
    if (in_array($mCode, ['YIELD_LOAD','MAX_LOAD'], true)) {
        // A small machine reports N; a UTM reports kN. Decide from the values.
        return ['kN', 3];
    }
    return [$u, 2];
}

/**
 * Assemble everything needed to render one report.
 */
function ctms_build_report_model(int $reportId): ?array {
    $pdo = $GLOBALS['pdo'];

    $st = $pdo->prepare(
        'SELECT r.*, s.sample_code, s.description AS sample_description, s.grade_claimed,
                s.condition_note, s.material_group, s.received_date,
                i.inv_number, i.invoice_date
           FROM c_reports r
           LEFT JOIN c_samples  s ON s.id = r.sample_id
           LEFT JOIN c_billing i ON i.id = r.invoice_id
          WHERE r.id = ?');
    $st->execute([$reportId]);
    $rep = $st->fetch();
    if (!$rep) return null;

    $header = json_decode((string)($rep['header_json'] ?? '{}'), true) ?: [];

    // ── sections, in order ──
    $st = $pdo->prepare(
        'SELECT rs.*, j.test_type_id, j.id AS job_id, j.standard_id, j.status AS job_status,
                j.piece_id, j.remarks AS job_remarks, j.standard_text, j.standard_is_typed,
                tt.name AS test_name, tt.category, tt.result_style, tt.report_title,
                st.code AS std_code, st.title AS std_title, st.revision AS std_rev,
                p.piece_code
           FROM c_report_sections rs
           JOIN c_test_jobs  j  ON j.id  = rs.job_id
           JOIN c_test_types tt ON tt.id = j.test_type_id
           LEFT JOIN c_standards st ON st.id = j.standard_id
           LEFT JOIN c_sample_pieces p ON p.id = j.piece_id
          WHERE rs.report_id = ? AND rs.is_included = 1
          ORDER BY rs.section_order, rs.id');
    $st->execute([$reportId]);
    $sections = $st->fetchAll();

    $out = [];
    foreach ($sections as $sec) {
        $rules = engine_rules((int)$sec['test_type_id']);
        $cols = [];
        $rowsOut = [];

        if ($rules) {
            $readings = engine_job_readings((int)$sec['job_id']);
            foreach ($rules as $code => $rule) {
                [$unit, $dp] = ctms_unit_for($rule, (string)$sec['test_name'], $code);
                $cols[$code] = [
                    'label'  => $rule['column_label'] ?: $rule['m_name'],
                    'unit'   => $unit,
                    'dp'     => $dp,
                    'computed' => (int)$rule['is_computed'] === 1,
                ];
            }
            foreach ($readings as $ri => $byCode) {
                $row = ['label' => '', 'cells' => [], 'reference' => false, 'any_reference' => false];
                foreach ($byCode as $code => $byCol) {
                    $r = $byCol[0] ?? null;
                    if (!$r) continue;
                    if ($r['row_key']) $row['label'] = $r['row_key'];
                    $cell = [
                        'raw'   => $r['raw_value'],
                        'num'   => $r['value_num'],
                        'text'  => $r['value_text'],
                        'bdl'   => (int)$r['is_below_detection'] === 1,
                        'ref'   => (int)$r['is_reference'] === 1,
                    ];
                    if ($cell['ref']) $row['any_reference'] = true;
                    $row['cells'][$code] = $cell;
                }
                $rowsOut[$ri] = $row;
            }
        }

        $secStd = null;
        if ((int)($sec['standard_is_typed'] ?? 0) === 1) {
            $t = trim((string)($sec['standard_text'] ?? ''));
            $secStd = $t !== '' ? $t : null;
        } elseif (!empty($sec['std_code'])) {
            $secStd = trim($sec['std_code'] . ($sec['std_rev'] ? ' (' . $sec['std_rev'] . ')' : ''));
        }

        $out[] = [
            'job_id'      => (int)$sec['job_id'],
            'test_name'   => $sec['test_name'],
            'title'       => $sec['report_title'] ?: strtoupper((string)$sec['test_name']),
            'standard'    => $secStd,
            'standard_title' => (int)($sec['standard_is_typed'] ?? 0) === 1 ? null : $sec['std_title'],
            'piece'       => $sec['piece_code'],
            'is_multi'    => $sec['result_style'] === 'composition_multi',
            'columns'     => $cols,
            'rows'        => $rowsOut,
            'remarks'     => $sec['job_remarks'],
            'has_readings' => !empty($rowsOut),
        ];
    }

    // ── assets (micrographs) attached to this report or to jobs included in this report ──
    $includedJobIds = array_map(fn($s) => (int)$s['job_id'], $sections);
    if ($includedJobIds) {
        $inJobs = implode(',', $includedJobIds);
        $st = $pdo->prepare("SELECT * FROM c_report_assets WHERE report_id = ? OR job_id IN ($inJobs) ORDER BY sort_order, id");
        $st->execute([$reportId]);
    } else {
        $st = $pdo->prepare('SELECT * FROM c_report_assets WHERE report_id = ? ORDER BY sort_order, id');
        $st->execute([$reportId]);
    }
    $assets = $st->fetchAll();

    $verifyCode = ctms_verify_code((string)$rep['report_number']);
    $barcodeVal = ctms_barcode_value((string)$rep['report_number'], $verifyCode);

    return [
        'report'   => $rep,
        'header'   => $header,
        'sections' => $out,
        'assets'   => $assets,
        'verify_code' => $verifyCode,
        'barcode_value' => $barcodeVal,
        'settings' => [
            'lab_name'   => setting('lab_name', 'Department of Materials Engineering'),
            'lab_full'   => setting('lab_full_name', 'Example Technical University'),
            'lab_unit'   => setting('lab_unit_name', 'MATERIAL TESTING LABORATORIES'),
            'lab_phone'  => setting('lab_phone', ''),
            'lab_fax'    => setting('lab_fax', ''),
            'lab_email'  => setting('lab_email', ''),
        ],
    ];
}

/** Format one cell exactly as the reports print it. */
function ctms_cell_text(?array $cell, int $dp = 2): string {
    if (!$cell) return '';
    if ($cell['raw'] === null || $cell['raw'] === '') return '';
    if ($cell['bdl']) return '< ' . rtrim(rtrim(number_format((float)$cell['num'], $dp, '.', ''), '0'), '.');
    if ($cell['num'] === null) return htmlspecialchars((string)$cell['raw']);
    if (in_array($cell['text'], ['remainder'], true)) return 'Balance';
    if (in_array($cell['text'], ['not_measured'], true)) return '--';
    if (in_array($cell['text'], ['not_tested'], true)) return 'Not tested';
    if (in_array($cell['text'], ['not_applicable'], true)) return 'N/A';
    if (in_array($cell['text'], ['room_temperature'], true)) return 'Room Temp.';
    if (in_array($cell['text'], ['spec_min'], true)) return 'Min ' . number_format((float)$cell['num'], $dp);
    if (in_array($cell['text'], ['spec_max'], true)) return 'Max ' . number_format((float)$cell['num'], $dp);
    return number_format((float)$cell['num'], $dp, '.', '');
}

/**
 * Render one annotated image, ready for print.
 *
 * The picture and its mark-up stay separate: the original photo is placed as a
 * normal image, and the annotations sit over it as SVG in percent coordinates.
 * So a depth line drawn on a micrograph is vector-sharp at any printed size, the
 * original file is never altered, and the mark-up can still be corrected later.
 *
 * Sizing rules, so the report keeps its shape:
 *   - the requested width is capped by the page
 *   - the height is capped too, so one tall photo cannot wreck the layout
 *   - an image and its caption never split across a page break
 */
function ctms_render_image(array $a): string {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // ── a chart is stored as a specification, not a picture ──
    // It is redrawn from the current readings every time the report is rendered,
    // so correcting a value corrects the graph that sits beside its table.
    if (!empty($a['chart_spec_json'])) {
        require_once __DIR__ . '/chart.php';
        $spec = json_decode((string)$a['chart_spec_json'], true) ?: [];
        $series = chart_build_series([
            'job_ids'     => $spec['job_ids'] ?? [],
            'x_code'      => $spec['x_code'] ?? 'ROW',
            'y_code'      => $spec['y_code'] ?? '',
            'group_by'    => $spec['group_by'] ?? 'piece',
            'y_reference' => !empty($spec['y_reference']),
        ]);
        $draw = $spec;
        $draw['series'] = $series;
        $cap = trim((string)($a['caption'] ?? ''));
        return '<figure class="imgfig" style="align-self:center">'
             . chart_svg($draw)
             . ($cap !== '' && $cap !== ($spec['title'] ?? '') ? '<figcaption>' . $h($cap) . '</figcaption>' : '')
             . '</figure>';
    }

    $file = app_url('uploads/original/' . rawurlencode((string)$a['stored_name']));

    $w = match ($a['display_size'] ?? 'medium') {
        'small'  => 45.0,
        'medium' => 75.0,
        'large'  => 110.0,
        'full'   => 185.0,
        'custom' => (float)($a['display_width_mm'] ?? 75.0),
        default  => 75.0,
    };
    $w = max(20.0, min(265.0, $w));
    $maxH = (($a['display_size'] ?? '') === 'full') ? 118.0 : 90.0;

    $align = match ($a['display_align'] ?? 'center') {
        'left' => 'flex-start', 'right' => 'flex-end', default => 'center',
    };

    // measure the original so the overlay keeps its proportions
    $actual = @getimagesize(rtrim((string)$GLOBALS['CFG']['upload_dir'], '/\\') . '/original/' . $a['stored_name']);
    $iw = (int)($actual[0] ?? 1000);
    $ih = (int)($actual[1] ?? 700);
    $vbW = 1000;
    $vbH = max(1, (int)round($vbW * ($ih / max(1, $iw))));

    $shapes = json_decode((string)($a['annotations_json'] ?? '[]'), true);
    if (!is_array($shapes)) $shapes = [];

    $parts = '';
    foreach ($shapes as $s) {
        if (!is_array($s)) continue;
        $t   = (string)($s['type'] ?? '');
        $col = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($s['color'] ?? '')) ? (string)$s['color'] : '#E01B24';
        $sw  = max(0.1, min(4.0, (float)($s['weight'] ?? 0.7))) * $vbW / 100;
        $X = fn($p) => round((float)$p / 100 * $vbW, 2);
        $Y = fn($p) => round((float)$p / 100 * $vbH, 2);
        $fs = $vbW / 52;
        $outline = ' stroke="#000" stroke-width="' . round($fs / 14, 2) . '" paint-order="stroke"';

        if ($t === 'line') {
            $parts .= '<line x1="' . $X($s['x1'] ?? 0) . '" y1="' . $Y($s['y1'] ?? 0)
                    . '" x2="' . $X($s['x2'] ?? 0) . '" y2="' . $Y($s['y2'] ?? 0)
                    . '" stroke="' . $h($col) . '" stroke-width="' . round($sw, 2) . '"/>';
            if (!empty($s['label'])) {
                $mx = ($X($s['x1'] ?? 0) + $X($s['x2'] ?? 0)) / 2;
                $my = ($Y($s['y1'] ?? 0) + $Y($s['y2'] ?? 0)) / 2;
                $parts .= '<text x="' . round($mx + $sw * 1.4, 2) . '" y="' . round($my, 2)
                        . '" font-size="' . round($fs, 2) . '" font-family="Arial,Helvetica,sans-serif"'
                        . ' font-weight="bold" fill="' . $h($col) . '"' . $outline . '>'
                        . $h($s['label']) . '</text>';
            }
        } elseif ($t === 'rect') {
            $x1 = min((float)($s['x1'] ?? 0), (float)($s['x2'] ?? 0));
            $y1 = min((float)($s['y1'] ?? 0), (float)($s['y2'] ?? 0));
            $x2 = max((float)($s['x1'] ?? 0), (float)($s['x2'] ?? 0));
            $y2 = max((float)($s['y1'] ?? 0), (float)($s['y2'] ?? 0));
            $parts .= '<rect x="' . $X($x1) . '" y="' . $Y($y1) . '" width="'
                    . round($X($x2) - $X($x1), 2) . '" height="' . round($Y($y2) - $Y($y1), 2)
                    . '" fill="none" stroke="' . $h($col) . '" stroke-width="' . round($sw, 2) . '"/>';
            if (!empty($s['text'])) {
                $parts .= '<text x="' . $X($x1) . '" y="' . round($Y($y2) + $fs * 1.2, 2)
                        . '" font-size="' . round($fs, 2) . '" font-family="Arial,Helvetica,sans-serif"'
                        . ' font-weight="bold" fill="' . $h($col) . '"' . $outline . '>'
                        . $h($s['text']) . '</text>';
            }
        } elseif ($t === 'arrow') {
            $x1 = $X($s['x1'] ?? 0); $y1 = $Y($s['y1'] ?? 0);
            $x2 = $X($s['x2'] ?? 0); $y2 = $Y($s['y2'] ?? 0);
            $ang = atan2($y2 - $y1, $x2 - $x1);
            $hl = max($sw * 4, $vbW / 60);
            $parts .= '<line x1="' . $x1 . '" y1="' . $y1 . '" x2="' . $x2 . '" y2="' . $y2
                    . '" stroke="' . $h($col) . '" stroke-width="' . round($sw, 2) . '"/>'
                    . '<path d="M' . $x2 . ',' . $y2
                    . ' L' . round($x2 - $hl * cos($ang - 0.4), 2) . ',' . round($y2 - $hl * sin($ang - 0.4), 2)
                    . ' L' . round($x2 - $hl * cos($ang + 0.4), 2) . ',' . round($y2 - $hl * sin($ang + 0.4), 2)
                    . ' Z" fill="' . $h($col) . '"/>';
        } elseif ($t === 'text') {
            $fsx = max(1.0, min(14.0, (float)($s['size'] ?? 3.2))) / 100 * $vbH;
            $parts .= '<text x="' . $X($s['x1'] ?? 0) . '" y="' . $Y($s['y1'] ?? 0)
                    . '" font-size="' . round($fsx, 2) . '" font-family="Arial,Helvetica,sans-serif"'
                    . ' font-weight="bold" fill="' . $h($col) . '" stroke="#000" stroke-width="'
                    . round($fsx / 14, 2) . '" paint-order="stroke">' . $h($s['text'] ?? '') . '</text>';
        }
    }
    $overlay = $parts === '' ? '' :
        '<svg class="ann" viewBox="0 0 ' . $vbW . ' ' . $vbH . '" preserveAspectRatio="none"'
        . ' xmlns="http://www.w3.org/2000/svg">' . $parts . '</svg>';

    $cap = trim((string)($a['caption'] ?? ''));
    $mag = trim((string)($a['magnification'] ?? ''));
    $captionHtml = ($cap !== '' || $mag !== '')
        ? '<figcaption>' . $h($cap) . ($mag !== '' ? ' <strong>(' . $h($mag) . ')</strong>' : '') . '</figcaption>'
        : '';

    return '<figure class="imgfig" style="width:' . $w . 'mm;align-self:' . $align . '">'
         . '<div class="imgwrap" style="max-height:' . $maxH . 'mm">'
         . '<img src="' . $h($file) . '" alt="' . $h($cap !== '' ? $cap : 'report image') . '"'
         . ' onerror="this.onerror=null;this.style.display=\'none\';var p=document.createElement(\'div\');p.style.cssText=\'padding:16px;border:1px dashed #999;font-size:8pt;color:#666;text-align:center;background:#f9f9f9;\';p.textContent=\'[Image missing or unavailable]\';this.parentNode.insertBefore(p,this);">'
         . $overlay . '</div>' . $captionHtml . '</figure>';
}

/**
 * Render the complete printable report as HTML.
 * The same markup is used for the on-screen preview and for printing, so the
 * PDF cannot disagree with what the technician saw.
 */
function ctms_render_report(array $model, bool $printButton = true): string {
    $rep    = $model['report'];
    $hd     = $model['header'];
    $S      = $model['settings'];
    // The report's own choice wins; otherwise the laboratory default.
    $landscape = (($hd['orientation'] ?? '') ?: report_layout('report_orientation')) === 'landscape';
    $printOrientation = $landscape ? 'landscape' : 'portrait';

    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $nl = fn($s) => nl2br($h($s));

    $css = <<<CSS
:root{--navy:#1B2A4A;--copper:#C2703D}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Times New Roman',Times,serif;font-size:9pt;color:#000;background:#f0f2f5}
.sheet{background:#fff;margin:16px auto;padding:5mm 7mm;box-shadow:0 6px 24px rgba(0,0,0,.12);
  width:297mm;min-height:190mm;display:flex;flex-direction:column;justify-content:space-between}
.sheet.portrait{width:210mm;min-height:287mm}
@media print{
  body{background:#fff;margin:0}
  .sheet{margin:0;box-shadow:none;width:auto;min-height:0;height:auto;padding:0;display:block}
  .bottombar{display:block;margin-top:12mm;padding-top:0}
  .sigs,.barcodeblock{display:block;margin-top:4mm}
  .imggrid{display:block}
  .imgfig{display:inline-block;margin:2mm;vertical-align:top}
  .section,table.data,.bottombar{break-inside:avoid;page-break-inside:avoid}
  .no-print{display:none!important}
  @page{size:A4 {$printOrientation};margin:6mm 7mm 6mm 7mm}
}
.masthead{width:100%;display:table;table-layout:fixed;border-bottom:1.5px solid var(--navy);padding-bottom:2px;margin-bottom:3px}
.masthead .logo{display:table-cell;width:42mm;vertical-align:middle}
.masthead .logo img{width:25mm;height:25mm;object-fit:contain;color:transparent;font-size:0}
.masthead .centre{display:table-cell;vertical-align:middle;text-align:center;padding:0 4px}
.masthead .contact{display:table-cell;width:42mm;vertical-align:middle;text-align:right;
  font-size:7.5pt;line-height:1.25;word-break:break-word}
.uni{font-size:13.5pt;font-weight:bold;line-height:1.05}
.dept{font-size:10.5pt;line-height:1.1}
.unit{font-size:9.5pt;font-weight:bold;letter-spacing:.3px}
table.info{width:100%;border-collapse:collapse;margin-bottom:3px;font-size:8.5pt;line-height:1.2}
table.info td{border:none;vertical-align:top;padding:0 2px}
table.info td.l{width:62%}
table.info td.r{width:38%;text-align:right}
.section{margin-top:4px}
.section-title{text-align:center;font-size:10.5pt;font-weight:bold;text-decoration:underline;margin:3px 0 1px}
.section-std{text-align:center;font-size:8pt;color:#333;margin-bottom:1px}
table.data{width:100%;border-collapse:collapse;font-size:8.5pt}
table.data td,table.data th{border:1px solid #000;padding:1px 2px;vertical-align:middle}
table.data th{text-align:center;font-weight:bold;background:#fff}
table.data td.num{text-align:center}
table.data td.lbl{text-align:center;font-weight:bold}
tr.refrow td{font-style:italic;background:#f6f6f6}
.remarks{font-size:8pt;line-height:1.25;margin-top:4px;padding:1px}
.remarks strong{display:block;margin-bottom:1px}
.summary{font-size:8pt;line-height:1.25;margin-top:4px;padding:1px}
.summary strong{display:block;margin-bottom:1px}
/* The signature row and the barcode are one unit and pin cleanly to the page bottom */
.bottombar{display:flex;flex-direction:column;align-items:stretch;gap:4px;margin-top:auto;padding-top:14px;
  break-inside:avoid;page-break-inside:avoid}
.sigs{flex:0 0 auto;min-width:0;margin-top:4px}
.barcodeblock{flex:0 0 auto;text-align:center;break-inside:avoid;page-break-inside:avoid;margin-top:2px}
.barcodefit{max-width:140mm;margin:0 auto;display:block}
.barcodefit svg{width:100%;height:auto;max-height:10mm;display:block;margin:0 auto}
table.sig{width:100%;border-collapse:collapse}
table.sig td{border:none;text-align:center;font-size:8.5pt;padding-top:38px;vertical-align:bottom}
.sigline{border-top:1px solid #000;margin:0 8%;padding-top:2px}
.sigdate{font-size:7.5pt;color:#444;text-align:center;margin-top:2px}
.barcodeblock .cap{font-size:6pt;line-height:1.1;margin-top:1px;text-align:center;
  font-family:'Courier New',monospace;font-weight:bold}
.cg{text-align:center;font-size:8.5pt;font-style:italic;padding-top:8px}
.assets{margin-top:4px;text-align:center}
.assets figure{display:inline-block;margin:2px 4px;vertical-align:top}
.assets img{max-width:48mm;max-height:34mm;border:1px solid #999}
.assets figcaption{font-size:7pt;margin-top:1px}
/* ── inserted images and their mark-up ── */
.imggrid{display:flex;flex-wrap:wrap;gap:5mm;justify-content:center;align-items:flex-start;
  margin-top:6px;break-inside:avoid;page-break-inside:avoid}
.imgfig{margin:0;break-inside:avoid;page-break-inside:avoid;text-align:center}
.imgwrap{position:relative;display:inline-block;line-height:0;max-width:100%}
.imgwrap img{max-width:100%;display:block;border:1px solid #999}
/* The mark-up sits over the picture as vector shapes in percent coordinates,
   so it stays sharp at any printed size and never alters the original. */
.imgwrap svg.ann{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;overflow:visible}
.imgfig figcaption{font-size:8pt;margin-top:2px;text-align:center;color:#111}
.pagebreak{page-break-before:always}
.screenbar{max-width:297mm;margin:12px auto 0;text-align:right;padding:0 4px}
.screenbar button{padding:9px 18px;background:var(--copper);color:#fff;border:0;border-radius:6px;
  font-weight:700;cursor:pointer;font-size:12px;margin-left:6px}
.screenbar a{display:inline-block;padding:9px 18px;background:#fff;color:var(--navy);border:1.5px solid #ccc;
  border-radius:6px;font-weight:600;font-size:12px;text-decoration:none}
CSS;

    $configuredLogo = trim((string)(getenv('CTMS_CONSULT_REPORT_LOGO') ?: ''));
    $logoFile = $configuredLogo !== '' ? $configuredLogo : '';
    $logoBytes = is_file($logoFile) ? file_get_contents($logoFile) : false;
    $logo = is_string($logoBytes) ? 'data:image/png;base64,' . base64_encode($logoBytes) : app_url('assets/ned_logo.png');
    $status = $rep['status'] === 'issued' ? '' :
        '<div style="position:fixed;top:8px;right:12px;background:#8A6116;color:#fff;padding:4px 12px;'
        . 'border-radius:4px;font:700 11px sans-serif;z-index:99" class="no-print">'
        . strtoupper($h($rep['status'])) . ' — not issued</div>';

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
          . '<title>' . $h($rep['report_number']) . ' · ' . $h($rep['company_name']) . '</title>'
          . '<style>' . $css . '</style></head><body>' . $status;

    if ($printButton) {
        $html .= '<div class="screenbar no-print">'
               . '<a href="' . $h(app_url('reports.php?action=view&id=' . (int)$rep['id'])) . '">Back to report</a>'
               . '<button onclick="window.print()">Print / Save as PDF</button>'
               . '<button type="button" onclick="exportReportToWord()" style="background:var(--navy);margin-left:6px">Download in Word (.doc)</button>'
               . '</div>';
    }

    $html .= '<div class="sheet' . ($landscape ? '' : ' portrait') . '">';

    // ── masthead ──
    $html .= '<div class="masthead">'
           . '<div class="logo"><img src="' . $h($logo) . '" alt="" class="logoimg"></div>'
           . '<div class="centre"><div class="uni">' . $h($S['lab_full']) . '</div>'
           . '<div class="dept">' . $h($S['lab_name']) . '</div>'
           . '<div class="unit">' . $h($S['lab_unit']) . '</div></div>'
           . '<div class="contact">'
           . ($S['lab_phone'] ? 'Off: ' . $h($S['lab_phone']) . '<br>' : '')
           . ($S['lab_fax']   ? 'Fax: ' . $h($S['lab_fax']) . '<br>' : '')
           . ($S['lab_email'] ? 'Email: ' . $h($S['lab_email']) : '')
           . '</div></div>';

    // ── information block ──
    $html .= '<table class="info">'
           . '<tr><td class="l"><strong>Customer Name:</strong> ' . $h($rep['company_name']) . '</td>'
           .     '<td class="r"><strong>Test Report No:</strong> ' . $h($rep['report_number']) . '</td></tr>'
           . '<tr><td class="l"><strong>Type of sample:</strong> ' . $h($hd['type_of_sample'] ?? $rep['sample_description']) . '</td>'
           .     '<td class="r"><strong>Standard Test Method for:</strong> ' . $h($hd['standard_note'] ?? '') . '</td></tr>'
           . '<tr><td class="l">'
           .   (($hd['item_description'] ?? '') !== '' ? '<strong>Item Description:</strong> ' . $h($hd['item_description']) . '<br>' : '')
           .   (($hd['grade_match'] ?? '') !== '' ? '<strong>Grade Match:</strong> ' . $h($hd['grade_match']) . '<br>' : '')
           .   '<strong>Sample ID:</strong> ' . $h($rep['sample_code'] ?: '-')
           .   (($hd['reference'] ?? '') !== '' ? '&nbsp;&nbsp;<strong>Ref. No.:</strong> ' . $h($hd['reference']) : '')
           .   '</td>'
           .     '<td class="r"><strong>Test Date:</strong> ' . $h($hd['test_date'] ?? date('d M Y')) . '<br>'
           .     (($hd['received'] ?? '') !== '' ? '<strong>Received:</strong> ' . $h($hd['received']) : '')
           .     '</td></tr>'
           . '</table>';

    // ── one block per test ──
    if (!$model['sections']) {
        $html .= '<p style="margin-top:16px;font-style:italic">No test results have been attached to this report yet.</p>';
    }

    foreach ($model['sections'] as $sec) {
        $html .= '<div class="section">';
        $html .= '<div class="section-title">' . $h($sec['title']) . '</div>';
        if ($sec['standard']) {
            $html .= '<div class="section-std">' . $h($sec['standard'])
                   . ($sec['standard_title'] ? ' — ' . $h($sec['standard_title']) : '') . '</div>';
        }

        $mine = array_values(array_filter($model['assets'],
            fn($a) => (int)$a['job_id'] === (int)$sec['job_id']));

        if (!$sec['has_readings'] && !$mine && !$sec['remarks']) {
            $html .= '<p style="text-align:center;font-style:italic;font-size:9.5pt">No readings recorded.</p></div>';
            continue;
        }

        $cols = $sec['columns'];
        if (!empty($cols) && !empty($sec['rows'])) {
            $html .= '<table class="data"><thead><tr>';
            $html .= '<th style="width:34px">Sr.<br>No.</th>';
            if ($sec['is_multi']) {
                $html .= '<th style="width:90px">Sample</th>';
            } else {
                $html .= '<th style="width:90px">Sample</th>';
            }
        foreach ($cols as $code => $c) {
            $html .= '<th>' . $h($c['label'])
                   . ($c['unit'] ? '<br><span style="font-weight:normal">(' . $h($c['unit']) . ')</span>' : '')
                   . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        $n = 0;
        foreach ($sec['rows'] as $ri => $row) {
            $n++;
            $cls = $row['any_reference'] ? ' class="refrow"' : '';
            $html .= '<tr' . $cls . '>';
            $html .= '<td class="num">' . str_pad((string)$n, 2, '0', STR_PAD_LEFT) . '</td>';
            // What identifies this row, most meaningful first.
            //
            // This used to fall back to the row NUMBER, so a composition table showed
            // "1" under a heading of "Sample" — which tells a client nothing.
            //
            // Some readings carry an automatically generated piece code as their row
            // label, of the form SMP20260007.1. That is an internal identifier and
            // means nothing on a client document, so it is treated as "no label" and
            // the sample type is used instead.
            $label = trim((string)$row['label']);
            if (preg_match('/^SMP\d+\.[0-9]+$/i', $label)) $label = '';

            if ($label === '') {
                $typeOfSample = trim((string)($hd['type_of_sample'] ?? ''));
                $label = $typeOfSample !== ''
                      ? $typeOfSample
                      : trim((string)($rep['sample_description'] ?? ''));
            }
            if ($label === '') $label = (string)($sec['piece'] ?? '');
            if ($label === '') $label = (string)($rep['sample_code'] ?? '');
            if ($label === '') $label = (string)$n;          // last resort only
            $html .= '<td class="lbl">' . $h($label) . '</td>';
            foreach ($cols as $code => $c) {
                $cell = $row['cells'][$code] ?? null;
                $txt = ctms_cell_text($cell, (int)$c['dp']);
                $html .= '<td class="num">' . $txt . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
    }

        // Images attached to this test, with their mark-up.
        // Small and medium pictures share a row, exactly as the laboratory's own
        // case-depth pages do (two micrographs side by side). Large and full-width
        // pictures take a row of their own.
        $mine = array_values(array_filter($model['assets'],
            fn($a) => (int)$a['job_id'] === (int)$sec['job_id']));
        if ($mine) {
            $row = [];
            $flush = function () use (&$row, &$html) {
                if (!$row) return;
                $html .= '<div class="imggrid">';
                foreach ($row as $a) $html .= ctms_render_image($a);
                $html .= '</div>';
                $row = [];
            };
            foreach ($mine as $a) {
                $wide = in_array($a['display_size'], ['large', 'full'], true);
                if ($wide) { $flush(); $html .= '<div class="imggrid">' . ctms_render_image($a) . '</div>'; }
                else {
                    $row[] = $a;
                    if (count($row) === 2) $flush();
                }
            }
            $flush();
        }
        if ($sec['remarks']) {
            $html .= '<div class="remarks"><strong>Remarks:</strong> ' . $nl($sec['remarks']) . '</div>';
        }
        $html .= '</div>';
    }

    // ── images not tied to a particular test (general photographs) ──
    $loose = array_values(array_filter($model['assets'], fn($a) => empty($a['job_id'])));
    if ($loose) {
        $html .= '<div class="section">';
        $html .= '<div class="section-title">PHOTOGRAPHS</div>';
        $row = [];
        $flush = function () use (&$row, &$html) {
            if (!$row) return;
            $html .= '<div class="imggrid">';
            foreach ($row as $a) $html .= ctms_render_image($a);
            $html .= '</div>';
            $row = [];
        };
        foreach ($loose as $a) {
            $wide = in_array($a['display_size'], ['large', 'full'], true);
            if ($wide) { $flush(); $html .= '<div class="imggrid">' . ctms_render_image($a) . '</div>'; }
            else {
                $row[] = $a;
                if (count($row) === 2) $flush();
            }
        }
        $flush();
        $html .= '</div>';
    }
    $remarks = $rep['remarks_html'] ?: setting('report_default_remarks', '');
    if ($remarks !== '' && report_layout('report_show_remarks') === '1') {
        $html .= '<div class="remarks"><strong>Remarks / Term &amp; Conditions:</strong> '
               . $nl(strip_tags((string)$remarks)) . '</div>';
    }

    // ── professor's summary ──
    if (($rep['summary_html'] ?? '') !== '' && report_layout('report_show_summary') === '1') {
        $html .= '<div class="summary"><strong>Summary</strong>'
               . $nl(strip_tags((string)$rep['summary_html'])) . '</div>';
    }

    // ── signatures and barcode ──
    // The signature block is configurable, because the staff who sign a report
    // change. "Measured By" ships switched off: the technician records readings
    // in the system and the report is checked and issued.
    $issued  = $rep['status'] === 'issued';
    $sigs    = report_signatures_active();
    $showBar = report_layout('report_show_barcode') === '1';

    $html .= '<div class="bottombar">';

    if ($sigs) {
        $w = (int)floor(100 / max(1, count($sigs)));
        $html .= '<div class="sigs"><table class="sig"><tr>';
        foreach ($sigs as $s) {
            $html .= '<td style="width:' . $w . '%"><div class="sigline">' . $h($s['label']) . '</div></td>';
        }
        $html .= '</tr></table>';
        if ($issued) {
            $html .= '<div class="sigdate">'
                   . 'Checked and issued on ' . $h($rep['issued_at'] ? date('d M Y', strtotime((string)$rep['issued_at'])) : '')
                   . '</div>';
        } else {
            $html .= '<div class="sigdate"><em>This report is not yet issued. '
                   . 'It becomes a controlled document once approved and issued.</em></div>';
        }
        $html .= '</div>';
    } elseif (!$issued) {
        $html .= '<div class="sigs"><div class="cg">This report is not yet issued. '
               . 'It becomes a controlled document once approved and issued.</div></div>';
    }

    if ($showBar) {
        // The barcode sits BELOW the signature lines, centred on the page.
        //
        // It used to sit to the right of the signatures inside the same flex row.
        // A Code-128 barcode's width grows with the length of the value, and it was
        // being drawn 85.8 mm wide inside a 58 mm box — which pushed the box past
        // the right edge of the paper. Placed on its own line and given a maximum
        // width, it can never overflow however long the value becomes.
        $html .= '<div class="barcodeblock">'
               . '<div class="barcodefit">' . ctms_barcode_svg($model['barcode_value']) . '</div>'
               . '<div class="cap">' . $h($model['barcode_value']) . '</div>'
               . '</div>';
    }
    $html .= '</div>';

    $html .= '</div>'
           . '<script>
async function exportReportToWord() {
    var sheet = document.querySelector(".sheet");
    if (!sheet) return;
    var cloned = sheet.cloneNode(true);
    await Promise.all(Array.from(cloned.querySelectorAll("img")).map(async function(img) {
        var src = img.getAttribute("src") || "";
        if (!src || src.indexOf("data:") === 0) return;
        try {
            var response = await fetch(src, {credentials: "same-origin"});
            if (!response.ok) return;
            var blob = await response.blob();
            img.src = await new Promise(function(resolve) {
                var reader = new FileReader();
                reader.onload = function() { resolve(reader.result); };
                reader.readAsDataURL(blob);
            });
        } catch (e) {}
    }));
    await Promise.all(Array.from(cloned.querySelectorAll(".barcodefit svg")).map(async function(svg) {
        var view = svg.viewBox && svg.viewBox.baseVal;
        if (!view || !view.width || !view.height) return;
        var xml = new XMLSerializer().serializeToString(svg);
        var objectUrl = URL.createObjectURL(new Blob([xml], {type: "image/svg+xml;charset=utf-8"}));
        try {
            var source = new Image();
            source.src = objectUrl;
            await new Promise(function(resolve, reject) { source.onload = resolve; source.onerror = reject; });
            var scale = Math.min(24, 4200 / Math.max(view.width, view.height));
            var canvas = document.createElement("canvas");
            canvas.width = Math.max(1, Math.ceil(view.width * scale));
            canvas.height = Math.max(1, Math.ceil(view.height * scale));
            var context = canvas.getContext("2d");
            context.drawImage(source, 0, 0, canvas.width, canvas.height);
            var image = document.createElement("img");
            image.className = "word-barcode";
            image.alt = "Report barcode";
            image.src = canvas.toDataURL("image/png");
            image.style.cssText = "width:100%;height:auto;max-height:10mm;display:block;margin:0 auto";
            svg.replaceWith(image);
        } catch (e) {
            console.error("Could not prepare the report barcode for Word export.", e);
        } finally {
            URL.revokeObjectURL(objectUrl);
        }
    }));
    var masthead = cloned.querySelector(".masthead");
    if (masthead) {
        var table = document.createElement("table");
        table.className = "word-masthead";
        var row = table.insertRow();
        [".logo", ".centre", ".contact"].forEach(function(selector) {
            var cell = row.insertCell();
            var source = masthead.querySelector(selector);
            if (source) { cell.className = source.className; while (source.firstChild) cell.appendChild(source.firstChild); }
        });
        masthead.replaceWith(table);
    }
    var styles = document.querySelector("style") ? document.querySelector("style").innerHTML : "";
    var pageRule = cloned.classList.contains("portrait") ? "@page Section1{size:595.3pt 841.9pt;mso-page-orientation:portrait;margin:22pt} " : "@page Section1{size:841.9pt 595.3pt;mso-page-orientation:landscape;margin:22pt} ";
    var wordStyles = pageRule +
        "div.Section1{page:Section1} body{background:#fff;padding:0;margin:0} " +
        ".sheet{margin:0;box-shadow:none;width:100%;min-height:0;display:block;padding:0} " +
        ".word-masthead{width:100%;border-collapse:collapse;border-bottom:1.5px solid #1B2A4A;margin-bottom:3px} " +
        ".word-masthead td{border:0;vertical-align:middle;padding:1px 3px} " +
        ".word-masthead td.logo{width:25mm} .word-masthead td.centre{text-align:center} " +
        ".word-masthead td.contact{width:42mm;text-align:right} .word-masthead img{width:25mm;height:25mm} " +
        ".imggrid{display:block;margin-top:6px}.imgfig{display:inline-block;vertical-align:top;margin:2mm} " +
        ".section{page-break-inside:avoid} table.data{page-break-inside:avoid} .barcodefit .word-barcode{object-fit:contain}";
    var htmlContent = "<html xmlns:o=\'urn:schemas-microsoft-com:office:office\' xmlns:w=\'urn:schemas-microsoft-com:office:word\' xmlns=\'http://www.w3.org/TR/REC-html40\'><head><meta charset=\'utf-8\'><title>" + document.title + "</title><style>" + styles + wordStyles + "</style></head><body><div class=\'Section1\'>" + cloned.innerHTML + "</div></body></html>";
    var blob = new Blob(["\ufeff", htmlContent], {type: "application/msword;charset=utf-8"});
    var url = URL.createObjectURL(blob);
    var a = document.createElement("a");
    a.href = url;
    a.download = (document.title || "Report").replace(/[^a-zA-Z0-9_-]/g, "_") + ".doc";
    document.body.appendChild(a);
    a.click();
    setTimeout(function() { document.body.removeChild(a); URL.revokeObjectURL(url); }, 500);
}
</script>'
           . '</body></html>';
    return $html;
}
