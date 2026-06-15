# OneCatalog Import

Документация OneCatalog: **https://docs.onecatalog.net/**

Архитектура и платформо-независимый стандарт (для портирования на другие CMS — Bitrix24/PrestaShop/…): **[OneCatalog/onecatalog-standard](https://github.com/OneCatalog/onecatalog-standard)** (эта реализация — эталонная)

История изменений и бэклог: **[CHANGELOG.md](CHANGELOG.md)** · текущая версия — **1.7.0**

Импорт товаров из OneCatalog (Wiki API) в WooCommerce: модалка выбора товаров
(OneCatalog Picker) на странице «Товары», фоновая очередь импорта, зависимости
(категории, характеристики `pa_*`, коллекции CPT, бренды `product_brand`),
изображения с трекингом качества, раздельные артикулы.

## Структура

```
onecatalog-import/
├── onecatalog-import.php                 — бутстрап (константы, подключение модулей)
├── includes/
│   ├── class-plugin.php                  — сборка модулей, миграция настроек
│   ├── class-api.php                     — клиент Wiki API (URL, токен, язык, GET, справочник характеристик)
│   ├── class-units.php                   — универсальный конвертер единиц (вес/размеры → единицы магазина)
│   ├── class-media.php                   — изображения: размер, sideload, обложка, галерея, дедуп
│   ├── class-taxonomies.php              — атрибуты pa_* (поиск по имени), термы, категории
│   ├── class-product-importer.php        — импорт товара (SKU/public_id, зависимости, оркестратор)
│   ├── class-collection-importer.php     — коллекция → атрибут / таксономия / CPT + поля
│   ├── class-brand-importer.php          — бренд → атрибут / таксономия / CPT
│   ├── class-country-importer.php        — страна → атрибут / таксономия
│   ├── class-queue.php                   — фоновая очередь (Action Scheduler), лог
│   ├── class-rest.php                    — REST: /import, /import-status
│   ├── class-settings.php                — страница настроек (токен, шаг, язык)
│   └── class-admin-ui.php                — кнопка на «Товарах», поле public_id у товара
└── assets/
    ├── picker-loader.js                  — лоадер модалки OneCatalog Picker
    └── admin-import.js                   — UI импорта на странице «Товары»
```

Неймспейс PHP: `OneCatalog\Import`.

## Где что в админке

- **Импорт**: Товары → кнопка «OneCatalog(Товары)» (после «Экспорт»). Выбранные
  в модалке товары ставятся в фоновую очередь, прогресс — в notice над списком.
- **Настройки**: меню «OneCatalog» (`admin.php?page=onecatalog`) — токен Wiki API,
  шаг импорта (минимум 10 товаров за проход очереди), язык данных
  (en/ru/ar/zh/kk; по умолчанию английский), **коллекции**: импортировать ли,
  хранить как **post type** или **таксономию**, и выбор конкретной цели.
- **Артикулы**: родное поле SKU — только артикул производителя (`article` из API);
  идентификатор OneCatalog — отдельное поле «Артикул OneCatalog»
  (мета `_onecatalog_public_id`, вкладка «Запасы» под SKU); по нему товар
  обновляется при повторном импорте.
- **Изображения**: обложка + галерея; качество фиксируется
  (`_onecatalog_image_quality`, `_onecatalog_gallery`); при появлении токена
  повторный импорт автоматически заменяет файлы лучшим качеством.

## Коллекции

Импорт коллекций настраивается:

- **Импортировать коллекции** — включить/выключить.
- **Хранить как** — `post type` (запись CPT) или `taxonomy` (термин) + выбор
  конкретного post type / таксономии.

Сохраняемые поля коллекции (из payload товара OneCatalog):

| Поле / мета | Источник |
|---|---|
| заголовок / название термина | `menutitle` |
| slug | `slug` |
| `_onecatalog_collection_id` | `id` (ID в OneCatalog) |
| `_onecatalog_link_3d` | `link_3d` |
| `_onecatalog_link_official_site` | `link_official_site` |
| обложка | `images_urls` → featured image (post) / `_onecatalog_cover_id`+`_onecatalog_cover_url` (term) |

В режиме post type связь товар→коллекция — мета `_onecatalog_collection`
(ID записи); в режиме taxonomy товар привязывается термином.

## REST

| Метод | Маршрут | Описание |
|---|---|---|
| POST | `/wp-json/onecatalog/v1/import` `{ids:[…]}` | поставить идентификаторы в очередь; ответ `{queued, batches, step}`; без Action Scheduler — синхронно `{sync:true, results}` |
| GET | `/wp-json/onecatalog/v1/import-status` | статус очереди `{pending, running, log[30]}` |

Права: `manage_woocommerce` + nonce `wp_rest`.

## Хуки для разработчиков

### Фильтры

| Фильтр | Аргументы | Назначение |
|---|---|---|
| `onecatalog_api_base` | `string $base` | базовый URL Wiki API |
| `onecatalog_api_token` | `string $token` | токен X-API-Key |
| `onecatalog_api_request_args` | `array $args, string $url` | аргументы `wp_remote_get` |
| `onecatalog_lang` | `string $lang` | язык запросов (`lang=`) |
| `onecatalog_languages` | `array $langs` | список языков в настройках |
| `onecatalog_import_step` | `int $step` | размер порции очереди |
| `onecatalog_product_payload` | `array $p` | payload товара перед импортом |
| `onecatalog_product_sku` | `string $sku, array $p, int $existing_id` | SKU (пусто — не задавать) |
| `onecatalog_product_price` | `null\|string $price, array $p, int $existing_id` | цена товара (`null`/`''` — не задавать; в Wiki API цен нет) |
| `onecatalog_source_dimension_unit` | `string $unit, array $p` | единица длины источника (по умолчанию `mm`) |
| `onecatalog_source_weight_unit` | `string $unit, array $p` | единица веса источника (по умолчанию `g`) |
| `onecatalog_image_size_order` | `array $order` | порядок размеров изображений |
| `onecatalog_media_dedup` | `bool $enabled` | дедупликация медиа по контент-ключу |
| `onecatalog_gallery_files` | `array $files, int $product_id, array $p` | файлы галереи перед загрузкой |
| `onecatalog_picker_base` | `string $origin` | origin виджета выбора товаров |

### Экшены

| Экшен | Аргументы | Момент |
|---|---|---|
| `onecatalog_queue_enqueued` | `array $ids, int $batches, int $step` | идентификаторы поставлены в очередь |
| `onecatalog_before_import_product` | `array $p, int $existing_id` | перед импортом товара |
| `onecatalog_product_imported` | `int $product_id, array $p, array $report` | товар импортирован (полный payload `$p` — дозаполняйте остальные поля здесь) |
| `onecatalog_collection_imported` | `int $object_id, array $c, array $result` | коллекция создана/найдена (`$result`: `id`,`type`=post_type\|taxonomy,`title`) |
| `onecatalog_brand_imported` | `int $object_id, array $b, array $result` | бренд назначен |
| `onecatalog_country_imported` | `int $object_id, array $c, array $result` | страна назначена |

### Пример

```php
// Свой таймаут API и пометка импортированных товаров.
add_filter('onecatalog_api_request_args', fn ($args) => array_merge($args, ['timeout' => 90]));

add_action('onecatalog_product_imported', function (int $product_id, array $p, array $report) {
    update_post_meta($product_id, '_my_imported_at', time());
}, 10, 3);
```

## Очередь

Работает на Action Scheduler (поставляется с WooCommerce): порции по «шагу
импорта» (минимум 10) выполняются фоном (WP-Cron/async runner). CLI-обработка
вручную: `wp action-scheduler run --hooks=onecatalog_import_batch`. Лог последних
100 результатов — опция `onecatalog_import_log`.

## Локализация (i18n)

Плагин мультиязычный: исходные строки — английские, переводы в `languages/`
(text domain `onecatalog-import`). В комплекте русский перевод
(`onecatalog-import-ru_RU.po/.mo`). Пересборка после правок:
`wp i18n make-mo languages/`. Строки админ-JS передаются через
`wp_localize_script` и переводятся вместе с PHP.

## Интеграция с темой/сайтом

Плагин не пишет никаких оформительских мета-полей сайта. Подключайтесь к
`onecatalog_product_imported` / `onecatalog_collection_imported`, чтобы заполнить
свои поля (пример — mu-plugin сайта Ceram Online).
