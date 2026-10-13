<?php
/**
 * Plugin Name:       OSM Ride Booking
 * Plugin URI:        https://github.com/mondobarry/osm-ride-booking
 * Description:       Ride booking for private drivers and shuttles: OpenStreetMap address search, route and distance-based price, availability calendar, paid through WooCommerce.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   11.2
 * Author:            Mamadou Alimou Barry
 * Author URI:        https://mondobarry.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       osm-ride-booking
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ORB_VERSION', '1.0.0');
define('ORB_FILE', __FILE__);
define('ORB_PATH', plugin_dir_path(__FILE__));
define('ORB_URL', plugin_dir_url(__FILE__));

require_once ORB_PATH . 'includes/class-orb-pricing.php';
require_once ORB_PATH . 'includes/class-orb-peak.php';

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage and cart/checkout blocks.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', ORB_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', ORB_FILE, true);
    }
});

// Bundled translations (languages/) for installs outside WordPress.org.
add_action('init', function () {
    load_plugin_textdomain('osm-ride-booking', false, dirname(plugin_basename(ORB_FILE)) . '/languages');
});

function orb_init() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('OSM Ride Booking requires WooCommerce. Please install and activate WooCommerce.', 'osm-ride-booking')
                . '</p></div>';
        });
        return;
    }

    require_once ORB_PATH . 'includes/class-orb-settings.php';
    require_once ORB_PATH . 'includes/class-orb-routing.php';
    require_once ORB_PATH . 'includes/class-orb-availability.php';
    require_once ORB_PATH . 'includes/class-orb-woocommerce.php';
    require_once ORB_PATH . 'includes/class-orb-shortcode.php';

    new ORB_Settings();
    new ORB_Routing();
    new ORB_Availability();
    new ORB_WooCommerce();
    new ORB_Shortcode();
}
add_action('plugins_loaded', 'orb_init');

/**
 * Activation: create the hidden, virtual WooCommerce product that carries every booking.
 */
function orb_activate() {
    if (!class_exists('WooCommerce')) {
        return;
    }
    require_once ORB_PATH . 'includes/class-orb-woocommerce.php';
    ORB_WooCommerce::ensure_product();
}
register_activation_hook(__FILE__, 'orb_activate');
