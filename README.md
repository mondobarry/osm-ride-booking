**English** · [Français](README.fr.md)

# OSM Ride Booking

WordPress booking plugin for private drivers, chauffeur services and shuttles: customers enter their addresses, see the route on an OpenStreetMap map with a per-kilometre price, then pay through WooCommerce.

No Google APIs: the map, address search and routing rely on OpenStreetMap, Nominatim and OpenRouteService (free key).

| Booking form | Availability in the admin |
|---|---|
| ![Form](docs/screenshot-form.png) | ![Calendar](docs/screenshot-calendar.png) |

*Screenshots taken on a local demo site with fictitious data.*

## Features

- **Address search** with autocomplete (stations, airports and stops recognised), keyboard-friendly
- **Route and road distance** displayed on a Leaflet map
- **Per-kilometre pricing**: standard rate, long-distance rate above a threshold, minimum fare
- Optional **peak-hour surcharge**: percentage, days, time slots, French public holidays excluded, and an optional geographic zone (GeoJSON)
- **Availability**: weekly schedule, date exceptions (holidays, days off), automatic blocking of already-paid slots with an adjustable buffer
- Private **iCal sync** to Google Calendar, Apple Calendar or Outlook
- **WooCommerce payment**: a hidden product carries each booking; ride details appear in the cart, the order and the e-mails
- Configurable **legal notice** (for example the prior-booking proof required for chauffeur services in France)
- Compatible with HPOS order storage and the cart / checkout blocks
- Translated into **French**; source strings in English

## Security

- The **price is always recalculated on the server** from a recalculated route: any distance or price sent by the browser is ignored
- The OpenRouteService key stays on the server (calls go through WordPress)
- Every AJAX request is protected by a nonce; admin actions require the `manage_woocommerce` capability
- The iCal feed is only reachable with a secret, regenerable token

## Installation

1. Copy the folder into `wp-content/plugins/` and activate **OSM Ride Booking** (WooCommerce required)
2. Create a free key on [openrouteservice.org](https://openrouteservice.org) and enter it in **WooCommerce → Ride booking**
3. Set your rates, opening hours and, if needed, the surcharge and the legal notice
4. Add the `[osm_ride_booking]` shortcode to a page

Requirements: WordPress 6.0+, PHP 7.4+, WooCommerce 7.0+.

## For developers

| Filter | Purpose |
|---|---|
| `orb_tile_layer` | Change the map tiles (`url`, `attribution`) |
| `orb_map_center` | Initial map view (`lat`, `lng`, `zoom`) |
| `orb_peak_holidays` | Dates (Y-m-d) without surcharge |

The form can be customised by copying `templates/booking-form.php` to `your-theme/osm-ride-booking/booking-form.php`. The accent colour is set with the `--orb-accent` CSS variable.

```
includes/class-orb-pricing.php       Price calculation (pure logic, tested)
includes/class-orb-peak.php          Surcharge, public holidays, GeoJSON zone (pure logic, tested)
includes/class-orb-routing.php       OpenRouteService and Nominatim, with caching
includes/class-orb-availability.php  Opening hours, exceptions, conflicts, iCal feed
includes/class-orb-woocommerce.php   Cart, server-side price, order, legal notice
includes/class-orb-settings.php      Settings page and calendar
```

## Tests

```bash
php tests/run-tests.php
```

35 tests cover price calculation, time slots, public holidays (Easter, Ascension, Pentecost) and zone detection.

## Personal data

Addresses typed by customers are sent to Nominatim (OpenStreetMap) for autocomplete and, as coordinates, to OpenRouteService for routing. Mention it in your privacy policy. Map tiles are loaded from `tile.openstreetmap.org` (use the `orb_tile_layer` filter for another provider); respect their [usage policy](https://operations.osmfoundation.org/policies/tiles/).

## Author

**Mamadou Alimou Barry** — [mondobarry.com/en](https://www.mondobarry.com/en/)

## License

GPL v2 or later — see [LICENSE](LICENSE). Leaflet is bundled under the BSD 2-Clause license (`assets/vendor/leaflet/LICENSE`). Map data © OpenStreetMap contributors.
