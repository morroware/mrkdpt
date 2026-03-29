<?php

declare(strict_types=1);

/**
 * AiAutopilot — Orchestration engine for autonomous AI marketing pipelines.
 *
 * Chains multiple AI calls sequentially, passing context between steps,
 * and saves results as real database records (campaigns, posts, brand profiles, etc.).
 */
final class AiAutopilot
{
    private const STEP_LABELS = [
        'research'     => 'Running market research',
        'persona'      => 'Building audience persona',
        'competitors'  => 'Analyzing competitors',
        'brand_voice'  => 'Crafting brand voice',
        'strategy'     => 'Creating social strategy',
        'calendar'     => 'Planning content calendar',
        'content'      => 'Drafting content',
        'campaign'     => 'Setting up campaign',
        'ideas'        => 'Generating content ideas',
    ];

    public function __construct(
        private PDO              $pdo,
        private AiStrategyTools  $strategyTools,
        private AiContentTools   $contentTools,
        private AiLogRepository  $aiLogs,
        private PostRepository   $posts,
        private CampaignRepository $campaigns,
        private BrandProfileRepository $brandProfiles,
        private CompetitorRepository $competitors,
    ) {}

    /* ================================================================== */
    /*  ONBOARDING AUTOPILOT — Full 9-step pipeline                      */
    /* ================================================================== */

    public function launchOnboarding(array $profile): array
    {
        $steps = ['research', 'persona', 'competitors', 'brand_voice', 'strategy', 'calendar', 'content', 'campaign', 'ideas'];
        $taskId = $this->createTask('onboarding', $steps);
        return $this->runOnboardingTask($taskId, $profile);
    }

    public function launchOnboardingAsync(array $profile): array
    {
        $steps = ['research', 'persona', 'competitors', 'brand_voice', 'strategy', 'calendar', 'content', 'campaign', 'ideas'];
        $taskId = $this->createTask('onboarding', $steps);
        return ['task_id' => $taskId, 'status' => 'running'];
    }

    public function runOnboardingTask(int $taskId, array $profile): array
    {
        $context = [];

        // Step 1: Market Research
        $this->updateStep($taskId, 1, 'research');
        try {
            $audience = $profile['target_audience'] ?: 'small business owners';
            $goals = $profile['marketing_goals'] ?: 'grow brand awareness and generate leads';
            $researchResult = $this->strategyTools->marketResearch($audience, $goals);
            $context['research'] = $this->summarize($researchResult['brief'] ?? '', 800);
            $this->aiLogs->saveResearch('Autopilot: Market Research for ' . ($profile['business_description'] ?: 'business'), $researchResult['brief'] ?? '');
            $this->saveAsset($taskId, 'research', 'Market Research Brief', $researchResult['brief'] ?? '');
            $this->saveResult($taskId, 'research', $context['research']);
        } catch (\Throwable $e) {
            $this->recordStepError($taskId, 'research', $e->getMessage());
            $context['research'] = '';
        }

        // Step 2: Audience Persona
        $this->updateStep($taskId, 2, 'persona');
        try {
            $demographics = $profile['target_audience'] ?: 'general audience';
            $behaviors = 'Interested in ' . ($profile['products_services'] ?: 'our products') . '. ' . $context['research'];
            $personaResult = $this->strategyTools->audiencePersona(
                $this->summarize($demographics, 200),
                $this->summarize($behaviors, 400)
            );
            $context['persona'] = $this->summarize($personaResult['persona'] ?? '', 600);
            $this->saveAsset($taskId, 'persona', 'Audience Persona', $personaResult['persona'] ?? '');
            $this->saveResult($taskId, 'persona', $context['persona']);
        } catch (\Throwable $e) {
            $this->recordStepError($taskId, 'persona', $e->getMessage());
            $context['persona'] = '';
        }

        // Step 3: Competitor Analysis
        $this->updateStep($taskId, 3, 'competitors');
        try {
            $competitorNames = array_filter(array_map('trim', explode(',', $profile['competitors'] ?? '')));
            $competitorResults = [];
            foreach (array_slice($competitorNames, 0, 3) as $name) {
                if (empty($name)) continue;
                $result = $this->strategyTools->competitorAnalysis($name, 'Based on research: ' . $this->summarize($context['research'], 200));
                $competitorResults[] = $name . ': ' . $this->summarize($result['analysis'] ?? '', 300);
                $this->competitors->create([
                    'name' => $name,
                    'channel' => 'multiple',
                    'positioning' => $this->summarize($result['analysis'] ?? '', 500),
                    'recent_activity' => '',
                    'opportunity' => '',
                ]);
            }
            $context['competitors'] = implode("\n\n", $competitorResults);
            if (empty($context['competitors'])) {
                $context['competitors'] = 'No specific competitors analyzed.';
            }
            $this->saveAsset($taskId, 'competitor_analysis', 'Competitor Analysis', $context['competitors']);
            $this->saveResult($taskId, 'competitors', $context['competitors']);
        } catch (\Throwable $e) {
            $this->recordStepError($taskId, 'competitors', $e->getMessage());
            $context['competitors'] = '';
        }

        // Step 4: Brand Voice
        $this->updateStep($taskId, 4, 'brand_voice');
        try {
            $examples = $profile['content_examples'] ?? '';
            if (empty(trim($examples))) {
                // Generate voice from research + persona context instead
                $examples = "Based on our business context, target audience, and market research, create a brand voice that would resonate.\n\n"
                    . "Business: " . ($profile['business_description'] ?: '') . "\n"
                    . "Audience: " . $context['persona'] . "\n"
                    . "Market: " . $this->summarize($context['research'], 300);
            }
            $voiceResult = $this->contentTools->buildBrandVoice($examples);
            if (!empty($voiceResult['profile'])) {
                $voiceData = $voiceResult['profile'];
                $this->brandProfiles->create([
                    'name' => 'AI Generated Voice',
                    'voice_tone' => $voiceData['voice_tone'] ?? '',
                    'vocabulary' => $voiceData['vocabulary'] ?? '',
                    'avoid_words' => $voiceData['avoid_words'] ?? '',
                    'example_content' => $voiceData['example_content'] ?? '',
                    'target_audience' => $voiceData['target_audience'] ?? '',
                    'is_active' => true,
                ]);
                $context['brand_voice'] = ($voiceData['voice_tone'] ?? '') . '. Vocabulary: ' . ($voiceData['vocabulary'] ?? '');
            } else {
                $context['brand_voice'] = $voiceResult['raw'] ?? '';
            }
            $this->saveAsset($taskId, 'brand_voice', 'Brand Voice Profile', json_encode($voiceResult['profile'] ?? $voiceResult['raw'] ?? '', JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->saveResult($taskId, 'brand_voice', $this->summarize($context['brand_voice'], 300));
        } catch (\Throwable $e) {
            $this->recordStepError($taskId, 'brand_voice', $e->getMessage());
            $context['brand_voice'] = '';
        }

        // Step 5: Social Strategy
        $this->updateStep($taskId, 5, 'strategy');
        try {
            $goals = $profile['marketing_goals'] ?: 'grow brand awareness';
            $currentState = "New business setup. Research findings: " . $this->summarize($context['research'], 300)
                . "\nTarget persona: " . $this->summarize($context['persona'], 200)
                . "\nActive platforms: " . ($profile['active_platforms'] ?: 'not specified')
                . "\nBudget: " . ($profile['budget_range'] ?: 'not specified');
            $strategyResult = $this->strategyTools->socialStrategy($goals, $currentState);
            $context['strategy'] = $this->summarize($strategyResult['strategy'] ?? '', 800);
            $this->saveAsset($taskId, 'strategy', 'Social Media Strategy', $strategyResult['strategy'] ?? '');
            $this->saveResult($taskId, 'strategy', $context['strategy']);
        } catch (\Throwable $e) {
            $this->recordStepError($taskId, 'strategy', $e->getMessage());
            $context['strategy'] = '';
        }

        // Step 6: Content Calendar
        $this->updateStep($taskId, 6, 'calendar');
        try {
            $month = date('F Y');
            $goals = $profile['marketing_goals'] ?: 'brand awareness and engagement';
            $channels = $profile['active_platforms'] ?: 'instagram, twitter, linkedin';
            $calendarResult = $this->strategyTools->contentCalendarMonth(
                $month,
                $goals . '. Strategy context: ' . $this->summarize($context['strategy'], 300),
                $channels
            );
            $context['calendar'] = $this->summarize($calendarResult['calendar'] ?? '', 800);
            $this->saveAsset($taskId, 'calendar', "Content Calendar — {$month}", $calendarResult['calendar'] ?? '');
            $this->saveResult($taskId, 'calendar', $context['calendar']);
        } catch (\Throwable $e) {
            $this->recordStepError($taskId, 'calendar', $e->getMessage());
            $context['calendar'] = '';
        }

        // Step 7: Draft Content (first week of posts)
        $this->updateStep($taskId, 7, 'content');
        try {
            $platforms = array_filter(array_map('trim', explode(',', $profile['active_platforms'] ?: 'instagram,twitter,linkedin')));
            $topic = $profile['products_services'] ?: $profile['business_description'] ?: 'our business';
            $goal = $profile['marketing_goals'] ?: 'brand awareness and engagement';
            $workflowResult = $this->contentTools->contentWorkflow($topic, $goal, $platforms, 7);
            $context['content'] = $this->summarize($workflowResult['workflow'] ?? '', 600);

            // Parse and create actual draft posts from the workflow
            $this->createDraftPostsFromWorkflow($workflowResult['workflow'] ?? '', $platforms, $taskId);

            $this->saveAsset($taskId, 'content_workflow', '7-Day Content Plan', $workflowResult['workflow'] ?? '');
            $this->saveResult($taskId, 'content', $context['content']);
        } catch (\Throwable $e) {
            $this->recordStepError($taskId, 'content', $e->getMessage());
            $context['content'] = '';
        }

        // Step 8: Create Campaign
        $this->updateStep($taskId, 8, 'campaign');
        try {
            $campaignName = 'Launch Campaign — ' . date('M Y');
            $channel = $profile['active_platforms'] ?: 'multi-channel';
            $objective = $profile['marketing_goals'] ?: 'brand awareness';
            $campaign = $this->campaigns->create([
                'name' => $campaignName,
                'channel' => $channel,
                'objective' => $objective,
                'budget' => 0,
                'notes' => "Auto-generated by AI Autopilot.\n\nStrategy Summary:\n" . $this->summarize($context['strategy'], 500),
                'start_date' => date('Y-m-d'),
                'end_date' => date('Y-m-d', strtotime('+30 days')),
            ]);
            $campaignId = $campaign['id'] ?? 0;
            $context['campaign_id'] = $campaignId;
            $this->saveResult($taskId, 'campaign', "Campaign '{$campaignName}' created (ID: {$campaignId})");
            // Link autopilot posts to this campaign
            if ($campaignId) {
                $this->pdo->prepare("UPDATE posts SET campaign_id = :cid WHERE tags LIKE '%autopilot%' AND campaign_id IS NULL AND created_at >= :since")->execute([
                    ':cid' => $campaignId,
                    ':since' => date('Y-m-d\T00:00:00', strtotime('-1 day')),
                ]);
            }
        } catch (\Throwable $e) {
            $this->recordStepError($taskId, 'campaign', $e->getMessage());
        }

        // Step 9: Content Ideas Backlog
        $this->updateStep($taskId, 9, 'ideas');
        try {
            $platforms = array_filter(array_map('trim', explode(',', $profile['active_platforms'] ?: 'instagram,twitter,linkedin')));
            $topic = $profile['products_services'] ?: $profile['business_description'] ?: 'our business';
            foreach (array_slice($platforms, 0, 3) as $platform) {
                $ideasResult = $this->strategyTools->contentIdeas($topic, trim($platform));
                $this->aiLogs->saveIdea($topic, trim($platform), $ideasResult['ideas'] ?? '');
            }
            $this->saveResult($taskId, 'ideas', 'Generated content ideas for ' . implode(', ', array_slice($platforms, 0, 3)));
        } catch (\Throwable $e) {
            $this->recordStepError($taskId, 'ideas', $e->getMessage());
        }

        // Mark task complete
        $this->completeTask($taskId);

        // Mark autopilot as run in business profile
        $stmt = $this->pdo->prepare('UPDATE business_profile SET autopilot_run = 1, updated_at = :updated');
        $stmt->execute([':updated' => gmdate(DATE_ATOM)]);

        return ['task_id' => $taskId, 'status' => 'completed'];
    }

    /* ================================================================== */
    /*  CAMPAIGN AUTOPILOT                                                */
    /* ================================================================== */

    public function launchCampaignAutopilot(array $params): array
    {
        $steps = ['research', 'strategy', 'calendar', 'content', 'campaign'];
        $taskId = $this->createTask('campaign_autopilot', $steps);
        $context = [];

        $objective = $params['objective'] ?? 'brand awareness';
        $channel = $params['channel'] ?? 'multi-channel';
        $duration = (int)($params['duration_days'] ?? 14);
        $topic = $params['topic'] ?? $objective;

        // Step 1: Research
        $this->updateStep($taskId, 1, 'research');
        try {
            $result = $this->strategyTools->marketResearch($params['audience'] ?? 'target customers', $objective);
            $context['research'] = $this->summarize($result['brief'] ?? '', 600);
            $this->saveResult($taskId, 'research', $context['research']);
        } catch (\Throwable $e) {
            $this->recordStepError($taskId, 'research', $e->getMessage());
            $context['research'] = '';
        }

        // Step 2: Strategy
        $this->updateStep($taskId, 2, 'strategy');
        try {
            $result = $this->strategyTools->socialStrategy($objective, "Campaign focus: {$topic}. Research: " . $this->summarize($context['research'], 300));
            $context['strategy'] = $this->summarize($result['strategy'] ?? '', 600);
            $this->saveResult($taskId, 'strategy', $context['strategy']);
        } catch (\Throwable $e) {
            $this->recordStepError($taskId, 'strategy', $e->getMessage());
            $context['strategy'] = '';
        }

        // Step 3: Calendar
        $this->updateStep($taskId, 3, 'calendar');
        try {
            $result = $this->strategyTools->contentCalendarMonth(date('F Y'), $objective . '. ' . $this->summarize($context['strategy'], 200), $channel);
            $context['calendar'] = $this->summarize($result['calendar'] ?? '', 600);
            $this->saveAsset($taskId, 'calendar', "Campaign Calendar", $result['calendar'] ?? '');
            $this->saveResult($taskId, 'calendar', $context['calendar']);
        } catch (\Throwable $e) {
            $this->recordStepError($taskId, 'calendar', $e->getMessage());
            $context['calendar'] = '';
        }

        // Step 4: Draft Content
        $this->updateStep($taskId, 4, 'content');
        try {
            $platforms = array_filter(array_map('trim', explode(',', $channel)));
            if (empty($platforms)) $platforms = ['instagram', 'twitter'];
            $days = min($duration, 7);
            $result = $this->contentTools->contentWorkflow($topic, $objective, $platforms, $days);
            $this->createDraftPostsFromWorkflow($result['workflow'] ?? '', $platforms, $taskId);
            $this->saveResult($taskId, 'content', 'Generated ' . $days . '-day content workflow');
        } catch (\Throwable $e) {
            $this->recordStepError($taskId, 'content', $e->getMessage());
        }

        // Step 5: Create Campaign record
        $this->updateStep($taskId, 5, 'campaign');
        try {
            $campaign = $this->campaigns->create([
                'name' => $params['name'] ?? ('Campaign: ' . ucfirst($objective)),
                'channel' => $channel,
                'objective' => $objective,
                'budget' => (float)($params['budget'] ?? 0),
                'notes' => "AI-generated campaign.\n\nStrategy:\n" . $this->summarize($context['strategy'], 500),
                'start_date' => date('Y-m-d'),
                'end_date' => date('Y-m-d', strtotime("+{$duration} days")),
            ]);
            $this->saveResult($taskId, 'campaign', "Campaign created (ID: " . ($campaign['id'] ?? 0) . ")");
            // Link autopilot posts to this campaign
            if (!empty($campaign['id'])) {
                $this->pdo->prepare("UPDATE posts SET campaign_id = :cid WHERE tags LIKE '%autopilot%' AND campaign_id IS NULL AND created_at >= :since")->execute([
                    ':cid' => (int)$campaign['id'],
                    ':since' => date('Y-m-d\T00:00:00', strtotime('-1 day')),
                ]);
            }
        } catch (\Throwable $e) {
            $this->recordStepError($taskId, 'campaign', $e->getMessage());
        }

        $this->completeTask($taskId);
        return ['task_id' => $taskId, 'status' => 'completed'];
    }

    /* ================================================================== */
    /*  WEEKLY PLAN                                                       */
    /* ================================================================== */

    public function generateWeeklyPlan(): array
    {
        $steps = ['analysis', 'ideas'];
        $taskId = $this->createTask('weekly_plan', $steps);

        // Step 1: Insights based on current data
        $this->updateStep($taskId, 1, 'analysis');
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN status='published' THEN 1 ELSE 0 END) as published, SUM(CASE WHEN status='scheduled' THEN 1 ELSE 0 END) as scheduled FROM posts");
            $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $insights = $this->strategyTools->aiInsights($stats);
            $this->saveAsset($taskId, 'weekly_insights', 'Weekly AI Insights', json_encode($insights['insights'] ?? [], JSON_PRETTY_PRINT));
            $this->saveResult($taskId, 'analysis', 'Generated weekly insights');
        } catch (\Throwable $e) {
            $this->recordStepError($taskId, 'analysis', $e->getMessage());
        }

        // Step 2: Next week content ideas
        $this->updateStep($taskId, 2, 'ideas');
        try {
            $bp = $this->getBusinessProfile() ?? [];
            $topic = $bp['products_services'] ?? $bp['business_description'] ?? 'our business';
            $platforms = array_filter(array_map('trim', explode(',', $bp['active_platforms'] ?? 'instagram')));
            foreach (array_slice($platforms, 0, 2) as $platform) {
                $result = $this->strategyTools->contentIdeas($topic, $platform);
                $this->aiLogs->saveIdea($topic, $platform, $result['ideas'] ?? '');
            }
            $this->saveResult($taskId, 'ideas', 'Generated ideas for ' . implode(', ', array_slice($platforms, 0, 2)));
        } catch (\Throwable $e) {
            $this->recordStepError($taskId, 'ideas', $e->getMessage());
        }

        $this->completeTask($taskId);
        return ['task_id' => $taskId, 'status' => 'completed'];
    }

    /* ================================================================== */
    /*  TASK STATUS                                                       */
    /* ================================================================== */

    public function getTaskStatus(int $taskId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ai_tasks WHERE id = :id');
        $stmt->execute([':id' => $taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$task) return null;

        $task['steps_config'] = json_decode($task['steps_config'], true) ?: [];
        $task['results'] = json_decode($task['results'], true) ?: [];

        // Add step labels
        $task['step_labels'] = [];
        foreach ($task['steps_config'] as $step) {
            $task['step_labels'][] = self::STEP_LABELS[$step] ?? ucfirst($step);
        }

        return $task;
    }

    public function getLatestTask(?string $type = null): ?array
    {
        $sql = 'SELECT * FROM ai_tasks';
        $params = [];
        if ($type) {
            $sql .= ' WHERE task_type = :type';
            $params[':type'] = $type;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$task) return null;

        $task['steps_config'] = json_decode($task['steps_config'], true) ?: [];
        $task['results'] = json_decode($task['results'], true) ?: [];
        $task['step_labels'] = [];
        foreach ($task['steps_config'] as $step) {
            $task['step_labels'][] = self::STEP_LABELS[$step] ?? ucfirst($step);
        }
        return $task;
    }

    /* ================================================================== */
    /*  ASSETS                                                            */
    /* ================================================================== */

    public function getAssets(string $status = 'pending_review'): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ai_generated_assets WHERE status = :status ORDER BY id DESC');
        $stmt->execute([':status' => $status]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllAssets(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM ai_generated_assets ORDER BY id DESC LIMIT 50');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function approveAsset(int $assetId): bool
    {
        $stmt = $this->pdo->prepare('UPDATE ai_generated_assets SET status = :status WHERE id = :id');
        return $stmt->execute([':status' => 'approved', ':id' => $assetId]);
    }

    public function rejectAsset(int $assetId): bool
    {
        $stmt = $this->pdo->prepare('UPDATE ai_generated_assets SET status = :status WHERE id = :id');
        return $stmt->execute([':status' => 'rejected', ':id' => $assetId]);
    }

    public function findAsset(int $assetId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ai_generated_assets WHERE id = :id');
        $stmt->execute([':id' => $assetId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /* ================================================================== */
    /*  BUSINESS PROFILE                                                  */
    /* ================================================================== */

    public function getBusinessProfile(): ?array
    {
        $stmt = $this->pdo->query('SELECT * FROM business_profile ORDER BY id DESC LIMIT 1');
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function discoverFromWebsite(string $url): array
    {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['error' => 'Please provide a valid website URL.'];
        }

        $snapshot = $this->fetchWebsiteSnapshot($url);
        if ($snapshot === '') {
            return ['error' => 'Could not fetch website content for analysis.'];
        }

        $result = $this->strategyTools->discoverBusinessFromWebsite($url, $snapshot);
        if (empty($result['profile']) || !is_array($result['profile'])) {
            return ['error' => 'AI could not infer a profile from this website yet. Try adding more details manually.'];
        }

        return [
            'profile' => $result['profile'],
            'provider' => $result['provider'] ?? null,
        ];
    }

    public function saveBusinessProfile(array $data): int
    {
        $existing = $this->getBusinessProfile();
        $now = gmdate(DATE_ATOM);

        if ($existing) {
            $stmt = $this->pdo->prepare('UPDATE business_profile SET
                business_description = :desc, target_audience = :audience,
                products_services = :products, competitors = :competitors,
                marketing_goals = :goals, active_platforms = :platforms,
                content_examples = :examples, budget_range = :budget,
                website_url = :website, unique_selling_points = :usps,
                onboarding_completed = :onboarded, updated_at = :updated
                WHERE id = :id');
            $stmt->execute([
                ':desc'      => $data['business_description'] ?? $existing['business_description'],
                ':audience'  => $data['target_audience'] ?? $existing['target_audience'],
                ':products'  => $data['products_services'] ?? $existing['products_services'],
                ':competitors' => $data['competitors'] ?? $existing['competitors'],
                ':goals'     => $data['marketing_goals'] ?? $existing['marketing_goals'],
                ':platforms' => $data['active_platforms'] ?? $existing['active_platforms'],
                ':examples'  => $data['content_examples'] ?? $existing['content_examples'],
                ':budget'    => $data['budget_range'] ?? $existing['budget_range'],
                ':website'   => $data['website_url'] ?? $existing['website_url'],
                ':usps'      => $data['unique_selling_points'] ?? $existing['unique_selling_points'],
                ':onboarded' => (int)($data['onboarding_completed'] ?? $existing['onboarding_completed']),
                ':updated'   => $now,
                ':id'        => $existing['id'],
            ]);
            return (int)$existing['id'];
        }

        $stmt = $this->pdo->prepare('INSERT INTO business_profile
            (business_description, target_audience, products_services, competitors,
             marketing_goals, active_platforms, content_examples, budget_range,
             website_url, unique_selling_points, onboarding_completed, autopilot_run,
             created_at, updated_at)
            VALUES (:desc, :audience, :products, :competitors, :goals, :platforms,
                    :examples, :budget, :website, :usps, :onboarded, 0, :created, :updated)');
        $stmt->execute([
            ':desc'      => $data['business_description'] ?? '',
            ':audience'  => $data['target_audience'] ?? '',
            ':products'  => $data['products_services'] ?? '',
            ':competitors' => $data['competitors'] ?? '',
            ':goals'     => $data['marketing_goals'] ?? '',
            ':platforms' => $data['active_platforms'] ?? '',
            ':examples'  => $data['content_examples'] ?? '',
            ':budget'    => $data['budget_range'] ?? '',
            ':website'   => $data['website_url'] ?? '',
            ':usps'      => $data['unique_selling_points'] ?? '',
            ':onboarded' => (int)($data['onboarding_completed'] ?? 0),
            ':created'   => $now,
            ':updated'   => $now,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /* ================================================================== */
    /*  PRIVATE HELPERS                                                   */
    /* ================================================================== */

    private function createTask(string $type, array $steps): int
    {
        $now = gmdate(DATE_ATOM);
        $stmt = $this->pdo->prepare('INSERT INTO ai_tasks (task_type, status, step_current, step_total, steps_config, results, created_at, updated_at) VALUES (:type, :status, 0, :total, :steps, :results, :created, :updated)');
        $stmt->execute([
            ':type'    => $type,
            ':status'  => 'running',
            ':total'   => count($steps),
            ':steps'   => json_encode($steps),
            ':results' => '{}',
            ':created' => $now,
            ':updated' => $now,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function updateStep(int $taskId, int $step, string $stepName): void
    {
        $stmt = $this->pdo->prepare('UPDATE ai_tasks SET step_current = :step, updated_at = :updated WHERE id = :id');
        $stmt->execute([':step' => $step, ':updated' => gmdate(DATE_ATOM), ':id' => $taskId]);
    }

    private function saveResult(int $taskId, string $key, string $value): void
    {
        $stmt = $this->pdo->prepare('SELECT results FROM ai_tasks WHERE id = :id');
        $stmt->execute([':id' => $taskId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $results = json_decode($row['results'] ?? '{}', true) ?: [];
        $results[$key] = $value;

        $stmt = $this->pdo->prepare('UPDATE ai_tasks SET results = :results, updated_at = :updated WHERE id = :id');
        $stmt->execute([':results' => json_encode($results), ':updated' => gmdate(DATE_ATOM), ':id' => $taskId]);
    }

    private function recordStepError(int $taskId, string $step, string $message): void
    {
        $stmt = $this->pdo->prepare('SELECT error FROM ai_tasks WHERE id = :id');
        $stmt->execute([':id' => $taskId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $errors = ($row['error'] ?? '') ?: '';
        $errors .= "[{$step}] {$message}\n";
        $stmt = $this->pdo->prepare('UPDATE ai_tasks SET error = :error, updated_at = :updated WHERE id = :id');
        $stmt->execute([':error' => $errors, ':updated' => gmdate(DATE_ATOM), ':id' => $taskId]);
    }

    private function completeTask(int $taskId): void
    {
        $stmt = $this->pdo->prepare('UPDATE ai_tasks SET status = :status, updated_at = :updated WHERE id = :id');
        $stmt->execute([':status' => 'completed', ':updated' => gmdate(DATE_ATOM), ':id' => $taskId]);
    }

    private function saveAsset(int $taskId, string $type, string $title, string $content, array $metadata = []): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO ai_generated_assets (task_id, asset_type, title, content, metadata, status, created_at) VALUES (:task_id, :type, :title, :content, :metadata, :status, :created)');
        $stmt->execute([
            ':task_id'  => $taskId,
            ':type'     => $type,
            ':title'    => $title,
            ':content'  => $content,
            ':metadata' => json_encode($metadata),
            ':status'   => 'pending_review',
            ':created'  => gmdate(DATE_ATOM),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function createDraftPostsFromWorkflow(string $workflow, array $platforms, int $taskId, ?int $campaignId = null): void
    {
        // Create draft posts for each day/platform combination
        // Parse the workflow text and create structured posts
        $now = gmdate(DATE_ATOM);
        $postsCreated = 0;

        for ($day = 1; $day <= 7 && $postsCreated < 14; $day++) {
            $scheduleDate = date('Y-m-d', strtotime("+{$day} days"));
            foreach (array_slice($platforms, 0, 3) as $platform) {
                $platform = trim($platform);
                if (empty($platform)) continue;

                // Extract a portion of the workflow relevant to this day
                $dayContent = $this->extractDayContent($workflow, $day, $platform);
                if (empty($dayContent)) {
                    $dayContent = "Day {$day} — {$platform} post (review and customize from the content plan)";
                }

                $this->posts->create([
                    'campaign_id'  => $campaignId,
                    'platform'     => $platform,
                    'content_type' => 'social_post',
                    'title'        => "Day {$day} — " . ucfirst($platform) . " Post",
                    'body'         => $dayContent,
                    'cta'          => '',
                    'tags'         => 'autopilot,ai-generated',
                    'scheduled_for' => $scheduleDate . 'T09:00:00Z',
                    'status'       => 'draft',
                    'ai_score'     => 0,
                ]);
                $postsCreated++;
            }
        }
    }

    private function extractDayContent(string $workflow, int $day, string $platform): string
    {
        // Try to find content for this specific day and platform in the workflow text
        $patterns = [
            "/Day\s*{$day}[^\n]*{$platform}[^\n]*\n([\s\S]*?)(?=Day\s*\d|$)/i",
            "/Day\s*{$day}[\s\S]*?{$platform}[\s\S]*?(?:caption|post|content)[:\s]*([\s\S]*?)(?=\n\n|\n[A-Z#*]|Day\s*\d|$)/i",
            "/Day\s*{$day}[^\n]*\n([\s\S]*?)(?=Day\s*\d|$)/i",
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $workflow, $m)) {
                $content = trim($m[1]);
                if (strlen($content) > 20) {
                    return $this->summarize($content, 1000);
                }
            }
        }

        return '';
    }

    private function summarize(string $text, int $maxLen): string
    {
        if (strlen($text) <= $maxLen) return $text;
        return mb_substr($text, 0, $maxLen - 3) . '...';
    }

    private function fetchWebsiteSnapshot(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 20,
                'user_agent' => 'MarketingSuiteBot/1.0 (+https://local.marketing-suite)',
            ],
        ]);
        $html = @file_get_contents($url, false, $context);
        if (!is_string($html) || trim($html) === '') {
            return '';
        }

        $html = mb_substr($html, 0, 250000);
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        libxml_clear_errors();

        $extract = [];
        $titleNodes = $dom->getElementsByTagName('title');
        if ($titleNodes->length > 0) {
            $extract[] = 'Title: ' . trim((string)$titleNodes->item(0)->textContent);
        }

        foreach (['description', 'og:description'] as $metaName) {
            foreach ($dom->getElementsByTagName('meta') as $meta) {
                $name = strtolower((string)$meta->getAttribute('name'));
                $prop = strtolower((string)$meta->getAttribute('property'));
                if ($name === $metaName || $prop === $metaName) {
                    $content = trim((string)$meta->getAttribute('content'));
                    if ($content !== '') {
                        $extract[] = 'Meta Description: ' . $content;
                    }
                    break;
                }
            }
        }

        foreach (['h1', 'h2', 'p'] as $tag) {
            $count = 0;
            foreach ($dom->getElementsByTagName($tag) as $node) {
                $text = trim((string)preg_replace('/\s+/', ' ', (string)$node->textContent));
                if ($text === '') {
                    continue;
                }
                $extract[] = strtoupper($tag) . ': ' . $text;
                $count++;
                if (($tag === 'p' && $count >= 8) || ($tag !== 'p' && $count >= 6)) {
                    break;
                }
            }
        }

        return mb_substr(implode("\n", $extract), 0, 12000);
    }
}
