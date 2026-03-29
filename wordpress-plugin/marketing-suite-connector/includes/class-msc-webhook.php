<?php
/**
 * Webhook handler for sending real-time notifications to Marketing Suite.
 *
 * Fires webhook events when WordPress posts/pages are created, updated,
 * published, trashed, or deleted so the Marketing Suite can keep in sync.
 */

defined('ABSPATH') || exit;

class MSC_Webhook {

    private MSC_API_Client $api;

    public function __construct(MSC_API_Client $api) {
        $this->api = $api;

        if (!$this->api->is_configured() || !get_option('msc_webhooks_enabled', true)) {
            return;
        }

        // Post lifecycle hooks
        add_action('transition_post_status', [$this, 'on_status_transition'], 20, 3);
        add_action('before_delete_post', [$this, 'on_post_delete'], 20, 2);
        add_action('wp_trash_post', [$this, 'on_post_trash'], 20, 1);

        // Taxonomy hooks
        add_action('created_term', [$this, 'on_term_created'], 20, 3);
        add_action('edited_term', [$this, 'on_term_edited'], 20, 3);
        add_action('delete_term', [$this, 'on_term_deleted'], 20, 5);
    }

    /**
     * Send a webhook event to the Marketing Suite backend.
     */
    private function send_event(string $event, array $data = []): void {
        if (!$this->api->is_configured()) {
            return;
        }

        $payload = array_merge(['event' => $event], $data);

        // Fire async to avoid blocking WordPress
        $this->api->post('/api/wordpress-plugin/webhook', $payload);
    }

    /**
     * Called when a post transitions between statuses.
     */
    public function on_status_transition(string $new_status, string $old_status, \WP_Post $post): void {
        // Only handle standard post types
        if (!in_array($post->post_type, ['post', 'page'], true)) {
            return;
        }

        // Skip auto-drafts and revisions
        if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
            return;
        }
        if ($new_status === 'auto-draft') {
            return;
        }

        // Determine the event type
        $event = 'post_updated';
        if ($new_status === 'publish' && $old_status !== 'publish') {
            $event = 'post_published';
        } elseif ($old_status === 'publish' && $new_status !== 'publish') {
            $event = 'post_unpublished';
        } elseif ($new_status === 'trash') {
            $event = 'post_trashed';
            // Handled by wp_trash_post hook instead to avoid double-firing
            return;
        }

        $categories = wp_get_post_categories($post->ID, ['fields' => 'names']);
        $tags       = wp_get_post_tags($post->ID, ['fields' => 'names']);

        $this->send_event($event, [
            'wp_post_id'   => $post->ID,
            'wp_post_type' => $post->post_type,
            'title'        => $post->post_title,
            'content'      => $post->post_content,
            'excerpt'      => $post->post_excerpt,
            'slug'         => $post->post_name,
            'status'       => $new_status,
            'old_status'   => $old_status,
            'url'          => get_permalink($post->ID),
            'author'       => get_the_author_meta('display_name', $post->post_author),
            'categories'   => $categories,
            'tags'         => $tags,
            'modified_at'  => $post->post_modified_gmt,
        ]);
    }

    /**
     * Called when a post is trashed.
     */
    public function on_post_trash(int $post_id): void {
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, ['post', 'page'], true)) {
            return;
        }

        $this->send_event('post_trashed', [
            'wp_post_id'   => $post_id,
            'wp_post_type' => $post->post_type,
            'title'        => $post->post_title,
        ]);
    }

    /**
     * Called when a post is permanently deleted.
     */
    public function on_post_delete(int $post_id, ?\WP_Post $post): void {
        if (!$post || !in_array($post->post_type, ['post', 'page'], true)) {
            return;
        }

        $this->send_event('post_deleted', [
            'wp_post_id'   => $post_id,
            'wp_post_type' => $post->post_type,
            'title'        => $post->post_title,
        ]);
    }

    /**
     * Called when a term (category/tag) is created.
     */
    public function on_term_created(int $term_id, int $tt_id, string $taxonomy): void {
        if (!in_array($taxonomy, ['category', 'post_tag'], true)) {
            return;
        }

        $term = get_term($term_id, $taxonomy);
        if (!$term || is_wp_error($term)) {
            return;
        }

        $this->send_event('term_created', [
            'wp_term_id' => $term_id,
            'taxonomy'   => $taxonomy === 'post_tag' ? 'tag' : 'category',
            'name'       => $term->name,
            'slug'       => $term->slug,
        ]);
    }

    /**
     * Called when a term is edited.
     */
    public function on_term_edited(int $term_id, int $tt_id, string $taxonomy): void {
        if (!in_array($taxonomy, ['category', 'post_tag'], true)) {
            return;
        }

        $term = get_term($term_id, $taxonomy);
        if (!$term || is_wp_error($term)) {
            return;
        }

        $this->send_event('term_updated', [
            'wp_term_id' => $term_id,
            'taxonomy'   => $taxonomy === 'post_tag' ? 'tag' : 'category',
            'name'       => $term->name,
            'slug'       => $term->slug,
        ]);
    }

    /**
     * Called when a term is deleted.
     */
    public function on_term_deleted(int $term_id, int $tt_id, string $taxonomy, \WP_Term $deleted_term, array $object_ids): void {
        if (!in_array($taxonomy, ['category', 'post_tag'], true)) {
            return;
        }

        $this->send_event('term_deleted', [
            'wp_term_id' => $term_id,
            'taxonomy'   => $taxonomy === 'post_tag' ? 'tag' : 'category',
            'name'       => $deleted_term->name,
            'slug'       => $deleted_term->slug,
        ]);
    }
}
