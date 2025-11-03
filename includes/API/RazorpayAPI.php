<?php

namespace RazorpayFluentCart\API;

use FluentCart\Framework\Support\Arr;
use RazorpayFluentCart\Settings\RazorpaySettingsBase;

class RazorpayAPI
{
    private $baseUrl = 'https://api.razorpay.com/v1';
    private $settings;

    public function __construct()
    {
        $this->settings = new RazorpaySettingsBase();
    }

    /**
     * Make API request to Razorpay
     */
    public function makeApiCall($path, $args = [], $method = 'GET')
    {
        $keys = $this->settings->getApiKeys();
        $mode = $this->settings->getMode();
        
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
                    $path,
                    $mode,
                    substr($apiKey, 0, 10)
                ),
                'info'
            );
        }
        
        $headers = [
            'Authorization' => 'Basic ' . base64_encode($apiKey . ':' . $apiSecret),
            'Content-type' => 'application/json'
        ];

        // Match reference format exactly
        if ($method === 'POST') {
            $url = 'https://api.razorpay.com/v1/' . $path . '/';
            $response = wp_remote_post($url, [
                'headers' => $headers,
                'body' => !empty($args) ? json_encode($args) : ''
            ]);
        } else {
            $url = 'https://api.razorpay.com/v1/' . $path . '/';
            $response = wp_remote_get($url, [
                'headers' => $headers,
                'body' => $args
            ]);
        }

        dd($response, [
            'headers' => $headers,
            'body' => !empty($args) ? json_encode($args) : ''
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $responseData = json_decode($body, true);

        // Check for error in response (match reference exactly)
        if (!empty($responseData['error'])) {
            $message = Arr::get($responseData, 'error.description');
            if (!$message) {
                $message = 'Unknown RazorPay API request error';
            }
            return new \WP_Error(423, $message, $responseData);
        }

        return $responseData;
    }

    /**
     * Create order
     */
    public function createOrder($data)
    {
        return $this->makeApiCall('orders', $data, 'POST');
    }

    /**
     * Get payment
     */
    public function getPayment($paymentId)
    {
        return $this->makeApiCall('payments/' . $paymentId, [], 'GET');
    }

    /**
     * Create payment link (for hosted checkout)
     */
    public function createPaymentLink($data)
    {
        return $this->makeApiCall('payment_links', $data, 'POST');
    }

    /**
     * Capture a payment (changes status from authorized to captured)
     * 
     * @param string $paymentId The payment ID to capture
     * @param int $amount The amount to capture (in smallest currency unit, e.g., paise for INR)
     * @param string $currency The currency code (e.g., 'INR')
     * @return array|\WP_Error
     */
    public function capturePayment($paymentId, $amount, $currency)
    {
        $captureData = [
            'amount' => intval($amount),
            'currency' => strtoupper($currency)
        ];

        return $this->makeApiCall('payments/' . $paymentId . '/capture', $captureData, 'POST');
    }

    /**
     * Create refund
     */
    public function createRefund($paymentId, $amount)
    {
        $refundData = [
            'amount' => $amount // Amount in paise (smallest currency unit)
        ];

        return $this->makeApiCall('payments/' . $paymentId . '/refund', $refundData, 'POST');
    }
}

