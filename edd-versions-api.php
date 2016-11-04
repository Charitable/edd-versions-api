<?php
/**
 * Plugin Name:         Easy Digital Downloads - Version API
 * Plugin URI:          http://164a.com
 * Description:         Adds a /versions/ endpoint to the EDD REST API. Useful if you're using Software Licensing to handle automatic plugin/theme upgrades.
 * Version:             0.1.1
 * Author:              Studio 164a
 * Author URI:          https://164a.com
 * Requires at least:   4.5
 * Tested up to:        4.5.3
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
    $api->log_requests = false;

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
		$data[ $version['name'] ] = $version;
	}

    if ( ! isset( $_POST['licenses'] ) || ! isset( $_POST['url'] ) ) {
        return $versions;
    }

	foreach ( eddvapi_get_licensed_downloads( $_POST['licenses'] ) as $download_license ) {

		$data = eddvapi_get_licensed_download_response( $download_license->download_id, $download_license->license, $data );

	}

	return $data;

}

add_filter( 'edd_api_output_data', 'eddvapi_get_versions_data', 10, 3 );

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

	$licenses = array_filter( $licenses );

	$placeholders = array_fill( 0, count( $licenses ), '%s' );

	$placeholders = implode( ', ', $placeholders );

	$sql = "SELECT m2.meta_value AS license, m1.meta_value AS download_id 
			FROM $wpdb->postmeta m1
			INNER JOIN $wpdb->postmeta m2 ON (
				m2.post_id = m1.post_id
				AND m1.meta_key = '_edd_sl_download_id'
			)
			WHERE m2.meta_key = '_edd_sl_key'
			AND m2.meta_value IN ( $placeholders )";

	return $wpdb->get_results( $wpdb->prepare( $sql, $licenses ) );

}


// add_action( 'init', function() {
//     eddvapi_get_versions_data( array(), 'versions' );
// });