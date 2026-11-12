<?php

namespace Tests\Integration;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use lucatume\WPBrowser\TestCase\WPTestCase;
use RazorpayFluentCart\Confirmations\RazorpayConfirmations;
use RazorpayFluentCart\Subscription\RazorpaySubscriptions;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;

class RazorpayFirstInvoiceResyncTest extends WPTestCase
{
    private $subId;

    private $payId;

    protected function setUp(): void
    {
        parent::setUp();
        // Rows outlive the test, and the resync looks payments up store-wide.
        $suffix = wp_generate_password(10, false);
        $this->subId = 'sub_' . $suffix;
        $this->payId = 'pay_' . $suffix;
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
        parent::tearDown();
    }

    public function test_first_invoice_paid_before_confirmation_settles_the_first_payment(): void
    {
        list($order, $transaction, $subscription) = $this->unconfirmedSubscription(99900);
        $this->stubRazorpay($this->paidFirstInvoice(99900));
        $activations = did_action('fluent_cart/subscription_activated');

        (new RazorpaySubscriptions())->reSyncSubscriptionFromRemote($subscription);
        (new RazorpaySubscriptions())->reSyncSubscriptionFromRemote(Subscription::query()->find($subscription->id));

        $this->assertSame($activations + 1, did_action('fluent_cart/subscription_activated'));
        $transaction = OrderTransaction::query()->find($transaction->id);
        $this->assertSame('succeeded', $transaction->status);
        $this->assertSame($this->payId, $transaction->vendor_charge_id);
        $this->assertSame(1, OrderTransaction::query()->where('subscription_id', $subscription->id)->count());
        $this->assertSame(0, Order::query()->where('parent_id', $order->id)->count());
        $this->assertSame('active', Subscription::query()->find($subscription->id)->status);
    }

    public function test_first_invoice_of_an_addon_sub_is_a_renewal(): void
    {
        list($order, $transaction, $subscription) = $this->unconfirmedSubscription(99900, ['use_addon' => true]);
        $this->stubRazorpay($this->paidFirstInvoice(149900));

        (new RazorpaySubscriptions())->reSyncSubscriptionFromRemote($subscription);

        $transaction = OrderTransaction::query()->find($transaction->id);
        $this->assertSame('pending', $transaction->status);
        $this->assertSame(99900, (int) $transaction->total);
        $this->assertSame(1, Order::query()->where('parent_id', $order->id)->count());
    }

    public function test_first_invoice_resync_returns_an_error_while_another_request_holds_the_lock(): void
    {
        list($order, $transaction, $subscription) = $this->unconfirmedSubscription(99900);
        $this->stubRazorpay($this->paidFirstInvoice(99900));

        // GET_LOCK is per connection, so the holder needs its own.
        $holder = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $lockName = 'fc_razorpay_txn_' . $transaction->id;
        $this->assertSame('1', $holder->get_var($holder->prepare('SELECT GET_LOCK(%s, 0)', $lockName)));

        try {
            $result = (new RazorpaySubscriptions())->reSyncSubscriptionFromRemote($subscription);
        } finally {
            $holder->query($holder->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
            $holder->close();
        }

        $this->assertWPError($result);
        $this->assertSame('razorpay_confirmation_locked', $result->get_error_code());
        $this->assertSame('pending', OrderTransaction::query()->find($transaction->id)->status);
        $this->assertSame(0, Order::query()->where('parent_id', $order->id)->count());

        (new RazorpaySubscriptions())->reSyncSubscriptionFromRemote(Subscription::query()->find($subscription->id));

        $this->assertSame('succeeded', OrderTransaction::query()->find($transaction->id)->status);
        $this->assertSame(0, Order::query()->where('parent_id', $order->id)->count());
    }

    public function test_confirmation_after_the_activated_webhook_does_not_fire_activation_again(): void
    {
        list($order, $transaction, $subscription) = $this->unconfirmedSubscription(99900);
        $subscription->update(['status' => 'active']);
        $payment = $this->paidFirstInvoice(99900)['payments/' . $this->payId];
        $activations = did_action('fluent_cart/subscription_activated');

        (new RazorpayConfirmations())->confirmPaymentSuccessByCharge($transaction, $payment, ['status' => 'active']);

        $this->assertSame('succeeded', OrderTransaction::query()->find($transaction->id)->status);
        $this->assertSame($activations, did_action('fluent_cart/subscription_activated'));
    }

    private function unconfirmedSubscription(int $total, array $meta = []): array
    {
        $customer = (new CustomerFactory())->create();
        $product = (new ProductFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id, 'type' => 'subscription', 'payment_method' => 'razorpay',
            'currency' => 'INR', 'total_amount' => $total,
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id, 'post_id' => $product->ID, 'object_id' => $product->variants->first()->id,
            'payment_type' => 'subscription', 'quantity' => 1, 'unit_price' => $total, 'subtotal' => $total,
            'line_total' => $total,
        ]);
        $subscription = (new SubscriptionFactory())->create([
            'customer_id' => $customer->id, 'parent_order_id' => $order->id, 'product_id' => $product->ID,
            'variation_id' => $product->variants->first()->id, 'currency' => 'INR', 'status' => 'pending',
            'recurring_amount' => $total, 'recurring_total' => $total, 'bill_times' => 0,
            'vendor_subscription_id' => $this->subId,
        ]);
        $transaction = OrderTransaction::query()->create([
            'order_id' => $order->id, 'subscription_id' => $subscription->id, 'uuid' => wp_generate_uuid4(),
            'payment_method' => 'razorpay', 'payment_mode' => 'test', 'status' => 'pending',
            'transaction_type' => 'charge', 'currency' => 'INR', 'total' => $total,
            'vendor_charge_id' => $this->subId, 'meta' => array_merge(['razorpay_subscription_id' => $this->subId], $meta),
        ]);

        return [$order, $transaction, Subscription::query()->find($subscription->id)];
    }

    private function paidFirstInvoice(int $amount): array
    {
        $paidAt = time();

        return [
            'subscriptions/' . $this->subId => [
                'id' => $this->subId, 'status' => 'active', 'payment_method' => 'card',
                'current_end' => $paidAt + MONTH_IN_SECONDS,
            ],
            'invoices' => ['items' => [[
                'id' => 'inv_first', 'status' => 'paid', 'payment_id' => $this->payId,
                'subscription_id' => $this->subId, 'paid_at' => $paidAt,
                'amount_paid' => $amount, 'currency' => 'INR',
            ]]],
            'payments/' . $this->payId => [
                'id' => $this->payId, 'amount' => $amount, 'currency' => 'INR',
                'status' => 'captured', 'method' => 'card', 'created_at' => $paidAt,
            ],
        ];
    }

    private function stubRazorpay(array $responses): void
    {
        add_filter('pre_http_request', function ($preempt, $args, $url) use ($responses) {
            $path = parse_url($url, PHP_URL_PATH);
            foreach ($responses as $endpoint => $response) {
                if (substr($path, -strlen('/' . $endpoint)) === '/' . $endpoint) {
                    return ['headers' => [], 'body' => wp_json_encode($response), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
                }
            }

            return ['headers' => [], 'body' => '{}', 'response' => ['code' => 404, 'message' => 'Not Found'], 'cookies' => []];
        }, 10, 3);
    }
}
