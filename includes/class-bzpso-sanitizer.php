<?php
/**
 * Parsing and sanitizing of AI output and user-submitted values.
 *
 * @package PostSeoOptimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * AI output is untrusted: it is parsed strictly, stripped to an allow-list of HTML,
 * and length-limited before it reaches the browser. User-edited values are sanitized
 * again on save.
 */
class BZPSO_Sanitizer {

	/**
	 * HTML allowed in AI-generated descriptions (no attributes).
	 *
	 * @return array
	 */
	public static function allowed_html() {
		$tags = array( 'p', 'br', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'blockquote' );
		return array_fill_keys( $tags, array() );
	}

	/**
	 * Extract the JSON object from the model's text output.
	 *
	 * @param string $raw Raw text.
	 * @return array|WP_Error
	 */
	public static function parse_response( $raw ) {
		$raw   = trim( (string) $raw );
		$start = strpos( $raw, '{' );
		$end   = strrpos( $raw, '}' );

		$data = null;
		if ( false !== $start && false !== $end && $end > $start ) {
			$data = json_decode( substr( $raw, $start, $end - $start + 1 ), true, 16 );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'bzpso_parse', __( 'The AI response could not be read. Please try again.', 'post-seo-optimizer' ) );
		}
		return $data;
	}

	/**
	 * Turn parsed AI output into sanitized suggestions for the review screen.
	 *
	 * @param array    $data   Parsed AI output.
	 * @param string[] $fields Requested fields (empty = all). Others are dropped.
	 * @return array
	 */
	public static function suggestions( array $data, array $fields = array() ) {
		$get = static function ( $key ) use ( $data ) {
			return isset( $data[ $key ] ) ? $data[ $key ] : '';
		};

		$seo_title = self::line( $get( 'seo_title' ), 120 );
		if ( '' !== $seo_title && BZPSO_Settings::get( 'append_site_name' ) && false === strpos( $seo_title, '%%sitename%%' ) ) {
			$seo_title .= ' %%sep%% %%sitename%%';
		}

		$all = array(
			'focus_keyphrase'   => self::line( $get( 'focus_keyphrase' ), 100 ),
			'title'             => self::line( $get( 'title' ), 200 ),
			'seo_title'         => $seo_title,
			'meta_description'  => self::line( $get( 'meta_description' ), 320 ),
			'slug'              => sanitize_title( self::line( $get( 'slug' ), 200 ) ),
			'short_description' => self::html( $get( 'short_description' ), 5000 ),
			'description'       => self::html( $get( 'description' ), 30000 ),
			'image_alt'         => self::line( $get( 'image_alt' ), 200 ),
			'tags'              => implode( ', ', self::tag_list( $get( 'tags' ) ) ),
		);

		return $fields ? array_intersect_key( $all, array_flip( $fields ) ) : $all;
	}

	/**
	 * Sanitize values submitted from the review screen before saving.
	 * Unknown keys are dropped; empty titles and slugs are ignored.
	 *
	 * @param array $fields Raw (unslashed) field values.
	 * @return array
	 */
	public static function for_save( array $fields ) {
		$out = array();

		foreach ( $fields as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = (string) $value;

			switch ( $key ) {
				case 'title':
					$title = self::line( $value, 200 );
					if ( '' !== $title ) {
						$out['title'] = $title;
					}
					break;

				case 'slug':
					$slug = sanitize_title( $value );
					if ( '' !== $slug ) {
						$out['slug'] = $slug;
					}
					break;

				case 'description':
				case 'short_description':
					$out[ $key ] = trim( wp_kses_post( $value ) );
					break;

				case 'focus_keyphrase':
					$out[ $key ] = self::line( $value, 100 );
					break;

				case 'seo_title':
					$out[ $key ] = self::line( $value, 200 );
					break;

				case 'meta_description':
					$out[ $key ] = self::line( $value, 320 );
					break;

				case 'image_alt':
					$out[ $key ] = self::line( $value, 200 );
					break;

				case 'tags':
					$out['tags'] = self::tag_list( $value );
					break;
			}
		}

		return $out;
	}

	/**
	 * Single line of plain text. Unlike sanitize_text_field() this keeps sequences such
	 * as "%%category%%", which Yoast uses as template variables.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Max characters.
	 * @return string
	 */
	public static function line( $value, $max ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = wp_check_invalid_utf8( (string) $value, true );
		$value = wp_strip_all_tags( $value, true );
		$value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
		return mb_substr( $value, 0, $max );
	}

	/**
	 * Restricted HTML for AI-generated descriptions.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Max characters before filtering.
	 * @return string
	 */
	private static function html( $value, $max ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$html = mb_substr( wp_check_invalid_utf8( (string) $value, true ), 0, $max );
		$html = wp_kses( $html, self::allowed_html() );
		$html = trim( force_balance_tags( $html ) );

		// Plain text answer: give it paragraphs.
		if ( '' !== $html && false === strpos( $html, '<' ) ) {
			$html = trim( wpautop( $html ) );
		}
		return $html;
	}

	/**
	 * Normalize tags from an array or a comma-separated string.
	 *
	 * @param mixed $value Value.
	 * @return string[]
	 */
	private static function tag_list( $value ) {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$tags = array();
		foreach ( $value as $tag ) {
			$tag = self::line( $tag, 50 );
			if ( '' !== $tag ) {
				$tags[ mb_strtolower( $tag ) ] = $tag;
			}
		}
		return array_slice( array_values( $tags ), 0, 10 );
	}
}
