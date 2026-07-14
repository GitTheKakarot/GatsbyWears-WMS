<?php
declare(strict_types=1);

/**
 * GatsbyWears WMS 2.0 — performance.php (analytics, read-only)
 *
 * Actions (matched by src/lib/api.ts performance.get):
 *   revenue              → daily series { date, revenue, net, cogs, profit, units, sales }
 *   daily_breakdown      → alias of revenue (per-day rollup)
 *   profit_summary       → totals { gross, discount, net, cogs, profit, margin, sales }
 *   top_products         → [{ product_id, name, units, revenue, cogs, profit, margin }]
 *   worker_performance   → [{ worker_id, name, sales, units, revenue, profit, aov }]
 *   branch_compare       → [{ branch_id, name, sales, units, revenue, profit }]
 *   payment_breakdown    → [{ method, count, amount }]
 *   category_performance → [{ category, units, revenue, cogs, profit, margin }]
 *   inventory_health     → { units, cost_value, retail_value, low, out, dead, score }
 *   worker_kpi           → { today_*, month_*, daily_target, monthly_target }
 *
 * All money from non-cancelled sales. Branch-scope: managers locked to own branch;
 * admins see all (optional ?branch_id). Product/category revenue uses gross line_total
 * (sale-level discount is reported separately in profit_summary).
 */

require __DIR__ . '/config.php';

/** Resolve the [from, to] datetime window from ?days (default 30) or ?from/?to. */
function perf_window(): array
{
    $from = trim((string) query('from', ''));
    $to   = trim((string) query('to', ''));
    if ($from !== '' || $to !== '') {
        $f = $from !== '' ? $from . ' 00:00:00' : '1970-01-01 00:00:00';
        $t = $to   !== '' ? $to   . ' 23:59:59' : date('Y-m-d 23:59:59');
        return [$f, $t];
    }
    $days = (int) query('days', '30');
    $days = $days > 0 ? min($days, 366) : 30;
    return [date('Y-m-d 00:00:00', time() - ($days - 1) * 86400), date('Y-m-d 23:59:59')];
}

/**
 * Branch scope for analytics. Returns [sqlFragment, params] to AND into WHERE
 * (fragment may be ''). Managers forced to own branch; admins optional filter.
 */
function perf_scope(array $user, string $alias = 's'): array
{
    if (!is_admin($user)) {
        if ($user['branch_id'] === null) {
            respond_error('Your account is not assigned to a branch.', 409, 'no_branch');
        }
        return ["AND {$alias}.branch_id = ?", [(int) $user['branch_id']]];
    }
    $b = query('branch_id');
    if ($b !== null && $b !== '') {
        return ["AND {$alias}.branch_id = ?", [(int) $b]];
    }
    return ['', []];
}

/**
 * Branch scope for on-hand stock queries (alias `bs` = branch_stock). Managers locked to
 * own branch; admins optional ?branch_id. Returns [whereFragment, params] (fragment may be '').
 */
function perf_stock_scope(array $user): array
{
    if (!is_admin($user)) {
        return ['AND bs.branch_id = ?', [(int) $user['branch_id']]];
    }
    $b = query('branch_id');
    if ($b !== null && $b !== '') {
        return ['AND bs.branch_id = ?', [(int) $b]];
    }
    return ['', []];
}

const COST_EXPR = 'COALESCE(si.unit_cost, p.cost, 0)';

/* ============================================================ */

$user = require_auth();
$action = query('action', '');

// All analytics is manager+ (manager OR super_admin), EXCEPT `worker_kpi` — a worker may
// pull their OWN scorecard (daily/monthly goal progress) for the Dashboard. Only the worker
// role is restricted here (is_manager() is manager-only, so check the role directly).
if ($action !== 'worker_kpi' && $user['role'] === 'worker') {
    respond_error('You do not have permission to do that.', 403, 'forbidden');
}

[$from, $to] = perf_window();
[$scopeSql, $scopeParams] = perf_scope($user);

switch ($action) {

    case 'revenue':
    case 'daily_breakdown': {
        $sql = "SELECT DATE(s.created_at) AS d,
                       COUNT(DISTINCT s.id) AS sales,
                       COALESCE(SUM(s.net_amount),0) AS net,
                       COALESCE(SUM(s.total_amount),0) AS gross
                FROM sales s
                WHERE s.cancelled = 0 AND s.created_at BETWEEN ? AND ? $scopeSql
                GROUP BY DATE(s.created_at) ORDER BY d ASC";
        $stmt = db()->prepare($sql);
        $stmt->execute(array_merge([$from, $to], $scopeParams));
        $byDate = [];
        foreach ($stmt->fetchAll() as $r) {
            $byDate[$r['d']] = [
                'date' => $r['d'],
                'revenue' => (float) $r['gross'],
                'net' => (float) $r['net'],
                'cogs' => 0.0, 'profit' => 0.0, 'units' => 0,
                'sales' => (int) $r['sales'],
            ];
        }
        // COGS + units per day from items.
        $sql2 = "SELECT DATE(s.created_at) AS d,
                        COALESCE(SUM(" . COST_EXPR . " * si.qty),0) AS cogs,
                        COALESCE(SUM(si.qty),0) AS units
                 FROM sales s
                 JOIN sale_items si ON si.sale_id = s.id
                 JOIN products p ON p.id = si.product_id
                 WHERE s.cancelled = 0 AND s.created_at BETWEEN ? AND ? $scopeSql
                 GROUP BY DATE(s.created_at)";
        $stmt2 = db()->prepare($sql2);
        $stmt2->execute(array_merge([$from, $to], $scopeParams));
        foreach ($stmt2->fetchAll() as $r) {
            if (isset($byDate[$r['d']])) {
                $byDate[$r['d']]['cogs'] = (float) $r['cogs'];
                $byDate[$r['d']]['units'] = (int) $r['units'];
                $byDate[$r['d']]['profit'] = round($byDate[$r['d']]['net'] - (float) $r['cogs'], 2);
            }
        }
        respond(array_values($byDate));
    }

    case 'profit_summary': {
        // Sale-level totals.
        $a = db()->prepare(
            "SELECT COUNT(*) AS sales,
                    COALESCE(SUM(total_amount),0) AS gross,
                    COALESCE(SUM(discount),0) AS discount,
                    COALESCE(SUM(net_amount),0) AS net
             FROM sales s
             WHERE s.cancelled = 0 AND s.created_at BETWEEN ? AND ? $scopeSql"
        );
        $a->execute(array_merge([$from, $to], $scopeParams));
        $tot = $a->fetch();
        // Item-level COGS.
        $c = db()->prepare(
            "SELECT COALESCE(SUM(" . COST_EXPR . " * si.qty),0) AS cogs
             FROM sales s JOIN sale_items si ON si.sale_id = s.id JOIN products p ON p.id = si.product_id
             WHERE s.cancelled = 0 AND s.created_at BETWEEN ? AND ? $scopeSql"
        );
        $c->execute(array_merge([$from, $to], $scopeParams));
        $cogs = (float) $c->fetch()['cogs'];
        $net = (float) $tot['net'];
        $profit = round($net - $cogs, 2);
        respond([
            'sales'    => (int) $tot['sales'],
            'gross'    => (float) $tot['gross'],
            'discount' => (float) $tot['discount'],
            'net'      => $net,
            'cogs'     => round($cogs, 2),
            'profit'   => $profit,
            'margin'   => $net > 0 ? round($profit / $net * 100, 1) : 0.0,
        ]);
    }

    case 'top_products': {
        $limit = max(1, min(50, (int) query('limit', '5')));
        $sql = "SELECT si.product_id, p.name,
                       SUM(si.qty) AS units,
                       SUM(si.line_total) AS revenue,
                       SUM(" . COST_EXPR . " * si.qty) AS cogs
                FROM sales s
                JOIN sale_items si ON si.sale_id = s.id
                JOIN products p ON p.id = si.product_id
                WHERE s.cancelled = 0 AND s.created_at BETWEEN ? AND ? $scopeSql
                GROUP BY si.product_id, p.name
                ORDER BY revenue DESC
                LIMIT $limit";
        $stmt = db()->prepare($sql);
        $stmt->execute(array_merge([$from, $to], $scopeParams));
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $rev = (float) $r['revenue'];
            $cogs = (float) $r['cogs'];
            $profit = round($rev - $cogs, 2);
            $out[] = [
                'product_id' => (int) $r['product_id'], 'name' => (string) $r['name'],
                'units' => (int) $r['units'], 'revenue' => $rev, 'cogs' => round($cogs, 2),
                'profit' => $profit, 'margin' => $rev > 0 ? round($profit / $rev * 100, 1) : 0.0,
            ];
        }
        respond($out);
    }

    case 'worker_performance': {
        $sql = "SELECT s.worker_id, u.name,
                       COUNT(DISTINCT s.id) AS sales,
                       COALESCE(SUM(s.net_amount),0) AS revenue
                FROM sales s JOIN users u ON u.id = s.worker_id
                WHERE s.cancelled = 0 AND s.created_at BETWEEN ? AND ? $scopeSql
                GROUP BY s.worker_id, u.name ORDER BY revenue DESC";
        $stmt = db()->prepare($sql);
        $stmt->execute(array_merge([$from, $to], $scopeParams));
        $base = [];
        foreach ($stmt->fetchAll() as $r) {
            $base[(int) $r['worker_id']] = [
                'worker_id' => (int) $r['worker_id'], 'name' => (string) $r['name'],
                'sales' => (int) $r['sales'], 'revenue' => (float) $r['revenue'],
                'units' => 0, 'profit' => 0.0, 'aov' => 0.0,
            ];
        }
        // Units + cogs per worker.
        $sql2 = "SELECT s.worker_id, COALESCE(SUM(si.qty),0) AS units,
                        COALESCE(SUM(" . COST_EXPR . " * si.qty),0) AS cogs
                 FROM sales s JOIN sale_items si ON si.sale_id = s.id JOIN products p ON p.id = si.product_id
                 WHERE s.cancelled = 0 AND s.created_at BETWEEN ? AND ? $scopeSql
                 GROUP BY s.worker_id";
        $stmt2 = db()->prepare($sql2);
        $stmt2->execute(array_merge([$from, $to], $scopeParams));
        foreach ($stmt2->fetchAll() as $r) {
            $wid = (int) $r['worker_id'];
            if (isset($base[$wid])) {
                $base[$wid]['units'] = (int) $r['units'];
                $base[$wid]['profit'] = round($base[$wid]['revenue'] - (float) $r['cogs'], 2);
            }
        }
        foreach ($base as $wid => $w) {
            $base[$wid]['aov'] = $w['sales'] > 0 ? round($w['revenue'] / $w['sales'], 2) : 0.0;
        }
        respond(array_values($base));
    }

    case 'branch_compare': {
        // Admin-oriented; managers see only their own branch row.
        $sql = "SELECT s.branch_id, b.name,
                       COUNT(DISTINCT s.id) AS sales,
                       COALESCE(SUM(s.net_amount),0) AS revenue
                FROM sales s JOIN branches b ON b.id = s.branch_id
                WHERE s.cancelled = 0 AND s.created_at BETWEEN ? AND ? $scopeSql
                GROUP BY s.branch_id, b.name ORDER BY revenue DESC";
        $stmt = db()->prepare($sql);
        $stmt->execute(array_merge([$from, $to], $scopeParams));
        $base = [];
        foreach ($stmt->fetchAll() as $r) {
            $base[(int) $r['branch_id']] = [
                'branch_id' => (int) $r['branch_id'], 'name' => (string) $r['name'],
                'sales' => (int) $r['sales'], 'revenue' => (float) $r['revenue'],
                'units' => 0, 'profit' => 0.0,
            ];
        }
        $sql2 = "SELECT s.branch_id, COALESCE(SUM(si.qty),0) AS units,
                        COALESCE(SUM(" . COST_EXPR . " * si.qty),0) AS cogs
                 FROM sales s JOIN sale_items si ON si.sale_id = s.id JOIN products p ON p.id = si.product_id
                 WHERE s.cancelled = 0 AND s.created_at BETWEEN ? AND ? $scopeSql
                 GROUP BY s.branch_id";
        $stmt2 = db()->prepare($sql2);
        $stmt2->execute(array_merge([$from, $to], $scopeParams));
        foreach ($stmt2->fetchAll() as $r) {
            $bid = (int) $r['branch_id'];
            if (isset($base[$bid])) {
                $base[$bid]['units'] = (int) $r['units'];
                $base[$bid]['profit'] = round($base[$bid]['revenue'] - (float) $r['cogs'], 2);
            }
        }
        respond(array_values($base));
    }

    case 'payment_breakdown': {
        $sql = "SELECT s.payment_method AS method,
                       COUNT(*) AS count, COALESCE(SUM(s.net_amount),0) AS amount
                FROM sales s
                WHERE s.cancelled = 0 AND s.created_at BETWEEN ? AND ? $scopeSql
                GROUP BY s.payment_method ORDER BY amount DESC";
        $stmt = db()->prepare($sql);
        $stmt->execute(array_merge([$from, $to], $scopeParams));
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = ['method' => (string) $r['method'], 'count' => (int) $r['count'], 'amount' => (float) $r['amount']];
        }
        respond($out);
    }

    case 'category_performance': {
        $sql = "SELECT COALESCE(p.category,'Uncategorized') AS category,
                       SUM(si.qty) AS units,
                       SUM(si.line_total) AS revenue,
                       SUM(" . COST_EXPR . " * si.qty) AS cogs
                FROM sales s
                JOIN sale_items si ON si.sale_id = s.id
                JOIN products p ON p.id = si.product_id
                WHERE s.cancelled = 0 AND s.created_at BETWEEN ? AND ? $scopeSql
                GROUP BY category ORDER BY revenue DESC";
        $stmt = db()->prepare($sql);
        $stmt->execute(array_merge([$from, $to], $scopeParams));
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $rev = (float) $r['revenue'];
            $cogs = (float) $r['cogs'];
            $profit = round($rev - $cogs, 2);
            $out[] = [
                'category' => (string) $r['category'], 'units' => (int) $r['units'],
                'revenue' => $rev, 'cogs' => round($cogs, 2), 'profit' => $profit,
                'margin' => $rev > 0 ? round($profit / $rev * 100, 1) : 0.0,
            ];
        }
        respond($out);
    }

    case 'inventory_health': {
        // Stock valuation + counts (branch-scoped for managers).
        [$bScope, $bParams] = is_admin($user)
            ? ((($b = query('branch_id')) !== null && $b !== '') ? ['AND bs.branch_id = ?', [(int) $b]] : ['', []])
            : ['AND bs.branch_id = ?', [(int) $user['branch_id']]];

        $val = db()->prepare(
            "SELECT COALESCE(SUM(bs.qty),0) AS units,
                    COALESCE(SUM(bs.qty * p.cost),0) AS cost_value,
                    COALESCE(SUM(bs.qty * p.price),0) AS retail_value
             FROM branch_stock bs
             JOIN product_variants pv ON pv.id = bs.variant_id
             JOIN products p ON p.id = pv.product_id
             WHERE p.deleted_at IS NULL $bScope"
        );
        $val->execute($bParams);
        $v = $val->fetch();

        // Per-product totals for low/out counts.
        $prod = db()->prepare(
            "SELECT p.id, p.reorder_point, COALESCE(SUM(bs.qty),0) AS total
             FROM products p
             LEFT JOIN product_variants pv ON pv.product_id = p.id
             LEFT JOIN branch_stock bs ON bs.variant_id = pv.id " .
             (str_contains($bScope, 'bs.branch_id') ? 'AND bs.branch_id = ?' : '') . "
             WHERE p.deleted_at IS NULL
             GROUP BY p.id, p.reorder_point"
        );
        $prod->execute($bParams);
        $low = $out = $total = 0;
        foreach ($prod->fetchAll() as $r) {
            $total++;
            $t = (int) $r['total'];
            if ($t <= 0) {
                $out++;
            } elseif ($t <= (int) $r['reorder_point']) {
                $low++;
            }
        }

        // Dead stock: products with no sales in the window.
        $dead = db()->prepare(
            "SELECT COUNT(*) AS c FROM products p
             WHERE p.deleted_at IS NULL AND p.id NOT IN (
                SELECT DISTINCT si.product_id FROM sales s
                JOIN sale_items si ON si.sale_id = s.id
                WHERE s.cancelled = 0 AND s.created_at BETWEEN ? AND ? $scopeSql
             )"
        );
        $dead->execute(array_merge([$from, $to], $scopeParams));
        $deadCount = (int) $dead->fetch()['c'];

        // Health score: penalize out + low against catalog size.
        $score = 100;
        if ($total > 0) {
            $score = (int) round(100 - ($out * 100.0 / $total) * 0.6 - ($low * 100.0 / $total) * 0.3);
            $score = max(0, min(100, $score));
        }
        respond([
            'units' => (int) $v['units'],
            'cost_value' => (float) $v['cost_value'],
            'retail_value' => (float) $v['retail_value'],
            'products' => $total, 'low' => $low, 'out' => $out, 'dead' => $deadCount,
            'score' => $score,
        ]);
    }

    case 'worker_kpi': {
        // Target worker: managers/admin may pass ?worker_id; default = self.
        $wid = (int) query('worker_id', (string) $user['id']);
        if ($user['role'] === 'worker') {
            // Workers may ONLY see their own scorecard — never a co-worker's.
            $wid = (int) $user['id'];
        } elseif (!is_admin($user) && $wid !== (int) $user['id']) {
            // Managers may only view own-branch workers' KPI.
            $chk = db()->prepare('SELECT branch_id FROM users WHERE id = ? AND deleted_at IS NULL');
            $chk->execute([$wid]);
            $tb = $chk->fetch();
            if (!$tb || (int) $tb['branch_id'] !== (int) $user['branch_id']) {
                respond_error('You do not have permission to do that.', 403, 'forbidden');
            }
        }

        $today = date('Y-m-d');
        $monthStart = date('Y-m-01');

        $q = db()->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN DATE(created_at)=? THEN net_amount END),0) AS today_sales,
                COALESCE(SUM(CASE WHEN created_at>=? THEN net_amount END),0) AS month_sales
             FROM sales WHERE worker_id = ? AND cancelled = 0"
        );
        $q->execute([$today, $monthStart . ' 00:00:00', $wid]);
        $s = $q->fetch();

        // Units today/month.
        $qu = db()->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN DATE(s.created_at)=? THEN si.qty END),0) AS today_units,
                COALESCE(SUM(CASE WHEN s.created_at>=? THEN si.qty END),0) AS month_units
             FROM sales s JOIN sale_items si ON si.sale_id = s.id
             WHERE s.worker_id = ? AND s.cancelled = 0"
        );
        $qu->execute([$today, $monthStart . ' 00:00:00', $wid]);
        $u = $qu->fetch();

        // Targets for this month.
        $m = (int) date('n');
        $y = (int) date('Y');
        $t = db()->prepare(
            'SELECT target_type, target_amount, target_units FROM performance_targets
             WHERE user_id = ? AND month = ? AND year = ?'
        );
        $t->execute([$wid, $m, $y]);
        $daily = $monthly = null;
        $dailyUnits = $monthlyUnits = null;
        foreach ($t->fetchAll() as $row) {
            if ($row['target_type'] === 'daily') {
                $daily = $row['target_amount'] !== null ? (float) $row['target_amount'] : null;
                $dailyUnits = $row['target_units'] !== null ? (int) $row['target_units'] : null;
            } else {
                $monthly = $row['target_amount'] !== null ? (float) $row['target_amount'] : null;
                $monthlyUnits = $row['target_units'] !== null ? (int) $row['target_units'] : null;
            }
        }

        respond([
            'worker_id'      => $wid,
            'today_sales'    => (float) $s['today_sales'],
            'today_units'    => (int) $u['today_units'],
            'month_sales'    => (float) $s['month_sales'],
            'month_units'    => (int) $u['month_units'],
            'daily_target'   => $daily,
            'daily_target_units' => $dailyUnits,
            'monthly_target' => $monthly,
            'monthly_target_units' => $monthlyUnits,
        ]);
    }

    case 'sell_through': {
        // Apparel KPI: units sold ÷ (sold + on-hand) × 100. Proxy for true sell-through
        // (no goods-received log in schema). Branch-scoped.
        [$bScope, $bParams] = perf_stock_scope($user);
        $s = db()->prepare(
            "SELECT COALESCE(p.category,'Uncategorized') AS category, COALESCE(SUM(si.qty),0) AS sold
             FROM sales s JOIN sale_items si ON si.sale_id = s.id JOIN products p ON p.id = si.product_id
             WHERE s.cancelled = 0 AND s.created_at BETWEEN ? AND ? $scopeSql
             GROUP BY category"
        );
        $s->execute(array_merge([$from, $to], $scopeParams));
        $sold = [];
        foreach ($s->fetchAll() as $r) {
            $sold[$r['category']] = (int) $r['sold'];
        }
        $o = db()->prepare(
            "SELECT COALESCE(p.category,'Uncategorized') AS category, COALESCE(SUM(bs.qty),0) AS on_hand
             FROM branch_stock bs JOIN product_variants pv ON pv.id = bs.variant_id JOIN products p ON p.id = pv.product_id
             WHERE p.deleted_at IS NULL $bScope
             GROUP BY category"
        );
        $o->execute($bParams);
        $onhand = [];
        foreach ($o->fetchAll() as $r) {
            $onhand[$r['category']] = (int) $r['on_hand'];
        }
        $cats = array_values(array_unique(array_merge(array_keys($sold), array_keys($onhand))));
        $rows = [];
        $totSold = 0;
        $totBase = 0;
        foreach ($cats as $c) {
            $sd = $sold[$c] ?? 0;
            $oh = $onhand[$c] ?? 0;
            $base = $sd + $oh;
            $rows[] = ['category' => $c, 'sold' => $sd, 'on_hand' => $oh, 'pct' => $base > 0 ? round($sd / $base * 100, 1) : 0.0];
            $totSold += $sd;
            $totBase += $base;
        }
        usort($rows, fn($a, $b) => $b['pct'] <=> $a['pct']);
        respond(['overall_pct' => $totBase > 0 ? round($totSold / $totBase * 100, 1) : 0.0, 'by_category' => $rows]);
    }

    case 'size_curve': {
        // Per-size sell-through — leverages the per-size variant model (the clothing-brand differentiator).
        [$bScope, $bParams] = perf_stock_scope($user);
        $s = db()->prepare(
            "SELECT pv.size AS size, COALESCE(SUM(si.qty),0) AS sold
             FROM sales s JOIN sale_items si ON si.sale_id = s.id JOIN product_variants pv ON pv.id = si.variant_id
             WHERE s.cancelled = 0 AND s.created_at BETWEEN ? AND ? $scopeSql
             GROUP BY pv.size"
        );
        $s->execute(array_merge([$from, $to], $scopeParams));
        $sold = [];
        foreach ($s->fetchAll() as $r) {
            $sold[$r['size']] = (int) $r['sold'];
        }
        $o = db()->prepare(
            "SELECT pv.size AS size, COALESCE(SUM(bs.qty),0) AS on_hand
             FROM branch_stock bs JOIN product_variants pv ON pv.id = bs.variant_id JOIN products p ON p.id = pv.product_id
             WHERE p.deleted_at IS NULL $bScope
             GROUP BY pv.size"
        );
        $o->execute($bParams);
        $onhand = [];
        foreach ($o->fetchAll() as $r) {
            $onhand[$r['size']] = (int) $r['on_hand'];
        }
        $sizes = array_values(array_unique(array_merge(array_keys($sold), array_keys($onhand))));
        $rows = [];
        $pcts = [];
        foreach ($sizes as $sz) {
            $sd = $sold[$sz] ?? 0;
            $oh = $onhand[$sz] ?? 0;
            $base = $sd + $oh;
            $pct = $base > 0 ? round($sd / $base * 100, 1) : 0.0;
            $rows[] = ['size' => $sz, 'sold' => $sd, 'on_hand' => $oh, 'pct' => $pct];
            if ($base > 0) {
                $pcts[] = $pct;
            }
        }
        $order = ['XS' => 1, 'S' => 2, 'M' => 3, 'L' => 4, 'XL' => 5, 'XXL' => 6, 'XXXL' => 7, 'FREE' => 50, 'ONE SIZE' => 51];
        usort($rows, function ($a, $b) use ($order) {
            $ra = $order[strtoupper($a['size'])] ?? 40;
            $rb = $order[strtoupper($b['size'])] ?? 40;
            return $ra === $rb ? strcmp($a['size'], $b['size']) : $ra <=> $rb;
        });
        respond(['sizes' => $rows, 'variance_pct' => $pcts ? round(max($pcts) - min($pcts), 1) : 0.0]);
    }

    case 'turnover': {
        // Inventory turnover = period COGS ÷ inventory cost value; days-of-cover = on-hand ÷ daily sales rate.
        // Avg inventory approximated by current cost value (no historical stock snapshots).
        [$bScope, $bParams] = perf_stock_scope($user);
        $c = db()->prepare(
            "SELECT COALESCE(SUM(" . COST_EXPR . " * si.qty),0) AS cogs, COALESCE(SUM(si.qty),0) AS units
             FROM sales s JOIN sale_items si ON si.sale_id = s.id JOIN products p ON p.id = si.product_id
             WHERE s.cancelled = 0 AND s.created_at BETWEEN ? AND ? $scopeSql"
        );
        $c->execute(array_merge([$from, $to], $scopeParams));
        $cr = $c->fetch();
        $cogs = (float) $cr['cogs'];
        $unitsSold = (int) $cr['units'];
        $v = db()->prepare(
            "SELECT COALESCE(SUM(bs.qty),0) AS units, COALESCE(SUM(bs.qty * p.cost),0) AS cost_value
             FROM branch_stock bs JOIN product_variants pv ON pv.id = bs.variant_id JOIN products p ON p.id = pv.product_id
             WHERE p.deleted_at IS NULL $bScope"
        );
        $v->execute($bParams);
        $vr = $v->fetch();
        $onHand = (int) $vr['units'];
        $costValue = (float) $vr['cost_value'];
        $periodDays = max(1, (int) round((strtotime($to) - strtotime($from)) / 86400) + 1);
        $dailyUnits = $unitsSold / $periodDays;
        respond([
            'turnover'      => $costValue > 0 ? round($cogs / $costValue, 2) : 0.0,
            'days_of_cover' => $dailyUnits > 0 ? round($onHand / $dailyUnits, 1) : null,
            'cogs'          => round($cogs, 2),
            'cost_value'    => $costValue,
            'units_sold'    => $unitsSold,
            'on_hand_units' => $onHand,
            'period_days'   => $periodDays,
        ]);
    }

    case 'dead_stock': {
        // Products with zero sales in the window — count, capital tied up, and the worst offenders.
        [$bScope, $bParams] = perf_stock_scope($user);
        $joinBranch = str_contains($bScope, 'bs.branch_id') ? 'AND bs.branch_id = ?' : '';
        $sql = "SELECT p.id, p.name, p.cost,
                       COALESCE(SUM(bs.qty),0) AS qty,
                       (SELECT MAX(s2.created_at) FROM sales s2 JOIN sale_items si2 ON si2.sale_id = s2.id
                         WHERE si2.product_id = p.id AND s2.cancelled = 0) AS last_sold
                FROM products p
                LEFT JOIN product_variants pv ON pv.product_id = p.id
                LEFT JOIN branch_stock bs ON bs.variant_id = pv.id $joinBranch
                WHERE p.deleted_at IS NULL
                  AND p.id NOT IN (
                    SELECT DISTINCT si.product_id FROM sales s JOIN sale_items si ON si.sale_id = s.id
                    WHERE s.cancelled = 0 AND s.created_at BETWEEN ? AND ? $scopeSql
                  )
                GROUP BY p.id, p.name, p.cost
                HAVING qty > 0
                ORDER BY (qty * p.cost) DESC";
        $stmt = db()->prepare($sql);
        $stmt->execute(array_merge($bParams, [$from, $to], $scopeParams));
        $all = $stmt->fetchAll();
        $items = [];
        $value = 0.0;
        $units = 0;
        foreach ($all as $r) {
            $q = (int) $r['qty'];
            $val = round($q * (float) $r['cost'], 2);
            $value += $val;
            $units += $q;
            if (count($items) < 25) {
                $items[] = ['product_id' => (int) $r['id'], 'name' => (string) $r['name'], 'qty' => $q, 'value' => $val, 'last_sold' => $r['last_sold']];
            }
        }
        respond(['count' => count($all), 'value' => round($value, 2), 'units' => $units, 'items' => $items]);
    }

    case 'reorder_suggestions': {
        // Products whose branch-scoped on-hand has fallen to/below their reorder point.
        [$bScope, $bParams] = perf_stock_scope($user);
        $joinBranch = str_contains($bScope, 'bs.branch_id') ? 'AND bs.branch_id = ?' : '';
        $sql = "SELECT p.id, p.name, p.sku, p.reorder_point, COALESCE(SUM(bs.qty),0) AS on_hand
                FROM products p
                LEFT JOIN product_variants pv ON pv.product_id = p.id
                LEFT JOIN branch_stock bs ON bs.variant_id = pv.id $joinBranch
                WHERE p.deleted_at IS NULL AND p.reorder_point > 0
                GROUP BY p.id, p.name, p.sku, p.reorder_point
                HAVING COALESCE(SUM(bs.qty),0) <= p.reorder_point
                ORDER BY (p.reorder_point - COALESCE(SUM(bs.qty),0)) DESC
                LIMIT 50";
        $stmt = db()->prepare($sql);
        $stmt->execute($bParams);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $oh = (int) $r['on_hand'];
            $rp = (int) $r['reorder_point'];
            $out[] = [
                'product_id'    => (int) $r['id'],
                'name'          => (string) $r['name'],
                'sku'           => (string) $r['sku'],
                'on_hand'       => $oh,
                'reorder_point' => $rp,
                'suggested_qty' => max(0, $rp - $oh),
            ];
        }
        respond($out);
    }

    case 'customer_summary': {
        // Marketing: capture rate, new vs returning, repeat rate (branch-scoped via sales).
        $cap = db()->prepare(
            "SELECT COUNT(*) AS total_orders, COALESCE(SUM(s.customer_id IS NOT NULL),0) AS with_customer
             FROM sales s
             WHERE s.cancelled = 0 AND s.created_at BETWEEN ? AND ? $scopeSql"
        );
        $cap->execute(array_merge([$from, $to], $scopeParams));
        $capRow = $cap->fetch();
        $totalOrders = (int) $capRow['total_orders'];
        $withCustomer = (int) $capRow['with_customer'];

        // Customers active in the window (scoped).
        $win = db()->prepare(
            "SELECT DISTINCT s.customer_id
             FROM sales s
             WHERE s.cancelled = 0 AND s.customer_id IS NOT NULL AND s.created_at BETWEEN ? AND ? $scopeSql"
        );
        $win->execute(array_merge([$from, $to], $scopeParams));
        $windowed = array_map(fn ($r) => (int) $r['customer_id'], $win->fetchAll());

        // First-ever purchase per customer (scoped) → classify new vs returning.
        $first = db()->prepare(
            "SELECT s.customer_id, MIN(s.created_at) AS first_ever
             FROM sales s
             WHERE s.cancelled = 0 AND s.customer_id IS NOT NULL $scopeSql
             GROUP BY s.customer_id"
        );
        $first->execute($scopeParams);
        $firstMap = [];
        foreach ($first->fetchAll() as $r) {
            $firstMap[(int) $r['customer_id']] = (string) $r['first_ever'];
        }

        $new = 0;
        $returning = 0;
        foreach ($windowed as $cid) {
            $fe = $firstMap[$cid] ?? $from;
            if ($fe >= $from) {
                $new++;
            } else {
                $returning++;
            }
        }
        $unique = count($windowed);
        respond([
            'unique_customers'    => $unique,
            'new_customers'       => $new,
            'returning_customers' => $returning,
            'repeat_rate'         => $unique > 0 ? round($returning / $unique * 100, 1) : 0.0,
            'total_customers'     => count($firstMap),
            'orders'              => $totalOrders,
            'orders_with_customer' => $withCustomer,
            'capture_rate'        => $totalOrders > 0 ? round($withCustomer / $totalOrders * 100, 1) : 0.0,
        ]);
    }

    case 'top_customers': {
        $stmt = db()->prepare(
            "SELECT s.customer_id, c.name, c.phone,
                    COUNT(*) AS orders, COALESCE(SUM(s.net_amount),0) AS spent
             FROM sales s JOIN customers c ON c.id = s.customer_id
             WHERE s.cancelled = 0 AND s.created_at BETWEEN ? AND ? $scopeSql
             GROUP BY s.customer_id, c.name, c.phone
             ORDER BY spent DESC
             LIMIT 8"
        );
        $stmt->execute(array_merge([$from, $to], $scopeParams));
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'customer_id' => (int) $r['customer_id'],
                'name'        => (string) $r['name'],
                'phone'       => $r['phone'],
                'orders'      => (int) $r['orders'],
                'spent'       => (float) $r['spent'],
            ];
        }
        respond($out);
    }

    default:
        respond_error('Unknown action.', 404, 'unknown_action');
}
