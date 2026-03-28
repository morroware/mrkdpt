<?php

declare(strict_types=1);

final class Database
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }

        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA foreign_keys=ON');
        $this->pdo->exec('PRAGMA busy_timeout=5000');
        $this->migrate();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function migrate(): void
    {
        /* ---- original tables ---- */

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS campaigns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            channel TEXT NOT NULL,
            objective TEXT NOT NULL,
            budget REAL NOT NULL DEFAULT 0,
            notes TEXT DEFAULT "",
            start_date TEXT,
            end_date TEXT,
            created_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            campaign_id INTEGER,
            platform TEXT NOT NULL,
            content_type TEXT DEFAULT "social_post",
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            cta TEXT DEFAULT "",
            tags TEXT DEFAULT "",
            scheduled_for TEXT,
            status TEXT NOT NULL DEFAULT "draft",
            ai_score INTEGER DEFAULT 0,
            created_at TEXT NOT NULL,
            FOREIGN KEY(campaign_id) REFERENCES campaigns(id)
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS competitors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            channel TEXT NOT NULL,
            positioning TEXT DEFAULT "",
            recent_activity TEXT DEFAULT "",
            opportunity TEXT DEFAULT "",
            created_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS kpi_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            channel TEXT NOT NULL,
            metric_name TEXT NOT NULL,
            metric_value REAL NOT NULL,
            logged_on TEXT NOT NULL,
            note TEXT DEFAULT ""
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS research_briefs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            focus TEXT NOT NULL,
            output TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS content_ideas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            topic TEXT NOT NULL,
            platform TEXT NOT NULL,
            output TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');

        /* ---- Phase 1: auth & rate limiting ---- */

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT "admin",
            api_token TEXT,
            created_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            identifier TEXT NOT NULL,
            attempted_at INTEGER NOT NULL
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_rate_limits_id_at ON rate_limits(identifier, attempted_at)');

        /* ---- Phase 2: templates, brand profiles, media ---- */

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            type TEXT DEFAULT "social_post",
            platform TEXT DEFAULT "",
            structure TEXT DEFAULT "",
            variables TEXT DEFAULT "",
            created_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS brand_profiles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            voice_tone TEXT DEFAULT "",
            vocabulary TEXT DEFAULT "",
            avoid_words TEXT DEFAULT "",
            example_content TEXT DEFAULT "",
            target_audience TEXT DEFAULT "",
            is_active INTEGER DEFAULT 0,
            created_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS media (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            filename TEXT NOT NULL,
            original_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            size_bytes INTEGER DEFAULT 0,
            alt_text TEXT DEFAULT "",
            tags TEXT DEFAULT "",
            created_at TEXT NOT NULL
        )');

        /* ---- Phase 3: social accounts, publishing, scheduling, email ---- */

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS social_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            platform TEXT NOT NULL,
            account_name TEXT NOT NULL,
            access_token TEXT DEFAULT "",
            refresh_token TEXT DEFAULT "",
            token_expires TEXT,
            meta_json TEXT DEFAULT "{}",
            created_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS publish_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            social_account_id INTEGER,
            external_id TEXT,
            status TEXT NOT NULL DEFAULT "pending",
            error_message TEXT DEFAULT "",
            published_at TEXT NOT NULL,
            FOREIGN KEY(post_id) REFERENCES posts(id),
            FOREIGN KEY(social_account_id) REFERENCES social_accounts(id)
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS cron_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task TEXT NOT NULL,
            status TEXT NOT NULL,
            message TEXT DEFAULT "",
            ran_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS email_lists (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT DEFAULT "",
            created_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS subscribers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            name TEXT DEFAULT "",
            list_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT "active",
            subscribed_at TEXT NOT NULL,
            unsubscribed_at TEXT,
            FOREIGN KEY(list_id) REFERENCES email_lists(id)
        )');
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_subscribers_email_list ON subscribers(email, list_id)');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS email_campaigns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            subject TEXT NOT NULL,
            body_html TEXT DEFAULT "",
            body_text TEXT DEFAULT "",
            list_id INTEGER,
            status TEXT NOT NULL DEFAULT "draft",
            sent_count INTEGER DEFAULT 0,
            sent_at TEXT,
            created_at TEXT NOT NULL,
            FOREIGN KEY(list_id) REFERENCES email_lists(id)
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS email_tracking (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            campaign_id INTEGER NOT NULL,
            subscriber_id INTEGER NOT NULL,
            event_type TEXT NOT NULL,
            url TEXT DEFAULT "",
            tracked_at TEXT NOT NULL,
            FOREIGN KEY(campaign_id) REFERENCES email_campaigns(id),
            FOREIGN KEY(subscriber_id) REFERENCES subscribers(id)
        )');

        /* ---- Phase 4: analytics, RSS, webhooks ---- */

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS analytics_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_type TEXT NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id INTEGER DEFAULT 0,
            data_json TEXT DEFAULT "{}",
            created_at TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_analytics_type_date ON analytics_events(event_type, created_at)');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS rss_feeds (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            url TEXT NOT NULL,
            name TEXT DEFAULT "",
            is_active INTEGER DEFAULT 1,
            last_fetched TEXT,
            created_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS rss_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            feed_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            url TEXT DEFAULT "",
            summary TEXT DEFAULT "",
            published_at TEXT,
            curated INTEGER DEFAULT 0,
            created_at TEXT NOT NULL,
            FOREIGN KEY(feed_id) REFERENCES rss_feeds(id)
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS webhooks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event TEXT NOT NULL,
            url TEXT NOT NULL,
            secret TEXT NOT NULL,
            active INTEGER DEFAULT 1,
            created_at TEXT NOT NULL
        )');

        /* ---- Phase 5: UTM links, link shortener, landing pages, contacts, forms, A/B tests, funnels, automations ---- */

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS utm_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            campaign_name TEXT NOT NULL,
            base_url TEXT NOT NULL,
            utm_source TEXT NOT NULL,
            utm_medium TEXT NOT NULL,
            utm_campaign TEXT NOT NULL,
            utm_term TEXT DEFAULT "",
            utm_content TEXT DEFAULT "",
            full_url TEXT NOT NULL,
            short_code TEXT,
            clicks INTEGER DEFAULT 0,
            created_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS short_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            destination_url TEXT NOT NULL,
            title TEXT DEFAULT "",
            clicks INTEGER DEFAULT 0,
            utm_link_id INTEGER,
            created_at TEXT NOT NULL,
            FOREIGN KEY(utm_link_id) REFERENCES utm_links(id)
        )');
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_short_links_code ON short_links(code)');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS link_clicks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            link_type TEXT NOT NULL,
            link_id INTEGER NOT NULL,
            ip_hash TEXT DEFAULT "",
            user_agent TEXT DEFAULT "",
            referer TEXT DEFAULT "",
            country TEXT DEFAULT "",
            clicked_at TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_link_clicks_type_id ON link_clicks(link_type, link_id)');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS landing_pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            template TEXT DEFAULT "blank",
            status TEXT NOT NULL DEFAULT "draft",
            meta_title TEXT DEFAULT "",
            meta_description TEXT DEFAULT "",
            hero_heading TEXT DEFAULT "",
            hero_subheading TEXT DEFAULT "",
            hero_cta_text TEXT DEFAULT "",
            hero_cta_url TEXT DEFAULT "",
            body_html TEXT DEFAULT "",
            custom_css TEXT DEFAULT "",
            form_id INTEGER,
            campaign_id INTEGER,
            views INTEGER DEFAULT 0,
            conversions INTEGER DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT,
            FOREIGN KEY(form_id) REFERENCES forms(id),
            FOREIGN KEY(campaign_id) REFERENCES campaigns(id)
        )');
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_landing_pages_slug ON landing_pages(slug)');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            first_name TEXT DEFAULT "",
            last_name TEXT DEFAULT "",
            company TEXT DEFAULT "",
            phone TEXT DEFAULT "",
            source TEXT DEFAULT "manual",
            source_detail TEXT DEFAULT "",
            stage TEXT DEFAULT "lead",
            score INTEGER DEFAULT 0,
            tags TEXT DEFAULT "",
            notes TEXT DEFAULT "",
            custom_fields TEXT DEFAULT "{}",
            last_activity TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT
        )');
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_contacts_email ON contacts(email)');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS contact_activities (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            contact_id INTEGER NOT NULL,
            activity_type TEXT NOT NULL,
            description TEXT DEFAULT "",
            data_json TEXT DEFAULT "{}",
            created_at TEXT NOT NULL,
            FOREIGN KEY(contact_id) REFERENCES contacts(id)
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS forms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            fields TEXT NOT NULL DEFAULT "[]",
            submit_label TEXT DEFAULT "Submit",
            success_message TEXT DEFAULT "Thank you!",
            redirect_url TEXT DEFAULT "",
            notification_email TEXT DEFAULT "",
            list_id INTEGER,
            tag_on_submit TEXT DEFAULT "",
            status TEXT NOT NULL DEFAULT "active",
            submissions INTEGER DEFAULT 0,
            created_at TEXT NOT NULL,
            FOREIGN KEY(list_id) REFERENCES email_lists(id)
        )');
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_forms_slug ON forms(slug)');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS form_submissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            form_id INTEGER NOT NULL,
            contact_id INTEGER,
            data_json TEXT NOT NULL DEFAULT "{}",
            ip_hash TEXT DEFAULT "",
            page_url TEXT DEFAULT "",
            submitted_at TEXT NOT NULL,
            FOREIGN KEY(form_id) REFERENCES forms(id),
            FOREIGN KEY(contact_id) REFERENCES contacts(id)
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ab_tests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            test_type TEXT NOT NULL DEFAULT "content",
            status TEXT NOT NULL DEFAULT "running",
            metric TEXT DEFAULT "clicks",
            notes TEXT DEFAULT "",
            started_at TEXT NOT NULL,
            ended_at TEXT,
            winner_variant TEXT,
            created_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ab_variants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            test_id INTEGER NOT NULL,
            variant_name TEXT NOT NULL,
            content TEXT DEFAULT "",
            impressions INTEGER DEFAULT 0,
            conversions INTEGER DEFAULT 0,
            created_at TEXT NOT NULL,
            FOREIGN KEY(test_id) REFERENCES ab_tests(id)
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS funnels (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT DEFAULT "",
            campaign_id INTEGER,
            created_at TEXT NOT NULL,
            FOREIGN KEY(campaign_id) REFERENCES campaigns(id)
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS funnel_stages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            funnel_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            stage_order INTEGER NOT NULL DEFAULT 0,
            target_count INTEGER DEFAULT 0,
            actual_count INTEGER DEFAULT 0,
            conversion_rate REAL DEFAULT 0,
            color TEXT DEFAULT "#4c8dff",
            created_at TEXT NOT NULL,
            FOREIGN KEY(funnel_id) REFERENCES funnels(id)
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS automation_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            trigger_event TEXT NOT NULL,
            conditions TEXT DEFAULT "{}",
            action_type TEXT NOT NULL,
            action_config TEXT DEFAULT "{}",
            is_active INTEGER DEFAULT 1,
            run_count INTEGER DEFAULT 0,
            last_run TEXT,
            created_at TEXT NOT NULL
        )');

        /* ---- Phase 6: audience segments, social queue, email templates, campaign ROI ---- */

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS audience_segments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT DEFAULT "",
            criteria TEXT NOT NULL DEFAULT "{}",
            contact_count INTEGER DEFAULT 0,
            is_dynamic INTEGER DEFAULT 1,
            last_computed TEXT,
            created_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS social_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            social_account_id INTEGER NOT NULL,
            priority INTEGER DEFAULT 0,
            optimal_time TEXT,
            status TEXT NOT NULL DEFAULT "queued",
            queued_at TEXT NOT NULL,
            published_at TEXT,
            error_message TEXT DEFAULT "",
            FOREIGN KEY(post_id) REFERENCES posts(id),
            FOREIGN KEY(social_account_id) REFERENCES social_accounts(id)
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS email_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            category TEXT DEFAULT "general",
            subject_template TEXT DEFAULT "",
            html_template TEXT NOT NULL,
            text_template TEXT DEFAULT "",
            thumbnail_color TEXT DEFAULT "#4c8dff",
            variables TEXT DEFAULT "[]",
            is_builtin INTEGER DEFAULT 0,
            created_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS campaign_metrics (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            campaign_id INTEGER NOT NULL,
            metric_date TEXT NOT NULL,
            spend REAL DEFAULT 0,
            revenue REAL DEFAULT 0,
            impressions INTEGER DEFAULT 0,
            clicks INTEGER DEFAULT 0,
            conversions INTEGER DEFAULT 0,
            notes TEXT DEFAULT "",
            created_at TEXT NOT NULL,
            FOREIGN KEY(campaign_id) REFERENCES campaigns(id)
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS content_notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            author TEXT DEFAULT "",
            note TEXT NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY(post_id) REFERENCES posts(id)
        )');

        /* ---- Phase 7: AI chat conversations ---- */

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ai_chat_conversations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT DEFAULT "New conversation",
            provider TEXT DEFAULT "",
            model TEXT DEFAULT "",
            message_count INTEGER DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ai_chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            content TEXT NOT NULL,
            provider TEXT DEFAULT "",
            created_at TEXT NOT NULL,
            FOREIGN KEY(conversation_id) REFERENCES ai_chat_conversations(id)
        )');

        /* ---- Phase 8: AI Autopilot & Onboarding ---- */

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS business_profile (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            business_description TEXT DEFAULT "",
            target_audience TEXT DEFAULT "",
            products_services TEXT DEFAULT "",
            competitors TEXT DEFAULT "",
            marketing_goals TEXT DEFAULT "",
            active_platforms TEXT DEFAULT "",
            content_examples TEXT DEFAULT "",
            budget_range TEXT DEFAULT "",
            website_url TEXT DEFAULT "",
            unique_selling_points TEXT DEFAULT "",
            onboarding_completed INTEGER DEFAULT 0,
            autopilot_run INTEGER DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ai_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_type TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "pending",
            step_current INTEGER DEFAULT 0,
            step_total INTEGER DEFAULT 0,
            steps_config TEXT DEFAULT "[]",
            results TEXT DEFAULT "{}",
            error TEXT DEFAULT "",
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ai_generated_assets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER,
            asset_type TEXT NOT NULL,
            title TEXT DEFAULT "",
            content TEXT NOT NULL,
            metadata TEXT DEFAULT "{}",
            status TEXT DEFAULT "pending_review",
            created_at TEXT NOT NULL,
            FOREIGN KEY(task_id) REFERENCES ai_tasks(id)
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ai_shared_memory (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            memory_key TEXT DEFAULT "",
            content TEXT NOT NULL,
            source TEXT DEFAULT "manual",
            source_ref TEXT DEFAULT "",
            tags TEXT DEFAULT "",
            metadata_json TEXT DEFAULT "{}",
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_shared_memory_updated ON ai_shared_memory(updated_at DESC)');

        /* ---- Phase 9.5: AI Brain — Activity Log, Learnings, Performance Feedback ---- */

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ai_activity_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tool_name TEXT NOT NULL,
            tool_category TEXT DEFAULT "general",
            input_summary TEXT DEFAULT "",
            output_summary TEXT DEFAULT "",
            provider TEXT DEFAULT "",
            model TEXT DEFAULT "",
            tokens_estimated INTEGER DEFAULT 0,
            duration_ms INTEGER DEFAULT 0,
            quality_score INTEGER DEFAULT 0,
            metadata_json TEXT DEFAULT "{}",
            created_at TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_activity_log_tool ON ai_activity_log(tool_name, created_at DESC)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_activity_log_created ON ai_activity_log(created_at DESC)');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ai_learnings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category TEXT NOT NULL DEFAULT "general",
            insight TEXT NOT NULL,
            confidence REAL DEFAULT 0.7,
            source_tool TEXT DEFAULT "",
            source_activity_id INTEGER,
            times_reinforced INTEGER DEFAULT 1,
            last_used_at TEXT,
            expires_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY(source_activity_id) REFERENCES ai_activity_log(id)
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_learnings_category ON ai_learnings(category, confidence DESC)');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ai_performance_feedback (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            entity_type TEXT NOT NULL,
            entity_id INTEGER NOT NULL,
            activity_id INTEGER,
            metric_name TEXT NOT NULL,
            metric_value REAL DEFAULT 0,
            feedback_note TEXT DEFAULT "",
            created_at TEXT NOT NULL,
            FOREIGN KEY(activity_id) REFERENCES ai_activity_log(id)
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_perf_entity ON ai_performance_feedback(entity_type, entity_id)');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ai_pipelines (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT DEFAULT "",
            steps_json TEXT NOT NULL DEFAULT "[]",
            status TEXT DEFAULT "draft",
            last_run_at TEXT,
            run_count INTEGER DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ai_pipeline_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pipeline_id INTEGER NOT NULL,
            status TEXT DEFAULT "running",
            steps_completed INTEGER DEFAULT 0,
            steps_total INTEGER DEFAULT 0,
            results_json TEXT DEFAULT "{}",
            error TEXT DEFAULT "",
            started_at TEXT NOT NULL,
            completed_at TEXT,
            FOREIGN KEY(pipeline_id) REFERENCES ai_pipelines(id)
        )');

        /* ---- Performance indexes ---- */
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_posts_scheduled_for ON posts(scheduled_for)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_posts_status ON posts(status)');

        /* ---- Phase 9: App Settings (DB-backed overrides for .env) ---- */
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS app_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL DEFAULT "",
            updated_at TEXT NOT NULL
        )');

        /* ---- safe column additions for upgrades ---- */

        $this->applySafeAlter('campaigns', 'start_date', 'TEXT');
        $this->applySafeAlter('campaigns', 'end_date', 'TEXT');
        $this->applySafeAlter('posts', 'content_type', 'TEXT DEFAULT "social_post"');
        $this->applySafeAlter('posts', 'cta', 'TEXT DEFAULT ""');
        $this->applySafeAlter('posts', 'tags', 'TEXT DEFAULT ""');
        $this->applySafeAlter('posts', 'published_at', 'TEXT');
        $this->applySafeAlter('posts', 'publish_error', 'TEXT');
        $this->applySafeAlter('posts', 'retry_count', 'INTEGER DEFAULT 0');
        $this->applySafeAlter('posts', 'recurrence', 'TEXT DEFAULT "none"');
        $this->applySafeAlter('posts', 'recurring_parent_id', 'INTEGER');
        $this->applySafeAlter('posts', 'is_evergreen', 'INTEGER DEFAULT 0');
        $this->applySafeAlter('posts', 'media_id', 'INTEGER');
        $this->applySafeAlter('posts', 'approval_status', 'TEXT DEFAULT "none"');
        $this->applySafeAlter('posts', 'approved_by', 'TEXT');
        $this->applySafeAlter('posts', 'approved_at', 'TEXT');
        $this->applySafeAlter('posts', 'review_notes', 'TEXT DEFAULT ""');

        $this->applySafeAlter('campaigns', 'status', 'TEXT DEFAULT "active"');
        $this->applySafeAlter('campaigns', 'spend_to_date', 'REAL DEFAULT 0');
        $this->applySafeAlter('campaigns', 'revenue', 'REAL DEFAULT 0');
        $this->applySafeAlter('campaigns', 'target_audience', 'TEXT DEFAULT ""');
        $this->applySafeAlter('campaigns', 'kpi_target', 'TEXT DEFAULT ""');
        $this->applySafeAlter('users', 'failed_login_attempts', 'INTEGER DEFAULT 0');
        $this->applySafeAlter('users', 'last_failed_login_at', 'INTEGER');
        $this->applySafeAlter('users', 'locked_until', 'INTEGER');

        /* ---- Phase 10: CRM Deals & Tasks ---- */

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS deals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            contact_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            value REAL DEFAULT 0,
            currency TEXT DEFAULT "USD",
            stage TEXT DEFAULT "lead",
            probability INTEGER DEFAULT 0,
            expected_close TEXT,
            description TEXT DEFAULT "",
            status TEXT DEFAULT "open",
            won_at TEXT,
            lost_at TEXT,
            lost_reason TEXT DEFAULT "",
            created_at TEXT NOT NULL,
            updated_at TEXT,
            FOREIGN KEY(contact_id) REFERENCES contacts(id)
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_deals_contact ON deals(contact_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_deals_stage ON deals(stage)');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS contact_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            contact_id INTEGER,
            deal_id INTEGER,
            title TEXT NOT NULL,
            description TEXT DEFAULT "",
            due_date TEXT,
            priority TEXT DEFAULT "medium",
            status TEXT DEFAULT "pending",
            completed_at TEXT,
            created_at TEXT NOT NULL,
            FOREIGN KEY(contact_id) REFERENCES contacts(id),
            FOREIGN KEY(deal_id) REFERENCES deals(id)
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_contact_tasks_contact ON contact_tasks(contact_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_contact_tasks_due ON contact_tasks(due_date)');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS contact_notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            contact_id INTEGER NOT NULL,
            content TEXT NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY(contact_id) REFERENCES contacts(id)
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_contact_notes_contact ON contact_notes(contact_id)');

        /* ---- Landing page sections (JSON-based section builder) ---- */
        $this->applySafeAlter('landing_pages', 'sections_json', 'TEXT DEFAULT "[]"');
        $this->applySafeAlter('landing_pages', 'og_image', 'TEXT DEFAULT ""');

        // AI Brain enhancements
        $this->applySafeAlter('ai_shared_memory', 'relevance_score', 'REAL DEFAULT 1.0');
        $this->applySafeAlter('ai_shared_memory', 'access_count', 'INTEGER DEFAULT 0');
        $this->applySafeAlter('ai_shared_memory', 'expires_at', 'TEXT');
        $this->applySafeAlter('ai_shared_memory', 'category', 'TEXT DEFAULT "general"');

        /* ---- Phase 11: AI Agent System & Model Routing ---- */

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ai_agent_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            goal TEXT NOT NULL,
            context TEXT DEFAULT "",
            status TEXT DEFAULT "planned",
            plan_json TEXT DEFAULT "[]",
            results_json TEXT DEFAULT "[]",
            model_config_json TEXT DEFAULT "{}",
            auto_approve INTEGER DEFAULT 0,
            steps_completed INTEGER DEFAULT 0,
            steps_total INTEGER DEFAULT 0,
            current_step_output TEXT DEFAULT "",
            completed_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_agent_tasks_status ON ai_agent_tasks(status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_agent_tasks_created ON ai_agent_tasks(created_at DESC)');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ai_model_routing (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_type TEXT NOT NULL UNIQUE,
            provider TEXT NOT NULL DEFAULT "",
            model TEXT NOT NULL DEFAULT "",
            label TEXT DEFAULT "",
            updated_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ai_search_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            query TEXT NOT NULL,
            sources TEXT DEFAULT "internal",
            url TEXT DEFAULT "",
            results_count INTEGER DEFAULT 0,
            summary TEXT DEFAULT "",
            created_at TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_search_history_created ON ai_search_history(created_at DESC)');

        /* ---- Phase 2.5: Review & Reputation Manager ---- */

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS reviews (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            platform TEXT NOT NULL DEFAULT "manual",
            reviewer_name TEXT NOT NULL,
            rating INTEGER NOT NULL DEFAULT 5,
            review_text TEXT DEFAULT "",
            response_text TEXT DEFAULT "",
            response_status TEXT NOT NULL DEFAULT "pending",
            created_at TEXT NOT NULL,
            responded_at TEXT
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_reviews_platform ON reviews(platform)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_reviews_status ON reviews(response_status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_reviews_created ON reviews(created_at DESC)');
    }

    private function applySafeAlter(string $table, string $column, string $type): void
    {
        // Validate identifiers: only allow alphanumeric and underscores
        if (!preg_match('/^\w+$/', $table) || !preg_match('/^\w+$/', $column)) {
            throw new \InvalidArgumentException('Invalid table or column name');
        }
        $stmt = $this->pdo->query(sprintf('PRAGMA table_info("%s")', $table));
        $columns = array_map(static fn(array $row) => $row['name'], $stmt->fetchAll(PDO::FETCH_ASSOC));

        if (!in_array($column, $columns, true)) {
            $this->pdo->exec(sprintf('ALTER TABLE "%s" ADD COLUMN "%s" %s', $table, $column, $type));
        }
    }
}
