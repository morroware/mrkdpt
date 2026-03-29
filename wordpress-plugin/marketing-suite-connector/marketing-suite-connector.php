<?php
/**
 * Plugin Name: Marketing Suite Connector
 * Plugin URI:  https://github.com/morroware/marketing
 * Description: Connect your WordPress site to the Marketing Suite platform. Bidirectional content sync, AI writing, taxonomy mapping, webhook notifications, and campaign management directly from WordPress.
 * Version:     2.0.0
 * Author:      Morroware
 * Author URI:  https://github.com/morroware
 * License:     GPL-2.0-or-later
 * Text Domain: msc
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

defined('ABSPATH') || exit;

define('MSC_VERSION', '2.0.0');
define('MSC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MSC_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once MSC_PLUGIN_DIR . 'includes/class-msc-api-client.php';
require_once MSC_PLUGIN_DIR . 'includes/class-msc-settings.php';
require_once MSC_PLUGIN_DIR . 'includes/class-msc-dashboard-widget.php';
require_once MSC_PLUGIN_DIR . 'includes/class-msc-post-metabox.php';
require_once MSC_PLUGIN_DIR . 'includes/class-msc-content-sync.php';
require_once MSC_PLUGIN_DIR . 'includes/class-msc-taxonomy-sync.php';
require_once MSC_PLUGIN_DIR . 'includes/class-msc-webhook.php';

final class Marketing_Suite_Connector {

    private static ?self $instance = null;

    private MSC_API_Client $api;
    private MSC_Settings $settings;
    private MSC_Dashboard_Widget $dashboard;
    private MSC_Post_Metabox $metabox;
    private MSC_Content_Sync $sync;
    private MSC_Taxonomy_Sync $taxonomy;
    private MSC_Webhook $webhook;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->api       = new MSC_API_Client();
        $this->settings  = new MSC_Settings($this->api);
        $this->dashboard = new MSC_Dashboard_Widget($this->api);
        $this->metabox   = new MSC_Post_Metabox($this->api);
        $this->sync      = new MSC_Content_Sync($this->api);
        $this->taxonomy  = new MSC_Taxonomy_Sync($this->api);
        $this->webhook   = new MSC_Webhook($this->api);

        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_menu', [$this, 'register_admin_menu']);

        // REST API routes for AJAX operations
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('wp_ajax_msc_create_draft', [$this, 'ajax_create_draft']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_plugin_action_links']);
    }

    public function enqueue_admin_assets(string $hook): void {
        $screens = [
            'toplevel_page_msc-dashboard',
            'marketing-suite_page_msc-content',
            'marketing-suite_page_msc-settings',
            'post.php',
            'post-new.php',
            'index.php', // WP dashboard
        ];

        if (!in_array($hook, $screens, true)) {
            return;
        }

        wp_enqueue_style(
            'msc-admin',
            MSC_PLUGIN_URL . 'assets/admin.css',
            [],
            MSC_VERSION
        );

        wp_enqueue_script(
            'msc-admin',
            MSC_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            MSC_VERSION,
            true
        );

        wp_localize_script('msc-admin', 'mscData', [
            'restUrl'  => rest_url('msc/v1/'),
            'nonce'    => wp_create_nonce('wp_rest'),
            'adminUrl' => admin_url(),
            'siteUrl'  => home_url(),
            'defaults' => [
                'importStatus'   => get_option('msc_default_status', 'draft'),
                'importPostType' => get_option('msc_default_post_type', 'post'),
                'syncCategories' => (bool) get_option('msc_sync_categories', true),
                'syncTags'       => (bool) get_option('msc_sync_tags', true),
                'aiEnabled'      => (bool) get_option('msc_ai_enabled', true),
            ],
        ]);
    }

    public function register_admin_menu(): void {
        add_menu_page(
            __('Marketing Suite', 'msc'),
            __('Marketing Suite', 'msc'),
            'edit_posts',
            'msc-dashboard',
            [$this, 'render_dashboard_page'],
            'dashicons-megaphone',
            30
        );

        add_submenu_page(
            'msc-dashboard',
            __('Dashboard', 'msc'),
            __('Dashboard', 'msc'),
            'edit_posts',
            'msc-dashboard',
            [$this, 'render_dashboard_page']
        );

        add_submenu_page(
            'msc-dashboard',
            __('Content Sync', 'msc'),
            __('Content Sync', 'msc'),
            'edit_posts',
            'msc-content',
            [$this, 'render_content_page']
        );

        add_submenu_page(
            'msc-dashboard',
            __('Settings', 'msc'),
            __('Settings', 'msc'),
            'manage_options',
            'msc-settings',
            [$this->settings, 'render_page']
        );
    }

    public function render_dashboard_page(): void {
        $this->dashboard->render_page();
    }

    public function render_content_page(): void {
        $this->sync->render_page();
    }

    public function register_rest_routes(): void {
        // Test connection
        register_rest_route('msc/v1', '/test-connection', [
            'methods'             => 'POST',
            'callback'            => fn($req) => $this->api->test_connection(),
            'permission_callback' => fn() => current_user_can('manage_options'),
        ]);

        // Pull content from Marketing Suite
        register_rest_route('msc/v1', '/pull-posts', [
            'methods'             => 'GET',
            'callback'            => [$this->sync, 'rest_pull_posts'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
            'args'                => [
                'status'   => ['sanitize_callback' => 'sanitize_text_field'],
                'platform' => ['sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        // Push WP post to Marketing Suite
        register_rest_route('msc/v1', '/push-post', [
            'methods'             => 'POST',
            'callback'            => [$this->sync, 'rest_push_post'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
            'args'                => [
                'post_id' => [
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => fn($value) => (int) $value > 0,
                ],
            ],
        ]);

        // Bulk push
        register_rest_route('msc/v1', '/bulk-push', [
            'methods'             => 'POST',
            'callback'            => [$this->sync, 'rest_bulk_push'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
            'args'                => [
                'post_ids' => [
                    'required' => true,
                    'type'     => 'array',
                ],
            ],
        ]);

        // Import a Marketing Suite post into WordPress
        register_rest_route('msc/v1', '/import-post', [
            'methods'             => 'POST',
            'callback'            => [$this->sync, 'rest_import_post'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
            'args'                => [
                'remote_id' => [
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => fn($value) => (int) $value > 0,
                ],
                'post_type' => [
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => 'post',
                ],
            ],
        ]);

        // Bulk import
        register_rest_route('msc/v1', '/bulk-import', [
            'methods'             => 'POST',
            'callback'            => [$this->sync, 'rest_bulk_import'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
            'args'                => [
                'remote_ids' => [
                    'required' => true,
                    'type'     => 'array',
                ],
                'post_type' => [
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => 'post',
                ],
            ],
        ]);

        // Fetch WordPress site content via Marketing Suite proxy
        register_rest_route('msc/v1', '/wp-content', [
            'methods'             => 'GET',
            'callback'            => [$this->sync, 'rest_fetch_wp_content'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
            'args'                => [
                'content_type' => ['sanitize_callback' => 'sanitize_text_field', 'default' => 'posts'],
                'per_page'     => ['sanitize_callback' => 'absint', 'default' => 20],
                'page'         => ['sanitize_callback' => 'absint', 'default' => 1],
                'status'       => ['sanitize_callback' => 'sanitize_text_field'],
                'search'       => ['sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        // Sync status
        register_rest_route('msc/v1', '/sync-status', [
            'methods'             => 'GET',
            'callback'            => [$this->sync, 'rest_sync_status'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
        ]);

        // Get dashboard analytics
        register_rest_route('msc/v1', '/analytics', [
            'methods'             => 'GET',
            'callback'            => [$this->dashboard, 'rest_analytics'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
        ]);

        // AI content generation
        register_rest_route('msc/v1', '/ai-generate', [
            'methods'             => 'POST',
            'callback'            => [$this->sync, 'rest_ai_generate'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
            'args'                => [
                'topic'        => ['required' => true, 'sanitize_callback' => 'sanitize_textarea_field'],
                'content_type' => ['sanitize_callback' => 'sanitize_text_field'],
                'tone'         => ['sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        // AI refinement
        register_rest_route('msc/v1', '/ai-refine', [
            'methods'             => 'POST',
            'callback'            => [$this->sync, 'rest_ai_refine'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
            'args'                => [
                'content' => ['required' => true, 'sanitize_callback' => 'sanitize_textarea_field'],
                'action'  => ['sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        // Shared AI memory pull
        register_rest_route('msc/v1', '/memory', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_pull_memory'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
        ]);

        // Shared AI memory push
        register_rest_route('msc/v1', '/memory', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_push_memory'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
        ]);

        // Taxonomy endpoints
        register_rest_route('msc/v1', '/categories', [
            'methods'             => 'GET',
            'callback'            => [$this->taxonomy, 'rest_get_categories'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
        ]);

        register_rest_route('msc/v1', '/tags', [
            'methods'             => 'GET',
            'callback'            => [$this->taxonomy, 'rest_get_tags'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
        ]);

        register_rest_route('msc/v1', '/push-taxonomies', [
            'methods'             => 'POST',
            'callback'            => [$this->taxonomy, 'rest_push_taxonomies'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
            'args'                => [
                'taxonomy' => ['sanitize_callback' => 'sanitize_text_field', 'default' => 'all'],
            ],
        ]);

        register_rest_route('msc/v1', '/taxonomy-map', [
            'methods'             => 'GET',
            'callback'            => [$this->taxonomy, 'rest_get_taxonomy_map'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
            'args'                => [
                'taxonomy' => ['sanitize_callback' => 'sanitize_text_field', 'default' => 'category'],
            ],
        ]);
    }

    public function rest_pull_memory(\WP_REST_Request $request): \WP_REST_Response {
        $limit = max(1, min(200, (int)$request->get_param('limit')));
        if ($limit <= 0) {
            $limit = 50;
        }
        $result = $this->api->get('/api/wordpress-plugin/memory', ['limit' => $limit]);
        if (isset($result['error'])) {
            return new \WP_REST_Response(['success' => false, 'message' => $result['error']], 502);
        }
        return new \WP_REST_Response(['success' => true, 'items' => $result['items'] ?? []], 200);
    }

    public function rest_push_memory(\WP_REST_Request $request): \WP_REST_Response {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            $payload = [];
        }
        $result = $this->api->post('/api/wordpress-plugin/memory', $payload);
        if (isset($result['error'])) {
            return new \WP_REST_Response(['success' => false, 'message' => $result['error']], 502);
        }
        return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
    }

    public function api(): MSC_API_Client {
        return $this->api;
    }

    public function ajax_create_draft(): void {
        check_ajax_referer('wp_rest');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('You are not allowed to create drafts.', 'msc')], 403);
        }

        $title     = sanitize_text_field((string) ($_POST['title'] ?? ''));
        $content   = wp_kses_post((string) ($_POST['content'] ?? ''));
        $post_type = sanitize_text_field((string) ($_POST['post_type'] ?? 'post'));
        $category  = absint($_POST['category'] ?? 0);

        if ($title === '' && $content === '') {
            wp_send_json_error(['message' => __('Title or content is required.', 'msc')], 400);
        }

        if (!in_array($post_type, ['post', 'page'], true)) {
            $post_type = 'post';
        }

        $post_data = [
            'post_title'   => $title !== '' ? $title : __('AI Generated Draft', 'msc'),
            'post_content' => $content,
            'post_status'  => 'draft',
            'post_type'    => $post_type,
        ];

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            wp_send_json_error(['message' => $post_id->get_error_message()], 500);
        }

        // Set category if provided
        if ($category > 0 && $post_type === 'post') {
            wp_set_post_categories($post_id, [$category]);
        }

        wp_send_json_success([
            'post_id'  => $post_id,
            'edit_url' => get_edit_post_link($post_id, 'raw'),
            'message'  => __('Draft created successfully.', 'msc'),
        ]);
    }

    public function add_plugin_action_links(array $links): array {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=msc-settings')),
            esc_html__('Settings', 'msc')
        );
        array_unshift($links, $settings_link);
        return $links;
    }
}

// Boot
add_action('plugins_loaded', function () {
    Marketing_Suite_Connector::instance();
});

// Activation hook - set defaults
register_activation_hook(__FILE__, function () {
    $defaults = [
        'msc_api_url'              => '',
        'msc_api_token'            => '',
        'msc_default_status'       => 'draft',
        'msc_default_post_type'    => 'post',
        'msc_auto_push'            => false,
        'msc_auto_push_types'      => 'post',
        'msc_sync_categories'      => true,
        'msc_sync_tags'            => true,
        'msc_sync_featured_images' => true,
        'msc_ai_enabled'           => true,
        'msc_webhooks_enabled'     => true,
    ];

    foreach ($defaults as $key => $value) {
        if (!get_option($key)) {
            add_option($key, $value);
        }
    }
});

// Deactivation hook
register_deactivation_hook(__FILE__, function () {
    delete_transient('msc_dashboard_metrics');
    delete_transient('msc_remote_posts');
});
