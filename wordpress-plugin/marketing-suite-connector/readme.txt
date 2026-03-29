=== Marketing Suite Connector ===
Contributors: morroware
Tags: marketing, content, ai, sync, automation
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv2 or later

Connect your WordPress site to Marketing Suite for bidirectional content sync, AI writing, taxonomy mapping, and campaign management.

== Description ==

Marketing Suite Connector bridges your WordPress site with the Marketing Suite platform.
Manage content, leverage AI tools, and track campaigns without leaving WordPress.

**Features:**

* **Bidirectional Content Sync** - Pull content from Marketing Suite into WordPress (as posts or pages), or push WordPress posts/pages to Marketing Suite for multi-channel distribution.
* **Bulk Operations** - Select multiple posts for import or push in a single operation.
* **WordPress Site Content Browser** - View and manage posts/pages on your WordPress site through the Marketing Suite proxy, with pagination and search.
* **Taxonomy Sync** - Push WordPress categories and tags to Marketing Suite. Map terms bidirectionally for consistent content organization.
* **Webhook Notifications** - Real-time notifications to Marketing Suite when posts are created, updated, published, trashed, or deleted. Also notifies on category/tag changes.
* **AI Content Generation** - Generate blog posts, articles, how-to guides, case studies, and more using Marketing Suite's AI (supports OpenAI, Anthropic, Gemini, and 6 more providers).
* **AI Refinement** - Improve, expand, shorten, or SEO-optimize your posts directly from the WordPress editor or the Content Sync page.
* **Dashboard Widget** - View content metrics, sync status, recent posts, and active campaigns at a glance.
* **Full Dashboard Page** - Dedicated admin page with metrics, campaigns, sync activity, and quick actions.
* **Post Editor Metabox** - Push individual posts to Marketing Suite and run AI tools from the sidebar.
* **Page Support** - Import and push both posts and pages, not just posts.
* **Featured Image Sync** - Include featured image URLs when pushing posts.
* **Auto-Push** - Optionally auto-sync posts (and pages) to Marketing Suite on publish/update.
* **Sync Mapping** - Track which local posts are linked to which Marketing Suite items, preventing duplicates.

== Installation ==

1. Upload the `marketing-suite-connector` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to **Marketing Suite > Settings**.
4. Enter your Marketing Suite URL (e.g., `https://marketing.example.com`).
5. Paste your API token (found in Marketing Suite under Settings).
6. Click **Save Changes** and then **Test Connection**.

For bidirectional sync (Marketing Suite pushing to WordPress):
1. In WordPress, go to Users > Profile > Application Passwords and create one.
2. In Marketing Suite, go to Social > Add Account > WordPress.
3. Enter your WordPress site URL and `username:application_password` as the token.

== Frequently Asked Questions ==

= Where do I find my API token? =

In your Marketing Suite, go to Settings. Your API token is displayed in your user profile.

= What AI providers are supported? =

The plugin proxies AI requests through your Marketing Suite, which supports OpenAI, Anthropic Claude, Google Gemini, DeepSeek, Groq, Mistral, OpenRouter, xAI, and Together AI.

= Can I use this with the Classic Editor? =

Yes. The metabox and AI tools work with both the Block Editor (Gutenberg) and Classic Editor.

= Can I sync pages, not just posts? =

Yes. Version 2.0 adds full support for WordPress pages. You can import Marketing Suite content as pages, and push pages to Marketing Suite.

= What are webhooks? =

When enabled, the plugin sends real-time notifications to Marketing Suite whenever you create, update, publish, trash, or delete a post or page. This keeps your Marketing Suite in sync without manual intervention.

= How does bulk import/push work? =

On the Content Sync page, use the checkboxes to select multiple items, then click "Import Selected" or "Push Selected" to process them all at once.

== Changelog ==

= 2.0.0 =
* **Bidirectional Sync** - Full two-way content sync with sync mapping to prevent duplicates.
* **Bulk Operations** - Select and import/push multiple posts at once.
* **WordPress Pages Support** - Import and push pages in addition to posts.
* **Taxonomy Sync** - Push categories and tags to Marketing Suite, view mappings.
* **Webhook Notifications** - Real-time event notifications for post lifecycle and taxonomy changes.
* **WordPress Site Content Browser** - View remote WordPress site content through Marketing Suite proxy with pagination.
* **Enhanced Settings** - New options for default post type, taxonomy sync, featured images, webhooks, and auto-push post types.
* **Enhanced AI Tools** - More content types (how-to guide, listicle, case study), inline refinement (improve/expand/SEO), category selection for generated content.
* **Tabbed Content Sync UI** - Organized pull, push, WordPress content, AI, and taxonomy sync into separate tabs.
* **Sync Status Bar** - See how many items are synced at a glance.
* **Improved Dashboard** - Shows sync activity and additional metrics.
* **Plugin version bumped to 2.0.0.**

= 1.1.0 =
* Added secure AI draft creation endpoint for generated content.
* Added optional auto-push setting to sync posts automatically on save/publish.
* Improved import logic to prevent duplicate WordPress posts for the same remote item.
* Improved API error handling and REST argument validation.
* Polished admin UX and fixed AI SEO action behavior in the editor metabox.

= 1.0.0 =
* Initial release.
* Content pull/push sync.
* AI content generation and refinement.
* Dashboard widget and full dashboard page.
* Post editor metabox with AI tools.
