<?php
declare(strict_types=1);

/** Generate and persist a sample PDF through the normal report renderer. */
function ctms_ensure_report_pdf_for_sample(int $sampleId, int $userId, string $role): ?string
{
    $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $apiDirectory = realpath(__DIR__);
    if (!$documentRoot || !$apiDirectory || !str_starts_with(strtolower($apiDirectory), strtolower(rtrim($documentRoot, '\\/') . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('The report service is not under the configured web root.');
    }
    $webApiPath = str_replace('\\', '/', substr($apiDirectory, strlen(rtrim($documentRoot, '\\/'))));
    $url = 'http://127.0.0.1' . rtrim($webApiPath, '/') . '/report_generate.php?sample_id=' . $sampleId;
    $token = create_token($userId, $role);
    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer {$token}\r\nAccept: text/html\r\nConnection: close\r\n",
        'timeout' => 120,
        'ignore_errors' => true,
    ]]);
    $html = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) $status = (int)$match[1];
    }
    if ($html === false || $status !== 200 || stripos($html, '<!DOCTYPE html') === false) {
        throw new RuntimeException('Could not generate the PDF for sample ' . $sampleId . '.');
    }

    global $pdo;
    $stmt = $pdo->prepare('SELECT pdf_path FROM reports WHERE sample_id = ? AND is_deleted = 0 ORDER BY id DESC LIMIT 1');
    $stmt->execute([$sampleId]);
    $path = trim((string)$stmt->fetchColumn());
    return $path !== '' && is_file($path) && is_readable($path) ? $path : null;
}

/** Generate and persist a Word-compatible report through the same report renderer. */
function ctms_ensure_report_word_for_sample(int $sampleId, int $userId, string $role): ?string
{
    $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $apiDirectory = realpath(__DIR__);
    if (!$documentRoot || !$apiDirectory || !str_starts_with(strtolower($apiDirectory), strtolower(rtrim($documentRoot, '\\/') . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('The report service is not under the configured web root.');
    }
    $webApiPath = str_replace('\\', '/', substr($apiDirectory, strlen(rtrim($documentRoot, '\\/'))));
    $url = 'http://127.0.0.1' . rtrim($webApiPath, '/') . '/report_generate.php?sample_id=' . $sampleId . '&format=word';
    $token = create_token($userId, $role);
    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer {$token}\r\nAccept: application/msword\r\nConnection: close\r\n",
        'timeout' => 120,
        'ignore_errors' => true,
    ]]);
    $word = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\\/\\S+\\s+(\\d{3})/', $header, $match)) $status = (int)$match[1];
    }
    if (!is_string($word) || $status !== 200 || stripos($word, '<html') === false || stripos($word, 'WordSection1') === false) {
        throw new RuntimeException('Could not generate the Word report for sample ' . $sampleId . ' (HTTP ' . $status . ').');
    }

    global $pdo;
    $stmt = $pdo->prepare('SELECT report_number FROM reports WHERE sample_id = ? AND is_deleted = 0 ORDER BY id DESC LIMIT 1');
    $stmt->execute([$sampleId]);
    $reportNumber = trim((string)$stmt->fetchColumn());
    $safeNumber = preg_replace('/[^A-Za-z0-9._-]+/', '_', $reportNumber) ?: ('sample_' . $sampleId);
    $wordDir = __DIR__ . '/../storage/report_words';
    if (!is_dir($wordDir) && !@mkdir($wordDir, 0770, true) && !is_dir($wordDir)) {
        throw new RuntimeException('Could not create private Word report storage.');
    }
    $path = $wordDir . DIRECTORY_SEPARATOR . $safeNumber . '.doc';
    if (file_put_contents($path, $word, LOCK_EX) === false || filesize($path) <= 0) {
        throw new RuntimeException('Could not save the generated Word report.');
    }
    return $path;
}

/** Build an archive through the authenticated archive endpoint for the email attachment. */
function ctms_build_archive_for_email(int $lotId, int $userId, string $role): array
{
    $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $apiDirectory = realpath(__DIR__);
    if (!$documentRoot || !$apiDirectory || !str_starts_with(strtolower($apiDirectory), strtolower(rtrim($documentRoot, '\\/') . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('The archive service is not under the configured web root.');
    }
    $webApiPath = str_replace('\\', '/', substr($apiDirectory, strlen(rtrim($documentRoot, '\\/'))));
    $url = 'http://127.0.0.1' . rtrim($webApiPath, '/') . '/lot_archive_zip.php?lot_id=' . $lotId . '&action=build';
    $token = create_token($userId, $role);
    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer {$token}\r\nAccept: application/json\r\nConnection: close\r\n",
        'timeout' => 180,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) $status = (int)$match[1];
    }
    $data = is_string($body) ? json_decode($body, true) : null;
    if ($status !== 200 || !is_array($data) || empty($data['success']) || empty($data['file_path']) || empty($data['filename'])) {
        $message = is_array($data) && !empty($data['error']) ? (string)$data['error'] : 'The report archive could not be prepared.';
        throw new RuntimeException($message);
    }
    $archiveRoot = realpath(__DIR__ . '/../storage/lot_archives');
    $filePath = realpath((string)$data['file_path']);
    if (!$archiveRoot || !$filePath || !str_starts_with(strtolower($filePath), strtolower(rtrim($archiveRoot, '\\/') . DIRECTORY_SEPARATOR)) || !is_file($filePath) || filesize($filePath) <= 0) {
        throw new RuntimeException('The prepared report archive could not be found in secure storage.');
    }
    return ['path' => $filePath, 'filename' => basename((string)$data['filename'])];
}
