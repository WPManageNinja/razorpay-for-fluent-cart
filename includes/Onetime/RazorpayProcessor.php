<?php

namespace RazorpayFluentCart\Onetime;

use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\Framework\Support\Arr;
use RazorpayFluentCart\API\RazorpayAPI;
use RazorpayFluentCart\Settings\RazorpaySettingsBase;
use FluentCart\App\Helpers\CurrenciesHelper;

class RazorpayProcessor
{
    /**
     * Handle single payment
     */
    public function handleSinglePayment(PaymentInstance $paymentInstance, $paymentArgs = [])
    {

        $settings = new RazorpaySettingsBase();
        $checkoutType = $settings->get('checkout_type') ?? 'modal';

        if ($checkoutType === 'modal') {
            return $this->handleModalPayment($paymentInstance, $paymentArgs);
        } else {
            // not handled yet, will come later
            // return $this->handleHostedPayment($paymentInstance, $paymentArgs);
        }
    }

    /**
     * Handle modal checkout
     */
    private function handleModalPayment(PaymentInstance $paymentInstance, $paymentArgs = [])
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $paymentInstance->order->customer;

        // Create Razorpay order first
        $orderData = [
            'amount'   => $transaction->total,
            'currency' => strtoupper($transaction->currency),
            'receipt'  => $transaction->uuid,
            'notes'    => [
                'fluent_cart_order_id'       => $order->id,
                'transaction_hash' => $transaction->uuid,
                'order_hash'     => $order->uuid,
                'customer_name'  => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
            ]
        ];

        if (CurrenciesHelper::isZeroDecimal($transaction->currency)) {
            $orderData['amount'] = (int) ($transaction->total / 100);
        }

        $razorpayOrder = RazorpayAPI::createRazorpayObject('orders', $orderData);

        if (is_wp_error($razorpayOrder)) {
            return $razorpayOrder;
        }

        // Store Razorpay order ID
        $transaction->update([
            'vendor_charge_id' => $razorpayOrder['id'],
            'meta' => array_merge($transaction->meta ?? [], [
                'razorpay_order_id' => $razorpayOrder['id']
            ])
        ]);

        $settings = new RazorpaySettingsBase();

        // Prepare modal data
        $modalData = [
            'amount'       => $transaction->total,
            'currency'     => strtoupper($transaction->currency),
            'description'  => $this->getProductName($order),
            'order_id'     => $razorpayOrder['id'],
            'api_key'      => $settings->getApiKey(),
            'name'         => get_bloginfo('name'),
            'prefill'      => [
                'name'  => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
                'email' => $fcCustomer->email,
                'contact' => $fcCustomer->phone ?: ''
            ],
            'theme'        => [
                'color' => apply_filters('razorpay_fc/modal_theme_color', '#3399cc')
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
                'checkout_type'    => 'modal'
            ])
        ];
    }

    /**
     * Handle hosted checkout
     */
    // private function handleHostedPayment(PaymentInstance $paymentInstance, $paymentArgs = [])
    // {
    //     $order = $paymentInstance->order;
    //     $transaction = $paymentInstance->transaction;
    //     $fcCustomer = $paymentInstance->order->customer;
    //     $settings = new RazorpaySettingsBase();

    //     $listenerUrl = add_query_arg([
    //         'fluent_cart_payment' => $transaction->uuid,
    //         'payment_method'      => 'razorpay',
    //     ], Arr::get($paymentArgs, 'success_url'));

    //     $paymentLinkData = [
    //         'amount'         => $transaction->total,
    //         'currency'       => strtoupper($transaction->currency),
    //         'description'    => $this->getProductName($order),
    //         'reference_id'   => $transaction->uuid,
    //         'customer'       => [
    //             'name'    => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
    //             'email'   => $fcCustomer->email,
    //             'contact' => $fcCustomer->phone ?: ''
    //         ],
    //         'callback_url'   => $listenerUrl,
    //         'callback_method' => 'get',
    //         'notes'          => [
    //             'order_id'       => $order->id,
    //             'transaction_id' => $transaction->uuid,
    //             'order_hash'     => $order->uuid,
    //             'customer_name'  => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
    //         ],
    //         'notify'         => [
    //             'email' => in_array('email', $settings->get('notification') ?: []),
    //             'sms'   => in_array('sms', $settings->get('notification') ?: [])
    //         ]
    //     ];

    //     // Apply filters
    //     $paymentLinkData = apply_filters('razorpay_fc/payment_link_args', $paymentLinkData, [
    //         'order'       => $order,
    //         'transaction' => $transaction
    //     ]);

    //     $paymentLink = RazorpayAPI::createRazorpayObject('payment_links', $paymentLinkData);

    //     if (is_wp_error($paymentLink)) {
    //         return $paymentLink;
    //     }

    //     $redirectUrl = Arr::get($paymentLink, 'short_url');

    //     if (!$redirectUrl) {
    //         return new \WP_Error(
    //             'razorpay_url_error',
    //             __('Unable to get payment URL from Razorpay', 'razorpay-for-fluent-cart')
    //         );
    //     }

    //     return [
    //         'status'       => 'success',
    //         'nextAction'   => 'razorpay',
    //         'actionName'   => 'redirect',
    //         'message'      => __('Redirecting to Razorpay payment page...', 'razorpay-for-fluent-cart'),
    //         'payment_args' => array_merge($paymentArgs, [
    //             'checkout_url'  => $redirectUrl,
    //             'checkout_type' => 'hosted'
    //         ])
    //     ];
    // }

    /**
     * Get product name from order items
     */
    private function getProductName($order)
    {
        if ($order->order_items->isEmpty()) {
            return 'FluentCart Order #' . $order->id;
        }

        $itemNames = [];
        foreach ($order->order_items as $item) {
            $itemNames[] = $item->title;
        }

        $productName = implode(', ', array_slice($itemNames, 0, 3));
        
        if (count($itemNames) > 3) {
            $productName .= ' + ' . (count($itemNames) - 3) . ' more';
        }

        return $productName;
    }
}

