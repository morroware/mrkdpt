<?php

declare(strict_types=1);

final class SegmentRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        $segments = $this->pdo->query('SELECT * FROM audience_segments ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($segments as &$seg) {
            $seg['criteria'] = json_decode($seg['criteria'] ?? '{}', true) ?: [];
        }
        return $segments;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM audience_segments WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['criteria'] = json_decode($row['criteria'] ?? '{}', true) ?: [];
        }
        return $row ?: null;
    }

    public function create(array $data): array
    {
        $criteria = $data['criteria'] ?? [];
        if (is_array($criteria)) {
            $criteria = json_encode($criteria);
        }

        $this->pdo->prepare('INSERT INTO audience_segments(name, description, criteria, is_dynamic, created_at) VALUES(:n,:d,:c,:dyn,:ca)')->execute([
            ':n' => $data['name'],
            ':d' => $data['description'] ?? '',
            ':c' => $criteria,
            ':dyn' => (int)($data['is_dynamic'] ?? 1),
            ':ca' => gmdate(DATE_ATOM),
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $this->recompute($id);
        return $this->find($id);
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = [':id' => $id];
        foreach (['name', 'description', 'criteria', 'is_dynamic'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $val = $data[$col];
                if ($col === 'criteria' && is_array($val)) {
                    $val = json_encode($val);
                }
                $params[":{$col}"] = $val;
            }
        }
        if ($fields) {
            $this->pdo->prepare('UPDATE audience_segments SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
        }
        $this->recompute($id);
        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM audience_segments WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get contacts matching a segment's criteria.
     */
    public function contacts(int $segmentId, int $limit = 200): array
    {
        $segment = $this->find($segmentId);
        if (!$segment) return [];

        $criteria = $segment['criteria'];
        $where = [];
        $params = [];

        if (!empty($criteria['stage'])) {
            if (is_array($criteria['stage'])) {
                $placeholders = [];
                foreach ($criteria['stage'] as $i => $s) {
                    $key = ":stage_{$i}";
                    $placeholders[] = $key;
                    $params[$key] = $s;
                }
                $where[] = 'stage IN (' . implode(',', $placeholders) . ')';
            } else {
                $where[] = 'stage = :stage';
                $params[':stage'] = $criteria['stage'];
            }
        }

        if (!empty($criteria['min_score'])) {
            $where[] = 'score >= :min_score';
            $params[':min_score'] = (int)$criteria['min_score'];
        }

        if (!empty($criteria['max_score'])) {
            $where[] = 'score <= :max_score';
            $params[':max_score'] = (int)$criteria['max_score'];
        }

        if (!empty($criteria['tags'])) {
            $tags = is_array($criteria['tags']) ? $criteria['tags'] : explode(',', $criteria['tags']);
            foreach ($tags as $i => $tag) {
                $key = ":tag_{$i}";
                $where[] = "tags LIKE {$key}";
                $params[$key] = '%' . trim($tag) . '%';
            }
        }

        if (!empty($criteria['source'])) {
            $where[] = 'source = :source';
            $params[':source'] = $criteria['source'];
        }

        if (!empty($criteria['company'])) {
            $where[] = 'company LIKE :company';
            $params[':company'] = '%' . $criteria['company'] . '%';
        }

        if (!empty($criteria['created_after'])) {
            $where[] = 'created_at >= :created_after';
            $params[':created_after'] = $criteria['created_after'];
        }

        if (!empty($criteria['created_before'])) {
            $where[] = 'created_at <= :created_before';
            $params[':created_before'] = $criteria['created_before'];
        }

        if (!empty($criteria['has_activity_since'])) {
            $where[] = 'last_activity >= :activity_since';
            $params[':activity_since'] = $criteria['has_activity_since'];
        }

        if (!empty($criteria['no_activity_since'])) {
            $where[] = '(last_activity IS NULL OR last_activity < :no_activity)';
            $params[':no_activity'] = $criteria['no_activity_since'];
        }

        $sql = 'SELECT * FROM contacts';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY score DESC, id DESC LIMIT :lim';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Recompute contact count for a segment using COUNT(*) instead of fetching all rows.
     */
    public function recompute(int $id): void
    {
        $segment = $this->find($id);
        if (!$segment) return;

        $criteria = json_decode($segment['criteria'] ?? '{}', true) ?: [];
        $where = [];
        $params = [];

        // Build the same WHERE clause as contacts() but for COUNT
        if (!empty($criteria['stage'])) {
            if (is_array($criteria['stage'])) {
                $placeholders = [];
                foreach ($criteria['stage'] as $i => $s) {
                    $key = ":stage_{$i}";
                    $placeholders[] = $key;
                    $params[$key] = $s;
                }
                $where[] = 'stage IN (' . implode(',', $placeholders) . ')';
            } else {
                $where[] = 'stage = :stage';
                $params[':stage'] = $criteria['stage'];
            }
        }
        if (!empty($criteria['min_score'])) { $where[] = 'score >= :min_score'; $params[':min_score'] = (int)$criteria['min_score']; }
        if (!empty($criteria['max_score'])) { $where[] = 'score <= :max_score'; $params[':max_score'] = (int)$criteria['max_score']; }
        if (!empty($criteria['tags'])) {
            $tags = is_array($criteria['tags']) ? $criteria['tags'] : explode(',', $criteria['tags']);
            foreach ($tags as $i => $tag) { $key = ":tag_{$i}"; $where[] = "tags LIKE {$key}"; $params[$key] = '%' . trim($tag) . '%'; }
        }
        if (!empty($criteria['source'])) { $where[] = 'source = :source'; $params[':source'] = $criteria['source']; }
        if (!empty($criteria['company'])) { $where[] = 'company LIKE :company'; $params[':company'] = '%' . $criteria['company'] . '%'; }
        if (!empty($criteria['created_after'])) { $where[] = 'created_at >= :created_after'; $params[':created_after'] = $criteria['created_after']; }
        if (!empty($criteria['created_before'])) { $where[] = 'created_at <= :created_before'; $params[':created_before'] = $criteria['created_before']; }
        if (!empty($criteria['has_activity_since'])) { $where[] = 'last_activity >= :activity_since'; $params[':activity_since'] = $criteria['has_activity_since']; }
        if (!empty($criteria['no_activity_since'])) { $where[] = '(last_activity IS NULL OR last_activity < :no_activity)'; $params[':no_activity'] = $criteria['no_activity_since']; }

        $sql = 'SELECT COUNT(*) FROM contacts';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $count = (int)$stmt->fetchColumn();

        $this->pdo->prepare('UPDATE audience_segments SET contact_count = :c, last_computed = :lc WHERE id = :id')->execute([
            ':c' => $count,
            ':lc' => gmdate(DATE_ATOM),
            ':id' => $id,
        ]);
    }

    /**
     * Available criteria fields for the UI.
     */
    public static function criteriaFields(): array
    {
        return [
            'stage' => ['type' => 'select_multi', 'label' => 'Contact Stage', 'options' => ['lead', 'mql', 'sql', 'opportunity', 'customer']],
            'min_score' => ['type' => 'number', 'label' => 'Minimum Score'],
            'max_score' => ['type' => 'number', 'label' => 'Maximum Score'],
            'tags' => ['type' => 'text', 'label' => 'Has Tags (comma-separated)'],
            'source' => ['type' => 'text', 'label' => 'Source'],
            'company' => ['type' => 'text', 'label' => 'Company Contains'],
            'created_after' => ['type' => 'date', 'label' => 'Created After'],
            'created_before' => ['type' => 'date', 'label' => 'Created Before'],
            'has_activity_since' => ['type' => 'date', 'label' => 'Active Since'],
            'no_activity_since' => ['type' => 'date', 'label' => 'Inactive Since'],
        ];
    }
}
