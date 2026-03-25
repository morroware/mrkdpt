# Marketing Suite

An all-in-one marketing operations platform with deep AI integration. Zero-dependency PHP 8.1+ backend with vanilla JavaScript SPA frontend, SQLite database, and no build step.

## Quick Start

### Development

```bash
git clone <repo-url> marketing
cd marketing
php -S localhost:8080 -t public
```

Open `http://localhost:8080/install.php` to run the setup wizard.

### Production

**Nginx:** Copy `nginx.example.conf` and adjust paths. Point root to `public/`.

**Apache:** The included `public/.htaccess` handles rewriting and security headers automatically.

**Shared Hosting:** Upload files, point document root to `public/`, visit `/install.php`.

## Requirements

- PHP 8.1+ with `pdo_sqlite`, `curl`, `mbstring`
- Optional: `gd` for image thumbnails
- Write permission on `data/` directory
- No Composer, npm, or build step required

## Features

| Category | Highlights |
|----------|-----------|
| **AI Studio** | 25+ AI tools across content creation, analysis, and strategy — powered by 9 configurable providers |
| **Content Management** | Post creation, calendar view, approval workflows, bulk operations, recurring posts |
| **Social Publishing** | 15-platform publishing with queue, optimal timing, and retry logic |
| **Email Marketing** | List management, campaign composer, 6 built-in templates, open/click tracking |
| **CRM** | Contact management with pipeline stages, scoring, activity timeline, CSV import/export |
| **Analytics** | Dashboard metrics, content performance, charts, CSV exports |
| **Campaigns** | Budget tracking, ROI calculation, campaign comparison |
| **A/B Testing** | Variant creation, impression/conversion tracking |
| **Forms & Landing Pages** | Dynamic form builder, 5 landing page templates, embeddable forms |
| **Automations** | 9 trigger events, 6 action types, conditional execution |
| **Segments** | Dynamic audience segmentation with 10 criteria types |
| **Links & UTM** | Short links, UTM builder, click analytics |
| **Sales Funnels** | Multi-stage funnel builder with conversion tracking |
| **WordPress Plugin** | Content sync, AI generation, dashboard widget for WordPress |

## AI Providers

Supports 9 AI providers out of the box:

| Provider | Config Key | Models |
|----------|-----------|--------|
| OpenAI | `openai` | GPT-4.1, GPT-4.1-mini, GPT-4o, o3-mini |
| Anthropic | `anthropic` | Claude Sonnet 4, Claude Haiku 4.5, Claude Opus 4 |
| Google Gemini | `gemini` | Gemini 2.5 Pro, 2.5 Flash, 2.0 Flash |
| DeepSeek | `deepseek` | deepseek-chat, deepseek-reasoner |
| Groq | `groq` | Llama 3.3 70B, Llama 3.1 8B, Mixtral 8x7B |
| Mistral | `mistral` | Large, Medium, Small, Nemo |
| OpenRouter | `openrouter` | Meta-provider with 100+ models |
| xAI | `xai` | Grok-3, Grok-3-fast, Grok-2 |
| Together AI | `together` | Llama, Mixtral, Qwen variants |

## Configuration

The `.env` file is created by the web installer or manually:

```env
BUSINESS_NAME="My Business"
BUSINESS_INDUSTRY="Technology"
TIMEZONE="America/New_York"

AI_PROVIDER=openai
OPENAI_API_KEY=sk-...

APP_URL=https://yourdomain.com
MAX_UPLOAD_MB=10
CRON_KEY=<random-hex>

SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USER=user@example.com
SMTP_PASS=password
SMTP_FROM=noreply@example.com
```

See [docs/configuration.md](docs/configuration.md) for full configuration reference.

## Cron Setup

```bash
*/5 * * * * curl -s "https://yourdomain.com/cron.php?key=YOUR_CRON_KEY" > /dev/null 2>&1
```

Handles: scheduled post publishing, recurring post creation, RSS feed fetching.

## Documentation

| Document | Description |
|----------|-------------|
| [Architecture](docs/architecture.md) | System architecture, directory structure, tech stack |
| [Configuration](docs/configuration.md) | Environment variables, provider setup, deployment options |
| [API Reference](docs/api-reference.md) | Complete REST API documentation (80+ endpoints) |
| [AI System](docs/ai-system.md) | AI providers, tools, writing assistant, inline toolbar |
| [Content Management](docs/content-management.md) | Posts, calendar, workflows, bulk operations |
| [Social Publishing](docs/social-publishing.md) | 15-platform publishing, queue, optimal timing |
| [Email Marketing](docs/email-marketing.md) | Lists, campaigns, templates, tracking |
| [CRM & Contacts](docs/crm-contacts.md) | Contact management, pipeline, scoring, segmentation |
| [Campaigns & Analytics](docs/campaigns-analytics.md) | Campaign ROI, metrics, reporting, exports |
| [Forms & Landing Pages](docs/forms-landing-pages.md) | Form builder, landing pages, public endpoints |
| [Automations & Workflows](docs/automations-workflows.md) | Triggers, actions, conditions |
| [Frontend Architecture](docs/frontend-architecture.md) | SPA routing, page modules, UI patterns |
| [Database Schema](docs/database-schema.md) | All 35+ tables with columns and relationships |
| [WordPress Plugin](docs/wordpress-plugin.md) | WP connector plugin installation and usage |
| [Security](docs/security.md) | Authentication, CSRF, rate limiting, headers |
| [Known Issues](docs/known-issues.md) | Documented issues, limitations, and recommendations |

## Architecture Overview

```
marketing/
├── public/                  # Web root
│   ├── index.php            # Main router and API dispatcher
│   ├── app.html             # SPA shell (22 pages inline)
│   ├── install.php          # Web installer
│   ├── cron.php             # Cron trigger
│   └── assets/              # CSS, JS modules
├── src/                     # PHP backend
│   ├── Database.php         # SQLite schema (35+ tables)
│   ├── AiService.php        # Multi-provider AI orchestration
│   ├── SocialPublisher.php  # 15-platform publishing
│   ├── EmailService.php     # SMTP client with tracking
│   └── routes/              # 28 API route files
├── wordpress-plugin/        # WordPress connector plugin
├── data/                    # Runtime (gitignored)
│   ├── marketing.sqlite     # Database
│   └── uploads/             # Media files
└── nginx.example.conf       # Production config
```

## License

MIT
