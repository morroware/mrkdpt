<?php

/**
 * Cron endpoint for automated task execution.
 *
 * Call via cPanel cron or system crontab:
 *   Every 5 minutes: curl -s "https://yourdomain.com/cron.php?key=YOUR_CRON_KEY"
 *
 * Or via CLI:
 *   php /path/to/public/cron.php
 */

declare(strict_types=1);

// Auto-detect layout: src/ next to this file (flat) or one level up (nested/public)
$srcDir = is_dir(__DIR__ . '/src') ? __DIR__ . '/src' : __DIR__ . '/../src';

require $srcDir . '/bootstrap.php';
require $srcDir . '/Database.php';
require $srcDir . '/Repositories.php';
require $srcDir . '/Templates.php';
require $srcDir . '/Scheduler.php';
require $srcDir . '/Automations.php';
require $srcDir . '/SocialQueue.php';
require $srcDir . '/JobQueue.php';

// Auth check: either CLI or valid cron key
$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    $cronKey = env_value('CRON_KEY', '');
    $providedKey = $_GET['key'] ?? '';
    if ($cronKey === '' || !hash_equals($cronKey, $providedKey)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid cron key']);
        exit;
    }
}

$dataDir = APP_ROOT . '/data';
$dbPath = $dataDir . '/marketing.sqlite';
if (!is_file($dbPath)) {
    $msg = 'Database not found. Run the installer first.';
    if ($isCli) { echo $msg . "\n"; exit(1); }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $msg]);
    exit;
}
$db = new Database($dbPath);
$pdo = $db->pdo();

// Initialize DB-backed settings cache (needed by app_config() overrides)
db_setting('_init', null, $pdo);

// Optionally load SocialPublisher if available
$publisher = null;
$publisherFile = APP_ROOT . '/src/SocialPublisher.php';
if (is_file($publisherFile)) {
    require $publisherFile;
    $publisher = new SocialPublisher($pdo);
}

// Optionally load EmailService if available (needed for email_campaign job handler)
$emailService = null;
$emailServiceFile = APP_ROOT . '/src/EmailService.php';
if (is_file($emailServiceFile)) {
    require $emailServiceFile;
    $emailService = new EmailService($pdo, [
        'smtp_host'      => env_value('SMTP_HOST', ''),
        'smtp_port'      => (int) env_value('SMTP_PORT', '587'),
        'smtp_user'      => env_value('SMTP_USER', ''),
        'smtp_pass'      => env_value('SMTP_PASS', ''),
        'smtp_from'      => env_value('SMTP_FROM', ''),
        'smtp_from_name' => env_value('SMTP_FROM_NAME', env_value('BUSINESS_NAME', '')),
        'base_url'       => env_value('APP_URL', ''),
    ]);
}

$scheduler = new Scheduler($pdo, $publisher, $dataDir);
$scheduler->setAutomations(new AutomationRepository($pdo, $emailService));
$scheduler->setQueue(new SocialQueue($pdo));

// Wire up the async job queue and register handlers
$jobQueue = new JobQueue($pdo);
$scheduler->setJobQueue($jobQueue);

if ($emailService) {
    $scheduler->registerJobHandler('email_campaign', function (array $payload) use ($emailService): void {
        $result = $emailService->sendCampaign((int) ($payload['campaign_id'] ?? 0));
        if (!empty($result['errors'])) {
            error_log('Email campaign errors: ' . implode('; ', $result['errors']));
        }
        if ($result['sent'] === 0 && !empty($result['errors'])) {
            throw new \RuntimeException('Campaign send failed: ' . implode('; ', $result['errors']));
        }
    });
}

$result = $scheduler->run();

if ($isCli) {
    echo "Cron completed:\n";
    echo "  Posts published: {$result['posts_published']}\n";
    echo "  Recurring created: {$result['recurring_created']}\n";
    echo "  RSS fetched: {$result['rss_fetched']}\n";
    echo "  Jobs processed: " . ($result['jobs_processed'] ?? 0) . "\n";
    echo "  Jobs failed: " . ($result['jobs_failed'] ?? 0) . "\n";
    if ($result['errors']) {
        echo "  Errors:\n";
        foreach ($result['errors'] as $err) {
            echo "    - {$err}\n";
        }
    }
} else {
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);
}
