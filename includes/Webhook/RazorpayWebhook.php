<?php

namespace RazorpayFluentCart\Webhook;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Framework\Support\Arr;
use RazorpayFluentCart\API\RazorpayAPI;

class RazorpayWebhook
{
    public function init()
    {
        // Webhook initialization if needed
    }

    /**
     * Verify and process Razorpay webhook
     */
    public function verifyAndProcess()
    {
        // Check the request method is POST
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] != 'POST') {
            http_response_code(405);
            exit('Method not allowed');
        }

        // Get webhook payload
        $post_data = '';
        if (ini_get('allow_url_fopen')) {
            $post_data = file_get_contents('php://input');
        }

        if (!$post_data && empty($_POST)) {
            http_response_code(400);
            exit('No data received');
        }

        // Parse the data
        $data = $post_data ? json_decode($post_data, true) : $_POST;

        if (!$data || empty($data['payload'])) {
            http_response_code(400);
            exit('Invalid payload');
        }

        $payload = $data['payload'];
        $paymentId = Arr::get($payload, 'payment.entity.id');

        if (!$paymentId) {
            http_response_code(400);
            exit('Payment ID not found');
        }

        // Get payment details from Razorpay
        $api = new RazorpayAPI();
        $vendorPayment = $api->getPayment($paymentId);

        if (is_wp_error($vendorPayment)) {
            fluent_cart_add_log('Razorpay Webhook Error', $vendorPayment->get_error_message(), 'error');
            http_response_code(400);
            exit('Payment verification failed');
        }

        // Find transaction by payment ID or order ID
        $orderId = Arr::get($vendorPayment, 'order_id');
        
        $transaction = OrderTransaction::query()
            ->where('vendor_charge_id', $orderId)
            ->where('payment_method', 'razorpay')
            ->first();

        if (!$transaction) {
            // Try to find by payment ID in meta
            $transactions = OrderTransaction::query()
                ->where('payment_method', 'razorpay')
                ->get();

            foreach ($transactions as $t) {
                $meta = $t->meta ?? [];
                if (Arr::get($meta, 'razorpay_payment_id') == $paymentId) {
                    $transaction = $t;
                    break;
                }
            }
        }

        if (!$transaction) {
            fluent_cart_add_log('Razorpay Webhook', 'Transaction not found for payment: ' . $paymentId, 'error');
            http_response_code(404);
            exit('Transaction not found');
        }

        // Process the payment status
        $this->handlePaymentStatus($transaction, $vendorPayment);

        http_response_code(200);
        exit('Webhook processed');
    }

    /**
     * Handle payment status from webhook
     */
    private function handlePaymentStatus($transaction, $vendorPayment)
    {
        $status = Arr::get($vendorPayment, 'status');

        // Map Razorpay status to FluentCart status
        $fluentCartStatus = $this->mapStatus($status);

        // Update transaction
        $updateData = [
            'status'           => $fluentCartStatus,
            'vendor_charge_id' => $transaction->vendor_charge_id ?: Arr::get($vendorPayment, 'order_id'),
            'meta'             => array_merge($transaction->meta ?? [], [
                'razorpay_payment_id' => Arr::get($vendorPayment, 'id'),
                'razorpay_response'   => $vendorPayment
            ])
        ];

        // Add card details if available
        if ($card = Arr::get($vendorPayment, 'card')) {
            $updateData['card_last_4'] = Arr::get($card, 'last4');
            $updateData['card_brand'] = Arr::get($card, 'network');
        }

        $transaction->update($updateData);

        // Sync order status if payment successful
        if ($fluentCartStatus === Status::TRANSACTION_SUCCEEDED && $transaction->order) {
            (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);
        }

        fluent_cart_add_log('Razorpay Payment Webhook', 'Payment status updated: ' . $status, 'info', [
            'module_name' => 'order',
            'module_id'   => $transaction->order_id,
        ]);
    }

    /**
     * Map Razorpay status to FluentCart status
     */
    private function mapStatus($razorpayStatus)
    {
        $statusMap = [
            'captured'  => Status::TRANSACTION_SUCCEEDED,
            'paid'      => Status::TRANSACTION_SUCCEEDED,
            'authorized' => Status::TRANSACTION_AUTHORIZED,
            'failed'    => Status::TRANSACTION_FAILED,
            'refunded'  => Status::TRANSACTION_REFUNDED,
        ];

        return $statusMap[$razorpayStatus] ?? Status::TRANSACTION_PENDING;
    }
}

