<?php
/**
 * Dokan vendor dashboard — "Shipping Labels" tab.
 * Vendors see their pending orders and can buy a Shippo label per order.
 */

defined( 'ABSPATH' ) || exit;

class LT_Vendor_Dashboard {

    public static function init() {
        // Only load if Dokan is active.
        if ( ! function_exists( 'dokan_get_navigation_url' ) ) {
            return;
        }

        add_filter( 'dokan_get_dashboard_nav',    [ __CLASS__, 'add_nav_item' ] );
        add_action( 'dokan_load_custom_template', [ __CLASS__, 'load_template' ] );
        add_action( 'wp_enqueue_scripts',         [ __CLASS__, 'enqueue_assets' ] );

        // AJAX handler for buying a label.
        add_action( 'wp_ajax_lt_buy_label',       [ __CLASS__, 'ajax_buy_label' ] );
    }

    // -------------------------------------------------------------------------
    // Nav item
    // -------------------------------------------------------------------------

    public static function add_nav_item( array $urls ): array {
        $urls['shipping-labels'] = [
            'title' => __( 'Shipping Labels', 'loothtool-shipping' ),
            'icon'  => '<i class="fa fa-shipping-fast"></i>',
            'url'   => dokan_get_navigation_url( 'shipping-labels' ),
            'pos'   => 65,
        ];
        return $urls;
    }

    // -------------------------------------------------------------------------
    // Template routing
    // -------------------------------------------------------------------------

    public static function load_template( array $query_vars ) {
        if ( isset( $query_vars['shipping-labels'] ) ) {
            self::render_labels_page();
            // Tell Dokan the template has been handled.
            define( 'DOKAN_TEMPLATE_HANDLED', true );
        }
    }

    // -------------------------------------------------------------------------
    // Page render
    // -------------------------------------------------------------------------

    private static function render_labels_page() {
        $vendor_id = dokan_get_current_user_id();
        $api_key   = get_option( 'lt_shippo_api_key', '' );

        echo '<div class="dokan-dashboard-wrap">';
        echo '<div class="dokan-dash-sidebar">';
        // Dokan sidebar
        if ( function_exists( 'dokan_get_template_part' ) ) {
            dokan_get_template_part( 'dashboard/dashboard-nav' );
        }
        echo '</div>'; // sidebar

        echo '<div class="dokan-dashboard-content">';
        echo '<article class="dokan-dashboard-content-area">';
        echo '<div class="dokan-w8 dokan-dash-content-left">';

        echo '<h1 class="entry-title">Shipping Labels</h1>';

        if ( ! $api_key ) {
            echo '<div class="dokan-alert dokan-alert-warning">Shipping labels are not configured yet. Please ask the site admin to add the Shippo API key.</div>';
            echo '</div></article></div></div>';
            return;
        }

        // Get this vendor's recent orders that need shipping.
        $orders = self::get_vendor_orders_needing_label( $vendor_id );

        if ( empty( $orders ) ) {
            echo '<p>No orders awaiting a shipping label.</p>';
            echo '</div></article></div></div>';
            return;
        }

        echo '<p>Select a rate to purchase a label for each order. Labels are generated instantly via Shippo and tracking numbers are saved to the order.</p>';

        foreach ( $orders as $order ) {
            self::render_order_label_card( $order, $vendor_id );
        }

        echo '</div></article></div></div>';
    }

    private static function render_order_label_card( WC_Order $order, int $vendor_id ) {
        $order_id       = $order->get_id();
        $existing_label = get_post_meta( $order_id, '_lt_shippo_label_url_' . $vendor_id, true );
        $tracking_num   = get_post_meta( $order_id, '_lt_shippo_tracking_' . $vendor_id, true );

        echo '<div class="lt-label-card" style="border:1px solid #e6e6e6;padding:20px;margin-bottom:20px;border-radius:4px;">';
        echo '<h3>Order #' . esc_html( $order->get_order_number() ) . ' &mdash; ' . esc_html( $order->get_formatted_billing_full_name() ) . '</h3>';

        // Ship-to
        $to_parts = array_filter( [
            $order->get_shipping_address_1(),
            $order->get_shipping_city(),
            $order->get_shipping_state(),
            $order->get_shipping_postcode(),
            $order->get_shipping_country(),
        ] );
        echo '<p><strong>Ship to:</strong> ' . esc_html( implode( ', ', $to_parts ) ) . '</p>';

        if ( $existing_label && $tracking_num ) {
            echo '<p style="color:green;">&#10003; Label purchased. Tracking: <strong>' . esc_html( $tracking_num ) . '</strong></p>';
            echo '<p><a href="' . esc_url( $existing_label ) . '" target="_blank" class="dokan-btn dokan-btn-sm dokan-btn-theme">Download Label (PDF)</a></p>';
        } else {
            // Show cached rates or fetch button.
            $cached_rates = get_transient( 'lt_shippo_rates_' . $order_id . '_' . $vendor_id );
            if ( $cached_rates ) {
                self::render_rate_selector( $order_id, $vendor_id, $cached_rates );
            } else {
                echo '<button class="dokan-btn dokan-btn-sm dokan-btn-default lt-fetch-rates-btn"
                             data-order="' . esc_attr( $order_id ) . '"
                             data-vendor="' . esc_attr( $vendor_id ) . '">
                             Get Shipping Rates
                     </button>';
                echo '<div class="lt-rates-output" id="lt-rates-' . esc_attr( $order_id ) . '"></div>';
            }
        }

        echo '</div>';
    }

    public static function render_rate_selector_public( int $order_id, int $vendor_id, array $rates ) {
        self::render_rate_selector( $order_id, $vendor_id, $rates );
    }

    private static function render_rate_selector( int $order_id, int $vendor_id, array $rates ) {
        if ( empty( $rates ) ) {
            echo '<p>No rates available for this shipment. Ensure vendor address and product dimensions are set.</p>';
            return;
        }

        echo '<form class="lt-buy-label-form">';
        echo wp_nonce_field( 'lt_buy_label', 'lt_nonce', true, false );
        echo '<input type="hidden" name="order_id" value="' . esc_attr( $order_id ) . '">';
        echo '<input type="hidden" name="vendor_id" value="' . esc_attr( $vendor_id ) . '">';

        echo '<table style="width:100%;border-collapse:collapse;margin-bottom:12px;">';
        echo '<thead><tr>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #e6e6e6;">Carrier</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #e6e6e6;">Service</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #e6e6e6;">Est. Days</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #e6e6e6;">Price</th>
                <th></th>
              </tr></thead><tbody>';

        foreach ( $rates as $rate ) {
            if ( empty( $rate['amount'] ) || ( $rate['object_state'] ?? '' ) !== 'VALID' ) {
                continue;
            }
            $carrier = esc_html( $rate['provider'] ?? 'Carrier' );
            $service = esc_html( $rate['servicelevel']['name'] ?? 'Standard' );
            $days    = esc_html( $rate['estimated_days'] ?? '—' );
            $price   = '$' . number_format( (float) $rate['amount'], 2 );
            $rate_id = esc_attr( $rate['object_id'] );

            echo '<tr>
                <td style="padding:6px;">' . $carrier . '</td>
                <td style="padding:6px;">' . $service . '</td>
                <td style="padding:6px;">' . $days . '</td>
                <td style="padding:6px;">' . $price . '</td>
                <td style="padding:6px;">
                    <button type="submit" name="rate_id" value="' . $rate_id . '"
                            class="dokan-btn dokan-btn-sm dokan-btn-theme lt-buy-label-submit">
                        Buy Label
                    </button>
                </td>
              </tr>';
        }

        echo '</tbody></table>';
        echo '</form>';
    }

    // -------------------------------------------------------------------------
    // AJAX: buy label
    // -------------------------------------------------------------------------

    public static function ajax_buy_label() {
        check_ajax_referer( 'lt_buy_label', 'lt_nonce' );

        $order_id  = absint( $_POST['order_id'] ?? 0 );
        $vendor_id = absint( $_POST['vendor_id'] ?? 0 );
        $rate_id   = sanitize_text_field( $_POST['rate_id'] ?? '' );

        if ( ! $order_id || ! $vendor_id || ! $rate_id ) {
            wp_send_json_error( 'Missing parameters.' );
        }

        // Confirm the current user is this vendor.
        if ( (int) dokan_get_current_user_id() !== $vendor_id ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $api_key = get_option( 'lt_shippo_api_key', '' );
        if ( ! $api_key ) {
            wp_send_json_error( 'Shippo not configured.' );
        }

        $label_fmt = get_option( 'lt_shippo_label_format', 'PDF' );
        $shippo    = new LT_Shippo_API( $api_key );
        $txn       = $shippo->buy_label( $rate_id, $label_fmt );

        if ( is_wp_error( $txn ) ) {
            wp_send_json_error( $txn->get_error_message() );
        }

        if ( ( $txn['status'] ?? '' ) !== 'SUCCESS' ) {
            $msg = $txn['messages'][0]['text'] ?? 'Label purchase failed.';
            wp_send_json_error( $msg );
        }

        $label_url    = $txn['label_url'];
        $tracking_num = $txn['tracking_number'];

        // Save to order meta.
        $order = wc_get_order( $order_id );
        update_post_meta( $order_id, '_lt_shippo_label_url_' . $vendor_id, $label_url );
        update_post_meta( $order_id, '_lt_shippo_tracking_' . $vendor_id, $tracking_num );
        $order->add_order_note(
            sprintf( 'Shipping label purchased by vendor #%d. Carrier: %s. Tracking: %s', $vendor_id, $txn['tracking_url_provider'] ?? '', $tracking_num )
        );

        // Also push tracking to WooCommerce order (if the order is awaiting shipping).
        if ( in_array( $order->get_status(), [ 'processing', 'on-hold' ], true ) ) {
            $order->update_status( 'completed', 'Label purchased and order marked complete.' );
        }

        wp_send_json_success( [
            'label_url'    => $label_url,
            'tracking_num' => $tracking_num,
        ] );
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    public static function enqueue_assets() {
        if ( ! function_exists( 'dokan_is_seller_dashboard' ) || ! dokan_is_seller_dashboard() ) {
            return;
        }

        wp_enqueue_script(
            'lt-vendor-shipping',
            LT_SHIPPING_URL . 'assets/vendor-shipping.js',
            [ 'jquery' ],
            '1.0.0',
            true
        );

        wp_localize_script( 'lt-vendor-shipping', 'ltShipping', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'lt_buy_label' ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Data helpers
    // -------------------------------------------------------------------------

    /**
     * Get orders for this vendor that don't have a label yet.
     *
     * @return WC_Order[]
     */
    private static function get_vendor_orders_needing_label( int $vendor_id ): array {
        // Get order IDs from Dokan.
        if ( ! function_exists( 'dokan_get_seller_orders' ) ) {
            return [];
        }

        $args = [
            'seller_id' => $vendor_id,
            'status'    => [ 'wc-processing', 'wc-on-hold' ],
            'paged'     => 1,
            'limit'     => 20,
        ];

        $result = dokan_get_seller_orders( $args );
        $orders = [];

        if ( ! empty( $result['orders'] ) ) {
            foreach ( $result['orders'] as $order_data ) {
                $order = wc_get_order( $order_data->order_id ?? $order_data->ID ?? 0 );
                if ( $order ) {
                    $orders[] = $order;
                }
            }
        }

        return $orders;
    }
}

LT_Vendor_Dashboard::init();
