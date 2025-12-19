<?php
/**
 * Razorpay API Handler
 *
 * @package RazorpayFluentCart
 * @since 1.0.0
 */

namespace RazorpayFluentCart\API;

use FluentCart\Framework\Support\Arr;
use RazorpayFluentCart\Settings\RazorpaySettingsBase;

if (!defined('ABSPATH')) {
    exit; // Direct access not allowed.
}

class RazorpayAPI
{
    private static $baseUrl = 'https://api.razorpay.com/v1/';
    private static $settings = null;

    /**
     * Get settings instance
     */
    public static function getSettings()
    {
        if (!self::$settings) {
            self::$settings = new RazorpaySettingsBase();
        }
        return self::$settings;
    }

    /**
     * Make API request to Razorpay
     */
    private static function request($endpoint, $method = 'GET', $data = [])
    {
        // Input validation
        if (empty($endpoint) || !is_string($endpoint)) {
            return new \WP_Error('invalid_endpoint', 'Invalid API endpoint provided');
        }

        // Validate HTTP method
        $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE'];
        if (!in_array(strtoupper($method), $allowedMethods, true)) {
            return new \WP_Error('invalid_method', 'Invalid HTTP method');
        }

        $keys = self::getSettings()->getApiKeys();
        $mode = self::getSettings()->getMode();
        
        // Validate keys are not empty
        if (empty($keys['api_key']) || empty($keys['api_secret'])) {
            return new \WP_Error(
                'razorpay_auth_error',
                sprintf(
                    __('Razorpay API keys are not configured for %s mode. Please check your settings.', 'razorpay-for-fluent-cart'),
                    $mode
                ),
                [
                    'mode' => $mode,
                    'has_api_key' => !empty($keys['api_key']),
                    'has_api_secret' => !empty($keys['api_secret'])
                ]
            );
        }

        // Trim any whitespace from keys
        $apiKey = trim($keys['api_key']);
        $apiSecret = trim($keys['api_secret']);
        
        if (empty($apiKey) || empty($apiSecret)) {
            return new \WP_Error(
                'razorpay_auth_error',
                sprintf(
                    __('Razorpay API keys are empty for %s mode. Please check your settings in FluentCart > Settings > Payment Methods > Razorpay.', 'razorpay-for-fluent-cart'),
                    $mode
                ),
                [
                    'api_key_length' => strlen($apiKey),
                    'api_secret_length' => strlen($apiSecret),
                    'mode' => $mode,
                    'raw_key_exists' => !empty($keys['api_key']),
                    'raw_secret_exists' => !empty($keys['api_secret'])
                ]
            );
        }
        
        // Validate key format (Razorpay keys typically start with rzp_)
        if (strpos($apiKey, 'rzp_') !== 0) {
            return new \WP_Error(
                'razorpay_auth_error',
                __('Invalid Razorpay Public Key format. Keys should start with "rzp_test_" or "rzp_live_".', 'razorpay-for-fluent-cart'),
                [
                    'api_key_prefix' => substr($apiKey, 0, 10),
                    'mode' => $mode
                ]
            );
        }
        
        // Debug: Log what we're sending (without exposing full secret)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            fluent_cart_add_log(
                'Razorpay API Call',
                sprintf(
                    'Calling: %s %s | Mode: %s | Key: %s...',
                    $method,
                    $endpoint,
                    $mode,
                    substr($apiKey, 0, 10)
                ),
                'info'
            );
        }
        
        $url = self::$baseUrl . $endpoint;
        
        $args = [
            'method'  => strtoupper($method),
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($apiKey . ':' . $apiSecret),
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'RazorpayFluentCart/1.0.0 WordPress/' . get_bloginfo('version'),
            ],
            'timeout' => 30,
            'sslverify' => true, // Always verify SSL
        ];

        if ($method === 'POST' && !empty($data)) {
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $responseData = json_decode($body, true);

        $statusCode = wp_remote_retrieve_response_code($response);
        
        // Check for error in response
        if ($statusCode >= 400 || !empty($responseData['error'])) {
            $message = Arr::get($responseData, 'error.description');
            if (!$message) {
                $message = 'Unknown Razorpay API request error';
            }
            return new \WP_Error(
                'razorpay_api_error',
                $message,
                ['status' => $statusCode, 'response' => $responseData]
            );
        }

        return $responseData;
    }

    /**
     * Get Razorpay object (for GET requests)
     */
    public static function getRazorpayObject($endpoint, $params = [])
    {
        return self::request($endpoint, 'GET', $params);
    }

    /**
     * Create Razorpay object (for POST requests)
     */
    public static function createRazorpayObject($endpoint, $data = [])
    {
        return self::request($endpoint, 'POST', $data);
    }

    /**
     * Delete/Update Razorpay object (for POST requests used for deletion)
     */
    public static function deleteRazorpayObject($endpoint, $data = [])
    {
        return self::request($endpoint, 'POST', $data);
    }
}
