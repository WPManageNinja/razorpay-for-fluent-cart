<?php

namespace RazorpayFluentCart;

use FluentCart\Api\CurrencySettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;

class RazorpayGateway extends AbstractPaymentGateway
{
    private $methodSlug = 'razorpay';

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
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function boot()
    {
        // Initialize IPN handler
        (new Webhook\RazorpayWebhook())->init();
        
        add_filter('fluent_cart/payment_methods/razorpay_settings', [$this, 'getSettings'], 10, 2);

        (new Confirmations\RazorpayConfirmations())->init();

        // Add AJAX handler for modal payment confirmation
        add_action('wp_ajax_fluent_cart_razorpay_confirm_payment', [$this, 'confirmModalPayment']);
        add_action('wp_ajax_nopriv_fluent_cart_razorpay_confirm_payment', [$this, 'confirmModalPayment']);
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
                'public_key' => $this->settings->getPublicKey(),
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
    }

    public static function getRazorpaySupportedCurrency(): array
    {
        return ['INR'];
    }

    public function handleIPN(): void
    {
        (new Webhook\RazorpayWebhook())->verifyAndProcess();
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        $scripts = [
            [
                'handle' => 'razorpay-checkout-sdk',
                'src'    => 'https://checkout.razorpay.com/v1/checkout.js',
                'version' => '1.0'
            ],
            [
                'handle' => 'razorpay-fluent-cart-checkout-handler',
                'src'    => RAZORPAY_FC_PLUGIN_URL . 'assets/razorpay-checkout.js',
                'version' => RAZORPAY_FC_VERSION,
                'deps'    => ['razorpay-checkout-sdk']
            ]
        ];

        return $scripts;
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

        return (new API\RazorpayAPI())->createRefund($transaction->vendor_charge_id, $amount);
    }

    public function confirmModalPayment()
    {
        $transactionHash = sanitize_text_field(Arr::get($_REQUEST, 'transaction_hash'));
        
        if (!$transactionHash) {
            wp_send_json_error([
                'message' => __('Invalid request', 'razorpay-for-fluent-cart')
            ], 400);
        }

        $transaction = OrderTransaction::query()
            ->where('uuid', $transactionHash)
            ->where('payment_method', 'razorpay')
            ->first();

        if (!$transaction || $transaction->status !== 'pending') {
            wp_send_json_error([
                'message' => __('Invalid transaction', 'razorpay-for-fluent-cart')
            ], 400);
        }

        $paymentId = sanitize_text_field(Arr::get($_REQUEST, 'razorpay_payment_id'));
        if (!$paymentId) {
            wp_send_json_error([
                'message' => __('Payment ID is required', 'razorpay-for-fluent-cart')
            ], 400);
        }

        $api = new API\RazorpayAPI();
        $vendorPayment = $api->getPayment($paymentId);

        if (is_wp_error($vendorPayment)) {
            wp_send_json_error([
                'message' => $vendorPayment->get_error_message()
            ], 400);
        }

        $status = Arr::get($vendorPayment, 'status');
        $captured = Arr::get($vendorPayment, 'captured', false);
        
        // If payment is authorized but not captured, capture it
        if ($status === 'authorized' && !$captured) {
            $captureAmount = Arr::get($vendorPayment, 'amount');
            $currency = Arr::get($vendorPayment, 'currency', 'INR');
            
            $capturedPayment = $api->capturePayment($paymentId, $captureAmount, $currency);
            
            if (is_wp_error($capturedPayment)) {
                wp_send_json_error([
                    'message' => __('Failed to capture payment: ', 'razorpay-for-fluent-cart') . $capturedPayment->get_error_message()
                ], 400);
            }
            
            // Update vendorPayment with captured payment data
            $vendorPayment = $capturedPayment;
            $status = Arr::get($vendorPayment, 'status');
        }

        if ($status == 'paid' || $status == 'captured') {
            (new Confirmations\RazorpayConfirmations())->confirmPaymentSuccessByCharge($transaction, $vendorPayment);
            
            wp_send_json_success([
                'message' => __('Payment successful', 'razorpay-for-fluent-cart'),
                'redirect_url' => $this->getSuccessUrl($transaction)
            ]);
        }

        wp_send_json_error([
            'message' => __('Payment verification failed', 'razorpay-for-fluent-cart')
        ], 400);
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
            'checkout_type' => [
                'value'   => 'modal',
                'label'   => __('Checkout Type', 'razorpay-for-fluent-cart'),
                'type'    => 'radio',
                'options' => [
                    'modal'  => __('Modal Checkout (Popup)', 'razorpay-for-fluent-cart'),
                    'hosted' => __('Hosted Checkout (Redirect)', 'razorpay-for-fluent-cart')
                ],
                'tooltip' => __('Choose how customers will complete their payment.', 'razorpay-for-fluent-cart')
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
            'webhook_info' => [
                'value' => sprintf(
                    '<div><p><b>%s</b><code class="copyable-content">%s</code></p><p>%s</p></div>',
                    __('Webhook URL: ', 'razorpay-for-fluent-cart'),
                    $webhook_url,
                    __('Configure this webhook URL in your Razorpay Dashboard under Settings > Webhooks to receive payment notifications.', 'razorpay-for-fluent-cart')
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

