<?php
declare(strict_types=1);

/**
 * GatsbyWears WMS 2.0 — realtime.php
 *
 * Action (matched by src/lib/api.ts realtime):
 *   GET → RealtimeTimestamps {
 *           products?, branches?, transfers?, sales?, users?, audit_logs?, server_time
 *         }
 *
 * Returns the latest mutation timestamp per entity so the client can poll (15s)
 * and refetch ONLY the slices whose timestamp advanced. Lightweight: pure MAX()
 * scans on indexed columns. server_time is always present.
 */

require __DIR__ . '/config.php';

require_auth();

$pdo = db();

/** Safe MAX() of a timestamp expression; null if table empty. */
function latest(PDO $pdo, string $sql): ?string
{
    try {
        $v = $pdo->query($sql)->fetch();
        $t = $v['t'] ?? null;
        return ($t === null || $t === '') ? null : (string) $t;
    } catch (\Throwable $e) {
        return null;
    }
}

$out = [
    'products'    => latest($pdo, 'SELECT MAX(updated_at) AS t FROM products'),
    'branches'    => latest($pdo, 'SELECT MAX(updated_at) AS t FROM branches'),
    'transfers'   => latest($pdo, 'SELECT MAX(updated_at) AS t FROM transfers'),
    // Sales are immutable except for cancellation — track both.
    'sales'       => latest($pdo, 'SELECT MAX(GREATEST(created_at, COALESCE(cancelled_at, created_at))) AS t FROM sales'),
    'users'       => latest($pdo, 'SELECT MAX(updated_at) AS t FROM users'),
    'audit_logs'  => latest($pdo, 'SELECT MAX(created_at) AS t FROM audit_logs'),
    'server_time' => date('Y-m-d H:i:s'),
];

respond($out);
