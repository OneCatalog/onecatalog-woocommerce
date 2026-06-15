<?php
/**
 * Коллекции OneCatalog → WordPress.
 *
 * Цель импорта настраивается (страница настроек):
 *   - можно вовсе отключить импорт коллекций;
 *   - хранить коллекцию как POST TYPE (CPT) или как ТАКСОНОМИЮ (терм),
 *     с выбором конкретного post type / таксономии.
 *
 * Поля коллекции (из payload товара OneCatalog) сохраняются как мета:
 *   _onecatalog_collection_id       — ID коллекции в OneCatalog
 *   _onecatalog_link_3d             — ссылка на 3D-тур
 *   _onecatalog_link_official_site  — ссылка на официальный сайт
 *   + обложка: featured image (post) или _onecatalog_cover_id/url (term).
 */

namespace OneCatalog\Import;

use WC_Product_Attribute;

if (! defined('ABSPATH')) {
    exit;
}

final class CollectionImporter
{
    public const META_OC_ID        = '_onecatalog_collection_id';
    public const META_LINK_3D      = '_onecatalog_link_3d';
    public const META_LINK_SITE    = '_onecatalog_link_official_site';
    public const META_COVER_ID     = '_onecatalog_cover_id';
    public const META_COVER_URL    = '_onecatalog_cover_url';
    public const META_PRODUCT_REL  = '_onecatalog_collection'; // связь товар→коллекция (post type)

    /**
     * Создать/обновить коллекцию из payload и связать с товаром.
     * Возвращает ['id','type','title',(post_type|taxonomy)] либо [].
     */
    public static function ensure(array $c, int $product_id = 0): array
    {
        if (! Settings::collections_enabled()) {
            return [];
        }
        $title    = trim((string) ($c['menutitle'] ?? ''));
        $api_slug = (string) ($c['slug'] ?? '');
        $slug     = sanitize_title(Taxonomies::translit($api_slug ?: $title));
        if (! $slug || '' === $title) {
            return [];
        }

        $mode = Settings::collection_object_type();

        if ('attribute' === $mode) {
            // Коллекция как товарный атрибут pa_*: терм + видимый атрибут товара,
            // поля коллекции (id/ссылки/обложка) — как term-meta (как в режиме taxonomy).
            $attr_id  = Settings::collection_attribute_id();
            $taxonomy = $attr_id ? (string) wc_attribute_taxonomy_name_by_id($attr_id) : '';
            if (! $attr_id || '' === $taxonomy) {
                return []; // атрибут коллекции не выбран/не существует
            }
            if (! taxonomy_exists($taxonomy)) {
                register_taxonomy($taxonomy, 'product', ['hierarchical' => false, 'query_var' => true, 'show_ui' => false]);
            }
            $term_id = Taxonomies::term($taxonomy, $title);
            if (! $term_id) {
                return [];
            }
            if ($product_id) {
                self::attach_product_attribute($product_id, $attr_id, $taxonomy, $term_id);
            }
            self::store_fields($term_id, $c, 'term');
            $result = ['id' => $term_id, 'type' => 'attribute', 'taxonomy' => $taxonomy, 'title' => $title];
        } elseif ('taxonomy' === $mode) {
            $taxonomy = Settings::collection_taxonomy();
            if ('' === $taxonomy) {
                return []; // таксономия не выбрана в настройках
            }
            $term_id = Taxonomies::term($taxonomy, $title);
            if (! $term_id) {
                return [];
            }
            if ($product_id) {
                wp_set_object_terms($product_id, [$term_id], $taxonomy, true);
            }
            self::store_fields($term_id, $c, 'term');
            $result = ['id' => $term_id, 'type' => 'taxonomy', 'taxonomy' => $taxonomy, 'title' => $title];
        } else {
            $post_id = self::ensure_post($slug, $title);
            if (! $post_id) {
                return [];
            }
            if ($product_id) {
                update_post_meta($product_id, self::META_PRODUCT_REL, (string) $post_id);
            }
            self::store_fields($post_id, $c, 'post');
            $result = ['id' => $post_id, 'type' => 'post_type', 'post_type' => Settings::collection_post_type(), 'title' => $title];
        }

        /** Экшен: коллекция импортирована ($object_id, payload, результат с type/title). */
        do_action('onecatalog_collection_imported', (int) $result['id'], $c, $result);
        return $result;
    }

    /** Терм коллекции + видимый товарный атрибут pa_* (режим attribute). */
    private static function attach_product_attribute(int $product_id, int $attr_id, string $taxonomy, int $term_id): void
    {
        wp_set_object_terms($product_id, [$term_id], $taxonomy, false); // одна коллекция → replace
        $product = wc_get_product($product_id);
        if (! $product) {
            return;
        }
        $attrs = $product->get_attributes();
        $attr  = new WC_Product_Attribute();
        $attr->set_id($attr_id);
        $attr->set_name($taxonomy);
        $attr->set_options([$term_id]);
        $attr->set_visible(true);
        $attr->set_variation(false);
        $attrs[$taxonomy] = $attr;
        $product->set_attributes($attrs);
        $product->save();
    }

    /** Пост коллекции в выбранном post type → ID. */
    private static function ensure_post(string $slug, string $title): int
    {
        $post_type = Settings::collection_post_type();
        $existing  = get_page_by_path($slug, OBJECT, $post_type);
        if ($existing) {
            return (int) $existing->ID;
        }
        $post_id = wp_insert_post([
            'post_type'   => $post_type,
            'post_status' => 'publish',
            'post_title'  => $title,
            'post_name'   => $slug,
        ]);
        return is_wp_error($post_id) ? 0 : (int) $post_id;
    }

    /** Сохранить проанализированные поля коллекции (post meta или term meta). */
    private static function store_fields(int $object_id, array $c, string $meta_type): void
    {
        $set = 'term' === $meta_type ? 'update_term_meta' : 'update_post_meta';

        if (! empty($c['id'])) {
            $set($object_id, self::META_OC_ID, (int) $c['id']);
        }
        if (! empty($c['link_3d'])) {
            $set($object_id, self::META_LINK_3D, esc_url_raw((string) $c['link_3d']));
        }
        if (! empty($c['link_official_site'])) {
            $set($object_id, self::META_LINK_SITE, esc_url_raw((string) $c['link_official_site']));
        }

        if (is_array($c['images_urls'] ?? null)) {
            if ('term' === $meta_type) {
                self::term_cover($object_id, $c['images_urls']);
            } else {
                Media::cover($object_id, $c['images_urls']); // featured image + трекинг качества
            }
        }
    }

    /** Обложка для термина: URL в мету + однократный sideload в медиатеку. */
    private static function term_cover(int $term_id, array $urls): void
    {
        $pick = Media::pick_size_info($urls);
        if ('' === $pick['url']) {
            return;
        }
        update_term_meta($term_id, self::META_COVER_URL, esc_url_raw($pick['url']));
        if (get_term_meta($term_id, self::META_COVER_ID, true)) {
            return;
        }
        $att = Media::sideload(0, $pick['url'], 'collection-' . $term_id);
        if ($att) {
            update_term_meta($term_id, self::META_COVER_ID, $att);
        }
    }
}
