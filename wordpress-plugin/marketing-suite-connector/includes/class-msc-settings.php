<?php
/**
 * Settings page for the Marketing Suite Connector plugin.
 */

defined('ABSPATH') || exit;

class MSC_Settings {

    private MSC_API_Client $api;

    public function __construct(MSC_API_Client $api) {
        $this->api = $api;
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_settings(): void {
        // Connection settings
        register_setting('msc_settings', 'msc_api_url', [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
        ]);
        register_setting('msc_settings', 'msc_api_token', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        // Content settings
        register_setting('msc_settings', 'msc_default_status', [
            'type'              => 'string',
            'sanitize_callback' => function ($val) {
                return in_array($val, ['draft', 'publish'], true) ? $val : 'draft';
            },
        ]);
        register_setting('msc_settings', 'msc_default_post_type', [
            'type'              => 'string',
            'sanitize_callback' => function ($val) {
                return in_array($val, ['post', 'page'], true) ? $val : 'post';
            },
        ]);
        register_setting('msc_settings', 'msc_sync_categories', [
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => true,
        ]);
        register_setting('msc_settings', 'msc_sync_tags', [
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => true,
        ]);
        register_setting('msc_settings', 'msc_sync_featured_images', [
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => true,
        ]);

        // Automation settings
        register_setting('msc_settings', 'msc_ai_enabled', [
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => true,
        ]);
        register_setting('msc_settings', 'msc_auto_push', [
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
        ]);
        register_setting('msc_settings', 'msc_auto_push_types', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'post',
        ]);
        register_setting('msc_settings', 'msc_webhooks_enabled', [
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => true,
        ]);
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'msc'));
        }

        $connected = false;
        $site_info = null;
        if ($this->api->is_configured()) {
            $test = $this->api->get('/api/wordpress-plugin/status');
            $connected = !isset($test['error']);
            if ($connected) {
                $site_info = $test;
            }
        }
        ?>
        <div class="wrap msc-wrap">
            <h1><?php esc_html_e('Marketing Suite Settings', 'msc'); ?></h1>

            <div class="msc-connection-status <?php echo $connected ? 'connected' : 'disconnected'; ?>">
                <span class="dashicons <?php echo $connected ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                <?php echo $connected
                    ? esc_html__('Connected to Marketing Suite', 'msc')
                    : esc_html__('Not connected', 'msc'); ?>
                <?php if ($connected && $site_info): ?>
                    <span class="msc-connection-details">
                        &mdash; v<?php echo esc_html($site_info['version'] ?? '?'); ?>
                        (<?php echo esc_html($site_info['posts'] ?? '?'); ?> posts,
                        <?php echo esc_html($site_info['campaigns'] ?? '?'); ?> campaigns)
                    </span>
                <?php endif; ?>
                <button type="button" class="button button-small msc-test-connection">
                    <?php esc_html_e('Test Connection', 'msc'); ?>
                </button>
                <span class="msc-test-result"></span>
            </div>

            <?php if ($connected && !empty($site_info['capabilities'])): ?>
            <div class="msc-capabilities-bar">
                <strong><?php esc_html_e('Capabilities:', 'msc'); ?></strong>
                <?php foreach ($site_info['capabilities'] as $cap): ?>
                    <span class="msc-capability-badge"><?php echo esc_html(str_replace('_', ' ', $cap)); ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('msc_settings'); ?>

                <!-- Connection Section -->
                <h2 class="msc-section-header">
                    <span class="dashicons dashicons-admin-links"></span>
                    <?php esc_html_e('Connection', 'msc'); ?>
                </h2>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="msc_api_url"><?php esc_html_e('Marketing Suite URL', 'msc'); ?></label>
                        </th>
                        <td>
                            <input type="url" id="msc_api_url" name="msc_api_url"
                                   value="<?php echo esc_attr(get_option('msc_api_url')); ?>"
                                   class="regular-text" placeholder="https://your-marketing-suite.com" />
                            <p class="description">
                                <?php esc_html_e('The full URL of your Marketing Suite installation (no trailing slash).', 'msc'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="msc_api_token"><?php esc_html_e('API Token', 'msc'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="msc_api_token" name="msc_api_token"
                                   value="<?php echo esc_attr(get_option('msc_api_token')); ?>"
                                   class="regular-text" autocomplete="off" />
                            <p class="description">
                                <?php esc_html_e('Bearer token from your Marketing Suite user profile (Settings > API Token).', 'msc'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <!-- Content Sync Section -->
                <h2 class="msc-section-header">
                    <span class="dashicons dashicons-update"></span>
                    <?php esc_html_e('Content Sync', 'msc'); ?>
                </h2>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="msc_default_status"><?php esc_html_e('Default Import Status', 'msc'); ?></label>
                        </th>
                        <td>
                            <select id="msc_default_status" name="msc_default_status">
                                <option value="draft" <?php selected(get_option('msc_default_status', 'draft'), 'draft'); ?>>
                                    <?php esc_html_e('Draft', 'msc'); ?>
                                </option>
                                <option value="publish" <?php selected(get_option('msc_default_status'), 'publish'); ?>>
                                    <?php esc_html_e('Published', 'msc'); ?>
                                </option>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Status for posts imported from Marketing Suite into WordPress.', 'msc'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="msc_default_post_type"><?php esc_html_e('Default Import Post Type', 'msc'); ?></label>
                        </th>
                        <td>
                            <select id="msc_default_post_type" name="msc_default_post_type">
                                <option value="post" <?php selected(get_option('msc_default_post_type', 'post'), 'post'); ?>>
                                    <?php esc_html_e('Post', 'msc'); ?>
                                </option>
                                <option value="page" <?php selected(get_option('msc_default_post_type'), 'page'); ?>>
                                    <?php esc_html_e('Page', 'msc'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Taxonomy Sync', 'msc'); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="msc_sync_categories" value="1"
                                        <?php checked(get_option('msc_sync_categories', true)); ?> />
                                    <?php esc_html_e('Sync categories when importing/pushing posts', 'msc'); ?>
                                </label>
                                <br/>
                                <label>
                                    <input type="checkbox" name="msc_sync_tags" value="1"
                                        <?php checked(get_option('msc_sync_tags', true)); ?> />
                                    <?php esc_html_e('Sync tags when importing/pushing posts', 'msc'); ?>
                                </label>
                                <br/>
                                <label>
                                    <input type="checkbox" name="msc_sync_featured_images" value="1"
                                        <?php checked(get_option('msc_sync_featured_images', true)); ?> />
                                    <?php esc_html_e('Include featured image URL when pushing posts', 'msc'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <!-- Automation Section -->
                <h2 class="msc-section-header">
                    <span class="dashicons dashicons-admin-generic"></span>
                    <?php esc_html_e('Automation', 'msc'); ?>
                </h2>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('AI Features', 'msc'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="msc_ai_enabled" value="1"
                                    <?php checked(get_option('msc_ai_enabled', true)); ?> />
                                <?php esc_html_e('Enable AI content generation and refinement via Marketing Suite', 'msc'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Auto Push', 'msc'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="msc_auto_push" value="1"
                                    <?php checked(get_option('msc_auto_push', false)); ?> />
                                <?php esc_html_e('Automatically push posts to Marketing Suite when they are published or updated', 'msc'); ?>
                            </label>
                            <br/>
                            <label>
                                <?php esc_html_e('Auto-push post types:', 'msc'); ?>
                                <select name="msc_auto_push_types">
                                    <option value="post" <?php selected(get_option('msc_auto_push_types', 'post'), 'post'); ?>>
                                        <?php esc_html_e('Posts only', 'msc'); ?>
                                    </option>
                                    <option value="post,page" <?php selected(get_option('msc_auto_push_types'), 'post,page'); ?>>
                                        <?php esc_html_e('Posts and Pages', 'msc'); ?>
                                    </option>
                                </select>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Webhooks', 'msc'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="msc_webhooks_enabled" value="1"
                                    <?php checked(get_option('msc_webhooks_enabled', true)); ?> />
                                <?php esc_html_e('Send real-time notifications to Marketing Suite when posts are created, updated, or deleted', 'msc'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('When enabled, Marketing Suite will be notified immediately of content changes, keeping the sync map up-to-date.', 'msc'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <div class="msc-info-box">
                <h3><?php esc_html_e('Setup Instructions', 'msc'); ?></h3>
                <ol>
                    <li><?php esc_html_e('In your Marketing Suite, go to Settings and copy your API Token.', 'msc'); ?></li>
                    <li><?php esc_html_e('Enter your Marketing Suite URL above (e.g., https://marketing.example.com).', 'msc'); ?></li>
                    <li><?php esc_html_e('Paste the API Token and click "Save Changes".', 'msc'); ?></li>
                    <li><?php esc_html_e('Click "Test Connection" to verify everything works.', 'msc'); ?></li>
                    <li><?php esc_html_e('For bidirectional sync, also add your WordPress site as a Social Account in Marketing Suite (Social > Add Account > WordPress).', 'msc'); ?></li>
                </ol>
            </div>

            <div class="msc-info-box">
                <h3><?php esc_html_e('WordPress REST API Setup', 'msc'); ?></h3>
                <p><?php esc_html_e('To enable Marketing Suite to push content directly to this WordPress site:', 'msc'); ?></p>
                <ol>
                    <li><?php esc_html_e('Go to your WordPress user profile (Users > Profile).', 'msc'); ?></li>
                    <li><?php esc_html_e('Scroll to "Application Passwords" and create a new one.', 'msc'); ?></li>
                    <li><?php esc_html_e('In Marketing Suite, go to Social > Add Account > WordPress.', 'msc'); ?></li>
                    <li>
                        <?php printf(
                            esc_html__('Enter this site URL: %s', 'msc'),
                            '<code>' . esc_html(home_url()) . '</code>'
                        ); ?>
                    </li>
                    <li><?php esc_html_e('Enter your username:application_password as the access token.', 'msc'); ?></li>
                </ol>
            </div>
        </div>
        <?php
    }
}
