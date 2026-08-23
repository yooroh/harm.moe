/**
 * Admin Comments Manager — drop this into your admin panel JS
 *
 * Requires:
 *   - A container element: <div id="admin-comments"></div>
 *   - The profileId variable already defined (same as your existing admin panel)
 *   - Your existing admin session cookie (set by admin-auth.php)
 *
 * Usage: call loadAdminComments() when the admin panel loads.
 */

async function loadAdminComments() {
  const container = document.getElementById('admin-comments');
  if (!container) return;

  container.innerHTML = '<p style="opacity:.6">Loading comments…</p>';

  try {
    const res  = await fetch(`/comments.php?profile=${profileId}`);
    const data = await res.json();

    if (!data.comments || data.comments.length === 0) {
      container.innerHTML = '<p style="opacity:.6">No comments yet.</p>';
      return;
    }

    container.innerHTML = '';
    data.comments.forEach(c => renderAdminComment(container, c));
  } catch (e) {
    container.innerHTML = '<p style="color:red">Failed to load comments.</p>';
  }
}

function renderAdminComment(container, c) {
  const div = document.createElement('div');
  div.dataset.commentId = c.id;
  div.style.cssText = `
    display: flex; align-items: flex-start; gap: 10px;
    padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,.1);
    opacity: ${c.hidden ? '.4' : '1'};
  `;

  const date = new Date(c.posted_at * 1000).toLocaleString();

  div.innerHTML = `
    <div style="flex:1; min-width:0;">
      <div style="display:flex; gap:8px; align-items:baseline; flex-wrap:wrap;">
        <span style="font-weight:600">${c.username}</span>
        <span style="font-size:.75em; opacity:.5">${date}</span>
        ${c.hidden ? '<span style="font-size:.7em; color:#f59e0b; font-weight:600">HIDDEN</span>' : ''}
      </div>
      <div style="margin-top:4px; word-break:break-word">${c.message}</div>
    </div>
    <div style="display:flex; gap:6px; flex-shrink:0">
      <button
        onclick="toggleHideComment('${c.id}', ${c.hidden ? 0 : 1})"
        title="${c.hidden ? 'Unhide' : 'Hide'}"
        style="background:rgba(255,255,255,.1); border:none; border-radius:6px;
               padding:4px 10px; cursor:pointer; color:inherit; font-size:.8em">
        ${c.hidden ? '👁 Show' : '🙈 Hide'}
      </button>
      <button
        onclick="deleteComment('${c.id}')"
        title="Delete"
        style="background:rgba(220,38,38,.2); border:none; border-radius:6px;
               padding:4px 10px; cursor:pointer; color:inherit; font-size:.8em">
        🗑 Delete
      </button>
    </div>
  `;

  container.appendChild(div);
}

async function toggleHideComment(id, newHiddenState) {
  try {
    const res = await fetch(`/comments.php?profile=${profileId}`, {
      method:  'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ id, hidden: newHiddenState }),
    });
    const data = await res.json();
    if (data.ok) loadAdminComments();
    else alert('Error: ' + (data.error ?? 'unknown'));
  } catch (e) {
    alert('Failed to update comment.');
  }
}

async function deleteComment(id) {
  if (!confirm('Permanently delete this comment?')) return;
  try {
    const res = await fetch(`/comments.php?profile=${profileId}`, {
      method:  'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ id }),
    });
    const data = await res.json();
    if (data.ok) loadAdminComments();
    else alert('Error: ' + (data.error ?? 'unknown'));
  } catch (e) {
    alert('Failed to delete comment.');
  }
}

// Call this wherever you initialise your admin panel
// e.g. at the bottom of your admin panel init code:
// loadAdminComments();
