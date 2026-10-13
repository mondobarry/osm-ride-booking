/* OSM Ride Booking — public form. The server recomputes route and price; this file only previews them. */
(function ($) {
    'use strict';

    var cfg = window.orbData;
    var t = cfg.i18n;
    var map, routeLayer;
    var markers = {};
    var coords = { pickup: null, destination: null };
    var route = { distance: 0, duration: 0, peakZone: false };
    var dateAvailable = true;

    /* ---------- Formatting ---------- */

    function money(amount) {
        return new Intl.NumberFormat(cfg.locale, { style: 'currency', currency: cfg.currency }).format(amount);
    }

    function duration(seconds) {
        var h = Math.floor(seconds / 3600);
        var m = Math.round((seconds % 3600) / 60);
        return h > 0 ? h + ' h ' + (m < 10 ? '0' : '') + m : m + ' min';
    }

    function pad(n) {
        return String(n).padStart(2, '0');
    }

    // Visitor's local date (toISOString() would give the UTC date).
    function localDate(d) {
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }

    /* ---------- Pricing preview (mirrors ORB_Pricing and ORB_Peak) ---------- */

    function basePrice(distanceM) {
        var p = cfg.pricing;
        var km = distanceM / 1000;
        var rate = (p.long_threshold > 0 && km > p.long_threshold) ? p.price_per_km_long : p.price_per_km;
        return Math.round(Math.max(km * rate, p.min_price) * 100) / 100;
    }

    function isPeakTime(date, time) {
        var peak = cfg.peak;
        if (!peak.enabled || !date || !time) {
            return false;
        }
        var d = new Date(date + 'T00:00:00Z');
        var isoDay = d.getUTCDay() === 0 ? 7 : d.getUTCDay();
        if (peak.weekdays.indexOf(isoDay) === -1 || peak.holidays.indexOf(date) !== -1) {
            return false;
        }
        var hm = time.substring(0, 5);
        return peak.slots.some(function (slot) {
            return hm >= slot[0] && hm < slot[1];
        });
    }

    function updatePrice() {
        if (route.distance <= 0) {
            return;
        }
        var base = basePrice(route.distance);
        var peak = route.peakZone && isPeakTime($('#orb-date').val(), $('#orb-time').val());
        var total = peak ? Math.round(base * (1 + cfg.peak.rate) * 100) / 100 : base;

        $('#orb-price').text(money(total));
        $('#orb-peak-row').prop('hidden', !peak);
        $('#orb-peak-note').prop('hidden', !peak);
        if (peak) {
            var pct = Math.round(cfg.peak.rate * 100);
            var slots = cfg.peak.slots.map(function (s) { return s[0] + '–' + s[1]; }).join(', ');
            $('#orb-peak').text('+' + money(total - base) + ' (+' + pct + ' %)');
            $('#orb-peak-note').text(t.peakNote.replace('%1$s', pct).replace('%2$s', slots).replace('%%', '%'));
        }
    }

    /* ---------- Messages ---------- */

    function message(text, type) {
        $('#orb-message').text(text).attr('class', 'orb-message orb-message-' + type).prop('hidden', false);
    }

    function clearMessage() {
        $('#orb-message').prop('hidden', true);
    }

    function refreshButton() {
        $('#orb-book').prop('disabled', !(route.distance > 0 && dateAvailable));
    }

    /* ---------- Map ---------- */

    function initMap() {
        map = L.map('orb-map', { zoomControl: false }).setView([cfg.mapCenter.lat, cfg.mapCenter.lng], cfg.mapCenter.zoom);
        L.control.zoom({ position: 'bottomright' }).addTo(map);
        L.tileLayer(cfg.tiles.url, { attribution: cfg.tiles.attribution, maxZoom: 19 }).addTo(map);
        setTimeout(function () { map.invalidateSize(); }, 300);
    }

    function setPoint(kind, lat, lng) {
        coords[kind] = { lat: lat, lng: lng };
        if (markers[kind]) {
            markers[kind].setLatLng([lat, lng]);
        } else {
            markers[kind] = L.marker([lat, lng], { draggable: true, title: t[kind] }).addTo(map).bindPopup(t[kind]);
            markers[kind].on('dragend', function (e) {
                var pos = e.target.getLatLng();
                coords[kind] = { lat: pos.lat, lng: pos.lng };
                requestRoute();
            });
        }
        if (coords.pickup && coords.destination) {
            map.fitBounds([[coords.pickup.lat, coords.pickup.lng], [coords.destination.lat, coords.destination.lng]], { padding: [50, 50] });
        } else {
            map.setView([lat, lng], 13);
        }
        requestRoute();
    }

    function requestRoute() {
        if (!coords.pickup || !coords.destination) {
            return;
        }
        if (routeLayer) {
            map.removeLayer(routeLayer);
            routeLayer = null;
        }
        route.distance = 0;
        refreshButton();
        message(t.routing, 'info');

        $.post(cfg.ajaxUrl, {
            action: 'orb_route',
            nonce: cfg.nonce,
            start_lat: coords.pickup.lat,
            start_lng: coords.pickup.lng,
            end_lat: coords.destination.lat,
            end_lng: coords.destination.lng
        }).done(function (res) {
            if (!res.success) {
                message((res.data && res.data.message) || t.routeError, 'error');
                return;
            }
            clearMessage();
            route = { distance: res.data.distance, duration: res.data.duration, peakZone: !!res.data.peak_zone };
            if (res.data.geometry) {
                routeLayer = L.geoJSON(res.data.geometry, { style: { color: '#2563eb', weight: 5, opacity: 0.8 } }).addTo(map);
                map.fitBounds(routeLayer.getBounds(), { padding: [50, 50] });
            }
            $('#orb-distance').text(new Intl.NumberFormat(cfg.locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(route.distance / 1000) + ' km');
            $('#orb-duration').text(duration(route.duration));
            $('#orb-duration-row').prop('hidden', !cfg.showDuration);
            updatePrice();
            $('#orb-result').prop('hidden', false);
            refreshButton();
        }).fail(function () {
            message(t.connection, 'error');
        });
    }

    /* ---------- Address autocomplete (keyboard accessible) ---------- */

    function autocomplete(kind) {
        var $input = $('#orb-' + kind);
        var $list = $('#orb-' + kind + '-list');
        var timer = null;
        var active = -1;

        function close() {
            $list.empty().prop('hidden', true);
            $input.attr('aria-expanded', 'false');
            active = -1;
        }

        function choose(item) {
            $input.val(item.display_name);
            close();
            setPoint(kind, item.lat, item.lon);
        }

        function highlight(i) {
            var $items = $list.children();
            active = (i + $items.length) % $items.length;
            $items.attr('aria-selected', 'false').eq(active).attr('aria-selected', 'true');
        }

        $input.on('input', function () {
            var q = $.trim($input.val());
            clearTimeout(timer);
            coords[kind] = null;
            if (q.length < 3) {
                close();
                return;
            }
            timer = setTimeout(function () {
                $.get(cfg.ajaxUrl, { action: 'orb_geocode', nonce: cfg.nonce, q: q }).done(function (res) {
                    close();
                    var items = res.success ? res.data : [];
                    items.forEach(function (item, i) {
                        $('<li role="option">').attr('id', 'orb-' + kind + '-opt-' + i).text(item.display_name)
                            .on('mousedown', function (e) { e.preventDefault(); choose(item); })
                            .data('item', item).appendTo($list);
                    });
                    if (items.length) {
                        $list.prop('hidden', false);
                        $input.attr('aria-expanded', 'true');
                    }
                });
            }, 500);
        });

        $input.on('keydown', function (e) {
            var $items = $list.children();
            if ($list.prop('hidden') || !$items.length) {
                return;
            }
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                highlight(active + (e.key === 'ArrowDown' ? 1 : -1));
            } else if (e.key === 'Enter' && active >= 0) {
                e.preventDefault();
                choose($items.eq(active).data('item'));
            } else if (e.key === 'Escape') {
                close();
            }
        });

        $input.on('blur', function () { setTimeout(close, 150); });
    }

    /* ---------- Date, time, availability ---------- */

    // Same rule as the server: now + notice, rounded up to the next 5 minutes.
    function earliestTime() {
        return new Date(Math.ceil((Date.now() + cfg.leadMinutes * 60000) / 300000) * 300000);
    }

    function syncTimeMin() {
        var earliest = earliestTime();
        var $time = $('#orb-time');
        var minDay = localDate(earliest);
        $('#orb-date').attr('min', localDate(new Date()));
        if ($('#orb-date').val() === minDay) {
            var hm = pad(earliest.getHours()) + ':' + pad(earliest.getMinutes());
            $time.attr('min', hm);
            if (!$time.val() || $time.val() < hm) {
                $time.val(hm);
            }
        } else {
            $time.removeAttr('min');
        }
    }

    function dateTimeError() {
        var date = $('#orb-date').val();
        var time = $('#orb-time').val();
        if (!date || !time) {
            return t.fillAll;
        }
        if (date < localDate(new Date())) {
            return t.pastDate;
        }
        var earliest = earliestTime();
        if (new Date(date + 'T' + time + ':00') < earliest) {
            return t.tooSoon.replace('%s', pad(earliest.getHours()) + ':' + pad(earliest.getMinutes()));
        }
        return '';
    }

    function checkAvailability() {
        var date = $('#orb-date').val();
        if (!date) {
            return;
        }
        $.post(cfg.ajaxUrl, { action: 'orb_check_availability', nonce: cfg.nonce, date: date, time: $('#orb-time').val() || '' })
            .done(function (res) {
                if (!res.success) {
                    return;
                }
                dateAvailable = !!res.data.available;
                $('#orb-availability').text(res.data.message)
                    .attr('class', 'orb-availability ' + (dateAvailable ? 'is-open' : 'is-closed')).prop('hidden', false);
                refreshButton();
            });
    }

    /* ---------- Booking ---------- */

    function book() {
        var error = dateTimeError();
        if (!error && !dateAvailable) {
            error = t.unavailable;
        }
        if (!error && (!coords.pickup || !coords.destination)) {
            error = t.fillAll;
        }
        if (!error && route.distance <= 0) {
            error = t.waitRoute;
        }
        if (error) {
            message(error, 'error');
            return;
        }

        var $btn = $('#orb-book').prop('disabled', true).text(t.booking);
        $.post(cfg.ajaxUrl, {
            action: 'orb_add_to_cart',
            nonce: cfg.nonce,
            pickup: $('#orb-pickup').val(),
            destination: $('#orb-destination').val(),
            date: $('#orb-date').val(),
            time: $('#orb-time').val(),
            start_lat: coords.pickup.lat,
            start_lng: coords.pickup.lng,
            end_lat: coords.destination.lat,
            end_lng: coords.destination.lng
        }).done(function (res) {
            if (res.success) {
                message(res.data.message, 'success');
                window.location.href = res.data.cart_url;
                return;
            }
            message((res.data && res.data.message) || t.connection, 'error');
            $btn.text(t.book);
            refreshButton();
        }).fail(function () {
            message(t.connection, 'error');
            $btn.text(t.book);
            refreshButton();
        });
    }

    $(function () {
        if (!document.getElementById('orb-map') || typeof L === 'undefined') {
            return;
        }
        initMap();
        $('#orb-date').val(localDate(earliestTime()));
        syncTimeMin();
        autocomplete('pickup');
        autocomplete('destination');

        $('#orb-date').on('change', function () {
            syncTimeMin();
            checkAvailability();
            updatePrice();
        });
        $('#orb-time').on('change', function () {
            checkAvailability();
            updatePrice();
        });
        $('#orb-book').on('click', book);
        checkAvailability();
    });
})(jQuery);
