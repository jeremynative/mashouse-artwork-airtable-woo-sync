<?php
/**
 * Plugin Name: Ma's House Airtable Artwork Woo Sync
 * Description: Safely syncs available artworks from Ma's House Airtable Artwork Inventory into WooCommerce products.
 * Version: 0.1.0
 * Author: Ma's House / Codex
 */

if (!defined('ABSPATH')) {
    exit;
}

final class MA_Artwork_Airtable_Woo_Sync {
    private const OPTION_KEY = 'ma_artwork_airtable_woo_sync_options';
    private const LAST_RESULT_KEY = 'ma_artwork_airtable_woo_sync_last_result';
    private const FIELD_CACHE_KEY = 'ma_artwork_airtable_woo_sync_field_cache';
    private const VISITOR_FIELD_CACHE_KEY = 'ma_artwork_airtable_woo_sync_visitor_field_cache';
    private const RSVP_QUEUE_KEY = 'ma_artwork_airtable_woo_sync_rsvp_queue';
    private const RSVP_ATTENDEE_META_RECORD_ID = '_ma_airtable_visitor_record_id';
    private const RSVP_ATTENDEE_META_LAST_SYNCED = '_ma_airtable_visitor_last_synced_at';
    private const CRON_HOOK = 'ma_artwork_airtable_woo_sync_cron';
    private const LOCK_KEY = 'ma_artwork_airtable_woo_sync_lock';
    private const META_PREFIX = '_ma_artwork_airtable_';
    private static bool $shop_on_view_rendered = false;
    private static bool $catalog_css_printed = false;
    private static array $rendered_single_product_panels = [];

    public static function init(): void {
        add_filter('cron_schedules', [__CLASS__, 'add_cron_schedule']);
        add_action(self::CRON_HOOK, [__CLASS__, 'sync']);
        add_action(self::CRON_HOOK, [__CLASS__, 'process_rsvp_queue'], 20);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
        add_action('admin_menu', [__CLASS__, 'add_admin_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('init', [__CLASS__, 'ensure_runtime_setup'], 20);
        add_action('event_tickets_rsvp_attendee_created', [__CLASS__, 'sync_rsvp_attendee_created'], 20, 4);
        add_action('event_tickets_rsvp_ticket_created', [__CLASS__, 'sync_rsvp_ticket_created'], 25, 4);
        add_filter('woocommerce_product_tabs', [__CLASS__, 'add_scale_product_tab']);
        add_action('woocommerce_single_product_summary', [__CLASS__, 'render_single_product_artwork_panel'], 21);
        add_action('woocommerce_product_meta_start', [__CLASS__, 'render_single_product_artwork_panel_in_meta'], 5);
        add_action('woocommerce_before_add_to_cart_form', [__CLASS__, 'render_purchase_support_note'], 4);
        add_action('woocommerce_after_shop_loop_item_title', [__CLASS__, 'render_loop_on_view_label'], 11);
        add_action('woocommerce_before_main_content', [__CLASS__, 'render_shop_on_view_section'], 25);
        add_action('woocommerce_before_shop_loop', [__CLASS__, 'render_shop_on_view_section'], 4);
        add_action('woocommerce_before_shop_loop', [__CLASS__, 'render_all_art_heading'], 6);
        add_action('wp_head', [__CLASS__, 'render_catalog_head_guard'], 1);
        add_action('wp_head', [__CLASS__, 'render_global_site_polish_css'], 18);
        add_action('wp_head', [__CLASS__, 'render_artist_profile_css'], 20);
        add_action('wp_footer', [__CLASS__, 'render_single_product_artwork_panel_fallback'], 12);
        add_action('wp_footer', [__CLASS__, 'render_catalog_footer_assets'], 20);
        add_action('wp_enqueue_scripts', [__CLASS__, 'optimize_frontend_product_assets'], 100);
        add_filter('the_content', [__CLASS__, 'append_product_body_sections'], 30);
        add_shortcode('ma_on_view_now', [__CLASS__, 'on_view_shortcode']);
        add_shortcode('ma_artist_artworks', [__CLASS__, 'artist_artworks_shortcode']);
    }

    public static function activate(): void {
        $options = self::options();
        if (empty($options['cron_secret'])) {
            $options['cron_secret'] = wp_generate_password(32, false, false);
            update_option(self::OPTION_KEY, $options, false);
        }
        wp_clear_scheduled_hook(self::CRON_HOOK);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 1800, 'ma_artwork_every_thirty_minutes', self::CRON_HOOK);
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function ensure_runtime_setup(): void {
        $options = self::options();
        $changed = false;

        if (empty($options['cron_secret'])) {
            $options['cron_secret'] = wp_generate_password(32, false, false);
            $changed = true;
        }

        if ($changed) {
            update_option(self::OPTION_KEY, $options, false);
        }

        $schedule = wp_get_schedule(self::CRON_HOOK);
        if ($schedule === 'ma_artwork_every_five_minutes') {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 1800, 'ma_artwork_every_thirty_minutes', self::CRON_HOOK);
        }
    }

    public static function add_cron_schedule(array $schedules): array {
        $schedules['ma_artwork_every_five_minutes'] = [
            'interval' => 300,
            'display' => 'Every 5 minutes',
        ];
        $schedules['ma_artwork_every_thirty_minutes'] = [
            'interval' => 1800,
            'display' => 'Every 30 minutes',
        ];
        return $schedules;
    }

    public static function register_rest_routes(): void {
        register_rest_route('ma-artwork-sync/v1', '/run', [
            'methods' => ['GET', 'POST'],
            'callback' => [__CLASS__, 'rest_run'],
            'permission_callback' => [__CLASS__, 'rest_permission'],
        ]);
        register_rest_route('ma-artwork-sync/v1', '/fields', [
            'methods' => ['GET'],
            'callback' => [__CLASS__, 'rest_fields'],
            'permission_callback' => [__CLASS__, 'rest_permission'],
        ]);
        register_rest_route('ma-artwork-sync/v1', '/store-catalog', [
            'methods' => ['GET'],
            'callback' => [__CLASS__, 'rest_store_catalog'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function rest_permission(WP_REST_Request $request): bool {
        $options = self::options();
        $secret = (string) $request->get_param('secret');
        return !empty($options['cron_secret']) && hash_equals((string) $options['cron_secret'], $secret);
    }

    public static function rest_run(WP_REST_Request $request): WP_REST_Response {
        $result = self::sync([
            'dry_run' => (bool) $request->get_param('dry_run'),
            'max_records' => (int) $request->get_param('max_records'),
            'inventory_number' => self::text($request->get_param('inventory_number') ?? ''),
            'force_all' => (bool) $request->get_param('force_all'),
        ]);
        return new WP_REST_Response($result, empty($result['errors']) ? 200 : 500);
    }

    public static function rest_fields(): WP_REST_Response {
        try {
            return new WP_REST_Response(['fields' => self::discover_airtable_fields(self::options(), true)], 200);
        } catch (Throwable $error) {
            return new WP_REST_Response(['fields' => [], 'errors' => [$error->getMessage()]], 500);
        }
    }

    public static function rest_store_catalog(WP_REST_Request $request): WP_REST_Response {
        $artist = self::text($request->get_param('artist') ?? '');
        $medium = self::text($request->get_param('medium') ?? '');
        $items = self::shop_catalog_products_for_custom_grid([
            'artist' => $artist ? array_filter(array_map('trim', explode(',', $artist))) : [],
            'medium' => $medium ? array_filter(array_map('trim', explode(',', $medium))) : [],
        ]);
        return new WP_REST_Response(['items' => $items, 'count' => count($items)], 200);
    }

    public static function add_admin_page(): void {
        add_management_page(
            'Ma Artwork Airtable Sync',
            'Ma Artwork Sync',
            'manage_woocommerce',
            'ma-artwork-airtable-sync',
            [__CLASS__, 'render_admin_page']
        );
    }

    public static function register_settings(): void {
        register_setting('ma_artwork_airtable_woo_sync', self::OPTION_KEY, [
            'sanitize_callback' => [__CLASS__, 'sanitize_options'],
        ]);
    }

    public static function sanitize_options($input): array {
        $current = self::options();
        $input = is_array($input) ? $input : [];
        $field_map = is_array($input['field_map'] ?? null) ? $input['field_map'] : [];
        $sanitized_map = [];
        foreach (array_keys(self::default_field_map()) as $key) {
            $sanitized_map[$key] = sanitize_text_field($field_map[$key] ?? $current['field_map'][$key] ?? '');
        }
        $visitor_field_map = is_array($input['visitor_field_map'] ?? null) ? $input['visitor_field_map'] : [];
        $sanitized_visitor_map = [];
        foreach (array_keys(self::default_visitor_field_map()) as $key) {
            $sanitized_visitor_map[$key] = sanitize_text_field($visitor_field_map[$key] ?? $current['visitor_field_map'][$key] ?? '');
        }

        return [
            'airtable_token' => sanitize_text_field($input['airtable_token'] ?? $current['airtable_token']),
            'base_id' => sanitize_text_field($input['base_id'] ?? $current['base_id']),
            'table_id' => sanitize_text_field($input['table_id'] ?? $current['table_id']),
            'artist_table_id' => sanitize_text_field($input['artist_table_id'] ?? $current['artist_table_id']),
            'artist_portrait_drive_folder_url' => esc_url_raw($input['artist_portrait_drive_folder_url'] ?? $current['artist_portrait_drive_folder_url']),
            'exhibit_table_id' => sanitize_text_field($input['exhibit_table_id'] ?? $current['exhibit_table_id']),
            'visitor_table_id' => sanitize_text_field($input['visitor_table_id'] ?? $current['visitor_table_id']),
            'view' => sanitize_text_field($input['view'] ?? $current['view']),
            'batch_size' => max(1, min(100, (int) ($input['batch_size'] ?? $current['batch_size']))),
            'write_shop_url' => !empty($input['write_shop_url']) ? 1 : 0,
            'last_sync_at' => sanitize_text_field($input['last_sync_at'] ?? $current['last_sync_at']),
            'last_visitor_sync_at' => sanitize_text_field($input['last_visitor_sync_at'] ?? $current['last_visitor_sync_at']),
            'cron_secret' => sanitize_text_field($input['cron_secret'] ?? $current['cron_secret']),
            'field_map' => wp_parse_args($sanitized_map, self::default_field_map()),
            'visitor_field_map' => wp_parse_args($sanitized_visitor_map, self::default_visitor_field_map()),
        ];
    }

    public static function render_admin_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions.');
        }

        if (isset($_POST['ma_discover_fields']) && check_admin_referer('ma_discover_fields')) {
            try {
                $fields = self::discover_airtable_fields(self::options(), true);
                echo '<div class="notice notice-success"><p>Found ' . esc_html((string) count($fields)) . ' Airtable fields.</p></div>';
            } catch (Throwable $error) {
                echo '<div class="notice notice-error"><p>' . esc_html($error->getMessage()) . '</p></div>';
            }
        }
        if (isset($_POST['ma_discover_visitor_fields']) && check_admin_referer('ma_discover_visitor_fields')) {
            try {
                $fields = self::discover_visitor_airtable_fields(self::options(), true);
                echo '<div class="notice notice-success"><p>Found ' . esc_html((string) count($fields)) . ' Visitors fields.</p></div>';
            } catch (Throwable $error) {
                echo '<div class="notice notice-error"><p>' . esc_html($error->getMessage()) . '</p></div>';
            }
        }
        if (isset($_POST['ma_process_rsvp_queue']) && check_admin_referer('ma_process_rsvp_queue')) {
            $result = self::process_rsvp_queue(50);
            echo '<div class="notice notice-info"><p><strong>RSVP visitor sync:</strong> ' . esc_html(wp_json_encode($result)) . '</p></div>';
        }
        if (isset($_POST['ma_dry_run_one']) && check_admin_referer('ma_dry_run_one')) {
            $result = self::sync(['dry_run' => true, 'max_records' => 1, 'force_all' => true]);
            echo '<div class="notice notice-info"><p><strong>One-record dry run:</strong> ' . esc_html(wp_json_encode($result)) . '</p></div>';
        }
        if (isset($_POST['ma_run_sync']) && check_admin_referer('ma_run_sync')) {
            $result = self::sync(['force_all' => true]);
            echo '<div class="notice notice-info"><p><strong>Sync result:</strong> ' . esc_html(wp_json_encode($result)) . '</p></div>';
        }

        $options = self::options();
        $fields = get_option(self::FIELD_CACHE_KEY, []);
        $visitor_fields = get_option(self::VISITOR_FIELD_CACHE_KEY, []);
        $rsvp_queue = get_option(self::RSVP_QUEUE_KEY, []);
        $last_result = get_option(self::LAST_RESULT_KEY, []);
        $cron_url = rest_url('ma-artwork-sync/v1/run?secret=' . rawurlencode($options['cron_secret']));
        $one_record_url = add_query_arg(['dry_run' => 1, 'max_records' => 1], $cron_url);
        ?>
        <div class="wrap">
            <h1>Ma Artwork Airtable Sync</h1>
            <p>This sync only creates or updates WooCommerce products that are matched by SKU from Airtable Inventory Number. It marks synced products with Ma's House Airtable metadata and does not touch books, merch, events, donations, or other unrelated products.</p>

            <form method="post" action="options.php">
                <?php settings_fields('ma_artwork_airtable_woo_sync'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ma_airtable_token">Airtable token</label></th>
                        <td><input id="ma_airtable_token" class="regular-text" type="password" name="<?php echo esc_attr(self::OPTION_KEY); ?>[airtable_token]" value="<?php echo esc_attr($options['airtable_token']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ma_base_id">Airtable base ID</label></th>
                        <td><input id="ma_base_id" class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[base_id]" value="<?php echo esc_attr($options['base_id']); ?>" placeholder="app..."></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ma_table_id">Artwork Inventory table ID/name</label></th>
                        <td><input id="ma_table_id" class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[table_id]" value="<?php echo esc_attr($options['table_id']); ?>" placeholder="Artwork Inventory or tbl..."></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ma_artist_table_id">Artists table ID/name</label></th>
                        <td><input id="ma_artist_table_id" class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[artist_table_id]" value="<?php echo esc_attr($options['artist_table_id']); ?>" placeholder="Artists or tbl..."></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ma_artist_portrait_drive_folder_url">Artist portrait Drive folder URL</label></th>
                        <td>
                            <input id="ma_artist_portrait_drive_folder_url" class="large-text" type="url" name="<?php echo esc_attr(self::OPTION_KEY); ?>[artist_portrait_drive_folder_url]" value="<?php echo esc_attr($options['artist_portrait_drive_folder_url']); ?>" placeholder="https://drive.google.com/drive/folders/...">
                            <p class="description">Optional reference folder for artist portraits. The sync reads public portrait URLs or Airtable portrait attachments from the Artists tab; if you use Drive, share files publicly and paste/store the public file URL in Airtable.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ma_exhibit_table_id">Optional Exhibits table ID/name</label></th>
                        <td><input id="ma_exhibit_table_id" class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[exhibit_table_id]" value="<?php echo esc_attr($options['exhibit_table_id']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ma_visitor_table_id">Visitors table ID/name</label></th>
                        <td>
                            <input id="ma_visitor_table_id" class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[visitor_table_id]" value="<?php echo esc_attr($options['visitor_table_id']); ?>" placeholder="Visitors or tbl...">
                            <p class="description">Used when someone RSVPs through The Events Calendar / Event Tickets. The event date is written as the visit date.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ma_view">Optional Airtable view</label></th>
                        <td><input id="ma_view" class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[view]" value="<?php echo esc_attr($options['view']); ?>" placeholder="Leave blank to read all available records"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ma_batch_size">Batch size</label></th>
                        <td><input id="ma_batch_size" type="number" min="1" max="100" name="<?php echo esc_attr(self::OPTION_KEY); ?>[batch_size]" value="<?php echo esc_attr((string) $options['batch_size']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Woo sync options</th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[write_shop_url]" value="1" <?php checked($options['write_shop_url']); ?>> Write WooCommerce product URL back to Airtable if a matching field exists</label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ma_last_sync">Last sync timestamp</label></th>
                        <td><input id="ma_last_sync" class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[last_sync_at]" value="<?php echo esc_attr($options['last_sync_at']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ma_last_visitor_sync">Last visitor RSVP sync</label></th>
                        <td><input id="ma_last_visitor_sync" class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[last_visitor_sync_at]" value="<?php echo esc_attr($options['last_visitor_sync_at']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ma_cron_secret">Manual sync secret</label></th>
                        <td><input id="ma_cron_secret" class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cron_secret]" value="<?php echo esc_attr($options['cron_secret']); ?>"></td>
                    </tr>
                </table>

                <h2>Field Mapping</h2>
                <p>Defaults are aliases for likely Ma's House field names. After saving the Ma's House Airtable token/base/table, use "Fetch Airtable fields" and adjust only what differs.</p>
                <table class="widefat striped" style="max-width: 980px;">
                    <thead><tr><th>Purpose</th><th>Airtable field name</th><th>Common aliases this plugin tries</th></tr></thead>
                    <tbody>
                    <?php foreach (self::default_field_map() as $key => $default) : ?>
                        <tr>
                            <td><code><?php echo esc_html($key); ?></code></td>
                            <td><input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[field_map][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($options['field_map'][$key] ?? $default); ?>"></td>
                            <td><?php echo esc_html(implode(', ', self::field_aliases($key))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <h2>Visitors RSVP Field Mapping</h2>
                <p>These fields are used when an Event Tickets RSVP is copied into the Airtable Visitors tab. The sync only writes fields that exist in the Visitors table, so extra mappings are safe.</p>
                <table class="widefat striped" style="max-width: 980px;">
                    <thead><tr><th>Purpose</th><th>Airtable field name</th><th>Common aliases this plugin tries</th></tr></thead>
                    <tbody>
                    <?php foreach (self::default_visitor_field_map() as $key => $default) : ?>
                        <tr>
                            <td><code><?php echo esc_html($key); ?></code></td>
                            <td><input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[visitor_field_map][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($options['visitor_field_map'][$key] ?? $default); ?>"></td>
                            <td><?php echo esc_html(implode(', ', self::visitor_field_aliases($key))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button('Save sync settings'); ?>
            </form>

            <h2>Connection and Test</h2>
            <form method="post" style="display:inline-block;margin-right:10px;">
                <?php wp_nonce_field('ma_discover_fields'); ?>
                <?php submit_button('Fetch Airtable fields', 'secondary', 'ma_discover_fields', false); ?>
            </form>
            <form method="post" style="display:inline-block;margin-right:10px;">
                <?php wp_nonce_field('ma_discover_visitor_fields'); ?>
                <?php submit_button('Fetch Visitors fields', 'secondary', 'ma_discover_visitor_fields', false); ?>
            </form>
            <form method="post" style="display:inline-block;margin-right:10px;">
                <?php wp_nonce_field('ma_process_rsvp_queue'); ?>
                <?php submit_button('Retry RSVP visitor sync', 'secondary', 'ma_process_rsvp_queue', false); ?>
            </form>
            <form method="post" style="display:inline-block;margin-right:10px;">
                <?php wp_nonce_field('ma_dry_run_one'); ?>
                <?php submit_button('Dry run one artwork', 'secondary', 'ma_dry_run_one', false); ?>
            </form>
            <form method="post" style="display:inline-block;">
                <?php wp_nonce_field('ma_run_sync'); ?>
                <?php submit_button('Sync all available artworks', 'primary', 'ma_run_sync', false); ?>
            </form>

            <h2>Manual Sync URL</h2>
            <p>Use this for a secret manual sync or SiteGround cron. WordPress cron also runs every 5 minutes.</p>
            <p><code><?php echo esc_html($cron_url); ?></code></p>
            <p>One-record dry run URL:</p>
            <p><code><?php echo esc_html($one_record_url); ?></code></p>

            <?php if (is_array($fields) && $fields) : ?>
                <h2>Discovered Airtable Fields</h2>
                <p><?php echo esc_html(implode(', ', $fields)); ?></p>
            <?php endif; ?>
            <?php if (is_array($visitor_fields) && $visitor_fields) : ?>
                <h2>Discovered Visitors Fields</h2>
                <p><?php echo esc_html(implode(', ', $visitor_fields)); ?></p>
            <?php endif; ?>
            <h2>RSVP Visitor Queue</h2>
            <p><?php echo esc_html((string) count(is_array($rsvp_queue) ? $rsvp_queue : [])); ?> RSVP attendee(s) waiting to retry.</p>
            <?php if (is_array($last_result) && $last_result) : ?>
                <h2>Last Result</h2>
                <pre style="max-width:980px;white-space:pre-wrap;"><?php echo esc_html(wp_json_encode($last_result, JSON_PRETTY_PRINT)); ?></pre>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function sync(array $args = []): array {
        if (!class_exists('WooCommerce')) {
            return ['checked' => 0, 'updated' => 0, 'errors' => ['WooCommerce is not active.']];
        }

        $options = self::options();
        if (empty($options['airtable_token']) || empty($options['base_id']) || empty($options['table_id'])) {
            return ['checked' => 0, 'updated' => 0, 'errors' => ['Missing Airtable token, base ID, or Artwork Inventory table ID.']];
        }

        $dry_run = !empty($args['dry_run']);
        $max_records = max(0, (int) ($args['max_records'] ?? 0));
        $inventory_number = self::text($args['inventory_number'] ?? '');
        $force_all = !empty($args['force_all']);
        if (!$dry_run && !$force_all && !$inventory_number && !$max_records) {
            $max_records = 10;
        }
        $started_at = gmdate('c');
        $checked = 0;
        $updated = 0;
        $skipped = [];
        $errors = [];
        $dry_run_results = [];
        if (!$dry_run && get_transient(self::LOCK_KEY)) {
            return [
                'dry_run' => false,
                'checked' => 0,
                'updated' => 0,
                'skipped' => [],
                'errors' => ['Sync already running; skipped this run to protect hosting resources.'],
                'last_sync_at' => (string) $options['last_sync_at'],
            ];
        }
        if (!$dry_run) {
            set_transient(self::LOCK_KEY, $started_at, 15 * MINUTE_IN_SECONDS);
        }

        try {
            if (!$dry_run) {
                self::ensure_artist_portrait_airtable_fields($options);
            }
            $records = self::list_airtable_records($options, $force_all ? '' : (string) $options['last_sync_at'], $inventory_number, $max_records);
            $exhibits = self::list_linked_exhibit_records($records, $options);
        } catch (Throwable $error) {
            if (!$dry_run) {
                delete_transient(self::LOCK_KEY);
            }
            return ['checked' => 0, 'updated' => 0, 'errors' => [$error->getMessage()]];
        }

        foreach ($records as $record) {
            $checked++;
            try {
                $result = self::sync_record($record, $options, $dry_run, $exhibits);
                if (!empty($result['updated'])) {
                    $updated++;
                    if ($dry_run && count($dry_run_results) < 5) {
                        $dry_run_results[] = $result;
                    }
                } elseif (!empty($result['reason'])) {
                    $skipped[] = $result['reason'];
                }
            } catch (Throwable $error) {
                $errors[] = $error->getMessage();
            }
        }

        if (!$dry_run) {
            $options['last_sync_at'] = $started_at;
            update_option(self::OPTION_KEY, $options, false);
        }

        $summary = [
            'dry_run' => $dry_run,
            'checked' => $checked,
            'updated' => $updated,
            'skipped' => array_slice(array_values(array_unique($skipped)), 0, 12),
            'errors' => $errors,
            'last_sync_at' => $started_at,
        ];
        if ($dry_run) {
            $summary['dry_run_results'] = $dry_run_results;
        }
        update_option(self::LAST_RESULT_KEY, $summary, false);
        if (!$dry_run) {
            delete_transient(self::LOCK_KEY);
        }
        return $summary;
    }

    public static function sync_rsvp_attendee_created($attendee_id, $post_id, $order_id, $product_id): void {
        self::sync_rsvp_attendee_to_airtable((int) $attendee_id, (int) $post_id, (string) $order_id, (int) $product_id);
    }

    public static function sync_rsvp_ticket_created($attendee_id, $post_id, $product_id, $order_attendee_id): void {
        if (get_post_meta((int) $attendee_id, self::RSVP_ATTENDEE_META_RECORD_ID, true)) {
            return;
        }
        $order_id = self::text(get_post_meta((int) $attendee_id, '_tribe_rsvp_order', true));
        self::sync_rsvp_attendee_to_airtable((int) $attendee_id, (int) $post_id, $order_id, (int) $product_id);
    }

    public static function process_rsvp_queue(int $limit = 20): array {
        $queue = get_option(self::RSVP_QUEUE_KEY, []);
        if (!is_array($queue) || !$queue) {
            return ['checked' => 0, 'synced' => 0, 'remaining' => 0, 'errors' => []];
        }

        $checked = 0;
        $synced = 0;
        $errors = [];
        foreach ($queue as $key => $item) {
            if ($checked >= $limit) {
                break;
            }
            $checked++;
            $attendee_id = (int) ($item['attendee_id'] ?? $key);
            $post_id = (int) ($item['post_id'] ?? get_post_meta($attendee_id, '_tribe_rsvp_event', true));
            $product_id = (int) ($item['product_id'] ?? get_post_meta($attendee_id, '_tribe_rsvp_product', true));
            $order_id = self::text($item['order_id'] ?? get_post_meta($attendee_id, '_tribe_rsvp_order', true));

            $ok = self::sync_rsvp_attendee_to_airtable($attendee_id, $post_id, $order_id, $product_id, false);
            if ($ok) {
                unset($queue[$key]);
                $synced++;
                continue;
            }

            $queue[$key]['attempts'] = (int) ($queue[$key]['attempts'] ?? 0) + 1;
            $queue[$key]['last_attempt_at'] = gmdate('c');
            $errors[] = 'Could not sync RSVP attendee ' . $attendee_id . ' yet.';
        }

        update_option(self::RSVP_QUEUE_KEY, $queue, false);
        return ['checked' => $checked, 'synced' => $synced, 'remaining' => count($queue), 'errors' => array_slice($errors, 0, 10)];
    }

    private static function sync_rsvp_attendee_to_airtable(int $attendee_id, int $post_id, string $order_id, int $product_id, bool $enqueue_on_failure = true): bool {
        if ($attendee_id <= 0) {
            return false;
        }
        if (get_post_meta($attendee_id, self::RSVP_ATTENDEE_META_RECORD_ID, true)) {
            return true;
        }

        $options = self::options();
        if (empty($options['airtable_token']) || empty($options['base_id']) || empty($options['visitor_table_id'])) {
            if ($enqueue_on_failure) {
                self::enqueue_rsvp_sync($attendee_id, $post_id, $order_id, $product_id, 'Missing Airtable Visitors settings.');
            }
            return false;
        }

        try {
            $payload = self::visitor_fields_for_rsvp_attendee($attendee_id, $post_id, $order_id, $product_id, $options);
            if (!$payload) {
                throw new RuntimeException('No matching Visitors fields were found for the RSVP payload.');
            }
            $record_id = self::create_airtable_record($options, (string) $options['visitor_table_id'], $payload);
            update_post_meta($attendee_id, self::RSVP_ATTENDEE_META_RECORD_ID, $record_id);
            update_post_meta($attendee_id, self::RSVP_ATTENDEE_META_LAST_SYNCED, gmdate('c'));

            $options['last_visitor_sync_at'] = gmdate('c');
            update_option(self::OPTION_KEY, $options, false);
            self::remove_rsvp_from_queue($attendee_id);
            return true;
        } catch (Throwable $error) {
            if ($enqueue_on_failure) {
                self::enqueue_rsvp_sync($attendee_id, $post_id, $order_id, $product_id, $error->getMessage());
            }
            return false;
        }
    }

    private static function visitor_fields_for_rsvp_attendee(int $attendee_id, int $post_id, string $order_id, int $product_id, array $options): array {
        $post_id = $post_id > 0 ? $post_id : (int) get_post_meta($attendee_id, '_tribe_rsvp_event', true);
        $product_id = $product_id > 0 ? $product_id : (int) get_post_meta($attendee_id, '_tribe_rsvp_product', true);
        $event_title = $post_id > 0 ? get_the_title($post_id) : '';
        $ticket_title = $product_id > 0 ? get_the_title($product_id) : '';
        $event_url = $post_id > 0 ? get_permalink($post_id) : '';
        $event_start = self::event_start_datetime($post_id);
        $event_end = self::event_end_datetime($post_id);
        $name = self::first_non_empty_meta($attendee_id, ['_tribe_rsvp_full_name', '_tribe_tickets_full_name', '_tec_tc_attendee_full_name']);
        $email = self::first_non_empty_meta($attendee_id, ['_tribe_rsvp_email', '_tribe_tickets_email', '_tec_tc_attendee_email']);
        $status = self::first_non_empty_meta($attendee_id, ['_tribe_rsvp_status', '_tribe_rsvp_attendee_status']);
        $created_at = get_post_time('c', true, $attendee_id);
        $extra_notes = self::rsvp_attendee_extra_notes($attendee_id);

        $values = [
            'visitor_name' => $name,
            'visitor_email' => $email,
            'visit_date' => self::date_ymd($event_start ?: $created_at),
            'event_title' => $event_title,
            'event_start' => $event_start,
            'event_end' => $event_end,
            'event_time' => self::event_time_label($event_start, $event_end),
            'event_url' => $event_url,
            'ticket_name' => $ticket_title,
            'rsvp_status' => $status ?: 'yes',
            'rsvp_order_id' => $order_id,
            'wp_event_id' => $post_id ? (string) $post_id : '',
            'wp_attendee_id' => (string) $attendee_id,
            'wp_ticket_id' => $product_id ? (string) $product_id : '',
            'source' => 'WordPress Event RSVP',
            'notes' => $extra_notes,
        ];

        $fields = [];
        $available_fields = self::discover_visitor_airtable_fields($options, false);
        foreach ($values as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $field = self::resolve_visitor_airtable_field($options, $available_fields, $key);
            if ($field === '') {
                continue;
            }
            $fields[$field] = $value;
        }
        return $fields;
    }

    private static function enqueue_rsvp_sync(int $attendee_id, int $post_id, string $order_id, int $product_id, string $reason): void {
        $queue = get_option(self::RSVP_QUEUE_KEY, []);
        $queue = is_array($queue) ? $queue : [];
        $key = (string) $attendee_id;
        $queue[$key] = [
            'attendee_id' => $attendee_id,
            'post_id' => $post_id,
            'order_id' => $order_id,
            'product_id' => $product_id,
            'queued_at' => $queue[$key]['queued_at'] ?? gmdate('c'),
            'attempts' => (int) ($queue[$key]['attempts'] ?? 0),
            'last_error' => $reason,
        ];
        update_option(self::RSVP_QUEUE_KEY, $queue, false);
    }

    private static function remove_rsvp_from_queue(int $attendee_id): void {
        $queue = get_option(self::RSVP_QUEUE_KEY, []);
        if (!is_array($queue) || !isset($queue[(string) $attendee_id])) {
            return;
        }
        unset($queue[(string) $attendee_id]);
        update_option(self::RSVP_QUEUE_KEY, $queue, false);
    }

    private static function create_airtable_record(array $options, string $table_id, array $fields): string {
        $url = sprintf(
            'https://api.airtable.com/v0/%s/%s',
            rawurlencode($options['base_id']),
            rawurlencode($table_id)
        );
        $response = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $options['airtable_token'], 'Content-Type' => 'application/json'],
            'body' => wp_json_encode(['records' => [['fields' => $fields]], 'typecast' => true]),
        ]);
        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code >= 300) {
            throw new RuntimeException("Airtable visitor create failed ({$code}): {$body}");
        }
        $json = json_decode($body, true);
        $record_id = self::text($json['records'][0]['id'] ?? '');
        if (!$record_id) {
            throw new RuntimeException('Airtable visitor create did not return a record ID.');
        }
        return $record_id;
    }

    private static function discover_visitor_airtable_fields(array $options, bool $force = false): array {
        if (empty($options['visitor_table_id'])) {
            return [];
        }
        if (!$force) {
            $cached = get_option(self::VISITOR_FIELD_CACHE_KEY, []);
            if (is_array($cached) && $cached) {
                return $cached;
            }
        }
        if (empty($options['airtable_token']) || empty($options['base_id'])) {
            throw new RuntimeException('Save Airtable token, base ID, and Visitors table first.');
        }

        $fields = self::airtable_table_field_names($options, (string) $options['visitor_table_id']);
        if (!$fields) {
            $url = sprintf(
                'https://api.airtable.com/v0/%s/%s?%s',
                rawurlencode($options['base_id']),
                rawurlencode($options['visitor_table_id']),
                http_build_query(['pageSize' => 1])
            );
            $response = wp_remote_get($url, [
                'timeout' => 30,
                'headers' => ['Authorization' => 'Bearer ' . $options['airtable_token']],
            ]);
            if (is_wp_error($response)) {
                throw new RuntimeException($response->get_error_message());
            }
            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            if ($code >= 300) {
                throw new RuntimeException("Airtable Visitors field check failed ({$code}): {$body}");
            }
            $json = json_decode($body, true);
            foreach (($json['records'] ?? []) as $record) {
                $fields = array_values(array_unique(array_merge($fields, array_keys($record['fields'] ?? []))));
            }
        }
        sort($fields, SORT_NATURAL | SORT_FLAG_CASE);
        update_option(self::VISITOR_FIELD_CACHE_KEY, $fields, false);
        return $fields;
    }

    private static function airtable_table_field_names(array $options, string $table_id): array {
        if (empty($options['airtable_token']) || empty($options['base_id']) || $table_id === '') {
            return [];
        }
        $url = 'https://api.airtable.com/v0/meta/bases/' . rawurlencode($options['base_id']) . '/tables';
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $options['airtable_token']],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 300) {
            return [];
        }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        foreach (($json['tables'] ?? []) as $table) {
            if (($table['id'] ?? '') !== $table_id && ($table['name'] ?? '') !== $table_id) {
                continue;
            }
            $fields = array_values(array_filter(array_map(static function ($field) {
                return is_array($field) ? (string) ($field['name'] ?? '') : '';
            }, $table['fields'] ?? [])));
            sort($fields, SORT_NATURAL | SORT_FLAG_CASE);
            return $fields;
        }
        return [];
    }

    private static function resolve_visitor_airtable_field(array $options, array $available_fields, string $key): string {
        $candidates = array_values(array_unique(array_filter(array_merge(
            [self::text($options['visitor_field_map'][$key] ?? self::default_visitor_field_map()[$key] ?? '')],
            self::visitor_field_aliases($key)
        ))));
        if ($available_fields) {
            foreach ($candidates as $candidate) {
                foreach ($available_fields as $field) {
                    if (strcasecmp($field, $candidate) === 0) {
                        return $field;
                    }
                }
            }
            return '';
        }
        return $candidates[0] ?? '';
    }

    private static function first_non_empty_meta(int $post_id, array $keys): string {
        foreach ($keys as $key) {
            $value = self::text(get_post_meta($post_id, $key, true));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private static function event_start_datetime(int $post_id): string {
        if ($post_id <= 0) {
            return '';
        }
        if (function_exists('tribe_get_start_date')) {
            $date = tribe_get_start_date($post_id, true, 'Y-m-d H:i:s');
            if ($date) {
                return get_gmt_from_date($date, 'c');
            }
        }
        $date = self::text(get_post_meta($post_id, '_EventStartDate', true));
        return $date ? get_gmt_from_date($date, 'c') : '';
    }

    private static function event_end_datetime(int $post_id): string {
        if ($post_id <= 0) {
            return '';
        }
        if (function_exists('tribe_get_end_date')) {
            $date = tribe_get_end_date($post_id, true, 'Y-m-d H:i:s');
            if ($date) {
                return get_gmt_from_date($date, 'c');
            }
        }
        $date = self::text(get_post_meta($post_id, '_EventEndDate', true));
        return $date ? get_gmt_from_date($date, 'c') : '';
    }

    private static function event_time_label(string $start, string $end): string {
        if (!$start) {
            return '';
        }
        $start_ts = strtotime($start);
        $end_ts = $end ? strtotime($end) : false;
        if (!$start_ts) {
            return '';
        }
        $label = wp_date('g:i a', $start_ts);
        if ($end_ts) {
            $label .= ' - ' . wp_date('g:i a', $end_ts);
        }
        return $label;
    }

    private static function rsvp_attendee_extra_notes(int $attendee_id): string {
        $meta = get_post_meta($attendee_id);
        if (!is_array($meta)) {
            return '';
        }
        $notes = [];
        foreach ($meta as $key => $values) {
            if (strpos((string) $key, '_tribe_tickets_meta_') !== 0 && strpos((string) $key, '_tec_tickets_meta_') !== 0) {
                continue;
            }
            $value = self::text(maybe_unserialize($values[0] ?? ''));
            if ($value === '') {
                continue;
            }
            $label = trim(str_replace(['_tribe_tickets_meta_', '_tec_tickets_meta_', '_'], ['', '', ' '], (string) $key));
            $notes[] = ucwords($label) . ': ' . $value;
        }
        return implode("\n", array_slice($notes, 0, 12));
    }

    private static function sync_record(array $record, array $options, bool $dry_run, array $exhibit_records): array {
        $fields = $record['fields'] ?? [];
        $title = self::field($fields, $options, 'title');
        $inventory_number = self::field($fields, $options, 'inventory_number');
        $available = self::is_available(self::field_raw($fields, $options, 'availability'));
        $price = self::money(self::field($fields, $options, 'price'));

        if (!$available) {
            return ['updated' => false, 'reason' => 'Not available.'];
        }
        if (!$title || !$inventory_number) {
            return ['updated' => false, 'reason' => 'Missing Artwork Title or Inventory Number.'];
        }
        if (!$price) {
            return ['updated' => false, 'reason' => "Missing price for {$inventory_number}."];
        }

        $existing_id = wc_get_product_id_by_sku($inventory_number);
        $product = $existing_id ? wc_get_product($existing_id) : new WC_Product_Simple();
        if (!$product) {
            throw new RuntimeException("Unable to load WooCommerce product for SKU {$inventory_number}.");
        }
        if ($existing_id && (string) $product->get_sku() !== (string) $inventory_number) {
            throw new RuntimeException("SKU mismatch for product {$existing_id}; expected {$inventory_number}.");
        }

        $year = self::field($fields, $options, 'year');
        $medium = self::field($fields, $options, 'medium');
        $dimensions = self::field($fields, $options, 'dimensions');
        $edition = self::field($fields, $options, 'edition');
        $description = self::field($fields, $options, 'description');
        $series = self::field($fields, $options, 'series');
        $existing_description = $existing_id ? $product->get_description() : '';
        $artist = self::artist_details_for_record($fields, $options, !$dry_run, self::text($record['id'] ?? ''), $description . "\n\n" . $existing_description);
        $linked_exhibits = self::linked_exhibits_for_record($fields, $options, $exhibit_records);
        $current_exhibits = array_values(array_filter($linked_exhibits, [__CLASS__, 'is_current_exhibit']));
        $product_name = $year ? "{$title}, {$year}" : $title;
        if (!$dry_run && !empty($artist['name'])) {
            $artist_post_id = self::ensure_artist_profile_post($artist, $linked_exhibits);
            if ($artist_post_id) {
                $artist['profile_post_id'] = (string) $artist_post_id;
                $artist['profile_url'] = get_permalink($artist_post_id) ?: '';
            }
        }

        $product->set_name($product_name);
        $product->set_slug(sanitize_title($title . '-' . $inventory_number));
        $product->set_sku($inventory_number);
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        $product->set_regular_price($price);
        $product->set_description(self::build_product_description([
            'title' => $title,
            'year' => $year,
            'medium' => $medium,
            'dimensions' => $dimensions,
            'edition' => $edition,
            'inventory_number' => $inventory_number,
            'description' => $description,
            'artist' => $artist,
            'current_exhibits' => $current_exhibits,
        ]));
        $product->set_short_description(implode('<br>', array_filter([
            $series,
            $medium,
            $dimensions,
            $edition ? 'Edition ' . $edition : '',
            'Inventory ' . $inventory_number,
        ])));
        $product->set_manage_stock(true);
        $product->set_stock_quantity(1);
        $product->set_stock_status('instock');

        if ($dry_run) {
            $attachments = self::field_raw($fields, $options, 'image');
            $attachment = is_array($attachments) ? ($attachments[0] ?? null) : null;
            return [
                'updated' => true,
                'dry_run' => true,
                'sku' => $inventory_number,
                'title' => $product_name,
                'series' => $series,
                'has_airtable_image' => !empty($attachment['url']),
                'artist_name' => self::text($artist['name'] ?? ''),
                'has_artist_bio' => !empty($artist['bio']),
                'has_artist_portrait' => !empty($artist['portrait_url']),
                'would_artist_profile' => !empty($artist['name']),
                'would_update_product_id' => $existing_id ?: 0,
                'would_create_product' => !$existing_id,
                'current_exhibits' => count($current_exhibits),
            ];
        }

        $product->set_category_ids(self::ensure_artwork_categories($series));

        $image_id = self::ensure_airtable_image($fields, $options, $inventory_number, $title, (string) ($record['id'] ?? ''));
        if ($image_id) {
            $product->set_image_id($image_id);
        }

        $product_id = $product->save();
        self::remove_uncategorized_product_category($product_id);
        update_post_meta($product_id, self::META_PREFIX . 'record_id', self::text($record['id'] ?? ''));
        update_post_meta($product_id, self::META_PREFIX . 'inventory_number', $inventory_number);
        update_post_meta($product_id, self::META_PREFIX . 'last_synced_at', gmdate('c'));
        update_post_meta($product_id, 'inventory_number', $inventory_number);
        update_post_meta($product_id, 'year', $year);
        update_post_meta($product_id, 'material', $medium);
        update_post_meta($product_id, 'dimensions', $dimensions);
        update_post_meta($product_id, 'edition', $edition);
        update_post_meta($product_id, 'ma_artwork_series', $series);
        update_post_meta($product_id, 'ma_artist_name', self::text($artist['name'] ?? ''));
        update_post_meta($product_id, 'ma_artist_bio', self::text($artist['bio'] ?? ''));
        update_post_meta($product_id, 'ma_artist_portrait_url', esc_url_raw($artist['portrait_url'] ?? ''));
        update_post_meta($product_id, 'ma_artist_portrait_source', self::text($artist['portrait_source'] ?? ''));
        update_post_meta($product_id, 'ma_artist_profile_post_id', (int) ($artist['profile_post_id'] ?? 0));
        update_post_meta($product_id, 'ma_artist_profile_url', esc_url_raw($artist['profile_url'] ?? ''));
        update_post_meta($product_id, self::META_PREFIX . 'exhibits_json', wp_json_encode(self::compact_exhibit_records($linked_exhibits)));
        self::assign_clean_artist_tag($product_id, self::text($artist['name'] ?? ''));

        if (!empty($options['write_shop_url'])) {
            self::maybe_patch_shop_url($options, self::text($record['id'] ?? ''), get_permalink($product_id));
        }

        return ['updated' => true, 'product_id' => $product_id, 'sku' => $inventory_number, 'image_id' => $image_id];
    }

    private static function list_airtable_records(array $options, string $modified_since = '', string $inventory_number = '', int $max_records = 0): array {
        $query = ['pageSize' => (string) $options['batch_size']];
        $formula_parts = [self::available_formula($options), '{' . self::airtable_field_name($options, 'inventory_number') . "}!=''"];
        if ($inventory_number !== '') {
            $formula_parts[] = '{' . self::airtable_field_name($options, 'inventory_number') . "}='" . str_replace("'", "\\'", $inventory_number) . "'";
        }
        if ($modified_since && self::airtable_field_exists($options, 'last_modified')) {
            $formula_parts[] = "IS_AFTER({" . self::airtable_field_name($options, 'last_modified') . "}, '{$modified_since}')";
        }
        $query['filterByFormula'] = 'AND(' . implode(',', array_filter($formula_parts)) . ')';
        if (!$inventory_number && !$modified_since && !empty($options['view'])) {
            $query['view'] = $options['view'];
        }

        $records = [];
        $offset = '';
        do {
            $page_query = $query;
            if ($offset) {
                $page_query['offset'] = $offset;
            }
            $url = sprintf(
                'https://api.airtable.com/v0/%s/%s?%s',
                rawurlencode($options['base_id']),
                rawurlencode($options['table_id']),
                http_build_query($page_query)
            );
            $response = wp_remote_get($url, [
                'timeout' => 45,
                'headers' => ['Authorization' => 'Bearer ' . $options['airtable_token']],
            ]);
            if (is_wp_error($response)) {
                throw new RuntimeException($response->get_error_message());
            }
            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            if ($code >= 300) {
                throw new RuntimeException("Airtable list failed ({$code}): {$body}");
            }
            $json = json_decode($body, true);
            $records = array_merge($records, $json['records'] ?? []);
            if ($max_records > 0 && count($records) >= $max_records) {
                return array_slice($records, 0, $max_records);
            }
            $offset = $json['offset'] ?? '';
        } while ($offset);

        return $records;
    }

    private static function available_formula(array $options): string {
        $field = self::airtable_field_name($options, 'availability');
        $field_ref = '{' . $field . '}';
        return "OR(FIND('Available', ARRAYJOIN({$field_ref})), {$field_ref}=TRUE(), LOWER({$field_ref})='available', LOWER({$field_ref})='yes')";
    }

    private static function discover_airtable_fields(array $options, bool $force = false): array {
        if (!$force) {
            $cached = get_option(self::FIELD_CACHE_KEY, []);
            if (is_array($cached) && $cached) {
                return $cached;
            }
        }
        if (empty($options['airtable_token']) || empty($options['base_id']) || empty($options['table_id'])) {
            throw new RuntimeException('Save Airtable token, base ID, and Artwork Inventory table first.');
        }

        $schema_fields = self::discover_airtable_fields_from_schema($options);
        if ($schema_fields) {
            update_option(self::FIELD_CACHE_KEY, $schema_fields, false);
            return $schema_fields;
        }

        $url = sprintf(
            'https://api.airtable.com/v0/%s/%s?%s',
            rawurlencode($options['base_id']),
            rawurlencode($options['table_id']),
            http_build_query(['pageSize' => 1])
        );
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $options['airtable_token']],
        ]);
        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code >= 300) {
            throw new RuntimeException("Airtable field check failed ({$code}): {$body}");
        }
        $json = json_decode($body, true);
        $fields = [];
        foreach (($json['records'] ?? []) as $record) {
            $fields = array_values(array_unique(array_merge($fields, array_keys($record['fields'] ?? []))));
        }
        sort($fields, SORT_NATURAL | SORT_FLAG_CASE);
        update_option(self::FIELD_CACHE_KEY, $fields, false);
        return $fields;
    }

    private static function discover_airtable_fields_from_schema(array $options): array {
        $url = 'https://api.airtable.com/v0/meta/bases/' . rawurlencode($options['base_id']) . '/tables';
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $options['airtable_token']],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 300) {
            return [];
        }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        $target = self::text($options['table_id']);
        foreach (($json['tables'] ?? []) as $table) {
            if (($table['id'] ?? '') !== $target && ($table['name'] ?? '') !== $target) {
                continue;
            }
            $fields = array_values(array_filter(array_map(static function ($field) {
                return is_array($field) ? (string) ($field['name'] ?? '') : '';
            }, $table['fields'] ?? [])));
            sort($fields, SORT_NATURAL | SORT_FLAG_CASE);
            return $fields;
        }
        return [];
    }

    private static function ensure_artist_portrait_airtable_fields(array $options): void {
        if (empty($options['artist_table_id']) || empty($options['airtable_token']) || empty($options['base_id'])) {
            return;
        }

        $url = 'https://api.airtable.com/v0/meta/bases/' . rawurlencode($options['base_id']) . '/tables';
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $options['airtable_token']],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 300) {
            return;
        }

        $json = json_decode(wp_remote_retrieve_body($response), true);
        $table_id = '';
        $target = self::text($options['artist_table_id']);
        $field_names = [];
        foreach (($json['tables'] ?? []) as $table) {
            if (($table['id'] ?? '') !== $target && ($table['name'] ?? '') !== $target) {
                continue;
            }
            $table_id = self::text($table['id'] ?? '');
            foreach (($table['fields'] ?? []) as $field) {
                $field_names[] = self::text($field['name'] ?? '');
            }
            break;
        }
        if (!$table_id) {
            return;
        }

        $wanted = [
            'Portrait Jpeg' => 'multipleAttachments',
            'Artist Portrait Public URL' => 'url',
        ];
        foreach ($wanted as $name => $type) {
            if (in_array($name, $field_names, true)) {
                continue;
            }
            self::create_airtable_field($options, $table_id, $name, $type);
        }
    }

    private static function create_airtable_field(array $options, string $table_id, string $name, string $type): void {
        $endpoint = sprintf(
            'https://api.airtable.com/v0/meta/bases/%s/tables/%s/fields',
            rawurlencode($options['base_id']),
            rawurlencode($table_id)
        );
        wp_remote_post($endpoint, [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $options['airtable_token'], 'Content-Type' => 'application/json'],
            'body' => wp_json_encode(['name' => $name, 'type' => $type]),
        ]);
    }

    private static function airtable_field_exists(array $options, string $key): bool {
        $name = self::airtable_field_name($options, $key);
        if ($name === '') {
            return false;
        }
        $cached = get_option(self::FIELD_CACHE_KEY, []);
        if (!is_array($cached) || !$cached) {
            return false;
        }
        return in_array($name, $cached, true);
    }

    private static function airtable_field_name(array $options, string $key): string {
        return self::text($options['field_map'][$key] ?? self::default_field_map()[$key] ?? '');
    }

    private static function field(array $fields, array $options, string $key): string {
        return self::text(self::field_raw($fields, $options, $key));
    }

    private static function field_raw(array $fields, array $options, string $key) {
        $names = array_values(array_unique(array_filter(array_merge(
            [self::airtable_field_name($options, $key)],
            self::field_aliases($key)
        ))));
        foreach ($names as $name) {
            if (array_key_exists($name, $fields)) {
                return $fields[$name];
            }
        }
        return '';
    }

    private static function linked_exhibits_for_record(array $fields, array $options, array $exhibit_records): array {
        $linked = self::field_raw($fields, $options, 'exhibit_records');
        if (is_array($linked)) {
            return array_values(array_filter(array_map(static function ($id) use ($exhibit_records) {
                return $exhibit_records[(string) $id] ?? null;
            }, $linked)));
        }
        return self::inline_exhibit_from_fields($fields, $options);
    }

    private static function list_linked_exhibit_records(array $artwork_records, array $options): array {
        if (empty($options['exhibit_table_id'])) {
            return [];
        }
        $ids = [];
        foreach ($artwork_records as $record) {
            $linked = self::field_raw($record['fields'] ?? [], $options, 'exhibit_records');
            if (!is_array($linked)) {
                continue;
            }
            foreach ($linked as $id) {
                $ids[(string) $id] = true;
            }
        }
        if (!$ids) {
            return [];
        }
        $records = [];
        foreach (array_chunk(array_keys($ids), 25) as $chunk) {
            $formula = 'OR(' . implode(',', array_map(static function ($id) {
                return "RECORD_ID()='" . str_replace("'", "\\'", $id) . "'";
            }, $chunk)) . ')';
            foreach (self::list_airtable_table_records($options, $options['exhibit_table_id'], $formula) as $record) {
                if (!empty($record['id'])) {
                    $records[(string) $record['id']] = $record;
                }
            }
        }
        return $records;
    }

    private static function list_airtable_table_records(array $options, string $table_id, string $formula): array {
        $url = sprintf(
            'https://api.airtable.com/v0/%s/%s?%s',
            rawurlencode($options['base_id']),
            rawurlencode($table_id),
            http_build_query(['pageSize' => '100', 'filterByFormula' => $formula])
        );
        $response = wp_remote_get($url, [
            'timeout' => 45,
            'headers' => ['Authorization' => 'Bearer ' . $options['airtable_token']],
        ]);
        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code >= 300) {
            throw new RuntimeException("Airtable exhibit list failed ({$code}): {$body}");
        }
        $json = json_decode($body, true);
        return $json['records'] ?? [];
    }

    private static function get_airtable_record(array $options, string $table_id, string $record_id): array {
        if (!$table_id || !$record_id) {
            return [];
        }
        $url = sprintf(
            'https://api.airtable.com/v0/%s/%s/%s',
            rawurlencode($options['base_id']),
            rawurlencode($table_id),
            rawurlencode($record_id)
        );
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $options['airtable_token']],
        ]);
        if (is_wp_error($response)) {
            return [];
        }
        if (wp_remote_retrieve_response_code($response) >= 300) {
            return [];
        }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($json) ? $json : [];
    }

    private static function artist_details_for_record(array $fields, array $options, bool $allow_side_effects = false, string $artwork_record_id = '', string $site_bio_source = ''): array {
        $artist = [
            'name' => self::field($fields, $options, 'artist_name'),
            'bio' => self::field($fields, $options, 'artist_bio'),
            'portrait_url' => self::artist_portrait_url_from_fields($fields, $options),
            'portrait_source' => '',
            'record_id' => '',
            'residency_period' => '',
            'website' => '',
            'instagram' => '',
            'profile_url' => '',
            'profile_post_id' => '',
        ];

        $linked = self::field_raw($fields, $options, 'artist_records');
        $artist_id = is_array($linked) ? self::text($linked[0] ?? '') : '';
        if (!$artist['name']) {
            $artist['name'] = self::infer_artist_name_from_text(self::field($fields, $options, 'description') . "\n" . self::field($fields, $options, 'title'));
        }
        if ($artist_id && !empty($options['artist_table_id'])) {
            $artist['record_id'] = $artist_id;
            $artist_record = self::get_airtable_record($options, $options['artist_table_id'], $artist_id);
            $artist_fields = is_array($artist_record['fields'] ?? null) ? $artist_record['fields'] : [];
            if ($artist_fields) {
                if (!$artist['name'] || self::looks_like_airtable_record_id($artist['name'])) {
                    $artist['name'] = self::text(self::first_field_value($artist_fields, self::artist_field_aliases('name')));
                }
                $artist['bio'] = $artist['bio'] ?: self::text(self::first_field_value($artist_fields, self::artist_field_aliases('bio')));
                if (!$artist['bio']) {
                    $artist['bio'] = self::site_bio_for_artist($artist['name'], $site_bio_source);
                }
                if ($allow_side_effects && $artist['bio']) {
                    self::maybe_patch_artist_bio($options, $artist_id, $artist['bio']);
                }
                $synced_portrait_url = $allow_side_effects ? self::ensure_artist_portrait_public_url($artist_fields, $options, $artist_id, $artist['name']) : '';
                $public_portrait_url = self::text(self::first_field_value($artist_fields, ['Artist Portrait Public URL']));
                if ($synced_portrait_url) {
                    $artist['portrait_url'] = $synced_portrait_url;
                    $artist['portrait_source'] = 'Synced Airtable portrait attachment';
                } elseif ($public_portrait_url) {
                    $artist['portrait_url'] = self::public_image_url($public_portrait_url);
                    $artist['portrait_source'] = 'Artists table public URL';
                } elseif ($allow_side_effects) {
                    if (!$synced_portrait_url && $artist['portrait_url'] && strpos($artist['portrait_url'], 'airtableusercontent.com') !== false) {
                        $media_id = self::find_artist_portrait_media($artist_id, '');
                        if (!$media_id) {
                            $media_id = self::sideload_artist_portrait($artist['portrait_url'], $artist['name'], $artist_id, '', '');
                        }
                        $synced_portrait_url = $media_id ? (wp_get_attachment_url($media_id) ?: '') : '';
                        if ($synced_portrait_url) {
                            self::patch_artist_portrait_url($options, $artist_id, $synced_portrait_url);
                        }
                    }
                    if ($synced_portrait_url) {
                        $artist['portrait_url'] = $synced_portrait_url;
                        $artist['portrait_source'] = 'Synced public portrait URL';
                    }
                } elseif (!$artist['portrait_url']) {
                        $artist_portrait_url = self::text(self::first_field_value($artist_fields, self::artist_field_aliases('portrait_url')));
                        $artist['portrait_url'] = $artist_portrait_url ? self::public_image_url($artist_portrait_url) : self::artist_portrait_url_from_artist_fields($artist_fields);
                }
                if ($artist['portrait_url'] && !$artist['portrait_source']) {
                    $artist['portrait_source'] = 'Artists table';
                }
                $artist['residency_period'] = self::text(self::first_field_value($artist_fields, self::artist_field_aliases('residency_period')));
                $artist['website'] = self::text(self::first_field_value($artist_fields, self::artist_field_aliases('website')));
                $artist['instagram'] = self::text(self::first_field_value($artist_fields, self::artist_field_aliases('instagram')));
            }
        } elseif ($allow_side_effects && $artist['name'] && !empty($options['artist_table_id'])) {
            $artist['bio'] = $artist['bio'] ?: self::site_bio_for_artist($artist['name'], $site_bio_source);
            $artist_id = self::find_or_create_artist_record($options, $artist['name'], $artist['bio']);
            if ($artist_id) {
                $artist['record_id'] = $artist_id;
                self::maybe_link_artwork_to_artist($options, $artwork_record_id, $artist_id);
            }
        }

        if ($artist['portrait_url'] && empty($artist['portrait_source'])) {
            $artist['portrait_source'] = 'Artwork Inventory';
        }
        if (self::looks_like_airtable_record_id($artist['name'])) {
            $artist['name'] = '';
        }
        if (!$artist['bio']) {
            $artist['bio'] = self::site_bio_for_artist($artist['name'], $site_bio_source);
        }
        if ($allow_side_effects && !empty($artist['record_id']) && !empty($artist['bio'])) {
            self::maybe_patch_artist_bio($options, $artist['record_id'], $artist['bio']);
        }
        return array_map([__CLASS__, 'text'], $artist);
    }

    private static function infer_artist_name_from_text(string $text): string {
        $text = trim(wp_strip_all_tags($text));
        if (!$text) {
            return '';
        }
        if (preg_match('/\bby\s+([A-Z][A-Za-z.\' -]{2,80})(?:\s*[,(\n]|$)/', $text, $match)) {
            return trim($match[1]);
        }
        if (preg_match('/^([A-Z][A-Za-z.\'-]+(?:\s+[A-Z][A-Za-z.\'-]+){0,4}),\s+(?:an?|the)\s+/m', $text, $match)) {
            return trim($match[1]);
        }
        return '';
    }

    private static function site_bio_for_artist(string $artist_name, string $source_text = ''): string {
        $artist_name = trim($artist_name);
        if (!$artist_name) {
            return '';
        }

        $existing = self::bio_from_existing_artist_post($artist_name);
        if ($existing) {
            return $existing;
        }

        $from_source = self::bio_from_text_for_artist($artist_name, $source_text);
        if ($from_source) {
            return $from_source;
        }

        $from_site = self::bio_from_site_search($artist_name);
        if ($from_site) {
            return $from_site;
        }

        return self::artist_bio_fallback($artist_name);
    }

    private static function bio_from_existing_artist_post(string $artist_name): string {
        $post_id = self::find_artist_profile_post(['name' => $artist_name]);
        if (!$post_id) {
            return '';
        }
        $content = (string) get_post_field('post_content', $post_id);
        if (!$content || strpos($content, 'ma-artist-page__bio') === false) {
            return '';
        }
        if (preg_match('/<section class="ma-artist-page__bio">(.*?)<\/section>/is', $content, $match)) {
            return self::clean_bio_text($match[1]);
        }
        return '';
    }

    private static function bio_from_site_search(string $artist_name): string {
        $posts = get_posts([
            'post_type' => ['post', 'page', 'product'],
            'post_status' => 'publish',
            'posts_per_page' => 12,
            's' => $artist_name,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        foreach ($posts as $post) {
            if ((int) $post->ID === self::find_artist_profile_post(['name' => $artist_name])) {
                continue;
            }
            $bio = self::bio_from_text_for_artist($artist_name, (string) $post->post_content);
            if ($bio) {
                return $bio;
            }
        }
        return '';
    }

    private static function bio_from_text_for_artist(string $artist_name, string $text): string {
        $plain = self::clean_bio_text($text);
        if (!$plain || stripos($plain, $artist_name) === false) {
            return '';
        }
        $sentences = preg_split('/(?<=[.!?])\s+/', $plain) ?: [];
        $collected = [];
        $started = false;
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (!$sentence) {
                continue;
            }
            if (!$started && stripos($sentence, $artist_name) !== false && preg_match('/\b(artist|photographer|painter|maker|designer|creative|practice|work)\b/i', $sentence)) {
                $started = true;
            }
            if ($started) {
                $collected[] = $sentence;
                if (count($collected) >= 4) {
                    break;
                }
            }
        }
        return trim(implode(' ', $collected));
    }

    private static function clean_bio_text(string $text): string {
        $text = preg_replace('/<!--.*?-->/s', ' ', $text);
        $text = wp_strip_all_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim((string) $text);
    }

    private static function artist_bio_fallback(string $artist_name): string {
        $fallbacks = [
            'kerry sharkey-miller' => 'Kerry Sharkey-Miller is a Sag Harbor, New York based multidisciplinary artist with a background in fine art, photography, media production, digital printing, and alternative processes. Her work often reflects close attention to the beauty and fragility of the natural environment.',
        ];
        $key = strtolower(trim($artist_name));
        return $fallbacks[$key] ?? '';
    }

    private static function artist_portrait_url_from_fields(array $fields, array $options): string {
        $url = self::field($fields, $options, 'artist_portrait_url');
        if ($url) {
            return self::public_image_url($url);
        }
        $attachment = self::field_raw($fields, $options, 'artist_portrait');
        return self::attachment_or_url_to_public_image($attachment);
    }

    private static function artist_portrait_url_from_artist_fields(array $fields): string {
        $url = self::text(self::first_field_value($fields, self::artist_field_aliases('portrait_url')));
        if ($url) {
            return self::public_image_url($url);
        }
        return self::attachment_or_url_to_public_image(self::first_field_value($fields, self::artist_field_aliases('portrait')));
    }

    private static function ensure_artist_portrait_public_url(array $artist_fields, array $options, string $artist_record_id, string $artist_name): string {
        $attachment = self::first_field_value($artist_fields, self::artist_field_aliases('portrait'));
        $first = is_array($attachment) ? ($attachment[0] ?? null) : null;
        $source_url = is_array($first) ? self::text($first['url'] ?? '') : '';
        $attachment_id = is_array($first) ? self::text($first['id'] ?? '') : '';
        $filename = is_array($first) ? self::text($first['filename'] ?? '') : '';
        if (!$source_url) {
            $source_url = self::text(self::first_field_value($artist_fields, ['portrait url', 'Portrait URL', 'Artist Portrait URL']));
            $filename = $source_url ? basename((string) parse_url($source_url, PHP_URL_PATH)) : '';
        }
        if (!$source_url) {
            return '';
        }

        $media_id = $attachment_id ? self::find_artist_portrait_media('', $attachment_id) : 0;
        if (!$media_id) {
            $media_id = self::find_artist_portrait_media($artist_record_id, '');
            if ($media_id && $attachment_id) {
                $stored_attachment_id = self::text(get_post_meta($media_id, self::META_PREFIX . 'artist_portrait_attachment_id', true));
                if ($stored_attachment_id && $stored_attachment_id !== $attachment_id) {
                    $media_id = 0;
                }
            }
        }
        if ($media_id && $filename) {
            $stored_filename = self::text(get_post_meta($media_id, self::META_PREFIX . 'artist_portrait_source_filename', true));
            if (!$stored_filename || $stored_filename !== $filename) {
                $media_id = 0;
            }
        }
        if (!$media_id) {
            $media_id = self::sideload_artist_portrait($source_url, $artist_name, $artist_record_id, $attachment_id, $filename);
        }
        if (!$media_id) {
            return '';
        }

        $url = wp_get_attachment_url($media_id);
        if (!$url) {
            return '';
        }
        self::patch_artist_portrait_url($options, $artist_record_id, $url);
        return esc_url_raw($url);
    }

    private static function find_artist_portrait_media(string $artist_record_id, string $attachment_id): int {
        $queries = [
            [self::META_PREFIX . 'artist_record_id', $artist_record_id],
            [self::META_PREFIX . 'artist_portrait_attachment_id', $attachment_id],
        ];
        foreach ($queries as [$key, $value]) {
            if (!$value) {
                continue;
            }
            $ids = get_posts([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_key' => $key,
                'meta_value' => $value,
            ]);
            if ($ids) {
                return (int) $ids[0];
            }
        }
        return 0;
    }

    private static function sideload_artist_portrait(string $url, string $artist_name, string $artist_record_id, string $attachment_id, string $filename): int {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($url, 60);
        if (is_wp_error($tmp)) {
            return 0;
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: 'jpg';
        $name = sanitize_file_name(trim($artist_name . '-artist-portrait-' . $artist_record_id, '-') . '.' . $extension);
        $new_id = media_handle_sideload([
            'name' => $name,
            'tmp_name' => $tmp,
        ], 0, $artist_name ? $artist_name . ' artist portrait' : 'Artist portrait');
        if (is_wp_error($new_id)) {
            @unlink($tmp);
            return 0;
        }
        update_post_meta((int) $new_id, self::META_PREFIX . 'artist_record_id', $artist_record_id);
        update_post_meta((int) $new_id, self::META_PREFIX . 'artist_portrait_attachment_id', $attachment_id);
        update_post_meta((int) $new_id, self::META_PREFIX . 'artist_portrait_source_filename', $filename);
        return (int) $new_id;
    }

    private static function patch_artist_portrait_url(array $options, string $artist_record_id, string $url): void {
        if (!$artist_record_id || !$url || empty($options['artist_table_id'])) {
            return;
        }
        $endpoint = sprintf(
            'https://api.airtable.com/v0/%s/%s/%s',
            rawurlencode($options['base_id']),
            rawurlencode($options['artist_table_id']),
            rawurlencode($artist_record_id)
        );
        wp_remote_request($endpoint, [
            'method' => 'PATCH',
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $options['airtable_token'], 'Content-Type' => 'application/json'],
            'body' => wp_json_encode(['fields' => ['Artist Portrait Public URL' => $url]]),
        ]);
    }

    private static function find_or_create_artist_record(array $options, string $artist_name, string $bio = ''): string {
        $artist_name = trim($artist_name);
        if (!$artist_name || empty($options['artist_table_id'])) {
            return '';
        }

        $formula = 'LOWER({' . self::airtable_formula_field('Name') . '})=' . self::airtable_formula_string(strtolower($artist_name));
        $endpoint = sprintf(
            'https://api.airtable.com/v0/%s/%s?%s',
            rawurlencode($options['base_id']),
            rawurlencode($options['artist_table_id']),
            http_build_query(['pageSize' => 1, 'filterByFormula' => $formula])
        );
        $response = wp_remote_get($endpoint, [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $options['airtable_token']],
        ]);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) < 300) {
            $json = json_decode(wp_remote_retrieve_body($response), true);
            $record_id = self::text($json['records'][0]['id'] ?? '');
            if ($record_id) {
                if ($bio) {
                    self::maybe_patch_artist_bio($options, $record_id, $bio);
                }
                return $record_id;
            }
        }

        $fields = ['Name' => $artist_name];
        if ($bio) {
            $fields['Bio'] = $bio;
        }
        $create = wp_remote_post(sprintf(
            'https://api.airtable.com/v0/%s/%s',
            rawurlencode($options['base_id']),
            rawurlencode($options['artist_table_id'])
        ), [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $options['airtable_token'], 'Content-Type' => 'application/json'],
            'body' => wp_json_encode(['fields' => $fields]),
        ]);
        if (is_wp_error($create) || wp_remote_retrieve_response_code($create) >= 300) {
            return '';
        }
        $json = json_decode(wp_remote_retrieve_body($create), true);
        return self::text($json['id'] ?? '');
    }

    private static function maybe_patch_artist_bio(array $options, string $artist_record_id, string $bio): void {
        $bio = trim($bio);
        if (!$artist_record_id || !$bio || empty($options['artist_table_id'])) {
            return;
        }
        $endpoint = sprintf(
            'https://api.airtable.com/v0/%s/%s/%s',
            rawurlencode($options['base_id']),
            rawurlencode($options['artist_table_id']),
            rawurlencode($artist_record_id)
        );
        wp_remote_request($endpoint, [
            'method' => 'PATCH',
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $options['airtable_token'], 'Content-Type' => 'application/json'],
            'body' => wp_json_encode(['fields' => ['Bio' => $bio]]),
        ]);
    }

    private static function maybe_link_artwork_to_artist(array $options, string $artwork_record_id, string $artist_record_id): void {
        if (!$artwork_record_id || !$artist_record_id) {
            return;
        }
        $artist_field = self::airtable_field_name($options, 'artist_records') ?: 'Artist Name';
        $endpoint = sprintf(
            'https://api.airtable.com/v0/%s/%s/%s',
            rawurlencode($options['base_id']),
            rawurlencode($options['table_id']),
            rawurlencode($artwork_record_id)
        );
        wp_remote_request($endpoint, [
            'method' => 'PATCH',
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $options['airtable_token'], 'Content-Type' => 'application/json'],
            'body' => wp_json_encode(['fields' => [$artist_field => [$artist_record_id]]]),
        ]);
    }

    private static function airtable_formula_field(string $field): string {
        return str_replace('}', '\\}', $field);
    }

    private static function airtable_formula_string(string $value): string {
        return "'" . str_replace("'", "\\'", $value) . "'";
    }

    private static function first_field_value(array $fields, array $names) {
        foreach ($names as $name) {
            if (array_key_exists($name, $fields)) {
                return $fields[$name];
            }
        }
        return '';
    }

    private static function artist_field_aliases(string $key): array {
        $aliases = [
            'name' => ['Name', 'Artist Name', 'Artist', 'Full Name'],
            'bio' => ['Artist Bio', 'Bio', 'Biography', 'Artist Biography', 'Bio Statement', 'Artist Bio from Submission Form'],
            'portrait' => ['Portrait jpg', 'Portrait JPG', 'Portrait jpeg', 'Portrait JPEG', 'Artist Portrait', 'Portrait', 'Headshot', 'Artist Headshot', 'Artist Image', 'Artist Photo', 'Portrait Jpeg', 'Jpeg Image', 'JPEG Image', 'Image', 'Photo'],
            'portrait_url' => ['Artist Portrait Public URL', 'Artist Portrait URL', 'Google Drive Portrait URL', 'Public Portrait URL', 'Portrait URL', 'portrait url', 'Headshot URL', 'Artist Image URL', 'Artist Photo URL', 'Google Drive URL', 'LookupArtistPortraitUrl'],
            'residency_period' => ['Residency Period', 'Resident Artist Period', 'Ma\'s House Residency', 'Residency'],
            'website' => ['Artist Website', 'Website', 'URL'],
            'instagram' => ['Instagram Username (with @)', 'Instagram', 'Instagram Username'],
        ];
        return $aliases[$key] ?? [];
    }

    private static function attachment_or_url_to_public_image($value): string {
        if (is_array($value)) {
            $first = $value[0] ?? null;
            if (is_array($first)) {
                return self::public_image_url(self::text($first['url'] ?? ''));
            }
        }
        return self::public_image_url(self::text($value));
    }

    private static function public_image_url(string $url): string {
        $url = trim($url);
        if (!$url) {
            return '';
        }
        if (preg_match('~drive\.google\.com/file/d/([^/]+)~', $url, $match) || preg_match('~[?&]id=([^&]+)~', $url, $match)) {
            return 'https://drive.google.com/uc?export=view&id=' . rawurlencode($match[1]);
        }
        return esc_url_raw($url);
    }

    private static function looks_like_airtable_record_id(string $value): bool {
        return (bool) preg_match('/^rec[A-Za-z0-9]{10,}$/', trim($value));
    }

    private static function ensure_artist_profile_post(array $artist, array $linked_exhibits): int {
        $name = self::text($artist['name'] ?? '');
        if (!$name) {
            return 0;
        }

        $post_id = self::find_artist_profile_post($artist);
        $category_id = self::ensure_post_category('Artists');
        $content = self::build_artist_profile_content($artist, $linked_exhibits);
        $post_data = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => $name,
            'post_name' => sanitize_title($name),
            'post_content' => $content,
            'post_excerpt' => wp_trim_words(self::text($artist['bio'] ?? ''), 32),
        ];
        if ($post_id) {
            $post_data['ID'] = $post_id;
            $updated = wp_update_post($post_data, true);
            if (is_wp_error($updated)) {
                return 0;
            }
        } else {
            $post_id = wp_insert_post($post_data, true);
            if (is_wp_error($post_id)) {
                return 0;
            }
        }

        if ($category_id) {
            wp_set_post_terms((int) $post_id, [(int) $category_id], 'category', false);
        }
        update_post_meta((int) $post_id, 'ma_artist_name', $name);
        update_post_meta((int) $post_id, 'ma_artist_portrait_url', esc_url_raw($artist['portrait_url'] ?? ''));
        update_post_meta((int) $post_id, 'ma_artist_airtable_record_id', self::text($artist['record_id'] ?? ''));
        update_post_meta((int) $post_id, 'ma_artist_profile_synced_at', gmdate('c'));
        return (int) $post_id;
    }

    private static function find_artist_profile_post(array $artist): int {
        $record_id = self::text($artist['record_id'] ?? '');
        if ($record_id) {
            $ids = get_posts([
                'post_type' => 'post',
                'post_status' => ['publish', 'draft', 'pending', 'private'],
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_key' => 'ma_artist_airtable_record_id',
                'meta_value' => $record_id,
            ]);
            if ($ids) {
                return (int) $ids[0];
            }
        }

        $name = self::text($artist['name'] ?? '');
        if (!$name) {
            return 0;
        }
        $existing = get_page_by_path(sanitize_title($name), OBJECT, 'post');
        return $existing ? (int) $existing->ID : 0;
    }

    private static function ensure_post_category(string $name): int {
        $existing = term_exists($name, 'category');
        if (!$existing) {
            $existing = wp_insert_term($name, 'category');
        }
        if (is_wp_error($existing)) {
            return 0;
        }
        return (int) (is_array($existing) ? $existing['term_id'] : $existing);
    }

    private static function build_artist_profile_content(array $artist, array $linked_exhibits): string {
        $name = self::text($artist['name'] ?? '');
        $bio = self::text($artist['bio'] ?? '');
        $portrait_url = self::public_image_url(self::text($artist['portrait_url'] ?? ''));
        $residency_period = self::text($artist['residency_period'] ?? '');
        $website = self::text($artist['website'] ?? '');
        $instagram = self::text($artist['instagram'] ?? '');
        $exhibits = self::artist_exhibit_labels($linked_exhibits);
        $parts = ['<div class="ma-artist-page">'];

        if ($portrait_url) {
            $parts[] = '<figure class="ma-artist-page__portrait"><img src="' . esc_url($portrait_url) . '" alt="' . esc_attr($name . ' portrait') . '" loading="lazy"></figure>';
        }
        if ($bio) {
            $parts[] = '<section class="ma-artist-page__bio">' . wpautop(esc_html($bio)) . '</section>';
        }

        $facts = '';
        if ($residency_period) {
            $facts .= '<li><strong>Ma\'s House residency:</strong> ' . esc_html($residency_period) . '</li>';
        }
        if ($exhibits) {
            $facts .= '<li><strong>Exhibitions:</strong> ' . esc_html(implode('; ', $exhibits)) . '</li>';
        }
        if ($website) {
            $facts .= '<li><strong>Website:</strong> <a href="' . esc_url($website) . '">' . esc_html(preg_replace('~^https?://~', '', $website)) . '</a></li>';
        }
        if ($instagram) {
            $handle = ltrim($instagram, '@');
            $facts .= '<li><strong>Instagram:</strong> <a href="' . esc_url('https://www.instagram.com/' . $handle) . '">@' . esc_html($handle) . '</a></li>';
        }
        if ($facts) {
            $parts[] = '<section class="ma-artist-page__facts"><ul>' . $facts . '</ul></section>';
        }

        $parts[] = '<section class="ma-artist-page__artworks"><h2>Artworks</h2>' . "\n" . '<!-- wp:shortcode -->[ma_artist_artworks artist="' . esc_attr($name) . '"]<!-- /wp:shortcode -->' . "\n" . '</section>';
        $parts[] = '</div>';
        return implode("\n", $parts);
    }

    private static function artist_exhibit_labels(array $linked_exhibits): array {
        $labels = [];
        foreach ($linked_exhibits as $record) {
            $compact = self::compact_exhibit_record($record);
            $title = self::text($compact['title'] ?? '');
            if ($title) {
                $labels[$title] = $title;
            }
        }
        return array_values($labels);
    }

    private static function inline_exhibit_from_fields(array $fields, array $options): array {
        $title = self::field($fields, $options, 'exhibit_title');
        $start = self::date_ymd(self::field($fields, $options, 'exhibit_start'));
        $end = self::date_ymd(self::field($fields, $options, 'exhibit_end'));
        if (!$title && !$start && !$end) {
            return [];
        }
        return [[
            'fields' => [
                'Exhibit Title' => $title,
                'Venue Name' => self::field($fields, $options, 'exhibit_venue'),
                'Location' => self::field($fields, $options, 'exhibit_location'),
                'Start Date' => $start,
                'End Date' => $end ?: $start,
            ],
        ]];
    }

    private static function ensure_airtable_image(array $fields, array $options, string $inventory_number, string $title, string $record_id): int {
        $attachments = self::field_raw($fields, $options, 'image');
        $attachment = is_array($attachments) ? ($attachments[0] ?? null) : null;
        if (!$attachment || empty($attachment['url'])) {
            return 0;
        }
        $airtable_attachment_id = self::text($attachment['id'] ?? '');
        $filename = self::text($attachment['filename'] ?? '');
        $existing = self::find_exact_media($inventory_number, $record_id, $airtable_attachment_id, $filename);
        if ($existing) {
            return $existing;
        }
        return self::sideload_airtable_image((string) $attachment['url'], $title, $inventory_number, $record_id, $airtable_attachment_id, $filename);
    }

    private static function find_exact_media(string $inventory_number, string $record_id, string $attachment_id, string $filename): int {
        $meta_queries = [
            [self::META_PREFIX . 'inventory_number', $inventory_number],
            [self::META_PREFIX . 'record_id', $record_id],
            [self::META_PREFIX . 'attachment_id', $attachment_id],
        ];
        foreach ($meta_queries as [$key, $value]) {
            if (!$value) {
                continue;
            }
            $ids = get_posts([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_key' => $key,
                'meta_value' => $value,
            ]);
            if ($ids) {
                return (int) $ids[0];
            }
        }

        if ($filename) {
            $attachments = get_posts([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'post_mime_type' => 'image',
                's' => $filename,
                'posts_per_page' => 10,
                'fields' => 'ids',
            ]);
            foreach ($attachments as $id) {
                if ((string) get_post_meta($id, self::META_PREFIX . 'inventory_number', true) === $inventory_number) {
                    return (int) $id;
                }
            }
        }
        return 0;
    }

    private static function sideload_airtable_image(string $url, string $title, string $inventory_number, string $record_id, string $attachment_id, string $filename): int {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($url, 60);
        if (is_wp_error($tmp)) {
            throw new RuntimeException('Could not download Airtable image: ' . $tmp->get_error_message());
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: 'jpg';
        $file_array = [
            'name' => sanitize_file_name($title . '-' . $inventory_number . '.' . $extension),
            'tmp_name' => $tmp,
        ];
        $new_id = media_handle_sideload($file_array, 0, $title);
        if (is_wp_error($new_id)) {
            @unlink($tmp);
            throw new RuntimeException('Could not create WordPress media item: ' . $new_id->get_error_message());
        }
        update_post_meta($new_id, self::META_PREFIX . 'inventory_number', $inventory_number);
        update_post_meta($new_id, self::META_PREFIX . 'record_id', $record_id);
        update_post_meta($new_id, self::META_PREFIX . 'attachment_id', $attachment_id);
        return (int) $new_id;
    }

    private static function ensure_artwork_categories(string $series): array {
        $artwork_id = self::ensure_product_category('Artwork', 0);
        $ids = $artwork_id ? [$artwork_id] : [];
        if ($series && $artwork_id) {
            $series_id = self::ensure_product_category($series, $artwork_id);
            if ($series_id) {
                $ids[] = $series_id;
            }
        }
        return $ids;
    }

    private static function ensure_product_category(string $name, int $parent): int {
        $existing = term_exists($name, 'product_cat', $parent);
        if (!$existing) {
            $existing = wp_insert_term($name, 'product_cat', ['parent' => $parent]);
        }
        if (is_wp_error($existing)) {
            return 0;
        }
        return (int) (is_array($existing) ? $existing['term_id'] : $existing);
    }

    private static function remove_uncategorized_product_category(int $product_id): void {
        $uncategorized = get_term_by('slug', 'uncategorized', 'product_cat');
        if (!$uncategorized || is_wp_error($uncategorized)) {
            return;
        }
        $terms = wp_get_object_terms($product_id, 'product_cat', ['fields' => 'ids']);
        if (is_wp_error($terms) || count(array_diff(array_map('intval', $terms), [(int) $uncategorized->term_id])) === 0) {
            return;
        }
        wp_remove_object_terms($product_id, [(int) $uncategorized->term_id], 'product_cat');
    }

    private static function assign_clean_artist_tag(int $product_id, string $artist_name): void {
        $artist_name = trim($artist_name);
        if (!$product_id || !$artist_name) {
            return;
        }
        $legacy_name = str_replace(' ', '_', $artist_name);
        $legacy = get_term_by('name', $legacy_name, 'product_tag');
        if ($legacy && !is_wp_error($legacy)) {
            wp_update_term((int) $legacy->term_id, 'product_tag', ['name' => $artist_name]);
            wp_set_object_terms($product_id, [(int) $legacy->term_id], 'product_tag', true);
            return;
        }
        $term = term_exists($artist_name, 'product_tag');
        if (!$term) {
            $term = wp_insert_term($artist_name, 'product_tag', ['slug' => sanitize_title($artist_name)]);
        }
        if (is_wp_error($term)) {
            return;
        }
        $term_id = (int) (is_array($term) ? $term['term_id'] : $term);
        if ($term_id) {
            wp_set_object_terms($product_id, [$term_id], 'product_tag', true);
        }
    }

    private static function build_product_description(array $data): string {
        $title = self::text($data['title'] ?? '');
        $year = self::text($data['year'] ?? '');
        $medium = self::text($data['medium'] ?? '');
        $dimensions = self::text($data['dimensions'] ?? '');
        $edition = self::text($data['edition'] ?? '');
        $inventory_number = self::text($data['inventory_number'] ?? '');
        $description = self::text($data['description'] ?? '');
        $artist = is_array($data['artist'] ?? null) ? $data['artist'] : [];
        $current_exhibits = is_array($data['current_exhibits'] ?? null) ? $data['current_exhibits'] : [];
        $parts = [];

        if ($current_exhibits) {
            $parts[] = '<h3>Currently on view</h3><p>' . esc_html(self::current_exhibit_label(self::compact_exhibit_record($current_exhibits[0]))) . '</p>';
        }
        if ($description) {
            $parts[] = wpautop(esc_html($description));
        }

        $rows = '';
        foreach (array_filter([
            'Title' => $title,
            'Year' => $year,
            'Medium/Material' => $medium,
            'Size' => $dimensions,
            'Edition' => $edition,
            'Inventory Number' => $inventory_number,
        ]) as $label => $value) {
            $rows .= '<div><strong>' . esc_html($label) . ':</strong> ' . esc_html($value) . '</div>';
        }
        if ($rows) {
            $parts[] = '<h3>Artwork Details</h3>' . $rows;
        }
        $artist_section = self::artist_product_section($artist);
        if ($artist_section) {
            $parts[] = $artist_section;
        }
        return implode("\n", $parts);
    }

    private static function artist_product_section(array $artist): string {
        $name = self::text($artist['name'] ?? '');
        $bio = self::text($artist['bio'] ?? '');
        $portrait_url = self::public_image_url(self::text($artist['portrait_url'] ?? ''));
        $profile_url = esc_url(self::text($artist['profile_url'] ?? ''));
        if (!$name && !$bio && !$portrait_url) {
            return '';
        }

        $html = '<section class="ma-artist-profile" aria-label="Artist information">';
        if ($portrait_url) {
            $portrait = '<img src="' . esc_url($portrait_url) . '" alt="' . esc_attr($name ? $name . ' portrait' : 'Artist portrait') . '" loading="lazy">';
            $html .= '<figure class="ma-artist-profile__portrait">' . ($profile_url ? '<a href="' . $profile_url . '">' . $portrait . '</a>' : $portrait) . '</figure>';
        } else {
            $html .= '<div class="ma-artist-profile__portrait" aria-hidden="true"></div>';
        }
        $heading = esc_html($name ? 'About ' . $name : 'About the Artist');
        $html .= '<div class="ma-artist-profile__body"><h3>' . ($profile_url ? '<a href="' . $profile_url . '">' . $heading . '</a>' : $heading) . '</h3>';
        if ($bio) {
            $html .= wpautop(esc_html($bio));
        }
        $html .= '</div></section>';
        return $html;
    }

    public static function render_single_product_artwork_panel(): void {
        global $product;
        if (!$product instanceof WC_Product) {
            return;
        }
        if (isset(self::$rendered_single_product_panels[$product->get_id()])) {
            return;
        }
        self::$rendered_single_product_panels[$product->get_id()] = true;
        echo self::single_product_artwork_panel_html($product);
    }

    public static function render_single_product_artwork_panel_in_meta(): void {
        if (!is_product() || !class_exists('WooCommerce')) {
            return;
        }
        $product = wc_get_product((int) get_queried_object_id());
        if (!$product instanceof WC_Product) {
            return;
        }
        if (isset(self::$rendered_single_product_panels[$product->get_id()])) {
            return;
        }
        $html = self::single_product_artwork_panel_html($product);
        if (!$html) {
            return;
        }
        self::$rendered_single_product_panels[$product->get_id()] = true;
        echo $html;
    }

    public static function render_purchase_support_note(): void {
        if (!is_product() || !class_exists('WooCommerce')) {
            return;
        }
        $product = wc_get_product((int) get_queried_object_id());
        if (!$product instanceof WC_Product) {
            return;
        }
        $price = (float) $product->get_price();
        if ($price <= 0 || !$product->is_purchasable()) {
            return;
        }
        echo '<p class="ma-product-purchase-note">By purchasing artwork, you are supporting a reciprocal fundraising model: artists are paid for their work, and Ma&rsquo;s House receives support for the programs, residencies, exhibitions, and community gatherings that keep our space active and accessible.</p>';
    }

    private static function single_product_artwork_panel_html(WC_Product $product): string {
        $rows = self::product_detail_rows($product);
        $profile_url = esc_url(self::text(get_post_meta($product->get_id(), 'ma_artist_profile_url', true)));
        if (!$rows) {
            return '';
        }

        ob_start();
        echo '<section class="ma-product-artwork-panel" aria-label="Artwork details">';
        echo '<div class="ma-product-artwork-panel__details">';
        foreach ($rows as $row) {
            $label = self::text($row['label'] ?? '');
            $value = self::text($row['value'] ?? '');
            if (!$label || !$value) {
                continue;
            }
            if ($label === 'Artist' && $profile_url) {
                $value_html = '<a href="' . $profile_url . '">' . esc_html($value) . '</a>';
            } else {
                $value_html = esc_html($value);
            }
            echo '<div class="ma-product-artwork-panel__row"><span>' . esc_html($label) . '</span><strong>' . $value_html . '</strong></div>';
        }
        echo '</div>';
        echo '</section>';
        return (string) ob_get_clean();
    }

    public static function render_single_product_artwork_panel_fallback(): void {
        if (!is_product() || !class_exists('WooCommerce')) {
            return;
        }
        $product = wc_get_product((int) get_queried_object_id());
        if (!$product instanceof WC_Product) {
            return;
        }
        $html = self::single_product_artwork_panel_html($product);
        $exhibit_html = self::product_exhibit_body_section_html($product);
        ?>
        <?php if ($html) : ?>
        <template id="ma-product-artwork-panel-template"><?php echo $html; ?></template>
        <?php endif; ?>
        <?php if ($exhibit_html) : ?>
        <template id="ma-product-exhibit-body-template"><?php echo $exhibit_html; ?></template>
        <?php endif; ?>
        <script>
        (function(){
            function summaryColumn(){
                var rightColumn = document.querySelector('.elementor-widget-woocommerce-product-title') &&
                    document.querySelector('.elementor-widget-woocommerce-product-title').closest('.elementor-widget-wrap');
                if (rightColumn) rightColumn.classList.add('ma-product-summary-column');
                return rightColumn;
            }
            function placePanel(){
                var rightColumn = summaryColumn();
                if (document.querySelector('.ma-product-artwork-panel')) return;
                var template = document.getElementById('ma-product-artwork-panel-template');
                if (!template || !template.content) return;
                var panel = template.content.firstElementChild.cloneNode(true);
                var price = rightColumn && rightColumn.querySelector('.elementor-widget-woocommerce-product-price');
                var addToCart = rightColumn && rightColumn.querySelector('.elementor-widget-woocommerce-product-add-to-cart');
                var meta = rightColumn && rightColumn.querySelector('.elementor-widget-woocommerce-product-meta');
                if (addToCart && addToCart.parentNode) {
                    addToCart.parentNode.insertBefore(panel, addToCart);
                } else if (price && price.parentNode) {
                    price.parentNode.insertBefore(panel, price.nextSibling);
                } else if (meta && meta.parentNode) {
                    meta.parentNode.insertBefore(panel, meta);
                }
            }
            function placeExhibit(){
                var section = document.querySelector('.ma-product-exhibit-section');
                if (!section) {
                    var template = document.getElementById('ma-product-exhibit-body-template');
                    if (!template || !template.content) return;
                    section = template.content.firstElementChild.cloneNode(true);
                } else {
                    return;
                }
                var titleWidget = document.querySelector('.elementor-widget-woocommerce-product-title');
                var hero = titleWidget;
                while (hero && hero !== document.body && !hero.querySelector('.woocommerce-product-gallery, .elementor-widget-woocommerce-product-images')) {
                    hero = hero.parentElement;
                }
                if (hero && hero !== document.body) {
                    var heroSection = hero.closest('.elementor-section, .e-con-boxed, .e-con') || hero;
                    if (heroSection && heroSection.parentNode) {
                        heroSection.parentNode.insertBefore(section, heroSection.nextSibling);
                        return;
                    }
                }
                var artistProfile = document.querySelector('.ma-artist-profile');
                if (artistProfile && artistProfile.parentNode) {
                    artistProfile.parentNode.insertBefore(section, artistProfile);
                    return;
                }
                var productContent = document.querySelector('.elementor-widget-woocommerce-product-content, .woocommerce-product-details__short-description, .entry-summary');
                if (productContent && productContent.parentNode) {
                    productContent.parentNode.insertBefore(section, productContent.nextSibling);
                    return;
                }
                var product = document.querySelector('main .product, div.product');
                if (product) product.appendChild(section);
            }
            function boot(){
                summaryColumn();
                placePanel();
                placeExhibit();
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', boot, {once:true});
            } else {
                boot();
            }
        }());
        </script>
        <?php
    }

    public static function append_product_body_sections(string $content): string {
        if (is_admin() || !is_product() || !class_exists('WooCommerce')) {
            return $content;
        }
        if (strpos($content, 'ma-product-exhibit-section') !== false) {
            return $content;
        }
        $product = wc_get_product((int) get_queried_object_id());
        if (!$product instanceof WC_Product) {
            return $content;
        }
        $exhibit_html = self::product_exhibit_body_section_html($product);
        if (!$exhibit_html) {
            return $content;
        }
        $artist_pos = strpos($content, '<section class="ma-artist-profile');
        if ($artist_pos !== false) {
            return substr($content, 0, $artist_pos) . $exhibit_html . substr($content, $artist_pos);
        }
        return $content . $exhibit_html;
    }

    private static function product_exhibits(int $product_id): array {
        $json = (string) get_post_meta($product_id, self::META_PREFIX . 'exhibits_json', true);
        $records = $json ? json_decode($json, true) : [];
        if (!is_array($records)) {
            return [];
        }
        $records = array_values(array_filter(array_map([__CLASS__, 'compact_exhibit_record'], $records)));
        if (!$records) {
            $records = self::fallback_exhibits_from_product_terms($product_id);
        }
        usort($records, static function ($a, $b) {
            return strcmp((string) ($b['start_date'] ?? ''), (string) ($a['start_date'] ?? ''));
        });
        return $records;
    }

    private static function fallback_exhibits_from_product_terms(int $product_id): array {
        $terms = get_the_terms($product_id, 'product_tag');
        if (!$terms || is_wp_error($terms)) {
            return [];
        }
        $artist = strtolower(self::text(get_post_meta($product_id, 'ma_artist_name', true)));
        $records = [];
        foreach ($terms as $term) {
            $name = self::text($term->name ?? '');
            if (!$name || strtolower($name) === $artist) {
                continue;
            }
            $label = trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $name)));
            if (!$label || preg_match('/^(artwork|book|zine)$/i', $label)) {
                continue;
            }
            $post_id = self::find_exhibit_post_id($label);
            if (!$post_id) {
                $post_id = self::find_exhibit_post_id(trim(preg_replace('/\b20\d{2}\b/', '', $label)));
            }
            $post_title = $post_id ? self::text(get_the_title($post_id)) : $label;
            $records[] = [
                'title' => $post_title ?: $label,
                'venue' => $post_id ? self::event_venue_for_post($post_id) : '',
                'location' => '',
                'start_date' => $post_id ? self::date_ymd(get_post_meta($post_id, '_EventStartDate', true) ?: get_post_field('post_date', $post_id)) : '',
                'end_date' => $post_id ? self::date_ymd(get_post_meta($post_id, '_EventEndDate', true) ?: get_post_meta($post_id, '_EventStartDate', true)) : '',
                'url' => $post_id ? (get_permalink($post_id) ?: '') : '',
                'image_url' => $post_id ? (get_the_post_thumbnail_url($post_id, 'large') ?: '') : '',
            ];
        }
        return $records;
    }

    private static function event_venue_for_post(int $post_id): string {
        $venue_id = (int) get_post_meta($post_id, '_EventVenueID', true);
        if ($venue_id) {
            return self::text(get_the_title($venue_id));
        }
        return '';
    }

    private static function product_exhibit_body_section_html(WC_Product $product): string {
        $card = self::product_exhibit_card(self::product_exhibits($product->get_id()), $product);
        if (!$card) {
            return '';
        }
        return '<section class="ma-product-exhibit-section" aria-label="Exhibition information"><h2>Exhibition</h2>' . $card . '</section>';
    }

    private static function product_exhibit_card(array $exhibits, WC_Product $product): string {
        if (!$exhibits) {
            return '';
        }
        $today = current_time('Y-m-d');
        $chosen = $exhibits[0];
        foreach ($exhibits as $exhibit) {
            $start = self::date_ymd($exhibit['start_date'] ?? '');
            $end = self::date_ymd($exhibit['end_date'] ?? ($exhibit['start_date'] ?? ''));
            if ($start && $end && $today >= $start && $today <= $end) {
                $chosen = $exhibit;
                break;
            }
        }

        $title = self::text($chosen['title'] ?? '');
        if (!$title) {
            return '';
        }
        $url = self::exhibit_url($chosen);
        $image = self::exhibit_image_url($chosen, $product);
        $label = self::is_current_exhibit($chosen) ? 'Currently on view' : 'Included in exhibition';
        $date = self::format_date_range($chosen['start_date'] ?? '', $chosen['end_date'] ?? '');
        $place = implode(', ', array_filter([self::text($chosen['venue'] ?? ''), self::text($chosen['location'] ?? '')]));
        $inner = '';
        if ($image) {
            $inner .= '<div class="ma-product-exhibit-card__image"><img src="' . esc_url($image) . '" alt="' . esc_attr($title) . '" loading="lazy"></div>';
        }
        $inner .= '<div class="ma-product-exhibit-card__body"><span>' . esc_html($label) . '</span><h3>' . esc_html($title) . '</h3>';
        if ($place) {
            $inner .= '<p>' . esc_html($place) . '</p>';
        }
        if ($date) {
            $inner .= '<p>' . esc_html($date) . '</p>';
        }
        $inner .= '</div>';
        $tag = $url ? 'a' : 'div';
        $href = $url ? ' href="' . esc_url($url) . '"' : '';
        return '<' . $tag . ' class="ma-product-exhibit-card"' . $href . ' aria-label="' . esc_attr($label . ': ' . $title) . '">' . $inner . '</' . $tag . '>';
    }

    private static function exhibit_url(array $exhibit): string {
        $url = self::public_image_url(self::text($exhibit['url'] ?? ''));
        if ($url && !preg_match('~\.(jpe?g|png|gif|webp)(\?.*)?$~i', $url)) {
            return $url;
        }
        $post_id = self::find_exhibit_post_id(self::text($exhibit['title'] ?? ''));
        return $post_id ? (get_permalink($post_id) ?: '') : '';
    }

    private static function exhibit_image_url(array $exhibit, WC_Product $product): string {
        $image = self::public_image_url(self::text($exhibit['image_url'] ?? ''));
        if ($image) {
            return $image;
        }
        $post_id = self::find_exhibit_post_id(self::text($exhibit['title'] ?? ''));
        if ($post_id) {
            $thumb = get_the_post_thumbnail_url($post_id, 'large');
            if ($thumb) {
                return $thumb;
            }
            $content = (string) get_post_field('post_content', $post_id);
            if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $match)) {
                return esc_url_raw($match[1]);
            }
        }
        $image_id = $product->get_image_id();
        return $image_id ? (wp_get_attachment_image_url($image_id, 'large') ?: '') : '';
    }

    private static function find_exhibit_post_id(string $title): int {
        $title = trim($title);
        if (!$title) {
            return 0;
        }
        $cache_key = 'ma_exhibit_post_' . md5(strtolower($title));
        $cached = wp_cache_get($cache_key, 'ma_artwork_sync');
        if ($cached !== false) {
            return (int) $cached;
        }
        $query = new WP_Query([
            'post_type' => ['post', 'page', 'tribe_events'],
            'post_status' => 'publish',
            'posts_per_page' => 8,
            's' => $title,
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);
        $best = 0;
        foreach ($query->posts as $post_id) {
            $post_title = self::text(get_the_title((int) $post_id));
            if (strcasecmp($post_title, $title) === 0 || stripos($post_title, $title) !== false || stripos($title, $post_title) !== false) {
                $best = (int) $post_id;
                break;
            }
        }
        if (!$best && $query->posts) {
            $best = (int) $query->posts[0];
        }
        wp_reset_postdata();
        wp_cache_set($cache_key, $best, 'ma_artwork_sync', 300);
        return $best;
    }

    public static function render_global_site_polish_css(): void {
        if (is_admin()) {
            return;
        }
        echo '<style id="ma-global-site-polish-css">body:not(.home) .elementor-location-header,body:not(.home) .elementor-location-header>*,body:not(.home) .elementor-location-header .elementor,body:not(.home) .elementor-location-header .elementor-section,body:not(.home) .elementor-location-header .elementor-top-section,body:not(.home) .elementor-location-header .elementor-container,body:not(.home) .elementor-location-header .elementor-column,body:not(.home) .elementor-location-header .elementor-widget-wrap,body:not(.home) .elementor-location-header .e-con,body:not(.home) .elementor-location-header .e-con-inner,body:not(.home) header.site-header,body:not(.home) #masthead,body:not(.home) .site-header,body:not(.home) .main-header-bar,body:not(.home) .ast-primary-header-bar{border:0!important;border-color:transparent!important;box-shadow:none!important;outline:0!important}.elementor-location-header:before,.elementor-location-header:after,header.site-header:before,header.site-header:after,#masthead:before,#masthead:after{display:none!important;box-shadow:none!important}.elementor-location-header .elementor-widget-container{box-shadow:none!important;border:0!important}.elementor-location-header nav,.elementor-location-header .elementor-nav-menu,.elementor-location-header .elementor-menu-toggle,.elementor-location-header .elementor-search-form,.elementor-location-header .elementor-search-form__container{box-shadow:none!important}</style>';
    }

    public static function optimize_frontend_product_assets(): void {
        if (is_admin() || !function_exists('is_product') || !is_product()) {
            return;
        }
        if ((function_exists('is_cart') && is_cart()) || (function_exists('is_checkout') && is_checkout()) || (function_exists('is_account_page') && is_account_page())) {
            return;
        }

        $script_handles = [
            'wp-block-editor',
            'wp-blocks',
            'wp-components',
            'wp-compose',
            'wp-data',
            'wp-date',
            'wp-dom-ready',
            'wp-edit-post',
            'wp-editor',
            'wp-format-library',
            'wp-hooks',
            'wp-keycodes',
            'wp-notices',
            'wp-rich-text',
            'wp-server-side-render',
            'wp-token-list',
            'lodash',
            'moment',
            'wc-stripe-blocks-integration',
            'wc-stripe-express-checkout',
            'wc-stripe-payment-request',
            'wc-stripe-upe-classic',
            'woocommerce_stripe',
            'stripe',
            'ppcp-button',
            'ppcp-button-js',
            'ppcp-button-js-button',
            'ppcp-smart-buttons',
            'paypal-checkout',
        ];
        foreach ($script_handles as $handle) {
            wp_dequeue_script($handle);
            wp_deregister_script($handle);
        }

        $style_handles = [
            'wp-edit-blocks',
            'wp-block-editor',
            'wp-components',
            'wp-format-library',
            'wp-block-library-theme',
        ];
        foreach ($style_handles as $handle) {
            wp_dequeue_style($handle);
            wp_deregister_style($handle);
        }
    }

    public static function render_artist_profile_css(): void {
        $is_artist_post = is_single() && has_category('Artists');
        if (!is_product() && !$is_artist_post) {
            return;
        }
        echo '<style id="ma-artist-profile-css">body.single-product div.product .summary.entry-summary,.ma-product-summary-column{box-sizing:border-box;padding:0 0 0 28px!important;background:transparent;border:0;box-shadow:none}body.single-product div.product .summary.entry-summary .product_title,body.single-product .elementor-widget-woocommerce-product-title .product_title{margin:0 0 14px!important;color:#050505!important;font-size:30px!important;line-height:1.14!important;font-weight:650!important;letter-spacing:0!important;text-align:left!important}body.single-product div.product .summary.entry-summary .price,body.single-product .elementor-widget-woocommerce-product-price .price{margin:0 0 20px!important;color:#111!important;font-family:Georgia,"Times New Roman",serif!important;font-size:21px!important;line-height:1.2!important;font-weight:400!important;text-align:left!important}body.single-product .elementor-widget-woocommerce-product-price .price .amount{font:inherit!important;color:inherit!important}body.single-product div.product .summary.entry-summary .woocommerce-product-details__short-description{margin:0 0 22px!important;color:#222;font-size:15px;line-height:1.55}.ma-product-artwork-panel{display:grid;gap:20px;margin:20px 0!important;padding:18px 0 20px!important;border-top:1px solid rgba(0,0,0,.12);border-bottom:1px solid rgba(0,0,0,.12);font-family:' . esc_html(self::font_stack()) . ';color:#111}.ma-product-artwork-panel__details{display:grid;gap:10px}.ma-product-artwork-panel__row{display:grid;grid-template-columns:92px minmax(0,1fr);gap:14px;align-items:baseline}.ma-product-artwork-panel__row span{color:#686868;font-size:11px;font-weight:750;letter-spacing:.08em;text-transform:uppercase}.ma-product-artwork-panel__row strong{color:#111;font-size:14px;line-height:1.35;font-weight:500}.ma-product-artwork-panel__row a{color:#111;text-decoration:underline;text-underline-offset:3px}.ma-product-exhibit-card{display:grid;grid-template-columns:82px minmax(0,1fr);gap:14px;align-items:center;margin-top:2px;padding:0;color:#111;text-decoration:none;background:transparent;border:0}.ma-product-exhibit-card:hover h3{text-decoration:underline;text-underline-offset:3px}.ma-product-exhibit-card__image{min-height:74px;background:#f4f2ee;overflow:hidden}.ma-product-exhibit-card__image img{display:block;width:100%;height:100%;aspect-ratio:4/3;object-fit:contain}.ma-product-exhibit-card__body{align-self:center}.ma-product-exhibit-card__body span{display:block;margin:0 0 6px;color:#666;font-size:11px;font-weight:750;letter-spacing:.08em;text-transform:uppercase}.ma-product-exhibit-card__body h3{margin:0 0 7px!important;color:#111!important;font-size:16px!important;line-height:1.25!important;font-weight:700!important}.ma-product-exhibit-card__body p{margin:0 0 3px;color:#333;font-size:13px;line-height:1.35}.ma-product-summary-column .elementor-widget-woocommerce-product-add-to-cart{margin-top:8px}.ma-product-summary-column .stock{margin:0 0 12px!important;color:#333!important;font-size:13px!important;text-transform:uppercase;letter-spacing:.06em}.ma-product-summary-column .single_add_to_cart_button{width:100%;border-radius:0!important;background:#111!important;color:#fff!important;font-weight:700!important;letter-spacing:.02em}.ma-product-summary-column .product_meta{display:grid!important;gap:5px;margin-top:18px!important;color:#555!important;font-size:12px!important;line-height:1.45!important}.ma-product-summary-column .product_meta a{color:#111!important;text-decoration:underline;text-underline-offset:2px}.ma-artist-profile{clear:both;display:grid;grid-template-columns:180px minmax(0,1fr);gap:24px;align-items:start;margin:34px 0 0;padding-top:28px;border-top:1px solid #ddd;color:#111}.ma-artist-profile__portrait{width:180px;aspect-ratio:4/5;background:#f2eee8;overflow:hidden}.ma-artist-profile__portrait a,.ma-artist-profile__portrait img{display:block;width:100%;height:100%}.ma-artist-profile__portrait img{object-fit:cover}.ma-artist-profile h3{margin:0 0 10px;font-size:22px;line-height:1.2}.ma-artist-profile h3 a{color:inherit;text-decoration:none}.ma-artist-profile p{margin:0 0 12px;line-height:1.6}.ma-artist-page{max-width:1120px;margin:0 auto}.ma-artist-page__portrait{max-width:420px;margin:0 0 28px}.ma-artist-page__portrait img{display:block;width:100%;height:auto}.ma-artist-page__bio{max-width:780px}.ma-artist-page__facts{margin:26px 0}.ma-artist-page__facts ul{list-style:none;margin:0;padding:0;display:grid;gap:8px}.ma-artist-artworks{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:26px;margin-top:18px}.ma-artist-artwork img{display:block;width:100%;aspect-ratio:4/3;object-fit:cover}.ma-artist-artwork h3{font-size:18px;line-height:1.25;margin:10px 0 4px}.ma-artist-artwork a{color:inherit;text-decoration:none}.ma-artist-artwork__meta,.ma-artist-artwork__price{font-size:14px;margin:0;color:#333}@media(max-width:760px){body.single-product div.product .summary.entry-summary,.ma-product-summary-column{padding:0!important}.ma-product-artwork-panel__row{grid-template-columns:82px minmax(0,1fr)}.ma-product-exhibit-card{grid-template-columns:86px minmax(0,1fr)}.ma-artist-profile{display:block}.ma-artist-profile__portrait{width:150px;margin:0 0 18px}.ma-artist-artworks{grid-template-columns:1fr}}</style>';
        echo '<style id="ma-product-public-parity-css">body.single-product .elementor-widget-woocommerce-product-meta .product_meta{display:grid!important;gap:5px;margin-top:18px!important;color:#555!important;font-size:12px!important;line-height:1.45!important}body.single-product .elementor-widget-woocommerce-product-meta .product_meta .ma-product-artwork-panel{width:100%;margin:0 0 18px!important}body.single-product .elementor-widget-woocommerce-product-meta .product_meta a{color:#111!important;text-decoration:underline;text-underline-offset:2px}body.single-product .elementor-widget-woocommerce-product-add-to-cart{margin-top:8px}body.single-product .elementor-widget-woocommerce-product-add-to-cart .stock{margin:0 0 12px!important;color:#333!important;font-size:13px!important;text-transform:uppercase;letter-spacing:.06em}body.single-product .elementor-widget-woocommerce-product-add-to-cart .single_add_to_cart_button{width:100%;border-radius:0!important;background:#111!important;color:#fff!important;font-weight:700!important;letter-spacing:.02em}</style>';
        echo '<style id="ma-product-compact-hero-css">body.single-product .elementor-element-923ecf0 .elementor-container{align-items:flex-start!important}body.single-product .elementor-element-923ecf0 .elementor-widget-wrap{align-content:flex-start!important;align-items:flex-start!important}body.single-product .elementor-element-923ecf0 .elementor-element-f5de331,body.single-product .elementor-element-923ecf0 .elementor-widget-woocommerce-product-images{margin-top:0!important;padding-top:0!important}body.single-product .elementor-element-923ecf0 .elementor-element-8a50c1b>.elementor-widget-wrap{padding-top:10px!important}body.single-product .elementor-widget-woocommerce-product-title,body.single-product .elementor-widget-woocommerce-product-price,body.single-product .elementor-widget-woocommerce-product-add-to-cart,body.single-product .elementor-widget-woocommerce-product-meta,body.single-product .elementor-widget-shortcode,body.single-product .elementor-element-923ecf0 .elementor-widget-text-editor{margin-bottom:0!important}body.single-product .elementor-element-923ecf0 .elementor-widget-spacer{display:none!important}body.single-product .elementor-element-923ecf0 .elementor-widget-wrap>*+*{margin-top:18px!important}body.single-product .elementor-element-923ecf0 .elementor-widget-woocommerce-product-title{margin-top:0!important}body.single-product .elementor-widget-woocommerce-product-title .product_title{font-family:' . esc_html(self::font_stack()) . ' !important;font-style:normal!important;font-size:27px!important;font-weight:700!important;line-height:1.18!important;margin:0!important}body.single-product .elementor-widget-woocommerce-product-price .price,body.single-product .elementor-widget-woocommerce-product-price .amount,body.single-product .elementor-widget-woocommerce-product-add-to-cart,body.single-product .elementor-widget-woocommerce-product-meta,body.single-product .ma-product-artwork-panel,body.single-product .elementor-element-923ecf0 .elementor-widget-text-editor{font-family:' . esc_html(self::font_stack()) . ' !important;color:#111!important}body.single-product .elementor-widget-woocommerce-product-price .price{font-size:20px!important;font-weight:500!important;margin:0!important}body.single-product .elementor-widget-woocommerce-product-add-to-cart .stock{font-family:' . esc_html(self::font_stack()) . ' !important;margin:0 0 10px!important;color:#111!important;font-size:12px!important;font-weight:650!important;letter-spacing:.08em!important;text-transform:uppercase!important}body.single-product .elementor-widget-woocommerce-product-add-to-cart form.cart{margin:0!important}body.single-product .elementor-widget-woocommerce-product-add-to-cart .single_add_to_cart_button{min-height:48px!important;padding:13px 18px!important;font-family:' . esc_html(self::font_stack()) . ' !important;font-size:15px!important;line-height:1.2!important}body.single-product .elementor-widget-woocommerce-product-meta .product_meta{margin-top:0!important;font-family:' . esc_html(self::font_stack()) . ' !important}body.single-product .ma-product-artwork-panel{gap:0!important;padding:16px 0!important;margin:0!important}body.single-product .ma-product-artwork-panel__details{gap:8px!important}body.single-product .ma-product-artwork-panel__row{grid-template-columns:96px minmax(0,1fr)!important;gap:12px!important}body.single-product .ma-product-artwork-panel__row span{font-family:' . esc_html(self::font_stack()) . ' !important;color:#666!important;font-size:11px!important;letter-spacing:.08em!important}body.single-product .ma-product-artwork-panel__row strong{font-family:' . esc_html(self::font_stack()) . ' !important;font-size:14px!important;font-weight:500!important}body.single-product .product_meta .detail-container{font-family:' . esc_html(self::font_stack()) . ' !important;font-size:12px!important;line-height:1.35!important}body.single-product .elementor-element-923ecf0 .elementor-widget-text-editor{font-size:12px!important;line-height:1.35!important}@media(max-width:760px){body.single-product .elementor-element-923ecf0 .elementor-element-8a50c1b>.elementor-widget-wrap{padding-top:0!important}body.single-product .elementor-element-923ecf0 .elementor-widget-wrap>*+*{margin-top:14px!important}}</style>';
        echo '<style id="ma-product-font-final-css">body.single-product .elementor-element-923ecf0,body.single-product .elementor-element-923ecf0 *:not(.dashicons):not(.eicon):not([class*="icon"]){font-family:' . esc_html(self::font_stack()) . ' !important}body.single-product .elementor-widget-woocommerce-product-price .price,body.single-product .elementor-widget-woocommerce-product-price .price span,body.single-product .elementor-widget-woocommerce-product-price .amount{font-family:' . esc_html(self::font_stack()) . ' !important;font-style:normal!important}body.single-product .elementor-widget-woocommerce-product-title .product_title{font-family:' . esc_html(self::font_stack()) . ' !important;font-style:normal!important}</style>';
        echo '<style id="ma-product-price-font-final-css">body.single-product .elementor-element-923ecf0 .elementor-widget-woocommerce-product-price .price,body.single-product .elementor-element-923ecf0 .elementor-widget-woocommerce-product-price .price *,body.single-product .elementor-element-923ecf0 .elementor-widget-woocommerce-product-price .amount{font-family:' . esc_html(self::font_stack()) . ' !important;font-style:normal!important;font-weight:500!important}</style>';
        echo '<style id="ma-product-system-font-css">body.single-product .elementor-element-923ecf0,body.single-product .elementor-element-923ecf0 *:not(.dashicons):not(.eicon):not([class*="icon"]){font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif!important}body.single-product .elementor-element-923ecf0 .elementor-widget-woocommerce-product-price .price,body.single-product .elementor-element-923ecf0 .elementor-widget-woocommerce-product-price .price *,body.single-product .elementor-element-923ecf0 .elementor-widget-woocommerce-product-price .amount{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif!important;font-style:normal!important;font-weight:500!important}</style>';
        echo '<style id="ma-product-purchase-note-css">body.single-product .ma-product-purchase-note{max-width:520px;margin:0 0 14px!important;color:#444!important;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif!important;font-size:12px!important;line-height:1.45!important;font-weight:400!important}body.single-product .elementor-widget-woocommerce-product-add-to-cart .stock+.ma-product-purchase-note{margin-top:0!important}</style>';
        echo '<style id="ma-product-image-emphasis-css">body.single-product .elementor-element-923ecf0>.elementor-container{display:flex!important;gap:42px!important;justify-content:flex-start!important}body.single-product .elementor-element-923ecf0 .elementor-element-c02330b{width:54%!important;max-width:650px!important;flex:0 1 54%!important}body.single-product .elementor-element-923ecf0 .elementor-element-8a50c1b{width:46%!important;max-width:560px!important;flex:0 1 46%!important}body.single-product .elementor-element-923ecf0 .elementor-widget-woocommerce-product-images,body.single-product .elementor-element-923ecf0 .elementor-element-f5de331{width:100%!important;max-width:640px!important}body.single-product .elementor-element-923ecf0 .woocommerce-product-gallery,body.single-product .elementor-element-923ecf0 .woocommerce-product-gallery__wrapper,body.single-product .elementor-element-923ecf0 .woocommerce-product-gallery__image,body.single-product .elementor-element-923ecf0 .woocommerce-product-gallery img{width:100%!important;max-width:640px!important;height:auto!important}body.single-product .elementor-element-923ecf0 .elementor-element-8a50c1b>.elementor-widget-wrap{padding-left:0!important}@media(max-width:900px){body.single-product .elementor-element-923ecf0>.elementor-container{display:block!important}body.single-product .elementor-element-923ecf0 .elementor-element-c02330b,body.single-product .elementor-element-923ecf0 .elementor-element-8a50c1b{width:100%!important;max-width:none!important}}</style>';
        echo '<style id="ma-product-image-fill-css">body.single-product .elementor-element-923ecf0 .elementor-element-f5de331{padding-right:0!important;margin-right:0!important;width:100%!important;max-width:650px!important}body.single-product .elementor-element-923ecf0 .woocommerce-product-gallery{display:block!important;width:100%!important;max-width:650px!important}body.single-product .elementor-element-923ecf0 .woocommerce-product-gallery__wrapper,body.single-product .elementor-element-923ecf0 .woocommerce-product-gallery__image,body.single-product .elementor-element-923ecf0 .woocommerce-product-gallery__image a,body.single-product .elementor-element-923ecf0 .woocommerce-product-gallery__image img{display:block!important;width:100%!important;max-width:650px!important;height:auto!important}</style>';
        echo '<style id="ma-product-exhibit-body-css">body.single-product .ma-product-summary-column>.ma-product-exhibit-card,body.single-product .ma-product-artwork-panel .ma-product-exhibit-card{display:none!important}.ma-product-exhibit-section{clear:both;width:min(1120px,calc(100% - 48px));max-width:1120px;margin:44px auto 0!important;padding:28px 0;border-top:1px solid rgba(0,0,0,.14);border-bottom:1px solid rgba(0,0,0,.08);font-family:' . esc_html(self::font_stack()) . ';color:#111}.ma-product-exhibit-section h2{margin:0 0 18px!important;color:#111!important;font-size:22px!important;line-height:1.2!important;font-weight:650!important}.ma-product-exhibit-section .ma-product-exhibit-card{display:grid!important;max-width:820px;grid-template-columns:170px minmax(0,1fr)!important;gap:24px!important;align-items:start!important;margin:0!important;color:#111!important;text-decoration:none!important;background:transparent!important;border:0!important}.ma-product-exhibit-section .ma-product-exhibit-card__image{min-height:0!important;background:#f4f2ee;overflow:hidden}.ma-product-exhibit-section .ma-product-exhibit-card__image img{display:block;width:100%;height:auto;aspect-ratio:4/3;object-fit:cover}.ma-product-exhibit-section .ma-product-exhibit-card__body span{display:block;margin:0 0 8px;color:#666;font-size:11px;font-weight:750;letter-spacing:.08em;text-transform:uppercase}.ma-product-exhibit-section .ma-product-exhibit-card__body h3{margin:0 0 8px!important;color:#111!important;font-size:19px!important;line-height:1.25!important;font-weight:700!important}.ma-product-exhibit-section .ma-product-exhibit-card__body p{margin:0 0 5px;color:#333;font-size:14px;line-height:1.45}@media(max-width:760px){.ma-product-exhibit-section{width:calc(100% - 32px);margin-top:28px!important;padding:24px 0}.ma-product-exhibit-section .ma-product-exhibit-card{grid-template-columns:1fr!important;gap:14px!important}.ma-product-exhibit-section .ma-product-exhibit-card__image{max-width:260px}}</style>';
    }

    public static function artist_artworks_shortcode(array $atts): string {
        $atts = shortcode_atts(['artist' => ''], $atts, 'ma_artist_artworks');
        $artist = self::text($atts['artist'] ?? '');
        if (!$artist || !class_exists('WooCommerce')) {
            return '';
        }
        $products = get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'meta_key' => 'ma_artist_name',
            'meta_value' => $artist,
        ]);
        if (!$products) {
            return '<p>No artworks are currently listed for this artist.</p>';
        }
        $html = '<div class="ma-artist-artworks">';
        foreach ($products as $post) {
            $product = wc_get_product($post->ID);
            if (!$product instanceof WC_Product) {
                continue;
            }
            $image = $product->get_image('woocommerce_thumbnail');
            $meta = implode(' · ', array_filter([
                self::text(get_post_meta($product->get_id(), 'material', true)),
                self::text(get_post_meta($product->get_id(), 'dimensions', true)),
            ]));
            $html .= '<article class="ma-artist-artwork"><a href="' . esc_url(get_permalink($product->get_id())) . '">' . $image . '<h3>' . esc_html($product->get_name()) . '</h3></a>';
            if ($meta) {
                $html .= '<p class="ma-artist-artwork__meta">' . esc_html($meta) . '</p>';
            }
            $html .= '<p class="ma-artist-artwork__price">' . wp_kses_post($product->get_price_html()) . '</p></article>';
        }
        $html .= '</div>';
        return $html;
    }

    public static function add_scale_product_tab(array $tabs): array {
        global $product;
        if (!$product instanceof WC_Product) {
            return $tabs;
        }
        $dimensions = self::text(get_post_meta($product->get_id(), 'dimensions', true));
        if (!self::parse_artwork_dimensions($dimensions)) {
            return $tabs;
        }
        $tabs['ma_scale_view'] = [
            'title' => 'See this in scale',
            'priority' => 25,
            'callback' => [__CLASS__, 'render_scale_product_tab'],
        ];
        return $tabs;
    }

    public static function render_scale_product_tab(): void {
        global $product;
        if (!$product instanceof WC_Product) {
            return;
        }
        $dimensions = self::text(get_post_meta($product->get_id(), 'dimensions', true));
        $parsed = self::parse_artwork_dimensions($dimensions);
        if (!$parsed) {
            echo '<p>Scale view is not available for this artwork.</p>';
            return;
        }
        [$art_width, $art_height] = $parsed;
        $image_id = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'large') : '';
        $room_width = 120;
        $visual_width = 680;
        $art_visual_width = max(36, min(430, $visual_width * ($art_width / $room_width)));
        $art_visual_height = max(28, min(360, $visual_width * ($art_height / $room_width)));
        ?>
        <style>
            .ma-scale-room{max-width:760px;margin:0 auto 1.5rem;color:#222}
            .ma-scale-scene{position:relative;width:100%;aspect-ratio:16/10;overflow:hidden;background:linear-gradient(#f8f5ee 0 68%,#d9c8ac 68% 100%);border:1px solid #ddd5c8}
            .ma-scale-baseboard{position:absolute;left:0;right:0;top:67%;height:8px;background:#eee7db;border-top:1px solid #d7cfc3;border-bottom:1px solid #c7b99f}
            .ma-scale-artwork{position:absolute;left:50%;top:13%;transform:translateX(-50%);width:var(--art-width);height:var(--art-height);background:#fff center/cover no-repeat;border:8px solid #fafafa;box-shadow:0 8px 20px rgba(0,0,0,.18)}
            .ma-scale-couch{position:absolute;left:50%;bottom:9%;transform:translateX(-50%);width:408px;height:193px}
            .ma-scale-couch div{position:absolute;background:#596b66;box-shadow:inset 0 -10px 18px rgba(0,0,0,.14)}
            .ma-scale-back{left:8%;right:8%;top:8%;height:48%;border-radius:6px 6px 2px 2px}
            .ma-scale-seat{left:6%;right:6%;bottom:16%;height:34%;border-radius:5px}
            .ma-scale-arm{top:28%;bottom:12%;width:11%;border-radius:5px}.ma-scale-arm.left{left:0}.ma-scale-arm.right{right:0}
            .ma-scale-notes{display:grid;gap:.4rem;margin-top:.85rem;font-size:.95rem}
        </style>
        <div class="ma-scale-room">
            <div class="ma-scale-scene">
                <div class="ma-scale-baseboard"></div>
                <div class="ma-scale-artwork" style="--art-width:<?php echo esc_attr((string) round($art_visual_width, 2)); ?>px;--art-height:<?php echo esc_attr((string) round($art_visual_height, 2)); ?>px;<?php echo $image_url ? 'background-image:url(' . esc_url($image_url) . ');' : ''; ?>"></div>
                <div class="ma-scale-couch"><div class="ma-scale-back"></div><div class="ma-scale-seat"></div><div class="ma-scale-arm left"></div><div class="ma-scale-arm right"></div></div>
            </div>
            <div class="ma-scale-notes">
                <div><strong>Artwork:</strong> <?php echo esc_html(self::format_inches($art_width) . ' x ' . self::format_inches($art_height) . ' in'); ?></div>
                <div><strong>Reference:</strong> couch shown at about 72 inches wide.</div>
            </div>
        </div>
        <?php
    }

    public static function render_loop_on_view_label(): void {
        global $product;
        if (!$product instanceof WC_Product) {
            return;
        }
        $exhibit = self::current_product_exhibit($product->get_id());
        if ($exhibit) {
            echo '<p class="ma-on-view-line">Currently on view: ' . esc_html(self::current_exhibit_label($exhibit)) . '</p>';
        }
    }

    public static function on_view_shortcode(): string {
        ob_start();
        self::render_on_view_section(true);
        return (string) ob_get_clean();
    }

    public static function render_shop_on_view_section(): void {
        if (!self::is_artwork_catalog_request()) {
            return;
        }
        self::render_on_view_section(false);
    }

    private static function render_on_view_section(bool $allow_repeat): void {
        if (!$allow_repeat && self::$shop_on_view_rendered) {
            return;
        }
        $items = self::current_on_view_products();
        if (!$items) {
            return;
        }
        self::$shop_on_view_rendered = true;
        self::print_catalog_css();
        echo '<section class="ma-shop-on-view" aria-label="On View Now">';
        echo '<div class="ma-shop-section-header"><h2>On View Now</h2><p>Available works currently included in exhibitions.</p></div>';
        echo '<div class="ma-shop-on-view__grid">';
        foreach ($items as $item) {
            $product = $item['product'];
            $image_url = $product->get_image_id() ? wp_get_attachment_image_url($product->get_image_id(), 'large') : wc_placeholder_img_src('woocommerce_thumbnail');
            echo '<a class="ma-art-card" href="' . esc_url(get_permalink($product->get_id())) . '">';
            echo '<img src="' . esc_url($image_url) . '" alt="' . esc_attr($product->get_name()) . '" loading="lazy">';
            echo '<h3>' . esc_html($product->get_name()) . '</h3>';
            echo '<p class="ma-art-card__exhibit">' . esc_html(self::current_exhibit_label($item['exhibit'])) . '</p>';
            echo '<p class="ma-art-card__meta">' . implode('<br>', array_map('esc_html', self::product_detail_lines($product))) . '</p>';
            echo '<p class="ma-art-card__price">' . wp_kses_post($product->get_price_html()) . '</p>';
            echo '</a>';
        }
        echo '</div></section>';
    }

    public static function render_all_art_heading(): void {
        if (!self::is_artwork_catalog_request()) {
            return;
        }
        self::print_catalog_css();
        echo '<div class="ma-shop-section-header ma-all-art-heading"><h2>All Art</h2><p>Available artworks from the Ma\'s House collection.</p></div>';
    }

    public static function render_catalog_head_guard(): void {
        if (!self::is_artwork_catalog_request()) {
            return;
        }
        self::print_catalog_css();
        ?>
        <style id="ma-store-first-paint-guard">
            body .elementor-widget-woocommerce-products,
            body .elementor-widget-eael-woo-product-carousel,
            body .elementor-widget-wc-categories,
            body .elementor-widget-woocommerce-product-categories,
            body .elementor-widget-wp-widget-woocommerce_product_categories,
            body .woocommerce ul.products,
            body ul.products,
            body li.product-category{transition:none!important}
            body .elementor-widget-woocommerce-products,
            body .elementor-widget-eael-woo-product-carousel,
            body .elementor-widget-wc-categories,
            body .elementor-widget-woocommerce-product-categories,
            body .elementor-widget-wp-widget-woocommerce_product_categories,
            body .woocommerce ul.products,
            body ul.products,
            body li.product-category{display:none!important}
            body .ma-store-grid-placeholder{display:block!important;min-height:820px;visibility:visible!important}
            body .ma-custom-store-grid,
            body .ma-custom-store-grid *{display:revert;visibility:visible!important;opacity:1!important}
        </style>
        <script id="ma-store-first-paint-guard-js">
        (function(){
            function ensurePlaceholder(){
                if (document.querySelector('.ma-custom-store-grid') || document.querySelector('.ma-store-grid-placeholder')) return;
                var anchor = document.querySelector('.elementor-widget-woocommerce-products') ||
                    document.querySelector('.elementor-widget-eael-woo-product-carousel') ||
                    document.querySelector('ul.products');
                if (!anchor || !anchor.parentNode) return;
                var placeholder = document.createElement('div');
                placeholder.className = 'ma-store-grid-placeholder';
                placeholder.setAttribute('aria-hidden', 'true');
                anchor.parentNode.insertBefore(placeholder, anchor);
            }
            function hideOldStorePieces(){
                ensurePlaceholder();
                var selectors = [
                    '.elementor-widget-woocommerce-products',
                    '.elementor-widget-eael-woo-product-carousel',
                    '.elementor-widget-wc-categories',
                    '.elementor-widget-woocommerce-product-categories',
                    '.elementor-widget-wp-widget-woocommerce_product_categories',
                    '.woocommerce ul.products',
                    'ul.products'
                ];
                selectors.forEach(function(selector){
                    document.querySelectorAll(selector).forEach(function(el){
                        if (el.closest('.ma-custom-store-grid')) return;
                        el.classList.add('ma-hide-elementor-products');
                        el.setAttribute('aria-hidden', 'true');
                    });
                });
                document.querySelectorAll('.elementor-heading-title').forEach(function(heading){
                    if ((heading.textContent || '').trim().toLowerCase() !== 'featured') return;
                    var widget = heading.closest('.elementor-widget-heading,.elementor-element');
                    if (widget) widget.classList.add('ma-hide-featured-store-section');
                });
            }
            hideOldStorePieces();
            var observer = new MutationObserver(hideOldStorePieces);
            observer.observe(document.documentElement, {childList:true, subtree:true});
            window.addEventListener('load', function(){
                hideOldStorePieces();
                window.setTimeout(function(){ observer.disconnect(); hideOldStorePieces(); }, 7000);
            });
        }());
        </script>
        <?php
    }

    public static function render_catalog_footer_assets(): void {
        if (!self::is_artwork_catalog_request()) {
            return;
        }
        self::print_catalog_css();
        $meta = self::shop_catalog_product_meta();
        $catalog = self::shop_catalog_products_for_custom_grid();
        $catalog_endpoint = rest_url('ma-artwork-sync/v1/store-catalog');
        ?>
        <script>
        (function(){
            var meta = <?php echo wp_json_encode($meta); ?> || {};
            var customProducts = <?php echo wp_json_encode($catalog); ?> || [];
            var catalogEndpoint = <?php echo wp_json_encode($catalog_endpoint); ?>;
            var filterRequestId = 0;
            function esc(s){return String(s || '').replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
            function slugify(s){return String(s || '').toLowerCase().replace(/&amp;/g,'and').replace(/&/g,'and').replace(/[^a-z0-9]+/g,' ').trim().replace(/\s+/g,'-');}
            function productCard(item){
                var rows = (item.rows || []).map(function(row){
                    return '<div><span>' + esc(row.label) + '</span>' + esc(row.value) + '</div>';
                }).join('');
                var image = item.image ? '<img src="' + esc(item.image) + '" alt="' + esc(item.title) + '" loading="lazy">' : '';
                return '<article class="ma-custom-store-card" data-product-id="' + esc(item.id) + '" data-artist="' + esc(item.artist || '') + '" data-artist-slug="' + esc(slugify(item.artist || '')) + '" data-medium="' + esc(item.medium || '') + '" data-medium-slug="' + esc(slugify(item.medium || '')) + '">' +
                    '<a class="ma-custom-store-card__image" href="' + esc(item.url) + '">' + image + '</a>' +
                    '<h3><a href="' + esc(item.url) + '">' + esc(item.title) + '</a></h3>' +
                    (rows ? '<div class="ma-custom-store-card__meta">' + rows + '</div>' : '') +
                    (item.price_html ? '<div class="ma-custom-store-card__price">' + item.price_html + '</div>' : '') +
                '</article>';
            }
            function renderCards(items){
                customProducts = items || [];
                var grid = document.querySelector('.ma-custom-store-grid');
                if (!grid) return;
                var itemsWrap = grid.querySelector('.ma-custom-store-grid__items');
                if (itemsWrap) itemsWrap.innerHTML = customProducts.map(productCard).join('');
            }
            function buildCustomGrid(){
                if (!customProducts.length || document.querySelector('.ma-custom-store-grid')) return;
                var anchor = document.querySelector('.elementor-widget-woocommerce-products') ||
                    document.querySelector('.elementor-widget-eael-woo-product-carousel') ||
                    document.querySelector('ul.products') ||
                    document.querySelector('.elementor-location-archive,.woocommerce');
                if (!anchor || !anchor.parentNode) return;
                var section = document.createElement('section');
                section.className = 'ma-custom-store-grid';
                section.setAttribute('aria-label','Store catalog');
                section.innerHTML = '<div class="ma-custom-store-grid__header"><h2>Store Catalog</h2><p><span class="ma-custom-store-count">' + esc(customProducts.length) + '</span> available items</p></div><div class="ma-custom-store-active-filters" hidden></div><div class="ma-custom-store-grid__items">' + customProducts.map(productCard).join('') + '</div><p class="ma-custom-store-empty" hidden>No items match those filters.</p>';
                var placeholder = document.querySelector('.ma-store-grid-placeholder');
                if (placeholder && placeholder.parentNode) {
                    placeholder.parentNode.replaceChild(section, placeholder);
                } else {
                    anchor.parentNode.insertBefore(section, anchor);
                }
                document.querySelectorAll('.elementor-widget-woocommerce-products,.elementor-widget-eael-woo-product-carousel,.elementor-widget-wc-categories,ul.products').forEach(function(el){
                    if (!section.contains(el)) el.classList.add('ma-hide-elementor-products');
                });
            }
            function hideFeaturedSection(){
                document.querySelectorAll('.ma-hide-featured-store-section').forEach(function(el){
                    el.classList.remove('ma-hide-featured-store-section');
                });
                document.querySelectorAll('.elementor-heading-title').forEach(function(heading){
                    if ((heading.textContent || '').trim().toLowerCase() !== 'featured') return;
                    var widget = heading.closest('.elementor-widget-heading,.elementor-element');
                    if (widget) widget.classList.add('ma-hide-featured-store-section');
                });
            }
            function decorate(){
                hideFeaturedSection();
                buildCustomGrid();
                applyCustomFilters();
                document.querySelectorAll('ul.products').forEach(function(list){ list.classList.add('ma-shop-catalog-grid'); });
                document.querySelectorAll('li.product, div.product').forEach(function(card){
                    var idClass = Array.prototype.find.call(card.classList, function(c){return /^post-\d+$/.test(c);});
                    if (!idClass) return;
                    var id = idClass.replace('post-','');
                    if (!meta[id] || card.querySelector('.ma-shop-catalog-meta')) return;
                    var title = card.querySelector('.woocommerce-loop-product__title, .eael-product-title');
                    if (!title) return;
                    card.classList.add('ma-shop-catalog-card');
                    var block = document.createElement('div');
                    block.className = 'ma-shop-catalog-meta';
                    block.innerHTML = (meta[id].rows || []).map(function(row){
                        return '<div><span>' + esc(row.label) + '</span>' + esc(row.value) + '</div>';
                    }).join('');
                    title.insertAdjacentElement('afterend', block);
                });
            }
            function cleanArtistName(name){
                return String(name || '').replace(/\(\d+\)\s*$/,'').replace(/_/g,' ').replace(/\s+/g,' ').trim();
            }
            function selectedFilterValues(kind){
                var selectors = kind === 'artist'
                    ? ['.wpfFilterWrapper[data-taxonomy="pa_artist"] li', '.wpfFilterWrapper[data-get-attribute="wpf_filter_artist"] li']
                    : ['.wpfFilterWrapper[data-taxonomy="pa_medium"] li', '.wpfFilterWrapper[data-get-attribute="wpf_filter_medium"] li'];
                var values = [];
                selectors.forEach(function(selector){
                    document.querySelectorAll(selector).forEach(function(li){
                        var input = li.querySelector('input[type="checkbox"],input[type="radio"]');
                        if (!input || !input.checked) return;
                        var raw = li.getAttribute('data-term-slug') || li.textContent || '';
                        values.push({
                            label: cleanArtistName(li.textContent || raw),
                            slug: slugify(raw.replace(/_/g,' ')),
                            rawSlug: raw
                        });
                    });
                });
                return values.filter(function(item, index, list){
                    return item.slug && list.findIndex(function(other){ return other.slug === item.slug; }) === index;
                });
            }
            function applyCustomFilters(){
                var grid = document.querySelector('.ma-custom-store-grid');
                if (!grid) return;
                var artistFilters = selectedFilterValues('artist');
                var mediumFilters = selectedFilterValues('medium');
                var visible = 0;
                grid.querySelectorAll('.ma-custom-store-card').forEach(function(card){
                    var artistOk = !artistFilters.length || artistFilters.some(function(filter){
                        return card.getAttribute('data-artist-slug') === filter.slug;
                    });
                    var mediumOk = !mediumFilters.length || mediumFilters.some(function(filter){
                        return card.getAttribute('data-medium-slug') === filter.slug;
                    });
                    var show = artistOk && mediumOk;
                    card.hidden = !show;
                    card.style.display = show ? '' : 'none';
                    if (show) visible++;
                });
                var count = grid.querySelector('.ma-custom-store-count');
                if (count) count.textContent = String(visible);
                var active = grid.querySelector('.ma-custom-store-active-filters');
                var labels = artistFilters.concat(mediumFilters).map(function(filter){ return filter.label; });
                if (active) {
                    active.hidden = !labels.length;
                    active.textContent = labels.length ? 'Filtered by: ' + labels.join(', ') : '';
                }
                var empty = grid.querySelector('.ma-custom-store-empty');
                if (empty) empty.hidden = visible !== 0;
            }
            function currentFilterParams(){
                var artistFilters = selectedFilterValues('artist');
                var mediumFilters = selectedFilterValues('medium');
                return {
                    artistFilters: artistFilters,
                    mediumFilters: mediumFilters,
                    artist: artistFilters.map(function(item){ return item.rawSlug || item.slug; }).join(','),
                    medium: mediumFilters.map(function(item){ return item.rawSlug || item.slug; }).join(',')
                };
            }
            function refreshFilteredCatalog(){
                var params = currentFilterParams();
                if (!params.artist && !params.medium) {
                    applyCustomFilters();
                    return;
                }
                var requestId = ++filterRequestId;
                var grid = document.querySelector('.ma-custom-store-grid');
                if (grid) grid.classList.add('ma-custom-store-grid--loading');
                var url = new URL(catalogEndpoint, window.location.origin);
                if (params.artist) url.searchParams.set('artist', params.artist);
                if (params.medium) url.searchParams.set('medium', params.medium);
                fetch(url.toString(), {credentials:'same-origin'})
                    .then(function(response){ return response.ok ? response.json() : Promise.reject(response); })
                    .then(function(data){
                        if (requestId !== filterRequestId) return;
                        renderCards(data.items || []);
                        applyCustomFilters();
                    })
                    .catch(function(){ applyCustomFilters(); })
                    .finally(function(){
                        if (requestId === filterRequestId && grid) grid.classList.remove('ma-custom-store-grid--loading');
                    });
            }
            function cleanFilters(){
                document.querySelectorAll('.wpfFilterWrapper').forEach(function(block){
                    var heading = block.querySelector('.wpfFilterTitle,.wpfFilterTaxNameWrapper,.wpfFilterContent');
                    if (!/Artist/i.test(block.textContent || '')) return;
                    block.classList.add('ma-clean-artist-filter');
                    block.querySelectorAll('.wpfLiLabel').forEach(function(label){
                        var count = label.querySelector('.wpfCount');
                        if (count && /\(0\)/.test(count.textContent || '')) {
                            var row = label.closest('li') || label;
                            if (row.style.display !== 'none') row.style.display = 'none';
                            return;
                        }
                        label.querySelectorAll('.wpfValue,.wpfFilterTaxNameWrapper').forEach(function(name){
                            var clean = cleanArtistName(name.textContent);
                            if (name.textContent !== clean) name.textContent = clean;
                        });
                        var display = label.querySelector('.wpfDisplay');
                        if (display) {
                            display.childNodes.forEach(function(node){
                                if (node.nodeType === Node.TEXT_NODE) {
                                    var clean = cleanArtistName(node.nodeValue);
                                    if (node.nodeValue !== clean) node.nodeValue = clean;
                                }
                            });
                        }
                    });
                });
            }
            var scheduled = false;
            function schedule(){
                if (scheduled) return;
                scheduled = true;
                window.setTimeout(function(){
                    scheduled = false;
                    hideFeaturedSection();
                    decorate();
                    cleanFilters();
                }, 120);
            }
            decorate();
            hideFeaturedSection();
            cleanFilters();
            document.addEventListener('change', function(event){
                if (event.target && event.target.closest && event.target.closest('.wpfFilterWrapper')) {
                    window.setTimeout(function(){ cleanFilters(); refreshFilteredCatalog(); }, 50);
                    window.setTimeout(refreshFilteredCatalog, 350);
                }
            }, true);
            document.addEventListener('click', function(event){
                if (event.target && event.target.closest && event.target.closest('.wpfFilterWrapper')) {
                    window.setTimeout(function(){ cleanFilters(); refreshFilteredCatalog(); }, 120);
                    window.setTimeout(refreshFilteredCatalog, 500);
                }
            }, true);
            new MutationObserver(schedule).observe(document.body,{childList:true,subtree:true});
        }());
        </script>
        <?php
    }

    private static function font_stack(): string {
        return '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif';
    }

    private static function print_catalog_css(): void {
        if (self::$catalog_css_printed) {
            return;
        }
        self::$catalog_css_printed = true;
        $font_stack = self::font_stack();
        echo '<style>
            .ma-shop-on-view{clear:both;width:100%;margin:0 0 42px;padding:0 0 32px;border-bottom:1px solid rgba(0,0,0,.14);background:#fff}
            .ma-shop-section-header{clear:both;display:flex;align-items:baseline;justify-content:space-between;gap:18px;margin:0 0 18px}
            .ma-shop-section-header h2{margin:0;font-size:26px;line-height:1.15;font-weight:700;letter-spacing:0;color:#000}
            .ma-shop-section-header p{margin:0;color:#555;font-size:15px;line-height:1.35;text-align:right}
            .ma-shop-on-view__grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:32px;align-items:start}
            .ma-art-card{display:block;min-width:0;color:inherit;text-decoration:none;background:#fff}
            .ma-art-card img,.woocommerce ul.products.ma-shop-catalog-grid img{display:block;width:100%;height:100%;aspect-ratio:4/3;object-fit:cover;background:#f2f2f2;border-radius:0!important;box-shadow:none!important}
            .ma-shop-catalog-card img{display:block;width:100%;aspect-ratio:4/3;object-fit:cover;background:#f2f2f2;border-radius:0!important;box-shadow:none!important}
            .ma-art-card h3,.woocommerce ul.products.ma-shop-catalog-grid .woocommerce-loop-product__title,.ma-shop-catalog-card .eael-product-title,.ma-shop-catalog-card .eael-product-title a{margin:14px 0 8px!important;font-size:18px!important;line-height:1.24!important;font-weight:700!important;color:#000!important;letter-spacing:0!important;text-align:left!important;text-decoration:none!important}
            .ma-art-card__meta,.ma-art-card__exhibit,.ma-on-view-line{margin:.35rem 0 8px;color:#555;font-size:14px;line-height:1.45;text-align:left}
            .ma-shop-catalog-meta{display:grid;gap:3px;margin:0 0 10px;color:#333;font-size:14px;line-height:1.35;text-align:left}
            .ma-shop-catalog-meta div{display:block}
            .ma-shop-catalog-meta span{display:inline-block;margin-right:6px;color:#777;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
            .ma-art-card__price,.woocommerce ul.products.ma-shop-catalog-grid .price,.ma-shop-catalog-card .eael-product-price{margin:0!important;color:#000!important;font-size:16px!important;line-height:1.25!important;font-weight:700!important;text-align:left!important}
            .ma-hide-elementor-products,.ma-hide-featured-store-section,.woocommerce ul.products.ma-hide-elementor-products,.elementor-widget-wc-categories.ma-hide-elementor-products{display:none!important}
            .ma-store-grid-placeholder{clear:both;display:block;min-height:820px}
            .ma-custom-store-grid{box-sizing:border-box;clear:both;display:block!important;width:100%;max-width:100%;margin:44px 0 0!important;padding:30px 0 0!important;border-top:1px solid rgba(0,0,0,.12);text-align:left;font-family:' . esc_html($font_stack) . '}
            .ma-custom-store-grid *{box-sizing:border-box}
            .ma-custom-store-grid__header{display:grid!important;grid-template-columns:minmax(0,1fr) auto;align-items:end;gap:18px;margin:0 0 30px!important;min-height:32px}
            .ma-custom-store-grid__header h2{display:block!important;margin:0!important;padding:0!important;color:#000!important;font-family:' . esc_html($font_stack) . '!important;font-size:26px!important;line-height:1.15!important;font-weight:650!important;letter-spacing:0!important;text-align:left!important;text-decoration:none!important}
            .ma-custom-store-grid__header p{display:block!important;margin:0!important;padding:0!important;color:#555!important;font-family:' . esc_html($font_stack) . '!important;font-size:14px!important;line-height:1.3!important;font-weight:400!important;text-align:right!important;white-space:nowrap}
            .ma-custom-store-grid [hidden]{display:none!important}
            .ma-custom-store-active-filters{margin:-12px 0 24px!important;color:#555;font-family:' . esc_html($font_stack) . ';font-size:14px;line-height:1.4}
            .ma-custom-store-grid--loading .ma-custom-store-grid__items{opacity:.45;transition:opacity .15s ease}
            .ma-custom-store-grid__items{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr));gap:64px 40px;width:100%;align-items:start;justify-items:stretch}
            .ma-custom-store-empty{margin:20px 0!important;color:#555;font-family:' . esc_html($font_stack) . ';font-size:15px}
            .ma-custom-store-card{display:grid!important;grid-template-rows:auto auto 1fr auto;min-width:0;margin:0!important;padding:0!important;background:#fff;color:#000;font-family:' . esc_html($font_stack) . '}
            .ma-custom-store-card__image{display:block;width:100%;aspect-ratio:4/3;overflow:hidden;background:#f4f4f4;text-decoration:none}
            .ma-custom-store-card__image img{display:block;width:100%;height:100%;aspect-ratio:4/3;object-fit:cover;border-radius:0!important;box-shadow:none!important}
            .ma-custom-store-card h3{display:block!important;margin:16px 0 11px!important;padding:0!important;font-family:' . esc_html($font_stack) . '!important;font-size:18px!important;line-height:1.28!important;font-weight:650!important;letter-spacing:0!important;text-align:left!important}
            .ma-custom-store-card h3 a{color:#000!important;text-decoration:none!important}
            .ma-custom-store-card h3 a:hover{text-decoration:underline!important;text-underline-offset:3px}
            .ma-custom-store-card__meta{display:grid!important;gap:6px;margin:0 0 14px!important;color:#222;font-family:' . esc_html($font_stack) . ';font-size:14px;line-height:1.35;text-align:left}
            .ma-custom-store-card__meta div{display:grid!important;grid-template-columns:minmax(78px,max-content) minmax(0,1fr);column-gap:10px;align-items:baseline}
            .ma-custom-store-card__meta span{display:block;color:#5f6368;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em}
            .ma-custom-store-card__meta span::after{content:":"}
            .ma-custom-store-card__price{display:block!important;margin:2px 0 0!important;color:#111!important;font-family:Georgia,"Times New Roman",serif!important;font-size:17px!important;line-height:1.25!important;font-weight:500!important;text-align:left!important}
            .woocommerce ul.products.ma-shop-catalog-grid{clear:both;display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;column-gap:34px!important;row-gap:52px!important;width:100%!important;height:auto!important;margin:0!important;align-items:start!important;list-style:none!important}
            .woocommerce ul.products.ma-shop-catalog-grid>li.product{position:relative!important;left:auto!important;top:auto!important;float:none!important;display:block!important;width:auto!important;min-width:0!important;margin:0!important;padding:0!important;transform:none!important;background:#fff!important;border:0!important;box-shadow:none!important}
            .woocommerce ul.products.ma-shop-catalog-grid .woocommerce-LoopProduct-link,.woocommerce ul.products.ma-shop-catalog-grid .product-image-wrap,.woocommerce ul.products.ma-shop-catalog-grid .image-wrap{display:block!important;width:100%!important;margin:0!important;padding:0!important;border:0!important;border-radius:0!important;box-shadow:none!important;overflow:hidden!important;aspect-ratio:4/3!important}
            .woocommerce ul.products.ma-shop-catalog-grid .button,.woocommerce ul.products.ma-shop-catalog-grid .added_to_cart,.woocommerce ul.products.ma-shop-catalog-grid .star-rating{display:none!important}
            .woocommerce ul.products.ma-shop-catalog-grid::before,.woocommerce ul.products.ma-shop-catalog-grid::after{content:none!important;display:none!important}
            body.woocommerce.archive .widget-area,body.tax-product_cat .widget-area{float:left}
            .ma-clean-artist-filter .wpfFilterVerScroll{max-height:360px!important;padding-right:6px}
            .ma-clean-artist-filter .wpfLiLabel{display:flex!important;align-items:center;gap:8px;margin:0!important;padding:5px 0!important;color:#111;font-size:14px;line-height:1.25}
            .ma-clean-artist-filter .wpfFilterTaxNameWrapper,.ma-clean-artist-filter .wpfValue{white-space:normal!important;word-break:normal!important}
            .ma-clean-artist-filter .wpfCount{margin-left:4px;color:#777;font-size:12px}
            @media(min-width:1200px){body.post-type-archive-product .neve-main>.container,body.tax-product_cat .neve-main>.container{max-width:1280px}}
            @media(max-width:1024px){.ma-shop-on-view__grid,.woocommerce ul.products.ma-shop-catalog-grid,.ma-custom-store-grid__items{grid-template-columns:repeat(2,minmax(0,1fr))!important;column-gap:26px!important;row-gap:44px!important}}
            @media(max-width:640px){.ma-shop-section-header,.ma-custom-store-grid__header{display:block}.ma-shop-section-header p,.ma-custom-store-grid__header p{margin-top:6px!important;text-align:left!important}.ma-shop-on-view__grid,.woocommerce ul.products.ma-shop-catalog-grid,.ma-custom-store-grid__items{grid-template-columns:1fr!important}}
        </style>';
    }

    private static function current_on_view_products(): array {
        $products = wc_get_products([
            'status' => 'publish',
            'limit' => -1,
            'return' => 'objects',
            'meta_query' => [[
                'key' => self::META_PREFIX . 'exhibits_json',
                'compare' => 'EXISTS',
            ]],
        ]);
        $items = [];
        foreach ($products as $product) {
            if (!$product instanceof WC_Product) {
                continue;
            }
            $exhibit = self::current_product_exhibit($product->get_id());
            if ($exhibit) {
                $items[] = ['product' => $product, 'exhibit' => $exhibit];
            }
        }
        usort($items, static function ($a, $b) {
            return strcmp((string) ($a['exhibit']['end_date'] ?? ''), (string) ($b['exhibit']['end_date'] ?? ''));
        });
        return $items;
    }

    private static function current_product_exhibit(int $product_id): array {
        $json = (string) get_post_meta($product_id, self::META_PREFIX . 'exhibits_json', true);
        $records = $json ? json_decode($json, true) : [];
        if (!is_array($records)) {
            return [];
        }
        $today = current_time('Y-m-d');
        foreach ($records as $record) {
            $start = self::date_ymd($record['start_date'] ?? '');
            $end = self::date_ymd($record['end_date'] ?? ($record['start_date'] ?? ''));
            if ($start && $end && $today >= $start && $today <= $end) {
                return $record;
            }
        }
        return [];
    }

    private static function is_current_exhibit($record): bool {
        if (!is_array($record)) {
            return false;
        }
        $compact = self::compact_exhibit_record($record);
        $start = self::date_ymd($compact['start_date'] ?? '');
        $end = self::date_ymd($compact['end_date'] ?? ($compact['start_date'] ?? ''));
        $today = current_time('Y-m-d');
        return $start && $end && $today >= $start && $today <= $end;
    }

    private static function compact_exhibit_records(array $records): array {
        return array_values(array_filter(array_map([__CLASS__, 'compact_exhibit_record'], $records)));
    }

    private static function compact_exhibit_record($record): array {
        if (!is_array($record)) {
            return [];
        }
        if (isset($record['title']) || isset($record['start_date'])) {
            return [
                'title' => self::text($record['title'] ?? ''),
                'venue' => self::text($record['venue'] ?? ''),
                'location' => self::text($record['location'] ?? ''),
                'start_date' => self::date_ymd($record['start_date'] ?? ''),
                'end_date' => self::date_ymd($record['end_date'] ?? ($record['start_date'] ?? '')),
                'url' => esc_url_raw(self::text($record['url'] ?? '')),
                'image_url' => self::attachment_or_url_to_public_image($record['image_url'] ?? ''),
            ];
        }
        $fields = $record['fields'] ?? [];
        return [
            'title' => self::text($fields['Exhibit Title'] ?? $fields['Title'] ?? $fields['Name'] ?? ''),
            'venue' => self::text($fields['Venue Name'] ?? $fields['Venue'] ?? ''),
            'location' => self::text($fields['Location'] ?? ''),
            'start_date' => self::date_ymd($fields['Start Date'] ?? $fields['Exhibit Start Date'] ?? ''),
            'end_date' => self::date_ymd($fields['End Date'] ?? $fields['Exhibit End Date'] ?? ($fields['Start Date'] ?? '')),
            'url' => esc_url_raw(self::text(self::first_field_value($fields, ['Exhibit URL', 'URL', 'Link', 'Website', 'Page URL', 'Event URL']))),
            'image_url' => self::attachment_or_url_to_public_image(self::first_field_value($fields, ['Preview Image', 'Exhibit Image', 'Image', 'Poster', 'Hero Image', 'JPEG', 'Jpeg Image', 'Photo'])),
        ];
    }

    private static function current_exhibit_label(array $exhibit): string {
        return implode(', ', array_filter([
            self::text($exhibit['title'] ?? ''),
            implode(', ', array_filter([self::text($exhibit['venue'] ?? ''), self::text($exhibit['location'] ?? '')])),
            self::format_date_range($exhibit['start_date'] ?? '', $exhibit['end_date'] ?? ''),
        ]));
    }

    private static function format_date_range($start, $end): string {
        $start_text = self::format_date($start);
        $end_text = self::format_date($end);
        if (!$start_text) {
            return '';
        }
        return $end_text && $end_text !== $start_text ? "{$start_text} - {$end_text}" : $start_text;
    }

    private static function format_date($value): string {
        $ymd = self::date_ymd($value);
        return $ymd ? date_i18n('F j, Y', strtotime($ymd . ' 00:00:00 UTC')) : '';
    }

    private static function shop_catalog_product_meta(): array {
        if (!class_exists('WooCommerce')) {
            return [];
        }
        $products = wc_get_products(['status' => 'publish', 'limit' => -1, 'return' => 'objects']);
        $meta = [];
        foreach ($products as $product) {
            if ($product instanceof WC_Product) {
                $meta[$product->get_id()] = ['rows' => self::product_detail_rows($product)];
            }
        }
        return $meta;
    }

    private static function shop_catalog_products_for_custom_grid(array $filters = []): array {
        if (!class_exists('WooCommerce')) {
            return [];
        }
        $args = [
            'status' => 'publish',
            'limit' => empty($filters['artist']) && empty($filters['medium']) ? 120 : 300,
            'return' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
        ];
        $tax_query = [];
        $artist_terms = self::catalog_filter_terms($filters['artist'] ?? []);
        if ($artist_terms) {
            $tax_query[] = [
                'taxonomy' => 'pa_artist',
                'field' => 'slug',
                'terms' => $artist_terms,
                'operator' => 'IN',
            ];
        }
        $medium_terms = self::catalog_filter_terms($filters['medium'] ?? []);
        if ($medium_terms) {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field' => 'slug',
                'terms' => $medium_terms,
                'operator' => 'IN',
            ];
        }
        if ($tax_query) {
            $args['tax_query'] = count($tax_query) > 1 ? array_merge(['relation' => 'AND'], $tax_query) : $tax_query;
        }

        $product_ids = wc_get_products($args);
        $items = [];
        foreach ($product_ids as $product_id) {
            $product = wc_get_product((int) $product_id);
            if (!($product instanceof WC_Product)) {
                continue;
            }
            $items[] = self::catalog_item_for_product($product);
        }
        return $items;
    }

    private static function catalog_filter_terms($values): array {
        $values = is_array($values) ? $values : [$values];
        $terms = [];
        foreach ($values as $value) {
            $value = sanitize_title(str_replace('_', '-', self::text($value)));
            if (!$value) {
                continue;
            }
            $terms[] = $value;
            $terms[] = str_replace('-', '_', $value);
        }
        return array_values(array_unique($terms));
    }

    private static function catalog_item_for_product(WC_Product $product): array {
        $image_id = $product->get_image_id();
        $image = $image_id ? wp_get_attachment_image_url($image_id, 'large') : wc_placeholder_img_src('large');
        return [
            'id' => $product->get_id(),
            'title' => wp_strip_all_tags($product->get_name()),
            'url' => get_permalink($product->get_id()),
            'image' => $image ?: '',
            'price_html' => wp_kses_post($product->get_price_html()),
            'artist' => self::product_detail_value($product, 'Artist'),
            'medium' => self::product_detail_value($product, 'Medium') ?: self::product_detail_value($product, 'Series'),
            'rows' => self::product_detail_rows($product),
        ];
    }

    private static function product_detail_value(WC_Product $product, string $label): string {
        foreach (self::product_detail_rows($product) as $row) {
            if (strcasecmp(self::text($row['label'] ?? ''), $label) === 0) {
                return self::text($row['value'] ?? '');
            }
        }
        return '';
    }

    private static function product_detail_rows(WC_Product $product): array {
        $post = get_post($product->get_id());
        $source = $post ? ((string) $post->post_title . "\n" . (string) $post->post_content) : $product->get_name();
        $artist = self::text(get_post_meta($product->get_id(), 'ma_artist_name', true)) ?: self::infer_artist_name_from_text($source);
        $year = self::text(get_post_meta($product->get_id(), 'year', true)) ?: self::infer_year_from_text($source);
        $series = self::text(get_post_meta($product->get_id(), 'ma_artwork_series', true)) ?: self::product_series_label($product);
        $medium = self::text(get_post_meta($product->get_id(), 'material', true)) ?: self::infer_medium_from_text($source);
        $dimensions = self::text(get_post_meta($product->get_id(), 'dimensions', true)) ?: self::infer_dimensions_from_text($source);
        $sku = self::text($product->get_sku());

        return array_values(array_filter([
            $artist ? ['label' => 'Artist', 'value' => $artist] : null,
            $year ? ['label' => 'Year', 'value' => $year] : null,
            $medium ? ['label' => 'Medium', 'value' => $medium] : null,
            $dimensions ? ['label' => 'Size', 'value' => $dimensions] : null,
            $series ? ['label' => 'Series', 'value' => $series] : null,
            $sku ? ['label' => 'Inventory', 'value' => $sku] : null,
        ]));
    }

    private static function product_detail_lines(WC_Product $product): array {
        return array_values(array_filter([
            self::text(get_post_meta($product->get_id(), 'ma_artwork_series', true)) ?: self::product_series_label($product),
            self::text(get_post_meta($product->get_id(), 'material', true)),
            self::text(get_post_meta($product->get_id(), 'dimensions', true)),
        ]));
    }

    private static function infer_year_from_text(string $text): string {
        $plain = self::clean_bio_text($text);
        if (preg_match('/\b(19|20)\d{2}\b/', $plain, $match)) {
            return $match[0];
        }
        return '';
    }

    private static function infer_dimensions_from_text(string $text): string {
        $plain = self::clean_bio_text($text);
        if (preg_match('/\b\d+(?:\.\d+)?\s*(?:x|×)\s*\d+(?:\.\d+)?(?:\s*(?:x|×)\s*\d+(?:\.\d+)?)?\s*(?:inches|inch|in\.|")\b/i', $plain, $match)) {
            return trim($match[0]);
        }
        return '';
    }

    private static function infer_medium_from_text(string $text): string {
        $plain = self::clean_bio_text($text);
        if (preg_match('/\b(?:inches|inch|in\.)\s+([^\.]{3,120})(?:\.|$)/i', $plain, $match)) {
            return trim($match[1]);
        }
        return '';
    }

    private static function product_series_label(WC_Product $product): string {
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if (!is_array($terms)) {
            return '';
        }
        foreach ($terms as $term) {
            if (!in_array($term->slug, ['artwork', 'uncategorized'], true)) {
                return self::text($term->name);
            }
        }
        return '';
    }

    private static function maybe_patch_shop_url(array $options, string $record_id, $url): void {
        if (!$record_id || !$url) {
            return;
        }
        $field = self::airtable_field_name($options, 'shop_url');
        if (!$field) {
            return;
        }
        $endpoint = sprintf('https://api.airtable.com/v0/%s/%s/%s', rawurlencode($options['base_id']), rawurlencode($options['table_id']), rawurlencode($record_id));
        wp_remote_request($endpoint, [
            'method' => 'PATCH',
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $options['airtable_token'], 'Content-Type' => 'application/json'],
            'body' => wp_json_encode(['fields' => [$field => $url]]),
        ]);
    }

    private static function is_artwork_catalog_request(): bool {
        if (function_exists('is_paged') && is_paged()) {
            return false;
        }
        if (function_exists('is_shop') && is_shop()) {
            return true;
        }
        if (function_exists('is_product_category') && is_product_category()) {
            $term = get_queried_object();
            if (!($term instanceof WP_Term)) {
                return false;
            }
            if ($term->slug === 'artwork') {
                return true;
            }
            $ancestors = get_ancestors((int) $term->term_id, 'product_cat');
            foreach ($ancestors as $ancestor_id) {
                $ancestor = get_term((int) $ancestor_id, 'product_cat');
                if ($ancestor instanceof WP_Term && $ancestor->slug === 'artwork') {
                    return true;
                }
            }
            return false;
        }
        $path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
        return $path === 'store' || $path === 'shop' || strpos($path, 'collection/artwork') === 0;
    }

    private static function parse_artwork_dimensions(string $dimensions): array {
        $value = strtolower($dimensions);
        $value = str_replace(['"', 'inches', 'inch', 'in.'], ' in', $value);
        if (!preg_match_all('/\d+(?:\.\d+)?/', $value, $matches) || count($matches[0]) < 2) {
            return [];
        }
        $width = (float) $matches[0][0];
        $height = (float) $matches[0][1];
        return ($width > 0 && $height > 0) ? [$width, $height] : [];
    }

    private static function format_inches(float $value): string {
        $rounded = round($value, 2);
        return abs($rounded - round($rounded)) < 0.001 ? (string) (int) round($rounded) : rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');
    }

    private static function is_available($value): bool {
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        }
        $value = strtolower(trim((string) $value));
        return in_array($value, ['available', 'yes', 'true', '1', 'for sale', 'in stock'], true) || strpos($value, 'available') !== false;
    }

    private static function text($value): string {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $parts[] = (string) $item;
                } elseif (is_array($item) && isset($item['name'])) {
                    $parts[] = (string) $item['name'];
                }
            }
            return trim(implode(', ', $parts));
        }
        return trim((string) $value);
    }

    private static function money($value): string {
        $amount = (float) preg_replace('/[^0-9.]/', '', (string) $value);
        return $amount > 0 ? number_format($amount, 2, '.', '') : '';
    }

    private static function date_ymd($value): string {
        return preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value, $match) ? $match[0] : '';
    }

    private static function options(): array {
        $saved = get_option(self::OPTION_KEY, []);
        $defaults = [
            'airtable_token' => defined('MA_ARTWORK_AIRTABLE_TOKEN') ? MA_ARTWORK_AIRTABLE_TOKEN : '',
            'base_id' => '',
            'table_id' => 'Artwork Inventory',
            'artist_table_id' => 'Artists',
            'artist_portrait_drive_folder_url' => '',
            'exhibit_table_id' => '',
            'visitor_table_id' => 'Visitors',
            'view' => '',
            'batch_size' => 25,
            'write_shop_url' => 0,
            'last_sync_at' => '',
            'last_visitor_sync_at' => '',
            'cron_secret' => '',
            'field_map' => self::default_field_map(),
            'visitor_field_map' => self::default_visitor_field_map(),
        ];
        $options = wp_parse_args(is_array($saved) ? $saved : [], $defaults);
        $options['field_map'] = wp_parse_args(is_array($options['field_map'] ?? null) ? $options['field_map'] : [], self::default_field_map());
        $options['visitor_field_map'] = wp_parse_args(is_array($options['visitor_field_map'] ?? null) ? $options['visitor_field_map'] : [], self::default_visitor_field_map());
        return $options;
    }

    private static function default_field_map(): array {
        return [
            'title' => 'Artwork Title',
            'year' => 'Year',
            'medium' => 'Material',
            'dimensions' => 'Dimensions',
            'edition' => 'Edition',
            'price' => 'Price',
            'description' => 'Artwork Description',
            'series' => 'Photo Series',
            'availability' => 'Available',
            'image' => 'Jpeg Image',
            'inventory_number' => 'Inventory Number',
            'exhibit_records' => 'Exhibit Records',
            'exhibit_title' => 'Exhibit Title',
            'exhibit_start' => 'Start Date',
            'exhibit_end' => 'End Date',
            'exhibit_venue' => 'Venue Name',
            'exhibit_location' => 'Location',
            'last_modified' => 'Airtable Check',
            'shop_url' => 'Shop URL',
            'artist_records' => 'Artist Name',
            'artist_name' => 'Artist Name',
            'artist_bio' => 'Bio (from Artist Name)',
            'artist_portrait' => 'Artist Portrait',
            'artist_portrait_url' => 'Artist Portrait URL',
        ];
    }

    private static function default_visitor_field_map(): array {
        return [
            'visitor_name' => 'Name',
            'visitor_email' => 'Email',
            'visit_date' => 'Date of Visit',
            'event_title' => 'Event',
            'event_start' => 'Event Start',
            'event_end' => 'Event End',
            'event_time' => 'Event Time',
            'event_url' => 'Event URL',
            'ticket_name' => 'Ticket',
            'rsvp_status' => 'RSVP Status',
            'rsvp_order_id' => 'RSVP Order ID',
            'wp_event_id' => 'WordPress Event ID',
            'wp_attendee_id' => 'WordPress Attendee ID',
            'wp_ticket_id' => 'WordPress Ticket ID',
            'source' => 'Source',
            'notes' => 'Notes',
        ];
    }

    private static function field_aliases(string $key): array {
        $aliases = [
            'title' => ['Artwork Title', 'Title', 'Work Title', 'Name'],
            'year' => ['Year', 'Date', 'Artwork Year'],
            'medium' => ['Material', 'Medium', 'Medium/Material', 'Materials'],
            'dimensions' => ['Dimensions', 'Dimensions Print', 'Dimensions Framed', 'Size'],
            'edition' => ['Edition', 'Edition Size'],
            'price' => ['Price', 'Retail Price', 'Sale Price'],
            'description' => ['Artwork Description LONG FORM', 'Artwork Description', 'Description'],
            'series' => ['Photo Series', 'Artwork Series', 'Series', 'Category'],
            'availability' => ['Available', 'Availability', 'Status', 'Inventory Status'],
            'image' => ['Jpeg Image', 'JPEG Image', 'Image', 'Images', 'Artwork Image', 'Photo'],
            'inventory_number' => ['Inventory Number'],
            'exhibit_records' => ['Exhibit Records', 'Exhibitions', 'Exhibit'],
            'exhibit_title' => ['Exhibit Title', 'Currently On View', 'On View'],
            'exhibit_start' => ['Start Date', 'Exhibit Start Date', 'On View Start'],
            'exhibit_end' => ['End Date', 'Exhibit End Date', 'On View End'],
            'exhibit_venue' => ['Venue Name', 'Venue'],
            'exhibit_location' => ['Location'],
            'last_modified' => ['Airtable Check', 'Last Modified', 'Last Modified Time'],
            'shop_url' => ['Shop URL', 'WooCommerce URL', 'WordPress URL'],
            'artist_records' => ['Artist Name', 'Artist', 'Artists'],
            'artist_name' => ['Artist Name', 'Artist', 'Artists', 'Name (from Artist Name)', 'Artist Name from Submission'],
            'artist_bio' => ['Bio (from Artist Name)', 'Artist Bio from Submission Form', 'Artist Bio', 'Bio', 'Biography', 'Artist Biography'],
            'artist_portrait' => ['Portrait jpg', 'Portrait JPG', 'Portrait jpeg', 'Portrait JPEG', 'Artist Portrait', 'Portrait', 'Headshot', 'Artist Headshot', 'Artist Image', 'Artist Photo', 'Portrait Jpeg', 'Jpeg Image', 'JPEG Image', 'Photo'],
            'artist_portrait_url' => ['Artist Portrait Public URL', 'Artist Portrait URL', 'Google Drive Portrait URL', 'Public Portrait URL', 'Portrait URL', 'portrait url', 'Headshot URL', 'Artist Image URL', 'Artist Photo URL', 'Google Drive URL', 'LookupArtistPortraitUrl'],
        ];
        return $aliases[$key] ?? [];
    }

    private static function visitor_field_aliases(string $key): array {
        $aliases = [
            'visitor_name' => ['Name', 'Visitor Name', 'Full Name', 'Guest Name', 'Attendee Name'],
            'visitor_email' => ['Email', 'Email Address', 'Visitor Email', 'Guest Email', 'Attendee Email'],
            'visit_date' => ['Date of Visit', 'Visit Date', 'Date Visited', 'Date', 'Event Date'],
            'event_title' => ['Event', 'Event Name', 'Event Title', 'Program', 'Program Name'],
            'event_start' => ['Event Start', 'Event Start Date', 'Start Date', 'Start Date/Time'],
            'event_end' => ['Event End', 'Event End Date', 'End Date', 'End Date/Time'],
            'event_time' => ['Event Time', 'Time', 'Visit Time'],
            'event_url' => ['Event URL', 'Event Link', 'WordPress Event URL', 'URL'],
            'ticket_name' => ['Ticket', 'Ticket Name', 'RSVP Ticket'],
            'rsvp_status' => ['RSVP Status', 'RSVP', 'Attendance Status', 'Status'],
            'rsvp_order_id' => ['RSVP Order ID', 'Order ID', 'RSVP ID'],
            'wp_event_id' => ['WordPress Event ID', 'WP Event ID', 'Event Post ID'],
            'wp_attendee_id' => ['WordPress Attendee ID', 'WP Attendee ID', 'Attendee ID'],
            'wp_ticket_id' => ['WordPress Ticket ID', 'WP Ticket ID', 'Ticket ID'],
            'source' => ['Source', 'Signup Source', 'Visit Source'],
            'notes' => ['Notes', 'RSVP Notes', 'Details', 'Other Info'],
        ];
        return $aliases[$key] ?? [];
    }
}

MA_Artwork_Airtable_Woo_Sync::init();
register_activation_hook(__FILE__, ['MA_Artwork_Airtable_Woo_Sync', 'activate']);
register_deactivation_hook(__FILE__, ['MA_Artwork_Airtable_Woo_Sync', 'deactivate']);

