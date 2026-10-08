<?php
/**
 * Background generation jobs.
 *
 * @package PostSeoOptimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs the AI request outside the browser's request, so slow models can take up to
 * the configured timeout (max 10 minutes) without hitting web server, proxy or
 * Cloudflare limits.
 *
 * Flow: the generate AJAX call creates a job and returns at once. The job is started
 * by a non-blocking loopback request to this site (running as the same user), with an
 * Action Scheduler action as a backup in case loopbacks are blocked. The browser polls
 * the status endpoint until the job is done.
 *
 * Jobs are stored as transients; an atomic "claim" row guarantees a job runs once.
 */
class BZPSO_Jobs {

	const HOOK         = 'bzpso_run_job';
	const GROUP        = 'post-seo-optimizer';
	const PREFIX       = 'bzpso_job_';
	const CLAIM_PREFIX = 'bzpso_claim_';

	/**
	 * Seconds a job may stay queued before it is reported as not started.
	 */
	const START_GRACE = 180;

	/**
	 * Register the loopback worker endpoint (logged-in only).
	 */
	public function __construct() {
		add_action( 'wp_ajax_bzpso_worker', array( $this, 'worker' ) );
	}

	/**
	 * Create and start a job.
	 *
	 * @param int   $product_id Product id.
	 * @param int   $user_id    User id.
	 * @param array $args       language, keyword, instructions.
	 * @return string Job id.
	 */
	public static function create( $product_id, $user_id, array $args ) {
		$id  = strtolower( wp_generate_password( 32, false, false ) );
		$job = array(
			'id'       => $id,
			'user'     => (int) $user_id,
			'product'  => (int) $product_id,
			'args'     => $args,
			'status'   => 'queued',
			'created'  => time(),
			'started'  => 0,
			'finished' => 0,
			'result'   => null,
			'error'    => '',
		);
		self::save( $job );

		// Backup starter in case loopback requests are blocked on this server.
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 30, self::HOOK, array( $id ), self::GROUP );
		}
		self::dispatch( $id );

		return $id;
	}

	/**
	 * Fire a non-blocking request to this site that runs the job as the current user.
	 *
	 * @param string $id Job id.
	 */
	private static function dispatch( $id ) {
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter for loopback requests.
				// Same technique as WordPress core and WP Background Processing: forward
				// the current login cookies so the worker runs as this user.
				'cookies'   => $_COOKIE, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'body'      => array(
					'action' => 'bzpso_worker',
					'job'    => $id,
					'nonce'  => wp_create_nonce( 'bzpso_worker_' . $id ),
				),
			)
		);
	}

	/**
	 * Loopback worker endpoint.
	 */
	public function worker() {
		$id = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Used to build the nonce action below.
		if ( '' === $id || ! check_ajax_referer( 'bzpso_worker_' . $id, 'nonce', false ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		$job = self::get( $id );
		if ( ! $job || get_current_user_id() !== $job['user'] || ! current_user_can( 'edit_post', $job['product'] ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		// Keep running after the caller disconnects; release the connection early if possible.
		ignore_user_abort( true );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		self::run( $id );
		wp_die();
	}

	/**
	 * Run a job (from the loopback worker or Action Scheduler). Safe to call twice:
	 * only the first caller claims the job.
	 *
	 * @param string $id Job id.
	 */
	public static function run( $id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return; // WooCommerce is not active.
		}
		$id  = sanitize_key( $id );
		$job = self::get( $id );
		if ( ! $job || 'queued' !== $job['status'] || ! self::claim( $id ) ) {
			return;
		}

		$job['status']  = 'running';
		$job['started'] = time();
		self::save( $job );

		$settings = BZPSO_Settings::get();
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( (int) $settings['timeout'] + 120 );
		}

		try {
			$result = self::execute( $job );
		} catch ( \Throwable $e ) {
			$result = new WP_Error( 'bzpso_job', $e->getMessage() );
		}

		$job['finished'] = time();
		if ( is_wp_error( $result ) ) {
			$job['status'] = 'error';
			$job['error']  = $result->get_error_message();
		} else {
			$job['status'] = 'done';
			$job['result'] = $result;
		}
		self::save( $job );

		delete_option( self::CLAIM_PREFIX . $id );
		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( self::HOOK, array( $id ), self::GROUP );
		}
		BZPSO_Rate_Limiter::unlock( $job['user'] );
	}

	/**
	 * The actual AI work for a job.
	 *
	 * @param array $job Job.
	 * @return array|WP_Error Data for the review screen.
	 */
	private static function execute( array $job ) {
		$product = wc_get_product( $job['product'] );
		if ( ! $product ) {
			return new WP_Error( 'bzpso_job', __( 'Product not found.', 'post-seo-optimizer' ) );
		}

		$provider = BZPSO_Provider::create();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$fields = isset( $job['args']['fields'] ) ? (array) $job['args']['fields'] : array();
		$prompt = BZPSO_Prompt::build( BZPSO_Product_Data::source_data( $product, $fields ), $job['args'] );
		$raw    = $provider->generate( $prompt['system'], $prompt['user'] );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$parsed = BZPSO_Sanitizer::parse_response( $raw );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$suggested = BZPSO_Sanitizer::suggestions( $parsed, $fields );
		if ( ! array_filter( $suggested ) ) {
			return new WP_Error( 'bzpso_empty', __( 'The AI did not return any usable suggestions. Please try again.', 'post-seo-optimizer' ) );
		}

		return array(
			'current'   => BZPSO_Product_Data::display_values( $product ),
			'suggested' => $suggested,
			'provider'  => $provider->label(),
			'model'     => $provider->model(),
			'fallback'  => $provider->used_fallback(),
			'usage'     => $provider->usage(),
		);
	}

	/**
	 * Status of a job for its owner. Finished jobs are deleted once reported; jobs
	 * that never started or ran far too long are failed.
	 *
	 * @param string $id         Job id.
	 * @param int    $user_id    Requesting user.
	 * @param int    $product_id Product the request is for.
	 * @return array|WP_Error {status, elapsed, data?}
	 */
	public static function status( $id, $user_id, $product_id ) {
		$job = self::get( $id );
		if ( ! $job || (int) $user_id !== $job['user'] || (int) $product_id !== $job['product'] ) {
			return new WP_Error( 'bzpso_job_missing', __( 'This request has expired. Please generate the suggestions again.', 'post-seo-optimizer' ) );
		}

		$now     = time();
		$timeout = (int) BZPSO_Settings::get( 'timeout' );

		if ( 'queued' === $job['status'] && $now - $job['created'] > self::START_GRACE ) {
			self::discard( $job );
			return new WP_Error( 'bzpso_job_not_started', __( 'The background task could not start on this server. Make sure WP-Cron is working (WooCommerce → Status → Scheduled Actions), or ask your host to allow loopback requests.', 'post-seo-optimizer' ) );
		}
		if ( 'running' === $job['status'] && $now - $job['started'] > $timeout + 180 ) {
			self::discard( $job );
			return new WP_Error( 'bzpso_job_stalled', __( 'The background task stopped without an answer, probably because the server ended it. Try again, or ask your host about PHP time limits for background requests.', 'post-seo-optimizer' ) );
		}

		if ( 'error' === $job['status'] ) {
			self::discard( $job );
			return new WP_Error( 'bzpso_job_failed', $job['error'] );
		}

		if ( 'done' === $job['status'] ) {
			self::discard( $job );
			return array(
				'status'  => 'done',
				'elapsed' => $job['finished'] - $job['created'],
				'data'    => $job['result'],
			);
		}

		return array(
			'status'  => $job['status'],
			'elapsed' => $now - $job['created'],
		);
	}

	/**
	 * Load a job.
	 *
	 * @param string $id Job id.
	 * @return array|null
	 */
	private static function get( $id ) {
		$job = get_transient( self::PREFIX . sanitize_key( $id ) );
		return is_array( $job ) && isset( $job['id'], $job['user'], $job['product'], $job['status'] ) ? $job : null;
	}

	/**
	 * Store a job. Kept for the timeout plus one hour, then it expires on its own.
	 *
	 * @param array $job Job.
	 */
	private static function save( array $job ) {
		set_transient( self::PREFIX . $job['id'], $job, (int) BZPSO_Settings::get( 'timeout' ) + HOUR_IN_SECONDS );
	}

	/**
	 * Remove a job and release its owner's lock.
	 *
	 * @param array $job Job.
	 */
	private static function discard( array $job ) {
		delete_transient( self::PREFIX . $job['id'] );
		delete_option( self::CLAIM_PREFIX . $job['id'] );
		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( self::HOOK, array( $job['id'] ), self::GROUP );
		}
		BZPSO_Rate_Limiter::unlock( $job['user'] );
	}

	/**
	 * Atomically claim a job so it runs only once.
	 *
	 * @param string $id Job id.
	 * @return bool True if this caller claimed it.
	 */
	private static function claim( $id ) {
		global $wpdb;
		// INSERT IGNORE is atomic: only one concurrent caller gets a row inserted.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::CLAIM_PREFIX . $id,
				(string) time()
			)
		);
		return 1 === (int) $inserted;
	}
}
