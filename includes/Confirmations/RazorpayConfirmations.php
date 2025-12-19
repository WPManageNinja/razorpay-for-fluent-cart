<?php

namespace RazorpayFluentCart\Confirmations;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Framework\Support\Arr;
use RazorpayFluentCart\API\RazorpayAPI;

class RazorpayConfirmations
{
    public function init()
    {
        // Add action to confirm payment on redirect page (for hosted checkout)
        add_action('fluent_cart/before_render_redirect_page', [$this, 'maybeConfirmPaymentOnReturn'], 10, 1);
    }

    /**
     * Confirm payment on redirect page (for hosted checkout)
     */
    public function maybeConfirmPaymentOnReturn($data)
    {
        $isReceipt = Arr::get($data, 'is_receipt', false);
        $method = Arr::get($data, 'method', '');

        // Only process on thank you page (not receipt page) and for razorpay method
        if ($isReceipt || $method !== 'razorpay') {
            return;
        }

        // Check for payment details in URL (from hosted checkout callback)
        $paymentId = Arr::get($_GET, 'razorpay_payment_id');
        $paymentLinkId = Arr::get($_GET, 'razorpay_payment_link_id');
        $paymentLinkReferenceId = Arr::get($_GET, 'razorpay_payment_link_reference_id');
        $paymentLinkStatus = Arr::get($_GET, 'razorpay_payment_link_status');
        
        $transactionHash = Arr::get($data, 'trx_hash', '');

        if (!$paymentId && !$paymentLinkId) {
            return;
        }

        // Find the transaction
        $transaction = OrderTransaction::query()
            ->where('uuid', $transactionHash)
            ->where('payment_method', 'razorpay')
            ->first();

        if (!$transaction) {
            fluent_cart_add_log(
                'Razorpay Payment Return - Transaction Not Found',
                'Transaction hash: ' . $transactionHash,
                'error'
            );
            return;
        }

        // If already succeeded, skip
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return;
        }

        // If payment link status is paid, verify and confirm
        if ($paymentLinkStatus === 'paid' && $paymentId) {
            $this->verifyAndConfirmPayment($transaction, $paymentId);
        }

        fluent_cart_add_log(
            'Razorpay Payment Return',
            sprintf('Customer returned from Razorpay. Payment ID: %s, Status: %s', $paymentId, $paymentLinkStatus ?: 'unknown'),
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $transaction->order_id,
            ]
        );
    }

    /**
     * Verify and confirm payment
     */
    private function verifyAndConfirmPayment($transaction, $paymentId)
    {
        $vendorPayment = RazorpayAPI::getRazorpayObject('payments/' . $paymentId);

        if (is_wp_error($vendorPayment)) {
            fluent_cart_add_log(
                'Razorpay Payment Verification Error',
                $vendorPayment->get_error_message(),
                'error',
                [
                    'module_name' => 'order',
                    'module_id'   => $transaction->order_id,
                ]
            );
            return;
        }

        $status = Arr::get($vendorPayment, 'status');
        $captured = Arr::get($vendorPayment, 'captured', false);
        
        // If payment is authorized but not captured, capture it
        if ($status === 'authorized' && !$captured) {
            $captureAmount = Arr::get($vendorPayment, 'amount');
            $currency = Arr::get($vendorPayment, 'currency', 'INR');
            
            $captureData = [
                'amount' => intval($captureAmount),
                'currency' => strtoupper($currency)
            ];
            
            $capturedPayment = RazorpayAPI::createRazorpayObject('payments/' . $paymentId . '/capture', $captureData);
            
            if (is_wp_error($capturedPayment)) {
                fluent_cart_add_log(
                    'Razorpay Payment Capture Error',
                    $capturedPayment->get_error_message(),
                    'error',
                    [
                        'module_name' => 'order',
                        'module_id'   => $transaction->order_id,
                    ]
                );
                return;
            }
            
            // Update vendorPayment with captured payment data
            $vendorPayment = $capturedPayment;
            $status = Arr::get($vendorPayment, 'status');
        }
        
        // Only proceed if payment is captured or paid
        if ($status !== 'captured' && $status !== 'paid') {
            fluent_cart_add_log(
                'Razorpay Payment Not Captured',
                sprintf('Payment status: %s', $status),
                'info',
                [
                    'module_name' => 'order',
                    'module_id'   => $transaction->order_id,
                    'payment_status' => $status
                ]
            );
            return;
        }

        // Confirm the payment
        $this->confirmPaymentSuccessByCharge($transaction, $vendorPayment);
    }

    /**
     * Confirm payment success and update transaction
     * This method is called from:
     * 1. Modal payment confirmation (via AJAX from RazorpayGateway::confirmModalPayment)
     * 2. Hosted payment confirmation (via redirect callback)
     * 3. Webhook payment confirmation
     */
    public function confirmPaymentSuccessByCharge(OrderTransaction $transaction, $chargeData = [])
    {
        // Skip if already succeeded
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return;
        }

        $order = Order::query()->where('id', $transaction->order_id)->first();
        
        if (!$order) {
            fluent_cart_add_log(
                'Razorpay Payment Confirmation Error',
                'Order not found for transaction ID: ' . $transaction->id,
                'error'
            );
            return;
        }

        $paymentId = Arr::get($chargeData, 'id');
        $amount = Arr::get($chargeData, 'amount', 0);
        $currency = Arr::get($chargeData, 'currency');
        $status = Arr::get($chargeData, 'status');
        $method = Arr::get($chargeData, 'method', '');

        // Prepare transaction update data
        $updateData = [
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => $amount,
            'currency'         => $currency,
            'payment_method'   => 'razorpay',
            'meta'             => array_merge($transaction->meta ?? [], [
                'razorpay_payment_id' => $paymentId,
                'razorpay_status'     => $status,
                'razorpay_method'     => $method,
            ])
        ];

        // Add card details if available
        if ($card = Arr::get($chargeData, 'card')) {
            $updateData['card_last_4'] = Arr::get($card, 'last4', '');
            $updateData['card_brand'] = Arr::get($card, 'network', '');
            $updateData['payment_method_type'] = 'card';
            
            $updateData['meta']['card_info'] = [
                'last4'     => Arr::get($card, 'last4'),
                'brand'     => Arr::get($card, 'network'),
                'type'      => Arr::get($card, 'type'),
                'issuer'    => Arr::get($card, 'issuer'),
                'exp_month' => Arr::get($card, 'exp_month'),
                'exp_year'  => Arr::get($card, 'exp_year'),
            ];
        }

        // Add bank details if available (for UPI, netbanking, etc.)
        if ($bank = Arr::get($chargeData, 'bank')) {
            $updateData['payment_method_type'] = $method;
            $updateData['meta']['bank_info'] = $bank;
        }

        // Add UPI details if available
        if ($vpa = Arr::get($chargeData, 'vpa')) {
            $updateData['payment_method_type'] = 'upi';
            $updateData['meta']['upi_vpa'] = $vpa;
        }

        // Add wallet details if available
        if ($wallet = Arr::get($chargeData, 'wallet')) {
            $updateData['payment_method_type'] = 'wallet';
            $updateData['meta']['wallet'] = $wallet;
        }

        // Update transaction
        $transaction->fill($updateData);
        $transaction->save();

        // Log the confirmation
        fluent_cart_add_log(
            __('Razorpay Payment Confirmation', 'razorpay-for-fluent-cart'),
            sprintf(
                'Payment confirmed successfully. Payment ID: %s, Amount: %s %s, Method: %s',
                $paymentId,
                $amount,
                $currency,
                $method
            ),
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $order->id,
            ]
        );

        // Sync order status
        (new StatusHelper($order))->syncOrderStatuses($transaction);

        return $order;
    }
}
