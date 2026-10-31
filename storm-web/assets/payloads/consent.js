/**
 * consent.js — informed-consent gate.
 *
 * Loaded FIRST inside the injected payload block, ahead of every capture
 * payload. Its job: nothing runs until the person in front of the page has
 * read a notice and actively agreed to it.
 *
 * Contract
 * ────────
 *   window.__SB_CONSENT.register(name, fn)
 *
 *   Capture payloads hand their entry point here instead of running on
 *   load. Behaviour depends on the gate state:
 *
 *     pending   → queued, not run
 *     granted   → run immediately
 *     declined  → dropped, never run
 *
 *   Fail-closed: a payload that finds no controller on `window` does
 *   nothing at all. If this file is missing, blocked, or fails to parse,
 *   the capture modules stay inert rather than falling back to firing on
 *   page load.
 *
 * Config
 * ──────
 *   window.__SB_CONSENT_CONFIG — written by the cloner from the target's
 *   meta.json `consent` object. Every field is optional; the defaults
 *   below are what a Persian-language awareness lab shows.
 *
 * Accessibility
 * ─────────────
 *   The notice is a modal dialog: focus is trapped inside it while it is
 *   open, Escape counts as "decline" (the safe default — it sends nothing),
 *   and the card is wired with role/aria-modal/aria-labelledby so a screen
 *   reader announces it as a dialog with its title and lead.
 */
(function () {
  'use strict';

  if (window.__SB_CONSENT) return;          // idempotent — first load wins
  if (!window.__SB_TARGET) return;          // not a cloned page, nothing to gate

  var SLUG = window.__SB_TARGET;
  var CFG  = (window.__SB_CONSENT_CONFIG && typeof window.__SB_CONSENT_CONFIG === 'object')
    ? window.__SB_CONSENT_CONFIG
    : {};
  var T = CFG.text || {};

  /* ── copy ─────────────────────────────────────────────────────────── */

  var TEXT = {
    title: T.title || 'این صفحه یک محیط آزمایشی است',
    lead:  T.lead  || 'این صفحه بخشی از یک تمرین آموزش امنیت است و یک سرویس واقعی نیست. لطفاً قبل از ادامه، متن زیر را بخوانید.',

    bullets: (Array.isArray(T.bullets) && T.bullets.length) ? T.bullets : [
      'اگر روی «موافقم» بزنید، اطلاعاتی از این دستگاه جمع‌آوری و برای گردانندهٔ تمرین ارسال می‌شود: تصویر وبکم، صدای میکروفون، موقعیت مکانی، مشخصات مرورگر و مقادیری که در فرم‌های این صفحه وارد می‌کنید.',
      'اگر روی «انصراف» بزنید، هیچ اطلاعاتی جمع‌آوری یا ارسال نمی‌شود و همین صفحه بسته می‌شود.',
      'پس از زدن «موافقم»، مرورگر خودش هم برای دوربین، میکروفون و موقعیت مکانی از شما اجازه می‌گیرد. رد کردن آن اجازه‌ها هم کاملاً بی‌خطر است.',
      'هدف تمرین این است که ببینید یک صفحهٔ ناشناس، در صورت اجازهٔ شما، تا چه حد می‌تواند به اطلاعاتتان دسترسی پیدا کند.'
    ],

    accept:  T.accept  || 'موافقم — اطلاعات من ارسال شود',
    decline: T.decline || 'انصراف — هیچ اطلاعاتی ارسال نشود',

    confirm_title:  T.confirm_title  || 'آیا مطمئن هستید؟',
    confirm_lead:   T.confirm_lead   || 'با زدن «بله»، همین حالا جمع‌آوری و ارسال اطلاعات این دستگاه آغاز می‌شود. اگر مطمئن نیستید، برگردید.',
    confirm_accept: T.confirm_accept || 'بله، مطمئنم — ارسال شود',
    confirm_back:   T.confirm_back   || 'بازگشت',

    declined_title: T.declined_title || 'اطلاعاتی ارسال نشد',
    declined_note:  T.declined_note  || 'هیچ داده‌ای از این صفحه جمع‌آوری یا ارسال نشد. برای تمرین بیشتر می‌توانید صفحه را دوباره بارگذاری کنید و این بار مسیر «موافقم» را انتخاب کنید.',

    footer: T.footer || 'این پیام توسط محیط آزمایشگاهی که این صفحه را ساخته، نمایش داده می‌شود.',

    badge: T.badge || 'حالت تمرین آموزشی — فعال'
  };

  var SHOW_BADGE     = CFG.show_badge      !== false;   // default: on
  var REPORT_EVENT   = CFG.report_consent  !== false;   // default: report the grant
  var REPORT_DECLINE = CFG.report_decline  === true;    // default: off — a decline stays silent

  /* ── state ────────────────────────────────────────────────────────── */

  var state   = 'pending';                  // pending | granted | declined
  var view    = 'main';                     // which card shows: main | confirm | declined
  var queue   = [];
  var overlay = null;
  var badge   = null;

  function safe(fn) {
    try { fn(); } catch (_) { /* one broken module must not stop the rest */ }
  }

  /* ── network ──────────────────────────────────────────────────────── */

  function tellServer(decision) {
    var url;
    try {
      url = window.__SB_API
        ? window.__SB_API('consent')
        : (location.origin + '/api/capture.php?slug=' + encodeURIComponent(SLUG) + '&kind=consent');
    } catch (_) { return; }

    var body = JSON.stringify({
      decision:    decision,
      slug:        SLUG,
      page_url:    location.href,
      user_agent:  navigator.userAgent,
      language:    navigator.language || null,
      decided_at:  new Date().toISOString()
    });

    try {
      fetch(url, {
        method:      'POST',
        headers:     { 'Content-Type': 'application/json' },
        body:        body,
        keepalive:   true,
        mode:        'same-origin',
        credentials: 'omit'
      }).catch(function () {});
    } catch (_) { /* nothing else to do */ }
  }

  /* ── dom helpers ──────────────────────────────────────────────────── */

  function el(tag, props, kids) {
    var node = document.createElement(tag);
    if (props) {
      for (var k in props) {
        if (!Object.prototype.hasOwnProperty.call(props, k)) continue;
        if (k === 'style')        node.style.cssText = props[k];
        else if (k === 'text')    node.textContent = props[k];
        else if (k === 'html')    node.innerHTML = props[k];
        else if (k === 'onClick') node.addEventListener('click', props[k]);
        else                      node.setAttribute(k, props[k]);
      }
    }
    if (kids) {
      for (var i = 0; i < kids.length; i++) {
        if (kids[i]) node.appendChild(kids[i]);
      }
    }
    return node;
  }

  /* All styling lives here as one self-contained sheet. The clone runs on
     the target page's own origin and can't borrow the panel's stylesheet,
     so colours are declared as scoped custom properties and everything is
     namespaced under .sb-consent-* to avoid colliding with the page. */
  var CSS = `
@font-face{
  font-family:'Vazirmatn SB'; font-style:normal; font-weight:400; font-display:swap;
  src:url('https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/fonts/webfonts/Vazirmatn-Regular.woff2') format('woff2');
}
@font-face{
  font-family:'Vazirmatn SB'; font-style:normal; font-weight:500; font-display:swap;
  src:url('https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/fonts/webfonts/Vazirmatn-Medium.woff2') format('woff2');
}
@font-face{
  font-family:'Vazirmatn SB'; font-style:normal; font-weight:700; font-display:swap;
  src:url('https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/fonts/webfonts/Vazirmatn-Bold.woff2') format('woff2');
}
.sb-consent-root{
  position:fixed; inset:0; z-index:2147483647;
  display:flex; align-items:center; justify-content:center;
  padding:20px; box-sizing:border-box;
  background:rgba(7,11,20,.84);
  -webkit-backdrop-filter:blur(8px); backdrop-filter:blur(8px);
  font-family:'Vazirmatn SB',Vazirmatn,Tahoma,'Segoe UI',system-ui,sans-serif;
  -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale;
  text-rendering:optimizeLegibility;
  direction:rtl; text-align:right;
  --sb-accent:#f59e0b; --sb-accent-ink:#1b1206;
  --sb-card-top:#132035; --sb-card-bot:#0e1526;
  --sb-border:#2b3a52; --sb-ink:#f8fafc; --sb-ink-2:#dce5f0; --sb-ink-3:#9fb0c6;
  animation:sb-fade .22s ease-out;
}
.sb-consent-root *{box-sizing:border-box}
.sb-consent-card{
  width:100%; max-width:560px; max-height:calc(100vh - 40px); overflow-y:auto;
  background:linear-gradient(180deg,var(--sb-card-top),var(--sb-card-bot));
  color:var(--sb-ink); border:1px solid var(--sb-border); border-radius:18px;
  padding:30px 30px 22px; box-shadow:0 24px 70px rgba(0,0,0,.6);
  animation:sb-pop .26s cubic-bezier(.2,.8,.2,1);
}
.sb-consent-card::-webkit-scrollbar{width:10px}
.sb-consent-card::-webkit-scrollbar-thumb{background:#33415533;border-radius:8px}
.sb-consent-icon{
  width:52px; height:52px; border-radius:14px; display:flex;
  align-items:center; justify-content:center;
  background:rgba(245,158,11,.13); border:1px solid rgba(245,158,11,.38);
  margin-bottom:16px;
}
.sb-consent-icon svg{
  width:28px; height:28px; stroke:var(--sb-accent); fill:none;
  stroke-width:2; stroke-linecap:round; stroke-linejoin:round;
}
.sb-consent-title{font-size:23px; font-weight:700; line-height:1.7; margin:0 0 12px; color:var(--sb-ink); letter-spacing:0}
.sb-consent-lead{font-size:16px; font-weight:400; line-height:2.1; color:var(--sb-ink-2); margin:0 0 18px}
.sb-consent-list{list-style:none; margin:0 0 22px; padding:0}
.sb-consent-list li{
  position:relative; font-size:15.5px; font-weight:400; line-height:2.1; color:var(--sb-ink-2);
  padding:0 24px 0 2px; margin-bottom:13px;
}
.sb-consent-list li::before{
  content:""; position:absolute; right:5px; top:15px; width:8px; height:8px;
  border-radius:50%; background:var(--sb-accent); box-shadow:0 0 0 3px rgba(245,158,11,.14);
}
.sb-consent-actions{display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px}
.sb-consent-btn{
  flex:1 1 220px; font-family:inherit; font-size:15.5px; font-weight:700;
  padding:15px 18px; border-radius:12px; cursor:pointer; line-height:1.7;
  transition:filter .15s, background .15s, transform .05s;
}
.sb-consent-btn:active{transform:translateY(1px)}
.sb-consent-accept{background:var(--sb-accent); color:var(--sb-accent-ink); border:1px solid var(--sb-accent)}
.sb-consent-accept:hover{filter:brightness(1.08)}
.sb-consent-decline{background:transparent; color:var(--sb-ink-2); border:1px solid #475569}
.sb-consent-decline:hover{background:#1e293b; color:var(--sb-ink)}
.sb-consent-btn:focus-visible{outline:2px solid #38bdf8; outline-offset:2px}
.sb-consent-footer{
  font-size:13px; font-weight:400; line-height:2; color:var(--sb-ink-3); margin:0;
  padding-top:14px; border-top:1px solid #1e293b;
}
.sb-badge{
  position:fixed; bottom:16px; left:16px; z-index:2147483646; display:flex;
  align-items:center; gap:8px; padding:9px 14px; border-radius:999px;
  background:rgba(15,23,42,.94); border:1px solid var(--sb-border);
  color:#e2e8f0; font-size:13px; font-weight:500;
  font-family:'Vazirmatn SB',Vazirmatn,Tahoma,system-ui,sans-serif; direction:rtl;
  -webkit-font-smoothing:antialiased;
  pointer-events:none; box-shadow:0 8px 24px rgba(0,0,0,.4);
}
.sb-badge-dot{
  width:8px; height:8px; border-radius:50%; background:#22c55e;
  box-shadow:0 0 0 0 rgba(34,197,94,.7); animation:sb-pulse 2s infinite;
}
@keyframes sb-fade{from{opacity:0}to{opacity:1}}
@keyframes sb-pop{from{opacity:0; transform:translateY(10px) scale(.98)}to{opacity:1; transform:none}}
@keyframes sb-pulse{0%{box-shadow:0 0 0 0 rgba(34,197,94,.7)}70%{box-shadow:0 0 0 9px rgba(34,197,94,0)}100%{box-shadow:0 0 0 0 rgba(34,197,94,0)}}
@media (max-width:520px){
  .sb-consent-card{padding:24px 20px 18px}
  .sb-consent-title{font-size:20px}
  .sb-consent-btn{flex:1 1 100%}
}
@media (prefers-reduced-motion:reduce){
  .sb-consent-root,.sb-consent-card{animation:none}
  .sb-badge-dot{animation:none}
}`;

  function styleOnce() {
    if (document.getElementById('sb-consent-style')) return;
    var s = document.createElement('style');
    s.id = 'sb-consent-style';
    s.textContent = CSS;
    (document.head || document.documentElement).appendChild(s);
  }

  var WARN_SVG  = '<svg viewBox="0 0 24 24"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
  var CHECK_SVG = '<svg viewBox="0 0 24 24" style="stroke:#22c55e"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';

  function lockScroll(on) {
    try {
      document.documentElement.style.overflow = on ? 'hidden' : '';
      if (document.body) document.body.style.overflow = on ? 'hidden' : '';
    } catch (_) {}
  }

  /* ── focus trap + escape ──────────────────────────────────────────── */
  // While the dialog is open, keep Tab inside it and treat Escape as a
  // decline (the safe default: it sends nothing). Bound on the document in
  // capture phase so it wins even if the cloned page has its own handlers.

  function onKeydown(e) {
    if (!overlay) return;

    if (e.key === 'Escape' || e.keyCode === 27) {
      e.preventDefault();
      if (view === 'confirm') showMain();          // Escape backs out of the confirm step
      else if (state === 'pending') decline();
      return;
    }

    if (e.key !== 'Tab' && e.keyCode !== 9) return;

    var focusables = overlay.querySelectorAll('button');
    if (!focusables.length) return;
    var first  = focusables[0];
    var last   = focusables[focusables.length - 1];
    var active = document.activeElement;

    if (e.shiftKey) {
      if (active === first || !overlay.contains(active)) { e.preventDefault(); last.focus(); }
    } else {
      if (active === last || !overlay.contains(active)) { e.preventDefault(); first.focus(); }
    }
  }

  function addKeys()    { document.addEventListener('keydown', onKeydown, true); }
  function removeKeys() { document.removeEventListener('keydown', onKeydown, true); }

  /* ── overlay ──────────────────────────────────────────────────────── */

  function mount(node) {
    var host = document.body || document.documentElement;
    host.appendChild(node);
  }

  // Swap the visible card (main ⇆ confirm ⇆ declined) in place. The keydown
  // trap stays bound — onKeydown always reads the current `overlay`.
  function swapCard(node) {
    if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
    overlay = node;
    mount(node);
    lockScroll(true);
  }

  function buildOverlay() {
    styleOnce();

    var icon = el('div', { 'class': 'sb-consent-icon', 'aria-hidden': 'true', html: WARN_SVG });

    var list = el('ul', { 'class': 'sb-consent-list' });
    for (var i = 0; i < TEXT.bullets.length; i++) {
      list.appendChild(el('li', { text: String(TEXT.bullets[i]) }));
    }

    var acceptBtn = el('button', {
      'class': 'sb-consent-btn sb-consent-accept',
      type: 'button',
      text: TEXT.accept,
      onClick: showConfirm
    });

    var declineBtn = el('button', {
      'class': 'sb-consent-btn sb-consent-decline',
      type: 'button',
      text: TEXT.decline,
      onClick: decline
    });

    var card = el('div', {
      'class': 'sb-consent-card',
      role: 'dialog',
      'aria-modal': 'true',
      'aria-labelledby': 'sb-consent-title',
      'aria-describedby': 'sb-consent-lead'
    }, [
      icon,
      el('h1', { 'class': 'sb-consent-title', id: 'sb-consent-title', text: TEXT.title }),
      el('p',  { 'class': 'sb-consent-lead',  id: 'sb-consent-lead',  text: TEXT.lead  }),
      list,
      el('div', { 'class': 'sb-consent-actions' }, [acceptBtn, declineBtn]),
      el('p', { 'class': 'sb-consent-footer', text: TEXT.footer })
    ]);

    return el('div', { 'class': 'sb-consent-root' }, [card]);
  }

  function showOverlay() {
    if (overlay) return;
    overlay = buildOverlay();
    mount(overlay);
    lockScroll(true);
    addKeys();
    var first = overlay.querySelector('.sb-consent-accept');
    if (first && first.focus) { try { first.focus(); } catch (_) {} }
  }

  // Second step: clicking "accept" on the main card lands here, not straight on
  // accept(). The gate stays `pending` — nothing runs until the person confirms
  // once more. "Back" returns to the notice; only "yes" grants.
  function showConfirm() {
    if (state !== 'pending') return;
    styleOnce();
    view = 'confirm';

    var icon = el('div', { 'class': 'sb-consent-icon', 'aria-hidden': 'true', html: WARN_SVG });

    var yesBtn = el('button', {
      'class': 'sb-consent-btn sb-consent-accept', type: 'button',
      text: TEXT.confirm_accept, onClick: accept
    });
    var backBtn = el('button', {
      'class': 'sb-consent-btn sb-consent-decline', type: 'button',
      text: TEXT.confirm_back, onClick: showMain
    });

    var card = el('div', {
      'class': 'sb-consent-card', role: 'dialog', 'aria-modal': 'true',
      'aria-labelledby': 'sb-consent-title', 'aria-describedby': 'sb-consent-lead'
    }, [
      icon,
      el('h1', { 'class': 'sb-consent-title', id: 'sb-consent-title', text: TEXT.confirm_title }),
      el('p',  { 'class': 'sb-consent-lead',  id: 'sb-consent-lead',  text: TEXT.confirm_lead  }),
      el('div', { 'class': 'sb-consent-actions' }, [yesBtn, backBtn]),
      el('p', { 'class': 'sb-consent-footer', text: TEXT.footer })
    ]);

    swapCard(el('div', { 'class': 'sb-consent-root' }, [card]));
    // Focus "back", not "yes": confirming must be the deliberate choice.
    var b = overlay.querySelector('.sb-consent-decline');
    if (b && b.focus) { try { b.focus(); } catch (_) {} }
  }

  // "Back" on the confirm card rebuilds and shows the main notice.
  function showMain() {
    view = 'main';
    swapCard(buildOverlay());
    var first = overlay.querySelector('.sb-consent-accept');
    if (first && first.focus) { try { first.focus(); } catch (_) {} }
  }

  function showDeclined() {
    styleOnce();
    view = 'declined';
    var icon = el('div', {
      'class': 'sb-consent-icon',
      'aria-hidden': 'true',
      style: 'background:rgba(34,197,94,.13);border-color:rgba(34,197,94,.38)',
      html: CHECK_SVG
    });

    var card = el('div', {
      'class': 'sb-consent-card',
      role: 'dialog',
      'aria-modal': 'true',
      'aria-labelledby': 'sb-consent-title'
    }, [
      icon,
      el('h1', { 'class': 'sb-consent-title', id: 'sb-consent-title', text: TEXT.declined_title }),
      el('p',  { 'class': 'sb-consent-lead',  text: TEXT.declined_note  }),
      el('p',  { 'class': 'sb-consent-footer', text: TEXT.footer })
    ]);

    swapCard(el('div', { 'class': 'sb-consent-root' }, [card]));
  }

  function hideOverlay() {
    removeKeys();
    if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
    overlay = null;
    lockScroll(false);
  }

  function showBadge() {
    if (!SHOW_BADGE || badge) return;
    styleOnce();
    badge = el('div', { 'class': 'sb-badge' }, [
      el('span', { 'class': 'sb-badge-dot' }),
      el('span', { text: TEXT.badge })
    ]);
    mount(badge);
  }

  /* ── decisions ────────────────────────────────────────────────────── */

  function accept() {
    if (state !== 'pending') return;
    state = 'granted';

    if (REPORT_EVENT) tellServer('granted');

    var pending = queue.slice();
    queue.length = 0;

    hideOverlay();
    showBadge();

    for (var i = 0; i < pending.length; i++) {
      safe(pending[i].fn);
    }
  }

  function decline() {
    if (state !== 'pending') return;
    state = 'declined';

    queue.length = 0;                 // never run — not now, not later
    removeKeys();                     // no trap on the (button-less) declined card

    if (REPORT_DECLINE) tellServer('declined');

    showDeclined();
  }

  /* ── public api ───────────────────────────────────────────────────── */

  window.__SB_CONSENT = {
    register: function (name, fn) {
      if (typeof fn !== 'function') return;
      if (state === 'granted')  { safe(fn); return; }
      if (state === 'pending')  { queue.push({ name: name, fn: fn }); return; }
      /* declined → dropped */
    },
    getState:  function () { return state; },
    isGranted: function () { return state === 'granted'; },
    /* Escape hatch for tests only — never wired to any UI. */
    _forceState: function (s) { state = s; }
  };

  /* ── boot ─────────────────────────────────────────────────────────── */

  function boot() { showOverlay(); }

  if (document.body) {
    boot();
  } else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
