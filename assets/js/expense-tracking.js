/**
 * RDOMMS Expense Tracking — database-backed finance workspace.
 */
(function () {
  const PAGE = window.RDOMMS_EXPENSE_PAGE || {};
  const API_EXPENSES = PAGE.apiExpenses || '../api/expenses.php';
  const API_BUDGET = PAGE.apiBudget || '../api/budget.php';

  let editingId = null;
  let chartCategory = null;
  let chartDepartment = null;
  let chartBudgetVsExpense = null;
  let currentExpenses = [];
  let currentBudgets = [];
  let currentBudgetOverview = null;
  let pendingSavePayload = null;
  let fundingLinks = null;

  const CATEGORY_LABELS = {
    operational: 'Operational',
    personnel: 'Personnel',
    program: 'Program',
    administrative: 'Administrative',
    capital: 'Capital',
    maintenance: 'Maintenance',
    utilities: 'Utilities'
  };

  const QUARTER_LABELS = { q1: 'Q1', q2: 'Q2', q3: 'Q3', q4: 'Q4' };

  function setLoading(active) {
    const el = document.getElementById('pageLoading');
    if (el) el.classList.toggle('hidden', !active);
  }

  function notify(msg, type) {
    if (window.RDOMMSToast) {
      RDOMMSToast.show(msg, type || 'info', { title: 'Expense Tracking', duration: 4000, allowMultiple: true });
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

  function normalizeExpense(record) {
    return {
      id: record.expense_id || record.id || '',
      date: record.expense_date || record.date || '',
      category: record.category || '',
      department: record.department || '',
      quarter: String(record.quarter || '').toLowerCase(),
      fiscalYear: String(record.fiscal_year || record.fiscalYear || ''),
      amount: Number(record.amount) || 0,
      station: record.station || '',
      description: record.description || '',
      reference: record.reference || '',
      status: String(record.status || 'pending').toLowerCase(),
      linked_type: record.linked_type || null,
      linked_id: record.linked_id || null,
      linked_label: record.linked_label || null,
      createdAt: record.created_at || record.createdAt || '',
      approvedAt: record.approved_at || record.approvedAt || ''
    };
  }

  function normalizeBudget(record) {
    return {
      id: record.allocation_id || record.id || '',
      department: record.department || '',
      quarter: String(record.quarter || '').toLowerCase(),
      fiscalYear: String(record.fiscal_year || record.fiscalYear || ''),
      amount: Number(record.amount) || 0,
      station: record.station || '',
      status: String(record.status || 'pending').toLowerCase()
    };
  }

  async function fetchExpenses() {
    const response = await fetch(API_EXPENSES, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      const err = new Error(payload?.message || 'Failed to load expenses');
      err.status = response.status;
      throw err;
    }
    return (Array.isArray(payload.data) ? payload.data : []).map(normalizeExpense);
  }

  async function fetchBudgets() {
    const response = await fetch(API_BUDGET, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      const err = new Error(payload?.message || 'Failed to load budget allocations');
      err.status = response.status;
      throw err;
    }
    return (Array.isArray(payload.data) ? payload.data : []).map(normalizeBudget);
  }

  async function createExpense(data) {
    const response = await fetch(API_EXPENSES, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify(data)
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to save expense');
    }
    return payload;
  }

  async function updateExpenseRequest(id, data) {
    const response = await fetch(API_EXPENSES, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({ id, ...data })
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to update expense');
    }
    return payload;
  }

  async function approveExpenseRequest(id) {
    const response = await fetch(`${API_EXPENSES}?action=approve`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({ id })
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to approve expense');
    }
    return payload;
  }

  async function deleteExpenseRequest(id) {
    const response = await fetch(`${API_EXPENSES}?id=${encodeURIComponent(id)}`, {
      method: 'DELETE',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to delete expense');
    }
    return payload;
  }

  function formatMoney(n) {
    return typeof formatRdommsCurrency === 'function' ? formatRdommsCurrency(n || 0) : `TSH ${Number(n || 0).toLocaleString('en-US')}`;
  }

  function formatMoneyHtml(n) {
    return typeof formatRdommsCurrencyHtml === 'function' ? formatRdommsCurrencyHtml(n || 0) : formatMoney(n);
  }

  function normalizeDepartment(value) {
    return window.RDOMMSDepartments ? RDOMMSDepartments.getValue(value) : String(value || '');
  }

  function formatDepartment(value) {
    return window.RDOMMSDepartments ? (RDOMMSDepartments.getLabel(value) || '—') : (value || '—');
  }

  function formatCategory(c) {
    return CATEGORY_LABELS[c] || c || '—';
  }

  function formatQuarter(q) {
    return QUARTER_LABELS[q] || q || '—';
  }

  function formatStation(s) {
    if (!s) return 'All centers';
    if (window.RDOMMSOrganizations) {
      return RDOMMSOrganizations.getLabel(s) || s;
    }
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  }

  function normalizeOrganization(value) {
    return window.RDOMMSOrganizations ? RDOMMSOrganizations.getValue(value) : String(value || '');
  }

  function formatFundingLink(record) {
    if (fundingLinks) return fundingLinks.formatLinkLabel(record);
    if (record?.linked_label) return record.linked_label;
    if (!record?.linked_type) return 'General / Department';
    return `${record.linked_type} (${record.linked_id || '—'})`;
  }

  function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function populateYears() {
    const sel = document.getElementById('expenseFiscalYear');
    if (!sel || !window.RDOMMSDates) return;
    RDOMMSDates.fillYearSelect(sel, {
      includeEmpty: true,
      emptyLabel: 'Select fiscal year',
      selectedValue: PAGE.currentYear || RDOMMSDates.currentYear()
    });
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

  function initPageDefaults() {
    const dateInput = document.getElementById('expenseDate');
    if (dateInput && !dateInput.value) {
      dateInput.value = new Date().toISOString().split('T')[0];
    }
    const yearSelect = document.getElementById('expenseFiscalYear');
    if (yearSelect && !yearSelect.value) {
      yearSelect.value = String(PAGE.currentYear || new Date().getFullYear());
    }
    const quarterSelect = document.getElementById('expenseQuarter');
    if (quarterSelect && !quarterSelect.value && PAGE.currentQuarter) {
      quarterSelect.value = PAGE.currentQuarter;
    }

    const defaults = PAGE.userDefaults || null;
    const stored = window.RDOMMSFinanceFilters ? RDOMMSFinanceFilters.readStored() : {};
    const orgEl = document.getElementById('expenseOrganization');
    const deptEl = document.getElementById('expenseDepartment');
    if (orgEl && !orgEl.value) {
      orgEl.value = stored.organization || defaults?.organization || '';
    }
    if (deptEl && !deptEl.value) {
      deptEl.value = stored.department || defaults?.department || '';
    }
    if (quarterSelect && !quarterSelect.value) {
      quarterSelect.value = stored.quarter || PAGE.currentQuarter || '';
    }
    if (yearSelect && !yearSelect.value) {
      yearSelect.value = stored.year || String(PAGE.currentYear || new Date().getFullYear());
    }
  }

  function getScopeFilters() {
    const stored = window.RDOMMSFinanceFilters ? RDOMMSFinanceFilters.readStored() : {};
    return {
      organization: document.getElementById('filterOrganizationTable')?.value
        || document.getElementById('budgetFilterOrganization')?.value
        || stored.organization
        || '',
      department: document.getElementById('filterDepartmentTable')?.value
        || document.getElementById('budgetFilterDepartment')?.value
        || stored.department
        || '',
      quarter: document.getElementById('filterQuarterTable')?.value
        || document.getElementById('budgetFilterQuarter')?.value
        || stored.quarter
        || '',
      year: document.getElementById('filterYearTable')?.value
        || document.getElementById('budgetFilterYear')?.value
        || stored.year
        || ''
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

    if (scope.organization) {
      chips.push({ label: formatStation(scope.organization), type: 'org' });
    }
    if (scope.department) {
      chips.push({ label: formatDepartment(scope.department), type: 'dept' });
    }
    if (scope.quarter) {
      chips.push({ label: formatQuarter(scope.quarter), type: 'quarter' });
    }
    if (scope.year) {
      chips.push({ label: scope.year, type: 'year' });
    }

    if (!chips.length) {
      container.innerHTML = '<span class="filter-chip muted">All records</span>';
      return;
    }

    container.innerHTML = chips.map((chip) =>
      `<span class="filter-chip">${escapeHtml(chip.label)}</span>`
    ).join('');
  }

  function applyScopeToForm(options = {}) {
    const scope = getScopeFilters();
    const orgEl = document.getElementById('expenseOrganization');
    const deptEl = document.getElementById('expenseDepartment');
    const quarterEl = document.getElementById('expenseQuarter');
    const yearEl = document.getElementById('expenseFiscalYear');

    if (orgEl && scope.organization) orgEl.value = scope.organization;
    if (deptEl && scope.department) deptEl.value = scope.department;
    if (quarterEl && scope.quarter) quarterEl.value = scope.quarter;
    if (yearEl && scope.year) yearEl.value = scope.year;

    updateFormBudgetContext();
    if (!options.silent) {
      notify('Active scope applied to the expense form.', 'info');
    }
  }

  function applyScopeFromBudgetRow(row) {
    if (!row) return;
    const view = currentBudgetOverview?.view || 'department';
    const orgEl = document.getElementById('filterOrganizationTable');
    const deptEl = document.getElementById('filterDepartmentTable');
    const scope = getScopeFilters();

    if (view === 'organization' && orgEl) {
      orgEl.value = row.name || '';
    } else if (view === 'department' && deptEl) {
      deptEl.value = row.name || '';
    }

    if (window.RDOMMSFinanceFilters) {
      const source = view === 'organization' ? orgEl : deptEl;
      if (source) RDOMMSFinanceFilters.saveFromElement(source);
    }

    renderTable();
    syncBudgetFiltersFromTable();
    refreshBudgetOverview();
    applyScopeToForm();
  }

  function computeBudgetUsed(expenses, budgets) {
    const approvedBudgets = budgets.filter((b) => b.status === 'approved');
    const totalBudget = approvedBudgets.reduce((s, b) => s + (Number(b.amount) || 0), 0);
    const totalExpenses = expenses
      .filter((e) => e.status === 'approved' || e.status === 'pending')
      .reduce((s, e) => s + (Number(e.amount) || 0), 0);
    if (totalBudget <= 0) return '0.0';
    return Math.min(((totalExpenses / totalBudget) * 100), 999.9).toFixed(1);
  }

  function getBudgetFilterParams() {
    return {
      view: document.getElementById('budgetViewMode')?.value || 'department',
      organization: document.getElementById('budgetFilterOrganization')?.value || '',
      department: document.getElementById('budgetFilterDepartment')?.value || '',
      quarter: document.getElementById('budgetFilterQuarter')?.value || '',
      year: document.getElementById('budgetFilterYear')?.value || ''
    };
  }

  function buildBudgetOverviewUrl(params) {
    const query = new URLSearchParams({ action: 'budget-overview', ...params });
    return `${API_EXPENSES}?${query.toString()}`;
  }

  async function fetchBudgetOverview(params) {
    const response = await fetch(buildBudgetOverviewUrl(params), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to load budget overview');
    }
    return payload.data;
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

  function renderBudgetStatusBadge(status) {
    const badge = document.getElementById('budgetStatusBadge');
    if (!badge) return;
    const tone = status?.tone || 'secondary';
    badge.className = `budget-status-badge ${tone}`;
    badge.textContent = status?.label || 'No data';
  }

  function renderBudgetProgress(summary, label) {
    const fill = document.getElementById('budgetProgressFill');
    const progressLabel = document.getElementById('budgetProgressLabel');
    const hint = document.getElementById('budgetProgressHint');
    const utilizationEl = document.getElementById('budgetUtilizationTotal');
    const remainingEl = document.getElementById('budgetRemainingTotal');

    const utilization = Number(summary?.utilizationPct || 0);
    const width = Math.max(0, Math.min(utilization, 100));
    const tone = summary?.status?.tone || 'secondary';

    if (fill) {
      fill.style.width = `${width}%`;
      fill.className = 'budget-progress-fill';
      if (tone === 'danger') fill.classList.add('danger');
      else if (tone === 'warning' || tone === 'critical') fill.classList.add('warning');
    }

    if (progressLabel) {
      progressLabel.textContent = label ? `Budget utilization for ${label}` : 'Budget utilization';
    }

    if (utilizationEl) {
      utilizationEl.textContent = `${utilization.toFixed(1)}%`;
      utilizationEl.className = `value ${toneClass(tone)}`.trim();
    }

    if (remainingEl) {
      setMoneyElement(remainingEl, summary?.remaining || 0);
      remainingEl.className = `value ${summary?.remaining < 0 ? 'danger' : ''}`.trim();
    }

    if (hint) {
      const allocated = formatMoney(summary?.allocated || 0);
      const spent = formatMoney(summary?.spentCommitted || 0);
      const remaining = formatMoney(summary?.remaining || 0);
      hint.textContent = `Allocated ${allocated} | Committed ${spent} | Remaining ${remaining}`;
    }

    renderBudgetStatusBadge(summary?.status);
  }

  function renderBudgetOverviewTable(rows, view) {
    const tbody = document.getElementById('budgetOverviewBody');
    const header = document.getElementById('budgetTableNameHeader');
    if (header) {
      header.textContent = view === 'project' ? 'Project' : (view === 'organization' ? 'Organization Center' : 'Department');
    }
    if (!tbody) return;

    if (!rows.length) {
      tbody.innerHTML = '<tr class="empty-row"><td colspan="7"><i class="fas fa-chart-line"></i>No budget or expense records match the selected filters.</td></tr>';
      return;
    }

    tbody.innerHTML = rows.map((row, index) => {
      const tone = row.status?.tone || 'secondary';
      const remainingClass = row.remaining < 0 ? 'negative' : (row.remaining > 0 ? 'positive' : 'neutral');
      return `<tr data-budget-row="${index}" data-scope-row="1" title="Click to filter and apply to form">
        <td><strong>${escapeHtml(row.label || row.name || '—')}</strong></td>
        <td class="num">${formatMoneyHtml(row.allocated || 0)}</td>
        <td class="num">${formatMoneyHtml(row.spentApproved || 0)}</td>
        <td class="num">${formatMoneyHtml(row.spentPending || 0)}</td>
        <td class="num ${remainingClass}">${formatMoneyHtml(row.remaining || 0)}</td>
        <td class="num">${Number(row.utilizationPct || 0).toFixed(1)}%</td>
        <td><span class="budget-status-badge ${tone}">${escapeHtml(row.status?.label || 'No data')}</span></td>
      </tr>`;
    }).join('');

    tbody.querySelectorAll('[data-budget-row]').forEach((tr) => {
      tr.addEventListener('click', () => {
        const index = Number(tr.getAttribute('data-budget-row'));
        const row = rows[index];
        if (row) applyScopeFromBudgetRow(row);
      });
    });
  }

  function renderBudgetOverviewChart(rows) {
    const canvas = document.getElementById('chartBudgetVsExpense');
    if (!canvas || typeof Chart === 'undefined') return;

    if (chartBudgetVsExpense) chartBudgetVsExpense.destroy();

    const labels = rows.length
      ? rows.map((row) => row.label || row.name || 'Item')
      : ['No data'];
    const allocatedData = rows.length ? rows.map((row) => Number(row.allocated) || 0) : [0];
    const spentData = rows.length ? rows.map((row) => Number(row.spentCommitted) || 0) : [0];

    chartBudgetVsExpense = new Chart(canvas, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            label: 'Allocated budget',
            data: allocatedData,
            backgroundColor: 'rgba(21, 128, 61, 0.78)',
            borderRadius: 8
          },
          {
            label: 'Committed expenses',
            data: spentData,
            backgroundColor: 'rgba(29, 78, 216, 0.72)',
            borderRadius: 8
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { callback: (value) => formatMoney(value) }
          }
        }
      }
    });
  }

  function renderBudgetOverview(data) {
    currentBudgetOverview = data;
    const summary = data?.summary || {};
    const rows = Array.isArray(data?.rows) ? data.rows : [];
    const subtitle = document.getElementById('budgetOverviewSubtitle');

    setMoneyElement(document.getElementById('budgetAllocatedTotal'), summary.allocated || 0);
    setMoneyElement(document.getElementById('budgetSpentTotal'), summary.spentCommitted || 0);
    renderBudgetProgress(summary, data?.selectionLabel || '');
    renderBudgetOverviewTable(rows, data?.view || 'department');
    renderBudgetOverviewChart(rows.slice(0, 8));

    if (subtitle) {
      subtitle.textContent = `Approved allocations vs committed expenses for ${data?.selectionLabel || 'selected filters'}.`;
    }

    const budgetUsedEl = document.getElementById('statBudgetUsed');
    if (budgetUsedEl) {
      budgetUsedEl.textContent = `${Number(summary.utilizationPct || 0).toFixed(1)}%`;
    }

    updateFormBudgetContext();
  }

  async function refreshBudgetOverview() {
    try {
      const data = await fetchBudgetOverview(getBudgetFilterParams());
      renderBudgetOverview(data);
    } catch (error) {
      renderBudgetOverview({
        view: getBudgetFilterParams().view,
        selectionLabel: 'selected filters',
        summary: {
          allocated: 0,
          spentApproved: 0,
          spentPending: 0,
          spentCommitted: 0,
          remaining: 0,
          variance: 0,
          utilizationPct: 0,
          status: { label: error.message || 'Unable to load', tone: 'danger' }
        },
        rows: []
      });
    }
  }

  function computeDepartmentBudgetRow(department, quarter, year, organization) {
    if (!department || !quarter || !year) return null;

    const deptKey = normalizeDepartment(department);
    const orgKey = organization ? normalizeOrganization(organization) : '';
    const quarterKey = String(quarter).toLowerCase();
    const yearKey = String(year);

    const allocated = currentBudgets
      .filter((item) => item.status === 'approved'
        && normalizeDepartment(item.department) === deptKey
        && String(item.quarter).toLowerCase() === quarterKey
        && String(item.fiscalYear) === yearKey
        && (!orgKey || normalizeOrganization(item.station) === orgKey))
      .reduce((sum, item) => sum + (Number(item.amount) || 0), 0);

    let spentApproved = 0;
    let spentPending = 0;

    currentExpenses.forEach((item) => {
      if (item.status === 'rejected') return;
      if (normalizeDepartment(item.department) !== deptKey) return;
      if (String(item.quarter).toLowerCase() !== quarterKey) return;
      if (String(item.fiscalYear) !== yearKey) return;
      if (orgKey && normalizeOrganization(item.station) !== orgKey) return;
      if (String(item.id) === String(editingId)) return;

      const amount = Number(item.amount) || 0;
      if (item.status === 'approved') spentApproved += amount;
      else spentPending += amount;
    });

    const spentCommitted = spentApproved + spentPending;
    const remaining = allocated - spentCommitted;
    const utilizationPct = allocated > 0 ? Math.round((spentCommitted / allocated) * 1000) / 10 : (spentCommitted > 0 ? 100 : 0);

    return {
      name: department,
      label: formatDepartment(department),
      allocated,
      spentApproved,
      spentPending,
      spentCommitted,
      remaining,
      variance: remaining,
      utilizationPct,
      status: utilizationPct > 100
        ? { label: 'Over budget', tone: 'danger' }
        : utilizationPct >= 85
          ? { label: 'Near limit', tone: 'warning' }
          : allocated > 0
            ? { label: 'On track', tone: 'success' }
            : { label: 'No budget allocated', tone: 'secondary' }
    };
  }

  function findOverviewRowForForm() {
    return computeDepartmentBudgetRow(
      document.getElementById('expenseDepartment')?.value || '',
      document.getElementById('expenseQuarter')?.value || '',
      document.getElementById('expenseFiscalYear')?.value || '',
      document.getElementById('expenseOrganization')?.value || ''
    );
  }

  function updateFormBudgetContext() {
    const row = findOverviewRowForForm();
    const amount = parseFloat(document.getElementById('expenseAmount')?.value || '0') || 0;
    const hint = document.getElementById('formBudgetHint');
    const box = document.getElementById('formBudgetContext');
    const budgetLink = PAGE.links?.budgetAllocation || 'budget-allocation.php';

    if (box) {
      box.classList.remove('is-success', 'is-warning', 'is-danger');
    }

    if (!row) {
      setMoneyElement(document.getElementById('formBudgetAllocated'), 0);
      setMoneyElement(document.getElementById('formBudgetSpent'), 0);
      setMoneyElement(document.getElementById('formBudgetRemaining'), 0);
      setMoneyElement(document.getElementById('formBudgetAfter'), 0);
      if (hint) {
        hint.textContent = 'Select organization center, department, quarter, and fiscal year to preview budget impact before saving.';
      }
      return;
    }

    const afterEntry = Number(row.spentCommitted || 0) + amount;
    const remainingAfter = Number(row.allocated || 0) - afterEntry;

    setMoneyElement(document.getElementById('formBudgetAllocated'), row.allocated || 0);
    setMoneyElement(document.getElementById('formBudgetSpent'), row.spentCommitted || 0);
    setMoneyElement(document.getElementById('formBudgetRemaining'), row.remaining || 0);
    setMoneyElement(document.getElementById('formBudgetAfter'), remainingAfter);

    const scopeLabel = [
      formatStation(document.getElementById('expenseOrganization')?.value || ''),
      formatDepartment(row.name)
    ].filter((part) => part && part !== 'All centers').join(' · ');

    if (hint) {
      if (row.allocated <= 0) {
        hint.innerHTML = `No approved budget found for ${escapeHtml(scopeLabel || formatDepartment(row.name))}. `
          + `<a class="context-link" href="${escapeHtml(budgetLink)}">Create allocation in Budget Allocation</a>.`;
      } else if (remainingAfter < 0) {
        hint.textContent = `Warning: this entry would exceed the approved budget for ${scopeLabel || formatDepartment(row.name)} by ${formatMoney(Math.abs(remainingAfter))}.`;
        if (box) box.classList.add('is-danger');
      } else if (row.allocated > 0 && afterEntry / row.allocated >= 0.85) {
        hint.textContent = `Notice: after saving, ${scopeLabel || formatDepartment(row.name)} will have used ${((afterEntry / row.allocated) * 100).toFixed(1)}% of its approved budget.`;
        if (box) box.classList.add('is-warning');
      } else {
        hint.textContent = `After saving, ${scopeLabel || formatDepartment(row.name)} will remain with ${formatMoney(remainingAfter)} available budget.`;
        if (box) box.classList.add('is-success');
      }
    }
  }

  function populateBudgetFilterYears() {
    const sel = document.getElementById('budgetFilterYear');
    if (!sel || !window.RDOMMSDates) return;
    const current = sel.value;
    RDOMMSDates.fillYearSelect(sel, {
      allYearsLabel: 'All years',
      allYearsValue: '',
      selectedValue: current || PAGE.currentYear || RDOMMSDates.currentYear()
    });
    if (!current) sel.value = String(PAGE.currentYear || RDOMMSDates.currentYear());
  }

  function syncBudgetFiltersFromTable() {
    const year = document.getElementById('filterYearTable')?.value || '';
    const quarter = document.getElementById('filterQuarterTable')?.value || '';
    const organization = document.getElementById('filterOrganizationTable')?.value || '';
    const department = document.getElementById('filterDepartmentTable')?.value || '';
    const yearSel = document.getElementById('budgetFilterYear');
    const quarterSel = document.getElementById('budgetFilterQuarter');
    const orgSel = document.getElementById('budgetFilterOrganization');
    const deptSel = document.getElementById('budgetFilterDepartment');
    if (yearSel && year) yearSel.value = year;
    if (quarterSel && quarter) quarterSel.value = quarter;
    if (orgSel && organization) orgSel.value = organization;
    if (deptSel && department) deptSel.value = department;
  }

  function updateStats(list, filteredCount) {
    const total = list.reduce((s, e) => s + (Number(e.amount) || 0), 0);
    const approved = list.filter((e) => e.status === 'approved').reduce((s, e) => s + (Number(e.amount) || 0), 0);
    const pending = list.filter((e) => e.status === 'pending').reduce((s, e) => s + (Number(e.amount) || 0), 0);
    const pendingCount = list.filter((e) => e.status === 'pending').length;
    const totalEl = document.getElementById('statTotal');
    const approvedEl = document.getElementById('statApproved');
    const pendingEl = document.getElementById('statPending');
    const budgetEl = document.getElementById('statBudgetUsed');
    const totalMeta = document.getElementById('statTotalMeta');
    const budgetMeta = document.getElementById('statBudgetMeta');
    const allCount = currentExpenses.length;
    const shownCount = filteredCount != null ? filteredCount : list.length;

    if (typeof setRdommsCurrencyElement === 'function') {
      if (totalEl) setRdommsCurrencyElement(totalEl, total);
      if (approvedEl) setRdommsCurrencyElement(approvedEl, approved);
      if (pendingEl) setRdommsCurrencyElement(pendingEl, pending);
    } else {
      if (totalEl) totalEl.textContent = formatMoney(total);
      if (approvedEl) approvedEl.textContent = formatMoney(approved);
      if (pendingEl) pendingEl.textContent = formatMoney(pending);
    }
    if (budgetEl && !currentBudgetOverview) {
      budgetEl.textContent = `${computeBudgetUsed(list, currentBudgets)}%`;
    }
    if (totalMeta) {
      totalMeta.textContent = shownCount === allCount
        ? `${allCount} expense${allCount === 1 ? '' : 's'} in view`
        : `${shownCount} of ${allCount} expense${allCount === 1 ? '' : 's'} match filters`;
    }
    if (budgetMeta) {
      budgetMeta.textContent = pendingCount
        ? `${pendingCount} pending approval`
        : 'For selected scope';
    }
  }

  function resetTableFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('filterStatus').value = '';
    document.getElementById('filterCategory').value = '';
    document.getElementById('filterQuarterTable').value = '';
    document.getElementById('filterYearTable').value = '';
    document.getElementById('filterOrganizationTable').value = '';
    document.getElementById('filterDepartmentTable').value = '';
    if (window.RDOMMSFinanceFilters) RDOMMSFinanceFilters.clearFilters();
  }

  function getFiltered() {
    const q = (document.getElementById('searchInput').value || '').toLowerCase();
    const st = document.getElementById('filterStatus').value;
    const cat = document.getElementById('filterCategory').value;
    const fq = document.getElementById('filterQuarterTable').value;
    const fy = document.getElementById('filterYearTable').value;
    const fo = document.getElementById('filterOrganizationTable')?.value || '';
    const fd = document.getElementById('filterDepartmentTable').value;
    return currentExpenses.filter((e) => {
      if (st && e.status !== st) return false;
      if (cat && e.category !== cat) return false;
      if (fq && e.quarter !== fq) return false;
      if (fy && String(e.fiscalYear) !== String(fy)) return false;
      if (fo && normalizeOrganization(e.station) !== fo) return false;
      if (fd && normalizeDepartment(e.department) !== fd) return false;
      if (q) {
        const hay = `${formatCategory(e.category)} ${formatDepartment(e.department)} ${formatStation(e.station)} ${e.description || ''} ${formatFundingLink(e)}`.toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
  }

  function updateTableMeta(filteredCount) {
    const meta = document.getElementById('tableMeta');
    if (!meta) return;
    const total = currentExpenses.length;
    if (!total) {
      meta.textContent = 'No expense records yet';
      return;
    }
    if (filteredCount === total) {
      meta.textContent = `Showing all ${total} expense${total === 1 ? '' : 's'}`;
      return;
    }
    meta.textContent = `Showing ${filteredCount} of ${total} expense${total === 1 ? '' : 's'}`;
  }

  function renderTable() {
    const list = getFiltered();
    updateTableMeta(list.length);
    updateStats(list, list.length);
    renderFilterChips();
    const tbody = document.getElementById('expenseTableBody');
    if (!list.length) {
      const hiddenByFilters = currentExpenses.length > 0;
      const emptyMsg = hiddenByFilters
        ? `No expenses match the current filters. Clear filters to see all ${currentExpenses.length} record(s).`
        : 'No expenses yet. Use the form above to add your first expense record.';
      tbody.innerHTML = `<tr class="empty-row"><td colspan="11"><i class="fas fa-receipt"></i>${escapeHtml(emptyMsg)}</td></tr>`;
      return;
    }

    tbody.innerHTML = list.map((e) => {
      const st = (e.status || 'pending').toLowerCase();
      const rowClass = st === 'pending' ? 'row-near-limit' : '';
      return `<tr class="${rowClass}">
        <td><strong>${escapeHtml(e.date)}</strong></td>
        <td>${escapeHtml(formatCategory(e.category))}</td>
        <td>${escapeHtml(formatStation(e.station))}</td>
        <td>${escapeHtml(formatDepartment(e.department))}</td>
        <td>${escapeHtml(formatQuarter(e.quarter))}</td>
        <td>${escapeHtml(e.fiscalYear || '—')}</td>
        <td class="num">${formatMoneyHtml(e.amount)}</td>
        <td>${escapeHtml(formatFundingLink(e))}</td>
        <td>${escapeHtml(e.description || '—')}${e.reference ? `<br><small style="color:var(--text-secondary)">Ref: ${escapeHtml(e.reference)}</small>` : ''}</td>
        <td><span class="badge ${st}">${escapeHtml(st)}</span></td>
        <td class="actions">
          <button type="button" class="btn btn-sm btn-info" data-edit="${escapeHtml(e.id)}"><i class="fas fa-pen"></i> Edit</button>
          ${st !== 'approved' ? `<button type="button" class="btn btn-sm btn-success" data-approve="${escapeHtml(e.id)}"><i class="fas fa-check"></i> Approve</button>` : ''}
          <button type="button" class="btn btn-sm btn-danger" data-delete="${escapeHtml(e.id)}"><i class="fas fa-trash"></i></button>
        </td>
      </tr>`;
    }).join('');

    tbody.querySelectorAll('[data-edit]').forEach((btn) => {
      btn.addEventListener('click', () => startEdit(btn.getAttribute('data-edit')));
    });
    tbody.querySelectorAll('[data-approve]').forEach((btn) => {
      btn.addEventListener('click', () => approveExpense(btn.getAttribute('data-approve')));
    });
    tbody.querySelectorAll('[data-delete]').forEach((btn) => {
      btn.addEventListener('click', () => deleteExpense(btn.getAttribute('data-delete')));
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

    const byCat = {};
    const byDept = {};
    list.forEach((e) => {
      const amt = Number(e.amount) || 0;
      byCat[e.category] = (byCat[e.category] || 0) + amt;
      const deptKey = normalizeDepartment(e.department);
      byDept[deptKey] = (byDept[deptKey] || 0) + amt;
    });

    if (chartCategory) chartCategory.destroy();
    if (chartDepartment) chartDepartment.destroy();

    const catLabels = Object.keys(byCat);
    const colors = ['#2d5016', '#4a7c28', '#6dbd6d', '#28a745', '#17a2b8', '#64748b', '#94a3b8', '#475569'];
    chartCategory = new Chart(document.getElementById('chartCategory'), {
      type: 'doughnut',
      data: {
        labels: catLabels.length ? catLabels.map(formatCategory) : ['No data'],
        datasets: [{
          data: catLabels.length ? catLabels.map((c) => byCat[c]) : [1],
          backgroundColor: catLabels.map((_, i) => `${colors[i % colors.length]}cc`),
          borderWidth: 2
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } }
      }
    });

    const deptKeys = Object.keys(byDept);
    const deptLabels = deptKeys.map((key) => formatDepartment(key));
    chartDepartment = new Chart(document.getElementById('chartDepartment'), {
      type: 'bar',
      data: {
        labels: deptLabels.length ? deptLabels : ['No data'],
        datasets: [{
          label: 'By department',
          data: deptKeys.length ? deptKeys.map((key) => byDept[key]) : [0],
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
  }

  async function refresh() {
    setLoading(true);
    try {
      const [expenses, budgets] = await Promise.all([fetchExpenses(), fetchBudgets()]);
      currentExpenses = expenses;
      currentBudgets = budgets;
      setDbStatus(true);
      renderTable();
      refreshCharts();
      syncBudgetFiltersFromTable();
      await refreshBudgetOverview();
    } catch (error) {
      setDbStatus(false, error.status === 401 ? 'Sign in required' : 'Unable to load');
      notify(error.message || 'Unable to refresh expenses.', 'error');
      if (error.status === 401) {
        notify('Sign in with finance access to manage expenses.', 'warning');
      }
    } finally {
      setLoading(false);
    }
  }

  function clearForm() {
    editingId = null;
    document.getElementById('expenseForm').reset();
    document.getElementById('expenseFiscalYear').value = String(PAGE.currentYear || new Date().getFullYear());
    initPageDefaults();
    applyScopeToForm({ silent: true });
    fundingLinks?.clear({ skipRefresh: true });
    document.getElementById('editingBanner').classList.remove('show');
    document.getElementById('btnCancelEdit').style.display = 'none';
    document.getElementById('btnSaveLabel').textContent = 'Save Expense';
  }

  function startEdit(id) {
    const e = currentExpenses.find((x) => String(x.id) === String(id));
    if (!e) return;
    editingId = e.id;
    document.getElementById('expenseDate').value = e.date;
    document.getElementById('expenseCategory').value = e.category;
    document.getElementById('expenseOrganization').value = normalizeOrganization(e.station);
    document.getElementById('expenseDepartment').value = normalizeDepartment(e.department);
    document.getElementById('expenseQuarter').value = e.quarter || '';
    document.getElementById('expenseFiscalYear').value = e.fiscalYear || '';
    document.getElementById('expenseAmount').value = e.amount;
    document.getElementById('expenseDescription').value = e.description || '';
    document.getElementById('expenseReference').value = e.reference || '';
    fundingLinks?.setValues(e.linked_type || '', e.linked_id || '', e.linked_label || '', { skipApply: true });
    document.getElementById('editingBanner').classList.add('show');
    document.getElementById('btnCancelEdit').style.display = 'inline-flex';
    document.getElementById('btnSaveLabel').textContent = 'Update Expense';
    document.getElementById('expenseForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
    notify('Editing expense — save when done.', 'info');
  }

  function readFormPayload() {
    return {
      date: document.getElementById('expenseDate').value,
      category: document.getElementById('expenseCategory').value,
      department: document.getElementById('expenseDepartment').value,
      station: document.getElementById('expenseOrganization')?.value || '',
      quarter: document.getElementById('expenseQuarter').value,
      fiscalYear: document.getElementById('expenseFiscalYear').value,
      amount: parseFloat(document.getElementById('expenseAmount').value),
      description: document.getElementById('expenseDescription').value.trim(),
      reference: document.getElementById('expenseReference')?.value.trim() || '',
      ...((fundingLinks && fundingLinks.getPayload()) || {})
    };
  }

  function buildPreviewHtml(data) {
    const row = findOverviewRowForForm();
    const afterRemaining = row
      ? Number(row.allocated || 0) - (Number(row.spentCommitted || 0) + Number(data.amount || 0))
      : null;
    const budgetBlock = row
      ? `<div class="span-2"><dt>Budget remaining after save</dt><dd>${formatMoneyHtml(afterRemaining)}</dd></div>`
      : '';

    return `
      <dl class="preview-grid">
        <div><dt>Date</dt><dd>${escapeHtml(data.date)}</dd></div>
        <div><dt>Category</dt><dd>${escapeHtml(formatCategory(data.category))}</dd></div>
        <div><dt>Organization center</dt><dd>${escapeHtml(formatStation(data.station))}</dd></div>
        <div><dt>Department</dt><dd>${escapeHtml(formatDepartment(data.department))}</dd></div>
        <div><dt>Quarter</dt><dd>${escapeHtml(formatQuarter(data.quarter))}</dd></div>
        <div><dt>Fiscal year</dt><dd>${escapeHtml(data.fiscalYear)}</dd></div>
        <div><dt>Amount</dt><dd>${formatMoneyHtml(data.amount)}</dd></div>
        <div><dt>Reference</dt><dd>${escapeHtml(data.reference || '—')}</dd></div>
        <div><dt>Linked to</dt><dd>${escapeHtml(formatFundingLink(data))}</dd></div>
        <div class="span-2"><dt>Description</dt><dd>${escapeHtml(data.description || '—')}</dd></div>
        ${budgetBlock}
      </dl>`;
  }

  function closePreviewModal() {
    document.getElementById('previewModal')?.classList.remove('open');
    pendingSavePayload = null;
  }

  function openPreviewModal(data) {
    pendingSavePayload = data;
    const body = document.getElementById('previewBody');
    const title = document.getElementById('previewTitle');
    if (body) body.innerHTML = buildPreviewHtml(data);
    if (title) title.textContent = editingId ? 'Confirm update' : 'Confirm new expense';
    document.getElementById('previewModal')?.classList.add('open');
  }

  async function commitSave(data) {
    const payload = {
      expense_date: data.date,
      category: data.category,
      department: data.department,
      quarter: data.quarter,
      fiscal_year: data.fiscalYear,
      amount: data.amount,
      station: data.station,
      description: data.description,
      reference: data.reference,
      linked_type: data.linked_type || null,
      linked_id: data.linked_id || null,
      linked_label: data.linked_label || null
    };

    if (editingId) {
      await updateExpenseRequest(editingId, payload);
      notify('Expense updated successfully.', 'success');
    } else {
      await createExpense(payload);
      notify('Expense added successfully.', 'success');
    }
    clearForm();
    await refresh();
  }

  function saveExpense(e) {
    e.preventDefault();
    const data = readFormPayload();
    if (!data.date || !data.category || !data.department || !data.quarter || !data.fiscalYear || !data.amount || data.amount <= 0) {
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
      notify(error.message || 'Unable to save expense.', 'error');
    }
  }

  async function approveExpense(id) {
    const e = currentExpenses.find((x) => String(x.id) === String(id));
    if (!e) return;
    if ((e.status || '').toLowerCase() === 'approved') {
      notify('Already approved.', 'info');
      return;
    }
    if (!confirm(`Approve expense of ${formatMoney(e.amount)} for ${formatDepartment(e.department)}?`)) return;
    try {
      await approveExpenseRequest(e.id);
      notify('Expense approved successfully.', 'success');
      await refresh();
    } catch (error) {
      notify(error.message || 'Unable to approve expense.', 'error');
    }
  }

  async function deleteExpense(id) {
    if (!confirm('Delete this expense?')) return;
    try {
      await deleteExpenseRequest(id);
      if (String(editingId) === String(id)) clearForm();
      notify('Expense deleted successfully.', 'success');
      await refresh();
    } catch (error) {
      notify(error.message || 'Unable to delete expense.', 'error');
    }
  }

  function buildExportRows(list) {
    const headers = ['ID', 'Date', 'Category', 'Organization Center', 'Department', 'Quarter', 'Fiscal Year', 'Amount', 'Linked To', 'Reference', 'Description', 'Status', 'Created At'];
    const rows = list.map((e) => [
      e.id,
      e.date,
      formatCategory(e.category),
      formatStation(e.station),
      formatDepartment(e.department),
      formatQuarter(e.quarter),
      e.fiscalYear || '',
      e.amount,
      formatFundingLink(e),
      e.reference || '',
      e.description || '',
      e.status,
      e.createdAt || ''
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
    const filename = `rdomms-expenses-${new Date().toISOString().slice(0, 10)}`;
    try {
      await RDOMMSExport.exportData({
        format,
        title: 'Expense Records Report',
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
    document.getElementById('expenseForm')?.addEventListener('submit', saveExpense);
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
    document.getElementById('filterCategory')?.addEventListener('change', renderTable);
    document.getElementById('filterQuarterTable')?.addEventListener('change', () => {
      renderTable();
      syncBudgetFiltersFromTable();
      refreshBudgetOverview();
    });
    document.getElementById('filterYearTable')?.addEventListener('change', () => {
      renderTable();
      syncBudgetFiltersFromTable();
      refreshBudgetOverview();
    });
    document.getElementById('filterOrganizationTable')?.addEventListener('change', () => {
      renderTable();
      syncBudgetFiltersFromTable();
      refreshBudgetOverview();
    });
    document.getElementById('filterDepartmentTable')?.addEventListener('change', () => {
      renderTable();
      syncBudgetFiltersFromTable();
      refreshBudgetOverview();
    });
    document.getElementById('btnClearFilters')?.addEventListener('click', () => {
      resetTableFilters();
      renderTable();
      syncBudgetFiltersFromTable();
      refreshBudgetOverview();
      notify('Filters cleared.', 'info');
    });

    ['budgetViewMode', 'budgetFilterOrganization', 'budgetFilterQuarter', 'budgetFilterYear', 'budgetFilterDepartment'].forEach((id) => {
      document.getElementById(id)?.addEventListener('change', refreshBudgetOverview);
    });

    window.addEventListener('rdommsFinanceFiltersChanged', () => {
      renderTable();
      syncBudgetFiltersFromTable();
      refreshBudgetOverview();
    });

    document.getElementById('btnApplyScopeToForm')?.addEventListener('click', () => applyScopeToForm());
    document.getElementById('btnRetryBudgetOverview')?.addEventListener('click', () => {
      refreshBudgetOverview();
      notify('Budget overview refreshed.', 'info');
    });
    document.getElementById('btnToggleBudgetPanel')?.addEventListener('click', () => {
      const panel = document.getElementById('budgetOverviewPanel');
      const btn = document.getElementById('btnToggleBudgetPanel');
      if (!panel || !btn) return;
      const collapsed = panel.classList.toggle('collapsed');
      btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      btn.innerHTML = collapsed
        ? '<i class="fas fa-chevron-down"></i> Expand'
        : '<i class="fas fa-chevron-up"></i> Collapse';
    });

    ['expenseOrganization', 'expenseDepartment', 'expenseQuarter', 'expenseFiscalYear', 'expenseAmount'].forEach((id) => {
      document.getElementById(id)?.addEventListener('input', updateFormBudgetContext);
      document.getElementById(id)?.addEventListener('change', updateFormBudgetContext);
    });

    window.addEventListener('rdommsDataLoaded', refresh);
  }

  function boot() {
    populateYears();
    populateFilterYears();
    populateBudgetFilterYears();
    if (window.RDOMMSFinanceFilters) RDOMMSFinanceFilters.loadDefaults();
    renderUserScopeChip();
    renderFilterChips();
    const quarterSel = document.getElementById('budgetFilterQuarter');
    if (quarterSel && PAGE.currentQuarter) quarterSel.value = PAGE.currentQuarter;
    initPageDefaults();
    if (window.RDOMMSFinanceFundingLinks) {
      fundingLinks = RDOMMSFinanceFundingLinks.create({
        apiBase: '../api/finance/funding-sources.php',
        typeSelectId: 'expenseFundingType',
        itemSelectId: 'expenseFundingItem',
        hintId: 'expenseFundingHint',
        amountFieldId: 'expenseAmount',
        filterFieldIds: {
          organization: 'expenseOrganization',
          department: 'expenseDepartment',
          quarter: 'expenseQuarter',
          year: 'expenseFiscalYear'
        },
        onApplySource(item) {
          if (!item) return;
          const orgEl = document.getElementById('expenseOrganization');
          const deptEl = document.getElementById('expenseDepartment');
          const amountEl = document.getElementById('expenseAmount');
          if (orgEl && item.organization && !orgEl.value) orgEl.value = normalizeOrganization(item.organization);
          if (deptEl && item.department && !deptEl.value) deptEl.value = normalizeDepartment(item.department);
          if (amountEl && Number(item.budget) > 0 && !amountEl.value) amountEl.value = String(item.budget);
          updateFormBudgetContext();
        }
      });
      if (window.RDOMMSProjectFinance) {
        RDOMMSProjectFinance.injectContextBannerStyles();
        const link = RDOMMSProjectFinance.applyDeepLinkToFunding(fundingLinks, { formSelector: '#expenseForm' });
        if (link) RDOMMSProjectFinance.renderProjectContextBanner(link);
      }
    }
    bindUi();
    refresh();
  }

  document.addEventListener('DOMContentLoaded', boot);
  window.RDOMMSExpenseTracking = { refresh, exportData, refreshBudgetOverview };
})();
