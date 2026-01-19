<?php
/**
 * Plugin Name: Olgerdin Employee Sync
 * Plugin URI: #
 * Description: Sync employee data from Olgerdin API to WordPress database
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: olgerdin-employee-sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('OES_VERSION', '1.0.0');
define('OES_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OES_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OES_TABLE_NAME', 'olgerdin_employees');
define('OES_API_URL', 'https://olgerdin-api.starfsmenn.is/api/HrData/EmployeesSearch');

// Plugin activation/deactivation hooks
register_activation_hook(__FILE__, 'oes_activate_plugin');
register_deactivation_hook(__FILE__, 'oes_deactivate_plugin');

/**
 * Plugin activation callback
 */
function oes_activate_plugin() {
    require_once OES_PLUGIN_DIR . 'includes/class-database.php';
    OES_Database::create_table();
    add_option('oes_version', OES_VERSION);
}

/**
 * Plugin deactivation callback
 */
function oes_deactivate_plugin() {
    // Clean up if needed
}

// Autoloader for plugin classes
spl_autoload_register(function ($class) {
    $prefix = 'OES_';
    $base_dir = OES_PLUGIN_DIR . 'includes/';
    
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    
    $relative_class = substr($class, strlen($prefix));
    $file = $base_dir . 'class-' . str_replace('_', '-', strtolower($relative_class)) . '.php';
    
    if (file_exists($file)) {
        require_once $file;
    }
});

// Initialize the plugin
add_action('plugins_loaded', 'oes_init_plugin');

function oes_init_plugin() {
    // Initialize AJAX handler
    if (!class_exists('OES_AJAX')) {
        require_once OES_PLUGIN_DIR . 'includes/class-ajax.php';
        new OES_AJAX();
    }
    
    // Initialize admin settings if in admin area
    if (is_admin() && !class_exists('OES_Admin_Settings')) {
        require_once OES_PLUGIN_DIR . 'includes/class-admin.php';
        // Create instance - constructor should be public, not private
        new OES_Admin_Settings();
    }
}

// Load textdomain for translations
add_action('init', function() {
    load_plugin_textdomain('olgerdin-employee-sync', false, dirname(plugin_basename(__FILE__)) . '/languages');
});