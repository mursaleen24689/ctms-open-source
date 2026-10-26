<?php
// ============================================================
// api/generate_cheques.php — Cheque Submission Sheet (SpreadsheetML)
//
// Called through a short-lived, one-time download ticket.
//
// Produces a true Excel 2003 XML file matching the exact
// format of the uploaded submission sheets — title row with
// sheet number, account info, column headers, data rows,
// totals, summary, amount in words, signature line.
// ============================================================
require_once 'db.php';

// A-2: POST issues a one-time ticket; GET accepts only that ticket.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    $u = require_auth();
    if (ctms_user_lot_access_restricted($pdo, (int)$u['user_id'])) { http_response_code(403); echo json_encode(['error' => 'Global cheque exports are unavailable to accounts limited to selected lots.']); exit; }
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $ticket = issue_download_ticket($pdo, (int)$u['user_id'], 'cheques', null);
    echo json_encode(['success' => true, 'ticket' => $ticket,
                      'url' => "generate_cheques.php?type=" . urlencode($body['type'] ?? 'hmbl')
                             . "&date=" . urlencode($body['date'] ?? date('Y-m-d')) . "&ticket=$ticket"]);
    exit;
}
$tRaw = $_GET['ticket'] ?? '';
if ($tRaw !== '') {
    $t = validate_download_ticket($pdo, $tRaw, 'cheques');
    if (empty($t['ok'])) die('Download link expired or already used. Please generate the sheet again.');
    if (ctms_user_lot_access_restricted($pdo, (int)$t['user_id'])) { http_response_code(403); die('Global cheque exports are unavailable to accounts limited to selected lots.'); }
    ctms_require_role(['superadmin', 'admin'], $t, 'Cheque exports are restricted to administrators.');
} else {
    http_response_code(401);
    die('A valid one-time download link is required. Please generate the sheet again.');
}

$type = strtolower($_GET['type'] ?? 'hmbl');
$date = $_GET['date'] ?? date('Y-m-d');

// Fetch cheques for this type and date
$stmt = $pdo->prepare("
    SELECT * FROM cheque_records
    WHERE cheque_type = ? AND entry_date = ? AND is_deleted = 0
    ORDER BY sheet_no ASC, serial_no ASC
");
$stmt->execute([$type, $date]);
$cheques = $stmt->fetchAll();

// Group by sheet number
$sheets = [];
foreach ($cheques as $c) {
    $sheets[(int)$c['sheet_no']][] = $c;
}
ksort($sheets);
if (empty($sheets)) $sheets[1] = []; // always at least one sheet

$grandTotal = array_sum(array_column($cheques, 'amount'));
$dispDate   = date('d/m/Y', strtotime($date));

$acctName = 'Commercial Testing & Other Related Services At Example University- D-CPE';
$acctNo   = '06-99-72-29314-714-194109';
// SEC-016: prefer the account details configured in system_settings so
// they are not hardcoded in source. The literals above remain only as a
// fallback so the printed cheque sheet is never blank on an unseeded DB.
try {
    $acctRows = $pdo->query("SELECT setting_key, setting_value FROM system_settings
                             WHERE setting_key IN ('cheque_account_name','cheque_account_no')")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($acctRows['cheque_account_name'])) $acctName = $acctRows['cheque_account_name'];
    if (!empty($acctRows['cheque_account_no']))   $acctNo   = $acctRows['cheque_account_no'];
} catch (\PDOException $e) { /* keep defaults if settings table/keys absent */ }

// ── Amount in words ────────────────────────────────────────────
function amountInWords($n) {
    $n = (int)round($n);
    if ($n === 0) return 'ZERO ONLY';
    $ones = ['','ONE','TWO','THREE','FOUR','FIVE','SIX','SEVEN','EIGHT','NINE',
             'TEN','ELEVEN','TWELVE','THIRTEEN','FOURTEEN','FIFTEEN','SIXTEEN',
             'SEVENTEEN','EIGHTEEN','NINETEEN'];
    $tens = ['','','TWENTY','THIRTY','FORTY','FIFTY','SIXTY','SEVENTY','EIGHTY','NINETY'];
    $tw = function($num) use (&$tw, $ones, $tens) {
        if ($num < 20) return $ones[$num];
        if ($num < 100) return $tens[intdiv($num,10)].($num%10 ? ' '.$ones[$num%10] : '');
        if ($num < 1000) return $ones[intdiv($num,100)].' HUNDRED'.($num%100 ? ' '.$tw($num%100) : '');
        if ($num < 100000) return $tw(intdiv($num,1000)).' THOUSAND'.($num%1000 ? ' '.$tw($num%1000) : '');
        if ($num < 10000000) return $tw(intdiv($num,100000)).' LAC'.($num%100000 ? ' '.$tw($num%100000) : '');
        return $tw(intdiv($num,10000000)).' CRORE'.($num%10000000 ? ' '.$tw($num%10000000) : '');
    };
    return $tw($n).' ONLY';
}

// SEC-007: spreadsheet-safe escaping (neutralises formula triggers).
function x($s) { return ctms_export_cell($s); }

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="Cheques_'.strtoupper($type).'_'.$date.'.xls"');
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

  <!-- Title row: "Submission of Cheques · Sheet No.X" -->
  <Style ss:ID="TitleRow">
    <Font ss:FontName="Arial" ss:Size="13" ss:Bold="1"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    </Borders>
  </Style>

  <!-- Account info rows -->
  <Style ss:ID="InfoLabel">
    <Font ss:FontName="Arial" ss:Size="9" ss:Color="#555555"/>
    <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    </Borders>
  </Style>
  <Style ss:ID="InfoValue">
    <Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/>
    <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    </Borders>
  </Style>
  <Style ss:ID="InfoValueRight">
    <Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/>
    <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    </Borders>
  </Style>

  <!-- Column headers — navy background -->
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
  <Style ss:ID="ColHeaderRight">
    <Font ss:FontName="Arial" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>
    <Interior ss:Color="#1B2A4A" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Right" ss:Vertical="Center" ss:WrapText="1"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    </Borders>
  </Style>

  <!-- Data rows -->
  <Style ss:ID="DataSerial">
    <Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
    </Borders>
  </Style>
  <Style ss:ID="DataSerialAlt">
    <Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/>
    <Interior ss:Color="#F5F8FF" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
    </Borders>
  </Style>

  <Style ss:ID="DataCell">
    <Font ss:FontName="Arial" ss:Size="10"/>
    <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
    </Borders>
  </Style>
  <Style ss:ID="DataCellAlt">
    <Font ss:FontName="Arial" ss:Size="10"/>
    <Interior ss:Color="#F5F8FF" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
    </Borders>
  </Style>

  <Style ss:ID="DataCellCenter">
    <Font ss:FontName="Arial" ss:Size="10"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
    </Borders>
  </Style>
  <Style ss:ID="DataCellCenterAlt">
    <Font ss:FontName="Arial" ss:Size="10"/>
    <Interior ss:Color="#F5F8FF" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
    </Borders>
  </Style>

  <!-- Amount column -->
  <Style ss:ID="AmountCell">
    <Font ss:FontName="Arial" ss:Size="10" ss:Bold="1" ss:Color="#059669"/>
    <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
    <NumberFormat ss:Format="#,##0"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
    </Borders>
  </Style>
  <Style ss:ID="AmountCellAlt">
    <Font ss:FontName="Arial" ss:Size="10" ss:Bold="1" ss:Color="#059669"/>
    <Interior ss:Color="#F5F8FF" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
    <NumberFormat ss:Format="#,##0"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#999999"/>
    </Borders>
  </Style>

  <!-- Total row (per sheet) -->
  <Style ss:ID="TotalLabel">
    <Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/>
    <Interior ss:Color="#E8F0FE" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="2"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="2"/>
    </Borders>
  </Style>
  <Style ss:ID="TotalValue">
    <Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/>
    <Interior ss:Color="#E8F0FE" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
    <NumberFormat ss:Format="#,##0"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="2"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="2"/>
    </Borders>
  </Style>

  <!-- Summary section -->
  <Style ss:ID="SummaryHeader">
    <Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/>
    <Interior ss:Color="#F0F0F0" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    </Borders>
  </Style>
  <Style ss:ID="SummaryRow">
    <Font ss:FontName="Arial" ss:Size="10"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    </Borders>
  </Style>
  <Style ss:ID="SummaryAmount">
    <Font ss:FontName="Arial" ss:Size="10"/>
    <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
    <NumberFormat ss:Format="#,##0"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    </Borders>
  </Style>

  <!-- Grand total -->
  <Style ss:ID="GrandTotalLabel">
    <Font ss:FontName="Arial" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/>
    <Interior ss:Color="#1B2A4A" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="2"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="2"/>
    </Borders>
  </Style>
  <Style ss:ID="GrandTotalValue">
    <Font ss:FontName="Arial" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/>
    <Interior ss:Color="#1B2A4A" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
    <NumberFormat ss:Format="#,##0"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="2"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="2"/>
    </Borders>
  </Style>

  <!-- Amount in words row -->
  <Style ss:ID="WordsRow">
    <Font ss:FontName="Arial" ss:Size="10" ss:Bold="1" ss:Italic="1"/>
    <Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:WrapText="1"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    </Borders>
  </Style>

  <!-- Signature row -->
  <Style ss:ID="SigRow">
    <Font ss:FontName="Arial" ss:Size="10"/>
    <Alignment ss:Horizontal="Left" ss:Vertical="Bottom"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    </Borders>
  </Style>

</Styles>

<?php
$lastSheetNo = max(array_keys($sheets));
foreach ($sheets as $sheetNo => $rows):
  $sheetTotal = array_sum(array_column($rows, 'amount'));
  $isLast     = ($sheetNo === $lastSheetNo);
?>
<Worksheet ss:Name="Sheet<?= $sheetNo ?>">
<Table>

  <!-- Column widths -->
  <Column ss:Width="35"/>   <!-- S.# -->
  <Column ss:Width="100"/>  <!-- Cheque Nos. -->
  <Column ss:Width="80"/>   <!-- Cheque Date -->
  <Column ss:Width="220"/>  <!-- Bank Name -->
  <Column ss:Width="100"/>  <!-- Amount -->
  <Column ss:Width="160"/>  <!-- Remarks -->

  <!-- Row 1: Title with sheet number (merged A:F) -->
  <Row ss:Height="26">
    <Cell ss:MergeAcross="5" ss:StyleID="TitleRow"><Data ss:Type="String">Submission of Cheques                              Sheet No.<?= $sheetNo ?></Data></Cell>
  </Row>

  <!-- Row 2: Title of Account -->
  <Row ss:Height="20">
    <Cell ss:MergeAcross="1" ss:StyleID="InfoLabel"><Data ss:Type="String">Title of Account:</Data></Cell>
    <Cell ss:MergeAcross="3" ss:StyleID="InfoValue"><Data ss:Type="String"><?= x($acctName) ?></Data></Cell>
  </Row>

  <!-- Row 3: Account No + Date -->
  <Row ss:Height="20">
    <Cell ss:MergeAcross="1" ss:StyleID="InfoLabel"><Data ss:Type="String">Account No.:</Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="InfoValue"><Data ss:Type="String"><?= x($acctNo) ?></Data></Cell>
    <Cell ss:StyleID="InfoLabel"><Data ss:Type="String">Date:</Data></Cell>
    <Cell ss:StyleID="InfoValueRight"><Data ss:Type="String"><?= x($dispDate) ?></Data></Cell>
  </Row>

  <!-- Blank spacer -->
  <Row ss:Height="6"></Row>

  <!-- Column headers -->
  <Row ss:Height="26">
    <Cell ss:StyleID="ColHeader"><Data ss:Type="String">S.#</Data></Cell>
    <Cell ss:StyleID="ColHeader"><Data ss:Type="String">Cheque Nos.</Data></Cell>
    <Cell ss:StyleID="ColHeader"><Data ss:Type="String">Cheque Date</Data></Cell>
    <Cell ss:StyleID="ColHeader"><Data ss:Type="String">Bank Name</Data></Cell>
    <Cell ss:StyleID="ColHeaderRight"><Data ss:Type="String">Amount</Data></Cell>
    <Cell ss:StyleID="ColHeader"><Data ss:Type="String">Remarks</Data></Cell>
  </Row>

  <!-- Data rows -->
  <?php foreach ($rows as $i => $r):
    $isAlt = ($i % 2 === 1);
    $serialStyle = $isAlt ? 'DataSerialAlt' : 'DataSerial';
    $cellStyle   = $isAlt ? 'DataCellAlt'   : 'DataCell';
    $centerStyle = $isAlt ? 'DataCellCenterAlt' : 'DataCellCenter';
    $amountStyle = $isAlt ? 'AmountCellAlt' : 'AmountCell';
  ?>
  <Row ss:Height="20">
    <Cell ss:StyleID="<?= $serialStyle ?>"><Data ss:Type="Number"><?= (int)$r['serial_no'] ?></Data></Cell>
    <Cell ss:StyleID="<?= $cellStyle ?>"><Data ss:Type="String"><?= x($r['cheque_no']) ?></Data></Cell>
    <Cell ss:StyleID="<?= $centerStyle ?>"><Data ss:Type="String"><?= x(date('d/m/y', strtotime($r['cheque_date']))) ?></Data></Cell>
    <Cell ss:StyleID="<?= $cellStyle ?>"><Data ss:Type="String"><?= x(strtoupper($r['bank_name'])) ?></Data></Cell>
    <Cell ss:StyleID="<?= $amountStyle ?>"><Data ss:Type="Number"><?= (float)$r['amount'] ?></Data></Cell>
    <Cell ss:StyleID="<?= $cellStyle ?>"><Data ss:Type="String"><?= x($r['remarks'] ?? '') ?></Data></Cell>
  </Row>
  <?php endforeach; ?>

  <!-- Total row for this sheet -->
  <Row ss:Height="22">
    <Cell ss:MergeAcross="3" ss:StyleID="TotalLabel"><Data ss:Type="String">Total<?= $isLast ? ' Amount:' : '' ?></Data></Cell>
    <Cell ss:StyleID="TotalValue"><Data ss:Type="Number"><?= (float)$sheetTotal ?></Data></Cell>
    <Cell ss:StyleID="TotalLabel"><Data ss:Type="String"></Data></Cell>
  </Row>

  <?php if ($isLast): ?>
  <!-- Blank spacer -->
  <Row ss:Height="10"></Row>

  <!-- Summary header -->
  <Row ss:Height="22">
    <Cell ss:StyleID="SummaryHeader"><Data ss:Type="String"></Data></Cell>
    <Cell ss:MergeAcross="2" ss:StyleID="SummaryHeader"><Data ss:Type="String">Summary / Detail All Sheets</Data></Cell>
    <Cell ss:StyleID="SummaryHeader"><Data ss:Type="String">Amount</Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="SummaryHeader"><Data ss:Type="String"></Data></Cell>
  </Row>

  <!-- Per-sheet subtotals -->
  <?php foreach ($sheets as $sn => $sRows):
    $sTotal = array_sum(array_column($sRows, 'amount'));
  ?>
  <Row ss:Height="20">
    <Cell ss:StyleID="SummaryRow"><Data ss:Type="String"></Data></Cell>
    <Cell ss:MergeAcross="2" ss:StyleID="SummaryRow"><Data ss:Type="String">Sheet No.<?= $sn ?></Data></Cell>
    <Cell ss:StyleID="SummaryAmount"><Data ss:Type="Number"><?= (float)$sTotal ?></Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="SummaryRow"><Data ss:Type="String"></Data></Cell>
  </Row>
  <?php endforeach; ?>

  <!-- Grand total -->
  <Row ss:Height="24">
    <Cell ss:StyleID="GrandTotalLabel"><Data ss:Type="String"></Data></Cell>
    <Cell ss:MergeAcross="2" ss:StyleID="GrandTotalLabel"><Data ss:Type="String">Gross Total (All Sheets):</Data></Cell>
    <Cell ss:StyleID="GrandTotalValue"><Data ss:Type="Number"><?= (float)$grandTotal ?></Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="GrandTotalLabel"><Data ss:Type="String"></Data></Cell>
  </Row>

  <!-- Blank spacer -->
  <Row ss:Height="10"></Row>

  <!-- Amount in words -->
  <Row ss:Height="24">
    <Cell ss:MergeAcross="5" ss:StyleID="WordsRow"><Data ss:Type="String">Rupees In Words: <?= x(amountInWords($grandTotal)) ?></Data></Cell>
  </Row>

  <!-- Blank spacer -->
  <Row ss:Height="20"></Row>

  <!-- Depositor signature -->
  <Row ss:Height="26">
    <Cell ss:Index="3" ss:MergeAcross="2" ss:StyleID="SigRow"><Data ss:Type="String">Depositor's Signature: ___________________</Data></Cell>
  </Row>
  <?php endif; ?>

</Table>
</Worksheet>
<?php endforeach; ?>

</Workbook>
