<?php

declare(strict_types=1);

function register_ai_routes(
    Router $router,
    AiService $ai,
    AiLogRepository $aiLogs,
    Analytics $analytics,
    PostRepository $posts,
    CampaignRepository $campaigns
): void {
    $router->post('/api/ai/research', function () use ($ai, $aiLogs, $analytics) {
        $p = request_json();
        $focus = sprintf('audience=%s;goal=%s', $p['audience'] ?? 'local customers', $p['goal'] ?? 'grow inbound leads');
        $result = $ai->marketResearch($p['audience'] ?? 'local customers', $p['goal'] ?? 'grow inbound leads');
        $aiLogs->saveResearch($focus, $result['brief']);
        $analytics->track('ai.research', 'ai', 0, ['provider' => $result['provider']]);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/content', function () use ($ai, $analytics) {
        $p = request_json();
        $result = $ai->generateContent($p);
        $analytics->track('ai.content', 'ai', 0, ['type' => $p['content_type'] ?? 'social_post']);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/ideas', function () use ($ai, $aiLogs, $analytics) {
        $p = request_json();
        $topic = $p['topic'] ?? 'seasonal offer';
        $platform = $p['platform'] ?? 'instagram';
        $result = $ai->contentIdeas($topic, $platform);
        $aiLogs->saveIdea($topic, $platform, $result['ideas']);
        $analytics->track('ai.ideas', 'ai', 0, ['platform' => $platform]);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/calendar', function () use ($ai) {
        $p = request_json();
        json_response(['item' => $ai->scheduleSuggestion($p['objective'] ?? 'increase qualified leads')]);
    });

    $router->post('/api/ai/repurpose', function () use ($ai) {
        $p = request_json();
        if (empty($p['content'])) { json_response(['error' => 'Missing: content'], 422); return; }
        $formats = $p['formats'] ?? ['tweet', 'linkedin_post', 'email', 'instagram_caption'];
        if (method_exists($ai, 'repurposeContent')) {
            json_response(['item' => $ai->repurposeContent($p['content'], $formats)]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/seo-keywords', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'seoKeywordResearch')) {
            json_response(['item' => $ai->seoKeywordResearch($p['topic'] ?? '', $p['niche'] ?? '')]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/blog-post', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'blogPostGenerator')) {
            json_response(['item' => $ai->blogPostGenerator($p['title'] ?? '', $p['keywords'] ?? '', $p['outline'] ?? null)]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/hashtags', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'hashtagResearch')) {
            json_response(['item' => $ai->hashtagResearch($p['topic'] ?? '', $p['platform'] ?? 'instagram')]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/persona', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'audiencePersona')) {
            json_response(['item' => $ai->audiencePersona($p['demographics'] ?? '', $p['behaviors'] ?? '')]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/score', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'contentScore')) {
            json_response(['item' => $ai->contentScore($p['content'] ?? '', $p['platform'] ?? 'instagram')]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/subject-lines', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'emailSubjectLines')) {
            json_response(['item' => $ai->emailSubjectLines($p['topic'] ?? '', (int)($p['count'] ?? 10))]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/ad-variations', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'adVariations')) {
            json_response(['item' => $ai->adVariations($p['base_ad'] ?? '', (int)($p['count'] ?? 5))]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/competitor-analysis', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'competitorAnalysis')) {
            json_response(['item' => $ai->competitorAnalysis($p['name'] ?? '', $p['notes'] ?? '')]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/report', function () use ($ai, $posts, $campaigns, $analytics) {
        $overview = $analytics->overview(7);
        $stats = [
            'posts_created' => (int)($overview['posts']['total'] ?? 0),
            'posts_published' => (int)($overview['posts']['published'] ?? 0),
            'campaigns_active' => count($campaigns->all()),
            'top_platforms' => $overview['by_platform'] ?? [],
            'ai_research_count' => (int)($overview['ai_usage']['research_count'] ?? 0),
            'ai_ideas_count' => (int)($overview['ai_usage']['ideas_count'] ?? 0),
        ];
        if (method_exists($ai, 'weeklyReport')) {
            json_response(['item' => $ai->weeklyReport($stats)]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/video-script', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'videoScript')) {
            json_response(['item' => $ai->videoScript($p['topic'] ?? '', $p['platform'] ?? 'tiktok', (int)($p['duration'] ?? 60))]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/caption-batch', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'socialCaptionBatch')) {
            $platforms = $p['platforms'] ?? ['instagram', 'twitter', 'linkedin'];
            json_response(['item' => $ai->socialCaptionBatch($p['topic'] ?? '', $platforms, (int)($p['count'] ?? 3))]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/seo-audit', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'seoAudit')) {
            json_response(['item' => $ai->seoAudit($p['url'] ?? '', $p['description'] ?? '')]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/social-strategy', function () use ($ai) {
        $p = request_json();
        if (method_exists($ai, 'socialStrategy')) {
            json_response(['item' => $ai->socialStrategy($p['goals'] ?? '', $p['current_state'] ?? '')]);
        } else {
            json_response(['error' => 'Method not available'], 501);
        }
    });

    $router->post('/api/ai/bulk', function () use ($ai) {
        $data = request_json();
        $specs = $data['specs'] ?? [];
        if (empty($specs) || !is_array($specs)) {
            json_response(['error' => 'Missing: specs array'], 422);
            return;
        }
        $results = [];
        foreach (array_slice($specs, 0, 10) as $spec) {
            $results[] = $ai->generateContent($spec);
        }
        json_response(['items' => $results]);
    });
}
