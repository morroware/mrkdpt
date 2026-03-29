<?php
/**
 * Post editor metabox for syncing individual posts with Marketing Suite.
 */

defined('ABSPATH') || exit;

class MSC_Post_Metabox {

    private MSC_API_Client $api;

    public function __construct(MSC_API_Client $api) {
        $this->api = $api;
        add_action('add_meta_boxes', [$this, 'register_metabox']);
        add_action('save_post', [$this, 'save_meta'], 10, 2);
    }

    public function register_metabox(): void {
        if (!$this->api->is_configured()) {
            return;
        }

        $post_types = ['post', 'page'];
        foreach ($post_types as $pt) {
            add_meta_box(
                'msc_post_sync',
                __('Marketing Suite', 'msc'),
                [$this, 'render_metabox'],
                $pt,
                'side',
                'default'
            );
        }
    }

    public function render_metabox(\WP_Post $post): void {
        $synced_id  = get_post_meta($post->ID, '_msc_remote_id', true);
        $synced_at  = get_post_meta($post->ID, '_msc_synced_at', true);
        $ai_enabled = get_option('msc_ai_enabled', true);

        wp_nonce_field('msc_post_sync', 'msc_post_sync_nonce');
        ?>
        <div class="msc-metabox">
            <?php if ($synced_id): ?>
                <p class="msc-synced-info">
                    <span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span>
                    <?php printf(
                        esc_html__('Synced (ID: %s)', 'msc'),
                        esc_html($synced_id)
                    ); ?>
                    <?php if ($synced_at): ?>
                        <br><small><?php echo esc_html($synced_at); ?></small>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <div class="msc-metabox-actions">
                <button type="button" class="button msc-push-post" data-post-id="<?php echo (int) $post->ID; ?>">
                    <span class="dashicons dashicons-upload"></span>
                    <?php echo $synced_id
                        ? esc_html__('Update in Marketing Suite', 'msc')
                        : esc_html__('Push to Marketing Suite', 'msc'); ?>
                </button>

                <?php if ($ai_enabled): ?>
                <hr />
                <p><strong><?php esc_html_e('AI Tools', 'msc'); ?></strong></p>

                <button type="button" class="button msc-ai-action" data-action="improve"
                        data-post-id="<?php echo (int) $post->ID; ?>">
                    <span class="dashicons dashicons-edit"></span>
                    <?php esc_html_e('Improve', 'msc'); ?>
                </button>

                <button type="button" class="button msc-ai-action" data-action="seo"
                        data-post-id="<?php echo (int) $post->ID; ?>">
                    <span class="dashicons dashicons-search"></span>
                    <?php esc_html_e('SEO Optimize', 'msc'); ?>
                </button>

                <button type="button" class="button msc-ai-action" data-action="headlines"
                        data-post-id="<?php echo (int) $post->ID; ?>">
                    <span class="dashicons dashicons-lightbulb"></span>
                    <?php esc_html_e('Headlines', 'msc'); ?>
                </button>

                <button type="button" class="button msc-ai-action" data-action="score"
                        data-post-id="<?php echo (int) $post->ID; ?>">
                    <span class="dashicons dashicons-chart-bar"></span>
                    <?php esc_html_e('Score', 'msc'); ?>
                </button>
                <?php endif; ?>
            </div>

            <div class="msc-metabox-result" id="msc-metabox-result" style="display:none;"></div>
        </div>
        <?php
    }

    public function save_meta(int $post_id, \WP_Post $post): void {
        if (!isset($_POST['msc_post_sync_nonce'])) {
            return;
        }
        if (!wp_verify_nonce($_POST['msc_post_sync_nonce'], 'msc_post_sync')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Meta is saved via REST API push actions, not via form submit.
        // This hook is here for future extensibility.
    }
}
