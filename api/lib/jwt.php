<?php
declare(strict_types=1);

// Defense-in-depth: lib files load only via config.php (which defines WMS_ROOT).
if (PHP_SAPI !== 'cli' && !defined('WMS_ROOT')) { http_response_code(403); exit; }

/**
 * GatsbyWears WMS 2.0 — minimal JWT (HS256).
 *
 * No external library: a small, audited HS256 implementation.
 *  - Signing secret from env JWT_SECRET (never hardcoded).
 *  - Constant-time signature compare via hash_equals().
 *  - Verifies exp (with small clock-skew leeway) and nbf/iat.
 *  - base64url encoding (RFC 7515), no padding.
 *
 * Payload we issue (auth.php): { sub, role, branch_id, name, iat, exp }.
 * jwt_verify() returns the decoded claims array or null on ANY failure.
 */

const JWT_ALG       = 'HS256';
const JWT_LEEWAY    = 30; // seconds of allowed clock skew

function jwt_b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function jwt_b64url_decode(string $txt): string|false
{
    $remainder = strlen($txt) % 4;
    if ($remainder) {
        $txt .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($txt, '-_', '+/'), true);
}

function jwt_secret(): string
{
    $secret = env_get('JWT_SECRET', '');
    if ($secret === null || strlen($secret) < 16) {
        // Refuse to operate with a weak/missing secret rather than sign insecurely.
        error_log('[WMS][jwt] JWT_SECRET missing or too short');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Server configuration error', 'code' => 'jwt_config']);
        exit;
    }
    return $secret;
}

/**
 * Create a signed JWT. $ttl overrides env TOKEN_TTL (seconds).
 */
function jwt_create(array $claims, ?int $ttl = null): string
{
    $now = time();
    $ttl = $ttl ?? (int) (env_get('TOKEN_TTL', '28800')); // default 8h
    $payload = array_merge($claims, [
        'iat' => $now,
        'nbf' => $now,
        'exp' => $now + $ttl,
    ]);

    $header = ['alg' => JWT_ALG, 'typ' => 'JWT'];
    $segments = [
        jwt_b64url_encode((string) json_encode($header, JSON_UNESCAPED_SLASHES)),
        jwt_b64url_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES)),
    ];
    $signingInput = implode('.', $segments);
    $signature = hash_hmac('sha256', $signingInput, jwt_secret(), true);
    $segments[] = jwt_b64url_encode($signature);

    return implode('.', $segments);
}

/**
 * Verify a JWT and return its claims, or null on any failure.
 * Failures: malformed, wrong alg, bad signature, expired, not-yet-valid.
 */
function jwt_verify(string $jwt): ?array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return null;
    }
    [$h64, $p64, $s64] = $parts;

    $headerJson = jwt_b64url_decode($h64);
    $payloadJson = jwt_b64url_decode($p64);
    $signature = jwt_b64url_decode($s64);
    if ($headerJson === false || $payloadJson === false || $signature === false) {
        return null;
    }

    $header = json_decode($headerJson, true);
    $claims = json_decode($payloadJson, true);
    if (!is_array($header) || !is_array($claims)) {
        return null;
    }

    // Pin algorithm — reject "none" and any non-HS256 to block alg-confusion.
    if (($header['alg'] ?? null) !== JWT_ALG) {
        return null;
    }

    // Recompute and constant-time compare the signature.
    $expected = hash_hmac('sha256', $h64 . '.' . $p64, jwt_secret(), true);
    if (!hash_equals($expected, $signature)) {
        return null;
    }

    $now = time();
    if (isset($claims['nbf']) && $now + JWT_LEEWAY < (int) $claims['nbf']) {
        return null;
    }
    if (isset($claims['iat']) && $now + JWT_LEEWAY < (int) $claims['iat']) {
        return null;
    }
    if (isset($claims['exp']) && $now - JWT_LEEWAY >= (int) $claims['exp']) {
        return null;
    }

    return $claims;
}
