(function () {
  const TYPE_MAP = {
    Annual: 'annual',
    Quarterly: 'quarterly',
    Monthly: 'monthly'
  };

  let planRegistry = [];
  let rejectTarget = null;
  let viewMode = 'cards';

  const planList = document.getElementById('planList');
  const planTableWrap = document.getElementById('planTableWrap');
  const planTableBody = document.getElementById('planTableBody');
  const emptyState = document.getElementById('emptyState');
  const searchInput = document.getElementById('searchInput');
  const filterType = document.getElementById('filterType');
  const filterStatus = document.getElementById('filterStatus');
  const filterYear = document.getElementById('filterYear');
  const filterDept = document.getElementById('filterDept');
  const filterStation = document.getElementById('filterStation');
  const sortBy = document.getElementById('sortBy');
  const viewModal = document.getElementById('viewModal');
  const rejectModal = document.getElementById('rejectModal');
  const rejectReason = document.getElementById('rejectReason');
  const loadingOverlay = document.getElementById('loadingOverlay');
  const resultsCount = document.getElementById('resultsCount');
  const activeFiltersPreview = document.getElementById('activeFiltersPreview');
  const plansWorkspace = document.getElementById('plansWorkspace');

  const WS = () => window.RDOMMSPlanningWorkspace || {};

  function uid(prefix) {
    return `${prefix}_${Math.random().toString(36).slice(2, 10)}_${Date.now().toString(36)}`;
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function notify(message, type = 'info') {
    if (window.RDOMMSToast) {
      RDOMMSToast.show(message, type, {
        title: 'Planning Register',
        duration: type === 'error' ? 6800 : 4200,
        allowMultiple: true
      });
      return;
    }
    alert(message);
  }

  function setLoading(active) {
    if (!loadingOverlay) return;
    loadingOverlay.classList.toggle('active', !!active);
    loadingOverlay.setAttribute('aria-hidden', active ? 'false' : 'true');
  }

  function getCurrentUserProfile() {
    if (WS().getCurrentUser) return WS().getCurrentUser();
    try {
      return JSON.parse(localStorage.getItem('currentUser') || '{}');
    } catch (error) {
      return {};
    }
  }

  function canUserEditPlan(plan) {
    const userId = WS().getCurrentUserId ? WS().getCurrentUserId() : null;
    if (WS().planBelongsToUser && !WS().planBelongsToUser(plan, userId)) return false;
    return WS().canEditPlan ? WS().canEditPlan(plan) : false;
  }

  function getEditUrl(plan) {
    const typeKey = TYPE_MAP[plan.type] || String(plan.type || '').toLowerCase();
    return WS().getEditPageUrl ? WS().getEditPageUrl(typeKey, plan) : '#';
  }

  function departmentLabel(value) {
    if (window.RDOMMSDepartments) {
      return RDOMMSDepartments.getLabel(value) || value || '';
    }
    return value || '';
  }

  function stationLabel(value) {
    if (window.RDOMMSOrganizations) {
      return RDOMMSOrganizations.getLabel(value) || value || '';
    }
    return value || '';
  }

  function normalizeToken(value) {
    return String(value || '').trim().toLowerCase().replace(/[\s_\-]+/g, '');
  }

  function matchesDepartment(planDept, filterValue) {
    if (!filterValue) return true;
    const tokens = [planDept, departmentLabel(planDept)].map(normalizeToken).filter(Boolean);
    return tokens.includes(normalizeToken(filterValue))
      || tokens.includes(normalizeToken(departmentLabel(filterValue)));
  }

  function matchesStation(planStation, filterValue) {
    if (!filterValue) return true;
    const tokens = [planStation, stationLabel(planStation)].map(normalizeToken).filter(Boolean);
    return tokens.includes(normalizeToken(filterValue))
      || tokens.includes(normalizeToken(stationLabel(filterValue)));
  }

  function reviewerName() {
    const user = getCurrentUserProfile();
    return user.fullname || user.name || user.email || 'Reviewer';
  }

  function timeAgo(iso) {
    if (!iso) return 'N/A';
    const diff = Date.now() - new Date(iso).getTime();
    const minutes = Math.floor(diff / 60000);
    if (minutes < 1) return 'Just now';
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    return `${Math.floor(hours / 24)}d ago`;
  }

  function initials(name) {
    const cleaned = String(name || '').trim();
    if (!cleaned) return 'PL';
    return cleaned.split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase();
  }

  function getApprovalStatus(plan) {
    return String(plan.approval_status || plan.approvalStatus || plan.status || 'pending').toLowerCase();
  }

  function getSubmittedAt(plan) {
    return plan.submitted_at || plan.submittedAt || '';
  }

  function isSubmittedPlan(plan) {
    if (!plan || typeof plan !== 'object') return false;
    const submittedAt = getSubmittedAt(plan);
    const approval = getApprovalStatus(plan);
    if (submittedAt) return true;
    return approval === 'pending' || approval === 'approved' || approval === 'rejected';
  }

  function normalizeStoredPlan(plan) {
    const normalized = { ...plan };
    const type = normalized.type || 'Plan';
    if (!normalized.id) {
      normalized.id = normalized.month
        ? `${normalized.year || ''}-${normalized.month}`
        : normalized.quarter
          ? `${normalized.year || ''}-${normalized.quarter}`
          : String(normalized.year || uid(type.toLowerCase()));
    }
    const planType = TYPE_MAP[type] || String(type).toLowerCase();
    return {
      ...normalized,
      type,
      compositeId: `${planType}:${normalized.id}`,
      approval_status: getApprovalStatus(normalized),
      submitted_at: getSubmittedAt(normalized) || normalized.saved_at || normalized.savedAt || '',
      rejectReason: normalized.reject_reason || normalized.rejectReason || ''
    };
  }

  async function loadRegistry() {
    if (!window.RDOMMSPlanningAPI) {
      planRegistry = [];
      return planRegistry;
    }
    const rows = await RDOMMSPlanningAPI.plans.listAllSubmitted();
    planRegistry = rows
      .filter(isSubmittedPlan)
      .map(normalizeStoredPlan)
      .sort((a, b) => new Date(b.submitted_at || 0) - new Date(a.submitted_at || 0));
    return planRegistry;
  }

  function getSubmittedPlans() {
    return planRegistry;
  }

  function findPlan(compositeId) {
    return planRegistry.find((plan) => plan.compositeId === compositeId);
  }

  function parseCompositeId(compositeId) {
    const [planType, id] = String(compositeId).split(':');
    return { planType, id };
  }

  function planPeriodLabel(plan) {
    if (plan.type === 'Annual') return String(plan.year || '');
    if (plan.type === 'Monthly') return `${plan.month || ''} ${plan.year || ''}`.trim();
    return `${plan.quarter || ''} ${plan.year || ''}`.trim();
  }

  function planTitle(plan) {
    if (plan.type === 'Annual') return `Annual Plan · ${escapeHtml(plan.year)}`;
    if (plan.type === 'Monthly') return `Monthly Plan · ${escapeHtml(plan.month)} ${escapeHtml(plan.year || '')}`.trim();
    return `Quarterly Plan · ${escapeHtml(plan.year)} ${escapeHtml(plan.quarter)}`;
  }

  function sortPlans(plans) {
    const mode = sortBy?.value || 'newest';
    const copy = [...plans];
    if (mode === 'oldest') {
      copy.sort((a, b) => new Date(a.submitted_at || 0) - new Date(b.submitted_at || 0));
    } else if (mode === 'staff-asc') {
      copy.sort((a, b) => String(a.staffName || '').localeCompare(String(b.staffName || '')));
    } else if (mode === 'staff-desc') {
      copy.sort((a, b) => String(b.staffName || '').localeCompare(String(a.staffName || '')));
    } else if (mode === 'type') {
      copy.sort((a, b) => String(a.type || '').localeCompare(String(b.type || '')));
    } else {
      copy.sort((a, b) => new Date(b.submitted_at || 0) - new Date(a.submitted_at || 0));
    }
    return copy;
  }

  function getFilteredPlans() {
    const search = (searchInput?.value || '').toLowerCase().trim();
    const typeValue = filterType?.value || '';
    const statusValue = filterStatus?.value || '';
    const yearValue = filterYear?.value || '';
    const deptValue = filterDept?.value || '';
    const stationValue = filterStation?.value || '';

    const filtered = getSubmittedPlans().filter((plan) => {
      const status = plan.approval_status || 'pending';
      const typeKey = String(plan.type || '').toLowerCase();
      if (typeValue && typeKey !== typeValue) return false;
      if (statusValue && status !== statusValue) return false;
      if (yearValue && String(plan.year || '') !== String(yearValue)) return false;
      if (!matchesDepartment(plan.department, deptValue)) return false;
      if (!matchesStation(plan.station, stationValue)) return false;
      if (!search) return true;
      const haystack = [
        plan.staffName,
        plan.department,
        departmentLabel(plan.department),
        plan.station,
        stationLabel(plan.station),
        plan.year,
        plan.quarter,
        plan.month,
        plan.objective,
        plan.id
      ].join(' ').toLowerCase();
      return haystack.includes(search);
    });

    return sortPlans(filtered);
  }

  function renderSummary(all) {
    const pending = all.filter((plan) => plan.approval_status === 'pending').length;
    const approved = all.filter((plan) => plan.approval_status === 'approved').length;
    const rejected = all.filter((plan) => plan.approval_status === 'rejected').length;

    const countAll = document.getElementById('countAll');
    const countAllKpi = document.getElementById('countAllKpi');
    const countPending = document.getElementById('countPending');
    const countApproved = document.getElementById('countApproved');
    const countRejected = document.getElementById('countRejected');
    if (countAll) countAll.textContent = all.length;
    if (countAllKpi) countAllKpi.textContent = all.length;
    if (countPending) countPending.textContent = pending;
    if (countApproved) countApproved.textContent = approved;
    if (countRejected) countRejected.textContent = rejected;

    const statPending = document.getElementById('statPending');
    if (statPending) statPending.textContent = pending;
  }

  function syncStatusPills() {
    const status = filterStatus?.value || '';
    document.querySelectorAll('[data-status-pill]').forEach((btn) => {
      btn.classList.toggle('is-active', btn.getAttribute('data-status-pill') === status);
    });
    document.querySelectorAll('[data-kpi-status]').forEach((btn) => {
      const value = btn.getAttribute('data-kpi-status') || '';
      btn.classList.toggle('is-active', value === status);
    });
  }

  function renderActiveFiltersPreview() {
    if (!activeFiltersPreview) return;
    const parts = [];
    if (searchInput?.value?.trim()) parts.push(`<strong>Search:</strong> ${escapeHtml(searchInput.value.trim())}`);
    if (filterType?.value) parts.push(`<strong>Type:</strong> ${escapeHtml(filterType.value)}`);
    if (filterStatus?.value) parts.push(`<strong>Status:</strong> ${escapeHtml(filterStatus.value)}`);
    if (filterYear?.value) parts.push(`<strong>Year:</strong> ${escapeHtml(filterYear.value)}`);
    if (filterDept?.value) parts.push(`<strong>Dept:</strong> ${escapeHtml(departmentLabel(filterDept.value))}`);
    if (filterStation?.value) parts.push(`<strong>Center:</strong> ${escapeHtml(stationLabel(filterStation.value))}`);
    if (sortBy?.value && sortBy.value !== 'newest') parts.push(`<strong>Sort:</strong> ${escapeHtml(sortBy.options[sortBy.selectedIndex]?.text || sortBy.value)}`);

    if (!parts.length) {
      activeFiltersPreview.innerHTML = 'No filters applied — showing all submitted plans.';
      return;
    }
    activeFiltersPreview.innerHTML = parts.join(' · ');
  }

  function buildActionButtons(plan) {
    const status = plan.approval_status || 'pending';
    const reviewActions = status === 'pending'
      ? `<button class="ap-mini-btn approve" type="button" data-action="approve" data-id="${escapeHtml(plan.compositeId)}">Approve</button>
         <button class="ap-mini-btn reject" type="button" data-action="reject" data-id="${escapeHtml(plan.compositeId)}">Reject</button>`
      : `<span class="ap-plan-meta" style="margin:0;text-transform:capitalize">${escapeHtml(status)}</span>`;
    const editBtn = canUserEditPlan(plan)
      ? `<button class="ap-mini-btn" type="button" data-action="edit" data-id="${escapeHtml(plan.compositeId)}"><i class="fas fa-pen"></i> Edit</button>`
      : '';
    const exportBtn = `<button class="ap-mini-btn" type="button" data-action="export" data-id="${escapeHtml(plan.compositeId)}"><i class="fas fa-file-export"></i> Export</button>`;
    const viewBtn = `<button class="ap-mini-btn" type="button" data-action="view" data-id="${escapeHtml(plan.compositeId)}"><i class="fas fa-eye"></i> View</button>`;
    return `${viewBtn}${editBtn}${exportBtn}${reviewActions}`;
  }

  function renderCards(filtered) {
    if (!planList) return;
    planList.innerHTML = filtered.map((plan) => {
      const status = plan.approval_status || 'pending';
      const meta = [
        plan.staffName ? `Staff: ${escapeHtml(plan.staffName)}` : '',
        plan.department ? `Dept: ${escapeHtml(departmentLabel(plan.department))}` : '',
        plan.station ? `Center: ${escapeHtml(stationLabel(plan.station))}` : '',
        plan.submitted_at ? `Submitted: ${escapeHtml(timeAgo(plan.submitted_at))}` : ''
      ].filter(Boolean).join(' · ');
      const rejectNote = status === 'rejected' && plan.rejectReason
        ? `<div class="ap-reject-note">Reason: ${escapeHtml(plan.rejectReason)}</div>`
        : '';

      return `
        <article class="ap-plan-card ${status}">
          <div class="ap-plan-head">
            <div class="ap-avatar">${escapeHtml(initials(plan.staffName))}</div>
            <div>
              <div class="ap-plan-title">
                ${planTitle(plan)}
                <span class="ap-badge ${status}">${escapeHtml(status)}</span>
                <span class="ap-badge type">${escapeHtml(plan.type)}</span>
              </div>
              <div class="ap-plan-meta">${meta}</div>
            </div>
          </div>
          ${rejectNote}
          <div class="ap-plan-footer">
            <div class="ap-plan-id">ID: ${escapeHtml(plan.id)}</div>
            <div class="ap-actions">${buildActionButtons(plan)}</div>
          </div>
        </article>
      `;
    }).join('');
  }

  function renderTable(filtered) {
    if (!planTableBody) return;
    planTableBody.innerHTML = filtered.map((plan) => {
      const status = plan.approval_status || 'pending';
      return `
        <tr>
          <td>
            <strong>${planTitle(plan)}</strong>
            <div class="ap-plan-meta">${escapeHtml(plan.staffName || '—')}</div>
          </td>
          <td>${escapeHtml(plan.type)}</td>
          <td>${escapeHtml(planPeriodLabel(plan))}</td>
          <td>${escapeHtml(departmentLabel(plan.department) || '—')}</td>
          <td>${escapeHtml(stationLabel(plan.station) || '—')}</td>
          <td><span class="ap-badge ${status}">${escapeHtml(status)}</span></td>
          <td>${escapeHtml(timeAgo(plan.submitted_at))}</td>
          <td>
            <div class="ap-actions">${buildActionButtons(plan)}</div>
          </td>
        </tr>
      `;
    }).join('');
  }

  function render() {
    const all = getSubmittedPlans();
    const filtered = getFilteredPlans();
    renderSummary(all);
    syncStatusPills();
    renderActiveFiltersPreview();

    if (resultsCount) {
      resultsCount.textContent = filtered.length === all.length
        ? `Showing ${filtered.length} plan${filtered.length === 1 ? '' : 's'}`
        : `Showing ${filtered.length} of ${all.length} plans`;
    }

    const showEmpty = !filtered.length;
    if (emptyState) emptyState.style.display = showEmpty ? 'block' : 'none';
    if (planList) planList.hidden = showEmpty || viewMode !== 'cards';
    if (planTableWrap) planTableWrap.hidden = showEmpty || viewMode !== 'table';

    if (showEmpty) {
      if (planList) planList.innerHTML = '';
      if (planTableBody) planTableBody.innerHTML = '';
      return;
    }

    if (viewMode === 'table') {
      renderTable(filtered);
    } else {
      renderCards(filtered);
    }
  }

  function setViewMode(mode) {
    viewMode = mode === 'table' ? 'table' : 'cards';
    document.querySelectorAll('[data-view-mode]').forEach((btn) => {
      btn.classList.toggle('is-active', btn.getAttribute('data-view-mode') === viewMode);
    });
    render();
  }

  function setStatusFilter(value) {
    if (filterStatus) filterStatus.value = value;
    render();
  }

  function applyUrlFilters() {
    const params = new URLSearchParams(window.location.search);
    const type = params.get('filterType');
    const status = params.get('filterStatus');
    if (type && filterType) filterType.value = type;
    if (status && filterStatus) filterStatus.value = status;
  }

  async function approvePlan(compositeId) {
    if (!window.RDOMMSPlanningAPI) return;
    const { planType, id } = parseCompositeId(compositeId);
    try {
      setLoading(true);
      const saved = await RDOMMSPlanningAPI.plans.approve(planType, id, reviewerName());
      const idx = planRegistry.findIndex((plan) => plan.compositeId === compositeId);
      if (idx >= 0) planRegistry[idx] = normalizeStoredPlan(saved);
      render();
      notify('Plan approved.', 'success');
    } catch (error) {
      notify(error.message || 'Failed to approve plan.', 'error');
    } finally {
      setLoading(false);
    }
  }

  function openReject(compositeId) {
    rejectTarget = compositeId;
    if (rejectReason) rejectReason.value = '';
    rejectModal?.classList.add('active');
    rejectModal?.setAttribute('aria-hidden', 'false');
    rejectReason?.focus();
  }

  function closeReject() {
    rejectTarget = null;
    rejectModal?.classList.remove('active');
    rejectModal?.setAttribute('aria-hidden', 'true');
  }

  async function confirmReject() {
    const reason = (rejectReason?.value || '').trim();
    if (!reason) {
      notify('Rejection reason is required.', 'warning');
      rejectReason?.focus();
      return;
    }
    if (!window.RDOMMSPlanningAPI || !rejectTarget) return;
    const { planType, id } = parseCompositeId(rejectTarget);
    try {
      setLoading(true);
      const saved = await RDOMMSPlanningAPI.plans.reject(planType, id, reason, reviewerName());
      const idx = planRegistry.findIndex((plan) => plan.compositeId === rejectTarget);
      if (idx >= 0) planRegistry[idx] = normalizeStoredPlan(saved);
      closeReject();
      render();
      notify('Plan rejected.', 'warning');
    } catch (error) {
      notify(error.message || 'Failed to reject plan.', 'error');
    } finally {
      setLoading(false);
    }
  }

  function formatCell(value) {
    if (!value) return '<em>—</em>';
    return escapeHtml(String(value)).replace(/\n/g, '<br>');
  }

  function buildMonthlyRows(plan) {
    const rows = Array.isArray(plan.activities) ? plan.activities : Array.isArray(plan.weeklyPlans) ? plan.weeklyPlans : [];
    return rows.map((row, index) => {
      const budget = Number(row.budget || 0) || 0;
      if (row.activity || row.output || row.responsible || row.week) {
        return {
          period: row.week || `Week ${index + 1}`,
          detail: row.activity || '',
          resources: row.output || row.resources || '',
          responsible: row.responsible || row.participants || '',
          budget
        };
      }
      return {
        period: `Week ${row.weekNumber || index + 1}`,
        detail: row.activities || '',
        resources: row.equipment || '',
        responsible: row.participants || '',
        budget
      };
    });
  }

  function buildPeriodRows(plan) {
    const rows = Array.isArray(plan.activities) ? plan.activities : [];
    return rows.map((row) => ({
      period: row.month || '—',
      detail: row.activities || '',
      resources: row.equipment || '',
      responsible: row.participants || '',
      budget: Number(row.budget || 0) || 0
    }));
  }

  function viewPlan(compositeId) {
    const plan = findPlan(compositeId);
    if (!plan) {
      notify('Plan not found.', 'error');
      return;
    }

    const type = plan.type || 'Plan';
    const title = type === 'Annual'
      ? `Annual Plan · ${escapeHtml(plan.year)}`
      : type === 'Monthly'
        ? `Monthly Plan · ${escapeHtml(plan.month)} ${escapeHtml(plan.year || '')}`.trim()
        : `Quarterly Plan · ${escapeHtml(plan.year)} ${escapeHtml(plan.quarter)}`;

    const meta = [
      plan.staffName ? `Staff: ${escapeHtml(plan.staffName)}` : '',
      plan.department ? `Dept: ${escapeHtml(departmentLabel(plan.department))}` : '',
      plan.station ? `Center: ${escapeHtml(stationLabel(plan.station))}` : '',
      plan.objective ? `Objective: ${escapeHtml(plan.objective)}` : '',
      getSubmittedAt(plan) ? `Submitted: ${escapeHtml(new Date(getSubmittedAt(plan)).toLocaleString())}` : ''
    ].filter(Boolean).join(' · ');

    const viewTitle = document.getElementById('viewTitle');
    const viewMeta = document.getElementById('viewMeta');
    if (viewTitle) viewTitle.innerHTML = title;
    if (viewMeta) viewMeta.innerHTML = meta;

    const head = document.getElementById('viewTableHead');
    if (head) {
      if (type === 'Monthly') {
        head.innerHTML = '<tr><th style="width:120px">Week</th><th>Activity</th><th style="width:180px">Expected Output</th><th style="width:180px">Responsible</th><th style="width:120px">Budget</th></tr>';
      } else {
        head.innerHTML = '<tr><th style="width:120px">Month</th><th>Activities</th><th style="width:180px">Equipment</th><th style="width:180px">Participants</th><th style="width:120px">Budget</th></tr>';
      }
    }

    const mappedRows = type === 'Monthly' ? buildMonthlyRows(plan) : buildPeriodRows(plan);
    let total = 0;
    const body = document.getElementById('viewBody');
    if (body) {
      body.innerHTML = mappedRows.map((row) => {
        total += row.budget;
        return `<tr>
          <td>${formatCell(row.period)}</td>
          <td>${formatCell(row.detail)}</td>
          <td>${formatCell(row.resources)}</td>
          <td>${formatCell(row.responsible)}</td>
          <td>${Number(row.budget || 0).toLocaleString()}</td>
        </tr>`;
      }).join('') || '<tr><td colspan="5"><em>No activity rows recorded.</em></td></tr>';
    }

    const viewTotal = document.getElementById('viewTotal');
    if (viewTotal) viewTotal.textContent = total.toLocaleString();
    viewModal?.classList.add('active');
    viewModal?.setAttribute('aria-hidden', 'false');
  }

  function closeView() {
    viewModal?.classList.remove('active');
    viewModal?.setAttribute('aria-hidden', 'true');
  }

  function exportSinglePlan(compositeId) {
    const plan = findPlan(compositeId);
    if (!plan) {
      notify('Plan not found.', 'error');
      return;
    }
    if (!window.RDOMMSExport) {
      notify('Export module not loaded.', 'error');
      return;
    }
    const format = document.getElementById('exportFormat')?.value || 'pdf';
    const type = plan.type || 'Plan';
    const mappedRows = type === 'Monthly' ? buildMonthlyRows(plan) : buildPeriodRows(plan);
    const headers = type === 'Monthly'
      ? ['Week', 'Activity', 'Expected Output', 'Responsible', 'Budget (Tsh)']
      : ['Month', 'Activities', 'Equipment', 'Participants', 'Budget (Tsh)'];
    const rows = mappedRows.map((row) => [row.period, row.detail, row.resources, row.responsible, row.budget]);
    const period = planPeriodLabel(plan);
    RDOMMSExport.exportData({
      format,
      title: `${type} Plan — ${period}`,
      subtitle: [
        plan.staffName ? `Planner: ${plan.staffName}` : '',
        plan.department ? `Dept: ${departmentLabel(plan.department)}` : '',
        plan.station ? `Center: ${stationLabel(plan.station)}` : ''
      ].filter(Boolean).join(' · '),
      headers,
      rows,
      filename: `${String(type).toLowerCase()}_plan_${period.replace(/\s+/g, '_')}`
    }).then(() => notify(`Plan exported as ${format.toUpperCase()}.`, 'success'))
      .catch((error) => notify(error.message || 'Export failed.', 'error'));
  }

  function editPlan(compositeId) {
    const plan = findPlan(compositeId);
    if (!plan) {
      notify('Plan not found.', 'error');
      return;
    }
    if (!canUserEditPlan(plan)) {
      notify('You cannot edit this plan.', 'warning');
      return;
    }
    window.location.href = getEditUrl(plan);
  }

  function exportRegistry() {
    const rows = getFilteredPlans();
    if (!rows.length) {
      notify('No plans to export for current filters.', 'warning');
      return;
    }
    if (!window.RDOMMSExport) {
      notify('Export module not loaded.', 'error');
      return;
    }
    const format = document.getElementById('exportFormat')?.value || 'pdf';
    const headers = ['Type', 'Period', 'Staff', 'Department', 'Center', 'Status', 'Submitted', 'Budget'];
    const dataRows = rows.map((plan) => {
      const budget = Number(plan.total_budget || plan.totalBudget || 0) || 0;
      return [
        plan.type,
        planPeriodLabel(plan),
        plan.staffName || '',
        departmentLabel(plan.department),
        stationLabel(plan.station),
        plan.approval_status || 'pending',
        plan.submitted_at ? new Date(plan.submitted_at).toLocaleString() : '',
        budget.toLocaleString()
      ];
    });

    RDOMMSExport.exportData({
      format,
      title: 'Planning Register',
      subtitle: `Export date: ${new Date().toLocaleString()} | Records: ${rows.length}`,
      headers,
      rows: dataRows,
      filename: `planning-register-${new Date().toISOString().slice(0, 10)}`
    }).then(() => notify(`Registry exported as ${format.toUpperCase()}.`, 'success'))
      .catch((error) => notify(error.message || 'Export failed.', 'error'));
  }

  function resetFilters() {
    if (searchInput) searchInput.value = '';
    if (filterType) filterType.value = '';
    if (filterStatus) filterStatus.value = '';
    if (filterYear) filterYear.value = '';
    if (filterDept) filterDept.value = '';
    if (filterStation) filterStation.value = '';
    if (sortBy) sortBy.value = 'newest';
    render();
    notify('Filters reset.', 'info');
  }

  function handlePlanAction(event) {
    const button = event.target.closest('[data-action][data-id]');
    if (!button) return;
    const action = button.dataset.action;
    const compositeId = button.dataset.id;
    if (action === 'view') viewPlan(compositeId);
    if (action === 'edit') editPlan(compositeId);
    if (action === 'export') exportSinglePlan(compositeId);
    if (action === 'approve') approvePlan(compositeId);
    if (action === 'reject') openReject(compositeId);
  }

  function bindUi() {
    plansWorkspace?.addEventListener('click', handlePlanAction);

    [searchInput, filterType, filterStatus, filterYear, filterDept, filterStation, sortBy].forEach((node) => {
      if (!node) return;
      node.addEventListener('change', render);
      if (node.tagName === 'INPUT') node.addEventListener('input', render);
    });

    document.querySelectorAll('[data-status-pill]').forEach((btn) => {
      btn.addEventListener('click', () => setStatusFilter(btn.getAttribute('data-status-pill') || ''));
    });

    document.querySelectorAll('[data-kpi-status]').forEach((btn) => {
      btn.addEventListener('click', () => setStatusFilter(btn.getAttribute('data-kpi-status') || ''));
    });

    document.querySelectorAll('[data-view-mode]').forEach((btn) => {
      btn.addEventListener('click', () => setViewMode(btn.getAttribute('data-view-mode')));
    });

    document.getElementById('resetFiltersBtn')?.addEventListener('click', resetFilters);
    document.getElementById('refreshBtn')?.addEventListener('click', async () => {
      try {
        setLoading(true);
        await loadRegistry();
        render();
        notify('Planning register refreshed.', 'success');
      } catch (error) {
        notify(error.message || 'Failed to refresh register.', 'error');
      } finally {
        setLoading(false);
      }
    });
    document.getElementById('exportBtn')?.addEventListener('click', exportRegistry);
    document.getElementById('closeViewBtn')?.addEventListener('click', closeView);
    document.getElementById('closeRejectBtn')?.addEventListener('click', closeReject);
    document.getElementById('cancelRejectBtn')?.addEventListener('click', closeReject);
    document.getElementById('confirmRejectBtn')?.addEventListener('click', confirmReject);

    viewModal?.addEventListener('click', (event) => {
      if (event.target === viewModal) closeView();
    });
    rejectModal?.addEventListener('click', (event) => {
      if (event.target === rejectModal) closeReject();
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeView();
        closeReject();
      }
    });
  }

  async function bootstrap() {
    if (window.RDOMMSDepartments) RDOMMSDepartments.fillAll();
    if (window.RDOMMSOrganizations) RDOMMSOrganizations.fillAll();
    applyUrlFilters();
    bindUi();
    try {
      setLoading(true);
      if (window.RDOMMSPlanningAPI) await loadRegistry();
      render();
    } catch (error) {
      notify(window.RDOMMSPlanningAPI?.formatLoadError?.(error) || error.message || 'Failed to load plans.', 'warning');
    } finally {
      setLoading(false);
    }
  }

  bootstrap();
})();
