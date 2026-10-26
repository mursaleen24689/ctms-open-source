<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/engine.php';
require_once __DIR__ . '/inc/report.php';
require_login(['superadmin','admin','professor','hod','deo']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('Use the report archive form.'); }
csrf_verify();
$ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['report_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
$format = strtolower(trim((string)($_POST['format'] ?? 'pdf')));
if (!$ids || count($ids) > 100 || !in_array($format, ['pdf','word','both'], true)) { http_response_code(400); exit('Choose 1 to 100 reports and a supported format.'); }
if (!class_exists(ZipArchive::class)) { http_response_code(500); exit('ZIP support is not installed in PHP.'); }
$projectRoot = realpath(__DIR__) ?: __DIR__;
$webRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
$configuredAutoload = trim((string)(getenv('CTMS_COMPOSER_AUTOLOAD') ?: ($CFG['composer_autoload'] ?? '')));
$configuredRoot = trim((string)(getenv('CTMS_APP_ROOT') ?: ($CFG['ctms_root'] ?? '')));
$autoloadCandidates = array_filter([
    $configuredAutoload,
    $configuredRoot !== '' ? rtrim($configuredRoot, '/\\') . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php' : '',
    __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ctms' . DIRECTORY_SEPARATOR . 'ctms_server' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
    $webRoot !== '' ? rtrim($webRoot, '/\\') . DIRECTORY_SEPARATOR . 'ctms' . DIRECTORY_SEPARATOR . 'ctms_server' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php' : '',
]);
$autoload = '';
foreach ($autoloadCandidates as $candidate) {
    $resolved = realpath($candidate);
    if ($resolved !== false && is_file($resolved)) { $autoload = $resolved; break; }
}
if ($autoload === '') { http_response_code(500); exit('The report generator is unavailable. Configure CTMS_COMPOSER_AUTOLOAD or CTMS_APP_ROOT.'); }
require_once $autoload;
if (($format === 'pdf' || $format === 'both') && !class_exists(\Dompdf\Dompdf::class)) { http_response_code(500); exit('PDF generation is unavailable.'); }
function consult_inline_images(string $html, string $projectRoot, string $webRoot): string {
 global $CFG;
 return preg_replace_callback('~(<img\b[^>]*\bsrc\s*=\s*)(["\'])(.*?)(\2[^>]*>)~is', static function(array $m) use ($projectRoot,$webRoot): string {
  global $CFG;
  $url = html_entity_decode($m[3], ENT_QUOTES|ENT_HTML5, 'UTF-8'); if (str_starts_with(strtolower($url),'data:')) return $m[0];
  $urlPath = parse_url($url, PHP_URL_PATH); if (!is_string($urlPath) || $urlPath === '') return $m[0];
  $decodedPath = str_replace('/', DIRECTORY_SEPARATOR, ltrim(rawurldecode($urlPath), '/\\'));
  $candidate = $webRoot !== '' ? realpath(rtrim($webRoot, '/\\') . DIRECTORY_SEPARATOR . $decodedPath) : false;
  if (!$candidate && preg_match('#/uploads/original/([^/]+)$#i', $urlPath, $imageMatch)) {
      $uploadBase = rtrim((string)($CFG['upload_dir'] ?? ''), '\/');
      $candidate = $uploadBase !== '' ? realpath($uploadBase . DIRECTORY_SEPARATOR . 'original' . DIRECTORY_SEPARATOR . basename($imageMatch[1])) : false;
  }
  $uploadRoot = realpath((string)($CFG['upload_dir'] ?? ''));
  $inProject = $candidate && str_starts_with(strtolower($candidate), strtolower(rtrim($projectRoot, '\/') . DIRECTORY_SEPARATOR));
  $inUploads = $candidate && $uploadRoot && str_starts_with(strtolower($candidate), strtolower(rtrim($uploadRoot, '\/') . DIRECTORY_SEPARATOR));
  if (!$candidate || !is_file($candidate) || (!$inProject && !$inUploads)) return $m[0];
  $mime = function_exists('mime_content_type') ? (mime_content_type($candidate) ?: 'application/octet-stream') : 'application/octet-stream';
  return $m[1] . $m[2] . 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($candidate)) . $m[4];
 }, $html) ?? $html;
}
function consult_word_doc(string $html): string {
 preg_match('~<style\b[^>]*>(.*?)</style>~is', $html, $styles);
 preg_match('~<body\b[^>]*>(.*?)</body>~is', $html, $body);
 $content = (string)($body[1] ?? '');
 $content = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $content) ?? $content;
 $content = preg_replace('~<button\b[^>]*>.*?</button>~is', '', $content) ?? $content;
 $portrait = preg_match('~class\s*=\s*(["\'])[^"\']*\bportrait\b~i', $content) === 1;
 $size = $portrait ? '595.3pt 841.9pt' : '841.9pt 595.3pt'; $orient = $portrait ? 'portrait' : 'landscape';
 $css = (string)($styles[1] ?? '');
 return "<html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'><head><meta charset='utf-8'><style>$css @page Section1{size:$size;mso-page-orientation:$orient;margin:22pt} div.Section1{page:Section1} body{background:#fff;margin:0;padding:0} .sheet{margin:0;box-shadow:none;width:100%;min-height:0;padding:0;display:block}</style></head><body><div class='Section1'>$content</div></body></html>";
}
$temp = tempnam(sys_get_temp_dir(), 'ctms_consult_zip_'); if ($temp === false) { http_response_code(500); exit('Could not prepare archive.'); }
$zipPath = $temp . '.zip'; @unlink($temp); $zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE|ZipArchive::OVERWRITE) !== true) { http_response_code(500); exit('Could not create archive.'); }
$kinds = $format === 'both' ? ['pdf','word'] : [$format]; $used=[]; $fail=[];
try {
 foreach ($ids as $id) {
  $model = ctms_build_report_model($id); if (!$model) { $fail[]='Report #'.$id; continue; }
  $rep = $model['report']; $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)($rep['report_number'] ?? ('report_'.$id))) ?: ('report_'.$id);
  $html = consult_inline_images(ctms_render_report($model, false), (string)$projectRoot, (string)$webRoot);
  foreach ($kinds as $kind) { try {
   if ($kind === 'word') { $bytes="\xEF\xBB\xBF" . consult_word_doc($html); $ext='.doc'; }
   else { $options=new \Dompdf\Options(); $options->setIsRemoteEnabled(false); $options->setChroot($projectRoot); $options->setDefaultFont('Times New Roman'); $pdf=new \Dompdf\Dompdf($options); $layout=(($model["header"]["orientation"] ?? "") ?: report_layout("report_orientation")); $pdf->setPaper("A4", $layout === "landscape" ? "landscape" : "portrait"); $pdf->setBasePath($projectRoot . DIRECTORY_SEPARATOR); $pdf->loadHtml($html,'UTF-8'); $pdf->render(); $bytes=$pdf->output(); if (!str_starts_with($bytes,'%PDF-')) throw new RuntimeException('Invalid PDF output'); $ext='.pdf'; }
   $entry=$name.$ext; $n=2; while(isset($used[strtolower($entry)])) $entry=$name.'_'.$n++.$ext; $used[strtolower($entry)]=true;
   if(!$zip->addFromString($entry,$bytes)) throw new RuntimeException('Archive entry failed');
  } catch(Throwable $e) { error_log('[Consultancy] archive '.$kind.' failed for report '.$id.': '.$e->getMessage()); $fail[]=$name.' ('.strtoupper($kind).')'; } }
 }
 $zip->close(); if($fail){ @unlink($zipPath); http_response_code(422); exit('Archive not created; could not generate: '.htmlspecialchars(implode(', ',array_unique($fail)),ENT_QUOTES,'UTF-8')); }
 if(!is_file($zipPath)||filesize($zipPath)<=0){@unlink($zipPath);http_response_code(500);exit('The archive was empty.');}
 $filename='Consultancy-Reports-'.date('Ymd-His').'-'.strtoupper($format).'.zip'; header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="'.$filename.'"'); header('Content-Length: '.filesize($zipPath)); header('Cache-Control: private, no-store'); readfile($zipPath);
} finally { @unlink($zipPath); }
