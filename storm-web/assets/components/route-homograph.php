<?php
/**
 * route-homograph.php — Homograph domain generator panel view.
 * Generates IDN/lookalike domains and checks availability via RDAP.
 */
?>
<section class="homo-section">

  <!-- ── INPUT PANEL ──────────────────────────────────── -->
  <div class="hg-panel">
    <div class="hg-panel-head">
      <svg class="icon icon-sm" style="color:var(--accent-primary)" viewBox="0 0 24 24"><path d="M4 6 L20 6" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M12 6 V20" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M7 20 L17 20" stroke="currentColor" stroke-width="1.5" fill="none"/><circle cx="6" cy="6" r="1.5" fill="currentColor"/><circle cx="18" cy="6" r="1.5" fill="currentColor"/></svg>
      <h2 class="hg-panel-title">HOMOGRAPH <span>GENERATOR</span></h2>
      <div class="hg-panel-line"></div>
    </div>
    <p class="hg-panel-note">
      Enter a target domain (e.g. <code>paypal.com</code>). We'll generate a ranked list
      of <strong>registrable</strong> lookalikes — pure-script IDN homographs, digit/bigram
      typosquats, hyphen/affix variants, TLD swaps — with the punycode (ACE) form you
      hand to the registrar.
    </p>
    <form id="homo-form" onsubmit="generateHomographs(event)" autocomplete="off">
      <div class="hg-input-row">
        <input type="text" id="homo-domain" class="hg-input"
               placeholder="paypal.com"
               pattern="[a-zA-Z0-9][a-zA-Z0-9\-]*[a-zA-Z0-9]\.[a-zA-Z]{2,}"
               required dir="ltr" spellcheck="false" autocomplete="off">
        <button type="submit" class="btn btn-primary btn-sm" id="homo-generate-btn">
          <svg class="icon icon-sm" id="homo-btn-icon"><use href="#i-zap"/></svg>
          <span class="hg-spinner" id="homo-btn-spinner" style="display:none" aria-hidden="true"></span>
          <span id="homo-btn-label">GENERATE</span>
        </button>
      </div>
    </form>

    <!-- Progress bar -->
    <div id="hg-progress" class="hg-progress" style="display:none">
      <div class="hg-progress-meta">
        <span class="hg-progress-label" id="hg-progress-label">GENERATING SUGGESTIONS</span>
        <span class="hg-progress-pct" id="hg-progress-pct">0%</span>
      </div>
      <div class="hg-progress-track">
        <div class="hg-progress-fill" id="hg-progress-fill"></div>
      </div>
    </div>
  </div>

  <!-- ── RESULTS PANEL ────────────────────────────────── -->
  <div id="homo-results" class="hg-panel" style="display:none">
    <div class="hg-panel-head">
      <svg class="icon icon-sm" style="color:var(--accent-green)" viewBox="0 0 24 24"><path d="M6 4 V20" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M18 4 V20" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M6 8 H18" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M6 16 H18" stroke="currentColor" stroke-width="1.5" fill="none"/></svg>
      <h2 class="hg-panel-title">SUGGESTIONS <span id="homo-count">0</span></h2>
      <div class="hg-panel-line"></div>
      <button id="homo-check-btn" class="btn btn-outline btn-sm" onclick="checkAllAvailability()">
        <svg class="icon icon-sm" id="homo-check-icon"><use href="#i-globe"/></svg>
        <span class="hg-spinner" id="homo-check-spinner" style="display:none" aria-hidden="true"></span>
        <span id="homo-check-label">CHECK RDAP</span>
      </button>
    </div>

    <!-- Filter chips -->
    <div id="hg-filters" class="hg-filters"></div>

    <!-- Compact list -->
    <div id="hg-list" class="hg-list"></div>

    <!-- Empty filter result -->
    <div id="hg-filter-empty" class="hg-list-empty" style="display:none">// no matches for this filter</div>
  </div>

  <!-- ── EMPTY STATE ──────────────────────────────────── -->
  <div id="homo-empty" class="homo-empty">
    <div class="homo-empty-icon">
      <svg class="icon icon-xl"><use href="#i-globe"/></svg>
    </div>
    <div class="homo-empty-title">IDN Homograph Generator</div>
    <div class="homo-empty-sub">Enter a domain above to generate IDN homograph, typosquatting, and lookalike domain suggestions across 9 categories.</div>
  </div>

</section>

<script>
let homoData = [];
let activeFilter = '';

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

const CAT_COLORS = {
  'mono-cyrillic':  { cls: 'monoscript', color: 'rgba(184,77,255,' },
  'mono-armenian':  { cls: 'monoscript', color: 'rgba(184,77,255,' },
  'mono-greek':     { cls: 'monoscript', color: 'rgba(184,77,255,' },
  'mixed-script':   { cls: 'mixed',      color: 'rgba(255,107,157,' },
  'diacritic':      { cls: 'diacritic',   color: 'rgba(255,170,0,' },
  'digit-swap':     { cls: 'typo',        color: 'rgba(0,229,255,' },
  'bigram':         { cls: 'typo',        color: 'rgba(0,229,255,' },
  'hyphen':         { cls: 'typo',        color: 'rgba(0,229,255,' },
  'tld-swap':       { cls: 'tld',         color: 'rgba(68,136,255,' },
};

function catClass(cat) {
  return (CAT_COLORS[cat] || CAT_COLORS['digit-swap']).cls;
}

async function generateHomographs(e) {
  e.preventDefault();
  const domain = document.getElementById('homo-domain').value.trim();
  if (!domain) return;

  const btn     = document.getElementById('homo-generate-btn');
  const icon    = document.getElementById('homo-btn-icon');
  const spinner = document.getElementById('homo-btn-spinner');
  const label   = document.getElementById('homo-btn-label');
  const prog    = document.getElementById('hg-progress');

  btn.disabled = true;
  icon.style.display = 'none';
  spinner.style.display = '';
  label.textContent = 'GENERATING…';
  prog.style.display = '';
  document.getElementById('hg-progress-label').textContent = 'GENERATING SUGGESTIONS';
  document.getElementById('hg-progress-pct').textContent = '';
  document.getElementById('hg-progress-fill').style.width = '30%';

  try {
    const res = await fetch('api/homograph.php?action=generate&domain=' + encodeURIComponent(domain));
    const json = await res.json();
    if (!json.ok) throw new Error(json.error);

    homoData = json.suggestions;
    document.getElementById('homo-count').textContent = json.count;
    document.getElementById('homo-results').style.display = '';
    document.getElementById('homo-empty').style.display = 'none';
    document.getElementById('hg-progress-fill').style.width = '100%';

    buildChips();
    renderList(homoData);
    if (typeof fireToast === 'function') fireToast('success', 'GENERATED', json.count + ' suggestions for ' + domain);
  } catch (err) {
    if (typeof fireToast === 'function') fireToast('error', 'ERROR', err.message);
  } finally {
    btn.disabled = false;
    icon.style.display = '';
    spinner.style.display = 'none';
    label.textContent = 'GENERATE';
    setTimeout(() => { prog.style.display = 'none'; }, 800);
  }
}

function buildChips() {
  const cats = [...new Set(homoData.map(s => s.category))];
  const el = document.getElementById('hg-filters');
  let html = '<button class="hg-chip is-active" data-filter="" onclick="setFilter(\'\',this)">ALL<span class="hg-chip-count">' + homoData.length + '</span></button>';
  cats.forEach(c => {
    const cnt = homoData.filter(s => s.category === c).length;
    const cls = catClass(c);
    html += '<button class="hg-chip hg-cat-' + cls + '" data-filter="' + esc(c) + '" onclick="setFilter(\'' + esc(c) + '\',this)">' + esc(c).toUpperCase() + '<span class="hg-chip-count">' + cnt + '</span></button>';
  });
  el.innerHTML = html;
  activeFilter = '';
}

function setFilter(cat, chipEl) {
  activeFilter = cat;
  document.querySelectorAll('.hg-chip').forEach(c => c.classList.remove('is-active'));
  chipEl.classList.add('is-active');

  const filtered = cat ? homoData.filter(s => s.category === cat) : homoData;
  document.getElementById('homo-count').textContent = filtered.length;
  document.getElementById('hg-filter-empty').style.display = filtered.length ? 'none' : '';

  document.querySelectorAll('.hg-row').forEach(row => {
    row.style.display = (!cat || row.dataset.cat === cat) ? '' : 'none';
  });
}

function renderList(data) {
  const el = document.getElementById('hg-list');
  el.innerHTML = data.map((s, i) => {
    const cls = catClass(s.category);
    return `<div class="hg-row hg-cat-${cls}-row" data-cat="${esc(s.category)}" data-domain="${esc(s.punycode || s.display)}" data-idx="${i}">
      <span class="hg-row-dot hg-row-dot-unk" title="Check availability"></span>
      <span class="hg-row-sub">${esc(s.category).toUpperCase()}</span>
      <span class="hg-display" dir="ltr" style="unicode-bidi:bidi-override">${esc(s.display)}</span>
      <span class="hg-puny">${esc(s.punycode)}</span>
      <span class="hg-avail-cell" data-domain="${esc(s.punycode || s.display)}">—</span>
      <button class="hg-btn-copy" onclick="copyDomain('${esc(s.punycode || s.display)}')" title="Copy punycode">COPY</button>
    </div>`;
  }).join('');
}

function copyDomain(d) {
  navigator.clipboard.writeText(d).then(() => {
    if (typeof fireToast === 'function') fireToast('success', 'COPIED', d);
  });
}

async function checkAllAvailability() {
  const btn     = document.getElementById('homo-check-btn');
  const icon    = document.getElementById('homo-check-icon');
  const spinner = document.getElementById('homo-check-spinner');
  const label   = document.getElementById('homo-check-label');

  btn.disabled = true;
  icon.style.display = 'none';
  spinner.style.display = '';
  label.textContent = 'CHECKING…';

  const domains = [...new Set(homoData.map(s => s.punycode || s.display))];

  try {
    for (let i = 0; i < domains.length; i += 20) {
      const batch = domains.slice(i, i + 20);
      label.textContent = 'CHECKING ' + Math.min(i + 20, domains.length) + '/' + domains.length;
      const res = await fetch('api/homograph.php?action=check', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ domains: batch }),
      });
      const json = await res.json();
      if (!json.ok) continue;

      for (const [dom, info] of Object.entries(json.results)) {
        document.querySelectorAll('.hg-avail-cell[data-domain="' + dom + '"]').forEach(cell => {
          if (info.available === true) {
            cell.innerHTML = '<span class="hg-avail hg-avail-yes">AVAILABLE</span>';
          } else if (info.available === false) {
            cell.innerHTML = '<span class="hg-avail hg-avail-no">TAKEN</span>';
          } else {
            cell.innerHTML = '<span class="hg-avail hg-avail-unk">UNKNOWN</span>';
          }
        });
        // Update dot
        document.querySelectorAll('.hg-row[data-domain="' + dom + '"] .hg-row-dot').forEach(dot => {
          dot.classList.remove('hg-row-dot-unk');
          if (info.available === true) dot.classList.add('hg-row-dot-green');
          else if (info.available === false) dot.classList.add('hg-row-dot-red');
          else dot.classList.add('hg-row-dot-amber');
        });
      }
    }
    if (typeof fireToast === 'function') fireToast('success', 'RDAP CHECK', 'Availability check complete for ' + domains.length + ' domains.');
  } catch (err) {
    if (typeof fireToast === 'function') fireToast('error', 'ERROR', err.message);
  } finally {
    btn.disabled = false;
    icon.style.display = '';
    spinner.style.display = 'none';
    label.textContent = 'CHECK RDAP';
  }
}
</script>

<style>
.homo-section { display: flex; flex-direction: column; gap: var(--space-5); }

/* ═══════════════════════════════════════════════════════════
   Panel — matches Creature Login's polished panel design
   ═══════════════════════════════════════════════════════════ */
.hg-panel {
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-xl, 16px);
  padding: var(--space-5);
  position: relative;
  overflow: hidden;
  transition: border-color .2s ease;
}
.hg-panel::before {
  content: '';
  position: absolute;
  top: 0; left: 10%; right: 10%;
  height: 1px;
  background: linear-gradient(90deg, transparent, var(--accent-primary), transparent);
  opacity: 0.5;
}
.hg-panel:hover { border-color: var(--border-default); }

.hg-panel-head {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  margin-bottom: var(--space-4);
  padding-bottom: var(--space-3);
  border-bottom: 1px solid var(--border-subtle);
}
.hg-panel-title {
  font-family: var(--font-display, var(--font-mono));
  font-size: var(--text-sm);
  font-weight: 600;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: var(--text-primary);
  margin: 0;
}
.hg-panel-title span { color: var(--accent-primary); }

.hg-panel-line {
  flex: 1;
  height: 1px;
  background: linear-gradient(90deg, var(--border-default), transparent);
}

.hg-panel-note {
  color: var(--text-secondary);
  font-size: var(--text-sm);
  line-height: var(--leading-normal, 1.6);
  margin: 0 0 var(--space-4);
}
.hg-panel-note code {
  font-family: var(--font-mono);
  background: var(--bg-raised);
  padding: 1px 6px;
  border-radius: 3px;
  color: var(--accent-primary);
  font-size: 0.92em;
}
.hg-panel-note strong { color: var(--text-primary); }

/* ── Input row ───────────────────────────────────────── */
.hg-input-row {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: var(--space-3);
  align-items: stretch;
}
.hg-input {
  background: var(--bg-raised);
  color: var(--text-primary);
  font-family: var(--font-mono);
  font-size: var(--text-sm);
  padding: 10px 14px;
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-md);
  outline: none;
  transition: border-color .15s, box-shadow .15s;
}
.hg-input:focus {
  border-color: var(--accent-primary);
  box-shadow: 0 0 0 2px rgba(0,229,255,0.15);
}
.hg-input::placeholder {
  color: var(--text-muted);
  opacity: 0.6;
}

/* ── Progress bar ────────────────────────────────────── */
.hg-progress { margin-top: var(--space-3); }
.hg-progress-meta {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: 6px;
}
.hg-progress-label {
  font-family: var(--font-mono);
  font-size: 10px; font-weight: 700;
  letter-spacing: .1em;
  color: var(--accent-primary);
}
.hg-progress-pct {
  font-family: var(--font-mono);
  font-size: 10px; font-weight: 700;
  color: var(--text-muted);
}
.hg-progress-track {
  height: 4px;
  background: var(--bg-raised);
  border-radius: 2px;
  overflow: hidden;
}
.hg-progress-fill {
  height: 100%;
  background: var(--accent-primary);
  border-radius: 2px;
  transition: width .4s ease;
  box-shadow: 0 0 8px rgba(0,229,255,0.4);
}

/* ── Filter chips ────────────────────────────────────── */
.hg-filters {
  display: flex; flex-wrap: wrap;
  gap: 6px;
  padding: 10px 2px 12px;
  border-bottom: 1px solid rgba(255,255,255,0.05);
  margin-bottom: 6px;
}
.hg-chip {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 5px 10px 5px 11px;
  font-family: var(--font-mono);
  font-size: 10.5px; font-weight: 700;
  letter-spacing: .07em;
  background: transparent;
  border: 1px solid rgba(255,255,255,0.08);
  color: var(--text-muted);
  border-radius: 14px;
  cursor: pointer;
  transition: color .12s, border-color .12s, background .12s;
}
.hg-chip:hover { color: var(--text-primary); border-color: rgba(255,255,255,0.18); }
.hg-chip-count {
  padding: 1px 7px;
  border-radius: 10px;
  background: rgba(255,255,255,0.06);
  font-size: 10px; color: inherit;
  min-width: 22px; text-align: center;
}
.hg-chip.is-active {
  color: var(--text-primary);
  border-color: currentColor;
  background: rgba(255,255,255,0.04);
}
.hg-chip.is-active.hg-cat-monoscript { color: var(--accent-purple, #b84dff); border-color: rgba(184,77,255,0.55); background: rgba(184,77,255,0.10); }
.hg-chip.is-active.hg-cat-mixed      { color: #ff6b9d; border-color: rgba(255,107,157,0.55); background: rgba(255,107,157,0.10); }
.hg-chip.is-active.hg-cat-diacritic  { color: var(--accent-amber, #ffa500); border-color: rgba(255,170,0,0.55); background: rgba(255,170,0,0.10); }
.hg-chip.is-active.hg-cat-typo       { color: var(--accent-primary); border-color: rgba(0,229,255,0.55); background: rgba(0,229,255,0.10); }
.hg-chip.is-active.hg-cat-tld        { color: var(--accent-info, #4488ff); border-color: rgba(68,136,255,0.55); background: rgba(68,136,255,0.10); }
.hg-chip.is-active[data-filter=""] {
  color: var(--text-primary);
  border-color: rgba(255,255,255,0.25);
  background: rgba(255,255,255,0.05);
}

/* ── Compact list ────────────────────────────────────── */
.hg-list {
  display: flex; flex-direction: column;
  gap: 2px;
  max-height: 62vh;
  overflow-y: auto;
  padding-right: 4px;
}
.hg-list::-webkit-scrollbar        { width: 8px; }
.hg-list::-webkit-scrollbar-track  { background: transparent; }
.hg-list::-webkit-scrollbar-thumb  { background: rgba(255,255,255,0.06); border-radius: 4px; }
.hg-list::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.12); }

.hg-list-empty {
  padding: 40px 0;
  text-align: center;
  color: var(--text-muted);
  font-family: var(--font-mono);
  font-size: 12px; letter-spacing: .05em;
}

/* Row — 6-column grid, ~40px tall */
.hg-row {
  display: grid;
  grid-template-columns: 12px auto minmax(0, 1fr) auto auto auto;
  align-items: center;
  gap: 12px;
  padding: 8px 14px;
  border-radius: 5px;
  background: transparent;
  border: 1px solid transparent;
  border-left: 3px solid transparent;
  min-height: 40px;
  transition: background .12s, border-color .12s;
}
.hg-row:hover {
  background: rgba(255,255,255,0.03);
  border-color: rgba(255,255,255,0.05);
}

/* Category left-border colors */
.hg-cat-monoscript-row { border-left-color: rgba(184,77,255,0.55); }
.hg-cat-mixed-row      { border-left-color: rgba(255,107,157,0.55); }
.hg-cat-diacritic-row  { border-left-color: rgba(255,170,0,0.55); }
.hg-cat-typo-row       { border-left-color: rgba(0,229,255,0.45); }
.hg-cat-tld-row        { border-left-color: rgba(68,136,255,0.45); }

/* Registrability dot */
.hg-row-dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  justify-self: center;
}
.hg-row-dot-unk   { background: rgba(255,255,255,0.12); }
.hg-row-dot-green { background: var(--accent-green, #39ff14); box-shadow: 0 0 6px rgba(57,255,20,0.5); }
.hg-row-dot-amber { background: var(--accent-amber, #ffa500); box-shadow: 0 0 6px rgba(255,165,0,0.5); }
.hg-row-dot-red   { background: var(--accent-red, #ff2d55); box-shadow: 0 0 6px rgba(255,45,85,0.5); }

/* Sub-category pill */
.hg-row-sub {
  font-family: var(--font-mono);
  font-size: 9.5px; font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
  padding: 3px 8px;
  border-radius: 3px;
  border: 1px solid currentColor;
  background: rgba(255,255,255,0.02);
  white-space: nowrap;
  color: var(--text-muted);
  min-width: 70px; text-align: center;
}
.hg-cat-monoscript-row .hg-row-sub { color: rgba(184,77,255,0.95); background: rgba(184,77,255,0.08); border-color: rgba(184,77,255,0.35); }
.hg-cat-mixed-row .hg-row-sub      { color: rgba(255,107,157,0.95); background: rgba(255,107,157,0.08); border-color: rgba(255,107,157,0.35); }
.hg-cat-diacritic-row .hg-row-sub  { color: rgba(255,170,0,0.95); background: rgba(255,170,0,0.08); border-color: rgba(255,170,0,0.35); }
.hg-cat-typo-row .hg-row-sub       { color: rgba(0,229,255,0.95); background: rgba(0,229,255,0.08); border-color: rgba(0,229,255,0.35); }
.hg-cat-tld-row .hg-row-sub        { color: rgba(68,136,255,0.95); background: rgba(68,136,255,0.08); border-color: rgba(68,136,255,0.35); }

/* Display domain */
.hg-display {
  font-family: var(--font-mono);
  font-size: 15px;
  color: var(--text-primary);
  font-weight: 600;
  letter-spacing: .02em;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
/* Punycode */
.hg-puny {
  font-family: var(--font-mono);
  font-size: 12px;
  color: var(--accent-primary);
  background: var(--bg-raised);
  padding: 3px 8px;
  border-radius: 3px;
  display: inline-block;
  max-width: 200px;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
/* Availability cell */
.hg-avail-cell {
  font-family: var(--font-mono);
  font-size: var(--text-xs);
  color: var(--text-muted);
  min-width: 70px; text-align: center;
}
.hg-avail {
  display: inline-block;
  padding: 2px 7px;
  font-family: var(--font-mono);
  font-size: 9px; font-weight: 700;
  letter-spacing: .1em;
  border-radius: 3px;
  border: 1px solid;
}
.hg-avail-yes { color: var(--accent-green, #39ff14); border-color: rgba(57,255,20,0.4); background: rgba(57,255,20,0.08); }
.hg-avail-no  { color: var(--accent-red, #ff2d55); border-color: rgba(255,45,85,0.4); background: rgba(255,45,85,0.08); }
.hg-avail-unk { color: var(--text-muted); border-color: rgba(255,255,255,0.1); background: rgba(255,255,255,0.03); }

/* Copy button */
.hg-btn-copy {
  padding: 5px 10px;
  font-family: var(--font-mono);
  font-size: 10px; font-weight: 700;
  letter-spacing: .08em;
  background: var(--bg-raised);
  border: 1px solid rgba(0,229,255,0.3);
  color: var(--accent-primary);
  border-radius: 3px;
  cursor: pointer;
  transition: all .15s;
}
.hg-btn-copy:hover {
  background: rgba(0,229,255,0.1);
  border-color: var(--accent-primary);
}

/* ── Empty state ─────────────────────────────────────── */
.homo-empty {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: var(--space-3);
  padding: var(--space-8) var(--space-5);
  text-align: center;
}
.homo-empty-icon {
  width: 56px; height: 56px;
  display: flex; align-items: center; justify-content: center;
  background: rgba(0,229,255,0.06);
  border: 1px solid rgba(0,229,255,0.20);
  border-radius: var(--radius-lg);
  color: var(--accent-primary);
}
.homo-empty-icon .icon { width: 28px; height: 28px; }
.homo-empty-title {
  color: var(--text-primary);
  font-family: var(--font-mono);
  font-size: var(--text-md); font-weight: 600;
  letter-spacing: .02em;
}
.homo-empty-sub {
  color: var(--text-muted);
  font-size: var(--text-sm);
  max-width: 400px; line-height: var(--leading-normal, 1.6);
}

/* ── Spinner ─────────────────────────────────────────── */
.hg-spinner {
  display: inline-block;
  width: 12px; height: 12px;
  border: 2px solid rgba(0,229,255,0.25);
  border-top-color: var(--accent-primary);
  border-radius: 50%;
  animation: hg-spin .7s linear infinite;
  vertical-align: -1px;
}
@keyframes hg-spin { to { transform: rotate(360deg); } }
</style>
