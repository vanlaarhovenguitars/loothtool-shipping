<?php
/**
 * WooCommerce custom shipping method — calls Shippo for live rates per vendor package.
 */

defined( 'ABSPATH' ) || exit;

class LT_Shippo_Shipping_Method extends WC_Shipping_Method {

    public function __construct( $instance_id = 0 ) {
        $this->id                 = 'lt_shippo';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = __( 'Shippo Live Rates', 'loothtool-shipping' );
        $this->method_description = __( 'Real-time shipping rates via Shippo, split per vendor.', 'loothtool-shipping' );
        $this->supports           = [ 'shipping-zones', 'instance-settings' ];

        $this->init();
        $this->enabled = $this->get_option( 'enabled' );
        $this->title   = $this->get_option( 'title', 'Live Shipping' );
    }

    public function init() {
        $this->init_form_fields();
        $this->init_settings();
        add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => 'Enable',
                'type'    => 'checkbox',
                'label'   => 'Enable Shippo Live Rates',
                'default' => 'yes',
            ],
            'title' => [
                'title'   => 'Method title',
                'type'    => 'text',
                'default' => 'Shipping',
            ],
        ];
    }

    /**
     * Called by WooCommerce for each package (one per vendor after our splitter runs).
     */
    public function calculate_shipping( $package = [] ) {
        $vendor_id = $package['vendor_id'] ?? 0;

        // ── Vendor shipping mode preference ────────────────────────────────────
        // Vendors can override live Shippo rates with flat-rate or free shipping.
        $ship_mode = get_user_meta( $vendor_id, '_lt_ship_mode', true ) ?: 'live';

        if ( $ship_mode === 'free' ) {
            $this->add_rate( [
                'id'    => $this->get_rate_id( 'free_shipping' ),
                'label' => __( 'Free Shipping', 'loothtool-shipping' ),
                'cost'  => 0,
            ] );
            return;
        }

        if ( $ship_mode === 'flat' ) {
            $flat_cost = (float) get_user_meta( $vendor_id, '_lt_ship_flat_cost', true );
            $flat_label = get_user_meta( $vendor_id, '_lt_ship_flat_label', true ) ?: __( 'Standard Shipping', 'loothtool-shipping' );
            $this->add_rate( [
                'id'    => $this->get_rate_id( 'flat_rate' ),
                'label' => $flat_label,
                'cost'  => max( 0, $flat_cost ),
            ] );
            return;
        }

        // Live rates mode — get the right provider for this vendor.
        $context = LT_Provider_Factory::for_vendor( $vendor_id );
        if ( is_wp_error( $context ) ) {
            return; // No provider configured — silently skip.
        }

        // Build from-address from Dokan vendor profile.
        $from = $this->get_vendor_from_address( $vendor_id );
        if ( ! $from ) {
            return; // Vendor hasn't set a store address.
        }

        // Build to-address from package destination.
        $to = [
            'name'    => WC()->customer->get_shipping_first_name() . ' ' . WC()->customer->get_shipping_last_name(),
            'street1' => $package['destination']['address'],
            'street2' => $package['destination']['address_2'] ?? '',
            'city'    => $package['destination']['city'],
            'state'   => $package['destination']['state'],
            'zip'     => $package['destination']['postcode'],
            'country' => $package['destination']['country'],
        ];

        // Estimate parcel dimensions from package contents.
        $parcel = $this->estimate_parcel( $package['contents'] );

        // Cache key based on stable inputs — Shippo UUIDs change every call,
        // so we cache by vendor+destination+weight to keep rate IDs stable.
        $cache_key = 'lt_rates_' . md5( $vendor_id . $to['zip'] . $to['country'] . round( $parcel['weight'], 2 ) );
        $rates = get_transient( $cache_key );

        if ( $rates === false ) {
            $rates = $context['provider']->get_rates( $from, $to, $parcel );
            if ( ! is_wp_error( $rates ) && ! empty( $rates ) ) {
                set_transient( $cache_key, $rates, 5 * MINUTE_IN_SECONDS );
            }
        }

        if ( is_wp_error( $rates ) || empty( $rates ) ) {
            return;
        }

        // Filter by vendor's allowed carriers (empty list = show all).
        $allowed_carriers = json_decode( get_user_meta( $vendor_id, '_lt_ship_carriers', true ) ?: '[]', true );
        if ( ! empty( $allowed_carriers ) ) {
            $rates = array_values( array_filter( $rates, function( $r ) use ( $allowed_carriers ) {
                return in_array( $r['provider'] ?? '', $allowed_carriers, true );
            } ) );
        }

        if ( empty( $rates ) ) {
            return;
        }

        // Expose each returned rate as a WooCommerce shipping option.
        foreach ( $rates as $rate ) {
            // Only offer rates that have an actual price.
            if ( empty( $rate['amount'] ) ) {
                continue;
            }

            $carrier  = $rate['provider'] ?? 'Carrier';
            $service  = $rate['servicelevel']['name'] ?? 'Standard';
            $days     = isset( $rate['estimated_days'] ) ? ' (' . $rate['estimated_days'] . ' days)' : '';
            $label    = $carrier . ' ' . $service . $days;
            $cost     = (float) $rate['amount'];

            // Use a stable rate ID based on carrier+service so selections survive
            // across checkout re-renders (Shippo object_id changes on every API call).
            $stable_key = sanitize_key( $carrier . '_' . $service );

            $this->add_rate( [
                'id'        => $this->get_rate_id( $stable_key ),
                'label'     => $label,
                'cost'      => $cost,
                'meta_data' => [
                    'shippo_rate_id' => $rate['object_id'],
                    'vendor_id'      => $vendor_id,
                    'carrier'        => $carrier,
                    'service'        => $service,
                ],
            ] );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Get the vendor's ship-from address using Dokan store info.
     * Falls back to the admin setting if vendor has no address.
     */
    private function get_vendor_from_address( int $vendor_id ): ?array {
        if ( $vendor_id && function_exists( 'dokan_get_store_info' ) ) {
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
                    'country' => $this->normalize_country( $address['country'] ?? 'US' ),
                    'email'   => $user->user_email,
                ];
            }
        }

        // Fall back to global store-from address set in admin settings.
        $fallback = get_option( 'lt_shippo_from_address', [] );
        if ( ! empty( $fallback['country'] ) ) {
            $fallback['country'] = $this->normalize_country( $fallback['country'] );
        }
        return empty( $fallback['zip'] ) ? null : $fallback;
    }

    /**
     * Ensure country is a 2-letter ISO code. WooCommerce sometimes stores full names.
     */
    private function normalize_mass_unit( string $unit ): string {
        $map = [ 'lbs' => 'lb', 'oz' => 'oz', 'g' => 'g', 'kg' => 'kg', 'lb' => 'lb' ];
        return $map[ strtolower( $unit ) ] ?? 'lb';
    }

    private function normalize_country( string $country ): string {
        if ( strlen( $country ) === 2 ) {
            return strtoupper( $country );
        }
        $countries = WC()->countries->get_countries();
        $code = array_search( $country, $countries );
        return $code ? strtoupper( $code ) : strtoupper( $country );
    }

    /**
     * Estimate a combined parcel from all items in the package.
     * Uses product weight/dimensions if set; otherwise falls back to admin defaults.
     */
    private function estimate_parcel( array $contents ): array {
        $total_weight = 0;
        $max_length   = 0;
        $max_width    = 0;
        $total_height = 0;

        $default_weight = (float) get_option( 'lt_shippo_default_weight', 1 );
        $default_length = (float) get_option( 'lt_shippo_default_length', 12 );
        $default_width  = (float) get_option( 'lt_shippo_default_width', 9 );
        $default_height = (float) get_option( 'lt_shippo_default_height', 4 );

        foreach ( $contents as $item ) {
            $product  = $item['data'];
            $qty      = $item['quantity'];
            $weight   = (float) $product->get_weight() ?: $default_weight;
            $length   = (float) $product->get_length() ?: $default_length;
            $width    = (float) $product->get_width()  ?: $default_width;
            $height   = (float) $product->get_height() ?: $default_height;

            $total_weight += $weight * $qty;
            $max_length    = max( $max_length, $length );
            $max_width     = max( $max_width,  $width );
            $total_height += $height * $qty;
        }

        return [
            'length'        => $max_length   ?: $default_length,
            'width'         => $max_width    ?: $default_width,
            'height'        => $total_height ?: $default_height,
            'distance_unit' => get_option( 'woocommerce_dimension_unit', 'in' ),
            'weight'        => $total_weight ?: $default_weight,
            'mass_unit'     => $this->normalize_mass_unit( get_option( 'woocommerce_weight_unit', 'lb' ) ),
        ];
    }
}
