# Razorpay for FluentCart

A WordPress plugin that integrates Razorpay payment gateway with FluentCart.

## Features

- ✅ One-time payments
- ✅ Modal checkout (popup)
- ✅ Hosted checkout (redirect)
- ✅ Webhook integration
- ✅ Refund processing via API
- ✅ Test and Live modes
- ✅ INR currency support
- ✅ Multiple payment methods (Cards, UPI, Netbanking, Wallets)
- ⚠️ No subscription support (Razorpay limitation in this implementation)

## Installation

1. Clone or download this repository to your WordPress plugins directory:
   ```bash
   cd wp-content/plugins/
   git clone [your-repo-url] razorpay-for-fluent-cart
   ```

2. Activate the plugin in WordPress admin

3. Go to FluentCart > Settings > Payment Methods

4. Enable and configure Razorpay with your API keys from [Razorpay Dashboard](https://dashboard.razorpay.com/)

## Requirements

- WordPress 5.6 or higher
- PHP 7.4 or higher
- FluentCart plugin (free or pro version)
- Razorpay merchant account

## Configuration

### Getting Credentials

1. Log into your [Razorpay Dashboard](https://dashboard.razorpay.com/)
2. Navigate to **Settings > API Keys**
3. Copy your **Key ID** (Public Key) and **Key Secret** (Secret Key)

### Setting Up Webhooks

1. Go to **Settings > Webhooks** in Razorpay Dashboard
2. Add webhook URL:
   ```
   https://yourdomain.com/?fluent-cart=fct_payment_listener_ipn&method=razorpay
   ```
3. Select events to listen for (recommended: payment events)

### Checkout Types

- **Modal Checkout**: Opens Razorpay payment popup on your site
- **Hosted Checkout**: Redirects customer to Razorpay payment page

## Development

### Directory Structure

```
razorpay-for-fluent-cart/
├── razorpay-for-fluent-cart.php    # Main plugin file
├── assets/
│   ├── razorpay-checkout.js        # Frontend payment handler
│   └── images/
│       └── razorpay-logo.svg       # Payment method logo
├── includes/
│   ├── RazorpayGateway.php         # Main gateway class
│   ├── API/
│   │   └── RazorpayAPI.php         # API client
│   ├── Webhook/
│   │   └── RazorpayWebhook.php     # Webhook handler
│   ├── Onetime/
│   │   └── RazorpayProcessor.php   # Payment processor
│   ├── Settings/
│   │   └── RazorpaySettingsBase.php  # Settings management
│   └── Confirmations/
│       └── RazorpayConfirmations.php # Payment confirmations
└── README.md
```

### API Endpoints

- **Base URL**: `https://api.razorpay.com/v1`
- **Create Order**: `POST /orders`
- **Get Payment**: `GET /payments/{payment_id}`
- **Create Payment Link**: `POST /payment_links`
- **Create Refund**: `POST /payments/{payment_id}/refund`

### Hooks and Filters

#### Filters

- `razorpay_fc/payment_link_args` - Modify payment link arguments before sending to Razorpay
- `razorpay_fc/razorpay_settings` - Modify Razorpay settings

### Payment Flow

1. **Customer initiates checkout** → FluentCart collects order info
2. **FluentCart calls** `makePaymentFromPaymentInstance()` 
3. **RazorpayProcessor** creates Razorpay order (for modal) or payment link (for hosted)
4. **Modal**: Opens Razorpay checkout popup
5. **Hosted**: Redirects to Razorpay payment page
6. **Customer pays** using preferred method
7. **Razorpay webhook** notifies your site
8. **RazorpayWebhook** verifies and processes payment
9. **FluentCart** completes the order

## Testing

### Test Mode

1. Enable **Test Mode** in settings
2. Use test Key ID and Key Secret from Razorpay
3. Use [test cards](https://razorpay.com/docs/payments/test-cards/) provided by Razorpay

### Test Cards

See Razorpay documentation for test card numbers:
https://razorpay.com/docs/payments/test-cards/

### Webhook Testing

1. Complete a test payment
2. Check FluentCart logs for webhook reception
3. Verify transaction status is updated
4. Confirm order status changes to "paid"

## Important Notes

### Currency Requirements

- Primary currency should be INR (Indian Rupee)
- Amounts should be in paise (smallest currency unit)
- Example: ₹100.00 = 10000 paise

### Refunds

Razorpay supports automated refunds via API. Use the refund feature in FluentCart admin to process refunds.

### Subscriptions

This plugin currently only supports one-time payments. Subscription support may be added in future versions.

## Troubleshooting

### Common Issues

1. **Payment initialization fails**
   - Check Key ID and Key Secret are correct
   - Verify you're using correct mode (test/live)
   - Check Razorpay account status

2. **Webhook not received**
   - Verify your site is publicly accessible
   - Check webhook URL is correct in Razorpay dashboard
   - Test webhook using Razorpay dashboard tools

3. **Modal not opening**
   - Check Razorpay checkout script is loaded
   - Verify browser console for JavaScript errors
   - Ensure Key ID is correctly set

### Debug Logging

Add to `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check logs at `wp-content/debug.log`

## Support

For issues, questions, or contributions:
- Razorpay API: https://razorpay.com/docs/
- FluentCart Documentation: https://fluentcart.com/docs/

## License

GPLv2 or later. See LICENSE file for details.

## Credits

Built for FluentCart following Razorpay API documentation.

