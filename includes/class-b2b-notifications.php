<?php
/**
 * Уведомления B2B-синка: баннер в админке и письма администратору при изменении
 * состава фида (новые регионы/поставщики/склады) и при ошибке выгрузки.
 */

namespace OneCatalog\Import;

if (! defined('ABSPATH')) {
    exit;
}

final class B2B_Notifications
{
    public const OPTION_NOTIFIED_HASH = 'onecatalog_b2b_notified_hash'; // антидубль письма об изменениях
    public const OPTION_ERR_NOTIFIED  = 'onecatalog_b2b_err_notified';  // ts последнего письма об ошибке
    private const ERR_THROTTLE = HOUR_IN_SECONDS;

    public static function init(): void
    {
        add_action('admin_notices', [self::class, 'admin_notice']);
    }

    /** Баннер: «фид изменился — есть новые ненастроенные элементы». */
    public static function admin_notice(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }
        $new = B2B_Settings::new_items();
        if (! $new) {
            return;
        }
        $parts = [];
        $labels = [
            'regions'    => __('regions', 'onecatalog-import'),
            'suppliers'  => __('suppliers', 'onecatalog-import'),
            'warehouses' => __('warehouses', 'onecatalog-import'),
        ];
        foreach ($new as $type => $items) {
            $parts[] = ($labels[$type] ?? $type) . ': ' . count($items);
        }
        $url = admin_url('admin.php?page=onecatalog-pricestock');
        echo '<div class="notice notice-warning"><p>';
        echo '<strong>OneCatalog:</strong> ';
        echo esc_html__('The B2B feed structure changed — there are new, not-yet-configured items', 'onecatalog-import');
        echo ' (' . esc_html(implode('; ', $parts)) . '). ';
        echo '<a href="' . esc_url($url) . '">' . esc_html__('Review “Prices & stock” settings', 'onecatalog-import') . '</a>.';
        echo '</p></div>';
    }

    /** Письмо при изменении состава фида (один раз на новый состав). */
    public static function maybe_notify_change(): void
    {
        if (! B2B_Settings::notify_enabled()) {
            return;
        }
        $new = B2B_Settings::new_items();
        if (! $new) {
            return;
        }
        // Хеш набора новых id — чтобы не слать письмо повторно на тот же состав.
        $sig = [];
        foreach ($new as $type => $items) {
            $sig[$type] = array_keys($items);
        }
        $hash = md5((string) wp_json_encode($sig));
        if (get_option(self::OPTION_NOTIFIED_HASH) === $hash) {
            return;
        }

        $lines = [];
        foreach ($new as $type => $items) {
            foreach ($items as $id => $label) {
                $lines[] = '- [' . $type . ' #' . $id . '] ' . $label;
            }
        }
        $body = __('The OneCatalog B2B feed structure has changed. New items that are not configured yet (e.g. price region / supplier priority):', 'onecatalog-import')
            . "\n\n" . implode("\n", $lines)
            . "\n\n" . __('They are not applied automatically. Open “Prices & stock” and set up the priorities:', 'onecatalog-import')
            . "\n" . admin_url('admin.php?page=onecatalog-pricestock');

        if (self::send(__('OneCatalog: B2B feed changed — review needed', 'onecatalog-import'), $body)) {
            update_option(self::OPTION_NOTIFIED_HASH, $hash, false);
        }
    }

    /** Письмо при ошибке выгрузки (с троттлингом). */
    public static function maybe_error_email(string $message): void
    {
        if (! B2B_Settings::notify_enabled()) {
            return;
        }
        $last = (int) get_option(self::OPTION_ERR_NOTIFIED, 0);
        if ((time() - $last) < self::ERR_THROTTLE) {
            return;
        }
        $body = __('A OneCatalog B2B price/stock sync run failed.', 'onecatalog-import')
            . "\n\n" . __('Error:', 'onecatalog-import') . ' ' . $message
            . "\n\n" . __('Check the connection keys and the feed:', 'onecatalog-import')
            . "\n" . admin_url('admin.php?page=onecatalog-b2b-log');

        if (self::send(__('OneCatalog: B2B sync failed', 'onecatalog-import'), $body)) {
            update_option(self::OPTION_ERR_NOTIFIED, time(), false);
        }
    }

    private static function send(string $subject, string $body): bool
    {
        /** Фильтр: получатель уведомлений B2B (по умолчанию admin_email). */
        $to = (string) apply_filters('onecatalog_b2b_notify_email', get_option('admin_email'));
        if ('' === $to) {
            return false;
        }
        $site = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
        return (bool) wp_mail($to, '[' . $site . '] ' . $subject, $body);
    }
}
