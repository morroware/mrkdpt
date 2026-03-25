<?php

declare(strict_types=1);

final class Webhooks
{
    public function __construct(private PDO $pdo)
    {
    }

    /* ---- CRUD ---- */

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM webhooks ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM webhooks WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): array
    {
        $secret = bin2hex(random_bytes(24));
        $stmt = $this->pdo->prepare('INSERT INTO webhooks(event, url, secret, active, created_at) VALUES(:e,:u,:s,:a,:c)');
        $stmt->execute([
            ':e' => $data['event'],
            ':u' => $data['url'],
            ':s' => $data['secret'] ?? $secret,
            ':a' => (int)($data['active'] ?? 1),
            ':c' => gmdate(DATE_ATOM),
        ]);
        return $this->find((int)$this->pdo->lastInsertId());
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = [':id' => $id];
        foreach (['event', 'url', 'secret', 'active'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[":{$col}"] = $col === 'active' ? (int)$data[$col] : $data[$col];
            }
        }
        if ($fields) {
            $this->pdo->prepare('UPDATE webhooks SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
        }
        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM webhooks WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /* ---- dispatch ---- */

    /**
     * Fire webhooks for a given event with payload data.
     * Events: post.published, post.scheduled, campaign.created, subscriber.added, cron.completed, email.sent
     */
    public function dispatch(string $event, array $payload): array
    {
        $hooks = $this->pdo->prepare('SELECT * FROM webhooks WHERE event = :e AND active = 1');
        $hooks->execute([':e' => $event]);
        $webhooks = $hooks->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($webhooks as $hook) {
            $results[] = $this->send($hook, $event, $payload);
        }
        return $results;
    }

    /**
     * Send a test ping to a specific webhook.
     */
    public function test(int $id): array
    {
        $hook = $this->find($id);
        if (!$hook) {
            return ['success' => false, 'error' => 'Webhook not found'];
        }
        return $this->send($hook, 'test.ping', [
            'message' => 'This is a test webhook from Marketing Suite',
            'timestamp' => gmdate(DATE_ATOM),
        ]);
    }

    /* ---- internal ---- */

    private function send(array $hook, string $event, array $payload): array
    {
        $body = json_encode([
            'event' => $event,
            'timestamp' => gmdate(DATE_ATOM),
            'data' => $payload,
        ]);

        $signature = hash_hmac('sha256', $body, $hook['secret']);

        $ch = curl_init($hook['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Webhook-Signature: sha256=' . $signature,
                'X-Webhook-Event: ' . $event,
                'User-Agent: MarketingSuite/2.0',
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $success = $error === '' && $httpCode >= 200 && $httpCode < 300;

        return [
            'webhook_id' => $hook['id'],
            'url' => $hook['url'],
            'event' => $event,
            'success' => $success,
            'http_code' => $httpCode,
            'error' => $error ?: null,
        ];
    }
}
