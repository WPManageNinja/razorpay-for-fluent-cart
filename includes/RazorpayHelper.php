<?php

namespace RazorpayFluentCart;

use FluentCart\App\Helpers\Status;
use FluentCart\Api\CurrencySettings;

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

}