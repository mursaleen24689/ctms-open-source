<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/login_captcha_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
try {
    echo json_encode(['success' => true] + ctms_create_login_captcha($pdo));
} catch (DomainException $e) {
    http_response_code(429);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[CTMS CAPTCHA] challenge generation failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'The login security check is temporarily unavailable. Please contact the CTMS administrator.']);
}
