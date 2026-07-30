<?php
/**
 * Plugin Name: Ma's House Site Integrity Guard
 * Description: Keeps protected public pages, their shortcodes, and the production theme from being reverted by deployments or updates.
 * Version: 1.0.0
 * Author: Ma's House
 */

defined( 'ABSPATH' ) || exit;

/**
 * These pages intentionally keep only a shortcode in post_content. Their full
 * layouts live in independent must-use plugins so theme and ordinary plugin
 * updates cannot remove them.
 */
function ma_integrity_protected_pages(): array {
	return array(
		40576 => array(
			'slug'      => 'visit',
			'shortcode' => '[ma_visit_contact]',
			'tag'       => 'ma_visit_contact',
			'callback'  => 'ma_visit_contact_shortcode',
		),
		42601 => array(
			'slug'      => 'collection-artworks',
			'shortcode' => '[ma_collection_artworks]',
			'tag'       => 'ma_collection_artworks',
			'callback'  => 'ma_collection_artworks_shortcode',
		),
	);
}

function ma_integrity_bypass_enabled(): bool {
	return defined( 'MA_ALLOW_PROTECTED_SITE_CHANGES' ) && MA_ALLOW_PROTECTED_SITE_CHANGES;
}

/**
 * Re-register after ordinary plugins initialize. This prevents a plugin update
 * from replacing or removing the protected shortcode handlers.
 */
function ma_integrity_register_protected_shortcodes(): void {
	foreach ( ma_integrity_protected_pages() as $config ) {
		if ( function_exists( $config['callback'] ) ) {
			add_shortcode( $config['tag'], $config['callback'] );
		}
	}
}
add_action( 'init', 'ma_integrity_register_protected_shortcodes', PHP_INT_MAX );

/**
 * Block accidental edits, imports, or deployments from changing the protected
 * page slugs/content. Define MA_ALLOW_PROTECTED_SITE_CHANGES as true in
 * wp-config.php for an intentional migration.
 */
function ma_integrity_preserve_protected_page_data( array $data, array $postarr ): array {
	if ( ma_integrity_bypass_enabled() ) {
		return $data;
	}

	$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
	$pages   = ma_integrity_protected_pages();
	if ( ! isset( $pages[ $post_id ] ) ) {
		return $data;
	}

	$data['post_status']  = 'publish';
	$data['post_name']    = $pages[ $post_id ]['slug'];
	$data['post_content'] = $pages[ $post_id ]['shortcode'];

	return $data;
}
add_filter( 'wp_insert_post_data', 'ma_integrity_preserve_protected_page_data', PHP_INT_MAX, 2 );

/**
 * Repair database drift on the next WordPress request. The check is tiny (two
 * indexed post lookups) and is throttled to once every five minutes when the
 * site is healthy.
 */
function ma_integrity_self_heal_protected_pages(): void {
	if ( ma_integrity_bypass_enabled() || wp_installing() ) {
		return;
	}

	if ( get_transient( 'ma_integrity_pages_healthy' ) ) {
		return;
	}

	$repaired = array();
	foreach ( ma_integrity_protected_pages() as $post_id => $config ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			error_log( sprintf( 'Ma site integrity: protected page %d is missing.', $post_id ) );
			continue;
		}

		$update = array( 'ID' => $post_id );
		if ( 'publish' !== $post->post_status ) {
			$update['post_status'] = 'publish';
		}
		if ( $config['slug'] !== $post->post_name ) {
			$update['post_name'] = $config['slug'];
		}
		if ( $config['shortcode'] !== trim( (string) $post->post_content ) ) {
			$update['post_content'] = $config['shortcode'];
		}

		if ( count( $update ) > 1 ) {
			$result = wp_update_post( wp_slash( $update ), true );
			if ( is_wp_error( $result ) ) {
				error_log( sprintf( 'Ma site integrity: page %d repair failed: %s', $post_id, $result->get_error_message() ) );
				continue;
			}
			$repaired[] = $post_id;
		}
	}

	if ( $repaired ) {
		flush_rewrite_rules( false );
		delete_transient( 'ma_integrity_pages_healthy' );
		update_option(
			'ma_integrity_last_repair',
			array(
				'time'  => gmdate( 'c' ),
				'pages' => $repaired,
			),
			false
		);
	} else {
		set_transient( 'ma_integrity_pages_healthy', 1, 5 * MINUTE_IN_SECONDS );
	}
}
add_action( 'init', 'ma_integrity_self_heal_protected_pages', PHP_INT_MAX - 1 );

/**
 * The current public site is built on Neve. Prevent update/import tools from
 * silently switching the active theme. Intentional theme migrations can use
 * the same explicit bypass constant documented above.
 */
function ma_integrity_preserve_stylesheet( $new_value, $old_value ) {
	if ( ma_integrity_bypass_enabled() || 'neve' === $new_value ) {
		return $new_value;
	}

	error_log( sprintf( 'Ma site integrity: blocked stylesheet change from %s to %s.', $old_value, $new_value ) );
	return $old_value;
}
add_filter( 'pre_update_option_stylesheet', 'ma_integrity_preserve_stylesheet', PHP_INT_MAX, 2 );
add_filter( 'pre_update_option_template', 'ma_integrity_preserve_stylesheet', PHP_INT_MAX, 2 );

/**
 * Machine-readable verification for deployments and support checks.
 */
function ma_integrity_status(): array {
	$pages = array();
	foreach ( ma_integrity_protected_pages() as $post_id => $config ) {
		$post = get_post( $post_id );
		$pages[ $post_id ] = array(
			'exists'               => $post instanceof WP_Post,
			'published'            => $post instanceof WP_Post && 'publish' === $post->post_status,
			'slug_ok'              => $post instanceof WP_Post && $config['slug'] === $post->post_name,
			'content_ok'           => $post instanceof WP_Post && $config['shortcode'] === trim( (string) $post->post_content ),
			'shortcode_registered' => shortcode_exists( $config['tag'] ),
		);
	}

	return array(
		'theme_ok' => 'neve' === get_option( 'stylesheet' ) && 'neve' === get_option( 'template' ),
		'pages'    => $pages,
	);
}
