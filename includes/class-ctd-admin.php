<?php
class CTD_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_ctd_save_rule', [$this, 'save_rule_ajax']);
        add_action('wp_ajax_ctd_get_rule', [$this, 'get_rule_ajax']);
        add_action('wp_ajax_ctd_delete_rule', [$this, 'delete_rule_ajax']);
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'T-shirt Discounts',
            'T-shirt Discounts',
            'manage_options',
            'tshirt-discounts',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page() {
        ?>
        <div class="wrap">
            <div class="ctd-admin-container">
                <div class="ctd-admin-header">
                    <h1><?php esc_html_e('T-shirt Discount Rules', 'custom-tshirt-discount'); ?></h1>
                    <button id="ctd-add-new" class="button button-primary">
                        <?php esc_html_e('Add New Rule', 'custom-tshirt-discount'); ?>
                    </button>
                </div>

                <div class="ctd-rules-list">
                    <?php $this->render_rules_table(); ?>
                </div>

                <div class="ctd-rule-editor" style="display: none;">
                    <?php $this->render_rule_editor(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, 'tshirt-discounts') === false) return;
        
        wp_enqueue_style('ctd-admin-css', plugin_dir_url(dirname(__FILE__)) . 'assets/css/admin.css');
        wp_enqueue_script('ctd-admin-js', plugin_dir_url(dirname(__FILE__)) . 'assets/js/admin.js', 
            ['jquery'], false, true);
        
        wp_localize_script('ctd-admin-js', 'ctd_admin_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ctd_admin_nonce')
        ]);
    }

    private function render_rules_table() {
        $rules = CTD_DB::get_all_rules();
        ?>
        <table class="wp-list-table widefat fixed striped ctd-rules-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Rule Name', 'custom-tshirt-discount'); ?></th>
                    <th><?php esc_html_e('Quantity', 'custom-tshirt-discount'); ?></th>
                    <th><?php esc_html_e('Discount Price', 'custom-tshirt-discount'); ?></th>
                    <th><?php esc_html_e('Created', 'custom-tshirt-discount'); ?></th>
                    <th><?php esc_html_e('Actions', 'custom-tshirt-discount'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rules as $rule) : ?>
                    <tr>
                        <td><?php echo esc_html($rule->name); ?></td>
                        <td><?php echo esc_html($rule->quantity); ?></td>
                        <td>₹<?php echo esc_html($rule->discount_price); ?></td>
                        <td><?php echo date_i18n(get_option('date_format'), strtotime($rule->created_at)); ?></td>
                        <td>
                            <button class="button ctd-edit-rule" data-id="<?php echo esc_attr($rule->rule_id); ?>">
                                <?php esc_html_e('Edit', 'custom-tshirt-discount'); ?>
                            </button>
                            <button class="button ctd-delete-rule" data-id="<?php echo esc_attr($rule->rule_id); ?>">
                                <?php esc_html_e('Delete', 'custom-tshirt-discount'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_rule_editor() {
        ?>
        <div class="ctd-editor-container">
            <div class="ctd-editor-header">
                <h2><?php esc_html_e('Edit Discount Rule', 'custom-tshirt-discount'); ?></h2>
                <div class="ctd-editor-actions">
                    <button id="ctd-save-rule" class="button button-primary">
                        <?php esc_html_e('Save Rule', 'custom-tshirt-discount'); ?>
                    </button>
                    <button id="ctd-cancel-edit" class="button">
                        <?php esc_html_e('Cancel', 'custom-tshirt-discount'); ?>
                    </button>
                </div>
            </div>

            <div class="ctd-form-section">
                <div class="ctd-form-group">
                    <label for="ctd-rule-name"><?php esc_html_e('Rule Name', 'custom-tshirt-discount'); ?></label>
                    <input type="text" id="ctd-rule-name" class="regular-text" 
                           placeholder="e.g., Buy 2 T-shirts for ₹999">
                </div>

                <div class="ctd-form-group">
                    <label for="ctd-quantity"><?php esc_html_e('Quantity', 'custom-tshirt-discount'); ?></label>
                    <input type="number" id="ctd-quantity" min="2" value="2">
                </div>

                <div class="ctd-form-group">
                    <label for="ctd-discount-price"><?php esc_html_e('Discount Price (₹)', 'custom-tshirt-discount'); ?></label>
                    <input type="number" id="ctd-discount-price" min="0" value="999">
                </div>
            </div>

            <div class="ctd-form-section">
                <h3><?php esc_html_e('Apply to Categories', 'custom-tshirt-discount'); ?></h3>
                <div class="ctd-checkbox-group">
                    <?php
                    $categories = get_terms('product_cat', ['hide_empty' => false]);
                    foreach ($categories as $cat) :
                    ?>
                        <label>
                            <input type="checkbox" name="categories[]" value="<?php echo esc_attr($cat->term_id); ?>">
                            <?php echo esc_html($cat->name); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="ctd-form-section">
                <h3><?php esc_html_e('Exclude Products', 'custom-tshirt-discount'); ?></h3>
                <div class="ctd-checkbox-group">
                    <?php
                    $products = get_posts([
                        'post_type' => 'product',
                        'posts_per_page' => -1
                    ]);
                    foreach ($products as $product) :
                    ?>
                        <label>
                            <input type="checkbox" name="excluded_products[]" value="<?php echo esc_attr($product->ID); ?>">
                            <?php echo esc_html($product->post_title); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function save_rule_ajax() {
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ctd_admin_nonce')) {
                throw new Exception('Security check failed');
            }

            if (!current_user_can('manage_options')) {
                throw new Exception('Permission denied');
            }
            
            
             // Decode and validate categories
        $categories = json_decode(wp_unslash($_POST['categories']), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid categories data');
        }

        // Decode and validate excluded products
        $excluded_products = json_decode(wp_unslash($_POST['excluded_products']), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid excluded products data');
        }



            $data = [
                'name' => sanitize_text_field($_POST['name']),
                'quantity' => intval($_POST['quantity']),
                'discount_price' => floatval($_POST['discount_price']),
                'categories' => sanitize_text_field($_POST['categories']),
                'excluded_products' => sanitize_text_field($_POST['excluded_products'])
            ];

            if (isset($_POST['rule_id'])) {
                $data['rule_id'] = intval($_POST['rule_id']);
            }

            $result = CTD_DB::save_rule($data);

            if ($result === false) {
                throw new Exception('Failed to save rule');
            }

            wp_send_json_success(['message' => 'Rule saved successfully']);

        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function get_rule_ajax() {
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ctd_admin_nonce')) {
                throw new Exception('Security check failed');
            }

            if (!current_user_can('manage_options')) {
                throw new Exception('Permission denied');
            }

            $rule_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            $rule = CTD_DB::get_rule($rule_id);

            if (!$rule) {
                throw new Exception('Rule not found');
            }

            wp_send_json_success($rule);

        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function delete_rule_ajax() {
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ctd_admin_nonce')) {
                throw new Exception('Security check failed');
            }

            if (!current_user_can('manage_options')) {
                throw new Exception('Permission denied');
            }

            $rule_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            $result = CTD_DB::delete_rule($rule_id);

            if ($result === false) {
                throw new Exception('Failed to delete rule');
            }

            wp_send_json_success(['message' => 'Rule deleted successfully']);

        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}