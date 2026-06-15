<?php
/**
 * REST API плагина (namespace onecatalog/v1):
 *   POST /import        {ids:[…]} — поставить идентификаторы в очередь
 *   GET  /import-status            — статус очереди + последние результаты
 */

namespace OneCatalog\Import;

use WP_Error;
use WP_REST_Request;

if (! defined('ABSPATH')) {
    exit;
}

final class Rest
{
    public const NS = 'onecatalog/v1';

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void
    {
        register_rest_route(self::NS, '/import', [
            'methods'             => 'POST',
            'permission_callback' => static fn () => current_user_can('manage_woocommerce'),
            'args'                => [
                'ids' => ['required' => true, 'type' => 'array'],
            ],
            'callback'            => [self::class, 'handle_import'],
        ]);

        register_rest_route(self::NS, '/import-status', [
            'methods'             => 'GET',
            'permission_callback' => static fn () => current_user_can('manage_woocommerce'),
            'callback'            => static fn () => Queue::status(),
        ]);
    }

    public static function handle_import(WP_REST_Request $request)
    {
        if (! function_exists('wc_get_product')) {
            return new WP_Error('no_wc', __('WooCommerce is not active', 'onecatalog-import'), ['status' => 500]);
        }
        $ids = (array) $request->get_param('ids');
        $ids = array_values(array_filter(array_map(static fn ($v) => trim((string) $v), $ids)));
        if (! $ids) {
            return new WP_Error('no_ids', __('Empty ID list', 'onecatalog-import'), ['status' => 400]);
        }
        $ids = array_slice($ids, 0, ONECATALOG_MAX_BATCH);

        $result = Queue::enqueue($ids);
        if (! empty($result['sync'])) {
            $result['imported'] = count(array_filter(
                $result['results'],
                static fn ($r) => in_array($r['status'], ['created', 'updated'], true)
            ));
        }
        return $result;
    }
}
