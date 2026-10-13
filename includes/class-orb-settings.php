<?php
/**
 * Settings page (WooCommerce → Ride booking): services, pricing, booking rules, peak surcharge,
 * legal notice, opening hours calendar.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ORB_Settings {

    const PAGE  = 'orb-settings';
    const GROUP = 'orb_settings';

    public function __construct() {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_init', array($this, 'register'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        add_filter('option_page_capability_' . self::GROUP, function () {
            return 'manage_woocommerce';
        });
        add_filter('plugin_action_links_' . plugin_basename(ORB_FILE), function ($links) {
            array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE)) . '">' . esc_html__('Settings', 'osm-ride-booking') . '</a>');
            return $links;
        });
    }

    public function menu() {
        add_submenu_page('woocommerce', __('Ride booking', 'osm-ride-booking'), __('Ride booking', 'osm-ride-booking'), 'manage_woocommerce', self::PAGE, array($this, 'render'));
    }

    /**
     * Field definitions: option => [section, label, type, default, sanitize, description].
     */
    private function fields() {
        $price = array($this, 'sanitize_price');
        return array(
            'orb_ors_api_key'        => array('services', __('OpenRouteService API key', 'osm-ride-booking'), 'password', '', 'sanitize_text_field',
                __('Free key (2,000 routes a day) at openrouteservice.org. Stored in your database only.', 'osm-ride-booking')),
            'orb_geocode_countries'  => array('services', __('Countries for address search', 'osm-ride-booking'), 'text', '', array($this, 'sanitize_countries'),
                __('Two-letter codes separated by commas, e.g. fr,be,ch. Empty = your store country.', 'osm-ride-booking')),

            'orb_price_per_km'       => array('pricing', __('Price per km', 'osm-ride-booking'), 'price', '2.00', $price, ''),
            'orb_price_per_km_long'  => array('pricing', __('Price per km, long trips', 'osm-ride-booking'), 'price', '2.00', $price,
                __('Applies to the whole trip when it is longer than the threshold below.', 'osm-ride-booking')),
            'orb_long_threshold'     => array('pricing', __('Long trip threshold (km)', 'osm-ride-booking'), 'number', 100, 'absint',
                __('0 = always use the standard price.', 'osm-ride-booking')),
            'orb_min_price'          => array('pricing', __('Minimum fare', 'osm-ride-booking'), 'price', '20.00', $price,
                __('Prices follow your WooCommerce tax settings (entered with or without tax).', 'osm-ride-booking')),

            'orb_min_lead_minutes'   => array('rules', __('Minimum notice (minutes)', 'osm-ride-booking'), 'number', 60, 'absint',
                __('How long before pickup a ride can still be booked online.', 'osm-ride-booking')),
            'orb_buffer_minutes'     => array('rules', __('Buffer around rides (minutes)', 'osm-ride-booking'), 'number', 15, 'absint',
                __('Time blocked before and after each paid ride.', 'osm-ride-booking')),
            'orb_block_overlaps'     => array('rules', __('One ride at a time', 'osm-ride-booking'), 'checkbox', 1, array($this, 'sanitize_bool'),
                __('Refuse a booking that overlaps a paid ride (single vehicle). Untick if you run several vehicles.', 'osm-ride-booking')),
            'orb_show_duration'      => array('rules', __('Show estimated duration', 'osm-ride-booking'), 'checkbox', 1, array($this, 'sanitize_bool'),
                __('Untick if traffic makes travel times unreliable in your area.', 'osm-ride-booking')),

            'orb_peak_enabled'       => array('peak', __('Enable peak-hour surcharge', 'osm-ride-booking'), 'checkbox', 0, array($this, 'sanitize_bool'), ''),
            'orb_peak_rate'          => array('peak', __('Surcharge (%)', 'osm-ride-booking'), 'number', 25, 'absint', ''),
            'orb_peak_weekdays'      => array('peak', __('Days', 'osm-ride-booking'), 'weekdays', array(1, 2, 3, 4, 5), array($this, 'sanitize_weekdays'), ''),
            'orb_peak_slots'         => array('peak', __('Pickup time slots', 'osm-ride-booking'), 'text', '07:00-10:00, 16:00-20:00', array($this, 'sanitize_slots'),
                __('Format HH:MM-HH:MM, separated by commas.', 'osm-ride-booking')),
            'orb_peak_skip_holidays' => array('peak', __('No surcharge on French public holidays', 'osm-ride-booking'), 'checkbox', 1, array($this, 'sanitize_bool'), ''),
            'orb_peak_zone_geojson'  => array('peak', __('Zone (GeoJSON, optional)', 'osm-ride-booking'), 'textarea', '', array($this, 'sanitize_zone'),
                __('Paste a GeoJSON Polygon, MultiPolygon or FeatureCollection: the surcharge then applies only when pickup or drop-off is inside. Empty = everywhere.', 'osm-ride-booking')),

            'orb_legal_notice'       => array('legal', __('Legal notice', 'osm-ride-booking'), 'textarea', '', 'wp_kses_post',
                __('Shown on the booking form, cart, checkout, order pages and emails. Example for a French VTC operator: "Service de voiture de transport avec chauffeur — réservation préalable (art. R3120-2 du Code des transports). Exploitant : [raison sociale], [adresse], SIREN [numéro]."', 'osm-ride-booking')),
        );
    }

    public function register() {
        $sections = array(
            'services' => __('Map and route services', 'osm-ride-booking'),
            'pricing'  => __('Pricing', 'osm-ride-booking'),
            'rules'    => __('Booking rules', 'osm-ride-booking'),
            'peak'     => __('Peak-hour surcharge', 'osm-ride-booking'),
            'legal'    => __('Legal notice', 'osm-ride-booking'),
        );
        foreach ($sections as $id => $title) {
            add_settings_section('orb_' . $id, $title, null, self::PAGE);
        }
        foreach ($this->fields() as $option => $f) {
            register_setting(self::GROUP, $option, array('sanitize_callback' => $f[4], 'default' => $f[3]));
            add_settings_field($option, $f[1], array($this, 'render_field'), self::PAGE, 'orb_' . $f[0], array('option' => $option, 'type' => $f[2], 'default' => $f[3], 'desc' => $f[5], 'label_for' => $option));
        }
    }

    public function render_field($a) {
        $name  = $a['option'];
        $value = get_option($name, $a['default']);
        switch ($a['type']) {
            case 'checkbox':
                printf('<input type="hidden" name="%1$s" value="0"><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s>', esc_attr($name), checked((bool) $value, true, false));
                break;
            case 'textarea':
                printf('<textarea id="%1$s" name="%1$s" rows="5" class="large-text code">%2$s</textarea>', esc_attr($name), esc_textarea($value));
                break;
            case 'weekdays':
                // Sent even when every box is unticked, so "no day" can be saved.
                printf('<input type="hidden" name="%s[]" value="0">', esc_attr($name));
                foreach (ORB_Availability::DAY_KEYS as $i => $day) {
                    printf('<label style="margin-right:12px"><input type="checkbox" name="%1$s[]" value="%2$d" %3$s> %4$s</label>',
                        esc_attr($name), absint($i + 1), checked(in_array($i + 1, array_map('intval', (array) $value), true), true, false), esc_html(ORB_Availability::day_label($day)));
                }
                break;
            default:
                $type  = $a['type'] === 'price' ? 'text' : $a['type'];
                $class = in_array($a['type'], array('price', 'number'), true) ? 'small-text' : 'regular-text';
                printf('<input type="%1$s" id="%2$s" name="%2$s" value="%3$s" class="%4$s" autocomplete="off">', esc_attr($type), esc_attr($name), esc_attr($value), esc_attr($class));
                if ($a['type'] === 'price') {
                    echo ' ' . esc_html(get_woocommerce_currency_symbol());
                }
        }
        if ($a['desc'] !== '') {
            echo '<p class="description">' . esc_html($a['desc']) . '</p>';
        }
    }

    public function sanitize_price($value) {
        $value = (float) str_replace(',', '.', (string) $value);
        return number_format(max(0, $value), 2, '.', '');
    }

    public function sanitize_bool($value) {
        return empty($value) ? 0 : 1;
    }

    public function sanitize_countries($value) {
        $codes = array_filter(array_map('trim', explode(',', strtolower((string) $value))), function ($c) {
            return (bool) preg_match('/^[a-z]{2}$/', $c);
        });
        return implode(',', $codes);
    }

    public function sanitize_weekdays($value) {
        return array_values(array_unique(array_filter(array_map('intval', (array) $value), function ($d) {
            return $d >= 1 && $d <= 7;
        })));
    }

    public function sanitize_slots($value) {
        return implode(', ', array_map(function ($s) {
            return $s[0] . '-' . $s[1];
        }, ORB_Peak::parse_slots((string) $value)));
    }

    /**
     * Keeps the pasted GeoJSON for editing and stores the compiled polygons used at runtime.
     */
    public function sanitize_zone($value) {
        $value  = trim((string) $value);
        $result = ORB_Peak::compile_geojson($value);
        if ($result['error'] !== '') {
            add_settings_error('orb_peak_zone_geojson', 'orb_zone', __('The surcharge zone is not valid GeoJSON with at least one polygon; it was not changed.', 'osm-ride-booking'));
            return get_option('orb_peak_zone_geojson', '');
        }
        update_option('orb_peak_zone', wp_json_encode($result['zone']), false);
        return $value;
    }

    public function assets($hook) {
        if ($hook !== 'woocommerce_page_' . self::PAGE) {
            return;
        }
        wp_enqueue_style('orb-admin', ORB_URL . 'assets/css/orb-admin.css', array(), ORB_VERSION);
        wp_enqueue_script('orb-admin', ORB_URL . 'assets/js/orb-admin.js', array('jquery'), ORB_VERSION, true);

        global $wp_locale;
        wp_localize_script('orb-admin', 'orbAdmin', array(
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('orb_admin_nonce'),
            'monthNames' => array_values($wp_locale->month),
            'i18n'       => array(
                'saving'        => __('Saving…', 'osm-ride-booking'),
                'saveSchedule'  => __('Save schedule', 'osm-ride-booking'),
                'closed'        => __('Closed', 'osm-ride-booking'),
                'open'          => __('Open', 'osm-ride-booking'),
                'error'         => __('Error', 'osm-ride-booking'),
                'connection'    => __('Connection error.', 'osm-ride-booking'),
                'bookings'      => __('Bookings', 'osm-ride-booking'),
                /* translators: %s: time range */
                'blocked'       => __('Blocked: %s', 'osm-ride-booking'),
                'order'         => __('Order', 'osm-ride-booking'),
                'confirmDelete' => __('Remove this exception? The weekly schedule will apply again.', 'osm-ride-booking'),
                'confirmToken'  => __('Generate a new link? The current one will stop working.', 'osm-ride-booking'),
                'copied'        => __('Link copied.', 'osm-ride-booking'),
            ),
        ));
    }

    public function render() {
        $schedule = ORB_Availability::get_weekly_schedule();
        ?>
        <div class="wrap orb-wrap">
            <h1><?php esc_html_e('Ride booking', 'osm-ride-booking'); ?></h1>
            <?php settings_errors(); // not printed automatically outside the Settings menu ?>
            <p><?php
                /* translators: %s: shortcode */
                printf(esc_html__('Add the booking form to any page with the shortcode %s.', 'osm-ride-booking'), '<code>[osm_ride_booking]</code>');
            ?></p>

            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); do_settings_sections(self::PAGE); submit_button(); ?>
            </form>

            <div class="orb-section" id="orb-availability">
                <h2><?php esc_html_e('Opening hours', 'osm-ride-booking'); ?></h2>
                <table class="orb-weekly">
                    <thead><tr>
                        <th><?php esc_html_e('Day', 'osm-ride-booking'); ?></th>
                        <th><?php esc_html_e('Open', 'osm-ride-booking'); ?></th>
                        <th><?php esc_html_e('Hours', 'osm-ride-booking'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach (ORB_Availability::DAY_KEYS as $day) :
                        $d = $schedule[$day] ?? array('open' => false, 'start' => '08:00', 'end' => '20:00'); ?>
                        <tr data-day="<?php echo esc_attr($day); ?>">
                            <td><?php echo esc_html(ORB_Availability::day_label($day)); ?></td>
                            <td><input type="checkbox" class="orb-day-open" <?php checked(!empty($d['open'])); ?>></td>
                            <td>
                                <input type="time" class="orb-day-start" value="<?php echo esc_attr($d['start']); ?>" <?php disabled(empty($d['open'])); ?>> –
                                <input type="time" class="orb-day-end" value="<?php echo esc_attr($d['end']); ?>" <?php disabled(empty($d['open'])); ?>>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" id="orb-save-schedule" class="button button-primary"><?php esc_html_e('Save schedule', 'osm-ride-booking'); ?></button></p>

                <h3><?php esc_html_e('Calendar and exceptions', 'osm-ride-booking'); ?></h3>
                <p class="description"><?php esc_html_e('Click a day to close it or change its hours (holiday, leave…). Paid rides are shown on each day.', 'osm-ride-booking'); ?></p>
                <div class="orb-calendar">
                    <div class="orb-calendar-nav">
                        <button type="button" class="button" id="orb-prev">&larr;</button>
                        <strong id="orb-month-title"></strong>
                        <button type="button" class="button" id="orb-next">&rarr;</button>
                    </div>
                    <div class="orb-grid" id="orb-grid">
                        <?php foreach (ORB_Availability::DAY_KEYS as $day) : ?>
                            <div class="orb-grid-head"><?php echo esc_html(mb_substr(ORB_Availability::day_label($day), 0, 3)); ?></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="orb-legend">
                        <span><i class="orb-dot orb-dot-open"></i><?php esc_html_e('Open', 'osm-ride-booking'); ?></span>
                        <span><i class="orb-dot orb-dot-closed"></i><?php esc_html_e('Closed', 'osm-ride-booking'); ?></span>
                        <span><i class="orb-dot orb-dot-override"></i><?php esc_html_e('Exception', 'osm-ride-booking'); ?></span>
                        <span><i class="orb-dot orb-dot-booking"></i><?php esc_html_e('Paid rides', 'osm-ride-booking'); ?></span>
                    </div>
                </div>

                <div class="orb-panel" id="orb-panel" hidden>
                    <h4><span id="orb-panel-date"></span></h4>
                    <input type="hidden" id="orb-ov-date">
                    <p><label><input type="checkbox" id="orb-ov-open"> <?php esc_html_e('Open', 'osm-ride-booking'); ?></label></p>
                    <p id="orb-ov-times"><input type="time" id="orb-ov-start"> – <input type="time" id="orb-ov-end"></p>
                    <p><label for="orb-ov-reason"><?php esc_html_e('Reason (optional)', 'osm-ride-booking'); ?></label><br>
                        <input type="text" id="orb-ov-reason" class="regular-text"></p>
                    <p>
                        <button type="button" class="button button-primary" id="orb-ov-save"><?php esc_html_e('Save', 'osm-ride-booking'); ?></button>
                        <button type="button" class="button-link button-link-delete" id="orb-ov-delete" hidden><?php esc_html_e('Remove exception', 'osm-ride-booking'); ?></button>
                        <button type="button" class="button" id="orb-ov-cancel"><?php esc_html_e('Cancel', 'osm-ride-booking'); ?></button>
                    </p>
                    <div id="orb-panel-bookings"></div>
                </div>

                <h3><?php esc_html_e('Calendar sync (iCal)', 'osm-ride-booking'); ?></h3>
                <p class="description"><?php esc_html_e('Subscribe to this private link in Google Calendar, Apple Calendar or Outlook. It contains your customers\' addresses: do not share it.', 'osm-ride-booking'); ?></p>
                <p class="orb-ical">
                    <input type="text" id="orb-ical-url" class="large-text code" readonly value="<?php echo esc_attr(ORB_Availability::get_ical_url()); ?>">
                    <button type="button" class="button" id="orb-ical-copy"><?php esc_html_e('Copy', 'osm-ride-booking'); ?></button>
                    <button type="button" class="button-link button-link-delete" id="orb-ical-regenerate"><?php esc_html_e('Generate a new link', 'osm-ride-booking'); ?></button>
                </p>
            </div>
        </div>
        <?php
    }
}
