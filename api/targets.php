<?php
declare(strict_types=1);

/**
 * GatsbyWears WMS 2.0 — targets.php (performance goals)
 *
 * Actions (matched by src/lib/api.ts):
 *   GET  ?action=list[&user_id&month&year]  → PerformanceTarget[]  (manager own-branch; admin any)
 *   POST ?action=set                         → PerformanceTarget     (upsert by user+month+year+type)
 *   POST ?action=delete {id}                 → { id }
 *
 * Scope: managers may only set/list/delete targets for OWN-BRANCH users; admins any.
 */

require __DIR__ . '/config.php';

function target_public(array $r): array
{
    return [
        'id'            => (int) $r['id'],
        'user_id'       => (int) $r['user_id'],
        'user_name'     => $r['user_name'] ?? null,
        'branch_id'     => (int) $r['branch_id'],
        'month'         => (int) $r['month'],
        'year'          => (int) $r['year'],
        'target_amount' => $r['target_amount'] !== null ? (float) $r['target_amount'] : null,
        'target_units'  => $r['target_units'] !== null ? (int) $r['target_units'] : null,
        'target_type'   => (string) $r['target_type'],
    ];
}

/** Load a user (id, branch_id) the actor is allowed to manage, or error. */
function target_assert_user(array $actor, int $userId): array
{
    $stmt = db()->prepare('SELECT id, branch_id FROM users WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    if (!$u) {
        respond_error('User not found.', 404, 'not_found');
    }
    if (!can_access_branch($actor, $u['branch_id'] !== null ? (int) $u['branch_id'] : null)) {
        respond_error('You do not have permission to do that.', 403, 'forbidden');
    }
    return $u;
}

/* ============================================================ */

$actor = require_manager();
$action = query('action', '');
$method = method();

switch ($action) {

    case 'list': {
        $where = [];
        $params = [];
        // Branch scope.
        if (!is_admin($actor)) {
            $where[] = 'u.branch_id = ?';
            $params[] = (int) $actor['branch_id'];
        }
        if (($uid = query('user_id')) !== null && $uid !== '') {
            $where[] = 't.user_id = ?';
            $params[] = (int) $uid;
        }
        if (($m = query('month')) !== null && $m !== '') {
            $where[] = 't.month = ?';
            $params[] = (int) $m;
        }
        if (($y = query('year')) !== null && $y !== '') {
            $where[] = 't.year = ?';
            $params[] = (int) $y;
        }
        $sql = 'SELECT t.*, u.name AS user_name
                FROM performance_targets t JOIN users u ON u.id = t.user_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY t.year DESC, t.month DESC, u.name ASC';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        respond(array_map('target_public', $stmt->fetchAll()));
    }

    case 'set': {
        if ($method !== 'POST') {
            respond_error('Method not allowed.', 405);
        }
        $userId = require_id(body('user_id'), 'user_id');
        $u = target_assert_user($actor, $userId);

        $month = (int) body('month', (int) date('n'));
        $year  = (int) body('year', (int) date('Y'));
        if ($month < 1 || $month > 12) {
            respond_error('Invalid month.', 422, 'bad_month');
        }
        $type = in_array(body('target_type'), ['daily', 'monthly'], true) ? body('target_type') : 'monthly';

        $amount = body('target_amount');
        $units  = body('target_units');
        $amount = ($amount === null || $amount === '') ? null : (float) $amount;
        $units  = ($units === null || $units === '') ? null : (int) $units;
        if ($amount === null && $units === null) {
            respond_error('Set at least a target amount or units.', 422, 'empty_target');
        }

        $branchId = $u['branch_id'] !== null ? (int) $u['branch_id'] : (int) $actor['branch_id'];

        // Upsert by the unique (user_id, month, year, target_type).
        $ins = db()->prepare(
            'INSERT INTO performance_targets (user_id, branch_id, month, year, target_amount, target_units, target_type)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                branch_id = VALUES(branch_id),
                target_amount = VALUES(target_amount),
                target_units = VALUES(target_units)'
        );
        $ins->execute([$userId, $branchId, $month, $year, $amount, $units, $type]);

        $sel = db()->prepare(
            'SELECT t.*, u.name AS user_name FROM performance_targets t JOIN users u ON u.id = t.user_id
             WHERE t.user_id = ? AND t.month = ? AND t.year = ? AND t.target_type = ? LIMIT 1'
        );
        $sel->execute([$userId, $month, $year, $type]);
        respond(target_public($sel->fetch()));
    }

    case 'delete': {
        if ($method !== 'POST') {
            respond_error('Method not allowed.', 405);
        }
        $id = require_id(body('id'));
        // Scope check: load the target's user branch.
        $sel = db()->prepare(
            'SELECT t.id, u.branch_id FROM performance_targets t JOIN users u ON u.id = t.user_id WHERE t.id = ?'
        );
        $sel->execute([$id]);
        $row = $sel->fetch();
        if (!$row) {
            respond_error('Target not found.', 404, 'not_found');
        }
        if (!can_access_branch($actor, $row['branch_id'] !== null ? (int) $row['branch_id'] : null)) {
            respond_error('You do not have permission to do that.', 403, 'forbidden');
        }
        db()->prepare('DELETE FROM performance_targets WHERE id = ?')->execute([$id]);
        respond(['id' => $id]);
    }

    default:
        respond_error('Unknown action.', 404, 'unknown_action');
}
