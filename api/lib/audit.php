<?php
declare(strict_types=1);

// Defense-in-depth: lib files load only via config.php (which defines WMS_ROOT).
if (PHP_SAPI !== 'cli' && !defined('WMS_ROOT')) { http_response_code(403); exit; }

/**
 * GatsbyWears WMS 2.0 — audit trail (best-effort).
 *
 * log_audit() writes one row to `audit_logs`. It is BEST-EFFORT by contract:
 *  - It NEVER throws.
 *  - It NEVER rolls back the caller's real transaction.
 *  - A logging failure is swallowed (error_log only) so a sale/transfer/edit
 *    can still succeed even if the audit insert hiccups.
 *
 * Call it AFTER the real mutation, ideally inside the same transaction so the
 * trail commits atomically with the change — but if it fails, we degrade
 * gracefully rather than failing the business operation.
 *
 * Depends on: db.php (loaded by config.php first).
 *
 * @param array{
 *   action_type: string, entity_type: string,
 *   entity_id?: ?int, branch_id?: ?int, product_id?: ?int,
 *   old_qty?: ?int, new_qty?: ?int, changed_by?: ?int,
 *   reason?: ?string, metadata?: mixed
 * } $entry
 */
function log_audit(array $entry): void
{
    // Allowed enum domains (mirror schema.sql) — guard against bad inserts.
    static $actions = ['manual_edit', 'pos_sale', 'transfer_deduct', 'transfer_add', 'adjustment', 'auth'];
    static $entities = ['branch_stock', 'inventory', 'transfer', 'sales', 'auth'];

    try {
        $action = (string) ($entry['action_type'] ?? '');
        $entity = (string) ($entry['entity_type'] ?? '');
        if (!in_array($action, $actions, true) || !in_array($entity, $entities, true)) {
            error_log('[WMS][audit] skipped: bad action/entity ' . $action . '/' . $entity);
            return;
        }

        $metadata = $entry['metadata'] ?? null;
        if ($metadata !== null && !is_string($metadata)) {
            $metadata = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($metadata === false) {
                $metadata = null;
            }
        }

        $stmt = db()->prepare(
            'INSERT INTO audit_logs
                (action_type, entity_type, entity_id, branch_id, product_id,
                 old_qty, new_qty, changed_by, reason, metadata)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $action,
            $entity,
            isset($entry['entity_id'])  ? (int) $entry['entity_id']   : null,
            isset($entry['branch_id'])  ? (int) $entry['branch_id']   : null,
            isset($entry['product_id']) ? (int) $entry['product_id']  : null,
            isset($entry['old_qty'])    ? (int) $entry['old_qty']     : null,
            isset($entry['new_qty'])    ? (int) $entry['new_qty']     : null,
            isset($entry['changed_by']) ? (int) $entry['changed_by']  : null,
            isset($entry['reason'])     ? (string) $entry['reason']   : null,
            $metadata,
        ]);
    } catch (\Throwable $e) {
        // Best-effort: never propagate. Just record server-side.
        error_log('[WMS][audit] insert failed: ' . $e->getMessage());
    }
}
