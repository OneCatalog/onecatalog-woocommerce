<?php
/**
 * Импорт товара: загрузка payload по идентификатору (public_id/slug/числовой id)
 * и создание/обновление WC-товара со всеми зависимостями.
 *
 * Идемпотентность — по public_id OneCatalog (мета _onecatalog_public_id).
 * Родное поле SKU — только артикул производителя; public_id в SKU не пишется.
 */

namespace OneCatalog\Import;

use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Data_Exception;

if (! defined('ABSPATH')) {
    exit;
}

final class ProductImporter
{
    public const META_PUBLIC_ID = '_onecatalog_public_id';

    /** Товар по public_id OneCatalog: мета, легаси-фолбэк — SKU == public_id. */
    public static function find_by_public_id(string $public_id): int
    {
        $found = get_posts([
            'post_type'   => 'product',
            'post_status' => 'any',
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_key'    => self::META_PUBLIC_ID,
            'meta_value'  => $public_id,
        ]);
        if ($found) {
            return (int) $found[0];
        }
        return (int) wc_get_product_id_by_sku($public_id);
    }

    /**
     * Payload товара из API по идентификатору: public_id или slug;
     * числовой id пикера дополнительно пробуется как OC.*.N не угадывается —
     * запрашивается как есть (валиден для инстансов с числовой адресацией).
     */
    public static function fetch(string $identifier): ?array
    {
        $idf = trim($identifier);
        if ('' === $idf) {
            return null;
        }
        $response = Api::get(Api::base() . '/products/' . rawurlencode($idf) . '/');
        if (! empty($response['success']) && is_array($response['data'] ?? null)) {
            return $response['data'];
        }
        return null;
    }

    /** Полный цикл: идентификатор → API → импорт. */
    public static function import_by_identifier(string $identifier): array
    {
        $payload = self::fetch($identifier);
        if (null === $payload) {
            return ['status' => 'error', 'identifier' => $identifier, 'error' => __('product not found in the OneCatalog API', 'onecatalog-import')];
        }
        $report               = self::import($payload);
        $report['identifier'] = $identifier;
        return $report;
    }

    /** Импорт одного товара из payload Wiki API → отчёт. */
    public static function import(array $p): array
    {
        /** Фильтр: payload товара перед импортом (можно дополнить/изменить). */
        $p = (array) apply_filters('onecatalog_product_payload', $p);

        $public_id = (string) ($p['public_id'] ?? '');
        if (! $public_id) {
            return ['status' => 'skip', 'error' => 'нет public_id'];
        }

        $existing = self::find_by_public_id($public_id);
        $product  = $existing ? wc_get_product($existing) : new WC_Product_Simple();

        /** Экшен: перед импортом товара (payload, ID существующего товара или 0). */
        do_action('onecatalog_before_import_product', $p, (int) $existing);

        $name = (string) ($p['menutitle'] ?? $public_id);
        $product->set_name($name);
        if (! empty($p['slug'])) {
            $product->set_slug(sanitize_title(Taxonomies::translit((string) $p['slug'])) ?: sanitize_title($name));
        }

        // Родное поле SKU — ТОЛЬКО артикул производителя (public_id храним отдельно).
        $article = trim((string) ($p['article'] ?? ''));

        /** Фильтр: SKU товара (пусто — не задавать). */
        $article = (string) apply_filters('onecatalog_product_sku', $article, $p, (int) $existing);
        if ('' !== $article) {
            $duplicate = wc_get_product_id_by_sku($article);
            if (! $duplicate || $duplicate === (int) $existing) {
                try {
                    $product->set_sku($article);
                } catch (WC_Data_Exception $e) {
                    // коллизия/невалидный артикул — SKU не меняем
                }
            }
        } elseif ((string) $product->get_sku('edit') === $public_id) {
            try {
                $product->set_sku(''); // легаси-миграция: public_id из SKU убирается
            } catch (WC_Data_Exception $e) {
                // не критично
            }
        }

        $brand_name = trim((string) ($p['brand']['menutitle'] ?? ''));
        $brand_line = trim($brand_name . ' · ' . ((string) ($p['country']['menutitle'] ?? '')), " ·");
        $product->set_short_description($brand_line);
        $product->set_description((string) ($p['description_text'] ?? ''));

        // Цена: в Wiki API цен нет. По умолчанию НЕ трогаем — новым товарам цена
        // не задаётся (заполняет вебмастер), при переимпорте сохраняется ручная.
        // Интеграторы могут подставить цену из своего источника фильтром.
        /** Фильтр: цена товара (null/'' — не задавать; так по умолчанию). */
        $price = apply_filters('onecatalog_product_price', null, $p, (int) $existing);
        if (null !== $price && '' !== $price) {
            $product->set_regular_price((string) $price);
        }
        $product->set_catalog_visibility('visible');
        // Статус задаём ТОЛЬКО новым товарам (по настройке плагина); у существующих
        // не трогаем — уважаем ручные изменения статуса в админке при переимпорте.
        if (! $existing) {
            $product->set_status(Settings::product_status());
        }

        // Наличие из API (in_stock). Если поля нет — считаем в наличии.
        $in_stock = ! array_key_exists('in_stock', $p) || ! empty($p['in_stock']);
        $product->set_stock_status($in_stock ? 'instock' : 'outofstock');

        // Габариты/вес (sizes). OneCatalog отдаёт в наименьшей единице: размеры — мм,
        // вес — г. Конвертируем в единицы магазина (которые зависят от настроек/страны).
        // Единицу источника берём из payload (sizes.*_unit, подпись локализована —
        // нормализуется в Units), иначе — наименьшая (mm/g); обе переопределяемы фильтром.
        $sizes    = is_array($p['sizes'] ?? null) ? $p['sizes'] : [];
        $unit_val = static function ($u): string {
            return is_array($u) ? (string) ($u['id'] ?? $u['slug'] ?? $u['label'] ?? '') : (string) ($u ?? '');
        };
        $src_dim = $unit_val($sizes['length_unit'] ?? '');
        $src_dim = Units::is_dimension($src_dim) ? $src_dim : 'mm';
        $src_wt  = $unit_val($sizes['weight_unit'] ?? '');
        $src_wt  = Units::is_weight($src_wt) ? $src_wt : 'g';

        /** Фильтр: единица длины источника (по умолчанию мм). */
        $src_dim = (string) apply_filters('onecatalog_source_dimension_unit', $src_dim, $p);
        /** Фильтр: единица веса источника (по умолчанию г). */
        $src_wt  = (string) apply_filters('onecatalog_source_weight_unit', $src_wt, $p);

        $dim_to = Units::store_dimension_unit();
        $wt_to  = Units::store_weight_unit();

        foreach (['length' => 'set_length', 'width' => 'set_width', 'height' => 'set_height'] as $key => $setter) {
            if (isset($sizes[$key]) && is_numeric($sizes[$key])) {
                $product->{$setter}(wc_format_decimal(Units::dimension((float) $sizes[$key], $src_dim, $dim_to), 4, true));
            }
        }
        if (isset($sizes['weight']) && is_numeric($sizes['weight'])) {
            $product->set_weight(wc_format_decimal(Units::weight((float) $sizes['weight'], $src_wt, $wt_to), 4, true));
        }

        // Зависимость: категории.
        $category_ids = [];
        foreach (($p['categories'] ?? []) as $category) {
            $cid = Taxonomies::category((string) ($category['menutitle'] ?? ''));
            if ($cid) {
                $category_ids[] = $cid;
            }
        }
        if ($category_ids) {
            $product->set_category_ids(array_values(array_unique($category_ids)));
        }

        // Зависимость: характеристики (options → глобальные атрибуты pa_*).
        // Группируем по specification_id (стабильный ключ привязки), сохраняя label для
        // режима «по названию» и значения характеристики.
        $by_spec = [];
        foreach (($p['options'] ?? []) as $option) {
            $label   = trim((string) ($option['specification_label'] ?? ''));
            $spec_id = (int) ($option['specification_id'] ?? 0);
            // boolean-характеристики приходят без specification_option_name (он null),
            // значение — в bool_option (true/false). Берём настроенное в «Характеристиках»
            // значение для true/false (если не задано — '' → характеристика пропускается).
            if (($option['specification_type'] ?? '') === 'boolean') {
                $value = $spec_id > 0 ? Settings::bool_value($spec_id, ! empty($option['bool_option'])) : '';
            } else {
                $value = trim((string) ($option['specification_option_name'] ?? ''));
            }
            if ('' === $label || '' === $value) {
                continue;
            }
            $key = $spec_id > 0 ? 'id:' . $spec_id : 'label:' . $label;
            if (! isset($by_spec[$key])) {
                $by_spec[$key] = ['spec_id' => $spec_id, 'label' => $label, 'values' => []];
            }
            $by_spec[$key]['values'][$value] = true;
        }

        $wc_attributes = [];
        $tax_terms     = [];
        foreach ($by_spec as $group) {
            // Резолв с учётом ручного сопоставления (вкл → привязка по id / пропуск; выкл → по названию).
            $attribute = Taxonomies::resolve_attribute((int) $group['spec_id'], (string) $group['label']);
            if (! $attribute['id']) {
                continue;
            }
            $term_ids = [];
            foreach (array_keys($group['values']) as $value) {
                $term_id = Taxonomies::term($attribute['taxonomy'], (string) $value);
                if ($term_id) {
                    $term_ids[] = $term_id;
                }
            }
            if (! $term_ids) {
                continue;
            }
            $wc_attribute = new WC_Product_Attribute();
            $wc_attribute->set_id($attribute['id']);
            $wc_attribute->set_name($attribute['taxonomy']);
            $wc_attribute->set_options($term_ids);
            $wc_attribute->set_visible(true);
            $wc_attribute->set_variation(false);
            $wc_attributes[]                     = $wc_attribute;
            $tax_terms[$attribute['taxonomy']]   = $term_ids;
        }
        $product->set_attributes($wc_attributes);

        $id = $product->save();

        foreach ($tax_terms as $taxonomy => $term_ids) {
            wp_set_object_terms($id, $term_ids, $taxonomy, false);
        }

        // Артикул OneCatalog — отдельным полем.
        update_post_meta($id, self::META_PUBLIC_ID, $public_id);

        // Зависимость: бренд (пост-тип или таксономия по настройкам; по умолчанию product_brand).
        if (is_array($p['brand'] ?? null)) {
            BrandImporter::ensure($p['brand'], (int) $id);
        }

        // Зависимость: страна происхождения (атрибут или таксономия по настройкам).
        if (is_array($p['country'] ?? null)) {
            CountryImporter::ensure($p['country'], (int) $id);
        }

        // Зависимость: теги → нативная таксономия product_tag (поиск по названию).
        if (Settings::tags_enabled() && is_array($p['tags'] ?? null)) {
            $tag_ids = [];
            foreach ($p['tags'] as $tag) {
                $tag_name = trim((string) (is_array($tag) ? ($tag['title'] ?? '') : ''));
                if ('' === $tag_name) {
                    continue;
                }
                $tid = Taxonomies::term('product_tag', $tag_name);
                if ($tid) {
                    $tag_ids[] = $tid;
                }
            }
            if ($tag_ids) {
                wp_set_object_terms((int) $id, array_values(array_unique($tag_ids)), 'product_tag', false);
            }
        }

        // Обложка (с фиксацией качества и авто-апгрейдом).
        $cover_id = 0;
        if (is_array($p['images_urls'] ?? null)) {
            $cover_id = Media::cover((int) $id, $p['images_urls']);
        }

        // Галерея из files[] (качество каждого файла фиксируется).
        /** Фильтр: файлы галереи перед загрузкой. */
        $gallery_files = (array) apply_filters('onecatalog_gallery_files', (array) ($p['files'] ?? []), (int) $id, $p);
        $gallery_ids   = [];
        if ($gallery_files) {
            $gallery_ids = Media::gallery((int) $id, $gallery_files);
            if ($gallery_ids) {
                update_post_meta($id, '_product_image_gallery', implode(',', array_map('intval', $gallery_ids)));
            }
        }

        // Зависимость: коллекция (первая) — post type или таксономия (по настройкам);
        // связывание товара и сохранение полей делает CollectionImporter.
        $collection_title = null;
        $collection_id    = 0;
        $collection       = $p['collections'][0] ?? null;
        if (is_array($collection)) {
            $res = CollectionImporter::ensure($collection, (int) $id);
            if (! empty($res['id'])) {
                $collection_id    = (int) $res['id'];
                $collection_title = (string) ($res['title'] ?? '');
            }
        }

        $report = [
            'status'        => $existing ? 'updated' : 'created',
            'product_id'    => (int) $id,
            'sku'           => $product->get_sku(),
            'public_id'     => $public_id,
            'name'          => $name,
            'attrs'         => count($wc_attributes),
            'image'         => $cover_id ? 'да' : '—',
            'gallery'       => count($gallery_ids),
            'collection'    => $collection_title,
            'collection_id' => (int) $collection_id,
            'in_stock'      => $in_stock,
        ];

        /** Экшен: товар импортирован ($product_id, payload, отчёт). */
        do_action('onecatalog_product_imported', (int) $id, $p, $report);

        return $report;
    }
}
