<?php
/**
 * Fetches Shippo rates for vendor orders using the vendor's own connected account,
 * and shows per-vendor tracking info on the customer's order detail page.
 */

defined( 'ABSPATH' ) || exit;

class LT_Order_Labels {

    public static function init() {
        // AJAX: fetch rates for an order (called from vendor dashboard JS).
        add_action( 'wp_ajax_lt_fetch_order_rates', [ __CLASS__, 'ajax_fetch_order_rates' ] );

        // Customer-facing: show tracking numbers on "My Account > Orders" detail page.
        add_action( 'woocommerce_order_details_after_order_table', [ __CLASS__, 'show_tracking_on_order' ] );

        // Admin order page: show labels in meta box.
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_admin_meta_box' ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: fetch rates using vendor's own Shippo account
    // -------------------------------------------------------------------------

    public static function ajax_fetch_order_rates() {
        check_ajax_referer( 'lt_buy_label', 'lt_nonce' );

        $order_id  = absint( $_POST['order_id'] ?? 0 );
        $vendor_id = absint( $_POST['vendor_id'] ?? 0 );

        if ( ! $order_id || ! $vendor_id ) {
            wp_send_json_error( 'Missing parameters.' );
        }

        if ( (int) dokan_get_current_user_id() !== $vendor_id ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        // Verify vendor owns items in this order (prevents IDOR).
        $order_check = wc_get_order( $order_id );
        if ( ! $order_check ) {
            wp_send_json_error( 'Order not found.' );
        }
        $vendor_has_items = false;
        foreach ( $order_check->get_items() as $item ) {
            $pid = $item->get_product_id();
            if ( $pid && (int) get_post_field( 'post_author', $pid ) === $vendor_id ) {
                $vendor_has_items = true;
                break;
            }
        }
        if ( ! $vendor_has_items ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        // Use the vendor's own connected Shippo account.
        $creds = LT_Vendor_Credentials::get( $vendor_id );
        if ( ! $creds || $creds['type'] !== 'shippo' ) {
            wp_send_json_error( 'No Shippo account connected. Connect your account in the Shipping Account panel above.' );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( 'Order not found.' );
        }

        $to = [
            'name'    => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'street1' => $order->get_shipping_address_1(),
            'street2' => $order->get_shipping_address_2(),
            'city'    => $order->get_shipping_city(),
            'state'   => $order->get_shipping_state(),
            'zip'     => $order->get_shipping_postcode(),
            'country' => $order->get_shipping_country(),
        ];

        $from = self::get_vendor_address( $vendor_id );
        if ( ! $from ) {
            wp_send_json_error( 'Vendor has no ship-from address. Set it in your store settings.' );
        }

        $parcel = self::estimate_parcel_for_vendor_order( $order, $vendor_id );

        $shippo = new LT_Shippo_API( $creds['key'] );
        $rates  = $shippo->get_rates( $from, $to, $parcel );

        if ( is_wp_error( $rates ) ) {
            // Log full Shippo error server-side; return only a generic message to the client
            // to prevent leaking internal API details, field names, or account structure.
            error_log( '[LT Shipping] Shippo get_rates error for order ' . $order_id . ': ' . $rates->get_error_message() . ' | data: ' . wp_json_encode( $rates->get_error_data() ) );
            wp_send_json_error( 'Unable to fetch shipping rates. Please check your store address and try again.' );
        }

        // Filter to only the carrier/service the customer chose at checkout.
        $chosen_carrier = null;
        $chosen_service = null;
        foreach ( $order->get_items( 'shipping' ) as $item ) {
            if ( $item->get_method_id() === 'lt_shippo' && (int) $item->get_meta( 'vendor_id' ) === $vendor_id ) {
                $chosen_carrier = $item->get_meta( 'carrier' );
                $chosen_service = $item->get_meta( 'service' );
                break;
            }
        }
        if ( $chosen_carrier && $chosen_service ) {
            $target = sanitize_key( $chosen_carrier . '_' . $chosen_service );
            $rates  = array_values( array_filter( $rates, function ( $rate ) use ( $target ) {
                $key = sanitize_key( ( $rate['provider'] ?? '' ) . '_' . ( $rate['servicelevel']['name'] ?? '' ) );
                return $key === $target;
            } ) );
        }

        // Cache for 30 minutes.
        set_transient( 'lt_shippo_rates_' . $order_id . '_' . $vendor_id, $rates, 30 * MINUTE_IN_SECONDS );

        ob_start();
        LT_Vendor_Dashboard::render_rate_selector_public( $order_id, $vendor_id, $rates );
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }

    // -------------------------------------------------------------------------
    // Customer-facing tracking
    // -------------------------------------------------------------------------

    public static function show_tracking_on_order( WC_Order $order ) {
        $order_id = $order->get_id();
        $vendors  = [];

        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();
            $vendor_id  = (int) get_post_field( 'post_author', $product_id );
            if ( $vendor_id && ! isset( $vendors[ $vendor_id ] ) ) {
                $tracking = get_post_meta( $order_id, '_lt_shippo_tracking_' . $vendor_id, true );
                if ( $tracking ) {
                    $store      = dokan_get_store_info( $vendor_id );
                    $store_name = $store['store_name'] ?? 'Vendor';
                    $vendors[ $vendor_id ] = [
                        'name'     => $store_name,
                        'tracking' => $tracking,
                    ];
                }
            }
        }

        if ( empty( $vendors ) ) {
            return;
        }

        echo '<h2>Shipment Tracking</h2>';
        echo '<table class="woocommerce-table woocommerce-table--order-details shop_table">';
        echo '<thead><tr><th>Seller</th><th>Tracking Number</th></tr></thead><tbody>';
        foreach ( $vendors as $v ) {
            echo '<tr>';
            echo '<td>' . esc_html( $v['name'] ) . '</td>';
            echo '<td><strong>' . esc_html( $v['tracking'] ) . '</strong></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    // -------------------------------------------------------------------------
    // Admin meta box
    // -------------------------------------------------------------------------

    public static function add_admin_meta_box() {
        add_meta_box(
            'lt_shippo_labels',
            'Shippo Labels',
            [ __CLASS__, 'render_admin_meta_box' ],
            'shop_order',
            'side',
            'default'
        );
    }

    public static function render_admin_meta_box( WP_Post $post ) {
        $order_id = $post->ID;
        $order    = wc_get_order( $order_id );
        $found    = false;

        foreach ( $order->get_items() as $item ) {
            $vendor_id = (int) get_post_field( 'post_author', $item->get_product_id() );
            $label_url = get_post_meta( $order_id, '_lt_shippo_label_url_' . $vendor_id, true );
            $tracking  = get_post_meta( $order_id, '_lt_shippo_tracking_' . $vendor_id, true );

            if ( $label_url ) {
                $found = true;
                $store = dokan_get_store_info( $vendor_id );
                echo '<p><strong>' . esc_html( $store['store_name'] ?? 'Vendor ' . $vendor_id ) . '</strong><br>';
                echo 'Tracking: ' . esc_html( $tracking ) . '<br>';
                echo '<a href="' . esc_url( $label_url ) . '" target="_blank">Download Label</a></p>';
                break;
            }
        }

        if ( ! $found ) {
            echo '<p>No labels purchased yet.</p>';
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function get_vendor_address( int $vendor_id ): ?array {
        if ( function_exists( 'dokan_get_store_info' ) ) {
            $info    = dokan_get_store_info( $vendor_id );
            $address = $info['address'] ?? [];

            if ( ! empty( $address['street_1'] ) && ! empty( $address['zip'] ) ) {
                $user = get_userdata( $vendor_id );
                return [
                    'name'    => $info['store_name'] ?? $user->display_name,
                    'street1' => $address['street_1'],
                    'street2' => $address['street_2'] ?? '',
                    'city'    => $address['city'] ?? '',
                    'state'   => $address['state'] ?? '',
                    'zip'     => $address['zip'],
                    'country' => $address['country'] ?? 'US',
                    'email'   => $user->user_email,
                    'phone'   => $info['phone'] ?? get_user_meta( $user->ID, 'billing_phone', true ) ?? '',
                ];
            }
        }

        $fb = get_option( 'lt_shippo_from_address', [] );
        return empty( $fb['zip'] ) ? null : $fb;
    }

    private static function estimate_parcel_for_vendor_order( WC_Order $order, int $vendor_id ): array {
        $default_weight = (float) get_option( 'lt_shippo_default_weight', 1 );
        $default_length = (float) get_option( 'lt_shippo_default_length', 12 );
        $default_width  = (float) get_option( 'lt_shippo_default_width', 9 );
        $default_height = (float) get_option( 'lt_shippo_default_height', 4 );

        $total_weight = 0;
        $max_length   = 0;
        $max_width    = 0;
        $total_height = 0;

        foreach ( $order->get_items() as $item ) {
            $pid = $item->get_product_id();
            if ( (int) get_post_field( 'post_author', $pid ) !== $vendor_id ) {
                continue;
            }
            $product = $item->get_product();
            $qty     = $item->get_quantity();

            $total_weight += ( (float) $product->get_weight() ?: $default_weight ) * $qty;
            $max_length    = max( $max_length, (float) $product->get_length() ?: $default_length );
            $max_width     = max( $max_width,  (float) $product->get_width()  ?: $default_width );
            $total_height += ( (float) $product->get_height() ?: $default_height ) * $qty;
        }

        return [
            'length'        => $max_length   ?: $default_length,
            'width'         => $max_width    ?: $default_width,
            'height'        => $total_height ?: $default_height,
            'distance_unit' => get_option( 'woocommerce_dimension_unit', 'in' ),
            'weight'        => $total_weight ?: $default_weight,
            'mass_unit'     => self::normalize_weight_unit( get_option( 'woocommerce_weight_unit', 'lb' ) ),
        ];
    }

    private static function normalize_weight_unit( string $wc_unit ): string {
        $map = [ 'lbs' => 'lb', 'kgs' => 'kg' ];
        return $map[ $wc_unit ] ?? $wc_unit;
    }
}

LT_Order_Labels::init();
