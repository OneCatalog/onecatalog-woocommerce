<?php
/**
 * Бренды (производители) OneCatalog → WordPress.
 *
 * Цель импорта настраивается (страница настроек), по образцу коллекций:
 *   - можно отключить назначение бренда;
 *   - хранить бренд как POST TYPE (CPT) или как ТАКСОНОМИЮ (терм),
 *     с выбором конкретного post type / таксономии.
 *     По умолчанию — нативная таксономия WooCommerce `product_brand`.
 *
 * Поля бренда (из payload товара OneCatalog) сохраняются как мета:
 *   _onecatalog_brand_id  — ID бренда в OneCatalog
 *   + логотип/обложка: featured image (post) или _onecatalog_brand_cover_id/url (term).
 */

namespace OneCatalog\Import;

use WC_Product_Attribute;

if (! defined('ABSPATH')) {
    exit;
}

final class BrandImporter
{
    public const META_OC_ID       = '_onecatalog_brand_id';
    public const META_PRODUCT_REL = '_onecatalog_brand';            // связь товар→бренд (post type)
    public const META_COVER_ID    = '_onecatalog_brand_cover_id';
    public const META_COVER_URL   = '_onecatalog_brand_cover_url';

    /**
     * Создать/обновить бренд из payload и связать с товаром.
     * Возвращает ['id','type','title', …] либо [].
     */
    public static function ensure(array $b, int $product_id = 0): array
    {
        if (! Settings::brands_enabled()) {
            return [];
        }
        $title    = trim((string) ($b['menutitle'] ?? ''));
        $api_slug = (string) ($b['slug'] ?? '');
        $slug     = sanitize_title(Taxonomies::translit($api_slug ?: $title));
        if (! $slug || '' === $title) {
            return [];
        }

        $mode = Settings::brand_object_type();

        if ('attribute' === $mode) {
            // Старый способ WooCommerce — бренд как товарный атрибут pa_* («в характеристиках»).
            $attr_id  = Settings::brand_attribute_id();
            $taxonomy = $attr_id ? (string) wc_attribute_taxonomy_name_by_id($attr_id) : '';
            if (! $attr_id || '' === $taxonomy) {
                return []; // атрибут бренда не выбран/не существует
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
            self::store_fields($term_id, $b, 'term');
            $result = ['id' => $term_id, 'type' => 'attribute', 'taxonomy' => $taxonomy, 'title' => $title];
        } elseif ('post_type' === $mode) {
            $post_type = Settings::brand_post_type();
            if ('' === $post_type) {
                return []; // пост-тип не выбран в настройках
            }
            $post_id = self::ensure_post($post_type, $slug, $title);
            if (! $post_id) {
                return [];
            }
            if ($product_id) {
                update_post_meta($product_id, self::META_PRODUCT_REL, (string) $post_id);
            }
            self::store_fields($post_id, $b, 'post');
            $result = ['id' => $post_id, 'type' => 'post_type', 'post_type' => $post_type, 'title' => $title];
        } else {
            $taxonomy = Settings::brand_taxonomy();
            if ('' === $taxonomy) {
                return []; // таксономия не выбрана
            }
            $term_id = Taxonomies::term($taxonomy, $title);
            if (! $term_id) {
                return [];
            }
            if ($product_id) {
                wp_set_object_terms($product_id, [$term_id], $taxonomy, false); // один бренд → replace
            }
            self::store_fields($term_id, $b, 'term');
            $result = ['id' => $term_id, 'type' => 'taxonomy', 'taxonomy' => $taxonomy, 'title' => $title];
        }

        /** Экшен: бренд импортирован ($object_id, payload, результат с type/title). */
        do_action('onecatalog_brand_imported', (int) $result['id'], $b, $result);
        return $result;
    }

    /**
     * Старый способ: назначить терм бренда товару И прикрепить как видимый товарный
     * атрибут pa_* (чтобы бренд отображался во вкладке «Атрибуты» и фильтровался).
     */
    private static function attach_product_attribute(int $product_id, int $attr_id, string $taxonomy, int $term_id): void
    {
        wp_set_object_terms($product_id, [$term_id], $taxonomy, false); // один бренд → replace
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

    /** Пост бренда в выбранном post type → ID. */
    private static function ensure_post(string $post_type, string $slug, string $title): int
    {
        $existing = get_page_by_path($slug, OBJECT, $post_type);
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

    /** Сохранить поля бренда (post meta или term meta). */
    private static function store_fields(int $object_id, array $b, string $meta_type): void
    {
        $set = 'term' === $meta_type ? 'update_term_meta' : 'update_post_meta';

        if (! empty($b['id'])) {
            $set($object_id, self::META_OC_ID, (int) $b['id']);
        }

        if (is_array($b['images_urls'] ?? null)) {
            if ('term' === $meta_type) {
                self::term_cover($object_id, $b['images_urls']);
            } else {
                Media::cover($object_id, $b['images_urls']); // featured image + трекинг качества
            }
        }
    }

    /** Логотип/обложка для термина: URL в мету + однократный sideload в медиатеку. */
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
        $att = Media::sideload(0, $pick['url'], 'brand-' . $term_id);
        if ($att) {
            update_term_meta($term_id, self::META_COVER_ID, $att);
        }
    }
}
