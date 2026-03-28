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
        $stmt = $this->pdo->prepare('INSERT INTO landing_pages(title, slug, template, status, meta_title, meta_description, hero_heading, hero_subheading, hero_cta_text, hero_cta_url, body_html, custom_css, form_id, campaign_id, sections_json, og_image, created_at) VALUES(:t,:s,:tp,:st,:mt,:md,:hh,:hs,:hct,:hcu,:bh,:cc,:fi,:ci,:sj,:og,:c)');
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
            ':sj' => is_array($data['sections_json'] ?? null) ? json_encode($data['sections_json']) : ($data['sections_json'] ?? '[]'),
            ':og' => $data['og_image'] ?? '',
            ':c' => gmdate(DATE_ATOM),
        ]);
        return $this->find((int)$this->pdo->lastInsertId());
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = [':id' => $id];
        $allowed = ['title', 'slug', 'template', 'status', 'meta_title', 'meta_description', 'hero_heading', 'hero_subheading', 'hero_cta_text', 'hero_cta_url', 'body_html', 'custom_css', 'form_id', 'campaign_id', 'sections_json', 'og_image'];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $val = $data[$col];
                if ($col === 'sections_json' && is_array($val)) $val = json_encode($val);
                $params[":{$col}"] = $val;
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
        $sections = json_decode($page['sections_json'] ?? '[]', true) ?: [];

        return '<!doctype html><html lang="en"><head>'
            . '<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . htmlspecialchars($page['meta_title'] ?: $page['title']) . '</title>'
            . '<meta name="description" content="' . htmlspecialchars($page['meta_description'] ?? '') . '">'
            . $this->renderOgTags($page)
            . '<style>' . $this->baseStyles($template) . $this->sectionStyles() . $css . '</style>'
            . '</head><body>'
            . '<div class="lp-container">'
            . $this->renderHero($page)
            . $this->renderSections($sections)
            . '<div class="lp-body">' . $this->sanitizeBodyHtml($page['body_html'] ?? '') . '</div>'
            . $formHtml
            . '</div>'
            . '<script>document.querySelectorAll(".lp-form").forEach(f=>{f.addEventListener("submit",async e=>{e.preventDefault();const fd=new FormData(f);const d=Object.fromEntries(fd.entries());const r=await fetch("/api/forms/"+f.dataset.slug+"/submit",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(d)});const j=await r.json();if(j.success){f.innerHTML="<p class=\\"lp-success\\">"+j.message+"</p>";}else{alert(j.error||"Error");}})});</script>'
            . '</body></html>';
    }

    private function renderOgTags(array $page): string
    {
        $title = htmlspecialchars($page['meta_title'] ?: $page['title']);
        $description = htmlspecialchars($page['meta_description'] ?? '');
        $ogImage = htmlspecialchars($page['og_image'] ?? '');
        $baseUrl = env_value('APP_URL', '');
        $url = rtrim($baseUrl, '/') . '/p/' . htmlspecialchars($page['slug']);

        $tags = '<meta property="og:type" content="website">'
            . '<meta property="og:title" content="' . $title . '">'
            . '<meta property="og:description" content="' . $description . '">'
            . '<meta property="og:url" content="' . $url . '">';
        if ($ogImage) {
            $tags .= '<meta property="og:image" content="' . $ogImage . '">';
        }
        $tags .= '<meta name="twitter:card" content="summary_large_image">'
            . '<meta name="twitter:title" content="' . $title . '">'
            . '<meta name="twitter:description" content="' . $description . '">';
        if ($ogImage) {
            $tags .= '<meta name="twitter:image" content="' . $ogImage . '">';
        }
        return $tags;
    }

    private function renderSections(array $sections): string
    {
        if (empty($sections)) return '';
        $html = '';
        foreach ($sections as $section) {
            $type = $section['type'] ?? '';
            $html .= match ($type) {
                'features' => $this->renderFeaturesSection($section),
                'testimonials' => $this->renderTestimonialsSection($section),
                'faq' => $this->renderFaqSection($section),
                'pricing' => $this->renderPricingSection($section),
                'cta' => $this->renderCtaSection($section),
                'text' => $this->renderTextSection($section),
                default => '',
            };
        }
        return $html;
    }

    private function renderFeaturesSection(array $section): string
    {
        $heading = htmlspecialchars($section['heading'] ?? 'Features');
        $items = $section['items'] ?? [];
        $html = '<div class="lp-section lp-features"><h2>' . $heading . '</h2><div class="lp-features-grid">';
        foreach ($items as $item) {
            $html .= '<div class="lp-feature-card">'
                . '<div class="lp-feature-icon">' . htmlspecialchars($item['icon'] ?? '&#9733;') . '</div>'
                . '<h3>' . htmlspecialchars($item['title'] ?? '') . '</h3>'
                . '<p>' . htmlspecialchars($item['description'] ?? '') . '</p>'
                . '</div>';
        }
        $html .= '</div></div>';
        return $html;
    }

    private function renderTestimonialsSection(array $section): string
    {
        $heading = htmlspecialchars($section['heading'] ?? 'What Our Customers Say');
        $items = $section['items'] ?? [];
        $html = '<div class="lp-section lp-testimonials"><h2>' . $heading . '</h2><div class="lp-testimonials-grid">';
        foreach ($items as $item) {
            $stars = str_repeat('&#9733;', (int)($item['rating'] ?? 5));
            $html .= '<div class="lp-testimonial-card">'
                . '<div class="lp-stars">' . $stars . '</div>'
                . '<blockquote>&ldquo;' . htmlspecialchars($item['quote'] ?? '') . '&rdquo;</blockquote>'
                . '<div class="lp-testimonial-author">'
                . '<strong>' . htmlspecialchars($item['name'] ?? '') . '</strong>'
                . ($item['role'] ?? '' ? '<span>' . htmlspecialchars($item['role']) . '</span>' : '')
                . '</div></div>';
        }
        $html .= '</div></div>';
        return $html;
    }

    private function renderFaqSection(array $section): string
    {
        $heading = htmlspecialchars($section['heading'] ?? 'Frequently Asked Questions');
        $items = $section['items'] ?? [];
        $html = '<div class="lp-section lp-faq"><h2>' . $heading . '</h2>';
        foreach ($items as $item) {
            $html .= '<details class="lp-faq-item"><summary>' . htmlspecialchars($item['question'] ?? '') . '</summary>'
                . '<p>' . htmlspecialchars($item['answer'] ?? '') . '</p></details>';
        }
        $html .= '</div>';
        return $html;
    }

    private function renderPricingSection(array $section): string
    {
        $heading = htmlspecialchars($section['heading'] ?? 'Pricing');
        $items = $section['items'] ?? [];
        $html = '<div class="lp-section lp-pricing"><h2>' . $heading . '</h2><div class="lp-pricing-grid">';
        foreach ($items as $item) {
            $featured = !empty($item['featured']) ? ' lp-pricing-featured' : '';
            $html .= '<div class="lp-pricing-card' . $featured . '">'
                . '<h3>' . htmlspecialchars($item['name'] ?? '') . '</h3>'
                . '<div class="lp-price">' . htmlspecialchars($item['price'] ?? '') . '</div>'
                . '<p>' . htmlspecialchars($item['description'] ?? '') . '</p>'
                . '<ul>';
            foreach ($item['features'] ?? [] as $feat) {
                $html .= '<li>' . htmlspecialchars($feat) . '</li>';
            }
            $html .= '</ul>';
            if (!empty($item['cta_text'])) {
                $html .= '<a href="' . htmlspecialchars($item['cta_url'] ?? '#form') . '" class="lp-cta-btn">' . htmlspecialchars($item['cta_text']) . '</a>';
            }
            $html .= '</div>';
        }
        $html .= '</div></div>';
        return $html;
    }

    private function renderCtaSection(array $section): string
    {
        $heading = htmlspecialchars($section['heading'] ?? '');
        $subheading = htmlspecialchars($section['subheading'] ?? '');
        $ctaText = htmlspecialchars($section['cta_text'] ?? 'Get Started');
        $ctaUrl = htmlspecialchars($section['cta_url'] ?? '#form');
        return '<div class="lp-section lp-cta-section">'
            . '<h2>' . $heading . '</h2>'
            . '<p>' . $subheading . '</p>'
            . '<a href="' . $ctaUrl . '" class="lp-cta-btn">' . $ctaText . '</a>'
            . '</div>';
    }

    private function renderTextSection(array $section): string
    {
        $heading = htmlspecialchars($section['heading'] ?? '');
        $body = htmlspecialchars($section['body'] ?? '');
        return '<div class="lp-section lp-text-section">'
            . ($heading ? '<h2>' . $heading . '</h2>' : '')
            . '<p>' . nl2br($body) . '</p>'
            . '</div>';
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
            $placeholder = htmlspecialchars($field['placeholder'] ?? '');

            // Non-input display elements
            if ($type === 'heading') {
                $html .= '<h4 style="margin:1rem 0 .3rem">' . htmlspecialchars($label) . '</h4>';
                continue;
            }
            if ($type === 'paragraph') {
                $html .= '<p style="color:var(--lp-text);opacity:.7;margin-bottom:.8rem;font-size:.9rem">' . htmlspecialchars($label) . '</p>';
                continue;
            }
            if ($type === 'consent') {
                $html .= '<div style="display:flex;align-items:flex-start;gap:.5rem;margin-bottom:1rem"><input type="checkbox" name="' . htmlspecialchars($name) . '" value="yes" ' . $required . ' style="width:auto;margin-top:.2rem"><label style="font-weight:400;font-size:.85rem">' . htmlspecialchars($label) . '</label></div>';
                continue;
            }

            $html .= '<div class="lp-field"><label>' . htmlspecialchars($label) . '</label>';
            if ($type === 'textarea') {
                $html .= '<textarea name="' . htmlspecialchars($name) . '" ' . $required . ' rows="4" placeholder="' . $placeholder . '"></textarea>';
            } elseif ($type === 'select') {
                $html .= '<select name="' . htmlspecialchars($name) . '" ' . $required . '><option value="">Select...</option>';
                foreach ($field['options'] ?? [] as $opt) {
                    $html .= '<option value="' . htmlspecialchars($opt) . '">' . htmlspecialchars($opt) . '</option>';
                }
                $html .= '</select>';
            } elseif ($type === 'radio') {
                foreach ($field['options'] ?? [] as $opt) {
                    $html .= '<label style="display:flex;align-items:center;gap:.4rem;font-weight:400;margin:.3rem 0"><input type="radio" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($opt) . '" ' . $required . ' style="width:auto"> ' . htmlspecialchars($opt) . '</label>';
                }
            } elseif ($type === 'checkbox') {
                foreach ($field['options'] ?? [] as $opt) {
                    $html .= '<label style="display:flex;align-items:center;gap:.4rem;font-weight:400;margin:.3rem 0"><input type="checkbox" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($opt) . '" style="width:auto"> ' . htmlspecialchars($opt) . '</label>';
                }
            } else {
                $html .= '<input type="' . htmlspecialchars($type) . '" name="' . htmlspecialchars($name) . '" ' . $required . ' placeholder="' . $placeholder . '">';
            }

            if (!empty($field['help_text'])) {
                $html .= '<div style="font-size:.75rem;opacity:.6;margin-top:.2rem">' . htmlspecialchars($field['help_text']) . '</div>';
            }
            $html .= '</div>';
        }

        // Honeypot
        $html .= '<div style="position:absolute;left:-9999px;opacity:0;height:0;width:0" aria-hidden="true"><input type="text" name="_hp" tabindex="-1" autocomplete="off"></div>';

        $html .= '<button type="submit" class="lp-submit">' . htmlspecialchars($form['submit_label'] ?? 'Submit') . '</button>';
        $html .= '</form></div>';
        return $html;
    }

    private function sanitizeBodyHtml(string $html): string
    {
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<iframe\b[^>]*>.*?</iframe>#is', '', $html);
        $html = preg_replace('#<object\b[^>]*>.*?</object>#is', '', $html);
        $html = preg_replace('#<embed\b[^>]*/?>#is', '', $html);
        $html = preg_replace('#<link\b[^>]*>#is', '', $html);
        $html = preg_replace('#<meta\b[^>]*>#is', '', $html);
        $html = preg_replace('#<base\b[^>]*>#is', '', $html);
        $html = preg_replace('#\s+on\w+\s*=\s*["\'][^"\']*["\']#is', '', $html);
        $html = preg_replace('#\s+on\w+\s*=\s*\S+#is', '', $html);
        $html = preg_replace('#(href|src|action)\s*=\s*["\']?\s*javascript\s*:#is', '$1="', $html);
        return $html;
    }

    private function sanitizeCss(string $css): string
    {
        $css = preg_replace('#</style#i', '', $css);
        $css = preg_replace('#javascript\s*:#i', '', $css);
        $css = preg_replace('#expression\s*\(#i', '', $css);
        $css = preg_replace('#@import\b#i', '', $css);
        $css = preg_replace('#-moz-binding\s*:#i', '', $css);
        $css = preg_replace('#behavior\s*:#i', '', $css);
        $css = preg_replace('#url\s*\(\s*["\']?\s*data\s*:#i', 'url(blocked:', $css);
        $css = preg_replace('#url\s*\(\s*["\']?\s*blob\s*:#i', 'url(blocked:', $css);
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

    private function sectionStyles(): string
    {
        return '.lp-section{padding:2.5rem 0;margin:1rem 0}'
            . '.lp-section h2{text-align:center;font-size:1.8rem;margin-bottom:1.5rem}'
            // Features
            . '.lp-features-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1.5rem}'
            . '.lp-feature-card{background:var(--lp-panel);border-radius:10px;padding:1.5rem;text-align:center}'
            . '.lp-feature-icon{font-size:2rem;margin-bottom:.5rem}'
            . '.lp-feature-card h3{font-size:1.1rem;margin-bottom:.4rem}'
            . '.lp-feature-card p{font-size:.9rem;opacity:.8}'
            // Testimonials
            . '.lp-testimonials-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:1.5rem}'
            . '.lp-testimonial-card{background:var(--lp-panel);border-radius:10px;padding:1.5rem}'
            . '.lp-stars{color:#f59e0b;font-size:1.1rem;margin-bottom:.5rem}'
            . '.lp-testimonial-card blockquote{font-size:.95rem;line-height:1.5;margin-bottom:.8rem;font-style:italic}'
            . '.lp-testimonial-author strong{display:block;font-size:.9rem}'
            . '.lp-testimonial-author span{font-size:.8rem;opacity:.7}'
            // FAQ
            . '.lp-faq-item{background:var(--lp-panel);border-radius:8px;padding:1rem 1.2rem;margin-bottom:.8rem;cursor:pointer}'
            . '.lp-faq-item summary{font-weight:600;font-size:1rem;list-style:none;display:flex;justify-content:space-between;align-items:center}'
            . '.lp-faq-item summary::after{content:"+";font-size:1.3rem;font-weight:bold}'
            . '.lp-faq-item[open] summary::after{content:"-"}'
            . '.lp-faq-item p{margin-top:.6rem;opacity:.85;font-size:.95rem}'
            // Pricing
            . '.lp-pricing-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.5rem}'
            . '.lp-pricing-card{background:var(--lp-panel);border-radius:12px;padding:2rem;text-align:center}'
            . '.lp-pricing-featured{border:2px solid var(--lp-accent);transform:scale(1.03)}'
            . '.lp-pricing-card h3{font-size:1.2rem;margin-bottom:.5rem}'
            . '.lp-price{font-size:2rem;font-weight:700;margin-bottom:.5rem;color:var(--lp-accent)}'
            . '.lp-pricing-card p{font-size:.9rem;opacity:.8;margin-bottom:1rem}'
            . '.lp-pricing-card ul{list-style:none;text-align:left;margin-bottom:1.5rem}'
            . '.lp-pricing-card li{padding:.3rem 0;font-size:.9rem}'
            . '.lp-pricing-card li::before{content:"\\2713 ";color:var(--lp-accent);font-weight:bold}'
            // CTA section
            . '.lp-cta-section{text-align:center;background:var(--lp-panel);border-radius:12px;padding:3rem 2rem}'
            . '.lp-cta-section p{opacity:.85;max-width:500px;margin:0 auto 1.5rem}'
            // Text section
            . '.lp-text-section p{font-size:1rem;line-height:1.7;opacity:.9}';
    }

    private function slugify(string $text): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $text), '-'));
        return $slug ?: 'page-' . time();
    }
}
