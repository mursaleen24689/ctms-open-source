<?php
declare(strict_types=1);
/**
 * Single sign-on from the main CTMS.
 *
 * One login for both systems. You sign in to CTMS Core as you always have, and
 * the Consultancy tab opens without asking again.
 *
 * HOW IT WORKS
 *
 *   1. CTMS Core already issues a signed token on login (HS256, JWT-style,
 *      8 hours). Both systems read the same secret, so this application can
 *      verify that token itself — it never needs to ask CTMS anything.
 *   2. A link to  /ctms_consult/sso.php?t=<token>  is all that is needed.
 *   3. This file checks the signature and the expiry. If either is wrong the
 *      token is refused, so a made-up token cannot get anybody in.
 *   4. The account is matched on employee_id. Its password and role are taken
 *      from the main CTMS `users` table, which stays the single source of
 *      truth — change a password or a role there and it applies here.
 *
 * Nothing here weakens the existing password login, which still works exactly
 * as before for anyone who prefers to go straight to this application.
 */

/**
 * The shared signing secret.
 *
 * Read from the external config first, then the shared secret file that CTMS
 * Core itself creates. Deliberately never a hard-coded fallback: a guessable
 * secret would let anyone mint a valid token, which is worse than the feature
 * not working.
 */
function sso_secret(array $cfg): string
{
    // The consultancy config, if the secret has been copied into it.
    $s = trim((string)($cfg['jwt_secret'] ?? ''));
    if ($s !== '') return $s;

    // The same external config the main CTMS reads. This is normally where it
    // lives, so both systems read one value and cannot drift apart.
    $ctmsCfg = 'C:/xampp/ctms_config/ctms_config.php';
    if (is_readable($ctmsCfg)) {
        $c = require $ctmsCfg;
        if (is_array($c) && !empty($c['jwt_secret'])) {
            return trim((string)$c['jwt_secret']);
        }
    }

    // The file CTMS Core writes when no secret is configured.
    $file = 'C:/xampp/ctms_config/jwt_secret.txt';
    if (is_readable($file)) {
        $s = trim((string)file_get_contents($file));
        if ($s !== '') return $s;
    }

    // An environment variable, as CTMS also honours.
    $env = getenv('JWT_SECRET');
    if (is_string($env) && trim($env) !== '') return trim($env);

    return '';
}

function sso_b64url_decode(string $s): string
{
    return (string)base64_decode(str_replace(['-', '_'], ['+', '/'], $s), true);
}

/**
 * Verify a token issued by CTMS Core.
 *
 * Returns ['user_id' => int, 'role' => string] or null. Uses hash_equals for
 * the signature so a wrong token cannot be found by timing how long the check
 * takes.
 */
function sso_verify(string $token, string $secret): ?array
{
    if ($secret === '') return null;

    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $payload, $signature] = $parts;

    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$payload", $secret, true)), '+/', '-_'), '=');
    if (!hash_equals($expected, $signature)) return null;

    $json = sso_b64url_decode($payload);
    if ($json === '') return null;
    $data = json_decode($json, true);
    if (!is_array($data)) return null;

    // expired, or issued so far in the future that it cannot be genuine
    $now = time();
    if (!isset($data['exp']) || (int)$data['exp'] < $now) return null;
    if (isset($data['iat']) && (int)$data['iat'] > $now + 120) return null;

    $uid = (int)($data['user_id'] ?? 0);
    if ($uid <= 0) return null;

    return ['user_id' => $uid, 'role' => (string)($data['role'] ?? '')];
}

/** Accept only a local CTMS page as the post-logout destination. */
function ctms_safe_return_url(string $raw): ?string
{
    $p = parse_url(trim($raw));
    if (!is_array($p) || !in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true)) return null;
    $host = strtolower((string)($p['host'] ?? ''));
    $trusted = ['localhost', '127.0.0.1', '::1'];
    $serverAddr = strtolower(trim((string)($_SERVER['SERVER_ADDR'] ?? '')));
    if (filter_var($serverAddr, FILTER_VALIDATE_IP)) $trusted[] = $serverAddr;
    $serverName = strtolower((string)($_SERVER['SERVER_NAME'] ?? ''));
    if ($serverName !== '') $trusted[] = $serverName;
    $mainCfg = 'C:/xampp/ctms_config/ctms_config.php';
    if (is_readable($mainCfg)) {
        $cfg = require $mainCfg;
        if (is_array($cfg) && !empty($cfg['allowed_hosts']) && is_array($cfg['allowed_hosts'])) {
            $trusted = array_merge($trusted, array_map('strtolower', $cfg['allowed_hosts']));
        }
    }
    if ($host === '' || !in_array($host, $trusted, true)) return null;
    $path = (string)($p['path'] ?? '/');
    if ($path === '' || $path[0] !== '/' || str_contains($path, "\\")) return null;
    $port = isset($p['port']) ? ':' . (int)$p['port'] : '';
    return strtolower((string)$p['scheme']) . '://' . $host . $port . $path;
}

/**
 * Map a CTMS Core role onto a consultancy role.
 *
 * The two systems give the same person different names for the same job, so the
 * mapping is explicit rather than assumed. Anything unrecognised becomes
 * `viewer`: able to look, unable to change anything. Failing closed matters —
 * an unknown role must never quietly become an administrator.
 */
function sso_map_role(string $ctmsRole): string
{
    return match (strtolower(trim($ctmsRole))) {
        'superadmin'            => 'superadmin',
        'admin'                 => 'admin',
        'hod', 'chairman'       => 'professor',   // they approve reports
        'pa'                    => 'admin',       // personal assistant to the chair
        'deo'                   => 'technician',  // records readings
        'technician', 'tester'  => 'technician',
        'mapping', 'reception'  => 'reception',
        default                 => 'viewer',
    };
}

/**
 * Find or create the consultancy account for a CTMS user, then return it.
 *
 * The account is matched on employee_id, so the same person is never created
 * twice. Name and role are refreshed from CTMS Core on every sign-in, so a
 * promotion or a rename there takes effect here without anyone maintaining two
 * lists.
 */
function sso_find_or_create_user(array $ctmsUser, ?string $orientation = null): array
{
    $pdo = $GLOBALS['pdo'];

    $employeeId = trim((string)($ctmsUser['employee_id'] ?? ''));
    if ($employeeId === '') return [];

    $allowedRoles = ['superadmin', 'admin', 'professor', 'technician', 'reception', 'viewer'];
    $role = (string)($ctmsUser['consultancy_role'] ?? '');
    if (!in_array($role, $allowedRoles, true)) $role = sso_map_role((string)($ctmsUser['role'] ?? ''));

    // The two tables were created by different systems and their text columns
    // use different collations (unicode_ci here, general_ci in CTMS Core). A
    // plain comparison raises "Illegal mix of collations", and attaching a
    // COLLATE to the placeholder does not help because a bound parameter is
    // delivered as binary. Comparing both sides as BINARY is unambiguous and
    // byte-wise, which is exactly right for an email address used as an
    // identifier.
    $st = $pdo->prepare('SELECT * FROM c_users WHERE employee_id = CAST(? AS BINARY) LIMIT 1');
    $st->execute([$employeeId]);
    $existing = $st->fetch();

    $active = !empty($ctmsUser['is_active']) && empty($ctmsUser['is_deleted']) ? 1 : 0;

    if ($existing) {
        // keep the consultancy record in step with the main CTMS
        $pdo->prepare('UPDATE c_users SET full_name = ?, password_hash = ?, role = ?, lab_stream = ?, designation = ?,
                          is_active = ?, is_deleted = ?, must_change_password = ?, ctms_user_id = ?, updated_at = NOW()
                        WHERE id = ?')
            ->execute([
                (string)($ctmsUser['full_name'] ?? $employeeId),
                (string)$ctmsUser['password_hash'],
                $role,
                $ctmsUser['lab_stream'] ?? null,
                $ctmsUser['designation'] ?? null,
                $active,
                !empty($ctmsUser['is_deleted']) ? 1 : 0,
                !empty($ctmsUser['must_change_password']) ? 1 : 0,
                (int)$ctmsUser['id'],
                (int)$existing['id'],
            ]);
        // a returning user keeps whichever working mode they chose
        if ($orientation !== null && $orientation !== (string)$existing['report_mode']) {
            $pdo->prepare('UPDATE c_users SET report_mode = ? WHERE id = ?')
                ->execute([$orientation === 'direct' ? 'direct' : 'guided', (int)$existing['id']]);
        }
        $st = $pdo->prepare('SELECT * FROM c_users WHERE id = ?');
        $st->execute([(int)$existing['id']]);
        return $st->fetch() ?: [];
    }

    // first visit: create the account against the CTMS identity.
    // The password hash is carried across, so this works even if the token is
    // not used — the person can also sign in here with their usual password.
    $pdo->prepare('INSERT INTO c_users
        (ctms_user_id, employee_id, full_name, password_hash, role, lab_stream, designation,
         is_active, is_deleted, must_change_password, report_mode)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([
            (int)$ctmsUser['id'],
            $employeeId,
            (string)($ctmsUser['full_name'] ?? $employeeId),
            (string)($ctmsUser['password_hash'] ?? password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT)),
            $role,
            $ctmsUser['lab_stream'] ?? null,
            $ctmsUser['designation'] ?? null,
            $active,
            !empty($ctmsUser['is_deleted']) ? 1 : 0,
            !empty($ctmsUser['must_change_password']) ? 1 : 0,
            $orientation === 'direct' ? 'direct' : 'guided',
        ]);

    $st = $pdo->prepare('SELECT * FROM c_users WHERE id = ?');
    $st->execute([(int)$pdo->lastInsertId()]);
    return $st->fetch() ?: [];
}
