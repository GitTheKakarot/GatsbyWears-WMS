<?php
declare(strict_types=1);

// Defense-in-depth: lib files load only via config.php (which defines WMS_ROOT).
if (PHP_SAPI !== 'cli' && !defined('WMS_ROOT')) { http_response_code(403); exit; }

/**
 * GatsbyWears WMS 2.0 — secure product-image ingestion.
 *
 * save_product_image() takes a raw uploaded file (or raw bytes) and:
 *  - validates the REAL MIME (finfo, not the client-supplied type),
 *  - enforces a 10 MB cap and an image whitelist (jpeg/png/webp),
 *  - decodes via GD and RE-ENCODES to WebP (strips EXIF/embedded payloads,
 *    neutralizing polyglot / "image-with-PHP" attacks),
 *  - downscales to a sane max dimension,
 *  - writes to uploads/ under a random, unguessable filename,
 *  - returns the relative web path (uploads/xxxx.webp) for product_images.path.
 *
 * On any failure it returns null (caller decides how to surface the error).
 * Depends on: GD with WebP support (Hostinger has it; locally enable with -d extension=gd).
 */

const UPLOAD_MAX_BYTES = 10 * 1024 * 1024;   // 10 MB
const UPLOAD_MAX_DIM   = 1600;               // px, longest edge
const UPLOAD_WEBP_Q    = 82;                 // WebP quality

/**
 * Absolute path to the uploads directory (created if missing).
 */
function uploads_dir(): string
{
    // api/lib/upload.php → project root is two levels up.
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Validate + re-encode raw image bytes to WebP. Returns relative path or null.
 */
function save_image_bytes(string $bytes): ?string
{
    if ($bytes === '' || strlen($bytes) > UPLOAD_MAX_BYTES) {
        return null;
    }

    // Real MIME from content, not the client.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->buffer($bytes);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return null;
    }

    if (!function_exists('imagecreatefromstring')) {
        error_log('[WMS][upload] GD not available');
        return null;
    }

    $src = @imagecreatefromstring($bytes);
    if ($src === false) {
        return null; // not a decodable image
    }

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 1 || $h < 1) {
        imagedestroy($src);
        return null;
    }

    // Downscale if larger than the max edge (preserve aspect ratio).
    $scale = min(1.0, UPLOAD_MAX_DIM / max($w, $h));
    if ($scale < 1.0) {
        $nw  = max(1, (int) round($w * $scale));
        $nh  = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        $src = $dst;
    } else {
        imagealphablending($src, false);
        imagesavealpha($src, true);
    }

    $name = bin2hex(random_bytes(16)) . '.webp';
    $abs  = uploads_dir() . DIRECTORY_SEPARATOR . $name;

    $ok = imagewebp($src, $abs, UPLOAD_WEBP_Q);
    imagedestroy($src);
    if (!$ok || !is_file($abs)) {
        return null;
    }

    return 'uploads/' . $name;
}

/**
 * Save from a PHP $_FILES entry. Returns relative path or null.
 *
 * @param array{tmp_name?:string,error?:int,size?:int} $file
 */
function save_product_image(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || ($file['size'] ?? 0) > UPLOAD_MAX_BYTES) {
        return null;
    }
    // Defense-in-depth: only accept genuine uploaded temp files in web context.
    if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server' && !is_uploaded_file($tmp)) {
        return null;
    }
    $bytes = @file_get_contents($tmp);
    if ($bytes === false) {
        return null;
    }
    return save_image_bytes($bytes);
}

/**
 * Save from a base64 data URL or raw base64 (web paste / Capacitor camera).
 * Accepts "data:image/...;base64,XXXX" or bare base64. Returns path or null.
 */
function save_product_image_base64(string $data): ?string
{
    if (str_contains($data, ',') && str_starts_with($data, 'data:')) {
        $data = substr($data, strpos($data, ',') + 1);
    }
    $bytes = base64_decode($data, true);
    if ($bytes === false) {
        return null;
    }
    return save_image_bytes($bytes);
}

/**
 * Best-effort delete of an uploaded image by its stored relative path.
 * Guards against path traversal (only files directly under uploads/).
 */
function delete_product_image(string $relPath): void
{
    if (!str_starts_with($relPath, 'uploads/')) {
        return;
    }
    $name = basename($relPath);
    $abs  = uploads_dir() . DIRECTORY_SEPARATOR . $name;
    if (is_file($abs)) {
        @unlink($abs);
    }
}
