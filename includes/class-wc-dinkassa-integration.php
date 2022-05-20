<?php
/**
 * DinKassa Integration.
 *
 * @package  WC_DinKassa_Integration
 * @category Integration
 * @author   Tom Boye
 */
defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'WC_DinKassa_Integration' ) ) :
    class WC_DinKassa_Integration extends WC_Integration {

        private $eskassasystem_logo;

        /**
         * Init and hook in the integration.
         */
        public function __construct() {
            $this->id                 = 'dinkassa-integration';
            $this->method_title       = __( 'Dinkassa.se for WooCommerce', 'woocommerce-dinkassa-integration' );
            $this->method_description = __( 'Enter data needed to integrate WooCommerce with DinKassa ', 'woocommerce-dinkassa-integration' );

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Actions.
            add_action( 'woocommerce_update_options_integration_' .  $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_update_options_integration_' .  $this->id, array( $this, 'process_dinkassa_options' ), 100 );
            add_action( 'woocommerce_settings_save_' . $this->id, '');

            // Filters.
            add_filter( 'woocommerce_settings_api_sanitized_fields_' . $this->id, array( $this, 'sanitize_settings' ) );
            
            // Set the path to ES-Kassasystem-Logo
            $this->eskassasystem_logo = $this->get_dir_url().'/eskassasystem-logo.png';
        }

        /**
         * Initialize integration settings form fields.
         *
         * @return void
         */
        public function init_form_fields() {
            $this->form_fields = array(
                'machine_key' => array(
                    'title'             => __( 'Machine Key', 'woocommerce-dinkassa-integration' ),
                    'type'              => 'text',
                    'css'               => 'height: 30px;',
                    'class'             => 'machine_key',
                    'description'       => __( 'Each cash register machine has a unique, secret key associated with a specific machine ID.', 'woocommerce-dinkassa-integration' ),
                    'desc_tip'          => true,
 /*                   'custom_attributes' => array(
                          'pattern' => "\s*[0-9a-fA-F]{32}\s*",
                          'title' => 'Machine key must be a 32-digit hexadecimal number '
                    ), // Machine-key doesn't necessarily have the same format as the other keys */
                    'default'           => ''
                ),
				'machine_id' => array(
                    'title'             => __( 'Machine ID', 'woocommerce-dinkassa-integration' ),
                    'type'              => 'text',
                    'css'               => 'height: 30px;',
                    'class'             => 'machine_id',
                    'custom_attributes' => array(
                          'pattern' => "\s*[0-9a-fA-F]{8}(-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\s*",
                          'title' => 'The machine ID must have the format xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxx'
                    ),
                    'description'       => __( 'Determines which machine the API can read data from or write data to.', 'woocommerce-dinkassa-integration'),
                    'desc_tip'          => true,
                    'default'           => ''
                ),
               'integrator_id' => array(
                    'title'             => __( 'Integrator ID', 'woocommerce-dinkassa-integration' ),
                    'type'              => 'text',
                    'css'               => 'height: 30px;',
                    'class'             => 'integrator_id',
                    'custom_attributes' => array(
                          'pattern' => "\s*[0-9a-fA-F]{8}(-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\s*",
                          'title' => 'The integrator ID must have the format xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxx'
                    ),
                    'description'       => __( 'A unique key representing a system of one or more machines that can connect to the API.', 'woocommerce-dinkassa-integration' ),
                    'desc_tip'          => true,
                    'default'           => ''
                ),
                'synchronize' => array(
                    'title'             => __( 'Synchronize', 'woocommerce-dinkassa-integration' ),
                    'type'              => 'checkbox',
                    'class'             => 'synch_checkbox',
                    'label'             => __( 'Synchronize products and categories', 'woocommerce-dinkassa-integration' ),
                    'default'           => 'no',
                    'description'       => __( 'Keep products and categories in WooCommerce and Dinkassa.se <br> synchronized.', 'woocommerce-dinkassa-integration' ),
                ),
                'synchronize_prices' => array(
                    'title'             => __( 'Synchronize prices', 'woocommerce-dinkassa-integration' ),
                    'type'              => 'checkbox',
                    'class'             => 'synch_price_checkbox',
                    'label'             => __( 'Synchronize prices', 'woocommerce-dinkassa-integration' ),
                    'default'           => 'yes',
                    'description'       => __( 'Keep prices in WooCommerce and Dinkassa.se synchronized.', 'woocommerce-dinkassa-integration' ),
                ),
                'synchronize_names'  => array(
                    'title'             => __( 'Synchronize product names', 'woocommerce-dinkassa-integration' ),
                    'type'              => 'checkbox',
                    'class'             => 'synch_name_checkbox',
                    'label'             => __( 'Synchronize product names', 'woocommerce-dinkassa-integration' ),
                    'default'           => 'yes',
                    'description'       => __( 'Keep product names in WooCommerce and Dinkassa.se synchronized.', 'woocommerce-dinkassa-integration' ),
                ),
                'delete_dinkassa_products'  => array(
                    'title'             => __( 'Delete Dinkassa.se products', 'woocommerce-dinkassa-integration' ),
                    'type'              => 'checkbox',
                    'class'             => 'delete_prod_checkbox',
                    'label'             => __( 'Delete Dinkassa.se products', 'woocommerce-dinkassa-integration' ),
                    'default'           => 'no',
                    'description'       => __( 'Products deleted in WooCommerce are also deleted in Dinkassa.se', 'woocommerce-dinkassa-integration' ),
                ),
                'log_wc_events' => array(
                    'title'             => __( 'Log WooCommerce events', 'woocommerce-dinkassa-integration' ),
                    'type'              => 'checkbox',
                    'class'             => 'log_events',
                    'label'             => __( 'Log WooCommerce events', 'woocommerce-dinkassa-integration' ),
                    'default'           => 'no',
                    'description'       => __( 'Log events such as purchases, updates, new products etc.', 'woocommerce-dinkassa-integration' ),
                ),
                'test_connection' => array(
                    'title'             => __( 'Test API-keys', 'woocommerce-dinkassa-integration' ),
                    'content'           => __( 'Connect', 'woocommerce-dinkassa-integration' ),
                    'type'              => 'button',
                    'css'               => 'height: 30px;',
                    'description'       => __( 'Check that the API-keys are valid by reading data from Dinkassa.se.', 'woocommerce-dinkassa-integration' ),
                    'desc_tip'          => true,
                )
            );
        }

        /**
         * Generate Button HTML.
         * @param string $key
         * @param array $data
         * @return false|string
         */
        public function generate_button_html( $key, $data ) {
            $field    = $this->plugin_id . $this->id . '_' . $key;
            $defaults = array(
                'class'             => 'button-secondary',
                'css'               => '',
                'custom_attributes' => array(),
                'desc_tip'          => false,
                'description'       => '',
                'title'             => '',
            );

            $data = wp_parse_args( $data, $defaults );

            ob_start();
            ?>
            <tr style="vertical-align: top">
                <th scope="row" class="titledesc">
                    <label for="<?php echo esc_attr( $field ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
                    <?php echo $this->get_tooltip_html( $data ); ?>
                </th>
                <td class="forminp">
                    <fieldset>
                        <legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
                        <button class="<?php echo esc_attr( $data['class'] ); ?>" type="button" name="<?php echo esc_attr( $field ); ?>" id="<?php echo esc_attr( $field ); ?>" style="<?php echo esc_attr( $data['css'] ); ?>" <?php echo $this->get_custom_attribute_html( $data ); ?>><?php echo wp_kses_post( $data['content'] ); ?></button>
                        <?php echo $this->get_description_html( $data ); ?>
                        <label for="status" style="padding-left: 25px;">Status: </label>
                        <input id="status" name="fname" type="text" style="width: 257px;height: 30px;" readonly/>
                    </fieldset>
                </td>
            </tr>
            <?php
            return ob_get_clean();
        }

        /**
         * Returns the the url-directory of this file.
         *
         * @param string $file
         * @return bool|string
         */
        private function get_dir_url( $file = __FILE__ ) {
            $file_path = str_replace( "\\", "/", str_replace( str_replace( "/", "\\", WP_CONTENT_DIR ), "", $file ) );
            if ( $file_path )
                return dirname(content_url( $file_path ));
            return false;
        }

        /**
         * Santize our settings
         * @param $settings
         * @return
         * @see process_admin_options()
         */
        public function sanitize_settings( $settings ) {
            return $settings;
        }

        private function dinkassa_settings_error($message)
        {
            WC_Admin_Settings::add_error(esc_html__($message, 'woocommerce-dinkassa-integration'));
        }

        function admin_options() {
            ?>
            <style>
                img {
                    height:auto;
                    width: 175px;
                    vertical-align: middle;
                    padding-left: 20px;
                }
            </style>
            <table height="100px">
                <tr>
                    <td>
                        <h2 style="vertical-align: middle;">
                            <?php _e('Integrate WooCommerce with Dinkassa.se','woocommerce'); ?>
                        </h2>
                    </td>
                   <td>
                       <img src="<?php echo $this->eskassasystem_logo; ?>" alt="">
                   </td>
                </tr>
            </table>
            <table class="form-table" id="dinkassa">
                <?php $this->generate_settings_html(); ?>
            </table> <?php
        }

        public function process_dinkassa_options()
        {
            $machine_id = $this->settings['machine_id'];
            $machine_key = $this->settings['machine_key'];
            $integrator_id = $this->settings['integrator_id'];
            $synchronize = $this->settings['synchronize'];
            $synch_prices = $this->settings['synchronize_prices'];
            $sync_names = $this->settings['synchronize_names'];
            $log_wc_events = $this->settings['log_wc_events'];
            $delete_dinkassa_products = $this->settings['delete_dinkassa_products'];
            update_option('machine_id', $machine_id);
            update_option('machine_key', $machine_key);
            update_option('integrator_id', $integrator_id);
            update_option('synchronize', $synchronize);
            update_option('synch_prices', $synch_prices);
            update_option('synch_desc', $sync_names);
            update_option('log_wc_events', $log_wc_events);
            update_option('delete_dinkassa_products', $delete_dinkassa_products);
        }
    }
endif;