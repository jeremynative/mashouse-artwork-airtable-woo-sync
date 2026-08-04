<?php
/**
 * Plugin Name: Ma's House Site Integrity Guard
 * Description: Keeps protected public pages, their shortcodes, and the production theme from being reverted by deployments or updates.
 * Version: 1.0.1
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
 * The homepage Press feed must be an allow-list. Excluding the historical
 * Resident Artists category is not sufficient because newer artist profiles
 * use the broader Artists category and would otherwise leak into Latest News.
 */
function ma_integrity_repair_home_news_widget(): void {
	if ( ma_integrity_bypass_enabled() || wp_installing() ) {
		return;
	}

	if ( get_transient( 'ma_integrity_home_news_healthy' ) ) {
		return;
	}

	$home_id    = 97;
	$widget_id  = 'efa97c0';
	$news_term  = '226';
	$raw_data   = get_post_meta( $home_id, '_elementor_data', true );
	$elements   = is_string( $raw_data ) ? json_decode( $raw_data, true ) : $raw_data;
	$was_fixed  = false;
	$was_found  = false;

	if ( ! is_array( $elements ) ) {
		error_log( 'Ma site integrity: homepage Elementor data is invalid.' );
		return;
	}

	ma_integrity_set_news_widget_terms( $elements, $widget_id, $news_term, $was_found, $was_fixed );

	if ( ! $was_found ) {
		error_log( sprintf( 'Ma site integrity: homepage news widget %s is missing.', $widget_id ) );
		return;
	}

	if ( $was_fixed ) {
		$encoded = wp_json_encode( $elements );
		if ( false === $encoded || false === update_post_meta( $home_id, '_elementor_data', wp_slash( $encoded ) ) ) {
			error_log( 'Ma site integrity: homepage news widget repair failed.' );
			return;
		}

		delete_post_meta( $home_id, '_elementor_element_cache' );
		delete_post_meta( $home_id, '_elementor_css' );
		delete_transient( 'ma_integrity_home_news_healthy' );
		update_option( 'ma_integrity_home_news_last_repair', gmdate( 'c' ), false );
		return;
	}

	set_transient( 'ma_integrity_home_news_healthy', 1, 5 * MINUTE_IN_SECONDS );
}
add_action( 'init', 'ma_integrity_repair_home_news_widget', PHP_INT_MAX - 2 );

function ma_integrity_set_news_widget_terms( array &$elements, string $widget_id, string $news_term, bool &$was_found, bool &$was_fixed ): void {
	foreach ( $elements as &$element ) {
		if ( ! is_array( $element ) ) {
			continue;
		}

		if ( isset( $element['id'] ) && $widget_id === (string) $element['id'] ) {
			$was_found = true;
			$settings  = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
			$expected  = array( $news_term );

			if ( array( 'terms' ) !== ( $settings['posts_include'] ?? array() ) ) {
				$settings['posts_include'] = array( 'terms' );
				$was_fixed = true;
			}
			if ( $expected !== array_values( (array) ( $settings['posts_include_term_ids'] ?? array() ) ) ) {
				$settings['posts_include_term_ids'] = $expected;
				$was_fixed = true;
			}
			if ( isset( $settings['posts_exclude'], $settings['posts_exclude_term_ids'] ) ) {
				unset( $settings['posts_exclude'], $settings['posts_exclude_term_ids'] );
				$was_fixed = true;
			}

			$element['settings'] = $settings;
			return;
		}

		if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
			ma_integrity_set_news_widget_terms( $element['elements'], $widget_id, $news_term, $was_found, $was_fixed );
			if ( $was_found ) {
				return;
			}
		}
	}
	unset( $element );
}

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
		'home_news_guarded' => get_transient( 'ma_integrity_home_news_healthy' ) || (bool) get_option( 'ma_integrity_home_news_last_repair' ),
		'pages'    => $pages,
	);
}
