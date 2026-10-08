<?php
/**
 * AJAX endpoints.
 *
 * @package PostSeoOptimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Logged-in AJAX endpoints only (no wp_ajax_nopriv_*). Every product endpoint checks
 * a per-product nonce and the edit_post capability for that product.
 */
class BZPSO_Ajax {

	/**
	 * Register endpoints.
	 */
	public function __construct() {
		add_action( 'wp_ajax_bzpso_generate', array( $this, 'generate' ) );
		add_action( 'wp_ajax_bzpso_job_status', array( $this, 'job_status' ) );
		add_action( 'wp_ajax_bzpso_apply', array( $this, 'apply' ) );
		add_action( 'wp_ajax_bzpso_restore', array( $this, 'restore' ) );
		add_action( 'wp_ajax_bzpso_test_connection', array( $this, 'test_connection' ) );
	}

	/**
	 * Start generating suggestions in the background. Returns a job id at once; the
	 * browser then polls job_status(). Nothing is saved to the product.
	 */
	public function generate() {
		$product  = $this->product_from_request();
		$settings = BZPSO_Settings::get();
		$user_id  = get_current_user_id();

		// Nonce verified in product_from_request().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$language = isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : '';
		if ( ! array_key_exists( $language, BZPSO_Settings::languages() ) ) {
			$language = $settings['language'];
		}
		$keyword      = isset( $_POST['keyword'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['keyword'] ) ), 0, 100 ) : '';
		$instructions = isset( $_POST['instructions'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['instructions'] ) ), 0, 500 ) : '';

		// Fields to generate: only known keys, in canonical order. Fewer fields = fewer output tokens.
		$requested = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? array_filter( wp_unslash( $_POST['fields'] ), 'is_string' ) : $settings['default_fields']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated against the allow-list below.
		$fields    = array_values( array_intersect( array_keys( BZPSO_Product_Data::fields() ), $requested ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $fields ) {
			$this->error( __( 'Select at least one field to generate.', 'post-seo-optimizer' ) );
		}

		$source = BZPSO_Product_Data::source_data( $product );

		// WooCommerce names untitled products "Product"; that alone is nothing to optimize.
		$placeholders = array( 'Product', 'AUTO-DRAFT', __( 'Product', 'woocommerce' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		$has_title    = ! empty( $source['title'] ) && ! in_array( $source['title'], $placeholders, true );
		if ( ! $has_title && empty( $source['description'] ) && empty( $source['short_description'] ) ) {
			$this->error( __( 'This product has no title or description to optimize yet.', 'post-seo-optimizer' ) );
		}

		// Fail fast on configuration problems (missing key) before queueing anything.
		$provider = BZPSO_Provider::create();
		if ( is_wp_error( $provider ) ) {
			$this->error( $provider->get_error_message() );
		}

		// One job per user at a time; the lock outlives the longest possible job.
		if ( ! BZPSO_Rate_Limiter::lock( $user_id, (int) $settings['timeout'] + BZPSO_Jobs::START_GRACE + 300 ) ) {
			$this->error( __( 'Another AI request of yours is still running. Please wait for it to finish.', 'post-seo-optimizer' ), 429 );
		}
		$allowed = BZPSO_Rate_Limiter::hit( $user_id );
		if ( is_wp_error( $allowed ) ) {
			BZPSO_Rate_Limiter::unlock( $user_id );
			$this->error( $allowed->get_error_message(), 429 );
		}

		$job = BZPSO_Jobs::create(
			$product->get_id(),
			$user_id,
			array(
				'language'     => $language,
				'keyword'      => $keyword,
				'instructions' => $instructions,
				'fields'       => $fields,
			)
		);

		wp_send_json_success( array( 'job' => $job ) );
	}

	/**
	 * Report the progress of a background job; returns the suggestions when done.
	 */
	public function job_status() {
		$product = $this->product_from_request();
		$job     = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in product_from_request().

		$status = BZPSO_Jobs::status( $job, get_current_user_id(), $product->get_id() );
		if ( is_wp_error( $status ) ) {
			$this->error( $status->get_error_message() );
		}
		wp_send_json_success( $status );
	}

	/**
	 * Apply reviewed values.
	 */
	public function apply() {
		$product = $this->product_from_request();

		// Nonce verified in product_from_request(); values are sanitized per field in BZPSO_Sanitizer::for_save().
		$fields = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		try {
			$result = BZPSO_Product_Data::apply( $product, $fields );
		} catch ( Exception $e ) {
			$result = new WP_Error( 'bzpso_save', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Changes applied.', 'post-seo-optimizer' ),
				'warnings' => $result,
			)
		);
	}

	/**
	 * Restore the original content.
	 */
	public function restore() {
		$product = $this->product_from_request();

		try {
			$result = BZPSO_Product_Data::restore( $product );
		} catch ( Exception $e ) {
			$result = new WP_Error( 'bzpso_restore', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Original content restored.', 'post-seo-optimizer' ),
				'warnings' => $result,
			)
		);
	}

	/**
	 * Test the saved provider settings with a tiny request.
	 */
	public function test_connection() {
		if ( ! check_ajax_referer( 'bzpso_test_connection', 'nonce', false ) || ! current_user_can( BZPSO_Settings::CAPABILITY ) ) {
			$this->error( __( 'You are not allowed to do this. Reload the page and try again.', 'post-seo-optimizer' ), 403 );
		}

		// The browser waits on this request directly, so keep it well under common
		// web server and proxy limits regardless of the generation timeout.
		$provider = BZPSO_Provider::create( 45 );
		if ( is_wp_error( $provider ) ) {
			$this->error( $provider->get_error_message() );
		}

		$user_id = get_current_user_id();
		if ( ! BZPSO_Rate_Limiter::lock( $user_id ) ) {
			$this->error( __( 'A request is already running. Please wait for it to finish.', 'post-seo-optimizer' ), 429 );
		}
		try {
			$raw = BZPSO_Rate_Limiter::hit( $user_id );
			if ( ! is_wp_error( $raw ) ) {
				$raw = $provider->generate( 'You are a connectivity check. Respond with JSON only.', 'Return exactly this JSON object: {"ok": true}' );
			}
		} finally {
			BZPSO_Rate_Limiter::unlock( $user_id );
		}

		if ( is_wp_error( $raw ) ) {
			$this->error( $raw->get_error_message() );
		}

		if ( $provider->used_fallback() ) {
			/* translators: 1: provider name, 2: fallback model id, 3: main model id. */
			$message = sprintf( __( 'Connected to %1$s using the fallback model %2$s, because %3$s is busy right now. The connection and key are fine.', 'post-seo-optimizer' ), $provider->label(), $provider->model(), $provider->primary_model() );
		} else {
			/* translators: 1: provider name, 2: model id. */
			$message = sprintf( __( 'Connected to %1$s using model %2$s.', 'post-seo-optimizer' ), $provider->label(), $provider->model() );
		}

		wp_send_json_success( array( 'message' => $message ) );
	}

	/**
	 * Validate nonce, capability and product. Ends the request on failure.
	 *
	 * @return WC_Product
	 */
	private function product_from_request() {
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Used to build the nonce action below.

		if ( ! $product_id || ! check_ajax_referer( 'bzpso_product_' . $product_id, 'nonce', false ) ) {
			$this->error( __( 'Your session has expired. Reload the page and try again.', 'post-seo-optimizer' ), 403 );
		}
		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			$this->error( __( 'You are not allowed to edit this product.', 'post-seo-optimizer' ), 403 );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || $product->is_type( 'variation' ) ) {
			$this->error( __( 'Product not found.', 'post-seo-optimizer' ), 404 );
		}
		return $product;
	}

	/**
	 * Send a JSON error and exit.
	 *
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 */
	private function error( $message, $status = 400 ) {
		wp_send_json_error( array( 'message' => $message ), $status );
	}
}
