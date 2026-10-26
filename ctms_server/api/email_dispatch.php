<?php
declare(strict_types=1);

/**
 * api/email_dispatch.php
 *
 * Handles composition, pre-send inspection, and email dispatch using PHPMailer.
 * - Auto-inserts subject with Lot No, Total Samples, Delivery Date, and SD/Ref/Req numbers.
 * - Builds sample column breakdown in HTML table.
 * - Attaches ZIP archive when ready.
 * - Validates credentials and provides user confirmation before actual send.
 *
 * POST action=preview  -> Returns generated subject, body, sample checklist & anomaly alerts
 * POST action=send     -> Actually sends the email via PHPMailer
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/archive_report_helper.php';

$tokenData = require_auth();
ctms_require_role(['superadmin', 'admin', 'hod', 'deo'], $tokenData,
    'Report email dispatch is restricted to reporting roles.');
$userId = (int)($tokenData['user_id'] ?? 0);

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON request']);
    exit;
}

$lotId          = (int)($body['lot_id'] ?? 0);
$recipientEmail = trim((string)($body['recipient_email'] ?? ''));
$action         = (string)($body['action'] ?? 'preview'); // 'preview' or 'send'
$userConfirmed  = !empty($body['confirmed']);

if (!$lotId) {
    http_response_code(400);
    echo json_encode(['error' => 'lot_id is required']);
    exit;
}

// 1. Fetch Lot
$lotStmt = $pdo->prepare("SELECT * FROM lots WHERE id = ? AND is_deleted = 0");
$lotStmt->execute([$lotId]);
$lot = $lotStmt->fetch();
if (!$lot) {
    http_response_code(404);
    echo json_encode(['error' => 'Lot not found']);
    exit;
}
ctms_require_lot_access($pdo, $userId, $lotId, 'manage');

// 2. Fetch all samples
$sStmt = $pdo->prepare("
    SELECT s.id, s.sd_number, s.request_number, s.reference_number,
           s.client_name, s.agency_type, s.status,
           r.report_number, r.checksum_hash, r.pdf_path, w.stage AS workflow_stage
    FROM samples s
    LEFT JOIN reports r ON r.sample_id = s.id AND r.is_deleted = 0
    LEFT JOIN report_approvals w ON w.report_id = r.id
    WHERE s.lot_id = ? AND s.is_deleted = 0
    ORDER BY s.id ASC
");
$sStmt->execute([$lotId]);
$samples = $sStmt->fetchAll();

$totalSamples = count($samples);
$unissuedReports = array_values(array_filter($samples, static fn($sample) => ($sample['workflow_stage'] ?? '') !== 'issued'));
$lotType = strtoupper($lot['agency_type'] ?? 'CHEQUE');
$deliveryDate = $lot['promised_date'] ?: ($lot['lot_date'] ?: date('Y-m-d'));

$sdList  = [];
$refList = [];
$reqList = [];
$anomalies = [];

foreach ($samples as $s) {
    $sd  = trim((string)($s['sd_number'] ?? ''));
    $ref = trim((string)($s['reference_number'] ?? ''));
    $req = trim((string)($s['request_number'] ?? ''));

    if ($sd !== '')  $sdList[] = $sd;
    if ($ref !== '') $refList[] = $ref;
    if ($req !== '') $reqList[] = $req;

    if (empty($s['report_number']) && $s['status'] !== 'Testing Complete - Awaiting Report') {
        $anomalies[] = "Sample #{$s['id']} (SD: {$sd}, Ref: {$ref}): Status is '{$s['status']}' without generated report.";
    }
}

$sdList  = array_values(array_unique($sdList));
$refList = array_values(array_unique($refList));
$reqList = array_values(array_unique($reqList));

// Build Subject line according to rules
$subjectIdParts = [];
if ($lotType === 'CHEQUE') {
    if (!empty($refList)) {
        $subjectIdParts[] = "Ref: " . implode(', ', array_slice($refList, 0, 4)) . (count($refList) > 4 ? ' et al' : '');
    }
} elseif ($lotType === 'MIXED') {
    if (!empty($sdList)) {
        $subjectIdParts[] = "SD: " . implode(', ', array_slice($sdList, 0, 3)) . (count($sdList) > 3 ? ' et al' : '');
    }
    if (!empty($refList)) {
        $subjectIdParts[] = "Ref: " . implode(', ', array_slice($refList, 0, 3)) . (count($refList) > 3 ? ' et al' : '');
    }
} else {
    // SD / ONLINE / ELECTRONICS
    if (!empty($sdList)) {
        $subjectIdParts[] = "SD: " . implode(', ', array_slice($sdList, 0, 4)) . (count($sdList) > 4 ? ' et al' : '');
    }
}

if (!empty($reqList)) {
    $subjectIdParts[] = "Req: " . implode(', ', array_slice($reqList, 0, 3)) . (count($reqList) > 3 ? ' et al' : '');
}

$identifiersStr = implode(' | ', $subjectIdParts);
$emailSubject = sprintf(
    "CTMS Test Reports Dispatch: %s (%d Samples) - Delivery: %s%s",
    $lot['lot_number'],
    $totalSamples,
    $deliveryDate,
    $identifiersStr !== '' ? " [{$identifiersStr}]" : ""
);

// Allow user-customized subject or recipient from body if provided
if (!empty($body['custom_subject'])) {
    $emailSubject = trim((string)$body['custom_subject']);
}

// Build HTML email body with Sample Columns table
$tableRowsHtml = '';
$sr = 1;
foreach ($samples as $s) {
    $tableRowsHtml .= sprintf(
        "<tr>
            <td style='border:1px solid #ddd;padding:6px;text-align:center;'>%d</td>
            <td style='border:1px solid #ddd;padding:6px;font-family:monospace;'>%s</td>
            <td style='border:1px solid #ddd;padding:6px;font-family:monospace;'>%s</td>
            <td style='border:1px solid #ddd;padding:6px;font-family:monospace;'>%s</td>
            <td style='border:1px solid #ddd;padding:6px;'>%s</td>
            <td style='border:1px solid #ddd;padding:6px;font-weight:600;'>%s</td>
        </tr>",
        $sr++,
        htmlspecialchars((string)($s['sd_number'] ?: '-')),
        htmlspecialchars((string)($s['reference_number'] ?: '-')),
        htmlspecialchars((string)($s['request_number'] ?: '-')),
        htmlspecialchars((string)($s['client_name'] ?: '-')),
        htmlspecialchars((string)($s['report_number'] ?: 'Ready / Pending Signature'))
    );
}

$emailBodyHtml = "
<div style='font-family:Arial, sans-serif; color:#222; line-height:1.5; max-width:800px; margin:0 auto;'>
    <div style='background:#1b2430; color:#fff; padding:16px 20px; border-radius:6px 6px 0 0;'>
        <h2 style='margin:0; font-size:18px;'>Example Technical University</h2>
        <p style='margin:4px 0 0; font-size:13px; color:#d9a23b;'>Materials Testing & Consultancy Laboratory (CTMS)</p>
    </div>
    <div style='border:1px solid #e0e0e0; border-top:none; padding:20px; border-radius:0 0 6px 6px;'>
        <p>Dear Client,</p>
        <p>Please find enclosed the official laboratory test reports for <strong>{$lot['lot_number']}</strong> comprising <strong>{$totalSamples} sample(s)</strong> delivered on <strong>{$deliveryDate}</strong>.</p>

        <h3 style='margin-top:20px; font-size:15px; border-bottom:2px solid #1b2430; padding-bottom:4px;'>Dispatched Samples Breakdown</h3>
        <table style='width:100%; border-collapse:collapse; margin-top:10px; font-size:12px;'>
            <thead>
                <tr style='background:#f4f5f7;'>
                    <th style='border:1px solid #ddd;padding:6px;'>Sr</th>
                    <th style='border:1px solid #ddd;padding:6px;'>SD No</th>
                    <th style='border:1px solid #ddd;padding:6px;'>Reference No</th>
                    <th style='border:1px solid #ddd;padding:6px;'>Request No</th>
                    <th style='border:1px solid #ddd;padding:6px;'>Material / Client</th>
                    <th style='border:1px solid #ddd;padding:6px;'>Report No</th>
                </tr>
            </thead>
            <tbody>
                {$tableRowsHtml}
            </tbody>
        </table>

        <p style='margin-top:20px; font-size:12px; color:#555;'>The complete archive containing individual signed reports is attached to this transmission.</p>
        <p style='margin-top:25px; font-size:12px; color:#777; border-top:1px solid #eee; padding-top:10px;'>
            This is an official automated dispatch from the Materials & Metallurgy Testing Division, Example Technical University.<br>
            For inquiries or verification, contact: <strong>irfan@Example University.edu.pk</strong>
        </p>
    </div>
</div>
";

// Check if credentials exist
$emailCfg = ctms_get_email_config();
$credentialsConfigured = !empty($emailCfg['password']);

// Action: PREVIEW
if ($action === 'preview') {
    echo json_encode([
        'lot_id'                 => $lotId,
        'lot_number'             => $lot['lot_number'],
        'lot_type'               => $lotType,
        'total_samples'          => $totalSamples,
        'delivery_date'          => $deliveryDate,
        'suggested_subject'      => $emailSubject,
        'recipient_email'        => $recipientEmail ?: ($lot['agency_type'] === 'CHEQUE' ? 'client-records@Example University.edu.pk' : ''),
        'sd_count'               => count($sdList),
        'ref_count'              => count($refList),
        'req_count'              => count($reqList),
        'anomalies'              => $anomalies,
        'has_anomalies'          => count($anomalies) > 0,
        'credentials_configured' => $credentialsConfigured,
        'sender_email'           => $emailCfg['from_email'],
        'html_preview'           => $emailBodyHtml,
    ]);
    exit;
}

// Action: SEND
if ($action === 'send') {
    if ($unissuedReports) {
        http_response_code(409);
        echo json_encode(['error' => 'Email dispatch is available after every report is Issued. Complete Technical Review for pending reports first.', 'pending_approval_count' => count($unissuedReports)]);
        exit;
    }
    if (!$userConfirmed) {
        http_response_code(400);
        echo json_encode(['error' => 'Confirmation is required before sending.']);
        exit;
    }

    if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'A valid recipient email address is required.']);
        exit;
    }

    if (!$credentialsConfigured) {
        http_response_code(422);
        echo json_encode([
            'error' => 'Google Workspace App Password is not yet entered in C:\\xampp\\ctms_config\\email_config.php. Please add your credentials first as described in EMAIL_CONFIGURATION_GUIDE.md.',
            'unconfigured' => true
        ]);
        exit;
    }

    try {
        $mail = ctms_create_mailer();
        $mail->addAddress($recipientEmail);
        $mail->Subject = $emailSubject;
        $mail->isHTML(true);
        $mail->Body    = $emailBodyHtml;
        $mail->AltBody = strip_tags(str_replace(['</tr>', '</td>'], ["\n", " | "], $emailBodyHtml));

        // Always build a fresh archive: never send an email with a stale ZIP or
        // with no report attachment when an archive was not built beforehand.
        $archive = ctms_build_archive_for_email($lotId, $userId, (string)($tokenData['role'] ?? ''));
        $mail->addAttachment($archive['path'], $archive['filename']);

        $mail->send();

        // Log audit
        try {
            $pdo->prepare("INSERT INTO audit_log (user_id, action, module, record_id, new_value, ip_address) VALUES (?, ?, 'lots', ?, ?, ?)")
                ->execute([$userId, 'LOT_EMAILED', $lotId, json_encode(['recipient' => $recipientEmail, 'subject' => $emailSubject]), $_SERVER['REMOTE_ADDR'] ?? '']);
        } catch (\Exception $e) {}

        echo json_encode([
            'success'   => true,
            'message'   => "Email successfully sent to {$recipientEmail}",
            'subject'   => $emailSubject,
            'lot_id'    => $lotId,
        ]);
        exit;
    } catch (\Throwable $e) {
        $mailError = isset($mail) ? trim((string)$mail->ErrorInfo) : '';
        $diagnostic = $mailError !== '' ? $mailError : $e->getMessage();
        error_log('[CTMS] Email dispatch failed for lot ' . $lotId . ': ' . $e->getMessage() . ($mailError !== '' ? ' | PHPMailer: ' . $mailError : ''));
        http_response_code(500);
        echo json_encode(['error' => 'Email dispatch failed: ' . $diagnostic]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
