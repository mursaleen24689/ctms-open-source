<?php
declare(strict_types=1);
/**
 * Samples and cut pieces — workflow step 2.
 *
 * The real scenario this is built for:
 *   A client sends ONE bar and asks for five tests.
 *   The bar is a SAMPLE. It is cut into PIECES. Each piece goes to a TEST.
 *   Every reading traces back to the piece, and therefore to the bar.
 *
 * When a sample is described, the standards library proposes the test set that
 * is usually ordered for that kind of material. The technician approves or
 * changes it — the system never decides.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/engine.php';

$u = require_login();
$action = (string)get('action', 'list');
$id     = (int)get('id', 0);

$testTypes = $pdo->query('SELECT id, code, name, category FROM c_test_types
                           WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll();

/** Next free piece code for a sample: 1.1, 1.2, 1.3 ... */
function next_piece_code(int $sampleId, string $sampleCode): string {
    $st = $GLOBALS['pdo']->prepare('SELECT piece_code FROM c_sample_pieces WHERE sample_id = ?');
    $st->execute([$sampleId]);
    $used = $st->fetchAll(PDO::FETCH_COLUMN);
    $base = preg_replace('/[^0-9A-Za-z]/', '', $sampleCode) ?: 'S';
    $n = 1;
    do { $code = $base . '.' . $n; $n++; } while (in_array($code, $used, true));
    return $code;
}

// ════════════════════════════════════════════════════════════════════════════
//  RECEIVE SAMPLES
// ════════════════════════════════════════════════════════════════════════════
if (is_post() && post('do') === 'save') {
    $invoiceId = (int)post('invoice_id', 0) ?: null;
    $desc      = trim((string)post('description', ''));
    $pieces    = post('pieces', []);
    $tests     = post('tests', []);
    $condition = trim((string)post('condition_note', ''));
    $gradeClaim= trim((string)post('grade_claimed', ''));
    $received  = trim((string)post('received_date', '')) ?: today();

    $company = '';
    $invRow  = null;
    if ($invoiceId) {
        $st = $pdo->prepare('SELECT * FROM c_billing WHERE id = ?');
        $st->execute([$invoiceId]);
        $invRow = $st->fetch();
        if ($invRow) $company = $invRow['company_name'];
    }

    $errors = [];
    if ($desc === '')      $errors[] = 'Describe what was received — the technician cannot judge a blank sample.';
    if (!$invoiceId)       $errors[] = 'Choose the invoice this sample belongs to.';
    $cleanTests = array_values(array_unique(array_map('intval', array_filter((array)$tests))));
    if (!$cleanTests)      $errors[] = 'Choose at least one test. Use the suggestions to start.';
    $cleanPieces = [];
    foreach ((array)$pieces as $p) {
        $pc = trim((string)($p['piece_code'] ?? ''));
        if ($pc === '') continue;
        $cleanPieces[] = [
            'piece_code'  => $pc,
            'description' => trim((string)($p['description'] ?? '')),
            'dim_kind'    => (string)($p['dimension_kind'] ?? 'not_recorded'),
            'dim_a'       => dec_or_null($p['dim_a'] ?? null),
            'dim_b'       => dec_or_null($p['dim_b'] ?? null),
            'mass_kg'     => dec_or_null($p['mass_kg'] ?? null),
        ];
    }

    if ($errors) {
        foreach ($errors as $e) flash('<strong>Not saved:</strong> ' . h($e), 'err');
        $action = 'new';
    } else {
        $pdo->beginTransaction();
        try {
            // sample code: SMP-YYYY-#### using the atomic counter
            $seq  = next_number('sample');
            $code = sprintf('SMP-%s-%04d', date('Y'), $seq);

            $pdo->prepare(
                'INSERT INTO c_samples (sample_code, invoice_id, client_id, company_name, description,
                    material_group, grade_claimed, received_date, condition_note, received_by, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$code, $invoiceId, $invRow['client_id'] ?? null, $company ?: null, $desc,
                           engine_guess_material($desc), $gradeClaim ?: null, $received,
                           $condition ?: null, $u['id'], $u['id']]);
            $sampleId = (int)$pdo->lastInsertId();

            // pieces
            $pieceIds = [];
            if ($cleanPieces) {
                $ps = $pdo->prepare(
                    'INSERT INTO c_sample_pieces (sample_id, piece_code, description, dimension_kind,
                        dim_a, dim_b, dim_unit, mass_kg, sort_order) VALUES (?,?,?,?,?,?,?,?,?)');
                foreach ($cleanPieces as $i => $p) {
                    $ps->execute([$sampleId, $p['piece_code'], $p['description'] ?: null, $p['dim_kind'],
                                  $p['dim_a'], $p['dim_b'], 'mm', $p['mass_kg'], $i]);
                    $pieceIds[] = (int)$pdo->lastInsertId();
                }
            } else {
                // no cutting recorded: the whole item is piece 1.1
                $ps = $pdo->prepare(
                    'INSERT INTO c_sample_pieces (sample_id, piece_code, description, dimension_kind, dim_unit, sort_order)
                     VALUES (?,?,?,"not_recorded","mm",0)');
                $ps->execute([$sampleId, next_piece_code($sampleId, $code), 'Whole item as received']);
                $pieceIds[] = (int)$pdo->lastInsertId();
            }

            // test jobs — one per test, assigned to the first piece by default
            $js = $pdo->prepare(
                'INSERT INTO c_test_jobs (sample_id, piece_id, test_type_id, standard_id, lab_stream,
                    status, created_by) VALUES (?,?,?,?,?,"pending",?)');
            $assigned = 0;
            foreach ($cleanTests as $i => $ttId) {
                $std = engine_suggest_standard($ttId);
                $lab = $pdo->prepare('SELECT name FROM c_test_types WHERE id = ?');
                $lab->execute([$ttId]);
                $js->execute([$sampleId, $pieceIds[0] ?? null, $ttId,
                              $std['id'] ?? null, $lab->fetchColumn() ?: null, $u['id']]);
                $assigned++;
                // remember what was ordered, so suggestions improve with use
                $pdo->prepare('INSERT INTO c_test_suggestions (keyword, material_group, test_type_id,
                                   confidence, times_used, source)
                                VALUES (?,?,?,0.5,1,"learned")
                                ON DUPLICATE KEY UPDATE times_used = times_used + 1')
                    ->execute([mb_substr(mb_strtolower($desc), 0, 120),
                               engine_guess_material($desc), $ttId]);
            }

            audit('SAMPLE_RECEIVED', 'samples', $sampleId, null, [
                'sample_code' => $code, 'invoice' => $invRow['inv_number'] ?? null,
                'company' => $company, 'description' => $desc,
                'pieces' => count($pieceIds), 'tests' => $assigned,
            ], null, $invoiceId);

            $pdo->commit();
            flash('Sample <strong>' . h($code) . '</strong> received — ' . count($pieceIds)
                  . ' piece(s), ' . $assigned . ' test(s) queued.', 'ok');
            redirect('samples.php?action=view&id=' . $sampleId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[CTMS-CONSULT] sample save failed: ' . $e->getMessage());
            flash('Could not save the sample: ' . h($e->getMessage()), 'err');
            $action = 'new';
        }
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  ADD A PIECE
// ════════════════════════════════════════════════════════════════════════════
if (is_post() && post('do') === 'add_piece') {
    $sampleId = (int)post('sample_id');
    $st = $pdo->prepare('SELECT sample_code FROM c_samples WHERE id = ?');
    $st->execute([$sampleId]);
    $sc = (string)$st->fetchColumn();
    if ($sc === '') { flash('Sample not found.', 'err'); redirect('samples.php'); }

    $pc = trim((string)post('piece_code', '')) ?: next_piece_code($sampleId, $sc);
    try {
        $pdo->prepare(
            'INSERT INTO c_sample_pieces (sample_id, piece_code, description, dimension_kind,
                dim_a, dim_b, dim_unit, mass_kg, sort_order)
             VALUES (?,?,?,?,?,?,?,?, (SELECT COALESCE(MAX(p2.sort_order),0)+1 FROM c_sample_pieces p2 WHERE p2.sample_id = ?))')
            ->execute([$sampleId, $pc, trim((string)post('description', '')) ?: null,
                       (string)post('dimension_kind', 'not_recorded'),
                       dec_or_null(post('dim_a')), dec_or_null(post('dim_b')), 'mm',
                       dec_or_null(post('mass_kg')), $sampleId]);
        audit('PIECE_ADDED', 'samples', $sampleId, null, ['piece_code' => $pc], null, $sampleId);
        flash('Piece <strong>' . h($pc) . '</strong> added.', 'ok');
    } catch (Throwable $e) {
        flash('That piece code already exists for this sample.', 'err');
    }
    redirect('samples.php?action=view&id=' . $sampleId);
}

// ════════════════════════════════════════════════════════════════════════════
//  EDIT / DELETE A PIECE  (allowed after saving, with a reason)
// ════════════════════════════════════════════════════════════════════════════
if (is_post() && post('do') === 'edit_piece') {
    $pieceId  = (int)post('piece_id');
    $sampleId = (int)post('sample_id');
    $reason   = trim((string)post('reason', ''));
    $st = $pdo->prepare('SELECT * FROM c_sample_pieces WHERE id = ?');
    $st->execute([$pieceId]);
    $before = $st->fetch();
    if (!$before) { flash('Piece not found.', 'err'); redirect('samples.php?action=view&id=' . $sampleId); }
    if ($reason === '') {
        flash('A reason is required when changing a saved piece.', 'err');
        redirect('samples.php?action=view&id=' . $sampleId);
    }
    $pdo->prepare('UPDATE c_sample_pieces SET piece_code=?, description=?, dimension_kind=?,
                      dim_a=?, dim_b=?, mass_kg=? WHERE id=?')
        ->execute([trim((string)post('piece_code', '')) ?: $before['piece_code'],
                   trim((string)post('description', '')) ?: null,
                   (string)post('dimension_kind', 'not_recorded'),
                   dec_or_null(post('dim_a')), dec_or_null(post('dim_b')),
                   dec_or_null(post('mass_kg')), $pieceId]);
    audit('PIECE_EDITED', 'samples', $pieceId, $before, [
        'piece_code' => post('piece_code'), 'dim_a' => post('dim_a'), 'dim_b' => post('dim_b'),
    ], $reason, $sampleId);
    flash('Piece updated.', 'ok');
    redirect('samples.php?action=view&id=' . $sampleId);
}

if (is_post() && post('do') === 'delete_piece') {
    $pieceId  = (int)post('piece_id');
    $sampleId = (int)post('sample_id');
    $reason   = trim((string)post('reason', ''));
    $st = $pdo->prepare('SELECT * FROM c_sample_pieces WHERE id = ?');
    $st->execute([$pieceId]);
    $before = $st->fetch();
    if (!$before) { flash('Piece not found.', 'err'); redirect('samples.php?action=view&id=' . $sampleId); }
    if ($reason === '') {
        flash('A reason is required to remove a piece.', 'err');
        redirect('samples.php?action=view&id=' . $sampleId);
    }
    // Readings already taken from this piece must not vanish with it.
    $st = $pdo->prepare('SELECT COUNT(*) FROM c_test_jobs WHERE piece_id = ?');
    $st->execute([$pieceId]);
    $jobs = (int)$st->fetchColumn();
    if ($jobs > 0) {
        flash('That piece already has ' . $jobs . ' test(s) attached, so it cannot be removed. '
            . 'Cancel those tests first if it really was not used.', 'err');
        redirect('samples.php?action=view&id=' . $sampleId);
    }
    $pdo->prepare('UPDATE c_sample_pieces SET is_deleted = 1 WHERE id = ?')->execute([$pieceId]);
    audit('PIECE_DELETED', 'samples', $pieceId, $before, ['is_deleted' => 1], $reason, $sampleId);
    flash('Piece removed. The record is kept in the audit trail.', 'warn');
    redirect('samples.php?action=view&id=' . $sampleId);
}

// ════════════════════════════════════════════════════════════════════════════
//  ADD A TEST TO AN EXISTING SAMPLE
// ════════════════════════════════════════════════════════════════════════════
if (is_post() && post('do') === 'add_test') {
    $sampleId = (int)post('sample_id');
    $ttId     = (int)post('test_type_id');
    $pieceId  = (int)post('piece_id', 0) ?: null;
    $priority = post('priority') === 'urgent' ? 'urgent' : 'normal';
    $due      = trim((string)post('due_date', '')) ?: null;
    if (!$sampleId || !$ttId) { flash('Choose a test.', 'err'); redirect('samples.php?action=view&id=' . $sampleId); }
    $std = engine_suggest_standard($ttId);
    $st = $pdo->prepare('SELECT lab_stream FROM c_users WHERE id = ?');
    $st->execute([$u['id']]);
    $pdo->prepare('INSERT INTO c_test_jobs (sample_id, piece_id, test_type_id, standard_id,
                     lab_stream, status, priority, due_date, created_by)
                   VALUES (?,?,?,?,"pending",?,?,?,?)')
        ->execute([$sampleId, $pieceId, $ttId, $std['id'] ?? null, $priority, $due, $u['id']]);
    $jobId = (int)$pdo->lastInsertId();
    audit('TEST_JOB_ADDED', 'jobs', $jobId, null, ['test_type_id' => $ttId, 'standard' => $std['code'] ?? null],
          null, $sampleId);
    flash('Test added to the queue.', 'ok');
    redirect('samples.php?action=view&id=' . $sampleId);
}

// ════════════════════════════════════════════════════════════════════════════
//  PAGE DATA
// ════════════════════════════════════════════════════════════════════════════
$viewSample = null; $viewPieces = []; $viewJobs = []; $suggested = [];
$preInvoice = null;
// Guided mode proposes tests and suggests a standard. Direct mode does neither:
// the technician picks the tests and gets on with it.
$guided = in_guided_mode();
if ($action === 'view' && $id) {
    $st = $pdo->prepare('SELECT s.*, i.inv_number, i.invoice_date FROM c_samples s
                          LEFT JOIN c_billing i ON i.id = s.invoice_id
                         WHERE s.id = ? AND s.is_deleted = 0');
    $st->execute([$id]);
    $viewSample = $st->fetch() ?: null;
    if (!$viewSample) { flash('That sample does not exist.', 'err'); redirect('samples.php'); }

    $st = $pdo->prepare('SELECT * FROM c_sample_pieces WHERE sample_id = ? AND is_deleted = 0
                          ORDER BY sort_order, id');
    $st->execute([$id]);
    $viewPieces = $st->fetchAll();

    $st = $pdo->prepare('SELECT j.*, tt.name AS test_name, tt.code AS test_code, tt.category,
                                st.code AS std_code, st.title AS std_title,
                                p.piece_code, us.full_name AS assigned_name,
                                (SELECT COUNT(*) FROM c_readings r WHERE r.job_id = j.id AND r.is_current = 1) AS reading_count
                           FROM c_test_jobs j
                           JOIN c_test_types tt ON tt.id = j.test_type_id
                           LEFT JOIN c_standards st ON st.id = j.standard_id
                           LEFT JOIN c_sample_pieces p ON p.id = j.piece_id
                           LEFT JOIN c_users us ON us.id = j.assigned_to
                          WHERE j.sample_id = ?
                          ORDER BY tt.sort_order, j.id');
    $st->execute([$id]);
    $viewJobs = $st->fetchAll();

    $suggested = $guided ? engine_suggest_tests((string)$viewSample['description'], 10) : [];
}
if ($action === 'new') {
    $invId = (int)get('invoice', 0);
    if ($invId) {
        $st = $pdo->prepare('SELECT i.*, c.phone FROM c_billing i
                              LEFT JOIN c_clients c ON c.id = i.client_id WHERE i.id = ?');
        $st->execute([$invId]);
        $preInvoice = $st->fetch() ?: null;
    }
}

$list = [];
if ($action === 'list') {
    $search = trim((string)get('q', ''));
    $status = (string)get('status', '');
    $sql = 'SELECT s.*, i.inv_number,
                   (SELECT COUNT(*) FROM c_sample_pieces p WHERE p.sample_id = s.id AND p.is_deleted=0) AS pieces,
                   (SELECT COUNT(*) FROM c_test_jobs j WHERE j.sample_id = s.id) AS jobs,
                   (SELECT COUNT(*) FROM c_test_jobs j WHERE j.sample_id = s.id AND j.status = "approved") AS approved
              FROM c_samples s LEFT JOIN c_billing i ON i.id = s.invoice_id
             WHERE s.is_deleted = 0';
    $params = [];
    if ($search !== '') { $sql .= ' AND (s.sample_code LIKE ? OR s.description LIKE ? OR s.company_name LIKE ?)';
                          array_push($params, "%$search%", "%$search%", "%$search%"); }
    if ($status !== '') { $sql .= ' AND s.status = ?'; $params[] = $status; }
    $sql .= ' ORDER BY s.id DESC LIMIT 300';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $list = $st->fetchAll();
}

$PAGE_TITLE = match ($action) {
    'new'  => 'Receive a sample',
    'view' => 'Sample ' . ($viewSample['sample_code'] ?? ''),
    default => 'Samples',
};
$PAGE_SUB = 'Workflow step 2 — the item received, the pieces it is cut into, and the tests ordered';
include __DIR__ . '/inc/layout_top.php';
?>

<?php if ($action === 'list'): ?>

  <div class="card">
    <div class="toolbar" style="margin:0">
      <a class="btn btn-copper" href="<?= h(app_url('samples.php?action=new')) ?>">+ Receive a sample</a>
      <form method="get" style="display:flex;gap:8px;align-items:center;margin-left:auto">
        <input type="hidden" name="action" value="list">
        <input type="text" name="q" value="<?= h(get('q', '')) ?>" placeholder="code, description or company" style="width:260px">
        <select name="status" style="width:170px">
          <option value="">All statuses</option>
          <?php foreach (['received','in_testing','awaiting_report','reported','dispatched','on_hold'] as $s): ?>
            <option value="<?= h($s) ?>" <?= get('status') === $s ? 'selected' : '' ?>><?= h(ucfirst(str_replace('_',' ',$s))) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-ghost" type="submit">Filter</button>
      </form>
    </div>
  </div>

  <div class="card">
    <h2><?= count($list) ?> sample(s)</h2>
    <?php if (!$list): ?>
      <div class="empty">No samples yet. Receive one against an invoice to begin testing.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Sample</th><th>Company</th><th>Description</th><th>Invoice</th>
                     <th class="right">Pieces</th><th class="right">Tests</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($list as $r): ?>
            <tr>
              <td class="mono"><a href="<?= h(app_url('samples.php?action=view&id=' . (int)$r['id'])) ?>"><?= h($r['sample_code']) ?></a></td>
              <td class="small"><?= h($r['company_name'] ?: '—') ?></td>
              <td><?= h(mb_strimwidth((string)$r['description'], 0, 46, '…')) ?></td>
              <td class="small mono"><?= h($r['inv_number'] ?: '—') ?></td>
              <td class="num"><?= (int)$r['pieces'] ?></td>
              <td class="num"><?= (int)$r['approved'] ?>/<?= (int)$r['jobs'] ?></td>
              <td><?= status_badge($r['status']) ?></td>
              <td><a class="btn btn-sm btn-ghost" href="<?= h(app_url('samples.php?action=view&id=' . (int)$r['id'])) ?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php elseif ($action === 'new'): ?>

  <?php if (!$preInvoice): ?>
    <div class="card">
      <h2>Which invoice does this belong to?</h2>
      <p class="small muted" style="margin-bottom:12px">
        Every sample belongs to an invoice, so the company name and what was ordered flow through
        automatically. Pick one to continue.
      </p>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Invoice</th><th>Company</th><th>Date</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($pdo->query("SELECT * FROM c_billing WHERE status <> 'cancelled' ORDER BY id DESC LIMIT 40") as $r): ?>
            <tr>
              <td class="mono"><?= h($r['inv_number']) ?></td>
              <td><?= h($r['company_name']) ?></td>
              <td class="small"><?= h(fmt_date($r['invoice_date'])) ?></td>
              <td><?= status_badge($r['status']) ?></td>
              <td><a class="btn btn-sm btn-copper" href="<?= h(app_url('samples.php?action=new&invoice=' . (int)$r['id'])) ?>">Use this one</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php else: ?>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="invoice_id" value="<?= (int)$preInvoice['id'] ?>">

    <div class="card">
      <h2>Received from <?= h($preInvoice['company_name']) ?></h2>
      <p class="small muted">
        Invoice <span class="mono"><?= h($preInvoice['inv_number']) ?></span>
        · <?= h(fmt_date($preInvoice['invoice_date'])) ?>
        — the company name carries through to every report automatically.
      </p>
    </div>

    <div class="card">
      <h2>What was received</h2>
      <div class="row r2">
        <div class="field">
          <label for="description">Description *</label>
          <input id="description" name="description" type="text" required autofocus
                 placeholder="e.g. 12mm rebar, 1 foot length"
                 <?= $guided ? 'onkeyup="suggestTests(this.value)"' : '' ?>>
          <div class="hint">
            <?= $guided
                ? 'Type what you see. The system will propose the tests usually ordered for it.'
                : 'Type what you see.' ?>
          </div>
        </div>
        <div class="field">
          <label for="grade_claimed">Grade the client claims</label>
          <input id="grade_claimed" name="grade_claimed" type="text" placeholder="e.g. ASTM A615 Gr.60"
                 <?= $guided ? 'onkeyup="suggestTests(this.value)"' : '' ?>>
        </div>
      </div>
      <div class="row r2">
        <div class="field">
          <label for="received_date">Date received</label>
          <input id="received_date" name="received_date" type="date" value="<?= h(today()) ?>">
        </div>
        <div class="field">
          <label for="condition_note">Condition as received</label>
          <input id="condition_note" name="condition_note" type="text"
                 placeholder="e.g. surface rust, one end deformed">
        </div>
      </div>
    </div>

    <div class="card">
      <h2>Tests to perform *</h2>
      <p class="small muted" style="margin-bottom:10px">
        <?php if ($guided): ?>
          Suggested from the standards library and from what this laboratory ordered before.
          <strong>Nothing is chosen for you</strong> — tick what is actually needed.
          <span id="suggNote"></span>
        <?php else: ?>
          Tick the tests that are being done.
        <?php endif; ?>
      </p>
      <div class="grid g3" id="testBoxes">
        <?php foreach ($testTypes as $tt): ?>
          <label style="display:flex;gap:8px;align-items:flex-start;text-transform:none;letter-spacing:0;
                        font-weight:400;font-size:13px;border:1px solid var(--border);border-radius:8px;
                        padding:9px 11px;cursor:pointer" class="testLabel" data-cat="<?= h($tt['category']) ?>">
            <input type="checkbox" name="tests[]" value="<?= (int)$tt['id'] ?>" style="width:auto;margin-top:3px">
            <span>
              <strong style="font-size:12.5px"><?= h($tt['name']) ?></strong>
              <span class="small muted" style="display:block"><?= h(ucfirst($tt['category'])) ?></span>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <h2>Pieces — if the sample is cut</h2>
      <p class="small muted" style="margin-bottom:10px">
        A one-foot bar cut into five specimens becomes five pieces here, each with its own permanent
        code. Every reading later traces back to its piece. Leave blank and the whole item is recorded
        as a single piece.
      </p>
      <div class="table-wrap">
        <table id="pieceTable">
          <thead><tr><th style="width:110px">Piece code</th><th>Description</th>
                     <th style="width:150px">Measured as</th>
                     <th style="width:90px">Dim A</th><th style="width:90px">Dim B</th>
                     <th style="width:90px">Mass kg</th><th style="width:40px"></th></tr></thead>
          <tbody>
            <?php for ($i = 0; $i < 3; $i++): ?>
            <tr class="pieceRow">
              <td><input type="text" name="pieces[<?= $i ?>][piece_code]" value="" placeholder="auto"></td>
              <td><input type="text" name="pieces[<?= $i ?>][description]" value=""></td>
              <td>
                <select name="pieces[<?= $i ?>][dimension_kind]">
                  <option value="not_recorded">not measured</option>
                  <option value="diameter">Diameter</option>
                  <option value="width">Width</option>
                  <option value="thickness">Thickness</option>
                  <option value="size">Size (L x W)</option>
                </select>
              </td>
              <td><input type="number" step="0.001" name="pieces[<?= $i ?>][dim_a]" value=""></td>
              <td><input type="number" step="0.001" name="pieces[<?= $i ?>][dim_b]" value=""></td>
              <td><input type="number" step="0.001" name="pieces[<?= $i ?>][mass_kg]" value=""></td>
              <td><button type="button" class="btn btn-sm btn-danger pieceDel">✕</button></td>
            </tr>
            <?php endfor; ?>
          </tbody>
          <tfoot><tr><td colspan="7">
            <button type="button" class="btn btn-sm btn-ghost" id="addPiece">+ Add another piece</button>
            <span class="small muted" style="margin-left:10px">Codes are filled in automatically if left blank.</span>
          </td></tr></tfoot>
        </table>
      </div>
    </div>

    <div class="toolbar">
      <button class="btn btn-copper" type="submit">Receive sample and queue tests</button>
      <a class="btn btn-ghost" href="<?= h(app_url('invoices.php?action=view&id=' . (int)$preInvoice['id'])) ?>">Back to invoice</a>
    </div>
  </form>

  <script>
  (function () {
    var catalogue = <?= json_encode(array_map(fn($t) => [
        'id' => (int)$t['id'], 'name' => $t['name'], 'category' => $t['category'],
    ], $testTypes), JSON_UNESCAPED_UNICODE) ?>;

    // Ask the server what is usually ordered for this description.
    // In Direct mode this does nothing at all — no suggestions are wanted.
    var SUGGEST = <?= $guided ? 'true' : 'false' ?>;
    var timer = null;
    window.suggestTests = function (text) {
      if (!SUGGEST) return;
      clearTimeout(timer);
      if (text.trim().length < 3) return;
      timer = setTimeout(function () {
        fetch('<?= h(app_url('api_suggest.php')) ?>?q=' + encodeURIComponent(text))
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (!d.suggestions || !d.suggestions.length) {
              document.getElementById('suggNote').textContent = '';
              return;
            }
            // tick the suggested boxes and highlight them — the user can untick
            var names = [];
            d.suggestions.forEach(function (s) {
              var box = document.querySelector('#testBoxes input[value="' + s.test_type_id + '"]');
              if (box && !box.checked) {
                box.checked = true;
                box.closest('.testLabel').style.borderColor = 'var(--copper)';
                box.closest('.testLabel').style.background = '#FFF9F5';
              }
              names.push(s.name);
            });
            document.getElementById('suggNote').innerHTML =
              '<br><strong>Suggested:</strong> ' + names.join(', ') +
              ' <span class="muted">(ticked for you — untick anything not needed)</span>';
          })
          .catch(function () {});
      }, 350);
    };

    var tbody = document.querySelector('#pieceTable tbody');
    var seq = 3;
    document.getElementById('addPiece').addEventListener('click', function () {
      var tr = tbody.querySelector('.pieceRow').cloneNode(true);
      tr.querySelectorAll('input,select').forEach(function (el) {
        el.value = el.tagName === 'SELECT' ? 'not_recorded' : '';
        if (el.name) el.name = el.name.replace(/pieces\[\d+\]/, 'pieces[' + seq + ']');
      });
      tbody.appendChild(tr);
      seq++;
    });
    document.addEventListener('click', function (e) {
      if (e.target.classList.contains('pieceDel')) {
        if (tbody.querySelectorAll('.pieceRow').length <= 1) { alert('Keep at least one row.'); return; }
        e.target.closest('tr').remove();
      }
    });
  })();
  </script>

  <?php endif; ?>

<?php elseif ($action === 'view'): ?>

  <div class="grid g3" style="margin-bottom:16px">
    <div class="kpi">
      <div class="l">Pieces</div><div class="v"><?= count($viewPieces) ?></div>
      <div class="s">Cut from one received item</div>
    </div>
    <div class="kpi" style="border-left-color:var(--amber)">
      <div class="l">Tests</div>
      <div class="v"><?= count($viewJobs) ?></div>
      <div class="s"><?= count(array_filter($viewJobs, fn($j) => $j['status'] === 'approved')) ?> approved</div>
    </div>
    <div class="kpi" style="border-left-color:var(--teal)">
      <div class="l">Status</div>
      <div class="v" style="font-size:18px;padding-top:6px"><?= status_badge($viewSample['status']) ?></div>
      <div class="s">Received <?= h(fmt_date($viewSample['received_date'])) ?></div>
    </div>
  </div>

  <div class="card">
    <div class="toolbar" style="margin:0">
      <a class="btn btn-ghost" href="<?= h(app_url('samples.php')) ?>">All samples</a>
      <?php if ($viewSample['invoice_id']): ?>
        <a class="btn btn-ghost" href="<?= h(app_url('invoices.php?action=view&id=' . (int)$viewSample['invoice_id'])) ?>">
          Invoice <?= h($viewSample['inv_number']) ?></a>
      <?php endif; ?>
      <a class="btn btn-teal" href="<?= h(app_url('entry.php?sample=' . (int)$viewSample['id'])) ?>">Go to data entry</a>
    </div>
  </div>

  <div class="card">
    <h2><?= h($viewSample['sample_code']) ?> — <?= h($viewSample['company_name'] ?: 'no company') ?></h2>
    <table style="width:auto">
      <tr><td class="muted" style="width:180px">Description</td><td><?= h($viewSample['description']) ?></td></tr>
      <?php if ($viewSample['grade_claimed']): ?>
      <tr><td class="muted">Grade claimed by client</td><td><?= h($viewSample['grade_claimed']) ?></td></tr>
      <?php endif; ?>
      <?php if ($viewSample['material_group']): ?>
      <tr><td class="muted">Detected material group</td><td><?= h($viewSample['material_group']) ?></td></tr>
      <?php endif; ?>
      <?php if ($viewSample['condition_note']): ?>
      <tr><td class="muted">Condition as received</td><td><?= h($viewSample['condition_note']) ?></td></tr>
      <?php endif; ?>
    </table>
  </div>

  <div class="card">
    <h2>Pieces</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Piece</th><th>Description</th><th>Measured as</th>
                   <th class="right">Dim A</th><th class="right">Dim B</th><th class="right">Mass</th>
                   <th></th></tr></thead>
        <tbody>
        <?php foreach ($viewPieces as $p): ?>
          <tr>
            <td class="mono"><?= h($p['piece_code']) ?></td>
            <td><?= h($p['description'] ?: '—') ?></td>
            <td class="small"><?= h(str_replace('_', ' ', $p['dimension_kind'])) ?></td>
            <td class="num"><?= h($p['dim_a'] !== null ? fmt($p['dim_a'], 3) : '—') ?></td>
            <td class="num"><?= h($p['dim_b'] !== null ? fmt($p['dim_b'], 3) : '—') ?></td>
            <td class="num"><?= h($p['mass_kg'] !== null ? fmt($p['mass_kg'], 3) : '—') ?></td>
            <td class="nowrap">
              <button type="button" class="btn btn-sm btn-ghost"
                      onclick="document.getElementById('editPiece<?= (int)$p['id'] ?>').style.display='table-row'">Edit</button>
              <button type="button" class="btn btn-sm btn-danger"
                      onclick="document.getElementById('delPiece<?= (int)$p['id'] ?>').style.display='table-row'">Remove</button>
            </td>
          </tr>
          <tr id="editPiece<?= (int)$p['id'] ?>" style="display:none;background:var(--amber-light)">
            <td colspan="7">
              <form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="edit_piece">
                <input type="hidden" name="piece_id" value="<?= (int)$p['id'] ?>">
                <input type="hidden" name="sample_id" value="<?= (int)$viewSample['id'] ?>">
                <div><label>Code</label><input type="text" name="piece_code" value="<?= h($p['piece_code']) ?>" style="width:110px"></div>
                <div><label>Description</label><input type="text" name="description" value="<?= h($p['description']) ?>" style="width:200px"></div>
                <div><label>Measured as</label>
                  <select name="dimension_kind" style="width:130px">
                    <?php foreach (['not_recorded','diameter','width','thickness','size','mixed'] as $k): ?>
                      <option value="<?= h($k) ?>" <?= $p['dimension_kind'] === $k ? 'selected' : '' ?>><?= h(str_replace('_',' ',$k)) ?></option>
                    <?php endforeach; ?>
                  </select></div>
                <div><label>Dim A</label><input type="number" step="0.001" name="dim_a" value="<?= h($p['dim_a']) ?>" style="width:90px"></div>
                <div><label>Dim B</label><input type="number" step="0.001" name="dim_b" value="<?= h($p['dim_b']) ?>" style="width:90px"></div>
                <div><label>Mass kg</label><input type="number" step="0.001" name="mass_kg" value="<?= h($p['mass_kg']) ?>" style="width:90px"></div>
                <div><label>Reason *</label><input type="text" name="reason" required placeholder="why the change" style="width:180px"></div>
                <button class="btn btn-sm" type="submit">Save</button>
              </form>
            </td>
          </tr>
          <tr id="delPiece<?= (int)$p['id'] ?>" style="display:none;background:var(--rust-light)">
            <td colspan="7">
              <form method="post" style="display:flex;gap:8px;align-items:end"
                    onsubmit="return confirm('Remove piece <?= h($p['piece_code']) ?>?');">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="delete_piece">
                <input type="hidden" name="piece_id" value="<?= (int)$p['id'] ?>">
                <input type="hidden" name="sample_id" value="<?= (int)$viewSample['id'] ?>">
                <div><label>Reason *</label><input type="text" name="reason" required style="width:300px"></div>
                <button class="btn btn-sm btn-danger" type="submit">Confirm removal</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <h3>Add a piece</h3>
    <form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="add_piece">
      <input type="hidden" name="sample_id" value="<?= (int)$viewSample['id'] ?>">
      <div><label>Code</label><input type="text" name="piece_code" placeholder="auto" style="width:110px"></div>
      <div><label>Description</label><input type="text" name="description" style="width:220px"></div>
      <div><label>Measured as</label>
        <select name="dimension_kind" style="width:130px">
          <option value="not_recorded">not measured</option>
          <option value="diameter">Diameter</option>
          <option value="width">Width</option>
          <option value="thickness">Thickness</option>
          <option value="size">Size (L x W)</option>
        </select></div>
      <div><label>Dim A</label><input type="number" step="0.001" name="dim_a" style="width:90px"></div>
      <div><label>Dim B</label><input type="number" step="0.001" name="dim_b" style="width:90px"></div>
      <button class="btn btn-sm btn-copper" type="submit">Add piece</button>
    </form>
  </div>

  <div class="card">
    <h2>Tests ordered</h2>
    <?php if (!$viewJobs): ?>
      <div class="empty">No tests ordered yet.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Test</th><th>Standard suggested</th><th>Piece</th><th>Assigned to</th>
                     <th class="right">Readings</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($viewJobs as $j): ?>
            <tr>
              <td><strong><?= h($j['test_name']) ?></strong><br><span class="small muted"><?= h($j['category']) ?></span></td>
              <td class="small">
                <?php if ($j['std_code']): ?>
                  <span class="mono"><?= h($j['std_code']) ?></span>
                  <?php if ($j['standard_approved']): ?>
                    <?= badge('approved', 'green') ?>
                  <?php else: ?>
                    <?= badge('to confirm', 'amber') ?>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="muted">none</span>
                <?php endif; ?>
              </td>
              <td class="mono small"><?= h($j['piece_code'] ?: '—') ?></td>
              <td class="small"><?= h($j['assigned_name'] ?: 'unassigned') ?></td>
              <td class="num"><?= (int)$j['reading_count'] ?></td>
              <td><?= status_badge($j['status']) ?></td>
              <td><a class="btn btn-sm btn-teal" href="<?= h(app_url('entry.php?job=' . (int)$j['id'])) ?>">Enter</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php
    // Adding a test must always be available — it is a normal part of the work.
    // Guided mode adds the "usually ordered" suggestion and a ★ marker; Direct
    // mode shows the plain list so nothing is suggested.
    $suggestedIds = array_column($suggested, 'test_type_id');
    ?>
    <?php if ($guided && $suggested): ?>
      <h3>Usually ordered for this kind of sample</h3>
    <?php else: ?>
      <h3>Add a test to this sample</h3>
    <?php endif; ?>
      <form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="add_test">
        <input type="hidden" name="sample_id" value="<?= (int)$viewSample['id'] ?>">
        <div><label>Add test</label>
          <select name="test_type_id" required style="width:260px">
            <option value="">choose…</option>
            <?php
            $have = array_map(fn($j) => (int)$j['test_type_id'], $viewJobs);
            foreach ($testTypes as $tt):
              if (in_array((int)$tt['id'], $have, true)) continue; ?>
              <option value="<?= (int)$tt['id'] ?>">
                <?= h($tt['name']) ?><?= ($guided && in_array((int)$tt['id'], $suggestedIds, true)) ? ' ★' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if ($guided && $suggested): ?>
          <div class="hint">★ = usually ordered for “<?= h(mb_strimwidth((string)$viewSample['description'], 0, 30, '…')) ?>”</div>
          <?php endif; ?>
        </div>
        <div><label>Piece</label>
          <select name="piece_id" style="width:130px">
            <option value="">—</option>
            <?php foreach ($viewPieces as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= h($p['piece_code']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div><label>Priority</label>
          <select name="priority" style="width:110px"><option value="normal">Normal</option><option value="urgent">Urgent</option></select></div>
        <div><label>Due</label><input type="date" name="due_date" style="width:150px"></div>
        <button class="btn btn-sm btn-copper" type="submit">Add test</button>
      </form>
  </div>
<?php endif; /* closes: if ($action === 'view') */ ?>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
