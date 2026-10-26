<?php
declare(strict_types=1);
/**
 * Page shell — top. Expects $PAGE_TITLE to be set before inclusion.
 * Every page includes this so navigation and security headers are consistent.
 */
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
}
$__u = current_user();
require_once __DIR__ . '/sso.php';
$__title = $PAGE_TITLE ?? 'CTMS';
$__here  = basename($_SERVER['PHP_SELF'] ?? '');
$__chatHost = (string)($_SERVER['HTTP_HOST'] ?? '');
$__chatBase = ctms_safe_return_url('https://' . $__chatHost . '/ctms/ctms_frontend/dist/');
$__chatUrl = $__chatBase ? $__chatBase . '?view=chat' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($__title) ?> · Consultancy Platform</title>
<style>
:root{
  --ink:#1B1E24; --ink-2:#262B33; --navy:#1B2A4A; --navy-2:#2D4270;
  --copper:#C2703D; --copper-dim:#A85C2E; --copper-light:#E8A87C;
  --teal:#1A7A6D; --teal-light:#E2F0EC; --rust:#8C2F24; --rust-light:#FAE3DF;
  --amber:#8A6116; --amber-light:#FBF1DD; --paper:#F7F5F1; --border:#E5E0D8;
  --text:#1B1E24; --text-muted:#786F63; --text-soft:#A39A8D;
  --blue:#1F5D8C; --blue-light:#E4EEF7; --green:#1F5D54;
  --mono:'IBM Plex Mono',Consolas,'Courier New',monospace;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,-apple-system,sans-serif;font-size:13.5px;color:var(--text);background:var(--paper);line-height:1.5}
a{color:var(--navy-2);text-decoration:none}
a:hover{text-decoration:underline}
code,.mono{font-family:var(--mono)}

/* ── shell ── */
.shell{display:flex;min-height:100vh}
.side{width:224px;flex:0 0 224px;background:var(--ink);color:#fff;padding:16px 0;display:flex;flex-direction:column;gap:2px}
.brand{display:flex;gap:10px;align-items:center;padding:0 16px 14px;border-bottom:1px solid rgba(255,255,255,.08);margin-bottom:10px}
.brand-name{font-weight:700;font-size:15px;letter-spacing:.2px}
.brand-sub{font-size:10px;color:var(--text-soft);text-transform:uppercase;letter-spacing:.7px}
.who{padding:0 16px 12px;font-size:11.5px;color:var(--text-soft)}
.who b{color:#fff;display:block;font-size:12.5px;font-weight:600}
.navgroup{padding:10px 16px 4px;font-size:9.5px;text-transform:uppercase;letter-spacing:.9px;color:#6b7280}
.nav{display:block;padding:8px 16px;color:#D6D3CE;font-size:13px;border-left:3px solid transparent}
.nav:hover{background:rgba(255,255,255,.05);text-decoration:none;color:#fff}
.nav.on{background:rgba(194,112,61,.16);border-left-color:var(--copper);color:#fff;font-weight:600}
.nav .n{display:inline-block;width:24px;color:var(--copper-light);font-family:var(--mono);font-size:10.5px}
.side-foot{margin-top:auto;padding:12px 16px;border-top:1px solid rgba(255,255,255,.08);font-size:11px;color:var(--text-soft)}

.main{flex:1;min-width:0;display:flex;flex-direction:column}
.top{background:#fff;border-bottom:1px solid var(--border);padding:14px 24px;display:flex;justify-content:space-between;align-items:center;gap:16px}
.top h1{font-size:17px;font-weight:700;color:var(--navy)}
.top .sub{font-size:11.5px;color:var(--text-muted)}
.body{padding:20px 24px 48px;max-width:1500px}

/* ── cards & tables ── */
.card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:18px;margin-bottom:16px}
.card h2{font-size:14px;font-weight:700;color:var(--navy);margin-bottom:12px}
.card h3{font-size:12.5px;font-weight:700;color:var(--copper-dim);margin:14px 0 8px;text-transform:uppercase;letter-spacing:.5px}
.grid{display:grid;gap:14px}
.g2{grid-template-columns:repeat(auto-fit,minmax(320px,1fr))}
.g3{grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}
.g4{grid-template-columns:repeat(auto-fit,minmax(170px,1fr))}
table{width:100%;border-collapse:collapse;font-size:12.5px}
th{background:var(--navy);color:#fff;text-align:left;padding:8px 10px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;font-weight:600;white-space:nowrap}
td{padding:7px 10px;border-bottom:1px solid var(--border);vertical-align:top}
tr:nth-child(even) td{background:#FBFAF8}
tr:hover td{background:var(--blue-light)}
.table-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:8px;background:#fff}
.num{text-align:right;font-family:var(--mono);white-space:nowrap}

/* ── forms ── */
label{display:block;font-size:11px;font-weight:700;color:var(--ink);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
input[type=text],input[type=number],input[type=date],input[type=password],input[type=email],select,textarea{
  width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;
  font-family:inherit;background:#fff;color:var(--text)}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--copper);background:#FFFDFB}
input[readonly]{background:#F1EFEB;color:var(--text-muted)}
.field{margin-bottom:12px}
.row{display:grid;gap:12px}
.r2{grid-template-columns:1fr 1fr}
.r3{grid-template-columns:1fr 1fr 1fr}
.r4{grid-template-columns:repeat(4,1fr)}
.hint{font-size:11px;color:var(--text-muted);margin-top:3px}

/* ── buttons ── */
.btn{display:inline-block;padding:8px 14px;border:none;border-radius:7px;background:var(--navy);color:#fff;
  font-size:12.5px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none}
.btn:hover{background:var(--navy-2);text-decoration:none;color:#fff}
.btn-copper{background:var(--copper)}.btn-copper:hover{background:var(--copper-dim)}
.btn-teal{background:var(--teal)}.btn-teal:hover{background:#155F55}
.btn-ghost{background:#fff;color:var(--navy);border:1.5px solid var(--border)}
.btn-ghost:hover{background:var(--paper);color:var(--navy)}
.btn-danger{background:var(--rust)}.btn-danger:hover{background:#72251C}
.btn-sm{padding:5px 10px;font-size:11.5px}
.btn:disabled{opacity:.5;cursor:not-allowed}

/* ── badges ── */
.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.badge-grey{background:#EFEDE9;color:var(--text-muted)}
.badge-blue{background:var(--blue-light);color:var(--blue)}
.badge-green{background:var(--teal-light);color:var(--green)}
.badge-amber{background:var(--amber-light);color:var(--amber)}
.badge-red{background:var(--rust-light);color:var(--rust)}

/* ── messages ── */
.msg{border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;border:1px solid}
.msg-ok{background:var(--teal-light);border-color:#A9DCD5;color:var(--green)}
.msg-warn{background:var(--amber-light);border-color:#ECD9AC;color:var(--amber)}
.msg-err{background:var(--rust-light);border-color:#F0BDB4;color:var(--rust)}
.msg-info{background:var(--blue-light);border-color:#BBD5EA;color:var(--blue)}

/* ── validation display (the important one) ── */
.chk{font-size:11.5px;border-radius:6px;padding:5px 8px;margin-top:3px;border-left:3px solid}
.chk-error{background:var(--rust-light);border-color:var(--rust);color:var(--rust)}
.chk-warning{background:var(--amber-light);border-color:var(--amber);color:var(--amber)}
.chk-ok{background:var(--teal-light);border-color:var(--teal);color:var(--green);opacity:.75}
.cell-input{width:100%;padding:5px 7px;border:1.5px solid var(--border);border-radius:5px;font-family:var(--mono);font-size:12.5px}
.cell-input.warn{border-color:var(--amber);background:var(--amber-light)}
.cell-input.err{border-color:var(--rust);background:var(--rust-light)}
.cell-input.ref{background:#F1EFEB;font-style:italic}

/* ── KPI ── */
.kpi{background:#fff;border:1px solid var(--border);border-left:4px solid var(--copper);border-radius:9px;padding:14px}
.kpi .l{font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);font-weight:700}
.kpi .v{font-size:26px;font-weight:700;color:var(--navy);font-family:var(--mono);margin-top:4px}
.kpi .s{font-size:11px;color:var(--text-soft)}

.empty{padding:26px;text-align:center;color:var(--text-soft);font-size:13px}
.pill{display:inline-block;background:var(--paper);border:1px solid var(--border);border-radius:20px;padding:3px 10px;font-size:11.5px;margin:0 4px 4px 0}
.toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
.muted{color:var(--text-muted)}
.small{font-size:11.5px}
.right{text-align:right}
.nowrap{white-space:nowrap}

/* ── working mode ── */
.modebar{display:flex;align-items:center;gap:10px}
.modepill{display:inline-flex;align-items:center;gap:6px;border-radius:20px;padding:4px 12px;
  font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border:1.5px solid}
.modepill-guided{background:var(--teal-light);border-color:#A9DCD5;color:var(--green)}
.modepill-direct{background:var(--amber-light);border-color:#ECD9AC;color:var(--amber)}
.modeswitch{display:inline-flex;border:1.5px solid var(--border);border-radius:8px;overflow:hidden}
.modeswitch a{padding:5px 12px;font-size:11.5px;font-weight:600;color:var(--text-muted);text-decoration:none}
.modeswitch a:hover{background:var(--paper);color:var(--navy);text-decoration:none}
.modeswitch a.on{background:var(--navy);color:#fff}
</style>
</head>
<body>
<?php if ($__u): ?>
<?php $__mode = current_mode(); $__here_q = basename($_SERVER['PHP_SELF'] ?? '') . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''); ?>
<div class="shell">
  <aside class="side">
    <div class="brand">
      <svg width="30" height="30" viewBox="0 0 100 100" aria-hidden="true">
        <polygon points="50,4 93,27 93,73 50,96 7,73 7,27" fill="#C2703D"/>
        <circle cx="50" cy="50" r="15" fill="#1B1E24"/>
      </svg>
      <div>
        <div class="brand-name">CTMS</div>
        <div class="brand-sub">Consultancy</div>
      </div>
    </div>
    <div class="who">
      <b><?= h($__u['full_name']) ?></b>
      <?= h(ucfirst($__u['role'])) ?><?= $__u['lab_stream'] ? ' · ' . h($__u['lab_stream']) : '' ?>
      <div style="margin-top:6px">
        <span class="modepill modepill-<?= h($__mode) ?>">
          <?= h(mode_label($__mode)) ?> mode
        </span>
      </div>
    </div>

    <div class="navgroup">Workflow</div>
    <a class="nav <?= $__here === 'index.php' ? 'on' : '' ?>" href="<?= h(app_url('index.php')) ?>"><span class="n">01</span>Dashboard</a>
    <a class="nav <?= $__here === 'invoices.php' ? 'on' : '' ?>" href="<?= h(app_url('invoices.php')) ?>"><span class="n">02</span>Invoices</a>
    <a class="nav <?= $__here === 'samples.php' ? 'on' : '' ?>" href="<?= h(app_url('samples.php')) ?>"><span class="n">03</span>Samples &amp; Pieces</a>
    <a class="nav <?= $__here === 'jobs.php' ? 'on' : '' ?>" href="<?= h(app_url('jobs.php')) ?>"><span class="n">04</span>Test Jobs</a>
    <a class="nav <?= $__here === 'entry.php' ? 'on' : '' ?>" href="<?= h(app_url('entry.php')) ?>"><span class="n">05</span>Data Entry</a>
    <a class="nav <?= $__here === 'reports.php' ? 'on' : '' ?>" href="<?= h(app_url('reports.php')) ?>"><span class="n">06</span>Reports</a>
    <a class="nav <?= $__here === 'dispatch.php' ? 'on' : '' ?>" href="<?= h(app_url('dispatch.php')) ?>"><span class="n">07</span>Dispatch</a>
    <?php if ($__chatUrl !== ''): ?><a class="nav" href="<?= h($__chatUrl) ?>"><span class="n">08</span>Team Chat <span style="font-size:10px;opacity:.65">E2E</span></a><?php endif; ?>

    <div class="navgroup">Reference</div>
    <a class="nav <?= $__here === 'standards.php' ? 'on' : '' ?>" href="<?= h(app_url('standards.php')) ?>"><span class="n">A</span>Standards Library</a>
    <?php // In Direct mode the working screens stay simple: the reference and
          // oversight menus are out of the way. Nothing is disabled — switching
          // to Guided brings them back. ?>
    <?php if ($__mode === 'guided'): ?>
      <a class="nav <?= $__here === 'report_settings.php' ? 'on' : '' ?>" href="<?= h(app_url('report_settings.php')) ?>"><span class="n">B</span>Report Settings</a>
      <?php if (can_user('superadmin','admin','professor')): ?>
      <a class="nav <?= $__here === 'audit.php' ? 'on' : '' ?>" href="<?= h(app_url('audit.php')) ?>"><span class="n">C</span>Audit Trail</a>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (can_user('superadmin','admin')): ?>
    <a class="nav <?= $__here === 'users.php' ? 'on' : '' ?>" href="<?= h(app_url('users.php')) ?>"><span class="n">D</span>Users</a>
    <?php endif; ?>

    <div class="side-foot">
      <?= h(setting('lab_unit_name', 'Material Testing Laboratories')) ?><br>
      <a href="<?= h(app_url('logout.php')) ?>" style="color:var(--copper-light)">Sign out</a>
    </div>
  </aside>
  <main class="main">
    <div class="top">
      <div>
        <h1><?= h($__title) ?></h1>
        <?php if (!empty($PAGE_SUB)): ?><div class="sub"><?= h($PAGE_SUB) ?></div><?php endif; ?>
      </div>
      <div class="modebar">
        <span class="small muted">Working mode</span>
        <span class="modeswitch">
          <a class="<?= $__mode === 'guided' ? 'on' : '' ?>"
             href="<?= h(app_url('mode.php?to=guided&back=' . rawurlencode($__here_q))) ?>"
             title="Values are checked as you type them. Warnings can be passed, with a reason.">Guided</a>
          <a class="<?= $__mode === 'direct' ? 'on' : '' ?>"
             href="<?= h(app_url('mode.php?to=direct&back=' . rawurlencode($__here_q))) ?>"
             title="You decide. No warnings, no suggestions — the checks are recorded quietly in the background.">Direct</a>
        </span>
        <span class="small muted nowrap"><?= date('d M Y') ?></span>
      </div>
    </div>
    <div class="body">
<?php else: ?>
<div class="body">
<?php endif; ?>

<?php foreach (take_flash() as $f): ?>
  <div class="msg msg-<?= h($f['type']) ?>"><?= $f['msg'] ?></div>
<?php endforeach; ?>
