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
    private const ARTIST_FIELD_SETUP_CACHE_KEY = 'ma_artwork_airtable_artist_field_setup_checked';
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
        add_action('init', [__CLASS__, 'register_artist_profile_rewrites'], 5);
        add_action('init', [__CLASS__, 'ensure_runtime_setup'], 20);
        add_action('save_post_post', [__CLASS__, 'sync_artist_post_to_products'], 20, 3);
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
        add_filter('woocommerce_related_products', [__CLASS__, 'filter_contextual_related_products'], 20, 3);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_public_fix_styles'], 999);
        add_action('wp_head', [__CLASS__, 'render_catalog_head_guard'], 1);
        add_action('wp_head', [__CLASS__, 'render_single_post_cover_spacing_css'], 1);
        add_action('wp_head', [__CLASS__, 'render_events_page_first_paint_css'], 2);
        add_action('wp_head', [__CLASS__, 'render_donate_page_first_paint_css'], 3);
        add_action('wp_head', [__CLASS__, 'render_global_site_polish_css'], 18);
        add_action('wp_head', [__CLASS__, 'render_homepage_final_layout_overrides'], 19);
        add_action('wp_head', [__CLASS__, 'render_donate_menu_button_css'], 19);
        add_action('wp_head', [__CLASS__, 'render_artist_profile_css'], 20);
        add_action('wp_head', [__CLASS__, 'render_staff_page_spacing_css'], 999);
        add_action('template_redirect', [__CLASS__, 'start_frontend_performance_buffer'], 0);
        add_action('template_redirect', [__CLASS__, 'render_news_posts_page_template'], 2);
        add_action('template_redirect', [__CLASS__, 'redirect_dated_artist_profile_urls'], 3);
        add_action('pre_get_posts', [__CLASS__, 'exclude_artist_profiles_from_home_news']);
        add_filter('posts_results', [__CLASS__, 'dedupe_homepage_weekly_event_posts'], 20, 2);
        add_filter('widget_posts_args', [__CLASS__, 'exclude_artist_profiles_from_recent_posts_widget']);
        add_filter('wp_nav_menu_objects', [__CLASS__, 'order_donate_menu_after_community_artists'], 20, 2);
        add_filter('nav_menu_link_attributes', [__CLASS__, 'filter_donate_menu_link_attributes'], 20, 4);
        add_filter('nav_menu_css_class', [__CLASS__, 'filter_donate_menu_item_classes'], 20, 4);
        add_action('wp_footer', [__CLASS__, 'render_home_donation_button_redirect'], 1);
        add_action('wp_footer', [__CLASS__, 'render_donate_page_button_redirect'], 2);
        add_action('wp_footer', [__CLASS__, 'render_single_event_rsvp_jump_button'], 4);
        add_action('wp_footer', [__CLASS__, 'render_staff_page_spacing_fallback'], 1);
        add_action('wp_footer', [__CLASS__, 'render_single_product_artwork_panel_fallback'], 12);
        add_action('wp_footer', [__CLASS__, 'render_variable_product_image_switcher'], 13);
        add_action('wp_footer', [__CLASS__, 'render_catalog_footer_assets'], 20);
        add_action('wp_enqueue_scripts', [__CLASS__, 'optimize_frontend_product_assets'], 100);
        add_action('wp_enqueue_scripts', [__CLASS__, 'optimize_frontend_global_assets'], 101);
        add_action('send_headers', [__CLASS__, 'send_front_page_public_cache_headers'], 999);
        add_filter('wp_headers', [__CLASS__, 'filter_public_cache_headers'], 999);
        add_filter('woocommerce_set_cookie_enabled', [__CLASS__, 'filter_woocommerce_cookie_enabled'], 20, 5);
        add_filter('script_loader_tag', [__CLASS__, 'filter_frontend_script_tag'], 20, 3);
        add_filter('style_loader_tag', [__CLASS__, 'filter_frontend_style_tag'], 20, 4);
        add_filter('post_link', [__CLASS__, 'filter_artist_profile_permalink'], 20, 3);
        add_filter('wp_resource_hints', [__CLASS__, 'filter_frontend_resource_hints'], 20, 2);
        add_filter('the_content', [__CLASS__, 'replace_homepage_static_events_block'], 20);
        add_filter('the_content', [__CLASS__, 'append_product_body_sections'], 30);
        add_filter('the_content', [__CLASS__, 'replace_sponsorship_page_content'], 35);
        add_filter('the_content', [__CLASS__, 'replace_about_page_content'], 36);
        add_filter('the_content', [__CLASS__, 'replace_news_page_content'], 37);
        add_filter('the_content', [__CLASS__, 'replace_subscribe_page_content'], 38);
        add_filter('the_content', [__CLASS__, 'replace_podcast_page_content'], 39);
        add_filter('the_content', [__CLASS__, 'replace_residency_page_content'], 40);
        add_filter('the_content', [__CLASS__, 'replace_community_artists_page_content'], 41);
        add_filter('the_content', [__CLASS__, 'prepend_single_post_content_header'], 41);
        add_filter('the_content', [__CLASS__, 'prepend_artist_post_content_header'], 42);
        add_shortcode('ma_on_view_now', [__CLASS__, 'on_view_shortcode']);
        add_shortcode('ma_artist_artworks', [__CLASS__, 'artist_artworks_shortcode']);
        add_shortcode('ma_past_sponsors', [__CLASS__, 'past_sponsors_shortcode']);
        add_shortcode('ma_home_events', [__CLASS__, 'home_events_shortcode']);
    }

    public static function order_donate_menu_after_community_artists(array $items, $args): array {
        $donate_index = null;
        $community_index = null;

        foreach ($items as $index => $item) {
            if (self::is_donate_menu_item($item)) {
                $donate_index = $index;
            }
            if (self::is_community_artists_menu_item($item)) {
                $community_index = $index;
            }
        }

        if ($donate_index === null || $community_index === null || $donate_index > $community_index) {
            return $items;
        }

        $donate = $items[$donate_index];
        array_splice($items, $donate_index, 1);
        if ($donate_index < $community_index) {
            $community_index--;
        }
        array_splice($items, $community_index + 1, 0, [$donate]);

        foreach ($items as $order => $item) {
            $item->menu_order = $order + 1;
        }

        return $items;
    }

    public static function filter_donate_menu_link_attributes(array $atts, WP_Post $item, $args, int $depth): array {
        if (!self::is_donate_menu_item($item)) {
            return $atts;
        }

        $atts['href'] = 'https://givebutter.com/support-mas-house-year-round-s5wfol';
        $atts['data-gb-account'] = 'yQLEsDOjxW31tHDZ';
        $atts['data-gb-campaign'] = 'support-mas-house-year-round-s5wfol';
        $atts['class'] = trim(($atts['class'] ?? '') . ' ma-givebutter-donate-link');
        $atts['aria-label'] = 'Donate to Ma\'s House';

        return $atts;
    }

    public static function filter_donate_menu_item_classes(array $classes, WP_Post $item, $args, int $depth): array {
        if (!self::is_donate_menu_item($item)) {
            return $classes;
        }

        $classes = array_diff($classes, ['menu-item-has-children', 'menu-item-has-children--active', 'has-submenu']);
        $classes[] = 'ma-donate-menu-item';

        return array_values(array_unique($classes));
    }

    private static function is_donate_menu_item($item): bool {
        $title = isset($item->title) ? trim(wp_strip_all_tags((string) $item->title)) : '';
        $url = isset($item->url) ? (string) $item->url : '';

        return strcasecmp($title, 'Donate') === 0
            || strpos($url, '/donations/generalfund/') !== false
            || strpos($url, 'givebutter.com/support-mas-house-year-round-s5wfol') !== false;
    }

    private static function is_community_artists_menu_item($item): bool {
        $title = isset($item->title) ? trim(wp_strip_all_tags((string) $item->title)) : '';
        $url = isset($item->url) ? (string) $item->url : '';

        return strcasecmp($title, 'Community Artists') === 0
            || strpos($url, '/community-artists/') !== false;
    }

    public static function render_donate_menu_button_css(): void {
        if (is_admin()) {
            return;
        }

        echo '<style id="ma-givebutter-donate-menu-css" data-no-optimize="1" data-cfasync="false">@media(min-width:961px){body .builder-item--primary-menu .nav-ul{flex-wrap:nowrap!important;align-items:center!important}body .builder-item--primary-menu .nav-ul>li>a:not(.ma-givebutter-donate-link){padding-left:9px!important;padding-right:9px!important}body .builder-item--primary-menu .nav-ul>li.ma-donate-menu-item{display:flex!important;align-items:center!important;flex:0 0 auto!important;margin-left:2px!important;white-space:nowrap!important}}.ma-givebutter-donate-link{display:inline-flex!important;align-items:center!important;justify-content:center!important;min-height:32px!important;padding:7px 12px!important;border-radius:4px!important;background:#d92f2f!important;color:#fff!important;font-size:14px!important;font-weight:700!important;line-height:1!important;text-decoration:none!important;white-space:nowrap!important}.ma-givebutter-donate-link:hover,.ma-givebutter-donate-link:focus{background:#111!important;color:#fff!important;text-decoration:none!important}.ma-givebutter-donate-link:focus-visible{outline:2px solid #111!important;outline-offset:3px!important}.ma-donate-menu-item .caret-wrap,.ma-donate-menu-item .sub-arrow,.ma-donate-menu-item .dropdown-toggle,.ma-donate-menu-item .nv-icon,.ma-donate-menu-item>button,.ma-donate-menu-item>.sub-menu{display:none!important}@media(max-width:1560px) and (min-width:1181px){body .builder-item--header_search .component-wrap.search-field,body .builder-item--header_search .widget-search,body .builder-item--header_search .search-form{width:230px!important;max-width:230px!important}body .builder-item--primary-menu .nav-ul>li>a:not(.ma-givebutter-donate-link){padding-left:7px!important;padding-right:7px!important;font-size:14px!important}.ma-givebutter-donate-link{padding:7px 10px!important;font-size:14px!important}}@media(max-width:1180px) and (min-width:961px){body .builder-item--primary-menu .nav-ul>li>a:not(.ma-givebutter-donate-link){padding-left:7px!important;padding-right:7px!important;font-size:14px!important}.ma-givebutter-donate-link{padding:7px 10px!important;font-size:14px!important}}@media(max-width:960px){.ma-givebutter-donate-link{display:flex!important;width:max-content!important;margin:.35rem 0!important}}</style>';
    }

    public static function exclude_artist_profiles_from_recent_posts_widget(array $args): array {
        $artists = get_term_by('slug', 'artists', 'category');
        if (!$artists instanceof WP_Term) {
            return $args;
        }
        $excluded = array_map('intval', (array) ($args['category__not_in'] ?? []));
        $excluded[] = (int) $artists->term_id;
        $args['category__not_in'] = array_values(array_unique(array_filter($excluded)));
        return $args;
    }

    public static function exclude_artist_profiles_from_home_news(WP_Query $query): void {
        if (is_admin() || !(function_exists('is_front_page') && is_front_page())) {
            return;
        }
        if ($query->is_main_query()) {
            return;
        }
        $post_type = $query->get('post_type');
        $is_post_query = !$post_type || $post_type === 'post' || (is_array($post_type) && in_array('post', $post_type, true));
        if (!$is_post_query) {
            return;
        }
        $resident_artists = get_term_by('slug', 'resident-artists', 'category');
        if ($resident_artists instanceof WP_Term && self::query_targets_category($query, (int) $resident_artists->term_id, 'resident-artists')) {
            $query->set('posts_per_page', 8);
        }
        $news = get_term_by('slug', 'news', 'category');
        if ($news instanceof WP_Term && self::query_targets_category($query, (int) $news->term_id, 'news')) {
            $query->set('posts_per_page', 6);
        }
        $artists = get_term_by('slug', 'artists', 'category');
        if (!$artists instanceof WP_Term) {
            return;
        }
        $excluded = array_map('intval', (array) $query->get('category__not_in'));
        $excluded[] = (int) $artists->term_id;
        $query->set('category__not_in', array_values(array_unique(array_filter($excluded))));
    }

    public static function dedupe_homepage_weekly_event_posts(array $posts, WP_Query $query): array {
        if (is_admin() || !(function_exists('is_front_page') && is_front_page()) || !$posts) {
            return $posts;
        }
        $post_type = $query->get('post_type');
        $is_post_query = !$post_type || $post_type === 'post' || (is_array($post_type) && in_array('post', $post_type, true));
        if (!$is_post_query) {
            return $posts;
        }
        $seen_weekly_titles = [];
        $deduped = [];
        foreach ($posts as $post) {
            if (!($post instanceof WP_Post)) {
                $deduped[] = $post;
                continue;
            }
            $title = self::text($post->post_title);
            if (preg_match('/^Upcoming Events at Ma\'s House - Week of /i', $title)) {
                $key = strtolower($title);
                if (isset($seen_weekly_titles[$key])) {
                    continue;
                }
                $seen_weekly_titles[$key] = true;
            }
            $deduped[] = $post;
        }
        return $deduped;
    }

    private static function query_targets_category(WP_Query $query, int $term_id, string $slug): bool {
        $category_in = array_map('intval', (array) $query->get('category__in'));
        if (in_array($term_id, $category_in, true)) {
            return true;
        }

        $cat = $query->get('cat');
        if ($cat && in_array($term_id, array_map('intval', preg_split('/[,\s]+/', (string) $cat) ?: []), true)) {
            return true;
        }

        $category_name = (string) $query->get('category_name');
        if ($category_name && in_array($slug, array_map('trim', explode(',', $category_name)), true)) {
            return true;
        }

        $tax_query = (array) $query->get('tax_query');
        foreach ($tax_query as $clause) {
            if (!is_array($clause) || ($clause['taxonomy'] ?? '') !== 'category') {
                continue;
            }
            $terms = (array) ($clause['terms'] ?? []);
            $field = (string) ($clause['field'] ?? 'term_id');
            if ($field === 'slug' && in_array($slug, array_map('strval', $terms), true)) {
                return true;
            }
            if (in_array($term_id, array_map('intval', $terms), true)) {
                return true;
            }
        }

        return false;
    }

    public static function activate(): void {
        $options = self::options();
        if (empty($options['cron_secret'])) {
            $options['cron_secret'] = wp_generate_password(32, false, false);
            update_option(self::OPTION_KEY, $options, false);
        }
        wp_clear_scheduled_hook(self::CRON_HOOK);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'ma_artwork_every_six_hours', self::CRON_HOOK);
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function register_artist_profile_rewrites(): void {
        add_rewrite_rule('^artist/([^/]+)/?$', 'index.php?name=$matches[1]', 'top');
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
        if ($schedule && $schedule !== 'ma_artwork_every_six_hours') {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'ma_artwork_every_six_hours', self::CRON_HOOK);
        }

        $rewrite_version = 'artist-profile-v1';
        if (get_option('ma_artwork_rewrite_version') !== $rewrite_version) {
            self::register_artist_profile_rewrites();
            flush_rewrite_rules(false);
            update_option('ma_artwork_rewrite_version', $rewrite_version, false);
        }
    }

    public static function filter_artist_profile_permalink(string $permalink, WP_Post $post, bool $leavename): string {
        if ($post->post_type !== 'post' || !self::is_artist_profile_post((int) $post->ID, (string) $post->post_title)) {
            return $permalink;
        }
        $slug = $leavename ? '%postname%' : $post->post_name;
        return home_url(user_trailingslashit('artist/' . $slug));
    }

    public static function enqueue_public_fix_styles(): void {
        if (is_admin()) {
            return;
        }
        $path = plugin_dir_path(__FILE__) . 'assets/ma-public-fixes.css';
        if (!file_exists($path)) {
            return;
        }
        wp_enqueue_style(
            'ma-artwork-public-fixes',
            plugins_url('assets/ma-public-fixes.css', __FILE__),
            [],
            (string) filemtime($path)
        );
    }

    public static function render_home_donation_button_redirect(): void {
        if (!is_front_page() && !is_home()) {
            return;
        }
        ?>
        <script id="ma-home-donation-button-redirect">
        document.addEventListener('DOMContentLoaded', function () {
            var selectors = [
                '.elementor-element-bb41249 button.js-give-embed-form-modal-opener',
                '.elementor-element-6060d11 button.js-give-embed-form-modal-opener'
            ];
            selectors.forEach(function (selector) {
                document.querySelectorAll(selector).forEach(function (button) {
                    button.type = 'button';
                    button.setAttribute('aria-label', 'Go to the Ma\'s House donation page');
                    button.addEventListener('click', function (event) {
                        event.preventDefault();
                        event.stopPropagation();
                        window.location.href = '<?php echo esc_js(home_url('/donate/')); ?>';
                    }, true);
                });
            });
        });
        </script>
        <?php
    }

    public static function render_events_page_first_paint_css(): void {
        if (!is_page('events')) {
            return;
        }
        ?>
        <style id="ma-events-first-paint-css" data-no-optimize="1" data-cfasync="false">
            body.page-id-1115 .hfg_header.site-header,
            body.page-id-1115 header.site-header,
            body.page-id-1115 .elementor-location-header{position:relative!important;z-index:100000!important;pointer-events:auto!important}
            body.page-id-1115 .header-menu-sidebar,
            body.page-id-1115 .header-menu-sidebar-overlay,
            body.page-id-1115 .sub-menu{z-index:100001!important}
            body.page-id-1115 #content.neve-main,
            body.page-id-1115 .elementor-1115,
            body.page-id-1115 .tribe-events{position:relative!important;z-index:1!important}
            body.page-id-1115 .tribe-events-view-loader.tribe-common-a11y-hidden{pointer-events:none!important}
            body.page-id-1115 #content.neve-main{padding-top:0!important}
            body.page-id-1115 .elementor-1115{font-family:Arial,Helvetica,sans-serif;color:#111}
            body.page-id-1115 .elementor-1115>.elementor-section{padding:34px 22px 70px!important}
            body.page-id-1115 .elementor-1115 .elementor-container{max-width:1180px!important;margin:0 auto!important}
            body.page-id-1115 .tribe-events{width:100%;font-family:Arial,Helvetica,sans-serif!important;color:#111}
            body.page-id-1115 .tribe-events .tribe-events-l-container{max-width:1180px!important;margin:0 auto!important;padding:0!important}
            body.page-id-1115 .tribe-events-header{margin:0 0 30px!important}
            body.page-id-1115 .tribe-events-header__events-bar,
            body.page-id-1115 .tribe-events-c-events-bar{display:flex!important;align-items:center!important;gap:14px!important;min-height:52px!important;border:1px solid #ddd!important;background:#fff!important;padding:8px 12px!important}
            body.page-id-1115 .tribe-events-c-search__input-group{display:flex!important;align-items:center!important;gap:10px!important}
            body.page-id-1115 .tribe-events-c-search__input-control{position:relative!important}
            body.page-id-1115 .tribe-events-c-search__input{height:38px!important;border:1px solid #d7d7d7!important;border-radius:0!important;padding:8px 12px 8px 38px!important;font-size:14px!important;line-height:1.25!important;resize:none!important;-webkit-appearance:none!important;appearance:none!important;background:#fff!important}
            body.page-id-1115 .tribe-events-c-search__input-control-icon-svg{position:absolute!important;left:13px!important;top:50%!important;width:15px!important;height:15px!important;transform:translateY(-50%)!important;color:#666!important;pointer-events:none!important}
            body.page-id-1115 .tribe-events-c-search__button{height:38px!important;border:0!important;background:#111!important;color:#fff!important;padding:0 16px!important;font-weight:700!important}
            body.page-id-1115 .tribe-events-c-top-bar{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:18px!important;margin:0 0 24px!important}
            body.page-id-1115 .tribe-events-c-top-bar__datepicker-button{display:inline-flex!important;align-items:center!important;gap:8px!important;color:#111!important;background:transparent!important;border:0!important;font-size:21px!important;font-weight:600!important}
            body.page-id-1115 .tribe-events-calendar-list{display:block!important;margin:0!important;padding:0!important;list-style:none!important}
            body.page-id-1115 .tribe-events-calendar-list__month-separator{display:flex!important;align-items:center!important;gap:18px!important;margin:34px 0 22px!important}
            body.page-id-1115 .tribe-events-calendar-list__month-separator:after{content:"";height:1px;background:#d9d9d9;flex:1}
            body.page-id-1115 .tribe-events-calendar-list__month-separator h3{margin:0!important;font-size:14px!important;font-weight:500!important}
            body.page-id-1115 .tribe-events-calendar-list__event-row{display:grid!important;grid-template-columns:76px minmax(0,1fr)!important;gap:18px!important;margin:0 0 42px!important;padding:0!important}
            body.page-id-1115 .tribe-events-calendar-list__event-date-tag{width:auto!important;text-align:center!important}
            body.page-id-1115 .tribe-events-calendar-list__event-date-tag-weekday{display:block!important;color:#666!important;font-size:10px!important;font-weight:700!important;line-height:1.1!important;text-transform:uppercase!important}
            body.page-id-1115 .tribe-events-calendar-list__event-date-tag-daynum{display:block!important;color:#111!important;font-size:28px!important;font-weight:800!important;line-height:1.05!important}
            body.page-id-1115 .tribe-events-calendar-list__event{display:grid!important;grid-template-columns:minmax(0,1fr) 220px!important;gap:34px!important;align-items:start!important;margin:0!important}
            body.page-id-1115 .tribe-events-calendar-list__event-featured-image-wrapper{grid-column:2!important;grid-row:1!important;width:220px!important;margin:0!important}
            body.page-id-1115 .tribe-events-calendar-list__event-featured-image{display:block!important;width:220px!important;aspect-ratio:1/1!important;height:auto!important;object-fit:cover!important;border-radius:0!important}
            body.page-id-1115 .tribe-events-calendar-list__event-details{grid-column:1!important;grid-row:1!important;width:auto!important}
            body.page-id-1115 .tribe-events-calendar-list__event-title{margin:6px 0 10px!important;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif!important;font-size:19px!important;line-height:1.32!important;font-weight:600!important;letter-spacing:0!important}
            body.page-id-1115 .tribe-events-calendar-list__event-title-link{color:#111!important;text-decoration:none!important}
            body.page-id-1115 .tribe-events-calendar-month__calendar-event-title,
            body.page-id-1115 .tribe-events-calendar-day__event-title{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif!important;font-weight:600!important;letter-spacing:0!important}
            body.page-id-1115 .tribe-events-calendar-list__event-datetime-wrapper,
            body.page-id-1115 .tribe-events-calendar-list__event-venue{margin:0 0 10px!important;color:#111!important;font-size:13px!important;line-height:1.4!important}
            body.page-id-1115 .tribe-events-calendar-list__event-description{display:block!important;visibility:visible!important;color:#111!important;font-size:14px!important;line-height:1.6!important;max-width:720px!important}
            body.page-id-1115 .tribe-events-calendar-list__event-description p{margin:0!important}
            body.page-id-1115 a[href*="eventDisplay=past"],
            body.page-id-1115 .tribe-events-c-top-bar__nav-link--prev,
            body.page-id-1115 .tribe-events-c-nav__prev{display:none!important}
            @media(max-width:760px){
                body.page-id-1115 .elementor-1115>.elementor-section{padding:22px 16px 48px!important}
                body.page-id-1115 .tribe-events-calendar-list__event-row{grid-template-columns:52px minmax(0,1fr)!important;gap:12px!important}
                body.page-id-1115 .tribe-events-calendar-list__event{grid-template-columns:1fr!important;gap:14px!important}
                body.page-id-1115 .tribe-events-calendar-list__event-featured-image-wrapper,
                body.page-id-1115 .tribe-events-calendar-list__event-details{grid-column:1!important;grid-row:auto!important;width:100%!important}
                body.page-id-1115 .tribe-events-calendar-list__event-featured-image{width:100%!important;aspect-ratio:4/3!important}
                body.page-id-1115 .tribe-events-calendar-list__event-title{font-size:18px!important}
                body.page-id-1115 .tribe-events-header__events-bar,
                body.page-id-1115 .tribe-events-c-events-bar{display:none!important}
            }
        </style>
        <?php
    }

    public static function render_donate_page_first_paint_css(): void {
        if (!is_page('donate')) {
            return;
        }
        ?>
        <style id="ma-donate-first-paint-css" data-no-optimize="1" data-cfasync="false">
            body.page-id-3388 .elementor-element-96b776d{display:none!important}
            body.page-id-3388 .single-page-container{max-width:1240px}
            body.page-id-3388 .nv-page-title-wrap{margin:54px 0 28px!important}
            body.page-id-3388 .nv-page-title h1{margin:0!important;color:#111!important;font-family:Arial,Helvetica,sans-serif!important;font-size:clamp(30px,3.2vw,42px)!important;line-height:1.08!important;font-weight:700!important}
            body.page-id-3388 .elementor-3388{font-family:Arial,Helvetica,sans-serif;color:#111}
            body.page-id-3388 .elementor-element-e2f87cd>.elementor-container{display:grid!important;grid-template-columns:minmax(0,.95fr) minmax(360px,1.05fr)!important;gap:42px!important;align-items:start!important}
            body.page-id-3388 .elementor-element-e2f87cd .elementor-column{width:auto!important}
            body.page-id-3388 .elementor-element-5c67db9>.elementor-element-populated,
            body.page-id-3388 .elementor-element-de6b01a>.elementor-element-populated{padding:0!important}
            body.page-id-3388 .elementor-element-f4fe0be{display:none!important}
            body.page-id-3388 .elementor-element-2a32835 p{margin:0 0 18px!important;color:#202020!important;font-size:17px!important;line-height:1.62!important}
            body.page-id-3388 .elementor-element-802371a img{display:block!important;width:100%!important;aspect-ratio:4/3!important;object-fit:cover!important}
            body.page-id-3388 .elementor-element-36b9c4a,
            body.page-id-3388 .elementor-element-c5860aa{width:100%!important;max-width:420px!important;margin:16px auto 0!important}
            body.page-id-3388 .elementor-element-36b9c4a .elementor-widget-container,
            body.page-id-3388 .elementor-element-c5860aa .elementor-widget-container,
            body.page-id-3388 .elementor-element-c5860aa form{width:100%!important;margin:0!important}
            body.page-id-3388 .elementor-element-36b9c4a .givewp-donation-form-modal__open,
            body.page-id-3388 .elementor-element-36b9c4a .ma-donate-direct-link,
            body.page-id-3388 .elementor-element-c5860aa .elementor-button{display:flex!important;align-items:center!important;justify-content:center!important;width:100%!important;min-height:50px!important;margin:0 auto!important;border-radius:0!important;font-family:Arial,Helvetica,sans-serif!important;font-size:15px!important;font-weight:800!important;line-height:1.2!important;text-align:center!important;text-transform:none!important}
            body.page-id-3388 .elementor-element-36b9c4a .givewp-donation-form-modal__open,
            body.page-id-3388 .elementor-element-36b9c4a .ma-donate-direct-link{background:#111!important;color:#fff!important;text-decoration:none!important}
            body.page-id-3388 .elementor-element-36b9c4a .give-embed-form-wrapper,
            body.page-id-3388 .elementor-element-36b9c4a .iframe-loader,
            body.page-id-3388 .elementor-element-c5860aa,
            body.page-id-3388 .elementor-element-96b776d{display:none!important}
            body.page-id-3388 .elementor-element-c5860aa .elementor-button{background:#fff!important;color:#111!important;border:1px solid #111!important}
            body.page-id-3388 .elementor-element-52863ee h2{margin:0 0 22px!important;color:#111!important;font-size:clamp(24px,2.4vw,32px)!important;line-height:1.14!important;font-weight:700!important;text-align:left!important}
            body.page-id-3388 .give-donor-wall-shortcode-wrap .give-grid{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:18px!important;align-items:start!important}
            body.page-id-3388 .give-donor-wall-shortcode-wrap .give-donor-container{display:grid!important;grid-template-columns:46px minmax(0,1fr)!important;grid-template-areas:"avatar name" "amount amount" "comment comment"!important;gap:12px 14px!important;padding:20px!important}
            body.page-id-3388 .give-donor-wall-shortcode-wrap .give-donor-details{display:flex!important;align-items:baseline!important;justify-content:space-between!important;gap:12px!important;padding-top:12px!important;border-top:1px solid #ece7df!important;text-align:left!important}
            @media(max-width:920px){body.page-id-3388 .elementor-element-e2f87cd>.elementor-container{grid-template-columns:1fr!important;gap:28px!important}}
        </style>
        <?php
    }

    public static function render_donate_page_button_redirect(): void {
        if (!is_page('donate')) {
            return;
        }
        ?>
        <script id="ma-donate-button-direct-link" data-no-optimize="1" data-cfasync="false">
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.elementor-element-36b9c4a button.js-give-embed-form-modal-opener').forEach(function (button) {
                button.type = 'button';
                button.setAttribute('aria-label', 'Open the Ma\'s House donation form');
                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    window.location.href = '<?php echo esc_js(home_url('/donations/generalfund/')); ?>';
                }, true);
            });
        });
        </script>
        <?php
    }

    public static function render_single_event_rsvp_jump_button(): void {
        if (!is_singular('tribe_events')) {
            return;
        }
        ?>
        <style id="ma-event-rsvp-jump-css" data-no-optimize="1" data-cfasync="false">
            body.single-tribe_events .ma-event-rsvp-jump {
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                width: auto !important;
                min-height: 42px !important;
                margin: 16px 0 22px !important;
                padding: 11px 18px !important;
                border: 1px solid #111 !important;
                border-radius: 0 !important;
                background: #111 !important;
                color: #fff !important;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif !important;
                font-size: 14px !important;
                line-height: 1.2 !important;
                font-weight: 750 !important;
                letter-spacing: .02em !important;
                text-decoration: none !important;
                text-transform: none !important;
            }

            body.single-tribe_events .ma-event-rsvp-jump:hover,
            body.single-tribe_events .ma-event-rsvp-jump:focus {
                background: #fff !important;
                color: #111 !important;
                outline: 2px solid transparent !important;
                text-decoration: none !important;
            }

            body.single-tribe_events #ma-event-rsvp-target {
                scroll-margin-top: 128px;
            }

            body.single-tribe_events .tribe-tickets input,
            body.single-tribe_events .tribe-tickets textarea,
            body.single-tribe_events .tribe-tickets select,
            body.single-tribe_events .event-tickets input,
            body.single-tribe_events .event-tickets textarea,
            body.single-tribe_events .event-tickets select,
            body.single-tribe_events [class*="tribe-tickets"] input,
            body.single-tribe_events [class*="tribe-tickets"] textarea,
            body.single-tribe_events [class*="tribe-tickets"] select {
                resize: none !important;
            }

            body.single-tribe_events .tribe-tickets input::-webkit-resizer,
            body.single-tribe_events .tribe-tickets textarea::-webkit-resizer,
            body.single-tribe_events .event-tickets input::-webkit-resizer,
            body.single-tribe_events .event-tickets textarea::-webkit-resizer,
            body.single-tribe_events [class*="tribe-tickets"] input::-webkit-resizer,
            body.single-tribe_events [class*="tribe-tickets"] textarea::-webkit-resizer {
                display: none !important;
                width: 0 !important;
                height: 0 !important;
            }

            @media (max-width: 760px) {
                body.single-tribe_events .ma-event-rsvp-jump {
                    width: 100% !important;
                    margin: 14px 0 18px !important;
                }

                body.single-tribe_events #ma-event-rsvp-target {
                    scroll-margin-top: 104px;
                }
            }
        </style>
        <script id="ma-event-rsvp-jump-js" data-no-optimize="1" data-cfasync="false">
        (function () {
            var targetSelectors = [
                '.tribe-tickets__rsvp',
                '.tribe-tickets__tickets-form',
                '.tribe-tickets__tickets',
                '.tribe-tickets',
                '#tribe-tickets',
                '.event-tickets',
                '[class*="tribe-tickets"]'
            ];
            var anchorSelectors = [
                '.tribe-events-schedule',
                '.tribe-events-single-event-title',
                'h1.entry-title',
                '.entry-title'
            ];

            function visibleElement(selectors) {
                for (var i = 0; i < selectors.length; i += 1) {
                    var elements = document.querySelectorAll(selectors[i]);
                    for (var j = 0; j < elements.length; j += 1) {
                        var element = elements[j];
                        var rect = element.getBoundingClientRect();
                        if (rect.width > 0 && rect.height > 0) {
                            return element;
                        }
                    }
                }
                return null;
            }

            function insertButton() {
                if (document.querySelector('.ma-event-rsvp-jump')) {
                    return true;
                }

                var target = visibleElement(targetSelectors);
                if (!target) {
                    return false;
                }

                target.id = 'ma-event-rsvp-target';

                var anchor = visibleElement(anchorSelectors);
                if (!anchor) {
                    return false;
                }

                var button = document.createElement('a');
                button.className = 'ma-event-rsvp-jump';
                button.href = '#ma-event-rsvp-target';
                button.textContent = 'RSVP';
                button.setAttribute('aria-label', 'Jump to the RSVP section for this event');
                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    setTimeout(function () {
                        var focusable = target.querySelector('button, a, input, select, textarea, [tabindex]:not([tabindex="-1"])');
                        if (focusable) {
                            focusable.focus({ preventScroll: true });
                        }
                    }, 450);
                });

                if (anchor.classList && anchor.classList.contains('tribe-events-schedule')) {
                    anchor.parentNode.insertBefore(button, anchor.nextSibling);
                } else {
                    anchor.insertAdjacentElement('afterend', button);
                }

                return true;
            }

            function boot() {
                if (insertButton()) {
                    return;
                }

                var attempts = 0;
                var timer = window.setInterval(function () {
                    attempts += 1;
                    if (insertButton() || attempts > 20) {
                        window.clearInterval(timer);
                    }
                }, 300);

                if ('MutationObserver' in window) {
                    var observer = new MutationObserver(function () {
                        if (insertButton()) {
                            observer.disconnect();
                        }
                    });
                    observer.observe(document.body, { childList: true, subtree: true });
                    window.setTimeout(function () { observer.disconnect(); }, 8000);
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', boot, { once: true });
            } else {
                boot();
            }
        }());
        </script>
        <?php
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
        $schedules['ma_artwork_every_six_hours'] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display' => 'Every 6 hours',
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
            if (!$dry_run && ($force_all || !get_transient(self::ARTIST_FIELD_SETUP_CACHE_KEY))) {
                self::ensure_artist_portrait_airtable_fields($options);
                set_transient(self::ARTIST_FIELD_SETUP_CACHE_KEY, 1, WEEK_IN_SECONDS);
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

        $artist_directory = [];
        if (!$dry_run && self::should_refresh_artist_directory($options, $force_all)) {
            $artist_directory = self::sync_community_artist_records(180);
            $options = self::options();
            $options['last_artist_directory_sync_at'] = $started_at;
            update_option(self::OPTION_KEY, $options, false);
        }

        $summary = [
            'dry_run' => $dry_run,
            'checked' => $checked,
            'updated' => $updated,
            'skipped' => array_slice(array_values(array_unique($skipped)), 0, 12),
            'errors' => $errors,
            'last_sync_at' => $started_at,
            'artist_directory' => $artist_directory,
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
            'Portrait Jpeg' => ['type' => 'multipleAttachments'],
            'Artist Portrait Public URL' => ['type' => 'url'],
            'Mediums' => [
                'type' => 'multipleSelects',
                'options' => ['choices' => array_map(static fn($name) => ['name' => $name], self::artist_medium_choices())],
            ],
            'Artist Roles' => [
                'type' => 'multipleSelects',
                'options' => ['choices' => array_map(static fn($name) => ['name' => $name], self::artist_role_choices())],
            ],
            'Public Website' => ['type' => 'url'],
            'Social Media URL' => ['type' => 'url'],
            'Based In' => ['type' => 'singleLineText'],
            'Residency Dates' => ['type' => 'singleLineText'],
            'Exhibits Involved' => ['type' => 'multilineText'],
            'Events Led' => ['type' => 'multilineText'],
            'Donated Works' => ['type' => 'multilineText'],
            'Exhibited Works' => ['type' => 'multilineText'],
            'Public Profile URL' => ['type' => 'url'],
        ];
        foreach ($wanted as $name => $config) {
            if (in_array($name, $field_names, true)) {
                continue;
            }
            self::create_airtable_field($options, $table_id, $name, $config['type'], $config['options'] ?? []);
        }
    }

    private static function create_airtable_field(array $options, string $table_id, string $name, string $type, array $field_options = []): void {
        $endpoint = sprintf(
            'https://api.airtable.com/v0/meta/bases/%s/tables/%s/fields',
            rawurlencode($options['base_id']),
            rawurlencode($table_id)
        );
        $payload = ['name' => $name, 'type' => $type];
        if ($field_options) {
            $payload['options'] = $field_options;
        }
        wp_remote_post($endpoint, [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $options['airtable_token'], 'Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
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
            'mediums' => '',
            'roles' => '',
            'location' => '',
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
                $artist['mediums'] = self::text(self::first_field_value($artist_fields, self::artist_field_aliases('mediums')));
                $artist['roles'] = self::text(self::first_field_value($artist_fields, self::artist_field_aliases('roles')));
                $artist['location'] = self::text(self::first_field_value($artist_fields, self::artist_field_aliases('location')));
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
        if (!$artist['location']) {
            $artist['location'] = self::infer_artist_location($artist['bio']);
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
        return self::bio_from_artist_post_id($post_id);
    }

    private static function bio_from_artist_post_id(int $post_id): string {
        if ($post_id <= 0 || get_post_type($post_id) !== 'post') {
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

    private static function should_refresh_artist_directory(array $options, bool $force_all): bool {
        if ($force_all) {
            return true;
        }

        $last = strtotime((string) ($options['last_artist_directory_sync_at'] ?? ''));
        return !$last || (time() - $last) >= DAY_IN_SECONDS;
    }

    public static function sync_community_artist_records(int $limit = 80): array {
        $options = self::options();
        if (empty($options['airtable_token']) || empty($options['base_id']) || empty($options['artist_table_id'])) {
            return ['checked' => 0, 'updated' => 0, 'errors' => ['Missing Airtable Artists settings.']];
        }
        self::ensure_artist_portrait_airtable_fields($options);
        $available_fields = self::airtable_table_field_names($options, (string) $options['artist_table_id']);
        $artists = array_slice(self::community_artist_cards_data(), 0, max(1, $limit));
        $checked = 0;
        $updated = 0;
        $errors = [];

        foreach ($artists as $artist) {
            $checked++;
            try {
                $post_id = (int) ($artist['post_id'] ?? 0);
                $bio = self::text($artist['bio'] ?? '');
                $record_id = $post_id ? self::text(get_post_meta($post_id, 'ma_artist_airtable_record_id', true)) : '';
                if (!$record_id) {
                    $record_id = self::find_or_create_artist_record($options, $artist['name'], $bio);
                }
                if (!$record_id) {
                    continue;
                }
                if (!empty($artist['is_resident_source'])) {
                    $profile_artist = [
                        'name' => $artist['name'],
                        'bio' => $bio,
                        'portrait_url' => $artist['image'],
                        'record_id' => $record_id,
                        'residency_period' => $artist['residency_period'] ?? '',
                        'website' => $artist['website'] ?? '',
                        'instagram' => $artist['social'] ?? '',
                        'mediums' => implode(', ', $artist['mediums']),
                        'roles' => implode(', ', $artist['roles']),
                        'location' => $artist['location'] ?? '',
                    ];
                    $profile_id = self::ensure_artist_profile_post($profile_artist, []);
                    if ($profile_id) {
                        $post_id = $profile_id;
                        $artist['url'] = get_permalink($profile_id) ?: $artist['url'];
                    }
                }
                $events = self::artist_event_labels($artist['name']);
                $product_context = self::artist_product_context($artist['name']);
                $payload = [
                    'Bio' => $bio,
                    'Mediums' => $artist['mediums'],
                    'Artist Roles' => $artist['roles'],
                    'Public Website' => $artist['website'],
                    'Social Media URL' => $artist['social'],
                    'Based In' => $artist['location'] ?? '',
                    'Donated Works' => implode("\n", $product_context['works'] ?? []),
                    'Events Led' => implode("\n", $events),
                    'Public Profile URL' => $artist['url'],
                ];
                if ($post_id) {
                    $residency = self::text($artist['residency_period'] ?? '') ?: self::text(get_post_meta($post_id, 'ma_artist_residency_period', true));
                    if ($residency) {
                        $payload['Residency Dates'] = $residency;
                    }
                    update_post_meta($post_id, 'ma_artist_airtable_record_id', $record_id);
                    update_post_meta($post_id, 'ma_artist_mediums', implode(', ', $artist['mediums']));
                    update_post_meta($post_id, 'ma_artist_roles', implode(', ', $artist['roles']));
                    update_post_meta($post_id, 'ma_artist_website', esc_url_raw($artist['website']));
                    update_post_meta($post_id, 'ma_artist_instagram', esc_url_raw($artist['social']));
                    update_post_meta($post_id, 'ma_artist_location', self::text($artist['location'] ?? ''));
                }
                if (self::patch_artist_directory_fields($options, $record_id, $payload, $available_fields)) {
                    $updated++;
                }
            } catch (Throwable $error) {
                $errors[] = $artist['name'] . ': ' . $error->getMessage();
            }
        }

        return ['checked' => $checked, 'updated' => $updated, 'errors' => array_slice($errors, 0, 8)];
    }

    public static function sync_exhibiting_artist_profiles(int $limit = 0, array $target_exhibits = [], bool $shinnecock_only = false): array {
        $options = self::options();
        if (empty($options['airtable_token']) || empty($options['base_id']) || empty($options['table_id']) || empty($options['artist_table_id'])) {
            return ['checked' => 0, 'updated' => 0, 'created' => 0, 'errors' => ['Missing Airtable Artwork Inventory or Artists settings.']];
        }
        self::ensure_artist_portrait_airtable_fields($options);
        $available_fields = self::airtable_table_field_names($options, (string) $options['artist_table_id']);
        $records = self::list_all_airtable_artwork_records($options, $limit);
        $artists = [];
        $errors = [];

        foreach ($records as $record) {
            $fields = is_array($record['fields'] ?? null) ? $record['fields'] : [];
            $exhibit_names = self::artwork_exhibit_names($fields, $options);
            if (!$fields || !$exhibit_names) {
                continue;
            }
            if ($target_exhibits && !self::artist_exhibit_matches($exhibit_names, $target_exhibits)) {
                continue;
            }
            try {
                $description = self::field($fields, $options, 'description');
                $artist = self::artist_details_for_record($fields, $options, true, self::text($record['id'] ?? ''), $description);
                $name = self::text($artist['name'] ?? '');
                if (!$name) {
                    continue;
                }
                $bio = self::text($artist['bio'] ?? '');
                $is_target_shinnecock_show = self::artist_exhibit_matches($exhibit_names, ['Shinnecock Speaks', 'Resilient Roots']);
                if ($shinnecock_only && !$is_target_shinnecock_show && !self::is_shinnecock_artist_signal($name, $bio)) {
                    continue;
                }
                $key = strtolower(remove_accents(trim($name)));
                if (!isset($artists[$key])) {
                    $roles = self::split_list(self::text($artist['roles'] ?? ''));
                    $roles[] = 'Exhibiting Artist';
                    if ($is_target_shinnecock_show || self::is_shinnecock_artist_signal($name, $bio)) {
                        $roles[] = 'Shinnecock Artist';
                        $roles[] = 'Community Artist';
                    }
                    $artists[$key] = array_merge($artist, [
                        'name' => $name,
                        'roles' => implode(', ', self::valid_artist_roles($roles)),
                        'mediums' => self::text($artist['mediums'] ?? ''),
                        'exhibited_works' => [],
                        'exhibits_involved' => [],
                    ]);
                }
                $artists[$key]['exhibits_involved'] = array_values(array_unique(array_merge($artists[$key]['exhibits_involved'], $exhibit_names)));
                $artists[$key]['exhibited_works'][] = self::compact_exhibited_work_from_fields($fields, $options, $exhibit_names);
                $mediums = array_merge(
                    self::split_list(self::text($artists[$key]['mediums'] ?? '')),
                    self::infer_artist_mediums(self::field($fields, $options, 'medium') . ' ' . self::text($artist['bio'] ?? ''))
                );
                $artists[$key]['mediums'] = implode(', ', self::correct_artist_mediums($name, array_values(array_unique($mediums))));
            } catch (Throwable $error) {
                $errors[] = self::text($record['id'] ?? 'Artwork record') . ': ' . $error->getMessage();
            }
        }

        $checked = 0;
        $updated = 0;
        $created = 0;
        foreach ($artists as $artist) {
            $checked++;
            try {
                $before = self::find_artist_profile_post($artist);
                $linked_exhibits = array_map(static function ($title): array {
                    return ['title' => $title];
                }, $artist['exhibits_involved']);
                $post_id = self::ensure_artist_profile_post($artist, $linked_exhibits);
                if (!$post_id) {
                    continue;
                }
                update_post_meta($post_id, 'ma_artist_exhibited_works_json', wp_json_encode(array_values($artist['exhibited_works'])));
                update_post_meta($post_id, 'ma_artist_exhibits_involved', implode("\n", $artist['exhibits_involved']));
                if (!$before) {
                    $created++;
                }
                $updated++;

                $record_id = self::text($artist['record_id'] ?? '');
                if (!$record_id) {
                    $record_id = self::find_or_create_artist_record($options, $artist['name'], self::text($artist['bio'] ?? ''));
                    if ($record_id) {
                        update_post_meta($post_id, 'ma_artist_airtable_record_id', $record_id);
                    }
                }
                if ($record_id) {
                    self::patch_artist_directory_fields($options, $record_id, [
                        'Bio' => self::text($artist['bio'] ?? ''),
                        'Mediums' => self::split_list(self::text($artist['mediums'] ?? '')),
                        'Artist Roles' => self::split_list(self::text($artist['roles'] ?? '')),
                        'Public Website' => self::text($artist['website'] ?? ''),
                        'Social Media URL' => self::artist_social_url(self::text($artist['instagram'] ?? '')),
                        'Based In' => self::artist_location_region(self::text($artist['location'] ?? '')),
                        'Exhibits Involved' => implode("\n", $artist['exhibits_involved']),
                        'Exhibited Works' => self::exhibited_works_text($artist['exhibited_works']),
                        'Public Profile URL' => get_permalink($post_id) ?: '',
                    ], $available_fields);
                }
            } catch (Throwable $error) {
                $errors[] = self::text($artist['name'] ?? 'Artist') . ': ' . $error->getMessage();
            }
        }

        return ['checked' => $checked, 'updated' => $updated, 'created' => $created, 'errors' => array_slice($errors, 0, 12)];
    }

    private static function artist_exhibit_matches(array $exhibit_names, array $needles): bool {
        $haystack = strtolower(remove_accents(implode(' ', array_map([__CLASS__, 'text'], $exhibit_names))));
        foreach ($needles as $needle) {
            $needle = strtolower(remove_accents(self::text($needle)));
            if ($needle && strpos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function list_all_airtable_artwork_records(array $options, int $limit = 0): array {
        $records = [];
        $offset = '';
        do {
            $query = ['pageSize' => '100'];
            if ($offset) {
                $query['offset'] = $offset;
            }
            $url = sprintf(
                'https://api.airtable.com/v0/%s/%s?%s',
                rawurlencode($options['base_id']),
                rawurlencode($options['table_id']),
                http_build_query($query)
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
                throw new RuntimeException("Airtable artwork list failed ({$code}): {$body}");
            }
            $json = json_decode($body, true);
            $records = array_merge($records, $json['records'] ?? []);
            if ($limit > 0 && count($records) >= $limit) {
                return array_slice($records, 0, $limit);
            }
            $offset = $json['offset'] ?? '';
        } while ($offset);
        return $records;
    }

    private static function artwork_exhibit_names(array $fields, array $options): array {
        $values = [];
        foreach (['exhibit_records', 'exhibit_title'] as $key) {
            $raw = self::field_raw($fields, $options, $key);
            $values = array_merge($values, self::split_field_values($raw));
        }
        $values = array_merge(
            $values,
            self::split_field_values($fields['Exhibitions'] ?? ''),
            self::split_field_values($fields['Exhibitions without quotes for extensions'] ?? ''),
            self::split_field_values($fields['Exhibition Description'] ?? '')
        );
        $clean = [];
        foreach ($values as $value) {
            $value = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $value)));
            if ($value !== '') {
                $clean[$value] = $value;
            }
        }
        return array_values($clean);
    }

    private static function split_field_values($value): array {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    $out[] = self::text($item['name'] ?? $item['title'] ?? $item['id'] ?? '');
                } else {
                    $out[] = self::text($item);
                }
            }
            return array_values(array_filter($out));
        }
        $text = self::text($value);
        return $text === '' ? [] : [$text];
    }

    private static function compact_exhibited_work_from_fields(array $fields, array $options, array $exhibit_names): array {
        return [
            'title' => self::field($fields, $options, 'title'),
            'year' => self::field($fields, $options, 'year'),
            'medium' => self::field($fields, $options, 'medium'),
            'dimensions' => self::field($fields, $options, 'dimensions'),
            'inventory' => self::field($fields, $options, 'inventory_number'),
            'availability' => self::field($fields, $options, 'availability'),
            'exhibits' => array_values($exhibit_names),
        ];
    }

    private static function exhibited_works_text(array $works): string {
        $lines = [];
        foreach ($works as $work) {
            $title = self::text($work['title'] ?? '');
            if (!$title) {
                continue;
            }
            $details = array_filter([
                self::text($work['year'] ?? ''),
                self::text($work['medium'] ?? ''),
                self::text($work['dimensions'] ?? ''),
                self::text($work['inventory'] ?? '') ? 'Inventory ' . self::text($work['inventory'] ?? '') : '',
            ]);
            $exhibits = array_filter(array_map([__CLASS__, 'text'], $work['exhibits'] ?? []));
            $lines[] = $title . ($details ? ' (' . implode(', ', $details) . ')' : '') . ($exhibits ? ' - ' . implode('; ', $exhibits) : '');
        }
        return implode("\n", $lines);
    }

    private static function patch_artist_directory_fields(array $options, string $record_id, array $payload, array $available_fields): bool {
        $fields = [];
        foreach ($payload as $field => $value) {
            if (!in_array($field, $available_fields, true)) {
                continue;
            }
            if (is_array($value)) {
                $value = array_values(array_unique(array_filter(array_map([__CLASS__, 'text'], $value))));
            } else {
                $value = self::text($value);
            }
            if ($value !== '' && $value !== []) {
                $fields[$field] = $value;
            }
        }
        if (!$fields) {
            return false;
        }
        $endpoint = sprintf(
            'https://api.airtable.com/v0/%s/%s/%s',
            rawurlencode($options['base_id']),
            rawurlencode($options['artist_table_id']),
            rawurlencode($record_id)
        );
        $response = wp_remote_request($endpoint, [
            'method' => 'PATCH',
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $options['airtable_token'], 'Content-Type' => 'application/json'],
            'body' => wp_json_encode(['fields' => $fields, 'typecast' => true]),
        ]);
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) < 300;
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
            'instagram' => ['Instagram Username (with @)', 'Instagram', 'Instagram Username', 'Social Media URL'],
            'mediums' => ['Mediums', 'Medium', 'Practice', 'Artist Mediums'],
            'roles' => ['Artist Roles', 'Roles', 'Artist Type', 'Artist Categories'],
            'location' => ['Based In', 'Artist Location', 'Location', 'Based Location', 'City', 'City/State'],
        ];
        return $aliases[$key] ?? [];
    }

    private static function artist_medium_choices(): array {
        return [
            'Painter',
            'Illustrator',
            'Photographer',
            'Printmaker',
            'Muralist',
            'Beadworker',
            'Weaver',
            'Textile Artist',
            'Sculptor',
            'Ceramic Artist',
            'Installation Artist',
            'Performance Artist',
            'Comedian',
            'Writer',
            'Filmmaker',
            'Sound Artist',
            'Digital Artist',
            'Curator',
            'Educator',
            'Designer',
            'Multidisciplinary Artist',
            'Traditional Artist',
            'Craft Artist',
        ];
    }

    private static function artist_role_choices(): array {
        return [
            'Community Artist',
            'Shinnecock Artist',
            'Exhibiting Artist',
            'Residency Artist',
            'Guest Workshop Artist',
        ];
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
        update_post_meta((int) $post_id, 'ma_artist_mediums', self::text($artist['mediums'] ?? ''));
        update_post_meta((int) $post_id, 'ma_artist_roles', self::text($artist['roles'] ?? ''));
        update_post_meta((int) $post_id, 'ma_artist_website', esc_url_raw($artist['website'] ?? ''));
        update_post_meta((int) $post_id, 'ma_artist_instagram', self::text($artist['instagram'] ?? ''));
        update_post_meta((int) $post_id, 'ma_artist_residency_period', self::text($artist['residency_period'] ?? ''));
        update_post_meta((int) $post_id, 'ma_artist_location', self::text($artist['location'] ?? ''));
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
        if ($existing instanceof WP_Post && self::is_artist_profile_post((int) $existing->ID, $name)) {
            return (int) $existing->ID;
        }
        return 0;
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
        $location = self::text($artist['location'] ?? '');
        $mediums = self::split_list(self::text($artist['mediums'] ?? ''));
        $roles = self::split_list(self::text($artist['roles'] ?? ''));
        $exhibits = self::artist_exhibit_labels($linked_exhibits);
        $exhibited_works = is_array($artist['exhibited_works'] ?? null) ? $artist['exhibited_works'] : [];
        $parts = ['<div class="ma-artist-page">'];
        if ($name) {
            $parts[] = '<header class="ma-artist-page__heading"><h1>' . esc_html($name) . '</h1></header>';
        }
        $parts[] = self::artist_page_back_and_tags_html($roles, $mediums);

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
        if ($roles) {
            $facts .= '<li><strong>Artist roles:</strong> ' . esc_html(implode(', ', $roles)) . '</li>';
        }
        if ($mediums) {
            $facts .= '<li><strong>Practice:</strong> ' . esc_html(implode(', ', $mediums)) . '</li>';
        }
        if ($location) {
            $facts .= '<li><strong>Based in:</strong> ' . esc_html($location) . '</li>';
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

        if ($exhibited_works) {
            $items = '';
            foreach ($exhibited_works as $work) {
                $title = self::text($work['title'] ?? '');
                if (!$title) {
                    continue;
                }
                $meta = array_filter([
                    self::text($work['year'] ?? ''),
                    self::text($work['medium'] ?? ''),
                    self::text($work['dimensions'] ?? ''),
                    self::text($work['inventory'] ?? '') ? 'Inventory ' . self::text($work['inventory'] ?? '') : '',
                ]);
                $work_exhibits = array_filter(array_map([__CLASS__, 'text'], $work['exhibits'] ?? []));
                $items .= '<li><strong>' . esc_html($title) . '</strong>';
                if ($meta) {
                    $items .= '<span>' . esc_html(implode(', ', $meta)) . '</span>';
                }
                if ($work_exhibits) {
                    $items .= '<em>Included in ' . esc_html(implode('; ', $work_exhibits)) . '</em>';
                }
                $items .= '</li>';
            }
            if ($items) {
                $parts[] = '<section class="ma-artist-page__exhibited-works"><h2>Exhibited works at Ma\'s House</h2><ul>' . $items . '</ul></section>';
            }
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

    private static function artist_page_back_and_tags_html(array $roles, array $mediums): string {
        $roles = self::valid_artist_roles($roles);
        $mediums = self::correct_artist_mediums('', $mediums);
        $back_url = self::artist_directory_back_url();
        $tags = [];
        foreach ($roles as $role) {
            $tags[] = '<a href="' . esc_url(add_query_arg(['role' => sanitize_title($role)], home_url('/community-artists/'))) . '">' . esc_html($role) . '</a>';
        }
        foreach ($mediums as $medium) {
            $tags[] = '<a href="' . esc_url(add_query_arg(['medium' => sanitize_title($medium)], home_url('/community-artists/'))) . '">' . esc_html($medium) . '</a>';
        }
        if (!$tags && !$back_url) {
            return '';
        }
        return '<nav class="ma-artist-page__nav" aria-label="Artist directory navigation"><a class="ma-artist-page__back" href="' . esc_url($back_url) . '">Back to Community Artists</a>' . ($tags ? '<div class="ma-artist-page__tags">' . implode('', $tags) . '</div>' : '') . '</nav>';
    }

    private static function artist_directory_back_url(): string {
        $role = sanitize_title(self::text($_GET['role'] ?? ''));
        $medium = sanitize_title(self::text($_GET['medium'] ?? ''));
        if (!$role && !$medium) {
            $referrer = wp_get_referer();
            $ref_path = $referrer ? trim((string) wp_parse_url($referrer, PHP_URL_PATH), '/') : '';
            if ($ref_path === 'community-artists') {
                return esc_url_raw($referrer);
            }
        }
        return add_query_arg(array_filter(['role' => $role, 'medium' => $medium]), home_url('/community-artists/'));
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
        if (!self::is_artwork_product($product)) {
            return;
        }
        $price = (float) $product->get_price();
        if ($price <= 0 || !$product->is_purchasable()) {
            return;
        }
        echo '<p class="ma-product-purchase-note">By purchasing artwork, you are supporting a reciprocal fundraising model: artists are paid for their work, and Ma&rsquo;s House receives support for the programs, residencies, exhibitions, and community gatherings that keep our space active and accessible.</p>';
    }

    private static function single_product_artwork_panel_html(WC_Product $product): string {
        if (!self::is_artwork_product($product)) {
            return '';
        }
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
        $product = wc_get_product((int) get_queried_object_id());
        if (!$product instanceof WC_Product) {
            return $content;
        }
        if (!self::is_artwork_product($product)) {
            return $content;
        }
        $artist_html = self::artist_product_section(self::artist_profile_data_for_product($product));
        if ($artist_html) {
            if (strpos($content, 'ma-artist-profile') !== false) {
                $content = preg_replace('/<section class="ma-artist-profile"[^>]*>.*?<\/section>/is', $artist_html, $content, 1) ?: $content;
            } else {
                $content .= $artist_html;
            }
        }
        if (strpos($content, 'ma-product-exhibit-section') !== false) {
            return $content;
        }
        $exhibit_html = self::product_exhibit_body_section_html($product);
        if (!$exhibit_html) {
            return strpos($content, 'ma-contextual-related-products') === false
                ? $content . self::contextual_related_products_section($product)
                : $content;
        }
        $artist_pos = strpos($content, '<section class="ma-artist-profile');
        if ($artist_pos !== false) {
            $content = substr($content, 0, $artist_pos) . $exhibit_html . substr($content, $artist_pos);
        } else {
            $content .= $exhibit_html;
        }
        if (strpos($content, 'ma-contextual-related-products') === false) {
            $content .= self::contextual_related_products_section($product);
        }
        return $content;
    }

    public static function replace_sponsorship_page_content(string $content): string {
        if (is_admin() || !is_page('sponsorship')) {
            return $content;
        }
        $post_id = (int) get_the_ID();
        if ($post_id && $post_id !== 8211) {
            return $content;
        }

        return self::sponsorship_page_html();
    }

    public static function replace_about_page_content(string $content): string {
        if (is_admin() || !is_page('about')) {
            return $content;
        }
        $post_id = (int) get_the_ID();
        if ($post_id && $post_id !== 767) {
            return $content;
        }

        return self::about_page_html();
    }

    private static function about_page_html(): string {
        $hero = 'https://www.mashouse.studio/wp-content/uploads/2021/07/roger-waters-20201110_145009-e1636136206168-1024x553.jpg';
        $staff = [
            ['name' => 'Jeremy Dennis', 'role' => 'Founder & Board Member'],
            ['name' => 'Denise Silva-Dennis', 'role' => 'Workshop Coordinator'],
            ['name' => 'Avery Dennis', 'role' => 'Facilities Director'],
            ['name' => 'Brianna L. Hernández', 'role' => 'Director of Curation'],
        ];
        $board = [
            ['name' => 'Kelly Dennis', 'role' => 'Board President'],
            ['name' => 'Jeremy Dennis', 'role' => 'Founder & Board Member'],
            ['name' => 'Darlene Troge', 'role' => 'Treasurer'],
            ['name' => 'Brianna L. Hernández', 'role' => 'Secretary'],
            ['name' => 'Debbie Rechler', 'role' => 'Board Member'],
            ['name' => 'Stephanie Joyce', 'role' => 'Board Member'],
            ['name' => 'Maureen McMahon', 'role' => 'Board Member'],
            ['name' => 'Danielle Hopson Begun', 'role' => 'Board Member'],
            ['name' => 'Christian Weaver', 'role' => 'Board Member'],
        ];

        ob_start();
        ?>
        <article class="ma-about-page">
            <section class="ma-about-hero">
                <figure>
                    <img src="<?php echo esc_url($hero); ?>" alt="Ma's House with friends and family" loading="eager">
                    <figcaption>Photo of Ma's House with friends and family with Shinnecock founding artist Jeremy Dennis.</figcaption>
                </figure>
                <div class="ma-about-hero__copy">
                    <p>About Ma's House</p>
                    <h1>A communal art space on the Shinnecock Indian Reservation.</h1>
                </div>
            </section>

            <section class="ma-about-intro">
                <div class="ma-about-intro__lead">
                    <h2>Ma's House &amp; BIPOC Art Studio Inc.</h2>
                    <p>Ma's House is a nonprofit communal art space based in Southampton, New York, supporting Black, Indigenous, and People of Color artists through residencies, exhibitions, workshops, a library, and community programs.</p>
                </div>
                <div class="ma-about-intro__body">
                    <p>The family house, built in the 1960s, has been restored as a safe and generative place for artists to create work, participate in residencies, and exhibit contemporary work on the Shinnecock Indian Reservation. Ma's House &amp; BIPOC Art Studio Inc. was chartered in 2021 as a 501(c)3 tax-exempt organization in New York State.</p>
                    <p>Since June 2020, founder Jeremy Dennis has worked with family, artists, board members, volunteers, and community partners to restore the Silva family home he grew up in. The project grew from the disruptions of the COVID-19 pandemic and from the need for spaces grounded in creativity, healing, imagining, and liberation for BIPOC artists and communities.</p>
                </div>
            </section>

            <section class="ma-about-programs ma-about-programs--impact" aria-label="Recognition and impact">
                <article>
                    <h3>Ruth Arts Core Grant</h3>
                    <p>Ma's House was selected for the Ruth Foundation for the Arts 2025-2028 Core Grants cohort, receiving $150,000 in unrestricted support over three years.</p>
                </article>
                <article>
                    <h3>Shinnecock Speaks</h3>
                    <p>The Museum Association of New York recognized Ma's House for Shinnecock Speaks, an exhibition and public program series featuring 27 contemporary Shinnecock artists.</p>
                </article>
                <article>
                    <h3>Residency Network</h3>
                    <p>Since 2021, Ma's House has hosted a growing alumni network of BIPOC artists working across visual art, writing, performance, research, sound, film, and interdisciplinary practice.</p>
                </article>
            </section>

            <section class="ma-about-programs" aria-label="What Ma's House supports">
                <article>
                    <h3>Residency</h3>
                    <p>Time, space, and community support for BIPOC artists developing new work.</p>
                </article>
                <article>
                    <h3>Exhibitions</h3>
                    <p>Contemporary art shows and public programs rooted in Indigenous presence and local history.</p>
                </article>
                <article>
                    <h3>Community</h3>
                    <p>Workshops, gatherings, a library, and programs for Shinnecock community members and the broader public.</p>
                </article>
            </section>

            <section class="ma-about-people">
                <div class="ma-about-people__group">
                    <h2>Staff</h2>
                    <div class="ma-about-people__list">
                        <?php foreach ($staff as $person) : ?>
                            <article>
                                <h3><?php echo esc_html($person['name']); ?></h3>
                                <p><?php echo esc_html($person['role']); ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="ma-about-people__group">
                    <h2>Board of Trustees</h2>
                    <div class="ma-about-people__list">
                        <?php foreach ($board as $person) : ?>
                            <article>
                                <h3><?php echo esc_html($person['name']); ?></h3>
                                <p><?php echo esc_html($person['role']); ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="ma-about-people__group ma-about-people__group--small">
                    <h2>Emeritus Board Members</h2>
                    <div class="ma-about-people__list">
                        <article>
                            <h3>James King</h3>
                            <p>Emeritus Board Member</p>
                        </article>
                    </div>
                </div>
            </section>
        </article>
        <?php
        return (string) ob_get_clean();
    }

    public static function replace_news_page_content(string $content): string {
        if (is_admin() || !is_page('news')) {
            return $content;
        }
        $post_id = (int) get_the_ID();
        if ($post_id && $post_id !== 32) {
            return $content;
        }

        return self::news_page_html();
    }

    public static function replace_subscribe_page_content(string $content): string {
        if (is_admin() || !is_page('subscribe')) {
            return $content;
        }
        $post_id = (int) get_the_ID();
        if ($post_id && $post_id !== 3374) {
            return $content;
        }

        return self::subscribe_page_html();
    }

    public static function replace_podcast_page_content(string $content): string {
        if (is_admin() || !is_page('podcast')) {
            return $content;
        }
        $post_id = (int) get_the_ID();
        if ($post_id && $post_id !== 2652) {
            return $content;
        }

        return self::podcast_page_html();
    }

    public static function replace_residency_page_content(string $content): string {
        if (is_admin() || !is_page('residency')) {
            return $content;
        }
        $post_id = (int) get_the_ID();
        if ($post_id && $post_id !== 198) {
            return $content;
        }

        return self::residency_page_html();
    }

    public static function replace_community_artists_page_content(string $content): string {
        if (is_admin() || !is_page('community-artists')) {
            return $content;
        }

        return self::community_artists_page_html();
    }

    public static function prepend_single_post_content_header(string $content): string {
        if (is_admin() || !is_singular('post') || self::is_current_artist_profile_post()) {
            return $content;
        }
        if (strpos($content, 'ma-single-post-content-header') !== false) {
            return $content;
        }
        $post = get_queried_object();
        if (!($post instanceof WP_Post)) {
            return $content;
        }
        $title = self::text(get_the_title($post));
        if (!$title) {
            return $content;
        }
        $image = (has_post_thumbnail((int) $post->ID) && !self::content_already_contains_featured_image($content, (int) $post->ID))
            ? get_the_post_thumbnail((int) $post->ID, 'large', [
                'class' => 'ma-single-post-featured-image',
                'loading' => 'eager',
                'decoding' => 'async',
            ])
            : '';
        $html = '<header class="ma-single-post-content-header">';
        $html .= '<h1>' . esc_html($title) . '</h1>';
        $html .= '<p>' . esc_html(get_the_date('F j, Y', (int) $post->ID)) . '</p>';
        if ($image) {
            $html .= '<figure>' . $image . '</figure>';
        }
        $html .= '</header>';
        return $html . $content;
    }

    public static function prepend_artist_post_content_header(string $content): string {
        if (is_admin() || !is_singular('post') || !self::is_current_artist_profile_post()) {
            return $content;
        }
        if (strpos($content, 'ma-artist-content-header') !== false || strpos($content, 'ma-artist-page__heading') !== false) {
            return $content;
        }
        $post = get_queried_object();
        if (!($post instanceof WP_Post)) {
            return $content;
        }
        $title = self::text(get_the_title($post));
        if (!$title) {
            return $content;
        }
        $roles = self::split_list(self::text(get_post_meta((int) $post->ID, 'ma_artist_roles', true)));
        $mediums = self::split_list(self::text(get_post_meta((int) $post->ID, 'ma_artist_mediums', true)));
        $image = (has_post_thumbnail((int) $post->ID) && !self::content_already_contains_featured_image($content, (int) $post->ID))
            ? get_the_post_thumbnail((int) $post->ID, 'large', [
                'class' => 'ma-single-post-featured-image',
                'loading' => 'eager',
                'decoding' => 'async',
            ])
            : '';
        $header = '<header class="ma-artist-content-header"><h1>' . esc_html($title) . '</h1>';
        if ($image) {
            $header .= '<figure class="ma-artist-content-header__image">' . $image . '</figure>';
        }
        $header .= '</header>';
        return $header . self::artist_page_back_and_tags_html($roles, $mediums) . $content;
    }

    private static function content_already_contains_featured_image(string $content, int $post_id): bool {
        $thumbnail_id = (int) get_post_thumbnail_id($post_id);
        if (!$thumbnail_id) {
            return false;
        }

        if (strpos($content, 'wp-image-' . $thumbnail_id) !== false || strpos($content, 'attachment_' . $thumbnail_id) !== false) {
            return true;
        }

        $urls = array_filter(array_unique([
            wp_get_attachment_url($thumbnail_id),
            wp_get_attachment_image_url($thumbnail_id, 'large'),
            wp_get_attachment_image_url($thumbnail_id, 'medium_large'),
            wp_get_attachment_image_url($thumbnail_id, 'medium'),
            wp_get_attachment_image_url($thumbnail_id, 'thumbnail'),
        ]));

        foreach ($urls as $url) {
            $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
            $filename = $path ? wp_basename($path) : '';
            if ($filename && strpos($content, $filename) !== false) {
                return true;
            }
        }

        return false;
    }

    public static function render_news_posts_page_template(): void {
        if (is_admin() || wp_doing_ajax() || is_feed() || !is_home()) {
            return;
        }
        $request_path = trim((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
        if (strpos($request_path, 'give/') === 0) {
            return;
        }
        if ((int) get_option('page_for_posts') !== 32) {
            return;
        }

        status_header(200);
        get_header();
        echo '<main id="content" class="neve-main ma-news-main" role="main">';
        echo self::news_page_html();
        echo '</main>';
        get_footer();
        exit;
    }

    public static function redirect_dated_artist_profile_urls(): void {
        if (!is_single() || is_admin()) {
            return;
        }
        $post = get_queried_object();
        if (!($post instanceof WP_Post) || $post->post_type !== 'post') {
            return;
        }
        if (!self::is_artist_profile_post((int) $post->ID, (string) $post->post_title)) {
            return;
        }
        $target = get_permalink($post);
        if (!$target) {
            return;
        }
        $current_path = trailingslashit((string) wp_parse_url(home_url(add_query_arg([])), PHP_URL_PATH));
        $target_path = trailingslashit((string) wp_parse_url($target, PHP_URL_PATH));
        if ($current_path !== $target_path) {
            wp_safe_redirect($target, 301);
            exit;
        }
    }

    private static function news_page_html(): string {
        $visiting_artists = self::news_posts_for_category('resident-artists', 12);
        $blog_news = self::news_posts_for_category('news', 12);

        ob_start();
        ?>
        <article class="ma-news-page">
            <header class="ma-news-hero">
                <p>News</p>
                <h1>Updates from Ma's House</h1>
                <div>
                    <p>Recent visiting artists, residency updates, press, and stories from the programs, exhibitions, and community work happening at Ma's House.</p>
                </div>
            </header>

            <div class="ma-news-columns">
                <section class="ma-news-section ma-news-section--artists" aria-label="Visiting Artists">
                    <div class="ma-news-section__heading">
                        <p>Residencies</p>
                        <h2>Visiting Artists</h2>
                    </div>
                    <?php echo self::news_cards_html($visiting_artists, true); ?>
                </section>

                <section class="ma-news-section ma-news-section--blog" aria-label="Blog News">
                    <div class="ma-news-section__heading">
                        <p>Blog</p>
                        <h2>Blog News</h2>
                    </div>
                    <?php echo self::news_cards_html($blog_news, false); ?>
                </section>
            </div>
        </article>
        <?php
        return (string) ob_get_clean();
    }

    private static function subscribe_page_html(): string {
        $settings = get_option('klaviyo_settings');
        $list_id = self::text(is_array($settings) ? ($settings['klaviyo_newsletter_list_id'] ?? '') : '');
        if (!$list_id) {
            $list_id = 'RKZXbT';
        }

        ob_start();
        ?>
        <article class="ma-subscribe-page">
            <section class="ma-subscribe-hero">
                <p>Newsletter</p>
                <h1>Stay connected with Ma's House.</h1>
                <div>
                    <p>Get updates about upcoming exhibitions, visiting artists, workshops, library news, and ways to support the space.</p>
                </div>
            </section>
            <section class="ma-subscribe-card" aria-label="Newsletter signup">
                <form id="ma-klaviyo-subscribe-form" class="ma-klaviyo-form" action="https://manage.kmail-lists.com/subscriptions/subscribe" data-ajax-submit="https://manage.kmail-lists.com/ajax/subscriptions/subscribe" method="GET" target="_blank" novalidate>
                    <input type="hidden" name="g" value="<?php echo esc_attr($list_id); ?>">
                    <label for="ma-klaviyo-email">Email address</label>
                    <div class="ma-klaviyo-form__row">
                        <input id="ma-klaviyo-email" type="email" name="email" placeholder="you@example.com" autocomplete="email" required>
                        <button type="submit">Sign Up</button>
                    </div>
                    <div class="klaviyo_messages" aria-live="polite">
                        <div class="success_message" style="display:none;"></div>
                        <div class="error_message" style="display:none;"></div>
                    </div>
                </form>
            </section>
        </article>
        <script src="https://www.klaviyo.com/media/js/public/klaviyo_subscribe.js" data-no-optimize="1" data-cfasync="false"></script>
        <script data-no-optimize="1" data-cfasync="false">
        if (window.KlaviyoSubscribe) {
            window.KlaviyoSubscribe.attachToForms('#ma-klaviyo-subscribe-form', {hide_form_on_success: true});
        }
        </script>
        <?php
        return (string) ob_get_clean();
    }

    private static function podcast_page_html(): string {
        $rss_url = 'https://cms.megaphone.fm/channel/STONY4418581412?selected=STONY1026693651';
        $apple_url = 'https://podcasts.apple.com/us/podcast/mas-house-podcast/id1635647518';
        $player_url = 'https://play.libsyn.com/embed/destination/id/4331243/height/412/theme/modern/size/large/thumbnail/yes/custom-color/742e2e/playlist-height/200/direction/backward/download/yes/font-color/FFFFFF';

        ob_start();
        ?>
        <article class="ma-podcast-page">
            <header class="ma-podcast-hero">
                <div class="ma-podcast-hero__label">Podcast</div>
                <div class="ma-podcast-hero__copy">
                    <h1>Conversations from Ma's House</h1>
                    <p>The Ma's House podcast, hosted by founder Jeremy Dennis, features conversations with artists-in-residence, exhibiting artists, Shinnecock Tribal Members, and guests connected to Ma's House programs.</p>
                </div>
            </header>

            <section class="ma-podcast-player" aria-label="Podcast player">
                <div class="ma-podcast-player__intro">
                    <p>Recorded in Southampton, New York, at Ma's House on Shinnecock Territory, the podcast shares artist stories, program conversations, and voices connected to the creative life of the space.</p>
                    <div class="ma-podcast-links">
                        <a href="<?php echo esc_url($apple_url); ?>">Listen on Apple Podcasts</a>
                        <a href="<?php echo esc_url($rss_url); ?>">RSS Feed</a>
                    </div>
                </div>
                <div class="ma-podcast-embed">
                    <iframe title="Ma's House Podcast player" src="<?php echo esc_url($player_url); ?>" width="100%" height="412" scrolling="no" allowfullscreen></iframe>
                </div>
            </section>

            <section class="ma-podcast-topics" aria-label="Podcast topics">
                <article>
                    <h2>Artists</h2>
                    <p>Interviews with visiting artists, exhibiting artists, and creative collaborators.</p>
                </article>
                <article>
                    <h2>Community</h2>
                    <p>Conversations with Shinnecock Tribal Members and people connected to local cultural work.</p>
                </article>
                <article>
                    <h2>Programs</h2>
                    <p>Stories from exhibitions, residencies, workshops, and public programs at Ma's House.</p>
                </article>
            </section>
        </article>
        <?php
        return (string) ob_get_clean();
    }

    private static function residency_page_html(): string {
        $hero = 'https://www.mashouse.studio/wp-content/uploads/2023/06/Resized_20230618_165507.jpeg';
        $subscribe_url = home_url('/subscribe/');
        $sponsor_url = home_url('/donations/mas-house-residency-sponsorship/');

        $details = [
            [
                'title' => 'Eligibility',
                'body' => 'The residency supports US-based BIPOC artists working in visual art, writing, performance, research, sound, film, and interdisciplinary practice.',
            ],
            [
                'title' => 'Length',
                'body' => 'Residencies are usually organized as short stays of one to four weeks, depending on the project, season, and available housing.',
            ],
            [
                'title' => 'Public Program',
                'body' => 'Residents are asked to share one public-facing program, workshop, conversation, open studio, or community offering during their stay.',
            ],
            [
                'title' => 'Stipend',
                'body' => 'A $250 weekly stipend is available for residency artists, equal to about $35.70 per day.',
            ],
            [
                'title' => 'Facilities',
                'body' => 'Residents stay at Ma\'s House on the Shinnecock Indian Reservation and have access to shared studio, library, gathering, and program spaces.',
            ],
            [
                'title' => 'Transportation',
                'body' => 'Artists should plan for travel to Southampton, New York. Local transportation needs can be discussed after selection.',
            ],
        ];
        $faqs = [
            [
                'question' => 'Who is eligible?',
                'answer' => [
                    'The Ma\'s House Artist Residency supports BIPOC artists 21+ working across visual art, media and new genres, performance, architecture, film and video, literature, interdisciplinary arts, music composition, research, and related practices.',
                    'Applicants are considered based on the strength of their proposal, artistic merit, feasibility, and how the artist may benefit from time at Ma\'s House and on Shinnecock Territory.',
                ],
            ],
            [
                'question' => 'How long is the residency?',
                'answer' => [
                    'Residencies are scheduled by mutual agreement between accepted artists and Ma\'s House. Most residencies are short stays, with timing shaped by the project, season, and availability.',
                    'Artists from federally recognized tribes may be considered for longer residency periods when the project and schedule allow.',
                ],
            ],
            [
                'question' => 'What is required of resident artists?',
                'answer' => [
                    'Residents participate in at least one public program during their stay. This may be an open rehearsal, workshop, studio visit, lecture, artist talk, reading, performance, screening, or another public-facing format.',
                    'Engaging with or researching Shinnecock artists, East End artists, and local art institutions is strongly encouraged before arrival.',
                ],
            ],
            [
                'question' => 'What does it cost?',
                'answer' => [
                    'There is no fee to apply and no fee to attend. Residents are responsible for their own groceries and meals.',
                    'Ma\'s House offers $250 weekly stipends for residency artists, or about $35.70 per day for stays shorter or longer than a week.',
                ],
            ],
            [
                'question' => 'What is the residency experience like?',
                'answer' => [
                    'Ma\'s House is located in a quiet part of the Shinnecock Indian Reservation. Artists should expect a retreat-style residency with time for focused work, rest, and community connection.',
                    'Founder and board member Jeremy Dennis also lives at Ma\'s House.',
                ],
            ],
            [
                'question' => 'What should artists know about visiting Shinnecock?',
                'answer' => [
                    'Ma\'s House is located on the Shinnecock Indian Reservation, a sovereign self-governing nation in Southampton, New York.',
                    'Being in residence is a privilege of being a guest of the nation. Residents are asked to respect the privacy and space of others on the reservation and not wander alone through the territory.',
                ],
            ],
            [
                'question' => 'How do artists get there?',
                'answer' => [
                    'Ma\'s House is in Southampton, New York, about two hours from New York City. Artists may travel by Long Island Rail Road, Hampton Jitney, or personal vehicle.',
                    'Pickup and drop-off at the Southampton train station or bus stop can be arranged. Uber, Lyft, and car rentals are also available nearby.',
                ],
            ],
            [
                'question' => 'What facilities are available?',
                'answer' => [
                    'Ma\'s House has woodworking tools and basic art materials including tempera paints, brushes, scissors, colored pencils, crayons, glue sticks, hot glue guns, X-Acto knives, beads, and related supplies.',
                    'At this time, Ma\'s House does not have a ceramic kiln, metalworking tools, 3D printing, or a formal dance platform.',
                ],
            ],
            [
                'question' => 'Can groups apply?',
                'answer' => [
                    'At the moment, Ma\'s House can host one artist in residence at a time. Resident artists may invite collaborators for day trips or public program support, but overnight collaborator stays are not currently available.',
                ],
            ],
            [
                'question' => 'When do applications open?',
                'answer' => [
                    'The residency application is currently closed. The open call returns each December.',
                ],
            ],
        ];

        ob_start();
        ?>
        <article class="ma-residency-page">
            <section class="ma-residency-hero">
                <figure>
                    <img src="<?php echo esc_url($hero); ?>" alt="The Ma's House residency bedroom" loading="eager">
                    <figcaption>The Ma's House residency bedroom.</figcaption>
                </figure>
                <div class="ma-residency-hero__copy">
                    <p class="ma-residency-kicker">Residency</p>
                    <h1>Time, space, and community for BIPOC artists.</h1>
                    <p>Ma's House offers a place for artists to rest, research, make work, and share a public program while spending time on the Shinnecock Indian Reservation in Southampton, New York.</p>
                    <div class="ma-residency-status" aria-label="Residency status">
                        <div>
                            <span>2026 Open Call</span>
                            <strong>Closed</strong>
                        </div>
                        <div>
                            <span>Applications</span>
                            <strong>Open each December</strong>
                        </div>
                        <div>
                            <span>Artist Stipend</span>
                            <strong>$250 weekly</strong>
                        </div>
                    </div>
                </div>
            </section>

            <section class="ma-residency-intro">
                <div>
                    <h2>Who the residency is for</h2>
                </div>
                <div>
                    <p>The program is open to BIPOC creatives whose practice may include visual art, writing, performance, community work, archival research, sound, film, or interdisciplinary projects.</p>
                    <p>Applicants are encouraged to consider work connected to Shinnecock history, the local landscape, Indigenous presence, community-based practice, diversity, race, identity, and the cultural life of Ma's House.</p>
                </div>
            </section>

            <section class="ma-residency-cards" aria-label="Residency focus areas">
                <article>
                    <h3>Make work</h3>
                    <p>Use the residency as focused time to create, edit, research, experiment, or develop a new body of work.</p>
                </article>
                <article>
                    <h3>Share with community</h3>
                    <p>Each resident contributes one public program that can take the form of a workshop, talk, open studio, screening, reading, or gathering.</p>
                </article>
                <article>
                    <h3>Spend time here</h3>
                    <p>The residency is intentionally small and place-based, with access to the house, library, studio, and surrounding community context.</p>
                </article>
            </section>

            <section class="ma-residency-details">
                <div class="ma-residency-section-heading">
                    <p>Program Details</p>
                    <h2>What to know before applying</h2>
                </div>
                <div class="ma-residency-detail-grid">
                    <?php foreach ($details as $detail) : ?>
                        <article>
                            <h3><?php echo esc_html($detail['title']); ?></h3>
                            <p><?php echo esc_html($detail['body']); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="ma-residency-note">
                <div>
                    <p>Recent Support</p>
                    <h2>Homebody Fellowship</h2>
                </div>
                <p>Recent special residency support has included the Homebody Fellowship for QTBIPOC artists based in the San Francisco Bay Area, made possible by the Homebody Fund at the East Bay Community Foundation.</p>
            </section>

            <section class="ma-residency-faq">
                <div class="ma-residency-section-heading">
                    <p>Q+A</p>
                    <h2>Residency questions</h2>
                </div>
                <div class="ma-residency-faq__list">
                    <?php foreach ($faqs as $faq) : ?>
                        <details>
                            <summary><?php echo esc_html($faq['question']); ?></summary>
                            <div>
                                <?php foreach ($faq['answer'] as $paragraph) : ?>
                                    <p><?php echo esc_html($paragraph); ?></p>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="ma-residency-cta">
                <div>
                    <p>Current Status</p>
                    <h2>Applications are currently closed.</h2>
                    <p>The residency open call returns each December. Subscribe for application announcements, visiting artist updates, and public program invitations.</p>
                </div>
                <div class="ma-residency-actions">
                    <a href="<?php echo esc_url($subscribe_url); ?>">Get Residency Updates</a>
                    <a href="<?php echo esc_url($sponsor_url); ?>">Sponsor a Residency</a>
                </div>
            </section>
        </article>
        <?php
        return (string) ob_get_clean();
    }

    private static function community_artists_page_html(): string {
        $artists = self::community_artist_cards_data();
        $mediums = [];
        $roles = [];
        $locations = [];
        foreach ($artists as $artist) {
            foreach ($artist['mediums'] as $medium) {
                $mediums[$medium] = $medium;
            }
            foreach ($artist['roles'] as $role) {
                $roles[$role] = $role;
            }
            $location = self::text($artist['location'] ?? '');
            if ($location) {
                $locations[$location] = $location;
            }
        }
        natcasesort($mediums);
        natcasesort($roles);
        natcasesort($locations);

        ob_start();
        ?>
        <style>body.page .nv-page-title-wrap:has(+ .nv-single-page-wrap .ma-community-artists-page){display:none!important}.ma-community-artist-card__image span{display:flex;align-items:center;justify-content:center;width:100%;height:100%;min-height:180px;padding:18px;background:#f3f0ea;color:#111;text-align:center;font-size:20px;line-height:1.15;font-weight:700}</style>
        <article class="ma-community-artists-page">
            <header class="ma-community-artists-hero">
                <p>Community Artists</p>
                <h1>Artists connected to Ma's House</h1>
                <div>
                    <p>A growing directory of community artists, exhibiting artists, resident artists, and guest workshop artists connected to Ma's House programs.</p>
                </div>
            </header>

            <section class="ma-community-artists-controls" aria-label="Artist filters">
                <?php if ($roles) : ?>
                    <div>
                        <span>Role</span>
                        <button type="button" class="is-active" data-ma-artist-filter="role" data-value="">All</button>
                        <?php foreach ($roles as $role) : ?>
                            <button type="button" data-ma-artist-filter="role" data-value="<?php echo esc_attr(sanitize_title($role)); ?>"><?php echo esc_html($role); ?></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($mediums) : ?>
                    <div>
                        <span>Practice</span>
                        <button type="button" class="is-active" data-ma-artist-filter="medium" data-value="">All</button>
                        <?php foreach ($mediums as $medium) : ?>
                            <button type="button" data-ma-artist-filter="medium" data-value="<?php echo esc_attr(sanitize_title($medium)); ?>"><?php echo esc_html($medium); ?></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($locations) : ?>
                    <div>
                        <span>Location</span>
                        <button type="button" class="is-active" data-ma-artist-filter="location" data-value="">All</button>
                        <?php foreach ($locations as $location) : ?>
                            <button type="button" data-ma-artist-filter="location" data-value="<?php echo esc_attr(sanitize_title($location)); ?>"><?php echo esc_html($location); ?></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
            <p class="ma-community-artists-count" aria-live="polite" data-total="<?php echo esc_attr((string) count($artists)); ?>">
                Showing <?php echo esc_html((string) count($artists)); ?> artists
            </p>

            <section class="ma-community-artists-grid" aria-label="Artists">
                <?php foreach ($artists as $artist) : ?>
                    <article class="ma-community-artist-card" data-role="<?php echo esc_attr(implode(' ', array_map('sanitize_title', $artist['roles']))); ?>" data-medium="<?php echo esc_attr(implode(' ', array_map('sanitize_title', $artist['mediums']))); ?>" data-location="<?php echo esc_attr(sanitize_title(self::text($artist['location'] ?? ''))); ?>">
                        <a class="ma-community-artist-card__image" href="<?php echo esc_url($artist['url']); ?>">
                            <?php if ($artist['image']) : ?>
                                <img src="<?php echo esc_url($artist['image']); ?>" alt="<?php echo esc_attr($artist['name'] . ' portrait'); ?>" loading="lazy">
                            <?php else : ?>
                                <span><?php echo esc_html($artist['name']); ?></span>
                            <?php endif; ?>
                        </a>
                        <div class="ma-community-artist-card__body">
                            <h2><a href="<?php echo esc_url($artist['url']); ?>"><?php echo esc_html($artist['name']); ?></a></h2>
                            <?php if ($artist['roles']) : ?>
                                <p class="ma-community-artist-card__roles"><?php echo esc_html(implode(', ', $artist['roles'])); ?></p>
                            <?php endif; ?>
                            <?php if ($artist['mediums']) : ?>
                                <p class="ma-community-artist-card__mediums"><?php echo esc_html(implode(', ', $artist['mediums'])); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($artist['location'])) : ?>
                                <p class="ma-community-artist-card__location">Based in <?php echo esc_html($artist['location']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($artist['residency_period'])) : ?>
                                <p class="ma-community-artist-card__residency">In residence: <?php echo esc_html($artist['residency_period']); ?></p>
                            <?php endif; ?>
                            <?php if ($artist['bio']) : ?>
                                <p class="ma-community-artist-card__bio"><?php echo esc_html(wp_trim_words($artist['bio'], 28)); ?></p>
                            <?php endif; ?>
                            <div class="ma-community-artist-card__links">
                                <a href="<?php echo esc_url($artist['url']); ?>">Profile</a>
                                <?php if ($artist['website']) : ?>
                                    <a href="<?php echo esc_url($artist['website']); ?>">Website</a>
                                <?php endif; ?>
                                <?php if ($artist['social']) : ?>
                                    <a href="<?php echo esc_url($artist['social']); ?>">Social</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        </article>
        <script>
        (function(){
            function controlsRoot(){
                return document.querySelector('.ma-community-artists-controls');
            }
            function activeValues(){
                var controls = controlsRoot();
                if (!controls) return {role:'', medium:'', location:''};
                var role = controls.querySelector('[data-ma-artist-filter="role"].is-active');
                var medium = controls.querySelector('[data-ma-artist-filter="medium"].is-active');
                var location = controls.querySelector('[data-ma-artist-filter="location"].is-active');
                return {
                    role: role ? role.getAttribute('data-value') : '',
                    medium: medium ? medium.getAttribute('data-value') : '',
                    location: location ? location.getAttribute('data-value') : ''
                };
            }
            function setActive(kind, value){
                var controls = controlsRoot();
                if (!controls) return;
                controls.querySelectorAll('[data-ma-artist-filter="' + kind + '"]').forEach(function(item) {
                    item.classList.toggle('is-active', item.getAttribute('data-value') === value);
                });
            }
            function updateProfileLinks(values){
                document.querySelectorAll('.ma-community-artist-card__image, .ma-community-artist-card__body h2 a, .ma-community-artist-card__links a:first-child').forEach(function(link) {
                    if (!link.href) return;
                    var url = new URL(link.href, window.location.origin);
                    if (values.role) url.searchParams.set('role', values.role); else url.searchParams.delete('role');
                    if (values.medium) url.searchParams.set('medium', values.medium); else url.searchParams.delete('medium');
                    if (values.location) url.searchParams.set('location', values.location); else url.searchParams.delete('location');
                    link.href = url.toString();
                });
            }
            function updateCount() {
                var count = document.querySelector('.ma-community-artists-count');
                if (!count) return;
                var visible = document.querySelectorAll('.ma-community-artist-card:not([hidden])').length;
                count.textContent = 'Showing ' + visible + ' artist' + (visible === 1 ? '' : 's');
            }
            function applyFilters(updateUrl){
                var values = activeValues();
                document.querySelectorAll('.ma-community-artist-card').forEach(function(card) {
                    var roleOk = !values.role || card.getAttribute('data-role').split(' ').indexOf(values.role) !== -1;
                    var mediumOk = !values.medium || card.getAttribute('data-medium').split(' ').indexOf(values.medium) !== -1;
                    var locationOk = !values.location || card.getAttribute('data-location') === values.location;
                    card.hidden = !(roleOk && mediumOk && locationOk);
                });
                updateProfileLinks(values);
                if (updateUrl) {
                    var url = new URL(window.location.href);
                    if (values.role) url.searchParams.set('role', values.role); else url.searchParams.delete('role');
                    if (values.medium) url.searchParams.set('medium', values.medium); else url.searchParams.delete('medium');
                    if (values.location) url.searchParams.set('location', values.location); else url.searchParams.delete('location');
                    window.history.replaceState({}, '', url.toString());
                }
                updateCount();
            }
            document.addEventListener('click', function(event) {
                var button = event.target.closest('[data-ma-artist-filter]');
                if (!button) return;
                setActive(button.getAttribute('data-ma-artist-filter'), button.getAttribute('data-value'));
                applyFilters(true);
            });
            var params = new URLSearchParams(window.location.search);
            if (params.has('role')) setActive('role', params.get('role'));
            if (params.has('medium')) setActive('medium', params.get('medium'));
            if (params.has('location')) setActive('location', params.get('location'));
            applyFilters(false);
        }());
        </script>
        <?php
        return (string) ob_get_clean();
    }

    private static function news_posts_for_category(string $slug, int $limit): array {
        return get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            'tax_query' => [[
                'taxonomy' => 'category',
                'field' => 'slug',
                'terms' => [$slug],
            ]],
        ]);
    }

    private static function community_artist_cards_data(): array {
        $cards = [];
        $category = get_term_by('slug', 'artists', 'category') ?: get_term_by('name', 'Artists', 'category');
        $posts = $category instanceof WP_Term ? get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 300,
            'orderby' => 'title',
            'order' => 'ASC',
            'category__in' => [(int) $category->term_id],
        ]) : [];
        foreach ($posts as $post) {
            $post_id = (int) $post->ID;
            $name = self::text(get_post_meta($post_id, 'ma_artist_name', true)) ?: self::text($post->post_title);
            if (!$name) {
                continue;
            }
            $bio = self::bio_from_artist_post_id($post_id) ?: self::clean_bio_text((string) $post->post_excerpt ?: (string) $post->post_content);
            $product_context = self::artist_product_context($name);
            $mediums = self::split_list(self::text(get_post_meta($post_id, 'ma_artist_mediums', true)));
            $mediums = array_values(array_unique(array_merge($mediums, self::infer_artist_mediums($bio . ' ' . $product_context['mediums']))));
            $mediums = self::correct_artist_mediums($name, $mediums);
            $stored_roles = self::valid_artist_roles(self::split_list(self::text(get_post_meta($post_id, 'ma_artist_roles', true))));
            $roles = $stored_roles
                ? self::reconcile_artist_roles($stored_roles, $name, $bio, $post_id, false)
                : self::reconcile_artist_roles(self::infer_artist_roles($post_id, $name, $bio, $product_context), $name, $bio, $post_id, true);
            $website = self::text(get_post_meta($post_id, 'ma_artist_website', true));
            $instagram = self::text(get_post_meta($post_id, 'ma_artist_instagram', true));
            $social = self::artist_social_url($instagram);
            $inferred_location = self::infer_artist_location($bio);
            $location = self::clean_artist_location(self::text(get_post_meta($post_id, 'ma_artist_location', true)));
            if (!self::is_plausible_artist_location($location)) {
                $location = '';
            }
            if (!$location || ($inferred_location && strlen($location) < 8)) {
                $location = $inferred_location;
            }
            $location = self::artist_location_region($location);
            $image = self::text(get_post_meta($post_id, 'ma_artist_portrait_url', true));
            if (!$image && has_post_thumbnail($post_id)) {
                $image = get_the_post_thumbnail_url($post_id, 'medium_large') ?: '';
            }
            $cards[] = [
                'post_id' => $post_id,
                'name' => $name,
                'url' => get_permalink($post_id) ?: home_url('/artist/' . sanitize_title($name) . '/'),
                'image' => self::public_image_url($image),
                'bio' => $bio,
                'mediums' => $mediums,
                'roles' => $roles,
                'location' => $location,
                'website' => $website ? esc_url_raw($website) : '',
                'social' => $social,
            ];
        }
        foreach (self::resident_artist_cards_data() as $resident) {
            $key = strtolower($resident['name']);
            $existing_index = null;
            foreach ($cards as $index => $card) {
                if (strtolower($card['name']) === $key) {
                    $existing_index = $index;
                    break;
                }
            }
            if ($existing_index !== null) {
                $roles = array_values(array_unique(array_merge($cards[$existing_index]['roles'], ['Residency Artist'])));
                $cards[$existing_index]['roles'] = self::reconcile_artist_roles($roles, $resident['name'], $resident['bio'], (int) ($cards[$existing_index]['post_id'] ?? 0));
                $cards[$existing_index]['residency_period'] = self::merge_residency_periods(
                    self::text($cards[$existing_index]['residency_period'] ?? ''),
                    self::text($resident['residency_period'] ?? '')
                );
                if (empty($cards[$existing_index]['bio'])) {
                    $cards[$existing_index]['bio'] = $resident['bio'];
                }
                if (empty($cards[$existing_index]['image'])) {
                    $cards[$existing_index]['image'] = $resident['image'];
                }
                if (empty($cards[$existing_index]['location'])) {
                    $cards[$existing_index]['location'] = $resident['location'] ?? '';
                }
                $cards[$existing_index]['mediums'] = self::correct_artist_mediums(
                    $cards[$existing_index]['name'],
                    array_values(array_unique(array_merge($cards[$existing_index]['mediums'], $resident['mediums'])))
                );
                continue;
            }
            $cards[] = $resident;
        }
        usort($cards, static function ($a, $b): int {
            return strcasecmp(self::artist_last_name_sort_key($a['name'] ?? ''), self::artist_last_name_sort_key($b['name'] ?? ''));
        });
        return $cards;
    }

    private static function artist_last_name_sort_key(string $name): string {
        $name = trim(wp_strip_all_tags($name));
        if (!$name) {
            return '';
        }
        $name = preg_replace('/\s*\([^)]*\)\s*/', ' ', $name);
        $name = preg_replace('/\s+/', ' ', (string) $name);
        $group_suffixes = ['Collective', 'Studio', 'Studios', 'Project', 'Projects', 'Press', 'Co.', 'Company', 'Consulting', 'Design'];
        $parts = array_values(array_filter(explode(' ', trim((string) $name))));
        if (count($parts) <= 1 || in_array(end($parts), $group_suffixes, true)) {
            return strtolower($name);
        }
        $particles = ['da', 'de', 'del', 'der', 'di', 'du', 'la', 'le', 'van', 'von'];
        $last = array_pop($parts);
        while ($parts && in_array(strtolower((string) end($parts)), $particles, true)) {
            $last = array_pop($parts) . ' ' . $last;
        }
        return strtolower($last . ', ' . implode(' ', $parts));
    }

    private static function resident_artist_cards_data(): array {
        $category = get_term_by('slug', 'resident-artists', 'category') ?: get_term_by('name', 'Resident Artists', 'category');
        if (!$category instanceof WP_Term) {
            return [];
        }
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 250,
            'orderby' => 'date',
            'order' => 'DESC',
            'category__in' => [(int) $category->term_id],
        ]);
        $cards = [];
        $seen = [];
        foreach ($posts as $post) {
            $post_id = (int) $post->ID;
            [$name, $period] = self::resident_artist_name_and_period((string) $post->post_title, (string) $post->post_date);
            if (!$name) {
                continue;
            }
            $key = strtolower($name);
            $bio = self::bio_from_text_for_artist($name, (string) $post->post_content) ?: self::clean_bio_text((string) $post->post_excerpt ?: (string) $post->post_content);
            $image = has_post_thumbnail($post_id) ? (get_the_post_thumbnail_url($post_id, 'medium_large') ?: '') : '';
            $location = self::artist_location_region(self::infer_artist_location($bio));
            $mediums = self::correct_artist_mediums($name, self::infer_artist_mediums($bio));
            if (isset($seen[$key])) {
                $index = $seen[$key];
                $cards[$index]['residency_period'] = self::merge_residency_periods($cards[$index]['residency_period'], $period);
                if (!$cards[$index]['bio'] && $bio) {
                    $cards[$index]['bio'] = $bio;
                }
                if (!$cards[$index]['image'] && $image) {
                    $cards[$index]['image'] = self::public_image_url($image);
                }
                if (empty($cards[$index]['location']) && $location) {
                    $cards[$index]['location'] = $location;
                }
                $cards[$index]['mediums'] = self::correct_artist_mediums($name, array_values(array_unique(array_merge($cards[$index]['mediums'], $mediums))));
                continue;
            }
            $seen[$key] = count($cards);
            $cards[] = [
                'post_id' => 0,
                'source_post_id' => $post_id,
                'is_resident_source' => true,
                'name' => $name,
                'url' => home_url('/artist/' . sanitize_title($name) . '/'),
                'image' => self::public_image_url($image),
                'bio' => $bio,
                'mediums' => $mediums,
                'roles' => ['Residency Artist'],
                'location' => $location,
                'residency_period' => $period,
                'website' => '',
                'social' => '',
            ];
        }
        return $cards;
    }

    private static function resident_artist_name_and_period(string $title, string $post_date): array {
        $title = trim(wp_strip_all_tags($title));
        $months = '(January|February|March|April|May|June|July|August|September|Sept|October|November|December)';
        $name = $title;
        $period = '';
        if (preg_match('/^(.*?)\s*[-–—]\s*' . $months . '\s+(\d{4})\s*$/i', $title, $match)) {
            $name = trim($match[1]);
            $period = self::normalize_month_name($match[2]) . ' ' . $match[3];
        } elseif (preg_match('/^(.*?)\s+' . $months . '\s+(\d{4})\s*$/i', $title, $match)) {
            $name = trim($match[1]);
            $period = self::normalize_month_name($match[2]) . ' ' . $match[3];
        }
        if (!$period && $post_date) {
            $timestamp = strtotime($post_date);
            if ($timestamp) {
                $period = gmdate('F Y', $timestamp);
            }
        }
        return [self::text($name), self::text($period)];
    }

    private static function normalize_month_name(string $month): string {
        return strtolower($month) === 'sept' ? 'September' : ucfirst(strtolower($month));
    }

    private static function merge_residency_periods(string $existing, string $period): string {
        $periods = array_values(array_unique(array_filter(array_merge(self::split_list(str_replace("\n", ',', $existing)), self::split_list($period)))));
        return implode(', ', $periods);
    }

    private static function artist_product_context(string $artist_name): array {
        if (!$artist_name || !class_exists('WooCommerce')) {
            return ['count' => 0, 'mediums' => '', 'works' => []];
        }
        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => 40,
            'fields' => 'ids',
            'meta_query' => [[
                'key' => 'ma_artist_name',
                'value' => $artist_name,
                'compare' => '=',
            ]],
        ]);
        $mediums = [];
        $works = [];
        foreach ($ids as $id) {
            $product = wc_get_product((int) $id);
            if (!$product) {
                continue;
            }
            $medium = self::product_detail_value($product, 'Medium');
            if ($medium) {
                $mediums[] = $medium;
            }
            $sku = $product->get_sku();
            $works[] = trim($product->get_name() . ($sku ? ' #' . $sku : ''));
        }
        return ['count' => count($ids), 'mediums' => implode(', ', $mediums), 'works' => $works];
    }

    private static function infer_artist_roles(int $post_id, string $name, string $bio, array $product_context): array {
        $roles = [];
        if (!empty($product_context['count'])) {
            $roles[] = 'Exhibiting Artist';
        }
        if (get_post_meta($post_id, 'ma_artist_residency_period', true) || preg_match('/\b(resident|residency|artist-in-residence|artist in residence)\b/i', $bio)) {
            $roles[] = 'Residency Artist';
        }
        if (self::artist_event_labels($name)) {
            $roles[] = 'Guest Workshop Artist';
        }
        if (self::is_community_artist_signal($name, $bio)) {
            $roles[] = 'Community Artist';
        }
        if (self::is_shinnecock_artist_signal($name, $bio)) {
            $roles[] = 'Shinnecock Artist';
        }
        return self::valid_artist_roles($roles);
    }

    private static function valid_artist_roles(array $roles): array {
        $allowed = self::artist_role_choices();
        $valid = [];
        foreach ($roles as $role) {
            $role = self::text($role);
            if (in_array($role, $allowed, true)) {
                $valid[$role] = $role;
            }
        }
        return array_values($valid);
    }

    private static function reconcile_artist_roles(array $roles, string $name, string $bio, int $post_id = 0, bool $allow_inferred_shinnecock = true): array {
        $roles = self::valid_artist_roles($roles);
        $has_residency = in_array('Residency Artist', $roles, true)
            || ($post_id && (get_post_meta($post_id, 'ma_artist_residency_period', true) || has_category('resident-artists', $post_id)))
            || preg_match('/\b(resident|residency|artist-in-residence|artist in residence)\b/i', $bio);
        if ($has_residency && !in_array('Residency Artist', $roles, true)) {
            $roles[] = 'Residency Artist';
        }
        if (!$has_residency && !$roles) {
            $roles[] = 'Community Artist';
        }
        $community_signal = $has_residency ? self::is_shinnecock_or_manual_community_artist($name, $bio) : self::is_community_artist_signal($name, $bio);
        if ($community_signal && !in_array('Community Artist', $roles, true)) {
            $roles[] = 'Community Artist';
        }
        if ($allow_inferred_shinnecock && self::is_shinnecock_artist_signal($name, $bio) && !in_array('Shinnecock Artist', $roles, true)) {
            $roles[] = 'Shinnecock Artist';
        }
        if (in_array('Community Artist', $roles, true) && $has_residency && !$community_signal) {
            $roles = array_values(array_diff($roles, ['Community Artist']));
        }
        return self::valid_artist_roles($roles);
    }

    private static function is_shinnecock_artist_signal(string $name, string $bio): bool {
        $manual_names = [
            'avery dennis',
            'denise silva-dennis',
            'heather rogers',
            'jeremy dennis',
            'kelly dennis',
        ];
        $normalized_name = strtolower(trim(remove_accents($name)));
        foreach ($manual_names as $manual_name) {
            if ($normalized_name === strtolower(remove_accents($manual_name))) {
                return true;
            }
        }

        $text = strtolower(remove_accents($name . ' ' . wp_strip_all_tags($bio)));
        return (bool) preg_match(
            '/\b(enrolled\s+member\s+of\s+(?:the\s+)?shinnecock|shinnecock\s+(?:indian\s+)?nation\s+(?:artist|member|citizen|tribal\s+member|community\s+member|council\s+member)|shinnecock\s+(?:artist|tribal\s+member|tribe\s+member|nation\s+member|citizen|community\s+member|council\s+member)|member\s+of\s+(?:the\s+)?shinnecock|shinnecock\s+descent)\b/i',
            $text
        );
    }

    private static function is_shinnecock_or_manual_community_artist(string $name, string $bio): bool {
        $text = strtolower($name . ' ' . $bio);
        $manual_names = [
            'denise silva-dennis',
            'brianna l. hernandez',
            'brianna l. hernández',
            'brianna hernandez',
            'brianna hernández',
            'jeremy dennis',
            'kelly dennis',
            'avery dennis',
        ];
        if (in_array(strtolower(trim($name)), $manual_names, true)) {
            return true;
        }
        return (bool) preg_match('/\b(shinnecock\s+(artist|tribal member|tribe member|nation member|member|community member)|enrolled member of the shinnecock|tribal member|tribe member|nation member|workshop coordinator|ma\'s house staff|staff member|board member)\b/i', $text);
    }

    private static function is_community_artist_signal(string $name, string $bio): bool {
        if (self::is_shinnecock_or_manual_community_artist($name, $bio)) {
            return true;
        }
        $text = strtolower($name . ' ' . $bio);
        return (bool) preg_match('/\b(shinnecock|tribal member|tribe member|nation member|community artist|community member|workshop coordinator|teaching artist|ma\'s house staff|staff member|board member)\b/i', $text);
    }

    private static function infer_artist_location(string $bio): string {
        $text = trim(wp_strip_all_tags($bio));
        if (!$text) {
            return '';
        }
        if (preg_match('/\b(?:born and based|based|based out of|lives and works|lives|resides|works)\s+(?:in|on|out of)\s+([^.;\n]{2,90})/i', $text, $match)) {
            return self::validated_artist_location($match[1]);
        }
        if (preg_match('/\b(?:from|native of)\s+((?:the\s+)?[A-Z][A-Za-z.\' -]+,\s*(?:New York|NY|California|CA|Florida|FL|New Jersey|NJ|Virginia|VA|Pennsylvania|PA|Ohio|OH|Colorado|CO|Alaska|AK|Illinois|IL|Washington|WA|Brooklyn|Queens|Bronx|Manhattan|Long Island|Southampton|Sag Harbor|East Hampton|Los Angeles|San Francisco|Philadelphia|Chicago|Cincinnati|Ketchikan|Ione))/i', $text, $match)) {
            return self::validated_artist_location($match[1]);
        }
        if (preg_match('/\b([A-Z][A-Za-z.\' -]+,\s*(?:New York|NY|California|CA|Florida|FL|New Jersey|NJ|Pennsylvania|PA|Ohio|OH|Colorado|CO|Alaska|AK|Illinois|IL|Virginia|VA|Brooklyn|Queens|Bronx|Manhattan|Long Island|Southampton|Sag Harbor|East Hampton|Los Angeles|San Francisco|Philadelphia|Chicago|Cincinnati))[-\x{2013}\x{2014} ]based\b/u', $text, $match)) {
            return self::validated_artist_location($match[1]);
        }
        if (preg_match('/\b([A-Z][A-Za-z.\' -]+)[-\x{2013}\x{2014} ]based\b/u', $text, $match)) {
            $location = self::clean_artist_location($match[1]);
            return preg_match('/\b(US|U\.S|American|community|place|home|studio|project)\b/i', $location) ? '' : self::validated_artist_location($location);
        }
        return '';
    }

    private static function clean_artist_location(string $location): string {
        $location = trim(wp_strip_all_tags($location));
        $location = preg_replace('/\s+/', ' ', $location);
        $location = preg_replace('/^[A-Za-z]+-born,\s*/i', '', $location);
        $location = preg_replace('/^.*?\bis\s+(?:a|an)\s+/i', '', $location);
        $location = preg_replace('/^(?:a|an)\s+/i', '', $location);
        $location = preg_replace('/\s+and\s+the\s+ocean\b.*$/i', '', $location);
        $location = preg_replace('/\s+(who|with|where|whose|working|primarily|currently|artist|designer|writer|photographer|painter|and\s+is|and\s+works|and\s+creates|while|but|after|made\s+possible|at)\b.*$/i', '', $location);
        $location = trim($location, " \t\n\r\0\x0B,.;:-");
        $aliases = [
            'NYC' => 'New York, NY',
            'New York City' => 'New York, NY',
            'New York' => 'New York, NY',
            'Brooklyn, New York' => 'Brooklyn, NY',
            'Brooklyn' => 'Brooklyn, NY',
            'Bronx, New York' => 'Bronx, NY',
            'Bronx' => 'Bronx, NY',
            'Queens, New York' => 'Queens, NY',
            'central Queens, New York' => 'Central Queens, NY',
            'Southampton' => 'Southampton, NY',
            'Sag Harbor, New York' => 'Sag Harbor, NY',
            'Sag Harbor' => 'Sag Harbor, NY',
            'East Hampton, New York' => 'East Hampton, NY',
            'East Hampton' => 'East Hampton, NY',
            'Long Island' => 'Long Island, NY',
            'the Shinnecock Nation' => 'Shinnecock Indian Nation, Southampton, NY',
            'Shinnecock Nation' => 'Shinnecock Indian Nation, Southampton, NY',
            'the Shinnecock Indian Nation' => 'Shinnecock Indian Nation, Southampton, NY',
            'Shinnecock Indian Nation' => 'Shinnecock Indian Nation, Southampton, NY',
            'the San Francisco Bay Area' => 'San Francisco Bay Area',
            'Central and South Florida' => 'Central and South Florida',
            'Philadelphia' => 'Philadelphia, PA',
            'Cincinnati' => 'Cincinnati, OH',
            'Chicago' => 'Chicago, IL',
        ];
        return $aliases[$location] ?? $location;
    }

    private static function validated_artist_location(string $location): string {
        $location = self::clean_artist_location($location);
        return self::is_plausible_artist_location($location) ? $location : '';
    }

    private static function artist_location_region(string $location): string {
        $location = self::clean_artist_location($location);
        if (!$location) {
            return '';
        }
        $regions = [
            'New York' => '/\b(NY|New York|NYC|Brooklyn|Bronx|Queens|Manhattan|Long Island|Southampton|Sag Harbor|East Hampton|Shinnecock)\b/i',
            'California' => '/\b(CA|California|Oakland|San Francisco|Bay Area|Los Angeles)\b/i',
            'Florida' => '/\b(FL|Florida|Central and South Florida)\b/i',
            'New Jersey' => '/\b(NJ|New Jersey)\b/i',
            'Virginia' => '/\b(VA|Virginia|Arlington)\b/i',
            'Pennsylvania' => '/\b(PA|Pennsylvania|Philadelphia)\b/i',
            'Ohio' => '/\b(OH|Ohio|Cincinnati)\b/i',
            'Colorado' => '/\b(CO|Colorado)\b/i',
            'Alaska' => '/\b(AK|Alaska|Ketchikan)\b/i',
            'Illinois' => '/\b(IL|Illinois|Chicago)\b/i',
            'Washington' => '/\b(WA|Washington|Ione)\b/i',
            'Quebec' => '/\b(Kebaowek|First Nation|Quebec|Québec)\b/i',
        ];
        foreach ($regions as $region => $pattern) {
            if (preg_match($pattern, $location)) {
                return $region;
            }
        }
        return '';
    }

    private static function is_plausible_artist_location(string $location): bool {
        if (!$location) {
            return false;
        }
        if (preg_match('/\b(University|College|School|Institute|Foundation|Fund|After|March|April|May|June|July|August|September|October|November|December)\b/i', $location)) {
            return false;
        }
        return (bool) preg_match('/\b(NY|New York|Brooklyn|Bronx|Queens|Manhattan|Long Island|Southampton|Sag Harbor|East Hampton|Shinnecock|Oakland|San Francisco|Bay Area|California|CA|Florida|FL|New Jersey|NJ|Arlington|Virginia|VA|Los Angeles|Central and South Florida|Kebaowek|First Nation|Pennsylvania|PA|Philadelphia|Ohio|OH|Cincinnati|Colorado|CO|Alaska|AK|Ketchikan|Illinois|IL|Chicago|Washington|WA|Ione|Quebec|Québec)\b/i', $location);
    }

    private static function infer_artist_mediums(string $text): array {
        $text = strtolower($text);
        $map = [
            'Printmaker' => ['printmaker', 'printmaking', 'print maker', 'linocut', 'linoleum', 'copperplate', 'etching', 'screenprint', 'screen print', 'woodcut', 'lithograph'],
            'Painter' => ['painter', 'painting', 'paintings', 'acrylic', 'oil paint', 'watercolor', 'gouache'],
            'Illustrator' => ['illustrator', 'illustration', 'illustrations', 'drawing', 'drawings'],
            'Photographer' => ['photographer', 'photography', 'photo-based', 'lens-based', 'cyanotype', 'anthotype'],
            'Muralist' => ['muralist', 'mural', 'murals', 'large-scale public art', 'public art'],
            'Beadworker' => ['beadwork', 'bead worker', 'beadworker', 'beading', 'wampum'],
            'Weaver' => ['weaver', 'weaving', 'woven', 'basketry'],
            'Textile Artist' => ['textile', 'fiber', 'fabric', 'quilting', 'embroidery', 'sewing'],
            'Sculptor' => ['sculptor', 'sculpture', 'carving', 'woodworking', 'woodworker'],
            'Ceramic Artist' => ['ceramic', 'clay', 'pottery'],
            'Installation Artist' => ['installation'],
            'Performance Artist' => ['performance', 'performer'],
            'Comedian' => ['comedian', 'comedy', 'comic'],
            'Writer' => ['writer', 'poet', 'poetry', 'literature', 'author'],
            'Filmmaker' => ['film', 'filmmaker', 'video artist', 'moving image'],
            'Sound Artist' => ['sound artist', 'sound art', 'sound,', 'sound and', 'sound.', 'sound-based', 'audio'],
            'Digital Artist' => ['digital', 'digital fabrication', 'new media', 'media art', 'technology design', 'technology-based'],
            'Curator' => ['curator', 'curatorial'],
            'Educator' => ['educator', 'teaching artist', 'teacher'],
            'Designer' => ['designer', 'design'],
            'Traditional Artist' => ['traditional', 'indigenous arts', 'regalia'],
            'Craft Artist' => ['craft', 'maker'],
            'Multidisciplinary Artist' => ['multidisciplinary', 'interdisciplinary', 'mixed media', 'multi-media', 'multimedia'],
        ];
        $found = [];
        foreach ($map as $medium => $needles) {
            foreach ($needles as $needle) {
                if (strpos($text, $needle) !== false) {
                    $found[$medium] = $medium;
                    break;
                }
            }
        }
        if (!$found && trim($text)) {
            $found['Multidisciplinary Artist'] = 'Multidisciplinary Artist';
        }
        return array_values($found);
    }

    private static function correct_artist_mediums(string $name, array $mediums): array {
        $key = strtolower(trim($name));
        $normalized = [];
        foreach ($mediums as $medium) {
            $medium = self::text($medium);
            if ($medium) {
                $normalized[$medium] = $medium;
            }
        }

        $add = [
            'aliza gandhi' => ['Comedian'],
            'denise silva-dennis' => ['Beadworker'],
            'jacoub reyes' => ['Muralist'],
            'kris waymire' => ['Ceramic Artist'],
            'saidah belo-osagie' => ['Comedian'],
            'stephen longoria' => ['Designer', 'Digital Artist', 'Muralist'],
            'christian weaver' => ['Illustrator'],
        ];
        $remove = [
            'beau bree rhee' => ['Beadworker'],
            'christian weaver' => ['Weaver'],
            'jacoub reyes' => ['Performance Artist'],
            'stephen longoria' => ['Craft Artist'],
        ];
        foreach ($add[$key] ?? [] as $medium) {
            $normalized[$medium] = $medium;
        }
        foreach ($remove[$key] ?? [] as $medium) {
            unset($normalized[$medium]);
        }

        $ordered = [];
        foreach (self::artist_medium_choices() as $choice) {
            if (isset($normalized[$choice])) {
                $ordered[] = $choice;
                unset($normalized[$choice]);
            }
        }
        foreach ($normalized as $medium) {
            $ordered[] = $medium;
        }
        return array_values(array_unique($ordered));
    }

    private static function artist_event_labels(string $artist_name): array {
        if (!$artist_name) {
            return [];
        }
        $events = get_posts([
            'post_type' => ['tribe_events'],
            'post_status' => 'publish',
            'posts_per_page' => 12,
            's' => $artist_name,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        $labels = [];
        foreach ($events as $event) {
            $labels[] = self::text($event->post_title);
        }
        return array_values(array_unique(array_filter($labels)));
    }

    private static function artist_social_url(string $value): string {
        $value = trim($value);
        if (!$value) {
            return '';
        }
        if (preg_match('~^https?://~i', $value)) {
            return esc_url_raw($value);
        }
        $handle = ltrim($value, '@');
        return $handle ? esc_url_raw('https://www.instagram.com/' . $handle) : '';
    }

    private static function initials(string $name): string {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $letters .= strtoupper(substr($part, 0, 1));
        }
        return $letters ?: 'A';
    }

    private static function news_cards_html(array $posts, bool $featured_first): string {
        if (!$posts) {
            return '<p class="ma-news-empty">No posts are available yet.</p>';
        }

        ob_start();
        echo '<div class="ma-news-grid' . ($featured_first ? ' ma-news-grid--featured' : '') . '">';
        foreach ($posts as $index => $post) {
            $post_id = (int) $post->ID;
            $is_featured = $featured_first && $index === 0;
            $image = get_the_post_thumbnail($post_id, $is_featured ? 'large' : 'medium_large', ['loading' => $index < 3 ? 'eager' : 'lazy']);
            if (!$image) {
                $image = '<div class="ma-news-card__placeholder" aria-hidden="true"></div>';
            }
            $excerpt = self::text(get_the_excerpt($post_id));
            if (!$excerpt) {
                $excerpt = wp_trim_words(self::clean_bio_text((string) get_post_field('post_content', $post_id)), 28);
            }
            $excerpt = preg_replace('~<a\b[^>]*>.*?</a>~i', '', $excerpt) ?: $excerpt;
            $excerpt = preg_replace('~\s*Read More\s*(?:Â»|»)?\s*[^<]*$~i', '', $excerpt) ?: $excerpt;
            $excerpt = trim(wp_strip_all_tags($excerpt));
            $terms = wp_get_post_terms($post_id, 'category', ['fields' => 'names']);
            $label = is_array($terms) && $terms ? implode(' / ', array_slice($terms, 0, 2)) : 'News';
            ?>
            <article class="ma-news-card<?php echo $is_featured ? ' ma-news-card--featured' : ''; ?>">
                <a class="ma-news-card__image" href="<?php echo esc_url(get_permalink($post_id)); ?>" aria-label="<?php echo esc_attr(get_the_title($post_id)); ?>">
                    <?php echo $image; ?>
                </a>
                <div class="ma-news-card__body">
                    <p class="ma-news-card__meta"><?php echo esc_html($label); ?> · <?php echo esc_html(get_the_date('M j, Y', $post_id)); ?></p>
                    <h3><a href="<?php echo esc_url(get_permalink($post_id)); ?>"><?php echo esc_html(get_the_title($post_id)); ?></a></h3>
                    <?php if ($excerpt) : ?>
                        <p class="ma-news-card__excerpt"><?php echo esc_html($excerpt); ?></p>
                    <?php endif; ?>
                </div>
            </article>
            <?php
        }
        echo '</div>';
        return (string) ob_get_clean();
    }

    private static function sponsorship_page_html(): string {
        $hero = 'https://www.mashouse.studio/wp-content/uploads/2024/08/20230921_170434-scaled-e1723729988554-1024x498.jpg';
        $tiers = [
            [
                'price' => '$500',
                'name' => 'Workshop',
                'summary' => 'Sponsor one hands-on community workshop led by a Shinnecock artist or current artist-in-residence.',
                'url' => 'https://www.mashouse.studio/donations/mas-house-workshop-sponsorship/',
                'cta' => 'Sponsor a Workshop',
                'benefits' => [
                    '1 complimentary ticket to the upcoming annual Ma\'s House Friendraiser.',
                    'Name or business name listed on the webpage for the upcoming Friendraiser.',
                    'Invitation to attend the sponsored workshop for you and 1 guest.',
                    'Listing on the webpage, emails, and social media content related to the sponsored workshop.',
                ],
            ],
            [
                'price' => '$1,000',
                'name' => 'Residency',
                'summary' => 'Sponsor one week-long Ma\'s House residency for a BIPOC artist-in-residence.',
                'url' => 'https://www.mashouse.studio/donations/mas-house-residency-sponsorship/',
                'cta' => 'Sponsor a Residency',
                'benefits' => [
                    '2 complimentary tickets to the upcoming annual Friendraiser.',
                    'Name or business name listed on the webpage for the upcoming Friendraiser.',
                    'Verbal recognition during the Friendraiser program.',
                    'Invitation for you and 4 guests to attend events related to the sponsored residency, when applicable.',
                    'Listing on webpage, emails, and social media content related to the sponsored residency.',
                ],
            ],
            [
                'price' => '$2,500',
                'name' => 'Exhibition',
                'summary' => 'Sponsor a single exhibition at Ma\'s House.',
                'url' => 'https://www.mashouse.studio/donations/mas-house-exhibit-sponsorship/',
                'cta' => 'Sponsor an Exhibition',
                'benefits' => [
                    '4 complimentary tickets to the upcoming Friendraiser.',
                    'Prominent placement of name or business logo on the Friendraiser webpage.',
                    'Verbal recognition during the Friendraiser program.',
                    'Company logo or donor name on gallery wall and printed content related to the sponsored exhibition.',
                    'Prominent listing on webpage, emails, social media content, and press release related to the sponsored exhibition.',
                    'Invitation for you and up to 6 guests to attend the opening for the sponsored exhibition.',
                ],
            ],
        ];

        ob_start();
        ?>
        <article class="ma-sponsorship-page">
            <section class="ma-sponsorship-hero">
                <img src="<?php echo esc_url($hero); ?>" alt="Community gathering at Ma's House" loading="eager">
                <div class="ma-sponsorship-hero__copy">
                    <p>Sponsorship</p>
                    <h1>Support workshops, residencies, and exhibitions at Ma's House.</h1>
                    <p class="ma-sponsorship-hero__note">Sponsorship supports artist honoraria, public programs, exhibitions, and community access at Ma's House.</p>
                    <div class="ma-sponsorship-hero__actions">
                        <a href="#sponsorship-levels">View Opportunities</a>
                        <a href="https://www.mashouse.studio/donate/">General Donation</a>
                    </div>
                </div>
            </section>

            <section id="sponsorship-levels" class="ma-sponsorship-section">
                <div class="ma-sponsorship-section__intro">
                    <h2>Sponsorship Opportunities &amp; Benefits</h2>
                    <p>Sponsors help keep Ma's House active as a space for Indigenous artists, community programs, exhibitions, and public gatherings. Recent support has strengthened Ma's House through the Ruth Foundation for the Arts 2025-2028 Core Grants cohort and recognition from the Museum Association of New York for Shinnecock Speaks.</p>
                </div>
                <div class="ma-sponsorship-grid">
                    <?php foreach ($tiers as $tier) : ?>
                        <article class="ma-sponsorship-tier">
                            <div>
                                <p class="ma-sponsorship-tier__price"><?php echo esc_html($tier['price']); ?></p>
                                <h3><?php echo esc_html($tier['name']); ?></h3>
                                <p class="ma-sponsorship-tier__summary"><?php echo esc_html($tier['summary']); ?></p>
                            </div>
                            <div>
                                <h4>Benefits</h4>
                                <ul>
                                    <?php foreach ($tier['benefits'] as $benefit) : ?>
                                        <li><?php echo esc_html($benefit); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <a class="ma-sponsorship-tier__cta" href="<?php echo esc_url($tier['url']); ?>"><?php echo esc_html($tier['cta']); ?></a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php echo do_shortcode('[ma_past_sponsors]'); ?>
        </article>
        <?php
        return (string) ob_get_clean();
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

    private static function artist_profile_data_for_product(WC_Product $product): array {
        $post = get_post($product->get_id());
        $source = $post ? ((string) $post->post_title . "\n" . (string) $post->post_content) : $product->get_name();
        $name = self::product_artist_name($product) ?: self::infer_artist_name_from_text($source);
        $profile_post_id = (int) get_post_meta($product->get_id(), 'ma_artist_profile_post_id', true);
        if (!$profile_post_id && $name) {
            $profile_post_id = self::find_artist_profile_post(['name' => $name]);
        }

        $profile_url = '';
        $bio = '';
        $portrait_url = '';
        if ($profile_post_id) {
            $profile_url = get_permalink($profile_post_id) ?: '';
            $bio = self::bio_from_artist_post_id($profile_post_id);
            $portrait_url = self::text(get_post_meta($profile_post_id, 'ma_artist_portrait_url', true));
        }

        if (!$bio && $name) {
            $bio = self::bio_from_existing_artist_post($name);
        }
        if (!$bio) {
            $bio = self::text(get_post_meta($product->get_id(), 'ma_artist_bio', true));
        }
        if (!$portrait_url) {
            $portrait_url = self::text(get_post_meta($product->get_id(), 'ma_artist_portrait_url', true));
        }
        if (!$profile_url) {
            $profile_url = self::text(get_post_meta($product->get_id(), 'ma_artist_profile_url', true));
        }

        return [
            'name' => $name,
            'bio' => $bio,
            'portrait_url' => $portrait_url,
            'profile_url' => $profile_url,
            'profile_post_id' => $profile_post_id,
        ];
    }

    public static function sync_artist_post_to_products(int $post_id, WP_Post $post, bool $update): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || $post->post_type !== 'post') {
            return;
        }

        $artist_name = self::text(get_post_meta($post_id, 'ma_artist_name', true)) ?: self::text(get_the_title($post_id));
        if (!$artist_name || !self::is_artist_profile_post($post_id, $artist_name)) {
            return;
        }

        $bio = self::bio_from_artist_post_id($post_id);
        $profile_url = get_permalink($post_id) ?: '';
        $portrait_url = self::text(get_post_meta($post_id, 'ma_artist_portrait_url', true));
        $product_ids = self::product_ids_for_artist_post($post_id, $artist_name);

        foreach ($product_ids as $product_id) {
            update_post_meta($product_id, 'ma_artist_name', $artist_name);
            update_post_meta($product_id, 'ma_artist_profile_post_id', $post_id);
            update_post_meta($product_id, 'ma_artist_profile_url', esc_url_raw($profile_url));
            if ($bio) {
                update_post_meta($product_id, 'ma_artist_bio', $bio);
            }
            if ($portrait_url) {
                update_post_meta($product_id, 'ma_artist_portrait_url', esc_url_raw($portrait_url));
            }
            clean_post_cache($product_id);
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($product_id);
            }
        }
    }

    private static function is_artist_profile_post(int $post_id, string $artist_name): bool {
        if (get_post_meta($post_id, 'ma_artist_name', true)) {
            return true;
        }
        if (has_category('Artists', $post_id)) {
            return true;
        }
        $content = (string) get_post_field('post_content', $post_id);
        return strpos($content, 'ma-artist-page') !== false && stripos($content, $artist_name) !== false;
    }

    private static function is_current_artist_profile_post(): bool {
        $post = get_queried_object();
        if (!($post instanceof WP_Post) || $post->post_type !== 'post') {
            return false;
        }
        return self::is_artist_profile_post((int) $post->ID, (string) $post->post_title);
    }

    private static function product_ids_for_artist_post(int $post_id, string $artist_name): array {
        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => 'ma_artist_profile_post_id',
                    'value' => $post_id,
                    'compare' => '=',
                ],
                [
                    'key' => 'ma_artist_name',
                    'value' => $artist_name,
                    'compare' => '=',
                ],
            ],
        ]);
        $all_ids = get_posts([
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);
        foreach ($all_ids as $id) {
            $product = wc_get_product((int) $id);
            if ($product instanceof WC_Product && self::product_matches_artist($product, $artist_name)) {
                $ids[] = (int) $id;
            }
        }
        return array_values(array_unique(array_map('intval', $ids)));
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
        echo '<style id="ma-global-site-polish-css">body .hfg_header.site-header,body:not(.home) .elementor-location-header,body:not(.home) .elementor-location-header>*,body:not(.home) .elementor-location-header .elementor,body:not(.home) .elementor-location-header .elementor-section,body:not(.home) .elementor-location-header .elementor-top-section,body:not(.home) .elementor-location-header .elementor-container,body:not(.home) .elementor-location-header .elementor-column,body:not(.home) .elementor-location-header .elementor-widget-wrap,body:not(.home) .elementor-location-header .e-con,body:not(.home) .elementor-location-header .e-con-inner,body:not(.home) header.site-header,body:not(.home) #masthead,body:not(.home) .site-header,body:not(.home) .main-header-bar,body:not(.home) .ast-primary-header-bar{border:0!important;border-color:transparent!important;box-shadow:none!important;outline:0!important}.elementor-location-header:before,.elementor-location-header:after,header.site-header:before,header.site-header:after,#masthead:before,#masthead:after,.hfg_header.site-header:before,.hfg_header.site-header:after{display:none!important;box-shadow:none!important}.elementor-location-header .elementor-widget-container{box-shadow:none!important;border:0!important}.elementor-location-header nav,.elementor-location-header .elementor-nav-menu,.elementor-location-header .elementor-menu-toggle,.elementor-location-header .elementor-search-form,.elementor-location-header .elementor-search-form__container{box-shadow:none!important}.builder-item--header_search,.builder-item--header_search *{box-sizing:border-box!important}.builder-item--header_search .component-wrap.search-field,.builder-item--header_search .widget-search{display:block!important;width:292px!important;max-width:292px!important;margin:0!important;padding:0!important}.builder-item--header_search .search-form{display:flex!important;align-items:stretch!important;width:292px!important;height:38px!important;margin:0!important;padding:0!important;border:1px solid #d8d8d8!important;border-radius:0!important;background:#fff!important;box-shadow:none!important;overflow:hidden!important}body .builder-item--header_search .search-form label{display:none!important}body .builder-item--header_search input.search-field[type="search"]{display:block!important;flex:1 1 auto!important;width:auto!important;min-width:0!important;height:36px!important;min-height:36px!important;max-height:36px!important;margin:0!important;padding:0 12px!important;border:0!important;border-radius:0!important;background:#fff!important;box-shadow:none!important;color:#222!important;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif!important;font-size:14px!important;font-weight:400!important;line-height:36px!important;letter-spacing:0!important;outline:0!important;resize:none!important;-webkit-appearance:none!important;appearance:none!important}body .builder-item--header_search input.search-field[type="search"]::placeholder{color:#777!important;opacity:1!important}body .builder-item--header_search .search-submit.nv-submit{position:static!important;display:flex!important;align-items:center!important;justify-content:center!important;flex:0 0 44px!important;width:44px!important;height:36px!important;min-height:36px!important;max-height:36px!important;margin:0!important;padding:0!important;border:0!important;border-left:1px solid #e3e0da!important;border-radius:0!important;background:#fff!important;box-shadow:none!important;color:#777!important;line-height:1!important;transform:none!important}body .builder-item--header_search .nv-search-icon-wrap,body .builder-item--header_search .nv-icon{display:flex!important;align-items:center!important;justify-content:center!important;width:100%!important;height:100%!important}body .builder-item--header_search svg{display:block!important;width:15px!important;height:15px!important;fill:currentColor!important}body .builder-item--header_search input.search-field[type="search"]::-webkit-search-decoration,body .builder-item--header_search input.search-field[type="search"]::-webkit-search-cancel-button,body .builder-item--header_search input.search-field[type="search"]::-webkit-search-results-button,body .builder-item--header_search input.search-field[type="search"]::-webkit-search-results-decoration{display:none!important}@media(max-width:1180px){.builder-item--header_search .component-wrap.search-field,.builder-item--header_search .widget-search,.builder-item--header_search .search-form{width:240px!important;max-width:240px!important}body .builder-item--header_search input.search-field[type="search"]{font-size:13px!important}}body.page-id-377 #content.neve-main,body.page-id-377 main.neve-main{padding-top:158px!important}body.page-id-377 .nv-single-page-wrap,body.page-id-377 .elementor-page-377{margin-top:0!important}@media(max-width:760px){body.page-id-377 #content.neve-main,body.page-id-377 main.neve-main{padding-top:126px!important}}</style>';
    }

    public static function render_single_post_cover_spacing_css(): void {
        if (is_admin() || !is_single() || (function_exists('is_product') && is_product())) {
            return;
        }
        echo '<style id="ma-single-post-cover-spacing-css" data-no-optimize="1" data-cfasync="false">body.single-post:not(.single-product) .nv-post-cover{display:none!important}body.single-post:not(.single-product) .widget_archive,body.single-post:not(.single-product) .widget_categories,body.single-post:not(.single-product) [id^="archives-"],body.single-post:not(.single-product) [id^="categories-"]{display:none!important}body.single-post:not(.single-product) .nv-post-cover+.container.single-post-container,body.single-post:not(.single-product) .container.single-post-container{margin-top:0!important;padding-top:64px!important}.ma-single-post-content-header{margin:0 0 34px!important}.ma-single-post-content-header h1{font-family:Arial,Helvetica,sans-serif!important;font-size:clamp(32px,4vw,52px)!important;line-height:1.08!important;font-weight:700!important;letter-spacing:0!important;margin:0 0 10px!important;color:#111!important}.ma-single-post-content-header p{font-size:14px!important;line-height:1.4!important;margin:0 0 22px!important;color:#5f6368!important}.ma-single-post-content-header figure{margin:0!important}.ma-single-post-featured-image{display:block!important;width:100%!important;max-width:820px!important;height:auto!important;object-fit:contain!important;border-radius:0!important}@media(max-width:760px){body.single-post:not(.single-product) .nv-post-cover+.container.single-post-container,body.single-post:not(.single-product) .container.single-post-container{padding-top:38px!important}.ma-single-post-content-header{margin-bottom:26px!important}.ma-single-post-content-header h1{font-size:31px!important}}</style>';
    }

    public static function render_staff_page_spacing_css(): void {
        if (!is_page(377)) {
            return;
        }
        echo '<style id="ma-staff-page-spacing-css" data-no-optimize="1" data-cfasync="false">body.page-id-377 #content.neve-main,body.page-id-377 main.neve-main{padding-top:158px!important}body.page-id-377 .hfg_header.site-header{box-shadow:none!important;border:0!important}@media(max-width:760px){body.page-id-377 #content.neve-main,body.page-id-377 main.neve-main{padding-top:126px!important}}</style>';
    }

    public static function render_staff_page_spacing_fallback(): void {
        if (!is_page(377)) {
            return;
        }
        echo '<script id="ma-staff-page-spacing-fallback" data-no-optimize="1" data-cfasync="false">(function(){var apply=function(){var main=document.querySelector("#content.neve-main,main.neve-main");if(main){main.style.setProperty("padding-top",window.innerWidth<=760?"126px":"158px","important");}var header=document.querySelector(".hfg_header.site-header");if(header){header.style.setProperty("box-shadow","none","important");header.style.setProperty("border","0","important");}};apply();if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",apply,{once:true});}window.addEventListener("load",apply,{once:true});}());</script>';
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

    public static function optimize_frontend_global_assets(): void {
        if (is_admin()) {
            return;
        }
        if (self::is_give_embed_endpoint()) {
            return;
        }

        $allow_payment_assets = self::allow_payment_assets_on_current_request();
        $allow_donation_assets = self::allow_donation_assets_on_current_request();
        $allow_contact_assets = self::allow_contact_assets_on_current_request();

        if (!$allow_payment_assets) {
            foreach (self::payment_script_handles() as $handle) {
                wp_dequeue_script($handle);
                wp_deregister_script($handle);
            }
            foreach (self::payment_style_handles() as $handle) {
                wp_dequeue_style($handle);
                wp_deregister_style($handle);
            }
        }

        if (!$allow_donation_assets) {
            foreach (self::donation_script_handles() as $handle) {
                wp_dequeue_script($handle);
                wp_deregister_script($handle);
            }
            foreach (self::donation_style_handles() as $handle) {
                wp_dequeue_style($handle);
                wp_deregister_style($handle);
            }
        }

        if (!$allow_contact_assets) {
            foreach (['google-recaptcha', 'wpcf7-recaptcha'] as $handle) {
                wp_dequeue_script($handle);
                wp_deregister_script($handle);
            }
        }

        if (!self::allow_marketing_assets_on_current_request()) {
            foreach (['klaviyo', 'klaviyo-js', 'kl-identify-browser', 'kl-identify-browser-js', 'wck-viewed-product', 'wck-viewed-product-js'] as $handle) {
                wp_dequeue_script($handle);
                wp_deregister_script($handle);
            }
        }

        if (!$allow_donation_assets && !$allow_contact_assets) {
            foreach (self::front_end_editor_script_handles() as $handle) {
                wp_dequeue_script($handle);
                wp_deregister_script($handle);
            }
        }
    }

    public static function filter_woocommerce_cookie_enabled(bool $enabled, string $name, string $value, int $expire, bool $secure): bool {
        if (!$enabled || is_admin() || wp_doing_ajax() || is_user_logged_in()) {
            return $enabled;
        }
        if (strpos($name, 'wp_woocommerce_session_') !== 0 && strpos($name, 'woocommerce_') !== 0 && strpos($name, 'wc_') !== 0) {
            return $enabled;
        }
        return self::allow_woocommerce_session_on_current_request();
    }

    public static function filter_public_cache_headers(array $headers): array {
        if (is_admin() || wp_doing_ajax() || is_user_logged_in() || !function_exists('is_front_page') || !is_front_page()) {
            return $headers;
        }
        if (self::allow_woocommerce_session_on_current_request() || self::is_direct_give_embed_request()) {
            return $headers;
        }
        $headers['Cache-Control'] = 'max-age=14400, must-revalidate';
        unset($headers['Pragma'], $headers['Expires']);
        return $headers;
    }

    public static function send_front_page_public_cache_headers(): void {
        if (headers_sent() || is_admin() || wp_doing_ajax() || is_user_logged_in() || !function_exists('is_front_page') || !is_front_page()) {
            return;
        }
        if (self::allow_woocommerce_session_on_current_request() || self::is_direct_give_embed_request()) {
            return;
        }
        header_remove('Cache-Control');
        header_remove('Pragma');
        header_remove('Expires');
        header('Cache-Control: max-age=14400, must-revalidate', true);
    }

    public static function filter_frontend_script_tag(string $tag, string $handle, string $src): string {
        if (is_admin()) {
            return $tag;
        }
        $src_lower = strtolower($src);
        if (!self::allow_payment_assets_on_current_request() && (
            self::contains($src_lower, 'js.stripe.com') ||
            self::contains($src_lower, 'paypal.com/sdk/js') ||
            self::contains($src_lower, 'woocommerce-paypal-payments/assets/')
        )) {
            return '';
        }
        if (!self::allow_donation_assets_on_current_request() && (
            self::contains($src_lower, '/plugins/give/') ||
            self::contains($src_lower, '/plugins/give-recurring/')
        )) {
            return '';
        }
        if (!self::allow_contact_assets_on_current_request() && (
            self::contains($src_lower, 'google.com/recaptcha') ||
            self::contains($src_lower, 'gstatic.com/recaptcha') ||
            self::contains($src_lower, '/contact-form-7/modules/recaptcha/')
        )) {
            return '';
        }
        if (!self::allow_marketing_assets_on_current_request() && (
            self::contains($src_lower, 'static.klaviyo.com') ||
            self::contains($src_lower, 'static-tracking.klaviyo.com') ||
            self::contains($src_lower, '/plugins/klaviyo/')
        )) {
            return '';
        }
        return $tag;
    }

    public static function filter_frontend_style_tag(string $html, string $handle, string $href, string $media): string {
        if (is_admin()) {
            return $html;
        }
        $href_lower = strtolower($href);
        if (!self::allow_payment_assets_on_current_request() && (
            self::contains($href_lower, 'woocommerce-gateway-stripe') ||
            self::contains($href_lower, 'woocommerce-paypal-payments')
        )) {
            return '';
        }
        if (!self::allow_donation_assets_on_current_request() && (
            self::contains($href_lower, '/plugins/give/') ||
            self::contains($href_lower, '/plugins/give-recurring/')
        )) {
            return '';
        }
        return $html;
    }

    public static function filter_frontend_resource_hints(array $urls, string $relation_type): array {
        if (is_admin() || $relation_type !== 'preload') {
            return $urls;
        }
        return array_values(array_filter($urls, static function ($url): bool {
            $href = is_array($url) ? strtolower((string) ($url['href'] ?? '')) : strtolower((string) $url);
            return strpos($href, 'social-icons-widget-by-wpzoom/assets/font/') === false;
        }));
    }

    public static function start_frontend_performance_buffer(): void {
        if (is_admin() || wp_doing_ajax() || (function_exists('wp_is_json_request') && wp_is_json_request())) {
            return;
        }
        if (function_exists('is_front_page') && is_front_page()) {
            return;
        }
        ob_start([__CLASS__, 'filter_frontend_html']);
    }

    public static function filter_frontend_html(string $html): string {
        if ($html === '' || stripos($html, '<html') === false) {
            return $html;
        }
        $html = self::filter_public_copy_text($html);
        $html = preg_replace('~<link\b(?=[^>]*\brel=(["\'])preload\1)(?=[^>]*social-icons-widget-by-wpzoom/assets/font/)[^>]*>\s*~i', '', $html) ?? $html;
        if (!self::allow_payment_assets_on_current_request()) {
            $html = preg_replace('~<link\b(?=[^>]*woocommerce-paypal-payments/assets/)[^>]*>\s*~i', '', $html) ?? $html;
        }
        if (!self::allow_marketing_assets_on_current_request()) {
            $html = preg_replace('~<script\b(?=[^>]*\bsrc=(["\'])[^"\']*klaviyo[^"\']*\1)[^>]*>\s*</script>\s*~i', '', $html) ?? $html;
            $html = preg_replace('~<script\b[^>]*\bid=(["\'])kl-identify-browser-js\1[^>]*>\s*</script>\s*~i', '', $html) ?? $html;
        }
        if (self::current_page_slug_matches(['donate'])) {
            $html = str_replace('President &amp; Lead Artist', 'Founder', $html);
            $donation_url = esc_url(home_url('/donations/generalfund/'));
            $html = preg_replace(
                '~<button\b(?=[^>]*\bclass=(["\'])[^"\']*js-give-embed-form-modal-opener[^"\']*\1)[^>]*>\s*Continue to Donate\s*</button>~i',
                '<a class="js-give-embed-form-modal-opener givewp-donation-form-modal__open ma-donate-direct-link" href="' . $donation_url . '">Continue to Donate</a>',
                $html
            ) ?? $html;
            $html = preg_replace(
                '~<form\b(?=[^>]*\baction=(["\'])https://www\.paypal\.com/cgi-bin/webscr\1)(?=[\s\S]*?<input\b[^>]*\bname=(["\'])cmd\2[^>]*\bvalue=(["\'])_xclick\3)(?=[\s\S]*?<input\b[^>]*\bname=(["\'])business\4[^>]*\bvalue=(["\'])\5)(?=[\s\S]*?<span\b[^>]*class=(["\'])elementor-button-text\6[^>]*>\s*Buy Now\s*</span>)[\s\S]*?</form>~i',
                '',
                $html
            ) ?? $html;
        }
        if (self::current_page_slug_matches(['events'])) {
            $html = preg_replace('~<a\b(?=[^>]*\beventDisplay=past\b)[\s\S]*?</a>~i', '', $html) ?? $html;
            $html = preg_replace('~"prev_url"\s*:\s*"[^"]*eventDisplay=past[^"]*"~i', '"prev_url":""', $html) ?? $html;
            $html = preg_replace('~"show_latest_past"\s*:\s*true~i', '"show_latest_past":false', $html) ?? $html;
            $html = str_replace('&#038;#038;', '&#038;', $html);
            if (strpos($html, 'ma-events-past-link-guard') === false) {
                $guard = '<script id="ma-events-past-link-guard" data-no-optimize="1" data-cfasync="false">(function(){function clean(){document.querySelectorAll(\'a[href*="eventDisplay=past"],a[href*="/upcoming/list/"]\').forEach(function(a){if((a.href||"").indexOf("eventDisplay=past")!==-1){a.removeAttribute("href");a.setAttribute("aria-disabled","true");a.style.display="none";}});}document.addEventListener("DOMContentLoaded",clean);window.addEventListener("load",clean);new MutationObserver(clean).observe(document.documentElement,{childList:true,subtree:true});}());</script>';
                $html = str_ireplace('</body>', $guard . '</body>', $html);
            }
        }
        if (function_exists('is_front_page') && is_front_page()) {
            $html = str_replace(
                '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.</p>',
                '<p>Current exhibitions, upcoming programs, workshops, and off-site partnerships from Ma\'s House.</p>',
                $html
            );
            $html = preg_replace(
                '~<section\b[\s\S]*?\bevents found\.[\s\S]*?</section>~i',
                self::render_homepage_live_events_block(),
                $html,
                1
            ) ?? $html;
            if (strpos($html, 'ma-homepage-final-layout-overrides') === false) {
                $html = str_ireplace('</head>', self::homepage_final_layout_overrides_css() . '</head>', $html);
            }
        }
        if (self::is_direct_give_embed_request()) {
            $html = self::eager_load_give_iframes($html);
            $html = self::add_direct_give_embed_guard_css($html);
        }
        if (!function_exists('is_product') || !is_product()) {
            $html = preg_replace('~\s*<style[^>]*>[^<]*\.gmwqp_popup_op\{[\s\S]*?</style>~i', '', $html) ?? $html;
            $html = preg_replace('~\s*<div\s+class=(["\'])gmwqp_popup_op\1[\s\S]*?</div>\s*<style\s+type=(["\'])text/css\2>\s*body\s+\.gmwqp_inq_addtocart:hover[\s\S]*?</style>~i', '', $html) ?? $html;
            $html = preg_replace('~\s*<div\s+class=(["\'])gmwqp_popup_op\1[\s\S]*?</div>\s*~i', '', $html) ?? $html;
        }
        if (function_exists('is_product') && is_product()) {
            $html = preg_replace('~<p>\s*hi\s*</p>\s*~i', '', $html) ?? $html;
            $html = str_replace('>ENQUIRY!<', '>Enquire About This Item<', $html);
        }
        return $html;
    }

    private static function homepage_final_layout_overrides_css(): string {
        return <<<'HTML'
<style id="ma-homepage-final-layout-overrides" data-no-optimize="1" data-cfasync="false">
body.home .elementor-element-c8e0c2e{padding-top:0!important;padding-bottom:48px!important;min-height:0!important;height:auto!important}
body.home .elementor-element-c8e0c2e>.elementor-container{min-height:0!important}
body.home .elementor-element-3dd430f>.elementor-container{display:grid!important;grid-template-columns:minmax(360px,480px) minmax(360px,440px) minmax(320px,380px)!important;gap:clamp(34px,3vw,58px)!important;width:min(1420px,calc(100vw - 96px))!important;max-width:none!important;margin:0 auto!important;align-items:start!important}
body.home .elementor-element-3dd430f .elementor-column{width:auto!important;max-width:none!important;min-width:0!important}
body.home .elementor-element-7b56059>.elementor-element-populated,
body.home .elementor-element-33c8c69>.elementor-element-populated,
body.home .elementor-element-9c0e721>.elementor-element-populated{padding-top:0!important;align-content:flex-start!important;align-items:flex-start!important}
body.home .elementor-element-3f664c6,
body.home .elementor-element-4b2c3f8,
body.home .elementor-element-18410d1{display:none!important}
body.home .elementor-element-58eb33a .elementor-heading-title{max-width:520px!important;font-size:clamp(28px,1.8vw,34px)!important;line-height:1.06!important;letter-spacing:0!important}
body.home .elementor-element-9f214c3{max-width:520px!important}
body.home .elementor-element-9f214c3 p{font-size:17px!important;line-height:1.42!important;margin-bottom:22px!important}
body.home .elementor-element-9f214c3 p:nth-of-type(2){display:none!important}
body.home .elementor-element-525dad1,
body.home .elementor-element-0a7cc4a{width:100%!important;margin-top:0!important}
body.home .elementor-element-525dad1 img,
body.home .elementor-element-0a7cc4a .swiper-slide-image{display:block!important;width:100%!important;height:auto!important;max-height:none!important;object-fit:contain!important}
body.home .elementor-element-0a7cc4a .swiper-slide-image{max-width:380px!important;margin:0 auto!important}
body.home .elementor-element-0a7cc4a .swiper,
body.home .elementor-element-0a7cc4a .swiper-wrapper,
body.home .elementor-element-0a7cc4a .swiper-slide,
body.home .elementor-element-0a7cc4a figure{height:auto!important;min-height:0!important}
body.home .elementor-element-8203c65,
body.home .elementor-element-e18ab85{width:100%!important;margin-top:12px!important}
body.home .elementor-element-8203c65 p,
body.home .elementor-element-e18ab85 .elementor-heading-title{font-size:15px!important;line-height:1.3!important}
body.home .ma-home-events-custom__title,
body.home .ma-home-events-custom__title a,
body.home .ma-home-events-custom__title *{font-size:16px!important;line-height:1.34!important;font-weight:650!important;letter-spacing:0!important}
body.home .ma-home-events-custom__date strong{font-size:22px!important;font-weight:650!important}
body.home .ma-home-events-custom__venue{font-weight:500!important}
body.home .ma-home-events-custom{width:min(1220px,calc(100vw - 48px));margin:72px auto 58px;color:#111;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
body.home .ma-home-events-custom__header{display:flex;align-items:baseline;justify-content:space-between;gap:20px;margin:0 0 34px}
body.home .ma-home-events-custom__header h2{margin:0!important;font-size:25px!important;line-height:1.2!important;font-weight:500!important;letter-spacing:0!important}
body.home .ma-home-events-custom__header a{color:#111!important;text-decoration:underline!important;text-underline-offset:3px;font-weight:650}
body.home .ma-home-events-custom__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));column-gap:52px;row-gap:42px}
body.home .ma-home-events-custom__item{display:grid;grid-template-columns:54px minmax(0,1fr) 160px;gap:22px;align-items:start}
body.home .ma-home-events-custom__date{padding-top:2px;text-align:center;text-transform:uppercase}
body.home .ma-home-events-custom__date span{display:block;color:#555;font-size:10px;line-height:1.1;font-weight:700}
body.home .ma-home-events-custom__date strong{display:block;color:#111;line-height:1.05}
body.home .ma-home-events-custom__meta{margin:0 0 9px;color:#111;font-size:14px;line-height:1.35}
body.home .ma-home-events-custom__title{margin:0 0 12px!important;color:#111!important}
body.home .ma-home-events-custom__title a{color:#111!important;text-decoration:none!important}
body.home .ma-home-events-custom__title a:hover{text-decoration:underline!important;text-underline-offset:3px}
body.home .ma-home-events-custom__venue{margin:0 0 12px;color:#111;font-size:13px;line-height:1.35}
body.home .ma-home-events-custom__excerpt{margin:0;color:#111;font-size:14px;line-height:1.55}
body.home .ma-home-events-custom__excerpt a{color:#111!important;text-decoration:underline!important;text-underline-offset:3px}
body.home .ma-home-events-custom__thumb img{display:block;width:160px;height:160px;object-fit:cover;border-radius:0}
body.home .ma-firefox-video-fallback{position:absolute!important;inset:0!important;display:flex!important;align-items:center!important;justify-content:center!important;flex-direction:column!important;gap:12px!important;background-size:cover!important;background-position:center!important;color:#fff!important;text-decoration:none!important}
body.home .ma-firefox-video-fallback__play{width:58px;height:58px;border-radius:50%;background:rgba(0,0,0,.62);box-shadow:0 0 0 1px rgba(255,255,255,.35);position:relative}
body.home .ma-firefox-video-fallback__play:after{content:"";position:absolute;left:23px;top:17px;border-left:18px solid #fff;border-top:12px solid transparent;border-bottom:12px solid transparent}
body.home .ma-firefox-video-fallback__label{font-size:13px;font-weight:700;letter-spacing:.02em;text-shadow:0 1px 6px rgba(0,0,0,.72)}
@media(max-width:1180px){
body.home .elementor-element-3dd430f>.elementor-container{grid-template-columns:minmax(340px,1fr) minmax(300px,420px)!important;grid-auto-rows:auto!important;column-gap:clamp(28px,4vw,48px)!important;row-gap:22px!important;width:min(1040px,calc(100vw - 48px))!important}
body.home .elementor-element-7b56059{grid-column:1!important;grid-row:1 / span 2!important;width:100%!important;max-width:100%!important}
body.home .elementor-element-33c8c69{grid-column:2!important;grid-row:1!important;width:100%!important;max-width:100%!important}
body.home .elementor-element-9c0e721{grid-column:2!important;grid-row:2!important;width:100%!important;max-width:100%!important}
body.home .elementor-element-525dad1 img{max-height:260px!important;object-fit:contain!important}
body.home .elementor-element-0a7cc4a .swiper-slide-image{max-width:310px!important}
body.home .elementor-element-8203c65 p,
body.home .elementor-element-e18ab85 .elementor-heading-title{font-size:14px!important;line-height:1.28!important}
body.home .ma-home-events-custom__grid{grid-template-columns:1fr}
body.home .ma-home-events-custom__item{grid-template-columns:54px minmax(0,1fr) 150px}
body.home .ma-home-events-custom__thumb img{width:150px;height:150px}
}
@media(max-width:900px){
body.home .elementor-element-c8e0c2e{padding-bottom:40px!important}
body.home .elementor-element-3dd430f>.elementor-container{grid-template-columns:1fr!important;width:min(680px,calc(100vw - 32px))!important}
body.home .elementor-element-7b56059,
body.home .elementor-element-33c8c69,
body.home .elementor-element-9c0e721{grid-column:1!important;grid-row:auto!important;width:100%!important;max-width:100%!important}
body.home .elementor-element-525dad1 img{max-height:none!important}
body.home .elementor-element-0a7cc4a .swiper-slide-image{max-width:420px!important}
body.home .elementor-element-c8e0c2e,
body.home .elementor-element-c8e0c2e>.elementor-container,
body.home .elementor-element-3dd430f,
body.home .elementor-element-3dd430f>.elementor-container,
body.home .elementor-element-3dd430f .elementor-column,
body.home .elementor-element-3dd430f .elementor-widget-wrap,
body.home .elementor-element-3dd430f .elementor-widget,
body.home .elementor-element-3dd430f .elementor-widget-container{box-sizing:border-box!important;max-width:100%!important;min-width:0!important;height:auto!important;min-height:0!important}
body.home .elementor-element-3dd430f .elementor-column{width:100%!important;flex:0 0 100%!important}
body.home .elementor-element-58eb33a,
body.home .elementor-element-9f214c3,
body.home .elementor-element-525dad1,
body.home .elementor-element-8203c65,
body.home .elementor-element-0a7cc4a,
body.home .elementor-element-e18ab85{width:100%!important;max-width:100%!important}
body.home .elementor-element-58eb33a .elementor-heading-title,
body.home .elementor-element-9f214c3,
body.home .elementor-element-9f214c3 .elementor-widget-container,
body.home .elementor-element-9f214c3 p,
body.home .elementor-element-8203c65 p,
body.home .elementor-element-e18ab85 .elementor-heading-title{width:100%!important;max-width:100%!important;white-space:normal!important;overflow-wrap:break-word!important;word-break:normal!important}
body.home .elementor-element-9f214c3 p{text-align:left!important}
body.home .elementor-element-525dad1 img,
body.home .elementor-element-0a7cc4a .swiper-slide-image,
body.home .elementor-element-0a7cc4a .swiper,
body.home .elementor-element-0a7cc4a .swiper-wrapper,
body.home .elementor-element-0a7cc4a .swiper-slide,
body.home .elementor-element-0a7cc4a figure{height:auto!important}
body.home .ma-home-events-custom{width:min(680px,calc(100vw - 32px));margin:48px auto}
body.home .ma-home-events-custom__header{margin-bottom:24px}
body.home .ma-home-events-custom__item{grid-template-columns:46px minmax(0,1fr);gap:14px}
body.home .ma-home-events-custom__thumb{grid-column:2}
body.home .ma-home-events-custom__thumb img{width:100%;height:auto;aspect-ratio:4/3}
}
</style>
<script id="ma-firefox-autoplay-video-fallback" data-no-optimize="1" data-cfasync="false">
(function(){
    if (!/firefox/i.test(navigator.userAgent || "")) {
        return;
    }
    var seen = new WeakSet();
    function videoInfo(src) {
        src = src || "";
        var vimeo = src.match(/player\.vimeo\.com\/video\/(\d+)/i);
        if (vimeo) {
            return { url: "https://vimeo.com/" + vimeo[1], poster: "https://vumbnail.com/" + vimeo[1] + ".jpg" };
        }
        var yt = src.match(/(?:youtube\.com\/embed\/|youtube-nocookie\.com\/embed\/)([A-Za-z0-9_-]+)/i);
        if (yt) {
            return { url: "https://www.youtube.com/watch?v=" + yt[1], poster: "https://img.youtube.com/vi/" + yt[1] + "/hqdefault.jpg" };
        }
        return null;
    }
    function replaceIframe(iframe) {
        if (!iframe || seen.has(iframe)) {
            return;
        }
        var src = iframe.getAttribute("data-lazy-src") || iframe.getAttribute("src") || "";
        var info = videoInfo(src);
        if (!info) {
            return;
        }
        seen.add(iframe);
        iframe.removeAttribute("data-lazy-src");
        iframe.setAttribute("src", "about:blank");
        var link = document.createElement("a");
        link.className = "ma-firefox-video-fallback";
        link.href = info.url;
        link.target = "_blank";
        link.rel = "noopener";
        link.setAttribute("aria-label", "Watch this video");
        link.style.backgroundImage = "linear-gradient(rgba(0,0,0,.25), rgba(0,0,0,.35)), url('" + info.poster + "')";
        link.innerHTML = '<span class="ma-firefox-video-fallback__play" aria-hidden="true"></span><span class="ma-firefox-video-fallback__label">Watch video</span>';
        iframe.replaceWith(link);
    }
    function scan(root) {
        root = root || document;
        if (root.matches && root.matches("iframe")) {
            replaceIframe(root);
        }
        if (root.querySelectorAll) {
            root.querySelectorAll("iframe").forEach(replaceIframe);
        }
    }
    scan(document);
    new MutationObserver(function(mutations){
        mutations.forEach(function(mutation){
            mutation.addedNodes && mutation.addedNodes.forEach(scan);
        });
    }).observe(document.documentElement, { childList: true, subtree: true });
    document.addEventListener("DOMContentLoaded", function(){ scan(document); });
    window.addEventListener("load", function(){ scan(document); });
}());
</script>
HTML;
    }

    public static function render_homepage_final_layout_overrides(): void {
        if (function_exists('is_front_page') && is_front_page()) {
            echo self::homepage_final_layout_overrides_css();
        }
    }

    private static function render_homepage_live_events_block(): string {
        $events = get_posts([
            'post_type' => 'tribe_events',
            'post_status' => 'publish',
            'posts_per_page' => 8,
            'orderby' => 'meta_value',
            'meta_key' => '_EventStartDate',
            'order' => 'ASC',
            'meta_query' => [
                [
                    'key' => '_EventStartDate',
                    'value' => current_time('mysql'),
                    'compare' => '>=',
                    'type' => 'DATETIME',
                ],
            ],
        ]);

        ob_start();
        ?>
        <section class="ma-home-events-custom" aria-label="Upcoming events">
            <div class="ma-home-events-custom__header">
                <h2>Upcoming</h2>
                <a href="<?php echo esc_url(home_url('/events/')); ?>">View all events</a>
            </div>
            <?php if (!$events): ?>
                <p>No upcoming events are currently listed.</p>
            <?php else: ?>
                <div class="ma-home-events-custom__grid">
                    <?php foreach ($events as $event): ?>
                        <?php
                        $event_id = (int) $event->ID;
                        $start = self::event_start_datetime($event_id);
                        $end = self::event_end_datetime($event_id);
                        $start_ts = $start ? strtotime($start) : false;
                        $permalink = get_permalink($event_id);
                        $time = self::event_time_label($start, $end);
                        $venue = function_exists('tribe_get_venue') ? wp_strip_all_tags((string) tribe_get_venue($event_id)) : '';
                        $address = function_exists('tribe_get_full_address') ? wp_strip_all_tags((string) tribe_get_full_address($event_id)) : '';
                        $thumb = get_the_post_thumbnail($event_id, 'medium_large', ['loading' => 'lazy']);
                        $excerpt = self::event_excerpt($event);
                        ?>
                        <article class="ma-home-events-custom__item">
                            <div class="ma-home-events-custom__date">
                                <span><?php echo esc_html($start_ts ? strtoupper(wp_date('D', $start_ts)) : ''); ?></span>
                                <strong><?php echo esc_html($start_ts ? wp_date('j', $start_ts) : ''); ?></strong>
                            </div>
                            <div class="ma-home-events-custom__body">
                                <p class="ma-home-events-custom__meta"><?php echo esc_html($start_ts ? wp_date('M j', $start_ts) : ''); ?><?php echo $time ? ' @ ' . esc_html(str_replace(':00', '', $time)) : ''; ?></p>
                                <h3 class="ma-home-events-custom__title"><a href="<?php echo esc_url($permalink); ?>"><?php echo esc_html(get_the_title($event)); ?></a></h3>
                                <?php if ($venue || $address): ?>
                                    <p class="ma-home-events-custom__venue"><?php echo esc_html(trim($venue . ($venue && $address ? ' ' : '') . $address)); ?></p>
                                <?php endif; ?>
                                <?php if ($excerpt): ?>
                                    <p class="ma-home-events-custom__excerpt"><?php echo esc_html($excerpt); ?> <a href="<?php echo esc_url($permalink); ?>">Read More »</a></p>
                                <?php endif; ?>
                            </div>
                            <?php if ($thumb): ?>
                                <a class="ma-home-events-custom__thumb" href="<?php echo esc_url($permalink); ?>" aria-label="<?php echo esc_attr(get_the_title($event)); ?>">
                                    <?php echo $thumb; ?>
                                </a>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    public static function home_events_shortcode(): string {
        return self::render_homepage_live_events_block();
    }

    public static function replace_homepage_static_events_block(string $content): string {
        $front_id = (int) get_option('page_on_front');
        $current_id = (int) get_the_ID();
        if ($current_id <= 0 && isset($GLOBALS['post']) && $GLOBALS['post'] instanceof WP_Post) {
            $current_id = (int) $GLOBALS['post']->ID;
        }
        if ($front_id <= 0 || $current_id !== $front_id || strpos($content, 'events found.') === false) {
            return $content;
        }
        return preg_replace(
            '~<section\b[\s\S]*?\bevents found\.[\s\S]*?</section>~i',
            self::render_homepage_live_events_block(),
            $content,
            1
        ) ?? $content;
    }

    private static function event_excerpt(WP_Post $event): string {
        $text = $event->post_excerpt ?: $event->post_content;
        $text = strip_shortcodes((string) $text);
        $text = wp_strip_all_tags($text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return wp_trim_words(trim($text), 32, '...');
    }

    private static function filter_public_copy_text(string $html): string {
        $replacements = [
            'Feburary' => 'February',
            'The 2026 Residency Open Call is now CLOSED' => 'The 2026 Residency Open Call is now closed. Applications open each December.',
            'Indigenous artist  artist and photographer Jeremy Dennis' => 'Indigenous artist and photographer Jeremy Dennis',
            'Ma\'s House &amp; BIPOC Art Studio is led by Indigenous artist and photographer Jeremy Dennis. Dennis is an enrolled member of the Shinnecock Indian Nation.' => 'Ma\'s House &amp; BIPOC Art Studio, Inc. was founded by Indigenous artist and photographer Jeremy Dennis, an enrolled member of the Shinnecock Indian Nation.',
            'Ma’s House &amp; BIPOC Art Studio Inc. was chartered in 2021' => 'Ma’s House &amp; BIPOC Art Studio, Inc. was chartered in 2021',
            'Through exhibitions, workshops, and community programs, we empower artists and provide vital resources to help them succeed.' => 'Through exhibitions, residencies, workshops, a library, and community programs, we support artists and provide vital resources for creative work and public learning.',
            'Your support helps us provide essential art supplies, equipment, and educational opportunities while keeping our programs accessible to all.' => 'Your support helps provide artist stipends, materials, equipment, educational opportunities, and accessible public programs.',
            'donations of furnature and other house items' => 'donations of furniture and other house items',
            'Our STart' => 'Our Start',
            'equity isues' => 'equity issues',
            'orgainzations' => 'organizations',
            'Solo artists are a eligible to apply.' => 'Solo artists are eligible to apply.',
            'Thanks to the Creatives Rebuild New York grant, we are grateful to offer $ 250.00 per week honorariums for visiting artists. (Or about $35.70 per day if staying more or less than a week)' => 'Ma&rsquo;s House offers $250 weekly stipends for residency artists, or about $35.70 per day for stays shorter or longer than a week.',
            'The lead artist of Ma&#8217;s House (Jeremy Dennis) also lives at Ma&#8217;s House.' => 'Founder and board member Jeremy Dennis also lives at Ma&#8217;s House.',
            'Interested in Sposoring a Future Resident Artist?' => 'Interested in Sponsoring a Future Resident Artist?',
            'This support has made $250.00 honorariums for resident artists per week possible until June 2024.' => 'Ma&rsquo;s House offers $250 weekly stipends for residency artists, or about $35.70 per day for stays shorter or longer than a week.',
            'six-to-eight-week long exhibitions' => 'six- to eight-week exhibitions',
            'solo-exhibiting visual artists' => 'solo exhibitions by visual artists',
            'Thanks you to our exhibit sponsors!' => 'Thank you to our exhibition sponsors.',
            'Please mail checks (payable to Ma&#8217;s House &amp; BIPOC Art Studio, Inc.) and to:' => 'Please make checks payable to Ma&#8217;s House &amp; BIPOC Art Studio, Inc. and mail them to:',
            'President &amp; Lead Artist' => 'Founder &amp; Board Member',
            'Lead Artist &amp; President of Ma&#8217;s House' => 'Founder &amp; Board Member',
            'The Ma&#8217;s House project is being led by artist and photographer Jeremy Dennis.' => 'Ma&#8217;s House was founded by artist and photographer Jeremy Dennis.',
            'The Ma&#8217;s House project is being led by artist and photographer Jeremy Dennis' => 'Ma&#8217;s House was founded by artist and photographer Jeremy Dennis',
            'Visits by appointment only' => 'Visits Thurs &amp; Sun 10 am to 5 pm weekly or by appointment',
        ];
        $html = str_replace(array_keys($replacements), array_values($replacements), $html);

        $html = preg_replace(
            '~<p><strong>Ma’s House &amp; BIPOC Art Studio Inc</strong>\.\s*is led by Indigenous artist<strong>\s*Jeremy Dennis</strong>\.\s*The project began in June 2020 and serves as a communal art space based on the Shinnecock Indian Reservation in Southampton, New York\.\s*The family house, built in the 1960s, now features a residency program for Black, Indigenous, and People of Color \(BIPOC\) artists, a shared art studio, and a communal library, along with hosting an array of art and history-based programs for tribal members and the broader local community\.</p>~u',
            '<p><strong>Ma’s House &amp; BIPOC Art Studio, Inc.</strong> was founded by Indigenous artist and photographer Jeremy Dennis and is guided by a board, staff, artists, and community partners. The project began in June 2020 and serves as a communal art space based on the Shinnecock Indian Reservation in Southampton, New York. The family house, built in the 1960s, now features a residency program for Black, Indigenous, and People of Color (BIPOC) artists, a shared art studio, a communal library, and art and history-based programs for Shinnecock community members and the broader local community.</p><p>Recent milestones include selection for the Ruth Foundation for the Arts 2025–2028 Core Grants cohort, recognition from the Museum Association of New York for <em>Shinnecock Speaks</em>, and a growing residency alumni network of BIPOC artists working across visual art, writing, performance, research, sound, film, and interdisciplinary practice.</p>',
            $html
        ) ?? $html;
        $html = preg_replace(
            '~<p>Recent milestones include selection for the Ruth Foundation for the Arts 2025[^<]*(?:<em>Shinnecock Speaks</em>)?[^<]*interdisciplinary practice\.</p>~u',
            '',
            $html
        ) ?? $html;
        $html = str_replace(
            'Since June 2020, we raised over $40,000 to renovate and replace leaky plumbing, pouring a new cement floor in the basement to cover the dirt, and replacing the floor of the kitchen and bathroom.',
            'Since June 2020, more than 400 supporters helped raise over $40,000 to repair leaky plumbing, pour a new cement floor in the basement, and replace the kitchen and bathroom floors.',
            $html
        );
        $html = str_replace(
            'It was decided in 2021 to turn Ma&#8217;s House into a non-profit organization to sustain this project and renovation. Yet according to a 2017 national report on equity issues in cultural philanthropy',
            'In 2021, Ma&#8217;s House became a nonprofit organization to sustain this project and its public programs. According to a 2017 national report on equity issues in cultural philanthropy',
            $html
        );
        if (strpos($html, 'Dear Friends and Supporters,') !== false && strpos($html, 'Ruth Foundation for the Arts 2025') === false) {
            $html = str_replace(
                'Thank you for standing with us.</p>',
                'Thank you for standing with us.</p><p>Recent milestones include selection for the Ruth Foundation for the Arts 2025–2028 Core Grants cohort, recognition from the Museum Association of New York for <em>Shinnecock Speaks</em>, and a growing residency alumni network of BIPOC artists.</p>',
                $html
            );
        }
        return $html;
    }

    private static function allow_payment_assets_on_current_request(): bool {
        if (!class_exists('WooCommerce')) {
            return false;
        }
        if (self::allow_donation_assets_on_current_request()) {
            return true;
        }
        if ((function_exists('is_cart') && is_cart()) || (function_exists('is_checkout') && is_checkout()) || (function_exists('is_account_page') && is_account_page())) {
            return true;
        }
        return self::current_page_slug_matches(['cart', 'checkout', 'my-account', 'order-pay']);
    }

    private static function allow_woocommerce_session_on_current_request(): bool {
        $request_path = trim(strtolower((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)), '/');
        if ($request_path === '') {
            return false;
        }
        if (strpos($request_path, 'wp-admin') === 0 || strpos($request_path, 'wp-json') === 0 || strpos($request_path, 'wc-api') === 0) {
            return true;
        }
        foreach (['cart', 'checkout', 'my-account', 'order-pay', 'order-received', 'product/', 'store', 'collection/artwork', 'artwork'] as $path) {
            if ($request_path === trim($path, '/') || strpos($request_path, trim($path, '/') . '/') === 0) {
                return true;
            }
        }
        if (isset($_GET['add-to-cart']) || isset($_GET['wc-ajax']) || isset($_POST['add-to-cart'])) {
            return true;
        }
        if ((function_exists('is_cart') && is_cart()) || (function_exists('is_checkout') && is_checkout()) || (function_exists('is_account_page') && is_account_page()) || (function_exists('is_product') && is_product()) || (function_exists('is_shop') && is_shop()) || (function_exists('is_product_taxonomy') && is_product_taxonomy())) {
            return true;
        }
        return false;
    }

    private static function allow_donation_assets_on_current_request(): bool {
        if (self::is_give_embed_endpoint()) {
            return true;
        }
        $request_path = trim(strtolower((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)), '/');
        if (strpos($request_path, 'donations/') === 0 || strpos($request_path, 'give/') === 0) {
            return true;
        }
        if (self::current_page_slug_matches(['donate', 'donation', 'donations', 'support', 'donor-dashboard'])) {
            return true;
        }
        $post = get_post();
        if (!$post instanceof WP_Post) {
            return false;
        }
        $content = (string) $post->post_content;
        return has_shortcode($content, 'give_form') || has_shortcode($content, 'give_totals') || has_shortcode($content, 'give_goal') || self::contains($content, 'givewp');
    }

    private static function allow_contact_assets_on_current_request(): bool {
        if (self::current_page_slug_matches(['contact', 'visit-contact', 'visit', 'rsvp'])) {
            return true;
        }
        $post = get_post();
        if (!$post instanceof WP_Post) {
            return false;
        }
        $content = (string) $post->post_content;
        return has_shortcode($content, 'contact-form-7') || self::contains($content, 'wpcf7') || self::contains($content, 'g-recaptcha');
    }

    private static function allow_marketing_assets_on_current_request(): bool {
        return self::allow_payment_assets_on_current_request() || self::allow_donation_assets_on_current_request() || self::current_page_slug_matches(['subscribe']);
    }

    private static function is_give_embed_endpoint(): bool {
        return isset($_GET['give-embed']) && self::text(wp_unslash($_GET['give-embed'])) !== '';
    }

    private static function is_give_sponsorship_request(): bool {
        $request_path = trim(strtolower((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)), '/');
        return strpos($request_path, 'donations/mas-house-workshop-sponsorship') === 0
            || strpos($request_path, 'donations/mas-house-residency-sponsorship') === 0
            || strpos($request_path, 'donations/mas-house-exhibit-sponsorship') === 0;
    }

    private static function is_direct_give_embed_request(): bool {
        $request_path = trim(strtolower((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)), '/');
        return $request_path === 'donor-dashboard'
            || strpos($request_path, 'donations/') === 0
            || self::is_give_sponsorship_request();
    }

    private static function eager_load_give_iframes(string $html): string {
        $html = preg_replace_callback('~<iframe\b(?=[^>]*\bname=(["\'])(?:give-embed-form|give-embed-donor-profile)\1)[^>]*>~i', static function (array $match): string {
            $tag = $match[0];
            if (preg_match('~\sdata-lazy-src=(["\'])(.*?)\1~i', $tag, $src_match)) {
                $src = $src_match[2];
                if (preg_match('~\ssrc=(["\'])(.*?)\1~i', $tag)) {
                    $tag = preg_replace('~\ssrc=(["\'])(.*?)\1~i', ' src="' . esc_url($src) . '"', $tag, 1) ?? $tag;
                } else {
                    $tag = preg_replace('~<iframe\b~i', '<iframe src="' . esc_url($src) . '"', $tag, 1) ?? $tag;
                }
            }
            $tag = preg_replace('~\sdata-lazy-src=(["\'])(.*?)\1~i', '', $tag) ?? $tag;
            $tag = preg_replace('~\sdata-rocket-lazyload=(["\'])(.*?)\1~i', '', $tag) ?? $tag;
            $tag = preg_replace('~\sloading=(["\'])lazy\1~i', ' loading="eager"', $tag) ?? $tag;
            $tag = str_replace('visibility: hidden;', 'visibility: visible;', $tag);
            return $tag;
        }, $html) ?? $html;
        $html = preg_replace('~<noscript>\s*<iframe\b(?=[\s\S]*?giveDonationFormInIframe=1)[\s\S]*?</iframe>\s*</noscript>~i', '', $html) ?? $html;
        return $html;
    }

    private static function add_direct_give_embed_guard_css(string $html): string {
        $css = '<style id="ma-direct-give-embed-guard" data-no-optimize="1">body .give-embed-form-wrapper iframe,body iframe[name="give-embed-form"],body iframe[name="give-embed-donor-profile"]{visibility:visible!important;opacity:1!important;width:100%!important;max-width:100%!important}body .give-embed-form-wrapper>.iframe-loader,body iframe[name="give-embed-donor-profile"]+.iframe-loader{display:none!important}</style>';
        if (strpos($html, 'ma-direct-give-embed-guard') !== false) {
            return $html;
        }
        if (stripos($html, '</head>') !== false) {
            return preg_replace('~</head>~i', $css . '</head>', $html, 1) ?? $html;
        }
        return $css . $html;
    }

    private static function current_page_slug_matches(array $slugs): bool {
        if (!is_page()) {
            return false;
        }
        $post = get_post();
        if (!$post instanceof WP_Post) {
            return false;
        }
        $slug = strtolower((string) $post->post_name);
        foreach ($slugs as $needle) {
            if ($slug === $needle || self::contains($slug, (string) $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function contains(string $haystack, string $needle): bool {
        return $needle !== '' && strpos($haystack, $needle) !== false;
    }

    private static function payment_script_handles(): array {
        return [
            'give-stripe-js',
            'give-stripe-js-js',
            'give-stripe-onpage-js',
            'give-stripe-onpage-js-js',
            'wc-stripe-blocks-integration',
            'wc-stripe-express-checkout',
            'wc-stripe-payment-request',
            'wc-stripe-upe-classic',
            'woocommerce_stripe',
            'stripe',
            'ppcp-smart-button',
            'ppcp-smart-button-js',
            'ppcp-button',
            'ppcp-button-js',
            'ppcp-button-js-button',
            'ppcp-smart-buttons',
            'ppcp-fraudnet',
            'ppcp-fraudnet-js',
            'paypal-checkout',
        ];
    }

    private static function payment_style_handles(): array {
        return [
            'wc-stripe-blocks-checkout-style',
            'wc-blocks-style',
        ];
    }

    private static function donation_script_handles(): array {
        return [
            'give',
            'give-js',
            'give-stripe-js',
            'give-stripe-js-js',
            'give-stripe-onpage-js',
            'give-stripe-onpage-js-js',
            'give_recurring_script',
            'give_recurring_script-js',
            'givewp-entities-public',
            'givewp-entities-public-js',
        ];
    }

    private static function donation_style_handles(): array {
        return [
            'give-styles',
            'givewp-design-system-foundation',
            'give_recurring_css',
            'give_recurring_css-css',
        ];
    }

    private static function front_end_editor_script_handles(): array {
        return [
            'react',
            'react-dom',
            'react-jsx-runtime',
            'wp-a11y',
            'wp-api-fetch',
            'wp-autop',
            'wp-blob',
            'wp-block-editor',
            'wp-block-serialization-default-parser',
            'wp-blocks',
            'wp-commands',
            'wp-components',
            'wp-compose',
            'wp-core-data',
            'wp-data',
            'wp-date',
            'wp-deprecated',
            'wp-dom',
            'wp-dom-ready',
            'wp-edit-post',
            'wp-editor',
            'wp-element',
            'wp-escape-html',
            'wp-format-library',
            'wp-html-entities',
            'wp-hooks',
            'wp-is-shallow-equal',
            'wp-keyboard-shortcuts',
            'wp-keycodes',
            'wp-notices',
            'wp-preferences',
            'wp-preferences-persistence',
            'wp-primitives',
            'wp-priority-queue',
            'wp-private-apis',
            'wp-redux-routine',
            'wp-rich-text',
            'wp-server-side-render',
            'wp-shortcode',
            'wp-style-engine',
            'wp-token-list',
            'wp-url',
            'wp-warning',
            'moment',
        ];
    }

    public static function render_artist_profile_css(): void {
        $is_artist_post = is_single() && self::is_current_artist_profile_post();
        if (!is_product() && !$is_artist_post) {
            return;
        }
        if ($is_artist_post) {
            echo '<style id="ma-artist-page-header-clearance-css">body.single-post .nv-post-cover{display:none!important}body.single-post #content.neve-main,body.single-post main.neve-main{padding-top:0!important}body.single-post .single-post-container,body.single-post .nv-single-post-wrap{margin-top:0!important;padding-top:54px!important}.ma-artist-content-header,.ma-artist-page__heading{max-width:1120px;margin:0 auto 24px}.ma-artist-content-header h1,.ma-artist-page__heading h1{margin:0;color:#111;font-family:' . esc_html(self::font_stack()) . ';font-size:34px;line-height:1.15;font-weight:700;letter-spacing:0}.ma-artist-page{padding-top:0!important}.ma-artist-page__portrait{margin-top:0!important}@media(max-width:760px){body.single-post .single-post-container,body.single-post .nv-single-post-wrap{padding-top:32px!important}.ma-artist-content-header h1,.ma-artist-page__heading h1{font-size:28px}}</style>';
            echo '<style id="ma-artist-page-tags-css">.ma-artist-page__nav{max-width:1120px;margin:0 auto 24px;display:grid;gap:14px;font-family:' . esc_html(self::font_stack()) . '}.ma-artist-page__back{display:inline-flex;width:max-content;align-items:center;color:#111!important;text-decoration:none!important;font-size:14px;font-weight:650}.ma-artist-page__back:before{content:"←";margin-right:8px}.ma-artist-page__back:hover{text-decoration:underline!important;text-underline-offset:3px}.ma-artist-page__tags{display:flex;flex-wrap:wrap;gap:8px}.ma-artist-page__tags a{display:inline-flex;align-items:center;border:1px solid rgba(0,0,0,.18);padding:6px 10px;color:#111!important;background:#fff;text-decoration:none!important;font-size:12px;line-height:1;font-weight:650}.ma-artist-page__tags a:hover{background:#f3f1ed}</style>';
        }
        if ($is_artist_post) {
            echo '<style id="ma-artist-page-exhibited-works-css">.ma-artist-page__exhibited-works{max-width:860px;margin:34px 0;padding-top:24px;border-top:1px solid rgba(0,0,0,.14)}.ma-artist-page__exhibited-works h2{margin:0 0 16px!important;color:#111!important;font-size:22px!important;line-height:1.2!important;font-weight:650!important}.ma-artist-page__exhibited-works ul{list-style:none;margin:0;padding:0;display:grid;gap:14px}.ma-artist-page__exhibited-works li{display:grid;gap:4px;padding-bottom:14px;border-bottom:1px solid rgba(0,0,0,.08)}.ma-artist-page__exhibited-works strong{font-size:16px;line-height:1.3}.ma-artist-page__exhibited-works span,.ma-artist-page__exhibited-works em{display:block;color:#444;font-size:14px;line-height:1.45;font-style:normal}</style>';
        }
        echo '<style id="ma-artist-profile-css">body.single-product div.product .summary.entry-summary,.ma-product-summary-column{box-sizing:border-box;padding:0 0 0 28px!important;background:transparent;border:0;box-shadow:none}body.single-product div.product .summary.entry-summary .product_title,body.single-product .elementor-widget-woocommerce-product-title .product_title{margin:0 0 14px!important;color:#050505!important;font-size:30px!important;line-height:1.14!important;font-weight:650!important;letter-spacing:0!important;text-align:left!important}body.single-product div.product .summary.entry-summary .price,body.single-product .elementor-widget-woocommerce-product-price .price{margin:0 0 20px!important;color:#111!important;font-family:Georgia,"Times New Roman",serif!important;font-size:21px!important;line-height:1.2!important;font-weight:400!important;text-align:left!important}body.single-product .elementor-widget-woocommerce-product-price .price .amount{font:inherit!important;color:inherit!important}body.single-product div.product .summary.entry-summary .woocommerce-product-details__short-description{margin:0 0 22px!important;color:#222;font-size:15px;line-height:1.55}.ma-product-artwork-panel{display:grid;gap:20px;margin:20px 0!important;padding:18px 0 20px!important;border-top:1px solid rgba(0,0,0,.12);border-bottom:1px solid rgba(0,0,0,.12);font-family:' . esc_html(self::font_stack()) . ';color:#111}.ma-product-artwork-panel__details{display:grid;gap:10px}.ma-product-artwork-panel__row{display:grid;grid-template-columns:92px minmax(0,1fr);gap:14px;align-items:baseline}.ma-product-artwork-panel__row span{color:#686868;font-size:11px;font-weight:750;letter-spacing:.08em;text-transform:uppercase}.ma-product-artwork-panel__row strong{color:#111;font-size:14px;line-height:1.35;font-weight:500}.ma-product-artwork-panel__row a{color:#111;text-decoration:underline;text-underline-offset:3px}.ma-product-exhibit-card{display:grid;grid-template-columns:82px minmax(0,1fr);gap:14px;align-items:center;margin-top:2px;padding:0;color:#111;text-decoration:none;background:transparent;border:0}.ma-product-exhibit-card:hover h3{text-decoration:underline;text-underline-offset:3px}.ma-product-exhibit-card__image{min-height:74px;background:#f4f2ee;overflow:hidden}.ma-product-exhibit-card__image img{display:block;width:100%;height:100%;aspect-ratio:4/3;object-fit:contain}.ma-product-exhibit-card__body{align-self:center}.ma-product-exhibit-card__body span{display:block;margin:0 0 6px;color:#666;font-size:11px;font-weight:750;letter-spacing:.08em;text-transform:uppercase}.ma-product-exhibit-card__body h3{margin:0 0 7px!important;color:#111!important;font-size:16px!important;line-height:1.25!important;font-weight:700!important}.ma-product-exhibit-card__body p{margin:0 0 3px;color:#333;font-size:13px;line-height:1.35}.ma-product-summary-column .elementor-widget-woocommerce-product-add-to-cart{margin-top:8px}.ma-product-summary-column .stock{margin:0 0 12px!important;color:#333!important;font-size:13px!important;text-transform:uppercase;letter-spacing:.06em}.ma-product-summary-column .single_add_to_cart_button{width:100%;border-radius:0!important;background:#111!important;color:#fff!important;font-weight:700!important;letter-spacing:.02em}.ma-product-summary-column .product_meta{display:grid!important;gap:5px;margin-top:18px!important;color:#555!important;font-size:12px!important;line-height:1.45!important}.ma-product-summary-column .product_meta a{color:#111!important;text-decoration:underline;text-underline-offset:2px}.ma-artist-profile{clear:both;display:grid;grid-template-columns:180px minmax(0,1fr);gap:24px;align-items:start;margin:34px 0 0;padding-top:28px;border-top:1px solid #ddd;color:#111}.ma-artist-profile__portrait{width:180px;aspect-ratio:4/5;background:#f2eee8;overflow:hidden}.ma-artist-profile__portrait a,.ma-artist-profile__portrait img{display:block;width:100%;height:100%}.ma-artist-profile__portrait img{object-fit:cover}.ma-artist-profile h3{margin:0 0 10px;font-size:22px;line-height:1.2}.ma-artist-profile h3 a{color:inherit;text-decoration:none}.ma-artist-profile p{margin:0 0 12px;line-height:1.6}.ma-artist-page{max-width:1120px;margin:0 auto}.ma-artist-page__portrait{max-width:420px;margin:0 0 28px}.ma-artist-page__portrait img{display:block;width:100%;height:auto}.ma-artist-page__bio{max-width:780px}.ma-artist-page__facts{margin:26px 0}.ma-artist-page__facts ul{list-style:none;margin:0;padding:0;display:grid;gap:8px}.ma-artist-artworks{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:26px;margin-top:18px}.ma-artist-artwork img{display:block;width:100%;aspect-ratio:4/3;object-fit:cover}.ma-artist-artwork h3{font-size:18px;line-height:1.25;margin:10px 0 4px}.ma-artist-artwork a{color:inherit;text-decoration:none}.ma-artist-artwork__meta,.ma-artist-artwork__price{font-size:14px;margin:0;color:#333}@media(max-width:760px){body.single-product div.product .summary.entry-summary,.ma-product-summary-column{padding:0!important}.ma-product-artwork-panel__row{grid-template-columns:82px minmax(0,1fr)}.ma-product-exhibit-card{grid-template-columns:86px minmax(0,1fr)}.ma-artist-profile{display:block}.ma-artist-profile__portrait{width:150px;margin:0 0 18px}.ma-artist-artworks{grid-template-columns:1fr}}</style>';
        echo '<style id="ma-product-public-parity-css">body.single-product .elementor-widget-woocommerce-product-meta .product_meta{display:grid!important;gap:5px;margin-top:18px!important;color:#555!important;font-size:12px!important;line-height:1.45!important}body.single-product .elementor-widget-woocommerce-product-meta .product_meta .ma-product-artwork-panel{width:100%;margin:0 0 18px!important}body.single-product .elementor-widget-woocommerce-product-meta .product_meta a{color:#111!important;text-decoration:underline;text-underline-offset:2px}body.single-product .elementor-widget-woocommerce-product-add-to-cart{margin-top:8px}body.single-product .elementor-widget-woocommerce-product-add-to-cart .stock{margin:0 0 12px!important;color:#333!important;font-size:13px!important;text-transform:uppercase;letter-spacing:.06em}body.single-product .elementor-widget-woocommerce-product-add-to-cart .single_add_to_cart_button{width:100%;border-radius:0!important;background:#111!important;color:#fff!important;font-weight:700!important;letter-spacing:.02em}</style>';
        echo '<style id="ma-product-compact-hero-css">body.single-product .elementor-element-923ecf0 .elementor-container{align-items:flex-start!important}body.single-product .elementor-element-923ecf0 .elementor-widget-wrap{align-content:flex-start!important;align-items:flex-start!important}body.single-product .elementor-element-923ecf0 .elementor-element-f5de331,body.single-product .elementor-element-923ecf0 .elementor-widget-woocommerce-product-images{margin-top:0!important;padding-top:0!important}body.single-product .elementor-element-923ecf0 .elementor-element-8a50c1b>.elementor-widget-wrap{padding-top:10px!important}body.single-product .elementor-widget-woocommerce-product-title,body.single-product .elementor-widget-woocommerce-product-price,body.single-product .elementor-widget-woocommerce-product-add-to-cart,body.single-product .elementor-widget-woocommerce-product-meta,body.single-product .elementor-widget-shortcode,body.single-product .elementor-element-923ecf0 .elementor-widget-text-editor{margin-bottom:0!important}body.single-product .elementor-element-923ecf0 .elementor-widget-spacer{display:none!important}body.single-product .elementor-element-923ecf0 .elementor-widget-wrap>*+*{margin-top:18px!important}body.single-product .elementor-element-923ecf0 .elementor-widget-woocommerce-product-title{margin-top:0!important}body.single-product .elementor-widget-woocommerce-product-title .product_title{font-family:' . esc_html(self::font_stack()) . ' !important;font-style:normal!important;font-size:27px!important;font-weight:700!important;line-height:1.18!important;margin:0!important}body.single-product .elementor-widget-woocommerce-product-price .price,body.single-product .elementor-widget-woocommerce-product-price .amount,body.single-product .elementor-widget-woocommerce-product-add-to-cart,body.single-product .elementor-widget-woocommerce-product-meta,body.single-product .ma-product-artwork-panel,body.single-product .elementor-element-923ecf0 .elementor-widget-text-editor{font-family:' . esc_html(self::font_stack()) . ' !important;color:#111!important}body.single-product .elementor-widget-woocommerce-product-price .price{font-size:20px!important;font-weight:500!important;margin:0!important}body.single-product .elementor-widget-woocommerce-product-add-to-cart .stock{font-family:' . esc_html(self::font_stack()) . ' !important;margin:0 0 10px!important;color:#111!important;font-size:12px!important;font-weight:650!important;letter-spacing:.08em!important;text-transform:uppercase!important}body.single-product .elementor-widget-woocommerce-product-add-to-cart form.cart{margin:0!important}body.single-product .elementor-widget-woocommerce-product-add-to-cart .single_add_to_cart_button{min-height:48px!important;padding:13px 18px!important;font-family:' . esc_html(self::font_stack()) . ' !important;font-size:15px!important;line-height:1.2!important}body.single-product .elementor-widget-woocommerce-product-meta .product_meta{margin-top:0!important;font-family:' . esc_html(self::font_stack()) . ' !important}body.single-product .ma-product-artwork-panel{gap:0!important;padding:16px 0!important;margin:0!important}body.single-product .ma-product-artwork-panel__details{gap:8px!important}body.single-product .ma-product-artwork-panel__row{grid-template-columns:96px minmax(0,1fr)!important;gap:12px!important}body.single-product .ma-product-artwork-panel__row span{font-family:' . esc_html(self::font_stack()) . ' !important;color:#666!important;font-size:11px!important;letter-spacing:.08em!important}body.single-product .ma-product-artwork-panel__row strong{font-family:' . esc_html(self::font_stack()) . ' !important;font-size:14px!important;font-weight:500!important}body.single-product .product_meta .detail-container{font-family:' . esc_html(self::font_stack()) . ' !important;font-size:12px!important;line-height:1.35!important}body.single-product .elementor-element-923ecf0 .elementor-widget-text-editor{font-size:12px!important;line-height:1.35!important}@media(max-width:760px){body.single-product .elementor-element-923ecf0 .elementor-element-8a50c1b>.elementor-widget-wrap{padding-top:0!important}body.single-product .elementor-element-923ecf0 .elementor-widget-wrap>*+*{margin-top:14px!important}}</style>';
        echo '<style id="ma-product-font-final-css">body.single-product .elementor-element-923ecf0,body.single-product .elementor-element-923ecf0 *:not(.dashicons):not(.eicon):not([class*="icon"]){font-family:' . esc_html(self::font_stack()) . ' !important}body.single-product .elementor-widget-woocommerce-product-price .price,body.single-product .elementor-widget-woocommerce-product-price .price span,body.single-product .elementor-widget-woocommerce-product-price .amount{font-family:' . esc_html(self::font_stack()) . ' !important;font-style:normal!important}body.single-product .elementor-widget-woocommerce-product-title .product_title{font-family:' . esc_html(self::font_stack()) . ' !important;font-style:normal!important}</style>';
        echo '<style id="ma-product-price-font-final-css">body.single-product .elementor-element-923ecf0 .elementor-widget-woocommerce-product-price .price,body.single-product .elementor-element-923ecf0 .elementor-widget-woocommerce-product-price .price *,body.single-product .elementor-element-923ecf0 .elementor-widget-woocommerce-product-price .amount{font-family:' . esc_html(self::font_stack()) . ' !important;font-style:normal!important;font-weight:500!important}</style>';
        echo '<style id="ma-product-system-font-css">body.single-product .elementor-element-923ecf0,body.single-product .elementor-element-923ecf0 *:not(.dashicons):not(.eicon):not([class*="icon"]){font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif!important}body.single-product .elementor-element-923ecf0 .elementor-widget-woocommerce-product-price .price,body.single-product .elementor-element-923ecf0 .elementor-widget-woocommerce-product-price .price *,body.single-product .elementor-element-923ecf0 .elementor-widget-woocommerce-product-price .amount{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif!important;font-style:normal!important;font-weight:500!important}</style>';
        echo '<style id="ma-variable-product-options-css">body.single-product form.variations_form table.variations{width:100%!important;max-width:540px!important;margin:0 0 18px!important;border:0!important}body.single-product form.variations_form table.variations tbody,body.single-product form.variations_form table.variations tr,body.single-product form.variations_form table.variations th,body.single-product form.variations_form table.variations td{display:block!important;width:100%!important;padding:0!important;border:0!important}body.single-product form.variations_form table.variations tr{margin:0 0 14px!important}body.single-product form.variations_form table.variations .label,body.single-product form.variations_form table.variations label{margin:0 0 6px!important;color:#111!important;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif!important;font-size:12px!important;line-height:1.25!important;font-weight:700!important;letter-spacing:.08em!important;text-transform:uppercase!important}body.single-product form.variations_form table.variations select{display:block!important;width:100%!important;min-width:0!important;max-width:100%!important;height:44px!important;min-height:44px!important;margin:0!important;padding:0 40px 0 12px!important;border:1px solid #d7d2ca!important;border-radius:0!important;background-color:#fff!important;color:#111!important;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif!important;font-size:14px!important;line-height:44px!important;white-space:nowrap!important;overflow:hidden!important;resize:none!important}body.single-product form.variations_form .reset_variations{display:inline-block!important;margin-top:4px!important;font-size:12px!important}body.single-product form.variations_form .single_variation_wrap .woocommerce-variation-add-to-cart,body.single-product form.variations_form .single_variation_wrap .variations_button{display:flex!important;align-items:stretch!important;gap:10px!important;max-width:540px!important}body.single-product form.variations_form .quantity{flex:0 0 74px!important}body.single-product form.variations_form .single_add_to_cart_button{flex:1 1 auto!important}@media(max-width:640px){body.single-product form.variations_form .single_variation_wrap .woocommerce-variation-add-to-cart,body.single-product form.variations_form .single_variation_wrap .variations_button{display:block!important}body.single-product form.variations_form .quantity{margin:0 0 10px!important}}</style>';
        echo '<style id="ma-product-purchase-note-css">body.single-product .ma-product-purchase-note{max-width:520px;margin:0 0 14px!important;color:#444!important;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif!important;font-size:12px!important;line-height:1.45!important;font-weight:400!important}body.single-product .elementor-widget-woocommerce-product-add-to-cart .stock+.ma-product-purchase-note{margin-top:0!important}</style>';
        echo '<style id="ma-product-brand-buttons-css">body.single-product .ma-product-summary-column .elementor-widget-shortcode{justify-content:flex-start!important;align-items:flex-start!important}body.single-product .elementor-widget-woocommerce-product-add-to-cart form.cart,body.single-product .gmwqp_inquirybtn_loop{display:grid!important;grid-template-columns:1fr!important;gap:0!important;width:320px!important;max-width:100%!important;margin-left:0!important;margin-right:auto!important;text-align:left!important}body.single-product .elementor-widget-woocommerce-product-add-to-cart .quantity{display:none!important}body.single-product .elementor-widget-woocommerce-product-add-to-cart .single_add_to_cart_button,body.single-product .summary.entry-summary .single_add_to_cart_button,body.single-product form.cart .single_add_to_cart_button,body.single-product button.single_add_to_cart_button{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:320px!important;max-width:100%!important;height:50px!important;min-height:50px!important;margin:0!important;padding:0 22px!important;border:1px solid #ad231b!important;border-radius:0!important;background:#ad231b!important;color:#fff!important;box-shadow:none!important;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif!important;font-size:15px!important;line-height:1!important;font-weight:750!important;letter-spacing:0!important;text-align:center!important;text-transform:none!important;text-decoration:none!important;appearance:none!important}.gmwqp_inq_addtocart,.gmwqp_inq_addtocart.button,.gmwqp_inq,.gmwqp_inq.button,.gmwqp_popup_op button,.gmwqp_popup_op .button,body.single-product a.gmwqp_inq_addtocart,body.single-product button.gmwqp_inq_addtocart,body.single-product a.gmwqp_inq,body.single-product button.gmwqp_inq{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:320px!important;max-width:100%!important;height:50px!important;min-height:50px!important;margin:12px 0 0!important;padding:0 22px!important;border:1px solid #ad231b!important;border-radius:0!important;background:#ad231b!important;color:#fff!important;box-shadow:none!important;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif!important;font-size:15px!important;line-height:1!important;font-weight:750!important;letter-spacing:0!important;text-align:center!important;text-transform:none!important;text-decoration:none!important;appearance:none!important}.gmwqp_inq_addtocart:hover,.gmwqp_inq_addtocart:focus,.gmwqp_inq:hover,.gmwqp_inq:focus,body.single-product .single_add_to_cart_button:hover,body.single-product .single_add_to_cart_button:focus{background:#8f1c16!important;border-color:#8f1c16!important;color:#fff!important}</style>';
        echo '<style id="ma-product-compact-details-css">body.single-product .elementor-element-923ecf0 .elementor-element-8a50c1b>.elementor-widget-wrap{padding-top:0!important}body.single-product .elementor-element-923ecf0 .elementor-widget-wrap>*+*{margin-top:9px!important}body.single-product .elementor-widget-woocommerce-product-title .product_title{margin-bottom:6px!important}body.single-product .elementor-widget-woocommerce-product-price .price{margin-bottom:10px!important}body.single-product .elementor-widget-woocommerce-product-add-to-cart{margin-top:4px!important}body.single-product .elementor-widget-woocommerce-product-add-to-cart .stock{margin:0 0 7px!important}.ma-product-purchase-note{max-width:390px!important;margin:0 0 8px!important;font-size:12px!important;line-height:1.35!important}.gmwqp_inq_addtocart,.gmwqp_inq_addtocart.button,.gmwqp_inq,.gmwqp_inq.button,body.single-product a.gmwqp_inq_addtocart,body.single-product button.gmwqp_inq_addtocart,body.single-product a.gmwqp_inq,body.single-product button.gmwqp_inq{margin-top:12px!important}body.single-product .elementor-widget-woocommerce-product-meta .product_meta{margin-top:6px!important;gap:2px!important}body.single-product .ma-product-artwork-panel{margin:6px 0 0!important;padding:10px 0!important}body.single-product .ma-product-artwork-panel__details{gap:5px!important}body.single-product .ma-product-artwork-panel__row{gap:10px!important}body.single-product .ma-product-artwork-panel__row strong{font-size:13px!important;line-height:1.25!important}body.single-product .elementor-widget-woocommerce-product-content,body.single-product .woocommerce-Tabs-panel--description,body.single-product .woocommerce-tabs{margin-top:16px!important;padding-top:0!important}body.single-product .elementor-widget-woocommerce-product-content h2,body.single-product .woocommerce-Tabs-panel--description h2{margin:0 0 8px!important;font-size:20px!important;line-height:1.2!important}@media(max-width:760px){body.single-product .elementor-element-923ecf0 .elementor-widget-wrap>*+*{margin-top:9px!important}.ma-product-purchase-note{max-width:none!important}}</style>';
        echo '<style id="ma-contextual-related-products-css">body.single-product .elementor-element-058532c,body.single-product .elementor-element-6c0ca08{display:none!important}.ma-contextual-related-products{clear:both;margin:48px 0 0;padding-top:28px;border-top:1px solid rgba(0,0,0,.14);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#111}.ma-contextual-related-products header{display:flex;align-items:end;justify-content:space-between;gap:20px;margin:0 0 20px}.ma-contextual-related-products h2{margin:0!important;font-size:24px!important;line-height:1.2!important;font-weight:700!important;letter-spacing:0!important;color:#111!important}.ma-contextual-related-products header p{max-width:360px;margin:0;color:#666;font-size:13px;line-height:1.45}.ma-contextual-related-products__grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:22px}.ma-contextual-related-products__item a{color:inherit;text-decoration:none}.ma-contextual-related-products__item img{display:block;width:100%;aspect-ratio:4/3;object-fit:cover;background:#f4f2ee;margin:0 0 12px}.ma-contextual-related-products__item h3{margin:0 0 7px!important;font-size:16px!important;line-height:1.25!important;font-weight:650!important;letter-spacing:0!important;color:#111!important}.ma-contextual-related-products__item p{margin:0 0 7px;color:#555;font-size:12px;line-height:1.35}.ma-contextual-related-products__price{font-size:14px;font-weight:650;color:#111}.ma-contextual-related-products__price .amount{color:inherit}@media(max-width:900px){.ma-contextual-related-products__grid{grid-template-columns:repeat(2,minmax(0,1fr))}.ma-contextual-related-products header{display:block}.ma-contextual-related-products header p{margin-top:8px}}@media(max-width:560px){.ma-contextual-related-products__grid{grid-template-columns:1fr}}</style>';
        echo '<style id="ma-product-image-emphasis-css">body.single-product .elementor-element-923ecf0>.elementor-container{display:flex!important;gap:42px!important;justify-content:flex-start!important}body.single-product .elementor-element-923ecf0 .elementor-element-c02330b{width:54%!important;max-width:650px!important;flex:0 1 54%!important}body.single-product .elementor-element-923ecf0 .elementor-element-8a50c1b{width:46%!important;max-width:560px!important;flex:0 1 46%!important}body.single-product .elementor-element-923ecf0 .elementor-widget-woocommerce-product-images,body.single-product .elementor-element-923ecf0 .elementor-element-f5de331{width:100%!important;max-width:640px!important}body.single-product .elementor-element-923ecf0 .woocommerce-product-gallery{display:block!important;width:100%!important;max-width:640px!important}body.single-product .elementor-element-923ecf0 .elementor-element-8a50c1b>.elementor-widget-wrap{padding-left:0!important}@media(max-width:900px){body.single-product .elementor-element-923ecf0>.elementor-container{display:block!important}body.single-product .elementor-element-923ecf0 .elementor-element-c02330b,body.single-product .elementor-element-923ecf0 .elementor-element-8a50c1b{width:100%!important;max-width:none!important}}</style>';
        echo '<style id="ma-product-image-fill-css">body.single-product .elementor-element-923ecf0 .elementor-element-f5de331{padding-right:0!important;margin-right:0!important;width:100%!important;max-width:650px!important}body.single-product .elementor-element-923ecf0 .woocommerce-product-gallery__wrapper{display:grid!important;grid-template-columns:repeat(6,minmax(0,72px))!important;gap:10px!important;align-items:start!important;width:100%!important;max-width:650px!important}body.single-product .elementor-element-923ecf0 .woocommerce-product-gallery__image{display:block!important;width:72px!important;max-width:72px!important;margin:0!important;float:none!important;overflow:hidden!important;background:#f4f2ee!important;border:1px solid #dedbd4!important;cursor:pointer!important}body.single-product .elementor-element-923ecf0 .woocommerce-product-gallery__image:first-child{grid-column:1/-1!important;width:100%!important;max-width:650px!important;border:0!important;background:transparent!important}body.single-product .elementor-element-923ecf0 .woocommerce-product-gallery__image a{display:block!important;width:100%!important;height:100%!important}body.single-product .elementor-element-923ecf0 .woocommerce-product-gallery__image img{display:block!important;width:100%!important;height:72px!important;max-width:100%!important;object-fit:cover!important}body.single-product .elementor-element-923ecf0 .woocommerce-product-gallery__image:first-child img{height:auto!important;max-height:none!important;object-fit:contain!important}@media(max-width:760px){body.single-product .elementor-element-923ecf0 .woocommerce-product-gallery__wrapper{grid-template-columns:repeat(5,minmax(0,64px))!important;gap:8px!important}body.single-product .elementor-element-923ecf0 .woocommerce-product-gallery__image{width:64px!important;max-width:64px!important}body.single-product .elementor-element-923ecf0 .woocommerce-product-gallery__image img{height:64px!important}}</style>';
        echo '<style id="ma-product-exhibit-body-css">body.single-product .ma-product-summary-column>.ma-product-exhibit-card,body.single-product .ma-product-artwork-panel .ma-product-exhibit-card{display:none!important}.ma-product-exhibit-section{clear:both;width:min(1120px,calc(100% - 48px));max-width:1120px;margin:44px auto 0!important;padding:28px 0;border-top:1px solid rgba(0,0,0,.14);border-bottom:1px solid rgba(0,0,0,.08);font-family:' . esc_html(self::font_stack()) . ';color:#111}.ma-product-exhibit-section h2{margin:0 0 18px!important;color:#111!important;font-size:22px!important;line-height:1.2!important;font-weight:650!important}.ma-product-exhibit-section .ma-product-exhibit-card{display:grid!important;max-width:820px;grid-template-columns:170px minmax(0,1fr)!important;gap:24px!important;align-items:start!important;margin:0!important;color:#111!important;text-decoration:none!important;background:transparent!important;border:0!important}.ma-product-exhibit-section .ma-product-exhibit-card__image{min-height:0!important;background:#f4f2ee;overflow:hidden}.ma-product-exhibit-section .ma-product-exhibit-card__image img{display:block;width:100%;height:auto;aspect-ratio:4/3;object-fit:cover}.ma-product-exhibit-section .ma-product-exhibit-card__body span{display:block;margin:0 0 8px;color:#666;font-size:11px;font-weight:750;letter-spacing:.08em;text-transform:uppercase}.ma-product-exhibit-section .ma-product-exhibit-card__body h3{margin:0 0 8px!important;color:#111!important;font-size:19px!important;line-height:1.25!important;font-weight:700!important}.ma-product-exhibit-section .ma-product-exhibit-card__body p{margin:0 0 5px;color:#333;font-size:14px;line-height:1.45}@media(max-width:760px){.ma-product-exhibit-section{width:calc(100% - 32px);margin-top:28px!important;padding:24px 0}.ma-product-exhibit-section .ma-product-exhibit-card{grid-template-columns:1fr!important;gap:14px!important}.ma-product-exhibit-section .ma-product-exhibit-card__image{max-width:260px}}</style>';
    }

    public static function render_variable_product_image_switcher(): void {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        global $product;
        if (!$product instanceof WC_Product || !$product->is_type('variable')) {
            return;
        }
        ?>
        <script id="ma-variable-product-image-switcher">
        (function(){
            function ready(fn){
                if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn, {once:true});
                else fn();
            }
            function slugify(value){
                return String(value || '').toLowerCase().replace(/&amp;/g,'and').replace(/&/g,'and').replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');
            }
            function gallery(){
                var root = document.querySelector('.elementor-element-923ecf0 .woocommerce-product-gallery') || document.querySelector('.woocommerce-product-gallery');
                if (!root) return null;
                var items = Array.prototype.slice.call(root.querySelectorAll('.woocommerce-product-gallery__image'));
                return items.length ? {root: root, items: items, main: items[0]} : null;
            }
            function imagePayloadFromVariation(variation){
                var image = variation && variation.image ? variation.image : null;
                if (!image || !image.src) return null;
                return {
                    src: image.src,
                    srcset: image.srcset || '',
                    sizes: image.sizes || '',
                    full: image.full_src || image.url || image.src,
                    alt: image.alt || ''
                };
            }
            function imagePayloadFromGalleryItem(item){
                if (!item) return null;
                var img = item.querySelector('img');
                if (!img) return null;
                return {
                    src: img.getAttribute('data-large_image') || img.getAttribute('data-src') || img.currentSrc || img.src,
                    srcset: img.getAttribute('srcset') || item.getAttribute('data-thumb-srcset') || '',
                    sizes: img.getAttribute('sizes') || item.getAttribute('data-thumb-sizes') || '',
                    full: img.getAttribute('data-large_image') || img.getAttribute('data-src') || img.currentSrc || img.src,
                    alt: item.getAttribute('data-thumb-alt') || img.getAttribute('alt') || ''
                };
            }
            function findGalleryItemForColor(colorSlug, colorText){
                var g = gallery();
                if (!g || !colorSlug) return null;
                var needles = [slugify(colorSlug), slugify(colorText)].filter(Boolean);
                for (var i = 0; i < g.items.length; i++) {
                    var item = g.items[i];
                    var img = item.querySelector('img');
                    var hay = [
                        item.getAttribute('data-thumb') || '',
                        item.getAttribute('data-thumb-alt') || '',
                        item.getAttribute('data-thumb-srcset') || '',
                        img ? img.getAttribute('src') : '',
                        img ? img.getAttribute('srcset') : '',
                        img ? img.getAttribute('data-src') : '',
                        img ? img.getAttribute('data-large_image') : '',
                        img ? img.getAttribute('alt') : ''
                    ].join(' ').toLowerCase();
                    for (var n = 0; n < needles.length; n++) {
                        if (needles[n] && hay.indexOf(needles[n]) !== -1) return item;
                    }
                }
                return null;
            }
            function applyMainImage(payload, activeItem){
                var g = gallery();
                if (!g || !g.main || !payload || !payload.src) return;
                var mainImg = g.main.querySelector('img');
                var mainLink = g.main.querySelector('a');
                if (!mainImg) return;

                mainImg.src = payload.src;
                mainImg.setAttribute('src', payload.src);
                mainImg.setAttribute('data-src', payload.full || payload.src);
                mainImg.setAttribute('data-large_image', payload.full || payload.src);
                if (payload.srcset) mainImg.setAttribute('srcset', payload.srcset);
                else mainImg.removeAttribute('srcset');
                if (payload.sizes) mainImg.setAttribute('sizes', payload.sizes);
                else mainImg.removeAttribute('sizes');
                if (payload.alt) mainImg.setAttribute('alt', payload.alt);
                if (mainLink && payload.full) mainLink.setAttribute('href', payload.full);
                g.items.forEach(function(item){ item.classList.remove('ma-active-product-thumb'); });
                if (activeItem) activeItem.classList.add('ma-active-product-thumb');
            }
            function selectedColorInfo(){
                var select = document.querySelector('form.variations_form select[name="attribute_pa_color"], form.variations_form select[data-attribute_name="attribute_pa_color"]');
                if (!select || !select.value) return null;
                var option = select.options[select.selectedIndex];
                return {slug: select.value, text: option ? option.textContent : select.value};
            }
            function syncFromColor(){
                var color = selectedColorInfo();
                if (!color) return;
                var item = findGalleryItemForColor(color.slug, color.text);
                if (item) applyMainImage(imagePayloadFromGalleryItem(item), item);
            }
            ready(function(){
                document.addEventListener('change', function(event){
                    if (event.target && event.target.matches('form.variations_form select[name="attribute_pa_color"], form.variations_form select[data-attribute_name="attribute_pa_color"]')) {
                        window.setTimeout(syncFromColor, 30);
                    }
                });
                document.addEventListener('click', function(event){
                    var item = event.target && event.target.closest ? event.target.closest('.woocommerce-product-gallery__image') : null;
                    if (item && !item.matches('.woocommerce-product-gallery__image:first-child')) {
                        applyMainImage(imagePayloadFromGalleryItem(item), item);
                    }
                }, true);
                if (window.jQuery) {
                    window.jQuery(document).on('found_variation', 'form.variations_form', function(event, variation){
                        var payload = imagePayloadFromVariation(variation);
                        if (payload) applyMainImage(payload, null);
                        else window.setTimeout(syncFromColor, 30);
                    });
                    window.jQuery(document).on('reset_data', 'form.variations_form', function(){
                        var g = gallery();
                        if (g) g.items.forEach(function(item){ item.classList.remove('ma-active-product-thumb'); });
                    });
                }
                syncFromColor();
            });
        }());
        </script>
        <style id="ma-variable-product-active-thumb-css">body.single-product .woocommerce-product-gallery__image.ma-active-product-thumb{border-color:#111!important;box-shadow:0 0 0 1px #111 inset!important}</style>
        <?php
    }

    public static function artist_artworks_shortcode(array $atts): string {
        $atts = shortcode_atts(['artist' => ''], $atts, 'ma_artist_artworks');
        $artist = self::text($atts['artist'] ?? '');
        if (!$artist || !class_exists('WooCommerce')) {
            return '';
        }
        $product_posts = get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        $products = [];
        foreach ($product_posts as $post) {
            $product = wc_get_product($post->ID);
            if ($product instanceof WC_Product && self::product_matches_artist($product, $artist)) {
                $products[] = $post;
            }
        }
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

    private static function product_matches_artist(WC_Product $product, string $artist_name): bool {
        $target = self::artist_compare_key($artist_name);
        if (!$target) {
            return false;
        }
        foreach (self::product_artist_candidates($product) as $candidate) {
            if (self::artist_compare_key($candidate) === $target) {
                return true;
            }
        }
        return false;
    }

    private static function product_artist_name(WC_Product $product): string {
        foreach (self::product_artist_candidates($product) as $candidate) {
            $candidate = self::text($candidate);
            if ($candidate) {
                return $candidate;
            }
        }
        return '';
    }

    private static function product_artist_candidates(WC_Product $product): array {
        $product_id = $product->get_id();
        $post = get_post($product_id);
        $source = $post ? ((string) $post->post_title . "\n" . (string) $post->post_content) : $product->get_name();
        $candidates = [
            get_post_meta($product_id, 'ma_artist_name', true),
        ];

        $artist_terms = wp_get_post_terms($product_id, 'pa_artist', ['fields' => 'names']);
        if (!is_wp_error($artist_terms)) {
            $candidates = array_merge($candidates, $artist_terms);
        }

        $tag_terms = wp_get_post_terms($product_id, 'product_tag', ['fields' => 'names']);
        if (!is_wp_error($tag_terms)) {
            $candidates = array_merge($candidates, $tag_terms);
        }

        if (preg_match('/\bby\s+([^,\n\r]+)/i', $product->get_name(), $match)) {
            $candidates[] = $match[1];
        }
        $inferred = self::infer_artist_name_from_text($source);
        if ($inferred) {
            $candidates[] = $inferred;
        }

        $cleaned = [];
        foreach ($candidates as $candidate) {
            $candidate = self::clean_artist_label((string) $candidate);
            if ($candidate && !in_array($candidate, $cleaned, true)) {
                $cleaned[] = $candidate;
            }
        }
        return $cleaned;
    }

    private static function clean_artist_label(string $artist_name): string {
        $artist_name = self::text(str_replace('_', ' ', $artist_name));
        $artist_name = preg_replace('/\s+/', ' ', $artist_name) ?: $artist_name;
        return trim($artist_name);
    }

    private static function artist_compare_key(string $artist_name): string {
        $artist_name = strtolower(remove_accents(self::clean_artist_label($artist_name)));
        $artist_name = preg_replace('/[^a-z0-9]+/', '', $artist_name) ?: '';
        return $artist_name;
    }

    public static function past_sponsors_shortcode(array $atts = []): string {
        $atts = shortcode_atts([
            'limit' => 48,
            'forms' => '7700,7705,7709',
        ], $atts, 'ma_past_sponsors');

        $sponsorship_form_ids = array_values(array_filter(array_map('absint', explode(',', (string) $atts['forms']))));
        $sponsors = self::givewp_public_sponsors($sponsorship_form_ids, absint($atts['limit']), 500.0);
        $heading = 'Major Program Sponsors';

        if (!$sponsors) {
            return '<section class="ma-past-sponsors"><h2>Major Program Sponsors</h2><p>Sponsors will appear here as GiveWP sponsorship records are completed.</p></section>';
        }

        ob_start();
        ?>
        <section class="ma-past-sponsors" aria-label="<?php echo esc_attr($heading); ?>">
            <div class="ma-past-sponsors__intro">
                <h2><?php echo esc_html($heading); ?></h2>
            </div>
            <div class="ma-past-sponsors__grid">
                <?php foreach ($sponsors as $sponsor) : ?>
                    <article class="ma-past-sponsor">
                        <h3><?php echo esc_html($sponsor['name']); ?></h3>
                        <?php if (!empty($sponsor['form_title'])) : ?>
                            <p><?php echo esc_html($sponsor['form_title']); ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private static function givewp_public_sponsors(array $form_ids, int $limit, float $minimum_total = 0.0): array {
        $limit = max(1, min(120, $limit ?: 48));
        $query = [
            'post_type' => 'give_payment',
            'post_status' => 'any',
            'posts_per_page' => 240,
            'fields' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
        ];
        $form_ids = array_map('strval', $form_ids);
        $seen = [];
        $sponsors = [];
        foreach (get_posts($query) as $payment_id) {
            if (self::text(get_post_meta($payment_id, '_give_anonymous_donation', true)) === '1') {
                continue;
            }
            $form_id = self::text(get_post_meta($payment_id, '_give_payment_form_id', true));
            $total = (float) get_post_meta($payment_id, '_give_payment_total', true);
            if (!in_array($form_id, $form_ids, true) && $total < $minimum_total) {
                continue;
            }
            $first = self::text(get_post_meta($payment_id, '_give_donor_billing_first_name', true));
            $last = self::text(get_post_meta($payment_id, '_give_donor_billing_last_name', true));
            $name = trim($first . ' ' . $last);
            if (!$name) {
                continue;
            }
            $key = strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $sponsors[] = [
                'name' => $name,
                'form_title' => self::text(get_post_meta($payment_id, '_give_payment_form_title', true)),
            ];
            if (count($sponsors) >= $limit) {
                break;
            }
        }
        return $sponsors;
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
                var searchText = [item.title, item.artist, item.medium, item.category_label, item.price_text].join(' ');
                return '<article class="ma-custom-store-card" data-product-id="' + esc(item.id) + '" data-artist="' + esc(item.artist || '') + '" data-artist-slug="' + esc(slugify(item.artist || '')) + '" data-medium="' + esc(item.medium || '') + '" data-medium-slug="' + esc(slugify(item.medium || '')) + '" data-category="' + esc(item.category_slug || '') + '" data-search="' + esc(searchText.toLowerCase()) + '">' +
                    '<a class="ma-custom-store-card__image" href="' + esc(item.url) + '">' + image + '</a>' +
                    (item.category_label ? '<div class="ma-custom-store-card__type">' + esc(item.category_label) + '</div>' : '') +
                    '<h3><a href="' + esc(item.url) + '">' + esc(item.title) + '</a></h3>' +
                    (item.artist ? '<p class="ma-custom-store-card__artist">' + esc(item.artist) + '</p>' : '') +
                    (item.medium ? '<p class="ma-custom-store-card__medium">' + esc(item.medium) + '</p>' : '') +
                    (item.price_html ? '<div class="ma-custom-store-card__price">' + item.price_html + '</div>' : '') +
                '</article>';
            }
            function categoryControls(){
                var seen = {};
                var labels = [];
                customProducts.forEach(function(item){
                    var slug = item.category_slug || '';
                    if (!slug || seen[slug]) return;
                    seen[slug] = true;
                    labels.push({slug: slug, label: item.category_label || slug});
                });
                labels.sort(function(a,b){ return a.label.localeCompare(b.label); });
                return '<button type="button" class="ma-store-chip is-active" data-category="">All</button>' + labels.map(function(item){
                    return '<button type="button" class="ma-store-chip" data-category="' + esc(item.slug) + '">' + esc(item.label) + '</button>';
                }).join('');
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
                section.innerHTML = '<div class="ma-store-toolbar"><label class="ma-store-search"><span>Search</span><input type="search" placeholder="Search works, artists, merch" aria-label="Search store catalog"></label><div class="ma-store-chips" aria-label="Store categories">' + categoryControls() + '</div><div class="ma-store-count"><span class="ma-custom-store-count">' + esc(customProducts.length) + '</span> items</div></div><div class="ma-custom-store-active-filters" hidden></div><div class="ma-custom-store-grid__items">' + customProducts.map(productCard).join('') + '</div><p class="ma-custom-store-empty" hidden>No items match those filters.</p>';
                var placeholder = document.querySelector('.ma-store-grid-placeholder');
                if (placeholder && placeholder.parentNode) {
                    placeholder.parentNode.replaceChild(section, placeholder);
                } else {
                    anchor.parentNode.insertBefore(section, anchor);
                }
                document.querySelectorAll('.elementor-widget-woocommerce-products,.elementor-widget-eael-woo-product-carousel,.elementor-widget-wc-categories,.elementor-widget-wp-widget-woocommerce_product_search,.woocommerce.widget_product_search,.woocommerce-product-search,.wpfMainWrapper,.wpfFilterWrapper,.woocommerce-sidebar,aside.widget-area,.nv-sidebar-wrap,ul.products').forEach(function(el){
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
            function hideContradictoryEmptyStates(){
                if (!document.querySelector('.ma-custom-store-grid')) return;
                document.querySelectorAll('.woocommerce-info,.woocommerce-no-products-found,p').forEach(function(el){
                    var text = (el.textContent || '').trim().toLowerCase();
                    if (text === 'no items match those filters.' || text === 'no products were found matching your selection.') {
                        el.style.display = 'none';
                        el.setAttribute('aria-hidden', 'true');
                    }
                });
            }
            function decorate(){
                hideFeaturedSection();
                buildCustomGrid();
                applyCustomFilters();
                hideContradictoryEmptyStates();
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
                var searchInput = grid.querySelector('.ma-store-search input');
                var search = searchInput ? searchInput.value.trim().toLowerCase() : '';
                var activeChip = grid.querySelector('.ma-store-chip.is-active');
                var category = activeChip ? activeChip.getAttribute('data-category') || '' : '';
                var artistFilters = selectedFilterValues('artist');
                var mediumFilters = selectedFilterValues('medium');
                var visible = 0;
                grid.querySelectorAll('.ma-custom-store-card').forEach(function(card){
                    var searchOk = !search || (card.getAttribute('data-search') || '').indexOf(search) !== -1;
                    var categoryOk = !category || card.getAttribute('data-category') === category;
                    var artistOk = !artistFilters.length || artistFilters.some(function(filter){
                        return card.getAttribute('data-artist-slug') === filter.slug;
                    });
                    var mediumOk = !mediumFilters.length || mediumFilters.some(function(filter){
                        return card.getAttribute('data-medium-slug') === filter.slug;
                    });
                    var show = searchOk && categoryOk && artistOk && mediumOk;
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
                    hideContradictoryEmptyStates();
                }, 120);
            }
            decorate();
            hideFeaturedSection();
            cleanFilters();
            hideContradictoryEmptyStates();
            document.addEventListener('change', function(event){
                if (event.target && event.target.closest && event.target.closest('.wpfFilterWrapper')) {
                    window.setTimeout(function(){ cleanFilters(); refreshFilteredCatalog(); }, 50);
                    window.setTimeout(refreshFilteredCatalog, 350);
                }
            }, true);
            document.addEventListener('click', function(event){
                var chip = event.target && event.target.closest ? event.target.closest('.ma-store-chip') : null;
                if (chip) {
                    var grid = chip.closest('.ma-custom-store-grid');
                    if (grid) {
                        grid.querySelectorAll('.ma-store-chip').forEach(function(item){ item.classList.toggle('is-active', item === chip); });
                        applyCustomFilters();
                    }
                    return;
                }
                if (event.target && event.target.closest && event.target.closest('.wpfFilterWrapper')) {
                    window.setTimeout(function(){ cleanFilters(); refreshFilteredCatalog(); }, 120);
                    window.setTimeout(refreshFilteredCatalog, 500);
                }
            }, true);
            document.addEventListener('input', function(event){
                if (event.target && event.target.closest && event.target.closest('.ma-store-search')) {
                    applyCustomFilters();
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
            body.post-type-archive-product,body.tax-product_cat{font-family:' . esc_html($font_stack) . ';color:#141414;background:#fff}
            body.post-type-archive-product .nv-page-title-wrap,body.tax-product_cat .nv-page-title-wrap{margin:0 0 26px!important}
            body.post-type-archive-product .page-title,body.tax-product_cat .page-title,body.post-type-archive-product h1,body.tax-product_cat h1{font-family:' . esc_html($font_stack) . '!important;font-size:clamp(30px,3vw,42px)!important;line-height:1.08!important;font-weight:650!important;letter-spacing:0!important;color:#111!important}
            body.post-type-archive-product .neve-main,body.tax-product_cat .neve-main{font-family:' . esc_html($font_stack) . '!important}
            body.post-type-archive-product .nv-content-wrap h1,body.tax-product_cat .nv-content-wrap h1{margin:0 0 20px!important;font-size:34px!important;line-height:1.12!important;font-weight:560!important;letter-spacing:0!important;color:#111!important}
            body.post-type-archive-product .nv-content-wrap p,body.tax-product_cat .nv-content-wrap p{font-family:' . esc_html($font_stack) . ';font-size:15px;line-height:1.55;color:#262626}
            body.post-type-archive-product .nv-content-wrap>p strong,body.tax-product_cat .nv-content-wrap>p strong,body.post-type-archive-product .elementor-widget-text-editor strong,body.tax-product_cat .elementor-widget-text-editor strong,body.post-type-archive-product .elementor-widget-text-editor b,body.tax-product_cat .elementor-widget-text-editor b{font-weight:500!important}
            body.post-type-archive-product .nv-content-wrap a,body.tax-product_cat .nv-content-wrap a{color:#111;text-decoration:underline;text-underline-offset:3px;text-decoration-thickness:1px}
            body.post-type-archive-product .nv-content-wrap .button,body.tax-product_cat .nv-content-wrap .button,body.post-type-archive-product button,body.tax-product_cat button{font-family:' . esc_html($font_stack) . '!important;letter-spacing:0!important;border-radius:0!important}
            body.post-type-archive-product input[type="search"],body.tax-product_cat input[type="search"],body.post-type-archive-product .aws-container .aws-search-field{height:42px!important;border:1px solid #d9d6cf!important;border-radius:0!important;box-shadow:none!important;font-family:' . esc_html($font_stack) . '!important;font-size:14px!important;color:#111!important}
            body.post-type-archive-product input[type="search"]::placeholder,body.tax-product_cat input[type="search"]::placeholder{color:#777!important;opacity:1!important}
            body.post-type-archive-product .nv-sidebar-wrap,body.tax-product_cat .nv-sidebar-wrap{font-family:' . esc_html($font_stack) . '!important;color:#222}
            body.post-type-archive-product .nv-sidebar-wrap .widget,body.tax-product_cat .nv-sidebar-wrap .widget{margin-bottom:28px!important}
            body.post-type-archive-product .nv-sidebar-wrap h2,body.post-type-archive-product .nv-sidebar-wrap h3,body.post-type-archive-product .wpfFilterTitle,body.tax-product_cat .nv-sidebar-wrap h2,body.tax-product_cat .nv-sidebar-wrap h3,body.tax-product_cat .wpfFilterTitle{font-family:' . esc_html($font_stack) . '!important;font-size:13px!important;line-height:1.35!important;font-weight:620!important;letter-spacing:0!important;color:#111!important;text-transform:none!important}
            body.post-type-archive-product .wpfFilterWrapper,body.tax-product_cat .wpfFilterWrapper{margin-bottom:22px!important}
            body.post-type-archive-product .wpfFilterContent,body.tax-product_cat .wpfFilterContent{font-family:' . esc_html($font_stack) . '!important}
            body.post-type-archive-product .wpfFilterButton.wpfButton,body.tax-product_cat .wpfFilterButton.wpfButton{border-radius:0!important;background:#111!important;color:#fff!important;border:1px solid #111!important;font-family:' . esc_html($font_stack) . '!important;font-size:13px!important;font-weight:600!important;letter-spacing:0!important}
            body.post-type-archive-product .wpfClearButton,body.tax-product_cat .wpfClearButton{font-family:' . esc_html($font_stack) . '!important;font-size:13px!important;color:#555!important;text-decoration:underline!important;text-underline-offset:3px}
            .ma-shop-on-view{clear:both;width:100%;margin:0 0 46px;padding:0 0 34px;border-bottom:1px solid #dedbd4;background:#fff}
            .ma-shop-section-header{clear:both;display:flex;align-items:flex-end;justify-content:space-between;gap:18px;margin:0 0 22px}
            .ma-shop-section-header h2{margin:0;font-family:' . esc_html($font_stack) . ';font-size:23px;line-height:1.15;font-weight:540;letter-spacing:0;color:#111}
            .ma-shop-section-header p{margin:0;color:#666;font-family:' . esc_html($font_stack) . ';font-size:13px;line-height:1.4;text-align:right}
            .ma-shop-on-view__grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:40px 34px;align-items:start}
            .ma-art-card{display:block;min-width:0;color:inherit;text-decoration:none;background:#fff}
            .ma-art-card img,.woocommerce ul.products.ma-shop-catalog-grid img{display:block;width:100%;height:100%;aspect-ratio:4/3;object-fit:cover;background:#f4f2ee;border-radius:0!important;box-shadow:none!important;transition:opacity .18s ease}
            .ma-shop-catalog-card img{display:block;width:100%;height:auto;background:#f4f2ee;border-radius:0!important;box-shadow:none!important;transition:opacity .18s ease}
            .ma-art-card:hover img,.ma-custom-store-card__image:hover img,.ma-shop-catalog-card:hover img{opacity:.92}
            .ma-art-card h3,.woocommerce ul.products.ma-shop-catalog-grid .woocommerce-loop-product__title,.ma-shop-catalog-card .eael-product-title,.ma-shop-catalog-card .eael-product-title a{margin:14px 0 9px!important;font-family:' . esc_html($font_stack) . '!important;font-size:15.5px!important;line-height:1.35!important;font-weight:500!important;color:#111!important;letter-spacing:0!important;text-align:left!important;text-decoration:none!important}
            .ma-art-card__meta,.ma-art-card__exhibit,.ma-on-view-line{margin:.35rem 0 8px;color:#555;font-family:' . esc_html($font_stack) . ';font-size:13px;line-height:1.45;text-align:left}
            .ma-shop-catalog-meta{display:grid;gap:5px;margin:0 0 13px;color:#2b2b2b;font-family:' . esc_html($font_stack) . ';font-size:13px;line-height:1.4;text-align:left}
            .ma-shop-catalog-meta div{display:block}
            .ma-shop-catalog-meta span{display:inline-block;margin-right:7px;color:#747474;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.075em}
            .ma-art-card__price,.woocommerce ul.products.ma-shop-catalog-grid .price,.ma-shop-catalog-card .eael-product-price{margin:0!important;color:#111!important;font-family:' . esc_html($font_stack) . '!important;font-size:15.5px!important;line-height:1.25!important;font-weight:650!important;text-align:left!important;font-variant-numeric:tabular-nums}
            .ma-hide-elementor-products,.ma-hide-featured-store-section,.woocommerce ul.products.ma-hide-elementor-products,.elementor-widget-wc-categories.ma-hide-elementor-products,body.post-type-archive-product .elementor-widget-wp-widget-woocommerce_product_search,body.post-type-archive-product .woocommerce.widget_product_search,body.post-type-archive-product .woocommerce-product-search,body.post-type-archive-product .wpfMainWrapper,body.post-type-archive-product .wpfFilterWrapper,body.post-type-archive-product .woocommerce-sidebar,body.post-type-archive-product aside.widget-area,body.post-type-archive-product .nv-sidebar-wrap{display:none!important}
            body.post-type-archive-product .elementor-1381>.elementor-section>.elementor-container,
            body.post-type-archive-product .elementor-1381 .elementor-section.elementor-top-section>.elementor-container,
            body.post-type-archive-product .elementor-1381 .elementor-section.elementor-inner-section>.elementor-container{width:calc(100vw - 64px)!important;max-width:1480px!important}
            body.post-type-archive-product .elementor-element-450fa30>.elementor-container{display:block!important;width:100%!important;max-width:1480px!important}
            body.post-type-archive-product .elementor-element-450fa30 .elementor-column{width:100%!important;max-width:100%!important}
            body.post-type-archive-product .elementor-element-450fa30 .elementor-column:not(:has(.ma-custom-store-grid)):has(.wpfMainWrapper),
            body.post-type-archive-product .elementor-element-450fa30 .elementor-column:not(:has(.ma-custom-store-grid)):has(.elementor-widget-wc-categories){display:none!important}
            body.post-type-archive-product .hfg_header.site-header,body.post-type-archive-product #masthead{position:fixed!important;top:0!important;left:0!important;right:0!important;width:100%!important;z-index:1000!important;background:#fff!important}
            body.post-type-archive-product .wrapper{padding-top:93px!important}
            body.admin-bar.post-type-archive-product .hfg_header.site-header,body.admin-bar.post-type-archive-product #masthead{top:32px!important}
            .ma-store-grid-placeholder{clear:both;display:block;min-height:820px}
            .ma-custom-store-grid{box-sizing:border-box;clear:both;display:block!important;width:100%;max-width:100%;margin:0!important;padding:92px 0 48px!important;border-top:0;text-align:left;font-family:' . esc_html($font_stack) . '}
            .ma-custom-store-grid *{box-sizing:border-box}
            .ma-store-toolbar{position:fixed;top:93px;left:64px;right:24px;z-index:999;display:grid!important;grid-template-columns:minmax(260px,420px) minmax(0,1fr) auto;gap:18px;align-items:center;width:auto;max-width:1480px;margin:0 auto!important;padding:14px 0!important;border-bottom:1px solid #dedbd4;background:#fff;transform:none}
            body.admin-bar.post-type-archive-product .ma-store-toolbar{top:125px}
            .ma-store-search{display:block;margin:0!important}
            .ma-store-search span{position:absolute!important;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
            .ma-store-search input{display:block;width:100%!important;height:42px!important;margin:0!important;padding:0 12px!important;border:1px solid #d8d3ca!important;border-radius:0!important;background:#fff!important;color:#111!important;font-family:' . esc_html($font_stack) . '!important;font-size:14px!important;line-height:42px!important;box-shadow:none!important}
            .ma-store-chips{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
            .ma-store-chip{display:inline-flex!important;align-items:center!important;justify-content:center!important;min-height:34px!important;margin:0!important;padding:0 13px!important;border:1px solid #d7d2ca!important;border-radius:999px!important;background:#fff!important;color:#111!important;font-family:' . esc_html($font_stack) . '!important;font-size:13px!important;line-height:1!important;font-weight:560!important;letter-spacing:0!important;text-transform:none!important;cursor:pointer!important}
            .ma-store-chip.is-active{border-color:#111!important;background:#111!important;color:#fff!important}
            .ma-store-count{color:#555;font-family:' . esc_html($font_stack) . ';font-size:13px;font-weight:560;text-align:right;white-space:nowrap}
            .ma-custom-store-grid [hidden]{display:none!important}
            .ma-custom-store-active-filters{margin:-12px 0 24px!important;color:#555;font-family:' . esc_html($font_stack) . ';font-size:13px;line-height:1.4}
            .ma-custom-store-grid--loading .ma-custom-store-grid__items{opacity:.45;transition:opacity .15s ease}
            .ma-custom-store-grid__items{display:block!important;columns:4 260px;column-gap:34px;width:100%}
            .ma-custom-store-empty{margin:20px 0!important;color:#555;font-family:' . esc_html($font_stack) . ';font-size:15px}
            .ma-custom-store-card{display:inline-block!important;width:100%;break-inside:avoid;min-width:0;margin:0 0 42px!important;padding:0!important;background:#fff;color:#141414;font-family:' . esc_html($font_stack) . '}
            .ma-custom-store-card__image{display:block;width:100%;overflow:visible;background:#f7f5f1;text-decoration:none}
            .ma-custom-store-card__image img{display:block;width:100%;height:auto;max-height:none;object-fit:contain;border-radius:0!important;box-shadow:none!important;transition:opacity .18s ease}
            .ma-custom-store-card__type{margin:13px 0 7px;color:#777;font-family:' . esc_html($font_stack) . ';font-size:10.5px;font-weight:720;letter-spacing:.085em;text-transform:uppercase}
            .ma-custom-store-card h3{display:block!important;margin:0 0 7px!important;padding:0!important;font-family:' . esc_html($font_stack) . '!important;font-size:15px!important;line-height:1.32!important;font-weight:560!important;letter-spacing:0!important;text-align:left!important}
            .ma-custom-store-card h3 a{color:#111!important;text-decoration:none!important}
            .ma-custom-store-card h3 a:hover{text-decoration:underline!important;text-underline-offset:3px}
            .ma-custom-store-card__artist,.ma-custom-store-card__medium{margin:0 0 5px!important;color:#555!important;font-family:' . esc_html($font_stack) . '!important;font-size:13px!important;line-height:1.35!important}
            .ma-custom-store-card__price{display:block!important;margin:10px 0 0!important;color:#111!important;font-family:' . esc_html($font_stack) . '!important;font-size:14px!important;line-height:1.25!important;font-weight:650!important;text-align:left!important;font-variant-numeric:tabular-nums}
            .woocommerce ul.products.ma-shop-catalog-grid{clear:both;display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;column-gap:42px!important;row-gap:58px!important;width:100%!important;height:auto!important;margin:0!important;align-items:start!important;list-style:none!important}
            .woocommerce ul.products.ma-shop-catalog-grid>li.product{position:relative!important;left:auto!important;top:auto!important;float:none!important;display:block!important;width:auto!important;min-width:0!important;margin:0!important;padding:0!important;transform:none!important;background:#fff!important;border:0!important;box-shadow:none!important}
            .woocommerce ul.products.ma-shop-catalog-grid .woocommerce-LoopProduct-link,.woocommerce ul.products.ma-shop-catalog-grid .product-image-wrap,.woocommerce ul.products.ma-shop-catalog-grid .image-wrap{display:block!important;width:100%!important;margin:0!important;padding:0!important;border:0!important;border-radius:0!important;box-shadow:none!important;overflow:hidden!important;aspect-ratio:4/3!important}
            .woocommerce ul.products.ma-shop-catalog-grid .button,.woocommerce ul.products.ma-shop-catalog-grid .added_to_cart,.woocommerce ul.products.ma-shop-catalog-grid .star-rating{display:none!important}
            .woocommerce ul.products.ma-shop-catalog-grid::before,.woocommerce ul.products.ma-shop-catalog-grid::after{content:none!important;display:none!important}
            body.woocommerce.archive .widget-area,body.tax-product_cat .widget-area{float:left}
            .ma-clean-artist-filter .wpfFilterVerScroll{max-height:360px!important;padding-right:6px}
            .ma-clean-artist-filter .wpfLiLabel{display:flex!important;align-items:center;gap:8px;margin:0!important;padding:5px 0!important;color:#222;font-family:' . esc_html($font_stack) . ';font-size:13px;line-height:1.3}
            .ma-clean-artist-filter .wpfFilterTaxNameWrapper,.ma-clean-artist-filter .wpfValue{white-space:normal!important;word-break:normal!important}
            .ma-clean-artist-filter .wpfCount{margin-left:4px;color:#777;font-size:12px}
            @media(min-width:1200px){body.post-type-archive-product .neve-main>.container,body.tax-product_cat .neve-main>.container{max-width:1480px}.archive.woocommerce .neve-main>.shop-container .nv-shop.col{max-width:100%!important}}
            @media(max-width:1024px){body.post-type-archive-product .hfg_header.site-header,body.post-type-archive-product #masthead{position:sticky!important}.ma-custom-store-grid{padding-top:0!important}.ma-store-toolbar{position:static;grid-template-columns:1fr;width:100%;transform:none}.ma-store-count{text-align:left}.ma-custom-store-grid__items{columns:2 240px!important}.ma-shop-on-view__grid,.woocommerce ul.products.ma-shop-catalog-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important;column-gap:26px!important;row-gap:44px!important}}
            @media(max-width:640px){.ma-shop-section-header{display:block}.ma-shop-section-header p{margin-top:6px!important;text-align:left!important}.ma-shop-on-view__grid,.woocommerce ul.products.ma-shop-catalog-grid{grid-template-columns:1fr!important}.ma-custom-store-grid__items{columns:1!important}}
        </style>';
    }

    private static function current_on_view_products(): array {
        $products = wc_get_products([
            'status' => 'publish',
            'limit' => -1,
            'return' => 'objects',
            'tax_query' => [[
                'taxonomy' => 'product_cat',
                'field' => 'slug',
                'terms' => ['artwork'],
                'include_children' => true,
                'operator' => 'IN',
            ]],
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
            if (!self::product_is_artwork($product->get_id())) {
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

    private static function product_is_artwork(int $product_id): bool {
        return has_term('artwork', 'product_cat', $product_id);
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
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => empty($filters['artist']) && empty($filters['medium']) ? 300 : 400,
            'fields' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
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
        if (!empty($tax_query)) {
            $args['tax_query'] = count($tax_query) > 1 ? array_merge(['relation' => 'AND'], $tax_query) : $tax_query;
        }

        $product_ids = get_posts($args);
        $items = [];
        foreach ($product_ids as $product_id) {
            $product = wc_get_product((int) $product_id);
            if (!($product instanceof WC_Product)) {
                continue;
            }
            if (!self::is_store_catalog_product($product)) {
                continue;
            }
            $items[] = self::catalog_item_for_product($product);
        }
        return $items;
    }

    private static function is_store_catalog_product(WC_Product $product): bool {
        $product_id = (int) $product->get_id();
        if (!$product_id || !$product->is_visible() || !$product->is_in_stock() || !$product->is_purchasable()) {
            return false;
        }
        if (!$product->get_image_id()) {
            return false;
        }
        if (has_term(['mas-house-collection', 'mas-house-permanent-collection'], 'product_cat', $product_id)) {
            return false;
        }
        if (self::is_artwork_product($product) && !has_term(['artwork-for-sale', 'available-artwork'], 'product_cat', $product_id)) {
            return false;
        }
        return true;
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
        $category = self::store_catalog_category($product);
        $price_text = wp_strip_all_tags($product->get_price_html());
        return [
            'id' => $product->get_id(),
            'title' => wp_strip_all_tags($product->get_name()),
            'url' => get_permalink($product->get_id()),
            'image' => $image ?: '',
            'price_html' => wp_kses_post($product->get_price_html()),
            'price_text' => $price_text,
            'artist' => self::product_detail_value($product, 'Artist'),
            'medium' => self::product_detail_value($product, 'Medium') ?: self::product_detail_value($product, 'Series'),
            'category_label' => $category['label'],
            'category_slug' => $category['slug'],
            'rows' => self::product_detail_rows($product),
        ];
    }

    private static function store_catalog_category(WC_Product $product): array {
        $product_id = (int) $product->get_id();
        if (self::is_artwork_product($product)) {
            return ['label' => 'Artwork', 'slug' => 'artwork'];
        }
        $preferred = [
            'clothing' => 'Merch',
            'consumable' => 'Goods',
            'book' => 'Books',
            'zine' => 'Zines',
        ];
        foreach ($preferred as $slug => $label) {
            if (has_term($slug, 'product_cat', $product_id)) {
                return ['label' => $label, 'slug' => $slug];
            }
        }
        $terms = get_the_terms($product_id, 'product_cat');
        if (is_array($terms)) {
            foreach ($terms as $term) {
                if ($term instanceof WP_Term && !in_array($term->slug, ['uncategorized'], true)) {
                    return ['label' => self::text($term->name), 'slug' => self::text($term->slug)];
                }
            }
        }
        return ['label' => 'Store', 'slug' => 'store'];
    }

    private static function contextual_related_products_section(WC_Product $product): string {
        if (!self::is_artwork_product($product)) {
            return '';
        }
        $ids = self::filter_contextual_related_products([], $product->get_id(), ['posts_per_page' => 4]);
        if (!$ids) {
            return '';
        }
        $heading = 'Related Artworks';
        ob_start();
        ?>
        <section class="ma-contextual-related-products" aria-label="<?php echo esc_attr($heading); ?>">
            <header>
                <h2><?php echo esc_html($heading); ?></h2>
                <p>Selected from the same artist, material, series, or collection.</p>
            </header>
            <div class="ma-contextual-related-products__grid">
                <?php foreach ($ids as $id) :
                    $related = wc_get_product((int) $id);
                    if (!$related) {
                        continue;
                    }
                    $item = self::catalog_item_for_product($related);
                    ?>
                    <article class="ma-contextual-related-products__item">
                        <a href="<?php echo esc_url($item['url']); ?>">
                            <?php if ($item['image']) : ?>
                                <img src="<?php echo esc_url($item['image']); ?>" alt="<?php echo esc_attr($item['title']); ?>" loading="lazy">
                            <?php endif; ?>
                            <h3><?php echo esc_html($item['title']); ?></h3>
                        </a>
                        <?php if ($item['artist'] || $item['medium']) : ?>
                            <p><?php echo esc_html(implode(' · ', array_filter([$item['artist'], $item['medium']]))); ?></p>
                        <?php endif; ?>
                        <?php if ($item['price_html']) : ?>
                            <div class="ma-contextual-related-products__price"><?php echo wp_kses_post($item['price_html']); ?></div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private static function is_artwork_product(WC_Product $product): bool {
        $product_id = (int) $product->get_id();
        if (!$product_id) {
            return false;
        }

        if (has_term('artwork', 'product_cat', $product_id)) {
            return true;
        }

        $terms = get_the_terms($product_id, 'product_cat');
        if (is_wp_error($terms) || !$terms) {
            return false;
        }

        foreach ($terms as $term) {
            if (!$term instanceof WP_Term) {
                continue;
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
        }

        return false;
    }

    public static function filter_contextual_related_products(array $related_posts, int $product_id, array $args): array {
        if (is_admin() || !function_exists('wc_get_product')) {
            return $related_posts;
        }
        $product = wc_get_product($product_id);
        if (!$product) {
            return $related_posts;
        }
        if (!self::is_artwork_product($product)) {
            return $related_posts;
        }

        $limit = max(1, (int) ($args['posts_per_page'] ?? count($related_posts) ?: 4));
        $artist = self::product_artist_name($product) ?: self::product_detail_value($product, 'Artist');
        $medium = self::text(get_post_meta($product_id, 'material', true)) ?: self::product_detail_value($product, 'Medium');
        $series = self::text(get_post_meta($product_id, 'ma_artwork_series', true)) ?: self::product_detail_value($product, 'Series');

        $ids = [];
        if ($artist) {
            $ids = array_merge($ids, self::related_product_ids_by_artist($product_id, $artist, $limit));
        }
        if (count($ids) < $limit && $medium) {
            $ids = array_merge($ids, self::related_product_ids_by_meta($product_id, 'material', $medium, $limit));
        }
        if (count($ids) < $limit && $series) {
            $ids = array_merge($ids, self::related_product_ids_by_meta($product_id, 'ma_artwork_series', $series, $limit));
        }
        if (count($ids) < $limit) {
            $ids = array_merge($ids, self::related_product_ids_by_category($product_id, $limit));
        }

        $ids = array_values(array_diff(array_unique(array_map('intval', $ids)), [$product_id]));
        return $ids ? array_slice($ids, 0, $limit) : $related_posts;
    }

    private static function related_product_ids_by_meta(int $product_id, string $key, string $value, int $limit): array {
        $value = self::text($value);
        if (!$value) {
            return [];
        }
        return get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'fields' => 'ids',
            'post__not_in' => [$product_id],
            'orderby' => ['date' => 'DESC', 'ID' => 'DESC'],
            'meta_query' => [[
                'key' => $key,
                'value' => $value,
                'compare' => '=',
            ]],
        ]);
    }

    private static function related_product_ids_by_artist(int $product_id, string $artist_name, int $limit): array {
        $ids = self::related_product_ids_by_meta($product_id, 'ma_artist_name', $artist_name, $limit);
        if (count($ids) >= $limit) {
            return $ids;
        }
        $all_ids = get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post__not_in' => [$product_id],
            'orderby' => ['date' => 'DESC', 'ID' => 'DESC'],
        ]);
        foreach ($all_ids as $id) {
            if (in_array((int) $id, $ids, true)) {
                continue;
            }
            $product = wc_get_product((int) $id);
            if ($product instanceof WC_Product && self::product_matches_artist($product, $artist_name)) {
                $ids[] = (int) $id;
            }
            if (count($ids) >= $limit) {
                break;
            }
        }
        return array_slice(array_values(array_unique(array_map('intval', $ids))), 0, $limit);
    }

    private static function related_product_ids_by_category(int $product_id, int $limit): array {
        $terms = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
        if (is_wp_error($terms) || !$terms) {
            return [];
        }
        $uncategorized = get_term_by('slug', 'uncategorized', 'product_cat');
        if ($uncategorized instanceof WP_Term) {
            $terms = array_values(array_diff(array_map('intval', $terms), [(int) $uncategorized->term_id]));
        }
        if (!$terms) {
            return [];
        }
        return get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'fields' => 'ids',
            'post__not_in' => [$product_id],
            'orderby' => ['date' => 'DESC', 'ID' => 'DESC'],
            'tax_query' => [[
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $terms,
            ]],
        ]);
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
        $artist = self::product_artist_name($product) ?: self::infer_artist_name_from_text($source);
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
        if (self::text($_GET['s'] ?? '') !== '' || (function_exists('get_query_var') && self::text(get_query_var('s')) !== '')) {
            return false;
        }
        if (function_exists('is_paged') && is_paged()) {
            return false;
        }
        if (function_exists('is_shop') && is_shop()) {
            return true;
        }
        if (function_exists('is_search') && is_search()) {
            return false;
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

    private static function split_list(string $value): array {
        if (!$value) {
            return [];
        }
        $parts = preg_split('/[,;|]+/', $value) ?: [];
        $clean = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part) {
                $clean[$part] = $part;
            }
        }
        return array_values($clean);
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
            'last_artist_directory_sync_at' => '',
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

