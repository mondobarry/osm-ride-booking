<?php
/**
 * Opening hours (weekly schedule + date exceptions), conflicts with paid bookings, iCal feed.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ORB_Availability {

    const DAY_KEYS = array('mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun');

    public function __construct() {
        foreach (array('save_schedule', 'save_override', 'delete_override', 'month', 'regenerate_token') as $action) {
            add_action('wp_ajax_orb_availability_' . $action, array($this, 'ajax_' . $action));
        }
        add_action('wp_ajax_orb_check_availability', array($this, 'ajax_check_availability'));
        add_action('wp_ajax_nopriv_orb_check_availability', array($this, 'ajax_check_availability'));
        add_action('init', array($this, 'handle_ical_feed'));
    }

    /* ---------------------------------------------------------------------
     * Schedule
     * ------------------------------------------------------------------ */

    public static function day_label($day_key) {
        // 2024-01-01 is a Monday.
        return date_i18n('l', strtotime('2024-01-0' . (array_search($day_key, self::DAY_KEYS, true) + 1) . ' 12:00:00'));
    }

    public static function get_weekly_schedule() {
        $schedule = json_decode((string) get_option('orb_weekly_schedule', ''), true);
        if (!is_array($schedule)) {
            $schedule = array();
            foreach (self::DAY_KEYS as $day) {
                $schedule[$day] = array('open' => $day !== 'sun', 'start' => '08:00', 'end' => '20:00');
            }
        }
        return $schedule;
    }

    public static function get_date_overrides() {
        $overrides = json_decode((string) get_option('orb_date_overrides', ''), true);
        return is_array($overrides) ? $overrides : array();
    }

    /**
     * @return array { open, start, end, reason, source: override|weekly }
     */
    public static function get_date_status($date) {
        $overrides = self::get_date_overrides();
        if (isset($overrides[$date])) {
            $ov = $overrides[$date];
            return array('open' => !empty($ov['open']), 'start' => $ov['start'] ?? '', 'end' => $ov['end'] ?? '', 'reason' => $ov['reason'] ?? '', 'source' => 'override');
        }
        $day = self::get_weekly_schedule()[self::date_to_day_key($date)] ?? array();
        return array('open' => !empty($day['open']), 'start' => $day['start'] ?? '', 'end' => $day['end'] ?? '', 'reason' => '', 'source' => 'weekly');
    }

    public static function is_available($date, $time = '') {
        $status = self::get_date_status($date);
        if (!$status['open']) {
            return false;
        }
        if ($time !== '' && $status['start'] !== '' && $status['end'] !== '' && ($time < $status['start'] || $time > $status['end'])) {
            return false;
        }
        return $time === '' || self::get_booking_conflict($date, $time) === null;
    }

    /**
     * Human-readable reason why a slot cannot be booked, or '' if it can.
     */
    public static function unavailable_message($date, $time) {
        $status = self::get_date_status($date);
        $day    = self::day_label(self::date_to_day_key($date));

        if (!$status['open']) {
            /* translators: %s: weekday name */
            $msg = sprintf(__('Not available on %s.', 'osm-ride-booking'), $day);
            return $status['reason'] !== '' ? $msg . ' (' . $status['reason'] . ')' : $msg;
        }
        $conflict = $time !== '' ? self::get_booking_conflict($date, $time) : null;
        if ($conflict) {
            /* translators: 1: start time, 2: end time */
            return sprintf(__('This slot is already booked (%1$s – %2$s). Next availability: %2$s.', 'osm-ride-booking'), $conflict['block_start'], $conflict['block_end']);
        }
        if (!self::is_available($date, $time)) {
            /* translators: 1: weekday name, 2: opening time, 3: closing time */
            return sprintf(__('Outside opening hours. Open on %1$s from %2$s to %3$s.', 'osm-ride-booking'), $day, $status['start'], $status['end']);
        }
        return '';
    }

    public static function get_month_data($year, $month) {
        $days_in_month = (int) gmdate('t', gmmktime(0, 0, 0, $month, 1, $year));
        $bookings      = self::get_bookings_range(sprintf('%04d-%02d-01', $year, $month), sprintf('%04d-%02d-%02d', $year, $month, $days_in_month));
        $days          = array();

        for ($d = 1; $d <= $days_in_month; $d++) {
            $date   = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $status = self::get_date_status($date);
            $days[] = array(
                'date'           => $date,
                'day'            => $d,
                'open'           => $status['open'],
                'start'          => $status['start'],
                'end'            => $status['end'],
                'reason'         => $status['reason'],
                'override'       => $status['source'] === 'override',
                'bookings_count' => count($bookings[$date] ?? array()),
                'bookings'       => $bookings[$date] ?? array(),
            );
        }

        return array('year' => $year, 'month' => $month, 'first_dow' => (int) gmdate('N', gmmktime(0, 0, 0, $month, 1, $year)), 'days' => $days);
    }

    /* ---------------------------------------------------------------------
     * Paid bookings (WooCommerce orders in processing/completed status)
     * ------------------------------------------------------------------ */

    /**
     * @return array Keyed by Y-m-d => list of { order_id, edit_url, time, duration, pickup, destination, block_start, block_end, block_start_ts, block_end_ts }
     */
    public static function get_bookings_range($from, $to) {
        global $wpdb;

        $hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        // Live availability: results must not be cached, and order item meta has no WP_Query API.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($hpos) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT oi.order_id, d.meta_value AS bdate, t.meta_value AS btime, du.meta_value AS duration, pu.meta_value AS pickup, de.meta_value AS destination
                 FROM {$wpdb->prefix}woocommerce_order_items oi
                 JOIN {$wpdb->prefix}woocommerce_order_itemmeta d ON oi.order_item_id = d.order_item_id AND d.meta_key = '_orb_date'
                 JOIN {$wpdb->prefix}woocommerce_order_itemmeta t ON oi.order_item_id = t.order_item_id AND t.meta_key = '_orb_time'
                 LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta du ON oi.order_item_id = du.order_item_id AND du.meta_key = '_orb_duration'
                 LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta pu ON oi.order_item_id = pu.order_item_id AND pu.meta_key = '_orb_pickup'
                 LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta de ON oi.order_item_id = de.order_item_id AND de.meta_key = '_orb_destination'
                 JOIN {$wpdb->prefix}wc_orders o ON oi.order_id = o.id AND o.type = 'shop_order' AND o.status IN ('wc-processing', 'wc-completed')
                 WHERE d.meta_value BETWEEN %s AND %s",
                $from,
                $to
            ));
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT oi.order_id, d.meta_value AS bdate, t.meta_value AS btime, du.meta_value AS duration, pu.meta_value AS pickup, de.meta_value AS destination
                 FROM {$wpdb->prefix}woocommerce_order_items oi
                 JOIN {$wpdb->prefix}woocommerce_order_itemmeta d ON oi.order_item_id = d.order_item_id AND d.meta_key = '_orb_date'
                 JOIN {$wpdb->prefix}woocommerce_order_itemmeta t ON oi.order_item_id = t.order_item_id AND t.meta_key = '_orb_time'
                 LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta du ON oi.order_item_id = du.order_item_id AND du.meta_key = '_orb_duration'
                 LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta pu ON oi.order_item_id = pu.order_item_id AND pu.meta_key = '_orb_pickup'
                 LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta de ON oi.order_item_id = de.order_item_id AND de.meta_key = '_orb_destination'
                 JOIN {$wpdb->posts} o ON oi.order_id = o.ID AND o.post_type = 'shop_order' AND o.post_status IN ('wc-processing', 'wc-completed')
                 WHERE d.meta_value BETWEEN %s AND %s",
                $from,
                $to
            ));
        }
        // phpcs:enable

        $buffer  = max(0, (int) get_option('orb_buffer_minutes', 15)) * 60;
        $tz      = wp_timezone();
        $by_date = array();

        foreach ((array) $rows as $row) {
            $start = DateTime::createFromFormat('Y-m-d H:i', $row->bdate . ' ' . $row->btime, $tz);
            if (!$start) {
                continue;
            }
            $duration = (int) $row->duration > 0 ? (int) $row->duration : HOUR_IN_SECONDS;
            $begin    = $start->getTimestamp() - $buffer;
            $end      = $start->getTimestamp() + $duration + $buffer;
            $order    = wc_get_order($row->order_id);

            $by_date[$row->bdate][] = array(
                'order_id'       => (int) $row->order_id,
                'edit_url'       => $order ? $order->get_edit_order_url() : '',
                'time'           => $row->btime,
                'duration'       => $duration,
                'pickup'         => (string) $row->pickup,
                'destination'    => (string) $row->destination,
                'block_start'    => wp_date('H:i', $begin, $tz),
                'block_end'      => wp_date('H:i', $end, $tz),
                'block_start_ts' => $begin,
                'block_end_ts'   => $end,
            );
        }

        foreach ($by_date as &$list) {
            usort($list, function ($a, $b) {
                return strcmp($a['time'], $b['time']);
            });
        }
        unset($list);

        return $by_date;
    }

    /**
     * The booking blocking a given slot, or null. Disabled when the site serves several rides at once.
     */
    public static function get_booking_conflict($date, $time) {
        if ($time === '' || !get_option('orb_block_overlaps', true)) {
            return null;
        }
        $check = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $time, wp_timezone());
        if (!$check) {
            return null;
        }
        foreach (self::get_bookings_range($date, $date)[$date] ?? array() as $booking) {
            if ($check->getTimestamp() >= $booking['block_start_ts'] && $check->getTimestamp() < $booking['block_end_ts']) {
                return $booking;
            }
        }
        return null;
    }

    /* ---------------------------------------------------------------------
     * AJAX
     * ------------------------------------------------------------------ */

    private function require_manager() {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'osm-ride-booking')), 403);
        }
    }

    public function ajax_save_schedule() {
        check_ajax_referer('orb_admin_nonce', 'nonce');
        $this->require_manager();
        $schedule = json_decode(sanitize_text_field(wp_unslash($_POST['schedule'] ?? '')), true);
        if (!is_array($schedule)) {
            wp_send_json_error(array('message' => __('Invalid data.', 'osm-ride-booking')));
        }
        $clean = array();
        foreach (self::DAY_KEYS as $day) {
            $d = (array) ($schedule[$day] ?? array());
            $clean[$day] = array(
                'open'  => !empty($d['open']),
                'start' => self::sanitize_time($d['start'] ?? '08:00') ?: '08:00',
                'end'   => self::sanitize_time($d['end'] ?? '20:00') ?: '20:00',
            );
        }
        update_option('orb_weekly_schedule', wp_json_encode($clean));
        wp_send_json_success(array('message' => __('Weekly schedule saved.', 'osm-ride-booking')));
    }

    public function ajax_save_override() {
        check_ajax_referer('orb_admin_nonce', 'nonce');
        $this->require_manager();
        $date = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            wp_send_json_error(array('message' => __('Invalid date.', 'osm-ride-booking')));
        }
        $open      = !empty($_POST['open']);
        $overrides = self::get_date_overrides();
        $overrides[$date] = array(
            'open'   => $open,
            'start'  => $open ? self::sanitize_time(sanitize_text_field(wp_unslash($_POST['start'] ?? ''))) : '',
            'end'    => $open ? self::sanitize_time(sanitize_text_field(wp_unslash($_POST['end'] ?? ''))) : '',
            'reason' => sanitize_text_field(wp_unslash($_POST['reason'] ?? '')),
        );
        ksort($overrides);
        update_option('orb_date_overrides', wp_json_encode($overrides));
        wp_send_json_success(array('message' => __('Exception saved.', 'osm-ride-booking')));
    }

    public function ajax_delete_override() {
        check_ajax_referer('orb_admin_nonce', 'nonce');
        $this->require_manager();
        $date      = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
        $overrides = self::get_date_overrides();
        unset($overrides[$date]);
        update_option('orb_date_overrides', wp_json_encode($overrides));
        wp_send_json_success(array('message' => __('Exception removed.', 'osm-ride-booking')));
    }

    public function ajax_month() {
        check_ajax_referer('orb_admin_nonce', 'nonce');
        $this->require_manager();
        $year  = absint($_GET['year'] ?? gmdate('Y'));
        $month = absint($_GET['month'] ?? gmdate('n'));
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            wp_send_json_error(array('message' => __('Invalid date.', 'osm-ride-booking')));
        }
        wp_send_json_success(self::get_month_data($year, $month));
    }

    public function ajax_regenerate_token() {
        check_ajax_referer('orb_admin_nonce', 'nonce');
        $this->require_manager();
        $token = bin2hex(random_bytes(16));
        update_option('orb_ical_token', $token, false);
        wp_send_json_success(array('message' => __('New link generated.', 'osm-ride-booking'), 'url' => self::get_ical_url($token)));
    }

    public function ajax_check_availability() {
        check_ajax_referer('orb_nonce', 'nonce');
        $date = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
        $time = self::sanitize_time(sanitize_text_field(wp_unslash($_POST['time'] ?? '')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            wp_send_json_error(array('message' => __('Invalid date.', 'osm-ride-booking')));
        }

        $message = self::unavailable_message($date, $time);
        if ($message === '') {
            $status = self::get_date_status($date);
            $day    = self::day_label(self::date_to_day_key($date));
            $message = $status['start'] && $status['end']
                /* translators: 1: weekday name, 2: opening time, 3: closing time */
                ? sprintf(__('Available — %1$s from %2$s to %3$s', 'osm-ride-booking'), $day, $status['start'], $status['end'])
                /* translators: %s: weekday name */
                : sprintf(__('Available — %s', 'osm-ride-booking'), $day);
            wp_send_json_success(array('available' => true, 'message' => $message));
        }
        wp_send_json_success(array('available' => false, 'message' => $message));
    }

    /* ---------------------------------------------------------------------
     * iCal feed (secret URL) — opening hours, exceptions and paid bookings
     * ------------------------------------------------------------------ */

    public static function get_ical_url($token = '') {
        return add_query_arg('orb_ical', $token !== '' ? $token : self::ensure_ical_token(), home_url('/'));
    }

    public static function ensure_ical_token() {
        $token = (string) get_option('orb_ical_token', '');
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            $token = bin2hex(random_bytes(16));
            update_option('orb_ical_token', $token, false);
        }
        return $token;
    }

    public function handle_ical_feed() {
        // Authenticated by a secret token instead of a nonce: calendar apps fetch this URL without a session.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['orb_ical'])) {
            return;
        }
        $token = sanitize_text_field(wp_unslash($_GET['orb_ical']));
        // phpcs:enable
        $stored = (string) get_option('orb_ical_token', '');
        if ($stored === '' || !hash_equals($stored, $token)) {
            status_header(403);
            exit;
        }

        $host  = wp_parse_url(home_url(), PHP_URL_HOST);
        $stamp = gmdate('Ymd\THis\Z');
        $lines = array('BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//OSM Ride Booking//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::ical_escape(get_bloginfo('name') . ' — ' . __('Availability', 'osm-ride-booking')),
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H');

        $byday = array('mon' => 'MO', 'tue' => 'TU', 'wed' => 'WE', 'thu' => 'TH', 'fri' => 'FR', 'sat' => 'SA', 'sun' => 'SU');
        foreach (self::get_weekly_schedule() as $day => $d) {
            $first   = gmdate('Ymd', strtotime('next ' . gmdate('l', strtotime('2024-01-0' . (array_search($day, self::DAY_KEYS, true) + 1)))));
            $summary = !empty($d['open'])
                ? sprintf('%s — %s %s–%s', __('Open', 'osm-ride-booking'), self::day_label($day), $d['start'], $d['end'])
                : sprintf('%s — %s', __('Closed', 'osm-ride-booking'), self::day_label($day));
            array_push($lines, 'BEGIN:VEVENT', "UID:weekly-$day@$host", "DTSTAMP:$stamp", "DTSTART;VALUE=DATE:$first",
                'DTEND;VALUE=DATE:' . gmdate('Ymd', strtotime($first . ' +1 day')), 'RRULE:FREQ=WEEKLY;BYDAY=' . $byday[$day],
                'SUMMARY:' . self::ical_escape($summary), 'TRANSP:' . (!empty($d['open']) ? 'TRANSPARENT' : 'OPAQUE'), 'END:VEVENT');
        }

        foreach (self::get_date_overrides() as $date => $ov) {
            $summary = !empty($ov['open']) ? sprintf('%s %s–%s', __('Open', 'osm-ride-booking'), $ov['start'], $ov['end']) : __('Closed', 'osm-ride-booking');
            if (!empty($ov['reason'])) {
                $summary .= ' — ' . $ov['reason'];
            }
            array_push($lines, 'BEGIN:VEVENT', "UID:override-$date@$host", "DTSTAMP:$stamp", 'DTSTART;VALUE=DATE:' . str_replace('-', '', $date),
                'DTEND;VALUE=DATE:' . gmdate('Ymd', strtotime($date . ' +1 day')), 'SUMMARY:' . self::ical_escape($summary), 'END:VEVENT');
        }

        // Bookings in UTC, so no VTIMEZONE block is needed.
        $from = wp_date('Y-m-d');
        foreach (self::get_bookings_range($from, wp_date('Y-m-d', strtotime('+6 months'))) as $date => $list) {
            foreach ($list as $bk) {
                $start = $bk['block_start_ts'] + max(0, (int) get_option('orb_buffer_minutes', 15)) * 60;
                array_push($lines, 'BEGIN:VEVENT', 'UID:booking-' . $bk['order_id'] . '-' . str_replace('-', '', $date) . "@$host", "DTSTAMP:$stamp",
                    'DTSTART:' . gmdate('Ymd\THis\Z', $start), 'DTEND:' . gmdate('Ymd\THis\Z', $start + max($bk['duration'], 1800)),
                    'SUMMARY:' . self::ical_escape(mb_strimwidth($bk['pickup'], 0, 50, '…') . ' → ' . mb_strimwidth($bk['destination'], 0, 50, '…')),
                    /* translators: %d: order number */
                    'DESCRIPTION:' . self::ical_escape(sprintf(__('Order #%d', 'osm-ride-booking'), $bk['order_id'])), 'STATUS:CONFIRMED', 'END:VEVENT');
            }
        }
        $lines[] = 'END:VCALENDAR';

        nocache_headers();
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename=availability.ics');
        echo implode("\r\n", $lines) . "\r\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCalendar text, escaped per RFC 5545.
        exit;
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    public static function date_to_day_key($date) {
        return self::DAY_KEYS[((int) gmdate('N', strtotime($date . ' 12:00:00 UTC'))) - 1];
    }

    public static function sanitize_time($time) {
        return preg_match('/^(\d{1,2}):(\d{2})/', (string) $time, $m) ? sprintf('%02d:%02d', min(23, (int) $m[1]), min(59, (int) $m[2])) : '';
    }

    private static function ical_escape($text) {
        return str_replace(array('\\', ',', ';', "\n"), array('\\\\', '\\,', '\\;', '\\n'), (string) $text);
    }
}
