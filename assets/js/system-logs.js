/**
 * RDOMMS System Logs — database-backed system audit trail.
 */
(function () {
  const PAGE = window.RDOMMS_SYSTEM_LOGS || {};
  const API = PAGE.apiSystemLogs || '../api/activity-log.php';
  let PAGE_SIZE = 15;

  let logs = [];
  let stats = { total: 0, today: 0, warnings: 0, critical: 0 };
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
        title: 'System Logs',
        duration: 4500,
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
    return getCurrentRole() === 'admin';
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
    params.set('scope', 'system');
    params.set('include_stats', '1');

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

    return `${API}?${params.toString()}`;
  }

  async function fetchLogs() {
    const response = await fetch(buildQuery(), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      const fallback = response.status === 401
        ? 'Please sign in again to view system logs.'
        : response.status === 403
          ? 'You do not have permission to view system logs.'
          : 'Failed to load system logs';
      throw new Error(payload?.message || fallback);
    }

    if (payload.stats) {
      stats = payload.stats;
    }

    populateModuleFilter(payload.modules || []);
    return (Array.isArray(payload.data) ? payload.data : []).map(normalizeLog);
  }

  function populateModuleFilter(modules) {
    const select = $('moduleFilter');
    if (!select || select.dataset.ready === '1') return;

    const current = select.value;
    select.innerHTML = '<option value="">All System Modules</option>';
    modules.forEach((module) => {
      const option = document.createElement('option');
      option.value = module;
      option.textContent = module;
      select.appendChild(option);
    });
    if (current) select.value = current;
    select.dataset.ready = '1';
  }

  async function clearOldLogs() {
    const response = await fetch(`${API}?clear=system_old`, {
      method: 'DELETE',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to clear system logs');
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
    $('statTotal').textContent = String(stats.total ?? logs.length);
    $('statToday').textContent = String(stats.today ?? 0);
    $('statWarn').textContent = String(stats.warnings ?? 0);
    $('statCritical').textContent = String(stats.critical ?? 0);
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
      updateStats();
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
      notify(error.message || 'Unable to load system logs.', 'error');
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
      notify('No system logs to export for current filters.', 'warning');
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
        title: 'System Logs',
        subtitle: `Exported: ${new Date().toLocaleString()} | Records: ${logs.length}`,
        headers,
        rows,
        filename: `rdomms_system_logs_${new Date().toISOString().slice(0, 10)}`
      });
      notify('System logs exported successfully.', 'success');
    } catch (error) {
      notify(error.message || 'Export failed.', 'error');
    }
  }

  async function handleClearLogs() {
    if (!canClearLogs()) {
      notify('Only administrators can clear old system logs.', 'error');
      return;
    }
    if (!window.confirm('Clear system logs older than 30 days? This cannot be undone.')) {
      return;
    }
    try {
      const result = await clearOldLogs();
      notify(result.message || 'Old system logs cleared successfully.', 'success');
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
