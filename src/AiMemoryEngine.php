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

        $total = (int)$this->pdo->prepare("SELECT COUNT(*) FROM ai_activity_log WHERE created_at >= :since")
            ->execute([':since' => $since]) ? (int)$this->pdo->query("SELECT COUNT(*) FROM ai_activity_log WHERE created_at >= '{$since}'")->fetchColumn() : 0;

        $byTool = $this->pdo->query("SELECT tool_name, COUNT(*) as count FROM ai_activity_log WHERE created_at >= '{$since}' GROUP BY tool_name ORDER BY count DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

        $byCategory = $this->pdo->query("SELECT tool_category, COUNT(*) as count FROM ai_activity_log WHERE created_at >= '{$since}' GROUP BY tool_category ORDER BY count DESC")->fetchAll(PDO::FETCH_ASSOC);

        $providers = $this->pdo->query("SELECT provider, COUNT(*) as count FROM ai_activity_log WHERE created_at >= '{$since}' AND provider != '' GROUP BY provider ORDER BY count DESC")->fetchAll(PDO::FETCH_ASSOC);

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
        // Boost confidence and reinforcement count of the most similar existing learning
        $stmt = $this->pdo->prepare("SELECT id FROM ai_learnings WHERE category = :cat ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([':cat' => $category]);
        $id = $stmt->fetchColumn();
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

        $sql .= " ORDER BY (confidence * (1 + LOG(MAX(times_reinforced, 1)))) DESC, updated_at DESC LIMIT :limit";
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
