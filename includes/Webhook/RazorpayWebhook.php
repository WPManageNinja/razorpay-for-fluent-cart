<?php

namespace RazorpayFluentCart\Webhook;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Events\Order\OrderRefund;
use FluentCart\App\Events\Subscription\SubscriptionActivated;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\Framework\Support\Arr;
use RazorpayFluentCart\API\RazorpayAPI;
use RazorpayFluentCart\Settings\RazorpaySettingsBase;
use RazorpayFluentCart\Confirmations\RazorpayConfirmations;
use RazorpayFluentCart\Refund\RazorpayRefund;
use RazorpayFluentCart\RazorpayHelper;
use FluentCart\App\Helpers\CurrenciesHelper;

class RazorpayWebhook
{
    public function init()
    {
        // Register webhook event handlers - Payment events
        add_action('fluent_cart/payments/razorpay/webhook_payment_captured', [$this, 'handlePaymentCaptured'], 10, 1);
        add_action('fluent_cart/payments/razorpay/webhook_payment_authorized', [$this, 'handlePaymentAuthorized'], 10, 1);
        add_action('fluent_cart/payments/razorpay/webhook_payment_failed', [$this, 'handlePaymentFailed'], 10, 1);
        add_action('fluent_cart/payments/razorpay/webhook_refund_processed', [$this, 'handleRefundProcessed'], 10, 1);

        // Subscription webhook event handlers
        add_action('fluent_cart/payments/razorpay/webhook_subscription_authenticated', [$this, 'handleSubscriptionAuthenticated'], 10, 1);
        add_action('fluent_cart/payments/razorpay/webhook_subscription_activated', [$this, 'handleSubscriptionActivated'], 10, 1);
        add_action('fluent_cart/payments/razorpay/webhook_subscription_charged', [$this, 'handleSubscriptionCharged'], 10, 1);
        add_action('fluent_cart/payments/razorpay/webhook_subscription_cancelled', [$this, 'handleSubscriptionCancelled'], 10, 1);
        add_action('fluent_cart/payments/razorpay/webhook_subscription_paused', [$this, 'handleSubscriptionPaused'], 10, 1);
        add_action('fluent_cart/payments/razorpay/webhook_subscription_resumed', [$this, 'handleSubscriptionResumed'], 10, 1);
        add_action('fluent_cart/payments/razorpay/webhook_subscription_halted', [$this, 'handleSubscriptionHalted'], 10, 1);
        add_action('fluent_cart/payments/razorpay/webhook_subscription_completed', [$this, 'handleSubscriptionCompleted'], 10, 1);
        add_action('fluent_cart/payments/razorpay/webhook_subscription_pending', [$this, 'handleSubscriptionPending'], 10, 1);
    }

    /**
     * Verify and process Razorpay webhook
     */
    public function verifyAndProcess()
    {
        $payload = $this->getWebhookPayload();
        
        if (is_wp_error($payload)) {
            http_response_code(400);
            exit('Not valid payload');
        }

        $data = json_decode($payload, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            exit('Invalid JSON payload');
        }


        $byPassSignatureVerification = apply_filters('razorpay_fc/by_pass_signature_verification', false);
        if (!$byPassSignatureVerification) {
            if (!$this->verifySignature($payload)) {
                http_response_code(401);
                exit('Invalid signature / Verification failed');
            }
        }

        $event = Arr::get($data, 'event');
        
        if (!$event) {
            http_response_code(400);
            exit('Event type not found');
        }


        // For subscription events, we may not have an order initially
        $isSubscriptionEvent = strpos($event, 'subscription.') === 0;
        $order = $this->getFluentCartOrder($data);

        if (!$order && !$isSubscriptionEvent) {
            http_response_code(404);
            exit('Order not found');
        }

        $eventAction = str_replace('.', '_', $event);

        // Check if we have a handler for this event
        if (has_action('fluent_cart/payments/razorpay/webhook_' . $eventAction)) {
            do_action('fluent_cart/payments/razorpay/webhook_' . $eventAction, [
                'payload' => Arr::get($data, 'payload'),
                'order'   => $order,
                'event'   => $event
            ]);

            $this->sendResponse(200, 'Webhook processed successfully');
        }

        // Log unhandled webhook
        fluent_cart_add_log(
            'Razorpay Webhook - Unhandled Event',
            sprintf('Event type: %s', $event),
            'info'
        );

        http_response_code(200);
        exit('Webhook received but not handled');
    }

    private function getWebhookPayload()
    {
        $input = file_get_contents('php://input');
        
        // Check payload size (max 1MB)
        if (strlen($input) > 1048576) {
            return new \WP_Error('payload_too_large', 'Webhook payload too large');
        }
        
        if (empty($input)) {
            return new \WP_Error('empty_payload', 'Empty webhook payload');
        }
        
        return $input;
    }

    private function verifySignature($payload)
    {
       $signature = $this->getRazorpaySignatureHeader();
    
       return $this->validateWebhookSignature($payload, $signature);
    }

    public function handlePaymentCaptured($data)
    {
        $razorpayPayment = Arr::get($data, 'payload.payment.entity');
        $paymentId = Arr::get($razorpayPayment, 'id');
        
        if (!$paymentId) {
            $this->sendResponse(400, 'Payment ID not found');
        }

        $transaction = $this->findTransactionByPayment($razorpayPayment);


        // Check if already processed
        if (!$transaction || $transaction->status == Status::TRANSACTION_SUCCEEDED) {
            $this->sendResponse(200, 'Payment already confirmed');
        }

        // Confirm the payment
        (new RazorpayConfirmations())->confirmPaymentSuccessByCharge($transaction, $razorpayPayment);

        fluent_cart_add_log(
            __('Razorpay Payment Captured (Webhook)', 'razorpay-for-fluent-cart'),
            sprintf('Payment ID: %s captured successfully', $paymentId),
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $transaction->order_id,
            ]
        );

        $this->sendResponse(200, 'Payment captured successfully');
    }

 
    public function handlePaymentAuthorized($data)
    {
        $razorpayPayment = Arr::get($data, 'payload.payment.entity');
        $paymentId = Arr::get($razorpayPayment, 'id');

        if (!$paymentId) {
            $this->sendResponse(400, 'Payment ID not found');
        }

        // Find transaction
        $transaction = $this->findTransactionByPayment($razorpayPayment);

        if (!$transaction) {
            $this->sendResponse(404, 'Transaction not found');
        }

        // Auto-capture the payment
        $captureAmount = Arr::get($razorpayPayment, 'amount');
        $currency = Arr::get($razorpayPayment, 'currency', 'INR');
        
        $captureData = [
            'amount' => intval($captureAmount),
            'currency' => strtoupper($currency)
        ];

        // Auto-capture the payment
        $shouldAutoCapture = apply_filters('razorpay_fc/should_auto_capture_payment', true);
        if ($shouldAutoCapture) {
            $razorpayPayment = RazorpayAPI::createRazorpayObject('payments/' . $paymentId . '/capture', $captureData);

            if (is_wp_error($razorpayPayment)) {
                $this->sendResponse(500, 'Failed to capture payment');
            } else {
                  // Process the captured payment
                  (new RazorpayConfirmations())->confirmPaymentSuccessByCharge($transaction, $razorpayPayment);

            }
        } else {
            $isAuthorizationIsASuccessState = apply_filters('razorpay_fc/is_authorization_is_a_success_state', true, [
                'razorpay_payment' => $razorpayPayment,
            ]);

            if ($isAuthorizationIsASuccessState) {
                // Process the captured payment
                (new RazorpayConfirmations())->confirmPaymentSuccessByCharge($transaction, $razorpayPayment);
            } else {
                $transaction->update([
                    'status' => Status::TRANSACTION_AUTHORIZED,
                    'vendor_charge_id' => $paymentId,
                    'meta'   => array_merge($transaction->meta ?? [], [
                        'razorpay_payment_id' => $paymentId,
                    ])
                ]);
            }
        }

        $this->sendResponse(200, 'Payment authorized and captured');
    }

  
    public function handlePaymentFailed($data)
    {
        $razorpayPayment = Arr::get($data, 'payload.payment.entity');
        $paymentId = Arr::get($razorpayPayment, 'id');

        if (!$paymentId) {
            $this->sendResponse(400, 'Payment ID not found');
        }

        // Find transaction
        $transaction = $this->findTransactionByPayment($razorpayPayment);

        if (!$transaction) {
            $this->sendResponse(404, 'Transaction not found');
        }

        // Update transaction status to failed
        $transaction->update([
            'status' => Status::TRANSACTION_FAILED,
            'meta'   => array_merge($transaction->meta ?? [], [
                'razorpay_payment_id' => $paymentId,
                'razorpay_error'      => Arr::get($razorpayPayment, 'error_description', 'Payment failed')
            ])
        ]);

        fluent_cart_add_log(
            __('Razorpay Payment Failed', 'razorpay-for-fluent-cart'),
            sprintf('Payment ID: %s failed. Reason: %s', $paymentId, Arr::get($razorpayPayment, 'error_description', 'Unknown')),
            'error',
            [
                'module_name' => 'order',
                'module_id'   => $transaction->order_id,
            ]
        );

        $this->sendResponse(200, 'Payment failure processed');
    }

    public function handleRefundProcessed($data)
    {
        $refund = Arr::get($data, 'payload.refund.entity');
        $order = Arr::get($data, 'order');
        
        $refundId = Arr::get($refund, 'id');
        $paymentId = Arr::get($refund, 'payment_id');
        $amount = Arr::get($refund, 'amount');
        $currency = Arr::get($refund, 'currency', 'INR');
        $status = Arr::get($refund, 'status');

        if (CurrenciesHelper::isZeroDecimal($currency)) {
            $amount = $amount * 100;
        }

        if (!$refundId || !$paymentId) {
            $this->sendResponse(400, 'Refund or Payment ID not found');
        }

        // Find parent transaction by payment ID
        $parentTransaction = OrderTransaction::query()
            ->where('vendor_charge_id', $paymentId)
            ->first();
           

        if (!$parentTransaction) {
            $this->sendResponse(404, 'Parent transaction not found for payment: ' . $paymentId);
        }

        // Check if this refund already exists
        $existingRefund = OrderTransaction::query()
            ->where('order_id', $parentTransaction->order_id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->where('vendor_charge_id', $refundId)
            ->first();

        if ($existingRefund) {
            // Update if status changed
            if ($existingRefund->status != Status::TRANSACTION_REFUNDED && $status == 'processed') {
                $existingRefund->update(['status' => Status::TRANSACTION_REFUNDED]);
            }
            $this->sendResponse(200, 'Refund already exists');
        }

        // Create refund transaction
        $refundData = [
            'order_id'           => $parentTransaction->order_id,
            'transaction_type'   => Status::TRANSACTION_TYPE_REFUND,
            'status'             => $status == 'processed' ? Status::TRANSACTION_REFUNDED : Status::TRANSACTION_PENDING,
            'payment_method'     => 'razorpay',
            'payment_mode'       => $parentTransaction->payment_mode,
            'vendor_charge_id'   => $refundId,
            'total'              => $amount,
            'currency'           => $currency,
            'meta'               => [
                'parent_id'          => $parentTransaction->id,
                'razorpay_refund_id' => $refundId,
                'razorpay_payment_id' => $paymentId,
                'refund_source'      => 'webhook',
                'refund_notes'       => Arr::get($refund, 'notes', [])
            ]
        ];

        $currentCreatedRefund = RazorpayRefund::createOrUpdateIpnRefund($refundData, $parentTransaction);

        if ($currentCreatedRefund && $currentCreatedRefund->wasRecentlyCreated) {
            (new OrderRefund($order, $currentCreatedRefund))->dispatch();
        }

        fluent_cart_add_log(
            __('Razorpay Refund Processed (Webhook)', 'razorpay-for-fluent-cart'),
            sprintf('Refund ID: %s processed successfully', $refundId),
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $parentTransaction->order_id,
            ]
        );

        $this->sendResponse(200, 'Refund processed successfully');
    }

    private function findTransactionByPayment($razorpayPayment)
    {
        $orderId = Arr::get($razorpayPayment, 'order_id');
        $paymentId = Arr::get($razorpayPayment, 'id');
        $notes = Arr::get($razorpayPayment, 'notes', []);

        if ($orderId) {
            $transaction = OrderTransaction::query()
                ->where('vendor_charge_id', $orderId)
                ->where('payment_method', 'razorpay')
                ->first();

            if ($transaction) {
                return $transaction;
            }
        }

        if ($paymentId) {
            $transaction = OrderTransaction::query()
                ->where('vendor_charge_id', $paymentId)
                ->where('payment_method', 'razorpay')
                ->first();

            if ($transaction) {
                return $transaction;
            }
        }

        $transactionHash = Arr::get($notes, 'transaction_id');
        if ($transactionHash) {
            $transaction = OrderTransaction::query()
                ->where('uuid', $transactionHash)
                ->where('payment_method', 'razorpay')
                ->first();

            if ($transaction) {
                return $transaction;
            }
        }

        return null;
    }

    protected function getRazorpaySignatureHeader(): ?string
    {
        if (function_exists('getallheaders')) {
            $headers = array_change_key_case(getallheaders(), CASE_LOWER);
            return $headers['x-razorpay-signature'] ?? null;
        }

        if (isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'])) {
            return $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'];
        }

        return null;
    }

    public function validateWebhookSignature($payload, $signature)
    {
        if (!$signature) {
            return false;
        }

        $webhookSecret = (new RazorpaySettingsBase())->getWebhookSecret('current');
        $message = $payload;

        $expectedSignature = hash_hmac('sha256', $message, $webhookSecret);

        return hash_equals(strtolower($expectedSignature), strtolower($signature));
    }


    protected function sendResponse($statusCode = 200, $message = 'Success')
    {
        http_response_code($statusCode);
        echo json_encode([
            'message' => $message,
            'status' => $statusCode >= 200 && $statusCode < 300 ? 'success' : 'error'
        ]);
        exit;
    }

    /**
     * Handle subscription.authenticated webhook
     * First auth payment completed
     *
     * @param array $data
     */
    public function handleSubscriptionAuthenticated($data)
    {
        $razorpaySubscription = Arr::get($data, 'payload.subscription.entity');
        $razorpaySubscriptionId = Arr::get($razorpaySubscription, 'id');

        $order = Arr::get($data, 'order');

        $subscription = $this->findSubscriptionByVendorId($razorpaySubscriptionId);

        if (!$subscription) {
            fluent_cart_add_log(
                'Razorpay Webhook - Subscription Authenticated',
                sprintf('Subscription not found for Razorpay subscription: %s', $razorpaySubscriptionId),
                'warning'
            );
            $this->sendResponse(200, 'Subscription not found in FluentCart');
        }

        if (in_array($subscription->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING, Status::SUBSCRIPTION_AUTHENTICATED])) {
            $this->sendResponse(200, 'Subscription already authenticated');
        }

        // Update subscription status - could be trialing if trial period exists
        $status = Status::SUBSCRIPTION_AUTHENTICATED;

        $subscription->update([
            'status'          => $status,
            'vendor_response' => $razorpaySubscription,
        ]);

        $activePaymentMethod = $subscription->getMeta('active_payment_method', []);

        $paymentMethod = Arr::get($razorpaySubscription, 'payment_method', '');
        if ($paymentMethod) {
            $activePaymentMethod['payment_method'] = $paymentMethod;
            if ($paymentMethod === 'card') { 
                $activePaymentMethod['payment_method'] = 'card';
                $activePaymentMethod['card_mandate_id'] = Arr::get($razorpaySubscription, 'card_mandate_id', '');
            }
        }

        $subscription->updateMeta('active_payment_method', $activePaymentMethod);

        fluent_cart_add_log(
            'Razorpay Webhook - Subscription Authenticated',
            sprintf('Subscription %d authenticated. Status: %s', $subscription->id, $status),
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $order->id,
            ]
        );

        $this->sendResponse(200, 'Subscription authenticated');
    }

    /**
     * Handle subscription.activated webhook
     * Subscription is now active
     *
     * @param array $data
     */
    public function handleSubscriptionActivated($data)
    {
        $razorpaySubscription = Arr::get($data, 'payload.subscription.entity');
        $razorpaySubscriptionId = Arr::get($razorpaySubscription, 'id');

        $order = Arr::get($data, 'order');

        $subscription = $this->findSubscriptionByVendorId($razorpaySubscriptionId);

        if (!$subscription) {
            $this->sendResponse(200, 'Subscription not found in FluentCart');
        }

        if ($subscription->status == Status::SUBSCRIPTION_ACTIVE) {
            $this->sendResponse(200, 'Subscription already activated');
        }

        $oldStatus = $subscription->status;

        $nextBillingDate = RazorpayHelper::getNextBillingDate($razorpaySubscription);

        $updateData = [
            'status'          => Status::SUBSCRIPTION_ACTIVE,
            'vendor_response' => $razorpaySubscription,
        ];

        if ($nextBillingDate) {
            $updateData['next_billing_date'] = $nextBillingDate;
        }

        if ($order->type == Status::ORDER_TYPE_RENEWAL) {
            $updateData['canceled_at'] = null;
        } else {
            $subscription->update($updateData);

            if ($oldStatus != $subscription->status && in_array($subscription->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING])) {
                (new SubscriptionActivated($subscription, $order, $order->customer))->dispatch();
            }
        }

        $activePaymentMethod = $subscription->getMeta('active_payment_method', []);

        $paymentMethod = Arr::get($razorpaySubscription, 'payment_method', '');
        if ($paymentMethod) {
            $activePaymentMethod['payment_method'] = $paymentMethod;
            if ($paymentMethod === 'card') { 
                $activePaymentMethod['payment_method'] = 'card';
                $activePaymentMethod['card_mandate_id'] = Arr::get($razorpaySubscription, 'card_mandate_id', '');
            }
        }

        $subscription->updateMeta('active_payment_method', $activePaymentMethod);

        fluent_cart_add_log(
            'Razorpay Webhook - Subscription Activated',
            sprintf('Subscription %d activated', $subscription->id),
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $order->id,
            ]
        );

        $this->sendResponse(200, 'Subscription activated');
    }

    /**
     * Handle subscription.charged webhook
     * Recurring payment successful (RENEWAL)
     *
     * @param array $data
     */
    public function handleSubscriptionCharged($data)
    {
        $razorpaySubscription = Arr::get($data, 'payload.subscription.entity');
        $razorpayPayment = Arr::get($data, 'payload.payment.entity');
        $razorpaySubscriptionId = Arr::get($razorpaySubscription, 'id');
        $paymentId = Arr::get($razorpayPayment, 'id');

        $order = Arr::get($data, 'order');

        $subscription = $this->findSubscriptionByVendorId($razorpaySubscriptionId);

        if (!$subscription) {
            fluent_cart_add_log(
                'Razorpay Webhook - Subscription Charged',
                sprintf('Subscription not found for Razorpay subscription: %s', $razorpaySubscriptionId),
                'warning'
            );
            $this->sendResponse(200, 'Subscription not found in FluentCart');
        }

        // Check if this payment already exists (deduplication)
        $existingTransaction = OrderTransaction::query()
            ->where('vendor_charge_id', $paymentId)
            ->where('payment_method', 'razorpay')
            ->first();

        if ($existingTransaction) {
            $this->sendResponse(200, 'Payment already processed');
        }

        // This is a renewal payment - record it
        $amount = Arr::get($razorpayPayment, 'amount', 0);
        $currency = Arr::get($razorpayPayment, 'currency', 'INR');
        $paidAt = Arr::get($razorpayPayment, 'created_at');

        $transactionData = [
            'subscription_id'  => $subscription->id,
            'payment_method'   => 'razorpay',
            'vendor_charge_id' => $paymentId,
            'total'            => (int) $amount,
            'currency'         => strtoupper($currency),
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'created_at'       => $paidAt ? gmdate('Y-m-d H:i:s', $paidAt) : gmdate('Y-m-d H:i:s'),
            'meta'             => [
                'razorpay_subscription_id' => $razorpaySubscriptionId,
                'razorpay_payment_id'      => $paymentId,
                'webhook_event'            => 'subscription.charged',
            ],
        ];

        // Calculate next billing date
        $nextBillingDate = RazorpayHelper::getNextBillingDate($razorpaySubscription);
        $subscriptionUpdateData = [
            'status'          => Status::SUBSCRIPTION_ACTIVE,
            'vendor_response' => $razorpaySubscription,
        ];

        if ($nextBillingDate) {
            $subscriptionUpdateData['next_billing_date'] = $nextBillingDate;
        }

        // Record renewal payment
        $transaction = SubscriptionService::recordRenewalPayment($transactionData, $subscription, $subscriptionUpdateData);

        if (is_wp_error($transaction)) {
            fluent_cart_add_log(
                'Razorpay Webhook - Subscription Charged',
                sprintf('Failed to record renewal: %s', $transaction->get_error_message()),
                'error',
                [
                    'module_name' => 'order',
                    'module_id'   => $order->id,
                ]
            );
            $this->sendResponse(500, 'Failed to record renewal payment');
        }

        fluent_cart_add_log(
            'Razorpay Webhook - Subscription Charged',
            sprintf('Renewal payment recorded for subscription %d. Payment: %s', $subscription->id, $paymentId),
            'info',
            [
                'module_name' => 'subscription',
                'module_id'   => $subscription->id,
            ]
        );

        $this->sendResponse(200, 'Renewal payment recorded');
    }

    /**
     * Handle subscription.cancelled webhook
     *
     * @param array $data
     */
    public function handleSubscriptionCancelled($data)
    {
        $razorpaySubscription = Arr::get($data, 'payload.subscription.entity');
        $razorpaySubscriptionId = Arr::get($razorpaySubscription, 'id');

        $subscription = $this->findSubscriptionByVendorId($razorpaySubscriptionId);

        if (!$subscription) {
            $this->sendResponse(200, 'Subscription not found in FluentCart');
        }

        $subscription->reSyncFromRemote();

        fluent_cart_add_log(
            'Razorpay Webhook - Subscription Cancelled',
            sprintf('Subscription %d cancelled', $subscription->id),
            'info',
            [
                'module_name' => 'subscription',
                'module_id'   => $subscription->id,
            ]
        );

        $this->sendResponse(200, 'Subscription cancelled');
    }

    /**
     * Handle subscription.paused webhook
     *
     * @param array $data
     */
    public function handleSubscriptionPaused($data)
    {
        $razorpaySubscription = Arr::get($data, 'payload.subscription.entity');
        $razorpaySubscriptionId = Arr::get($razorpaySubscription, 'id');

        $subscription = $this->findSubscriptionByVendorId($razorpaySubscriptionId);

        if (!$subscription) {
            $this->sendResponse(200, 'Subscription not found in FluentCart');
        }

        $subscription->reSyncFromRemote();

        fluent_cart_add_log(
            'Razorpay Webhook - Subscription Paused',
            sprintf('Subscription %d paused', $subscription->id),
            'info',
            [
                'module_name' => 'subscription',
                'module_id'   => $subscription->id,
            ]
        );

        $this->sendResponse(200, 'Subscription paused');
    }

    /**
     * Handle subscription.resumed webhook
     *
     * @param array $data
     */
    public function handleSubscriptionResumed($data)
    {
        $razorpaySubscription = Arr::get($data, 'payload.subscription.entity');
        $razorpaySubscriptionId = Arr::get($razorpaySubscription, 'id');

        $subscription = $this->findSubscriptionByVendorId($razorpaySubscriptionId);

        if (!$subscription) {
            $this->sendResponse(200, 'Subscription not found in FluentCart');
        }

        $subscription->reSyncFromRemote();

        fluent_cart_add_log(
            'Razorpay Webhook - Subscription Resumed',
            sprintf('Subscription %d resumed', $subscription->id),
            'info',
            [
                'module_name' => 'subscription',
                'module_id'   => $subscription->id,
            ]
        );

        $this->sendResponse(200, 'Subscription resumed');
    }

    /**
     * Handle subscription.halted webhook
     * Payment failures caused halt
     *
     * @param array $data
     */
    public function handleSubscriptionHalted($data)
    {
        $razorpaySubscription = Arr::get($data, 'payload.subscription.entity');
        $razorpaySubscriptionId = Arr::get($razorpaySubscription, 'id');

        $subscription = $this->findSubscriptionByVendorId($razorpaySubscriptionId);

        if (!$subscription) {
            $this->sendResponse(200, 'Subscription not found in FluentCart');
        }

        $subscription->reSyncFromRemote();

        fluent_cart_add_log(
            'Razorpay Webhook - Subscription Halted',
            sprintf('Subscription %d halted due to payment failures', $subscription->id),
            'warning',
            [
                'module_name' => 'subscription',
                'module_id'   => $subscription->id,
            ]
        );

        $this->sendResponse(200, 'Subscription halted');
    }

    /**
     * Handle subscription.completed webhook
     * All billing cycles completed
     *
     * @param array $data
     */
    public function handleSubscriptionCompleted($data)
    {
        $razorpaySubscription = Arr::get($data, 'payload.subscription.entity');
        $razorpaySubscriptionId = Arr::get($razorpaySubscription, 'id');

        $subscription = $this->findSubscriptionByVendorId($razorpaySubscriptionId);

        if (!$subscription) {
            $this->sendResponse(200, 'Subscription not found in FluentCart');
        }

        $subscription->reSyncFromRemote();

        fluent_cart_add_log(
            'Razorpay Webhook - Subscription Completed',
            sprintf('Subscription %d completed', $subscription->id),
            'info',
            [
                'module_name' => 'subscription',
                'module_id'   => $subscription->id,
            ]
        );

        $this->sendResponse(200, 'Subscription completed');
    }

    /**
     * Handle subscription.pending webhook
     *
     * @param array $data
     */
    public function handleSubscriptionPending($data)
    {
        $razorpaySubscription = Arr::get($data, 'payload.subscription.entity');
        $razorpaySubscriptionId = Arr::get($razorpaySubscription, 'id');

        $subscription = $this->findSubscriptionByVendorId($razorpaySubscriptionId);

        if (!$subscription) {
            $this->sendResponse(200, 'Subscription not found in FluentCart');
        }

        $subscription->reSyncFromRemote();

        fluent_cart_add_log(
            'Razorpay Webhook - Subscription Pending',
            sprintf('Subscription %d is pending', $subscription->id),
            'info',
            [
                'module_name' => 'subscription',
                'module_id'   => $subscription->id,
            ]
        );

        $this->sendResponse(200, 'Subscription pending');
    }

    private function findSubscriptionByVendorId($razorpaySubscriptionId)
    {
        if (empty($razorpaySubscriptionId)) {
            return null;
        }

        return Subscription::query()
            ->where('vendor_subscription_id', $razorpaySubscriptionId)
            ->first();
    }


    /**
     * Get FluentCart order from webhook data
     * Extended to also look in subscription notes
     *
     * @param array $data
     *
     * @return Order|null
     */
    public function getFluentCartOrder($data)
    {
        $order = null;

        // Try payment notes first
        $notes = Arr::get($data, 'payload.payment.entity.notes', []);
        $orderHash = Arr::get($notes, 'order_hash');

        if ($orderHash) {
            $order = Order::query()->where('uuid', $orderHash)->first();
        }

        // Try subscription notes
        if (!$order) {
            $subscriptionNotes = Arr::get($data, 'payload.subscription.entity.notes', []);
            $orderHash = Arr::get($subscriptionNotes, 'order_hash');

            if ($orderHash) {
                $order = Order::query()->where('uuid', $orderHash)->first();
            }
        }

        // Try to get from refund notes
        if (!$order) {
            $refundNotes = Arr::get($data, 'payload.refund.entity.notes', []);
            $orderHash = Arr::get($refundNotes, 'order_hash');

            if ($orderHash) {
                $order = Order::query()->where('uuid', $orderHash)->first();
            }
        }

        // For subscription events, try to find order via subscription
        if (!$order) {
            $razorpaySubscriptionId = Arr::get($data, 'payload.subscription.entity.id');
            if ($razorpaySubscriptionId) {
                $subscription = $this->findSubscriptionByVendorId($razorpaySubscriptionId);
                if ($subscription) {
                    $order = Order::query()->find($subscription->parent_order_id);
                }
            }
        }

        return $order;
    }
}
