<?php
declare(strict_types=1);
/**
 * Report layout settings.
 *
 * The laboratory's staffing changes: sometimes there are two people signing,
 * sometimes three, and the wording differs by who was actually involved. So the
 * signature block is DATA, not code. An administrator, HOD, professor or
 * technician can change it without anyone editing a file.
 */


/**
 * The signature columns printed at the foot of a report.
 * Returns a list of ['label' => string, 'active' => bool].
 *
 * Read through setting_fresh(), not setting(): this value is written and then
 * read again within the same request when the settings screen saves, and the
 * cached read would show the value from page load instead of the change.
 */
function report_signatures(): array {
    $raw = setting_fresh('report_signatures', '');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $out = [];
            foreach ($decoded as $s) {
                if (!is_array($s)) continue;
                $label = trim((string)($s['label'] ?? ''));
                if ($label === '') continue;
                $flag = $s['active'] ?? false;
                $out[] = [
                    'label'  => $label,
                    'active' => ($flag === true || $flag === 1 || $flag === '1'),
                ];
            }
            if ($out) return $out;
        }
    }
    return report_signatures_default();
}

/**
 * The starting set.
 *
 * "Measured By" is present but switched OFF: the laboratory's current practice is
 * that the technician records the readings in the system and the report is
 * checked and issued, so a separate measurement signature is not printed.
 * Switching it back on is a one-click change on the Report settings screen.
 */
function report_signatures_default(): array {
    return [
        ['label' => 'Measured By',                     'active' => false],
        ['label' => 'Checked By',                      'active' => true],
        ['label' => 'Issued by Head of the Department (HOD)', 'active' => true],
    ];
}

/** Only the ones that actually print. */
function report_signatures_active(): array {
    return array_values(array_filter(report_signatures(), fn($s) => $s['active']));
}

/**
 * Report layout options that a user may change.
 * Keys match the c_settings rows so the settings page stays generic.
 */
function report_layout_defaults(): array {
    return [
        'report_orientation'   => 'landscape',
        'report_show_barcode'  => '1',
        'report_show_summary'  => '1',
        'report_show_remarks'  => '1',
        'require_approval'     => '1',
        'report_number_prefix' => 'RPT',
        'report_signatures'    => json_encode(report_signatures_default(), JSON_UNESCAPED_UNICODE),
    ];
}

/** Read one layout option with its default. */
function report_layout(string $key): string {
    $defaults = report_layout_defaults();
    return (string)setting($key, $defaults[$key] ?? '');
}

/** Save the whole signature list. */
function report_signatures_save(array $list): void {
    $clean = [];
    $seen  = [];
    foreach ($list as $s) {
        $label = trim((string)($s['label'] ?? ''));
        if ($label === '') continue;
        $key = mb_strtolower($label);
        if (isset($seen[$key])) continue;      // never store the same wording twice
        $seen[$key] = true;
        // Explicit boolean test: a switched-off line must stay off. !empty() is not
        // used because the posted value can legitimately be the string "0".
        $flag   = $s['active'] ?? false;
        $active = ($flag === true || $flag === 1 || $flag === '1');
        $clean[] = ['label' => mb_substr($label, 0, 120), 'active' => $active];
    }
    if (!$clean) $clean = report_signatures_default();
    setting_set('report_signatures', json_encode($clean, JSON_UNESCAPED_UNICODE));
}
