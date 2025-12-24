<?php

namespace RazorpayFluentCart;

use FluentCart\App\Helpers\Status;
use FluentCart\Api\CurrencySettings;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;

class RazorpayHelper
{

    public static function getFctStatusFromRazorpayStatus($status)
    {
        // map fct status to razorpay status
        $statusMap = [
            'pending' => Status::TRANSACTION_PENDING,
            'paid' => Status::TRANSACTION_SUCCEEDED,
            'failed' => Status::TRANSACTION_FAILED,
            'refunded' => Status::TRANSACTION_REFUNDED,
            'authorized' => Status::TRANSACTION_AUTHORIZED,
            'captured' => Status::TRANSACTION_SUCCEEDED,
        ];

        return $statusMap[$status] ?? Status::TRANSACTION_PENDING;
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

    /**
     * Map FluentCart billing interval to Razorpay period and interval
     * Razorpay supports: daily, weekly, monthly, yearly with interval count
     * 
     * @param string $interval FluentCart interval (daily, weekly, monthly, quarterly, half_yearly, yearly)
     * @return array ['period' => 'monthly', 'interval' => 3]
     */
    public static function mapIntervalToRazorpay($interval)
    {
        $intervalMap = [
            'daily' => [
                'period' => 'daily',
                'interval' => 1
            ],
            'weekly' => [
                'period' => 'weekly',
                'interval' => 1
            ],
            'monthly' => [
                'period' => 'monthly',
                'interval' => 1
            ],
            'quarterly' => [
                'period' => 'monthly',
                'interval' => 3 // 3 months
            ],
            'half_yearly' => [
                'period' => 'monthly',
                'interval' => 6 // 6 months
            ],
            'yearly' => [
                'period' => 'yearly',
                'interval' => 1
            ],
        ];

        return $intervalMap[$interval] ?? ['period' => 'monthly', 'interval' => 1];
    }

    /**
     * Get FluentCart subscription status from Razorpay status
     * 
     * @param string $status Razorpay subscription status
     * @return string FluentCart subscription status
     */
    public static function getFctSubscriptionStatus($status)
    {
        $statusMap = [
            'created' => Status::SUBSCRIPTION_PENDING,
            'authenticated' => Status::SUBSCRIPTION_TRIALING,
            'active' => Status::SUBSCRIPTION_ACTIVE,
            'pending' => Status::SUBSCRIPTION_PENDING,
            'halted' => Status::SUBSCRIPTION_PAUSED,
            'cancelled' => Status::SUBSCRIPTION_CANCELED,
            'completed' => Status::SUBSCRIPTION_EXPIRED,
            'expired' => Status::SUBSCRIPTION_EXPIRED,
            'paused' => Status::SUBSCRIPTION_PAUSED,
        ];

        return $statusMap[$status] ?? Status::SUBSCRIPTION_PENDING;
    }

    /**
     * Get minimum amount for authorization based on currency
     * Used for trial periods where the initial charge is 0
     * 
     * @param string $currency Currency code
     * @return int Minimum amount in smallest currency unit (paise, cents, etc.)
     */
    public static function getMinimumAmountForAuthorization($currency)
    {
        $currency = strtoupper($currency);
        
        // Zero-decimal currencies (amount is in units, not subunits)
        $zeroDecimalCurrencies = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
        
        if (in_array($currency, $zeroDecimalCurrencies)) {
            return 100; // 100 units for zero-decimal currencies
        }
        
        // Most common currencies - 100 subunits (1.00 in major unit)
        $minimumAmounts = [
            'INR' => 100,    // ₹1.00
            'USD' => 100,    // $1.00
            'EUR' => 100,    // €1.00
            'GBP' => 100,    // £1.00
            'AUD' => 100,    // A$1.00
            'CAD' => 100,    // C$1.00
            'SGD' => 100,    // S$1.00
            'MYR' => 100,    // RM1.00
        ];

        return $minimumAmounts[$currency] ?? 100; // Default to 100 subunits (1.00 major unit)
    }

    /**
     * Get subscription update data from Razorpay subscription object
     * 
     * @param array $razorpaySubscription Razorpay subscription data
     * @param object $subscriptionModel FluentCart subscription model
     * @return array Update data for subscription
     */
    public static function getSubscriptionUpdateData($razorpaySubscription, $subscriptionModel)
    {
        $status = self::getFctSubscriptionStatus(Arr::get($razorpaySubscription, 'status'));

        $subscriptionUpdateData = array_filter([
            'current_payment_method' => 'razorpay',
            'status' => $status
        ]);

        // Handle cancellation
        if ($status === Status::SUBSCRIPTION_CANCELED) {
            $canceledAt = Arr::get($razorpaySubscription, 'cancelled_at');
            if ($canceledAt) {
                $subscriptionUpdateData['canceled_at'] = DateTime::anyTimeToGmt($canceledAt)->format('Y-m-d H:i:s');
            } else {
                $subscriptionUpdateData['canceled_at'] = DateTime::gmtNow()->format('Y-m-d H:i:s');
            }
        }

        // Handle next billing date
        $chargeAt = Arr::get($razorpaySubscription, 'charge_at');
        if ($chargeAt) {
            $subscriptionUpdateData['next_billing_date'] = DateTime::anyTimeToGmt($chargeAt)->format('Y-m-d H:i:s');
        }

        return $subscriptionUpdateData;
    }

}