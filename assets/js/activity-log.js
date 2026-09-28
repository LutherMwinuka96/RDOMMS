/**
 * RDOMMS Activity Log — database-backed audit trail.
 */
(function () {
  const PAGE = window.RDOMMS_ACTIVITY_LOG || {};
  const API = PAGE.apiActivityLog || '../api/activity-log.php';
  const IS_ADMIN = !!PAGE.isAdmin;
  const LOG_SCOPE = PAGE.scope || (IS_ADMIN ? 'all' : 'account');
  let PAGE_SIZE = 15;

  let logs = [];
  let currentPage = 1;
  let searchTimer = null;

  function $(id) {
    return document.getElementById(id);
  }

  function setLoading(active) {
    $('pageLoading')?.classList.toggle('hidden', !active);
  }

  function notify(msg, type) {
    if (window.RDOMMSToast) {
      RDOMMSToast.show(msg, type || 'info', {
        title: 'Activity Log',
        duration: 4000,
        allowMultiple: true
      });
    } else {
      alert(msg);
    }
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function getCurrentRole() {
    return String(window.RDOMMSRBAC?.getCurrentUser?.()?.role || '').toLowerCase();
  }

  function canClearLogs() {
    return IS_ADMIN || getCurrentRole() === 'admin';
  }

  function applyRoleUi() {
    const title = $('activityLogTitle');
    const subtitle = $('activityLogSubtitle');
    const allUsersLink = $('allUsersLink');

    if (IS_ADMIN) {
      if (title) title.textContent = 'Activity Log';
      if (subtitle) {
        subtitle.textContent = 'Audit trail of all user actions and system events across the platform.';
      }
      if (allUsersLink) allUsersLink.style.display = '';
    } else {
      if (title) title.textContent = 'My Activity Log';
      if (subtitle) {
        subtitle.textContent = 'Actions you have performed on your account, filtered to your role and user profile.';
      }
      if (allUsersLink) allUsersLink.style.display = 'none';
    }
  }

  function normalizeLog(row) {
    return {
      id: row.id,
      timestamp: row.created_at || row.timestamp || '',
      user: row.user_name || row.user || 'System',
      module: row.module || '—',
      action: row.action || '—',
      severity: row.severity || 'info',
      details: row.details || '—',
      ip: row.ip_address || '—'
    };
  }

  function buildQuery() {
    const params = new URLSearchParams();
    const search = ($('searchInput')?.value || '').trim();
    const module = $('moduleFilter')?.value || '';
    const severity = $('severityFilter')?.value || '';
    const start = $('fromDate')?.value || '';
    const end = $('toDate')?.value || '';

    if (search) params.set('search', search);
    if (module) params.set('module', module);
    if (severity) params.set('severity', severity);
    if (start) params.set('start_date', start);
    if (end) params.set('end_date', end);

    const query = params.toString();
    return query ? `${API}?${query}` : API;
  }

  async function fetchLogs() {
    const response = await fetch(buildQuery(), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to load activity logs');
    }
    return (Array.isArray(payload.data) ? payload.data : []).map(normalizeLog);
  }

  async function clearOldLogs() {
    const response = await fetch(`${API}?clear=all`, {
      method: 'DELETE',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to clear logs');
    }
    return payload;
  }

  function formatDateTime(value) {
    if (window.RDOMMSSettings && typeof RDOMMSSettings.formatDateTime === 'function') {
      return RDOMMSSettings.formatDateTime(value);
    }
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString('en-GB', {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    });
  }

  function severityBadge(severity) {
    const key = String(severity || 'info').toLowerCase();
    const cls = key === 'success'
      ? 'sev-success'
      : key === 'warning'
        ? 'sev-warning'
        : key === 'danger'
          ? 'sev-danger'
          : 'sev-info';
    const label = key === 'danger' ? 'Critical' : key.charAt(0).toUpperCase() + key.slice(1);
    const icon = key === 'success'
      ? 'fa-check-circle'
      : key === 'warning'
        ? 'fa-triangle-exclamation'
        : key === 'danger'
          ? 'fa-circle-xmark'
          : 'fa-circle-info';
    return `<span class="sev ${cls}"><i class="fas ${icon}"></i> ${label}</span>`;
  }

  function updateStats() {
    const startDay = new Date();
    startDay.setHours(0, 0, 0, 0);

    $('statTotal').textContent = String(logs.length);
    $('statToday').textContent = String(logs.filter((log) => new Date(log.timestamp) >= startDay).length);
    $('statWarn').textContent = String(logs.filter((log) => String(log.severity).toLowerCase() === 'warning').length);
    $('statCritical').textContent = String(logs.filter((log) => String(log.severity).toLowerCase() === 'danger').length);
  }

  function renderTable() {
    const totalPages = Math.max(1, Math.ceil(logs.length / PAGE_SIZE));
    if (currentPage > totalPages) currentPage = totalPages;

    const start = (currentPage - 1) * PAGE_SIZE;
    const pageRows = logs.slice(start, start + PAGE_SIZE);
    const tbody = $('logsBody');
    const empty = $('emptyState');

    if ($('countText')) {
      $('countText').textContent = `${logs.length} record${logs.length === 1 ? '' : 's'}`;
    }
    if ($('pagerMeta')) {
      $('pagerMeta').textContent = logs.length
        ? `Showing ${start + 1}–${Math.min(start + PAGE_SIZE, logs.length)} of ${logs.length}`
        : 'Showing 0–0';
    }

    if ($('prevBtn')) $('prevBtn').disabled = currentPage <= 1;
    if ($('nextBtn')) $('nextBtn').disabled = currentPage >= totalPages;

    if (!tbody) return;

    if (!pageRows.length) {
      tbody.innerHTML = '';
      empty?.classList.add('show');
      return;
    }

    empty?.classList.remove('show');
    tbody.innerHTML = pageRows.map((log) => `
      <tr>
        <td>${escapeHtml(formatDateTime(log.timestamp))}</td>
        <td>${escapeHtml(log.user)}</td>
        <td>${escapeHtml(log.module)}</td>
        <td>${escapeHtml(log.action)}</td>
        <td>${severityBadge(log.severity)}</td>
        <td>${escapeHtml(log.details)}</td>
        <td class="mono">${escapeHtml(log.ip)}</td>
      </tr>
    `).join('');

    updateStats();
  }

  async function reloadLogs() {
    setLoading(true);
    try {
      logs = await fetchLogs();
      renderTable();
    } catch (error) {
      notify(error.message || 'Unable to load activity logs.', 'error');
      logs = [];
      renderTable();
    } finally {
      setLoading(false);
    }
  }

  function scheduleReload() {
    currentPage = 1;
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      reloadLogs();
    }, 350);
  }

  function resetFilters() {
    if ($('searchInput')) $('searchInput').value = '';
    if ($('moduleFilter')) $('moduleFilter').value = '';
    if ($('severityFilter')) $('severityFilter').value = '';
    if ($('fromDate')) $('fromDate').value = '';
    if ($('toDate')) $('toDate').value = '';
    currentPage = 1;
    reloadLogs();
  }

  async function exportLogs() {
    if (!logs.length) {
      notify('No logs to export for current filters.', 'warning');
      return;
    }
    if (!window.RDOMMSExport) {
      notify('Export module not loaded.', 'error');
      return;
    }

    const format = $('exportFormat')?.value || 'pdf';
    const headers = ['Timestamp', 'User', 'Module', 'Action', 'Severity', 'Details', 'IP'];
    const rows = logs.map((log) => [
      log.timestamp || '',
      log.user || '',
      log.module || '',
      log.action || '',
      log.severity || '',
      log.details || '',
      log.ip || ''
    ]);

    try {
      await RDOMMSExport.exportData({
        format,
        title: 'Activity Log',
        subtitle: `Exported: ${new Date().toLocaleString()} | Records: ${logs.length}`,
        headers,
        rows,
        filename: `rdomms_activity_log_${new Date().toISOString().slice(0, 10)}`
      });
      notify('Activity log exported successfully.', 'success');
    } catch (error) {
      notify(error.message || 'Export failed.', 'error');
    }
  }

  async function handleClearLogs() {
    if (!canClearLogs()) {
      notify('Only administrators can clear old logs.', 'error');
      return;
    }
    if (!window.confirm('Clear activity logs older than 30 days? This cannot be undone.')) {
      return;
    }
    try {
      const result = await clearOldLogs();
      notify(result.message || 'Old logs cleared successfully.', 'success');
      await reloadLogs();
    } catch (error) {
      notify(error.message || 'Clear operation failed.', 'error');
    }
  }

  ['searchInput'].forEach((id) => {
    $(id)?.addEventListener('input', scheduleReload);
  });

  ['moduleFilter', 'severityFilter', 'fromDate', 'toDate'].forEach((id) => {
    $(id)?.addEventListener('change', () => {
      currentPage = 1;
      reloadLogs();
    });
  });

  $('resetBtn')?.addEventListener('click', resetFilters);
  $('exportBtn')?.addEventListener('click', exportLogs);
  $('clearBtn')?.addEventListener('click', handleClearLogs);

  $('prevBtn')?.addEventListener('click', () => {
    if (currentPage > 1) {
      currentPage -= 1;
      renderTable();
    }
  });

  $('nextBtn')?.addEventListener('click', () => {
    const totalPages = Math.max(1, Math.ceil(logs.length / PAGE_SIZE));
    if (currentPage < totalPages) {
      currentPage += 1;
      renderTable();
    }
  });

  async function initPage() {
    applyRoleUi();
    if (window.RDOMMSSettings) {
      try {
        await RDOMMSSettings.loadAuthenticated();
        PAGE_SIZE = RDOMMSSettings.getItemsPerPage();
      } catch (_) {}
    }
    await reloadLogs();
  }

  if (canClearLogs()) {
    const clearBtn = $('clearBtn');
    if (clearBtn) clearBtn.style.display = '';
  }

  initPage();
})();
