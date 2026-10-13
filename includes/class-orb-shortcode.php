<?php
/**
 * [osm_ride_booking] — the public booking form.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ORB_Shortcode {

    public function __construct() {
        add_shortcode('osm_ride_booking', array($this, 'render'));
    }

    public function render() {
        wp_enqueue_style('orb-leaflet', ORB_URL . 'assets/vendor/leaflet/leaflet.css', array(), '1.9.4');
        wp_enqueue_script('orb-leaflet', ORB_URL . 'assets/vendor/leaflet/leaflet.js', array(), '1.9.4', true);
        wp_enqueue_style('orb-booking', ORB_URL . 'assets/css/orb-booking.css', array('orb-leaflet'), ORB_VERSION);
        wp_enqueue_script('orb-booking', ORB_URL . 'assets/js/orb-booking.js', array('orb-leaflet', 'jquery'), ORB_VERSION, true);

        $peak = ORB_Peak::config();
        unset($peak['zone']); // the server tells the browser whether a route touches the zone

        wp_localize_script('orb-booking', 'orbData', array(
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('orb_nonce'),
            'pricing'      => ORB_Pricing::settings(),
            'peak'         => $peak,
            'leadMinutes'  => (int) get_option('orb_min_lead_minutes', 60),
            'showDuration' => (bool) get_option('orb_show_duration', true),
            'currency'     => get_woocommerce_currency(),
            'locale'       => str_replace('_', '-', get_locale()),
            /** Filters the map tile layer: { url, attribution }. */
            'tiles'        => apply_filters('orb_tile_layer', array(
                'url'         => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            )),
            /** Filters the initial map view before any address is chosen: { lat, lng, zoom }. */
            'mapCenter'    => apply_filters('orb_map_center', array('lat' => 46.6, 'lng' => 2.4, 'zoom' => 5)),
            'i18n'         => array(
                'pickup'          => __('Pickup', 'osm-ride-booking'),
                'destination'     => __('Destination', 'osm-ride-booking'),
                'routing'         => __('Calculating the route…', 'osm-ride-booking'),
                'routeError'      => __('The route could not be calculated.', 'osm-ride-booking'),
                'connection'      => __('Connection error. Please try again.', 'osm-ride-booking'),
                'fillAll'         => __('Please choose the date, time, pickup and destination.', 'osm-ride-booking'),
                'waitRoute'       => __('Please wait for the route to be calculated.', 'osm-ride-booking'),
                'unavailable'     => __('This date or time is not available.', 'osm-ride-booking'),
                'pastDate'        => __('The date cannot be in the past.', 'osm-ride-booking'),
                /* translators: %s: earliest pickup time */
                'tooSoon'         => __('Rides must be booked in advance. Earliest pickup today: %s.', 'osm-ride-booking'),
                'booking'         => __('Booking…', 'osm-ride-booking'),
                'book'            => __('Book now', 'osm-ride-booking'),
                /* translators: 1: surcharge percentage, 2: list of time slots */
                'peakNote'        => __('Peak-hour surcharge of %1$s %% for pickups between %2$s.', 'osm-ride-booking'),
            ),
        ));

        ob_start();
        $template = locate_template('osm-ride-booking/booking-form.php');
        include $template ? $template : ORB_PATH . 'templates/booking-form.php';
        return ob_get_clean();
    }
}
