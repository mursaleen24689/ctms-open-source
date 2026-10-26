<?php
// ============================================================
// api/generate_invoice.php — Printable Invoice (HTML or Excel)
//
// GET /generate_invoice.php?id=INVOICE_ID&token=T[&format=html|xls]
// format=html (default) — opens in browser, ready to Ctrl+P print
// format=xls — SpreadsheetML download for Excel
// ============================================================
require_once 'db.php';

// A-2: POST issues a one-time ticket; GET accepts only that ticket.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    $u = require_auth();
    if (ctms_user_lot_access_restricted($pdo, (int)$u['user_id'])) { http_response_code(403); echo json_encode(['error' => 'Global invoice exports are unavailable to accounts limited to selected lots.']); exit; }
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id   = (int)($body['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }
    $ticket = issue_download_ticket($pdo, (int)$u['user_id'], 'invoice', $id);
    echo json_encode(['success' => true, 'ticket' => $ticket,
                      'url' => "generate_invoice.php?id=$id&format=" . urlencode($body['format'] ?? 'html') . "&ticket=$ticket"]);
    exit;
}
$id    = (int)($_GET['id'] ?? 0);
$format = strtolower($_GET['format'] ?? 'html');
$tRaw = $_GET['ticket'] ?? '';
if ($tRaw !== '') {
    $t = validate_download_ticket($pdo, $tRaw, 'invoice');
    if (empty($t['ok']) || (int)$t['scope_id'] !== $id) {
        http_response_code(403);
        die('Download link expired or already used. Please generate the invoice again.');
    }
    if (ctms_user_lot_access_restricted($pdo, (int)$t['user_id'])) { http_response_code(403); die('Global invoice exports are unavailable to accounts limited to selected lots.'); }
    ctms_require_role(['superadmin', 'admin', 'hod', 'deo'], $t,
        'Invoice exports are restricted to authorized finance roles.');
} else {
    http_response_code(401);
    die('A valid one-time download link is required. Please generate the invoice again.');
}
if (!$id) {
    http_response_code(400);
    die('Invoice ID required.');
}

function getSetting($pdo, $key, $default = '') {
    $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
    $s->execute([$key]);
    $r = $s->fetch();
    return $r ? $r['setting_value'] : $default;
}

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

function x($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }
// SEC-007: spreadsheet-safe escaping for the SpreadsheetML export only.
// (The printable HTML invoice above uses x() — HTML needs no formula guard.)
function xs($s) { return ctms_export_cell($s); }
function money($n) { return number_format((float)$n, 2); }

$stmt = $pdo->prepare("
    SELECT i.*, u.full_name AS created_by_name
    FROM invoices i
    LEFT JOIN users u ON u.id = i.created_by
    WHERE i.id = ? AND i.is_deleted = 0
    LIMIT 1
");
$stmt->execute([$id]);
$inv = $stmt->fetch();
if (!$inv) die('Invoice not found.');

$itemStmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order, id");
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll();

$labName    = getSetting($pdo, 'lab_name', 'Department of Materials Engineering, Example Technical University');
$labAddress = getSetting($pdo, 'lab_address', 'Example University, Example Street, Example City, Example Country');
$issuedDate = $inv['issued_at'] ? date('d-M-Y', strtotime($inv['issued_at'])) : date('d-M-Y', strtotime($inv['created_at']));
$dueDate    = $inv['due_date'] ? date('d-M-Y', strtotime($inv['due_date'])) : '—';
$grandTotal = (float)$inv['grand_total'];
$words      = amountInWords($grandTotal);

// ── HTML printable invoice ────────────────────────────────────
if ($format === 'html') {
    header('Content-Type: text/html; charset=UTF-8');
    $statusColor = $inv['status'] === 'paid' ? '#1a7a6d' : ($inv['status'] === 'cancelled' ? '#8c2f24' : '#c2703d');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<title>Invoice <?= x($inv['inv_number']) ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, Helvetica, sans-serif; font-size: 13px; color: #1b1e24; background: #f7f5f1; padding: 24px; }
  .page { max-width: 820px; margin: 0 auto; background: #fff; border: 1px solid #e5e0d8; box-shadow: 0 8px 32px rgba(0,0,0,.08); }
  .head { background: #1b2a4a; color: #fff; padding: 22px 28px; }
  .head h1 { font-size: 18px; letter-spacing: .3px; }
  .head p { font-size: 11px; opacity: .85; margin-top: 4px; line-height: 1.5; }
  .meta { display: flex; justify-content: space-between; padding: 20px 28px; border-bottom: 2px solid #1b2a4a; gap: 20px; flex-wrap: wrap; }
  .meta-block h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .8px; color: #786f63; margin-bottom: 6px; }
  .meta-block .big { font-size: 20px; font-weight: 700; font-family: 'Courier New', monospace; color: #1b2a4a; }
  .meta-block div { margin-top: 3px; }
  .status { display: inline-block; padding: 4px 12px; border-radius: 4px; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #fff; background: <?= $statusColor ?>; margin-top: 8px; }
  .bill-to { padding: 18px 28px; background: #fbfaf8; border-bottom: 1px solid #e5e0d8; }
  .bill-to strong { display: block; font-size: 11px; text-transform: uppercase; color: #786f63; margin-bottom: 6px; }
  .bill-to .co { font-size: 16px; font-weight: 700; }
  table.items { width: 100%; border-collapse: collapse; }
  table.items th { background: #1b2a4a; color: #fff; padding: 10px 12px; font-size: 11px; text-transform: uppercase; text-align: left; }
  table.items th.r, table.items td.r { text-align: right; }
  table.items td { padding: 9px 12px; border-bottom: 1px solid #e5e0d8; vertical-align: top; }
  table.items tr:nth-child(even) td { background: #f5f8ff; }
  .totals { padding: 16px 28px 24px; display: flex; justify-content: flex-end; }
  .totals-box { width: 320px; }
  .totals-box div { display: flex; justify-content: space-between; padding: 5px 0; font-size: 13px; }
  .totals-box .grand { border-top: 2px solid #1b2a4a; margin-top: 8px; padding-top: 10px; font-size: 16px; font-weight: 700; color: #1a7a6d; }
  .words { padding: 0 28px 16px; font-size: 12px; color: #555; font-style: italic; }
  .words strong { color: #1b1e24; font-style: normal; }
  .payment { margin: 0 28px 20px; padding: 12px 16px; background: #e8f5f3; border-left: 4px solid #1a7a6d; font-size: 12px; }
  .notes { padding: 0 28px 16px; font-size: 12px; color: #555; }
  .footer { padding: 16px 28px; border-top: 2px solid #1b2a4a; font-size: 10px; color: #786f63; text-align: center; }
  .sig { padding: 24px 28px 32px; display: flex; justify-content: space-between; gap: 40px; }
  .sig div { flex: 1; border-top: 1px solid #1b1e24; padding-top: 8px; font-size: 11px; text-align: center; color: #786f63; }
  @media print {
    body { background: #fff; padding: 0; }
    .page { box-shadow: none; border: none; max-width: 100%; }
    .no-print { display: none !important; }
  }
</style>
</head>
<body>
<div class="no-print" style="max-width:820px;margin:0 auto 12px;text-align:right;">
  <button onclick="window.print()" style="padding:10px 20px;background:#c2703d;color:#fff;border:none;border-radius:6px;font-weight:700;cursor:pointer;">Print Invoice</button>
</div>
<div class="page">
  <div class="head">
    <h1><?= x($labName) ?></h1>
    <p><?= x($labAddress) ?><br/>Commercial Testing &amp; Related Services</p>
  </div>

  <div class="meta">
    <div class="meta-block">
      <h2>Tax Invoice</h2>
      <div class="big"><?= x($inv['inv_number']) ?></div>
      <span class="status"><?= x(strtoupper($inv['status'])) ?></span>
    </div>
    <div class="meta-block" style="text-align:right;">
      <h2>Dates</h2>
      <div><strong>Issued:</strong> <?= x($issuedDate) ?></div>
      <div><strong>Due:</strong> <?= x($dueDate) ?></div>
      <div style="margin-top:6px;font-size:11px;color:#786f63;">Prepared by: <?= x($inv['created_by_name'] ?? '—') ?></div>
    </div>
  </div>

  <div class="bill-to">
    <strong>Bill To</strong>
    <div class="co"><?= x($inv['company_name']) ?></div>
    <?php if ($inv['contact_person']): ?><div style="margin-top:4px;">Attn: <?= x($inv['contact_person']) ?></div><?php endif; ?>
  </div>

  <table class="items">
    <thead>
      <tr>
        <th style="width:36px;">#</th>
        <th>Description</th>
        <th class="r" style="width:60px;">Qty</th>
        <th class="r" style="width:100px;">Unit Price</th>
        <th class="r" style="width:110px;">Amount (Rs.)</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $i => $item):
        $price = isset($item['override_price']) && $item['override_price'] !== null && $item['override_price'] !== ''
            ? (float)$item['override_price'] : (float)$item['unit_price'];
      ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= x($item['description']) ?><?php if ($item['service_code']): ?><br/><span style="font-size:10px;color:#786f63;">Code: <?= x($item['service_code']) ?></span><?php endif; ?></td>
        <td class="r"><?= x($item['quantity']) ?></td>
        <td class="r"><?= money($price) ?></td>
        <td class="r"><?= money($item['line_total']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="totals">
    <div class="totals-box">
      <div><span>Subtotal</span><span>Rs. <?= money($inv['subtotal']) ?></span></div>
      <?php if ((float)$inv['discount_amount'] > 0): ?>
      <div style="color:#8c2f24;"><span>Discount<?= $inv['discount_reason'] ? ' ('.x($inv['discount_reason']).')' : '' ?></span><span>− Rs. <?= money($inv['discount_amount']) ?></span></div>
      <?php endif; ?>
      <div><span>Service Tax (<?= money($inv['service_tax_rate']) ?>%)</span><span>Rs. <?= money($inv['service_tax_amount']) ?></span></div>
      <div class="grand"><span>Grand Total</span><span>Rs. <?= money($grandTotal) ?></span></div>
    </div>
  </div>

  <div class="words"><strong>Amount in words:</strong> Rupees <?= x($words) ?></div>

  <?php if ($inv['status'] === 'paid'): ?>
  <div class="payment">
    <strong>Payment received</strong><br/>
    Method: <?= x(str_replace('_', ' ', $inv['payment_method'] ?? '—')) ?>
    &nbsp;|&nbsp; Reference: <?= x($inv['payment_reference'] ?? '—') ?>
    &nbsp;|&nbsp; Date: <?= x($inv['payment_date'] ? date('d-M-Y', strtotime($inv['payment_date'])) : '—') ?>
    <?php if ($inv['payment_notes']): ?><br/><?= x($inv['payment_notes']) ?><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($inv['notes']): ?><div class="notes"><strong>Notes:</strong> <?= x($inv['notes']) ?></div><?php endif; ?>

  <div class="sig">
    <div>Authorized Signature — Accounts</div>
    <div>Client Acknowledgement</div>
  </div>

  <div class="footer">
    Generated by CTMS Core &nbsp;|&nbsp; <?= date('d-M-Y H:i') ?> &nbsp;|&nbsp; This is a computer-generated invoice.
  </div>
</div>
</body>
</html>
    <?php
    exit();
}

// ── SpreadsheetML Excel export ────────────────────────────────
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="Invoice_'.x($inv['inv_number']).'.xls"');
header('Cache-Control: no-cache, must-revalidate');

echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
<Styles>
  <Style ss:ID="Title"><Font ss:Bold="1" ss:Size="14" ss:Color="#FFFFFF"/><Interior ss:Color="#1B2A4A" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center"/></Style>
  <Style ss:ID="Hdr"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1B2A4A" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center"/></Style>
  <Style ss:ID="Cell"><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/></Borders></Style>
  <Style ss:ID="Bold"><Font ss:Bold="1"/></Style>
</Styles>
<Worksheet ss:Name="Invoice">
<Table>
  <Column ss:Width="200"/><Column ss:Width="120"/><Column ss:Width="120"/>
  <Row ss:Height="24"><Cell ss:MergeAcross="4" ss:StyleID="Title"><Data ss:Type="String"><?= xs($labName) ?> — INVOICE</Data></Cell></Row>
  <Row><Cell ss:StyleID="Bold"><Data ss:Type="String">Invoice No.</Data></Cell><Cell><Data ss:Type="String"><?= xs($inv['inv_number']) ?></Data></Cell></Row>
  <Row><Cell ss:StyleID="Bold"><Data ss:Type="String">Company</Data></Cell><Cell><Data ss:Type="String"><?= xs($inv['company_name']) ?></Data></Cell></Row>
  <Row><Cell ss:StyleID="Bold"><Data ss:Type="String">Issued</Data></Cell><Cell><Data ss:Type="String"><?= xs($issuedDate) ?></Data></Cell></Row>
  <Row><Cell ss:StyleID="Bold"><Data ss:Type="String">Status</Data></Cell><Cell><Data ss:Type="String"><?= xs(strtoupper($inv['status'])) ?></Data></Cell></Row>
  <Row ss:Height="8"></Row>
  <Row>
    <Cell ss:StyleID="Hdr"><Data ss:Type="String">Description</Data></Cell>
    <Cell ss:StyleID="Hdr"><Data ss:Type="String">Qty</Data></Cell>
    <Cell ss:StyleID="Hdr"><Data ss:Type="String">Line Total (Rs.)</Data></Cell>
  </Row>
  <?php foreach ($items as $item): ?>
  <Row>
    <Cell ss:StyleID="Cell"><Data ss:Type="String"><?= xs($item['description']) ?></Data></Cell>
    <Cell ss:StyleID="Cell"><Data ss:Type="Number"><?= (float)$item['quantity'] ?></Data></Cell>
    <Cell ss:StyleID="Cell"><Data ss:Type="Number"><?= (float)$item['line_total'] ?></Data></Cell>
  </Row>
  <?php endforeach; ?>
  <Row ss:Height="8"></Row>
  <Row><Cell ss:StyleID="Bold"><Data ss:Type="String">Grand Total (Rs.)</Data></Cell><Cell/><Cell ss:StyleID="Bold"><Data ss:Type="Number"><?= $grandTotal ?></Data></Cell></Row>
  <Row><Cell ss:MergeAcross="2"><Data ss:Type="String">Amount in words: Rupees <?= xs($words) ?></Data></Cell></Row>
</Table>
</Worksheet>
</Workbook>
