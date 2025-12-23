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
            'test_api_key'     => '',
            'test_key_secret'  => '',
            'live_api_key'     => '',
            'live_key_secret'  => '',
            'payment_mode'    => 'live',
            'checkout_type'    => 'modal',
            'notification'    => [],
            'test_webhook_secret' => '',
            'live_webhook_secret' => '',
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
        // Fall back to FluentCart store mode
        return (new StoreSettings)->get('order_mode');
    }

    public function getKeySecret($mode = 'current')
    {
        if ($mode == 'current' || !$mode) {
            $mode = $this->getMode();
        }

        if ($mode === 'test') {
            $keySecret = $this->get('test_key_secret');
        } else {
            $keySecret = $this->get('live_key_secret');
        }

        if (empty($keySecret)) {
            return '';
        }

        // Try to decrypt - if decryption fails (returns false), return original value (plaintext)
        $decrypted = Helper::decryptKey($keySecret);
        return $decrypted !== false ? $decrypted : $keySecret;
    }

    public function getApiKey($mode = 'current')
    {
        if ($mode == 'current' || !$mode) {
            $mode = $this->getMode();
        }

        if ($mode === 'test') {
            return $this->get('test_api_key');
        } else {
            return $this->get('live_api_key');
        }
    }

    public function getWebhookSecret($mode = 'current')
    {
        if ($mode == 'current' || !$mode) {
            $mode = $this->getMode();
        }

        if ($mode === 'test') {
            return $this->get('test_webhook_secret');
        } else {
            return $this->get('live_webhook_secret');
        }
    }

    public function getApiKeys()
    {
        $mode = $this->getMode();
        
        $apiKey = $this->getApiKey($mode);
        $apiSecret = $this->getKeySecret($mode);
        
        // Ensure keys are not empty
        if (empty($apiKey) || empty($apiSecret)) {
            // For debugging: log what we got
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'Razorpay getApiKeys: Mode=%s, KeyEmpty=%s, SecretEmpty=%s, RawKey=%s, RawSecret=%s',
                    $mode,
                    empty($apiKey) ? 'yes' : 'no',
                    empty($apiSecret) ? 'yes' : 'no',
                    $mode === 'test' ? (empty($this->get('test_api_key')) ? 'empty' : 'exists') : (empty($this->get('live_api_key')) ? 'empty' : 'exists'),
                    $mode === 'test' ? (empty($this->get('test_key_secret')) ? 'empty' : 'exists') : (empty($this->get('live_key_secret')) ? 'empty' : 'exists')
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

