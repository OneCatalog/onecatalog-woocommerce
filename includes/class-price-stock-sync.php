<?php
/**
 * Синхронизация цен и остатков из B2B-фида — по принципу scan-and-diff.
 *
 * Чтобы не ронять сайт во время обновления и не делать лишней работы, синк разделён
 * на две фазы:
 *   1) СКАН (дёшево): читаем страницу фида, одним запросом достаём карту
 *      public_id→product_id и сохранённые сигнатуры, резолвим цену/остаток ЧИСТОЙ
 *      логикой и сравниваем сигнатуры В ПАМЯТИ — БЕЗ загрузки WC_Product. Если
 *      сигнатура совпала (цена и остаток те же) — товар пропускается, запись не идёт.
 *   2) ЗАПИСЬ (тяжело): только изменившиеся товары ставятся порциями в фоновую
 *      очередь; единственное место, где вызывается $product->save().
 *
 * known сопоставляются по public_id (мета _onecatalog_public_id). Цена: стратегия
 * (приоритет поставщиков / минимальная / конкретный) × приоритет регионов; promo>0
 * (и <base) → sale. Остаток: сумма по ВСЕМ складам поставщиков. Перетирает цену/остаток
 * синкаемых товаров (живой фид), но только когда они реально изменились.
 *
 * Чистые резолверы (resolve_price/resolve_stock/signature) не зависят от WP и покрыты
 * тестами.
 */

namespace OneCatalog\Import;

if (! defined('ABSPATH')) {
    exit;
}

final class PriceStockSync
{
    public const AS_HOOK       = 'onecatalog_b2b_sync_page';   // фаза скана (по страницам)
    public const AS_WRITE_HOOK = 'onecatalog_b2b_write_batch'; // фаза записи (только изменённые)
    public const CRON_HOOK     = 'onecatalog_b2b_cron';        // авто-синк по расписанию
    public const AS_GROUP      = 'onecatalog-b2b';

    public const OPTION_LOG      = 'onecatalog_b2b_log';
    public const OPTION_PROGRESS = 'onecatalog_b2b_progress';

    public const META_SUPPLIER_CODES = '_onecatalog_supplier_codes';
    public const META_SUPPLIER_CODE  = '_onecatalog_supplier_code';
    public const META_SYNCED_AT      = '_onecatalog_pricestock_synced_at';
    public const META_PURCHASING     = '_onecatalog_purchasing_price';
    public const META_SIG            = '_onecatalog_pricestock_sig';   // сигнатура последней записи
    public const META_STOCK_RAW      = '_onecatalog_stock_raw';

    public const WRITE_BATCH = 50; // товаров на одну порцию записи

    public static function init(): void
    {
        add_action(self::AS_HOOK, [self::class, 'process_page'], 10, 1);
        add_action(self::AS_WRITE_HOOK, [self::class, 'process_write_batch'], 10, 2);
        add_action(self::CRON_HOOK, [self::class, 'cron_run']);
        add_action('rest_api_init', [self::class, 'register_routes']);

        if (B2B_Settings::decimal_stock()) {
            add_filter('woocommerce_stock_amount', 'floatval');
        }
    }

    // ===================== Чистые резолверы (тестируются без WP) =====================

    /**
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
            foreach ($supplier_priority as $sid) {
                foreach ($priced as $row) {
                    if ((int) ($row['offer']['supplier']['id'] ?? 0) === (int) $sid) {
                        return self::price_with_sale($row['price'], $promo_as_sale);
                    }
                }
            }
        }
        if ('supplier' === $strategy) {
            return self::price_with_sale($priced[0]['price'], $promo_as_sale);
        }

        usort($priced, static fn ($a, $b) => $a['price']['base'] <=> $b['price']['base']);
        return self::price_with_sale($priced[0]['price'], $promo_as_sale);
    }

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

    /** Суммарный остаток по всем складам поставщиков. */
    public static function resolve_stock(array $offers): float
    {
        $sum = 0.0;
        foreach ($offers as $o) {
            foreach ((array) ($o['products_stocks'] ?? []) as $s) {
                $sum += (float) ($s['quantity'] ?? 0);
            }
        }
        return $sum;
    }

    public static function any_available(array $offers): bool
    {
        foreach ($offers as $o) {
            if (! empty($o['status'])) {
                return true;
            }
        }
        return false;
    }

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

    /**
     * Стабильная сигнатура того, ЧТО будет записано (цена + остаток + статус). Если
     * сигнатура не изменилась — записи не делаем. Зависит от значений, а не от фида,
     * поэтому смена настроек (регион/стратегия/единицы) корректно триггерит обновление.
     */
    public static function signature(array $r): string
    {
        $manage = ! empty($r['manage']);
        return md5(implode('|', [
            null === $r['regular'] ? '-' : (string) (float) $r['regular'],
            null === $r['sale'] ? '-' : (string) (float) $r['sale'],
            $manage ? 'm' : 's',
            // Количество влияет на сигнатуру только когда им управляем (иначе пишем лишь статус).
            ($manage && null !== ($r['qty'] ?? null)) ? (string) (float) $r['qty'] : '-',
            (string) ($r['status'] ?? ''),
        ]));
    }

    // ===================== Авто-расписание =====================

    /** Запуск синка по расписанию (рекуррентное действие). */
    public static function cron_run(): void
    {
        if (B2B_Api::configured()) {
            self::start();
        }
    }

    /**
     * Перепланировать авто-синк: снять старое и при включении поставить рекуррентным.
     * $first_run — момент первого запуска (для «каждый день» в выбранное время);
     * 0 — стартовать через интервал от текущего момента.
     */
    public static function reschedule(bool $enabled, int $interval, int $first_run = 0): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::CRON_HOOK, [], self::AS_GROUP);
        }
        if ($enabled && $interval > 0 && function_exists('as_schedule_recurring_action')) {
            $start = $first_run > 0 ? $first_run : (time() + $interval);
            as_schedule_recurring_action($start, $interval, self::CRON_HOOK, [], self::AS_GROUP);
        }
    }

    /** Время следующего авто-синка (timestamp) или 0. */
    public static function next_scheduled(): int
    {
        if (! function_exists('as_next_scheduled_action')) {
            return 0;
        }
        $ts = as_next_scheduled_action(self::CRON_HOOK, [], self::AS_GROUP);
        return is_int($ts) ? $ts : 0;
    }

    // ===================== Скан и diff (фаза 1) =====================

    /** Настройки резолва (общие для скана и ручного импорта из отстойника). */
    public static function cfg(): array
    {
        return [
            'region_prio'   => B2B_Settings::region_priority(),
            'supplier_prio' => B2B_Settings::supplier_priority(),
            'strategy'      => B2B_Settings::price_strategy(),
            'supplier_fix'  => B2B_Settings::supplier_fixed(),
            'promo_as_sale' => B2B_Settings::promo_as_sale(),
            'manage_stock'  => B2B_Settings::manage_stock(),
            'decimal_stock' => B2B_Settings::decimal_stock(),
        ];
    }

    public static function start(): array
    {
        update_option(self::OPTION_LOG, [], false);
        update_option(self::OPTION_PROGRESS, [
            'scanned' => 0, 'changed' => 0, 'unchanged' => 0, 'queued_import' => 0,
            'total' => B2B_Api::total(), 'finished' => false, 'started' => time(),
        ], false);

        if (! Queue::available()) {
            $start = 0;
            $size  = B2B_Settings::page_size();
            do {
                $more = self::process_page($start, true); // inline-режим: пишем сразу
                $start += $size;
            } while ($more);
            return ['sync' => true, 'progress' => get_option(self::OPTION_PROGRESS)];
        }

        as_enqueue_async_action(self::AS_HOOK, [0], self::AS_GROUP);
        return ['sync' => false, 'queued' => true];
    }

    /**
     * Сканировать страницу: вычислить изменения и поставить ТОЛЬКО изменившиеся в
     * очередь записи. $inline=true — применять сразу (синхронный фолбэк без планировщика).
     */
    public static function process_page($start, bool $inline = false): bool
    {
        $start = (int) $start;
        $size  = B2B_Settings::page_size();

        $page = B2B_Api::fetch_page($start, $size);
        if (null === $page) {
            self::log('', 'error', __('feed request failed', 'onecatalog-import'));
            self::finish();
            return false;
        }

        /** Фильтр: сырые данные страницы фида сразу после чтения (products/regions/warehouses). */
        $data    = (array) apply_filters('onecatalog_b2b_feed_data', $page['data'], $start);
        $total   = (int) ($page['meta']['counts'] ?? 0);
        $known   = (array) ($data['products']['known'] ?? []);
        $unknown = (array) ($data['products']['unknown'] ?? []);

        // Контекст фида: справочники регионов и складов — пробрасываются в хуки записи,
        // чтобы сайт мог разложить цены по регионам, а остатки по складам (напр. в ACF).
        $context = [
            'regions'    => (array) ($data['regions'] ?? []),
            'warehouses' => (array) ($data['warehouses'] ?? []),
        ];

        $cfg = self::cfg();
        $known_missing = B2B_Settings::known_missing();

        $id_map = self::map_public_ids(array_keys($known));         // public_id → product_id
        $sigs   = self::get_sigs(array_values($id_map));            // product_id → сохранённая сигнатура

        $changes   = [];
        $scanned   = 0;
        $changed   = 0;
        $unchanged = 0;
        $to_import = [];

        foreach ($known as $public_id => $offers) {
            $public_id = (string) $public_id;
            $offers    = (array) $offers;
            $scanned++;

            $product_id = $id_map[$public_id] ?? ProductImporter::find_by_public_id($public_id);
            if (! $product_id) {
                if ('import' === $known_missing) {
                    $to_import[] = $public_id;
                }
                continue;
            }

            /** Фильтр: офферы товара перед резолвом (можно отфильтровать/дополнить). */
            $offers = (array) apply_filters('onecatalog_b2b_offers', $offers, $public_id, (int) $product_id, $context);

            $rec = self::resolve_record($offers, (int) $product_id, $cfg, $context);
            if (($sigs[(int) $product_id] ?? '') === $rec['sig']) {
                $unchanged++;
                continue; // ничего не поменялось → не трогаем товар
            }

            $rec['id']     = (int) $product_id;
            $rec['codes']  = self::extract_supplier_codes($offers);
            $rec['offers'] = $offers; // сырые офферы → доступны в хуках записи (ACF и т.п.)
            $changes[]     = $rec;
            $changed++;
        }

        // Запись изменившихся: сразу (inline) или порциями в фоновую очередь.
        if ($changes) {
            if ($inline) {
                self::process_write_batch($changes, $context);
            } else {
                foreach (array_chunk($changes, self::WRITE_BATCH) as $batch) {
                    as_enqueue_async_action(self::AS_WRITE_HOOK, [$batch, $context], self::AS_GROUP);
                }
            }
        }

        if ($to_import) {
            Queue::enqueue($to_import); // ненайденные known → стандартная Wiki-очередь
        }

        if ($unknown) {
            $umode = B2B_Settings::unknown_mode(); // skip | import | stage
            foreach ($unknown as $offer) {
                self::handle_unknown((array) $offer, $cfg, $context, $umode);
            }
        }

        // Прогресс + лог сводки по странице.
        $p = (array) get_option(self::OPTION_PROGRESS, []);
        $p['scanned']       = (int) ($p['scanned'] ?? 0) + $scanned;
        $p['changed']       = (int) ($p['changed'] ?? 0) + $changed;
        $p['unchanged']     = (int) ($p['unchanged'] ?? 0) + $unchanged;
        $p['queued_import'] = (int) ($p['queued_import'] ?? 0) + count($to_import);
        $p['total']         = $total ?: (int) ($p['total'] ?? 0);
        update_option(self::OPTION_PROGRESS, $p, false);
        self::log('', 'page', sprintf(
            /* translators: 1: scanned, 2: changed, 3: unchanged */
            __('page %1$d: scanned %2$d, changed %3$d, unchanged %4$d', 'onecatalog-import'),
            $start,
            $scanned,
            $changed,
            $unchanged
        ));

        $next     = $start + $size;
        $has_more = ($scanned > 0) && ($next < ($total ?: PHP_INT_MAX));
        if ($has_more && ! $inline && Queue::available()) {
            as_enqueue_async_action(self::AS_HOOK, [$next], self::AS_GROUP);
        }
        if (! $has_more) {
            self::finish();
        }
        return $has_more;
    }

    /** Резолв итоговых значений + сигнатура (чистая логика + фильтры). */
    private static function resolve_record(array $offers, int $product_id, array $cfg, array $context = []): array
    {
        $price = self::resolve_price($offers, $cfg['region_prio'], $cfg['supplier_prio'], $cfg['strategy'], $cfg['supplier_fix'], $cfg['promo_as_sale']);
        /** Фильтр: цена B2B перед записью. */
        $price = (array) apply_filters('onecatalog_b2b_price', $price, $offers, $product_id);

        $stock = self::resolve_stock($offers);
        /** Фильтр: остаток B2B перед записью. */
        $stock = (float) apply_filters('onecatalog_b2b_stock_qty', $stock, $offers, $product_id);

        $available = self::any_available($offers) && $stock > 0;
        $manage    = (bool) $cfg['manage_stock'];
        $qty       = $manage ? ($cfg['decimal_stock'] ? $stock : (float) floor($stock)) : null;

        $rec = [
            'regular'    => $price['regular'],
            'sale'       => $price['sale'],
            'purchasing' => $price['purchasing'] ?? null,
            'manage'     => $manage,
            'qty'        => $qty,
            'stock_raw'  => $stock,
            'status'     => $available ? 'instock' : 'outofstock',
        ];
        $rec['sig'] = self::signature($rec);

        /**
         * Фильтр: сигнатура change-detection. РАСШИРЬТЕ её, если раскладываете весь
         * payload (все регионы/склады, напр. в ACF) — иначе изменения в неосновных
         * регионах/складах не вызовут обновление (scan-and-diff их пропустит).
         */
        $rec['sig'] = (string) apply_filters('onecatalog_b2b_signature', $rec['sig'], $offers, $rec, $context);
        return $rec;
    }

    // ===================== Запись (фаза 2) =====================

    /** Применить заранее посчитанные значения к товарам (единственное место save()). */
    public static function process_write_batch($records, $context = []): void
    {
        foreach ((array) $records as $rec) {
            self::apply_resolved((array) $rec, (array) $context);
        }
    }

    private static function apply_resolved(array $rec, array $context = []): void
    {
        $product_id = (int) ($rec['id'] ?? 0);
        $product    = ($product_id && function_exists('wc_get_product')) ? wc_get_product($product_id) : null;
        if (! $product) {
            return;
        }
        $offers = (array) ($rec['offers'] ?? []);

        /**
         * Экшен: перед записью товара. Сюда можно разложить цены по регионам и остатки
         * по складам в свои поля (ACF) — на руках полные офферы и карты regions/warehouses.
         */
        do_action('onecatalog_b2b_before_update', $product_id, $offers, $rec, $context);

        if (null !== $rec['regular']) {
            $product->set_regular_price((string) $rec['regular']);
            $product->set_sale_price(null !== $rec['sale'] ? (string) $rec['sale'] : '');
            if (null !== ($rec['purchasing'] ?? null)) {
                update_post_meta($product_id, self::META_PURCHASING, (string) $rec['purchasing']);
            }
        }

        if (! empty($rec['manage'])) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity((float) $rec['qty']);
            $product->set_stock_status((string) $rec['status']);
            update_post_meta($product_id, self::META_STOCK_RAW, (string) ($rec['stock_raw'] ?? $rec['qty']));
        } else {
            $product->set_manage_stock(false);
            $product->set_stock_status((string) $rec['status']);
        }

        if (isset($rec['codes'])) {
            self::store_supplier_codes($product_id, (array) $rec['codes']);
        }
        update_post_meta($product_id, self::META_SYNCED_AT, time());
        update_post_meta($product_id, self::META_SIG, (string) $rec['sig']);

        $product->save();

        /** Экшен: цена/остаток товара обновлены из B2B (полные офферы + контекст фида). */
        do_action('onecatalog_pricestock_updated', $product_id, $rec, $offers, $context);
    }

    /**
     * Unknown-оффер (без public_id). Сначала пытаемся сопоставить по коду поставщика
     * (тогда — обычное обновление с change-detection). Иначе — по режиму:
     *   import — авто-создание; stage — в отстойник (ручной отбор); skip — игнор.
     */
    private static function handle_unknown(array $offer, array $cfg, array $context, string $mode): void
    {
        $code = trim((string) ($offer['code'] ?? ''));
        if ('' === $code || ! function_exists('wc_get_product')) {
            return;
        }

        $product_id = self::find_by_supplier_code($code);
        if ($product_id) {
            $rec = self::resolve_record([$offer], $product_id, $cfg, $context);
            if ((string) get_post_meta($product_id, self::META_SIG, true) === $rec['sig']) {
                return; // без изменений
            }
            $rec['id']     = $product_id;
            $rec['codes']  = self::extract_supplier_codes([$offer]);
            $rec['offers'] = [$offer];
            self::apply_resolved($rec, $context);
            return;
        }

        if ('import' === $mode) {
            self::import_offer($offer, $cfg, $context);
        } elseif ('stage' === $mode) {
            B2B_Staging::upsert($offer); // в отстойник на ручной отбор
        }
        // 'skip' — ничего
    }

    /**
     * Создать товар из unknown-оффера и применить цену/остаток (+зафиксировать код).
     * Используется авто-режимом и ручным импортом из отстойника. Возвращает product_id.
     */
    public static function import_offer(array $offer, ?array $cfg = null, array $context = []): int
    {
        if (! function_exists('wc_get_product')) {
            return 0;
        }
        $cfg  = $cfg ?? self::cfg();
        $code = trim((string) ($offer['code'] ?? ''));
        $name = trim((string) ($offer['name'] ?? ''));
        if ('' === $code) {
            return 0;
        }

        $product_id = self::find_by_supplier_code($code);
        if (! $product_id) {
            $product = new \WC_Product_Simple();
            $product->set_name('' !== $name ? $name : $code);
            $product->set_status(Settings::product_status());
            if ('' === (string) wc_get_product_id_by_sku($code)) {
                $product->set_sku($code);
            }
            $product_id = (int) $product->save();
            if (! $product_id) {
                self::log($code, 'error', __('failed to create unknown product', 'onecatalog-import'));
                return 0;
            }
            self::log($code, 'created', '');
        }

        $rec           = self::resolve_record([$offer], $product_id, $cfg, $context);
        $rec['id']     = $product_id;
        $rec['codes']  = self::extract_supplier_codes([$offer]);
        $rec['offers'] = [$offer];
        self::apply_resolved($rec, $context);
        return $product_id;
    }

    // ===================== Хранилища / помощники =====================

    /** Карта public_id → product_id одним запросом. */
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
        $map = [];
        foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $r) {
            $map[(string) $r['pid']] = (int) $r['post_id'];
        }
        return $map;
    }

    /** Сохранённые сигнатуры product_id → sig одним запросом (для diff в памяти). */
    private static function get_sigs(array $product_ids): array
    {
        global $wpdb;
        $product_ids = array_values(array_unique(array_filter(array_map('intval', $product_ids))));
        if (! $product_ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT post_id, meta_value AS sig FROM {$wpdb->postmeta}
             WHERE meta_key = %s AND post_id IN ($placeholders)",
            array_merge([self::META_SIG], $product_ids)
        );
        $out = [];
        foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $r) {
            $out[(int) $r['post_id']] = (string) $r['sig'];
        }
        return $out;
    }

    private static function find_by_supplier_code(string $code): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            self::META_SUPPLIER_CODE,
            $code
        ));
    }

    private static function store_supplier_codes(int $product_id, array $codes): void
    {
        update_post_meta($product_id, self::META_SUPPLIER_CODES, $codes);
        delete_post_meta($product_id, self::META_SUPPLIER_CODE);
        foreach ($codes as $c) {
            if ('' !== (string) ($c['code'] ?? '')) {
                add_post_meta($product_id, self::META_SUPPLIER_CODE, (string) $c['code']);
            }
        }
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
        $p = (array) get_option(self::OPTION_PROGRESS, []);
        $p['finished'] = true;
        update_option(self::OPTION_PROGRESS, $p, false);
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
            $pending = count(as_get_scheduled_actions(['hook' => self::AS_HOOK, 'status' => 'pending', 'per_page' => 500], 'ids'))
                + count(as_get_scheduled_actions(['hook' => self::AS_WRITE_HOOK, 'status' => 'pending', 'per_page' => 500], 'ids'));
        }
        return new \WP_REST_Response([
            'pending'  => $pending,
            'progress' => (array) get_option(self::OPTION_PROGRESS, []),
            'log'      => array_slice((array) get_option(self::OPTION_LOG, []), 0, 40),
        ], 200);
    }
}
