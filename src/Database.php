<?php

declare(strict_types=1);

final class Database
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA foreign_keys=ON');
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
    }

    private function applySafeAlter(string $table, string $column, string $type): void
    {
        $stmt = $this->pdo->query(sprintf('PRAGMA table_info(%s)', $table));
        $columns = array_map(static fn(array $row) => $row['name'], $stmt->fetchAll(PDO::FETCH_ASSOC));

        if (!in_array($column, $columns, true)) {
            $this->pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $type));
        }
    }
}
