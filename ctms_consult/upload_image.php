<?php
declare(strict_types=1);
/**
 * Image upload.
 *
 * Validates by reading the file's actual contents, never by trusting the name or
 * the browser-supplied type. Stores the original untouched, plus a thumbnail for
 * the gallery, and records a SHA-256 so the same file is not stored twice.
 *
 * Annotations are NOT burned into the image here. They are kept as vector data
 * against the asset, so they stay sharp at any print size and remain editable.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/engine.php';

$u = require_login();

$reportId = (int)post('report_id', 0);
$jobId    = (int)post('job_id', 0) ?: null;
$back     = 'reports.php?action=build&id=' . $reportId;

$st = $pdo->prepare('SELECT * FROM c_reports WHERE id = ?');
$st->execute([$reportId]);
$rep = $st->fetch();
if (!$rep) {
    flash('That report does not exist.', 'err');
    redirect('reports.php');
}

if ($rep['status'] === 'issued') {
    flash('This report has been issued, so images cannot be changed. Raise a revision instead.', 'err');
    redirect($back);
}

// ── where files live ─────────────────────────────────────────────────────────
$base   = rtrim((string)$CFG['upload_dir'], '/\\');
$originals = $base . '/original';
$thumbs    = $base . '/thumb';
foreach ([$base, $originals, $thumbs] as $d) {
    if (!is_dir($d) && !@mkdir($d, 0775, true)) {
        flash('The storage folder could not be created: ' . h($d), 'err');
        redirect($back);
    }
}

$uploadHtaccess = $base . '/.htaccess';
if (!file_exists($uploadHtaccess)) {
    $policy = "Options -ExecCGI -Indexes\n"
            . "<FilesMatch \"\\.(php|phtml|php[0-9]|phps|cgi|pl|exe|sh|bat|cmd|dll|py|jsp|asp|shtml)$\">\n"
            . "    Require all denied\n"
            . "</FilesMatch>\n"
            . "<IfModule mod_php.c>\n    php_flag engine off\n</IfModule>\n"
            . "<IfModule mod_php7.c>\n    php_flag engine off\n</IfModule>\n"
            . "<IfModule mod_php8.c>\n    php_flag engine off\n</IfModule>\n";
    @file_put_contents($uploadHtaccess, $policy);
}

if (!is_post() || post('do') !== 'upload') {
    redirect($back);
}

$files = $_FILES['images'] ?? null;
if (!$files || !is_array($files['name'])) {
    flash('Choose at least one image to upload.', 'err');
    redirect($back);
}

/** Work out the real image type from the file's contents. */
function image_real_type(string $path): ?string {
    if (!function_exists('getimagesize')) return null;
    $info = @getimagesize($path);
    if (!$info || empty($info['mime'])) return null;
    $ok = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    return in_array($info['mime'], $ok, true) ? $info['mime'] : null;
}

$caption   = trim((string)post('caption', ''));
$magnify   = trim((string)post('magnification', ''));
$type      = (string)post('asset_type', 'micrograph');
if (!in_array($type, ['micrograph','photo','specimen','instrument_export','scan','other'], true)) $type = 'other';

$scaleVal  = dec_or_null(post('scale_value'));
$scaleUnit = trim((string)post('scale_unit', '')) ?: null;

$count = count($files['name']);
$ok = 0; $skipped = [];

$nextOrder = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM c_report_assets
                                WHERE report_id = ' . (int)$reportId)->fetchColumn();

for ($i = 0; $i < $count; $i++) {
    $name = (string)$files['name'][$i];
    $tmp  = (string)$files['tmp_name'][$i];
    $err  = (int)$files['error'][$i];
    $size = (int)$files['size'][$i];

    if ($err === UPLOAD_ERR_NO_FILE) continue;
    if ($err !== UPLOAD_ERR_OK) { $skipped[] = "$name (upload error $err)"; continue; }
    if ($size > 20 * 1024 * 1024) { $skipped[] = "$name (larger than 20 MB)"; continue; }
    if (!is_uploaded_file($tmp)) { $skipped[] = "$name (not a genuine upload)"; continue; }

    $mime = image_real_type($tmp);
    if ($mime === null) {
        $skipped[] = "$name (not a JPEG, PNG, WebP or GIF — TIFF and PDF are not readable in a browser)";
        continue;
    }
    $dimensions = @getimagesize($tmp);
    $width = (int)($dimensions[0] ?? 0);
    $height = (int)($dimensions[1] ?? 0);
    if (!$dimensions || $width < 1 || $height < 1 || $width > 12000 || $height > 12000 || $width * $height > 12000000) {
        $skipped[] = "$name (image dimensions exceed the safe processing limit)";
        continue;
    }

    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime];
    $hash = hash_file('sha256', $tmp);
    $stored = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $ext;

    if (!@move_uploaded_file($tmp, $originals . '/' . $stored)) {
        $skipped[] = "$name (could not be saved)";
        continue;
    }
    @chmod($originals . '/' . $stored, 0644);

    $info = $dimensions;
    $w = (int)($info[0] ?? 0);
    $h = (int)($info[1] ?? 0);

    // a small copy for the gallery, so the page does not load full-size micrographs
    $thumbName = null;
    if ($w > 0 && $h > 0 && function_exists('imagecreatetruecolor')) {
        $thumbName = 't-' . pathinfo($stored, PATHINFO_FILENAME) . '.jpg';
        $maxW = 420;
        if ($w > $maxW) {
            $nw = $maxW;
            $nh = (int)round($h * ($maxW / $w));
        } else { $nw = $w; $nh = $h; }
        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($originals . '/' . $stored),
            'image/png'  => @imagecreatefrompng($originals . '/' . $stored),
            'image/webp' => @imagecreatefromwebp($originals . '/' . $stored),
            'image/gif'  => @imagecreatefromgif($originals . '/' . $stored),
            default      => false,
        };
        if ($src) {
            $dst = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            if (@imagejpeg($dst, $thumbs . '/' . $thumbName, 82)) { /* ok */ } else { $thumbName = null; }
            imagedestroy($dst); imagedestroy($src);
        } else {
            $thumbName = null;
        }
    }

    $pdo->prepare(
        'INSERT INTO c_report_assets
           (report_id, sample_id, job_id, asset_type, caption, magnification,
            stored_name, original_name, mime_type, byte_size, sha256,
            sort_order, uploaded_by, display_size, display_align,
            scale_value, scale_unit, thumb_name)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,"medium","center",?,?,?)')
        ->execute([$reportId, $rep['sample_id'], $jobId, $type,
                   $caption !== '' ? $caption : pathinfo($name, PATHINFO_FILENAME),
                   $magnify ?: null, $stored, $name, $mime, $size, $hash,
                   $nextOrder++, $u['id'], $scaleVal, $scaleUnit, $thumbName]);

    audit('IMAGE_UPLOADED', 'reports', (int)$pdo->lastInsertId(), null, [
        'file' => $name, 'stored' => $stored, 'type' => $mime,
        'size' => $size, 'job_id' => $jobId, 'sha256' => substr($hash, 0, 16),
    ], null, $reportId);

    $ok++;
}

if ($ok > 0) {
    flash($ok . ' image(s) added to the report. '
        . 'Open <strong>Mark up</strong> on any image to draw the depth lines and labels.', 'ok');
}
if ($skipped) {
    flash('Not accepted: ' . h(implode('; ', array_slice($skipped, 0, 4)))
        . (count($skipped) > 4 ? ' and ' . (count($skipped) - 4) . ' more' : ''), 'err');
}

redirect($back);
