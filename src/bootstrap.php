<?php

declare(strict_types=1);

// APP_ROOT is always the parent of the src/ directory, which works in both
// nested layout (public/ document root) and flat layout (everything in one folder).
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

function env_value(string $key, ?string $default = null): ?string
{
    static $loaded = false;

    if (!$loaded) {
        $envFile = __DIR__ . '/../.env';
        if (is_file($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = array_map('trim', explode('=', $line, 2));
                $_ENV[$k] = $v;
            }
        }
        $loaded = true;
    }

    if (isset($_ENV[$key])) {
        return $_ENV[$key];
    }
    $env = getenv($key);
    return $env !== false ? $env : $default;
}

/**
 * DB-backed settings cache. Loaded once from app_settings table, overrides .env values.
 */
function db_setting(string $key, ?string $value = null, ?PDO $pdo = null): ?string
{
    static $cache = null;
    static $dbHandle = null;

    // Initialize DB handle
    if ($pdo !== null) {
        $dbHandle = $pdo;
    }

    // Write mode
    if ($value !== null && $dbHandle) {
        $stmt = $dbHandle->prepare('INSERT INTO app_settings(setting_key, setting_value, updated_at) VALUES(:k,:v,:u) ON CONFLICT(setting_key) DO UPDATE SET setting_value = :v2, updated_at = :u2');
        $stmt->execute([':k' => $key, ':v' => $value, ':u' => gmdate(DATE_ATOM), ':v2' => $value, ':u2' => gmdate(DATE_ATOM)]);
        if ($cache !== null) {
            $cache[$key] = $value;
        }
        return $value;
    }

    // Load cache on first read
    if ($cache === null && $dbHandle) {
        try {
            $rows = $dbHandle->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
            $cache = $rows ?: [];
        } catch (\Throwable) {
            $cache = [];
        }
    }

    if ($cache !== null && isset($cache[$key]) && $cache[$key] !== '') {
        return $cache[$key];
    }
    return null;
}

/**
 * Get a config value: DB setting > .env > default.
 */
function app_config(string $key, ?string $default = null): ?string
{
    return db_setting($key) ?? env_value($key, $default);
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_PRETTY_PRINT);
}

function request_json(): array
{
    $body = file_get_contents('php://input') ?: '{}';
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Send CSV response with appropriate headers.
 */
function csv_response(string $csv, string $filename): void
{
    http_response_code(200);
    header('Content-Type: text/csv');
    $filename = str_replace(["\r", "\n", '"'], '', $filename);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $csv;
}

/**
 * Set standard security headers on every response.
 */
function security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

/**
 * Serve an uploaded file from the data/uploads directory.
 */
function serve_upload(string $path, string $dataDir): void
{
    // $path is like /uploads/abc123.jpg or /uploads/thumbs/abc123.jpg
    $file = $dataDir . $path;
    if (!is_file($file)) {
        http_response_code(404);
        echo 'Not found';
        return;
    }
    // Prevent path traversal (e.g. /uploads/../../.env)
    $real = realpath($file);
    $uploadsRoot = realpath($dataDir . '/uploads');
    if (!$real || !$uploadsRoot || !str_starts_with($real, $uploadsRoot . '/')) {
        http_response_code(403);
        echo 'Forbidden';
        return;
    }

    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = finfo_file($finfo, $file);
        finfo_close($finfo);
        if ($detected) {
            $mime = $detected;
        }
    } else {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'mp4' => 'video/mp4', 'pdf' => 'application/pdf'];
        $mime = $mimes[$ext] ?? $mime;
    }

    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=604800');
    header('Content-Length: ' . filesize($file));
    readfile($file);
}
