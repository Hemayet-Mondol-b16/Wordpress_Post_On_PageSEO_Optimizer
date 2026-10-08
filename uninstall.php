<?php
/**
 * Uninstall cleanup.
 *
 * Always removes settings, API keys and transients. Original-content backups are
 * removed only if "delete data" was enabled. Optimized product content is kept.
 *
 * @package PostSeoOptimizer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean up one site.
 */
function bzpso_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'bzpso_settings' );

	if ( is_array( $settings ) && ! empty( $settings['delete_data'] ) ) {
		delete_post_meta_by_key( '_bzpso_original' );
		delete_post_meta_by_key( '_bzpso_optimized' );
		delete_post_meta_by_key( '_bzpso_optimized_by' );
	}

	delete_option( 'bzpso_settings' );
	delete_option( 'bzpso_api_keys' );

	// All plugin transients (locks, rate limits, jobs, Gemini model capabilities) and job claims.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_bzpso_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_bzpso_' ) . '%',
			$wpdb->esc_like( 'bzpso_claim_' ) . '%'
		)
	);

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'bzpso_run_job' );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $bzpso_site_id ) {
		switch_to_blog( $bzpso_site_id );
		bzpso_uninstall_site();
		restore_current_blog();
	}
} else {
	bzpso_uninstall_site();
}
