=== Marketing Suite Connector ===
Contributors: morroware
Tags: marketing, content, ai, sync, automation
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later

Connect your WordPress site to Marketing Suite for content sync, AI writing, and campaign management.

== Description ==

Marketing Suite Connector bridges your WordPress site with the Marketing Suite platform.
Manage content, leverage AI tools, and track campaigns without leaving WordPress.

**Features:**

* **Content Sync** - Pull content from Marketing Suite into WordPress drafts, or push WordPress posts to Marketing Suite for multi-channel distribution.
* **AI Content Generation** - Generate blog posts, articles, and landing page copy using Marketing Suite's AI (supports OpenAI, Anthropic, Gemini, and more).
* **AI Refinement** - Improve, expand, shorten, or SEO-optimize your posts directly from the WordPress editor.
* **Dashboard Widget** - View content metrics, recent posts, and active campaigns at a glance.
* **Full Dashboard Page** - Dedicated admin page with metrics, campaigns, and quick actions.
* **Post Editor Metabox** - Push individual posts to Marketing Suite and run AI tools from the sidebar.

== Installation ==

1. Upload the `marketing-suite-connector` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to **Marketing Suite > Settings**.
4. Enter your Marketing Suite URL (e.g., `https://marketing.example.com`).
5. Paste your API token (found in Marketing Suite under Settings).
6. Click **Save Changes** and then **Test Connection**.

== Frequently Asked Questions ==

= Where do I find my API token? =

In your Marketing Suite, go to Settings. Your API token is displayed in your user profile.

= What AI providers are supported? =

The plugin proxies AI requests through your Marketing Suite, which supports OpenAI, Anthropic Claude, Google Gemini, DeepSeek, Groq, Mistral, OpenRouter, xAI, and Together AI.

= Can I use this with the Classic Editor? =

Yes. The metabox and AI tools work with both the Block Editor (Gutenberg) and Classic Editor.

== Changelog ==

= 1.0.0 =
* Initial release.
* Content pull/push sync.
* AI content generation and refinement.
* Dashboard widget and full dashboard page.
* Post editor metabox with AI tools.
