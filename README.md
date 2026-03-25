# Marketing Suite v3.0-beta

A full-stack marketing operations platform built with vanilla JS, PHP 8+, and SQLite. Zero external dependencies — no Composer, no npm, no frameworks. Deploy anywhere PHP runs.

## Tech Stack

| Layer | Technology |
|-------|-----------|
| **Backend** | PHP 8+ (pure procedural, no framework) |
| **Frontend** | Vanilla HTML/CSS/JavaScript (SPA with hash-based router) |
| **Database** | SQLite via PDO (WAL mode, auto-migrating schema) |
| **AI** | OpenAI, Anthropic (Claude), Google Gemini |
| **Email** | Built-in SMTP client (RFC 5321, STARTTLS, MIME multipart) |
| **Social** | Twitter/X API v2, Bluesky AT Protocol, Mastodon ActivityPub, Facebook/Instagram Graph API |

## Quick Start

### Option A: PHP built-in server (development)

```bash
git clone <repo-url> marketing
cd marketing
php -S localhost:8080 -t public
```

Open `http://localhost:8080/install.php` to configure, or `http://localhost:8080` if already set up.

### Option B: Web installer (shared hosting)

1. Upload files to your web root (point document root to `public/`).
2. Visit `https://yourdomain.com/install.php`.
3. Fill in business name, AI keys, SMTP settings.
4. The installer writes `.env`, initializes the database, and creates your admin account.

### Option C: Production (Nginx/Apache)

- **Nginx:** Copy `nginx.example.conf` to `/etc/nginx/sites-available/` and adjust paths.
- **Apache:** The included `public/.htaccess` handles URL rewriting and security headers automatically.

## Requirements

- PHP 8.1+ with extensions: `pdo_sqlite`, `curl`, `mbstring`
- Optional: `gd` (for image thumbnail generation)
- No Composer, no npm, no build step
- Write permission on `data/` directory

## Configuration (.env)

The `.env` file is created by the web installer or manually:

```env
# Business
BUSINESS_NAME="My Business"
BUSINESS_INDUSTRY="Local services"
TIMEZONE="America/New_York"

# AI Provider (openai | anthropic | gemini)
AI_PROVIDER=openai
OPENAI_API_KEY=sk-...
OPENAI_BASE_URL=https://api.openai.com/v1
AI_MODEL=gpt-4.1-mini
ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=claude-sonnet-4-20250514
GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.5-flash

# App
APP_URL=https://yourdomain.com
MAX_UPLOAD_MB=10
CRON_KEY=<random-hex>

# SMTP (for email marketing)
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USER=user@example.com
SMTP_PASS=password
SMTP_FROM=noreply@example.com
SMTP_FROM_NAME="My Business"
```

If AI keys are missing, the app returns deterministic fallback output so all workflows remain functional without paid API access.

## Features

### Content Management
- **Content Studio** with calendar view, list view, and post creator
- **Publishing workflow:** draft → pending review → approved → scheduled → published
- **Content approval system** with approve/reject/request-review actions and review notes
- **Content notes/comments** for team collaboration on posts
- **Bulk operations:** publish, schedule, or delete multiple posts at once
- **Recurring posts:** daily, weekly, biweekly, monthly recurrence
- **Evergreen content** flagging
- **AI-powered content generation** directly from the post form

### AI Studio (18 tools)
| Tool | Endpoint | Description |
|------|----------|-------------|
| Market Research | `POST /api/ai/research` | ICP, pain points, objections, 30-day plan |
| Content Ideas | `POST /api/ai/ideas` | 8 platform-specific ideas with hooks and CTAs |
| Content Writer | `POST /api/ai/content` | Social posts, captions, ad copy, emails |
| Blog Generator | `POST /api/ai/blog-post` | 1200-1800 word SEO blog with meta tags and FAQ |
| SEO Keywords | `POST /api/ai/seo-keywords` | 20 keywords with intent, difficulty, content type |
| Hashtag Research | `POST /api/ai/hashtags` | 30 hashtags in 3 volume tiers |
| Content Repurpose | `POST /api/ai/repurpose` | Convert content across formats (tweet, LinkedIn, email, etc.) |
| Ad Variations | `POST /api/ai/ad-variations` | 5+ ad angles (pain, benefit, proof, urgency, story) |
| Email Subject Lines | `POST /api/ai/subject-lines` | 10 subjects with predicted open rate and psychological trigger |
| Audience Persona | `POST /api/ai/persona` | Detailed buyer persona with messaging dos/don'ts |
| Content Scorer | `POST /api/ai/score` | 1-100 score across 5 categories with improvement tips |
| Posting Calendar | `POST /api/ai/calendar` | 14-day schedule with times, channels, KPIs |
| Video Script | `POST /api/ai/video-script` | Scene-by-scene script with hooks, overlays, captions |
| Caption Batch | `POST /api/ai/caption-batch` | Multi-platform captions in one request |
| SEO Audit | `POST /api/ai/seo-audit` | 10-point page audit with scores and quick wins |
| Social Strategy | `POST /api/ai/social-strategy` | Full strategy with content pillars, schedule, KPIs |
| Competitor Analysis | `POST /api/ai/competitor-analysis` | Deep competitive analysis with counter-strategies |
| Weekly Report | `POST /api/ai/report` | AI-generated performance summary from your data |

### Campaign Management
- Campaign CRUD with budget, date range, channel, objective
- **ROI tracking:** log daily spend, revenue, impressions, clicks, conversions
- **Performance metrics:** ROI %, CTR, conversion rate, CPA, ROAS — all auto-calculated
- **Campaign comparison:** compare multiple campaigns side-by-side
- Budget utilization progress bars

### Social Media Publishing
- **Multi-platform publishing:** Twitter/X, Bluesky, Mastodon, Facebook Pages, Instagram
- **Publish Queue:** queue posts with priority and optimal time scheduling
- **Best posting times:** analytics based on historical publish success data
- Social account management with token handling
- Automatic retry with exponential backoff on failures
- Publish log with success/error tracking

### Email Marketing
- **List management** with subscriber counts
- **Campaign composer** with HTML and plain text editors
- **6 built-in responsive email templates:** Welcome, Newsletter, Promotional, Event, Follow-Up, Product Announcement
- **Template system:** preview, use-in-campaign one-click loading, custom template creation
- **Merge tags:** `{{name}}`, `{{email}}`, `{{unsubscribe_url}}`, `{{tracking_pixel}}`, `{{date}}`
- **Tracking:** open pixel, click tracking with redirect URLs
- **HMAC-signed unsubscribe URLs** for security
- **Campaign stats:** open rate, click rate, unique opens/clicks
- CSV subscriber import
- Test email sending

### Contacts / Mini CRM
- Contact database with lead → MQL → SQL → opportunity → customer stages
- Contact scoring (manual + automation-driven)
- Activity timeline per contact
- **CSV import/export** for bulk operations
- **Bulk actions:** delete, update stage, add tags, add score points
- Source tracking (form, manual, CSV import, email, etc.)
- Custom fields (JSON)
- Auto-creation from form submissions

### Audience Segments
- **Dynamic segmentation** with 10 criteria types:
  - Stage (multi-select), score range, tags, source, company
  - Created date range, active since, inactive since
- Auto-computed contact counts
- View matching contacts in modal
- Refresh/recompute on demand

### Forms & Landing Pages
- **Dynamic form builder** with configurable field types
- Form submissions auto-create contacts and fire automations
- Embeddable forms via iframe (`/f/slug`)
- **Landing page editor** with 5 templates (Dark, Startup Purple, Minimal Light, Bold Red, Nature Green)
- Hero section with CTA, body HTML, custom CSS
- Built-in form integration on landing pages
- View and conversion tracking
- Public rendering at `/p/slug`

### Links & UTM
- **UTM link builder** with auto-generated short links
- **Link shortener** with custom codes
- Click tracking with date, IP hash, user agent, referer
- Short link redirects at `/s/code`

### A/B Testing
- Create tests with multiple variants
- Track impressions and conversions per variant
- Auto-calculated conversion rates
- Winner selection

### Sales Funnels
- Multi-stage funnel builder with color-coded stages
- Target vs. actual count tracking
- Conversion rate calculation between stages
- Campaign association

### Automations
- **9 trigger events:** form submitted, contact created, stage changed, post published, post scheduled, subscriber added, email sent, landing page conversion, link clicked
- **6 action types:** tag contact, update stage, add score, add to email list, send webhook, log activity
- Conditional execution based on context
- Run count and last-run tracking

### Analytics & Reporting
- Dashboard with 30-day metrics overview
- Posts by platform, content type, and status
- Weekly posting trends
- AI usage tracking
- Email engagement metrics
- Social publishing stats
- **CSV export:** posts, campaigns, KPIs, subscribers, publish log, contacts

### Additional Features
- **RSS feed reader** with auto-fetch on cron and item curation
- **Webhooks** with HMAC-SHA256 signing for 6 event types
- **Cron scheduler** for scheduled posts, recurring posts, RSS fetching
- **Media library** with file upload, thumbnail generation, alt text, and tagging
- **Content templates** with variable substitution
- **Brand voice profiles** for consistent AI-generated content
- **Dark/light theme** toggle
- **Responsive design** — works on desktop and mobile

## Architecture

```
marketing/
├── public/                  # Web root (point your server here)
│   ├── index.php            # Entry point — routing, middleware, dispatch
│   ├── app.html             # SPA shell — all page templates
│   ├── install.php          # Web installer
│   ├── cron.php             # External cron trigger endpoint
│   ├── .htaccess            # Apache rewrite rules + security
│   └── assets/
│       ├── styles.css       # Full CSS (dark/light themes, responsive)
│       └── js/
│           ├── app.js       # Boot sequence, page registration
│           ├── core/
│           │   ├── router.js   # Hash-based SPA router
│           │   ├── api.js      # Fetch wrapper with CSRF + auth
│           │   ├── toast.js    # Notification system
│           │   └── utils.js    # DOM helpers, formatters, escaping
│           └── pages/         # 21 page modules (one per feature)
├── src/
│   ├── bootstrap.php        # Helper functions (env, JSON response, security headers)
│   ├── Database.php         # SQLite schema (35+ tables, auto-migration)
│   ├── Auth.php             # Session + bearer token auth, CSRF, rate limiting
│   ├── Router.php           # Lightweight router with middleware support
│   ├── Repositories.php     # Campaign, Post, Competitor, KPI, Email repos
│   ├── Templates.php        # Template + Brand Profile repos
│   ├── Analytics.php        # Dashboard metrics, charts, CSV export
│   ├── AiService.php        # Multi-provider AI (18 methods)
│   ├── EmailService.php     # SMTP client + tracking + merge tags
│   ├── SocialPublisher.php  # Multi-platform publishing (5 platforms)
│   ├── MediaLibrary.php     # File uploads + thumbnails
│   ├── Contacts.php         # Contact CRM repo
│   ├── FormBuilder.php      # Form builder + submissions
│   ├── LandingPages.php     # Landing page editor + renderer
│   ├── UtmBuilder.php       # UTM link generator
│   ├── LinkShortener.php    # Short links + click analytics
│   ├── RssFetcher.php       # RSS/Atom parser
│   ├── Webhooks.php         # Webhook dispatch + HMAC signing
│   ├── Scheduler.php        # Cron task runner
│   ├── Automations.php      # Trigger-action workflow engine
│   ├── AbTesting.php        # A/B test variants + conversion tracking
│   ├── Funnels.php          # Sales funnel stages
│   ├── Segments.php         # Dynamic audience segments
│   ├── SocialQueue.php      # Publish queue + best times
│   ├── EmailTemplates.php   # Email template library (6 built-in)
│   ├── CampaignMetrics.php  # ROI tracking + campaign comparison
│   └── routes/              # 24 route modules
├── data/                    # Runtime data (gitignored)
│   ├── marketing.sqlite     # Database (auto-created)
│   └── uploads/             # Uploaded media files
└── nginx.example.conf       # Production Nginx config
```

## API Reference

All API endpoints are prefixed with `/api/`. Authentication is required for all endpoints except `/api/health`, `/api/login`, `/api/setup-status`, and form submissions.

### Authentication
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/login` | Login (returns session + CSRF token) |
| POST | `/api/logout` | Logout |
| GET | `/api/setup-status` | Check if setup is complete |

### Content
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/posts` | List posts (filter: `status`, `platform`, `campaign_id`) |
| POST | `/api/posts` | Create post |
| GET | `/api/posts/{id}` | Get single post |
| PATCH | `/api/posts/{id}` | Update post |
| DELETE | `/api/posts/{id}` | Delete post |
| GET | `/api/posts/calendar` | Calendar view (`year`, `month` params) |
| POST | `/api/posts/{id}/approve` | Approve content |
| POST | `/api/posts/{id}/reject` | Reject content |
| POST | `/api/posts/{id}/request-review` | Request review |
| GET | `/api/posts/{id}/notes` | Get content notes |
| POST | `/api/posts/{id}/notes` | Add content note |
| POST | `/api/posts/bulk` | Bulk action (publish, schedule, delete) |

### Campaigns
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/campaigns` | List campaigns |
| POST | `/api/campaigns` | Create campaign |
| GET | `/api/campaigns/{id}` | Get campaign |
| PUT | `/api/campaigns/{id}` | Update campaign |
| DELETE | `/api/campaigns/{id}` | Delete campaign |
| GET | `/api/campaigns/{id}/metrics` | Get daily metrics |
| POST | `/api/campaigns/{id}/metrics` | Add metric entry (spend, revenue, etc.) |
| GET | `/api/campaigns/{id}/summary` | Get ROI summary (ROI%, CTR, CPA, ROAS) |
| POST | `/api/campaigns/compare` | Compare campaigns (`campaign_ids` array) |

### Contacts
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/contacts` | List (filter: `stage`, `search`) |
| POST | `/api/contacts` | Create contact |
| GET | `/api/contacts/{id}` | Get contact + activities |
| PATCH | `/api/contacts/{id}` | Update contact |
| DELETE | `/api/contacts/{id}` | Delete contact |
| POST | `/api/contacts/{id}/activity` | Log activity |
| GET | `/api/contacts/metrics` | Stage breakdown counts |
| GET | `/api/contacts/export` | CSV export |
| POST | `/api/contacts/import` | CSV import |
| POST | `/api/contacts/bulk` | Bulk ops (delete, update_stage, add_tag, add_score) |

### Segments
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/segments` | List segments |
| POST | `/api/segments` | Create segment |
| GET | `/api/segments/{id}` | Get segment |
| PUT | `/api/segments/{id}` | Update segment |
| DELETE | `/api/segments/{id}` | Delete segment |
| GET | `/api/segments/{id}/contacts` | Get matching contacts |
| POST | `/api/segments/{id}/recompute` | Refresh contact count |
| GET | `/api/segments/criteria-fields` | Available criteria types |

### Social
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/social-accounts` | List connected accounts |
| POST | `/api/social-accounts` | Connect account |
| PUT | `/api/social-accounts/{id}` | Update account |
| DELETE | `/api/social-accounts/{id}` | Remove account |
| GET | `/api/social-queue` | List queued posts |
| POST | `/api/social-queue` | Add to queue |
| PATCH | `/api/social-queue/{id}` | Update priority/status |
| DELETE | `/api/social-queue/{id}` | Remove from queue |
| GET | `/api/social-queue/metrics` | Queue stats |
| GET | `/api/social-queue/best-times` | Best posting times |

### Email Marketing
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/email-lists` | List email lists |
| POST | `/api/email-lists` | Create list |
| DELETE | `/api/email-lists/{id}` | Delete list |
| GET | `/api/subscribers` | List subscribers |
| POST | `/api/subscribers` | Add subscriber |
| POST | `/api/subscribers/import` | CSV import |
| DELETE | `/api/subscribers/{id}` | Remove subscriber |
| GET | `/api/email-campaigns` | List campaigns |
| POST | `/api/email-campaigns` | Create campaign |
| PUT | `/api/email-campaigns/{id}` | Update campaign |
| DELETE | `/api/email-campaigns/{id}` | Delete campaign |
| POST | `/api/email-campaigns/{id}/send` | Send to all subscribers |
| POST | `/api/email-campaigns/{id}/test` | Send test email |
| GET | `/api/email-campaigns/{id}/stats` | Open/click rates |
| GET | `/api/email-templates` | List templates |
| POST | `/api/email-templates` | Create template |
| PUT | `/api/email-templates/{id}` | Update template |
| DELETE | `/api/email-templates/{id}` | Delete (custom only) |
| POST | `/api/email-templates/{id}/render` | Render with variables |

### Other Endpoints
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/competitors` | List competitors |
| POST | `/api/competitors` | Add competitor |
| GET | `/api/kpis` | List KPI logs |
| POST | `/api/kpis` | Log KPI |
| GET | `/api/templates` | Content templates |
| GET | `/api/media` | Media library |
| POST | `/api/media` | Upload file |
| GET | `/api/rss-feeds` | RSS feeds |
| GET | `/api/rss-items` | RSS items |
| GET | `/api/utm-links` | UTM links |
| POST | `/api/utm-links` | Create UTM link |
| GET | `/api/short-links` | Short links |
| POST | `/api/short-links` | Create short link |
| GET | `/api/landing-pages` | Landing pages |
| POST | `/api/landing-pages` | Create landing page |
| GET | `/api/forms` | Forms |
| POST | `/api/forms` | Create form |
| GET | `/api/ab-tests` | A/B tests |
| POST | `/api/ab-tests` | Create test |
| GET | `/api/funnels` | Sales funnels |
| POST | `/api/funnels` | Create funnel |
| GET | `/api/automations` | Automation rules |
| POST | `/api/automations` | Create automation |
| GET | `/api/webhooks` | Webhooks |
| POST | `/api/webhooks` | Create webhook |
| GET | `/api/analytics/overview` | Dashboard metrics |
| GET | `/api/analytics/export/{type}` | CSV export |
| GET | `/api/health` | Health check |
| GET | `/api/settings` | App configuration |

### Public Endpoints (no auth)
| Path | Description |
|------|-------------|
| `/p/{slug}` | Render landing page |
| `/f/{slug}` | Render embeddable form |
| `/s/{code}` | Short link redirect |
| `/api/track/open` | Email open tracking pixel |
| `/api/track/click` | Email click tracking redirect |
| `/api/unsubscribe` | Email unsubscribe |
| `/api/forms/{slug}/submit` | Public form submission |

## Security

- **Authentication:** Session-based (browser) + Bearer token (API)
- **CSRF protection:** Token validation on all mutating requests
- **Rate limiting:** IP-based with configurable windows
- **Password hashing:** bcrypt
- **SQL injection:** Prepared statements throughout
- **XSS prevention:** HTML escaping in all templates
- **Email security:** HMAC-SHA256 signed unsubscribe URLs
- **Webhook security:** HMAC-SHA256 request signatures
- **File upload validation:** MIME type checking, size limits
- **Security headers:** X-Content-Type-Options, X-Frame-Options, X-XSS-Protection, Referrer-Policy
- **Path protection:** `.env`, `data/`, `src/` blocked via .htaccess/nginx

## Cron Setup

For scheduled posts, recurring content, and RSS fetching, set up a cron job:

```bash
# Run every 5 minutes
*/5 * * * * curl -s "https://yourdomain.com/cron.php?key=YOUR_CRON_KEY" > /dev/null 2>&1
```

The cron key is set in `.env` as `CRON_KEY`.

## Database

SQLite database at `data/marketing.sqlite` with 35+ tables. Schema is auto-migrating — the `Database` class creates missing tables and columns on every request. No manual migrations needed.

The database file is gitignored. A fresh clone auto-creates it on first request.

## Hosting Scenarios

| Environment | Setup |
|-------------|-------|
| **Local dev** | `php -S localhost:8080 -t public` |
| **Shared hosting** | Upload files, point document root to `public/`, visit `/install.php` |
| **VPS (Nginx)** | Use `nginx.example.conf`, install `php-fpm` |
| **VPS (Apache)** | `.htaccess` handles everything, enable `mod_rewrite` |
| **Docker** | Any PHP 8.1+ image with `pdo_sqlite` and `curl` extensions |
| **PaaS** | Set `public/` as web root, ensure `data/` is writable |

## License

MIT
