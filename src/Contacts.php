<?php

declare(strict_types=1);

final class ContactRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(?string $stage = null, ?string $search = null): array
    {
        $where = [];
        $params = [];
        if ($stage) {
            $where[] = 'stage = :stage';
            $params[':stage'] = $stage;
        }
        if ($search) {
            $where[] = '(email LIKE :s OR first_name LIKE :s2 OR last_name LIKE :s3 OR company LIKE :s4)';
            $params[':s'] = "%{$search}%";
            $params[':s2'] = "%{$search}%";
            $params[':s3'] = "%{$search}%";
            $params[':s4'] = "%{$search}%";
        }
        $sql = 'SELECT * FROM contacts';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM contacts WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM contacts WHERE email = :e LIMIT 1');
        $stmt->execute([':e' => strtolower(trim($email))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): array
    {
        $email = strtolower(trim($data['email']));
        $existing = $this->findByEmail($email);
        if ($existing) {
            return $this->update($existing['id'], $data);
        }

        $stmt = $this->pdo->prepare('INSERT INTO contacts(email, first_name, last_name, company, phone, source, source_detail, stage, score, tags, notes, custom_fields, created_at) VALUES(:e,:fn,:ln,:co,:ph,:src,:sd,:st,:sc,:tg,:nt,:cf,:c)');
        $stmt->execute([
            ':e' => $email,
            ':fn' => $data['first_name'] ?? '',
            ':ln' => $data['last_name'] ?? '',
            ':co' => $data['company'] ?? '',
            ':ph' => $data['phone'] ?? '',
            ':src' => $data['source'] ?? 'manual',
            ':sd' => $data['source_detail'] ?? '',
            ':st' => $data['stage'] ?? 'lead',
            ':sc' => (int)($data['score'] ?? 0),
            ':tg' => $data['tags'] ?? '',
            ':nt' => $data['notes'] ?? '',
            ':cf' => is_array($data['custom_fields'] ?? null) ? json_encode($data['custom_fields']) : ($data['custom_fields'] ?? '{}'),
            ':c' => gmdate(DATE_ATOM),
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $this->logActivity($id, 'created', 'Contact created via ' . ($data['source'] ?? 'manual'));
        return $this->find($id);
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = [':id' => $id];
        $allowed = ['first_name', 'last_name', 'company', 'phone', 'source', 'source_detail', 'stage', 'score', 'tags', 'notes', 'custom_fields'];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $val = $data[$col];
                if ($col === 'custom_fields' && is_array($val)) $val = json_encode($val);
                $params[":{$col}"] = $val;
            }
        }
        if ($fields) {
            $fields[] = "updated_at = :ua";
            $params[':ua'] = gmdate(DATE_ATOM);
            $this->pdo->prepare('UPDATE contacts SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
        }
        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $this->pdo->prepare('DELETE FROM contact_activities WHERE contact_id = :id')->execute([':id' => $id]);
        $this->pdo->prepare('DELETE FROM contact_notes WHERE contact_id = :id')->execute([':id' => $id]);
        $this->pdo->prepare('DELETE FROM contact_tasks WHERE contact_id = :id')->execute([':id' => $id]);
        $this->pdo->prepare('DELETE FROM deals WHERE contact_id = :id')->execute([':id' => $id]);
        $stmt = $this->pdo->prepare('DELETE FROM contacts WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function logActivity(int $contactId, string $type, string $description, array $data = []): void
    {
        $this->pdo->prepare('INSERT INTO contact_activities(contact_id, activity_type, description, data_json, created_at) VALUES(:ci,:at,:d,:dj,:c)')->execute([
            ':ci' => $contactId,
            ':at' => $type,
            ':d' => $description,
            ':dj' => json_encode($data),
            ':c' => gmdate(DATE_ATOM),
        ]);
        $this->pdo->prepare('UPDATE contacts SET last_activity = :la WHERE id = :id')->execute([
            ':la' => gmdate(DATE_ATOM),
            ':id' => $contactId,
        ]);
    }

    public function activities(int $contactId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM contact_activities WHERE contact_id = :ci ORDER BY id DESC LIMIT 50');
        $stmt->execute([':ci' => $contactId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function stageBreakdown(): array
    {
        return $this->pdo->query("SELECT stage, COUNT(*) as count FROM contacts GROUP BY stage ORDER BY CASE stage WHEN 'lead' THEN 1 WHEN 'mql' THEN 2 WHEN 'sql' THEN 3 WHEN 'opportunity' THEN 4 WHEN 'customer' THEN 5 ELSE 6 END")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function metrics(): array
    {
        $row = $this->pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN stage='lead' THEN 1 ELSE 0 END) as leads, SUM(CASE WHEN stage='mql' THEN 1 ELSE 0 END) as mqls, SUM(CASE WHEN stage='sql' THEN 1 ELSE 0 END) as sqls, SUM(CASE WHEN stage='opportunity' THEN 1 ELSE 0 END) as opportunities, SUM(CASE WHEN stage='customer' THEN 1 ELSE 0 END) as customers, AVG(score) as avg_score FROM contacts")->fetch(PDO::FETCH_ASSOC);

        // Pipeline value
        $dealRow = $this->pdo->query("SELECT COUNT(*) as open_deals, COALESCE(SUM(value),0) as pipeline_value FROM deals WHERE status = 'open'")->fetch(PDO::FETCH_ASSOC);
        $wonRow = $this->pdo->query("SELECT COALESCE(SUM(value),0) as won_value FROM deals WHERE status = 'won'")->fetch(PDO::FETCH_ASSOC);
        $taskRow = $this->pdo->query("SELECT COUNT(*) as overdue_tasks FROM contact_tasks WHERE status = 'pending' AND due_date < date('now')")->fetch(PDO::FETCH_ASSOC);

        return array_merge($row ?: [], [
            'open_deals' => (int)($dealRow['open_deals'] ?? 0),
            'pipeline_value' => (float)($dealRow['pipeline_value'] ?? 0),
            'won_value' => (float)($wonRow['won_value'] ?? 0),
            'overdue_tasks' => (int)($taskRow['overdue_tasks'] ?? 0),
        ]);
    }

    // =========================================================================
    // Deals
    // =========================================================================

    public function createDeal(array $data): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO deals(contact_id, title, value, currency, stage, probability, expected_close, description, status, created_at) VALUES(:ci,:t,:v,:cu,:st,:pr,:ec,:d,:s,:c)');
        $stmt->execute([
            ':ci' => (int)$data['contact_id'],
            ':t' => $data['title'],
            ':v' => (float)($data['value'] ?? 0),
            ':cu' => $data['currency'] ?? 'USD',
            ':st' => $data['stage'] ?? 'lead',
            ':pr' => (int)($data['probability'] ?? 0),
            ':ec' => $data['expected_close'] ?? '',
            ':d' => $data['description'] ?? '',
            ':s' => $data['status'] ?? 'open',
            ':c' => gmdate(DATE_ATOM),
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $this->logActivity((int)$data['contact_id'], 'deal_created', 'Deal created: ' . $data['title'], ['deal_id' => $id, 'value' => $data['value'] ?? 0]);
        return $this->findDeal($id);
    }

    public function findDeal(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT d.*, c.email as contact_email, c.first_name, c.last_name FROM deals d LEFT JOIN contacts c ON c.id = d.contact_id WHERE d.id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function contactDeals(int $contactId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM deals WHERE contact_id = :ci ORDER BY id DESC');
        $stmt->execute([':ci' => $contactId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function allDeals(?string $status = null): array
    {
        $sql = 'SELECT d.*, c.email as contact_email, c.first_name, c.last_name FROM deals d LEFT JOIN contacts c ON c.id = d.contact_id';
        $params = [];
        if ($status) {
            $sql .= ' WHERE d.status = :st';
            $params[':st'] = $status;
        }
        $sql .= ' ORDER BY d.id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateDeal(int $id, array $data): ?array
    {
        $fields = [];
        $params = [':id' => $id];
        $allowed = ['title', 'value', 'currency', 'stage', 'probability', 'expected_close', 'description', 'status', 'lost_reason'];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[":{$col}"] = $data[$col];
            }
        }
        if (isset($data['status'])) {
            if ($data['status'] === 'won') {
                $fields[] = "won_at = :won_at";
                $params[':won_at'] = gmdate(DATE_ATOM);
            } elseif ($data['status'] === 'lost') {
                $fields[] = "lost_at = :lost_at";
                $params[':lost_at'] = gmdate(DATE_ATOM);
            }
        }
        if ($fields) {
            $fields[] = "updated_at = :ua";
            $params[':ua'] = gmdate(DATE_ATOM);
            $this->pdo->prepare('UPDATE deals SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
        }
        return $this->findDeal($id);
    }

    public function deleteDeal(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM deals WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    // =========================================================================
    // Tasks
    // =========================================================================

    public function createTask(array $data): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO contact_tasks(contact_id, deal_id, title, description, due_date, priority, status, created_at) VALUES(:ci,:di,:t,:d,:dd,:p,:s,:c)');
        $stmt->execute([
            ':ci' => !empty($data['contact_id']) ? (int)$data['contact_id'] : null,
            ':di' => !empty($data['deal_id']) ? (int)$data['deal_id'] : null,
            ':t' => $data['title'],
            ':d' => $data['description'] ?? '',
            ':dd' => $data['due_date'] ?? '',
            ':p' => $data['priority'] ?? 'medium',
            ':s' => $data['status'] ?? 'pending',
            ':c' => gmdate(DATE_ATOM),
        ]);
        return $this->findTask((int)$this->pdo->lastInsertId());
    }

    public function findTask(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT t.*, c.email as contact_email, c.first_name, c.last_name FROM contact_tasks t LEFT JOIN contacts c ON c.id = t.contact_id WHERE t.id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function contactTasks(int $contactId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM contact_tasks WHERE contact_id = :ci ORDER BY CASE WHEN status="pending" THEN 0 ELSE 1 END, due_date ASC');
        $stmt->execute([':ci' => $contactId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function allTasks(?string $status = null): array
    {
        $sql = 'SELECT t.*, c.email as contact_email, c.first_name, c.last_name FROM contact_tasks t LEFT JOIN contacts c ON c.id = t.contact_id';
        $params = [];
        if ($status) {
            $sql .= ' WHERE t.status = :st';
            $params[':st'] = $status;
        }
        $sql .= ' ORDER BY CASE WHEN t.status="pending" THEN 0 ELSE 1 END, t.due_date ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateTask(int $id, array $data): ?array
    {
        $fields = [];
        $params = [':id' => $id];
        $allowed = ['title', 'description', 'due_date', 'priority', 'status'];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[":{$col}"] = $data[$col];
            }
        }
        if (isset($data['status']) && $data['status'] === 'completed') {
            $fields[] = "completed_at = :ca";
            $params[':ca'] = gmdate(DATE_ATOM);
        }
        if ($fields) {
            $this->pdo->prepare('UPDATE contact_tasks SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
        }
        return $this->findTask($id);
    }

    public function deleteTask(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM contact_tasks WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    // =========================================================================
    // Notes
    // =========================================================================

    public function createNote(int $contactId, string $content): array
    {
        $this->pdo->prepare('INSERT INTO contact_notes(contact_id, content, created_at) VALUES(:ci,:co,:c)')->execute([
            ':ci' => $contactId,
            ':co' => $content,
            ':c' => gmdate(DATE_ATOM),
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('SELECT * FROM contact_notes WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function contactNotes(int $contactId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM contact_notes WHERE contact_id = :ci ORDER BY id DESC');
        $stmt->execute([':ci' => $contactId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteNote(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM contact_notes WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
