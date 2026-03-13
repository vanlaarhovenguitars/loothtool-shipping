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

// Load sub-modules
require_once LT_SHIPPING_PATH . 'includes/class-shippo-api.php';
require_once LT_SHIPPING_PATH . 'includes/class-shipstation-api.php';
require_once LT_SHIPPING_PATH . 'includes/class-vendor-credentials.php';
require_once LT_SHIPPING_PATH . 'includes/class-provider-factory.php';
require_once LT_SHIPPING_PATH . 'includes/class-cart-packages.php';
require_once LT_SHIPPING_PATH . 'includes/class-shipping-method.php';
require_once LT_SHIPPING_PATH . 'includes/class-admin-settings.php';
require_once LT_SHIPPING_PATH . 'includes/class-vendor-dashboard.php';
require_once LT_SHIPPING_PATH . 'includes/class-order-labels.php';

// Register the shipping method
add_filter( 'woocommerce_shipping_methods', function( $methods ) {
    $methods['lt_shippo'] = 'LT_Shippo_Shipping_Method';
    return $methods;
} );
