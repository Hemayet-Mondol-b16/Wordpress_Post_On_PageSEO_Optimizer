<?php
/**
 * Base AI provider.
 *
 * @package PostSeoOptimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared HTTP handling and error mapping for AI providers.
 */
abstract class BZPSO_Provider {

	/**
	 * API key.
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * Model id.
	 *
	 * @var string
	 */
	protected $model;

	/**
	 * Maximum output tokens.
	 *
	 * @var int
	 */
	protected $max_tokens;

	/**
	 * Total request time budget in seconds.
	 *
	 * @var int
	 */
	protected $timeout;

	/**
	 * Model chosen in the settings.
	 *
	 * @var string
	 */
	protected $primary_model;

	/**
	 * Model to use when the primary model is busy ('' for none).
	 *
	 * @var string
	 */
	protected $fallback_model;

	/**
	 * Unix time by which all attempts must have finished (0 = not started).
	 *
	 * @var int
	 */
	protected $deadline = 0;

	/**
	 * Token usage of the last successful request: input, output (incl. thinking),
	 * thinking, cached (input tokens billed at a discount).
	 *
	 * @var int[]
	 */
	protected $usage = array();

	/**
	 * HTTP statuses meaning "busy right now, try again or elsewhere".
	 * 529 is Anthropic's "overloaded".
	 *
	 * @var int[]
	 */
	const BUSY_STATUSES = array( 429, 500, 502, 503, 504, 529 );

	/**
	 * Constructor.
	 *
	 * @param string $api_key        API key.
	 * @param string $model          Model id.
	 * @param int    $max_tokens     Max output tokens.
	 * @param int    $timeout        Request time budget in seconds.
	 * @param string $fallback_model Model to use when the primary one is busy.
	 */
	public function __construct( $api_key, $model, $max_tokens, $timeout = 60, $fallback_model = '' ) {
		$this->api_key        = (string) $api_key;
		$this->model          = (string) $model;
		$this->primary_model  = $this->model;
		$this->fallback_model = $fallback_model !== $model ? (string) $fallback_model : '';
		$this->max_tokens     = (int) $max_tokens;
		$this->timeout        = (int) $timeout;
	}

	/**
	 * Send a system + user prompt to the current model and return its text output.
	 *
	 * @param string $system System prompt.
	 * @param string $user   User message.
	 * @return string|WP_Error
	 */
	abstract public function complete( $system, $user );

	/**
	 * Generate with automatic recovery from "busy" errors, within the time budget:
	 * retry the chosen model once after a short pause, then try the fallback model.
	 *
	 * @param string $system System prompt.
	 * @param string $user   User message.
	 * @return string|WP_Error
	 */
	public function generate( $system, $user ) {
		$this->deadline = time() + max( 10, $this->timeout - 5 );
		$this->model    = $this->primary_model;
		$this->usage    = array();

		$result = $this->complete( $system, $user );

		if ( $this->is_busy( $result ) && $this->seconds_left() >= 20 ) {
			$this->log( 'Busy, retrying ' . $this->model );
			sleep( 2 );
			$result = $this->complete( $system, $user );
		}

		if ( $this->is_busy( $result ) && '' !== $this->fallback_model && $this->seconds_left() >= 15 ) {
			$this->log( sprintf( 'Busy, switching from %s to fallback %s', $this->model, $this->fallback_model ) );
			$this->model = $this->fallback_model;
			$result      = $this->complete( $system, $user );
			if ( $this->is_busy( $result ) ) {
				$result = new WP_Error(
					'bzpso_busy',
					sprintf(
						/* translators: 1: provider name, 2: model, 3: fallback model. */
						__( '%1$s is very busy right now: both %2$s and the fallback model %3$s are overloaded. Please try again in a few minutes.', 'post-seo-optimizer' ),
						$this->label(),
						$this->primary_model,
						$this->fallback_model
					),
					$result->get_error_data()
				);
			}
		}

		return $result;
	}

	/**
	 * Token usage of the last successful request.
	 *
	 * @return int[] input, output, thinking, cached (empty if unknown).
	 */
	public function usage() {
		return $this->usage;
	}

	/**
	 * Record token usage reported by the API.
	 *
	 * @param mixed $input    Input (prompt) tokens.
	 * @param mixed $output   Output tokens including thinking.
	 * @param mixed $thinking Thinking/reasoning tokens.
	 * @param mixed $cached   Input tokens served from the provider's cache.
	 */
	protected function set_usage( $input, $output, $thinking = 0, $cached = 0 ) {
		$this->usage = array(
			'input'    => (int) $input,
			'output'   => (int) $output,
			'thinking' => (int) $thinking,
			'cached'   => (int) $cached,
		);
	}

	/**
	 * Whether the last generate() call had to use the fallback model.
	 *
	 * @return bool
	 */
	public function used_fallback() {
		return $this->model !== $this->primary_model;
	}

	/**
	 * Model chosen in the settings.
	 *
	 * @return string
	 */
	public function primary_model() {
		return $this->primary_model;
	}

	/**
	 * Whether a result is a "busy / overloaded / rate limited" error.
	 *
	 * @param mixed $result Result of complete().
	 * @return bool
	 */
	protected function is_busy( $result ) {
		return is_wp_error( $result ) && in_array( $this->error_status( $result ), self::BUSY_STATUSES, true );
	}

	/**
	 * Seconds left in the time budget.
	 *
	 * @return int
	 */
	protected function seconds_left() {
		return $this->deadline ? $this->deadline - time() : max( 10, $this->timeout - 5 );
	}

	/**
	 * Human-readable provider name.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Model id.
	 *
	 * @return string
	 */
	public function model() {
		return $this->model;
	}

	/**
	 * Build the provider configured in settings.
	 *
	 * @param int $max_timeout Optional cap on the time budget (for requests the browser
	 *                         waits on directly, such as the connection test).
	 * @return BZPSO_Provider|WP_Error
	 */
	public static function create( $max_timeout = 0 ) {
		$settings  = BZPSO_Settings::get();
		$providers = BZPSO_Settings::providers();
		$id        = $settings['provider'];

		if ( ! isset( $providers[ $id ] ) ) {
			return new WP_Error( 'bzpso_provider', __( 'No valid AI provider is selected in the settings.', 'post-seo-optimizer' ) );
		}

		$key = BZPSO_Settings::get_api_key( $id );
		if ( '' === $key ) {
			return new WP_Error(
				'bzpso_no_key',
				/* translators: %s: provider name. */
				sprintf( __( 'No API key is configured for %s. Add one under WooCommerce → SEO Optimizer.', 'post-seo-optimizer' ), $providers[ $id ]['label'] )
			);
		}

		$timeout = (int) $settings['timeout'];
		if ( $max_timeout > 0 ) {
			$timeout = min( $timeout, (int) $max_timeout );
		}

		$class    = $providers[ $id ]['class'];
		$provider = new $class( $key, $settings['models'][ $id ], (int) $settings['max_tokens'], $timeout, $settings['fallback_models'][ $id ] );
		if ( method_exists( $provider, 'set_thinking_level' ) ) {
			$provider->set_thinking_level( $settings['thinking'] );
		}
		return $provider;
	}

	/**
	 * POST JSON and decode the JSON response.
	 *
	 * @param string $url     Endpoint (HTTPS).
	 * @param array  $headers Extra headers (auth).
	 * @param array  $body    Request body.
	 * @return array|WP_Error
	 */
	protected function post_json( $url, array $headers, array $body ) {
		$response = wp_safe_remote_post(
			$url,
			array(
				// Stay inside the remaining budget so we can still send a clear error.
				'timeout'     => max( 5, $this->seconds_left() ),
				'redirection' => 0,
				'sslverify'   => true,
				'user-agent'  => 'PostSEOOptimizer/' . BZPSO_VERSION,
				'headers'     => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
				'body'        => wp_json_encode( $body ),
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'HTTP error: ' . $response->get_error_message() );
			if ( false !== stripos( $response->get_error_message(), 'timed out' ) || false !== strpos( $response->get_error_message(), 'cURL error 28' ) ) {
				return new WP_Error(
					'bzpso_timeout',
					/* translators: 1: provider name, 2: seconds. */
					sprintf( __( '%1$s did not answer within %2$d seconds. Try again, use a faster model, or shorten the product text.', 'post-seo-optimizer' ), $this->label(), max( 10, $this->timeout - 5 ) )
				);
			}
			return new WP_Error(
				'bzpso_http',
				/* translators: 1: provider name, 2: error message. */
				sprintf( __( 'Could not connect to %1$s: %2$s', 'post-seo-optimizer' ), $this->label(), $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return $this->http_error( $code, is_array( $data ) ? $data : array() );
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'bzpso_bad_response', __( 'The AI provider returned an unreadable response. Please try again.', 'post-seo-optimizer' ) );
		}
		return $data;
	}

	/**
	 * Map an HTTP error to a helpful message. The API key is never included.
	 *
	 * @param int   $code HTTP status.
	 * @param array $data Decoded body.
	 * @return WP_Error
	 */
	protected function http_error( $code, array $data ) {
		$detail = '';
		if ( isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
			$detail = mb_substr( sanitize_text_field( $data['error']['message'] ), 0, 300 );
		}
		$this->log( sprintf( 'API error %d: %s', $code, $detail ) );

		if ( 401 === $code || 403 === $code ) {
			/* translators: %s: provider name. */
			$message = sprintf( __( '%s rejected the API key. Check the key in the plugin settings.', 'post-seo-optimizer' ), $this->label() );
		} elseif ( 404 === $code ) {
			/* translators: %s: model id. */
			$message = sprintf( __( 'The model "%s" was not found. Check the model name in the plugin settings.', 'post-seo-optimizer' ), $this->model );
		} elseif ( 429 === $code ) {
			/* translators: %s: provider name. */
			$message = sprintf( __( '%s rate limit or quota exceeded. Wait a moment or check your billing.', 'post-seo-optimizer' ), $this->label() );
		} elseif ( $code >= 500 ) {
			/* translators: %s: provider name. */
			$message = sprintf( __( '%s is temporarily unavailable. Please try again shortly.', 'post-seo-optimizer' ), $this->label() );
		} else {
			/* translators: 1: provider name, 2: HTTP status code. */
			$message = sprintf( __( '%1$s returned an error (HTTP %2$d).', 'post-seo-optimizer' ), $this->label(), $code );
		}

		if ( '' !== $detail && 401 !== $code && 403 !== $code ) {
			$message .= ' ' . $detail;
		}
		return new WP_Error( 'bzpso_api_error', $message, array( 'status' => (int) $code ) );
	}

	/**
	 * HTTP status of an API error, or 0 for other errors (e.g. timeouts).
	 *
	 * @param WP_Error $error Error.
	 * @return int
	 */
	protected function error_status( WP_Error $error ) {
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
	}

	/**
	 * Error for a response that hit the output token limit.
	 *
	 * @return WP_Error
	 */
	protected function truncated_error() {
		return new WP_Error( 'bzpso_truncated', __( 'The AI response was cut off. Increase "Max output tokens" in the plugin settings and try again.', 'post-seo-optimizer' ) );
	}

	/**
	 * Log to WooCommerce → Status → Logs (source: post-seo-optimizer).
	 *
	 * @param string $message Message without secrets.
	 */
	protected function log( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $this->label() . ' – ' . $message, array( 'source' => 'post-seo-optimizer' ) );
		}
	}
}
