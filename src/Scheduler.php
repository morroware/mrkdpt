<?php

declare(strict_types=1);

final class Scheduler
{
    private string $lockFile;

    public function __construct(
        private PDO $pdo,
        private ?SocialPublisher $publisher = null,
        string $dataDir = '',
    ) {
        $this->lockFile = rtrim($dataDir ?: __DIR__ . '/../data', '/') . '/cron.lock';
    }

    /**
     * Main cron entry point. Returns summary of actions taken.
     */
    public function run(): array
    {
        $summary = [
            'started_at' => gmdate(DATE_ATOM),
            'posts_published' => 0,
            'recurring_created' => 0,
            'rss_fetched' => 0,
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

            // 3. Fetch RSS feeds (if RssFetcher is available)
            $summary['rss_fetched'] = $this->fetchRssFeeds();

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
                $accountIds = array_filter(explode(',', $post['account_ids']));
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
                $xml = @file_get_contents($feed['url']);
                if (!$xml) {
                    continue;
                }

                $parsed = @simplexml_load_string($xml);
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

    /* ---- lock ---- */

    private function acquireLock(): bool
    {
        if (is_file($this->lockFile)) {
            $age = time() - filemtime($this->lockFile);
            if ($age < 300) { // 5 min max lock
                return false;
            }
            unlink($this->lockFile); // stale lock
        }
        return (bool)file_put_contents($this->lockFile, (string)getmypid());
    }

    private function releaseLock(): void
    {
        if (is_file($this->lockFile)) {
            unlink($this->lockFile);
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
