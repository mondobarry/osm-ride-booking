/* OSM Ride Booking — opening hours, calendar exceptions and iCal link in the admin. */
(function ($) {
    'use strict';

    var cfg = window.orbAdmin;
    var t = cfg.i18n;
    var year, month;

    function toast(text, type) {
        $('.orb-toast').remove();
        var $toast = $('<div class="orb-toast">').addClass('orb-toast-' + (type || 'success')).text(text).appendTo('body');
        setTimeout(function () { $toast.addClass('is-visible'); }, 10);
        setTimeout(function () { $toast.remove(); }, 3000);
    }

    function post(data, onSuccess, $button) {
        if ($button) {
            $button.prop('disabled', true);
        }
        return $.post(cfg.ajaxUrl, $.extend({ nonce: cfg.nonce }, data))
            .done(function (res) {
                if (res.success) {
                    toast(res.data.message);
                    onSuccess(res.data);
                } else {
                    toast((res.data && res.data.message) || t.error, 'error');
                }
            })
            .fail(function () { toast(t.connection, 'error'); })
            .always(function () {
                if ($button) {
                    $button.prop('disabled', false);
                }
            });
    }

    /* ---------- Weekly schedule ---------- */

    function initSchedule() {
        $('.orb-weekly').on('change', '.orb-day-open', function () {
            $(this).closest('tr').find('input[type="time"]').prop('disabled', !this.checked);
        });

        $('#orb-save-schedule').on('click', function () {
            var schedule = {};
            $('.orb-weekly tbody tr').each(function () {
                var $row = $(this);
                schedule[$row.data('day')] = {
                    open: $row.find('.orb-day-open').is(':checked'),
                    start: $row.find('.orb-day-start').val() || '08:00',
                    end: $row.find('.orb-day-end').val() || '20:00'
                };
            });
            post({ action: 'orb_availability_save_schedule', schedule: JSON.stringify(schedule) }, function () {
                loadMonth();
            }, $(this));
        });
    }

    /* ---------- Calendar ---------- */

    function loadMonth() {
        $('#orb-month-title').text(cfg.monthNames[month - 1] + ' ' + year);
        $.get(cfg.ajaxUrl, { action: 'orb_availability_month', nonce: cfg.nonce, year: year, month: month }).done(function (res) {
            if (res.success) {
                renderMonth(res.data);
            }
        });
    }

    function renderMonth(data) {
        var $grid = $('#orb-grid');
        var today = new Date();
        var todayStr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
        $grid.find('.orb-day').remove();

        for (var i = 1; i < data.first_dow; i++) {
            $grid.append('<div class="orb-day is-empty"></div>');
        }

        data.days.forEach(function (d) {
            var status = d.open ? (d.start && d.end ? d.start + '–' + d.end : t.open) : (d.reason || t.closed);
            var $cell = $('<button type="button" class="orb-day">')
                .addClass(d.open ? 'is-open' : 'is-closed')
                .toggleClass('is-override', d.override)
                .toggleClass('is-past', d.date < todayStr)
                .append($('<span class="orb-day-number">').text(d.day))
                .append($('<span class="orb-day-status">').text(status));
            if (d.bookings_count) {
                $cell.append($('<span class="orb-day-bookings">').text(d.bookings_count).attr('title', t.bookings));
            }
            $cell.on('click', function () { openPanel(d); });
            $grid.append($cell);
        });
    }

    function initCalendar() {
        var now = new Date();
        year = now.getFullYear();
        month = now.getMonth() + 1;
        $('#orb-prev, #orb-next').on('click', function () {
            month += this.id === 'orb-next' ? 1 : -1;
            if (month < 1) { month = 12; year--; }
            if (month > 12) { month = 1; year++; }
            loadMonth();
        });
        loadMonth();
    }

    /* ---------- Exception panel ---------- */

    function openPanel(d) {
        var parts = d.date.split('-');
        $('#orb-panel').prop('hidden', false);
        $('#orb-ov-date').val(d.date);
        $('#orb-panel-date').text(new Date(+parts[0], parts[1] - 1, +parts[2]).toLocaleDateString(document.documentElement.lang || undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }));
        $('#orb-ov-open').prop('checked', d.open);
        $('#orb-ov-start').val(d.start || '08:00');
        $('#orb-ov-end').val(d.end || '20:00');
        $('#orb-ov-reason').val(d.reason || '');
        $('#orb-ov-times').prop('hidden', !d.open);
        $('#orb-ov-delete').prop('hidden', !d.override);

        var $list = $('#orb-panel-bookings').empty();
        if (d.bookings.length) {
            $list.append($('<h5>').text(t.bookings + ' (' + d.bookings.length + ')'));
            d.bookings.forEach(function (b) {
                var $item = $('<div class="orb-booking-item">')
                    .append($('<strong>').text(b.time))
                    .append($('<div>').text(b.pickup + ' → ' + b.destination))
                    .append($('<div class="orb-booking-block">').text(t.blocked.replace('%s', b.block_start + ' – ' + b.block_end)));
                if (b.edit_url) {
                    $item.append($('<a target="_blank" rel="noopener">').attr('href', b.edit_url).text(t.order + ' #' + b.order_id));
                }
                $list.append($item);
            });
        }
        $('html, body').animate({ scrollTop: $('#orb-panel').offset().top - 50 }, 300);
    }

    function closePanel() {
        $('#orb-panel').prop('hidden', true);
        loadMonth();
    }

    function initPanel() {
        $('#orb-ov-open').on('change', function () { $('#orb-ov-times').prop('hidden', !this.checked); });
        $('#orb-ov-cancel').on('click', function () { $('#orb-panel').prop('hidden', true); });

        $('#orb-ov-save').on('click', function () {
            post({
                action: 'orb_availability_save_override',
                date: $('#orb-ov-date').val(),
                open: $('#orb-ov-open').is(':checked') ? 1 : 0,
                start: $('#orb-ov-start').val(),
                end: $('#orb-ov-end').val(),
                reason: $('#orb-ov-reason').val()
            }, closePanel, $(this));
        });

        $('#orb-ov-delete').on('click', function () {
            if (window.confirm(t.confirmDelete)) {
                post({ action: 'orb_availability_delete_override', date: $('#orb-ov-date').val() }, closePanel, $(this));
            }
        });
    }

    /* ---------- iCal ---------- */

    function initICal() {
        $('#orb-ical-copy').on('click', function () {
            var url = $('#orb-ical-url').val();
            if (navigator.clipboard) {
                navigator.clipboard.writeText(url).then(function () { toast(t.copied); });
            } else {
                $('#orb-ical-url').trigger('select');
                document.execCommand('copy');
                toast(t.copied);
            }
        });
        $('#orb-ical-regenerate').on('click', function () {
            if (window.confirm(t.confirmToken)) {
                post({ action: 'orb_availability_regenerate_token' }, function (data) {
                    $('#orb-ical-url').val(data.url);
                }, $(this));
            }
        });
    }

    $(function () {
        if (!document.getElementById('orb-availability')) {
            return;
        }
        initSchedule();
        initCalendar();
        initPanel();
        initICal();
    });
})(jQuery);
