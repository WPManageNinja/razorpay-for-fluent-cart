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
    public function init(): void
    {
        add_action('wp_ajax_fluent_cart_razorpay_confirm_payment', [$this, 'confirmModalPayment']);
        add_action('wp_ajax_nopriv_fluent_cart_razorpay_confirm_payment', [$this, 'confirmModalPayment']);
    }

    public function confirmModalPayment()
    {
        if (!isset($_REQUEST['_nonce'])) {
            $this->confirmationFailed(400);
        }

        if (isset($_REQUEST['_nonce']) && !wp_verify_nonce(sanitize_text_field(wp_unslash(Arr::get($_REQUEST, '_nonce'))), 'fluent_cart_razorpay_nonce')) {
            $this->confirmationFailed(400);
        }

        $transactionHash = sanitize_text_field(Arr::get($_REQUEST, 'transaction_hash'));

        if (!$transactionHash) {
            $this->confirmationFailed(400);
        }

        $paymentId = sanitize_text_field(wp_unslash(Arr::get($_REQUEST, 'razorpay_payment_id')));
        if (!$paymentId) {
            $this->confirmationFailed(400);
        }

        $razorpayPayment = RazorpayAPI::getRazorpayObject('payments/' . $paymentId);

        if (is_wp_error($razorpayPayment)) {
            $this->confirmationFailed(400);
        }

        $paymentNotes = Arr::get($razorpayPayment, 'notes', []);
        $paymentTransactionHash = Arr::get($paymentNotes, 'transaction_hash');

        if (empty($paymentTransactionHash)) {
            $this->confirmationFailed(404);
        }

        if ($paymentTransactionHash !== $transactionHash) {
            fluent_cart_add_log(
                'Razorpay Confirmation',
                sprintf(
                    'Payment ownership mismatch. Payment %s belongs to transaction %s, not %s',
                    $paymentId,
                    $paymentTransactionHash,
                    $transactionHash
                )
            );
            $this->confirmationFailed(400);
        }

        $transactionModel = OrderTransaction::query()
            ->where('uuid', $transactionHash)
            ->where('payment_method', 'razorpay')
            ->first();

        if (!$transactionModel) {
            $this->confirmationFailed(404);
        }

        // If already succeeded, return success with redirect (idempotent response)
        if ($transactionModel->status === Status::TRANSACTION_SUCCEEDED) {
            wp_send_json_success([
                'message'      => __('Payment already confirmed', 'razorpay-for-fluent-cart'),
                'redirect_url' => $transactionModel->getReceiptPageUrl()
            ]);
        }

        $razorpayPaymentStatus = Arr::get($razorpayPayment, 'status');
        $captured = Arr::get($razorpayPayment, 'captured', false);

        if ($razorpayPaymentStatus === 'authorized' && !$captured) {
            $captureAmount = Arr::get($razorpayPayment, 'amount');
            $currency = Arr::get($razorpayPayment, 'currency', 'INR');

            $captureData = [
                'amount' => intval($captureAmount),
                'currency' => strtoupper($currency)
            ];

            $capturedPayment = RazorpayAPI::createRazorpayObject('payments/' . $paymentId . '/capture', $captureData);

            if (is_wp_error($capturedPayment)) {
                fluent_cart_add_log(
                    'Razorpay Confirmation',
                    'Failed to capture payment: ' . $capturedPayment->get_error_message(),
                    'error',
                    [
                        'module_name' => 'order',
                        'module_id'   => $transactionModel->order_id,
                    ]
                );

               $this->confirmationFailed(400);
            }

            $razorpayPayment = $capturedPayment;
            $razorpayPaymentStatus = Arr::get($razorpayPayment, 'status');
        }

        // Only accept captured/paid status - not just authorized
        if ($razorpayPaymentStatus === 'paid' || $razorpayPaymentStatus === 'captured') {
            $this->confirmPaymentSuccessByCharge($transactionModel, $razorpayPayment);
            wp_send_json_success([
                'message' => __('Payment successful', 'razorpay-for-fluent-cart'),
                'redirect_url' => $transactionModel->getReceiptPageUrl()
            ]);
        }

        fluent_cart_add_log(
            'Razorpay Confirmation',
            sprintf('Payment verification failed. Status: %s, Payment ID: %s', $razorpayPaymentStatus, $paymentId),
            'warning',
            [
                'module_name' => 'order',
                'module_id'   => $transactionModel->order_id,
            ]
        );

        $this->confirmationFailed(400);
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
            return null;
        }

        $order = Order::query()->where('id', $transaction->order_id)->first();
        
        if (!$order) {
            fluent_cart_add_log(
                'Razorpay Payment Confirmation Error',
                'Order not found for transaction ID: ' . $transaction->id,
                'error'
            );
            return null;
        }

        $paymentId = Arr::get($chargeData, 'id');
        $amount = Arr::get($chargeData, 'amount', 0);
        $currency = Arr::get($chargeData, 'currency');
        $status = Arr::get($chargeData, 'status');
        $method = Arr::get($chargeData, 'method', '');

        // Razorpay returns amount in smallest currency unit (paise for INR, yen for JPY)
        // FluentCart also stores in smallest unit, so no conversion needed

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

        // Calculate display amount for logging
        $displayAmount = CurrenciesHelper::isZeroDecimal($currency) ? $amount : ($amount / 100);

        fluent_cart_add_log(
            __('Razorpay Payment Confirmation', 'razorpay-for-fluent-cart'),
            sprintf(
                'Payment confirmed successfully. Payment ID: %s, Amount: %s %s, Method: %s',
                $paymentId,
                $displayAmount,
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

    public function confirmationFailed($code = 400)
    {
        wp_send_json_error([
            'message' => __('Payment confirmation failed', 'razorpay-for-fluent-cart')
        ], $code);
    }
}
