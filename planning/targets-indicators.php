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
<title>Targets &amp; Indicators | RDO|MMS</title>
<meta name="description" content="Set KPI targets and update progress in RDO|MMS.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="../assets/css/rdomms-icons-offline.css">
<link rel="stylesheet" href="../assets/css/rdomms-session-countdown.css?v=4">
<link rel="stylesheet" href="../assets/css/project-nav.css?v=4">
<link rel="stylesheet" href="../assets/css/planning-pages.css">
<link rel="stylesheet" href="../assets/css/monthly-plans-workspace.css?v=1">
<link rel="stylesheet" href="../assets/css/targets-indicators-workspace.css?v=1">
<link rel="stylesheet" href="../assets/css/rdomms-toast.css">
</head>
<body class="planning-page ti-targets">
<nav id="rdomms-planning-nav" data-active="targets-indicators"></nav>

<div class="mp-shell">
  <header class="mp-head">
    <div>
      <h1>Targets &amp; Indicators</h1>
      <p>Define KPIs for <?php echo (int) $currentYear; ?>, track current values, and monitor achievement.</p>
    </div>
    <div class="mp-stats" aria-live="polite">
      <span class="mp-stat"><strong id="statTotal">0</strong> targets</span>
      <span class="mp-stat"><strong id="statOverall">0%</strong> avg progress</span>
    </div>
  </header>

  <section class="mp-card" id="builderPanel">
    <div class="mp-card-body">
      <div class="ti-split">
        <div class="ti-subcard">
          <h3><span id="formTitle">New target</span> <span class="ti-editing-hint" id="editingHint">Editing</span></h3>
          <div class="mp-context">
            <div class="mp-field" style="grid-column:1/-1;">
              <label for="targetName">Name <span class="req">*</span></label>
              <input id="targetName" type="text" placeholder="e.g. Beneficiaries served">
            </div>
            <div class="mp-field">
              <label for="targetCategory">Category <span class="req">*</span></label>
              <select id="targetCategory">
                <option value="">Select</option>
                <option value="beneficiaries">Beneficiaries</option>
                <option value="projects">Projects</option>
                <option value="financial">Financial</option>
                <option value="operational">Operational</option>
                <option value="training">Training</option>
                <option value="outreach">Outreach</option>
              </select>
            </div>
            <div class="mp-field">
              <label for="targetPeriod">Period <span class="req">*</span></label>
              <select id="targetPeriod">
                <option value="monthly" selected>Monthly</option>
                <option value="quarterly">Quarterly</option>
                <option value="annually">Annually</option>
              </select>
            </div>
            <div class="mp-field">
              <label for="targetValue">Target value <span class="req">*</span></label>
              <input id="targetValue" type="number" min="0" step="any" placeholder="100">
            </div>
            <div class="mp-field">
              <label for="targetUnit">Unit <span class="req">*</span></label>
              <input id="targetUnit" type="text" placeholder="people, TZS…">
            </div>
            <div class="mp-field" style="grid-column:1/-1;">
              <label for="targetDescription">Description</label>
              <input id="targetDescription" type="text" placeholder="How this will be measured">
            </div>
          </div>
          <div class="mp-btn-row">
            <button class="mp-btn mp-btn--primary" id="btnSaveTarget" type="button"><i class="fas fa-plus"></i> Add target</button>
            <button class="mp-btn mp-btn--ghost" id="btnCancelEdit" type="button" style="display:none;"><i class="fas fa-times"></i> Cancel</button>
          </div>
        </div>

        <div class="ti-subcard">
          <h3>Update progress</h3>
          <div class="mp-field">
            <label for="progressTargetSelect">Target <span class="req">*</span></label>
            <select id="progressTargetSelect">
              <option value="">Select target</option>
            </select>
          </div>
          <div class="mp-field">
            <label for="currentValue">Current value <span class="req">*</span></label>
            <input id="currentValue" type="number" min="0" step="any" placeholder="0">
          </div>
          <div class="mp-field">
            <label for="progressNotes">Notes</label>
            <input id="progressNotes" type="text" placeholder="Optional update notes">
          </div>
          <button class="mp-btn mp-btn--ghost" id="btnUpdateProgress" type="button"><i class="fas fa-chart-line"></i> Save progress</button>
        </div>
      </div>

      <div class="mp-status" id="statusMessage" role="status"></div>
    </div>
  </section>

  <section class="mp-card">
    <div class="mp-list-head">
      <h2><i class="fas fa-bullseye"></i> Active targets</h2>
      <button class="mp-btn mp-btn--ghost" type="button" id="exportBtn"><i class="fas fa-file-export"></i> Export</button>
    </div>
    <div class="mp-card-body" style="padding-top:0;">
      <div class="ti-list-toolbar">
        <div class="ti-filters">
          <input type="search" id="searchInput" placeholder="Search targets…" aria-label="Search targets">
          <select id="filterCategory" aria-label="Filter by category">
            <option value="">All categories</option>
            <option value="beneficiaries">Beneficiaries</option>
            <option value="projects">Projects</option>
            <option value="financial">Financial</option>
            <option value="operational">Operational</option>
            <option value="training">Training</option>
            <option value="outreach">Outreach</option>
          </select>
        </div>
      </div>
      <div class="mp-table-wrap">
        <table class="mp-table">
          <thead>
            <tr>
              <th>Target</th>
              <th>Category</th>
              <th>Period</th>
              <th>Goal</th>
              <th>Current</th>
              <th>Progress</th>
              <th>Status</th>
              <th style="width:140px;"></th>
            </tr>
          </thead>
          <tbody id="targetsTableBody">
            <tr><td colspan="8" class="mp-plans-empty">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</div>

<script src="../assets/js/rdomms-security.js"></script>
<script src="../assets/js/localStorage-service.js"></script>
<script src="../assets/js/rdomms-planning-api.js?v=2"></script>
<script src="../assets/js/rdomms-export.js"></script>
<script src="../assets/js/rdomms-toast.js"></script>
<script>
(function () {
  let targets = [];
  let editingId = null;
  let apiReady = false;

  const statusMessage = document.getElementById('statusMessage');
  const formTitle = document.getElementById('formTitle');
  const editingHint = document.getElementById('editingHint');
  const btnSaveTarget = document.getElementById('btnSaveTarget');
  const btnCancelEdit = document.getElementById('btnCancelEdit');
  const statTotal = document.getElementById('statTotal');
  const statOverall = document.getElementById('statOverall');

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function notify(msg, type = 'info') {
    if (window.RDOMMSToast) {
      RDOMMSToast.show(msg, type === 'error' ? 'error' : type === 'warning' ? 'warning' : 'success', {
        title: 'Targets & Indicators',
        duration: type === 'error' ? 6000 : 3500,
        allowMultiple: true
      });
    }
    statusMessage.className = 'mp-status is-show mp-status--' + (type === 'error' ? 'err' : type === 'warning' ? 'warn' : 'ok');
    statusMessage.textContent = msg;
  }

  function getProgress(target) {
    const goal = Number(target.targetValue || target.target_value || 0);
    const current = Number(target.currentValue || target.current_value || 0);
    return goal > 0 ? (current / goal) * 100 : 0;
  }

  function getTargetStatus(progress) {
    if (progress >= 100) return 'Achieved';
    if (progress >= 75) return 'On Track';
    if (progress >= 50) return 'In Progress';
    if (progress >= 25) return 'Behind';
    return 'Not Started';
  }

  function statusBadgeClass(status) {
    const map = {
      Achieved: 'ti-badge--achieved',
      'On Track': 'ti-badge--on-track',
      'In Progress': 'ti-badge--in-progress',
      Behind: 'ti-badge--behind',
      'Not Started': 'ti-badge--not-started'
    };
    return map[status] || 'ti-badge--not-started';
  }

  function clearForm() {
    document.getElementById('targetName').value = '';
    document.getElementById('targetCategory').value = '';
    document.getElementById('targetPeriod').value = 'monthly';
    document.getElementById('targetValue').value = '';
    document.getElementById('targetUnit').value = '';
    document.getElementById('targetDescription').value = '';
  }

  function setEditMode(id) {
    editingId = id;
    formTitle.textContent = 'Edit target';
    btnSaveTarget.innerHTML = '<i class="fas fa-save"></i> Save changes';
    btnCancelEdit.style.display = 'inline-flex';
    editingHint.classList.add('is-show');
  }

  function cancelEdit() {
    editingId = null;
    clearForm();
    formTitle.textContent = 'New target';
    btnSaveTarget.innerHTML = '<i class="fas fa-plus"></i> Add target';
    btnCancelEdit.style.display = 'none';
    editingHint.classList.remove('is-show');
  }

  async function loadTargets() {
    if (!window.RDOMMSPlanningAPI) return;
    targets = await RDOMMSPlanningAPI.targets.list();
    apiReady = true;
  }

  function updateStats() {
    const total = targets.length;
    const overall = total > 0
      ? Math.round(targets.reduce((sum, t) => sum + getProgress(t), 0) / total)
      : 0;
    statTotal.textContent = total;
    statOverall.textContent = `${overall}%`;
  }

  function getFilteredTargets() {
    const q = (document.getElementById('searchInput').value || '').toLowerCase().trim();
    const cat = document.getElementById('filterCategory').value;
    return targets.filter((target) => {
      if (cat && target.category !== cat) return false;
      if (!q) return true;
      const hay = `${target.name || ''} ${target.category || ''} ${target.description || ''}`.toLowerCase();
      return hay.includes(q);
    });
  }

  function renderTable() {
    const tbody = document.getElementById('targetsTableBody');
    const list = getFilteredTargets();

    if (!list.length) {
      tbody.innerHTML = '<tr><td colspan="8" class="mp-plans-empty">No targets yet. Add your first KPI above.</td></tr>';
      return;
    }

    tbody.innerHTML = list.map((target) => {
      const progress = getProgress(target);
      const status = getTargetStatus(progress);
      const pct = Math.min(Math.round(progress), 100);
      const goal = target.targetValue ?? target.target_value ?? 0;
      const current = target.currentValue ?? target.current_value ?? 0;
      const unit = escapeHtml(target.unit || '');
      const id = escapeHtml(String(target.id));

      return `
        <tr>
          <td><strong>${escapeHtml(target.name)}</strong></td>
          <td>${escapeHtml(target.category)}</td>
          <td>${escapeHtml(target.period)}</td>
          <td>${goal} ${unit}</td>
          <td>${current} ${unit}</td>
          <td>
            <div class="ti-progress-wrap">
              <div class="ti-progress-bar"><div class="ti-progress-fill" style="width:${pct}%"></div></div>
              <span class="ti-progress-pct">${pct}%</span>
            </div>
          </td>
          <td><span class="ti-badge ${statusBadgeClass(status)}">${escapeHtml(status)}</span></td>
          <td>
            <div class="mp-plan-actions">
              <button type="button" class="mp-btn mp-btn--ghost" data-edit="${id}"><i class="fas fa-pen"></i></button>
              <button type="button" class="mp-btn mp-btn--ghost mp-btn--danger" data-delete="${id}"><i class="fas fa-trash"></i></button>
            </div>
          </td>
        </tr>
      `;
    }).join('');
  }

  function updateTargetSelect() {
    const select = document.getElementById('progressTargetSelect');
    select.innerHTML = '<option value="">Select target</option>';
    targets.forEach((target) => {
      const opt = document.createElement('option');
      opt.value = String(target.id);
      const goal = target.targetValue ?? target.target_value ?? 0;
      const current = target.currentValue ?? target.current_value ?? 0;
      opt.textContent = `${target.name} (${current}/${goal} ${target.unit || ''})`;
      select.appendChild(opt);
    });
  }

  function refreshUI() {
    updateStats();
    renderTable();
    updateTargetSelect();
  }

  async function saveTarget() {
    const name = document.getElementById('targetName').value.trim();
    const category = document.getElementById('targetCategory').value;
    const period = document.getElementById('targetPeriod').value;
    const targetValue = parseFloat(document.getElementById('targetValue').value);
    const unit = document.getElementById('targetUnit').value.trim();
    const description = document.getElementById('targetDescription').value.trim();

    if (!name || !category || !period || Number.isNaN(targetValue) || !unit) {
      notify('Fill in all required fields.', 'error');
      return;
    }
    if (!window.RDOMMSPlanningAPI) {
      notify('Planning API not loaded.', 'error');
      return;
    }

    try {
      if (editingId !== null) {
        const saved = await RDOMMSPlanningAPI.targets.update(editingId, {
          name, category, period, targetValue, unit, description
        });
        const idx = targets.findIndex((t) => String(t.id) === String(editingId));
        if (idx >= 0) targets[idx] = saved;
        notify('Target updated.', 'success');
        cancelEdit();
      } else {
        const saved = await RDOMMSPlanningAPI.targets.create({
          name, category, period, targetValue, currentValue: 0, unit, description, status: 'not_started'
        });
        targets.push(saved);
        notify('Target added.', 'success');
        clearForm();
      }
      refreshUI();
    } catch (error) {
      notify(error.message || 'Could not save target.', 'error');
    }
  }

  async function updateProgress() {
    const targetId = document.getElementById('progressTargetSelect').value;
    const currentValue = parseFloat(document.getElementById('currentValue').value);
    const notes = document.getElementById('progressNotes').value.trim();

    if (!targetId || Number.isNaN(currentValue)) {
      notify('Select a target and enter the current value.', 'error');
      return;
    }
    if (!window.RDOMMSPlanningAPI) return;

    try {
      const saved = await RDOMMSPlanningAPI.targets.updateProgress(targetId, currentValue, notes);
      const idx = targets.findIndex((t) => String(t.id) === String(targetId));
      if (idx >= 0) targets[idx] = saved;
      document.getElementById('progressTargetSelect').value = '';
      document.getElementById('currentValue').value = '';
      document.getElementById('progressNotes').value = '';
      refreshUI();
      notify('Progress saved.', 'success');
    } catch (error) {
      notify(error.message || 'Could not update progress.', 'error');
    }
  }

  function editTarget(targetId) {
    const target = targets.find((t) => String(t.id) === String(targetId));
    if (!target) return;
    document.getElementById('targetName').value = target.name || '';
    document.getElementById('targetCategory').value = target.category || '';
    document.getElementById('targetPeriod').value = target.period || 'monthly';
    document.getElementById('targetValue').value = target.targetValue ?? target.target_value ?? '';
    document.getElementById('targetUnit').value = target.unit || '';
    document.getElementById('targetDescription').value = target.description || '';
    setEditMode(targetId);
    document.getElementById('builderPanel')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  async function deleteTarget(targetId) {
    if (!confirm('Delete this target?')) return;
    if (!window.RDOMMSPlanningAPI) return;
    try {
      await RDOMMSPlanningAPI.targets.delete(targetId);
      targets = targets.filter((t) => String(t.id) !== String(targetId));
      if (String(editingId) === String(targetId)) cancelEdit();
      refreshUI();
      notify('Target deleted.', 'success');
    } catch (error) {
      notify(error.message || 'Delete failed.', 'error');
    }
  }

  function exportTargets() {
    if (!targets.length) {
      notify('No targets to export.', 'warning');
      return;
    }
    if (!window.RDOMMSExport) {
      notify('Export module not loaded.', 'error');
      return;
    }
    const headers = ['Name', 'Category', 'Period', 'Target', 'Current', 'Unit', 'Status'];
    const rows = targets.map((target) => {
      const status = getTargetStatus(getProgress(target));
      return [
        target.name,
        target.category,
        target.period,
        target.targetValue ?? target.target_value,
        target.currentValue ?? target.current_value,
        target.unit,
        status
      ];
    });
    RDOMMSExport.exportData({
      format: 'pdf',
      title: 'Targets & Indicators',
      subtitle: `Generated ${new Date().toLocaleString()} · ${targets.length} targets`,
      headers,
      rows,
      filename: `targets-indicators-${new Date().toISOString().slice(0, 10)}`
    }).then(() => notify('Exported as PDF.', 'success'))
      .catch((error) => notify(error.message || 'Export failed.', 'error'));
  }

  btnSaveTarget.addEventListener('click', saveTarget);
  btnCancelEdit.addEventListener('click', cancelEdit);
  document.getElementById('btnUpdateProgress').addEventListener('click', updateProgress);
  document.getElementById('exportBtn').addEventListener('click', exportTargets);
  document.getElementById('searchInput').addEventListener('input', renderTable);
  document.getElementById('filterCategory').addEventListener('change', renderTable);

  document.getElementById('targetsTableBody').addEventListener('click', (event) => {
    const editBtn = event.target.closest('[data-edit]');
    const deleteBtn = event.target.closest('[data-delete]');
    if (editBtn) editTarget(editBtn.getAttribute('data-edit'));
    if (deleteBtn) deleteTarget(deleteBtn.getAttribute('data-delete'));
  });

  async function bootstrap() {
    if (window.RDOMMSPlanningAPI) {
      try {
        await RDOMMSPlanningAPI.targets.migrate();
        await loadTargets();
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
