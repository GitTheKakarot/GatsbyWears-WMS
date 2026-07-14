<?php
declare(strict_types=1);

/**
 * GatsbyWears WMS 2.0 — API bootstrap.
 *
 * EVERY endpoint does `require __DIR__ . '/config.php';` as its first line.
 * This file:
 *   1. Loads .env (project root) into the environment.
 *   2. Configures error handling (log, never display in prod).
 *   3. Sends strict security headers + HSTS.
 *   4. Applies a strict CORS allowlist + handles OPTIONS preflight.
 *   5. Requires the lib/ helpers (db, jwt, respond, guards, audit, upload).
 *
 * Order matters: .env first (so env_get works), then libs.
 */

/* ---- 0. paths ---- */
define('WMS_ROOT', dirname(__DIR__));          // project root
define('WMS_API', __DIR__);                    // api/

/* ---- 1. .env loader (minimal, safe) ---- */
(function (): void {
    $envFile = WMS_ROOT . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($envFile) || !is_readable($envFile)) {
        return; // rely on real environment (e.g. CLI exports) if no file
    }
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        // Strip surrounding quotes.
        if (strlen($val) >= 2
            && (($val[0] === '"' && substr($val, -1) === '"')
             || ($val[0] === "'" && substr($val, -1) === "'"))) {
            $val = substr($val, 1, -1);
        }
        if ($key === '') {
            continue;
        }
        // Don't override an already-set real env var (CLI export wins).
        if (getenv($key) === false && !isset($_ENV[$key])) {
            putenv("$key=$val");
            $_ENV[$key] = $val;
        }
    }
})();

/* ---- 2. error handling ---- */
require WMS_API . '/lib/db.php'; // defines env_get() / app_is_local()

// Pin PHP's clock to Bangladesh (+06:00) so date()/strtotime() agree with the
// MySQL session time zone — critical for date windows, invoice prefixes, "today".
date_default_timezone_set('Asia/Dhaka');

$isLocal = app_is_local();
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', $isLocal ? '1' : '0'); // never leak in prod

// Convert uncaught throwables into a generic JSON 500 (no stack leak in prod).
set_exception_handler(function (\Throwable $e) use ($isLocal): void {
    error_log('[WMS][uncaught] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    $msg = $isLocal ? $e->getMessage() : 'Something went wrong. Please try again.';
    echo json_encode(['ok' => false, 'error' => $msg, 'code' => 'server_error']);
    exit;
});

/* ---- 3. security headers ---- */
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
if (!$isLocal) {
    // HSTS only over real HTTPS in production.
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

/* ---- 4. strict CORS ---- */
(function (): void {
    $allowed = array_filter(array_map('trim', explode(',', (string) env_get('APP_ORIGIN', ''))));
    // Local dev: also allow the Vite dev origins.
    if (app_is_local()) {
        $allowed = array_merge($allowed, [
            'http://localhost:5173', 'http://127.0.0.1:5173',
            'http://localhost:4173', 'http://127.0.0.1:4173',
        ]);
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
        header('Access-Control-Max-Age: 86400');
    }
    // Preflight ends here regardless.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
})();

/* ---- 5. remaining libs ---- */
require WMS_API . '/lib/jwt.php';
require WMS_API . '/lib/respond.php';
require WMS_API . '/lib/guards.php';
require WMS_API . '/lib/audit.php';
require WMS_API . '/lib/upload.php';
