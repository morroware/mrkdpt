# API Reference

All API endpoints are prefixed with `/api/`. Authentication is required unless noted otherwise. Mutating requests require a CSRF token in the `X-CSRF-Token` header (except bearer token requests).

## Response Format

```json
// Single item
{ "item": { "id": 1, "name": "..." } }

// Collection
{ "items": [{ "id": 1 }, { "id": 2 }] }

// Action success
{ "ok": true }

// Error
{ "error": "Description of the error" }
```

## Authentication

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/api/login` | Public | Login with `username` and `password`. Returns `user`, `csrf_token`, `api_token` |
| POST | `/api/logout` | Public | End session |
| GET | `/api/setup-status` | Public | Check if initial setup is complete. Returns `{ needs_setup: bool }` |
| GET | `/api/me` | Required | Get current user info with `csrf_token` |
| POST | `/api/regenerate-token` | Required | Generate new API bearer token |

## Content (Posts)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/posts` | List posts. Filters: `status`, `platform`, `campaign_id` |
| POST | `/api/posts` | Create post. Fields: `platform`, `title`, `body`, `content_type`, `status`, `tags`, `cta`, `scheduled_for`, `campaign_id`, `media_ids` |
| GET | `/api/posts/{id}` | Get single post |
| PATCH | `/api/posts/{id}` | Update post fields |
| DELETE | `/api/posts/{id}` | Delete post |
| GET | `/api/posts/calendar` | Calendar view. Params: `year`, `month`. Returns `{ month, days: { date: [posts] } }` |
| POST | `/api/posts/{id}/approve` | Approve content. Body: `approved_by`, `notes` |
| POST | `/api/posts/{id}/reject` | Reject content. Body: `notes` |
| POST | `/api/posts/{id}/request-review` | Request review |
| GET | `/api/posts/{id}/notes` | Get review notes |
| POST | `/api/posts/{id}/notes` | Add review note. Body: `author`, `note` |
| POST | `/api/posts/bulk` | Bulk action. Body: `ids[]`, `action` (publish/schedule/delete) |

### Post Statuses
`draft` → `pending_review` → `approved` → `scheduled` → `published`

Also: `rejected`, `failed`

## Campaigns

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/campaigns` | List all campaigns |
| POST | `/api/campaigns` | Create. Fields: `name`, `channel`, `objective`, `budget`, `status`, `start_date`, `end_date`, `notes` |
| GET | `/api/campaigns/{id}` | Get campaign |
| PUT | `/api/campaigns/{id}` | Update campaign |
| DELETE | `/api/campaigns/{id}` | Delete campaign |

### Campaign Metrics

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/campaigns/{id}/metrics` | Get daily metric entries |
| POST | `/api/campaigns/{id}/metrics` | Add entry: `metric_date`, `impressions`, `clicks`, `conversions`, `spend`, `revenue` |
| GET | `/api/campaigns/{id}/summary` | Calculated metrics: ROI%, CTR, CVR, CPA, ROAS |
| DELETE | `/api/campaign-metrics/{id}` | Delete metric entry |
| POST | `/api/campaigns/compare` | Compare campaigns: `campaign_ids[]` |

## Contacts (CRM)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/contacts` | List. Filters: `stage`, `search` |
| POST | `/api/contacts` | Create. Fields: `email`, `first_name`, `last_name`, `company`, `phone`, `stage`, `score`, `tags`, `source` |
| GET | `/api/contacts/{id}` | Get contact + activity timeline |
| PATCH | `/api/contacts/{id}` | Update contact |
| DELETE | `/api/contacts/{id}` | Delete contact + activities |
| POST | `/api/contacts/{id}/activity` | Log activity: `type`, `description`, `data` |
| GET | `/api/contacts/metrics` | Total, by stage, average score |
| GET | `/api/contacts/stages` | Stage breakdown counts |
| GET | `/api/contacts/export` | CSV download |
| POST | `/api/contacts/import` | Import CSV: `csv` (raw text), `source` |
| POST | `/api/contacts/bulk` | Bulk ops: `ids[]`, `action` (delete/update_stage/add_tag/add_score) |

### Contact Stages
`lead` → `mql` → `sql` → `opportunity` → `customer`

## Audience Segments

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/segments` | List segments |
| POST | `/api/segments` | Create: `name`, `description`, `criteria` (JSON rules) |
| GET | `/api/segments/{id}` | Get segment |
| PUT | `/api/segments/{id}` | Update segment |
| DELETE | `/api/segments/{id}` | Delete segment |
| GET | `/api/segments/{id}/contacts` | Get matching contacts |
| POST | `/api/segments/{id}/recompute` | Recompute contact count |
| GET | `/api/segments/criteria-fields` | Available criteria types |

### Criteria Fields
`stage` (multi-select), `min_score`, `max_score`, `tags`, `source`, `company`, `created_after`, `created_before`, `has_activity_since`, `no_activity_since`

## Social Accounts

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/social-accounts` | List connected accounts |
| POST | `/api/social-accounts` | Connect: `platform`, `account_name`, `access_token`, `metadata` |
| PUT | `/api/social-accounts/{id}` | Update account |
| DELETE | `/api/social-accounts/{id}` | Remove account |
| POST | `/api/social-accounts/{id}/test` | Test connection |

## Social Queue

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/social-queue` | List queue items. Filter: `status` |
| POST | `/api/social-queue` | Enqueue: `post_id`, `social_account_id`, `priority`, `optimal_time` |
| PATCH | `/api/social-queue/{id}` | Update: `priority`, `status`, `error_message` |
| DELETE | `/api/social-queue/{id}` | Remove from queue |
| GET | `/api/social-queue/metrics` | Queue stats: total, queued, published, failed |
| GET | `/api/social-queue/best-times` | Optimal posting times from historical data. Filter: `platform` |

## Email Marketing

### Email Lists

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/email-lists` | List email lists |
| POST | `/api/email-lists` | Create: `name`, `description` |
| DELETE | `/api/email-lists/{id}` | Delete list |

### Subscribers

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/subscribers` | List. Filter: `list_id` |
| POST | `/api/subscribers` | Add: `email`, `list_id`, `first_name`, `last_name`, `status` |
| POST | `/api/subscribers/import` | CSV import: `list_id`, `csv` |
| DELETE | `/api/subscribers/{id}` | Remove subscriber |

### Email Campaigns

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/email-campaigns` | List campaigns |
| POST | `/api/email-campaigns` | Create: `name`, `subject`, `body_html`, `body_text`, `list_id`, `status` |
| GET | `/api/email-campaigns/{id}` | Get campaign |
| PUT | `/api/email-campaigns/{id}` | Update campaign |
| DELETE | `/api/email-campaigns/{id}` | Delete campaign |
| POST | `/api/email-campaigns/{id}/send` | Send to all subscribers. Returns `sent`, `failed`, `skipped` |
| POST | `/api/email-campaigns/{id}/test` | Send test email: `to` (email) |
| GET | `/api/email-campaigns/{id}/stats` | Open/click/bounce statistics |

### Email Templates

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/email-templates` | List templates (built-ins first) |
| POST | `/api/email-templates` | Create: `name`, `html_template`, `text_template`, `category` |
| GET | `/api/email-templates/{id}` | Get template |
| PUT | `/api/email-templates/{id}` | Update template |
| DELETE | `/api/email-templates/{id}` | Delete (custom only, built-ins protected) |
| POST | `/api/email-templates/{id}/render` | Render with variables: `variables: { key: value }` |

## Content Templates & Brand Profiles

### Templates

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/templates` | List templates |
| POST | `/api/templates` | Create: `name`, `category`, `content` |
| GET | `/api/templates/{id}` | Get template |
| PUT | `/api/templates/{id}` | Update template |
| DELETE | `/api/templates/{id}` | Delete template |
| POST | `/api/templates/{id}/clone` | Duplicate template |
| POST | `/api/templates/{id}/render` | Render with variables: `values: { variable: value }` |

### Brand Profiles

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/brand-profiles` | List profiles |
| POST | `/api/brand-profiles` | Create: `name`, `voice_tone`, `vocabulary`, `avoid_words`, `example_content`, `target_audience` |
| GET | `/api/brand-profiles/{id}` | Get profile |
| PUT | `/api/brand-profiles/{id}` | Update profile |
| DELETE | `/api/brand-profiles/{id}` | Delete profile |
| POST | `/api/brand-profiles/{id}/activate` | Set as active profile |

## Forms

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/forms` | List forms |
| POST | `/api/forms` | Create: `name`, `fields` (JSON), `submit_label`, `success_message`, `redirect_url`, `notification_email`, `list_id`, `tag_on_submit` |
| GET | `/api/forms/{id}` | Get form |
| PATCH | `/api/forms/{id}` | Update form |
| DELETE | `/api/forms/{id}` | Delete form + submissions |
| GET | `/api/forms/{id}/submissions` | Get submissions |
| GET | `/api/forms/{id}/embed` | Get embed code (iframe) |
| POST | `/api/forms/{slug}/submit` | **Public** — Submit form |

## Landing Pages

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/landing-pages` | List pages |
| POST | `/api/landing-pages` | Create: `title`, `slug`, `template`, `status`, `hero_*`, `body_html`, `custom_css`, `form_id`, `campaign_id` |
| GET | `/api/landing-pages/{id}` | Get page |
| PATCH | `/api/landing-pages/{id}` | Update page |
| DELETE | `/api/landing-pages/{id}` | Delete page |

## A/B Tests

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/ab-tests` | List tests |
| POST | `/api/ab-tests` | Create: `name`, `type`, `goal`, `status`, `start_date`, `end_date` |
| GET | `/api/ab-tests/{id}` | Get test with variants |
| PATCH | `/api/ab-tests/{id}` | Update test |
| DELETE | `/api/ab-tests/{id}` | Delete test + variants |
| POST | `/api/ab-tests/{id}/variants` | Add variant: `variant_name`, `content`, `description` |
| PATCH | `/api/ab-tests/variants/{id}` | Update variant |
| POST | `/api/ab-tests/variants/{id}/impression` | Record impression |
| POST | `/api/ab-tests/variants/{id}/conversion` | Record conversion |

## Sales Funnels

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/funnels` | List funnels with stages |
| POST | `/api/funnels` | Create: `name`, `description` |
| GET | `/api/funnels/{id}` | Get funnel with stages |
| PATCH | `/api/funnels/{id}` | Update funnel |
| DELETE | `/api/funnels/{id}` | Delete funnel + stages |
| POST | `/api/funnels/{id}/stages` | Add stage: `name`, `description`, `stage_order` |
| PATCH | `/api/funnels/stages/{id}` | Update stage |
| DELETE | `/api/funnels/stages/{id}` | Delete stage |

## Automations

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/automations` | List rules |
| POST | `/api/automations` | Create: `name`, `trigger_event`, `action_type`, `action_config`, `conditions`, `enabled` |
| GET | `/api/automations/{id}` | Get rule |
| PATCH | `/api/automations/{id}` | Update rule |
| DELETE | `/api/automations/{id}` | Delete rule |
| GET | `/api/automations/options` | Available triggers and actions |

## Analytics

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/analytics/overview` | Dashboard metrics (default 30 days). Param: `days` |
| GET | `/api/analytics/content` | Content performance data |
| GET | `/api/analytics/chart/{metric}` | Chart data. Metrics: `posts_by_day`, `posts_by_platform`, `kpi_trend`, `publish_success` |
| GET | `/api/analytics/export/{type}` | CSV download. Types: `posts`, `campaigns`, `kpis`, `competitors`, `subscribers`, `publish_log` |

## Dashboard

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/dashboard` | Overview: `metrics`, `campaigns`, `kpis`, `recent_posts`, `recent_ideas` |

## Links & UTM

### Short Links

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/links` | List short links |
| POST | `/api/links` | Create: `destination_url`, `title`, `custom_code`, `utm_link_id` |
| GET | `/api/links/{id}` | Get link |
| GET | `/api/links/{id}/stats` | Click stats (30-day timeline) |
| DELETE | `/api/links/{id}` | Delete link |

### UTM Links

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/utm` | List UTM links |
| POST | `/api/utm` | Create: `base_url`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`, `create_short_link` |
| DELETE | `/api/utm/{id}` | Delete UTM link |

## Media

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/media` | List media files |
| POST | `/api/media` | Upload file (multipart): `file`, `alt_text`, `tags` |
| DELETE | `/api/media/{id}` | Delete file + thumbnail |

## Competitors

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/competitors` | List competitors |
| POST | `/api/competitors` | Create: `name`, `channel`, `positioning`, `recent_activity`, `opportunity` |
| DELETE | `/api/competitors/{id}` | Delete competitor |

## KPIs

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/kpis` | List KPI logs + summary |
| POST | `/api/kpis` | Log: `channel`, `metric_name`, `metric_value`, `date` |
| GET | `/api/ideas` | List AI-generated ideas |

## RSS Feeds

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/rss-feeds` | List feeds |
| POST | `/api/rss-feeds` | Create: `url`, `title`, `category` |
| PUT | `/api/rss-feeds/{id}` | Update feed |
| DELETE | `/api/rss-feeds/{id}` | Delete feed + items |
| POST | `/api/rss-feeds/{id}/fetch` | Fetch feed items now |
| GET | `/api/rss-items` | List items. Filter: `feed_id` |

## Webhooks

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/webhooks` | List webhooks |
| POST | `/api/webhooks` | Create: `event`, `url`, `active`. Auto-generates `secret` |
| PUT | `/api/webhooks/{id}` | Update webhook |
| DELETE | `/api/webhooks/{id}` | Delete webhook |
| POST | `/api/webhooks/{id}/test` | Send test ping |

### Webhook Events
`post.published`, `post.scheduled`, `campaign.created`, `subscriber.added`, `cron.completed`, `email.sent`

### Webhook Payload
```json
{
    "event": "post.published",
    "timestamp": "2025-01-01T00:00:00+00:00",
    "data": { /* event-specific data */ }
}
```

Requests include:
- `X-Webhook-Signature`: HMAC-SHA256 of body with webhook secret
- `X-Webhook-Event`: Event name
- `Content-Type`: application/json

## Settings

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/settings` | App configuration (business, AI provider, SMTP status) |
| GET | `/api/settings/health` | System health: PHP version, SQLite version, extensions, disk space |
| POST | `/api/settings/backup` | Download SQLite database backup |

## Cron Log

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/cron-log` | Recent cron execution history |

## WordPress Plugin API

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/wordpress-plugin/status` | Connection test |
| GET | `/api/wordpress-plugin/dashboard` | Dashboard data for WP widget |
| GET | `/api/wordpress-plugin/posts` | List posts for content sync |
| GET | `/api/wordpress-plugin/posts/{id}` | Get single post |
| POST | `/api/wordpress-plugin/posts` | Create post from WordPress |
| PUT | `/api/wordpress-plugin/posts/{id}` | Update post |

## Public Endpoints (No Authentication)

| Path | Description |
|------|-------------|
| `GET /api/health` | Health check: `{ ok, service, version }` |
| `GET /p/{slug}` | Render published landing page |
| `GET /f/{slug}` | Render embeddable form |
| `GET /s/{code}` | Short link redirect (302) |
| `POST /api/forms/{slug}/submit` | Public form submission |
| `GET /api/track/open` | Email open tracking pixel (1x1 GIF) |
| `GET /api/track/click` | Email click tracking redirect |
| `GET /api/unsubscribe` | Email unsubscribe page |

## AI Endpoints

See [AI System documentation](ai-system.md) for the complete list of 60+ AI endpoints with parameters and response formats.
