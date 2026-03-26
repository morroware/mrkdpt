<?php

declare(strict_types=1);

function register_settings_routes(Router $router, AiService $ai, Scheduler $scheduler, string $dataDir, PDO $pdo): void
{
    $router->get('/api/settings', function () use ($ai, $pdo) {
        // Load DB-backed overrides merged with .env defaults
        $dbSettings = [];
        try {
            $dbSettings = $pdo->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\Throwable) {}

        json_response([
            'business_name' => app_config('BUSINESS_NAME', 'My Small Business'),
            'business_industry' => app_config('BUSINESS_INDUSTRY', 'Local services'),
            'timezone' => app_config('TIMEZONE', 'America/New_York'),
            'ai' => $ai->providerStatus(),
            'ai_provider' => app_config('AI_PROVIDER', 'openai'),
            'ai_system_prompt' => $dbSettings['AI_SYSTEM_PROMPT'] ?? '',
            'smtp_configured' => env_value('SMTP_HOST', '') !== '',
            'cron_key' => env_value('CRON_KEY', ''),
            'app_url' => app_config('APP_URL', ''),
        ]);
    });

    $router->put('/api/settings', function () use ($pdo) {
        $data = request_json();
        $allowedKeys = [
            'BUSINESS_NAME', 'BUSINESS_INDUSTRY', 'TIMEZONE',
            'AI_PROVIDER', 'AI_SYSTEM_PROMPT', 'APP_URL',
        ];
        $updated = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $data)) {
                db_setting($key, (string)$data[$key], $pdo);
                $updated[$key] = $data[$key];
            }
        }
        if (empty($updated)) {
            json_response(['error' => 'No valid settings provided'], 422);
            return;
        }
        json_response(['updated' => $updated]);
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

    $router->post('/api/settings/backup', function () use ($dataDir, $pdo) {
        $dbFile = $dataDir . '/marketing.sqlite';
        if (!is_file($dbFile)) {
            json_response(['error' => 'Database not found'], 404);
            return;
        }
        // Checkpoint WAL to ensure backup includes all committed data
        try {
            $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        } catch (\Throwable $e) {
            // Non-fatal: backup proceeds even without checkpoint
        }
        header('Content-Type: application/octet-stream');
        $filename = str_replace(["\r", "\n", '"'], '', 'marketing-backup-' . date('Y-m-d-His') . '.sqlite');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($dbFile));
        readfile($dbFile);
    });
}
