<?php
/**
 * Клиент B2B API OneCatalog (цены и остатки ритейлера).
 *
 * Эндпоинт: /b2b/v1/retailer-share-products/{url_key}/?private_key=...
 * Авторизация ОТЛИЧАЕТСЯ от Wiki API: ключ в пути (url_key) + private_key в query
 * (это НЕ заголовок X-API-Key). Ответ:
 *   data.products.known   = { "<public_id>": [ offer, … ] }   (есть wiki-товар)
 *   data.products.unknown = [ offer, … ]                       (без wiki)
 *   data.warehouses       = { id: {name, city, …} }
 *   data.regions          = { id: {slug, menutitle} }
 *   meta.counts           = ОБЩЕЕ число; пагинация ?start&limit
 *
 * offer = { name, code(код поставщика), public_id, status,
 *           products_stocks:[{warehouse_id, quantity}],
 *           product_prices:[{region_id, base_price, promo_price, purchasing_price}],
 *           supplier:{id,name} }
 */

namespace OneCatalog\Import;

if (! defined('ABSPATH')) {
    exit;
}

final class B2B_Api
{
    public const OPTION_URL_KEY     = 'onecatalog_b2b_key';
    public const OPTION_PRIVATE_KEY = 'onecatalog_b2b_private_key';

    /** Базовый URL B2B API (env ONECATALOG_B2B_API приоритетнее). */
    public static function base(): string
    {
        $base = getenv('ONECATALOG_B2B_API') ?: 'https://api.onecatalog.net/b2b/v1';

        /** Фильтр: базовый URL B2B API. */
        return (string) apply_filters('onecatalog_b2b_api_base', rtrim($base, '/'));
    }

    /** Ключ ритейлера в пути URL (env ONECATALOG_B2B_KEY приоритетнее опции). */
    public static function url_key(): string
    {
        $env = getenv('ONECATALOG_B2B_KEY');
        $key = (false !== $env && '' !== (string) $env) ? (string) $env : (string) get_option(self::OPTION_URL_KEY, '');

        /** Фильтр: ключ ритейлера B2B (url_key). */
        return (string) apply_filters('onecatalog_b2b_url_key', trim($key));
    }

    /** Секретный private_key в query (env ONECATALOG_B2B_PRIVATE_KEY приоритетнее опции). */
    public static function private_key(): string
    {
        $env = getenv('ONECATALOG_B2B_PRIVATE_KEY');
        $key = (false !== $env && '' !== (string) $env) ? (string) $env : (string) get_option(self::OPTION_PRIVATE_KEY, '');

        /** Фильтр: private_key B2B. */
        return (string) apply_filters('onecatalog_b2b_private_key', trim($key));
    }

    /** Заданы ли обе части доступа. */
    public static function configured(): bool
    {
        return '' !== self::url_key() && '' !== self::private_key();
    }

    /**
     * Получить страницу фида (start/limit). Возвращает полный декодированный ответ
     * (с ключами data/meta) или null при ошибке (§5.5 — деградация без падений).
     */
    public static function fetch_page(int $start = 0, int $limit = 200): ?array
    {
        if (! self::configured()) {
            return null;
        }
        $url = self::base() . '/retailer-share-products/' . rawurlencode(self::url_key()) . '/';
        $url = add_query_arg(
            [
                'private_key' => self::private_key(),
                'start'       => max(0, $start),
                'limit'       => max(1, $limit),
            ],
            $url
        );

        $args = ['timeout' => 60];
        /** Фильтр: аргументы wp_remote_get для B2B-запросов. */
        $args = (array) apply_filters('onecatalog_b2b_request_args', $args, $url);

        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return null;
        }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (! is_array($json) || empty($json['success']) || ! isset($json['data']) || ! is_array($json['data'])) {
            return null;
        }
        return $json;
    }

    /** Общее число товаров в фиде (meta.counts) — для пагинации. 0 при ошибке. */
    public static function total(): int
    {
        $page = self::fetch_page(0, 1);
        return $page ? (int) ($page['meta']['counts'] ?? 0) : 0;
    }

    /**
     * Разведка справочников для настроек: регионы, склады, поставщики.
     * Регионы/склады — из верхнего уровня ответа; поставщики — агрегируются из офферов
     * первой страницы (limit высокий). Возвращает
     *   ['regions'=>[id=>label], 'warehouses'=>[id=>label], 'suppliers'=>[id=>name]]
     * или null при ошибке.
     */
    public static function discover(int $scan = 500): ?array
    {
        $page = self::fetch_page(0, $scan);
        if (null === $page) {
            return null;
        }
        $data = $page['data'];

        $regions = [];
        foreach ((array) ($data['regions'] ?? []) as $id => $r) {
            $regions[(int) $id] = (string) ($r['menutitle'] ?? $r['slug'] ?? ('#' . $id));
        }
        $warehouses = [];
        foreach ((array) ($data['warehouses'] ?? []) as $id => $w) {
            $label = trim((string) ($w['name'] ?? '') . (empty($w['city']) ? '' : ' (' . $w['city'] . ')'));
            $warehouses[(int) $id] = $label !== '' ? $label : ('#' . $id);
        }

        $suppliers = [];
        $collect = static function (array $offers) use (&$suppliers): void {
            foreach ($offers as $offer) {
                $sid = (int) ($offer['supplier']['id'] ?? 0);
                if ($sid > 0 && ! isset($suppliers[$sid])) {
                    $suppliers[$sid] = (string) ($offer['supplier']['name'] ?? ('#' . $sid));
                }
            }
        };
        foreach ((array) ($data['products']['known'] ?? []) as $offers) {
            $collect((array) $offers);
        }
        $collect((array) ($data['products']['unknown'] ?? []));

        return ['regions' => $regions, 'warehouses' => $warehouses, 'suppliers' => $suppliers];
    }
}
