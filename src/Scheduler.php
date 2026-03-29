<?php

declare(strict_types=1);

final class Scheduler
{
    private string $lockFile;

    private ?AutomationRepository $automations = null;
    private ?SocialQueue $queue = null;
    private ?JobQueue $jobQueue = null;
    /** @var array<string, callable> */
    private array $jobHandlers = [];

    public function __construct(
        private PDO $pdo,
        private ?SocialPublisher $publisher = null,
        string $dataDir = '',
    ) {
        $this->lockFile = rtrim($dataDir ?: __DIR__ . '/../data', '/') . '/cron.lock';
    }

    public function setAutomations(AutomationRepository $automations): void
    {
        $this->automations = $automations;
    }

    public function setQueue(SocialQueue $queue): void
    {
        $this->queue = $queue;
    }

    public function setJobQueue(JobQueue $jobQueue): void
    {
        $this->jobQueue = $jobQueue;
    }

    /**
     * Register a handler for a job type. Called during app bootstrap.
     */
    public function registerJobHandler(string $type, callable $handler): void
    {
        $this->jobHandlers[$type] = $handler;
    }

    /**
     * Main cron entry point. Returns summary of actions taken.
     */
    public function run(): array
    {
        $summary = [
            'started_at' => gmdate(DATE_ATOM),
            'posts_published' => 0,
            'queue_published' => 0,
            'recurring_created' => 0,
            'rss_fetched' => 0,
            'jobs_processed' => 0,
            'jobs_failed' => 0,
            'errors' => [],
        ];

        if (!$this->acquireLock()) {
            $summary['errors'][] = 'Could not acquire lock. Another cron may be running.';
            $this->logRun('cron', 'skipped', 'Lock file exists');
            return $summary;
        }

        try {
            // 1. Publish scheduled posts
            $published = $this->publishDuePosts();
            $summary['posts_published'] = $published['count'];
            if ($published['errors']) {
                $summary['errors'] = array_merge($summary['errors'], $published['errors']);
            }

            // 2. Create recurring posts
            $recurring = $this->processRecurring();
            $summary['recurring_created'] = $recurring;

            // 3. Process social publish queue
            $queueResult = $this->processQueue();
            $summary['queue_published'] = $queueResult['count'];
            if ($queueResult['errors']) {
                $summary['errors'] = array_merge($summary['errors'], $queueResult['errors']);
            }

            // 4. Fetch RSS feeds (if RssFetcher is available)
            $summary['rss_fetched'] = $this->fetchRssFeeds();

            // 5. Process async job queue
            $jobResult = $this->processJobQueue();
            $summary['jobs_processed'] = $jobResult['processed'];
            $summary['jobs_failed'] = $jobResult['failed'];
            if ($jobResult['errors']) {
                $summary['errors'] = array_merge($summary['errors'], $jobResult['errors']);
            }

            $this->logRun('cron', 'success', json_encode($summary));
        } catch (\Throwable $e) {
            $summary['errors'][] = $e->getMessage();
            $this->logRun('cron', 'error', $e->getMessage());
        } finally {
            $this->releaseLock();
        }

        $summary['finished_at'] = gmdate(DATE_ATOM);
        return $summary;
    }

    /**
     * Find and publish all posts where scheduled_for <= now and status = 'scheduled'.
     */
    private function publishDuePosts(): array
    {
        $now = gmdate('Y-m-d\TH:i:s');
        $stmt = $this->pdo->prepare("
            SELECT p.*, GROUP_CONCAT(sa.id) as account_ids
            FROM posts p
            LEFT JOIN social_accounts sa ON sa.platform = p.platform AND sa.id IS NOT NULL
            WHERE p.status = 'scheduled'
              AND p.scheduled_for IS NOT NULL
              AND p.scheduled_for <= :now
            GROUP BY p.id
            ORDER BY p.scheduled_for ASC
            LIMIT 50
        ");
        $stmt->execute([':now' => $now]);
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $count = 0;
        $errors = [];

        foreach ($posts as $post) {
            $published = false;
            $publishError = null;

            // Try to publish to social media if publisher and accounts are available
            if ($this->publisher && !empty($post['account_ids'])) {
                $accountIds = array_filter(explode(',', $post['account_ids'] ?? ''));
                foreach ($accountIds as $accountId) {
                    $acctStmt = $this->pdo->prepare('SELECT * FROM social_accounts WHERE id = :id LIMIT 1');
                    $acctStmt->execute([':id' => (int)$accountId]);
                    $account = $acctStmt->fetch(PDO::FETCH_ASSOC);

                    if ($account) {
                        $result = $this->publisher->publish($post, $account);
                        if ($result['success']) {
                            $published = true;
                        } else {
                            $publishError = $result['error'];
                            $errors[] = "Post #{$post['id']} to {$account['platform']}: {$result['error']}";
                        }
                    }
                }
            } else {
                // No social publisher or no accounts - just mark as published
                $published = true;
            }

            // Update post status
            if ($published) {
                $this->pdo->prepare("UPDATE posts SET status = 'published', published_at = :pa WHERE id = :id")->execute([
                    ':pa' => gmdate(DATE_ATOM),
                    ':id' => $post['id'],
                ]);
                $count++;
                // Fire automation event
                if ($this->automations) {
                    try {
                        $this->automations->fire('post.published', [
                            'post_id' => $post['id'],
                            'platform' => $post['platform'] ?? '',
                            'campaign_id' => $post['campaign_id'] ?? null,
                            'title' => $post['title'] ?? '',
                        ]);
                    } catch (\Throwable $e) {
                        $errors[] = "Automation error for post #{$post['id']}: " . $e->getMessage();
                    }
                }
            } else {
                // Increment retry count
                $retries = (int)($post['retry_count'] ?? 0) + 1;
                if ($retries >= 3) {
                    $this->pdo->prepare("UPDATE posts SET status = 'failed', publish_error = :e, retry_count = :r WHERE id = :id")->execute([
                        ':e' => $publishError,
                        ':r' => $retries,
                        ':id' => $post['id'],
                    ]);
                } else {
                    // Reschedule with exponential backoff (5min, 20min, 80min)
                    $delay = (int)(5 * pow(4, $retries - 1));
                    $newTime = gmdate('Y-m-d\TH:i:s', time() + $delay * 60);
                    $this->pdo->prepare("UPDATE posts SET scheduled_for = :sf, publish_error = :e, retry_count = :r WHERE id = :id")->execute([
                        ':sf' => $newTime,
                        ':e' => $publishError,
                        ':r' => $retries,
                        ':id' => $post['id'],
                    ]);
                }
            }
        }

        return ['count' => $count, 'errors' => $errors];
    }

    /**
     * Process recurring posts: if a recurring post was published, create the next occurrence.
     */
    private function processRecurring(): int
    {
        $stmt = $this->pdo->query("
            SELECT * FROM posts
            WHERE status = 'published'
              AND recurrence IS NOT NULL
              AND recurrence != 'none'
              AND recurrence != ''
              AND (published_at >= datetime('now', '-90 days') OR created_at >= datetime('now', '-90 days'))
            ORDER BY id DESC
            LIMIT 100
        ");
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $created = 0;

        foreach ($posts as $post) {
            // Check if next occurrence already exists
            $check = $this->pdo->prepare("
                SELECT COUNT(*) FROM posts
                WHERE recurring_parent_id = :pid AND status IN ('draft', 'scheduled')
            ");
            $check->execute([':pid' => $post['id']]);
            if ((int)$check->fetchColumn() > 0) {
                continue; // next occurrence already queued
            }

            $nextDate = $this->calculateNextDate($post['scheduled_for'] ?? $post['published_at'] ?? gmdate(DATE_ATOM), $post['recurrence']);
            if (!$nextDate) {
                continue;
            }

            $ins = $this->pdo->prepare("
                INSERT INTO posts(campaign_id, platform, content_type, title, body, cta, tags, scheduled_for, status, ai_score, recurrence, recurring_parent_id, is_evergreen, created_at)
                VALUES(:cid,:pl,:ct,:ti,:bo,:cta,:tags,:sf,'scheduled',:score,:rec,:pid,:ev,:ca)
            ");
            $ins->execute([
                ':cid' => $post['campaign_id'] ?: null,
                ':pl' => $post['platform'],
                ':ct' => $post['content_type'],
                ':ti' => $post['title'],
                ':bo' => $post['body'],
                ':cta' => $post['cta'] ?? '',
                ':tags' => $post['tags'] ?? '',
                ':sf' => $nextDate,
                ':score' => $post['ai_score'] ?? 0,
                ':rec' => $post['recurrence'],
                ':pid' => $post['id'],
                ':ev' => $post['is_evergreen'] ?? 0,
                ':ca' => gmdate(DATE_ATOM),
            ]);
            $created++;
        }

        return $created;
    }

    private function calculateNextDate(?string $currentDate, string $recurrence): ?string
    {
        if (!$currentDate) {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable($currentDate);
        } catch (\Throwable) {
            return null;
        }

        return match ($recurrence) {
            'daily' => $dt->modify('+1 day')->format('Y-m-d\TH:i:s'),
            'weekly' => $dt->modify('+1 week')->format('Y-m-d\TH:i:s'),
            'biweekly' => $dt->modify('+2 weeks')->format('Y-m-d\TH:i:s'),
            'monthly' => $dt->modify('+1 month')->format('Y-m-d\TH:i:s'),
            default => null,
        };
    }

    /**
     * Fetch RSS feeds if the rss_feeds table exists.
     */
    private function fetchRssFeeds(): int
    {
        try {
            $feeds = $this->pdo->query("SELECT * FROM rss_feeds WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return 0; // table may not exist yet
        }

        $fetched = 0;
        foreach ($feeds as $feed) {
            try {
                $ctx = stream_context_create(['http' => ['timeout' => 15, 'user_agent' => 'MarketingSuite/2.0']]);
                $xml = @file_get_contents($feed['url'], false, $ctx);
                if (!$xml) {
                    continue;
                }

                $previousUseErrors = libxml_use_internal_errors(true);
                $parsed = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
                libxml_use_internal_errors($previousUseErrors);
                if (!$parsed) {
                    continue;
                }

                $items = [];
                // RSS 2.0
                if (isset($parsed->channel->item)) {
                    foreach ($parsed->channel->item as $item) {
                        $items[] = [
                            'title' => (string)$item->title,
                            'url' => (string)$item->link,
                            'summary' => strip_tags((string)($item->description ?? '')),
                            'published_at' => !empty($item->pubDate) ? gmdate(DATE_ATOM, strtotime((string)$item->pubDate)) : gmdate(DATE_ATOM),
                        ];
                    }
                }
                // Atom
                if (isset($parsed->entry)) {
                    foreach ($parsed->entry as $entry) {
                        $link = '';
                        if (isset($entry->link['href'])) {
                            $link = (string)$entry->link['href'];
                        }
                        $items[] = [
                            'title' => (string)$entry->title,
                            'url' => $link,
                            'summary' => strip_tags((string)($entry->summary ?? $entry->content ?? '')),
                            'published_at' => !empty($entry->published) ? gmdate(DATE_ATOM, strtotime((string)$entry->published)) : gmdate(DATE_ATOM),
                        ];
                    }
                }

                foreach (array_slice($items, 0, 20) as $item) {
                    // skip duplicates
                    $check = $this->pdo->prepare('SELECT COUNT(*) FROM rss_items WHERE feed_id = :fid AND url = :url');
                    $check->execute([':fid' => $feed['id'], ':url' => $item['url']]);
                    if ((int)$check->fetchColumn() > 0) {
                        continue;
                    }

                    $ins = $this->pdo->prepare('INSERT INTO rss_items(feed_id, title, url, summary, published_at, curated, created_at) VALUES(:fid,:title,:url,:summary,:pub,0,:ca)');
                    $ins->execute([
                        ':fid' => $feed['id'],
                        ':title' => mb_substr($item['title'], 0, 500),
                        ':url' => $item['url'],
                        ':summary' => mb_substr($item['summary'], 0, 2000),
                        ':pub' => $item['published_at'],
                        ':ca' => gmdate(DATE_ATOM),
                    ]);
                    $fetched++;
                }

                $this->pdo->prepare('UPDATE rss_feeds SET last_fetched = :lf WHERE id = :id')->execute([
                    ':lf' => gmdate(DATE_ATOM),
                    ':id' => $feed['id'],
                ]);
            } catch (\Throwable $e) {
                $this->logRun('rss_fetch', 'error', "Feed #{$feed['id']}: " . $e->getMessage());
            }
        }

        return $fetched;
    }

    /**
     * Process queued items from the social publish queue.
     */
    private function processQueue(): array
    {
        if (!$this->queue || !$this->publisher) {
            return ['count' => 0, 'errors' => []];
        }

        $now = gmdate('Y-m-d\TH:i:s');
        $stmt = $this->pdo->prepare("
            SELECT sq.*, p.platform, p.title, p.body, p.cta, p.tags, p.campaign_id, p.content_type
            FROM social_queue sq
            JOIN posts p ON p.id = sq.post_id
            WHERE sq.status = 'queued'
              AND (sq.optimal_time IS NULL OR sq.optimal_time <= :now)
            ORDER BY sq.priority DESC, sq.queued_at ASC
            LIMIT 20
        ");
        $stmt->execute([':now' => $now]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $count = 0;
        $errors = [];

        foreach ($items as $item) {
            $acctStmt = $this->pdo->prepare('SELECT * FROM social_accounts WHERE id = :id LIMIT 1');
            $acctStmt->execute([':id' => (int)$item['social_account_id']]);
            $account = $acctStmt->fetch(PDO::FETCH_ASSOC);

            if (!$account) {
                $this->queue->updateStatus((int)$item['id'], 'failed', 'Social account not found');
                $errors[] = "Queue #{$item['id']}: social account not found";
                continue;
            }

            $post = [
                'id' => $item['post_id'],
                'platform' => $item['platform'],
                'title' => $item['title'],
                'body' => $item['body'],
                'cta' => $item['cta'] ?? '',
                'tags' => $item['tags'] ?? '',
                'content_type' => $item['content_type'] ?? 'social_post',
            ];

            $result = $this->publisher->publish($post, $account);
            if ($result['success']) {
                $this->queue->updateStatus((int)$item['id'], 'published');
                $count++;
                // Fire automation
                if ($this->automations) {
                    try {
                        $this->automations->fire('post.published', [
                            'post_id' => $item['post_id'],
                            'platform' => $item['platform'],
                            'campaign_id' => $item['campaign_id'] ?? null,
                        ]);
                    } catch (\Throwable) {}
                }
            } else {
                $this->queue->updateStatus((int)$item['id'], 'failed', $result['error'] ?? 'Unknown error');
                $errors[] = "Queue #{$item['id']} to {$account['platform']}: " . ($result['error'] ?? 'Unknown');
            }
        }

        return ['count' => $count, 'errors' => $errors];
    }

    /**
     * Process pending jobs from the async queue.
     */
    private function processJobQueue(): array
    {
        if (!$this->jobQueue || empty($this->jobHandlers)) {
            return ['processed' => 0, 'failed' => 0, 'errors' => []];
        }

        // Recover any jobs stuck in "processing" from a crashed previous run
        $this->jobQueue->recoverStale(300);

        // Process up to 20 jobs per cron tick
        $result = $this->jobQueue->process($this->jobHandlers, limit: 20);

        // Purge completed jobs older than 7 days
        $this->jobQueue->purgeOld(7);

        return $result;
    }

    /* ---- lock ---- */

    /** @var resource|null */
    private $lockHandle = null;

    private function acquireLock(): bool
    {
        $this->lockHandle = @fopen($this->lockFile, 'c');
        if (!$this->lockHandle) {
            return false;
        }
        if (!flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($this->lockHandle);
            $this->lockHandle = null;
            // Check for stale lock (older than 5 minutes)
            if (is_file($this->lockFile) && (time() - filemtime($this->lockFile)) > 300) {
                @unlink($this->lockFile);
                return $this->acquireLock();
            }
            return false;
        }
        ftruncate($this->lockHandle, 0);
        fwrite($this->lockHandle, (string)getmypid());
        fflush($this->lockHandle);
        return true;
    }

    private function releaseLock(): void
    {
        if ($this->lockHandle) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
        if (is_file($this->lockFile)) {
            @unlink($this->lockFile);
        }
    }

    /* ---- logging ---- */

    public function logRun(string $task, string $status, string $message): void
    {
        try {
            $this->pdo->prepare('INSERT INTO cron_log(task, status, message, ran_at) VALUES(:t,:s,:m,:r)')->execute([
                ':t' => $task,
                ':s' => $status,
                ':m' => mb_substr($message, 0, 5000),
                ':r' => gmdate(DATE_ATOM),
            ]);
        } catch (\Throwable) {
            // silently fail if table doesn't exist yet
        }
    }

    public function getLog(int $limit = 50): array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM cron_log ORDER BY id DESC LIMIT :lim');
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
    }
}
