# Content Management

## Overview

The Content Studio is the central hub for creating, managing, and publishing content. It provides a calendar view, list view, post creator, approval workflows, and bulk operations.

## Post Model

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Auto-increment primary key |
| `campaign_id` | int | Optional campaign association |
| `platform` | string | Target platform (twitter, linkedin, instagram, etc.) |
| `content_type` | string | Type: social_post, blog, email, ad_copy, video_script |
| `title` | string | Post title |
| `body` | text | Post content body |
| `status` | string | Workflow status (see below) |
| `tags` | string | Comma-separated tags |
| `cta` | string | Call-to-action text |
| `ai_score` | int | AI-generated quality score (1-100) |
| `scheduled_for` | datetime | Scheduled publish time |
| `published_at` | datetime | Actual publish time |
| `is_evergreen` | bool | Evergreen content flag |
| `recurrence` | string | Recurrence pattern |
| `media_ids` | string | Comma-separated media IDs |
| `approved_by` | string | Approver name |
| `approved_at` | datetime | Approval timestamp |
| `rejection_notes` | string | Rejection reason |

## Workflow States

```
draft → pending_review → approved → scheduled → published
                ↓
            rejected
```

| Status | Description |
|--------|-------------|
| `draft` | Work in progress |
| `pending_review` | Submitted for approval |
| `approved` | Approved, ready to schedule |
| `rejected` | Sent back with notes |
| `scheduled` | Queued for future publish |
| `published` | Live on platform |
| `failed` | Publish attempt failed |

### Approval Actions

- **Request Review** — Moves draft to `pending_review`
- **Approve** — Moves to `approved` with approver name and timestamp
- **Reject** — Moves to `rejected` with notes

### Review Notes

Each post supports a thread of review notes for team collaboration:
- `POST /api/posts/{id}/notes` — Add note with `author` and `note`
- `GET /api/posts/{id}/notes` — List all notes

## Calendar View

The calendar view shows posts organized by day for a given month:

```
GET /api/posts/calendar?year=2025&month=6
```

Returns:
```json
{
    "month": "2025-06",
    "days": {
        "2025-06-01": [{ "id": 1, "title": "...", "platform": "twitter" }],
        "2025-06-05": [{ "id": 2, "title": "..." }]
    }
}
```

## Recurring Posts

Posts can be set to recur automatically:

| Pattern | Description |
|---------|-------------|
| `daily` | Every day |
| `weekly` | Same day each week |
| `biweekly` | Every two weeks |
| `monthly` | Same day each month |

The cron scheduler creates new instances of recurring posts based on the pattern.

## Bulk Operations

```
POST /api/posts/bulk
{
    "ids": [1, 2, 3],
    "action": "publish" | "schedule" | "delete"
}
```

Returns `{ affected: 3 }`.

## AI Integration

### Content Creation

From the Content Studio form:
- **AI Write Content** — Generates full post content based on topic, platform, and content type
- **AI Title** — Generates title suggestions
- **AI Hashtags** — Generates relevant hashtags for the post
- **AI Score** — Scores the current content (1-100)

### Inline AI Toolbar

Rendered above the post body textarea with one-click actions:
- **Improve** — General quality improvement
- **Expand** — Add more detail
- **Shorten** — Condense content
- **Persuasive** — Add persuasive elements
- **Emojis** — Add relevant emojis

### One-Click Repurpose

From the post list view, each post has a "Repurpose" button that sends the content to AI Studio for multi-format conversion.

## Platforms

| Platform | Identifier |
|----------|-----------|
| Twitter/X | `twitter` |
| LinkedIn | `linkedin` |
| Instagram | `instagram` |
| Facebook | `facebook` |
| TikTok | `tiktok` |
| YouTube | `youtube` |
| Pinterest | `pinterest` |
| Bluesky | `bluesky` |
| Mastodon | `mastodon` |
| Threads | `threads` |
| Reddit | `reddit` |
| Blog | `blog` |
| Email | `email` |
| General | `general` |

## Content Types

| Type | Description |
|------|-------------|
| `social_post` | Standard social media post |
| `blog` | Long-form blog article |
| `email` | Email newsletter/campaign content |
| `ad_copy` | Advertisement copy |
| `video_script` | Video script with timestamps |
