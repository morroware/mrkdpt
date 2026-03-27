# Configuration

## Environment Variables

The `.env` file is created by the web installer (`/install.php`) or can be created manually in the project root.

### Business Settings

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `BUSINESS_NAME` | Yes | — | Your business name (used in AI prompts and emails) |
| `BUSINESS_INDUSTRY` | No | — | Industry context for AI-generated content |
| `TIMEZONE` | No | `UTC` | PHP timezone identifier (e.g., `America/New_York`) |

### AI Provider Configuration

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `AI_PROVIDER` | No | `openai` | Primary AI provider |

Valid values: `openai`, `anthropic`, `gemini`, `deepseek`, `groq`, `mistral`, `openrouter`, `xai`, `together`

#### OpenAI

| Variable | Default | Description |
|----------|---------|-------------|
| `OPENAI_API_KEY` | — | API key from platform.openai.com |
| `OPENAI_BASE_URL` | `https://api.openai.com/v1` | API base URL (for proxies/compatible APIs) |
| `OPENAI_MODEL` | `gpt-4.1-mini` | Model identifier |

#### Anthropic (Claude)

| Variable | Default | Description |
|----------|---------|-------------|
| `ANTHROPIC_API_KEY` | — | API key from console.anthropic.com |
| `ANTHROPIC_MODEL` | `claude-sonnet-4-20250514` | Model identifier |

#### Google Gemini

| Variable | Default | Description |
|----------|---------|-------------|
| `GEMINI_API_KEY` | — | API key from ai.google.dev |
| `GEMINI_MODEL` | `gemini-2.5-flash` | Model identifier |

#### DeepSeek

| Variable | Default | Description |
|----------|---------|-------------|
| `DEEPSEEK_API_KEY` | — | API key |
| `DEEPSEEK_MODEL` | `deepseek-chat` | Model identifier |

#### Groq

| Variable | Default | Description |
|----------|---------|-------------|
| `GROQ_API_KEY` | — | API key from console.groq.com |
| `GROQ_MODEL` | `llama-3.3-70b-versatile` | Model identifier |

#### Mistral

| Variable | Default | Description |
|----------|---------|-------------|
| `MISTRAL_API_KEY` | — | API key |
| `MISTRAL_MODEL` | `mistral-large-latest` | Model identifier |

#### OpenRouter

| Variable | Default | Description |
|----------|---------|-------------|
| `OPENROUTER_API_KEY` | — | API key from openrouter.ai |
| `OPENROUTER_MODEL` | `anthropic/claude-sonnet-4` | Model identifier |

#### xAI (Grok)

| Variable | Default | Description |
|----------|---------|-------------|
| `XAI_API_KEY` | — | API key |
| `XAI_MODEL` | `grok-3-fast` | Model identifier |

#### Together AI

| Variable | Default | Description |
|----------|---------|-------------|
| `TOGETHER_API_KEY` | — | API key from api.together.xyz |
| `TOGETHER_MODEL` | `meta-llama/Llama-3.3-70B-Instruct-Turbo` | Model identifier |

#### Image Generation (Banana/NanoBanana)

| Variable | Default | Description |
|----------|---------|-------------|
| `BANANA_API_KEY` | — | API key |
| `BANANA_BASE_URL` | `https://api.banana.dev` | API base URL |
| `BANANA_MODEL_ID` | — | Model ID for image generation |

### Application Settings

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `APP_URL` | Yes | — | Public URL (e.g., `https://marketing.example.com`) |
| `APP_FORCE_HTTPS` | No | `false` | Force HTTP requests to redirect to HTTPS in production |
| `TRUST_PROXY_HEADERS` | No | `false` | Trust `X-Forwarded-For` for rate limiting/client IP when behind a reverse proxy |
| `MAX_UPLOAD_MB` | No | `10` | Maximum upload file size (1-100 MB) |
| `CRON_KEY` | Yes | — | Secret key for cron endpoint authentication |

### SMTP (Email Marketing)

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `SMTP_HOST` | For email | — | SMTP server hostname |
| `SMTP_PORT` | For email | `587` | SMTP port (25, 465, 587) |
| `SMTP_USER` | For email | — | SMTP username |
| `SMTP_PASS` | For email | — | SMTP password |
| `SMTP_FROM` | For email | — | Sender email address |
| `SMTP_FROM_NAME` | No | — | Sender display name |

## Web Installer

The setup wizard at `/install.php` provides a guided setup:

1. **Business Information** — Name, industry, timezone, URL
2. **Admin Account** — Username and password (minimum 10 chars with uppercase, lowercase, number, symbol)
3. **AI Provider** — Select provider and enter API key
4. **Additional Providers** — Optional secondary AI provider keys
5. **SMTP Configuration** — Email marketing settings
6. **Upload Limits** — Maximum file upload size

The installer:
- Writes the `.env` file
- Creates the `data/` directory with `0750` permissions
- Initializes the SQLite database
- Creates the admin user account
- Generates a random cron key

## Deployment Options

| Environment | Setup |
|-------------|-------|
| **Local dev** | `php -S localhost:8080 -t public` |
| **Shared hosting** | Upload files, point document root to `public/`, visit `/install.php` |
| **VPS (Nginx)** | Use `nginx.example.conf`, install `php-fpm` |
| **VPS (Apache)** | `.htaccess` handles everything, enable `mod_rewrite` |
| **Docker** | Any PHP 8.1+ image with `pdo_sqlite` and `curl` |
| **PaaS** | Set `public/` as web root, ensure `data/` is writable |

### Nginx Configuration

An example Nginx config is provided in `nginx.example.conf`. Key directives:

- Document root points to `public/`
- All requests fall through to `index.php`
- Blocks access to `.env`, `data/`, and `src/`
- Enables gzip compression
- Sets security headers

### Apache Configuration

The included `public/.htaccess` provides:

- URL rewriting (all requests to `index.php`)
- Security headers
- Blocks access to sensitive paths
- Enables compression

### Cron Setup

For scheduled posts, recurring content, and RSS fetching:

```bash
# Run every 5 minutes
*/5 * * * * curl -s "https://yourdomain.com/cron.php?key=YOUR_CRON_KEY" > /dev/null 2>&1

# Or via CLI
*/5 * * * * cd /path/to/marketing && php public/cron.php
```

The cron runner executes:
1. Publishing due scheduled posts (with exponential backoff retry)
2. Creating recurring post instances
3. Fetching RSS feed updates

## Fallback Mode

If AI API keys are not configured, the application returns deterministic fallback output so all workflows remain functional. This allows the platform to be used without paid API access for testing and development.
