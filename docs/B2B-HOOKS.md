# Хуки синхронизации цен и остатков (B2B)

Точки расширения модуля `PriceStockSync`. Готовый пример раскладки цен по регионам и
остатков по складам в ACF — [`b2b-regional-pricing-example.php`](b2b-regional-pricing-example.php).

> ⚠️ Архитектура **scan-and-diff**: товар обновляется, только если изменилась его
> **сигнатура** (по умолчанию — основная цена + остаток + статус). Если вы раскладываете
> весь payload (все регионы/склады), **расширьте сигнатуру** фильтром
> `onecatalog_b2b_signature`, иначе изменения в неосновных регионах/складах не вызовут
> запись и ваши поля останутся старыми.

## Фильтры

| Фильтр | Аргументы | Назначение |
|---|---|---|
| `onecatalog_b2b_api_base` | `string $base` | базовый URL B2B API |
| `onecatalog_b2b_url_key` | `string $key` | ключ ритейлера (путь) |
| `onecatalog_b2b_private_key` | `string $key` | private_key (query) |
| `onecatalog_b2b_request_args` | `array $args, string $url` | аргументы `wp_remote_get` |
| `onecatalog_b2b_feed_data` | `array $data, int $start` | **сырые данные страницы фида** сразу после чтения (`products`/`regions`/`warehouses`) |
| `onecatalog_b2b_offers` | `array $offers, string $public_id, int $product_id, array $context` | офферы товара перед резолвом |
| `onecatalog_b2b_price` | `array $price, array $offers, int $product_id` | итоговая цена (`regular`/`sale`/`purchasing`) перед записью |
| `onecatalog_b2b_stock_qty` | `float $qty, array $offers, int $product_id` | итоговое количество остатка |
| `onecatalog_b2b_signature` | `string $sig, array $offers, array $rec, array $context` | **сигнатура change-detection** — расширьте, если раскладываете весь payload |

## Экшены

| Экшен | Аргументы | Момент |
|---|---|---|
| `onecatalog_b2b_before_update` | `int $product_id, array $offers, array $rec, array $context` | **перед** записью товара (здесь пишите свои поля — ACF и т.п.) |
| `onecatalog_pricestock_updated` | `int $product_id, array $rec, array $offers, array $context` | **после** сохранения товара |

## Структуры данных

```php
// $offers — массив офферов поставщиков по одному товару:
$offer = [
    'name' => string, 'code' => string /* код поставщика */, 'public_id' => string, 'status' => bool,
    'supplier_id' => int,  // поставщик — id; имя в $context['suppliers'][id]['name']
    'product_prices'  => [ ['region_id'=>int,'base_price'=>float,'promo_price'=>?float,'purchasing_price'=>?float], … ],
    'products_stocks' => [ ['warehouse_id'=>int,'quantity'=>float], … ],
];
// (старый формат API — supplier=>['id','name'] внутри оффера — тоже поддерживается)

// $rec — что плагин записал в товар:
$rec = ['id'=>int,'regular'=>?float,'sale'=>?float,'purchasing'=>?float,
        'manage'=>bool,'qty'=>?float,'stock_raw'=>float,'status'=>'instock'|'outofstock',
        'sig'=>string,'codes'=>[['supplier_id'=>int,'code'=>string], …],'offers'=>[…] ];

// $context — справочники фида:
$context = [
    'regions'    => [ region_id => ['slug'=>string,'menutitle'=>string] ],
    'warehouses' => [ warehouse_id => ['name'=>string,'city'=>string,'address'=>?string] ],
    'suppliers'  => [ supplier_id => ['id'=>int,'name'=>string] ],
];
```

## Минимальный пример

```php
// Своё поле «цена в регионе 1» + остаток на складе 2.
add_filter('onecatalog_b2b_signature', function ($sig, $offers) {
    return md5($sig . wp_json_encode($offers)); // учитывать весь payload в diff
}, 10, 2);

add_action('onecatalog_b2b_before_update', function ($product_id, $offers, $rec, $context) {
    foreach ($offers as $o) {
        foreach ($o['product_prices'] as $pr) {
            if ((int) $pr['region_id'] === 1) {
                update_post_meta($product_id, '_price_region_1', (float) $pr['base_price']);
            }
        }
    }
}, 10, 4);
```

Подробный рабочий пример (все регионы + все склады, ACF, агрегация по нескольким
поставщикам) — в [`b2b-regional-pricing-example.php`](b2b-regional-pricing-example.php).
