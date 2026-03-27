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

        $aiStatus = $ai->providerStatus();
        json_response([
            'business_name' => app_config('BUSINESS_NAME', 'My Small Business'),
            'business_industry' => app_config('BUSINESS_INDUSTRY', 'Local services'),
            'timezone' => app_config('TIMEZONE', 'America/New_York'),
            'ai' => $aiStatus,
            'ai_provider' => app_config('AI_PROVIDER', 'openai'),
            'ai_models' => $aiStatus['current_models'] ?? [],
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
            'OPENAI_MODEL', 'ANTHROPIC_MODEL', 'GEMINI_MODEL',
            'DEEPSEEK_MODEL', 'GROQ_MODEL', 'MISTRAL_MODEL',
            'OPENROUTER_MODEL', 'XAI_MODEL', 'TOGETHER_MODEL',
        ];
        $updated = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $data)) {
                $val = (string)$data[$key];
                // Validate specific settings
                if ($key === 'TIMEZONE' && $val !== '' && !in_array($val, timezone_identifiers_list(), true)) {
                    json_response(['error' => 'Invalid timezone: ' . $val], 422);
                    return;
                }
                if ($key === 'AI_PROVIDER' && $val !== '' && !in_array($val, ['openai', 'anthropic', 'gemini', 'deepseek', 'groq', 'mistral', 'openrouter', 'xai', 'together'], true)) {
                    json_response(['error' => 'Invalid AI provider: ' . $val], 422);
                    return;
                }
                db_setting($key, $val, $pdo);
                $updated[$key] = $val;
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
