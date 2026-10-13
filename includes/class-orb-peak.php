<?php
/**
 * Optional peak-hour surcharge: a percentage added on given weekdays and time slots,
 * optionally only when pickup or drop-off is inside a zone drawn by the site owner (GeoJSON).
 *
 * Every rule is a setting; the logic below is pure so it can be unit-tested.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ORB_Peak {

    /**
     * Surcharge configuration from the admin settings.
     *
     * @return array { enabled, rate, weekdays, slots, holidays, zone }
     */
    public static function config() {
        $year  = (int) current_time('Y');
        $skip  = (bool) get_option('orb_peak_skip_holidays', true);
        $days  = $skip ? array_merge(self::french_holidays($year), self::french_holidays($year + 1)) : array();
        $zone  = json_decode((string) get_option('orb_peak_zone', ''), true);

        return array(
            'enabled'  => (bool) get_option('orb_peak_enabled', false),
            'rate'     => max(0.0, (float) get_option('orb_peak_rate', 25)) / 100,
            'weekdays' => array_map('intval', (array) get_option('orb_peak_weekdays', array(1, 2, 3, 4, 5))),
            'slots'    => self::parse_slots((string) get_option('orb_peak_slots', '07:00-10:00, 16:00-20:00')),
            /** Filters the dates (Y-m-d) on which the surcharge never applies. */
            'holidays' => apply_filters('orb_peak_holidays', $days),
            'zone'     => is_array($zone) ? $zone : array(),
        );
    }

    /**
     * "07:00-10:00, 16:00-20:00" → [["07:00","10:00"],["16:00","20:00"]]. Invalid parts are dropped.
     */
    public static function parse_slots($text) {
        $slots = array();
        foreach (preg_split('/[,;\n]+/', (string) $text) as $part) {
            if (preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $part, $m)) {
                list(, $h1, $m1, $h2, $m2) = array_map('intval', $m);
                // Reject impossible times instead of clamping them; 24:00 is allowed as an end of day.
                if ($h1 > 23 || $m1 > 59 || $m2 > 59 || $h2 > 24 || ($h2 === 24 && $m2 !== 0)) {
                    continue;
                }
                $start = sprintf('%02d:%02d', $h1, $m1);
                $end   = sprintf('%02d:%02d', $h2, $m2);
                if ($start < $end) {
                    $slots[] = array($start, $end);
                }
            }
        }
        return $slots;
    }

    /**
     * French public holidays for a year (fixed dates plus Easter Monday, Ascension, Whit Monday).
     */
    public static function french_holidays($year) {
        $year   = (int) $year;
        $easter = self::easter_sunday($year);
        $days   = array("$year-01-01", "$year-05-01", "$year-05-08", "$year-07-14", "$year-08-15", "$year-11-01", "$year-11-11", "$year-12-25");
        foreach (array(1, 39, 50) as $offset) {
            $days[] = gmdate('Y-m-d', $easter + $offset * 86400);
        }
        sort($days);
        return $days;
    }

    // Anonymous Gregorian algorithm (Meeus/Jones/Butcher).
    private static function easter_sunday($y) {
        $a = $y % 19;
        $b = intdiv($y, 100);
        $c = $y % 100;
        $h = (19 * $a + $b - intdiv($b, 4) - intdiv($b - intdiv($b + 8, 25) + 1, 3) + 15) % 30;
        $l = (32 + 2 * ($b % 4) + 2 * intdiv($c, 4) - $h - $c % 4) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day   = (($h + $l - 7 * $m + 114) % 31) + 1;
        return gmmktime(0, 0, 0, $month, $day, $y);
    }

    /**
     * Whether a local pickup date (Y-m-d) and time (H:i) fall in a surcharged slot.
     */
    public static function is_peak_time($date, $time, array $cfg) {
        if (empty($cfg['enabled']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) || !preg_match('/^\d{2}:\d{2}/', (string) $time)) {
            return false;
        }
        $ts = strtotime($date . ' 00:00:00 UTC');
        if (!$ts || !in_array((int) gmdate('N', $ts), $cfg['weekdays'], true) || in_array($date, $cfg['holidays'], true)) {
            return false;
        }
        $t = substr($time, 0, 5);
        foreach ($cfg['slots'] as $slot) {
            if ($t >= $slot[0] && $t < $slot[1]) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a point is inside the surcharge zone. No zone configured = everywhere.
     *
     * @param array $zone Compiled polygons: [ { b: [minLng,minLat,maxLng,maxLat], r: [ring, ...] }, ... ]
     */
    public static function in_zone($lat, $lng, array $zone) {
        if (empty($zone)) {
            return true;
        }
        $lat = (float) $lat;
        $lng = (float) $lng;
        foreach ($zone as $poly) {
            $b = $poly['b'];
            if ($lng < $b[0] || $lng > $b[2] || $lat < $b[1] || $lat > $b[3]) {
                continue;
            }
            // Even-odd rule over all rings: holes are handled naturally.
            $inside = false;
            foreach ($poly['r'] as $ring) {
                $n = count($ring);
                for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                    list($xi, $yi) = $ring[$i];
                    list($xj, $yj) = $ring[$j];
                    if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
                        $inside = !$inside;
                    }
                }
            }
            if ($inside) {
                return true;
            }
        }
        return false;
    }

    public static function applies($date, $time, $start_lat, $start_lng, $end_lat, $end_lng, array $cfg) {
        return self::is_peak_time($date, $time, $cfg)
            && (self::in_zone($start_lat, $start_lng, $cfg['zone']) || self::in_zone($end_lat, $end_lng, $cfg['zone']));
    }

    public static function apply_to_price($price, array $cfg) {
        return round($price * (1 + $cfg['rate']), 2);
    }

    /**
     * Compile a GeoJSON FeatureCollection / Feature / (Multi)Polygon into the compact form used by in_zone().
     *
     * @return array { zone: array, error: string } — error is empty on success; empty input gives an empty zone.
     */
    public static function compile_geojson($json) {
        $json = trim((string) $json);
        if ($json === '') {
            return array('zone' => array(), 'error' => '');
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return array('zone' => array(), 'error' => 'invalid_json');
        }

        $geometries = array();
        $collect    = function ($node) use (&$collect, &$geometries) {
            $type = $node['type'] ?? '';
            if ($type === 'FeatureCollection') {
                foreach ((array) ($node['features'] ?? array()) as $f) {
                    $collect($f);
                }
            } elseif ($type === 'Feature') {
                $collect((array) ($node['geometry'] ?? array()));
            } elseif ($type === 'Polygon') {
                $geometries[] = (array) ($node['coordinates'] ?? array());
            } elseif ($type === 'MultiPolygon') {
                foreach ((array) ($node['coordinates'] ?? array()) as $poly) {
                    $geometries[] = (array) $poly;
                }
            }
        };
        $collect($data);

        $zone = array();
        foreach ($geometries as $rings) {
            $clean = array();
            $box   = array(INF, INF, -INF, -INF);
            foreach ($rings as $ring) {
                $points = array();
                foreach ((array) $ring as $pt) {
                    if (is_array($pt) && isset($pt[0], $pt[1]) && is_numeric($pt[0]) && is_numeric($pt[1])) {
                        $x = round((float) $pt[0], 6);
                        $y = round((float) $pt[1], 6);
                        $points[] = array($x, $y);
                        $box = array(min($box[0], $x), min($box[1], $y), max($box[2], $x), max($box[3], $y));
                    }
                }
                if (count($points) >= 3) {
                    $clean[] = $points;
                }
            }
            if ($clean) {
                $zone[] = array('b' => $box, 'r' => $clean);
            }
        }

        return $zone ? array('zone' => $zone, 'error' => '') : array('zone' => array(), 'error' => 'no_polygon');
    }
}
