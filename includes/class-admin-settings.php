<?php
/**
 * Admin settings page: Shippo API key, default from-address, default parcel dimensions.
 */

defined( 'ABSPATH' ) || exit;

class LT_Admin_Settings {

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_notices', [ __CLASS__, 'maybe_show_setup_notice' ] );
    }

    public static function add_menu() {
        add_submenu_page(
            'woocommerce',
            'Loothtool Shipping Settings',
            'Loothtool Shipping',
            'manage_woocommerce',
            'lt-shippo-settings',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function register_settings() {
        $options = [
            'lt_shippo_provider',     // 'shippo' | 'shipstation'
            'lt_shippo_api_key',
            'lt_ss_api_key',
            'lt_ss_api_secret',
            'lt_shippo_from_address', // array
            'lt_shippo_default_weight',
            'lt_shippo_default_length',
            'lt_shippo_default_width',
            'lt_shippo_default_height',
            'lt_shippo_label_format',
            'lt_shippo_label_markup_pct',
        ];

        foreach ( $options as $key ) {
            register_setting( 'lt_shippo_settings', $key );
        }
    }

    public static function render_page() {
        $provider    = get_option( 'lt_shippo_provider', 'shippo' );
        $api_key     = get_option( 'lt_shippo_api_key', '' );
        $ss_key      = get_option( 'lt_ss_api_key', '' );
        $ss_secret   = get_option( 'lt_ss_api_secret', '' );
        $from        = get_option( 'lt_shippo_from_address', [] );
        $label_fmt   = get_option( 'lt_shippo_label_format', 'PDF' );
        $markup_pct  = get_option( 'lt_shippo_label_markup_pct', 0 );
        $platform_ok = ( $provider === 'shippo' && $api_key ) || ( $provider === 'shipstation' && $ss_key && $ss_secret );
        ?>
        <div class="wrap">
            <h1>Loothtool Shipping Settings</h1>

            <?php if ( ! $platform_ok ) : ?>
                <div class="notice notice-warning">
                    <p><strong>Configure your platform shipping provider below to enable live rates.</strong></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'lt_shippo_settings' ); ?>

                <h2>Platform Shipping Provider</h2>
                <p>This is the account the platform uses when vendors don't have their own connected. Vendors can connect their own Shippo or ShipStation account from their dashboard.</p>
                <table class="form-table">
                    <tr>
                        <th>Provider</th>
                        <td>
                            <label style="margin-right:20px;">
                                <input type="radio" name="lt_shippo_provider" value="shippo"
                                       <?php checked( $provider, 'shippo' ); ?> id="lt-provider-shippo">
                                <strong>Shippo</strong> — best for rate shopping across all carriers
                            </label>
                            <label>
                                <input type="radio" name="lt_shippo_provider" value="shipstation"
                                       <?php checked( $provider, 'shipstation' ); ?> id="lt-provider-ss">
                                <strong>ShipStation</strong> — best if you already manage orders there
                            </label>
                        </td>
                    </tr>
                </table>

                <div id="lt-admin-shippo-fields"<?php if ( $provider !== 'shippo' ) echo ' style="display:none"'; ?>>
                    <h3>Shippo</h3>
                    <table class="form-table">
                        <tr>
                            <th>Shippo API Key</th>
                            <td>
                                <input type="text" name="lt_shippo_api_key"
                                       value="<?php echo esc_attr( $api_key ); ?>"
                                       class="regular-text" placeholder="shippo_live_xxxxxxxx" />
                                <p class="description">
                                    Found in your <a href="https://app.goshippo.com/settings/api" target="_blank">Shippo dashboard → API</a>.
                                    Use the <strong>live</strong> token for production; test token for dev.
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div id="lt-admin-ss-fields"<?php if ( $provider !== 'shipstation' ) echo ' style="display:none"'; ?>>
                    <h3>ShipStation</h3>
                    <table class="form-table">
                        <tr>
                            <th>ShipStation API Key</th>
                            <td>
                                <input type="text" name="lt_ss_api_key"
                                       value="<?php echo esc_attr( $ss_key ); ?>"
                                       class="regular-text" placeholder="API Key" />
                            </td>
                        </tr>
                        <tr>
                            <th>ShipStation API Secret</th>
                            <td>
                                <input type="password" name="lt_ss_api_secret"
                                       value="<?php echo esc_attr( $ss_secret ); ?>"
                                       class="regular-text" placeholder="API Secret" />
                                <p class="description">
                                    Found in ShipStation → Account Settings → API Keys. Both key and secret are required.
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <script>
                document.querySelectorAll('input[name="lt_shippo_provider"]').forEach(function(radio) {
                    radio.addEventListener('change', function() {
                        document.getElementById('lt-admin-shippo-fields').style.display = this.value === 'shippo' ? '' : 'none';
                        document.getElementById('lt-admin-ss-fields').style.display = this.value === 'shipstation' ? '' : 'none';
                    });
                });
                </script>

                <h2>Label Options</h2>
                <table class="form-table">
                    <tr>
                        <th>Default Label Format</th>
                        <td>
                            <?php
                            $formats = [
                                'PDF'      => 'Full Page PDF (8.5&Prime; &times; 11&Prime;) — no label printer needed, print on regular paper',
                                'PDF_4x6'  => '4&Prime; &times; 6&Prime; PDF — for label printers (Rollo, Dymo 4XL, etc.)',
                                'ZPLII'    => 'ZPL / Thermal — for Zebra and compatible thermal printers',
                            ];
                            foreach ( $formats as $val => $desc ) : ?>
                                <label style="display:block;margin-bottom:6px;">
                                    <input type="radio" name="lt_shippo_label_format"
                                           value="<?php echo esc_attr( $val ); ?>"
                                           <?php checked( $label_fmt, $val ); ?>>
                                    <?php echo $desc; ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description" style="margin-top:6px;">
                                Vendors can override this with their own preference from their Shipping Labels dashboard.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>Platform Markup on Labels</th>
                        <td>
                            <input type="number" step="0.1" min="0" max="100"
                                   name="lt_shippo_label_markup_pct"
                                   value="<?php echo esc_attr( $markup_pct ); ?>"
                                   style="width:70px" /> %
                            <p class="description">
                                Added on top of the label cost when deducting from the vendor's Dokan balance (platform account only — no markup when vendor uses their own account).
                                E.g. <strong>10</strong> means a $5.00 label costs the vendor $5.50 — you keep the $0.50.
                                Set to <strong>0</strong> to pass costs through at no markup.
                            </p>
                        </td>
                    </tr>
                </table>

                <h2>Default Ship-From Address <small>(used when a vendor hasn't set their store address)</small></h2>
                <table class="form-table">
                    <?php
                    $fields = [
                        'name'    => 'Name / Business Name',
                        'street1' => 'Street Address',
                        'city'    => 'City',
                        'state'   => 'State (2-letter)',
                        'zip'     => 'ZIP Code',
                        'country' => 'Country (2-letter)',
                        'phone'   => 'Phone',
                        'email'   => 'Email',
                    ];
                    foreach ( $fields as $key => $label ) :
                        $val = $from[ $key ] ?? '';
                    ?>
                    <tr>
                        <th><?php echo esc_html( $label ); ?></th>
                        <td>
                            <input type="text"
                                   name="lt_shippo_from_address[<?php echo esc_attr( $key ); ?>]"
                                   value="<?php echo esc_attr( $val ); ?>"
                                   class="regular-text" />
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>

                <h2>Default Parcel Dimensions <small>(used when products have no weight/dimensions set)</small></h2>
                <table class="form-table">
                    <?php
                    $dim_fields = [
                        'lt_shippo_default_weight' => [ 'Weight', 'lb' ],
                        'lt_shippo_default_length' => [ 'Length', 'in' ],
                        'lt_shippo_default_width'  => [ 'Width',  'in' ],
                        'lt_shippo_default_height' => [ 'Height', 'in' ],
                    ];
                    foreach ( $dim_fields as $opt => [ $lbl, $unit ] ) :
                    ?>
                    <tr>
                        <th><?php echo esc_html( $lbl ); ?></th>
                        <td>
                            <input type="number" step="0.01" min="0"
                                   name="<?php echo $opt; ?>"
                                   value="<?php echo esc_attr( get_option( $opt, 1 ) ); ?>"
                                   style="width:80px" /> <?php echo $unit; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>

                <?php submit_button( 'Save Settings' ); ?>
            </form>
        </div>
        <?php
    }

    public static function maybe_show_setup_notice() {
        $screen = get_current_screen();
        if ( $screen && $screen->id === 'woocommerce_page_lt-shippo-settings' ) {
            return; // Already on our page.
        }

        if ( ! get_option( 'lt_shippo_api_key' ) && current_user_can( 'manage_woocommerce' ) ) {
            $url = admin_url( 'admin.php?page=lt-shippo-settings' );
            echo '<div class="notice notice-info is-dismissible"><p>';
            echo 'Loothtool Shipping is active but needs a Shippo API key. ';
            echo '<a href="' . esc_url( $url ) . '">Configure now</a>.';
            echo '</p></div>';
        }
    }
}

LT_Admin_Settings::init();
