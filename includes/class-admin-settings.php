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
            'Shippo Shipping Settings',
            'Shippo Shipping',
            'manage_woocommerce',
            'lt-shippo-settings',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function register_settings() {
        $options = [
            'lt_shippo_api_key',
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
        $api_key     = get_option( 'lt_shippo_api_key', '' );
        $from        = get_option( 'lt_shippo_from_address', [] );
        $label_fmt   = get_option( 'lt_shippo_label_format', 'PDF' );
        $markup_pct  = get_option( 'lt_shippo_label_markup_pct', 0 );
        ?>
        <div class="wrap">
            <h1>Shippo Shipping Settings</h1>

            <?php if ( ! $api_key ) : ?>
                <div class="notice notice-warning">
                    <p><strong>Enter your Shippo API key below to enable live rates.</strong>
                    Get one free at <a href="https://goshippo.com" target="_blank">goshippo.com</a>.</p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'lt_shippo_settings' ); ?>

                <h2>API</h2>
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
                    <tr>
                        <th>Label Format</th>
                        <td>
                            <select name="lt_shippo_label_format">
                                <?php foreach ( [ 'PDF', 'PNG', 'ZPLII' ] as $fmt ) : ?>
                                    <option value="<?php echo $fmt; ?>" <?php selected( $label_fmt, $fmt ); ?>><?php echo $fmt; ?></option>
                                <?php endforeach; ?>
                            </select>
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
                                Added on top of the Shippo label cost when deducting from the vendor's balance.
                                E.g. <strong>10</strong> means a $5.00 label costs the vendor $5.50 — you keep the $0.50.
                                Set to <strong>0</strong> to pass cost through at no markup.
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
                                   name="lt_shippo_from_address[<?php echo $key; ?>]"
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
