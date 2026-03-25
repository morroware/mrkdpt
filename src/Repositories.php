<?php

declare(strict_types=1);

/* ---- Campaign ---- */

final class CampaignRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM campaigns ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO campaigns(name, channel, objective, budget, notes, start_date, end_date, created_at) VALUES(:name,:channel,:objective,:budget,:notes,:start_date,:end_date,:created_at)');
        $stmt->execute([
            ':name' => $data['name'],
            ':channel' => $data['channel'],
            ':objective' => $data['objective'],
            ':budget' => (float)($data['budget'] ?? 0),
            ':notes' => $data['notes'] ?? '',
            ':start_date' => $data['start_date'] ?? null,
            ':end_date' => $data['end_date'] ?? null,
            ':created_at' => gmdate(DATE_ATOM),
        ]);
        return $this->find((int)$this->pdo->lastInsertId());
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM campaigns WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = [':id' => $id];
        foreach (['name', 'channel', 'objective', 'budget', 'notes', 'start_date', 'end_date'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[":{$col}"] = $col === 'budget' ? (float)$data[$col] : $data[$col];
            }
        }
        if ($fields) {
            $this->pdo->prepare('UPDATE campaigns SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
        }
        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM campaigns WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }
}

/* ---- Post ---- */

final class PostRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(?string $status = null, ?string $platform = null, ?int $campaignId = null): array
    {
        $where = [];
        $params = [];
        if ($status) {
            $where[] = 'p.status = :status';
            $params[':status'] = $status;
        }
        if ($platform) {
            $where[] = 'p.platform = :platform';
            $params[':platform'] = $platform;
        }
        if ($campaignId) {
            $where[] = 'p.campaign_id = :cid';
            $params[':cid'] = $campaignId;
        }
        $sql = 'SELECT p.*, c.name as campaign_name FROM posts p LEFT JOIN campaigns c ON c.id = p.campaign_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY COALESCE(p.scheduled_for, p.created_at) DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO posts(campaign_id, platform, content_type, title, body, cta, tags, scheduled_for, status, ai_score, recurrence, is_evergreen, media_id, created_at) VALUES(:campaign_id,:platform,:content_type,:title,:body,:cta,:tags,:scheduled_for,:status,:ai_score,:recurrence,:is_evergreen,:media_id,:created_at)');
        $stmt->execute([
            ':campaign_id' => !empty($data['campaign_id']) ? (int)$data['campaign_id'] : null,
            ':platform' => $data['platform'],
            ':content_type' => $data['content_type'] ?? 'social_post',
            ':title' => $data['title'],
            ':body' => $data['body'],
            ':cta' => $data['cta'] ?? '',
            ':tags' => $data['tags'] ?? '',
            ':scheduled_for' => $data['scheduled_for'] ?: null,
            ':status' => $data['status'] ?? 'draft',
            ':ai_score' => (int)($data['ai_score'] ?? 0),
            ':recurrence' => $data['recurrence'] ?? 'none',
            ':is_evergreen' => (int)($data['is_evergreen'] ?? 0),
            ':media_id' => !empty($data['media_id']) ? (int)$data['media_id'] : null,
            ':created_at' => gmdate(DATE_ATOM),
        ]);
        return $this->find((int)$this->pdo->lastInsertId());
    }

    public function updateStatus(int $id, string $status): ?array
    {
        $params = [':status' => $status, ':id' => $id];
        $sql = 'UPDATE posts SET status = :status';
        if ($status === 'published') {
            $sql .= ', published_at = :pa';
            $params[':pa'] = gmdate(DATE_ATOM);
        }
        $sql .= ' WHERE id = :id';
        $this->pdo->prepare($sql)->execute($params);
        return $this->find($id);
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = [':id' => $id];
        foreach (['campaign_id', 'platform', 'content_type', 'title', 'body', 'cta', 'tags', 'scheduled_for', 'status', 'ai_score', 'recurrence', 'is_evergreen', 'media_id'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[":{$col}"] = $data[$col];
            }
        }
        if ($fields) {
            $this->pdo->prepare('UPDATE posts SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
        }
        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM posts WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT p.*, c.name as campaign_name FROM posts p LEFT JOIN campaigns c ON c.id = p.campaign_id WHERE p.id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function metrics(): array
    {
        $totals = $this->pdo->query('SELECT COUNT(*) as posts, SUM(CASE WHEN status = "scheduled" THEN 1 ELSE 0 END) as scheduled, SUM(CASE WHEN status = "published" THEN 1 ELSE 0 END) as published, SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed, AVG(ai_score) as avg_score FROM posts')->fetch(PDO::FETCH_ASSOC);
        return [
            'posts' => (int)($totals['posts'] ?? 0),
            'scheduled' => (int)($totals['scheduled'] ?? 0),
            'published' => (int)($totals['published'] ?? 0),
            'failed' => (int)($totals['failed'] ?? 0),
            'avg_score' => round((float)($totals['avg_score'] ?? 0), 1),
        ];
    }

    public function bulkUpdateStatus(array $ids, string $status): int
    {
        if (empty($ids)) return 0;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_map('intval', $ids);
        $params[] = $status;
        if ($status === 'published') {
            $params[] = gmdate(DATE_ATOM);
            $stmt = $this->pdo->prepare("UPDATE posts SET status = ?, published_at = ? WHERE id IN ({$placeholders})");
            // reorder params: ids first, then status, then published_at
            $ordered = array_map('intval', $ids);
            $stmt2 = $this->pdo->prepare("UPDATE posts SET status = ?, published_at = ? WHERE id IN ({$placeholders})");
            $stmt2->execute(array_merge([$status, gmdate(DATE_ATOM)], array_map('intval', $ids)));
            return $stmt2->rowCount();
        }
        $stmt = $this->pdo->prepare("UPDATE posts SET status = ? WHERE id IN ({$placeholders})");
        $stmt->execute(array_merge([$status], array_map('intval', $ids)));
        return $stmt->rowCount();
    }

    public function bulkDelete(array $ids): int
    {
        if (empty($ids)) return 0;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM posts WHERE id IN ({$placeholders})");
        $stmt->execute(array_map('intval', $ids));
        return $stmt->rowCount();
    }
}

/* ---- Competitor ---- */

final class CompetitorRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM competitors ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO competitors(name, channel, positioning, recent_activity, opportunity, created_at) VALUES(:name,:channel,:positioning,:recent_activity,:opportunity,:created_at)');
        $stmt->execute([
            ':name' => $data['name'],
            ':channel' => $data['channel'],
            ':positioning' => $data['positioning'] ?? '',
            ':recent_activity' => $data['recent_activity'] ?? '',
            ':opportunity' => $data['opportunity'] ?? '',
            ':created_at' => gmdate(DATE_ATOM),
        ]);
        $id = (int)$this->pdo->lastInsertId();
        return $this->pdo->query('SELECT * FROM competitors WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM competitors WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }
}

/* ---- KPI ---- */

final class KpiRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM kpi_logs ORDER BY logged_on DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO kpi_logs(channel, metric_name, metric_value, logged_on, note) VALUES(:channel,:metric_name,:metric_value,:logged_on,:note)');
        $stmt->execute([
            ':channel' => $data['channel'],
            ':metric_name' => $data['metric_name'],
            ':metric_value' => (float)$data['metric_value'],
            ':logged_on' => $data['logged_on'] ?? gmdate('Y-m-d'),
            ':note' => $data['note'] ?? '',
        ]);
        $id = (int)$this->pdo->lastInsertId();
        return $this->pdo->query('SELECT * FROM kpi_logs WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function summary(): array
    {
        return $this->pdo->query('SELECT channel, metric_name, AVG(metric_value) as avg_value, MAX(logged_on) as latest_on FROM kpi_logs GROUP BY channel, metric_name ORDER BY channel, metric_name')->fetchAll(PDO::FETCH_ASSOC);
    }
}

/* ---- AI Logs ---- */

final class AiLogRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function saveResearch(string $focus, string $output): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO research_briefs(focus, output, created_at) VALUES(:focus,:output,:created_at)');
        $stmt->execute([':focus' => $focus, ':output' => $output, ':created_at' => gmdate(DATE_ATOM)]);
    }

    public function saveIdea(string $topic, string $platform, string $output): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO content_ideas(topic, platform, output, created_at) VALUES(:topic,:platform,:output,:created_at)');
        $stmt->execute([':topic' => $topic, ':platform' => $platform, ':output' => $output, ':created_at' => gmdate(DATE_ATOM)]);
    }

    public function ideas(): array
    {
        return $this->pdo->query('SELECT * FROM content_ideas ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function research(): array
    {
        return $this->pdo->query('SELECT * FROM research_briefs ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    }
}

/* ---- Social Accounts ---- */

final class SocialAccountRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM social_accounts ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => $this->decode($r), $rows);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM social_accounts WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->decode($row) : null;
    }

    public function findByPlatform(string $platform): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM social_accounts WHERE platform = :p ORDER BY id');
        $stmt->execute([':p' => $platform]);
        return array_map(fn($r) => $this->decode($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO social_accounts(platform, account_name, access_token, refresh_token, token_expires, meta_json, created_at) VALUES(:p,:n,:at,:rt,:te,:m,:c)');
        $stmt->execute([
            ':p' => $data['platform'],
            ':n' => $data['account_name'],
            ':at' => $data['access_token'] ?? '',
            ':rt' => $data['refresh_token'] ?? '',
            ':te' => $data['token_expires'] ?? null,
            ':m' => is_array($data['meta_json'] ?? null) ? json_encode($data['meta_json']) : ($data['meta_json'] ?? '{}'),
            ':c' => gmdate(DATE_ATOM),
        ]);
        return $this->find((int)$this->pdo->lastInsertId());
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = [':id' => $id];
        foreach (['platform', 'account_name', 'access_token', 'refresh_token', 'token_expires', 'meta_json'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $val = $data[$col];
                if ($col === 'meta_json' && is_array($val)) {
                    $val = json_encode($val);
                }
                $params[":{$col}"] = $val;
            }
        }
        if ($fields) {
            $this->pdo->prepare('UPDATE social_accounts SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
        }
        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM social_accounts WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    private function decode(array $row): array
    {
        $meta = json_decode($row['meta_json'] ?? '{}', true);
        $row['meta'] = is_array($meta) ? $meta : [];
        return $row;
    }
}

/* ---- Email Lists ---- */

final class EmailListRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return $this->pdo->query("
            SELECT el.*, (SELECT COUNT(*) FROM subscribers s WHERE s.list_id = el.id AND s.status = 'active') as subscriber_count
            FROM email_lists el ORDER BY el.id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT el.*, (SELECT COUNT(*) FROM subscribers s WHERE s.list_id = el.id AND s.status = 'active') as subscriber_count
            FROM email_lists el WHERE el.id = :id LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO email_lists(name, description, created_at) VALUES(:n,:d,:c)');
        $stmt->execute([
            ':n' => $data['name'],
            ':d' => $data['description'] ?? '',
            ':c' => gmdate(DATE_ATOM),
        ]);
        return $this->find((int)$this->pdo->lastInsertId());
    }

    public function delete(int $id): bool
    {
        $this->pdo->prepare('DELETE FROM subscribers WHERE list_id = :id')->execute([':id' => $id]);
        $stmt = $this->pdo->prepare('DELETE FROM email_lists WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }
}

/* ---- Subscribers ---- */

final class SubscriberRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(?int $listId = null): array
    {
        if ($listId) {
            $stmt = $this->pdo->prepare('SELECT s.*, el.name as list_name FROM subscribers s LEFT JOIN email_lists el ON el.id = s.list_id WHERE s.list_id = :lid ORDER BY s.id DESC');
            $stmt->execute([':lid' => $listId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $this->pdo->query('SELECT s.*, el.name as list_name FROM subscribers s LEFT JOIN email_lists el ON el.id = s.list_id ORDER BY s.id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM subscribers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): array|string
    {
        try {
            $stmt = $this->pdo->prepare('INSERT INTO subscribers(email, name, list_id, status, subscribed_at) VALUES(:e,:n,:l,:s,:sa)');
            $stmt->execute([
                ':e' => strtolower(trim($data['email'])),
                ':n' => $data['name'] ?? '',
                ':l' => (int)$data['list_id'],
                ':s' => $data['status'] ?? 'active',
                ':sa' => gmdate(DATE_ATOM),
            ]);
            return $this->find((int)$this->pdo->lastInsertId());
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                return 'Subscriber already exists in this list';
            }
            throw $e;
        }
    }

    public function importCsv(int $listId, string $csvContent): array
    {
        $lines = str_getcsv_rows($csvContent);
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($lines as $i => $row) {
            if ($i === 0 && (stripos($row[0] ?? '', 'email') !== false)) {
                continue; // skip header
            }
            $email = trim($row[0] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                continue;
            }
            $name = $row[1] ?? '';
            $result = $this->create(['email' => $email, 'name' => $name, 'list_id' => $listId]);
            if (is_string($result)) {
                $skipped++;
            } else {
                $imported++;
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    public function activeForList(int $listId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM subscribers WHERE list_id = :lid AND status = 'active' ORDER BY id");
        $stmt->execute([':lid' => $listId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM subscribers WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }
}

/**
 * Helper to parse CSV rows from a string.
 */
function str_getcsv_rows(string $csv): array
{
    $rows = [];
    $lines = explode("\n", str_replace("\r\n", "\n", $csv));
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $rows[] = str_getcsv($line);
        }
    }
    return $rows;
}

/* ---- Email Campaigns ---- */

final class EmailCampaignRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT ec.*, el.name as list_name FROM email_campaigns ec LEFT JOIN email_lists el ON el.id = ec.list_id ORDER BY ec.id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ec.*, el.name as list_name FROM email_campaigns ec LEFT JOIN email_lists el ON el.id = ec.list_id WHERE ec.id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO email_campaigns(name, subject, body_html, body_text, list_id, status, created_at) VALUES(:n,:s,:bh,:bt,:l,:st,:c)');
        $stmt->execute([
            ':n' => $data['name'],
            ':s' => $data['subject'],
            ':bh' => $data['body_html'] ?? '',
            ':bt' => $data['body_text'] ?? '',
            ':l' => !empty($data['list_id']) ? (int)$data['list_id'] : null,
            ':st' => $data['status'] ?? 'draft',
            ':c' => gmdate(DATE_ATOM),
        ]);
        return $this->find((int)$this->pdo->lastInsertId());
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = [':id' => $id];
        foreach (['name', 'subject', 'body_html', 'body_text', 'list_id', 'status', 'sent_count', 'sent_at'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[":{$col}"] = $data[$col];
            }
        }
        if ($fields) {
            $this->pdo->prepare('UPDATE email_campaigns SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
        }
        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM email_campaigns WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
