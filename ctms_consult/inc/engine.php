<?php
declare(strict_types=1);
/**
 * CTMS Consultancy Platform - standards & validation engine
 *
 * THE CORE RULE OF THIS FILE, decided by the laboratory:
 *
 *   The engine may SUGGEST, PARSE, NORMALISE, COMPARE and WARN.
 *   It may NEVER invent a measured value, approve a result, or block a
 *   technician. Instruments here are not always calibrated and many readings
 *   are judged by eye, so a value outside a published range is a prompt for a
 *   human look, never a refusal.
 *
 * Every warning that is overridden is recorded, so nothing is hidden.
 */


// ---------------------------------------------------------------------------
//  CACHED DEFINITIONS
// ---------------------------------------------------------------------------

function engine_measurements(): array {
    static $m = null;
    if ($m !== null) return $m;
    $m = [];
    foreach ($GLOBALS['pdo']->query('SELECT * FROM c_measurements') as $r) {
        $r['accepted_units_arr'] = json_decode($r['accepted_units'] ?? '{}', true) ?: [];
        $r['marker_arr']         = json_decode($r['marker_json'] ?? '{}', true) ?: [];
        $m[$r['code']] = $r;
    }
    return $m;
}

function engine_measurement(int $id): ?array {
    foreach (engine_measurements() as $m) {
        if ((int)$m['id'] === $id) return $m;
    }
    return null;
}

/** Rules for one test type: measurement -> {normal_min, normal_max, warn, ...} */
function engine_rules(int $testTypeId): array {
    static $cache = [];
    if (isset($cache[$testTypeId])) return $cache[$testTypeId];
    $st = $GLOBALS['pdo']->prepare(
        'SELECT tm.*, m.code AS m_code, m.name AS m_name, m.symbol AS m_symbol, m.is_analyte, m.canonical_unit,
                m.accepted_units, m.marker_json, m.value_kind
           FROM c_test_measurements tm
           JOIN c_measurements m ON m.id = tm.measurement_id
          WHERE tm.test_type_id = ?
          ORDER BY tm.sort_order, tm.id');
    $st->execute([$testTypeId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $r['accepted_units_arr'] = json_decode($r['accepted_units'] ?? '{}', true) ?: [];
        $r['marker_arr']         = json_decode($r['marker_json'] ?? '{}', true) ?: [];
        $out[$r['m_code']] = $r;
    }
    return $cache[$testTypeId] = $out;
}

function engine_measurement_by_code(string $code): ?array {
    return engine_measurements()[$code] ?? null;
}

// ---------------------------------------------------------------------------
//  PARSING - turning what a technician typed into something usable
// ---------------------------------------------------------------------------

/**
 * Meanings that are valid in ANY numeric field, whatever it measures.
 * A technician writing "Balance" in a width box has still written something
 * meaningful, and turning it into 0 or discarding it would both be wrong.
 * Per-measurement markers (see c_measurements.marker_json) take priority.
 */
function engine_global_markers(): array {
    return [
        'balance'         => 'remainder',
        'bal.'            => 'remainder',
        'bal'             => 'remainder',
        'remainder'       => 'remainder',
        'rem'             => 'remainder',
        '--'              => 'not_measured',
        '---'             => 'not_measured',
        '-'               => 'not_measured',
        'n/a'             => 'not_applicable',
        'na'              => 'not_applicable',
        'nil'             => 'none',
        'none'            => 'none',
        'not tested'      => 'not_tested',
        'not tested.'     => 'not_tested',
        'room temp.'      => 'room_temperature',
        'room temp'       => 'room_temperature',
        'room temperature'=> 'room_temperature',
        'rt'              => 'room_temperature',
        'ambient'         => 'room_temperature',
        'ok'              => 'acceptable',
        'pass'            => 'pass',
        'passed'          => 'pass',
        'fail'            => 'fail',
        'failed'          => 'fail',
    ];
}

/**
 * Parse a raw typed value. Returns an array describing EVERYTHING that was
 * understood, so nothing is lost:
 *
 *   raw       the exact text, preserved forever
 *   num       the number, or null when the entry is not numeric
 *   text      the resolved meaning ('remainder', 'below_detection', 'spec_max'...)
 *   bdl       true when below the detection limit
 *   is_ref    true when this is a specification/reference value, NOT a measurement
 *   unit      the unit as typed, when present
 *   range_to  the upper end, when the entry was a range like "18 to 21"
 */
function engine_parse(?string $raw, array $definition = []): array {
    $out = [
        'raw' => $raw, 'num' => null, 'text' => null, 'bdl' => false,
        'is_ref' => false, 'unit' => null, 'range_to' => null,
    ];
    if ($raw === null) return $out;
    $s = trim($raw);
    if ($s === '') return $out;

    // 1. known verbal markers have priority over number parsing.
    //    Per-measurement markers first, then the shared vocabulary.
    $markers = ($definition['marker_arr'] ?? []) + engine_global_markers();
    foreach ($markers as $marker => $meaning) {
        if (strcasecmp($s, (string)$marker) === 0) {
            $out['text'] = $meaning;
            if ($meaning === 'below_detection') $out['bdl'] = true;
            if ($meaning === 'spec_min' || $meaning === 'spec_max') $out['is_ref'] = true;
            return $out;
        }
    }

    // 2. "Max 0.23" / "Min 74" / "Mini 0.40" -> a specification limit, not a reading
    if (preg_match('/^(max|maxi|maximum)\.?\s*([0-9]*\.?[0-9]+)/i', $s, $m)) {
        $out['num'] = (float)$m[2]; $out['text'] = 'spec_max'; $out['is_ref'] = true; return $out;
    }
    if (preg_match('/^(min|mini|minimum)\.?\s*([0-9]*\.?[0-9]+)/i', $s, $m)) {
        $out['num'] = (float)$m[2]; $out['text'] = 'spec_min'; $out['is_ref'] = true; return $out;
    }

    // 3. comparison operators
    if (preg_match('/^<\s*([0-9]*\.?[0-9]+)/', $s, $m)) {
        $out['num'] = (float)$m[1]; $out['text'] = 'below_detection'; $out['bdl'] = true; return $out;
    }
    if (preg_match('/^>\s*([0-9]*\.?[0-9]+)/', $s, $m)) {
        $out['num'] = (float)$m[1]; $out['text'] = 'above_limit'; return $out;
    }

    // 4. a range: "18 to 21", "220-240"
    if (preg_match('/^([0-9]*\.?[0-9]+)\s*(?:to|TO|–|—|-)\s*([0-9]*\.?[0-9]+)$/u', $s, $m)) {
        $out['num'] = (float)$m[1]; $out['range_to'] = (float)$m[2];
        $out['text'] = 'range'; $out['is_ref'] = true; return $out;
    }

    // 5. a dimension pair: "13.92x13.36" stays text; it is not one number
    if (preg_match('/^[0-9]*\.?[0-9]+\s*[xX×]\s*[0-9]*\.?[0-9]+$/', $s)) {
        $out['text'] = 'dimension_pair'; return $out;
    }

    // 6. a plain number with an optional unit
    if (preg_match('/^(-?[0-9]+(?:\.[0-9]+)?(?:[eE][-+]?[0-9]+)?)\s*([A-Za-z%°µ\/²³]+)?$/u', $s, $m)) {
        $out['num']  = (float)$m[1];
        $out['unit'] = $m[2] ?? null;
        return $out;
    }

    // 7. anything else is kept verbatim; it is never silently discarded
    $out['text'] = 'text';
    return $out;
}

/**
 * Convert a value to the measurement's canonical unit.
 * Returns [value, note]. 'MPa' and 'N/mm2' are the same thing; ksi is not.
 */
function engine_to_canonical(?float $value, ?string $unit, array $definition): array {
    if ($value === null) return [null, null];
    $units = $definition['accepted_units_arr'] ?? [];
    if (!$units) return [$value, null];
    if ($unit === null || $unit === '') {
        // No unit given. If the value already sits in a sensible range for the
        // canonical unit, accept it as canonical rather than guessing a factor.
        return [$value, 'no unit given; treated as ' . ($definition['canonical_unit'] ?: 'canonical')];
    }
    $key = trim($unit);
    foreach ($units as $u => $factor) {
        if (strcasecmp($u, $key) === 0) {
            if ((float)$factor === 1.0) return [$value, null];
            return [$value * (float)$factor, "converted from {$key}"];
        }
    }
    return [$value, "unit '{$key}' is not recognised for this measurement; value stored as typed"];
}

// ---------------------------------------------------------------------------
//  VALIDATION - warning only, never blocking
// ---------------------------------------------------------------------------

/**
 * Check one parsed value against the rules for its test type.
 * Returns ['state' => ok|warning|error, 'note' => '...', 'suggestion' => '...']
 *
 * Only three things are ever treated as 'error', because they are not
 * disagreements about quality, they are internal contradictions that make the
 * number meaningless. Even then the caller records rather than refuses.
 */
function engine_validate(array $parsed, array $rule): array {
    // A specification/reference value is not a measurement, so range rules
    // designed for readings do not apply to it.
    if (!empty($parsed['is_ref'])) {
        return ['state' => 'ok', 'note' => 'reference value, not a measurement', 'suggestion' => null];
    }
    if ($parsed['num'] === null) {
        return ['state' => 'ok', 'note' => $parsed['text'] ? "kept as '{$parsed['text']}'" : 'empty', 'suggestion' => null];
    }

    $v    = (float)$parsed['num'];
    $nmin = $rule['normal_min'] !== null ? (float)$rule['normal_min'] : null;
    $nmax = $rule['normal_max'] !== null ? (float)$rule['normal_max'] : null;
    $warn = $rule['warn_message'] ?: 'This value is outside the usual range for this test.';

    if ($nmin !== null && $v < $nmin) {
        return ['state' => 'warning', 'note' => $warn . " (entered {$v}, expected at least {$nmin})", 'suggestion' => null];
    }
    if ($nmax !== null && $v > $nmax) {
        return ['state' => 'warning', 'note' => $warn . " (entered {$v}, expected at most {$nmax})", 'suggestion' => null];
    }
    return ['state' => 'ok', 'note' => '', 'suggestion' => null];
}

/**
 * Cross-checks between values in one test row - the checks that catch real
 * laboratory mistakes rather than out-of-range readings.
 *
 * $values is [measurement_code => parsed array].
 * Returns a list of messages; each has severity 'warning' or 'error'.
 */
function engine_crosscheck(array $values): array {
    $issues = [];
    $get = fn(string $code) => $values[$code]['num'] ?? null;
    $ref = fn(string $code) => !empty($values[$code]['is_ref']);

    // 1. UTS must not be below yield. This is the impossible case found in the
    //    Dr. Department Chair report, where yield exceeded UTS in all four rows.
    $ys = $get('YS'); $uts = $get('UTS');
    if ($ys !== null && $uts !== null && !$ref('YS') && !$ref('UTS') && $uts < $ys) {
        $d = $ys - $uts;
        $issues[] = [
            'severity' => 'error',
            'code' => 'UTS_BELOW_YS',
            'message' => "Ultimate tensile strength ({$uts}) is LOWER than yield strength ({$ys}), "
                       . "which is not physically possible. Either the two columns are the wrong way "
                       . "round, or a value was entered in the wrong box. Please check before continuing.",
        ];
    }

    // 2. Cross-section area against the stated dimensions. This is the A-36
    //    typo (156.38 typed as 1156.38) - a factor of 7.4.
    $w = $get('DIM_WIDTH'); $t = $get('DIM_THICKNESS'); $d = $get('DIM_DIAMETER'); $a = $get('CROSS_SECTION_AREA');
    if ($a !== null && $a > 0 && !$ref('CROSS_SECTION_AREA')) {
        $geo = null; $how = '';
        if ($w !== null && $t !== null && $w > 0 && $t > 0) { $geo = $w * $t; $how = "{$w} x {$t}"; }
        elseif ($d !== null && $d > 0) { $geo = M_PI / 4 * $d * $d; $how = "a diameter of {$d} mm"; }
        if ($geo !== null && $geo > 0) {
            $ratio = $a / $geo;
            if ($ratio > 1.5 || $ratio < 0.67) {
                $issues[] = [
                    'severity' => 'error',
                    'code' => 'AREA_MISMATCH',
                    'message' => "The cross-section area {$a} mm2 does not match {$how} "
                               . "(that gives " . round($geo, 2) . " mm2, " . round($ratio, 2) . "x different). "
                               . "This looks like a typing error and it will change the calculated strengths.",
                ];
            }
        }
    }

    // 3. A mean must follow from its readings. Catches the report where
    //    248.3 and 259.8 were printed with a mean of 255.96.
    //    (Handled in engine_check_mean, called by the entry screen.)

    // 4. Elongation above 100% is impossible for a metal.
    $el = $get('ELONGATION');
    if ($el !== null && $el > 100 && !in_array(($values['ELONGATION']['text'] ?? ''), ['range', 'spec_max', 'spec_min'], true)) {
        $issues[] = [
            'severity' => 'error',
            'code' => 'ELONGATION_IMPOSSIBLE',
            'message' => "Elongation of {$el}% is not possible. A reading around "
                       . round($el / 100, 3) . "% would be typical; the value may have been "
                       . "multiplied by 100 twice.",
        ];
    }

    // 5. Loads: a maximum load below the yield load is contradictory.
    $yl = $get('YIELD_LOAD'); $ml = $get('MAX_LOAD');
    if ($yl !== null && $ml !== null && $ml < $yl) {
        $issues[] = [
            'severity' => 'warning',
            'code' => 'MAXLOAD_BELOW_YIELDLOAD',
            'message' => "Maximum load ({$ml}) is below the yield load ({$yl}). "
                       . "Check which column each value belongs to.",
        ];
    }

    // 6. Iron as 'Balance' alongside elements that already exceed 100%.
    $sum = 0.0; $counted = 0;
    foreach ($values as $code => $p) {
        if (str_starts_with((string)$code, 'EL_') && $code !== 'EL_FE'
            && $p['num'] !== null && empty($p['is_ref'])) {
            $sum += (float)$p['num']; $counted++;
        }
    }
    if ($counted >= 3 && $sum > 100.5) {
        $issues[] = [
            'severity' => 'warning',
            'code' => 'COMPOSITION_OVER_100',
            'message' => 'The entered element percentages already total ' . round($sum, 3)
                       . '%, which is over 100%. Check for a misplaced decimal point.',
        ];
    }

    return $issues;
}

/**
 * Does the printed mean follow from the readings?
 * Non-blocking: returns a message when it does not, plus the correct value as a
 * SUGGESTION the technician may accept with one click.
 */
function engine_check_mean(array $readings, ?float $printedMean): ?array {
    $present = array_values(array_filter($readings, fn($v) => $v !== null));
    if (!$present || $printedMean === null) return null;
    $calc = array_sum($present) / count($present);
    if (abs($calc - $printedMean) <= 0.02) return null;
    return [
        'severity'   => 'warning',
        'code'       => 'MEAN_MISMATCH',
        'message'    => 'The mean does not follow from the readings: ' . count($present)
                      . ' reading(s) average to ' . round($calc, 2) . ', but ' . $printedMean
                      . ' was entered.',
        'suggestion' => round($calc, 2),
        'based_on'   => $present,
    ];
}

// ---------------------------------------------------------------------------
//  GRADE ACCEPTANCE  (PASS / FAIL suggestion - never automatic approval)
// ---------------------------------------------------------------------------

function engine_grade_limits(string $gradeCode): array {
    $st = $GLOBALS['pdo']->prepare(
        'SELECT gl.*, m.code AS m_code, m.name AS m_name, m.canonical_unit
           FROM c_grade_limits gl
           JOIN c_measurements m ON m.id = gl.measurement_id
          WHERE gl.grade_code = ?');
    $st->execute([$gradeCode]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[$r['m_code']] = $r;
    return $out;
}

/**
 * Suggest PASS/FAIL for one test row against a named grade.
 * Returns a list of per-measurement verdicts. The verdict is a SUGGESTION for
 * the technician or professor to confirm; it is never written as an approval.
 */
function engine_grade_verdict(string $gradeCode, array $values): array {
    $limits = engine_grade_limits($gradeCode);
    if (!$limits) return [];
    $out = [];
    foreach ($limits as $code => $lim) {
        $p = $values[$code] ?? null;
        if (!$p || $p['num'] === null || !empty($p['is_ref'])) continue;
        $v = (float)$p['num'];
        $pass = true; $why = [];
        if ($lim['min_value'] !== null && $v < (float)$lim['min_value']) {
            $pass = false; $why[] = 'below the required minimum of ' . (float)$lim['min_value'];
        }
        if ($lim['max_value'] !== null && $v > (float)$lim['max_value']) {
            $pass = false; $why[] = 'above the permitted maximum of ' . (float)$lim['max_value'];
        }
        $out[] = [
            'measurement' => $lim['m_name'],
            'code'        => $code,
            'value'       => $v,
            'unit'        => $lim['canonical_unit'],
            'requirement' => $lim['requirement_text'],
            'standard'    => $lim['standard_id'] ? engine_standard((int)$lim['standard_id'])['code'] ?? null : null,
            'verdict'     => $pass ? 'PASS' : 'FAIL',
            'because'     => $why ? implode('; ', $why) : 'meets the requirement',
        ];
    }
    return $out;
}

function engine_standard(int $id): ?array {
    static $c = [];
    if (isset($c[$id])) return $c[$id];
    $st = $GLOBALS['pdo']->prepare('SELECT * FROM c_standards WHERE id = ?');
    $st->execute([$id]);
    return $c[$id] = ($st->fetch() ?: null);
}

// ---------------------------------------------------------------------------
//  SUGGESTIONS  (standards, test sets, similar past work)
// ---------------------------------------------------------------------------

function engine_suggest_standard(int $testTypeId): ?array {
    $st = $GLOBALS['pdo']->prepare('SELECT default_standard_id FROM c_test_types WHERE id = ?');
    $st->execute([$testTypeId]);
    $sid = $st->fetchColumn();
    return $sid ? engine_standard((int)$sid) : null;
}

/**
 * Propose the test set for a sample, based on what was actually ordered before.
 * Every suggestion is offered for approval; nothing is applied automatically.
 */
function engine_suggest_tests(string $description, int $limit = 8): array {
    $description = mb_strtolower(trim($description));
    if ($description === '') return [];
    $words = preg_split('/[^a-z0-9]+/u', $description, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    // include the whole phrase so multi-word keywords like 'case depth' match
    $needles = array_unique(array_merge($words, [$description]));

    $found = [];
    $st = $GLOBALS['pdo']->prepare(
        'SELECT ts.keyword, ts.confidence, ts.material_group,
                tt.id AS test_type_id, tt.code, tt.name, tt.category
           FROM c_test_suggestions ts
           JOIN c_test_types tt ON tt.id = ts.test_type_id
          WHERE ? LIKE CONCAT("%", ts.keyword, "%")
             OR ts.keyword LIKE CONCAT("%", ?, "%")
          ORDER BY ts.confidence DESC');
    foreach ($needles as $n) {
        if ($n === '') continue;
        $st->execute([$n, $n]);
        foreach ($st->fetchAll() as $r) {
            $id = (int)$r['test_type_id'];
            $conf = (float)$r['confidence'];
            if (!isset($found[$id]) || $found[$id]['confidence'] < $conf) {
                $found[$id] = $r;
            }
        }
    }
    uasort($found, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
    return array_slice(array_values($found), 0, $limit);
}

/** Detect a material group from free text, to pick the right grade list. */
function engine_guess_material(string $description): ?string {
    $d = mb_strtolower($description);
    $map = [
        'stainless' => ['stainless', 'ss 304', 'ss 316', 'ss304', 'ss316'],
        'steel'     => ['steel', 'rebar', 'bar', 'plate', 'bolt', 'wire', 'rod', 'pipe', 'beam', 'angle',
                        'a36', 'a572', 'a615', 'mild steel', 'hrc', 'crc', 'ingot', 'billet'],
        'polymer'   => ['polystyrene', 'plastic', 'polymer', 'polyethylene', 'pvc', 'hdpe'],
        'cement'    => ['cement', 'mortar', 'concrete', 'marble', 'stone'],
        'electronics' => ['handfree', 'handsfree', 'stove', 'iron', 'fan', 'kettle', 'toaster', 'dispenser'],
    ];
    foreach ($map as $group => $needles) {
        foreach ($needles as $n) {
            if (str_contains($d, $n)) return $group;
        }
    }
    return null;
}

/** Offer grade codes that might match what the client claimed. */
function engine_suggest_grades(string $description): array {
    $group = engine_guess_material($description);
    $sql = 'SELECT DISTINCT grade_code, grade_name FROM c_grade_limits';
    $params = [];
    if ($group) { $sql .= ' WHERE material_group = ?'; $params[] = $group; }
    $sql .= ' ORDER BY grade_code';
    $st = $GLOBALS['pdo']->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Find previous tests of the same kind so the technician can copy values
 * across. This is the single most requested feature: when the same sample
 * arrives again, the old readings are pasted in and only the changes are typed.
 */
function engine_similar_jobs(int $testTypeId, ?string $description = null, int $limit = 10): array {
    $sql = 'SELECT j.id AS job_id, j.status, j.entered_at,
                   s.id AS sample_id, s.sample_code, s.description, s.company_name,
                   tt.name AS test_name, tt.code AS test_code,
                   (SELECT COUNT(*) FROM c_readings r WHERE r.job_id = j.id AND r.is_current = 1) AS readings
              FROM c_test_jobs j
              JOIN c_samples  s  ON s.id  = j.sample_id
              JOIN c_test_types tt ON tt.id = j.test_type_id
             WHERE j.test_type_id = ?
               AND j.status IN ("entered","checked","approved")
               AND j.id <> COALESCE(?, 0)
               AND s.is_deleted = 0';
    $params = [$testTypeId];
    if ($description) {
        $words = preg_split('/[^a-z0-9]+/u', mb_strtolower($description), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $word = $words[0] ?? '';
        if ($word !== '') {
            $sql .= ' AND (s.description LIKE ? OR s.company_name LIKE ?)';
            $params[] = '%' . $word . '%';
            $params[] = '%' . $word . '%';
        }
    }
    $sql .= ' ORDER BY j.entered_at DESC LIMIT ' . (int)$limit;
    try {
        $st = $GLOBALS['pdo']->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Load every current reading for a job, grouped by row. */
function engine_job_readings(int $jobId): array {
    $st = $GLOBALS['pdo']->prepare(
        'SELECT r.*, m.code AS m_code, m.name AS m_name, m.canonical_unit, m.value_kind
           FROM c_readings r
           JOIN c_measurements m ON m.id = r.measurement_id
          WHERE r.job_id = ? AND r.is_current = 1
          ORDER BY r.row_index, r.col_index');
    $st->execute([$jobId]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[(int)$r['row_index']][$r['m_code']][(int)$r['col_index']] = $r;
    }
    return $rows;
}

/** Record a suggestion so its outcome can be measured later. */
function engine_log_suggestion(string $context, string $text, ?float $confidence = null,
                               ?string $basis = null, ?int $jobId = null,
                               ?int $sampleId = null, ?array $payload = null): int {
    $st = $GLOBALS['pdo']->prepare(
        'INSERT INTO c_suggestions (context, job_id, sample_id, suggestion, payload_json, confidence, basis)
         VALUES (?,?,?,?,?,?,?)');
    $st->execute([
        $context, $jobId, $sampleId, mb_substr($text, 0, 500),
        $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
        $confidence, $basis ? mb_substr($basis, 0, 300) : null,
    ]);
    return (int)$GLOBALS['pdo']->lastInsertId();
}
