<?php
/**
 * Settings page (menu "OneCatalog", slug `onecatalog`):
 * Wiki API token, import step (min 10), products language, documentation link.
 */

namespace OneCatalog\Import;

if (! defined('ABSPATH')) {
    exit;
}

final class Settings
{
    public const DOCS_URL = 'https://docs.onecatalog.net/';

    public const OPTION_PRODUCT_STATUS     = 'onecatalog_product_status'; // статус создаваемых товаров

    public const OPTION_IMPORT_COLLECTIONS = 'onecatalog_import_collections';
    public const OPTION_COLLECTION_OBJECT  = 'onecatalog_collection_object_type'; // attribute | taxonomy | post_type
    public const OPTION_COLLECTION_ATTRIBUTE = 'onecatalog_collection_attribute'; // attribute_id (хранить коллекцию в атрибуте)
    public const OPTION_COLLECTION_PT       = 'onecatalog_collection_post_type';
    public const OPTION_COLLECTION_TAX      = 'onecatalog_collection_taxonomy';

    public const OPTION_IMPORT_BRANDS      = 'onecatalog_import_brands';
    public const OPTION_BRAND_OBJECT       = 'onecatalog_brand_object_type'; // attribute | taxonomy | post_type
    public const OPTION_BRAND_ATTRIBUTE    = 'onecatalog_brand_attribute';   // attribute_id (старый способ — бренд в характеристиках)
    public const OPTION_BRAND_PT            = 'onecatalog_brand_post_type';
    public const OPTION_BRAND_TAX           = 'onecatalog_brand_taxonomy';

    public const OPTION_IMPORT_TAGS        = 'onecatalog_import_tags'; // импортировать ли теги → product_tag

    // Страна происхождения: атрибут или таксономия.
    public const OPTION_IMPORT_COUNTRY     = 'onecatalog_import_country';
    public const OPTION_COUNTRY_OBJECT     = 'onecatalog_country_object_type'; // attribute | taxonomy
    public const OPTION_COUNTRY_ATTRIBUTE  = 'onecatalog_country_attribute';   // attribute_id
    public const OPTION_COUNTRY_TAX        = 'onecatalog_country_taxonomy';

    // Ручное сопоставление характеристик OneCatalog → глобальные атрибуты WC.
    public const OPTION_ATTR_MAP_ENABLED   = 'onecatalog_attr_map_enabled';
    public const OPTION_ATTR_MAP           = 'onecatalog_attr_map';  // [specification_id => attribute_id]
    public const OPTION_ATTR_BOOL          = 'onecatalog_attr_bool'; // [specification_id => ['t'=>значение, 'f'=>значение]]

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
    }

    /** Импортировать ли коллекции вместе с товарами. */
    public static function collections_enabled(): bool
    {
        $enabled = get_option(self::OPTION_IMPORT_COLLECTIONS, '1') !== '0';

        /** Фильтр: импортировать ли коллекции. */
        return (bool) apply_filters('onecatalog_import_collections', $enabled);
    }

    /** Способ хранения коллекции: 'attribute' | 'taxonomy' | 'post_type' (по умолчанию). */
    public static function collection_object_type(): string
    {
        $type = (string) get_option(self::OPTION_COLLECTION_OBJECT, 'post_type');
        return in_array($type, ['attribute', 'taxonomy', 'post_type'], true) ? $type : 'post_type';
    }

    /** Глобальный атрибут для коллекции (если object_type = attribute). 0 — не выбран/не существует. */
    public static function collection_attribute_id(): int
    {
        $id = (int) get_option(self::OPTION_COLLECTION_ATTRIBUTE, 0);
        if ($id > 0 && function_exists('wc_attribute_taxonomy_name_by_id')
            && '' !== (string) wc_attribute_taxonomy_name_by_id($id)
        ) {
            return $id;
        }
        return 0;
    }

    /** Целевой post type для коллекций (если object_type = post_type). */
    public static function collection_post_type(): string
    {
        $pt = (string) get_option(self::OPTION_COLLECTION_PT, 'collection');
        return post_type_exists($pt) ? $pt : 'collection';
    }

    /** Целевая таксономия для коллекций (если object_type = taxonomy). */
    public static function collection_taxonomy(): string
    {
        $tax = (string) get_option(self::OPTION_COLLECTION_TAX, '');
        return ($tax && taxonomy_exists($tax)) ? $tax : '';
    }

    /** Назначать ли бренд товара при импорте. */
    public static function brands_enabled(): bool
    {
        $enabled = get_option(self::OPTION_IMPORT_BRANDS, '1') !== '0';

        /** Фильтр: назначать ли бренд при импорте. */
        return (bool) apply_filters('onecatalog_import_brands', $enabled);
    }

    /**
     * Способ хранения бренда:
     *   'attribute'  — товарный атрибут pa_* (старый способ WooCommerce, «в характеристиках»);
     *   'taxonomy'   — отдельная таксономия product_brand (новый способ, по умолчанию);
     *   'post_type'  — запись CPT.
     */
    public static function brand_object_type(): string
    {
        $type = (string) get_option(self::OPTION_BRAND_OBJECT, 'taxonomy');
        return in_array($type, ['attribute', 'taxonomy', 'post_type'], true) ? $type : 'taxonomy';
    }

    /** Глобальный атрибут для бренда (если object_type = attribute). 0 — не выбран/не существует. */
    public static function brand_attribute_id(): int
    {
        $id = (int) get_option(self::OPTION_BRAND_ATTRIBUTE, 0);
        if ($id > 0 && function_exists('wc_attribute_taxonomy_name_by_id')
            && '' !== (string) wc_attribute_taxonomy_name_by_id($id)
        ) {
            return $id;
        }
        return 0;
    }

    /** Целевой post type для бренда (если object_type = post_type). */
    public static function brand_post_type(): string
    {
        $pt = (string) get_option(self::OPTION_BRAND_PT, '');
        return post_type_exists($pt) ? $pt : '';
    }

    /** Импортировать ли теги товара (в нативную таксономию product_tag, поиск по названию). */
    public static function tags_enabled(): bool
    {
        $on = get_option(self::OPTION_IMPORT_TAGS, '0') === '1';

        /** Фильтр: импортировать ли теги. */
        return (bool) apply_filters('onecatalog_import_tags', $on);
    }

    /** Назначать ли страну происхождения при импорте. */
    public static function country_enabled(): bool
    {
        $on = get_option(self::OPTION_IMPORT_COUNTRY, '0') === '1';

        /** Фильтр: назначать ли страну при импорте. */
        return (bool) apply_filters('onecatalog_import_country', $on);
    }

    /** Способ хранения страны: 'attribute' (по умолчанию) или 'taxonomy'. */
    public static function country_object_type(): string
    {
        $type = (string) get_option(self::OPTION_COUNTRY_OBJECT, 'attribute');
        return in_array($type, ['attribute', 'taxonomy'], true) ? $type : 'attribute';
    }

    /** Глобальный атрибут для страны (если object_type = attribute). 0 — не выбран/не существует. */
    public static function country_attribute_id(): int
    {
        $id = (int) get_option(self::OPTION_COUNTRY_ATTRIBUTE, 0);
        if ($id > 0 && function_exists('wc_attribute_taxonomy_name_by_id')
            && '' !== (string) wc_attribute_taxonomy_name_by_id($id)
        ) {
            return $id;
        }
        return 0;
    }

    /** Целевая таксономия для страны (если object_type = taxonomy). */
    public static function country_taxonomy(): string
    {
        $tax = (string) get_option(self::OPTION_COUNTRY_TAX, '');
        return ($tax && taxonomy_exists($tax)) ? $tax : '';
    }

    /** Значение терма для boolean-характеристики ($spec_id) при true/false ('' — не задано). */
    public static function bool_value(int $spec_id, bool $value): string
    {
        $map = get_option(self::OPTION_ATTR_BOOL, []);
        if (! is_array($map) || ! isset($map[$spec_id]) || ! is_array($map[$spec_id])) {
            return '';
        }
        return trim((string) ($map[$spec_id][$value ? 't' : 'f'] ?? ''));
    }

    /** Целевая таксономия для бренда (если object_type = taxonomy; по умолчанию product_brand). */
    public static function brand_taxonomy(): string
    {
        $tax = (string) get_option(self::OPTION_BRAND_TAX, 'product_brand');
        if ($tax && taxonomy_exists($tax)) {
            return $tax;
        }
        return taxonomy_exists('product_brand') ? 'product_brand' : '';
    }

    /** Допустимые статусы для создаваемых товаров (slug => локализованная подпись WP). */
    private static function product_status_choices(): array
    {
        $choices = [];
        foreach (['publish', 'pending', 'draft', 'private'] as $status) {
            $obj = get_post_status_object($status);
            $choices[$status] = ($obj && isset($obj->label)) ? $obj->label : $status;
        }
        return $choices;
    }

    /** Статус, с которым создаются новые товары при импорте (по умолчанию publish). */
    public static function product_status(): string
    {
        $status  = (string) get_option(self::OPTION_PRODUCT_STATUS, 'publish');
        $allowed = array_keys(self::product_status_choices());
        $status  = in_array($status, $allowed, true) ? $status : 'publish';

        /** Фильтр: статус создаваемых при импорте товаров. */
        return (string) apply_filters('onecatalog_product_status', $status);
    }

    /** Включено ли ручное сопоставление характеристик (иначе — поиск/создание по названию). */
    public static function attr_map_enabled(): bool
    {
        $on = get_option(self::OPTION_ATTR_MAP_ENABLED, '0') === '1';

        /** Фильтр: включено ли ручное сопоставление характеристик. */
        return (bool) apply_filters('onecatalog_attr_map_enabled', $on);
    }

    /** Карта сопоставления: [specification_id OneCatalog => attribute_id WC]. */
    public static function attr_map(): array
    {
        $raw = get_option(self::OPTION_ATTR_MAP, []);
        $map = [];
        if (is_array($raw)) {
            foreach ($raw as $spec_id => $attr_id) {
                $spec_id = (int) $spec_id;
                $attr_id = (int) $attr_id;
                if ($spec_id > 0 && $attr_id > 0) {
                    $map[$spec_id] = $attr_id;
                }
            }
        }

        /** Фильтр: карта сопоставления характеристик (specification_id => attribute_id). */
        return (array) apply_filters('onecatalog_attr_map', $map);
    }

    /** ID связанного WC-атрибута для характеристики OneCatalog по её id (0 — привязки нет). */
    public static function mapped_attribute_id(int $spec_id): int
    {
        $map = self::attr_map();
        return isset($map[$spec_id]) ? (int) $map[$spec_id] : 0;
    }

    public static function register_menu(): void
    {
        add_menu_page(
            __('OneCatalog Settings', 'onecatalog-import'),
            'OneCatalog',
            'manage_woocommerce',
            'onecatalog',
            [self::class, 'render'],
            'dashicons-download',
            58
        );

        // Подстраница «Характеристики» — показывается только при включённом ручном
        // сопоставлении (тумблер «Manual mapping» на главной странице настроек).
        if (self::attr_map_enabled()) {
            add_submenu_page(
                'onecatalog',
                __('Characteristics', 'onecatalog-import'),
                __('Characteristics', 'onecatalog-import'),
                'manage_woocommerce',
                'onecatalog-characteristics',
                [self::class, 'render_characteristics']
            );
        }
    }

    /** Documentation URL (filterable). */
    public static function docs_url(): string
    {
        /** Filter: OneCatalog documentation URL. */
        return (string) apply_filters('onecatalog_docs_url', self::DOCS_URL);
    }

    private static function save(): bool
    {
        if (! isset($_POST['onecatalog_nonce'])
            || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['onecatalog_nonce'])), 'onecatalog_save_settings')
            || ! current_user_can('manage_woocommerce')
        ) {
            return false;
        }
        update_option(Api::OPTION_TOKEN, sanitize_text_field(wp_unslash($_POST['onecatalog_token'] ?? '')), false);
        update_option(
            Queue::OPTION_STEP,
            max(Queue::STEP_MIN, min(Queue::STEP_MAX, (int) ($_POST['onecatalog_step'] ?? Queue::STEP_DEFAULT))),
            false
        );
        $lang  = sanitize_key(wp_unslash($_POST['onecatalog_lang'] ?? 'en'));
        $langs = Api::languages();
        update_option(Api::OPTION_LANG, isset($langs[$lang]) ? $lang : 'en', false);

        // Статус создаваемых товаров.
        $status         = sanitize_key(wp_unslash($_POST['onecatalog_product_status'] ?? 'publish'));
        $allowed_status = array_keys(self::product_status_choices());
        update_option(self::OPTION_PRODUCT_STATUS, in_array($status, $allowed_status, true) ? $status : 'publish', false);

        // Коллекции.
        update_option(self::OPTION_IMPORT_COLLECTIONS, empty($_POST['onecatalog_import_collections']) ? '0' : '1', false);
        $obj = sanitize_key(wp_unslash($_POST['onecatalog_collection_object'] ?? 'post_type'));
        update_option(self::OPTION_COLLECTION_OBJECT, in_array($obj, ['attribute', 'taxonomy', 'post_type'], true) ? $obj : 'post_type', false);
        update_option(self::OPTION_COLLECTION_ATTRIBUTE, (int) ($_POST['onecatalog_collection_attribute'] ?? 0), false);
        update_option(self::OPTION_COLLECTION_PT, sanitize_key(wp_unslash($_POST['onecatalog_collection_post_type'] ?? 'collection')), false);
        update_option(self::OPTION_COLLECTION_TAX, sanitize_key(wp_unslash($_POST['onecatalog_collection_taxonomy'] ?? '')), false);

        // Бренд (производитель).
        update_option(self::OPTION_IMPORT_BRANDS, empty($_POST['onecatalog_import_brands']) ? '0' : '1', false);
        $bobj = sanitize_key(wp_unslash($_POST['onecatalog_brand_object'] ?? 'taxonomy'));
        update_option(self::OPTION_BRAND_OBJECT, in_array($bobj, ['attribute', 'taxonomy', 'post_type'], true) ? $bobj : 'taxonomy', false);
        update_option(self::OPTION_BRAND_ATTRIBUTE, (int) ($_POST['onecatalog_brand_attribute'] ?? 0), false);
        update_option(self::OPTION_BRAND_PT, sanitize_key(wp_unslash($_POST['onecatalog_brand_post_type'] ?? '')), false);
        update_option(self::OPTION_BRAND_TAX, sanitize_key(wp_unslash($_POST['onecatalog_brand_taxonomy'] ?? 'product_brand')), false);

        // Страна происхождения.
        update_option(self::OPTION_IMPORT_COUNTRY, empty($_POST['onecatalog_import_country']) ? '0' : '1', false);
        $cobj = sanitize_key(wp_unslash($_POST['onecatalog_country_object'] ?? 'attribute'));
        update_option(self::OPTION_COUNTRY_OBJECT, in_array($cobj, ['attribute', 'taxonomy'], true) ? $cobj : 'attribute', false);
        update_option(self::OPTION_COUNTRY_ATTRIBUTE, (int) ($_POST['onecatalog_country_attribute'] ?? 0), false);
        update_option(self::OPTION_COUNTRY_TAX, sanitize_key(wp_unslash($_POST['onecatalog_country_taxonomy'] ?? '')), false);

        // Теги.
        update_option(self::OPTION_IMPORT_TAGS, empty($_POST['onecatalog_import_tags']) ? '0' : '1', false);

        // Ручное сопоставление характеристик: тут только тумблер.
        // Сами привязки настраиваются на подстранице «Характеристики» (save_characteristics()).
        update_option(self::OPTION_ATTR_MAP_ENABLED, empty($_POST['onecatalog_attr_map_enabled']) ? '0' : '1', false);
        return true;
    }

    /** Сохранение привязок характеристик (подстраница «Характеристики», свой nonce). */
    private static function save_characteristics(): bool
    {
        if (! isset($_POST['onecatalog_chars_nonce'])
            || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['onecatalog_chars_nonce'])), 'onecatalog_save_characteristics')
            || ! current_user_can('manage_woocommerce')
        ) {
            return false;
        }
        // Карта приходит ассоциативно: onecatalog_attr_map[<specification_id>] = attribute_id.
        // Ключ — id характеристики, поэтому дубли структурно невозможны.
        $raw = (array) ($_POST['onecatalog_attr_map'] ?? []);
        $map = [];
        foreach ($raw as $spec_id => $attr_id) {
            $spec_id = (int) $spec_id;
            $attr_id = (int) $attr_id;
            if ($spec_id > 0 && $attr_id > 0) {
                $map[$spec_id] = $attr_id;
            }
        }
        update_option(self::OPTION_ATTR_MAP, $map, false);

        // Значения для boolean-характеристик: onecatalog_attr_bool[<spec_id>][t|f].
        $raw_bool = (array) ($_POST['onecatalog_attr_bool'] ?? []);
        $bool     = [];
        foreach ($raw_bool as $spec_id => $vals) {
            $spec_id = (int) $spec_id;
            if ($spec_id <= 0 || ! is_array($vals)) {
                continue;
            }
            $t = sanitize_text_field(wp_unslash((string) ($vals['t'] ?? '')));
            $f = sanitize_text_field(wp_unslash((string) ($vals['f'] ?? '')));
            if ('' !== $t || '' !== $f) {
                $bool[$spec_id] = ['t' => $t, 'f' => $f];
            }
        }
        update_option(self::OPTION_ATTR_BOOL, $bool, false);
        return true;
    }

    /** Public post types для выбора цели коллекций. */
    private static function post_type_choices(): array
    {
        $choices = [];
        foreach (get_post_types(['public' => true], 'objects') as $pt) {
            if (in_array($pt->name, ['attachment'], true)) {
                continue;
            }
            $choices[$pt->name] = $pt->labels->singular_name ?: $pt->name;
        }
        if (! isset($choices['collection']) && post_type_exists('collection')) {
            $choices['collection'] = 'Collection';
        }
        return $choices;
    }

    /** Public taxonomies для выбора цели коллекций. */
    private static function taxonomy_choices(): array
    {
        $choices = ['' => __('— select —', 'onecatalog-import')];
        foreach (get_taxonomies(['public' => true], 'objects') as $tax) {
            $choices[$tax->name] = ($tax->labels->singular_name ?: $tax->name) . ' (' . $tax->name . ')';
        }
        return $choices;
    }

    public static function render(): void
    {
        $saved     = self::save();
        $env_token = getenv('ONECATALOG_TOKEN') ?: getenv('CERAPLAT_TOKEN');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('OneCatalog Settings', 'onecatalog-import'); ?></h1>
            <p>
                <?php
                printf(
                    /* translators: 1: link to the Products admin page, 2: import button label */
                    esc_html__('Product import lives on the %1$s page — use the “%2$s” button next to “Export”.', 'onecatalog-import'),
                    '<a href="' . esc_url(admin_url('edit.php?post_type=product')) . '">' . esc_html__('Products', 'onecatalog-import') . '</a>',
                    esc_html__('OneCatalog (Products)', 'onecatalog-import')
                );
                ?>
                <br>
                <a href="<?php echo esc_url(self::docs_url()); ?>" target="_blank" rel="noopener">
                    <?php esc_html_e('OneCatalog documentation', 'onecatalog-import'); ?> ↗
                </a>
            </p>

            <?php if ($saved) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'onecatalog-import'); ?></p></div>
            <?php endif; ?>

            <form method="post" style="margin:0 0 8px;">
                <?php wp_nonce_field('onecatalog_save_settings', 'onecatalog_nonce'); ?>
                <table class="form-table" style="max-width:760px;">
                    <tr>
                        <th scope="row"><label for="onecatalog_token"><?php esc_html_e('Wiki API token', 'onecatalog-import'); ?></label></th>
                        <td>
                            <input type="password" class="regular-text" id="onecatalog_token" name="onecatalog_token"
                                   value="<?php echo esc_attr((string) get_option(Api::OPTION_TOKEN, '')); ?>"
                                   autocomplete="off" placeholder="X-API-Key">
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: Wiki API base URL */
                                    esc_html__('Sent as the X-API-Key header to Wiki API requests and to the product picker. API: %s. With a token, images are downloaded in maximum quality; files previously downloaded in lower quality are replaced automatically when the product is imported again.', 'onecatalog-import'),
                                    '<code>' . esc_html(Api::base()) . '</code>'
                                );
                                if (false !== $env_token && '' !== (string) $env_token) {
                                    echo '<br><strong>' . esc_html__('Warning:', 'onecatalog-import') . '</strong> '
                                        . esc_html__('a token environment variable is set — it takes priority over this field.', 'onecatalog-import');
                                }
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="onecatalog_step"><?php esc_html_e('Import step', 'onecatalog-import'); ?></label></th>
                        <td>
                            <input type="number" min="<?php echo esc_attr((string) Queue::STEP_MIN); ?>"
                                   max="<?php echo esc_attr((string) Queue::STEP_MAX); ?>"
                                   id="onecatalog_step" name="onecatalog_step"
                                   value="<?php echo esc_attr((string) Queue::step()); ?>" style="width:90px;">
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %d: minimum step value */
                                    esc_html__('Number of products processed per background queue run (minimum %d). Selected products are never loaded all at once.', 'onecatalog-import'),
                                    (int) Queue::STEP_MIN
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="onecatalog_lang"><?php esc_html_e('Products language', 'onecatalog-import'); ?></label></th>
                        <td>
                            <select id="onecatalog_lang" name="onecatalog_lang">
                                <?php foreach (Api::languages() as $code => $label) : ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php selected(Api::lang(), $code); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Language of product names and specifications loaded from OneCatalog (Wiki API “lang” parameter; en, ru, ar, zh and kk are supported). Defaults to English.', 'onecatalog-import'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="onecatalog_product_status"><?php esc_html_e('New product status', 'onecatalog-import'); ?></label></th>
                        <td>
                            <select id="onecatalog_product_status" name="onecatalog_product_status">
                                <?php foreach (self::product_status_choices() as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected(self::product_status(), $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Status assigned to products created by the import. Existing products keep their current status on re-import.', 'onecatalog-import'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th colspan="2" style="padding-bottom:0;"><h2 style="margin:14px 0 0;"><?php esc_html_e('Collections', 'onecatalog-import'); ?></h2></th>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Import collections', 'onecatalog-import'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="onecatalog_import_collections" value="1" <?php checked(self::collections_enabled()); ?>>
                                <?php esc_html_e('Create/update collections together with products', 'onecatalog-import'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Store collections as', 'onecatalog-import'); ?></th>
                        <td>
                            <label style="margin-right:18px;">
                                <input type="radio" name="onecatalog_collection_object" value="attribute" <?php checked(self::collection_object_type(), 'attribute'); ?>>
                                <?php esc_html_e('Attribute (characteristic)', 'onecatalog-import'); ?>
                            </label>
                            <label style="margin-right:18px;">
                                <input type="radio" name="onecatalog_collection_object" value="taxonomy" <?php checked(self::collection_object_type(), 'taxonomy'); ?>>
                                <?php esc_html_e('Taxonomy', 'onecatalog-import'); ?>
                            </label>
                            <label>
                                <input type="radio" name="onecatalog_collection_object" value="post_type" <?php checked(self::collection_object_type(), 'post_type'); ?>>
                                <?php esc_html_e('Post type', 'onecatalog-import'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Whether each collection becomes a product attribute pa_*, a taxonomy term, or a post (CPT). Select the specific target below.', 'onecatalog-import'); ?></p>
                        </td>
                    </tr>
                    <tr id="onecatalog-row-attribute"<?php echo self::collection_object_type() === 'attribute' ? '' : ' style="display:none;"'; ?>>
                        <th scope="row"><label for="onecatalog_collection_attribute"><?php esc_html_e('Collection attribute', 'onecatalog-import'); ?></label></th>
                        <td>
                            <?php $collection_wc_attrs = function_exists('wc_get_attribute_taxonomies') ? wc_get_attribute_taxonomies() : []; ?>
                            <select id="onecatalog_collection_attribute" name="onecatalog_collection_attribute">
                                <option value="0"><?php esc_html_e('— select —', 'onecatalog-import'); ?></option>
                                <?php foreach ($collection_wc_attrs as $a) : ?>
                                    <option value="<?php echo (int) $a->attribute_id; ?>" <?php selected(self::collection_attribute_id(), (int) $a->attribute_id); ?>><?php echo esc_html($a->attribute_label . ' (pa_' . $a->attribute_name . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php
                                printf(
                                    /* translators: %s: link to the WooCommerce attributes admin page */
                                    esc_html__('Used when the collection is stored as a product attribute. No collection attribute yet? Create one under %s.', 'onecatalog-import'),
                                    '<a href="' . esc_url(admin_url('edit.php?post_type=product&page=product_attributes')) . '">' . esc_html__('Products → Attributes', 'onecatalog-import') . '</a>'
                                );
                            ?></p>
                        </td>
                    </tr>
                    <tr id="onecatalog-row-taxonomy"<?php echo self::collection_object_type() === 'taxonomy' ? '' : ' style="display:none;"'; ?>>
                        <th scope="row"><label for="onecatalog_collection_taxonomy"><?php esc_html_e('Target taxonomy', 'onecatalog-import'); ?></label></th>
                        <td>
                            <select id="onecatalog_collection_taxonomy" name="onecatalog_collection_taxonomy">
                                <?php foreach (self::taxonomy_choices() as $name => $label) : ?>
                                    <option value="<?php echo esc_attr($name); ?>" <?php selected((string) get_option(self::OPTION_COLLECTION_TAX, ''), $name); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Used when collections are stored as a taxonomy.', 'onecatalog-import'); ?></p>
                        </td>
                    </tr>
                    <tr id="onecatalog-row-post_type"<?php echo self::collection_object_type() === 'post_type' ? '' : ' style="display:none;"'; ?>>
                        <th scope="row"><label for="onecatalog_collection_post_type"><?php esc_html_e('Target post type', 'onecatalog-import'); ?></label></th>
                        <td>
                            <select id="onecatalog_collection_post_type" name="onecatalog_collection_post_type">
                                <?php foreach (self::post_type_choices() as $name => $label) : ?>
                                    <option value="<?php echo esc_attr($name); ?>" <?php selected(self::collection_post_type(), $name); ?>><?php echo esc_html($label . ' (' . $name . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Used when collections are stored as a post type.', 'onecatalog-import'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th colspan="2"><p class="description"><?php esc_html_e('Imported collection fields: title, slug, OneCatalog ID, cover image, 3D link, official site link.', 'onecatalog-import'); ?></p></th>
                    </tr>

                    <tr>
                        <th colspan="2" style="padding-bottom:0;"><h2 style="margin:14px 0 0;"><?php esc_html_e('Brand', 'onecatalog-import'); ?></h2></th>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Import brands', 'onecatalog-import'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="onecatalog_import_brands" value="1" <?php checked(self::brands_enabled()); ?>>
                                <?php esc_html_e('Assign the product brand on import', 'onecatalog-import'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Store brand as', 'onecatalog-import'); ?></th>
                        <td>
                            <label style="margin-right:18px;">
                                <input type="radio" name="onecatalog_brand_object" value="attribute" <?php checked(self::brand_object_type(), 'attribute'); ?>>
                                <?php esc_html_e('Attribute (characteristic)', 'onecatalog-import'); ?>
                            </label>
                            <label style="margin-right:18px;">
                                <input type="radio" name="onecatalog_brand_object" value="taxonomy" <?php checked(self::brand_object_type(), 'taxonomy'); ?>>
                                <?php esc_html_e('Taxonomy', 'onecatalog-import'); ?>
                            </label>
                            <label>
                                <input type="radio" name="onecatalog_brand_object" value="post_type" <?php checked(self::brand_object_type(), 'post_type'); ?>>
                                <?php esc_html_e('Post type', 'onecatalog-import'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Attribute — a product attribute pa_* (legacy WooCommerce, brand shown among attributes). Taxonomy — the product_brand taxonomy (current WooCommerce brands). Post type — a CPT. Select the specific target below.', 'onecatalog-import'); ?></p>
                        </td>
                    </tr>
                    <tr id="onecatalog-brand-row-attribute"<?php echo self::brand_object_type() === 'attribute' ? '' : ' style="display:none;"'; ?>>
                        <th scope="row"><label for="onecatalog_brand_attribute"><?php esc_html_e('Brand attribute', 'onecatalog-import'); ?></label></th>
                        <td>
                            <?php $brand_wc_attrs = function_exists('wc_get_attribute_taxonomies') ? wc_get_attribute_taxonomies() : []; ?>
                            <select id="onecatalog_brand_attribute" name="onecatalog_brand_attribute">
                                <option value="0"><?php esc_html_e('— select —', 'onecatalog-import'); ?></option>
                                <?php foreach ($brand_wc_attrs as $a) : ?>
                                    <option value="<?php echo (int) $a->attribute_id; ?>" <?php selected(self::brand_attribute_id(), (int) $a->attribute_id); ?>><?php echo esc_html($a->attribute_label . ' (pa_' . $a->attribute_name . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php
                                printf(
                                    /* translators: %s: link to the WooCommerce attributes admin page */
                                    esc_html__('Used when the brand is stored as a product attribute (legacy way). Don’t have a brand attribute? Create one under %s.', 'onecatalog-import'),
                                    '<a href="' . esc_url(admin_url('edit.php?post_type=product&page=product_attributes')) . '">' . esc_html__('Products → Attributes', 'onecatalog-import') . '</a>'
                                );
                            ?></p>
                        </td>
                    </tr>
                    <tr id="onecatalog-brand-row-taxonomy"<?php echo self::brand_object_type() === 'taxonomy' ? '' : ' style="display:none;"'; ?>>
                        <th scope="row"><label for="onecatalog_brand_taxonomy"><?php esc_html_e('Target taxonomy', 'onecatalog-import'); ?></label></th>
                        <td>
                            <select id="onecatalog_brand_taxonomy" name="onecatalog_brand_taxonomy">
                                <?php foreach (self::taxonomy_choices() as $name => $label) : ?>
                                    <option value="<?php echo esc_attr($name); ?>" <?php selected(self::brand_taxonomy(), $name); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Used when brands are stored as a taxonomy.', 'onecatalog-import'); ?></p>
                        </td>
                    </tr>
                    <tr id="onecatalog-brand-row-post_type"<?php echo self::brand_object_type() === 'post_type' ? '' : ' style="display:none;"'; ?>>
                        <th scope="row"><label for="onecatalog_brand_post_type"><?php esc_html_e('Target post type', 'onecatalog-import'); ?></label></th>
                        <td>
                            <select id="onecatalog_brand_post_type" name="onecatalog_brand_post_type">
                                <?php foreach (self::post_type_choices() as $name => $label) : ?>
                                    <option value="<?php echo esc_attr($name); ?>" <?php selected(self::brand_post_type(), $name); ?>><?php echo esc_html($label . ' (' . $name . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Used when brands are stored as a post type.', 'onecatalog-import'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th colspan="2" style="padding-bottom:0;"><h2 style="margin:14px 0 0;"><?php esc_html_e('Country of origin', 'onecatalog-import'); ?></h2></th>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Import country', 'onecatalog-import'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="onecatalog_import_country" value="1" <?php checked(self::country_enabled()); ?>>
                                <?php esc_html_e('Assign the country of origin on import', 'onecatalog-import'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Store country as', 'onecatalog-import'); ?></th>
                        <td>
                            <label style="margin-right:18px;">
                                <input type="radio" name="onecatalog_country_object" value="attribute" <?php checked(self::country_object_type(), 'attribute'); ?>>
                                <?php esc_html_e('Attribute (characteristic)', 'onecatalog-import'); ?>
                            </label>
                            <label>
                                <input type="radio" name="onecatalog_country_object" value="taxonomy" <?php checked(self::country_object_type(), 'taxonomy'); ?>>
                                <?php esc_html_e('Taxonomy', 'onecatalog-import'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Country is most often a product attribute. Pick the target below.', 'onecatalog-import'); ?></p>
                        </td>
                    </tr>
                    <tr id="onecatalog-country-row-attribute"<?php echo self::country_object_type() === 'attribute' ? '' : ' style="display:none;"'; ?>>
                        <th scope="row"><label for="onecatalog_country_attribute"><?php esc_html_e('Country attribute', 'onecatalog-import'); ?></label></th>
                        <td>
                            <?php $country_wc_attrs = function_exists('wc_get_attribute_taxonomies') ? wc_get_attribute_taxonomies() : []; ?>
                            <select id="onecatalog_country_attribute" name="onecatalog_country_attribute">
                                <option value="0"><?php esc_html_e('— select —', 'onecatalog-import'); ?></option>
                                <?php foreach ($country_wc_attrs as $a) : ?>
                                    <option value="<?php echo (int) $a->attribute_id; ?>" <?php selected(self::country_attribute_id(), (int) $a->attribute_id); ?>><?php echo esc_html($a->attribute_label . ' (pa_' . $a->attribute_name . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php
                                printf(
                                    /* translators: %s: link to the WooCommerce attributes admin page */
                                    esc_html__('Used when the country is stored as a product attribute. No country attribute yet? Create one under %s.', 'onecatalog-import'),
                                    '<a href="' . esc_url(admin_url('edit.php?post_type=product&page=product_attributes')) . '">' . esc_html__('Products → Attributes', 'onecatalog-import') . '</a>'
                                );
                            ?></p>
                        </td>
                    </tr>
                    <tr id="onecatalog-country-row-taxonomy"<?php echo self::country_object_type() === 'taxonomy' ? '' : ' style="display:none;"'; ?>>
                        <th scope="row"><label for="onecatalog_country_taxonomy"><?php esc_html_e('Target taxonomy', 'onecatalog-import'); ?></label></th>
                        <td>
                            <select id="onecatalog_country_taxonomy" name="onecatalog_country_taxonomy">
                                <?php foreach (self::taxonomy_choices() as $name => $label) : ?>
                                    <option value="<?php echo esc_attr($name); ?>" <?php selected(self::country_taxonomy(), $name); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Used when the country is stored as a taxonomy.', 'onecatalog-import'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th colspan="2" style="padding-bottom:0;"><h2 style="margin:14px 0 0;"><?php esc_html_e('Tags', 'onecatalog-import'); ?></h2></th>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Import tags', 'onecatalog-import'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="onecatalog_import_tags" value="1" <?php checked(self::tags_enabled()); ?>>
                                <?php esc_html_e('Import product tags into the product_tag taxonomy', 'onecatalog-import'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Tags are matched by name (label) — an existing tag is reused, otherwise created.', 'onecatalog-import'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th colspan="2"><hr><h2 style="margin:.4em 0 0"><?php esc_html_e('Characteristic mapping', 'onecatalog-import'); ?></h2></th>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Manual mapping', 'onecatalog-import'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" id="onecatalog_attr_map_enabled" name="onecatalog_attr_map_enabled" value="1" <?php checked(self::attr_map_enabled()); ?>>
                                <?php esc_html_e('Bind OneCatalog characteristics to existing WooCommerce attributes', 'onecatalog-import'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Off: each characteristic is matched to a global attribute by name, creating it if missing (default). On: only the characteristics bound on the “Characteristics” page are imported — unbound ones are skipped.', 'onecatalog-import'); ?></p>
                            <?php if (self::attr_map_enabled()) : ?>
                                <p>
                                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=onecatalog-characteristics')); ?>">
                                        <?php esc_html_e('Configure characteristics →', 'onecatalog-import'); ?>
                                    </a>
                                </p>
                            <?php else : ?>
                                <p class="description"><em><?php esc_html_e('Enable and save — a “Characteristics” submenu will appear under OneCatalog for configuring the bindings.', 'onecatalog-import'); ?></em></p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"></th>
                        <td><?php submit_button(__('Save settings', 'onecatalog-import'), 'primary', 'submit', false); ?></td>
                    </tr>
                </table>
                <script>
                (function () {
                    var rowAttr = document.getElementById('onecatalog-row-attribute');
                    var rowPT   = document.getElementById('onecatalog-row-post_type');
                    var rowTax  = document.getElementById('onecatalog-row-taxonomy');
                    function sync() {
                        var checked = document.querySelector('input[name="onecatalog_collection_object"]:checked');
                        var v = checked ? checked.value : 'post_type';
                        if (rowAttr) { rowAttr.style.display = (v === 'attribute') ? '' : 'none'; }
                        if (rowTax)  { rowTax.style.display  = (v === 'taxonomy')  ? '' : 'none'; }
                        if (rowPT)   { rowPT.style.display   = (v === 'post_type') ? '' : 'none'; }
                    }
                    document.querySelectorAll('input[name="onecatalog_collection_object"]').forEach(function (r) {
                        r.addEventListener('change', sync);
                    });
                    sync();

                    // Бренд: показ целевой строки (атрибут / таксономия / пост-тип) по выбранному способу.
                    var bRowAttr = document.getElementById('onecatalog-brand-row-attribute');
                    var bRowTax  = document.getElementById('onecatalog-brand-row-taxonomy');
                    var bRowPT   = document.getElementById('onecatalog-brand-row-post_type');
                    function syncBrand() {
                        var checked = document.querySelector('input[name="onecatalog_brand_object"]:checked');
                        var v = checked ? checked.value : 'taxonomy';
                        if (bRowAttr) { bRowAttr.style.display = (v === 'attribute') ? '' : 'none'; }
                        if (bRowTax)  { bRowTax.style.display  = (v === 'taxonomy')  ? '' : 'none'; }
                        if (bRowPT)   { bRowPT.style.display   = (v === 'post_type') ? '' : 'none'; }
                    }
                    document.querySelectorAll('input[name="onecatalog_brand_object"]').forEach(function (r) {
                        r.addEventListener('change', syncBrand);
                    });
                    syncBrand();

                    // Страна: показ целевой строки (атрибут / таксономия) по выбранному способу.
                    var cRowAttr = document.getElementById('onecatalog-country-row-attribute');
                    var cRowTax  = document.getElementById('onecatalog-country-row-taxonomy');
                    function syncCountry() {
                        var checked = document.querySelector('input[name="onecatalog_country_object"]:checked');
                        var v = checked ? checked.value : 'attribute';
                        if (cRowAttr) { cRowAttr.style.display = (v === 'attribute') ? '' : 'none'; }
                        if (cRowTax)  { cRowTax.style.display  = (v === 'taxonomy')  ? '' : 'none'; }
                    }
                    document.querySelectorAll('input[name="onecatalog_country_object"]').forEach(function (r) {
                        r.addEventListener('change', syncCountry);
                    });
                    syncCountry();
                })();
                </script>
            </form>
        </div>
        <?php
    }

    /** Подстраница «Характеристики»: привязки specification_id → WC-атрибут (есть только при включённом тумблере). */
    public static function render_characteristics(): void
    {
        // Доступна только при включённом ручном сопоставлении.
        if (! self::attr_map_enabled()) {
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('Characteristics', 'onecatalog-import'); ?></h1>
                <p><?php esc_html_e('Manual mapping is off. Enable it on the OneCatalog settings page first.', 'onecatalog-import'); ?></p>
                <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=onecatalog')); ?>"><?php esc_html_e('← OneCatalog settings', 'onecatalog-import'); ?></a></p>
            </div>
            <?php
            return;
        }

        $saved = self::save_characteristics();

        // Принудительное обновление кэша справочника характеристик (ссылка «↻»).
        if (isset($_GET['onecatalog_refresh_specs'])
            && current_user_can('manage_woocommerce')
            && isset($_GET['_wpnonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'onecatalog_refresh_specs')
        ) {
            Api::specifications(true);
        }

        $wc_attrs   = function_exists('wc_get_attribute_taxonomies') ? wc_get_attribute_taxonomies() : [];
        $attr_map   = self::attr_map();                 // [specification_id => attribute_id]
        $attr_bool  = get_option(self::OPTION_ATTR_BOOL, []); // [spec_id => ['t'=>,'f'=>]]
        $attr_bool  = is_array($attr_bool) ? $attr_bool : [];

        // Термы каждого атрибута — для выпадающих списков значений bool (true/false).
        $attr_terms = [];
        foreach ($wc_attrs as $a) {
            $tax   = wc_attribute_taxonomy_name($a->attribute_name);
            $names = taxonomy_exists($tax) ? get_terms(['taxonomy' => $tax, 'hide_empty' => false, 'fields' => 'names']) : [];
            $attr_terms[(int) $a->attribute_id] = is_array($names) ? array_values($names) : [];
        }
        $specs      = Api::specifications();             // [ ['id','label','slug','type'], … ] из Wiki API
        $spec_label = [];
        foreach ($specs as $s) {
            $spec_label[(int) $s['id']] = $s['label'];
        }

        // Строки = ВСЕ характеристики из API (по одной), + «осиротевшие» привязки,
        // id которых уже нет в списке (чтобы не потерять их при сохранении).
        $rows = [];
        foreach ($specs as $s) {
            $rows[] = ['id' => (int) $s['id'], 'label' => (string) $s['label'], 'type' => (string) ($s['type'] ?? 'text'), 'stale' => false];
        }
        foreach ($attr_map as $spec_id => $attr_id) {
            if (! isset($spec_label[$spec_id])) {
                $rows[] = [
                    'id'    => (int) $spec_id,
                    /* translators: %d: characteristic id */
                    'label' => sprintf(__('characteristic #%d (not in list)', 'onecatalog-import'), (int) $spec_id),
                    'type'  => 'text',
                    'stale' => true,
                ];
            }
        }
        $refresh_url = wp_nonce_url(admin_url('admin.php?page=onecatalog-characteristics&onecatalog_refresh_specs=1'), 'onecatalog_refresh_specs');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Characteristics', 'onecatalog-import'); ?></h1>
            <p><?php esc_html_e('For each OneCatalog characteristic pick the WooCommerce attribute to bind it to. Characteristics left unbound are skipped on import.', 'onecatalog-import'); ?></p>

            <?php if ($saved) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Bindings saved.', 'onecatalog-import'); ?></p></div>
            <?php endif; ?>
            <?php if (! $specs) : ?>
                <div class="notice notice-warning"><p><?php esc_html_e('Could not load the characteristics list from the Wiki API (check the token / connection). Already-saved bindings are kept.', 'onecatalog-import'); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('onecatalog_save_characteristics', 'onecatalog_chars_nonce'); ?>
                <?php if ($rows) : ?>
                    <table class="widefat striped" style="max-width:680px;">
                        <thead>
                            <tr>
                                <th style="width:48%;"><?php esc_html_e('OneCatalog characteristic', 'onecatalog-import'); ?></th>
                                <th><?php esc_html_e('WooCommerce attribute', 'onecatalog-import'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row) : $sel = (int) ($attr_map[$row['id']] ?? 0); $is_bool = ($row['type'] ?? '') === 'boolean'; ?>
                                <tr>
                                    <th scope="row" style="font-weight:600;<?php echo $row['stale'] ? 'opacity:.7;' : ''; ?>">
                                        <?php echo esc_html($row['label']); ?>
                                        <?php if ($is_bool) : ?><br><span class="description" style="font-weight:400;"><?php esc_html_e('yes / no', 'onecatalog-import'); ?></span><?php endif; ?>
                                    </th>
                                    <td>
                                        <select name="onecatalog_attr_map[<?php echo (int) $row['id']; ?>]" style="min-width:280px;">
                                            <option value="0"><?php esc_html_e('— skip (no binding) —', 'onecatalog-import'); ?></option>
                                            <?php foreach ($wc_attrs as $a) : ?>
                                                <option value="<?php echo (int) $a->attribute_id; ?>" <?php selected($sel, (int) $a->attribute_id); ?>><?php echo esc_html($a->attribute_label . ' (pa_' . $a->attribute_name . ')'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($is_bool) :
                                            $bv    = is_array($attr_bool[$row['id']] ?? null) ? $attr_bool[$row['id']] : ['t' => '', 'f' => ''];
                                            $terms = $attr_terms[$sel] ?? [];
                                            $bool_select = static function (string $name, string $current) use ($terms) {
                                                echo '<select name="' . esc_attr($name) . '" style="min-width:130px;">';
                                                echo '<option value="">' . esc_html__('— not set —', 'onecatalog-import') . '</option>';
                                                $seen = false;
                                                foreach ($terms as $tn) {
                                                    $sel_attr = selected($current, $tn, false);
                                                    if ('' !== $sel_attr) { $seen = true; }
                                                    echo '<option value="' . esc_attr($tn) . '" ' . $sel_attr . '>' . esc_html($tn) . '</option>';
                                                }
                                                if ('' !== $current && ! $seen) { // сохранённое значение, которого нет в термах атрибута — не теряем
                                                    echo '<option value="' . esc_attr($current) . '" selected>' . esc_html($current) . '</option>';
                                                }
                                                echo '</select>';
                                            };
                                        ?>
                                            <div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap;">
                                                <label style="font-size:12px;"><?php esc_html_e('value for “true”', 'onecatalog-import'); ?><br>
                                                    <?php $bool_select('onecatalog_attr_bool[' . (int) $row['id'] . '][t]', (string) ($bv['t'] ?? '')); ?></label>
                                                <label style="font-size:12px;"><?php esc_html_e('value for “false”', 'onecatalog-import'); ?><br>
                                                    <?php $bool_select('onecatalog_attr_bool[' . (int) $row['id'] . '][f]', (string) ($bv['f'] ?? '')); ?></label>
                                            </div>
                                            <p class="description" style="margin:4px 0 0;"><?php esc_html_e('Boolean characteristic: pick a term from the bound attribute for true / false. Empty (or no value from OneCatalog) counts as false.', 'onecatalog-import'); ?></p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p class="description"><?php esc_html_e('No characteristics found. Set the Wiki API token, then use “Refresh characteristics”.', 'onecatalog-import'); ?></p>
                <?php endif; ?>
                <p>
                    <a class="button-link" href="<?php echo esc_url($refresh_url); ?>"><?php esc_html_e('↻ Refresh characteristics', 'onecatalog-import'); ?></a>
                </p>
                <?php if (! $wc_attrs) : ?>
                    <p class="description"><?php
                        printf(
                            /* translators: %s: link to the WooCommerce attributes admin page */
                            esc_html__('No WooCommerce attributes exist yet — create them under %s, then bind here.', 'onecatalog-import'),
                            '<a href="' . esc_url(admin_url('edit.php?post_type=product&page=product_attributes')) . '">' . esc_html__('Products → Attributes', 'onecatalog-import') . '</a>'
                        );
                    ?></p>
                <?php endif; ?>
                <p class="description"><?php esc_html_e('The characteristics list is loaded from the Wiki API (cached). Values are still matched by name (or created) inside the bound attribute.', 'onecatalog-import'); ?></p>
                <?php submit_button(__('Save bindings', 'onecatalog-import')); ?>
            </form>
        </div>
        <script>
        (function () {
            // Значения bool — выпадающий список термов привязанного атрибута; при смене
            // атрибута в строке переподтягиваем термы в списки true/false.
            var TERMS = <?php echo wp_json_encode($attr_terms); ?>;
            document.querySelectorAll('select[name^="onecatalog_attr_map["]').forEach(function (attrSel) {
                var row = attrSel.closest('tr');
                var boolSelects = row ? row.querySelectorAll('select[name^="onecatalog_attr_bool"]') : [];
                if (! boolSelects.length) { return; }
                attrSel.addEventListener('change', function () {
                    var terms = TERMS[attrSel.value] || [];
                    boolSelects.forEach(function (bs) {
                        var cur = bs.value;
                        while (bs.options.length > 1) { bs.remove(1); } // оставляем «— not set —»
                        var has = false;
                        terms.forEach(function (tn) {
                            var o = document.createElement('option');
                            o.value = tn; o.textContent = tn;
                            if (tn === cur) { o.selected = true; has = true; }
                            bs.appendChild(o);
                        });
                        if (cur && ! has) {
                            var o = document.createElement('option');
                            o.value = cur; o.textContent = cur; o.selected = true;
                            bs.appendChild(o);
                        }
                    });
                });
            });
        })();
        </script>
        <?php
    }
}
