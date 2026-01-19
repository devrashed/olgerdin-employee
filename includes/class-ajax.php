<?php
/**
 * AJAX handlers for Olgerdin Employee Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class OES_AJAX {
    
    public function __construct() {
        add_action('wp_ajax_oes_sync_employees', array($this, 'handle_sync_employees'));
        add_action('wp_ajax_oes_validate_token', array($this, 'handle_validate_token'));
    }
    
    /**
     * Handle employee sync AJAX request
     */
    public function handle_sync_employees() {
        // Verify nonce
        if (!check_ajax_referer('oes_sync_nonce', 'nonce', false)) {
            wp_die(json_encode(array(
                'success' => false,
                'message' => __('Security check failed.', 'olgerdin-employee-sync')
            )));
        }
        
        // Check user capability
        if (!current_user_can('manage_options')) {
            wp_die(json_encode(array(
                'success' => false,
                'message' => __('You do not have permission to perform this action.', 'olgerdin-employee-sync')
            )));
        }
        
        // Initialize API handler and sync
        $api_handler = new OES_API_Handler();
        $results = $api_handler->sync_employees();
        
        if (is_wp_error($results)) {
            wp_send_json_error(array(
                'message' => $results->get_error_message()
            ));
        }
        
        // Log results if enabled
        if (get_option('oes_logging_enabled', true)) {
            $this->log_sync_results($results);
        }
        
        // Prepare response
        $response = array(
            'success' => true,
            'message' => sprintf(
                __('Sync completed successfully! Processed: %d, Inserted: %d, Updated: %d, Failed: %d', 'olgerdin-employee-sync'),
                $results['total'],
                $results['inserted'],
                $results['updated'],
                $results['failed']
            ),
            'results' => $results,
            'summary_html' => $this->get_results_summary_html($results)
        );
        
        wp_send_json_success($response);
    }
    
    /**
     * Handle token validation AJAX request
     */
    public function handle_validate_token() {
        // Verify nonce
        if (!check_ajax_referer('oes_sync_nonce', 'nonce', false)) {
            wp_die(json_encode(array(
                'success' => false,
                'message' => __('Security check failed.', 'olgerdin-employee-sync')
            )));
        }
        
        // Check user capability
        if (!current_user_can('manage_options')) {
            wp_die(json_encode(array(
                'success' => false,
                'message' => __('You do not have permission to perform this action.', 'olgerdin-employee-sync')
            )));
        }
        
        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
        
        if (empty($token)) {
            wp_send_json_error(array(
                'message' => __('Token is empty.', 'olgerdin-employee-sync')
            ));
        }
        
        $api_handler = new OES_API_Handler();
        $is_valid = $api_handler->validate_token($token);
        
        if ($is_valid) {
            wp_send_json_success(array(
                'message' => __('Token is valid and API is accessible.', 'olgerdin-employee-sync')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Token is invalid or API is not accessible.', 'olgerdin-employee-sync')
            ));
        }
    }
    
    /**
     * Log sync results
     */
    private function log_sync_results($results) {
        $log_entry = sprintf(
            '[%s] Sync completed. Total: %d, Inserted: %d, Updated: %d, Failed: %d',
            current_time('mysql'),
            $results['total'],
            $results['inserted'],
            $results['updated'],
            $results['failed']
        );
        
        if (!empty($results['errors'])) {
            $log_entry .= "\nErrors:\n" . implode("\n", $results['errors']);
        }
        
        error_log($log_entry);
    }
    
    /**
     * Generate HTML summary of sync results
     */
    private function get_results_summary_html($results) {
        ob_start();
        ?>
        <div class="oes-results-summary">
            <h3><?php esc_html_e('Sync Results', 'olgerdin-employee-sync'); ?></h3>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Total Processed', 'olgerdin-employee-sync'); ?></th>
                        <th><?php esc_html_e('New Records', 'olgerdin-employee-sync'); ?></th>
                        <th><?php esc_html_e('Updated Records', 'olgerdin-employee-sync'); ?></th>
                        <th><?php esc_html_e('Failed', 'olgerdin-employee-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo esc_html($results['total']); ?></td>
                        <td class="oes-success"><?php echo esc_html($results['inserted']); ?></td>
                        <td class="oes-updated"><?php echo esc_html($results['updated']); ?></td>
                        <td class="oes-error"><?php echo esc_html($results['failed']); ?></td>
                    </tr>
                </tbody>
            </table>
            
            <?php if (!empty($results['errors'])) : ?>
            <div class="oes-results-errors">
                <h4><?php esc_html_e('Errors:', 'olgerdin-employee-sync'); ?></h4>
                <ul>
                    <?php foreach (array_slice($results['errors'], 0, 5) as $error) : ?>
                        <li><?php echo esc_html($error); ?></li>
                    <?php endforeach; ?>
                    <?php if (count($results['errors']) > 5) : ?>
                        <li><?php printf(esc_html__('... and %d more errors', 'olgerdin-employee-sync'), count($results['errors']) - 5); ?></li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}