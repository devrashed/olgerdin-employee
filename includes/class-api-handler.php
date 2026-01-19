<?php
/**
 * API handler for Olgerdin Employee Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class OES_API_Handler {
    
    private $api_base_url;
    private $api_data_url;
    private $token_data;
    
    public function __construct() {
        $this->api_base_url = "https://olgerdin-api.starfsmenn.is";
        $this->api_data_url = $this->api_base_url . "/api/HrData/EmployeesSearch";
        $this->token_data = get_option('oes_api_token_data', array());
    }
    
    /**
     * Get stored token data
     */
    public function get_token_data() {
        return $this->token_data;
    }
    
    /**
     * Set token data with expiration calculation
     */
    public function set_token_data($token_response) {
        $token_data = array(
            'access_token' => sanitize_text_field($token_response['access_token']),
            'token_type' => isset($token_response['token_type']) ? sanitize_text_field($token_response['token_type']) : 'bearer',
            'expires_in' => isset($token_response['expires_in']) ? intval($token_response['expires_in']) : 3600,
            'created_at' => time(),
            '.issued' => isset($token_response['.issued']) ? sanitize_text_field($token_response['.issued']) : '',
            '.expires' => isset($token_response['.expires']) ? sanitize_text_field($token_response['.expires']) : '',
        );
        
        $this->token_data = $token_data;
        update_option('oes_api_token_data', $token_data, false);
        
        return $token_data;
    }
    
    /**
     * Get current access token
     */
    public function get_access_token() {
        $token_data = $this->get_token_data();
        return isset($token_data['access_token']) ? $token_data['access_token'] : '';
    }
    
    /**
     * Check if token is expired or about to expire
     */
    public function is_token_expired($buffer_seconds = 300) {
        $token_data = $this->get_token_data();
        
        if (empty($token_data) || !isset($token_data['created_at']) || !isset($token_data['expires_in'])) {
            return true;
        }
        
        $expiry_time = $token_data['created_at'] + $token_data['expires_in'];
        $current_time = time();
        
        return ($current_time + $buffer_seconds) >= $expiry_time;
    }
    
    /**
     * Authenticate with username/password to get new token
     */
    public function authenticate($username, $password) {
        $token_url = $this->api_base_url . "/Token";
        
        $args = array(
            'timeout'     => 30,
            'redirection' => 5,
            'httpversion' => '1.1',
            'headers'     => array(
                'Accept' => 'application/json',
            ),
            'body'        => http_build_query(array(
                'username' => $username,
                'password' => $password,
                'grant_type' => 'password'
            )),
            'sslverify'   => true,
        );
        
        $response = wp_remote_post($token_url, $args);
        
        if (is_wp_error($response)) {
            return new WP_Error('auth_failed', sprintf(
                __('Authentication failed: %s', 'olgerdin-employee-sync'),
                $response->get_error_message()
            ));
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            return new WP_Error('auth_error', sprintf(
                __('Authentication returned error code %d: %s', 'olgerdin-employee-sync'),
                $response_code,
                $response_body
            ));
        }
        
        $token_response = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', __('Failed to parse authentication response.', 'olgerdin-employee-sync'));
        }
        
        if (empty($token_response['access_token'])) {
            return new WP_Error('token_missing', __('No access token received from authentication.', 'olgerdin-employee-sync'));
        }
        
        return $this->set_token_data($token_response);
    }
    
    /**
     * Get valid token - checks if token is valid
     */
    public function get_valid_token() {
        if ($this->is_token_expired()) {
            return false;
        }
        
        return $this->get_access_token();
    }
    
    /**
     * Fetch data from API 
     * 
     */
    public function fetch_employees() {
        $token = $this->get_valid_token();
        
        if (!$token) {
            return new WP_Error('token_expired', 
                __('API token has expired. Please re-authenticate with username and password.', 'olgerdin-employee-sync'),
                array('requires_reauth' => true)
            );
        }
        
        $args = array(
            'timeout'     => 30,
            'redirection' => 5,
            'httpversion' => '1.1',
            'headers'     => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
            'sslverify'   => true,
        );
        
        $response = wp_remote_get($this->api_data_url, $args);
        
        if (is_wp_error($response)) {
            return new WP_Error('api_request_failed', sprintf(
                __('API request failed: %s', 'olgerdin-employee-sync'),
                $response->get_error_message()
            ));
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code === 401) {
            delete_option('oes_api_token_data');
            return new WP_Error('token_expired', 
                __('API token has expired. Please re-authenticate with username and password.', 'olgerdin-employee-sync'),
                array('requires_reauth' => true, 'status' => $response_code)
            );
        }
        
        if ($response_code !== 200) {
            return new WP_Error('api_error', sprintf(
                __('API returned error code %d: %s', 'olgerdin-employee-sync'),
                $response_code,
                wp_remote_retrieve_response_message($response)
            ), array('status' => $response_code));
        }
        
        $data = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', __('Failed to parse API response.', 'olgerdin-employee-sync'));
        }
        
        if (empty($data) || !is_array($data)) {
            return new WP_Error('empty_data', __('No employee data received from API.', 'olgerdin-employee-sync'));
        }
        
        return $data;
    }
    
    /**
     * Validate API token
     */
    public function validate_token() {
        $token = $this->get_access_token();
        
        if (empty($token)) {
            return array(
                'valid' => false,
                'message' => __('No token configured. Please authenticate.', 'olgerdin-employee-sync'),
                'requires_auth' => true
            );
        }
        
        if ($this->is_token_expired()) {
            return array(
                'valid' => false,
                'message' => __('Token has expired. Please re-authenticate.', 'olgerdin-employee-sync'),
                'requires_auth' => true
            );
        }
        
        $token_data = $this->get_token_data();
        $expires_at = $token_data['created_at'] + $token_data['expires_in'];
        $time_left = $expires_at - time();
        
        return array(
            'valid' => true,
            'message' => __('Token is valid.', 'olgerdin-employee-sync'),
            'expires_in' => $this->format_time_remaining($time_left),
            'expires_at' => date('Y-m-d H:i:s', $expires_at)
        );
    }
    
    /**
     * Format time remaining in human readable format
     */
    private function format_time_remaining($seconds) {
        if ($seconds <= 0) {
            return __('Expired', 'olgerdin-employee-sync');
        }
        
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        
        if ($hours > 0) {
            return sprintf(_n('%d hour', '%d hours', $hours, 'olgerdin-employee-sync'), $hours);
        }
        
        return sprintf(_n('%d minute', '%d minutes', $minutes, 'olgerdin-employee-sync'), $minutes);
    }
    
    /**
     * Clear stored token data
     */
    public function clear_token() {
        delete_option('oes_api_token_data');
        $this->token_data = array();
        return true;
    }
    
    /**
     * Get authentication status for display
     */
    public function get_auth_status() {
        $validation = $this->validate_token();
        $token_data = $this->get_token_data();
        
        $status = array(
            'is_authenticated' => !empty($token_data),
            'token_valid' => $validation['valid'],
            'message' => $validation['message'],
            'requires_auth' => isset($validation['requires_auth']) ? $validation['requires_auth'] : false,
        );
        
        if (isset($validation['expires_in'])) {
            $status['expires_in'] = $validation['expires_in'];
        }
        
        if (isset($validation['expires_at'])) {
            $status['expires_at'] = $validation['expires_at'];
        }
        
        return $status;
    }
    
    /**
     * Process and sync all employees with token management
     */
    public function sync_employees() {
        $employees = $this->fetch_employees();
        
        if (is_wp_error($employees)) {
            return $employees;
        }
        
        $results = array(
            'total' => 0,
            'inserted' => 0,
            'updated' => 0,
            'failed' => 0,
            'errors' => array()
        );
        
        foreach ($employees as $employee) {
            $results['total']++;
            
            if (empty($employee['EmployeeDetailID'])) {
                $results['failed']++;
                $results['errors'][] = sprintf(
                    __('Employee skipped: Missing EmployeeDetailID at index %d', 'olgerdin-employee-sync'),
                    $results['total'] - 1
                );
                continue;
            }
            
            $result = OES_Database::save_employee($employee);
            
            if ($result === 'inserted') {
                $results['inserted']++;
            } elseif ($result === 'updated') {
                $results['updated']++;
            } else {
                $results['failed']++;
                $results['errors'][] = sprintf(
                    __('Failed to save employee %d: %s', 'olgerdin-employee-sync'),
                    $employee['EmployeeDetailID'],
                    $result
                );
            }
            
            if ($results['total'] % 10 === 0) {
                usleep(100000);
            }
        }
        
        update_option('oes_last_sync', current_time('mysql', 1), false);
        update_option('oes_last_sync_results', $results, false);
        
        return $results;
    }
}