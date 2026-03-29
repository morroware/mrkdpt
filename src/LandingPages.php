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

        $bodyHtml = trim($page['body_html'] ?? '');
        $bodySection = $bodyHtml ? '<div class="lp-body">' . $this->sanitizeBodyHtml($bodyHtml) . '</div>' : '';

        return '<!doctype html><html lang="en"><head>'
            . '<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . htmlspecialchars($page['meta_title'] ?: $page['title']) . '</title>'
            . '<meta name="description" content="' . htmlspecialchars($page['meta_description'] ?? '') . '">'
            . $this->renderOgTags($page)
            . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">'
            . '<style>' . $this->baseStyles($template) . $this->sectionStyles() . $css . '</style>'
            . '</head><body>'
            . '<div class="lp-container">'
            . $this->renderHero($page)
            . $this->renderSections($sections)
            . $bodySection
            . $formHtml
            . '</div>'
            . '<script>document.querySelectorAll(".lp-form").forEach(f=>{f.addEventListener("submit",async e=>{e.preventDefault();const btn=f.querySelector(".lp-submit");if(btn){btn.disabled=true;btn.textContent="Sending...";}const fd=new FormData(f);const d=Object.fromEntries(fd.entries());try{const r=await fetch("/api/forms/"+f.dataset.slug+"/submit",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(d)});const j=await r.json();if(j.success){f.innerHTML="<p class=\\"lp-success\\">&#10003; "+j.message+"</p>";}else{alert(j.error||"Error");if(btn){btn.disabled=false;btn.textContent="Submit";}}}catch(err){alert("Something went wrong. Please try again.");if(btn){btn.disabled=false;btn.textContent="Submit";}}})});</script>'
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
        $sub = trim($page['hero_subheading'] ?? '');
        return '<div class="lp-hero" style="animation:lpFadeUp .5s ease forwards">'
            . '<h1>' . htmlspecialchars($page['hero_heading']) . '</h1>'
            . ($sub ? '<p>' . htmlspecialchars($sub) . '</p>' : '')
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
            'startup' => '--lp-accent:#6366f1;--lp-accent-hover:#818cf8;--lp-bg:#0f0f23;--lp-text:#e2e8f0;--lp-panel:#1a1a3e;--lp-panel-border:rgba(99,102,241,.15);--lp-hero-gradient:linear-gradient(135deg,#0f0f23 0%,#1a1a3e 50%,#0f0f23 100%);',
            'minimal' => '--lp-accent:#111;--lp-accent-hover:#333;--lp-bg:#fff;--lp-text:#333;--lp-panel:#f8fafc;--lp-panel-border:rgba(0,0,0,.08);--lp-hero-gradient:linear-gradient(180deg,#fff 0%,#f8fafc 100%);',
            'bold' => '--lp-accent:#ef4444;--lp-accent-hover:#f87171;--lp-bg:#1a1a2e;--lp-text:#fff;--lp-panel:#16213e;--lp-panel-border:rgba(239,68,68,.15);--lp-hero-gradient:linear-gradient(135deg,#1a1a2e 0%,#16213e 50%,#0f172a 100%);',
            'nature' => '--lp-accent:#16a34a;--lp-accent-hover:#22c55e;--lp-bg:#f0fdf4;--lp-text:#1a2e1a;--lp-panel:#fff;--lp-panel-border:rgba(22,163,74,.12);--lp-hero-gradient:linear-gradient(180deg,#f0fdf4 0%,#dcfce7 100%);',
            default => '--lp-accent:#4c8dff;--lp-accent-hover:#6ba1ff;--lp-bg:#0d1117;--lp-text:#e6edf3;--lp-panel:#151b23;--lp-panel-border:rgba(76,141,255,.12);--lp-hero-gradient:linear-gradient(135deg,#0d1117 0%,#151b23 50%,#0d1117 100%);',
        };

        return ':root{' . $colors . '}'
            . '*{box-sizing:border-box;margin:0;padding:0}'
            . 'body{font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:var(--lp-bg);color:var(--lp-text);line-height:1.7;-webkit-font-smoothing:antialiased}'
            . '.lp-container{max-width:800px;margin:0 auto;padding:2rem 1.5rem}'
            // Hero
            . '.lp-hero{text-align:center;padding:4.5rem 0 3.5rem;background:var(--lp-hero-gradient)}'
            . '.lp-hero h1{font-size:3rem;margin-bottom:1rem;line-height:1.15;font-weight:800;letter-spacing:-.02em}'
            . '.lp-hero p{font-size:1.2rem;opacity:.8;max-width:560px;margin:0 auto 2rem;line-height:1.6}'
            // CTA button
            . '.lp-cta-btn{display:inline-block;padding:.85rem 2.2rem;background:var(--lp-accent);color:#fff;border-radius:10px;font-weight:700;font-size:1rem;text-decoration:none;transition:all .25s ease;box-shadow:0 4px 14px rgba(0,0,0,.15)}'
            . '.lp-cta-btn:hover{background:var(--lp-accent-hover);transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.2)}'
            // Body
            . '.lp-body{padding:2rem 0;font-size:1.05rem}'
            . '.lp-body h2{margin:2rem 0 .75rem;font-size:1.6rem;font-weight:700}'
            . '.lp-body p{margin-bottom:1.2rem;line-height:1.7}'
            . '.lp-body ul,.lp-body ol{margin:0 0 1.2rem 1.5rem}'
            . '.lp-body li{margin-bottom:.3rem}'
            // Form
            . '.lp-form-wrap{background:var(--lp-panel);border:1px solid var(--lp-panel-border);border-radius:16px;padding:2.5rem;margin:3rem 0}'
            . '.lp-form-wrap h3{margin-bottom:1.2rem;font-size:1.3rem;font-weight:700}'
            . '.lp-field{margin-bottom:1.2rem}'
            . '.lp-field label{display:block;font-size:.85rem;font-weight:600;margin-bottom:.4rem;letter-spacing:.01em}'
            . '.lp-field input,.lp-field textarea,.lp-field select{width:100%;padding:.7rem .9rem;border:1px solid var(--lp-panel-border);border-radius:8px;font-size:.95rem;background:transparent;color:var(--lp-text);transition:border-color .2s,box-shadow .2s}'
            . '.lp-field input:focus,.lp-field textarea:focus,.lp-field select:focus{outline:none;border-color:var(--lp-accent);box-shadow:0 0 0 3px rgba(76,141,255,.15)}'
            . '.lp-submit{width:100%;padding:.85rem;background:var(--lp-accent);color:#fff;border:none;border-radius:10px;font-size:1.05rem;font-weight:700;cursor:pointer;transition:all .25s ease}'
            . '.lp-submit:hover{background:var(--lp-accent-hover);transform:translateY(-1px);box-shadow:0 4px 14px rgba(0,0,0,.15)}'
            . '.lp-success{text-align:center;font-size:1.15rem;padding:1.5rem;color:var(--lp-accent);font-weight:600}'
            // Animations
            . '.lp-section{opacity:0;transform:translateY(20px);animation:lpFadeUp .6s ease forwards}'
            . '@keyframes lpFadeUp{to{opacity:1;transform:translateY(0)}}'
            . '.lp-section:nth-child(2){animation-delay:.1s}'
            . '.lp-section:nth-child(3){animation-delay:.2s}'
            . '.lp-section:nth-child(4){animation-delay:.3s}'
            . '.lp-section:nth-child(5){animation-delay:.4s}'
            // Responsive
            . '@media(max-width:768px){.lp-hero{padding:3rem 0 2.5rem}.lp-hero h1{font-size:2.2rem}.lp-hero p{font-size:1.05rem}.lp-container{padding:1.5rem 1rem}.lp-form-wrap{padding:1.5rem}}'
            . '@media(max-width:480px){.lp-hero h1{font-size:1.75rem}.lp-hero p{font-size:1rem;margin-bottom:1.5rem}.lp-cta-btn{padding:.75rem 1.5rem;font-size:.95rem;width:100%;text-align:center}}';
    }

    private function sectionStyles(): string
    {
        return '.lp-section{padding:3rem 0;margin:1rem 0}'
            . '.lp-section h2{text-align:center;font-size:1.9rem;margin-bottom:2rem;font-weight:700;letter-spacing:-.01em}'
            // Features
            . '.lp-features-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1.5rem}'
            . '.lp-feature-card{background:var(--lp-panel);border:1px solid var(--lp-panel-border);border-radius:14px;padding:2rem 1.5rem;text-align:center;transition:transform .2s ease,box-shadow .2s ease}'
            . '.lp-feature-card:hover{transform:translateY(-4px);box-shadow:0 8px 25px rgba(0,0,0,.1)}'
            . '.lp-feature-icon{font-size:2.2rem;margin-bottom:.8rem}'
            . '.lp-feature-card h3{font-size:1.1rem;margin-bottom:.5rem;font-weight:700}'
            . '.lp-feature-card p{font-size:.9rem;opacity:.75;line-height:1.6}'
            // Testimonials
            . '.lp-testimonials-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1.5rem}'
            . '.lp-testimonial-card{background:var(--lp-panel);border:1px solid var(--lp-panel-border);border-radius:14px;padding:2rem;position:relative}'
            . '.lp-testimonial-card::before{content:"\\201C";position:absolute;top:.5rem;left:1.2rem;font-size:3rem;opacity:.15;font-family:Georgia,serif;line-height:1}'
            . '.lp-stars{color:#f59e0b;font-size:1rem;margin-bottom:.8rem;letter-spacing:2px}'
            . '.lp-testimonial-card blockquote{font-size:.95rem;line-height:1.65;margin-bottom:1rem;font-style:italic;opacity:.9}'
            . '.lp-testimonial-author{border-top:1px solid var(--lp-panel-border);padding-top:.8rem}'
            . '.lp-testimonial-author strong{display:block;font-size:.9rem}'
            . '.lp-testimonial-author span{font-size:.8rem;opacity:.6}'
            // FAQ
            . '.lp-faq-item{background:var(--lp-panel);border:1px solid var(--lp-panel-border);border-radius:10px;padding:1.2rem 1.5rem;margin-bottom:.8rem;transition:border-color .2s}'
            . '.lp-faq-item:hover{border-color:var(--lp-accent)}'
            . '.lp-faq-item summary{font-weight:600;font-size:1rem;list-style:none;display:flex;justify-content:space-between;align-items:center;cursor:pointer;gap:1rem}'
            . '.lp-faq-item summary::after{content:"+";font-size:1.3rem;font-weight:bold;opacity:.5;transition:transform .2s;flex-shrink:0}'
            . '.lp-faq-item[open] summary::after{content:"\\2212";transform:rotate(180deg)}'
            . '.lp-faq-item p{margin-top:.8rem;opacity:.8;font-size:.95rem;line-height:1.65}'
            // Pricing
            . '.lp-pricing-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1.5rem;align-items:start}'
            . '.lp-pricing-card{background:var(--lp-panel);border:1px solid var(--lp-panel-border);border-radius:16px;padding:2.5rem 2rem;text-align:center;transition:transform .2s ease,box-shadow .2s ease}'
            . '.lp-pricing-card:hover{transform:translateY(-4px);box-shadow:0 12px 30px rgba(0,0,0,.12)}'
            . '.lp-pricing-featured{border:2px solid var(--lp-accent);position:relative}'
            . '.lp-pricing-featured::before{content:"Popular";position:absolute;top:-12px;left:50%;transform:translateX(-50%);background:var(--lp-accent);color:#fff;font-size:.75rem;font-weight:700;padding:.25rem .85rem;border-radius:20px;text-transform:uppercase;letter-spacing:.05em}'
            . '.lp-pricing-card h3{font-size:1.2rem;margin-bottom:.3rem;font-weight:700}'
            . '.lp-price{font-size:2.5rem;font-weight:800;margin:.5rem 0;color:var(--lp-accent);letter-spacing:-.02em}'
            . '.lp-pricing-card p{font-size:.9rem;opacity:.7;margin-bottom:1.2rem}'
            . '.lp-pricing-card ul{list-style:none;text-align:left;margin-bottom:2rem}'
            . '.lp-pricing-card li{padding:.45rem 0;font-size:.9rem;border-bottom:1px solid var(--lp-panel-border)}'
            . '.lp-pricing-card li:last-child{border-bottom:none}'
            . '.lp-pricing-card li::before{content:"\\2713 ";color:var(--lp-accent);font-weight:bold;margin-right:.4rem}'
            . '.lp-pricing-card .lp-cta-btn{display:block;text-align:center;margin-top:auto}'
            // CTA section
            . '.lp-cta-section{text-align:center;background:var(--lp-panel);border:1px solid var(--lp-panel-border);border-radius:16px;padding:3.5rem 2rem}'
            . '.lp-cta-section h2{margin-bottom:.8rem}'
            . '.lp-cta-section p{opacity:.8;max-width:500px;margin:0 auto 2rem;font-size:1.05rem}'
            // Text section
            . '.lp-text-section{max-width:680px;margin-left:auto;margin-right:auto}'
            . '.lp-text-section h2{text-align:left}'
            . '.lp-text-section p{font-size:1.05rem;line-height:1.8;opacity:.85}'
            // Responsive
            . '@media(max-width:768px){.lp-section{padding:2rem 0}.lp-section h2{font-size:1.5rem;margin-bottom:1.2rem}.lp-pricing-grid,.lp-features-grid,.lp-testimonials-grid{grid-template-columns:1fr}.lp-pricing-featured{transform:none}.lp-cta-section{padding:2.5rem 1.5rem}}';
    }

    private function slugify(string $text): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $text), '-'));
        $slug = $slug ?: 'page-' . time();

        // Ensure uniqueness by appending a numeric suffix if needed
        $base = $slug;
        $suffix = 1;
        $check = $this->pdo->prepare('SELECT COUNT(*) FROM landing_pages WHERE slug = :s');
        while ($check->execute([':s' => $slug]) && (int)$check->fetchColumn() > 0) {
            $slug = $base . '-' . (++$suffix);
        }
        return $slug;
    }
}
