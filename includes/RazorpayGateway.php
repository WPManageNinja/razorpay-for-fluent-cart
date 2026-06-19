<?php

namespace RazorpayFluentCart;

use FluentCart\Api\CurrencySettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Helpers\Status;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Services\PluginInstaller\PaymentAddonManager;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use RazorpayFluentCart\Subscription\RazorpaySubscriptions;
use RazorpayFluentCart\Subscription\RazorpaySubscriptionProcessor;

class RazorpayGateway extends AbstractPaymentGateway
{
    private $methodSlug = 'razorpay';
    private $addonSlug = 'razorpay-for-fluent-cart';
    private $addonFile = 'razorpay-for-fluent-cart/razorpay-for-fluent-cart.php';

    public array $supportedFeatures = [
        'payment',
        'refund',
        'webhook',
        'subscription'
    ];

    public function __construct()
    {
        parent::__construct(
            new Settings\RazorpaySettingsBase(),
            new RazorpaySubscriptions()
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

        // Check if the payment instance is a subscription
        if ($paymentInstance->subscription) {
            return (new RazorpaySubscriptionProcessor())->handleSubscription($paymentInstance, $paymentArgs);
        }

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
                'api_key' => $this->settings->getApiKey('current'),
                'checkout_type' => $this->settings->get('checkout_type')
            ],
        ], 200);
    }

    public function checkCurrencySupport()
    {
        $currency = CurrencySettings::get('currency');

        if (!RazorpayHelper::checkCurrencySupport()) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Razorpay does not support the currency you are using!', 'razorpay-for-fluent-cart')
            ], 422);
        }
    }

    public function isCurrencySupported(): bool
    {
        return (RazorpayHelper::checkCurrencySupport());
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
                'api_key' => $this->settings->getApiKey(),
                'checkout_type' => $this->settings->get('checkout_type'),
                'ajax_url' => admin_url('admin-ajax.php'),
                'confirm_nonce' => wp_create_nonce('fluent_cart_razorpay_nonce'),
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

        if ($transaction->status === Status::TRANSACTION_REFUNDED) {
            $parentTransaction = OrderTransaction::query()
                ->where('id', Arr::get($transaction->meta, 'parent_id'))
                ->first();
            if ($parentTransaction) {
                return 'https://dashboard.razorpay.com/app/payments/' . $parentTransaction->vendor_charge_id;
            }
        }

        return 'https://dashboard.razorpay.com/app/payments/' . $transaction->vendor_charge_id;
    }

    public function getSubscriptionUrl($url, $data): string
    {
        $subscription = Arr::get($data, 'subscription', null);
        $url = 'https://dashboard.razorpay.com/app/subscriptions';
        if (!$subscription || !$subscription->vendor_subscription_id) {
            return $url;
        }

        return $url . '/' . $subscription->vendor_subscription_id;
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

    public function getWebhookInstructions(): array
    { 
        $webhook_url = site_url('?fluent-cart=fct_payment_listener_ipn&method=razorpay');
   
        return [
            'title'       => __('Webhook URL', 'razorpay-for-fluent-cart'),
            'webhook_url' => esc_url($webhook_url),
            'description' => __('Configure this webhook URL with secret in your Razorpay Dashboard.', 'razorpay-for-fluent-cart'),
            'steps'       => [
                'title' => __('How to configure?', 'razorpay-for-fluent-cart'),
                'list'  => [
                    __('In your Razorpay Dashboard under Settings &rarr; Webhooks', 'razorpay-for-fluent-cart'),
                ],
            ],
            'events' => [
                'title' => __('Required Webhook Events', 'razorpay-for-fluent-cart'),
                'list'  => [
                    'payment.authorized',
                    'payment.captured',
                    'payment.failed',
                    'refund.processed',
                    'subscription.authenticated',
                    'subscription.activated',
                    'invoice.paid',
                    'subscription.cancelled',
                    'subscription.halted',
                    'subscription.completed',
                ],
            ],
            
        ];

    }


    public function fields(): array
    {
        
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
                            'live_api_key' => [
                                'value'       => '',
                                'label'       => __('Live API Key', 'razorpay-for-fluent-cart'),
                                'type'        => 'text',
                                'placeholder' => __('rzp_live_xxxxxxxxxxxxx', 'razorpay-for-fluent-cart'),
                            ],
                            'live_key_secret' => [
                                'value'       => '',
                                'label'       => __('Live Key Secret', 'razorpay-for-fluent-cart'),
                                'type'        => 'password',
                                'placeholder' => __('Your live key secret', 'razorpay-for-fluent-cart'),
                            ],
                            'live_webhook_secret' => [
                                'value'       => '',
                                'label'       => __('Live Webhook Secret', 'razorpay-for-fluent-cart'),
                                'type'        => 'password',
                                'placeholder' => __('Your live webhook secret', 'razorpay-for-fluent-cart'),
                            ],
                        ]
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => __('Test credentials', 'razorpay-for-fluent-cart'),
                        'value'  => 'test',
                        'schema' => [
                            'test_api_key' => [
                                'value'       => '',
                                'label'       => __('Test API Key', 'razorpay-for-fluent-cart'),
                                'type'        => 'text',
                                'placeholder' => __('rzp_test_xxxxxxxxxxxxx', 'razorpay-for-fluent-cart'),
                            ],
                            'test_key_secret' => [
                                'value'       => '',
                                'label'       => __('Test Key Secret', 'razorpay-for-fluent-cart'),
                                'type'        => 'password',
                                'placeholder' => __('Your test key secret', 'razorpay-for-fluent-cart'),
                            ],
                            'test_webhook_secret' => [
                                'value'       => '',
                                'label'       => __('Test Webhook Secret', 'razorpay-for-fluent-cart'),
                                'type'        => 'password',
                                'placeholder' => __('Your test webhook secret', 'razorpay-for-fluent-cart'),
                            ],
                        ],
                    ],
                ]
            ],
            'international_payments_notice' => [
                'value' => [
                     'title'       => __('International Payments', 'razorpay-for-fluent-cart'),
                    'description' => __('If you want to accept payments from international customers', 'razorpay-for-fluent-cart'),
                    'steps'       => [
                        'title' => __('You must:', 'razorpay-for-fluent-cart'),
                        'list'  => [
                            __('Enable International Payments in Razorpay Dashboard', 'razorpay-for-fluent-cart'),
                            __('Go to Settings &rarr; Configuration &rarr; Payment Methods', 'razorpay-for-fluent-cart'),
                            
                        ],
                    ],
                ],
                'label' => '',
                'type'  => 'html_attr'
            ],
            'webhook_info' => [
                'value' => $this->getWebhookInstructions(),
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
            if (empty(Arr::get($data, 'test_api_key')) || empty(Arr::get($data, 'test_key_secret'))) {
                $errors['test_api_key'] = __('Please provide Test API Key and Test Key Secret', 'razorpay-for-fluent-cart');
            }
        }

        if ($mode == 'live') {
            if (empty(Arr::get($data, 'live_api_key')) || empty(Arr::get($data, 'live_key_secret'))) {
                $errors['live_api_key'] = __('Please provide Live API Key and Live Key Secret', 'razorpay-for-fluent-cart');
            }
        }

        return $errors;
    }

    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        $mode = Arr::get($data, 'payment_mode', 'test');

        // Only encrypt if key is not empty and not already encrypted
        if ($mode == 'test') {
            if (!empty($data['test_key_secret'])) {
                $data['test_key_secret'] = Helper::encryptKey($data['test_key_secret']);
            }
        } else {
            if (!empty($data['live_key_secret'])) {
                $data['live_key_secret'] = Helper::encryptKey($data['live_key_secret']);
            }
        }

        return $data;
    }

    public static function register(): void
    {
        fluent_cart_api()->registerCustomPaymentMethod('razorpay', new self());
    }
}

