<?php
declare(strict_types=1);
/**
 * Database routing.
 *
 * The consultancy platform can run in one of two shapes, chosen in the config
 * file, without any change to the application code:
 *
 *   'db_mode' => 'separate'   its own database (ctms_consult)
 *   'db_mode' => 'merged'     inside the main CTMS database (ctms_db)
 *
 * Why both exist:
 *
 *   SEPARATE is the safer shape while this is still being proven. The
 *   consultancy application then physically cannot read or write anything in
 *   your live CTMS — it is a different database, reached with a different user
 *   that has rights on that database only. A mistake here cannot damage the
 *   records your laboratory has built up.
 *
 *   MERGED is one database to back up and one place to look. It is possible
 *   without conflict because every consultancy table carries a `c_` prefix
 *   (c_reports, c_users, c_samples, …), so none of the 24 tables clash with the
 *   45 tables the main CTMS already uses. Long term this is the tidier shape.
 *
 * The tables are themselves independent in both shapes. Consultancy reports and
 * main-CTMS reports are different kinds of document with different numbers of
 * columns; forcing them into one table would make both worse. What merging gives
 * you is one database, not one set of tables.
 *
 * IMPORTANT: whatever is chosen, a report number is unique within the database
 * it lives in. `c_counters` guarantees that. It does not coordinate with the
 * main CTMS's `report_counters`, so if both systems ever start from the same
 * prefix they should not both begin at 0001.
 */

/**
 * Decide where the application should connect, and complain clearly if the
 * chosen shape is not actually usable.
 */
function db_target(array $cfg): array
{
    $mode = (string)($cfg['db_mode'] ?? 'separate');
    if (!in_array($mode, ['separate', 'merged'], true)) $mode = 'separate';

    $host = (string)($cfg['db_host'] ?? '127.0.0.1');
    $charset = (string)($cfg['db_charset'] ?? 'utf8mb4');

    if ($mode === 'merged') {
        $name = (string)($cfg['merged_db_name'] ?? 'ctms_db');
        // Blank means "use the same account as separate mode" — not "use no
        // account". Reading them straight through would send an empty username
        // and fail with a bare "Access denied".
        $user = trim((string)($cfg['merged_db_user'] ?? ''));
        $pass = (string)($cfg['merged_db_pass'] ?? '');
        if ($user === '') {
            $user = (string)($cfg['db_user'] ?? '');
            $pass = (string)($cfg['db_pass'] ?? '');
        }
        $label = 'merged into ' . $name;
    } else {
        $name = (string)($cfg['db_name'] ?? 'ctms_consult');
        $user = (string)($cfg['db_user'] ?? '');
        $pass = (string)($cfg['db_pass'] ?? '');
        $label = 'separate database ' . $name;
    }

    return [
        'mode'    => $mode,
        'label'   => $label,
        'dsn'     => sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $name, $charset),
        'name'    => $name,
        'user'    => $user,
        'pass'    => $pass,
    ];
}

/**
 * A plain-language explanation of what went wrong, for the person running the
 * system rather than for a developer.
 */
function db_connect_error_message(string $mode, string $name, string $user, Throwable $e): string
{
    $raw = $e->getMessage();
    $tail = '';

    if (str_contains($raw, 'Access denied')) {
        $tail = 'The database refused the username or password.';
        if ($mode === 'merged') {
            $tail .= ' Running in merged mode needs an account with rights on `' . $name . '`. '
                   . 'Grant them once as an administrator, then reload:'
                   . "\n\n    GRANT SELECT, INSERT, UPDATE, DELETE ON `" . $name . "`.* TO '" . $user . "'@'localhost';\n"
                   . "    FLUSH PRIVILEGES;\n";
        } else {
            $tail .= ' Check db_user and db_pass in the config file.';
        }
    } elseif (str_contains($raw, 'Unknown database')) {
        $tail = 'The database `' . $name . '` does not exist. '
              . ($mode === 'merged'
                  ? 'Either create it, or set db_mode back to "separate" to keep using the consultancy database.'
                  : 'Run sql/01_schema.sql to create it.');
    } elseif (str_contains($raw, 'Connection refused') || str_contains($raw, 'No such host')) {
        $tail = 'The database server could not be reached. Is MySQL running in the XAMPP Control Panel?';
    } elseif (str_contains($raw, "doesn't exist") || str_contains($raw, 'Table') && str_contains($raw, 'exist')) {
        $tail = $mode === 'merged'
              ? 'The consultancy tables are not in `' . $name . '` yet. Run sql/merge_into_main.php to move them across.'
              : 'The consultancy tables are missing. Run sql/01_schema.sql.';
    }

    return "Could not connect to the database.\n\n"
         . "  Mode     : $mode ($name)\n"
         . "  Username : $user\n\n"
         . ($tail !== '' ? $tail . "\n\n" : '')
         . "Technical detail (kept out of the web page on purpose):\n  " . $raw;
}
