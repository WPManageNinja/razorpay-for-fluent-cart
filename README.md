# Razorpay for FluentCart

[![Download Latest](https://img.shields.io/badge/Download-Latest-blue?style=for-the-badge&logo=github)](https://github.com/WPManageNinja/razorpay-for-fluent-cart/releases/latest/download/razorpay-for-fluent-cart.zip)

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

### Prerequisites

- WordPress 5.6 or higher
- PHP 7.4 or higher
- [FluentCart](https://wordpress.org/plugins/fluent-cart/) plugin installed and activated
- A [Razorpay](https://razorpay.com) merchant account

### Install & Activate

1. **Download the Plugin**
   - Visit the [latest release](../../releases/latest)
   - Download the `Source code (zip)` file

2. **Upload to WordPress**
   - Go to your WordPress admin dashboard
   - Navigate to **Plugins > Add New**
   - Click **Upload Plugin**
   - Select the downloaded zip file and click **Install Now**

3. **Activate the Plugin**
   - After installation, click **Activate Plugin**
   - Alternatively, go to **Plugins** and click "Activate" below the plugin name

4. **Configure Razorpay**
   - Go to **FluentCart > Settings > Payment Methods**
   - Find and enable **Razorpay**
   - Enter your Test and Live API keys from the [Razorpay Dashboard](https://dashboard.razorpay.com/)
   - Configure your webhook URL (see [Configuration](#configuration) below)

## Updates

To update the Razorpay for FluentCart addon:

1. **Check for Updates**
   - Go to **FluentCart > Settings > Payment Methods**
   - Click on the **Razorpay** payment method
   - Click the **Check for Updates** button

2. **Download the New Version**
   - If a new version is available, an **Update Now** button will appear
   - Clicking this button will take you to the latest release page
   - Download the `Source code (zip)` file

3. **Install the Update**
   - Go to **Plugins > Add New > Upload Plugin**
   - Upload the new zip file
   - WordPress will automatically replace the old version with the new one
   - Reactivate the plugin if prompted

> **Note:** Since this addon is distributed via GitHub releases (not the WordPress Plugin Directory), updates must be installed manually using the steps above.

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
