<?php
/**
 * Dashboard widget and dashboard page for Marketing Suite metrics.
 */

defined('ABSPATH') || exit;

class MSC_Dashboard_Widget {

    private MSC_API_Client $api;

    public function __construct(MSC_API_Client $api) {
        $this->api = $api;
        add_action('wp_dashboard_setup', [$this, 'register_widget']);
    }

    public function register_widget(): void {
        if (!$this->api->is_configured()) {
            return;
        }

        wp_add_dashboard_widget(
            'msc_dashboard_widget',
            __('Marketing Suite', 'msc'),
            [$this, 'render_widget']
        );
    }

    public function render_widget(): void {
        $metrics = get_transient('msc_dashboard_metrics');
        if ($metrics === false) {
            $metrics = $this->api->get('/api/wordpress-plugin/dashboard');
            if (!isset($metrics['error'])) {
                set_transient('msc_dashboard_metrics', $metrics, 300); // 5 min cache
            }
        }

        if (isset($metrics['error'])) {
            printf(
                '<p class="msc-error">%s</p><p><a href="%s">%s</a></p>',
                esc_html($metrics['error']),
                esc_url(admin_url('admin.php?page=msc-settings')),
                esc_html__('Check Settings', 'msc')
            );
            return;
        }
        ?>
        <div class="msc-widget-grid">
            <?php if (!empty($metrics['total_posts'])): ?>
            <div class="msc-widget-stat">
                <span class="msc-stat-value"><?php echo esc_html($metrics['total_posts']); ?></span>
                <span class="msc-stat-label"><?php esc_html_e('Total Posts', 'msc'); ?></span>
            </div>
            <?php endif; ?>

            <?php if (!empty($metrics['published_posts'])): ?>
            <div class="msc-widget-stat">
                <span class="msc-stat-value"><?php echo esc_html($metrics['published_posts']); ?></span>
                <span class="msc-stat-label"><?php esc_html_e('Published', 'msc'); ?></span>
            </div>
            <?php endif; ?>

            <?php if (!empty($metrics['scheduled_posts'])): ?>
            <div class="msc-widget-stat">
                <span class="msc-stat-value"><?php echo esc_html($metrics['scheduled_posts']); ?></span>
                <span class="msc-stat-label"><?php esc_html_e('Scheduled', 'msc'); ?></span>
            </div>
            <?php endif; ?>

            <?php if (!empty($metrics['campaigns'])): ?>
            <div class="msc-widget-stat">
                <span class="msc-stat-value"><?php echo esc_html($metrics['campaigns']); ?></span>
                <span class="msc-stat-label"><?php esc_html_e('Campaigns', 'msc'); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($metrics['recent_posts'])): ?>
        <h4><?php esc_html_e('Recent Content', 'msc'); ?></h4>
        <ul class="msc-recent-list">
            <?php foreach (array_slice($metrics['recent_posts'], 0, 5) as $post): ?>
            <li>
                <strong><?php echo esc_html($post['title'] ?? 'Untitled'); ?></strong>
                <span class="msc-badge msc-badge-<?php echo esc_attr($post['status'] ?? 'draft'); ?>">
                    <?php echo esc_html($post['status'] ?? 'draft'); ?>
                </span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <p class="msc-widget-footer">
            <a href="<?php echo esc_url(admin_url('admin.php?page=msc-dashboard')); ?>">
                <?php esc_html_e('View Full Dashboard', 'msc'); ?> &rarr;
            </a>
        </p>
        <?php
    }

    /**
     * Render the full Marketing Suite dashboard page.
     */
    public function render_page(): void {
        if (!$this->api->is_configured()) {
            printf(
                '<div class="wrap msc-wrap"><h1>%s</h1><div class="msc-notice"><p>%s</p><a href="%s" class="button button-primary">%s</a></div></div>',
                esc_html__('Marketing Suite Dashboard', 'msc'),
                esc_html__('Please configure your Marketing Suite connection first.', 'msc'),
                esc_url(admin_url('admin.php?page=msc-settings')),
                esc_html__('Go to Settings', 'msc')
            );
            return;
        }
        ?>
        <div class="wrap msc-wrap">
            <h1><?php esc_html_e('Marketing Suite Dashboard', 'msc'); ?></h1>

            <div class="msc-dashboard-grid" id="msc-dashboard">
                <div class="msc-card msc-card-metrics">
                    <h2><?php esc_html_e('Content Metrics', 'msc'); ?></h2>
                    <div class="msc-metrics-grid" id="msc-metrics-grid">
                        <p class="msc-loading"><?php esc_html_e('Loading metrics...', 'msc'); ?></p>
                    </div>
                </div>

                <div class="msc-card msc-card-recent">
                    <h2><?php esc_html_e('Recent Content', 'msc'); ?></h2>
                    <div id="msc-recent-content">
                        <p class="msc-loading"><?php esc_html_e('Loading...', 'msc'); ?></p>
                    </div>
                </div>

                <div class="msc-card msc-card-campaigns">
                    <h2><?php esc_html_e('Active Campaigns', 'msc'); ?></h2>
                    <div id="msc-campaigns">
                        <p class="msc-loading"><?php esc_html_e('Loading...', 'msc'); ?></p>
                    </div>
                </div>

                <div class="msc-card msc-card-quick-actions">
                    <h2><?php esc_html_e('Quick Actions', 'msc'); ?></h2>
                    <div class="msc-quick-actions">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=msc-content')); ?>" class="button">
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e('Pull Content', 'msc'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('post-new.php')); ?>" class="button">
                            <span class="dashicons dashicons-upload"></span>
                            <?php esc_html_e('New Post → Push', 'msc'); ?>
                        </a>
                        <button type="button" class="button msc-refresh-dashboard">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e('Refresh Data', 'msc'); ?>
                        </button>
                        <?php if ($this->api->get_base_url()): ?>
                        <a href="<?php echo esc_url($this->api->get_base_url()); ?>" target="_blank" class="button">
                            <span class="dashicons dashicons-external"></span>
                            <?php esc_html_e('Open Marketing Suite', 'msc'); ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * REST endpoint for fetching analytics data.
     */
    public function rest_analytics(\WP_REST_Request $request): \WP_REST_Response {
        delete_transient('msc_dashboard_metrics');

        $data = $this->api->get('/api/wordpress-plugin/dashboard');

        if (isset($data['error'])) {
            return new \WP_REST_Response(['error' => $data['error']], 502);
        }

        set_transient('msc_dashboard_metrics', $data, 300);

        return new \WP_REST_Response($data);
    }
}
