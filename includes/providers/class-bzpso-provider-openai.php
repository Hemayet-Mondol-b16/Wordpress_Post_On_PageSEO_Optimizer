<?php
/**
 * OpenAI (ChatGPT) provider.
 *
 * @package PostSeoOptimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * OpenAI Chat Completions API with JSON output mode.
 */
class BZPSO_Provider_OpenAI extends BZPSO_Provider {

	/**
	 * Human-readable provider name.
	 *
	 * @return string
	 */
	public function label() {
		return 'OpenAI';
	}

	/**
	 * Send the prompt to OpenAI and return its text output.
	 *
	 * @param string $system System prompt.
	 * @param string $user   User message.
	 * @return string|WP_Error
	 */
	public function complete( $system, $user ) {
		$data = $this->post_json(
			'https://api.openai.com/v1/chat/completions',
			array( 'Authorization' => 'Bearer ' . $this->api_key ),
			array(
				'model'                 => $this->model,
				'messages'              => array(
					array(
						'role'    => 'system',
						'content' => $system,
					),
					array(
						'role'    => 'user',
						'content' => $user,
					),
				),
				'response_format'       => array( 'type' => 'json_object' ),
				'max_completion_tokens' => $this->max_tokens,
			)
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// completion_tokens already includes reasoning tokens.
		$usage = isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array();
		$this->set_usage(
			isset( $usage['prompt_tokens'] ) ? $usage['prompt_tokens'] : 0,
			isset( $usage['completion_tokens'] ) ? $usage['completion_tokens'] : 0,
			isset( $usage['completion_tokens_details']['reasoning_tokens'] ) ? $usage['completion_tokens_details']['reasoning_tokens'] : 0,
			isset( $usage['prompt_tokens_details']['cached_tokens'] ) ? $usage['prompt_tokens_details']['cached_tokens'] : 0
		);

		$choice = isset( $data['choices'][0] ) && is_array( $data['choices'][0] ) ? $data['choices'][0] : array();
		if ( isset( $choice['finish_reason'] ) && 'length' === $choice['finish_reason'] ) {
			return $this->truncated_error();
		}
		if ( ! empty( $choice['message']['refusal'] ) ) {
			return new WP_Error( 'bzpso_refused', __( 'The AI declined to rewrite this product.', 'post-seo-optimizer' ) );
		}

		$text = isset( $choice['message']['content'] ) && is_string( $choice['message']['content'] ) ? $choice['message']['content'] : '';
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'bzpso_empty', __( 'The AI returned an empty response. Please try again.', 'post-seo-optimizer' ) );
		}
		return $text;
	}
}
