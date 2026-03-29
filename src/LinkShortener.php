<?php

declare(strict_types=1);

final class LinkShortener
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(array $data): array
    {
        $code = $data['code'] ?? $this->generateCode();

        $stmt = $this->pdo->prepare('INSERT INTO short_links(code, destination_url, title, utm_link_id, created_at) VALUES(:c,:d,:t,:u,:ca)');
        $stmt->execute([
            ':c' => $code,
            ':d' => $data['destination_url'],
            ':t' => $data['title'] ?? '',
            ':u' => !empty($data['utm_link_id']) ? (int)$data['utm_link_id'] : null,
            ':ca' => gmdate(DATE_ATOM),
        ]);

        return $this->find((int)$this->pdo->lastInsertId());
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM short_links ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM short_links WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM short_links WHERE code = :c LIMIT 1');
        $stmt->execute([':c' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM short_links WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function recordClick(int $linkId, string $linkType, array $meta = []): void
    {
        $table = $linkType === 'utm' ? 'utm_links' : 'short_links';
        $this->pdo->prepare("UPDATE {$table} SET clicks = clicks + 1 WHERE id = :id")->execute([':id' => $linkId]);

        $this->pdo->prepare('INSERT INTO link_clicks(link_type, link_id, ip_hash, user_agent, referer, clicked_at) VALUES(:lt,:li,:ip,:ua,:r,:c)')->execute([
            ':lt' => $linkType,
            ':li' => $linkId,
            ':ip' => $meta['ip_hash'] ?? '',
            ':ua' => substr($meta['user_agent'] ?? '', 0, 500),
            ':r' => substr($meta['referer'] ?? '', 0, 1000),
            ':c' => gmdate(DATE_ATOM),
        ]);
    }

    public function clickStats(int $linkId, string $linkType): array
    {
        $stmt = $this->pdo->prepare('SELECT DATE(clicked_at) as date, COUNT(*) as clicks FROM link_clicks WHERE link_type = :lt AND link_id = :li GROUP BY date ORDER BY date DESC LIMIT 30');
        $stmt->execute([':lt' => $linkType, ':li' => $linkId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function generateCode(): string
    {
        $chars = 'abcdefghijkmnpqrstuvwxyz23456789';
        $length = 6;
        $stmt = $this->pdo->prepare('SELECT id FROM short_links WHERE code = :c');

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $stmt->execute([':c' => $code]);
            if (!$stmt->fetch()) {
                return $code;
            }
            // After 10 failed attempts, increase code length
            if ($attempt === 10) {
                $length = 8;
            }
        }

        throw new \RuntimeException('Unable to generate unique short code after 20 attempts');
    }
}
