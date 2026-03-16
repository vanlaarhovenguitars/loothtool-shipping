<?php
/**
 * Shippo API wrapper.
 * Docs: https://docs.goshippo.com/shippoapi/public-api/
 */

defined( 'ABSPATH' ) || exit;

class LT_Shippo_API {

    const BASE = 'https://api.goshippo.com/';

    private string $api_key;

    public function __construct( string $api_key ) {
        $this->api_key = $api_key;
    }

    // -------------------------------------------------------------------------
    // Public helpers
    // -------------------------------------------------------------------------

    /**
     * Get live shipping rates for a shipment.
     *
     * @param array $from   { name, street1, city, state, zip, country, phone, email }
     * @param array $to     Same shape as $from.
     * @param array $parcel { length, width, height, distance_unit, weight, mass_unit }
     * @return array  List of rate objects, or WP_Error on failure.
     */
    public function get_rates( array $from, array $to, array $parcel ) {
        $body = [
            'address_from' => $from,
            'address_to'   => $to,
            'parcels'      => [ $parcel ],
            'async'        => false,
        ];

        $response = $this->post( 'shipments/', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['rates'] ?? [];
    }

    /**
     * Purchase a label from a rate object ID.
     *
     * @param string $rate_id   Shippo rate object_id.
     * @param string $label_fmt 'PDF' | 'PNG' | 'ZPLII'
     * @return array|WP_Error  Transaction object with label_url, tracking_number, etc.
     */
    public function buy_label( string $rate_id, string $label_fmt = 'PDF' ) {
        $body = [
            'rate'              => $rate_id,
            'label_file_type'   => $label_fmt,
            'async'             => false,
        ];

        return $this->post( 'transactions/', $body );
    }

    /**
     * Verify the API key is valid by hitting a lightweight endpoint.
     * Returns true on success, WP_Error on failure.
     */
    public function ping(): bool|WP_Error {
        $result = $this->get( 'carrier_accounts/?results=1' );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return true;
    }

    /**
     * Validate / create an address (optional call before rating).
     *
     * @param array $address
     * @return array|WP_Error
     */
    public function validate_address( array $address ) {
        $address['validate'] = true;
        return $this->post( 'addresses/', $address );
    }

    // -------------------------------------------------------------------------
    // Private HTTP helpers
    // -------------------------------------------------------------------------

    private function post( string $endpoint, array $body ) {
        $response = wp_remote_post(
            self::BASE . $endpoint,
            [
                'headers' => [
                    'Authorization' => 'ShippoToken ' . $this->api_key,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode( $body ),
                'timeout' => 20,
            ]
        );

        return $this->parse( $response );
    }

    private function get( string $endpoint ) {
        $response = wp_remote_get(
            self::BASE . $endpoint,
            [
                'headers' => [
                    'Authorization' => 'ShippoToken ' . $this->api_key,
                    'Content-Type'  => 'application/json',
                ],
                'timeout' => 20,
            ]
        );

        return $this->parse( $response );
    }

    private function parse( $response ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 ) {
            $msg = $body['detail'] ?? $body['message'] ?? 'Shippo API error ' . $code;
            return new WP_Error( 'shippo_api_error', $msg, [ 'status' => $code, 'body' => $body ] );
        }

        return $body;
    }
}
