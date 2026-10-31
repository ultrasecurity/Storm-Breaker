<?php
// header.php — top app bar partial: brand, mobile sidebar toggle,
// notification bell (with live unread count + dropdown feed),
// settings (opens #modal-settings).
//
// The notifications dropdown polls /api/notifications.php every
// NOTIF_POLL_MS while the panel is open, and continues to surface
// the unread count after the operator closes and reopens the panel
// because the "last seen" timestamp lives on disk in
// log/notifications-state.json (not localStorage).
//
// Included by panel.php after icons.php.
?>
<header class="app-header">
  <div class="app-header-inner">
    <button class="icon-btn menu-toggle" type="button" aria-label="Toggle menu" onclick="toggleSidebar()">
      <svg class="icon icon-md"><use href="#i-menu"/></svg>
    </button>

    <div class="app-header-actions">

      <!-- Notifications: bell + count badge + dropdown panel. -->
      <div class="notif-wrap">
        <button class="icon-btn notif-btn" id="notif-btn" type="button"
                aria-label="Notifications" aria-haspopup="true" aria-expanded="false">
          <svg class="icon icon-md" id="notif-icon"><use href="#i-bell"/></svg>
          <span class="notif-badge" id="notif-badge" hidden>0</span>
        </button>

        <div class="notif-panel" id="notif-panel" role="menu" aria-hidden="true">
          <div class="notif-panel-head">
            <div>
              <div class="notif-panel-title">// captures &amp; events</div>
              <div class="notif-panel-sub" id="notif-panel-sub">no unread events</div>
            </div>
            <button type="button" class="btn btn-outline btn-xs" id="notif-mark-read" disabled>
              <svg class="icon icon-sm"><use href="#i-check"/></svg>
              Mark all as read
            </button>
          </div>

          <ul class="notif-list" id="notif-list">
            <!-- populated by JS -->
          </ul>

          <div class="notif-empty" id="notif-empty">
            <svg class="icon icon-md"><use href="#i-bell"/></svg>
            <div>You're all caught up.</div>
            <div class="notif-empty-sub">// new captures will pop up here in real time</div>
          </div>
        </div>
      </div>

      <button class="icon-btn" aria-label="Account settings" type="button" onclick="openModal('modal-settings')">
        <svg class="icon icon-md"><use href="#i-settings"/></svg>
      </button>
    </div>
  </div>
</header>

<style>
/* ── Notification dropdown ─────────────────────────────────────
   Anchored to the bell, opens below it. Stays inside the viewport
   on small screens by clamping its width and hugging the right edge. */
.notif-wrap { position: relative; }

.notif-btn  { position: relative; }
.notif-btn .notif-badge {
  position: absolute;
  top: -4px; right: -4px;
  min-width: 18px; height: 18px;
  padding: 0 5px;
  background: var(--accent-red);
  color: #fff;
  border-radius: 999px;
  font-family: var(--font-mono);
  font-size: 10px; font-weight: 700;
  line-height: 18px;
  text-align: center;
  box-shadow: 0 0 0 2px var(--bg-void), var(--glow-red-sm);
  pointer-events: none;
}
.notif-btn[aria-expanded="true"] {
  background: var(--bg-hover);
  border-color: var(--border-default);
  color: var(--accent-primary);
}

.notif-panel {
  position: absolute;
  top: calc(100% + 8px); right: 0;
  width: min(420px, calc(100vw - 24px));
  max-height: min(540px, 75vh);
  background: var(--bg-surface);
  border: 1px solid var(--border-default);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-xl), var(--glow-sm);
  display: flex; flex-direction: column;
  opacity: 0;
  transform: translateY(-6px) scale(.98);
  pointer-events: none;
  transition: opacity .15s var(--ease-smooth), transform .15s var(--ease-smooth);
  z-index: 80;
}
.notif-panel.open {
  opacity: 1;
  transform: translateY(0) scale(1);
  pointer-events: all;
}
.notif-panel::before {
  content: ''; position: absolute; top: 0; left: 16px; right: 16px;
  height: 1px;
  background: linear-gradient(90deg, transparent, var(--accent-primary), transparent);
}

.notif-panel-head {
  display: flex; align-items: center; justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--border-subtle);
}
.notif-panel-title {
  font-family: var(--font-mono); font-size: var(--text-sm); font-weight: 600;
  color: var(--accent-primary); letter-spacing: .04em;
}
.notif-panel-sub {
  font-family: var(--font-mono); font-size: var(--text-xs);
  color: var(--text-muted); margin-top: 2px;
}

.notif-list {
  list-style: none; padding: 0; margin: 0;
  overflow-y: auto;
  flex: 1 1 auto;
}
.notif-item {
  display: grid;
  grid-template-columns: 28px 1fr auto;
  gap: var(--space-2) var(--space-3);
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--border-subtle);
  transition: background .12s;
}
.notif-item:hover { background: var(--bg-hover); }
.notif-item:last-child { border-bottom: 0; }
.notif-item-icon {
  width: 28px; height: 28px;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: var(--radius-sm);
  background: var(--accent-primary-muted, rgba(0,200,255,.08));
  color: var(--accent-primary);
}
.notif-item-icon.cred   { color: var(--accent-red);   background: rgba(255,45,85,.08); }
.notif-item-icon.geo    { color: var(--accent-warn,  #f5a524); background: rgba(245,165,36,.08); }
.notif-item-icon.camera { color: var(--accent-success,#22c55e); background: rgba(34,197,94,.08); }
.notif-item-icon.mic    { color: #c084fc; background: rgba(192,132,252,.08); }
.notif-item-body { min-width: 0; }
.notif-item-line1 {
  font-size: var(--text-sm); color: var(--text-primary);
  display: flex; align-items: baseline; gap: 6px; flex-wrap: wrap;
}
.notif-item-line1 .notif-target {
  font-family: var(--font-mono); font-weight: 600;
  color: var(--accent-primary);
}
.notif-item-line1 .notif-kind {
  font-family: var(--font-mono); font-size: 10px;
  text-transform: uppercase; letter-spacing: .08em;
  color: var(--text-muted);
}
.notif-item-line2 {
  font-size: var(--text-xs); color: var(--text-secondary);
  margin-top: 2px;
  word-break: break-word;
}
.notif-item-time {
  font-family: var(--font-mono); font-size: var(--text-xs);
  color: var(--text-muted);
  white-space: nowrap;
}

.notif-empty {
  display: none;
  flex-direction: column; align-items: center; justify-content: center;
  text-align: center;
  padding: var(--space-6) var(--space-4);
  color: var(--text-muted);
  gap: var(--space-2);
}
.notif-empty .icon { color: var(--text-muted); width: 28px; height: 28px; }
.notif-empty-sub  { font-family: var(--font-mono); font-size: var(--text-xs); }
.notif-list.is-empty { display: none; }
.notif-list.is-empty + .notif-empty { display: flex; }

@media (max-width: 540px) {
  .notif-panel { right: -8px; }
}
</style>

<script>
/* ── Notifications dropdown ─────────────────────────────────────
   Polls api/notifications.php every NOTIF_POLL_MS, renders into the
   header dropdown, and surfaces the unread count on the bell.

   Persistence: the unread set is determined by a "last_seen_at"
   timestamp on the SERVER (log/notifications-state.json), so closing
   the panel and coming back later still surfaces every event that
   landed in the meantime. */
(function () {
  const NOTIF_API      = 'api/notifications.php';
  const NOTIF_POLL_MS  = 5000;

  const $btn      = document.getElementById('notif-btn');
  const $panel    = document.getElementById('notif-panel');
  const $badge    = document.getElementById('notif-badge');
  const $list     = document.getElementById('notif-list');
  const $sub      = document.getElementById('notif-panel-sub');
  const $markBtn  = document.getElementById('notif-mark-read');

  let pollTimer = null;
  let isOpen    = false;

  /* Mapping from event.kind to an icon symbol id + a friendly label. */
  const KIND_META = {
    cred:   { icon: 'i-shield-alert',   label: 'CREDENTIAL' },
    device: { icon: 'i-cpu',            label: 'DEVICE'     },
    geo:    { icon: 'i-target',         label: 'GEO'        },
    camera: { icon: 'i-camera',         label: 'CAMERA'     },
    mic:    { icon: 'i-mic',            label: 'MIC'        },
  };
  // Some icons may not exist in the sprite — fall back to bell.
  function safeIcon(id) {
    return document.querySelector('#' + id) ? id : 'i-bell';
  }

  function formatRelative(unix) {
    const diff = Math.max(0, Math.floor(Date.now() / 1000) - unix);
    if (diff < 60)    return diff + 's ago';
    if (diff < 3600)  return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }

  function setBadge(count) {
    if (!count || count <= 0) {
      $badge.hidden = true;
      $badge.textContent = '0';
    } else {
      $badge.hidden = false;
      $badge.textContent = count > 99 ? '99+' : String(count);
    }
    $markBtn.disabled = !count;
  }

  function render(payload) {
    const events = payload.events || [];
    setBadge(payload.count || 0);

    if (!events.length) {
      $list.innerHTML = '';
      $list.classList.add('is-empty');
      $sub.textContent = 'no unread events';
      return;
    }
    $list.classList.remove('is-empty');

    const truncated = (payload.count || 0) > events.length;
    $sub.textContent = truncated
      ? events.length + ' shown · ' + payload.count + ' total unread'
      : events.length + ' unread event' + (events.length === 1 ? '' : 's');

    const html = events.map(ev => {
      const meta = KIND_META[ev.kind] || { icon: 'i-bell', label: ev.kind.toUpperCase() };
      const icon = safeIcon(meta.icon);
      return `
        <li class="notif-item">
          <span class="notif-item-icon ${escapeHtml(ev.kind)}">
            <svg class="icon icon-sm"><use href="#${icon}"/></svg>
          </span>
          <div class="notif-item-body">
            <div class="notif-item-line1">
              <span class="notif-kind">${escapeHtml(meta.label)}</span>
              <span class="notif-target">/${escapeHtml(ev.target)}</span>
            </div>
            <div class="notif-item-line2">${escapeHtml(ev.preview || '')}</div>
          </div>
          <span class="notif-item-time" title="${escapeHtml(new Date(ev.ts * 1000).toISOString())}">
            ${escapeHtml(formatRelative(ev.ts))}
          </span>
        </li>`;
    }).join('');

    $list.innerHTML = html;
  }

  async function refresh() {
    try {
      const res  = await fetch(NOTIF_API, { credentials: 'same-origin', cache: 'no-store' });
      const json = await res.json();
      if (!res.ok || !json.ok) return;
      render(json);
    } catch (_) { /* network blip — try again on next tick */ }
  }

  async function markAllRead() {
    try {
      const fd = new FormData();
      fd.append('action', 'mark_read');
      const res  = await fetch(NOTIF_API, { method: 'POST', body: fd, credentials: 'same-origin' });
      const json = await res.json();
      if (!res.ok || !json.ok) throw new Error(json.error || 'failed');
      render({ count: 0, events: [] });
      // Toast for nice feedback. fireToast is defined in panel.php.
      if (typeof fireToast === 'function') {
        fireToast('success', 'NOTIFICATIONS', 'All caught up.');
      }
    } catch (e) {
      if (typeof fireToast === 'function') {
        fireToast('error', 'MARK READ FAILED', e.message);
      }
    }
  }

  function openPanel() {
    isOpen = true;
    $panel.classList.add('open');
    $panel.setAttribute('aria-hidden', 'false');
    $btn.setAttribute('aria-expanded', 'true');
    refresh();   // ensure the panel is fresh the moment the user opens it
  }
  function closePanel() {
    isOpen = false;
    $panel.classList.remove('open');
    $panel.setAttribute('aria-hidden', 'true');
    $btn.setAttribute('aria-expanded', 'false');
  }
  function togglePanel() { isOpen ? closePanel() : openPanel(); }

  /* ── Wire up ────────────────────────────────────────── */
  $btn.addEventListener('click', e => { e.stopPropagation(); togglePanel(); });
  $panel.addEventListener('click', e => e.stopPropagation());
  document.addEventListener('click', () => { if (isOpen) closePanel(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && isOpen) closePanel(); });
  $markBtn.addEventListener('click', markAllRead);

  /* Polling: pause while tab is hidden so we don't burn cycles or
     pile up requests when the operator's away. Kicks back in on
     visibility change with an immediate refresh. */
  function startPoll() { stopPoll(); pollTimer = setInterval(refresh, NOTIF_POLL_MS); }
  function stopPoll()  { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stopPoll();
    else { refresh(); startPoll(); }
  });

  /* Initial fetch + start the poll. */
  refresh();
  startPoll();
})();
</script>
