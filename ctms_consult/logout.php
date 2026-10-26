<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/sso.php';

$u = current_user();
if ($u) {
    audit('LOGOUT', 'auth', (int)$u['id']);
}
$returnTo = ctms_safe_return_url((string)($_SESSION['ctms_return_to'] ?? ''));
logout_user();
if ($returnTo !== null) redirect($returnTo . '#consultancy-signed-out');
redirect('login.php');
