<?php
/**
 * API Client for communicating with the Marketing Suite backend.
 *
 * Uses Bearer token authentication against the Marketing Suite REST API.
 */

defined('ABSPATH') || exit;

class MSC_API_Client {

    /**
     * Make a GET request to the Marketing Suite API.
     */
    public function get(string $endpoint, array $query = []): array {
        return $this->request('GET', $endpoint, $query);
    }

    /**
     * Make a POST request to the Marketing Suite API.
     */
    public function post(string $endpoint, array $body = []): array {
        return $this->request('POST', $endpoint, $body);
    }

    /**
     * Make a PUT request to the Marketing Suite API.
     */
    public function put(string $endpoint, array $body = []): array {
        return $this->request('PUT', $endpoint, $body);
    }

    /**
     * Make a DELETE request to the Marketing Suite API.
     */
    public function delete(string $endpoint): array {
        return $this->request('DELETE', $endpoint);
    }

    /**
     * Test the connection to the Marketing Suite.
     */
    public function test_connection(): \WP_REST_Response {
        $base_url = $this->get_base_url();
        $token    = $this->get_token();

        if (empty($base_url) || empty($token)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'API URL and token are required. Configure them in Settings.',
            ], 400);
        }

        $result = $this->get('/api/wordpress-plugin/status');

        if (isset($result['error'])) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $result['error'],
            ], 502);
        }

        return new \WP_REST_Response([
            'success' => true,
            'message' => 'Connected successfully.',
            'data'    => $result,
        ]);
    }

    /**
     * Check whether the plugin is configured with URL and token.
     */
    public function is_configured(): bool {
        return !empty($this->get_base_url()) && !empty($this->get_token());
    }

    public function get_base_url(): string {
        return rtrim((string) get_option('msc_api_url', ''), '/');
    }

    public function get_token(): string {
        return (string) get_option('msc_api_token', '');
    }

    // -------------------------------------------------------------------------
    //  Internal
    // -------------------------------------------------------------------------

    private function request(string $method, string $endpoint, array $data = []): array {
        $base_url = $this->get_base_url();
        $token    = $this->get_token();

        if (empty($base_url) || empty($token)) {
            return ['error' => 'Marketing Suite connection not configured.'];
        }

        $url = $base_url . $endpoint;

        $args = [
            'method'  => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ];

        if ($method === 'GET' && !empty($data)) {
            $url = add_query_arg($data, $url);
        } elseif ($method !== 'GET' && !empty($data)) {
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return ['error' => "Invalid response (HTTP {$code}): " . substr($body, 0, 200)];
        }

        if ($code >= 400) {
            return ['error' => $decoded['error'] ?? "HTTP {$code}: " . substr($body, 0, 200)];
        }

        return $decoded;
    }
}
