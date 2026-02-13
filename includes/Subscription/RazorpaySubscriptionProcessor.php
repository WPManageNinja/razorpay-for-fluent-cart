<?php

namespace RazorpayFluentCart\Subscription;

use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\Framework\Support\Arr;
use RazorpayFluentCart\API\RazorpayAPI;
use RazorpayFluentCart\RazorpayHelper;
use RazorpayFluentCart\Settings\RazorpaySettingsBase;
use FluentCart\App\Helpers\CurrenciesHelper;

if (!defined('ABSPATH')) {
    exit;
}

class RazorpaySubscriptionProcessor
{
    public function handleSubscription(PaymentInstance $paymentInstance, $paymentArgs = [])
    {
        $order = $paymentInstance->order;

        $settings = new RazorpaySettingsBase();
        $checkoutType = $settings->get('checkout_type');

        if ($checkoutType !== 'modal') {
            return new \WP_Error(
                'razorpay_subscription_error',
                __('Hosted checkout is not supported for subscriptions. Please use modal checkout.', 'razorpay-for-fluent-cart')
            );
        }

        $isRenewal = $order->type === 'renewal';

        if ($isRenewal) {
            return $this->handleRenewalSubscription($paymentInstance, $order,$paymentArgs);
        }

        return $this->handleInitialSubscription($paymentInstance, $order, $paymentArgs);
    }

    /**
     * Decision logic for 5 scenarios:
     * 1. Normal: first == recurring, no trial → No addon, no start_at
     * 2. Coupon with simulated trial: first < recurring, trial days → Addon + start_at
     * 3. Signup fee: first > recurring, no trial → Addon + start_at (1 billing period)
     * 4. Real trial ($0 first): first == 0, trial days → No addon, start_at
     * 5. 100% coupon ($0 first): first == 0, trial days (simulated) → No addon, start_at
     */
    private function handleInitialSubscription(PaymentInstance $paymentInstance, $order, $paymentArgs = [])
    {
        $transaction = $paymentInstance->transaction;
        $subscription = $paymentInstance->subscription;
        $fcCustomer = $order->customer;

        $currency = strtoupper($transaction->currency);
        $isZeroDecimal = CurrenciesHelper::isZeroDecimal($currency);

        $firstPayment = (int) $transaction->total;
        $recurringTotal = (int) $subscription->recurring_total;
        $trialDays = (int) $subscription->trial_days;
        $billingInterval = $subscription->billing_interval;
        $billTimes = (int) $subscription->bill_times;

        if ($isZeroDecimal) {
            $firstPayment = (int) ($firstPayment / 100);
            $recurringTotal = (int) ($recurringTotal / 100);
        }

        $razorpayCustomer = RazorpayHelper::createOrGetRazorpayCustomer($fcCustomer);
        if (is_wp_error($razorpayCustomer)) {
            return $razorpayCustomer;
        }

        $plan = RazorpayPlan::getOrCreatePlan([
            'amount'           => $recurringTotal,
            'currency'         => $currency,
            'billing_interval' => $billingInterval,
            'variation_id'     => $subscription->variation_id,
            'item_name'        => $subscription->item_name,
            'trial_days'       => $trialDays,
        ]);

        if (is_wp_error($plan)) {
            return $plan;
        }

        // Build notify_info with phone if available
        $notifyInfo = ['notify_email' => $fcCustomer->email];
        if (!empty($fcCustomer->phone)) {
            $notifyInfo['notify_phone'] = $fcCustomer->phone;
        }

        $subscriptionData = [
            'plan_id'     => $plan['id'],
            'customer_id' => $razorpayCustomer['id'],
            'customer_notify' => 1,  // Enforce card saving - disable "Skip saving card" option
            'notify_info' => $notifyInfo,
            'notes'       => [
                'fluent_cart_order_id'        => $order->id,
                'fluent_cart_subscription_hash' => $subscription->uuid,
                'transaction_hash'              => $transaction->uuid,
                'order_hash'                  => $order->uuid,
                'customer_email'              => $fcCustomer->email,
            ],
        ];

        $totalCount = $billTimes;
        $useAddon = false;

        if ($firstPayment == 0) {
            // Scenario 4 & 5: Real trial or 100% coupon / upgrade
            if ($trialDays > 0) {
                $subscriptionData['start_at'] = time() + ($trialDays * DAY_IN_SECONDS);
            }
        } elseif ($firstPayment == $recurringTotal && $trialDays == 0) {
            // Scenario 1: Normal - plan handles first billing
        } else {
            // Scenario 2 & 3: Use addon for different first payment
            $useAddon = true;
            $subscriptionData['addons'] = [
                [
                    'item' => [
                        'name'     => __('Initial Payment', 'razorpay-for-fluent-cart'),
                        'amount'   => $firstPayment,
                        'currency' => $currency,
                    ],
                ],
            ];

            if ($trialDays > 0) {
                $subscriptionData['start_at'] = time() + ($trialDays * DAY_IN_SECONDS);
            } else {
                // Signup fee - delay by 1 billing period
                $subscriptionData['start_at'] = time() + RazorpayPlan::getIntervalInSeconds($billingInterval);
            }

            if ($totalCount > 0) {
                // Addon covers first period, reduce count by 1
                $totalCount = $totalCount - 1;
            }
        }

        if ($totalCount > 0) {
            $subscriptionData['total_count'] = $totalCount;
        } else {
            // For unlimited subscriptions (bill_times = 0), set appropriate total_count
            // Razorpay supports max 100 years, but cycles depend on billing interval
            $unlimitedCounts = [
                'daily'       => 3650,  // ~10 years
                'weekly'      => 520,   // ~10 years
                'monthly'     => 120,   // ~10 years
                'quarterly'   => 40,    // ~10 years
                'half_yearly' => 20,    // ~10 years
                'yearly'      => 100,   // 100 years (max supported by Razorpay)
            ];
            $subscriptionData['total_count'] = $unlimitedCounts[$billingInterval] ?? 120;
        }

        $razorpaySubscription = RazorpayAPI::createRazorpayObject('subscriptions', $subscriptionData);

        if (is_wp_error($razorpaySubscription)) {
            return $razorpaySubscription;
        }

        $transaction->update([
            'vendor_charge_id' => $razorpaySubscription['id'],
            'meta'             => array_merge($transaction->meta ?? [], [
                'razorpay_subscription_id' => $razorpaySubscription['id'],
                'razorpay_plan_id'         => $plan['id'],
                'use_addon'                => $useAddon,
            ]),
        ]);

        $subscription->update([
            'vendor_subscription_id' => $razorpaySubscription['id'],
            'vendor_plan_id'         => $plan['id'],
            'vendor_customer_id'     => $razorpayCustomer['id'],
        ]);

        $settings = new RazorpaySettingsBase();
        $modalData = [
            'subscription_id' => $razorpaySubscription['id'],
            'api_key'         => $settings->getApiKey(),
            'name'            => get_bloginfo('name'),
            'description'     => $subscription->item_name,
            'prefill'         => [
                'name'    => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
                'email'   => $fcCustomer->email,
                'contact' => $fcCustomer->phone ?: '',
            ],
            'theme'           => [
                'color' => apply_filters('razorpay_fc/modal_theme_color', '#3399cc'),
            ],
        ];

        return [
            'status'       => 'success',
            'nextAction'   => 'razorpay',
            'actionName'   => 'custom',
            'message'      => __('Payment Modal is opening, Please complete the payment', 'razorpay-for-fluent-cart'),
            'payment_args' => array_merge($paymentArgs, [
                'modal_data'       => $modalData,
                'transaction_hash' => $transaction->uuid,
                'order_hash'       => $order->uuid,
                'checkout_type'    => 'modal',
                'is_subscription'  => true,
            ]),
        ];
    }

    private function handleRenewalSubscription(PaymentInstance $paymentInstance, $order,$paymentArgs = [])
    {
        $transaction = $paymentInstance->transaction;
        $subscription = $paymentInstance->subscription;
        $fcCustomer = $order->customer;

        $currency = strtoupper($transaction->currency);
        $isZeroDecimal = CurrenciesHelper::isZeroDecimal($currency);

        $renewalAmount = (int) $subscription->recurring_total;
        $billingInterval = $subscription->billing_interval;
        $billTimes = (int) $subscription->getRequiredBillTimes();

        if ($isZeroDecimal) {
            $renewalAmount = (int) ($renewalAmount / 100);
        }

        $reactivationTrialDays = 0;
        if (method_exists($subscription, 'getReactivationTrialDays')) {
            $reactivationTrialDays = (int) $subscription->getReactivationTrialDays();
        }

        $razorpayCustomer = RazorpayHelper::createOrGetRazorpayCustomer($fcCustomer);
        if (is_wp_error($razorpayCustomer)) {
            return $razorpayCustomer;
        }

        $plan = RazorpayPlan::getOrCreatePlan([
            'amount'           => $renewalAmount,
            'currency'         => $currency,
            'billing_interval' => $billingInterval,
            'variation_id'     => $subscription->variation_id,
            'item_name'        => $subscription->item_name,
            'trial_days'       => $reactivationTrialDays,
        ]);

        if (is_wp_error($plan)) {
            return $plan;
        }

        // Build notify_info with phone if available
        $notifyInfo = ['notify_email' => $fcCustomer->email];
        if (!empty($fcCustomer->phone)) {
            $notifyInfo['notify_phone'] = $fcCustomer->phone;
        }

        $subscriptionData = [
            'plan_id'     => $plan['id'],
            'customer_id' => $razorpayCustomer['id'],
            'customer_notify' => 1,  // Enforce card saving - disable "Skip saving card" option
            'notify_info' => $notifyInfo,
            'notes'       => [
                'fluent_cart_order_id'        => $order->id,
                'fluent_cart_subscription_hash' => $subscription->uuid,
                'transaction_hash'            => $transaction->uuid,
                'order_hash'                  => $order->uuid,
                'customer_email'              => $fcCustomer->email,
                'is_renewal'                  => true,
            ],
        ];

        if ($billTimes > 0) {
            $completedBills = (int) $subscription->bill_count;
            $remainingBillTimes = max(0, $billTimes - $completedBills);
            if ($remainingBillTimes > 0) {
                $subscriptionData['total_count'] = $remainingBillTimes;
            }
        } else {
            // For unlimited subscriptions (bill_times = 0), set appropriate total_count
            $unlimitedCounts = [
                'daily'       => 3650,  // ~10 years
                'weekly'      => 520,   // ~10 years
                'monthly'     => 120,   // ~10 years
                'quarterly'   => 40,    // ~10 years
                'half_yearly' => 20,    // ~10 years
                'yearly'      => 100,   // 100 years (max supported by Razorpay)
            ];
            $subscriptionData['total_count'] = $unlimitedCounts[$billingInterval] ?? 120;
        }

        if ($reactivationTrialDays > 0) {
            $subscriptionData['start_at'] = time() + ($reactivationTrialDays * DAY_IN_SECONDS);
        }

        $razorpaySubscription = RazorpayAPI::createRazorpayObject('subscriptions', $subscriptionData);

        if (is_wp_error($razorpaySubscription)) {
            return $razorpaySubscription;
        }

        $oldSubscriptionId = $subscription->vendor_subscription_id;

        $transaction->update([
            'vendor_charge_id' => $razorpaySubscription['id'],
            'meta'             => array_merge($transaction->meta ?? [], [
                'razorpay_subscription_id' => $razorpaySubscription['id'],
                'razorpay_plan_id'         => $plan['id'],
                'is_renewal'               => true,
                'old_subscription_id'      => $oldSubscriptionId,
            ]),
        ]);

        $subscription->update([
            'vendor_subscription_id' => $razorpaySubscription['id'],
            'vendor_plan_id'         => $plan['id'],
            'vendor_customer_id'     => $razorpayCustomer['id'],
        ]);

        $oldSubscriptions = $subscription->getMeta('old_subscriptions', []);
        if ($oldSubscriptionId) {
            $oldSubscriptions[] = [
                'vendor_subscription_id' => $oldSubscriptionId,
                'replaced_at'            => gmdate('Y-m-d H:i:s'),
                'reason'                 => 'renewal',
            ];
            $subscription->updateMeta('old_subscriptions', $oldSubscriptions);
        }

        $settings = new RazorpaySettingsBase();
        $modalData = [
            'subscription_id' => $razorpaySubscription['id'],
            'api_key'         => $settings->getApiKey(),
            'name'            => get_bloginfo('name'),
            'description'     => $subscription->item_name . ' - ' . __('Renewal/Reactivation', 'razorpay-for-fluent-cart'),
            'prefill'         => [
                'name'    => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
                'email'   => $fcCustomer->email,
                'contact' => $fcCustomer->phone ?: '',
            ],
            'theme'           => [
                'color' => apply_filters('razorpay_fc/modal_theme_color', '#3399cc'),
            ],
        ];

        return [
            'status'       => 'success',
            'nextAction'   => 'razorpay',
            'actionName'   => 'custom',
            'message'      => __('Payment Modal is opening, Please complete the payment', 'razorpay-for-fluent-cart'),
            'payment_args' => array_merge($paymentArgs, [
                'modal_data'       => $modalData,
                'transaction_hash' => $transaction->uuid,
                'order_hash'       => $order->uuid,
                'checkout_type'    => 'modal',
                'is_subscription'  => true,
                'is_renewal'       => true,
            ]),
        ];
    }
}
