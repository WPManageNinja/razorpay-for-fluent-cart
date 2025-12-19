<?php

namespace RazorpayFluentCart\Webhook;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Events\Order\OrderRefund;
use FluentCart\Framework\Support\Arr;
use RazorpayFluentCart\API\RazorpayAPI;
use RazorpayFluentCart\Settings\RazorpaySettingsBase;
use RazorpayFluentCart\Confirmations\RazorpayConfirmations;
use RazorpayFluentCart\Refund\RazorpayRefund;

class RazorpayWebhook
{
    public function init()
    {
        // Register webhook event handlers
        add_action('fluent_cart/payments/razorpay/webhook_payment_captured', [$this, 'handlePaymentCaptured'], 10, 1);
        add_action('fluent_cart/payments/razorpay/webhook_payment_authorized', [$this, 'handlePaymentAuthorized'], 10, 1);
        add_action('fluent_cart/payments/razorpay/webhook_payment_failed', [$this, 'handlePaymentFailed'], 10, 1);
        add_action('fluent_cart/payments/razorpay/webhook_refund_created', [$this, 'handleRefundCreated'], 10, 1);
        add_action('fluent_cart/payments/razorpay/webhook_refund_processed', [$this, 'handleRefundProcessed'], 10, 1);
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

        // Verify webhook signature
        if (!$this->verifySignature($payload)) {
            http_response_code(401);
            exit('Invalid signature / Verification failed');
        }

        // Get the event type
        $event = Arr::get($data, 'event');
        
        if (!$event) {
            http_response_code(400);
            exit('Event type not found');
        }

        // Get the order from webhook data
        $order = $this->getFluentCartOrder($data);

        if (!$order) {
            http_response_code(404);
            exit('Order not found');
        }

        // Convert event format: payment.captured => payment_captured
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

    /**
     * Get webhook payload from request
     */
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

    /**
     * Verify webhook signature
     * Razorpay uses HMAC SHA256 signature verification
     */
    private function verifySignature($payload)
    {
        // Get signature from headers
        $signature = isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']) 
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'])) 
            : '';
        
        if (!$signature) {
            fluent_cart_add_log('Razorpay Webhook', 'No signature found in webhook request', 'error');
            return false;
        }

        $settings = new RazorpaySettingsBase();
        $webhookSecret = $settings->get('webhook_secret');

        // If no webhook secret is configured, fall back to API secret key
        if (empty($webhookSecret)) {
            $webhookSecret = $settings->getSecretKey();
        }
        
        if (empty($webhookSecret)) {
            fluent_cart_add_log('Razorpay Webhook', 'Webhook secret not configured', 'error');
            return false;
        }
        
        // Compute expected signature
        $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);

        // Verify signature using timing-safe comparison
        $isValid = hash_equals($expectedSignature, $signature);

        if (!$isValid) {
            fluent_cart_add_log(
                'Razorpay Webhook Verification Failed',
                'Signature mismatch',
                'error'
            );
        }

        return $isValid;
    }

    /**
     * Handle payment.captured webhook event
     */
    public function handlePaymentCaptured($data)
    {
        $razorpayPayment = Arr::get($data, 'payload.payment.entity');
        $paymentId = Arr::get($razorpayPayment, 'id');
        
        if (!$paymentId) {
            $this->sendResponse(400, 'Payment ID not found');
        }

        // Find transaction
        $transaction = $this->findTransactionByPayment($razorpayPayment);

        if (!$transaction) {
            $this->sendResponse(404, 'Transaction not found for payment: ' . $paymentId);
        }

        // Check if already processed
        if ($transaction->status == Status::TRANSACTION_SUCCEEDED) {
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

    /**
     * Handle payment.authorized webhook event
     */
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

        $capturedPayment = RazorpayAPI::createRazorpayObject('payments/' . $paymentId . '/capture', $captureData);

        if (is_wp_error($capturedPayment)) {
            fluent_cart_add_log(
                'Razorpay Auto-Capture Failed',
                $capturedPayment->get_error_message(),
                'error',
                [
                    'module_name' => 'order',
                    'module_id'   => $transaction->order_id,
                ]
            );
            $this->sendResponse(500, 'Failed to capture payment');
        }

        // Process the captured payment
        (new RazorpayConfirmations())->confirmPaymentSuccessByCharge($transaction, $capturedPayment);

        $this->sendResponse(200, 'Payment authorized and captured');
    }

    /**
     * Handle payment.failed webhook event
     */
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

    /**
     * Handle refund.created or refund.processed webhook event
     */
    public function handleRefundCreated($data)
    {
        $this->handleRefundProcessed($data);
    }

    /**
     * Handle refund.processed webhook event
     */
    public function handleRefundProcessed($data)
    {
        $refund = Arr::get($data, 'payload.refund.entity');
        $order = Arr::get($data, 'order');
        
        $refundId = Arr::get($refund, 'id');
        $paymentId = Arr::get($refund, 'payment_id');
        $amount = Arr::get($refund, 'amount');
        $currency = Arr::get($refund, 'currency', 'INR');
        $status = Arr::get($refund, 'status');

        if (!$refundId || !$paymentId) {
            $this->sendResponse(400, 'Refund or Payment ID not found');
        }

        // Find parent transaction by payment ID
        $parentTransaction = OrderTransaction::query()
            ->where('payment_method', 'razorpay')
            ->get()
            ->first(function($transaction) use ($paymentId) {
                $meta = $transaction->meta ?? [];
                return Arr::get($meta, 'razorpay_payment_id') == $paymentId 
                    || $transaction->vendor_charge_id == $paymentId;
            });

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
            // Dispatch refund event
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

    /**
     * Find transaction by Razorpay payment data
     */
    private function findTransactionByPayment($razorpayPayment)
    {
        $orderId = Arr::get($razorpayPayment, 'order_id');
        $paymentId = Arr::get($razorpayPayment, 'id');
        $notes = Arr::get($razorpayPayment, 'notes', []);

        // Try to find by Razorpay order ID first
        if ($orderId) {
            $transaction = OrderTransaction::query()
                ->where('vendor_charge_id', $orderId)
                ->where('payment_method', 'razorpay')
                ->first();

            if ($transaction) {
                return $transaction;
            }
        }

        // Try to find by transaction UUID in notes
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

        // Try to find by payment ID in meta
        $transactions = OrderTransaction::query()
            ->where('payment_method', 'razorpay')
            ->get();

        foreach ($transactions as $transaction) {
            $meta = $transaction->meta ?? [];
            if (Arr::get($meta, 'razorpay_payment_id') == $paymentId) {
                return $transaction;
            }
        }

        return null;
    }

    /**
     * Get FluentCart order from webhook data
     */
    public function getFluentCartOrder($data)
    {
        $order = null;

        // Try to get order from payment notes
        $notes = Arr::get($data, 'payload.payment.entity.notes', []);
        $orderHash = Arr::get($notes, 'order_hash');

        if ($orderHash) {
            $order = Order::query()->where('uuid', $orderHash)->first();
        }

        // Try to get from refund notes
        if (!$order) {
            $refundNotes = Arr::get($data, 'payload.refund.entity.notes', []);
            $orderHash = Arr::get($refundNotes, 'order_hash');

            if ($orderHash) {
                $order = Order::query()->where('uuid', $orderHash)->first();
            }
        }

        // Try to find order by Razorpay order ID
        if (!$order) {
            $razorpayOrderId = Arr::get($data, 'payload.payment.entity.order_id') 
                ?: Arr::get($data, 'payload.order.entity.id');

            if ($razorpayOrderId) {
                $transaction = OrderTransaction::query()
                    ->where('vendor_charge_id', $razorpayOrderId)
                    ->where('payment_method', 'razorpay')
                    ->first();

                if ($transaction) {
                    $order = Order::query()->where('id', $transaction->order_id)->first();
                }
            }
        }

        // Try to find by payment ID
        if (!$order) {
            $paymentId = Arr::get($data, 'payload.payment.entity.id')
                ?: Arr::get($data, 'payload.refund.entity.payment_id');

            if ($paymentId) {
                $transactions = OrderTransaction::query()
                    ->where('payment_method', 'razorpay')
                    ->get();

                foreach ($transactions as $transaction) {
                    $meta = $transaction->meta ?? [];
                    if (Arr::get($meta, 'razorpay_payment_id') == $paymentId) {
                        $order = Order::query()->where('id', $transaction->order_id)->first();
                        break;
                    }
                }
            }
        }

        return $order;
    }

    /**
     * Send JSON response and exit
     */
    protected function sendResponse($statusCode = 200, $message = 'Success')
    {
        http_response_code($statusCode);
        echo json_encode([
            'message' => $message,
            'status' => $statusCode >= 200 && $statusCode < 300 ? 'success' : 'error'
        ]);
        exit;
    }
}
