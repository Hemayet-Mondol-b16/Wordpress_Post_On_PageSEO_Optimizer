<?php
/**
 * Plugin bootstrap.
 *
 * @package PostSeoOptimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires up the admin-only components. Nothing is loaded on the storefront.
 */
final class BZPSO_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var BZPSO_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get (and on first call, boot) the plugin.
	 *
	 * @return BZPSO_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks. Front-end requests return immediately, so the plugin adds no
	 * overhead to page loads for shoppers.
	 */
	private function __construct() {
		// Background jobs run from Action Scheduler (WP-Cron) as a backup starter, which
		// is not an admin request. Registering the hook costs nothing: the class is only
		// loaded when the action actually runs.
		add_action( 'bzpso_run_job', array( 'BZPSO_Jobs', 'run' ) );

		if ( ! is_admin() ) {
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'missing_woocommerce_notice' ) );
			return;
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BZPSO_FILE ), array( $this, 'action_links' ) );

		new BZPSO_Settings();
		new BZPSO_Metabox();
		new BZPSO_Ajax();
		new BZPSO_Jobs();
		new BZPSO_Admin_List();
	}

	/**
	 * Create the settings option with autoload disabled: it is only needed in wp-admin.
	 */
	public static function activate() {
		if ( false === get_option( BZPSO_Settings::OPTION ) ) {
			add_option( BZPSO_Settings::OPTION, BZPSO_Settings::defaults(), '', 'no' );
		}
		if ( false === get_option( BZPSO_Settings::KEYS_OPTION ) ) {
			add_option( BZPSO_Settings::KEYS_OPTION, array(), '', 'no' );
		}
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'post-seo-optimizer', false, dirname( plugin_basename( BZPSO_FILE ) ) . '/languages' );
	}

	/**
	 * Add a "Settings" link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		if ( current_user_can( BZPSO_Settings::CAPABILITY ) ) {
			array_unshift(
				$links,
				sprintf(
					'<a href="%s">%s</a>',
					esc_url( admin_url( 'admin.php?page=' . BZPSO_Settings::PAGE ) ),
					esc_html__( 'Settings', 'post-seo-optimizer' )
				)
			);
		}
		return $links;
	}

	/**
	 * Shown when WooCommerce is not active.
	 */
	public function missing_woocommerce_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Post SEO Optimizer requires WooCommerce to be installed and active.', 'post-seo-optimizer' ) . '</p></div>';
	}
}
