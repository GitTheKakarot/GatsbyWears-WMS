<?php
declare(strict_types=1);

/**
 * GatsbyWears WMS 2.0 — audit.php (read-only trail viewer)
 *
 * Action (matched by src/lib/api.ts audit.list):
 *   GET ?[action_type][&entity_type][&product_id][&days][&limit]  → AuditLog[]
 *
 * Scope: managers see only their own branch's logs; admins see all (with an
 * optional ?branch_id filter). metadata is decoded to an object.
 */

require __DIR__ . '/config.php';

$user = require_manager();

$where = [];
$params = [];

// Branch scope.
if (!is_admin($user)) {
    if ($user['branch_id'] === null) {
        respond_error('Your account is not assigned to a branch.', 409, 'no_branch');
    }
    $where[] = 'al.branch_id = ?';
    $params[] = (int) $user['branch_id'];
} elseif (($b = query('branch_id')) !== null && $b !== '') {
    $where[] = 'al.branch_id = ?';
    $params[] = (int) $b;
}

// Filters.
if (($at = query('action_type')) !== null && $at !== ''
    && in_array($at, ['manual_edit', 'pos_sale', 'transfer_deduct', 'transfer_add', 'adjustment', 'auth'], true)) {
    $where[] = 'al.action_type = ?';
    $params[] = $at;
}
if (($et = query('entity_type')) !== null && $et !== ''
    && in_array($et, ['branch_stock', 'inventory', 'transfer', 'sales', 'auth'], true)) {
    $where[] = 'al.entity_type = ?';
    $params[] = $et;
}
if (($pid = query('product_id')) !== null && $pid !== '') {
    $where[] = 'al.product_id = ?';
    $params[] = (int) $pid;
}
$days = (int) query('days', '30');
if ($days > 0) {
    $where[] = 'al.created_at >= ?';
    $params[] = date('Y-m-d H:i:s', time() - min($days, 365) * 86400);
}

$limit = max(1, min(1000, (int) query('limit', '200')));

$sql = 'SELECT al.*, b.name AS branch_name, p.name AS product_name, u.name AS changed_by_name
        FROM audit_logs al
        LEFT JOIN branches b ON b.id = al.branch_id
        LEFT JOIN products p ON p.id = al.product_id
        LEFT JOIN users u    ON u.id = al.changed_by';
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= " ORDER BY al.created_at DESC, al.id DESC LIMIT $limit";

$stmt = db()->prepare($sql);
$stmt->execute($params);

$out = [];
foreach ($stmt->fetchAll() as $r) {
    $meta = null;
    if ($r['metadata'] !== null && $r['metadata'] !== '') {
        $decoded = json_decode((string) $r['metadata'], true);
        $meta = is_array($decoded) ? $decoded : null;
    }
    $out[] = [
        'id'              => (int) $r['id'],
        'action_type'     => (string) $r['action_type'],
        'entity_type'     => (string) $r['entity_type'],
        'entity_id'       => $r['entity_id'] !== null ? (int) $r['entity_id'] : null,
        'branch_id'       => $r['branch_id'] !== null ? (int) $r['branch_id'] : null,
        'branch_name'     => $r['branch_name'],
        'product_id'      => $r['product_id'] !== null ? (int) $r['product_id'] : null,
        'product_name'    => $r['product_name'],
        'old_qty'         => $r['old_qty'] !== null ? (int) $r['old_qty'] : null,
        'new_qty'         => $r['new_qty'] !== null ? (int) $r['new_qty'] : null,
        'changed_by'      => $r['changed_by'] !== null ? (int) $r['changed_by'] : null,
        'changed_by_name' => $r['changed_by_name'],
        'reason'          => $r['reason'],
        'metadata'        => $meta,
        'created_at'      => $r['created_at'],
    ];
}
respond($out);
