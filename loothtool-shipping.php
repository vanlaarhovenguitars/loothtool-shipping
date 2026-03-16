<?php
/**
 * Plugin Name: Loothtool Shipping
 * Description: Live Shippo shipping rates per vendor in cart, multi-vendor package splitting, and vendor label purchasing via Dokan dashboard.
 * Version: 1.0.0
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'LT_SHIPPING_PATH', plugin_dir_path( __FILE__ ) );
define( 'LT_SHIPPING_URL', plugin_dir_url( __FILE__ ) );

// Load files that have no WooCommerce/Dokan class dependencies immediately.
require_once LT_SHIPPING_PATH . 'includes/class-shippo-api.php';
require_once LT_SHIPPING_PATH . 'includes/class-shipstation-api.php';
require_once LT_SHIPPING_PATH . 'includes/class-vendor-credentials.php';
require_once LT_SHIPPING_PATH . 'includes/class-provider-factory.php';

// Load everything that depends on WooCommerce or Dokan after all plugins are loaded.
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return; // WooCommerce not active — bail silently.
    }

    require_once LT_SHIPPING_PATH . 'includes/class-shipping-method.php';
    require_once LT_SHIPPING_PATH . 'includes/class-cart-packages.php';
    require_once LT_SHIPPING_PATH . 'includes/class-admin-settings.php';
    require_once LT_SHIPPING_PATH . 'includes/class-vendor-dashboard.php';
    require_once LT_SHIPPING_PATH . 'includes/class-order-labels.php';
    require_once LT_SHIPPING_PATH . 'includes/class-product-video.php';

    // Register the custom shipping method with WooCommerce.
    add_filter( 'woocommerce_shipping_methods', function( $methods ) {
        $methods['lt_shippo'] = 'LT_Shippo_Shipping_Method';
        return $methods;
    } );
}, 10 );

// Remove pickup shipping methods — not used on this marketplace.
add_filter( 'woocommerce_shipping_methods', function( $methods ) {
    unset( $methods['local_pickup'] );
    unset( $methods['pickup_location'] );
    return $methods;
}, 20 );
