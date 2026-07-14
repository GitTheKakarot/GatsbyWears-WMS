<?php
declare(strict_types=1);

// Defense-in-depth: lib files load only via config.php (which defines WMS_ROOT).
if (PHP_SAPI !== 'cli' && !defined('WMS_ROOT')) { http_response_code(403); exit; }

/**
 * GatsbyWears WMS 2.0 — authentication & authorization guards.
 *
 * Usage in endpoints (return value ALWAYS assigned — owner's hard rule):
 *   $user = require_auth();          // any logged-in, active user
 *   $user = require_manager();       // manager or super_admin
 *   $user = require_admin();         // super_admin only
 *
 * Each returns the fresh DB user row (authoritative — never trust JWT claims
 * for role/branch). On failure they emit a JSON error and exit.
 *
 * Depends on: db.php, jwt.php, respond.php (loaded by config.php first).
 */

/**
 * Pull the raw Bearer token from the Authorization header.
 * Handles Apache stripping it (REDIRECT_HTTP_AUTHORIZATION) and getallheaders().
 */
function bearer_token(): ?string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if ($auth === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $auth = $v;
                break;
            }
        }
    }

    if ($auth === '' || !preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m)) {
        return null;
    }
    return trim($m[1]);
}

/**
 * Require any authenticated, active, non-deleted user.
 * Returns the authoritative DB user row.
 */
function require_auth(): array
{
    $token = bearer_token();
    if ($token === null) {
        respond_error('Authentication required.', 401, 'no_token');
    }

    $claims = jwt_verify($token);
    if ($claims === null || !isset($claims['sub'])) {
        respond_error('Session expired. Please sign in again.', 401, 'bad_token');
    }

    $stmt = db()->prepare(
        'SELECT id, name, email, phone, role, branch_id, pos_access,
                must_change_password, is_active, created_at, updated_at
         FROM users
         WHERE id = ? AND deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([(int) $claims['sub']]);
    $user = $stmt->fetch();

    // Inactive / deleted / vanished users are rejected at verify time.
    if (!$user || (int) $user['is_active'] !== 1) {
        respond_error('Session expired. Please sign in again.', 401, 'inactive');
    }

    // Normalize types for downstream consumers + the wire (User shape).
    $user['id']                   = (int) $user['id'];
    $user['branch_id']            = $user['branch_id'] !== null ? (int) $user['branch_id'] : null;
    $user['pos_access']           = (int) $user['pos_access'];
    $user['must_change_password'] = (int) $user['must_change_password'];
    $user['is_active']            = (int) $user['is_active'];

    return $user;
}

/**
 * Require a manager or super_admin.
 */
function require_manager(): array
{
    $user = require_auth();
    if (!in_array($user['role'], ['manager', 'super_admin'], true)) {
        respond_error('You do not have permission to do that.', 403, 'forbidden');
    }
    return $user;
}

/**
 * Require a super_admin.
 */
function require_admin(): array
{
    $user = require_auth();
    if ($user['role'] !== 'super_admin') {
        respond_error('You do not have permission to do that.', 403, 'forbidden');
    }
    return $user;
}

/* ============================================================
   Branch-scope / IDOR helpers
   ============================================================ */

function is_admin(array $user): bool
{
    return $user['role'] === 'super_admin';
}

function is_manager(array $user): bool
{
    return $user['role'] === 'manager';
}

/**
 * True if the user may act on objects belonging to $branchId.
 * super_admin → any branch; everyone else → only their own branch.
 */
function can_access_branch(array $user, ?int $branchId): bool
{
    if (is_admin($user)) {
        return true;
    }
    if ($branchId === null || $user['branch_id'] === null) {
        return false;
    }
    return (int) $user['branch_id'] === (int) $branchId;
}

/**
 * Enforce branch ownership for non-admins, or emit 403 + exit.
 * Use on every object access that carries a branch_id (IDOR defense).
 */
function require_branch_access(array $user, ?int $branchId): void
{
    if (!can_access_branch($user, $branchId)) {
        respond_error('You do not have permission to do that.', 403, 'branch_scope');
    }
}

/**
 * The branch a non-admin is locked to (their own); admins get null = all.
 * Handy for scoping list queries.
 */
function scope_branch_id(array $user): ?int
{
    return is_admin($user) ? null : $user['branch_id'];
}
