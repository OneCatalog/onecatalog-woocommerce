<?php
/**
 * Фоновая очередь импорта на Action Scheduler (поставляется с WooCommerce):
 * идентификаторы режутся на порции по «шагу импорта» и выполняются в фоне.
 * Результаты пишутся в лог (последние 100) для статуса в админке.
 */

namespace OneCatalog\Import;

if (! defined('ABSPATH')) {
    exit;
}

final class Queue
{
    public const AS_HOOK     = 'onecatalog_import_batch';
    public const AS_GROUP    = 'onecatalog-import';
    public const OPTION_LOG  = 'onecatalog_import_log';
    public const OPTION_STEP = 'onecatalog_step';

    public const STEP_MIN     = 10;
    public const STEP_MAX     = 100;
    public const STEP_DEFAULT = 10;

    public static function init(): void
    {
        add_action(self::AS_HOOK, [self::class, 'process_batch'], 10, 1);
    }

    /** Шаг импорта: сколько товаров обрабатывается за один проход очереди (минимум 10). */
    public static function step(): int
    {
        $step = (int) get_option(self::OPTION_STEP, self::STEP_DEFAULT);
        $step = max(self::STEP_MIN, min(self::STEP_MAX, $step));

        /** Фильтр: размер порции очереди импорта. */
        return max(1, (int) apply_filters('onecatalog_import_step', $step));
    }

    public static function available(): bool
    {
        return function_exists('as_enqueue_async_action');
    }

    /**
     * Поставить идентификаторы в очередь импорта.
     * Без Action Scheduler — синхронный фолбэк (результаты сразу в ответе).
     */
    public static function enqueue(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($v) => trim((string) $v),
            $ids
        ))));
        if (! $ids) {
            return ['queued' => 0, 'batches' => 0, 'sync' => false, 'results' => []];
        }

        if (! self::available()) {
            $results = [];
            foreach ($ids as $identifier) {
                $report = ProductImporter::import_by_identifier($identifier);
                self::log($report);
                $results[] = $report;
            }
            return ['queued' => count($ids), 'batches' => 0, 'sync' => true, 'results' => $results];
        }

        $step    = self::step();
        $batches = array_chunk($ids, $step);
        foreach ($batches as $batch) {
            as_enqueue_async_action(self::AS_HOOK, [$batch], self::AS_GROUP);
        }

        /** Экшен: идентификаторы поставлены в очередь ($ids, число порций, шаг). */
        do_action('onecatalog_queue_enqueued', $ids, count($batches), $step);

        return ['queued' => count($ids), 'batches' => count($batches), 'step' => $step, 'sync' => false];
    }

    /** Обработчик одной порции очереди (вызывается Action Scheduler). */
    public static function process_batch($identifiers): void
    {
        foreach ((array) $identifiers as $identifier) {
            $report = ProductImporter::import_by_identifier((string) $identifier);
            self::log($report);
        }
    }

    /** Запись результата в лог (последние 100). */
    public static function log(array $report): void
    {
        $log = get_option(self::OPTION_LOG, []);
        if (! is_array($log)) {
            $log = [];
        }
        array_unshift($log, [
            't'          => time(),
            'identifier' => (string) ($report['identifier'] ?? ''),
            'status'     => (string) ($report['status'] ?? ''),
            'name'       => (string) ($report['name'] ?? ''),
            'error'      => (string) ($report['error'] ?? ''),
        ]);
        update_option(self::OPTION_LOG, array_slice($log, 0, 100), false);
    }

    /** Статус очереди: порции в ожидании/в работе + последние результаты. */
    public static function status(): array
    {
        $pending = 0;
        $running = 0;
        if (self::available()) {
            $pending = count(as_get_scheduled_actions(
                ['hook' => self::AS_HOOK, 'status' => 'pending', 'per_page' => 500],
                'ids'
            ));
            $running = count(as_get_scheduled_actions(
                ['hook' => self::AS_HOOK, 'status' => 'in-progress', 'per_page' => 100],
                'ids'
            ));
        }
        return [
            'pending' => $pending,
            'running' => $running,
            'log'     => array_slice((array) get_option(self::OPTION_LOG, []), 0, 30),
        ];
    }
}
