<?php
declare(strict_types=1);

/**
 * GatsbyWears WMS 2.0 — branches.php
 *
 * Actions (matched by src/lib/api.ts):
 *   GET    (no action)            list        → Branch[]  (reads: any auth)
 *   POST   (no action)           add         → Branch     (admin)
 *   PUT    ?id=N                  update      → Branch     (admin)
 *   DELETE ?id=N                 soft-delete  → { id }     (admin; mother protected)
 *   GET    ?action=trash         trash list  → Branch[]   (admin)
 *   POST   ?action=restore  {id} restore     → { id }     (admin)
 *   POST   ?action=purge    {id} hard delete → { id }     (admin; mother protected; blocked if in use)
 *
 * manager_name/total_stock/total_products are derived (no manager_id column on
 * branches — a branch's manager is the user with role=manager + branch_id=this).
 * Optional `manager_id` in add/update (re)assigns that user to the branch.
 */

require __DIR__ . '/config.php';

/**
 * Assemble the Branch shape for given ids (or all live when $ids === null).
 * @return array<int,array>
 */
function build_branches(?array $ids = null, bool $includeDeleted = false): array
{
    $where = [];
    $params = [];
    if ($ids !== null) {
        if ($ids === []) {
            return [];
        }
        $where[] = 'b.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = $ids;
    }
    $where[] = $includeDeleted ? 'b.deleted_at IS NOT NULL' : 'b.deleted_at IS NULL';

    $sql = 'SELECT b.*,
                   (SELECT u.id   FROM users u WHERE u.branch_id = b.id AND u.role = "manager"
                        AND u.deleted_at IS NULL AND u.is_active = 1 ORDER BY u.id ASC LIMIT 1) AS manager_id,
                   (SELECT u.name FROM users u WHERE u.branch_id = b.id AND u.role = "manager"
                        AND u.deleted_at IS NULL AND u.is_active = 1 ORDER BY u.id ASC LIMIT 1) AS manager_name,
                   COALESCE((SELECT SUM(bs.qty) FROM branch_stock bs WHERE bs.branch_id = b.id), 0) AS total_stock,
                   COALESCE((SELECT COUNT(DISTINCT pv.product_id)
                             FROM branch_stock bs JOIN product_variants pv ON pv.id = bs.variant_id
                             WHERE bs.branch_id = b.id AND bs.qty > 0), 0) AS total_products
            FROM branches b
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY b.type = "mother" DESC, b.name ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $b) {
        $out[(int) $b['id']] = [
            'id'             => (int) $b['id'],
            'name'           => (string) $b['name'],
            'code'           => (string) $b['code'],
            'type'           => (string) $b['type'],
            'city'           => $b['city'],
            'area'           => $b['area'],
            'address'        => $b['address'],
            'capacity'       => (int) $b['capacity'],
            'phone'          => $b['phone'],
            'email'          => $b['email'],
            'latitude'       => $b['latitude'] !== null ? (float) $b['latitude'] : null,
            'longitude'      => $b['longitude'] !== null ? (float) $b['longitude'] : null,
            'hours'          => $b['hours'] !== null && $b['hours'] !== '' ? json_decode((string) $b['hours'], true) : null,
            'notes'          => $b['notes'],
            'manager_id'     => $b['manager_id'] !== null ? (int) $b['manager_id'] : null,
            'manager_name'   => $b['manager_name'],
            'total_stock'    => (int) $b['total_stock'],
            'total_products' => (int) $b['total_products'],
            'images'         => [],
            'primary_image'  => null,
            'deleted_at'     => $b['deleted_at'],
            'created_at'     => $b['created_at'],
        ];
    }

    // Gallery images.
    if ($out !== []) {
        $bids = array_keys($out);
        $place = implode(',', array_fill(0, count($bids), '?'));
        $imgs = db()->prepare("SELECT * FROM branch_images WHERE branch_id IN ($place) ORDER BY is_primary DESC, sort ASC, id ASC");
        $imgs->execute($bids);
        foreach ($imgs->fetchAll() as $img) {
            $bid = (int) $img['branch_id'];
            if (!isset($out[$bid])) {
                continue;
            }
            $out[$bid]['images'][] = [
                'id'         => (int) $img['id'],
                'branch_id'  => $bid,
                'path'       => (string) $img['path'],
                'is_primary' => (int) $img['is_primary'],
                'sort'       => (int) $img['sort'],
            ];
            if ($out[$bid]['primary_image'] === null) {
                $out[$bid]['primary_image'] = (string) $img['path'];
            }
        }
    }

    return $out;
}

function build_branch(int $id): ?array
{
    $m = build_branches([$id]);
    if (isset($m[$id])) {
        return $m[$id];
    }
    $m = build_branches([$id], true); // maybe soft-deleted
    return $m[$id] ?? null;
}

/** Reassign a user as the manager of a branch (optional helper). */
function assign_manager(PDO $pdo, int $branchId, ?int $managerId): void
{
    if ($managerId === null) {
        return;
    }
    $sel = $pdo->prepare('SELECT id, role FROM users WHERE id = ? AND deleted_at IS NULL');
    $sel->execute([$managerId]);
    $u = $sel->fetch();
    if (!$u) {
        return;
    }
    // Point the user at this branch; promote a worker to manager if needed.
    $role = $u['role'] === 'super_admin' ? 'super_admin' : 'manager';
    $pdo->prepare('UPDATE users SET branch_id = ?, role = ? WHERE id = ?')
        ->execute([$branchId, $role, $managerId]);
}

/* ============================================================ */

$action = query('action', '');
$method = method();

if ($method === 'GET' && $action === 'trash') {
    require_admin();
    respond(array_values(build_branches(null, true)));
}

if ($method === 'POST' && $action === 'restore') {
    require_admin();
    $id = require_id(body('id'));
    db()->prepare('UPDATE branches SET deleted_at = NULL WHERE id = ?')->execute([$id]);
    respond(['id' => $id]);
}

if ($method === 'POST' && $action === 'delete_image') {
    require_admin();
    $imgId = require_id(body('image_id'), 'image_id');
    $sel = db()->prepare('SELECT branch_id, path, is_primary FROM branch_images WHERE id = ?');
    $sel->execute([$imgId]);
    $img = $sel->fetch();
    if (!$img) {
        respond_error('Image not found.', 404, 'not_found');
    }
    delete_product_image((string) $img['path']);
    db()->prepare('DELETE FROM branch_images WHERE id = ?')->execute([$imgId]);
    // Promote another image to primary if we removed the primary one.
    if ((int) $img['is_primary'] === 1) {
        $n = db()->prepare('SELECT id FROM branch_images WHERE branch_id = ? ORDER BY sort ASC, id ASC LIMIT 1');
        $n->execute([(int) $img['branch_id']]);
        if ($row = $n->fetch()) {
            db()->prepare('UPDATE branch_images SET is_primary = 1 WHERE id = ?')->execute([(int) $row['id']]);
        }
    }
    respond(['id' => $imgId]);
}

if ($method === 'POST' && $action === 'set_primary_image') {
    require_admin();
    $imgId = require_id(body('image_id'), 'image_id');
    $sel = db()->prepare('SELECT branch_id FROM branch_images WHERE id = ?');
    $sel->execute([$imgId]);
    $img = $sel->fetch();
    if (!$img) {
        respond_error('Image not found.', 404, 'not_found');
    }
    db()->prepare('UPDATE branch_images SET is_primary = 0 WHERE branch_id = ?')->execute([(int) $img['branch_id']]);
    db()->prepare('UPDATE branch_images SET is_primary = 1 WHERE id = ?')->execute([$imgId]);
    respond(['id' => $imgId]);
}

if ($method === 'POST' && $action === 'purge') {
    require_admin();
    $id = require_id(body('id'));
    $b = db()->prepare('SELECT type FROM branches WHERE id = ?');
    $b->execute([$id]);
    $row = $b->fetch();
    if (!$row) {
        respond_error('Branch not found.', 404, 'not_found');
    }
    if ($row['type'] === 'mother') {
        respond_error('The mother branch cannot be removed.', 409, 'mother_protected');
    }
    // Block if branch still has stock, sales, or assigned users.
    foreach ([
        ['SELECT 1 FROM branch_stock WHERE branch_id = ? AND qty > 0 LIMIT 1', 'stock'],
        ['SELECT 1 FROM sales WHERE branch_id = ? LIMIT 1', 'sales'],
        ['SELECT 1 FROM users WHERE branch_id = ? AND deleted_at IS NULL LIMIT 1', 'users'],
    ] as [$q, $what]) {
        $s = db()->prepare($q);
        $s->execute([$id]);
        if ($s->fetch()) {
            respond_error("Cannot purge: branch still has $what.", 409, 'in_use');
        }
    }
    db()->prepare('DELETE FROM branches WHERE id = ?')->execute([$id]);
    respond(['id' => $id]);
}

switch ($method) {

    case 'GET': {
        require_auth();
        respond(array_values(build_branches()));
    }

    case 'POST': {
        $user = require_admin();
        $name = trim((string) body('name', ''));
        $code = trim((string) body('code', ''));
        if ($name === '' || $code === '') {
            respond_error('Name and code are required.', 422, 'missing_field');
        }
        $type = in_array(body('type'), ['mother', 'sub'], true) ? body('type') : 'sub';

        // Enforce a single mother branch.
        if ($type === 'mother') {
            $m = db()->query('SELECT 1 FROM branches WHERE type = "mother" AND deleted_at IS NULL LIMIT 1')->fetch();
            if ($m) {
                respond_error('A mother branch already exists.', 409, 'mother_exists');
            }
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO branches (name, code, type, city, area, address, capacity, phone, email, latitude, longitude, hours, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $ins->execute([
                $name, $code, $type,
                ($v = trim((string) body('city', '')))    !== '' ? $v : null,
                ($v = trim((string) body('area', '')))    !== '' ? $v : null,
                ($v = trim((string) body('address', ''))) !== '' ? $v : null,
                (int) body('capacity', 0),
                ($v = trim((string) body('phone', '')))   !== '' ? $v : null,
                ($v = trim((string) body('email', '')))   !== '' ? $v : null,
                branch_coord(body('latitude')),
                branch_coord(body('longitude')),
                branch_hours_json(body('hours')),
                ($v = trim((string) body('notes', '')))   !== '' ? $v : null,
            ]);
            $id = (int) $pdo->lastInsertId();
            $mid = body('manager_id');
            assign_manager($pdo, $id, $mid !== null && $mid !== '' ? (int) $mid : null);
            save_branch_images($pdo, $id, body('images', []));
            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            if ((int) $e->errorInfo[1] === 1062) {
                respond_error('That branch code already exists.', 409, 'duplicate_code');
            }
            throw $e;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        respond(build_branch($id), 201);
    }

    case 'PUT': {
        $user = require_admin();
        $id = require_id(query('id'));
        $sel = db()->prepare('SELECT type FROM branches WHERE id = ? AND deleted_at IS NULL');
        $sel->execute([$id]);
        $cur = $sel->fetch();
        if (!$cur) {
            respond_error('Branch not found.', 404, 'not_found');
        }

        $b = request_body();
        $fields = [];
        $params = [];
        $map = [
            'name' => 'string', 'code' => 'string', 'city' => 'string', 'area' => 'string',
            'address' => 'string', 'capacity' => 'int', 'phone' => 'string',
            'email' => 'string', 'notes' => 'string',
        ];
        foreach ($map as $key => $type) {
            if (!array_key_exists($key, $b)) {
                continue;
            }
            $val = $b[$key];
            if ($type === 'int') {
                $val = (int) $val;
            } else {
                $val = is_string($val) ? trim($val) : $val;
                if ($val === '' && in_array($key, ['city', 'area', 'address', 'phone', 'email', 'notes'], true)) {
                    $val = null;
                }
            }
            $fields[] = "$key = ?";
            $params[] = $val;
        }
        // Geo + hours (typed separately).
        foreach (['latitude', 'longitude'] as $coordKey) {
            if (array_key_exists($coordKey, $b)) {
                $fields[] = "$coordKey = ?";
                $params[] = branch_coord($b[$coordKey]);
            }
        }
        if (array_key_exists('hours', $b)) {
            $fields[] = 'hours = ?';
            $params[] = branch_hours_json($b['hours']);
        }
        // type changes: only allow sub<->mother with single-mother enforcement.
        if (array_key_exists('type', $b) && in_array($b['type'], ['mother', 'sub'], true)) {
            if ($b['type'] === 'mother' && $cur['type'] !== 'mother') {
                $m = db()->query('SELECT 1 FROM branches WHERE type = "mother" AND deleted_at IS NULL LIMIT 1')->fetch();
                if ($m) {
                    respond_error('A mother branch already exists.', 409, 'mother_exists');
                }
            }
            $fields[] = 'type = ?';
            $params[] = $b['type'];
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($fields !== []) {
                $params[] = $id;
                $pdo->prepare('UPDATE branches SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
            }
            if (array_key_exists('manager_id', $b)) {
                $mid = $b['manager_id'];
                assign_manager($pdo, $id, $mid !== null && $mid !== '' ? (int) $mid : null);
            }
            if (array_key_exists('images', $b)) {
                save_branch_images($pdo, $id, $b['images']);
            }
            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            if ((int) $e->errorInfo[1] === 1062) {
                respond_error('That branch code already exists.', 409, 'duplicate_code');
            }
            throw $e;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        respond(build_branch($id));
    }

    case 'DELETE': {
        $user = require_admin();
        $id = require_id(query('id'));
        $sel = db()->prepare('SELECT type FROM branches WHERE id = ? AND deleted_at IS NULL');
        $sel->execute([$id]);
        $cur = $sel->fetch();
        if (!$cur) {
            respond_error('Branch not found.', 404, 'not_found');
        }
        if ($cur['type'] === 'mother') {
            respond_error('The mother branch cannot be deleted.', 409, 'mother_protected');
        }
        db()->prepare('UPDATE branches SET deleted_at = NOW() WHERE id = ?')->execute([$id]);
        respond(['id' => $id]);
    }

    default:
        respond_error('Method not allowed.', 405);
}

/* ============================================================
   Helpers (hoisted)
   ============================================================ */

/** Coerce a body value to a coordinate float, or null. */
function branch_coord(mixed $v): ?float
{
    if ($v === null || $v === '' || !is_numeric($v)) {
        return null;
    }
    return (float) $v;
}

/** Encode an opening-hours array to JSON for storage, or null. */
function branch_hours_json(mixed $v): ?string
{
    return is_array($v) && $v !== [] ? json_encode($v) : null;
}

/**
 * Persist incoming branch gallery images (base64 data URLs or {data,is_primary}).
 * Mirrors inventory's image handling; already-saved `uploads/...` paths are skipped.
 */
function save_branch_images(PDO $pdo, int $branchId, mixed $images): void
{
    if (!is_array($images) || $images === []) {
        return;
    }
    $sel = $pdo->prepare('SELECT COALESCE(MAX(sort),-1) AS m FROM branch_images WHERE branch_id = ?');
    $sel->execute([$branchId]);
    $sort = (int) ($sel->fetch()['m'] ?? -1);

    $s = $pdo->prepare('SELECT 1 FROM branch_images WHERE branch_id = ? AND is_primary = 1 LIMIT 1');
    $s->execute([$branchId]);
    $hasPrimary = (bool) $s->fetch();

    foreach ($images as $img) {
        $data = is_array($img) ? (string) ($img['data'] ?? '') : (string) $img;
        if ($data === '' || str_starts_with($data, 'uploads/')) {
            continue; // already-saved path → skip
        }
        $path = save_product_image_base64($data);
        if ($path === null) {
            continue;
        }
        $sort++;
        $primary = (is_array($img) && !empty($img['is_primary'])) || !$hasPrimary;
        if ($primary) {
            $pdo->prepare('UPDATE branch_images SET is_primary = 0 WHERE branch_id = ?')->execute([$branchId]);
            $hasPrimary = true;
        }
        $pdo->prepare('INSERT INTO branch_images (branch_id, path, is_primary, sort) VALUES (?,?,?,?)')
            ->execute([$branchId, $path, $primary ? 1 : 0, $sort]);
    }
}
