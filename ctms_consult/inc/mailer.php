<?php
declare(strict_types=1);

/**
 * inc/mailer.php — Central PHPMailer abstraction for CTMS
 * Handles Google Workspace / Gmail SMTP relay and configuration loading.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Loads email configuration from outside web root.
 */
function ctms_get_email_config(): array {
    $cfgPath = getenv('CTMS_MAIL_CONFIG_PATH') ?: dirname(__DIR__, 3)
        . DIRECTORY_SEPARATOR . 'ctms_config' . DIRECTORY_SEPARATOR . 'email_config.php';
    if (file_exists($cfgPath)) {
        $c = require $cfgPath;
        if (is_array($c)) return $c;
    }
    return [
        'driver'     => 'smtp',
        'host'       => 'smtp.gmail.com',
        'port'       => 587,
        'encryption' => 'tls',
        'smtp_auth'  => true,
        'username'   => 'ctms@example.org',
        'password'   => '',
        'from_email' => 'ctms@example.org',
        'from_name'  => 'CTMS Laboratory',
        'reply_to'   => 'ctms@example.org',
        'bcc_admin'  => '',
    ];
}

/**
 * Initializes and returns a configured PHPMailer instance.
 *
 * @throws Exception
 */
function ctms_create_mailer(): PHPMailer {
    // Autoload PHPMailer
    $autoload1 = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload1)) {
        require_once $autoload1;
    }

    $cfg = ctms_get_email_config();
    $mail = new PHPMailer(true);

    if (empty($cfg['password'])) {
        // Unconfigured indicator
        $mail->isMail(); // fallback or raises informative error on send
    } elseif (($cfg['driver'] ?? 'smtp') === 'smtp') {
        $mail->isSMTP();
        $mail->Host       = $cfg['host'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = (bool)($cfg['smtp_auth'] ?? true);
        $mail->Username   = $cfg['username'] ?? 'ctms@example.org';
        $mail->Password   = $cfg['password'] ?? '';
        $mail->SMTPSecure = strtolower((string)($cfg['encryption'] ?? 'tls')) === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)($cfg['port'] ?? 587);
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 25;
    }

    $mail->setFrom($cfg['from_email'] ?? 'ctms@example.org', $cfg['from_name'] ?? 'CTMS Laboratory');
    if (!empty($cfg['reply_to'])) {
        $mail->addReplyTo($cfg['reply_to'], $cfg['from_name'] ?? 'CTMS Laboratory');
    }
    if (!empty($cfg['bcc_admin'])) {
        $mail->addBCC($cfg['bcc_admin']);
    }

    return $mail;
}
