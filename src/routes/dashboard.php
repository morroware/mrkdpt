<?php

declare(strict_types=1);

function register_dashboard_routes(Router $router, PostRepository $posts, CampaignRepository $campaigns, KpiRepository $kpis, AiLogRepository $aiLogs, PDO $pdo): void
{
    $router->get('/api/dashboard', function () use ($posts, $campaigns, $kpis, $aiLogs) {
        json_response([
            'metrics' => $posts->metrics(),
            'campaigns' => count($campaigns->all()),
            'kpis' => $kpis->summary(),
            'recent_posts' => array_slice($posts->all(), 0, 8),
            'recent_ideas' => array_slice($aiLogs->ideas(), 0, 5),
        ]);
    });

    $router->get('/api/dashboard/actions', function () use ($posts, $campaigns, $pdo) {

        // Draft posts count
        $draftCount = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'draft'")->fetchColumn();

        // Upcoming scheduled posts (next 48 hours)
        $now = gmdate(DATE_ATOM);
        $in48h = gmdate(DATE_ATOM, strtotime('+48 hours'));
        $stmtScheduled = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE status = 'scheduled' AND scheduled_for >= :now AND scheduled_for <= :in48h");
        $stmtScheduled->execute([':now' => $now, ':in48h' => $in48h]);
        $scheduledCount = (int)$stmtScheduled->fetchColumn();

        // Active campaigns
        $allCampaigns = $campaigns->all();
        $activeCampaigns = array_filter($allCampaigns, function ($c) use ($now) {
            $started = empty($c['start_date']) || $c['start_date'] <= $now;
            $notEnded = empty($c['end_date']) || $c['end_date'] >= $now;
            return $started && $notEnded;
        });
        $activeCampaignCount = count($activeCampaigns);

        // Recent subscriber count (last 7 days)
        $sevenDaysAgo = gmdate(DATE_ATOM, strtotime('-7 days'));
        $stmtSubs = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE created_at >= :since");
        $stmtSubs->execute([':since' => $sevenDaysAgo]);
        $recentSubscribers = (int)$stmtSubs->fetchColumn();

        // Recent email campaigns (last 30 days)
        $thirtyDaysAgo = gmdate(DATE_ATOM, strtotime('-30 days'));
        $stmtEmail = $pdo->prepare("SELECT COUNT(*) FROM email_campaigns WHERE created_at >= :since");
        $stmtEmail->execute([':since' => $thirtyDaysAgo]);
        $recentEmailCampaigns = (int)$stmtEmail->fetchColumn();

        // Review stats
        $reviewTotal = (int)$pdo->query('SELECT COUNT(*) FROM reviews')->fetchColumn();
        $reviewAvg = (float)$pdo->query('SELECT COALESCE(AVG(rating), 0) FROM reviews')->fetchColumn();
        $pendingReviews = (int)$pdo->query("SELECT COUNT(*) FROM reviews WHERE response_status = 'pending'")->fetchColumn();

        json_response([
            'draft_posts' => $draftCount,
            'scheduled_posts_48h' => $scheduledCount,
            'active_campaigns' => $activeCampaignCount,
            'recent_subscribers' => $recentSubscribers,
            'recent_email_campaigns' => $recentEmailCampaigns,
            'review_stats' => [
                'total' => $reviewTotal,
                'avg_rating' => round($reviewAvg, 2),
                'pending' => $pendingReviews,
            ],
        ]);
    });
}
