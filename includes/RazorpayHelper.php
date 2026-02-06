<?php

namespace RazorpayFluentCart;

use FluentCart\App\Helpers\Status;
use FluentCart\Api\CurrencySettings;
use FluentCart\Framework\Support\Arr;
use RazorpayFluentCart\API\RazorpayAPI;

class RazorpayHelper
{
    public static function getFctStatusFromRazorpayStatus($status)
    {
        $statusMap = [
            'pending'    => Status::TRANSACTION_PENDING,
            'paid'       => Status::TRANSACTION_SUCCEEDED,
            'failed'     => Status::TRANSACTION_FAILED,
            'refunded'   => Status::TRANSACTION_REFUNDED,
            'authorized' => Status::TRANSACTION_AUTHORIZED,
            'captured'   => Status::TRANSACTION_SUCCEEDED,
        ];

        return $statusMap[$status] ?? Status::TRANSACTION_PENDING;
    }

    public static function getFctStatusFromRazorpaySubscriptionStatus($status)
    {
        $statusMap = [
            'created'       => Status::SUBSCRIPTION_INTENDED,
            'authenticated' => Status::SUBSCRIPTION_TRIALING,
            'active'        => Status::SUBSCRIPTION_ACTIVE,
            'pending'       => Status::SUBSCRIPTION_PENDING,
            'halted'        => Status::SUBSCRIPTION_EXPIRING,
            'cancelled'     => Status::SUBSCRIPTION_CANCELED,
            'completed'     => Status::SUBSCRIPTION_COMPLETED,
            'expired'       => Status::SUBSCRIPTION_EXPIRED,
            'paused'        => Status::SUBSCRIPTION_PAUSED,
            'trialing'      => Status::SUBSCRIPTION_TRIALING,
        ];

        return $statusMap[$status] ?? Status::SUBSCRIPTION_PENDING;
    }

    public static function checkCurrencySupport()
    {
        return in_array(strtoupper(CurrencySettings::get('currency')), self::getRazorpaySupportedCurrency());
    }

    public static function getRazorpaySupportedCurrency(): array
    {
        return apply_filters('fluent_cart/razorpay_supported_currencies', [
            'AED', 'ALL', 'AMD', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN',
            'BHD', 'BIF', 'BMD', 'BND', 'BOB', 'BRL', 'BSD', 'BTN', 'BWP', 'BZD',
            'CAD', 'CHF', 'CLP', 'CNY', 'COP', 'CRC', 'CUP', 'CVE', 'CZK', 'DJF',
            'DKK', 'DOP', 'DZD', 'EGP', 'ETB', 'EUR', 'FJD', 'GBP', 'GHS', 'GIP',
            'GMD', 'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HRK', 'HTG', 'HUF', 'IDR',
            'ILS', 'INR', 'IQD', 'ISK', 'JMD', 'JOD', 'JPY', 'KES', 'KGS', 'KHR',
            'KMF', 'KRW', 'KWD', 'KYD', 'KZT', 'LAK', 'LKR', 'LRD', 'LSL', 'MAD',
            'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MUR', 'MVR', 'MWK', 'MXN',
            'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'OMR', 'PEN',
            'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RUB', 'RWF',
            'SAR', 'SCR', 'SEK', 'SGD', 'SLL', 'SOS', 'SVC', 'SZL', 'THB', 'TND',
            'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD', 'UYU', 'UZS', 'VND',
            'VUV', 'XAF', 'XCD', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMW'
        ], []);
    }

    public static function getIntervalInSeconds($billingInterval)
    {
        $intervals = [
            'daily'       => DAY_IN_SECONDS,
            'weekly'      => WEEK_IN_SECONDS,
            'monthly'     => 30 * DAY_IN_SECONDS,
            'quarterly'   => 90 * DAY_IN_SECONDS,
            'half_yearly' => 182 * DAY_IN_SECONDS,
            'yearly'      => YEAR_IN_SECONDS,
        ];

        return $intervals[$billingInterval] ?? (30 * DAY_IN_SECONDS);
    }

    public static function createOrGetRazorpayCustomer($fcCustomer)
    {
        $razorpayCustomerId = $fcCustomer->getMeta('razorpay_customer_id', false);

        if ($razorpayCustomerId) {
            $existingCustomer = RazorpayAPI::getRazorpayObject('customers/' . $razorpayCustomerId);

            if (!is_wp_error($existingCustomer) && Arr::get($existingCustomer, 'id')) {
                return $existingCustomer;
            }
        }

        $customerData = [
            'name'    => trim($fcCustomer->first_name . ' ' . $fcCustomer->last_name),
            'email'   => $fcCustomer->email,
            'contact' => $fcCustomer->phone ?: '',
            'notes'   => [
                'fluent_cart_customer_id' => $fcCustomer->id,
            ],
        ];

        if (empty($customerData['contact'])) {
            unset($customerData['contact']);
        }

        $razorpayCustomer = RazorpayAPI::createRazorpayObject('customers', $customerData);

        if (is_wp_error($razorpayCustomer)) {
            return $razorpayCustomer;
        }

        $id = Arr::get($razorpayCustomer, 'id', false);

        if ($id) {
            $fcCustomer->updateMeta('razorpay_customer_id', $id);
        }

        return $razorpayCustomer;
    }

    public static function calculateNextBillingDate($razorpaySubscription)
    {
        $chargeAt = Arr::get($razorpaySubscription, 'charge_at');

        if ($chargeAt) {
            return gmdate('Y-m-d H:i:s', $chargeAt);
        }

        $currentEnd = Arr::get($razorpaySubscription, 'current_end');
        if ($currentEnd) {
            return gmdate('Y-m-d H:i:s', $currentEnd);
        }

        return null;
    }
}