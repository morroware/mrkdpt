<?php

declare(strict_types=1);

function register_settings_routes(Router $router, AiService $ai, Scheduler $scheduler, string $dataDir, PDO $pdo): void
{
    $router->get('/api/settings', function () use ($ai) {
        json_response([
            'business_name' => env_value('BUSINESS_NAME', 'My Small Business'),
            'business_industry' => env_value('BUSINESS_INDUSTRY', 'Local services'),
            'timezone' => env_value('TIMEZONE', 'America/New_York'),
            'ai' => $ai->providerStatus(),
            'smtp_configured' => env_value('SMTP_HOST', '') !== '',
            'cron_key' => env_value('CRON_KEY', ''),
            'app_url' => env_value('APP_URL', ''),
        ]);
    });

    $router->get('/api/settings/health', function () use ($pdo, $scheduler, $dataDir) {
        $diskFree = @disk_free_space($dataDir);
        $cronLog = $scheduler->getLog(1);
        json_response([
            'php_version' => PHP_VERSION,
            'sqlite_version' => $pdo->query('SELECT sqlite_version()')->fetchColumn(),
            'extensions' => [
                'curl' => extension_loaded('curl'),
                'gd' => extension_loaded('gd'),
                'mbstring' => extension_loaded('mbstring'),
                'simplexml' => extension_loaded('simplexml'),
                'pdo_sqlite' => extension_loaded('pdo_sqlite'),
            ],
            'disk_free_mb' => $diskFree ? round($diskFree / 1024 / 1024) : null,
            'data_dir_writable' => is_writable($dataDir),
            'last_cron' => $cronLog[0] ?? null,
        ]);
    });

    $router->post('/api/settings/backup', function () use ($dataDir) {
        $dbFile = $dataDir . '/marketing.sqlite';
        if (!is_file($dbFile)) {
            json_response(['error' => 'Database not found'], 404);
            return;
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="marketing-backup-' . date('Y-m-d-His') . '.sqlite"');
        header('Content-Length: ' . filesize($dbFile));
        readfile($dbFile);
    });
}
