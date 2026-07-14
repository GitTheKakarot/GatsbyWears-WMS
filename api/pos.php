<?php
declare(strict_types=1);

/**
 * GatsbyWears WMS 2.0 — pos.php
 *
 * Actions (matched by src/lib/api.ts):
 *   GET  ?action=list_products[&branch_id=N]  → Product[]  (sellable: in-stock variants at branch)
 *   POST ?action=checkout                      → Sale       (atomic stock deduct + cost snapshot)
 *   POST ?action=undo_sale  {id}               → { id }     (super_admin ONLY; returns stock)
 *   GET  ?action=sales_history                 → Sale[]     (scoped: worker=own, manager=branch, admin=all)
 *
 * POS access: managers/admins always; workers require pos_access=1.
 * Profit uses COALESCE(si.unit_cost, p.cost, 0) so it holds if product cost changes.
 */

require __DIR__ . '/config.php';

/** Gate POS usage: workers need pos_access; managers/admins always allowed. */
function require_pos(): array
{
    $user = require_auth();
    if ($user['role'] === 'worker' && (int) $user['pos_access'] !== 1) {
        respond_error('You do not have POS access.', 403, 'no_pos');
    }
    return $user;
}

/** The branch a sale is recorded against (the actor's own branch). */
function acting_branch(array $user): int
{
    if ($user['branch_id'] === null) {
        respond_error('Your account is not assigned to a branch.', 409, 'no_branch');
    }
    return (int) $user['branch_id'];
}

/**
 * The branch a checkout deducts from / is recorded against.
 * Super admins have access to every branch, so they may sell from any
 * branch by passing `branch_id` in the body (and need not be assigned to
 * one themselves). Managers/workers always sell from their own branch.
 */
function checkout_branch(array $user): int
{
    if (is_admin($user)) {
        $b = body('branch_id');
        if ($b !== null && $b !== '') {
            $bid = (int) $b;
            $chk = db()->prepare('SELECT 1 FROM branches WHERE id = ? AND deleted_at IS NULL');
            $chk->execute([$bid]);
            if (!$chk->fetch()) {
                respond_error('Branch not found.', 404, 'not_found');
            }
            return $bid;
        }
        if ($user['branch_id'] !== null) {
            return (int) $user['branch_id'];
        }
        respond_error('Select a branch to sell from.', 422, 'no_branch');
    }
    return acting_branch($user);
}

/**
 * Assemble full Sale shapes (with items, cogs, profit) for given sale ids.
 * @return array<int,array>
 */
function build_sales(array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT s.*, b.name AS branch_name, u.name AS worker_name, c.name AS customer_name
         FROM sales s
         JOIN branches b ON b.id = s.branch_id
         JOIN users u    ON u.id = s.worker_id
         LEFT JOIN customers c ON c.id = s.customer_id
         WHERE s.id IN ($place)"
    );
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll() as $s) {
        $out[(int) $s['id']] = [
            'id'             => (int) $s['id'],
            'invoice_no'     => (string) $s['invoice_no'],
            'branch_id'      => (int) $s['branch_id'],
            'branch_name'    => (string) $s['branch_name'],
            'worker_id'      => (int) $s['worker_id'],
            'worker_name'    => (string) $s['worker_name'],
            'customer_id'    => $s['customer_id'] !== null ? (int) $s['customer_id'] : null,
            'customer_name'  => $s['customer_name'] !== null ? (string) $s['customer_name'] : null,
            'total_amount'   => (float) $s['total_amount'],
            'discount'       => (float) $s['discount'],
            'net_amount'     => (float) $s['net_amount'],
            'payment_method' => (string) $s['payment_method'],
            'notes'          => $s['notes'],
            'cancelled'      => (int) $s['cancelled'],
            'cancelled_at'   => $s['cancelled_at'],
            'created_at'     => $s['created_at'],
            'items'          => [],
            'cogs'           => 0.0,
            'profit'         => 0.0,
        ];
    }
    if ($out === []) {
        return [];
    }

    $stmt = db()->prepare(
        "SELECT si.*, p.name AS product_name, p.cost AS product_cost, pv.size AS size
         FROM sale_items si
         JOIN products p          ON p.id = si.product_id
         JOIN product_variants pv ON pv.id = si.variant_id
         WHERE si.sale_id IN ($place)
         ORDER BY si.id ASC"
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $it) {
        $sid = (int) $it['sale_id'];
        if (!isset($out[$sid])) {
            continue;
        }
        $unitCost = $it['unit_cost'] !== null ? (float) $it['unit_cost'] : (float) $it['product_cost'];
        $qty = (int) $it['qty'];
        $out[$sid]['items'][] = [
            'id'           => (int) $it['id'],
            'sale_id'      => $sid,
            'variant_id'   => (int) $it['variant_id'],
            'product_id'   => (int) $it['product_id'],
            'product_name' => (string) $it['product_name'],
            'size'         => (string) $it['size'],
            'qty'          => $qty,
            'unit_price'   => (float) $it['unit_price'],
            'line_total'   => (float) $it['line_total'],
            'unit_cost'    => $it['unit_cost'] !== null ? (float) $it['unit_cost'] : null,
        ];
        $out[$sid]['cogs'] += $unitCost * $qty;
    }
    foreach ($out as $sid => $s) {
        $out[$sid]['profit'] = round($s['net_amount'] - $s['cogs'], 2);
        $out[$sid]['cogs']   = round($s['cogs'], 2);
    }
    return $out;
}

function build_sale(int $id): ?array
{
    $m = build_sales([$id]);
    return $m[$id] ?? null;
}

/** Generate a unique invoice number INV-YYYYMMDD-XXXXXX. */
function gen_invoice_no(): string
{
    for ($i = 0; $i < 5; $i++) {
        $candidate = 'INV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $chk = db()->prepare('SELECT 1 FROM sales WHERE invoice_no = ? LIMIT 1');
        $chk->execute([$candidate]);
        if (!$chk->fetch()) {
            return $candidate;
        }
    }
    // Extremely unlikely fallback.
    return 'INV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
}

/* ============================================================ */

$action = query('action', '');
$method = method();

switch ($action) {

    /* ---------------- list_products ---------------- */
    case 'list_products': {
        $user = require_pos();
        // Default to the actor's branch; admins may pass an explicit branch_id.
        $branchId = $user['branch_id'];
        $q = query('branch_id');
        if ($q !== null && $q !== '' && is_admin($user)) {
            $branchId = (int) $q;
        }
        if ($branchId === null) {
            respond_error('No branch context for POS.', 409, 'no_branch');
        }

        // Products that have at least one in-stock variant at this branch.
        $stmt = db()->prepare(
            'SELECT DISTINCT p.id
             FROM products p
             JOIN product_variants pv ON pv.product_id = p.id
             JOIN branch_stock bs     ON bs.variant_id = pv.id AND bs.branch_id = ?
             WHERE p.deleted_at IS NULL AND p.status <> "inactive" AND bs.qty > 0
             ORDER BY p.name ASC'
        );
        $stmt->execute([$branchId]);
        $ids = array_map(fn ($r) => (int) $r['id'], $stmt->fetchAll());

        // Light builder (inlined below) gives full shapes with per-branch qty.
        respond(pos_build_products($ids, (int) $branchId));
    }

    /* ---------------- checkout ---------------- */
    case 'checkout': {
        if ($method !== 'POST') {
            respond_error('Method not allowed.', 405);
        }
        $user   = require_pos();
        $branch = checkout_branch($user);

        $items = body('items', []);
        if (!is_array($items) || $items === []) {
            respond_error('Cart is empty.', 422, 'empty_cart');
        }
        $discount = max(0.0, (float) body('discount', 0));
        $payment  = body('payment_method', 'cash');
        if (!in_array($payment, ['cash', 'card', 'bkash', 'nagad', 'other'], true)) {
            $payment = 'cash';
        }
        $notes = trim((string) body('notes', ''));

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Optional customer capture (anonymous sales leave customer_id NULL).
            $customerId = resolve_customer($pdo, $user);

            $lines = [];
            $total = 0.0;

            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $variantId = (int) ($it['variant_id'] ?? 0);
                $qty       = (int) ($it['qty'] ?? 0);
                if ($variantId <= 0 || $qty <= 0) {
                    $pdo->rollBack();
                    respond_error('Invalid cart line.', 422, 'bad_line');
                }

                // Resolve product + authoritative price/cost.
                $pv = $pdo->prepare(
                    'SELECT pv.product_id, p.name, p.price, p.cost
                     FROM product_variants pv JOIN products p ON p.id = pv.product_id
                     WHERE pv.id = ?'
                );
                $pv->execute([$variantId]);
                $prow = $pv->fetch();
                if (!$prow) {
                    $pdo->rollBack();
                    respond_error('Product not found.', 404, 'not_found');
                }
                $productId = (int) $prow['product_id'];
                // Trust server price unless an explicit unit_price override is sent.
                $unitPrice = isset($it['unit_price']) ? (float) $it['unit_price'] : (float) $prow['price'];
                $unitCost  = (float) $prow['cost'];

                // Lock stock row, verify, deduct.
                $cur = $pdo->prepare('SELECT qty FROM branch_stock WHERE branch_id = ? AND variant_id = ? FOR UPDATE');
                $cur->execute([$branch, $variantId]);
                $srow = $cur->fetch();
                $have = $srow ? (int) $srow['qty'] : 0;
                if ($have < $qty) {
                    $pdo->rollBack();
                    respond_error("Only {$have} left of {$prow['name']}.", 409, 'insufficient_stock');
                }
                $pdo->prepare('UPDATE branch_stock SET qty = qty - ? WHERE branch_id = ? AND variant_id = ?')
                    ->execute([$qty, $branch, $variantId]);

                $lineTotal = round($unitPrice * $qty, 2);
                $total += $lineTotal;
                $lines[] = [
                    'variant_id' => $variantId, 'product_id' => $productId,
                    'qty' => $qty, 'unit_price' => $unitPrice,
                    'line_total' => $lineTotal, 'unit_cost' => $unitCost,
                    'old_qty' => $have, 'new_qty' => $have - $qty,
                ];
            }

            if ($lines === []) {
                $pdo->rollBack();
                respond_error('Cart is empty.', 422, 'empty_cart');
            }

            $discount = min($discount, $total); // never negative net
            $net = round($total - $discount, 2);
            $invoice = gen_invoice_no();

            $ins = $pdo->prepare(
                'INSERT INTO sales (invoice_no, branch_id, worker_id, customer_id, total_amount, discount, net_amount, payment_method, notes)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );
            $ins->execute([$invoice, $branch, $user['id'], $customerId, round($total, 2), round($discount, 2), $net, $payment,
                $notes !== '' ? $notes : null]);
            $saleId = (int) $pdo->lastInsertId();

            $insItem = $pdo->prepare(
                'INSERT INTO sale_items (sale_id, variant_id, product_id, qty, unit_price, line_total, unit_cost)
                 VALUES (?,?,?,?,?,?,?)'
            );
            foreach ($lines as $l) {
                $insItem->execute([$saleId, $l['variant_id'], $l['product_id'], $l['qty'],
                    $l['unit_price'], $l['line_total'], $l['unit_cost']]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Best-effort audit per line (outside the txn).
        foreach ($lines as $l) {
            log_audit([
                'action_type' => 'pos_sale', 'entity_type' => 'branch_stock',
                'entity_id' => $l['variant_id'], 'branch_id' => $branch, 'product_id' => $l['product_id'],
                'old_qty' => $l['old_qty'], 'new_qty' => $l['new_qty'], 'changed_by' => $user['id'],
                'reason' => 'pos_sale', 'metadata' => ['sale_id' => $saleId, 'invoice_no' => $invoice],
            ]);
        }
        // Recompute statuses for affected products.
        $touched = array_unique(array_map(fn ($l) => $l['product_id'], $lines));
        foreach ($touched as $pid) {
            pos_refresh_status((int) $pid);
        }

        respond(build_sale($saleId), 201);
    }

    /* ---------------- undo_sale (super_admin only) ---------------- */
    case 'undo_sale': {
        if ($method !== 'POST') {
            respond_error('Method not allowed.', 405);
        }
        $user = require_admin();
        $id = require_id(body('id'));

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $sel = $pdo->prepare('SELECT * FROM sales WHERE id = ? FOR UPDATE');
            $sel->execute([$id]);
            $sale = $sel->fetch();
            if (!$sale) {
                $pdo->rollBack();
                respond_error('Sale not found.', 404, 'not_found');
            }
            if ((int) $sale['cancelled'] === 1) {
                $pdo->rollBack();
                respond_error('This sale is already cancelled.', 409, 'already_cancelled');
            }
            $branch = (int) $sale['branch_id'];

            // Return each item's qty to branch_stock.
            $its = $pdo->prepare('SELECT variant_id, product_id, qty FROM sale_items WHERE sale_id = ?');
            $its->execute([$id]);
            $restored = $its->fetchAll();

            foreach ($restored as $it) {
                $variantId = (int) $it['variant_id'];
                $qty = (int) $it['qty'];
                $cur = $pdo->prepare('SELECT qty FROM branch_stock WHERE branch_id = ? AND variant_id = ? FOR UPDATE');
                $cur->execute([$branch, $variantId]);
                $row = $cur->fetch();
                $old = $row ? (int) $row['qty'] : 0;
                $new = $old + $qty;
                $pdo->prepare(
                    'INSERT INTO branch_stock (branch_id, variant_id, qty) VALUES (?,?,?)
                     ON DUPLICATE KEY UPDATE qty = VALUES(qty)'
                )->execute([$branch, $variantId, $new]);
                $it['_old'] = $old;
                $it['_new'] = $new;
            }

            $pdo->prepare('UPDATE sales SET cancelled = 1, cancelled_at = NOW() WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        foreach ($restored as $it) {
            log_audit([
                'action_type' => 'adjustment', 'entity_type' => 'branch_stock',
                'entity_id' => (int) $it['variant_id'], 'branch_id' => $branch,
                'product_id' => (int) $it['product_id'],
                'old_qty' => $it['_old'] ?? null, 'new_qty' => $it['_new'] ?? null,
                'changed_by' => $user['id'], 'reason' => 'undo_sale',
                'metadata' => ['sale_id' => $id, 'invoice_no' => $sale['invoice_no']],
            ]);
            pos_refresh_status((int) $it['product_id']);
        }

        respond(['id' => $id]);
    }

    /* ---------------- sales_history ---------------- */
    case 'sales_history': {
        $user = require_auth();
        $where = [];
        $params = [];

        // Scope: worker → own; manager → own branch; admin → all (optional filters).
        if ($user['role'] === 'worker') {
            $where[] = 's.worker_id = ?';
            $params[] = $user['id'];
        } elseif ($user['role'] === 'manager') {
            $where[] = 's.branch_id = ?';
            $params[] = acting_branch($user);
        } else {
            if (($b = query('branch_id')) !== null && $b !== '') {
                $where[] = 's.branch_id = ?';
                $params[] = (int) $b;
            }
            if (($w = query('worker_id')) !== null && $w !== '') {
                $where[] = 's.worker_id = ?';
                $params[] = (int) $w;
            }
        }

        if (($pm = query('payment_method')) !== null && $pm !== ''
            && in_array($pm, ['cash', 'card', 'bkash', 'nagad', 'other'], true)) {
            $where[] = 's.payment_method = ?';
            $params[] = $pm;
        }
        if (($from = query('from')) !== null && $from !== '') {
            $where[] = 's.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if (($to = query('to')) !== null && $to !== '') {
            $where[] = 's.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }
        $sql = 'SELECT id FROM sales s';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY s.created_at DESC, s.id DESC LIMIT 1000';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $ids = array_map(fn ($r) => (int) $r['id'], $stmt->fetchAll());
        $map = build_sales($ids);
        respond(array_map(fn ($id) => $map[$id], array_filter($ids, fn ($id) => isset($map[$id]))));
    }

    default:
        respond_error('Unknown action.', 404, 'unknown_action');
}

/* ============================================================
   Local helpers (hoisted)
   ============================================================ */

/** Light product builder for the POS grid (per-branch qty on each variant). */
function pos_build_products(array $ids, int $branchId): array
{
    if ($ids === []) {
        return [];
    }
    $place = implode(',', array_fill(0, count($ids), '?'));
    $pdo = db();

    $pstmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($place)");
    $pstmt->execute($ids);
    $out = [];
    foreach ($pstmt->fetchAll() as $p) {
        $out[(int) $p['id']] = [
            'id' => (int) $p['id'], 'name' => (string) $p['name'], 'sku' => (string) $p['sku'],
            'category' => $p['category'], 'price' => (float) $p['price'], 'cost' => (float) $p['cost'],
            'color' => $p['color'], 'reorder_point' => (int) $p['reorder_point'],
            'status' => (string) $p['status'], 'emoji' => $p['emoji'], 'description' => $p['description'],
            'images' => [], 'variants' => [], 'total_stock' => 0, 'primary_image' => null,
        ];
    }

    $istmt = $pdo->prepare("SELECT product_id, path FROM product_images WHERE product_id IN ($place) AND is_primary = 1");
    $istmt->execute($ids);
    foreach ($istmt->fetchAll() as $img) {
        if (isset($out[(int) $img['product_id']])) {
            $out[(int) $img['product_id']]['primary_image'] = (string) $img['path'];
        }
    }

    $params = array_merge([$branchId], $ids);
    $vstmt = $pdo->prepare(
        "SELECT pv.id, pv.product_id, pv.size, pv.variant_sku, COALESCE(bs.qty,0) AS qty
         FROM product_variants pv
         LEFT JOIN branch_stock bs ON bs.variant_id = pv.id AND bs.branch_id = ?
         WHERE pv.product_id IN ($place) ORDER BY pv.id ASC"
    );
    $vstmt->execute($params);
    foreach ($vstmt->fetchAll() as $v) {
        $pid = (int) $v['product_id'];
        if (!isset($out[$pid])) {
            continue;
        }
        $qty = (int) $v['qty'];
        $out[$pid]['variants'][] = [
            'id' => (int) $v['id'], 'product_id' => $pid, 'size' => (string) $v['size'],
            'variant_sku' => $v['variant_sku'], 'stock' => [$branchId => $qty], 'total_stock' => $qty,
        ];
        $out[$pid]['total_stock'] += $qty;
    }

    return array_values(array_filter($out, fn ($p) => $p['total_stock'] > 0));
}

/**
 * Resolve the optional POS customer from the request body `{ customer: { name, phone } }`.
 * Upserts by phone when provided; returns the customer id, or null for an anonymous sale.
 */
function resolve_customer(\PDO $pdo, array $user): ?int
{
    $c = body('customer');
    if (!is_array($c)) {
        return null;
    }
    $name  = trim((string) ($c['name'] ?? ''));
    $phone = trim((string) ($c['phone'] ?? ''));
    if ($name === '' && $phone === '') {
        return null;
    }
    if ($phone !== '') {
        $sel = $pdo->prepare('SELECT id, name FROM customers WHERE phone = ? LIMIT 1');
        $sel->execute([$phone]);
        $row = $sel->fetch();
        if ($row) {
            if ($name !== '' && trim((string) $row['name']) === '') {
                $pdo->prepare('UPDATE customers SET name = ? WHERE id = ?')->execute([$name, (int) $row['id']]);
            }
            return (int) $row['id'];
        }
    }
    $ins = $pdo->prepare('INSERT INTO customers (name, phone, created_by) VALUES (?,?,?)');
    $ins->execute([$name !== '' ? $name : 'Walk-in', $phone !== '' ? $phone : null, $user['id']]);
    return (int) $pdo->lastInsertId();
}

/** Recompute product status from total stock (skips admin 'inactive'). */
function pos_refresh_status(int $productId): void
{
    $stmt = db()->prepare(
        'SELECT p.reorder_point, p.status, COALESCE(SUM(bs.qty),0) AS total
         FROM products p
         LEFT JOIN product_variants pv ON pv.product_id = p.id
         LEFT JOIN branch_stock bs ON bs.variant_id = pv.id
         WHERE p.id = ? GROUP BY p.id'
    );
    $stmt->execute([$productId]);
    $row = $stmt->fetch();
    if (!$row || $row['status'] === 'inactive') {
        return;
    }
    $status = (int) $row['total'] <= (int) $row['reorder_point'] ? 'low' : 'active';
    db()->prepare('UPDATE products SET status = ? WHERE id = ?')->execute([$status, $productId]);
}
