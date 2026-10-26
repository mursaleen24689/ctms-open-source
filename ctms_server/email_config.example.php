<?php
// Copy outside the web root to email_config.php and set CTMS_MAIL_CONFIG_PATH.
return [
    'driver' => getenv('MAIL_DRIVER') ?: 'mail',
    'host' => getenv('MAIL_HOST') ?: 'smtp.example.org',
    'port' => (int)(getenv('MAIL_PORT') ?: 587),
    'encryption' => getenv('MAIL_ENCRYPTION') ?: 'tls',
    'smtp_auth' => true,
    'username' => getenv('MAIL_USERNAME') ?: 'ctms@example.org',
    'password' => getenv('MAIL_PASSWORD') ?: '',
    'from_email' => getenv('MAIL_FROM_ADDRESS') ?: 'ctms@example.org',
    'from_name' => getenv('MAIL_FROM_NAME') ?: 'CTMS',
    'reply_to' => '',
    'bcc_admin' => '',
];
