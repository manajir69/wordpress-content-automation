<?php
/**
 * Plugin Name: Portfolio Blog Connector
 * Description: Securely receives researched blog drafts and saves Yoast SEO fields for the Blog Automation system.
 * Version: 1.3.0
 * Author: Blog Automation
 * Requires at least: 6.0
 * Requires PHP: 8.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', static function (): void {
    register_rest_route('portfolio-blog/v1', '/status', [
        'methods' => WP_REST_Server::READABLE,
        'permission_callback' => static function (): bool {
            return current_user_can('edit_posts');
        },
        'callback' => static function (): WP_REST_Response {
            return new WP_REST_Response([
                'connected' => true,
                'user' => wp_get_current_user()->user_login,
                'can_publish' => current_user_can('publish_posts'),
                'yoast_active' => defined('WPSEO_VERSION'),
                'yoast_version' => defined('WPSEO_VERSION') ? WPSEO_VERSION : null,
                'woocommerce_active' => function_exists('wc_get_products'),
            ]);
        },
    ]);
    register_rest_route('portfolio-blog/v1', '/products', [
        'methods' => WP_REST_Server::READABLE,
        'permission_callback' => static function (): bool {
            return current_user_can('edit_posts');
        },
        'callback' => 'portfolio_blog_connector_products',
    ]);
    register_rest_route('portfolio-blog/v1', '/articles', [
        'methods' => WP_REST_Server::CREATABLE,
        'permission_callback' => static function (): bool {
            return current_user_can('edit_posts');
        },
        'callback' => 'portfolio_blog_connector_create_article',
    ]);
});

/** @return WP_REST_Response|WP_Error */
function portfolio_blog_connector_products(WP_REST_Request $request)
{
    if (!function_exists('wc_get_products')) {
        return new WP_Error('bi_woocommerce_missing', 'WooCommerce is not active.', ['status' => 409]);
    }
    $limit = max(10, min(80, absint($request->get_param('limit')) ?: 50));
    $products = wc_get_products([
        'status' => 'publish',
        'limit' => $limit,
        'orderby' => 'rand',
        'return' => 'objects',
    ]);
    $result = [];
    foreach ($products as $product) {
        if (!$product instanceof WC_Product) continue;
        $featuredId = (int)$product->get_image_id();
        $featured = $featuredId > 0 ? portfolio_blog_connector_image($featuredId, 'featured') : null;
        $images = array_merge(
            portfolio_blog_connector_description_images((string)$product->get_description()),
            portfolio_blog_connector_description_images((string)$product->get_short_description())
        );
        foreach ((array)$product->get_gallery_image_ids() as $imageId) {
            $image = portfolio_blog_connector_image((int)$imageId, 'gallery');
            if ($image) $images[] = $image;
        }
        if ($featured) $images[] = $featured;
        $unique = [];
        foreach ($images as $image) {
            if (!is_array($image) || empty($image['url'])) continue;
            $unique[(string)$image['url']] = $image;
        }
        if ($featured === null && $unique === []) continue;

        $attributes = [];
        foreach ($product->get_attributes() as $attribute) {
            if (!$attribute instanceof WC_Product_Attribute || !$attribute->get_visible()) continue;
            $values = $attribute->is_taxonomy()
                ? wc_get_product_terms($product->get_id(), $attribute->get_name(), ['fields' => 'names'])
                : $attribute->get_options();
            if (is_wp_error($values) || $values === []) continue;
            $attributes[] = [
                'name' => wc_attribute_label($attribute->get_name()),
                'values' => array_values(array_map('strval', $values)),
            ];
        }
        $categories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
        if (is_wp_error($categories)) $categories = [];
        $result[] = [
            'id' => (int)$product->get_id(),
            'name' => (string)$product->get_name(),
            'sku' => (string)$product->get_sku(),
            'url' => (string)$product->get_permalink(),
            'short_description' => trim(wp_strip_all_tags((string)$product->get_short_description())),
            'description' => trim(wp_strip_all_tags((string)$product->get_description())),
            'price' => trim(wp_strip_all_tags((string)$product->get_price_html())),
            'categories' => array_values($categories),
            'attributes' => $attributes,
            'featured_image' => $featured,
            'images' => array_values(array_slice($unique, 0, 12)),
        ];
    }
    return new WP_REST_Response(['products' => $result], 200);
}

/** @return array{id:int,url:string,alt:string,source:string}|null */
function portfolio_blog_connector_image(int $attachmentId, string $source): ?array
{
    if ($attachmentId < 1 || !wp_attachment_is_image($attachmentId)) return null;
    $url = wp_get_attachment_image_url($attachmentId, 'medium_large');
    if (!$url) return null;
    $url = esc_url_raw($url, ['https']);
    if ($url === '') return null;
    return [
        'id' => $attachmentId,
        'url' => $url,
        'alt' => sanitize_text_field((string)get_post_meta($attachmentId, '_wp_attachment_image_alt', true)),
        'source' => $source,
    ];
}

/** @return array<int,array{id:int,url:string,alt:string,source:string}> */
function portfolio_blog_connector_description_images(string $html): array
{
    if ($html === '') return [];
    preg_match_all('/<img\b[^>]*>/i', $html, $tags);
    $images = [];
    foreach ((array)($tags[0] ?? []) as $tag) {
        if (!preg_match('/\bsrc\s*=\s*["\x27]([^"\x27]+)["\x27]/i', $tag, $src)) continue;
        $url = esc_url_raw(html_entity_decode((string)$src[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ['https']);
        if ($url === '') continue;
        $id = 0;
        if (preg_match('/\bwp-image-(\d+)\b/i', $tag, $classId)) $id = absint($classId[1]);
        if ($id < 1) $id = (int)attachment_url_to_postid($url);
        if ($id > 0) {
            $sizedUrl = wp_get_attachment_image_url($id, 'medium_large');
            if ($sizedUrl) $url = esc_url_raw($sizedUrl, ['https']);
        }
        $alt = '';
        if (preg_match('/\balt\s*=\s*["\x27]([^"\x27]*)["\x27]/i', $tag, $altMatch)) {
            $alt = sanitize_text_field(html_entity_decode((string)$altMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if ($alt === '' && $id > 0) $alt = sanitize_text_field((string)get_post_meta($id, '_wp_attachment_image_alt', true));
        $images[] = ['id' => $id, 'url' => $url, 'alt' => $alt, 'source' => 'description'];
    }
    return $images;
}

/** @return WP_REST_Response|WP_Error */
function portfolio_blog_connector_create_article(WP_REST_Request $request)
{
    $title = sanitize_text_field((string)$request->get_param('title'));
    $content = (string)$request->get_param('content_html');
    $focusKeyphrase = sanitize_text_field((string)$request->get_param('focus_keyphrase'));
    $seoTitle = sanitize_text_field((string)$request->get_param('seo_title'));
    $metaDescription = sanitize_text_field((string)$request->get_param('meta_description'));
    if ($title === '' || trim(wp_strip_all_tags($content)) === '' || $focusKeyphrase === '' || $seoTitle === '' || $metaDescription === '') {
        return new WP_Error('bi_missing_fields', 'Title, article content and Yoast SEO fields are required.', ['status' => 422]);
    }

    $status = (string)$request->get_param('status') === 'publish' ? 'publish' : 'draft';
    if ($status === 'publish' && !current_user_can('publish_posts')) {
        return new WP_Error('bi_cannot_publish', 'This WordPress user cannot publish posts.', ['status' => 403]);
    }

    $content = preg_replace_callback('/<\/?h1\b[^>]*>/i', static function (array $match): string {
        return str_starts_with(strtolower($match[0]), '</') ? '</h2>' : '<h2>';
    }, $content) ?: $content;
    $content = wp_kses_post($content);

    $post = [
        'post_type' => 'post',
        'post_status' => $status,
        'post_title' => $title,
        'post_name' => sanitize_title((string)$request->get_param('slug')),
        'post_excerpt' => sanitize_textarea_field((string)$request->get_param('excerpt')),
        'post_content' => $content,
        'post_author' => get_current_user_id(),
    ];

    $categoryId = absint($request->get_param('category_id'));
    if ($categoryId > 0 && term_exists($categoryId, 'category')) {
        $post['post_category'] = [$categoryId];
    } else {
        $categoryName = sanitize_text_field((string)$request->get_param('category_name'));
        if ($categoryName === '') $categoryName = 'Buying Guides';
        $term = term_exists($categoryName, 'category');
        if (!$term) $term = wp_insert_term($categoryName, 'category');
        if (is_wp_error($term)) return $term;
        $resolvedCategoryId = (int)(is_array($term) ? $term['term_id'] : $term);
        if ($resolvedCategoryId > 0) $post['post_category'] = [$resolvedCategoryId];
    }

    $postId = wp_insert_post(wp_slash($post), true);
    if (is_wp_error($postId)) return $postId;

    update_post_meta($postId, '_yoast_wpseo_focuskw', $focusKeyphrase);
    update_post_meta($postId, '_yoast_wpseo_title', $seoTitle);
    update_post_meta($postId, '_yoast_wpseo_metadesc', $metaDescription);
    update_post_meta($postId, '_bi_automation_generated', '1');
    $sourceProductId = absint($request->get_param('source_product_id'));
    if ($sourceProductId > 0 && get_post_type($sourceProductId) === 'product') update_post_meta($postId, '_bi_source_product_id', $sourceProductId);

    $sources = portfolio_blog_connector_clean_sources((array)$request->get_param('sources'));
    if ($sources !== []) update_post_meta($postId, '_bi_research_sources', wp_json_encode($sources, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $tagIds = [];
    foreach (array_slice((array)$request->get_param('tags'), 0, 8) as $tag) {
        $name = sanitize_text_field((string)$tag);
        if ($name === '') continue;
        $term = term_exists($name, 'post_tag');
        if (!$term) $term = wp_insert_term($name, 'post_tag');
        if (is_wp_error($term)) continue;
        $tagIds[] = (int)(is_array($term) ? $term['term_id'] : $term);
    }
    if ($tagIds !== []) wp_set_post_terms($postId, array_values(array_unique($tagIds)), 'post_tag', false);

    $featuredMediaId = absint($request->get_param('featured_media_id'));
    if ($featuredMediaId > 0 && wp_attachment_is_image($featuredMediaId)) {
        set_post_thumbnail($postId, $featuredMediaId);
    }

    // Trigger normal save hooks once more after Yoast fields are present.
    wp_update_post(['ID' => $postId]);
    clean_post_cache($postId);

    return new WP_REST_Response([
        'id' => $postId,
        'status' => get_post_status($postId),
        'link' => get_permalink($postId),
        'edit_link' => get_edit_post_link($postId, 'raw'),
        'category_id' => (int)($post['post_category'][0] ?? 0),
        'yoast' => [
            'focus_keyphrase' => $focusKeyphrase,
            'seo_title' => $seoTitle,
            'meta_description' => $metaDescription,
        ],
    ], 201);
}

/** @return array<int,array{name:string,url:string}> */
function portfolio_blog_connector_clean_sources(array $sources): array
{
    $clean = [];
    foreach (array_slice($sources, 0, 15) as $source) {
        if (!is_array($source)) continue;
        $url = esc_url_raw((string)($source['url'] ?? ''), ['https']);
        if ($url === '' || !str_starts_with(strtolower($url), 'https://')) continue;
        $clean[] = [
            'name' => sanitize_text_field((string)($source['name'] ?? wp_parse_url($url, PHP_URL_HOST))),
            'url' => $url,
        ];
    }
    return $clean;
}

add_action('admin_notices', static function (): void {
    if (!current_user_can('manage_options') || !isset($_GET['activate'])) return;
    echo '<div class="notice notice-success is-dismissible"><p><strong>Blog Automation Connector activated.</strong> Create a dedicated WordPress Application Password and add this website in Blog Automation → Websites.</p></div>';
});

add_action('wp_head', static function (): void {
    if (!is_singular('post')) return;
    $postId = get_queried_object_id();
    if ($postId < 1 || get_post_meta($postId, '_bi_automation_generated', true) !== '1') return;
    echo '<style id="bi-blog-product-image-size">body.single-post article .wp-post-image,body.single-post article figure.product-image img{display:block;width:100%;max-width:768px!important;max-height:508px!important;height:auto!important;object-fit:contain;margin-left:auto;margin-right:auto}</style>';
});
