<?php
/**
 * Product edit screen meta box.
 *
 * @package PostSeoOptimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * "AI SEO Optimizer" box on the product edit screen: generate → review/edit → apply.
 * Assets are only loaded on this screen.
 */
class BZPSO_Metabox {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes_product', array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the meta box.
	 *
	 * @param WP_Post $post Post.
	 */
	public function register( $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		add_meta_box( 'bzpso-metabox', __( 'AI SEO Optimizer', 'post-seo-optimizer' ), array( $this, 'render' ), 'product', 'normal', 'high' );
	}

	/**
	 * Enqueue assets on the product edit screen only.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function enqueue( $hook ) {
		if ( 'post.php' !== $hook ) {
			return;
		}
		$screen = get_current_screen();
		$post   = get_post();
		if ( ! $screen || 'product' !== $screen->post_type || ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$fields = array();
		$limits = array(
			'title'            => array( 40, 70 ),
			'seo_title'        => array( 0, 60 ),
			'meta_description' => array( 120, 156 ),
			'image_alt'        => array( 0, 125 ),
		);
		foreach ( BZPSO_Product_Data::fields() as $key => $label ) {
			$type = 'text';
			if ( in_array( $key, array( 'description', 'short_description' ), true ) ) {
				$type = 'html';
			} elseif ( 'meta_description' === $key ) {
				$type = 'textarea';
			}
			$fields[] = array(
				'key'   => $key,
				'label' => $label,
				'type'  => $type,
				'min'   => isset( $limits[ $key ] ) ? $limits[ $key ][0] : 0,
				'max'   => isset( $limits[ $key ] ) ? $limits[ $key ][1] : 0,
				'yoast' => isset( BZPSO_SEO_Meta::KEYS[ $key ] ),
			);
		}

		wp_enqueue_style( 'bzpso-admin', BZPSO_URL . 'assets/css/admin.css', array(), BZPSO_VERSION );
		wp_enqueue_script( 'bzpso-admin', BZPSO_URL . 'assets/js/admin.js', array(), BZPSO_VERSION, true );
		wp_add_inline_script(
			'bzpso-admin',
			'window.bzpsoConfig = ' . wp_json_encode(
				array(
					'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
					'nonce'       => wp_create_nonce( 'bzpso_product_' . $post->ID ),
					'productId'   => $post->ID,
					'siteName'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					'yoastActive' => BZPSO_SEO_Meta::is_active(),
					'fields'      => $fields,
					'i18n'        => array(
						'generating'     => __( 'Starting…', 'post-seo-optimizer' ),
						/* translators: %s: elapsed time, e.g. "1 min 20 s". */
						'working'        => __( 'Generating suggestions in the background… %s. Careful models can take several minutes; please keep this page open.', 'post-seo-optimizer' ),
						'resuming'       => __( 'Checking on the suggestions that were being generated…', 'post-seo-optimizer' ),
						/* translators: %d: seconds. */
						'sec'            => __( '%d s', 'post-seo-optimizer' ),
						/* translators: 1: minutes, 2: seconds. */
						'minSec'         => __( '%1$d min %2$d s', 'post-seo-optimizer' ),
						'fallbackUsed'   => __( 'fallback model, the main model was busy', 'post-seo-optimizer' ),
						'selectGenerate' => __( 'Tick at least one field to generate.', 'post-seo-optimizer' ),
						/* translators: 1: input tokens, 2: output tokens. */
						'tokens'         => __( 'Tokens: %1$s in, %2$s out', 'post-seo-optimizer' ),
						/* translators: %s: thinking tokens. */
						'tokensThinking' => __( '(incl. %s thinking)', 'post-seo-optimizer' ),
						/* translators: %s: cached input tokens. */
						'tokensCached'   => __( '%s input tokens cached (discounted)', 'post-seo-optimizer' ),
						'generated'      => __( 'Review the suggestions below. Edit anything you like, tick the fields to apply, then click "Apply selected".', 'post-seo-optimizer' ),
						'applying'       => __( 'Applying changes…', 'post-seo-optimizer' ),
						'restoring'      => __( 'Restoring original content…', 'post-seo-optimizer' ),
						'reloading'      => __( 'Done. Reloading the page…', 'post-seo-optimizer' ),
						'reloadNow'      => __( 'Reload page', 'post-seo-optimizer' ),
						'selectField'    => __( 'Tick at least one field to apply.', 'post-seo-optimizer' ),
						'confirmApply'   => __( 'Apply the selected changes to this product? The page will reload afterwards.', 'post-seo-optimizer' ),
						'confirmRestore' => __( 'Restore the original (pre-optimization) content for this product? The page will reload afterwards.', 'post-seo-optimizer' ),
						'unsavedWarning' => __( 'You have unsaved changes in the product editor. They will be lost when the page reloads.', 'post-seo-optimizer' ),
						'networkError'   => __( 'Network error. Check your connection and try again.', 'post-seo-optimizer' ),
						'serverError'    => __( 'The server did not respond in time. Try again, or ask your host to raise the PHP time limit.', 'post-seo-optimizer' ),
						'unknownError'   => __( 'Something went wrong. Reload the page and try again.', 'post-seo-optimizer' ),
						'colApply'       => __( 'Apply', 'post-seo-optimizer' ),
						'colField'       => __( 'Field', 'post-seo-optimizer' ),
						'colCurrent'     => __( 'Current', 'post-seo-optimizer' ),
						'colSuggested'   => __( 'Suggested (editable)', 'post-seo-optimizer' ),
						'applySelected'  => __( 'Apply selected', 'post-seo-optimizer' ),
						'discard'        => __( 'Discard', 'post-seo-optimizer' ),
						'showPreview'    => __( 'Show preview', 'post-seo-optimizer' ),
						'hidePreview'    => __( 'Hide preview', 'post-seo-optimizer' ),
						'empty'          => __( '(empty)', 'post-seo-optimizer' ),
						/* translators: %s: field name. */
						'applyField'     => __( 'Apply %s', 'post-seo-optimizer' ),
						/* translators: %d: number of characters. */
						'characters'     => __( '%d characters', 'post-seo-optimizer' ),
						/* translators: 1: minimum, 2: maximum number of characters. */
						'recommendRange' => __( 'recommended %1$d–%2$d', 'post-seo-optimizer' ),
						/* translators: %d: maximum number of characters. */
						'recommendMax'   => __( 'recommended max %d', 'post-seo-optimizer' ),
						'yoastInactive'  => __( 'Saved to Yoast fields (Yoast not active)', 'post-seo-optimizer' ),
						'slugNote'       => __( 'Changes the product URL. The old URL redirects automatically.', 'post-seo-optimizer' ),
						'tagsNote'       => __( 'Comma separated. Added to existing tags.', 'post-seo-optimizer' ),
						'altNote'        => __( 'Main image; gallery images get a numbered variant.', 'post-seo-optimizer' ),
						'generatedBy'    => __( 'Generated by', 'post-seo-optimizer' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Save the product as a draft first, then come back here to generate SEO suggestions.', 'post-seo-optimizer' ) . '</p>';
			return;
		}

		$settings = BZPSO_Settings::get();
		$has_key  = in_array( BZPSO_Settings::key_source( $settings['provider'] ), array( 'constant', 'database' ), true );
		?>
		<div id="bzpso-app" class="bzpso-app">
			<?php if ( ! $has_key ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'No API key is configured for the selected AI provider.', 'post-seo-optimizer' ); ?>
					<?php if ( current_user_can( BZPSO_Settings::CAPABILITY ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . BZPSO_Settings::PAGE ) ); ?>"><?php esc_html_e( 'Open settings', 'post-seo-optimizer' ); ?></a>
					<?php endif; ?>
				</p></div>
			<?php endif; ?>

			<?php if ( ! BZPSO_SEO_Meta::is_active() ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'Yoast SEO is not active. SEO title, meta description and focus keyphrase are still saved in Yoast\'s fields and used once Yoast is activated.', 'post-seo-optimizer' ); ?></p></div>
			<?php endif; ?>

			<div class="bzpso-controls">
				<p class="bzpso-control">
					<label for="bzpso-language"><?php esc_html_e( 'Language', 'post-seo-optimizer' ); ?></label>
					<select id="bzpso-language">
						<?php foreach ( BZPSO_Settings::languages() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['language'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="bzpso-control">
					<label for="bzpso-keyword"><?php esc_html_e( 'Focus keyphrase (optional)', 'post-seo-optimizer' ); ?></label>
					<input type="text" id="bzpso-keyword" maxlength="100" value="<?php echo esc_attr( BZPSO_SEO_Meta::get( $post->ID, 'focus_keyphrase' ) ); ?>" placeholder="<?php esc_attr_e( 'Leave empty to let AI choose', 'post-seo-optimizer' ); ?>">
				</p>
				<p class="bzpso-control bzpso-control-wide">
					<label for="bzpso-instructions"><?php esc_html_e( 'Extra instructions for this product (optional)', 'post-seo-optimizer' ); ?></label>
					<input type="text" id="bzpso-instructions" maxlength="500" placeholder="<?php esc_attr_e( 'e.g. Highlight that it is suitable for kids', 'post-seo-optimizer' ); ?>">
				</p>
				<fieldset class="bzpso-control bzpso-control-full bzpso-gen-fields">
					<legend><?php esc_html_e( 'Generate', 'post-seo-optimizer' ); ?></legend>
					<?php foreach ( BZPSO_Product_Data::fields() as $key => $label ) : ?>
						<label><input type="checkbox" class="bzpso-gen-field" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $settings['default_fields'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
					<span class="description"><?php esc_html_e( 'Only ticked fields are written by the AI: fewer fields, fewer tokens.', 'post-seo-optimizer' ); ?></span>
				</fieldset>
				<p class="bzpso-control bzpso-control-action">
					<button type="button" class="button button-primary" id="bzpso-generate" <?php disabled( ! $has_key ); ?>><?php esc_html_e( 'Generate SEO suggestions', 'post-seo-optimizer' ); ?></button>
					<span class="spinner"></span>
				</p>
			</div>

			<div id="bzpso-notice" class="bzpso-notice" role="status" aria-live="polite" hidden></div>
			<div id="bzpso-results" class="bzpso-results" hidden></div>

			<?php $this->render_status( $post->ID ); ?>
		</div>
		<?php
	}

	/**
	 * Optimization status and restore button.
	 *
	 * @param int $post_id Post id.
	 */
	private function render_status( $post_id ) {
		$optimized  = (int) get_post_meta( $post_id, BZPSO_Product_Data::META_OPTIMIZED, true );
		$has_backup = BZPSO_Product_Data::has_backup( $post_id );
		if ( ! $optimized && ! $has_backup ) {
			return;
		}
		?>
		<div class="bzpso-status">
			<?php if ( $optimized ) : ?>
				<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
				<?php
				$user = get_userdata( (int) get_post_meta( $post_id, BZPSO_Product_Data::META_USER, true ) );
				if ( $user ) {
					/* translators: 1: date and time, 2: user display name. */
					printf( esc_html__( 'Optimized on %1$s by %2$s.', 'post-seo-optimizer' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $optimized ) ), esc_html( $user->display_name ) );
				} else {
					/* translators: %s: date and time. */
					printf( esc_html__( 'Optimized on %s.', 'post-seo-optimizer' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $optimized ) ) );
				}
				?>
			<?php endif; ?>
			<?php if ( $has_backup ) : ?>
				<button type="button" class="button button-link-delete" id="bzpso-restore"><?php esc_html_e( 'Restore original content', 'post-seo-optimizer' ); ?></button>
			<?php endif; ?>
		</div>
		<?php
	}
}
