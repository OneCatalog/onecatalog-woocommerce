<?php
/**
 * Страна происхождения OneCatalog → WordPress.
 *
 * Настраивается (страница настроек): хранить как товарный АТРИБУТ pa_* (по умолчанию —
 * страна чаще всего атрибут) или как ТАКСОНОМИЮ. Имя терма — `menutitle` страны.
 */

namespace OneCatalog\Import;

use WC_Product_Attribute;

if (! defined('ABSPATH')) {
    exit;
}

final class CountryImporter
{
    public const META_OC_ID = '_onecatalog_country_id';

    /**
     * Назначить страну товару. Возвращает ['id','type','taxonomy','title'] либо [].
     */
    public static function ensure(array $c, int $product_id = 0): array
    {
        if (! Settings::country_enabled()) {
            return [];
        }
        $title = trim((string) ($c['menutitle'] ?? ''));
        if ('' === $title) {
            return [];
        }

        if ('taxonomy' === Settings::country_object_type()) {
            $taxonomy = Settings::country_taxonomy();
            if ('' === $taxonomy) {
                return []; // таксономия не выбрана
            }
            $term_id = Taxonomies::term($taxonomy, $title);
            if (! $term_id) {
                return [];
            }
            if ($product_id) {
                wp_set_object_terms($product_id, [$term_id], $taxonomy, false); // одна страна → replace
            }
            $result = ['id' => $term_id, 'type' => 'taxonomy', 'taxonomy' => $taxonomy, 'title' => $title];
        } else {
            // Старый способ — страна как товарный атрибут pa_*.
            $attr_id  = Settings::country_attribute_id();
            $taxonomy = $attr_id ? (string) wc_attribute_taxonomy_name_by_id($attr_id) : '';
            if (! $attr_id || '' === $taxonomy) {
                return []; // атрибут страны не выбран/не существует
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
            $result = ['id' => $term_id, 'type' => 'attribute', 'taxonomy' => $taxonomy, 'title' => $title];
        }

        if ($product_id && ! empty($c['id'])) {
            update_post_meta($product_id, self::META_OC_ID, (int) $c['id']);
        }

        /** Экшен: страна назначена ($object_id, payload страны, результат). */
        do_action('onecatalog_country_imported', (int) $result['id'], $c, $result);
        return $result;
    }

    /** Терм страны + видимый товарный атрибут pa_*. */
    private static function attach_product_attribute(int $product_id, int $attr_id, string $taxonomy, int $term_id): void
    {
        wp_set_object_terms($product_id, [$term_id], $taxonomy, false); // одна страна → replace
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
}
