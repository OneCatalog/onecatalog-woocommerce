<?php
/**
 * Plugin Name: OneCatalog Import
 * Description: Import products from OneCatalog (Wiki API) into WooCommerce: product picker modal, background queue, dependencies (categories, attributes, collections, brands), images with quality tracking, separate article numbers.
 * Version: 1.8.0
 * Author: OneCatalog
 * Author URI: https://docs.onecatalog.net/
 * Text Domain: onecatalog-import
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (! defined('ABSPATH')) {
    exit;
}

define('ONECATALOG_IMPORT_VERSION', '1.8.0');
define('ONECATALOG_IMPORT_FILE', __FILE__);
define('ONECATALOG_IMPORT_DIR', __DIR__);
define('ONECATALOG_MAX_BATCH', 100);

require_once __DIR__ . '/includes/class-api.php';
require_once __DIR__ . '/includes/class-units.php';
require_once __DIR__ . '/includes/class-media.php';
require_once __DIR__ . '/includes/class-taxonomies.php';
require_once __DIR__ . '/includes/class-collection-importer.php';
require_once __DIR__ . '/includes/class-brand-importer.php';
require_once __DIR__ . '/includes/class-country-importer.php';
require_once __DIR__ . '/includes/class-product-importer.php';
require_once __DIR__ . '/includes/class-queue.php';
require_once __DIR__ . '/includes/class-rest.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-import-log.php';
require_once __DIR__ . '/includes/class-b2b-api.php';
require_once __DIR__ . '/includes/class-b2b-settings.php';
require_once __DIR__ . '/includes/class-b2b-staging.php';
require_once __DIR__ . '/includes/class-price-stock-sync.php';
require_once __DIR__ . '/includes/class-supplier-codes-field.php';
require_once __DIR__ . '/includes/class-admin-ui.php';
require_once __DIR__ . '/includes/class-admin-product-column.php';
require_once __DIR__ . '/includes/class-plugin.php';

OneCatalog\Import\Plugin::init();

// Создать таблицу отстойника при активации плагина.
register_activation_hook(__FILE__, static function () {
    OneCatalog\Import\B2B_Staging::install();
});

// Снять авто-расписание B2B-синка при деактивации плагина.
register_deactivation_hook(__FILE__, static function () {
    OneCatalog\Import\PriceStockSync::reschedule(false, 0);
});
