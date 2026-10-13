<?php
/**
 * Booking form. Copy this file to yourtheme/osm-ride-booking/booking-form.php to customise it.
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="orb-booking" id="orb-booking">
    <div class="orb-fields">
        <div class="orb-row">
            <p class="orb-field">
                <label for="orb-date"><?php esc_html_e('Date', 'osm-ride-booking'); ?></label>
                <input type="date" id="orb-date" required>
            </p>
            <p class="orb-field">
                <label for="orb-time"><?php esc_html_e('Pickup time', 'osm-ride-booking'); ?></label>
                <input type="time" id="orb-time" required>
            </p>
        </div>

        <p class="orb-availability" id="orb-availability" role="status" hidden></p>

        <div class="orb-field orb-address">
            <label for="orb-pickup"><?php esc_html_e('Pickup address', 'osm-ride-booking'); ?></label>
            <input type="text" id="orb-pickup" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="orb-pickup-list" placeholder="<?php esc_attr_e('Start typing an address…', 'osm-ride-booking'); ?>">
            <ul class="orb-suggestions" id="orb-pickup-list" role="listbox" hidden></ul>
        </div>

        <div class="orb-field orb-address">
            <label for="orb-destination"><?php esc_html_e('Destination address', 'osm-ride-booking'); ?></label>
            <input type="text" id="orb-destination" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="orb-destination-list" placeholder="<?php esc_attr_e('Start typing an address…', 'osm-ride-booking'); ?>">
            <ul class="orb-suggestions" id="orb-destination-list" role="listbox" hidden></ul>
        </div>
    </div>

    <div class="orb-map" id="orb-map" aria-label="<?php esc_attr_e('Route map', 'osm-ride-booking'); ?>"></div>

    <div class="orb-result" id="orb-result" hidden>
        <p class="orb-result-row"><span><?php esc_html_e('Distance', 'osm-ride-booking'); ?></span><strong id="orb-distance">—</strong></p>
        <p class="orb-result-row" id="orb-duration-row"><span><?php esc_html_e('Estimated duration', 'osm-ride-booking'); ?></span><strong id="orb-duration">—</strong></p>
        <p class="orb-result-row" id="orb-peak-row" hidden><span><?php esc_html_e('Peak-hour surcharge', 'osm-ride-booking'); ?></span><strong id="orb-peak">—</strong></p>
        <p class="orb-result-row orb-price"><span><?php esc_html_e('Price', 'osm-ride-booking'); ?></span><strong id="orb-price">—</strong></p>
        <p class="orb-note" id="orb-peak-note" hidden></p>
        <?php if (wc_tax_enabled() && !wc_prices_include_tax()) : ?>
            <p class="orb-note"><?php esc_html_e('Taxes are added at checkout.', 'osm-ride-booking'); ?></p>
        <?php endif; ?>
    </div>

    <?php if (ORB_WooCommerce::legal_notice() !== '') : ?>
        <div class="orb-legal"><?php echo wp_kses_post(wpautop(ORB_WooCommerce::legal_notice())); ?></div>
    <?php endif; ?>

    <p class="orb-actions">
        <button type="button" class="orb-button" id="orb-book" disabled><?php esc_html_e('Book now', 'osm-ride-booking'); ?></button>
    </p>
    <p class="orb-message" id="orb-message" role="alert" hidden></p>
</div>
