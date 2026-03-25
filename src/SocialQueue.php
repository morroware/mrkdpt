<?php

declare(strict_types=1);

final class SocialQueue
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(?string $status = null): array
    {
        $sql = 'SELECT sq.*, p.title as post_title, p.platform as post_platform, p.body as post_body, sa.account_name, sa.platform as account_platform FROM social_queue sq LEFT JOIN posts p ON p.id = sq.post_id LEFT JOIN social_accounts sa ON sa.id = sq.social_account_id';
        $params = [];
        if ($status) {
            $sql .= ' WHERE sq.status = :status';
            $params[':status'] = $status;
        }
        $sql .= ' ORDER BY COALESCE(sq.optimal_time, sq.queued_at) ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT sq.*, p.title as post_title, p.platform as post_platform, sa.account_name FROM social_queue sq LEFT JOIN posts p ON p.id = sq.post_id LEFT JOIN social_accounts sa ON sa.id = sq.social_account_id WHERE sq.id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function enqueue(array $data): array
    {
        $this->pdo->prepare('INSERT INTO social_queue(post_id, social_account_id, priority, optimal_time, status, queued_at) VALUES(:pid,:said,:p,:ot,:s,:qa)')->execute([
            ':pid' => (int)$data['post_id'],
            ':said' => (int)$data['social_account_id'],
            ':p' => (int)($data['priority'] ?? 0),
            ':ot' => $data['optimal_time'] ?? null,
            ':s' => 'queued',
            ':qa' => gmdate(DATE_ATOM),
        ]);
        return $this->find((int)$this->pdo->lastInsertId());
    }

    public function updateStatus(int $id, string $status, string $error = ''): ?array
    {
        $params = [':status' => $status, ':id' => $id];
        $sql = 'UPDATE social_queue SET status = :status';
        if ($status === 'published') {
            $sql .= ', published_at = :pa';
            $params[':pa'] = gmdate(DATE_ATOM);
        }
        if ($error) {
            $sql .= ', error_message = :err';
            $params[':err'] = $error;
        }
        $sql .= ' WHERE id = :id';
        $this->pdo->prepare($sql)->execute($params);
        return $this->find($id);
    }

    public function reorder(int $id, int $priority): void
    {
        $this->pdo->prepare('UPDATE social_queue SET priority = :p WHERE id = :id')->execute([':p' => $priority, ':id' => $id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM social_queue WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function pendingCount(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM social_queue WHERE status = 'queued'")->fetchColumn();
    }

    public function metrics(): array
    {
        $row = $this->pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN status='queued' THEN 1 ELSE 0 END) as queued, SUM(CASE WHEN status='published' THEN 1 ELSE 0 END) as published, SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed FROM social_queue")->fetch(PDO::FETCH_ASSOC);
        return $row ?: [];
    }

    /**
     * Get optimal posting times based on past publish success data.
     */
    public function bestTimes(string $platform = ''): array
    {
        $sql = "SELECT strftime('%w', published_at) as day_of_week, strftime('%H', published_at) as hour, COUNT(*) as total, SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) as successes FROM publish_log WHERE published_at IS NOT NULL";
        $params = [];
        if ($platform) {
            $sql .= ' AND platform = :p';
            $params[':p'] = $platform;
        }
        $sql .= ' GROUP BY day_of_week, hour ORDER BY successes DESC LIMIT 20';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        return array_map(fn($r) => [
            'day' => $dayNames[(int)$r['day_of_week']] ?? $r['day_of_week'],
            'hour' => sprintf('%02d:00', (int)$r['hour']),
            'total_posts' => (int)$r['total'],
            'successes' => (int)$r['successes'],
        ], $rows);
    }
}
