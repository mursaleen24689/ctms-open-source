<?php
declare(strict_types=1);

/** Convert the normal report page HTML to the same self-contained Word HTML used by the report button. */
function ctms_make_word_report(string $html, bool $portrait): string
{
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) throw new RuntimeException('Word archive dependencies are unavailable.');
    require_once $autoload;
    if (!class_exists(\chillerlan\QRCode\QRCode::class)) throw new RuntimeException('The QR generator is unavailable.');

    $body = '';
    if (!preg_match('/<body\b[^>]*>([\s\S]*?)<\/body>/i', $html, $bodyMatch)) {
        throw new RuntimeException('The report could not be prepared for Word.');
    }
    $body = $bodyMatch[1];
    $body = preg_replace_callback(
        '/<canvas\b(?=[^>]*\bclass=["\'][^"\']*\bqr-canvas\b[^"\']*["\'])(?=[^>]*\bdata-verify=["\']([^"\']+)["\'])[^>]*>\s*<\/canvas>/i',
        static function (array $match): string {
            $verifyUrl = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $options = new \chillerlan\QRCode\QROptions([
                'outputInterface' => \chillerlan\QRCode\Output\QRGdImagePNG::class,
                'scale' => 4,
            ]);
            $qr = (new \chillerlan\QRCode\QRCode($options))->render($verifyUrl);
            return '<img class="qr-img" width="42" height="42" alt="Report verification QR" src="' . htmlspecialchars($qr, ENT_QUOTES, 'UTF-8') . '">';
        },
        $body
    ) ?? $body;
    if (!class_exists(DOMDocument::class)) throw new RuntimeException('Word layout conversion is unavailable.');
    $dom = new DOMDocument('1.0', 'UTF-8');
    $previousErrors = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"><div id="word-root">' . $body . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);
    $xpath = new DOMXPath($dom);
    $root = $xpath->query('//*[@id="word-root"]')->item(0);
    if (!$root) throw new RuntimeException('The report content could not be converted for Word.');
    $classNode = static function (DOMElement $root, string $class) use ($xpath): ?DOMElement {
        $nodes = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]', $root);
        return $nodes && $nodes->length ? $nodes->item(0) : null;
    };
    $mastheads = iterator_to_array($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " masthead-wrap ")]', $root));
    foreach ($mastheads as $masthead) {
        $table = $dom->createElement('table');
        $table->setAttribute('class', 'word-masthead');
        $row = $dom->createElement('tr');
        foreach ([['logo', 'masthead-logo'], ['centre', 'masthead-center'], ['contact', 'masthead-contact']] as [$cellClass, $sourceClass]) {
            $cell = $dom->createElement('td');
            $cell->setAttribute('class', $cellClass);
            $source = $classNode($masthead, $sourceClass);
            if ($source) while ($source->firstChild) $cell->appendChild($source->firstChild);
            $row->appendChild($cell);
        }
        $table->appendChild($row);
        $masthead->parentNode->replaceChild($table, $masthead);
    }
    $bottomRows = iterator_to_array($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " bottom-row ")]', $root));
    foreach ($bottomRows as $bottom) {
        $table = $dom->createElement('table');
        $table->setAttribute('class', 'word-bottom');
        foreach ([['main', 'bottom-main'], ['qr', 'qr-box']] as [$cellClass, $sourceClass]) {
            $row = $dom->createElement('tr');
            $cell = $dom->createElement('td');
            $cell->setAttribute('class', $cellClass);
            $source = $classNode($bottom, $sourceClass);
            if ($source) {
                if ($cellClass === 'qr') {
                    $caption = $classNode($source, 'qr-caption');
                    if ($caption) {
                        $inlineCaption = $dom->createElement('span');
                        $inlineCaption->setAttribute('class', 'qr-caption');
                        $inlineCaption->appendChild($dom->createTextNode("\u{00A0}\u{00A0}\u{00A0}"));
                        while ($caption->firstChild) $inlineCaption->appendChild($caption->firstChild);
                        $source->replaceChild($inlineCaption, $caption);
                    }
                }
                while ($source->firstChild) $cell->appendChild($source->firstChild);
            }
            $row->appendChild($cell);
            $table->appendChild($row);
        }
        $bottom->parentNode->replaceChild($table, $bottom);
    }
    $signatureLines = iterator_to_array($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " signature-line ")]', $root));
    foreach ($signatureLines as $line) {
        $rule = $dom->createElement('table');
        $rule->setAttribute('class', 'signature-rule');
        $rule->setAttribute('width', '60%');
        $rule->setAttribute('align', 'center');
        $rule->setAttribute('border', '0');
        $row = $dom->createElement('tr');
        $cell = $dom->createElement('td');
        $cell->setAttribute('style', 'height:10mm;padding:0;border-bottom:1pt solid #000');
        $cell->appendChild($dom->createTextNode("\u{00A0}"));
        $row->appendChild($cell);
        $rule->appendChild($row);
        $line->parentNode->replaceChild($rule, $line);
    }
    foreach (['button', 'script'] as $tag) {
        foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
            if ($node->parentNode) $node->parentNode->removeChild($node);
        }
    }
    foreach ($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " report-action-note ")]', $root) as $note) {
        $note->parentNode->removeChild($note);
    }
    foreach ($xpath->query('.//br', $root) as $br) {
        $separator = $dom->createElement('div');
        $separator->setAttribute('style', 'margin:0;padding:0;line-height:1.1;font-size:1pt');
        $separator->appendChild($dom->createTextNode("\u{00a0}"));
        $br->parentNode->replaceChild($separator, $br);
    }
    $body = '';
    foreach ($root->childNodes as $child) $body .= $dom->saveHTML($child);

    preg_match('/<style\b[^>]*>([\s\S]*?)<\/style>/i', $html, $styleMatch);
    $css = (string)($styleMatch[1] ?? '');
    $page = $portrait ? '8.27in 11.69in' : '11.69in 8.27in';
    $orientation = $portrait ? 'portrait' : 'landscape';
    $margins = $portrait ? '.35in .5in' : '.15in .25in';
    $wordCss = "@page WordSection1{size:$page;mso-page-orientation:$orientation;margin:$margins} "
        . "div.WordSection1{page:WordSection1} body{background:#fff;padding:0;margin:0} "
        . ".report-page{width:100%;min-height:0!important;height:auto!important;margin:0;padding:0;box-shadow:none;display:block;page-break-after:always} "
        . ".report-page:last-child{page-break-after:auto} .word-masthead,.word-bottom,.signature-rule{border-collapse:collapse;table-layout:fixed} .word-masthead,.word-bottom{width:100%} .word-masthead{margin-bottom:4px} "
        . ".word-masthead td,.word-bottom td{border:0!important;padding:1px 3px;vertical-align:middle}.word-masthead .logo{width:66px;text-align:left}.word-masthead .centre{text-align:center}.word-masthead .contact{width:180px;text-align:right;font-size:8pt}.word-masthead img.logo{width:58px!important;height:58px!important;object-fit:contain} "
        . ".word-bottom{margin-top:10mm;page-break-inside:avoid}.word-bottom tr,.word-bottom td{page-break-inside:avoid}.word-bottom .main,.word-bottom .qr{width:100%;text-align:center}.word-bottom .qr{padding-top:2mm;white-space:normal}.word-bottom .qr-img{width:42px!important;height:42px!important;display:block!important;vertical-align:middle;margin:0 auto 1mm}.qr-caption{display:block;font-size:6pt;line-height:1.15;text-align:center;vertical-align:middle;white-space:normal} "
        . ".sig-table{width:100%;margin:0!important;padding:0!important;table-layout:fixed}.sig-table td{padding:0 4mm!important;font-size:7pt!important;vertical-align:top}.signature-rule{width:60%;margin:0 auto 2mm!important;border:0!important}.signature-rule td{height:16mm;padding:0!important;border:0!important;border-bottom:1px solid #000!important}.signature-label{display:block;text-align:center;font-size:7pt;line-height:1.1}.info-table td{line-height:1.1!important;padding:1px 2px!important}.info-table p{margin:0!important;padding:0!important;line-height:1.1!important} "
        . ".remarks-block{font-size:7pt!important;line-height:1!important;margin:1px 0!important;padding:0!important}";
    $title = 'CTMS Report';
    if (preg_match('/<title>(.*?)<\/title>/is', $html, $titleMatch)) $title = trim(strip_tags($titleMatch[1]));
    return "<html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'><head><meta charset='utf-8'><title>"
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</title><style>" . $css . $wordCss . "</style></head><body><div class='WordSection1'>" . $body . '</div></body></html>';
}

/** Render the report HTML with the existing browser layout and save it to private CTMS storage. */
function ctms_save_report_pdf(PDO $pdo, int $sampleId, string $html): string
{
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) throw new RuntimeException('PDF generator is not installed.');
    require_once $autoload;
    if (!class_exists(\Dompdf\Dompdf::class) || !class_exists(\chillerlan\QRCode\QRCode::class)) {
        throw new RuntimeException('PDF or QR generator dependency is unavailable.');
    }

    $reportStmt = $pdo->prepare('SELECT id, report_number FROM reports WHERE sample_id = ? AND is_deleted = 0 ORDER BY id DESC LIMIT 1');
    $reportStmt->execute([$sampleId]);
    $report = $reportStmt->fetch(PDO::FETCH_ASSOC);
    if (!$report) throw new RuntimeException('Report registry entry is missing for sample ' . $sampleId . '.');

    $html = preg_replace_callback(
        '/<canvas\b(?=[^>]*\bclass=["\'][^"\']*\bqr-canvas\b[^"\']*["\'])(?=[^>]*\bdata-verify=["\']([^"\']+)["\'])[^>]*>\s*<\/canvas>/i',
        static function (array $match): string {
            $verifyUrl = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $qrDataUri = (new \chillerlan\QRCode\QRCode())->render($verifyUrl);
            return '<img class="qr-img" width="58" height="58" alt="Report verification QR" src="' . htmlspecialchars($qrDataUri, ENT_QUOTES, 'UTF-8') . '">';
        },
        $html
    ) ?? $html;

    // Dompdf does not implement Flexbox; replace just the two report rows
    // that use it with table layout while keeping the shared report markup.
    $pdfStyles = '<style>.report-page{display:block;min-height:0}.bottom-row{display:table;width:100%;table-layout:fixed}.bottom-main,.qr-box{display:table-cell;vertical-align:bottom}.bottom-main{width:auto}.qr-box{width:74px}.sig-table{margin-top:18px}.computer-gen{margin-top:18px}</style>';
    $html = preg_replace('/<\/head>/i', $pdfStyles . '</head>', $html, 1) ?? $html;

    $serverRoot = realpath(__DIR__ . '/..');
    if (!$serverRoot) throw new RuntimeException('CTMS server folder could not be resolved.');
    $options = new \Dompdf\Options();
    $options->setIsRemoteEnabled(false);
    $options->setChroot($serverRoot);
    $options->setDefaultFont('Arial');
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->setBasePath(__DIR__ . DIRECTORY_SEPARATOR);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();
    $pdfBytes = $dompdf->output();
    if (!str_starts_with($pdfBytes, '%PDF-')) throw new RuntimeException('PDF generation returned an invalid document.');

    $storageDir = $serverRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'report_pdfs';
    if (!is_dir($storageDir) && !@mkdir($storageDir, 0770, true) && !is_dir($storageDir)) {
        throw new RuntimeException('Could not create private report PDF storage.');
    }
    $safeNumber = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$report['report_number']) ?: ('sample_' . $sampleId);
    $target = $storageDir . DIRECTORY_SEPARATOR . $safeNumber . '.pdf';
    if (file_put_contents($target, $pdfBytes, LOCK_EX) === false) {
        throw new RuntimeException('Could not write the generated report PDF.');
    }

    try {
        $update = $pdo->prepare('UPDATE reports SET pdf_path = ? WHERE id = ? AND is_deleted = 0');
        $update->execute([$target, (int)$report['id']]);
    } catch (Throwable $e) {
        @unlink($target);
        throw $e;
    }
    return $target;
}
