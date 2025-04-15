<?php
/*
Plugin Name: Custom T-shirt Discount
Description: Applies custom discounts on T-shirts based on quantity and categories
Version: 1.0
Author: Your Name
*/

if (!defined('ABSPATH')) {
    exit;
}

// Define constants
define('CTD_VERSION', '1.0.0');
define('CTD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CTD_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include core classes
require_once CTD_PLUGIN_DIR . 'includes/class-ctd-db.php';
require_once CTD_PLUGIN_DIR . 'includes/class-ctd-admin.php';

// Initialize plugin
function ctd_init() {
    // Initialize admin
    if (is_admin()) {
        new CTD_Admin();
    }
    
    // Add price calculation
    add_action('woocommerce_before_calculate_totals', 'ctd_calculate_prices', 99);
    
    // Add AJAX handlers
    add_action('wp_ajax_ctd_add_to_cart', 'ctd_handle_add_to_cart');
    add_action('wp_ajax_nopriv_ctd_add_to_cart', 'ctd_handle_add_to_cart');
}
add_action('plugins_loaded', 'ctd_init');

// Activation hook
register_activation_hook(__FILE__, 'ctd_activate');

function ctd_activate() {
    // Create database tables
    if (!CTD_DB::create_tables()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('Failed to create required database tables. Please check your database permissions.');
    }
}

// Calculate special pricing
function ctd_calculate_prices($cart) {
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }

    if (did_action('woocommerce_before_calculate_totals') >= 2) {
        return;
    }
    
          try {
        // Reset all prices to original first (NEW)
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['original_price'])) {
                $cart_item['data']->set_price($cart_item['original_price']);
            }
        }
        
      

       // Store original prices (corrected)
foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
    if (!isset($cart_item['original_price'])) {
        $cart->cart_contents[$cart_item_key]['original_price'] = $cart_item['data']->get_price();
    }
}


  $rules = CTD_DB::get_all_rules(); // Add this line


        foreach ($rules as $rule) {
            $categories = json_decode($rule->categories, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                continue;
            }

            $excluded_products = json_decode($rule->excluded_products, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                continue;
            }

            // Group eligible items
            $eligible_items = [];
            foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                $product_id = $cart_item['product_id'];
                // custom-tshirt-discount.php (ctd_calculate_prices)
$product_cats = [];
$terms = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'all']);
foreach ($terms as $term) {
    $product_cats[] = $term->term_id;
    $ancestors = get_ancestors($term->term_id, 'product_cat');
    $product_cats = array_merge($product_cats, $ancestors);
}
$product_cats = array_unique($product_cats);

                if (!is_wp_error($product_cats) && 
                    !in_array($product_id, $excluded_products) && 
                    array_intersect($categories, $product_cats)) {
                    
                    for ($i = 0; $i < $cart_item['quantity']; $i++) {
                        $eligible_items[] = [
                            'key' => $cart_item_key,
                            'product_id' => $product_id,
                            'original_price' => $cart_item['original_price']
                        ];
                    }
                }
            }

            // Apply special pricing to sets
            $total_items = count($eligible_items);
            $sets = floor($total_items / $rule->quantity);

            if ($sets > 0) {
                $items_in_sets = $sets * $rule->quantity;
                $price_per_item = $rule->discount_price / $rule->quantity;

                // Track processed items
                $processed_counts = [];

                // Apply special price to items in complete sets
                for ($i = 0; $i < $items_in_sets; $i++) {
                    $item = $eligible_items[$i];
                    $cart_item_key = $item['key'];

                    if (!isset($processed_counts[$cart_item_key])) {
                        $processed_counts[$cart_item_key] = 0;
                    }

                    $cart_item = $cart->get_cart_item($cart_item_key);
                    $processed_counts[$cart_item_key]++;

                    if ($processed_counts[$cart_item_key] <= $cart_item['quantity']) {
                        $cart_item['data']->set_price($price_per_item);
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log('CTD: Error calculating prices: ' . $e->getMessage());
    }
}

// Handle AJAX add to cart
function ctd_handle_add_to_cart() {
    try {
        check_ajax_referer('wc-ajax', 'security');

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $quantity = isset($_POST['quantity']) ? wc_stock_amount($_POST['quantity']) : 1;
        $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
        $variations = isset($_POST['variation']) ? (array) $_POST['variation'] : [];

        // Sanitize variations
        foreach ($variations as $key => $value) {
            $variations[$key] = wc_clean($value);
        }

        $passed_validation = apply_filters('woocommerce_add_to_cart_validation', true, $product_id, $quantity, $variation_id, $variations);

        if ($passed_validation && WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variations)) {
            do_action('woocommerce_ajax_added_to_cart', $product_id);

            // Get mini cart HTML
            ob_start();
            woocommerce_mini_cart();
            $mini_cart = ob_get_clean();

            // Fragments and cart hash
            $data = [
                'fragments' => apply_filters(
                    'woocommerce_add_to_cart_fragments',
                    [
                        'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>'
                    ]
                ),
                'cart_hash' => WC()->cart->get_cart_hash()
            ];

            wp_send_json_success($data);
        } else {
            wp_send_json_error([
                'error' => __('Error adding product to cart.', 'woocommerce')
            ]);
        }
    } catch (Exception $e) {
        wp_send_json_error([
            'error' => $e->getMessage()
        ]);
    }

    wp_die();
}

// Add compatibility filter for WooCommerce AJAX
function ctd_ajax_compatibility($fragments) {
    if (wp_doing_ajax()) {
        nocache_headers();
    }
    return $fragments;
}
add_filter('woocommerce_add_to_cart_fragments', 'ctd_ajax_compatibility');

// Initialize AJAX handling
function ctd_init_ajax_handling() {
    if (wp_doing_ajax()) {
        if (!defined('DOING_AJAX')) {
            define('DOING_AJAX', true);
        }
        
        error_reporting(0);
        @ini_set('display_errors', 0);
    }
}
add_action('init', 'ctd_init_ajax_handling', 0);

// Add custom AJAX endpoint
function ctd_add_ajax_events() {
    add_rewrite_endpoint('ctd-ajax', EP_ALL);
}
add_action('init', 'ctd_add_ajax_events');