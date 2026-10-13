=== OSM Ride Booking ===
Contributors: mondobarry
Tags: booking, taxi, chauffeur, openstreetmap, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ride booking for drivers and shuttles: OpenStreetMap address search, route-based price, availability calendar, WooCommerce payment.

== Description ==

Customers type their pickup and destination, see the route on an OpenStreetMap map with the distance-based price, then pay through WooCommerce. No Google API needed.

* Address autocomplete (Nominatim) with stations and airports, keyboard accessible
* Driving route and distance (OpenRouteService, free API key)
* Price per km, long-distance rate above a threshold, minimum fare
* Optional peak-hour surcharge: percentage, weekdays, time slots, French public holidays excluded, optional GeoJSON zone
* Weekly opening hours, date exceptions, automatic blocking of paid slots with a buffer
* Private iCal feed for Google Calendar, Apple Calendar or Outlook
* Configurable legal notice on the form, cart, checkout, orders and emails
* The price is always recomputed on the server; the API key never reaches the browser
* Compatible with HPOS and the cart/checkout blocks
* French translation included

Shortcode: `[osm_ride_booking]`

== External services ==

This plugin relies on the following third-party services:

* **Nominatim (OpenStreetMap Foundation)** — address search. The text typed by the customer is sent from your server when they search for an address. [Usage policy](https://operations.osmfoundation.org/policies/nominatim/), [privacy policy](https://osmfoundation.org/wiki/Privacy_Policy).
* **OpenRouteService (HeiGIT)** — driving route and distance. The pickup and destination coordinates are sent from your server when a route is calculated. [Terms](https://openrouteservice.org/terms-of-service/), [privacy policy](https://openrouteservice.org/privacy-policy/).
* **OpenStreetMap tile servers** — map images, loaded by the customer's browser. [Tile usage policy](https://operations.osmfoundation.org/policies/tiles/). Another provider can be set with the `orb_tile_layer` filter.

== Installation ==

1. Install and activate WooCommerce, then this plugin.
2. Get a free key at openrouteservice.org and enter it in WooCommerce → Ride booking.
3. Set your prices, opening hours and, if needed, the surcharge and legal notice.
4. Add `[osm_ride_booking]` to a page.

== Frequently Asked Questions ==

= Can customers change the price? =

No. The route is recomputed on the server when the ride is added to the cart, and the price is calculated from it.

= Can I take several rides at the same time? =

Yes: untick "One ride at a time" in the booking rules.

== Screenshots ==

1. Booking form with route, peak-hour surcharge and price.
2. Opening hours and calendar with paid rides.

== Changelog ==

= 1.0.0 =
* First public release.
