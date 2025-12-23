<?php

namespace RazorpayFluentCart\Confirmations;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Framework\Support\Arr;
use RazorpayFluentCart\API\RazorpayAPI;
use RazorpayFluentCart\RazorpayHelper;
use FluentCart\App\Helpers\CurrenciesHelper;

class RazorpayConfirmations
{
    public function init()
    {
        add_action('wp_ajax_fluent_cart_razorpay_confirm_payment', [$this, 'confirmModalPayment']);
        add_action('wp_ajax_nopriv_fluent_cart_razorpay_confirm_payment', [$this, 'confirmModalPayment']);
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

        $vendorPayment = RazorpayAPI::getRazorpayObject('payments/' . $paymentId);


        if (is_wp_error($vendorPayment)) {
            wp_send_json_error([
                'message' => $vendorPayment->get_error_message()
            ], 400);
        }

        $status = Arr::get($vendorPayment, 'status');
        $captured = Arr::get($vendorPayment, 'captured', false);
        
        if ($status === 'authorized' && !$captured) {
            $captureAmount = Arr::get($vendorPayment, 'amount');
            $currency = Arr::get($vendorPayment, 'currency', 'INR');
            
            $captureData = [
                'amount' => intval($captureAmount),
                'currency' => strtoupper($currency)
            ];
            
            $capturedPayment = RazorpayAPI::createRazorpayObject('payments/' . $paymentId . '/capture', $captureData);
            
            if (is_wp_error($capturedPayment)) {
                wp_send_json_error([
                    'message' => __('Failed to capture payment: ', 'razorpay-for-fluent-cart') . $capturedPayment->get_error_message()
                ], 400);
            }
            
            $vendorPayment = $capturedPayment;
            $status = Arr::get($vendorPayment, 'status');
        }

        if ($status == 'paid' || $status == 'captured' || $status == 'authorized') {
           $this->confirmPaymentSuccessByCharge($transaction, $vendorPayment);
            
            wp_send_json_success([
                'message' => __('Payment successful', 'razorpay-for-fluent-cart'),
                'redirect_url' => $transaction->getReceiptPageUrl()
            ]);
        }


        wp_send_json_error([
            'message' => __('Payment verification failed', 'razorpay-for-fluent-cart')
        ], 400);
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

        if (!$transaction || $transaction->status === Status::TRANSACTION_SUCCEEDED) {
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

        if (CurrenciesHelper::isZeroDecimal($currency)) {
            $amount = (int) $amount * 100;
        }

        $status = RazorpayHelper::getFctStatusFromRazorpayStatus($status);

        $updateData = [
            'status'           => $status,
            'total'            => $amount,
            'currency'         => $currency,
            'payment_method'   => 'razorpay',
            'vendor_charge_id' => $paymentId,
            'meta'             => array_merge($transaction->meta ?? [], [
                'razorpay_status'     => $status,
                'razorpay_method'     => $method,
            ])
        ];

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

        if ($bank = Arr::get($chargeData, 'bank')) {
            $updateData['payment_method_type'] = $method;
            $updateData['meta']['bank_info'] = $bank;
        }

        if ($vpa = Arr::get($chargeData, 'vpa')) {
            $updateData['payment_method_type'] = 'upi';
            $updateData['meta']['upi_vpa'] = $vpa;
        }

        if ($wallet = Arr::get($chargeData, 'wallet')) {
            $updateData['payment_method_type'] = 'wallet';
            $updateData['meta']['wallet'] = $wallet;
        }

        // Update transaction
        $transaction->fill($updateData);
        $transaction->save();

        fluent_cart_add_log(
            __('Razorpay Payment Confirmation', 'razorpay-for-fluent-cart'),
            sprintf(
                'Payment confirmed successfully. Payment ID: %s, Amount: %s %s, Method: %s',
                $paymentId,
                $amount / 100,
                $currency,
                $method
            ),
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $order->id,
            ]
        );

        (new StatusHelper($order))->syncOrderStatuses($transaction);

        return $order;
    }
}
