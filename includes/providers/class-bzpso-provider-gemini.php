<?php
/**
 * Google Gemini provider.
 *
 * @package PostSeoOptimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gemini generateContent API with JSON output mode. The key is sent as a header,
 * not in the URL, so it does not end up in proxy or server logs.
 */
class BZPSO_Provider_Gemini extends BZPSO_Provider {

	/**
	 * Thinking level for Gemini 3 and newer: low, medium or high.
	 *
	 * @var string
	 */
	private $thinking_level = 'low';

	/**
	 * Set the thinking level (from the settings).
	 *
	 * @param string $level low|medium|high.
	 */
	public function set_thinking_level( $level ) {
		if ( in_array( $level, array( 'low', 'medium', 'high' ), true ) ) {
			$this->thinking_level = $level;
		}
	}

	/**
	 * Human-readable provider name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Google Gemini';
	}

	/**
	 * Send the prompt to Gemini and return its text output.
	 *
	 * @param string $system System prompt.
	 * @param string $user   User message.
	 * @return string|WP_Error
	 */
	public function complete( $system, $user ) {
		$generation_config = array(
			'responseMimeType' => 'application/json',
			'maxOutputTokens'  => $this->max_tokens,
		);

		$thinking = $this->thinking_config();
		if ( $thinking ) {
			$generation_config['thinkingConfig'] = $thinking;
		}

		$data = $this->request( $system, $user, $generation_config );

		// If the model rejects the thinking setting (Google changes these per model
		// generation), retry once with the model's default thinking behaviour. When the
		// error names the thinking setting and the retry works, remember it so later
		// requests skip the failing first attempt.
		if ( $thinking && is_wp_error( $data ) && 400 === $this->error_status( $data ) ) {
			$about_thinking = false !== stripos( $data->get_error_message(), 'thinking' );
			unset( $generation_config['thinkingConfig'] );
			$this->log( 'Retrying without thinkingConfig for model ' . $this->model );
			$data = $this->request( $system, $user, $generation_config );
			if ( $about_thinking && ! is_wp_error( $data ) ) {
				set_transient( $this->no_thinking_key(), 1, WEEK_IN_SECONDS );
			}
		}

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// candidatesTokenCount excludes thinking; both are billed as output.
		$meta     = isset( $data['usageMetadata'] ) && is_array( $data['usageMetadata'] ) ? $data['usageMetadata'] : array();
		$thinking = isset( $meta['thoughtsTokenCount'] ) ? $meta['thoughtsTokenCount'] : 0;
		$this->set_usage(
			isset( $meta['promptTokenCount'] ) ? $meta['promptTokenCount'] : 0,
			( isset( $meta['candidatesTokenCount'] ) ? (int) $meta['candidatesTokenCount'] : 0 ) + (int) $thinking,
			$thinking,
			isset( $meta['cachedContentTokenCount'] ) ? $meta['cachedContentTokenCount'] : 0
		);

		if ( ! empty( $data['promptFeedback']['blockReason'] ) ) {
			return new WP_Error( 'bzpso_refused', __( 'The AI declined to rewrite this product (blocked by safety filters).', 'post-seo-optimizer' ) );
		}

		$candidate = isset( $data['candidates'][0] ) && is_array( $data['candidates'][0] ) ? $data['candidates'][0] : array();
		$finish    = isset( $candidate['finishReason'] ) ? $candidate['finishReason'] : '';
		if ( 'MAX_TOKENS' === $finish ) {
			return $this->truncated_error();
		}
		if ( in_array( $finish, array( 'SAFETY', 'RECITATION', 'PROHIBITED_CONTENT', 'BLOCKLIST' ), true ) ) {
			return new WP_Error( 'bzpso_refused', __( 'The AI declined to rewrite this product (blocked by safety filters).', 'post-seo-optimizer' ) );
		}

		$text = '';
		if ( isset( $candidate['content']['parts'] ) && is_array( $candidate['content']['parts'] ) ) {
			foreach ( $candidate['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) && is_string( $part['text'] ) && empty( $part['thought'] ) ) {
					$text .= $part['text'];
				}
			}
		}

		if ( '' === trim( $text ) ) {
			return new WP_Error( 'bzpso_empty', __( 'The AI returned an empty response. Please try again.', 'post-seo-optimizer' ) );
		}
		return $text;
	}

	/**
	 * Thinking setting for the request. More thinking is more careful but slower and
	 * uses more tokens; the owner picks the level in the settings.
	 *
	 * - Gemini 2.5 Flash: thinkingBudget 0 (thinking off).
	 * - Other Gemini 1.x/2.x models: nothing (2.0 does not think, 2.5 Pro cannot turn it off).
	 * - Everything newer – Gemini 3.x, future versions and aliases such as
	 *   "gemini-flash-latest": thinkingLevel low/medium/high ("minimal" is rejected by
	 *   some models, so it is not offered). If a model rejects the setting, complete()
	 *   retries without it and remembers that here.
	 *
	 * @return array Empty when no thinking setting should be sent.
	 */
	private function thinking_config() {
		if ( 0 === strpos( $this->model, 'gemini-2.5-flash' ) ) {
			return array( 'thinkingBudget' => 0 );
		}
		if ( preg_match( '/^gemini-[12]\./', $this->model ) || get_transient( $this->no_thinking_key() ) ) {
			return array();
		}
		return array( 'thinkingLevel' => $this->thinking_level );
	}

	/**
	 * Transient that marks a model as not accepting the thinking setting.
	 *
	 * @return string
	 */
	private function no_thinking_key() {
		return 'bzpso_gemini_nothink_' . md5( $this->model );
	}

	/**
	 * Call generateContent.
	 *
	 * @param string $system            System prompt.
	 * @param string $user              User message.
	 * @param array  $generation_config Generation config.
	 * @return array|WP_Error
	 */
	private function request( $system, $user, array $generation_config ) {
		return $this->post_json(
			'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $this->model ) . ':generateContent',
			array( 'x-goog-api-key' => $this->api_key ),
			array(
				'systemInstruction' => array(
					'parts' => array( array( 'text' => $system ) ),
				),
				'contents'          => array(
					array(
						'role'  => 'user',
						'parts' => array( array( 'text' => $user ) ),
					),
				),
				'generationConfig'  => $generation_config,
			)
		);
	}
}
