<?php
/**
 * Колонка «OneCatalog» в списке товаров (wp-admin → Товары).
 *
 * - Есть public_id: показывает OC.ID (клик — копирование в буфер) + мелкая ссылка
 *   «Открыть» (товар на OneCatalog, URL фильтруемый).
 * - Пусто: ссылка «+ внести код» → инлайн-поле ввода → сохранение через REST в мету
 *   _onecatalog_public_id (по нему работает идемпотентность импорта).
 */

namespace OneCatalog\Import;

use WP_REST_Request;
use WP_REST_Response;

if (! defined('ABSPATH')) {
    exit;
}

final class Admin_Product_Column
{
    public const COLUMN = 'onecatalog';

    public static function init(): void
    {
        add_filter('manage_product_posts_columns', [self::class, 'add_column']);
        add_action('manage_product_posts_custom_column', [self::class, 'render_column'], 10, 2);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    /** URL «Открыть» для товара (фильтруемый шаблон с {id}). */
    public static function open_url(string $public_id): string
    {
        /** Фильтр: шаблон URL «Открыть» для товара OneCatalog ({id} — public_id). */
        $tpl = (string) apply_filters(
            'onecatalog_admin_open_url_template',
            'https://api.onecatalog.net/wiki/v1/products/{id}/'
        );
        return str_replace('{id}', rawurlencode($public_id), $tpl);
    }

    /** Добавить колонку после SKU (или в конец). */
    public static function add_column(array $columns): array
    {
        $out = [];
        foreach ($columns as $key => $label) {
            $out[$key] = $label;
            if ('sku' === $key) {
                $out[self::COLUMN] = __('OneCatalog', 'onecatalog-import');
            }
        }
        if (! isset($out[self::COLUMN])) {
            $out[self::COLUMN] = __('OneCatalog', 'onecatalog-import');
        }
        return $out;
    }

    public static function render_column(string $column, int $post_id): void
    {
        if (self::COLUMN !== $column) {
            return;
        }
        $public_id = (string) get_post_meta($post_id, ProductImporter::META_PUBLIC_ID, true);

        echo '<div class="oc-col" data-product="' . (int) $post_id . '">';
        if ('' !== $public_id) {
            self::render_value($public_id);
        } else {
            self::render_empty();
        }
        echo '</div>';
    }

    /** Разметка значения (id + «Открыть») — переиспользуется и в JS после сохранения. */
    private static function render_value(string $public_id): void
    {
        ?>
        <span class="oc-id" role="button" tabindex="0"
              title="<?php esc_attr_e('Click to copy', 'onecatalog-import'); ?>"
              data-id="<?php echo esc_attr($public_id); ?>"><?php echo esc_html($public_id); ?></span>
        <span class="oc-copied" hidden><?php esc_html_e('Copied', 'onecatalog-import'); ?></span>
        <div class="oc-actions">
            <a class="oc-open" href="<?php echo esc_url(self::open_url($public_id)); ?>" target="_blank" rel="noopener">
                <?php esc_html_e('Open', 'onecatalog-import'); ?>
            </a>
        </div>
        <?php
    }

    private static function render_empty(): void
    {
        ?>
        <a href="#" class="oc-add"><?php esc_html_e('+ enter code', 'onecatalog-import'); ?></a>
        <span class="oc-edit" hidden>
            <input type="text" class="oc-input" placeholder="OC.IND.1" size="12" autocomplete="off">
            <button type="button" class="button button-small oc-save">OK</button>
        </span>
        <?php
    }

    public static function enqueue(string $hook_suffix): void
    {
        if ('edit.php' !== $hook_suffix || ! current_user_can('manage_woocommerce')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (! $screen || 'edit-product' !== $screen->id) {
            return;
        }

        wp_enqueue_script(
            'onecatalog-admin-column',
            plugins_url('assets/admin-column.js', ONECATALOG_IMPORT_FILE),
            [],
            ONECATALOG_IMPORT_VERSION,
            true
        );
        wp_localize_script('onecatalog-admin-column', 'OneCatalogColumn', [
            'restSet'     => esc_url_raw(rest_url(Rest::NS . '/admin/set-public-id')),
            'nonce'       => wp_create_nonce('wp_rest'),
            'openTpl'     => self::open_url('__ID__'),
            'i18n'        => [
                'copy'   => __('Click to copy', 'onecatalog-import'),
                'copied' => __('Copied', 'onecatalog-import'),
                'open'   => __('Open', 'onecatalog-import'),
                'error'  => __('Save error', 'onecatalog-import'),
            ],
        ]);

        wp_register_style('onecatalog-admin-column', false, [], ONECATALOG_IMPORT_VERSION);
        wp_enqueue_style('onecatalog-admin-column');
        wp_add_inline_style('onecatalog-admin-column', '
            .column-onecatalog{width:140px}
            .oc-col .oc-id{cursor:pointer;font-family:monospace;border-bottom:1px dashed #2271b1;color:#2271b1}
            .oc-col .oc-copied{color:#1a7f37;margin-left:6px;font-size:11px}
            .oc-col .oc-actions{margin-top:2px}
            .oc-col .oc-open{font-size:11px;text-decoration:none}
            .oc-col .oc-input{font-family:monospace}
        ');
    }

    // --- REST ---

    public static function register_routes(): void
    {
        register_rest_route(Rest::NS, '/admin/set-public-id', [
            'methods'             => 'POST',
            'permission_callback' => static fn () => current_user_can('manage_woocommerce'),
            'args'                => [
                'product_id' => ['required' => true, 'type' => 'integer'],
                'public_id'  => ['required' => true, 'type' => 'string'],
            ],
            'callback'            => [self::class, 'rest_set'],
        ]);
    }

    public static function rest_set(WP_REST_Request $request): WP_REST_Response
    {
        $product_id = (int) $request->get_param('product_id');
        $public_id  = sanitize_text_field((string) $request->get_param('public_id'));

        if ($product_id <= 0 || 'product' !== get_post_type($product_id)) {
            return new WP_REST_Response(['error' => 'bad_product'], 400);
        }
        if (! current_user_can('edit_post', $product_id)) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }
        if ('' === $public_id) {
            return new WP_REST_Response(['error' => 'empty'], 400);
        }

        update_post_meta($product_id, ProductImporter::META_PUBLIC_ID, $public_id);

        return new WP_REST_Response([
            'ok'        => true,
            'public_id' => $public_id,
            'open_url'  => self::open_url($public_id),
        ], 200);
    }
}
