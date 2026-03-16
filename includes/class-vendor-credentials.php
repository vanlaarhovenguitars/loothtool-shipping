<?php
/**
 * Vendor shipping account credentials.
 *
 * Vendors can connect their own Shippo or ShipStation account from their
 * Dokan dashboard. When connected, all label purchases go through their
 * account and are billed directly to them — no Dokan balance deduction.
 *
 * Keys are encrypted at rest using AES-256-CBC with the WP auth salt.
 */

defined( 'ABSPATH' ) || exit;

class LT_Vendor_Credentials {

    const META_KEY = '_lt_shipping_credentials';

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Get a vendor's stored credentials (decrypted).
     *
     * @return array{ type: string, key: string, secret: string }|null
     */
    public static function get( int $vendor_id ): ?array {
        $raw = get_user_meta( $vendor_id, self::META_KEY, true );
        if ( ! $raw ) {
            return null;
        }

        $decrypted = self::decrypt( $raw );
        if ( ! $decrypted ) {
            return null;
        }

        $data = json_decode( $decrypted, true );
        return is_array( $data ) && ! empty( $data['key'] ) ? $data : null;
    }

    /**
     * Save a vendor's credentials (encrypted).
     *
     * @param string $type   'shippo' | 'shipstation'
     * @param string $key    API key
     * @param string $secret API secret (ShipStation only; empty for Shippo)
     */
    public static function save( int $vendor_id, string $type, string $key, string $secret = '' ): void {
        $payload   = wp_json_encode( compact( 'type', 'key', 'secret' ) );
        $encrypted = self::encrypt( $payload );
        update_user_meta( $vendor_id, self::META_KEY, $encrypted );
    }

    /**
     * Remove a vendor's stored credentials.
     */
    public static function delete( int $vendor_id ): void {
        delete_user_meta( $vendor_id, self::META_KEY );
    }

    /**
     * Test raw (unsaved) credentials without writing them to the DB first.
     * Used so we can validate before saving, eliminating the race window.
     *
     * @return true|WP_Error
     */
    public static function validate_raw( string $type, string $key, string $secret = '' ): bool|WP_Error {
        if ( $type === 'shippo' ) {
            $api = new LT_Shippo_API( $key );
            return $api->ping();
        }
        if ( $type === 'shipstation' ) {
            $api = new LT_ShipStation_API( $key, $secret );
            return $api->validate_credentials() ? true : new WP_Error( 'ss_invalid', 'ShipStation credentials are invalid.' );
        }
        return new WP_Error( 'unknown_type', 'Unknown provider type.' );
    }

    /**
     * Test whether stored credentials actually work.
     *
     * @return true|WP_Error
     */
    public static function validate( int $vendor_id ): bool|WP_Error {
        $creds = self::get( $vendor_id );
        if ( ! $creds ) {
            return new WP_Error( 'no_creds', 'No credentials stored.' );
        }

        if ( $creds['type'] === 'shippo' ) {
            $api = new LT_Shippo_API( $creds['key'] );
            return $api->ping();
        }

        if ( $creds['type'] === 'shipstation' ) {
            $api = new LT_ShipStation_API( $creds['key'], $creds['secret'] );
            return $api->validate_credentials() ? true : new WP_Error( 'ss_invalid', 'ShipStation credentials are invalid.' );
        }

        return new WP_Error( 'unknown_type', 'Unknown provider type.' );
    }

    // -------------------------------------------------------------------------
    // Dokan dashboard UI (rendered inside vendor dashboard tab)
    // -------------------------------------------------------------------------

    public static function init() {
        // Hook into Dokan dashboard AJAX to save credentials.
        add_action( 'wp_ajax_lt_save_vendor_credentials',   [ __CLASS__, 'ajax_save' ] );
        add_action( 'wp_ajax_lt_remove_vendor_credentials', [ __CLASS__, 'ajax_remove' ] );
    }

    /**
     * Render the "Connect your shipping account" panel.
     * Called from LT_Vendor_Dashboard::render_labels_page().
     */
    public static function render_connect_panel( int $vendor_id ): void {
        $existing = self::get( $vendor_id );
        ?>
        <div class="lt-connect-panel" style="border:1px solid #e6e6e6;padding:20px;margin-bottom:28px;border-radius:4px;">
            <h3 style="margin-top:0;">Your Shipping Account</h3>

            <?php if ( $existing ) : ?>
                <div id="lt-account-connected">
                    <p style="color:green;">
                        &#10003; Connected: <strong><?php echo esc_html( strtoupper( $existing['type'] ) ); ?></strong>
                        &mdash; Labels are billed directly to your account.
                    </p>
                    <button class="dokan-btn dokan-btn-sm dokan-btn-danger" id="lt-remove-account"
                            data-nonce="<?php echo wp_create_nonce( 'lt_vendor_creds' ); ?>">
                        Disconnect Account
                    </button>
                </div>
            <?php else : ?>
                <p>Connect your own Shippo or ShipStation account. Labels will be charged directly to you — no platform deduction.</p>
                <form id="lt-connect-account-form">
                    <?php wp_nonce_field( 'lt_vendor_creds', 'lt_creds_nonce' ); ?>

                    <div style="margin-bottom:12px;">
                        <label><strong>Provider</strong></label><br>
                        <label style="margin-right:16px;">
                            <input type="radio" name="lt_provider_type" value="shippo" checked> Shippo
                        </label>
                        <label>
                            <input type="radio" name="lt_provider_type" value="shipstation"> ShipStation
                        </label>
                    </div>

                    <div id="lt-shippo-fields">
                        <label><strong>Shippo API Key</strong></label><br>
                        <input type="text" name="lt_api_key" class="dokan-form-control"
                               placeholder="shippo_live_xxxxxxxx" style="max-width:400px;margin-bottom:8px;" />
                        <p class="description">Found in your Shippo dashboard → Settings → API.</p>
                    </div>

                    <div id="lt-ss-fields" style="display:none;">
                        <label><strong>ShipStation API Key</strong></label><br>
                        <input type="text" name="lt_ss_key" class="dokan-form-control"
                               placeholder="API Key" style="max-width:400px;margin-bottom:8px;" /><br>
                        <label><strong>ShipStation API Secret</strong></label><br>
                        <input type="password" name="lt_ss_secret" class="dokan-form-control"
                               placeholder="API Secret" style="max-width:400px;margin-bottom:8px;" />
                        <p class="description">Found in ShipStation → Account Settings → API Keys.</p>
                    </div>

                    <button type="submit" class="dokan-btn dokan-btn-sm dokan-btn-theme" id="lt-connect-submit">
                        Connect &amp; Verify
                    </button>
                    <span id="lt-connect-msg" style="margin-left:10px;"></span>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    public static function ajax_save(): void {
        check_ajax_referer( 'lt_vendor_creds', 'lt_creds_nonce' );

        $vendor_id = (int) dokan_get_current_user_id();
        if ( ! $vendor_id ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $type   = sanitize_key( $_POST['lt_provider_type'] ?? 'shippo' );
        $key    = sanitize_text_field( $_POST['lt_api_key'] ?? '' );
        $secret = sanitize_text_field( $_POST['lt_ss_secret'] ?? '' );

        if ( $type === 'shipstation' ) {
            $key = sanitize_text_field( $_POST['lt_ss_key'] ?? '' );
        }

        if ( ! $key ) {
            wp_send_json_error( 'API key is required.' );
        }

        // Validate credentials BEFORE saving — avoids a race window where another
        // request could read invalid credentials between save() and validate().
        $valid = self::validate_raw( $type, $key, $secret );
        if ( is_wp_error( $valid ) ) {
            wp_send_json_error( 'Credentials invalid: ' . $valid->get_error_message() );
        }

        self::save( $vendor_id, $type, $key, $secret );

        wp_send_json_success( [ 'type' => strtoupper( $type ) ] );
    }

    public static function ajax_remove(): void {
        check_ajax_referer( 'lt_vendor_creds', 'nonce' );

        $vendor_id = (int) dokan_get_current_user_id();
        if ( ! $vendor_id ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        self::delete( $vendor_id );
        wp_send_json_success();
    }

    // -------------------------------------------------------------------------
    // Encryption helpers
    // -------------------------------------------------------------------------

    private static function encrypt( string $plaintext ): string {
        $key = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
        $iv  = random_bytes( 16 ); // cryptographically secure; replaces deprecated openssl_random_pseudo_bytes
        $enc = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        return base64_encode( $iv . $enc );
    }

    private static function decrypt( string $ciphertext ): string|false {
        $raw = base64_decode( $ciphertext, true );
        if ( ! $raw || strlen( $raw ) < 17 ) {
            return false;
        }
        $key = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
        $iv  = substr( $raw, 0, 16 );
        $enc = substr( $raw, 16 );
        return openssl_decrypt( $enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
    }
}

LT_Vendor_Credentials::init();
