# Automations & Workflows

## Overview

The automation system enables trigger-action workflows that execute automatically when events occur. Rules consist of a trigger event, optional conditions, and an action.

## Automation Rules

### Creating a Rule

```
POST /api/automations
{
    "name": "Tag new form leads",
    "trigger_event": "form.submitted",
    "conditions": { "form_id": 5 },
    "action_type": "tag_contact",
    "action_config": { "tag": "form-lead" },
    "enabled": true
}
```

### Trigger Events

| Event | Context Data | Description |
|-------|-------------|-------------|
| `form.submitted` | `form_id`, `email`, `submission_data` | A form is submitted |
| `contact.created` | `contact_id`, `email`, `source` | A new contact is created |
| `contact.stage_changed` | `contact_id`, `old_stage`, `new_stage` | Contact pipeline stage changes |
| `post.published` | `post_id`, `platform`, `title` | A post is published |
| `post.scheduled` | `post_id`, `platform`, `scheduled_for` | A post is scheduled |
| `subscriber.added` | `subscriber_id`, `email`, `list_id` | A new email subscriber is added |
| `email.sent` | `campaign_id`, `subscriber_count` | An email campaign is sent |
| `landing_page.conversion` | `page_id`, `form_id` | A landing page form is submitted |
| `link.clicked` | `link_id`, `link_type`, `url` | A tracked link is clicked |

### Action Types

| Action | Config Fields | Description |
|--------|--------------|-------------|
| `tag_contact` | `tag` | Add tag to the contact |
| `update_contact_stage` | `stage` | Change contact pipeline stage |
| `add_score` | `points` | Add score points to contact |
| `add_to_list` | `list_id` | Add subscriber to email list |
| `send_email` | `subject`, `body_html`, `body_text` | Send a transactional-style email to the matched contact |
| `send_sms` | `message` | Send SMS to contact phone via Twilio (requires E.164 phone) |
| `send_webhook` | `url`, `method` | Send webhook (SSRF-protected) |
| `log_activity` | `description` | Log activity on contact |

### Conditions

Conditions are optional JSON rules that must match the event context for the action to execute:

```json
// Simple equality
{ "form_id": 5 }

// Array match (IN)
{ "stage": ["mql", "sql"] }

// Multiple conditions (AND)
{ "source": "form", "stage": "lead" }
```

### Execution

When an event fires:
1. All active rules for that event are loaded
2. For each rule, conditions are checked against the event context
3. If conditions match, the action is executed
4. `run_count` is incremented and `last_run` is updated

### Viewing Available Options

```
GET /api/automations/options
```

Returns all trigger events and action types with descriptions.

## Sales Funnels

### Creating a Funnel

```
POST /api/funnels
{
    "name": "Product Launch Funnel",
    "description": "Lead capture to purchase"
}
```

### Adding Stages

```
POST /api/funnels/{id}/stages
{
    "name": "Awareness",
    "description": "Landing page visitors",
    "stage_order": 1,
    "target_count": 10000,
    "actual_count": 8500,
    "color": "#4c8dff"
}
```

### Funnel Metrics

Each stage tracks:
- **Target count** — Goal number
- **Actual count** — Current number
- **Conversion rate** — Percentage progressing to next stage

### AI Funnel Advisor

```
POST /api/ai/funnel-advisor
{ "funnel_id": 1 }
```

Returns optimization recommendations for improving conversion between stages.

## A/B Testing

### Creating a Test

```
POST /api/ab-tests
{
    "name": "Homepage Headline Test",
    "type": "headline",
    "goal": "Click-through to pricing",
    "status": "running",
    "start_date": "2025-06-01",
    "end_date": "2025-06-30"
}
```

### Adding Variants

```
POST /api/ab-tests/{id}/variants
{
    "variant_name": "Control",
    "content": "Scale Your Business Today",
    "description": "Original headline"
}
```

### Recording Events

```
# Record impression
POST /api/ab-tests/variants/{id}/impression

# Record conversion
POST /api/ab-tests/variants/{id}/conversion
```

### Metrics

Each variant tracks:
- **Impressions** — Number of views
- **Conversions** — Number of goal completions
- **Conversion Rate** — `(conversions / impressions) * 100`

### AI Variant Generation

```
POST /api/ai/ab-generate
{
    "content": "Scale Your Business Today",
    "test_type": "headline",
    "variants": 5
}
```

### AI Test Analysis

```
POST /api/ai/ab-analyze
{ "test_id": 1 }
```

Returns winner recommendation, statistical analysis, and suggestions.

## Webhooks

### Creating a Webhook

```
POST /api/webhooks
{
    "event": "post.published",
    "url": "https://hooks.example.com/marketing",
    "active": true
}
```

A `secret` key is auto-generated for HMAC signing.

### Events

| Event | Description |
|-------|-------------|
| `post.published` | Post published to a platform |
| `post.scheduled` | Post scheduled for future |
| `campaign.created` | New campaign created |
| `subscriber.added` | New email subscriber |
| `cron.completed` | Cron job completed |
| `email.sent` | Email campaign sent |

### Payload Format

```json
{
    "event": "post.published",
    "timestamp": "2025-06-15T14:30:00+00:00",
    "data": {
        "post_id": 42,
        "title": "Summer Sale Announcement",
        "platform": "twitter"
    }
}
```

### Security

Webhook requests include:
- **`X-Webhook-Signature`** — HMAC-SHA256 of the request body signed with the webhook's secret key
- **`X-Webhook-Event`** — Event name
- **`User-Agent`** — `MarketingSuite-Webhook/1.0`
- **`Content-Type`** — `application/json`

### Verification Example

```php
$signature = hash_hmac('sha256', $requestBody, $secret);
if (hash_equals($signature, $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'])) {
    // Valid webhook
}
```

### Testing

```
POST /api/webhooks/{id}/test
```

Sends a test ping payload to verify the endpoint is reachable.

## RSS Feed Reader

### Adding Feeds

```
POST /api/rss-feeds
{
    "url": "https://blog.example.com/feed.xml",
    "title": "Example Blog",
    "category": "industry-news"
}
```

### Fetching

Feeds are automatically fetched by the cron scheduler. Manual fetch:

```
POST /api/rss-feeds/{id}/fetch
```

Supports RSS 2.0 and Atom formats. Items are deduplicated by URL. Each fetch stores up to 30 new items.

### RSS to Post

Convert an RSS item to a social post using AI:

```
POST /api/ai/rss-to-post
{
    "title": "Article Title",
    "summary": "Article summary...",
    "url": "https://example.com/article",
    "platform": "twitter"
}
```

### Curation

Mark items as curated for later use:

```
PATCH /api/rss-items/{id}
```

---

**Next:** [Content Management](content-management.md) | [CRM & Contacts](crm-contacts.md) | [API Reference](api-reference.md)
