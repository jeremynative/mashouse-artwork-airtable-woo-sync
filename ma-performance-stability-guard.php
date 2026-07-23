<?php
/**
 * Plugin Name: Ma's House Performance Stability Guard
 * Description: Emergency public-page stability guard for Ma's House. Keeps expensive background work out of anonymous page loads and improves cacheability.
 * Version: 0.1.0
 * Author: Ma's House
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MA_STABILITY_LAST_CRON_SPAWN', 'ma_stability_last_cron_spawn' );
define( 'MA_STABILITY_CRON_THROTTLE', 10 * MINUTE_IN_SECONDS );

add_filter( 'pre_spawn_cron', 'ma_stability_throttle_frontend_cron_spawn', 1 );
function ma_stability_throttle_frontend_cron_spawn( $pre ) {
	if ( ma_stability_allow_cron_for_request() ) {
		return $pre;
	}

	$last_spawn = (int) get_transient( MA_STABILITY_LAST_CRON_SPAWN );
	if ( $last_spawn && ( time() - $last_spawn ) < MA_STABILITY_CRON_THROTTLE ) {
		return true;
	}

	set_transient( MA_STABILITY_LAST_CRON_SPAWN, time(), MA_STABILITY_CRON_THROTTLE );
	return $pre;
}

function ma_stability_allow_cron_for_request(): bool {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return true;
	}

	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return false;
	}

	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	if ( preg_match( '#/(wp-cron\.php|wp-admin/|wp-login\.php)#', $uri ) ) {
		return true;
	}

	return false;
}

add_action( 'send_headers', 'ma_stability_public_cache_headers', PHP_INT_MAX );
function ma_stability_public_cache_headers(): void {
	if ( ! ma_stability_is_cacheable_public_request() ) {
		return;
	}

	header_remove( 'Set-Cookie' );
	header_remove( 'Pragma' );
	header_remove( 'Expires' );
	header_remove( 'Cache-Control' );
	header( 'Cache-Control: public, max-age=300, s-maxage=14400, stale-while-revalidate=3600' );
}

function ma_stability_is_cacheable_public_request(): bool {
	if ( is_user_logged_in() || is_admin() || wp_doing_ajax() || is_search() || is_preview() || is_404() ) {
		return false;
	}

	foreach ( array( 'add-to-cart', 'wc-ajax', 'giveDonationFormInIframe', 'give-embed' ) as $cache_bypass_key ) {
		if ( isset( $_GET[ $cache_bypass_key ] ) ) {
			return false;
		}
	}

	if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
		return false;
	}

	if ( function_exists( 'is_woocommerce' ) && is_woocommerce() ) {
		return false;
	}

	return true;
}

add_action( 'shutdown', 'ma_stability_capture_home_fallback', 0 );
function ma_stability_capture_home_fallback(): void {
	if ( ! is_front_page() || is_user_logged_in() || is_admin() || wp_doing_ajax() || http_response_code() >= 400 ) {
		return;
	}

	$elapsed = timer_stop( 0, 3 );
	if ( $elapsed > 8 ) {
		error_log( 'Ma stability guard: slow homepage render ' . $elapsed . 's' );
	}
}
