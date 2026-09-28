<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/planning-access.php';
rdomms_require_planning_module_page();
require_once __DIR__ . '/../config/dates.php';

$currentYear = rdomms_current_year();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Progress Monitoring | RDO|MMS</title>
<meta name="description" content="Log and track project progress updates in RDO|MMS.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="../assets/css/rdomms-icons-offline.css">
<link rel="stylesheet" href="../assets/css/rdomms-session-countdown.css?v=4">
<link rel="stylesheet" href="../assets/css/project-nav.css?v=4">
<link rel="stylesheet" href="../assets/css/planning-pages.css">
<link rel="stylesheet" href="../assets/css/monthly-plans-workspace.css?v=1">
<link rel="stylesheet" href="../assets/css/progress-monitoring-workspace.css?v=1">
<link rel="stylesheet" href="../assets/css/rdomms-toast.css">
</head>
<body class="planning-page pm-progress">
<nav id="rdomms-planning-nav" data-active="progress-monitoring"></nav>

<div class="mp-shell">
  <header class="mp-head">
    <div>
      <h1>Progress Monitoring</h1>
      <p>Log activity progress for <?php echo (int) $currentYear; ?> — project, timeline, status, and notes.</p>
    </div>
    <div class="mp-stats" aria-live="polite">
      <span class="mp-stat"><strong id="statTotal">0</strong> updates</span>
      <span class="mp-stat"><strong id="statCompleted">0</strong> completed</span>
    </div>
  </header>

  <section class="mp-card" id="builderPanel">
    <div class="mp-card-body">
      <h2 style="margin:0 0 14px;font-size:1rem;color:var(--pl-green-900,#163120);">
        <span id="formTitle">New update</span>
        <span class="pm-editing-hint" id="editingHint">Editing</span>
      </h2>

      <form id="progressForm">
        <input type="hidden" id="editingId" value="">
        <div class="pm-form-grid">
          <div class="mp-field">
            <label for="projectName">Project <span class="req">*</span></label>
            <input id="projectName" type="text" list="projectOptions" placeholder="Project name" required>
            <datalist id="projectOptions"></datalist>
          </div>
          <div class="mp-field">
            <label for="activityName">Activity <span class="req">*</span></label>
            <input id="activityName" type="text" placeholder="Activity name" required>
          </div>
          <div class="mp-field">
            <label for="stationSelect">Center <span class="req">*</span></label>
            <select id="stationSelect" required>
              <option value="">Select center</option>
              <option value="rdo_mafinga">RDO Mafinga</option>
              <option value="rdo_mdabulo">RDO Mdabulo</option>
              <option value="rdo_ibwanzi">RDO Ibwanzi</option>
              <option value="rdo_kilolo">RDO Kilolo</option>
              <option value="rdo_malangali">RDO Malangali</option>
            </select>
          </div>
          <div class="mp-field">
            <label for="departmentSelect">Department <span class="req">*</span></label>
            <select id="departmentSelect" data-rdomms-department data-empty-label="Select department" required></select>
          </div>
          <div class="mp-field">
            <label for="startDate">Start <span class="req">*</span></label>
            <input id="startDate" type="date" required>
          </div>
          <div class="mp-field">
            <label for="endDate">End <span class="req">*</span></label>
            <input id="endDate" type="date" required>
          </div>
          <div class="mp-field">
            <label for="progressInput">Progress % <span class="req">*</span></label>
            <input id="progressInput" type="number" min="0" max="100" value="0" required>
            <div class="pm-progress-preview">
              <div class="pm-progress-bar"><div class="pm-progress-fill" id="progressFill"></div></div>
              <div class="pm-progress-meta"><span id="progressLabel">0%</span><span id="progressHint">Just started</span></div>
            </div>
          </div>
          <div class="mp-field">
            <label for="statusSelect">Status <span class="req">*</span></label>
            <select id="statusSelect" required>
              <option value="on-track">On track</option>
              <option value="at-risk">At risk</option>
              <option value="behind">Behind</option>
              <option value="completed">Completed</option>
            </select>
          </div>
          <div class="mp-field mp-field--full">
            <label for="descriptionInput">Progress note <span class="req">*</span></label>
            <textarea class="mp-input mp-textarea" id="descriptionInput" placeholder="What was done?" required></textarea>
          </div>
          <div class="mp-field mp-field--full">
            <label for="challengesInput">Challenges</label>
            <textarea class="mp-input mp-textarea" id="challengesInput" placeholder="Blockers or issues (optional)"></textarea>
          </div>
        </div>
        <div class="mp-btn-row">
          <button class="mp-btn mp-btn--primary" type="submit" id="saveBtn"><i class="fas fa-save"></i> Save update</button>
          <button class="mp-btn mp-btn--ghost" type="button" id="resetFormBtn"><i class="fas fa-rotate-left"></i> Reset</button>
        </div>
      </form>

      <div class="mp-status" id="statusMessage" role="status"></div>
    </div>
  </section>

  <section class="mp-card">
    <div class="mp-list-head">
      <h2><i class="fas fa-list"></i> Progress register</h2>
      <button class="mp-btn mp-btn--ghost" type="button" id="exportBtn"><i class="fas fa-file-export"></i> Export</button>
    </div>
    <div class="mp-card-body" style="padding-top:0;">
      <div class="pm-list-toolbar">
        <div class="pm-filters">
          <input type="search" id="searchInput" placeholder="Search…" aria-label="Search records">
          <select id="statusFilter" aria-label="Filter by status">
            <option value="all">All statuses</option>
            <option value="on-track">On track</option>
            <option value="at-risk">At risk</option>
            <option value="behind">Behind</option>
            <option value="completed">Completed</option>
          </select>
          <select id="stationFilter" aria-label="Filter by center">
            <option value="all">All centers</option>
            <option value="rdo_mafinga">RDO Mafinga</option>
            <option value="rdo_mdabulo">RDO Mdabulo</option>
            <option value="rdo_ibwanzi">RDO Ibwanzi</option>
            <option value="rdo_kilolo">RDO Kilolo</option>
            <option value="rdo_malangali">RDO Malangali</option>
          </select>
        </div>
      </div>
      <div class="mp-table-wrap">
        <table class="mp-table">
          <thead>
            <tr>
              <th>Project / Activity</th>
              <th>Progress</th>
              <th>Center</th>
              <th>Status</th>
              <th>Timeline</th>
              <th style="width:130px;"></th>
            </tr>
          </thead>
          <tbody id="tableBody">
            <tr><td colspan="6" class="mp-plans-empty">Loading…</td></tr>
          </tbody>
        </table>
      </div>
      <p class="pm-cell-sub" id="resultsCount" style="margin:10px 0 0;"></p>
    </div>
  </section>
</div>

<script src="../assets/js/rdomms-security.js"></script>
<script src="../assets/js/localStorage-service.js"></script>
<script src="../assets/js/rdomms-planning-api.js?v=2"></script>
<script src="../assets/js/rdomms-departments.js"></script>
<script src="../assets/js/rdomms-export.js"></script>
<script src="../assets/js/rdomms-toast.js"></script>
<script>
(function () {
  const SEED_ID_PATTERN = /^seed-/i;
  let updates = [];
  let apiReady = false;

  const progressForm = document.getElementById('progressForm');
  const editingId = document.getElementById('editingId');
  const formTitle = document.getElementById('formTitle');
  const editingHint = document.getElementById('editingHint');
  const saveBtn = document.getElementById('saveBtn');
  const tableBody = document.getElementById('tableBody');
  const resultsCount = document.getElementById('resultsCount');
  const statusMessage = document.getElementById('statusMessage');
  const statTotal = document.getElementById('statTotal');
  const statCompleted = document.getElementById('statCompleted');
  const progressInput = document.getElementById('progressInput');
  const progressFill = document.getElementById('progressFill');
  const progressLabel = document.getElementById('progressLabel');
  const progressHint = document.getElementById('progressHint');

  const fields = {
    projectName: document.getElementById('projectName'),
    activityName: document.getElementById('activityName'),
    stationSelect: document.getElementById('stationSelect'),
    departmentSelect: document.getElementById('departmentSelect'),
    startDate: document.getElementById('startDate'),
    endDate: document.getElementById('endDate'),
    progress: progressInput,
    status: document.getElementById('statusSelect'),
    description: document.getElementById('descriptionInput'),
    challenges: document.getElementById('challengesInput')
  };

  const filters = { search: '', status: 'all', station: 'all' };

  function uid(prefix) {
    if (window.crypto?.randomUUID) return `${prefix}-${window.crypto.randomUUID()}`;
    return `${prefix}-${Date.now()}-${Math.random().toString(16).slice(2, 8)}`;
  }

  function todayISO() {
    return new Date().toISOString().slice(0, 10);
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function notify(message, type = 'info') {
    if (window.RDOMMSToast) {
      RDOMMSToast.show(message, type === 'error' ? 'error' : type === 'warning' ? 'warning' : 'success', {
        title: 'Progress Monitoring',
        duration: type === 'error' ? 6000 : 3500,
        allowMultiple: true
      });
    }
    statusMessage.className = 'mp-status is-show mp-status--' + (type === 'error' ? 'err' : type === 'warning' ? 'warn' : 'ok');
    statusMessage.textContent = message;
  }

  function stationLabel(value) {
    const map = {
      rdo_mafinga: 'RDO Mafinga', mafinga: 'RDO Mafinga',
      rdo_mdabulo: 'RDO Mdabulo', mdabulo: 'RDO Mdabulo',
      rdo_ibwanzi: 'RDO Ibwanzi', ibwanzi: 'RDO Ibwanzi',
      rdo_kilolo: 'RDO Kilolo', kilolo: 'RDO Kilolo',
      rdo_malangali: 'RDO Malangali', malangali: 'RDO Malangali'
    };
    return map[value] || String(value || '—').replace(/^rdo_/i, 'RDO ').replace(/_/g, ' ');
  }

  function departmentLabel(value) {
    if (window.RDOMMSDepartments) return RDOMMSDepartments.getLabel(value) || value || '—';
    return value || '—';
  }

  function formatDate(isoDate) {
    if (!isoDate) return '—';
    return new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
      .format(new Date(isoDate + 'T00:00:00'));
  }

  function statusLabel(status) {
    const map = { 'on-track': 'On track', 'at-risk': 'At risk', behind: 'Behind', completed: 'Completed' };
    return map[status] || status || '—';
  }

  function statusBadgeClass(status) {
    const map = {
      'on-track': 'pm-badge--on-track',
      'at-risk': 'pm-badge--at-risk',
      behind: 'pm-badge--behind',
      completed: 'pm-badge--completed'
    };
    return map[status] || 'pm-badge--on-track';
  }

  function progressStage(progress) {
    if (progress >= 100) return 'Done';
    if (progress >= 75) return 'Near complete';
    if (progress >= 50) return 'Halfway';
    if (progress >= 25) return 'Started';
    return 'Just started';
  }

  function getStatusByProgress(progress) {
    if (progress >= 100) return 'completed';
    if (progress >= 75) return 'on-track';
    if (progress >= 50) return 'at-risk';
    return 'behind';
  }

  function normalizeStation(value) {
    const v = String(value || '').trim();
    const legacy = {
      mafinga: 'rdo_mafinga', mdabulo: 'rdo_mdabulo', ibwanzi: 'rdo_ibwanzi',
      kilolo: 'rdo_kilolo', malangali: 'rdo_malangali'
    };
    return legacy[v] || v;
  }

  function normalize(item) {
    const progress = Math.max(0, Math.min(100, parseInt(item.progress || 0, 10) || 0));
    return {
      id: item.id || uid('progress'),
      projectName: (item.projectName || '').trim(),
      activityName: (item.activityName || '').trim(),
      station: normalizeStation(item.station),
      department: item.department || '',
      startDate: item.startDate || '',
      endDate: item.endDate || '',
      progress,
      status: item.status || getStatusByProgress(progress),
      description: item.description || '',
      challenges: item.challenges || '',
      updatedAt: item.updatedAt || new Date().toISOString()
    };
  }

  function isLegacySeed(item) {
    return SEED_ID_PATTERN.test(String(item?.id || ''));
  }

  function updateProgressPreview() {
    const value = Math.max(0, Math.min(100, parseInt(progressInput.value || '0', 10) || 0));
    progressFill.style.width = `${value}%`;
    progressLabel.textContent = `${value}%`;
    progressHint.textContent = progressStage(value);
    if (!editingId.value) fields.status.value = getStatusByProgress(value);
  }

  function updateStats() {
    statTotal.textContent = updates.length;
    statCompleted.textContent = updates.filter((u) => u.status === 'completed').length;
  }

  function filteredUpdates() {
    const needle = filters.search.trim().toLowerCase();
    return updates
      .filter((item) => {
        if (filters.status !== 'all' && item.status !== filters.status) return false;
        if (filters.station !== 'all' && item.station !== filters.station) return false;
        if (!needle) return true;
        const hay = [
          item.projectName, item.activityName, stationLabel(item.station),
          departmentLabel(item.department), item.description, item.challenges, statusLabel(item.status)
        ].join(' ').toLowerCase();
        return hay.includes(needle);
      })
      .sort((a, b) => new Date(b.updatedAt) - new Date(a.updatedAt));
  }

  function renderTable() {
    const rows = filteredUpdates();
    resultsCount.textContent = `${rows.length} record${rows.length === 1 ? '' : 's'}`;

    if (!rows.length) {
      tableBody.innerHTML = '<tr><td colspan="6" class="mp-plans-empty">No records yet. Save your first update above.</td></tr>';
      return;
    }

    tableBody.innerHTML = rows.map((item) => {
      const id = escapeHtml(item.id);
      return `
        <tr>
          <td>
            <strong>${escapeHtml(item.projectName)}</strong>
            <div class="pm-cell-sub">${escapeHtml(item.activityName)}</div>
            <div class="pm-cell-sub">${escapeHtml(item.description)}</div>
          </td>
          <td>
            <div class="pm-progress-bar" style="min-width:80px;"><div class="pm-progress-fill" style="width:${item.progress}%"></div></div>
            <div class="pm-cell-sub">${item.progress}% · ${progressStage(item.progress)}</div>
          </td>
          <td>${escapeHtml(stationLabel(item.station))}</td>
          <td><span class="pm-badge ${statusBadgeClass(item.status)}">${escapeHtml(statusLabel(item.status))}</span></td>
          <td>
            <div class="pm-cell-sub">${formatDate(item.startDate)} – ${formatDate(item.endDate)}</div>
          </td>
          <td>
            <div class="mp-plan-actions">
              <button type="button" class="mp-btn mp-btn--ghost" data-action="edit" data-id="${id}" title="Edit"><i class="fas fa-pen"></i></button>
              <button type="button" class="mp-btn mp-btn--ghost" data-action="complete" data-id="${id}" title="Complete"><i class="fas fa-check"></i></button>
              <button type="button" class="mp-btn mp-btn--ghost mp-btn--danger" data-action="delete" data-id="${id}" title="Delete"><i class="fas fa-trash"></i></button>
            </div>
          </td>
        </tr>
      `;
    }).join('');
  }

  function refreshUI() {
    updateStats();
    renderTable();
  }

  function resetForm(silent) {
    progressForm.reset();
    editingId.value = '';
    formTitle.textContent = 'New update';
    editingHint.classList.remove('is-show');
    saveBtn.innerHTML = '<i class="fas fa-save"></i> Save update';
    fields.startDate.value = todayISO();
    fields.endDate.value = todayISO();
    fields.status.value = 'on-track';
    progressInput.value = '0';
    initProfileDefaults();
    updateProgressPreview();
    if (!silent) notify('Form reset.', 'info');
  }

  function fillForm(doc) {
    editingId.value = doc.id;
    fields.projectName.value = doc.projectName;
    fields.activityName.value = doc.activityName;
    fields.stationSelect.value = doc.station;
    setDepartmentValue(doc.department);
    fields.startDate.value = doc.startDate;
    fields.endDate.value = doc.endDate;
    fields.progress.value = doc.progress;
    fields.status.value = doc.status;
    fields.description.value = doc.description;
    fields.challenges.value = doc.challenges || '';
    formTitle.textContent = 'Edit update';
    editingHint.classList.add('is-show');
    saveBtn.innerHTML = '<i class="fas fa-save"></i> Update';
    updateProgressPreview();
    document.getElementById('builderPanel')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function validatePayload(payload) {
    const issues = [];
    if (!payload.projectName) issues.push('Project is required.');
    if (!payload.activityName) issues.push('Activity is required.');
    if (!payload.station) issues.push('Select a center.');
    if (!payload.department) issues.push('Select a department.');
    if (!payload.startDate || !payload.endDate) issues.push('Start and end dates are required.');
    if (payload.startDate > payload.endDate) issues.push('End date must be on or after start date.');
    if (!payload.description) issues.push('Progress note is required.');
    return issues;
  }

  function readFormPayload() {
    return normalize({
      id: editingId.value || uid('progress'),
      projectName: fields.projectName.value.trim(),
      activityName: fields.activityName.value.trim(),
      station: fields.stationSelect.value,
      department: fields.departmentSelect.value,
      startDate: fields.startDate.value,
      endDate: fields.endDate.value,
      progress: fields.progress.value,
      status: fields.status.value,
      description: fields.description.value.trim(),
      challenges: fields.challengesInput.value.trim(),
      updatedAt: new Date().toISOString()
    });
  }

  async function loadUpdates() {
    if (!window.RDOMMSPlanningAPI) return;
    const rows = await RDOMMSPlanningAPI.progress.list();
    updates = rows.filter((item) => !isLegacySeed(item)).map(normalize)
      .filter((item) => item.projectName && item.activityName);
    apiReady = true;
  }

  async function saveRecord(record) {
    return RDOMMSPlanningAPI.progress.save(record);
  }

  function ensureDepartmentsReady() {
    if (!fields.departmentSelect || !window.RDOMMSDepartments) return;
    RDOMMSDepartments.fillSelect(fields.departmentSelect, {
      emptyLabel: 'Select department',
      selectedValue: fields.departmentSelect.value || ''
    });
  }

  function setDepartmentValue(value) {
    ensureDepartmentsReady();
    if (!value || !window.RDOMMSDepartments) return;
    fields.departmentSelect.value = RDOMMSDepartments.getValue(value);
  }

  function initProfileDefaults() {
    try {
      const user = JSON.parse(localStorage.getItem('currentUser') || '{}');
      if (!fields.departmentSelect.value && user.department) setDepartmentValue(user.department);
    } catch (e) { /* ignore */ }
  }

  async function loadProjectOptions() {
    const datalist = document.getElementById('projectOptions');
    if (!datalist) return;
    const names = new Set();
    try {
      const stored = JSON.parse(localStorage.getItem('rd_projects') || '[]');
      if (Array.isArray(stored)) {
        stored.forEach((p) => {
          const name = (p.name || p.project_name || '').trim();
          if (name) names.add(name);
        });
      }
    } catch (e) { /* ignore */ }
    try {
      const res = await fetch('../api/projects.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const payload = await res.json();
      if (payload.status === 'success' && Array.isArray(payload.data)) {
        payload.data.forEach((p) => {
          const name = (p.name || p.project_name || '').trim();
          if (name) names.add(name);
        });
      }
    } catch (e) { /* ignore */ }
    datalist.innerHTML = Array.from(names).sort().map((n) => `<option value="${escapeHtml(n)}">`).join('');
  }

  async function applyComplete(id) {
    const item = updates.find((u) => u.id === id);
    if (!item || !window.RDOMMSPlanningAPI) return;
    try {
      const saved = await saveRecord(normalize({ ...item, status: 'completed', progress: 100, updatedAt: new Date().toISOString() }));
      const idx = updates.findIndex((u) => u.id === id);
      if (idx >= 0) updates[idx] = normalize(saved);
      refreshUI();
      notify('Marked as completed.', 'success');
    } catch (error) {
      notify(error.message || 'Update failed.', 'error');
    }
  }

  async function deleteRecord(id) {
    const item = updates.find((u) => u.id === id);
    if (!item || !confirm(`Delete "${item.projectName}"?`)) return;
    try {
      if (window.RDOMMSPlanningAPI) await RDOMMSPlanningAPI.progress.delete(id);
      updates = updates.filter((u) => u.id !== id);
      if (editingId.value === id) resetForm(true);
      refreshUI();
      notify('Record deleted.', 'success');
    } catch (error) {
      notify(error.message || 'Delete failed.', 'error');
    }
  }

  function exportRecords() {
    const rows = filteredUpdates();
    if (!rows.length) {
      notify('No records to export.', 'warning');
      return;
    }
    if (!window.RDOMMSExport) {
      notify('Export module not loaded.', 'error');
      return;
    }
    const headers = ['Project', 'Activity', 'Center', 'Department', 'Start', 'End', 'Progress', 'Status', 'Note'];
    const dataRows = rows.map((item) => [
      item.projectName, item.activityName, stationLabel(item.station), departmentLabel(item.department),
      item.startDate, item.endDate, `${item.progress}%`, statusLabel(item.status), item.description
    ]);
    RDOMMSExport.exportData({
      format: 'pdf',
      title: 'Progress Monitoring',
      subtitle: `Exported ${todayISO()} · ${rows.length} records`,
      headers,
      rows: dataRows,
      filename: `progress-monitoring-${todayISO()}`
    }).then(() => notify('Exported as PDF.', 'success'))
      .catch((error) => notify(error.message || 'Export failed.', 'error'));
  }

  progressForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!window.RDOMMSPlanningAPI) {
      notify('Planning API not loaded.', 'error');
      return;
    }
    const payload = readFormPayload();
    const issues = validatePayload(payload);
    if (issues.length) {
      notify(issues[0], 'error');
      return;
    }
    try {
      const wasEdit = !!editingId.value;
      const saved = await saveRecord(payload);
      const normalized = normalize(saved);
      const idx = updates.findIndex((u) => u.id === normalized.id);
      if (idx >= 0) updates[idx] = normalized;
      else updates.unshift(normalized);
      resetForm(true);
      refreshUI();
      notify(wasEdit ? 'Update saved.' : 'Progress saved.', 'success');
    } catch (error) {
      notify(error.message || 'Save failed.', 'error');
    }
  });

  progressInput.addEventListener('input', updateProgressPreview);
  document.getElementById('resetFormBtn').addEventListener('click', () => resetForm(false));
  document.getElementById('exportBtn').addEventListener('click', exportRecords);
  document.getElementById('searchInput').addEventListener('input', (e) => {
    filters.search = e.target.value;
    renderTable();
  });
  document.getElementById('statusFilter').addEventListener('change', (e) => {
    filters.status = e.target.value;
    renderTable();
  });
  document.getElementById('stationFilter').addEventListener('change', (e) => {
    filters.station = e.target.value;
    renderTable();
  });

  tableBody.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-action]');
    if (!btn) return;
    const id = btn.getAttribute('data-id');
    const action = btn.getAttribute('data-action');
    const item = updates.find((u) => u.id === id);
    if (!item) return;
    if (action === 'edit') fillForm(item);
    if (action === 'complete') applyComplete(id);
    if (action === 'delete') deleteRecord(id);
  });

  async function bootstrap() {
    ensureDepartmentsReady();
    fields.startDate.value = todayISO();
    fields.endDate.value = todayISO();
    updateProgressPreview();
    initProfileDefaults();
    await loadProjectOptions();
    if (window.RDOMMSPlanningAPI) {
      try {
        await RDOMMSPlanningAPI.progress.migrate();
        await loadUpdates();
      } catch (error) {
        notify(RDOMMSPlanningAPI.formatLoadError(error), 'warning');
      }
    }
    refreshUI();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bootstrap);
  else bootstrap();
})();
</script>
<script src="../assets/js/rpn-nav-shared.js?v=2"></script>
<script src="../assets/js/planning-nav.js?v=10"></script>
</body>
</html>
