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
                // Strip surrounding quotes written by the installer
                if (strlen($v) >= 2 && (($v[0] === '"' && $v[-1] === '"') || ($v[0] === "'" && $v[-1] === "'"))) {
                    $v = substr($v, 1, -1);
                    $v = str_replace(['\\"', '\\\\'], ['"', '\\'], $v);
                }
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
        } catch (\Throwable $e) {
            $cache = [];
            error_log('db_setting init failed: ' . $e->getMessage());
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
    header('X-XSS-Protection: 0'); // Deprecated; CSP is the modern replacement
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    // CSP: allow inline styles for SPA, sandbox iframes for email preview, restrict everything else
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-src blob: data:; object-src 'none'; base-uri 'self'; form-action 'self'");
}

/**
 * Validate redirect URLs and optionally enforce same-host redirects.
 */
function sanitize_redirect_url(string $url, ?string $fallback = '/', bool $sameHostOnly = false): string
{
    $clean = str_replace(["\r", "\n"], '', trim($url));
    if ($clean === '') {
        return $fallback ?? '/';
    }

    $parsed = parse_url($clean);
    if (!is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host'])) {
        return $fallback ?? '/';
    }

    $scheme = strtolower((string)$parsed['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return $fallback ?? '/';
    }

    if ($sameHostOnly) {
        $appUrl = app_config('APP_URL', '');
        $appHost = (string)(parse_url((string)$appUrl, PHP_URL_HOST) ?? '');
        if ($appHost !== '' && !hash_equals(strtolower($appHost), strtolower((string)$parsed['host']))) {
            return $fallback ?? '/';
        }
    }

    return $clean;
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
    $size = filesize($file);
    if ($size !== false) {
        header('Content-Length: ' . $size);
    }
    if (@readfile($file) === false) {
        // File may have been deleted between check and read
        http_response_code(500);
    }
}
