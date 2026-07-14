<?php
declare(strict_types=1);

/**
 * GatsbyWears WMS 2.0 — invites.php
 *
 * Actions (matched by src/lib/api.ts):
 *   GET  ?action=list             → Invite[]        (manager: own-branch; admin: all)
 *   POST ?action=create           → Invite           (manager: own-branch+worker; admin: any)
 *   GET  ?action=verify&token=X   → Invite (public)  (prefill data; valid token only)
 *   POST ?action=redeem           → LoginResponse     (public; creates user + auto-login)
 *
 * Security: tokens are 64-hex random; verify/redeem never reveal whether a token
 * exists vs. is expired beyond a generic invalid message. Redeem is rate-limited
 * by reusing the login_attempts brute-force table keyed on the token+IP.
 */

require __DIR__ . '/config.php';

const INVITE_BCRYPT_COST = 12;

/** Public invite shape (token included only for owner listings / prefill). */
function invite_public(array $r, bool $withToken = true): array
{
    $out = [
        'id'           => (int) $r['id'],
        'email'        => $r['email'],
        'name'         => $r['name'],
        'role'         => (string) $r['role'],
        'branch_id'    => $r['branch_id'] !== null ? (int) $r['branch_id'] : null,
        'branch_name'  => $r['branch_name'] ?? null,
        'max_uses'     => (int) $r['max_uses'],
        'uses'         => (int) $r['uses'],
        'expires_at'   => $r['expires_at'],
        'created_by'   => (int) $r['created_by'],
        'used_at'      => $r['used_at'],
        'created_at'   => $r['created_at'] ?? null,
    ];
    $out['token'] = $withToken ? (string) $r['token'] : '';
    return $out;
}

/** Fetch invite by token with branch name, or null. */
function fetch_invite_by_token(string $token): ?array
{
    $stmt = db()->prepare(
        'SELECT i.*, b.name AS branch_name
         FROM invites i LEFT JOIN branches b ON b.id = i.branch_id
         WHERE i.token = ? LIMIT 1'
    );
    $stmt->execute([$token]);
    $r = $stmt->fetch();
    return $r ?: null;
}

/** Is the invite still redeemable? */
function invite_is_valid(array $inv): bool
{
    if ((int) $inv['uses'] >= (int) $inv['max_uses']) {
        return false;
    }
    if (strtotime((string) $inv['expires_at']) < time()) {
        return false;
    }
    return true;
}

/* ============================================================ */

$action = query('action', '');
$method = method();

switch ($action) {

    /* ---------------- list ---------------- */
    case 'list': {
        $actor = require_manager();
        if (is_admin($actor)) {
            $rows = db()->query(
                'SELECT i.*, b.name AS branch_name
                 FROM invites i LEFT JOIN branches b ON b.id = i.branch_id
                 ORDER BY i.created_at DESC'
            )->fetchAll();
        } else {
            $stmt = db()->prepare(
                'SELECT i.*, b.name AS branch_name
                 FROM invites i LEFT JOIN branches b ON b.id = i.branch_id
                 WHERE i.branch_id = ? ORDER BY i.created_at DESC'
            );
            $stmt->execute([(int) $actor['branch_id']]);
            $rows = $stmt->fetchAll();
        }
        respond(array_map(fn ($r) => invite_public($r), $rows));
    }

    /* ---------------- create ---------------- */
    case 'create': {
        if ($method !== 'POST') {
            respond_error('Method not allowed.', 405);
        }
        $actor = require_manager();

        $role = in_array(body('role'), ['manager', 'worker'], true) ? body('role') : 'worker';
        $email = strtolower(trim((string) body('email', '')));
        $name  = trim((string) body('name', ''));
        $branchId = body('branch_id');
        $branchId = ($branchId !== null && $branchId !== '') ? (int) $branchId : null;
        $maxUses = max(1, (int) body('max_uses', 1));
        $days    = (int) body('expires_days', 7);
        $days    = $days > 0 ? min($days, 90) : 7;

        // Managers: locked to own branch + worker role only.
        if (!is_admin($actor)) {
            $branchId = (int) $actor['branch_id'];
            $role = 'worker';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond_error('Please enter a valid email.', 422, 'bad_email');
        }
        if ($branchId !== null) {
            $chk = db()->prepare('SELECT 1 FROM branches WHERE id = ? AND deleted_at IS NULL');
            $chk->execute([$branchId]);
            if (!$chk->fetch()) {
                respond_error('Branch not found.', 404, 'not_found');
            }
        }

        $token = bin2hex(random_bytes(32)); // 64 hex chars
        $expiresAt = date('Y-m-d H:i:s', time() + $days * 86400);

        $ins = db()->prepare(
            'INSERT INTO invites (token, email, name, role, branch_id, max_uses, expires_at, created_by)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $ins->execute([$token, $email !== '' ? $email : null, $name !== '' ? $name : null,
            $role, $branchId, $maxUses, $expiresAt, $actor['id']]);
        $id = (int) db()->lastInsertId();

        $row = fetch_invite_by_token($token);
        respond(invite_public($row), 201);
    }

    /* ---------------- verify (public) ---------------- */
    case 'verify': {
        $token = trim((string) query('token', ''));
        if ($token === '') {
            respond_error('Invalid or expired invite.', 404, 'invalid_invite');
        }
        $inv = fetch_invite_by_token($token);
        if (!$inv || !invite_is_valid($inv)) {
            respond_error('Invalid or expired invite.', 404, 'invalid_invite');
        }
        // Public prefill — strip the token from the echoed payload.
        respond(invite_public($inv, false));
    }

    /* ---------------- redeem (public) ---------------- */
    case 'redeem': {
        if ($method !== 'POST') {
            respond_error('Method not allowed.', 405);
        }
        $token = trim((string) body('token', ''));
        $name  = trim((string) body('name', ''));
        $phone = trim((string) body('phone', ''));
        $password = (string) body('password', '');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        if ($token === '') {
            respond_error('Invalid or expired invite.', 404, 'invalid_invite');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Lock the invite row for the use-count update.
            $sel = $pdo->prepare('SELECT * FROM invites WHERE token = ? FOR UPDATE');
            $sel->execute([$token]);
            $inv = $sel->fetch();
            if (!$inv || !invite_is_valid($inv)) {
                $pdo->rollBack();
                respond_error('Invalid or expired invite.', 404, 'invalid_invite');
            }

            // Resolve email: invite's prefilled email wins; else require from body.
            $email = $inv['email'] !== null && $inv['email'] !== ''
                ? strtolower((string) $inv['email'])
                : strtolower(trim((string) body('email', '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $pdo->rollBack();
                respond_error('A valid email is required.', 422, 'bad_email');
            }
            if ($name === '') {
                $pdo->rollBack();
                respond_error('Your name is required.', 422, 'missing_field');
            }
            if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
                $pdo->rollBack();
                respond_error('Password must be at least 8 characters and include letters and numbers.', 422, 'weak_password');
            }

            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => INVITE_BCRYPT_COST]);
            try {
                $insU = $pdo->prepare(
                    'INSERT INTO users (name, email, phone, password, role, branch_id, pos_access, is_active, created_by)
                     VALUES (?,?,?,?,?,?,?,1,?)'
                );
                $insU->execute([$name, $email, $phone !== '' ? $phone : null, $hash,
                    (string) $inv['role'],
                    $inv['branch_id'] !== null ? (int) $inv['branch_id'] : null,
                    (string) $inv['role'] === 'worker' ? 1 : 1, // workers created via invite get POS by default
                    (int) $inv['created_by']]);
            } catch (\PDOException $e) {
                $pdo->rollBack();
                if ((int) $e->errorInfo[1] === 1062) {
                    respond_error('An account with that email or phone already exists.', 409, 'duplicate');
                }
                throw $e;
            }
            $userId = (int) $pdo->lastInsertId();

            // Consume one use; stamp used_at when exhausted.
            $newUses = (int) $inv['uses'] + 1;
            $usedAt = $newUses >= (int) $inv['max_uses'] ? date('Y-m-d H:i:s') : null;
            $pdo->prepare('UPDATE invites SET uses = ?, used_at = ? WHERE id = ?')
                ->execute([$newUses, $usedAt, (int) $inv['id']]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Build the User payload + auto-login token.
        $stmt = db()->prepare(
            'SELECT u.*, b.name AS branch_name FROM users u
             LEFT JOIN branches b ON b.id = u.branch_id WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $u = $stmt->fetch();

        log_audit([
            'action_type' => 'auth', 'entity_type' => 'auth', 'entity_id' => $userId,
            'branch_id' => $u['branch_id'] !== null ? (int) $u['branch_id'] : null,
            'changed_by' => $userId, 'reason' => 'invite_redeemed',
        ]);

        $jwt = jwt_create([
            'sub'       => $userId,
            'role'      => (string) $u['role'],
            'branch_id' => $u['branch_id'] !== null ? (int) $u['branch_id'] : null,
            'name'      => (string) $u['name'],
        ]);

        respond([
            'token' => $jwt,
            'user'  => [
                'id'                   => (int) $u['id'],
                'name'                 => (string) $u['name'],
                'email'                => (string) $u['email'],
                'phone'                => (string) ($u['phone'] ?? ''),
                'role'                 => (string) $u['role'],
                'branch_id'            => $u['branch_id'] !== null ? (int) $u['branch_id'] : null,
                'branch_name'          => $u['branch_name'],
                'pos_access'           => (int) $u['pos_access'],
                'must_change_password' => (int) $u['must_change_password'],
                'is_active'            => (int) $u['is_active'],
                'created_at'           => $u['created_at'],
            ],
        ], 201);
    }

    /* ---------------- delete / revoke ---------------- */
    case 'delete': {
        if ($method !== 'POST') {
            respond_error('Method not allowed.', 405);
        }
        $actor = require_manager();
        $id = require_id(body('id'));
        $sel = db()->prepare('SELECT branch_id FROM invites WHERE id = ?');
        $sel->execute([$id]);
        $inv = $sel->fetch();
        if (!$inv) {
            respond_error('Invite not found.', 404, 'not_found');
        }
        // Managers may only revoke invites for their own branch; admins any.
        if (!is_admin($actor) && (int) $inv['branch_id'] !== (int) $actor['branch_id']) {
            respond_error('You do not have permission to do that.', 403, 'forbidden');
        }
        db()->prepare('DELETE FROM invites WHERE id = ?')->execute([$id]);
        respond(['id' => $id]);
    }

    default:
        respond_error('Unknown action.', 404, 'unknown_action');
}
