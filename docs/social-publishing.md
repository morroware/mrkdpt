# Social Publishing

## Overview

The social publishing system supports 15 platforms through a unified `publish()` interface in `SocialPublisher.php`. Posts can be published immediately, scheduled via cron, or managed through the publish queue.

## Supported Platforms

| Platform | Protocol | Auth Method | Notes |
|----------|----------|-------------|-------|
| Twitter/X | API v2 + v1.1 media | OAuth 2.0 Bearer | Media upload uses v1.1 chunked endpoint |
| Bluesky | AT Protocol | Identifier + password | Creates session on each publish |
| Mastodon | ActivityPub | OAuth token | Requires instance URL |
| Facebook Pages | Graph API v19.0 | Page Access Token | Pages only, not personal profiles |
| Instagram | Graph API | Access Token | Requires publicly accessible image URL |
| LinkedIn | REST API v2 | OAuth 2.0 | URN-based author identification |
| Threads | Meta Graph API | Access Token | Two-step container publish flow |
| Pinterest | REST API v5 | OAuth 2.0 | Requires board_id and image URL |
| TikTok | Content Posting API | OAuth 2.0 | Video/photo upload |
| Reddit | REST API | OAuth 2.0 | Subreddit targeting, self/link posts |
| Telegram | Bot API | Bot token | Requires chat_id |
| Discord | Webhooks | Webhook URL | No OAuth needed |
| Slack | Incoming Webhooks | Webhook URL | No OAuth needed |
| WordPress | WP REST API | Application Passwords | Basic auth with app password |
| Medium | REST API | Integration token | Bearer token auth |

## Social Account Setup

### Adding an Account

```
POST /api/social-accounts
{
    "platform": "twitter",
    "account_name": "@myaccount",
    "access_token": "...",
    "metadata": {
        "refresh_token": "...",
        "additional_field": "..."
    }
}
```

### Platform-Specific Metadata

| Platform | Required Metadata |
|----------|------------------|
| Mastodon | `instance_url` (e.g., `https://mastodon.social`) |
| Facebook | Page access token with `pages_manage_posts` permission |
| Instagram | Business account ID, public image hosting |
| LinkedIn | `urn` (organization or person URN) |
| Pinterest | `board_id` |
| TikTok | `open_id` |
| Reddit | `subreddit` |
| Telegram | `chat_id` |
| Discord | Webhook URL as access token |
| Slack | Webhook URL as access token |
| WordPress | `site_url`, username as account name |

### Testing Connections

```
POST /api/social-accounts/{id}/test
```

Verifies the account credentials are valid and returns platform-specific account info.

## Publishing Flow

### Direct Publish

When a post status changes to `published`, the scheduler detects associated social accounts and publishes:

```
Post (status: scheduled, scheduled_for: datetime)
     │
     ▼ (cron runs, time reached)
Scheduler::publishDuePosts()
     │
     ├── Find matching social accounts
     ├── For each account:
     │   └── SocialPublisher::publish(post, account)
     │       ├── Route to platform-specific method
     │       ├── Handle media upload if needed
     │       └── Return external_id or error
     └── Log result in publish_log
```

### Retry Logic

Failed publishes use exponential backoff:
- **Attempt 1:** Immediate
- **Attempt 2:** After 5 minutes
- **Attempt 3:** After 20 minutes
- **Attempt 4:** After 80 minutes
- **After 3 retries:** Marked as permanently failed

## Publish Queue

The queue system provides prioritized, time-optimized publishing.

### Queue Operations

```
# Add to queue
POST /api/social-queue
{
    "post_id": 1,
    "social_account_id": 1,
    "priority": 5,
    "optimal_time": "2025-06-15T14:30:00Z"
}

# View queue
GET /api/social-queue?status=queued

# Reorder
PATCH /api/social-queue/{id}
{ "priority": 10 }
```

### Queue Statuses

| Status | Description |
|--------|-------------|
| `queued` | Waiting to be published |
| `publishing` | Currently being published |
| `published` | Successfully published |
| `failed` | Publish attempt failed |

### Best Posting Times

The system calculates optimal posting times from historical publish success data:

```
GET /api/social-queue/best-times?platform=twitter
```

Returns the top 20 time slots by success rate, broken down by day of week and hour.

### AI Smart Posting Times

```
POST /api/ai/smart-times
{
    "platform": "twitter",
    "audience": "B2B SaaS professionals",
    "content_type": "social_post"
}
```

AI-powered posting time recommendations based on platform and audience characteristics.

## Publish Log

Every publish attempt is logged:

| Field | Description |
|-------|-------------|
| `post_id` | Source post |
| `social_account_id` | Target account |
| `platform` | Platform name |
| `status` | success / error |
| `external_id` | Platform's post ID |
| `error_message` | Error details (if failed) |
| `published_at` | Timestamp |
