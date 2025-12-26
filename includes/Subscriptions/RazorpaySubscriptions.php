<?php
/**
 * Razorpay Subscriptions Module
 *
 * Follows Razorpay's subscription workflow:
 * 1. Create Plan
 * 2. Create Subscription (returns subscription_id)
 * 3. Authentication Transaction (uses subscription_id)
 * 4. Subscription becomes active when billing starts
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
     * Razorpay Flow:
     * 1. Create/Get Plan
     * 2. Create Subscription on Razorpay
     * 3. Return subscription_id for authentication transaction
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

        // Step 1: Create or get Razorpay plan
        $plan = self::getOrCreateRazorpayPlan($paymentInstance);

        if (is_wp_error($plan)) {
            return $plan;
        }

        $planId = Arr::get($plan, 'id');

        // Save plan ID to subscription
        $subscription->update([
            'vendor_plan_id' => $planId,
        ]);

        // Step 2: Create subscription on Razorpay (BEFORE authentication)
        $razorpaySubscription = $this->createRazorpaySubscription($paymentInstance, $planId);

        if (is_wp_error($razorpaySubscription)) {
            return $razorpaySubscription;
        }

        $subscriptionId = Arr::get($razorpaySubscription, 'id');
        $shortUrl = Arr::get($razorpaySubscription, 'short_url');

        // Save subscription ID
        $subscription->update([
            'vendor_subscription_id' => $subscriptionId,
            'vendor_customer_id' => Arr::get($razorpaySubscription, 'customer_id'),
        ]);

        // Store subscription info in transaction meta
        $transaction->updateMeta('razorpay_subscription_id', $subscriptionId);
        $transaction->updateMeta('razorpay_plan_id', $planId);

        // Prepare modal data for frontend
        $settings = new \RazorpayFluentCart\Settings\RazorpaySettingsBase();
        $modalData = [
            'api_key'      => $settings->getApiKey(),
            'name'         => get_bloginfo('name'),
            'description'  => $subscription->item_name,
            'prefill'      => [
                'name'  => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
                'email' => $fcCustomer->email,
                'contact' => $fcCustomer->phone ?: ''
            ],
            'theme'        => [
                'color' => apply_filters('razorpay_fc/modal_theme_color', '#3399cc')
            ],
        ];

        // Return response for authentication
        return [
            'status' => 'success',
            'nextAction' => 'razorpay',
            'actionName' => 'custom',
            'message' => __('Opening Razorpay payment popup...', 'razorpay-for-fluent-cart'),
            'payment_args' => array_merge($paymentArgs, [
                'modal_data' => $modalData,
                'transaction_hash' => $transaction->uuid,
                'checkout_type' => 'modal'
            ]),
            'data' => [
                'razorpay_subscription' => $razorpaySubscription,
                'subscription_id' => $subscriptionId,
                'short_url' => $shortUrl,
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
            . $transaction->total . '_'
            . $product->ID . '_'
            . $variation->id . '_'
            . $subscription->recurring_total . '_'
            . $subscription->billing_interval . '_'
            . $subscription->bill_times . '_'
            . $subscription->trial_days . '_'
            . $transaction->currency;

        $fctRazorpayPlanId = apply_filters('razorpay_fc/recurring_plan_id', $fctRazorpayPlanId, [
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
                'description' => $product->post_title . ' - ' . $variation->post_title
            ]
        ];

        // Add notes for tracking
        $planData['notes'] = [
            'fct_product_id' => $product->ID,
            'fct_variation_id' => $variation->id,
            'fluentcart_plan_id' => $fctRazorpayPlanId
        ];

        $planData = apply_filters('razorpay_fc/plan_data', $planData, [
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
     * Create subscription on Razorpay
     * 
     * This is called BEFORE authentication transaction
     * 
     * @param object $paymentInstance
     * @param string $planId
     * @return array|WP_Error
     */
    private function createRazorpaySubscription($paymentInstance, $planId)
    {
        $subscription = $paymentInstance->subscription;
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $order->customer;

        $subscriptionData = [
            'plan_id' => $planId,
            'customer_notify' => apply_filters('razorpay_fc/subscription_customer_notify', 1, [
                'subscription' => $subscription,
                'order' => $order
            ]),
            'quantity' => 1,
            'total_count' => $subscription->bill_times ?: 0, // 0 means infinite
            'notes' => [
                'order_hash' => $order->uuid,
                'subscription_hash' => $subscription->uuid,
                'transaction_hash' => $transaction->uuid,
                'customer_email' => $fcCustomer->email,
                'customer_name' => $fcCustomer->first_name . ' ' . $fcCustomer->last_name
            ]
        ];

        // Handle trial period - set start date in future
        if ($subscription->trial_days > 0) {
            $startDate = time() + ($subscription->trial_days * DAY_IN_SECONDS);
            $subscriptionData['start_at'] = $startDate;
        }

        // Handle upfront amount (setup fees, deposits, etc.)
        $upfrontAmount = (int)$subscription->signup_fee;
        if ($upfrontAmount > 0) {
            $subscriptionData['addons'] = [
                [
                    'item' => [
                        'name' => __('Setup Fee', 'razorpay-for-fluent-cart'),
                        'amount' => $upfrontAmount,
                        'currency' => strtoupper($transaction->currency)
                    ]
                ]
            ];
        }

        $subscriptionData = apply_filters('razorpay_fc/subscription_create_data', $subscriptionData, [
            'subscription' => $subscription,
            'order' => $order,
            'transaction' => $transaction
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
            return $razorpaySubscription;
        }

        fluent_cart_add_log(
            __('Razorpay Subscription Created', 'razorpay-for-fluent-cart'),
            'Subscription created on Razorpay. ID: ' . Arr::get($razorpaySubscription, 'id') . ', Status: ' . Arr::get($razorpaySubscription, 'status'),
            'info',
            [
                'module_name' => 'order',
                'module_id' => $order->id
            ]
        );

        return $razorpaySubscription;
    }

    /**
     * Update subscription status after authentication
     * Called after customer completes authentication transaction
     * 
     * @param Subscription $subscriptionModel
     * @param array $razorpayPayment Payment details from Razorpay
     * @return void
     */
    public function updateSubscriptionAfterAuth($subscriptionModel, $razorpayPayment = [])
    {
        $oldStatus = $subscriptionModel->status;
        $order = $subscriptionModel->order;

        // Fetch latest subscription data from Razorpay
        $vendorSubscriptionId = $subscriptionModel->vendor_subscription_id;
        
        if (!$vendorSubscriptionId) {
            return;
        }

        $razorpaySubscription = RazorpayAPI::getRazorpayObject('subscriptions/' . $vendorSubscriptionId);
        
        if (is_wp_error($razorpaySubscription)) {
            fluent_cart_add_log(
                __('Razorpay Subscription Update Failed', 'razorpay-for-fluent-cart'),
                $razorpaySubscription->get_error_message(),
                'error',
                [
                    'module_name' => 'subscription',
                    'module_id' => $subscriptionModel->id,
                ]
            );
            return;
        }

        // Get subscription update data
        $updateData = RazorpayHelper::getSubscriptionUpdateData($razorpaySubscription, $subscriptionModel);
        
        $subscriptionModel->update($updateData);

        // Store billing info if provided
        if ($razorpayPayment) {
            $billingInfo = [
                'payment_method_type' => Arr::get($razorpayPayment, 'method'),
            ];

            if ($card = Arr::get($razorpayPayment, 'card')) {
                $billingInfo['card_brand'] = Arr::get($card, 'network');
                $billingInfo['card_last_4'] = Arr::get($card, 'last4');
                $billingInfo['card_type'] = Arr::get($card, 'type');
            }

            $subscriptionModel->updateMeta('active_payment_method', $billingInfo);
        }

        fluent_cart_add_log(
            __('Razorpay Subscription Authentication Complete', 'razorpay-for-fluent-cart'),
            'Subscription ID: ' . $vendorSubscriptionId . ', Status: ' . Arr::get($razorpaySubscription, 'status'),
            'info',
            [
                'module_name' => 'subscription',
                'module_id' => $subscriptionModel->id,
            ]
        );

        // Dispatch activation event if status changed to active or trialing
        if ($oldStatus != $subscriptionModel->status && 
            (Status::SUBSCRIPTION_ACTIVE === $subscriptionModel->status || 
             Status::SUBSCRIPTION_TRIALING === $subscriptionModel->status)) {
            (new SubscriptionActivated($subscriptionModel, $order, $order->customer))->dispatch();
        }
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

        // Fetch all invoices for this subscription
        $invoices = RazorpayAPI::getRazorpayObject('invoices', [
            'subscription_id' => $vendorSubscriptionId
        ]);

        if (!is_wp_error($invoices)) {
            $invoiceItems = Arr::get($invoices, 'items', []);
            $newPayment = false;
            $order = $subscriptionModel->order;

            foreach ($invoiceItems as $invoice) {
                // Only process paid invoices
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

