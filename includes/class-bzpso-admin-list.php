<?php
/**
 * Products list integration.
 *
 * @package PostSeoOptimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds an "AI SEO" status column and an "Optimize SEO" row action to Products.
 * The column reads post meta that WordPress already primes for the listed posts,
 * so it adds no extra queries.
 */
class BZPSO_Admin_List {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_filter( 'manage_edit-product_columns', array( $this, 'add_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Column styles on the products list only.
	 */
	public function enqueue() {
		$screen = get_current_screen();
		if ( $screen && 'edit-product' === $screen->id ) {
			wp_enqueue_style( 'bzpso-admin', BZPSO_URL . 'assets/css/admin.css', array(), BZPSO_VERSION );
		}
	}

	/**
	 * Insert the column after the product name.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'name' === $key ) {
				$out['bzpso_seo'] = __( 'AI SEO', 'post-seo-optimizer' );
			}
		}
		if ( ! isset( $out['bzpso_seo'] ) ) {
			$out['bzpso_seo'] = __( 'AI SEO', 'post-seo-optimizer' );
		}
		return $out;
	}

	/**
	 * Render the column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post id.
	 */
	public function render_column( $column, $post_id ) {
		if ( 'bzpso_seo' !== $column ) {
			return;
		}

		$optimized = (int) get_post_meta( $post_id, BZPSO_Product_Data::META_OPTIMIZED, true );
		if ( $optimized ) {
			printf(
				'<span class="bzpso-badge is-optimized" title="%1$s">%2$s</span>',
				/* translators: %s: date. */
				esc_attr( sprintf( __( 'Optimized on %s', 'post-seo-optimizer' ), wp_date( get_option( 'date_format' ), $optimized ) ) ),
				esc_html__( 'Optimized', 'post-seo-optimizer' )
			);
		} else {
			echo '<span class="bzpso-badge" aria-hidden="true">–</span><span class="screen-reader-text">' . esc_html__( 'Not optimized', 'post-seo-optimizer' ) . '</span>';
		}
	}

	/**
	 * "Optimize SEO" row action linking to the meta box.
	 *
	 * @param array   $actions Actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public function row_action( $actions, $post ) {
		if ( 'product' !== $post->post_type || 'trash' === $post->post_status || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		$link = get_edit_post_link( $post->ID, 'raw' );
		if ( $link ) {
			$actions['bzpso_optimize'] = sprintf( '<a href="%s">%s</a>', esc_url( $link . '#bzpso-metabox' ), esc_html__( 'Optimize SEO', 'post-seo-optimizer' ) );
		}
		return $actions;
	}
}
