<?php

declare(strict_types=1);

function register_ai_routes(
    Router $router,
    AiService $ai,
    AiContentTools $contentTools,
    AiAnalysisTools $analysisTools,
    AiStrategyTools $strategyTools,
    AiChatService $chatService,
    AiLogRepository $aiLogs,
    Analytics $analytics,
    PostRepository $posts,
    CampaignRepository $campaigns,
    PDO $pdo
): void {

    /* ================================================================== */
    /*  CONTENT CREATION                                                  */
    /* ================================================================== */

    $router->post('/api/ai/content', function () use ($contentTools, $analytics) {
        $p = request_json();
        $result = $contentTools->generateContent($p);
        $analytics->track('ai.content', 'ai', 0, ['type' => $p['content_type'] ?? 'social_post']);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/blog-post', function () use ($contentTools) {
        $p = request_json();
        json_response(['item' => $contentTools->blogPostGenerator($p['title'] ?? '', $p['keywords'] ?? '', $p['outline'] ?? null, $p['provider'] ?? null, $p['model'] ?? null)]);
    });

    $router->post('/api/ai/video-script', function () use ($contentTools) {
        $p = request_json();
        json_response(['item' => $contentTools->videoScript($p['topic'] ?? '', $p['platform'] ?? 'tiktok', (int)($p['duration'] ?? 60))]);
    });

    $router->post('/api/ai/caption-batch', function () use ($contentTools) {
        $p = request_json();
        $platforms = $p['platforms'] ?? ['instagram', 'twitter', 'linkedin'];
        json_response(['item' => $contentTools->socialCaptionBatch($p['topic'] ?? '', $platforms, (int)($p['count'] ?? 3))]);
    });

    $router->post('/api/ai/repurpose', function () use ($contentTools) {
        $p = request_json();
        if (empty($p['content'])) { json_response(['error' => 'Missing: content'], 422); return; }
        $formats = $p['formats'] ?? ['tweet', 'linkedin_post', 'email', 'instagram_caption'];
        json_response(['item' => $contentTools->repurposeContent($p['content'], $formats)]);
    });

    $router->post('/api/ai/ad-variations', function () use ($contentTools) {
        $p = request_json();
        json_response(['item' => $contentTools->adVariations($p['base_ad'] ?? '', (int)($p['count'] ?? 5))]);
    });

    $router->post('/api/ai/subject-lines', function () use ($contentTools) {
        $p = request_json();
        json_response(['item' => $contentTools->emailSubjectLines($p['topic'] ?? '', (int)($p['count'] ?? 10))]);
    });

    $router->post('/api/ai/brief', function () use ($contentTools, $analytics) {
        $p = request_json();
        $result = $contentTools->contentBrief($p['topic'] ?? '', $p['content_type'] ?? 'blog_post', $p['goal'] ?? 'drive engagement');
        $analytics->track('ai.brief', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/headlines', function () use ($contentTools) {
        $p = request_json();
        if (empty($p['headline'])) { json_response(['error' => 'Missing: headline'], 422); return; }
        json_response(['item' => $contentTools->headlineOptimizer($p['headline'], $p['platform'] ?? 'general')]);
    });

    $router->post('/api/ai/refine', function () use ($contentTools, $analytics) {
        $p = request_json();
        if (empty($p['content'])) { json_response(['error' => 'Missing: content'], 422); return; }
        $result = $contentTools->refineContent($p['content'], $p['action'] ?? 'improve', $p['context'] ?? null);
        $analytics->track('ai.refine', 'ai', 0, ['action' => $p['action'] ?? 'improve']);
        json_response(['item' => $result]);
    });

    /* ================================================================== */
    /*  RESEARCH & STRATEGY                                               */
    /* ================================================================== */

    $router->post('/api/ai/research', function () use ($strategyTools, $aiLogs, $analytics) {
        $p = request_json();
        $audience = $p['audience'] ?? 'local customers';
        $goal = $p['goal'] ?? 'grow inbound leads';
        $result = $strategyTools->marketResearch($audience, $goal);
        $aiLogs->saveResearch("audience={$audience};goal={$goal}", $result['brief']);
        $analytics->track('ai.research', 'ai', 0, ['provider' => $result['provider']]);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/ideas', function () use ($strategyTools, $aiLogs, $analytics) {
        $p = request_json();
        $topic = $p['topic'] ?? 'seasonal offer';
        $platform = $p['platform'] ?? 'instagram';
        $result = $strategyTools->contentIdeas($topic, $platform);
        $aiLogs->saveIdea($topic, $platform, $result['ideas']);
        $analytics->track('ai.ideas', 'ai', 0, ['platform' => $platform]);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/persona', function () use ($strategyTools) {
        $p = request_json();
        json_response(['item' => $strategyTools->audiencePersona($p['demographics'] ?? '', $p['behaviors'] ?? '')]);
    });

    $router->post('/api/ai/competitor-analysis', function () use ($strategyTools) {
        $p = request_json();
        json_response(['item' => $strategyTools->competitorAnalysis($p['name'] ?? '', $p['notes'] ?? '')]);
    });

    $router->post('/api/ai/social-strategy', function () use ($strategyTools) {
        $p = request_json();
        json_response(['item' => $strategyTools->socialStrategy($p['goals'] ?? '', $p['current_state'] ?? '')]);
    });

    $router->post('/api/ai/calendar', function () use ($strategyTools) {
        $p = request_json();
        json_response(['item' => $strategyTools->scheduleSuggestion($p['objective'] ?? 'increase qualified leads')]);
    });

    $router->post('/api/ai/calendar-month', function () use ($strategyTools) {
        $p = request_json();
        json_response(['item' => $strategyTools->contentCalendarMonth(
            $p['month'] ?? date('F Y'),
            $p['goals'] ?? 'grow audience and engagement',
            $p['channels'] ?? 'instagram, twitter, linkedin, email'
        )]);
    });

    $router->post('/api/ai/smart-times', function () use ($strategyTools) {
        $p = request_json();
        json_response(['item' => $strategyTools->smartPostingTime(
            $p['platform'] ?? 'instagram',
            $p['audience'] ?? 'general business audience',
            $p['content_type'] ?? 'social_post'
        )]);
    });

    $router->post('/api/ai/campaign-optimizer', function () use ($strategyTools, $campaigns) {
        $p = request_json();
        $campaignData = $p['campaign_data'] ?? '';
        if (!empty($p['campaign_id'])) {
            $c = $campaigns->find((int)$p['campaign_id']);
            if ($c) {
                $campaignData = "Name: {$c['name']}\nChannel: {$c['channel']}\nObjective: {$c['objective']}\nBudget: \${$c['budget']}\nSpent: \${$c['spend_to_date']}\nRevenue: \${$c['revenue']}\nStart: {$c['start_date']}\nEnd: {$c['end_date']}\nStatus: {$c['status']}\nNotes: {$c['notes']}";
            }
        }
        json_response(['item' => $strategyTools->campaignOptimizer($campaignData, $p['goals'] ?? 'maximize ROI')]);
    });

    $router->post('/api/ai/report', function () use ($strategyTools, $posts, $campaigns, $analytics) {
        $overview = $analytics->overview(7);
        $stats = [
            'posts_created' => (int)($overview['posts']['total'] ?? 0),
            'posts_published' => (int)($overview['posts']['published'] ?? 0),
            'campaigns_active' => count($campaigns->all()),
            'top_platforms' => $overview['by_platform'] ?? [],
            'ai_research_count' => (int)($overview['ai_usage']['research_count'] ?? 0),
            'ai_ideas_count' => (int)($overview['ai_usage']['ideas_count'] ?? 0),
        ];
        json_response(['item' => $strategyTools->weeklyReport($stats)]);
    });

    $router->post('/api/ai/insights', function () use ($strategyTools, $posts, $campaigns, $analytics) {
        $overview = $analytics->overview(30);
        $stats = [
            'posts_total' => (int)($overview['posts']['total'] ?? 0),
            'posts_published' => (int)($overview['posts']['published'] ?? 0),
            'posts_scheduled' => (int)($overview['posts']['scheduled'] ?? 0),
            'posts_draft' => (int)($overview['posts']['draft'] ?? 0),
            'avg_ai_score' => (int)($overview['posts']['avg_score'] ?? 0),
            'campaigns_count' => count($campaigns->all()),
            'top_platforms' => $overview['by_platform'] ?? [],
            'content_types' => $overview['by_type'] ?? [],
            'ai_research_count' => (int)($overview['ai_usage']['research_count'] ?? 0),
            'ai_ideas_count' => (int)($overview['ai_usage']['ideas_count'] ?? 0),
            'email_campaigns' => (int)($overview['email']['campaigns'] ?? 0),
            'email_sent' => (int)($overview['email']['total_sent'] ?? 0),
            'social_published' => (int)($overview['social']['total_published'] ?? 0),
        ];
        json_response(['item' => $strategyTools->aiInsights($stats)]);
    });

    /* ================================================================== */
    /*  ANALYSIS & OPTIMIZATION                                           */
    /* ================================================================== */

    $router->post('/api/ai/tone-analysis', function () use ($analysisTools, $analytics) {
        $p = request_json();
        if (empty($p['content'])) { json_response(['error' => 'Missing: content'], 422); return; }
        $result = $analysisTools->toneAnalysis($p['content']);
        $analytics->track('ai.tone_analysis', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/score', function () use ($analysisTools) {
        $p = request_json();
        json_response(['item' => $analysisTools->contentScore($p['content'] ?? '', $p['platform'] ?? 'instagram')]);
    });

    $router->post('/api/ai/seo-keywords', function () use ($analysisTools) {
        $p = request_json();
        json_response(['item' => $analysisTools->seoKeywordResearch($p['topic'] ?? '', $p['niche'] ?? '')]);
    });

    $router->post('/api/ai/hashtags', function () use ($analysisTools) {
        $p = request_json();
        json_response(['item' => $analysisTools->hashtagResearch($p['topic'] ?? '', $p['platform'] ?? 'instagram')]);
    });

    $router->post('/api/ai/seo-audit', function () use ($analysisTools) {
        $p = request_json();
        json_response(['item' => $analysisTools->seoAudit($p['url'] ?? '', $p['description'] ?? '')]);
    });

    /* ================================================================== */
    /*  NEW TIER 1 FEATURES                                               */
    /* ================================================================== */

    // Content Workflow Engine — full week of coordinated content
    $router->post('/api/ai/workflow', function () use ($contentTools, $analytics) {
        $p = request_json();
        if (empty($p['topic'])) { json_response(['error' => 'Missing: topic'], 422); return; }
        $platforms = $p['platforms'] ?? ['instagram', 'twitter', 'linkedin', 'email'];
        $result = $contentTools->contentWorkflow($p['topic'], $p['goal'] ?? 'drive engagement', $platforms, (int)($p['days'] ?? 7));
        $analytics->track('ai.workflow', 'ai', 0, ['days' => $p['days'] ?? 7]);
        json_response(['item' => $result]);
    });

    // Brand Voice Auto-Builder
    $router->post('/api/ai/build-brand-voice', function () use ($contentTools, $analytics) {
        $p = request_json();
        if (empty($p['examples'])) { json_response(['error' => 'Missing: examples (paste 3-5 content samples)'], 422); return; }
        $result = $contentTools->buildBrandVoice($p['examples']);
        $analytics->track('ai.brand_voice', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    // Content Approval Reviewer — Pre-Flight Check
    $router->post('/api/ai/preflight', function () use ($analysisTools, $analytics) {
        $p = request_json();
        if (empty($p['content'])) { json_response(['error' => 'Missing: content'], 422); return; }
        $result = $analysisTools->preFlightCheck($p['content'], $p['platform'] ?? 'general', $p['context'] ?? null);
        $analytics->track('ai.preflight', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    // RSS-to-Post Pipeline
    $router->post('/api/ai/rss-to-post', function () use ($contentTools, $analytics) {
        $p = request_json();
        if (empty($p['title'])) { json_response(['error' => 'Missing: title'], 422); return; }
        $result = $contentTools->rssToPost(
            $p['title'],
            $p['summary'] ?? '',
            $p['url'] ?? '',
            $p['platform'] ?? 'twitter'
        );
        $analytics->track('ai.rss_to_post', 'ai', 0, ['platform' => $p['platform'] ?? 'twitter']);
        json_response(['item' => $result]);
    });

    /* ================================================================== */
    /*  NEW TIER 2 FEATURES                                               */
    /* ================================================================== */

    // Email Drip Sequence Generator
    $router->post('/api/ai/drip-sequence', function () use ($contentTools, $analytics) {
        $p = request_json();
        if (empty($p['goal'])) { json_response(['error' => 'Missing: goal'], 422); return; }
        $result = $contentTools->emailDripSequence($p['goal'], $p['audience'] ?? 'general', (int)($p['count'] ?? 5));
        $analytics->track('ai.drip_sequence', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    // Content Performance Predictor
    $router->post('/api/ai/predict', function () use ($analysisTools, $analytics) {
        $p = request_json();
        if (empty($p['content'])) { json_response(['error' => 'Missing: content'], 422); return; }
        $result = $analysisTools->predictPerformance(
            $p['content'],
            $p['platform'] ?? 'instagram',
            $p['scheduled_time'] ?? null,
            $p['historical_stats'] ?? null
        );
        $analytics->track('ai.predict', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    // Smart Segmentation
    $router->post('/api/ai/smart-segments', function () use ($strategyTools, $pdo, $analytics) {
        $segments = $pdo->query("SELECT name, description, contact_count FROM audience_segments ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

        $contactStats = [];
        $contactStats['total_contacts'] = (int)$pdo->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
        $stages = $pdo->query("SELECT stage, COUNT(*) as c FROM contacts GROUP BY stage")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stages as $s) $contactStats['stage_' . $s['stage']] = (int)$s['c'];
        $contactStats['avg_score'] = (int)$pdo->query("SELECT AVG(score) FROM contacts WHERE score > 0")->fetchColumn();
        $contactStats['active_subscribers'] = (int)$pdo->query("SELECT COUNT(*) FROM subscribers WHERE status = 'active'")->fetchColumn();

        $result = $strategyTools->smartSegmentation($contactStats, $segments);
        $analytics->track('ai.smart_segments', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    // Competitor Content Radar
    $router->post('/api/ai/competitor-radar', function () use ($strategyTools, $pdo, $analytics) {
        $competitors = $pdo->query("SELECT name, channel, positioning, recent_activity, opportunity FROM competitors ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($competitors)) { json_response(['error' => 'No competitors tracked. Add competitors first.'], 422); return; }
        $result = $strategyTools->competitorRadar($competitors);
        $analytics->track('ai.competitor_radar', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    // Multi-Language Localization
    $router->post('/api/ai/localize', function () use ($contentTools, $analytics) {
        $p = request_json();
        if (empty($p['content']) || empty($p['language'])) { json_response(['error' => 'Missing: content and language'], 422); return; }
        $result = $contentTools->localizeContent($p['content'], $p['language'], $p['platform'] ?? 'general');
        $analytics->track('ai.localize', 'ai', 0, ['language' => $p['language']]);
        json_response(['item' => $result]);
    });

    /* ================================================================== */
    /*  NEW TIER 3 FEATURES                                               */
    /* ================================================================== */

    // AI Image Prompt Generator
    $router->post('/api/ai/image-prompts', function () use ($contentTools, $analytics) {
        $p = request_json();
        if (empty($p['content'])) { json_response(['error' => 'Missing: content'], 422); return; }
        $result = $contentTools->imagePromptGenerator($p['content'], $p['platform'] ?? 'instagram', $p['style'] ?? 'modern');
        $analytics->track('ai.image_prompts', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    // Image Generation (Banana / DALL-E)
    $router->post('/api/ai/generate-image', function () use ($contentTools, $analytics) {
        $p = request_json();
        if (empty($p['prompt'])) { json_response(['error' => 'Missing: prompt'], 422); return; }
        $result = $contentTools->generateImage($p['prompt'], $p['provider'] ?? 'auto', $p['size'] ?? '1024x1024');
        $analytics->track('ai.generate_image', 'ai', 0, ['provider' => $result['provider'] ?? 'unknown']);
        json_response(['item' => $result]);
    });

    // Funnel Advisor
    $router->post('/api/ai/funnel-advisor', function () use ($strategyTools, $pdo, $analytics) {
        $p = request_json();
        $funnelId = (int)($p['funnel_id'] ?? 0);
        if (!$funnelId) { json_response(['error' => 'Missing: funnel_id'], 422); return; }

        $stmt = $pdo->prepare("SELECT name FROM funnels WHERE id = :id");
        $stmt->execute([':id' => $funnelId]);
        $funnel = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$funnel) { json_response(['error' => 'Funnel not found'], 404); return; }

        $stmt = $pdo->prepare("SELECT * FROM funnel_stages WHERE funnel_id = :id ORDER BY stage_order ASC");
        $stmt->execute([':id' => $funnelId]);
        $stages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = $strategyTools->funnelAdvisor($funnel['name'], $stages);
        $analytics->track('ai.funnel_advisor', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    // A/B Test Auto-Optimizer
    $router->post('/api/ai/ab-generate', function () use ($analysisTools, $analytics) {
        $p = request_json();
        if (empty($p['content'])) { json_response(['error' => 'Missing: content'], 422); return; }
        $result = $analysisTools->generateAbVariants($p['content'], $p['test_type'] ?? 'content', (int)($p['variants'] ?? 3));
        $analytics->track('ai.ab_generate', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    $router->post('/api/ai/ab-analyze', function () use ($analysisTools, $pdo) {
        $p = request_json();
        $testId = (int)($p['test_id'] ?? 0);
        if (!$testId) { json_response(['error' => 'Missing: test_id'], 422); return; }
        $stmt = $pdo->prepare("SELECT variant_name, impressions, conversions FROM ab_variants WHERE test_id = :id");
        $stmt->execute([':id' => $testId]);
        $variants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($variants)) { json_response(['error' => 'No variants found'], 404); return; }
        json_response(['item' => $analysisTools->analyzeAbResults($variants)]);
    });

    // Smart UTM & Attribution AI
    $router->post('/api/ai/smart-utm', function () use ($strategyTools, $analytics) {
        $p = request_json();
        if (empty($p['campaign_name']) || empty($p['url'])) { json_response(['error' => 'Missing: campaign_name and url'], 422); return; }
        $result = $strategyTools->smartUtm($p['campaign_name'], $p['url'], $p['channel'] ?? '', $p['description'] ?? '');
        $analytics->track('ai.smart_utm', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    // Weekly Standup Digest
    $router->post('/api/ai/standup', function () use ($strategyTools, $posts, $campaigns, $analytics, $pdo) {
        $overview = $analytics->overview(7);
        $lastWeekStats = [
            'posts_created' => (int)($overview['posts']['total'] ?? 0),
            'posts_published' => (int)($overview['posts']['published'] ?? 0),
            'campaigns_active' => count($campaigns->all()),
            'platforms' => $overview['by_platform'] ?? [],
            'email_sent' => (int)($overview['email']['total_sent'] ?? 0),
        ];

        $scheduled = $pdo->query("SELECT title, platform, scheduled_for FROM posts WHERE status = 'scheduled' AND scheduled_for IS NOT NULL ORDER BY scheduled_for ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

        $pending = [];
        $draftCount = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'draft'")->fetchColumn();
        if ($draftCount > 0) $pending[] = ['type' => 'drafts', 'description' => "{$draftCount} posts still in draft"];
        $queuedCount = (int)$pdo->query("SELECT COUNT(*) FROM social_queue WHERE status = 'queued'")->fetchColumn();
        if ($queuedCount > 0) $pending[] = ['type' => 'queue', 'description' => "{$queuedCount} items in publish queue"];

        $result = $strategyTools->weeklyStandup($lastWeekStats, $scheduled, $pending);
        $analytics->track('ai.standup', 'ai', 0, []);
        json_response(['item' => $result]);
    });

    /* ================================================================== */
    /*  CONVERSATIONAL AI CHAT                                            */
    /* ================================================================== */

    $router->post('/api/ai/chat', function () use ($chatService, $pdo, $analytics) {
        $p = request_json();
        $message = $p['message'] ?? '';
        if ($message === '') { json_response(['error' => 'Missing: message'], 422); return; }

        $conversationId = (int)($p['conversation_id'] ?? 0);
        $history = [];

        // Load conversation history
        if ($conversationId > 0) {
            $stmt = $pdo->prepare("SELECT role, content FROM ai_chat_messages WHERE conversation_id = :id ORDER BY id ASC");
            $stmt->execute([':id' => $conversationId]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Create new conversation
            $stmt = $pdo->prepare("INSERT INTO ai_chat_conversations (title, created_at, updated_at) VALUES (:t, :c, :u)");
            $now = gmdate(DATE_ATOM);
            $title = mb_substr($message, 0, 80);
            $stmt->execute([':t' => $title, ':c' => $now, ':u' => $now]);
            $conversationId = (int)$pdo->lastInsertId();
        }

        // Get reply
        $result = $chatService->chat($message, $history, $p['provider'] ?? null, $p['model'] ?? null);

        // Save messages
        $now = gmdate(DATE_ATOM);
        $stmt = $pdo->prepare("INSERT INTO ai_chat_messages (conversation_id, role, content, provider, created_at) VALUES (:cid, :role, :content, :provider, :ca)");
        $stmt->execute([':cid' => $conversationId, ':role' => 'user', ':content' => $message, ':provider' => '', ':ca' => $now]);
        $stmt->execute([':cid' => $conversationId, ':role' => 'assistant', ':content' => $result['reply'], ':provider' => $result['provider'], ':ca' => $now]);

        // Update conversation
        $pdo->prepare("UPDATE ai_chat_conversations SET message_count = message_count + 2, updated_at = :u, provider = :p WHERE id = :id")
            ->execute([':u' => $now, ':p' => $result['provider'], ':id' => $conversationId]);

        $analytics->track('ai.chat', 'ai', 0, ['provider' => $result['provider']]);

        json_response(['item' => [
            'reply'           => $result['reply'],
            'conversation_id' => $conversationId,
            'provider'        => $result['provider'],
            'context_used'    => $result['context_used'],
        ]]);
    });

    // List conversations
    $router->get('/api/ai/conversations', function () use ($pdo) {
        $rows = $pdo->query("SELECT id, title, provider, message_count, created_at, updated_at FROM ai_chat_conversations ORDER BY updated_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        json_response(['items' => $rows]);
    });

    // Get conversation messages
    $router->get('/api/ai/conversations/{id}', function (array $params) use ($pdo) {
        $id = (int)($params['id'] ?? 0);
        $conv = $pdo->prepare("SELECT * FROM ai_chat_conversations WHERE id = :id");
        $conv->execute([':id' => $id]);
        $conversation = $conv->fetch(PDO::FETCH_ASSOC);
        if (!$conversation) { json_response(['error' => 'Not found'], 404); return; }

        $msgs = $pdo->prepare("SELECT role, content, provider, created_at FROM ai_chat_messages WHERE conversation_id = :id ORDER BY id ASC");
        $msgs->execute([':id' => $id]);

        json_response(['item' => $conversation, 'messages' => $msgs->fetchAll(PDO::FETCH_ASSOC)]);
    });

    // Delete conversation
    $router->delete('/api/ai/conversations/{id}', function (array $params) use ($pdo) {
        $id = (int)($params['id'] ?? 0);
        $pdo->prepare("DELETE FROM ai_chat_messages WHERE conversation_id = :id")->execute([':id' => $id]);
        $pdo->prepare("DELETE FROM ai_chat_conversations WHERE id = :id")->execute([':id' => $id]);
        json_response(['ok' => true]);
    });

    /* ================================================================== */
    /*  BULK / MULTI / PROVIDER STATUS                                    */
    /* ================================================================== */

    $router->post('/api/ai/bulk', function () use ($contentTools) {
        $data = request_json();
        $specs = $data['specs'] ?? [];
        if (empty($specs) || !is_array($specs)) { json_response(['error' => 'Missing: specs array'], 422); return; }
        $results = [];
        foreach (array_slice($specs, 0, 10) as $spec) {
            $results[] = $contentTools->generateContent($spec);
        }
        json_response(['items' => $results]);
    });

    $router->post('/api/ai/multi', function () use ($ai) {
        $data = request_json();
        $prompt = $data['prompt'] ?? '';
        $providers = $data['providers'] ?? [];
        if (empty($prompt) || empty($providers)) { json_response(['error' => 'Missing: prompt and providers'], 422); return; }
        $results = $ai->generateMulti($ai->buildSystemPrompt(), $prompt, $providers);
        json_response(['item' => ['results' => $results]]);
    });

    $router->get('/api/ai/providers', function () use ($ai) {
        json_response($ai->providerStatus());
    });
}
