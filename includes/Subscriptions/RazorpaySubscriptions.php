<?php
/**
 * Razorpay Subscriptions Module
 *
 * @package RazorpayFluentCart
 * @since 1.0.0
 */

namespace RazorpayFluentCart\Subscriptions;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\Order;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use FluentCart\App\Events\Subscription\SubscriptionActivated;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;
use RazorpayFluentCart\API\RazorpayAPI;
use RazorpayFluentCart\RazorpayHelper;

if (!defined('ABSPATH')) {
    exit; // Direct access not allowed.
}

class RazorpaySubscriptions extends AbstractSubscriptionModule
{
    /**
     * Handle subscription payment initialization
     * 
     * @param object $paymentInstance
     * @param array $paymentArgs
     * @return array|WP_Error
     */
    public function handleSubscription($paymentInstance, $paymentArgs)
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $paymentInstance->order->customer;
        $subscription = $paymentInstance->subscription;

        // Create or get Razorpay plan
        $plan = self::getOrCreateRazorpayPlan($paymentInstance);

        if (is_wp_error($plan)) {
            return $plan;
        }

        // Save plan ID to subscription
        $subscription->update([
            'vendor_plan_id' => Arr::get($plan, 'id'),
        ]);

        // Prepare Razorpay order data for authentication transaction
        $authAmount = (int)$transaction->total;
        
        // If total is 0 or less, use minimum amount for authorization
        // This happens with trial periods or when upfront amount is 0
        if ($authAmount <= 0) {
            $authAmount = RazorpayHelper::getMinimumAmountForAuthorization($transaction->currency);
        }

        // Create Razorpay order for authentication
        $orderData = [
            'amount' => $authAmount,
            'currency' => strtoupper($transaction->currency),
            'receipt' => $transaction->uuid,
            'notes' => [
                'order_hash' => $order->uuid,
                'transaction_hash' => $transaction->uuid,
                'subscription_hash' => $subscription->uuid,
                'customer_name' => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
                'razorpay_plan_id' => Arr::get($plan, 'id'),
                'intent' => 'subscription_auth',
                'amount_is_for_authorization_only' => $authAmount <= 0 ? 'yes' : 'no'
            ]
        ];

        // Apply filters for customization
        $orderData = apply_filters('fluent_cart/razorpay/subscription_order_args', $orderData, [
            'order' => $order,
            'transaction' => $transaction,
            'subscription' => $subscription
        ]);

        // Create Razorpay order
        $razorpayOrder = RazorpayAPI::createRazorpayObject('orders', $orderData);

        if (is_wp_error($razorpayOrder)) {
            return $razorpayOrder;
        }

        // Store razorpay order ID in transaction meta for later use
        $transaction->updateMeta('razorpay_order_id', Arr::get($razorpayOrder, 'id'));
        $transaction->updateMeta('razorpay_plan_id', Arr::get($plan, 'id'));

        return [
            'status' => 'success',
            'nextAction' => 'razorpay',
            'actionName' => 'custom',
            'message' => __('Opening Razorpay payment popup...', 'razorpay-for-fluent-cart'),
            'data' => [
                'razorpay_order' => $razorpayOrder,
                'intent' => 'subscription',
                'transaction_hash' => $transaction->uuid,
            ]
        ];
    }

    /**
     * Create or get Razorpay plan
     * 
     * @param object $paymentInstance
     * @return array|WP_Error
     */
    public static function getOrCreateRazorpayPlan($paymentInstance)
    {
        $subscription = $paymentInstance->subscription;
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $variation = $subscription->variation;
        $product = $subscription->product;

        // Map billing interval to Razorpay format
        $razorpayInterval = RazorpayHelper::mapIntervalToRazorpay($subscription->billing_interval);

        // Create unique plan identifier
        $fctRazorpayPlanId = 'fct_razorpay_plan_'
            . $order->mode . '_'
            . $product->id . '_'
            . $order->variation_id . '_'
            . $subscription->recurring_total . '_'
            . $subscription->billing_interval . '_'
            . $subscription->bill_times . '_'
            . $subscription->trial_days . '_'
            . $transaction->currency;

        $fctRazorpayPlanId = apply_filters('fluent_cart/razorpay_recurring_plan_id', $fctRazorpayPlanId, [
            'variation' => $variation,
            'product' => $product,
            'subscription' => $subscription
        ]);

        // Check if plan already exists in product meta
        $razorpayPlanId = $product->getProductMeta($fctRazorpayPlanId);

        if ($razorpayPlanId) {
            $plan = RazorpayAPI::getRazorpayObject('plans/' . $razorpayPlanId);
            if (!is_wp_error($plan) && Arr::get($plan, 'id')) {
                return $plan;
            }
        }

        // Create new plan
        $planData = [
            'period' => Arr::get($razorpayInterval, 'period'),
            'interval' => Arr::get($razorpayInterval, 'interval'),
            'item' => [
                'name' => substr($subscription->item_name, 0, 250), // Razorpay has 250 char limit
                'amount' => (int)($subscription->recurring_total),
                'currency' => strtoupper($transaction->currency),
                'description' => $fctRazorpayPlanId
            ]
        ];

        // Add notes for tracking
        $planData['notes'] = [
            'product_id' => $product->id,
            'variation_id' => $order->variation_id,
            'fluentcart_plan_id' => $fctRazorpayPlanId
        ];

        $planData = apply_filters('fluent_cart/razorpay/plan_data', $planData, [
            'subscription' => $subscription,
            'order' => $order,
            'product' => $product
        ]);

        $plan = RazorpayAPI::createRazorpayObject('plans', $planData);

        if (is_wp_error($plan)) {
            return $plan;
        }

        // Save plan ID to product meta for future use
        $product->updateProductMeta($fctRazorpayPlanId, Arr::get($plan, 'id'));

        return $plan;
    }

    /**
     * Re-sync subscription from Razorpay
     * 
     * @param Subscription $subscriptionModel
     * @return Subscription|WP_Error
     */
    public function reSyncSubscriptionFromRemote(Subscription $subscriptionModel)
    {
        if ($subscriptionModel->current_payment_method !== 'razorpay') {
            return new \WP_Error(
                'invalid_payment_method',
                __('This subscription is not using Razorpay as payment method.', 'razorpay-for-fluent-cart')
            );
        }

        $vendorSubscriptionId = $subscriptionModel->vendor_subscription_id;

        if (!$vendorSubscriptionId) {
            return new \WP_Error(
                'invalid_subscription',
                __('Invalid vendor subscription ID.', 'razorpay-for-fluent-cart')
            );
        }

        // Fetch subscription from Razorpay
        $razorpaySubscription = RazorpayAPI::getRazorpayObject('subscriptions/' . $vendorSubscriptionId);
        
        if (is_wp_error($razorpaySubscription)) {
            return $razorpaySubscription;
        }

        // Get subscription update data
        $subscriptionUpdateData = RazorpayHelper::getSubscriptionUpdateData($razorpaySubscription, $subscriptionModel);

        // Fetch all payments for this subscription
        $payments = RazorpayAPI::getRazorpayObject('subscriptions/' . $vendorSubscriptionId . '/invoices');

        if (is_wp_error($payments)) {
            // Log but don't fail - update subscription state anyway
            fluent_cart_add_log(
                __('Razorpay Subscription Sync - Payment Fetch Failed', 'razorpay-for-fluent-cart'),
                $payments->get_error_message(),
                'warning',
                [
                    'module_name' => 'subscription',
                    'module_id' => $subscriptionModel->id,
                ]
            );
        } else {
            // Process subscription invoices/payments
            $invoices = Arr::get($payments, 'items', []);
            $newPayment = false;
            $order = $subscriptionModel->order;

            foreach ($invoices as $invoice) {
                if (Arr::get($invoice, 'status') === 'paid') {
                    $paymentId = Arr::get($invoice, 'payment_id');
                    
                    if (!$paymentId) {
                        continue;
                    }

                    // Check if transaction already exists
                    $transaction = OrderTransaction::query()
                        ->where('vendor_charge_id', $paymentId)
                        ->first();

                    if (!$transaction) {
                        // Check if there's a pending transaction for this subscription
                        $transaction = OrderTransaction::query()
                            ->where('subscription_id', $subscriptionModel->id)
                            ->where('vendor_charge_id', '')
                            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                            ->first();

                        if ($transaction) {
                            // Update existing pending transaction
                            $transaction->update([
                                'vendor_charge_id' => $paymentId,
                                'status' => Status::TRANSACTION_SUCCEEDED,
                                'total' => Arr::get($invoice, 'amount_paid', 0),
                            ]);

                            (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);
                            continue;
                        }

                        // Create new transaction for renewal payment
                        $transactionData = [
                            'order_id' => $order->id,
                            'amount' => Arr::get($invoice, 'amount_paid', 0),
                            'currency' => Arr::get($invoice, 'currency'),
                            'vendor_charge_id' => $paymentId,
                            'status' => Status::TRANSACTION_SUCCEEDED,
                            'payment_method' => 'razorpay',
                            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
                            'created_at' => Arr::get($invoice, 'paid_at') 
                                ? DateTime::anyTimeToGmt(Arr::get($invoice, 'paid_at'))->format('Y-m-d H:i:s')
                                : DateTime::gmtNow()->format('Y-m-d H:i:s'),
                        ];

                        $newPayment = true;
                        SubscriptionService::recordRenewalPayment($transactionData, $subscriptionModel, $subscriptionUpdateData);
                    } elseif ($transaction->status !== Status::TRANSACTION_SUCCEEDED) {
                        // Update transaction status if it changed
                        $transaction->update([
                            'status' => Status::TRANSACTION_SUCCEEDED,
                        ]);

                        (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);
                    }
                }
            }

            // Update subscription data
            if (!$newPayment) {
                $subscriptionModel = SubscriptionService::syncSubscriptionStates($subscriptionModel, $subscriptionUpdateData);
            } else {
                $subscriptionModel = Subscription::query()->find($subscriptionModel->id);
            }
        }

        return $subscriptionModel;
    }

    /**
     * Create subscription on Razorpay after successful authentication
     * 
     * @param Subscription $subscriptionModel
     * @param array $args Expected: 'payment_id', 'razorpay_signature'
     * @return array
     */
    public function createSubscriptionOnRazorpay($subscriptionModel, $args = [])
    {
        $order = $subscriptionModel->order;
        $oldStatus = $subscriptionModel->status;

        // Get plan ID and customer info
        $planId = $subscriptionModel->vendor_plan_id;

        if (!$planId) {
            fluent_cart_add_log(
                __('Razorpay Subscription Creation Failed', 'razorpay-for-fluent-cart'),
                __('Plan ID not found for subscription.', 'razorpay-for-fluent-cart'),
                'error',
                [
                    'module_name' => 'order',
                    'module_id' => $order->id,
                ]
            );
            return [];
        }

        // Prepare subscription data
        $subscriptionData = [
            'plan_id' => $planId,
            'customer_notify' => apply_filters('fluent_cart/razorpay/subscription_customer_notify', 1, [
                'subscription' => $subscriptionModel,
                'order' => $order
            ]),
            'quantity' => 1,
            'total_count' => $subscriptionModel->bill_times ?: 0, // 0 means infinite
            'notes' => [
                'order_id' => $order->id,
                'subscription_id' => $subscriptionModel->id,
                'customer_email' => $order->customer->email
            ]
        ];

        // Handle trial period - set start date in future
        if ($subscriptionModel->trial_days > 0) {
            $startDate = time() + ($subscriptionModel->trial_days * DAY_IN_SECONDS);
            $subscriptionData['start_at'] = $startDate;
        }

        // Add addons if any (upfront amount, setup fees, etc.)
        $addons = Arr::get($args, 'addons', []);
        if (!empty($addons)) {
            $subscriptionData['addons'] = $addons;
        }

        $subscriptionData = apply_filters('fluent_cart/razorpay/subscription_create_data', $subscriptionData, [
            'subscription' => $subscriptionModel,
            'order' => $order
        ]);

        // Create subscription on Razorpay
        $razorpaySubscription = RazorpayAPI::createRazorpayObject('subscriptions', $subscriptionData);

        if (is_wp_error($razorpaySubscription)) {
            fluent_cart_add_log(
                __('Razorpay Subscription Creation Failed', 'razorpay-for-fluent-cart'),
                __('Failed to create subscription on Razorpay. Error: ', 'razorpay-for-fluent-cart') . $razorpaySubscription->get_error_message(),
                'error',
                [
                    'module_name' => 'order',
                    'module_id' => $order->id,
                ]
            );
            return [];
        }

        // Get status
        $status = RazorpayHelper::getFctSubscriptionStatus(Arr::get($razorpaySubscription, 'status'));

        // Update subscription model
        $updateData = [
            'vendor_subscription_id' => Arr::get($razorpaySubscription, 'id'),
            'status' => $status,
            'vendor_customer_id' => Arr::get($razorpaySubscription, 'customer_id'),
        ];

        // Set next billing date if available
        if (Arr::get($razorpaySubscription, 'charge_at')) {
            $updateData['next_billing_date'] = DateTime::anyTimeToGmt(
                Arr::get($razorpaySubscription, 'charge_at')
            )->format('Y-m-d H:i:s');
        }

        $subscriptionModel->update($updateData);

        // Store billing info if provided
        if (Arr::get($args, 'billingInfo')) {
            $subscriptionModel->updateMeta('active_payment_method', Arr::get($args, 'billingInfo', []));
        }

        fluent_cart_add_log(
            __('Razorpay Subscription Created', 'razorpay-for-fluent-cart'),
            'Subscription created on Razorpay. ID: ' . Arr::get($razorpaySubscription, 'id'),
            'info',
            [
                'module_name' => 'order',
                'module_id' => $order->id
            ]
        );

        // Dispatch activation event if status changed to active or trialing
        if ($oldStatus != $subscriptionModel->status && 
            (Status::SUBSCRIPTION_ACTIVE === $subscriptionModel->status || 
             Status::SUBSCRIPTION_TRIALING === $subscriptionModel->status)) {
            (new SubscriptionActivated($subscriptionModel, $order, $order->customer))->dispatch();
        }

        return $updateData;
    }

    /**
     * Cancel subscription on Razorpay
     * 
     * @param string $vendorSubscriptionId
     * @param array $args
     * @return array|WP_Error
     */
    public function cancel($vendorSubscriptionId, $args = [])
    {
        $subscriptionModel = Subscription::query()
            ->where('vendor_subscription_id', $vendorSubscriptionId)
            ->first();

        if (!$subscriptionModel) {
            return new \WP_Error(
                'invalid_subscription',
                __('Invalid vendor subscription ID.', 'razorpay-for-fluent-cart')
            );
        }

        // Cancel immediately or at end of cycle
        $cancelAtCycleEnd = Arr::get($args, 'cancel_at_cycle_end', false);

        $endpoint = 'subscriptions/' . $vendorSubscriptionId . '/cancel';
        $cancelData = [
            'cancel_at_cycle_end' => $cancelAtCycleEnd ? 1 : 0
        ];

        // Cancel subscription via Razorpay API
        $response = RazorpayAPI::createRazorpayObject($endpoint, $cancelData);

        if (is_wp_error($response)) {
            fluent_cart_add_log(
                'Razorpay Subscription Cancellation Failed',
                $response->get_error_message(),
                'error',
                [
                    'module_name' => 'subscription',
                    'module_id' => $subscriptionModel->id,
                ]
            );
            return $response;
        }

        if (Arr::get($response, 'status') !== 'cancelled') {
            return new \WP_Error(
                'cancellation_failed',
                __('Failed to cancel subscription on Razorpay.', 'razorpay-for-fluent-cart'),
                ['response' => $response]
            );
        }

        // Update subscription status
        $subscriptionModel->update([
            'status' => Status::SUBSCRIPTION_CANCELED,
            'canceled_at' => DateTime::gmtNow()->format('Y-m-d H:i:s')
        ]);

        $order = Order::query()->where('id', $subscriptionModel->parent_order_id)->first();

        fluent_cart_add_log(
            __('Razorpay Subscription Cancelled', 'razorpay-for-fluent-cart'),
            __('Subscription cancelled on Razorpay. ID: ', 'razorpay-for-fluent-cart') . $vendorSubscriptionId,
            'info',
            [
                'module_name' => 'order',
                'module_id' => $order->id,
            ]
        );

        fluent_cart_add_log(
            __('Razorpay Subscription Cancelled', 'razorpay-for-fluent-cart'),
            __('Subscription cancelled on Razorpay. ID: ', 'razorpay-for-fluent-cart') . $vendorSubscriptionId,
            'info',
            [
                'module_name' => 'subscription',
                'module_id' => $subscriptionModel->id,
            ]
        );

        return [
            'status' => Status::SUBSCRIPTION_CANCELED,
            'canceled_at' => DateTime::gmtNow()->format('Y-m-d H:i:s')
        ];
    }
}

