<?php

namespace RazorpayFluentCart\Subscription;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\DateTime\DateTime;
use RazorpayFluentCart\API\RazorpayAPI;
use RazorpayFluentCart\RazorpayHelper;

if (!defined('ABSPATH')) {
    exit;
}

class RazorpaySubscriptions extends AbstractSubscriptionModule
{
    /**
     * Cancel subscription on Razorpay
     *
     * @param string $vendorSubscriptionId
     * @param array  $args
     *
     * @return array|WP_Error
     */
    public function cancel($vendorSubscriptionId, $args = [])
    {
        if (empty($vendorSubscriptionId)) {
            return new \WP_Error(
                'razorpay_cancel_error',
                __('Subscription ID is required for cancellation.', 'razorpay-for-fluent-cart')
            );
        }

        $cancelAtCycleEnd = Arr::get($args, 'cancel_at_cycle_end', false);

        $endpoint = 'subscriptions/' . $vendorSubscriptionId . '/cancel';
        $data = [];

        if ($cancelAtCycleEnd) {
            $data['cancel_at_cycle_end'] = 1;
        }

        $response = RazorpayAPI::createRazorpayObject($endpoint, $data);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = Arr::get($response, 'status');
        $endedAt = Arr::get($response, 'ended_at');

        return [
            'status'      => RazorpayHelper::getFctStatusFromRazorpaySubscriptionStatus($status),
            'canceled_at' => $endedAt ? gmdate('Y-m-d H:i:s', $endedAt) : gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * Pause subscription on Razorpay
     *
     * @param array        $data
     * @param Order        $order
     * @param Subscription $subscription
     *
     * @return array|WP_Error
     */
    public function pauseSubscription($data, $order, $subscription)
    {
        $vendorSubscriptionId = $subscription->vendor_subscription_id;

        if (empty($vendorSubscriptionId)) {
            return new \WP_Error(
                'razorpay_pause_error',
                __('No Razorpay subscription ID found for this subscription.', 'razorpay-for-fluent-cart')
            );
        }

        $pauseAt = Arr::get($data, 'pause_at', 'now');

        $endpoint = 'subscriptions/' . $vendorSubscriptionId . '/pause';
        $requestData = [
            'pause_at' => $pauseAt,
        ];

        $response = RazorpayAPI::createRazorpayObject($endpoint, $requestData);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = Arr::get($response, 'status');
        $pausedAt = Arr::get($response, 'paused_at');

        return [
            'status'    => RazorpayHelper::getFctStatusFromRazorpaySubscriptionStatus($status),
            'paused_at' => $pausedAt ? gmdate('Y-m-d H:i:s', $pausedAt) : gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * Resume subscription on Razorpay
     *
     * @param array        $data
     * @param Order        $order
     * @param Subscription $subscription
     *
     * @return array|WP_Error
     */
    public function resumeSubscription($data, $order, $subscription)
    {
        $vendorSubscriptionId = $subscription->vendor_subscription_id;

        if (empty($vendorSubscriptionId)) {
            return new \WP_Error(
                'razorpay_resume_error',
                __('No Razorpay subscription ID found for this subscription.', 'razorpay-for-fluent-cart')
            );
        }

        $resumeAt = Arr::get($data, 'resume_at', 'now');

        $endpoint = 'subscriptions/' . $vendorSubscriptionId . '/resume';
        $requestData = [
            'resume_at' => $resumeAt,
        ];

        $response = RazorpayAPI::createRazorpayObject($endpoint, $requestData);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = Arr::get($response, 'status');

        return [
            'status'     => RazorpayHelper::getFctStatusFromRazorpaySubscriptionStatus($status),
            'resumed_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * Re-sync subscription from Razorpay
     *
     * Fetches the latest subscription state and any new invoices/payments
     *
     * @param Subscription $subscriptionModel
     *
     * @return Subscription|WP_Error
     */
    public function reSyncSubscriptionFromRemote(Subscription $subscriptionModel)
    {
        $vendorSubscriptionId = $subscriptionModel->vendor_subscription_id;

        if (empty($vendorSubscriptionId)) {
            return new \WP_Error(
                'razorpay_sync_error',
                __('No Razorpay subscription ID found.', 'razorpay-for-fluent-cart')
            );
        }

        $razorpaySubscription = RazorpayAPI::getRazorpayObject('subscriptions/' . $vendorSubscriptionId);

        if (is_wp_error($razorpaySubscription)) {
            return $razorpaySubscription;
        }

        $razorpayStatus = Arr::get($razorpaySubscription, 'status');
        $nextBillingDate = RazorpayHelper::getNextBillingDate($razorpaySubscription);
        $fctSubStatus = RazorpayHelper::getFctStatusFromRazorpaySubscriptionStatus($razorpayStatus);

        if ($fctSubStatus == Status::SUBSCRIPTION_AUTHENTICATED) {
            $startAt = DateTime::anyTimeToGmt(Arr::get($razorpaySubscription, 'start_at'));
            $now = DateTime::gmtNow();

            if ($startAt > $now) {
                $fctSubStatus = Status::SUBSCRIPTION_TRIALING;
            }
        } 


        $subscriptionUpdateData = [
            'status'          => $fctSubStatus,
            'vendor_response' => $razorpaySubscription,
        ];

        if ($nextBillingDate) {
            $subscriptionUpdateData['next_billing_date'] = $nextBillingDate;
        }

        $endedAt = Arr::get($razorpaySubscription, 'ended_at');
        if ($endedAt && in_array($razorpayStatus, ['cancelled', 'completed'])) {
            if ($razorpayStatus === 'cancelled') {
                $subscriptionUpdateData['canceled_at'] = gmdate('Y-m-d H:i:s', $endedAt);
            }
            $subscriptionUpdateData['expire_at'] = gmdate('Y-m-d H:i:s', $endedAt);
        }

        // Update active payment method, it's useful for only if subscription wasn't active already
        $activePaymentMethod = $subscriptionModel->getMeta('active_payment_method', []);
        $paymentMethod = Arr::get($razorpaySubscription, 'payment_method', '');
        if ($paymentMethod) {
            $activePaymentMethod['payment_method'] = $paymentMethod;
            if ($paymentMethod === 'card') { 
                $activePaymentMethod['payment_method'] = 'card';
                $activePaymentMethod['card_mandate_id'] = Arr::get($razorpaySubscription, 'card_mandate_id', '');
            }
        }

        $subscriptionModel->updateMeta('active_payment_method', $activePaymentMethod);

        // Sync invoices
        $invoices = RazorpayAPI::getRazorpayObject('invoices', [
            'subscription_id' => $vendorSubscriptionId,
        ]);

        if (is_wp_error($invoices)) {
            fluent_cart_add_log(
                'Razorpay Subscription Sync',
                'Failed to fetch invoices: ' . $invoices->get_error_message(),
                'warning',
                [
                    'module_name' => 'subscription',
                    'module_id'   => $subscriptionModel->id,
                ]
            );
            $invoices = ['items' => []];
        }

        $invoiceItems = Arr::get($invoices, 'items', []);
  
        array_reverse($invoiceItems);

        $hasNewInvoice = false;

        foreach ($invoiceItems as $invoice) {
            $invoiceStatus = Arr::get($invoice, 'status');
            $paymentId = Arr::get($invoice, 'payment_id');
            $invoiceSubscriptionId = Arr::get($invoice, 'subscription_id');

            if ($invoiceSubscriptionId !== $vendorSubscriptionId) {
                fluent_cart_add_log(
                    'Razorpay Subscription Sync',
                    sprintf(
                        'Skipping invoice %s - belongs to subscription %s, not %s',
                        Arr::get($invoice, 'id'),
                        $invoiceSubscriptionId,
                        $vendorSubscriptionId
                    ),
                    'warning',
                    [
                        'module_name' => 'subscription',
                        'module_id'   => $subscriptionModel->id,
                    ]
                );
                continue;
            }

            if ($invoiceStatus !== 'paid' || empty($paymentId)) {
                continue;
            }

            // Check if this payment already exists
            $existingTransaction = OrderTransaction::query()
                ->where('vendor_charge_id', $paymentId)
                ->first();

            if ($existingTransaction) {
                continue;
            }

            $payment = RazorpayAPI::getRazorpayObject('payments/' . $paymentId);
            if (is_wp_error($payment)) {
                continue;
            }

            $transaction = OrderTransaction::query()
                ->where('vendor_charge_id', '')
                ->where('subscription_id', $subscriptionModel->id)
                ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                ->where('payment_method', 'razorpay')
                ->first();

            if ($transaction) {
                $transaction->update([
                    'vendor_charge_id' => $paymentId,
                    'status' => Status::TRANSACTION_SUCCEEDED
                ]);
                continue;
            }

            $amount = Arr::get($invoice, 'amount_paid', Arr::get($payment, 'amount', 0));
            $currency = Arr::get($invoice, 'currency', Arr::get($payment, 'currency', 'INR'));
            $paidAt = Arr::get($invoice, 'paid_at', Arr::get($payment, 'created_at'));

            $transactionData = [
                'subscription_id'  => $subscriptionModel->id,
                'payment_method'   => 'razorpay',
                'vendor_charge_id' => $paymentId,
                'total'            => (int) $amount,
                'currency'         => strtoupper($currency),
                'status'           => Status::TRANSACTION_SUCCEEDED,
                'created_at'       => $paidAt ? gmdate('Y-m-d H:i:s', $paidAt) : gmdate('Y-m-d H:i:s'),
                'meta'             => [
                    'razorpay_invoice_id' => Arr::get($invoice, 'id'),
                    'razorpay_payment_id' => $paymentId,
                    'synced_from_remote'  => true,
                ],
            ];

            $hasNewInvoice = true;

            SubscriptionService::recordRenewalPayment($transactionData, $subscriptionModel, $subscriptionUpdateData);

            fluent_cart_add_log(
                'Razorpay Subscription Sync',
                sprintf('Synced renewal payment: %s', $paymentId),
                'info',
                [
                    'module_name' => 'subscription',
                    'module_id'   => $subscriptionModel->id,
                ]
            );
        }

        // Sync subscription states
        if (!$hasNewInvoice) {
            $subscriptionModel = SubscriptionService::syncSubscriptionStates($subscriptionModel, $subscriptionUpdateData);
        }

        return $subscriptionModel;
    }

    /**
     * Update card/payment method
     *
     * @param array  $data
     * @param string $subscriptionId
     *
     * @return array|WP_Error
     */
    public function cardUpdate($data, $subscriptionId)
    {
        // TODO: Implement card update, not in use currently
        $subscription = Subscription::query()->find($subscriptionId);

        if (!$subscription) {
            return new \WP_Error(
                'razorpay_card_update_error',
                __('Subscription not found.', 'razorpay-for-fluent-cart')
            );
        }

        $vendorSubscriptionId = $subscription->vendor_subscription_id;

        if (empty($vendorSubscriptionId)) {
            return new \WP_Error(
                'razorpay_card_update_error',
                __('No Razorpay subscription ID found.', 'razorpay-for-fluent-cart')
            );
        }

        // Razorpay requires generating an update link for card updates
        $endpoint = 'subscriptions/' . $vendorSubscriptionId . '/update_card_url';
        $response = RazorpayAPI::createRazorpayObject($endpoint, []);

        if (is_wp_error($response)) {
            return $response;
        }

        $shortUrl = Arr::get($response, 'short_url');

        if (!$shortUrl) {
            return new \WP_Error(
                'razorpay_card_update_error',
                __('Failed to generate card update link.', 'razorpay-for-fluent-cart')
            );
        }

        return [
            'status'      => 'redirect',
            'redirect_url' => $shortUrl,
            'message'     => __('Redirecting to Razorpay to update your card...', 'razorpay-for-fluent-cart'),
        ];
    }
}
