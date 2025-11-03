<?php

namespace RazorpayFluentCart\Confirmations;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Framework\Support\Arr;
use RazorpayFluentCart\API\RazorpayAPI;

class RazorpayConfirmations
{
    public function init()
    {
        add_action('fluent_cart/before_render_redirect_page', [$this, 'maybeConfirmPayment'], 10, 1);
    }

    /**
     * Confirm payment on redirect page
     */
    public function maybeConfirmPayment($data)
    {
        $isReceipt = Arr::get($data, 'is_receipt', false);
        $method = Arr::get($data, 'method', '');

        if ($isReceipt || $method !== 'razorpay') {
            return;
        }

        // Check for payment ID in URL (from hosted checkout callback)
        $paymentId = Arr::get($_GET, 'razorpay_payment_id');
        $transactionHash = Arr::get($data, 'trx_hash', '');

        if (!$paymentId) {
            return;
        }

        $transaction = OrderTransaction::query()
            ->where('uuid', $transactionHash)
            ->where('payment_method', 'razorpay')
            ->first();

        if (!$transaction || $transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return;
        }

        // Verify payment with Razorpay
        $this->verifyAndConfirmPayment($transaction, $paymentId);

        fluent_cart_add_log('Razorpay Payment Return', 'Customer returned from Razorpay. Payment ID: ' . $paymentId, 'info', [
            'module_name' => 'order',
            'module_id'   => $transaction->order_id,
        ]);
    }

    /**
     * Verify and confirm payment
     */
    private function verifyAndConfirmPayment($transaction, $paymentId)
    {
        $api = new RazorpayAPI();
        $vendorPayment = $api->getPayment($paymentId);

        if (is_wp_error($vendorPayment)) {
            fluent_cart_add_log('Razorpay Payment Verification Error', $vendorPayment->get_error_message(), 'error', [
                'module_name' => 'order',
                'module_id'   => $transaction->order_id,
            ]);
            return;
        }

        $status = Arr::get($vendorPayment, 'status');
        $captured = Arr::get($vendorPayment, 'captured', false);
        
        // If payment is authorized but not captured, capture it
        if ($status === 'authorized' && !$captured) {
            $captureAmount = Arr::get($vendorPayment, 'amount');
            $currency = Arr::get($vendorPayment, 'currency', 'INR');
            
            $capturedPayment = $api->capturePayment($paymentId, $captureAmount, $currency);
            
            if (is_wp_error($capturedPayment)) {
                fluent_cart_add_log('Razorpay Payment Capture Error', $capturedPayment->get_error_message(), 'error', [
                    'module_name' => 'order',
                    'module_id'   => $transaction->order_id,
                ]);
                return;
            }
            
            // Update vendorPayment with captured payment data
            $vendorPayment = $capturedPayment;
            $status = Arr::get($vendorPayment, 'status');
        }
        
        // Only proceed if payment is captured or paid
        if ($status !== 'captured' && $status !== 'paid') {
            fluent_cart_add_log('Razorpay Payment Not Captured', 'Payment status: ' . $status, 'info', [
                'module_name' => 'order',
                'module_id'   => $transaction->order_id,
                'payment_status' => $status
            ]);
            return;
        }

        // Update transaction
        $this->confirmPaymentSuccessByCharge($transaction, $vendorPayment);
    }

    /**
     * Confirm payment by charge
     */
    public function confirmPaymentSuccessByCharge(OrderTransaction $transaction, $chargeData = [])
    {
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return;
        }

        $order = $transaction->order;
        if (!$order) {
            return;
        }

        // Update transaction
        $updateData = [
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'vendor_charge_id' => $transaction->vendor_charge_id ?: Arr::get($chargeData, 'order_id'),
            'meta'             => array_merge($transaction->meta ?? [], [
                'razorpay_payment_id' => Arr::get($chargeData, 'id'),
                'razorpay_response'   => $chargeData
            ])
        ];

        // Add card details if available
        if ($card = Arr::get($chargeData, 'card')) {
            $updateData['card_last_4'] = Arr::get($card, 'last4');
            $updateData['card_brand'] = Arr::get($card, 'network');
        }

        $transaction->update($updateData);

        // Sync order status
        (new StatusHelper($order))->syncOrderStatuses($transaction);

        fluent_cart_add_log('Razorpay Payment Confirmation', 'Payment confirmed. Payment ID: ' . Arr::get($chargeData, 'id'), 'info', [
            'module_name' => 'order',
            'module_id'   => $order->id,
        ]);

        return $order;
    }
}

