<?php
defined( 'ABSPATH' ) || exit;

define('HTTP_OK', 200);
/**
 * Class WooCommerce_Async_Http_Request
 *
 * Class for handling non-blocking asynchronous HTTP requests. Sends asynchronous
 * HTTP-requests to update the Dinkassa.se API in response to events such as
 * purchases, creation/editing of products and categories. The response sent
 * back from Dinkassa.se is then processed by the thread.
 *
 */
class WooCommerce_Async_Http_Request extends WP_Async_Request
{
    /* Note: Removed property types because they don't work for PHP versions < 7.4 */
    protected $action = 'woocommerce_async_http_request';

    private $dinkassa_headers;

    private $log_woocommerce_events;

    public function __construct()
    {
        parent::__construct();

        $this->dinkassa_headers = array(
            'MachineId: ' . get_option('machine_id'),
            'MachineKey: ' . get_option('machine_key'),
            'IntegratorId: ' . get_option('integrator_id')
        );
        $this->log_woocommerce_events = get_option('log_wc_events') === 'yes';
    }

    /**
     * Sends an HTTP request to the Dinkassa.se server and processes the response.
     */
    protected function handle()
    {
        // TODO: Implement handle() method.

        $event = $_POST['event'];
        $controller = $_POST['controller'];
        $dinkassa_id = $_POST['dinkassa_id'];
        $url = DINKASSA_SE_API . '/' . $controller;
        if (isset($dinkassa_id))
            $url .= '/' . $dinkassa_id;
        $handle = curl_init($url);
        if (! $handle)
            return new WP_Error('', __FUNCTION__ . ": curl_init() failed");
        else {
            $request = $_POST['request'];
            $post_id = (int)$_POST['post_id'];
            $opt_headers = $_POST['opt_headers'];
            if (! empty($opt_headers))
                $this->dinkassa_headers = array_merge($this->dinkassa_headers, $opt_headers);
            curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $request);
            if (isset($_POST['data']))
            {
                $json_data = urldecode($_POST['data']);
                curl_setopt($handle, CURLOPT_POSTFIELDS, $json_data);
            }
            curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, is_ssl()); // curl_exec() error code 60 without this
            curl_setopt($handle, CURLOPT_HTTPHEADER, $this->dinkassa_headers);
            curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($handle, CURLOPT_TIMEOUT, 30);
            $response = curl_exec($handle);
            $http_code = curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $response = json_decode($response, true);
            curl_close($handle);
            if ($this->log_woocommerce_events) {
                if (isset($_POST['info']))
                    $response = $_POST['info'];
                woocommerce_event_logger($event, $http_code, $response);
            }
            $this->process_server_response($event, $http_code, $response, $post_id, $dinkassa_id);
        }
    }

    /**
     * Handles responses from Dinkassa.se. The data in the response is used to set the
     * ids of products and categories created in WooCommerce and update custom fields.
     * If a request fails, a flag is set to indicate that the request should be processed
     * again.
     *
     * @param string $event Type of event: product-purchased, product-created etc.
     * @param int $status HTTP status code of the response from Dinkassa.se
     * @param array $response Response from Dinkassa.se in the form of an associative array
     * @param int $post_id WordPress id of a product or category
     * @param string $dinkassa_id Dinkassa.se inventoryitem/category ID
     */
    private function process_server_response($event, $status, $response, $post_id, $dinkassa_id)
    {
        switch ($event) {
            case 'product-created':
                if ($status >= 400)
                {
                    // Error. Set pending create bit to 1
                    $meta_key = META_KEY_PREFIX . 'pending_crud';
                    $pending_crud = (int)get_post_meta($post_id, $meta_key, true);
                    if (($pending_crud & 0x1) == 0)
                        update_post_meta($post_id, $meta_key, $pending_crud | 0x1);
                }
                else {
                    $item = $response['Item'];
                    $custom_fields = array(
                        'id' => $item['Id'],
                        'categoryname' => $item['CategoryName']
                    );
                    foreach ($custom_fields as $field_name => $value)
                    {
                        $meta_key = META_KEY_PREFIX . $field_name;
                        update_post_meta($post_id, $meta_key, $value);
                    }
                    $meta_key = META_KEY_PREFIX . 'pending_crud';
                    $pending_crud = (int)get_post_meta($post_id, $meta_key, true);
                    // Set pending create bit to 0
                    if ($pending_crud & 0x1)
                        update_post_meta($post_id, $meta_key, $pending_crud & 0x6);
                    $product = wc_get_product($post_id);
                    if ($product) {
                        try {
                            $product->set_catalog_visibility('visible');
                        } catch (WC_Data_Exception $e) {
                        }
                    }
                }
                break;

            case 'product-updated':
                {
                    $meta_key = META_KEY_PREFIX . 'pending_crud';
                    $pending_crud = (int)get_post_meta($post_id, $meta_key, true);
                    if ($status >= 400)
                    {
                        // Update failed, set bit to 1
                        if (($pending_crud & 0x2) == 0)
                            update_post_meta($post_id, $meta_key, $pending_crud | 0x2);
                    }
                    else if ($pending_crud & 0x2)
                        update_post_meta($post_id, $meta_key, $pending_crud & 0x5);
                }
                break;

            case 'stock-quantity-updated':
                {
                    global $wp_lock;

                    $meta_key = META_KEY_PREFIX . 'pending_crud';
                    $pending_crud = (int)get_post_meta($post_id, $meta_key, true);
                    if ($status >= 400) {
                        if (($pending_crud & 0x4) == 0)
                            update_post_meta($post_id, $meta_key, $pending_crud | 0x4);
                    } else {
                        if ($pending_crud & 0x4)
                            update_post_meta($post_id, $meta_key, $pending_crud & 0x3);
                        // Set 'quantity_change' field to 0
                        $meta_key = META_KEY_PREFIX . 'quantity_change';
                        update_post_meta($post_id, $meta_key, 0);
                    }
                    $wp_lock->release();
                }
                break;

            case 'product-deleted':
            case 'category-deleted':
                {
                    $type = $event === 'product-deleted'? 'product' : 'category';
                    $term_id = get_deleted_item_term_id();
                    if (! deleted_item_exists($term_id, $type, $dinkassa_id))
                    {
                        if ($status >= 400) {
                            $deleted_item = new WC_Deleted_Item();
                            $deleted_item->type = $type;
                            $deleted_item->dinkassa_id = $dinkassa_id;
                            add_term_meta($term_id, 'meta_deleted_item', $deleted_item);
                        }
                    }
                    else if ($status < 400) {
                        $deleted_item = new WC_Deleted_Item();
                        $deleted_item->type = $type;
                        $deleted_item->dinkassa_id = $dinkassa_id;
                        delete_term_meta($term_id, 'meta_deleted_item', $deleted_item);
                    }
                }
                break;

            case 'category-updated':
                {
                    $meta_key = 'wh_meta_pending_crud';
                    $pending_crud = (int)get_term_meta($post_id, $meta_key, true);
                    if ($status >= 400) {
                        // Update failed, set bit to 1
                        if (($pending_crud & 0x2) == 0)
                            update_term_meta($post_id, $meta_key, $pending_crud | 0x2);
                    } else if ($pending_crud & 0x2)
                        update_term_meta($post_id, $meta_key, $pending_crud & 0x5);
                }
                break;

            case 'category-created':
                if ($status >= 400)
                {
                    $meta_key = 'wh_meta_pending_crud';
                    $pending_crud = (int)get_term_meta($post_id, $meta_key, true);
                    if (($pending_crud & 0x1) == 0)
                        update_term_meta($post_id, $meta_key, $pending_crud | 0x1);
                }
                else {
                    // Set custom category field id to the inventory item id
                    $category_id = $response['Item']['Id'];
                    update_term_meta($post_id, 'wh_meta_cat_id', $category_id);
                    $meta_key = 'wh_meta_pending_crud';
                    $pending_crud = (int)get_term_meta($post_id, $meta_key, true);
                    if ($pending_crud & 0x1)
                        update_term_meta($post_id, $meta_key, $pending_crud & 0x6);
                }
                break;
        }
    }
}