<?php
/**
 * Plugin Name: OneCatalog B2B — regional prices & per-warehouse stock (ACF example)
 * Description: Пример сайтового слоя поверх OneCatalog Import: раскладывает цены B2B-фида
 *              по регионам и остатки по складам в ACF-поля товара. Скопируйте в
 *              wp-content/mu-plugins/ и адаптируйте имена ACF-полей под свой шаблон.
 *
 * Как это работает
 * ----------------
 * Базовый плагин (PriceStockSync) по принципу scan-and-diff пишет в товар ОДНУ цену
 * (regular/sale) и ОДИН суммарный остаток. Всё остальное из фида он отдаёт в хуки —
 * здесь мы берём СЫРЫЕ офферы и справочники регионов/складов и кладём их в свои поля.
 *
 * Важно про change-detection
 * --------------------------
 * Синк обновляет товар, только если изменилась его СИГНАТУРА (по умолчанию — основная
 * цена + остаток + статус). Раз мы раскладываем ВЕСЬ payload (все регионы и склады),
 * нужно РАСШИРИТЬ сигнатуру — иначе изменение цены в неосновном регионе или остатка на
 * неосновном складе не вызовет обновление, и ACF-поля останутся старыми. Это делает
 * фильтр `onecatalog_b2b_signature` ниже.
 *
 * Используемые хуки
 * -----------------
 *  - filter onecatalog_b2b_signature ($sig, $offers, $rec, $context)  — расширить change-detection
 *  - action onecatalog_b2b_before_update ($product_id, $offers, $rec, $context) — запись своих полей
 *  - (есть ещё: filter onecatalog_b2b_feed_data, onecatalog_b2b_offers,
 *               onecatalog_b2b_price, onecatalog_b2b_stock_qty;
 *     action onecatalog_pricestock_updated — после сохранения товара)
 *
 * Структура оффера (из фида):
 *   $offer['product_prices']   = [ ['region_id'=>int,'base_price'=>float,'promo_price'=>float,'purchasing_price'=>?float], … ]
 *   $offer['products_stocks']  = [ ['warehouse_id'=>int,'quantity'=>float], … ]
 *   $offer['supplier']         = ['id'=>int,'name'=>string]
 * $context['regions']    = [ region_id => ['slug'=>…,'menutitle'=>…] ]
 * $context['warehouses'] = [ warehouse_id => ['name'=>…,'city'=>…, …] ]
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Свести офферы в поля по регионам/складам.
 *
 * Возвращает:
 *   prices[region_id] = ['base'=>min base по региону, 'promo'=>min promo>0 по региону]
 *   stocks[warehouse_id] = сумма quantity по складу (по всем поставщикам)
 *
 * @param array $offers
 * @return array{prices: array<int,array{base:float,promo:?float}>, stocks: array<int,float>}
 */
function oc_b2b_breakdown(array $offers): array
{
    $prices = [];
    $stocks = [];

    foreach ($offers as $offer) {
        foreach ((array) ($offer['product_prices'] ?? []) as $pr) {
            $rid  = (int) ($pr['region_id'] ?? 0);
            $base = (float) ($pr['base_price'] ?? 0);
            if ($rid <= 0 || $base <= 0) {
                continue;
            }
            $promo = (float) ($pr['promo_price'] ?? 0);
            // Несколько поставщиков в одном регионе → берём минимальную цену.
            if (! isset($prices[$rid]) || $base < $prices[$rid]['base']) {
                $prices[$rid] = [
                    'base'  => $base,
                    'promo' => ($promo > 0 && $promo < $base) ? $promo : null,
                ];
            }
        }
        foreach ((array) ($offer['products_stocks'] ?? []) as $st) {
            $wid = (int) ($st['warehouse_id'] ?? 0);
            if ($wid > 0) {
                $stocks[$wid] = ($stocks[$wid] ?? 0.0) + (float) ($st['quantity'] ?? 0);
            }
        }
    }

    return ['prices' => $prices, 'stocks' => $stocks];
}

/**
 * 1) Расширяем сигнатуру change-detection всем содержимым по регионам/складам,
 *    чтобы изменения в любом регионе/складе вызывали обновление товара.
 */
add_filter('onecatalog_b2b_signature', static function (string $sig, array $offers): string {
    $b = oc_b2b_breakdown($offers);
    ksort($b['prices']);
    ksort($b['stocks']);
    return md5($sig . '|' . wp_json_encode($b));
}, 10, 2);

/**
 * 2) Перед сохранением товара пишем цены по регионам и остатки по складам в ACF.
 *    Имена полей — ПОДставьте свои. Здесь шаблон:
 *      ACF: oc_price_region_{region_id}, oc_promo_region_{region_id}, oc_stock_wh_{warehouse_id}
 */
add_action('onecatalog_b2b_before_update', static function (int $product_id, array $offers, array $rec, array $context): void {
    if (! function_exists('update_field')) {
        return; // ACF не активен
    }
    $b = oc_b2b_breakdown($offers);

    // Цены по регионам.
    foreach (($context['regions'] ?? []) as $region_id => $region) {
        $region_id = (int) $region_id;
        $p = $b['prices'][$region_id] ?? null;
        update_field('oc_price_region_' . $region_id, $p['base'] ?? '', $product_id);
        update_field('oc_promo_region_' . $region_id, $p['promo'] ?? '', $product_id);
    }

    // Остатки по складам.
    foreach (($context['warehouses'] ?? []) as $warehouse_id => $warehouse) {
        $warehouse_id = (int) $warehouse_id;
        update_field('oc_stock_wh_' . $warehouse_id, $b['stocks'][$warehouse_id] ?? 0, $product_id);
    }

    // Вариант через repeater-поле (если вместо отдельных полей — таблица):
    // $rows = [];
    // foreach ($b['prices'] as $rid => $p) {
    //     $rows[] = [
    //         'region'    => ($context['regions'][$rid]['menutitle'] ?? $rid),
    //         'price'     => $p['base'],
    //         'promo'     => $p['promo'],
    //     ];
    // }
    // update_field('oc_regional_prices', $rows, $product_id);
}, 10, 4);
