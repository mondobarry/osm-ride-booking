<?php
/**
 * Route (OpenRouteService) and address search (Nominatim / OpenStreetMap), proxied server-side
 * so the API key never reaches the browser and results can be cached.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ORB_Routing {

    public function __construct() {
        foreach (array('orb_route' => 'handle_route', 'orb_geocode' => 'handle_geocode') as $action => $method) {
            add_action('wp_ajax_' . $action, array($this, $method));
            add_action('wp_ajax_nopriv_' . $action, array($this, $method));
        }
    }

    /**
     * Driving route between two points. Used for the preview and, again, when the booking is
     * added to the cart: the charged distance never comes from the browser.
     *
     * @return array|WP_Error { distance (m), duration (s), geometry (GeoJSON) }
     */
    public static function fetch_route($start_lat, $start_lng, $end_lat, $end_lng) {
        $coords = array_map('floatval', array($start_lat, $start_lng, $end_lat, $end_lng));
        if (in_array(0.0, $coords, true) || abs($coords[0]) > 90 || abs($coords[2]) > 90 || abs($coords[1]) > 180 || abs($coords[3]) > 180) {
            return new WP_Error('orb_coords', __('Invalid coordinates.', 'osm-ride-booking'));
        }

        $api_key = trim((string) get_option('orb_ors_api_key', ''));
        if ($api_key === '') {
            return new WP_Error('orb_ors_key', __('The routing service is not configured yet.', 'osm-ride-booking'));
        }

        $cache_key = 'orb_route_' . md5(vsprintf('%.6f,%.6f,%.6f,%.6f', $coords));
        $cached    = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_post('https://api.openrouteservice.org/v2/directions/driving-car/geojson', array(
            'timeout' => 30,
            'headers' => array('Authorization' => $api_key, 'Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array('coordinates' => array(array($coords[1], $coords[0]), array($coords[3], $coords[2])))),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('orb_ors_http', __('The routing service cannot be reached. Please try again.', 'osm-ride-booking'));
        }

        $data    = json_decode(wp_remote_retrieve_body($response), true);
        $feature = $data['features'][0] ?? null;
        if (wp_remote_retrieve_response_code($response) !== 200 || !$feature) {
            return new WP_Error('orb_ors_route', __('No route found between these two addresses.', 'osm-ride-booking'));
        }

        $route = array(
            'distance' => (float) ($feature['properties']['summary']['distance'] ?? 0),
            'duration' => (float) ($feature['properties']['summary']['duration'] ?? 0),
            'geometry' => $feature['geometry'] ?? null,
        );
        if ($route['distance'] <= 0) {
            return new WP_Error('orb_ors_empty', __('No route found between these two addresses.', 'osm-ride-booking'));
        }

        set_transient($cache_key, $route, HOUR_IN_SECONDS);
        return $route;
    }

    public function handle_route() {
        check_ajax_referer('orb_nonce', 'nonce');

        $p     = array_map('floatval', array($_POST['start_lat'] ?? 0, $_POST['start_lng'] ?? 0, $_POST['end_lat'] ?? 0, $_POST['end_lng'] ?? 0));
        $route = self::fetch_route($p[0], $p[1], $p[2], $p[3]);
        if (is_wp_error($route)) {
            wp_send_json_error(array('message' => $route->get_error_message()));
        }

        $cfg = ORB_Peak::config();
        $route['peak_zone'] = ORB_Peak::in_zone($p[0], $p[1], $cfg['zone']) || ORB_Peak::in_zone($p[2], $p[3], $cfg['zone']);
        wp_send_json_success($route);
    }

    /**
     * Address suggestions. Results are cached for a day to respect the Nominatim usage policy
     * (https://operations.osmfoundation.org/policies/nominatim/).
     */
    public function handle_geocode() {
        check_ajax_referer('orb_nonce', 'nonce');

        $query = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
        if (mb_strlen($query) < 3 || mb_strlen($query) > 200) {
            wp_send_json_success(array());
        }

        $countries = self::countries();
        $cache_key = 'orb_geo_' . md5(mb_strtolower($query) . '|' . $countries . '|' . get_locale());
        $cached    = get_transient($cache_key);
        if (is_array($cached)) {
            wp_send_json_success($cached);
        }

        $args = array('q' => $query, 'format' => 'json', 'addressdetails' => 1, 'limit' => 7);
        if ($countries !== '') {
            $args['countrycodes'] = $countries;
        }
        $response = wp_remote_get(add_query_arg($args, 'https://nominatim.openstreetmap.org/search'), array(
            'timeout' => 10,
            'headers' => array(
                'User-Agent'      => 'OSM-Ride-Booking/' . ORB_VERSION . ' (WordPress; ' . home_url() . ')',
                'Accept-Language' => substr(get_locale(), 0, 2) . ',en',
            ),
        ));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            wp_send_json_error(array('message' => __('Address search is unavailable. Please try again.', 'osm-ride-booking')));
        }

        $suggestions = array();
        foreach ((array) json_decode(wp_remote_retrieve_body($response), true) as $r) {
            $name = self::format_display_name((array) $r);
            if ($name !== '') {
                $suggestions[] = array('display_name' => $name, 'lat' => (float) ($r['lat'] ?? 0), 'lon' => (float) ($r['lon'] ?? 0));
            }
        }

        set_transient($cache_key, $suggestions, DAY_IN_SECONDS);
        wp_send_json_success($suggestions);
    }

    /**
     * Comma-separated ISO country codes to search in; defaults to the WooCommerce store country.
     */
    public static function countries() {
        $saved = (string) get_option('orb_geocode_countries', '');
        if ($saved === '' && function_exists('WC') && WC()->countries) {
            $saved = WC()->countries->get_base_country();
        }
        $codes = array_filter(array_map('trim', explode(',', strtolower($saved))), function ($c) {
            return (bool) preg_match('/^[a-z]{2}$/', $c);
        });
        return implode(',', $codes);
    }

    /**
     * "12 Main Street, Town, Postcode, CC", or "Name (Station), Town, Postcode, CC" for transport and points of interest.
     */
    public static function format_display_name(array $r) {
        $addr     = (array) ($r['address'] ?? array());
        $city     = $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['municipality'] ?? '';
        $postcode = $addr['postcode'] ?? '';
        $country  = strtoupper($addr['country_code'] ?? '');
        $type     = $r['type'] ?? '';
        $labels   = array(
            'station'       => __('Station', 'osm-ride-booking'),
            'train_station' => __('Station', 'osm-ride-booking'),
            'halt'          => __('Stop', 'osm-ride-booking'),
            'stop_position' => __('Stop', 'osm-ride-booking'),
            'platform'      => __('Stop', 'osm-ride-booking'),
            'tram_stop'     => __('Tram stop', 'osm-ride-booking'),
            'bus_stop'      => __('Bus stop', 'osm-ride-booking'),
            'bus_station'   => __('Bus station', 'osm-ride-booking'),
            'aerodrome'     => __('Airport', 'osm-ride-booking'),
            'terminal'      => __('Terminal', 'osm-ride-booking'),
            'taxi'          => __('Taxi rank', 'osm-ride-booking'),
        );
        $is_poi = !empty($r['name'])
            && (in_array($r['class'] ?? '', array('public_transport', 'railway', 'aeroway', 'amenity'), true) || isset($labels[$type]));

        if ($is_poi) {
            $first = isset($labels[$type]) ? $r['name'] . ' (' . $labels[$type] . ')' : $r['name'];
        } else {
            $road  = $addr['road'] ?? $addr['pedestrian'] ?? $addr['footway'] ?? '';
            $first = trim(($addr['house_number'] ?? '') . ' ' . $road);
        }

        $parts = array_values(array_filter(array($first, $city, $postcode, $country), 'strlen'));
        return count($parts) > 1 ? implode(', ', $parts) : '';
    }
}
