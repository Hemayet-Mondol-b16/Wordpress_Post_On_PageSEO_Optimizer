<?php
/**
 * Settings storage and settings page.
 *
 * @package PostSeoOptimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page under WooCommerce → SEO Optimizer.
 *
 * Settings are saved through admin-post.php with a nonce and capability check.
 * API keys live in a separate option, are encrypted at rest, and are never printed
 * back into the page. They can also be defined as constants in wp-config.php.
 */
class BZPSO_Settings {

	const OPTION      = 'bzpso_settings';
	const KEYS_OPTION = 'bzpso_api_keys';
	const PAGE        = 'bzpso-settings';
	const CAPABILITY  = 'manage_woocommerce';

	/**
	 * Request-level cache of the merged settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Settings page hook suffix.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_post_bzpso_save_settings', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Supported AI providers.
	 *
	 * @return array
	 */
	public static function providers() {
		return array(
			'openai'    => array(
				'label'    => 'OpenAI (ChatGPT)',
				'model'    => 'gpt-4.1-mini',
				'fallback' => '',
				'constant' => 'BZPSO_OPENAI_API_KEY',
				'class'    => 'BZPSO_Provider_OpenAI',
				'key_url'  => 'https://platform.openai.com/api-keys',
			),
			'anthropic' => array(
				'label'    => 'Anthropic (Claude)',
				'model'    => 'claude-sonnet-5-5',
				'fallback' => '',
				'constant' => 'BZPSO_ANTHROPIC_API_KEY',
				'class'    => 'BZPSO_Provider_Anthropic',
				'key_url'  => 'https://console.anthropic.com/settings/keys',
			),
			'gemini'    => array(
				'label'    => 'Google (Gemini)',
				'model'    => 'gemini-3.8-flash',
				'fallback' => 'gemini-3.7-flash',
				'constant' => 'BZPSO_GEMINI_API_KEY',
				'class'    => 'BZPSO_Provider_Gemini',
				'key_url'  => 'https://aistudio.google.com/apikey',
			),
		);
	}

	/**
	 * Output languages.
	 *
	 * @return array
	 */
	public static function languages() {
		return array(
			'auto' => __( 'Same as the product (auto-detect)', 'post-seo-optimizer' ),
			'en'   => __( 'English', 'post-seo-optimizer' ),
			'bn'   => __( 'Bangla (বাংলা)', 'post-seo-optimizer' ),
		);
	}

	/**
	 * Writing tones.
	 *
	 * @return array
	 */
	public static function tones() {
		return array(
			'professional' => __( 'Professional & trustworthy', 'post-seo-optimizer' ),
			'friendly'     => __( 'Friendly & conversational', 'post-seo-optimizer' ),
			'persuasive'   => __( 'Persuasive & benefit-focused', 'post-seo-optimizer' ),
			'premium'      => __( 'Premium & elegant', 'post-seo-optimizer' ),
			'simple'       => __( 'Simple & easy to read', 'post-seo-optimizer' ),
		);
	}

	/**
	 * Thinking levels for Gemini 3 and newer.
	 *
	 * @return array
	 */
	public static function thinking_levels() {
		return array(
			'low'    => __( 'Low – fastest', 'post-seo-optimizer' ),
			'medium' => __( 'Medium – balanced (recommended)', 'post-seo-optimizer' ),
			'high'   => __( 'High – most careful, slowest', 'post-seo-optimizer' ),
		);
	}

	/**
	 * Description length presets (word ranges live in BZPSO_Prompt::DESCRIPTION_WORDS).
	 *
	 * @return array
	 */
	public static function description_lengths() {
		return array(
			'short'    => __( 'Short – 150–250 words (lowest cost)', 'post-seo-optimizer' ),
			'standard' => __( 'Standard – 250–400 words (recommended)', 'post-seo-optimizer' ),
			'long'     => __( 'Long – 400–600 words', 'post-seo-optimizer' ),
		);
	}

	/**
	 * Default settings. Must not call translation functions (used on activation).
	 *
	 * @return array
	 */
	public static function defaults() {
		$models    = array();
		$fallbacks = array();
		foreach ( self::providers() as $id => $provider ) {
			$models[ $id ]    = $provider['model'];
			$fallbacks[ $id ] = $provider['fallback'];
		}

		return array(
			'provider'         => 'gemini',
			'models'           => $models,
			'fallback_models'  => $fallbacks,
			'language'         => 'auto',
			'tone'             => 'professional',
			'desc_length'      => 'standard',
			'store_name'       => '',
			'audience'         => '',
			'instructions'     => '',
			'append_site_name' => 1,
			'default_fields'   => array( 'focus_keyphrase', 'title', 'seo_title', 'meta_description', 'short_description', 'description', 'image_alt' ),
			'max_tokens'       => 8000,
			'timeout'          => 300,
			'thinking'         => 'medium',
			'hourly_limit'     => 30,
			'delete_data'      => 0,
		);
	}

	/**
	 * Get all settings, or one setting.
	 *
	 * @param string|null $key Setting key.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		if ( null === self::$cache ) {
			$defaults = self::defaults();
			$saved    = get_option( self::OPTION, array() );
			$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );

			$settings['models']          = wp_parse_args( is_array( $settings['models'] ) ? $settings['models'] : array(), $defaults['models'] );
			$settings['fallback_models'] = wp_parse_args( is_array( $settings['fallback_models'] ) ? $settings['fallback_models'] : array(), $defaults['fallback_models'] );
			$settings['default_fields']  = is_array( $settings['default_fields'] ) ? $settings['default_fields'] : array();

			self::$cache = $settings;
		}

		if ( null === $key ) {
			return self::$cache;
		}
		return isset( self::$cache[ $key ] ) ? self::$cache[ $key ] : null;
	}

	/**
	 * Where a provider's API key comes from: 'constant', 'database', 'undecryptable' or ''.
	 *
	 * @param string $provider Provider id.
	 * @return string
	 */
	public static function key_source( $provider ) {
		$providers = self::providers();
		if ( ! isset( $providers[ $provider ] ) ) {
			return '';
		}

		$constant = $providers[ $provider ]['constant'];
		if ( defined( $constant ) && is_string( constant( $constant ) ) && '' !== constant( $constant ) ) {
			return 'constant';
		}

		$keys = get_option( self::KEYS_OPTION, array() );
		if ( empty( $keys[ $provider ] ) ) {
			return '';
		}
		return '' === BZPSO_Crypto::decrypt( $keys[ $provider ] ) ? 'undecryptable' : 'database';
	}

	/**
	 * Get a provider's API key in plaintext. Server-side use only.
	 *
	 * @param string $provider Provider id.
	 * @return string
	 */
	public static function get_api_key( $provider ) {
		$providers = self::providers();
		if ( ! isset( $providers[ $provider ] ) ) {
			return '';
		}

		$constant = $providers[ $provider ]['constant'];
		if ( defined( $constant ) && is_string( constant( $constant ) ) && '' !== constant( $constant ) ) {
			return constant( $constant );
		}

		$keys = get_option( self::KEYS_OPTION, array() );
		return empty( $keys[ $provider ] ) ? '' : BZPSO_Crypto::decrypt( $keys[ $provider ] );
	}

	/**
	 * Add the submenu page.
	 */
	public function register_page() {
		$this->hook_suffix = (string) add_submenu_page(
			'woocommerce',
			__( 'Post SEO Optimizer', 'post-seo-optimizer' ),
			__( 'SEO Optimizer', 'post-seo-optimizer' ),
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Load assets on the settings page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ) {
		if ( '' === $this->hook_suffix || $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'bzpso-admin', BZPSO_URL . 'assets/css/admin.css', array(), BZPSO_VERSION );
		wp_enqueue_script( 'bzpso-settings', BZPSO_URL . 'assets/js/settings.js', array(), BZPSO_VERSION, true );
		wp_add_inline_script(
			'bzpso-settings',
			'window.bzpsoSettings = ' . wp_json_encode(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'bzpso_test_connection' ),
					'i18n'    => array(
						'testing' => __( 'Testing…', 'post-seo-optimizer' ),
						'failed'  => __( 'The connection test failed. Please try again.', 'post-seo-optimizer' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Handle the settings form submission.
	 */
	public function save() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage these settings.', 'post-seo-optimizer' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'bzpso_save_settings' );

		// Each field is validated individually below.
		$input     = isset( $_POST['pso'] ) && is_array( $_POST['pso'] ) ? wp_unslash( $_POST['pso'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$defaults  = self::defaults();
		$providers = self::providers();

		$clean = array();

		$provider          = self::text( $input, 'provider' );
		$clean['provider'] = isset( $providers[ $provider ] ) ? $provider : $defaults['provider'];

		$models    = isset( $input['models'] ) && is_array( $input['models'] ) ? $input['models'] : array();
		$fallbacks = isset( $input['fallback_models'] ) && is_array( $input['fallback_models'] ) ? $input['fallback_models'] : array();
		foreach ( $providers as $id => $config ) {
			$model                  = self::sanitize_model( self::text( $models, $id ) );
			$clean['models'][ $id ] = '' !== $model ? $model : $config['model'];
			// An empty fallback is allowed and means "no fallback".
			$clean['fallback_models'][ $id ] = self::sanitize_model( self::text( $fallbacks, $id ) );
		}

		$language          = self::text( $input, 'language' );
		$clean['language'] = array_key_exists( $language, self::languages() ) ? $language : $defaults['language'];

		$tone          = self::text( $input, 'tone' );
		$clean['tone'] = array_key_exists( $tone, self::tones() ) ? $tone : $defaults['tone'];

		$desc_length          = self::text( $input, 'desc_length' );
		$clean['desc_length'] = array_key_exists( $desc_length, self::description_lengths() ) ? $desc_length : $defaults['desc_length'];

		$clean['store_name']       = mb_substr( sanitize_text_field( self::text( $input, 'store_name' ) ), 0, 100 );
		$clean['audience']         = mb_substr( sanitize_text_field( self::text( $input, 'audience' ) ), 0, 200 );
		$clean['instructions']     = mb_substr( sanitize_textarea_field( self::text( $input, 'instructions' ) ), 0, 1000 );
		$clean['append_site_name'] = empty( $input['append_site_name'] ) ? 0 : 1;

		$fields                  = isset( $input['default_fields'] ) && is_array( $input['default_fields'] ) ? array_filter( $input['default_fields'], 'is_string' ) : array();
		$clean['default_fields'] = array_values( array_intersect( $fields, array_keys( BZPSO_Product_Data::fields() ) ) );

		$clean['max_tokens']   = min( 32000, max( 1000, absint( self::text( $input, 'max_tokens' ) ) ) );
		$clean['timeout']      = min( 600, max( 30, absint( self::text( $input, 'timeout' ) ) ) );
		$thinking              = self::text( $input, 'thinking' );
		$clean['thinking']     = array_key_exists( $thinking, self::thinking_levels() ) ? $thinking : $defaults['thinking'];
		$clean['hourly_limit'] = min( 1000, absint( self::text( $input, 'hourly_limit' ) ) );
		$clean['delete_data']  = empty( $input['delete_data'] ) ? 0 : 1;

		update_option( self::OPTION, $clean, false );

		$error = $this->save_api_keys( $providers );

		self::$cache = null;

		$args = array(
			'page'             => self::PAGE,
			'settings-updated' => 'true',
		);
		if ( '' !== $error ) {
			$args['bzpso_error'] = $error;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Save submitted API keys. An empty field keeps the stored key.
	 *
	 * @param array $providers Providers.
	 * @return string Error code, or ''.
	 */
	private function save_api_keys( array $providers ) {
		// Nonce verified in save(). Keys are validated against a strict pattern below.
		$submitted = isset( $_POST['bzpso_api_key'] ) && is_array( $_POST['bzpso_api_key'] ) ? wp_unslash( $_POST['bzpso_api_key'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$clear     = isset( $_POST['bzpso_clear_key'] ) && is_array( $_POST['bzpso_clear_key'] ) ? wp_unslash( $_POST['bzpso_clear_key'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$keys = get_option( self::KEYS_OPTION, array() );
		$keys = is_array( $keys ) ? $keys : array();

		$error = '';
		foreach ( array_keys( $providers ) as $id ) {
			if ( ! empty( $clear[ $id ] ) ) {
				unset( $keys[ $id ] );
				continue;
			}

			$key = trim( self::text( $submitted, $id ) );
			if ( '' === $key ) {
				continue;
			}
			if ( ! preg_match( '/^[A-Za-z0-9_\-.]{20,300}$/', $key ) ) {
				$error = 'invalid_key';
				continue;
			}

			$encrypted = BZPSO_Crypto::encrypt( $key );
			if ( '' === $encrypted ) {
				$error = 'encryption';
				continue;
			}
			$keys[ $id ] = $encrypted;
		}

		update_option( self::KEYS_OPTION, $keys, false );
		return $error;
	}

	/**
	 * Read a scalar value from an input array as a string.
	 *
	 * @param array  $input Input array.
	 * @param string $key   Key.
	 * @return string
	 */
	private static function text( array $input, $key ) {
		return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : '';
	}

	/**
	 * Model ids only contain letters, digits, dots, dashes and underscores.
	 *
	 * @param string $model Model id.
	 * @return string
	 */
	private static function sanitize_model( $model ) {
		$model = preg_replace( '#^models/#', '', trim( $model ) );
		return substr( (string) preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) $model ), 0, 100 );
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$settings  = self::get();
		$providers = self::providers();
		?>
		<div class="wrap bzpso-settings">
			<h1><?php esc_html_e( 'Post SEO Optimizer', 'post-seo-optimizer' ); ?></h1>
			<?php $this->render_notices(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
				<input type="hidden" name="action" value="bzpso_save_settings">
				<?php wp_nonce_field( 'bzpso_save_settings' ); ?>

				<h2><?php esc_html_e( 'AI provider', 'post-seo-optimizer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bzpso-provider"><?php esc_html_e( 'Active provider', 'post-seo-optimizer' ); ?></label></th>
						<td>
							<select id="bzpso-provider" name="pso[provider]">
								<?php foreach ( $providers as $id => $provider ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $settings['provider'], $id ); ?>><?php echo esc_html( $provider['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Only the active provider is used. You can store keys for several providers and switch at any time.', 'post-seo-optimizer' ); ?></p>
						</td>
					</tr>
					<?php foreach ( $providers as $id => $provider ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $provider['label'] ); ?></th>
							<td>
								<?php $this->render_key_field( $id, $provider ); ?>
								<p>
									<label for="bzpso-model-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Model', 'post-seo-optimizer' ); ?></label><br>
									<input type="text" class="regular-text code" id="bzpso-model-<?php echo esc_attr( $id ); ?>" name="pso[models][<?php echo esc_attr( $id ); ?>]" value="<?php echo esc_attr( $settings['models'][ $id ] ); ?>" placeholder="<?php echo esc_attr( $provider['model'] ); ?>" spellcheck="false">
								</p>
								<p>
									<label for="bzpso-fallback-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Fallback model (optional)', 'post-seo-optimizer' ); ?></label><br>
									<input type="text" class="regular-text code" id="bzpso-fallback-<?php echo esc_attr( $id ); ?>" name="pso[fallback_models][<?php echo esc_attr( $id ); ?>]" value="<?php echo esc_attr( $settings['fallback_models'][ $id ] ); ?>" placeholder="<?php echo esc_attr( '' !== $provider['fallback'] ? $provider['fallback'] : __( 'none', 'post-seo-optimizer' ) ); ?>" spellcheck="false">
									<span class="description"><?php esc_html_e( 'Used automatically when the main model is busy or overloaded. Leave empty to disable.', 'post-seo-optimizer' ); ?></span>
								</p>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Connection', 'post-seo-optimizer' ); ?></th>
						<td>
							<button type="button" class="button" id="bzpso-test-connection"><?php esc_html_e( 'Test connection', 'post-seo-optimizer' ); ?></button>
							<span id="bzpso-test-result" class="bzpso-test-result" role="status" aria-live="polite"></span>
							<p class="description"><?php esc_html_e( 'Tests the saved provider, key and model. Save your changes first.', 'post-seo-optimizer' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Content', 'post-seo-optimizer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bzpso-language"><?php esc_html_e( 'Default language', 'post-seo-optimizer' ); ?></label></th>
						<td>
							<select id="bzpso-language" name="pso[language]">
								<?php foreach ( self::languages() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['language'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Can be changed per product before generating.', 'post-seo-optimizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bzpso-tone"><?php esc_html_e( 'Writing tone', 'post-seo-optimizer' ); ?></label></th>
						<td>
							<select id="bzpso-tone" name="pso[tone]">
								<?php foreach ( self::tones() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['tone'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bzpso-desc-length"><?php esc_html_e( 'Description length', 'post-seo-optimizer' ); ?></label></th>
						<td>
							<select id="bzpso-desc-length" name="pso[desc_length]">
								<?php foreach ( self::description_lengths() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['desc_length'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The description is the largest part of every answer, so its length has the biggest effect on cost.', 'post-seo-optimizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bzpso-store-name"><?php esc_html_e( 'Store name', 'post-seo-optimizer' ); ?></label></th>
						<td><input type="text" class="regular-text" id="bzpso-store-name" name="pso[store_name]" value="<?php echo esc_attr( $settings['store_name'] ); ?>" placeholder="<?php echo esc_attr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>" maxlength="100"></td>
					</tr>
					<tr>
						<th scope="row"><label for="bzpso-audience"><?php esc_html_e( 'Target market / audience', 'post-seo-optimizer' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="bzpso-audience" name="pso[audience]" value="<?php echo esc_attr( $settings['audience'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Online shoppers in Bangladesh', 'post-seo-optimizer' ); ?>" maxlength="200">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bzpso-instructions"><?php esc_html_e( 'Extra instructions', 'post-seo-optimizer' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="4" id="bzpso-instructions" name="pso[instructions]" maxlength="1000"><?php echo esc_textarea( $settings['instructions'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Optional rules applied to every product, e.g. "Mention cash on delivery is available" or "Use metric units".', 'post-seo-optimizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'SEO title', 'post-seo-optimizer' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="pso[append_site_name]" value="1" <?php checked( $settings['append_site_name'] ); ?>>
								<?php esc_html_e( 'Append the site name using Yoast variables (%%sep%% %%sitename%%)', 'post-seo-optimizer' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Yoast SEO', 'post-seo-optimizer' ); ?></th>
						<td>
							<?php if ( BZPSO_SEO_Meta::is_active() ) : ?>
								<span class="bzpso-status-ok"><?php esc_html_e( 'Detected. SEO title, meta description and focus keyphrase are written to Yoast.', 'post-seo-optimizer' ); ?></span>
							<?php else : ?>
								<span class="bzpso-status-warn"><?php esc_html_e( 'Not active. SEO fields are still saved in Yoast\'s fields and used once Yoast is activated.', 'post-seo-optimizer' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Fields', 'post-seo-optimizer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Default fields to generate', 'post-seo-optimizer' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Default fields to generate', 'post-seo-optimizer' ); ?></legend>
								<?php foreach ( BZPSO_Product_Data::fields() as $key => $label ) : ?>
									<label class="bzpso-checkbox">
										<input type="checkbox" name="pso[default_fields][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $settings['default_fields'], true ) ); ?>>
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Fields ticked by default on the product screen. Only ticked fields are written by the AI, so fewer fields cost fewer tokens. You can change the selection for each product. Changing the slug changes the product URL; WordPress redirects the old URL automatically.', 'post-seo-optimizer' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Limits & data', 'post-seo-optimizer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bzpso-max-tokens"><?php esc_html_e( 'Max output tokens', 'post-seo-optimizer' ); ?></label></th>
						<td>
							<input type="number" class="small-text" id="bzpso-max-tokens" name="pso[max_tokens]" value="<?php echo esc_attr( $settings['max_tokens'] ); ?>" min="1000" max="32000" step="500">
							<p class="description"><?php esc_html_e( 'Increase if you see "response was cut off" errors. Bangla text and reasoning models use more tokens.', 'post-seo-optimizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bzpso-timeout"><?php esc_html_e( 'Request timeout (seconds)', 'post-seo-optimizer' ); ?></label></th>
						<td>
							<input type="number" class="small-text" id="bzpso-timeout" name="pso[timeout]" value="<?php echo esc_attr( $settings['timeout'] ); ?>" min="30" max="600" step="30">
							<p class="description"><?php esc_html_e( 'How long to wait for the AI: 30–600 seconds (10 minutes). Generation runs in the background, so long waits are not affected by web server or Cloudflare time limits.', 'post-seo-optimizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bzpso-thinking"><?php esc_html_e( 'Thinking level (Gemini 3 and newer)', 'post-seo-optimizer' ); ?></label></th>
						<td>
							<select id="bzpso-thinking" name="pso[thinking]">
								<?php foreach ( self::thinking_levels() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['thinking'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'How carefully the model reasons before writing. Thinking is billed as output tokens, so it is one of the biggest cost factors: Low is cheapest, High can cost several times more (raise "Max output tokens" for High). The token count shown after each generation tells you the real usage.', 'post-seo-optimizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bzpso-hourly-limit"><?php esc_html_e( 'Requests per user per hour', 'post-seo-optimizer' ); ?></label></th>
						<td>
							<input type="number" class="small-text" id="bzpso-hourly-limit" name="pso[hourly_limit]" value="<?php echo esc_attr( $settings['hourly_limit'] ); ?>" min="0" max="1000">
							<p class="description"><?php esc_html_e( 'Protects your API budget. 0 means unlimited.', 'post-seo-optimizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall', 'post-seo-optimizer' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="pso[delete_data]" value="1" <?php checked( $settings['delete_data'] ); ?>>
								<?php esc_html_e( 'Also delete the saved original-content backups when the plugin is deleted', 'post-seo-optimizer' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Settings and API keys are always removed on uninstall. Optimized product content is never removed.', 'post-seo-optimizer' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the API key input. The stored key is never printed.
	 *
	 * @param string $id       Provider id.
	 * @param array  $provider Provider config.
	 */
	private function render_key_field( $id, array $provider ) {
		$source   = self::key_source( $id );
		$input_id = 'bzpso-key-' . $id;
		?>
		<label for="<?php echo esc_attr( $input_id ); ?>"><?php esc_html_e( 'API key', 'post-seo-optimizer' ); ?></label><br>
		<?php if ( 'constant' === $source ) : ?>
			<input type="password" class="regular-text" id="<?php echo esc_attr( $input_id ); ?>" value="" placeholder="<?php esc_attr_e( 'Defined in wp-config.php', 'post-seo-optimizer' ); ?>" disabled>
			<p class="description bzpso-status-ok">
				<?php
				/* translators: %s: PHP constant name. */
				printf( esc_html__( 'Using the %s constant from wp-config.php.', 'post-seo-optimizer' ), '<code>' . esc_html( $provider['constant'] ) . '</code>' );
				?>
			</p>
		<?php else : ?>
			<input type="password" class="regular-text" id="<?php echo esc_attr( $input_id ); ?>" name="bzpso_api_key[<?php echo esc_attr( $id ); ?>]" value="" autocomplete="new-password" spellcheck="false"
				placeholder="<?php echo 'database' === $source ? esc_attr__( 'Saved. Enter a new key to replace it.', 'post-seo-optimizer' ) : esc_attr__( 'Paste your API key', 'post-seo-optimizer' ); ?>">
			<?php if ( 'database' === $source ) : ?>
				<label class="bzpso-inline-check"><input type="checkbox" name="bzpso_clear_key[<?php echo esc_attr( $id ); ?>]" value="1"> <?php esc_html_e( 'Remove saved key', 'post-seo-optimizer' ); ?></label>
				<p class="description bzpso-status-ok"><?php esc_html_e( 'A key is saved (encrypted).', 'post-seo-optimizer' ); ?></p>
			<?php elseif ( 'undecryptable' === $source ) : ?>
				<p class="description bzpso-status-warn"><?php esc_html_e( 'A key is saved but can no longer be decrypted, probably because the security keys in wp-config.php changed. Please enter it again.', 'post-seo-optimizer' ); ?></p>
			<?php endif; ?>
			<p class="description">
				<a href="<?php echo esc_url( $provider['key_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get an API key', 'post-seo-optimizer' ); ?></a>
				<?php
				/* translators: %s: PHP constant name. */
				printf( esc_html__( 'or define %s in wp-config.php (recommended).', 'post-seo-optimizer' ), '<code>' . esc_html( $provider['constant'] ) . '</code>' );
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Notices after saving.
	 */
	private function render_notices() {
		// Read-only display flags set by our own redirect.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['bzpso_error'] ) ) {
			$error    = sanitize_key( wp_unslash( $_GET['bzpso_error'] ) );
			$messages = array(
				'invalid_key' => __( 'Settings saved, but an API key was not saved because it has an invalid format.', 'post-seo-optimizer' ),
				'encryption'  => __( 'Settings saved, but an API key could not be encrypted on this server. Define it in wp-config.php instead.', 'post-seo-optimizer' ),
			);
			if ( isset( $messages[ $error ] ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $messages[ $error ] ) . '</p></div>';
			}
		} elseif ( isset( $_GET['settings-updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'post-seo-optimizer' ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! function_exists( 'sodium_crypto_secretbox' ) && ! function_exists( 'openssl_encrypt' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Neither libsodium nor OpenSSL is available, so API keys cannot be stored in the database. Define them in wp-config.php.', 'post-seo-optimizer' ) . '</p></div>';
		}
	}
}
