<?php

namespace RazorpayFluentCart;

use FluentCart\App\Helpers\Status;

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
}