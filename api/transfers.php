<?php
declare(strict_types=1);

/**
 * GatsbyWears WMS 2.0 — transfers.php
 *
 * Actions (matched by src/lib/api.ts):
 *   GET  (no action)   list          → Transfer[]  (status/branch filters; branch-scoped for non-admin)
 *   POST (no action)   request       → Transfer     (any auth — workers may request)
 *   PUT  ?id=N {status} updateStatus → Transfer     (manager+; own source branch)
 *
 * State machine:
 *   pending  → approved | rejected
 *   approved → delivered | rejected
 *   delivered/rejected → (terminal)
 *
 * deliver performs the atomic stock move (source -= qty, dest += qty) under
 * FOR UPDATE locks, with transfer_deduct + transfer_add audit rows.
 */

require __DIR__ . '/config.php';

/**
 * Assemble Transfer shapes for the given ids (preserves DB order via caller).
 * @return array<int,array>
 */
function build_transfers(array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $place = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT t.*, pv.size AS size, pv.product_id AS product_id,
                   p.name AS product_name,
                   bf.name AS from_branch_name, bt.name AS to_branch_name,
                   u.name AS requested_by_name
            FROM transfers t
            JOIN product_variants pv ON pv.id = t.variant_id
            JOIN products p          ON p.id = pv.product_id
            JOIN branches bf         ON bf.id = t.from_branch_id
            JOIN branches bt         ON bt.id = t.to_branch_id
            JOIN users u             ON u.id = t.requested_by
            WHERE t.id IN ($place)";
    $stmt = db()->prepare($sql);
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll() as $t) {
        $out[(int) $t['id']] = [
            'id'                => (int) $t['id'],
            'from_branch_id'    => (int) $t['from_branch_id'],
            'to_branch_id'      => (int) $t['to_branch_id'],
            'from_branch_name'  => (string) $t['from_branch_name'],
            'to_branch_name'    => (string) $t['to_branch_name'],
            'variant_id'        => (int) $t['variant_id'],
            'product_id'        => (int) $t['product_id'],
            'product_name'      => (string) $t['product_name'],
            'size'              => (string) $t['size'],
            'qty'               => (int) $t['qty'],
            'status'            => (string) $t['status'],
            'requested_by'      => (int) $t['requested_by'],
            'requested_by_name' => (string) $t['requested_by_name'],
            'notes'             => $t['notes'],
            'created_at'        => $t['created_at'],
            'updated_at'        => $t['updated_at'],
        ];
    }
    return $out;
}

function build_transfer(int $id): ?array
{
    $m = build_transfers([$id]);
    return $m[$id] ?? null;
}

/* ============================================================ */

$method = method();

switch ($method) {

    case 'GET': {
        $user = require_auth();
        $where = [];
        $params = [];

        $status = trim((string) query('status', ''));
        if (in_array($status, ['pending', 'approved', 'delivered', 'rejected'], true)) {
            $where[] = 't.status = ?';
            $params[] = $status;
        }

        // Branch scope: non-admins see only transfers touching their branch.
        $scope = scope_branch_id($user);
        if ($scope !== null) {
            $where[] = '(t.from_branch_id = ? OR t.to_branch_id = ?)';
            $params[] = $scope;
            $params[] = $scope;
        } elseif (($bid = query('branch_id')) !== null && $bid !== '') {
            // Admin may optionally filter by a branch.
            $where[] = '(t.from_branch_id = ? OR t.to_branch_id = ?)';
            $params[] = (int) $bid;
            $params[] = (int) $bid;
        }

        $sql = 'SELECT id FROM transfers t';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY t.created_at DESC, t.id DESC';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $ids = array_map(fn ($r) => (int) $r['id'], $stmt->fetchAll());
        $map = build_transfers($ids);
        respond(array_map(fn ($id) => $map[$id], array_filter($ids, fn ($id) => isset($map[$id]))));
    }

    case 'POST': {
        $user = require_auth(); // workers may request
        $fromBranch = require_id(body('from_branch_id'), 'from_branch_id');
        $toBranch   = require_id(body('to_branch_id'), 'to_branch_id');
        $variantId  = require_id(body('variant_id'), 'variant_id');
        $qty        = (int) body('qty', 0);
        $notes      = trim((string) body('notes', ''));

        if ($qty <= 0) {
            respond_error('Quantity must be greater than zero.', 422, 'bad_qty');
        }
        if ($fromBranch === $toBranch) {
            respond_error('Source and destination must differ.', 422, 'same_branch');
        }

        // Validate branches + variant exist.
        $chk = db()->prepare('SELECT 1 FROM branches WHERE id = ? AND deleted_at IS NULL');
        $chk->execute([$fromBranch]);
        if (!$chk->fetch()) {
            respond_error('Source branch not found.', 404, 'not_found');
        }
        $chk->execute([$toBranch]);
        if (!$chk->fetch()) {
            respond_error('Destination branch not found.', 404, 'not_found');
        }
        $v = db()->prepare('SELECT 1 FROM product_variants WHERE id = ?');
        $v->execute([$variantId]);
        if (!$v->fetch()) {
            respond_error('Variant not found.', 404, 'not_found');
        }

        $ins = db()->prepare(
            'INSERT INTO transfers (from_branch_id, to_branch_id, variant_id, qty, status, requested_by, notes)
             VALUES (?,?,?,?,?,?,?)'
        );
        $ins->execute([$fromBranch, $toBranch, $variantId, $qty, 'pending', $user['id'],
            $notes !== '' ? $notes : null]);
        $id = (int) db()->lastInsertId();

        log_audit([
            'action_type' => 'manual_edit', 'entity_type' => 'transfer',
            'entity_id' => $id, 'branch_id' => $fromBranch, 'changed_by' => $user['id'],
            'reason' => 'request_transfer',
            'metadata' => ['to_branch_id' => $toBranch, 'variant_id' => $variantId, 'qty' => $qty],
        ]);
        respond(build_transfer($id), 201);
    }

    case 'PUT': {
        $user = require_manager();
        $id = require_id(query('id'));
        $next = (string) body('status', '');
        if (!in_array($next, ['approved', 'delivered', 'rejected'], true)) {
            respond_error('Invalid status.', 422, 'bad_status');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Lock the transfer row.
            $sel = $pdo->prepare('SELECT * FROM transfers WHERE id = ? FOR UPDATE');
            $sel->execute([$id]);
            $t = $sel->fetch();
            if (!$t) {
                $pdo->rollBack();
                respond_error('Transfer not found.', 404, 'not_found');
            }

            // Branch scope: manager must own the SOURCE branch (the one giving stock).
            if (!can_access_branch($user, (int) $t['from_branch_id'])) {
                $pdo->rollBack();
                respond_error('You do not have permission to do that.', 403, 'branch_scope');
            }

            $curStatus = (string) $t['status'];
            $valid = [
                'pending'  => ['approved', 'rejected'],
                'approved' => ['delivered', 'rejected'],
            ];
            if (!isset($valid[$curStatus]) || !in_array($next, $valid[$curStatus], true)) {
                $pdo->rollBack();
                respond_error("Cannot change a {$curStatus} transfer to {$next}.", 409, 'bad_transition');
            }

            $variantId = (int) $t['variant_id'];
            $fromB = (int) $t['from_branch_id'];
            $toB   = (int) $t['to_branch_id'];
            $qty   = (int) $t['qty'];

            if ($next === 'delivered') {
                // Atomic stock move under locks.
                $cur = $pdo->prepare('SELECT qty FROM branch_stock WHERE branch_id = ? AND variant_id = ? FOR UPDATE');
                $cur->execute([$fromB, $variantId]);
                $fromRow = $cur->fetch();
                $fromQty = $fromRow ? (int) $fromRow['qty'] : 0;
                if ($fromQty < $qty) {
                    $pdo->rollBack();
                    respond_error('Not enough stock at the source branch.', 409, 'insufficient_stock');
                }
                $cur->execute([$toB, $variantId]);
                $toRow = $cur->fetch();
                $toQty = $toRow ? (int) $toRow['qty'] : 0;

                $newFrom = $fromQty - $qty;
                $newTo   = $toQty + $qty;

                $pdo->prepare('UPDATE branch_stock SET qty = ? WHERE branch_id = ? AND variant_id = ?')
                    ->execute([$newFrom, $fromB, $variantId]);
                $pdo->prepare(
                    'INSERT INTO branch_stock (branch_id, variant_id, qty) VALUES (?,?,?)
                     ON DUPLICATE KEY UPDATE qty = VALUES(qty)'
                )->execute([$toB, $variantId, $newTo]);

                $pdo->prepare('UPDATE transfers SET status = ? WHERE id = ?')->execute([$next, $id]);
                $pdo->commit();

                $productId = (int) ($pdo->query('SELECT product_id FROM product_variants WHERE id = ' . $variantId)->fetch()['product_id'] ?? 0);
                log_audit([
                    'action_type' => 'transfer_deduct', 'entity_type' => 'branch_stock',
                    'entity_id' => $variantId, 'branch_id' => $fromB, 'product_id' => $productId,
                    'old_qty' => $fromQty, 'new_qty' => $newFrom, 'changed_by' => $user['id'],
                    'reason' => 'transfer_delivered', 'metadata' => ['transfer_id' => $id],
                ]);
                log_audit([
                    'action_type' => 'transfer_add', 'entity_type' => 'branch_stock',
                    'entity_id' => $variantId, 'branch_id' => $toB, 'product_id' => $productId,
                    'old_qty' => $toQty, 'new_qty' => $newTo, 'changed_by' => $user['id'],
                    'reason' => 'transfer_delivered', 'metadata' => ['transfer_id' => $id],
                ]);
            } else {
                $pdo->prepare('UPDATE transfers SET status = ? WHERE id = ?')->execute([$next, $id]);
                $pdo->commit();
                log_audit([
                    'action_type' => 'manual_edit', 'entity_type' => 'transfer',
                    'entity_id' => $id, 'branch_id' => $fromB, 'changed_by' => $user['id'],
                    'reason' => 'transfer_' . $next,
                ]);
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        respond(build_transfer($id));
    }

    default:
        respond_error('Method not allowed.', 405);
}
