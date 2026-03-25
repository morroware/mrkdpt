# WordPress Plugin

## Overview

The **Marketing Suite Connector** is a WordPress plugin that bridges your WordPress site with the Marketing Suite platform. It enables content sync, AI writing tools, and campaign management directly from the WordPress admin.

## Requirements

- WordPress 6.0+
- PHP 8.0+
- A running Marketing Suite instance with API access

## Installation

1. Upload the `marketing-suite-connector` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Marketing Suite > Settings**
4. Enter your Marketing Suite URL (e.g., `https://marketing.example.com`)
5. Paste your API token (found in Marketing Suite under Settings)
6. Click **Save Changes** and **Test Connection**

## Plugin Structure

```
marketing-suite-connector/
├── marketing-suite-connector.php    # Plugin bootstrap (singleton)
├── readme.txt                       # WordPress plugin readme
├── includes/
│   ├── class-msc-api-client.php     # HTTP client for Marketing Suite API
│   ├── class-msc-settings.php       # Settings page
│   ├── class-msc-dashboard-widget.php # WP dashboard widget + full page
│   ├── class-msc-post-metabox.php   # Post editor sidebar metabox
│   └── class-msc-content-sync.php   # Content pull/push operations
└── assets/
    ├── admin.css                    # Admin styles
    └── admin.js                     # Admin JavaScript
```

## Features

### Admin Menu

The plugin adds a top-level **Marketing Suite** menu with:
- **Dashboard** — Metrics overview, recent posts, active campaigns
- **Content Sync** — Pull/push content between WordPress and Marketing Suite
- **Settings** — API connection configuration

### Dashboard Widget

A WordPress dashboard widget shows:
- Total posts in Marketing Suite
- Published vs. draft counts
- Active campaigns
- Recent posts with quick links

### Full Dashboard Page

The dedicated dashboard page provides:
- Content metrics (total, published, drafts)
- Campaign overview with budgets
- Recent posts list
- Quick action buttons

### Content Sync

#### Pull Content (Marketing Suite → WordPress)

Pulls posts from Marketing Suite and displays them in a table:
- Filter by status and platform
- Import individual posts as WordPress drafts
- Post content maps to WordPress post title and content

#### Push Content (WordPress → Marketing Suite)

Push WordPress posts to Marketing Suite:
- Select platform and content type
- Post title and content are sent to Marketing Suite
- Status defaults to `draft`

### Post Editor Metabox

In the WordPress post editor sidebar:
- **Push to Marketing Suite** — Send the current post to Marketing Suite
- **AI Generate** — Generate content using Marketing Suite's AI
- **AI Refine** — Improve, expand, or optimize the current post content

### AI Content Generation

Generate complete blog posts and articles from WordPress:

```
POST msc/v1/ai-generate
{
    "topic": "How to improve your marketing ROI",
    "content_type": "blog",
    "platform": "blog"
}
```

The generated content can be inserted directly into the WordPress editor.

### AI Refinement

Refine existing post content:

```
POST msc/v1/ai-refine
{
    "content": "Current post content...",
    "action": "improve"
}
```

Available actions: improve, expand, shorten, formal, casual, persuasive, simplify

## WordPress REST API Routes

The plugin registers these REST API routes under the `msc/v1` namespace:

| Method | Route | Permission | Description |
|--------|-------|-----------|-------------|
| POST | `/msc/v1/test-connection` | `manage_options` | Test API connection |
| GET | `/msc/v1/pull-posts` | `edit_posts` | Pull posts from Marketing Suite |
| POST | `/msc/v1/push-post` | `edit_posts` | Push WP post to Marketing Suite |
| POST | `/msc/v1/import-post` | `edit_posts` | Import Marketing Suite post to WP |
| GET | `/msc/v1/analytics` | `edit_posts` | Get dashboard analytics |
| POST | `/msc/v1/ai-generate` | `edit_posts` | Generate AI content |
| POST | `/msc/v1/ai-refine` | `edit_posts` | Refine content with AI |

## Configuration

### WordPress Options

| Option | Description |
|--------|-------------|
| `msc_api_url` | Marketing Suite instance URL |
| `msc_api_token` | API bearer token |
| `msc_default_status` | Default status for pushed posts (default: `draft`) |

### Transients

The plugin uses WordPress transients for caching:

| Transient | Description |
|-----------|-------------|
| `msc_dashboard_metrics` | Cached dashboard data |
| `msc_remote_posts` | Cached post list |

Transients are cleared on plugin deactivation.

## API Client

The `MSC_API_Client` class handles all communication with the Marketing Suite API:
- Uses WordPress `wp_remote_*` functions for HTTP
- Automatically includes bearer token authentication
- Handles error responses and timeouts
- Base URL configurable via settings

## Compatibility

- Works with both Block Editor (Gutenberg) and Classic Editor
- Compatible with WordPress 6.0 through 6.7
- No conflicts with common plugins
- Uses standard WordPress APIs (Settings API, REST API, Dashboard Widgets)

## Marketing Suite API Endpoints Used

The plugin communicates with these Marketing Suite endpoints:

| Endpoint | Usage |
|----------|-------|
| `GET /api/wordpress-plugin/status` | Connection testing |
| `GET /api/wordpress-plugin/dashboard` | Dashboard widget data |
| `GET /api/wordpress-plugin/posts` | Content sync - list posts |
| `GET /api/wordpress-plugin/posts/{id}` | Content sync - get post |
| `POST /api/wordpress-plugin/posts` | Push content to Marketing Suite |
| `PUT /api/wordpress-plugin/posts/{id}` | Update content in Marketing Suite |
| `POST /api/ai/content` | AI content generation |
| `POST /api/ai/refine` | AI content refinement |

## License

GPL-2.0-or-later
