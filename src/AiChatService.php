<?php

declare(strict_types=1);

/**
 * AiChatService — Conversational AI marketing chat.
 *
 * Tier 1: Chat interface where users ask questions about their data and get
 * answers grounded in actual analytics ("What was my best performing platform
 * last month?", "Write me 3 posts about our sale").
 */
final class AiChatService
{
    public function __construct(
        private AiService $ai,
        private PDO $pdo,
    ) {}

    /**
     * Process a chat message with full context from the marketing database.
     *
     * @param string $userMessage  The user's message
     * @param array  $history      Previous messages [{role, content}, ...]
     * @param string|null $provider Override provider
     * @param string|null $model    Override model
     * @return array {reply, context_used, provider}
     */
    public function chat(string $userMessage, array $history = [], ?string $provider = null, ?string $model = null): array
    {
        // Gather context from the database
        $context = $this->gatherContext($userMessage);

        $system = $this->ai->buildSystemPrompt(
            "You are an AI marketing assistant for {$this->ai->getBusinessName()} ({$this->ai->getIndustry()}).

You have access to the company's real marketing data. Use the data context below to ground your answers in actual numbers and facts. When the user asks about performance, content, campaigns, or anything data-related, reference the actual data.

When the user asks you to create content, write it ready-to-publish using the brand voice guidelines.

CURRENT DATA CONTEXT:
{$context}

RULES:
- Be conversational but professional
- Always reference real data when available
- If asked about data you don't have, say so honestly
- For content creation requests, produce ready-to-use output
- Keep responses focused and actionable
- You can suggest follow-up actions"
        );

        $p = $provider ?? $this->ai->getProvider();

        // Build messages array
        $messages = [];
        foreach ($history as $msg) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        // Route to the right provider
        $compatProviders = ['deepseek', 'groq', 'mistral', 'openrouter', 'xai', 'together'];
        $reply = match (true) {
            $p === 'anthropic' => $this->ai->chatAnthropic($system, $messages, $model),
            $p === 'gemini'    => $this->chatViaGemini($system, $messages, $model),
            in_array($p, $compatProviders, true) => $this->ai->chatOpenAiCompatible(
                $p,
                array_merge([['role' => 'system', 'content' => $system]], $messages),
                $model,
            ),
            default => $this->chatViaOpenAi($system, $messages, $model),
        };

        if ($reply === '') {
            $reply = $this->ai->fallback($userMessage);
        }

        return [
            'reply'        => $reply,
            'context_used' => $this->summarizeContext(),
            'provider'     => $p,
        ];
    }

    /**
     * Gather relevant context from the marketing database based on the query.
     */
    private function gatherContext(string $query): string
    {
        $ctx = [];

        // Always include high-level overview
        try {
            $ctx[] = $this->getOverviewStats();
        } catch (\PDOException $e) {
            error_log("AiChatService context error: " . $e->getMessage());
            $ctx[] = "OVERVIEW: Data unavailable.";
        }

        // Include relevant data based on query keywords
        $q = strtolower($query);

        if (preg_match('/post|content|publish|social|blog/', $q)) {
            $ctx[] = $this->getRecentPosts();
        }
        if (preg_match('/campaign|budget|spend|roi|revenue/', $q)) {
            $ctx[] = $this->getCampaignData();
        }
        if (preg_match('/email|subscriber|list|open|click/', $q)) {
            $ctx[] = $this->getEmailData();
        }
        if (preg_match('/contact|lead|customer|segment|crm/', $q)) {
            $ctx[] = $this->getContactData();
        }
        if (preg_match('/competitor|competition/', $q)) {
            $ctx[] = $this->getCompetitorData();
        }
        if (preg_match('/platform|instagram|twitter|linkedin|facebook|tiktok|perform/', $q)) {
            $ctx[] = $this->getPlatformBreakdown();
        }
        if (preg_match('/funnel|conversion|stage/', $q)) {
            $ctx[] = $this->getFunnelData();
        }
        if (preg_match('/schedule|queue|upcoming|planned/', $q)) {
            $ctx[] = $this->getUpcoming();
        }
        // Always include shared memory so all AI assistants stay aligned.
        $ctx[] = $this->getSharedMemory();

        return implode("\n\n", array_filter($ctx));
    }

    private function getOverviewStats(): string
    {
        $posts = $this->count('posts');
        $published = $this->count('posts', "status = 'published'");
        $scheduled = $this->count('posts', "status = 'scheduled'");
        $drafts = $this->count('posts', "status = 'draft'");
        $campaigns = $this->count('campaigns');
        $contacts = $this->count('contacts');
        $subscribers = $this->count('subscribers', "status = 'active'");

        $avgScore = 0;
        $stmt = $this->pdo->query("SELECT AVG(ai_score) as avg FROM posts WHERE ai_score > 0");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $avgScore = round((float)$row['avg']);

        return "OVERVIEW: {$posts} total posts ({$published} published, {$scheduled} scheduled, {$drafts} drafts), {$campaigns} campaigns, {$contacts} contacts, {$subscribers} active email subscribers, avg AI score: {$avgScore}.";
    }

    private function getRecentPosts(): string
    {
        $stmt = $this->pdo->query("SELECT title, platform, status, ai_score, scheduled_for, created_at FROM posts ORDER BY created_at DESC LIMIT 10");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return "RECENT POSTS: None.";

        $lines = "RECENT POSTS (last 10):\n";
        foreach ($rows as $r) {
            $lines .= "- \"{$r['title']}\" on {$r['platform']} [{$r['status']}] score:{$r['ai_score']} " . ($r['scheduled_for'] ?? $r['created_at']) . "\n";
        }
        return $lines;
    }

    private function getCampaignData(): string
    {
        $stmt = $this->pdo->query("SELECT name, channel, objective, budget, spend_to_date, revenue, status FROM campaigns ORDER BY created_at DESC LIMIT 10");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return "CAMPAIGNS: None.";

        $lines = "CAMPAIGNS:\n";
        foreach ($rows as $r) {
            $roi = $r['spend_to_date'] > 0 ? round(($r['revenue'] - $r['spend_to_date']) / $r['spend_to_date'] * 100) . '%' : 'N/A';
            $lines .= "- {$r['name']} ({$r['channel']}) — Budget: \${$r['budget']}, Spent: \${$r['spend_to_date']}, Revenue: \${$r['revenue']}, ROI: {$roi}, Status: {$r['status']}\n";
        }
        return $lines;
    }

    private function getEmailData(): string
    {
        $lists = $this->count('email_lists');
        $subs = $this->count('subscribers', "status = 'active'");
        $ecamps = $this->count('email_campaigns');
        $sent = 0;
        $stmt = $this->pdo->query("SELECT SUM(sent_count) as total FROM email_campaigns");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $sent = (int)$row['total'];

        return "EMAIL: {$lists} lists, {$subs} active subscribers, {$ecamps} campaigns, {$sent} total emails sent.";
    }

    private function getContactData(): string
    {
        $total = $this->count('contacts');
        $stages = $this->pdo->query("SELECT stage, COUNT(*) as c FROM contacts GROUP BY stage")->fetchAll(PDO::FETCH_ASSOC);
        $stageStr = implode(', ', array_map(fn($s) => "{$s['stage']}: {$s['c']}", $stages));
        $avgScore = 0;
        $stmt = $this->pdo->query("SELECT AVG(score) as avg FROM contacts WHERE score > 0");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $avgScore = round((float)$row['avg']);

        return "CONTACTS: {$total} total. By stage: {$stageStr}. Avg score: {$avgScore}.";
    }

    private function getCompetitorData(): string
    {
        $stmt = $this->pdo->query("SELECT name, channel, positioning, recent_activity, opportunity FROM competitors ORDER BY created_at DESC LIMIT 5");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return "COMPETITORS: None tracked.";

        $lines = "COMPETITORS:\n";
        foreach ($rows as $r) {
            $lines .= "- {$r['name']} ({$r['channel']}): {$r['positioning']}. Recent: {$r['recent_activity']}\n";
        }
        return $lines;
    }

    private function getPlatformBreakdown(): string
    {
        $stmt = $this->pdo->query("SELECT platform, COUNT(*) as c, SUM(CASE WHEN status='published' THEN 1 ELSE 0 END) as pub FROM posts GROUP BY platform ORDER BY c DESC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return "PLATFORMS: No posts.";

        $lines = "PLATFORM BREAKDOWN:\n";
        foreach ($rows as $r) {
            $lines .= "- {$r['platform']}: {$r['c']} total, {$r['pub']} published\n";
        }
        return $lines;
    }

    private function getFunnelData(): string
    {
        $stmt = $this->pdo->query("SELECT f.name, fs.name as stage, fs.target_count, fs.actual_count, fs.conversion_rate FROM funnels f JOIN funnel_stages fs ON f.id = fs.funnel_id ORDER BY f.id, fs.stage_order LIMIT 20");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return "FUNNELS: None created.";

        $lines = "FUNNELS:\n";
        $currentFunnel = '';
        foreach ($rows as $r) {
            if ($r['name'] !== $currentFunnel) {
                $currentFunnel = $r['name'];
                $lines .= "Funnel: {$currentFunnel}\n";
            }
            $lines .= "  - {$r['stage']}: target {$r['target_count']}, actual {$r['actual_count']}, {$r['conversion_rate']}% conversion\n";
        }
        return $lines;
    }

    private function getUpcoming(): string
    {
        $stmt = $this->pdo->query("SELECT title, platform, scheduled_for FROM posts WHERE status = 'scheduled' AND scheduled_for IS NOT NULL ORDER BY scheduled_for ASC LIMIT 10");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return "UPCOMING: Nothing scheduled.";

        $lines = "UPCOMING SCHEDULED POSTS:\n";
        foreach ($rows as $r) {
            $lines .= "- \"{$r['title']}\" on {$r['platform']} — {$r['scheduled_for']}\n";
        }
        return $lines;
    }

    private function summarizeContext(): string
    {
        return 'posts, campaigns, email, contacts, competitors, platforms, funnels, schedule, shared-memory';
    }

    private static array $allowedTables = [
        'posts', 'campaigns', 'contacts', 'subscribers', 'email_lists',
        'email_campaigns', 'competitors', 'funnels', 'funnel_stages',
        'social_accounts', 'social_queue', 'ab_tests', 'forms',
        'landing_pages', 'automation_rules', 'audience_segments',
    ];

    private function count(string $table, string $where = '1=1', array $params = []): int
    {
        if (!in_array($table, self::$allowedTables, true)) {
            return 0;
        }
        try {
            $sql = "SELECT COUNT(*) as c FROM {$table} WHERE {$where}";
            if ($params) {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
            } else {
                $stmt = $this->pdo->query($sql);
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($row['c'] ?? 0);
        } catch (\PDOException $e) {
            error_log("AiChatService::count error on {$table}: " . $e->getMessage());
            return 0;
        }
    }

    private function getSharedMemory(): string
    {
        $stmt = $this->pdo->query("SELECT memory_key, content, source, tags, updated_at FROM ai_shared_memory ORDER BY updated_at DESC LIMIT 15");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return 'SHARED MEMORY: None saved yet.';

        $lines = "SHARED MEMORY:\n";
        foreach ($rows as $row) {
            $key = trim((string)($row['memory_key'] ?? ''));
            $content = trim((string)($row['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $tags = trim((string)($row['tags'] ?? ''));
            $source = trim((string)($row['source'] ?? 'manual'));
            $prefix = $key !== '' ? "{$key}: " : '';
            $meta = "source={$source}";
            if ($tags !== '') {
                $meta .= ", tags={$tags}";
            }
            $lines .= "- {$prefix}{$content} ({$meta})\n";
        }

        return $lines;
    }

    private function chatViaOpenAi(string $system, array $messages, ?string $model): string
    {
        return $this->ai->chatOpenAi(
            array_merge([['role' => 'system', 'content' => $system]], $messages),
            $model,
        );
    }

    private function chatViaGemini(string $system, array $messages, ?string $model): string
    {
        // Gemini uses a different format — build contents with role alternation
        $contents = [];
        foreach ($messages as $msg) {
            $role = $msg['role'] === 'assistant' ? 'model' : 'user';
            $text = $msg['content'];

            // Merge consecutive messages with the same role (Gemini requires alternation)
            if (!empty($contents) && $contents[count($contents) - 1]['role'] === $role) {
                $contents[count($contents) - 1]['parts'][] = ['text' => $text];
            } else {
                $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
            }
        }

        // Ensure first message is from 'user' (Gemini requirement)
        if (!empty($contents) && $contents[0]['role'] !== 'user') {
            array_unshift($contents, ['role' => 'user', 'parts' => [['text' => 'Continue.']]]);
        }

        return $this->ai->chatGemini($contents, $model, $system);
    }
}
