# Marketing Suite

All-in-one marketing operations platform with deep AI integration. Create content, manage campaigns, publish to 15 social platforms, send email campaigns, track analytics, and run your entire marketing operation from a single self-hosted app.

**Zero dependencies.** PHP 8.1+, SQLite, vanilla JavaScript. No Composer, no npm, no build step.

## Getting Started

```bash
git clone <repo-url> marketing
cd marketing
php -S localhost:8080 -t public
```

Open `http://localhost:8080/install.php` to run the setup wizard, then follow the **[Quick Start Guide](docs/quick-start.md)** to configure AI providers and generate your first content.

## Requirements

- PHP 8.1+ with `pdo_sqlite`, `curl`, `mbstring`
- Optional: `gd` for image thumbnails
- Write permission on `data/` directory

## Deployment

| Environment | Setup |
|-------------|-------|
| **Local dev** | `php -S localhost:8080 -t public` |
| **Shared hosting** | Upload files, point document root to `public/`, visit `/install.php` |
| **VPS (Nginx)** | Use `nginx.example.conf`, point root to `public/`, install `php-fpm` |
| **VPS (Apache)** | `.htaccess` handles rewriting automatically, enable `mod_rewrite` |
| **Docker** | Any PHP 8.1+ image with `pdo_sqlite` and `curl` |
| **PaaS** | Set `public/` as web root, ensure `data/` is writable |

See **[Configuration](docs/configuration.md)** for full environment variables, SMTP setup, and deployment details.

## Features

| Category | Highlights |
|----------|-----------|
| **AI Studio** | 25+ AI tools across content creation, analysis, and strategy with 9 configurable providers |
| **AI Brain** | Self-learning system: activity logging, auto-extracted insights, performance feedback, situational awareness |
| **AI Agents** | Multi-agent task system: describe a goal, AI plans and executes with human-in-the-loop approval |
| **AI Chat** | Conversational AI assistant grounded in your marketing data |
| **Content Studio** | Post creation, calendar view, approval workflows, bulk operations, recurring posts |
| **Social Publishing** | 15-platform publishing with queue, optimal timing, and retry logic |
| **Email Marketing** | List management, campaign composer, 6 built-in templates, open/click tracking |
| **CRM** | Contact management with pipeline stages, scoring, activity timeline, import/export |
| **Campaigns & Analytics** | Budget tracking, ROI calculation, dashboard metrics, CSV exports |
| **A/B Testing** | Variant creation with impression/conversion tracking |
| **Forms & Landing Pages** | Dynamic form builder, 5 landing page templates, embeddable forms |
| **Automations** | 9 trigger events, 6 action types, conditional workflow execution |
| **Sales Funnels** | Multi-stage funnel builder with conversion tracking |
| **Links & UTM** | Short links, UTM builder, click analytics |
| **Segments** | Dynamic audience segmentation with 10 criteria types |
| **WordPress Plugin** | Content sync, AI generation, and dashboard widget for WordPress |

## AI Providers

Supports 9 AI providers out of the box. Configure one or many simultaneously:

| Provider | Models |
|----------|--------|
| **OpenAI** | GPT-4.1, GPT-4.1-mini, GPT-4o, o3-mini |
| **Anthropic** | Claude Opus 4, Claude Sonnet 4, Claude Haiku 4.5 |
| **Google Gemini** | Gemini 2.5 Pro, 2.5 Flash, 2.0 Flash |
| **DeepSeek** | deepseek-chat, deepseek-reasoner |
| **Groq** | Llama 3.3 70B, Llama 3.1 8B, Mixtral 8x7B |
| **Mistral** | Large, Medium, Small, Nemo |
| **OpenRouter** | Meta-provider with 100+ models |
| **xAI** | Grok-3, Grok-3-fast, Grok-2 |
| **Together AI** | Llama, Mixtral, Qwen variants |

Switch providers at any time from **Settings** in the app — no reinstall needed.

## Cron Setup

For scheduled publishing, recurring content, and RSS feed fetching:

```bash
*/5 * * * * curl -s "https://yourdomain.com/cron.php?key=YOUR_CRON_KEY" > /dev/null 2>&1
```

## Documentation

### Setup

| Document | Description |
|----------|-------------|
| **[Quick Start](docs/quick-start.md)** | End-to-end guide: install, configure AI, onboard your business, generate first content |
| **[Configuration](docs/configuration.md)** | Environment variables, AI providers, SMTP, deployment options |
| **[System Reference](docs/system-reference.md)** | Implementation-level architecture and module map reflecting the current codebase |
| **[Code Audit (2026-03-29)](docs/code-audit-2026-03-29.md)** | Deep-dive functionality audit results, checks run, and release checklist |

### Features

| Document | Description |
|----------|-------------|
| **[AI System](docs/ai-system.md)** | AI tools, providers, Brain, Agents, Search, Pipelines, Writing Assistant, Chat, Brand Voice |
| **[Content Management](docs/content-management.md)** | Content Studio, calendar, approval workflows, bulk operations |
| **[Social Publishing](docs/social-publishing.md)** | 15-platform publishing, queue management, optimal timing |
| **[Email Marketing](docs/email-marketing.md)** | Lists, campaigns, templates, open/click/unsubscribe tracking |
| **[Campaigns & Analytics](docs/campaigns-analytics.md)** | Campaign ROI, metrics, dashboard, reporting, CSV exports |
| **[CRM & Contacts](docs/crm-contacts.md)** | Contact pipeline, scoring, segmentation, import/export |
| **[Forms & Landing Pages](docs/forms-landing-pages.md)** | Form builder, landing pages, short links, UTM tracking |
| **[Automations & Workflows](docs/automations-workflows.md)** | Triggers, actions, funnels, A/B tests, webhooks, RSS |

### Integrations

| Document | Description |
|----------|-------------|
| **[WordPress Plugin](docs/wordpress-plugin.md)** | WordPress connector: content sync, AI tools, dashboard widget |
| **[API Reference](docs/api-reference.md)** | Complete REST API documentation (80+ endpoints) |

## License

MIT
