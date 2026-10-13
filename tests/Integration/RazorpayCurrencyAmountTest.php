<?php

namespace Tests\Integration;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Services\Payments\PaymentInstance;
use lucatume\WPBrowser\TestCase\WPTestCase;
use RazorpayFluentCart\Confirmations\RazorpayConfirmations;
use RazorpayFluentCart\Onetime\RazorpayProcessor;
use RazorpayFluentCart\RazorpayGateway;
use RazorpayFluentCart\RazorpayHelper;
use RazorpayFluentCart\Refund\RazorpayRefund;
use RazorpayFluentCart\Subscription\RazorpaySubscriptionProcessor;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;

class RazorpayCurrencyAmountTest extends WPTestCase
{
    private $storeSettingsSnapshot;

    private $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        add_filter('razorpay_fc/razorpay_settings', function ($settings) {
            return array_merge($settings, [
                'test_api_key' => 'rzp_test_key', 'test_key_secret' => 'secret',
                'live_api_key' => 'rzp_live_key', 'live_key_secret' => 'secret',
            ]);
        });
    }

    protected function tearDown(): void
    {
        remove_all_filters('razorpay_fc/razorpay_settings');
        remove_all_filters('pre_http_request');
        CartCurrency::reset();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        if ($this->storeSettingsSnapshot !== null) {
            update_option('fluent_cart_store_settings', $this->storeSettingsSnapshot);
            StoreSettings::clearCache();
        }
        parent::tearDown();
    }

    /**
     * @dataProvider wireAmounts
     */
    public function test_amount_uses_the_razorpay_minor_unit(string $currency, int $cents, int $wire): void
    {
        $this->assertSame($wire, RazorpayHelper::toRazorpayAmount($cents, $currency));
        $this->assertSame($cents, RazorpayHelper::fromRazorpayAmount($wire, $currency));
        $this->assertSame($wire, RazorpayHelper::toRazorpayAmount($cents, strtolower($currency)));
    }

    public static function wireAmounts(): array
    {
        return [
            'INR two-decimal'        => ['INR', 49999, 49999],
            'EUR two-decimal'        => ['EUR', 4999, 4999],
            'JPY zero-decimal'       => ['JPY', 150000, 1500],
            'ISK zero-decimal'       => ['ISK', 138500, 1385],
            'UGX zero-decimal'       => ['UGX', 3700000, 37000],
            'MGA two-decimal'        => ['MGA', 1000000, 1000000],
            'HUF two-decimal'        => ['HUF', 123456, 123456],
            'KWD three-decimal'      => ['KWD', 9999, 99990],
            'BHD three-decimal'      => ['BHD', 1050, 10500],
            'IQD three-decimal'      => ['IQD', 500000, 5000000],
        ];
    }

    public function test_a_refund_in_thousandths_rounds_to_the_stored_unit(): void
    {
        // A dashboard refund of 1.235 KWD.
        $this->assertSame(124, RazorpayHelper::fromRazorpayAmount(1235, 'KWD'));
    }

    /**
     * @dataProvider foreignOrders
     */
    public function test_a_foreign_currency_order_is_charged_in_its_own_minor_unit(string $currency, int $total, int $wire): void
    {
        $this->presentCurrency('INR');
        $order = $this->orderIn($currency, $total);
        $this->stubRazorpay(['id' => 'order_test']);

        $result = (new RazorpayProcessor())->handleSinglePayment(new PaymentInstance($order));

        $this->assertSame($currency, $this->requests[0]['body']['currency']);
        $this->assertSame($wire, $this->requests[0]['body']['amount']);
        $this->assertSame($wire, $result['payment_args']['modal_data']['amount']);
    }

    public static function foreignOrders(): array
    {
        return [
            'USD'  => ['USD', 4999, 4999],
            'JPY'  => ['JPY', 150000, 1500],
            'ISK'  => ['ISK', 138500, 1385],
            'MGA'  => ['MGA', 1000000, 1000000],
            'KWD'  => ['KWD', 9999, 99990],
        ];
    }

    /**
     * @dataProvider foreignOrders
     */
    public function test_a_confirmed_payment_keeps_the_order_total(string $currency, int $total, int $wire): void
    {
        $this->presentCurrency('INR');
        $order = $this->orderIn($currency, $total);
        $transaction = OrderTransaction::query()->where('order_id', $order->id)->first();
        $this->stubRazorpay([]);

        (new RazorpayConfirmations())->confirmPaymentSuccessByCharge($transaction, [
            'id' => 'pay_test', 'amount' => $wire, 'currency' => $currency,
            'status' => 'captured', 'method' => 'card', 'created_at' => time(),
        ]);

        $transaction = OrderTransaction::query()->find($transaction->id);
        $this->assertSame('succeeded', $transaction->status);
        $this->assertSame($total, (int) $transaction->total);
    }

    /**
     * @dataProvider refunds
     */
    public function test_a_refund_is_sent_in_the_razorpay_minor_unit(string $currency, int $total, int $refund, int $wire): void
    {
        $this->presentCurrency('INR');
        $order = $this->orderIn($currency, $total);
        $transaction = OrderTransaction::query()->where('order_id', $order->id)->first();
        $transaction->vendor_charge_id = 'pay_test';
        $transaction->save();
        $this->stubRazorpay(['id' => 'rfnd_test', 'status' => 'processed']);

        RazorpayRefund::processRemoteRefund($transaction, $refund, []);

        $this->assertStringEndsWith('payments/pay_test/refund', $this->requests[0]['url']);
        $this->assertSame($wire, $this->requests[0]['body']['amount']);
    }

    public static function refunds(): array
    {
        return [
            'JPY' => ['JPY', 150000, 50000, 500],
            'ISK' => ['ISK', 138500, 50000, 500],
            'KWD' => ['KWD', 9999, 2500, 25000],
        ];
    }

    /**
     * @dataProvider signupFeeSubscriptions
     */
    public function test_a_subscription_plan_and_signup_fee_use_the_razorpay_minor_unit(string $currency, int $firstPayment, int $recurring, int $planWire, int $addonWire): void
    {
        $this->presentCurrency('INR');
        $customer = (new CustomerFactory())->create();
        $product = (new ProductFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id, 'type' => 'subscription', 'payment_method' => 'razorpay',
            'currency' => $currency, 'total_amount' => $firstPayment,
        ]);
        OrderTransaction::query()->create([
            'order_id' => $order->id, 'uuid' => wp_generate_uuid4(), 'payment_method' => 'razorpay',
            'payment_mode' => 'test', 'status' => 'pending', 'transaction_type' => 'charge',
            'currency' => $currency, 'total' => $firstPayment,
        ]);
        (new SubscriptionFactory())->create([
            'customer_id' => $customer->id, 'parent_order_id' => $order->id, 'product_id' => $product->ID,
            'variation_id' => $product->variants->first()->id, 'currency' => $currency,
            'recurring_amount' => $recurring, 'recurring_total' => $recurring, 'bill_times' => 0,
        ]);
        $this->stubRazorpay(['id' => 'rzp_object']);

        $result = (new RazorpaySubscriptionProcessor())->handleSubscription(new PaymentInstance($order));

        $this->assertIsArray($result);
        $sent = [];
        foreach ($this->requests as $request) {
            $sent[basename(parse_url($request['url'], PHP_URL_PATH))] = $request['body'];
        }
        $this->assertSame($planWire, $sent['plans']['item']['amount']);
        $this->assertSame($addonWire, $sent['subscriptions']['addons'][0]['item']['amount']);
    }

    public static function signupFeeSubscriptions(): array
    {
        return [
            'INR' => ['INR', 199900, 99900, 99900, 199900],
            'ISK' => ['ISK', 300000, 138500, 1385, 3000],
            'KWD' => ['KWD', 15000, 9999, 99990, 150000],
        ];
    }

    public function test_a_cart_in_a_foreign_currency_is_offered_razorpay(): void
    {
        $this->presentCurrency('INR');
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'USD'], ['code' => 'JPY']]]);
        ExchangeRateService::record('USD', '0.012');
        ExchangeRateService::record('JPY', '1.8');

        $gateway = new RazorpayGateway();
        foreach (['INR', 'USD', 'JPY'] as $currency) {
            $kept = apply_filters('fluent_cart/checkout_active_payment_methods', [$gateway], ['cart' => $this->cartIn($currency)]);
            $this->assertSame([$gateway], array_values($kept), $currency);
        }

        $withoutFlag = new RazorpayGateway();
        $withoutFlag->supportedFeatures = array_values(array_diff($withoutFlag->supportedFeatures, ['multi_currency']));
        $kept = apply_filters('fluent_cart/checkout_active_payment_methods', [$withoutFlag], ['cart' => $this->cartIn('USD')]);
        $this->assertSame([], array_values($kept));
    }

    private function stubRazorpay(array $response): void
    {
        add_filter('pre_http_request', function ($preempt, $args, $url) use ($response) {
            $this->requests[] = ['url' => $url, 'body' => json_decode($args['body'] ?? '', true)];

            return ['headers' => [], 'body' => wp_json_encode($response), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
        }, 10, 3);
    }

    private function orderIn(string $currency, int $total)
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id, 'type' => 'onetime', 'payment_method' => 'razorpay',
            'currency' => $currency, 'total_amount' => $total,
        ]);
        OrderTransaction::query()->create([
            'order_id' => $order->id, 'uuid' => wp_generate_uuid4(), 'payment_method' => 'razorpay',
            'payment_mode' => 'test', 'status' => 'pending', 'transaction_type' => 'charge',
            'currency' => $currency, 'total' => $total,
        ]);

        return $order;
    }

    private function presentCurrency(string $currency): void
    {
        if ($this->storeSettingsSnapshot === null) {
            $this->storeSettingsSnapshot = get_option('fluent_cart_store_settings', []);
        }
        update_option('fluent_cart_store_settings', array_merge((array) $this->storeSettingsSnapshot, ['currency' => $currency]));
        StoreSettings::clearCache();
    }

    private function cartIn(string $currency): Cart
    {
        $product = (new ProductFactory())->create();
        $variation = ProductVariation::query()->find($product->variants->first()->id);

        $cart = new Cart();
        $cart->cart_group = 'instant';
        $cart->cart_data = [CartHelper::generateCartItemFromVariation($variation, 1)];
        $cart->checkout_data = [];
        CartCurrency::force($cart, $currency);
        $cart->save();

        return Cart::query()->find($cart->cart_hash);
    }
}
