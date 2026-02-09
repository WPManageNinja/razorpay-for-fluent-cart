<?php

namespace RazorpayFluentCart\Subscription;

use FluentCart\Framework\Support\Arr;
use RazorpayFluentCart\API\RazorpayAPI;

if (!defined('ABSPATH')) {
    exit;
}

class RazorpayPlan
{
    public static function getOrCreatePlan($data)
    {
        $amount = Arr::get($data, 'amount');
        $currency = strtoupper(Arr::get($data, 'currency', 'INR'));
        $billingInterval = Arr::get($data, 'billing_interval');
        $variationId = Arr::get($data, 'variation_id', 0);
        $itemName = Arr::get($data, 'item_name', 'Subscription Plan');
        $trialDays = Arr::get($data, 'trial_days', 0);

        if (!$amount || !$billingInterval) {
            return new \WP_Error(
                'razorpay_plan_error',
                __('Amount and billing interval are required for plan creation.', 'razorpay-for-fluent-cart')
            );
        }

        $razorpayInterval = self::mapToRazorpayInterval($billingInterval);
        if (is_wp_error($razorpayInterval)) {
            return $razorpayInterval;
        }

        $period = $razorpayInterval['period'];
        $interval = $razorpayInterval['interval'];

        if ($period == 'daily' && $interval < 7) {
            return new \WP_Error(
                'razorpay_plan_error',
                __('Subscription cannot be created with daily interval less than 7 days.', 'razorpay-for-fluent-cart')
            );
        }

        $generatedPlanId = self::generatePlanId($variationId, $amount, $period, $interval, $currency, $trialDays);

        $cachedPlan = self::getCachedPlan($generatedPlanId);
        if ($cachedPlan) {
            $existingPlan = RazorpayAPI::getRazorpayObject('plans/' . $cachedPlan);
            if (!is_wp_error($existingPlan) && Arr::get($existingPlan, 'id')) {
                return $existingPlan;
            }
        }
        $planData = [
            'period'   => $period,
            'interval' => $interval,
            'item'     => [
                'name'     => $itemName,
                'amount'   => (int) $amount,
                'currency' => $currency,
            ],
            'notes'    => [
                'fluent_cart_plan_id' => $generatedPlanId,
                'variation_id'        => $variationId,
                'billing_interval'    => $billingInterval,
            ],
        ];

        $plan = RazorpayAPI::createRazorpayObject('plans', $planData);

        if (is_wp_error($plan)) {
            return $plan;
        }

        self::cachePlan($generatedPlanId, $plan['id']);

        return $plan;
    }

    public static function generatePlanId($variationId, $amount, $period, $interval, $currency, $trialDays = 0)
    {
        return sprintf(
            'fct_razorpay_%d_%d_%s_%d_%s_%d',
            $variationId,
            $amount,
            $period,
            $interval,
            strtolower($currency),
            $trialDays ?? 0
        );
    }

    /**
     * FluentCart intervals: daily, weekly, monthly, quarterly, half_yearly, yearly
     * Razorpay supports: daily, weekly, monthly, yearly + interval multiplier (1-4095)
     */
    public static function mapToRazorpayInterval($billingInterval)
    {
        $mapping = [
            'daily'       => ['period' => 'daily', 'interval' => 1],
            'weekly'      => ['period' => 'weekly', 'interval' => 1],
            'monthly'     => ['period' => 'monthly', 'interval' => 1],
            'quarterly'   => ['period' => 'monthly', 'interval' => 3],
            'half_yearly' => ['period' => 'monthly', 'interval' => 6],
            'yearly'      => ['period' => 'yearly', 'interval' => 1],
        ];

        if (!isset($mapping[$billingInterval])) {
            return new \WP_Error(
                'razorpay_invalid_interval',
                sprintf(
                    __('Unsupported billing interval: %s. Supported: daily, weekly, monthly, quarterly, half_yearly, yearly.', 'razorpay-for-fluent-cart'),
                    $billingInterval
                )
            );
        }

        return $mapping[$billingInterval];
    }

    private static function getCachedPlan($planId)
    {
        $plans = get_option('fct_razorpay_plans', []);
        return Arr::get($plans, $planId);
    }

    private static function cachePlan($planId, $razorpayPlanId)
    {
        $plans = get_option('fct_razorpay_plans', []);
        $plans[$planId] = $razorpayPlanId;
        update_option('fct_razorpay_plans', $plans, false);
    }

    public static function getIntervalInSeconds($billingInterval)
    {
        // Define time constants if not already defined
        if (!defined('DAY_IN_SECONDS'))   define('DAY_IN_SECONDS', 86400);
        if (!defined('WEEK_IN_SECONDS'))  define('WEEK_IN_SECONDS', 604800);
        if (!defined('YEAR_IN_SECONDS'))  define('YEAR_IN_SECONDS', 31536000);

        $MONTH_IN_SECONDS = 30 * DAY_IN_SECONDS;

        $intervals = [
            'daily'       => DAY_IN_SECONDS,
            'weekly'      => WEEK_IN_SECONDS,
            'monthly'     => $MONTH_IN_SECONDS,
            'quarterly'   => 90 * DAY_IN_SECONDS,
            'half_yearly' => 182 * DAY_IN_SECONDS,
            'yearly'      => YEAR_IN_SECONDS,
        ];

        return isset($intervals[$billingInterval]) ? $intervals[$billingInterval] : $MONTH_IN_SECONDS;
    }
}
