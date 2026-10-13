<?php
/**
 * Unit tests for the pure pricing and peak-surcharge logic. No WordPress needed:
 *   php tests/run-tests.php
 */

define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../includes/class-orb-pricing.php';
require __DIR__ . '/../includes/class-orb-peak.php';

$failures = 0;
$count    = 0;

function check($label, $actual, $expected) {
    global $failures, $count;
    $count++;
    if ($actual !== $expected) {
        $failures++;
        echo "FAIL  $label\n      expected " . var_export($expected, true) . "\n      got      " . var_export($actual, true) . "\n";
    }
}

/* ---------------- Pricing ---------------- */

$s = array('price_per_km' => 2.0, 'price_per_km_long' => 1.5, 'long_threshold' => 50, 'min_price' => 20.0);
check('minimum fare applies to short trips', ORB_Pricing::base_price(3.2, $s), 20.0);
check('standard rate up to the threshold', ORB_Pricing::base_price(50, $s), 100.0);
check('long rate on the whole trip above the threshold', ORB_Pricing::base_price(60, $s), 90.0);
check('rounded to the cent', ORB_Pricing::base_price(12.345, $s), 24.69);
check('threshold 0 disables the long rate', ORB_Pricing::base_price(200, array('long_threshold' => 0) + $s), 400.0);
check('negative distance is treated as zero', ORB_Pricing::base_price(-5, $s), 20.0);

/* ---------------- Slots ---------------- */

check('slots parsed and normalised', ORB_Peak::parse_slots('7:00-10:00, 16:00 - 20:00'), array(array('07:00', '10:00'), array('16:00', '20:00')));
check('invalid and reversed slots dropped', ORB_Peak::parse_slots('abc, 10:00-09:00, 22:00-24:00'), array(array('22:00', '24:00')));
check('impossible hours rejected, not clamped', ORB_Peak::parse_slots('25:00-26:00, 07:75-09:00, 23:00-24:30'), array());

/* ---------------- Holidays ---------------- */

$h2026 = ORB_Peak::french_holidays(2026);
check('Easter Monday 2026', in_array('2026-04-06', $h2026, true), true);
check('Ascension 2026', in_array('2026-05-14', $h2026, true), true);
check('Whit Monday 2026', in_array('2026-05-25', $h2026, true), true);
check('Easter Monday 2027', in_array('2027-03-29', ORB_Peak::french_holidays(2027), true), true);
check('11 holidays a year', count($h2026), 11);

/* ---------------- Peak time ---------------- */

$cfg = array(
    'enabled'  => true,
    'rate'     => 0.25,
    'weekdays' => array(1, 2, 3, 4, 5),
    'slots'    => array(array('07:00', '10:00'), array('16:00', '20:00')),
    'holidays' => $h2026,
    'zone'     => array(),
);
check('weekday inside a slot', ORB_Peak::is_peak_time('2026-10-13', '08:30', $cfg), true);   // Tuesday
check('slot end is exclusive', ORB_Peak::is_peak_time('2026-10-13', '10:00', $cfg), false);
check('outside slots', ORB_Peak::is_peak_time('2026-10-13', '12:00', $cfg), false);
check('weekend excluded', ORB_Peak::is_peak_time('2026-10-17', '08:30', $cfg), false);     // Saturday
check('public holiday excluded', ORB_Peak::is_peak_time('2026-05-14', '08:30', $cfg), false);
check('disabled surcharge never applies', ORB_Peak::is_peak_time('2026-10-13', '08:30', array('enabled' => false) + $cfg), false);
check('malformed input', ORB_Peak::is_peak_time('13/10/2026', '8h30', $cfg), false);
check('price with 25 % surcharge', ORB_Peak::apply_to_price(80.0, $cfg), 100.0);

/* ---------------- Zone ---------------- */

// A 1°×1° square with a 0.2° hole in the middle.
$geojson = json_encode(array(
    'type'     => 'FeatureCollection',
    'features' => array(array(
        'type'     => 'Feature',
        'geometry' => array('type' => 'Polygon', 'coordinates' => array(
            array(array(2, 48), array(3, 48), array(3, 49), array(2, 49), array(2, 48)),
            array(array(2.4, 48.4), array(2.6, 48.4), array(2.6, 48.6), array(2.4, 48.6), array(2.4, 48.4)),
        )),
    )),
));
$compiled = ORB_Peak::compile_geojson($geojson);
check('GeoJSON compiles without error', $compiled['error'], '');
check('bounding box computed', $compiled['zone'][0]['b'], array(2.0, 48.0, 3.0, 49.0));
check('point inside the zone', ORB_Peak::in_zone(48.2, 2.2, $compiled['zone']), true);
check('point in the hole is outside', ORB_Peak::in_zone(48.5, 2.5, $compiled['zone']), false);
check('point outside the zone', ORB_Peak::in_zone(47.5, 2.5, $compiled['zone']), false);
check('no zone = everywhere', ORB_Peak::in_zone(10, 10, array()), true);
check('MultiPolygon accepted', count(ORB_Peak::compile_geojson('{"type":"MultiPolygon","coordinates":[[[[0,0],[1,0],[1,1],[0,0]]],[[[5,5],[6,5],[6,6],[5,5]]]]}')['zone']), 2);
check('empty input = no zone', ORB_Peak::compile_geojson('  '), array('zone' => array(), 'error' => ''));
check('invalid JSON rejected', ORB_Peak::compile_geojson('{oops')['error'], 'invalid_json');
check('JSON without polygon rejected', ORB_Peak::compile_geojson('{"type":"Point","coordinates":[2,48]}')['error'], 'no_polygon');

$zoned = array('zone' => $compiled['zone']) + $cfg;
check('surcharge when pickup is in the zone', ORB_Peak::applies('2026-10-13', '08:30', 48.2, 2.2, 45.0, 5.0, $zoned), true);
check('surcharge when drop-off is in the zone', ORB_Peak::applies('2026-10-13', '08:30', 45.0, 5.0, 48.2, 2.2, $zoned), true);
check('no surcharge when neither end is in the zone', ORB_Peak::applies('2026-10-13', '08:30', 45.0, 5.0, 46.0, 6.0, $zoned), false);

echo ($failures ? "$failures/$count FAILED" : "$count tests passed") . "\n";
exit($failures ? 1 : 0);
