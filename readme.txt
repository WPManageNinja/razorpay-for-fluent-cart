=== Razorpay for FluentCart ===
Contributors: hasanuzzamanshamim
Tags: razorpay, payment gateway, fluentcart, india, inr
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept payments via Razorpay in FluentCart - supports one-time payments, multiple payment methods, and automatic refunds.

== Description ==

Razorpay for FluentCart seamlessly integrates Razorpay payment gateway with FluentCart, allowing you to accept payments from customers in India and internationally.

= Features =

* **One-time Payments**: Accept card payments, UPI, Netbanking, Wallets, and more
* **Multiple Payment Methods**: Support for all Razorpay payment methods
* **Modal & Hosted Checkout**: Choose between popup or redirect checkout experience
* **Webhook Support**: Automatic payment verification via webhooks
* **Refund Support**: Process refunds directly from FluentCart
* **Test Mode**: Test your integration before going live
* **Secure**: All transactions are encrypted and secure

= Supported Payment Methods =

* Credit/Debit Cards (Visa, Mastercard, RuPay, Amex)
* UPI (Google Pay, PhonePe, Paytm, BHIM, etc.)
* Netbanking (All major banks)
* Wallets (Paytm, Mobikwik, Freecharge, etc.)
* EMI (Credit Card EMI)
* Cash/COD (where applicable)

= Supported Currency =

* INR (Indian Rupee)

= Requirements =

* FluentCart plugin (free or pro)
* Razorpay account ([Sign up here](https://razorpay.com/))

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/razorpay-for-fluent-cart/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to FluentCart > Settings > Payment Methods
4. Enable and configure Razorpay with your API keys
5. Choose your checkout type (Modal or Hosted)
6. Save your settings

== Frequently Asked Questions ==

= Do I need a Razorpay account? =

Yes, you need a Razorpay merchant account. You can sign up at https://razorpay.com/

= Where do I get my API keys? =

Log into your Razorpay Dashboard and navigate to Settings > API Keys

= What is the difference between Modal and Hosted checkout? =

Modal checkout opens a popup overlay on your site, while Hosted checkout redirects customers to Razorpay's payment page.

= Does this support subscriptions? =

No, Razorpay integration currently supports one-time payments only. Subscription support may be added in future versions.

= Is it secure? =

Yes, all transactions are processed securely through Razorpay's infrastructure. No card details are stored on your server.

= How do I configure webhooks? =

The plugin automatically handles webhook configuration. The webhook URL is displayed in the payment settings and should be configured in your Razorpay Dashboard under Settings > Webhooks.

== Screenshots ==

1. Payment method settings page
2. Modal checkout experience
3. Hosted checkout experience
4. Transaction management

== Changelog ==

= 1.2.0 =
23 February 2026
* Adds support for Subscription


= 1.0.2 =

04 February 2026
* Adds missing nonce checks on payment confirmation
* Fixes some critical corner cases

= 1.0.0 =
* Initial release
* Support for one-time payments
* Modal and hosted checkout options
* Webhook integration
* Refund support via API
* Test and live mode
* INR currency support

== Upgrade Notice ==

= 1.0.0 =
Initial release of Razorpay for FluentCart.

