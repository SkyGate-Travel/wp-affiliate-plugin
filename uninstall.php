<?php
/**
 * Runs when the plugin is deleted (not merely deactivated).
 *
 * Removes settings and every cached response. The auto-created search page is
 * left in place — it may have been edited, republished or linked to, and
 * deleting a visitor-facing page on uninstall would be a nasty surprise.
 *
 * @package SkyAffiliateSearch
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'sky_aff_settings' );
delete_option( 'sky_aff_cache_epoch' );
delete_option( 'sky_aff_search_page_id' );

global $wpdb;

// Transients have no group API, so the cached responses and rate-limit
// counters are cleared by name pattern.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$sky_aff_names = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_sky_aff_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_sky_aff_' ) . '%'
	)
);

foreach ( $sky_aff_names as $sky_aff_name ) {
	delete_option( $sky_aff_name );
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery

if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
	wp_cache_flush_group( 'transient' );
}
