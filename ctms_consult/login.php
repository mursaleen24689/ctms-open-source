<?php
declare(strict_types=1);
/**
 * Sign in.
 *
 * There is deliberately no self-service password reset here: an unauthenticated
 * reset request endpoint is a common way for a system to be abused. Password
 * resets are performed by an administrator from the Users screen and are
 * audited. If nobody can sign in, firstrun.php recreates the first account.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/engine.php';

// first run: no accounts yet
$userCount = (int)$pdo->query('SELECT COUNT(*) FROM c_users')->fetchColumn();
if ($userCount === 0) {
    redirect('firstrun.php');
}

// already signed in
if (current_user()) redirect('index.php');

$error = '';
$employeeId = '';

if (is_post()) {
    $employeeId = trim((string)post('employee_id', ''));
    $password   = (string)post('password', '');

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!rate_limit('login_ip:' . $ip, 10, 900)
        || ($employeeId !== '' && !rate_limit('login_user:' . strtolower($employeeId), 5, 900))) {
        $error = 'Too many failed login attempts. Please wait a few minutes and try again.';
    } elseif ($employeeId === '' || $password === '') {
        $error = 'Please enter your ID or email and your password.';
    } else {
        $st = $pdo->prepare('SELECT * FROM c_users WHERE employee_id = ? LIMIT 1');
        $st->execute([$employeeId]);
        $u = $st->fetch();

        // Main CTMS-linked users must enter through CTMS Core SSO so the
        // central Consultancy access switch cannot be bypassed with a copied
        // password hash. Standalone Consultancy accounts retain password login.
        $ok = $u && empty($u['ctms_user_id']) && (int)$u['is_deleted'] === 0 && (int)$u['is_active'] === 1
              && password_verify($password, $u['password_hash']);

        if ($ok && (int)($u['must_change_password'] ?? 0) === 1) {
            $error = 'Sign in through CTMS Core and complete the required password change before opening Consultancy.';
        } elseif ($ok) {
            rate_limit_reset('login_user:' . strtolower($employeeId));
            login_user($u);
            $pdo->prepare('UPDATE c_users SET last_login = NOW() WHERE id = ?')->execute([(int)$u['id']]);
            audit('LOGIN_SUCCESS', 'auth', (int)$u['id'], null, ['employee_id' => $u['employee_id']]);
            redirect('index.php');
        } else {
            // One generic message: never reveal whether the account exists.
            $error = 'Those details were not recognised.';
            audit('LOGIN_FAILED', 'auth', null, null, ['employee_id' => $employeeId]);
        }
    }
}

$PAGE_TITLE = 'Sign in';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · CTMS Consultancy Platform</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;
  background:radial-gradient(circle at 30% 20%,#262B33 0%,#1B1E24 55%,#14171C 100%);padding:20px}
.card{width:420px;background:#fff;border-radius:16px;padding:34px 36px;box-shadow:0 30px 70px rgba(0,0,0,.45)}
.brand{display:flex;gap:13px;align-items:center;margin-bottom:20px}
.brand b{font-size:19px;color:#1B1E24;display:block;line-height:1.2}
.brand span{font-size:10.5px;color:#786F63;text-transform:uppercase;letter-spacing:.7px;font-weight:600}
h1{font-size:13px;color:#C2703D;text-align:center;padding:9px 0;border-top:1px solid #E5E0D8;border-bottom:1px solid #E5E0D8;margin-bottom:20px;font-weight:600}
label{display:block;font-size:11px;font-weight:700;color:#1B1E24;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
input{width:100%;padding:11px 14px;border:1.5px solid #E5E0D8;border-radius:8px;font-size:14px;font-family:inherit;background:#F7F5F1}
input:focus{outline:none;border-color:#C2703D;background:#fff}
.field{margin-bottom:15px}
button{width:100%;padding:13px;margin-top:6px;background:#C2703D;color:#fff;border:none;border-radius:8px;
  font-size:13.5px;font-weight:700;cursor:pointer;text-transform:uppercase;letter-spacing:.5px}
button:hover{background:#A85C2E}
.err{background:#FAE3DF;color:#8C2F24;border:1px solid #F0BDB4;border-radius:8px;padding:10px 14px;font-size:12.5px;margin-bottom:16px;text-align:center}
.foot{margin-top:18px;text-align:center;font-size:11px;color:#A39A8D;line-height:1.7}
</style>
</head>
<body>
<div class="card">
  <div class="brand">
    <svg width="44" height="44" viewBox="0 0 100 100" aria-hidden="true">
      <polygon points="50,4 93,27 93,73 50,96 7,73 7,27" fill="#1B1E24"/>
      <polygon points="50,4 93,27 93,73 50,96 7,73 7,27" fill="none" stroke="#C2703D" stroke-width="5"/>
      <circle cx="50" cy="50" r="15" fill="#C2703D"/>
    </svg>
    <div><b>CTMS Consultancy</b><span>Materials Testing</span></div>
  </div>
  <h1>Sign in to continue</h1>

  <?php if ($error !== ''): ?><div class="err"><?= h($error) ?></div><?php endif; ?>

  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <div class="field">
      <label for="employee_id">Employee ID or email</label>
      <input id="employee_id" name="employee_id" type="text" required autofocus autocomplete="off"
             value="<?= h($employeeId) ?>" placeholder="name@Example University.edu.pk">
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input id="password" name="password" type="password" required autocomplete="new-password" placeholder="••••••••">
    </div>
    <button type="submit">Sign in</button>
  </form>

  <div class="foot">
    <?= h(setting('lab_full_name', 'Example Technical University')) ?><br>
    Forgot your password? Ask the system administrator to reset it from User Management.
  </div>
</div>
</body>
</html>
