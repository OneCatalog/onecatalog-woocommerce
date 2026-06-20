<?php
/**
 * «Отстойник» (staging) для unknown-товаров B2B-фида.
 *
 * При режиме unknown = «отстойник» несопоставленные товары поставщиков не создаются
 * сразу, а складываются в таблицу на ручной отбор. Вебмастер на странице «Отстойник»
 * отмечает нужные → «Импортировать», ненужные → «Игнорировать» (больше не предлагаются).
 * Авто-режим (создавать всё) остаётся отдельной опцией.
 */

namespace OneCatalog\Import;

if (! defined('ABSPATH')) {
    exit;
}

final class B2B_Staging
{
    public const DB_VERSION_OPTION = 'onecatalog_b2b_staging_db';
    public const DB_VERSION        = '1';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu'], 30);
        add_action('admin_init', [self::class, 'maybe_install']);
    }

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'onecatalog_b2b_staging';
    }

    /** Создать/обновить таблицу (на активации и при смене версии схемы). */
    public static function install(): void
    {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(190) NOT NULL,
            name VARCHAR(255) NULL,
            supplier_id INT NULL,
            supplier_name VARCHAR(255) NULL,
            payload LONGTEXT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'new',
            product_id BIGINT UNSIGNED NULL,
            first_seen DATETIME NULL,
            last_seen DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY status (status)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    public static function maybe_install(): void
    {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            self::install();
        }
    }

    /**
     * Положить unknown-оффер в отстойник. Новый → status 'new'; уже игнорированный —
     * оставляем игнорированным (не воскрешаем), но обновляем last_seen/payload.
     */
    public static function upsert(array $offer, array $suppliers = []): void
    {
        global $wpdb;
        $code = trim((string) ($offer['code'] ?? ''));
        if ('' === $code) {
            return;
        }
        $table = self::table();
        $now   = current_time('mysql');
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id, status FROM {$table} WHERE code = %s", $code), ARRAY_A);

        // Поставщик: новый формат — supplier_id + карта suppliers; старый — supplier{id,name}.
        $supplier_id = PriceStockSync::offer_supplier_id($offer);
        $supplier_name = (string) (
            $suppliers[$supplier_id]['name']
            ?? $offer['supplier']['name']
            ?? (B2B_Settings::catalog_meta()['suppliers'][$supplier_id] ?? '')
        );

        $data = [
            'name'          => (string) ($offer['name'] ?? ''),
            'supplier_id'   => $supplier_id,
            'supplier_name' => $supplier_name,
            'payload'       => wp_json_encode($offer),
            'last_seen'     => $now,
        ];

        if ($existing) {
            if ('imported' === $existing['status']) {
                return; // уже импортирован — обновляется обычным синком по коду
            }
            $wpdb->update($table, $data, ['id' => (int) $existing['id']]);
        } else {
            $data['code']       = $code;
            $data['status']     = 'new';
            $data['first_seen'] = $now;
            $wpdb->insert($table, $data);
        }
    }

    public static function count(string $status = 'new'): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table() . ' WHERE status = %s',
            $status
        ));
    }

    /** @return array<int,array> строки отстойника */
    public static function rows(string $status, int $limit, int $offset): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE status = %s ORDER BY last_seen DESC LIMIT %d OFFSET %d',
            $status,
            $limit,
            $offset
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /** Импортировать выбранные строки (создать товары + цена/остаток). */
    public static function import_ids(array $ids): int
    {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (! $ids) {
            return 0;
        }
        $table = self::table();
        $done  = 0;
        foreach ($ids as $id) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND status = 'new'", $id), ARRAY_A);
            if (! $row) {
                continue;
            }
            $offer = json_decode((string) $row['payload'], true);
            if (! is_array($offer)) {
                continue;
            }
            $product_id = PriceStockSync::import_offer($offer);
            if ($product_id > 0) {
                $wpdb->update($table, ['status' => 'imported', 'product_id' => $product_id], ['id' => $id]);
                $done++;
            }
        }
        return $done;
    }

    public static function set_status(array $ids, string $status): int
    {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (! $ids || ! in_array($status, ['new', 'ignored'], true)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare(
            'UPDATE ' . self::table() . " SET status = %s WHERE id IN ($placeholders)",
            array_merge([$status], $ids)
        );
        return (int) $wpdb->query($sql);
    }

    // ===================== Меню и страница =====================

    public static function register_menu(): void
    {
        $count = self::count('new');
        $title = __('Staging', 'onecatalog-import');
        if ($count > 0) {
            $title .= ' <span class="awaiting-mod">' . (int) $count . '</span>';
        }
        add_submenu_page(
            'onecatalog',
            __('Staging (unknown products)', 'onecatalog-import'),
            $title,
            'manage_woocommerce',
            'onecatalog-staging',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied', 'onecatalog-import'));
        }

        $notice = self::handle_post();

        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : 'new';
        $status = in_array($status, ['new', 'ignored', 'imported'], true) ? $status : 'new';
        $paged  = max(1, (int) ($_GET['paged'] ?? 1));
        $per    = 50;
        $rows   = self::rows($status, $per, ($paged - 1) * $per);

        $cfg    = PriceStockSync::cfg();
        $base   = admin_url('admin.php?page=onecatalog-staging');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Staging — unknown supplier products', 'onecatalog-import'); ?></h1>
            <p><?php esc_html_e('Products from the B2B feed without a OneCatalog public_id. Select the ones to import; the rest stay here. Ignored ones are not offered again.', 'onecatalog-import'); ?></p>

            <?php if ($notice) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper">
                <?php foreach (['new' => __('New', 'onecatalog-import'), 'ignored' => __('Ignored', 'onecatalog-import'), 'imported' => __('Imported', 'onecatalog-import')] as $key => $label) : ?>
                    <a class="nav-tab<?php echo $status === $key ? ' nav-tab-active' : ''; ?>"
                       href="<?php echo esc_url(add_query_arg('status', $key, $base)); ?>">
                        <?php echo esc_html($label); ?> (<?php echo (int) self::count($key); ?>)
                    </a>
                <?php endforeach; ?>
            </h2>

            <form method="post">
                <?php wp_nonce_field('onecatalog_staging', 'onecatalog_staging_nonce'); ?>
                <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">

                <?php if ('new' === $status && $rows) : ?>
                    <p>
                        <button type="submit" name="oc_action" value="import" class="button button-primary"><?php esc_html_e('Import selected', 'onecatalog-import'); ?></button>
                        <button type="submit" name="oc_action" value="ignore" class="button"><?php esc_html_e('Ignore selected', 'onecatalog-import'); ?></button>
                    </p>
                <?php elseif ('ignored' === $status && $rows) : ?>
                    <p><button type="submit" name="oc_action" value="restore" class="button"><?php esc_html_e('Return to “New”', 'onecatalog-import'); ?></button></p>
                <?php endif; ?>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <?php if ('imported' !== $status) : ?><td class="check-column"><input type="checkbox" onclick="document.querySelectorAll('.oc-st-cb').forEach(c=>c.checked=this.checked)"></td><?php endif; ?>
                            <th><?php esc_html_e('Name', 'onecatalog-import'); ?></th>
                            <th><?php esc_html_e('Supplier code', 'onecatalog-import'); ?></th>
                            <th><?php esc_html_e('Supplier', 'onecatalog-import'); ?></th>
                            <th><?php esc_html_e('Price', 'onecatalog-import'); ?></th>
                            <th><?php esc_html_e('Stock', 'onecatalog-import'); ?></th>
                            <th><?php esc_html_e('Last seen', 'onecatalog-import'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (! $rows) : ?>
                        <tr><td colspan="7"><?php esc_html_e('Empty.', 'onecatalog-import'); ?></td></tr>
                    <?php else : foreach ($rows as $row) :
                        $offer = json_decode((string) $row['payload'], true);
                        $offer = is_array($offer) ? $offer : [];
                        $price = PriceStockSync::resolve_price([$offer], $cfg['region_prio'], $cfg['supplier_prio'], $cfg['strategy'], $cfg['supplier_fix'], $cfg['promo_as_sale']);
                        $stock = PriceStockSync::resolve_stock([$offer]);
                        ?>
                        <tr>
                            <?php if ('imported' !== $status) : ?><th class="check-column"><input type="checkbox" class="oc-st-cb" name="ids[]" value="<?php echo (int) $row['id']; ?>"></th><?php endif; ?>
                            <td><?php echo esc_html($row['name'] ?: '—'); ?>
                                <?php if (! empty($row['product_id'])) : ?>
                                    — <a href="<?php echo esc_url(get_edit_post_link((int) $row['product_id'])); ?>"><?php esc_html_e('product', 'onecatalog-import'); ?></a>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo esc_html($row['code']); ?></code></td>
                            <td><?php echo esc_html($row['supplier_name'] ?: ('#' . $row['supplier_id'])); ?></td>
                            <td><?php echo null !== $price['regular'] ? esc_html((string) $price['regular'] . (null !== $price['sale'] ? ' / ' . $price['sale'] : '')) : '—'; ?></td>
                            <td><?php echo esc_html((string) $stock); ?></td>
                            <td><?php echo esc_html((string) $row['last_seen']); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </form>
        </div>
        <?php
    }

    /** Обработка bulk-действий. Возвращает текст уведомления или null. */
    private static function handle_post(): ?string
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ! isset($_POST['onecatalog_staging_nonce'])) {
            return null;
        }
        if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['onecatalog_staging_nonce'])), 'onecatalog_staging')
            || ! current_user_can('manage_woocommerce')
        ) {
            return null;
        }
        $ids    = array_map('intval', (array) ($_POST['ids'] ?? []));
        $action = sanitize_key(wp_unslash($_POST['oc_action'] ?? ''));
        if (! $ids) {
            return null;
        }
        if ('import' === $action) {
            $n = self::import_ids($ids);
            /* translators: %d: number of products */
            return sprintf(__('Imported: %d', 'onecatalog-import'), $n);
        }
        if ('ignore' === $action) {
            $n = self::set_status($ids, 'ignored');
            /* translators: %d: number of products */
            return sprintf(__('Ignored: %d', 'onecatalog-import'), $n);
        }
        if ('restore' === $action) {
            $n = self::set_status($ids, 'new');
            /* translators: %d: number of products */
            return sprintf(__('Returned to “New”: %d', 'onecatalog-import'), $n);
        }
        return null;
    }
}
