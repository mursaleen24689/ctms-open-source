<?php
// ============================================================
// api/generate_lot.php — Lot Sheet Excel Export (SpreadsheetML)
//
// Called through a short-lived, one-time download ticket.
//
// Produces a true Excel 2003 XML (SpreadsheetML) file —
// opens natively in Excel with exact column widths, merged
// cells, borders, and fonts. No external libraries needed.
// ============================================================
require_once 'db.php';

// A-2: POST issues a one-time ticket; GET accepts only that ticket.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    $u = require_auth();
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $lid  = (int)($body['lot_id'] ?? 0);
    if (!$lid) { http_response_code(400); echo json_encode(['error' => 'lot_id required']); exit; }
    ctms_require_lot_access($pdo, (int)$u['user_id'], $lid, 'view');
    $ticket = issue_download_ticket($pdo, (int)$u['user_id'], 'lot_excel', $lid);
    echo json_encode(['success' => true, 'ticket' => $ticket,
                      'url' => "generate_lot.php?lot_id=$lid&ticket=$ticket"]);
    exit;
}
$lot_id = (int)($_GET['lot_id'] ?? 0);
$tRaw = $_GET['ticket'] ?? '';
if ($tRaw !== '') {
    $t = validate_download_ticket($pdo, $tRaw, 'lot_excel');
    if (empty($t['ok']) || (int)$t['scope_id'] !== $lot_id) { http_response_code(403); die('Download link expired or already used. Please generate the sheet again.'); }
    ctms_require_role(['superadmin', 'admin', 'hod', 'deo', 'reception', 'mapping', 'technician'], $t,
        'Lot exports are restricted to authorized staff.');
    ctms_require_lot_access($pdo, (int)$t['user_id'], $lot_id, 'view');
} else {
    http_response_code(401);
    die('A valid one-time download link is required. Please generate the sheet again.');
}
if (!$lot_id) {
    die('Lot ID required.');
}

// Fetch lot
$lotStmt = $pdo->prepare("SELECT * FROM lots WHERE id = ? AND is_deleted = 0 LIMIT 1");
$lotStmt->execute([$lot_id]);
$lot = $lotStmt->fetch();
if (!$lot) die('Lot not found.');

// Fetch samples
$sStmt = $pdo->prepare("SELECT * FROM samples WHERE lot_id = ? AND is_deleted = 0 ORDER BY id ASC");
$sStmt->execute([$lot_id]);
$samples = $sStmt->fetchAll();

$lotNum  = $lot['lot_number'];
$lotDate = date('d/m/Y', strtotime($lot['lot_date'] ?? $lot['created_at']));
$lotType = strtoupper($lot['agency_type']);

// ── Data-driven columns (audit fix: no more blank columns) ──────
// The old sheet printed a fixed 8-column layout, so fields this lot
// never had (SD No on cheque lots, Mode/Remarks on online lots) came
// out as blank columns. Now each column is included only when at
// least one sample actually carries a value — and the Material
// Description / Customer Name confusion is fixed at the source:
// client_name holds the CUSTOMER since the intake upgrade, the
// material lives in extra_data['Material Description'].
$displayRows = [];
foreach ($samples as $i => $s) {
    $extra = json_decode($s['extra_data'] ?? '{}', true) ?: [];
    $material = trim((string)($extra['Material Description'] ?? ''));
    $customer = trim((string)($extra['Customer Name'] ?? ''));
    // Legacy rows: client_name used to store the material itself.
    if ($material === '') $material = trim((string)($s['client_name'] ?? ''));
    // Customer is only shown when it is real information (differs
    // from the material) — legacy rows suppress the column entirely.
    if ($customer !== '' && strcasecmp($customer, $material) === 0) $customer = '';
    $remarks = trim((string)($s['notes'] ?? ''));
    if ($remarks !== '' && $remarks[0] === '{') $remarks = '';   // electronics JSON never belongs in Remarks
    $displayRows[] = [
        'sr'     => $i + 1,
        'sd'     => trim((string)($s['sd_number'] ?? ''))        ?: trim((string)($extra['SD No'] ?? '')),
        'ref'    => trim((string)($s['reference_number'] ?? '')) ?: trim((string)($extra['Reference Number'] ?? '')),
        'req'    => trim((string)($s['request_number'] ?? ''))   ?: trim((string)($extra['Request No'] ?? '')),
        'desc'   => $material,
        'cust'   => $customer,
        'mode'   => strtoupper(trim((string)($s['delivery_type'] ?? '')) ?: trim((string)($extra['Mode'] ?? ''))),
        'rem'    => $remarks !== '' ? $remarks : trim((string)($extra['Remarks'] ?? '')),
    ];
}
// Column registry: [key, header, width(pt)]. sr/desc/write are always shown.
$colDefs = [
    ['sr',   'Sr. No.',                 45],
    ['sd',   'SD No.',                  90],
    ['ref',  'Reference No.',          100],
    ['req',  'Request No.',             90],
    ['desc', 'Material Description',   220],
    ['cust', 'Customer Name',          140],
    ['mode', 'Mode',                    70],
    ['rem',  'Remarks',                140],
];
$activeCols = [];
foreach ($colDefs as $def) {
    [$key, $header, $width] = $def;
    if ($key === 'sr' || $key === 'desc') { $activeCols[] = $def + ['always' => true]; continue; }
    $hasValue = false;
    foreach ($displayRows as $r) { if (trim((string)$r[$key]) !== '') { $hasValue = true; break; } }
    if ($hasValue) $activeCols[] = $def;
}
$activeCols[] = ['write', 'Written on Sample (✓/✗)', 120];   // always last, green
$colCount = count($activeCols);
$lastMerge = $colCount - 1;   // MergeAcross value for full-width rows

// Helper: XML-escape text + SEC-007 formula-injection neutralisation.
// Values that a spreadsheet could treat as a formula are prefixed with
// an apostrophe before escaping.
function x($s) { return ctms_export_cell($s); }

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="LotSheet_'.$lotNum.'.xls"');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">

<Styles>

  <!-- Main title — navy background, white bold text -->
  <Style ss:ID="MainTitle">
    <Font ss:FontName="Arial" ss:Size="14" ss:Bold="1" ss:Color="#FFFFFF"/>
    <Interior ss:Color="#1B2A4A" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    </Borders>
  </Style>

  <!-- Sub title -->
  <Style ss:ID="SubTitle">
    <Font ss:FontName="Arial" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/>
    <Interior ss:Color="#2D4270" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    </Borders>
  </Style>

  <!-- Metadata row (Lot Number / Type / Date / Total) -->
  <Style ss:ID="MetaLabel">
    <Font ss:FontName="Arial" ss:Size="10" ss:Bold="1" ss:Color="#1B2A4A"/>
    <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    </Borders>
  </Style>

  <!-- Instruction note row -->
  <Style ss:ID="Instructions">
    <Font ss:FontName="Arial" ss:Size="9" ss:Italic="1" ss:Color="#555555"/>
    <Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:WrapText="1"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    </Borders>
  </Style>

  <!-- Column header row -->
  <Style ss:ID="ColHeader">
    <Font ss:FontName="Arial" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>
    <Interior ss:Color="#1B2A4A" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    </Borders>
  </Style>

  <!-- "Written on sample" column header — green accent -->
  <Style ss:ID="ColHeaderGreen">
    <Font ss:FontName="Arial" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>
    <Interior ss:Color="#4A6741" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    </Borders>
  </Style>

  <!-- Data row — white background -->
  <Style ss:ID="DataCell">
    <Font ss:FontName="Arial" ss:Size="10"/>
    <Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:WrapText="1"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    </Borders>
  </Style>

  <!-- Data row — light blue alternate -->
  <Style ss:ID="DataCellAlt">
    <Font ss:FontName="Arial" ss:Size="10"/>
    <Interior ss:Color="#F5F8FF" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:WrapText="1"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    </Borders>
  </Style>

  <!-- Serial number cell — centered, bold -->
  <Style ss:ID="SerialCell">
    <Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    </Borders>
  </Style>

  <!-- Serial number cell (alt row) -->
  <Style ss:ID="SerialCellAlt">
    <Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/>
    <Interior ss:Color="#F5F8FF" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    </Borders>
  </Style>

  <!-- Written-on-sample checkbox cell — dashed border -->
  <Style ss:ID="WriteCell">
    <Font ss:FontName="Arial" ss:Size="10" ss:Italic="1" ss:Color="#BBBBBB"/>
    <Interior ss:Color="#FFFFF0" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Dash" ss:Weight="1" ss:Color="#AAAAAA"/>
      <Border ss:Position="Bottom" ss:LineStyle="Dash" ss:Weight="1" ss:Color="#AAAAAA"/>
      <Border ss:Position="Left" ss:LineStyle="Dash" ss:Weight="1" ss:Color="#AAAAAA"/>
      <Border ss:Position="Right" ss:LineStyle="Dash" ss:Weight="1" ss:Color="#AAAAAA"/>
    </Borders>
  </Style>

  <!-- Mode cell — centered uppercase -->
  <Style ss:ID="ModeCell">
    <Font ss:FontName="Arial" ss:Size="10"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    </Borders>
  </Style>
  <Style ss:ID="ModeCellAlt">
    <Font ss:FontName="Arial" ss:Size="10"/>
    <Interior ss:Color="#F5F8FF" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    </Borders>
  </Style>

  <!-- Summary total row -->
  <Style ss:ID="SummaryLabel">
    <Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/>
    <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="2"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="2"/>
    </Borders>
  </Style>
  <Style ss:ID="SummaryValue">
    <Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="2"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="2"/>
    </Borders>
  </Style>

  <!-- Footer -->
  <Style ss:ID="Footer">
    <Font ss:FontName="Arial" ss:Size="9" ss:Color="#666666"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#1B2A4A"/>
    </Borders>
  </Style>

</Styles>

<Worksheet ss:Name="Lot Sheet">
<Table>

  <!-- Column widths — one per active column, data-driven -->
<?php foreach ($activeCols as $def): ?>
  <Column ss:Width="<?= $def[2] ?>"/>
<?php endforeach; ?>

  <!-- Row 1: Main title (merged across all cols) -->
  <Row ss:Height="26">
    <Cell ss:MergeAcross="<?= $lastMerge ?>" ss:StyleID="MainTitle"><Data ss:Type="String">Department of Materials Engineering — Example University</Data></Cell>
  </Row>

  <!-- Row 2: Sub title -->
  <Row ss:Height="20">
    <Cell ss:MergeAcross="<?= $lastMerge ?>" ss:StyleID="SubTitle"><Data ss:Type="String">Material Testing Lab — Sample Lot Registration Sheet</Data></Cell>
  </Row>

  <!-- Row 3: Metadata — 4 cells spread EXACTLY across the active columns
       (audit fix: fixed MergeAcross=1 overflowed on narrower column sets
       and pushed "Total Samples" outside the table frame) -->
  <Row ss:Height="20">
<?php
    $metaPer  = max(1, (int)intdiv($colCount, 4));
    $metaLast = max(0, $colCount - 3 * $metaPer - 1);   // last cell absorbs the remainder
    $metaCells = [
        'Lot Number: ' . $lotNum,
        'Lot Type: ' . $lotType,
        'Date: ' . $lotDate,
        'Total Samples: ' . count($samples),
    ];
    foreach ($metaCells as $mi => $mtext) {
        $across = ($mi === 3) ? $metaLast : $metaPer - 1;
        echo '    <Cell ss:MergeAcross="' . $across . '" ss:StyleID="MetaLabel"><Data ss:Type="String">' . x($mtext) . '</Data></Cell>' . "\n";
    }
?>
  </Row>

  <!-- Row 4: Instructions -->
  <Row ss:Height="28">
    <Cell ss:MergeAcross="<?= $lastMerge ?>" ss:StyleID="Instructions"><Data ss:Type="String">INSTRUCTIONS FOR PEON: Write the Lot Number and Sr. No. on each physical sample using a marker.  |  For any query, contact the Mapping Officer (Technical Reviewer).</Data></Cell>
  </Row>

  <!-- Row 5: Blank spacer -->
  <Row ss:Height="6"></Row>

  <!-- Row 6: Column headers -->
  <Row ss:Height="32">
<?php foreach ($activeCols as $def): ?>
    <Cell ss:StyleID="<?= $def[0] === 'write' ? 'ColHeaderGreen' : 'ColHeader' ?>"><Data ss:Type="String"><?= x($def[1]) ?></Data></Cell>
<?php endforeach; ?>
  </Row>

  <!-- Data rows -->
  <?php foreach ($displayRows as $i => $r):
    $isAlt  = ($i % 2 === 1);
    $serialStyle = $isAlt ? 'SerialCellAlt' : 'SerialCell';
  ?>
  <Row ss:Height="20">
<?php foreach ($activeCols as $def):
    $key = $def[0];
    if ($key === 'sr'): ?>
    <Cell ss:StyleID="<?= $serialStyle ?>"><Data ss:Type="Number"><?= $r['sr'] ?></Data></Cell>
<?php elseif ($key === 'write'): ?>
    <Cell ss:StyleID="WriteCell"><Data ss:Type="String">✓ / ✗</Data></Cell>
<?php elseif ($key === 'mode'): ?>
    <Cell ss:StyleID="<?= $isAlt ? 'ModeCellAlt' : 'ModeCell' ?>"><Data ss:Type="String"><?= x($r['mode']) ?></Data></Cell>
<?php else: ?>
    <Cell ss:StyleID="<?= $isAlt ? 'DataCellAlt' : 'DataCell' ?>"><Data ss:Type="String"><?= x($r[$key]) ?></Data></Cell>
<?php endif;
endforeach; ?>
  </Row>
  <?php endforeach; ?>

  <!-- Blank spacer -->
  <Row ss:Height="10"></Row>

  <!-- Summary: Total Samples — spans fitted to the active columns -->
  <Row ss:Height="22">
<?php
    // 4 columns of width for label+value pairs; spacer absorbs the rest
    $sumSpacer = max(0, $colCount - 5);
    if ($sumSpacer > 0) echo '    <Cell ss:MergeAcross="' . ($sumSpacer - 1) . '"></Cell>' . "\n";
?>
    <Cell ss:MergeAcross="1" ss:StyleID="SummaryLabel"><Data ss:Type="String">Total Samples:</Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="SummaryValue"><Data ss:Type="Number"><?= count($samples) ?></Data></Cell>
  </Row>

  <!-- Blank spacer -->
  <Row ss:Height="8"></Row>

  <!-- Footer -->
  <Row ss:Height="20">
    <Cell ss:MergeAcross="<?= $lastMerge ?>" ss:StyleID="Footer"><Data ss:Type="String">Generated by CTMS Core v3  |  <?= date('d-M-Y H:i') ?>  |  Lot: <?= x($lotNum) ?>  |  Department of Materials Engineering, Example University</Data></Cell>
  </Row>

</Table>

<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
  <PageSetup>
    <Layout x:Orientation="Landscape"/>
    <Header x:Margin="0.3"/>
    <Footer x:Margin="0.3"/>
    <PageMargins x:Bottom="0.5" x:Left="0.4" x:Right="0.4" x:Top="0.5"/>
  </PageSetup>
  <Print>
    <FitWidth>1</FitWidth>
    <FitHeight>0</FitHeight>
  </Print>
</WorksheetOptions>

</Worksheet>
</Workbook>
