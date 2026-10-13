<?php
/**
 * Distance-based fare. Pure logic: no WordPress call except in settings(), so it can be unit-tested.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ORB_Pricing {

    const DEFAULTS = array(
        'price_per_km'      => 2.00,
        'price_per_km_long' => 2.00,
        'long_threshold'    => 100,
        'min_price'         => 20.00,
    );

    /**
     * Pricing settings saved in the admin, falling back to neutral defaults.
     */
    public static function settings() {
        $s = array();
        foreach (self::DEFAULTS as $key => $default) {
            $s[$key] = (float) get_option('orb_' . $key, $default);
        }
        return $s;
    }

    /**
     * Price before any surcharge.
     *
     * One rate applies to the whole trip: the long-distance rate when the trip is longer
     * than the threshold, the standard rate otherwise. The minimum fare applies last.
     *
     * @param float $distance_km Route distance.
     * @param array $s           Settings as returned by settings().
     * @return float Rounded to the cent.
     */
    public static function base_price($distance_km, array $s) {
        $distance_km = max(0.0, (float) $distance_km);
        $threshold   = (float) $s['long_threshold'];
        $rate        = ($threshold > 0 && $distance_km > $threshold) ? (float) $s['price_per_km_long'] : (float) $s['price_per_km'];
        return round(max($distance_km * $rate, (float) $s['min_price']), 2);
    }
}
