<?php
/**
 * Plugin Name:          Post SEO Optimizer
 * Plugin URI:           https://bazarpati.com
 * Description:          AI-assisted SEO rewriting for imported WooCommerce products: title, descriptions, slug, image alt text, tags and Yoast SEO title, meta description and focus keyphrase. Every change is reviewed before it is applied and the original content can be restored.
 * Version:              1.1.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Bazarpati
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          post-seo-optimizer
 * Domain Path:          /languages
 * WC requires at least: 7.0
 * WC tested up to:      11.1
 *
 * @package PostSeoOptimizer
 */

defined( 'ABSPATH' ) || exit;

define( 'BZPSO_VERSION', '1.1.0' );
define( 'BZPSO_FILE', __FILE__ );
define( 'BZPSO_DIR', plugin_dir_path( __FILE__ ) );
define( 'BZPSO_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoload BZPSO_* classes from includes/ and includes/providers/.
 * BZPSO_Product_Data => includes/class-bzpso-product-data.php
 */
spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'BZPSO_' ) ) {
			return;
		}
		$file = 'class-' . str_replace( '_', '-', strtolower( $class_name ) ) . '.php';
		foreach ( array( 'includes/', 'includes/providers/' ) as $dir ) {
			if ( is_readable( BZPSO_DIR . $dir . $file ) ) {
				require_once BZPSO_DIR . $dir . $file;
				return;
			}
		}
	}
);

register_activation_hook( __FILE__, array( 'BZPSO_Plugin', 'activate' ) );

// Products are not stored in the HPOS order tables, so the plugin is compatible.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'BZPSO_Plugin', 'instance' ) );
