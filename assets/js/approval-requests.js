/**
 * RDOMMS Approval Requests — database-backed approval queue.
 */
(function () {
  const PAGE = window.RDOMMS_APPROVALS_PAGE || {};
  const API = PAGE.apiApprovals || '../api/approval-requests.php';

  let requests = [];
  let currentTab = 'all';
  let rejectTargetId = null;

  function $(id) { return document.getElementById(id); }

  function setLoading(active) {
    $('pageLoading')?.classList.toggle('hidden', !active);
  }

  function notify(msg, type) {
    if (window.RDOMMSToast) {
      RDOMMSToast.show(msg, type || 'info', { title: 'Approval Requests', duration: 4000, allowMultiple: true });
    } else {
      alert(msg);
    }
  }

  function escapeHtml(v) {
    return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function timeAgo(iso) {
    const diff = Date.now() - new Date(iso).getTime();
    const m = Math.floor(diff / 60000);
    if (m < 1) return 'Just now';
    if (m < 60) return `${m}m ago`;
    const h = Math.floor(m / 60);
    if (h < 24) return `${h}h ago`;
    return `${Math.floor(h / 24)}d ago`;
  }

  async function fetchRequests() {
    const response = await fetch(API, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to load approval requests');
    }
    return Array.isArray(payload.data) ? payload.data : [];
  }

  async function createRequest(data) {
    const response = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify(data)
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to submit request');
    }
  }

  async function updateRequest(id, action, rejectReason) {
    const response = await fetch(API, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({ id, action, rejectReason })
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to update request');
    }
  }

  async function deleteRequest(id) {
    const response = await fetch(`${API}?id=${encodeURIComponent(id)}`, {
      method: 'DELETE',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to delete request');
    }
  }

  function getFiltered() {
    const search = ($('searchInput')?.value || '').trim().toLowerCase();
    const module = $('filterModule')?.value || '';
    const priority = $('filterPriority')?.value || '';

    return requests.filter((r) => {
      if (currentTab !== 'all' && r.status !== currentTab) return false;
      if (module && r.module !== module) return false;
      if (priority && r.priority !== priority) return false;
      if (search && !`${r.title} ${r.requester}`.toLowerCase().includes(search)) return false;
      return true;
    });
  }

  function render() {
    const all = requests;
    $('countAll').textContent = String(all.length);
    $('countPending').textContent = String(all.filter((r) => r.status === 'pending').length);
    $('countApproved').textContent = String(all.filter((r) => r.status === 'approved').length);
    $('countRejected').textContent = String(all.filter((r) => r.status === 'rejected').length);
    $('pendingCount').textContent = String(all.filter((r) => r.status === 'pending').length);

    const rows = getFiltered();
    const list = $('requestList');
    const empty = $('emptyState');
    if (!rows.length) {
      if (list) list.innerHTML = '';
      if (empty) empty.style.display = '';
      return;
    }
    if (empty) empty.style.display = 'none';

    list.innerHTML = rows.map((r) => {
      const pending = r.status === 'pending';
      const actions = pending
        ? `<button type="button" class="btn btn-success btn-sm" data-action="approve" data-id="${escapeHtml(r.id)}"><i class="fas fa-check"></i> Approve</button>
           <button type="button" class="btn btn-danger btn-sm" data-action="reject" data-id="${escapeHtml(r.id)}"><i class="fas fa-times"></i> Reject</button>`
        : `<span class="muted">${escapeHtml(r.status)}</span>`;
      const deleteBtn = r.source === 'users'
        ? ''
        : `<button type="button" class="btn btn-light btn-sm" data-action="delete" data-id="${escapeHtml(r.id)}"><i class="fas fa-trash"></i></button>`;

      return `
        <div class="request-card">
          <div class="request-title">${escapeHtml(r.title)} <span class="badge">${escapeHtml(r.status)}</span></div>
          <div class="request-meta">${escapeHtml(r.requester)} · ${escapeHtml(r.type)} · ${escapeHtml(r.module)} · ${escapeHtml(timeAgo(r.createdAt))}</div>
          ${r.desc ? `<div class="request-desc">${escapeHtml(r.desc)}</div>` : ''}
          ${r.rejectReason ? `<div class="request-desc reject">${escapeHtml(r.rejectReason)}</div>` : ''}
          <div class="request-actions">${actions}${deleteBtn}</div>
        </div>`;
    }).join('');
  }

  async function refresh() {
    requests = await fetchRequests();
    render();
  }

  function bindUi() {
    document.querySelectorAll('.tab-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        currentTab = btn.dataset.tab || 'all';
        render();
      });
    });

    ['searchInput', 'filterModule', 'filterPriority'].forEach((id) => {
      $(id)?.addEventListener('input', render);
      $(id)?.addEventListener('change', render);
    });

    $('submitRequestBtn')?.addEventListener('click', async () => {
      const title = ($('newTitle')?.value || '').trim();
      const type = $('newType')?.value || '';
      const requester = ($('newRequester')?.value || '').trim();
      if (!title || !type || !requester) {
        notify('Fill title, type, and requester.', 'error');
        return;
      }
      setLoading(true);
      try {
        await createRequest({
          title,
          type,
          requester,
          module: $('newModule')?.value || 'General',
          priority: $('newPriority')?.value || 'medium',
          desc: ($('newDesc')?.value || '').trim()
        });
        ['newTitle', 'newRequester', 'newDesc'].forEach((id) => { if ($(id)) $(id).value = ''; });
        $('newType').value = '';
        await refresh();
        notify('Request submitted.', 'success');
      } catch (error) {
        notify(error.message, 'error');
      } finally {
        setLoading(false);
      }
    });

    $('requestList')?.addEventListener('click', async (e) => {
      const btn = e.target.closest('[data-action][data-id]');
      if (!btn) return;
      const { action, id } = btn.dataset;
      if (action === 'reject') {
        rejectTargetId = id;
        $('rejectReason').value = '';
        $('rejectModal')?.classList.add('open');
        return;
      }
      setLoading(true);
      try {
        if (action === 'approve') {
          await updateRequest(id, 'approve');
          notify('Request approved.', 'success');
        }
        if (action === 'delete') {
          if (!confirm('Delete this request?')) return;
          await deleteRequest(id);
          notify('Request deleted.', 'success');
        }
        await refresh();
      } catch (error) {
        notify(error.message, 'error');
      } finally {
        setLoading(false);
      }
    });

    $('cancelRejectBtn')?.addEventListener('click', () => {
      $('rejectModal')?.classList.remove('open');
      rejectTargetId = null;
    });

    $('confirmRejectBtn')?.addEventListener('click', async () => {
      const reason = ($('rejectReason')?.value || '').trim();
      if (!reason) {
        notify('Enter a reject reason.', 'error');
        return;
      }
      setLoading(true);
      try {
        await updateRequest(rejectTargetId, 'reject', reason);
        $('rejectModal')?.classList.remove('open');
        rejectTargetId = null;
        await refresh();
        notify('Request rejected.', 'success');
      } catch (error) {
        notify(error.message, 'error');
      } finally {
        setLoading(false);
      }
    });
  }

  async function boot() {
    bindUi();
    setLoading(true);
    try {
      await refresh();
    } catch (error) {
      notify(error.message || 'Unable to load requests.', 'error');
      requests = [];
      render();
    } finally {
      setLoading(false);
    }
  }

  document.addEventListener('DOMContentLoaded', boot);
})();
