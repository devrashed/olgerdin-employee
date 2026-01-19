<?php
/**
 * Database operations for Olgerdin Employee Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class OES_Database {
    
    /**
     * Create custom database table
     */
    public static function create_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . OES_TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_detail_id BIGINT(20) UNSIGNED NOT NULL,
            name VARCHAR(255) DEFAULT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            mobile VARCHAR(50) DEFAULT NULL,
            email VARCHAR(255) DEFAULT NULL,
            title VARCHAR(255) DEFAULT NULL,
            department_id BIGINT(20) UNSIGNED DEFAULT NULL,
            department VARCHAR(255) DEFAULT NULL,
            division VARCHAR(255) DEFAULT NULL,
            company VARCHAR(255) DEFAULT NULL,
            location VARCHAR(255) DEFAULT NULL,
            image_url TEXT DEFAULT NULL,
            cost_center VARCHAR(100) DEFAULT NULL,
            manager VARCHAR(255) DEFAULT NULL,
            qualifications TEXT DEFAULT NULL,
            raw_api_data LONGTEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY employee_detail_id (employee_detail_id),
            KEY email (email(100)),
            KEY department_id (department_id),
            KEY department (department(100)),
            KEY updated_at (updated_at)
        ) $charset_collate;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
    
    /**
     * Insert or update employee record
     */
    public static function save_employee($employee_data) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . OES_TABLE_NAME;
        
        // Sanitize data
        $employee_data = self::sanitize_employee_data($employee_data);
        
        // Check if employee exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE employee_detail_id = %d",
            $employee_data['employee_detail_id']
        ));
        
        // Prepare data for database
        $data = array(
            'employee_detail_id' => absint($employee_data['employee_detail_id']),
            'name' => sanitize_text_field($employee_data['name']),
            'phone' => sanitize_text_field($employee_data['phone']),
            'mobile' => sanitize_text_field($employee_data['mobile']),
            'email' => sanitize_email($employee_data['email']),
            'title' => sanitize_text_field($employee_data['title']),
            'department_id' => absint($employee_data['department_id']),
            'department' => sanitize_text_field($employee_data['department']),
            'division' => sanitize_text_field($employee_data['division']),
            'company' => sanitize_text_field($employee_data['company']),
            'location' => sanitize_text_field($employee_data['location']),
            'image_url' => esc_url_raw($employee_data['image_url']),
            'cost_center' => sanitize_text_field($employee_data['cost_center']),
            'manager' => sanitize_text_field($employee_data['manager']),
            'qualifications' => wp_kses_post($employee_data['qualifications']),
            'raw_api_data' => wp_json_encode($employee_data['raw_data']),
            'updated_at' => current_time('mysql', 1)
        );
        
        $format = array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s');
        
        if ($exists) {
            // Update existing record
            $where = array('employee_detail_id' => $employee_data['employee_detail_id']);
            $where_format = array('%d');
            $result = $wpdb->update($table_name, $data, $where, $format, $where_format);
            return $result !== false ? 'updated' : false;
        } else {
            // Insert new record
            $data['created_at'] = current_time('mysql', 1);
            $format[] = '%s';
            $result = $wpdb->insert($table_name, $data, $format);
            return $result !== false ? 'inserted' : false;
        }
    }
    
    /**
     * Sanitize employee data
     */
    private static function sanitize_employee_data($data) {
        $sanitized = array(
            'employee_detail_id' => isset($data['EmployeeDetailID']) ? absint($data['EmployeeDetailID']) : 0,
            'name' => isset($data['Name']) ? sanitize_text_field($data['Name']) : '',
            'phone' => isset($data['Phone']) ? sanitize_text_field($data['Phone']) : '',
            'mobile' => isset($data['Mobile']) ? sanitize_text_field($data['Mobile']) : '',
            'email' => isset($data['Email']) ? sanitize_email($data['Email']) : '',
            'title' => isset($data['Title']) ? sanitize_text_field($data['Title']) : '',
            'department_id' => isset($data['DepartmentID']) ? absint($data['DepartmentID']) : 0,
            'department' => isset($data['Department']) ? sanitize_text_field($data['Department']) : '',
            'division' => isset($data['Division']) ? sanitize_text_field($data['Division']) : '',
            'company' => isset($data['Company']) ? sanitize_text_field($data['Company']) : '',
            'location' => isset($data['Location']) ? sanitize_text_field($data['Location']) : '',
            'image_url' => isset($data['ImageUrl']) ? esc_url_raw($data['ImageUrl']) : '',
            'cost_center' => isset($data['CostCenter']) ? sanitize_text_field($data['CostCenter']) : '',
            'manager' => isset($data['Manager']) ? sanitize_text_field($data['Manager']) : '',
            'qualifications' => isset($data['Qualifications']) ? wp_kses_post($data['Qualifications']) : '',
            'raw_data' => $data
        );
        
        return $sanitized;
    }
    
    /**
     * Get all employees count
     */
    public static function get_employees_count() {
        global $wpdb;
        $table_name = $wpdb->prefix . OES_TABLE_NAME;
        return $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    }
    
    /**
     * Get last sync time
     */
    public static function get_last_sync_time() {
        global $wpdb;
        $table_name = $wpdb->prefix . OES_TABLE_NAME;
        return $wpdb->get_var("SELECT MAX(updated_at) FROM $table_name");
    }
    
    /**
     * Clear all data (optional, for testing)
     */
    public static function clear_all_data() {
        global $wpdb;
        $table_name = $wpdb->prefix . OES_TABLE_NAME;
        return $wpdb->query("TRUNCATE TABLE $table_name");
    }
}