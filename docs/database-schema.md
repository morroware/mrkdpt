# Database Schema

SQLite database at `data/marketing.sqlite`. Schema auto-migrates via `Database.php` — tables and columns are created or updated on every request. No manual migrations needed.

## Connection Settings

- **WAL mode** enabled for concurrent read/write
- **Foreign keys** enforced
- **Busy timeout:** 5000ms

## Tables

### Authentication

#### `users`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `username` | TEXT UNIQUE | Login username |
| `password_hash` | TEXT | bcrypt hash |
| `role` | TEXT | User role (default: 'admin') |
| `api_token` | TEXT | Bearer token for API access |
| `created_at` | TEXT | ISO 8601 timestamp |

#### `rate_limits`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `key` | TEXT | Rate limit key (IP or action) |
| `attempted_at` | TEXT | Timestamp of attempt |

### Content

#### `posts`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `campaign_id` | INTEGER | FK to campaigns |
| `platform` | TEXT | Target platform |
| `content_type` | TEXT | social_post, blog, email, ad_copy, video_script |
| `title` | TEXT | Post title |
| `body` | TEXT | Post content |
| `status` | TEXT | draft, pending_review, approved, rejected, scheduled, published, failed |
| `tags` | TEXT | Comma-separated |
| `cta` | TEXT | Call-to-action |
| `ai_score` | INTEGER | AI quality score (1-100) |
| `scheduled_for` | TEXT | Scheduled publish datetime |
| `published_at` | TEXT | Actual publish datetime |
| `is_evergreen` | INTEGER | Boolean flag |
| `recurrence` | TEXT | daily, weekly, biweekly, monthly |
| `recurrence_parent_id` | INTEGER | Parent post ID for recurring |
| `media_ids` | TEXT | Comma-separated media IDs |
| `approved_by` | TEXT | Approver name |
| `approved_at` | TEXT | Approval timestamp |
| `rejection_notes` | TEXT | Rejection reason |
| `retry_count` | INTEGER | Publish retry count |
| `next_retry_at` | TEXT | Next retry timestamp |
| `created_at` | TEXT | ISO 8601 |
| `updated_at` | TEXT | ISO 8601 |

#### `post_notes`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `post_id` | INTEGER | FK to posts |
| `author` | TEXT | Note author |
| `note` | TEXT | Note content |
| `created_at` | TEXT | ISO 8601 |

#### `content_ideas`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `topic` | TEXT | Idea topic |
| `platform` | TEXT | Target platform |
| `content` | TEXT | Generated content |
| `metadata` | TEXT | JSON metadata |
| `created_at` | TEXT | ISO 8601 |

#### `research_briefs`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `audience` | TEXT | Target audience |
| `goal` | TEXT | Research goal |
| `content` | TEXT | Research output |
| `created_at` | TEXT | ISO 8601 |

### Campaigns

#### `campaigns`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `name` | TEXT | Campaign name |
| `channel` | TEXT | Marketing channel |
| `objective` | TEXT | Campaign objective |
| `budget` | REAL | Total budget |
| `spend_to_date` | REAL | Auto-calculated |
| `revenue` | REAL | Auto-calculated |
| `status` | TEXT | active, paused, completed, draft |
| `start_date` | TEXT | Campaign start |
| `end_date` | TEXT | Campaign end |
| `notes` | TEXT | Campaign notes |
| `kpi_target` | TEXT | Target KPI |
| `kpi_current` | TEXT | Current KPI |
| `created_at` | TEXT | ISO 8601 |
| `updated_at` | TEXT | ISO 8601 |

#### `campaign_metrics`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `campaign_id` | INTEGER | FK to campaigns |
| `metric_date` | TEXT | Date of metrics |
| `impressions` | INTEGER | View count |
| `clicks` | INTEGER | Click count |
| `conversions` | INTEGER | Conversion count |
| `spend` | REAL | Daily spend |
| `revenue` | REAL | Daily revenue |
| `created_at` | TEXT | ISO 8601 |

### Social Publishing

#### `social_accounts`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `platform` | TEXT | Platform name |
| `account_name` | TEXT | Display name |
| `access_token` | TEXT | OAuth/API token |
| `refresh_token` | TEXT | OAuth refresh token |
| `token_expires_at` | TEXT | Token expiry |
| `meta_json` | TEXT | Platform-specific metadata (JSON) |
| `is_active` | INTEGER | Active flag |
| `created_at` | TEXT | ISO 8601 |
| `updated_at` | TEXT | ISO 8601 |

#### `publish_log`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `post_id` | INTEGER | FK to posts |
| `social_account_id` | INTEGER | FK to social_accounts |
| `platform` | TEXT | Platform name |
| `status` | TEXT | success / error |
| `external_id` | TEXT | Platform's post ID |
| `error_message` | TEXT | Error details |
| `published_at` | TEXT | ISO 8601 |

#### `social_queue`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `post_id` | INTEGER | FK to posts |
| `social_account_id` | INTEGER | FK to social_accounts |
| `status` | TEXT | queued, publishing, published, failed |
| `priority` | INTEGER | Queue priority |
| `optimal_time` | TEXT | Best time to publish |
| `error_message` | TEXT | Error details |
| `queued_at` | TEXT | ISO 8601 |
| `published_at` | TEXT | ISO 8601 |

### Email Marketing

#### `email_lists`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `name` | TEXT | List name |
| `description` | TEXT | List description |
| `subscriber_count` | INTEGER | Auto-maintained count |
| `created_at` | TEXT | ISO 8601 |

#### `subscribers`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `email` | TEXT | Subscriber email |
| `list_id` | INTEGER | FK to email_lists |
| `first_name` | TEXT | First name |
| `last_name` | TEXT | Last name |
| `status` | TEXT | active / unsubscribed |
| `created_at` | TEXT | ISO 8601 |
| UNIQUE | | (`email`, `list_id`) |

#### `email_campaigns`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `name` | TEXT | Campaign name |
| `subject` | TEXT | Email subject |
| `body_html` | TEXT | HTML body |
| `body_text` | TEXT | Plain text body |
| `list_id` | INTEGER | FK to email_lists |
| `status` | TEXT | draft, sending, sent |
| `sent_at` | TEXT | Send timestamp |
| `sent_count` | INTEGER | Number sent |
| `created_at` | TEXT | ISO 8601 |
| `updated_at` | TEXT | ISO 8601 |

#### `email_templates`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `name` | TEXT | Template name |
| `html_template` | TEXT | HTML with {{variables}} |
| `text_template` | TEXT | Plain text with {{variables}} |
| `category` | TEXT | Template category |
| `variables` | TEXT | JSON array of variable names |
| `thumbnail_color` | TEXT | Preview color |
| `is_builtin` | INTEGER | Protected from deletion |
| `created_at` | TEXT | ISO 8601 |

#### `email_tracking`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `campaign_id` | INTEGER | FK to email_campaigns |
| `subscriber_id` | INTEGER | FK to subscribers |
| `event_type` | TEXT | open / click |
| `url` | TEXT | Clicked URL (for click events) |
| `ip_hash` | TEXT | Privacy-preserving IP hash |
| `user_agent` | TEXT | Browser user agent |
| `created_at` | TEXT | ISO 8601 |

### CRM

#### `contacts`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `email` | TEXT UNIQUE | Contact email |
| `first_name` | TEXT | First name |
| `last_name` | TEXT | Last name |
| `company` | TEXT | Company name |
| `phone` | TEXT | Phone number |
| `stage` | TEXT | lead, mql, sql, opportunity, customer |
| `score` | INTEGER | Lead score (default 0) |
| `tags` | TEXT | Comma-separated tags |
| `source` | TEXT | Acquisition source |
| `source_detail` | TEXT | Source detail |
| `notes` | TEXT | Free-form notes |
| `custom_fields` | TEXT | JSON custom data |
| `last_activity` | TEXT | Last activity timestamp |
| `created_at` | TEXT | ISO 8601 |
| `updated_at` | TEXT | ISO 8601 |

#### `contact_activities`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `contact_id` | INTEGER | FK to contacts |
| `type` | TEXT | Activity type |
| `description` | TEXT | Activity description |
| `data_json` | TEXT | JSON additional data |
| `created_at` | TEXT | ISO 8601 |

#### `audience_segments`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `name` | TEXT | Segment name |
| `description` | TEXT | Segment description |
| `criteria` | TEXT | JSON criteria rules |
| `contact_count` | INTEGER | Computed count |
| `created_at` | TEXT | ISO 8601 |
| `updated_at` | TEXT | ISO 8601 |

### Forms & Landing Pages

#### `forms`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `name` | TEXT | Form name |
| `slug` | TEXT UNIQUE | URL slug |
| `fields` | TEXT | JSON field definitions |
| `submit_label` | TEXT | Submit button text |
| `success_message` | TEXT | Post-submit message |
| `redirect_url` | TEXT | Post-submit redirect |
| `notification_email` | TEXT | Notification recipient |
| `list_id` | INTEGER | Auto-subscribe to list |
| `tag_on_submit` | TEXT | Auto-tag contacts |
| `status` | TEXT | active / inactive |
| `submissions` | INTEGER | Submission counter |
| `created_at` | TEXT | ISO 8601 |

#### `form_submissions`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `form_id` | INTEGER | FK to forms |
| `contact_id` | INTEGER | FK to contacts (if matched) |
| `data_json` | TEXT | JSON submission data |
| `ip_hash` | TEXT | Privacy-preserving IP hash |
| `page_url` | TEXT | Submitting page URL |
| `created_at` | TEXT | ISO 8601 |

#### `landing_pages`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `title` | TEXT | Page title |
| `slug` | TEXT UNIQUE | URL slug |
| `template` | TEXT | startup, minimal, bold, nature, blank |
| `status` | TEXT | published / draft |
| `hero_headline` | TEXT | Hero section headline |
| `hero_subheadline` | TEXT | Hero subheadline |
| `hero_cta_text` | TEXT | CTA button text |
| `hero_cta_url` | TEXT | CTA button URL |
| `hero_image_url` | TEXT | Hero background image |
| `body_html` | TEXT | Body content |
| `custom_css` | TEXT | Custom CSS |
| `form_id` | INTEGER | Integrated form |
| `campaign_id` | INTEGER | Associated campaign |
| `meta_description` | TEXT | SEO meta description |
| `views` | INTEGER | View counter |
| `conversions` | INTEGER | Conversion counter |
| `created_at` | TEXT | ISO 8601 |
| `updated_at` | TEXT | ISO 8601 |

### A/B Testing

#### `ab_tests`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `name` | TEXT | Test name |
| `test_type` | TEXT | Test type |
| `status` | TEXT | running, paused, completed |
| `metric` | TEXT | Success metric (default: clicks) |
| `notes` | TEXT | Test notes |
| `winner_variant` | TEXT | Winning variant name |
| `started_at` | TEXT | Start timestamp |
| `ended_at` | TEXT | End timestamp |
| `created_at` | TEXT | ISO 8601 |

#### `ab_variants`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `test_id` | INTEGER | FK to ab_tests |
| `variant_name` | TEXT | Variant label |
| `content` | TEXT | Variant content |
| `description` | TEXT | Variant description |
| `impressions` | INTEGER | View count |
| `conversions` | INTEGER | Conversion count |
| `created_at` | TEXT | ISO 8601 |

### Sales Funnels

#### `funnels`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `name` | TEXT | Funnel name |
| `description` | TEXT | Funnel description |
| `campaign_id` | INTEGER | Associated campaign |
| `created_at` | TEXT | ISO 8601 |
| `updated_at` | TEXT | ISO 8601 |

#### `funnel_stages`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `funnel_id` | INTEGER | FK to funnels |
| `name` | TEXT | Stage name |
| `description` | TEXT | Stage description |
| `stage_order` | INTEGER | Display order |
| `target_count` | INTEGER | Goal number |
| `actual_count` | INTEGER | Current number |
| `conversion_rate` | REAL | Stage conversion rate |
| `color` | TEXT | Display color |
| `created_at` | TEXT | ISO 8601 |

### Automations

#### `automation_rules`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `name` | TEXT | Rule name |
| `trigger_event` | TEXT | Event type |
| `conditions` | TEXT | JSON conditions |
| `action_type` | TEXT | Action type |
| `action_config` | TEXT | JSON action config |
| `enabled` | INTEGER | Active flag |
| `run_count` | INTEGER | Execution count |
| `last_run` | TEXT | Last execution timestamp |
| `created_at` | TEXT | ISO 8601 |
| `updated_at` | TEXT | ISO 8601 |

### Links & Tracking

#### `utm_links`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `base_url` | TEXT | Original URL |
| `utm_source` | TEXT | Campaign source |
| `utm_medium` | TEXT | Campaign medium |
| `utm_campaign` | TEXT | Campaign name |
| `utm_term` | TEXT | Campaign term |
| `utm_content` | TEXT | Campaign content |
| `full_url` | TEXT | Complete URL with params |
| `campaign_name` | TEXT | Display name |
| `clicks` | INTEGER | Click counter |
| `created_at` | TEXT | ISO 8601 |

#### `short_links`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `code` | TEXT UNIQUE | Short code (6 chars) |
| `destination_url` | TEXT | Redirect target |
| `title` | TEXT | Link title |
| `utm_link_id` | INTEGER | FK to utm_links |
| `clicks` | INTEGER | Click counter |
| `created_at` | TEXT | ISO 8601 |

#### `link_clicks`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `link_id` | INTEGER | FK to short_links or utm_links |
| `link_type` | TEXT | short_link / utm_link |
| `ip_hash` | TEXT | Privacy-preserving IP hash |
| `user_agent` | TEXT | Browser user agent |
| `referer` | TEXT | Referring URL |
| `created_at` | TEXT | ISO 8601 |

### Content Library

#### `templates`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `name` | TEXT | Template name |
| `type` | TEXT | Template type |
| `platform` | TEXT | Target platform |
| `structure` | TEXT | Template content |
| `variables` | TEXT | JSON variable list |
| `created_at` | TEXT | ISO 8601 |
| `updated_at` | TEXT | ISO 8601 |

#### `brand_profiles`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `name` | TEXT | Profile name |
| `voice_tone` | TEXT | Tone description |
| `vocabulary` | TEXT | Preferred words |
| `avoid_words` | TEXT | Words to avoid |
| `example_content` | TEXT | Example content |
| `target_audience` | TEXT | Audience description |
| `is_active` | INTEGER | Active flag (only one at a time) |
| `created_at` | TEXT | ISO 8601 |
| `updated_at` | TEXT | ISO 8601 |

### Media

#### `media`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `filename` | TEXT | Original filename |
| `stored_name` | TEXT | Stored filename (unique) |
| `mime_type` | TEXT | MIME type |
| `size` | INTEGER | File size in bytes |
| `alt_text` | TEXT | Alt text for images |
| `tags` | TEXT | Comma-separated tags |
| `has_thumb` | INTEGER | Thumbnail generated flag |
| `created_at` | TEXT | ISO 8601 |

### Analytics & Tracking

#### `analytics_events`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `event_type` | TEXT | Event type |
| `entity_type` | TEXT | Entity type |
| `entity_id` | INTEGER | Entity ID |
| `data_json` | TEXT | JSON event data |
| `created_at` | TEXT | ISO 8601 |

#### `kpi_logs`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `channel` | TEXT | Marketing channel |
| `metric_name` | TEXT | Metric name |
| `metric_value` | REAL | Metric value |
| `date` | TEXT | Metric date |
| `created_at` | TEXT | ISO 8601 |

### Competitors

#### `competitors`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `name` | TEXT | Competitor name |
| `channel` | TEXT | Primary channel |
| `positioning` | TEXT | Market positioning |
| `recent_activity` | TEXT | Recent activity notes |
| `opportunity` | TEXT | Competitive opportunity |
| `created_at` | TEXT | ISO 8601 |

### AI Chat

#### `ai_chat_conversations`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `title` | TEXT | Conversation title |
| `provider` | TEXT | AI provider used |
| `model` | TEXT | Model used |
| `created_at` | TEXT | ISO 8601 |
| `updated_at` | TEXT | ISO 8601 |

#### `ai_chat_messages`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `conversation_id` | INTEGER | FK to conversations |
| `role` | TEXT | user / assistant |
| `content` | TEXT | Message content |
| `provider` | TEXT | AI provider |
| `model` | TEXT | Model used |
| `created_at` | TEXT | ISO 8601 |

### System

#### `webhooks`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `event` | TEXT | Event type |
| `url` | TEXT | Webhook URL |
| `secret` | TEXT | HMAC signing secret |
| `active` | INTEGER | Active flag |
| `created_at` | TEXT | ISO 8601 |

#### `cron_log`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `task` | TEXT | Task name |
| `status` | TEXT | success / error |
| `message` | TEXT | Execution details |
| `created_at` | TEXT | ISO 8601 |

#### `rss_feeds`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `url` | TEXT | Feed URL |
| `name` | TEXT | Feed name |
| `category` | TEXT | Feed category |
| `is_active` | INTEGER | Active flag |
| `last_fetched_at` | TEXT | Last fetch timestamp |
| `created_at` | TEXT | ISO 8601 |

#### `rss_items`
| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `feed_id` | INTEGER | FK to rss_feeds |
| `title` | TEXT | Item title |
| `url` | TEXT UNIQUE | Item URL |
| `summary` | TEXT | Item summary |
| `is_curated` | INTEGER | Curated flag |
| `published_at` | TEXT | Original publish date |
| `created_at` | TEXT | ISO 8601 |

## Auto-Migration

The `Database::connect()` method:
1. Creates the `data/` directory if needed
2. Opens/creates the SQLite file
3. Enables WAL mode and foreign keys
4. Creates all tables using `CREATE TABLE IF NOT EXISTS`
5. Adds missing columns using `applySafeAlter()`

The `applySafeAlter()` method checks `PRAGMA table_info` before attempting `ALTER TABLE ADD COLUMN`, preventing errors on repeated runs.
