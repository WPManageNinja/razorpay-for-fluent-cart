<?php

namespace RazorpayFluentCart;

use FluentCart\Api\CurrencySettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Services\PluginInstaller\PaymentAddonManager;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;

class RazorpayGateway extends AbstractPaymentGateway
{
    private $methodSlug = 'razorpay';
    private $addonSlug = 'razorpay-for-fluent-cart';
    private $addonFile = 'razorpay-for-fluent-cart/razorpay-for-fluent-cart.php';

    public array $supportedFeatures = [
        'payment',
        'refund',
        'webhook'
    ];

    public function __construct()
    {
        parent::__construct(
            new Settings\RazorpaySettingsBase(), 
            null // No subscription support
        );
    }

    public function meta(): array
    {
        $logo = RAZORPAY_FC_PLUGIN_URL . 'assets/images/razorpay-logo.svg';

        $addonStatus = PaymentAddonManager::getAddonStatus($this->addonSlug, $this->addonFile);
        
        return [
            'title'              => __('Razorpay', 'razorpay-for-fluent-cart'),
            'route'              => $this->methodSlug,
            'slug'               => $this->methodSlug,
            'label'              => 'Razorpay',
            'admin_title'        => 'Razorpay',
            'description'        => __('Pay securely with Razorpay - Cards, UPI, Netbanking, Wallets, and more', 'razorpay-for-fluent-cart'),
            'logo'               => $logo,
            'icon'               => $logo,
            'brand_color'        => '#3399cc',
            'status'             => $this->settings->get('is_active') === 'yes',
            'upcoming'           => false,
            'is_addon'           => true,
            'addon_status'       => $addonStatus,
            'addon_source'       => [
                'type' => 'github',
                'link' => 'https://github.com/WPManageNinja/razorpay-for-fluent-cart/releases/latest',
                'slug' => $this->addonSlug,
                'file' => $this->addonFile,
                'is_installed' => true
            ],
            'supported_features' => $this->supportedFeatures
        ];
    }

    public function boot()
    {
        // Initialize IPN handler
        (new Webhook\RazorpayWebhook())->init();
        
        add_filter('fluent_cart/payment_methods/razorpay_settings', [$this, 'getSettings'], 10, 2);

        (new Confirmations\RazorpayConfirmations())->init();
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        $paymentArgs = [
            'success_url' => $this->getSuccessUrl($paymentInstance->transaction),
            'cancel_url'  => $this->getCancelUrl(),
        ];

        return (new Onetime\RazorpayProcessor())->handleSinglePayment($paymentInstance, $paymentArgs);
    }

    public function getOrderInfo($data)
    {
        $this->checkCurrencySupport();

        wp_send_json([
            'status'       => 'success',
            'message'      => __('Order info retrieved!', 'razorpay-for-fluent-cart'),
            'data'         => [],
            'payment_args' => [
                'key' => $this->settings->getPublicKey(),
                'checkout_type' => $this->settings->get('checkout_type')
            ],
        ], 200);
    }

    public function checkCurrencySupport()
    {
        $currency = CurrencySettings::get('currency');

        if (!in_array(strtoupper($currency), self::getRazorpaySupportedCurrency())) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Razorpay does not support the currency you are using!', 'razorpay-for-fluent-cart')
            ], 422);
        }

        // Check if using international currency and add helpful note
        if (strtoupper($currency) !== 'INR') {
            $mode = $this->settings->get('payment_mode', 'test');
            $modeText = $mode === 'live' ? 'live' : 'test';
            
            fluent_cart_add_log(
                'Razorpay International Currency', 
                sprintf(
                    'Using %s currency. International payments must be enabled in Razorpay Dashboard (Settings > Configuration > Payment Methods) for %s mode.',
                    $currency,
                    $modeText
                ), 
                'info'
            );
        }
    }

    public static function getRazorpaySupportedCurrency(): array
    {
        return $razorpay_supported_currencies = [
            'AED', // United Arab Emirates Dirham
            'ALL', // Albanian Lek
            'AMD', // Armenian Dram
            'ARS', // Argentine Peso
            'AUD', // Australian Dollar
            'AWG', // Aruban Florin
            'BBD', // Barbadian Dollar
            'BDT', // Bangladeshi Taka
            'BMD', // Bermudian Dollar
            'BND', // Brunei Dollar
            'BOB', // Bolivian Boliviano
            'BSD', // Bahamian Dollar
            'BWP', // Botswana Pula
            'BZD', // Belize Dollar
            'CAD', // Canadian Dollar
            'CHF', // Swiss Franc
            'CNY', // Chinese Yuan
            'COP', // Colombian Peso
            'CRC', // Costa Rican Colon
            'CZK', // Czech Koruna
            'DKK', // Danish Krone
            'DOP', // Dominican Peso
            'EUR', // Euro
            'FJD', // Fijian Dollar
            'GBP', // British Pound Sterling
            'GHS', // Ghanaian Cedi
            'GIP', // Gibraltar Pound
            'GMD', // Gambian Dalasi
            'GTQ', // Guatemalan Quetzal
            'GYD', // Guyanese Dollar
            'HKD', // Hong Kong Dollar
            'HNL', // Honduran Lempira
            'HRK', // Croatian Kuna
            'HTG', // Haitian Gourde
            'HUF', // Hungarian Forint
            'IDR', // Indonesian Rupiah
            'ILS', // Israeli New Shekel
            'INR', // Indian Rupee
            'JPY', // Japanese Yen
            'KRW', // South Korean Won
            'KWD', // Kuwaiti Dinar
            'LKR', // Sri Lankan Rupee
            'MAD', // Moroccan Dirham
            'MXN', // Mexican Peso
            'MYR', // Malaysian Ringgit
            'NGN', // Nigerian Naira
            'NOK', // Norwegian Krone
            'NZD', // New Zealand Dollar
            'PEN', // Peruvian Sol
            'PHP', // Philippine Peso
            'PKR', // Pakistani Rupee
            'PLN', // Polish Zloty
            'QAR', // Qatari Riyal
            'RON', // Romanian Leu
            'RUB', // Russian Ruble
            'SAR', // Saudi Riyal
            'SEK', // Swedish Krona
            'SGD', // Singapore Dollar
            'THB', // Thai Baht
            'TRY', // Turkish Lira
            'TWD', // Taiwan Dollar
            'UAH', // Ukrainian Hryvnia
            'USD', // United States Dollar
            'UYU', // Uruguayan Peso
            'UZS', // Uzbekistani Soʻm
            'YER', // Yemeni Rial
        ];
        
        
    }

    public function handleIPN(): void
    {
        (new Webhook\RazorpayWebhook())->verifyAndProcess();
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [
            [
                'handle' => 'fluent-cart-razorpay-checkout',
                'src' => 'https://checkout.razorpay.com/v1/checkout.js',
            ],
            [
                'handle' => 'fluent-cart-razorpay-custom',
                'src' => RAZORPAY_FC_PLUGIN_URL . 'assets/razorpay-checkout.js',
                'version' => RAZORPAY_FC_VERSION,
                'deps' => ['fluent-cart-razorpay-checkout']
            ]
        ];
    }

    public function getEnqueueStyleSrc(): array
    {
        return [];
    }

    public function getLocalizeData(): array
    {
        return [
            'fct_razorpay_data' => [
                'public_key' => $this->settings->getPublicKey(),
                'checkout_type' => $this->settings->get('checkout_type'),
                'ajax_url' => admin_url('admin-ajax.php'),
                'translations' => [
                    'Processing payment...' => __('Processing payment...', 'razorpay-for-fluent-cart'),
                    'Pay Now' => __('Pay Now', 'razorpay-for-fluent-cart'),
                    'Place Order' => __('Place Order', 'razorpay-for-fluent-cart'),
                    'Payment Modal is opening...' => __('Payment Modal is opening, Please complete the payment', 'razorpay-for-fluent-cart'),
                ]
            ]
        ];
    }

    public function webHookPaymentMethodName()
    {
        return $this->getMeta('route');
    }

    public function getTransactionUrl($url, $data): string
    {
        $transaction = Arr::get($data, 'transaction', null);
        if (!$transaction || !$transaction->vendor_charge_id) {
            return 'https://dashboard.razorpay.com/app/payments';
        }

        return 'https://dashboard.razorpay.com/app/payments/' . $transaction->vendor_charge_id;
    }

    public function processRefund($transaction, $amount, $args)
    {
        if (!$amount) {
            return new \WP_Error(
                'razorpay_refund_error',
                __('Refund amount is required.', 'razorpay-for-fluent-cart')
            );
        }

        return (new Refund\RazorpayRefund())->processRemoteRefund($transaction, $amount, $args);
    }


    public function fields(): array
    {
        $webhook_url = site_url('?fluent-cart=fct_payment_listener_ipn&method=razorpay');
        
        return [
            'notice' => [
                'value' => $this->renderStoreModeNotice(),
                'label' => __('Store Mode notice', 'razorpay-for-fluent-cart'),
                'type'  => 'notice'
            ],
            'payment_mode' => [
                'type'   => 'tabs',
                'schema' => [
                    [
                        'type'   => 'tab',
                        'label'  => __('Live credentials', 'razorpay-for-fluent-cart'),
                        'value'  => 'live',
                        'schema' => [
                            'live_pub_key' => [
                                'value'       => '',
                                'label'       => __('Live Public Key', 'razorpay-for-fluent-cart'),
                                'type'        => 'text',
                                'placeholder' => __('rzp_live_xxxxxxxxxxxxx', 'razorpay-for-fluent-cart'),
                            ],
                            'live_secret_key' => [
                                'value'       => '',
                                'label'       => __('Live Secret Key', 'razorpay-for-fluent-cart'),
                                'type'        => 'password',
                                'placeholder' => __('Your live secret key', 'razorpay-for-fluent-cart'),
                            ],
                        ]
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => __('Test credentials', 'razorpay-for-fluent-cart'),
                        'value'  => 'test',
                        'schema' => [
                            'test_pub_key' => [
                                'value'       => '',
                                'label'       => __('Test Public Key', 'razorpay-for-fluent-cart'),
                                'type'        => 'text',
                                'placeholder' => __('rzp_test_xxxxxxxxxxxxx', 'razorpay-for-fluent-cart'),
                            ],
                            'test_secret_key' => [
                                'value'       => '',
                                'label'       => __('Test Secret Key', 'razorpay-for-fluent-cart'),
                                'type'        => 'password',
                                'placeholder' => __('Your test secret key', 'razorpay-for-fluent-cart'),
                            ],
                        ],
                    ],
                ]
            ],
            'international_payments_notice' => [
                'value' => sprintf(
                    '<div style="padding: 12px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; margin: 10px 0;">
                        <strong>%s</strong><br/>
                        %s
                        <ul style="margin: 8px 0 0 20px;">
                            <li>%s</li>
                            <li>%s</li>
                            <li>%s</li>
                            <li>%s</li>
                        </ul>
                        <p style="margin: 8px 0 0 0;"><strong>%s</strong> %s</p>
                    </div>',
                    __('💡 International Payments', 'razorpay-for-fluent-cart'),
                    __('If you are using currencies other than INR (like USD, EUR, GBP, etc.), you must:', 'razorpay-for-fluent-cart'),
                    __('Enable International Payments in Razorpay Dashboard', 'razorpay-for-fluent-cart'),
                    __('Go to Settings > Configuration > Payment Methods', 'razorpay-for-fluent-cart'),
                    __('Enable "International" toggle', 'razorpay-for-fluent-cart'),
                    __('This is required even in TEST mode', 'razorpay-for-fluent-cart'),
                    __('For Testing:', 'razorpay-for-fluent-cart'),
                    __('Use INR currency for fastest testing, or enable international payments as mentioned above.', 'razorpay-for-fluent-cart')
                ),
                'label' => '',
                'type'  => 'html_attr'
            ],
            'notification' => [
                'value'   => [],
                'label'   => __('Razorpay Notifications', 'razorpay-for-fluent-cart'),
                'type'    => 'checkbox_group',
                'options' => [
                    'sms'   => __('SMS', 'razorpay-for-fluent-cart'),
                    'email' => __('Email', 'razorpay-for-fluent-cart')
                ],
                'tooltip' => __('Select if you want to enable SMS and Email notifications from Razorpay', 'razorpay-for-fluent-cart')
            ],
            'webhook_secret' => [
                'value'       => '',
                'label'       => __('Webhook Secret', 'razorpay-for-fluent-cart'),
                'type'        => 'password',
                'placeholder' => __('Your webhook secret key', 'razorpay-for-fluent-cart'),
                'tooltip'     => __('Enter the webhook secret from your Razorpay Dashboard (Settings > Webhooks). This is used to verify incoming webhooks.', 'razorpay-for-fluent-cart')
            ],
            'webhook_info' => [
                'value' => sprintf(
                    '<div><p><b>%s</b><code class="copyable-content">%s</code></p><p>%s</p></div>',
                    __('Webhook URL: ', 'razorpay-for-fluent-cart'),
                    $webhook_url,
                    __('Configure this webhook URL in your Razorpay Dashboard under Settings > Webhooks to receive payment notifications. Make sure to also configure the Webhook Secret above for security.', 'razorpay-for-fluent-cart')
                ),
                'label' => __('Webhook Configuration', 'razorpay-for-fluent-cart'),
                'type'  => 'html_attr'
            ],
        ];
    }

    public static function validateSettings($data): array
    {
        $mode = Arr::get($data, 'payment_mode', 'test');
        $errors = [];

        if ($mode == 'test') {
            if (empty(Arr::get($data, 'test_pub_key')) || empty(Arr::get($data, 'test_secret_key'))) {
                $errors['test_pub_key'] = __('Please provide Test Public Key and Test Secret Key', 'razorpay-for-fluent-cart');
            }
        }

        if ($mode == 'live') {
            if (empty(Arr::get($data, 'live_pub_key')) || empty(Arr::get($data, 'live_secret_key'))) {
                $errors['live_pub_key'] = __('Please provide Live Public Key and Live Secret Key', 'razorpay-for-fluent-cart');
            }
        }

        return $errors;
    }

    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        $mode = Arr::get($data, 'payment_mode', 'test');

        // Only encrypt if key is not empty and not already encrypted
        if ($mode == 'test') {
            if (!empty($data['test_secret_key'])) {
                $data['test_secret_key'] = Helper::encryptKey($data['test_secret_key']);
            }
        } else {
            if (!empty($data['live_secret_key'])) {
                $data['live_secret_key'] = Helper::encryptKey($data['live_secret_key']);
            }
        }

        return $data;
    }

    public static function register(): void
    {
        fluent_cart_api()->registerCustomPaymentMethod('razorpay', new self());
    }
}

