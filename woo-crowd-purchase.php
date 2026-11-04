<?php
/**
 * Plugin Name: Woo Crowd Purchase
 * Description: Crowdfunded contributions toward WooCommerce simple products; each funded round creates one real completed fulfillment order.
 * Version: 1.1.0
 * Update URI: https://github.com/Cajadfa/woo-crowd-purchase
 * Author: Sajad
 * Text Domain: woo-crowd-purchase
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * WC requires at least: 8.2
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'WCP_VERSION', '1.1.0' );
define( 'WCP_FILE', __FILE__ );
define( 'WCP_PATH', plugin_dir_path( __FILE__ ) );

require_once WCP_PATH . 'includes/class-wcp-github-updater.php';
WCP_GitHub_Updater::init();

add_action( 'before_woocommerce_init', static function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

register_activation_hook( __FILE__, static function () {
    require_once WCP_PATH . 'includes/class-wcp-storage.php';
    WCP_Storage::install();
} );

add_action( 'plugins_loaded', static function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', static function () {
            if ( current_user_can( 'activate_plugins' ) ) {
                echo '<div class="notice notice-error"><p>افزونه «مشارکت در خرید» به ووکامرس فعال نیاز دارد.</p></div>';
            }
        } );
        return;
    }
    require_once WCP_PATH . 'includes/class-wcp-storage.php';
    require_once WCP_PATH . 'includes/class-wcp-plugin.php';
    WCP_Plugin::instance();
} );
