<?php
class CTD_DB {
    private static $table_name = 'ctd_rules'; // Changed to match all references

    public static function create_tables() {
        global $wpdb;
        $table = $wpdb->prefix . self::$table_name;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            rule_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            categories TEXT NOT NULL,
            excluded_products TEXT NOT NULL,
            quantity INT NOT NULL,
            discount_price DECIMAL(10,2) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (rule_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $result = dbDelta($sql);

        // More robust table creation check
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            error_log('CTD: Failed to create table: ' . $table);
            return false;
        }

        return true;
    }

    public static function check_tables() {
        global $wpdb;
        $table = $wpdb->prefix . self::$table_name;
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            return self::create_tables();
        }
        
        return true;
    }

    public static function get_all_rules() {
        global $wpdb;
        $table = $wpdb->prefix . self::$table_name;
        
        if (!self::check_tables()) {
            error_log('CTD: Tables not available');
            return [];
        }
        
        $results = $wpdb->get_results(
            "SELECT * FROM $table 
            WHERE status = 'active' 
            ORDER BY created_at DESC"
        );
        
        if ($wpdb->last_error) {
            error_log('CTD: Database error - ' . $wpdb->last_error);
            return [];
        }
        
        return $results;
    }

    public static function get_rule($id) {
        global $wpdb;
        $table = $wpdb->prefix . self::$table_name;
        
        if (!self::check_tables()) {
            return null;
        }
        
        $rule = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE rule_id = %d",
            $id
        ));
        
        if ($wpdb->last_error) {
            error_log('CTD: Database error - ' . $wpdb->last_error);
            return null;
        }
        
        return $rule;
    }

    public static function save_rule($data) {
        global $wpdb;
        $table = $wpdb->prefix . self::$table_name;
        
        if (!self::check_tables()) {
            error_log('CTD: Cannot save rule - tables not available');
            return false;
        }
        
        $defaults = [
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
            'status' => 'active'
        ];
        
        $data = wp_parse_args($data, $defaults);
        
        // Sanitize data
        $data['name'] = sanitize_text_field($data['name']);
        $data['quantity'] = absint($data['quantity']);
        $data['discount_price'] = floatval($data['discount_price']);
        
        try {
            if (isset($data['rule_id'])) {
                $id = $data['rule_id'];
                unset($data['rule_id']);
                $result = $wpdb->update($table, $data, ['rule_id' => $id]);
            } else {
                $result = $wpdb->insert($table, $data);
            }
            
            if ($wpdb->last_error) {
                throw new Exception($wpdb->last_error);
            }
            
            return $result !== false;
            
        } catch (Exception $e) {
            error_log('CTD: Error saving rule - ' . $e->getMessage());
            return false;
        }
    }

    public static function delete_rule($id) {
        global $wpdb;
        $table = $wpdb->prefix . self::$table_name;
        
        if (!self::check_tables()) {
            return false;
        }
        
        try {
            $result = $wpdb->update(
                $table,
                ['status' => 'deleted'],
                ['rule_id' => $id]
            );
            
            if ($wpdb->last_error) {
                throw new Exception($wpdb->last_error);
            }
            
            return $result !== false;
            
        } catch (Exception $e) {
            error_log('CTD: Error deleting rule - ' . $e->getMessage());
            return false;
        }
    }
}