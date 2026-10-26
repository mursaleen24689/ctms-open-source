<?php
declare(strict_types=1);
/**
 * Invoices — workflow step 1.
 *
 * The company named here flows automatically into every downstream record:
 * the sample, the test job, and the printed report. The client types it once.
 *
 * An invoice line is what the client is paying for. Each line carries a test
 * type and a sample count, and is what the "Add samples" button turns into
 * actual samples in the next workflow step.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/engine.php';

$u = require_login();
$action = (string)get('action', 'list');
$id     = (int)get('id', 0);
$canCreateInvoice = can_create_consult_invoice();
$canSeeBilling = $canCreateInvoice || can_user('superadmin', 'admin', 'professor');

// Hide authoring routes as well as the button. A typed URL is not permission.
if (in_array($action, ['new', 'edit'], true) && !$canCreateInvoice) {
    http_response_code(403);
    include __DIR__ . '/inc/layout_top.php';
    echo '<div class="card"><h2>Invoice creation is not assigned to your account</h2>'
       . '<p>You can view the test order and continue sample testing and reporting. Ask a CTMS administrator if you need invoice creation permission.</p>'
       . '<p><a class="btn" href="' . h(app_url('invoices.php')) . '">View invoice details</a></p></div>';
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
}

/** Find or create a client from a typed company name. */
function ensure_client(string $company, array $extra = []): int {
    $company = trim($company);
    $st = $GLOBALS['pdo']->prepare('SELECT id FROM c_clients WHERE company_name = ? LIMIT 1');
    $st->execute([$company]);
    $found = $st->fetchColumn();
    if ($found) return (int)$found;
    $st = $GLOBALS['pdo']->prepare(
        'INSERT INTO c_clients (company_name, contact_person, phone, email, address) VALUES (?,?,?,?,?)');
    $st->execute([$company, $extra['contact_person'] ?? null, $extra['phone'] ?? null,
                  $extra['email'] ?? null, $extra['address'] ?? null]);
    $newId = (int)$GLOBALS['pdo']->lastInsertId();
    audit('CLIENT_CREATED', 'clients', $newId, null, ['company_name' => $company], null);
    return $newId;
}

/** Recalculate and store the money totals from the lines. */
function recalc_invoice(int $invoiceId): array {
    $pdo = $GLOBALS['pdo'];
    $st = $pdo->prepare('SELECT SUM(line_total) FROM c_invoice_lines WHERE invoice_id = ?');
    $st->execute([$invoiceId]);
    $subtotal = (float)$st->fetchColumn();

    $st = $pdo->prepare('SELECT tax_rate, discount_amount FROM c_billing WHERE id = ?');
    $st->execute([$invoiceId]);
    $inv = $st->fetch() ?: ['tax_rate' => 0, 'discount_amount' => 0];

    $taxable = max(0, $subtotal - (float)$inv['discount_amount']);
    $tax     = round($taxable * (float)$inv['tax_rate'] / 100, 2);
    $grand   = round($taxable + $tax, 2);

    $pdo->prepare('UPDATE c_billing SET subtotal = ?, tax_amount = ?, grand_total = ?, updated_at = NOW()
                    WHERE id = ?')->execute([$subtotal, $tax, $grand, $invoiceId]);
    return ['subtotal' => $subtotal, 'tax' => $tax, 'grand' => $grand];
}

// ════════════════════════════════════════════════════════════════════════════
//  SAVE  (create or update)
// ════════════════════════════════════════════════════════════════════════════
if (is_post() && post('do') === 'save') {
    if (!$canCreateInvoice) {
        http_response_code(403);
        flash('Invoice creation is not assigned to your account.', 'err');
        redirect('invoices.php');
    }
    $invId     = (int)post('id', 0);
    $company   = trim((string)post('company_name', ''));
    $contact   = trim((string)post('contact_person', ''));
    $invDate   = (string)post('invoice_date', today());
    $dueDate   = trim((string)post('due_date', ''));
    $taxRate   = (float)post('tax_rate', 13);
    $discount  = (float)post('discount_amount', 0);
    // Only management can grant discounts
    if ($discount > 0 && !in_array($u['role'], ['superadmin', 'admin', 'professor'], true)) {
        $discount = 0.0;
    }
    $notes     = trim((string)post('notes', ''));
    $lines     = post('lines', []);

    $errors = [];
    if ($company === '')  $errors[] = 'The company name is required — it is printed on every report.';
    if (!$invDate)        $errors[] = 'An invoice date is required.';
    if ($discount < 0)    $errors[] = 'The discount cannot be negative.';
    if ($taxRate < 0 || $taxRate > 100) $errors[] = 'The tax rate must be between 0 and 100.';

    // validate the lines
    $cleanLines = [];
    foreach ((array)$lines as $ln) {
        $desc  = trim((string)($ln['description'] ?? ''));
        $ttId  = (int)($ln['test_type_id'] ?? 0);
        $count = (int)($ln['sample_count'] ?? 1);
        $price = $ln['unit_price'] === '' ? 0.0 : (float)$ln['unit_price'];
        if ($desc === '' && !$ttId) continue;              // fully blank row
        if ($desc === '')     $errors[] = 'Every line needs a description.';
        if ($count < 1)       $errors[] = 'A line cannot have fewer than one sample.';
        if ($price < 0)       $errors[] = 'A unit price cannot be negative.';
        $cleanLines[] = [
            'test_type_id' => $ttId ?: null,
            'description'  => $desc,
            'sample_count' => max(1, $count),
            'unit_price'   => $price,
            'line_total'   => round($price * max(1, $count), 2),
        ];
    }
    if (!$cleanLines && $invId === 0) $errors[] = 'Add at least one line so the client is being charged for something.';

    if ($errors) {
        foreach ($errors as $e) flash('<strong>Not saved:</strong> ' . h($e), 'err');
        $action = $invId ? 'edit' : 'new';
        $id = $invId;
    } else {
        $pdo->beginTransaction();
        try {
            if ($invId === 0) {
                $clientId = ensure_client($company, ['contact_person' => $contact]);
                $seq      = next_number('invoice');
                $prefix   = setting('invoice_prefix', 'INV');
                $invNum   = sprintf('%s-%s-%04d', $prefix, date('Y'), $seq);

                $pdo->prepare(
                    'INSERT INTO c_billing (inv_number, client_id, company_name, contact_person,
                        invoice_date, due_date, status, tax_rate, discount_amount, notes, created_by)
                     VALUES (?,?,?,?,?,?,"draft",?,?,?,?)')
                    ->execute([$invNum, $clientId, $company, $contact ?: null, $invDate,
                               $dueDate ?: null, $taxRate, $discount, $notes ?: null, $u['id']]);
                $invId = (int)$pdo->lastInsertId();

                foreach ($cleanLines as $i => $cl) {
                    $pdo->prepare(
                        'INSERT INTO c_invoice_lines (invoice_id, test_type_id, description, sample_count,
                            unit_price, line_total, sort_order) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$invId, $cl['test_type_id'], $cl['description'], $cl['sample_count'],
                                   $cl['unit_price'], $cl['line_total'], $i]);
                }
                $totals = recalc_invoice($invId);
                audit('INVOICE_CREATED', 'invoices', $invId, null, [
                    'inv_number' => $invNum, 'company' => $company,
                    'lines' => count($cleanLines), 'grand_total' => $totals['grand'],
                ]);
                flash('Invoice <strong>' . h($invNum) . '</strong> created for ' . h($company)
                      . '. Next: receive the samples it covers.', 'ok');
            } else {
                $st = $pdo->prepare('SELECT * FROM c_billing WHERE id = ?');
                $st->execute([$invId]);
                $before = $st->fetch();
                if (!$before) { throw new RuntimeException('Invoice not found.'); }

                $pdo->prepare(
                    'UPDATE c_billing SET company_name=?, contact_person=?, invoice_date=?, due_date=?,
                        tax_rate=?, discount_amount=?, notes=?, updated_at=NOW() WHERE id=?')
                    ->execute([$company, $contact ?: null, $invDate, $dueDate ?: null,
                               $taxRate, $discount, $notes ?: null, $invId]);

                // replace the lines wholesale
                $pdo->prepare('DELETE FROM c_invoice_lines WHERE invoice_id = ?')->execute([$invId]);
                foreach ($cleanLines as $i => $cl) {
                    $pdo->prepare(
                        'INSERT INTO c_invoice_lines (invoice_id, test_type_id, description, sample_count,
                            unit_price, line_total, sort_order) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$invId, $cl['test_type_id'], $cl['description'], $cl['sample_count'],
                                   $cl['unit_price'], $cl['line_total'], $i]);
                }
                $totals = recalc_invoice($invId);
                audit('INVOICE_UPDATED', 'invoices', $invId, $before, [
                    'company' => $company, 'lines' => count($cleanLines), 'grand_total' => $totals['grand'],
                ], trim((string)post('edit_reason', '')) ?: null);
                flash('Invoice <strong>' . h($before['inv_number']) . '</strong> updated.', 'ok');
            }
            $pdo->commit();
            redirect('invoices.php?action=view&id=' . $invId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[CTMS-CONSULT] invoice save failed: ' . $e->getMessage());
            flash('Could not save the invoice: ' . h($e->getMessage()), 'err');
            $action = $invId ? 'edit' : 'new';
        }
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  STATUS CHANGE / PAYMENT / DELETE
// ════════════════════════════════════════════════════════════════════════════
if (is_post() && post('do') === 'status') {
    require_login(['superadmin', 'admin', 'professor']);
    $invId  = (int)post('id');
    $status = (string)post('status');
    $allowed = ['draft', 'issued', 'partial', 'paid', 'cancelled'];
    if (!in_array($status, $allowed, true)) {
        flash('That is not a valid invoice status.', 'err');
    } else {
        $st = $pdo->prepare('SELECT * FROM c_billing WHERE id = ?');
        $st->execute([$invId]);
        $before = $st->fetch();
        $payload = ['status' => $status];
        $sql = 'UPDATE c_billing SET status = ?, updated_at = NOW()';
        $params = [$status];
        if ($status === 'paid') {
            $method = (string)post('payment_method', 'bank_voucher');
            $ref    = trim((string)post('payment_reference', ''));
            $pdate  = trim((string)post('payment_date', '')) ?: today();
            if ($ref === '') {
                flash('A payment reference is required when marking an invoice paid — '
                    . 'it is the only link back to the bank record.', 'err');
                redirect('invoices.php?action=view&id=' . $invId);
            }
            $sql .= ', payment_method = ?, payment_reference = ?, payment_date = ?';
            array_push($params, $method, $ref, $pdate);
            $payload += ['payment_method' => $method, 'payment_reference' => $ref, 'payment_date' => $pdate];
        }
        $sql .= ' WHERE id = ?';
        $params[] = $invId;
        $pdo->prepare($sql)->execute($params);
        audit('INVOICE_STATUS_CHANGED', 'invoices', $invId, $before, $payload,
              trim((string)post('reason', '')) ?: null);
        flash('Invoice status set to <strong>' . h($status) . '</strong>.', 'ok');
    }
    redirect('invoices.php?action=view&id=' . $invId);
}

if (is_post() && post('do') === 'delete') {
    require_login(['superadmin', 'admin']);
    $invId  = (int)post('id');
    $reason = trim((string)post('reason', ''));
    $st = $pdo->prepare('SELECT * FROM c_billing WHERE id = ?');
    $st->execute([$invId]);
    $before = $st->fetch();
    if (!$before) { flash('Invoice not found.', 'err'); redirect('invoices.php'); }
    if ($reason === '') {
        flash('A reason is required to cancel an invoice.', 'err');
        redirect('invoices.php?action=view&id=' . $invId);
    }
    $pdo->prepare('UPDATE c_billing SET status = "cancelled", updated_at = NOW() WHERE id = ?')->execute([$invId]);
    audit('INVOICE_CANCELLED', 'invoices', $invId, $before, ['status' => 'cancelled'], $reason);
    flash('Invoice <strong>' . h($before['inv_number']) . '</strong> cancelled. The record is kept for audit.', 'warn');
    redirect('invoices.php');
}

// ════════════════════════════════════════════════════════════════════════════
//  PAGE DATA
// ════════════════════════════════════════════════════════════════════════════
$testTypes = $pdo->query('SELECT id, code, name, category FROM c_test_types
                           WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll();

$editInv   = null;
$editLines = [];
if ($action === 'edit' || $action === 'view') {
    $st = $pdo->prepare('SELECT i.*, c.contact_person AS client_contact, c.phone AS client_phone
                           FROM c_billing i LEFT JOIN c_clients c ON c.id = i.client_id WHERE i.id = ?');
    $st->execute([$id]);
    $editInv = $st->fetch() ?: null;
    if (!$editInv) { flash('That invoice does not exist.', 'err'); redirect('invoices.php'); }
    $st = $pdo->prepare('SELECT l.*, tt.name AS test_name FROM c_invoice_lines l
                          LEFT JOIN c_test_types tt ON tt.id = l.test_type_id
                         WHERE l.invoice_id = ? ORDER BY l.sort_order, l.id');
    $st->execute([$id]);
    $editLines = $st->fetchAll();
    if (!$editLines) $editLines = [['id' => 0, 'test_type_id' => null, 'description' => '', 'sample_count' => 1, 'unit_price' => 0]];

    if ($action === 'view') {
        $st = $pdo->prepare('SELECT s.id, s.sample_code, s.description, s.status, s.created_at,
                                    (SELECT COUNT(*) FROM c_sample_pieces p WHERE p.sample_id = s.id AND p.is_deleted=0) AS pieces,
                                    (SELECT COUNT(*) FROM c_test_jobs j WHERE j.sample_id = s.id) AS jobs
                               FROM c_samples s WHERE s.invoice_id = ? AND s.is_deleted = 0 ORDER BY s.id');
        $st->execute([$id]);
        $invSamples = $st->fetchAll();
    }
}

$list = [];
if ($action === 'list') {
    $status = (string)get('status', '');
    $search = trim((string)get('q', ''));
    $sql = 'SELECT i.*, (SELECT COUNT(*) FROM c_invoice_lines l WHERE l.invoice_id = i.id) AS line_count,
                   (SELECT COUNT(*) FROM c_samples s WHERE s.invoice_id = i.id AND s.is_deleted = 0) AS sample_count
              FROM c_billing i WHERE 1=1';
    $params = [];
    if ($status !== '') { $sql .= ' AND i.status = ?'; $params[] = $status; }
    if ($search !== '') { $sql .= ' AND (i.inv_number LIKE ? OR i.company_name LIKE ?)';
                          $params[] = "%$search%"; $params[] = "%$search%"; }
    $sql .= ' ORDER BY i.id DESC LIMIT 300';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $list = $st->fetchAll();
}

$PAGE_TITLE = match ($action) {
    'new'   => 'New invoice',
    'edit'  => 'Edit invoice ' . ($editInv['inv_number'] ?? ''),
    'view'  => 'Invoice ' . ($editInv['inv_number'] ?? ''),
    default => 'Invoices',
};
$PAGE_SUB = 'Workflow step 1 — the company named here appears on every report that follows';
include __DIR__ . '/inc/layout_top.php';
?>

<?php if ($action === 'list'): ?>

  <div class="card">
    <div class="toolbar" style="margin:0">
      <?php if ($canCreateInvoice): ?>
      <a class="btn btn-copper" href="<?= h(app_url('invoices.php?action=new')) ?>">+ New invoice</a>
      <?php else: ?>
      <span class="hint">Invoice creation is handled by Reception. Open an invoice to see the requested tests.</span>
      <?php endif; ?>
      <form method="get" style="display:flex;gap:8px;align-items:center;margin-left:auto">
        <input type="hidden" name="action" value="list">
        <input type="text" name="q" value="<?= h(get('q', '')) ?>" placeholder="Search invoice no. or company" style="width:250px">
        <select name="status" style="width:150px">
          <option value="">All statuses</option>
          <?php foreach (['draft','issued','partial','paid','cancelled'] as $s): ?>
            <option value="<?= h($s) ?>" <?= get('status') === $s ? 'selected' : '' ?>><?= h(ucfirst($s)) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-ghost" type="submit">Filter</button>
      </form>
    </div>
  </div>

  <div class="card">
    <h2><?= count($list) ?> invoice(s)</h2>
    <?php if (!$list): ?>
      <div class="empty">No invoices yet. Create one to begin a consultancy job.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Invoice</th><th>Company</th><th>Date</th><th class="right">Lines</th>
                <th class="right">Samples</th><?php if ($canSeeBilling): ?><th class="right">Total</th><th>Status</th><?php endif; ?><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($list as $r): ?>
            <tr>
              <td class="mono"><a href="<?= h(app_url('invoices.php?action=view&id=' . (int)$r['id'])) ?>"><?= h($r['inv_number']) ?></a></td>
              <td><?= h($r['company_name']) ?></td>
              <td class="small nowrap"><?= h(fmt_date($r['invoice_date'])) ?></td>
              <td class="num"><?= (int)$r['line_count'] ?></td>
              <td class="num"><?= (int)$r['sample_count'] ?></td>
              <?php if ($canSeeBilling): ?><td class="num"><?= h(setting('currency', 'Rs.')) ?> <?= h(fmt($r['grand_total'])) ?></td><?php endif; ?>
              <?php if ($canSeeBilling): ?><td><?= status_badge($r['status']) ?></td><?php endif; ?>
              <td class="nowrap">
                <a class="btn btn-sm btn-ghost" href="<?= h(app_url('invoices.php?action=view&id=' . (int)$r['id'])) ?>">Open</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php elseif ($action === 'new' || $action === 'edit'): ?>

  <form method="post" id="invForm">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="id" value="<?= (int)($editInv['id'] ?? 0) ?>">

    <div class="card">
      <h2>Client and dates</h2>
      <div class="row r3">
        <div class="field">
          <label for="company_name">Company name *</label>
          <input id="company_name" name="company_name" type="text" required list="clientList"
                 value="<?= h($editInv['company_name'] ?? post('company_name', '')) ?>"
                 placeholder="e.g. ENGRO CORPORATION LIMITED">
          <datalist id="clientList">
            <?php foreach ($pdo->query('SELECT company_name FROM c_clients ORDER BY company_name') as $c): ?>
              <option value="<?= h($c['company_name']) ?>">
            <?php endforeach; ?>
          </datalist>
          <div class="hint">Printed as the customer on every report generated from this invoice.</div>
        </div>
        <div class="field">
          <label for="contact_person">Contact person</label>
          <input id="contact_person" name="contact_person" type="text"
                 value="<?= h($editInv['contact_person'] ?? '') ?>">
        </div>
        <div class="field">
          <label for="invoice_date">Invoice date *</label>
          <input id="invoice_date" name="invoice_date" type="date" required
                 value="<?= h($editInv['invoice_date'] ?? today()) ?>">
        </div>
      </div>
      <div class="row r3">
        <div class="field">
          <label for="due_date">Payment due</label>
          <input id="due_date" name="due_date" type="date" value="<?= h($editInv['due_date'] ?? '') ?>">
        </div>
        <div class="field">
          <label for="tax_rate">Service tax %</label>
          <input id="tax_rate" name="tax_rate" type="number" step="0.01" min="0" max="100"
                 value="<?= h($editInv['tax_rate'] ?? setting('default_tax_rate', '13')) ?>">
        </div>
        <div class="field">
          <label for="discount_amount">Discount</label>
          <input id="discount_amount" name="discount_amount" type="number" step="0.01" min="0"
                 value="<?= h($editInv['discount_amount'] ?? '0.00') ?>">
          <div class="hint">A discount reduces the taxable amount before tax is applied.</div>
        </div>
      </div>
    </div>

    <div class="card">
      <h2>What the client is paying for</h2>
      <p class="small muted" style="margin-bottom:10px">
        Each line becomes a set of samples in the next step. Choose the test so the laboratory
        knows what to do; the description is what the client sees.
      </p>
      <div class="table-wrap">
        <table id="lineTable">
          <thead>
            <tr><th style="width:230px">Test</th><th>Description</th>
                <th style="width:90px">Samples</th><th style="width:120px">Unit price</th>
                <th style="width:120px" class="right">Line total</th><th style="width:40px"></th></tr>
          </thead>
          <tbody>
          <?php foreach ($editLines as $i => $ln): ?>
            <tr class="lineRow">
              <td>
                <select name="lines[<?= $i ?>][test_type_id]" class="ttSel">
                  <option value="">— test —</option>
                  <?php foreach ($testTypes as $tt): ?>
                    <option value="<?= (int)$tt['id'] ?>" <?= (int)($ln['test_type_id'] ?? 0) === (int)$tt['id'] ? 'selected' : '' ?>>
                      <?= h($tt['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="text" name="lines[<?= $i ?>][description]" class="descInp"
                         value="<?= h($ln['description'] ?? '') ?>" placeholder="e.g. Tensile test on 5 specimens"></td>
              <td><input type="number" name="lines[<?= $i ?>][sample_count]" class="cntInp" min="1"
                         value="<?= h($ln['sample_count'] ?? 1) ?>"></td>
              <td><input type="number" name="lines[<?= $i ?>][unit_price]" class="priceInp" step="0.01" min="0"
                         value="<?= h($ln['unit_price'] ?? '0.00') ?>"></td>
              <td class="num lineTotal"><?= h(fmt($ln['line_total'] ?? 0)) ?></td>
              <td><button type="button" class="btn btn-sm btn-danger rowDel">✕</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="lineRow template" style="display:none">
              <td>
                <select class="ttSel" data-name="test_type_id">
                  <option value="">— test —</option>
                  <?php foreach ($testTypes as $tt): ?>
                    <option value="<?= (int)$tt['id'] ?>"><?= h($tt['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="text" class="descInp" data-name="description" placeholder="e.g. Tensile test on 5 specimens"></td>
              <td><input type="number" class="cntInp" data-name="sample_count" min="1" value="1"></td>
              <td><input type="number" class="priceInp" data-name="unit_price" step="0.01" min="0" value="0.00"></td>
              <td class="num lineTotal">0.00</td>
              <td><button type="button" class="btn btn-sm btn-danger rowDel">✕</button></td>
            </tr>
            <tr>
              <td colspan="6"><button type="button" class="btn btn-ghost btn-sm" id="addLine">+ Add another line</button></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div style="display:flex;justify-content:flex-end;margin-top:14px">
        <table style="width:320px">
          <tr><td>Subtotal</td><td class="num" id="sumSub">0.00</td></tr>
          <tr><td>Discount</td><td class="num" id="sumDisc">0.00</td></tr>
          <tr><td>Tax</td><td class="num" id="sumTax">0.00</td></tr>
          <tr style="font-weight:700;background:var(--paper)"><td>Grand total</td><td class="num" id="sumGrand">0.00</td></tr>
        </table>
      </div>
    </div>

    <div class="card">
      <h2>Notes</h2>
      <textarea name="notes" rows="3" placeholder="Anything the laboratory should know about this job"><?= h($editInv['notes'] ?? '') ?></textarea>
      <?php if ($action === 'edit'): ?>
        <div class="field" style="margin-top:12px">
          <label for="edit_reason">Reason for this edit</label>
          <input id="edit_reason" name="edit_reason" type="text"
                 placeholder="Recorded in the audit trail">
          <div class="hint">An invoice is a financial record, so changes are logged with a reason.</div>
        </div>
      <?php endif; ?>
    </div>

    <div class="toolbar">
      <button class="btn btn-copper" type="submit">
        <?= $action === 'edit' ? 'Save changes' : 'Create invoice' ?>
      </button>
      <a class="btn btn-ghost" href="<?= h(app_url('invoices.php')) ?>">Cancel</a>
    </div>
  </form>

  <script>
  (function () {
    var tbody = document.querySelector('#lineTable tbody');
    var tmpl  = document.querySelector('#lineTable .template');
    var seq   = <?= count($editLines) ?>;

    function money(n) { return (Math.round(n * 100) / 100).toFixed(2); }

    function recalc() {
      var sub = 0;
      document.querySelectorAll('#lineTable tbody .lineRow').forEach(function (tr) {
        var c = parseFloat(tr.querySelector('.cntInp').value) || 0;
        var p = parseFloat(tr.querySelector('.priceInp').value) || 0;
        var t = c * p;
        sub += t;
        tr.querySelector('.lineTotal').textContent = money(t);
      });
      var disc  = parseFloat(document.getElementById('discount_amount').value) || 0;
      var rate  = parseFloat(document.getElementById('tax_rate').value) || 0;
      var taxable = Math.max(0, sub - disc);
      var tax = taxable * rate / 100;
      document.getElementById('sumSub').textContent   = money(sub);
      document.getElementById('sumDisc').textContent  = money(disc);
      document.getElementById('sumTax').textContent   = money(tax);
      document.getElementById('sumGrand').textContent = money(taxable + tax);
    }

    function renumber() {
      document.querySelectorAll('#lineTable tbody .lineRow').forEach(function (tr, i) {
        tr.querySelectorAll('[data-name]').forEach(function (el) {
          el.name = 'lines[' + i + '][' + el.dataset.name + ']';
        });
        // rows rendered by PHP already carry a name; rewrite it to stay in order
        tr.querySelectorAll('.ttSel').forEach(function (el) { if (!el.dataset.name) el.name = 'lines[' + i + '][test_type_id]'; });
        tr.querySelectorAll('.descInp').forEach(function (el) { el.name = 'lines[' + i + '][description]'; });
        tr.querySelectorAll('.cntInp').forEach(function (el) { el.name = 'lines[' + i + '][sample_count]'; });
        tr.querySelectorAll('.priceInp').forEach(function (el) { el.name = 'lines[' + i + '][unit_price]'; });
      });
      recalc();
    }

    document.getElementById('addLine').addEventListener('click', function () {
      var tr = tmpl.cloneNode(true);
      tr.classList.remove('template');
      tr.style.display = '';
      tr.querySelectorAll('[data-name]').forEach(function (el) { el.removeAttribute('name'); });
      tbody.appendChild(tr);
      renumber();
    });

    document.addEventListener('click', function (e) {
      if (e.target.classList.contains('rowDel')) {
        var rows = document.querySelectorAll('#lineTable tbody .lineRow');
        if (rows.length === 1) { alert('An invoice needs at least one line.'); return; }
        e.target.closest('tr').remove();
        renumber();
      }
    });

    document.addEventListener('input', function (e) {
      if (e.target.closest('#lineTable') || e.target.id === 'tax_rate' || e.target.id === 'discount_amount') recalc();
    });

    // help: picking a test fills the description if it is still empty
    document.addEventListener('change', function (e) {
      if (e.target.classList.contains('ttSel')) {
        var tr = e.target.closest('tr');
        var d  = tr.querySelector('.descInp');
        if (!d.value.trim() && e.target.selectedIndex > 0) {
          d.value = e.target.options[e.target.selectedIndex].text;
        }
        recalc();
      }
    });

    renumber();
  })();
  </script>

<?php elseif ($action === 'view'): ?>

  <div class="grid g3" style="margin-bottom:16px">
    <?php if ($canSeeBilling): ?>
    <div class="kpi">
      <div class="l">Grand total</div>
      <div class="v"><?= h(setting('currency', 'Rs.')) ?> <?= h(fmt($editInv['grand_total'])) ?></div>
      <div class="s">Tax <?= h(fmt($editInv['tax_rate'], 2)) ?>% · Discount <?= h(fmt($editInv['discount_amount'])) ?></div>
    </div>
    <?php endif; ?>
    <div class="kpi" style="border-left-color:var(--navy-2)">
      <div class="l">Samples received</div>
      <div class="v"><?= count($invSamples ?? []) ?></div>
      <div class="s">Against <?= count($editLines) ?> invoice line(s)</div>
    </div>
    <?php if ($canSeeBilling): ?><div class="kpi" style="border-left-color:var(--teal)">
      <div class="l">Status</div>
      <div class="v" style="font-size:18px;padding-top:6px"><?= status_badge($editInv['status']) ?></div>
      <div class="s">Created <?= h(fmt_date($editInv['created_at'])) ?></div>
    </div><?php endif; ?>
  </div>

  <div class="card">
    <div class="toolbar" style="margin:0">
      <?php if (can_user('superadmin', 'admin', 'reception')): ?>
      <a class="btn btn-copper" href="<?= h(app_url('samples.php?action=new&invoice=' . (int)$editInv['id'])) ?>">+ Receive samples for this invoice</a>
      <?php endif; ?>
      <?php if ($canCreateInvoice): ?><a class="btn btn-ghost" href="<?= h(app_url('invoices.php?action=edit&id=' . (int)$editInv['id'])) ?>">Edit invoice</a><?php endif; ?>
      <a class="btn btn-ghost" href="<?= h(app_url('invoices.php')) ?>">All invoices</a>
    </div>
  </div>

  <div class="card">
    <h2><?= h($editInv['company_name']) ?></h2>
    <table style="width:auto">
      <tr><td class="muted" style="width:170px">Invoice number</td><td class="mono"><?= h($editInv['inv_number']) ?></td></tr>
      <tr><td class="muted">Invoice date</td><td><?= h(fmt_date($editInv['invoice_date'])) ?></td></tr>
      <?php if ($canSeeBilling): ?><tr><td class="muted">Payment due</td><td><?= h(fmt_date($editInv['due_date'])) ?></td></tr><?php endif; ?>
      <?php if ($canSeeBilling && $editInv['contact_person']): ?>
      <tr><td class="muted">Contact</td><td><?= h($editInv['contact_person']) ?></td></tr>
      <?php endif; ?>
      <?php if ($canSeeBilling && $editInv['payment_reference']): ?>
      <tr><td class="muted">Payment</td><td><?= h($editInv['payment_method']) ?> ·
        <?= h($editInv['payment_reference']) ?> · <?= h(fmt_date($editInv['payment_date'])) ?></td></tr>
      <?php endif; ?>
      <?php if ($editInv['notes']): ?>
      <tr><td class="muted">Notes</td><td><?= nl2br(h($editInv['notes'])) ?></td></tr>
      <?php endif; ?>
    </table>
  </div>

  <div class="card">
    <h2>Lines</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>#</th><th>Test</th><th>Description</th><th class="right">Samples</th>
                   <?php if ($canSeeBilling): ?><th class="right">Unit price</th><th class="right">Line total</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($editLines as $i => $ln): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td class="small"><?= h($ln['test_name'] ?: '—') ?></td>
            <td><?= h($ln['description']) ?></td>
            <td class="num"><?= (int)$ln['sample_count'] ?></td>
            <?php if ($canSeeBilling): ?><td class="num"><?= h(fmt($ln['unit_price'])) ?></td>
            <td class="num"><?= h(fmt($ln['line_total'])) ?></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($canSeeBilling): ?><tfoot>
          <tr><td colspan="5" class="right">Subtotal</td><td class="num"><?= h(fmt($editInv['subtotal'])) ?></td></tr>
          <tr><td colspan="5" class="right">Discount</td><td class="num">- <?= h(fmt($editInv['discount_amount'])) ?></td></tr>
          <tr><td colspan="5" class="right">Tax <?= h(fmt($editInv['tax_rate'], 2)) ?>%</td><td class="num"><?= h(fmt($editInv['tax_amount'])) ?></td></tr>
          <tr style="font-weight:700;background:var(--paper)"><td colspan="5" class="right">Grand total</td>
              <td class="num"><?= h(fmt($editInv['grand_total'])) ?></td></tr>
        </tfoot><?php endif; ?>
      </table>
    </div>
  </div>

  <?php if (!empty($invSamples)): ?>
  <div class="card">
    <h2>Samples received against this invoice</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Sample</th><th>Description</th><th class="right">Pieces</th>
                   <th class="right">Tests</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($invSamples as $s): ?>
          <tr>
            <td class="mono"><a href="<?= h(app_url('samples.php?action=view&id=' . (int)$s['id'])) ?>"><?= h($s['sample_code']) ?></a></td>
            <td><?= h($s['description'] ?: '—') ?></td>
            <td class="num"><?= (int)$s['pieces'] ?></td>
            <td class="num"><?= (int)$s['jobs'] ?></td>
            <td><?= status_badge($s['status']) ?></td>
            <td><a class="btn btn-sm btn-ghost" href="<?= h(app_url('samples.php?action=view&id=' . (int)$s['id'])) ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if (can_user('superadmin', 'admin', 'professor')): ?><div class="grid g2">
    <div class="card">
      <h2>Change status</h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="status">
        <input type="hidden" name="id" value="<?= (int)$editInv['id'] ?>">
        <div class="row r2">
          <div class="field">
            <label for="status">New status</label>
            <select id="status" name="status" required>
              <?php foreach (['draft','issued','partial','paid','cancelled'] as $s): ?>
                <option value="<?= h($s) ?>" <?= $editInv['status'] === $s ? 'selected' : '' ?>><?= h(ucfirst($s)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="reason">Reason (recorded)</label>
            <input id="reason" name="reason" type="text" placeholder="optional">
          </div>
        </div>
        <div class="grid g3" style="margin-bottom:12px">
          <div class="field" style="margin:0">
            <label for="payment_method">Payment method</label>
            <select id="payment_method" name="payment_method">
              <option value="bank_voucher">Bank voucher</option>
              <option value="online_transfer">Online transfer</option>
              <option value="cash">Cash</option>
              <option value="cheque">Cheque</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="field" style="margin:0">
            <label for="payment_reference">Payment reference *</label>
            <input id="payment_reference" name="payment_reference" type="text" placeholder="slip / voucher no.">
          </div>
          <div class="field" style="margin:0">
            <label for="payment_date">Payment date</label>
            <input id="payment_date" name="payment_date" type="date" value="<?= h(today()) ?>">
          </div>
        </div>
        <p class="hint" style="margin-bottom:10px">
          The reference is required to mark an invoice paid. Paying by <em>credit</em> is not offered,
          because credit means the money has not arrived.
        </p>
        <button class="btn" type="submit">Apply status</button>
      </form>
    </div>

    <?php if (can_user('superadmin', 'admin')): ?>
    <div class="card">
      <h2>Cancel this invoice</h2>
      <p class="small muted" style="margin-bottom:10px">
        Cancelling keeps the record and its history; nothing is erased. A reason is required.
      </p>
      <form method="post" onsubmit="return confirm('Cancel this invoice? The record is kept for audit.');">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="delete">
        <input type="hidden" name="id" value="<?= (int)$editInv['id'] ?>">
        <div class="field">
          <label for="del_reason">Reason *</label>
          <input id="del_reason" name="reason" type="text" required placeholder="e.g. raised in error, duplicate">
        </div>
        <button class="btn btn-danger" type="submit">Cancel invoice</button>
      </form>
    </div>
    <?php endif; ?>
  </div><?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
