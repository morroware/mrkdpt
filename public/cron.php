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

// Optionally load SocialPublisher if available
$publisher = null;
$publisherFile = APP_ROOT . '/src/SocialPublisher.php';
if (is_file($publisherFile)) {
    require $publisherFile;
    $publisher = new SocialPublisher($pdo);
}

$scheduler = new Scheduler($pdo, $publisher, $dataDir);
$scheduler->setAutomations(new AutomationRepository($pdo));
$scheduler->setQueue(new SocialQueue($pdo));
$result = $scheduler->run();

if ($isCli) {
    echo "Cron completed:\n";
    echo "  Posts published: {$result['posts_published']}\n";
    echo "  Recurring created: {$result['recurring_created']}\n";
    echo "  RSS fetched: {$result['rss_fetched']}\n";
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
