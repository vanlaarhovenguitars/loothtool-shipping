<?php
/**
 * Per-product YouTube video field.
 *
 * Adds a YouTube URL field to:
 *   - The Dokan vendor product editor (front-end)
 *   - The WooCommerce admin product editor
 *
 * Saved as post meta `_wc_video_url`, which the single-product.php
 * template already reads and embeds.
 */

defined( 'ABSPATH' ) || exit;

class LT_Product_Video {

    public static function init() {
        // ── Dokan vendor product editor ──────────────────────────────────────
        add_action( 'dokan_product_edit_after_main',    [ __CLASS__, 'render_vendor_field' ], 10, 2 );
        add_action( 'dokan_process_product_meta',       [ __CLASS__, 'save_vendor_field' ],   10, 2 );
        add_action( 'dokan_new_product_added',          [ __CLASS__, 'save_vendor_field' ],   10, 2 );

        // ── WC admin product editor ───────────────────────────────────────────
        add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'render_admin_field' ] );
        add_action( 'woocommerce_process_product_meta',                 [ __CLASS__, 'save_admin_field' ] );
    }

    // -------------------------------------------------------------------------
    // Dokan front-end
    // -------------------------------------------------------------------------

    public static function render_vendor_field( $post, $post_id ) {
        $value = get_post_meta( $post_id, '_wc_video_url', true );
        ?>
        <div class="dokan-form-group" style="margin-top:16px;">
            <label class="dokan-form-label" for="lt_product_video_url">
                <?php esc_html_e( 'Product Video (YouTube URL)', 'loothtool-shipping' ); ?>
            </label>
            <input type="url"
                   id="lt_product_video_url"
                   name="lt_product_video_url"
                   class="dokan-form-control"
                   value="<?php echo esc_attr( $value ); ?>"
                   placeholder="https://www.youtube.com/watch?v=…">
            <p class="description" style="margin-top:4px;font-size:0.85em;color:#777;">
                <?php esc_html_e( 'Optional. Shown on the product page below the description.', 'loothtool-shipping' ); ?>
            </p>
        </div>
        <?php
    }

    public static function save_vendor_field( $post_id, $data ) {
        // $data is the $_POST array passed by Dokan.
        $url = isset( $data['lt_product_video_url'] )
            ? esc_url_raw( wp_unslash( $data['lt_product_video_url'] ) )
            : '';

        // Only accept YouTube / youtu.be URLs; clear the meta if invalid.
        if ( $url && ! preg_match( '/(?:youtube\.com|youtu\.be)/', $url ) ) {
            $url = '';
        }

        if ( $url ) {
            update_post_meta( $post_id, '_wc_video_url', $url );
        } else {
            delete_post_meta( $post_id, '_wc_video_url' );
        }
    }

    // -------------------------------------------------------------------------
    // WC admin product editor
    // -------------------------------------------------------------------------

    public static function render_admin_field() {
        global $post;
        $value = get_post_meta( $post->ID, '_wc_video_url', true );
        woocommerce_wp_text_input( [
            'id'          => '_wc_video_url',
            'label'       => __( 'Product Video (YouTube URL)', 'loothtool-shipping' ),
            'desc_tip'    => true,
            'description' => __( 'Optional YouTube URL shown on the product page.', 'loothtool-shipping' ),
            'value'       => $value,
            'placeholder' => 'https://www.youtube.com/watch?v=…',
            'type'        => 'url',
        ] );
    }

    public static function save_admin_field( $post_id ) {
        $url = isset( $_POST['_wc_video_url'] )
            ? esc_url_raw( wp_unslash( $_POST['_wc_video_url'] ) )
            : '';

        if ( $url && ! preg_match( '/(?:youtube\.com|youtu\.be)/', $url ) ) {
            $url = '';
        }

        if ( $url ) {
            update_post_meta( $post_id, '_wc_video_url', $url );
        } else {
            delete_post_meta( $post_id, '_wc_video_url' );
        }
    }
}

LT_Product_Video::init();
