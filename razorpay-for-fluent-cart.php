<?php
/**
 * Plugin Name: Razorpay for FluentCart
 * Plugin URI: https://fluentcart.com
 * Description: Accept payments via Razorpay in FluentCart - supports one-time payments, refunds, and multiple payment methods
 * Version: 1.0.0
 * Author: FluentCart
 * Author URI: https://fluentcart.com
 * Text Domain: razorpay-for-fluent-cart
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined('ABSPATH') or exit;

// Define plugin constants
define('RAZORPAY_FC_VERSION', '1.0.0');
define('RAZORPAY_FC_PLUGIN_FILE', __FILE__);
define('RAZORPAY_FC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RAZORPAY_FC_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Load plugin textdomain for translations
 */
add_action('plugins_loaded', function() {
    load_plugin_textdomain('razorpay-for-fluent-cart', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

/**
 * Check if FluentCart is active
 */
function razorpay_fc_check_dependencies() {
    if (!defined('FLUENTCART_VERSION')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php _e('Razorpay for FluentCart', 'razorpay-for-fluent-cart'); ?></strong> 
                    <?php _e('requires FluentCart to be installed and activated.', 'razorpay-for-fluent-cart'); ?>
                </p>
            </div>
            <?php
        });
        return false;
    }
    return true;
}

/**
 * Initialize the plugin
 */
add_action('plugins_loaded', function() {
    if (!razorpay_fc_check_dependencies()) {
        return;
    }

    // Register autoloader
    spl_autoload_register(function ($class) {
        $prefix = 'RazorpayFluentCart\\';
        $base_dir = RAZORPAY_FC_PLUGIN_DIR . 'includes/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    });

    // Register the payment gateway
    add_action('fluent_cart/register_payment_methods', function($data) {
        \RazorpayFluentCart\RazorpayGateway::register();
    }, 10);

}, 20);

/**
 * Activation hook
 */
register_activation_hook(__FILE__, function() {
    if (!razorpay_fc_check_dependencies()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            __('Razorpay for FluentCart requires FluentCart to be installed and activated.', 'razorpay-for-fluent-cart'),
            __('Plugin Activation Error', 'razorpay-for-fluent-cart'),
            ['back_link' => true]
        );
    }
});

