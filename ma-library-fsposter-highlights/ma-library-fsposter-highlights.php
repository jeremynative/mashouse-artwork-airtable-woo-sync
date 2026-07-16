<?php
/**
 * Plugin Name: Ma Library FS Poster Highlights
 * Description: Creates recurring Ma's House library highlight posts with JPEG cover images for FS Poster.
 * Version: 0.1.0
 * Author: Ma's House
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MA_LFH_HOOK', 'ma_lfh_create_library_highlight' );
define( 'MA_LFH_INTERVAL', 8 * DAY_IN_SECONDS );
define( 'MA_LFH_SCHEDULE', 'ma_lfh_every_8_days' );
define( 'MA_LFH_OPTION_STATUS', 'ma_lfh_post_status' );
define( 'MA_LFH_PLANNER_TITLE', 'Ma Library Highlight FSPoster' );
define( 'MA_LFH_STORY_TEXT', 'Featured in the Ma\'s House Library' );
define( 'MA_LFH_RECENT_BOOK_OPTION', 'ma_lfh_recent_book_ids' );
define( 'MA_LFH_RECENT_BOOK_LIMIT', 250 );
define( 'MA_LFH_RECENT_BOOK_DAYS', 365 );
define( 'MA_LFH_IMAGE_GOOD_AREA', 250000 );
define( 'MA_LFH_IMAGE_MIN_AREA', 50000 );
define( 'MA_LFH_SOCIAL_IMAGE_SIZE', 1080 );
define( 'MA_LFH_SOCIAL_COVER_MAX_WIDTH', 760 );
define( 'MA_LFH_SOCIAL_COVER_MAX_HEIGHT', 920 );
define( 'MA_LFH_COVER_VERSION', 'v5-quality-gated-square' );
define( 'MA_LFH_PLACEHOLDER_VERSION', 'v13-helvetica-title-fit-no-overlap' );

register_activation_hook( __FILE__, 'ma_lfh_activate' );
function ma_lfh_activate() {
	if ( false === get_option( MA_LFH_OPTION_STATUS, false ) ) {
		add_option( MA_LFH_OPTION_STATUS, 'publish', '', false );
	}

	ma_lfh_schedule();
}

register_deactivation_hook( __FILE__, 'ma_lfh_deactivate' );
function ma_lfh_deactivate() {
	wp_clear_scheduled_hook( MA_LFH_HOOK );
}

add_filter( 'cron_schedules', 'ma_lfh_cron_schedules' );
function ma_lfh_cron_schedules( $schedules ) {
	$schedules[ MA_LFH_SCHEDULE ] = array(
		'interval' => MA_LFH_INTERVAL,
		'display'  => 'Every 8 days for Ma Library FS Poster highlights',
	);

	return $schedules;
}

add_action( 'init', 'ma_lfh_schedule' );
function ma_lfh_schedule() {
	$event = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( MA_LFH_HOOK ) : null;
	$too_soon = $event && ! empty( $event->timestamp ) && (int) $event->timestamp < ( time() + MA_LFH_INTERVAL - DAY_IN_SECONDS );
	$hour     = $event && ! empty( $event->timestamp ) ? (int) wp_date( 'G', (int) $event->timestamp ) : 13;
	if ( $event && ( ( ! empty( $event->schedule ) && MA_LFH_SCHEDULE !== $event->schedule ) || $too_soon || 13 !== $hour ) ) {
		wp_clear_scheduled_hook( MA_LFH_HOOK );
		$event = null;
	}

	if ( ! $event && ! wp_next_scheduled( MA_LFH_HOOK ) ) {
		wp_schedule_event( ma_lfh_next_interval_1pm_timestamp(), MA_LFH_SCHEDULE, MA_LFH_HOOK );
	}
}

add_action( 'pre_get_posts', 'ma_lfh_hide_public_library_highlight_queries', 9 );
function ma_lfh_hide_public_library_highlight_queries( $query ) {
	if ( is_admin() || ! $query instanceof WP_Query ) {
		return;
	}

	if ( is_singular() && $query->is_main_query() ) {
		return;
	}

	$post_type     = $query->get( 'post_type' );
	$is_post_query = ! $post_type || 'post' === $post_type || ( is_array( $post_type ) && in_array( 'post', $post_type, true ) );
	if ( ! $is_post_query ) {
		return;
	}

	$category_id = ma_lfh_category_id();
	if ( ! $category_id ) {
		return;
	}

	$excluded   = array_map( 'intval', (array) $query->get( 'category__not_in' ) );
	$excluded[] = (int) $category_id;
	$query->set( 'category__not_in', array_values( array_unique( array_filter( $excluded ) ) ) );
}

add_filter( 'posts_results', 'ma_lfh_remove_library_highlights_from_public_results', 20, 2 );
function ma_lfh_remove_library_highlights_from_public_results( $posts, $query ) {
	if ( is_admin() || ! $posts || ! $query instanceof WP_Query ) {
		return $posts;
	}

	if ( is_singular() && $query->is_main_query() ) {
		return $posts;
	}

	$post_type     = $query->get( 'post_type' );
	$is_post_query = ! $post_type || 'post' === $post_type || ( is_array( $post_type ) && in_array( 'post', $post_type, true ) );
	if ( ! $is_post_query ) {
		return $posts;
	}

	return array_values(
		array_filter(
			$posts,
			static function ( $post ) {
				if ( ! $post instanceof WP_Post ) {
					return true;
				}

				return ! preg_match( '/^Library Highlight:/i', (string) $post->post_title )
					&& ! has_category( 'library-highlights', $post );
			}
		)
	);
}

function ma_lfh_next_1pm_timestamp() {
	$timezone = wp_timezone();
	$now      = new DateTimeImmutable( 'now', $timezone );
	$target   = $now->setTime( 13, 0, 0 );

	if ( $now >= $target ) {
		$target = $target->modify( '+1 day' );
	}

	return $target->getTimestamp();
}

function ma_lfh_next_interval_1pm_timestamp() {
	$timezone = wp_timezone();
	$now      = new DateTimeImmutable( 'now', $timezone );
	$target   = $now->modify( '+8 days' )->setTime( 13, 0, 0 );

	return $target->getTimestamp();
}

add_action( MA_LFH_HOOK, 'ma_lfh_create_scheduled_highlight' );
function ma_lfh_create_scheduled_highlight() {
	ma_lfh_create_highlight_post( get_option( MA_LFH_OPTION_STATUS, 'publish' ) );
}

add_action( 'admin_menu', 'ma_lfh_register_tools_page' );
add_action( 'wp_head', 'ma_lfh_public_book_title_style' );
add_action( 'wp_footer', 'ma_lfh_public_book_cover_script' );
function ma_lfh_register_tools_page() {
	add_management_page(
		'Library FS Poster Highlights',
		'Library FS Poster Highlights',
		'edit_posts',
		'ma-library-fsposter-highlights',
		'ma_lfh_render_tools_page'
	);
}

function ma_lfh_public_book_title_style() {
	if ( ! is_singular( 'book' ) ) {
		return;
	}
	?>
	<style id="ma-lfh-book-title-normalizer">
		.single-book .entry-title,
		.single-book h1.entry-title,
		body.single-book h1 {
			font-size: clamp(1.75rem, 3vw, 2.25rem) !important;
			line-height: 1.12 !important;
			letter-spacing: 0 !important;
			max-width: 980px;
		}

		.ma-lfh-public-book-cover {
			margin: 0 0 2rem;
			max-width: 360px;
		}

		.ma-lfh-public-book-cover img {
			display: block;
			width: 100%;
			height: auto;
			box-shadow: 0 10px 28px rgba(0, 0, 0, 0.12);
		}

		.ma-library-single__cover.ma-lfh-public-book-cover {
			background: transparent !important;
			color: inherit !important;
			padding: 0 !important;
			overflow: hidden;
		}

		.ma-library-single__cover.ma-lfh-public-book-cover img {
			display: block;
			width: 100%;
			height: 100%;
			object-fit: contain;
			box-shadow: none;
		}
	</style>
	<?php
}

function ma_lfh_public_book_cover_script() {
	if ( ! is_singular( 'book' ) ) {
		return;
	}

	$book_id = get_queried_object_id();
	if ( ! $book_id ) {
		return;
	}

	$cover_url = ma_lfh_cover_url( $book_id );
	if ( ! $cover_url && has_post_thumbnail( $book_id ) ) {
		$cover_url = get_the_post_thumbnail_url( $book_id, 'large' );
	}

	if ( ! $cover_url ) {
		return;
	}

	$title = trim( get_the_title( $book_id ) . ' cover' );
	?>
	<script id="ma-lfh-book-cover-inserter">
	(function () {
		var coverUrl = <?php echo wp_json_encode( esc_url_raw( $cover_url ) ); ?>;
		var altText = <?php echo wp_json_encode( $title ); ?>;
		if (!coverUrl) {
			return;
		}

		var pageRoot = document.querySelector('.ma-library-single') || document.querySelector('main') || document.body;
		var article = document.querySelector('body.single-book article') || document.querySelector('article') || pageRoot;
		if (!pageRoot || !article) {
			return;
		}

		var fallbackFigures = pageRoot.querySelectorAll('.ma-lfh-public-book-cover--fallback');
		fallbackFigures.forEach(function (node) {
			node.remove();
		});

		var templateCover = pageRoot.querySelector('.ma-library-single__cover');
		if (templateCover) {
			templateCover.innerHTML = '';
			templateCover.classList.remove('ma-library-card__cover--fallback');
			templateCover.classList.add('ma-lfh-public-book-cover');

			var slotImage = document.createElement('img');
			slotImage.src = coverUrl;
			slotImage.alt = altText;
			slotImage.loading = 'eager';
			slotImage.decoding = 'async';
			templateCover.appendChild(slotImage);
			return;
		}

		var candidates = Array.prototype.slice.call(article.querySelectorAll('figure img, .wp-post-image, img'));
		var bookCover = candidates.find(function (img) {
			var src = img.currentSrc || img.src || '';
			var alt = img.alt || '';
			if (src.indexOf('cropped') !== -1 || src.indexOf('mashoueslogo') !== -1 || src.indexOf('mashouse') !== -1 && alt.indexOf('cover') === -1) {
				return false;
			}

			return alt.toLowerCase().indexOf('cover') !== -1 || src.indexOf('ma-library-placeholder-covers') !== -1 || img.closest('figure');
		});

		if (bookCover) {
			bookCover.src = coverUrl;
			bookCover.removeAttribute('srcset');
			bookCover.removeAttribute('sizes');
			bookCover.alt = altText;
			bookCover.loading = 'eager';
			bookCover.decoding = 'async';

			var parentFigure = bookCover.closest('figure');
			if (parentFigure) {
				parentFigure.classList.add('ma-lfh-public-book-cover');
			}
			return;
		}

		var anchor = article.querySelector('h1');
		var figure = document.createElement('figure');
		figure.className = 'ma-lfh-public-book-cover ma-lfh-public-book-cover--fallback';
		var image = document.createElement('img');
		image.src = coverUrl;
		image.alt = altText;
		image.loading = 'eager';
		image.decoding = 'async';
		figure.appendChild(image);
		if (anchor && anchor.parentNode) {
			anchor.parentNode.insertBefore(figure, anchor.nextSibling);
		} else {
			article.insertBefore(figure, article.firstChild);
		}
	})();
	</script>
	<?php
}

function ma_lfh_render_tools_page() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'ma-lfh' ) );
	}

	$message = '';
	if ( isset( $_POST['ma_lfh_save_settings'] ) && check_admin_referer( 'ma_lfh_save_settings' ) ) {
		$status = isset( $_POST['ma_lfh_status'] ) && 'draft' === $_POST['ma_lfh_status'] ? 'draft' : 'publish';
		update_option( MA_LFH_OPTION_STATUS, $status, false );
		$message = 'Settings saved.';
	}

	if ( isset( $_POST['ma_lfh_create_test'] ) && check_admin_referer( 'ma_lfh_create_test' ) ) {
		$post_id = ma_lfh_create_highlight_post( 'draft' );
		$message = $post_id ? 'Test library highlight draft created.' : 'No eligible library book with a usable image was found.';
	}

	if ( isset( $_POST['ma_lfh_refresh_planner'] ) && check_admin_referer( 'ma_lfh_refresh_planner' ) ) {
		$message = ma_lfh_refresh_latest_planner() ? 'FS Poster planner refreshed from the latest library highlight.' : 'No published library highlight with a usable image was found.';
	}

	if ( isset( $_POST['ma_lfh_audit_covers'] ) && check_admin_referer( 'ma_lfh_audit_covers' ) ) {
		$audited = ma_lfh_audit_cover_batch();
		$message = $audited ? 'Audited ' . (int) $audited . ' library covers.' : 'No library covers were available to audit.';
	}

	if ( isset( $_POST['ma_lfh_create_placeholder'] ) && check_admin_referer( 'ma_lfh_create_placeholder' ) ) {
		$book_id = ma_lfh_find_book_from_input( isset( $_POST['ma_lfh_placeholder_book'] ) ? wp_unslash( $_POST['ma_lfh_placeholder_book'] ) : '' );
		if ( $book_id ) {
			$image_id = ma_lfh_create_placeholder_cover_for_book( $book_id );
			if ( $image_id ) {
				ma_lfh_purge_known_caches();
			}
			$message = $image_id ? 'Placeholder cover created for ' . get_the_title( $book_id ) . '.' : 'Could not create a placeholder cover for that book.';
		} else {
			$message = 'Could not find that book. Paste the full library URL or the book slug.';
		}
	}

	$next   = wp_next_scheduled( MA_LFH_HOOK );
	$event  = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( MA_LFH_HOOK ) : null;
	$planner = ma_lfh_planner_status();
	$status = get_option( MA_LFH_OPTION_STATUS, 'publish' );
	?>
	<div class="wrap">
		<h1>Library FS Poster Highlights</h1>
		<?php if ( $message ) : ?>
			<div class="notice notice-success"><p><?php echo esc_html( $message ); ?></p></div>
		<?php endif; ?>
		<p>Creates one recurring library highlight post for FS Poster. Books are only selected when they have a usable cover image, and the cover is imported as a JPEG before the post is queued.</p>
		<p><strong>Next scheduled run:</strong> <?php echo $next ? esc_html( wp_date( 'F j, Y g:i a T', $next ) ) : 'Not scheduled'; ?></p>
		<table class="widefat striped" style="max-width: 900px; margin: 1rem 0;">
			<tbody>
				<tr>
					<th scope="row">WordPress schedule</th>
					<td><?php echo esc_html( $event && ! empty( $event->schedule ) ? $event->schedule : 'Not scheduled' ); ?></td>
				</tr>
				<tr>
					<th scope="row">WordPress interval</th>
					<td><?php echo esc_html( $event && ! empty( $event->interval ) ? ma_lfh_format_interval( (int) $event->interval ) : 'Not scheduled' ); ?></td>
				</tr>
				<tr>
					<th scope="row">FS Poster interval</th>
					<td><?php echo esc_html( $planner && isset( $planner['schedule_interval'] ) ? ma_lfh_format_interval( (int) $planner['schedule_interval'] ) : 'Planner not found' ); ?></td>
				</tr>
				<tr>
					<th scope="row">FS Poster selected post</th>
					<td><?php echo esc_html( $planner && ! empty( $planner['selected_posts'] ) ? $planner['selected_posts'] : 'None' ); ?></td>
				</tr>
				<tr>
					<th scope="row">FS Poster next run</th>
					<td><?php echo esc_html( $planner && ! empty( $planner['next_execute_at'] ) ? $planner['next_execute_at'] : 'Planner not found' ); ?></td>
				</tr>
			</tbody>
		</table>
		<form method="post">
			<?php wp_nonce_field( 'ma_lfh_save_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ma_lfh_status">Generated post status</label></th>
					<td>
						<select id="ma_lfh_status" name="ma_lfh_status">
							<option value="publish" <?php selected( $status, 'publish' ); ?>>Publish for FS Poster</option>
							<option value="draft" <?php selected( $status, 'draft' ); ?>>Draft for review</option>
						</select>
					</td>
				</tr>
			</table>
			<p><button class="button button-primary" type="submit" name="ma_lfh_save_settings" value="1">Save settings</button></p>
		</form>
		<form method="post">
			<?php wp_nonce_field( 'ma_lfh_create_test' ); ?>
			<p><button class="button" type="submit" name="ma_lfh_create_test" value="1">Create test draft now</button></p>
		</form>
		<form method="post">
			<?php wp_nonce_field( 'ma_lfh_refresh_planner' ); ?>
			<p><button class="button" type="submit" name="ma_lfh_refresh_planner" value="1">Refresh FS Poster planner now</button></p>
		</form>
		<form method="post">
			<?php wp_nonce_field( 'ma_lfh_audit_covers' ); ?>
			<p><button class="button" type="submit" name="ma_lfh_audit_covers" value="1">Audit next 25 book covers</button></p>
		</form>
		<form method="post">
			<?php wp_nonce_field( 'ma_lfh_create_placeholder' ); ?>
			<h2>Create a placeholder book cover</h2>
			<p>Use this when a real book cover cannot be found. It creates a square JPEG with the title, author/editor, publisher, and year when available.</p>
			<p>
				<label for="ma_lfh_placeholder_book"><strong>Book URL or slug</strong></label><br>
				<input id="ma_lfh_placeholder_book" name="ma_lfh_placeholder_book" type="text" class="regular-text" placeholder="https://www.mashouse.studio/library/book-slug/" style="width: min(720px, 100%);">
			</p>
			<p><button class="button" type="submit" name="ma_lfh_create_placeholder" value="1">Create placeholder cover</button></p>
		</form>
		<?php ma_lfh_render_cover_audit(); ?>
		<?php ma_lfh_render_fsposter_channels(); ?>
	</div>
	<?php
}

function ma_lfh_planner_status() {
	global $wpdb;

	$planner_table = $wpdb->prefix . 'fsp_planners';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $planner_table ) ) !== $planner_table ) {
		return null;
	}

	return $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, title, status, share_type, sort_by, next_execute_at, selected_posts, shared_posts, repeating, schedule_interval, post_filters_term, updated_at
			FROM {$planner_table}
			WHERE title = %s
			ORDER BY id DESC
			LIMIT 1",
			MA_LFH_PLANNER_TITLE
		),
		ARRAY_A
	);
}

function ma_lfh_format_interval( $seconds ) {
	$seconds = (int) $seconds;
	if ( MA_LFH_INTERVAL === $seconds ) {
		return '8 days (' . $seconds . ' seconds)';
	}

	return human_time_diff( 0, $seconds ) . ' (' . $seconds . ' seconds)';
}

function ma_lfh_purge_known_caches() {
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}

	if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
		sg_cachepress_purge_cache();
	}

	if ( class_exists( 'SiteGround_Optimizer\\Supercacher\\Supercacher' ) && method_exists( 'SiteGround_Optimizer\\Supercacher\\Supercacher', 'purge_cache' ) ) {
		SiteGround_Optimizer\Supercacher\Supercacher::purge_cache();
	}

	do_action( 'litespeed_purge_all' );
	do_action( 'w3tc_flush_all' );
	do_action( 'wp_cache_clear_cache' );
}

function ma_lfh_render_cover_audit() {
	$report = get_option( 'ma_lfh_cover_audit_report', array() );
	if ( empty( $report ) || ! is_array( $report ) ) {
		echo '<h2>Cover quality audit</h2><p>No recent cover audit has been run.</p>';
		return;
	}

	echo '<h2>Cover quality audit</h2>';
	echo '<p>Minimum for Instagram is 800 px on the long edge when possible. Strong covers are used first for future posts.</p>';
	echo '<table class="widefat striped"><thead><tr><th>Book</th><th>Status</th><th>Best size</th><th>Source</th></tr></thead><tbody>';
	foreach ( $report as $row ) {
		$status = isset( $row['status'] ) ? $row['status'] : '';
		$class  = 'good' === $status ? 'ma-lfh-good' : ( 'weak' === $status ? 'ma-lfh-weak' : 'ma-lfh-missing' );
		echo '<tr class="' . esc_attr( $class ) . '">';
		echo '<td>' . esc_html( $row['title'] ?? '' ) . '</td>';
		echo '<td>' . esc_html( ucfirst( $status ) ) . '</td>';
		echo '<td>' . esc_html( $row['size'] ?? '' ) . '</td>';
		echo '<td>' . esc_html( $row['source'] ?? '' ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
}

function ma_lfh_audit_cover_batch() {
	if ( ! post_type_exists( 'book' ) ) {
		return 0;
	}

	$books = get_posts(
		array(
			'post_type'      => 'book',
			'post_status'    => 'publish',
			'posts_per_page' => 25,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => '_ma_library_cover_url',
					'value'   => '',
					'compare' => '!=',
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => '_ma_lfh_cover_quality_checked_at',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_ma_lfh_cover_quality_checked_at',
						'value'   => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
						'compare' => '<',
					),
				),
			),
		)
	);

	$report = array();
	foreach ( $books as $book ) {
		$cover = ma_lfh_best_cover_editor( $book->ID );
		if ( empty( $cover['tmp'] ) ) {
			update_post_meta( $book->ID, '_ma_lfh_cover_quality_checked_at', current_time( 'mysql' ) );
			$report[] = array(
				'title'  => ma_lfh_book_title( $book ),
				'status' => 'missing',
				'size'   => '',
				'source' => '',
			);
			continue;
		}

		@unlink( $cover['tmp'] );
		$width  = empty( $cover['width'] ) ? 0 : (int) $cover['width'];
		$height = empty( $cover['height'] ) ? 0 : (int) $cover['height'];
		$score  = empty( $cover['score'] ) ? 0 : (int) $cover['score'];
		$status = ( $width >= 800 || $height >= 800 || $score >= MA_LFH_IMAGE_GOOD_AREA ) ? 'good' : 'weak';

		update_post_meta( $book->ID, '_ma_lfh_cover_quality_width', (string) $width );
		update_post_meta( $book->ID, '_ma_lfh_cover_quality_height', (string) $height );
		update_post_meta( $book->ID, '_ma_lfh_cover_quality_checked_at', current_time( 'mysql' ) );
		update_post_meta( $book->ID, '_ma_lfh_cover_quality_source_url', esc_url_raw( $cover['url'] ) );

		if ( 'good' === $status && ! empty( $cover['url'] ) && $cover['url'] !== ma_lfh_cover_url( $book->ID ) ) {
			update_post_meta( $book->ID, '_ma_library_cover_url', esc_url_raw( $cover['url'] ) );
		}

		$report[] = array(
			'title'  => ma_lfh_book_title( $book ),
			'status' => $status,
			'size'   => $width && $height ? $width . ' x ' . $height : '',
			'source' => ma_lfh_cover_source_label( $cover['url'] ?? '' ),
		);
	}

	update_option( 'ma_lfh_cover_audit_report', $report, false );
	return count( $report );
}

function ma_lfh_cover_source_label( $url ) {
	$host = wp_parse_url( (string) $url, PHP_URL_HOST );
	if ( ! $host ) {
		return '';
	}

	return preg_replace( '/^www\./', '', strtolower( $host ) );
}

function ma_lfh_find_book_from_input( $input ) {
	$input = trim( (string) $input );
	if ( '' === $input ) {
		return 0;
	}

	$post_id = 0;
	if ( false !== strpos( $input, '://' ) ) {
		$post_id = url_to_postid( esc_url_raw( $input ) );
		$path    = trim( (string) wp_parse_url( $input, PHP_URL_PATH ), '/' );
		if ( ! $post_id && $path ) {
			$parts = explode( '/', $path );
			$input = end( $parts );
		}
	}

	if ( ! $post_id ) {
		$slug = sanitize_title( $input );
		$post = get_page_by_path( $slug, OBJECT, 'book' );
		if ( $post ) {
			$post_id = (int) $post->ID;
		}
	}

	return $post_id && 'book' === get_post_type( $post_id ) ? (int) $post_id : 0;
}

function ma_lfh_create_placeholder_cover_for_book( $book_id ) {
	if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagejpeg' ) ) {
		return 0;
	}

	$book_id = (int) $book_id;
	if ( ! $book_id || 'book' !== get_post_type( $book_id ) ) {
		return 0;
	}

	$title     = ma_lfh_book_title( get_post( $book_id ) );
	$author    = ma_lfh_author_name( get_post_meta( $book_id, '_ma_library_author', true ) );
	$publisher = ma_lfh_clean_text( get_post_meta( $book_id, '_ma_library_publisher', true ) );
	$year      = ma_lfh_clean_text( get_post_meta( $book_id, '_ma_library_date', true ) );
	$year      = preg_match( '/\b(1[5-9][0-9]{2}|20[0-9]{2})\b/', $year, $matches ) ? $matches[1] : $year;

	$hash = md5( MA_LFH_PLACEHOLDER_VERSION . '|placeholder|' . $book_id . '|' . $title . '|' . $author . '|' . $publisher . '|' . $year );
	$existing = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => '_ma_lfh_placeholder_hash',
					'value' => $hash,
				),
			),
		)
	);

	if ( ! empty( $existing ) ) {
		$image_id = (int) $existing[0];
		$url      = wp_get_attachment_url( $image_id );
		if ( $url ) {
			update_post_meta( $book_id, '_ma_library_cover_url', esc_url_raw( $url ) );
			set_post_thumbnail( $book_id, $image_id );
			return $image_id;
		}
	}

	$uploads = wp_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . 'ma-library-placeholder-covers';
	wp_mkdir_p( $dir );
	$target = trailingslashit( $dir ) . 'library-placeholder-' . $book_id . '-' . substr( $hash, 0, 10 ) . '.jpg';

	if ( ! ma_lfh_render_placeholder_cover_jpeg( $target, $title, $author, $publisher, $year, $hash ) ) {
		return 0;
	}

	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => sanitize_text_field( $title . ' placeholder library cover' ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		),
		$target,
		$book_id
	);

	if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
		return 0;
	}

	$metadata = wp_generate_attachment_metadata( $attachment_id, $target );
	if ( ! is_wp_error( $metadata ) && ! empty( $metadata ) ) {
		wp_update_attachment_metadata( $attachment_id, $metadata );
	}

	$url = wp_get_attachment_url( $attachment_id );
	update_post_meta( $attachment_id, '_ma_lfh_placeholder_hash', $hash );
	update_post_meta( $attachment_id, '_ma_lfh_placeholder_book_id', (string) $book_id );
	update_post_meta( $book_id, '_ma_library_cover_url', esc_url_raw( $url ) );
	update_post_meta( $book_id, '_ma_lfh_cover_quality_width', '1080' );
	update_post_meta( $book_id, '_ma_lfh_cover_quality_height', '1080' );
	update_post_meta( $book_id, '_ma_lfh_cover_quality_checked_at', current_time( 'mysql' ) );
	update_post_meta( $book_id, '_ma_lfh_cover_quality_source_url', esc_url_raw( $url ) );
	set_post_thumbnail( $book_id, $attachment_id );

	return (int) $attachment_id;
}

function ma_lfh_render_placeholder_cover_jpeg( $target, $title, $author, $publisher, $year, $hash ) {
	$required = array(
		'imagecreatetruecolor',
		'imagecolorallocate',
		'imagecolorallocatealpha',
		'imagefilledrectangle',
		'imagejpeg',
		'imagedestroy',
	);
	foreach ( $required as $function ) {
		if ( ! function_exists( $function ) ) {
			return false;
		}
	}

	$size   = MA_LFH_SOCIAL_IMAGE_SIZE;
	$canvas = imagecreatetruecolor( $size, $size );
	if ( ! $canvas ) {
		return false;
	}

	try {
		$background = imagecolorallocate( $canvas, 244, 241, 234 );
		imagefilledrectangle( $canvas, 0, 0, $size, $size, $background );

		$palette = ma_lfh_placeholder_palette( $hash );
		$book    = imagecolorallocate( $canvas, $palette[0], $palette[1], $palette[2] );
		$spine   = imagecolorallocate( $canvas, max( 0, $palette[0] - 58 ), max( 0, $palette[1] - 58 ), max( 0, $palette[2] - 58 ) );
		$accent  = imagecolorallocate( $canvas, min( 255, $palette[0] + 34 ), min( 255, $palette[1] + 34 ), min( 255, $palette[2] + 34 ) );
		$text    = imagecolorallocate( $canvas, 255, 255, 255 );
		$muted   = imagecolorallocate( $canvas, 237, 233, 221 );
		$line    = imagecolorallocatealpha( $canvas, 255, 255, 255, 84 );
		$shadow  = imagecolorallocatealpha( $canvas, 0, 0, 0, 96 );

		$x           = 218;
		$y           = 70;
		$w           = 640;
		$h           = 940;
		$spine_width = 128;
		imagefilledrectangle( $canvas, $x + 24, $y + 28, $x + $w + 24, $y + $h + 28, $shadow );
		imagefilledrectangle( $canvas, $x, $y, $x + $w, $y + $h, $book );
		imagefilledrectangle( $canvas, $x, $y, $x + $spine_width, $y + $h, $spine );
		imagefilledrectangle( $canvas, $x + $spine_width, $y, $x + $spine_width + 8, $y + $h, $accent );
		imagefilledrectangle( $canvas, $x + $spine_width + 28, $y + 38, $x + $w - 38, $y + 42, $line );
		imagefilledrectangle( $canvas, $x + $spine_width + 28, $y + $h - 92, $x + $w - 38, $y + $h - 88, $line );

		$font_regular = ma_lfh_placeholder_font_path( false );
		$font_bold    = ma_lfh_placeholder_font_path( true );

		$text_x     = $x + $spine_width + 46;
		$text_width = $w - $spine_width - 92;
		$title_top     = $y + 116;
		$title_bottom  = $y + $h - 272;
		$title_layout  = ma_lfh_placeholder_title_layout( $title, $font_bold, $text_width, $title_bottom - $title_top );
		ma_lfh_draw_text_lines( $canvas, $title_layout['lines'], $font_bold, $title_layout['size'], $text_x, $title_top, $text, $title_layout['line_height'] );

		$meta_top    = $y + $h - 224;
		$meta_bottom = $y + $h - 116;
		$details     = trim( implode( ' | ', array_filter( array( $publisher, $year ) ) ) );
		ma_lfh_draw_placeholder_meta( $canvas, $author, $details, $font_regular, $text_x, $meta_top, $text_width, $meta_bottom - $meta_top, $text, $muted );

		ma_lfh_draw_text_lines( $canvas, array( "Ma's House Library" ), $font_bold, 34, $text_x, $y + $h - 82, $muted, 38 );

		$saved = imagejpeg( $canvas, $target, 92 );
	} catch ( Throwable $e ) {
		$saved = false;
	}

	imagedestroy( $canvas );

	return $saved && file_exists( $target );
}

function ma_lfh_placeholder_palette( $hash ) {
	$palettes = array(
		array( 21, 86, 119 ),
		array( 126, 54, 45 ),
		array( 47, 99, 71 ),
		array( 88, 73, 135 ),
		array( 148, 96, 46 ),
		array( 51, 70, 109 ),
		array( 122, 62, 97 ),
		array( 76, 94, 43 ),
	);

	$index = hexdec( substr( $hash, 0, 2 ) ) % count( $palettes );
	return $palettes[ $index ];
}

function ma_lfh_placeholder_title_layout( $title, $font, $max_width, $max_height ) {
	foreach ( array( 44, 42, 40, 38, 36, 34, 32, 30, 28, 26, 24, 22, 20, 18, 16, 14 ) as $size ) {
		$lines       = ma_lfh_wrap_placeholder_text( $title, $font, $size, $max_width, 0 );
		$line_height = max( $size + 4, (int) round( $size * 1.12 ) );
		$height      = count( $lines ) * $line_height;
		if ( $height <= $max_height ) {
			return array(
				'size'        => $size,
				'lines'       => $lines,
				'line_height' => $line_height,
			);
		}
	}

	$size = 12;
	return array(
		'size'        => $size,
		'lines'       => ma_lfh_wrap_placeholder_text( $title, $font, $size, $max_width, 0 ),
		'line_height' => 16,
	);
}

function ma_lfh_draw_placeholder_meta( $canvas, $author, $details, $font, $x, $y, $max_width, $max_height, $primary_color, $secondary_color ) {
	$author  = ma_lfh_clean_text( $author );
	$details = ma_lfh_clean_text( $details );
	if ( '' === $author && '' === $details ) {
		return $y;
	}

	foreach ( array( 28, 26, 24, 22, 20, 18, 16 ) as $author_size ) {
		$details_size        = max( 14, $author_size - 4 );
		$author_line_height  = max( $author_size + 4, (int) round( $author_size * 1.12 ) );
		$details_line_height = max( $details_size + 4, (int) round( $details_size * 1.12 ) );
		$author_lines        = $author ? ma_lfh_wrap_placeholder_text( $author, $font, $author_size, $max_width, 0 ) : array();
		$details_lines       = $details ? ma_lfh_wrap_placeholder_text( $details, $font, $details_size, $max_width, 0 ) : array();
		$total_height        = count( $author_lines ) * $author_line_height + ( $author_lines && $details_lines ? 8 : 0 ) + count( $details_lines ) * $details_line_height;

		if ( $total_height <= $max_height || 16 === $author_size ) {
			$cursor = $y;
			ma_lfh_draw_text_lines( $canvas, $author_lines, $font, $author_size, $x, $cursor, $primary_color, $author_line_height );
			$cursor += count( $author_lines ) * $author_line_height;
			if ( $author_lines && $details_lines ) {
				$cursor += 8;
			}
			ma_lfh_draw_text_lines( $canvas, $details_lines, $font, $details_size, $x, $cursor, $secondary_color, $details_line_height );
			return $cursor + count( $details_lines ) * $details_line_height;
		}
	}

	return $y;
}

function ma_lfh_placeholder_font_path( $bold = false ) {
	$plugin_fonts = array(
		WP_PLUGIN_DIR . '/fs-poster/App/Libraries/PHPImage/font/arial.ttf',
		WP_PLUGIN_DIR . '/auto-post-thumbnail/fonts/arial.ttf',
	);

	$candidates = $bold
		? array_merge(
			array(
			'C:\Windows\Fonts\arialbd.ttf',
			'/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
			'/usr/share/fonts/liberation/LiberationSans-Bold.ttf',
			),
			$plugin_fonts,
			array(
			'/usr/share/fonts/google-noto-vf/NotoSans[wght].ttf',
			'/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
			'/usr/share/fonts/truetype/dejavu/DejaVuSansCondensed-Bold.ttf',
			'/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
			'/usr/share/fonts/dejavu/DejaVuSansCondensed-Bold.ttf',
			'/usr/local/share/fonts/DejaVuSans-Bold.ttf',
			)
		)
		: array_merge(
			array(
			'C:\Windows\Fonts\arial.ttf',
			'/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
			'/usr/share/fonts/liberation/LiberationSans-Regular.ttf',
			),
			$plugin_fonts,
			array(
			'/usr/share/fonts/google-noto-vf/NotoSans[wght].ttf',
			'/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
			'/usr/share/fonts/truetype/dejavu/DejaVuSansCondensed.ttf',
			'/usr/share/fonts/dejavu/DejaVuSans.ttf',
			'/usr/share/fonts/dejavu/DejaVuSansCondensed.ttf',
			'/usr/local/share/fonts/DejaVuSans.ttf',
			)
		);

	foreach ( $candidates as $candidate ) {
		if ( file_exists( $candidate ) ) {
			return $candidate;
		}
	}

	$found = ma_lfh_find_server_font( $bold );
	if ( $found ) {
		return $found;
	}

	return '';
}

function ma_lfh_find_server_font( $bold = false ) {
	$roots = array(
		'/usr/share/fonts',
		'/usr/local/share/fonts',
		WP_CONTENT_DIR,
	);

	$preferred = $bold
		? array( 'notosans[wght]', 'notosans-bold', 'liberationsans-bold', 'arialbd', 'arial-bold', 'opensans-bold', 'roboto-bold', 'dejavusans-bold' )
		: array( 'notosans[wght]', 'notosans-regular', 'liberationsans-regular', 'arial.', 'opensans-regular', 'roboto-regular', 'dejavusans.' );

	foreach ( $roots as $root ) {
		if ( ! is_dir( $root ) || ! is_readable( $root ) ) {
			continue;
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
		} catch ( Exception $e ) {
			continue;
		}

		$checked = 0;
		foreach ( $iterator as $file ) {
			$checked++;
			if ( $checked > 2500 ) {
				break;
			}

			if ( ! $file->isFile() ) {
				continue;
			}

			$path = $file->getPathname();
			if ( ! preg_match( '/\.(ttf|otf)$/i', $path ) ) {
				continue;
			}

			$name = strtolower( $file->getFilename() );
			foreach ( $preferred as $needle ) {
				if ( false !== strpos( $name, $needle ) ) {
					return $path;
				}
			}
		}
	}

	return '';
}

function ma_lfh_draw_wrapped_text( $canvas, $text, $font, $font_size, $x, $y, $max_width, $max_lines, $color, $line_height_multiplier = 1.2 ) {
	$text = ma_lfh_clean_text( $text );
	if ( '' === $text ) {
		return $y;
	}

	$lines = ma_lfh_wrap_placeholder_text( $text, $font, $font_size, $max_width, $max_lines );
	$line_height = (int) round( $font_size * $line_height_multiplier );
	ma_lfh_draw_text_lines( $canvas, $lines, $font, $font_size, $x, $y, $color, $line_height );

	return $y + count( $lines ) * $line_height;
}

function ma_lfh_draw_text_lines( $canvas, $lines, $font, $font_size, $x, $y, $color, $line_height ) {
	foreach ( $lines as $line ) {
		if ( $font && function_exists( 'imagettftext' ) ) {
			imagettftext( $canvas, $font_size, 0, $x, $y + $font_size, $color, $font, $line );
		} else {
			ma_lfh_draw_scaled_builtin_text( $canvas, $line, $font_size, $x, $y, $color );
		}
		$y += $line_height;
	}
}

function ma_lfh_draw_scaled_builtin_text( $canvas, $text, $font_size, $x, $y, $color ) {
	$text = ma_lfh_placeholder_ascii_text( $text );
	if ( '' === $text ) {
		return;
	}

	$base_font = 5;
	$base_w    = max( 1, imagefontwidth( $base_font ) * strlen( $text ) );
	$base_h    = max( 1, imagefontheight( $base_font ) );
	$scale     = max( 1, $font_size / $base_h );
	$dst_w     = max( 1, (int) round( $base_w * $scale ) );
	$dst_h     = max( 1, (int) round( $base_h * $scale ) );

	$tmp = imagecreatetruecolor( $base_w, $base_h );
	if ( ! $tmp ) {
		imagestring( $canvas, $base_font, $x, $y, $text, $color );
		return;
	}

	imagealphablending( $tmp, false );
	imagesavealpha( $tmp, true );
	$transparent = imagecolorallocatealpha( $tmp, 0, 0, 0, 127 );
	imagefilledrectangle( $tmp, 0, 0, $base_w, $base_h, $transparent );
	$white = imagecolorallocate( $tmp, 255, 255, 255 );
	imagestring( $tmp, $base_font, 0, 0, $text, $white );

	imagecopyresampled( $canvas, $tmp, $x, $y, 0, 0, $dst_w, $dst_h, $base_w, $base_h );
	imagedestroy( $tmp );
}

function ma_lfh_placeholder_ascii_text( $text ) {
	$map = array(
		'–' => '-',
		'—' => '-',
		'‘' => "'",
		'’' => "'",
		'“' => '"',
		'”' => '"',
		'…' => '...',
		' ' => ' ',
	);

	$text = strtr( $text, $map );
	if ( function_exists( 'iconv' ) ) {
		$converted = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $text );
		if ( false !== $converted ) {
			$text = $converted;
		}
	}

	return preg_replace( '/[^\x20-\x7E]/', '', $text );
}

function ma_lfh_wrap_placeholder_text( $text, $font, $font_size, $max_width, $max_lines ) {
	$words = preg_split( '/\s+/', $text );
	$lines = array();
	$line  = '';
	$limited = $max_lines > 0;

	foreach ( $words as $word ) {
		$word_parts = ma_lfh_split_long_placeholder_word( (string) $word, $font, $font_size, $max_width );
		foreach ( $word_parts as $word_part ) {
			$try = trim( $line . ' ' . $word_part );
			if ( '' === $line || ma_lfh_text_width( $try, $font, $font_size ) <= $max_width ) {
				$line = $try;
				continue;
			}

			$lines[] = $line;
			$line = $word_part;
			if ( $limited && count( $lines ) >= $max_lines ) {
				break 2;
			}
		}
	}

	if ( ( ! $limited || count( $lines ) < $max_lines ) && '' !== $line ) {
		$lines[] = $line;
	}

	if ( $limited && count( $lines ) > $max_lines ) {
		$lines = array_slice( $lines, 0, $max_lines );
	}

	$consumed_text = trim( implode( ' ', $lines ) );
	if ( $limited && ! empty( $lines ) && strlen( $consumed_text ) < strlen( trim( $text ) ) ) {
		$last_index = count( $lines ) - 1;
		$lines[ $last_index ] = ma_lfh_fit_placeholder_line( rtrim( $lines[ $last_index ], " \t\n\r\0\x0B.,;:" ) . '...', $font, $font_size, $max_width );
	}

	if ( ! $limited ) {
		return $lines;
	}

	return array_map(
		function( $line ) use ( $font, $font_size, $max_width ) {
			return ma_lfh_fit_placeholder_line( $line, $font, $font_size, $max_width );
		},
		$lines
	);
}

function ma_lfh_split_long_placeholder_word( $word, $font, $font_size, $max_width ) {
	if ( ma_lfh_text_width( $word, $font, $font_size ) <= $max_width ) {
		return array( $word );
	}

	$parts = preg_split( '/([\-–—:\/])/u', $word, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
	if ( is_array( $parts ) && count( $parts ) > 1 ) {
		$chunks = array();
		$current = '';
		foreach ( $parts as $part ) {
			$try = $current . $part;
			if ( '' !== $current && ma_lfh_text_width( $try, $font, $font_size ) > $max_width ) {
				$chunks[] = $current;
				$current = ltrim( $part );
				continue;
			}
			$current = $try;
		}
		if ( '' !== $current ) {
			$chunks[] = $current;
		}
		if ( count( $chunks ) > 1 ) {
			return $chunks;
		}
	}

	$chars = preg_split( '//u', $word, -1, PREG_SPLIT_NO_EMPTY );
	if ( ! is_array( $chars ) ) {
		return array( ma_lfh_fit_placeholder_line( $word, $font, $font_size, $max_width ) );
	}

	$chunks = array();
	$current = '';
	foreach ( $chars as $char ) {
		$try = $current . $char;
		if ( '' !== $current && ma_lfh_text_width( $try . '-', $font, $font_size ) > $max_width ) {
			$chunks[] = $current . '-';
			$current = $char;
			continue;
		}
		$current = $try;
	}
	if ( '' !== $current ) {
		$chunks[] = $current;
	}

	return $chunks ?: array( $word );
}

function ma_lfh_fit_placeholder_line( $line, $font, $font_size, $max_width ) {
	$line = trim( (string) $line );
	if ( ma_lfh_text_width( $line, $font, $font_size ) <= $max_width ) {
		return $line;
	}

	$suffix = '...';
	while ( '' !== $line && ma_lfh_text_width( rtrim( $line ) . $suffix, $font, $font_size ) > $max_width ) {
		$line = preg_replace( '/.$/u', '', $line );
	}

	return rtrim( $line ) . $suffix;
}

function ma_lfh_wrap_placeholder_text_old_unused( $text, $font, $font_size, $max_width, $max_lines ) {
	$words = preg_split( '/\s+/', $text );
	$lines = array();
	$line  = '';

	foreach ( $words as $word ) {
		$try = trim( $line . ' ' . $word );
		if ( '' === $line || ma_lfh_text_width( $try, $font, $font_size ) <= $max_width ) {
			$line = $try;
			continue;
		}

		$lines[] = $line;
		$line = $word;
		if ( count( $lines ) >= $max_lines ) {
			break;
		}
	}

	if ( count( $lines ) < $max_lines && '' !== $line ) {
		$lines[] = $line;
	}

	if ( count( $lines ) > $max_lines ) {
		$lines = array_slice( $lines, 0, $max_lines );
	}

	if ( ! empty( $lines ) && count( $lines ) === $max_lines && count( $words ) > count( explode( ' ', implode( ' ', $lines ) ) ) ) {
		$lines[ count( $lines ) - 1 ] = rtrim( $lines[ count( $lines ) - 1 ], " \t\n\r\0\x0B.,;:" ) . '...';
	}

	return $lines;
}

function ma_lfh_text_width( $text, $font, $font_size ) {
	if ( $font && function_exists( 'imagettfbbox' ) ) {
		$box = imagettfbbox( $font_size, 0, $font, $text );
		if ( is_array( $box ) ) {
			return abs( $box[2] - $box[0] );
		}
	}

	return strlen( ma_lfh_placeholder_ascii_text( $text ) ) * $font_size * 0.62;
}

function ma_lfh_refresh_latest_planner() {
	$posts = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_key'       => '_ma_lfh_book_id',
		)
	);

	if ( empty( $posts ) ) {
		return false;
	}

	$post_id  = (int) $posts[0]->ID;
	$image_id = (int) get_post_thumbnail_id( $post_id );
	if ( ! $image_id ) {
		return false;
	}

	ma_lfh_sync_fsposter_planner( $post_id, $image_id );
	return true;
}

function ma_lfh_render_fsposter_channels() {
	$channels = ma_lfh_fsposter_channels();
	if ( empty( $channels ) ) {
		echo '<h2>FS Poster channels</h2><p>No active FS Poster channels were found.</p>';
		return;
	}

	echo '<h2>FS Poster channels</h2>';
	echo '<p>The library planner uses every active FS Poster channel. Story channels receive the short story text; feed channels receive the full caption.</p>';
	echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Channel</th><th>Detected use</th></tr></thead><tbody>';
	foreach ( $channels as $channel ) {
		$id    = isset( $channel['id'] ) ? (int) $channel['id'] : 0;
		$label = ma_lfh_fsposter_channel_label( $channel );
		$use   = ma_lfh_is_story_channel( $channel ) ? 'Story text only' : 'Full feed caption';
		echo '<tr><td>' . esc_html( (string) $id ) . '</td><td>' . esc_html( $label ) . '</td><td>' . esc_html( $use ) . '</td></tr>';
	}
	echo '</tbody></table>';
}

function ma_lfh_create_highlight_post( $status = 'publish', $sync_planner = true ) {
	if ( ! post_type_exists( 'book' ) ) {
		return 0;
	}

	$book = ma_lfh_select_library_book();
	if ( ! $book ) {
		return 0;
	}

	$image_id = ma_lfh_jpeg_cover_attachment_id( $book->ID );
	if ( ! $image_id ) {
		return 0;
	}

	$caption = ma_lfh_social_caption( $book );
	$content = ma_lfh_post_content( $book, $image_id, $caption );
	$title   = 'Library Highlight: ' . ma_lfh_book_title( $book );

	$post_id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'draft' === $status ? 'draft' : 'publish',
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => $caption,
		),
		true
	);

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return 0;
	}

	set_post_thumbnail( $post_id, $image_id );
	wp_update_post(
		array(
			'ID'          => $image_id,
			'post_parent' => (int) $post_id,
		)
	);

	update_post_meta( $post_id, '_ma_lfh_book_id', (string) $book->ID );
	update_post_meta( $post_id, '_ma_lfh_generated_at', current_time( 'mysql' ) );
	update_post_meta( $book->ID, '_ma_lfh_last_highlighted_at', current_time( 'mysql' ) );
	ma_lfh_remember_highlighted_book( $book->ID );

	wp_set_post_tags( $post_id, array( 'library-highlight', 'ma-house-library' ), false );
	$category_id = ma_lfh_category_id();
	if ( $category_id ) {
		wp_set_post_categories( $post_id, array( $category_id ), false );
	}

	if ( $sync_planner && 'publish' === get_post_status( $post_id ) ) {
		ma_lfh_sync_fsposter_planner( $post_id, $image_id );
	}

	return (int) $post_id;
}

function ma_lfh_select_library_book() {
	global $wpdb;

	$recent_book_ids = ma_lfh_recent_book_ids();
	$exclude_sql     = $recent_book_ids ? ' AND p.ID NOT IN (' . implode( ',', array_map( 'intval', $recent_book_ids ) ) . ')' : '';

	$book_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.ID
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} last ON last.post_id = p.ID AND last.meta_key = %s
			WHERE p.post_type = 'book'
				AND p.post_status = 'publish'
				AND (last.meta_value IS NULL OR last.meta_value < %s)
				{$exclude_sql}
			ORDER BY RAND()
			LIMIT 120",
			'_ma_lfh_last_highlighted_at',
			gmdate( 'Y-m-d H:i:s', time() - ( 120 * DAY_IN_SECONDS ) )
		)
	);

	if ( empty( $book_ids ) ) {
		$book_ids = $wpdb->get_col(
			"SELECT p.ID
			FROM {$wpdb->posts} p
			WHERE p.post_type = 'book'
				AND p.post_status = 'publish'
				{$exclude_sql}
			ORDER BY RAND()
			LIMIT 120"
		);
	}

	foreach ( $book_ids as $book_id ) {
		$book_id = (int) $book_id;
		if ( ! in_array( $book_id, $recent_book_ids, true ) && ma_lfh_book_is_postable( $book_id ) ) {
			return get_post( $book_id );
		}
	}

	return null;
}

function ma_lfh_select_book_with_image() {
	return ma_lfh_select_library_book();
}

function ma_lfh_remember_highlighted_book( $book_id ) {
	$book_id = (int) $book_id;
	if ( ! $book_id ) {
		return;
	}

	$ids = array_map( 'intval', (array) get_option( MA_LFH_RECENT_BOOK_OPTION, array() ) );
	array_unshift( $ids, $book_id );
	$ids = array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, MA_LFH_RECENT_BOOK_LIMIT );

	update_option( MA_LFH_RECENT_BOOK_OPTION, $ids, false );
}

function ma_lfh_recent_book_ids() {
	global $wpdb;

	$ids = array_map( 'intval', (array) get_option( MA_LFH_RECENT_BOOK_OPTION, array() ) );
	$recent_post_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT pm.meta_value
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s
				AND p.post_type = 'post'
				AND p.post_status IN ('publish', 'future', 'draft', 'pending')
				AND p.post_date >= %s
			ORDER BY p.post_date DESC
			LIMIT %d",
			'_ma_lfh_book_id',
			wp_date( 'Y-m-d H:i:s', time() - ( MA_LFH_RECENT_BOOK_DAYS * DAY_IN_SECONDS ) ),
			MA_LFH_RECENT_BOOK_LIMIT
		)
	);

	$ids = array_merge( $ids, array_map( 'intval', (array) $recent_post_ids ) );

	$all_highlighted_book_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT pm.meta_value
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s
				AND p.post_type = 'post'
				AND p.post_status IN ('publish', 'future', 'draft', 'pending', 'private')
				AND p.post_title LIKE %s
			ORDER BY p.post_date DESC
			LIMIT %d",
			'_ma_lfh_book_id',
			'Library Highlight:%',
			MA_LFH_RECENT_BOOK_LIMIT
		)
	);

	$ids = array_merge( $ids, array_map( 'intval', (array) $all_highlighted_book_ids ) );
	return array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, MA_LFH_RECENT_BOOK_LIMIT );
}

function ma_lfh_cover_url( $book_id ) {
	$url = trim( (string) get_post_meta( $book_id, '_ma_library_cover_url', true ) );
	return $url ? esc_url_raw( $url ) : '';
}

function ma_lfh_jpeg_cover_attachment_id( $book_id ) {
	$cover = ma_lfh_best_cover_editor( $book_id );
	if ( empty( $cover['editor'] ) || empty( $cover['tmp'] ) || empty( $cover['url'] ) ) {
		return 0;
	}

	$source_width  = empty( $cover['width'] ) ? 0 : (int) $cover['width'];
	$source_height = empty( $cover['height'] ) ? 0 : (int) $cover['height'];
	$source_score  = empty( $cover['score'] ) ? 0 : (int) $cover['score'];
	if ( max( $source_width, $source_height ) < 800 || $source_score < MA_LFH_IMAGE_GOOD_AREA ) {
		@unlink( $cover['tmp'] );
		return ma_lfh_create_placeholder_cover_for_book( $book_id );
	}

	$source_url = $cover['url'];
	$editor     = $cover['editor'];
	$tmp        = $cover['tmp'];
	$hash       = md5( MA_LFH_COVER_VERSION . '|' . $book_id . '|' . $source_url );
	$existing = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => '_ma_lfh_cover_source_hash',
					'value' => $hash,
				),
			),
		)
	);

	if ( ! empty( $existing ) ) {
		$existing_id = (int) $existing[0];
		$existing_meta = wp_get_attachment_metadata( $existing_id );
		$existing_w    = empty( $existing_meta['width'] ) ? 0 : (int) $existing_meta['width'];
		$existing_h    = empty( $existing_meta['height'] ) ? 0 : (int) $existing_meta['height'];
		if ( 'image/jpeg' === get_post_mime_type( $existing_id ) && file_exists( get_attached_file( $existing_id ) ) && min( $existing_w, $existing_h ) >= 800 ) {
			@unlink( $tmp );
			return $existing_id;
		}
	}

	$uploads = wp_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . 'ma-library-social';
	wp_mkdir_p( $dir );

	$filename = 'library-book-' . (int) $book_id . '-' . substr( $hash, 0, 10 ) . '.jpg';
	$target   = trailingslashit( $dir ) . $filename;
	$saved    = ma_lfh_save_square_social_jpeg( $tmp, $target );
	@unlink( $tmp );

	if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) {
		return 0;
	}

	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => sanitize_text_field( ma_lfh_clean_text( get_the_title( $book_id ) ) . ' cover social JPG' ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		),
		$saved['path']
	);

	if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
		return 0;
	}

	$metadata = wp_generate_attachment_metadata( $attachment_id, $saved['path'] );
	if ( ! is_wp_error( $metadata ) && ! empty( $metadata ) ) {
		wp_update_attachment_metadata( $attachment_id, $metadata );
	}

	update_post_meta( $attachment_id, '_ma_lfh_cover_source_hash', $hash );
	update_post_meta( $attachment_id, '_ma_lfh_cover_book_id', (string) $book_id );
	update_post_meta( $attachment_id, '_ma_lfh_cover_source_url', $source_url );
	if ( ! empty( $cover['width'] ) && ! empty( $cover['height'] ) ) {
		update_post_meta( $attachment_id, '_ma_lfh_cover_source_width', (string) (int) $cover['width'] );
		update_post_meta( $attachment_id, '_ma_lfh_cover_source_height', (string) (int) $cover['height'] );
		update_post_meta( $book_id, '_ma_lfh_cover_quality_width', (string) (int) $cover['width'] );
		update_post_meta( $book_id, '_ma_lfh_cover_quality_height', (string) (int) $cover['height'] );
		update_post_meta( $book_id, '_ma_lfh_cover_quality_checked_at', current_time( 'mysql' ) );
	}

	if ( $source_url && $source_url !== ma_lfh_cover_url( $book_id ) && ! empty( $cover['score'] ) && (int) $cover['score'] >= MA_LFH_IMAGE_GOOD_AREA ) {
		update_post_meta( $book_id, '_ma_library_cover_url', esc_url_raw( $source_url ) );
	}

	return (int) $attachment_id;
}

function ma_lfh_save_square_social_jpeg( $source_path, $target_path ) {
	if ( function_exists( 'imagecreatefromstring' ) && function_exists( 'imagecreatetruecolor' ) ) {
		$bytes = file_get_contents( $source_path );
		$src   = $bytes ? @imagecreatefromstring( $bytes ) : false;
		if ( $src ) {
			$src_w = imagesx( $src );
			$src_h = imagesy( $src );
			if ( $src_w > 0 && $src_h > 0 ) {
				$canvas_size = MA_LFH_SOCIAL_IMAGE_SIZE;
				$canvas      = imagecreatetruecolor( $canvas_size, $canvas_size );
				$bg          = imagecolorallocate( $canvas, 246, 244, 239 );
				imagefilledrectangle( $canvas, 0, 0, $canvas_size, $canvas_size, $bg );

				$scale = min( MA_LFH_SOCIAL_COVER_MAX_WIDTH / $src_w, MA_LFH_SOCIAL_COVER_MAX_HEIGHT / $src_h, 1.0 );
				$new_w = max( 1, (int) round( $src_w * $scale ) );
				$new_h = max( 1, (int) round( $src_h * $scale ) );
				$dst_x = (int) round( ( $canvas_size - $new_w ) / 2 );
				$dst_y = (int) round( ( $canvas_size - $new_h ) / 2 );

				$shadow = imagecolorallocatealpha( $canvas, 0, 0, 0, 105 );
				imagefilledrectangle( $canvas, $dst_x + 18, $dst_y + 18, $dst_x + $new_w + 18, $dst_y + $new_h + 18, $shadow );
				imagecopyresampled( $canvas, $src, $dst_x, $dst_y, 0, 0, $new_w, $new_h, $src_w, $src_h );
				imagejpeg( $canvas, $target_path, 92 );
				imagedestroy( $src );
				imagedestroy( $canvas );

				return array(
					'path' => $target_path,
					'file' => basename( $target_path ),
				);
			}

			imagedestroy( $src );
		}
	}

	$editor = wp_get_image_editor( $source_path );
	if ( is_wp_error( $editor ) ) {
		return $editor;
	}

	$editor->resize( MA_LFH_SOCIAL_IMAGE_SIZE, MA_LFH_SOCIAL_IMAGE_SIZE, false );
	$editor->set_quality( 92 );
	return $editor->save( $target_path, 'image/jpeg' );
}

function ma_lfh_best_cover_editor( $book_id ) {
	if ( ! function_exists( 'download_url' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( ! function_exists( 'wp_get_image_editor' ) ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	$best       = array();
	$best_score = 0;
	foreach ( ma_lfh_cover_urls( $book_id ) as $url ) {
		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			continue;
		}

		$editor = wp_get_image_editor( $tmp );
		if ( is_wp_error( $editor ) ) {
			@unlink( $tmp );
			continue;
		}

		$size   = $editor->get_size();
		$width  = empty( $size['width'] ) ? 0 : (int) $size['width'];
		$height = empty( $size['height'] ) ? 0 : (int) $size['height'];
		$score  = $width * $height;

		if ( $score >= MA_LFH_IMAGE_GOOD_AREA ) {
			if ( ! empty( $best['tmp'] ) ) {
				@unlink( $best['tmp'] );
			}
			return array(
				'editor' => $editor,
				'tmp'    => $tmp,
				'url'    => $url,
				'width'  => $width,
				'height' => $height,
				'score'  => $score,
			);
		}

		if ( $score > $best_score ) {
			if ( ! empty( $best['tmp'] ) ) {
				@unlink( $best['tmp'] );
			}
			$best = array(
				'editor' => $editor,
				'tmp'    => $tmp,
				'url'    => $url,
				'width'  => $width,
				'height' => $height,
				'score'  => $score,
			);
			$best_score = $score;
		} else {
			@unlink( $tmp );
		}
	}

	if ( $best_score >= MA_LFH_IMAGE_MIN_AREA ) {
		return $best;
	}

	if ( ! empty( $best['tmp'] ) ) {
		@unlink( $best['tmp'] );
	}

	return array();
}

function ma_lfh_cover_urls( $book_id ) {
	$urls = array();
	foreach ( ma_lfh_book_isbns( $book_id ) as $isbn ) {
		$urls[] = 'https://covers.openlibrary.org/b/isbn/' . rawurlencode( $isbn ) . '-L.jpg?default=false';
	}

	$stored = ma_lfh_cover_url( $book_id );
	if ( $stored ) {
		$urls = array_merge( $urls, ma_lfh_upgrade_cover_urls( $stored ) );
		$urls[] = $stored;
	}

	return array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
}

function ma_lfh_book_isbns( $book_id ) {
	$isbn  = (string) get_post_meta( $book_id, '_ma_library_isbn', true );
	$parts = preg_split( '/[,;\s]+/', $isbn );
	$clean = array();
	foreach ( $parts as $part ) {
		$part = preg_replace( '/[^0-9Xx]/', '', (string) $part );
		if ( strlen( $part ) >= 10 ) {
			$clean[] = strtoupper( $part );
		}
	}

	return array_values( array_unique( $clean ) );
}

function ma_lfh_upgrade_cover_url( $url ) {
	$urls = ma_lfh_upgrade_cover_urls( $url );
	return esc_url_raw( $urls ? $urls[0] : $url );
}

function ma_lfh_upgrade_cover_urls( $url ) {
	$url = trim( (string) $url );
	if ( ! $url ) {
		return array();
	}

	$urls = array( $url );
	if ( preg_match( '#covers\.openlibrary\.org/.+-(S|M)\.jpg#i', $url ) ) {
		array_unshift( $urls, preg_replace( '#-(S|M)\.jpg.*$#i', '-L.jpg?default=false', $url ) );
	}

	if ( false !== strpos( $url, 'books.google.' ) || false !== strpos( $url, 'books.googleusercontent.' ) ) {
		$google = remove_query_arg( array( 'edge' ), $url );
		$urls[] = add_query_arg( 'zoom', '0', $google );
	}

	if ( false !== strpos( $url, 'drive.google.com/thumbnail' ) ) {
		$drive = remove_query_arg( 'sz', $url );
		$drive_urls = array();
		foreach ( array( 'w1600', 'w1200', 'w1000', 'w800' ) as $size ) {
			$drive_urls[] = add_query_arg( 'sz', $size, $drive );
		}
		$urls = array_merge( $drive_urls, $urls );
	}

	return array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
}

function ma_lfh_post_content( $book, $image_id, $caption ) {
	$image = wp_get_attachment_image(
		$image_id,
		'full',
		false,
		array(
			'class'   => 'ma-lfh-cover-image wp-image-' . (int) $image_id,
			'data-id' => (string) (int) $image_id,
		)
	);

	$content = '';
	if ( $image ) {
		$content .= '<figure class="ma-lfh-cover-frame">' . $image . '</figure>' . "\n";
	}

	$content .= '<p>' . nl2br( esc_html( $caption ) ) . '</p>';
	$content .= "\n" . '<!-- ma-fsposter-library-cover:' . (int) $image_id . ' -->';

	return $content;
}

function ma_lfh_social_caption( $book ) {
	$title  = ma_lfh_book_title( $book );
	$author = ma_lfh_author_name( get_post_meta( $book->ID, '_ma_library_author', true ) );
	$year   = ma_lfh_clean_text( get_post_meta( $book->ID, '_ma_library_date', true ) );
	$summary = ma_lfh_book_social_summary( $book );

	$book_line = $title;
	if ( $author ) {
		$book_line .= ' by ' . $author;
	}
	if ( $year ) {
		$book_line .= ' (' . $year . ')';
	}

	$lines = array(
		'From the Ma\'s House Library:',
		$book_line,
	);

	if ( $summary ) {
		$lines[] = '';
		$lines[] = $summary;
	}

	$lines[] = '';
	$lines[] = 'The Ma\'s House Library is a free community library on Shinnecock land, centered on Indigenous books, artists, histories, and research.';
	$lines[] = '';
	$lines[] = 'Browse the catalog: https://www.mashouse.studio/library/';
	$lines[] = '';
	$lines[] = '#Mashouse #NativeAmericanBooks #IndigenousBooks #CommunityLibrary';

	$caption = implode( "\n", array_filter( $lines, 'ma_lfh_keep_line' ) );
	if ( function_exists( 'mb_strlen' ) && mb_strlen( $caption ) > 1900 ) {
		$caption = implode(
			"\n",
			array(
				'From the Ma\'s House Library:',
				$book_line,
				'',
				'The Ma\'s House Library is a free community library on Shinnecock land, centered on Indigenous books, artists, histories, and research.',
				'',
				'https://www.mashouse.studio/library/',
				'',
				'#Mashouse #IndigenousBooks #CommunityLibrary',
			)
		);
	}

	return $caption;
}

function ma_lfh_book_social_summary( $book ) {
	$reviewed_caption = ma_lfh_clean_text( get_post_meta( $book->ID, '_ma_library_social_caption', true ) );
	if ( ma_lfh_is_strong_book_text( $reviewed_caption, $book ) ) {
		return wp_trim_words( $reviewed_caption, 70 );
	}

	$short_description = ma_lfh_clean_text( get_post_meta( $book->ID, '_ma_library_short_description', true ) );
	if ( ma_lfh_is_strong_book_text( $short_description, $book ) ) {
		return wp_trim_words( $short_description, 55 );
	}

	$source = ma_lfh_clean_text( get_post_meta( $book->ID, '_ma_library_description_source', true ) );
	if ( ma_lfh_is_weak_description_source( $source ) ) {
		return '';
	}

	return ma_lfh_interest_fact( $book );
}

function ma_lfh_keep_line( $line ) {
	return '' === $line || null !== $line;
}

function ma_lfh_interest_fact( $book ) {
	$excerpt = $book->post_excerpt;
	if ( ! $excerpt ) {
		$excerpt = $book->post_content;
	}

	$text = ma_lfh_clean_text( $excerpt );
	if ( ! $text || $text === ma_lfh_clean_text( get_the_title( $book ) ) ) {
		return '';
	}

	$sentences = preg_split( '/(?<=[.!?])\s+/', $text );
	foreach ( $sentences as $sentence ) {
		$sentence = trim( $sentence );
		if ( strlen( $sentence ) >= 60 && ma_lfh_is_strong_book_text( $sentence, $book ) ) {
			return wp_trim_words( $sentence, 45 );
		}
	}

	if ( ! ma_lfh_is_strong_book_text( $text, $book ) ) {
		return '';
	}

	return wp_trim_words( $text, 45 );
}

function ma_lfh_book_is_postable( $book_id ) {
	$blocked_values = array( '1', 'yes', 'true', 'on' );
	$book = get_post( $book_id );
	if ( ! $book || 'book' !== $book->post_type || '' === ma_lfh_book_social_summary( $book ) ) {
		return false;
	}

	$do_not_post = strtolower( ma_lfh_clean_text( get_post_meta( $book_id, '_ma_library_do_not_post', true ) ) );
	if ( in_array( $do_not_post, $blocked_values, true ) ) {
		return false;
	}

	$needs_review = strtolower( ma_lfh_clean_text( get_post_meta( $book_id, '_ma_library_needs_manual_review', true ) ) );
	if ( in_array( $needs_review, $blocked_values, true ) ) {
		return false;
	}

	$status = strtolower( ma_lfh_clean_text( get_post_meta( $book_id, '_ma_library_caption_review_status', true ) ) );
	if ( in_array( $status, array( 'do not post', 'needs review', 'needs manual review', 'reject', 'rejected' ), true ) ) {
		return false;
	}

	return true;
}

function ma_lfh_is_strong_book_text( $text, $book = null ) {
	$text = ma_lfh_clean_text( $text );
	if ( strlen( $text ) < 80 ) {
		return false;
	}

	if ( $book instanceof WP_Post ) {
		$title = ma_lfh_clean_text( get_the_title( $book ) );
		if ( $title && 0 === strcasecmp( $text, $title ) ) {
			return false;
		}
	}

	$lower = strtolower( $text );
	$weak_phrases = array(
		'visitors can use this entry as a starting point',
		'supports research and browsing around',
		'is part of the ma s house library collection',
		'is part of the ma\'s house library collection',
		'the catalog record lists it as published',
		'good for readers interested in',
		'why this pick',
		'held in the ma s house library',
		'held in the ma\'s house library',
		'this entry can help visitors',
		'starting point for deciding whether to read',
	);

	foreach ( $weak_phrases as $phrase ) {
		if ( false !== strpos( $lower, $phrase ) ) {
			return false;
		}
	}

	return true;
}

function ma_lfh_is_weak_description_source( $source ) {
	$source = strtolower( ma_lfh_clean_text( $source ) );
	if ( '' === $source ) {
		return true;
	}

	return in_array(
		$source,
		array(
			'cleaned-catalog-summary',
			'weak-airtable-hidden',
			'missing',
			'openlibrary-title',
		),
		true
	);
}

function ma_lfh_clean_tags( $tags ) {
	$parts = array_filter( array_map( 'trim', explode( ',', (string) $tags ) ) );
	$parts = array_slice( array_unique( $parts ), 0, 3 );
	if ( empty( $parts ) ) {
		return '';
	}

	if ( 1 === count( $parts ) ) {
		return $parts[0];
	}

	$last = array_pop( $parts );
	return implode( ', ', $parts ) . ', and ' . $last;
}

function ma_lfh_book_title( $book ) {
	$title = ma_lfh_clean_text( get_the_title( $book ) );
	$parts = array_map( 'trim', explode( ':', $title ) );
	$deduped = array();

	foreach ( $parts as $part ) {
		if ( '' === $part ) {
			continue;
		}

		$last = end( $deduped );
		if ( false !== $last && 0 === strcasecmp( $last, $part ) ) {
			continue;
		}

		$deduped[] = $part;
	}

	return empty( $deduped ) ? $title : implode( ': ', $deduped );
}

function ma_lfh_author_name( $author ) {
	$author = ma_lfh_clean_text( $author );
	if ( preg_match( '/^([^,]+),\s*([^,]+)$/', $author, $matches ) ) {
		return trim( $matches[2] . ' ' . $matches[1] );
	}

	return $author;
}

function ma_lfh_clean_text( $text ) {
	$text = wp_strip_all_tags( (string) $text );
	for ( $i = 0; $i < 3; $i++ ) {
		$decoded = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
		if ( $decoded === $text ) {
			break;
		}
		$text = $decoded;
	}

	$text = str_replace( "\xc2\xa0", ' ', $text );
	return trim( preg_replace( '/\s+/', ' ', $text ) );
}

function ma_lfh_category_id() {
	$term = term_exists( 'Library Highlights', 'category' );
	if ( ! $term ) {
		$term = wp_insert_term(
			'Library Highlights',
			'category',
			array(
				'slug' => 'library-highlights',
			)
		);
	}

	if ( is_wp_error( $term ) ) {
		return 0;
	}

	return is_array( $term ) ? (int) $term['term_id'] : (int) $term;
}

function ma_lfh_sync_fsposter_planner( $post_id, $image_id, $next_execute_at = null ) {
	global $wpdb;

	$planner_table  = $wpdb->prefix . 'fsp_planners';
	$channels_table = $wpdb->prefix . 'fsp_channels';

	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $planner_table ) ) !== $planner_table ) {
		return;
	}
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $channels_table ) ) !== $channels_table ) {
		return;
	}

	$channels = ma_lfh_fsposter_channels();
	$channel_ids = array_values(
		array_filter(
			array_map(
				function( $channel ) {
					return isset( $channel['id'] ) ? (int) $channel['id'] : 0;
				},
				$channels
			)
		)
	);
	if ( empty( $channel_ids ) ) {
		return;
	}

	$channel_customization = array();
	foreach ( $channels as $channel ) {
		$channel_id = isset( $channel['id'] ) ? (int) $channel['id'] : 0;
		if ( ! $channel_id ) {
			continue;
		}

		$channel_customization[ $channel_id ] = array(
			'attach_link'          => false,
			'custom_link'          => '',
			'post_content'         => ma_lfh_is_story_channel( $channel ) ? MA_LFH_STORY_TEXT : '{post_excerpt}',
			'upload_media'         => true,
			'upload_media_type'    => 'featured_image',
			'media_list_to_upload' => array_filter( array( (int) $image_id ) ),
			'first_comment'        => '',
			'story_hashtag'        => '',
			'pin_the_post'         => false,
		);
	}

	$category_id = ma_lfh_category_id();

	$data = array(
		'title'                          => MA_LFH_PLANNER_TITLE,
		'post_type'                      => 'post',
		'status'                         => 'active',
		'channels'                       => implode( ',', $channel_ids ),
		'customization_data'             => wp_json_encode( $channel_customization ),
		'share_type'                     => 'interval',
		'sort_by'                        => 'random',
		'start_at'                       => current_time( 'mysql' ),
		'next_execute_at'                => $next_execute_at ? ma_lfh_clean_text( $next_execute_at ) : ma_lfh_next_interval_1pm_mysql(),
		'selected_posts'                 => (string) (int) $post_id,
		'shared_posts'                   => '',
		'repeating'                      => 1,
		'created_by'                     => 1,
		'created_at'                     => current_time( 'mysql' ),
		'blog_id'                        => get_current_blog_id(),
		'post_filters_date_range_from'   => null,
		'post_filters_date_range_to'     => null,
		'post_filters_term'              => null,
		'post_filters_skip_oos_products' => 0,
		'schedule_interval'              => MA_LFH_INTERVAL,
		'sleep_time_start'               => null,
		'sleep_time_end'                 => null,
		'weekly'                         => null,
		'updated_at'                     => current_time( 'mysql' ),
	);

	$existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$planner_table} WHERE title = %s ORDER BY id DESC LIMIT 1", MA_LFH_PLANNER_TITLE ) );
	if ( $existing_id ) {
		unset( $data['created_at'], $data['created_by'], $data['start_at'] );
		$wpdb->update( $planner_table, $data, array( 'id' => $existing_id ) );
	} else {
		$wpdb->insert( $planner_table, $data );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command(
		'ma-lfh schedule-makeup',
		static function( $args, $assoc_args ) {
			$time = isset( $assoc_args['time'] ) ? ma_lfh_clean_text( $assoc_args['time'] ) : wp_date( 'Y-m-d 18:00:00' );
			$avoid = isset( $assoc_args['avoid'] ) ? strtolower( ma_lfh_clean_text( $assoc_args['avoid'] ) ) : '';

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
		}
	);
}

function ma_lfh_fsposter_channels() {
	global $wpdb;

	$channels_table = $wpdb->prefix . 'fsp_channels';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $channels_table ) ) !== $channels_table ) {
		return array();
	}

	$channels = $wpdb->get_results( "SELECT * FROM {$channels_table} WHERE status = 1 AND is_deleted = 0 ORDER BY id ASC", ARRAY_A );
	return is_array( $channels ) ? $channels : array();
}

function ma_lfh_fsposter_channel_label( $channel ) {
	$parts = array();
	foreach ( array( 'driver', 'social_network', 'platform', 'provider', 'node_type', 'type' ) as $key ) {
		if ( ! empty( $channel[ $key ] ) ) {
			$parts[] = ma_lfh_clean_text( $channel[ $key ] );
		}
	}
	foreach ( array( 'name', 'title', 'screen_name', 'username', 'account_name', 'node_name', 'channel_name' ) as $key ) {
		if ( ! empty( $channel[ $key ] ) ) {
			$parts[] = ma_lfh_clean_text( $channel[ $key ] );
			break;
		}
	}

	$parts = array_values( array_unique( array_filter( $parts ) ) );
	if ( empty( $parts ) ) {
		return isset( $channel['id'] ) ? 'Channel ' . (int) $channel['id'] : 'Unknown channel';
	}

	return implode( ' / ', $parts );
}

function ma_lfh_is_story_channel( $channel ) {
	$story_fields = array( 'node_type', 'type', 'channel_type', 'post_type', 'publication_type', 'method' );
	foreach ( $story_fields as $field ) {
		if ( isset( $channel[ $field ] ) && false !== stripos( (string) $channel[ $field ], 'stor' ) ) {
			return true;
		}
	}

	$label = strtolower( ma_lfh_fsposter_channel_label( $channel ) );
	if ( false !== strpos( $label, 'story' ) || false !== strpos( $label, 'stories' ) ) {
		return true;
	}

	return false;
}

function ma_lfh_next_1pm_mysql() {
	return wp_date( 'Y-m-d H:i:s', ma_lfh_next_1pm_timestamp() );
}

function ma_lfh_next_interval_1pm_mysql() {
	return wp_date( 'Y-m-d H:i:s', ma_lfh_next_interval_1pm_timestamp() );
}
