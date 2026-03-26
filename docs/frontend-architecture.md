# Frontend Architecture

## Overview

The frontend is a vanilla JavaScript Single Page Application (SPA) with hash-based routing. All page HTML is defined inline in `app.html`. No build step, no framework, no npm packages.

## SPA Shell (`app.html`)

The main HTML file contains:
- Sidebar navigation with 5 collapsible sections
- 24 page sections (hidden by default, shown on navigation)
- AI Writing Assistant floating panel
- Global AI Command Bar (Ctrl+K)
- Login overlay

### Navigation Structure

```
Dashboard
AI & Content
  ├── AI Studio
  ├── AI Chat
  ├── Content Studio
  ├── Content Library
  ├── SEO Tools
Marketing
  ├── Email Marketing
  ├── Campaigns
  ├── Social Accounts
  ├── Publish Queue
Intelligence
  ├── Analytics
  ├── Competitors
  ├── A/B Tests
Audience
  ├── Contacts
  ├── Segments
  ├── Forms
  ├── Landing Pages
Tools
  ├── Links & UTM
  ├── Funnels
  ├── Automations
  ├── RSS Feeds
Settings
```

Section collapse state persists in localStorage (`nav_<section>` keys). The active page's section auto-expands on navigation.

## Core Modules

### `app.js` — Boot Sequence

1. Imports all page modules
2. Registers pages with the router via `registerPage(name, module)`
3. Calls `initAll()` after authentication
4. Sets up inline AI toolbars via `initInlineAiToolbars()`

### `core/router.js` — SPA Router

Hash-based routing: `#page-name` (e.g., `#dashboard`, `#content`, `#ai`)

Key functions:
- `registerPage(name, module)` — Register a page module
- `navigate(page)` — Navigate to a page
- `showPage(page)` — Show the page section, hide others, call `refresh()`
- `initAll()` — Call `init()` on all registered pages

Also handles:
- Tab switching within pages (`.tab-btn[data-tab]` → `.tab-panel`)
- Sidebar section toggling
- Global AI Command Bar (Ctrl+K)

### `core/api.js` — Fetch Wrapper

```javascript
const data = await api('/api/posts', {
    method: 'POST',
    body: { title: 'Hello', body: '...' }
});
```

Features:
- Automatic CSRF token injection in `X-CSRF-Token` header
- Bearer token support for API authentication
- JSON request/response handling
- Error extraction from response body

### `core/toast.js` — Notifications

```javascript
import { success, error } from './toast.js';

success('Post created!');
error('Failed to save');
```

Displays auto-dismissing toast notifications at the top of the page.

### `core/utils.js` — DOM Helpers

| Function | Description |
|----------|-------------|
| `$(selector)` | `document.querySelector` shorthand |
| `$$(selector)` | `document.querySelectorAll` shorthand |
| `escapeHtml(str)` | HTML entity escaping |
| `formatDateTime(str)` | Format ISO date to readable string |
| `formData(event)` | Convert FormData to plain object |
| `statusBadge(status)` | Colored status badge HTML |

## Page Modules

Each page module exports two functions:

```javascript
export function init() {
    // Bind event handlers — called once on boot
}

export function refresh() {
    // Load data from API — called on each page visit
}
```

### Module List (24 pages)

| Module | Page | Key Features |
|--------|------|-------------|
| `dashboard.js` | Dashboard | Metrics, recent items, AI quick actions, AI insights |
| `content.js` | Content Studio | Calendar, list view, post create/edit, AI buttons, inline toolbar |
| `ai.js` | AI Studio | 25+ tools in category tabs, sticky output panel, copy/use actions |
| `chat.js` | AI Chat | Conversational AI interface with history |
| `assistant.js` | AI Writing Assistant | Floating panel, 12 refinement actions, auto-detect textarea |
| `email.js` | Email Marketing | Lists, subscribers, campaign compose, templates, AI toolbar |
| `campaigns.js` | Campaigns | CRUD, ROI metrics, budget utilization, AI strategy |
| `contacts.js` | Contacts CRM | Pipeline view, scoring, activity timeline, CSV import/export |
| `analytics.js` | Analytics | Charts, content performance, CSV export |
| `social.js` | Social Accounts | Account management, platform connections |
| `queue.js` | Publish Queue | Queue management, best times, AI smart times |
| `templates.js` | Content Library | Templates, brand voice profiles, media library |
| `forms.js` | Forms | Form builder, field configuration, submissions |
| `landing.js` | Landing Pages | Page builder, template selection, AI copy generation |
| `abtests.js` | A/B Tests | Test creation, variant management, AI generation |
| `funnels.js` | Sales Funnels | Funnel builder, stage management |
| `automations.js` | Automations | Rule creation, trigger/action configuration |
| `segments.js` | Segments | Criteria builder, contact preview |
| `links.js` | Links & UTM | Short links, UTM builder, click stats |
| `seo.js` | SEO Tools | Keyword research, blog generator |
| `rss.js` | RSS Feeds | Feed management, item curation |
| `competitors.js` | Competitors | Competitor tracking, AI deep dive |
| `settings.js` | Settings | Config display, health check, backup download |
| `onboarding.js` | Onboarding | 5-step wizard: business profile, goals, platforms, competitors, AI Autopilot launch |
| `login.js` | Login | Authentication form |

## Tab System

Pages with multiple views use tabs:

```html
<button class="tab-btn" data-tab="list">List</button>
<button class="tab-btn" data-tab="calendar">Calendar</button>

<div class="tab-panel" data-tab="list">...</div>
<div class="tab-panel" data-tab="calendar">...</div>
```

Tab switching is handled globally in `router.js`. The active tab gets the `.active` class.

## AI Integration Patterns

### AI Button (`.btn-ai`)

All AI buttons use the purple gradient style:

```html
<button class="btn-ai" id="ai-write-btn">AI Write</button>
```

Loading state pattern:
```javascript
btn.classList.add('loading');
btn.disabled = true;
try {
    const res = await api('/api/ai/content', { method: 'POST', body });
    // Handle result
} catch (err) {
    error(err.message);
} finally {
    btn.classList.remove('loading');
    btn.disabled = false;
}
```

### AI Inline Toolbar

Contextual AI buttons above textarea fields:

```html
<div class="ai-inline-toolbar">
    <button class="ai-inline-btn" data-inline-refine="improve">Improve</button>
    <button class="ai-inline-btn" data-inline-refine="expand">Expand</button>
    <button class="ai-inline-btn" data-inline-refine="shorten">Shorten</button>
</div>
```

Wired globally in `app.js::initInlineAiToolbars()`.

### AI Writing Assistant

Floating side panel (`pages/assistant.js`):
- Activated via purple FAB button (bottom-right corner)
- Detects the last-focused textarea on the current page
- Quick actions, tone changes, analysis tools
- "Apply to Field" replaces the textarea content with AI output

### Global Command Bar (Ctrl+K)

Quick access from any page. 10 AI actions in 2 groups:
- Content creation shortcuts
- Analysis and optimization tools

## CSS Architecture

### Theme System

CSS variables on `:root` (dark) and `body.light` (light):

| Variable | Dark Default | Light Default | Usage |
|----------|-------------|---------------|-------|
| `--bg` | Dark background | Light background | Page background |
| `--panel` | Panel background | Panel background | Card/section backgrounds |
| `--text` | Light text | Dark text | Text color |
| `--accent` | Accent color | Accent color | Links, active states |
| `--line` | Border color | Border color | Dividers, borders |
| `--input-bg` | Input background | Input background | Form inputs |
| `--radius` | Border radius | Border radius | Rounded corners |

### AI Styling

Purple gradient for AI elements:
```css
background: linear-gradient(135deg, #6366f1, #8b5cf6, #a855f7);
```

### Accessibility

- `:focus-visible` outlines on all interactive elements for keyboard navigation
- Input focus styles use `box-shadow` instead of outline (already in place via `:focus`)
- Disabled buttons get `opacity: 0.5`, `cursor: not-allowed`, and `pointer-events: none`
- Modal overlays include `role="dialog"` and `aria-labelledby` pointing to the modal title
- Icon-only buttons (close `&times;`, menu toggle `&#9776;`, theme toggle `&#9790;`) include `aria-label`
- Modal open/close uses animated `opacity` + `visibility` + `transform` transitions
- Loading skeletons available via `.skeleton` class with shimmer animation

### Responsive Design

The layout is responsive with:
- Collapsible sidebar on smaller screens (hidden at 768px, toggle via menu button)
- Stacked layouts on mobile (`.row2`, `.row3`, `.row4` collapse to single column)
- Responsive tables via `.table-wrap` with horizontal scroll on mobile
- AI Studio layout switches from 2-column to stacked at 1024px
- AI Assistant panel goes full-width on mobile
