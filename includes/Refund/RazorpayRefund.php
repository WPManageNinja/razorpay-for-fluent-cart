<?php

namespace RazorpayFluentCart\Refund;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\Framework\Support\Arr;
use RazorpayFluentCart\API\RazorpayAPI;
use FluentCart\App\Helpers\CurrenciesHelper;

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
            'amount' => (int)$amount
        ];

        if (CurrenciesHelper::isZeroDecimal($transaction->currency)) {
            $refundData['amount'] = (int)$amount / 100;
        }

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

    /**
     * Create or update refund from webhook
     */
    public static function createOrUpdateIpnRefund($refundData, $parentTransaction)
    {
        $allRefunds = OrderTransaction::query()
            ->where('vendor_charge_id', $refundData['vendor_charge_id'])
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->orderBy('id', 'DESC')
            ->get();

        if ($allRefunds->isEmpty()) {
            // This is the first refund for this order
            $createdRefund = OrderTransaction::query()->create($refundData);
            
            // Update parent transaction refunded total
            if ($createdRefund) {
                PaymentHelper::updateTransactionRefundedTotal($parentTransaction, $createdRefund->total);
            }
            
            return $createdRefund instanceof OrderTransaction ? $createdRefund : null;
        }

        $existingLocalRefund = null;

        foreach ($allRefunds as $refund) {
            // Check if this exact refund already exists
            if ($refund->vendor_charge_id == $refundData['vendor_charge_id']) {
                if ($refund->total != $refundData['total'] || $refund->status != $refundData['status']) {
                    $refund->fill($refundData);
                    $refund->save();
                }

                return $refund;
            }

            // Check for local refund without vendor charge id
            if (!$refund->vendor_charge_id) {
                $refundedAmount = $refund->total;

                // This is a local refund without vendor charge id, we will update it
                if ($refundedAmount == $refundData['total']) {
                    $existingLocalRefund = $refund;
                }
            }
        }

        if ($existingLocalRefund) {
            $existingLocalRefund->fill($refundData);
            $existingLocalRefund->save();
            return $existingLocalRefund;
        }

        // Create new refund
        $createdRefund = OrderTransaction::query()->create($refundData);
        
        // Update parent transaction refunded total
        if ($createdRefund) {
            PaymentHelper::updateTransactionRefundedTotal($parentTransaction, $createdRefund->total);
        }

        return $createdRefund;
    }
}

