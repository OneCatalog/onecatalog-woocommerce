<?php
/**
 * Поле «Коды поставщиков» в карточке товара (вкладка «Запасы»).
 *
 * Плоская мета _onecatalog_supplier_code (searchable, по одному значению на код) —
 * ключ для сопоставления unknown-товаров фида с существующим товаром. Вебмастер может
 * задать коды вручную, чтобы unknown-позиции обновляли нужный товар. Для известных
 * (known) товаров коды поддерживаются синком автоматически.
 */

namespace OneCatalog\Import;

if (! defined('ABSPATH')) {
    exit;
}

final class Supplier_Codes_Field
{
    public static function init(): void
    {
        add_action('woocommerce_product_options_inventory_product_data', [self::class, 'render']);
        add_action('woocommerce_process_product_meta', [self::class, 'save']);
    }

    public static function render(): void
    {
        global $post;
        if (! $post) {
            return;
        }
        $product_id = (int) $post->ID;

        $flat  = (array) get_post_meta($product_id, PriceStockSync::META_SUPPLIER_CODE); // [code, …]
        $value = implode(', ', array_filter(array_map('strval', $flat)));

        echo '<div class="options_group">';
        woocommerce_wp_textarea_input([
            'id'          => 'onecatalog_supplier_codes_manual',
            'value'       => $value,
            'label'       => __('Supplier codes', 'onecatalog-import'),
            'desc_tip'    => true,
            'description' => __('Codes used to match this product against the B2B feed (comma or newline separated). For known products they are maintained automatically by the price/stock sync.', 'onecatalog-import'),
        ]);

        // Что записал синк (с привязкой к поставщику) — только для информации.
        $synced = get_post_meta($product_id, PriceStockSync::META_SUPPLIER_CODES, true);
        if (is_array($synced) && $synced) {
            $parts = [];
            foreach ($synced as $c) {
                $code = (string) ($c['code'] ?? '');
                $sid  = (int) ($c['supplier_id'] ?? 0);
                if ('' !== $code) {
                    $parts[] = $code . ($sid ? ' (#' . $sid . ')' : '');
                }
            }
            if ($parts) {
                echo '<p class="form-field"><span class="description">'
                    . esc_html__('Recorded by sync:', 'onecatalog-import') . ' '
                    . esc_html(implode(', ', $parts)) . '</span></p>';
            }
        }

        $synced_at = (int) get_post_meta($product_id, PriceStockSync::META_SYNCED_AT, true);
        if ($synced_at) {
            echo '<p class="form-field"><span class="description">'
                . esc_html__('Last price/stock sync:', 'onecatalog-import') . ' '
                . esc_html(date_i18n('Y-m-d H:i', $synced_at)) . '</span></p>';
        }
        echo '</div>';
    }

    public static function save(int $post_id): void
    {
        // Только при ручном сохранении формы товара (программный save() не шлёт это поле).
        if (! isset($_POST['onecatalog_supplier_codes_manual'])) {
            return;
        }
        $raw   = sanitize_textarea_field(wp_unslash((string) $_POST['onecatalog_supplier_codes_manual']));
        $codes = array_values(array_unique(array_filter(array_map(
            'trim',
            preg_split('/[\r\n,;]+/', $raw) ?: []
        ))));

        delete_post_meta($post_id, PriceStockSync::META_SUPPLIER_CODE);
        foreach ($codes as $code) {
            add_post_meta($post_id, PriceStockSync::META_SUPPLIER_CODE, $code);
        }
    }
}
