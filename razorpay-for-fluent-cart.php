<?php
/**
 * Plugin Name: Razorpay for FluentCart
 * Plugin URI: https://fluentcart.com
 * Description: Accept payments via Razorpay in FluentCart - supports one-time payments, refunds, and multiple payment methods
 * Version: 1.2.0
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
define('RAZORPAY_FC_VERSION', '1.2.0');
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
                    <strong><?php esc_html_e('Razorpay for FluentCart', 'razorpay-for-fluent-cart'); ?></strong> 
                    <?php esc_html_e('requires FluentCart to be installed and activated.', 'razorpay-for-fluent-cart'); ?>
                </p>
            </div>
            <?php
        });
        return false;
    }
    
    if (version_compare(FLUENTCART_VERSION, '1.2.5', '<')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('Razorpay for FluentCart', 'razorpay-for-fluent-cart'); ?></strong> 
                    <?php esc_html_e('requires FluentCart version 1.2.5 or higher', 'razorpay-for-fluent-cart'); ?>
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

    /**
     * Plugin Updater
     */
    $apiUrl = 'https://fluentcart.com/wp-admin/admin-ajax.php?action=fluent_cart_razorpay_update&time=' . time();
    new \RazorpayFluentCart\PluginManager\Updater($apiUrl, RAZORPAY_FC_PLUGIN_FILE, array(
        'version'   => RAZORPAY_FC_VERSION,
        'license'   => '12345',
        'item_name' => 'Razorpay for FluentCart',
        'item_id'   => '104',
        'author'    => 'wpmanageninja'
    ),
        array(
            'license_status' => 'valid',
            'admin_page_url' => admin_url('admin.php?page=fluent-cart#/'),
            'purchase_url'   => 'https://fluentcart.com',
            'plugin_title'   => 'Razorpay for FluentCart'
        )
    );

    add_filter('plugin_row_meta', function ($links, $pluginFile) {
        if (plugin_basename(RAZORPAY_FC_PLUGIN_FILE) !== $pluginFile) {
            return $links;
        }

        $checkUpdateUrl = esc_url(admin_url('plugins.php?razorpay-for-fluent-cart-check-update=' . time()));

        $row_meta = array(
            'check_update' => '<a style="color: #583fad;font-weight: 600;" href="' . $checkUpdateUrl . '" aria-label="' . esc_attr__('Check Update', 'razorpay-for-fluent-cart') . '">' . esc_html__('Check Update', 'razorpay-for-fluent-cart') . '</a>',
        );

        return array_merge($links, $row_meta);
    }, 10, 2);

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

