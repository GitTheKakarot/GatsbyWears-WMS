<?php
declare(strict_types=1);

/**
 * GatsbyWears WMS 2.0 — workers.php (Team)
 *
 * Actions (matched by src/lib/api.ts):
 *   GET    (no action)            list          → User[]   (manager: own-branch; admin: all)
 *   POST   (no action)           add           → User      (admin only)
 *   PUT    ?id=N                  update        → User      (admin: any field; manager: own-branch worker name/phone/pos_access)
 *   DELETE ?id=N                 soft-delete    → { id }    (admin only)
 *   POST   ?action=reset_password {id}          → { temp_password }  (admin any; manager own-branch worker)
 *   GET    ?action=trash         trash list    → User[]    (admin)
 *   POST   ?action=restore  {id} restore       → { id }    (admin)
 *   POST   ?action=purge    {id} hard delete   → { id }    (admin; blocked if sales history)
 *
 * Hard rules: role can only ever be worker|manager via API (never super_admin).
 * Managers cannot add/remove/change role/branch; only edit own-branch workers.
 */

require __DIR__ . '/config.php';

const WORKER_BCRYPT_COST = 12;
const TEMP_PW_LEN        = 10;

/**
 * Shape a user row (joined branch) into the public User contract.
 * Salary is sensitive: included only when $admin (never exposed to managers).
 */
function worker_public(array $r, bool $admin = false): array
{
    $out = [
        'id'                   => (int) $r['id'],
        'name'                 => (string) $r['name'],
        'email'                => (string) $r['email'],
        'phone'                => (string) ($r['phone'] ?? ''),
        'role'                 => (string) $r['role'],
        'branch_id'            => $r['branch_id'] !== null ? (int) $r['branch_id'] : null,
        'branch_name'          => $r['branch_name'] ?? null,
        'pos_access'           => (int) $r['pos_access'],
        'must_change_password' => (int) $r['must_change_password'],
        'is_active'            => (int) $r['is_active'],
        // HR / profile
        'photo'                => $r['photo'] ?? null,
        'nid'                  => $r['nid'] ?? null,
        'dob'                  => $r['dob'] ?? null,
        'gender'               => $r['gender'] ?? null,
        'blood_group'          => $r['blood_group'] ?? null,
        'address'              => $r['address'] ?? null,
        'emergency_name'       => $r['emergency_name'] ?? null,
        'emergency_phone'      => $r['emergency_phone'] ?? null,
        'emergency_relation'   => $r['emergency_relation'] ?? null,
        'designation'          => $r['designation'] ?? null,
        'join_date'            => $r['join_date'] ?? null,
        'employment_type'      => $r['employment_type'] ?? null,
        'created_by'           => $r['created_by'] !== null ? (int) $r['created_by'] : null,
        'created_at'           => $r['created_at'] ?? null,
        'deleted_at'           => $r['deleted_at'] ?? null,
    ];
    if ($admin) {
        $out['salary'] = isset($r['salary']) && $r['salary'] !== null ? (float) $r['salary'] : null;
    }
    return $out;
}

function fetch_worker(int $id, bool $includeDeleted = false): ?array
{
    $sql = 'SELECT u.*, b.name AS branch_name
            FROM users u LEFT JOIN branches b ON b.id = u.branch_id
            WHERE u.id = ?';
    if (!$includeDeleted) {
        $sql .= ' AND u.deleted_at IS NULL';
    }
    $stmt = db()->prepare($sql . ' LIMIT 1');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

/** Generate a readable temp password (letters+digits, guaranteed mix). */
function gen_temp_password(): string
{
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz';
    $digit = '23456789';
    $out = $alpha[random_int(0, strlen($alpha) - 1)] . $digit[random_int(0, strlen($digit) - 1)];
    $pool = $alpha . $digit;
    for ($i = strlen($out); $i < TEMP_PW_LEN; $i++) {
        $out .= $pool[random_int(0, strlen($pool) - 1)];
    }
    return str_shuffle($out);
}

/* ============================================================ */

$action = query('action', '');
$method = method();

if ($method === 'GET' && $action === 'trash') {
    require_admin();
    $rows = db()->query(
        'SELECT u.*, b.name AS branch_name
         FROM users u LEFT JOIN branches b ON b.id = u.branch_id
         WHERE u.deleted_at IS NOT NULL ORDER BY u.deleted_at DESC'
    )->fetchAll();
    respond(array_map(fn ($r) => worker_public($r, true), $rows));
}

if ($method === 'POST' && $action === 'restore') {
    require_admin();
    $id = require_id(body('id'));
    db()->prepare('UPDATE users SET deleted_at = NULL, is_active = 1 WHERE id = ?')->execute([$id]);
    respond(['id' => $id]);
}

if ($method === 'POST' && $action === 'purge') {
    require_admin();
    $id = require_id(body('id'));
    $chk = db()->prepare('SELECT 1 FROM sales WHERE worker_id = ? LIMIT 1');
    $chk->execute([$id]);
    if ($chk->fetch()) {
        respond_error('Cannot purge: this member has sales history.', 409, 'has_history');
    }
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    respond(['id' => $id]);
}

if ($method === 'POST' && $action === 'reset_password') {
    $actor = require_manager();
    $id = require_id(body('id'));
    $target = fetch_worker($id);
    if (!$target) {
        respond_error('Member not found.', 404, 'not_found');
    }
    // Managers may only reset OWN-BRANCH workers (not other managers/admins).
    if (!is_admin($actor)) {
        if ((string) $target['role'] !== 'worker'
            || !can_access_branch($actor, (int) $target['branch_id'])) {
            respond_error('You do not have permission to do that.', 403, 'forbidden');
        }
    }
    if ((string) $target['role'] === 'super_admin') {
        respond_error('You do not have permission to do that.', 403, 'forbidden');
    }

    $temp = gen_temp_password();
    $hash = password_hash($temp, PASSWORD_BCRYPT, ['cost' => WORKER_BCRYPT_COST]);
    db()->prepare('UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?')
        ->execute([$hash, $id]);

    log_audit([
        'action_type' => 'auth', 'entity_type' => 'auth', 'entity_id' => $id,
        'branch_id' => $target['branch_id'] !== null ? (int) $target['branch_id'] : null,
        'changed_by' => $actor['id'], 'reason' => 'reset_password',
    ]);
    respond(['temp_password' => $temp]);
}

switch ($method) {

    case 'GET': {
        $actor = require_manager();
        $admin = is_admin($actor);
        if ($admin) {
            $rows = db()->query(
                'SELECT u.*, b.name AS branch_name
                 FROM users u LEFT JOIN branches b ON b.id = u.branch_id
                 WHERE u.deleted_at IS NULL ORDER BY u.role, u.name'
            )->fetchAll();
        } else {
            // Manager: own-branch members, excluding super_admins.
            $stmt = db()->prepare(
                'SELECT u.*, b.name AS branch_name
                 FROM users u LEFT JOIN branches b ON b.id = u.branch_id
                 WHERE u.deleted_at IS NULL AND u.branch_id = ? AND u.role <> "super_admin"
                 ORDER BY u.role, u.name'
            );
            $stmt->execute([acting_branch_id($actor)]);
            $rows = $stmt->fetchAll();
        }
        respond(array_map(fn ($r) => worker_public($r, $admin), $rows));
    }

    case 'POST': {
        $actor = require_admin();
        $name  = trim((string) body('name', ''));
        $email = strtolower(trim((string) body('email', '')));
        if ($name === '' || $email === '') {
            respond_error('Name and email are required.', 422, 'missing_field');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond_error('Please enter a valid email.', 422, 'bad_email');
        }
        $role = in_array(body('role'), ['manager', 'worker'], true) ? body('role') : 'worker';
        $phone = trim((string) body('phone', ''));
        $branchId = body('branch_id');
        $branchId = ($branchId !== null && $branchId !== '') ? (int) $branchId : null;
        $posAccess = (int) (bool) body('pos_access', 0);

        // Password: explicit, or generate a temp one with forced change.
        $explicit = (string) body('password', '');
        $mustChange = 0;
        if ($explicit !== '') {
            if (strlen($explicit) < 8) {
                respond_error('Password must be at least 8 characters.', 422, 'weak_password');
            }
            $temp = $explicit;
        } else {
            $temp = gen_temp_password();
            $mustChange = 1;
        }
        $hash = password_hash($temp, PASSWORD_BCRYPT, ['cost' => WORKER_BCRYPT_COST]);

        try {
            $ins = db()->prepare(
                'INSERT INTO users
                   (name, email, phone, password, role, branch_id, pos_access, must_change_password, is_active, created_by,
                    photo, nid, dob, gender, blood_group, address, emergency_name, emergency_phone, emergency_relation,
                    designation, join_date, employment_type, salary)
                 VALUES (?,?,?,?,?,?,?,?,1,?, ?,?,?,?,?,?,?,?,?, ?,?,?,?)'
            );
            $ins->execute([
                $name, $email, $phone !== '' ? $phone : null, $hash, $role, $branchId,
                $posAccess, $mustChange, $actor['id'],
                resolve_member_photo(body('photo')), nz(body('nid')), nz_date(body('dob')),
                enum_or_null(body('gender'), ['male', 'female', 'other']), nz(body('blood_group')), nz(body('address')),
                nz(body('emergency_name')), nz(body('emergency_phone')), nz(body('emergency_relation')),
                nz(body('designation')), nz_date(body('join_date')),
                enum_or_null(body('employment_type'), ['full_time', 'part_time', 'contract']), num_or_null(body('salary')),
            ]);
        } catch (\PDOException $e) {
            if ((int) $e->errorInfo[1] === 1062) {
                respond_error('That email or phone is already in use.', 409, 'duplicate');
            }
            throw $e;
        }
        $id = (int) db()->lastInsertId();

        log_audit([
            'action_type' => 'auth', 'entity_type' => 'auth', 'entity_id' => $id,
            'branch_id' => $branchId, 'changed_by' => $actor['id'], 'reason' => 'create_member',
        ]);

        $resp = worker_public(fetch_worker($id), true);
        // Surface the temp password once when we generated it.
        if ($mustChange === 1) {
            $resp['temp_password'] = $temp;
        }
        respond($resp, 201);
    }

    case 'PUT': {
        $actor = require_manager();
        $id = require_id(query('id'));
        $target = fetch_worker($id);
        if (!$target) {
            respond_error('Member not found.', 404, 'not_found');
        }
        if ((string) $target['role'] === 'super_admin') {
            respond_error('You do not have permission to do that.', 403, 'forbidden');
        }

        $b = request_body();
        $fields = [];
        $params = [];

        if (is_admin($actor)) {
            // Admin may edit identity, job, contact, role/branch, and salary (never super_admin).
            $map = [
                'name' => 'string', 'phone' => 'string', 'pos_access' => 'bool',
                'branch_id' => 'int', 'role' => 'role',
                'nid' => 'string', 'dob' => 'date', 'gender' => 'gender', 'blood_group' => 'string',
                'address' => 'string', 'emergency_name' => 'string', 'emergency_phone' => 'string',
                'emergency_relation' => 'string', 'designation' => 'string', 'join_date' => 'date',
                'employment_type' => 'emptype', 'salary' => 'num',
            ];
        } else {
            // Manager: own-branch workers only — basics + safety contact (never role/branch/NID/salary).
            if ((string) $target['role'] !== 'worker'
                || !can_access_branch($actor, (int) $target['branch_id'])) {
                respond_error('You do not have permission to do that.', 403, 'forbidden');
            }
            $map = [
                'name' => 'string', 'phone' => 'string', 'pos_access' => 'bool', 'address' => 'string',
                'emergency_name' => 'string', 'emergency_phone' => 'string', 'emergency_relation' => 'string',
            ];
        }

        foreach ($map as $key => $type) {
            if (!array_key_exists($key, $b)) {
                continue;
            }
            $val = $b[$key];
            switch ($type) {
                case 'bool':    $val = (int) (bool) $val; break;
                case 'int':     $val = ($val === null || $val === '') ? null : (int) $val; break;
                case 'role':    $val = in_array($val, ['manager', 'worker'], true) ? $val : $target['role']; break;
                case 'date':    $val = nz_date($val); break;
                case 'gender':  $val = enum_or_null($val, ['male', 'female', 'other']); break;
                case 'emptype': $val = enum_or_null($val, ['full_time', 'part_time', 'contract']); break;
                case 'num':     $val = num_or_null($val); break;
                default:
                    $val = is_string($val) ? trim($val) : $val;
                    if ($val === '' && $key !== 'name') {
                        $val = null;
                    }
            }
            $fields[] = "$key = ?";
            $params[] = $val;
        }
        // Photo (base64 → saved path, existing path kept, null clears). Allowed for admin + manager.
        if (array_key_exists('photo', $b)) {
            $fields[] = 'photo = ?';
            $params[] = resolve_member_photo($b['photo']);
        }
        if ($fields === []) {
            respond(worker_public($target, is_admin($actor)));
        }

        $params[] = $id;
        try {
            db()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        } catch (\PDOException $e) {
            if ((int) $e->errorInfo[1] === 1062) {
                respond_error('That phone is already in use.', 409, 'duplicate');
            }
            throw $e;
        }

        log_audit([
            'action_type' => 'auth', 'entity_type' => 'auth', 'entity_id' => $id,
            'branch_id' => $target['branch_id'] !== null ? (int) $target['branch_id'] : null,
            'changed_by' => $actor['id'], 'reason' => 'update_member',
        ]);
        respond(worker_public(fetch_worker($id), is_admin($actor)));
    }

    case 'DELETE': {
        $actor = require_admin();
        $id = require_id(query('id'));
        if ($id === $actor['id']) {
            respond_error('You cannot remove your own account.', 409, 'self_delete');
        }
        $target = fetch_worker($id);
        if (!$target) {
            respond_error('Member not found.', 404, 'not_found');
        }
        if ((string) $target['role'] === 'super_admin') {
            respond_error('A super admin cannot be removed here.', 409, 'admin_protected');
        }
        db()->prepare('UPDATE users SET deleted_at = NOW(), is_active = 0 WHERE id = ?')->execute([$id]);
        log_audit([
            'action_type' => 'auth', 'entity_type' => 'auth', 'entity_id' => $id,
            'branch_id' => $target['branch_id'] !== null ? (int) $target['branch_id'] : null,
            'changed_by' => $actor['id'], 'reason' => 'soft_delete_member',
        ]);
        respond(['id' => $id]);
    }

    default:
        respond_error('Method not allowed.', 405);
}

/** Manager's own branch id (must exist). */
function acting_branch_id(array $user): int
{
    if ($user['branch_id'] === null) {
        respond_error('Your account is not assigned to a branch.', 409, 'no_branch');
    }
    return (int) $user['branch_id'];
}

/* ---- HR field coercion helpers ---- */

/** Trimmed string, or null when empty. */
function nz(mixed $v): ?string
{
    $s = is_string($v) ? trim($v) : ($v === null ? '' : (string) $v);
    return $s !== '' ? $s : null;
}

/** A YYYY-MM-DD date string, or null. */
function nz_date(mixed $v): ?string
{
    $s = is_string($v) ? trim($v) : '';
    return $s !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}

/** One of $allowed, or null. */
function enum_or_null(mixed $v, array $allowed): ?string
{
    return in_array($v, $allowed, true) ? (string) $v : null;
}

/** A float, or null when blank. */
function num_or_null(mixed $v): ?float
{
    return $v === null || $v === '' ? null : (float) $v;
}

/**
 * Resolve a member photo from the body: base64 data URL → saved path;
 * an existing `uploads/...` path is kept; null/blank clears it.
 */
function resolve_member_photo(mixed $v): ?string
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
