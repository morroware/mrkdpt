<?php
/**
 * Content synchronization between WordPress and Marketing Suite.
 *
 * Handles pulling content from the Marketing Suite into WordPress drafts,
 * pushing WordPress posts to the Marketing Suite, and AI content operations.
 */

defined('ABSPATH') || exit;

class MSC_Content_Sync {

    private MSC_API_Client $api;

    public function __construct(MSC_API_Client $api) {
        $this->api = $api;
    }

    // -------------------------------------------------------------------------
    //  Admin page
    // -------------------------------------------------------------------------

    public function render_page(): void {
        if (!$this->api->is_configured()) {
            printf(
                '<div class="wrap msc-wrap"><h1>%s</h1><div class="msc-notice"><p>%s</p><a href="%s" class="button button-primary">%s</a></div></div>',
                esc_html__('Content Sync', 'msc'),
                esc_html__('Please configure your Marketing Suite connection first.', 'msc'),
                esc_url(admin_url('admin.php?page=msc-settings')),
                esc_html__('Go to Settings', 'msc')
            );
            return;
        }
        ?>
        <div class="wrap msc-wrap">
            <h1><?php esc_html_e('Content Sync', 'msc'); ?></h1>

            <div class="msc-sync-layout">
                <!-- Pull from Marketing Suite -->
                <div class="msc-card">
                    <h2>
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e('Pull from Marketing Suite', 'msc'); ?>
                    </h2>
                    <p><?php esc_html_e('Browse content from your Marketing Suite and import it into WordPress as posts.', 'msc'); ?></p>

                    <div class="msc-pull-filters">
                        <select id="msc-pull-status">
                            <option value=""><?php esc_html_e('All Statuses', 'msc'); ?></option>
                            <option value="draft"><?php esc_html_e('Drafts', 'msc'); ?></option>
                            <option value="published"><?php esc_html_e('Published', 'msc'); ?></option>
                            <option value="scheduled"><?php esc_html_e('Scheduled', 'msc'); ?></option>
                        </select>
                        <select id="msc-pull-platform">
                            <option value=""><?php esc_html_e('All Platforms', 'msc'); ?></option>
                            <option value="wordpress"><?php esc_html_e('WordPress', 'msc'); ?></option>
                            <option value="blog"><?php esc_html_e('Blog', 'msc'); ?></option>
                        </select>
                        <button type="button" class="button button-primary" id="msc-fetch-remote">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e('Fetch Content', 'msc'); ?>
                        </button>
                    </div>

                    <div id="msc-remote-posts">
                        <p class="msc-hint"><?php esc_html_e('Click "Fetch Content" to load posts from Marketing Suite.', 'msc'); ?></p>
                    </div>
                </div>

                <!-- Push to Marketing Suite -->
                <div class="msc-card">
                    <h2>
                        <span class="dashicons dashicons-upload"></span>
                        <?php esc_html_e('Push to Marketing Suite', 'msc'); ?>
                    </h2>
                    <p><?php esc_html_e('Send WordPress posts to your Marketing Suite for multi-channel distribution.', 'msc'); ?></p>

                    <div id="msc-local-posts">
                        <?php $this->render_local_posts(); ?>
                    </div>
                </div>

                <!-- AI Content Generation -->
                <?php if (get_option('msc_ai_enabled', true)): ?>
                <div class="msc-card msc-card-ai">
                    <h2>
                        <span class="dashicons dashicons-admin-customizer"></span>
                        <?php esc_html_e('AI Content Generator', 'msc'); ?>
                    </h2>
                    <p><?php esc_html_e('Generate new content using Marketing Suite AI and save it as a WordPress draft.', 'msc'); ?></p>

                    <div class="msc-ai-form">
                        <label for="msc-ai-topic"><?php esc_html_e('Topic / Description', 'msc'); ?></label>
                        <textarea id="msc-ai-topic" rows="3" class="large-text"
                                  placeholder="<?php esc_attr_e('Describe what you want to write about...', 'msc'); ?>"></textarea>

                        <div class="msc-ai-options">
                            <label for="msc-ai-type"><?php esc_html_e('Content Type', 'msc'); ?></label>
                            <select id="msc-ai-type">
                                <option value="blog_post"><?php esc_html_e('Blog Post', 'msc'); ?></option>
                                <option value="article"><?php esc_html_e('Article', 'msc'); ?></option>
                                <option value="landing_page"><?php esc_html_e('Landing Page Copy', 'msc'); ?></option>
                                <option value="newsletter"><?php esc_html_e('Newsletter', 'msc'); ?></option>
                            </select>

                            <label for="msc-ai-tone"><?php esc_html_e('Tone', 'msc'); ?></label>
                            <select id="msc-ai-tone">
                                <option value="professional"><?php esc_html_e('Professional', 'msc'); ?></option>
                                <option value="casual"><?php esc_html_e('Casual', 'msc'); ?></option>
                                <option value="formal"><?php esc_html_e('Formal', 'msc'); ?></option>
                                <option value="persuasive"><?php esc_html_e('Persuasive', 'msc'); ?></option>
                            </select>
                        </div>

                        <button type="button" class="button button-primary" id="msc-ai-generate">
                            <span class="dashicons dashicons-admin-customizer"></span>
                            <?php esc_html_e('Generate Content', 'msc'); ?>
                        </button>
                    </div>

                    <div id="msc-ai-result" style="display:none;">
                        <h3><?php esc_html_e('Generated Content', 'msc'); ?></h3>
                        <div id="msc-ai-output" class="msc-ai-output"></div>
                        <div class="msc-ai-result-actions">
                            <button type="button" class="button button-primary" id="msc-ai-save-draft">
                                <?php esc_html_e('Save as WordPress Draft', 'msc'); ?>
                            </button>
                            <button type="button" class="button" id="msc-ai-copy">
                                <?php esc_html_e('Copy to Clipboard', 'msc'); ?>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_local_posts(): void {
        $posts = get_posts([
            'numberposts' => 20,
            'post_status' => ['publish', 'draft'],
            'orderby'     => 'modified',
            'order'       => 'DESC',
        ]);

        if (empty($posts)) {
            echo '<p>' . esc_html__('No posts found.', 'msc') . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Title', 'msc') . '</th>';
        echo '<th>' . esc_html__('Status', 'msc') . '</th>';
        echo '<th>' . esc_html__('Synced', 'msc') . '</th>';
        echo '<th>' . esc_html__('Actions', 'msc') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($posts as $p) {
            $synced_id = get_post_meta($p->ID, '_msc_remote_id', true);
            echo '<tr>';
            printf('<td><a href="%s">%s</a></td>',
                esc_url(get_edit_post_link($p->ID)),
                esc_html($p->post_title ?: '(no title)')
            );
            printf('<td><span class="msc-badge msc-badge-%s">%s</span></td>',
                esc_attr($p->post_status),
                esc_html($p->post_status)
            );
            printf('<td>%s</td>',
                $synced_id
                    ? '<span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span> ID: ' . esc_html($synced_id)
                    : '<span class="dashicons dashicons-minus" style="color:#999;"></span>'
            );
            printf(
                '<td><button type="button" class="button button-small msc-push-post" data-post-id="%d">%s</button></td>',
                (int) $p->ID,
                $synced_id ? esc_html__('Re-push', 'msc') : esc_html__('Push', 'msc')
            );
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // -------------------------------------------------------------------------
    //  REST callbacks
    // -------------------------------------------------------------------------

    /**
     * Pull available posts from the Marketing Suite.
     */
    public function rest_pull_posts(\WP_REST_Request $request): \WP_REST_Response {
        $params = [
            'status'   => $request->get_param('status') ?: '',
            'platform' => $request->get_param('platform') ?: '',
        ];

        $data = $this->api->get('/api/wordpress-plugin/posts', array_filter($params));

        if (isset($data['error'])) {
            return new \WP_REST_Response(['error' => $data['error']], 502);
        }

        return new \WP_REST_Response($data);
    }

    /**
     * Push a WordPress post to the Marketing Suite.
     */
    public function rest_push_post(\WP_REST_Request $request): \WP_REST_Response {
        $post_id = (int) $request->get_param('post_id');
        $post    = get_post($post_id);

        if (!$post || !current_user_can('edit_post', $post_id)) {
            return new \WP_REST_Response(['error' => 'Post not found or access denied.'], 404);
        }

        $payload = [
            'title'       => $post->post_title,
            'body'        => $post->post_content,
            'status'      => $post->post_status === 'publish' ? 'published' : 'draft',
            'platform'    => 'wordpress',
            'wp_post_id'  => $post_id,
            'wp_url'      => get_permalink($post_id),
            'categories'  => wp_get_post_categories($post_id, ['fields' => 'names']),
            'tags'        => wp_get_post_tags($post_id, ['fields' => 'names']),
        ];

        // Check if already synced — update instead of create
        $remote_id = get_post_meta($post_id, '_msc_remote_id', true);

        if ($remote_id) {
            $result = $this->api->put('/api/wordpress-plugin/posts/' . $remote_id, $payload);
        } else {
            $result = $this->api->post('/api/wordpress-plugin/posts', $payload);
        }

        if (isset($result['error'])) {
            return new \WP_REST_Response(['error' => $result['error']], 502);
        }

        // Store the remote ID
        $new_remote_id = $result['item']['id'] ?? $result['id'] ?? $remote_id;
        if ($new_remote_id) {
            update_post_meta($post_id, '_msc_remote_id', $new_remote_id);
            update_post_meta($post_id, '_msc_synced_at', current_time('mysql'));
        }

        return new \WP_REST_Response([
            'success'   => true,
            'remote_id' => $new_remote_id,
            'message'   => $remote_id ? 'Post updated in Marketing Suite.' : 'Post pushed to Marketing Suite.',
        ]);
    }

    /**
     * Import a Marketing Suite post as a WordPress draft.
     */
    public function rest_import_post(\WP_REST_Request $request): \WP_REST_Response {
        $remote_id = (int) $request->get_param('remote_id');

        if (!$remote_id) {
            return new \WP_REST_Response(['error' => 'Missing remote_id.'], 400);
        }

        // Fetch the full post from Marketing Suite
        $data = $this->api->get('/api/wordpress-plugin/posts/' . $remote_id);

        if (isset($data['error'])) {
            return new \WP_REST_Response(['error' => $data['error']], 502);
        }

        $item = $data['item'] ?? $data;
        $default_status = get_option('msc_default_status', 'draft');

        $wp_post_id = wp_insert_post([
            'post_title'   => sanitize_text_field($item['title'] ?? 'Imported Post'),
            'post_content' => wp_kses_post($item['body'] ?? $item['content'] ?? ''),
            'post_status'  => $default_status,
            'post_type'    => 'post',
        ], true);

        if (is_wp_error($wp_post_id)) {
            return new \WP_REST_Response(['error' => $wp_post_id->get_error_message()], 500);
        }

        // Link the WP post to the remote post
        update_post_meta($wp_post_id, '_msc_remote_id', $remote_id);
        update_post_meta($wp_post_id, '_msc_synced_at', current_time('mysql'));

        // Sync categories if enabled
        if (get_option('msc_sync_categories', true) && !empty($item['platform'])) {
            $cat_id = wp_create_category($item['platform']);
            if ($cat_id && !is_wp_error($cat_id)) {
                wp_set_post_categories($wp_post_id, [$cat_id], true);
            }
        }

        return new \WP_REST_Response([
            'success'    => true,
            'wp_post_id' => $wp_post_id,
            'edit_url'   => get_edit_post_link($wp_post_id, 'raw'),
            'message'    => 'Post imported as ' . $default_status . '.',
        ]);
    }

    /**
     * Generate AI content via the Marketing Suite.
     */
    public function rest_ai_generate(\WP_REST_Request $request): \WP_REST_Response {
        if (!get_option('msc_ai_enabled', true)) {
            return new \WP_REST_Response(['error' => 'AI features are disabled.'], 403);
        }

        $payload = [
            'topic'        => sanitize_textarea_field($request->get_param('topic') ?: ''),
            'content_type' => sanitize_text_field($request->get_param('content_type') ?: 'blog_post'),
            'tone'         => sanitize_text_field($request->get_param('tone') ?: 'professional'),
            'platform'     => 'wordpress',
        ];

        if (empty($payload['topic'])) {
            return new \WP_REST_Response(['error' => 'Topic is required.'], 400);
        }

        $result = $this->api->post('/api/ai/content', $payload);

        if (isset($result['error'])) {
            return new \WP_REST_Response(['error' => $result['error']], 502);
        }

        return new \WP_REST_Response($result);
    }

    /**
     * Refine content via the Marketing Suite AI.
     */
    public function rest_ai_refine(\WP_REST_Request $request): \WP_REST_Response {
        if (!get_option('msc_ai_enabled', true)) {
            return new \WP_REST_Response(['error' => 'AI features are disabled.'], 403);
        }

        $payload = [
            'content' => sanitize_textarea_field($request->get_param('content') ?: ''),
            'action'  => sanitize_text_field($request->get_param('action') ?: 'improve'),
        ];

        if (empty($payload['content'])) {
            return new \WP_REST_Response(['error' => 'Content is required.'], 400);
        }

        $result = $this->api->post('/api/ai/refine', $payload);

        if (isset($result['error'])) {
            return new \WP_REST_Response(['error' => $result['error']], 502);
        }

        return new \WP_REST_Response($result);
    }
}
