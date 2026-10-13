<?php
/**
 * Booking → WooCommerce cart → order. The price is always recomputed on the server.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ORB_WooCommerce {

    public function __construct() {
        add_action('wp_ajax_orb_add_to_cart', array($this, 'add_to_cart'));
        add_action('wp_ajax_nopriv_orb_add_to_cart', array($this, 'add_to_cart'));
        add_action('woocommerce_before_calculate_totals', array($this, 'set_cart_prices'), 20);
        add_filter('woocommerce_get_item_data', array($this, 'cart_item_data'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_order_item_meta'), 10, 3);

        // Legal notice (optional, set in the admin) on cart, checkout, order pages and emails.
        add_action('woocommerce_after_cart_table', array($this, 'legal_notice_if_cart_has_booking'));
        add_action('woocommerce_checkout_before_order_review', array($this, 'legal_notice_if_cart_has_booking'));
        // Cart and checkout blocks (WooCommerce default) do not run the classic hooks above.
        add_filter('render_block_woocommerce/cart', array($this, 'append_legal_notice_to_block'));
        add_filter('render_block_woocommerce/checkout', array($this, 'append_legal_notice_to_block'));
        add_action('woocommerce_order_details_after_order_table', array($this, 'legal_notice_for_order'));
        add_action('woocommerce_email_after_order_table', array($this, 'legal_notice_email'), 10, 3);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
    }

    /**
     * The hidden virtual product every booking is attached to. Created on activation, recreated if deleted.
     */
    public static function ensure_product() {
        $product = wc_get_product((int) get_option('orb_product_id', 0));
        if ($product && $product->get_status() === 'publish' && $product->is_purchasable() && $product->get_sold_individually()) {
            return $product;
        }
        if (!$product || $product->get_status() === 'trash') {
            $product = new WC_Product_Simple();
            $product->set_name(__('Ride booking', 'osm-ride-booking'));
            $product->set_catalog_visibility('hidden');
            $product->set_virtual(true);
            $product->set_tax_status('taxable');
        }
        // One ride per cart line: no quantity selector, so a ride cannot be paid twice by mistake.
        $product->set_sold_individually(true);
        // A placeholder price keeps the product purchasable; the real price is set per cart item.
        $product->set_status('publish');
        $product->set_regular_price('1');
        $product->save();
        update_option('orb_product_id', $product->get_id());
        return $product;
    }

    public function add_to_cart() {
        check_ajax_referer('orb_nonce', 'nonce');
        if (is_null(WC()->cart)) {
            wc_load_cart();
        }

        $in = array(
            'pickup'      => sanitize_text_field(wp_unslash($_POST['pickup'] ?? '')),
            'destination' => sanitize_text_field(wp_unslash($_POST['destination'] ?? '')),
            'date'        => sanitize_text_field(wp_unslash($_POST['date'] ?? '')),
            'time'        => ORB_Availability::sanitize_time(sanitize_text_field(wp_unslash($_POST['time'] ?? ''))),
            'start_lat'   => (float) sanitize_text_field(wp_unslash($_POST['start_lat'] ?? '0')),
            'start_lng'   => (float) sanitize_text_field(wp_unslash($_POST['start_lng'] ?? '0')),
            'end_lat'     => (float) sanitize_text_field(wp_unslash($_POST['end_lat'] ?? '0')),
            'end_lng'     => (float) sanitize_text_field(wp_unslash($_POST['end_lng'] ?? '0')),
        );

        if (in_array('', array($in['pickup'], $in['destination'], $in['date'], $in['time']), true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['date'])) {
            wp_send_json_error(array('message' => __('Please fill in every field.', 'osm-ride-booking')));
        }

        $error = self::validate_lead_time($in['date'], $in['time']);
        if ($error === '') {
            $error = ORB_Availability::unavailable_message($in['date'], $in['time']);
        }
        if ($error !== '') {
            wp_send_json_error(array('message' => $error));
        }

        // Distance comes from the routing service, never from the browser.
        $route = ORB_Routing::fetch_route($in['start_lat'], $in['start_lng'], $in['end_lat'], $in['end_lng']);
        if (is_wp_error($route)) {
            wp_send_json_error(array('message' => $route->get_error_message()));
        }

        $distance_km = $route['distance'] / 1000;
        $base        = ORB_Pricing::base_price($distance_km, ORB_Pricing::settings());
        $cfg         = ORB_Peak::config();
        $peak        = ORB_Peak::applies($in['date'], $in['time'], $in['start_lat'], $in['start_lng'], $in['end_lat'], $in['end_lng'], $cfg);

        $booking = array(
            'pickup'      => $in['pickup'],
            'destination' => $in['destination'],
            'date'        => $in['date'],
            'time'        => $in['time'],
            'distance_km' => round($distance_km, 1),
            'duration'    => (int) round($route['duration']),
            'base_price'  => $base,
            'peak_rate'   => $peak ? $cfg['rate'] : 0,
            'price'       => $peak ? ORB_Peak::apply_to_price($base, $cfg) : $base,
            'unique'      => wp_generate_uuid4(), // several bookings can sit in the same cart
        );

        $product = self::ensure_product();
        wc_clear_notices();
        if (!WC()->cart->add_to_cart($product->get_id(), 1, 0, array(), array('orb_booking' => $booking))) {
            $notices = wc_get_notices('error');
            wc_clear_notices();
            $detail = $notices ? ' ' . wp_strip_all_tags($notices[0]['notice'] ?? '') : '';
            wp_send_json_error(array('message' => __('The booking could not be added to the cart.', 'osm-ride-booking') . $detail));
        }

        wp_send_json_success(array('message' => __('Booking added to your cart.', 'osm-ride-booking'), 'cart_url' => wc_get_cart_url()));
    }

    /**
     * Minimum notice before pickup (setting, in minutes).
     */
    public static function validate_lead_time($date, $time) {
        $tz      = wp_timezone();
        $pickup  = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $time, $tz);
        // Same rule as the form: now + notice, rounded up to the next 5 minutes.
        $minimum = new DateTime('@' . (int) (ceil((time() + max(0, (int) get_option('orb_min_lead_minutes', 60)) * 60) / 300) * 300));
        $minimum->setTimezone($tz);

        if (!$pickup) {
            return __('Invalid date.', 'osm-ride-booking');
        }
        if ($pickup < $minimum) {
            /* translators: %s: earliest date and time a ride can be booked for */
            return sprintf(__('Rides must be booked in advance. Earliest pickup: %s.', 'osm-ride-booking'), wp_date(get_option('date_format') . ' H:i', $minimum->getTimestamp()));
        }
        return '';
    }

    public function set_cart_prices($cart) {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }
        foreach ($cart->get_cart() as $item) {
            if (isset($item['orb_booking']['price'])) {
                $item['data']->set_price($item['orb_booking']['price']);
            }
        }
    }

    /**
     * Visible booking details: label => value.
     */
    private static function details(array $b) {
        $rows = array(
            __('Pickup', 'osm-ride-booking')      => $b['pickup'],
            __('Destination', 'osm-ride-booking') => $b['destination'],
            __('Date', 'osm-ride-booking')        => date_i18n(get_option('date_format'), strtotime($b['date'] . ' 12:00:00')),
            __('Time', 'osm-ride-booking')        => $b['time'],
            __('Distance', 'osm-ride-booking')    => number_format_i18n($b['distance_km'], 1) . ' km',
        );
        if (!empty($b['peak_rate'])) {
            /* translators: 1: surcharge percentage, 2: price before surcharge */
            $rows[__('Peak-hour surcharge', 'osm-ride-booking')] = sprintf(__('+%1$d %% included (base price %2$s)', 'osm-ride-booking'), round($b['peak_rate'] * 100), html_entity_decode(wp_strip_all_tags(wc_price($b['base_price'])), ENT_QUOTES, 'UTF-8'));
        }
        return $rows;
    }

    public function cart_item_data($data, $item) {
        if (!empty($item['orb_booking'])) {
            foreach (self::details($item['orb_booking']) as $key => $value) {
                $data[] = array('key' => $key, 'value' => esc_html($value));
            }
        }
        return $data;
    }

    public function save_order_item_meta($item, $cart_item_key, $values) {
        if (empty($values['orb_booking'])) {
            return;
        }
        $b = $values['orb_booking'];
        foreach (self::details($b) as $label => $value) {
            $item->add_meta_data($label, $value);
        }
        // Language-independent keys used by the availability calendar and the iCal feed.
        foreach (array('date', 'time', 'duration', 'pickup', 'destination') as $key) {
            $item->add_meta_data('_orb_' . $key, $b[$key]);
        }
    }

    /* ---------------------------------------------------------------------
     * Legal notice
     * ------------------------------------------------------------------ */

    public static function legal_notice() {
        return trim((string) get_option('orb_legal_notice', ''));
    }

    private static function cart_has_booking() {
        foreach (WC()->cart ? WC()->cart->get_cart() : array() as $item) {
            if (!empty($item['orb_booking'])) {
                return true;
            }
        }
        return false;
    }

    private static function order_has_booking($order) {
        foreach ($order->get_items() as $item) {
            if ($item->get_meta('_orb_date')) {
                return true;
            }
        }
        return false;
    }

    private static function render_notice() {
        if (self::legal_notice() !== '') {
            echo '<div class="orb-legal">' . wp_kses_post(wpautop(self::legal_notice())) . '</div>';
        }
    }

    public function legal_notice_if_cart_has_booking() {
        if (self::cart_has_booking()) {
            self::render_notice();
        }
    }

    public function append_legal_notice_to_block($html) {
        if (self::legal_notice() === '' || !self::cart_has_booking()) {
            return $html;
        }
        ob_start();
        self::render_notice();
        return $html . ob_get_clean();
    }

    public function legal_notice_for_order($order) {
        if (self::order_has_booking($order)) {
            self::render_notice();
        }
    }

    public function legal_notice_email($order, $sent_to_admin, $plain_text) {
        if (self::legal_notice() === '' || !self::order_has_booking($order)) {
            return;
        }
        if ($plain_text) {
            echo "\n" . esc_html(wp_strip_all_tags(self::legal_notice())) . "\n";
        } else {
            echo '<div style="border:1px solid #cbd5e1;border-left:4px solid #475569;border-radius:6px;padding:12px 16px;margin:16px 0;font-size:13px;line-height:1.5;">'
                . wp_kses_post(wpautop(self::legal_notice())) . '</div>';
        }
    }

    public function enqueue_styles() {
        if (function_exists('is_cart') && (is_cart() || is_checkout() || is_account_page())) {
            wp_enqueue_style('orb-booking', ORB_URL . 'assets/css/orb-booking.css', array(), ORB_VERSION);
        }
    }
}
