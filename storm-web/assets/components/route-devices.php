<?php
/**
 * route-devices.php — UI for the "Devices" sidebar route.
 *
 * Lists every captured device-info event across every target.
 * Each card shows browser/OS fingerprint, screen, timezone, etc.
 * Caller (panel.php) is responsible for auth.
 */
?>
<section class="dev-section">

  <div class="dev-toolbar">
    <div class="dev-stats">
      <span class="badge badge-cyan" id="dev-stat-total"><span class="badge-dot cyan pulse"></span>0 DEVICES</span>
      <span class="badge badge-muted" id="dev-stat-sources">0 SOURCES</span>
    </div>
    <div class="dev-actions-bar">
      <button type="button" class="btn btn-sm btn-outline" id="dev-refresh">
        <svg class="icon icon-sm"><use href="#i-refresh"/></svg> Refresh
      </button>
    </div>
  </div>

  <div class="dev-grid" id="dev-grid"></div>

  <div class="dev-empty" id="dev-empty" hidden>
    <svg class="icon icon-xl"><use href="#i-cpu"/></svg>
    <div class="dev-empty-title">No device info captured yet</div>
    <div class="dev-empty-sub">// enable the <code>device-info</code> payload on a target — fingerprints appear after consent is granted</div>
  </div>

</section>

<template id="dev-card-tpl">
  <div class="dev-card">
    <div class="dev-card-header">
      <svg class="icon icon-md dev-card-icon"><use href="#i-cpu"/></svg>
      <div class="dev-card-meta">
        <span class="dev-card-target c-target"></span>
        <span class="dev-card-time c-time"></span>
      </div>
    </div>
    <div class="dev-card-body">
      <div class="dev-row"><span class="dev-label">BROWSER</span><span class="dev-value c-browser"></span></div>
      <div class="dev-row"><span class="dev-label">OS / PLATFORM</span><span class="dev-value c-platform"></span></div>
      <div class="dev-row"><span class="dev-label">SCREEN</span><span class="dev-value c-screen"></span></div>
      <div class="dev-row"><span class="dev-label">VIEWPORT</span><span class="dev-value c-viewport"></span></div>
      <div class="dev-row"><span class="dev-label">TIMEZONE</span><span class="dev-value c-tz"></span></div>
      <div class="dev-row"><span class="dev-label">LANGUAGE</span><span class="dev-value c-lang"></span></div>
      <div class="dev-row"><span class="dev-label">HARDWARE</span><span class="dev-value c-hw"></span></div>
      <div class="dev-row"><span class="dev-label">CONNECTION</span><span class="dev-value c-conn"></span></div>
      <div class="dev-row"><span class="dev-label">DNT / COOKIES</span><span class="dev-value c-privacy"></span></div>
    </div>
    <div class="dev-card-footer">
      <span class="dev-card-url c-url"></span>
      <div class="dev-card-actions">
        <button type="button" class="btn btn-sm btn-ghost c-copy" title="Copy all">
          <svg class="icon icon-sm"><use href="#i-copy"/></svg>
        </button>
        <button type="button" class="btn btn-sm btn-ghost danger c-delete" title="Delete">
          <svg class="icon icon-sm"><use href="#i-trash"/></svg>
        </button>
      </div>
    </div>
  </div>
</template>

<script>
/* ── Devices route ──────────────────────────────────────── */
(function () {
  const API     = 'api/devices.php';
  const POLL_MS = 5000;

  const $grid   = document.getElementById('dev-grid');
  const $empty  = document.getElementById('dev-empty');
  const $tpl    = document.getElementById('dev-card-tpl');
  const $bTotal = document.getElementById('dev-stat-total');
  const $bSrc   = document.getElementById('dev-stat-sources');
  const $btnRefresh = document.getElementById('dev-refresh');

  let lastCount = 0;

  function relTime(unix) {
    const diff = Math.max(0, Math.floor(Date.now()/1000) - unix);
    if (diff < 60)    return diff + 's ago';
    if (diff < 3600)  return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    return Math.floor(diff/86400) + 'd ago';
  }

  function parseBrowser(ua) {
    if (!ua) return '—';
    const m = ua.match(/(Chrome|Firefox|Safari|Edge|OPR|Opera)[\/\s](\d+)/i);
    if (m) {
      const name = m[1] === 'OPR' ? 'Opera' : m[1];
      return name + ' ' + m[2];
    }
    if (ua.includes('Mobile')) return 'Mobile Browser';
    return ua.slice(0, 40) + (ua.length > 40 ? '…' : '');
  }

  function parseOS(ua, platform) {
    if (!ua) return platform || '—';
    if (/iPhone|iPad/.test(ua))      return 'iOS';
    if (/Android/.test(ua))          return 'Android';
    if (/Windows NT 10/.test(ua))    return 'Windows 10+';
    if (/Windows NT/.test(ua))       return 'Windows';
    if (/Mac OS X/.test(ua))         return 'macOS';
    if (/Linux/.test(ua))            return 'Linux';
    if (/CrOS/.test(ua))             return 'Chrome OS';
    return platform || '—';
  }

  function esc(s) { return String(s).replace(/[<>&"]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c])); }

  function render(payload) {
    const items = payload.items || [];

    $bTotal.innerHTML = '<span class="badge-dot cyan pulse"></span>' + items.length + ' DEVICES';
    $bSrc.textContent = (payload.sources || 0) + ' SOURCES';

    if (items.length === 0) {
      $grid.innerHTML = '';
      $empty.hidden = false;
      lastCount = 0;
      return;
    }
    $empty.hidden = true;

    const sig = items.length + ':' + (items[0]?.id || '');
    if ($grid.dataset.sig === sig) return;
    $grid.dataset.sig = sig;

    $grid.innerHTML = '';
    items.forEach(it => {
      const node = $tpl.content.firstElementChild.cloneNode(true);

      node.querySelector('.c-target').textContent = '/' + it.target;
      node.querySelector('.c-time').textContent = relTime(it.ts) + ' · ' +
        new Date(it.ts * 1000).toISOString().slice(0, 19).replace('T', ' ') + ' UTC';

      node.querySelector('.c-browser').textContent = parseBrowser(it.user_agent);
      node.querySelector('.c-platform').textContent = parseOS(it.user_agent, it.platform);

      const scr = it.screen;
      node.querySelector('.c-screen').textContent = scr
        ? scr.width + '×' + scr.height + ' · ' + scr.color_depth + 'bit · ' + (scr.pixel_ratio || 1) + 'x'
        : '—';
      const vp = it.viewport;
      node.querySelector('.c-viewport').textContent = vp ? vp.width + '×' + vp.height : '—';

      const tz = it.timezone;
      const tzOff = it.timezone_off;
      node.querySelector('.c-tz').textContent = tz
        ? tz + (tzOff != null ? ' (UTC' + (tzOff > 0 ? '-' : '+') + Math.abs(tzOff / 60) + ')' : '')
        : '—';

      const langs = it.languages;
      node.querySelector('.c-lang').textContent = Array.isArray(langs) && langs.length ? langs.join(', ') : '—';

      const parts = [];
      if (it.hw_cores) parts.push(it.hw_cores + ' cores');
      if (it.device_mem) parts.push(it.device_mem + ' GB RAM');
      node.querySelector('.c-hw').textContent = parts.length ? parts.join(' · ') : '—';

      const conn = it.connection;
      node.querySelector('.c-conn').textContent = conn
        ? (conn.type || '—') + (conn.downlink ? ' · ' + conn.downlink + ' Mbps' : '') + (conn.rtt ? ' · ' + conn.rtt + 'ms RTT' : '')
        : '—';

      const dnt = it.do_not_track;
      const cookies = it.cookies_on;
      node.querySelector('.c-privacy').textContent =
        'DNT: ' + (dnt === '1' ? 'on' : dnt === '0' ? 'off' : '—') +
        ' · Cookies: ' + (cookies === true ? 'on' : cookies === false ? 'off' : '—');

      if (it.page_url) {
        try {
          const u = new URL(it.page_url);
          node.querySelector('.c-url').textContent = u.hostname + u.pathname;
        } catch (_) {
          node.querySelector('.c-url').textContent = it.page_url;
        }
      }

      node.querySelector('.c-copy').addEventListener('click', () => {
        const text = 'Target: /' + it.target +
          '\nBrowser: ' + parseBrowser(it.user_agent) +
          '\nOS: ' + parseOS(it.user_agent, it.platform) +
          '\nScreen: ' + (scr ? scr.width + '×' + scr.height : '—') +
          '\nTimezone: ' + (tz || '—') +
          '\nUA: ' + (it.user_agent || '');
        copyToClipboard(text);
      });

      node.querySelector('.c-delete').addEventListener('click', () => deleteEntry(it));

      $grid.appendChild(node);
    });

    if (lastCount && items.length > lastCount && typeof fireToast === 'function') {
      fireToast('success', 'NEW DEVICE', (items.length - lastCount) + ' new fingerprint(s)');
    }
    lastCount = items.length;
  }

  function copyToClipboard(text) {
    const announce = ok => fireToast(ok ? 'success' : 'error', ok ? 'COPIED' : 'COPY FAILED',
      ok ? 'Device info copied.' : 'Clipboard not available.');
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(() => announce(true), () => announce(false));
      return;
    }
    const ta = document.createElement('textarea');
    ta.value = text; ta.setAttribute('readonly','');
    ta.style.cssText = 'position:fixed;top:0;left:-9999px;opacity:0';
    document.body.appendChild(ta); ta.select();
    let ok = false; try { ok = document.execCommand('copy'); } catch (_) {}
    document.body.removeChild(ta);
    announce(ok);
  }

  async function deleteEntry(it) {
    if (!confirm('Delete this device entry? This cannot be undone.')) return;
    try {
      const fd = new FormData();
      fd.append('action', 'delete');
      fd.append('id', it.id);
      const res  = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
      const json = await res.json();
      if (!res.ok || !json.ok) throw new Error(json.error || 'delete failed');
      if (typeof fireToast === 'function') fireToast('success', 'ENTRY DELETED', '/' + it.target);
      load(false);
    } catch (e) {
      if (typeof fireToast === 'function') fireToast('error', 'DELETE FAILED', e.message);
    }
  }

  async function load(showError) {
    try {
      const res  = await fetch(API, { credentials: 'same-origin', cache: 'no-store' });
      const json = await res.json();
      if (!res.ok || !json.ok) throw new Error(json.error || 'request failed');
      render(json);
    } catch (e) {
      if (showError && typeof fireToast === 'function') fireToast('error', 'LOAD FAILED', e.message);
    }
  }
  $btnRefresh.addEventListener('click', () => load(true));

  let pollTimer = null;
  function startPoll() { stopPoll(); pollTimer = setInterval(() => load(false), POLL_MS); }
  function stopPoll()  { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stopPoll();
    else { load(false); startPoll(); }
  });

  load(false).then(startPoll);
})();
</script>

<style>
.dev-section { display: flex; flex-direction: column; gap: var(--space-4); }
.dev-toolbar {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: var(--space-3);
}
.dev-stats       { display: flex; gap: var(--space-2); flex-wrap: wrap; }
.dev-actions-bar { display: flex; gap: var(--space-2); flex-wrap: wrap; }

.dev-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
  gap: var(--space-4);
}

.dev-card {
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-lg);
  overflow: hidden;
  transition: border-color .15s;
}
.dev-card:hover { border-color: var(--accent-primary); }

.dev-card-header {
  display: flex; align-items: center; gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--border-subtle);
  background: var(--bg-overlay);
}
.dev-card-icon { color: var(--accent-primary); flex-shrink: 0; }
.dev-card-meta { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.dev-card-target {
  font-family: var(--font-mono); font-size: var(--text-sm); font-weight: 600;
  color: var(--accent-cyan);
}
.dev-card-time {
  font-family: var(--font-mono); font-size: var(--text-xs);
  color: var(--text-muted);
}

.dev-card-body { padding: var(--space-3) var(--space-4); display: flex; flex-direction: column; gap: var(--space-2); }
.dev-row {
  display: flex; justify-content: space-between; align-items: baseline;
  gap: var(--space-3); min-height: 20px;
}
.dev-label {
  font-family: var(--font-mono); font-size: 10px; font-weight: 600;
  color: var(--text-muted); letter-spacing: .06em; text-transform: uppercase;
  white-space: nowrap; flex-shrink: 0;
}
.dev-value {
  font-family: var(--font-mono); font-size: var(--text-xs);
  color: var(--text-primary); text-align: right;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

.dev-card-footer {
  display: flex; align-items: center; justify-content: space-between;
  padding: var(--space-2) var(--space-4);
  border-top: 1px solid var(--border-subtle);
  background: var(--bg-overlay);
}
.dev-card-url {
  font-family: var(--font-mono); font-size: var(--text-xs);
  color: var(--text-muted); overflow: hidden; text-overflow: ellipsis;
  white-space: nowrap; max-width: 60%;
}
.dev-card-actions { display: flex; gap: var(--space-1); }

.dev-empty[hidden] { display: none !important; }
.dev-empty {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center;
  padding: var(--space-7) var(--space-4);
  background: var(--bg-surface);
  border: 1px dashed var(--border-subtle);
  border-radius: var(--radius-lg);
  color: var(--text-muted);
  gap: var(--space-2);
}
.dev-empty .icon { color: var(--text-muted); width: 36px; height: 36px; }
.dev-empty-title {
  font-family: var(--font-mono); font-size: var(--text-sm); font-weight: 600;
  color: var(--text-secondary); letter-spacing: .04em;
}
.dev-empty-sub { font-family: var(--font-mono); font-size: var(--text-xs); }
.dev-empty-sub code {
  background: var(--bg-overlay);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-sm);
  padding: 1px 5px;
  color: var(--text-code);
}
</style>
