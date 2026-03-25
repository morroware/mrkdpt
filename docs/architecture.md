# Architecture

## Tech Stack

| Layer | Technology | Details |
|-------|-----------|---------|
| Backend | PHP 8.1+ | Procedural + OOP, no framework, `declare(strict_types=1)` |
| Frontend | Vanilla JS | ES modules SPA with hash-based routing |
| Database | SQLite | WAL mode, foreign keys, auto-migrating schema |
| AI | Multi-provider | 9 providers via unified interface |
| Email | Built-in SMTP | Raw socket, RFC 5321, STARTTLS, MIME multipart |
| Social | Multi-platform | 15 platforms via unified `publish()` interface |

## Design Principles

- **Zero dependencies** — No Composer, no npm, no build step
- **Single-file deployment** — No asset compilation required
- **Auto-migrating schema** — Database creates and updates itself
- **Progressive enhancement** — AI features degrade gracefully without API keys

## Directory Structure

```
marketing/
├── public/                         # Web root (document root points here)
│   ├── index.php                   # Main entry: router, middleware, dispatch
│   ├── app.html                    # SPA shell — all 22 page templates inline
│   ├── install.php                 # Web-based setup wizard
│   ├── cron.php                    # External cron trigger
│   ├── .htaccess                   # Apache rewrite + security headers
│   └── assets/
│       ├── styles.css              # Complete CSS (dark/light themes, responsive)
│       └── js/
│           ├── app.js              # Boot sequence, page registration, inline AI
│           ├── core/
│           │   ├── api.js          # Fetch wrapper with CSRF + bearer auth
│           │   ├── router.js       # Hash-based SPA router, command bar
│           │   ├── toast.js        # Notification system (success/error)
│           │   └── utils.js        # DOM helpers, formatters, escaping
│           └── pages/              # 24 page modules
│               ├── dashboard.js    # Metrics, recent items, AI quick actions
│               ├── content.js      # Content studio (calendar, list, create)
│               ├── ai.js           # AI Studio — 25+ tools with categories
│               ├── assistant.js    # AI Writing Assistant — floating panel
│               ├── chat.js         # AI Chat — conversational interface
│               ├── email.js        # Email marketing (lists, campaigns)
│               ├── campaigns.js    # Campaign management with ROI
│               ├── contacts.js     # CRM — pipeline, scoring, import/export
│               ├── analytics.js    # Charts, CSV export
│               ├── social.js       # Social accounts management
│               ├── queue.js        # Publish queue
│               ├── templates.js    # Content templates, brand voice
│               ├── forms.js        # Form builder
│               ├── landing.js      # Landing page builder
│               ├── funnels.js      # Sales funnels
│               ├── automations.js  # Trigger-action workflows
│               ├── segments.js     # Dynamic audience segments
│               ├── abtests.js      # A/B testing
│               ├── links.js        # UTM builder, short links
│               ├── seo.js          # SEO tools (keywords, blog generator)
│               ├── rss.js          # RSS feed reader
│               ├── competitors.js  # Competitor tracking
│               ├── settings.js     # App config, health, backups
│               └── login.js        # Authentication flow
│
├── src/                            # PHP backend classes
│   ├── bootstrap.php               # Helpers: env_value(), json_response(), security_headers()
│   ├── Database.php                # SQLite schema (35+ tables), auto-migration
│   ├── Auth.php                    # Session + bearer token, CSRF, rate limiting
│   ├── Router.php                  # Lightweight router with middleware
│   ├── AiService.php               # Multi-provider AI orchestration (690 lines)
│   ├── AiContentTools.php          # Content creation, repurposing (433 lines)
│   ├── AiAnalysisTools.php         # Content analysis, scoring (263 lines)
│   ├── AiStrategyTools.php         # Research, strategy, campaigns (404 lines)
│   ├── AiChatService.php           # Conversational AI with context (316 lines)
│   ├── Repositories.php            # Data access: Campaign, Post, Competitor, KPI, Email
│   ├── SocialPublisher.php         # 15-platform publishing (1,408 lines)
│   ├── SocialQueue.php             # Queue with best-time optimization
│   ├── EmailService.php            # SMTP client + tracking + merge tags (604 lines)
│   ├── EmailTemplates.php          # 6 built-in email templates
│   ├── Scheduler.php               # Cron task runner
│   ├── Analytics.php               # Dashboard metrics, charts, CSV export
│   ├── Contacts.php                # Mini CRM repository
│   ├── FormBuilder.php             # Dynamic forms + submissions
│   ├── LandingPages.php            # Landing page editor + renderer
│   ├── LinkShortener.php           # Short links + click analytics
│   ├── UtmBuilder.php              # UTM link generator
│   ├── Automations.php             # Trigger-action workflow engine
│   ├── Segments.php                # Dynamic audience segmentation
│   ├── Templates.php               # Content templates + brand voice profiles
│   ├── Funnels.php                 # Sales funnel stages
│   ├── AbTesting.php               # A/B test variants + conversion tracking
│   ├── CampaignMetrics.php         # ROI tracking + campaign comparison
│   ├── MediaLibrary.php            # File uploads + thumbnails
│   ├── RssFetcher.php              # RSS/Atom parser
│   ├── Webhooks.php                # Event dispatch + HMAC signing
│   └── routes/                     # 28 API route files
│       ├── ai.php                  # 60+ AI endpoints
│       ├── auth.php                # Login, logout, setup
│       ├── posts.php               # Content CRUD, calendar, bulk ops
│       ├── campaigns.php           # Campaign CRUD
│       ├── campaign_metrics.php    # ROI tracking
│       ├── contacts.php            # CRM operations
│       ├── email.php               # Lists, subscribers, campaigns
│       ├── email_templates.php     # Email template management
│       ├── social.php              # Social account management
│       ├── social_queue.php        # Publish queue operations
│       ├── templates.php           # Content templates + brand profiles
│       ├── forms.php               # Form builder + submissions
│       ├── landing_pages.php       # Landing page CRUD
│       ├── ab_tests.php            # A/B test management
│       ├── funnels.php             # Sales funnel management
│       ├── automations.php         # Automation rule management
│       ├── segments.php            # Segment management
│       ├── analytics_routes.php    # Analytics + chart data
│       ├── dashboard.php           # Dashboard overview
│       ├── settings.php            # App settings + health + backup
│       ├── competitors.php         # Competitor tracking
│       ├── kpis.php                # KPI logging
│       ├── media.php               # Media upload + management
│       ├── links.php               # Short link management
│       ├── utm.php                 # UTM link management
│       ├── rss.php                 # RSS feed management
│       ├── webhooks_routes.php     # Webhook management
│       ├── cron.php                # Cron log viewing
│       └── wordpress_plugin.php    # WordPress plugin API
│
├── wordpress-plugin/               # WordPress connector plugin
│   └── marketing-suite-connector/
│       ├── marketing-suite-connector.php  # Plugin bootstrap
│       ├── readme.txt                     # WP plugin readme
│       ├── includes/                      # Plugin classes
│       └── assets/                        # Admin CSS/JS
│
├── data/                           # Runtime data (gitignored)
│   ├── marketing.sqlite            # SQLite database (auto-created)
│   └── uploads/                    # Uploaded media files
│
├── nginx.example.conf              # Production Nginx config
├── CLAUDE.md                       # AI assistant project reference
└── README.md                       # This documentation
```

## Request Flow

```
Browser Request
     │
     ▼
public/index.php
     │
     ├─── Static files (CSS, JS, images)
     │         → Served directly
     │
     ├─── Public routes (no auth)
     │    ├── /p/{slug}         → Landing page rendering
     │    ├── /f/{slug}         → Form embedding
     │    ├── /s/{code}         → Short link redirect
     │    ├── /api/health       → Health check
     │    ├── /api/login        → Authentication
     │    └── /api/track/*      → Email tracking
     │
     ├─── API routes (auth + CSRF required)
     │    ├── Auth middleware    → Session or bearer token
     │    ├── CSRF middleware    → X-CSRF-Token header
     │    └── Route dispatch    → src/routes/*.php
     │
     └─── SPA shell
          └── app.html          → Single page application
```

## Key Patterns

### API Response Format

All API endpoints return JSON:

```json
// Single item
{ "item": { "id": 1, "name": "..." } }

// Collection
{ "items": [{ "id": 1 }, { "id": 2 }] }

// Success action
{ "ok": true }

// Error
{ "error": "message" }
```

### Page Module Pattern

Each frontend page exports two functions:

```javascript
// pages/example.js
export function init() {
    // Bind event handlers (called once on boot)
}

export function refresh() {
    // Load/reload data (called on each page visit)
}
```

### AI Button Pattern

```javascript
btn.classList.add('loading');
btn.disabled = true;
try {
    const res = await api('/api/ai/endpoint', { method: 'POST', body });
    // Use result
} catch (err) {
    error(err.message);
} finally {
    btn.classList.remove('loading');
    btn.disabled = false;
}
```

### Repository Pattern

Backend data access follows a consistent CRUD pattern:

```php
final class ExampleRepository {
    public function __construct(private PDO $pdo) {}
    public function all(): array { /* SELECT * */ }
    public function find(int $id): ?array { /* SELECT ... WHERE id = ? */ }
    public function create(array $data): array { /* INSERT ... RETURNING */ }
    public function update(int $id, array $data): ?array { /* UPDATE ... */ }
    public function delete(int $id): bool { /* DELETE ... */ }
}
```

## Service Initialization

All services are instantiated in `public/index.php` and passed to route registration functions:

```php
$db = Database::connect();
$auth = new Auth($db);
$router = new Router();

// Services created with PDO dependency
$aiService = new AiService();
$emailService = new EmailService($db);
$socialPublisher = new SocialPublisher($db);
// ... 20+ more services

// Routes registered with services
registerAiRoutes($router, $auth, $aiService, ...);
registerPostRoutes($router, $auth, $postRepo, ...);
// ... 28 route groups
```
