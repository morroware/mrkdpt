<?php

declare(strict_types=1);

/**
 * SocialPublisher — zero-dependency social media publishing service.
 *
 * Supports Twitter/X, Bluesky, Mastodon, Facebook Pages, and Instagram.
 * Uses only cURL for HTTP; no Composer packages required.
 *
 * Each platform method expects an $account array containing at minimum:
 *   - id            (int)    — internal account row ID
 *   - platform      (string) — one of: twitter, bluesky, mastodon, facebook, instagram
 *   - access_token  (string) — OAuth token (usage varies per platform)
 *   - meta_json     (string|array) — JSON string or decoded array with platform-specific fields
 */
final class SocialPublisher
{
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

        $result = match ($platform) {
            'twitter'   => $this->publishToTwitter($account, $text, $mediaPath),
            'bluesky'   => $this->publishToBluesky($account, $text, $mediaPath),
            'mastodon'  => $this->publishToMastodon($account, $text, $mediaPath),
            'facebook'  => $this->publishToFacebook($account, $text, $mediaPath),
            'instagram' => $this->publishToInstagram($account, $text, $mediaUrl),
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

    // =========================================================================
    //  Twitter / X  (API v2 + v1.1 media upload)
    // =========================================================================

    /**
     * Publish a tweet via Twitter API v2.
     *
     * Auth: OAuth 2.0 Bearer token (User-context) in $account['access_token'].
     * Media upload uses the v1.1 chunked upload endpoint because v2 has no media route.
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
     * Upload media to Twitter via v1.1 simple media/upload (images < 5 MB).
     *
     * Returns the media_id_string on success, or null on failure.
     */
    private function twitterUploadMedia(string $token, string $filePath): ?string
    {
        $data = $this->postForm(
            'https://upload.twitter.com/1.1/media/upload.json',
            ["Authorization: Bearer {$token}"],
            ['media_data' => base64_encode((string)file_get_contents($filePath))],
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
    //  Facebook Pages  (Graph API v19.0)
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

            $baseUrl = "https://graph.facebook.com/v19.0/{$pageId}";

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

            $baseUrl = "https://graph.facebook.com/v19.0/{$igId}";

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
    //  Token refresh (stub)
    // =========================================================================

    /**
     * Refresh an OAuth token for the given account.
     *
     * This is a stub for future implementation. Each platform will require its own
     * refresh logic (e.g. Facebook long-lived tokens, Twitter OAuth 2.0 refresh_token).
     *
     * @param  array $account Decoded account row.
     * @return array|null     New token data, or null if not implemented / not needed.
     */
    public function refreshToken(array $account): ?array
    {
        // TODO: Implement per-platform token refresh logic.
        // Example future structure:
        //   return match ($account['platform'] ?? '') {
        //       'facebook'  => $this->refreshFacebookToken($account),
        //       'twitter'   => $this->refreshTwitterToken($account),
        //       'mastodon'  => $this->refreshMastodonToken($account),
        //       default     => null,
        //   };

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
