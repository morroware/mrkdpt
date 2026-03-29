<?php

declare(strict_types=1);

final class AutomationRepository
{
    public function __construct(
        private PDO $pdo,
        private ?EmailService $emailService = null,
        private ?SmsService $smsService = null
    )
    {
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM automation_rules ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM automation_rules WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): array
    {
        $conditions = $data['conditions'] ?? '{}';
        if (is_array($conditions)) $conditions = json_encode($conditions);
        $actionConfig = $data['action_config'] ?? '{}';
        if (is_array($actionConfig)) $actionConfig = json_encode($actionConfig);

        $this->pdo->prepare('INSERT INTO automation_rules(name, trigger_event, conditions, action_type, action_config, is_active, created_at) VALUES(:n,:te,:co,:at,:ac,:ia,:c)')->execute([
            ':n' => $data['name'],
            ':te' => $data['trigger_event'],
            ':co' => $conditions,
            ':at' => $data['action_type'],
            ':ac' => $actionConfig,
            ':ia' => (int)($data['is_active'] ?? 1),
            ':c' => gmdate(DATE_ATOM),
        ]);
        return $this->find((int)$this->pdo->lastInsertId());
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = [':id' => $id];
        foreach (['name', 'trigger_event', 'conditions', 'action_type', 'action_config', 'is_active'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $val = $data[$col];
                if (in_array($col, ['conditions', 'action_config']) && is_array($val)) $val = json_encode($val);
                $params[":{$col}"] = $val;
            }
        }
        if ($fields) {
            $this->pdo->prepare('UPDATE automation_rules SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
        }
        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM automation_rules WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Fire automations for a given trigger event.
     * Returns the number of automations executed.
     */
    public function fire(string $event, array $context = []): int
    {
        $stmt = $this->pdo->prepare('SELECT * FROM automation_rules WHERE trigger_event = :e AND is_active = 1');
        $stmt->execute([':e' => $event]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $executed = 0;
        foreach ($rules as $rule) {
            $conditions = json_decode($rule['conditions'] ?? '{}', true) ?: [];
            if (!$this->checkConditions($conditions, $context)) {
                continue;
            }

            $this->executeAction($rule, $context);
            $this->pdo->prepare('UPDATE automation_rules SET run_count = run_count + 1, last_run = :lr WHERE id = :id')->execute([
                ':lr' => gmdate(DATE_ATOM),
                ':id' => $rule['id'],
            ]);
            $executed++;
        }

        return $executed;
    }

    /**
     * Available trigger events.
     */
    public static function triggerEvents(): array
    {
        return [
            'form.submitted' => 'Form Submitted',
            'contact.created' => 'Contact Created',
            'contact.stage_changed' => 'Contact Stage Changed',
            'post.published' => 'Post Published',
            'post.scheduled' => 'Post Scheduled',
            'subscriber.added' => 'Email Subscriber Added',
            'email.sent' => 'Email Campaign Sent',
            'landing_page.conversion' => 'Landing Page Conversion',
            'link.clicked' => 'Short Link Clicked',
        ];
    }

    /**
     * Available action types.
     */
    public static function actionTypes(): array
    {
        return [
            'tag_contact' => 'Add Tag to Contact',
            'update_contact_stage' => 'Update Contact Stage',
            'add_score' => 'Add to Contact Score',
            'add_to_list' => 'Add to Email List',
            'send_email' => 'Send Email to Contact',
            'send_sms' => 'Send SMS to Contact',
            'send_webhook' => 'Send Webhook',
            'log_activity' => 'Log Activity',
        ];
    }

    private function checkConditions(array $conditions, array $context): bool
    {
        foreach ($conditions as $key => $expected) {
            $actual = $context[$key] ?? null;
            if ($actual === null) return false;
            if (is_array($expected)) {
                if (!in_array($actual, $expected)) return false;
            } else {
                if ((string)$actual !== (string)$expected) return false;
            }
        }
        return true;
    }

    private function executeAction(array $rule, array $context): void
    {
        $actionType = $rule['action_type'];
        $config = json_decode($rule['action_config'] ?? '{}', true) ?: [];
        $contactId = $context['contact_id'] ?? null;
        $contact = null;

        if ($contactId) {
            $contactStmt = $this->pdo->prepare('SELECT id, email, first_name, last_name, phone, stage, tags FROM contacts WHERE id = :id LIMIT 1');
            $contactStmt->execute([':id' => $contactId]);
            $contact = $contactStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        switch ($actionType) {
            case 'tag_contact':
                if ($contactId && !empty($config['tag'])) {
                    if ($contact) {
                        $existing = array_filter(array_map('trim', explode(',', $contact['tags'] ?? '')));
                        $newTag = trim($config['tag']);
                        if (!in_array($newTag, $existing, true)) {
                            $existing[] = $newTag;
                        }
                        $tags = implode(',', $existing);
                        $this->pdo->prepare('UPDATE contacts SET tags = :t WHERE id = :id')->execute([':t' => $tags, ':id' => $contactId]);
                    }
                }
                break;

            case 'update_contact_stage':
                if ($contactId && !empty($config['stage'])) {
                    $this->pdo->prepare('UPDATE contacts SET stage = :s, updated_at = :u WHERE id = :id')->execute([
                        ':s' => $config['stage'],
                        ':u' => gmdate(DATE_ATOM),
                        ':id' => $contactId,
                    ]);
                }
                break;

            case 'add_score':
                if ($contactId && isset($config['points'])) {
                    $this->pdo->prepare('UPDATE contacts SET score = score + :p, updated_at = :u WHERE id = :id')->execute([
                        ':p' => (int)$config['points'],
                        ':u' => gmdate(DATE_ATOM),
                        ':id' => $contactId,
                    ]);
                }
                break;

            case 'add_to_list':
                if ($contactId && !empty($config['list_id'])) {
                    $contact = $this->pdo->prepare('SELECT email, first_name FROM contacts WHERE id = :id');
                    $contact->execute([':id' => $contactId]);
                    $c = $contact->fetch(PDO::FETCH_ASSOC);
                    if ($c) {
                        try {
                            $this->pdo->prepare('INSERT OR IGNORE INTO subscribers(email, name, list_id, status, subscribed_at) VALUES(:e,:n,:l,"active",:s)')->execute([
                                ':e' => $c['email'],
                                ':n' => $c['first_name'] ?? '',
                                ':l' => (int)$config['list_id'],
                                ':s' => gmdate(DATE_ATOM),
                            ]);
                        } catch (\PDOException) {
                            // Ignore duplicate
                        }
                    }
                }
                break;

            case 'send_email':
                if ($contact && $this->emailService && !empty($contact['email']) && !empty($config['subject']) && !empty($config['body_html'])) {
                    $email = (string)$contact['email'];
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $contactName = trim((string)($contact['first_name'] ?? '') . ' ' . (string)($contact['last_name'] ?? ''));
                        $replacements = [
                            '{{first_name}}' => (string)($contact['first_name'] ?? ''),
                            '{{last_name}}' => (string)($contact['last_name'] ?? ''),
                            '{{email}}' => $email,
                            '{{phone}}' => (string)($contact['phone'] ?? ''),
                            '{{stage}}' => (string)($contact['stage'] ?? ''),
                        ];
                        $subject = strtr((string)$config['subject'], $replacements);
                        $html = strtr((string)$config['body_html'], $replacements);
                        $text = trim((string)($config['body_text'] ?? strip_tags($html)));
                        $this->emailService->sendTestEmail($email, $subject, $html, $text);
                        if (!empty($config['log_activity'])) {
                            $this->pdo->prepare('INSERT INTO contact_activities(contact_id, activity_type, description, data_json, created_at) VALUES(:ci,"email_sent",:d,:dj,:c)')->execute([
                                ':ci' => $contactId,
                                ':d' => 'Automation email sent to ' . ($contactName !== '' ? $contactName : $email),
                                ':dj' => json_encode(['rule_id' => $rule['id'], 'subject' => $subject]),
                                ':c' => gmdate(DATE_ATOM),
                            ]);
                        }
                    }
                }
                break;

            case 'send_sms':
                if ($contact && $this->smsService && $this->smsService->isConfigured() && !empty($config['message'])) {
                    $phone = trim((string)($contact['phone'] ?? ''));
                    if (preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
                        $message = strtr((string)$config['message'], [
                            '{{first_name}}' => (string)($contact['first_name'] ?? ''),
                            '{{last_name}}' => (string)($contact['last_name'] ?? ''),
                            '{{email}}' => (string)($contact['email'] ?? ''),
                            '{{phone}}' => $phone,
                            '{{stage}}' => (string)($contact['stage'] ?? ''),
                        ]);
                        $sent = $this->smsService->sendMessage($phone, $message);
                        if ($sent && !empty($config['log_activity'])) {
                            $this->pdo->prepare('INSERT INTO contact_activities(contact_id, activity_type, description, data_json, created_at) VALUES(:ci,"sms_sent",:d,:dj,:c)')->execute([
                                ':ci' => $contactId,
                                ':d' => 'Automation SMS sent to ' . $phone,
                                ':dj' => json_encode(['rule_id' => $rule['id']]),
                                ':c' => gmdate(DATE_ATOM),
                            ]);
                        }
                    }
                }
                break;

            case 'send_webhook':
                if (!empty($config['url']) && preg_match('#^https?://#i', $config['url'])) {
                    // SSRF protection: reject private/internal IPs and pin DNS to prevent rebinding
                    $host = parse_url($config['url'], PHP_URL_HOST);
                    $port = parse_url($config['url'], PHP_URL_PORT) ?: (parse_url($config['url'], PHP_URL_SCHEME) === 'https' ? 443 : 80);
                    $resolvedIp = $host ? gethostbyname($host) : '';
                    if (!$host || !filter_var($resolvedIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        break; // skip internal/private IPs
                    }
                    $ch = curl_init($config['url']);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                        CURLOPT_POSTFIELDS => json_encode([
                            'event' => $rule['trigger_event'],
                            'rule_name' => $rule['name'],
                            'context' => $context,
                            'fired_at' => gmdate(DATE_ATOM),
                        ]),
                        CURLOPT_TIMEOUT => 10,
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_SSL_VERIFYHOST => 2,
                        // Pin DNS resolution to prevent DNS rebinding attacks
                        CURLOPT_RESOLVE => ["{$host}:{$port}:{$resolvedIp}"],
                    ]);
                    curl_exec($ch);
                    curl_close($ch);
                }
                break;

            case 'log_activity':
                if ($contactId) {
                    $msg = $config['message'] ?? 'Automation triggered: ' . $rule['name'];
                    $this->pdo->prepare('INSERT INTO contact_activities(contact_id, activity_type, description, data_json, created_at) VALUES(:ci,"automation",:d,:dj,:c)')->execute([
                        ':ci' => $contactId,
                        ':d' => $msg,
                        ':dj' => json_encode(['rule_id' => $rule['id']]),
                        ':c' => gmdate(DATE_ATOM),
                    ]);
                }
                break;
        }
    }
}
