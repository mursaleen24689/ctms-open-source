<?php
declare(strict_types=1);

/**
 * api/lot_archive_zip.php
 *
 * Handles creation and download of ZIP archives for completed lots of up to 200+ samples.
 * Format requirement:
 * Name: {LOT_NO} - {TOTAL_SAMPLES} Samples - Delivery {DELIVERY_DATE} - {SD_OR_REF_OR_MIXED}.zip
 *
 * GET ?lot_id=123&action=preview   -> Returns metadata, sample list, anomaly checks, and archive readiness
 * GET ?lot_id=123&action=download  -> Builds and downloads the .zip file
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/archive_report_helper.php';

$lotId  = (int)($_GET['lot_id'] ?? 0);
$action = (string)($_GET['action'] ?? 'preview');
$format = strtolower(trim((string)($_GET['format'] ?? 'pdf')));
if (!in_array($format, ['pdf', 'word', 'both'], true)) $format = 'pdf';
$ticket = trim((string)($_GET['ticket'] ?? ''));
$tokenData = null;

// A download is opened in a new browser tab, where the Axios bearer header is
// not available. Accept only a short-lived, one-use ticket bound to this lot.
// Preview/build requests still require the normal authenticated API request.
if ($action === 'download' && $ticket !== '') {
    $ticketData = validate_download_ticket($pdo, $ticket, 'lot_archive_zip', false);
    if (empty($ticketData['ok']) || (int)($ticketData['scope_id'] ?? 0) !== $lotId) {
        http_response_code(403);
        echo json_encode(['error' => 'Download link expired or already used. Please build the archive again.']);
        exit;
    }
    ctms_require_role(['superadmin', 'admin', 'hod', 'deo'], $ticketData,
        'Report archives are restricted to reporting roles.');
    $userId = (int)$ticketData['user_id'];
    $tokenData = $ticketData;
} else {
    $tokenData = require_auth();
    ctms_require_role(['superadmin', 'admin', 'hod', 'deo'], $tokenData,
        'Report archives are restricted to reporting roles.');
    $userId = (int)($tokenData['user_id'] ?? 0);
}

if (!$lotId) {
    http_response_code(400);
    echo json_encode(['error' => 'lot_id required']);
    exit;
}

// 1. Fetch Lot info
$lotStmt = $pdo->prepare("SELECT * FROM lots WHERE id = ? AND is_deleted = 0");
$lotStmt->execute([$lotId]);
$lot = $lotStmt->fetch();

if (!$lot) {
    http_response_code(404);
    echo json_encode(['error' => 'Lot not found']);
    exit;
}
ctms_require_lot_access($pdo, $userId, $lotId, ($action === 'preview') ? 'view' : 'manage');

// 2. Fetch all samples in this lot
$sStmt = $pdo->prepare("
    SELECT s.id, s.lot_id, s.sd_number, s.request_number, s.reference_number,
           s.client_name, s.agency_type, s.sample_category, s.status,
           s.delivery_type, s.notes, s.extra_data, s.created_at,
           r.id AS report_id, r.report_number, r.pdf_path, r.checksum_hash, r.status AS report_status,
           w.stage AS workflow_stage
    FROM samples s
    LEFT JOIN reports r ON r.sample_id = s.id AND r.is_deleted = 0
    LEFT JOIN report_approvals w ON w.report_id = r.id
    WHERE s.lot_id = ? AND s.is_deleted = 0
    ORDER BY s.id ASC
");
$sStmt->execute([$lotId]);
$samples = $sStmt->fetchAll();

$totalSamples = count($samples);
$lotType = strtoupper($lot['agency_type'] ?? 'CHEQUE');
$deliveryDate = $lot['promised_date'] ?: ($lot['lot_date'] ?: date('Y-m-d'));

$sdNumbers = [];
$refNumbers = [];
$reqNumbers = [];
$missingReports = [];
$readyReports = 0;

foreach ($samples as $s) {
    $sd = trim((string)($s['sd_number'] ?? ''));
    $ref = trim((string)($s['reference_number'] ?? ''));
    $req = trim((string)($s['request_number'] ?? ''));

    if ($sd !== '') $sdNumbers[] = $sd;
    if ($ref !== '') $refNumbers[] = $ref;
    if ($req !== '') $reqNumbers[] = $req;

    if (!empty($s['report_id']) && ($s['workflow_stage'] ?? '') === 'issued') {
        $readyReports++;
    } else {
        $missingReports[] = [
            'sample_id' => $s['id'],
            'sd'        => $sd,
            'ref'       => $ref,
            'req'       => $req,
            'status'    => $s['status'],
            'workflow_stage' => $s['workflow_stage'] ?? 'draft'
        ];
    }
}

$sdNumbers = array_values(array_unique($sdNumbers));
$refNumbers = array_values(array_unique($refNumbers));
$reqNumbers = array_values(array_unique($reqNumbers));

// Determine Identifier segment for file naming
$idSegment = '';
if ($lotType === 'CHEQUE') {
    $idSegment = 'Ref ' . (!empty($refNumbers) ? implode('-', array_slice($refNumbers, 0, 5)) : 'None');
    if (count($refNumbers) > 5) $idSegment .= ' et al';
} elseif ($lotType === 'MIXED') {
    $sPart = !empty($sdNumbers) ? 'SD ' . implode('-', array_slice($sdNumbers, 0, 3)) : '';
    $rPart = !empty($refNumbers) ? 'Ref ' . implode('-', array_slice($refNumbers, 0, 3)) : '';
    $idSegment = trim("$sPart $rPart");
} else {
    // SD / ONLINE / ELECTRONICS
    $idSegment = 'SD ' . (!empty($sdNumbers) ? implode('-', array_slice($sdNumbers, 0, 5)) : 'None');
    if (count($sdNumbers) > 5) $idSegment .= ' et al';
}

// Clean filename
$rawArchiveName = sprintf(
    "%s - %d Samples - Delivery %s - %s.zip",
    $lot['lot_number'],
    $totalSamples,
    $deliveryDate,
    $idSegment
);
$safeArchiveName = preg_replace('/[\\/\\\\:*?"<>|]/', '_', $rawArchiveName);
$formatLabel = ['pdf' => 'PDF', 'word' => 'Word', 'both' => 'PDF and Word'][$format];
$safeArchiveName = preg_replace('/\.zip$/i', ' - ' . $formatLabel . '.zip', $safeArchiveName);

// PREVIEW MODE
if ($action === 'preview') {
    echo json_encode([
        'lot_id'           => $lot['id'],
        'lot_number'       => $lot['lot_number'],
        'lot_type'         => $lotType,
        'delivery_date'    => $deliveryDate,
        'total_samples'    => $totalSamples,
        'ready_reports'    => $readyReports,
        'is_fully_ready'   => ($totalSamples > 0 && count($missingReports) === 0),
        'archive_filename' => $safeArchiveName,
        'sd_numbers'       => $sdNumbers,
        'ref_numbers'      => $refNumbers,
        'req_numbers'      => $reqNumbers,
        'missing_reports'  => $missingReports,
        'samples'          => $samples,
    ]);
    exit;
}

// DOWNLOAD / BUILD ARCHIVE MODE
if ($action === 'download' || $action === 'build') {
    if ($missingReports) {
        http_response_code(409);
        echo json_encode(['error' => 'Archive creation is available after every sample report has been issued. Complete Technical Review for pending reports first.', 'pending_approval' => $missingReports]);
        exit;
    }
    $archiveDir = __DIR__ . '/../storage/lot_archives';
    if (!is_dir($archiveDir)) {
        @mkdir($archiveDir, 0777, true);
    }

    $zipPath = $archiveDir . '/' . $safeArchiveName;

    // Keep the archive ready for IDM metadata and byte-range resume requests.
    // The ticket remains valid only for this lot and expires after ten minutes.
    if ($action === 'download') {
        if (!is_file($zipPath)) {
            http_response_code(404);
            echo json_encode(['error' => 'Archive expired or missing. Please build it again.']);
            exit;
        }
        $size = filesize($zipPath);
        $start = 0;
        $end = max(0, $size - 1);
        $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
        if ($range !== '') {
            if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) || ($m[1] === '' && $m[2] === '')) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            if ($m[1] === '') {
                $suffix = (int)$m[2];
                $start = max(0, $size - $suffix);
            } else {
                $start = (int)$m[1];
                if ($m[2] !== '') $end = min($end, (int)$m[2]);
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$size}");
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $safeArchiveName) . '"');
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . ($end - $start + 1));
        header('Cache-Control: private, no-store');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            $fh = fopen($zipPath, 'rb');
            if ($fh) {
                fseek($fh, $start);
                $remaining = $end - $start + 1;
                while ($remaining > 0 && !feof($fh)) {
                    $chunk = fread($fh, min(1024 * 1024, $remaining));
                    if ($chunk === false || $chunk === '') break;
                    echo $chunk;
                    $remaining -= strlen($chunk);
                }
                fclose($fh);
            }
        }
        exit;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot create zip file on server storage']);
        exit;
    }

    // Build only the formats selected by the user. The complete archive fails
    // if any requested report cannot be generated; partial deliveries mislead.
    $requestedFormats = $format === 'both' ? ['pdf', 'word'] : [$format];
    $missingReportsByFormat = ['pdf' => [], 'word' => []];
    $usedNames = [];
    foreach ($samples as $s) {
        $reportName = (string)($s['report_number'] ?: ('Sample #' . $s['id']));
        foreach ($requestedFormats as $requestedFormat) {
            $path = '';
            try {
                if ($requestedFormat === 'pdf') {
                    $path = trim((string)($s['pdf_path'] ?? ''));
                    $valid = $path !== '' && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf' && is_file($path) && is_readable($path);
                    if (!$valid) $path = ctms_ensure_report_pdf_for_sample((int)$s['id'], $userId, (string)($tokenData['role'] ?? '')) ?? '';
                    $valid = $path !== '' && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf' && is_file($path) && is_readable($path);
                } else {
                    $path = ctms_ensure_report_word_for_sample((int)$s['id'], $userId, (string)($tokenData['role'] ?? '')) ?? '';
                    $valid = $path !== '' && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'doc' && is_file($path) && is_readable($path);
                }
                if (!$valid) throw new RuntimeException('Generated report file is missing or invalid.');
                $baseName = basename($path);
                $extension = $requestedFormat === 'word' ? '.doc' : '.pdf';
                $entryName = pathinfo($baseName, PATHINFO_FILENAME) . $extension;
                $suffix = 2;
                while (isset($usedNames[strtolower($entryName)])) {
                    $entryName = pathinfo($baseName, PATHINFO_FILENAME) . '_' . $suffix++ . $extension;
                }
                $usedNames[strtolower($entryName)] = true;
                if (!$zip->addFile($path, $entryName)) throw new RuntimeException('Could not add the report to the archive.');
            } catch (Throwable $e) {
                $missingReportsByFormat[$requestedFormat][] = $reportName;
                error_log('[CTMS] archive ' . $requestedFormat . ' generation failed for sample ' . (int)$s['id'] . ': ' . $e->getMessage());
            }
        }
    }

    $missingPdfReports = array_values(array_unique($missingReportsByFormat['pdf']));
    $missingWordReports = array_values(array_unique($missingReportsByFormat['word']));
    if ($missingPdfReports || $missingWordReports) {
        $zip->close();
        @unlink($zipPath);
        http_response_code(422);
        $missingMessages = [];
        if ($missingPdfReports) $missingMessages[] = 'PDF: ' . implode(', ', $missingPdfReports);
        if ($missingWordReports) $missingMessages[] = 'Word: ' . implode(', ', $missingWordReports);
        echo json_encode([
            'error' => 'CTMS could not prepare the requested report files (' . implode('; ', $missingMessages) . '). Open each report and check the server log if the problem continues.',
            'missing_pdf_reports' => $missingPdfReports,
            'missing_word_reports' => $missingWordReports,
        ]);
        exit;
    }

    $zip->close();

    if ($action === 'build') {
        if (!is_file($zipPath) || filesize($zipPath) <= 0) {
            http_response_code(500);
            echo json_encode(['error' => 'The archive was empty and could not be prepared.']);
            exit;
        }
        $downloadTicket = issue_download_ticket($pdo, $userId, 'lot_archive_zip', $lotId, 600);
        echo json_encode([
            'success'      => true,
            'filename'     => $safeArchiveName,
            'file_path'    => $zipPath,
            'file_size'    => filesize($zipPath),
            'download_url' => 'lot_archive_zip.php?lot_id=' . $lotId . '&action=download&format=' . rawurlencode($format) . '&ticket=' . rawurlencode($downloadTicket),
        ]);
        exit;
    }

}

http_response_code(400);
echo json_encode(['error' => 'Invalid action']);
