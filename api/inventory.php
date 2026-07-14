<?php
declare(strict_types=1);

/**
 * GatsbyWears WMS 2.0 — inventory.php
 *
 * Actions (matched by src/lib/api.ts):
 *   GET    (no action)            list        → Product[]   (filters: search/category/status/branch/flag)
 *   GET    ?id=N                  get         → Product     (full detail: variants+per-branch stock+images)
 *   POST   (no action)           add         → Product     (manager+)
 *   PUT    ?id=N                  update      → Product     (manager+)
 *   DELETE ?id=N                 soft-delete  → { id }      (manager+)
 *   GET    ?action=trash         trash list  → Product[]   (admin)
 *   POST   ?action=restore  {id} restore     → { id }      (admin)
 *   POST   ?action=purge    {id} hard delete → { id }      (admin; blocked if sale history)
 *   POST   ?action=allocate {variant_id,branch_id,qty}        → { qty }  (manager+)
 *   POST   ?action=adjust   {variant_id,branch_id,delta,reason}→ { qty }  (manager+)
 *
 * Stock model is per-variant × branch (branch_stock). Non-admins are branch-scoped
 * for stock writes; reads are global (inventory is shared catalog).
 */

require __DIR__ . '/config.php';

/* ============================================================
   Shared builders
   ============================================================ */

/**
 * Assemble the full Product shape for a set of product ids.
 * Returns an id→Product map preserving nothing about order.
 *
 * @param int[] $ids
 * @return array<int, array>
 */
function build_products(array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $place = implode(',', array_fill(0, count($ids), '?'));

    // Base rows.
    $stmt = db()->prepare("SELECT * FROM products WHERE id IN ($place)");
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll() as $p) {
        $out[(int) $p['id']] = [
            'id'            => (int) $p['id'],
            'name'          => (string) $p['name'],
            'sku'           => (string) $p['sku'],
            'category'      => $p['category'],
            'price'         => (float) $p['price'],
            'cost'          => (float) $p['cost'],
            'color'         => $p['color'],
            'reorder_point' => (int) $p['reorder_point'],
            'status'        => (string) $p['status'],
            'emoji'         => $p['emoji'],
            'description'   => $p['description'],
            'images'        => [],
            'variants'      => [],
            'total_stock'   => 0,
            'primary_image' => null,
            'deleted_at'    => $p['deleted_at'],
            'created_at'    => $p['created_at'],
            'updated_at'    => $p['updated_at'],
        ];
    }
    if ($out === []) {
        return [];
    }

    // Images.
    $stmt = db()->prepare("SELECT * FROM product_images WHERE product_id IN ($place) ORDER BY is_primary DESC, sort ASC, id ASC");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $img) {
        $pid = (int) $img['product_id'];
        if (!isset($out[$pid])) {
            continue;
        }
        $out[$pid]['images'][] = [
            'id'         => (int) $img['id'],
            'product_id' => $pid,
            'path'       => (string) $img['path'],
            'is_primary' => (int) $img['is_primary'],
            'sort'       => (int) $img['sort'],
        ];
        if ($out[$pid]['primary_image'] === null) {
            $out[$pid]['primary_image'] = (string) $img['path'];
        }
    }

    // Variants + per-branch stock.
    $stmt = db()->prepare(
        "SELECT pv.id, pv.product_id, pv.size, pv.variant_sku,
                bs.branch_id, bs.qty
         FROM product_variants pv
         LEFT JOIN branch_stock bs ON bs.variant_id = pv.id
         WHERE pv.product_id IN ($place)
         ORDER BY pv.id ASC"
    );
    $stmt->execute($ids);
    $variantIndex = []; // pid => [vid => index]
    foreach ($stmt->fetchAll() as $r) {
        $pid = (int) $r['product_id'];
        $vid = (int) $r['id'];
        if (!isset($out[$pid])) {
            continue;
        }
        if (!isset($variantIndex[$pid][$vid])) {
            $out[$pid]['variants'][] = [
                'id'          => $vid,
                'product_id'  => $pid,
                'size'        => (string) $r['size'],
                'variant_sku' => $r['variant_sku'],
                'stock'       => [],
                'total_stock' => 0,
            ];
            $variantIndex[$pid][$vid] = count($out[$pid]['variants']) - 1;
        }
        if ($r['branch_id'] !== null) {
            $idx = $variantIndex[$pid][$vid];
            $bid = (int) $r['branch_id'];
            $qty = (int) $r['qty'];
            $out[$pid]['variants'][$idx]['stock'][$bid] = $qty;
            $out[$pid]['variants'][$idx]['total_stock'] += $qty;
            $out[$pid]['total_stock'] += $qty;
        }
    }

    return $out;
}

/** Build one product (full shape) or null. */
function build_product(int $id): ?array
{
    $m = build_products([$id]);
    return $m[$id] ?? null;
}

/** Recompute & persist product status from total stock vs reorder_point. */
function refresh_product_status(int $productId): void
{
    $stmt = db()->prepare(
        'SELECT p.reorder_point, p.status,
                COALESCE(SUM(bs.qty),0) AS total
         FROM products p
         LEFT JOIN product_variants pv ON pv.product_id = p.id
         LEFT JOIN branch_stock bs ON bs.variant_id = pv.id
         WHERE p.id = ? GROUP BY p.id'
    );
    $stmt->execute([$productId]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }
    // Don't auto-flip an admin-set 'inactive'.
    if ($row['status'] === 'inactive') {
        return;
    }
    $total = (int) $row['total'];
    $rp    = (int) $row['reorder_point'];
    $status = $total <= $rp ? 'low' : 'active';
    $up = db()->prepare('UPDATE products SET status = ? WHERE id = ?');
    $up->execute([$status, $productId]);
}

/* ============================================================
   Routing
   ============================================================ */

$action = query('action', '');
$method = method();

/* ---------- writes that use ?action= ---------- */
if ($method === 'POST' && $action === 'allocate') {
    $user = require_manager();
    $variantId = require_id(body('variant_id'), 'variant_id');
    $branchId  = require_id(body('branch_id'), 'branch_id');
    $qty       = (int) body('qty', 0);
    if ($qty < 0) {
        respond_error('Quantity cannot be negative.', 422, 'bad_qty');
    }
    require_branch_access($user, $branchId);

    // Validate variant + resolve product for audit.
    $v = db()->prepare('SELECT product_id FROM product_variants WHERE id = ?');
    $v->execute([$variantId]);
    $vr = $v->fetch();
    if (!$vr) {
        respond_error('Variant not found.', 404, 'not_found');
    }
    $productId = (int) $vr['product_id'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $cur = $pdo->prepare('SELECT qty FROM branch_stock WHERE branch_id = ? AND variant_id = ? FOR UPDATE');
        $cur->execute([$branchId, $variantId]);
        $row = $cur->fetch();
        $old = $row ? (int) $row['qty'] : 0;

        $ins = $pdo->prepare(
            'INSERT INTO branch_stock (branch_id, variant_id, qty) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE qty = VALUES(qty)'
        );
        $ins->execute([$branchId, $variantId, $qty]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    log_audit([
        'action_type' => 'manual_edit', 'entity_type' => 'branch_stock',
        'entity_id' => $variantId, 'branch_id' => $branchId, 'product_id' => $productId,
        'old_qty' => $old, 'new_qty' => $qty, 'changed_by' => $user['id'],
        'reason' => 'allocate',
    ]);
    refresh_product_status($productId);
    respond(['qty' => $qty]);
}

if ($method === 'POST' && $action === 'adjust') {
    $user = require_manager();
    $variantId = require_id(body('variant_id'), 'variant_id');
    $branchId  = require_id(body('branch_id'), 'branch_id');
    $delta     = (int) body('delta', 0);
    $reason    = trim((string) body('reason', ''));
    if ($delta === 0) {
        respond_error('Adjustment must be non-zero.', 422, 'bad_delta');
    }
    require_branch_access($user, $branchId);

    $v = db()->prepare('SELECT product_id FROM product_variants WHERE id = ?');
    $v->execute([$variantId]);
    $vr = $v->fetch();
    if (!$vr) {
        respond_error('Variant not found.', 404, 'not_found');
    }
    $productId = (int) $vr['product_id'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $cur = $pdo->prepare('SELECT qty FROM branch_stock WHERE branch_id = ? AND variant_id = ? FOR UPDATE');
        $cur->execute([$branchId, $variantId]);
        $row = $cur->fetch();
        $old = $row ? (int) $row['qty'] : 0;
        $new = $old + $delta;
        if ($new < 0) {
            $pdo->rollBack();
            respond_error('Adjustment would make stock negative.', 422, 'negative_stock');
        }
        $ins = $pdo->prepare(
            'INSERT INTO branch_stock (branch_id, variant_id, qty) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE qty = VALUES(qty)'
        );
        $ins->execute([$branchId, $variantId, $new]);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    log_audit([
        'action_type' => 'adjustment', 'entity_type' => 'branch_stock',
        'entity_id' => $variantId, 'branch_id' => $branchId, 'product_id' => $productId,
        'old_qty' => $old, 'new_qty' => $new, 'changed_by' => $user['id'],
        'reason' => $reason !== '' ? $reason : 'adjustment',
    ]);
    refresh_product_status($productId);
    respond(['qty' => $new]);
}

if ($method === 'GET' && $action === 'trash') {
    require_admin();
    $rows = db()->query('SELECT id FROM products WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC')->fetchAll();
    $map = build_products(array_map(fn ($r) => (int) $r['id'], $rows));
    $list = [];
    foreach ($rows as $r) {
        if (isset($map[(int) $r['id']])) {
            $list[] = $map[(int) $r['id']];
        }
    }
    respond($list);
}

if ($method === 'POST' && $action === 'restore') {
    require_admin();
    $id = require_id(body('id'));
    $up = db()->prepare('UPDATE products SET deleted_at = NULL WHERE id = ?');
    $up->execute([$id]);
    respond(['id' => $id]);
}

if ($method === 'POST' && $action === 'purge') {
    require_admin();
    $id = require_id(body('id'));
    // Block if any sale history references this product (preserve attribution).
    $chk = db()->prepare('SELECT 1 FROM sale_items WHERE product_id = ? LIMIT 1');
    $chk->execute([$id]);
    if ($chk->fetch()) {
        respond_error('Cannot purge: this product has sales history.', 409, 'has_history');
    }
    // Clean up uploaded images from disk first.
    $imgs = db()->prepare('SELECT path FROM product_images WHERE product_id = ?');
    $imgs->execute([$id]);
    foreach ($imgs->fetchAll() as $img) {
        delete_product_image((string) $img['path']);
    }
    // FK cascades remove images/variants/branch_stock.
    $del = db()->prepare('DELETE FROM products WHERE id = ?');
    $del->execute([$id]);
    respond(['id' => $id]);
}

/* ---------- standard REST verbs ---------- */
switch ($method) {

    case 'GET': {
        require_auth();
        $id = query('id');
        if ($id !== null) {
            $pid = require_id($id);
            $p = build_product($pid);
            if ($p === null || $p['deleted_at'] !== null) {
                respond_error('Product not found.', 404, 'not_found');
            }
            respond($p);
        }

        // List with filters.
        $where = ['p.deleted_at IS NULL'];
        $params = [];
        if (($s = trim((string) query('search', ''))) !== '') {
            $where[] = '(p.name LIKE ? OR p.sku LIKE ?)';
            $params[] = "%$s%";
            $params[] = "%$s%";
        }
        if (($cat = trim((string) query('category', ''))) !== '') {
            $where[] = 'p.category = ?';
            $params[] = $cat;
        }
        if (($st = trim((string) query('status', ''))) !== '') {
            $where[] = 'p.status = ?';
            $params[] = $st;
        }
        $sql = 'SELECT id FROM products p WHERE ' . implode(' AND ', $where) . ' ORDER BY p.updated_at DESC, p.id DESC';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $ids = array_map(fn ($r) => (int) $r['id'], $stmt->fetchAll());
        $map = build_products($ids);

        $flag = trim((string) query('flag', '')); // low | out | (none)
        $list = [];
        foreach ($ids as $pid) {
            if (!isset($map[$pid])) {
                continue;
            }
            $p = $map[$pid];
            if ($flag === 'low' && !($p['total_stock'] <= $p['reorder_point'])) {
                continue;
            }
            if ($flag === 'out' && $p['total_stock'] > 0) {
                continue;
            }
            $list[] = $p;
        }
        respond($list);
    }

    case 'POST': {
        $user = require_manager();
        $name = trim((string) body('name', ''));
        $sku  = trim((string) body('sku', ''));
        if ($name === '' || $sku === '') {
            respond_error('Name and SKU are required.', 422, 'missing_field');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO products (name, sku, category, price, cost, color, reorder_point, status, emoji, description)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            $ins->execute([
                $name, $sku,
                ($c = trim((string) body('category', ''))) !== '' ? $c : null,
                (float) body('price', 0),
                (float) body('cost', 0),
                ($cl = trim((string) body('color', ''))) !== '' ? $cl : null,
                (int) body('reorder_point', 10),
                in_array(body('status'), ['active', 'low', 'inactive'], true) ? body('status') : 'active',
                ($e = trim((string) body('emoji', ''))) !== '' ? $e : null,
                ($d = trim((string) body('description', ''))) !== '' ? $d : null,
            ]);
            $productId = (int) $pdo->lastInsertId();

            save_variants_and_stock($pdo, $productId, body('variants', []), $user);
            save_incoming_images($pdo, $productId, body('images', []));

            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            if ((int) $e->errorInfo[1] === 1062) {
                respond_error('That SKU already exists.', 409, 'duplicate_sku');
            }
            throw $e;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        refresh_product_status($productId);
        log_audit([
            'action_type' => 'manual_edit', 'entity_type' => 'inventory',
            'entity_id' => $productId, 'product_id' => $productId,
            'changed_by' => $user['id'], 'reason' => 'create_product',
        ]);
        respond(build_product($productId), 201);
    }

    case 'PUT': {
        $user = require_manager();
        $id = require_id(query('id'));
        $exists = db()->prepare('SELECT id FROM products WHERE id = ? AND deleted_at IS NULL');
        $exists->execute([$id]);
        if (!$exists->fetch()) {
            respond_error('Product not found.', 404, 'not_found');
        }

        // Only update fields that were provided.
        $fields = [];
        $params = [];
        $map = [
            'name' => 'string', 'sku' => 'string', 'category' => 'string',
            'price' => 'float', 'cost' => 'float', 'color' => 'string',
            'reorder_point' => 'int', 'status' => 'status', 'emoji' => 'string',
            'description' => 'string',
        ];
        $b = request_body();
        foreach ($map as $key => $type) {
            if (!array_key_exists($key, $b)) {
                continue;
            }
            $val = $b[$key];
            switch ($type) {
                case 'float':  $val = (float) $val; break;
                case 'int':    $val = (int) $val; break;
                case 'status': $val = in_array($val, ['active', 'low', 'inactive'], true) ? $val : 'active'; break;
                default:
                    $val = is_string($val) ? trim($val) : $val;
                    if ($val === '' && in_array($key, ['category', 'color', 'emoji', 'description'], true)) {
                        $val = null;
                    }
            }
            $fields[] = "$key = ?";
            $params[] = $val;
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($fields !== []) {
                $params[] = $id;
                $sql = 'UPDATE products SET ' . implode(', ', $fields) . ' WHERE id = ?';
                $pdo->prepare($sql)->execute($params);
            }
            // Optional variant/stock + image updates.
            if (array_key_exists('variants', $b)) {
                save_variants_and_stock($pdo, $id, $b['variants'], $user);
            }
            // Remove existing images flagged for deletion (DB row + disk file).
            if (array_key_exists('remove_image_ids', $b) && is_array($b['remove_image_ids'])) {
                remove_product_images($pdo, $id, $b['remove_image_ids']);
            }
            // Re-point primary to an existing image, if requested.
            if (array_key_exists('primary_image_id', $b)
                && $b['primary_image_id'] !== null && $b['primary_image_id'] !== '') {
                set_primary_image($pdo, $id, (int) $b['primary_image_id']);
            }
            // Append any newly uploaded images (a new image may claim primary).
            if (array_key_exists('images', $b)) {
                save_incoming_images($pdo, $id, $b['images']);
            }
            // Guarantee one image stays primary if any remain.
            ensure_primary_image($pdo, $id);
            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            if ((int) $e->errorInfo[1] === 1062) {
                respond_error('That SKU already exists.', 409, 'duplicate_sku');
            }
            throw $e;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        refresh_product_status($id);
        log_audit([
            'action_type' => 'manual_edit', 'entity_type' => 'inventory',
            'entity_id' => $id, 'product_id' => $id,
            'changed_by' => $user['id'], 'reason' => 'update_product',
        ]);
        respond(build_product($id));
    }

    case 'DELETE': {
        $user = require_manager();
        $id = require_id(query('id'));
        $up = db()->prepare('UPDATE products SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL');
        $up->execute([$id]);
        log_audit([
            'action_type' => 'manual_edit', 'entity_type' => 'inventory',
            'entity_id' => $id, 'product_id' => $id,
            'changed_by' => $user['id'], 'reason' => 'soft_delete_product',
        ]);
        respond(['id' => $id]);
    }

    default:
        respond_error('Method not allowed.', 405);
}

/* ============================================================
   Write helpers (defined after switch; hoisted by PHP)
   ============================================================ */

/**
 * Upsert variants for a product and (optionally) their per-branch stock.
 * Each incoming variant: { id?, size, variant_sku?, stock?: {branchId: qty} }.
 * Non-admins may only write stock for their own branch.
 */
function save_variants_and_stock(PDO $pdo, int $productId, mixed $variants, array $user): void
{
    if (!is_array($variants)) {
        return;
    }
    foreach ($variants as $v) {
        if (!is_array($v)) {
            continue;
        }
        $size = trim((string) ($v['size'] ?? ''));
        if ($size === '') {
            continue;
        }
        $variantSku = isset($v['variant_sku']) && trim((string) $v['variant_sku']) !== ''
            ? trim((string) $v['variant_sku']) : null;

        // Upsert the variant by (product_id, size).
        $sel = $pdo->prepare('SELECT id FROM product_variants WHERE product_id = ? AND size = ?');
        $sel->execute([$productId, $size]);
        $existing = $sel->fetch();
        if ($existing) {
            $variantId = (int) $existing['id'];
            $pdo->prepare('UPDATE product_variants SET variant_sku = ? WHERE id = ?')
                ->execute([$variantSku, $variantId]);
        } else {
            $pdo->prepare('INSERT INTO product_variants (product_id, size, variant_sku) VALUES (?,?,?)')
                ->execute([$productId, $size, $variantSku]);
            $variantId = (int) $pdo->lastInsertId();
        }

        // Per-branch stock.
        if (isset($v['stock']) && is_array($v['stock'])) {
            foreach ($v['stock'] as $bid => $qty) {
                $bid = (int) $bid;
                $qty = max(0, (int) $qty);
                if ($bid <= 0) {
                    continue;
                }
                // Branch-scope: non-admins can only set their own branch.
                if (!can_access_branch($user, $bid)) {
                    continue;
                }
                $cur = $pdo->prepare('SELECT qty FROM branch_stock WHERE branch_id = ? AND variant_id = ? FOR UPDATE');
                $cur->execute([$bid, $variantId]);
                $row = $cur->fetch();
                $old = $row ? (int) $row['qty'] : 0;
                if ($old === $qty) {
                    continue;
                }
                $pdo->prepare(
                    'INSERT INTO branch_stock (branch_id, variant_id, qty) VALUES (?,?,?)
                     ON DUPLICATE KEY UPDATE qty = VALUES(qty)'
                )->execute([$bid, $variantId, $qty]);
                log_audit([
                    'action_type' => 'manual_edit', 'entity_type' => 'branch_stock',
                    'entity_id' => $variantId, 'branch_id' => $bid, 'product_id' => $productId,
                    'old_qty' => $old, 'new_qty' => $qty, 'changed_by' => $user['id'],
                    'reason' => 'stock_set',
                ]);
            }
        }
    }
}

/**
 * Persist incoming images (base64 data URLs or {data,is_primary} objects).
 * New images are appended; the first primary wins.
 */
function save_incoming_images(PDO $pdo, int $productId, mixed $images): void
{
    if (!is_array($images) || $images === []) {
        return;
    }
    // Current max sort.
    $sel = $pdo->prepare('SELECT COALESCE(MAX(sort),-1) AS m FROM product_images WHERE product_id = ?');
    $sel->execute([$productId]);
    $sort = (int) ($sel->fetch()['m'] ?? -1);

    $hasPrimary = (function () use ($pdo, $productId): bool {
        $s = $pdo->prepare('SELECT 1 FROM product_images WHERE product_id = ? AND is_primary = 1 LIMIT 1');
        $s->execute([$productId]);
        return (bool) $s->fetch();
    })();

    foreach ($images as $img) {
        $data = is_array($img) ? (string) ($img['data'] ?? '') : (string) $img;
        if ($data === '' || str_starts_with($data, 'uploads/')) {
            // Already-saved path → skip (no re-upload).
            continue;
        }
        $path = save_product_image_base64($data);
        if ($path === null) {
            continue;
        }
        $sort++;
        $primary = (is_array($img) && !empty($img['is_primary'])) || !$hasPrimary;
        if ($primary) {
            $pdo->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?')->execute([$productId]);
            $hasPrimary = true;
        }
        $pdo->prepare(
            'INSERT INTO product_images (product_id, path, is_primary, sort) VALUES (?,?,?,?)'
        )->execute([$productId, $path, $primary ? 1 : 0, $sort]);
    }
}

/**
 * Delete the given image ids (scoped to $productId) from the DB and disk.
 * Ignores ids that don't belong to the product.
 *
 * @param int[] $imageIds
 */
function remove_product_images(PDO $pdo, int $productId, array $imageIds): void
{
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $imageIds),
        static fn (int $i): bool => $i > 0
    )));
    if ($ids === []) {
        return;
    }
    $place = implode(',', array_fill(0, count($ids), '?'));
    // Fetch matching rows scoped to this product (prevents cross-product deletes).
    $sel = $pdo->prepare("SELECT id, path FROM product_images WHERE product_id = ? AND id IN ($place)");
    $sel->execute(array_merge([$productId], $ids));
    $rows = $sel->fetchAll();
    if ($rows === []) {
        return;
    }
    $del = array_map(static fn ($r): int => (int) $r['id'], $rows);
    $place2 = implode(',', array_fill(0, count($del), '?'));
    $pdo->prepare("DELETE FROM product_images WHERE product_id = ? AND id IN ($place2)")
        ->execute(array_merge([$productId], $del));
    // Disk cleanup only after the DB delete succeeds.
    foreach ($rows as $r) {
        delete_product_image((string) $r['path']);
    }
}

/** Make one existing image (must belong to $productId) the sole primary. */
function set_primary_image(PDO $pdo, int $productId, int $imageId): void
{
    if ($imageId <= 0) {
        return;
    }
    $chk = $pdo->prepare('SELECT 1 FROM product_images WHERE id = ? AND product_id = ?');
    $chk->execute([$imageId, $productId]);
    if (!$chk->fetch()) {
        return; // not this product's image — ignore
    }
    $pdo->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?')->execute([$productId]);
    $pdo->prepare('UPDATE product_images SET is_primary = 1 WHERE id = ?')->execute([$imageId]);
}

/** If no image is primary but images exist, promote the first by sort. */
function ensure_primary_image(PDO $pdo, int $productId): void
{
    $chk = $pdo->prepare('SELECT 1 FROM product_images WHERE product_id = ? AND is_primary = 1 LIMIT 1');
    $chk->execute([$productId]);
    if ($chk->fetch()) {
        return;
    }
    $first = $pdo->prepare('SELECT id FROM product_images WHERE product_id = ? ORDER BY sort ASC, id ASC LIMIT 1');
    $first->execute([$productId]);
    $row = $first->fetch();
    if ($row) {
        $pdo->prepare('UPDATE product_images SET is_primary = 1 WHERE id = ?')->execute([(int) $row['id']]);
    }
}
