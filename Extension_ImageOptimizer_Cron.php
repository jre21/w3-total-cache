<?php
/**
 * File: Extension_ImageOptimizer_Cron.php
 *
 * @since X.X.X
 *
 * @package W3TC
 */

namespace W3TC;

/**
 * Class: Extension_ImageOptimizer_Cron
 *
 * @since X.X.X
 */
class Extension_ImageOptimizer_Cron {
	/**
	 * Add cron job/event.
	 *
	 * @since X.X.X
	 * @static
	 */
	public static function add_cron() {
		if ( ! wp_next_scheduled( 'w3tc_optimager_cron' ) ) {
			wp_schedule_event( time(), 'ten_seconds', 'w3tc_optimager_cron' );
		}
	}

	/**
	 * Add cron schedule.
	 *
	 * @since X.X.X
	 * @static
	 */
	public static function add_schedule() {
		$schedules['ten_seconds'] = array(
			'interval' => 10,
			'display'  => esc_html__( 'Every Ten Seconds' ),
		);
		return $schedules;
	}

	/**
	 * Remove cron job/event.
	 *
	 * @since X.X.X
	 * @static
	 */
	public static function delete_cron() {
		$timestamp = wp_next_scheduled( 'w3tc_optimager_cron' );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'w3tc_optimager_cron' );
		}
	}

	/**
	 * Run the cron event.
	 *
	 * @since X.X.X
	 *
	 * @global $wp_filesystem WP_Filesystem.
	 */
	public static function run() {
		// Get all images with postmeta key "w3tc_optimager".
		$results = new \WP_Query(
			array(
				'post_type'           => 'attachment',
				'post_status'         => 'inherit',
				'post_mime_type'      => Extension_ImageOptimizer_Plugin_Admin::$mime_types,
				'posts_per_page'      => -1,
				'ignore_sticky_posts' => true,
				'suppress_filters'    => true,
				'meta_key'            => 'w3tc_optimager', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		// If there are matches, then load dependencies before use.
		if ( $results->have_posts() ) {
			require_once __DIR__ . '/Extension_ImageOptimizer_Plugin_Admin.php';
			require_once __DIR__ . '/Extension_ImageOptimizer_Api.php';
			$api = new Extension_ImageOptimizer_Api();

			$wp_upload_dir = wp_upload_dir();

			global $wp_filesystem;

			// Make sure that this file is included, as wp_generate_attachment_metadata() depends on it.
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		foreach ( $results->posts as $post ) {
			$postmeta = get_post_meta( $post->ID, 'w3tc_optimager', true );
			$status   = isset( $postmeta['status'] ) ? $postmeta['status'] : null;

			// Handle items with the "processing" status.
			if ( 'processing' === $status && isset( $postmeta['processing']['job_id'] ) && isset( $postmeta['processing']['signature'] ) ) {
				// Check the status of the request.
				$response = $api->get_status( $postmeta['processing']['job_id'], $postmeta['processing']['signature'] );

				// Save the status response.
				Extension_ImageOptimizer_Plugin_Admin::update_postmeta(
					$post->ID,
					array( 'job_status' => $response )
				);

				// Check if image is ready for pickup/download.
				if ( isset( $response['status'] ) && 'pickup' === $response['status'] ) {
					// Download image.
					$response = $api->download( $postmeta['processing']['job_id'], $postmeta['processing']['signature'] );
					$headers  = wp_remote_retrieve_headers( $response );
					$is_error = isset( $response['error'] );

					// Save the download headers or error.
					Extension_ImageOptimizer_Plugin_Admin::update_postmeta(
						$post->ID,
						array(
							'download' => $is_error ? $response['error'] : (array) $headers,
							'status'   => $is_error ? 'error' : 'optimized',
						)
					);

					// Skip error responses.
					if ( $is_error ) {
						continue;
					}

					// Save the file.
					$original_filepath = get_attached_file( $post->ID );
					$original_size     = wp_getimagesize( $original_filepath );
					$original_filename = basename( get_attached_file( $post->ID ) );
					$original_filedir  = str_replace( '/' . $original_filename, '', $original_filepath );
					$extension         = isset( $headers['X-Mime-Type-Out'] ) ?
						str_replace( 'image/', '', $headers['X-Mime-Type-Out'] ) : 'webp';
					$new_filename      = preg_replace( '/\.[^.]+$/', '', $original_filename ) . '.' . $extension;
					$new_filepath      = $original_filedir . '/' . $new_filename;

					if ( is_a( $wp_filesystem, 'WP_Filesystem_Base' ) ) {
						$wp_filesystem->put_contents( $new_filepath, wp_remote_retrieve_body( $response ) );
					} else {
						Util_File::file_put_contents_atomic( $new_filepath, wp_remote_retrieve_body( $response ) );
					}

					// Insert as attachment post.
					$post_id = wp_insert_attachment(
						array(
							'guid'           => $new_filepath,
							'post_mime_type' => $headers['x-mime-type-out'],
							'post_title'     => preg_replace( '/\.[^.]+$/', '', $new_filename ),
							'post_content'   => '',
							'post_status'    => 'inherit',
							'post_parent'    => $post->ID,
							'comment_status' => 'closed',
						),
						$new_filepath,
						$post->ID,
						false,
						false
					);

					// Copy postmeta data to the new attachment.
					Extension_ImageOptimizer_Plugin_Admin::copy_postmeta( $post->ID, $post_id );

					// Save the new post id.
					Extension_ImageOptimizer_Plugin_Admin::update_postmeta(
						$post->ID,
						array(
							'post_child' => $post_id,
						)
					);

					// Generate the metadata for the attachment, and update the database record.
					$attach_data           = wp_generate_attachment_metadata( $post_id, $new_filepath );
					$attach_data['width']  = $original_size[0];
					$attach_data['height'] = $original_size[1];
					wp_update_attachment_metadata( $post_id, $attach_data );
				} elseif ( isset( $response['status'] ) && 'complete' === $response['status'] ) {
					// Update the status to "error".
					Extension_ImageOptimizer_Plugin_Admin::update_postmeta(
						$post->ID,
						array( 'status' => 'error' )
					);
				}
			}
		}
	}
}