<?php
/**
 * Anthropic (Claude) provider.
 *
 * @package PostSeoOptimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Anthropic Messages API.
 */
class BZPSO_Provider_Anthropic extends BZPSO_Provider {

	/**
	 * Human-readable provider name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Anthropic Claude';
	}

	/**
	 * Send the prompt to Claude and return its text output.
	 *
	 * @param string $system System prompt.
	 * @param string $user   User message.
	 * @return string|WP_Error
	 */
	public function complete( $system, $user ) {
		$data = $this->post_json(
			'https://api.anthropic.com/v1/messages',
			array(
				'x-api-key'         => $this->api_key,
				'anthropic-version' => '2023-06-01',
			),
			array(
				'model'      => $this->model,
				'max_tokens' => $this->max_tokens,
				'system'     => $system,
				'messages'   => array(
					array(
						'role'    => 'user',
						'content' => $user,
					),
				),
			)
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$usage = isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array();
		$this->set_usage(
			isset( $usage['input_tokens'] ) ? $usage['input_tokens'] : 0,
			isset( $usage['output_tokens'] ) ? $usage['output_tokens'] : 0,
			0,
			isset( $usage['cache_read_input_tokens'] ) ? $usage['cache_read_input_tokens'] : 0
		);

		$stop_reason = isset( $data['stop_reason'] ) ? $data['stop_reason'] : '';
		if ( 'max_tokens' === $stop_reason ) {
			return $this->truncated_error();
		}
		if ( 'refusal' === $stop_reason ) {
			return new WP_Error( 'bzpso_refused', __( 'The AI declined to rewrite this product.', 'post-seo-optimizer' ) );
		}

		$text = '';
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] && is_string( $block['text'] ) ) {
					$text .= $block['text'];
				}
			}
		}

		if ( '' === trim( $text ) ) {
			return new WP_Error( 'bzpso_empty', __( 'The AI returned an empty response. Please try again.', 'post-seo-optimizer' ) );
		}
		return $text;
	}
}
