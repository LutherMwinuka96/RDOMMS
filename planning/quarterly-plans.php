<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/planning-access.php';
rdomms_require_planning_module_page();
require_once __DIR__ . '/../config/dates.php';

$currentYear = rdomms_current_year();
$currentQuarter = rdomms_current_quarter_label();
$years = rdomms_year_range();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quarterly Plans | RDO|MMS</title>
<meta name="description" content="Create and submit quarterly activity plans in RDO|MMS.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="../assets/css/rdomms-icons-offline.css">
<link rel="stylesheet" href="../assets/css/rdomms-session-countdown.css?v=4">
<link rel="stylesheet" href="../assets/css/project-nav.css?v=4">
<link rel="stylesheet" href="../assets/css/planning-pages.css">
<link rel="stylesheet" href="../assets/css/monthly-plans-workspace.css?v=1">
<link rel="stylesheet" href="../assets/css/quarterly-plans-workspace.css?v=1">
<link rel="stylesheet" href="../assets/css/rdomms-toast.css">
</head>
<body class="planning-page qp-quarterly">
<nav id="rdomms-planning-nav" data-active="quarterly-plans"></nav>

<div class="mp-shell">
  <header class="mp-head">
    <div>
      <h1>Quarterly Plan</h1>
      <p>Plan activities month by month for the quarter, save your draft, then submit when ready.</p>
    </div>
    <div class="mp-stats" aria-live="polite">
      <span class="mp-stat"><strong id="statMonths">0</strong> / 3 months</span>
      <span class="mp-stat">TSH <strong id="statBudget">0</strong></span>
    </div>
  </header>

  <section class="mp-card" id="builderPanel">
    <div class="mp-card-body">
      <div class="mp-context qp-context">
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
          <label for="quarterSelect">Quarter</label>
          <select id="quarterSelect">
            <option value="Q1" <?php echo $currentQuarter === 'Q1' ? 'selected' : ''; ?>>Q1 — Jan to Mar</option>
            <option value="Q2" <?php echo $currentQuarter === 'Q2' ? 'selected' : ''; ?>>Q2 — Apr to Jun</option>
            <option value="Q3" <?php echo $currentQuarter === 'Q3' ? 'selected' : ''; ?>>Q3 — Jul to Sep</option>
            <option value="Q4" <?php echo $currentQuarter === 'Q4' ? 'selected' : ''; ?>>Q4 — Oct to Dec</option>
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
      </div>

      <div class="mp-field" style="margin-bottom:14px;">
        <label for="objectiveInput">Objective</label>
        <input id="objectiveInput" type="text" placeholder="Main goal for this quarter">
      </div>

      <div class="mp-toolbar">
        <div class="qp-quarter-nav">
          <button class="mp-btn mp-btn--ghost" type="button" id="prevQuarterBtn" title="Previous quarter"><i class="fas fa-chevron-left"></i></button>
          <div class="qp-quarter-pill">
            <span id="quarterPillLabel"><?php echo htmlspecialchars($currentQuarter); ?> <?php echo (int) $currentYear; ?></span>
            <span id="quarterPillMeta">Current quarter</span>
          </div>
          <button class="mp-btn mp-btn--ghost" type="button" id="nextQuarterBtn" title="Next quarter"><i class="fas fa-chevron-right"></i></button>
        </div>
        <div class="mp-btn-row">
          <button class="mp-btn mp-btn--ghost" id="saveDraftBtn" type="button"><i class="fas fa-save"></i> Save draft</button>
          <button class="mp-btn mp-btn--primary" id="submitBtn" type="button"><i class="fas fa-paper-plane"></i> Submit</button>
          <button class="mp-btn mp-btn--danger" id="clearBtn" type="button"><i class="fas fa-rotate-left"></i> Reset</button>
        </div>
      </div>

      <div class="mp-table-wrap">
        <table class="mp-table" id="planTable">
          <thead>
            <tr>
              <th style="width:120px;">Month</th>
              <th>Activities</th>
              <th>Equipment</th>
              <th>Participants</th>
              <th style="width:130px;">Budget</th>
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
          <thead id="previewModalHead"><tr><th>Month</th><th>Activities</th><th>Equipment</th><th>Participants</th><th>Budget</th></tr></thead>
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
  const PLAN_TYPE = 'quarterly';
  const STORAGE_QUARTERLY = 'quarterlyPlans';
  const STORAGE_DRAFTS = 'quarterlyPlanDrafts';
  const QUARTERS = {
    Q1: ['January', 'February', 'March'],
    Q2: ['April', 'May', 'June'],
    Q3: ['July', 'August', 'September'],
    Q4: ['October', 'November', 'December']
  };
  const QUARTER_LABELS = {
    Q1: 'January to March',
    Q2: 'April to June',
    Q3: 'July to September',
    Q4: 'October to December'
  };
  const QUARTER_ORDER = ['Q1', 'Q2', 'Q3', 'Q4'];
  const WS = () => window.RDOMMSPlanningWorkspace || {};

  let planCache = [];
  let currentRows = [];
  let currentDraftKey = '';
  let forceEditMode = false;
  let amendMode = false;
  let editingPlanId = '';
  let apiReady = false;
  let draftSaveTimer = null;

  const yearSelect = document.getElementById('yearSelect');
  const quarterSelect = document.getElementById('quarterSelect');
  const stationSelect = document.getElementById('stationSelect');
  const departmentSelect = document.getElementById('departmentSelect');
  const plannerName = document.getElementById('plannerName');
  const objectiveInput = document.getElementById('objectiveInput');
  const tableBody = document.getElementById('tableBody');
  const statusMessage = document.getElementById('statusMessage');
  const statMonths = document.getElementById('statMonths');
  const statBudget = document.getElementById('statBudget');
  const saveDraftBtn = document.getElementById('saveDraftBtn');
  const submitBtn = document.getElementById('submitBtn');
  const editModeBanner = document.getElementById('editModeBanner');
  const quarterPillLabel = document.getElementById('quarterPillLabel');
  const quarterPillMeta = document.getElementById('quarterPillMeta');

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
      ? '<i class="fas fa-pen"></i> Amending an approved plan. Update activities, then resubmit for review.'
      : '<i class="fas fa-pen"></i> Editing an existing plan. Save draft or submit when ready.';
  }

  function updateQuarterPill() {
    const q = quarterSelect.value;
    if (quarterPillLabel) {
      quarterPillLabel.textContent = `${q} ${yearSelect.value}`;
    }
    if (quarterPillMeta) {
      quarterPillMeta.textContent = QUARTER_LABELS[q] || '';
    }
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
    return `${yearSelect.value}-${quarterSelect.value}`;
  }

  function blankRow(month) {
    return { month, activities: '', equipment: '', participants: '', budget: '' };
  }

  function showStatus(message, type = 'info') {
    if (window.RDOMMSToast) {
      RDOMMSToast.show(message, type === 'error' ? 'error' : type === 'warning' ? 'warning' : 'success', {
        title: 'Quarterly Plan',
        duration: type === 'error' ? 6000 : 3500,
        allowMultiple: true
      });
    }
    statusMessage.className = 'mp-status is-show mp-status--' + (type === 'error' ? 'err' : type === 'warning' ? 'warn' : 'ok');
    statusMessage.textContent = message;
  }

  function updateStats() {
    const filled = currentRows.filter((row) => String(row.activities || '').trim()).length;
    const budget = currentRows.reduce((sum, row) => sum + (Number(String(row.budget).replace(/[^0-9.]/g, '')) || 0), 0);
    statMonths.textContent = filled;
    statBudget.textContent = formatCurrency(budget);
    updateQuarterPill();
  }

  function normalizeDraftFromUI() {
    return {
      id: editingPlanId || currentKey(),
      year: Number(yearSelect.value),
      quarter: quarterSelect.value,
      department: departmentSelect.value,
      station: stationSelect.value,
      staffName: plannerName.value.trim(),
      objective: objectiveInput.value.trim(),
      activities: currentRows.map((row) => ({
        month: row.month,
        activities: row.activities || '',
        equipment: row.equipment || '',
        participants: row.participants || '',
        budget: Number(row.budget || 0)
      })),
      total_budget: currentRows.reduce((sum, row) => sum + (Number(String(row.budget).replace(/[^0-9.]/g, '')) || 0), 0),
      totalBudget: currentRows.reduce((sum, row) => sum + (Number(String(row.budget).replace(/[^0-9.]/g, '')) || 0), 0),
      approval_status: 'draft',
      saved_at: new Date().toISOString()
    };
  }

  function renderRows() {
    const quarter = quarterSelect.value;
    const months = QUARTERS[quarter] || QUARTERS.Q1;

    if (!currentRows.length) {
      currentRows = months.map((month) => blankRow(month));
    }

    tableBody.innerHTML = currentRows.map((row, index) => `
      <tr data-index="${index}">
        <td class="qp-month-cell">${escapeHtml(row.month)}<small>${escapeHtml(quarter)}</small></td>
        <td><textarea class="mp-input mp-textarea" data-field="activities" placeholder="What will be done?">${escapeHtml(row.activities)}</textarea></td>
        <td><textarea class="mp-input mp-textarea" data-field="equipment" placeholder="Equipment needed">${escapeHtml(row.equipment)}</textarea></td>
        <td><textarea class="mp-input mp-textarea" data-field="participants" placeholder="Who is involved?">${escapeHtml(row.participants)}</textarea></td>
        <td><input class="mp-input" data-field="budget" type="number" min="0" step="1000" placeholder="0" value="${escapeHtml(row.budget)}"></td>
      </tr>
    `).join('');

    tableBody.querySelectorAll('[data-field]').forEach((el) => {
      el.addEventListener('input', onRowChange);
      el.addEventListener('change', onRowChange);
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
    const quarter = record?.quarter || quarterSelect.value;
    const months = QUARTERS[quarter] || QUARTERS.Q1;
    const activities = Array.isArray(record?.activities) ? record.activities : [];
    const rowMap = {};
    activities.forEach((item) => {
      rowMap[item.month] = {
        month: item.month,
        activities: item.activities || '',
        equipment: item.equipment || '',
        participants: item.participants || '',
        budget: item.budget || ''
      };
    });
    return {
      department: record?.department || '',
      station: record?.station || '',
      staffName: record?.staffName || '',
      objective: record?.objective || '',
      rows: months.map((month) => rowMap[month] || blankRow(month))
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
    const months = QUARTERS[quarterSelect.value] || QUARTERS.Q1;
    currentRows = months.map((month) => blankRow(month));
    stationSelect.value = '';
    objectiveInput.value = '';
    ensureDepartmentsReady();
    if (departmentSelect) departmentSelect.value = '';
    initProfileDefaults();
    renderRows();
  }

  function findCachedPlan(key) {
    return planCache.find((plan) => String(plan.id || `${plan.year}-${plan.quarter}`) === key)
      || planCache.find((plan) => String(plan.quarter) === quarterSelect.value && String(plan.year) === String(yearSelect.value));
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
    const active = currentRows.filter((row) => String(row.activities || '').trim());
    if (!active.length) issues.push('Add at least one monthly activity.');
    active.forEach((row) => {
      if (!String(row.participants || '').trim()) {
        issues.push(`${row.month}: add participants.`);
      }
      if (!String(row.budget || '').trim()) {
        issues.push(`${row.month}: add a budget amount.`);
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
    if (!confirm(`${actionLabel} for ${quarterSelect.value} ${yearSelect.value}?`)) return;
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

  function buildPreviewMeta(plan) {
    return [
      WS().departmentLabel ? WS().departmentLabel(plan.department) : plan.department,
      plan.station || '—',
      plan.objective || '',
      `${plan.quarter || quarterSelect.value} ${plan.year || yearSelect.value}`
    ].filter(Boolean).join(' · ');
  }

  function openPreview(plan) {
    WS().openPreviewModal({
      modalId: 'previewModal',
      title: `Quarterly Plan — ${plan.quarter || quarterSelect.value} ${plan.year || yearSelect.value}`,
      meta: buildPreviewMeta(plan),
      headers: ['Month', 'Activities', 'Equipment', 'Participants', 'Budget'],
      rows: WS().buildPeriodPreviewRows ? WS().buildPeriodPreviewRows(plan) : [],
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
    quarterSelect.value = plan.quarter || quarterSelect.value;
    forceEditMode = true;
    currentDraftKey = currentKey();
    loadCurrentPlan();
    document.getElementById('builderPanel')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    const status = WS().getApprovalStatus ? WS().getApprovalStatus(plan) : '';
    showStatus(
      status === 'approved'
        ? `Amending ${plan.quarter} ${plan.year}. Resubmit when ready.`
        : `Editing ${plan.quarter} ${plan.year}.`,
      'info'
    );
  }

  function exportPlanRecord(plan) {
    if (!window.RDOMMSExport) {
      showStatus('Export module not loaded.', 'error');
      return;
    }
    const activities = Array.isArray(plan.activities) ? plan.activities : [];
    const headers = ['Month', 'Activities', 'Equipment', 'Participants', 'Budget (TSH)'];
    const rows = activities.map((item) => [
      item.month || '',
      item.activities || '',
      item.equipment || '',
      item.participants || '',
      item.budget || 0
    ]);
    RDOMMSExport.exportData({
      format: 'pdf',
      title: `Quarterly Plan — ${plan.quarter} ${plan.year}`,
      subtitle: buildPreviewMeta(plan),
      headers,
      rows,
      filename: `quarterly_plan_${plan.quarter}_${plan.year}`
    }).then(() => showStatus('Plan exported as PDF.', 'success'))
      .catch((error) => showStatus(error.message || 'Export failed.', 'error'));
  }

  function duplicatePlanRecord(plan) {
    yearSelect.value = String(plan.year || yearSelect.value);
    quarterSelect.value = plan.quarter || quarterSelect.value;
    currentDraftKey = currentKey();
    const existing = findCachedPlan(currentDraftKey);
    const existingStatus = existing && WS().getApprovalStatus ? WS().getApprovalStatus(existing) : '';
    if (existing && existingStatus && existingStatus !== 'draft') {
      showStatus('A submitted plan already exists for this quarter. Delete it first or pick another quarter.', 'warning');
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
    showStatus(`Duplicated ${plan.quarter} ${plan.year} into the builder. Save or submit when ready.`, 'info');
  }

  async function deletePlanRecord(plan) {
    if (!window.RDOMMSPlanningAPI) return;
    if (!confirm(`Delete plan for ${plan.quarter} ${plan.year}? This cannot be undone.`)) return;
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
    const period = encodeURIComponent(`${plan.quarter || ''} ${plan.year || ''}`.trim());
    window.location.href = `upload-evidence.php?planType=quarterly&period=${period}`;
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
      planType: 'quarterly',
      variant: 'mp',
      showRegister: false,
      emptyMessage: 'No submitted plans yet. Submit your first quarterly plan above.',
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
    const quarter = params.get('quarter');
    if (quarter && QUARTERS[quarter]) quarterSelect.value = quarter;
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

  function shiftQuarter(direction) {
    const current = QUARTER_ORDER.indexOf(quarterSelect.value);
    if (current < 0) return;
    quarterSelect.value = QUARTER_ORDER[(current + direction + QUARTER_ORDER.length) % QUARTER_ORDER.length];
    currentDraftKey = currentKey();
    forceEditMode = false;
    amendMode = false;
    editingPlanId = '';
    loadCurrentPlan();
  }

  document.getElementById('saveDraftBtn').addEventListener('click', () => autoSaveDraft(true));
  document.getElementById('submitBtn').addEventListener('click', submitPlan);
  document.getElementById('prevQuarterBtn').addEventListener('click', () => shiftQuarter(-1));
  document.getElementById('nextQuarterBtn').addEventListener('click', () => shiftQuarter(1));

  document.getElementById('clearBtn').addEventListener('click', () => {
    if (!confirm(`Reset ${quarterSelect.value} ${yearSelect.value}? Unsaved rows will be cleared.`)) return;
    forceEditMode = false;
    amendMode = false;
    editingPlanId = '';
    applyDefaultFormState();
    editModeBanner?.classList.remove('is-active');
    updateFormEditState();
    autoSaveDraft(false);
    showStatus('Form reset.', 'warning');
  });

  [yearSelect, quarterSelect].forEach((el) => {
    el.addEventListener('change', () => {
      currentDraftKey = currentKey();
      forceEditMode = false;
      amendMode = false;
      editingPlanId = '';
      currentRows = [];
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
    updateQuarterPill();
    if (window.RDOMMSPlanningAPI) {
      try {
        await RDOMMSPlanningAPI.plans.migrate(PLAN_TYPE, STORAGE_QUARTERLY, STORAGE_DRAFTS);
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
