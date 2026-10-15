<?php

namespace RazorpayFluentCart\Refund;

use FluentCart\Framework\Support\Arr;
use RazorpayFluentCart\API\RazorpayAPI;
use RazorpayFluentCart\RazorpayHelper;

class RazorpayRefund
{
    /**
     * Process remote refund on Razorpay
     */
    public static function processRemoteRefund($transaction, $amount, $args)
    {
        $razorpayPaymentId = $transaction->vendor_charge_id;

        if (!$razorpayPaymentId) {
            return new \WP_Error(
                'razorpay_refund_error',
                __('Payment ID not found for refund', 'razorpay-for-fluent-cart')
            );
        }

        // Prepare refund data
        $refundData = [
            'amount' => RazorpayHelper::toRazorpayAmount($amount, $transaction->currency)
        ];

        $refundSpeed = apply_filters('razorpay_fc/refund_speed', 'normal', [
            'transaction' => $transaction,
            'amount_in_cents' => $amount,
            'args' => $args
        ]);

        if (!empty($refundSpeed)) {
            $refundData['speed'] = $refundSpeed;
        }

        if (!empty($args['note'])) {
            $refundData['notes'] = [
                'merchant_note' => $args['note']
            ];
        }

        if (empty($args['note']) && !empty($args['reason'])) {
            $reasonMap = [
                'duplicate' => 'Duplicate payment',
                'fraudulent' => 'Fraudulent payment',
                'requested_by_customer' => 'Requested by customer'
            ];
            $refundData['notes'] = [
                'merchant_note' => $reasonMap[$args['reason']] ?? $args['reason']
            ];
        }

        $refund = RazorpayAPI::createRazorpayObject('payments/' . $razorpayPaymentId . '/refund', $refundData);

        if (is_wp_error($refund)) {
            return $refund;
        }

        // Razorpay refund structure: { id, entity, amount, currency, payment_id, status, ... }
        $refundId = Arr::get($refund, 'id');
        $refundStatus = Arr::get($refund, 'status');

        if (!$refundId) {
            return new \WP_Error(
                'razorpay_refund_error',
                __('Refund ID not found in Razorpay response', 'razorpay-for-fluent-cart')
            );
        }

        // Razorpay refund statuses: pending, processed, failed
        $acceptedStatus = ['pending', 'processed'];

        if (!in_array($refundStatus, $acceptedStatus)) {
            return new \WP_Error(
                'refund_failed',
                sprintf(
                    __('Refund could not be processed in Razorpay. Status: %s', 'razorpay-for-fluent-cart'),
                    $refundStatus
                )
            );
        }

        fluent_cart_add_log(
            __('Razorpay Refund Initiated', 'razorpay-for-fluent-cart'),
            sprintf('Refund created with ID: %s, Status: %s', $refundId, $refundStatus),
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $transaction->order_id,
            ]
        );

        return $refundId;
    }
}

