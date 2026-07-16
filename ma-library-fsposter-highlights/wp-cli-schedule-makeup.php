<?php
/**
 * Schedules a one-off replacement library highlight for FS Poster.
 *
 * Usage:
 * wp eval-file wp-cli-schedule-makeup.php -- "2026-07-16 18:00:00" "Rehearsals for Living"
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'ma_lfh_create_highlight_post' ) ) {
	require_once WP_PLUGIN_DIR . '/ma-library-fsposter-highlights/ma-library-fsposter-highlights.php';
}

$time  = isset( $args[0] ) ? ma_lfh_clean_text( $args[0] ) : wp_date( 'Y-m-d 18:00:00' );
$avoid = isset( $args[1] ) ? strtolower( ma_lfh_clean_text( $args[1] ) ) : '';

if ( $avoid ) {
	$avoid_posts = get_posts(
		array(
			'post_type'      => 'book',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			's'              => $avoid,
			'fields'         => 'ids',
		)
	);
	foreach ( $avoid_posts as $avoid_book_id ) {
		ma_lfh_remember_highlighted_book( (int) $avoid_book_id );
	}
}

$post_id = ma_lfh_create_highlight_post( 'publish', false );
if ( ! $post_id ) {
	WP_CLI::error( 'No eligible replacement library book could be selected.' );
}

$image_id = (int) get_post_thumbnail_id( $post_id );
if ( ! $image_id ) {
	WP_CLI::error( 'Replacement highlight was created but does not have a featured image.' );
}

ma_lfh_sync_fsposter_planner( $post_id, $image_id, $time );
WP_CLI::success(
	wp_json_encode(
		array(
			'post_id'         => (int) $post_id,
			'title'           => get_the_title( $post_id ),
			'book_id'         => (int) get_post_meta( $post_id, '_ma_lfh_book_id', true ),
			'next_execute_at' => $time,
		)
	)
);
