<?php

/**
 * Cron endpoint for automated task execution.
 *
 * Call via cPanel cron or system crontab:
 *   */5 * * * * curl -s "https://yourdomain.com/cron.php?key=YOUR_CRON_KEY"
 *
 * Or via CLI:
 *   php /path/to/public/cron.php
 */

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Repositories.php';
require __DIR__ . '/../src/Templates.php';
require __DIR__ . '/../src/Scheduler.php';

// Auth check: either CLI or valid cron key
$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    $cronKey = env_value('CRON_KEY', '');
    $providedKey = $_GET['key'] ?? '';
    if ($cronKey === '' || $providedKey !== $cronKey) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid cron key']);
        exit;
    }
}

$dataDir = __DIR__ . '/../data';
$db = new Database($dataDir . '/marketing.sqlite');
$pdo = $db->pdo();

// Optionally load SocialPublisher if available
$publisher = null;
$publisherFile = __DIR__ . '/../src/SocialPublisher.php';
if (is_file($publisherFile)) {
    require $publisherFile;
    $publisher = new SocialPublisher($pdo);
}

$scheduler = new Scheduler($pdo, $publisher, $dataDir);
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
