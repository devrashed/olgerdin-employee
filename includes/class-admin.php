<?php
/**
 * Admin settings page for Olgerdin Employee Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class OES_Admin_Settings {
    
    private $api_handler;
    private $page_slug = 'olgerdin-employee-sync';
    private static $instance = null;
    
    /**
     * Constructor - must be PUBLIC
     */
    public function __construct() {
        // Initialize API handler
        if (!class_exists('OES_API_Handler')) {
            require_once plugin_dir_path(__FILE__) . 'class-api-handler.php';
        }
        $this->api_handler = new OES_API_Handler();
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_form_submissions'));
        add_action('admin_notices', array($this, 'display_admin_notices'));
        
        // Add admin styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
    }
    
    /**
     * Get instance (singleton pattern - optional)
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles() {
        wp_enqueue_style(
            'oes-admin-styles',
            plugin_dir_url(__FILE__) . '../assets/css/admin.css',
            array(),
            OES_VERSION
        );
    }
    
    /**
     * Add SINGLE admin menu (no submenus)
     */
    public function add_admin_menu() {
        // Remove any existing menu items with same slug to prevent duplicates
        remove_menu_page($this->page_slug);
        
        // Add single main menu
        add_menu_page(
            __('Olgerdin Employee Sync', 'olgerdin-employee-sync'),
            __('Olgerdin Sync', 'olgerdin-employee-sync'),
            'manage_options',
            $this->page_slug,
            array($this, 'render_admin_page'),
            'dashicons-groups',
            30
        );
    }
    
    /**
     * Handle form submissions
     */
    public function handle_form_submissions() {
        if (!isset($_POST['oes_action']) || !check_admin_referer('oes_admin_action', 'oes_nonce')) {
            return;
        }
        
        $action = sanitize_text_field($_POST['oes_action']);
        
        switch ($action) {
            case 'authenticate':
                $this->handle_authentication();
                break;
                
            case 'sync_employees':
                $this->handle_sync();
                break;
                
            case 'clear_token':
                $this->handle_clear_token();
                break;
        }
    }
    
    /**
     * Handle authentication form submission
     */
    private function handle_authentication() {
        $username = isset($_POST['oes_username']) ? sanitize_text_field($_POST['oes_username']) : '';
        $password = isset($_POST['oes_password']) ? $_POST['oes_password'] : '';
        
        if (empty($username) || empty($password)) {
            add_settings_error(
                'oes_messages',
                'oes_auth_error',
                __('Please enter both username and password.', 'olgerdin-employee-sync'),
                'error'
            );
            return;
        }
        
        $result = $this->api_handler->authenticate($username, $password);
        
        if (is_wp_error($result)) {
            add_settings_error(
                'oes_messages',
                'oes_auth_error',
                sprintf(__('Authentication failed: %s', 'olgerdin-employee-sync'), $result->get_error_message()),
                'error'
            );
        } else {
            add_settings_error(
                'oes_messages',
                'oes_auth_success',
                __('Authentication successful! Token has been saved.', 'olgerdin-employee-sync'),
                'success'
            );
        }
    }
    
    /**
     * Handle sync action
     */
    private function handle_sync() {
        $result = $this->api_handler->sync_employees();
        
        if (is_wp_error($result)) {
            if (isset($result->get_error_data()['requires_reauth'])) {
                add_settings_error(
                    'oes_messages',
                    'oes_sync_error',
                    __('Sync failed: Token has expired. Please re-authenticate first.', 'olgerdin-employee-sync'),
                    'error'
                );
            } else {
                add_settings_error(
                    'oes_messages',
                    'oes_sync_error',
                    sprintf(__('Sync failed: %s', 'olgerdin-employee-sync'), $result->get_error_message()),
                    'error'
                );
            }
        } else {
            $message = sprintf(
                __('Sync completed successfully! Processed %d employees: %d inserted, %d updated, %d failed.', 'olgerdin-employee-sync'),
                $result['total'],
                $result['inserted'],
                $result['updated'],
                $result['failed']
            );
            
            add_settings_error(
                'oes_messages',
                'oes_sync_success',
                $message,
                'success'
            );
            
            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $error) {
                    add_settings_error(
                        'oes_messages',
                        'oes_sync_warning',
                        $error,
                        'warning'
                    );
                }
            }
        }
    }
    
    /**
     * Handle clear token action
     */
    private function handle_clear_token() {
        $this->api_handler->clear_token();
        add_settings_error(
            'oes_messages',
            'oes_clear_success',
            __('Token has been cleared successfully.', 'olgerdin-employee-sync'),
            'success'
        );
    }
    
    /**
     * Display admin notices
     */
    public function display_admin_notices() {
        settings_errors('oes_messages');
    }
    
    /**
     * Get paginated synced employees from database
     */
    private function get_synced_employees($page = 1, $per_page = 20) {
        global $wpdb;
        $table_name = $wpdb->prefix . OES_TABLE_NAME;
        
        // Calculate offset
        $offset = ($page - 1) * $per_page;
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name ORDER BY updated_at DESC, name ASC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );
    }
    
    /**
     * Get employee count
     */
    private function get_employee_count() {
        global $wpdb;
        $table_name = $wpdb->prefix . OES_TABLE_NAME;
        
        return $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    }
    
    /**
     * Get total pages for pagination
     */
    private function get_total_pages($per_page = 20) {
        $total_employees = $this->get_employee_count();
        return ceil($total_employees / $per_page);
    }
    
    /**
     * Generate simple pagination HTML
     */
    private function generate_pagination($current_page, $total_pages) {
        if ($total_pages <= 1) {
            return '';
        }
        
        $html = '<div class="oes-pagination">';
        
        // Previous button
        if ($current_page > 1) {
            $html .= '<a href="?page=' . $this->page_slug . '&tab=sync&p=' . ($current_page - 1) . '" class="oes-page-link oes-prev">« ' . __('Previous', 'olgerdin-employee-sync') . '</a>';
        }
        
        // Page numbers
        for ($i = 1; $i <= $total_pages; $i++) {
            if ($i == $current_page) {
                $html .= '<span class="oes-page-link oes-current">' . $i . '</span>';
            } elseif (
                $i == 1 || 
                $i == $total_pages || 
                ($i >= $current_page - 2 && $i <= $current_page + 2)
            ) {
                $html .= '<a href="?page=' . $this->page_slug . '&tab=sync&p=' . $i . '" class="oes-page-link">' . $i . '</a>';
            } elseif (
                $i == $current_page - 3 || 
                $i == $current_page + 3
            ) {
                $html .= '<span class="oes-page-link oes-dots">...</span>';
            }
        }
        
        // Next button
        if ($current_page < $total_pages) {
            $html .= '<a href="?page=' . $this->page_slug . '&tab=sync&p=' . ($current_page + 1) . '" class="oes-page-link oes-next">' . __('Next', 'olgerdin-employee-sync') . ' »</a>';
        }
        
        $html .= '</div>';
        return $html;
    }
    
    /**
     * Render SINGLE admin page with tabs
     */
    public function render_admin_page() {
        $auth_status = $this->api_handler->get_auth_status();
        $last_sync_results = get_option('oes_last_sync_results', array());
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'dashboard';
        
        // Pagination settings
        $per_page = 50; // Number of employees per page
        $current_page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=<?php echo esc_attr($this->page_slug); ?>&tab=dashboard" class="nav-tab <?php echo $current_tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html('Dashboard', 'olgerdin-employee-sync'); ?>
                </a>
                <a href="?page=<?php echo esc_attr($this->page_slug); ?>&tab=sync" class="nav-tab <?php echo $current_tab === 'sync' ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html('Sync Employees', 'olgerdin-employee-sync'); ?>
                </a>
            </h2>
            
            <div class="oes-admin-container">
                <?php if ($current_tab === 'dashboard'): ?>
                    <!-- Dashboard Tab Content -->
                    <div class="oes-status-card">
                        <h2><?php echo esc_html('Authentication Status', 'olgerdin-employee-sync'); ?></h2>
                        
                        <div class="oes-status-indicator <?php echo $auth_status['token_valid'] ? 'oes-status-valid' : 'oes-status-invalid'; ?>">
                            <span class="oes-status-dot"></span>
                            <span class="oes-status-text">
                                <?php echo esc_html($auth_status['message']); ?>
                            </span>
                        </div>
                        
                        <?php if ($auth_status['token_valid'] && isset($auth_status['expires_in'])): ?>
                            <div class="oes-status-detail">
                                <p>
                                    <strong><?php echo esc_html('Token expires in:', 'olgerdin-employee-sync'); ?></strong> 
                                    <?php echo esc_html($auth_status['expires_in']); ?>
                                </p>
                                <?php if (isset($auth_status['expires_at'])): ?>
                                    <p>
                                        <strong><?php echo esc_html('Expires at:', 'olgerdin-employee-sync'); ?></strong> 
                                        <?php echo esc_html($auth_status['expires_at']); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="oes-auth-card">
                        <h2><?php echo esc_html('API Authentication', 'olgerdin-employee-sync'); ?></h2>
                        
                        <?php if ($auth_status['requires_auth']): ?>
                            <div class="notice notice-warning">
                                <p><?php echo esc_html('Authentication is required to access the Olgerdin API.', 'olgerdin-employee-sync'); ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <form method="post" class="oes-auth-form">
                            <?php wp_nonce_field('oes_admin_action', 'oes_nonce'); ?>
                            <input type="hidden" name="oes_action" value="authenticate">
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="oes_username"><?php echo esc_html('Username', 'olgerdin-employee-sync'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" 
                                               id="oes_username" 
                                               name="oes_username" 
                                               class="regular-text" 
                                               required
                                               placeholder="<?php esc_attr_e('Enter your API username', 'olgerdin-employee-sync'); ?>">
                                        <p class="description">
                                            <?php echo esc_html('Your Olgerdin API username', 'olgerdin-employee-sync'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="oes_password"><?php echo esc_html('Password', 'olgerdin-employee-sync'); ?></label>
                                    </th>
                                    <td>
                                        <input type="password" 
                                               id="oes_password" 
                                               name="oes_password" 
                                               class="regular-text" 
                                               required
                                               placeholder="<?php esc_attr_e('Enter your API password', 'olgerdin-employee-sync'); ?>">
                                        <p class="description">
                                            <?php echo esc_html('Your Olgerdin API password', 'olgerdin-employee-sync'); ?>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            
                            <p class="submit">
                                <button type="submit" class="button button-primary">
                                    <?php echo esc_html('Authenticate', 'olgerdin-employee-sync'); ?>
                                </button>
                                
                                <?php if ($auth_status['is_authenticated']): ?>
                                    <button type="submit" 
                                            name="oes_action" 
                                            value="clear_token" 
                                            class="button button-secondary"
                                            onclick="return confirm('<?php esc_attr_e('Are you sure you want to clear the token?', 'olgerdin-employee-sync'); ?>');">
                                        <?php echo esc_html('Clear Token', 'olgerdin-employee-sync'); ?>
                                    </button>
                                <?php endif; ?>
                            </p>
                        </form>
                    </div>
                    
                    <?php $last_sync = get_option('oes_last_sync', ''); ?>
                    <?php if ($last_sync): ?>
                        <div class="oes-last-sync">
                            <h3><?php echo esc_html('Last Sync', 'olgerdin-employee-sync'); ?></h3>
                            <p><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_sync))); ?></p>
                        </div>
                    <?php endif; ?>
                    
                <?php elseif ($current_tab === 'sync'): ?>
                    <!-- Sync Tab Content -->
                    <?php if (!$auth_status['token_valid']): ?>
                        <div class="notice notice-warning">
                            <p><?php echo esc_html('Authentication required for new sync operations. You can still view previously synced data below.', 'olgerdin-employee-sync'); ?></p>
                            <p>
                                <a href="?page=<?php echo esc_attr($this->page_slug); ?>&tab=dashboard" class="button button-primary">
                                    <?php echo esc_html('Go to Authentication', 'olgerdin-employee-sync'); ?>
                                </a>
                            </p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="oes-sync-controls">
                        <form method="post">
                            <?php wp_nonce_field('oes_admin_action', 'oes_nonce'); ?>
                            <input type="hidden" name="oes_action" value="sync_employees">
                            
                            <p>
                                <button type="submit" 
                                        class="button button-primary button-large"
                                        <?php echo !$auth_status['token_valid'] ? 'disabled' : ''; ?>>
                                    <?php echo esc_html('Start Employee Sync', 'olgerdin-employee-sync'); ?>
                                </button>
                                
                                <?php if (!$auth_status['token_valid']): ?>
                                    <span class="oes-sync-disabled">
                                        <?php echo esc_html('Authentication required before syncing.', 'olgerdin-employee-sync'); ?>
                                    </span>
                                <?php endif; ?>
                            </p>
                            
                            <p class="description">
                                <?php echo esc_html('This will fetch all employees from the Olgerdin API and sync them with your WordPress database.', 'olgerdin-employee-sync'); ?>
                            </p>
                        </form>
                    </div>
                    
                    <?php if (!empty($last_sync_results)): ?>
                        <div class="oes-sync-results">
                            <h2><?php echo esc_html('Last Sync Results', 'olgerdin-employee-sync'); ?></h2>
                            
                            <div class="oes-results-stats">
                                <div class="oes-stat-box">
                                    <span class="oes-stat-number"><?php echo esc_html($last_sync_results['total']); ?></span>
                                    <span class="oes-stat-label"><?php echo esc_html('Total Employees', 'olgerdin-employee-sync'); ?></span>
                                </div>
                                
                                <div class="oes-stat-box oes-stat-success">
                                    <span class="oes-stat-number"><?php echo esc_html($last_sync_results['inserted']); ?></span>
                                    <span class="oes-stat-label"><?php echo esc_html('Inserted', 'olgerdin-employee-sync'); ?></span>
                                </div>
                                
                                <div class="oes-stat-box oes-stat-updated">
                                    <span class="oes-stat-number"><?php echo esc_html($last_sync_results['updated']); ?></span>
                                    <span class="oes-stat-label"><?php echo esc_html('Updated', 'olgerdin-employee-sync'); ?></span>
                                </div>
                                
                                <div class="oes-stat-box oes-stat-error">
                                    <span class="oes-stat-number"><?php echo esc_html($last_sync_results['failed']); ?></span>
                                    <span class="oes-stat-label"><?php echo esc_html('Failed', 'olgerdin-employee-sync'); ?></span>
                                </div>
                            </div>
                            
                            <?php if (!empty($last_sync_results['errors'])): ?>
                                <div class="oes-sync-errors">
                                    <h3><?php echo esc_html('Errors', 'olgerdin-employee-sync'); ?></h3>
                                    <ul>
                                        <?php foreach ($last_sync_results['errors'] as $error): ?>
                                            <li><?php echo esc_html($error); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Display all synced employees in a table with pagination -->
                    <?php
                    $employees = $this->get_synced_employees($current_page, $per_page);
                    $employee_count = $this->get_employee_count();
                    $total_pages = $this->get_total_pages($per_page);
                    ?>
                    
                    <div class="oes-employees-table">
                        <h2><?php _e('Synced Employees', 'olgerdin-employee-sync'); ?></h2>
                        
                        <?php if ($employee_count > 0): ?>
                            <p class="description">
                                <?php 
                                $start = (($current_page - 1) * $per_page) + 1;
                                $end = min($current_page * $per_page, $employee_count);
                                printf(
                                    __('Showing employees %d-%d of %d total', 'olgerdin-employee-sync'),
                                    $start,
                                    $end,
                                    $employee_count
                                ); 
                                ?>
                            </p>
                            
                            <!-- Pagination top -->
                            <?php //echo $this->generate_pagination($current_page, $total_pages); ?>
                            
                            <div class="oes-table-container">
                                <table class="wp-list-table widefat fixed striped">
                                    <thead>
                                        <tr>
                                            <th><?php _e('ID', 'olgerdin-employee-sync'); ?></th>
                                            <th><?php _e('Name', 'olgerdin-employee-sync'); ?></th>
                                            <th><?php _e('Title', 'olgerdin-employee-sync'); ?></th>
                                            <th><?php _e('Department', 'olgerdin-employee-sync'); ?></th>
                                            <th><?php _e('Email', 'olgerdin-employee-sync'); ?></th>
                                            <th><?php _e('Phone', 'olgerdin-employee-sync'); ?></th>
                                            <th><?php _e('Last Updated', 'olgerdin-employee-sync'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($employees)): ?>
                                            <?php foreach ($employees as $employee): ?>
                                                <tr>
                                                    <td><?php echo esc_html($employee['employee_detail_id']); ?></td>
                                                    <td>
                                                        <?php echo esc_html($employee['name']); ?>
                                                        <?php if (!empty($employee['image_url'])): ?>
                                                            <div class="oes-employee-image">
                                                                <img src="<?php echo esc_url($employee['image_url']); ?>" 
                                                                     alt="<?php echo esc_attr($employee['name']); ?>"
                                                                     style="max-width: 50px; max-height: 50px; border-radius: 4px; margin-top: 5px;">
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo esc_html($employee['title']); ?></td>
                                                    <td><?php echo esc_html($employee['department']); ?></td>
                                                    <td>
                                                        <?php if (!empty($employee['email'])): ?>
                                                            <a href="mailto:<?php echo esc_attr($employee['email']); ?>">
                                                                <?php echo esc_html($employee['email']); ?>
                                                            </a>
                                                        <?php else: ?>
                                                            -
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $phone = !empty($employee['mobile']) ? $employee['mobile'] : $employee['phone'];
                                                        echo esc_html($phone ?: '-'); 
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        echo esc_html(
                                                            date_i18n(
                                                                get_option('date_format') . ' ' . get_option('time_format'),
                                                                strtotime($employee['updated_at'])
                                                            )
                                                        ); 
                                                        ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="oes-no-data">
                                                    <?php echo esc_html('No employees have been synced yet.', 'olgerdin-employee-sync'); ?>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <th><?php echo esc_html('ID', 'olgerdin-employee-sync'); ?></th>
                                            <th><?php echo esc_html('Name', 'olgerdin-employee-sync'); ?></th>
                                            <th><?php echo esc_html('Title', 'olgerdin-employee-sync'); ?></th>
                                            <th><?php echo esc_html('Department', 'olgerdin-employee-sync'); ?></th>
                                            <th><?php echo esc_html('Email', 'olgerdin-employee-sync'); ?></th>
                                            <th><?php echo esc_html('Phone', 'olgerdin-employee-sync'); ?></th>
                                            <th><?php echo esc_html('Last Updated', 'olgerdin-employee-sync'); ?></th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            
                            <!-- Pagination bottom -->
                            <?php echo $this->generate_pagination($current_page, $total_pages); ?>
                            
                        <?php else: ?>
                            <div class="oes-no-data">
                                <p><?php _e('No employees have been synced yet. Use the sync button above to fetch employees from the API.', 'olgerdin-employee-sync'); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                <?php endif; ?>
            </div>
        </div>
     <?php
    }
}