<?php

declare(strict_types=1);

final class FormRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM forms ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM forms WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['fields_parsed'] = json_decode($row['fields'] ?? '[]', true) ?: [];
        }
        return $row ?: null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM forms WHERE slug = :s AND status = "active" LIMIT 1');
        $stmt->execute([':s' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['fields_parsed'] = json_decode($row['fields'] ?? '[]', true) ?: [];
        }
        return $row ?: null;
    }

    public function create(array $data): array
    {
        $fields = $data['fields'] ?? '[]';
        if (is_array($fields)) $fields = json_encode($fields);

        $stmt = $this->pdo->prepare('INSERT INTO forms(name, slug, fields, submit_label, success_message, redirect_url, notification_email, list_id, tag_on_submit, status, created_at) VALUES(:n,:s,:f,:sl,:sm,:ru,:ne,:li,:ts,:st,:c)');
        $stmt->execute([
            ':n' => $data['name'],
            ':s' => $this->slugify($data['slug'] ?? $data['name']),
            ':f' => $fields,
            ':sl' => $data['submit_label'] ?? 'Submit',
            ':sm' => $data['success_message'] ?? 'Thank you!',
            ':ru' => $data['redirect_url'] ?? '',
            ':ne' => $data['notification_email'] ?? '',
            ':li' => !empty($data['list_id']) ? (int)$data['list_id'] : null,
            ':ts' => $data['tag_on_submit'] ?? '',
            ':st' => $data['status'] ?? 'active',
            ':c' => gmdate(DATE_ATOM),
        ]);
        return $this->find((int)$this->pdo->lastInsertId());
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = [':id' => $id];
        $allowed = ['name', 'slug', 'fields', 'submit_label', 'success_message', 'redirect_url', 'notification_email', 'list_id', 'tag_on_submit', 'status'];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $val = $data[$col];
                if ($col === 'fields' && is_array($val)) $val = json_encode($val);
                $params[":{$col}"] = $val;
            }
        }
        if ($fields) {
            $this->pdo->prepare('UPDATE forms SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
        }
        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $this->pdo->prepare('DELETE FROM form_submissions WHERE form_id = :id')->execute([':id' => $id]);
        $stmt = $this->pdo->prepare('DELETE FROM forms WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function submit(int $formId, array $submissionData, string $ipHash, string $pageUrl, ?ContactRepository $contacts = null): array
    {
        $form = $this->find($formId);
        if (!$form) return ['success' => false, 'error' => 'Form not found'];

        $this->pdo->prepare('INSERT INTO form_submissions(form_id, data_json, ip_hash, page_url, submitted_at) VALUES(:fi,:dj,:ip,:pu,:s)')->execute([
            ':fi' => $formId,
            ':dj' => json_encode($submissionData),
            ':ip' => $ipHash,
            ':pu' => $pageUrl,
            ':s' => gmdate(DATE_ATOM),
        ]);
        $subId = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare('UPDATE forms SET submissions = submissions + 1 WHERE id = :id')->execute([':id' => $formId]);

        // Create/update contact if email field present
        $contactId = null;
        $email = $submissionData['email'] ?? null;
        if ($email && $contacts) {
            $contact = $contacts->create([
                'email' => $email,
                'first_name' => $submissionData['first_name'] ?? $submissionData['name'] ?? '',
                'last_name' => $submissionData['last_name'] ?? '',
                'company' => $submissionData['company'] ?? '',
                'phone' => $submissionData['phone'] ?? '',
                'source' => 'form',
                'source_detail' => $form['name'],
                'tags' => $form['tag_on_submit'] ?? '',
            ]);
            $contactId = $contact['id'] ?? null;
            if ($contactId) {
                $this->pdo->prepare('UPDATE form_submissions SET contact_id = :ci WHERE id = :id')->execute([':ci' => $contactId, ':id' => $subId]);
                $contacts->logActivity($contactId, 'form_submitted', 'Submitted form: ' . $form['name'], ['form_id' => $formId]);
            }
        }

        return [
            'success' => true,
            'message' => $form['success_message'] ?? 'Thank you!',
            'redirect_url' => $form['redirect_url'] ?? '',
            'contact_id' => $contactId,
        ];
    }

    public function submissions(int $formId): array
    {
        $stmt = $this->pdo->prepare('SELECT fs.*, c.email as contact_email, c.first_name, c.last_name FROM form_submissions fs LEFT JOIN contacts c ON c.id = fs.contact_id WHERE fs.form_id = :fi ORDER BY fs.id DESC');
        $stmt->execute([':fi' => $formId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function embedCode(array $form, string $baseUrl): string
    {
        $slug = htmlspecialchars($form['slug']);
        return '<iframe src="' . rtrim($baseUrl, '/') . '/f/' . $slug . '" style="width:100%;min-height:400px;border:none" title="' . htmlspecialchars($form['name']) . '"></iframe>';
    }

    public function renderStandalone(array $form): string
    {
        $fields = json_decode($form['fields'] ?? '[]', true) ?: [];
        $html = '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . htmlspecialchars($form['name']) . '</title>'
            . '<style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:Inter,-apple-system,sans-serif;background:#f6f8fa;color:#1f2937;display:flex;justify-content:center;padding:2rem}'
            . '.form-wrap{width:100%;max-width:480px;background:#fff;border-radius:12px;padding:2rem;box-shadow:0 2px 12px rgba(0,0,0,.08)}'
            . 'h2{margin-bottom:1rem;font-size:1.2rem}.field{margin-bottom:1rem}.field label{display:block;font-size:.85rem;font-weight:600;margin-bottom:.3rem;color:#6b7280}'
            . '.field input,.field textarea,.field select{width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem}'
            . '.field input:focus,.field textarea:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.15)}'
            . 'button{width:100%;padding:.75rem;background:#2563eb;color:#fff;border:none;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer;margin-top:.5rem}'
            . 'button:hover{background:#1d4ed8}.success{text-align:center;padding:2rem;font-size:1.1rem;color:#16a34a}</style></head>'
            . '<body><div class="form-wrap"><h2>' . htmlspecialchars($form['name']) . '</h2>'
            . '<form id="captureForm">';

        foreach ($fields as $field) {
            $name = htmlspecialchars($field['name'] ?? '');
            $label = htmlspecialchars($field['label'] ?? ucfirst($field['name'] ?? ''));
            $type = $field['type'] ?? 'text';
            $req = !empty($field['required']) ? 'required' : '';

            $html .= '<div class="field"><label>' . $label . '</label>';
            if ($type === 'textarea') {
                $html .= '<textarea name="' . $name . '" ' . $req . ' rows="4"></textarea>';
            } else {
                $html .= '<input type="' . htmlspecialchars($type) . '" name="' . $name . '" ' . $req . '>';
            }
            $html .= '</div>';
        }

        $html .= '<button type="submit">' . htmlspecialchars($form['submit_label'] ?? 'Submit') . '</button></form></div>';
        $safeSlug = json_encode($form['slug'], JSON_UNESCAPED_SLASHES);
        $html .= '<script>document.getElementById("captureForm").addEventListener("submit",async e=>{e.preventDefault();const fd=new FormData(e.target);const d=Object.fromEntries(fd.entries());const r=await fetch("/api/forms/"+' . $safeSlug . '+"/submit",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(d)});const j=await r.json();if(j.success){e.target.parentElement.innerHTML="<div class=\\"success\\">"+j.message+"</div>";}else{alert(j.error||"Error");}});</script></body></html>';

        return $html;
    }

    private function slugify(string $text): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $text), '-'));
        return $slug ?: 'form-' . time();
    }
}
