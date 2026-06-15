<?php
/**
 * Админ-UI: кнопка «OneCatalog(Товары)» на списке товаров (после «Экспорт»)
 * с модалкой выбора и фоновым импортом; поле «Артикул OneCatalog» в карточке
 * товара (под родным SKU, вкладка «Запасы»).
 */

namespace OneCatalog\Import;

if (! defined('ABSPATH')) {
    exit;
}

final class AdminUI
{
    public const PICKER_BASE = 'https://tools.onecatalog.net';

    public static function init(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_products_page_assets']);
        add_action('woocommerce_product_options_sku', [self::class, 'render_public_id_field']);
        add_action('woocommerce_admin_process_product_object', [self::class, 'save_public_id_field']);
    }

    /** База виджета выбора товаров. */
    public static function picker_base(): string
    {
        /** Фильтр: origin виджета OneCatalog Picker. */
        return (string) apply_filters('onecatalog_picker_base', self::PICKER_BASE);
    }

    public static function enqueue_products_page_assets(string $hook_suffix): void
    {
        if ('edit.php' !== $hook_suffix || ! current_user_can('manage_woocommerce')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (! $screen || 'edit-product' !== $screen->id) {
            return;
        }

        wp_enqueue_script(
            'onecatalog-picker-loader',
            plugins_url('assets/picker-loader.js', ONECATALOG_IMPORT_FILE),
            [],
            ONECATALOG_IMPORT_VERSION,
            true
        );
        wp_enqueue_script(
            'onecatalog-admin-import',
            plugins_url('assets/admin-import.js', ONECATALOG_IMPORT_FILE),
            ['onecatalog-picker-loader'],
            ONECATALOG_IMPORT_VERSION,
            true
        );
        wp_localize_script('onecatalog-admin-import', 'OneCatalogImport', [
            'restImport' => esc_url_raw(rest_url(Rest::NS . '/import')),
            'restStatus' => esc_url_raw(rest_url(Rest::NS . '/import-status')),
            'nonce'      => wp_create_nonce('wp_rest'),
            'token'      => Api::token() ?: 'test_token',
            'origin'     => self::picker_base(),
            'i18n'       => [
                'buttonText'   => __('OneCatalog (Products)', 'onecatalog-import'),
                'loadingText'  => __('Loading OneCatalog…', 'onecatalog-import'),
                /* translators: %1$s: number of products */
                'queueing'     => __('OneCatalog: queueing %1$s product(s)…', 'onecatalog-import'),
                /* translators: 1: products, 2: batches, 3: step */
                'queued'       => __('OneCatalog: %1$s product(s) queued, %2$s batch(es) of %3$s. Running in background…', 'onecatalog-import'),
                /* translators: 1: batches left, 2: total batches */
                'progress'     => __('OneCatalog: running in background — %1$s of %2$s batch(es) left…', 'onecatalog-import'),
                /* translators: 1: imported, 2: total */
                'done'         => __('OneCatalog: imported %1$s of %2$s.', 'onecatalog-import'),
                'errorsLabel'  => __('Errors:', 'onecatalog-import'),
                'refresh'      => __('Refresh list', 'onecatalog-import'),
                'errorPrefix'  => __('OneCatalog: error — ', 'onecatalog-import'),
                'networkError' => __('OneCatalog: network error — ', 'onecatalog-import'),
            ],
        ]);
    }

    /** Поле «Артикул OneCatalog» (public_id) под родным SKU. */
    public static function render_public_id_field(): void
    {
        woocommerce_wp_text_input([
            'id'          => ProductImporter::META_PUBLIC_ID,
            'label'       => __('OneCatalog SKU', 'onecatalog-import'),
            'desc_tip'    => true,
            'description' => __('OneCatalog product identifier (public_id). Filled by the import and used to update the product on re-import.', 'onecatalog-import'),
        ]);
    }

    public static function save_public_id_field($product): void
    {
        if (isset($_POST[ProductImporter::META_PUBLIC_ID])) {
            $product->update_meta_data(
                ProductImporter::META_PUBLIC_ID,
                sanitize_text_field(wp_unslash($_POST[ProductImporter::META_PUBLIC_ID]))
            );
        }
    }
}
