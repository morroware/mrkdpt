<?php

declare(strict_types=1);

/**
 * AiService — Core AI engine.
 *
 * Handles provider routing (OpenAI / Anthropic / Gemini), per-request model
 * overrides, brand-voice injection, image generation (Banana / OpenAI DALL-E),
 * and the low-level HTTP transport shared by every tool module.
 *
 * Tool-specific logic lives in:
 *   AiContentTools.php   — content creation, workflow engine, image prompts
 *   AiAnalysisTools.php  — scoring, tone, approval, performance prediction
 *   AiStrategyTools.php  — research, competitors, segments, funnels, UTM
 *   AiChatService.php    — conversational marketing chat
 */
final class AiService
{
    private ?array $brandVoice = null;

    /** Available models per provider for the frontend model picker. */
    private const MODELS = [
        'openai' => [
            'gpt-4.1'         => 'GPT-4.1 (Best quality)',
            'gpt-4.1-mini'    => 'GPT-4.1 Mini (Fast)',
            'gpt-4.1-nano'    => 'GPT-4.1 Nano (Fastest)',
            'gpt-4o'          => 'GPT-4o',
            'gpt-4o-mini'     => 'GPT-4o Mini',
            'o3-mini'         => 'o3-mini (Reasoning)',
        ],
        'anthropic' => [
            'claude-sonnet-4-20250514'   => 'Claude Sonnet 4 (Balanced)',
            'claude-haiku-4-5-20251001'  => 'Claude Haiku 4.5 (Fast)',
            'claude-opus-4-20250514'     => 'Claude Opus 4 (Best quality)',
        ],
        'gemini' => [
            'gemini-2.5-pro'   => 'Gemini 2.5 Pro (Best quality)',
            'gemini-2.5-flash' => 'Gemini 2.5 Flash (Fast)',
            'gemini-2.0-flash' => 'Gemini 2.0 Flash (Fastest)',
        ],
    ];

    public function __construct(
        private string $provider,
        private string $businessName,
        private string $industry,
        private string $timezone,
        private array  $config,
    ) {
    }

    /* ------------------------------------------------------------------ */
    /*  Accessors                                                         */
    /* ------------------------------------------------------------------ */

    public function getProvider(): string { return $this->provider; }
    public function getBusinessName(): string { return $this->businessName; }
    public function getIndustry(): string { return $this->industry; }
    public function getTimezone(): string { return $this->timezone; }
    public function getConfig(): array { return $this->config; }

    public function setBrandVoice(?array $profile): void
    {
        $this->brandVoice = $profile;
    }

    public function getBrandVoice(): ?array
    {
        return $this->brandVoice;
    }

    /* ------------------------------------------------------------------ */
    /*  Provider / Model info                                             */
    /* ------------------------------------------------------------------ */

    public function providerStatus(): array
    {
        return [
            'active_provider'    => $this->provider,
            'supports'           => ['openai', 'anthropic', 'gemini'],
            'has_openai_key'     => !empty($this->config['openai_api_key']),
            'has_anthropic_key'  => !empty($this->config['anthropic_api_key']),
            'has_gemini_key'     => !empty($this->config['gemini_api_key']),
            'has_banana_key'     => !empty($this->config['banana_api_key']),
            'models'             => self::MODELS,
            'current_models'     => [
                'openai'    => $this->config['openai_model'] ?? 'gpt-4.1-mini',
                'anthropic' => $this->config['anthropic_model'] ?? 'claude-sonnet-4-20250514',
                'gemini'    => $this->config['gemini_model'] ?? 'gemini-2.5-flash',
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Brand-voice-aware system prompt (shared by tool modules)          */
    /* ------------------------------------------------------------------ */

    public function buildSystemPrompt(string $extra = ''): string
    {
        $base = 'You are a practical SMB marketing strategist. Be concise but specific.';

        if ($this->brandVoice !== null) {
            $tone       = $this->brandVoice['voice_tone'] ?? 'professional';
            $audience   = $this->brandVoice['target_audience'] ?? 'general';
            $vocabulary = $this->brandVoice['vocabulary'] ?? '';
            $avoid      = $this->brandVoice['avoid_words'] ?? '';
            $example    = $this->brandVoice['example_content'] ?? '';

            $base .= "\n\nBrand Voice Guidelines:"
                . "\n- Tone: {$tone}"
                . "\n- Target Audience: {$audience}"
                . "\n- Vocabulary to use: {$vocabulary}"
                . "\n- Words/phrases to avoid: {$avoid}"
                . "\n- Example of our voice: {$example}";
        }

        if ($extra !== '') {
            $base .= "\n\n" . $extra;
        }

        return $base;
    }

    /* ------------------------------------------------------------------ */
    /*  Public generation entry points                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Simple generation — system prompt is the default marketing strategist.
     *
     * @param string      $prompt   User prompt
     * @param string|null $provider Override provider for this request
     * @param string|null $model    Override model for this request
     */
    public function generate(string $prompt, ?string $provider = null, ?string $model = null): string
    {
        return $this->generateAdvanced(
            $this->buildSystemPrompt(),
            $prompt,
            $provider,
            $model,
        );
    }

    /**
     * Advanced generation — caller supplies the full system prompt.
     */
    public function generateAdvanced(
        string  $system,
        string  $prompt,
        ?string $provider = null,
        ?string $model = null,
        int     $maxTokens = 4096,
        float   $temperature = 0.7,
    ): string {
        $p = $provider ?? $this->provider;
        return match ($p) {
            'anthropic' => $this->callAnthropicAdv($system, $prompt, $model, $maxTokens, $temperature),
            'gemini'    => $this->callGeminiAdv($system, $prompt, $model, $maxTokens, $temperature),
            default     => $this->callOpenAiAdv($system, $prompt, $model, $maxTokens, $temperature),
        };
    }

    /**
     * Run the same prompt on multiple providers and return all results.
     */
    public function generateMulti(string $system, string $prompt, array $providers): array
    {
        $results = [];
        foreach ($providers as $p) {
            $results[$p] = $this->generateAdvanced($system, $prompt, $p);
        }
        return $results;
    }

    /* ------------------------------------------------------------------ */
    /*  Image Generation                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Generate an image from a text prompt.
     *
     * Supports:
     *   - 'banana'  → NanoBanana / Banana.dev serverless endpoint (Flux / SD)
     *   - 'openai'  → DALL-E 3
     *   - 'gemini'  → Gemini Imagen
     *
     * Returns ['url' => '...', 'provider' => '...'] or ['error' => '...']
     */
    public function generateImage(string $prompt, string $imageProvider = 'auto', string $size = '1024x1024'): array
    {
        // Auto-select: prefer banana, fall back to openai, then gemini
        if ($imageProvider === 'auto') {
            if (!empty($this->config['banana_api_key'])) {
                $imageProvider = 'banana';
            } elseif (!empty($this->config['openai_api_key'])) {
                $imageProvider = 'openai';
            } else {
                return ['error' => 'No image generation provider configured. Add BANANA_API_KEY or OPENAI_API_KEY to .env.'];
            }
        }

        return match ($imageProvider) {
            'banana' => $this->callBananaImage($prompt, $size),
            'openai' => $this->callDallE($prompt, $size),
            default  => ['error' => "Unknown image provider: {$imageProvider}"],
        };
    }

    /* ------------------------------------------------------------------ */
    /*  Provider call — OpenAI-compatible                                 */
    /* ------------------------------------------------------------------ */

    private function callOpenAiAdv(string $system, string $prompt, ?string $model = null, int $maxTokens = 4096, float $temperature = 0.7): string
    {
        if (empty($this->config['openai_api_key'])) {
            return $this->fallback($prompt);
        }

        $url = rtrim((string)$this->config['openai_base_url'], '/') . '/chat/completions';
        $payload = [
            'model'       => $model ?? $this->config['openai_model'] ?? 'gpt-4.1-mini',
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $prompt],
            ],
            'temperature' => $temperature,
            'max_tokens'  => $maxTokens,
        ];

        $data = $this->postJson($url, [
            'Authorization: Bearer ' . $this->config['openai_api_key'],
        ], $payload);

        $content = $data['choices'][0]['message']['content'] ?? null;
        return is_string($content) && $content !== '' ? $content : $this->fallback($prompt);
    }

    /**
     * OpenAI chat with full message history (for chat feature).
     */
    public function chatOpenAi(array $messages, ?string $model = null, float $temperature = 0.7): string
    {
        if (empty($this->config['openai_api_key'])) {
            return $this->fallback($messages[count($messages) - 1]['content'] ?? '');
        }

        $url = rtrim((string)$this->config['openai_base_url'], '/') . '/chat/completions';
        $payload = [
            'model'       => $model ?? $this->config['openai_model'] ?? 'gpt-4.1-mini',
            'messages'    => $messages,
            'temperature' => $temperature,
        ];

        $data = $this->postJson($url, [
            'Authorization: Bearer ' . $this->config['openai_api_key'],
        ], $payload);

        $content = $data['choices'][0]['message']['content'] ?? null;
        return is_string($content) && $content !== '' ? $content : '';
    }

    /* ------------------------------------------------------------------ */
    /*  Provider call — Anthropic                                         */
    /* ------------------------------------------------------------------ */

    private function callAnthropicAdv(string $system, string $prompt, ?string $model = null, int $maxTokens = 4096, float $temperature = 0.7): string
    {
        if (empty($this->config['anthropic_api_key'])) {
            return $this->fallback($prompt);
        }

        $payload = [
            'model'      => $model ?? $this->config['anthropic_model'] ?? 'claude-sonnet-4-20250514',
            'max_tokens' => $maxTokens,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ];

        $data = $this->postJson('https://api.anthropic.com/v1/messages', [
            'x-api-key: ' . $this->config['anthropic_api_key'],
            'anthropic-version: 2023-06-01',
        ], $payload);

        $content = $data['content'][0]['text'] ?? null;
        return is_string($content) && $content !== '' ? $content : $this->fallback($prompt);
    }

    /**
     * Anthropic chat with full message history.
     */
    public function chatAnthropic(string $system, array $messages, ?string $model = null): string
    {
        if (empty($this->config['anthropic_api_key'])) {
            return '';
        }

        $payload = [
            'model'      => $model ?? $this->config['anthropic_model'] ?? 'claude-sonnet-4-20250514',
            'max_tokens' => 4096,
            'system'     => $system,
            'messages'   => $messages,
        ];

        $data = $this->postJson('https://api.anthropic.com/v1/messages', [
            'x-api-key: ' . $this->config['anthropic_api_key'],
            'anthropic-version: 2023-06-01',
        ], $payload);

        return $data['content'][0]['text'] ?? '';
    }

    /* ------------------------------------------------------------------ */
    /*  Provider call — Gemini                                            */
    /* ------------------------------------------------------------------ */

    private function callGeminiAdv(string $system, string $prompt, ?string $model = null, int $maxTokens = 4096, float $temperature = 0.7): string
    {
        if (empty($this->config['gemini_api_key'])) {
            return $this->fallback($prompt);
        }

        $m = $model ?? $this->config['gemini_model'] ?? 'gemini-2.5-flash';
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            $m,
            urlencode((string)$this->config['gemini_api_key']),
        );

        $payload = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => [
                'temperature'   => $temperature,
                'maxOutputTokens' => $maxTokens,
            ],
        ];

        $data = $this->postJson($url, [], $payload);
        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        return is_string($content) && $content !== '' ? $content : $this->fallback($prompt);
    }

    /**
     * Gemini chat with full message history.
     */
    public function chatGemini(array $contents, ?string $model = null): string
    {
        if (empty($this->config['gemini_api_key'])) {
            return '';
        }

        $m = $model ?? $this->config['gemini_model'] ?? 'gemini-2.5-flash';
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            $m,
            urlencode((string)$this->config['gemini_api_key']),
        );

        $data = $this->postJson($url, [], ['contents' => $contents]);
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    /* ------------------------------------------------------------------ */
    /*  Image providers                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * NanoBanana / Banana.dev serverless image generation.
     * Works with Flux, Stable Diffusion, or any model deployed on Banana.
     */
    private function callBananaImage(string $prompt, string $size = '1024x1024'): array
    {
        $apiKey  = $this->config['banana_api_key'] ?? '';
        $baseUrl = rtrim($this->config['banana_base_url'] ?? 'https://api.banana.dev', '/');
        $modelId = $this->config['banana_model_id'] ?? '';

        if ($apiKey === '' || $modelId === '') {
            return ['error' => 'BANANA_API_KEY and BANANA_MODEL_ID required in .env'];
        }

        [$w, $h] = $this->parseSize($size);

        $payload = [
            'id'          => bin2hex(random_bytes(8)),
            'created'     => time(),
            'apiKey'      => $apiKey,
            'modelKey'    => $modelId,
            'modelInputs' => [
                'prompt'  => $prompt,
                'width'   => $w,
                'height'  => $h,
            ],
        ];

        // Banana can take time — extend timeout
        $data = $this->postJson($baseUrl . '/start/v4', [], $payload, 120);

        // Banana returns base64 image in modelOutputs
        $b64 = $data['modelOutputs'][0]['image_base64']
            ?? $data['modelOutputs'][0]['image']
            ?? $data['output']['image_base64']
            ?? $data['output']['image']
            ?? null;

        if ($b64) {
            return ['image_base64' => $b64, 'provider' => 'banana', 'prompt' => $prompt];
        }

        // Some Banana deployments return a URL
        $url = $data['modelOutputs'][0]['image_url']
            ?? $data['output']['image_url']
            ?? null;

        if ($url) {
            return ['url' => $url, 'provider' => 'banana', 'prompt' => $prompt];
        }

        return ['error' => 'No image returned from Banana. Response: ' . json_encode(array_keys($data))];
    }

    /**
     * OpenAI DALL-E 3 image generation.
     */
    private function callDallE(string $prompt, string $size = '1024x1024'): array
    {
        if (empty($this->config['openai_api_key'])) {
            return ['error' => 'OPENAI_API_KEY required for DALL-E image generation'];
        }

        $allowedSizes = ['1024x1024', '1024x1792', '1792x1024'];
        if (!in_array($size, $allowedSizes, true)) {
            $size = '1024x1024';
        }

        $url = rtrim((string)$this->config['openai_base_url'], '/') . '/images/generations';
        $payload = [
            'model'           => 'dall-e-3',
            'prompt'          => $prompt,
            'n'               => 1,
            'size'            => $size,
            'response_format' => 'b64_json',
        ];

        $data = $this->postJson($url, [
            'Authorization: Bearer ' . $this->config['openai_api_key'],
        ], $payload, 90);

        $b64 = $data['data'][0]['b64_json'] ?? null;
        if ($b64) {
            return [
                'image_base64'    => $b64,
                'provider'        => 'openai_dalle',
                'revised_prompt'  => $data['data'][0]['revised_prompt'] ?? $prompt,
                'prompt'          => $prompt,
            ];
        }

        $url2 = $data['data'][0]['url'] ?? null;
        if ($url2) {
            return ['url' => $url2, 'provider' => 'openai_dalle', 'prompt' => $prompt];
        }

        return ['error' => 'No image returned from DALL-E'];
    }

    /* ------------------------------------------------------------------ */
    /*  HTTP transport                                                    */
    /* ------------------------------------------------------------------ */

    public function postJson(string $url, array $headers, array $payload, int $timeout = 60): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_POST            => true,
            CURLOPT_HTTPHEADER      => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_POSTFIELDS      => json_encode($payload),
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
        ]);

        $raw   = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $error !== '') {
            error_log("AiService::postJson curl error: {$error} (URL: {$url})");
            return ['error' => 'Network error: ' . $error];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            error_log("AiService::postJson invalid JSON response (HTTP {$httpCode}, URL: {$url})");
            return ['error' => "Invalid response from AI provider (HTTP {$httpCode})"];
        }

        // Surface API-level errors
        if ($httpCode >= 400) {
            $apiError = $decoded['error']['message'] ?? $decoded['error'] ?? "HTTP {$httpCode}";
            if (is_array($apiError)) {
                $apiError = $apiError['message'] ?? json_encode($apiError);
            }
            error_log("AiService::postJson API error: {$apiError} (HTTP {$httpCode}, URL: {$url})");
            return ['error' => "AI provider error: {$apiError}"];
        }

        return $decoded;
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                           */
    /* ------------------------------------------------------------------ */

    public function fallback(string $prompt): string
    {
        return "[Fallback mode: configure AI provider keys in .env]\n\n"
            . "- Core strategy: 40% educational, 30% social proof, 20% offer, 10% behind-the-scenes.\n"
            . "- Recommended cadence: 5 posts/week + 2 stories/day + 1 email/week.\n"
            . "- Highest-conversion windows: Tue 11:30 AM, Wed 6:30 PM, Thu 12:15 PM ({$this->timezone}).\n"
            . "- CTA suggestions: 'Comment START', 'Book your spot', 'Send us DM with keyword'.";
    }

    private function parseSize(string $size): array
    {
        $parts = explode('x', $size);
        $w = max(256, min(2048, (int)($parts[0] ?? 1024)));
        $h = max(256, min(2048, (int)($parts[1] ?? 1024)));
        return [$w, $h];
    }
}
