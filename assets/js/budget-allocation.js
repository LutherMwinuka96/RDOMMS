/**
 * RDOMMS Budget Allocation — database-backed finance workspace.
 */
(function () {
  const PAGE = window.RDOMMS_BUDGET_PAGE || {};
  const API_BASE = PAGE.apiBase || '../api/budget.php';
  const API_EXPENSES = PAGE.apiExpenses || '../api/expenses.php';

  let editingId = null;
  let chartQuarterly = null;
  let chartDepartment = null;
  let chartExpenseSync = null;
  let currentAllocations = [];
  let currentExpenseOverview = null;
  let pendingSavePayload = null;
  let fundingLinks = null;

  function setLoading(active) {
    const el = document.getElementById('pageLoading');
    if (el) el.classList.toggle('hidden', !active);
  }

  function notify(msg, type) {
    if (window.RDOMMSToast) {
      RDOMMSToast.show(msg, type || 'info', { title: 'Budget Allocation', duration: 4000, allowMultiple: true });
    } else {
      alert(msg);
    }
  }

  function setDbStatus(connected, message) {
    const chip = document.getElementById('dbStatusChip');
    if (!chip) return;
    chip.innerHTML = connected
      ? '<i class="fas fa-circle-check"></i> Database connected'
      : `<i class="fas fa-triangle-exclamation"></i> ${message || 'Offline'}`;
    chip.style.background = connected ? 'rgba(255,255,255,.14)' : 'rgba(220,38,38,.22)';
  }

  async function fetchAllocations() {
    const response = await fetch(API_BASE, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      const err = new Error(payload?.message || 'Failed to load budget allocations');
      err.status = response.status;
      throw err;
    }
    return Array.isArray(payload.data) ? payload.data : [];
  }

  async function fetchPendingProjectBudgets() {
    const response = await fetch('../api/projects.php?action=pending-budgets', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok) {
      console.warn('Pending project budgets request failed:', response.status, payload?.message || '');
      return { rows: [], error: payload?.message || `Request failed (${response.status})` };
    }
    if (payload?.status !== 'success' || !Array.isArray(payload.data)) {
      return { rows: [], error: payload?.message || 'Invalid pending budgets response' };
    }
    return { rows: payload.data, error: null };
  }

  function formatProjectBudgetMoney(value) {
    if (typeof formatRdommsCurrencyHtml === 'function') {
      return formatRdommsCurrencyHtml(value);
    }
    return 'TSH ' + Number(value || 0).toLocaleString('en-US');
  }

  function escapePendingBudgetHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  async function approvePendingProjectBudget(projectId) {
    if (!window.RDOMMSProjectFinance?.approveProjectBudget) {
      throw new Error('Budget approval is unavailable on this page.');
    }
    await RDOMMSProjectFinance.approveProjectBudget(projectId);
  }

  async function rejectPendingProjectBudget(projectId) {
    const reason = prompt('Reason for rejecting this project budget (optional):', '');
    if (reason === null) return;
    if (!window.RDOMMSProjectFinance?.rejectProjectBudget) {
      throw new Error('Budget rejection is unavailable on this page.');
    }
    await RDOMMSProjectFinance.rejectProjectBudget(projectId, reason);
  }

  async function renderPendingProjectBudgets() {
    const panel = document.getElementById('pendingProjectBudgetsPanel');
    const list = document.getElementById('pendingProjectBudgetsList');
    if (!panel || !list) return;

    const canApprove = window.RDOMMSProjectFinance?.canUserApproveBudget?.()
      || window.RDOMMSRBAC?.getCurrentRole?.() === 'accountant'
      || window.RDOMMSRBAC?.getCurrentRole?.() === 'admin';

    if (!canApprove) {
      panel.hidden = true;
      list.innerHTML = '';
      return;
    }

    const { rows, error } = await fetchPendingProjectBudgets();
    if (error) {
      panel.hidden = false;
      list.innerHTML = `<div class="pending-budget-item"><div class="pending-budget-meta">${escapePendingBudgetHtml(error)}</div></div>`;
      return;
    }

    if (!rows.length) {
      panel.hidden = false;
      list.innerHTML = '<div class="pending-budget-item"><div class="pending-budget-meta">No project budgets waiting for approval.</div></div>';
      return;
    }

    panel.hidden = false;
    list.innerHTML = rows.map((project) => {
      const id = project.project_id || project.id;
      const name = escapePendingBudgetHtml(project.name || 'Project');
      const org = escapePendingBudgetHtml(project.organization || project.district || '—');
      const budget = formatProjectBudgetMoney(project.budget);
      const submitted = escapePendingBudgetHtml(
        project.budget_submitted_at || project.budgetSubmittedAt || project.created_at || ''
      );
      return `
        <div class="pending-budget-item" data-project-id="${escapePendingBudgetHtml(id)}">
          <div>
            <strong>${name}</strong>
            <div class="pending-budget-meta">${org} · Budget ${budget}${submitted ? ` · Submitted ${submitted}` : ''}</div>
          </div>
          <div class="pending-budget-actions">
            <button type="button" class="btn-approve" data-approve-project="${escapePendingBudgetHtml(id)}">
              <i class="fas fa-check"></i> Approve
            </button>
            <button type="button" class="btn-reject" data-reject-project="${escapePendingBudgetHtml(id)}">
              <i class="fas fa-times"></i> Reject
            </button>
          </div>
        </div>
      `;
    }).join('');

    list.querySelectorAll('[data-approve-project]').forEach((button) => {
      button.addEventListener('click', async () => {
        const projectId = button.getAttribute('data-approve-project');
        if (!projectId || !confirm('Approve this project budget?')) return;
        try {
          await approvePendingProjectBudget(projectId);
          notify('Project budget approved.', 'success');
          await refresh();
        } catch (error) {
          notify(error.message || 'Unable to approve project budget.', 'error');
        }
      });
    });

    list.querySelectorAll('[data-reject-project]').forEach((button) => {
      button.addEventListener('click', async () => {
        const projectId = button.getAttribute('data-reject-project');
        if (!projectId) return;
        try {
          await rejectPendingProjectBudget(projectId);
          notify('Project budget rejected.', 'warning');
          await refresh();
        } catch (error) {
          notify(error.message || 'Unable to reject project budget.', 'error');
        }
      });
    });
  }

  async function createAllocation(data) {
    const response = await fetch(API_BASE, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify(data)
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to save allocation');
    }
    return payload;
  }

  async function updateAllocationRequest(id, data) {
    const response = await fetch(API_BASE, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({ id, ...data })
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to update allocation');
    }
    return payload;
  }

  async function approveAllocationRequest(id) {
    const response = await fetch(`${API_BASE}?action=approve`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({ id })
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to approve allocation');
    }
    return payload;
  }

  async function deleteAllocationRequest(id) {
    const response = await fetch(`${API_BASE}?id=${encodeURIComponent(id)}`, {
      method: 'DELETE',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to delete allocation');
    }
    return payload;
  }

  function formatMoney(n) {
    return typeof formatRdommsCurrency === 'function' ? formatRdommsCurrency(n || 0) : `TSH ${Number(n || 0).toLocaleString('en-US')}`;
  }

  function formatMoneyHtml(n) {
    return typeof formatRdommsCurrencyHtml === 'function' ? formatRdommsCurrencyHtml(n || 0) : formatMoney(n);
  }

  function formatQuarter(q) {
    const map = { q1: 'Q1 (Jan–Mar)', q2: 'Q2 (Apr–Jun)', q3: 'Q3 (Jul–Sep)', q4: 'Q4 (Oct–Dec)' };
    return map[q] || q || '—';
  }

  function formatStation(s) {
    if (!s) return 'All centers';
    if (window.RDOMMSOrganizations) {
      return RDOMMSOrganizations.getLabel(s) || s;
    }
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  }

  function formatFundingLink(record) {
    if (fundingLinks) return fundingLinks.formatLinkLabel(record);
    if (record?.linked_label) return record.linked_label;
    if (!record?.linked_type) return 'General / Department';
    return `${record.linked_type} (${record.linked_id || '—'})`;
  }

  function normalizeOrganization(value) {
    return window.RDOMMSOrganizations ? RDOMMSOrganizations.getValue(value) : String(value || '');
  }

  function normalizeDepartment(value) {
    return window.RDOMMSDepartments ? RDOMMSDepartments.getValue(value) : String(value || '');
  }

  function formatDepartment(value) {
    return window.RDOMMSDepartments ? (RDOMMSDepartments.getLabel(value) || '—') : (value || '—');
  }

  function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function populateYears() {
    const sel = document.getElementById('quickYear');
    if (!sel || !window.RDOMMSDates) return;
    RDOMMSDates.fillYearSelect(sel, { selectedValue: PAGE.currentYear || RDOMMSDates.currentYear() });
  }

  function populateFilterYears() {
    const sel = document.getElementById('filterYearTable');
    if (!sel || !window.RDOMMSDates) return;
    const current = sel.value;
    RDOMMSDates.fillYearSelect(sel, {
      allYearsLabel: 'All years',
      allYearsValue: '',
      selectedValue: current || PAGE.currentYear || RDOMMSDates.currentYear()
    });
    if (!current) sel.value = '';
  }

  function setMoneyElement(el, amount) {
    if (!el) return;
    if (typeof setRdommsCurrencyElement === 'function') {
      setRdommsCurrencyElement(el, amount);
      return;
    }
    el.textContent = formatMoney(amount);
  }

  function toneClass(tone) {
    if (tone === 'danger') return 'danger';
    if (tone === 'warning') return 'warning';
    if (tone === 'info') return 'info';
    return '';
  }

  function initPageDefaults() {
    const stored = window.RDOMMSFinanceFilters ? RDOMMSFinanceFilters.readStored() : {};
    const defaults = PAGE.userDefaults || null;

    if (PAGE.currentQuarter) {
      const quarterSelect = document.getElementById('quickQuarter');
      if (quarterSelect && !quarterSelect.value) {
        quarterSelect.value = stored.quarter || PAGE.currentQuarter;
      }
    }
    if (PAGE.currentYear) {
      const yearSelect = document.getElementById('quickYear');
      if (yearSelect && !yearSelect.value) {
        yearSelect.value = stored.year || String(PAGE.currentYear);
      }
    }

    const orgEl = document.getElementById('quickOrganization');
    const deptEl = document.getElementById('quickDepartment');
    if (orgEl && !orgEl.value) orgEl.value = stored.organization || defaults?.organization || '';
    if (deptEl && !deptEl.value) deptEl.value = stored.department || defaults?.department || '';
  }

  function getScopeFilters() {
    const stored = window.RDOMMSFinanceFilters ? RDOMMSFinanceFilters.readStored() : {};
    return {
      organization: document.getElementById('filterOrganizationTable')?.value || stored.organization || '',
      department: document.getElementById('filterDepartmentTable')?.value || stored.department || '',
      quarter: document.getElementById('filterQuarterTable')?.value || stored.quarter || '',
      year: document.getElementById('filterYearTable')?.value || stored.year || ''
    };
  }

  function renderUserScopeChip() {
    const chip = document.getElementById('userScopeChip');
    const defaults = PAGE.userDefaults;
    if (!chip || !defaults?.fullname) return;
    const parts = [];
    if (defaults.organization) parts.push(formatStation(defaults.organization));
    if (defaults.department) parts.push(formatDepartment(defaults.department));
    if (!parts.length) return;
    chip.style.display = 'inline-flex';
    chip.innerHTML = `<i class="fas fa-user"></i> ${escapeHtml(defaults.fullname)} · ${escapeHtml(parts.join(' · '))}`;
  }

  function renderFilterChips() {
    const container = document.getElementById('filterChips');
    if (!container) return;
    const scope = getScopeFilters();
    const chips = [];
    if (scope.organization) chips.push(formatStation(scope.organization));
    if (scope.department) chips.push(formatDepartment(scope.department));
    if (scope.quarter) chips.push(formatQuarter(scope.quarter));
    if (scope.year) chips.push(scope.year);
    container.innerHTML = chips.length
      ? chips.map((label) => `<span class="filter-chip">${escapeHtml(label)}</span>`).join('')
      : '<span class="filter-chip muted">All records</span>';
  }

  function applyScopeToForm(options = {}) {
    const scope = getScopeFilters();
    const orgEl = document.getElementById('quickOrganization');
    const deptEl = document.getElementById('quickDepartment');
    const quarterEl = document.getElementById('quickQuarter');
    const yearEl = document.getElementById('quickYear');
    if (orgEl && scope.organization) orgEl.value = scope.organization;
    if (deptEl && scope.department) deptEl.value = scope.department;
    if (quarterEl && scope.quarter) quarterEl.value = scope.quarter;
    if (yearEl && scope.year) yearEl.value = scope.year;
    updateFormExpenseContext();
    if (!options.silent) notify('Active scope applied to allocation form.', 'info');
  }

  function applyScopeFromRow(allocation) {
    if (!allocation) return;
    const orgEl = document.getElementById('filterOrganizationTable');
    const deptEl = document.getElementById('filterDepartmentTable');
    const quarterEl = document.getElementById('filterQuarterTable');
    const yearEl = document.getElementById('filterYearTable');
    if (orgEl) orgEl.value = normalizeOrganization(allocation.station) || '';
    if (deptEl) deptEl.value = normalizeDepartment(allocation.department) || '';
    if (quarterEl) quarterEl.value = allocation.quarter || '';
    if (yearEl) yearEl.value = String(allocation.fiscalYear || allocation.fiscal_year || '');
    if (window.RDOMMSFinanceFilters) RDOMMSFinanceFilters.saveFromElement(deptEl);
    renderTable();
    refreshExpenseSync();
    applyScopeToForm({ silent: true });
  }

  function getBudgetOverviewParams() {
    const scope = getScopeFilters();
    return {
      view: 'department',
      organization: scope.organization,
      department: scope.department,
      quarter: scope.quarter,
      year: scope.year
    };
  }

  async function fetchBudgetOverview(params) {
    const query = new URLSearchParams({ action: 'budget-overview', ...params });
    const response = await fetch(`${API_EXPENSES}?${query.toString()}`, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to load expense utilization');
    }
    return payload.data;
  }

  function renderExpenseSyncChart(rows) {
    const canvas = document.getElementById('chartExpenseSync');
    if (!canvas || typeof Chart === 'undefined') return;
    if (chartExpenseSync) chartExpenseSync.destroy();
    const slice = rows.slice(0, 8);
    chartExpenseSync = new Chart(canvas, {
      type: 'bar',
      data: {
        labels: slice.length ? slice.map((row) => row.label || row.name) : ['No data'],
        datasets: [
          {
            label: 'Allocated',
            data: slice.length ? slice.map((row) => Number(row.allocated) || 0) : [0],
            backgroundColor: 'rgba(21, 128, 61, 0.78)',
            borderRadius: 8
          },
          {
            label: 'Committed',
            data: slice.length ? slice.map((row) => Number(row.spentCommitted) || 0) : [0],
            backgroundColor: 'rgba(29, 78, 216, 0.72)',
            borderRadius: 8
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } },
        scales: { y: { beginAtZero: true, ticks: { callback: (v) => formatMoney(v) } } }
      }
    });
  }

  function renderExpenseSyncPanel(data) {
    currentExpenseOverview = data;
    const summary = data?.summary || {};
    const rows = Array.isArray(data?.rows) ? data.rows : [];
    const subtitle = document.getElementById('expenseSyncSubtitle');
    const utilMeta = document.getElementById('statUtilMeta');

    setMoneyElement(document.getElementById('syncAllocatedTotal'), summary.allocated || 0);
    setMoneyElement(document.getElementById('syncSpentTotal'), summary.spentCommitted || 0);
    setMoneyElement(document.getElementById('syncRemainingTotal'), summary.remaining || 0);

    const utilization = Number(summary.utilizationPct || 0);
    const utilEl = document.getElementById('syncUtilizationTotal');
    const tone = summary.status?.tone || 'secondary';
    if (utilEl) {
      utilEl.textContent = `${utilization.toFixed(1)}%`;
      utilEl.className = `value ${toneClass(tone)}`.trim();
    }

    const fill = document.getElementById('syncProgressFill');
    if (fill) {
      fill.style.width = `${Math.max(0, Math.min(utilization, 100))}%`;
      fill.className = 'budget-progress-fill';
      if (tone === 'danger') fill.classList.add('danger');
      else if (tone === 'warning' || tone === 'critical') fill.classList.add('warning');
    }

    const badge = document.getElementById('syncStatusBadge');
    if (badge) {
      badge.className = `budget-status-badge ${tone}`;
      badge.textContent = summary.status?.label || 'No data';
    }

    const progressLabel = document.getElementById('syncProgressLabel');
    if (progressLabel) {
      progressLabel.textContent = data?.selectionLabel
        ? `Utilization for ${data.selectionLabel}`
        : 'Budget utilization';
    }

    const hint = document.getElementById('syncProgressHint');
    if (hint) {
      hint.textContent = `Allocated ${formatMoney(summary.allocated || 0)} | Committed ${formatMoney(summary.spentCommitted || 0)} | Remaining ${formatMoney(summary.remaining || 0)}`;
    }

    if (subtitle) {
      subtitle.textContent = `Live sync with Expense Tracking for ${data?.selectionLabel || 'selected scope'}.`;
    }
    if (utilMeta) {
      utilMeta.textContent = `${utilization.toFixed(1)}% utilized in scope`;
    }

    renderExpenseSyncChart(rows);
    updateFormExpenseContext();
  }

  async function refreshExpenseSync() {
    try {
      const data = await fetchBudgetOverview(getBudgetOverviewParams());
      renderExpenseSyncPanel(data);
    } catch (error) {
      renderExpenseSyncPanel({
        selectionLabel: 'selected scope',
        summary: {
          allocated: 0,
          spentCommitted: 0,
          remaining: 0,
          utilizationPct: 0,
          status: { label: error.message || 'Unable to load', tone: 'danger' }
        },
        rows: []
      });
    }
  }

  function getFormExpenseSummary() {
    const org = document.getElementById('quickOrganization')?.value || '';
    const dept = document.getElementById('quickDepartment')?.value || '';
    const quarter = document.getElementById('quickQuarter')?.value || '';
    const year = document.getElementById('quickYear')?.value || '';
    if (!dept || !quarter || !year) return null;

    const formScope = {
      organization: org,
      department: dept,
      quarter,
      year
    };
    const activeScope = getBudgetOverviewParams();
    const scopeMatches = ['organization', 'department', 'quarter', 'year'].every((key) =>
      String(formScope[key] || '') === String(activeScope[key] || ''));

    if (scopeMatches && currentExpenseOverview?.summary) {
      return currentExpenseOverview.summary;
    }

    const deptKey = normalizeDepartment(dept);
    const row = (currentExpenseOverview?.rows || []).find((item) =>
      normalizeDepartment(item.name) === deptKey || item.name === deptKey);
    return row || null;
  }

  function computeScopeUsage(organization, department, quarter, year, newAmount) {
    if (!department || !quarter || !year) return null;
    const orgKey = organization ? normalizeOrganization(organization) : '';
    const deptKey = normalizeDepartment(department);
    const quarterKey = String(quarter).toLowerCase();
    const yearKey = String(year);
    const amount = Number(newAmount) || 0;

    let allocated = 0;
    currentAllocations.forEach((item) => {
      if (normalizeDepartment(item.department) !== deptKey) return;
      if (String(item.quarter).toLowerCase() !== quarterKey) return;
      if (String(item.fiscalYear || item.fiscal_year) !== yearKey) return;
      if (orgKey && normalizeOrganization(item.station) !== orgKey) return;
      if ((item.status || '').toLowerCase() !== 'approved') return;
      if (String(item.allocation_id || item.id) === String(editingId)) return;
      allocated += Number(item.amount) || 0;
    });

    if (editingId) {
      const editing = currentAllocations.find((item) =>
        String(item.allocation_id || item.id) === String(editingId));
      if (editing
        && normalizeDepartment(editing.department) === deptKey
        && String(editing.quarter).toLowerCase() === quarterKey
        && String(editing.fiscalYear || editing.fiscal_year) === yearKey
        && (!orgKey || normalizeOrganization(editing.station) === orgKey)
        && (editing.status || '').toLowerCase() === 'approved') {
        allocated = Number(editing.amount) || 0;
      }
    }

    const expenseSummary = getFormExpenseSummary();
    const spentCommitted = Number(expenseSummary?.spentCommitted || expenseSummary?.spentApproved || 0)
      + Number(expenseSummary?.spentPending || 0);

    const totalAllocated = allocated + amount;
    const afterAllocation = totalAllocated - spentCommitted;

    return {
      allocated: totalAllocated,
      spentCommitted,
      remaining: allocated - spentCommitted,
      afterAllocation
    };
  }

  function updateFormExpenseContext() {
    const org = document.getElementById('quickOrganization')?.value || '';
    const dept = document.getElementById('quickDepartment')?.value || '';
    const quarter = document.getElementById('quickQuarter')?.value || '';
    const year = document.getElementById('quickYear')?.value || '';
    const amount = parseFloat(document.getElementById('quickAmount')?.value || '0') || 0;
    const box = document.getElementById('formExpenseContext');
    const hint = document.getElementById('formExpenseHint');
    const expenseLink = PAGE.links?.expenseTracking || 'expense-tracking.php';

    if (box) box.classList.remove('is-warning', 'is-danger');

    const usage = computeScopeUsage(org, dept, quarter, year, amount);
    if (!usage) {
      setMoneyElement(document.getElementById('formScopeAllocated'), 0);
      setMoneyElement(document.getElementById('formScopeSpent'), 0);
      setMoneyElement(document.getElementById('formScopeRemaining'), 0);
      setMoneyElement(document.getElementById('formScopeAfter'), 0);
      if (hint) {
        hint.textContent = 'Select organization center, department, quarter, and fiscal year to preview expense utilization.';
      }
      return;
    }

    setMoneyElement(document.getElementById('formScopeAllocated'), usage.allocated);
    setMoneyElement(document.getElementById('formScopeSpent'), usage.spentCommitted);
    setMoneyElement(document.getElementById('formScopeRemaining'), usage.remaining);
    setMoneyElement(document.getElementById('formScopeAfter'), usage.afterAllocation);

    const scopeLabel = [formatStation(org), formatDepartment(dept)].filter((p) => p && p !== 'All centers').join(' · ');
    if (hint) {
      if (usage.afterAllocation < 0) {
        hint.innerHTML = `Committed expenses exceed this allocation scope by ${escapeHtml(formatMoney(Math.abs(usage.afterAllocation)))}. `
          + `<a class="context-link" href="${escapeHtml(expenseLink)}">Review in Expense Tracking</a>.`;
        if (box) box.classList.add('is-danger');
      } else if (usage.allocated > 0 && usage.spentCommitted / usage.allocated >= 0.85) {
        hint.textContent = `${scopeLabel || formatDepartment(dept)} is nearing its expense limit (${usage.spentCommitted > 0 ? ((usage.spentCommitted / usage.allocated) * 100).toFixed(1) : 0}% used).`;
        if (box) box.classList.add('is-warning');
      } else {
        hint.textContent = `After saving, ${scopeLabel || formatDepartment(dept)} will have ${formatMoney(usage.afterAllocation)} remaining against committed expenses.`;
      }
    }
  }

  function updateStats(list, filteredCount) {
    const total = list.reduce((s, a) => s + (Number(a.amount) || 0), 0);
    const approved = list.filter((a) => a.status === 'approved').length;
    const pending = list.filter((a) => a.status === 'pending').length;
    const depts = new Set(list.map((a) => a.department)).size;
    const allCount = currentAllocations.length;
    const shownCount = filteredCount != null ? filteredCount : list.length;
    const totalEl = document.getElementById('statTotal');
    const totalMeta = document.getElementById('statTotalMeta');

    if (typeof setRdommsCurrencyElement === 'function' && totalEl) {
      setRdommsCurrencyElement(totalEl, total);
    } else if (totalEl) {
      totalEl.textContent = formatMoney(total);
    }
    const approvedEl = document.getElementById('statApproved');
    const pendingEl = document.getElementById('statPending');
    const deptsEl = document.getElementById('statDepts');
    if (approvedEl) approvedEl.textContent = approved;
    if (pendingEl) pendingEl.textContent = pending;
    if (deptsEl) deptsEl.textContent = depts;
    if (totalMeta) {
      totalMeta.textContent = shownCount === allCount
        ? `${allCount} allocation${allCount === 1 ? '' : 's'} in view`
        : `${shownCount} of ${allCount} allocation${allCount === 1 ? '' : 's'} match filters`;
    }
  }

  function resetTableFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('filterStatus').value = '';
    document.getElementById('filterQuarterTable').value = '';
    document.getElementById('filterYearTable').value = '';
    document.getElementById('filterOrganizationTable').value = '';
    document.getElementById('filterDepartmentTable').value = '';
    if (window.RDOMMSFinanceFilters) RDOMMSFinanceFilters.clearFilters();
  }

  function getFiltered() {
    const q = (document.getElementById('searchInput').value || '').toLowerCase();
    const st = document.getElementById('filterStatus').value;
    const fq = document.getElementById('filterQuarterTable').value;
    const fy = document.getElementById('filterYearTable').value;
    const fo = document.getElementById('filterOrganizationTable')?.value || '';
    const fd = document.getElementById('filterDepartmentTable').value;
    return currentAllocations.filter((a) => {
      if (st && a.status !== st) return false;
      if (fq && a.quarter !== fq) return false;
      if (fy && String(a.fiscalYear || a.fiscal_year) !== String(fy)) return false;
      if (fo && normalizeOrganization(a.station) !== fo) return false;
      if (fd && normalizeDepartment(a.department) !== fd) return false;
      if (q) {
        const hay = `${formatDepartment(a.department)} ${formatStation(a.station)} ${a.department || ''} ${a.description || ''} ${formatFundingLink(a)}`.toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
  }

  function updateTableMeta(filteredCount) {
    const meta = document.getElementById('tableMeta');
    if (!meta) return;
    const total = currentAllocations.length;
    if (!total) {
      meta.textContent = 'No allocations recorded yet';
      return;
    }
    if (filteredCount === total) {
      meta.textContent = `Showing all ${total} allocation${total === 1 ? '' : 's'}`;
      return;
    }
    meta.textContent = `Showing ${filteredCount} of ${total} allocation${total === 1 ? '' : 's'}`;
  }

  function renderTable() {
    const list = getFiltered();
    updateTableMeta(list.length);
    updateStats(list, list.length);
    renderFilterChips();
    const tbody = document.getElementById('allocationTableBody');
    if (!list.length) {
      const hiddenByFilters = currentAllocations.length > 0;
      const emptyMsg = hiddenByFilters
        ? `No allocations match the current filters. Clear filters to see all ${currentAllocations.length} record(s).`
        : 'No allocations yet. Use the form above to add your first budget allocation.';
      tbody.innerHTML = `<tr class="empty-row"><td colspan="9"><i class="fas fa-wallet"></i>${escapeHtml(emptyMsg)}</td></tr>`;
      return;
    }

    tbody.innerHTML = list.map((a) => {
      const st = (a.status || 'pending').toLowerCase();
      const id = a.allocation_id || a.id;
      return `<tr data-scope-row="1" data-allocation-id="${escapeHtml(id)}" title="Click to apply scope to form and filters">
        <td>${escapeHtml(formatStation(a.station))}</td>
        <td><strong>${escapeHtml(formatDepartment(a.department))}</strong></td>
        <td>${escapeHtml(formatQuarter(a.quarter))}</td>
        <td>${escapeHtml(a.fiscalYear || a.fiscal_year)}</td>
        <td class="num">${formatMoneyHtml(a.amount)}</td>
        <td>${escapeHtml(formatFundingLink(a))}</td>
        <td>${escapeHtml(a.description || '—')}</td>
        <td><span class="badge ${st}">${escapeHtml(st)}</span></td>
        <td class="actions">
          <button type="button" class="btn btn-sm btn-info" data-edit="${escapeHtml(id)}"><i class="fas fa-pen"></i> Edit</button>
          ${st !== 'approved' ? `<button type="button" class="btn btn-sm btn-success" data-approve="${escapeHtml(id)}"><i class="fas fa-check"></i> Approve</button>` : ''}
          <button type="button" class="btn btn-sm btn-danger" data-delete="${escapeHtml(id)}"><i class="fas fa-trash"></i></button>
        </td>
      </tr>`;
    }).join('');

    tbody.querySelectorAll('[data-allocation-id]').forEach((tr) => {
      tr.addEventListener('click', (event) => {
        if (event.target.closest('button')) return;
        const allocation = currentAllocations.find((item) =>
          String(item.allocation_id || item.id) === tr.getAttribute('data-allocation-id'));
        if (allocation) applyScopeFromRow(allocation);
      });
    });

    tbody.querySelectorAll('[data-edit]').forEach((btn) => {
      btn.addEventListener('click', () => startEdit(btn.getAttribute('data-edit')));
    });
    tbody.querySelectorAll('[data-approve]').forEach((btn) => {
      btn.addEventListener('click', () => approveAllocation(btn.getAttribute('data-approve')));
    });
    tbody.querySelectorAll('[data-delete]').forEach((btn) => {
      btn.addEventListener('click', () => deleteAllocation(btn.getAttribute('data-delete')));
    });
  }

  function refreshCharts() {
    const list = getFiltered();
    const chartSection = document.getElementById('chartPanel');
    const noDataMsg = document.getElementById('noChartsData');

    if (!list.length) {
      if (chartSection) chartSection.style.display = 'none';
      if (noDataMsg) noDataMsg.style.display = 'block';
      return;
    }

    if (chartSection) chartSection.style.display = 'block';
    if (noDataMsg) noDataMsg.style.display = 'none';

    const quarterly = { q1: 0, q2: 0, q3: 0, q4: 0 };
    const byDept = {};
    list.forEach((a) => {
      const amt = Number(a.amount) || 0;
      if (quarterly[a.quarter] != null) quarterly[a.quarter] += amt;
      const deptKey = normalizeDepartment(a.department);
      byDept[deptKey] = (byDept[deptKey] || 0) + amt;
    });

    if (chartQuarterly) chartQuarterly.destroy();
    if (chartDepartment) chartDepartment.destroy();

    chartQuarterly = new Chart(document.getElementById('chartQuarterly'), {
      type: 'bar',
      data: {
        labels: ['Q1', 'Q2', 'Q3', 'Q4'],
        datasets: [{
          label: 'Allocated',
          data: [quarterly.q1, quarterly.q2, quarterly.q3, quarterly.q4],
          backgroundColor: 'rgba(45, 80, 22, 0.78)',
          borderRadius: 8
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { callback: (v) => formatMoney(v) } } }
      }
    });

    const deptKeys = Object.keys(byDept);
    const deptLabels = deptKeys.map((key) => formatDepartment(key));
    const colors = ['#2d5016', '#4a7c28', '#6dbd6d', '#28a745', '#17a2b8', '#64748b', '#94a3b8', '#475569'];
    chartDepartment = new Chart(document.getElementById('chartDepartment'), {
      type: 'doughnut',
      data: {
        labels: deptLabels.length ? deptLabels : ['No data'],
        datasets: [{
          data: deptKeys.length ? deptKeys.map((key) => byDept[key]) : [1],
          backgroundColor: deptKeys.map((_, i) => `${colors[i % colors.length]}cc`),
          borderWidth: 2
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } }
      }
    });
  }

  async function refresh() {
    setLoading(true);
    try {
      currentAllocations = await fetchAllocations();
      setDbStatus(true);
      renderTable();
      refreshCharts();
      await refreshExpenseSync();
      await renderPendingProjectBudgets();
    } catch (error) {
      setDbStatus(false, error.status === 401 ? 'Sign in required' : 'Unable to load');
      notify(error.message || 'Unable to refresh budget allocations.', 'error');
      if (error.status === 401) {
        notify('Sign in with finance access to manage budget allocations.', 'warning');
      }
    } finally {
      setLoading(false);
    }
  }

  function clearForm() {
    editingId = null;
    document.getElementById('allocationForm').reset();
    document.getElementById('quickYear').value = String(PAGE.currentYear || new Date().getFullYear());
    if (PAGE.currentQuarter) document.getElementById('quickQuarter').value = PAGE.currentQuarter;
    initPageDefaults();
    applyScopeToForm({ silent: true });
    fundingLinks?.clear({ skipRefresh: true });
    document.getElementById('editingBanner').classList.remove('show');
    document.getElementById('btnCancelEdit').style.display = 'none';
    document.getElementById('btnSaveLabel').textContent = 'Save Allocation';
  }

  function startEdit(id) {
    const a = currentAllocations.find((x) => String(x.id) === String(id) || String(x.allocation_id) === String(id));
    if (!a) return;
    editingId = a.allocation_id || a.id || id;
    document.getElementById('quickOrganization').value = normalizeOrganization(a.station);
    document.getElementById('quickDepartment').value = normalizeDepartment(a.department);
    document.getElementById('quickQuarter').value = a.quarter;
    document.getElementById('quickYear').value = a.fiscalYear || a.fiscal_year;
    document.getElementById('quickAmount').value = a.amount;
    document.getElementById('quickDescription').value = a.description || '';
    fundingLinks?.setValues(a.linked_type || '', a.linked_id || '', a.linked_label || '', { skipApply: true });
    updateFormExpenseContext();
    document.getElementById('editingBanner').classList.add('show');
    document.getElementById('btnCancelEdit').style.display = 'inline-flex';
    document.getElementById('btnSaveLabel').textContent = 'Update Allocation';
    document.getElementById('allocationForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
    notify('Editing allocation — save when done.', 'info');
  }

  function readFormPayload() {
    const department = document.getElementById('quickDepartment').value;
    const quarter = document.getElementById('quickQuarter').value;
    const fiscalYear = document.getElementById('quickYear').value;
    const station = document.getElementById('quickOrganization')?.value || '';
    const amount = parseFloat(document.getElementById('quickAmount').value);
    const description = document.getElementById('quickDescription').value.trim();
    const link = fundingLinks ? fundingLinks.getPayload() : {};
    return { department, quarter, fiscalYear, station, amount, description, ...link };
  }

  function buildPreviewHtml(data) {
    return `
      <dl class="preview-grid">
        <div><dt>Organization center</dt><dd>${escapeHtml(formatStation(data.station))}</dd></div>
        <div><dt>Department</dt><dd>${escapeHtml(formatDepartment(data.department))}</dd></div>
        <div><dt>Quarter</dt><dd>${escapeHtml(formatQuarter(data.quarter))}</dd></div>
        <div><dt>Fiscal year</dt><dd>${escapeHtml(data.fiscalYear)}</dd></div>
        <div><dt>Amount</dt><dd>${formatMoneyHtml(data.amount)}</dd></div>
        <div><dt>Linked to</dt><dd>${escapeHtml(formatFundingLink(data))}</dd></div>
        <div class="span-2"><dt>Description</dt><dd>${escapeHtml(data.description || '—')}</dd></div>
      </dl>`;
  }

  function closePreviewModal() {
    const modal = document.getElementById('previewModal');
    if (modal) modal.classList.remove('open');
    pendingSavePayload = null;
  }

  function openPreviewModal(data) {
    pendingSavePayload = data;
    const body = document.getElementById('previewBody');
    const title = document.getElementById('previewTitle');
    if (body) body.innerHTML = buildPreviewHtml(data);
    if (title) title.textContent = editingId ? 'Confirm update' : 'Confirm new allocation';
    document.getElementById('previewModal')?.classList.add('open');
  }

  async function commitSave(data) {
    const payload = {
      department: data.department,
      quarter: data.quarter,
      fiscal_year: data.fiscalYear,
      amount: data.amount,
      station: data.station,
      description: data.description,
      linked_type: data.linked_type || null,
      linked_id: data.linked_id || null,
      linked_label: data.linked_label || null
    };

    if (editingId) {
      await updateAllocationRequest(editingId, payload);
      notify('Allocation updated successfully.', 'success');
    } else {
      const duplicate = currentAllocations.find((a) =>
        normalizeDepartment(a.department) === normalizeDepartment(data.department) &&
        a.quarter === data.quarter &&
        String(a.fiscalYear || a.fiscal_year) === String(data.fiscalYear) &&
        normalizeOrganization(a.station) === normalizeOrganization(data.station)
      );
      if (duplicate) {
        if (!confirm('An allocation exists for this department, quarter, and year. Update it?')) return;
        await updateAllocationRequest(duplicate.allocation_id || duplicate.id, payload);
        notify('Allocation updated.', 'success');
      } else {
        await createAllocation(payload);
        notify('Allocation added successfully.', 'success');
      }
    }
    clearForm();
    await refresh();
  }

  function saveAllocation(e) {
    e.preventDefault();
    const data = readFormPayload();
    if (!data.department || !data.quarter || !data.fiscalYear || !data.amount || data.amount <= 0) {
      notify('Please fill all required fields with a valid amount.', 'error');
      return;
    }

    const linkPayload = fundingLinks ? fundingLinks.getPayload() : {};
    const linkContext = window.RDOMMSProjectFinance?.parseProjectLinkFromUrl?.() || null;
    const validation = window.RDOMMSProjectFinance?.validateLinkedFinancePayload
      ? RDOMMSProjectFinance.validateLinkedFinancePayload(
          { ...data, ...linkPayload },
          linkContext
        )
      : { ok: true, warnings: [], errors: [] };

    if (!validation.ok) {
      notify(validation.errors[0] || 'Invalid project link.', 'error');
      return;
    }

    if (validation.warnings?.length) {
      const proceed = confirm(`${validation.warnings.join('\n')}\n\nContinue anyway?`);
      if (!proceed) return;
    }

    openPreviewModal({ ...data, ...linkPayload });
  }

  async function confirmPreviewSave() {
    if (!pendingSavePayload) return;
    const data = pendingSavePayload;
    closePreviewModal();
    try {
      await commitSave(data);
    } catch (error) {
      notify(error.message || 'Unable to save allocation.', 'error');
    }
  }

  async function approveAllocation(id) {
    const a = currentAllocations.find((x) => String(x.id) === String(id) || String(x.allocation_id) === String(id));
    if (!a) return;
    if ((a.status || '').toLowerCase() === 'approved') {
      notify('Already approved.', 'info');
      return;
    }
    if (!confirm(`Approve allocation of ${formatMoney(a.amount)} for ${formatDepartment(a.department)}?`)) return;
    try {
      await approveAllocationRequest(a.allocation_id || a.id);
      notify('Allocation approved.', 'success');
      await refresh();
    } catch (error) {
      notify(error.message || 'Unable to approve allocation.', 'error');
    }
  }

  async function deleteAllocation(id) {
    if (!confirm('Delete this allocation?')) return;
    try {
      await deleteAllocationRequest(id);
      if (String(editingId) === String(id)) clearForm();
      notify('Allocation deleted.', 'success');
      await refresh();
    } catch (error) {
      notify(error.message || 'Unable to delete allocation.', 'error');
    }
  }

  function buildExportRows(list) {
    const headers = ['ID', 'Organization Center', 'Department', 'Quarter', 'Fiscal Year', 'Amount', 'Linked To', 'Description', 'Status', 'Created At'];
    const rows = list.map((a) => [
      a.allocation_id || a.id || '',
      formatStation(a.station),
      formatDepartment(a.department),
      a.quarter,
      a.fiscalYear || a.fiscal_year || '',
      a.amount,
      formatFundingLink(a),
      a.description || '',
      a.status || '',
      a.created_at || a.createdAt || ''
    ]);
    return { headers, rows };
  }

  async function exportData() {
    const format = document.getElementById('exportFormat')?.value || 'pdf';
    const list = getFiltered();
    if (!list.length) {
      notify('No data to export.', 'warning');
      return;
    }
    if (!window.RDOMMSExport) {
      notify('Export module not loaded.', 'error');
      return;
    }
    const { headers, rows } = buildExportRows(list);
    const filename = `rdomms-budget-allocations-${new Date().toISOString().slice(0, 10)}`;
    try {
      await RDOMMSExport.exportData({
        format,
        title: 'Budget Allocations Report',
        subtitle: `Generated: ${new Date().toLocaleString()} · ${list.length} record(s)`,
        headers,
        rows,
        filename,
        pdf: { orientation: 'landscape' }
      });
      notify(`Export complete (${format.toUpperCase()}).`, 'success');
    } catch (error) {
      notify(error.message || 'Export failed.', 'error');
    }
  }

  function bindUi() {
    document.getElementById('allocationForm')?.addEventListener('submit', saveAllocation);
    document.getElementById('btnCancelEdit')?.addEventListener('click', clearForm);
    document.getElementById('btnRefresh')?.addEventListener('click', async () => {
      await refresh();
      notify('Data refreshed.', 'info');
    });
    document.getElementById('btnExport')?.addEventListener('click', exportData);
    document.getElementById('btnPreviewCancel')?.addEventListener('click', closePreviewModal);
    document.getElementById('btnPreviewConfirm')?.addEventListener('click', confirmPreviewSave);
    document.getElementById('previewModal')?.addEventListener('click', (e) => {
      if (e.target.id === 'previewModal') closePreviewModal();
    });
    document.getElementById('searchInput')?.addEventListener('input', renderTable);
    document.getElementById('filterStatus')?.addEventListener('change', renderTable);
    document.getElementById('filterQuarterTable')?.addEventListener('change', () => {
      renderTable();
      refreshExpenseSync();
    });
    document.getElementById('filterYearTable')?.addEventListener('change', () => {
      renderTable();
      refreshExpenseSync();
    });
    document.getElementById('filterOrganizationTable')?.addEventListener('change', () => {
      renderTable();
      refreshExpenseSync();
    });
    document.getElementById('filterDepartmentTable')?.addEventListener('change', () => {
      renderTable();
      refreshExpenseSync();
    });
    document.getElementById('btnClearFilters')?.addEventListener('click', () => {
      resetTableFilters();
      renderTable();
      refreshExpenseSync();
      notify('Filters cleared.', 'info');
    });
    window.addEventListener('rdommsFinanceFiltersChanged', () => {
      renderTable();
      refreshExpenseSync();
    });

    document.getElementById('btnApplyScopeToForm')?.addEventListener('click', () => applyScopeToForm());
    document.getElementById('btnRetryExpenseSync')?.addEventListener('click', () => {
      refreshExpenseSync();
      notify('Expense utilization refreshed.', 'info');
    });
    document.getElementById('btnToggleExpensePanel')?.addEventListener('click', () => {
      const panel = document.getElementById('expenseSyncPanel');
      const btn = document.getElementById('btnToggleExpensePanel');
      if (!panel || !btn) return;
      const collapsed = panel.classList.toggle('collapsed');
      btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      btn.innerHTML = collapsed
        ? '<i class="fas fa-chevron-down"></i> Expand'
        : '<i class="fas fa-chevron-up"></i> Collapse';
    });

    ['quickOrganization', 'quickDepartment', 'quickQuarter', 'quickYear', 'quickAmount'].forEach((id) => {
      document.getElementById(id)?.addEventListener('input', updateFormExpenseContext);
      document.getElementById(id)?.addEventListener('change', updateFormExpenseContext);
    });

    window.addEventListener('rdommsDataLoaded', refresh);
  }

  function boot() {
    populateYears();
    populateFilterYears();
    initPageDefaults();
    if (window.RDOMMSFinanceFundingLinks) {
      fundingLinks = RDOMMSFinanceFundingLinks.create({
        apiBase: '../api/finance/funding-sources.php',
        typeSelectId: 'allocFundingType',
        itemSelectId: 'allocFundingItem',
        hintId: 'allocFundingHint',
        amountFieldId: 'quickAmount',
        filterFieldIds: {
          organization: 'quickOrganization',
          department: 'quickDepartment',
          quarter: 'quickQuarter',
          year: 'quickYear'
        },
        onApplySource(item) {
          if (!item) return;
          const orgEl = document.getElementById('quickOrganization');
          const deptEl = document.getElementById('quickDepartment');
          const amountEl = document.getElementById('quickAmount');
          if (orgEl && item.organization && !orgEl.value) orgEl.value = normalizeOrganization(item.organization);
          if (deptEl && item.department && !deptEl.value) deptEl.value = normalizeDepartment(item.department);
          if (amountEl && Number(item.budget) > 0 && !amountEl.value) amountEl.value = String(item.budget);
          updateFormExpenseContext();
        }
      });
      if (window.RDOMMSProjectFinance) {
        RDOMMSProjectFinance.injectContextBannerStyles();
        const link = RDOMMSProjectFinance.applyDeepLinkToFunding(fundingLinks, { formSelector: '#allocationForm' });
        if (link) RDOMMSProjectFinance.renderProjectContextBanner(link);
      }
    }
    if (window.RDOMMSFinanceFilters) RDOMMSFinanceFilters.loadDefaults();
    renderUserScopeChip();
    renderFilterChips();
    bindUi();
    refresh();
    window.addEventListener('rdommsDataLoaded', () => {
      renderPendingProjectBudgets();
    });
    window.addEventListener('rdommsAlreadyAuthenticated', () => {
      renderPendingProjectBudgets();
    });
  }

  document.addEventListener('DOMContentLoaded', boot);
  window.RDOMMSBudgetAllocation = { refresh, exportData, refreshExpenseSync };
})();
