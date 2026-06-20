<?php
/**
 * Страница «Журнал обновлений» (wp-admin → OneCatalog → Журнал обновлений).
 *
 * История запусков B2B-синхронизации цен/остатков: когда стартовал/завершился, сколько
 * просканировано/изменено/без изменений/поставлено в очередь импорта. Плюс прогресс
 * текущего/последнего запуска и детальный лог по страницам.
 */

namespace OneCatalog\Import;

if (! defined('ABSPATH')) {
    exit;
}

final class B2B_Log
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu'], 25);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            'onecatalog',
            __('Sync log', 'onecatalog-import'),
            __('Sync log', 'onecatalog-import'),
            'manage_woocommerce',
            'onecatalog-b2b-log',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied', 'onecatalog-import'));
        }

        $progress = (array) get_option(PriceStockSync::OPTION_PROGRESS, []);
        $history  = (array) get_option(PriceStockSync::OPTION_HISTORY, []);
        $log      = array_slice((array) get_option(PriceStockSync::OPTION_LOG, []), 0, 60);

        $pending = 0;
        if (function_exists('as_get_scheduled_actions')) {
            $pending = count(as_get_scheduled_actions(['hook' => PriceStockSync::AS_HOOK, 'status' => 'pending', 'per_page' => 500], 'ids'))
                + count(as_get_scheduled_actions(['hook' => PriceStockSync::AS_WRITE_HOOK, 'status' => 'pending', 'per_page' => 500], 'ids'));
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('OneCatalog — price/stock sync log', 'onecatalog-import'); ?></h1>
            <p>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=onecatalog-pricestock')); ?>"><?php esc_html_e('Go to “Prices & stock”', 'onecatalog-import'); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=onecatalog-b2b-log')); ?>"><?php esc_html_e('Refresh', 'onecatalog-import'); ?></a>
            </p>

            <?php if ($progress) :
                $running = empty($progress['finished']);
                ?>
                <h2><?php echo $running ? esc_html__('Current run', 'onecatalog-import') : esc_html__('Last run', 'onecatalog-import'); ?></h2>
                <p>
                    <?php
                    printf(
                        /* translators: 1: scanned, 2: total, 3: changed, 4: unchanged, 5: queued */
                        esc_html__('Scanned %1$d/%2$d · changed %3$d · unchanged %4$d · queued for import %5$d', 'onecatalog-import'),
                        (int) ($progress['scanned'] ?? 0),
                        (int) ($progress['total'] ?? 0),
                        (int) ($progress['changed'] ?? 0),
                        (int) ($progress['unchanged'] ?? 0),
                        (int) ($progress['queued_import'] ?? 0)
                    );
                    echo ' — ' . ($running ? '<strong>' . esc_html__('running', 'onecatalog-import') . '</strong>' : esc_html__('finished', 'onecatalog-import'));
                    ?>
                </p>
            <?php endif; ?>

            <?php if ($pending > 0) : ?>
                <div class="notice notice-warning inline" style="margin:0 0 12px;"><p><?php
                    /* translators: %d: pending actions */
                    printf(esc_html__('%d background action(s) pending. Manual “Sync now” runs in the browser and does not need them; scheduled auto-sync needs WP-Cron / real cron.', 'onecatalog-import'), (int) $pending);
                ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e('Run history', 'onecatalog-import'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Started', 'onecatalog-import'); ?></th>
                        <th><?php esc_html_e('Finished', 'onecatalog-import'); ?></th>
                        <th><?php esc_html_e('Duration', 'onecatalog-import'); ?></th>
                        <th><?php esc_html_e('Scanned', 'onecatalog-import'); ?></th>
                        <th><?php esc_html_e('Changed', 'onecatalog-import'); ?></th>
                        <th><?php esc_html_e('Unchanged', 'onecatalog-import'); ?></th>
                        <th><?php esc_html_e('Queued import', 'onecatalog-import'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (! $history) : ?>
                    <tr><td colspan="7"><?php esc_html_e('No runs yet.', 'onecatalog-import'); ?></td></tr>
                <?php else : foreach ($history as $h) :
                    $started  = (int) ($h['started'] ?? 0);
                    $finished = (int) ($h['finished'] ?? 0);
                    $dur      = ($started && $finished && $finished >= $started) ? human_time_diff($started, $finished) : '—';
                    ?>
                    <tr>
                        <td><?php echo esc_html($started ? date_i18n('Y-m-d H:i:s', $started) : '—'); ?></td>
                        <td><?php echo esc_html($finished ? date_i18n('Y-m-d H:i:s', $finished) : '—'); ?></td>
                        <td><?php echo esc_html($dur); ?></td>
                        <td><?php echo (int) ($h['scanned'] ?? 0); ?> / <?php echo (int) ($h['total'] ?? 0); ?></td>
                        <td><strong style="color:#2271b1;"><?php echo (int) ($h['changed'] ?? 0); ?></strong></td>
                        <td><?php echo (int) ($h['unchanged'] ?? 0); ?></td>
                        <td><?php echo (int) ($h['queued_import'] ?? 0); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <h2><?php esc_html_e('Last page-by-page details', 'onecatalog-import'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:160px;"><?php esc_html_e('Time', 'onecatalog-import'); ?></th>
                        <th style="width:140px;"><?php esc_html_e('Key', 'onecatalog-import'); ?></th>
                        <th style="width:110px;"><?php esc_html_e('Status', 'onecatalog-import'); ?></th>
                        <th><?php esc_html_e('Message', 'onecatalog-import'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (! $log) : ?>
                    <tr><td colspan="4"><?php esc_html_e('No entries yet.', 'onecatalog-import'); ?></td></tr>
                <?php else : foreach ($log as $row) :
                    $st    = (string) ($row['status'] ?? '');
                    $when  = ! empty($row['t']) ? date_i18n('Y-m-d H:i:s', (int) $row['t']) : '';
                    $color = ['updated' => '#2271b1', 'created' => '#1a7f37', 'page' => '#555', 'error' => '#b32d2e', 'skipped' => '#996800'][$st] ?? '#555';
                    ?>
                    <tr>
                        <td><?php echo esc_html($when); ?></td>
                        <td><code><?php echo esc_html((string) ($row['key'] ?? '')); ?></code></td>
                        <td><span style="color:<?php echo esc_attr($color); ?>;font-weight:600;"><?php echo esc_html($st); ?></span></td>
                        <td><?php echo esc_html((string) ($row['message'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            <p class="description"><?php esc_html_e('History keeps the last 30 runs; details — the last 60 page/item entries.', 'onecatalog-import'); ?></p>
        </div>
        <?php
    }
}
