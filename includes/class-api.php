<?php
/**
 * Клиент Wiki API OneCatalog: базовый URL, токен, язык, GET-запросы.
 */

namespace OneCatalog\Import;

if (! defined('ABSPATH')) {
    exit;
}

final class Api
{
    public const OPTION_TOKEN = 'onecatalog_token';
    public const OPTION_LANG  = 'onecatalog_lang';

    /** Базовый URL Wiki API (env ONECATALOG_API/CERAPLAT_API приоритетнее). */
    public static function base(): string
    {
        $base = getenv('ONECATALOG_API') ?: (getenv('CERAPLAT_API') ?: 'https://api.onecatalog.net/wiki/v1');

        /** Фильтр: базовый URL Wiki API. */
        return (string) apply_filters('onecatalog_api_base', rtrim($base, '/'));
    }

    /** Токен X-API-Key (env ONECATALOG_TOKEN/CERAPLAT_TOKEN приоритетнее опции). */
    public static function token(): string
    {
        $env = getenv('ONECATALOG_TOKEN') ?: getenv('CERAPLAT_TOKEN');
        $token = (false !== $env && '' !== (string) $env)
            ? (string) $env
            : (string) get_option(self::OPTION_TOKEN, '');

        /** Фильтр: токен Wiki API. */
        return (string) apply_filters('onecatalog_api_token', $token);
    }

    /**
     * Поддерживаемые API языки локализации (из OpenAPI-спеки Wiki API).
     */
    public static function languages(): array
    {
        /** Фильтр: список языков в настройках (код => подпись). */
        return (array) apply_filters('onecatalog_languages', [
            'en' => 'English',
            'ru' => 'Русский',
            'ar' => 'العربية',
            'zh' => '中文',
            'kk' => 'Қазақша',
        ]);
    }

    /** Язык загружаемых данных (по умолчанию английский). */
    public static function lang(): string
    {
        $lang = (string) get_option(self::OPTION_LANG, 'en');

        /** Фильтр: язык запросов к Wiki API. */
        return (string) apply_filters('onecatalog_lang', $lang ?: 'en');
    }

    /**
     * Справочник характеристик (/specifications/) → [ ['id'=>int,'label'=>string,'slug'=>string], … ].
     * Кэшируется в транзиенте на язык (6 ч). $force — принудительно перезапросить.
     */
    public static function specifications(bool $force = false): array
    {
        $cache_key = 'onecatalog_specs_' . self::lang();
        if (! $force) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }
        // Список характеристик небольшой — забираем ВСЕ одним запросом с заведомо высоким
        // лимитом (без него инстанс мог бы обрезать выдачу дефолтным limit).
        $endpoint = self::base() . '/specifications/';
        $resp = self::get(add_query_arg('limit', 1000, $endpoint));
        $data = (is_array($resp) && isset($resp['data']) && is_array($resp['data'])) ? $resp['data'] : null;
        if (null === $data) {
            return []; // неудачу не кэшируем — попробуем при следующем рендере
        }

        // Подстраховка: если инстанс всё же ограничил страницу меньше общего числа
        // (meta.counts), дочитываем оставшиеся страницами. В норме цикл не выполняется.
        $total = (int) ($resp['meta']['counts'] ?? count($data));
        $guard = 0;
        while (count($data) < $total && $guard++ < 50) {
            $more  = self::get(add_query_arg(['start' => count($data), 'limit' => 1000], $endpoint));
            $chunk = (is_array($more) && isset($more['data']) && is_array($more['data'])) ? $more['data'] : [];
            if (! $chunk) {
                break; // больше ничего не отдаёт — выходим, чтобы не зациклиться
            }
            $data = array_merge($data, $chunk);
        }

        $specs = [];
        foreach ($data as $row) {
            $id    = (int) ($row['id'] ?? 0);
            $label = trim((string) ($row['label'] ?? ''));
            if ($id > 0 && '' !== $label) {
                $specs[] = [
                    'id'    => $id,
                    'label' => $label,
                    'slug'  => (string) ($row['slug'] ?? ''),
                    'type'  => (string) ($row['specification_type'] ?? 'text'), // text|boolean|numeric
                ];
            }
        }
        set_transient($cache_key, $specs, 6 * HOUR_IN_SECONDS);

        /** Фильтр: справочник характеристик OneCatalog. */
        return (array) apply_filters('onecatalog_specifications', $specs);
    }

    /** GET к Wiki API: lang добавляется ко всем запросам, токен — заголовком X-API-Key. */
    public static function get(string $url): ?array
    {
        $url  = add_query_arg('lang', self::lang(), $url);
        $args = ['timeout' => 40];

        $token = self::token();
        if ('' !== $token) {
            $args['headers'] = ['X-API-Key' => $token];
        }

        /** Фильтр: аргументы wp_remote_get (заголовки, таймаут и т.д.). */
        $args = (array) apply_filters('onecatalog_api_request_args', $args, $url);

        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return null;
        }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($json) ? $json : null;
    }
}
