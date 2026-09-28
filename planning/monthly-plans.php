<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/planning-access.php';
rdomms_require_planning_module_page();
require_once __DIR__ . '/../config/dates.php';

$months = rdomms_month_names();
$currentMonth = rdomms_current_month_name();
$currentYear = rdomms_current_year();
$years = rdomms_year_range();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Monthly Plans | RDO|MMS</title>
<meta name="description" content="Create and submit monthly activity plans in RDO|MMS.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="../assets/css/rdomms-icons-offline.css">
<link rel="stylesheet" href="../assets/css/rdomms-session-countdown.css?v=4">
<link rel="stylesheet" href="../assets/css/project-nav.css?v=4">
<link rel="stylesheet" href="../assets/css/planning-pages.css">
<link rel="stylesheet" href="../assets/css/monthly-plans-workspace.css?v=1">
<link rel="stylesheet" href="../assets/css/rdomms-toast.css">
</head>
<body class="planning-page">
<nav id="rdomms-planning-nav" data-active="monthly-plans"></nav>

<div class="mp-shell">
  <header class="mp-head">
    <div>
      <h1>Monthly Plan</h1>
      <p>Add activities for the month, save your draft, then submit when ready.</p>
    </div>
    <div class="mp-stats" aria-live="polite">
      <span class="mp-stat"><strong id="statActivities">0</strong> activities</span>
      <span class="mp-stat">TSH <strong id="statBudget">0</strong></span>
    </div>
  </header>

  <section class="mp-card" id="builderPanel">
    <div class="mp-card-body">
      <div class="mp-context">
        <div class="mp-field">
          <label for="monthSelect">Month</label>
          <select id="monthSelect">
            <?php foreach ($months as $month): ?>
              <option value="<?php echo htmlspecialchars($month); ?>" <?php echo $month === $currentMonth ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($month); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mp-field">
          <label for="yearSelect">Year</label>
          <select id="yearSelect">
            <?php foreach ($years as $year): ?>
              <option value="<?php echo (int) $year; ?>" <?php echo ((int) $year === $currentYear) ? 'selected' : ''; ?>>
                <?php echo (int) $year; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mp-field">
          <label for="stationSelect">Center <span class="req">*</span></label>
          <select id="stationSelect">
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
          <label for="objectiveInput">Objective</label>
          <input id="objectiveInput" type="text" placeholder="Main goal for this month">
        </div>
      </div>

      <div class="mp-toolbar">
        <div class="mp-btn-row">
          <button class="mp-btn mp-btn--ghost" id="addRowBtn" type="button"><i class="fas fa-plus"></i> Add row</button>
          <button class="mp-btn mp-btn--ghost" id="saveDraftBtn" type="button"><i class="fas fa-save"></i> Save draft</button>
          <button class="mp-btn mp-btn--primary" id="submitBtn" type="button"><i class="fas fa-paper-plane"></i> Submit</button>
        </div>
        <button class="mp-btn mp-btn--danger" id="clearBtn" type="button"><i class="fas fa-rotate-left"></i> Reset</button>
      </div>

      <div class="mp-table-wrap">
        <table class="mp-table" id="planTable">
          <thead>
            <tr>
              <th style="width:110px;">Week</th>
              <th>Activity</th>
              <th>Output</th>
              <th>Responsible</th>
              <th style="width:130px;">Budget</th>
              <th style="width:120px;">Status</th>
              <th style="width:52px;"></th>
            </tr>
          </thead>
          <tbody id="tableBody"></tbody>
        </table>
      </div>

      <div class="mp-banner" id="editModeBanner"><i class="fas fa-pen"></i> Editing an existing plan.</div>
      <div class="mp-status" id="statusMessage" role="status"></div>
    </div>
  </section>

  <section class="mp-card">
    <div class="mp-list-head">
      <h2><i class="fas fa-folder-open"></i> My submitted plans</h2>
    </div>
    <div id="mySubmittedPlansList">
      <div class="mp-plans-empty">Loading…</div>
    </div>
  </section>
</div>

<div class="mp-modal" id="previewModal" aria-hidden="true">
  <div class="mp-modal-card" role="dialog" aria-modal="true">
    <div class="mp-modal-head">
      <div>
        <h3 id="previewModalTitle">Plan preview</h3>
        <div class="mp-modal-meta" id="previewModalMeta"></div>
      </div>
      <button class="mp-btn mp-btn--ghost" type="button" id="previewModalCloseBtn">Close</button>
    </div>
    <div class="mp-modal-body">
      <div class="mp-table-wrap">
        <table class="mp-table">
          <thead id="previewModalHead"><tr><th>Week</th><th>Activity</th><th>Output</th><th>Responsible</th><th>Budget</th></tr></thead>
          <tbody id="previewModalBody"></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<input type="hidden" id="plannerName" value="">

<script src="../assets/js/rdomms-security.js"></script>
<script src="../assets/js/localStorage-service.js"></script>
<script src="../assets/js/planning-workspace-tools.js?v=3"></script>
<script src="../assets/js/rdomms-planning-api.js?v=2"></script>
<script src="../assets/js/rdomms-departments.js"></script>
<script src="../assets/js/rdomms-export.js"></script>
<script src="../assets/js/rdomms-toast.js"></script>
<script>
(function () {
  const PLAN_TYPE = 'monthly';
  const STORAGE_MONTHLY = 'monthlyPlans';
  const STORAGE_DRAFTS = 'monthlyPlanDrafts';
  const WS = () => window.RDOMMSPlanningWorkspace || {};

  let planCache = [];
  let currentRows = [];
  let currentDraftKey = '';
  let forceEditMode = false;
  let amendMode = false;
  let editingPlanId = '';
  let apiReady = false;
  let draftSaveTimer = null;

  const monthSelect = document.getElementById('monthSelect');
  const yearSelect = document.getElementById('yearSelect');
  const stationSelect = document.getElementById('stationSelect');
  const departmentSelect = document.getElementById('departmentSelect');
  const plannerName = document.getElementById('plannerName');
  const objectiveInput = document.getElementById('objectiveInput');
  const tableBody = document.getElementById('tableBody');
  const statusMessage = document.getElementById('statusMessage');
  const statActivities = document.getElementById('statActivities');
  const statBudget = document.getElementById('statBudget');
  const saveDraftBtn = document.getElementById('saveDraftBtn');
  const submitBtn = document.getElementById('submitBtn');
  const editModeBanner = document.getElementById('editModeBanner');

  function canEditCurrentPlan() {
    if (amendMode) return true;
    const source = findCachedPlan(currentKey());
    if (!source) return true;
    const status = WS().getApprovalStatus ? WS().getApprovalStatus(source) : String(source.approval_status || 'draft').toLowerCase();
    return status === 'draft' || status === 'rejected';
  }

  function updateFormEditState() {
    if (saveDraftBtn) {
      saveDraftBtn.disabled = amendMode;
      saveDraftBtn.title = amendMode ? 'Approved plans must be resubmitted — draft save is not available.' : '';
    }
    if (submitBtn) {
      submitBtn.innerHTML = amendMode
        ? '<i class="fas fa-paper-plane"></i> Resubmit'
        : '<i class="fas fa-paper-plane"></i> Submit';
    }
  }

  function updateEditBanner(isAmend) {
    if (!editModeBanner) return;
    editModeBanner.innerHTML = isAmend
      ? '<i class="fas fa-pen"></i> Amending an approved plan. Save changes, then resubmit for review.'
      : '<i class="fas fa-pen"></i> Editing an existing plan. Save draft or submit when ready.';
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function formatCurrency(value) {
    return Number(value || 0).toLocaleString('en-US');
  }

  function currentKey() {
    return `${yearSelect.value}-${monthSelect.value}`;
  }

  function blankRow(week = 'Week 1') {
    return { week, activity: '', output: '', responsible: '', budget: '', status: 'planned' };
  }

  function showStatus(message, type = 'info') {
    if (window.RDOMMSToast) {
      RDOMMSToast.show(message, type === 'error' ? 'error' : type === 'warning' ? 'warning' : 'success', {
        title: 'Monthly Plan',
        duration: type === 'error' ? 6000 : 3500,
        allowMultiple: true
      });
    }
    statusMessage.className = 'mp-status is-show mp-status--' + (type === 'error' ? 'err' : type === 'warning' ? 'warn' : 'ok');
    statusMessage.textContent = message;
  }

  function updateStats() {
    const active = currentRows.filter((row) =>
      String(row.activity || '').trim() || String(row.output || '').trim() || String(row.responsible || '').trim() || String(row.budget || '').trim()
    ).length;
    const budget = currentRows.reduce((sum, row) => sum + (Number(String(row.budget).replace(/[^0-9.]/g, '')) || 0), 0);
    statActivities.textContent = active;
    statBudget.textContent = formatCurrency(budget);
  }

  function normalizeDraftFromUI() {
    return {
      id: editingPlanId || currentKey(),
      month: monthSelect.value,
      year: Number(yearSelect.value),
      department: departmentSelect.value,
      station: stationSelect.value,
      staffName: plannerName.value.trim(),
      objective: objectiveInput.value.trim(),
      status: 'draft',
      notes: '',
      risks: '',
      activities: currentRows.map((row) => ({
        week: row.week || '',
        activity: row.activity || '',
        resources: row.output || '',
        participants: row.responsible || '',
        budget: Number(row.budget || 0),
        status: row.status || 'planned',
        output: row.output || '',
        responsible: row.responsible || ''
      })),
      total_budget: currentRows.reduce((sum, row) => sum + (Number(String(row.budget).replace(/[^0-9.]/g, '')) || 0), 0),
      approval_status: 'draft',
      saved_at: new Date().toISOString()
    };
  }

  function renderRows() {
    if (!currentRows.length) currentRows.push(blankRow());

    tableBody.innerHTML = currentRows.map((row, index) => `
      <tr data-index="${index}">
        <td>
          <select class="mp-select" data-field="week">
            ${[1, 2, 3, 4, 5].map((w) => `<option value="Week ${w}" ${row.week === `Week ${w}` ? 'selected' : ''}>Week ${w}</option>`).join('')}
          </select>
        </td>
        <td><textarea class="mp-input mp-textarea" data-field="activity" placeholder="What will be done?">${escapeHtml(row.activity)}</textarea></td>
        <td><textarea class="mp-input mp-textarea" data-field="output" placeholder="Expected result">${escapeHtml(row.output)}</textarea></td>
        <td><input class="mp-input" data-field="responsible" type="text" placeholder="Who" value="${escapeHtml(row.responsible)}"></td>
        <td><input class="mp-input" data-field="budget" type="number" min="0" step="1000" placeholder="0" value="${escapeHtml(row.budget)}"></td>
        <td>
          <select class="mp-select" data-field="status">
            <option value="planned" ${row.status === 'planned' ? 'selected' : ''}>Planned</option>
            <option value="in_progress" ${row.status === 'in_progress' ? 'selected' : ''}>In progress</option>
            <option value="done" ${row.status === 'done' ? 'selected' : ''}>Done</option>
          </select>
        </td>
        <td><button class="mp-row-del" type="button" data-remove="${index}" title="Remove"><i class="fas fa-trash"></i></button></td>
      </tr>
    `).join('');

    tableBody.querySelectorAll('[data-field]').forEach((el) => {
      el.addEventListener('input', onRowChange);
      el.addEventListener('change', onRowChange);
    });

    tableBody.querySelectorAll('[data-remove]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const idx = Number(btn.getAttribute('data-remove'));
        currentRows.splice(idx, 1);
        if (!currentRows.length) currentRows.push(blankRow());
        renderRows();
        autoSaveDraft(false);
      });
    });

    updateStats();
  }

  function onRowChange(event) {
    const tr = event.target.closest('tr');
    if (!tr) return;
    const idx = Number(tr.getAttribute('data-index'));
    const field = event.target.getAttribute('data-field');
    if (!currentRows[idx] || !field) return;
    currentRows[idx][field] = event.target.value;
    updateStats();
    autoSaveDraft(false);
  }

  function parseStoredRecord(record) {
    const activities = Array.isArray(record?.activities) ? record.activities : [];
    return {
      department: record?.department || '',
      station: record?.station || '',
      staffName: record?.staffName || '',
      objective: record?.objective || '',
      rows: activities.length ? activities.map((item, index) => ({
        week: item.week || `Week ${Math.min(index + 1, 5)}`,
        activity: item.activity || '',
        output: item.output || item.resources || '',
        responsible: item.responsible || item.participants || '',
        budget: item.budget || 0,
        status: item.status || 'planned'
      })) : [blankRow()]
    };
  }

  function loadPlanIntoForm(record) {
    const parsed = parseStoredRecord(record);
    setDepartmentValue(parsed.department);
    stationSelect.value = parsed.station || '';
    plannerName.value = parsed.staffName || plannerName.value || '';
    objectiveInput.value = parsed.objective || '';
    currentRows = parsed.rows;
    renderRows();
  }

  function applyDefaultFormState() {
    currentRows = [blankRow()];
    stationSelect.value = '';
    objectiveInput.value = '';
    ensureDepartmentsReady();
    if (departmentSelect) departmentSelect.value = '';
    initProfileDefaults();
    renderRows();
  }

  function findCachedPlan(key) {
    return planCache.find((plan) => String(plan.id || `${plan.year}-${plan.month}`) === key)
      || planCache.find((plan) => String(plan.month || '').toLowerCase() === String(monthSelect.value).toLowerCase() && String(plan.year || '') === String(yearSelect.value));
  }

  function loadCurrentPlan() {
    currentDraftKey = currentKey();
    const source = findCachedPlan(currentDraftKey);
    const userId = WS().getCurrentUserId ? WS().getCurrentUserId() : null;
    if (!source) {
      applyDefaultFormState();
      editingPlanId = '';
      amendMode = false;
      forceEditMode = false;
      editModeBanner?.classList.remove('is-active');
      updateFormEditState();
      return;
    }
    const status = WS().getApprovalStatus ? WS().getApprovalStatus(source) : (source.approval_status || source.status || 'draft');
    if (forceEditMode && WS().canOwnerModifyPlan && WS().canOwnerModifyPlan(source, userId)) {
      loadPlanIntoForm(source);
      editingPlanId = String(source.id || currentDraftKey);
      amendMode = status === 'approved';
      updateEditBanner(amendMode);
      editModeBanner?.classList.add('is-active');
      updateFormEditState();
      return;
    }
    if (status === 'rejected') {
      loadPlanIntoForm(source);
      editingPlanId = String(source.id || currentDraftKey);
      amendMode = false;
      editModeBanner?.classList.remove('is-active');
      updateFormEditState();
      return;
    }
    if (status === 'pending' || status === 'approved') {
      applyDefaultFormState();
      editingPlanId = '';
      amendMode = false;
      forceEditMode = false;
      editModeBanner?.classList.remove('is-active');
      updateFormEditState();
      return;
    }
    loadPlanIntoForm(source);
    editingPlanId = String(source.id || currentDraftKey);
    amendMode = false;
    editModeBanner?.classList.remove('is-active');
    updateFormEditState();
  }

  async function refreshPlanCache() {
    if (!window.RDOMMSPlanningAPI) return [];
    planCache = await RDOMMSPlanningAPI.plans.list(PLAN_TYPE);
    return planCache;
  }

  function autoSaveDraft(showToast = false) {
    if (!apiReady || !window.RDOMMSPlanningAPI || !canEditCurrentPlan()) return;
    const draft = normalizeDraftFromUI();
    draft.approval_status = 'draft';
    clearTimeout(draftSaveTimer);
    draftSaveTimer = setTimeout(async () => {
      try {
        const saved = await RDOMMSPlanningAPI.plans.save(PLAN_TYPE, draft);
        const idx = planCache.findIndex((plan) => String(plan.id) === String(saved.id));
        if (idx >= 0) planCache[idx] = saved;
        else planCache.push(saved);
        if (showToast) showStatus('Draft saved.', 'success');
      } catch (error) {
        if (showToast) showStatus(error.message || 'Could not save draft.', 'error');
      }
    }, 450);
  }

  function collectIssues() {
    const issues = [];
    if (!departmentSelect.value) issues.push('Select a department.');
    if (!stationSelect.value) issues.push('Select an organization center.');
    const active = currentRows.filter((row) => String(row.activity || '').trim());
    if (!active.length) issues.push('Add at least one activity.');
    active.forEach((row, idx) => {
      if (!String(row.output || '').trim() || !String(row.responsible || '').trim()) {
        issues.push(`Row ${idx + 1}: fill output and responsible.`);
      }
    });
    return issues;
  }

  function validatePlan() {
    const issues = collectIssues();
    if (issues.length) {
      showStatus(issues[0], 'error');
      return false;
    }
    return true;
  }

  async function submitPlan() {
    if (!validatePlan() || !window.RDOMMSPlanningAPI) return;
    const actionLabel = amendMode ? 'Resubmit amended plan' : 'Submit plan';
    if (!confirm(`${actionLabel} for ${monthSelect.value} ${yearSelect.value}?`)) return;
    const draft = normalizeDraftFromUI();
    draft.id = editingPlanId || draft.id || currentDraftKey;
    const wasAmend = amendMode;
    try {
      const saved = await RDOMMSPlanningAPI.plans.save(PLAN_TYPE, draft, { submit: true });
      const idx = planCache.findIndex((plan) => String(plan.id) === String(saved.id));
      if (idx >= 0) planCache[idx] = saved;
      else planCache.push(saved);
      forceEditMode = false;
      amendMode = false;
      editingPlanId = '';
      applyDefaultFormState();
      editModeBanner?.classList.remove('is-active');
      updateFormEditState();
      renderMyPlans();
      showStatus(wasAmend ? 'Plan resubmitted for review.' : 'Plan submitted successfully.', 'success');
    } catch (error) {
      showStatus(error.message || 'Submit failed.', 'error');
    }
  }

  function openPreview(plan) {
    WS().openPreviewModal({
      modalId: 'previewModal',
      title: `Monthly Plan — ${plan.month || monthSelect.value} ${plan.year || yearSelect.value}`,
      meta: [
        WS().departmentLabel ? WS().departmentLabel(plan.department) : plan.department,
        plan.station || '—',
        plan.objective || ''
      ].filter(Boolean).join(' · '),
      headers: ['Week', 'Activity', 'Output', 'Responsible', 'Budget'],
      rows: WS().buildMonthlyPreviewRows ? WS().buildMonthlyPreviewRows(plan) : [],
      totalBudget: plan.total_budget || plan.totalBudget
    });
  }

  function viewPlanFromPanel(plan) {
    openPreview(plan);
  }

  function editPlanFromPanel(plan) {
    const userId = WS().getCurrentUserId ? WS().getCurrentUserId() : null;
    if (!WS().canOwnerModifyPlan || !WS().canOwnerModifyPlan(plan, userId)) {
      showStatus('You can only edit your own plans.', 'warning');
      return;
    }
    yearSelect.value = String(plan.year || yearSelect.value);
    monthSelect.value = plan.month || monthSelect.value;
    forceEditMode = true;
    currentDraftKey = currentKey();
    loadCurrentPlan();
    document.getElementById('builderPanel')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    const status = WS().getApprovalStatus ? WS().getApprovalStatus(plan) : '';
    showStatus(
      status === 'approved'
        ? `Amending ${plan.month} ${plan.year}. Resubmit when ready.`
        : `Editing ${plan.month} ${plan.year}.`,
      'info'
    );
  }

  function exportPlanRecord(plan) {
    if (!window.RDOMMSExport) {
      showStatus('Export module not loaded.', 'error');
      return;
    }
    const activities = Array.isArray(plan.activities) ? plan.activities : [];
    const headers = ['Week', 'Activity', 'Output', 'Responsible', 'Budget (TSH)'];
    const rows = activities.map((item) => [
      item.week || '',
      item.activity || item.activities || '',
      item.output || item.resources || '',
      item.responsible || item.participants || '',
      item.budget || 0
    ]);
    RDOMMSExport.exportData({
      format: 'pdf',
      title: `Monthly Plan — ${plan.month} ${plan.year}`,
      subtitle: [
        WS().departmentLabel ? WS().departmentLabel(plan.department) : plan.department,
        plan.station || '',
        plan.objective || ''
      ].filter(Boolean).join(' · '),
      headers,
      rows,
      filename: `monthly_plan_${plan.month}_${plan.year}`
    }).then(() => showStatus('Plan exported as PDF.', 'success'))
      .catch((error) => showStatus(error.message || 'Export failed.', 'error'));
  }

  function duplicatePlanRecord(plan) {
    yearSelect.value = String(plan.year || yearSelect.value);
    monthSelect.value = plan.month || monthSelect.value;
    currentDraftKey = currentKey();
    const existing = findCachedPlan(currentDraftKey);
    const existingStatus = existing && WS().getApprovalStatus ? WS().getApprovalStatus(existing) : '';
    if (existing && existingStatus && existingStatus !== 'draft') {
      showStatus('A submitted plan already exists for this month. Delete it first or pick another month.', 'warning');
      return;
    }
    loadPlanIntoForm(plan);
    editingPlanId = currentKey();
    amendMode = false;
    forceEditMode = true;
    updateEditBanner(false);
    editModeBanner?.classList.add('is-active');
    updateFormEditState();
    document.getElementById('builderPanel')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    showStatus(`Duplicated ${plan.month} ${plan.year} into the builder. Save or submit when ready.`, 'info');
  }

  async function deletePlanRecord(plan) {
    if (!window.RDOMMSPlanningAPI) return;
    if (!confirm(`Delete plan for ${plan.month} ${plan.year}? This cannot be undone.`)) return;
    try {
      await RDOMMSPlanningAPI.plans.delete(PLAN_TYPE, plan.id);
      planCache = planCache.filter((item) => String(item.id) !== String(plan.id));
      if (String(editingPlanId) === String(plan.id) || currentKey() === String(plan.id)) {
        forceEditMode = false;
        amendMode = false;
        editingPlanId = '';
        applyDefaultFormState();
        editModeBanner?.classList.remove('is-active');
        updateFormEditState();
      }
      renderMyPlans();
      showStatus('Plan deleted.', 'success');
    } catch (error) {
      showStatus(error.message || 'Delete failed.', 'error');
    }
  }

  function openEvidenceForPlan(plan) {
    const period = encodeURIComponent(`${plan.month || ''} ${plan.year || ''}`.trim());
    window.location.href = `upload-evidence.php?planType=monthly&period=${period}`;
  }

  function renderMyPlans() {
    if (!WS().renderSubmittedPlansPanel) return;
    const userId = WS().getCurrentUserId ? WS().getCurrentUserId() : null;
    const mine = planCache
      .filter((p) => WS().planBelongsToUser ? WS().planBelongsToUser(p, userId) : true)
      .sort((a, b) => new Date(b.submitted_at || b.submittedAt || b.saved_at || 0) - new Date(a.submitted_at || a.submittedAt || a.saved_at || 0));

    WS().renderSubmittedPlansPanel({
      containerId: 'mySubmittedPlansList',
      plans: mine,
      planType: 'monthly',
      variant: 'mp',
      showRegister: false,
      emptyMessage: 'No submitted plans yet. Submit your first monthly plan above.',
      onView: viewPlanFromPanel,
      onEdit: editPlanFromPanel,
      onExport: exportPlanRecord,
      onDuplicate: duplicatePlanRecord,
      onDelete: deletePlanRecord,
      onEvidence: openEvidenceForPlan
    });
  }

  function getCurrentUserProfile() {
    if (window.RDOMMSRBAC?.getCurrentUser) return RDOMMSRBAC.getCurrentUser() || {};
    try { return JSON.parse(localStorage.getItem('currentUser') || '{}'); } catch (e) { return {}; }
  }

  function ensureDepartmentsReady() {
    if (!departmentSelect || !window.RDOMMSDepartments) return;
    RDOMMSDepartments.fillSelect(departmentSelect, { emptyLabel: 'Select department', selectedValue: departmentSelect.value || '' });
  }

  function setDepartmentValue(value) {
    ensureDepartmentsReady();
    if (!value || !window.RDOMMSDepartments) return;
    departmentSelect.value = RDOMMSDepartments.getValue(value);
  }

  function initProfileDefaults() {
    const user = getCurrentUserProfile();
    if (user.fullname) plannerName.value = user.fullname;
    if (!departmentSelect.value && user.department) setDepartmentValue(user.department);
  }

  function applyUrlParams() {
    const params = new URLSearchParams(window.location.search);
    if (params.get('year')) yearSelect.value = params.get('year');
    if (params.get('month')) monthSelect.value = params.get('month');
    if (params.get('edit') === '1') forceEditMode = true;
  }

  function bindPreviewModal() {
    const modal = document.getElementById('previewModal');
    const close = () => {
      modal?.classList.remove('is-open', 'active');
      WS().closePreviewModal && WS().closePreviewModal('previewModal');
    };
    document.getElementById('previewModalCloseBtn')?.addEventListener('click', close);
    modal?.addEventListener('click', (e) => { if (e.target === modal) close(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
  }

  document.getElementById('addRowBtn').addEventListener('click', () => {
    currentRows.push(blankRow(`Week ${Math.min(currentRows.length + 1, 5)}`));
    renderRows();
    autoSaveDraft(false);
  });

  document.getElementById('saveDraftBtn').addEventListener('click', () => autoSaveDraft(true));

  document.getElementById('submitBtn').addEventListener('click', submitPlan);

  document.getElementById('clearBtn').addEventListener('click', () => {
    if (!confirm(`Reset ${monthSelect.value} ${yearSelect.value}? Unsaved rows will be cleared.`)) return;
    forceEditMode = false;
    amendMode = false;
    editingPlanId = '';
    applyDefaultFormState();
    editModeBanner?.classList.remove('is-active');
    updateFormEditState();
    autoSaveDraft(false);
    showStatus('Form reset.', 'warning');
  });

  [monthSelect, yearSelect].forEach((el) => {
    el.addEventListener('change', () => {
      currentDraftKey = currentKey();
      forceEditMode = false;
      amendMode = false;
      editingPlanId = '';
      loadCurrentPlan();
    });
  });

  [stationSelect, departmentSelect, objectiveInput].forEach((el) => {
    el.addEventListener('change', () => autoSaveDraft(false));
    el.addEventListener('input', () => autoSaveDraft(false));
  });

  async function bootstrap() {
    applyUrlParams();
    ensureDepartmentsReady();
    bindPreviewModal();
    if (window.RDOMMSPlanningAPI) {
      try {
        await RDOMMSPlanningAPI.plans.migrate(PLAN_TYPE, STORAGE_MONTHLY, STORAGE_DRAFTS);
        await refreshPlanCache();
        apiReady = true;
      } catch (error) {
        showStatus(RDOMMSPlanningAPI.formatLoadError(error), 'warning');
      }
    }
    loadCurrentPlan();
    updateFormEditState();
    initProfileDefaults();
    renderMyPlans();
    if (apiReady) autoSaveDraft(false);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bootstrap);
  else bootstrap();
})();
</script>
<script src="../assets/js/rpn-nav-shared.js?v=2"></script>
<script src="../assets/js/planning-nav.js?v=10"></script>
</body>
</html>
