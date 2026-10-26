<?php
declare(strict_types=1);
/**
 * Code 128 barcode generator and verification codes.
 *
 * Written here rather than pulled from a library so that the laboratory's
 * reports never depend on an internet service or an external package. The
 * output is plain SVG, which prints sharply at any size.
 *
 * Code 128 subset B and C are implemented, which covers digits, letters and
 * the punctuation used in a report number.
 */


/** The 107 Code 128 symbol patterns, as bar/space widths. */
function ctms_code128_patterns(): array {
    static $p = null;
    if ($p !== null) return $p;
    $p = [
        '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
        '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
        '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
        '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
        '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
        '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
        '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
        '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
        '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
        '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
        '114131','311141','411131','211412','211214','211232','2331112',
    ];
    return $p;
}

/**
 * Encode a string as Code 128 (auto subset B/C) and return bar widths.
 * Each entry is [isBar, widthInModules].
 */
function ctms_code128_bars(string $data): array {
    $patterns = ctms_code128_patterns();
    $codes = [];

    // choose subset C (pairs of digits) when the data is all digits and even
    $allDigits = preg_match('/^[0-9]+$/', $data) === 1;

    if ($allDigits && strlen($data) >= 4 && strlen($data) % 2 === 0) {
        $codes[] = 105;                       // Start C
        for ($i = 0; $i < strlen($data); $i += 2) {
            $codes[] = (int)substr($data, $i, 2);
        }
    } else {
        $codes[] = 104;                       // Start B
        for ($i = 0; $i < strlen($data); $i++) {
            $ord = ord($data[$i]);
            if ($ord < 32 || $ord > 126) continue;   // subset B range
            $codes[] = $ord - 32;
        }
    }

    // checksum
    $sum = $codes[0];
    for ($i = 1, $n = count($codes); $i < $n; $i++) {
        $sum += $codes[$i] * $i;
    }
    $codes[] = $sum % 103;
    $codes[] = 106;                           // stop

    $bars = [];
    foreach ($codes as $c) {
        $pat = $patterns[$c] ?? $patterns[0];
        $isBar = true;
        foreach (str_split($pat) as $w) {
            $bars[] = [$isBar, (int)$w];
            $isBar = !$isBar;
        }
    }
    return $bars;
}

/**
 * Render a barcode as inline SVG.
 * $height is in millimetres; the module width is chosen so the result is
 * scannable at typical print resolution.
 */
function ctms_barcode_svg(string $data, float $heightMm = 11.0, float $moduleMm = 0.26): string {
    $bars = ctms_code128_bars($data);
    $totalModules = 0;
    foreach ($bars as [$isBar, $w]) $totalModules += $w;
    $quiet = 10;                               // quiet zones, in modules
    $widthMm = ($totalModules + $quiet * 2) * $moduleMm;

    $x = $quiet * $moduleMm;
    $rects = '';
    foreach ($bars as [$isBar, $w]) {
        $wMm = $w * $moduleMm;
        if ($isBar) {
            $rects .= sprintf('<rect x="%.3f" y="0" width="%.3f" height="%.3f"/>', $x, $wMm, $heightMm);
        }
        $x += $wMm;
    }

    return sprintf(
        '<svg class="barcode" xmlns="http://www.w3.org/2000/svg" width="%.2fmm" height="%.2fmm" '
        . 'viewBox="0 0 %.3f %.3f" preserveAspectRatio="none" role="img" aria-label="Barcode %s">'
        . '<rect width="100%%" height="100%%" fill="#fff"/><g fill="#000">%s</g></svg>',
        $widthMm, $heightMm, $widthMm, $heightMm, htmlspecialchars($data, ENT_QUOTES), $rects
    );
}

/**
 * The short code printed under the barcode, used to verify a paper report.
 * Derived from the report number with a secret, so a code cannot be invented
 * for a report that was never issued.
 */
function ctms_verify_code(string $reportNumber, ?string $secret = null): string {
    if ($secret === null || $secret === '') {
        $secret = (string)setting('verify_secret', '');
        if ($secret === '') {
            require_once __DIR__ . '/sso.php';
            $secret = sso_secret($GLOBALS['CFG'] ?? []);
            if ($secret === '') {
                $secret = 'ctms-consult';
            }
        }
    }
    return substr(hash_hmac('sha256', $reportNumber, $secret), 0, 8);
}

/** The value encoded in the barcode: the report number plus its verify code. */
function ctms_barcode_value(string $reportNumber, string $verifyCode): string {
    return $reportNumber . '-' . strtoupper($verifyCode);
}
