<?php
// ctms_server/api/report_generate.php
// v5 — Sprint 1 Patch A (see README_SPRINT1.md):
//   D-01 signature block restored via data-family rule
//   D-02 remarks family lookup fixed (steel_cheque / steel_sd / electronics)
//   Report logo is optional and may be configured per deployment.
//   D-04 real test date printed from the test tables
//   D-05 report numbering driven by report_counters (atomic)
//   D-06 Sample column filled with SD / reference identifier
// Accepts one-time ticket URLs for a sample or legacy full-lot report.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/result_fields_lib.php';
require_once __DIR__ . '/report_workflow_lib.php';
ctms_ensure_result_fields($pdo);

// Reports are displayed inside the authenticated CTMS report viewer modal.
// Keep that embed same-origin only; no external site may frame a report.
header_remove('X-Frame-Options');
header_remove('Content-Security-Policy');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; font-src 'self'; connect-src 'self'; object-src 'none'; frame-ancestors 'self'; base-uri 'self'");

// This endpoint streams HTML reports — declare it explicitly (db.php
// sets a JSON Content-Type which must be overridden).
header('Content-Type: text/html; charset=UTF-8');

$user = null;

// POST = issue a one-time download ticket (the frontend then opens the
// GET with ?ticket=... — no bearer token ever lands in a URL/history).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    $user = require_auth();
    // Z-1/B-3 four-eyes: only reporting roles may generate reports.
    if (!in_array($user['role'] ?? '', ['superadmin', 'admin', 'deo', 'hod'], true)
        && !ctms_user_is_assigned_report_reviewer($pdo, (int)($user['user_id'] ?? 0))) {
        http_response_code(403);
        echo json_encode(['error' => 'Report generation and review access is restricted to reporting staff and assigned Technical Reviewers.']);
        exit;
    }
    $body = json_decode(file_get_contents('php://input'), true);
    $sid  = (int)($body['sample_id'] ?? 0);
    $lid  = (int)($body['lot_id'] ?? 0);
    if (!$sid && !$lid) {
        http_response_code(400); echo json_encode(['error' => 'sample_id or lot_id required']); exit;
    }
    if ($sid) ctms_require_sample_lot_access($pdo, (int)$user['user_id'], $sid, 'view');
    if ($lid) ctms_require_lot_access($pdo, (int)$user['user_id'], $lid, 'view');
    if (!in_array($user['role'] ?? '', ['superadmin', 'admin', 'deo', 'hod'], true)) {
        $reviewable = $pdo->prepare("SELECT COUNT(*) FROM reports r JOIN report_approvals w ON w.report_id = r.id WHERE r.sample_id = ? AND r.is_deleted = 0 AND w.stage = 'technical_review'");
        $reviewable->execute([$sid]);
        if (!$sid || !(int)$reviewable->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['error' => 'Technical Reviewers can preview only reports currently assigned to the Technical Review stage.']);
            exit;
        }
    }
    // P-3: cap report generation attempts per user
    if (!ctms_rate_limit($pdo, 'reportgen:' . (int)$user['user_id'], 60, 600)) {
        http_response_code(429); echo json_encode(['error' => 'Too many report generations. Wait a few minutes.']); exit;
    }
    $ticket = issue_download_ticket($pdo, (int)$user['user_id'], $sid ? 'report_sample' : 'report_lot', $sid ?: $lid);
    // Give the report's Word button its own one-time ticket. This lets the
    // browser download the server-prepared document instead of relying on a
    // Blob download started inside the report viewer iframe.
    $wordTicket = issue_download_ticket($pdo, (int)$user['user_id'], $sid ? 'report_sample' : 'report_lot', $sid ?: $lid);
    echo json_encode(['success' => true, 'ticket' => $ticket,
                      'url' => 'report_generate.php?' . ($sid ? "sample_id=$sid" : "lot_id=$lid") . "&ticket=$ticket&word_ticket=$wordTicket"]);
    exit;
}

// GET: authenticate via a one-time ticket, a signed print token, or the
// Authorization header.
$ticket = $_GET['ticket'] ?? '';
if (!empty($_GET['issue']) && !empty($_GET['issue_token'])) {
    // Print / Word request from an already open report page. This is checked
    // before the download ticket because the page URL still carries the
    // ticket that was consumed when the page was opened. Only the user is
    // identified here; the signed scope binding is verified below, and every
    // role and lot access check still runs.
    if (!preg_match('/^(\d+)\.(\d{10})\.([0-9a-f]{64})$/', (string)$_GET['issue_token'], $m) || (int)$m[2] < time()) {
        http_response_code(403); echo '<h2>This print link has expired. Reopen the report and print it again.</h2>'; exit;
    }
    $uStmt = $pdo->prepare("SELECT id, role, profile_pic FROM users WHERE id = ? AND is_active = 1 AND is_deleted = 0 AND must_change_password = 0");
    $uStmt->execute([(int)$m[1]]);
    $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
    if (!$uRow) {
        http_response_code(403); echo '<h2>This print link has expired. Reopen the report and print it again.</h2>'; exit;
    }
    $role = (string)$uRow['role'];
    if ($role === 'reception' && strtolower((string)($uRow['profile_pic'] ?? '')) === 'pa') $role = 'pa';
    $user = ['user_id' => (int)$uRow['id'], 'role' => $role];
} elseif ($ticket !== '') {
    $scope = !empty($_GET['sample_id']) ? 'report_sample' : 'report_lot';
    $t = validate_download_ticket($pdo, $ticket, $scope);
    $requestedScopeId = $scope === 'report_sample' ? (int)($_GET['sample_id'] ?? 0) : (int)($_GET['lot_id'] ?? 0);
    if (empty($t['ok']) || $requestedScopeId <= 0 || (int)($t['scope_id'] ?? 0) !== $requestedScopeId) {
        http_response_code(403); echo '<h2>Download link expired or does not match this report. Please generate the report again.</h2>'; exit;
    }
    $user = ['user_id' => $t['user_id'], 'role' => $t['role']];
} else {
    // Legacy bearer tokens in URLs leak into browser history and logs.
    // Continue to accept only the Authorization header for this fallback.
    $user = require_auth();
}

// Z-1/B-3: report generation is a reporting-role action. Technicians
// enter results; signed reports are produced by admin/deo/HOD.
if (!in_array($user['role'] ?? '', ['superadmin', 'admin', 'deo', 'hod'], true)
    && !ctms_user_is_assigned_report_reviewer($pdo, (int)($user['user_id'] ?? 0))) {
    http_response_code(403);
    echo '<h2>Report generation is restricted to reporting roles.</h2>'; exit;
}

// ── Determine mode: single sample or full lot ──
$sample_id = intval($_GET['sample_id'] ?? 0);
$lot_id    = intval($_GET['lot_id'] ?? 0);

if ($sample_id) {
    // Single-sample mode
    $stmt = $pdo->prepare("SELECT s.*, l.lot_number, l.agency_type
                           FROM samples s JOIN lots l ON s.lot_id = l.id
                           WHERE s.id = ? AND s.is_deleted = 0 AND l.is_deleted = 0");
    $stmt->execute([$sample_id]);
    $sample_row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sample_row) { echo '<h2>Sample not found</h2>'; exit; }
    if (!ctms_user_has_lot_access($pdo, (int)($user['user_id'] ?? 0), (int)$sample_row['lot_id'], 'view')) { http_response_code(403); echo '<h2>You are not assigned to this lot.</h2>'; exit; }
    $lot = [
        'id'         => $sample_row['lot_id'],
        'lot_number' => $sample_row['lot_number'],
        'agency_type'=> $sample_row['agency_type'],
    ];
    $samples = [$sample_row];
} elseif ($lot_id) {
    // Legacy lot mode (all samples)
    $stmt = $pdo->prepare("SELECT * FROM lots WHERE id = ? AND is_deleted = 0");
    $stmt->execute([$lot_id]);
    $lot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lot) { echo '<h2>Lot not found</h2>'; exit; }
    if (!ctms_user_has_lot_access($pdo, (int)($user['user_id'] ?? 0), (int)$lot['id'], 'view')) { http_response_code(403); echo '<h2>You are not assigned to this lot.</h2>'; exit; }
    $stmt = $pdo->prepare("SELECT * FROM samples WHERE lot_id = ? AND is_deleted = 0 ORDER BY serial_number ASC");
    $stmt->execute([$lot_id]);
    $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    echo '<h2>Missing sample_id or lot_id</h2>'; exit;
}

if (empty($samples)) { echo '<h2>No samples found.</h2>'; exit; }

// ?issue=1 (with a valid signature) means the user pressed Print or
// Download in Word. Only then is a number allocated and the report
// registered; a plain preview never consumes a number.
$report_scope    = $sample_id ? 'report_sample' : 'report_lot';
$report_scope_id = $sample_id ? $sample_id : $lot_id;
$issue_token     = ctms_report_issue_token((int)($user['user_id'] ?? 0), $report_scope, (int)$report_scope_id);
$issue_mode      = false;
if (!empty($_GET['issue'])) {
    if (!ctms_report_issue_token_check($_GET['issue_token'] ?? '', (int)($user['user_id'] ?? 0), $report_scope, (int)$report_scope_id)) {
        http_response_code(403);
        echo '<h2>This print link has expired. Reopen the report and print it again.</h2>';
        exit;
    }
    $issue_mode = true;
    $issue_token = (string)$_GET['issue_token'];
}
$GLOBALS['ctms_report_issue_mode']  = $issue_mode;
$GLOBALS['ctms_report_issued']      = [];
$GLOBALS['ctms_report_pending']     = false;

$is_cheque = strtoupper($lot['agency_type'] ?? '') === 'CHEQUE';

// ── Sprint 1 / D-05 helper: atomic report numbering ─────────
// Increments the counter for $key atomically and returns e.g.
// "OES-T-0007". Returns null when the key is not seeded or the
// table does not exist yet, so the caller can fall back to the
// legacy 'RPT-####' format instead of printing a blank number.
function next_report_number($pdo, $key) {
    if ($key === '') return null;
    try {
        // Auto-seed unknown series (e.g. a brand-new appliance type
        // that elec_type_code() just invented) so the report never
        // falls back to legacy RPT-#### just because nobody ran a
        // manual INSERT into report_counters.
        $ins = $pdo->prepare("INSERT IGNORE INTO report_counters (counter_key, next_number)
                              VALUES (?, 1)");
        $ins->execute([$key]);

        // next_number = the next value to issue. LAST_INSERT_ID(next_number)
        // captures the current value for the SELECT below, then +1 advances
        // the counter — so a row seeded at 1 yields …-0001 on first use.
        $upd = $pdo->prepare("UPDATE report_counters
                              SET next_number = LAST_INSERT_ID(next_number) + 1
                              WHERE counter_key = ?");
        $upd->execute([$key]);
        if ($upd->rowCount() === 0) return null;
        $n = (int)$pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn();
        return $key . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    } catch (\PDOException $e) {
        return null;   // table missing → caller falls back to legacy format
    }
}

// ── Report registry: re-prints keep their number, search stays possible ──
// Every generated report is recorded in the reports table with the
// fields the archive search needs (sample type, SD/ref identifier,
// client). When a report for a sample already exists, its number is
// REUSED — re-opening or re-printing a report no longer burns the
// next number of the series.
function find_existing_report($pdo, $sid) {
    try {
        $st = $pdo->prepare("SELECT report_number FROM reports
                             WHERE sample_id = ? AND is_deleted = 0
                             ORDER BY id DESC LIMIT 1");
        $st->execute([$sid]);
        $n = $st->fetchColumn();
        return $n ? (string)$n : null;
    } catch (\PDOException $e) {
        return null;   // table/columns missing → numbering falls through
    }
}

function register_report($pdo, $sid, $lot_number, $report_number, $format_used,
                         $sample_type, $identifier, $client_name, $user_id) {
    try {
        // checksum_hash = the printed Verify Code — lets Report Search
        // confirm a paper report's authenticity from the code alone.
        $vset = verify_settings($pdo);
        $st = $pdo->prepare("INSERT INTO reports
            (sample_id, lot_number, report_number, format_used, sample_type,
             identifier, client_name, checksum_hash, status, generated_by, generated_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, NOW(), NOW())");
        $st->execute([$sid, $lot_number, $report_number, $format_used,
                      $sample_type, $identifier, $client_name,
                      verify_checksum($report_number, $vset['secret']), $user_id]);
        $reportId = (int)$pdo->lastInsertId();
        try {
            $stage = ctms_report_review_enabled($pdo) ? 'draft' : 'issued';
            $pdo->prepare('INSERT INTO report_approvals (report_id, stage, issued_by, issued_at) VALUES (?, ?, ?, ?)')
                ->execute([$reportId, $stage, $stage === 'issued' ? $user_id : null, $stage === 'issued' ? date('Y-m-d H:i:s') : null]);
            $pdo->prepare('INSERT INTO report_workflow_events (report_id, actor_id, action, from_stage, to_stage, created_at) VALUES (?, ?, \'CREATED\', NULL, ?, NOW())')
                ->execute([$reportId, $user_id, $stage]);
        } catch (Throwable $workflowError) {
            error_log('[CTMS report workflow] New report workflow row could not be created: ' . $workflowError->getMessage());
        }
    } catch (\PDOException $e) { /* registry failure must never block printing */ }
}

// ── Report verification (QR) helpers ─────────────────────────
// Every printed report carries a QR pointing at the PUBLIC
// verify.php page: <base>?r=<report_no>&c=<checksum>. The
// checksum (first 8 hex of HMAC-SHA256(report, secret)) makes report
// numbers un-fakeable by guessing. verify_base_url is a system
// setting so the QR works when deployed on the LAN IP; it
// defaults to whatever host served this page.
function verify_settings($pdo) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $base = ''; $secret = '';
    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings
                             WHERE setting_key IN ('verify_base_url','verify_secret')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $base   = trim((string)($rows['verify_base_url'] ?? ''));
    } catch (\PDOException $e) { /* defaults */ }
    $secret = ctms_verify_secret($pdo);
    if ($base === '') {
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
              . rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/report_generate.php'))), '/')
              . '/verify.php';
    }
    $cache = ['base' => $base, 'secret' => $secret];
    return $cache;
}
function verify_checksum($report_no, $secret) {
    // SEC-003: HMAC-SHA256 truncated to 8 hex chars (the printed
    // Verify Code) instead of the old truncated MD5. verify.php still
    // accepts the previously stored MD5 value for old reports.
    return ctms_verify_code((string)$report_no, (string)$secret);
}

// ── Numbering: provisional on preview, issued on print (H-02) ──
// Looking at a report must not consume a series number. A preview only
// reads the next number for display; the number is allocated and the
// report is registered when the page is explicitly printed or exported.
function peek_report_number($pdo, $key) {
    if ($key === '') return null;
    static $shown = [];   // several samples in one document preview in sequence
    try {
        // Seeding a missing series does not consume a number.
        $pdo->prepare("INSERT IGNORE INTO report_counters (counter_key, next_number) VALUES (?, 1)")
            ->execute([$key]);
        $st = $pdo->prepare("SELECT next_number FROM report_counters WHERE counter_key = ?");
        $st->execute([$key]);
        $n = $st->fetchColumn();
        if ($n === false) return null;
        $n = (int)$n + ($shown[$key] ?? 0);
        $shown[$key] = ($shown[$key] ?? 0) + 1;
        return $key . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    } catch (\PDOException $e) {
        return null;
    }
}

// A short-lived signature that lets the report page ask the server to
// number the report when the user presses Print / Download in Word.
// ctms_report_issue_token()/ctms_report_issue_token_check() live in db.php
// so the archive ZIP/email helpers can request an issued report too.

// ── Electronics sample-type codes for report numbering ──────
// Report number carries the sample type: EL-HF-0001 (handfree),
// EL-GS-0001 (gas stove), … Known types map to fixed codes; anything
// else auto-derives its code from the initials of the entered name
// (Example Client operators type new names all the time), capped at 4 letters.
function elec_type_code($material) {
    static $map = [
        'HANDFREE' => 'HF', 'HANDS FREE' => 'HF', 'HANDSFREE' => 'HF', 'HAND FREE' => 'HF',
        'HEADPHONE' => 'HP', 'HEADPHONES' => 'HP', 'EARPHONE' => 'HP', 'EARPHONES' => 'HP',
        'GAS STOVE' => 'GS', 'GAS STOVES' => 'GS', 'GAS COOKER' => 'GS', 'COOKING RANGE' => 'GS',
        'ELECTRIC IRON' => 'IR', 'IRON' => 'IR', 'DRY IRON' => 'IR', 'STEAM IRON' => 'IR',
        'FAN' => 'FN', 'CEILING FAN' => 'FN', 'PEDESTAL FAN' => 'FN', 'EXHAUST FAN' => 'FN', 'TABLE FAN' => 'FN',
        'WATER DISPENSER' => 'WD', 'DISPENSER' => 'WD',
        'ELECTRIC KETTLE' => 'KT', 'KETTLE' => 'KT',
        'TOASTER' => 'TS', 'ROOM COOLER' => 'RC', 'COOLER' => 'RC', 'AIR COOLER' => 'RC',
        'EXTENSION CORD' => 'EC', 'EXTENSION BOARD' => 'EC',
        'SWITCH' => 'SW', 'CABLE' => 'CB', 'WIRE' => 'CB', 'WIRING' => 'CB',
        'BLOWER' => 'BL', 'HEATER' => 'HT', 'ROOM HEATER' => 'HT', 'GEYSER' => 'GY',
        'WASHING MACHINE' => 'WM', 'REFRIGERATOR' => 'RF', 'DEEP FREEZER' => 'DF', 'FREEZER' => 'DF',
        'MIXER' => 'MX', 'BLENDER' => 'MX', 'JUICER' => 'MX',
        'UPS' => 'UPS', 'ONLINE UPS' => 'UPS', 'OFFLINE UPS' => 'UPS',
        'TYRE' => 'TY', 'TIRE' => 'TY', 'TYRES' => 'TY',
        'RAZOR' => 'RB', 'RAZOR BLADE' => 'RB', 'SAFETY RAZOR' => 'RB',
    ];
    $t = strtoupper(preg_replace('/[^A-Za-z ]/', ' ', (string)$material));
    $t = trim(preg_replace('/\s+/', ' ', $t));
    if ($t === '') return 'GEN';
    if (isset($map[$t])) return $map[$t];
    $code = '';
    foreach (explode(' ', $t) as $w) {
        if ($w === '') continue;
        $code .= $w[0];
        if (strlen($code) >= 4) break;
    }
    return $code !== '' ? $code : 'GEN';
}

// ── Element display orders (matching Word templates) ──
$XRF_ELEMENT_ORDER = ['Fe','Cr','Mn','Ni','Mo','Cu','Ti','C','S','P','Si'];
$OES_ELEMENT_ORDER = ['Fe','C','Si','Mn','Cr','Ni','S','P','Mo','Cu','Sn'];

// ── Standard remarks text (from Word templates) ──
// Each line is numbered with Roman numerals: I., II., III., IV. …
function to_roman($n) {
    $map = [10=>'X', 9=>'IX', 5=>'V', 4=>'IV', 1=>'I'];
    $out = '';
    foreach ($map as $v => $s) {
        while ($n >= $v) { $out .= $s; $n -= $v; }
    }
    return $out;
}

function remarks_text($raw) {
    // DB notes may already carry "i." style prefixes — strip them so
    // the numbering below is always clean and sequential.
    $lines = [];
    if (trim((string)$raw)) {
        foreach (explode("\n", $raw) as $line) {
            $line = trim(preg_replace('/^[ivx]+\.\s*/i', '', $line));
            if ($line !== '') $lines[] = $line;
        }
    }
    if (!$lines) {
        $lines = [
            'Grades identified are the best close match of Standard Built-in Library & or open literature.',
            'Results pertain to the sample received and sampling was done at customer\'s end.',
            'No counter portion/un-expanded part of the sample has been retained.',
            'Test report cannot be made a part of the litigation / court proceeding or other commercial purposes etc.',
        ];
    }
    $out = '';
    foreach ($lines as $i => $line) {
        $out .= to_roman($i + 1) . '. ' . htmlspecialchars($line) . '<br>';
    }
    return rtrim($out, '<br>');
}

// ── Render composition table (XRF or OES) ──
// FIX: Only ONE data row per sample (all elements in columns of that single row)
function render_composition_table($title, $rows, $element_order, $sample_label = '', $fieldMeta = []) {
    if (empty($rows)) return '';

    // Build element->value map from data rows (one row per element)
    $data = [];
    foreach ($rows as $r) {
        $el = $r['element'] ?? '';
        if (isset($fieldMeta[$el]) && !(int)$fieldMeta[$el]['is_active']) continue;
        $data[$el] = $r['percentage'] ?? '';
    }

    // Determine which elements to show (use order, skip if no data)
    $display_elements = [];
    foreach ($element_order as $el) {
        if (isset($data[$el])) {
            $display_elements[] = $el;
        }
    }
    // Add any extra elements not in the order list
    foreach ($data as $el => $val) {
        if (!in_array($el, $display_elements)) {
            $display_elements[] = $el;
        }
    }

    if (empty($display_elements)) return '';

    $num_elements = count($display_elements);

    $html = '<div class="section-title">' . htmlspecialchars($title) . '</div>';
    $html .= '<table class="data-table">';

    // Row 0: Merged header
    $html .= '<tr>';
    $html .= '<td class="th" rowspan="2">Sr.<br>No.</td>';
    $html .= '<td class="th" rowspan="2">Sample</td>';
    $html .= '<td class="th section-header" colspan="' . $num_elements . '">Chemical Composition Wt. %</td>';
    $html .= '</tr>';

    // Row 1: Individual element names
    $html .= '<tr>';
    foreach ($display_elements as $el) {
        $html .= '<td class="th">' . htmlspecialchars((string)($fieldMeta[$el]['label'] ?? $el)) . '</td>';
    }
    $html .= '</tr>';

    // ── FIX: Only ONE data row (not per-element) ──
    $html .= '<tr>';
    $html .= '<td class="td-num">01</td>';
    $html .= '<td class="td-sample">' . htmlspecialchars($sample_label) . '</td>';   // D-06
    foreach ($display_elements as $el) {
        $val = isset($data[$el]) ? htmlspecialchars((string)$data[$el]) : '';
        $html .= '<td class="td-val">' . $val . '</td>';
    }
    $html .= '</tr>';

    $html .= '</table>';
    return $html;
}

// ── Render tensile table ──
function render_tensile_table($rows, $sample_label = '', $fields = [], $customValues = []) {
    if (empty($rows)) return '';
    $hasFieldConfiguration = !empty($fields);
    $fields = array_values(array_filter($fields, fn($f) => (int)($f['is_active'] ?? 1) === 1));
    if (!$fields && !$hasFieldConfiguration) $fields = [
        ['field_key'=>'Dia (mm)','label'=>'Diameter (mm)'], ['field_key'=>'Size','label'=>'Size (mm)'],
        ['field_key'=>'Cross Section Area (mm²)','label'=>'Cross Section Area (mm²)'], ['field_key'=>'Gauge Length (mm)','label'=>'Gauge Length (mm)'],
        ['field_key'=>'Yield Strength (MPa)','label'=>'Yield Strength (MPa)'], ['field_key'=>'Ultimate Tensile Strength (MPa)','label'=>'Ultimate Tensile Strength (MPa)'], ['field_key'=>'Elongation %','label'=>'Elongation (%)'],
    ];
    if (!$fields) return '<div class="section-title">TENSILE TEST (UTM) — No result fields selected</div>';

    $html = '<div class="section-title">TENSILE TEST (UTM)</div>';
    $html .= '<table class="data-table">';

    // Row 0: Merged header
    $html .= '<tr>';
    $html .= '<td class="th" rowspan="2">Sr.<br>No.</td>';
    $html .= '<td class="th" rowspan="2">Sample</td>';
    $html .= '<td class="th section-header" colspan="' . count($fields) . '">Detailed Information</td>';
    $html .= '</tr>';

    // Row 1: Column names
    $html .= '<tr>';
    foreach ($fields as $field) {
        $label = trim((string)$field['label']);
        $unit = trim((string)($field['unit'] ?? ''));
        if ($unit !== '' && !str_contains($label, $unit)) $label .= ' (' . $unit . ')';
        $html .= '<td class="th sub-header">' . htmlspecialchars($label) . '</td>';
    }
    $html .= '</tr>';

    // Data rows — one per tensile test record
    $rn = 0;
    foreach ($rows as $r) {
        $rn++;
        $html .= '<tr>';
        $html .= '<td class="td-num">' . str_pad($rn, 2, '0', STR_PAD_LEFT) . '</td>';
        $html .= '<td class="td-sample">' . htmlspecialchars($sample_label) . '</td>';   // D-06
        $dbMap = ['Dia (mm)'=>'width','Size'=>'specimen_no','Cross Section Area (mm²)'=>'cross_section_area',
            'Gauge Length (mm)'=>'gauge_length','Yield Strength (MPa)'=>'yield_strength',
            'Ultimate Tensile Strength (MPa)'=>'tensile_strength','Elongation %'=>'elongation'];
        foreach ($fields as $field) {
            $key = (string)$field['field_key'];
            $value = isset($dbMap[$key]) ? ($r[$dbMap[$key]] ?? '') : ($customValues[$key] ?? '');
            $html .= '<td class="td-val">' . htmlspecialchars((string)$value) . '</td>';
        }
        $html .= '</tr>';
    }

    $html .= '</table>';
    return $html;
}

// ── Expand sample-type abbreviations to their full names ──────
// Admins enter short codes (HRC, CRC, …) in the Material Description
// column while making the sheet; the printed report must show the
// full name. Extend $map as the lab provides more codes.
function expand_abbrev($raw) {
    static $map = [
        'HRC' => 'Hot Rolled Stainless Steel Sheet',
        'CRC' => 'Cold Rolled Stainless Steel Sheet',
        'SWR' => 'Steel Wire Rod',
        'SIB' => 'Steel Ingots and Billets',
        'CSS' => 'Carbon Structural Steel',
        'TIN' => 'Tin Plate',
        'SS'  => 'Stainless Steel',
    ];
    $t = trim((string)$raw);
    if ($t === '') return '';
    return $map[strtoupper($t)] ?? $t;
}

// ── Build one sample page ──
function build_sample_page($sample, $is_cheque, $lot, $pdo) {
    global $user;
    $sid = $sample['id'];
    $extra = json_decode($sample['extra_data'] ?? '{}', true) ?: [];

    $material_raw = trim((string)($extra['Material Description'] ?? ''));

    // ── "Sample" column of the composition / tensile tables ─────
    // Shows the full material name (same expansion as the Type of
    // Sample field, e.g. HRC → Hot Rolled Stainless Steel Sheet).
    // Falls back to the SD / reference identifier when no material
    // was recorded, so the column can never print blank.
    $sample_label = expand_abbrev($material_raw);
    if ($sample_label === '') {
        if (!$is_cheque) {
            $sample_label = trim((string)($sample['sd_number'] ?? ''));
        } else {
            $sample_label = trim((string)($sample['reference_number'] ?? ''));
            if ($sample_label === '') $sample_label = trim((string)($sample['client_name'] ?? ''));
        }
    }
    if ($sample_label === '') $sample_label = 'SD-' . $sid;

    // Info fields
    if ($is_cheque) {
        $field1_label = 'Ref. No.';
        $field1_value = $extra['Reference Number'] ?? ($sample['reference_number'] ?? '');
        $field2_label = 'Code';
        $field2_value = $extra['Code'] ?? ($sample['inspector_code'] ?? $sample['customer_code'] ?? '');
        $item_desc    = $extra['Item Description'] ?? '';
        $grade_match  = $extra['Grade Match'] ?? '';
        $standard_ref = '';
    } else {
        $field1_label = 'SD No.';
        $field1_value = $sample['sd_number'] ?? '';
        $field2_label = 'Request No.';
        $field2_value = $extra['Request No'] ?? ($sample['request_number'] ?? '');
        $item_desc    = $extra['Item Description'] ?? '';
        $grade_match  = $extra['Grade Match'] ?? '';
        $standard_ref = '';
    }

    // Customer Name — the company that submitted the samples (Example Client etc.).
    // Legacy rows stored the Material Description in samples.client_name
    // (the old form had no customer field), so client_name is only
    // trusted when it does NOT duplicate the Material Description.
    $customer_name = trim((string)($extra['Customer Name'] ?? $extra['Company'] ?? ''));
    if ($customer_name === '') {
        $cn = trim((string)($sample['client_name'] ?? ''));
        if ($cn !== '' && strcasecmp($cn, $material_raw) !== 0) $customer_name = $cn;
    }
    // Type of sample — full name of the material abbreviation (HRC → Hot Rolled Stainless Steel Sheet)
    $type_of_sample = expand_abbrev($material_raw !== '' ? $material_raw : ($sample['sample_description'] ?? ''));
    // Sprint 1 / D-05 + D-04: the report number and the test date
    // are computed further below, once the sample's test rows
    // (and therefore its data family) are known.

    // Fetch test data
    $tStmt = $pdo->prepare("SELECT * FROM test_tensile WHERE sample_id = ? AND is_deleted = 0 ORDER BY version DESC");
    $tStmt->execute([$sid]);
    $tensile_rows = $tStmt->fetchAll(PDO::FETCH_ASSOC);

    $xStmt = $pdo->prepare("SELECT * FROM test_xrf WHERE sample_id = ? AND is_deleted = 0 ORDER BY element ASC");
    $xStmt->execute([$sid]);
    $xrf_rows = $xStmt->fetchAll(PDO::FETCH_ASSOC);

    $oStmt = $pdo->prepare("SELECT * FROM test_spectroscopy WHERE sample_id = ? AND is_deleted = 0 ORDER BY element ASC");
    $oStmt->execute([$sid]);
    $oes_rows = $oStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── ELECTRONICS family ─────────────────────────────────────
    // Primary source: test_electronics table (proper results storage
    // with technician attribution). Fallbacks: completed Electronics
    // assignment rows and legacy notes-JSON — older samples keep
    // rendering unchanged.
    $elec_params = [];
    try {
        $et = $pdo->prepare("SELECT parameter, specified_range, observed_value, remarks
                             FROM test_electronics
                             WHERE sample_id = ? AND is_deleted = 0
                             ORDER BY id ASC");
        $et->execute([$sid]);
        foreach ($et->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (trim((string)$r['parameter']) === '') continue;
            $elec_params[] = [
                'Test Parameter'  => $r['parameter'],
                'Specified Range' => (string)$r['specified_range'],
                'Observed Value'  => (string)$r['observed_value'],
                'Remarks'         => (string)$r['remarks'],
            ];
        }
    } catch (\PDOException $e) { /* table not present yet — legacy paths below */ }
    $elec_note = trim((string)($sample['notes'] ?? ''));
    $decode_elec = function ($json) {
        $dec = json_decode((string)$json, true);
        if (!is_array($dec)) return [];
        if (isset($dec['params']) && is_array($dec['params'])) {
            $out = [];
            foreach ($dec['params'] as $p) {
                if (!is_array($p)) continue;
                // Accept both stored report keys ("Test Parameter") and
                // the live lab-sheet keys (parameter / range / observed).
                $tp = trim((string)($p['Test Parameter'] ?? $p['parameter'] ?? ''));
                if ($tp === '') continue;
                $out[] = [
                    'Test Parameter'  => $tp,
                    'Specified Range' => (string)($p['Specified Range'] ?? $p['range']    ?? ''),
                    'Observed Value'  => (string)($p['Observed Value']  ?? $p['observed'] ?? ''),
                    'Remarks'         => (string)($p['Remarks']         ?? $p['remarks']  ?? ''),
                ];
            }
            return $out;
        }
        if (isset($dec['Test Parameter']) || isset($dec['parameter'])) {
            $tp = trim((string)($dec['Test Parameter'] ?? $dec['parameter'] ?? ''));
            if ($tp === '') return [];
            return [[
                'Test Parameter'  => $tp,
                'Specified Range' => (string)($dec['Specified Range'] ?? $dec['range']    ?? ''),
                'Observed Value'  => (string)($dec['Observed Value']  ?? $dec['observed'] ?? ''),
                'Remarks'         => (string)($dec['Remarks']         ?? $dec['remarks']  ?? ''),
            ]];
        }
        return [];
    };
    // Legacy notes-JSON only when the proper table had nothing.
    if (empty($elec_params) && $elec_note !== '') $elec_params = $decode_elec($elec_note);
    // Fallback: completed Electronics assignment row (covers samples
    // that WERE routed through mapping and saved there instead).
    if (empty($elec_params)) {
        try {
            $eStmt = $pdo->prepare("SELECT result_data FROM sample_lab_assignments
                                    WHERE sample_id = ? AND lab_stream LIKE '%Electronics%' AND status = 'complete'
                                    ORDER BY id DESC LIMIT 1");
            $eStmt->execute([$sid]);
            $rd = $eStmt->fetchColumn();
            if ($rd) $elec_params = $decode_elec((string)$rd);
        } catch (\PDOException $e) { /* table missing — ignore */ }
    }

    if (empty($tensile_rows) && empty($xrf_rows) && empty($oes_rows) && empty($elec_params)) return '';

    // ── Sprint 1 / D-01 + D-02: data-family rules ──────────────
    // D-01 (signature): results.php stores NULL template ids, so the
    // old assignment-join never matched and steel reports printed
    // "no signature required". The signature requirement is now
    // derived from the data actually present: any tensile / XRF /
    // OES row means a steel report and gets the full signature
    // block; only the electronics family (no rows in these tables)
    // uses the no-signature note.
    $has_steel_data = !empty($tensile_rows) || !empty($xrf_rows) || !empty($oes_rows);
    $requires_sig   = $has_steel_data;
    // Document orientation: steel pages print landscape (Word template);
    // electronics-only reports print PORTRAIT like the Example Client format.
    if ($has_steel_data) $GLOBALS['any_steel_page'] = true;

    // D-02 (remarks): pick the family the seeding actually uses
    // (steel_cheque / steel_sd / electronics) instead of the dead
    // 'sd_cheque' key. The 'no_sd_note' label covers SD-format
    // steel samples that have no SD number stored yet.
    // remarks_text() below still falls back to the hard-coded
    // template text if a row is missing, so the remarks block can
    // never come out blank.
    if (!$has_steel_data) {
        $notes_family = 'electronics';   $notes_label = 'default_remarks';
    } elseif (!empty($sample['sd_number'])) {
        $notes_family = 'steel_sd';      $notes_label = 'default_remarks';
    } elseif ($is_cheque) {
        $notes_family = 'steel_cheque';  $notes_label = 'default_remarks';
    } else {
        $notes_family = 'steel_sd';      $notes_label = 'no_sd_note';
    }
    $nstmt = $pdo->prepare("SELECT notes_text FROM report_notes_master
                            WHERE family = ? AND label = ? AND active = 1 LIMIT 1");
    $nstmt->execute([$notes_family, $notes_label]);
    $notes_raw = $nstmt->fetchColumn() ?: '';

    // ── Sprint 1 / D-05: report number from report_counters ────
    // OES+Tensile → OES-T, XRF+Tensile → X-T, tensile-only → T.
    // (OUPS / RBT / ST / SM stay reserved for the lab's other
    // series and can be mapped in as needed.)
    // UPDATE ... SET next_number = LAST_INSERT_ID(next_number + 1)
    // makes the increment atomic: two staff members generating
    // reports at the same moment can never receive the same number.
    // A preview only peeks at the next number; it is consumed on print.
    if (!empty($oes_rows) && !empty($tensile_rows))     $counter_key = 'OES-T';
    elseif (!empty($xrf_rows) && !empty($tensile_rows)) $counter_key = 'X-T';
    elseif (!empty($tensile_rows))                      $counter_key = 'T';
    elseif (!empty($oes_rows))                          $counter_key = 'OES';
    elseif (!empty($xrf_rows))                          $counter_key = 'X';
    elseif (!empty($elec_params))                       $counter_key = 'EL-' . elec_type_code($material_raw);
    else                                                $counter_key = '';

    // Registry first: a re-open / re-print of the same sample keeps
    // its original number instead of burning the next in the series.
    $report_no = find_existing_report($pdo, $sid);
    if ($report_no === null) {
        if (!empty($GLOBALS['ctms_report_issue_mode'])) {
            $report_no = next_report_number($pdo, $counter_key);
            if ($report_no === null) {
                // Counter table missing or series key not seeded — legacy format
                $report_no = 'RPT-' . str_pad($sid, 4, '0', STR_PAD_LEFT);
            }
            register_report(
                $pdo, $sid,
                $sample['lot_number']      ?? ($lot['lot_number'] ?? ''),
                $report_no,
                $counter_key !== '' ? $counter_key : 'LEGACY',
                $type_of_sample,
                trim((string)($sample['sd_number'] ?? '')) !== ''
                    ? (string)$sample['sd_number']
                    : (string)($sample['reference_number'] ?? ''),
                $customer_name,
                (int)($user['user_id'] ?? $user['id'] ?? 0)
            );
            $GLOBALS['ctms_report_issued'][] = $report_no;
        } else {
            $report_no = peek_report_number($pdo, $counter_key);
            if ($report_no === null) {
                // Legacy RPT-#### format is derived from the sample id, so it
                // is stable and needs no counter.
                $report_no = 'RPT-' . str_pad($sid, 4, '0', STR_PAD_LEFT);
            } else {
                $GLOBALS['ctms_report_pending'] = true;
            }
        }
    }

    // ── Sprint 1 / D-04: real test date, not "today" ───────────
    // Take the latest test_date across the sample's tensile, XRF and
    // OES rows (the same rows the page already queries); fall back
    // to today only when no dated row exists at all.
    $dStmt = $pdo->prepare("
        SELECT MAX(test_date) FROM (
            SELECT test_date FROM test_tensile      WHERE sample_id = ? AND is_deleted = 0 AND test_date IS NOT NULL
            UNION ALL
            SELECT test_date FROM test_xrf          WHERE sample_id = ? AND is_deleted = 0 AND test_date IS NOT NULL
            UNION ALL
            SELECT test_date FROM test_spectroscopy WHERE sample_id = ? AND is_deleted = 0 AND test_date IS NOT NULL
        ) AS all_tests
    ");
    $dStmt->execute([$sid, $sid, $sid]);
    $last_test_date = $dStmt->fetchColumn();
    if ($last_test_date) {
        $test_date = date('F d, Y', strtotime($last_test_date));
    } elseif (!empty($elec_params) && !empty($sample['updated_at'])) {
        // Electronics results carry no test_date row — the moment the
        // technician saved the results (updated_at) is the test date.
        $test_date = date('F d, Y', strtotime($sample['updated_at']));
    } else {
        $test_date = date('F d, Y');
    }

    // Build composition table(s)
    $comp_html = '';
    $oesFieldMeta = array_column(ctms_result_fields($pdo, 'oes', false), null, 'field_key');
    $xrfFieldMeta = array_column(ctms_result_fields($pdo, 'xrf', false), null, 'field_key');
    if (!empty($oes_rows)) {
        global $OES_ELEMENT_ORDER;
        $comp_html .= render_composition_table('CHEMICAL COMPOSITION (OES)', $oes_rows, $OES_ELEMENT_ORDER, $sample_label, $oesFieldMeta);
    }
    if (!empty($xrf_rows)) {
        global $XRF_ELEMENT_ORDER;
        $comp_html .= render_composition_table('CHEMICAL COMPOSITION (XRF)', $xrf_rows, $XRF_ELEMENT_ORDER, $sample_label, $xrfFieldMeta);
    }

    // Build tensile table
    $tensile_html = '';
    if (!empty($tensile_rows)) {
        $tensileFieldStmt = $pdo->prepare("SELECT result_data FROM sample_lab_assignments
            WHERE sample_id = ? AND lab_stream LIKE '%Tensile%' AND status = 'complete' ORDER BY id DESC LIMIT 1");
        $tensileFieldStmt->execute([$sid]);
        $tensileResultData = json_decode((string)($tensileFieldStmt->fetchColumn() ?: '{}'), true) ?: [];
        $tensileCustomValues = is_array($tensileResultData['custom_values'] ?? null) ? $tensileResultData['custom_values'] : [];
        $tensile_html = render_tensile_table($tensile_rows, $sample_label, ctms_result_fields($pdo, 'tensile', false), $tensileCustomValues);
    }

    // Build electronics table — one row PER PARAMETER (a sample can
    // carry many parameter rows, e.g. an appliance's full Example Client
    // checklist). No signature for this family (computer-generated
    // report, link printed after the no-signature note).
    $elec_html = '';
    if (!empty($elec_params)) {
        $elec_html = '<div class="section-title">ELECTRONICS TEST RESULTS</div>'
            . '<table class="data-table"><tr>'
            . '<td class="th" style="width:6%">Sr.<br>No.</td>'
            . '<td class="th" style="width:16%">Sample</td>'
            . '<td class="th">Test Parameter</td>'
            . '<td class="th">Specified Range</td>'
            . '<td class="th">Observed Value</td>'
            . '<td class="th">Remarks</td>'
            . '</tr>';
        $rn = 0;
        foreach ($elec_params as $p) {
            $rn++;
            $elec_html .= '<tr>'
                . '<td class="td-num">' . str_pad((string)$rn, 2, '0', STR_PAD_LEFT) . '</td>'
                . '<td class="td-sample">' . htmlspecialchars($sample_label) . '</td>'
                . '<td class="td-val">' . htmlspecialchars((string)($p['Test Parameter'] ?? '')) . '</td>'
                . '<td class="td-val">' . htmlspecialchars((string)($p['Specified Range'] ?? '')) . '</td>'
                . '<td class="td-val">' . htmlspecialchars((string)($p['Observed Value'] ?? '')) . '</td>'
                . '<td class="td-val">' . htmlspecialchars((string)($p['Remarks'] ?? '')) . '</td>'
                . '</tr>';
        }
        $elec_html .= '</table>';
    }

    // Keep signatures and verification together at the lower end of the report.
    // The Word exporters turn this into a vertical footer so the QR sits below
    // the signature labels instead of beside them.
    $vset = verify_settings($pdo);
    $vcode = verify_checksum($report_no, $vset['secret']);
    $verify_url = $vset['base'] . '?r=' . rawurlencode($report_no) . '&c=' . $vcode;
    // Prefer a server-rendered QR so the report does not depend on a browser
    // asset request (which can fail in embedded reports or on dev proxy paths).
    // Keep the canvas as a fallback for installations without the QR package.
    $qr_markup = '<canvas class="qr-canvas" data-verify="' . htmlspecialchars($verify_url, ENT_QUOTES) . '"></canvas>';
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) {
        try {
            require_once $autoload;
            if (class_exists(\chillerlan\QRCode\QRCode::class)) {
                // Word's legacy .doc/HTML importer does not reliably accept
                // inline SVG data URIs. Embed a PNG so the QR is portable in
                // Word as well as in the browser and generated PDFs.
                $qr_options = new \chillerlan\QRCode\QROptions([
                    'outputInterface' => \chillerlan\QRCode\Output\QRGdImagePNG::class,
                    'scale' => 4,
                ]);
                $qr_data_uri = (new \chillerlan\QRCode\QRCode($qr_options))->render($verify_url);
                $qr_markup = '<img class="qr-img" width="58" height="58" alt="Report verification QR" src="'
                    . htmlspecialchars($qr_data_uri, ENT_QUOTES, 'UTF-8') . '">';
            }
        } catch (Throwable $e) {
            error_log('[CTMS] QR image generation failed for ' . $report_no . ': ' . $e->getMessage());
        }
    }
    $qr_box = '<div class="qr-box">'
        . $qr_markup
        . '<div class="qr-caption">Verify: <span class="mono" data-report-number="' . htmlspecialchars($report_no, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($report_no) . '</span>'
        . ' &nbsp;|&nbsp; Code <span class="mono">' . htmlspecialchars($vcode) . '</span></div>'
        . '</div>';

    if ($requires_sig) {
        $bottom_html = '<div class="bottom-row">'
            . '<div class="bottom-main"><table class="sig-table"><tr>'
            . '<td class="sig-cell"><div class="signature-line">&nbsp;</div><div class="signature-label">Signature of Lab-Technician</div></td>'
            . '<td class="sig-cell"><div class="signature-line">&nbsp;</div><div class="signature-label"><strong>Department Chair</strong><br>Department of Materials Engineering</div></td>'
            . '</tr></table></div>'
            . $qr_box
            . '</div>';
    } else {
        $bottom_html = '<div class="bottom-row">'
            . '<div class="bottom-main"><p class="computer-gen"><em>This is a computer generated report. Hence no signature is required.</em></p></div>'
            . $qr_box
            . '</div>';
    }

    $remarks = remarks_text($notes_raw);

    // Build the info table Row 2 left side (multi-line like Word template)
    $info_row2_left = '<strong>Item Description:</strong> ' . htmlspecialchars($item_desc)
                    . '<br><strong>Grade Match:</strong> ' . htmlspecialchars($grade_match)
                    . '<br><strong>' . htmlspecialchars($field1_label) . ':</strong> ' . htmlspecialchars($field1_value)
                    . '&nbsp;&nbsp;&nbsp;&nbsp;<strong>' . htmlspecialchars($field2_label) . ':</strong> ' . htmlspecialchars($field2_value);

    $info_row2_right = '';
    if ($standard_ref) {
        $info_row2_right = htmlspecialchars($standard_ref) . '<br>';
    }
    $info_row2_right .= 'Test Date: ' . htmlspecialchars($test_date);

    return '
<div class="report-page">

  <!-- MASTHEAD — matches Word document header exactly -->
  <div class="masthead-wrap">
    <div class="masthead-logo">
      <!-- Configure a report logo with CTMS_REPORT_LOGO. -->
      <img src="__CTMS_LOGO_DATA__" class="logo" onerror="this.style.display=\'none\'">
    </div>
    <div class="masthead-center">
      <div class="uni-name">Example Technical University</div>
      <div class="dept-name">Department of Materials Engineering</div>
      <div class="lab-name">MATERIAL TESTING LABORATORIES</div>
    </div>
    <div class="masthead-contact">
      <div>Off: 99261261-68, Ext.: 2516</div>
      <div>Fax: 99261255</div>
      <div>Email: alidad@Example University.edu.pk</div>
    </div>
  </div>

  <!-- INFO TABLE — 3 rows x 2 cols, matches Word template -->
  <table class="info-table">
    <tr>
      <td class="info-left"><strong>Customer Name:</strong> ' . htmlspecialchars($customer_name) . '</td>
      <td class="info-right"><strong>Test Report No:</strong> <span data-report-number="' . htmlspecialchars($report_no, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($report_no) . '</span></td>
    </tr>
    <tr>
      <td class="info-left"><strong>Type of sample:</strong> ' . htmlspecialchars($type_of_sample) . '</td>
      <td class="info-right"><strong>Standard Test Method for:</strong></td>
    </tr>
    <tr>
      <td class="info-left">' . $info_row2_left . '</td>
      <td class="info-right">' . $info_row2_right . '</td>
    </tr>
  </table>

  ' . $comp_html . '
  ' . $tensile_html . '
  ' . $elec_html . '

  <!-- REMARKS — no border (matches Word template) -->
  <div class="remarks-block">
    <strong>Remarks/ Term &amp; Conditions:</strong><br>
    ' . $remarks . '
  </div>

  ' . $bottom_html . '
</div>';
}

// ── Build all pages ──
$pages_html = '';
foreach ($samples as $s) {
    $page = build_sample_page($s, $is_cheque, $lot, $pdo);
    if ($page) $pages_html .= $page;
}

if (!trim($pages_html)) {
    echo '<h2>No completed test data found for samples.</h2>';
    echo '<p>Make sure technicians have submitted results in Phase 3 for all assigned labs.</p>';
    exit;
}

// Title for browser tab
$tab_title = $sample_id
    ? ('Test Report — Sample #' . $sample_id)
    : ('Test Report — ' . htmlspecialchars($lot['lot_number']));

header('Content-Type: text/html; charset=utf-8');
// Steel pages print landscape (Word template); an electronics-only
// document prints PORTRAIT like the Example Client appliance format.
$is_portrait = empty($GLOBALS['any_steel_page']);
$configuredLogoPath = trim((string)(getenv('CTMS_REPORT_LOGO') ?: ''));
$logoPath = $configuredLogoPath !== '' ? realpath($configuredLogoPath) : false;
$logoDataUri = ($logoPath && is_file($logoPath))
    ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($logoPath))
    : '';
$reportAssetBase = !empty($_GET['asset_proxy'])
    ? '/ctms_assets'
    : rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/ctms_server/api/report_generate.php'))), '/') . '/assets';
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title><?= $tab_title ?></title>
<style>
/* ═══════════════════════════════════════════════════════════════
   PAGE SETUP — Exact match to Word template
   Steel: A4 Landscape 11" x 8.5", 1" L/R, 0.5" T/B margins
   Electronics-only: A4 Portrait (Example Client appliance format)
   ═══════════════════════════════════════════════════════════════ */
@page {
  size: A4 <?= $is_portrait ? 'portrait' : 'landscape' ?>;
  margin: 0.5in 1in;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Times New Roman', Times, serif;
  font-size: 10pt;
  color: #000;
  background: #fff;
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
}

/* ═══ Each sample = one printed page ═══ */
.report-page {
  width: 100%;
  page-break-after: always;
  display: flex;
  flex-direction: column;
  /* Landscape printable height = 8.27in − 1in margins = 7.27in; the old
     7.5in forced EVERY report onto a second (blank) page. Portrait
     (electronics) printable height = 11.69in − 1in = 10.69in. */
  min-height: <?= $is_portrait ? '10.2in' : '7.1in' ?>;
}
.report-page:last-child { page-break-after: avoid; }

/* ═══ MASTHEAD — Matches Word document header ═══
   Word header: logo anchored-left, center-aligned text, right-aligned contact
   No table in header, just paragraphs + floating image */
.masthead-wrap {
  width: 100%;
  display: table;
  margin-bottom: 4px;
}
.masthead-logo {
  display: table-cell;
  width: 65px;
  vertical-align: middle;
  text-align: center;
  padding-right: 4px;
}
.logo {
  width: 58px;
  height: 58px;
  object-fit: contain;
}
.masthead-center {
  display: table-cell;
  vertical-align: middle;
  text-align: center;
  padding: 0 4px;
}
.masthead-contact {
  display: table-cell;
  vertical-align: middle;
  text-align: right;
  font-size: 10pt;
  font-weight: bold;
  white-space: nowrap;
  line-height: 1.35;
  padding-left: 4px;
}

/* Masthead font sizes — exact match from Word template XML */
.uni-name {
  font-size: 18pt;
  font-weight: bold;
  line-height: 1.15;
}
.dept-name {
  font-size: 14pt;
  font-weight: normal;
  line-height: 1.15;
}
.lab-name {
  font-size: 12pt;
  font-weight: bold;
  line-height: 1.15;
}

/* ═══ INFO TABLE — 3 rows x 2 cols, NO borders (matches Word) ═══ */
.info-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 6px;
  font-size: 10pt;
  line-height: 1.35;
}
.info-table td {
  border: none;
  vertical-align: top;
  padding: 1px 2px;
  line-height: 1.15;
}
.info-left {
  width: 65%;
}
.info-right {
  width: 35%;
  text-align: right;
}

/* ═══ SECTION TITLES — "CHEMICAL COMPOSITION (XRF)" etc.
   Word: 12pt, Bold, Center, Underlined ═══ */
.section-title {
  text-align: center;
  font-size: 12pt;
  font-weight: bold;
  text-decoration: underline;
  margin: 6px 0 2px 0;
}

/* ═══ DATA TABLES — Composition & Tensile
   Word: default table style borders (1pt black), NO shading on headers ═══ */
.data-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 6px;
  font-size: 10pt;
}

/* Header cells — NO background color (Word has fill=auto/val=clear) */
.data-table .th {
  border: 1px solid #000;
  padding: 2px 4px;
  text-align: center;
  font-weight: bold;
  font-size: 10pt;
  background: transparent;
  vertical-align: middle;
}
.data-table .section-header {
  font-size: 12pt;
}
.data-table .sub-header {
  font-size: 9pt;
  line-height: 1.15;
}

/* Data cells */
.data-table .td-num {
  border: 1px solid #000;
  padding: 2px 4px;
  text-align: center;
  font-size: 10pt;
}
.data-table .td-sample {
  border: 1px solid #000;
  padding: 2px 4px;
  text-align: center;
  font-size: 8pt;
}
.data-table .td-val {
  border: 1px solid #000;
  padding: 2px 4px;
  text-align: center;
  font-size: 10pt;
}

/* ═══ REMARKS — NO border (Word template has explicit nil borders) ═══ */
.remarks-block {
  font-size: 10pt;
  line-height: 1.35;
  margin-top: 4px;
  padding: 4px 6px;
  border: none;
}

/* ═══ SIGNATURE ═══ */
.sig-table {
  width: 100%;
  border: none;
  border-collapse: collapse;
  margin-top: auto;
  padding-top: 12px;
}
.sig-table td {
  border: none;
  text-align: center;
  font-size: 10pt;
  padding: 0 4mm;
  width: 50%;
  vertical-align: top;
}
.signature-line {
  height: 12mm;
  border-bottom: 1px solid #000;
  margin: 0 8% 2mm;
}
.signature-label {
  display: block;
  text-align: center;
  font-size: 9pt;
  line-height: 1.1;
}
.computer-gen {
  text-align: center;
  font-style: italic;
  font-size: 10pt;
  margin-top: auto;
  padding-top: 14px;
}
.verify-line {
  text-align: center;
  font-size: 8pt;
  margin-top: 6px;
}
.verify-url {
  font-family: 'Courier New', Courier, monospace;
  font-size: 8pt;
}

/* ═══ Bottom row — signatures / note LEFT, verification QR RIGHT.
       In normal flow (flex) so nothing can spill onto a 2nd page.
       break-inside guards keep the QR from being sliced by the
       printer's page fragmentation. ═══ */
.bottom-row {
  margin-top: auto;
  display: flex;
  align-items: flex-end;
  gap: 10px;
  padding-top: 14px;
  break-inside: avoid;
  page-break-inside: avoid;
}
.bottom-main { flex: 1; min-width: 0; }
.bottom-main .sig-table { margin-top: 0; width: 100%; }
.bottom-main .computer-gen { margin-top: 0; text-align: center; }
.qr-box {
  flex: 0 0 auto;
  width: 74px;
  text-align: center;
  break-inside: avoid;
  page-break-inside: avoid;
}
.qr-canvas {
  width: 58px;
  height: 58px;
  display: none;               /* replaced by .qr-img once rendered */
  break-inside: avoid;
  page-break-inside: avoid;
}
.qr-img {
  width: 58px;
  height: 58px;
  display: block;
  margin: 0 auto;
  break-inside: avoid;
  page-break-inside: avoid;
}
.qr-caption {
  font-size: 6pt;
  line-height: 1.25;
  color: #000;
  margin-top: 1px;
  break-inside: avoid;
}
.qr-caption .mono { font-family: 'Courier New', Courier, monospace; font-weight: bold; }

/* ═══ Print button (screen only) ═══ */
.print-btn {
  position: fixed;
  top: 10px;
  right: 10px;
  padding: 8px 18px;
  background: #1a5276;
  color: #fff;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 11pt;
  z-index: 999;
}
.word-btn {
  position: fixed;
  top: 10px;
  right: 175px;
  padding: 8px 18px;
  background: #2e7d32;
  color: #fff;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 11pt;
  z-index: 999;
}
@media print {
  html, body { width:auto; height:auto; overflow:visible; }
  body { margin:0; }
  .report-page {
    width:auto;
    min-height:0;
    height:auto;
    display:block;
    page-break-after:always;
    break-after:page;
  }
  .report-page:last-child { page-break-after:auto; break-after:auto; }
  .bottom-row { display:table; width:100%; table-layout:fixed; margin-top:12mm; }
  .bottom-main, .qr-box { display:table-cell; vertical-align:bottom; }
  .qr-box { width:74px; }
  .info-table tr, .data-table tr, .section-title { page-break-inside:avoid; break-inside:avoid; }
  .print-btn, .word-btn { display: none; }
}
.report-action-note {
  position: fixed;
  top: 58px;
  right: 10px;
  max-width: 460px;
  padding: 7px 10px;
  border: 1px solid #d9e2ec;
  border-radius: 4px;
  background: #f7fafc;
  color: #334155;
  font: 10pt Arial, sans-serif;
  z-index: 998;
}
@media print { .report-action-note { display: none; } }
</style>
</head>
<body>
<button class="word-btn" onclick="exportReportToWord()">Download in Word (.doc)</button>
<button class="print-btn" onclick="ctmsIssueThenPrint()">Print / Save PDF</button>
<div class="report-action-note">The report number is issued when you print or export this report. CTMS then saves a PDF copy for the archive; ZIP and email dispatch also generate any missing PDF copies.</div>
<?= $pages_html ?>
<script src="<?= htmlspecialchars($reportAssetBase . '/qrcode.min.js', ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
(function () {
  var PENDING = <?= $GLOBALS['ctms_report_pending'] ? 'true' : 'false' ?>;
  var TOKEN   = <?= json_encode((string)$issue_token, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var base = null;
  try {
    base = new URL(window.location.href);
    base.searchParams.delete('ajax');
    base.searchParams.delete('autoprint');
    base.searchParams.delete('ticket');
    base.searchParams.delete('word_ticket');
    base.searchParams.set('issue', '1');
    base.searchParams.set('issue_token', TOKEN);
  } catch (e) { base = null; }
  var ISSUE_BASE = base ? base.toString() : '';
  var ISSUE_URL  = ISSUE_BASE ? ISSUE_BASE + '&ajax=1' : '';
  var issued = !PENDING;
  var reloading = false;

  function currentNumbers() {
    var seen = [];
    Array.prototype.forEach.call(document.querySelectorAll('[data-report-number]'), function (node) {
      var v = node.getAttribute('data-report-number');
      if (seen.indexOf(v) === -1) seen.push(v);
    });
    return seen;
  }
  function applyResult(res) {
    if (!res || res.ok !== true || !Array.isArray(res.numbers)) return false;
    issued = true;
    var got = res.numbers.map(String), have = currentNumbers();
    return got.length === have.length && got.every(function (v, i) { return v === have[i]; });
  }
  function reloadIssued() {
    if (reloading) return;
    reloading = true;
    window.location.replace(ISSUE_BASE + '&autoprint=1');
  }

  // Number and register the report only when the user asks for a printed copy.
  window.ctmsIssueThenPrint = function () {
    if (issued || !ISSUE_URL) { window.print(); return; }
    fetch(ISSUE_URL, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!applyResult(res)) { reloadIssued(); return; }
        window.print();
      })
      .catch(function () { alert('Could not confirm the report number. Reopen the report and try again.'); });
  };

  // Browser menu / Ctrl+P path: issue first, while the print dialog is opening.
  window.addEventListener('beforeprint', function () {
    if (issued || !ISSUE_URL) return;
    try {
      var xhr = new XMLHttpRequest();
      xhr.open('GET', ISSUE_URL, false);
      xhr.send(null);
      applyResult(JSON.parse(xhr.responseText));
    } catch (e) { /* the Print button reports failures */ }
  });

  window.ctmsWordIssueParams = function (url) {
    if (issued || !TOKEN) return;
    url.searchParams.set('issue', '1');
    url.searchParams.set('issue_token', TOKEN);
  };
  window.ctmsWordIssued = function () { issued = true; };

  if (issued && new URLSearchParams(window.location.search).get('autoprint') === '1') {
    window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 150); });
  }
})();
</script>
<script>
async function exportReportToWord() {
  try {
  var wordTicket = <?= json_encode((string)($_GET['word_ticket'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  if (wordTicket) {
    var wordUrl = new URL(window.location.href);
    wordUrl.searchParams.set('format', 'word');
    wordUrl.searchParams.set('ticket', wordTicket);
    wordUrl.searchParams.delete('word_ticket');
    if (window.ctmsWordIssueParams) window.ctmsWordIssueParams(wordUrl);
    var response = await fetch(wordUrl.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/msword' } });
    var wordBlob = await response.blob();
    if (!response.ok || !wordBlob.size || !/application\/msword/i.test(response.headers.get('Content-Type') || '')) {
      var serverMessage = await wordBlob.text();
      throw new Error(serverMessage.slice(0, 320) || ('HTTP ' + response.status));
    }
    if (window.ctmsWordIssued) window.ctmsWordIssued();
    var wordFilename = (document.title || 'Report').replace(/[^a-zA-Z0-9_-]/g, '_') + '.doc';
    if (window.navigator && typeof window.navigator.msSaveOrOpenBlob === 'function') {
      window.navigator.msSaveOrOpenBlob(wordBlob, wordFilename);
      return;
    }
    var wordObjectUrl = URL.createObjectURL(wordBlob);
    var wordLink = document.createElement('a');
    wordLink.href = wordObjectUrl;
    wordLink.download = wordFilename;
    wordLink.style.display = 'none';
    document.body.appendChild(wordLink);
    wordLink.click();
    setTimeout(function() {
      if (wordLink.parentNode) wordLink.parentNode.removeChild(wordLink);
      URL.revokeObjectURL(wordObjectUrl);
    }, 30000);
    return;
  }
  var pages = document.querySelectorAll('.report-page');
  if (!pages.length) { alert('No report pages are available to export.'); return; }
  var copies = Array.from(pages, function(p) { return p.cloneNode(true); });
  var bodyHtml = copies.map(function(p) {
    var masthead = p.querySelector('.masthead-wrap');
    if (masthead) {
      var headerTable = document.createElement('table');
      headerTable.className = 'word-masthead';
      var headerRow = headerTable.insertRow();
      [['logo', '.masthead-logo'], ['centre', '.masthead-center'], ['contact', '.masthead-contact']].forEach(function(item) {
        var cell = headerRow.insertCell();
        cell.className = item[0];
        var source = masthead.querySelector(item[1]);
        if (source) while (source.firstChild) cell.appendChild(source.firstChild);
      });
      masthead.replaceWith(headerTable);
    }
    var bottom = p.querySelector('.bottom-row');
    if (bottom) {
      var footerTable = document.createElement('table');
      footerTable.className = 'word-bottom';
      var footerRow = footerTable.insertRow();
      var mainCell = footerRow.insertCell();
      mainCell.className = 'main';
      var mainContent = bottom.querySelector('.bottom-main');
      if (mainContent) while (mainContent.firstChild) mainCell.appendChild(mainContent.firstChild);
      var qrRow = footerTable.insertRow();
      var qrCell = qrRow.insertCell();
      qrCell.className = 'qr';
      var qrContent = bottom.querySelector('.qr-box');
      if (qrContent) {
        var qrCanvas = qrContent.querySelector('canvas.qr-canvas');
        if (qrCanvas) {
          try {
            var qrImage = document.createElement('img');
            qrImage.className = 'qr-img';
            qrImage.width = 42;
            qrImage.height = 42;
            qrImage.src = qrCanvas.toDataURL('image/png');
            qrImage.alt = 'Report verification QR';
            qrCanvas.replaceWith(qrImage);
          } catch (_) {}
        }
        var qrCaption = qrContent.querySelector('.qr-caption');
        if (qrCaption) {
          var inlineCaption = document.createElement('span');
          inlineCaption.className = 'qr-caption';
          inlineCaption.innerHTML = '&nbsp;&nbsp;&nbsp;' + qrCaption.innerHTML;
          qrCaption = inlineCaption;
        }
        var qrImage = qrContent.querySelector('img.qr-img');
        if (qrImage) qrCell.appendChild(qrImage);
        if (qrCaption) qrCell.appendChild(qrCaption);
      }
      bottom.replaceWith(footerTable);
    }
    p.querySelectorAll('.signature-line').forEach(function(line) {
      var rule = document.createElement('table');
      rule.className = 'signature-rule';
      rule.setAttribute('width', '60%');
      rule.setAttribute('align', 'center');
      rule.setAttribute('border', '0');
      var row = rule.insertRow();
      var cell = row.insertCell();
      cell.setAttribute('style', 'height:10mm;padding:0;border-bottom:1pt solid #000');
      cell.innerHTML = '&nbsp;';
      line.replaceWith(rule);
    });
    return p.outerHTML;
  }).join('');
  // Word imports <br> inconsistently, so use compact block separators for
  // ordinary report line breaks. Signature lines are real styled blocks.
  bodyHtml = bodyHtml.replace(/<br\b[^>]*\/?\s*>/gi, '<div style="margin:0;padding:0;line-height:1.1;font-size:1pt">&nbsp;</div>');
  var styles = document.querySelector('style') ? document.querySelector('style').innerHTML : '';
  var htmlContent = "<html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'>" +
      "<head><meta charset='utf-8'><title>" + document.title + "</title>" +
      "<style>" + styles + " @page WordSection1{size:<?= $is_portrait ? '8.27in 11.69in' : '11.69in 8.27in' ?>;mso-page-orientation:<?= $is_portrait ? 'portrait' : 'landscape' ?>;margin:<?= $is_portrait ? '.35in .5in' : '.15in .25in' ?>} " +
      "div.WordSection1{page:WordSection1} body{background:#fff;padding:0;margin:0} .report-page{width:100%;min-height:0!important;height:auto!important;margin:0;padding:0;box-shadow:none;display:block;page-break-after:always} " +
      ".report-page:last-child{page-break-after:auto} .word-masthead,.word-bottom,.signature-rule{border-collapse:collapse;table-layout:fixed} .word-masthead,.word-bottom{width:100%}.word-masthead{margin-bottom:4px}.word-masthead td,.word-bottom td{border:0!important;padding:1px 3px;vertical-align:middle}.word-masthead .logo{width:66px;text-align:left}.word-masthead .centre{text-align:center}.word-masthead .contact{width:180px;text-align:right;font-size:8pt}.word-masthead img.logo{width:58px!important;height:58px!important;object-fit:contain}.word-bottom{margin-top:10mm;page-break-inside:avoid}.word-bottom tr,.word-bottom td{page-break-inside:avoid}.word-bottom .main,.word-bottom .qr{width:100%;text-align:center}.word-bottom .qr{padding-top:2mm;white-space:normal}.word-bottom .qr-img{width:42px!important;height:42px!important;display:block!important;vertical-align:middle;margin:0 auto 1mm}.qr-caption{display:block;font-size:6pt;line-height:1.15;text-align:center;vertical-align:middle;white-space:normal}.sig-table{width:100%;margin:0!important;padding:0!important;table-layout:fixed}.sig-table td{padding:0 4mm!important;font-size:7pt!important;vertical-align:top}.signature-rule{width:60%;margin:0 auto 2mm!important;border:0!important}.signature-rule td{height:16mm;padding:0!important;border:0!important;border-bottom:1px solid #000!important}.signature-label{display:block;text-align:center;font-size:7pt;line-height:1.1}.info-table td{line-height:1.1!important;padding:1px 2px!important}.info-table p{margin:0!important;padding:0!important;line-height:1.1!important}.remarks-block{font-size:7pt!important;line-height:1.15!important;margin:2px 0!important;padding:0!important} </style></head>" +
      "<body><div class='WordSection1'>" + bodyHtml + "</div></body></html>";

  var blob = new Blob(["\ufeff", htmlContent], { type: "application/msword;charset=utf-8" });
  if (window.navigator && typeof window.navigator.msSaveOrOpenBlob === 'function') {
    window.navigator.msSaveOrOpenBlob(blob, (document.title || 'Report').replace(/[^a-zA-Z0-9_-]/g, '_') + '.doc');
    return;
  }
  var url = URL.createObjectURL(blob);
  var a = document.createElement("a");
  a.href = url;
  var filename = (document.title || "Report").replace(/[^a-zA-Z0-9_-]/g, "_") + ".doc";
  a.download = filename;
  a.style.display = 'none';
  document.body.appendChild(a);
  a.click();
  setTimeout(function() {
    if (a.parentNode) a.parentNode.removeChild(a);
    URL.revokeObjectURL(url);
  }, 30000);
  } catch (err) {
    console.error('CTMS Word export failed:', err);
    alert('Word file could not be prepared. Please reload the report and try again. If it continues, share the browser console error with the CTMS administrator.');
  }
}

// Render the verification QR on every printed page. Uses toDataURL +
// an <img> with explicit width/height ATTRIBUTES: Chrome's print path
// is known to ignore CSS scaling on <canvas> and print it at its raw
// pixel size, which sliced the QR. Images sized by attribute print
// correctly in every browser/driver combination.
(function () {
  var nodes = document.querySelectorAll('.qr-canvas');
  if (typeof QRCodeLib === 'undefined') return;
  nodes.forEach(function (cv) {
    var text = cv.getAttribute('data-verify');
    if (!text) return;
    QRCodeLib.toDataURL(text, { width: 232, margin: 2 }, function (err, url) {
      if (err || !url) return;
      var img = document.createElement('img');
      img.className = 'qr-img';
      img.width = 58;           // attribute — respected by print, not just CSS
      img.height = 58;
      img.alt = 'Report verification QR';
      img.src = url;
      cv.parentNode.replaceChild(img, cv);
    });
  });
})();
</script>
</body>
</html>
<?php
$reportHtml = (string)ob_get_clean();
$reportHtml = str_replace('__CTMS_LOGO_DATA__', htmlspecialchars($logoDataUri, ENT_QUOTES, 'UTF-8'), $reportHtml);
$reportHtml = str_replace('__CTMS_ASSETS__', htmlspecialchars($reportAssetBase, ENT_QUOTES, 'UTF-8'), $reportHtml);
if ($sample_id > 0) {
    try {
        $wf = $pdo->prepare('SELECT stage FROM report_approvals WHERE report_id = (SELECT id FROM reports WHERE sample_id = ? AND is_deleted = 0 ORDER BY id DESC LIMIT 1)');
        $wf->execute([$sample_id]);
        $stage = (string)($wf->fetchColumn() ?: 'draft');
    } catch (Throwable $e) {
        $stage = 'draft';
    }
    if ($stage !== 'issued') {
        $draftStamp = '<div class="ctms-draft-stamp">DRAFT · NOT ISSUED</div>';
        $draftCss = '<style>.ctms-draft-stamp{position:fixed;top:8px;right:10px;z-index:99999;padding:5px 10px;border:2px solid #9d2e22;color:#9d2e22;background:rgba(255,255,255,.94);font:700 10pt Arial,sans-serif;letter-spacing:.6px;pointer-events:none}</style>';
        $reportHtml = str_replace('</head>', $draftCss . '</head>', $reportHtml);
        $reportHtml = str_replace('<body>', '<body>' . $draftStamp, $reportHtml);
    }
    // A PDF copy is the archived document, so only store it once the report
    // has a registered number (existing report or explicit print/export).
    if (find_existing_report($pdo, $sample_id) !== null) {
        try {
            require_once __DIR__ . '/report_pdf_lib.php';
            ctms_save_report_pdf($pdo, $sample_id, $reportHtml);
        } catch (Throwable $e) {
            error_log('[CTMS] automatic PDF generation failed for sample ' . $sample_id . ': ' . $e->getMessage());
        }
    }
}
if ($issue_mode && !empty($_GET['ajax'])) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok'      => true,
        'numbers' => $GLOBALS['ctms_report_issued'],
    ]);
    exit;
}
if (strtolower((string)($_GET['format'] ?? '')) === 'word') {
    try {
        require_once __DIR__ . '/report_pdf_lib.php';
        $wordHtml = ctms_make_word_report($reportHtml, (bool)$is_portrait);
        $wordName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $tab_title) ?: 'CTMS_Report';
        header('Content-Type: application/msword; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $wordName . '.doc"');
        echo "\xEF\xBB\xBF" . $wordHtml;
        exit;
    } catch (Throwable $e) {
        error_log('[CTMS] Word report generation failed for sample ' . $sample_id . ': ' . $e->getMessage());
        http_response_code(500);
        echo 'Could not prepare the Word report.';
        exit;
    }
}
echo $reportHtml;
?>
<?php
