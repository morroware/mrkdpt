<?php

declare(strict_types=1);

/**
 * SocialPublisher — zero-dependency social media publishing service.
 *
 * Supports 15 platforms: Twitter/X, Bluesky, Mastodon, Facebook Pages, Instagram,
 * LinkedIn, Threads, Pinterest, TikTok, Reddit, Telegram, Discord, Slack, WordPress, Medium.
 * Uses only cURL for HTTP; no Composer packages required.
 *
 * Updated March 2026: LinkedIn Posts API, Graph API v22.0, Threads v2.0, X API v2 media.
 *
 * Each platform method expects an $account array containing at minimum:
 *   - id            (int)    — internal account row ID
 *   - platform      (string) — platform identifier
 *   - access_token  (string) — OAuth token (usage varies per platform)
 *   - meta_json     (string|array) — JSON string or decoded array with platform-specific fields
 */
final class SocialPublisher
{
    /** @var array<string,int> Platform post length limits (characters). */
    private const PLATFORM_CHAR_LIMITS = [
        'twitter' => 280,
        'threads' => 500,
        'bluesky' => 300,
        'mastodon' => 500,
        'linkedin' => 3000,
        'telegram' => 4096,
        'reddit' => 40000,
    ];

    /** cURL timeout in seconds for normal API calls. */
    private const TIMEOUT = 30;

    /** cURL timeout for media uploads (larger payloads). */
    private const UPLOAD_TIMEOUT = 120;

    public function __construct(private PDO $pdo)
    {
    }

    // =========================================================================
    //  Unified dispatch
    // =========================================================================

    /**
     * Publish a post to the correct platform based on $account['platform'].
     *
     * @param  array $post    Post row — must contain at least 'id', 'body'; optionally 'media_path' or 'media_url'.
     * @param  array $account Account row — must contain 'id', 'platform', 'access_token', 'meta_json'.
     * @return array{success: bool, external_id: string|null, error: string|null}
     */
    public function publish(array $post, array $account): array
    {
        $text      = (string)($post['body'] ?? '');
        $mediaPath = $post['media_path'] ?? null;
        $mediaUrl  = $post['media_url'] ?? null;
        $platform  = $account['platform'] ?? '';

        // Ensure meta_json is decoded.
        $account = $this->decodeMeta($account);

        $lengthValidation = $this->validateContentLength($platform, $text);
        if ($lengthValidation !== null) {
            $this->logPublish(
                (int)($post['id'] ?? 0),
                $platform,
                (int)($account['id'] ?? 0),
                null,
                'failed',
                $lengthValidation,
            );
            return ['success' => false, 'external_id' => null, 'error' => $lengthValidation];
        }

        $result = match ($platform) {
            'twitter'   => $this->publishToTwitter($account, $text, $mediaPath),
            'bluesky'   => $this->publishToBluesky($account, $text, $mediaPath),
            'mastodon'  => $this->publishToMastodon($account, $text, $mediaPath),
            'facebook'  => $this->publishToFacebook($account, $text, $mediaPath),
            'instagram' => $this->publishToInstagram($account, $text, $mediaUrl),
            'linkedin'  => $this->publishToLinkedIn($account, $text, $mediaPath),
            'threads'   => $this->publishToThreads($account, $text, $mediaUrl),
            'pinterest' => $this->publishToPinterest($account, $text, $mediaUrl),
            'tiktok'    => $this->publishToTikTok($account, $text, $mediaPath),
            'reddit'    => $this->publishToReddit($account, $text, $mediaUrl),
            'telegram'  => $this->publishToTelegram($account, $text, $mediaUrl),
            'discord'   => $this->publishToDiscord($account, $text, $mediaUrl),
            'slack'     => $this->publishToSlack($account, $text),
            'wordpress' => $this->publishToWordPress($account, $text, $mediaPath),
            'medium'    => $this->publishToMedium($account, $text),
            default     => ['success' => false, 'external_id' => null, 'error' => "Unsupported platform: {$platform}"],
        };

        // Persist to publish_log.
        $this->logPublish(
            (int)($post['id'] ?? 0),
            $platform,
            (int)($account['id'] ?? 0),
            $result['external_id'] ?? null,
            $result['success'] ? 'published' : 'failed',
            $result['error'] ?? null,
        );

        return $result;
    }

    private function validateContentLength(string $platform, string $text): ?string
    {
        $limit = self::PLATFORM_CHAR_LIMITS[$platform] ?? null;
        if ($limit === null) {
            return null;
        }

        $length = mb_strlen($text);
        if ($length <= $limit) {
            return null;
        }

        return sprintf(
            '%s content exceeds %d character limit (%d).',
            ucfirst($platform),
            $limit,
            $length,
        );
    }

    // =========================================================================
    //  Twitter / X  (API v2)
    // =========================================================================

    /**
     * Publish a tweet via X (Twitter) API v2.
     *
     * Auth: OAuth 2.0 Bearer token (User-context) in $account['access_token'].
     * Media upload uses the X API v2 media upload endpoint.
     *
     * @param  array       $account   Decoded account row.
     * @param  string      $text      Tweet text (max 280 chars enforced by API).
     * @param  string|null $mediaPath Absolute path to a local image/video file.
     * @return array{success: bool, external_id: string|null, error: string|null}
     */
    public function publishToTwitter(array $account, string $text, ?string $mediaPath = null): array
    {
        try {
            $token   = (string)($account['access_token'] ?? '');
            $headers = ["Authorization: Bearer {$token}"];

            $payload = ['text' => $text];

            // Optional media attachment via v1.1 media/upload (simple upload).
            if ($mediaPath !== null && is_file($mediaPath)) {
                $mediaId = $this->twitterUploadMedia($token, $mediaPath);
                if ($mediaId !== null) {
                    $payload['media'] = ['media_ids' => [$mediaId]];
                }
            }

            $data = $this->postJson(
                'https://api.twitter.com/2/tweets',
                $headers,
                $payload,
            );

            if (!empty($data['data']['id'])) {
                return ['success' => true, 'external_id' => (string)$data['data']['id'], 'error' => null];
            }

            $error = $data['detail'] ?? $data['title'] ?? json_encode($data['errors'] ?? $data);
            return ['success' => false, 'external_id' => null, 'error' => "Twitter API error: {$error}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => "Twitter exception: {$e->getMessage()}"];
        }
    }

    /**
     * Upload media to X (Twitter) via the v2 media upload endpoint.
     *
     * Returns the media_id_string on success, or null on failure.
     */
    private function twitterUploadMedia(string $token, string $filePath): ?string
    {
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        $data = $this->postForm(
            'https://api.twitter.com/2/media/upload',
            ["Authorization: Bearer {$token}"],
            [
                'media' => new \CURLFile($filePath, $mimeType, basename($filePath)),
            ],
            self::UPLOAD_TIMEOUT,
        );

        return isset($data['media_id_string']) ? (string)$data['media_id_string'] : null;
    }

    // =========================================================================
    //  Bluesky  (AT Protocol)
    // =========================================================================

    /**
     * Publish a post to Bluesky via the AT Protocol.
     *
     * Auth flow:
     *   1. Create a session using identifier (handle/email) + password stored in meta_json.
     *   2. Use the returned accessJwt for subsequent requests.
     *
     * Media: upload blob via com.atproto.repo.uploadBlob, reference in embed.
     * Facets: URLs, mentions (@handle), and hashtags (#tag) are parsed and attached.
     *
     * @param  array       $account   Decoded account row. meta_json must contain 'identifier' and 'password'.
     * @param  string      $text      Post text.
     * @param  string|null $mediaPath Absolute path to a local image file.
     * @return array{success: bool, external_id: string|null, error: string|null}
     */
    public function publishToBluesky(array $account, string $text, ?string $mediaPath = null): array
    {
        try {
            $meta = $account['meta_json'] ?? [];
            $identifier = (string)($meta['identifier'] ?? '');
            $password   = (string)($meta['password'] ?? '');

            if ($identifier === '' || $password === '') {
                return ['success' => false, 'external_id' => null, 'error' => 'Bluesky credentials (identifier, password) missing in meta_json'];
            }

            // Step 1 — Authenticate.
            $session = $this->postJson(
                'https://bsky.social/xrpc/com.atproto.server.createSession',
                [],
                ['identifier' => $identifier, 'password' => $password],
            );

            $accessJwt = $session['accessJwt'] ?? null;
            $did       = $session['did'] ?? null;

            if ($accessJwt === null || $did === null) {
                $error = $session['message'] ?? 'Session creation failed';
                return ['success' => false, 'external_id' => null, 'error' => "Bluesky auth error: {$error}"];
            }

            $authHeaders = ["Authorization: Bearer {$accessJwt}"];

            // Step 2 — Build the record.
            $record = [
                '$type'     => 'app.bsky.feed.post',
                'text'      => $text,
                'createdAt' => gmdate('Y-m-d\TH:i:s.v\Z'),
            ];

            // Parse facets (links, mentions, hashtags).
            $facets = $this->blueskyParseFacets($text);
            if ($facets !== []) {
                $record['facets'] = $facets;
            }

            // Optional image embed.
            if ($mediaPath !== null && is_file($mediaPath)) {
                $blob = $this->blueskyUploadBlob($authHeaders, $mediaPath);
                if ($blob !== null) {
                    $record['embed'] = [
                        '$type'  => 'app.bsky.embed.images',
                        'images' => [
                            ['alt' => '', 'image' => $blob],
                        ],
                    ];
                }
            }

            // Step 3 — Create the record.
            $result = $this->postJson(
                'https://bsky.social/xrpc/com.atproto.repo.createRecord',
                $authHeaders,
                [
                    'repo'       => $did,
                    'collection' => 'app.bsky.feed.post',
                    'record'     => $record,
                ],
            );

            if (!empty($result['uri'])) {
                return ['success' => true, 'external_id' => (string)$result['uri'], 'error' => null];
            }

            $error = $result['message'] ?? json_encode($result);
            return ['success' => false, 'external_id' => null, 'error' => "Bluesky post error: {$error}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => "Bluesky exception: {$e->getMessage()}"];
        }
    }

    /**
     * Upload an image blob to Bluesky and return the blob reference object.
     *
     * @return array|null The blob reference (with $type, ref, mimeType, size) or null.
     */
    private function blueskyUploadBlob(array $authHeaders, string $filePath): ?array
    {
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        $fileData = file_get_contents($filePath);
        if ($fileData === false) {
            return null;
        }

        $ch = curl_init('https://bsky.social/xrpc/com.atproto.repo.uploadBlob');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => array_merge($authHeaders, ["Content-Type: {$mimeType}"]),
            CURLOPT_POSTFIELDS     => $fileData,
            CURLOPT_TIMEOUT        => self::UPLOAD_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw   = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '' || !is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);
        return $decoded['blob'] ?? null;
    }

    /**
     * Parse Bluesky rich-text facets from plain text.
     *
     * Extracts:
     *   - URLs   (https?://...)
     *   - Mentions (@handle or @did)
     *   - Hashtags (#tag)
     *
     * Byte offsets are used as required by the AT Protocol.
     *
     * @return list<array> Facet objects ready for the record.
     */
    private function blueskyParseFacets(string $text): array
    {
        $facets = [];

        // URLs.
        if (preg_match_all('#https?://[^\s\)\]]+#u', $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$url, $offset]) {
                $byteStart = strlen(substr($text, 0, $offset));
                $byteEnd   = $byteStart + strlen($url);
                $facets[]  = [
                    'index'    => ['byteStart' => $byteStart, 'byteEnd' => $byteEnd],
                    'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]],
                ];
            }
        }

        // Mentions (@handle).
        if (preg_match_all('/(?<=^|(?<=\s))@([\w.-]+(?:\.[\w.-]+)+)/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => [$full, $offset]) {
                $byteStart = strlen(substr($text, 0, $offset));
                $byteEnd   = $byteStart + strlen($full);
                $facets[]  = [
                    'index'    => ['byteStart' => $byteStart, 'byteEnd' => $byteEnd],
                    'features' => [['$type' => 'app.bsky.richtext.facet#mention', 'did' => $matches[1][$i][0]]],
                ];
            }
        }

        // Hashtags (#tag).
        if (preg_match_all('/(?<=^|(?<=\s))#(\w+)/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => [$full, $offset]) {
                $byteStart = strlen(substr($text, 0, $offset));
                $byteEnd   = $byteStart + strlen($full);
                $facets[]  = [
                    'index'    => ['byteStart' => $byteStart, 'byteEnd' => $byteEnd],
                    'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => $matches[1][$i][0]]],
                ];
            }
        }

        return $facets;
    }

    // =========================================================================
    //  Mastodon
    // =========================================================================

    /**
     * Publish a status to a Mastodon instance.
     *
     * Auth: OAuth 2.0 Bearer token in $account['access_token'].
     * The instance base URL (e.g. "https://mastodon.social") comes from meta_json['instance_url'].
     *
     * Media: uploaded first via POST /api/v1/media, then referenced by ID in the status.
     *
     * @param  array       $account   Decoded account row. meta_json must contain 'instance_url'.
     * @param  string      $text      Status text.
     * @param  string|null $mediaPath Absolute path to a local media file.
     * @return array{success: bool, external_id: string|null, error: string|null}
     */
    public function publishToMastodon(array $account, string $text, ?string $mediaPath = null): array
    {
        try {
            $meta        = $account['meta_json'] ?? [];
            $instanceUrl = rtrim((string)($meta['instance_url'] ?? ''), '/');
            $token       = (string)($account['access_token'] ?? '');

            if ($instanceUrl === '' || $token === '') {
                return ['success' => false, 'external_id' => null, 'error' => 'Mastodon instance_url or access_token missing'];
            }

            $authHeaders = ["Authorization: Bearer {$token}"];
            $mediaIds    = [];

            // Optional media attachment.
            if ($mediaPath !== null && is_file($mediaPath)) {
                $mediaData = $this->postForm(
                    "{$instanceUrl}/api/v1/media",
                    $authHeaders,
                    ['file' => new \CURLFile($mediaPath, mime_content_type($mediaPath) ?: 'application/octet-stream')],
                    self::UPLOAD_TIMEOUT,
                );

                if (!empty($mediaData['id'])) {
                    $mediaIds[] = (string)$mediaData['id'];
                }
            }

            // Build form fields for the status.
            $fields = ['status' => $text];
            foreach ($mediaIds as $idx => $id) {
                $fields["media_ids[{$idx}]"] = $id;
            }

            $data = $this->postForm(
                "{$instanceUrl}/api/v1/statuses",
                $authHeaders,
                $fields,
            );

            if (!empty($data['id'])) {
                return ['success' => true, 'external_id' => (string)$data['id'], 'error' => null];
            }

            $error = $data['error'] ?? json_encode($data);
            return ['success' => false, 'external_id' => null, 'error' => "Mastodon API error: {$error}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => "Mastodon exception: {$e->getMessage()}"];
        }
    }

    // =========================================================================
    //  Facebook Pages  (Graph API v22.0)
    // =========================================================================

    /**
     * Publish a post (or photo) to a Facebook Page.
     *
     * Auth: Page Access Token in $account['access_token'].
     * Page ID from meta_json['page_id'].
     *
     * Text-only: POST /{page_id}/feed with "message" field.
     * With photo: POST /{page_id}/photos with "message" + "source" (file upload).
     *
     * @param  array       $account   Decoded account row. meta_json must contain 'page_id'.
     * @param  string      $text      Post message.
     * @param  string|null $mediaPath Absolute path to a local image file.
     * @return array{success: bool, external_id: string|null, error: string|null}
     */
    public function publishToFacebook(array $account, string $text, ?string $mediaPath = null): array
    {
        try {
            $meta   = $account['meta_json'] ?? [];
            $pageId = (string)($meta['page_id'] ?? '');
            $token  = (string)($account['access_token'] ?? '');

            if ($pageId === '' || $token === '') {
                return ['success' => false, 'external_id' => null, 'error' => 'Facebook page_id or access_token missing'];
            }

            $baseUrl = "https://graph.facebook.com/v22.0/{$pageId}";

            // Photo post.
            if ($mediaPath !== null && is_file($mediaPath)) {
                $data = $this->postForm(
                    "{$baseUrl}/photos",
                    [],
                    [
                        'message'      => $text,
                        'access_token' => $token,
                        'source'       => new \CURLFile($mediaPath, mime_content_type($mediaPath) ?: 'image/jpeg'),
                    ],
                    self::UPLOAD_TIMEOUT,
                );

                if (!empty($data['id'])) {
                    return ['success' => true, 'external_id' => (string)$data['id'], 'error' => null];
                }

                $error = $data['error']['message'] ?? json_encode($data);
                return ['success' => false, 'external_id' => null, 'error' => "Facebook photo error: {$error}"];
            }

            // Text-only post.
            $data = $this->postForm(
                "{$baseUrl}/feed",
                [],
                [
                    'message'      => $text,
                    'access_token' => $token,
                ],
            );

            if (!empty($data['id'])) {
                return ['success' => true, 'external_id' => (string)$data['id'], 'error' => null];
            }

            $error = $data['error']['message'] ?? json_encode($data);
            return ['success' => false, 'external_id' => null, 'error' => "Facebook API error: {$error}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => "Facebook exception: {$e->getMessage()}"];
        }
    }

    // =========================================================================
    //  Instagram  (Graph API via Facebook)
    // =========================================================================

    /**
     * Publish a post to Instagram via the Facebook Graph API.
     *
     * Two-step creation flow required by Instagram's Content Publishing API:
     *   1. Create a media container: POST /{ig_account_id}/media with caption + image_url.
     *   2. Publish: POST /{ig_account_id}/media_publish with creation_id.
     *
     * IMPORTANT: Instagram requires a publicly accessible image URL — local file paths
     * cannot be used. The caller must host the image and pass the URL via $mediaUrl.
     *
     * @param  array       $account  Decoded account row. meta_json must contain 'ig_account_id'.
     * @param  string      $text     Caption text.
     * @param  string|null $mediaUrl Publicly accessible URL of the image to post.
     * @return array{success: bool, external_id: string|null, error: string|null}
     */
    public function publishToInstagram(array $account, string $text, ?string $mediaUrl = null): array
    {
        try {
            $meta   = $account['meta_json'] ?? [];
            $igId   = (string)($meta['ig_account_id'] ?? '');
            $token  = (string)($account['access_token'] ?? '');

            if ($igId === '' || $token === '') {
                return ['success' => false, 'external_id' => null, 'error' => 'Instagram ig_account_id or access_token missing'];
            }

            if ($mediaUrl === null || $mediaUrl === '') {
                return ['success' => false, 'external_id' => null, 'error' => 'Instagram requires a publicly accessible image_url'];
            }

            $baseUrl = "https://graph.facebook.com/v22.0/{$igId}";

            // Step 1 — Create the media container.
            $container = $this->postForm(
                "{$baseUrl}/media",
                [],
                [
                    'caption'      => $text,
                    'image_url'    => $mediaUrl,
                    'access_token' => $token,
                ],
            );

            $creationId = $container['id'] ?? null;
            if ($creationId === null) {
                $error = $container['error']['message'] ?? json_encode($container);
                return ['success' => false, 'external_id' => null, 'error' => "Instagram container error: {$error}"];
            }

            // Step 2 — Publish the container.
            $publish = $this->postForm(
                "{$baseUrl}/media_publish",
                [],
                [
                    'creation_id'  => (string)$creationId,
                    'access_token' => $token,
                ],
            );

            if (!empty($publish['id'])) {
                return ['success' => true, 'external_id' => (string)$publish['id'], 'error' => null];
            }

            $error = $publish['error']['message'] ?? json_encode($publish);
            return ['success' => false, 'external_id' => null, 'error' => "Instagram publish error: {$error}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => "Instagram exception: {$e->getMessage()}"];
        }
    }

    // =========================================================================
    //  LinkedIn  (Community Management Posts API — replaces deprecated ugcPosts)
    // =========================================================================

    /**
     * Publish a post to LinkedIn via the Posts API (Community Management API).
     *
     * Auth: OAuth 2.0 Bearer token with w_member_social or w_organization_social scope.
     * meta_json must contain 'urn' — the author URN (e.g. "urn:li:person:xxx" or "urn:li:organization:xxx").
     *
     * Uses POST /rest/posts (replaces deprecated /v2/ugcPosts).
     * Image upload via POST /rest/images?action=initializeUpload.
     */
    public function publishToLinkedIn(array $account, string $text, ?string $mediaPath = null): array
    {
        try {
            $meta  = $account['meta_json'] ?? [];
            $token = (string)($account['access_token'] ?? '');
            $urn   = (string)($meta['urn'] ?? '');

            if ($urn === '' || $token === '') {
                return ['success' => false, 'external_id' => null, 'error' => 'LinkedIn URN or access_token missing'];
            }

            $authHeaders = [
                "Authorization: Bearer {$token}",
                'LinkedIn-Version: 202501',
                'X-Restli-Protocol-Version: 2.0.0',
            ];

            // Upload image if provided.
            $imageUrn = null;
            if ($mediaPath !== null && is_file($mediaPath)) {
                $imageUrn = $this->linkedInUploadImage($authHeaders, $urn, $mediaPath);
            }

            $payload = [
                'author'       => $urn,
                'commentary'   => $text,
                'visibility'   => 'PUBLIC',
                'distribution' => [
                    'feedDistribution'          => 'MAIN_FEED',
                    'targetEntities'            => [],
                    'thirdPartyDistributionChannels' => [],
                ],
                'lifecycleState' => 'PUBLISHED',
            ];

            if ($imageUrn) {
                $payload['content'] = [
                    'media' => [
                        'title' => mb_substr($text, 0, 200),
                        'id'    => $imageUrn,
                    ],
                ];
            }

            $data = $this->postJson('https://api.linkedin.com/rest/posts', $authHeaders, $payload);

            // Posts API returns 201 with x-restli-id header; body may be empty on success.
            if (!empty($data['id'])) {
                return ['success' => true, 'external_id' => (string)$data['id'], 'error' => null];
            }

            // Check for the common "created" response (empty body = success with 201).
            if (empty($data) || (isset($data['value']) && isset($data['value']['id']))) {
                $postId = $data['value']['id'] ?? 'created';
                return ['success' => true, 'external_id' => (string)$postId, 'error' => null];
            }

            $error = $data['message'] ?? $data['status'] ?? json_encode($data);
            return ['success' => false, 'external_id' => null, 'error' => "LinkedIn API error: {$error}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => "LinkedIn exception: {$e->getMessage()}"];
        }
    }

    /**
     * Upload an image to LinkedIn via the Images API and return the image URN.
     *
     * Uses POST /rest/images?action=initializeUpload with LinkedIn-Version header.
     */
    private function linkedInUploadImage(array $authHeaders, string $ownerUrn, string $filePath): ?string
    {
        $fileData = file_get_contents($filePath);
        if ($fileData === false) {
            return null;
        }

        // Ensure LinkedIn-Version header is present for REST API
        $restHeaders = $authHeaders;
        $hasVersion = false;
        foreach ($restHeaders as $h) {
            if (stripos($h, 'LinkedIn-Version') !== false) { $hasVersion = true; break; }
        }
        if (!$hasVersion) {
            $restHeaders[] = 'LinkedIn-Version: 202405';
        }

        // Step 1: Initialize the upload.
        $init = $this->postJson('https://api.linkedin.com/rest/images?action=initializeUpload', $restHeaders, [
            'initializeUploadRequest' => [
                'owner' => $ownerUrn,
            ],
        ]);

        $uploadUrl = $init['value']['uploadUrl'] ?? null;
        $imageUrn  = $init['value']['image'] ?? null;

        if (!$uploadUrl || !$imageUrn) {
            return null;
        }

        // Step 2: Upload the binary via PUT.
        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_HTTPHEADER     => array_merge($authHeaders, [
                'Content-Type: ' . (mime_content_type($filePath) ?: 'application/octet-stream'),
            ]),
            CURLOPT_POSTFIELDS     => $fileData,
            CURLOPT_TIMEOUT        => self::UPLOAD_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode >= 200 && $httpCode < 300) ? (string)$imageUrn : null;
    }

    // =========================================================================
    //  Threads  (Meta Graph API)
    // =========================================================================

    /**
     * Publish to Threads via Meta's Threads API (Graph API-based).
     *
     * Two-step flow similar to Instagram:
     *   1. Create media container: POST /{threads_user_id}/threads
     *   2. Publish: POST /{threads_user_id}/threads_publish
     *
     * meta_json must contain 'threads_user_id'. Requires publicly accessible image URL for media.
     */
    public function publishToThreads(array $account, string $text, ?string $mediaUrl = null): array
    {
        try {
            $meta     = $account['meta_json'] ?? [];
            $userId   = (string)($meta['threads_user_id'] ?? '');
            $token    = (string)($account['access_token'] ?? '');

            if ($userId === '' || $token === '') {
                return ['success' => false, 'external_id' => null, 'error' => 'Threads user_id or access_token missing'];
            }

            $baseUrl = "https://graph.threads.net/v2.0/{$userId}";

            // Step 1: Create container.
            $containerFields = [
                'text'          => $text,
                'media_type'    => ($mediaUrl !== null && $mediaUrl !== '') ? 'IMAGE' : 'TEXT',
                'access_token'  => $token,
            ];
            if ($mediaUrl !== null && $mediaUrl !== '') {
                $containerFields['image_url'] = $mediaUrl;
            }

            $container = $this->postForm("{$baseUrl}/threads", [], $containerFields);
            $creationId = $container['id'] ?? null;
            if ($creationId === null) {
                $error = $container['error']['message'] ?? json_encode($container);
                return ['success' => false, 'external_id' => null, 'error' => "Threads container error: {$error}"];
            }

            // Step 2: Publish.
            $publish = $this->postForm("{$baseUrl}/threads_publish", [], [
                'creation_id'  => (string)$creationId,
                'access_token' => $token,
            ]);

            if (!empty($publish['id'])) {
                return ['success' => true, 'external_id' => (string)$publish['id'], 'error' => null];
            }

            $error = $publish['error']['message'] ?? json_encode($publish);
            return ['success' => false, 'external_id' => null, 'error' => "Threads publish error: {$error}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => "Threads exception: {$e->getMessage()}"];
        }
    }

    // =========================================================================
    //  Pinterest  (REST API v5)
    // =========================================================================

    /**
     * Create a Pin on Pinterest.
     *
     * Auth: OAuth 2.0 Bearer token with pins:write scope.
     * meta_json must contain 'board_id'. Requires publicly accessible image URL.
     */
    public function publishToPinterest(array $account, string $text, ?string $mediaUrl = null): array
    {
        try {
            $meta    = $account['meta_json'] ?? [];
            $boardId = (string)($meta['board_id'] ?? '');
            $token   = (string)($account['access_token'] ?? '');

            if ($boardId === '' || $token === '') {
                return ['success' => false, 'external_id' => null, 'error' => 'Pinterest board_id or access_token missing'];
            }

            if ($mediaUrl === null || $mediaUrl === '') {
                return ['success' => false, 'external_id' => null, 'error' => 'Pinterest requires an image URL'];
            }

            $payload = [
                'board_id'    => $boardId,
                'media_source' => [
                    'source_type' => 'image_url',
                    'url'         => $mediaUrl,
                ],
                'description' => $text,
            ];

            // Use title from first line if present.
            $lines = explode("\n", $text, 2);
            if (count($lines) > 1) {
                $payload['title']       = trim($lines[0]);
                $payload['description'] = trim($lines[1]);
            }

            // Optional link from meta.
            if (!empty($meta['link'])) {
                $payload['link'] = $meta['link'];
            }

            $data = $this->postJson('https://api.pinterest.com/v5/pins', [
                "Authorization: Bearer {$token}",
            ], $payload);

            if (!empty($data['id'])) {
                return ['success' => true, 'external_id' => (string)$data['id'], 'error' => null];
            }

            $error = $data['message'] ?? json_encode($data);
            return ['success' => false, 'external_id' => null, 'error' => "Pinterest API error: {$error}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => "Pinterest exception: {$e->getMessage()}"];
        }
    }

    // =========================================================================
    //  TikTok  (Content Posting API)
    // =========================================================================

    /**
     * Publish to TikTok using the Content Posting API v2 (photo or video).
     *
     * Auth: OAuth 2.0 Bearer token. Requires 'video.publish' scope for video,
     * or 'video.upload' for photo mode.
     *
     * meta_json options:
     *   - privacy_level: PUBLIC_TO_EVERYONE | MUTUAL_FOLLOW_FRIENDS | FOLLOWER_OF_CREATOR | SELF_ONLY (default)
     *   - disable_comment: bool (default false)
     *   - disable_duet: bool (default false)
     *   - disable_stitch: bool (default false)
     *
     * TikTok's API uses a two-step init + upload flow.
     */
    public function publishToTikTok(array $account, string $text, ?string $mediaPath = null): array
    {
        try {
            $token = (string)($account['access_token'] ?? '');
            $meta  = $account['meta_json'] ?? [];

            if ($token === '') {
                return ['success' => false, 'external_id' => null, 'error' => 'TikTok access_token missing'];
            }

            if ($mediaPath === null || !is_file($mediaPath)) {
                return ['success' => false, 'external_id' => null, 'error' => 'TikTok requires a media file (photo or video)'];
            }

            $authHeaders = ["Authorization: Bearer {$token}"];
            $fileSize = filesize($mediaPath);

            // Privacy level from meta or default to PUBLIC_TO_EVERYONE for real publishing.
            $validPrivacy = ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'];
            $privacyLevel = strtoupper((string)($meta['privacy_level'] ?? 'PUBLIC_TO_EVERYONE'));
            if (!in_array($privacyLevel, $validPrivacy, true)) {
                $privacyLevel = 'PUBLIC_TO_EVERYONE';
            }

            // Detect media type from extension/mime.
            $mimeType = mime_content_type($mediaPath) ?: 'video/mp4';
            $isVideo  = str_starts_with($mimeType, 'video/');

            // Step 1: Initialize the upload.
            $initPayload = [
                'post_info' => [
                    'title'           => mb_substr($text, 0, 150),
                    'description'     => mb_substr($text, 0, 2200),
                    'privacy_level'   => $privacyLevel,
                    'disable_comment' => (bool)($meta['disable_comment'] ?? false),
                    'disable_duet'    => (bool)($meta['disable_duet'] ?? false),
                    'disable_stitch'  => (bool)($meta['disable_stitch'] ?? false),
                ],
                'source_info' => [
                    'source'            => 'FILE_UPLOAD',
                    'video_size'        => $fileSize,
                    'chunk_size'        => $fileSize,
                    'total_chunk_count' => 1,
                ],
            ];

            $initEndpoint = $isVideo
                ? 'https://open.tiktokapis.com/v2/post/publish/video/init/'
                : 'https://open.tiktokapis.com/v2/post/publish/content/init/';

            $init = $this->postJson($initEndpoint, $authHeaders, $initPayload);

            $uploadUrl = $init['data']['upload_url'] ?? null;
            $publishId = $init['data']['publish_id'] ?? null;

            if (!$uploadUrl || !$publishId) {
                $error = $init['error']['message'] ?? json_encode($init);
                return ['success' => false, 'external_id' => null, 'error' => "TikTok init error: {$error}"];
            }

            // Step 2: Upload the file.
            $fileData = file_get_contents($mediaPath);
            if ($fileData === false) {
                return ['success' => false, 'external_id' => null, 'error' => 'Failed to read media file'];
            }

            $ch = curl_init($uploadUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => 'PUT',
                CURLOPT_HTTPHEADER     => [
                    "Content-Type: {$mimeType}",
                    "Content-Length: {$fileSize}",
                    'Content-Range: bytes 0-' . ($fileSize - 1) . "/{$fileSize}",
                ],
                CURLOPT_POSTFIELDS     => $fileData,
                CURLOPT_TIMEOUT        => self::UPLOAD_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                return ['success' => true, 'external_id' => (string)$publishId, 'error' => null];
            }

            return ['success' => false, 'external_id' => null, 'error' => "TikTok upload failed (HTTP {$httpCode})"];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => "TikTok exception: {$e->getMessage()}"];
        }
    }

    // =========================================================================
    //  Reddit  (REST API)
    // =========================================================================

    /**
     * Submit a post to a Reddit subreddit.
     *
     * Auth: OAuth 2.0 Bearer token in access_token. meta_json must contain 'subreddit'.
     * For proper OAuth flow, meta_json should also contain 'client_id' and 'client_secret'
     * (used for token refresh). If only access_token is provided, it's used directly.
     *
     * Supports link posts (with media URL) or self posts (text only).
     */
    public function publishToReddit(array $account, string $text, ?string $mediaUrl = null): array
    {
        try {
            $meta      = $account['meta_json'] ?? [];
            $token     = (string)($account['access_token'] ?? '');
            $subreddit = (string)($meta['subreddit'] ?? '');
            $clientId  = (string)($meta['client_id'] ?? '');

            if ($token === '' || $subreddit === '') {
                return ['success' => false, 'external_id' => null, 'error' => 'Reddit access_token or subreddit missing'];
            }

            // Extract title from first line, body from rest.
            $lines = explode("\n", $text, 2);
            $title = trim($lines[0]);
            $body  = trim($lines[1] ?? '');

            $fields = [
                'sr'    => $subreddit,
                'title' => $title,
                'api_type' => 'json',
            ];

            if ($mediaUrl !== null && $mediaUrl !== '') {
                $fields['kind'] = 'link';
                $fields['url']  = $mediaUrl;
            } else {
                $fields['kind'] = 'self';
                $fields['text'] = $body;
            }

            // Reddit requires a descriptive User-Agent; include client_id if available.
            $userAgent = $clientId !== ''
                ? "php:MarketingSuite:v2.0 (by /u/{$clientId})"
                : 'MarketingSuite/2.0';

            $data = $this->postForm(
                'https://oauth.reddit.com/api/submit',
                ["Authorization: Bearer {$token}", "User-Agent: {$userAgent}"],
                $fields,
            );

            $postId = $data['json']['data']['id'] ?? null;
            if ($postId) {
                return ['success' => true, 'external_id' => (string)$postId, 'error' => null];
            }

            $errors = $data['json']['errors'] ?? [];
            $error = !empty($errors) ? json_encode($errors) : json_encode($data);
            return ['success' => false, 'external_id' => null, 'error' => "Reddit API error: {$error}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => "Reddit exception: {$e->getMessage()}"];
        }
    }

    // =========================================================================
    //  Telegram  (Bot API)
    // =========================================================================

    /**
     * Send a message to a Telegram channel or group via Bot API.
     *
     * Auth: Bot token in access_token. meta_json must contain 'chat_id'
     * (channel username like @mychannel or numeric chat ID).
     */
    public function publishToTelegram(array $account, string $text, ?string $mediaUrl = null): array
    {
        try {
            $meta   = $account['meta_json'] ?? [];
            $token  = (string)($account['access_token'] ?? '');
            $chatId = (string)($meta['chat_id'] ?? '');

            if ($token === '' || $chatId === '') {
                return ['success' => false, 'external_id' => null, 'error' => 'Telegram bot token or chat_id missing'];
            }

            $baseUrl = "https://api.telegram.org/bot{$token}";

            if ($mediaUrl !== null && $mediaUrl !== '') {
                // Send photo with caption.
                $data = $this->postJson("{$baseUrl}/sendPhoto", [], [
                    'chat_id'    => $chatId,
                    'photo'      => $mediaUrl,
                    'caption'    => mb_substr($text, 0, 1024),
                    'parse_mode' => 'HTML',
                ]);
            } else {
                // Text-only message.
                $data = $this->postJson("{$baseUrl}/sendMessage", [], [
                    'chat_id'    => $chatId,
                    'text'       => $text,
                    'parse_mode' => 'HTML',
                ]);
            }

            if (!empty($data['ok']) && !empty($data['result']['message_id'])) {
                return ['success' => true, 'external_id' => (string)$data['result']['message_id'], 'error' => null];
            }

            $error = $data['description'] ?? json_encode($data);
            return ['success' => false, 'external_id' => null, 'error' => "Telegram API error: {$error}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => "Telegram exception: {$e->getMessage()}"];
        }
    }

    // =========================================================================
    //  Discord  (Webhook)
    // =========================================================================

    /**
     * Post a message to a Discord channel via webhook.
     *
     * Auth: Webhook URL stored in access_token (no OAuth needed).
     * Format: https://discord.com/api/webhooks/{id}/{token}
     */
    public function publishToDiscord(array $account, string $text, ?string $mediaUrl = null): array
    {
        try {
            $webhookUrl = (string)($account['access_token'] ?? '');

            if ($webhookUrl === '' || !str_contains($webhookUrl, 'discord.com/api/webhooks/')) {
                return ['success' => false, 'external_id' => null, 'error' => 'Discord webhook URL missing or invalid'];
            }

            $payload = ['content' => mb_substr($text, 0, 2000)];

            if ($mediaUrl !== null && $mediaUrl !== '') {
                $payload['embeds'] = [[
                    'image' => ['url' => $mediaUrl],
                ]];
            }

            $data = $this->postJson($webhookUrl . '?wait=true', [], $payload);

            if (!empty($data['id'])) {
                return ['success' => true, 'external_id' => (string)$data['id'], 'error' => null];
            }

            $error = $data['message'] ?? json_encode($data);
            return ['success' => false, 'external_id' => null, 'error' => "Discord API error: {$error}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => "Discord exception: {$e->getMessage()}"];
        }
    }

    // =========================================================================
    //  Slack  (Incoming Webhook)
    // =========================================================================

    /**
     * Post a message to a Slack channel via incoming webhook.
     *
     * Auth: Webhook URL stored in access_token (no OAuth needed).
     */
    public function publishToSlack(array $account, string $text): array
    {
        try {
            $webhookUrl = (string)($account['access_token'] ?? '');

            if ($webhookUrl === '' || !str_contains($webhookUrl, 'hooks.slack.com/')) {
                return ['success' => false, 'external_id' => null, 'error' => 'Slack webhook URL missing or invalid'];
            }

            $payload = ['text' => $text];

            $ch = curl_init($webhookUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_TIMEOUT        => self::TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $raw = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error !== '') {
                return ['success' => false, 'external_id' => null, 'error' => "Slack error: {$error}"];
            }

            // Slack webhooks return "ok" as plain text on success.
            if ($raw === 'ok') {
                return ['success' => true, 'external_id' => null, 'error' => null];
            }

            return ['success' => false, 'external_id' => null, 'error' => "Slack error: {$raw}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => "Slack exception: {$e->getMessage()}"];
        }
    }

    // =========================================================================
    //  WordPress  (REST API)
    // =========================================================================

    /**
     * Create a post on a WordPress site via the WP REST API.
     *
     * Auth: Application password in access_token (format: "username:app_password").
     * meta_json must contain 'site_url' (e.g. "https://myblog.com").
     */
    public function publishToWordPress(array $account, string $text, ?string $mediaPath = null): array
    {
        try {
            $meta    = $account['meta_json'] ?? [];
            $siteUrl = rtrim((string)($meta['site_url'] ?? ''), '/');
            $creds   = (string)($account['access_token'] ?? '');

            if ($siteUrl === '' || $creds === '') {
                return ['success' => false, 'external_id' => null, 'error' => 'WordPress site_url or credentials missing'];
            }

            $authHeaders = ['Authorization: Basic ' . base64_encode($creds)];

            // Extract title from first line.
            $lines   = explode("\n", $text, 2);
            $title   = trim($lines[0]);
            $content = trim($lines[1] ?? $text);

            $payload = [
                'title'   => $title,
                'content' => $content,
                'status'  => ($meta['status'] ?? 'draft'),  // 'draft' or 'publish'
            ];

            if (!empty($meta['categories'])) {
                $payload['categories'] = array_map('intval', (array)$meta['categories']);
            }

            // Upload featured image if provided.
            if ($mediaPath !== null && is_file($mediaPath)) {
                $mediaId = $this->wordPressUploadMedia($siteUrl, $authHeaders, $mediaPath);
                if ($mediaId !== null) {
                    $payload['featured_media'] = $mediaId;
                }
            }

            $data = $this->postJson("{$siteUrl}/wp-json/wp/v2/posts", $authHeaders, $payload);

            if (!empty($data['id'])) {
                return ['success' => true, 'external_id' => (string)$data['id'], 'error' => null];
            }

            $error = $data['message'] ?? json_encode($data);
            return ['success' => false, 'external_id' => null, 'error' => "WordPress API error: {$error}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => "WordPress exception: {$e->getMessage()}"];
        }
    }

    /**
     * Upload a media file to WordPress and return the media ID.
     */
    private function wordPressUploadMedia(string $siteUrl, array $authHeaders, string $filePath): ?int
    {
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        $fileName = basename($filePath);
        $fileData = file_get_contents($filePath);
        if ($fileData === false) {
            return null;
        }

        $ch = curl_init("{$siteUrl}/wp-json/wp/v2/media");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => array_merge($authHeaders, [
                "Content-Type: {$mimeType}",
                "Content-Disposition: attachment; filename=\"{$fileName}\"",
            ]),
            CURLOPT_POSTFIELDS     => $fileData,
            CURLOPT_TIMEOUT        => self::UPLOAD_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($ch);
        curl_close($ch);

        if (!is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);
        return isset($decoded['id']) ? (int)$decoded['id'] : null;
    }

    // =========================================================================
    //  Medium  (REST API)
    // =========================================================================

    /**
     * Publish an article to Medium.
     *
     * Auth: Integration token in access_token.
     * First line of text becomes the title.
     *
     * NOTE: Medium stopped issuing new integration tokens in 2023.
     * This integration is maintained for existing token holders but may stop working.
     * Consider using the WordPress integration as an alternative.
     */
    public function publishToMedium(array $account, string $text): array
    {
        try {
            $token = (string)($account['access_token'] ?? '');
            if ($token === '') {
                return ['success' => false, 'external_id' => null, 'error' => 'Medium integration token missing'];
            }

            $authHeaders = ["Authorization: Bearer {$token}"];

            // Get authenticated user ID.
            $ch = curl_init('https://api.medium.com/v1/me');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $authHeaders,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);

            $me = is_string($raw) ? json_decode($raw, true) : [];
            $userId = $me['data']['id'] ?? null;
            if (!$userId) {
                return ['success' => false, 'external_id' => null, 'error' => 'Failed to get Medium user ID'];
            }

            // Extract title from first line.
            $lines   = explode("\n", $text, 2);
            $title   = trim($lines[0]);
            $content = trim($lines[1] ?? $text);
            $meta    = $account['meta_json'] ?? [];

            $payload = [
                'title'         => $title,
                'contentFormat' => 'html',
                'content'       => nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8')),
                'publishStatus' => ($meta['publish_status'] ?? 'draft'),  // 'draft' or 'public'
            ];

            if (!empty($meta['tags'])) {
                $payload['tags'] = array_slice((array)$meta['tags'], 0, 5);
            }

            $data = $this->postJson(
                "https://api.medium.com/v1/users/{$userId}/posts",
                $authHeaders,
                $payload,
            );

            if (!empty($data['data']['id'])) {
                return ['success' => true, 'external_id' => (string)$data['data']['id'], 'error' => null];
            }

            $error = $data['errors'][0]['message'] ?? json_encode($data);
            return ['success' => false, 'external_id' => null, 'error' => "Medium API error: {$error}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'external_id' => null, 'error' => "Medium exception: {$e->getMessage()}"];
        }
    }

    // =========================================================================
    //  Publish logging & history
    // =========================================================================

    /**
     * Insert a row into the publish_log table.
     */
    public function logPublish(
        int $postId,
        string $platform,
        int $accountId,
        ?string $externalId,
        string $status,
        ?string $error,
    ): void {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO publish_log (post_id, platform, social_account_id, external_id, status, error_message, published_at)
                 VALUES (:post_id, :platform, :social_account_id, :external_id, :status, :error_message, :published_at)'
            );
            $stmt->execute([
                ':post_id'            => $postId,
                ':platform'           => $platform,
                ':social_account_id'  => $accountId,
                ':external_id'        => $externalId,
                ':status'             => $status,
                ':error_message'      => $error,
                ':published_at'       => gmdate(DATE_ATOM),
            ]);
        } catch (\Throwable $e) {
            // Logging failure should not break the publish flow.
            error_log("SocialPublisher::logPublish failed: {$e->getMessage()}");
        }
    }

    /**
     * Retrieve all publish_log entries for a given post, newest first.
     *
     * @return list<array>
     */
    public function getPublishHistory(int $postId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM publish_log WHERE post_id = :post_id ORDER BY published_at DESC, id DESC'
            );
            $stmt->execute([':post_id' => $postId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log("SocialPublisher::getPublishHistory failed: {$e->getMessage()}");
            return [];
        }
    }

    // =========================================================================
    //  Connection testing
    // =========================================================================

    /**
     * Test connection to a social platform by making a lightweight API call.
     *
     * @param  array $account Decoded account row.
     * @return array{success: bool, error: string|null, info: string|null}
     */
    public function testConnection(array $account): array
    {
        $account  = $this->decodeMeta($account);
        $platform = $account['platform'] ?? '';
        $token    = (string)($account['access_token'] ?? '');
        $meta     = $account['meta_json'] ?? [];

        if ($token === '' && !in_array($platform, ['bluesky'], true)) {
            return ['success' => false, 'error' => 'Access token is empty', 'info' => null];
        }

        try {
            return match ($platform) {
                'twitter'   => $this->testTwitter($token),
                'bluesky'   => $this->testBluesky($meta),
                'mastodon'  => $this->testMastodon($token, $meta),
                'facebook'  => $this->testFacebook($token, $meta),
                'instagram' => $this->testInstagram($token, $meta),
                'linkedin'  => $this->testLinkedIn($token),
                'threads'   => $this->testThreads($token, $meta),
                'pinterest' => $this->testPinterest($token),
                'tiktok'    => $this->testTikTok($token),
                'reddit'    => $this->testReddit($token, $meta),
                'telegram'  => $this->testTelegram($token, $meta),
                'discord'   => $this->testDiscord($token),
                'slack'     => $this->testSlack($token),
                'wordpress' => $this->testWordPress($token, $meta),
                'medium'    => $this->testMedium($token),
                default     => ['success' => false, 'error' => "Unknown platform: {$platform}", 'info' => null],
            };
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'info' => null];
        }
    }

    private function testTwitter(string $token): array
    {
        $data = $this->getJson('https://api.twitter.com/2/users/me', ["Authorization: Bearer {$token}"]);
        if (!empty($data['data']['id'])) {
            return ['success' => true, 'error' => null, 'info' => '@' . ($data['data']['username'] ?? 'unknown')];
        }
        return ['success' => false, 'error' => $data['detail'] ?? $data['title'] ?? 'Auth failed', 'info' => null];
    }

    private function testBluesky(array $meta): array
    {
        $identifier = (string)($meta['identifier'] ?? '');
        $password   = (string)($meta['password'] ?? '');
        if ($identifier === '' || $password === '') {
            return ['success' => false, 'error' => 'Identifier and password required', 'info' => null];
        }
        $session = $this->postJson('https://bsky.social/xrpc/com.atproto.server.createSession', [], [
            'identifier' => $identifier, 'password' => $password,
        ]);
        if (!empty($session['did'])) {
            return ['success' => true, 'error' => null, 'info' => $session['handle'] ?? $identifier];
        }
        return ['success' => false, 'error' => $session['message'] ?? 'Auth failed', 'info' => null];
    }

    private function testMastodon(string $token, array $meta): array
    {
        $instanceUrl = rtrim((string)($meta['instance_url'] ?? ''), '/');
        if ($instanceUrl === '') {
            return ['success' => false, 'error' => 'Instance URL required', 'info' => null];
        }
        $data = $this->getJson("{$instanceUrl}/api/v1/accounts/verify_credentials", ["Authorization: Bearer {$token}"]);
        if (!empty($data['id'])) {
            return ['success' => true, 'error' => null, 'info' => '@' . ($data['acct'] ?? 'unknown')];
        }
        return ['success' => false, 'error' => $data['error'] ?? 'Auth failed', 'info' => null];
    }

    private function testFacebook(string $token, array $meta): array
    {
        $pageId = (string)($meta['page_id'] ?? '');
        if ($pageId === '') {
            return ['success' => false, 'error' => 'Page ID required', 'info' => null];
        }
        $data = $this->getJson("https://graph.facebook.com/v22.0/{$pageId}?fields=name,id&access_token={$token}", []);
        if (!empty($data['name'])) {
            return ['success' => true, 'error' => null, 'info' => $data['name']];
        }
        return ['success' => false, 'error' => $data['error']['message'] ?? 'Auth failed', 'info' => null];
    }

    private function testInstagram(string $token, array $meta): array
    {
        $igId = (string)($meta['ig_account_id'] ?? '');
        if ($igId === '') {
            return ['success' => false, 'error' => 'Instagram Account ID required', 'info' => null];
        }
        $data = $this->getJson("https://graph.facebook.com/v22.0/{$igId}?fields=username,id&access_token={$token}", []);
        if (!empty($data['username'])) {
            return ['success' => true, 'error' => null, 'info' => '@' . $data['username']];
        }
        return ['success' => false, 'error' => $data['error']['message'] ?? 'Auth failed', 'info' => null];
    }

    private function testLinkedIn(string $token): array
    {
        $data = $this->getJson('https://api.linkedin.com/v2/userinfo', [
            "Authorization: Bearer {$token}",
            'LinkedIn-Version: 202501',
        ]);
        if (!empty($data['sub'])) {
            return ['success' => true, 'error' => null, 'info' => $data['name'] ?? $data['email'] ?? 'Connected'];
        }
        return ['success' => false, 'error' => $data['message'] ?? 'Auth failed', 'info' => null];
    }

    private function testThreads(string $token, array $meta): array
    {
        $userId = (string)($meta['threads_user_id'] ?? '');
        if ($userId === '') {
            return ['success' => false, 'error' => 'Threads User ID required', 'info' => null];
        }
        $data = $this->getJson("https://graph.threads.net/v2.0/{$userId}?fields=username&access_token={$token}", []);
        if (!empty($data['username'])) {
            return ['success' => true, 'error' => null, 'info' => '@' . $data['username']];
        }
        return ['success' => false, 'error' => $data['error']['message'] ?? 'Auth failed', 'info' => null];
    }

    private function testPinterest(string $token): array
    {
        $data = $this->getJson('https://api.pinterest.com/v5/user_account', ["Authorization: Bearer {$token}"]);
        if (!empty($data['username'])) {
            return ['success' => true, 'error' => null, 'info' => '@' . $data['username']];
        }
        return ['success' => false, 'error' => $data['message'] ?? 'Auth failed', 'info' => null];
    }

    private function testTikTok(string $token): array
    {
        $data = $this->getJson('https://open.tiktokapis.com/v2/user/info/?fields=display_name,username', [
            "Authorization: Bearer {$token}",
        ]);
        if (!empty($data['data']['user']['username'])) {
            return ['success' => true, 'error' => null, 'info' => '@' . $data['data']['user']['username']];
        }
        return ['success' => false, 'error' => $data['error']['message'] ?? 'Auth failed', 'info' => null];
    }

    private function testReddit(string $token, array $meta): array
    {
        $clientId = (string)($meta['client_id'] ?? '');
        $userAgent = $clientId !== ''
            ? "php:MarketingSuite:v2.0 (by /u/{$clientId})"
            : 'MarketingSuite/2.0';
        $data = $this->getJson('https://oauth.reddit.com/api/v1/me', [
            "Authorization: Bearer {$token}",
            "User-Agent: {$userAgent}",
        ]);
        if (!empty($data['name'])) {
            return ['success' => true, 'error' => null, 'info' => 'u/' . $data['name']];
        }
        return ['success' => false, 'error' => $data['message'] ?? 'Auth failed', 'info' => null];
    }

    private function testTelegram(string $token, array $meta): array
    {
        $chatId = (string)($meta['chat_id'] ?? '');
        if ($chatId === '') {
            return ['success' => false, 'error' => 'Chat ID required', 'info' => null];
        }
        $data = $this->getJson("https://api.telegram.org/bot{$token}/getMe", []);
        if (!empty($data['ok']) && !empty($data['result']['username'])) {
            return ['success' => true, 'error' => null, 'info' => '@' . $data['result']['username']];
        }
        return ['success' => false, 'error' => $data['description'] ?? 'Auth failed', 'info' => null];
    }

    private function testDiscord(string $webhookUrl): array
    {
        if (!str_contains($webhookUrl, 'discord.com/api/webhooks/')) {
            return ['success' => false, 'error' => 'Invalid webhook URL', 'info' => null];
        }
        $data = $this->getJson($webhookUrl, []);
        if (!empty($data['id'])) {
            return ['success' => true, 'error' => null, 'info' => $data['name'] ?? 'Webhook active'];
        }
        return ['success' => false, 'error' => $data['message'] ?? 'Webhook invalid', 'info' => null];
    }

    private function testSlack(string $webhookUrl): array
    {
        if (!str_contains($webhookUrl, 'hooks.slack.com/')) {
            return ['success' => false, 'error' => 'Invalid webhook URL', 'info' => null];
        }
        // Slack webhooks don't have a test endpoint; we validate the URL format.
        return ['success' => true, 'error' => null, 'info' => 'Webhook URL format valid'];
    }

    private function testWordPress(string $creds, array $meta): array
    {
        $siteUrl = rtrim((string)($meta['site_url'] ?? ''), '/');
        if ($siteUrl === '') {
            return ['success' => false, 'error' => 'Site URL required', 'info' => null];
        }
        $data = $this->getJson("{$siteUrl}/wp-json/wp/v2/users/me", [
            'Authorization: Basic ' . base64_encode($creds),
        ]);
        if (!empty($data['name'])) {
            return ['success' => true, 'error' => null, 'info' => $data['name']];
        }
        return ['success' => false, 'error' => $data['message'] ?? 'Auth failed', 'info' => null];
    }

    private function testMedium(string $token): array
    {
        $data = $this->getJson('https://api.medium.com/v1/me', ["Authorization: Bearer {$token}"]);
        if (!empty($data['data']['id'])) {
            return ['success' => true, 'error' => null, 'info' => $data['data']['username'] ?? 'Connected'];
        }
        return ['success' => false, 'error' => 'Auth failed — Medium stopped issuing new tokens in 2023', 'info' => null];
    }

    // =========================================================================
    //  Token refresh
    // =========================================================================

    /**
     * Refresh an OAuth token for the given account.
     *
     * Supports token refresh for platforms that use OAuth 2.0 with refresh tokens:
     * - Facebook: Exchange short-lived token for long-lived token
     * - Twitter/X: OAuth 2.0 PKCE refresh_token grant
     *
     * @param  array $account Decoded account row (must contain refresh_token for applicable platforms).
     * @return array|null     Updated token fields [access_token, refresh_token?, token_expires?], or null if not supported.
     */
    public function refreshToken(array $account): ?array
    {
        $account = $this->decodeMeta($account);

        return match ($account['platform'] ?? '') {
            'facebook', 'instagram' => $this->refreshFacebookToken($account),
            'twitter'               => $this->refreshTwitterToken($account),
            default                 => null,
        };
    }

    /**
     * Exchange a Facebook/Instagram short-lived token for a long-lived one (~60 days).
     */
    private function refreshFacebookToken(array $account): ?array
    {
        $meta         = $account['meta_json'] ?? [];
        $token        = (string)($account['access_token'] ?? '');
        $appId        = (string)($meta['app_id'] ?? '');
        $appSecret    = (string)($meta['app_secret'] ?? '');

        if ($token === '' || $appId === '' || $appSecret === '') {
            return null;
        }

        $data = $this->getJson(
            "https://graph.facebook.com/v22.0/oauth/access_token?grant_type=fb_exchange_token&client_id={$appId}&client_secret={$appSecret}&fb_exchange_token={$token}",
            [],
        );

        if (!empty($data['access_token'])) {
            return [
                'access_token'  => $data['access_token'],
                'token_expires' => isset($data['expires_in'])
                    ? gmdate(DATE_ATOM, time() + (int)$data['expires_in'])
                    : null,
            ];
        }

        return null;
    }

    /**
     * Refresh a Twitter/X OAuth 2.0 PKCE access token using the refresh_token grant.
     */
    private function refreshTwitterToken(array $account): ?array
    {
        $meta         = $account['meta_json'] ?? [];
        $refreshToken = (string)($account['refresh_token'] ?? '');
        $clientId     = (string)($meta['client_id'] ?? '');

        if ($refreshToken === '' || $clientId === '') {
            return null;
        }

        $data = $this->postForm('https://api.twitter.com/2/oauth2/token', [], [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => $clientId,
        ]);

        if (!empty($data['access_token'])) {
            return [
                'access_token'  => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? $refreshToken,
                'token_expires' => isset($data['expires_in'])
                    ? gmdate(DATE_ATOM, time() + (int)$data['expires_in'])
                    : null,
            ];
        }

        return null;
    }

    // =========================================================================
    //  HTTP helpers
    // =========================================================================

    /**
     * Send a POST request with a JSON body and return the decoded response.
     *
     * Mirrors the pattern from AiService::postJson().
     *
     * @param  string $url     Fully qualified URL.
     * @param  array  $headers Additional HTTP headers (no Content-Type needed — set automatically).
     * @param  array  $payload Data to JSON-encode as the request body.
     * @param  int    $timeout Request timeout in seconds.
     * @return array  Decoded JSON response, or empty array on failure.
     */
    private function postJson(string $url, array $headers, array $payload, int $timeout = self::TIMEOUT): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw   = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '' || !is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Send a POST request with multipart/form-data (or URL-encoded) fields.
     *
     * Use CURLFile objects in $fields for file uploads; cURL will automatically
     * switch to multipart encoding when it encounters a CURLFile value.
     *
     * @param  string $url     Fully qualified URL.
     * @param  array  $headers Additional HTTP headers (Content-Type set by cURL for multipart).
     * @param  array  $fields  Key-value form fields. Values may be strings or CURLFile instances.
     * @param  int    $timeout Request timeout in seconds.
     * @return array  Decoded JSON response, or empty array on failure.
     */
    private function postForm(string $url, array $headers, array $fields, int $timeout = self::TIMEOUT): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $fields,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw   = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '' || !is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Send a GET request and return the decoded JSON response.
     *
     * @param  string $url     Fully qualified URL.
     * @param  array  $headers HTTP headers.
     * @param  int    $timeout Request timeout in seconds.
     * @return array  Decoded JSON response, or empty array on failure.
     */
    private function getJson(string $url, array $headers, int $timeout = self::TIMEOUT): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw   = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '' || !is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    // =========================================================================
    //  Internal helpers
    // =========================================================================

    /**
     * Ensure $account['meta_json'] is a decoded array.
     *
     * Accounts may store meta as a JSON string in the database; this normalizes it.
     */
    private function decodeMeta(array $account): array
    {
        $meta = $account['meta_json'] ?? '{}';
        if (is_string($meta)) {
            $account['meta_json'] = json_decode($meta, true) ?: [];
        }
        return $account;
    }
}
