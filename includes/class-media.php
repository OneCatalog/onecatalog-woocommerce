<?php
/**
 * Изображения: выбор размера (с токеном — максимальный), sideload в медиатеку,
 * обложка и галерея с фиксацией качества. Файлы, загруженные в низком качестве
 * (например, без токена), автоматически заменяются лучшими при повторном импорте.
 */

namespace OneCatalog\Import;

if (! defined('ABSPATH')) {
    exit;
}

final class Media
{
    public const META_SOURCE   = '_onecatalog_image_source';
    public const META_QUALITY  = '_onecatalog_image_quality';
    public const META_GALLERY  = '_onecatalog_gallery';
    public const META_FILE_KEY = '_onecatalog_file_key'; // контент-ключ для дедупликации (sha1 path#size)

    /** Кэш «ключ → attachment_id» в пределах запроса (чтобы не дёргать БД повторно). */
    private static array $key_cache = [];

    // Легаси-ключи ранних версий плагина (читаются как фолбэк).
    private const LEGACY_SOURCE  = '_ceram_image_done';
    private const LEGACY_QUALITY = '_ceram_image_quality';

    /** Предпочтительный URL из набора {min,middle,max} + выбранный размер. */
    public static function pick_size_info(array $urls): array
    {
        $order = '' !== Api::token() ? ['max', 'middle', 'min'] : ['middle', 'max', 'min'];

        /** Фильтр: порядок предпочтения размеров изображений. */
        $order = (array) apply_filters('onecatalog_image_size_order', $order);

        foreach ($order as $size) {
            if (! empty($urls[$size])) {
                return ['url' => (string) $urls[$size], 'size' => (string) $size];
            }
        }
        return ['url' => '', 'size' => ''];
    }

    public static function pick_size(array $urls): string
    {
        return self::pick_size_info($urls)['url'];
    }

    /** Ранг качества — для сравнения «нужно ли заменить файл лучшим». */
    public static function size_rank(string $size): int
    {
        return ['min' => 1, 'middle' => 2, 'max' => 3][$size] ?? 0;
    }

    /** Размер из URL OneCatalog (base64-префикс вида «middle|images/…»). */
    public static function guess_size_from_url(string $url): string
    {
        if (preg_match('~/media_files/([A-Za-z0-9_-]+)~', $url, $m)) {
            $raw = base64_decode(strtr($m[1], '-_', '+/'), false);
            if ($raw && false !== ($pos = strpos($raw, '|'))) {
                $size = substr($raw, 0, $pos);
                if (in_array($size, ['min', 'middle', 'max'], true)) {
                    return $size;
                }
            }
        }
        return '';
    }

    /**
     * Стабильный контент-ключ файла из URL OneCatalog для дедупликации.
     * В base64-префиксе media_files зашит `size|path|token`; берём path+size
     * (неизменны между запросами и сменой токена) → sha1. '' — если не извлечь.
     */
    public static function file_key(string $url): string
    {
        if (! preg_match('~/media_files/([A-Za-z0-9_-]+)~', $url, $m)) {
            return '';
        }
        $raw = base64_decode(strtr($m[1], '-_', '+/'), false);
        if (! $raw || false === strpos($raw, '|')) {
            return '';
        }
        $parts = explode('|', $raw, 3);
        $size  = (string) ($parts[0] ?? '');
        $path  = (string) ($parts[1] ?? '');
        if ('' === $path) {
            return '';
        }
        return sha1($path . '#' . $size);
    }

    /** Существующий attachment по контент-ключу (0 — нет). Кэшируется на запрос. */
    public static function find_by_key(string $key): int
    {
        if ('' === $key) {
            return 0;
        }
        if (isset(self::$key_cache[$key])) {
            return self::$key_cache[$key];
        }
        $found = get_posts([
            'post_type'        => 'attachment',
            'post_status'      => 'inherit',
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'meta_key'         => self::META_FILE_KEY,
            'meta_value'       => $key,
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ]);
        $id = $found ? (int) $found[0] : 0;
        if ($id) {
            self::$key_cache[$key] = $id;
        }
        return $id;
    }

    /**
     * Sideload файла по URL → attachment ID (0 при ошибке).
     * URL OneCatalog не содержат расширения — тип определяется по mime.
     *
     * Дедупликация: тот же файл (контент-ключ path#size) уже в медиатеке →
     * переиспользуем существующий attachment, не скачивая заново. Ключ пишется
     * на каждый загруженный файл (реестр строится даже если поиск отключён фильтром).
     */
    public static function sideload(int $post_id, string $url, string $basename = ''): int
    {
        if ('' === $url || ! $post_id) {
            return 0;
        }

        $key = self::file_key($url);
        /** Фильтр: использовать ли дедупликацию медиа (поиск существующего по ключу). */
        if ('' !== $key && apply_filters('onecatalog_media_dedup', true)) {
            $existing = self::find_by_key($key);
            if ($existing) {
                return $existing;
            }
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($url, 60);
        if (is_wp_error($tmp)) {
            return 0;
        }
        $mime = wp_get_image_mime($tmp);
        $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif'][$mime] ?? null;
        if (! $ext) {
            @unlink($tmp);
            return 0;
        }
        $base   = sanitize_file_name($basename ?: (get_post_field('post_name', $post_id) ?: 'onecatalog-' . $post_id));
        $att_id = media_handle_sideload(['name' => $base . '.' . $ext, 'tmp_name' => $tmp], $post_id);
        if (is_wp_error($att_id)) {
            @unlink($tmp);
            return 0;
        }
        $att_id = (int) $att_id;
        if ('' !== $key) {
            update_post_meta($att_id, self::META_FILE_KEY, $key);
            self::$key_cache[$key] = $att_id;
        }
        return $att_id;
    }

    /** Общий ли это attachment (с контент-ключом) — такой нельзя удалять при замене. */
    private static function is_shared(int $att_id): bool
    {
        return $att_id > 0 && '' !== (string) get_post_meta($att_id, self::META_FILE_KEY, true);
    }

    /**
     * Обложка поста из {min,middle,max} с фиксацией качества: если уже загружена
     * в качестве не хуже доступного — не трогаем; если доступно лучше — заменяем,
     * старый attachment удаляем.
     */
    public static function cover(int $post_id, array $urls): int
    {
        $pick = self::pick_size_info($urls);
        if ('' === $pick['url'] || ! $post_id) {
            return has_post_thumbnail($post_id) ? (int) get_post_thumbnail_id($post_id) : 0;
        }

        $recorded = (string) get_post_meta($post_id, self::META_QUALITY, true);
        if ('' === $recorded) {
            // Миграция с легаси-ключей / восстановление качества из URL источника.
            $recorded = (string) get_post_meta($post_id, self::LEGACY_QUALITY, true);
            if ('' === $recorded && has_post_thumbnail($post_id)) {
                $legacy_src = (string) get_post_meta($post_id, self::LEGACY_SOURCE, true);
                $recorded   = self::guess_size_from_url($legacy_src) ?: 'min';
            }
            if ('' !== $recorded) {
                update_post_meta($post_id, self::META_QUALITY, $recorded);
            }
        }

        if (has_post_thumbnail($post_id) && self::size_rank($recorded) >= self::size_rank($pick['size'])) {
            return (int) get_post_thumbnail_id($post_id);
        }

        $old = (int) get_post_thumbnail_id($post_id);
        $att = self::sideload($post_id, $pick['url']);
        if (! $att) {
            return $old;
        }
        set_post_thumbnail($post_id, $att);
        update_post_meta($post_id, self::META_SOURCE, $pick['url']);
        update_post_meta($post_id, self::META_QUALITY, $pick['size']);
        if ($old && $old !== $att && ! self::is_shared($old)) {
            wp_delete_attachment($old, true); // заменено лучшим качеством (общие файлы не трогаем)
        }
        return $att;
    }

    /**
     * Галерея товара из files[] API. Идемпотентно по имени файла (подписи URL
     * меняются между запросами); качество каждого файла фиксируется ({id, size}).
     */
    public static function gallery(int $product_id, array $files): array
    {
        $map = json_decode((string) get_post_meta($product_id, self::META_GALLERY, true), true);
        $map = is_array($map) ? $map : [];
        $ids = [];

        foreach ($files as $file) {
            if (! is_array($file) || ($file['category'] ?? '') !== 'images') {
                continue;
            }
            $name = (string) ($file['name'] ?? '');
            $pick = is_array($file['urls'] ?? null) ? self::pick_size_info($file['urls']) : ['url' => '', 'size' => ''];
            if ('' === $name || '' === $pick['url']) {
                continue;
            }

            $entry = $map[$name] ?? null;
            if (is_int($entry) || (is_string($entry) && ctype_digit($entry))) {
                $entry = ['id' => (int) $entry, 'size' => 'min']; // легаси-формат
            }
            $entry_ok = is_array($entry) && ! empty($entry['id']) && get_post((int) $entry['id']);

            if ($entry_ok && self::size_rank((string) ($entry['size'] ?? '')) >= self::size_rank($pick['size'])) {
                $map[$name] = $entry;
                $ids[]      = (int) $entry['id'];
                continue;
            }

            $att = self::sideload($product_id, $pick['url'], $name);
            if ($att) {
                if ($entry_ok && (int) $entry['id'] !== $att && ! self::is_shared((int) $entry['id'])) {
                    wp_delete_attachment((int) $entry['id'], true); // заменено лучшим качеством (общие не трогаем)
                }
                $map[$name] = ['id' => $att, 'size' => $pick['size']];
                $ids[]      = $att;
            } elseif ($entry_ok) {
                $map[$name] = $entry;
                $ids[]      = (int) $entry['id'];
            }
        }

        update_post_meta($product_id, self::META_GALLERY, wp_json_encode($map));
        return $ids;
    }
}
