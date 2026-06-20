<?php
/**
 * Точка сборки плагина: инициализация модулей и одноразовая миграция
 * настроек/данных с ранних версий.
 */

namespace OneCatalog\Import;

if (! defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private const OPTION_MIGRATED = 'onecatalog_migrated';

    public static function init(): void
    {
        Queue::init();
        Rest::init();
        Settings::init();
        Import_Log::init();
        B2B_Settings::init();
        B2B_Staging::init();
        PriceStockSync::init();
        Supplier_Codes_Field::init();
        AdminUI::init();
        Admin_Product_Column::init();

        add_action('init', [self::class, 'load_textdomain']);
        add_action('init', [self::class, 'maybe_migrate']);
        add_filter('plugin_row_meta', [self::class, 'plugin_row_meta'], 10, 2);
        add_filter('plugin_action_links_' . plugin_basename(ONECATALOG_IMPORT_FILE), [self::class, 'action_links']);
    }

    /** Переводы плагина (languages/onecatalog-import-{locale}.mo). */
    public static function load_textdomain(): void
    {
        load_plugin_textdomain(
            'onecatalog-import',
            false,
            dirname(plugin_basename(ONECATALOG_IMPORT_FILE)) . '/languages'
        );
    }

    /** Строка плагина в списке: ссылка на документацию. */
    public static function plugin_row_meta(array $links, string $file): array
    {
        if (plugin_basename(ONECATALOG_IMPORT_FILE) === $file) {
            $links[] = '<a href="' . esc_url(Settings::docs_url()) . '" target="_blank" rel="noopener">'
                . esc_html__('Documentation', 'onecatalog-import') . '</a>';
        }
        return $links;
    }

    /** Быстрая ссылка «Настройки» в списке плагинов. */
    public static function action_links(array $links): array
    {
        array_unshift(
            $links,
            '<a href="' . esc_url(admin_url('admin.php?page=onecatalog')) . '">'
            . esc_html__('Settings', 'onecatalog-import') . '</a>'
        );
        return $links;
    }

    /** Перенос опций с ранних версий плагина (ceram_onecatalog_*). */
    public static function maybe_migrate(): void
    {
        if (get_option(self::OPTION_MIGRATED)) {
            return;
        }
        $map = [
            'ceram_onecatalog_token' => Api::OPTION_TOKEN,
            'ceram_onecatalog_step'  => Queue::OPTION_STEP,
            'ceram_onecatalog_lang'  => Api::OPTION_LANG,
            'ceram_onecatalog_log'   => Queue::OPTION_LOG,
        ];
        foreach ($map as $old => $new) {
            $value = get_option($old, null);
            if (null !== $value && false === get_option($new, false)) {
                update_option($new, $value, false);
            }
            delete_option($old);
        }
        update_option(self::OPTION_MIGRATED, ONECATALOG_IMPORT_VERSION, false);
    }
}
