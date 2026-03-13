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

        // Render the "connect your own account" panel.
        LT_Vendor_Credentials::render_connect_panel( $vendor_id );

        // If vendor has their own account connected, no balance check needed.
        $has_own_account   = (bool) LT_Vendor_Credentials::get( $vendor_id );
        $vendor_country    = self::get_vendor_store_country( $vendor_id );
        $is_international  = ( $vendor_country && $vendor_country !== 'US' );

        // International vendors without their own account: show a hard requirement notice.
        if ( $is_international && ! $has_own_account ) {
            echo '<div class="dokan-alert dokan-alert-warning" style="border-left:4px solid #e67e22;background:#fef9f0;padding:14px 18px;margin-bottom:20px;">';
            echo '<strong>Connect your own shipping account to purchase labels.</strong><br>';
            echo 'Vendors outside the US must use their own Shippo or ShipStation account. ';
            echo 'Rate estimates are still shown at checkout, but label purchases must go through your own account. ';
            echo 'Use the panel above to connect your account.';
            echo '</div>';
        }

        if ( ! $has_own_account && ! $is_international ) {
            // Show the vendor their available balance so they know before buying.
            $balance = self::get_vendor_available_balance( $vendor_id );
            echo '<div style="background:#f1f1f1;border-left:4px solid #a42325;padding:12px 16px;margin-bottom:20px;">';
            echo '<strong>Your available balance:</strong> $' . number_format( $balance, 2 ) . ' USD';
            echo ' &nbsp;—&nbsp; Label costs are automatically deducted from this balance when using the platform account.';
            echo '</div>';
        }

        // Check a platform provider exists (only matters if vendor has no own account).
        $platform_ok = ! is_wp_error( LT_Provider_Factory::platform() );
        if ( ! $has_own_account && ! $platform_ok ) {
            echo '<div class="dokan-alert dokan-alert-warning">Shipping is not configured yet. Connect your own account above or ask the site admin to set up the platform shipping account.</div>';
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

        // ── Resolve provider (vendor's own account or platform default) ────────
        $context = LT_Provider_Factory::for_vendor( $vendor_id );
        if ( is_wp_error( $context ) ) {
            wp_send_json_error( $context->get_error_message() );
        }

        $provider           = $context['provider'];
        $vendor_pays_direct = $context['vendor_pays_direct'];

        // ── International vendor check ───────────────────────────────────────
        // Non-US vendors must use their own account — no platform billing.
        if ( ! $vendor_pays_direct ) {
            $vendor_country = self::get_vendor_store_country( $vendor_id );
            if ( $vendor_country && $vendor_country !== 'US' ) {
                wp_send_json_error( 'International vendors must connect their own Shippo or ShipStation account to purchase labels. Connect your account in the Shipping Labels dashboard.' );
            }
        }

        // ── Find the cached rate object ──────────────────────────────────────
        // We look it up server-side so the vendor cannot pass a fake price.
        $cached_rates = get_transient( 'lt_shippo_rates_' . $order_id . '_' . $vendor_id );
        $rate_object  = null;
        $rate_amount  = 0.0;
        $rate_currency = 'USD';

        if ( $cached_rates ) {
            foreach ( $cached_rates as $rate ) {
                if ( ( $rate['object_id'] ?? '' ) === $rate_id ) {
                    $rate_object   = $rate;
                    $rate_amount   = (float) ( $rate['amount'] ?? 0 );
                    $rate_currency = $rate['currency'] ?? 'USD';
                    break;
                }
            }
        }

        // ── Balance deduction (platform account only) ─────────────────────────
        $charge_amount = 0.0;

        if ( ! $vendor_pays_direct ) {
            // Apply platform markup.
            $markup_pct    = (float) get_option( 'lt_shippo_label_markup_pct', 0 );
            $charge_amount = $rate_amount * ( 1 + $markup_pct / 100 );

            if ( $charge_amount > 0 ) {
                $deducted = self::deduct_vendor_balance(
                    $vendor_id,
                    $charge_amount,
                    $rate_currency,
                    sprintf( 'Shipping label – Order #%d', $order_id )
                );
                if ( is_wp_error( $deducted ) ) {
                    wp_send_json_error( $deducted->get_error_message() );
                }
            }
        }

        // ── Purchase label ───────────────────────────────────────────────────
        $label_fmt = get_option( 'lt_shippo_label_format', 'PDF' );

        // ShipStation needs the full rate object to reconstruct the shipment.
        // Shippo just needs the rate ID string.
        $buy_arg = ( $context['type'] === 'shipstation' && $rate_object )
            ? $rate_object
            : $rate_id;

        $txn = $provider->buy_label( $buy_arg, $label_fmt );

        // If purchase fails, refund any balance already deducted.
        if ( is_wp_error( $txn ) || ( $txn['status'] ?? '' ) !== 'SUCCESS' ) {
            if ( ! $vendor_pays_direct && $charge_amount > 0 ) {
                self::refund_vendor_balance(
                    $vendor_id,
                    $charge_amount,
                    $rate_currency,
                    sprintf( 'Refund – label purchase failed – Order #%d', $order_id )
                );
            }

            $msg = is_wp_error( $txn )
                ? $txn->get_error_message()
                : ( $txn['messages'][0]['text'] ?? 'Label purchase failed.' );

            wp_send_json_error( $msg );
        }

        $label_url    = $txn['label_url'];
        $tracking_num = $txn['tracking_number'];

        // Save to order meta.
        $order = wc_get_order( $order_id );
        update_post_meta( $order_id, '_lt_shippo_label_url_' . $vendor_id, $label_url );
        update_post_meta( $order_id, '_lt_shippo_tracking_' . $vendor_id, $tracking_num );
        update_post_meta( $order_id, '_lt_shippo_label_cost_' . $vendor_id, $charge_amount );

        if ( $vendor_pays_direct ) {
            $billing_note = sprintf( 'Billed directly to vendor\'s own %s account.', strtoupper( $context['type'] ) );
        } else {
            $billing_note = sprintf( '$%.2f %s deducted from vendor balance.', $charge_amount, $rate_currency );
        }

        $order->add_order_note(
            sprintf(
                'Shipping label purchased by vendor #%d. %s Tracking: %s',
                $vendor_id,
                $billing_note,
                $tracking_num
            )
        );

        if ( in_array( $order->get_status(), [ 'processing', 'on-hold' ], true ) ) {
            $order->update_status( 'completed', 'Label purchased and order marked complete.' );
        }

        wp_send_json_success( [
            'label_url'          => $label_url,
            'tracking_num'       => $tracking_num,
            'amount_charged'     => $vendor_pays_direct ? '0.00' : number_format( $charge_amount, 2 ),
            'currency'           => $rate_currency,
            'vendor_pays_direct' => $vendor_pays_direct,
        ] );
    }

    // -------------------------------------------------------------------------
    // Dokan balance helpers
    // -------------------------------------------------------------------------

    /**
     * Deduct an amount from a vendor's Dokan balance.
     * Inserts a debit row into wp_dokan_vendor_balance.
     *
     * @return true|WP_Error
     */
    private static function deduct_vendor_balance( int $vendor_id, float $amount, string $currency, string $note ) {
        $balance = self::get_vendor_available_balance( $vendor_id );

        if ( $balance < $amount ) {
            return new WP_Error(
                'insufficient_balance',
                sprintf(
                    'Insufficient balance. Available: $%.2f. Label cost: $%.2f. Withdraw more earnings first.',
                    $balance,
                    $amount
                )
            );
        }

        return self::insert_balance_row( $vendor_id, $amount, 0, 'shipping_label', $note );
    }

    /**
     * Refund a previously deducted amount back to the vendor's balance.
     */
    private static function refund_vendor_balance( int $vendor_id, float $amount, string $currency, string $note ): void {
        self::insert_balance_row( $vendor_id, 0, $amount, 'shipping_label_refund', $note );
    }

    /**
     * Insert a row into Dokan's vendor balance table.
     *
     * @param float $debit  Amount to subtract (use 0 for credits).
     * @param float $credit Amount to add (use 0 for debits).
     */
    private static function insert_balance_row( int $vendor_id, float $debit, float $credit, string $trn_type, string $note ) {
        global $wpdb;

        $table = $wpdb->prefix . 'dokan_vendor_balance';

        // Check the table exists (requires Dokan to be active).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
        if ( ! $exists ) {
            return new WP_Error( 'dokan_missing', 'Dokan balance table not found.' );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $table,
            [
                'vendor_id'    => $vendor_id,
                'trn_id'       => 0,
                'trn_type'     => $trn_type,
                'perticulars'  => $note,   // Dokan's column name has the typo "perticulars"
                'debit'        => $debit,
                'credit'       => $credit,
                'status'       => 'approved',
                'trn_date'     => current_time( 'mysql' ),
                'balance_date' => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s', '%s', '%f', '%f', '%s', '%s', '%s' ]
        );

        return true;
    }

    /**
     * Get a vendor's net available balance (credits minus debits).
     * Uses Dokan's own function if available, otherwise queries directly.
     */
    private static function get_vendor_available_balance( int $vendor_id ): float {
        // Dokan Lite exposes dokan_get_seller_balance() — use it if present.
        if ( function_exists( 'dokan_get_seller_balance' ) ) {
            return (float) dokan_get_seller_balance( $vendor_id, false );
        }

        // Fallback: query the balance table directly.
        global $wpdb;
        $table = $wpdb->prefix . 'dokan_vendor_balance';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT SUM(credit) - SUM(debit) AS net FROM {$table} WHERE vendor_id = %d AND status = 'approved'",
                $vendor_id
            )
        );

        return $row ? (float) $row->net : 0.0;
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

    /**
     * Get the 2-letter country code from the vendor's Dokan store address.
     * Returns null if Dokan is not active or the vendor has no address set.
     */
    private static function get_vendor_store_country( int $vendor_id ): ?string {
        if ( ! function_exists( 'dokan_get_store_info' ) ) {
            return null;
        }
        $info    = dokan_get_store_info( $vendor_id );
        $country = $info['address']['country'] ?? '';
        return $country ? strtoupper( $country ) : null;
    }
}

LT_Vendor_Dashboard::init();
