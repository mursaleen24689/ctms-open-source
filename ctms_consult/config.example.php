<?php
// Copy outside the web root to ctms_consult_config.php and fill in locally.
// For least privilege, use a dedicated Consultancy database user.
return [
    'db_mode' => 'separate',
    'db_host' => getenv('CTMS_DB_HOST') ?: '127.0.0.1',
    'db_charset' => 'utf8mb4',
    'db_name' => getenv('CTMS_CONSULT_DB_NAME') ?: 'ctms_consult',
    'db_user' => getenv('CTMS_CONSULT_DB_USER') ?: '',
    'db_pass' => getenv('CTMS_CONSULT_DB_PASS') ?: '',
    'merged_db_name' => getenv('CTMS_DB_NAME') ?: 'ctms',
    'merged_db_user' => getenv('CTMS_DB_USER') ?: '',
    'merged_db_pass' => getenv('CTMS_DB_PASS') ?: '',
    'app_url' => getenv('CTMS_CONSULT_APP_URL') ?: 'http://localhost/ctms/ctms_consult',
    'allowed_hosts' => ['localhost', '127.0.0.1', '::1'],
    'force_https' => false,
    'session_lifetime' => 7200,
    'upload_dir' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ctms_private' . DIRECTORY_SEPARATOR . 'consultancy_uploads',
];
