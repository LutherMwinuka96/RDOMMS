/**
 * RDOMMS System Alerts — database-backed alerts workspace.
 */
(function () {
  const PAGE = window.RDOMMS_ALERTS_PAGE || {};
  const API = PAGE.apiAlerts || '../api/notifications.php';
  const IS_ADMIN = !!PAGE.isAdmin;

  let alerts = [];
  let listScope = IS_ADMIN ? 'system' : 'account';

  function $(id) { return document.getElementById(id); }

  function setLoading(active) {
    $('pageLoading')?.classList.toggle('hidden', !active);
  }

  function notify(msg, type) {
    if (window.RDOMMSToast) {
      RDOMMSToast.show(msg, type || 'info', { title: 'System Alerts', duration: 4000, allowMultiple: true });
    } else {
      alert(msg);
    }
  }

  function escapeHtml(v) {
    return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function mapSeverity(value) {
    const s = String(value || 'info').toLowerCase();
    return s === 'danger' ? 'critical' : s;
  }

  function normalize(row) {
    return {
      id: row.notification_id || row.id || '',
      title: row.title || '',
      desc: row.message || row.desc || '',
      severity: mapSeverity(row.severity),
      module: row.module || 'System',
      read: !!(row.is_read || row.read),
      createdAt: row.created_at || row.createdAt || '',
      scope: row.scope || (row.user_id ? 'account' : 'system')
    };
  }

  function applyRoleUi() {
    const publishPanel = $('publishPanel');
    const layout = document.querySelector('.layout');
    const pageTitle = $('pageTitle');
    const pageSubtitle = $('pageSubtitle');
    const clearAllBtn = $('clearAllBtn');

    if (IS_ADMIN) {
      if (pageTitle) pageTitle.textContent = 'System Alerts';
      if (pageSubtitle) {
        pageSubtitle.textContent = 'Review every system alert across the platform, including maintenance, finance, and user events.';
      }
      publishPanel?.classList.remove('hidden');
      if (layout) layout.style.gridTemplateColumns = '';
    } else {
      if (pageTitle) pageTitle.textContent = 'My Alerts';
      if (pageSubtitle) {
        pageSubtitle.textContent = 'Notifications linked to your account, such as role changes, project updates, and approvals.';
      }
      publishPanel?.classList.add('hidden');
      if (layout) layout.style.gridTemplateColumns = '1fr';
    }

    if (clearAllBtn) {
      clearAllBtn.setAttribute('title', IS_ADMIN ? 'Clear all system alerts' : 'Clear my alerts');
    }
  }

  async function fetchAlerts() {
    const response = await fetch(API, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to load alerts');
    }
    if (payload.meta?.scope) {
      listScope = payload.meta.scope;
    }
    return (Array.isArray(payload.data) ? payload.data : []).map(normalize);
  }

  async function createAlert(data) {
    const response = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify(data)
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to create alert');
    }
  }

  async function markRead(id) {
    const response = await fetch(API, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({ id, read: true })
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to update alert');
    }
  }

  async function markAllRead() {
    const response = await fetch(API, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({ action: 'mark_all_read' })
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to mark alerts read');
    }
  }

  async function deleteAlert(id) {
    const response = await fetch(`${API}?id=${encodeURIComponent(id)}`, {
      method: 'DELETE',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to delete alert');
    }
  }

  async function clearAll() {
    const response = await fetch(`${API}?clear=all`, {
      method: 'DELETE',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to clear alerts');
    }
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

  function getFiltered() {
    const search = ($('searchInput')?.value || '').trim().toLowerCase();
    const severity = $('filterSeverity')?.value || '';
    const module = $('filterModule')?.value || '';
    const readFilter = $('filterRead')?.value || '';

    return alerts.filter((item) => {
      if (severity && item.severity !== severity) return false;
      if (module && item.module !== module) return false;
      if (readFilter === 'read' && !item.read) return false;
      if (readFilter === 'unread' && item.read) return false;
      if (!search) return true;
      return [item.title, item.desc, item.module].join(' ').toLowerCase().includes(search);
    });
  }

  function updateStats(list) {
    $('countCritical').textContent = String(list.filter((a) => a.severity === 'critical').length);
    $('countWarning').textContent = String(list.filter((a) => a.severity === 'warning').length);
    $('countInfo').textContent = String(list.filter((a) => a.severity === 'info').length);
    $('countResolved').textContent = String(list.filter((a) => a.severity === 'success').length);
    $('unreadCount').textContent = String(list.filter((a) => !a.read).length);
  }

  function render() {
    const rows = getFiltered();
    updateStats(alerts);
    const list = $('alertList');
    const empty = $('emptyState');
    if (!list) return;

    if (!rows.length) {
      list.innerHTML = '';
      if (empty) {
        empty.style.display = '';
        const emptyText = empty.querySelector('p');
        if (emptyText) {
          emptyText.textContent = alerts.length
            ? 'No alerts match your filters.'
            : (IS_ADMIN
              ? 'No system alerts have been recorded yet.'
              : 'You have no account notifications yet.');
        }
      }
      return;
    }
    if (empty) empty.style.display = 'none';

    list.innerHTML = rows.map((a) => `
      <div class="alert-row ${a.read ? '' : 'unread'}">
        <div class="alert-content">
          <div class="alert-title">${escapeHtml(a.title)}
            <span class="badge badge-${a.severity === 'critical' ? 'danger' : a.severity}">${escapeHtml(a.severity)}</span>
            <span class="badge badge-module">${escapeHtml(a.module)}</span>
            ${IS_ADMIN && a.scope === 'system' ? '<span class="badge badge-module">System-wide</span>' : ''}
          </div>
          <div class="alert-meta"><i class="fas fa-clock"></i> ${escapeHtml(timeAgo(a.createdAt))}${a.read ? ' · Read' : ' · Unread'}</div>
          ${a.desc ? `<div class="alert-desc">${escapeHtml(a.desc)}</div>` : ''}
        </div>
        <div class="alert-actions">
          ${!a.read ? `<button type="button" class="btn btn-light btn-sm" data-action="read" data-id="${escapeHtml(a.id)}"><i class="fas fa-check"></i></button>` : ''}
          <button type="button" class="btn btn-danger btn-sm" data-action="delete" data-id="${escapeHtml(a.id)}"><i class="fas fa-trash"></i></button>
        </div>
      </div>
    `).join('');
  }

  async function refresh() {
    alerts = await fetchAlerts();
    render();
  }

  function bindUi() {
    ['searchInput', 'filterSeverity', 'filterModule', 'filterRead'].forEach((id) => {
      $(id)?.addEventListener('input', render);
      $(id)?.addEventListener('change', render);
    });

    $('createAlertBtn')?.addEventListener('click', async () => {
      const title = ($('newTitle')?.value || '').trim();
      if (!title) {
        notify('Please enter an alert title.', 'error');
        return;
      }
      setLoading(true);
      try {
        await createAlert({
          title,
          severity: $('newSeverity')?.value || 'info',
          module: $('newModule')?.value || 'System',
          description: ($('newDesc')?.value || '').trim()
        });
        $('newTitle').value = '';
        $('newDesc').value = '';
        await refresh();
        notify('Alert published.', 'success');
      } catch (error) {
        notify(error.message, 'error');
      } finally {
        setLoading(false);
      }
    });

    $('markAllReadBtn')?.addEventListener('click', async () => {
      setLoading(true);
      try {
        await markAllRead();
        await refresh();
        notify('All alerts marked as read.', 'success');
      } catch (error) {
        notify(error.message, 'error');
      } finally {
        setLoading(false);
      }
    });

    $('clearAllBtn')?.addEventListener('click', async () => {
      const promptText = IS_ADMIN
        ? 'Clear every system alert from the platform?'
        : 'Clear all alerts linked to your account?';
      if (!confirm(promptText)) return;
      setLoading(true);
      try {
        await clearAll();
        await refresh();
        notify('All alerts cleared.', 'success');
      } catch (error) {
        notify(error.message, 'error');
      } finally {
        setLoading(false);
      }
    });

    $('alertList')?.addEventListener('click', async (e) => {
      const btn = e.target.closest('[data-action][data-id]');
      if (!btn) return;
      setLoading(true);
      try {
        if (btn.dataset.action === 'read') {
          await markRead(btn.dataset.id);
          await refresh();
        }
        if (btn.dataset.action === 'delete') {
          await deleteAlert(btn.dataset.id);
          await refresh();
          notify('Alert removed.', 'success');
        }
      } catch (error) {
        notify(error.message, 'error');
      } finally {
        setLoading(false);
      }
    });
  }

  async function boot() {
    applyRoleUi();
    bindUi();
    setLoading(true);
    try {
      await refresh();
    } catch (error) {
      notify(error.message || 'Unable to load alerts.', 'error');
      alerts = [];
      render();
    } finally {
      setLoading(false);
    }
  }

  document.addEventListener('DOMContentLoaded', boot);
})();
