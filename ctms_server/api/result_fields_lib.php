<?php
declare(strict_types=1);

/** Database-backed field definitions for the Phase 3 lab entry grid. */
function ctms_ensure_result_fields(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS lab_result_fields (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lab_type ENUM('xrf','oes','tensile') NOT NULL,
        field_key VARCHAR(80) NOT NULL,
        label VARCHAR(120) NOT NULL,
        unit VARCHAR(30) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        is_builtin TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_lab_field (lab_type, field_key),
        KEY idx_lab_field_order (lab_type, is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $defaults = [
        'xrf' => ['Fe','C','Si','Mn','Cr','Ni','Mo','P','S','Cu'],
        'oes' => ['Fe','C','Si','Mn','Cr','Ni','S','P','Mo','Cu','Sn'],
        'tensile' => [
            'Dia (mm)' => 'Diameter (mm)',
            'Size' => 'Size (mm)',
            'Cross Section Area (mm²)' => 'Cross Section Area (mm²)',
            'Gauge Length (mm)' => 'Gauge Length (mm)',
            'Yield Strength (MPa)' => 'Yield Strength (MPa)',
            'Ultimate Tensile Strength (MPa)' => 'Ultimate Tensile Strength (MPa)',
            'Elongation %' => 'Elongation (%)',
        ],
    ];
    $insert = $pdo->prepare('INSERT IGNORE INTO lab_result_fields
        (lab_type, field_key, label, unit, sort_order, is_active, is_builtin) VALUES (?, ?, ?, ?, ?, 1, 1)');
    foreach ($defaults as $type => $fields) {
        $i = 0;
        foreach ($fields as $key => $value) {
            if ($type === 'tensile') { $fieldKey = (string)$key; $label = (string)$value; $unit = null; }
            else { $fieldKey = (string)$value; $label = (string)$value . ' %'; $unit = '%'; }
            $insert->execute([$type, $fieldKey, $label, $unit, $i++ * 10]);
        }
    }
}

function ctms_result_field_type_for_stream(string $stream): ?string
{
    if (stripos($stream, 'XRF') !== false) return 'xrf';
    if (stripos($stream, 'OES') !== false) return 'oes';
    if (stripos($stream, 'Tensile') !== false) return 'tensile';
    return null;
}

function ctms_result_fields(PDO $pdo, string $type, bool $activeOnly = true): array
{
    $sql = 'SELECT id, lab_type, field_key, label, unit, sort_order, is_active, is_builtin
              FROM lab_result_fields WHERE lab_type = ?';
    if ($activeOnly) $sql .= ' AND is_active = 1';
    $sql .= ' ORDER BY sort_order, id';
    $st = $pdo->prepare($sql);
    $st->execute([$type]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
