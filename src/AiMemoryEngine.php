<?php

declare(strict_types=1);

/**
 * AiMemoryEngine — Self-aware memory system for the AI marketing department.
 *
 * Responsibilities:
 *  1. Log every AI tool invocation (activity log)
 *  2. Auto-extract learnings from AI outputs and store them
 *  3. Provide smart, relevance-weighted context to all AI tools
 *  4. Track performance feedback (what content performed well/poorly)
 *  5. Decay stale memories and reinforce validated ones
 *  6. Build situational awareness context (date, deadlines, active campaigns, recent activity)
 */
final class AiMemoryEngine
{
    public function __construct(
        private PDO $pdo,
        private ?AiService $ai = null,
    ) {}

    /* ================================================================== */
    /*  ACTIVITY LOGGING                                                   */
    /* ================================================================== */

    /**
     * Log an AI tool invocation. Returns the activity log ID.
     */
    public function logActivity(
        string $toolName,
        string $category,
        string $inputSummary,
        string $outputSummary,
        string $provider = '',
        string $model = '',
        int    $durationMs = 0,
        array  $metadata = [],
    ): int {
        $stmt = $this->pdo->prepare("INSERT INTO ai_activity_log
            (tool_name, tool_category, input_summary, output_summary, provider, model, duration_ms, metadata_json, created_at)
            VALUES (:tool, :cat, :input, :output, :provider, :model, :dur, :meta, :created)");
        $stmt->execute([
            ':tool'     => $toolName,
            ':cat'      => $category,
            ':input'    => mb_substr($inputSummary, 0, 500),
            ':output'   => mb_substr($outputSummary, 0, 2000),
            ':provider' => $provider,
            ':model'    => $model,
            ':dur'      => $durationMs,
            ':meta'     => json_encode($metadata, JSON_UNESCAPED_SLASHES),
            ':created'  => gmdate(DATE_ATOM),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Get recent AI activity for context injection.
     */
    public function getRecentActivity(int $limit = 15, ?string $category = null): array
    {
        $sql = "SELECT tool_name, tool_category, input_summary, output_summary, provider, created_at
                FROM ai_activity_log";
        $params = [];
        if ($category !== null) {
            $sql .= " WHERE tool_category = :cat";
            $params[':cat'] = $category;
        }
        $sql .= " ORDER BY created_at DESC LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get activity stats for a time period.
     */
    public function getActivityStats(int $days = 7): array
    {
        $since = gmdate('Y-m-d\TH:i:s\Z', strtotime("-{$days} days"));

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM ai_activity_log WHERE created_at >= :since");
        $stmt->execute([':since' => $since]);
        $total = (int)$stmt->fetchColumn();

        $stmt = $this->pdo->prepare("SELECT tool_name, COUNT(*) as count FROM ai_activity_log WHERE created_at >= :since GROUP BY tool_name ORDER BY count DESC LIMIT 10");
        $stmt->execute([':since' => $since]);
        $byTool = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->pdo->prepare("SELECT tool_category, COUNT(*) as count FROM ai_activity_log WHERE created_at >= :since GROUP BY tool_category ORDER BY count DESC");
        $stmt->execute([':since' => $since]);
        $byCategory = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->pdo->prepare("SELECT provider, COUNT(*) as count FROM ai_activity_log WHERE created_at >= :since AND provider != '' GROUP BY provider ORDER BY count DESC");
        $stmt->execute([':since' => $since]);
        $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'total_calls'  => $total,
            'by_tool'      => $byTool,
            'by_category'  => $byCategory,
            'by_provider'  => $providers,
            'period_days'  => $days,
        ];
    }

    /* ================================================================== */
    /*  AUTO-LEARNING: Extract insights from AI outputs                    */
    /* ================================================================== */

    /**
     * Extract learnings from an AI tool's output and save them automatically.
     * Uses the AI itself to identify key insights worth remembering.
     */
    public function extractAndSaveLearnings(
        string $toolName,
        string $inputContext,
        string $output,
        int    $activityId = 0,
    ): array {
        if ($this->ai === null || mb_strlen($output) < 100) {
            return [];
        }

        // Don't extract from refinement/simple tools
        $skipTools = ['refine', 'headlines', 'hashtags', 'subject-lines', 'localize', 'image-prompts', 'smart-utm'];
        if (in_array($toolName, $skipTools, true)) {
            return [];
        }

        // Rate limit: max 1 extraction per tool per 5 minutes
        $recentExtraction = $this->pdo->prepare(
            "SELECT COUNT(*) FROM ai_learnings WHERE source_tool = :tool AND created_at >= :since"
        );
        $fiveMinAgo = gmdate(DATE_ATOM, strtotime('-5 minutes'));
        $recentExtraction->execute([':tool' => $toolName, ':since' => $fiveMinAgo]);
        if ((int)$recentExtraction->fetchColumn() > 0) {
            return [];
        }

        $truncatedOutput = mb_substr($output, 0, 1500);
        $extractionPrompt = "Analyze this AI-generated marketing output and extract 1-3 KEY LEARNINGS that would be valuable to remember for future marketing decisions. These should be specific, actionable insights — not generic advice.

TOOL USED: {$toolName}
INPUT CONTEXT: {$inputContext}

OUTPUT TO ANALYZE:
{$truncatedOutput}

Return ONLY a JSON array of objects, each with:
- \"category\": one of [\"audience\", \"content\", \"strategy\", \"performance\", \"brand\", \"competitor\", \"channel\", \"timing\"]
- \"insight\": the specific learning (1-2 sentences, be concrete)
- \"confidence\": 0.5 to 1.0 (how reliable/actionable this insight is)

If nothing worth remembering, return an empty array [].
Return ONLY valid JSON, no markdown fences.";

        try {
            $raw = $this->ai->generateAdvanced(
                'You are a marketing intelligence analyst. Extract only genuinely useful, specific insights. Skip generic marketing advice.',
                $extractionPrompt,
                null,
                null,
                1024,
                0.3,
            );

            $learnings = $this->parseJsonFromResponse($raw);
            if (!is_array($learnings)) {
                return [];
            }

            $saved = [];
            $now = gmdate(DATE_ATOM);
            foreach (array_slice($learnings, 0, 3) as $learning) {
                if (empty($learning['insight']) || !is_string($learning['insight'])) {
                    continue;
                }

                // Check for duplicate/similar existing learnings
                $category = $learning['category'] ?? 'general';
                $insight = trim($learning['insight']);
                $confidence = max(0.3, min(1.0, (float)($learning['confidence'] ?? 0.7)));

                if ($this->isDuplicateLearning($insight, $category)) {
                    $this->reinforceLearning($insight, $category);
                    continue;
                }

                $stmt = $this->pdo->prepare("INSERT INTO ai_learnings
                    (category, insight, confidence, source_tool, source_activity_id, expires_at, created_at, updated_at)
                    VALUES (:cat, :insight, :conf, :tool, :aid, :expires, :created, :updated)");
                $stmt->execute([
                    ':cat'     => $category,
                    ':insight' => $insight,
                    ':conf'    => $confidence,
                    ':tool'    => $toolName,
                    ':aid'     => $activityId ?: null,
                    ':expires' => gmdate(DATE_ATOM, strtotime('+30 days')),
                    ':created' => $now,
                    ':updated' => $now,
                ]);
                $saved[] = ['category' => $category, 'insight' => $insight, 'confidence' => $confidence];
            }
            return $saved;
        } catch (\Throwable $e) {
            error_log("AiMemoryEngine::extractAndSaveLearnings error: " . $e->getMessage());
            return [];
        }
    }

    private function isDuplicateLearning(string $insight, string $category): bool
    {
        // Simple keyword overlap check — find learnings with similar words
        $words = array_unique(array_filter(explode(' ', strtolower(preg_replace('/[^a-z0-9\s]/i', '', $insight)))));
        $keyWords = array_filter($words, fn($w) => mb_strlen($w) > 4);
        if (count($keyWords) < 2) {
            return false;
        }

        $stmt = $this->pdo->prepare("SELECT insight FROM ai_learnings WHERE category = :cat ORDER BY created_at DESC LIMIT 50");
        $stmt->execute([':cat' => $category]);
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($existing as $existingInsight) {
            $existingWords = array_unique(array_filter(explode(' ', strtolower(preg_replace('/[^a-z0-9\s]/i', '', $existingInsight)))));
            $overlap = count(array_intersect($keyWords, $existingWords));
            if ($overlap >= min(3, count($keyWords))) {
                return true;
            }
        }
        return false;
    }

    private function reinforceLearning(string $insight, string $category): void
    {
        // Boost confidence of the most similar existing learning in this category
        // Use keyword matching to find the best match rather than just the most recent
        $keywords = array_filter(array_unique(explode(' ', strtolower(preg_replace('/[^a-zA-Z0-9\s]/', '', $insight)))), fn($w) => strlen($w) > 3);
        $stmt = $this->pdo->prepare("SELECT id, insight FROM ai_learnings WHERE category = :cat");
        $stmt->execute([':cat' => $category]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $bestId = null;
        $bestScore = 0;
        foreach ($rows as $row) {
            $lowerInsight = strtolower($row['insight']);
            $score = 0;
            foreach ($keywords as $kw) {
                if (str_contains($lowerInsight, $kw)) $score++;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = $row['id'];
            }
        }
        $id = $bestId ?: ($rows[0]['id'] ?? null);
        if ($id) {
            $this->pdo->prepare(
                "UPDATE ai_learnings SET times_reinforced = times_reinforced + 1, confidence = MIN(1.0, confidence + 0.05), updated_at = :now WHERE id = :id"
            )->execute([':now' => gmdate(DATE_ATOM), ':id' => $id]);
        }
    }

    /* ================================================================== */
    /*  SMART CONTEXT: Relevance-weighted memory retrieval                 */
    /* ================================================================== */

    /**
     * Get the most relevant learnings for a given context.
     */
    public function getRelevantLearnings(int $limit = 20, ?string $category = null): array
    {
        $now = gmdate(DATE_ATOM);
        $sql = "SELECT id, category, insight, confidence, source_tool, times_reinforced, created_at
                FROM ai_learnings
                WHERE (expires_at IS NULL OR expires_at > :now)";
        $params = [':now' => $now];

        if ($category !== null) {
            $sql .= " AND category = :cat";
            $params[':cat'] = $category;
        }

        $sql .= " ORDER BY (confidence * (1 + MIN(times_reinforced, 50))) DESC, updated_at DESC LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Build the "AI Brain" context string for injection into system prompts.
     * This is the key method that makes the AI self-aware.
     */
    public function buildBrainContext(): string
    {
        $parts = [];

        // 1. Situational awareness
        $parts[] = $this->buildSituationalAwareness();

        // 2. Recent AI activity digest
        $activityDigest = $this->buildActivityDigest();
        if ($activityDigest !== '') {
            $parts[] = $activityDigest;
        }

        // 3. Learned insights (ranked by confidence × reinforcement)
        $learningsCtx = $this->buildLearningsContext();
        if ($learningsCtx !== '') {
            $parts[] = $learningsCtx;
        }

        // 4. Performance feedback
        $perfCtx = $this->buildPerformanceContext();
        if ($perfCtx !== '') {
            $parts[] = $perfCtx;
        }

        return implode("\n\n", array_filter($parts));
    }

    private function buildSituationalAwareness(): string
    {
        $tz = $this->ai?->getTimezone() ?? 'UTC';
        $now = new \DateTime('now', new \DateTimeZone($tz));
        $lines = [
            "SITUATIONAL AWARENESS:",
            "- Current date/time: " . $now->format('l, F j, Y g:i A') . " ({$tz})",
            "- Day of week: " . $now->format('l') . " (plan content timing accordingly)",
        ];

        // Active campaigns with deadlines
        try {
            $campaigns = $this->pdo->query(
                "SELECT name, end_date, objective, status FROM campaigns WHERE status = 'active' ORDER BY end_date ASC LIMIT 5"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($campaigns)) {
                $lines[] = "- Active campaigns:";
                foreach ($campaigns as $c) {
                    $deadline = !empty($c['end_date']) ? " (ends: {$c['end_date']})" : '';
                    $lines[] = "  * {$c['name']}: {$c['objective']}{$deadline}";
                }
            }
        } catch (\PDOException $e) {
            // ignore
        }

        // Upcoming scheduled posts (next 48 hours)
        try {
            $upcoming = $this->pdo->query(
                "SELECT title, platform, scheduled_for FROM posts WHERE status = 'scheduled' AND scheduled_for IS NOT NULL AND scheduled_for <= datetime('now', '+48 hours') ORDER BY scheduled_for ASC LIMIT 5"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($upcoming)) {
                $lines[] = "- Upcoming posts (next 48h):";
                foreach ($upcoming as $u) {
                    $lines[] = "  * \"{$u['title']}\" on {$u['platform']} at {$u['scheduled_for']}";
                }
            }
        } catch (\PDOException $e) {
            // ignore
        }

        // Draft count needing attention
        try {
            $drafts = (int)$this->pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'draft'")->fetchColumn();
            if ($drafts > 0) {
                $lines[] = "- {$drafts} draft posts awaiting review/publish";
            }
        } catch (\PDOException $e) {
            // ignore
        }

        return implode("\n", $lines);
    }

    private function buildActivityDigest(): string
    {
        $recent = $this->getRecentActivity(8);
        if (empty($recent)) {
            return '';
        }

        $lines = ["RECENT AI ACTIVITY (what I've been working on):"];
        foreach ($recent as $a) {
            $ago = $this->timeAgo($a['created_at']);
            $lines[] = "- [{$a['tool_category']}] {$a['tool_name']}: {$a['input_summary']} ({$ago})";
        }
        $lines[] = "Use this context to avoid repeating work and to build on recent outputs.";
        return implode("\n", $lines);
    }

    private function buildLearningsContext(): string
    {
        $learnings = $this->getRelevantLearnings(15);
        if (empty($learnings)) {
            return '';
        }

        $grouped = [];
        foreach ($learnings as $l) {
            $cat = $l['category'];
            if (!isset($grouped[$cat])) {
                $grouped[$cat] = [];
            }
            $reinforced = $l['times_reinforced'] > 1 ? " [confirmed x{$l['times_reinforced']}]" : '';
            $grouped[$cat][] = "- {$l['insight']}{$reinforced}";
        }

        $lines = ["LEARNED INSIGHTS (extracted from past AI work):"];
        foreach ($grouped as $cat => $items) {
            $label = ucfirst($cat);
            $lines[] = "{$label}:";
            foreach ($items as $item) {
                $lines[] = "  {$item}";
            }
        }
        $lines[] = "Apply these learnings to improve output quality. Contradict them only with good reason.";
        return implode("\n", $lines);
    }

    private function buildPerformanceContext(): string
    {
        // Get recent performance feedback to learn what works
        try {
            $stmt = $this->pdo->query(
                "SELECT f.entity_type, f.metric_name, f.metric_value, f.feedback_note, a.tool_name, a.input_summary
                 FROM ai_performance_feedback f
                 LEFT JOIN ai_activity_log a ON f.activity_id = a.id
                 ORDER BY f.created_at DESC LIMIT 10"
            );
            $feedback = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return '';
        }

        if (empty($feedback)) {
            return '';
        }

        $lines = ["PERFORMANCE FEEDBACK (what worked/didn't):"];
        foreach ($feedback as $f) {
            $tool = $f['tool_name'] ? " (from {$f['tool_name']})" : '';
            $note = $f['feedback_note'] ? " — {$f['feedback_note']}" : '';
            $lines[] = "- {$f['entity_type']} {$f['metric_name']}: {$f['metric_value']}{$tool}{$note}";
        }
        $lines[] = "Use this feedback to optimize future content for better performance.";
        return implode("\n", $lines);
    }

    /* ================================================================== */
    /*  PERFORMANCE FEEDBACK                                               */
    /* ================================================================== */

    /**
     * Record performance feedback for AI-generated content.
     */
    public function recordPerformanceFeedback(
        string $entityType,
        int    $entityId,
        string $metricName,
        float  $metricValue,
        string $feedbackNote = '',
        ?int   $activityId = null,
    ): void {
        $stmt = $this->pdo->prepare("INSERT INTO ai_performance_feedback
            (entity_type, entity_id, activity_id, metric_name, metric_value, feedback_note, created_at)
            VALUES (:type, :eid, :aid, :metric, :val, :note, :created)");
        $stmt->execute([
            ':type'    => $entityType,
            ':eid'     => $entityId,
            ':aid'     => $activityId,
            ':metric'  => $metricName,
            ':val'     => $metricValue,
            ':note'    => $feedbackNote,
            ':created' => gmdate(DATE_ATOM),
        ]);
    }

    /**
     * Auto-capture performance data from published posts.
     * Called by cron or manually to feed results back into AI context.
     */
    public function capturePostPerformance(): int
    {
        $captured = 0;
        try {
            // Find published posts with AI scores that haven't been captured yet
            $stmt = $this->pdo->query(
                "SELECT p.id, p.title, p.platform, p.ai_score, p.published_at
                 FROM posts p
                 WHERE p.status = 'published' AND p.ai_score > 0
                 AND p.id NOT IN (SELECT entity_id FROM ai_performance_feedback WHERE entity_type = 'post')
                 ORDER BY p.published_at DESC LIMIT 20"
            );
            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($posts as $post) {
                $this->recordPerformanceFeedback(
                    'post',
                    (int)$post['id'],
                    'ai_score',
                    (float)$post['ai_score'],
                    "Published on {$post['platform']}: \"{$post['title']}\"",
                );
                $captured++;
            }
        } catch (\PDOException $e) {
            error_log("AiMemoryEngine::capturePostPerformance error: " . $e->getMessage());
        }
        return $captured;
    }

    /* ================================================================== */
    /*  MEMORY MAINTENANCE                                                 */
    /* ================================================================== */

    /**
     * Decay old learnings and clean up expired memories.
     */
    public function maintenance(): array
    {
        $now = gmdate(DATE_ATOM);
        $results = [];

        // Remove expired learnings
        $stmt = $this->pdo->prepare("DELETE FROM ai_learnings WHERE expires_at IS NOT NULL AND expires_at < :now");
        $stmt->execute([':now' => $now]);
        $results['expired_learnings_removed'] = $stmt->rowCount();

        // Decay confidence of old, unreinforced learnings (older than 14 days, never reinforced)
        $twoWeeksAgo = gmdate(DATE_ATOM, strtotime('-14 days'));
        $stmt = $this->pdo->prepare(
            "UPDATE ai_learnings SET confidence = MAX(0.3, confidence - 0.1), updated_at = :now
             WHERE times_reinforced <= 1 AND updated_at < :threshold AND confidence > 0.3"
        );
        $stmt->execute([':now' => $now, ':threshold' => $twoWeeksAgo]);
        $results['learnings_decayed'] = $stmt->rowCount();

        // Clean old activity logs (keep last 500)
        $this->pdo->exec("DELETE FROM ai_activity_log WHERE id NOT IN (SELECT id FROM ai_activity_log ORDER BY created_at DESC LIMIT 500)");

        // Remove expired shared memories
        $stmt = $this->pdo->prepare("DELETE FROM ai_shared_memory WHERE expires_at IS NOT NULL AND expires_at < :now");
        $stmt->execute([':now' => $now]);
        $results['expired_memories_removed'] = $stmt->rowCount();

        return $results;
    }

    /* ================================================================== */
    /*  AI SELF-REFLECTION                                                 */
    /* ================================================================== */

    /**
     * Generate an AI self-assessment of its current knowledge and gaps.
     */
    public function selfReflect(): array
    {
        $stats = $this->getActivityStats(30);
        $learningCount = (int)$this->pdo->query("SELECT COUNT(*) FROM ai_learnings")->fetchColumn();
        $memoryCount = (int)$this->pdo->query("SELECT COUNT(*) FROM ai_shared_memory")->fetchColumn();
        $feedbackCount = (int)$this->pdo->query("SELECT COUNT(*) FROM ai_performance_feedback")->fetchColumn();

        $learningsByCategory = $this->pdo->query(
            "SELECT category, COUNT(*) as count, AVG(confidence) as avg_confidence FROM ai_learnings GROUP BY category ORDER BY count DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $topReinforced = $this->pdo->query(
            "SELECT insight, category, times_reinforced, confidence FROM ai_learnings ORDER BY times_reinforced DESC LIMIT 5"
        )->fetchAll(PDO::FETCH_ASSOC);

        return [
            'activity_stats'       => $stats,
            'total_learnings'      => $learningCount,
            'total_memories'       => $memoryCount,
            'total_feedback'       => $feedbackCount,
            'learnings_by_category' => $learningsByCategory,
            'strongest_learnings'  => $topReinforced,
            'knowledge_gaps'       => $this->identifyKnowledgeGaps($learningsByCategory),
        ];
    }

    private function identifyKnowledgeGaps(array $learningsByCategory): array
    {
        $allCategories = ['audience', 'content', 'strategy', 'performance', 'brand', 'competitor', 'channel', 'timing'];
        $covered = array_column($learningsByCategory, 'count', 'category');
        $gaps = [];
        foreach ($allCategories as $cat) {
            if (($covered[$cat] ?? 0) < 2) {
                $gaps[] = $cat;
            }
        }
        return $gaps;
    }

    /* ================================================================== */
    /*  BRAIN INITIALIZATION — Seed from onboarding data                   */
    /* ================================================================== */

    /**
     * Initialize the brain from onboarding profile data.
     * Seeds foundational learnings so the AI is useful from day one.
     */
    public function initializeFromOnboarding(array $profile): array
    {
        $seeded = [];
        $now = gmdate(DATE_ATOM);
        $neverExpires = gmdate(DATE_ATOM, strtotime('+5 years'));

        // Build foundational learnings from profile data
        $foundations = [];

        if (!empty($profile['target_audience'])) {
            $foundations[] = ['audience', "Our target audience is: {$profile['target_audience']}.", 0.95];
        }
        if (!empty($profile['products_services'])) {
            $foundations[] = ['brand', "Our products/services: {$profile['products_services']}.", 0.95];
        }
        if (!empty($profile['unique_selling_points'])) {
            $foundations[] = ['brand', "Our key differentiators: {$profile['unique_selling_points']}.", 0.95];
        }
        if (!empty($profile['marketing_goals'])) {
            $foundations[] = ['strategy', "Primary marketing goals: {$profile['marketing_goals']}.", 0.9];
        }
        if (!empty($profile['active_platforms'])) {
            $foundations[] = ['channel', "Active marketing channels: {$profile['active_platforms']}.", 0.9];
        }
        if (!empty($profile['budget_range'])) {
            $foundations[] = ['strategy', "Marketing budget range: {$profile['budget_range']}.", 0.85];
        }
        if (!empty($profile['competitors'])) {
            $foundations[] = ['competitor', "Known competitors: {$profile['competitors']}.", 0.85];
        }
        if (!empty($profile['business_description'])) {
            $foundations[] = ['brand', "Business overview: " . mb_substr($profile['business_description'], 0, 300), 0.95];
        }

        foreach ($foundations as [$category, $insight, $confidence]) {
            if ($this->isDuplicateLearning($insight, $category)) {
                continue;
            }
            $stmt = $this->pdo->prepare("INSERT INTO ai_learnings
                (category, insight, confidence, source_tool, times_reinforced, expires_at, created_at, updated_at)
                VALUES (:cat, :insight, :conf, 'onboarding', 3, :expires, :created, :updated)");
            $stmt->execute([
                ':cat' => $category,
                ':insight' => $insight,
                ':conf' => $confidence,
                ':expires' => $neverExpires,
                ':created' => $now,
                ':updated' => $now,
            ]);
            $seeded[] = ['category' => $category, 'insight' => $insight];
        }

        // If AI is available, also generate strategic insights from the profile
        if ($this->ai !== null && count($seeded) >= 3) {
            $profileSummary = json_encode($profile, JSON_UNESCAPED_SLASHES);
            try {
                $raw = $this->ai->generateAdvanced(
                    'You are a senior marketing strategist analyzing a new client profile. Extract 3-5 strategic insights that would guide all future marketing work. Be specific and actionable.',
                    "Analyze this business profile and extract key strategic marketing insights:\n\n{$profileSummary}\n\nReturn ONLY a JSON array of objects with: category (one of: audience, content, strategy, performance, brand, competitor, channel, timing), insight (1-2 sentences, specific), confidence (0.7-0.95).\nReturn ONLY valid JSON.",
                    null,
                    null,
                    1024,
                    0.3,
                );
                $insights = $this->parseJsonFromResponse($raw);
                if (is_array($insights)) {
                    foreach (array_slice($insights, 0, 5) as $i) {
                        if (empty($i['insight'])) continue;
                        $cat = $i['category'] ?? 'strategy';
                        $ins = trim($i['insight']);
                        if ($this->isDuplicateLearning($ins, $cat)) continue;
                        $stmt = $this->pdo->prepare("INSERT INTO ai_learnings
                            (category, insight, confidence, source_tool, times_reinforced, expires_at, created_at, updated_at)
                            VALUES (:cat, :insight, :conf, 'onboarding-ai', 2, :expires, :created, :updated)");
                        $stmt->execute([
                            ':cat' => $cat,
                            ':insight' => $ins,
                            ':conf' => max(0.5, min(0.95, (float)($i['confidence'] ?? 0.8))),
                            ':expires' => $neverExpires,
                            ':created' => $now,
                            ':updated' => $now,
                        ]);
                        $seeded[] = ['category' => $cat, 'insight' => $ins];
                    }
                }
            } catch (\Throwable $e) {
                error_log("AiMemoryEngine::initializeFromOnboarding AI extraction error: " . $e->getMessage());
            }
        }

        $this->logActivity('brain:initialize', 'brain', 'Initialized from onboarding profile', 'Seeded ' . count($seeded) . ' foundational learnings');

        return ['seeded' => count($seeded), 'learnings' => $seeded];
    }

    /* ================================================================== */
    /*  DAILY BRIEFING — Smart morning briefing for the user               */
    /* ================================================================== */

    /**
     * Generate an AI-powered daily briefing with priorities, insights, and actions.
     * This is the "what should I focus on today" feature.
     */
    public function generateDailyBriefing(): array
    {
        if ($this->ai === null) {
            return ['error' => 'AI service not available'];
        }

        // Gather comprehensive context
        $context = $this->gatherBriefingContext();

        $prompt = "Generate a concise daily marketing briefing based on this data.

CURRENT STATE:
{$context}

Return a JSON object with:
{
  \"greeting\": \"Brief motivational opening (1 sentence)\",
  \"priority_actions\": [
    {
      \"priority\": \"high|medium|low\",
      \"title\": \"Short action title\",
      \"description\": \"Why this matters and what to do (1-2 sentences)\",
      \"action_type\": \"publish_draft|review_content|create_content|send_email|check_analytics|engage_audience|run_campaign|optimize_strategy\",
      \"entity_type\": \"post|campaign|email|null\",
      \"entity_id\": null
    }
  ],
  \"insights\": [
    {
      \"type\": \"opportunity|warning|celebration|tip\",
      \"message\": \"Specific insight based on real data (1-2 sentences)\"
    }
  ],
  \"focus_areas\": [\"area1\", \"area2\", \"area3\"],
  \"brain_growth_tip\": \"One specific suggestion to help the AI Brain learn more about the business\"
}

Rules:
- 3-6 priority actions, ordered by importance
- 2-4 insights based on REAL data patterns you can see
- Be specific — reference actual drafts, campaigns, numbers
- Don't invent data — only reference what's in the context
Return ONLY valid JSON.";

        try {
            $raw = $this->ai->generateAdvanced(
                $this->ai->buildSystemPrompt('You are the AI marketing department lead providing a daily briefing. Be concise, specific, and actionable. Reference real data.'),
                $prompt,
                null,
                null,
                2048,
                0.4,
            );

            $briefing = $this->parseJsonFromResponse($raw);
            if (!$briefing || !isset($briefing['priority_actions'])) {
                return ['error' => 'Failed to parse briefing', 'raw' => mb_substr($raw, 0, 500)];
            }

            $this->logActivity('brain:briefing', 'brain', 'Generated daily briefing', count($briefing['priority_actions']) . ' actions, ' . count($briefing['insights'] ?? []) . ' insights');

            return $briefing;
        } catch (\Throwable $e) {
            return ['error' => 'Briefing generation failed: ' . $e->getMessage()];
        }
    }

    private function gatherBriefingContext(): string
    {
        $parts = [];

        // Date/time
        $tz = $this->ai?->getTimezone() ?? 'UTC';
        $now = new \DateTime('now', new \DateTimeZone($tz));
        $parts[] = "DATE: " . $now->format('l, F j, Y g:i A') . " ({$tz})";

        // Draft posts needing attention
        try {
            $drafts = $this->pdo->query("SELECT id, title, platform, created_at FROM posts WHERE status = 'draft' ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
            if ($drafts) {
                $parts[] = "DRAFT POSTS (" . count($drafts) . " total):";
                foreach ($drafts as $d) $parts[] = "- #{$d['id']} \"{$d['title']}\" ({$d['platform']}) created {$d['created_at']}";
            }
        } catch (\PDOException $e) {}

        // Scheduled posts (next 48h)
        try {
            $scheduled = $this->pdo->query("SELECT id, title, platform, scheduled_for FROM posts WHERE status = 'scheduled' AND scheduled_for IS NOT NULL AND scheduled_for <= datetime('now', '+48 hours') ORDER BY scheduled_for LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
            if ($scheduled) {
                $parts[] = "UPCOMING SCHEDULED POSTS:";
                foreach ($scheduled as $s) $parts[] = "- #{$s['id']} \"{$s['title']}\" on {$s['platform']} at {$s['scheduled_for']}";
            }
        } catch (\PDOException $e) {}

        // Active campaigns
        try {
            $campaigns = $this->pdo->query("SELECT id, name, objective, end_date, budget, spend_to_date FROM campaigns WHERE status = 'active' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            if ($campaigns) {
                $parts[] = "ACTIVE CAMPAIGNS:";
                foreach ($campaigns as $c) {
                    $budget = $c['budget'] ? " (budget: \${$c['budget']}, spent: \${$c['spend_to_date']})" : '';
                    $deadline = $c['end_date'] ? " ends {$c['end_date']}" : '';
                    $parts[] = "- #{$c['id']} \"{$c['name']}\": {$c['objective']}{$budget}{$deadline}";
                }
            }
        } catch (\PDOException $e) {}

        // Content stats
        try {
            $pubCount = (int)$this->pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'published'")->fetchColumn();
            $draftCount = (int)$this->pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'draft'")->fetchColumn();
            $schCount = (int)$this->pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'scheduled'")->fetchColumn();
            $parts[] = "CONTENT STATS: {$pubCount} published, {$draftCount} drafts, {$schCount} scheduled";
        } catch (\PDOException $e) {}

        // Email campaigns
        try {
            $emails = $this->pdo->query("SELECT id, name, status FROM email_campaigns WHERE status IN ('draft', 'scheduled') LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            if ($emails) {
                $parts[] = "EMAIL CAMPAIGNS:";
                foreach ($emails as $e) $parts[] = "- #{$e['id']} \"{$e['name']}\" ({$e['status']})";
            }
        } catch (\PDOException $e) {}

        // Subscriber count
        try {
            $subs = (int)$this->pdo->query("SELECT COUNT(*) FROM subscribers WHERE status = 'active'")->fetchColumn();
            if ($subs > 0) $parts[] = "ACTIVE SUBSCRIBERS: {$subs}";
        } catch (\PDOException $e) {}

        // Recent performance (AI scores)
        try {
            $recentPerf = $this->pdo->query("SELECT AVG(ai_score) as avg_score, COUNT(*) as count FROM posts WHERE status = 'published' AND ai_score > 0 AND published_at >= datetime('now', '-7 days')")->fetch(PDO::FETCH_ASSOC);
            if ($recentPerf && $recentPerf['count'] > 0) {
                $parts[] = "RECENT PERFORMANCE: {$recentPerf['count']} posts published in 7 days, avg AI score: " . round($recentPerf['avg_score'] ?? 0);
            }
        } catch (\PDOException $e) {}

        // AI Brain learnings summary
        $learnings = $this->getRelevantLearnings(8);
        if ($learnings) {
            $parts[] = "TOP AI BRAIN INSIGHTS:";
            foreach ($learnings as $l) {
                $parts[] = "- [{$l['category']}] {$l['insight']}";
            }
        }

        // Knowledge gaps
        $reflection = $this->selfReflect();
        $gaps = $reflection['knowledge_gaps'] ?? [];
        if ($gaps) {
            $parts[] = "BRAIN KNOWLEDGE GAPS: " . implode(', ', $gaps);
        }

        return implode("\n", $parts);
    }

    /* ================================================================== */
    /*  PROACTIVE RECOMMENDATIONS — Smart action suggestions               */
    /* ================================================================== */

    /**
     * Generate proactive recommendations based on current marketing state.
     * These are higher-level strategic recommendations vs daily actions.
     */
    public function generateProactiveRecommendations(): array
    {
        if ($this->ai === null) {
            return [];
        }

        $context = $this->gatherBriefingContext();
        $prompt = "As the AI marketing department, analyze this marketing data and generate 3-5 proactive recommendations. These should be strategic opportunities the business should act on.

{$context}

Return a JSON array of objects with:
- type: \"quick_win\" | \"strategic\" | \"experiment\" | \"optimization\" | \"growth\"
- title: Short title (5-8 words)
- description: What to do and why (2-3 sentences)
- impact: \"high\" | \"medium\" | \"low\"
- effort: \"low\" | \"medium\" | \"high\"
- category: \"content\" | \"email\" | \"social\" | \"campaign\" | \"seo\" | \"audience\"
- suggested_tool: The AI tool to use (e.g. \"content\", \"research\", \"ideas\", \"persona\", \"calendar-month\", \"seo-keywords\")
- auto_executable: true if this can be done entirely by AI, false if human input needed

Prioritize quick wins (high impact, low effort). Be specific to this business.
Return ONLY valid JSON array.";

        try {
            $raw = $this->ai->generateAdvanced(
                'You are a proactive AI marketing strategist. Generate specific, actionable recommendations. Prioritize quick wins.',
                $prompt,
                null,
                null,
                1500,
                0.5,
            );

            $recs = $this->parseJsonFromResponse($raw);
            if (!is_array($recs)) return [];

            $this->logActivity('brain:recommendations', 'brain', 'Generated proactive recommendations', count($recs) . ' recommendations');

            return array_slice($recs, 0, 5);
        } catch (\Throwable $e) {
            error_log("AiMemoryEngine::generateProactiveRecommendations error: " . $e->getMessage());
            return [];
        }
    }

    /* ================================================================== */
    /*  KNOWLEDGE BASE — Structured, accumulated knowledge                 */
    /* ================================================================== */

    /**
     * Get the full knowledge base: learnings + shared memory + performance data,
     * organized into a structured, queryable format.
     */
    public function getKnowledgeBase(): array
    {
        $allCategories = ['audience', 'content', 'strategy', 'performance', 'brand', 'competitor', 'channel', 'timing'];

        // Get all learnings grouped by category
        $learnings = $this->pdo->query(
            "SELECT category, insight, confidence, times_reinforced, source_tool, created_at
             FROM ai_learnings
             WHERE expires_at IS NULL OR expires_at > datetime('now')
             ORDER BY category, (confidence * (1 + MIN(times_reinforced, 50))) DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($allCategories as $cat) {
            $grouped[$cat] = [
                'learnings' => [],
                'count' => 0,
                'avg_confidence' => 0,
                'strongest' => null,
            ];
        }

        foreach ($learnings as $l) {
            $cat = $l['category'] ?? 'general';
            if (!isset($grouped[$cat])) {
                $grouped[$cat] = ['learnings' => [], 'count' => 0, 'avg_confidence' => 0, 'strongest' => null];
            }
            $grouped[$cat]['learnings'][] = $l;
            $grouped[$cat]['count']++;
        }

        foreach ($grouped as $cat => &$data) {
            if ($data['count'] > 0) {
                $data['avg_confidence'] = array_sum(array_column($data['learnings'], 'confidence')) / $data['count'];
                $data['strongest'] = $data['learnings'][0] ?? null;
            }
        }
        unset($data);

        // Get shared memories
        $memories = $this->pdo->query(
            "SELECT id, memory_key, content, source, category, created_at FROM ai_shared_memory ORDER BY updated_at DESC LIMIT 50"
        )->fetchAll(PDO::FETCH_ASSOC);

        // Get performance summary
        $perfSummary = $this->pdo->query(
            "SELECT metric_name, AVG(metric_value) as avg_value, COUNT(*) as count
             FROM ai_performance_feedback
             GROUP BY metric_name
             ORDER BY count DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        // Total stats
        $totalLearnings = array_sum(array_column($grouped, 'count'));
        $totalMemories = count($memories);

        return [
            'categories' => $grouped,
            'shared_memories' => $memories,
            'performance_summary' => $perfSummary,
            'total_learnings' => $totalLearnings,
            'total_memories' => $totalMemories,
            'knowledge_completeness' => $this->calculateCompleteness($grouped),
        ];
    }

    private function calculateCompleteness(array $grouped): array
    {
        $scores = [];
        foreach ($grouped as $cat => $data) {
            $count = $data['count'];
            $conf = $data['avg_confidence'];
            // Score: 0-100 based on count (up to 60) and confidence (up to 40)
            $countScore = min(60, $count * 8);
            $confScore = $conf > 0 ? min(40, $conf * 40) : 0;
            $scores[$cat] = min(100, (int)round($countScore + $confScore));
        }
        $scores['overall'] = count($scores) > 0 ? (int)round(array_sum($scores) / count($scores)) : 0;
        return $scores;
    }

    /* ================================================================== */
    /*  SMART LEARNING — Add manual knowledge entries                       */
    /* ================================================================== */

    /**
     * Allow users to manually add knowledge to the brain.
     */
    public function addManualLearning(string $category, string $insight, float $confidence = 0.85): int
    {
        $now = gmdate(DATE_ATOM);
        $stmt = $this->pdo->prepare("INSERT INTO ai_learnings
            (category, insight, confidence, source_tool, times_reinforced, expires_at, created_at, updated_at)
            VALUES (:cat, :insight, :conf, 'manual', 2, :expires, :created, :updated)");
        $stmt->execute([
            ':cat' => $category,
            ':insight' => trim($insight),
            ':conf' => max(0.3, min(1.0, $confidence)),
            ':expires' => gmdate(DATE_ATOM, strtotime('+90 days')),
            ':created' => $now,
            ':updated' => $now,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Execute a specific AI tool by name and return the result.
     * This allows the brain/agents to run tools programmatically.
     */
    public function executeToolByName(
        string $toolName,
        array $input,
        ?AiContentTools $contentTools = null,
        ?AiAnalysisTools $analysisTools = null,
        ?AiStrategyTools $strategyTools = null,
    ): ?array {
        $toolMap = [
            'content' => [$contentTools, 'generateContent'],
            'blog-post' => [$contentTools, 'blogPostGenerator'],
            'ideas' => [$strategyTools, 'contentIdeas'],
            'research' => [$strategyTools, 'marketResearch'],
            'persona' => [$strategyTools, 'audiencePersona'],
            'competitor-analysis' => [$strategyTools, 'competitorAnalysis'],
            'social-strategy' => [$strategyTools, 'socialStrategy'],
            'calendar-month' => [$strategyTools, 'contentCalendarMonth'],
            'seo-keywords' => [$analysisTools, 'seoKeywordResearch'],
            'tone-analysis' => [$analysisTools, 'toneAnalysis'],
            'score' => [$analysisTools, 'contentScore'],
            'headlines' => [$contentTools, 'headlineOptimizer'],
            'hashtags' => [$analysisTools, 'hashtagResearch'],
            'refine' => [$contentTools, 'refineContent'],
            'smart-times' => [$strategyTools, 'smartPostingTime'],
            'insights' => [$strategyTools, 'aiInsights'],
            'brief' => [$contentTools, 'contentBrief'],
        ];

        if (!isset($toolMap[$toolName])) return null;

        [$service, $method] = $toolMap[$toolName];
        if ($service === null || !method_exists($service, $method)) return null;

        try {
            $startTime = microtime(true);
            $result = $service->$method(...$this->prepareToolArgs($method, $input));
            $durationMs = (int)((microtime(true) - $startTime) * 1000);

            $this->logActivity(
                $toolName,
                $this->getToolCategory($toolName),
                mb_substr(json_encode($input, JSON_UNESCAPED_SLASHES), 0, 300),
                mb_substr(is_array($result) ? json_encode($result, JSON_UNESCAPED_SLASHES) : (string)$result, 0, 800),
                '',
                '',
                $durationMs,
            );

            return is_array($result) ? $result : ['content' => $result];
        } catch (\Throwable $e) {
            error_log("AiMemoryEngine::executeToolByName({$toolName}) error: " . $e->getMessage());
            return null;
        }
    }

    private function prepareToolArgs(string $method, array $input): array
    {
        // Map common input keys to method parameters
        switch ($method) {
            case 'generateContent':
                return [$input];
            case 'blogPostGenerator':
                return [$input['title'] ?? '', $input['keywords'] ?? '', $input['outline'] ?? null];
            case 'contentIdeas':
                return [$input['topic'] ?? '', $input['platform'] ?? 'general'];
            case 'marketResearch':
                return [$input['audience'] ?? $input['focus'] ?? '', $input['goal'] ?? $input['audience'] ?? ''];
            case 'audiencePersona':
                return [$input['demographics'] ?? '', $input['behaviors'] ?? ''];
            case 'competitorAnalysis':
                return [$input['competitorName'] ?? $input['competitor'] ?? '', $input['notes'] ?? $input['our_position'] ?? ''];
            case 'contentScore':
                return [$input['content'] ?? '', $input['platform'] ?? 'general'];
            case 'toneAnalysis':
                return [$input['content'] ?? ''];
            case 'headlineOptimizer':
                return [$input['headline'] ?? $input['topic'] ?? '', $input['platform'] ?? $input['current'] ?? 'general'];
            case 'hashtagResearch':
                return [$input['topic'] ?? '', $input['platform'] ?? 'instagram'];
            case 'seoKeywordResearch':
                return [$input['topic'] ?? '', $input['niche'] ?? $input['intent'] ?? 'informational'];
            default:
                return [$input];
        }
    }

    private function getToolCategory(string $toolName): string
    {
        $map = [
            'content' => 'content', 'blog-post' => 'content', 'headlines' => 'content',
            'refine' => 'content', 'brief' => 'content',
            'ideas' => 'strategy', 'research' => 'strategy', 'persona' => 'strategy',
            'social-strategy' => 'strategy', 'calendar-month' => 'strategy', 'insights' => 'strategy',
            'smart-times' => 'strategy',
            'competitor-analysis' => 'analysis', 'score' => 'analysis', 'tone-analysis' => 'analysis',
            'seo-keywords' => 'analysis', 'hashtags' => 'analysis',
        ];
        return $map[$toolName] ?? 'general';
    }

    /* ================================================================== */
    /*  HELPERS                                                            */
    /* ================================================================== */

    private function timeAgo(string $datetime): string
    {
        $diff = time() - strtotime($datetime);
        if ($diff < 60) return 'just now';
        if ($diff < 3600) return round($diff / 60) . 'm ago';
        if ($diff < 86400) return round($diff / 3600) . 'h ago';
        return round($diff / 86400) . 'd ago';
    }

    private function parseJsonFromResponse(string $raw): ?array
    {
        $raw = trim($raw);
        // Strip markdown fences
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $raw, $m)) {
            $raw = trim($m[1]);
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
