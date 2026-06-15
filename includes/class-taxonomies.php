<?php
/**
 * Таксономии импорта: глобальные атрибуты WooCommerce (pa_*), термы,
 * категории товаров, бренды (нативная таксономия product_brand).
 */

namespace OneCatalog\Import;

if (! defined('ABSPATH')) {
    exit;
}

final class Taxonomies
{
    /** Транслитерация кириллицы → латиница (для слагов атрибутов/коллекций). */
    public static function translit(string $s): string
    {
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y',
            'к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f',
            'х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        ];
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, $map);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim((string) $s, '-');
    }

    /**
     * Резолв характеристики OneCatalog → WC-атрибут с учётом ручного сопоставления.
     *
     * Ручное сопоставление ВЫКЛ → поиск/создание по названию (attribute()).
     * Ручное сопоставление ВКЛ (привязка по specification_id):
     *   • есть привязка к существующему атрибуту → используем её;
     *   • привязки нет (или она указывает на удалённый атрибут) → ['id'=>0]
     *     (строгий режим — характеристика пропускается импортёром).
     *
     * @param int    $spec_id specification_id из payload (0 — у характеристики нет id).
     * @param string $label   человекочитаемое имя (для режима «по названию»).
     */
    public static function resolve_attribute(int $spec_id, string $label): array
    {
        if (! Settings::attr_map_enabled()) {
            return self::attribute($label);
        }
        $attr_id = $spec_id > 0 ? Settings::mapped_attribute_id($spec_id) : 0;
        if ($attr_id <= 0) {
            return ['id' => 0, 'taxonomy' => '']; // нет привязки → пропустить
        }
        $taxonomy = function_exists('wc_attribute_taxonomy_name_by_id')
            ? wc_attribute_taxonomy_name_by_id($attr_id)
            : '';
        if (! is_string($taxonomy) || '' === $taxonomy) {
            return ['id' => 0, 'taxonomy' => '']; // привязка на удалённый атрибут → пропустить
        }
        if (! taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, 'product', ['hierarchical' => false, 'query_var' => true, 'show_ui' => false]);
        }
        return ['id' => (int) $attr_id, 'taxonomy' => $taxonomy];
    }

    /** Глобальный WC-атрибут (pa_*) по человекочитаемому label → ['id','taxonomy']. */
    public static function attribute(string $label): array
    {
        static $cache = [];
        $label = trim($label);
        if (isset($cache[$label])) {
            return $cache[$label];
        }

        // 1) Существующий атрибут ищем по НАЗВАНИЮ (label), независимо от схемы слага.
        //    Иначе плодим дубли, когда слаг сделан другим транслитом — напр. «Толщина»:
        //    наш tolschina vs плагин Cyr-to-Lat tolshhina (поиск по слагу не находит).
        $attr_id = 0;
        $slug    = '';
        foreach (wc_get_attribute_taxonomies() as $tax) {
            if (mb_strtolower(trim((string) $tax->attribute_label), 'UTF-8') === mb_strtolower($label, 'UTF-8')) {
                $attr_id = (int) $tax->attribute_id;
                $slug    = (string) $tax->attribute_name;
                break;
            }
        }

        // 2) По названию не нашли — пробуем свой слаг, иначе создаём новый атрибут.
        if (! $attr_id) {
            $slug    = substr(self::translit($label), 0, 24) ?: 'attr';
            $attr_id = wc_attribute_taxonomy_id_by_name($slug);
            if (! $attr_id) {
                $created = wc_create_attribute([
                    'name'         => $label,
                    'slug'         => $slug,
                    'type'         => 'select',
                    'order_by'     => 'menu_order',
                    'has_archives' => false,
                ]);
                $attr_id = is_wp_error($created) ? 0 : (int) $created;
                delete_transient('wc_attribute_taxonomies');
            }
        }

        $taxonomy = wc_attribute_taxonomy_name($slug);
        if (! taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, 'product', ['hierarchical' => false, 'query_var' => true, 'show_ui' => false]);
        }
        return $cache[$label] = ['id' => (int) $attr_id, 'taxonomy' => $taxonomy];
    }

    /** Терм таксономии по имени → term_id (создаётся при отсутствии). */
    public static function term(string $taxonomy, string $name): int
    {
        $term = get_term_by('name', $name, $taxonomy);
        if ($term) {
            return (int) $term->term_id;
        }
        $created = wp_insert_term($name, $taxonomy);
        return is_wp_error($created) ? 0 : (int) $created['term_id'];
    }

    /** Категория товара по имени → term_id. */
    public static function category(string $name): int
    {
        if ('' === trim($name)) {
            return 0;
        }
        return self::term('product_cat', $name);
    }
}
