<?php
declare(strict_types=1);
/**
 * Image markup.
 *
 * Lets a technician draw on a micrograph the way they would in Word:
 *   - a LINE, dragged out with the mouse, to mark where the case depth ends
 *   - a BOX around a feature to label it ("Mounting Resin", "Substrate")
 *   - TEXT placed anywhere, movable
 *   - an ARROW for pointing at a feature
 *
 * Annotations are stored as VECTOR data in percent coordinates, never burned into
 * the pixels. That means they stay sharp when printed at any size, they can be
 * corrected later, and a report reprinted in five years still matches.
 *
 * The same tool sets how the image is laid out on the page — size, alignment and
 * caption — so the report keeps its shape whatever is inserted.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/engine.php';
require_once __DIR__ . '/inc/report_settings.php';

$u = require_login();

$assetId = (int)get('id', 0);
if (is_post()) $assetId = (int)(post('id') ?? $assetId);

$st = $pdo->prepare(
    'SELECT a.*, r.report_number, r.status AS report_status
       FROM c_report_assets a
       LEFT JOIN c_reports r ON r.id = a.report_id
      WHERE a.id = ?');
$st->execute([$assetId]);
$asset = $st->fetch();
if (!$asset) {
    flash('That image does not exist or was removed.', 'err');
    redirect('reports.php');
}
$storedName = trim((string)($asset['stored_name'] ?? ''));
$imagePath = rtrim((string)$CFG['upload_dir'], '/\\') . '/original/' . basename($storedName);
if ($storedName === '' || !is_file($imagePath)) {
    flash($storedName === ''
        ? 'This report item is a chart, not an image, so it cannot be marked up.'
        : 'The image record exists, but its original file is missing from Consultancy uploads.', 'err');
    $returnReportId = (int)($asset['report_id'] ?? 0);
    if ($returnReportId > 0) redirect('reports.php?action=build&id=' . $returnReportId);
    redirect('entry.php');
}
$reportId = (int)($asset['report_id'] ?? 0);
$back     = $reportId ? ('reports.php?action=build&id=' . $reportId) : 'entry.php';

if ($reportId && (string)($asset['report_status'] ?? '') === 'issued') {
    flash('This report has been issued, so its images are locked. Raise a revision to change them.', 'err');
    redirect($back);
}

// ── saving the mark-up and the layout ────────────────────────────────────────
if (is_post() && post('do') === 'save') {
    $shapesIn = json_decode((string)post('shapes', '[]'), true);
    if (!is_array($shapesIn)) $shapesIn = [];

    // keep only shapes we understand, and clamp every coordinate into the image.
    // Anything malformed is dropped rather than stored and printed.
    $clean = [];
    foreach ($shapesIn as $s) {
        if (!is_array($s)) continue;
        $type = (string)($s['type'] ?? '');
        if (!in_array($type, ['line','rect','arrow','text'], true)) continue;
        $clamp = function ($v) { return max(0.0, min(100.0, (float)$v)); };
        $row = [
            'id'     => preg_match('/^[A-Za-z0-9]{1,16}$/', (string)($s['id'] ?? '')) ? (string)$s['id'] : 's' . bin2hex(random_bytes(3)),
            'type'   => $type,
            'x1'     => $clamp($s['x1'] ?? 0),
            'y1'     => $clamp($s['y1'] ?? 0),
            'x2'     => $clamp($s['x2'] ?? 0),
            'y2'     => $clamp($s['y2'] ?? 0),
            'color'  => preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($s['color'] ?? '')) ? (string)$s['color'] : '#E01B24',
            'weight' => max(0.1, min(4.0, (float)($s['weight'] ?? 0.7))),
        ];
        if ($type === 'text' || isset($s['text'])) {
            $row['text'] = mb_substr(trim((string)($s['text'] ?? '')), 0, 200);
            $row['size'] = max(1.0, min(14.0, (float)($s['size'] ?? 3.2)));
        }
        if ($type === 'line' && isset($s['label'])) {
            $row['label'] = mb_substr(trim((string)$s['label']), 0, 60);
        }
        $clean[] = $row;
    }

    $size  = (string)post('display_size', 'medium');
    if (!in_array($size, ['small','medium','large','full','custom'], true)) $size = 'medium';
    $align = (string)post('display_align', 'center');
    if (!in_array($align, ['left','center','right'], true)) $align = 'center';
    $kind  = (string)post('asset_type', 'micrograph');
    if (!in_array($kind, ['micrograph','photo','specimen','instrument_export','scan','other'], true)) $kind = 'other';
    $unit  = (string)post('scale_unit', '');
    if (!in_array($unit, ['', 'µm', 'mm'], true)) $unit = '';
    $widthMm = dec_or_null(post('display_width_mm'));
    if ($widthMm !== null) $widthMm = max(20, min(260, (float)$widthMm));

    $before = [
        'caption' => $asset['caption'], 'shapes' => count($clean),
        'display_size' => $asset['display_size'], 'align' => $asset['display_align'],
    ];

    $pdo->prepare(
        'UPDATE c_report_assets
            SET caption = ?, magnification = ?, asset_type = ?,
                display_size = ?, display_width_mm = ?, display_align = ?,
                scale_value = ?, scale_unit = ?, annotations_json = ?
          WHERE id = ?')
        ->execute([
            trim((string)post('caption', '')) ?: null,
            trim((string)post('magnification', '')) ?: null,
            $kind, $size, $widthMm, $align,
            dec_or_null(post('scale_value')), $unit ?: null,
            $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null,
            $assetId,
        ]);

    audit('IMAGE_ANNOTATED', 'reports', $assetId, $before, [
        'shapes' => count($clean), 'display_size' => $size, 'align' => $align,
    ], null, $reportId ?: null);

    flash(count($clean) . ' mark-up item(s) saved on this image.', 'ok');
    redirect('images.php?id=' . $assetId);
}

// ── Crop image (remove white/blank borders or keep region of interest) ──────────
if (is_post() && post('do') === 'crop') {
    $cropData = json_decode((string)post('crop_coords', '{}'), true);
    if (!empty($cropData) && isset($cropData['x'], $cropData['y'], $cropData['w'], $cropData['h'])) {
        $origPath = rtrim((string)$CFG['upload_dir'], '/\\') . '/original/' . $asset['stored_name'];
        $thumbPath = !empty($asset['thumb_name']) ? (rtrim((string)$CFG['upload_dir'], '/\\') . '/thumb/' . $asset['thumb_name']) : null;

        $info = @getimagesize($origPath);
        if ($info) {
            $w = (int)$info[0];
            $h = (int)$info[1];
            $mime = (string)$info['mime'];

            // convert percent coordinates to pixels
            $cx = max(0, min($w - 1, (int)round(($cropData['x'] / 100) * $w)));
            $cy = max(0, min($h - 1, (int)round(($cropData['y'] / 100) * $h)));
            $cw = max(10, min($w - $cx, (int)round(($cropData['w'] / 100) * $w)));
            $ch = max(10, min($h - $cy, (int)round(($cropData['h'] / 100) * $h)));

            $src = match ($mime) {
                'image/jpeg' => @imagecreatefromjpeg($origPath),
                'image/png'  => @imagecreatefrompng($origPath),
                'image/webp' => @imagecreatefromwebp($origPath),
                'image/gif'  => @imagecreatefromgif($origPath),
                default      => false,
            };

            if ($src) {
                $cropped = imagecreatetruecolor($cw, $ch);
                if (in_array($mime, ['image/png', 'image/webp'], true)) {
                    imagealphablending($cropped, false);
                    imagesavealpha($cropped, true);
                }
                imagecopyresampled($cropped, $src, 0, 0, $cx, $cy, $cw, $ch, $cw, $ch);

                // Save back to original file
                match ($mime) {
                    'image/jpeg' => @imagejpeg($cropped, $origPath, 92),
                    'image/png'  => @imagepng($cropped, $origPath, 8),
                    'image/webp' => @imagewebp($cropped, $origPath, 90),
                    'image/gif'  => @imagegif($cropped, $origPath),
                    default      => false,
                };

                // Regenerate thumbnail
                if ($thumbPath) {
                    $maxW = 420;
                    $tw = min($maxW, $cw);
                    $th = (int)round($ch * ($tw / $cw));
                    $tdst = imagecreatetruecolor($tw, $th);
                    imagecopyresampled($tdst, $cropped, 0, 0, 0, 0, $tw, $th, $cw, $ch);
                    @imagejpeg($tdst, $thumbPath, 82);
                    imagedestroy($tdst);
                }

                imagedestroy($cropped);
                imagedestroy($src);

                clearstatcache(true, $origPath);
                $newSize = @filesize($origPath);
                $newHash = @hash_file('sha256', $origPath);

                $pdo->prepare('UPDATE c_report_assets SET byte_size = ?, sha256 = ? WHERE id = ?')
                    ->execute([$newSize, $newHash, $assetId]);

                audit('IMAGE_CROPPED', 'reports', $assetId, null, [
                    'cropped_w' => $cw, 'cropped_h' => $ch,
                ], null, $reportId ?: null);

                flash('Image cropped and saved successfully.', 'ok');
            } else {
                flash('Could not process image format for cropping.', 'err');
            }
        }
    }
    redirect('images.php?id=' . $assetId);
}

// ── the images this one can be measured against ──────────────────────────────
$st = $pdo->prepare('SELECT id, caption, stored_name, thumb_name, asset_type, sort_order
                       FROM c_report_assets WHERE report_id = ? ORDER BY sort_order, id');
$st->execute([$reportId]);
$gallery = $st->fetchAll();

$fileUrl  = app_url('uploads/original/' . rawurlencode($storedName));
$thumbUrl = report_asset_preview_url($asset) ?: $fileUrl;

// image geometry, needed so a drawn distance can be given a physical meaning
$orig = @getimagesize(rtrim((string)$CFG['upload_dir'], '/\\') . '/original/' . $asset['stored_name']);
$imgW = (int)($orig[0] ?? 1000);
$imgH = (int)($orig[1] ?? 700);

$shapes = json_decode((string)($asset['annotations_json'] ?? '[]'), true);
if (!is_array($shapes)) $shapes = [];

$PAGE_TITLE = 'Mark up image';
$PAGE_SUB   = $asset['report_number'] . ' · ' . ($asset['caption'] ?: $asset['original_name']);
include __DIR__ . '/inc/layout_top.php';
?>

<style>
.mk-wrap{display:grid;grid-template-columns:1fr 330px;gap:16px}
@media (max-width:1100px){.mk-wrap{grid-template-columns:1fr}}
.mk-stage{position:relative;background:#2b2b2b;border-radius:8px;padding:12px;overflow:auto;text-align:center}
.mk-holder{position:relative;display:inline-block;line-height:0;max-width:100%}
.mk-holder img{max-width:100%;display:block;user-select:none;-webkit-user-drag:none}
.mk-holder svg{position:absolute;inset:0;width:100%;height:100%;pointer-events:all;touch-action:none}
.mk-holder svg .hit{pointer-events:all;cursor:move}
.mk-tools{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:10px}
.tool{padding:6px 12px;border:1.5px solid var(--border);background:#fff;border-radius:7px;font-size:12px;
  font-weight:600;cursor:pointer;color:var(--text)}
.tool.on{background:var(--navy);border-color:var(--navy);color:#fff}
.tool:hover{background:var(--paper)}
.tool.on:hover{background:var(--navy-2)}
.swatch{width:26px;height:26px;border-radius:6px;border:2px solid #fff;box-shadow:0 0 0 1.5px var(--border);cursor:pointer}
.swatch.on{box-shadow:0 0 0 2.5px var(--copper)}
.szbtn{padding:4px 9px;border:1.5px solid var(--border);background:#fff;border-radius:6px;cursor:pointer;font-size:11.5px}
.szbtn.on{background:var(--navy);color:#fff;border-color:var(--navy)}
.shapelist{max-height:230px;overflow:auto;border:1px solid var(--border);border-radius:7px}
.shapelist div{padding:6px 9px;font-size:12px;border-bottom:1px solid var(--border);cursor:pointer;display:flex;justify-content:space-between;gap:6px}
.shapelist div:hover{background:var(--blue-light)}
.shapelist div.on{background:var(--blue-light);font-weight:600}
.gal{display:flex;gap:6px;overflow-x:auto;padding-bottom:4px}
.gal img{height:52px;border:2px solid transparent;border-radius:5px;cursor:pointer;background:#fff}
.gal img.on{border-color:var(--copper)}
</style>

<div class="mk-wrap">
  <div>
    <div class="card" style="margin-bottom:12px">
      <div class="mk-tools">
        <span class="small muted" style="font-weight:700">TOOL</span>
        <button type="button" class="tool on" data-tool="select">Select / move</button>
        <button type="button" class="tool" data-tool="line">Line</button>
        <button type="button" class="tool" data-tool="rect">Box</button>
        <button type="button" class="tool" data-tool="arrow">Arrow</button>
        <button type="button" class="tool" data-tool="text">Text</button>
        <button type="button" class="tool" data-tool="crop" style="background:#8C2F24;color:#fff;border-color:#8C2F24">✂️ Crop Image</button>
        <span class="small muted" style="margin-left:8px;font-weight:700">COLOUR</span>
        <span class="swatch on" data-color="#E01B24" style="background:#E01B24"></span>
        <span class="swatch" data-color="#1B2A4A" style="background:#1B2A4A"></span>
        <span class="swatch" data-color="#FFFFFF" style="background:#FFFFFF"></span>
        <span class="swatch" data-color="#F5C211" style="background:#F5C211"></span>
        <span class="swatch" data-color="#1A7A6D" style="background:#1A7A6D"></span>
        <span class="small muted" style="margin-left:8px;font-weight:700">THICKNESS</span>
        <button type="button" class="szbtn" data-w="0.35">thin</button>
        <button type="button" class="szbtn on" data-w="0.7">normal</button>
        <button type="button" class="szbtn" data-w="1.4">thick</button>
        <button type="button" class="tool" id="undoBtn" style="margin-left:auto">Undo</button>
        <button type="button" class="tool" id="delBtn">Delete selected</button>
      </div>
      <p class="small muted" style="margin:0">
        Choose a tool, then drag on the image. <strong>Line</strong> for a depth marker,
        <strong>Box</strong> to frame a feature, <strong>Text</strong> to click and type a label.
        Use <strong>✂️ Crop Image</strong> to drag a crop rectangle and remove black or blank borders.
      </p>
      <p class="small" style="margin:7px 0 0;color:var(--navy)"><strong>How to mark a depth:</strong> select Line, drag across the boundary, then Save the mark-up. Use Select / move to reposition or select a mark; Delete selected removes it. Marks are saved as a separate overlay and leave the source image unchanged.</p>
    </div>

    <div class="mk-stage">
      <div class="mk-holder" id="holder">
        <img id="theImage" src="<?= h($fileUrl) ?>" alt="<?= h($asset['caption']) ?>"
             width="<?= $imgW ?>" height="<?= $imgH ?>"
             onerror="this.onerror=null;this.parentElement.innerHTML='<div style=\'padding:40px;color:#fff;background:#333;text-align:center;border-radius:6px\'>Image file not found on disk.</div>';">
        <svg id="overlay" viewBox="0 0 <?= $imgW ?> <?= $imgH ?>" preserveAspectRatio="none"></svg>
      </div>
    </div>

    <div class="card" id="cropBar" style="display:none;margin-top:12px;background:#FAE3DF;border:1.5px solid #E01B24">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
        <div>
          <strong style="color:#8C2F24">✂️ Cropping Area Selected</strong>
          <div class="small" style="color:#666">Drag the box on the image to adjust the crop area, then click Apply Crop to remove blank borders.</div>
        </div>
        <div style="display:flex;gap:8px">
          <button type="button" class="btn btn-sm btn-ghost" id="autoCropBtn" style="background:#fff;border-color:#C2703D;color:#C2703D">🔍 Auto-Detect Specimen</button>
          <button type="button" class="btn btn-sm btn-ghost" id="cancelCropBtn">Cancel</button>
          <button type="button" class="btn btn-sm btn-copper" id="applyCropBtn" style="background:#8C2F24">Apply Crop & Save</button>
        </div>
      </div>
    </div>

    <!-- Hidden crop submission form -->
    <form method="post" id="cropForm" style="display:none">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="crop">
      <input type="hidden" name="id" value="<?= $assetId ?>">
      <input type="hidden" name="crop_coords" id="cropCoordsField" value="">
    </form>

    <?php if (count($gallery) > 1): ?>
    <div class="card" style="margin-top:12px">
      <h2>Other images on this report</h2>
      <div class="gal">
          <?php foreach ($gallery as $g): $galleryPreview = report_asset_preview_url($g); ?>
          <a href="<?= h(app_url('images.php?id=' . (int)$g['id'])) ?>" title="<?= h($g['caption']) ?>">
            <?php if ($galleryPreview !== ''): ?>
              <img src="<?= h($galleryPreview) ?>" class="<?= (int)$g['id'] === $assetId ? 'on' : '' ?>" alt="">
            <?php else: ?><span class="small muted">Image unavailable</span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div>
    <form method="post" id="saveForm">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <input type="hidden" name="id" value="<?= $assetId ?>">
      <input type="hidden" name="shapes" id="shapesField" value="">

      <div class="card">
        <h2>Save the mark-up</h2>
        <p class="small muted" style="margin-bottom:10px">
          Mark-up is stored separately from the picture, so it prints sharply at any size
          and can still be corrected later.
        </p>
        <button class="btn btn-copper" type="submit" style="width:100%">Save mark-up</button>
        <a class="btn btn-ghost" href="<?= h($back) ?>" style="width:100%;text-align:center;margin-top:8px">Back to the report</a>
      </div>

      <div class="card">
        <h2>On the page</h2>
        <div class="field">
          <label for="caption">Caption</label>
          <input id="caption" name="caption" type="text" value="<?= h($asset['caption']) ?>">
        </div>
        <div class="field">
          <label for="magnification">Magnification</label>
          <input id="magnification" name="magnification" type="text" value="<?= h($asset['magnification']) ?>"
                 placeholder="e.g. 500X">
        </div>
        <div class="field">
          <label for="display_size">Printed size</label>
          <select id="display_size" name="display_size">
            <?php foreach ([
                'small'  => 'Small — about 45 mm wide',
                'medium' => 'Medium — about 75 mm wide',
                'large'  => 'Large — about 110 mm wide',
                'full'   => 'Full width of the page',
                'custom' => 'Custom width…',
            ] as $k => $lab): ?>
              <option value="<?= h($k) ?>" <?= $asset['display_size'] === $k ? 'selected' : '' ?>><?= h($lab) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="hint">Two images side by side fit neatly at Medium.</div>
        </div>
        <div class="field">
          <label for="display_width_mm">Custom width (mm)</label>
          <input id="display_width_mm" name="display_width_mm" type="number" step="1" min="20" max="260"
                 value="<?= h($asset['display_width_mm']) ?>" placeholder="used when Custom is chosen">
        </div>
        <div class="field">
          <label for="display_align">Alignment</label>
          <select id="display_align" name="display_align">
            <?php foreach (['left'=>'Left','center'=>'Centre','right'=>'Right'] as $k => $lab): ?>
              <option value="<?= h($k) ?>" <?= $asset['display_align'] === $k ? 'selected' : '' ?>><?= h($lab) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="asset_type">Kind of image</label>
          <select id="asset_type" name="asset_type">
            <?php foreach (['micrograph'=>'Micrograph','photo'=>'Photograph','specimen'=>'Specimen',
                            'instrument_export'=>'Instrument output','scan'=>'Scan'] as $k => $lab): ?>
              <option value="<?= h($k) ?>" <?= $asset['asset_type'] === $k ? 'selected' : '' ?>><?= h($lab) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="row r2">
          <div class="field">
            <label for="scale_value">Scale</label>
            <input id="scale_value" name="scale_value" type="number" step="0.0001"
                   value="<?= h($asset['scale_value']) ?>" placeholder="e.g. 500">
          </div>
          <div class="field">
            <label for="scale_unit">Unit</label>
            <select id="scale_unit" name="scale_unit">
              <?php foreach ([''=>'-','µm'=>'µm','mm'=>'mm'] as $k => $lab): ?>
                <option value="<?= h($k) ?>" <?= (string)$asset['scale_unit'] === $k ? 'selected' : '' ?>><?= h($lab) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="hint" style="margin-top:-6px">
          Used by <strong>Measure</strong>: draw a line, then enter what its length means.
        </div>
      </div>

      <div class="card">
        <h2>Mark-up on this image <span class="badge badge-grey" id="count">0</span></h2>
        <div class="shapelist" id="shapeList"><div style="color:#A39A8D">Nothing drawn yet.</div></div>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var W = <?= $imgW ?>, H = <?= $imgH ?>;
  var svg = document.getElementById('overlay');
  var holder = document.getElementById('holder');
  var field = document.getElementById('shapesField');
  var shapes = <?= json_encode($shapes, JSON_UNESCAPED_UNICODE) ?>;
  if (!Array.isArray(shapes)) shapes = [];

  var tool = 'select', color = '#E01B24', weight = 0.7;
  var selected = -1, dragging = null, draft = null;

  function uid() { return 's' + Math.random().toString(36).slice(2, 9); }

  // percent -> viewBox units
  function px(p) { return p / 100 * W; }
  function py(p) { return p / 100 * H; }

  function render() {
    var out = '';
    shapes.forEach(function (s, i) {
      var sel = (i === selected);
      var stroke = s.color || '#E01B24';
      var sw = (s.weight || 0.7) * W / 100;   // scale stroke with the image
      var dash = sel ? ' stroke-dasharray="' + (sw * 3) + ' ' + (sw * 2) + '"' : '';
      var common = 'stroke="' + stroke + '" stroke-width="' + sw + '" fill="none"' + dash;
      if (s.type === 'line') {
        out += '<line x1="' + px(s.x1) + '" y1="' + py(s.y1) + '" x2="' + px(s.x2) + '" y2="' + py(s.y2) + '" ' + common + '/>';
      } else if (s.type === 'rect') {
        out += '<rect x="' + px(s.x1) + '" y="' + py(s.y1) + '" width="' + px(Math.abs(s.x2 - s.x1))
             + '" height="' + py(Math.abs(s.y2 - s.y1)) + '" ' + common + '/>';
      } else if (s.type === 'arrow') {
        var ax1 = px(s.x1), ay1 = py(s.y1), ax2 = px(s.x2), ay2 = py(s.y2);
        var ang = Math.atan2(ay2 - ay1, ax2 - ax1);
        var hl = Math.max(sw * 4, W / 60);
        out += '<line x1="' + ax1 + '" y1="' + ay1 + '" x2="' + ax2 + '" y2="' + ay2 + '" ' + common + '/>';
        out += '<path d="M' + ax2 + ',' + ay2
             + ' L' + (ax2 - hl * Math.cos(ang - 0.4)) + ',' + (ay2 - hl * Math.sin(ang - 0.4))
             + ' L' + (ax2 - hl * Math.cos(ang + 0.4)) + ',' + (ay2 - hl * Math.sin(ang + 0.4))
             + ' Z" fill="' + stroke + '" stroke="none"/>';
      } else if (s.type === 'text') {
        var fs = (s.size || 3.2) * H / 100;
        out += '<text x="' + px(s.x1) + '" y="' + py(s.y1) + '" fill="' + stroke + '"'
             + ' font-size="' + fs + '" font-family="Arial,Helvetica,sans-serif" font-weight="bold"'
             + ' stroke="#000" stroke-width="' + (fs / 14) + '" paint-order="stroke"'
             + ' style="paint-order:stroke">' + esc(s.text || 'Label') + '</text>';
      }
      // invisible hit area so any shape can be grabbed
      var bx, by, bw, bh;
      if (s.type === 'text') { bx = px(s.x1) - 10; by = py(s.y1) - (s.size||3.2)*H/100; bw = 260; bh = (s.size||3.2)*H/100*1.5; }
      else { bx = Math.min(px(s.x1), px(s.x2)) - 6; by = Math.min(py(s.y1), py(s.y2)) - 6;
             bw = Math.abs(px(s.x2)-px(s.x1)) + 12; bh = Math.abs(py(s.y2)-py(s.y1)) + 12; }
      out += '<rect class="hit" data-i="' + i + '" x="' + bx + '" y="' + by + '" width="' + Math.max(bw,10)
           + '" height="' + Math.max(bh,10) + '" fill="transparent" stroke="none"/>';
    });
    if (draft) {
      var sw2 = weight * W / 100;
      if (draft.type === 'rect' || draft.type === 'crop') {
        var cropStroke = (draft.type === 'crop') ? '#8C2F24' : color;
        out += '<rect x="' + px(Math.min(draft.x1,draft.x2)) + '" y="' + py(Math.min(draft.y1,draft.y2))
             + '" width="' + px(Math.abs(draft.x2-draft.x1)) + '" height="' + py(Math.abs(draft.y2-draft.y1))
             + '" stroke="' + cropStroke + '" stroke-width="' + (draft.type === 'crop' ? (sw2 * 1.5) : sw2)
             + '" fill="' + (draft.type === 'crop' ? 'rgba(140,47,36,0.18)' : 'none') + '" stroke-dasharray="6 4"/>';
      } else if (draft.type !== 'text') {
        out += '<line x1="' + px(draft.x1) + '" y1="' + py(draft.y1) + '" x2="' + px(draft.x2)
             + '" y2="' + py(draft.y2) + '" stroke="' + color + '" stroke-width="' + sw2 + '" fill="none"/>';
      }
    if (activeCropRect && !draft) {
      out += '<rect x="' + px(activeCropRect.x) + '" y="' + py(activeCropRect.y)
           + '" width="' + px(activeCropRect.w) + '" height="' + py(activeCropRect.h)
           + '" stroke="#8C2F24" stroke-width="' + (weight * W / 100 * 1.5)
           + '" fill="rgba(140,47,36,0.18)" stroke-dasharray="6 4"/>';
    }
    svg.innerHTML = out;
    listShapes();
    field.value = JSON.stringify(shapes);
  }

  var activeCropRect = null;

  function esc(t) { return String(t).replace(/[&<>"]/g, function (c) {
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

  function listShapes() {
    var el = document.getElementById('shapeList');
    document.getElementById('count').textContent = shapes.length;
    if (!shapes.length) { el.innerHTML = '<div style="color:#A39A8D">Nothing drawn yet.</div>'; return; }
    el.innerHTML = shapes.map(function (s, i) {
      var label = s.type === 'text' ? ('Text: ' + (s.text || ''))
                : s.type === 'line' ? ('Line ' + (s.label ? s.label + ' ' : '') + (s.measure || ''))
                : s.type === 'rect' ? ('Box' + (s.text ? ': ' + s.text : ''))
                : 'Arrow';
      return '<div class="' + (i === selected ? 'on' : '') + '" data-i="' + i + '">'
           + '<span>' + esc(label) + '</span>'
           + '<span style="color:#A39A8D">#' + (i + 1) + '</span></div>';
    }).join('');
  }

  function toPct(ev) {
    var r = holder.getBoundingClientRect();
    return { x: (ev.clientX - r.left) / r.width * 100, y: (ev.clientY - r.top) / r.height * 100 };
  }

  svg.addEventListener('mousedown', function (ev) {
    var t = ev.target;
    if (t.classList && t.classList.contains('hit')) {
      selected = parseInt(t.dataset.i, 10);
      var p = toPct(ev);
      var s = shapes[selected];
      dragging = { i: selected, mode: (tool === 'select' && nearCorner(s, p)) ? 'resize' : 'move', from: p, orig: JSON.parse(JSON.stringify(s)) };
      render(); return;
    }
    if (tool === 'select') { selected = -1; render(); return; }
    var p = toPct(ev);
    draft = { id: uid(), type: tool, x1: p.x, y1: p.y, x2: p.x, y2: p.y, color: color, weight: weight };
  });

  function nearCorner(s, p) {
    if (!s || s.type === 'text') return false;
    var d1 = Math.hypot(p.x - s.x2, p.y - s.y2);
    return d1 < 4;
  }

  window.addEventListener('mousemove', function (ev) {
    if (draft) { var p = toPct(ev); draft.x2 = p.x; draft.y2 = p.y; render(); return; }
    if (dragging) {
      var p = toPct(ev), s = shapes[dragging.i], o = dragging.orig;
      var dx = p.x - dragging.from.x, dy = p.y - dragging.from.y;
      if (dragging.mode === 'move') {
        s.x1 = o.x1 + dx; s.y1 = o.y1 + dy; s.x2 = o.x2 + dx; s.y2 = o.y2 + dy;
      } else {
        s.x2 = p.x; s.y2 = p.y;
      }
      render();
    }
  });

  window.addEventListener('mouseup', function () {
    if (draft) {
      var s = draft; draft = null;
      var tiny = Math.abs(s.x2 - s.x1) < 1 && Math.abs(s.y2 - s.y1) < 1;
      if (s.type === 'crop') {
        if (!tiny) {
          var cx = Math.min(s.x1, s.x2);
          var cy = Math.min(s.y1, s.y2);
          var cw = Math.abs(s.x2 - s.x1);
          var ch = Math.abs(s.y2 - s.y1);
          activeCropRect = { x: cx, y: cy, w: cw, h: ch };
          document.getElementById('cropCoordsField').value = JSON.stringify(activeCropRect);
          document.getElementById('cropBar').style.display = 'block';
        }
        render();
        return;
      }
      if (s.type === 'text') {
        var txt = prompt('Label text:', 'Mounting Resin');
        if (txt === null || txt.trim() === '') { render(); return; }
        s.text = txt.trim(); s.size = 3.2;
      } else if (tiny) {
        render(); return;                  // accidental click, not a drag
      } else if (s.type === 'line') {
        var lbl = prompt('Anything to write beside this line? (optional)\n'
          + 'For a case-depth marker, the depth is useful, e.g. "0.30 mm"', '');
        if (lbl) { s.label = lbl.trim(); }
      }
      shapes.push(s); selected = shapes.length - 1; render(); return;
    }
    dragging = null;
  });

  document.getElementById('cancelCropBtn').addEventListener('click', function () {
    activeCropRect = null;
    document.getElementById('cropCoordsField').value = '';
    document.getElementById('cropBar').style.display = 'none';
    tool = 'select';
    document.querySelectorAll('[data-tool]').forEach(function (b) { b.classList.toggle('on', b.dataset.tool === 'select'); });
    render();
  });

  document.getElementById('applyCropBtn').addEventListener('click', function () {
    if (!activeCropRect) return;
    if (confirm('Apply crop to this image? This will permanently trim the image margins.')) {
      document.getElementById('cropForm').submit();
    }
  });

  // ── Auto-Detect Specimen margins (Canvas pixel scan) ──
  document.getElementById('autoCropBtn').addEventListener('click', function () {
    var imgEl = document.getElementById('theImage');
    if (!imgEl || !imgEl.complete || !imgEl.naturalWidth) {
      alert('Image is not fully loaded yet.');
      return;
    }
    try {
      var cv = document.createElement('canvas');
      var nw = imgEl.naturalWidth, nh = imgEl.naturalHeight;
      cv.width = nw; cv.height = nh;
      var ctx = cv.getContext('2d');
      ctx.drawImage(imgEl, 0, 0, nw, nh);
      var imgData = ctx.getImageData(0, 0, nw, nh);
      var data = imgData.data;

      // Sample corners to know if border is dark (<=30) or light (>=225)
      var c1 = data[0] + data[1] + data[2];
      var isDark = (c1 / 3) < 40;
      var isLight = (c1 / 3) > 220;

      if (!isDark && !isLight) {
        // default fallback: 5% inset trim
        activeCropRect = { x: 5, y: 5, w: 90, h: 90 };
      } else {
        var top = 0, bottom = nh - 1, left = 0, right = nw - 1;
        function isBorderPixel(idx) {
          var r = data[idx], g = data[idx+1], b = data[idx+2];
          var avg = (r + g + b) / 3;
          return isDark ? (avg < 45) : (avg > 215);
        }

        // Scan from top
        for (var y = 0; y < nh * 0.45; y++) {
          var borderRow = true;
          for (var x = 0; x < nw; x += 10) {
            if (!isBorderPixel((y * nw + x) * 4)) { borderRow = false; break; }
          }
          if (!borderRow) { top = y; break; }
        }

        // Scan from bottom
        for (var y = nh - 1; y > nh * 0.55; y--) {
          var borderRow = true;
          for (var x = 0; x < nw; x += 10) {
            if (!isBorderPixel((y * nw + x) * 4)) { borderRow = false; break; }
          }
          if (!borderRow) { bottom = y; break; }
        }

        var padX = Math.round(nw * 0.01), padY = Math.round(nh * 0.01);
        top = Math.max(0, top - padY);
        bottom = Math.min(nh, bottom + padY);

        var ch = bottom - top;
        if (ch < nh * 0.2) { top = 0; ch = nh; } // safety guard

        activeCropRect = {
          x: 0,
          y: Math.max(0, (top / nh) * 100),
          w: 100,
          h: Math.min(100, (ch / nh) * 100)
        };
      }

      document.getElementById('cropCoordsField').value = JSON.stringify(activeCropRect);
      document.getElementById('cropBar').style.display = 'block';
      tool = 'crop';
      document.querySelectorAll('[data-tool]').forEach(function (b) { b.classList.toggle('on', b.dataset.tool === 'crop'); });
      render();
    } catch (e) {
      // Cross-origin fallback
      activeCropRect = { x: 5, y: 5, w: 90, h: 90 };
      document.getElementById('cropCoordsField').value = JSON.stringify(activeCropRect);
      document.getElementById('cropBar').style.display = 'block';
      render();
    }
  });

  document.addEventListener('click', function (e) {
    var tl = e.target.closest('[data-tool]');
    if (tl) {
      tool = tl.dataset.tool;
      document.querySelectorAll('[data-tool]').forEach(function (b) { b.classList.toggle('on', b === tl); });
      return;
    }
    var sw = e.target.closest('.swatch');
    if (sw) {
      color = sw.dataset.color;
      document.querySelectorAll('.swatch').forEach(function (b) { b.classList.toggle('on', b === sw); });
      if (selected >= 0) { shapes[selected].color = color; render(); }
      return;
    }
    var sz = e.target.closest('.szbtn');
    if (sz) {
      weight = parseFloat(sz.dataset.w);
      document.querySelectorAll('.szbtn').forEach(function (b) { b.classList.toggle('on', b === sz); });
      if (selected >= 0) { shapes[selected].weight = weight; render(); }
      return;
    }
    var row = e.target.closest('#shapeList div[data-i]');
    if (row) { selected = parseInt(row.dataset.i, 10); render(); return; }
  });

  document.getElementById('undoBtn').addEventListener('click', function () {
    if (shapes.length) { shapes.pop(); selected = -1; render(); }
  });
  document.getElementById('delBtn').addEventListener('click', function () {
    if (selected >= 0) { shapes.splice(selected, 1); selected = -1; render(); }
  });

  // double-click a shape to edit its text
  svg.addEventListener('dblclick', function (ev) {
    var t = ev.target;
    if (!t.classList || !t.classList.contains('hit')) return;
    var i = parseInt(t.dataset.i, 10), s = shapes[i];
    if (s.type === 'text') {
      var v = prompt('Edit label:', s.text || '');
      if (v !== null) { s.text = v.trim(); render(); }
    } else if (s.type === 'line') {
      var v2 = prompt('Text beside this line (e.g. a depth such as 0.30 mm):', s.label || '');
      if (v2 !== null) { s.label = v2.trim(); render(); }
    } else {
      var v3 = prompt('Label for this box:', s.text || '');
      if (v3 !== null) { s.text = v3.trim(); render(); }
    }
  });

  document.getElementById('saveForm').addEventListener('submit', function () {
    field.value = JSON.stringify(shapes);
  });

  render();
})();
</script>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
