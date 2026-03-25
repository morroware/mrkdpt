<?php

declare(strict_types=1);

final class Analytics
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Log an analytics event.
     */
    public function track(string $eventType, string $entityType, int $entityId, array $data = []): void
    {
        $this->pdo->prepare('INSERT INTO analytics_events(event_type, entity_type, entity_id, data_json, created_at) VALUES(:et,:ent,:eid,:d,:c)')->execute([
            ':et' => $eventType,
            ':ent' => $entityType,
            ':eid' => $entityId,
            ':d' => json_encode($data),
            ':c' => gmdate(DATE_ATOM),
        ]);
    }

    /**
     * Dashboard overview with key metrics.
     */
    public function overview(int $days = 30): array
    {
        $since = gmdate('Y-m-d', strtotime("-{$days} days"));

        // Posts metrics
        $posts = $this->pdo->prepare("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status='published' THEN 1 ELSE 0 END) as published,
                SUM(CASE WHEN status='scheduled' THEN 1 ELSE 0 END) as scheduled,
                SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) as drafts,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
                AVG(ai_score) as avg_score
            FROM posts WHERE created_at >= :since
        ");
        $posts->execute([':since' => $since]);
        $postMetrics = $posts->fetch(PDO::FETCH_ASSOC);

        // Posts by platform
        $byPlatform = $this->pdo->prepare("
            SELECT platform, COUNT(*) as count,
                   SUM(CASE WHEN status='published' THEN 1 ELSE 0 END) as published
            FROM posts WHERE created_at >= :since
            GROUP BY platform ORDER BY count DESC
        ");
        $byPlatform->execute([':since' => $since]);
        $platformBreakdown = $byPlatform->fetchAll(PDO::FETCH_ASSOC);

        // Posts by content type
        $byType = $this->pdo->prepare("
            SELECT content_type, COUNT(*) as count
            FROM posts WHERE created_at >= :since
            GROUP BY content_type ORDER BY count DESC
        ");
        $byType->execute([':since' => $since]);
        $typeBreakdown = $byType->fetchAll(PDO::FETCH_ASSOC);

        // Posts per week (for chart)
        $weekly = $this->pdo->prepare("
            SELECT strftime('%Y-W%W', created_at) as week, COUNT(*) as count
            FROM posts WHERE created_at >= :since
            GROUP BY week ORDER BY week
        ");
        $weekly->execute([':since' => $since]);
        $weeklyData = $weekly->fetchAll(PDO::FETCH_ASSOC);

        // Campaign stats
        $campaigns = $this->pdo->prepare("
            SELECT COUNT(*) as total, SUM(budget) as total_budget
            FROM campaigns WHERE created_at >= :since
        ");
        $campaigns->execute([':since' => $since]);
        $campaignMetrics = $campaigns->fetch(PDO::FETCH_ASSOC);

        // AI usage
        $aiUsage = $this->pdo->prepare("
            SELECT
                (SELECT COUNT(*) FROM research_briefs WHERE created_at >= :s1) as research_count,
                (SELECT COUNT(*) FROM content_ideas WHERE created_at >= :s2) as ideas_count
        ");
        $aiUsage->execute([':s1' => $since, ':s2' => $since]);
        $aiMetrics = $aiUsage->fetch(PDO::FETCH_ASSOC);

        // Email stats
        $emailStats = $this->getEmailStats($since);

        // Social publishing stats
        $publishStats = $this->getPublishStats($since);

        return [
            'period_days' => $days,
            'posts' => $postMetrics,
            'by_platform' => $platformBreakdown,
            'by_content_type' => $typeBreakdown,
            'weekly_posts' => $weeklyData,
            'campaigns' => $campaignMetrics,
            'ai_usage' => $aiMetrics,
            'email' => $emailStats,
            'social_publishing' => $publishStats,
        ];
    }

    /**
     * Content performance data.
     */
    public function contentPerformance(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare("
            SELECT p.id, p.title, p.platform, p.content_type, p.status, p.ai_score,
                   p.created_at, p.published_at,
                   (SELECT COUNT(*) FROM publish_log pl WHERE pl.post_id = p.id AND pl.status = 'published') as publish_count,
                   c.name as campaign_name
            FROM posts p
            LEFT JOIN campaigns c ON c.id = p.campaign_id
            ORDER BY p.created_at DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Generate SVG bar chart data for frontend rendering.
     */
    public function chartData(string $metric, int $days = 30): array
    {
        $since = gmdate('Y-m-d', strtotime("-{$days} days"));

        return match ($metric) {
            'posts_by_day' => $this->postsPerDay($since),
            'posts_by_platform' => $this->postsByPlatform($since),
            'kpi_trend' => $this->kpiTrend($since),
            'publish_success' => $this->publishSuccess($since),
            default => [],
        };
    }

    /**
     * Export data as CSV rows.
     */
    public function export(string $type): array
    {
        return match ($type) {
            'posts' => $this->exportTable('SELECT p.*, c.name as campaign_name FROM posts p LEFT JOIN campaigns c ON c.id = p.campaign_id ORDER BY p.id DESC'),
            'campaigns' => $this->exportTable('SELECT * FROM campaigns ORDER BY id DESC'),
            'kpis' => $this->exportTable('SELECT * FROM kpi_logs ORDER BY logged_on DESC'),
            'competitors' => $this->exportTable('SELECT * FROM competitors ORDER BY id DESC'),
            'subscribers' => $this->exportTable('SELECT s.*, el.name as list_name FROM subscribers s LEFT JOIN email_lists el ON el.id = s.list_id ORDER BY s.id DESC'),
            'publish_log' => $this->exportTable('SELECT * FROM publish_log ORDER BY id DESC'),
            default => [],
        };
    }

    /**
     * Export as CSV string.
     */
    public function exportCsv(string $type): string
    {
        $rows = $this->export($type);
        if (empty($rows)) {
            return '';
        }

        $output = fopen('php://temp', 'r+');
        fputcsv($output, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    /* ---- private helpers ---- */

    private function getEmailStats(string $since): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    (SELECT COUNT(*) FROM email_campaigns WHERE created_at >= :s1) as campaigns,
                    (SELECT COUNT(*) FROM email_campaigns WHERE status = 'sent' AND sent_at >= :s2) as sent,
                    (SELECT COUNT(*) FROM subscribers WHERE status = 'active') as active_subscribers,
                    (SELECT COUNT(DISTINCT subscriber_id) FROM email_tracking WHERE event_type = 'open' AND tracked_at >= :s3) as unique_opens,
                    (SELECT COUNT(DISTINCT subscriber_id) FROM email_tracking WHERE event_type = 'click' AND tracked_at >= :s4) as unique_clicks
            ");
            $stmt->execute([':s1' => $since, ':s2' => $since, ':s3' => $since, ':s4' => $since]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function getPublishStats(string $since): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT platform,
                       COUNT(*) as total,
                       SUM(CASE WHEN status='published' THEN 1 ELSE 0 END) as success,
                       SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as errors
                FROM publish_log WHERE published_at >= :since
                GROUP BY platform
            ");
            $stmt->execute([':since' => $since]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
    }

    private function postsPerDay(string $since): array
    {
        $stmt = $this->pdo->prepare("
            SELECT DATE(created_at) as date, COUNT(*) as value
            FROM posts WHERE created_at >= :since
            GROUP BY date ORDER BY date
        ");
        $stmt->execute([':since' => $since]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function postsByPlatform(string $since): array
    {
        $stmt = $this->pdo->prepare("
            SELECT platform as label, COUNT(*) as value
            FROM posts WHERE created_at >= :since
            GROUP BY platform ORDER BY value DESC
        ");
        $stmt->execute([':since' => $since]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function kpiTrend(string $since): array
    {
        $stmt = $this->pdo->prepare("
            SELECT logged_on as date, channel || ': ' || metric_name as label, metric_value as value
            FROM kpi_logs WHERE logged_on >= :since
            ORDER BY logged_on
        ");
        $stmt->execute([':since' => $since]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function publishSuccess(string $since): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT DATE(published_at) as date,
                       SUM(CASE WHEN status='published' THEN 1 ELSE 0 END) as success,
                       SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as errors
                FROM publish_log WHERE published_at >= :since
                GROUP BY date ORDER BY date
            ");
            $stmt->execute([':since' => $since]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
    }

    private function exportTable(string $sql): array
    {
        try {
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
    }
}
