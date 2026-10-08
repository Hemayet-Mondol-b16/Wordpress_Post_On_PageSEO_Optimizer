<?php
/**
 * Yoast SEO integration.
 *
 * @package PostSeoOptimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes Yoast SEO's per-post fields. Yoast's indexable watchers pick up
 * post meta changes, so its indexables and front-end output stay in sync without
 * calling any internal Yoast API.
 */
class BZPSO_SEO_Meta {

	/**
	 * Plugin field => Yoast post meta key.
	 *
	 * @var array
	 */
	const KEYS = array(
		'focus_keyphrase'  => '_yoast_wpseo_focuskw',
		'seo_title'        => '_yoast_wpseo_title',
		'meta_description' => '_yoast_wpseo_metadesc',
	);

	/**
	 * Whether Yoast SEO is active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'WPSEO_VERSION' );
	}

	/**
	 * Get a field.
	 *
	 * @param int    $post_id Post id.
	 * @param string $field   Field key.
	 * @return string
	 */
	public static function get( $post_id, $field ) {
		if ( ! isset( self::KEYS[ $field ] ) ) {
			return '';
		}
		return (string) get_post_meta( $post_id, self::KEYS[ $field ], true );
	}

	/**
	 * Set a field. An empty value deletes it, so Yoast falls back to its template.
	 *
	 * @param int    $post_id Post id.
	 * @param string $field   Field key.
	 * @param string $value   Sanitized value.
	 */
	public static function set( $post_id, $field, $value ) {
		if ( ! isset( self::KEYS[ $field ] ) ) {
			return;
		}
		if ( '' === $value ) {
			delete_post_meta( $post_id, self::KEYS[ $field ] );
		} else {
			update_post_meta( $post_id, self::KEYS[ $field ], wp_slash( $value ) );
		}
	}
}
