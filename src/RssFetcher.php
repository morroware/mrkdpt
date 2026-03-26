<?php

declare(strict_types=1);

final class RssFetcher
{
    public function __construct(private PDO $pdo)
    {
    }

    /* ---- feed CRUD ---- */

    public function allFeeds(): array
    {
        return $this->pdo->query('SELECT * FROM rss_feeds ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findFeed(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rss_feeds WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createFeed(array $data): array
    {
        $url = $data['url'] ?? '';
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException('Feed URL must be a valid HTTP(S) URL');
        }
        $stmt = $this->pdo->prepare('INSERT INTO rss_feeds(url, name, is_active, last_fetched, created_at) VALUES(:u,:n,:a,NULL,:c)');
        $stmt->execute([
            ':u' => $data['url'],
            ':n' => $data['name'] ?? parse_url($data['url'], PHP_URL_HOST) ?? 'Feed',
            ':a' => (int)($data['is_active'] ?? 1),
            ':c' => gmdate(DATE_ATOM),
        ]);
        return $this->findFeed((int)$this->pdo->lastInsertId());
    }

    public function updateFeed(int $id, array $data): ?array
    {
        $fields = [];
        $params = [':id' => $id];
        foreach (['url', 'name', 'is_active'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[":{$col}"] = $col === 'is_active' ? (int)$data[$col] : $data[$col];
            }
        }
        if ($fields) {
            $this->pdo->prepare('UPDATE rss_feeds SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
        }
        return $this->findFeed($id);
    }

    public function deleteFeed(int $id): bool
    {
        $this->pdo->prepare('DELETE FROM rss_items WHERE feed_id = :id')->execute([':id' => $id]);
        $stmt = $this->pdo->prepare('DELETE FROM rss_feeds WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /* ---- items ---- */

    public function allItems(int $limit = 100, ?int $feedId = null): array
    {
        if ($feedId) {
            $stmt = $this->pdo->prepare('SELECT ri.*, rf.name as feed_name FROM rss_items ri LEFT JOIN rss_feeds rf ON rf.id = ri.feed_id WHERE ri.feed_id = :fid ORDER BY ri.published_at DESC LIMIT :lim');
            $stmt->bindValue(':fid', $feedId, PDO::PARAM_INT);
        } else {
            $stmt = $this->pdo->prepare('SELECT ri.*, rf.name as feed_name FROM rss_items ri LEFT JOIN rss_feeds rf ON rf.id = ri.feed_id ORDER BY ri.published_at DESC LIMIT :lim');
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findItem(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ri.*, rf.name as feed_name FROM rss_items ri LEFT JOIN rss_feeds rf ON rf.id = ri.feed_id WHERE ri.id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function markCurated(int $id): void
    {
        $this->pdo->prepare('UPDATE rss_items SET curated = 1 WHERE id = :id')->execute([':id' => $id]);
    }

    /**
     * Fetch a single feed and store new items.
     */
    public function fetchFeed(int $feedId): array
    {
        $feed = $this->findFeed($feedId);
        if (!$feed) {
            return ['error' => 'Feed not found', 'new_items' => 0];
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 15,
                'user_agent' => 'MarketingSuite/2.0 RSS Fetcher',
            ],
        ]);

        $xml = @file_get_contents($feed['url'], false, $ctx);
        if (!$xml) {
            return ['error' => 'Could not fetch feed URL', 'new_items' => 0];
        }

        $previousUseErrors = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($previousUseErrors);
        if (!$parsed) {
            return ['error' => 'Invalid XML', 'new_items' => 0];
        }

        $items = $this->extractItems($parsed);
        $newCount = 0;

        foreach (array_slice($items, 0, 30) as $item) {
            $check = $this->pdo->prepare('SELECT COUNT(*) FROM rss_items WHERE feed_id = :fid AND url = :url');
            $check->execute([':fid' => $feedId, ':url' => $item['url']]);
            if ((int)$check->fetchColumn() > 0) {
                continue;
            }

            $this->pdo->prepare('INSERT INTO rss_items(feed_id, title, url, summary, published_at, curated, created_at) VALUES(:fid,:t,:u,:s,:p,0,:c)')->execute([
                ':fid' => $feedId,
                ':t' => mb_substr($item['title'], 0, 500),
                ':u' => $item['url'],
                ':s' => mb_substr($item['summary'], 0, 2000),
                ':p' => $item['published_at'],
                ':c' => gmdate(DATE_ATOM),
            ]);
            $newCount++;
        }

        $this->pdo->prepare('UPDATE rss_feeds SET last_fetched = :lf WHERE id = :id')->execute([
            ':lf' => gmdate(DATE_ATOM),
            ':id' => $feedId,
        ]);

        return ['new_items' => $newCount, 'feed' => $feed['name']];
    }

    private function extractItems(\SimpleXMLElement $xml): array
    {
        $items = [];

        // RSS 2.0
        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $items[] = [
                    'title' => (string)$item->title,
                    'url' => (string)$item->link,
                    'summary' => strip_tags((string)($item->description ?? '')),
                    'published_at' => !empty($item->pubDate) ? gmdate(DATE_ATOM, strtotime((string)$item->pubDate)) : gmdate(DATE_ATOM),
                ];
            }
        }

        // Atom
        if (isset($xml->entry)) {
            foreach ($xml->entry as $entry) {
                $link = '';
                if (isset($entry->link['href'])) {
                    $link = (string)$entry->link['href'];
                }
                $items[] = [
                    'title' => (string)$entry->title,
                    'url' => $link,
                    'summary' => strip_tags((string)($entry->summary ?? $entry->content ?? '')),
                    'published_at' => !empty($entry->published)
                        ? gmdate(DATE_ATOM, strtotime((string)$entry->published))
                        : (!empty($entry->updated) ? gmdate(DATE_ATOM, strtotime((string)$entry->updated)) : gmdate(DATE_ATOM)),
                ];
            }
        }

        return $items;
    }
}
