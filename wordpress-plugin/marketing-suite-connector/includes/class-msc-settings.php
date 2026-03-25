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
        register_setting('msc_settings', 'msc_api_url', [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
        ]);
        register_setting('msc_settings', 'msc_api_token', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('msc_settings', 'msc_default_status', [
            'type'              => 'string',
            'sanitize_callback' => function ($val) {
                return in_array($val, ['draft', 'publish'], true) ? $val : 'draft';
            },
        ]);
        register_setting('msc_settings', 'msc_sync_categories', [
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => true,
        ]);
        register_setting('msc_settings', 'msc_ai_enabled', [
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
        if ($this->api->is_configured()) {
            $test = $this->api->get('/api/wordpress-plugin/status');
            $connected = !isset($test['error']);
        }
        ?>
        <div class="wrap msc-wrap">
            <h1><?php esc_html_e('Marketing Suite Settings', 'msc'); ?></h1>

            <div class="msc-connection-status <?php echo $connected ? 'connected' : 'disconnected'; ?>">
                <span class="dashicons <?php echo $connected ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                <?php echo $connected
                    ? esc_html__('Connected to Marketing Suite', 'msc')
                    : esc_html__('Not connected', 'msc'); ?>
                <button type="button" class="button button-small msc-test-connection">
                    <?php esc_html_e('Test Connection', 'msc'); ?>
                </button>
                <span class="msc-test-result"></span>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('msc_settings'); ?>

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
                                <?php esc_html_e('Bearer token from your Marketing Suite user profile (Settings → API Token).', 'msc'); ?>
                            </p>
                        </td>
                    </tr>
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
                        <th scope="row"><?php esc_html_e('Sync Categories', 'msc'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="msc_sync_categories" value="1"
                                    <?php checked(get_option('msc_sync_categories', true)); ?> />
                                <?php esc_html_e('Automatically map Marketing Suite platforms/tags to WordPress categories', 'msc'); ?>
                            </label>
                        </td>
                    </tr>
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
                </ol>
            </div>
        </div>
        <?php
    }
}
