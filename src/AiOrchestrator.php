<?php

declare(strict_types=1);

/**
 * AiOrchestrator — Tool chaining, pipelines, and cross-tool intelligence.
 *
 * Enables:
 *  1. Run multi-step AI pipelines (e.g., research → strategy → content → review)
 *  2. Suggest next actions based on what was just done
 *  3. Auto-chain tools when one output naturally feeds another
 *  4. Save/load reusable pipeline templates
 */
final class AiOrchestrator
{
    /** Built-in pipeline templates. */
    private const PIPELINE_TEMPLATES = [
        'content_creation' => [
            'name' => 'Content Creation Pipeline',
            'description' => 'Research → Ideas → Write → Score → Pre-flight check',
            'steps' => [
                ['tool' => 'research', 'label' => 'Market Research', 'map' => ['audience' => '{{audience}}', 'goal' => '{{goal}}']],
                ['tool' => 'ideas', 'label' => 'Content Ideas', 'map' => ['topic' => '{{topic}}', 'platform' => '{{platform}}']],
                ['tool' => 'content', 'label' => 'Generate Content', 'map' => ['topic' => '{{topic}}', 'platform' => '{{platform}}', 'tone' => '{{tone}}', 'goal' => '{{goal}}']],
                ['tool' => 'score', 'label' => 'Content Scoring', 'map' => ['content' => '{{prev.content}}', 'platform' => '{{platform}}']],
                ['tool' => 'preflight', 'label' => 'Pre-Flight Check', 'map' => ['content' => '{{prev.content}}', 'platform' => '{{platform}}']],
            ],
        ],
        'campaign_launch' => [
            'name' => 'Campaign Launch Pipeline',
            'description' => 'Research → Strategy → Calendar → Content Workflow → Campaign Optimizer',
            'steps' => [
                ['tool' => 'research', 'label' => 'Audience Research', 'map' => ['audience' => '{{audience}}', 'goal' => '{{goal}}']],
                ['tool' => 'social-strategy', 'label' => 'Social Strategy', 'map' => ['goals' => '{{goal}}', 'current_state' => '{{prev_summary}}']],
                ['tool' => 'calendar-month', 'label' => 'Monthly Calendar', 'map' => ['month' => '{{month}}', 'goals' => '{{goal}}', 'channels' => '{{channels}}']],
                ['tool' => 'workflow', 'label' => 'Content Workflow', 'map' => ['topic' => '{{topic}}', 'goal' => '{{goal}}', 'platforms' => '{{platforms}}']],
                ['tool' => 'campaign-optimizer', 'label' => 'Campaign Optimization', 'map' => ['campaign_data' => '{{prev_summary}}', 'goals' => '{{goal}}']],
            ],
        ],
        'competitor_intel' => [
            'name' => 'Competitor Intelligence Pipeline',
            'description' => 'Competitor Analysis → Radar → Content Ideas → Strategy',
            'steps' => [
                ['tool' => 'competitor-analysis', 'label' => 'Deep Analysis', 'map' => ['name' => '{{competitor}}', 'notes' => '{{notes}}']],
                ['tool' => 'competitor-radar', 'label' => 'Content Radar', 'map' => []],
                ['tool' => 'ideas', 'label' => 'Counter-Content Ideas', 'map' => ['topic' => 'counter {{competitor}} strategy', 'platform' => '{{platform}}']],
                ['tool' => 'social-strategy', 'label' => 'Differentiation Strategy', 'map' => ['goals' => 'differentiate from {{competitor}}', 'current_state' => '{{prev_summary}}']],
            ],
        ],
        'content_repurpose' => [
            'name' => 'Content Repurpose Pipeline',
            'description' => 'Score existing → Repurpose → Caption Batch → Hashtags',
            'steps' => [
                ['tool' => 'score', 'label' => 'Score Original', 'map' => ['content' => '{{content}}', 'platform' => '{{platform}}']],
                ['tool' => 'repurpose', 'label' => 'Repurpose Content', 'map' => ['content' => '{{content}}', 'formats' => '{{formats}}']],
                ['tool' => 'caption-batch', 'label' => 'Social Captions', 'map' => ['topic' => '{{topic}}', 'platforms' => '{{platforms}}']],
                ['tool' => 'hashtags', 'label' => 'Hashtag Research', 'map' => ['topic' => '{{topic}}', 'platform' => '{{platform}}']],
            ],
        ],
        'seo_content' => [
            'name' => 'SEO Content Pipeline',
            'description' => 'Keyword Research → Blog Post → SEO Audit → Headlines',
            'steps' => [
                ['tool' => 'seo-keywords', 'label' => 'Keyword Research', 'map' => ['topic' => '{{topic}}', 'niche' => '{{niche}}']],
                ['tool' => 'blog-post', 'label' => 'Blog Post', 'map' => ['title' => '{{title}}', 'keywords' => '{{prev_summary}}']],
                ['tool' => 'seo-audit', 'label' => 'SEO Audit', 'map' => ['url' => '{{url}}', 'description' => '{{prev_summary}}']],
                ['tool' => 'headlines', 'label' => 'Headline Optimization', 'map' => ['headline' => '{{title}}']],
            ],
        ],
    ];

    /** Maps tool names to the route endpoint and expected input/output fields. */
    private const TOOL_REGISTRY = [
        'content'             => ['endpoint' => '/api/ai/content', 'category' => 'content', 'output_field' => 'content'],
        'blog-post'           => ['endpoint' => '/api/ai/blog-post', 'category' => 'content', 'output_field' => 'post'],
        'video-script'        => ['endpoint' => '/api/ai/video-script', 'category' => 'content', 'output_field' => 'script'],
        'caption-batch'       => ['endpoint' => '/api/ai/caption-batch', 'category' => 'content', 'output_field' => 'captions'],
        'repurpose'           => ['endpoint' => '/api/ai/repurpose', 'category' => 'content', 'output_field' => 'repurposed'],
        'ad-variations'       => ['endpoint' => '/api/ai/ad-variations', 'category' => 'content', 'output_field' => 'variations'],
        'subject-lines'       => ['endpoint' => '/api/ai/subject-lines', 'category' => 'content', 'output_field' => 'subjects'],
        'refine'              => ['endpoint' => '/api/ai/refine', 'category' => 'content', 'output_field' => 'content'],
        'headlines'           => ['endpoint' => '/api/ai/headlines', 'category' => 'content', 'output_field' => 'headlines'],
        'brief'               => ['endpoint' => '/api/ai/brief', 'category' => 'content', 'output_field' => 'brief'],
        'workflow'            => ['endpoint' => '/api/ai/workflow', 'category' => 'content', 'output_field' => 'workflow'],
        'research'            => ['endpoint' => '/api/ai/research', 'category' => 'strategy', 'output_field' => 'brief'],
        'ideas'               => ['endpoint' => '/api/ai/ideas', 'category' => 'strategy', 'output_field' => 'ideas'],
        'persona'             => ['endpoint' => '/api/ai/persona', 'category' => 'strategy', 'output_field' => 'persona'],
        'competitor-analysis' => ['endpoint' => '/api/ai/competitor-analysis', 'category' => 'strategy', 'output_field' => 'analysis'],
        'social-strategy'     => ['endpoint' => '/api/ai/social-strategy', 'category' => 'strategy', 'output_field' => 'strategy'],
        'calendar'            => ['endpoint' => '/api/ai/calendar', 'category' => 'strategy', 'output_field' => 'calendar'],
        'calendar-month'      => ['endpoint' => '/api/ai/calendar-month', 'category' => 'strategy', 'output_field' => 'calendar'],
        'smart-times'         => ['endpoint' => '/api/ai/smart-times', 'category' => 'strategy', 'output_field' => 'times'],
        'campaign-optimizer'  => ['endpoint' => '/api/ai/campaign-optimizer', 'category' => 'strategy', 'output_field' => 'optimization'],
        'score'               => ['endpoint' => '/api/ai/score', 'category' => 'analysis', 'output_field' => 'score'],
        'tone-analysis'       => ['endpoint' => '/api/ai/tone-analysis', 'category' => 'analysis', 'output_field' => 'analysis'],
        'seo-keywords'        => ['endpoint' => '/api/ai/seo-keywords', 'category' => 'analysis', 'output_field' => 'keywords'],
        'hashtags'            => ['endpoint' => '/api/ai/hashtags', 'category' => 'analysis', 'output_field' => 'hashtags'],
        'seo-audit'           => ['endpoint' => '/api/ai/seo-audit', 'category' => 'analysis', 'output_field' => 'audit'],
        'preflight'           => ['endpoint' => '/api/ai/preflight', 'category' => 'analysis', 'output_field' => 'review'],
        'predict'             => ['endpoint' => '/api/ai/predict', 'category' => 'analysis', 'output_field' => 'prediction'],
        'competitor-radar'    => ['endpoint' => '/api/ai/competitor-radar', 'category' => 'strategy', 'output_field' => 'radar'],
    ];

    /** Suggest next tools based on what was just used. */
    private const NEXT_ACTION_MAP = [
        'research'            => ['ideas', 'persona', 'social-strategy'],
        'ideas'               => ['content', 'brief', 'calendar'],
        'persona'             => ['content', 'social-strategy', 'research'],
        'content'             => ['score', 'preflight', 'repurpose', 'hashtags'],
        'blog-post'           => ['seo-audit', 'headlines', 'score'],
        'score'               => ['refine', 'preflight', 'predict'],
        'preflight'           => ['refine', 'content'],
        'competitor-analysis' => ['competitor-radar', 'ideas', 'social-strategy'],
        'social-strategy'     => ['calendar-month', 'workflow', 'campaign-optimizer'],
        'calendar-month'      => ['workflow', 'content'],
        'workflow'            => ['score', 'preflight'],
        'seo-keywords'        => ['blog-post', 'content', 'brief'],
        'seo-audit'           => ['seo-keywords', 'headlines'],
        'tone-analysis'       => ['refine', 'score'],
        'hashtags'            => ['content', 'caption-batch'],
        'repurpose'           => ['score', 'hashtags', 'caption-batch'],
        'headlines'           => ['content', 'blog-post'],
        'caption-batch'       => ['hashtags', 'score'],
        'campaign-optimizer'  => ['calendar-month', 'content'],
        'predict'             => ['refine', 'smart-times'],
    ];

    public function __construct(
        private PDO $pdo,
        private AiService $ai,
        private AiContentTools $contentTools,
        private AiAnalysisTools $analysisTools,
        private AiStrategyTools $strategyTools,
        private AiMemoryEngine $memoryEngine,
    ) {}

    /* ================================================================== */
    /*  PIPELINE TEMPLATES                                                 */
    /* ================================================================== */

    public function getTemplates(): array
    {
        return self::PIPELINE_TEMPLATES;
    }

    public function getToolRegistry(): array
    {
        return self::TOOL_REGISTRY;
    }

    /* ================================================================== */
    /*  NEXT ACTION SUGGESTIONS                                            */
    /* ================================================================== */

    /**
     * Suggest next tools to run based on what was just completed.
     */
    public function suggestNextActions(string $completedTool, ?string $outputSummary = null): array
    {
        $suggestions = self::NEXT_ACTION_MAP[$completedTool] ?? [];
        $result = [];
        foreach ($suggestions as $tool) {
            $reg = self::TOOL_REGISTRY[$tool] ?? null;
            if ($reg === null) continue;
            $result[] = [
                'tool'     => $tool,
                'endpoint' => $reg['endpoint'],
                'category' => $reg['category'],
                'reason'   => $this->getActionReason($completedTool, $tool),
            ];
        }
        return $result;
    }

    private function getActionReason(string $from, string $to): string
    {
        $reasons = [
            'research:ideas'        => 'Generate content ideas based on your research findings',
            'research:persona'      => 'Build audience personas from research data',
            'research:social-strategy' => 'Create a strategy informed by research',
            'ideas:content'         => 'Turn your best idea into ready-to-publish content',
            'ideas:brief'           => 'Create a detailed brief from an idea',
            'ideas:calendar'        => 'Plan a schedule around your ideas',
            'content:score'         => 'Score your content for quality and engagement potential',
            'content:preflight'     => 'Run a pre-flight check before publishing',
            'content:repurpose'     => 'Repurpose this content for other platforms',
            'content:hashtags'      => 'Find the best hashtags for this content',
            'score:refine'          => 'Improve content based on the score feedback',
            'score:preflight'       => 'Run pre-flight check on scored content',
            'score:predict'         => 'Predict how this content will perform',
            'blog-post:seo-audit'   => 'Audit the blog post for SEO optimization',
            'blog-post:headlines'   => 'Generate alternative headline options',
            'blog-post:score'       => 'Score the blog post quality',
            'seo-keywords:blog-post' => 'Write a blog post targeting these keywords',
            'seo-keywords:content'  => 'Create content optimized for these keywords',
            'competitor-analysis:competitor-radar' => 'Scan for content opportunities vs competitors',
            'competitor-analysis:ideas' => 'Generate ideas to counter competitor positioning',
            'social-strategy:calendar-month' => 'Build a monthly calendar from the strategy',
            'social-strategy:workflow' => 'Create a content workflow based on the strategy',
        ];
        return $reasons["{$from}:{$to}"] ?? "Recommended next step after {$from}";
    }

    /* ================================================================== */
    /*  PIPELINE EXECUTION                                                 */
    /* ================================================================== */

    /**
     * Run a pipeline (template or custom steps).
     *
     * @param string $templateId  Built-in template ID, or empty for custom
     * @param array  $variables   Variables to substitute in step maps (e.g., topic, platform)
     * @param array  $customSteps If not using a template, provide steps directly
     * @return array Pipeline run results
     */
    public function runPipeline(string $templateId, array $variables, array $customSteps = []): array
    {
        $template = self::PIPELINE_TEMPLATES[$templateId] ?? null;
        $steps = $template !== null ? $template['steps'] : $customSteps;
        $pipelineName = $template !== null ? $template['name'] : 'Custom Pipeline';

        if (empty($steps)) {
            return ['error' => 'No pipeline steps defined'];
        }

        // Create pipeline run record
        $now = gmdate(DATE_ATOM);
        $pipelineId = $this->getOrCreatePipeline($pipelineName, $templateId, $steps);
        $stmt = $this->pdo->prepare(
            "INSERT INTO ai_pipeline_runs (pipeline_id, status, steps_total, results_json, started_at) VALUES (:pid, 'running', :total, '{}', :started)"
        );
        $stmt->execute([':pid' => $pipelineId, ':total' => count($steps), ':started' => $now]);
        $runId = (int)$this->pdo->lastInsertId();

        $results = [];
        $prevOutput = '';
        $prevSummary = '';

        foreach ($steps as $i => $step) {
            $toolName = $step['tool'] ?? '';
            $label = $step['label'] ?? $toolName;
            $inputMap = $step['map'] ?? [];

            // Resolve variables and previous output references
            $resolvedInput = $this->resolveStepInput($inputMap, $variables, $prevOutput, $prevSummary);

            $startTime = microtime(true);
            try {
                $output = $this->executeToolStep($toolName, $resolvedInput);
                $durationMs = (int)((microtime(true) - $startTime) * 1000);

                // Extract a summary from the output for the next step
                $outputStr = is_array($output) ? json_encode($output, JSON_UNESCAPED_SLASHES) : (string)$output;
                $prevOutput = $outputStr;
                $prevSummary = mb_substr($outputStr, 0, 800);

                // Log activity
                $activityId = $this->memoryEngine->logActivity(
                    "pipeline:{$toolName}",
                    self::TOOL_REGISTRY[$toolName]['category'] ?? 'pipeline',
                    mb_substr(json_encode($resolvedInput), 0, 300),
                    mb_substr($outputStr, 0, 500),
                    $output['provider'] ?? '',
                    '',
                    $durationMs,
                    ['pipeline_run_id' => $runId, 'step' => $i],
                );

                $results[] = [
                    'step'        => $i + 1,
                    'tool'        => $toolName,
                    'label'       => $label,
                    'status'      => 'completed',
                    'output'      => $output,
                    'duration_ms' => $durationMs,
                    'activity_id' => $activityId,
                ];

                // Update run progress
                $this->pdo->prepare("UPDATE ai_pipeline_runs SET steps_completed = :sc, results_json = :results WHERE id = :id")->execute([
                    ':sc' => $i + 1,
                    ':results' => json_encode($results, JSON_UNESCAPED_SLASHES),
                    ':id' => $runId,
                ]);

            } catch (\Throwable $e) {
                $results[] = [
                    'step'   => $i + 1,
                    'tool'   => $toolName,
                    'label'  => $label,
                    'status' => 'error',
                    'error'  => $e->getMessage(),
                ];

                // Continue pipeline despite errors — reset prevOutput so next step doesn't get stale data
                $prevOutput = '';
                $prevSummary = "Step {$label} failed: {$e->getMessage()}";
            }
        }

        // Finalize run
        $finalStatus = array_reduce($results, fn($carry, $r) => $r['status'] === 'error' ? 'partial' : $carry, 'completed');
        $this->pdo->prepare("UPDATE ai_pipeline_runs SET status = :status, results_json = :results, completed_at = :completed WHERE id = :id")->execute([
            ':status'    => $finalStatus,
            ':results'   => json_encode($results, JSON_UNESCAPED_SLASHES),
            ':completed' => gmdate(DATE_ATOM),
            ':id'        => $runId,
        ]);

        // Auto-extract learnings from the full pipeline
        $pipelineSummary = implode("\n", array_map(fn($r) => "[{$r['label']}] " . mb_substr(json_encode($r['output'] ?? $r['error'] ?? ''), 0, 200), $results));
        $this->memoryEngine->extractAndSaveLearnings(
            "pipeline:{$templateId}",
            json_encode($variables),
            $pipelineSummary,
        );

        return [
            'pipeline_id'   => $pipelineId,
            'run_id'        => $runId,
            'name'          => $pipelineName,
            'status'        => $finalStatus,
            'steps'         => $results,
            'next_actions'  => $this->suggestNextActions($steps[count($steps) - 1]['tool'] ?? ''),
        ];
    }

    private function getOrCreatePipeline(string $name, string $templateId, array $steps): int
    {
        $now = gmdate(DATE_ATOM);
        $stmt = $this->pdo->prepare("SELECT id FROM ai_pipelines WHERE name = :name LIMIT 1");
        $stmt->execute([':name' => $name]);
        $id = $stmt->fetchColumn();

        if ($id) {
            $this->pdo->prepare("UPDATE ai_pipelines SET run_count = run_count + 1, last_run_at = :now, updated_at = :now WHERE id = :id")
                ->execute([':now' => $now, ':id' => $id]);
            return (int)$id;
        }

        $stmt = $this->pdo->prepare("INSERT INTO ai_pipelines (name, description, steps_json, status, last_run_at, run_count, created_at, updated_at) VALUES (:name, :desc, :steps, 'active', :now, 1, :now2, :now3)");
        $stmt->execute([
            ':name'  => $name,
            ':desc'  => $templateId,
            ':steps' => json_encode($steps, JSON_UNESCAPED_SLASHES),
            ':now'   => $now,
            ':now2'  => $now,
            ':now3'  => $now,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function resolveStepInput(array $inputMap, array $variables, string $prevOutput, string $prevSummary): array
    {
        $resolved = [];
        foreach ($inputMap as $key => $value) {
            if (!is_string($value)) {
                $resolved[$key] = $value;
                continue;
            }
            $value = str_replace('{{prev_summary}}', $prevSummary, $value);
            $value = str_replace('{{prev.content}}', $prevOutput, $value);
            foreach ($variables as $varName => $varValue) {
                $value = str_replace('{{' . $varName . '}}', (string)$varValue, $value);
            }
            $resolved[$key] = $value;
        }
        return $resolved;
    }

    /**
     * Execute a single tool step and return its output.
     */
    private function executeToolStep(string $toolName, array $input): array
    {
        return match ($toolName) {
            'content'             => $this->contentTools->generateContent($input),
            'blog-post'           => $this->contentTools->blogPostGenerator($input['title'] ?? '', $input['keywords'] ?? '', $input['outline'] ?? null),
            'video-script'        => $this->contentTools->videoScript($input['topic'] ?? '', $input['platform'] ?? 'tiktok', (int)($input['duration'] ?? 60)),
            'caption-batch'       => $this->contentTools->socialCaptionBatch($input['topic'] ?? '', $input['platforms'] ?? ['instagram', 'twitter', 'linkedin'], (int)($input['count'] ?? 3)),
            'repurpose'           => $this->contentTools->repurposeContent($input['content'] ?? '', $input['formats'] ?? ['tweet', 'linkedin_post', 'email']),
            'refine'              => $this->contentTools->refineContent($input['content'] ?? '', $input['action'] ?? 'improve'),
            'headlines'           => $this->contentTools->headlineOptimizer($input['headline'] ?? '', $input['platform'] ?? 'general'),
            'brief'               => $this->contentTools->contentBrief($input['topic'] ?? '', $input['content_type'] ?? 'blog_post', $input['goal'] ?? 'engagement'),
            'workflow'            => $this->contentTools->contentWorkflow($input['topic'] ?? '', $input['goal'] ?? 'engagement', $input['platforms'] ?? ['instagram', 'twitter', 'linkedin'], (int)($input['days'] ?? 7)),
            'research'            => $this->strategyTools->marketResearch($input['audience'] ?? '', $input['goal'] ?? ''),
            'ideas'               => $this->strategyTools->contentIdeas($input['topic'] ?? '', $input['platform'] ?? 'instagram'),
            'persona'             => $this->strategyTools->audiencePersona($input['demographics'] ?? '', $input['behaviors'] ?? ''),
            'competitor-analysis' => $this->strategyTools->competitorAnalysis($input['name'] ?? '', $input['notes'] ?? ''),
            'social-strategy'     => $this->strategyTools->socialStrategy($input['goals'] ?? '', $input['current_state'] ?? ''),
            'calendar'            => $this->strategyTools->scheduleSuggestion($input['objective'] ?? ''),
            'calendar-month'      => $this->strategyTools->contentCalendarMonth($input['month'] ?? date('F Y'), $input['goals'] ?? '', $input['channels'] ?? ''),
            'smart-times'         => $this->strategyTools->smartPostingTime($input['platform'] ?? 'instagram', $input['audience'] ?? '', $input['content_type'] ?? 'social_post'),
            'campaign-optimizer'  => $this->strategyTools->campaignOptimizer($input['campaign_data'] ?? '', $input['goals'] ?? ''),
            'score'               => $this->analysisTools->contentScore($input['content'] ?? '', $input['platform'] ?? 'instagram'),
            'tone-analysis'       => $this->analysisTools->toneAnalysis($input['content'] ?? ''),
            'seo-keywords'        => $this->analysisTools->seoKeywordResearch($input['topic'] ?? '', $input['niche'] ?? ''),
            'hashtags'            => $this->analysisTools->hashtagResearch($input['topic'] ?? '', $input['platform'] ?? 'instagram'),
            'seo-audit'           => $this->analysisTools->seoAudit($input['url'] ?? '', $input['description'] ?? ''),
            'preflight'           => $this->analysisTools->preFlightCheck($input['content'] ?? '', $input['platform'] ?? 'general'),
            'predict'             => $this->analysisTools->predictPerformance($input['content'] ?? '', $input['platform'] ?? 'instagram'),
            default               => ['error' => "Unknown tool: {$toolName}"],
        };
    }

    /* ================================================================== */
    /*  PIPELINE HISTORY                                                   */
    /* ================================================================== */

    public function getRecentRuns(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.id, r.pipeline_id, p.name, r.status, r.steps_completed, r.steps_total, r.started_at, r.completed_at
             FROM ai_pipeline_runs r
             JOIN ai_pipelines p ON r.pipeline_id = p.id
             ORDER BY r.started_at DESC LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRunDetails(int $runId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.*, p.name as pipeline_name FROM ai_pipeline_runs r JOIN ai_pipelines p ON r.pipeline_id = p.id WHERE r.id = :id"
        );
        $stmt->execute([':id' => $runId]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$run) return null;
        $run['results'] = json_decode($run['results_json'] ?? '[]', true);
        return $run;
    }
}
