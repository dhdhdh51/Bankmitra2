/* ==========================================================================
   D2 Recovery - the location trail map
   ==========================================================================
   Leaflet against OpenStreetMap's own tiles. No API key, no account, nothing
   that expires - the same reasoning as GeocodingService, which uses Nominatim.

   The data arrives in a data-trail attribute rather than an inline <script>,
   so this file stays static and cacheable and the page carries no inline
   script for a Content-Security-Policy to have to allow later.

   Written in the same plain ES5 style as app.js: these panels are opened on
   whatever browser a branch has, and a build step for one map would be a
   build step for the whole project.
   ========================================================================== */
(function () {
    'use strict';

    var host = document.getElementById('trailMap');
    if (!host || typeof L === 'undefined') {
        return;
    }

    var data;
    try {
        data = JSON.parse(host.getAttribute('data-trail') || '{}');
    } catch (e) {
        return;
    }

    var points = data.points || [];
    var visits = data.visits || [];
    if (points.length === 0 && visits.length === 0) {
        return;
    }

    var map = L.map(host, { scrollWheelZoom: false });

    /* OSM's usage policy asks for attribution and a sane tile volume. This is one
       operator's branch staff looking at one day at a time, which is well inside
       "light use" - and the attribution is not optional, so it is not in a
       collapsed control. */
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors'
    }).addTo(map);

    var latLngs = [];
    var i;

    for (i = 0; i < points.length; i++) {
        latLngs.push([points[i].lat, points[i].lng]);
    }

    /* The route first, so markers sit on top of it. */
    if (latLngs.length > 1) {
        L.polyline(latLngs, { color: '#1a56db', weight: 3, opacity: 0.8 }).addTo(map);
    }

    /* Every point as a small dot, with its time and accuracy. A marker pin per
       point would bury the map at a point every four minutes; the dots read as a
       path and stay clickable. */
    for (i = 0; i < points.length; i++) {
        var point = points[i];
        var accuracy = point.accuracy === null ? 'accuracy not reported' : ('+/-' + point.accuracy + ' m');

        L.circleMarker([point.lat, point.lng], {
            radius: 4,
            color: point.on_duty ? '#1a56db' : '#9aa1ab',
            fillColor: point.on_duty ? '#1a56db' : '#9aa1ab',
            fillOpacity: 0.9,
            weight: 1
        }).addTo(map).bindPopup(
            '<strong>' + escapeHtml(point.at) + '</strong><br>' + escapeHtml(accuracy)
        );
    }

    /* Where the day started and ended, called out - a path of identical dots does
       not say which end is which. */
    if (points.length > 0) {
        L.marker(latLngs[0]).addTo(map)
            .bindPopup('<strong>First point</strong><br>' + escapeHtml(points[0].at));
    }
    if (points.length > 1) {
        L.marker(latLngs[latLngs.length - 1]).addTo(map)
            .bindPopup('<strong>Last point</strong><br>' + escapeHtml(points[points.length - 1].at));
    }

    /* The visits, in a different shape entirely. The question this screen answers is
       "was the report filed where the visit happened", and that needs the two kinds
       of dot to be told apart at a glance. */
    for (i = 0; i < visits.length; i++) {
        var visit = visits[i];
        latLngs.push([visit.lat, visit.lng]);

        L.circleMarker([visit.lat, visit.lng], {
            radius: 8,
            color: '#0b7a3b',
            fillColor: '#12b76a',
            fillOpacity: 0.85,
            weight: 2
        }).addTo(map).bindPopup(
            '<strong>' + escapeHtml(visit.label) + '</strong><br>' +
            escapeHtml(visit.who) + '<br>' +
            (visit.at ? escapeHtml(visit.at) + '<br>' : '') +
            '<a href="' + escapeHtml(visit.url) + '">Open the report</a>'
        );
    }

    if (latLngs.length === 1) {
        map.setView(latLngs[0], 16);
    } else {
        map.fitBounds(L.latLngBounds(latLngs), { padding: [24, 24] });
    }

    /* Scroll-wheel zoom is off so the page can be scrolled past on a phone; clicking
       the map turns it on, which is the behaviour people expect from an embedded map. */
    map.on('click', function () {
        map.scrollWheelZoom.enable();
    });

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
}());
