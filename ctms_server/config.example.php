<?php
// Copy this file outside the web root as ctms_config.php and configure it
// using environment variables or a protected local copy. Never commit the
// real configuration file.
return [
    'db_host' => getenv('CTMS_DB_HOST') ?: '127.0.0.1',
    'db_name' => getenv('CTMS_DB_NAME') ?: 'ctms',
    'db_user' => getenv('CTMS_DB_USER') ?: '',
    'db_pass' => getenv('CTMS_DB_PASS') ?: '',
    'jwt_secret' => getenv('CTMS_JWT_SECRET') ?: '',
];
