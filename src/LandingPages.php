<?php

declare(strict_types=1);

final class LandingPageRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT lp.*, c.name as campaign_name FROM landing_pages lp LEFT JOIN campaigns c ON c.id = lp.campaign_id ORDER BY lp.id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT lp.*, c.name as campaign_name FROM landing_pages lp LEFT JOIN campaigns c ON c.id = lp.campaign_id WHERE lp.id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM landing_pages WHERE slug = :s AND status = "published" LIMIT 1');
        $stmt->execute([':s' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO landing_pages(title, slug, template, status, meta_title, meta_description, hero_heading, hero_subheading, hero_cta_text, hero_cta_url, body_html, custom_css, form_id, campaign_id, created_at) VALUES(:t,:s,:tp,:st,:mt,:md,:hh,:hs,:hct,:hcu,:bh,:cc,:fi,:ci,:c)');
        $stmt->execute([
            ':t' => $data['title'],
            ':s' => $this->slugify($data['slug'] ?? $data['title']),
            ':tp' => $data['template'] ?? 'blank',
            ':st' => $data['status'] ?? 'draft',
            ':mt' => $data['meta_title'] ?? '',
            ':md' => $data['meta_description'] ?? '',
            ':hh' => $data['hero_heading'] ?? '',
            ':hs' => $data['hero_subheading'] ?? '',
            ':hct' => $data['hero_cta_text'] ?? '',
            ':hcu' => $data['hero_cta_url'] ?? '',
            ':bh' => $data['body_html'] ?? '',
            ':cc' => $data['custom_css'] ?? '',
            ':fi' => !empty($data['form_id']) ? (int)$data['form_id'] : null,
            ':ci' => !empty($data['campaign_id']) ? (int)$data['campaign_id'] : null,
            ':c' => gmdate(DATE_ATOM),
        ]);
        return $this->find((int)$this->pdo->lastInsertId());
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = [':id' => $id];
        $allowed = ['title', 'slug', 'template', 'status', 'meta_title', 'meta_description', 'hero_heading', 'hero_subheading', 'hero_cta_text', 'hero_cta_url', 'body_html', 'custom_css', 'form_id', 'campaign_id'];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[":{$col}"] = $data[$col];
            }
        }
        if ($fields) {
            $fields[] = "updated_at = :ua";
            $params[':ua'] = gmdate(DATE_ATOM);
            $this->pdo->prepare('UPDATE landing_pages SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
        }
        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM landing_pages WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function incrementViews(int $id): void
    {
        $this->pdo->prepare('UPDATE landing_pages SET views = views + 1 WHERE id = :id')->execute([':id' => $id]);
    }

    public function incrementConversions(int $id): void
    {
        $this->pdo->prepare('UPDATE landing_pages SET conversions = conversions + 1 WHERE id = :id')->execute([':id' => $id]);
    }

    public function render(array $page, ?array $form = null): string
    {
        $formHtml = '';
        if ($form) {
            $formHtml = $this->renderForm($form, $page['slug']);
        }

        $css = $this->sanitizeCss($page['custom_css'] ?? '');
        $template = $page['template'] ?? 'blank';

        return '<!doctype html><html lang="en"><head>'
            . '<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . htmlspecialchars($page['meta_title'] ?: $page['title']) . '</title>'
            . '<meta name="description" content="' . htmlspecialchars($page['meta_description'] ?? '') . '">'
            . '<style>' . $this->baseStyles($template) . $css . '</style>'
            . '</head><body>'
            . '<div class="lp-container">'
            . $this->renderHero($page)
            . '<div class="lp-body">' . ($page['body_html'] ?? '') . '</div>'
            . $formHtml
            . '</div>'
            . '<script>document.querySelectorAll(".lp-form").forEach(f=>{f.addEventListener("submit",async e=>{e.preventDefault();const fd=new FormData(f);const d=Object.fromEntries(fd.entries());const r=await fetch("/api/forms/"+f.dataset.slug+"/submit",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(d)});const j=await r.json();if(j.success){f.innerHTML="<p class=\\"lp-success\\">"+j.message+"</p>";}else{alert(j.error||"Error");}})});</script>'
            . '</body></html>';
    }

    private function renderHero(array $page): string
    {
        if (empty($page['hero_heading'])) return '';
        $cta = '';
        if (!empty($page['hero_cta_text'])) {
            $url = $page['hero_cta_url'] ?: '#form';
            $cta = '<a href="' . htmlspecialchars($url) . '" class="lp-cta-btn">' . htmlspecialchars($page['hero_cta_text']) . '</a>';
        }
        return '<div class="lp-hero">'
            . '<h1>' . htmlspecialchars($page['hero_heading']) . '</h1>'
            . '<p>' . htmlspecialchars($page['hero_subheading'] ?? '') . '</p>'
            . $cta
            . '</div>';
    }

    private function renderForm(array $form, string $pageSlug): string
    {
        $fields = json_decode($form['fields'] ?? '[]', true);
        if (!is_array($fields) || empty($fields)) return '';

        $html = '<div class="lp-form-wrap" id="form"><h3>' . htmlspecialchars($form['name']) . '</h3>'
            . '<form class="lp-form" data-slug="' . htmlspecialchars($form['slug']) . '">';

        foreach ($fields as $field) {
            $name = $field['name'] ?? '';
            $label = $field['label'] ?? ucfirst($name);
            $type = $field['type'] ?? 'text';
            $required = !empty($field['required']) ? 'required' : '';

            $html .= '<div class="lp-field"><label>' . htmlspecialchars($label) . '</label>';
            if ($type === 'textarea') {
                $html .= '<textarea name="' . htmlspecialchars($name) . '" ' . $required . ' rows="4"></textarea>';
            } elseif ($type === 'select') {
                $html .= '<select name="' . htmlspecialchars($name) . '" ' . $required . '>';
                foreach ($field['options'] ?? [] as $opt) {
                    $html .= '<option value="' . htmlspecialchars($opt) . '">' . htmlspecialchars($opt) . '</option>';
                }
                $html .= '</select>';
            } else {
                $html .= '<input type="' . htmlspecialchars($type) . '" name="' . htmlspecialchars($name) . '" ' . $required . '>';
            }
            $html .= '</div>';
        }

        $html .= '<button type="submit" class="lp-submit">' . htmlspecialchars($form['submit_label'] ?? 'Submit') . '</button>';
        $html .= '</form></div>';
        return $html;
    }

    private function sanitizeCss(string $css): string
    {
        // Strip sequences that could escape the style tag or inject scripts
        $css = preg_replace('#</style#i', '', $css);
        $css = preg_replace('#javascript\s*:#i', '', $css);
        $css = preg_replace('#expression\s*\(#i', '', $css);
        $css = preg_replace('#@import\b#i', '', $css);
        $css = preg_replace('#-moz-binding\s*:#i', '', $css);
        $css = preg_replace('#behavior\s*:#i', '', $css);
        // Block url() with data: or blob: schemes to prevent script injection
        $css = preg_replace('#url\s*\(\s*["\']?\s*data\s*:#i', 'url(blocked:', $css);
        $css = preg_replace('#url\s*\(\s*["\']?\s*blob\s*:#i', 'url(blocked:', $css);
        // Strip HTML event attributes that could sneak in via CSS escape sequences
        $css = preg_replace('#\\\\[0-9a-fA-F]{1,6}#', '', $css);
        return $css;
    }

    private function baseStyles(string $template): string
    {
        $colors = match ($template) {
            'startup' => '--lp-accent:#6366f1;--lp-bg:#0f0f23;--lp-text:#e2e8f0;--lp-panel:#1a1a3e;',
            'minimal' => '--lp-accent:#000;--lp-bg:#fff;--lp-text:#333;--lp-panel:#f9f9f9;',
            'bold' => '--lp-accent:#ef4444;--lp-bg:#1a1a2e;--lp-text:#fff;--lp-panel:#16213e;',
            'nature' => '--lp-accent:#22c55e;--lp-bg:#f0fdf4;--lp-text:#1a2e1a;--lp-panel:#fff;',
            default => '--lp-accent:#4c8dff;--lp-bg:#0d1117;--lp-text:#e6edf3;--lp-panel:#151b23;',
        };

        return ':root{' . $colors . '}'
            . '*{box-sizing:border-box;margin:0;padding:0}'
            . 'body{font-family:Inter,-apple-system,BlinkMacSystemFont,sans-serif;background:var(--lp-bg);color:var(--lp-text);line-height:1.6}'
            . '.lp-container{max-width:720px;margin:0 auto;padding:2rem 1.5rem}'
            . '.lp-hero{text-align:center;padding:3rem 0}'
            . '.lp-hero h1{font-size:2.4rem;margin-bottom:.8rem;line-height:1.2}'
            . '.lp-hero p{font-size:1.15rem;opacity:.85;max-width:540px;margin:0 auto 1.5rem}'
            . '.lp-cta-btn{display:inline-block;padding:.75rem 2rem;background:var(--lp-accent);color:#fff;border-radius:8px;font-weight:600;font-size:1rem;text-decoration:none;transition:opacity .2s}'
            . '.lp-cta-btn:hover{opacity:.85}'
            . '.lp-body{padding:1.5rem 0;font-size:1rem}'
            . '.lp-body h2{margin:1.5rem 0 .5rem}'
            . '.lp-body p{margin-bottom:1rem}'
            . '.lp-body ul,.lp-body ol{margin:0 0 1rem 1.5rem}'
            . '.lp-form-wrap{background:var(--lp-panel);border-radius:12px;padding:2rem;margin:2rem 0}'
            . '.lp-form-wrap h3{margin-bottom:1rem;font-size:1.2rem}'
            . '.lp-field{margin-bottom:1rem}'
            . '.lp-field label{display:block;font-size:.85rem;font-weight:600;margin-bottom:.3rem}'
            . '.lp-field input,.lp-field textarea,.lp-field select{width:100%;padding:.6rem .8rem;border:1px solid rgba(128,128,128,.3);border-radius:6px;font-size:.95rem;background:transparent;color:var(--lp-text)}'
            . '.lp-field input:focus,.lp-field textarea:focus{outline:none;border-color:var(--lp-accent)}'
            . '.lp-submit{width:100%;padding:.75rem;background:var(--lp-accent);color:#fff;border:none;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer}'
            . '.lp-submit:hover{opacity:.9}'
            . '.lp-success{text-align:center;font-size:1.1rem;padding:1rem;color:var(--lp-accent)}'
            . '@media(max-width:600px){.lp-hero h1{font-size:1.7rem}.lp-container{padding:1rem}}';
    }

    private function slugify(string $text): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $text), '-'));
        return $slug ?: 'page-' . time();
    }
}
