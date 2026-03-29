<?php

declare(strict_types=1);

final class SmsService
{
    private string $accountSid;
    private string $authToken;
    private string $fromNumber;

    public function __construct(array $config)
    {
        $this->accountSid = trim((string)($config['twilio_account_sid'] ?? ''));
        $this->authToken = trim((string)($config['twilio_auth_token'] ?? ''));
        $this->fromNumber = trim((string)($config['twilio_from_number'] ?? ''));
    }

    public function isConfigured(): bool
    {
        return $this->accountSid !== '' && $this->authToken !== '' && $this->fromNumber !== '';
    }

    public function sendMessage(string $to, string $body): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $to = trim($to);
        $body = trim($body);
        if ($to === '' || $body === '') {
            return false;
        }

        // Twilio expects E.164 phone numbers.
        if (!preg_match('/^\+[1-9]\d{7,14}$/', $to) || !preg_match('/^\+[1-9]\d{7,14}$/', $this->fromNumber)) {
            return false;
        }

        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($this->accountSid) . '/Messages.json';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->accountSid . ':' . $this->authToken,
            CURLOPT_POSTFIELDS => http_build_query([
                'From' => $this->fromNumber,
                'To' => $to,
                'Body' => $body,
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);
        $response = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($code < 200 || $code >= 300) {
            $detail = $curlError ?: ($response ?: 'Unknown error');
            error_log("SmsService: Twilio API error (HTTP {$code}): {$detail}");
        }

        return $code >= 200 && $code < 300;
    }
}
