<?php

declare(strict_types=1);

class EmailService
{
    private \PDO $db;
    private string $smtpHost;
    private int $smtpPort;
    private string $smtpUser;
    private string $smtpPass;
    private string $smtpFrom;
    private string $smtpFromName;
    private string $baseUrl;

    public function __construct(\PDO $db, array $config)
    {
        $this->db = $db;
        $this->smtpHost = $config['smtp_host'] ?? 'localhost';
        $this->smtpPort = (int) ($config['smtp_port'] ?? 587);
        $this->smtpUser = $config['smtp_user'] ?? '';
        $this->smtpPass = $config['smtp_pass'] ?? '';
        $this->smtpFrom = $config['smtp_from'] ?? '';
        $this->smtpFromName = $config['smtp_from_name'] ?? '';
        $this->baseUrl = rtrim($config['base_url'] ?? '', '/');
    }

    // =========================================================================
    // SMTP Client (private methods)
    // =========================================================================

    /**
     * Open a socket connection to the SMTP server.
     *
     * @return resource|false
     */
    private function smtpConnect()
    {
        $errno = 0;
        $errstr = '';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);

        $socket = @stream_socket_client(
            "tcp://{$this->smtpHost}:{$this->smtpPort}",
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            return false;
        }

        stream_set_timeout($socket, 30);

        return $socket;
    }

    /**
     * Send an SMTP command and validate the response code.
     *
     * @param resource $socket
     */
    private function smtpCommand($socket, string $command, int $expectedCode): string
    {
        if ($command !== '') {
            fwrite($socket, $command . "\r\n");
        }

        $response = '';
        while (true) {
            $line = fgets($socket, 4096);
            if ($line === false) {
                throw new \RuntimeException("SMTP: Lost connection while awaiting response to: {$command}");
            }
            $response .= $line;

            $meta = stream_get_meta_data($socket);
            if ($meta['timed_out']) {
                throw new \RuntimeException("SMTP: Timeout while awaiting response to: {$command}");
            }

            // RFC 5321: continuation lines have a dash after the code; final line has a space.
            if (isset($line[3]) && $line[3] !== '-') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new \RuntimeException(
                "SMTP: Expected {$expectedCode} but got {$code}. Response: " . trim($response)
            );
        }

        return $response;
    }

    /**
     * Upgrade the connection to TLS via STARTTLS.
     *
     * @param resource $socket
     */
    private function smtpStartTls($socket): bool
    {
        $this->smtpCommand($socket, 'STARTTLS', 220);

        $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($crypto !== true) {
            throw new \RuntimeException('SMTP: TLS negotiation failed.');
        }

        return true;
    }

    /**
     * Authenticate using AUTH LOGIN with base64-encoded credentials.
     *
     * @param resource $socket
     */
    private function smtpAuthenticate($socket, string $user, string $pass): bool
    {
        $this->smtpCommand($socket, 'AUTH LOGIN', 334);
        $this->smtpCommand($socket, base64_encode($user), 334);
        $this->smtpCommand($socket, base64_encode($pass), 235);

        return true;
    }

    /**
     * Full SMTP send flow:
     * connect -> EHLO -> STARTTLS (if 587) -> AUTH -> MAIL FROM -> RCPT TO -> DATA -> QUIT
     */
    private function sendSmtp(
        string $to,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody,
        array $headers = []
    ): bool {
        $socket = $this->smtpConnect();
        if ($socket === false) {
            throw new \RuntimeException("SMTP: Unable to connect to {$this->smtpHost}:{$this->smtpPort}");
        }

        try {
            // Read server greeting
            $this->smtpCommand($socket, '', 220);

            // EHLO
            $this->smtpCommand($socket, 'EHLO ' . gethostname(), 250);

            // STARTTLS for submission port
            if ($this->smtpPort === 587) {
                $this->smtpStartTls($socket);
                // Re-issue EHLO after TLS
                $this->smtpCommand($socket, 'EHLO ' . gethostname(), 250);
            }

            // Authenticate
            if ($this->smtpUser !== '' && $this->smtpPass !== '') {
                $this->smtpAuthenticate($socket, $this->smtpUser, $this->smtpPass);
            }

            // MAIL FROM
            $this->smtpCommand($socket, "MAIL FROM:<{$this->smtpFrom}>", 250);

            // RCPT TO
            $this->smtpCommand($socket, "RCPT TO:<{$to}>", 250);

            // DATA
            $this->smtpCommand($socket, 'DATA', 354);

            // Build the full message
            $boundary = 'boundary_' . bin2hex(random_bytes(16));
            $headerBlock = $this->buildHeaders($to, $toName, $subject, $boundary, $headers);
            $mimeBody = $this->buildMimeMessage($htmlBody, $textBody, $boundary);

            $message = $headerBlock . "\r\n" . $mimeBody;

            // Dot-stuffing: any line beginning with a dot must be doubled (RFC 5321 4.5.2)
            $lines = explode("\r\n", $message);
            $stuffed = [];
            foreach ($lines as $line) {
                if (isset($line[0]) && $line[0] === '.') {
                    $line = '.' . $line;
                }
                $stuffed[] = $line;
            }
            $message = implode("\r\n", $stuffed);

            // Send message data followed by terminating sequence
            fwrite($socket, $message . "\r\n.\r\n");

            // Read DATA response
            $this->smtpCommand($socket, '', 250);

            // QUIT
            $this->smtpCommand($socket, 'QUIT', 221);

            return true;
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    // =========================================================================
    // MIME Message Construction
    // =========================================================================

    /**
     * Build a multipart/alternative MIME body with plain text and HTML parts.
     */
    private function buildMimeMessage(string $htmlBody, string $textBody, string $boundary): string
    {
        $message = "This is a multi-part message in MIME format.\r\n\r\n";

        // Plain text part
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $message .= quoted_printable_encode($textBody) . "\r\n\r\n";

        // HTML part
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $message .= quoted_printable_encode($htmlBody) . "\r\n\r\n";

        // Closing boundary
        $message .= "--{$boundary}--\r\n";

        return $message;
    }

    /**
     * Build RFC-compliant email headers.
     */
    private function buildHeaders(
        string $to,
        string $toName,
        string $subject,
        string $boundary,
        array $extraHeaders = []
    ): string {
        $fromEncoded = $this->encodeHeaderValue($this->smtpFromName);
        $toEncoded = $this->encodeHeaderValue($toName);
        $subjectEncoded = $this->encodeHeaderValue($subject);

        $messageId = '<' . bin2hex(random_bytes(16)) . '@' . $this->smtpHost . '>';

        $headers = [];
        $headers[] = "From: {$fromEncoded} <{$this->smtpFrom}>";
        $headers[] = "To: {$toEncoded} <{$to}>";
        $headers[] = "Subject: {$subjectEncoded}";
        $headers[] = "Date: " . date('r');
        $headers[] = "Message-ID: {$messageId}";
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
        $headers[] = "X-Mailer: EmailService/1.0";

        foreach ($extraHeaders as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }

        return implode("\r\n", $headers) . "\r\n";
    }

    /**
     * RFC 2047 encode a header value if it contains non-ASCII characters.
     */
    private function encodeHeaderValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    // =========================================================================
    // Campaign Sending
    // =========================================================================

    /**
     * Send a campaign to all active subscribers on the associated list.
     *
     * @return array{sent: int, failed: int, errors: string[]}
     */
    public function sendCampaign(int $campaignId): array
    {
        $stats = ['sent' => 0, 'failed' => 0, 'errors' => []];

        try {
            // Load campaign
            $stmt = $this->db->prepare(
                'SELECT * FROM campaigns WHERE id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $campaignId]);
            $campaign = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$campaign) {
                $stats['errors'][] = "Campaign {$campaignId} not found.";
                return $stats;
            }

            // Load active subscribers for the campaign's list
            $stmt = $this->db->prepare(
                "SELECT * FROM subscribers WHERE list_id = :list_id AND status = 'active'"
            );
            $stmt->execute([':list_id' => $campaign['list_id']]);
            $subscribers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($subscribers)) {
                $stats['errors'][] = 'No active subscribers found for this campaign list.';
                return $stats;
            }

            foreach ($subscribers as $subscriber) {
                try {
                    $htmlBody = $this->processMergeTags(
                        $campaign['html_content'],
                        $subscriber,
                        $campaignId
                    );
                    $textBody = $this->processMergeTags(
                        $campaign['text_content'],
                        $subscriber,
                        $campaignId
                    );

                    $success = $this->sendSmtp(
                        $subscriber['email'],
                        $subscriber['name'] ?? '',
                        $campaign['subject'],
                        $htmlBody,
                        $textBody,
                        ['List-Unsubscribe' => '<' . $this->getUnsubscribeUrl((int) $subscriber['id'], (int) $campaign['list_id']) . '>']
                    );

                    if ($success) {
                        $stats['sent']++;
                        $this->recordSend($campaignId, (int) $subscriber['id']);
                    } else {
                        $stats['failed']++;
                        $stats['errors'][] = "Failed to send to {$subscriber['email']}: unknown error.";
                    }
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    $stats['errors'][] = "Failed to send to {$subscriber['email']}: {$e->getMessage()}";
                }
            }

            // Update campaign status
            $stmt = $this->db->prepare(
                'UPDATE campaigns SET status = :status, sent_at = :sent_at WHERE id = :id'
            );
            $stmt->execute([
                ':status' => 'sent',
                ':sent_at' => date('Y-m-d H:i:s'),
                ':id' => $campaignId,
            ]);
        } catch (\Throwable $e) {
            $stats['errors'][] = "Campaign send error: {$e->getMessage()}";
        }

        return $stats;
    }

    /**
     * Send a single test email.
     */
    public function sendTestEmail(string $to, string $subject, string $html, string $text): bool
    {
        try {
            return $this->sendSmtp($to, '', $subject, $html, $text);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Replace merge tags with actual subscriber/campaign values.
     */
    public function processMergeTags(string $content, array $subscriber, int $campaignId): string
    {
        $subscriberId = (int) ($subscriber['id'] ?? 0);
        $listId = (int) ($subscriber['list_id'] ?? 0);

        $replacements = [
            '{{name}}' => htmlspecialchars($subscriber['name'] ?? '', ENT_QUOTES, 'UTF-8'),
            '{{email}}' => htmlspecialchars($subscriber['email'] ?? '', ENT_QUOTES, 'UTF-8'),
            '{{unsubscribe_url}}' => $this->getUnsubscribeUrl($subscriberId, $listId),
            '{{tracking_pixel}}' => $this->generateTrackingPixel($campaignId, $subscriberId),
            '{{date}}' => date('Y-m-d'),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    /**
     * Record a successful send in the tracking table.
     */
    private function recordSend(int $campaignId, int $subscriberId): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO email_tracking (campaign_id, subscriber_id, event_type, created_at)
                 VALUES (:campaign_id, :subscriber_id, :event_type, :created_at)'
            );
            $stmt->execute([
                ':campaign_id' => $campaignId,
                ':subscriber_id' => $subscriberId,
                ':event_type' => 'sent',
                ':created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Silently continue — tracking failure should not block sending.
        }
    }

    // =========================================================================
    // Tracking
    // =========================================================================

    /**
     * Record an open event for a campaign/subscriber pair.
     */
    public function trackOpen(int $campaignId, int $subscriberId): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO email_tracking (campaign_id, subscriber_id, event_type, created_at)
                 VALUES (:campaign_id, :subscriber_id, :event_type, :created_at)'
            );
            $stmt->execute([
                ':campaign_id' => $campaignId,
                ':subscriber_id' => $subscriberId,
                ':event_type' => 'open',
                ':created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Tracking must not throw — fail silently.
        }
    }

    /**
     * Record a click event for a campaign/subscriber pair.
     */
    public function trackClick(int $campaignId, int $subscriberId, string $url): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO email_tracking (campaign_id, subscriber_id, event_type, url, created_at)
                 VALUES (:campaign_id, :subscriber_id, :event_type, :url, :created_at)'
            );
            $stmt->execute([
                ':campaign_id' => $campaignId,
                ':subscriber_id' => $subscriberId,
                ':event_type' => 'click',
                ':url' => $url,
                ':created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Tracking must not throw — fail silently.
        }
    }

    /**
     * Generate an HTML <img> tracking pixel tag.
     */
    public function generateTrackingPixel(int $campaignId, int $subscriberId): string
    {
        $url = "{$this->baseUrl}/track/open?cid={$campaignId}&sid={$subscriberId}";
        $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        return '<img src="' . $url . '" width="1" height="1" alt="" style="display:none" />';
    }

    /**
     * Generate a redirect URL for click tracking.
     */
    public function generateTrackingUrl(int $campaignId, int $subscriberId, string $originalUrl): string
    {
        $encoded = urlencode($originalUrl);
        return "{$this->baseUrl}/track/click?cid={$campaignId}&sid={$subscriberId}&url={$encoded}";
    }

    // =========================================================================
    // Subscriber Management
    // =========================================================================

    /**
     * Unsubscribe a subscriber by email and list ID.
     */
    public function unsubscribe(string $email, int $listId): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE subscribers
                 SET status = 'unsubscribed', unsubscribed_at = :unsubscribed_at
                 WHERE email = :email AND list_id = :list_id"
            );
            $stmt->execute([
                ':unsubscribed_at' => date('Y-m-d H:i:s'),
                ':email' => $email,
                ':list_id' => $listId,
            ]);

            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Generate a signed unsubscribe URL using HMAC-SHA256.
     * The signing key is derived from the smtp_pass config value.
     */
    public function getUnsubscribeUrl(int $subscriberId, int $listId): string
    {
        $payload = "{$subscriberId}-{$listId}";
        $signature = hash_hmac('sha256', $payload, $this->smtpPass);

        return "{$this->baseUrl}/unsubscribe?sid={$subscriberId}&lid={$listId}&sig={$signature}";
    }

    // =========================================================================
    // Stats
    // =========================================================================

    /**
     * Retrieve aggregated statistics for a campaign.
     *
     * @return array{total_sent: int, opens: int, unique_opens: int, clicks: int, unique_clicks: int, open_rate: float, click_rate: float}
     */
    public function getCampaignStats(int $campaignId): array
    {
        $stats = [
            'total_sent' => 0,
            'opens' => 0,
            'unique_opens' => 0,
            'clicks' => 0,
            'unique_clicks' => 0,
            'open_rate' => 0.0,
            'click_rate' => 0.0,
        ];

        try {
            // Total sent
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM email_tracking
                 WHERE campaign_id = :cid AND event_type = 'sent'"
            );
            $stmt->execute([':cid' => $campaignId]);
            $stats['total_sent'] = (int) $stmt->fetchColumn();

            // Total opens
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM email_tracking
                 WHERE campaign_id = :cid AND event_type = 'open'"
            );
            $stmt->execute([':cid' => $campaignId]);
            $stats['opens'] = (int) $stmt->fetchColumn();

            // Unique opens
            $stmt = $this->db->prepare(
                "SELECT COUNT(DISTINCT subscriber_id) FROM email_tracking
                 WHERE campaign_id = :cid AND event_type = 'open'"
            );
            $stmt->execute([':cid' => $campaignId]);
            $stats['unique_opens'] = (int) $stmt->fetchColumn();

            // Total clicks
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM email_tracking
                 WHERE campaign_id = :cid AND event_type = 'click'"
            );
            $stmt->execute([':cid' => $campaignId]);
            $stats['clicks'] = (int) $stmt->fetchColumn();

            // Unique clicks
            $stmt = $this->db->prepare(
                "SELECT COUNT(DISTINCT subscriber_id) FROM email_tracking
                 WHERE campaign_id = :cid AND event_type = 'click'"
            );
            $stmt->execute([':cid' => $campaignId]);
            $stats['unique_clicks'] = (int) $stmt->fetchColumn();

            // Rates
            if ($stats['total_sent'] > 0) {
                $stats['open_rate'] = round($stats['unique_opens'] / $stats['total_sent'] * 100, 2);
                $stats['click_rate'] = round($stats['unique_clicks'] / $stats['total_sent'] * 100, 2);
            }
        } catch (\Throwable $e) {
            // Return whatever we managed to collect.
        }

        return $stats;
    }
}
