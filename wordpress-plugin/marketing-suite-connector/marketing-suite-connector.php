<?php
/**
 * Plugin Name: Marketing Suite Connector
 * Plugin URI:  https://github.com/morroware/marketing
 * Description: Connect your WordPress site to the Marketing Suite platform. Pull content, push posts, view analytics, and manage campaigns directly from WordPress.
 * Version:     1.0.0
 * Author:      Morroware
 * Author URI:  https://github.com/morroware
 * License:     GPL-2.0-or-later
 * Text Domain: msc
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

defined('ABSPATH') || exit;

define('MSC_VERSION', '1.0.0');
define('MSC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MSC_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once MSC_PLUGIN_DIR . 'includes/class-msc-api-client.php';
require_once MSC_PLUGIN_DIR . 'includes/class-msc-settings.php';
require_once MSC_PLUGIN_DIR . 'includes/class-msc-dashboard-widget.php';
require_once MSC_PLUGIN_DIR . 'includes/class-msc-post-metabox.php';
require_once MSC_PLUGIN_DIR . 'includes/class-msc-content-sync.php';

final class Marketing_Suite_Connector {

    private static ?self $instance = null;

    private MSC_API_Client $api;
    private MSC_Settings $settings;
    private MSC_Dashboard_Widget $dashboard;
    private MSC_Post_Metabox $metabox;
    private MSC_Content_Sync $sync;

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

        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_menu', [$this, 'register_admin_menu']);

        // REST API routes for AJAX operations
        add_action('rest_api_init', [$this, 'register_rest_routes']);
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
        ]);

        // Push WP post to Marketing Suite
        register_rest_route('msc/v1', '/push-post', [
            'methods'             => 'POST',
            'callback'            => [$this->sync, 'rest_push_post'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
        ]);

        // Import a Marketing Suite post into WordPress
        register_rest_route('msc/v1', '/import-post', [
            'methods'             => 'POST',
            'callback'            => [$this->sync, 'rest_import_post'],
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
        ]);

        // AI refinement
        register_rest_route('msc/v1', '/ai-refine', [
            'methods'             => 'POST',
            'callback'            => [$this->sync, 'rest_ai_refine'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
        ]);
    }

    public function api(): MSC_API_Client {
        return $this->api;
    }
}

// Boot
add_action('plugins_loaded', function () {
    Marketing_Suite_Connector::instance();
});

// Activation hook - set defaults
register_activation_hook(__FILE__, function () {
    if (!get_option('msc_api_url')) {
        add_option('msc_api_url', '');
    }
    if (!get_option('msc_api_token')) {
        add_option('msc_api_token', '');
    }
    if (!get_option('msc_default_status')) {
        add_option('msc_default_status', 'draft');
    }
});

// Deactivation hook
register_deactivation_hook(__FILE__, function () {
    delete_transient('msc_dashboard_metrics');
    delete_transient('msc_remote_posts');
});
