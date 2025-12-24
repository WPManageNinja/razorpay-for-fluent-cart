# Razorpay Subscriptions for FluentCart

This document explains how Razorpay subscriptions are implemented in FluentCart.

## Overview

Razorpay subscriptions allow you to accept recurring payments from your customers automatically. This integration follows Razorpay's subscription workflow and FluentCart's subscription architecture.

## Features

- ✅ Create and manage subscription plans
- ✅ Automatic recurring payments
- ✅ Trial periods support
- ✅ Flexible billing intervals (daily, weekly, monthly, quarterly, half-yearly, yearly)
- ✅ Upfront amount collection (setup fees, deposits)
- ✅ Subscription cancellation
- ✅ Webhook support for real-time updates
- ✅ Automatic invoice generation
- ✅ Multiple payment methods (Cards, UPI, Net Banking, Wallets)

## How It Works

### 1. Plan Creation

When a subscription product is purchased, Razorpay for FluentCart automatically:
- Creates a plan on Razorpay with the product details
- Maps FluentCart billing intervals to Razorpay periods
- Caches the plan ID to avoid duplicate plans

**Billing Interval Mapping:**
- `daily` → Razorpay: `daily` with interval 1
- `weekly` → Razorpay: `weekly` with interval 1
- `monthly` → Razorpay: `monthly` with interval 1
- `quarterly` → Razorpay: `monthly` with interval 3
- `half_yearly` → Razorpay: `monthly` with interval 6
- `yearly` → Razorpay: `yearly` with interval 1

### 2. Authentication Transaction

Before creating a subscription, customers must complete an authentication transaction:
- If there's an upfront amount, that amount is charged
- If no upfront amount, a token amount (₹1.00) is charged for card authentication
- This validates the customer's payment method for future recurring charges

### 3. Subscription Creation

After successful authentication:
- A subscription is created on Razorpay with the plan ID
- Trial period is set (if configured)
- Customer details are linked to the subscription
- Billing information (card details, UPI, etc.) is stored

### 4. Recurring Payments

Razorpay automatically:
- Charges the customer on the billing cycle
- Sends webhooks for payment status
- Creates invoices for each charge
- Handles payment retries if needed

### 5. Subscription Management

You can manage subscriptions from:
- **FluentCart Dashboard**: View and manage subscriptions
- **Razorpay Dashboard**: Access detailed subscription analytics
- Both dashboards are synchronized via webhooks

## Configuration

### Required Settings

1. **Razorpay API Keys**
   - Live API Key (Key ID)
   - Live Key Secret
   - Test API Key (for testing)
   - Test Key Secret

2. **Webhook Configuration**
   - URL: `https://yoursite.com/?fluent-cart=fct_payment_listener_ipn&method=razorpay`
   - Secret: Configure in Razorpay Dashboard
   - Required Events:
     - `subscription.authenticated`
     - `subscription.activated`
     - `subscription.charged`
     - `subscription.pending`
     - `subscription.halted`
     - `subscription.cancelled`
     - `subscription.completed`
     - `subscription.paused`
     - `subscription.resumed`

### Enable Subscriptions

1. Go to **FluentCart > Settings > Payment Methods**
2. Click on **Razorpay**
3. Enter your API credentials
4. Save settings
5. Subscriptions are automatically enabled!

## Technical Implementation

### Files Structure

```
includes/
├── Subscriptions/
│   └── RazorpaySubscriptions.php      # Main subscription logic
├── RazorpayGateway.php                # Gateway integration
├── RazorpayHelper.php                  # Helper functions
├── API/
│   └── RazorpayAPI.php                # API wrapper
├── Webhook/
│   └── RazorpayWebhook.php            # Webhook handlers
└── Confirmations/
    └── RazorpayConfirmations.php      # Payment confirmations
```

### Key Classes

#### RazorpaySubscriptions

Extends `AbstractSubscriptionModule` and handles:
- `handleSubscription()` - Initialize subscription payment
- `getOrCreateRazorpayPlan()` - Create/retrieve Razorpay plans
- `createSubscriptionOnRazorpay()` - Create subscription after auth
- `reSyncSubscriptionFromRemote()` - Sync subscription state
- `cancel()` - Cancel subscriptions

#### RazorpayHelper

Utility methods:
- `mapIntervalToRazorpay()` - Convert FluentCart intervals to Razorpay format
- `getFctSubscriptionStatus()` - Map Razorpay status to FluentCart
- `getMinimumAmountForAuthorization()` - Calculate auth amount
- `getSubscriptionUpdateData()` - Extract update data from webhook

#### RazorpayWebhook

Subscription webhook handlers:
- `handleSubscriptionAuthenticated()` - Process authentication success
- `handleSubscriptionActivated()` - Activate subscription
- `handleSubscriptionCharged()` - Record renewal payments
- `handleSubscriptionCancelled()` - Handle cancellations
- And more...

## Webhook Events

### subscription.authenticated
**Fired when**: Authentication transaction succeeds
**Action**: Creates the subscription on Razorpay

### subscription.activated
**Fired when**: Subscription becomes active
**Action**: Updates subscription status to active

### subscription.charged
**Fired when**: Recurring payment succeeds
**Action**: Records the renewal payment transaction

### subscription.pending
**Fired when**: Payment is pending
**Action**: Updates subscription status

### subscription.halted
**Fired when**: Subscription is halted due to payment failures
**Action**: Updates subscription status to paused/halted

### subscription.cancelled
**Fired when**: Subscription is cancelled
**Action**: Marks subscription as cancelled

### subscription.completed
**Fired when**: All billing cycles are completed
**Action**: Marks subscription as expired

## Testing Subscriptions

### Test Mode Configuration

1. Use Razorpay test credentials
2. Use test card numbers from [Razorpay Test Cards](https://razorpay.com/docs/payments/payments/test-card-details/)

### Test Card Example
```
Card Number: 4111 1111 1111 1111
Expiry: Any future date
CVV: Any 3 digits
```

### Testing Workflow

1. Create a subscription product in FluentCart
2. Add to cart and proceed to checkout
3. Select Razorpay as payment method
4. Complete authentication payment
5. Verify subscription is created in both dashboards
6. Test webhook events using Razorpay's webhook test tool

## Filters and Hooks

### Customize Plan Data
```php
add_filter('fluent_cart/razorpay/plan_data', function($planData, $context) {
    // Modify plan data before creating on Razorpay
    return $planData;
}, 10, 2);
```

### Customize Subscription Data
```php
add_filter('fluent_cart/razorpay/subscription_create_data', function($subscriptionData, $context) {
    // Modify subscription data
    return $subscriptionData;
}, 10, 2);
```

### Customize Customer Notifications
```php
add_filter('fluent_cart/razorpay/subscription_customer_notify', function($notify, $context) {
    // Control if Razorpay should send emails to customers
    return 1; // or 0
}, 10, 2);
```

### Custom Plan ID Format
```php
add_filter('fluent_cart/razorpay_recurring_plan_id', function($planId, $context) {
    // Customize plan ID format
    return $planId;
}, 10, 2);
```

## Troubleshooting

### Subscription Not Creating

**Check:**
1. Razorpay API credentials are correct
2. Test mode vs Live mode settings
3. Plan was created successfully
4. Authentication payment succeeded
5. Check FluentCart logs

### Webhooks Not Working

**Check:**
1. Webhook URL is correct and accessible
2. Webhook secret is configured correctly
3. Required webhook events are enabled
4. Check Razorpay webhook logs
5. SSL certificate is valid

### Payment Failures

**Check:**
1. Customer has sufficient balance
2. Card/UPI is not expired
3. Bank/payment method allows recurring charges
4. Customer hasn't blocked recurring payments

## API Reference

### Create Plan
```php
$plan = RazorpayAPI::createRazorpayObject('plans', [
    'period' => 'monthly',
    'interval' => 1,
    'item' => [
        'name' => 'Plan Name',
        'amount' => 99900, // in paise
        'currency' => 'INR'
    ]
]);
```

### Create Subscription
```php
$subscription = RazorpayAPI::createRazorpayObject('subscriptions', [
    'plan_id' => 'plan_xxxxx',
    'customer_notify' => 1,
    'total_count' => 12, // 0 for infinite
    'start_at' => time() + (7 * 86400) // 7 days trial
]);
```

### Cancel Subscription
```php
$result = RazorpayAPI::createRazorpayObject(
    'subscriptions/' . $subscriptionId . '/cancel',
    ['cancel_at_cycle_end' => 0]
);
```

## Best Practices

1. **Always Test in Test Mode First**: Use Razorpay's test environment before going live
2. **Configure Webhooks**: Essential for automatic subscription updates
3. **Monitor Webhook Logs**: Regularly check for failed webhook deliveries
4. **Handle Failed Payments**: Have a strategy for payment retry and grace periods
5. **Customer Communication**: Keep customers informed about subscription status
6. **Security**: Never expose API keys in frontend code
7. **Logging**: Enable FluentCart logging for debugging

## Support

For issues related to:
- **FluentCart Integration**: Contact FluentCart support
- **Razorpay API**: Check [Razorpay Documentation](https://razorpay.com/docs/)
- **Plugin Bugs**: Report on GitHub repository

## Resources

- [Razorpay Subscriptions Documentation](https://razorpay.com/docs/payments/subscriptions/)
- [Razorpay API Reference](https://razorpay.com/docs/api/subscriptions/)
- [FluentCart Documentation](https://fluentcart.com/docs/)
- [Test Card Details](https://razorpay.com/docs/payments/payments/test-card-details/)

## Changelog

### Version 1.0.0
- Initial subscription support
- Plan creation and management
- Automatic recurring payments
- Trial period support
- Webhook integration
- Comprehensive logging

