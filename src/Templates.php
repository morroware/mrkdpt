<?php

declare(strict_types=1);

final class TemplateRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM templates ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM templates WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO templates(name, type, platform, structure, variables, created_at) VALUES(:name,:type,:platform,:structure,:variables,:created_at)');
        $stmt->execute([
            ':name' => $data['name'],
            ':type' => $data['type'] ?? 'social_post',
            ':platform' => $data['platform'] ?? '',
            ':structure' => $data['structure'] ?? '',
            ':variables' => $data['variables'] ?? '',
            ':created_at' => gmdate(DATE_ATOM),
        ]);
        return $this->find((int)$this->pdo->lastInsertId());
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = [':id' => $id];
        foreach (['name', 'type', 'platform', 'structure', 'variables'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[":{$col}"] = $data[$col];
            }
        }
        if ($fields === []) {
            return $this->find($id);
        }
        $sql = 'UPDATE templates SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $this->pdo->prepare($sql)->execute($params);
        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM templates WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function duplicate(int $id): ?array
    {
        $template = $this->find($id);
        if (!$template) {
            return null;
        }
        $template['name'] .= ' (copy)';
        return $this->create($template);
    }

    /**
     * Render a template by replacing {{variable}} placeholders with values.
     */
    public function render(int $id, array $values): ?string
    {
        $template = $this->find($id);
        if (!$template) {
            return null;
        }
        $output = $template['structure'];
        foreach ($values as $key => $value) {
            $output = str_replace('{{' . $key . '}}', (string)$value, $output);
        }
        return $output;
    }
}

final class BrandProfileRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM brand_profiles ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM brand_profiles WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getActive(): ?array
    {
        $stmt = $this->pdo->query('SELECT * FROM brand_profiles WHERE is_active = 1 LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO brand_profiles(name, voice_tone, vocabulary, avoid_words, example_content, target_audience, is_active, created_at) VALUES(:name,:voice_tone,:vocabulary,:avoid_words,:example_content,:target_audience,:is_active,:created_at)');
        $stmt->execute([
            ':name' => $data['name'],
            ':voice_tone' => $data['voice_tone'] ?? '',
            ':vocabulary' => $data['vocabulary'] ?? '',
            ':avoid_words' => $data['avoid_words'] ?? '',
            ':example_content' => $data['example_content'] ?? '',
            ':target_audience' => $data['target_audience'] ?? '',
            ':is_active' => (int)($data['is_active'] ?? 0),
            ':created_at' => gmdate(DATE_ATOM),
        ]);
        $id = (int)$this->pdo->lastInsertId();
        if (!empty($data['is_active'])) {
            $this->setActive($id);
        }
        return $this->find($id);
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = [':id' => $id];
        foreach (['name', 'voice_tone', 'vocabulary', 'avoid_words', 'example_content', 'target_audience', 'is_active'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[":{$col}"] = $col === 'is_active' ? (int)$data[$col] : $data[$col];
            }
        }
        if ($fields === []) {
            return $this->find($id);
        }
        $this->pdo->prepare('UPDATE brand_profiles SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
        if (!empty($data['is_active'])) {
            $this->setActive($id);
        }
        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM brand_profiles WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function setActive(int $id): void
    {
        $this->pdo->exec('UPDATE brand_profiles SET is_active = 0');
        $this->pdo->prepare('UPDATE brand_profiles SET is_active = 1 WHERE id = :id')->execute([':id' => $id]);
    }
}
