# Razorpay Subscription Workflow (Corrected Implementation)

## Overview

This document explains the **correct** Razorpay subscription workflow as per [Razorpay's official documentation](https://razorpay.com/docs/payments/subscriptions/workflow/).

## The Correct Flow

```
1. Create Plan
   ↓
2. Create Subscription (get subscription_id)
   ↓
3. Authentication Transaction (using subscription_id)
   ↓
4. Subscription becomes active
```

## Detailed Workflow

### Step 1: Create Plan

**API**: `POST /v1/plans`

```json
{
  "period": "monthly",
  "interval": 1,
  "item": {
    "name": "Product Name",
    "amount": 99900,
    "currency": "INR",
    "description": "Product description"
  }
}
```

**Response**:
```json
{
  "id": "plan_xxxxx",
  "entity": "plan",
  "interval": 1,
  "period": "monthly",
  ...
}
```

**Implementation**: `RazorpaySubscriptions::getOrCreateRazorpayPlan()`
- Checks if plan already exists in product meta
- Creates new plan if not found
- Caches plan_id for future use

### Step 2: Create Subscription

**API**: `POST /v1/subscriptions`

```json
{
  "plan_id": "plan_xxxxx",
  "customer_notify": 1,
  "quantity": 1,
  "total_count": 12,
  "start_at": 1234567890,  // For trial periods
  "addons": [              // For upfront amounts
    {
      "item": {
        "name": "Setup Fee",
        "amount": 10000,
        "currency": "INR"
      }
    }
  ],
  "notes": {
    "order_hash": "xxx",
    "subscription_hash": "yyy"
  }
}
```

**Response**:
```json
{
  "id": "sub_xxxxx",
  "entity": "subscription",
  "status": "created",
  "plan_id": "plan_xxxxx",
  "customer_id": "cust_xxxxx",
  "short_url": "https://rzp.io/i/xxxxx",
  ...
}
```

**Implementation**: `RazorpaySubscriptions::createRazorpaySubscription()`
- Creates subscription on Razorpay **before** authentication
- Saves `subscription_id` to FluentCart subscription
- Returns subscription data for checkout

### Step 3: Authentication Transaction

**Frontend**: Razorpay Checkout with `subscription_id`

```javascript
const options = {
  key: 'rzp_xxx',
  subscription_id: 'sub_xxxxx',  // Not order_id!
  name: 'Merchant Name',
  description: 'Subscription',
  recurring: '1',
  prefill: {
    name: 'Customer Name',
    email: 'customer@example.com'
  },
  handler: function(response) {
    // Payment success handler
    // response contains: razorpay_payment_id, razorpay_subscription_id, razorpay_signature
  }
};

const rzp = new Razorpay(options);
rzp.open();
```

**Implementation**: `assets/razorpay-checkout.js`
- Detects subscription intent
- Uses `subscription_id` instead of `order_id`
- Adds `recurring: '1'` flag
- Handles authentication response

### Step 4: Subscription Activation

**After Authentication**:
1. Customer completes payment
2. Razorpay sends `subscription.authenticated` webhook
3. Subscription status changes to `authenticated` or `active`
4. FluentCart updates local subscription status

**Webhook**: `subscription.authenticated`

```json
{
  "event": "subscription.authenticated",
  "payload": {
    "subscription": {
      "entity": {
        "id": "sub_xxxxx",
        "status": "authenticated",
        ...
      }
    },
    "payment": {
      "entity": {
        "id": "pay_xxxxx",
        "method": "card",
        "card": {...},
        ...
      }
    }
  }
}
```

**Implementation**: `RazorpayWebhook::handleSubscriptionAuthenticated()`
- Fetches latest subscription data
- Updates local subscription status
- Stores billing information
- Fires activation event

## Key Differences from Previous Implementation

### ❌ Old (Incorrect) Flow:
```
1. Create Plan
2. Create Razorpay Order for authentication  ← Wrong!
3. Customer completes payment
4. After payment, create Subscription          ← Wrong!
```

### ✅ New (Correct) Flow:
```
1. Create Plan
2. Create Subscription (before authentication) ← Correct!
3. Customer completes authentication
4. Subscription becomes active                 ← Correct!
```

## Implementation Changes

### RazorpaySubscriptions.php

**Old `handleSubscription()` method**:
```php
// Created Razorpay Order
$razorpayOrder = RazorpayAPI::createRazorpayObject('orders', $orderData);
return [..., 'razorpay_order' => $razorpayOrder];
```

**New `handleSubscription()` method**:
```php
// Create subscription FIRST
$razorpaySubscription = $this->createRazorpaySubscription($paymentInstance, $planId);
return [..., 'subscription_id' => $subscriptionId];
```

### JavaScript (razorpay-checkout.js)

**Old**:
```javascript
const options = {
  order_id: modalData.order_id,  // ← Wrong for subscriptions!
  amount: modalData.amount,
  currency: modalData.currency,
  ...
};
```

**New**:
```javascript
if (isSubscription) {
  options.subscription_id = subscriptionId;  // ← Correct!
  options.recurring = '1';
} else {
  options.order_id = orderId;
  options.amount = amount;
  options.currency = currency;
}
```

### Confirmations (RazorpayConfirmations.php)

**Old**:
```php
// Created subscription after authentication
(new RazorpaySubscriptions())->createSubscriptionOnRazorpay($subscription, $args);
```

**New**:
```php
// Just update status (subscription already exists)
(new RazorpaySubscriptions())->updateSubscriptionAfterAuth($subscription, $payment);
```

## Trial Periods

Trial periods are handled using the `start_at` parameter:

```php
if ($subscription->trial_days > 0) {
    $startDate = time() + ($subscription->trial_days * DAY_IN_SECONDS);
    $subscriptionData['start_at'] = $startDate;
}
```

**Example**:
- Customer subscribes on Jan 1st
- Trial period: 7 days
- `start_at` is set to Jan 8th
- Customer is authenticated on Jan 1st
- First billing happens on Jan 8th

## Upfront Amounts

Setup fees or deposits are handled using `addons`:

```php
if ($upfrontAmount > 0) {
    $subscriptionData['addons'] = [
        [
            'item' => [
                'name' => 'Setup Fee',
                'amount' => $upfrontAmount,
                'currency' => 'INR'
            ]
        ]
    ];
}
```

This amount is charged immediately during authentication.

## Webhook Events Sequence

1. **subscription.authenticated** - After auth transaction succeeds
2. **subscription.activated** - When subscription becomes active
3. **subscription.charged** - For each recurring payment
4. **subscription.cancelled** - When subscription is cancelled
5. **subscription.completed** - When all billing cycles complete

## Testing

### Test the Correct Flow:

1. **Create a subscription product** in FluentCart
2. **Add to cart** and proceed to checkout
3. **Select Razorpay** as payment method
4. **Click Place Order**
   - Plan is created/retrieved
   - Subscription is created on Razorpay
   - Modal opens with `subscription_id`
5. **Complete payment** with test card
6. **Check logs**:
   - "Razorpay Subscription Created" (before authentication)
   - "Razorpay Subscription Authentication Complete" (after payment)
7. **Check Razorpay Dashboard**:
   - Subscription should exist
   - Status should be "authenticated" or "active"

### Test Cards

```
Card: 4111 1111 1111 1111
Expiry: Any future date
CVV: Any 3 digits
```

## API References

- **Create Plan**: https://razorpay.com/docs/api/payments/subscriptions/#create-a-plan
- **Create Subscription**: https://razorpay.com/docs/api/payments/subscriptions/create-subscription/
- **Subscription Workflow**: https://razorpay.com/docs/payments/subscriptions/workflow/
- **Authentication Transaction**: https://razorpay.com/docs/payments/subscriptions/create/#authentication-transaction

## Filters Available

```php
// Customize plan creation
razorpay_fc/plan_data

// Customize subscription creation
razorpay_fc/subscription_create_data

// Customize customer notifications
razorpay_fc/subscription_customer_notify

// Customize plan ID format
razorpay_fc/recurring_plan_id
```

## Summary

The key insight is that **Razorpay subscriptions must be created BEFORE the authentication transaction**, not after. The `subscription_id` returned from subscription creation is what you pass to the Razorpay checkout, not an `order_id`.

This is fundamentally different from one-time payments where you create an order first and then charge it.

