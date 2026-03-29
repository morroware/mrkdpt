<?php

declare(strict_types=1);

/**
 * AiAgentSystem — Multi-agent autonomous task system with human-in-the-loop.
 *
 * Architecture:
 *  - Users describe a high-level goal
 *  - The Planner agent decomposes it into discrete tasks
 *  - Each task is assigned to a specialized agent (researcher, writer, analyst, strategist)
 *  - Agents can be configured with specific providers/models
 *  - Tasks execute sequentially, sharing context through a workspace
 *  - Human approval gates pause execution for review
 *  - All results are stored and feed back into the Brain context
 *
 * Agent Types:
 *  - researcher: Gathers information from internal data, web, and websites
 *  - writer: Creates content (copy, emails, ads, social posts)
 *  - analyst: Analyzes data, scores content, audits performance
 *  - strategist: Builds plans, calendars, campaign strategies
 *  - creative: Generates image prompts, visual concepts, brand assets
 */
final class AiAgentSystem
{
    /** Agent type definitions with default behaviors. */
    private const AGENT_TYPES = [
        'researcher' => [
            'label' => 'Research Agent',
            'icon' => 'search',
            'description' => 'Gathers intelligence from your data, the web, and competitor sites',
            'capabilities' => ['internal_search', 'web_search', 'website_analysis', 'competitor_intel'],
            'default_temperature' => 0.3,
            'system_extra' => 'You are a thorough research agent. Gather specific, data-driven intelligence. Always cite your reasoning and confidence level.',
        ],
        'writer' => [
            'label' => 'Content Agent',
            'icon' => 'edit',
            'description' => 'Creates high-quality marketing copy across all formats',
            'capabilities' => ['content_creation', 'blog_posts', 'social_posts', 'email_copy', 'ad_copy', 'refinement'],
            'default_temperature' => 0.7,
            'system_extra' => 'You are an expert marketing copywriter. Write compelling, on-brand content that drives action. Follow brand voice guidelines strictly.',
        ],
        'analyst' => [
            'label' => 'Analysis Agent',
            'icon' => 'chart',
            'description' => 'Scores content, audits SEO, analyzes performance and sentiment',
            'capabilities' => ['content_scoring', 'seo_audit', 'tone_analysis', 'performance_prediction', 'ab_testing'],
            'default_temperature' => 0.2,
            'system_extra' => 'You are a data-driven marketing analyst. Provide specific scores, metrics, and actionable recommendations. Be precise and evidence-based.',
        ],
        'strategist' => [
            'label' => 'Strategy Agent',
            'icon' => 'compass',
            'description' => 'Builds marketing plans, campaign strategies, and content calendars',
            'capabilities' => ['campaign_planning', 'content_calendar', 'audience_personas', 'competitive_strategy', 'budget_optimization'],
            'default_temperature' => 0.5,
            'system_extra' => 'You are a senior marketing strategist. Create specific, executable plans with timelines, KPIs, and clear ownership. Think big picture but plan granularly.',
        ],
        'creative' => [
            'label' => 'Creative Agent',
            'icon' => 'palette',
            'description' => 'Generates visual concepts, image prompts, and creative direction',
            'capabilities' => ['image_prompts', 'visual_concepts', 'brand_assets', 'creative_direction'],
            'default_temperature' => 0.8,
            'system_extra' => 'You are a creative director. Generate vivid, specific visual concepts and image descriptions. Think about visual storytelling, brand consistency, and audience appeal.',
        ],
    ];

    public function __construct(
        private PDO $pdo,
        private AiService $ai,
        private AiSearchEngine $searchEngine,
        private AiMemoryEngine $memoryEngine,
        private ?AiContentTools $contentTools = null,
        private ?AiAnalysisTools $analysisTools = null,
        private ?AiStrategyTools $strategyTools = null,
    ) {}

    /* ================================================================== */
    /*  AGENT INFO                                                         */
    /* ================================================================== */

    public function getAgentTypes(): array
    {
        return self::AGENT_TYPES;
    }

    /* ================================================================== */
    /*  TASK CREATION — Plan tasks from a goal                             */
    /* ================================================================== */

    /**
     * Create a new agent task from a high-level goal.
     * The planner agent decomposes it into steps.
     *
     * @param string      $goal        The user's high-level objective
     * @param string      $context     Additional context or constraints
     * @param array       $modelConfig Model routing config: ['researcher' => ['provider' => 'openai', 'model' => 'gpt-4.1'], ...]
     * @param bool        $autoApprove If true, skip human approval gates
     * @return array      The created task with its planned steps
     */
    public function createTask(string $goal, string $context = '', array $modelConfig = [], bool $autoApprove = false): array
    {
        $now = gmdate(DATE_ATOM);

        // Use the planner to decompose the goal into agent steps
        $plan = $this->planTask($goal, $context);

        // Store the task
        $stmt = $this->pdo->prepare(
            "INSERT INTO ai_agent_tasks (goal, context, status, plan_json, model_config_json, auto_approve, steps_total, created_at, updated_at)
             VALUES (:goal, :context, 'planned', :plan, :config, :auto, :total, :created, :updated)"
        );
        $stmt->execute([
            ':goal' => $goal,
            ':context' => $context,
            ':plan' => json_encode($plan['steps'], JSON_UNESCAPED_SLASHES),
            ':config' => json_encode($modelConfig, JSON_UNESCAPED_SLASHES),
            ':auto' => $autoApprove ? 1 : 0,
            ':total' => count($plan['steps']),
            ':created' => $now,
            ':updated' => $now,
        ]);
        $taskId = (int)$this->pdo->lastInsertId();

        // Log activity
        $this->memoryEngine->logActivity(
            'agent:plan',
            'agent',
            "Goal: " . mb_substr($goal, 0, 200),
            "Planned " . count($plan['steps']) . " steps: " . implode(' -> ', array_column($plan['steps'], 'agent')),
        );

        return [
            'id' => $taskId,
            'goal' => $goal,
            'status' => 'planned',
            'plan' => $plan,
            'steps_total' => count($plan['steps']),
            'auto_approve' => $autoApprove,
        ];
    }

    /**
     * Use AI to decompose a goal into agent steps.
     */
    private function planTask(string $goal, string $context): array
    {
        $agentDescriptions = '';
        foreach (self::AGENT_TYPES as $type => $info) {
            $caps = implode(', ', $info['capabilities']);
            $agentDescriptions .= "- {$type} ({$info['label']}): {$info['description']}. Capabilities: {$caps}\n";
        }

        $prompt = "Decompose this marketing goal into a sequence of agent tasks.

GOAL: {$goal}
" . ($context !== '' ? "CONTEXT: {$context}\n" : '') . "
AVAILABLE AGENTS:
{$agentDescriptions}

Create 2-6 sequential steps. Each step should use one agent type.
Earlier steps should gather information, later steps should create/execute.

Return ONLY a JSON object with this structure:
{
  \"reasoning\": \"Brief explanation of your task decomposition\",
  \"steps\": [
    {
      \"step\": 1,
      \"agent\": \"researcher\",
      \"title\": \"Research market trends\",
      \"instruction\": \"Detailed instruction for what the agent should do\",
      \"search_sources\": [\"internal\", \"web\"],
      \"search_query\": \"optional search query to pre-fetch context\",
      \"needs_approval\": false
    }
  ]
}

Guidelines:
- Start with research/analysis when the goal needs information gathering
- Use needs_approval: true for steps that create customer-facing content
- Each step's instruction should be specific enough to execute independently
- Include search_query for research steps to pre-fetch relevant data
- The writer agent should be used for content creation steps
- The analyst agent should review content before it goes to approval
Return ONLY valid JSON, no markdown fences.";

        $raw = $this->ai->generateAdvanced(
            $this->ai->buildSystemPrompt('You are a task planning agent. Decompose marketing goals into precise, executable agent steps. Return only valid JSON.'),
            $prompt,
            null,
            null,
            2048,
            0.3,
        );

        $parsed = $this->parseJson($raw);
        if (!$parsed || empty($parsed['steps'])) {
            // Fallback: single-step plan
            return [
                'reasoning' => 'Single-step execution',
                'steps' => [[
                    'step' => 1,
                    'agent' => 'writer',
                    'title' => 'Execute goal',
                    'instruction' => $goal,
                    'needs_approval' => true,
                ]],
            ];
        }

        return $parsed;
    }

    /* ================================================================== */
    /*  TASK EXECUTION                                                     */
    /* ================================================================== */

    /**
     * Execute the next pending step of a task.
     * Returns the step result or a request for human approval.
     */
    public function executeNextStep(int $taskId): array
    {
        $task = $this->getTask($taskId);
        if (!$task) return ['error' => 'Task not found'];
        if ($task['status'] === 'completed') return ['error' => 'Task already completed', 'task' => $task];
        if ($task['status'] === 'awaiting_approval') return ['error' => 'Task is awaiting approval', 'task' => $task];

        $steps = json_decode($task['plan_json'], true) ?: [];
        $results = json_decode($task['results_json'] ?: '[]', true) ?: [];
        $currentStep = (int)$task['steps_completed'];

        if ($currentStep >= count($steps)) {
            $this->updateTaskStatus($taskId, 'completed');
            return ['status' => 'completed', 'task' => $this->getTask($taskId)];
        }

        $step = $steps[$currentStep] ?? null;
        if (!$step) {
            $this->updateTaskStatus($taskId, 'completed');
            return ['status' => 'completed', 'task' => $this->getTask($taskId)];
        }

        // Update task to running
        $this->updateTaskStatus($taskId, 'running');

        $agentType = $step['agent'] ?? 'writer';
        $agentConfig = self::AGENT_TYPES[$agentType] ?? self::AGENT_TYPES['writer'];
        $modelConfig = json_decode($task['model_config_json'] ?: '{}', true) ?: [];

        // Get provider/model for this agent from config
        $agentModelConf = $modelConfig[$agentType] ?? [];
        $provider = $agentModelConf['provider'] ?? null;
        $model = $agentModelConf['model'] ?? null;

        // Build context from previous step results
        $prevContext = $this->buildStepContext($results, $task['goal'], $task['context']);

        // Pre-fetch search context if configured
        $searchContext = '';
        if (!empty($step['search_query'])) {
            $sources = $step['search_sources'] ?? ['internal'];
            $searchResults = $this->searchEngine->search($step['search_query'], $sources);
            $searchContext = $this->searchEngine->buildSearchContext($searchResults);
        }

        $startTime = microtime(true);

        // Execute the agent
        $result = $this->executeAgent(
            $agentType,
            $step['instruction'] ?? $step['title'],
            $prevContext,
            $searchContext,
            $agentConfig,
            $provider,
            $model,
        );

        $durationMs = (int)((microtime(true) - $startTime) * 1000);

        $stepResult = [
            'step' => $currentStep + 1,
            'agent' => $agentType,
            'title' => $step['title'] ?? "Step " . ($currentStep + 1),
            'status' => 'completed',
            'output' => $result,
            'duration_ms' => $durationMs,
            'provider' => $provider ?? $this->ai->getProvider(),
        ];

        $results[] = $stepResult;

        // Log activity
        $this->memoryEngine->logActivity(
            "agent:{$agentType}",
            'agent',
            mb_substr($step['instruction'] ?? $step['title'], 0, 300),
            mb_substr(is_string($result) ? $result : json_encode($result), 0, 800),
            $provider ?? $this->ai->getProvider(),
            $model ?? '',
            $durationMs,
        );

        // Check if this step needs approval
        $needsApproval = !empty($step['needs_approval']) && !$task['auto_approve'];

        $newStatus = $needsApproval ? 'awaiting_approval' : 'running';
        if ($currentStep + 1 >= count($steps) && !$needsApproval) {
            $newStatus = 'completed';
        }

        // Update task
        $stmt = $this->pdo->prepare(
            "UPDATE ai_agent_tasks SET status = :status, steps_completed = :sc, results_json = :results,
             current_step_output = :output, updated_at = :updated WHERE id = :id"
        );
        $stmt->execute([
            ':status' => $newStatus,
            ':sc' => $currentStep + 1,
            ':results' => json_encode($results, JSON_UNESCAPED_SLASHES),
            ':output' => is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_SLASHES),
            ':updated' => gmdate(DATE_ATOM),
            ':id' => $taskId,
        ]);

        // Auto-extract learnings from agent output
        $outputStr = is_string($result) ? $result : json_encode($result);
        $this->memoryEngine->extractAndSaveLearnings(
            "agent:{$agentType}",
            mb_substr($step['instruction'] ?? '', 0, 300),
            mb_substr($outputStr, 0, 1500),
        );

        return [
            'step_result' => $stepResult,
            'status' => $newStatus,
            'needs_approval' => $needsApproval,
            'steps_completed' => $currentStep + 1,
            'steps_total' => count($steps),
            'task' => $this->getTask($taskId),
        ];
    }

    /**
     * Execute all remaining steps of a task (respecting approval gates).
     */
    public function executeAll(int $taskId): array
    {
        $task = $this->getTask($taskId);
        if ($task && in_array($task['status'], ['cancelled', 'completed', 'failed'], true)) {
            return [
                'executed_steps' => [],
                'task' => $task,
                'error' => "Task is already {$task['status']}",
            ];
        }
        $stepsTotal = 0;
        if ($task) {
            $steps = json_decode($task['plan_json'] ?? '[]', true);
            $stepsTotal = is_array($steps) ? count($steps) : 0;
        }
        $maxSteps = max(10, $stepsTotal);
        $executed = [];

        for ($i = 0; $i < $maxSteps; $i++) {
            $result = $this->executeNextStep($taskId);
            $executed[] = $result;

            if (!empty($result['error'])) break;
            if ($result['status'] === 'completed') break;
            if ($result['status'] === 'awaiting_approval') break;
        }

        return [
            'executed_steps' => $executed,
            'task' => $this->getTask($taskId),
        ];
    }

    /**
     * Approve the current pending step and continue execution.
     */
    public function approveStep(int $taskId, string $feedback = ''): array
    {
        $task = $this->getTask($taskId);
        if (!$task || $task['status'] !== 'awaiting_approval') {
            return ['error' => 'Task is not awaiting approval'];
        }

        // Apply feedback if provided
        if ($feedback !== '') {
            $results = json_decode($task['results_json'] ?: '[]', true) ?: [];
            if (!empty($results)) {
                $lastIdx = count($results) - 1;
                $results[$lastIdx]['human_feedback'] = $feedback;
                $this->pdo->prepare("UPDATE ai_agent_tasks SET results_json = :results WHERE id = :id")
                    ->execute([':results' => json_encode($results, JSON_UNESCAPED_SLASHES), ':id' => $taskId]);
            }
        }

        $this->updateTaskStatus($taskId, 'running');

        // Check if there are more steps
        $steps = json_decode($task['plan_json'], true) ?: [];
        if ((int)$task['steps_completed'] >= count($steps)) {
            $this->updateTaskStatus($taskId, 'completed');
            return ['status' => 'completed', 'task' => $this->getTask($taskId)];
        }

        // Execute next step
        return $this->executeNextStep($taskId);
    }

    /**
     * Reject/revise the current pending step.
     */
    public function rejectStep(int $taskId, string $reason = ''): array
    {
        $task = $this->getTask($taskId);
        if (!$task || $task['status'] !== 'awaiting_approval') {
            return ['error' => 'Task is not awaiting approval'];
        }

        // Mark in results
        $results = json_decode($task['results_json'] ?: '[]', true) ?: [];
        if (!empty($results)) {
            $lastIdx = count($results) - 1;
            $results[$lastIdx]['status'] = 'rejected';
            $results[$lastIdx]['rejection_reason'] = $reason;
        }

        // Roll back step count so it re-executes
        $stmt = $this->pdo->prepare(
            "UPDATE ai_agent_tasks SET status = 'running', steps_completed = steps_completed - 1,
             results_json = :results, updated_at = :updated WHERE id = :id"
        );
        $stmt->execute([
            ':results' => json_encode($results, JSON_UNESCAPED_SLASHES),
            ':updated' => gmdate(DATE_ATOM),
            ':id' => $taskId,
        ]);

        // Re-execute with revision context
        if ($reason !== '') {
            // Update the step instruction to include the revision
            $steps = json_decode($task['plan_json'], true) ?: [];
            $currentStep = max(0, (int)$task['steps_completed'] - 1);
            if (isset($steps[$currentStep])) {
                $steps[$currentStep]['instruction'] .= "\n\nREVISION REQUESTED: {$reason}";
                $this->pdo->prepare("UPDATE ai_agent_tasks SET plan_json = :plan WHERE id = :id")
                    ->execute([':plan' => json_encode($steps, JSON_UNESCAPED_SLASHES), ':id' => $taskId]);
            }
        }

        return $this->executeNextStep($taskId);
    }

    /**
     * Cancel a task.
     */
    public function cancelTask(int $taskId): array
    {
        $this->updateTaskStatus($taskId, 'cancelled');
        return ['status' => 'cancelled', 'task' => $this->getTask($taskId)];
    }

    /* ================================================================== */
    /*  AGENT EXECUTION                                                    */
    /* ================================================================== */

    /**
     * Execute a single agent with full context and optional tool execution.
     * Agents can now request tool execution via [TOOL:tool_name] tags in their output.
     */
    private function executeAgent(
        string $agentType,
        string $instruction,
        string $prevContext,
        string $searchContext,
        array $agentConfig,
        ?string $provider,
        ?string $model,
    ): string {
        $toolList = $this->getAvailableToolsList();

        $systemParts = [
            $this->ai->buildSystemPrompt($agentConfig['system_extra']),
        ];

        // Give agents awareness of available tools
        $systemParts[] = "\nAVAILABLE TOOLS YOU CAN REQUEST:
To execute an AI tool, include a tool request block in your response:
[TOOL:tool_name]
{\"param1\": \"value1\", \"param2\": \"value2\"}
[/TOOL]

Available tools: {$toolList}

Use tools when they would improve your output quality. The system will execute them and include results.
You can request up to 2 tools per step. Include tool requests naturally within your response.";

        if ($prevContext !== '') {
            $systemParts[] = "\nCONTEXT FROM PREVIOUS STEPS:\n{$prevContext}";
        }

        if ($searchContext !== '') {
            $systemParts[] = "\nSEARCH RESULTS:\n{$searchContext}";
        }

        $system = implode("\n", $systemParts);
        $temperature = $agentConfig['default_temperature'] ?? 0.7;

        $response = $this->ai->generateAdvanced(
            $system,
            $instruction,
            $provider,
            $model,
            4096,
            $temperature,
        );

        // Process any tool requests in the response
        $response = $this->processToolRequests($response);

        return $response;
    }

    /**
     * Parse and execute tool requests embedded in agent output.
     */
    private function processToolRequests(string $response): string
    {
        $maxTools = 2;
        $toolsExecuted = 0;

        $response = preg_replace_callback(
            '/\[TOOL:(\w[\w-]*)\]\s*(\{(?:[^{}]|\{(?:[^{}]|\{[^{}]*\})*\})*\})\s*\[\/TOOL\]/s',
            function ($matches) use (&$toolsExecuted, $maxTools) {
                if ($toolsExecuted >= $maxTools) {
                    return $matches[0] . "\n[Tool execution skipped — max {$maxTools} tools per step]";
                }

                $toolName = trim($matches[1]);
                $paramsJson = trim($matches[2]);
                $params = json_decode($paramsJson, true) ?: [];

                $result = $this->memoryEngine->executeToolByName(
                    $toolName,
                    $params,
                    $this->contentTools,
                    $this->analysisTools,
                    $this->strategyTools,
                );

                $toolsExecuted++;

                if ($result === null) {
                    return $matches[0] . "\n[Tool '{$toolName}' not found or failed]";
                }

                $resultStr = is_array($result)
                    ? mb_substr(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 0, 2000)
                    : mb_substr((string)$result, 0, 2000);

                return $matches[0] . "\n\n**Tool Result ({$toolName}):**\n{$resultStr}";
            },
            $response
        );

        return $response;
    }

    private function getAvailableToolsList(): string
    {
        $tools = [
            'content' => 'Generate marketing content',
            'blog-post' => 'Write a blog post',
            'ideas' => 'Generate content ideas',
            'research' => 'Market research',
            'persona' => 'Create audience persona',
            'competitor-analysis' => 'Analyze a competitor',
            'score' => 'Score content quality',
            'tone-analysis' => 'Analyze content tone',
            'seo-keywords' => 'SEO keyword research',
            'headlines' => 'Generate headline variations',
            'hashtags' => 'Research hashtags',
            'smart-times' => 'Optimal posting times',
        ];

        return implode(', ', array_map(fn($k, $v) => "{$k} ({$v})", array_keys($tools), $tools));
    }

    /**
     * Build context string from previous step results.
     */
    private function buildStepContext(array $results, string $goal, string $taskContext): string
    {
        if (empty($results)) return '';

        $parts = ["TASK GOAL: {$goal}"];
        if ($taskContext !== '') {
            $parts[] = "TASK CONTEXT: {$taskContext}";
        }

        $parts[] = "\nPREVIOUS AGENT OUTPUTS:";
        foreach ($results as $r) {
            $agent = $r['agent'] ?? 'unknown';
            $title = $r['title'] ?? "Step {$r['step']}";
            $output = is_string($r['output']) ? $r['output'] : json_encode($r['output']);
            $truncated = mb_substr($output, 0, 2000);
            $parts[] = "\n[{$agent}] {$title}:\n{$truncated}";

            if (!empty($r['human_feedback'])) {
                $parts[] = "HUMAN FEEDBACK: {$r['human_feedback']}";
            }
        }

        return implode("\n", $parts);
    }

    /* ================================================================== */
    /*  TASK QUERIES                                                       */
    /* ================================================================== */

    public function getTask(int $taskId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM ai_agent_tasks WHERE id = :id");
        $stmt->execute([':id' => $taskId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getRecentTasks(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, goal, status, steps_completed, steps_total, auto_approve, created_at, updated_at
             FROM ai_agent_tasks ORDER BY created_at DESC LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTaskDetails(int $taskId): ?array
    {
        $task = $this->getTask($taskId);
        if (!$task) return null;

        $task['plan'] = json_decode($task['plan_json'] ?: '[]', true);
        $task['results'] = json_decode($task['results_json'] ?: '[]', true);
        $task['model_config'] = json_decode($task['model_config_json'] ?: '{}', true);
        return $task;
    }

    /* ================================================================== */
    /*  HELPERS                                                            */
    /* ================================================================== */

    private function updateTaskStatus(int $taskId, string $status): void
    {
        $extra = '';
        if ($status === 'completed') {
            $extra = ', completed_at = :completed';
        }
        $stmt = $this->pdo->prepare("UPDATE ai_agent_tasks SET status = :status, updated_at = :updated{$extra} WHERE id = :id");
        $params = [':status' => $status, ':updated' => gmdate(DATE_ATOM), ':id' => $taskId];
        if ($status === 'completed') {
            $params[':completed'] = gmdate(DATE_ATOM);
        }
        $stmt->execute($params);
    }

    private function parseJson(string $raw): ?array
    {
        $raw = trim($raw);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $raw, $m)) {
            $raw = trim($m[1]);
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
