<?php
declare(strict_types=1);

/**
 * GatsbyWears WMS 2.0 — auth.php
 *
 * Actions (matched by src/lib/api.ts):
 *   login            POST  { identifier, password } → { token, user }
 *   me               GET   → User
 *   change_password  POST  { current_password, new_password } → { message }
 *   update_profile   POST  { name, phone, photo, nid, dob, gender, blood_group, address,
 *                            emergency_name/phone/relation } → User  (self-service; admin-only
 *                            fields like role/branch/designation/salary are NOT editable here)
 *
 * Security:
 *   - login: email OR phone identifier; brute-force lockout (email AND IP,
 *     5 attempts / 60s); generic "Invalid credentials" (no enumeration);
 *     inactive/deleted users rejected.
 *   - bcrypt cost 12; change_password requires current + min strength.
 */

require __DIR__ . '/config.php';

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW_SECS  = 60;
const BCRYPT_COST        = 12;
const PW_MIN_LEN         = 8;

/**
 * Shape a DB user row into the public User contract (+ branch_name).
 */
function user_public(array $row): array
{
    return [
        'id'                   => (int) $row['id'],
        'name'                 => (string) $row['name'],
        'email'                => (string) $row['email'],
        'phone'                => (string) ($row['phone'] ?? ''),
        'role'                 => (string) $row['role'],
        'branch_id'            => $row['branch_id'] !== null ? (int) $row['branch_id'] : null,
        'branch_name'          => $row['branch_name'] ?? null,
        'pos_access'           => (int) $row['pos_access'],
        'must_change_password' => (int) $row['must_change_password'],
        'is_active'            => (int) $row['is_active'],
        // HR / profile (self-service editable subset on the Settings page)
        'photo'                => $row['photo'] ?? null,
        'nid'                  => $row['nid'] ?? null,
        'dob'                  => $row['dob'] ?? null,
        'gender'               => $row['gender'] ?? null,
        'blood_group'          => $row['blood_group'] ?? null,
        'address'              => $row['address'] ?? null,
        'emergency_name'       => $row['emergency_name'] ?? null,
        'emergency_phone'      => $row['emergency_phone'] ?? null,
        'emergency_relation'   => $row['emergency_relation'] ?? null,
        'designation'          => $row['designation'] ?? null,
        'join_date'            => $row['join_date'] ?? null,
        'employment_type'      => $row['employment_type'] ?? null,
        'created_at'           => $row['created_at'] ?? null,
    ];
}

/** Resolve a profile photo: base64 → saved path; existing `uploads/...` kept; null/blank clears. */
function profile_resolve_photo(mixed $v): ?string
{
    if ($v === null) {
        return null;
    }
    $s = is_string($v) ? trim($v) : '';
    if ($s === '') {
        return null;
    }
    if (str_starts_with($s, 'uploads/')) {
        return $s;
    }
    if (str_starts_with($s, 'data:')) {
        return save_product_image_base64($s);
    }
    return null;
}

/**
 * Fetch a full user (with branch_name) by id, or null.
 */
function fetch_user(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT u.*, b.name AS branch_name
         FROM users u LEFT JOIN branches b ON b.id = u.branch_id
         WHERE u.id = ? AND u.deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * Count recent failed attempts for this email OR ip inside the window.
 */
function recent_attempts(?string $email, string $ip): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS c FROM login_attempts
         WHERE attempted_at > (NOW() - INTERVAL ? SECOND)
           AND (ip = ? OR (email IS NOT NULL AND email = ?))'
    );
    $stmt->execute([LOGIN_WINDOW_SECS, $ip, $email]);
    return (int) ($stmt->fetch()['c'] ?? 0);
}

function record_attempt(?string $email, string $ip): void
{
    $stmt = db()->prepare('INSERT INTO login_attempts (email, ip) VALUES (?, ?)');
    $stmt->execute([$email, $ip]);
}

function clear_attempts(?string $email, string $ip): void
{
    $stmt = db()->prepare('DELETE FROM login_attempts WHERE ip = ? OR (email IS NOT NULL AND email = ?)');
    $stmt->execute([$ip, $email]);
}

/**
 * Minimum password strength gate (server-side). Returns null if ok, else message.
 */
function password_problem(string $pw): ?string
{
    if (strlen($pw) < PW_MIN_LEN) {
        return 'Password must be at least ' . PW_MIN_LEN . ' characters.';
    }
    // Require a mix: at least one letter and one digit.
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
        return 'Password must include both letters and numbers.';
    }
    return null;
}

/* ============================================================ */

$action = query('action', '');

switch ($action) {

    /* ---------------- login ---------------- */
    case 'login': {
        if (method() !== 'POST') {
            respond_error('Method not allowed.', 405);
        }
        $identifier = trim((string) body('identifier', ''));
        $password   = (string) body('password', '');
        $ip         = client_ip();

        if ($identifier === '' || $password === '') {
            respond_error('Invalid credentials.', 401, 'invalid_credentials');
        }

        // Brute-force gate (email OR ip).
        if (recent_attempts($identifier, $ip) >= LOGIN_MAX_ATTEMPTS) {
            respond_error('Too many attempts. Please wait a minute and try again.', 429, 'rate_limited');
        }

        // Look up by email OR phone.
        $stmt = db()->prepare(
            'SELECT u.*, b.name AS branch_name
             FROM users u LEFT JOIN branches b ON b.id = u.branch_id
             WHERE (u.email = ? OR u.phone = ?) AND u.deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([$identifier, $identifier]);
        $row = $stmt->fetch();

        $ok = $row && (int) $row['is_active'] === 1
            && password_verify($password, (string) $row['password']);

        if (!$ok) {
            record_attempt($identifier, $ip);
            // Same generic message for all failure modes (no enumeration).
            respond_error('Invalid credentials.', 401, 'invalid_credentials');
        }

        // Success: clear attempts, transparently upgrade legacy hashes.
        clear_attempts($identifier, $ip);
        if (password_needs_rehash((string) $row['password'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST])) {
            $up = db()->prepare('UPDATE users SET password = ? WHERE id = ?');
            $up->execute([password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]), (int) $row['id']]);
        }

        $token = jwt_create([
            'sub'       => (int) $row['id'],
            'role'      => (string) $row['role'],
            'branch_id' => $row['branch_id'] !== null ? (int) $row['branch_id'] : null,
            'name'      => (string) $row['name'],
        ]);

        log_audit([
            'action_type' => 'auth',
            'entity_type' => 'auth',
            'entity_id'   => (int) $row['id'],
            'branch_id'   => $row['branch_id'] !== null ? (int) $row['branch_id'] : null,
            'changed_by'  => (int) $row['id'],
            'reason'      => 'login',
        ]);

        respond(['token' => $token, 'user' => user_public($row)]);
    }

    /* ---------------- me ---------------- */
    case 'me': {
        $auth = require_auth();
        $row  = fetch_user($auth['id']);
        if ($row === null) {
            respond_error('Session expired. Please sign in again.', 401, 'inactive');
        }
        respond(user_public($row));
    }

    /* ---------------- change_password ---------------- */
    case 'change_password': {
        if (method() !== 'POST') {
            respond_error('Method not allowed.', 405);
        }
        $auth    = require_auth();
        $current = (string) body('current_password', '');
        $next    = (string) body('new_password', '');

        // Load the stored hash.
        $stmt = db()->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$auth['id']]);
        $hash = (string) ($stmt->fetch()['password'] ?? '');

        if ($hash === '' || !password_verify($current, $hash)) {
            respond_error('Your current password is incorrect.', 403, 'bad_current');
        }
        if (($problem = password_problem($next)) !== null) {
            respond_error($problem, 422, 'weak_password');
        }
        if (password_verify($next, $hash)) {
            respond_error('New password must be different from the current one.', 422, 'same_password');
        }

        $newHash = password_hash($next, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
        $up = db()->prepare('UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?');
        $up->execute([$newHash, $auth['id']]);

        log_audit([
            'action_type' => 'auth',
            'entity_type' => 'auth',
            'entity_id'   => $auth['id'],
            'branch_id'   => $auth['branch_id'],
            'changed_by'  => $auth['id'],
            'reason'      => 'change_password',
        ]);

        respond(['message' => 'Password updated.']);
    }

    /* ---------------- update_profile ---------------- */
    case 'update_profile': {
        if (method() !== 'POST') {
            respond_error('Method not allowed.', 405);
        }
        $auth = require_auth();
        $b    = request_body();
        $name = isset($b['name']) ? trim((string) $b['name']) : (string) $auth['name'];

        if ($name === '') {
            respond_error('Name is required.', 422, 'missing_field');
        }

        // Phone uniqueness (if changing) — exclude self.
        if (array_key_exists('phone', $b)) {
            $phone = trim((string) $b['phone']);
            if ($phone !== '') {
                $chk = db()->prepare('SELECT id FROM users WHERE phone = ? AND id <> ? LIMIT 1');
                $chk->execute([$phone, $auth['id']]);
                if ($chk->fetch()) {
                    respond_error('That phone number is already in use.', 409, 'phone_taken');
                }
            }
        }

        // Self-editable fields only — never role/branch/designation/salary (admin-managed).
        $fields = ['name = ?'];
        $params = [$name];
        foreach (['phone', 'nid', 'blood_group', 'address', 'emergency_name', 'emergency_phone', 'emergency_relation'] as $k) {
            if (array_key_exists($k, $b)) {
                $v = trim((string) $b[$k]);
                $fields[] = "$k = ?";
                $params[] = $v !== '' ? $v : null;
            }
        }
        if (array_key_exists('dob', $b)) {
            $v = trim((string) $b['dob']);
            $fields[] = 'dob = ?';
            $params[] = $v !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        }
        if (array_key_exists('gender', $b)) {
            $fields[] = 'gender = ?';
            $params[] = in_array($b['gender'], ['male', 'female', 'other'], true) ? $b['gender'] : null;
        }
        if (array_key_exists('photo', $b)) {
            $fields[] = 'photo = ?';
            $params[] = profile_resolve_photo($b['photo']);
        }

        $params[] = $auth['id'];
        db()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

        $row = fetch_user($auth['id']);
        respond(user_public($row));
    }

    default:
        respond_error('Unknown action.', 404, 'unknown_action');
}
