<?php

declare(strict_types=1);

final class UtmBuilder
{
    public function __construct(private PDO $pdo)
    {
    }

    public function build(array $data): array
    {
        $base = rtrim($data['base_url'], '?&');
        $params = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $key) {
            $val = trim($data[$key] ?? '');
            if ($val !== '') {
                $params[$key] = $val;
            }
        }
        $query = http_build_query($params);
        if ($query !== '') {
            $separator = str_contains($base, '?') ? '&' : '?';
            $fullUrl = $base . $separator . $query;
        } else {
            $fullUrl = $base;
        }

        $stmt = $this->pdo->prepare('INSERT INTO utm_links(campaign_name, base_url, utm_source, utm_medium, utm_campaign, utm_term, utm_content, full_url, created_at) VALUES(:cn,:bu,:us,:um,:uc,:ut,:uco,:fu,:c)');
        $stmt->execute([
            ':cn' => $data['campaign_name'] ?? $data['utm_campaign'],
            ':bu' => $data['base_url'],
            ':us' => $data['utm_source'],
            ':um' => $data['utm_medium'],
            ':uc' => $data['utm_campaign'],
            ':ut' => $data['utm_term'] ?? '',
            ':uco' => $data['utm_content'] ?? '',
            ':fu' => $fullUrl,
            ':c' => gmdate(DATE_ATOM),
        ]);

        return $this->find((int)$this->pdo->lastInsertId());
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM utm_links ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM utm_links WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM utm_links WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function incrementClicks(int $id): void
    {
        $this->pdo->prepare('UPDATE utm_links SET clicks = clicks + 1 WHERE id = :id')->execute([':id' => $id]);
    }
}
