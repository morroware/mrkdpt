<?php

declare(strict_types=1);

function register_dashboard_routes(Router $router, PostRepository $posts, CampaignRepository $campaigns, KpiRepository $kpis, AiLogRepository $aiLogs): void
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
}
