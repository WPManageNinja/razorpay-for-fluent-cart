<?php

namespace RazorpayFluentCart\Confirmations;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Events\Subscription\SubscriptionActivated;
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

        $transactionHash = sanitize_text_field(wp_unslash(Arr::get($_REQUEST, 'transaction_hash')));

        if (!$transactionHash) {
            $this->confirmationFailed(400);
        }

        $paymentId = sanitize_text_field(wp_unslash(Arr::get($_REQUEST, 'razorpay_payment_id')));
        if (!$paymentId) {
            $this->confirmationFailed(400);
        }

        $isSubscription = sanitize_text_field(wp_unslash(Arr::get($_REQUEST, 'is_subscription'))) === '1';
        $razorpaySubscriptionId = sanitize_text_field(wp_unslash(Arr::get($_REQUEST, 'razorpay_subscription_id', '')));

        if ($isSubscription) {
            $this->confirmSubscriptionPayment($transactionHash, $paymentId, $razorpaySubscriptionId);
            return;
        }

        $razorpayPayment = RazorpayAPI::getRazorpayObject('payments/' . $paymentId);

        if (is_wp_error($razorpayPayment)) {
            $this->confirmationFailed(400);
        }

        $paymentNotes = Arr::get($razorpayPayment, 'notes', []);
        $transactionHashFromRazorpay = Arr::get($paymentNotes, 'transaction_hash');

        if (empty($transactionHashFromRazorpay)) {
            $this->confirmationFailed(404);
        }

        if ($transactionHashFromRazorpay !== $transactionHash) {
            fluent_cart_add_log(
                'Razorpay Confirmation',
                sprintf(
                    'Payment ownership mismatch. Payment %s belongs to transaction %s, not %s',
                    $paymentId,
                    $transactionHashFromRazorpay,
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

        if ($transactionModel->status === Status::TRANSACTION_SUCCEEDED) {
            $this->confirmationFailed(400);
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
     * Confirm subscription payment after modal checkout
     *
     * @param string $transactionHash
     * @param string $paymentId
     * @param string $razorpaySubscriptionId
     */
    public function confirmSubscriptionPayment($transactionHash, $paymentId, $razorpaySubscriptionId)
    {
        $transactionModel = OrderTransaction::query()
            ->where('uuid', $transactionHash)
            ->where('payment_method', 'razorpay')
            ->first();

        if (!$transactionModel) {
            $this->confirmationFailed(404);
        }

        if ($transactionModel->status === Status::TRANSACTION_SUCCEEDED) {
            $this->confirmationFailed(400);
        }

        $order = Order::query()->where('id', $transactionModel->order_id)->first();

        if (!$order) {
            $this->confirmationFailed(404);
        }
        $razorpaySubscription = RazorpayAPI::getRazorpayObject('subscriptions/' . $razorpaySubscriptionId);

        if (is_wp_error($razorpaySubscription)) {
            fluent_cart_add_log(
                'Razorpay Subscription Confirmation',
                'Failed to fetch subscription: ' . $razorpaySubscription->get_error_message(),
                'error',
                [
                    'module_name' => 'order',
                    'module_id'   => $order->id,
                ]
            );
            $this->confirmationFailed(400);
        }

        $notes = Arr::get($razorpaySubscription, 'notes', []);
        $transactionHashFromRazorpay = Arr::get($notes, 'transaction_hash', '');
        $subscriptionPaymentMethod = Arr::get($razorpaySubscription, 'payment_method', '');

        if ($transactionHashFromRazorpay !== $transactionHash) {
            fluent_cart_add_log(
                'Razorpay Subscription Confirmation',
                sprintf('Transaction ownership mismatch. Transaction %s belongs to subscription %s, not %s', $transactionHash, $transactionHashFromRazorpay, $transactionHash),
                'error',
                [
                    'module_name' => 'order',
                    'module_id'   => $order->id,
                ]
            );
            $this->confirmationFailed(400);
        }

        $razorpayPayment = RazorpayAPI::getRazorpayObject('payments/' . $paymentId);

        if (is_wp_error($razorpayPayment)) {
            fluent_cart_add_log(
                'Razorpay Subscription Confirmation',
                'Failed to fetch payment: ' . $razorpayPayment->get_error_message(),
                'error',
                [
                    'module_name' => 'order',
                    'module_id'   => $order->id,
                ]
            );
            $this->confirmationFailed(400);
        }

        $razorpayPaymentStatus = Arr::get($razorpayPayment, 'status');

        // status 'refunded' happens when the payment is refunded after the initial payment is confirmed, case: first payment is 0, in case of renewal/reactivation payment or subscription with trial
        if (!in_array($razorpayPaymentStatus, ['captured', 'authorized', 'paid', 'refunded'])) {
            fluent_cart_add_log(
                'Razorpay Subscription Confirmation',
                sprintf('Payment verification failed. Status: %s, Payment ID: %s', $razorpayPaymentStatus, $paymentId),
                'warning',
                [
                    'module_name' => 'order',
                    'module_id'   => $order->id,
                ]
            );
            $this->confirmationFailed(400);
        }

        $subscription = Subscription::query()
            ->where('parent_order_id', $order->id)
            ->first();

        $subscriptionUpdateData = [];

        if ($subscription) {
            $subscriptionUpdateData['vendor_subscription_id'] = $razorpaySubscriptionId;

            $razorpaySubStatus = Arr::get($razorpaySubscription, 'status');
            $fctStatus = RazorpayHelper::getFctStatusFromRazorpaySubscriptionStatus($razorpaySubStatus);

            if ($fctStatus == Status::SUBSCRIPTION_AUTHENTICATED) {
                $startAt = DateTime::anyTimeToGmt(Arr::get($razorpaySubscription, 'start_at'));
                $now = DateTime::gmtNow();

                if ($startAt > $now) {
                    $fctStatus = Status::SUBSCRIPTION_TRIALING;
                }
            } 

         
            
            $subscriptionUpdateData['status'] = $fctStatus;

            $nextBillingDate = RazorpayHelper::getNextBillingDate($razorpaySubscription);
            if ($nextBillingDate) {
                $subscriptionUpdateData['next_billing_date'] = $nextBillingDate;
            }

            $subscriptionUpdateData['vendor_response'] = $razorpaySubscription;

            $this->confirmPaymentSuccessByCharge($transactionModel, $razorpayPayment, $subscriptionUpdateData);
           
            fluent_cart_add_log(
                'Razorpay Subscription Confirmed',
                sprintf(
                    'Subscription %d confirmed. Razorpay Subscription: %s, Payment: %s',
                    $subscription->id,
                    $razorpaySubscriptionId,
                    $paymentId
                ),
                'info',
                [
                    'module_name' => 'subscription',
                    'module_id'   => $subscription->id,
                ]
            );
        }

        wp_send_json_success([
            'message'      => __('Subscription payment confirmed', 'razorpay-for-fluent-cart'),
            'redirect_url' => $transactionModel->getReceiptPageUrl()
        ]);
    }


    /**
     * Confirm payment success and update transaction
     * This method is called from:
     * 1. Modal payment confirmation (via AJAX from RazorpayGateway::confirmModalPayment)
     * 2. Hosted payment confirmation (via redirect callback)
     * 3. Webhook payment confirmation
     */
    public function confirmPaymentSuccessByCharge(OrderTransaction $transaction, $chargeData = [], $subscriptionUpdateData = [] )
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

        $status = RazorpayHelper::getFctStatusFromRazorpayStatus($status);

        if ($status === Status::TRANSACTION_REFUNDED && $transaction->total <= 0) {
            $status = Status::TRANSACTION_SUCCEEDED;
            $amount = 0;
        }

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

        $method = Arr::get($chargeData, 'method', 'card');
        $billingInfo = [
            'payment_method'   => $method,
            'vendor' => 'razorpay',
        ];

        if ($method === 'card') {
            $card = Arr::get($chargeData, 'card', []);
            $billingInfo['card'] = [
                'last4'   => Arr::get($card, 'last4', ''),
                'brand'   => Arr::get($card, 'network', ''),
                'type'    => Arr::get($card, 'type', ''),
            ];
        } elseif ($method === 'upi') {
            $billingInfo['upi'] = [
                'vpa' => Arr::get($chargeData, 'vpa', ''),
            ];
        } elseif ($method === 'netbanking') {
            $billingInfo['bank'] = Arr::get($chargeData, 'bank', '');
        } elseif ($method === 'wallet') {
            $billingInfo['wallet'] = Arr::get($chargeData, 'wallet', '');
        } elseif ($method == 'vpa') {
            $billingInfo['vpa'] = Arr::get($chargeData, 'vpa', '');
        } elseif ($method === 'bank') {
            $billingInfo['bank'] = Arr::get($chargeData, 'bank', '');
        }

        $updateData['card_last_4'] = Arr::get($billingInfo, 'last4', '');
        $updateData['card_brand'] = Arr::get($billingInfo, 'brand', '');
        $updateData['payment_method_type'] = $method;
        $updateData['meta'] = $billingInfo;

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

        $subscription = Subscription::query()->where('id', $transaction->subscription_id)->first();
        if ($order->type === Status::ORDER_TYPE_RENEWAL) {

            $razorpaySubscription = Arr::get($subscriptionUpdateData, 'vendor_response', []);
            $paymentMethod = Arr::get($razorpaySubscription, 'payment_method', '');

            if ($paymentMethod) {
                $billingInfo['payment_method'] = $paymentMethod;
                if ($paymentMethod === 'card') { 
                    $billingInfo['payment_method'] = 'card';
                    $billingInfo['card_mandate_id'] = Arr::get($razorpaySubscription, 'card_mandate_id', '');
                }
            }
        
            $subscriptionUpdateData = array_merge($subscriptionUpdateData, [
                'current_payment_method' => 'razorpay',
                'canceled_at' => null,
                'status' => Status::SUBSCRIPTION_ACTIVE,
            ]);

            (new SubscriptionService())->recordManualRenewal($subscription, $transaction, [
                'billing_info' => $billingInfo,
                'subscription_args' => $subscriptionUpdateData
            ]);

            $subscription->updateMeta('active_payment_method', $billingInfo);

        } else {
            if ($subscription) {
                $subscription->fill($subscriptionUpdateData);
                $subscription->save();

                $razorpaySubscription = Arr::get($subscriptionUpdateData, 'vendor_response', []);
                $paymentMethod = Arr::get($razorpaySubscription, 'payment_method', '');

                if ($paymentMethod) {
                    $billingInfo['payment_method'] = $paymentMethod;
                    if ($paymentMethod === 'card') { 
                        $billingInfo['payment_method'] = 'card';
                        $billingInfo['card_mandate_id'] = Arr::get($razorpaySubscription, 'card_mandate_id', '');
                    }
                }

                $subscription->updateMeta('active_payment_method', $billingInfo);

                if (in_array($subscription->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING])) {
                    (new SubscriptionActivated($subscription, $order, $order->customer))->dispatch();
                }
            }

            // sync order statuses
            (new StatusHelper($order))->syncOrderStatuses($transaction);
        }

        
        return $order;
    }

    public function confirmationFailed($code = 400)
    {
        wp_send_json_error([
            'message' => __('Payment confirmation failed', 'razorpay-for-fluent-cart')
        ], $code);
    }
}
