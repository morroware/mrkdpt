<?php
/**
 * Taxonomy synchronization between WordPress and Marketing Suite.
 *
 * Handles bidirectional sync of categories and tags, including mapping
 * WordPress term IDs to Marketing Suite values and vice versa.
 */

defined('ABSPATH') || exit;

class MSC_Taxonomy_Sync {

    private MSC_API_Client $api;

    public function __construct(MSC_API_Client $api) {
        $this->api = $api;
    }

    /**
     * Fetch categories from the Marketing Suite's connected WordPress site.
     */
    public function get_remote_categories(array $params = []): array {
        $result = $this->api->get('/api/wordpress-plugin/wp-categories', $params);
        if (isset($result['error'])) {
            return [];
        }
        return $result['items'] ?? [];
    }

    /**
     * Fetch tags from the Marketing Suite's connected WordPress site.
     */
    public function get_remote_tags(array $params = []): array {
        $result = $this->api->get('/api/wordpress-plugin/wp-tags', $params);
        if (isset($result['error'])) {
            return [];
        }
        return $result['items'] ?? [];
    }

    /**
     * Get the taxonomy mapping from Marketing Suite.
     */
    public function get_taxonomy_map(string $taxonomy = 'category'): array {
        $result = $this->api->get('/api/wordpress-plugin/taxonomy-map', ['taxonomy' => $taxonomy]);
        if (isset($result['error'])) {
            return [];
        }
        return $result['items'] ?? [];
    }

    /**
     * Save a taxonomy mapping to the Marketing Suite.
     */
    public function save_taxonomy_mapping(string $local_value, string $taxonomy, int $wp_term_id, string $wp_term_name): array {
        return $this->api->post('/api/wordpress-plugin/taxonomy-map', [
            'local_value'  => $local_value,
            'taxonomy'     => $taxonomy,
            'wp_term_id'   => $wp_term_id,
            'wp_term_name' => $wp_term_name,
        ]);
    }

    /**
     * Delete a taxonomy mapping.
     */
    public function delete_taxonomy_mapping(int $mapping_id): array {
        return $this->api->delete('/api/wordpress-plugin/taxonomy-map/' . $mapping_id);
    }

    /**
     * Push WordPress categories to Marketing Suite.
     * Creates mappings for all WordPress categories.
     */
    public function push_categories(): array {
        $categories = get_categories([
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        $results = [];
        foreach ($categories as $cat) {
            $result = $this->save_taxonomy_mapping(
                $cat->slug,
                'category',
                (int) $cat->term_id,
                $cat->name
            );
            $results[] = [
                'term_id' => $cat->term_id,
                'name'    => $cat->name,
                'success' => !isset($result['error']),
            ];
        }

        return $results;
    }

    /**
     * Push WordPress tags to Marketing Suite.
     */
    public function push_tags(): array {
        $tags = get_tags([
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        $results = [];
        foreach ($tags as $tag) {
            $result = $this->save_taxonomy_mapping(
                $tag->slug,
                'tag',
                (int) $tag->term_id,
                $tag->name
            );
            $results[] = [
                'term_id' => $tag->term_id,
                'name'    => $tag->name,
                'success' => !isset($result['error']),
            ];
        }

        return $results;
    }

    /**
     * Map remote category/tag names to WordPress term IDs.
     * Creates terms if they don't exist and auto-create is enabled.
     */
    public function resolve_term_ids(array $names, string $taxonomy = 'category', bool $auto_create = true): array {
        $term_ids = [];

        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $wp_taxonomy = $taxonomy === 'tag' ? 'post_tag' : 'category';
            $term = get_term_by('name', $name, $wp_taxonomy);

            if ($term) {
                $term_ids[] = (int) $term->term_id;
            } elseif ($auto_create) {
                $result = wp_insert_term($name, $wp_taxonomy);
                if (!is_wp_error($result)) {
                    $term_ids[] = (int) $result['term_id'];
                }
            }
        }

        return $term_ids;
    }

    /**
     * Get local WordPress categories formatted for display.
     */
    public function get_local_categories(): array {
        $categories = get_categories([
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        return array_map(function ($cat) {
            return [
                'id'     => (int) $cat->term_id,
                'name'   => $cat->name,
                'slug'   => $cat->slug,
                'count'  => (int) $cat->count,
                'parent' => (int) $cat->parent,
            ];
        }, $categories);
    }

    /**
     * Get local WordPress tags formatted for display.
     */
    public function get_local_tags(): array {
        $tags = get_tags([
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        return array_map(function ($tag) {
            return [
                'id'    => (int) $tag->term_id,
                'name'  => $tag->name,
                'slug'  => $tag->slug,
                'count' => (int) $tag->count,
            ];
        }, $tags);
    }

    // -------------------------------------------------------------------------
    //  REST endpoints
    // -------------------------------------------------------------------------

    public function rest_get_categories(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response([
            'success' => true,
            'items'   => $this->get_local_categories(),
        ]);
    }

    public function rest_get_tags(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response([
            'success' => true,
            'items'   => $this->get_local_tags(),
        ]);
    }

    public function rest_push_taxonomies(\WP_REST_Request $request): \WP_REST_Response {
        $taxonomy = sanitize_text_field($request->get_param('taxonomy') ?: 'all');

        $results = [];
        if ($taxonomy === 'all' || $taxonomy === 'category') {
            $results['categories'] = $this->push_categories();
        }
        if ($taxonomy === 'all' || $taxonomy === 'tag') {
            $results['tags'] = $this->push_tags();
        }

        return new \WP_REST_Response([
            'success' => true,
            'results' => $results,
        ]);
    }

    public function rest_get_taxonomy_map(\WP_REST_Request $request): \WP_REST_Response {
        $taxonomy = sanitize_text_field($request->get_param('taxonomy') ?: 'category');
        $items = $this->get_taxonomy_map($taxonomy);

        return new \WP_REST_Response([
            'success' => true,
            'items'   => $items,
        ]);
    }
}
