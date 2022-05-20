<?php
/**
* Plugin Name: WooCommerce-ES Kassasystem Integration
* Plugin URI: https://github.com/tomberpato/WooCommerce
* Description: A plugin for integrating WooCommerce with the ES Kassasystem Dinkassa.se server.
* Version: 2.1.2
* Author: Tom Boye
* License: None
*/
defined( 'ABSPATH' ) || exit;
define('BLANKS', '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;');
define('META_KEY_PREFIX', '_custom_pf_');

/**
 * WP_Lock class.
 */
if ( ! class_exists( 'WP_Lock' ) ) {
    require_once dirname( __FILE__ ) . '/lib/class-wp-lock.php';
}

/**
 * WP_Lock_Backend class.
 */
if ( ! class_exists( 'WP_Lock_Backend' ) ) {
    require_once dirname( __FILE__ ) . '/lib/backend/class-wp-lock-backend.php';
}

foreach ( glob( dirname( __FILE__ ) . '/lib/backend/class-wp-lock-backend-*.php' ) as $backend_class_path ) {
    require_once $backend_class_path;
}

add_filter( 'wp_lock_backend', 'wp_lock_set_default_backend' );

/**
 * Set the default lock backend if null.
 *
 * Called via `wp_lock_backend`.
 *
 * @param WP_Lock_Backend|null $lock_backend The backend.
 *
 * @return WP_Lock_Backend A default lock backend.
 */
function wp_lock_set_default_backend( $lock_backend ) {
    if ( is_null( $lock_backend ) ) {
        return new WP_Lock_Backend_flock();
    }
}

if ( ! class_exists( 'WC_ESKassa_Integration' ) ) :

    class WC_ESKassa_Integration {

        /**
         * Construct the plugin.
         */
        public function __construct() {
            add_action( 'plugins_loaded', array( $this, 'init' ) );
        }

        /**
         * Initialize the plugin.
         */
        public function init() {

            // Checks if WooCommerce is installed.
            if ( class_exists( 'WC_Integration' ) ) {
                // Include our integration class.
                include_once 'includes/class-wc-dinkassa-integration.php';

                // Register the integration.
                add_filter( 'woocommerce_integrations', array( $this, 'add_integration' ) );
            } else {
                // throw an admin error if you like
            }
        }

        /**
         * Add a new integration to WooCommerce.
         */
        public function add_integration( $integrations ) {
            $integrations[] = 'WC_DinKassa_Integration';
            return $integrations;
        }

    }

    $WC_ESKassa_Integration = new WC_ESKassa_Integration(  );

endif;

/**
 * @var WP_Async_Request $async_http_request
 * @var WooCommerce_Background_Process $background_process
 * @var bool $synchronizing
 * @var WP_Lock $wp_lock
 */
$async_http_request = null;
$background_process = null;
$synchronizing = false;
$wp_lock = null;

/**
 * Sends an asynchronous HTTP request to Dinkassa.se REST API.
 *
 * @param string $request HTTP verb such as POST, PUT, DELETE etc.
 * @param string $json_data JSON-encoded string containing product/category data
 * @param string $controller Name of the Dinkassa.se API controller. inventoryitem/category
 * @param string[] $opt_headers Optional headers added to the 3 Dinkassa.se API keys
 * @param string $dinkassa_id Dinkassa Id of a product or category
 * @param int $post_id WordPress post_id/term_id of product and category resp.
 * @param string $event Name of an event e.g. 'stock-quantity-updated', 'product-updated' etc.
 * @param array|mixed $info Contains information about a deleted product/category
 */
function create_async_http_request(
        $request,
        $json_data,
        $controller,
        $opt_headers,
        $dinkassa_id,
        $post_id,
        $event,
        $info = null)
{
    global $async_http_request;

    if (isset($async_http_request)) {
        $data = array(
            'request' => $request,
            'controller' => $controller,
            'data' => urlencode($json_data),
            'opt_headers' => $opt_headers,
            'dinkassa_id' => $dinkassa_id,
            'post_id' => $post_id,
            'event' => $event,
            'info' => $info
        );
        $async_http_request->data($data);
        $async_http_request->dispatch();
    }
}

/**
 * Performs necessary initializations when plugins are loaded.
 */
add_action('plugins_loaded', 'plugins_loaded_integration', 10, 0);
function plugins_loaded_integration() {
    global $async_http_request,$background_process,$synchronizing,$wp_lock;
    require_once('async-request/woocommerce-async-request.php');
    require_once('background-process/woocommerce-background-process.php');
    require_once('lib/class-wp-lock.php');

    $synchronizing = get_option('synchronize') === 'yes';

    // WooCommerce_Async_Http_Request and WooCommerce_Background_Process
    // objects must be instantiated when the plugins are loaded.
    $async_http_request = new WooCommerce_Async_Http_Request();
    $background_process = new WooCommerce_Background_Process();
    $wp_lock = new WP_Lock("wp_resource_id");
}
add_action('woocommerce_new_order', 'update_dinkassa_product_inventory', 10, 2);
add_action('woocommerce_update_product', 'update_dinkassa_product', 10, 2);
add_action('edited_product_cat', 'update_dinkassa_category', 10, 1);
add_action('before_delete_post', 'delete_dinkassa_product', 10, 1);
//add_action('pre_delete_term', 'delete_dinkassa_product_category', 10, 4);
add_action('init', 'register_custom_taxonomy', 10, 0);
add_action('created_product_cat', 'create_dinkassa_product_category', 10, 2);

/**
 * Registers a taxonomy 'deleted_item' used for storing information about
 * deleted products and categories. The information is used when you need
 * to resend a failed delete product/category request to Dinkassa.se. It
 * is also used to remember which products have been deleted in WooCommerce
 * when the settings 'delete_dinkassa_products' on the integration page is
 * false. Remembering which WooCommerce products that have been deleted
 * prevents these products from being added during an update.
 */
function register_custom_taxonomy()
{
    if (! taxonomy_exists('deleted_item')) {
        register_taxonomy(
            'deleted_item',
            'WC_Deleted_Item',
            array(
                'hierarchical' => false,
                'public' => false,
                'show_ui' => false,
                'show_in_menu' => false,
                'show_in_nav_menus' => false,
                'show_in_rest' => false,
                'show_tagcloud' => false,
                'show_in_quick_edit' => false,
                'meta_box_cb' => false,
                'rewrite' => false
            )
        );
    }
}

class WC_Deleted_Item
{
    public $type; // Product/Category
    public $dinkassa_id;
}

function get_deleted_item_term_id()
{
    $term = term_exists('deleted_items', 'deleted_item');
    if (! $term)
    {
        // Create term 'deleted_items'.
        $term = wp_insert_term('deleted_items', 'deleted_item');
    }
    return $term['term_id'];
}

/**
 *
 * @param int $product_id
 * @param array $data
 */
function woocommerce_api_create_dinkassa_product($product_id, $data)
{
    $custom_fields = array(
        'id',
        'barcode',
        'barcode2',
        'barcode3',
        'barcode4',
        'barcode5',
        'productcode',
        'description',
        'externalproductcode',
        'pickuppriceincludingvat',
        'suppliername',
        'categoryname',
        'vatpercentage',
        'current_cat_term_id',
        'pending_crud',
        'quantity_change'
    );
    foreach ($custom_fields as $field_name)
    {
        $meta_key = META_KEY_PREFIX . $field_name;
        if (isset($data[$field_name]))
            $meta_value = sanitize_text_field($data[$field_name]);
        else
            $meta_value = '';
        update_post_meta($product_id, $meta_key, $meta_value);
    }
}
add_action('woocommerce_api_create_product', 'woocommerce_api_create_dinkassa_product', 10, 2);

function wc_api_create_product_data_filter($data, $wc_api_product)
{
    $product_data = array();
    foreach ($data as $key => $value)
    {
        $lower_case_key = strtolower($key);
        $product_data[$lower_case_key] = $value;
    }
    $product_data['title'] = $product_data['description'];
    $product_data['name'] = $product_data['title'];
    $product_data['regular_price'] = $product_data['priceincludingvat'];
    $product_data['managing_stock'] = true;
    $product_data['in_stock'] = true;
    $product_data['sold_individually'] = false;
    $product_data['backorders'] = false;
    if (isset($product_data['visibleonsalesmenu'])) {
        $visible_on_sales_menu = $product_data['visibleonsalesmenu'];
        $product_data['catalog_visibility'] = empty($visible_on_sales_menu) ? 'hidden' : 'visible';
        unset($product_data['visibleonsalesmenu']);
    }
    if (isset($product_data['quantityinstockcurrent']))
    {
        $product_data['stock_quantity'] = $product_data['quantityinstockcurrent'];
        unset($product_data['quantityinstockcurrent']);
    }
    unset($product_data['priceincludingvat']);
    $terms = get_terms(array(
        'taxonomy' => 'product_cat',
        'hide_empty' => false
    ));
    $category_exists = false;
    if (isset($product_data['categoryid']))
    {
        $main_category_id = $product_data['categoryid'];
        foreach ($terms as $category)
        {
            $term_id = $category->term_id;
            $category_id = get_term_meta($term_id, 'wh_meta_cat_id', true);
            if ($main_category_id == $category_id)
            {
                $product_data['categories'] = array($term_id);
                $product_data['current_cat_term_id'] = $term_id;
                if (! isset($product_data['categoryname']))
                    $product_data['categoryname'] = $category->name;
                else {
                    $category_name = $product_data['categoryname'];
                    if (strcasecmp($category_name, $category->name) != 0)
                    {
                        // Error. Category name/Category ID mismatch. Fix error
                        // by setting category name to the one that corresponds
                        // to the given category ID.
                        $product_data['categoryname'] = $category->name;
                    }
                }
                $category_exists = true;
                break;
            }
        }
        unset($product_data['categoryid']);
    }
    else if (isset($product_data['categoryname']))
    {
        $category_name = $product_data['categoryname'];
        foreach ($terms as $category)
        {
            if (strcasecmp($category_name, $category->name) == 0)
            {
                $category_exists = true;
                $product_data['categories'] = array($category->term_id);
                $product_data['current_cat_term_id'] = $category->term_id;
                break;
            }
        }
    }
    if (! $category_exists)
    {
        foreach ($terms as $category)
        {
            if (strcasecmp('Uncategorized', $category->name) == 0)
            {
                $product_data['categoryname'] = 'Uncategorized';
                $product_data['categories'] = array($category->term_id);
                $product_data['current_cat_term_id'] = $category->term_id;
                break;
            }
        }
    }
    $product_data['pending_crud'] = 0x1;
    $product_data['quantity_change'] = 0;
    $product_data['id'] = '';
    return $product_data;
}
add_filter('woocommerce_api_create_product_data', 'wc_api_create_product_data_filter', 10, 2);

function woocommerce_api_delete_product($product_id, $wc_api_product)
{
    $meta_key = META_KEY_PREFIX . 'id';
    $dinkassa_id = get_post_meta($product_id, $meta_key, true);
    if (! empty($dinkassa_id))
    {
        $deleted_item = new WC_Deleted_Item();
        $deleted_item->type = 'product';
        $deleted_item->dinkassa_id = $dinkassa_id;
        $term_id = get_deleted_item_term_id();
        add_term_meta($term_id, 'meta_deleted_item', $deleted_item);
    }
}
add_action('woocommerce_api_delete_product', 'woocommerce_api_delete_product', 10, 2);

/**
 * Called when a product category is created via the WooCommerce API.
 *
 * @param int $term_id
 * @param array $data
 * @return mixed
 */
function woocommerce_api_create_product_category($term_id, $data)
{
    if (empty($data))
        return new WP_Error('null value', __FUNCTION__ . ': $data is null');
    if (! isset($data['name']))
        return new WP_Error('null value', __FUNCTION__ . ': Category name is null');
    $term = get_term($term_id, 'product_cat');
    if (empty($term))
        return new WP_Error('null value', __FUNCTION__ . ': $term is null');
    if (is_wp_error($term))
        return $term;
    $account_number = $data['accountnumber'];
    $default_vat_percentage = $data['defaultvatpercentage'];
    $allow_only_categories = (bool)$data['onlyallowcategories'] ? 'on' : '';
    if (empty($default_vat_percentage))
        $default_vat_percentage = 0;
    if (! empty($data['parentcategoryid']))
    {
        $parent_category_id = $data['parentcategoryid'];
        // Set parent category of created category
        $terms = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false
        ));
        if (is_wp_error($terms))
            return $terms;
        $parent_cat_exists = false;
        foreach ($terms as $category)
        {
            $cat_term_id = $category->term_id;
            $category_id = get_term_meta($cat_term_id, 'wh_meta_cat_id', true);
            if ($category_id === $parent_category_id)
            {
                $parent_cat_exists = true;
                wp_update_term(
                    $term_id,
           'product_cat',
                    array(
                        'parent' => $cat_term_id
                    )
                );
                break;
            }
        }
        if (! $parent_cat_exists)
        {
            $error_msg = 'Parent category ID ' . $parent_category_id . 'doesn\'t exist';
            return new WP_Error('invalid category id', __FUNCTION__ . $error_msg);
        }
    }
    update_term_meta($term_id, 'wh_meta_account', $account_number);
    update_term_meta($term_id, 'wh_meta_only_cat', $allow_only_categories);
    update_term_meta($term_id, 'wh_meta_default_vat', $default_vat_percentage);
    update_term_meta($term_id, 'wh_meta_pending_crud', 0x1);
}
add_action('woocommerce_api_create_product_category', 'woocommerce_api_create_product_category', 10, 2);

add_filter('woocommerce_api_create_product_category_data', 'wc_api_product_category_data_filter', 10, 2);
function wc_api_product_category_data_filter($data, $wc_api_product)
{
    $category_data = array();
    foreach ($data as $key => $value)
    {
        $lower_case_key = strtolower($key);
        $category_data[$lower_case_key] = $value;
    }
    // Prevent firing of action hook 'create_dinkassa_product_category' prematurely
    remove_action('created_product_cat', 'create_dinkassa_product_category');
    return $category_data;
}

add_action('wp_login', 'wordpress_login_handler', 10, 2);
function wordpress_login_handler($user_name, $user)
{
    update_option('user_logged_in', 'yes');
}

add_action('wp_logout', 'wordpress_logout_handler');
function wordpress_logout_handler()
{
    global $background_process,$synchronizing;

    update_option('user_logged_in', 'no');
    if ($synchronizing && ! empty($background_process))
    {
        if ($background_process->is_queue_empty())
            $background_process->push_to_queue(1);
        $background_process->save()->dispatch();
    }
}

/**
 * Updates or creates a new product in Dinkassa.se using its REST API. This function
 * is called when a product has been edited or when a new one has been published.
 *
 * @param int $product_id
 * @param WC_Product_Simple $product
 */
function update_dinkassa_product($product_id, $product)
{
    $updating = woocommerce_save_product_meta_data($product_id);
    $json_data = create_product_payload($product, $updating);
    if (!is_wp_error($json_data)) {
        $opt_headers = array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json_data),
            'Accept: application/json'
        );
        if ($updating) {
            create_async_http_request(
                "PUT",
                $json_data,
                "inventoryitem",
                $opt_headers,
                null,
                $product_id,
                "product-updated");
        } else {
            create_async_http_request(
                "POST",
                $json_data,
                "inventoryitem",
                $opt_headers,
                null,
                $product_id,
                "product-created");
        }
    }
    // For technical reasons this action gets fired twice. This is bad when
    // a new product is being created in Dinkassa.se. Creating the same pro-
    // duct twice is an error. Removing the action solves this problem.
    remove_action('woocommerce_update_product', 'update_dinkassa_product');
}

/**
 * Updates the product inventory in Dinkassa.se using its REST API.
 * This function is called when a product has been ordered.
 *
 * @param int $order_id
 * @param WC_Order $order
 * @return mixed bool/WP_Error
 */
function update_dinkassa_product_inventory($order_id, $order)
{
    global $wp_lock;
    if (empty($order))
        return new WP_Error('null value', __FUNCTION__ . ': $order is null');
    $items = $order->get_items();
    // Prevent unnecessary extra update of Dinkassa.se inventory item.
    remove_action('woocommerce_update_product', 'update_dinkassa_product');
    foreach ($items as $item) {
        $product_id = $item->get_product_id('edit');
        $product = wc_get_product($product_id);
        if (empty($product))
            return new WP_Error('fcn failed', __FUNCTION__ . ': wc_get_product() failed');
        // Accumulate the number of items of this product that have
        // been purchased so far in case communication with Dinkassa.se
        // fails. This field is set to 0 upon successful update of
        // Dinkassa.se inventoryitem stock quantity. We need to lock
        // this piece of code to prevent the background process from
        // processing pending stock quantity updates simultaneously.
        $wp_lock->acquire();
        $quantity_change = $item->get_quantity();
        $meta_key = META_KEY_PREFIX . 'quantity_change';
        $prev_qty_change = (int)get_post_meta($product_id, $meta_key, true);
        update_post_meta($product_id, $meta_key, $quantity_change + $prev_qty_change);
        if ($prev_qty_change > 0)
            $wp_lock->release();
        else {
            // If $prev_qty_change > 0 then previous HTTP-request failed.
            // Update 'quantity_change' field and let the background process
            // handle the update of inventoryitem stock quantity.
            $meta_key = META_KEY_PREFIX . 'id';
            $dinkassa_id = get_post_meta($product_id, $meta_key, true);
            $current_quantity = $product->get_stock_quantity('edit');
            $new_quantity = $current_quantity - $quantity_change;
            $form_data = sprintf('quantityChange=%d&currentQuantity=%d&newQuantity=%d',
                $quantity_change,
                $current_quantity,
                $new_quantity);
            $opt_headers = array(
                'Content-Type: application/x-www-form-urlencoded',
                'Content-Length: ' . strlen($form_data),
                'Accept: application/json'
            );
            create_async_http_request(
                "POST",
                $form_data,
                "inventoryitem",
                $opt_headers,
                $dinkassa_id,
                $product_id,
                "stock-quantity-updated");
        }
    }
    return true;
}

/**
 * Creates a JSON-encoded string containing product category data that is
 * sent to Dinkassa.se.
 *
 * @param int $term_id Term id of category
 * @param bool $updating
 * @return mixed string or WP_Error
 */
function create_category_payload($term_id, $updating = false)
{
    $term = get_term($term_id, 'product_cat');
    if (is_wp_error($term))
        return $term;
    else if (! is_a($term,'WP_Term'))
        return new WP_Error('invalid data type',__FUNCTION__ . ': get_term() failed');
    else {
        $category_name = $term->name;
        $account_number = get_term_meta($term_id, 'wh_meta_account', true);
        $only_categories = get_term_meta($term_id, 'wh_meta_only_cat', true);
        $default_vat = get_term_meta($term_id, 'wh_meta_default_vat', true);
        $category_data = array(
            'Name' =>  $category_name,
            'AccountNumber' =>  $account_number,
            'OnlyAllowCategories' => $only_categories === 'on',
            'DefaultVatPercentage' => floatval($default_vat)
        );
        if ($updating) {
            $category_id = get_term_meta($term_id, 'wh_meta_cat_id', true);
            if (empty($category_id))
                return new WP_Error('null value',__FUNCTION__ . ': Category ID is null');
            $category_data['Id'] = $category_id;
        }
        if ($term->parent > 0) {
            // Get Id of parent category
            $parent_category_id = get_term_meta($term->parent, 'wh_meta_cat_id', true);
            if (empty($parent_category_id))
                return new WP_Error('null value',__FUNCTION__ . ': Parent category ID is null');
            $category_data['ParentCategoryId'] = $parent_category_id;
        }
        $item = array('Item' => $category_data);
        return json_encode($item);
    }
}

/**
 * Creates a new product category in Dinkassa.se.
 *
 * @param int $term_id
 * @param int $tt_id Term taxonomy id
 */
function create_dinkassa_product_category($term_id, $tt_id = 0)
{
    save_custom_category_fields($term_id);
    $json_data = create_category_payload($term_id);
    if (! is_wp_error($json_data)) {
        $opt_headers = array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json_data),
            'Accept: application/json'
        );
        create_async_http_request(
            'POST',
            $json_data,
            'category',
            $opt_headers,
            null,
            $term_id,
            'category-created');
    }
}

/**
 * Updates/creates custom product category fields.
 *
 * @param int $term_id
 */
function save_custom_category_fields($term_id)
{
    date_default_timezone_set('Europe/Stockholm'); // Fixed bug
    $current_datetime = date(DateTimeInterface::ATOM);
    $meta_account = $_POST['wh_meta_account'];
    $meta_only_cat = $_POST['wh_meta_only_cat'];
    $meta_default_vat = $_POST['wh_meta_default_vat'];
    update_term_meta($term_id, 'wh_meta_account', $meta_account);
    update_term_meta($term_id, 'wh_meta_only_cat', $meta_only_cat);
    update_term_meta($term_id, 'wh_meta_default_vat', $meta_default_vat);
    update_term_meta($term_id, 'wh_meta_modified_datetime', $current_datetime);
}

/**
 * Called when a product category is removed from the WP database. The category's
 * custom fields are also removed from the database. Afterwards, a DELETE request
 * is sent to Dinkassa.se using its REST API to remove the corresponding category.
 *
 * @param int $term_id
 * @param string $taxonomy The taxonomy of the term = 'product_cat'
 */
function delete_dinkassa_product_category($term_id, $taxonomy)
{
    if ($taxonomy === 'product_cat') {
        $category_info = null;
        $category_id = get_term_meta($term_id, 'wh_meta_cat_id', true);
        if (get_option('log_wc_events') === 'yes') {
            $category = get_term($term_id, 'product_cat');
            $category_info = array();
            $category_info['Id'] = $category_id;
            $category_info['Name'] = $category->name;
            $category_info = array('Item' => $category_info);
        }
        if (! empty($category_id)) {
            create_async_http_request(
                'DELETE',
                null,
                'category',
                null,
                $category_id,
                0,
                'category-deleted',
                $category_info);
        }
    }
}

/**
 * Adds input fields for VAT percentage and pickup price (inc. VAT)
 * on the product admin page
 */
add_action('woocommerce_product_options_pricing', 'woocommerce_product_pickup_price_field');
function woocommerce_product_pickup_price_field()
{
    woocommerce_wp_select(
        array(
            'desc_tip' => true,
            'id' => META_KEY_PREFIX . 'vatpercentage',
            'label' => __( 'VAT percentage', 'woocommerce' ),
            'options' => array(
                '0'  => __( '0%', 'woocommerce' ),
                '6'  => __( '6%', 'woocommerce' ),
                '12' => __( '12%', 'woocommerce' ),
                '25' => __( '25%', 'woocommerce' )
            ),
            'description' => 'The VAT percentage of the inventoryitem',
        )
    );
    woocommerce_wp_text_input(
        array(
            'id' => META_KEY_PREFIX . 'pickuppriceincludingvat',
            'label' => __('Pickup price (inc. VAT)', 'woocommerce'),
            'type' => 'number',
            'desc_tip' => true,
            'description' => 'Pickup price (takeaway) for the product including VAT',
            'custom_attributes' => array(
                'min' => '0'
            ),
        )
    );
}

/**
 * Returns an array of categories (WP_Term[]) that have $parent as parent
 * category. These categories are removed from the array $categories.
 *
 * @param WP_Term[] $categories
 * @param int $parent
 * @return WP_Term[] Array of categories whose parent category is $parent
 */
function get_subcategories($categories, $parent)
{
    $index = 0;
    $indexes = array();
    $subcategories = array();
    foreach ($categories as $category)
    {
        if ($category->parent == $parent) {
            $subcategories[] = $category;
            $indexes[] = $index;
        }
        $index++;
    }
    foreach ($indexes as $i)
    {
        // Remove all subcategories from $categories
        unset($categories[$i]);
    }
    return $subcategories;
}

/**
 * Creates an associative array of categories => subcategories recursively.
 * The subcategories are indented by an amount that depends on the depth of
 * the subcategories.
 *
 * @param WP_Term[] $parent_categories
 * @param WP_Term[] $allcategories
 * @param string $indent
 * @return array An associative array of (key => value) pairs
 */
function create_hierarchical_option_list($parent_categories, $allcategories, $indent = '')
{
    $option_list = array();
    foreach ($parent_categories as $parent_category)
    {
        $key = $parent_category->name;
        $value = $indent . $parent_category->name;
        $option_list = array_merge($option_list, array($key => $value));
        $subcategories = get_subcategories($allcategories, $parent_category->term_id);
        if (count($subcategories) > 0) {
            $hierarchical_list = create_hierarchical_option_list($subcategories, $allcategories, $indent . BLANKS);
            $option_list = array_merge($option_list, $hierarchical_list);
        }
    }
    return $option_list;
}

/**
 * @return array Returns a list of WooCommerce product categories
 * hierarchically arranged.
 */
function wc_get_category_options()
{
    $categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false
        ));
    if (is_wp_error($categories))
        return array();
    else {
        $top_level_categories = get_subcategories($categories, 0);
        return create_hierarchical_option_list($top_level_categories, $categories);
    }
}

add_action('woocommerce_product_options_general_product_data', 'woocommerce_product_custom_fields');
/**
 * Adds input fields for custom product fields on the product admin page.
 */
function woocommerce_product_custom_fields()
{
    echo '<div class="options_group">';
    woocommerce_wp_text_input(
        array(
            'id' => META_KEY_PREFIX . 'description',
            'label' => __('Description', 'woocommerce'),
            'custom_attributes' => array(
                'required' => 'required'
            ),
            'desc_tip' => true,
            'description' => 'Inventoryitem description'
        )
    );
    woocommerce_wp_text_input(
        array(
            'id' => META_KEY_PREFIX . 'id',
            'label' => __('Product Id', 'woocommerce'),
            'type' => 'number',
            'custom_attributes' => array(
                'readonly' => 'readonly'
            ),
            'desc_tip' => true,
            'description' => 'Unique 64-bit identification number of each inventoryitem'
        )
    );
    woocommerce_wp_select(
        array(
            'id' => META_KEY_PREFIX . 'categoryname',
            'label' => __('Category Name', 'woocommerce'),
            'options' => wc_get_category_options(),
            'desc_tip' => true,
            'description' => 'Main product category',
            'custom_attributes' => array(
                'required' => 'required'
            )
        )
    );
    woocommerce_wp_text_input(
        array(
            'desc_tip' => true,
            'id' => META_KEY_PREFIX . 'suppliername',
            'label' => __('Supplier Name', 'woocommerce'),
            'description' => 'The name of the supplier',
        )
    );
    woocommerce_wp_text_input(
        array(
            'id' => META_KEY_PREFIX . 'barcode',
            'label' => __('BarCode', 'woocommerce'),
            'desc_tip' => true,
            'description' => 'Unique product barcode. If the product doesn\'t 
                              have one; provide another unique value.',
            'custom_attributes' => array(
                'required' => 'required'
            )
        )
    );
    $barcode_numbers = array('2', '3', '4', '5');
    foreach ($barcode_numbers as $number) {
        $field_name = 'BarCode' . $number;
        $field_id = META_KEY_PREFIX . strtolower($field_name);
        woocommerce_wp_text_input(
            array(
                'id' => $field_id,
                'label' => __($field_name, 'woocommerce'),
                'desc_tip' => true,
                'description' => 'Represents an extra barcode connected to the same product.'
            )
        );
    }
    woocommerce_wp_text_input(
        array(
            'id' => META_KEY_PREFIX . 'productcode',
            'label' => __('Product Code', 'woocommerce'),
            'desc_tip' => true,
            'custom_attributes' => array(
                'required' => 'required'
            ),
            'description' => 'Unique product code. If no such code exists, provide another unique value.'
        )
    );
    woocommerce_wp_text_input(
        array(
            'desc_tip' => true,
            'id' => META_KEY_PREFIX . 'externalproductcode',
            'label' => __('External Product Code', 'woocommerce'),
            'description' => 'Unique product code. If no such code exists, provide another unique value.'
        )
    );
    woocommerce_wp_hidden_input(
        array(
            'id' => META_KEY_PREFIX . 'current_cat_term_id'
        )
    );
    woocommerce_wp_hidden_input(
        array(
            'id' => '_modified_custom_fields'
        )
    );
    woocommerce_wp_hidden_input(
        array(
            'id' => '_modified_builtin_fields'
        )
    );
    woocommerce_wp_hidden_input(
        array(
            'id' => '_synchronize_prices',
            'value' => get_option('synch_prices') === 'yes'
        )
    );
    woocommerce_wp_hidden_input(
        array(
            'id' => '_synchronize_description',
            'value' => get_option('synch_desc') === 'yes'
        )
    );
    echo '</div>';
}

/**
 * Saves custom product fields of a created/updated product.
 * The function returns true if the product is being updated,
 * false if it is new.
 *
 * @param int $post_id
 * @return bool
 */
function woocommerce_save_product_meta_data($post_id)
{
    $custom_field_names = array(
        'barcode',
        'barcode2',
        'barcode3',
        'barcode4',
        'barcode5',
        'productcode',
        'categoryname',
        'description',
        'externalproductcode',
        'pickuppriceincludingvat',
        'suppliername',
        'current_cat_term_id'
    );
    $updating = true;
    $wc_product = wc_get_product($post_id);
    // check if any custom fields have been modified
    if ($_POST['_modified_custom_fields']) {
        foreach ($custom_field_names as $field_name) {
            $meta_key = META_KEY_PREFIX . $field_name;
            $posted_value = $_POST[$meta_key];
            if (!empty($posted_value))
                $meta_value = sanitize_text_field($posted_value);
            else
                $meta_value = '';
            update_post_meta($post_id, $meta_key, esc_attr($meta_value));
        }
        // Bugfix: VAT% not saved correctly for 0%. Treat value as integer
        $meta_key = META_KEY_PREFIX . 'vatpercentage';
        update_post_meta($post_id, $meta_key, (int)$_POST[$meta_key]);
    }
    if (!empty($wc_product)) {
        // Check if product exists or is new
        $meta_key = META_KEY_PREFIX . 'id';
        $dinkassa_id = get_post_meta($post_id, $meta_key, true);
        if (! empty($dinkassa_id))
        {
            if (get_option('synch_prices') === 'yes')
            {
                // Synchronizing WC and Dinkassa.se prices. Save current price of product
                $meta_key = META_KEY_PREFIX . 'priceincludingvat';
                update_post_meta($post_id, $meta_key, $wc_product->get_regular_price());
            }
        }
        else {
            // New product
            $updating = false;
            $extra_custom_fields = array(
                'id' => '',
                'pending_crud' => 0,
                'quantity_change' => 0,
                'priceincludingvat' => $wc_product->get_regular_price()
            );
            foreach ($extra_custom_fields as $field_name => $value) {
                $meta_key = META_KEY_PREFIX . $field_name;
                add_post_meta($post_id, $meta_key, $value);
            }
            $current_quantity = $wc_product->get_stock_quantity('edit');
            $stock_status = $current_quantity > 0 ? 'instock' : 'outofstock';
            $wc_product->set_featured(true);
            $wc_product->set_manage_stock(true);
            $wc_product->set_stock_status($stock_status);
            $wc_product->set_reviews_allowed(true);
            $wc_product->set_backorders('no');
            $wc_product->set_sold_individually(true);
            // Set visibility to hidden until product has been assigned an
            // inventoryitem ID from Dinkassa.se.
            try {
                // Set visibility to hidden until product has been
                // assigned a dinkassa.se inventoryitem id
                $wc_product->set_catalog_visibility('hidden');
            } catch (WC_Data_Exception $e) {
            }
        }
    }
    return $updating;
}

/**
 * Checks if the product stock has changed. If it has, it updates the
 * stock quantity in Dinkassa.se.
 *
 * @param int $post_id Product ID
 */
function woocommerce_process_product_meta($post_id)
{
    $wc_product = wc_get_product($post_id);
    $modified_custom = $_POST['_modified_custom_fields'];
    $modified_builtin = $_POST['_modified_builtin_fields'];
    $unmodified = !$modified_builtin && !$modified_custom;
    if ($unmodified)
        remove_action('woocommerce_update_product', 'update_dinkassa_product');
    $meta_key = META_KEY_PREFIX . 'id';
    $dinkassa_id = get_post_meta($post_id, $meta_key, true);
    if (isset($_POST['_stock']) && !empty($dinkassa_id)) {
        // Check if stock quantity has changed. If it has,
        // update stock quantity in Dinkassa.se.
        $new_quantity = (int)$_POST['_stock'];
        $current_quantity = $wc_product->get_stock_quantity('edit');
        if ($new_quantity != $current_quantity) {
            global $wp_lock;

            $wp_lock->acquire();
            $meta_key = META_KEY_PREFIX . 'quantity_change';
            $prev_qty_change = (int)get_post_meta($post_id, $meta_key, true);
            $quantity_change = $current_quantity - $new_quantity + $prev_qty_change;
            $form_data = sprintf('quantityChange=%d&currentQuantity=%d&newQuantity=%d',
                $quantity_change,
                $current_quantity,
                $new_quantity);
            $opt_headers = array(
                'Content-Type: application/x-www-form-urlencoded',
                'Content-Length: ' . strlen($form_data),
                'Accept: application/json'
            );
            update_post_meta($post_id, $meta_key, $quantity_change);
            create_async_http_request(
                "POST",
                $form_data,
                "inventoryitem",
                $opt_headers,
                $dinkassa_id,
                $post_id,
                "stock-quantity-updated");
        }
    }
}
add_action('woocommerce_process_product_meta', 'woocommerce_process_product_meta', 10, 1);

/**
 * Creates a JSON-encoded string containing product data. This
 * function is called when a product has been created or edited.
 *
 * @param WC_Product_Simple $product
 * @param bool $updating
 * @return mixed JSON-encoded string with product data or WP_Error
 */
function create_product_payload($product, $updating)
{
    $product_data = array();
    $product_fields = array(
        'BarCode',
        'BarCode2',
        'BarCode3',
        'BarCode4',
        'BarCode5',
        'ProductCode',
        'Description',
        'PriceIncludingVat',
        'ExternalProductCode',
        'PickupPriceIncludingVat',
        'VatPercentage',
        'SupplierName'
    );
    $product_id = $product->get_id();
    foreach ($product_fields as $field)
    {
        $meta_key = META_KEY_PREFIX . strtolower($field);
        $meta_value = get_post_meta($product_id, $meta_key, true);
        $product_data[$field] = htmlspecialchars_decode($meta_value);
    }
    $product_data['VisibleOnSalesMenu'] = $product->is_visible();
    $category_ids = $product->get_category_ids('edit');
    if (! isset($category_ids))
        return new WP_Error('null value', __FUNCTION__ . ': WC Product category not set');
    else if (count($category_ids) == 1) {
        $term_id = $category_ids[0];
        $category_id = get_term_meta($term_id, 'wh_meta_cat_id', true);
        if (empty($category_id))
            return new WP_Error('null value', __FUNCTION__ . ': Dinkassa.se CategoryId is null');
        $product_data['CategoryId'] = $category_id;
    }
    else {
        // Extra category ids. (Not yet implemented in Dinkassa.se)
        $meta_key = META_KEY_PREFIX . 'current_cat_term_id';
        $term_id = get_post_meta($product_id, $meta_key, true);
        $category_id = get_term_meta($term_id, 'wh_meta_cat_id', true);
        if (empty($category_id))
            return new WP_Error('null value', __FUNCTION__ . ': Dinkassa.se CategoryId is null');
        $product_data['CategoryId'] = $category_id;
        $extra_category_ids = array();
        foreach ($category_ids as $cat_term_id)
        {
            if ($cat_term_id != $term_id)
                $extra_category_ids[] = get_term_meta($cat_term_id, 'wh_meta_cat_id', true);
        }
        $product_data['ExtraCategoryIds'] = $extra_category_ids;
    }
    if (! $updating)
        $product_data['QuantityInStockCurrent'] = $product->get_stock_quantity('edit');
    else {
        $meta_key = META_KEY_PREFIX . 'id';
        $dinkassa_id = get_post_meta($product_id, $meta_key, true);
        if (empty($dinkassa_id))
            return new WP_Error('null value', __FUNCTION__ . ': Product id is null');
        $product_data['Id'] = $dinkassa_id;
    }
    $item = array('Item' => $product_data);
    return json_encode($item);
}

/**
 * Returns an associative array containing product information.
 * The function is used to save information about a product before
 * it's deleted.
 *
 * @param int $product_id
 * @return array
 */
function get_product_info($product_id)
{
    if (get_option('log_wc_events') === 'no')
        return null;
    else {
        $product_info = array();
        $product = wc_get_product($product_id);
        if (!empty($product)) {
            $meta_key = META_KEY_PREFIX . 'id';
            $dinkassa_id = get_post_meta($product_id, $meta_key, true);
            $meta_key = META_KEY_PREFIX . 'categoryname';
            $category_name = get_post_meta($product_id, $meta_key, true);
            $product_info['Description'] = $product->get_name();
            $product_info['CategoryName'] = $category_name;
            $product_info['Id'] = $dinkassa_id;
        }
        return array('Item' => $product_info);
    }
}

/**
 * Deletes a product in Dinkassa.se if the setting 'delete_dinkassa_products' is true.
 * The product's custom fields are also removed from the WordPress database. If the
 * setting is false the product is not deleted in Dinkassa.se. Instead, the dinkassa_id
 * is stored in the database as a term_meta. WooCommerce needs to remember which products
 * that have been deleted in order to prevent them from being added as new products when
 * WooCommerce is updated.
 *
 * @param int $post_id
 */
function delete_dinkassa_product($post_id)
{
    $meta_key = META_KEY_PREFIX . 'id';
    $dinkassa_id = get_post_meta($post_id, $meta_key, true);
    if (! empty($dinkassa_id)) {
        $delete_dinkassa_products = get_option('delete_dinkassa_products');
        if ($delete_dinkassa_products === 'yes') {
            // Delete product in Dinkassa.se

            $product_info = get_product_info($post_id);
            create_async_http_request(
                "DELETE",
                null,
                "inventoryitem",
                null,
                $dinkassa_id,
                0,
                "product-deleted",
                $product_info);
        }
        else {
            // Don't delete the product in Dinkassa.se.
            // Save dinkassa_id in the database so that WooCommerce can
            // remember which products that have been deleted. This pre-
            // vents them from being added during the next update.
            $term_id = get_deleted_item_term_id();
            if (! deleted_item_exists($term_id, 'product', $dinkassa_id)) {
                $deleted_item = new WC_Deleted_Item();
                $deleted_item->type = 'product';
                $deleted_item->dinkassa_id = $dinkassa_id;
                add_term_meta($term_id, 'meta_deleted_item', $deleted_item);
            }
        }
    }
}

/**
 * Returns true if there exists an item in the list of WC_Deleted_Items
 * that matches $type and $dinkassa_id.
 *
 * @param int $term_id
 * @param string $type
 * @param string $dinkassa_id
 * @return bool
 */
function deleted_item_exists($term_id, $type, $dinkassa_id)
{
    /** @var WC_Deleted_Item[] $deleted_items */
    $deleted_items = get_term_meta($term_id, 'meta_deleted_item');
    foreach ($deleted_items as $item) {
        if ($item->type == $type && $item->dinkassa_id == $dinkassa_id)
            return true;
    }
    return false;
}

/**
 * Product Category Create page
 */
function woocommerce_taxonomy_add_new_meta_field() {
    ?>
    <div class="form-field">
        <label for="wh_meta_account"><?php _e('Account number', 'wh'); ?></label>
        <input type="number" name="wh_meta_account" id="wh_meta_account" min="0" max="9999">
        <p class="description"><?php _e('Account number used for accounting (up to 4 digits)', 'wh'); ?></p>
    </div>
    <div class="form-field">
        <label for="wh_meta_only_cat"><?php _e('Only allow categories', 'wh'); ?></label>
        <input type="checkbox" name="wh_meta_only_cat" id="wh_meta_only_cat">
        <p class="description"><?php _e('Checkbox in POS. Used as a "favorites" or parent category', 'wh'); ?></p>
    </div>
    <div class="form-field">
        <label for="wh_meta_default_vat"><?php _e('Default VAT percentage', 'wh'); ?></label>
        <select name="wh_meta_default_vat" id="wh_meta_default_vat" style="width: 75px">
            <option value="0">0%</option>
            <option value="6">6%</option>
            <option value="12">12%</option>
            <option value="25">25%</option>
        </select>
    </div>
    <?php
}

/**
 * Product category Edit page.
 *
 * @param WP_Term $term
 */
function woocommerce_taxonomy_edit_meta_field($term) {
    $term_id = $term->term_id;
    $wh_meta_account = get_term_meta($term_id, 'wh_meta_account', true);
    $wh_meta_only_cat = get_term_meta($term_id, 'wh_meta_only_cat', true);
    $wh_meta_default_vat = get_term_meta($term_id, 'wh_meta_default_vat', true);

    ?>
    <tr class="form-field">
        <th scope="row" style="vertical-align:top">
            <label for="wh_meta_account"><?php _e('Account number', 'wh'); ?></label>
        </th>
        <td>
            <input type="text" name="wh_meta_account" id="wh_meta_account" value="<?php echo esc_attr($wh_meta_account) ? esc_attr($wh_meta_account) : ''; ?>">
            <p class="description"><?php _e('Enter account number (up to 4 digits)', 'wh'); ?></p>
        </td>
    </tr>
    <tr class="form-field">
        <th scope="row" style="vertical-align:top">
            <label for="wh_meta_only_cat"><?php _e('Only allow categories', 'wh'); ?></label>
        </th>
        <td>
            <input type="checkbox" name="wh_meta_only_cat" id="wh_meta_only_cat" <?php if (esc_attr($wh_meta_only_cat) === 'on') echo 'checked'; ?>>
            <p class="description"><?php _e('Checkbox in POS. Used as a "favorites" or parent category', 'wh'); ?></p>
        </td>
    </tr>
    <tr class="form-field">
        <th scope="row" style="vertical-align:top">
            <label for="wh_meta_default_vat"><?php _e('Default VAT percentage', 'wh'); ?></label>
        </th>
        <td>
            <select name="wh_meta_default_vat" id="wh_meta_default_vat">
                <option value="0" <?php if (esc_attr($wh_meta_default_vat) === '0') echo 'selected'; ?>>0%</option>
                <option value="6" <?php if (esc_attr($wh_meta_default_vat) === '6') echo 'selected'; ?>>6%</option>
                <option value="12" <?php if (esc_attr($wh_meta_default_vat) === '12') echo 'selected'; ?>>12%</option>
                <option value="25" <?php if (esc_attr($wh_meta_default_vat) === '25') echo 'selected'; ?>>25%</option>
            </select>
            <p class="description"><?php _e('Select VAT percentage', 'wh'); ?></p>
        </td>
    </tr>
    <?php
}

add_action('product_cat_add_form_fields', 'woocommerce_taxonomy_add_new_meta_field', 10, 1);
add_action('product_cat_edit_form_fields', 'woocommerce_taxonomy_edit_meta_field', 10, 1);

// Save extra taxonomy fields callback function.
function update_dinkassa_category($term_id) {
    save_custom_category_fields($term_id);
    $json_data = create_category_payload($term_id, true);
    if (! is_wp_error($json_data)) {
        $opt_headers = array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json_data),
            'Accept: application/json'
        );
        create_async_http_request(
            'PUT',
            $json_data,
            'category',
            $opt_headers,
            null,
            $term_id,
            'category-updated');
    }
}

// Register custom field names in product category list on admin page
add_filter( 'manage_edit-product_cat_columns', 'woocommerce_register_custom_fields' );
function woocommerce_register_custom_fields($columns) {
    // Hide description and slug columns in product category list
    if (isset($columns['description']))
        unset($columns['description']);
    if (isset($columns['slug']))
        unset($columns['slug']);
    $columns['pro_meta_account'] = __( 'Account Number', 'woocommerce' );
    $columns['pro_meta_only_cat'] = __( 'Only Allow Categories', 'woocommerce' );
    $columns['pro_meta_default_vat'] = __( 'Default VAT %', 'woocommerce' );
    $columns['pro_meta_category_id'] = __( 'Id', 'woocommerce' );
    return $columns;
}

// Display custom field values in product category list on admin page
add_action( 'manage_product_cat_custom_column', 'woocommerce_display_custom_field_values' , 10, 3);
function woocommerce_display_custom_field_values($columns, $column, $id) {
    $term = get_term($id, 'product_cat');
    if (is_a($term, 'WP_Term') && $term->name === 'Uncategorized')
        return '';

    switch ($column)
    {
        case 'pro_meta_account':
            return esc_html(get_term_meta($id, 'wh_meta_account', true));

        case 'pro_meta_only_cat':
        {
            $checkbox_value = esc_html(get_term_meta($id, 'wh_meta_only_cat', true));
            return $checkbox_value === 'on' ? 'yes' : 'no';
        }

        case 'pro_meta_default_vat':
            return esc_html(get_term_meta($id, 'wh_meta_default_vat', true)) . '%';

        case 'pro_meta_category_id':
            return esc_html(get_term_meta($id, 'wh_meta_cat_id', true));
    }
    return $columns;
}

add_filter( 'manage_edit-product_columns', 'woocommerce_admin_products_visibility_column' );
function woocommerce_admin_products_visibility_column( $columns ){
    $columns['vatpercentage'] = 'VAT %';
    $columns['visibility'] = 'Visibility';
    $columns['suppliername'] = 'Supplier';
    $columns['barcode'] = 'Barcode';
    $columns['productcode'] = 'Productcode';
    $columns['id'] = 'Id';
    if (isset($columns['date']))
        unset($columns['date']);
    if (isset($columns['product_tag']))
        unset($columns['product_tag']);
    if (isset($columns['featured']))
        unset($columns['featured']);
    if (isset($columns['sku']))
        unset($columns['sku']);
    return $columns;
}

add_action( 'manage_product_posts_custom_column', 'display_admin_custom_product_fields', 10, 2 );

function display_admin_custom_product_fields( $column, $product_id ){
    switch ($column)
    {
        case 'visibility':
        {
            $product = wc_get_product( $product_id );
            echo $product->get_catalog_visibility();
        }
        break;

        case 'id':
        case 'barcode':
        case 'productcode':
        case 'suppliername':
        {
            $meta_key = META_KEY_PREFIX . $column;
            $meta_value = get_post_meta($product_id, $meta_key, true);
            echo esc_html($meta_value);
        }
        break;

        case 'vatpercentage':
        {
            $meta_key = META_KEY_PREFIX . 'vatpercentage';
            $meta_value = get_post_meta($product_id, $meta_key, true);
            echo (int)($meta_value) . '%';
        }
        break;
    }
}
if (is_admin()) {
    define('VERSION', '1.1');
    function version_id() {
        if ( WP_DEBUG )
            return time();
        return VERSION;
    }

    function get_inventoryitem()
    {
        $machine_id = $_POST['machineId'];
        $machine_key = $_POST['machineKey'];
        $integrator_id = $_POST['integratorId'];
        $url = DINKASSA_SE_API . '/inventoryitem?fetch=1';
        $handle = curl_init($url);
        if (curl_errno($handle) == 0) {
            $headers = array(
                'MachineId: ' . $machine_id,
                'MachineKey: ' .  $machine_key,
                'IntegratorId: ' . $integrator_id,
                'Accept: application/json'
            );
            $options = array(
                CURLOPT_CUSTOMREQUEST => "GET",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => is_ssl(),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30
            );
            if (curl_setopt_array($handle, $options)) {
                $response = curl_exec($handle);
                $http_code = curl_getinfo($handle, CURLINFO_HTTP_CODE);
                if ($http_code < 400){
                    curl_close($handle);
                    echo json_encode(array('status' => 'Ok'));
                    wp_die();
                }
            }
        }
        $http_code = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        echo json_encode(array('status' => 'Error: ' . $http_code));
        wp_die();
    }

// Action hooks for making ajax/javascript calls in WordPress
    add_action('wp_ajax_get_inventoryitem', 'get_inventoryitem');
    add_action('wp_ajax_nopriv_get_inventoryitem', 'get_inventoryitem');
    add_action('admin_enqueue_scripts', 'add_admin_scripts');
    function add_admin_scripts()
    {
        $src = plugin_dir_url(__FILE__) . 'js/admin-scripts.js';
        wp_enqueue_script('integration_scripts', $src, array('jquery'),  version_id());
        $variables = array(
            'ajaxurl' => admin_url('admin-ajax.php')
        );
        wp_localize_script('integration_scripts', 'wp_admin', $variables);
    }
}

// Remove quick edit from product admin
add_filter( 'post_row_actions', 'disable_quick_edit_product', 10, 2 );
add_filter( 'page_row_actions', 'disable_quick_edit_product', 10, 2 );
function disable_quick_edit_product( $actions = array(), $post = null ) {

    // Remove the Quick Edit link
    if ( isset( $actions['inline hide-if-no-js'] ) ) {
        unset( $actions['inline hide-if-no-js'] );
    }

    // Return the set of links without Quick Edit
    return $actions;

}

/***
 * @param WC_Product_Simple $wc_product
 * @param string $price_dink
 */
function woocommerce_print_debug($wc_product, $price_dink)
{
    $dir_path = plugin_dir_path( __FILE__ );
    if (! is_dir($dir_path . 'log'))
        mkdir($dir_path . 'log');
    $log_file = $dir_path . 'log/woocommerce-debug.txt';

}

/**
 * Prints out information about requests and responses in woocommerce-transaction-log.txt.
 *
 * @param string $event Type of event: product-purchased, product-created etc.
 * @param int $status HTTP status code of the response from Dinkassa.se
 * @param array $response An associative array containing the response from Dinkassa.se
 * @param bool $dinkassa_event Flag indicating whether an event occurred in Dinkassa.se
 */
function woocommerce_event_logger($event, $status, $response, $dinkassa_event = false)
{
    $dir_path = plugin_dir_path( __FILE__ );
    if (! is_dir($dir_path . 'log'))
        mkdir($dir_path . 'log');
    date_default_timezone_set('Europe/Stockholm');
    $log_file = $dir_path . 'log/woocommerce-event-log.txt';
    $date_time = date("Y-m-d H:i");
    $wc_event = add_padding($event, 26);
    $date_time = add_padding($date_time, 20);
    $http_info = $date_time . $wc_event . $status . "    ";
    if (empty($response))
        file_put_contents($log_file, $http_info . "\r\n", FILE_APPEND);
    else if ($status >= 400) {
        $error_msg = is_array($response)? $response['Message'] : $response;
        file_put_contents($log_file, $http_info . $error_msg . "\r\n", FILE_APPEND);
    }
    else if (! empty($response['Item']))
    {
        $item = $response['Item'];
        switch ($event)
        {
            case 'product-created':
            case 'product-updated':
            case 'product-deleted':
            case 'stock-quantity-updated':
                {
                    $product_id    = $item['Id'];
                    $description   = add_padding($item['Description'], 35);
                    $category_name = add_padding($item['CategoryName'], 35);
                    $product_data  = $http_info . $category_name . $description . $product_id;
                    if ($dinkassa_event)
                        $product_data .= '*';
                    $product_data .= "\r\n";
                    file_put_contents($log_file, $product_data, FILE_APPEND);
                }
                break;

            case 'category-deleted':
            case 'category-created':
            case 'category-updated':
                {
                    $category_id   = $item['Id'];
                    $category_name = add_padding($item['Name'], 35);
                    $description   = add_padding($item['Name'], 35);
                    $category_data = $http_info . $category_name . $description . $category_id;
                    if ($dinkassa_event)
                        $category_data .= '*';
                    $category_data .= "\r\n";
                    file_put_contents($log_file, $category_data, FILE_APPEND);
                }
                break;

            default:
                break;
        }
    }
}

/**
 * Appends blanks to $string so that the length of it is equal to $column_width
 *
 * @param string $string
 * @param int $column_width
 * @return string
 */
function add_padding($string, $column_width)
{
    $count = max($column_width - mb_strlen($string), 0);
    return $string . str_repeat(" ", $count);
}
