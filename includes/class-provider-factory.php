<?php
/**
 * Provider factory — decides which shipping provider and credentials to use.
 *
 * Priority order:
 *   1. Vendor's own connected account (billed directly to them, no balance deduction)
 *   2. Platform default (Shippo or ShipStation set in admin, billed to platform + Dokan balance deduction)
 */

defined( 'ABSPATH' ) || exit;

class LT_Provider_Factory {

    /**
     * Get the shipping provider instance to use for a given vendor.
     *
     * Returns an object with a standardised interface:
     *   ->get_rates( $from, $to, $parcel ) : array|WP_Error
     *   ->buy_label( $rate_id_or_data, $fmt ) : array|WP_Error
     *
     * Also returns metadata about whether the vendor is paying directly.
     *
     * @return array{
     *   provider: LT_Shippo_API|LT_ShipStation_API,
     *   type: 'shippo'|'shipstation',
     *   vendor_pays_direct: bool,
     *   vendor_id: int
     * }|WP_Error
     */
    public static function for_vendor( int $vendor_id ): array|WP_Error {
        // 1. Check if the vendor has their own credentials stored.
        $vendor_creds = LT_Vendor_Credentials::get( $vendor_id );

        if ( $vendor_creds ) {
            $provider = self::build( $vendor_creds['type'], $vendor_creds['key'], $vendor_creds['secret'] ?? '' );
            if ( is_wp_error( $provider ) ) {
                return $provider;
            }
            return [
                'provider'           => $provider,
                'type'               => $vendor_creds['type'],
                'vendor_pays_direct' => true,
                'vendor_id'          => $vendor_id,
            ];
        }

        // 2. Fall back to platform credentials.
        return self::platform();
    }

    /**
     * Get the platform-level provider (no vendor context).
     *
     * @return array|WP_Error
     */
    public static function platform(): array|WP_Error {
        $type = get_option( 'lt_shippo_provider', 'shippo' ); // 'shippo' | 'shipstation'

        if ( $type === 'shipstation' ) {
            $key    = get_option( 'lt_ss_api_key', '' );
            $secret = get_option( 'lt_ss_api_secret', '' );
            if ( ! $key || ! $secret ) {
                return new WP_Error( 'no_provider', 'ShipStation API key/secret not configured.' );
            }
        } else {
            $key    = get_option( 'lt_shippo_api_key', '' );
            $secret = '';
            if ( ! $key ) {
                return new WP_Error( 'no_provider', 'Shippo API key not configured.' );
            }
        }

        $provider = self::build( $type, $key, $secret );
        if ( is_wp_error( $provider ) ) {
            return $provider;
        }

        return [
            'provider'           => $provider,
            'type'               => $type,
            'vendor_pays_direct' => false,
            'vendor_id'          => 0,
        ];
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private static function build( string $type, string $key, string $secret ): LT_Shippo_API|LT_ShipStation_API|WP_Error {
        return match ( $type ) {
            'shippo'      => new LT_Shippo_API( $key ),
            'shipstation' => new LT_ShipStation_API( $key, $secret ),
            default       => new WP_Error( 'unknown_provider', 'Unknown shipping provider: ' . $type ),
        };
    }
}
