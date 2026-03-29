<?php

declare(strict_types=1);

final class EmailTemplateRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->seedBuiltins();
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM email_templates ORDER BY is_builtin DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM email_templates WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create(array $data): array
    {
        $vars = $data['variables'] ?? '[]';
        if (is_array($vars)) $vars = json_encode($vars);

        $this->pdo->prepare('INSERT INTO email_templates(name, category, subject_template, html_template, text_template, thumbnail_color, variables, is_builtin, created_at) VALUES(:n,:c,:st,:ht,:tt,:tc,:v,:ib,:ca)')->execute([
            ':n' => $data['name'],
            ':c' => $data['category'] ?? 'general',
            ':st' => $data['subject_template'] ?? '',
            ':ht' => $data['html_template'],
            ':tt' => $data['text_template'] ?? '',
            ':tc' => $data['thumbnail_color'] ?? '#4c8dff',
            ':v' => $vars,
            ':ib' => (int)($data['is_builtin'] ?? 0),
            ':ca' => gmdate(DATE_ATOM),
        ]);
        return $this->find((int)$this->pdo->lastInsertId());
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = [':id' => $id];
        foreach (['name', 'category', 'subject_template', 'html_template', 'text_template', 'thumbnail_color', 'variables'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $val = $data[$col];
                if ($col === 'variables' && is_array($val)) $val = json_encode($val);
                $params[":{$col}"] = $val;
            }
        }
        if ($fields) {
            $this->pdo->prepare('UPDATE email_templates SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
        }
        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM email_templates WHERE id = :id AND is_builtin = 0');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function render(int $id, array $vars = []): array
    {
        $tpl = $this->find($id);
        if (!$tpl) return ['error' => 'Template not found'];

        $html = $tpl['html_template'];
        $text = $tpl['text_template'];
        $subject = $tpl['subject_template'];

        foreach ($vars as $key => $value) {
            $tag = '{{' . $key . '}}';
            $html = str_replace($tag, htmlspecialchars($value, ENT_QUOTES, 'UTF-8'), $html);
            $text = str_replace($tag, $value, $text);
            $subject = str_replace($tag, $value, $subject);
        }

        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    private function seedBuiltins(): void
    {
        // Use INSERT OR IGNORE to handle race conditions with concurrent requests
        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM email_templates WHERE is_builtin = 1")->fetchColumn();
        if ($count > 0) return;

        // Wrap in a transaction for atomicity
        $this->pdo->beginTransaction();
        try {
            // Re-check inside transaction
            $count = (int)$this->pdo->query("SELECT COUNT(*) FROM email_templates WHERE is_builtin = 1")->fetchColumn();
            if ($count > 0) { $this->pdo->rollBack(); return; }

        $templates = [
            [
                'name' => 'Welcome Email',
                'category' => 'onboarding',
                'subject_template' => 'Welcome to {{business_name}}!',
                'thumbnail_color' => '#2da44e',
                'variables' => '["name","business_name","cta_url"]',
                'html_template' => $this->welcomeHtml(),
                'text_template' => "Hi {{name}},\n\nWelcome to {{business_name}}! We're thrilled to have you.\n\nGet started: {{cta_url}}\n\nBest regards,\nThe {{business_name}} Team",
            ],
            [
                'name' => 'Newsletter',
                'category' => 'newsletter',
                'subject_template' => '{{headline}} - {{business_name}} Newsletter',
                'thumbnail_color' => '#4c8dff',
                'variables' => '["name","business_name","headline","intro","article_1_title","article_1_summary","article_1_url","article_2_title","article_2_summary","article_2_url","cta_text","cta_url"]',
                'html_template' => $this->newsletterHtml(),
                'text_template' => "{{headline}}\n\nHi {{name}},\n\n{{intro}}\n\n---\n{{article_1_title}}\n{{article_1_summary}}\nRead more: {{article_1_url}}\n\n---\n{{article_2_title}}\n{{article_2_summary}}\nRead more: {{article_2_url}}\n\n{{cta_text}}: {{cta_url}}",
            ],
            [
                'name' => 'Promotional Offer',
                'category' => 'promotional',
                'subject_template' => '{{offer_title}} - Limited Time!',
                'thumbnail_color' => '#da3633',
                'variables' => '["name","business_name","offer_title","offer_description","discount_code","cta_url","expiry_date"]',
                'html_template' => $this->promoHtml(),
                'text_template' => "Hi {{name}},\n\n{{offer_title}}\n\n{{offer_description}}\n\nUse code: {{discount_code}}\nExpires: {{expiry_date}}\n\nShop now: {{cta_url}}\n\n{{business_name}}",
            ],
            [
                'name' => 'Event Invitation',
                'category' => 'event',
                'subject_template' => "You're Invited: {{event_name}}",
                'thumbnail_color' => '#d4a72c',
                'variables' => '["name","business_name","event_name","event_date","event_time","event_location","event_description","rsvp_url"]',
                'html_template' => $this->eventHtml(),
                'text_template' => "Hi {{name}},\n\nYou're invited to {{event_name}}!\n\nDate: {{event_date}}\nTime: {{event_time}}\nLocation: {{event_location}}\n\n{{event_description}}\n\nRSVP: {{rsvp_url}}\n\n{{business_name}}",
            ],
            [
                'name' => 'Follow-Up',
                'category' => 'sales',
                'subject_template' => 'Following up - {{business_name}}',
                'thumbnail_color' => '#6ba1ff',
                'variables' => '["name","business_name","context","cta_text","cta_url","sender_name","sender_title"]',
                'html_template' => $this->followUpHtml(),
                'text_template' => "Hi {{name}},\n\n{{context}}\n\n{{cta_text}}: {{cta_url}}\n\nBest,\n{{sender_name}}\n{{sender_title}}, {{business_name}}",
            ],
            [
                'name' => 'Product Announcement',
                'category' => 'product',
                'subject_template' => 'Introducing {{product_name}}',
                'thumbnail_color' => '#8b5cf6',
                'variables' => '["name","business_name","product_name","product_description","feature_1","feature_2","feature_3","cta_url"]',
                'html_template' => $this->productHtml(),
                'text_template' => "Hi {{name}},\n\nWe're excited to announce {{product_name}}!\n\n{{product_description}}\n\nKey Features:\n- {{feature_1}}\n- {{feature_2}}\n- {{feature_3}}\n\nLearn more: {{cta_url}}\n\n{{business_name}}",
            ],
        ];

        foreach ($templates as $tpl) {
            $tpl['is_builtin'] = 1;
            $this->create($tpl);
        }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            error_log('EmailTemplates::seedBuiltins() error: ' . $e->getMessage());
        }
    }

    private function baseHtml(string $accentColor, string $innerContent): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f4f4f7;color:#333}.wrapper{max-width:600px;margin:0 auto;background:#ffffff}.header{background:' . $accentColor . ';padding:32px 40px;text-align:center}.header h1{color:#ffffff;margin:0;font-size:24px;font-weight:600}.content{padding:40px}.content h2{margin:0 0 16px;font-size:20px;color:#1a1a1a}.content p{margin:0 0 16px;line-height:1.6;color:#555}.btn{display:inline-block;background:' . $accentColor . ';color:#ffffff!important;text-decoration:none;padding:14px 32px;border-radius:6px;font-weight:600;font-size:16px}.footer{padding:24px 40px;text-align:center;font-size:12px;color:#999;border-top:1px solid #eee}hr{border:none;border-top:1px solid #eee;margin:24px 0}.feature{margin:8px 0;padding-left:12px;border-left:3px solid ' . $accentColor . '}</style></head><body><div class="wrapper">' . $innerContent . '</div></body></html>';
    }

    private function welcomeHtml(): string
    {
        return $this->baseHtml('#2da44e', '<div class="header"><h1>Welcome to {{business_name}}</h1></div><div class="content"><h2>Hi {{name}},</h2><p>We\'re thrilled to have you join us! You\'re now part of the {{business_name}} community.</p><p>We\'re here to help you succeed. Here\'s what you can do next:</p><p style="text-align:center;margin:32px 0"><a href="{{cta_url}}" class="btn">Get Started</a></p><p>If you have any questions, just reply to this email. We\'re always happy to help.</p><p>Best regards,<br>The {{business_name}} Team</p></div><div class="footer"><p>{{business_name}} &middot; {{unsubscribe_url}}</p></div>');
    }

    private function newsletterHtml(): string
    {
        return $this->baseHtml('#4c8dff', '<div class="header"><h1>{{headline}}</h1></div><div class="content"><p>Hi {{name}},</p><p>{{intro}}</p><hr><h2>{{article_1_title}}</h2><p>{{article_1_summary}}</p><p><a href="{{article_1_url}}" class="btn" style="font-size:14px;padding:10px 24px">Read More</a></p><hr><h2>{{article_2_title}}</h2><p>{{article_2_summary}}</p><p><a href="{{article_2_url}}" class="btn" style="font-size:14px;padding:10px 24px">Read More</a></p><hr><p style="text-align:center;margin:32px 0"><a href="{{cta_url}}" class="btn">{{cta_text}}</a></p></div><div class="footer"><p>{{business_name}} &middot; {{unsubscribe_url}}</p></div>');
    }

    private function promoHtml(): string
    {
        return $this->baseHtml('#da3633', '<div class="header"><h1>{{offer_title}}</h1></div><div class="content"><h2>Hi {{name}},</h2><p>{{offer_description}}</p><div style="background:#fef2f2;border:2px dashed #da3633;padding:20px;text-align:center;border-radius:8px;margin:24px 0"><p style="margin:0;font-size:24px;font-weight:700;color:#da3633">{{discount_code}}</p><p style="margin:8px 0 0;color:#666">Expires: {{expiry_date}}</p></div><p style="text-align:center;margin:32px 0"><a href="{{cta_url}}" class="btn">Shop Now</a></p></div><div class="footer"><p>{{business_name}} &middot; {{unsubscribe_url}}</p></div>');
    }

    private function eventHtml(): string
    {
        return $this->baseHtml('#d4a72c', '<div class="header"><h1>{{event_name}}</h1></div><div class="content"><h2>You\'re Invited, {{name}}!</h2><p>{{event_description}}</p><div style="background:#fffbeb;padding:20px;border-radius:8px;margin:24px 0"><p style="margin:0 0 8px"><strong>Date:</strong> {{event_date}}</p><p style="margin:0 0 8px"><strong>Time:</strong> {{event_time}}</p><p style="margin:0"><strong>Location:</strong> {{event_location}}</p></div><p style="text-align:center;margin:32px 0"><a href="{{rsvp_url}}" class="btn">RSVP Now</a></p></div><div class="footer"><p>{{business_name}} &middot; {{unsubscribe_url}}</p></div>');
    }

    private function followUpHtml(): string
    {
        return $this->baseHtml('#6ba1ff', '<div class="content" style="padding-top:48px"><h2>Hi {{name}},</h2><p>{{context}}</p><p style="text-align:center;margin:32px 0"><a href="{{cta_url}}" class="btn">{{cta_text}}</a></p><p>Best,<br><strong>{{sender_name}}</strong><br><span style="color:#999">{{sender_title}}, {{business_name}}</span></p></div><div class="footer"><p>{{business_name}} &middot; {{unsubscribe_url}}</p></div>');
    }

    private function productHtml(): string
    {
        return $this->baseHtml('#8b5cf6', '<div class="header"><h1>Introducing {{product_name}}</h1></div><div class="content"><h2>Hi {{name}},</h2><p>{{product_description}}</p><h2 style="margin-top:24px">Key Features</h2><div class="feature"><p><strong>{{feature_1}}</strong></p></div><div class="feature"><p><strong>{{feature_2}}</strong></p></div><div class="feature"><p><strong>{{feature_3}}</strong></p></div><p style="text-align:center;margin:32px 0"><a href="{{cta_url}}" class="btn">Learn More</a></p></div><div class="footer"><p>{{business_name}} &middot; {{unsubscribe_url}}</p></div>');
    }
}
