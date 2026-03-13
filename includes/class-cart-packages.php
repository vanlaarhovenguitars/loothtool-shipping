<?php
/**
 * Split WooCommerce cart into one package per Dokan vendor.
 * Each package gets rated separately so the customer sees per-vendor shipping costs.
 */

defined( 'ABSPATH' ) || exit;

class LT_Cart_Packages {

    public static function init() {
        add_filter( 'woocommerce_cart_shipping_packages', [ __CLASS__, 'split_by_vendor' ] );
    }

    /**
     * Rebuild the packages array so there is one entry per vendor.
     * Dokan stores the vendor (seller) ID as the post author of the product.
     */
    public static function split_by_vendor( array $packages ): array {
        // If Dokan isn't active, return the default packages untouched.
        if ( ! function_exists( 'dokan_get_store_info' ) ) {
            return $packages;
        }

        $vendor_packages = [];

        foreach ( WC()->cart->get_cart() as $key => $item ) {
            $product_id = $item['product_id'];
            $vendor_id  = (int) get_post_field( 'post_author', $product_id );

            if ( ! isset( $vendor_packages[ $vendor_id ] ) ) {
                $store_info = dokan_get_store_info( $vendor_id );

                $vendor_packages[ $vendor_id ] = [
                    'contents'          => [],
                    'contents_cost'     => 0,
                    'applied_coupons'   => WC()->cart->get_applied_coupons(),
                    // Pass vendor_id through so our shipping method can look up the from-address.
                    'vendor_id'         => $vendor_id,
                    'vendor_name'       => $store_info['store_name'] ?? 'Vendor ' . $vendor_id,
                    'destination'       => [
                        'country'   => WC()->customer->get_shipping_country(),
                        'state'     => WC()->customer->get_shipping_state(),
                        'postcode'  => WC()->customer->get_shipping_postcode(),
                        'city'      => WC()->customer->get_shipping_city(),
                        'address'   => WC()->customer->get_shipping_address(),
                        'address_2' => WC()->customer->get_shipping_address_2(),
                    ],
                ];
            }

            $vendor_packages[ $vendor_id ]['contents'][ $key ] = $item;
            $vendor_packages[ $vendor_id ]['contents_cost'] += $item['line_total'];
        }

        // Re-index numerically (WC expects a simple array).
        return array_values( $vendor_packages );
    }
}

LT_Cart_Packages::init();
