<?php
/**
 * Синхронизация цен и остатков из B2B-фида.
 *
 * known (ключ = public_id) → товар по мете _onecatalog_public_id → цена/остаток.
 * Цена: стратегия (приоритет поставщиков / минимальная / конкретный) × приоритет
 * регионов; promo>0 (и <base) → sale_price. Остаток: сумма по выбранным складам по
 * всем поставщикам. Коды поставщиков фиксируются в мете для будущего матчинга.
 *
 * Перетирает цену/остаток синкаемых товаров каждый запуск (это живой фид) — в отличие
 * от импорта каталога, который цену не трогает. Чистые резолверы (resolve_price/
 * resolve_stock) не зависят от WP и покрыты тестами.
 */

namespace OneCatalog\Import;

if (! defined('ABSPATH')) {
    exit;
}

final class PriceStockSync
{
    public const AS_HOOK   = 'onecatalog_b2b_sync_page';
    public const AS_GROUP  = 'onecatalog-b2b';
    public const OPTION_LOG      = 'onecatalog_b2b_log';
    public const OPTION_PROGRESS = 'onecatalog_b2b_progress';

    public const META_SUPPLIER_CODES = '_onecatalog_supplier_codes'; // [['supplier_id'=>,'code'=>], …]
    public const META_SUPPLIER_CODE  = '_onecatalog_supplier_code';  // плоская (searchable) мета на каждый код
    public const META_SYNCED_AT      = '_onecatalog_pricestock_synced_at';
    public const META_PURCHASING     = '_onecatalog_purchasing_price';

    public static function init(): void
    {
        add_action(self::AS_HOOK, [self::class, 'process_page'], 10, 1);
        add_action('rest_api_init', [self::class, 'register_routes']);

        // Дробные остатки (м²): включаем поддержку decimal stock в WooCommerce.
        if (B2B_Settings::decimal_stock()) {
            add_filter('woocommerce_stock_amount', 'floatval');
        }
    }

    // ===================== Чистые резолверы (тестируются без WP) =====================

    /**
     * Цена товара из офферов по стратегии и приоритетам.
     *
     * @param array  $offers          офферы поставщиков по одному public_id
     * @param int[]  $region_priority порядок регионов
     * @param int[]  $supplier_priority порядок поставщиков
     * @param string $strategy        priority | min | supplier
     * @param int    $supplier_fixed  поставщик для strategy=supplier
     * @param bool   $promo_as_sale   promo>0 (и <base) → sale
     * @return array{regular: ?float, sale: ?float, purchasing: ?float}
     */
    public static function resolve_price(
        array $offers,
        array $region_priority,
        array $supplier_priority,
        string $strategy = 'priority',
        int $supplier_fixed = 0,
        bool $promo_as_sale = true
    ): array {
        $none = ['regular' => null, 'sale' => null, 'purchasing' => null];

        $candidates = [];
        if ('supplier' === $strategy) {
            foreach ($offers as $o) {
                if ((int) ($o['supplier']['id'] ?? 0) === $supplier_fixed) {
                    $candidates[] = $o;
                }
            }
        } else {
            $candidates = $offers;
        }
        if (! $candidates) {
            return $none;
        }

        // Цена каждого кандидата по приоритету регионов.
        $priced = [];
        foreach ($candidates as $o) {
            $p = self::price_for_offer($o, $region_priority);
            if (null !== $p) {
                $priced[] = ['offer' => $o, 'price' => $p];
            }
        }
        if (! $priced) {
            return $none;
        }

        if ('priority' === $strategy && $supplier_priority) {
            // Первый по приоритету поставщик с валидной ценой.
            foreach ($supplier_priority as $sid) {
                foreach ($priced as $row) {
                    if ((int) ($row['offer']['supplier']['id'] ?? 0) === (int) $sid) {
                        return self::price_with_sale($row['price'], $promo_as_sale);
                    }
                }
            }
            // фолбэк — минимальная
        }

        if ('supplier' === $strategy) {
            return self::price_with_sale($priced[0]['price'], $promo_as_sale);
        }

        // 'min' или фолбэк 'priority' → минимальная base.
        usort($priced, static fn ($a, $b) => $a['price']['base'] <=> $b['price']['base']);
        return self::price_with_sale($priced[0]['price'], $promo_as_sale);
    }

    /** Цена оффера для первого региона по приоритету с валидной base. */
    private static function price_for_offer(array $offer, array $region_priority): ?array
    {
        $by_region = [];
        foreach ((array) ($offer['product_prices'] ?? []) as $pr) {
            $rid = (int) ($pr['region_id'] ?? 0);
            if ($rid > 0) {
                $by_region[$rid] = $pr;
            }
        }
        if (! $by_region) {
            return null;
        }
        $order = $region_priority ?: array_keys($by_region);
        foreach ($order as $rid) {
            $pr = $by_region[(int) $rid] ?? null;
            if (! $pr) {
                continue;
            }
            $base = (float) ($pr['base_price'] ?? 0);
            if ($base > 0) {
                return [
                    'base'       => $base,
                    'promo'      => (float) ($pr['promo_price'] ?? 0),
                    'purchasing' => isset($pr['purchasing_price']) && null !== $pr['purchasing_price'] ? (float) $pr['purchasing_price'] : null,
                ];
            }
        }
        return null;
    }

    private static function price_with_sale(array $price, bool $promo_as_sale): array
    {
        $sale = null;
        if ($promo_as_sale && $price['promo'] > 0 && $price['promo'] < $price['base']) {
            $sale = $price['promo'];
        }
        return ['regular' => $price['base'], 'sale' => $sale, 'purchasing' => $price['purchasing']];
    }

    /**
     * Суммарный остаток по выбранным складам по всем офферам.
     *
     * @param int[] $warehouses выбранные склады ([] = все)
     */
    public static function resolve_stock(array $offers, array $warehouses): float
    {
        $sum = 0.0;
        foreach ($offers as $o) {
            foreach ((array) ($o['products_stocks'] ?? []) as $s) {
                $wid = (int) ($s['warehouse_id'] ?? 0);
                if (! $warehouses || in_array($wid, $warehouses, true)) {
                    $sum += (float) ($s['quantity'] ?? 0);
                }
            }
        }
        return $sum;
    }

    /** Хотя бы один оффер доступен (status=true). */
    public static function any_available(array $offers): bool
    {
        foreach ($offers as $o) {
            if (! empty($o['status'])) {
                return true;
            }
        }
        return false;
    }

    /** Уникальные коды поставщиков из офферов: [['supplier_id'=>,'code'=>], …]. */
    public static function extract_supplier_codes(array $offers): array
    {
        $out = [];
        $seen = [];
        foreach ($offers as $o) {
            $code = trim((string) ($o['code'] ?? ''));
            $sid  = (int) ($o['supplier']['id'] ?? 0);
            if ('' === $code) {
                continue;
            }
            $key = $sid . '|' . $code;
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = ['supplier_id' => $sid, 'code' => $code];
            }
        }
        return $out;
    }

    // ===================== Оркестрация (WP) =====================

    /** Запустить синк с нуля: сброс лога/прогресса и постановка первой страницы. */
    public static function start(): array
    {
        update_option(self::OPTION_LOG, [], false);
        update_option(self::OPTION_PROGRESS, ['done' => 0, 'total' => B2B_Api::total(), 'finished' => false, 'started' => time()], false);

        if (! Queue::available()) {
            // Синхронный фолбэк: гоняем страницы подряд (для малых каталогов / CLI).
            $start = 0;
            $size  = B2B_Settings::page_size();
            do {
                $more = self::process_page($start, true);
                $start += $size;
            } while ($more);
            return ['sync' => true, 'progress' => get_option(self::OPTION_PROGRESS)];
        }

        as_enqueue_async_action(self::AS_HOOK, [0], self::AS_GROUP);
        return ['sync' => false, 'queued' => true];
    }

    /**
     * Обработать одну страницу фида начиная со $start. Возвращает true, если есть ещё.
     * При работе через Action Scheduler сама планирует следующую страницу.
     */
    public static function process_page($start, bool $return_more = false): bool
    {
        $start = (int) $start;
        $size  = B2B_Settings::page_size();

        $page = B2B_Api::fetch_page($start, $size);
        if (null === $page) {
            self::log('', 'error', __('feed request failed', 'onecatalog-import'));
            self::finish();
            return false;
        }

        $data    = $page['data'];
        $total   = (int) ($page['meta']['counts'] ?? 0);
        $known   = (array) ($data['products']['known'] ?? []);
        $unknown = (array) ($data['products']['unknown'] ?? []);

        $region_prio   = B2B_Settings::region_priority();
        $supplier_prio = B2B_Settings::supplier_priority();
        $strategy      = B2B_Settings::price_strategy();
        $supplier_fix  = B2B_Settings::supplier_fixed();
        $promo_as_sale = B2B_Settings::promo_as_sale();
        $warehouses    = B2B_Settings::warehouses();
        $known_missing = B2B_Settings::known_missing();

        $map       = self::map_public_ids(array_keys($known));
        $processed = 0;
        $to_import = [];

        foreach ($known as $public_id => $offers) {
            $public_id = (string) $public_id;
            $offers    = (array) $offers;
            $product_id = $map[$public_id] ?? ProductImporter::find_by_public_id($public_id);

            if (! $product_id) {
                if ('import' === $known_missing) {
                    $to_import[] = $public_id;
                    self::log($public_id, 'queued', __('not in store — queued for Wiki import', 'onecatalog-import'));
                } else {
                    self::log($public_id, 'missing', __('not in store — skipped', 'onecatalog-import'));
                }
                $processed++;
                continue;
            }

            $report = self::apply_to_product(
                (int) $product_id,
                $offers,
                compact('region_prio', 'supplier_prio', 'strategy', 'supplier_fix', 'promo_as_sale', 'warehouses')
            );
            self::log($public_id, $report['status'], $report['message'] ?? '');
            $processed++;
        }

        if ($to_import) {
            Queue::enqueue($to_import); // стандартный механизм Wiki + очередь
        }

        if ($unknown && 'import' === B2B_Settings::unknown_mode()) {
            foreach ($unknown as $offer) {
                self::handle_unknown((array) $offer, compact('region_prio', 'supplier_prio', 'strategy', 'supplier_fix', 'promo_as_sale', 'warehouses'));
            }
        } elseif ($unknown) {
            self::log('', 'skipped', sprintf(/* translators: %d: count */ __('%d unknown products skipped', 'onecatalog-import'), count($unknown)));
        }

        // Прогресс.
        $progress = (array) get_option(self::OPTION_PROGRESS, []);
        $progress['done']  = (int) ($progress['done'] ?? 0) + $processed;
        $progress['total'] = $total ?: (int) ($progress['total'] ?? 0);
        update_option(self::OPTION_PROGRESS, $progress, false);

        $next = $start + $size;
        $has_more = ($processed > 0) && ($next < ($total ?: PHP_INT_MAX)) && (! empty($known) || ! empty($unknown));

        if ($has_more && ! $return_more && Queue::available()) {
            as_enqueue_async_action(self::AS_HOOK, [$next], self::AS_GROUP);
        }
        if (! $has_more) {
            self::finish();
        }
        return $has_more;
    }

    /** Применить цену/остаток к товару. @return array{status,message?} */
    private static function apply_to_product(int $product_id, array $offers, array $cfg): array
    {
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if (! $product) {
            return ['status' => 'error', 'message' => 'product object missing'];
        }

        $price = self::resolve_price($offers, $cfg['region_prio'], $cfg['supplier_prio'], $cfg['strategy'], $cfg['supplier_fix'], $cfg['promo_as_sale']);
        /** Фильтр: цена B2B перед записью (regular/sale/purchasing). */
        $price = (array) apply_filters('onecatalog_b2b_price', $price, $offers, $product_id);

        if (null !== $price['regular']) {
            $product->set_regular_price((string) $price['regular']);
            $product->set_sale_price(null !== $price['sale'] ? (string) $price['sale'] : '');
            if (null !== ($price['purchasing'] ?? null)) {
                update_post_meta($product_id, self::META_PURCHASING, (string) $price['purchasing']);
            }
        }

        $stock = self::resolve_stock($offers, $cfg['warehouses']);
        /** Фильтр: количество остатка B2B перед записью. */
        $stock = (float) apply_filters('onecatalog_b2b_stock_qty', $stock, $offers, $product_id);
        $available = self::any_available($offers) && $stock > 0;

        if (B2B_Settings::manage_stock()) {
            $product->set_manage_stock(true);
            $qty = B2B_Settings::decimal_stock() ? $stock : (float) floor($stock);
            $product->set_stock_quantity($qty);
            $product->set_stock_status($available ? 'instock' : 'outofstock');
            update_post_meta($product_id, '_onecatalog_stock_raw', (string) $stock);
        } else {
            $product->set_manage_stock(false);
            $product->set_stock_status($available ? 'instock' : 'outofstock');
        }

        // Коды поставщиков (для будущего матчинга по коду).
        self::store_supplier_codes($product_id, self::extract_supplier_codes($offers));
        update_post_meta($product_id, self::META_SYNCED_AT, time());

        $product->save();

        /** Экшен: цена/остаток товара обновлены из B2B. */
        do_action('onecatalog_pricestock_updated', $product_id, $offers, $price, $stock);

        return ['status' => 'updated', 'message' => self::summary($price, $stock)];
    }

    /** Создать/обновить unknown-товар по коду поставщика (не рекомендуется). */
    private static function handle_unknown(array $offer, array $cfg): void
    {
        $code = trim((string) ($offer['code'] ?? ''));
        $name = trim((string) ($offer['name'] ?? ''));
        if ('' === $code || ! function_exists('wc_get_product')) {
            return;
        }
        $product_id = self::find_by_supplier_code($code);
        if (! $product_id) {
            $product = new \WC_Product_Simple();
            $product->set_name($name !== '' ? $name : $code);
            $product->set_status(Settings::product_status());
            if ('' === (string) wc_get_product_id_by_sku($code)) {
                $product->set_sku($code);
            }
            $product_id = $product->save();
            if (! $product_id) {
                self::log($code, 'error', __('failed to create unknown product', 'onecatalog-import'));
                return;
            }
        }
        $report = self::apply_to_product((int) $product_id, [$offer], $cfg);
        self::log($code, $report['status'] === 'updated' ? 'created/updated' : $report['status'], $report['message'] ?? '');
    }

    // ===================== Хранилища / помощники =====================

    /** Карта public_id → product_id одним запросом (без поштучных lookup’ов). */
    private static function map_public_ids(array $public_ids): array
    {
        global $wpdb;
        $public_ids = array_values(array_filter(array_map('strval', $public_ids)));
        if (! $public_ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($public_ids), '%s'));
        $sql = $wpdb->prepare(
            "SELECT meta_value AS pid, post_id FROM {$wpdb->postmeta}
             WHERE meta_key = %s AND meta_value IN ($placeholders)",
            array_merge([ProductImporter::META_PUBLIC_ID], $public_ids)
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $map = [];
        foreach ((array) $rows as $r) {
            $map[(string) $r['pid']] = (int) $r['post_id'];
        }
        return $map;
    }

    private static function find_by_supplier_code(string $code): int
    {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            self::META_SUPPLIER_CODE,
            $code
        ));
        return (int) $id;
    }

    private static function store_supplier_codes(int $product_id, array $codes): void
    {
        update_post_meta($product_id, self::META_SUPPLIER_CODES, $codes);
        // Плоская searchable-мета: пересоздаём набор кодов.
        delete_post_meta($product_id, self::META_SUPPLIER_CODE);
        foreach ($codes as $c) {
            if ('' !== (string) ($c['code'] ?? '')) {
                add_post_meta($product_id, self::META_SUPPLIER_CODE, (string) $c['code']);
            }
        }
    }

    private static function summary(array $price, float $stock): string
    {
        $parts = [];
        if (null !== $price['regular']) {
            $parts[] = 'price ' . $price['regular'] . (null !== $price['sale'] ? '/' . $price['sale'] : '');
        }
        $parts[] = 'stock ' . $stock;
        return implode(', ', $parts);
    }

    private static function log(string $key, string $status, string $message = ''): void
    {
        $log = get_option(self::OPTION_LOG, []);
        if (! is_array($log)) {
            $log = [];
        }
        array_unshift($log, ['t' => time(), 'key' => $key, 'status' => $status, 'message' => $message]);
        update_option(self::OPTION_LOG, array_slice($log, 0, 200), false);
    }

    private static function finish(): void
    {
        $progress = (array) get_option(self::OPTION_PROGRESS, []);
        $progress['finished'] = true;
        update_option(self::OPTION_PROGRESS, $progress, false);
    }

    // ===================== REST =====================

    public static function register_routes(): void
    {
        register_rest_route('onecatalog/v1', '/b2b-sync', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'rest_sync'],
            'permission_callback' => static fn () => current_user_can('manage_woocommerce'),
        ]);
        register_rest_route('onecatalog/v1', '/b2b-status', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'rest_status'],
            'permission_callback' => static fn () => current_user_can('manage_woocommerce'),
        ]);
    }

    public static function rest_sync(): \WP_REST_Response
    {
        if (! B2B_Api::configured()) {
            return new \WP_REST_Response(['error' => 'not_configured'], 400);
        }
        return new \WP_REST_Response(self::start(), 200);
    }

    public static function rest_status(): \WP_REST_Response
    {
        $pending = 0;
        if (Queue::available()) {
            $pending = count(as_get_scheduled_actions(['hook' => self::AS_HOOK, 'status' => 'pending', 'per_page' => 500], 'ids'));
        }
        return new \WP_REST_Response([
            'pending'  => $pending,
            'progress' => (array) get_option(self::OPTION_PROGRESS, []),
            'log'      => array_slice((array) get_option(self::OPTION_LOG, []), 0, 40),
        ], 200);
    }
}
