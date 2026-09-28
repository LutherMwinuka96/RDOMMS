/**
 * RDOMMS Variance Analysis — budget vs actual from database with local fallback.
 */
(function () {
  const FINANCIAL_CATEGORIES = ['financial', 'finance', 'budget', 'expenditure', 'spending', 'cost'];
  const PAGE = window.RDOMMS_VARIANCE_PAGE || {};
  const API_BASE = PAGE.apiBase || '../api/reports.php';

  let currentView = 'department';
  let chartCompare = null;
  let chartTrend = null;
  let lastRows = [];
  let lastComputedRows = [];
  let apiReady = false;
  let cachedStores = null;
  let dataSource = 'local';

  function notify(msg, type = 'info') {
    if (window.RDOMMSToast) {
      RDOMMSToast.show(msg, type, { title: 'Variance Analysis', duration: 4000, allowMultiple: true });
    }
  }

  function formatDateInput(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  }

  function applyDatePreset(preset) {
    const start = document.getElementById('startDate');
    const end = document.getElementById('endDate');
    if (!start || !end) return;

    const now = new Date();
    let startDate = new Date(now);
    let endDate = new Date(now);

    if (preset === 'this-month') {
      startDate = new Date(now.getFullYear(), now.getMonth(), 1);
      endDate = new Date(now.getFullYear(), now.getMonth() + 1, 0);
    } else if (preset === 'last-month') {
      startDate = new Date(now.getFullYear(), now.getMonth() - 1, 1);
      endDate = new Date(now.getFullYear(), now.getMonth(), 0);
    } else if (preset === 'this-year') {
      startDate = new Date(now.getFullYear(), 0, 1);
      endDate = new Date(now.getFullYear(), 11, 31);
    } else if (preset === 'ytd') {
      startDate = new Date(now.getFullYear(), 0, 1);
      endDate = now;
    }

    start.value = formatDateInput(startDate);
    end.value = formatDateInput(endDate);

    document.querySelectorAll('[data-preset]').forEach((btn) => {
      btn.classList.toggle('is-active', btn.getAttribute('data-preset') === preset);
    });
  }

  function setLoading(active) {
    const overlay = document.getElementById('loadingOverlay');
    if (!overlay) return;
    overlay.classList.toggle('active', !!active);
    overlay.setAttribute('aria-hidden', active ? 'false' : 'true');
  }

  async function apiRequest(action, options = {}) {
    const params = new URLSearchParams({ action, ...(options.params || {}) });
    const url = `${API_BASE}?${params.toString()}`;
    const response = await fetch(url, {
      method: options.method || 'GET',
      credentials: 'same-origin',
      cache: options.cache || 'default',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      const detail = payload?.detail ? ` (${payload.detail})` : '';
      const err = new Error((payload?.message || 'Request failed') + detail);
      err.status = response.status;
      throw err;
    }
    return payload.data;
  }

  function normalizeText(value) {
    return String(value ?? '').trim().toLowerCase();
  }

  function normalizeQuarter(value) {
    const q = normalizeText(value);
    if (!q) return '';
    return q.startsWith('q') ? q : `q${q}`;
  }

  function normalizeRecord(record) {
    if (!record || typeof record !== 'object') return record;
    return {
      ...record,
      department: record.department || record.departmentName,
      fiscalYear: record.fiscalYear || record.fiscal_year,
      fiscal_year: record.fiscal_year || record.fiscalYear,
      quarter: record.quarter,
      amount: Number(record.amount || 0),
      budget: Number(record.budget || 0),
      total_budget: Number(record.total_budget || record.totalBudget || 0),
      totalBudget: Number(record.totalBudget || record.total_budget || 0),
      createdAt: record.createdAt || record.created_at,
      updatedAt: record.updatedAt || record.updated_at,
      expenseDate: record.expenseDate || record.expense_date || record.date,
      expense_date: record.expense_date || record.expenseDate || record.date,
      name: record.name || record.fullname || record.projectName || record.title,
      sector: record.sector || record.department,
      targetValue: Number(record.targetValue || record.target_value || 0),
      currentValue: Number(record.currentValue || record.current_value || 0),
      activities: record.activities || [],
      project_id: record.project_id || record.projectId || null,
      projectId: record.projectId || record.project_id || null,
      linked_type: record.linked_type || record.linkedType || null,
      linked_id: record.linked_id || record.linkedId || null,
      linked_label: record.linked_label || record.linkedLabel || null,
      linkedType: record.linkedType || record.linked_type || null,
      linkedId: record.linkedId || record.linked_id || null,
      linkedLabel: record.linkedLabel || record.linked_label || null,
      status: record.status || ''
    };
  }

  function loadJson(key, fallback = []) {
    try {
      const raw = localStorage.getItem(key);
      if (!raw) return fallback;
      const parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) return fallback;
      return parsed.map(normalizeRecord);
    } catch (_) {
      return fallback;
    }
  }

  function loadLocalStores() {
    const projects = loadJson('projects', []);
    const legacyProjects = loadJson('rd_projects', []);
    return {
      allocations: loadJson('budgetAllocations', loadJson('rdomms_budgetAllocations', [])),
      expenses: loadJson('expenses', loadJson('rdomms_expenses', [])),
      allProjects: projects.length ? projects : legacyProjects,
      targets: loadJson('targets', []),
      monthlyPlans: loadJson('monthlyPlans', []),
      quarterlyPlans: loadJson('quarterlyPlans', []),
      annualPlans: loadJson('annualPlans', [])
    };
  }

  function normalizeStores(raw) {
    return {
      allocations: (raw.allocations || []).map(normalizeRecord),
      expenses: (raw.expenses || []).map(normalizeRecord),
      allProjects: (raw.allProjects || raw.projects || []).map(normalizeRecord),
      targets: (raw.targets || []).map(normalizeRecord),
      monthlyPlans: (raw.monthlyPlans || []).map(normalizeRecord),
      quarterlyPlans: (raw.quarterlyPlans || []).map(normalizeRecord),
      annualPlans: (raw.annualPlans || []).map(normalizeRecord)
    };
  }

  async function fetchVarianceData() {
    const data = await apiRequest('variance', { cache: 'no-store', params: { _t: String(Date.now()) } });
    return normalizeStores({
      allocations: data.allocations || [],
      expenses: data.expenses || [],
      allProjects: data.projects || [],
      targets: data.targets || [],
      monthlyPlans: data.monthlyPlans || [],
      quarterlyPlans: data.quarterlyPlans || [],
      annualPlans: data.annualPlans || []
    });
  }

  async function loadDataStores(force = false) {
    if (cachedStores && !force) return cachedStores;

    try {
      const remote = await fetchVarianceData();
      cachedStores = remote;
      dataSource = 'database';
      apiReady = true;
      return cachedStores;
    } catch (error) {
      apiReady = false;
      cachedStores = loadLocalStores();
      dataSource = 'local';
      if (error.status === 401) {
        notify('Sign in to load live variance data from the database.', 'warning');
      }
      return cachedStores;
    }
  }

  function parseDate(str) {
    if (!str) return null;
    const d = new Date(str);
    return Number.isNaN(d.getTime()) ? null : d;
  }

  function inDateRange(dateStr, start, end) {
    if (!start && !end) return true;
    const d = parseDate(dateStr);
    if (!d) return false;
    if (start && d < start) return false;
    if (end && d > end) return false;
    return true;
  }

  function matchDepartment(recordDept, filterDept) {
    if (!filterDept) return true;
    const recordValue = window.RDOMMSDepartments
      ? RDOMMSDepartments.getValue(recordDept)
      : recordDept;
    const filterValue = window.RDOMMSDepartments
      ? RDOMMSDepartments.getValue(filterDept)
      : filterDept;
    const a = normalizeText(recordValue || recordDept);
    const b = normalizeText(filterValue || filterDept);
    if (!a) return false;
    if (a === b) return true;
    const labelA = window.RDOMMSDepartments
      ? normalizeText(RDOMMSDepartments.getLabel(recordValue || recordDept))
      : a;
    const labelB = window.RDOMMSDepartments
      ? normalizeText(RDOMMSDepartments.getLabel(filterValue || filterDept))
      : b;
    if (labelA && labelB && labelA === labelB) return true;
    return a.includes(b) || b.includes(a);
  }

  function matchStation(recordStation, filterStation) {
    if (!filterStation) return true;
    const recordValue = window.RDOMMSOrganizations
      ? RDOMMSOrganizations.getValue(recordStation)
      : recordStation;
    const filterValue = window.RDOMMSOrganizations
      ? RDOMMSOrganizations.getValue(filterStation)
      : filterStation;
    const a = normalizeText(recordValue || recordStation);
    const b = normalizeText(filterValue || filterStation);
    if (!a) return false;
    if (a === b) return true;
    const labelA = window.RDOMMSOrganizations
      ? normalizeText(RDOMMSOrganizations.getLabel(recordValue || recordStation))
      : a;
    const labelB = window.RDOMMSOrganizations
      ? normalizeText(RDOMMSOrganizations.getLabel(filterValue || filterStation))
      : b;
    if (labelA && labelB && labelA === labelB) return true;
    return a.includes(b) || b.includes(a);
  }

  function formatMoney(n) {
    if (typeof window.formatRdommsCurrency === 'function') {
      return formatRdommsCurrency(n || 0);
    }
    return `TSH ${Number(n || 0).toLocaleString('en-US')}`;
  }

  function formatMoneyHtml(n) {
    if (typeof window.formatRdommsCurrencyHtml === 'function') {
      return formatRdommsCurrencyHtml(n || 0);
    }
    return formatMoney(n);
  }

  function getStatValueSizeClass(value) {
    const compact = String(value ?? '').replace(/\s/g, '');
    const len = compact.length;
    if (len > 16) return 'stat-value--tight';
    if (len > 12) return 'stat-value--compact';
    if (len > 9) return 'stat-value--medium';
    return '';
  }

  function applyStatValueSizeClass(el, displayValue) {
    if (!el) return;
    el.classList.remove('stat-value--medium', 'stat-value--compact', 'stat-value--tight');
    const sizeClass = getStatValueSizeClass(displayValue);
    if (sizeClass) el.classList.add(sizeClass);
  }

  function resetStatValueFit(el) {
    if (!el) return;
    el.style.fontSize = '';
    el.style.transform = '';
    el.style.transformOrigin = '';
    el.style.display = '';
    el.style.width = '';
    el.style.whiteSpace = 'nowrap';
    el.style.overflow = 'visible';
    el.style.textOverflow = '';
    el.style.wordBreak = '';
    el.style.overflowWrap = '';
  }

  function setMoneyElement(el, n) {
    if (!el) return;
    resetStatValueFit(el);
    const display = formatMoney(n || 0);
    applyStatValueSizeClass(el, display);
    if (typeof window.setRdommsCurrencyElement === 'function') {
      setRdommsCurrencyElement(el, n || 0);
    } else {
      el.textContent = display;
    }
    scheduleVarianceStatFit();
  }

  let varianceStatFitFrame = null;

  function getStatValueAvailableWidth(element) {
    const wrap = element.closest('.stat-value-wrap');
    const card = element.closest('.stat-card') || element.closest('.summary-card');
    const box = wrap || card;
    if (!box) return 0;
    const styles = window.getComputedStyle(box);
    const padX = parseFloat(styles.paddingLeft) + parseFloat(styles.paddingRight);
    return Math.max(0, box.clientWidth - (wrap ? 0 : padX));
  }

  function fitVarianceStatText(element) {
    if (!element) return;

    resetStatValueFit(element);

    const available = getStatValueAvailableWidth(element);
    if (available <= 0) return;

    const isMobile = window.matchMedia('(max-width: 768px)').matches;
    let maxSize = 24;
    if (element.classList.contains('stat-value--tight')) maxSize = 13;
    else if (element.classList.contains('stat-value--compact')) maxSize = 16;
    else if (element.classList.contains('stat-value--medium')) maxSize = 19;
    if (isMobile) maxSize = Math.min(maxSize, 18);

    const minSize = 7;
    let size = maxSize;

    element.style.display = 'inline-block';
    element.style.fontSize = `${size}px`;
    element.style.lineHeight = '1.15';
    element.style.whiteSpace = 'nowrap';

    while (element.scrollWidth > available && size > minSize) {
      size -= 0.5;
      element.style.fontSize = `${size}px`;
    }

    if (element.scrollWidth > available) {
      const scale = available / element.scrollWidth;
      element.style.transform = `scale(${Math.max(scale, 0.55)})`;
      element.style.transformOrigin = 'left center';
    }
  }

  function fitVarianceStatCards() {
    const grid = document.querySelector('.summary-grid') || document.querySelector('.stats-grid');
    if (!grid) return;
    grid.querySelectorAll('.stat-value').forEach((el) => fitVarianceStatText(el));
  }

  function scheduleVarianceStatFit() {
    if (varianceStatFitFrame) {
      cancelAnimationFrame(varianceStatFitFrame);
    }
    varianceStatFitFrame = requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        varianceStatFitFrame = null;
        fitVarianceStatCards();
      });
    });
  }

  function initVarianceStatCardFit() {
    const grid = document.querySelector('.summary-grid') || document.querySelector('.stats-grid');
    if (!grid || grid.dataset.fitBound === '1') return;
    grid.dataset.fitBound = '1';
    window.addEventListener('resize', scheduleVarianceStatFit);
    if (window.ResizeObserver) {
      const observer = new ResizeObserver(() => scheduleVarianceStatFit());
      observer.observe(grid);
      grid.querySelectorAll('.summary-card, .stat-card').forEach((card) => observer.observe(card));
    }
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(() => scheduleVarianceStatFit()).catch(() => {});
    }
  }

  function getFilters() {
    const start = parseDate(document.getElementById('startDate')?.value);
    const end = parseDate(document.getElementById('endDate')?.value);
    if (end) end.setHours(23, 59, 59, 999);
    return {
      start,
      end,
      department: document.getElementById('filterDept')?.value || '',
      station: document.getElementById('filterStation')?.value || '',
      quarter: document.getElementById('filterQuarter')?.value || '',
      year: document.getElementById('filterYear')?.value || ''
    };
  }

  function getDataStores() {
    return cachedStores || loadLocalStores();
  }

  function getAllocationFiscalYear(allocation) {
    const year = parseInt(allocation?.fiscalYear || allocation?.fiscal_year, 10);
    return Number.isFinite(year) && year > 0 ? year : null;
  }

  function getQuarterBounds(year, quarterRaw) {
    const quarter = normalizeQuarter(quarterRaw) || 'q1';
    const quarterNum = Math.max(1, Math.min(4, parseInt(quarter.replace('q', ''), 10) || 1));
    const startMonth = (quarterNum - 1) * 3;
    return {
      start: new Date(year, startMonth, 1),
      end: new Date(year, startMonth + 3, 0, 23, 59, 59, 999)
    };
  }

  function allocationMatchesPeriod(allocation, f) {
    const year = getAllocationFiscalYear(allocation);
    if (f.year && (!year || String(year) !== String(f.year))) return false;
    if (f.quarter && normalizeQuarter(allocation.quarter) !== normalizeQuarter(f.quarter)) return false;

    if (!f.start && !f.end) return true;
    if (!year) return true;

    const bounds = getQuarterBounds(year, allocation.quarter || 'q1');
    const rangeStart = f.start || new Date(0);
    const rangeEnd = f.end || new Date(8640000000000000);
    return bounds.start <= rangeEnd && bounds.end >= rangeStart;
  }

  function getDepartmentGroupKey(dept) {
    if (!dept) return 'unassigned';
    if (window.RDOMMSDepartments) {
      return RDOMMSDepartments.getValue(dept) || normalizeText(dept);
    }
    return normalizeText(dept);
  }

  function getDepartmentDisplayName(dept) {
    if (!dept) return 'Unassigned';
    if (window.RDOMMSDepartments) {
      return RDOMMSDepartments.getLabel(dept) || dept;
    }
    return dept;
  }

  function filterAllocations(list, f) {
    return list.filter((a) => {
      if (!matchDepartment(a.department, f.department)) return false;
      if (!matchStation(a.station, f.station)) return false;
      return allocationMatchesPeriod(a, f);
    });
  }

  function filterExpenses(list, f) {
    return list.filter((e) => {
      if (!matchDepartment(e.department, f.department)) return false;
      if (!matchStation(e.station, f.station)) return false;
      if (f.quarter && normalizeQuarter(e.quarter) !== normalizeQuarter(f.quarter)) return false;
      if (f.year && String(e.fiscalYear || e.fiscal_year) !== String(f.year)) return false;
      const dateValue = e.expenseDate || e.expense_date || e.date || e.createdAt;
      return inDateRange(dateValue, f.start, f.end);
    });
  }

  function isRejectedFinanceRecord(record) {
    return normalizeText(record?.status) === 'rejected';
  }

  function isApprovedAllocation(record) {
    const status = normalizeText(record?.status);
    return !status || status === 'approved';
  }

  function filterCommittedExpenses(list, f) {
    return filterExpenses(list, f).filter((e) => !isRejectedFinanceRecord(e));
  }

  function filterProjectLinkedExpenses(list, f) {
    return list.filter((e) => {
      if (isRejectedFinanceRecord(e)) return false;
      if (f.quarter && normalizeQuarter(e.quarter) !== normalizeQuarter(f.quarter)) return false;
      if (f.year && String(e.fiscalYear || e.fiscal_year) !== String(f.year)) return false;
      const dateValue = e.expenseDate || e.expense_date || e.date || e.createdAt;
      return inDateRange(dateValue, f.start, f.end);
    });
  }

  function filterApprovedAllocations(list, f) {
    return filterAllocations(list, f).filter((a) => isApprovedAllocation(a));
  }

  function getProjectLinkIds(project) {
    const ids = new Set();
    const add = (value) => {
      const token = String(value ?? '').trim();
      if (token) ids.add(token);
    };
    add(project.project_id);
    add(project.projectId);
    add(project.id);
    return ids;
  }

  function expenseLinksToProject(expense, project) {
    const linkedType = normalizeText(expense.linked_type || expense.linkedType);
    if (linkedType !== 'project') return false;
    const linkedId = String(expense.linked_id || expense.linkedId || '').trim();
    if (!linkedId) return false;
    return getProjectLinkIds(project).has(linkedId);
  }

  function sumLinkedProjectExpenses(expenses, project) {
    return expenses
      .filter((expense) => expenseLinksToProject(expense, project))
      .reduce((sum, expense) => sum + (Number(expense.amount) || 0), 0);
  }

  function varianceStatus(budget, actual) {
    if (budget <= 0 && actual <= 0) return { label: 'No data', cls: 'secondary' };
    if (budget <= 0 && actual > 0) return { label: 'Unbudgeted', cls: 'danger' };
    const pct = (actual / budget) * 100;
    if (pct > 100) return { label: 'Over budget', cls: 'danger' };
    if (pct >= 85) return { label: 'On track', cls: 'success' };
    if (pct >= 50) return { label: 'Under used', cls: 'warning' };
    return { label: 'Low spend', cls: 'info' };
  }

  function sumPlanningBudget(plans) {
    return plans.reduce((total, plan) => {
      const direct = Number(plan.total_budget || plan.totalBudget || 0);
      if (direct > 0) return total + direct;
      if (Array.isArray(plan.activities)) {
        return total + plan.activities.reduce((sum, act) => sum + Number(act?.budget || 0), 0);
      }
      return total;
    }, 0);
  }

  function buildDepartmentRows(f, stores) {
    const allocations = filterApprovedAllocations(stores.allocations, f);
    const expenses = filterCommittedExpenses(stores.expenses, f);
    const map = {};

    allocations.forEach((a) => {
      const key = getDepartmentGroupKey(a.department);
      const label = getDepartmentDisplayName(a.department);
      if (!map[key]) map[key] = { name: label, budget: 0, actual: 0 };
      map[key].budget += Number(a.amount) || 0;
    });

    expenses.forEach((e) => {
      const key = getDepartmentGroupKey(e.department);
      const label = getDepartmentDisplayName(e.department);
      if (!map[key]) map[key] = { name: label, budget: 0, actual: 0 };
      map[key].actual += Number(e.amount) || 0;
    });

    return Object.values(map)
      .sort((a, b) => a.name.localeCompare(b.name))
      .map((row) => {
        const variance = row.budget - row.actual;
        const pct = row.budget > 0 ? (variance / row.budget) * 100 : 0;
        return { ...row, variance, pct, status: varianceStatus(row.budget, row.actual) };
      });
  }

  function buildProjectRows(f, stores) {
    const expenses = filterProjectLinkedExpenses(stores.expenses, f);
    return stores.allProjects
      .filter((p) => matchDepartment(p.sector || p.department, f.department))
      .filter((p) => matchStation(p.organization || p.station, f.station))
      .map((p) => {
        const budget = Number(p.budget) || 0;
        const name = p.name || 'Unnamed project';
        const sector = p.sector || p.department || '—';
        const actual = sumLinkedProjectExpenses(expenses, p);
        const variance = budget - actual;
        const pct = budget > 0 ? (variance / budget) * 100 : 0;
        return { name, sector, budget, actual, variance, pct, status: varianceStatus(budget, actual) };
      });
  }

  function buildPlanningRows(f, stores) {
    const monthly = stores.monthlyPlans;
    const quarterly = stores.quarterlyPlans;
    const annual = stores.annualPlans;
    const monthlyBudget = sumPlanningBudget(monthly);
    const quarterlyBudget = sumPlanningBudget(quarterly);
    const annualBudget = sumPlanningBudget(annual);
    const expenses = filterCommittedExpenses(stores.expenses, f);
    const totalActual = expenses.reduce((sum, e) => sum + (Number(e.amount) || 0), 0);
    const totalPlanned = monthlyBudget + quarterlyBudget + annualBudget;
    const rows = [];

    if (monthly.length || monthlyBudget) {
      rows.push({
        name: 'Monthly plans',
        source: `${monthly.length} plan(s)`,
        budget: monthlyBudget,
        actual: totalPlanned ? totalActual * (monthlyBudget / totalPlanned) : 0
      });
    }
    if (quarterly.length || quarterlyBudget) {
      rows.push({
        name: 'Quarterly plans',
        source: `${quarterly.length} plan(s)`,
        budget: quarterlyBudget,
        actual: totalPlanned ? totalActual * (quarterlyBudget / totalPlanned) : 0
      });
    }
    if (annual.length || annualBudget) {
      rows.push({
        name: 'Annual plans',
        source: `${annual.length} plan(s)`,
        budget: annualBudget,
        actual: totalPlanned ? totalActual * (annualBudget / totalPlanned) : 0
      });
    }

    if (!rows.length) {
      rows.push({ name: 'No planning data', source: 'Add plans in Planning', budget: 0, actual: totalActual });
    }

    return rows.map((row) => {
      const variance = row.budget - row.actual;
      const pct = row.budget > 0 ? (variance / row.budget) * 100 : 0;
      return { ...row, variance, pct, status: varianceStatus(row.budget, row.actual) };
    });
  }

  function isFinancialTarget(t) {
    const cat = normalizeText(t.category);
    const name = normalizeText(t.name);
    return FINANCIAL_CATEGORIES.some((key) => cat.includes(key) || name.includes(key));
  }

  function buildTargetRows(stores) {
    return stores.targets
      .filter(isFinancialTarget)
      .map((t) => {
        const budget = Number(t.targetValue) || 0;
        const actual = Number(t.currentValue) || 0;
        const variance = budget - actual;
        const pct = budget > 0 ? (variance / budget) * 100 : 0;
        return {
          name: t.name || 'Target',
          period: t.period || '—',
          budget,
          actual,
          variance,
          pct,
          status: varianceStatus(budget, actual)
        };
      });
  }

  function populateFilterOptions(stores) {
    const deptSelect = document.getElementById('filterDept');
    const stationSelect = document.getElementById('filterStation');
    const yearSelect = document.getElementById('filterYear');
    if (!deptSelect || !yearSelect) return;

    const currentDept = deptSelect.value;
    if (window.RDOMMSDepartments) {
      RDOMMSDepartments.fillSelect(deptSelect, {
        includeEmpty: true,
        emptyLabel: 'All departments',
        selectedValue: currentDept
      });
    }

    if (stationSelect && window.RDOMMSOrganizations) {
      const currentStation = stationSelect.value;
      RDOMMSOrganizations.fillSelect(stationSelect, {
        includeEmpty: true,
        emptyLabel: 'All stations',
        selectedValue: currentStation
      });
    }

    const currentYear = yearSelect.value;
    if (window.RDOMMSDates) {
      RDOMMSDates.fillYearSelect(yearSelect, {
        allYearsLabel: 'All years',
        allYearsValue: '',
        selectedValue: currentYear || '',
      });
      if (!currentYear) yearSelect.value = '';
    }
  }

  function updateModuleCounts(stores, f) {
    const filters = f || getFilters();
    const filteredAllocations = filterApprovedAllocations(stores.allocations, filters);
    const filteredExpenses = filterCommittedExpenses(stores.expenses, filters);
    const set = (id, value) => {
      const el = document.getElementById(id);
      if (el) el.textContent = value;
    };
    set('countAllocations', filteredAllocations.length);
    set('countExpenses', filteredExpenses.length);
    set('countProjects', stores.allProjects.length);
    set('countTargets', stores.targets.filter(isFinancialTarget).length);
  }

  function updateStats(rows) {
    const budget = rows.reduce((sum, row) => sum + (row.budget || 0), 0);
    const actual = rows.reduce((sum, row) => sum + (row.actual || 0), 0);
    const variance = budget - actual;
    const util = budget > 0 ? Math.round((actual / budget) * 100) : 0;

    const elBudget = document.getElementById('statBudget');
    const elActual = document.getElementById('statActual');
    const elVar = document.getElementById('statVariance');
    const elUtil = document.getElementById('statUtil');

    if (elBudget) setMoneyElement(elBudget, budget);
    if (elActual) setMoneyElement(elActual, actual);
    if (elVar) {
      setMoneyElement(elVar, variance);
      const tone = variance >= 0 ? 'success' : 'danger';
      const sizeClass = getStatValueSizeClass(formatMoney(variance));
      elVar.className = ['stat-value', tone, sizeClass].filter(Boolean).join(' ');
    }
    if (elUtil) {
      const utilDisplay = `${util}%`;
      resetStatValueFit(elUtil);
      applyStatValueSizeClass(elUtil, utilDisplay);
      elUtil.textContent = utilDisplay;
      const tone = util > 100 ? 'danger' : util > 85 ? 'warning' : 'success';
      elUtil.className = ['stat-value', tone, getStatValueSizeClass(utilDisplay)].filter(Boolean).join(' ');
    }

    scheduleVarianceStatFit();

    const hints = {
      department: ['Approved allocations', 'Committed expenses'],
      project: ['Project budgets', 'Linked project expenses'],
      planning: ['Planned activity budgets', 'Expense share estimate'],
      targets: ['Financial targets', 'Current target values']
    };
    const hint = hints[currentView] || hints.department;
    const budgetHint = document.getElementById('statBudgetHint');
    const actualHint = document.getElementById('statActualHint');
    if (budgetHint) budgetHint.textContent = hint[0];
    if (actualHint) actualHint.textContent = hint[1];
  }

  function filterRowsBySearch(rows) {
    const term = normalizeText(document.getElementById('filterSearch')?.value || '');
    if (!term) return rows;
    return rows.filter((row) => {
      const haystack = normalizeText([
        row.name,
        row.sector,
        row.source,
        row.period,
        row.status?.label
      ].filter(Boolean).join(' '));
      return haystack.includes(term);
    });
  }

  function getTableColspan() {
    return currentView === 'department' ? 6 : 7;
  }

  function toggleTableEmptyState(show, message) {
    const empty = document.getElementById('tableEmptyState');
    const wrap = document.querySelector('.va-table-wrap');
    if (empty) {
      empty.classList.toggle('is-visible', !!show);
      if (message) {
        const text = empty.querySelector('div');
        if (text) text.textContent = message;
      }
    }
    if (wrap) wrap.style.display = show ? 'none' : '';
  }

  function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
  }

  function renderTable(rows) {
    lastComputedRows = rows;
    const filtered = filterRowsBySearch(rows);
    lastRows = filtered;
    const head = document.getElementById('tableHead');
    const body = document.getElementById('tableBody');
    const title = document.getElementById('tableTitle');
    if (!head || !body) return;

    const titles = {
      department: 'Department variance',
      project: 'Project variance',
      planning: 'Planning budget variance',
      targets: 'Financial target variance'
    };
    if (title) title.textContent = titles[currentView] || 'Variance';

    let headerHtml = '';
    if (currentView === 'project') {
      headerHtml = '<tr><th>Project</th><th>Sector</th><th class="num">Budget</th><th class="num">Actual</th><th class="num">Variance</th><th class="num">Var %</th><th>Status</th></tr>';
    } else if (currentView === 'planning') {
      headerHtml = '<tr><th>Source</th><th>Records</th><th class="num">Planned</th><th class="num">Actual (est.)</th><th class="num">Variance</th><th class="num">Var %</th><th>Status</th></tr>';
    } else if (currentView === 'targets') {
      headerHtml = '<tr><th>Target</th><th>Period</th><th class="num">Target</th><th class="num">Current</th><th class="num">Variance</th><th class="num">Var %</th><th>Status</th></tr>';
    } else {
      headerHtml = '<tr><th>Department</th><th class="num">Budget</th><th class="num">Actual</th><th class="num">Variance</th><th class="num">Var %</th><th>Status</th></tr>';
    }
    head.innerHTML = headerHtml;

    const colspan = getTableColspan();
    if (!rows.length) {
      body.innerHTML = '';
      toggleTableEmptyState(true, 'No data for this view. Add finance records or adjust filters.');
      return;
    }

    if (!filtered.length) {
      body.innerHTML = '';
      toggleTableEmptyState(true, 'No variance rows match your search.');
      return;
    }

    toggleTableEmptyState(false);

    body.innerHTML = filtered.map((row) => {
      const varCls = row.variance >= 0 ? 'var-positive' : 'var-negative';
      const badge = `<span class="badge ${row.status.cls}">${row.status.label}</span>`;
      const pctStr = row.budget > 0 ? `${row.pct.toFixed(1)}%` : '—';

      if (currentView === 'project') {
        return `<tr>
          <td>${escapeHtml(row.name)}</td>
          <td>${escapeHtml(row.sector)}</td>
          <td class="num">${formatMoneyHtml(row.budget)}</td>
          <td class="num">${formatMoneyHtml(row.actual)}</td>
          <td class="num ${varCls}">${formatMoneyHtml(row.variance)}</td>
          <td class="num">${pctStr}</td>
          <td>${badge}</td>
        </tr>`;
      }
      if (currentView === 'planning') {
        return `<tr>
          <td>${escapeHtml(row.name)}</td>
          <td>${escapeHtml(row.source || '—')}</td>
          <td class="num">${formatMoneyHtml(row.budget)}</td>
          <td class="num">${formatMoneyHtml(row.actual)}</td>
          <td class="num ${varCls}">${formatMoneyHtml(row.variance)}</td>
          <td class="num">${pctStr}</td>
          <td>${badge}</td>
        </tr>`;
      }
      if (currentView === 'targets') {
        return `<tr>
          <td>${escapeHtml(row.name)}</td>
          <td>${escapeHtml(row.period)}</td>
          <td class="num">${formatMoneyHtml(row.budget)}</td>
          <td class="num">${formatMoneyHtml(row.actual)}</td>
          <td class="num ${varCls}">${formatMoneyHtml(row.variance)}</td>
          <td class="num">${pctStr}</td>
          <td>${badge}</td>
        </tr>`;
      }
      return `<tr>
        <td>${escapeHtml(row.name)}</td>
        <td class="num">${formatMoneyHtml(row.budget)}</td>
        <td class="num">${formatMoneyHtml(row.actual)}</td>
        <td class="num ${varCls}">${formatMoneyHtml(row.variance)}</td>
        <td class="num">${pctStr}</td>
        <td>${badge}</td>
      </tr>`;
    }).join('');
  }

  function getChartRows(rows, f, stores) {
    if (rows.length) return rows;

    if (currentView === 'department') {
      const relaxed = { ...f, start: null, end: null, quarter: '', year: '' };
      const fallback = buildDepartmentRows(relaxed, stores);
      if (fallback.length) return fallback;
    }

    return rows;
  }

  function updateCharts(rows, stores, f) {
    if (typeof Chart === 'undefined') return;

    const chartRows = getChartRows(rows, f, stores);
    const labels = chartRows.slice(0, 8).map((row) => row.name);
    const budgets = chartRows.slice(0, 8).map((row) => row.budget || 0);
    const actuals = chartRows.slice(0, 8).map((row) => row.actual || 0);
    const hasBudget = budgets.some((value) => value > 0);
    const hasActual = actuals.some((value) => value > 0);
    const emptyLabel = hasBudget || hasActual ? 'No data' : 'No matching data — try Reset filters';

    if (chartCompare) chartCompare.destroy();
    chartCompare = new Chart(document.getElementById('chartCompare'), {
      type: 'bar',
      data: {
        labels: labels.length ? labels : [emptyLabel],
        datasets: [
          { label: 'Budget', data: labels.length ? budgets : [0], backgroundColor: 'rgba(31, 90, 54, 0.8)', borderRadius: 6 },
          { label: 'Actual', data: labels.length ? actuals : [0], backgroundColor: 'rgba(37, 99, 235, 0.75)', borderRadius: 6 }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'top' } },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { callback: (v) => formatMoney(v) }
          }
        }
      }
    });

    const expenses = filterCommittedExpenses(stores.expenses, f);
    const byMonth = {};
    expenses.forEach((e) => {
      const d = parseDate(e.expenseDate || e.expense_date || e.date || e.createdAt);
      if (!d) return;
      const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
      byMonth[key] = (byMonth[key] || 0) + (Number(e.amount) || 0);
    });
    const monthKeys = Object.keys(byMonth).sort();

    if (chartTrend) chartTrend.destroy();
    chartTrend = new Chart(document.getElementById('chartTrend'), {
      type: 'line',
      data: {
        labels: monthKeys.length ? monthKeys : ['No expenses'],
        datasets: [{
          label: 'Expenses',
          data: monthKeys.length ? monthKeys.map((key) => byMonth[key]) : [0],
          borderColor: '#1f5a36',
          backgroundColor: 'rgba(74, 124, 40, 0.15)',
          fill: true,
          tension: 0.35
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { callback: (v) => formatMoney(v) }
          }
        }
      }
    });
  }

  function getRowsForView(f, stores) {
    if (currentView === 'project') return buildProjectRows(f, stores);
    if (currentView === 'planning') return buildPlanningRows(f, stores);
    if (currentView === 'targets') return buildTargetRows(stores);
    return buildDepartmentRows(f, stores);
  }

  function renderActiveFiltersPreview() {
    const el = document.getElementById('activeFiltersPreview');
    if (!el) return;

    const f = getFilters();
    const parts = [];

    if (f.start && f.end) {
      parts.push(`<strong>Period:</strong> ${formatDateInput(f.start)} → ${formatDateInput(f.end)}`);
    }
    if (f.department) {
      const label = window.RDOMMSDepartments
        ? RDOMMSDepartments.getLabel(f.department)
        : f.department;
      parts.push(`<strong>Department:</strong> ${label}`);
    }
    if (f.station) {
      const label = window.RDOMMSOrganizations
        ? RDOMMSOrganizations.getLabel(f.station)
        : f.station;
      parts.push(`<strong>Station:</strong> ${label}`);
    }
    if (f.quarter) parts.push(`<strong>Quarter:</strong> ${f.quarter.toUpperCase()}`);
    if (f.year) parts.push(`<strong>Year:</strong> ${f.year}`);

    if (!parts.length) {
      el.innerHTML = 'Set period and filters, then click <strong>Apply</strong> to refresh variance data.';
      return;
    }

    el.innerHTML = `${parts.join(' · ')} — click <strong>Apply</strong> to update.`;
  }

  function refreshView() {
    const f = getFilters();
    const stores = getDataStores();
    populateFilterOptions(stores);
    updateModuleCounts(stores, f);
    const rows = getRowsForView(f, stores);
    updateStats(rows);
    renderTable(rows);
    updateCharts(rows, stores, f);
    renderActiveFiltersPreview();
  }

  async function refresh(options = {}) {
    const { reload = false, silent = false } = options;
    if (reload) {
      setLoading(true);
      try {
        cachedStores = null;
        await loadDataStores(true);
      } finally {
        setLoading(false);
      }
    }
    refreshView();
    if (reload && !silent) {
      notify(dataSource === 'database' ? 'Live data refreshed.' : 'Cached data refreshed.', 'info');
    }
  }

  function setDefaultDates() {
    applyDatePreset('this-year');
    const start = document.getElementById('startDate');
    const end = document.getElementById('endDate');
    const year = document.getElementById('filterYear');
    if (start && PAGE.monthStart && !start.value) start.value = PAGE.monthStart;
    if (end && PAGE.monthEnd && !end.value) end.value = PAGE.monthEnd;
    if (year) year.value = '';
  }

  function buildExportRows() {
    if (currentView === 'project') {
      return {
        headers: ['Project', 'Sector', 'Budget', 'Actual', 'Variance', 'Variance %', 'Status'],
        rows: lastRows.map((row) => [row.name, row.sector, row.budget, row.actual, row.variance, row.pct.toFixed(1), row.status.label])
      };
    }
    if (currentView === 'planning') {
      return {
        headers: ['Source', 'Records', 'Planned', 'Actual', 'Variance', 'Variance %', 'Status'],
        rows: lastRows.map((row) => [row.name, row.source, row.budget, row.actual, row.variance, row.pct.toFixed(1), row.status.label])
      };
    }
    if (currentView === 'targets') {
      return {
        headers: ['Target', 'Period', 'Target Value', 'Current', 'Variance', 'Variance %', 'Status'],
        rows: lastRows.map((row) => [row.name, row.period, row.budget, row.actual, row.variance, row.pct.toFixed(1), row.status.label])
      };
    }
    return {
      headers: ['Department', 'Budget', 'Actual', 'Variance', 'Variance %', 'Status'],
      rows: lastRows.map((row) => [row.name, row.budget, row.actual, row.variance, row.pct.toFixed(1), row.status.label])
    };
  }

  function exportReport() {
    if (!lastRows.length) {
      notify('No data to export.', 'warning');
      return;
    }
    if (!window.RDOMMSExport) {
      notify('Export module not loaded.', 'error');
      return;
    }

    const format = document.getElementById('exportFormat')?.value || 'pdf';
    const { headers, rows } = buildExportRows();
    const filename = `rdomms-variance-${currentView}-${new Date().toISOString().slice(0, 10)}`;

    RDOMMSExport.exportData({
      format,
      title: `Variance Analysis — ${currentView}`,
      subtitle: `Generated: ${new Date().toLocaleString()}`,
      headers,
      rows,
      filename
    }).then(() => notify(`Variance report exported as ${format.toUpperCase()}.`, 'success'))
      .catch((error) => notify(error.message || 'Export failed.', 'error'));
  }

  function bindUi() {
    initVarianceStatCardFit();
    document.querySelectorAll('[data-preset]').forEach((btn) => {
      btn.addEventListener('click', () => {
        applyDatePreset(btn.getAttribute('data-preset'));
        refreshView();
      });
    });

    document.querySelectorAll('.tab-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach((b) => {
          b.classList.remove('active');
          b.setAttribute('aria-selected', 'false');
        });
        btn.classList.add('active');
        btn.setAttribute('aria-selected', 'true');
        currentView = btn.dataset.view || 'department';
        refreshView();
      });
    });

    document.getElementById('btnApply')?.addEventListener('click', () => {
      refreshView();
      notify('Filters applied.', 'success');
    });
    document.getElementById('btnReset')?.addEventListener('click', () => {
      setDefaultDates();
      const dept = document.getElementById('filterDept');
      const station = document.getElementById('filterStation');
      const quarter = document.getElementById('filterQuarter');
      const search = document.getElementById('filterSearch');
      if (dept) dept.value = '';
      if (station) station.value = '';
      if (quarter) quarter.value = '';
      if (search) search.value = '';
      refreshView();
      notify('Filters reset.', 'info');
    });
    document.getElementById('filterSearch')?.addEventListener('input', () => {
      renderTable(lastComputedRows);
    });
    document.getElementById('btnRefresh')?.addEventListener('click', () => refresh({ reload: true }));
    document.getElementById('btnExport')?.addEventListener('click', exportReport);
  }

  async function boot() {
    if (window.RDOMMSOrganizations) RDOMMSOrganizations.fillAll();
    if (window.RDOMMSDepartments) RDOMMSDepartments.fillAll();
    setDefaultDates();
    bindUi();
    renderActiveFiltersPreview();
    setLoading(true);
    try {
      await loadDataStores(true);
      refreshView();
    } finally {
      setLoading(false);
      scheduleVarianceStatFit();
      setTimeout(scheduleVarianceStatFit, 120);
    }
  }

  document.addEventListener('DOMContentLoaded', boot);
  window.addEventListener('rdommsDataLoaded', () => refresh({ reload: true, silent: true }));

  window.RDOMMSVariance = { refresh, exportReport };
})();
