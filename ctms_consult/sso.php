<?php
declare(strict_types=1);
/**
 * Sign in from the main CTMS.
 *
 * CTMS Core opens this page with a short-lived one-time ticket:
 *
 *   /ctms_consult/sso.php?t=<ticket>
 *
 * The ticket is not the session token. It is good for 60 seconds and can be
 * used once, so a URL left in browser history, in a chat message or in a server
 * log cannot be replayed later to get back in. The session token itself never
 * appears in a URL at all.
 *
 * Why the indirection is worth it: a link containing an 8-hour credential would
 * be a password sitting in plain sight in the address bar, and the browser may
 * hand it on as a Referer to any other site visited from that page.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/sso.php';

$ticket = trim((string)get('t', ''));
$returnTo = ctms_safe_return_url((string)get('return_to', ''));

/** Say no, plainly, and offer the ordinary login. */
function sso_refuse(string $why): never
{
    error_log('[CTMS-CONSULT] SSO refused: ' . $why);
    $PAGE_TITLE = 'Sign-in link not valid';
    $PAGE_SUB   = '';
    include __DIR__ . '/inc/layout_top.php';
    echo '<div class="card"><h2>That sign-in link cannot be used</h2>'
       . '<p>' . htmlspecialchars($why, ENT_QUOTES) . '</p>'
       . '<p class="small muted">Sign-in links are single-use and expire after a minute. '
       . 'Opening the Consultancy tab again from CTMS Core will make a fresh one.</p>'
       . '<p><a class="btn btn-copper" href="' . htmlspecialchars(app_url('login.php'), ENT_QUOTES) . '">'
       . 'Sign in with a password instead</a> '
       . '<a class="btn btn-ghost" href="' . htmlspecialchars(app_url('index.php'), ENT_QUOTES) . '">Go to the dashboard</a></p>'
       . '</div>';
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
}

if ($ticket === '') {
    // no ticket at all: this is just a normal visit to the page
    redirect(empty($_SESSION['uid']) ? 'login.php' : 'index.php');
}

if (!preg_match('/^[a-f0-9]{64}$/', $ticket)) {
    sso_refuse('The link is not in the expected form.');
}

// ── claim the ticket, atomically ─────────────────────────────────────────────
// The UPDATE only matches a ticket that is unused and unexpired. Two requests
// arriving together cannot both win: only the first one changes the row, so
// only the first sees an affected-row count of 1.
$claim = $pdo->prepare('UPDATE c_sso_tickets SET used_at = NOW()
                         WHERE ticket_hash = ? AND used_at IS NULL AND expires_at > NOW()');
$claim->execute([hash('sha256', $ticket)]);

if ($claim->rowCount() !== 1) {
    sso_refuse('This link has already been used, or it has expired.');
}

// the ticket is spent; find out who it belonged to
$st = $pdo->prepare('SELECT * FROM c_sso_tickets WHERE ticket_hash = ? LIMIT 1');
$st->execute([hash('sha256', $ticket)]);
$tk = $st->fetch();
if (!$tk || (int)$tk['ctms_user_id'] <= 0) {
    sso_refuse('The link did not identify an account.');
}

// ── read the identity from the main CTMS, the single source of truth ─────────
$st = $pdo->prepare(
    'SELECT id, employee_id, full_name, password_hash, role, consultancy_access, consultancy_role, lab_stream, designation,
            is_active, is_deleted, must_change_password
       FROM users WHERE id = ? LIMIT 1');
$st->execute([(int)$tk['ctms_user_id']]);
$ctmsUser = $st->fetch();

if (!$ctmsUser) {
    sso_refuse('That account no longer exists in CTMS Core.');
}
if (empty($ctmsUser['is_active']) || !empty($ctmsUser['is_deleted'])) {
    sso_refuse('That account is not active in CTMS Core.');
}
if (empty($ctmsUser['consultancy_access'])) {
    sso_refuse('Your CTMS administrator has not enabled Consultancy access for this account.');
}

// ── match or create the consultancy account ─────────────────────────────────
$user = sso_find_or_create_user($ctmsUser, $tk['mode'] ?? null);
if (!$user) {
    sso_refuse('The account could not be matched to a consultancy user.');
}
if (empty($user['is_active']) || !empty($user['is_deleted'])) {
    sso_refuse('That account is not active for consultancy work.');
}

// ── start the session ───────────────────────────────────────────────────────
// Use the same helper the password login uses. Hand-rolling this is exactly how
// the session key drifted from 'uid' to 'user_id' and left the user logged out
// while looking logged in.
login_user($user);
if ($returnTo !== null) $_SESSION['ctms_return_to'] = $returnTo;
unset($_SESSION['login_attempts']);

$pdo->prepare('UPDATE c_users SET last_login = NOW() WHERE id = ?')->execute([(int)$user['id']]);

audit('SIGNED_IN_VIA_CTMS', 'auth', (int)$user['id'], null, [
    'ctms_user_id' => (int)$ctmsUser['id'],
    'role'         => (string)$user['role'],
    'from_role'    => (string)$ctmsUser['role'],
]);

flash('Signed in as <strong>' . h((string)$user['full_name']) . '</strong> '
    . '(from CTMS Core).', 'ok');
redirect('index.php');
