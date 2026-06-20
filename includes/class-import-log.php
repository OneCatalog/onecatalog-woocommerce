<?php
/**
 * Страница «Журнал импорта» (wp-admin → OneCatalog → Журнал импорта).
 *
 * Показывает состояние фоновой очереди (Action Scheduler): сколько порций в ожидании
 * и в работе, и историю последних результатов по товарам (создан/обновлён/пропущен/
 * ошибка). Кнопка «Выполнить сейчас» принудительно прогоняет очередь, если WP-Cron не
 * срабатывает (типично на тестовых сайтах без трафика/реального cron).
 */

namespace OneCatalog\Import;

if (! defined('ABSPATH')) {
    exit;
}

final class Import_Log
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu'], 40);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            'onecatalog',
            __('Import log', 'onecatalog-import'),
            __('Import log', 'onecatalog-import'),
            'manage_woocommerce',
            'onecatalog-log',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied', 'onecatalog-import'));
        }

        $ran_notice = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST['onecatalog_log_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['onecatalog_log_nonce'])), 'onecatalog_run_queue')
        ) {
            $res = Queue::run_now();
            if (! $res['available']) {
                $ran_notice = __('Action Scheduler is unavailable.', 'onecatalog-import');
            } else {
                /* translators: %d: number of processed actions */
                $ran_notice = sprintf(__('Queue run: %d action(s) processed.', 'onecatalog-import'), (int) $res['ran']);
            }
        }

        $status = Queue::status();
        $pending = (int) ($status['pending'] ?? 0);
        $running = (int) ($status['running'] ?? 0);
        $log     = (array) ($status['log'] ?? []);
        $has_cron = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('OneCatalog — import log', 'onecatalog-import'); ?></h1>

            <?php if ($ran_notice) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($ran_notice); ?></p></div>
            <?php endif; ?>

            <p>
                <strong><?php esc_html_e('Queue:', 'onecatalog-import'); ?></strong>
                <?php
                /* translators: 1: pending batches, 2: running batches */
                printf(esc_html__('%1$d pending, %2$d running.', 'onecatalog-import'), $pending, $running);
                ?>
            </p>

            <form method="post" style="margin:0 0 12px;">
                <?php wp_nonce_field('onecatalog_run_queue', 'onecatalog_log_nonce'); ?>
                <button type="submit" class="button button-primary"><?php esc_html_e('Run queue now', 'onecatalog-import'); ?></button>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=onecatalog-log')); ?>"><?php esc_html_e('Refresh', 'onecatalog-import'); ?></a>
                <?php if (function_exists('as_get_scheduled_actions')) : ?>
                    <a class="button" href="<?php echo esc_url(admin_url('tools.php?page=action-scheduler&s=onecatalog')); ?>"><?php esc_html_e('Scheduled Actions (all)', 'onecatalog-import'); ?></a>
                <?php endif; ?>
            </form>

            <?php if ($pending > 0) : ?>
                <div class="notice notice-warning inline" style="margin:0 0 12px;"><p>
                    <?php esc_html_e('There are pending batches. The background queue runs via WP-Cron — on low-traffic or test sites it may not fire on its own. Use “Run queue now”, or set up a real system cron.', 'onecatalog-import'); ?>
                    <?php if ($has_cron) : ?>
                        <br><strong><?php esc_html_e('Note:', 'onecatalog-import'); ?></strong>
                        <?php esc_html_e('DISABLE_WP_CRON is enabled — a real cron must call wp-cron.php, otherwise nothing runs automatically.', 'onecatalog-import'); ?>
                    <?php endif; ?>
                </p></div>
            <?php endif; ?>

            <h2><?php esc_html_e('Last results', 'onecatalog-import'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:160px;"><?php esc_html_e('Time', 'onecatalog-import'); ?></th>
                        <th style="width:120px;"><?php esc_html_e('Identifier', 'onecatalog-import'); ?></th>
                        <th style="width:110px;"><?php esc_html_e('Status', 'onecatalog-import'); ?></th>
                        <th><?php esc_html_e('Product / error', 'onecatalog-import'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (! $log) : ?>
                    <tr><td colspan="4"><?php esc_html_e('No entries yet.', 'onecatalog-import'); ?></td></tr>
                <?php else : foreach ($log as $row) :
                    $st   = (string) ($row['status'] ?? '');
                    $when = ! empty($row['t']) ? date_i18n('Y-m-d H:i:s', (int) $row['t']) : '';
                    $color = ['created' => '#1a7f37', 'updated' => '#2271b1', 'skipped' => '#996800', 'error' => '#b32d2e'][$st] ?? '#555';
                    ?>
                    <tr>
                        <td><?php echo esc_html($when); ?></td>
                        <td><code><?php echo esc_html((string) ($row['identifier'] ?? '')); ?></code></td>
                        <td><span style="color:<?php echo esc_attr($color); ?>;font-weight:600;"><?php echo esc_html($st); ?></span></td>
                        <td><?php
                            $name = (string) ($row['name'] ?? '');
                            $err  = (string) ($row['error'] ?? '');
                            echo esc_html($name);
                            if ('' !== $err) {
                                echo ($name !== '' ? ' — ' : '') . '<span style="color:#b32d2e;">' . esc_html($err) . '</span>';
                            }
                        ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            <p class="description"><?php esc_html_e('History of the last 100 imported items (most recent first).', 'onecatalog-import'); ?></p>
        </div>
        <?php
    }
}
