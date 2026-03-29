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

    public function submit(int $formId, array $submissionData, string $ipHash, string $pageUrl, ?ContactRepository $contacts = null, ?EmailService $emailService = null): array
    {
        $form = $this->find($formId);
        if (!$form) return ['success' => false, 'error' => 'Form not found'];

        // Honeypot check — if the hidden _hp field is filled, it's a bot
        if (!empty($submissionData['_hp'])) {
            // Silently accept but don't store — bots think they succeeded
            return [
                'success' => true,
                'message' => $form['success_message'] ?? 'Thank you!',
                'redirect_url' => $form['redirect_url'] ?? '',
                'contact_id' => null,
            ];
        }
        // Strip honeypot field from stored data
        unset($submissionData['_hp']);

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

        // Send notification email if configured
        if ($emailService && !empty($form['notification_email'])) {
            $this->sendNotification($emailService, $form, $submissionData);
        }

        return [
            'success' => true,
            'message' => $form['success_message'] ?? 'Thank you!',
            'redirect_url' => $form['redirect_url'] ?? '',
            'contact_id' => $contactId,
        ];
    }

    private function sendNotification(EmailService $emailService, array $form, array $data): void
    {
        $subject = 'New submission: ' . $form['name'];
        $rows = '';
        foreach ($data as $key => $value) {
            $rows .= '<tr><td style="padding:6px 12px;border:1px solid #ddd;font-weight:600">' . htmlspecialchars($key) . '</td>'
                    . '<td style="padding:6px 12px;border:1px solid #ddd">' . htmlspecialchars((string)$value) . '</td></tr>';
        }
        $html = '<div style="font-family:sans-serif;max-width:600px;margin:auto">'
            . '<h2 style="color:#2563eb">New Form Submission</h2>'
            . '<p>Your form <strong>' . htmlspecialchars($form['name']) . '</strong> received a new submission.</p>'
            . '<table style="width:100%;border-collapse:collapse;margin:16px 0">' . $rows . '</table>'
            . '<p style="color:#6b7280;font-size:13px">Submitted at ' . gmdate('Y-m-d H:i:s') . ' UTC</p></div>';
        $text = "New submission for: {$form['name']}\n\n";
        foreach ($data as $key => $value) {
            $text .= "{$key}: {$value}\n";
        }

        try {
            $emailService->sendTestEmail($form['notification_email'], $subject, $html, $text);
        } catch (\Throwable $e) {
            // Notification failure should not break form submission
        }
    }

    public function submissions(int $formId): array
    {
        $stmt = $this->pdo->prepare('SELECT fs.*, c.email as contact_email, c.first_name, c.last_name FROM form_submissions fs LEFT JOIN contacts c ON c.id = fs.contact_id WHERE fs.form_id = :fi ORDER BY fs.id DESC');
        $stmt->execute([':fi' => $formId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function submissionsCsv(int $formId): string
    {
        $subs = $this->submissions($formId);
        if (empty($subs)) return '';

        // Collect all unique field names across submissions
        $allKeys = [];
        $parsedRows = [];
        foreach ($subs as $s) {
            $d = json_decode($s['data_json'] ?? '{}', true) ?: [];
            foreach (array_keys($d) as $k) {
                if (!in_array($k, $allKeys)) $allKeys[] = $k;
            }
            $parsedRows[] = ['submitted_at' => $s['submitted_at'], 'contact_email' => $s['contact_email'] ?? '', 'page_url' => $s['page_url'] ?? '', 'fields' => $d];
        }

        $output = fopen('php://temp', 'r+');
        $headers = array_merge(['submitted_at', 'contact_email', 'page_url'], $allKeys);
        fputcsv($output, $headers);
        foreach ($parsedRows as $row) {
            $line = [$row['submitted_at'], $row['contact_email'], $row['page_url']];
            foreach ($allKeys as $k) {
                $line[] = $row['fields'][$k] ?? '';
            }
            fputcsv($output, $line);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
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
            . '.field-help{font-size:.75rem;color:#9ca3af;margin-top:.2rem}'
            . '.checkbox-group,.radio-group{display:flex;flex-direction:column;gap:.4rem}'
            . '.checkbox-group label,.radio-group label{display:flex;align-items:center;gap:.5rem;font-weight:400;font-size:.9rem;cursor:pointer}'
            . '.checkbox-group input,.radio-group input{width:auto}'
            . '.consent-field{display:flex;align-items:flex-start;gap:.5rem;margin-bottom:1rem}.consent-field input{width:auto;margin-top:.2rem}.consent-field label{font-weight:400;font-size:.85rem}'
            . '.heading-field{margin:1.2rem 0 .4rem}.heading-field h3{font-size:1.1rem;color:#374151}.paragraph-field{margin-bottom:1rem;color:#6b7280;font-size:.9rem}'
            . 'button{width:100%;padding:.75rem;background:#2563eb;color:#fff;border:none;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer;margin-top:.5rem}'
            . 'button:hover{background:#1d4ed8}.success{text-align:center;padding:2rem;font-size:1.1rem;color:#16a34a}'
            . '.hp-field{position:absolute;left:-9999px;top:-9999px;opacity:0;height:0;width:0;z-index:-1;pointer-events:none;tab-index:-1}'
            . '</style></head>'
            . '<body><div class="form-wrap"><h2>' . htmlspecialchars($form['name']) . '</h2>'
            . '<form id="captureForm">';

        foreach ($fields as $field) {
            $html .= $this->renderFieldHtml($field);
        }

        // Honeypot field
        $html .= '<div class="hp-field" aria-hidden="true"><input type="text" name="_hp" tabindex="-1" autocomplete="off"></div>';

        $html .= '<button type="submit">' . htmlspecialchars($form['submit_label'] ?? 'Submit') . '</button></form></div>';
        $safeSlug = json_encode($form['slug'], JSON_UNESCAPED_SLASHES);
        $html .= '<script>document.getElementById("captureForm").addEventListener("submit",async e=>{e.preventDefault();const fd=new FormData(e.target);const d=Object.fromEntries(fd.entries());'
            . 'const cbs=e.target.querySelectorAll("input[type=checkbox]");cbs.forEach(cb=>{if(cb.name&&!cb.name.startsWith("_")){if(!d[cb.name])d[cb.name]=[];if(cb.checked){if(typeof d[cb.name]==="string")d[cb.name]=[d[cb.name]];d[cb.name].push?d[cb.name].push(cb.value):d[cb.name]=cb.value;}}});'
            . 'const r=await fetch("/api/forms/"+' . $safeSlug . '+"/submit",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(d)});const j=await r.json();if(j.success){if(j.redirect_url){window.location.href=j.redirect_url}else{e.target.parentElement.innerHTML="<div class=\\"success\\">"+j.message+"</div>";}}else{alert(j.error||"Error");}});</script></body></html>';

        return $html;
    }

    private function renderFieldHtml(array $field): string
    {
        $name = htmlspecialchars($field['name'] ?? '');
        $label = htmlspecialchars($field['label'] ?? ucfirst($field['name'] ?? ''));
        $type = $field['type'] ?? 'text';
        $req = !empty($field['required']) ? 'required' : '';
        $placeholder = htmlspecialchars($field['placeholder'] ?? '');
        $helpText = htmlspecialchars($field['help_text'] ?? '');
        $options = $field['options'] ?? [];

        // Non-input display elements
        if ($type === 'heading') {
            return '<div class="heading-field"><h3>' . $label . '</h3></div>';
        }
        if ($type === 'paragraph') {
            return '<div class="paragraph-field">' . $label . '</div>';
        }
        if ($type === 'consent') {
            return '<div class="consent-field"><input type="checkbox" name="' . $name . '" value="yes" ' . $req . ' id="cf_' . $name . '"><label for="cf_' . $name . '">' . $label . '</label></div>';
        }

        $html = '<div class="field"><label>' . $label . '</label>';

        if ($type === 'textarea') {
            $html .= '<textarea name="' . $name . '" ' . $req . ' rows="4" placeholder="' . $placeholder . '"></textarea>';
        } elseif ($type === 'select') {
            $html .= '<select name="' . $name . '" ' . $req . '><option value="">Select...</option>';
            foreach ($options as $opt) {
                $html .= '<option value="' . htmlspecialchars($opt) . '">' . htmlspecialchars($opt) . '</option>';
            }
            $html .= '</select>';
        } elseif ($type === 'checkbox') {
            $html .= '<div class="checkbox-group">';
            foreach ($options as $opt) {
                $html .= '<label><input type="checkbox" name="' . $name . '" value="' . htmlspecialchars($opt) . '"> ' . htmlspecialchars($opt) . '</label>';
            }
            $html .= '</div>';
        } elseif ($type === 'radio') {
            $html .= '<div class="radio-group">';
            foreach ($options as $opt) {
                $html .= '<label><input type="radio" name="' . $name . '" value="' . htmlspecialchars($opt) . '" ' . $req . '> ' . htmlspecialchars($opt) . '</label>';
            }
            $html .= '</div>';
        } else {
            $html .= '<input type="' . htmlspecialchars($type) . '" name="' . $name . '" ' . $req . ' placeholder="' . $placeholder . '">';
        }

        if ($helpText) {
            $html .= '<div class="field-help">' . $helpText . '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    private function slugify(string $text): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $text), '-'));
        $slug = $slug ?: 'form-' . time();

        // Ensure uniqueness by appending a numeric suffix if needed
        $base = $slug;
        $suffix = 1;
        $check = $this->pdo->prepare('SELECT COUNT(*) FROM forms WHERE slug = :s');
        while ($check->execute([':s' => $slug]) && (int)$check->fetchColumn() > 0) {
            $slug = $base . '-' . (++$suffix);
        }
        return $slug;
    }
}
