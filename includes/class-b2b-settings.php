<?php
/**
 * Настройки синхронизации цен/остатков (B2B). Две подстраницы под меню «OneCatalog»:
 *   - «Подключение B2B» (slug onecatalog-b2b): url_key + private_key + «Проверить и загрузить»;
 *   - «Цены и остатки» (slug onecatalog-pricestock): стратегия цены, приоритеты
 *     регионов/поставщиков (drag-and-drop), склады, тогглы, ручной запуск.
 */

namespace OneCatalog\Import;

if (! defined('ABSPATH')) {
    exit;
}

final class B2B_Settings
{
    public const OPTION_STRATEGY      = 'onecatalog_b2b_price_strategy';   // priority | min | supplier
    public const OPTION_SUPPLIER_PRIO = 'onecatalog_b2b_supplier_priority'; // [supplier_id, …] (порядок)
    public const OPTION_REGION_PRIO   = 'onecatalog_b2b_region_priority';   // [region_id, …] (порядок)
    public const OPTION_SUPPLIER_FIX  = 'onecatalog_b2b_supplier_fixed';    // supplier_id (для strategy=supplier)
    public const OPTION_WAREHOUSES    = 'onecatalog_b2b_warehouses';        // [warehouse_id, …] ([] = все)
    public const OPTION_MANAGE_STOCK  = 'onecatalog_b2b_manage_stock';      // 1|0
    public const OPTION_DECIMAL_STOCK = 'onecatalog_b2b_decimal_stock';     // 1|0
    public const OPTION_PROMO_AS_SALE = 'onecatalog_b2b_promo_as_sale';     // 1|0
    public const OPTION_ZERO_ABSENT   = 'onecatalog_b2b_zero_absent';       // 1|0
    public const OPTION_KNOWN_MISSING = 'onecatalog_b2b_known_missing';     // import | skip
    public const OPTION_UNKNOWN_MODE  = 'onecatalog_b2b_unknown_mode';      // skip | import
    public const OPTION_PAGE_SIZE     = 'onecatalog_b2b_page_size';         // строк за страницу фида
    public const OPTION_CATALOG_META  = 'onecatalog_b2b_catalog_meta';      // {regions,warehouses,suppliers}

    public const PAGE_MIN = 50;
    public const PAGE_MAX = 500;
    public const PAGE_DEFAULT = 200;

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu'], 20);
    }

    // --- геттеры ---

    public static function price_strategy(): string
    {
        $s = (string) get_option(self::OPTION_STRATEGY, 'priority');
        return in_array($s, ['priority', 'min', 'supplier'], true) ? $s : 'priority';
    }

    /** @return int[] упорядоченный приоритет поставщиков */
    public static function supplier_priority(): array
    {
        return self::int_list(get_option(self::OPTION_SUPPLIER_PRIO, []));
    }

    /** @return int[] упорядоченный приоритет регионов */
    public static function region_priority(): array
    {
        return self::int_list(get_option(self::OPTION_REGION_PRIO, []));
    }

    public static function supplier_fixed(): int
    {
        return (int) get_option(self::OPTION_SUPPLIER_FIX, 0);
    }

    /** @return int[] выбранные склады ([] = считать все) */
    public static function warehouses(): array
    {
        return self::int_list(get_option(self::OPTION_WAREHOUSES, []));
    }

    public static function manage_stock(): bool
    {
        return get_option(self::OPTION_MANAGE_STOCK, '1') !== '0';
    }

    public static function decimal_stock(): bool
    {
        return get_option(self::OPTION_DECIMAL_STOCK, '0') === '1';
    }

    public static function promo_as_sale(): bool
    {
        return get_option(self::OPTION_PROMO_AS_SALE, '1') !== '0';
    }

    public static function zero_absent(): bool
    {
        return get_option(self::OPTION_ZERO_ABSENT, '0') === '1';
    }

    /** Поведение для known-товаров, которых нет в магазине: 'import' (через Wiki-очередь) | 'skip'. */
    public static function known_missing(): string
    {
        $v = (string) get_option(self::OPTION_KNOWN_MISSING, 'skip');
        return in_array($v, ['import', 'skip'], true) ? $v : 'skip';
    }

    /** Поведение для unknown-товаров (без wiki): 'skip' | 'import' (создавать и фиксировать код). */
    public static function unknown_mode(): string
    {
        $v = (string) get_option(self::OPTION_UNKNOWN_MODE, 'skip');
        return in_array($v, ['skip', 'import'], true) ? $v : 'skip';
    }

    public static function page_size(): int
    {
        $n = (int) get_option(self::OPTION_PAGE_SIZE, self::PAGE_DEFAULT);
        return max(self::PAGE_MIN, min(self::PAGE_MAX, $n));
    }

    /** Разведанные справочники {regions,warehouses,suppliers} (из «Проверить и загрузить»). */
    public static function catalog_meta(): array
    {
        $m = get_option(self::OPTION_CATALOG_META, []);
        return is_array($m) ? $m : [];
    }

    private static function int_list($raw): array
    {
        $out = [];
        foreach ((array) $raw as $v) {
            $v = (int) $v;
            if ($v > 0 && ! in_array($v, $out, true)) {
                $out[] = $v;
            }
        }
        return $out;
    }

    // --- меню ---

    public static function register_menu(): void
    {
        add_submenu_page(
            'onecatalog',
            __('B2B connection', 'onecatalog-import'),
            __('B2B connection', 'onecatalog-import'),
            'manage_woocommerce',
            'onecatalog-b2b',
            [self::class, 'render_connection']
        );
        add_submenu_page(
            'onecatalog',
            __('Prices & stock', 'onecatalog-import'),
            __('Prices & stock', 'onecatalog-import'),
            'manage_woocommerce',
            'onecatalog-pricestock',
            [self::class, 'render_sync']
        );
    }

    // --- страница «Подключение B2B» ---

    private static function save_connection(): bool
    {
        if (! isset($_POST['onecatalog_b2b_nonce'])
            || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['onecatalog_b2b_nonce'])), 'onecatalog_b2b_connection')
            || ! current_user_can('manage_woocommerce')
        ) {
            return false;
        }
        update_option(B2B_Api::OPTION_URL_KEY, sanitize_text_field(wp_unslash($_POST['onecatalog_b2b_key'] ?? '')), false);
        update_option(B2B_Api::OPTION_PRIVATE_KEY, sanitize_text_field(wp_unslash($_POST['onecatalog_b2b_private_key'] ?? '')), false);
        return true;
    }

    public static function render_connection(): void
    {
        $saved = self::save_connection();

        // «Проверить и загрузить» — тянем справочники из фида.
        $tested = null;
        if (isset($_POST['onecatalog_b2b_test'])
            && isset($_POST['onecatalog_b2b_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['onecatalog_b2b_nonce'])), 'onecatalog_b2b_connection')
            && current_user_can('manage_woocommerce')
        ) {
            $meta = B2B_Api::discover();
            if (null !== $meta) {
                update_option(self::OPTION_CATALOG_META, $meta, false);
                $tested = $meta;
            } else {
                $tested = false;
            }
        }

        $env_key = getenv('ONECATALOG_B2B_KEY');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('OneCatalog — B2B connection', 'onecatalog-import'); ?></h1>
            <p><?php esc_html_e('Credentials for the B2B price & stock feed. These are separate from the Wiki API token: a retailer key in the URL path plus a private key.', 'onecatalog-import'); ?></p>

            <?php if ($saved) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Saved.', 'onecatalog-import'); ?></p></div>
            <?php endif; ?>
            <?php if (false === $tested) : ?>
                <div class="notice notice-error"><p><?php esc_html_e('Could not load the feed — check the keys and try again.', 'onecatalog-import'); ?></p></div>
            <?php elseif (is_array($tested)) : ?>
                <div class="notice notice-success"><p><?php
                    printf(
                        /* translators: 1: regions count, 2: warehouses count, 3: suppliers count */
                        esc_html__('Feed loaded: %1$d regions, %2$d warehouses, %3$d suppliers. Configure them on the “Prices & stock” page.', 'onecatalog-import'),
                        count($tested['regions']),
                        count($tested['warehouses']),
                        count($tested['suppliers'])
                    );
                ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('onecatalog_b2b_connection', 'onecatalog_b2b_nonce'); ?>
                <table class="form-table" style="max-width:760px;">
                    <tr>
                        <th scope="row"><label for="onecatalog_b2b_key"><?php esc_html_e('Retailer key (URL)', 'onecatalog-import'); ?></label></th>
                        <td>
                            <input type="text" class="regular-text" id="onecatalog_b2b_key" name="onecatalog_b2b_key"
                                   value="<?php echo esc_attr(B2B_Api::url_key()); ?>" autocomplete="off">
                            <p class="description"><?php
                                echo esc_html__('The key from the share URL path (…/retailer-share-products/KEY/).', 'onecatalog-import');
                                if (false !== $env_key && '' !== (string) $env_key) {
                                    echo '<br><strong>' . esc_html__('Warning:', 'onecatalog-import') . '</strong> '
                                        . esc_html__('an environment variable is set — it overrides this field.', 'onecatalog-import');
                                }
                            ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="onecatalog_b2b_private_key"><?php esc_html_e('Private key', 'onecatalog-import'); ?></label></th>
                        <td>
                            <input type="password" class="regular-text" id="onecatalog_b2b_private_key" name="onecatalog_b2b_private_key"
                                   value="<?php echo esc_attr(B2B_Api::private_key()); ?>" autocomplete="off">
                            <p class="description"><?php esc_html_e('The private_key query parameter from the share URL.', 'onecatalog-import'); ?></p>
                        </td>
                    </tr>
                </table>
                <p>
                    <?php submit_button(__('Save', 'onecatalog-import'), 'primary', 'submit', false); ?>
                    <button type="submit" name="onecatalog_b2b_test" value="1" class="button" style="margin-left:8px;">
                        <?php esc_html_e('Save & load (test)', 'onecatalog-import'); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    // --- страница «Цены и остатки» ---

    private static function save_sync(): bool
    {
        if (! isset($_POST['onecatalog_ps_nonce'])
            || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['onecatalog_ps_nonce'])), 'onecatalog_ps_settings')
            || ! current_user_can('manage_woocommerce')
        ) {
            return false;
        }
        $strategy = sanitize_key(wp_unslash($_POST['onecatalog_b2b_price_strategy'] ?? 'priority'));
        update_option(self::OPTION_STRATEGY, in_array($strategy, ['priority', 'min', 'supplier'], true) ? $strategy : 'priority', false);
        update_option(self::OPTION_SUPPLIER_PRIO, self::int_list($_POST['onecatalog_b2b_supplier_priority'] ?? []), false);
        update_option(self::OPTION_REGION_PRIO, self::int_list($_POST['onecatalog_b2b_region_priority'] ?? []), false);
        update_option(self::OPTION_SUPPLIER_FIX, (int) ($_POST['onecatalog_b2b_supplier_fixed'] ?? 0), false);
        update_option(self::OPTION_WAREHOUSES, self::int_list($_POST['onecatalog_b2b_warehouses'] ?? []), false);
        update_option(self::OPTION_MANAGE_STOCK, empty($_POST['onecatalog_b2b_manage_stock']) ? '0' : '1', false);
        update_option(self::OPTION_DECIMAL_STOCK, empty($_POST['onecatalog_b2b_decimal_stock']) ? '0' : '1', false);
        update_option(self::OPTION_PROMO_AS_SALE, empty($_POST['onecatalog_b2b_promo_as_sale']) ? '0' : '1', false);
        update_option(self::OPTION_ZERO_ABSENT, empty($_POST['onecatalog_b2b_zero_absent']) ? '0' : '1', false);
        $km = sanitize_key(wp_unslash($_POST['onecatalog_b2b_known_missing'] ?? 'skip'));
        update_option(self::OPTION_KNOWN_MISSING, in_array($km, ['import', 'skip'], true) ? $km : 'skip', false);
        $um = sanitize_key(wp_unslash($_POST['onecatalog_b2b_unknown_mode'] ?? 'skip'));
        update_option(self::OPTION_UNKNOWN_MODE, in_array($um, ['skip', 'import'], true) ? $um : 'skip', false);
        update_option(self::OPTION_PAGE_SIZE, max(self::PAGE_MIN, min(self::PAGE_MAX, (int) ($_POST['onecatalog_b2b_page_size'] ?? self::PAGE_DEFAULT))), false);
        return true;
    }

    public static function render_sync(): void
    {
        $saved = self::save_sync();
        $meta  = self::catalog_meta();
        $regions    = (array) ($meta['regions'] ?? []);
        $warehouses = (array) ($meta['warehouses'] ?? []);
        $suppliers  = (array) ($meta['suppliers'] ?? []);

        if (! B2B_Api::configured()) {
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('Prices & stock', 'onecatalog-import'); ?></h1>
                <div class="notice notice-warning"><p><?php
                    printf(
                        /* translators: %s: link to the B2B connection page */
                        esc_html__('Configure the B2B connection first: %s.', 'onecatalog-import'),
                        '<a href="' . esc_url(admin_url('admin.php?page=onecatalog-b2b')) . '">' . esc_html__('B2B connection', 'onecatalog-import') . '</a>'
                    );
                ?></p></div>
            </div>
            <?php
            return;
        }

        // Приоритеты: сохранённый порядок + добавляем недостающие из meta в конец.
        $region_order   = self::ordered_with_rest(self::region_priority(), array_keys($regions));
        $supplier_order = self::ordered_with_rest(self::supplier_priority(), array_keys($suppliers));
        $strategy       = self::price_strategy();
        $sel_warehouses = self::warehouses();

        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_script('onecatalog-b2b', plugins_url('assets/admin-b2b.js', ONECATALOG_IMPORT_FILE), ['jquery', 'jquery-ui-sortable'], ONECATALOG_IMPORT_VERSION, true);
        wp_localize_script('onecatalog-b2b', 'OneCatalogB2B', [
            'restSync'   => esc_url_raw(rest_url('onecatalog/v1/b2b-sync')),
            'restStatus' => esc_url_raw(rest_url('onecatalog/v1/b2b-status')),
            'nonce'      => wp_create_nonce('wp_rest'),
            'i18n'       => [
                'starting' => __('Starting…', 'onecatalog-import'),
                'running'  => __('Syncing…', 'onecatalog-import'),
                'done'     => __('Done.', 'onecatalog-import'),
                'error'    => __('Error.', 'onecatalog-import'),
            ],
        ]);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Prices & stock', 'onecatalog-import'); ?></h1>
            <p><?php esc_html_e('Updates regular/sale price and stock of products matched by public_id from the B2B feed. This overwrites price/stock of synced products on every run (it is a live feed).', 'onecatalog-import'); ?></p>

            <?php if ($saved) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'onecatalog-import'); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('onecatalog_ps_settings', 'onecatalog_ps_nonce'); ?>
                <table class="form-table" style="max-width:820px;">
                    <tr>
                        <th scope="row"><label for="onecatalog_b2b_price_strategy"><?php esc_html_e('Price strategy', 'onecatalog-import'); ?></label></th>
                        <td>
                            <select id="onecatalog_b2b_price_strategy" name="onecatalog_b2b_price_strategy">
                                <option value="priority" <?php selected($strategy, 'priority'); ?>><?php esc_html_e('Supplier priority (fallback: lowest)', 'onecatalog-import'); ?></option>
                                <option value="min" <?php selected($strategy, 'min'); ?>><?php esc_html_e('Lowest base price', 'onecatalog-import'); ?></option>
                                <option value="supplier" <?php selected($strategy, 'supplier'); ?>><?php esc_html_e('Specific supplier only', 'onecatalog-import'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Stock is always summed across suppliers and selected warehouses; this controls the price only.', 'onecatalog-import'); ?></p>
                        </td>
                    </tr>
                    <tr class="oc-ps-supplier-fixed"<?php echo $strategy === 'supplier' ? '' : ' style="display:none;"'; ?>>
                        <th scope="row"><label for="onecatalog_b2b_supplier_fixed"><?php esc_html_e('Supplier', 'onecatalog-import'); ?></label></th>
                        <td>
                            <select id="onecatalog_b2b_supplier_fixed" name="onecatalog_b2b_supplier_fixed">
                                <option value="0"><?php esc_html_e('— select —', 'onecatalog-import'); ?></option>
                                <?php foreach ($suppliers as $sid => $sname) : ?>
                                    <option value="<?php echo (int) $sid; ?>" <?php selected(self::supplier_fixed(), (int) $sid); ?>><?php echo esc_html($sname . ' (#' . $sid . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Region priority', 'onecatalog-import'); ?></th>
                        <td>
                            <?php self::render_sortable('onecatalog_b2b_region_priority', $region_order, $regions); ?>
                            <p class="description"><?php esc_html_e('Drag to order. Price is taken from the first region that has a valid price.', 'onecatalog-import'); ?></p>
                        </td>
                    </tr>
                    <tr class="oc-ps-supplier-prio"<?php echo $strategy === 'priority' ? '' : ' style="display:none;"'; ?>>
                        <th scope="row"><?php esc_html_e('Supplier priority', 'onecatalog-import'); ?></th>
                        <td>
                            <?php self::render_sortable('onecatalog_b2b_supplier_priority', $supplier_order, $suppliers); ?>
                            <p class="description"><?php esc_html_e('Drag to order. Used when the price strategy is “Supplier priority”.', 'onecatalog-import'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Warehouses (stock)', 'onecatalog-import'); ?></th>
                        <td>
                            <?php if (! $warehouses) : ?>
                                <em><?php esc_html_e('No warehouses loaded.', 'onecatalog-import'); ?></em>
                            <?php else : foreach ($warehouses as $wid => $wlabel) : ?>
                                <label style="display:block;margin:2px 0;">
                                    <input type="checkbox" name="onecatalog_b2b_warehouses[]" value="<?php echo (int) $wid; ?>"
                                        <?php checked(! $sel_warehouses || in_array((int) $wid, $sel_warehouses, true)); ?>>
                                    <?php echo esc_html($wlabel . ' (#' . $wid . ')'); ?>
                                </label>
                            <?php endforeach; endif; ?>
                            <p class="description"><?php esc_html_e('Stock quantity is the sum across the checked warehouses. None checked = all.', 'onecatalog-import'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Stock options', 'onecatalog-import'); ?></th>
                        <td>
                            <label style="display:block;"><input type="checkbox" name="onecatalog_b2b_manage_stock" value="1" <?php checked(self::manage_stock()); ?>> <?php esc_html_e('Manage stock quantity (set quantity, not just in/out)', 'onecatalog-import'); ?></label>
                            <label style="display:block;"><input type="checkbox" name="onecatalog_b2b_decimal_stock" value="1" <?php checked(self::decimal_stock()); ?>> <?php esc_html_e('Allow fractional stock (e.g. m²) — enables decimal stock amounts', 'onecatalog-import'); ?></label>
                            <label style="display:block;"><input type="checkbox" name="onecatalog_b2b_zero_absent" value="1" <?php checked(self::zero_absent()); ?>> <?php esc_html_e('Set products NOT present in the feed to out of stock', 'onecatalog-import'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Price options', 'onecatalog-import'); ?></th>
                        <td>
                            <label><input type="checkbox" name="onecatalog_b2b_promo_as_sale" value="1" <?php checked(self::promo_as_sale()); ?>> <?php esc_html_e('Use promo_price as the WooCommerce sale price (when 0 < promo < base)', 'onecatalog-import'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="onecatalog_b2b_known_missing"><?php esc_html_e('Known products not in store', 'onecatalog-import'); ?></label></th>
                        <td>
                            <select id="onecatalog_b2b_known_missing" name="onecatalog_b2b_known_missing">
                                <option value="skip" <?php selected(self::known_missing(), 'skip'); ?>><?php esc_html_e('Skip (only update existing)', 'onecatalog-import'); ?></option>
                                <option value="import" <?php selected(self::known_missing(), 'import'); ?>><?php esc_html_e('Import them via the standard Wiki queue', 'onecatalog-import'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Known products have a public_id, so they can be imported through the normal Wiki import pipeline and then priced.', 'onecatalog-import'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="onecatalog_b2b_unknown_mode"><?php esc_html_e('Unknown products (no wiki)', 'onecatalog-import'); ?></label></th>
                        <td>
                            <select id="onecatalog_b2b_unknown_mode" name="onecatalog_b2b_unknown_mode">
                                <option value="skip" <?php selected(self::unknown_mode(), 'skip'); ?>><?php esc_html_e('Skip (recommended)', 'onecatalog-import'); ?></option>
                                <option value="import" <?php selected(self::unknown_mode(), 'import'); ?>><?php esc_html_e('Create and record the supplier code', 'onecatalog-import'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Unknown products have no public_id; if created, the supplier code is stored for future matching. Not recommended.', 'onecatalog-import'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="onecatalog_b2b_page_size"><?php esc_html_e('Feed page size', 'onecatalog-import'); ?></label></th>
                        <td><input type="number" min="<?php echo (int) self::PAGE_MIN; ?>" max="<?php echo (int) self::PAGE_MAX; ?>" id="onecatalog_b2b_page_size" name="onecatalog_b2b_page_size" value="<?php echo (int) self::page_size(); ?>" style="width:90px;"></td>
                    </tr>
                </table>
                <?php submit_button(__('Save settings', 'onecatalog-import')); ?>
            </form>

            <hr>
            <h2><?php esc_html_e('Run sync', 'onecatalog-import'); ?></h2>
            <p><button class="button button-primary" id="oc-b2b-sync"><?php esc_html_e('Sync now', 'onecatalog-import'); ?></button></p>
            <div id="oc-b2b-progress" style="font-weight:bold;margin:8px 0;"></div>
            <pre id="oc-b2b-log" style="max-height:340px;overflow:auto;background:#f7f7f7;border:1px solid #ddd;padding:8px;"></pre>
        </div>
        <script>
        (function () {
            var sel = document.getElementById('onecatalog_b2b_price_strategy');
            function sync() {
                var v = sel ? sel.value : 'priority';
                document.querySelectorAll('.oc-ps-supplier-prio').forEach(function (r) { r.style.display = (v === 'priority') ? '' : 'none'; });
                document.querySelectorAll('.oc-ps-supplier-fixed').forEach(function (r) { r.style.display = (v === 'supplier') ? '' : 'none'; });
            }
            if (sel) { sel.addEventListener('change', sync); sync(); }
        })();
        </script>
        <?php
    }

    /** Рендер сортируемого списка приоритета (li с вложенным hidden input — порядок = DOM). */
    private static function render_sortable(string $field, array $order, array $labels): void
    {
        if (! $labels) {
            echo '<em>' . esc_html__('Nothing loaded.', 'onecatalog-import') . '</em>';
            return;
        }
        echo '<ul class="oc-sortable" style="margin:0;max-width:420px;">';
        foreach ($order as $id) {
            $id = (int) $id;
            if (! isset($labels[$id])) {
                continue;
            }
            echo '<li style="padding:6px 10px;margin:3px 0;background:#fff;border:1px solid #ccd0d4;border-radius:3px;cursor:move;">';
            echo '<span class="dashicons dashicons-menu" style="color:#999;"></span> ';
            echo esc_html($labels[$id] . ' (#' . $id . ')');
            echo '<input type="hidden" name="' . esc_attr($field) . '[]" value="' . $id . '">';
            echo '</li>';
        }
        echo '</ul>';
    }

    /** Сохранённый порядок + недостающие ключи из $all в конец. */
    private static function ordered_with_rest(array $order, array $all): array
    {
        $all = array_map('intval', $all);
        $out = [];
        foreach ($order as $id) {
            $id = (int) $id;
            if (in_array($id, $all, true) && ! in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        foreach ($all as $id) {
            if (! in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return $out;
    }
}
