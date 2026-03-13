<?php
/**
 * ShipStation API wrapper.
 * Docs: https://www.shipstation.com/docs/api/
 *
 * ShipStation uses Basic Auth (API Key : API Secret).
 * Unlike Shippo, rates require a specific carrier code, so we first fetch
 * the account's connected carriers, then query each for rates.
 */

defined( 'ABSPATH' ) || exit;

class LT_ShipStation_API {

    const BASE = 'https://ssapi.shipstation.com/';

    private string $api_key;
    private string $api_secret;

    public function __construct( string $api_key, string $api_secret ) {
        $this->api_key    = $api_key;
        $this->api_secret = $api_secret;
    }

    // -------------------------------------------------------------------------
    // Public helpers
    // -------------------------------------------------------------------------

    /**
     * Get live rates across all connected carriers.
     * Normalises the response into the same shape Shippo returns so the rest
     * of the plugin can treat both providers identically.
     *
     * @param array $from   { name, street1, city, state, zip, country }
     * @param array $to     Same shape.
     * @param array $parcel { length, width, height, distance_unit, weight, mass_unit }
     * @return array  Normalised rate objects, or WP_Error.
     */
    public function get_rates( array $from, array $to, array $parcel ): array|WP_Error {
        $carriers = $this->get_carriers();
        if ( is_wp_error( $carriers ) ) {
            return $carriers;
        }

        if ( empty( $carriers ) ) {
            return new WP_Error( 'ss_no_carriers', 'No carriers connected to this ShipStation account.' );
        }

        $all_rates = [];

        foreach ( $carriers as $carrier ) {
            $carrier_code = $carrier['code'] ?? '';
            if ( ! $carrier_code ) {
                continue;
            }

            $body = [
                'carrierCode'    => $carrier_code,
                'serviceCode'    => null,
                'packageCode'    => null,
                'fromPostalCode' => $from['zip'],
                'toState'        => $to['state'],
                'toCountry'      => $to['country'],
                'toPostalCode'   => $to['zip'],
                'toCity'         => $to['city'],
                'weight'         => [
                    'value' => $this->to_ounces( $parcel['weight'], $parcel['mass_unit'] ?? 'lb' ),
                    'units' => 'ounces',
                ],
                'dimensions'     => [
                    'units'  => 'inches',
                    'length' => $this->to_inches( $parcel['length'], $parcel['distance_unit'] ?? 'in' ),
                    'width'  => $this->to_inches( $parcel['width'],  $parcel['distance_unit'] ?? 'in' ),
                    'height' => $this->to_inches( $parcel['height'], $parcel['distance_unit'] ?? 'in' ),
                ],
                'confirmation' => 'none',
                'residential'  => true,
            ];

            $rates = $this->post( 'shipments/getrates', $body );

            if ( is_wp_error( $rates ) ) {
                continue; // Skip unavailable carriers, keep trying others.
            }

            foreach ( $rates as $rate ) {
                // Normalise to a Shippo-compatible shape so downstream code
                // doesn't need to know which provider returned the rate.
                $all_rates[] = [
                    'object_id'     => 'ss_' . $carrier_code . '_' . ( $rate['serviceCode'] ?? uniqid() ),
                    'object_state'  => 'VALID',
                    'provider'      => $carrier['name'] ?? $carrier_code,
                    'servicelevel'  => [ 'name' => $rate['serviceName'] ?? 'Standard' ],
                    'amount'        => (string) ( ( $rate['shipmentCost'] ?? 0 ) + ( $rate['otherCost'] ?? 0 ) ),
                    'currency'      => 'USD',
                    'estimated_days'=> null,
                    // Store enough data to create a label later.
                    '_ss_carrier_code' => $carrier_code,
                    '_ss_service_code' => $rate['serviceCode'] ?? '',
                    '_ss_from'         => $from,
                    '_ss_to'           => $to,
                    '_ss_parcel'       => $parcel,
                ];
            }
        }

        return $all_rates;
    }

    /**
     * Purchase a label.
     * $rate_id is our synthetic "ss_{carrier}_{service}" ID.
     * The rate object must be in the transient cache so we can recover all params.
     *
     * @param array  $rate_data  The full normalised rate object from get_rates().
     * @param string $label_fmt  Ignored for ShipStation (always returns PDF).
     * @return array|WP_Error  Normalised transaction with label_url, tracking_number.
     */
    public function buy_label( array $rate_data, string $label_fmt = 'PDF' ): array|WP_Error {
        $from   = $rate_data['_ss_from'];
        $to     = $rate_data['_ss_to'];
        $parcel = $rate_data['_ss_parcel'];

        $body = [
            'carrierCode'        => $rate_data['_ss_carrier_code'],
            'serviceCode'        => $rate_data['_ss_service_code'],
            'packageCode'        => 'package',
            'confirmation'       => 'none',
            'shipDate'           => gmdate( 'Y-m-d' ),
            'weight'             => [
                'value' => $this->to_ounces( $parcel['weight'], $parcel['mass_unit'] ?? 'lb' ),
                'units' => 'ounces',
            ],
            'dimensions'         => [
                'units'  => 'inches',
                'length' => $this->to_inches( $parcel['length'], $parcel['distance_unit'] ?? 'in' ),
                'width'  => $this->to_inches( $parcel['width'],  $parcel['distance_unit'] ?? 'in' ),
                'height' => $this->to_inches( $parcel['height'], $parcel['distance_unit'] ?? 'in' ),
            ],
            'shipFrom' => [
                'name'       => $from['name'],
                'street1'    => $from['street1'],
                'street2'    => $from['street2'] ?? '',
                'city'       => $from['city'],
                'state'      => $from['state'],
                'postalCode' => $from['zip'],
                'country'    => $from['country'],
            ],
            'shipTo' => [
                'name'       => $to['name'],
                'street1'    => $to['street1'],
                'street2'    => $to['street2'] ?? '',
                'city'       => $to['city'],
                'state'      => $to['state'],
                'postalCode' => $to['zip'],
                'country'    => $to['country'],
                'residential'=> true,
            ],
            'testLabel' => false,
        ];

        $txn = $this->post( 'shipments/createlabel', $body );

        if ( is_wp_error( $txn ) ) {
            return $txn;
        }

        // Normalise to Shippo-compatible transaction shape.
        return [
            'status'           => 'SUCCESS',
            'label_url'        => $txn['labelData'] ?? $txn['labelDownload'] ?? '',
            'tracking_number'  => $txn['trackingNumber'] ?? '',
            'tracking_url_provider' => $txn['trackingUrl'] ?? '',
        ];
    }

    /**
     * Validate account credentials by calling /account/listtags (lightweight).
     */
    public function validate_credentials(): bool {
        $result = $this->get( 'carriers' );
        return ! is_wp_error( $result );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function get_carriers(): array|WP_Error {
        return $this->get( 'carriers' );
    }

    private function post( string $endpoint, array $body ): array|WP_Error {
        $response = wp_remote_post(
            self::BASE . $endpoint,
            [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode( $this->api_key . ':' . $this->api_secret ),
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode( $body ),
                'timeout' => 20,
            ]
        );
        return $this->parse( $response );
    }

    private function get( string $endpoint ): array|WP_Error {
        $response = wp_remote_get(
            self::BASE . $endpoint,
            [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode( $this->api_key . ':' . $this->api_secret ),
                    'Content-Type'  => 'application/json',
                ],
                'timeout' => 20,
            ]
        );
        return $this->parse( $response );
    }

    private function parse( $response ): array|WP_Error {
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 ) {
            $msg = $body['ExceptionMessage'] ?? $body['message'] ?? 'ShipStation API error ' . $code;
            return new WP_Error( 'ss_api_error', $msg, [ 'status' => $code ] );
        }
        return is_array( $body ) ? $body : [];
    }

    // -------------------------------------------------------------------------
    // Unit conversion
    // -------------------------------------------------------------------------

    private function to_ounces( float $weight, string $unit ): float {
        return match ( strtolower( $unit ) ) {
            'lb', 'lbs' => $weight * 16,
            'kg'        => $weight * 35.274,
            'g'         => $weight * 0.035274,
            default     => $weight, // assume oz
        };
    }

    private function to_inches( float $dim, string $unit ): float {
        return match ( strtolower( $unit ) ) {
            'cm'        => $dim * 0.393701,
            'mm'        => $dim * 0.0393701,
            'm'         => $dim * 39.3701,
            default     => $dim, // assume inches
        };
    }
}
