<?php
/**
 * Plugin Name:         Easy Digital Downloads - Version API
 * Plugin URI:          http://164a.com
 * Description:         Adds a /versions/ endpoint to the EDD REST API. Useful if you're using Software Licensing to handle automatic plugin/theme upgrades.
 * Version:             0.3.0
 * Author:              Studio 164a
 * Author URI:          https://164a.com
 * Requires at least:   4.5
 * Tested up to:        5.1.1
 *
 * Text Domain:         eddvapi
 * Domain Path:         /languages/
 *
 * @package             EDD Versions API
 * @author              Eric Daams
 * @copyright           Copyright (c) 2015, Studio 164a
 * @license             http://opensource.org/licenses/gpl-2.0.php GNU Public License
 */

if ( ! defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly

/**
 * Adds 'versions' as an accepted query mode to the EDD REST API.
 *
 * @param   string[] $modes
 * @return  string[]
 * @since   0.1.0
 */
function eddvapi_add_versions_query_mode( $modes ) {
	$modes[] = 'versions';
	$modes[] = 'versions-v2';
	return $modes;
}

add_filter( 'edd_api_valid_query_modes', 'eddvapi_add_versions_query_mode' );
add_filter( 'edd_api_public_query_modes', 'eddvapi_add_versions_query_mode' );

/**
 * Returns the data.
 *
 * @global  WPDB $wpdb
 * @param   mixed $data
 * @param   string $endpoint
 * @return  array
 * @since   0.1.0
 */
function eddvapi_get_versions_data( $data, $endpoint, $api ) {
	global $wpdb;

	if ( 'versions' != $endpoint ) {
		return $data;
	}

	/**
	 * Don't log these requests.
	 */
	add_filter( 'edd_api_log_requests', '__return_false' );

	$sql = "SELECT p.post_title AS name, m.meta_value AS new_version
			FROM $wpdb->postmeta m
			INNER JOIN $wpdb->posts p
			ON m.post_id = p.ID
			WHERE m.meta_key = '_edd_sl_version'
			AND m.meta_value != ''
			AND p.post_type = 'download'";

	$versions = $wpdb->get_results( $sql, ARRAY_A );

	$data = array();

	foreach ( $versions as $version ) {
		$data[ $version[ 'name' ] ] = $version;
	}

	if ( ! isset( $_POST['licenses'] ) || ! isset( $_POST['url'] ) ) {
		return $versions;
	}

	foreach ( eddvapi_get_licensed_downloads( $_POST['licenses'] ) as $download_license ) {
		$data = eddvapi_get_licensed_download_response( $download_license->download_id, $download_license->license, $data );
	}

	/**
	 * Turn request logging back on.
	 */
	remove_filter( 'edd_api_log_requests', '__return_false' );

	return $data;
}

add_filter( 'edd_api_output_data', 'eddvapi_get_versions_data', 10, 3 );

/**
 * Returns the data.
 *
 * @global  WPDB $wpdb
 * @param   mixed $data
 * @param   string $endpoint
 * @return  array
 * @since   0.1.0
 */
function eddvapi_get_versions_data_new( $data, $endpoint, $api ) {
	global $wpdb;

	if ( 'versions-v2' != $endpoint ) {
		return $data;
	}

	/**
	 * Don't log these requests.
	 */
	add_filter( 'edd_api_log_requests', '__return_false' );

	$data = get_transient( 'charitable_plugin_versions' );

	if ( false === $data ) {
		$sql = "SELECT p.ID as download_id, p.post_title AS name, m.meta_value AS new_version
			FROM $wpdb->postmeta m
			INNER JOIN $wpdb->posts p
			ON m.post_id = p.ID
			WHERE m.meta_key = '_edd_sl_version'
			AND m.meta_value != ''
			AND p.post_type = 'download'";

		$versions = $wpdb->get_results( $sql );
		$data     = array_map( function( $version ) {

			$download = get_post( $version->download_id );

			if ( ! $download ) {
				return;
			}

			$slug         = ! empty( $slug ) ? $slug : $download->post_name;
			$description  = ! empty( $download->post_excerpt ) ? $download->post_excerpt : $download->post_content;
			$changelog    = get_post_meta( $version->download_id, '_edd_sl_changelog', true );
			$requirements = array_filter( (array) get_post_meta( $version->download_id, '_edd_minimum_requirements', true ) );
			$response     = array(
				'download_id'   => $version->download_id,
				'name'          => $version->name,
				'new_version'   => $version->new_version,
				'slug'          => $slug,
				'url'           => esc_url( add_query_arg( 'changelog', '1', get_permalink( $version->download_id ) ) ),
				'last_updated'  => $download->post_modified,
				'homepage'      => get_permalink( $version->download_id ),
				'package'       => 'missing_license', // Default package/download link if no license key is provided.
				'download_link' => 'missing_license', // Default package/download link if no license key is provided.
				'requirements'  => $requirements,
				'sections'      => serialize(
					array(
						'description' => wpautop( strip_tags( $description, '<p><li><ul><ol><strong><a><em><span><br>' ) ),
						'changelog'   => wpautop( strip_tags( stripslashes( $changelog ), '<p><li><ul><ol><strong><a><em><span><br>' ) ),
					)
				),
			);

			return apply_filters( 'eddvapi_sl_license_response', $response, $download );

		}, $versions );

		set_transient( 'charitable_plugin_versions', $data );

	}

	if ( ! array_key_exists( 'url', $_POST ) || ! array_key_exists( 'licenses', $_POST ) ) {
		return $data;
	}

	$licensed = eddvapi_get_licensed_downloads( $_POST['licenses'] );

	if ( is_array( $licensed ) ) {

		$licensed = array_combine( wp_list_pluck( $licensed, 'download_id' ), $licensed );
		$sl       = edd_software_licensing();
		$data     = array_map( function( $response ) use ( $licensed, $sl ) {

			$license_details = array_key_exists( $response['download_id'], $licensed ) ? $licensed[ $response['download_id'] ] : '';

			if ( empty( $license_details ) ) {
				return $response;
			}

			switch ( $license_details->status ) {
				case 'expired':
					$package = 'expired_license';

					// Send back a renewal link.
					if ( isset( $license_details->license_id ) ) {
						$license_parent     = get_post_field( 'post_parent', $license_details->license_id );
						$renewal_license_id = $license_parent ? $license_parent : $license_details->license_id;
						$response['renewal_link'] = $sl->get_renewal_url( $renewal_license_id );
					}

					break;

				default:
					$package = $sl->get_encoded_download_package_url( $response['download_id'], $license_details->license, $_POST['url'] );
			}

			$response['package'] = $package;
			$response['download_link'] = $package;

			return $response;

		}, $data );

	}

	/**
	 * Turn request logging back on.
	 */
	remove_filter( 'edd_api_log_requests', '__return_false' );

	return $data;
}

add_filter( 'edd_api_output_data', 'eddvapi_get_versions_data_new', 10, 3 );

/**
 * Return the license response data for a particular download & license key.
 *
 * @param   int $download_id
 * @param   string $license
 * @param   array $data
 * @return  array
 * @since   0.1.0
 */
function eddvapi_get_licensed_download_response( $download_id, $license, $data ) {

	$sl = edd_software_licensing();

	$download = get_post( $download_id );

	if ( ! $download ) {
		return;
	}

	$url         = $_POST['url'];
	$name        = $download->post_title;
	$slug        = ! empty( $slug ) ? $slug : $download->post_name;
	$description = ! empty( $download->post_excerpt ) ? $download->post_excerpt : $download->post_content;
	$changelog   = get_post_meta( $download_id, '_edd_sl_changelog', true );
	$package     = $sl->get_encoded_download_package_url( $download_id, $license, $url );
	$response    = array_merge( $data[ $name ], array(
		'slug'          => $slug,
		'url'           => esc_url( add_query_arg( 'changelog', '1', get_permalink( $download_id ) ) ),
		'last_updated'  => $download->post_modified,
		'homepage'      => get_permalink( $download_id ),
		'package'       => $package,
		'download_link' => $package,
		'sections'      => serialize(
			array(
				'description' => wpautop( strip_tags( $description, '<p><li><ul><ol><strong><a><em><span><br>' ) ),
				'changelog'   => wpautop( strip_tags( stripslashes( $changelog ), '<p><li><ul><ol><strong><a><em><span><br>' ) ),
			)
		),
	) );

	$data[ $name ] = apply_filters( 'eddvapi_sl_license_response', $response, $download );

	return $data;

}

/**
 * Return all downloads for the given collection of licenses.
 *
 * @global  WPDB $wpdb
 * @param   array $licenses
 * @return  array
 * @since   0.1.0
 */
function eddvapi_get_licensed_downloads( $licenses ) {
	global $wpdb;

	$licenses  = array_filter( $licenses );
	$cache_key = implode( ':', $licenses );
	$results   = wp_cache_get( $cache_key, 'charitable_licensed_downloads' );

	if ( false === $results ) {
		// error_log( sprintf( 'charitable_licensed_downloads not cached: %s', $cache_key ) );
		$placeholders = implode( ', ', array_fill( 0, count( $licenses ), '%s' ) );

		$sql = "SELECT m2.post_id AS license_id, m2.meta_value AS license, m1.meta_value AS download_id, m3.meta_value AS status
				FROM $wpdb->postmeta m1
				INNER JOIN $wpdb->postmeta m2 ON (
					m2.post_id = m1.post_id
					AND m1.meta_key = '_edd_sl_download_id'
				)
				INNER JOIN $wpdb->postmeta m3 ON (
					m3.post_id = m1.post_id
					AND m3.meta_key = '_edd_sl_status'
				)
				WHERE m2.meta_key = '_edd_sl_key'
				AND m2.meta_value IN ( $placeholders )";

		$results = $wpdb->get_results( $wpdb->prepare( $sql, $licenses ) );

		wp_cache_set( $cache_key, $results, 'charitable_licensed_downloads', DAY_IN_SECONDS );
	} else {
		// error_log( sprintf( 'charitable_licensed_downloads cached: %s', $cache_key ) );
	}

	return $results;
}

/**
 * Clear transient when a download is saved.
 */
function eddvapi_clear_download_versions_cache() {
	delete_transient( 'charitable_plugin_versions' );
	wp_cache_flush();
}

add_action( 'save_post_download', 'eddvapi_clear_download_versions_cache' );

/**
 * Add metabox for minimum requirements.
 *
 * @since  0.3.0
 *
 * @return void
 */
add_action(
	'add_meta_boxes',
	function() {
		add_meta_box(
			'charitable-minimum-requirements',
			'Minimum Requirements',
			function() {
				global $post;

				$requirements = get_post_meta( $post->ID, '_edd_minimum_requirements', true );
				?>
				<input type="hidden" name="charitable_min_requirements_meta_box_nonce" value="<?php echo wp_create_nonce( basename( __FILE__ ) ); ?>" />
				<table class="form-table">
					<tr class="edd_sl_toggled_row">
						<td class="edd_field_type_text" colspan="2">
							<label for="edd_sl_upgrade_file"><strong>PHP Version</strong></label><br/>
							<input type="text" class="medium-text" style="width:80px;" name="_edd_minimum_requirements[php]" id="edd_minimum_requirements[php]" value="<?php echo esc_attr( $requirements['php'] ); ?>"/>&nbsp;
						</td>
					</tr>
					<tr class="edd_sl_toggled_row">
						<td class="edd_field_type_text" colspan="2">
							<label for="edd_sl_upgrade_file"><strong>Charitable Version</strong></label><br/>
							<input type="text" class="medium-text" style="width:80px;" name="_edd_minimum_requirements[charitable]" id="edd_minimum_requirements[charitable]" value="<?php echo esc_attr( $requirements['charitable'] ); ?>"/>&nbsp;
						</td>
					</tr>
				</table>
				<?php
			},
			'download',
			'normal',
			'core'
		);
	}
);

/**
 * Save the minimum requirements.
 *
 * @since  0.3.0
 *
 * @param  int $post_id The download id.
 * @return void
 */
add_action(
	'save_post',
	function( $post_id ) {

		global $post;

		// verify nonce
		if ( ! isset( $_POST['charitable_min_requirements_meta_box_nonce'] ) || ! wp_verify_nonce( $_POST['charitable_min_requirements_meta_box_nonce'], basename( __FILE__ ) ) ) {
			return $post_id;
		}

		// Check for auto save / bulk edit
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || isset( $_REQUEST['bulk_edit'] ) ) {
			return $post_id;
		}

		if ( isset( $_POST['post_type'] ) && 'download' != $_POST['post_type'] ) {
			return $post_id;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_edd_minimum_requirements', $_POST['_edd_minimum_requirements'] );
	}
);
