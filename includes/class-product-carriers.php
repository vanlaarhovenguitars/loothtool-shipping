<?php
/**
 * Per-product carrier restrictions.
 *
 * Adds carrier checkboxes to:
 *   - The Dokan vendor product editor (front-end)
 *   - The WooCommerce admin product editor
 *
 * Saved as post meta `_lt_product_carriers` (JSON array of carrier names).
 * Empty array = no restriction (fall back to vendor-level setting).
 *
 * At shipping calculation time, product-level carriers are intersected with
 * vendor-level carriers. Mixed packages use the most restrictive intersection.
 */

defined( 'ABSPATH' ) || exit;

class LT_Product_Carriers {

    private static $known_carriers = [ 'USPS', 'UPS', 'FedEx', 'DHL Express', 'Canada Post', 'Australia Post' ];

    public static function init() {
        // ── Dokan vendor product editor ──────────────────────────────────────
        add_action( 'dokan_product_edit_after_main',    [ __CLASS__, 'render_vendor_field' ], 11, 2 );
        add_action( 'dokan_process_product_meta',       [ __CLASS__, 'save_vendor_field' ],   10, 1 );
        add_action( 'dokan_new_product_added',          [ __CLASS__, 'save_vendor_field' ],   10, 1 );

        // ── WC admin product editor ───────────────────────────────────────────
        add_action( 'woocommerce_product_options_shipping', [ __CLASS__, 'render_admin_field' ] );
        add_action( 'woocommerce_process_product_meta',     [ __CLASS__, 'save_admin_field' ] );
    }

    // -------------------------------------------------------------------------
    // Dokan front-end
    // -------------------------------------------------------------------------

    public static function render_vendor_field( $post, $post_id ) {
        $carriers = self::get_product_carriers( $post_id );
        ?>
        <div class="dokan-form-group" style="margin-top:16px;">
            <label class="dokan-form-label">
                <?php esc_html_e( 'Shipping Carriers', 'loothtool-shipping' ); ?>
            </label>
            <p class="description" style="margin-bottom:6px;font-size:0.85em;color:#777;">
                <?php esc_html_e( 'Restrict which carriers can ship this product. Leave all unchecked to use your default shipping settings.', 'loothtool-shipping' ); ?>
            </p>
            <?php foreach ( self::$known_carriers as $c ) : ?>
                <label style="display:inline-block;margin-right:16px;margin-bottom:6px;">
                    <input type="checkbox" name="lt_product_carriers[]" value="<?php echo esc_attr( $c ); ?>"
                           <?php checked( in_array( $c, $carriers, true ) ); ?>>
                    <?php echo esc_html( $c ); ?>
                </label>
            <?php endforeach; ?>
        </div>
        <?php
    }

    public static function save_vendor_field( $post_id ) {
        // phpcs:ignore WordPress.Security.NonceVerification -- Dokan handles the nonce.
        $raw = isset( $_POST['lt_product_carriers'] ) ? array_map( 'sanitize_text_field', (array) $_POST['lt_product_carriers'] ) : [];
        $valid = array_values( array_intersect( $raw, self::$known_carriers ) );

        if ( ! empty( $valid ) ) {
            update_post_meta( $post_id, '_lt_product_carriers', wp_json_encode( $valid ) );
        } else {
            delete_post_meta( $post_id, '_lt_product_carriers' );
        }
    }

    // -------------------------------------------------------------------------
    // WC admin product editor
    // -------------------------------------------------------------------------

    public static function render_admin_field() {
        global $post;
        $carriers = self::get_product_carriers( $post->ID );
        ?>
        <div class="options_group">
            <p class="form-field">
                <label><?php esc_html_e( 'Carrier Restrictions', 'loothtool-shipping' ); ?></label>
                <span class="description" style="display:block;margin-bottom:6px;">
                    <?php esc_html_e( 'Restrict which carriers can ship this product. Leave all unchecked to use vendor defaults.', 'loothtool-shipping' ); ?>
                </span>
                <?php foreach ( self::$known_carriers as $c ) : ?>
                    <label style="display:inline-block;margin-right:14px;">
                        <input type="checkbox" name="lt_product_carriers[]" value="<?php echo esc_attr( $c ); ?>"
                               <?php checked( in_array( $c, $carriers, true ) ); ?>>
                        <?php echo esc_html( $c ); ?>
                    </label>
                <?php endforeach; ?>
            </p>
        </div>
        <?php
    }

    public static function save_admin_field( $post_id ) {
        // phpcs:ignore WordPress.Security.NonceVerification -- WC handles the nonce.
        $raw = isset( $_POST['lt_product_carriers'] ) ? array_map( 'sanitize_text_field', (array) $_POST['lt_product_carriers'] ) : [];
        $valid = array_values( array_intersect( $raw, self::$known_carriers ) );

        if ( ! empty( $valid ) ) {
            update_post_meta( $post_id, '_lt_product_carriers', wp_json_encode( $valid ) );
        } else {
            delete_post_meta( $post_id, '_lt_product_carriers' );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Get the carrier restriction array for a product.
     *
     * @return string[] Carrier names, or empty array (= no restriction).
     */
    public static function get_product_carriers( int $product_id ): array {
        $raw = get_post_meta( $product_id, '_lt_product_carriers', true );
        if ( ! $raw ) {
            return [];
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * Compute the effective allowed carriers for a package.
     *
     * Intersects each product's carrier restriction. Products with no
     * restriction are ignored. If ALL products are unrestricted, returns
     * an empty array (= no product-level filtering).
     *
     * @param array $package WooCommerce shipping package.
     * @return string[] Allowed carriers, or empty array.
     */
    public static function get_package_carriers( array $package ): array {
        $result = null; // null = not yet initialized

        foreach ( $package['contents'] as $item ) {
            $product_id = $item['product_id'];
            $product_carriers = self::get_product_carriers( $product_id );

            if ( empty( $product_carriers ) ) {
                continue; // This product has no restriction.
            }

            if ( $result === null ) {
                $result = $product_carriers;
            } else {
                $result = array_values( array_intersect( $result, $product_carriers ) );
            }
        }

        return $result ?? [];
    }
}

LT_Product_Carriers::init();
