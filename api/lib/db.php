<?php
declare(strict_types=1);

// Defense-in-depth: lib files load only via config.php (which defines WMS_ROOT).
// Refuse direct web access even if .htaccess is ever misconfigured.
if (PHP_SAPI !== 'cli' && !defined('WMS_ROOT')) { http_response_code(403); exit; }

/**
 * GatsbyWears WMS 2.0 — PDO singleton.
 *
 * Reads connection settings from the environment (populated by config.php's
 * .env loader). Picks the local XAMPP throwaway DB when APP_ENV=local,
 * otherwise the production Hostinger credentials.
 *
 * Hard rules:
 *  - PDO with ERRMODE_EXCEPTION (no silent failures).
 *  - Prepared statements only (EMULATE_PREPARES = false).
 *  - Native typed fetches, utf8mb4, +06:00 session time zone.
 *  - Single shared connection per request (singleton).
 */

/**
 * Read an environment value, falling back to $_ENV / $_SERVER and a default.
 */
function env_get(string $key, ?string $default = null): ?string
{
    $val = getenv($key);
    if ($val === false || $val === '') {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? null;
    }
    if ($val === null || $val === '') {
        return $default;
    }
    return $val;
}

/**
 * Are we running against the local XAMPP throwaway DB?
 * Explicit APP_ENV=local wins; otherwise auto-detect a CLI/localhost dev server.
 */
function app_is_local(): bool
{
    $env = strtolower((string) env_get('APP_ENV', ''));
    if ($env === 'local') {
        return true;
    }
    if ($env === 'production') {
        return false;
    }
    // Auto-detect: PHP built-in server / CLI dev with no real host header.
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') {
        return true;
    }
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return $host === 'localhost' || str_starts_with($host, 'localhost:')
        || str_starts_with($host, '127.0.0.1');
}

/**
 * Return the shared PDO connection, creating it on first call.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $local = app_is_local();
    $host = $local ? env_get('DB_HOST_LOCAL', '127.0.0.1') : env_get('DB_HOST', 'localhost');
    $name = $local ? env_get('DB_NAME_LOCAL', 'gatsby_verify_tmp') : env_get('DB_NAME', '');
    $user = $local ? env_get('DB_USER_LOCAL', 'root') : env_get('DB_USER', '');
    $pass = $local ? env_get('DB_PASS_LOCAL', '') : env_get('DB_PASS', '');

    if ($name === null || $name === '') {
        http_response_code(500);
        // Never leak credentials/config in the message.
        echo json_encode(['ok' => false, 'error' => 'Server configuration error', 'code' => 'db_config']);
        exit;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name);
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
        // Force a deterministic session time zone for Bangladesh (+06:00).
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+06:00', NAMES utf8mb4",
    ];

    try {
        $pdo = new PDO($dsn, (string) $user, (string) $pass, $options);
    } catch (PDOException $e) {
        // Log the real cause server-side; return a generic error to the client.
        error_log('[WMS][db] connection failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database unavailable', 'code' => 'db_connect']);
        exit;
    }

    return $pdo;
}
