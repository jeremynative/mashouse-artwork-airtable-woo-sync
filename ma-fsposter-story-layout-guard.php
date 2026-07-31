<?php
/**
 * Plugin Name: Ma FS Poster Story Layout Guard
 * Description: Prevents FS Poster from layering duplicate text and nested templates onto Ma's House event stories.
 * Version: 1.0.0
 * Author: Ma's House
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'fsp_schedule_posting_data', 'ma_fsposter_story_layout_guard_apply', 99, 2 );

function ma_fsposter_story_layout_guard_apply( $posting_data, $schedule_obj ) {
	if ( ! is_object( $posting_data ) || ! is_object( $schedule_obj ) ) {
		return $posting_data;
	}

	if ( ! method_exists( $schedule_obj, 'getSocialNetwork' ) || 'instagram' !== (string) $schedule_obj->getSocialNetwork() ) {
		return $posting_data;
	}

	$channel = method_exists( $schedule_obj, 'getChannel' ) ? $schedule_obj->getChannel() : null;
	$is_story = is_object( $channel ) && ! empty( $channel->channel_type ) && false !== strpos( (string) $channel->channel_type, 'story' );
	if ( ! $is_story && ( empty( $posting_data->edge ) || 'story' !== (string) $posting_data->edge ) ) {
		return $posting_data;
	}

	$post_id = ( ! empty( $schedule_obj->wpPost ) && ! empty( $schedule_obj->wpPost->ID ) ) ? (int) $schedule_obj->wpPost->ID : 0;
	if ( ! ma_fsposter_story_layout_guard_is_event_content( $post_id ) ) {
		return $posting_data;
	}

	// FS Poster renders its message into the image before this filter. Replacing
	// that intermediate image with the original media prevents both text overlap
	// and a second copy of the branded story frame.
	$posting_data->message = '';
	$media = ma_fsposter_story_layout_guard_original_media( $post_id, $schedule_obj );
	if ( $media ) {
		ma_fsposter_story_layout_guard_clear_generated_cache( $post_id );
		$posting_data->uploadMedia = array( $media );
	}

	return $posting_data;
}

function ma_fsposter_story_layout_guard_is_event_content( $post_id ) {
	if ( ! $post_id ) {
		return false;
	}

	if ( 'tribe_events' === get_post_type( $post_id ) ) {
		return true;
	}

	return 'post' === get_post_type( $post_id ) && has_tag( 'weekly-events', $post_id );
}

function ma_fsposter_story_layout_guard_original_media( $post_id, $schedule_obj ) {
	$media_ids = array();
	if ( method_exists( $schedule_obj, 'getSchedule' ) ) {
		$schedule = $schedule_obj->getSchedule();
		$customization = is_object( $schedule ) && isset( $schedule->customization_data_obj ) ? $schedule->customization_data_obj : null;
		if ( is_object( $customization ) && ! empty( $customization->media_list_to_upload ) ) {
			$media_ids = array_map( 'absint', (array) $customization->media_list_to_upload );
		}
	}

	$thumbnail_id = (int) get_post_thumbnail_id( $post_id );
	if ( $thumbnail_id ) {
		$media_ids[] = $thumbnail_id;
	}

	foreach ( array_values( array_unique( array_filter( $media_ids ) ) ) as $media_id ) {
		$path = get_attached_file( $media_id );
		$url = wp_get_attachment_url( $media_id );
		if ( ! $path || ! $url || ! file_exists( $path ) || ! is_readable( $path ) ) {
			continue;
		}

		$size = @getimagesize( $path );
		return array(
			'id'     => $media_id,
			'type'   => 'image',
			'path'   => $path,
			'url'    => $url,
			'width'  => is_array( $size ) ? (int) $size[0] : 0,
			'height' => is_array( $size ) ? (int) $size[1] : 0,
		);
	}

	return array();
}

function ma_fsposter_story_layout_guard_clear_generated_cache( $post_id ) {
	$uploads = wp_upload_dir();
	if ( empty( $uploads['basedir'] ) ) {
		return;
	}

	$pattern = trailingslashit( $uploads['basedir'] ) . '*/*/ma-fsposter-instagram-safe-story-' . (int) $post_id . '-*.jpg';
	foreach ( (array) glob( $pattern ) as $file ) {
		if ( is_file( $file ) && 0 === strpos( wp_normalize_path( $file ), wp_normalize_path( trailingslashit( $uploads['basedir'] ) ) ) ) {
			@unlink( $file );
		}
	}
}

function ma_fsposter_story_layout_guard_repair_configuration() {
	global $wpdb;

	$repaired_schedules = 0;
	$schedule_table = $wpdb->prefix . 'fsp_schedules';
	$rows = $wpdb->get_results( "SELECT id, wp_post_id, customization_data FROM {$schedule_table} WHERE status = 'not_sent' AND channel_id = 2" );
	foreach ( (array) $rows as $row ) {
		if ( ! ma_fsposter_story_layout_guard_is_event_content( (int) $row->wp_post_id ) ) {
			continue;
		}

		$customization = json_decode( (string) $row->customization_data, true );
		if ( ! is_array( $customization ) ) {
			continue;
		}
		$customization['post_content'] = '';
		$updated = $wpdb->update(
			$schedule_table,
			array( 'customization_data' => wp_json_encode( $customization, JSON_UNESCAPED_SLASHES ) ),
			array( 'id' => (int) $row->id ),
			array( '%s' ),
			array( '%d' )
		);
		if ( false !== $updated && $updated > 0 ) {
			$repaired_schedules++;
		}
	}

	$repaired_planners = 0;
	$planner_table = $wpdb->prefix . 'fsp_planners';
	$planners = $wpdb->get_results( $wpdb->prepare( "SELECT id, customization_data FROM {$planner_table} WHERE title = %s", 'Ma Weekly Events FSPoster' ) );
	foreach ( (array) $planners as $planner ) {
		$customization = json_decode( (string) $planner->customization_data, true );
		if ( ! is_array( $customization ) || empty( $customization['2'] ) || ! is_array( $customization['2'] ) ) {
			continue;
		}
		$customization['2']['post_content'] = '';
		$updated = $wpdb->update(
			$planner_table,
			array( 'customization_data' => wp_json_encode( $customization, JSON_UNESCAPED_SLASHES ) ),
			array( 'id' => (int) $planner->id ),
			array( '%s' ),
			array( '%d' )
		);
		if ( false !== $updated && $updated > 0 ) {
			$repaired_planners++;
		}
	}

	return array(
		'schedules' => $repaired_schedules,
		'planners'  => $repaired_planners,
	);
}
