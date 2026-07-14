<?php
declare(strict_types=1);

// Defense-in-depth: lib files load only via config.php (which defines WMS_ROOT).
if (PHP_SAPI !== 'cli' && !defined('WMS_ROOT')) { http_response_code(403); exit; }

/**
 * GatsbyWears WMS 2.0 — JSON response + request-input helpers.
 *
 * Wire contract (the TS client in src/lib/api.ts depends on this exactly):
 *   success → { "ok": true,  "data": <T> }
 *   error   → { "ok": false, "error": "<msg>", "code": "<optional>" }
 *
 * Every endpoint terminates through respond() / respond_error().
 */

/**
 * Emit a success envelope and exit.
 */
function respond(mixed $data = null, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Emit an error envelope and exit. Keep messages generic & safe (no internals).
 */
function respond_error(string $message, int $status = 400, ?string $code = null): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $payload = ['ok' => false, 'error' => $message];
    if ($code !== null) {
        $payload['code'] = $code;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Decode the JSON request body once (cached). Returns [] when empty/invalid.
 */
function request_body(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $cached = [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        respond_error('Invalid JSON body.', 400, 'bad_json');
    }
    return $cached = $decoded;
}

/**
 * Read one field from the JSON body with an optional default.
 */
function body(string $key, mixed $default = null): mixed
{
    $b = request_body();
    return array_key_exists($key, $b) ? $b[$key] : $default;
}

/**
 * Read a query-string param (?key=...) with an optional default.
 */
function query(string $key, ?string $default = null): ?string
{
    $v = $_GET[$key] ?? null;
    if ($v === null) {
        return $default;
    }
    return is_string($v) ? $v : $default;
}

/**
 * Require a body field to be present and non-empty; error 422 otherwise.
 * Returns the raw value.
 */
function require_field(string $key): mixed
{
    $v = body($key);
    if ($v === null || (is_string($v) && trim($v) === '')) {
        respond_error("Missing required field: {$key}", 422, 'missing_field');
    }
    return $v;
}

/**
 * Coerce a value to a positive integer id, or error 422.
 */
function require_id(mixed $v, string $label = 'id'): int
{
    if (is_string($v)) {
        $v = trim($v);
    }
    if (!is_numeric($v) || (int) $v <= 0) {
        respond_error("Invalid {$label}.", 422, 'bad_id');
    }
    return (int) $v;
}

/**
 * The request HTTP method (GET/POST/PUT/DELETE).
 */
function method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}
