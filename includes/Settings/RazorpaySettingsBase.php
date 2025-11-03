<?php

namespace RazorpayFluentCart\Settings;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

class RazorpaySettingsBase extends BaseGatewaySettings
{
    public $settings;
    public $methodHandler = 'fluent_cart_payment_settings_razorpay';

    public function __construct()
    {
        parent::__construct();
        $settings = $this->getCachedSettings();
        $defaults = static::getDefaults();

        if (!$settings || !is_array($settings) || empty($settings)) {
            $settings = $defaults;
        } else {
            $settings = wp_parse_args($settings, $defaults);
        }

        $this->settings = apply_filters('razorpay_fc/razorpay_settings', $settings);
    }

    public static function getDefaults()
    {
        return [
            'is_active'        => 'no',
            'test_pub_key'     => '',
            'test_secret_key'  => '',
            'live_pub_key'     => '',
            'live_secret_key'  => '',
            'payment_mode'    => 'test',
            'checkout_type'    => 'modal',
            'notification'    => [],
        ];
    }

    public function isActive(): bool
    {
        return $this->settings['is_active'] == 'yes';
    }

    public function get($key = '')
    {
        $settings = $this->settings;

        if ($key && isset($this->settings[$key])) {
            return $this->settings[$key];
        }
        return $settings;
    }

    public function getMode()
    {
        // First check if Razorpay has its own payment_mode setting
        // This allows Razorpay to override FluentCart's store mode if needed
        $razorpayMode = $this->get('payment_mode');
        
        if (!empty($razorpayMode) && ($razorpayMode === 'test' || $razorpayMode === 'live')) {
            return $razorpayMode;
        }
        
        // Fall back to FluentCart store mode
        return (new StoreSettings)->get('order_mode');
    }

    public function getSecretKey($mode = 'current')
    {
        if ($mode == 'current' || !$mode) {
            $mode = $this->getMode();
        }

        if ($mode === 'test') {
            $secretKey = $this->get('test_secret_key');
        } else {
            $secretKey = $this->get('live_secret_key');
        }

        if (empty($secretKey)) {
            return '';
        }

        // Try to decrypt - if decryption fails (returns false), return original value (plaintext)
        $decrypted = Helper::decryptKey($secretKey);
        return $decrypted !== false ? $decrypted : $secretKey;
    }

    public function getPublicKey($mode = 'current')
    {
        if ($mode == 'current' || !$mode) {
            $mode = $this->getMode();
        }

        if ($mode === 'test') {
            return $this->get('test_pub_key');
        } else {
            return $this->get('live_pub_key');
        }
    }

    public function getApiKeys()
    {
        $mode = $this->getMode();
        
        $apiKey = $this->getPublicKey($mode);
        $apiSecret = $this->getSecretKey($mode);
        
        // Ensure keys are not empty
        if (empty($apiKey) || empty($apiSecret)) {
            // For debugging: log what we got
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'Razorpay getApiKeys: Mode=%s, KeyEmpty=%s, SecretEmpty=%s, RawKey=%s, RawSecret=%s',
                    $mode,
                    empty($apiKey) ? 'yes' : 'no',
                    empty($apiSecret) ? 'yes' : 'no',
                    $mode === 'test' ? (empty($this->get('test_pub_key')) ? 'empty' : 'exists') : (empty($this->get('live_pub_key')) ? 'empty' : 'exists'),
                    $mode === 'test' ? (empty($this->get('test_secret_key')) ? 'empty' : 'exists') : (empty($this->get('live_secret_key')) ? 'empty' : 'exists')
                ));
            }
            return [
                'api_key' => '',
                'api_secret' => ''
            ];
        }
        
        return [
            'api_key'    => $apiKey,
            'api_secret' => $apiSecret
        ];
    }
}

