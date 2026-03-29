<?php
/**
 * Content synchronization between WordPress and Marketing Suite.
 *
 * Handles pulling content from the Marketing Suite into WordPress drafts,
 * pushing WordPress posts/pages to the Marketing Suite, bulk operations,
 * and AI content operations.
 */

defined('ABSPATH') || exit;

class MSC_Content_Sync {

    private MSC_API_Client $api;

    public function __construct(MSC_API_Client $api) {
        $this->api = $api;
        add_action('save_post_post', [$this, 'maybe_auto_push_post'], 20, 3);
        add_action('save_post_page', [$this, 'maybe_auto_push_post'], 20, 3);
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

            <!-- Sync Status Bar -->
            <div class="msc-sync-status-bar" id="msc-sync-status">
                <div class="msc-sync-stats">
                    <span class="dashicons dashicons-update"></span>
                    <span id="msc-sync-count">Loading sync status...</span>
                </div>
                <div class="msc-sync-actions-bar">
                    <button type="button" class="button button-small" id="msc-refresh-sync-status">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Refresh', 'msc'); ?>
                    </button>
                </div>
            </div>

            <!-- Tab navigation -->
            <nav class="nav-tab-wrapper msc-tabs">
                <a href="#msc-tab-pull" class="nav-tab nav-tab-active" data-tab="pull">
                    <span class="dashicons dashicons-download"></span>
                    <?php esc_html_e('Pull from Marketing Suite', 'msc'); ?>
                </a>
                <a href="#msc-tab-push" class="nav-tab" data-tab="push">
                    <span class="dashicons dashicons-upload"></span>
                    <?php esc_html_e('Push to Marketing Suite', 'msc'); ?>
                </a>
                <a href="#msc-tab-wp" class="nav-tab" data-tab="wp">
                    <span class="dashicons dashicons-wordpress"></span>
                    <?php esc_html_e('WordPress Site Content', 'msc'); ?>
                </a>
                <a href="#msc-tab-ai" class="nav-tab" data-tab="ai">
                    <span class="dashicons dashicons-admin-customizer"></span>
                    <?php esc_html_e('AI Content', 'msc'); ?>
                </a>
                <a href="#msc-tab-taxonomy" class="nav-tab" data-tab="taxonomy">
                    <span class="dashicons dashicons-tag"></span>
                    <?php esc_html_e('Taxonomy Sync', 'msc'); ?>
                </a>
            </nav>

            <!-- Pull Tab -->
            <div class="msc-tab-panel msc-tab-active" id="msc-tab-pull">
                <div class="msc-card">
                    <h2>
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e('Pull from Marketing Suite', 'msc'); ?>
                    </h2>
                    <p><?php esc_html_e('Browse content from your Marketing Suite and import it into WordPress as posts or pages.', 'msc'); ?></p>

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
                        <select id="msc-pull-import-as">
                            <option value="post"><?php esc_html_e('Import as Post', 'msc'); ?></option>
                            <option value="page"><?php esc_html_e('Import as Page', 'msc'); ?></option>
                        </select>
                        <button type="button" class="button button-primary" id="msc-fetch-remote">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e('Fetch Content', 'msc'); ?>
                        </button>
                    </div>

                    <div class="msc-bulk-actions" id="msc-pull-bulk" style="display:none;">
                        <label><input type="checkbox" id="msc-pull-select-all" /> <?php esc_html_e('Select All', 'msc'); ?></label>
                        <button type="button" class="button button-primary" id="msc-bulk-import">
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e('Import Selected', 'msc'); ?>
                        </button>
                        <span class="msc-selected-count" id="msc-pull-selected-count">0 selected</span>
                    </div>

                    <div id="msc-remote-posts">
                        <p class="msc-hint"><?php esc_html_e('Click "Fetch Content" to load posts from Marketing Suite.', 'msc'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Push Tab -->
            <div class="msc-tab-panel" id="msc-tab-push" style="display:none;">
                <div class="msc-card">
                    <h2>
                        <span class="dashicons dashicons-upload"></span>
                        <?php esc_html_e('Push to Marketing Suite', 'msc'); ?>
                    </h2>
                    <p><?php esc_html_e('Send WordPress posts and pages to your Marketing Suite for multi-channel distribution.', 'msc'); ?></p>

                    <div class="msc-push-filters">
                        <select id="msc-push-type">
                            <option value="post"><?php esc_html_e('Posts', 'msc'); ?></option>
                            <option value="page"><?php esc_html_e('Pages', 'msc'); ?></option>
                        </select>
                        <select id="msc-push-status">
                            <option value=""><?php esc_html_e('All Statuses', 'msc'); ?></option>
                            <option value="publish"><?php esc_html_e('Published', 'msc'); ?></option>
                            <option value="draft"><?php esc_html_e('Drafts', 'msc'); ?></option>
                        </select>
                        <button type="button" class="button" id="msc-refresh-local">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e('Refresh', 'msc'); ?>
                        </button>
                    </div>

                    <div class="msc-bulk-actions" id="msc-push-bulk">
                        <label><input type="checkbox" id="msc-push-select-all" /> <?php esc_html_e('Select All', 'msc'); ?></label>
                        <button type="button" class="button button-primary" id="msc-bulk-push">
                            <span class="dashicons dashicons-upload"></span>
                            <?php esc_html_e('Push Selected', 'msc'); ?>
                        </button>
                        <span class="msc-selected-count" id="msc-push-selected-count">0 selected</span>
                    </div>

                    <div id="msc-local-posts">
                        <?php $this->render_local_posts(); ?>
                    </div>
                </div>
            </div>

            <!-- WP Site Content Tab (view remote WordPress content from Marketing Suite) -->
            <div class="msc-tab-panel" id="msc-tab-wp" style="display:none;">
                <div class="msc-card">
                    <h2>
                        <span class="dashicons dashicons-wordpress"></span>
                        <?php esc_html_e('WordPress Site Content (via Marketing Suite)', 'msc'); ?>
                    </h2>
                    <p><?php esc_html_e('View and manage your WordPress site content through the Marketing Suite connection. This shows posts on the WordPress site connected to Marketing Suite.', 'msc'); ?></p>

                    <div class="msc-wp-filters">
                        <select id="msc-wp-content-type">
                            <option value="posts"><?php esc_html_e('Posts', 'msc'); ?></option>
                            <option value="pages"><?php esc_html_e('Pages', 'msc'); ?></option>
                        </select>
                        <select id="msc-wp-status">
                            <option value="any"><?php esc_html_e('All Statuses', 'msc'); ?></option>
                            <option value="publish"><?php esc_html_e('Published', 'msc'); ?></option>
                            <option value="draft"><?php esc_html_e('Draft', 'msc'); ?></option>
                        </select>
                        <input type="text" id="msc-wp-search" placeholder="<?php esc_attr_e('Search...', 'msc'); ?>" class="regular-text" />
                        <button type="button" class="button button-primary" id="msc-fetch-wp-content">
                            <span class="dashicons dashicons-search"></span>
                            <?php esc_html_e('Fetch', 'msc'); ?>
                        </button>
                    </div>

                    <div id="msc-wp-content">
                        <p class="msc-hint"><?php esc_html_e('Click "Fetch" to load content from the connected WordPress site.', 'msc'); ?></p>
                    </div>

                    <div class="msc-pagination" id="msc-wp-pagination" style="display:none;">
                        <button type="button" class="button" id="msc-wp-prev">&laquo; <?php esc_html_e('Previous', 'msc'); ?></button>
                        <span id="msc-wp-page-info"></span>
                        <button type="button" class="button" id="msc-wp-next"><?php esc_html_e('Next', 'msc'); ?> &raquo;</button>
                    </div>
                </div>
            </div>

            <!-- AI Content Tab -->
            <?php if (get_option('msc_ai_enabled', true)): ?>
            <div class="msc-tab-panel" id="msc-tab-ai" style="display:none;">
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
                                <option value="product_description"><?php esc_html_e('Product Description', 'msc'); ?></option>
                                <option value="how_to_guide"><?php esc_html_e('How-To Guide', 'msc'); ?></option>
                                <option value="listicle"><?php esc_html_e('Listicle', 'msc'); ?></option>
                                <option value="case_study"><?php esc_html_e('Case Study', 'msc'); ?></option>
                            </select>

                            <label for="msc-ai-tone"><?php esc_html_e('Tone', 'msc'); ?></label>
                            <select id="msc-ai-tone">
                                <option value="professional"><?php esc_html_e('Professional', 'msc'); ?></option>
                                <option value="casual"><?php esc_html_e('Casual', 'msc'); ?></option>
                                <option value="formal"><?php esc_html_e('Formal', 'msc'); ?></option>
                                <option value="persuasive"><?php esc_html_e('Persuasive', 'msc'); ?></option>
                                <option value="friendly"><?php esc_html_e('Friendly', 'msc'); ?></option>
                                <option value="authoritative"><?php esc_html_e('Authoritative', 'msc'); ?></option>
                            </select>
                        </div>

                        <div class="msc-ai-options-row">
                            <label for="msc-ai-save-as"><?php esc_html_e('Save as', 'msc'); ?></label>
                            <select id="msc-ai-save-as">
                                <option value="post"><?php esc_html_e('Post', 'msc'); ?></option>
                                <option value="page"><?php esc_html_e('Page', 'msc'); ?></option>
                            </select>

                            <label for="msc-ai-category"><?php esc_html_e('Category', 'msc'); ?></label>
                            <select id="msc-ai-category">
                                <option value=""><?php esc_html_e('None', 'msc'); ?></option>
                                <?php
                                $cats = get_categories(['hide_empty' => false]);
                                foreach ($cats as $cat) {
                                    printf(
                                        '<option value="%d">%s</option>',
                                        (int) $cat->term_id,
                                        esc_html($cat->name)
                                    );
                                }
                                ?>
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
                            <button type="button" class="button" id="msc-ai-refine-btn" data-action="improve">
                                <?php esc_html_e('Improve', 'msc'); ?>
                            </button>
                            <button type="button" class="button" id="msc-ai-refine-expand" data-action="expand">
                                <?php esc_html_e('Expand', 'msc'); ?>
                            </button>
                            <button type="button" class="button" id="msc-ai-refine-seo" data-action="seo">
                                <?php esc_html_e('SEO Optimize', 'msc'); ?>
                            </button>
                            <button type="button" class="button" id="msc-ai-copy">
                                <?php esc_html_e('Copy to Clipboard', 'msc'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Taxonomy Sync Tab -->
            <div class="msc-tab-panel" id="msc-tab-taxonomy" style="display:none;">
                <div class="msc-card">
                    <h2>
                        <span class="dashicons dashicons-tag"></span>
                        <?php esc_html_e('Taxonomy Sync', 'msc'); ?>
                    </h2>
                    <p><?php esc_html_e('Sync categories and tags between WordPress and Marketing Suite.', 'msc'); ?></p>

                    <div class="msc-taxonomy-actions">
                        <button type="button" class="button button-primary" id="msc-push-categories">
                            <span class="dashicons dashicons-upload"></span>
                            <?php esc_html_e('Push Categories to Marketing Suite', 'msc'); ?>
                        </button>
                        <button type="button" class="button button-primary" id="msc-push-tags">
                            <span class="dashicons dashicons-upload"></span>
                            <?php esc_html_e('Push Tags to Marketing Suite', 'msc'); ?>
                        </button>
                        <button type="button" class="button" id="msc-refresh-taxonomy-map">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e('View Mappings', 'msc'); ?>
                        </button>
                    </div>

                    <div class="msc-taxonomy-grid" style="margin-top:20px;">
                        <div>
                            <h3><?php esc_html_e('WordPress Categories', 'msc'); ?></h3>
                            <div id="msc-local-categories">
                                <?php $this->render_local_categories(); ?>
                            </div>
                        </div>
                        <div>
                            <h3><?php esc_html_e('WordPress Tags', 'msc'); ?></h3>
                            <div id="msc-local-tags">
                                <?php $this->render_local_tags(); ?>
                            </div>
                        </div>
                    </div>

                    <div id="msc-taxonomy-map-result" style="margin-top:16px;"></div>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_local_categories(): void {
        $categories = get_categories(['hide_empty' => false, 'orderby' => 'name']);

        if (empty($categories)) {
            echo '<p>' . esc_html__('No categories found.', 'msc') . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Name', 'msc') . '</th>';
        echo '<th>' . esc_html__('Slug', 'msc') . '</th>';
        echo '<th>' . esc_html__('Posts', 'msc') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($categories as $cat) {
            echo '<tr>';
            printf('<td>%s</td>', esc_html($cat->name));
            printf('<td><code>%s</code></td>', esc_html($cat->slug));
            printf('<td>%d</td>', (int) $cat->count);
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function render_local_tags(): void {
        $tags = get_tags(['hide_empty' => false, 'orderby' => 'name']);

        if (empty($tags)) {
            echo '<p>' . esc_html__('No tags found.', 'msc') . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Name', 'msc') . '</th>';
        echo '<th>' . esc_html__('Slug', 'msc') . '</th>';
        echo '<th>' . esc_html__('Posts', 'msc') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($tags as $tag) {
            echo '<tr>';
            printf('<td>%s</td>', esc_html($tag->name));
            printf('<td><code>%s</code></td>', esc_html($tag->slug));
            printf('<td>%d</td>', (int) $tag->count);
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function render_local_posts(): void {
        $post_type   = sanitize_text_field($_GET['type'] ?? 'post');
        $post_status = sanitize_text_field($_GET['status'] ?? '');
        $statuses    = $post_status !== '' ? [$post_status] : ['publish', 'draft'];

        $posts = get_posts([
            'numberposts' => 30,
            'post_type'   => $post_type,
            'post_status' => $statuses,
            'orderby'     => 'modified',
            'order'       => 'DESC',
        ]);

        if (empty($posts)) {
            echo '<p>' . esc_html__('No posts found.', 'msc') . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat striped">';
        echo '<thead><tr>';
        echo '<th class="check-column"><input type="checkbox" class="msc-push-check-all" /></th>';
        echo '<th>' . esc_html__('Title', 'msc') . '</th>';
        echo '<th>' . esc_html__('Type', 'msc') . '</th>';
        echo '<th>' . esc_html__('Status', 'msc') . '</th>';
        echo '<th>' . esc_html__('Synced', 'msc') . '</th>';
        echo '<th>' . esc_html__('Actions', 'msc') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($posts as $p) {
            $synced_id = get_post_meta($p->ID, '_msc_remote_id', true);
            $synced_at = get_post_meta($p->ID, '_msc_synced_at', true);
            echo '<tr>';
            printf('<td><input type="checkbox" class="msc-push-check" value="%d" /></td>', (int) $p->ID);
            printf('<td><a href="%s">%s</a></td>',
                esc_url(get_edit_post_link($p->ID)),
                esc_html($p->post_title ?: '(no title)')
            );
            printf('<td>%s</td>', esc_html($p->post_type));
            printf('<td><span class="msc-badge msc-badge-%s">%s</span></td>',
                esc_attr($p->post_status),
                esc_html($p->post_status)
            );
            printf('<td>%s</td>',
                $synced_id
                    ? '<span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span> ID: ' . esc_html($synced_id) .
                      ($synced_at ? '<br><small>' . esc_html($synced_at) . '</small>' : '')
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
            'excerpt'     => $post->post_excerpt,
            'slug'        => $post->post_name,
            'status'      => $post->post_status === 'publish' ? 'published' : 'draft',
            'platform'    => 'wordpress',
            'content_type'=> $post->post_type === 'page' ? 'page' : 'blog_post',
            'wp_post_id'  => $post_id,
            'wp_post_type'=> $post->post_type,
            'wp_url'      => get_permalink($post_id),
            'categories'  => wp_get_post_categories($post_id, ['fields' => 'names']),
            'tags'        => wp_get_post_tags($post_id, ['fields' => 'names']),
        ];

        // Get featured image URL
        $thumb_id = get_post_thumbnail_id($post_id);
        if ($thumb_id) {
            $payload['featured_image_url'] = wp_get_attachment_url($thumb_id);
        }

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
     * Bulk push multiple WordPress posts to Marketing Suite.
     */
    public function rest_bulk_push(\WP_REST_Request $request): \WP_REST_Response {
        $post_ids = $request->get_param('post_ids');

        if (!is_array($post_ids) || empty($post_ids)) {
            return new \WP_REST_Response(['error' => 'post_ids array is required.'], 400);
        }

        $results = [];
        foreach ($post_ids as $post_id) {
            $sub_request = new \WP_REST_Request('POST', '/msc/v1/push-post');
            $sub_request->set_param('post_id', (int) $post_id);
            $result = $this->rest_push_post($sub_request);
            $data = $result->get_data();
            $results[] = [
                'post_id'   => (int) $post_id,
                'success'   => $data['success'] ?? false,
                'remote_id' => $data['remote_id'] ?? null,
                'error'     => $data['error'] ?? null,
            ];
        }

        $success_count = count(array_filter($results, fn($r) => $r['success'] ?? false));

        return new \WP_REST_Response([
            'success' => true,
            'results' => $results,
            'message' => sprintf('%d of %d posts pushed successfully.', $success_count, count($results)),
        ]);
    }

    /**
     * Import a Marketing Suite post as a WordPress draft.
     */
    public function rest_import_post(\WP_REST_Request $request): \WP_REST_Response {
        $remote_id = (int) $request->get_param('remote_id');
        $post_type = sanitize_text_field($request->get_param('post_type') ?: 'post');

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
        $existing_post_id = (int) $this->find_existing_post_for_remote_id($remote_id);

        if ($existing_post_id > 0) {
            return new \WP_REST_Response([
                'success'    => true,
                'wp_post_id' => $existing_post_id,
                'edit_url'   => get_edit_post_link($existing_post_id, 'raw'),
                'message'    => 'This post was already imported.',
                'existing'   => true,
            ]);
        }

        $wp_post_id = wp_insert_post([
            'post_title'   => sanitize_text_field($item['title'] ?? 'Imported Post'),
            'post_content' => wp_kses_post($item['body'] ?? $item['content'] ?? ''),
            'post_status'  => $default_status,
            'post_type'    => in_array($post_type, ['post', 'page'], true) ? $post_type : 'post',
            'post_excerpt' => sanitize_text_field($item['excerpt'] ?? ''),
        ], true);

        if (is_wp_error($wp_post_id)) {
            return new \WP_REST_Response(['error' => $wp_post_id->get_error_message()], 500);
        }

        // Link the WP post to the remote post
        update_post_meta($wp_post_id, '_msc_remote_id', $remote_id);
        update_post_meta($wp_post_id, '_msc_synced_at', current_time('mysql'));

        // Sync categories
        if (get_option('msc_sync_categories', true)) {
            $this->sync_imported_taxonomies($wp_post_id, $item);
        }

        return new \WP_REST_Response([
            'success'    => true,
            'wp_post_id' => $wp_post_id,
            'edit_url'   => get_edit_post_link($wp_post_id, 'raw'),
            'message'    => 'Post imported as ' . $default_status . '.',
        ]);
    }

    /**
     * Bulk import multiple posts from Marketing Suite.
     */
    public function rest_bulk_import(\WP_REST_Request $request): \WP_REST_Response {
        $remote_ids = $request->get_param('remote_ids');
        $post_type  = sanitize_text_field($request->get_param('post_type') ?: 'post');

        if (!is_array($remote_ids) || empty($remote_ids)) {
            return new \WP_REST_Response(['error' => 'remote_ids array is required.'], 400);
        }

        $results = [];
        foreach ($remote_ids as $remote_id) {
            $sub_request = new \WP_REST_Request('POST', '/msc/v1/import-post');
            $sub_request->set_param('remote_id', (int) $remote_id);
            $sub_request->set_param('post_type', $post_type);
            $result = $this->rest_import_post($sub_request);
            $data = $result->get_data();
            $results[] = [
                'remote_id'  => (int) $remote_id,
                'success'    => $data['success'] ?? false,
                'wp_post_id' => $data['wp_post_id'] ?? null,
                'edit_url'   => $data['edit_url'] ?? null,
                'existing'   => $data['existing'] ?? false,
                'error'      => $data['error'] ?? null,
            ];
        }

        $success_count = count(array_filter($results, fn($r) => $r['success'] ?? false));

        return new \WP_REST_Response([
            'success' => true,
            'results' => $results,
            'message' => sprintf('%d of %d posts imported successfully.', $success_count, count($results)),
        ]);
    }

    /**
     * Fetch WordPress site content through Marketing Suite proxy.
     */
    public function rest_fetch_wp_content(\WP_REST_Request $request): \WP_REST_Response {
        $content_type = sanitize_text_field($request->get_param('content_type') ?: 'posts');
        $endpoint = $content_type === 'pages' ? '/api/wordpress-plugin/wp-pages' : '/api/wordpress-plugin/wp-posts';

        $params = [];
        foreach (['per_page', 'page', 'status', 'search', 'orderby', 'order'] as $key) {
            $val = $request->get_param($key);
            if ($val !== null && $val !== '') {
                $params[$key] = $val;
            }
        }

        $data = $this->api->get($endpoint, $params);

        if (isset($data['error'])) {
            return new \WP_REST_Response(['error' => $data['error']], 502);
        }

        return new \WP_REST_Response($data);
    }

    /**
     * Get sync status/map from Marketing Suite.
     */
    public function rest_sync_status(\WP_REST_Request $request): \WP_REST_Response {
        $data = $this->api->get('/api/wordpress-plugin/sync-map', [
            'local_type' => $request->get_param('local_type') ?: 'post',
            'limit'      => $request->get_param('limit') ?: 50,
        ]);

        if (isset($data['error'])) {
            return new \WP_REST_Response(['error' => $data['error']], 502);
        }

        return new \WP_REST_Response($data);
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

    // -------------------------------------------------------------------------
    //  Helpers
    // -------------------------------------------------------------------------

    /**
     * Sync taxonomy terms for an imported post.
     */
    private function sync_imported_taxonomies(int $wp_post_id, array $item): void {
        // Handle tags from the imported item
        $tags_str = $item['tags'] ?? '';
        if (is_string($tags_str) && $tags_str !== '') {
            $tag_names = array_map('trim', explode(',', $tags_str));
            $tag_names = array_filter($tag_names);
            if (!empty($tag_names)) {
                wp_set_post_tags($wp_post_id, $tag_names, true);
            }
        } elseif (is_array($tags_str)) {
            wp_set_post_tags($wp_post_id, $tags_str, true);
        }

        // Handle platform as category
        if (!empty($item['platform'])) {
            $cat_id = wp_create_category($item['platform']);
            if ($cat_id && !is_wp_error($cat_id)) {
                wp_set_post_categories($wp_post_id, [$cat_id], true);
            }
        }

        // Handle categories if provided as names
        if (!empty($item['categories']) && is_array($item['categories'])) {
            $cat_ids = [];
            foreach ($item['categories'] as $cat_name) {
                if (is_string($cat_name)) {
                    $cid = wp_create_category($cat_name);
                    if ($cid && !is_wp_error($cid)) {
                        $cat_ids[] = $cid;
                    }
                } elseif (is_int($cat_name)) {
                    $cat_ids[] = $cat_name;
                }
            }
            if (!empty($cat_ids)) {
                wp_set_post_categories($wp_post_id, $cat_ids, true);
            }
        }
    }

    private function find_existing_post_for_remote_id(int $remote_id): int {
        $posts = get_posts([
            'post_type'      => ['post', 'page'],
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'meta_key'       => '_msc_remote_id',
            'meta_value'     => (string) $remote_id,
        ]);

        if (!empty($posts)) {
            return (int) $posts[0];
        }

        return 0;
    }

    public function maybe_auto_push_post(int $post_id, \WP_Post $post, bool $update): void {
        if (!get_option('msc_auto_push', false) || !$this->api->is_configured()) {
            return;
        }

        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if (!in_array($post->post_status, ['publish', 'draft'], true)) {
            return;
        }

        // Do not auto-push auto-drafts created by editor.
        if ($post->post_status === 'draft' && !$update) {
            return;
        }

        $request = new \WP_REST_Request('POST', '/msc/v1/push-post');
        $request->set_param('post_id', $post_id);
        $this->rest_push_post($request);
    }
}
