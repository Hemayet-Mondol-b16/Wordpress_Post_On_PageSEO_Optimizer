<?php
/**
 * Per-user request limiting.
 *
 * @package PostSeoOptimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prevents duplicate concurrent AI calls per user and caps hourly usage, protecting
 * the API budget and the server's PHP workers.
 */
class BZPSO_Rate_Limiter {

	/**
	 * Acquire the per-user lock.
	 *
	 * @param int $user_id User id.
	 * @param int $ttl     Seconds after which a forgotten lock expires on its own.
	 * @return bool False if a request is already running.
	 */
	public static function lock( $user_id, $ttl = 180 ) {
		$key = 'bzpso_lock_' . (int) $user_id;
		if ( get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, max( 60, (int) $ttl ) );
		return true;
	}

	/**
	 * Release the per-user lock.
	 *
	 * @param int $user_id User id.
	 */
	public static function unlock( $user_id ) {
		delete_transient( 'bzpso_lock_' . (int) $user_id );
	}

	/**
	 * Count a request against the hourly limit.
	 *
	 * @param int $user_id User id.
	 * @return true|WP_Error
	 */
	public static function hit( $user_id ) {
		$limit = (int) BZPSO_Settings::get( 'hourly_limit' );
		if ( $limit <= 0 ) {
			return true;
		}

		$key   = 'bzpso_rate_' . (int) $user_id;
		$now   = time();
		$state = get_transient( $key );
		if ( ! is_array( $state ) || ! isset( $state['count'], $state['reset'] ) || $state['reset'] <= $now ) {
			$state = array(
				'count' => 0,
				'reset' => $now + HOUR_IN_SECONDS,
			);
		}

		if ( $state['count'] >= $limit ) {
			return new WP_Error(
				'bzpso_rate_limited',
				/* translators: 1: request limit, 2: human-readable time. */
				sprintf( __( 'You reached the limit of %1$d AI requests per hour. Try again in %2$s.', 'post-seo-optimizer' ), $limit, human_time_diff( $now, $state['reset'] ) )
			);
		}

		++$state['count'];
		set_transient( $key, $state, max( 1, $state['reset'] - $now ) );
		return true;
	}
}
