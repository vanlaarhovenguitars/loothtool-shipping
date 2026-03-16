<?php
/**
 * Dokan vendor dashboard — "Shipping Labels" tab.
 * Shows vendors their pending orders with the shipping method the customer chose,
 * so they can purchase the label through their own Shippo account.
 */

defined( 'ABSPATH' ) || exit;

class LT_Vendor_Dashboard {

    public static function init() {
        // Only load if Dokan is active.
        if ( ! function_exists( 'dokan_get_navigation_url' ) ) {
            return;
        }

        add_filter( 'dokan_query_var_filter',     [ __CLASS__, 'register_query_var' ] );
        add_filter( 'dokan_get_dashboard_nav',    [ __CLASS__, 'add_nav_item' ] );
        add_action( 'dokan_load_custom_template', [ __CLASS__, 'load_template' ] );
        add_action( 'wp_enqueue_scripts',         [ __CLASS__, 'enqueue_assets' ] );

        add_action( 'wp_ajax_lt_buy_label',       [ __CLASS__, 'ajax_buy_label' ] );
        add_action( 'wp_ajax_lt_save_ship_prefs', [ __CLASS__, 'ajax_save_ship_prefs' ] );
        add_action( 'wp_ajax_lt_mark_shipped',    [ __CLASS__, 'ajax_mark_shipped' ] );
        add_action( 'wp_ajax_lt_save_tracking',   [ __CLASS__, 'ajax_save_tracking' ] );
    }

    // -------------------------------------------------------------------------
    // Query var registration (required so WP doesn't 404 the endpoint)
    // -------------------------------------------------------------------------

    public static function register_query_var( array $vars ): array {
        $vars[] = 'shipping-labels';
        return $vars;
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
            define( 'DOKAN_TEMPLATE_HANDLED', true );
        }
    }

    // -------------------------------------------------------------------------
    // Page render
    // -------------------------------------------------------------------------

    private static function render_labels_page() {
        $vendor_id = dokan_get_current_user_id();

        echo '<div class="dokan-dashboard-wrap">';
        if ( function_exists( 'dokan_get_template_part' ) ) {
            dokan_get_template_part( 'global/dashboard-nav', '', [ 'active_menu' => 'shipping-labels' ] );
        }

        echo '<div class="dokan-dashboard-content">';
        echo '<article class="dokan-dashboard-content-area">';
        echo '<div class="dokan-w8 dokan-dash-content-left">';

        echo '<h1 class="entry-title">Shipping Labels</h1>';

        // Connect panel — vendors must link their own Shippo account.
        LT_Vendor_Credentials::render_connect_panel( $vendor_id );

        // Render shipping method preference panel.
        self::render_shipping_prefs_panel( $vendor_id );

        // Gate: require a connected Shippo account before showing orders.
        $has_own_account = (bool) LT_Vendor_Credentials::get( $vendor_id );
        if ( ! $has_own_account ) {
            echo '<div style="border-left:4px solid #2980b9;background:#eaf4fb;padding:14px 18px;margin-bottom:20px;border-radius:0 4px 4px 0;">';
            echo '<strong>Connect your Shippo account above</strong> to view your pending orders and enter tracking numbers.';
            echo '</div>';
            echo '</div></article></div></div>';
            return;
        }

        $orders = self::get_vendor_orders( $vendor_id );

        if ( empty( $orders ) ) {
            echo '<p>No processing orders found.</p>';
            echo '</div></article></div></div>';
            return;
        }

        // Split into unshipped (needs action) and shipped (already handled).
        $pending  = [];
        $shipped  = [];
        foreach ( $orders as $order ) {
            $oid = $order->get_id();
            $has_lt_meta = (
                get_post_meta( $oid, '_lt_shippo_label_url_' . $vendor_id, true ) ||
                get_post_meta( $oid, '_lt_shippo_tracking_' . $vendor_id, true ) ||
                get_post_meta( $oid, '_lt_manually_shipped_' . $vendor_id, true )
            );
            $has_dokan_tracking = ! empty( self::get_dokan_tracking_items( $oid, $vendor_id ) );
            if ( $has_lt_meta || $has_dokan_tracking ) {
                $shipped[] = $order;
            } else {
                $pending[] = $order;
            }
        }

        echo '<p style="margin-bottom:16px;">Use the carrier and service shown below to purchase a label through your <a href="https://goshippo.com" target="_blank">Shippo account</a>, then enter the tracking number here.</p>';

        if ( ! empty( $pending ) ) {
            echo '<h3 style="margin-bottom:10px;">Needs Shipping (' . count( $pending ) . ')</h3>';
            foreach ( $pending as $order ) {
                self::render_order_label_card( $order, $vendor_id, false );
            }
        }

        if ( ! empty( $shipped ) ) {
            echo '<h3 style="margin-top:24px;margin-bottom:10px;">Shipped (' . count( $shipped ) . ')</h3>';
            foreach ( $shipped as $order ) {
                self::render_order_label_card( $order, $vendor_id, true );
            }
        }

        echo '</div></article></div></div>';
    }

    // -------------------------------------------------------------------------
    // Order card
    // -------------------------------------------------------------------------

    private static function render_order_label_card( WC_Order $order, int $vendor_id, bool $is_shipped ) {
        $order_id       = $order->get_id();
        $existing_label = get_post_meta( $order_id, '_lt_shippo_label_url_' . $vendor_id, true );
        $tracking_num   = get_post_meta( $order_id, '_lt_shippo_tracking_' . $vendor_id, true );
        $manually_ship  = get_post_meta( $order_id, '_lt_manually_shipped_' . $vendor_id, true );

        // Pull Dokan shipment tracking items as fallback.
        $dokan_tracking = self::get_dokan_tracking_items( $order_id, $vendor_id );

        // Compact shipped card.
        if ( $is_shipped ) {
            echo '<div class="lt-label-card" style="border:1px solid #d4edda;background:#f8fff9;padding:10px 14px;margin-bottom:8px;border-radius:4px;display:flex;flex-wrap:wrap;align-items:center;gap:12px;">';
            echo '<span style="font-weight:600;min-width:80px;">Order #' . esc_html( $order->get_order_number() ) . '</span>';
            echo '<span style="color:#555;">' . esc_html( $order->get_formatted_billing_full_name() ) . '</span>';
            echo '<span style="color:green;font-weight:600;">&#10003; Shipped</span>';
            if ( $tracking_num ) {
                echo '<span style="color:#555;">Tracking: <strong>' . esc_html( $tracking_num ) . '</strong></span>';
            } elseif ( ! empty( $dokan_tracking ) ) {
                foreach ( $dokan_tracking as $item ) {
                    $provider = ! empty( $item->provider_label ) ? esc_html( $item->provider_label ) . ': ' : '';
                    $tnum     = ! empty( $item->number ) ? esc_html( $item->number ) : '';
                    if ( $tnum ) {
                        echo '<span style="color:#555;">' . $provider . '<strong>' . $tnum . '</strong></span>';
                    }
                }
            } elseif ( $manually_ship ) {
                echo '<span style="color:#888;font-style:italic;">No tracking recorded</span>';
            }
            if ( $existing_label ) {
                echo '<a href="' . esc_url( $existing_label ) . '" target="_blank"
                        class="dokan-btn dokan-btn-sm dokan-btn-theme"
                        style="display:inline-block;padding:4px 10px;font-size:12px;">
                        &#128438; Download Label
                     </a>';
            }
            echo '</div>';
            return;
        }

        // Full card for unshipped orders.
        // Build a compact ship-to line.
        $to_parts = array_filter( [
            $order->get_shipping_address_1(),
            $order->get_shipping_city(),
            $order->get_shipping_state(),
            $order->get_shipping_postcode(),
        ] );

        // Chosen carrier line.
        $chosen_label = '';
        $chosen = self::get_chosen_shipping_for_vendor( $order, $vendor_id );
        if ( $chosen ) {
            $chosen_label = esc_html( $chosen['carrier'] );
            if ( $chosen['service'] ) {
                $chosen_label .= ' — ' . esc_html( $chosen['service'] );
            }
            if ( $chosen['days'] ) {
                $days          = (int) $chosen['days'];
                $chosen_label .= ' (est. ' . $days . 'd)';
            }
        } else {
            foreach ( $order->get_items( 'shipping' ) as $item ) {
                $chosen_label = esc_html( $item->get_method_title() );
                break;
            }
        }

        echo '<div class="lt-label-card" style="border:1px solid #e6e6e6;padding:14px 16px;margin-bottom:12px;border-radius:4px;">';

        // Compact header row.
        echo '<div style="display:flex;flex-wrap:wrap;gap:12px;align-items:baseline;margin-bottom:8px;">';
        echo '<strong style="font-size:1em;">Order #' . esc_html( $order->get_order_number() ) . '</strong>';
        echo '<span>' . esc_html( $order->get_formatted_billing_full_name() ) . '</span>';
        if ( $to_parts ) {
            echo '<span style="color:#777;font-size:0.88em;">&#9993; ' . esc_html( implode( ', ', $to_parts ) ) . '</span>';
        }
        if ( $chosen_label ) {
            echo '<span style="color:#555;font-size:0.88em;"><strong>Carrier:</strong> ' . $chosen_label . '</span>';
        }
        echo '</div>';

        echo '<div style="border-top:1px solid #f0f0f0;padding-top:10px;">';

        // Rate selector or fetch button.
        $cached_rates = get_transient( 'lt_shippo_rates_' . $order_id . '_' . $vendor_id );
        if ( $cached_rates ) {
            self::render_rate_selector( $order_id, $vendor_id, $cached_rates );
        } else {
            echo '<button class="dokan-btn dokan-btn-sm dokan-btn-theme lt-fetch-rates-btn"
                         data-order="' . esc_attr( $order_id ) . '"
                         data-vendor="' . esc_attr( $vendor_id ) . '">
                         Get Shipping Rates
                 </button>';
            echo '<div class="lt-rates-output" id="lt-rates-' . esc_attr( $order_id ) . '"></div>';
        }

        // Manual tracking entry.
        echo '<div style="margin-top:10px;">';
        echo '<p style="margin:0 0 4px;color:#666;font-size:0.85em;">Already have a label? Enter tracking:</p>';
        echo '<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">';
        echo '<input type="text" class="lt-tracking-input dokan-form-control"
                     placeholder="e.g. 9400111899223397221318"
                     style="max-width:240px;flex:1;height:32px;font-size:13px;">';
        echo '<button class="dokan-btn dokan-btn-sm dokan-btn-default lt-save-tracking-btn"
                     data-order="' . esc_attr( $order_id ) . '"
                     data-vendor="' . esc_attr( $vendor_id ) . '"
                     data-nonce="' . esc_attr( wp_create_nonce( 'lt_save_tracking_' . $order_id ) ) . '"
                     style="height:32px;">
                     Save
             </button>';
        echo '<span class="lt-tracking-msg" style="font-style:italic;font-size:0.85em;"></span>';
        echo '</div>';
        echo '<label style="display:inline-flex;align-items:center;gap:5px;margin-top:6px;font-size:0.85em;color:#555;cursor:pointer;">';
        echo '<input type="checkbox" class="lt-notify-checkbox" checked> Notify customer by email';
        echo '</label>';
        echo '</div>';

        // Dismiss without tracking.
        echo '<div style="margin-top:8px;">';
        echo '<button class="dokan-btn dokan-btn-sm dokan-btn-default lt-mark-shipped-btn"
                     data-order="' . esc_attr( $order_id ) . '"
                     data-vendor="' . esc_attr( $vendor_id ) . '"
                     data-nonce="' . esc_attr( wp_create_nonce( 'lt_mark_shipped_' . $order_id ) ) . '"
                     style="color:#888;font-size:11px;padding:3px 8px;">
                     &#10003; Already Shipped (no tracking)
             </button>';
        echo '<span class="lt-mark-shipped-msg" style="margin-left:6px;font-style:italic;color:#555;font-size:0.85em;"></span>';
        echo '</div>';

        echo '</div>'; // border-top wrapper
        echo '</div>'; // lt-label-card
    }

    public static function render_rate_selector_public( int $order_id, int $vendor_id, array $rates ) {
        self::render_rate_selector( $order_id, $vendor_id, $rates );
    }

    private static function render_rate_selector( int $order_id, int $vendor_id, array $rates ) {
        if ( empty( $rates ) ) {
            echo '<p style="color:#888;">No rates available for this shipment. Ensure your store address and product dimensions are set.</p>';
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
            if ( empty( $rate['amount'] ) ) {
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
        echo '<label style="display:inline-flex;align-items:center;gap:6px;margin-bottom:10px;font-size:0.9em;color:#555;cursor:pointer;">';
        echo '<input type="checkbox" name="lt_notify" value="1" checked> Notify customer by email when label is purchased';
        echo '</label>';
        echo '<div class="lt-buy-label-msg" style="font-style:italic;color:#555;"></div>';
        echo '</form>';
    }

    /**
     * Get the carrier/service the customer picked at checkout for this vendor's shipment.
     * Returns null for old orders that used a method without carrier meta.
     */
    private static function get_chosen_shipping_for_vendor( WC_Order $order, int $vendor_id ): ?array {
        foreach ( $order->get_items( 'shipping' ) as $item ) {
            if ( $item->get_method_id() === 'lt_shippo' && (int) $item->get_meta( 'vendor_id' ) === $vendor_id ) {
                return [
                    'carrier' => $item->get_meta( 'carrier' ) ?: $item->get_method_title(),
                    'service' => $item->get_meta( 'service' ) ?: '',
                    'days'    => $item->get_meta( 'estimated_days' ) ?: '',
                    'cost'    => (float) $item->get_total(),
                ];
            }
        }
        return null;
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
            '1.0.6',
            true
        );

        wp_localize_script( 'lt-vendor-shipping', 'ltShipping', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'lt_buy_label' ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Shipment recording helper
    // -------------------------------------------------------------------------

    /**
     * Add tracking to the Shipments section via Advanced Shipment Tracking (AST)
     * if it's active. Does NOT use add_order_note() so the customer only receives
     * AST's purpose-built tracking email (if $notify is true), not a raw WC note.
     *
     * Falls back to a private order note (not customer-facing) when AST is absent.
     */
    private static function record_shipment( WC_Order $order, string $carrier, string $tracking_num, bool $notify ): void {
        $order_id = $order->get_id();

        // ── Advanced Shipment Tracking (AST) by Zorem ────────────────────────
        if ( class_exists( 'WC_Advanced_Shipment_Tracking_Actions' ) ) {
            $ast  = WC_Advanced_Shipment_Tracking_Actions::get_instance();
            $item = $ast->add_tracking_item( $order_id, [
                'tracking_provider' => $carrier,
                'tracking_number'   => $tracking_num,
                'date_shipped'      => date( 'Y-m-d' ),
                'status_shipped'    => 1,   // 1 = "On the way"
            ] );

            if ( $notify && method_exists( $ast, 'send_customer_email' ) ) {
                $item_id = is_array( $item ) ? ( $item['tracking_id'] ?? 0 ) : (int) $item;
                if ( $item_id ) {
                    $ast->send_customer_email( $order_id, $item_id );
                }
            }
            return;
        }

        // ── WooCommerce Shipment Tracking (official extension) ────────────────
        if ( class_exists( 'WC_Shipment_Tracking_Actions' ) ) {
            $wc_st = WC_Shipment_Tracking_Actions::get_instance();
            $wc_st->add_tracking_item( $order_id, [
                'tracking_provider' => $carrier,
                'tracking_number'   => $tracking_num,
                'date_shipped'      => time(),
                'status_shipped'    => 1,
            ] );
            // Official plugin sends its own email on add; no extra call needed.
            return;
        }

        // ── Fallback: internal (private) order note only ──────────────────────
        if ( $notify ) {
            $store      = function_exists( 'dokan_get_store_info' ) ? dokan_get_store_info( 0 ) : [];
            $order->add_order_note(
                sprintf( 'Shipped via %s. Tracking: %s', $carrier, $tracking_num ),
                1   // customer-facing; only reached when no tracking plugin is present
            );
        } else {
            $order->add_order_note(
                sprintf( '[LT] Tracking recorded: %s via %s (customer not notified)', $tracking_num, $carrier ),
                0   // internal note only
            );
        }
    }

    // -------------------------------------------------------------------------
    // Data helpers
    // -------------------------------------------------------------------------

    /**
     * Get all processing orders for this vendor.
     *
     * @return WC_Order[]
     */
    /**
     * Verify that at least one item in the order was sold by this vendor.
     * Prevents IDOR: a vendor modifying another vendor's order data.
     */
    private static function vendor_owns_order( int $order_id, int $vendor_id ): bool {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return false;
        }
        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();
            if ( $product_id && (int) get_post_field( 'post_author', $product_id ) === $vendor_id ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Query Dokan's shipment tracking table for a given order + vendor.
     */
    private static function get_dokan_tracking_items( int $order_id, int $vendor_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'dokan_shipping_tracking';
        // Check the table exists first (parameterized to prevent SQL injection).
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [];
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE order_id = %d AND seller_id = %d",
            $order_id,
            $vendor_id
        ) );
        return $rows ?: [];
    }

    private static function get_vendor_orders( int $vendor_id ): array {
        if ( ! function_exists( 'dokan' ) ) {
            return [];
        }

        $args = [
            'seller_id' => $vendor_id,
            'status'    => [ 'processing', 'completed' ],
            'paged'     => 1,
            'limit'     => 50,
        ];

        $result = dokan()->order->all( $args );

        if ( empty( $result ) || ! is_array( $result ) ) {
            return [];
        }

        $orders = [];
        foreach ( $result as $order ) {
            if ( $order instanceof WC_Order ) {
                $orders[] = $order;
            }
        }

        return $orders;
    }

    // -------------------------------------------------------------------------
    // Shipping method preference panel
    // -------------------------------------------------------------------------

    public static function render_shipping_prefs_panel( int $vendor_id ): void {
        $mode              = get_user_meta( $vendor_id, '_lt_ship_mode', true )            ?: 'live';
        $flat_cost         = get_user_meta( $vendor_id, '_lt_ship_flat_cost', true )       ?: '';
        $flat_label        = get_user_meta( $vendor_id, '_lt_ship_flat_label', true )      ?: '';
        $carriers_raw      = get_user_meta( $vendor_id, '_lt_ship_carriers', true )        ?: '[]';
        $carriers          = json_decode( $carriers_raw, true ) ?: [];
        $handling_type     = get_user_meta( $vendor_id, '_lt_ship_handling_type', true )   ?: 'none';
        $handling_amount   = get_user_meta( $vendor_id, '_lt_ship_handling_amount', true ) ?: '';
        $known_carriers    = [ 'USPS', 'UPS', 'FedEx', 'DHL Express', 'Canada Post', 'Australia Post' ];
        ?>
        <div class="lt-ship-prefs-panel" style="border:1px solid #e6e6e6;padding:20px;margin-bottom:28px;border-radius:4px;">
            <h3 style="margin-top:0;">Shipping Method</h3>
            <p style="margin-bottom:14px;">Choose how shipping costs are calculated for your products at checkout.</p>
            <?php wp_nonce_field( 'lt_ship_prefs', 'lt_prefs_nonce' ); ?>

            <label style="display:block;margin-bottom:10px;">
                <input type="radio" name="lt_ship_mode" value="live" <?php checked( $mode, 'live' ); ?>>
                <strong>Live Rates</strong> — real-time rates from your shipping carrier(s)
            </label>
            <div id="lt-carrier-options" style="margin-left:24px;margin-bottom:12px;<?php echo $mode !== 'live' ? 'display:none;' : ''; ?>">
                <p style="margin:4px 0 8px;color:#555;font-size:0.9em;">Show only these carriers (leave all unchecked to show all):</p>
                <?php foreach ( $known_carriers as $c ) : ?>
                    <label style="display:inline-block;margin-right:16px;margin-bottom:6px;">
                        <input type="checkbox" name="lt_ship_carriers[]" value="<?php echo esc_attr( $c ); ?>"
                               <?php checked( in_array( $c, $carriers, true ) ); ?>>
                        <?php echo esc_html( $c ); ?>
                    </label>
                <?php endforeach; ?>
                <p style="margin:10px 0 6px;color:#555;font-size:0.9em;"><strong>Handling fee</strong> (added on top of each live rate):</p>
                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                    <label style="margin-right:4px;">
                        <input type="radio" name="lt_ship_handling_type" value="none" <?php checked( $handling_type, 'none' ); ?>> None
                    </label>
                    <label style="margin-right:4px;">
                        <input type="radio" name="lt_ship_handling_type" value="fixed" <?php checked( $handling_type, 'fixed' ); ?>> Fixed ($)
                    </label>
                    <label>
                        <input type="radio" name="lt_ship_handling_type" value="percent" <?php checked( $handling_type, 'percent' ); ?>> Percentage (%)
                    </label>
                </div>
                <div id="lt-handling-amount-wrap" style="margin-top:6px;<?php echo $handling_type === 'none' ? 'display:none;' : ''; ?>">
                    <input type="number" name="lt_ship_handling_amount"
                           value="<?php echo esc_attr( $handling_amount ); ?>"
                           min="0" step="0.01" placeholder="e.g. 2.50"
                           class="dokan-form-control" style="max-width:120px;">
                    <span id="lt-handling-unit" style="margin-left:4px;color:#555;">
                        <?php echo $handling_type === 'percent' ? '%' : '$'; ?>
                    </span>
                </div>
            </div>

            <label style="display:block;margin-bottom:10px;">
                <input type="radio" name="lt_ship_mode" value="flat" <?php checked( $mode, 'flat' ); ?>>
                <strong>Flat Rate</strong> — charge a fixed fee per shipment
            </label>
            <div id="lt-flat-options" style="margin-left:24px;margin-bottom:12px;<?php echo $mode !== 'flat' ? 'display:none;' : ''; ?>">
                <label style="display:block;margin-bottom:6px;">
                    Label shown to customer:
                    <input type="text" name="lt_ship_flat_label" value="<?php echo esc_attr( $flat_label ); ?>"
                           placeholder="e.g. Standard Shipping" class="dokan-form-control" style="max-width:260px;">
                </label>
                <label style="display:block;">
                    Cost (USD):
                    <input type="number" name="lt_ship_flat_cost" value="<?php echo esc_attr( $flat_cost ); ?>"
                           min="0" step="0.01" placeholder="e.g. 6.99" class="dokan-form-control" style="max-width:120px;">
                </label>
            </div>

            <label style="display:block;margin-bottom:14px;">
                <input type="radio" name="lt_ship_mode" value="free" <?php checked( $mode, 'free' ); ?>>
                <strong>Free Shipping</strong> — no shipping charge (include cost in your product prices)
            </label>

            <button type="button" class="dokan-btn dokan-btn-sm dokan-btn-theme" id="lt-save-ship-prefs">Save</button>
            <span id="lt-prefs-msg" style="margin-left:10px;font-style:italic;color:#555;"></span>

            <script>
            (function(){
                var modes = document.querySelectorAll('input[name="lt_ship_mode"]');
                function toggleSections() {
                    var v = document.querySelector('input[name="lt_ship_mode"]:checked').value;
                    document.getElementById('lt-carrier-options').style.display = v==='live' ? 'block' : 'none';
                    document.getElementById('lt-flat-options').style.display    = v==='flat' ? 'block' : 'none';
                }
                modes.forEach(function(r){ r.addEventListener('change', toggleSections); });

                // Handling fee type toggle.
                document.querySelectorAll('input[name="lt_ship_handling_type"]').forEach(function(r){
                    r.addEventListener('change', function(){
                        var wrap = document.getElementById('lt-handling-amount-wrap');
                        var unit = document.getElementById('lt-handling-unit');
                        wrap.style.display = this.value === 'none' ? 'none' : 'block';
                        unit.textContent   = this.value === 'percent' ? '%' : '$';
                    });
                });

                document.getElementById('lt-save-ship-prefs').addEventListener('click', function(){
                    var mode            = document.querySelector('input[name="lt_ship_mode"]:checked').value;
                    var carriers        = Array.from(document.querySelectorAll('input[name="lt_ship_carriers[]"]:checked')).map(function(c){return c.value;});
                    var flatCost        = document.querySelector('input[name="lt_ship_flat_cost"]').value;
                    var flatLabel       = document.querySelector('input[name="lt_ship_flat_label"]').value;
                    var handlingType    = document.querySelector('input[name="lt_ship_handling_type"]:checked').value;
                    var handlingAmount  = document.querySelector('input[name="lt_ship_handling_amount"]').value;
                    var nonce           = document.querySelector('input[name="lt_prefs_nonce"]').value;
                    var msg             = document.getElementById('lt-prefs-msg');
                    msg.textContent = 'Saving...';
                    fetch(ltShipping.ajaxUrl, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: new URLSearchParams({
                            action: 'lt_save_ship_prefs',
                            lt_prefs_nonce: nonce,
                            lt_ship_mode: mode,
                            lt_ship_flat_cost: flatCost,
                            lt_ship_flat_label: flatLabel,
                            lt_ship_carriers: JSON.stringify(carriers),
                            lt_ship_handling_type: handlingType,
                            lt_ship_handling_amount: handlingAmount
                        })
                    }).then(function(r){return r.json();}).then(function(d){
                        msg.textContent = d.success ? 'Saved!' : ('Error: ' + (d.data || 'unknown'));
                    });
                });
            })();
            </script>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX: buy label using vendor's own Shippo account
    // -------------------------------------------------------------------------

    public static function ajax_buy_label(): void {
        check_ajax_referer( 'lt_buy_label', 'lt_nonce' );

        $order_id  = absint( $_POST['order_id'] ?? 0 );
        $vendor_id = absint( $_POST['vendor_id'] ?? 0 );
        $rate_id   = sanitize_text_field( $_POST['rate_id'] ?? '' );

        if ( ! $order_id || ! $vendor_id || ! $rate_id ) {
            wp_send_json_error( 'Missing parameters.' );
        }

        if ( (int) dokan_get_current_user_id() !== $vendor_id ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        if ( ! self::vendor_owns_order( $order_id, $vendor_id ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        // Validate rate_id against the cached rates for this order — prevents spoofing
        // a rate ID from a different shipment or a cheaper/invalid carrier.
        $cached_rates = get_transient( 'lt_shippo_rates_' . $order_id . '_' . $vendor_id );
        $rate_valid   = false;
        if ( is_array( $cached_rates ) ) {
            foreach ( $cached_rates as $rate ) {
                if ( ( $rate['object_id'] ?? '' ) === $rate_id ) {
                    $rate_valid = true;
                    break;
                }
            }
        }
        if ( ! $rate_valid ) {
            wp_send_json_error( 'Invalid or expired rate selection. Please refresh the rates and try again.' );
        }

        $creds = LT_Vendor_Credentials::get( $vendor_id );
        if ( ! $creds || $creds['type'] !== 'shippo' ) {
            wp_send_json_error( 'No Shippo account connected.' );
        }

        $shippo    = new LT_Shippo_API( $creds['key'] );
        $label_fmt = get_user_meta( $vendor_id, '_lt_label_format', true ) ?: 'PDF_4x6';
        $txn       = $shippo->buy_label( $rate_id, $label_fmt );

        if ( is_wp_error( $txn ) ) {
            error_log( '[LT Shipping] Shippo buy_label error for order ' . $order_id . ': ' . $txn->get_error_message() . ' | data: ' . wp_json_encode( $txn->get_error_data() ) );
            wp_send_json_error( 'Label purchase failed. Please check your Shippo account and try again.' );
        }

        if ( ( $txn['status'] ?? '' ) !== 'SUCCESS' ) {
            error_log( '[LT Shipping] Shippo buy_label non-success for order ' . $order_id . ': ' . wp_json_encode( $txn['messages'] ?? [] ) );
            wp_send_json_error( 'Label purchase failed. Please check your Shippo account and try again.' );
        }

        $label_url    = $txn['label_url'];
        $tracking_num = $txn['tracking_number'];
        $notify       = ! empty( $_POST['notify'] );

        // Resolve carrier name from cached rates so we can populate the Shipments section.
        $carrier      = 'Other';
        $cached_rates = get_transient( 'lt_shippo_rates_' . $order_id . '_' . $vendor_id );
        if ( is_array( $cached_rates ) ) {
            foreach ( $cached_rates as $rate ) {
                if ( ( $rate['object_id'] ?? '' ) === $rate_id ) {
                    $carrier = $rate['provider'] ?? 'Other';
                    break;
                }
            }
        }

        $order = wc_get_order( $order_id );
        update_post_meta( $order_id, '_lt_shippo_label_url_' . $vendor_id, $label_url );
        update_post_meta( $order_id, '_lt_shippo_tracking_' . $vendor_id, $tracking_num );
        update_post_meta( $order_id, '_lt_manually_shipped_' . $vendor_id, 1 );

        self::record_shipment( $order, $carrier, $tracking_num, $notify );

        wp_send_json_success( [
            'label_url'    => $label_url,
            'tracking_num' => $tracking_num,
        ] );
    }

    public static function ajax_save_ship_prefs(): void {
        check_ajax_referer( 'lt_ship_prefs', 'lt_prefs_nonce' );
        $vendor_id = (int) dokan_get_current_user_id();
        if ( ! $vendor_id ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $mode = sanitize_key( $_POST['lt_ship_mode'] ?? 'live' );
        if ( ! in_array( $mode, [ 'live', 'flat', 'free' ], true ) ) {
            $mode = 'live';
        }
        update_user_meta( $vendor_id, '_lt_ship_mode', $mode );
        update_user_meta( $vendor_id, '_lt_ship_flat_cost', max( 0, (float) ( $_POST['lt_ship_flat_cost'] ?? 0 ) ) );
        update_user_meta( $vendor_id, '_lt_ship_flat_label', sanitize_text_field( $_POST['lt_ship_flat_label'] ?? '' ) );

        $carriers_raw = sanitize_text_field( $_POST['lt_ship_carriers'] ?? '[]' );
        $carriers     = json_decode( $carriers_raw, true );
        $carriers     = is_array( $carriers ) ? array_map( 'sanitize_text_field', $carriers ) : [];
        update_user_meta( $vendor_id, '_lt_ship_carriers', wp_json_encode( $carriers ) );

        $handling_type = sanitize_key( $_POST['lt_ship_handling_type'] ?? 'none' );
        if ( ! in_array( $handling_type, [ 'none', 'fixed', 'percent' ], true ) ) {
            $handling_type = 'none';
        }
        update_user_meta( $vendor_id, '_lt_ship_handling_type', $handling_type );
        update_user_meta( $vendor_id, '_lt_ship_handling_amount', max( 0, (float) ( $_POST['lt_ship_handling_amount'] ?? 0 ) ) );

        // Bust rate cache so changes take effect immediately at checkout.
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_lt_rates_%' OR option_name LIKE '_transient_timeout_lt_rates_%'" );

        wp_send_json_success();
    }

    // -------------------------------------------------------------------------
    // AJAX: mark order as already shipped (dismiss from label queue)
    // -------------------------------------------------------------------------

    public static function ajax_mark_shipped(): void {
        $order_id  = absint( $_POST['order_id'] ?? 0 );
        $vendor_id = absint( $_POST['vendor_id'] ?? 0 );

        if ( ! $order_id || ! $vendor_id ) {
            wp_send_json_error( 'Missing parameters.' );
        }

        // Nonce check first — order_id is required to build the nonce action but
        // only absint() has been applied so no harm in reading it before verification.
        check_ajax_referer( 'lt_mark_shipped_' . $order_id, 'nonce' );

        if ( (int) dokan_get_current_user_id() !== $vendor_id ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        if ( ! self::vendor_owns_order( $order_id, $vendor_id ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        update_post_meta( $order_id, '_lt_manually_shipped_' . $vendor_id, 1 );

        wp_send_json_success();
    }

    // -------------------------------------------------------------------------
    // AJAX: save tracking number and notify customer
    // -------------------------------------------------------------------------

    public static function ajax_save_tracking(): void {
        $order_id  = absint( $_POST['order_id'] ?? 0 );
        $vendor_id = absint( $_POST['vendor_id'] ?? 0 );
        $tracking  = sanitize_text_field( $_POST['tracking'] ?? '' );

        if ( ! $order_id || ! $vendor_id || ! $tracking ) {
            wp_send_json_error( 'Missing parameters.' );
        }

        check_ajax_referer( 'lt_save_tracking_' . $order_id, 'nonce' );

        if ( (int) dokan_get_current_user_id() !== $vendor_id ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        if ( ! self::vendor_owns_order( $order_id, $vendor_id ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( 'Order not found.' );
        }

        $notify = ! empty( $_POST['notify'] );

        // Resolve carrier from the customer's chosen shipping method.
        $carrier = 'Other';
        foreach ( $order->get_items( 'shipping' ) as $item ) {
            if ( (int) $item->get_meta( 'vendor_id' ) === $vendor_id || $item->get_method_id() === 'lt_shippo' ) {
                $carrier = $item->get_meta( 'carrier' ) ?: $item->get_method_title() ?: 'Other';
                break;
            }
            $carrier = $item->get_method_title() ?: 'Other';
            break;
        }

        update_post_meta( $order_id, '_lt_shippo_tracking_' . $vendor_id, $tracking );
        update_post_meta( $order_id, '_lt_manually_shipped_' . $vendor_id, 1 );

        self::record_shipment( $order, $carrier, $tracking, $notify );

        wp_send_json_success( [ 'tracking' => $tracking ] );
    }
}

LT_Vendor_Dashboard::init();
