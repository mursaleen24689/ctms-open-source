<?php
declare(strict_types=1);

/**
 * api/mailer.php — PHPMailer loader & configuration provider for ctms_server
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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
        'username'   => 'irfan@Example University.edu.pk',
        'password'   => '',
        'from_email' => 'irfan@Example University.edu.pk',
        'from_name'  => 'CTMS Laboratory - Example Technical University',
        'reply_to'   => 'irfan@Example University.edu.pk',
        'bcc_admin'  => '',
    ];
}

function ctms_create_mailer(): PHPMailer {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }

    $cfg = ctms_get_email_config();
    $mail = new PHPMailer(true);

    if (empty($cfg['password'])) {
        $mail->isMail();
    } elseif (($cfg['driver'] ?? 'smtp') === 'smtp') {
        $mail->isSMTP();
        $mail->Host       = $cfg['host'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = (bool)($cfg['smtp_auth'] ?? true);
        $mail->Username   = $cfg['username'] ?? 'irfan@Example University.edu.pk';
        $mail->Password   = $cfg['password'] ?? '';
        $mail->SMTPSecure = strtolower((string)($cfg['encryption'] ?? 'tls')) === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)($cfg['port'] ?? 587);
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 25;
    }

    $mail->setFrom($cfg['from_email'] ?? 'irfan@Example University.edu.pk', $cfg['from_name'] ?? 'CTMS Laboratory');
    if (!empty($cfg['reply_to'])) {
        $mail->addReplyTo($cfg['reply_to'], $cfg['from_name'] ?? 'CTMS Laboratory');
    }
    if (!empty($cfg['bcc_admin'])) {
        $mail->addBCC($cfg['bcc_admin']);
    }

    return $mail;
}
