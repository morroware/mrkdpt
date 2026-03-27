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
        $url = $data['url'] ?? '';
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException('Webhook URL must be a valid HTTP(S) URL');
        }
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

    /**
     * Validate that a webhook URL does not target a private/internal network address (SSRF protection).
     *
     * @throws \InvalidArgumentException if the URL resolves to a private/internal IP.
     */
    private function validateUrlNotInternal(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false || $host === '') {
            throw new \InvalidArgumentException('Webhook URL has no valid host.');
        }

        // Resolve hostname to IP addresses
        $ips = gethostbynamel($host);
        if ($ips === false) {
            // Also check if the host itself is a literal IP
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                $ips = [$host];
            } else {
                throw new \InvalidArgumentException('Webhook URL host could not be resolved.');
            }
        }

        foreach ($ips as $ip) {
            if ($this->isPrivateIp($ip)) {
                throw new \InvalidArgumentException('Webhook URL must not target private/internal network addresses.');
            }
        }
    }

    /**
     * Check whether an IP address belongs to a private/internal/reserved range.
     */
    private function isPrivateIp(string $ip): bool
    {
        // IPv6 loopback
        if ($ip === '::1' || $ip === '0:0:0:0:0:0:0:1') {
            return true;
        }

        // Use PHP's built-in filter to reject private and reserved ranges:
        // 127.0.0.0/8, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 169.254.0.0/16,
        // 0.0.0.0/8, 100.64.0.0/10, 192.0.0.0/24, 198.18.0.0/15, fc00::/7, fe80::/10, etc.
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            return true;
        }

        return false;
    }

    private function send(array $hook, string $event, array $payload): array
    {
        // SSRF protection: reject private/internal network targets
        try {
            $this->validateUrlNotInternal($hook['url']);
        } catch (\InvalidArgumentException $e) {
            return [
                'webhook_id' => $hook['id'],
                'url' => $hook['url'],
                'event' => $event,
                'success' => false,
                'http_code' => 0,
                'error' => $e->getMessage(),
            ];
        }

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
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
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
