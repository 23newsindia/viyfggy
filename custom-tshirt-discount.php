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
    
    // Add AJAX handlers for both logged in and non-logged in users
    add_action('wp_ajax_ctd_add_to_cart', 'ctd_handle_add_to_cart');
    add_action('wp_ajax_nopriv_ctd_add_to_cart', 'ctd_handle_add_to_cart');
    
    // Add WooCommerce AJAX handlers
    add_action('wc_ajax_add_to_cart', 'ctd_handle_add_to_cart');
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
        // Reset all prices to original first
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['original_price'])) {
                $cart_item['data']->set_price($cart_item['original_price']);
            }
        }

        // Get all active rules
        $rules = CTD_DB::get_all_rules();
        if (empty($rules)) {
            return;
        }

        // Store original prices and group items by rule eligibility
        $eligible_items = [];
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (!isset($cart_item['original_price'])) {
                $cart_item['original_price'] = $cart_item['data']->get_price();
                $cart->cart_contents[$cart_item_key]['original_price'] = $cart_item['original_price'];
            }

            $product_id = $cart_item['product_id'];
            $product_cats = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
            
            // Include parent categories
            foreach ($product_cats as $cat_id) {
                $ancestors = get_ancestors($cat_id, 'product_cat');
                $product_cats = array_merge($product_cats, $ancestors);
            }
            $product_cats = array_unique($product_cats);

            // Check each rule
            foreach ($rules as $rule) {
                $rule_categories = json_decode($rule->categories, true);
                $excluded_products = json_decode($rule->excluded_products, true) ?: [];

                // Skip if product is excluded
                if (in_array($product_id, $excluded_products)) {
                    continue;
                }

                // Check if product belongs to any of the rule categories
                if (array_intersect($product_cats, $rule_categories)) {
                    if (!isset($eligible_items[$rule->rule_id])) {
                        $eligible_items[$rule->rule_id] = [];
                    }
                    
                    // Add item multiple times based on quantity
                    for ($i = 0; $i < $cart_item['quantity']; $i++) {
                        $eligible_items[$rule->rule_id][] = [
                            'key' => $cart_item_key,
                            'price' => $cart_item['original_price']
                        ];
                    }
                }
            }
        }

        // Apply discounts for each rule
        foreach ($rules as $rule) {
            if (!isset($eligible_items[$rule->rule_id])) {
                continue;
            }

            $items = $eligible_items[$rule->rule_id];
            $total_items = count($items);
            $sets = floor($total_items / $rule->quantity);

            if ($sets > 0) {
                $items_in_sets = $sets * $rule->quantity;
                $discount_per_item = $rule->discount_price / $rule->quantity;

                // Track how many items we've discounted for each cart item
                $discounted_counts = [];

                // Apply discount to items in complete sets
                for ($i = 0; $i < $items_in_sets; $i++) {
                    $item = $items[$i];
                    $cart_item_key = $item['key'];

                    if (!isset($discounted_counts[$cart_item_key])) {
                        $discounted_counts[$cart_item_key] = 0;
                    }

                    $cart_item = $cart->get_cart_item($cart_item_key);
                    $discounted_counts[$cart_item_key]++;

                    // Calculate how many items should be discounted
                    $items_to_discount = min(
                        $discounted_counts[$cart_item_key],
                        $cart_item['quantity']
                    );

                    if ($items_to_discount > 0) {
                        // Set the new price
                        $cart_item['data']->set_price($discount_per_item);
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
    // Prevent any output before our JSON response
    @ob_clean();
    
    header('Content-Type: application/json');

    try {
        // Verify nonce for security
        if (!check_ajax_referer('woocommerce-add-to-cart', 'security', false)) {
            throw new Exception(__('Security check failed', 'woocommerce'));
        }

        $product_id = apply_filters('woocommerce_add_to_cart_product_id', absint($_POST['product_id']));
        $quantity = empty($_POST['quantity']) ? 1 : wc_stock_amount(wp_unslash($_POST['quantity']));
        $variation_id = empty($_POST['variation_id']) ? 0 : absint($_POST['variation_id']);
        $passed_validation = apply_filters('woocommerce_add_to_cart_validation', true, $product_id, $quantity);
        $product_status = get_post_status($product_id);

        if ($passed_validation && WC()->cart && 'publish' === $product_status) {
            // Get variation data
            $variation = [];
            if ($variation_id) {
                foreach ($_POST as $key => $value) {
                    if (strpos($key, 'attribute_') === 0) {
                        $variation[sanitize_title(wp_unslash($key))] = wp_unslash($value);
                    }
                }
            }

            $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation);

            if ($cart_item_key) {
                do_action('woocommerce_ajax_added_to_cart', $product_id);

                // Get mini cart HTML
                ob_start();
                woocommerce_mini_cart();
                $mini_cart = ob_get_clean();

                // Prepare response data
                $data = array(
                    'error' => false,
                    'product_url' => apply_filters('woocommerce_cart_redirect_after_error', get_permalink($product_id), $product_id),
                    'fragments' => apply_filters(
                        'woocommerce_add_to_cart_fragments',
                        array(
                            'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>'
                        )
                    ),
                    'cart_hash' => WC()->cart->get_cart_hash()
                );

                wp_send_json($data);
            } else {
                throw new Exception(__('Error adding product to cart.', 'woocommerce'));
            }
        } else {
            throw new Exception(__('Product cannot be purchased.', 'woocommerce'));
        }
    } catch (Exception $e) {
        wp_send_json(array(
            'error' => true,
            'message' => $e->getMessage(),
            'product_url' => apply_filters('woocommerce_cart_redirect_after_error', get_permalink($product_id), $product_id)
        ));
    }
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
