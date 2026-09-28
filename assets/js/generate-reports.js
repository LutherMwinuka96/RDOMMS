/**
 * RDOMMS Report Studio — data-backed report builder with export tools.
 */
(function () {
  let currentReport = null;
  let mainChartInstance = null;
  let apiReady = false;
  let summaryCache = null;
  let plansCache = null;

  const PAGE = window.RDOMMS_REPORTS_PAGE || {};
  const API_BASE = PAGE.apiBase || '../api/reports.php';
  const REPORT_ACCESS = PAGE.reportAccess || window.RDOMMS_REPORT_ACCESS || {};

  function getAllowedReportTypes() {
    const allowed = REPORT_ACCESS.allowedReportTypes;
    if (Array.isArray(allowed) && allowed.length) {
      return allowed.map((type) => normalizeText(type));
    }
    return REPORT_CATALOG.map((item) => item.type);
  }

  function isReportTypeAllowed(type) {
    const key = normalizeText(type);
    if (!key) return false;
    return getAllowedReportTypes().includes(key);
  }

  function applyReportTypeRestrictions() {
    const allowed = new Set(getAllowedReportTypes());
    const select = document.getElementById('reportType');
    if (!select) return;

    Array.from(select.options).forEach((option) => {
      if (!option.value) return;
      const visible = allowed.has(normalizeText(option.value));
      option.hidden = !visible;
      option.disabled = !visible;
    });

    if (select.value && !allowed.has(normalizeText(select.value))) {
      select.value = '';
    }
  }

  const REPORT_CATALOG = [
    { type: 'executive', label: 'Executive Overview', description: 'Cross-module snapshot of projects, beneficiaries, finance, and operations.', pill: 'Core', icon: 'dashboard.png' },
    { type: 'projects', label: 'Project Portfolio', description: 'Status, progress, and delivery watchlist across your project portfolio.', pill: 'Projects', icon: 'allproject.png' },
    { type: 'beneficiaries', label: 'Beneficiary Coverage', description: 'Registration status, categories, and service reach analytics.', pill: 'Coverage', icon: 'beneficiary.png' },
    { type: 'planning', label: 'Planning & Delivery', description: 'Monthly, quarterly, and annual plans with evidence tracking.', pill: 'Planning', icon: 'planning.png' },
    { type: 'finance', label: 'Finance Summary', description: 'Budget allocations, expenses, and spend by department.', pill: 'Finance', icon: 'finance.png' },
    { type: 'users', label: 'Users & Access', description: 'Roles, account status, and access distribution.', pill: 'Access', icon: 'usersroles.png' },
    { type: 'operations', label: 'Operations & Alerts', description: 'Notifications, approvals, and live operational signals.', pill: 'Ops', icon: 'notification.png' },
    { type: 'master', label: 'System Master Report', description: 'Full consolidated report across all RDO|MMS modules.', pill: 'Master', icon: 'generatereport.png' }
  ];

  function notify(message, type = 'info') {
    if (window.RDOMMSToast) {
      RDOMMSToast.show(message, type, { title: 'Report Studio', allowMultiple: true });
      return;
    }
    console.log(`[${type}] ${message}`);
  }

  function formatNumber(value) {
    if (typeof window.formatRdommsNumber === 'function') {
      return formatRdommsNumber(value, 0);
    }
    return Number(value || 0).toLocaleString('en-US');
  }

  function formatCurrency(value) {
    if (typeof window.formatRdommsCurrency === 'function') {
      return formatRdommsCurrency(value, 'TSH');
    }
    return `TSH ${formatNumber(value)}`;
  }

  function normalizeText(value) {
    return String(value ?? '').trim().toLowerCase();
  }

  function filterToken(value) {
    return normalizeText(value).replace(/[\s_\-]+/g, '');
  }

  function expandFilterTerms(raw, kind) {
    const key = String(raw ?? '').trim();
    if (!key) return [];

    const terms = [key];
    if (kind === 'station' && window.RDOMMSOrganizations) {
      const value = RDOMMSOrganizations.getValue(key);
      const label = RDOMMSOrganizations.getLabel(value || key);
      if (value) terms.push(value);
      if (label) terms.push(label);
    } else if (kind === 'department' && window.RDOMMSDepartments) {
      const value = RDOMMSDepartments.getValue(key);
      const label = RDOMMSDepartments.getLabel(value || key);
      if (value) terms.push(value);
      if (label) terms.push(label);
    }
    return [...new Set(terms.filter(Boolean))];
  }

  function buildFilterMeta(type, startStr, endStr, station, department) {
    const stationValue = station && window.RDOMMSOrganizations
      ? RDOMMSOrganizations.getValue(station)
      : station;
    const departmentValue = department && window.RDOMMSDepartments
      ? RDOMMSDepartments.getValue(department)
      : department;

    return {
      reportType: normalizeText(type),
      startDate: startStr,
      endDate: endStr,
      station: stationValue || '',
      stationLabel: stationValue && window.RDOMMSOrganizations
        ? RDOMMSOrganizations.getLabel(stationValue)
        : 'All stations',
      department: departmentValue || '',
      departmentLabel: departmentValue && window.RDOMMSDepartments
        ? RDOMMSDepartments.getLabel(departmentValue)
        : 'All departments'
    };
  }

  function countFilteredRecords(type, counts) {
    const key = normalizeText(type);
    if (key === 'projects') return counts.projects || 0;
    if (key === 'beneficiaries') return counts.beneficiaries || 0;
    if (key === 'planning') return counts.plans || 0;
    if (key === 'finance') return (counts.expenses || 0) + (counts.budgets || 0);
    if (key === 'users') return counts.users || 0;
    if (key === 'operations') return (counts.notifications || 0) + (counts.activities || 0);
    if (key === 'executive' || key === 'master') {
      return (counts.projects || 0)
        + (counts.beneficiaries || 0)
        + (counts.expenses || 0)
        + (counts.budgets || 0)
        + (counts.plans || 0)
        + (counts.users || 0)
        + (counts.notifications || 0)
        + (counts.activities || 0);
    }
    return 0;
  }

  function normalizeRecord(record) {
    if (!record || typeof record !== 'object') return record;
    return {
      ...record,
      name: record.name || record.fullname || record.projectName || record.title,
      fullname: record.fullname || record.name,
      projectName: record.projectName || record.name || record.title,
      title: record.title || record.name || record.subject,
      createdAt: record.createdAt || record.created_at,
      updatedAt: record.updatedAt || record.updated_at,
      startDate: record.startDate || record.start_date,
      endDate: record.endDate || record.end_date,
      dueDate: record.dueDate || record.end_date || record.endDate,
      reportType: record.reportType || record.report_type,
      generatedAt: record.generatedAt || record.generated_at,
      staffName: record.staffName || record.staff_name,
      department: record.department || record.departmentName || record.sector,
      station: record.station || record.organization || record.district,
      location: record.location || record.district,
      status: record.status || record.approval_status,
      severity: record.severity || record.level || 'info',
      module: record.module,
      action: record.action,
      timestamp: record.timestamp || record.created_at || record.createdAt,
      expenseDate: record.expenseDate || record.expense_date,
      fiscalYear: record.fiscalYear || record.fiscal_year,
      progress: record.progress ?? 0,
      beneficiaries: record.beneficiaries ?? 0,
      amount: Number(record.amount || 0),
      budget: Number(record.budget || 0),
      read: record.read === true || record.read === 1 || record.is_read === 1
    };
  }

  function setLoading(active) {
    const overlay = document.getElementById('loadingOverlay');
    if (!overlay) return;
    overlay.classList.toggle('active', !!active);
    overlay.setAttribute('aria-hidden', active ? 'false' : 'true');
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

  async function apiRequest(action, options = {}) {
    const method = options.method || 'GET';
    const params = new URLSearchParams({ action, ...(options.params || {}) });
    const url = `${API_BASE}?${params.toString()}`;
    const fetchOptions = {
      method,
      credentials: 'same-origin',
      cache: options.cache || 'default',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        ...(options.body ? { 'Content-Type': 'application/json' } : {})
      }
    };
    if (options.body) {
      fetchOptions.body = JSON.stringify(options.body);
    }

    const response = await fetch(url, fetchOptions);
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      const detail = payload?.detail ? ` (${payload.detail})` : '';
      const err = new Error((payload?.message || 'Request failed') + detail);
      err.status = response.status;
      throw err;
    }
    return payload.data;
  }

  function loadArray(keys) {
    for (const key of keys) {
      try {
        const raw = localStorage.getItem(key);
        if (raw == null) continue;
        const value = JSON.parse(raw);
        if (Array.isArray(value)) {
          return value.map(normalizeRecord);
        }
      } catch (_) {}
    }
    return [];
  }

  function pickField(record, fields) {
    for (const field of fields) {
      const value = record?.[field];
      if (value != null && String(value).trim() !== '') return value;
    }
    return '';
  }

  function parseDateValue(value) {
    if (!value) return null;
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
  }

  function getRecordDate(record, fields) {
    return parseDateValue(pickField(record, fields));
  }

  function withinRange(record, fields, startDate, endDate) {
    const date = getRecordDate(record, fields);
    const hasDateFilter = !!(startDate || endDate);
    if (!date) {
      return !hasDateFilter;
    }
    return (!startDate || date >= startDate) && (!endDate || date <= endDate);
  }

  function parseMonthValue(monthRaw, year) {
    const month = String(monthRaw ?? '').trim();
    if (!month) return null;

    let planYear = year;
    let planMonth = month;
    const isoMatch = month.match(/^(\d{4})-(\d{1,2})/);
    if (isoMatch) {
      planYear = parseInt(isoMatch[1], 10);
      planMonth = isoMatch[2];
    }

    if (/^\d{1,2}$/.test(planMonth)) {
      const date = new Date(planYear, parseInt(planMonth, 10) - 1, 1);
      return Number.isNaN(date.getTime()) ? null : date;
    }

    const parsed = new Date(`${planMonth} 1, ${planYear}`);
    return Number.isNaN(parsed.getTime()) ? null : new Date(parsed.getFullYear(), parsed.getMonth(), 1);
  }

  function getPlanPeriodBounds(record) {
    const year = parseInt(record?.year, 10) || 0;

    if (record?.month) {
      const planYear = year > 0 ? year : new Date().getFullYear();
      const start = parseMonthValue(record.month, planYear);
      if (start) {
        const end = new Date(start.getFullYear(), start.getMonth() + 1, 0, 23, 59, 59, 999);
        return [start, end];
      }
    }

    if (record?.quarter) {
      const quarterRaw = String(record.quarter).toUpperCase();
      const quarterMatch = quarterRaw.match(/(\d)/);
      const quarterNum = quarterMatch ? Math.max(1, Math.min(4, parseInt(quarterMatch[1], 10))) : 1;
      const planYear = year > 0 ? year : new Date().getFullYear();
      const startMonth = ((quarterNum - 1) * 3);
      const start = new Date(planYear, startMonth, 1);
      const end = new Date(planYear, startMonth + 3, 0, 23, 59, 59, 999);
      return [start, end];
    }

    if (year > 0) {
      return [
        new Date(year, 0, 1),
        new Date(year, 11, 31, 23, 59, 59, 999)
      ];
    }

    return null;
  }

  function recordInDateRange(record, dateFields, startDate, endDate) {
    if (!startDate && !endDate) return true;

    const date = getRecordDate(record, dateFields);
    if (date) {
      return (!startDate || date >= startDate) && (!endDate || date <= endDate);
    }

    const bounds = getPlanPeriodBounds(record);
    if (bounds) {
      const [planStart, planEnd] = bounds;
      const rangeStart = startDate || new Date(0);
      const rangeEnd = endDate || new Date(8640000000000000);
      return planStart <= rangeEnd && planEnd >= rangeStart;
    }

    return false;
  }

  function projectOverlapsReportRange(project, startDate, endDate) {
    if (!startDate && !endDate) return true;

    const activeStatuses = new Set(['ongoing', 'delayed', 'not-started']);
    const status = normalizeText(project?.status || '');
    const isActive = activeStatuses.has(status);

    const projectStart = parseDateValue(
      project?.startDate || project?.start_date
    );
    const projectEnd = parseDateValue(
      project?.endDate || project?.dueDate || project?.end_date
    );

    const rangeStart = startDate || new Date(0);
    const rangeEnd = endDate || new Date(8640000000000000);

    let effectiveEnd = projectEnd;
    if (!effectiveEnd && isActive && projectStart) {
      effectiveEnd = rangeEnd;
    }

    if (!projectStart && !effectiveEnd) {
      const created = parseDateValue(project?.createdAt || project?.created_at);
      if (!created) return false;
      return created >= rangeStart && created <= rangeEnd;
    }

    const effectiveStart = projectStart || effectiveEnd;
    const effectiveProjectEnd = effectiveEnd || projectStart;
    if (!effectiveStart || !effectiveProjectEnd) return true;

    return effectiveStart <= rangeEnd && effectiveProjectEnd >= rangeStart;
  }

  function filterProjectsByScope(projects, filters = {}) {
    return (Array.isArray(projects) ? projects : []).filter((project) => matchesFilters(project, filters));
  }

  function filterProjectsForPeriod(projects, filters = {}) {
    const { startDate = null, endDate = null } = filters;
    return (Array.isArray(projects) ? projects : []).filter((project) =>
      projectOverlapsReportRange(project, startDate, endDate)
    );
  }

  function matchesValue(value, needle) {
    if (!needle) return true;
    const haystack = normalizeText(value);
    const term = normalizeText(needle);
    if (!haystack) return false;
    if (haystack === term) return true;
    if (haystack.includes(term) || term.includes(haystack)) return true;
    const hayTok = filterToken(value);
    const termTok = filterToken(needle);
    return !!(hayTok && termTok && (hayTok.includes(termTok) || termTok.includes(hayTok)));
  }

  function matchesFilters(record, filters = {}) {
    const {
      station,
      department,
      stationFields = ['station', 'location', 'district', 'site', 'organization'],
      departmentFields = ['department', 'departmentName', 'unit', 'section', 'category', 'sector']
    } = filters;

    const stationTerms = expandFilterTerms(station, 'station');
    const departmentTerms = expandFilterTerms(department, 'department');

    const matchesStation = !station || stationFields.some((field) =>
      stationTerms.some((term) => matchesValue(record?.[field], term))
    );
    const matchesDepartment = !department || departmentFields.some((field) =>
      departmentTerms.some((term) => matchesValue(record?.[field], term))
    );
    return matchesStation && matchesDepartment;
  }

  function filterRecords(records, filters = {}) {
    const {
      startDate = null,
      endDate = null,
      dateFields = ['createdAt', 'created_at', 'timestamp', 'date', 'updatedAt', 'updated_at', 'submittedAt', 'dueDate', 'endDate', 'startDate', 'generatedAt', 'expenseDate', 'expense_date', 'registered_date']
    } = filters;

    return (Array.isArray(records) ? records : []).filter((record) => {
      if (!matchesFilters(record, filters)) return false;
      if (!recordInDateRange(record, dateFields, startDate, endDate)) return false;
      return true;
    });
  }

  function countBy(records, field) {
    const counts = {};
    (Array.isArray(records) ? records : []).forEach((record) => {
      const key = normalizeText(typeof field === 'function' ? field(record) : record?.[field]) || 'unknown';
      counts[key] = (counts[key] || 0) + 1;
    });
    return counts;
  }

  function topRows(records, mapper, limit = 10) {
    return (Array.isArray(records) ? records : []).slice(0, limit).map(mapper);
  }

  function makeSection(title, summary, data, chartType = 'bar', chartLabels = [], chartData = [], chartColors = []) {
    return { title, summary, data, chartType, chartLabels, chartData, chartColors };
  }

  function loadReportContext() {
    return {
      projects: loadArray(['projects', 'rd_projects', 'rdomms_projects']),
      beneficiaries: loadArray(['beneficiaries', 'rdomms_beneficiaries']),
      users: loadArray(['users', 'rdomms_users']),
      activities: loadArray(['user_activities', 'activities', 'rdomms_activity_logs']),
      systemLogs: loadArray(['user_activities', 'rdomms_system_logs', 'rdomms_activity_logs']),
      notifications: loadArray(['notifications', 'system_alerts', 'rdomms_notifications']),
      approvals: loadArray(['rdomms_approval_requests']),
      weeklyPlans: loadArray(['weeklyPlans', 'weekly_plans']),
      monthlyPlans: loadArray(['monthlyPlans', 'monthly_plans']),
      quarterlyPlans: loadArray(['quarterlyPlans', 'quarterly_plans']),
      annualPlans: loadArray(['annualPlans', 'annual_plans']),
      targets: loadArray(['targets']),
      evidenceList: loadArray(['evidenceList']),
      expenses: loadArray(['expenses', 'rdomms_expenses']),
      budgetAllocations: loadArray(['budgetAllocations', 'rdomms_budgetAllocations']),
      scheduledReports: loadArray(['scheduledReports']),
      reportHistory: loadArray(['reportHistory'])
    };
  }

  function getReportTitle(type) {
    const map = {
      executive: 'Executive Overview Report',
      projects: 'Project Portfolio Report',
      beneficiaries: 'Beneficiary Coverage Report',
      planning: 'Planning & Delivery Report',
      finance: 'Finance Summary Report',
      users: 'Users & Access Report',
      operations: 'Operations & Alerts Report',
      governance: 'Governance & Audit Report',
      master: 'System Master Report'
    };
    return map[normalizeText(type)] || 'System Report';
  }

  function buildReportData(type, startStr, endStr, station, department) {
    const startDate = parseDateValue(startStr);
    const endDate = parseDateValue(endStr);
    if (endDate) {
      endDate.setHours(23, 59, 59, 999);
    }

    const ctx = loadReportContext();
    const reportKey = normalizeText(type);
    const filters = buildFilterMeta(type, startStr, endStr, station, department);
    const rd = {
      title: getReportTitle(type),
      period: `${startStr} to ${endStr}`,
      generatedAt: new Date().toISOString(),
      reportType: reportKey,
      filters,
      sections: []
    };

    const baseFilters = { station, department, startDate, endDate };
    const scopeFilters = { station, department };
    const scopedProjects = filterProjectsByScope(ctx.projects, scopeFilters);
    const filteredProjects = filterProjectsForPeriod(scopedProjects, baseFilters);
    const filteredBeneficiaries = filterRecords(ctx.beneficiaries, { ...baseFilters, dateFields: ['createdAt', 'created_at', 'registered_date', 'updatedAt'] });
    const filteredUsers = filterRecords(ctx.users, { ...baseFilters, dateFields: ['createdAt', 'created_at', 'updatedAt'] });
    const filteredActivities = filterRecords(ctx.activities, { ...baseFilters, dateFields: ['timestamp', 'created_at', 'createdAt', 'date'] });
    const filteredLogs = filterRecords(ctx.systemLogs, { ...baseFilters, dateFields: ['timestamp', 'created_at', 'createdAt', 'date'] });
    const filteredNotifications = filterRecords(ctx.notifications, { ...baseFilters, dateFields: ['createdAt', 'created_at', 'timestamp', 'updatedAt'] });
    const filteredExpenses = filterRecords(ctx.expenses, { ...baseFilters, dateFields: ['expenseDate', 'expense_date', 'createdAt', 'created_at'] });
    const filteredBudgets = filterRecords(ctx.budgetAllocations, { ...baseFilters, dateFields: ['createdAt', 'created_at', 'updatedAt'] });
    const filteredPlans = {
      weekly: filterRecords(ctx.weeklyPlans, { ...baseFilters, dateFields: ['createdAt', 'updatedAt', 'weekEnding'] }),
      monthly: filterRecords(ctx.monthlyPlans, { ...baseFilters, dateFields: ['createdAt', 'updatedAt', 'month', 'saved_at'] }),
      quarterly: filterRecords(ctx.quarterlyPlans, { ...baseFilters, dateFields: ['createdAt', 'updatedAt', 'quarter'] }),
      annual: filterRecords(ctx.annualPlans, { ...baseFilters, dateFields: ['createdAt', 'updatedAt', 'year'] })
    };

    const projectStatusCounts = countBy(filteredProjects, 'status');
    const beneficiaryStatusCounts = countBy(filteredBeneficiaries, 'status');
    const roleCounts = countBy(filteredUsers, 'role');
    const userStatusCounts = countBy(filteredUsers, 'status');
    const expenseStatusCounts = countBy(filteredExpenses, 'status');
    const budgetStatusCounts = countBy(filteredBudgets, 'status');
    const totalExpense = filteredExpenses.reduce((sum, row) => sum + Number(row.amount || 0), 0);
    const totalBudget = filteredBudgets.reduce((sum, row) => sum + Number(row.amount || 0), 0);
    const totalProjectBudget = filteredProjects.reduce((sum, row) => sum + Number(row.budget || 0), 0);

    const projectRows = topRows(filteredProjects, (project) => ({
      Project: project.name || project.projectName || 'Project',
      Status: project.status || 'unknown',
      Progress: `${Number(project.progress || 0)}%`,
      Budget: formatCurrency(project.budget || 0),
      Beneficiaries: Number(project.beneficiaries || 0),
      Start: project.startDate || '-',
      Due: project.endDate || project.dueDate || '-',
      Location: project.location || project.district || '-'
    }));

    const beneficiaryRows = topRows(filteredBeneficiaries, (item) => ({
      Name: item.fullname || item.name || 'Beneficiary',
      Status: item.status || 'pending',
      Category: item.category || '-',
      District: item.district || item.location || '-',
      Registered: item.registered_date || item.createdAt || '-'
    }));

    const userRows = topRows(filteredUsers, (item) => ({
      Name: item.fullname || item.email || 'User',
      Role: item.role || '-',
      Status: item.status || '-',
      Department: item.department || '-',
      Organization: item.organization || '-',
      Created: item.createdAt || '-'
    }));

    const expenseRows = topRows(filteredExpenses, (item) => ({
      Date: item.expenseDate || item.createdAt || '-',
      Category: item.category || '-',
      Department: item.department || '-',
      Amount: formatCurrency(item.amount || 0),
      Status: item.status || '-',
      Reference: item.reference || '-'
    }));

    const budgetRows = topRows(filteredBudgets, (item) => ({
      Department: item.department || '-',
      Quarter: item.quarter || '-',
      Year: item.fiscalYear || item.fiscal_year || '-',
      Amount: formatCurrency(item.amount || 0),
      Status: item.status || '-',
      Station: item.station || '-'
    }));

    const planningRows = topRows(
      [].concat(filteredPlans.monthly, filteredPlans.quarterly, filteredPlans.annual),
      (item) => ({
        Plan: item.title || item.objective || item.month || item.quarter || item.year || 'Plan',
        Type: item.month ? 'Monthly' : item.quarter ? 'Quarterly' : 'Annual',
        Department: item.department || '-',
        Status: item.status || item.approval_status || '-',
        Staff: item.staffName || '-',
        Updated: item.updatedAt || item.createdAt || '-'
      }),
      12
    );

    const activityRows = topRows(filteredActivities, (item) => ({
      Activity: item.action || item.title || 'Activity',
      Module: item.module || '-',
      Severity: item.severity || 'info',
      Date: item.timestamp || item.createdAt || '-'
    }));

    const notificationRows = topRows(filteredNotifications, (item) => ({
      Title: item.title || item.subject || item.name || 'Notification',
      Module: item.module || item.type || '-',
      Severity: item.severity || 'info',
      Status: item.read ? 'Read' : 'Unread',
      Date: item.createdAt || item.timestamp || '-'
    }));

    const overviewSummary = {
      Projects: filteredProjects.length,
      'In Portfolio': scopedProjects.length,
      Beneficiaries: filteredBeneficiaries.length,
      Users: filteredUsers.length,
      Plans: filteredPlans.monthly.length + filteredPlans.quarterly.length + filteredPlans.annual.length,
      Expenses: formatCurrency(totalExpense),
      'Budget Allocated': formatCurrency(totalBudget)
    };

    const moduleRows = [
      { Module: 'Projects', Records: filteredProjects.length, Note: `${scopedProjects.length} in portfolio` },
      { Module: 'Beneficiaries', Records: filteredBeneficiaries.length, Note: `${beneficiaryStatusCounts.active || 0} active` },
      { Module: 'Finance', Records: filteredExpenses.length + filteredBudgets.length, Note: formatCurrency(totalExpense) },
      { Module: 'Planning', Records: filteredPlans.monthly.length + filteredPlans.quarterly.length + filteredPlans.annual.length, Note: `${filteredPlans.monthly.length} monthly` },
      { Module: 'Users', Records: filteredUsers.length, Note: `${roleCounts.admin || 0} admins` },
      { Module: 'Operations', Records: filteredNotifications.length + filteredLogs.length, Note: `${filteredNotifications.filter((n) => !n.read).length} unread` }
    ];

    const systemOverview = makeSection('Executive Snapshot', overviewSummary, moduleRows, 'doughnut',
      moduleRows.map((row) => row.Module), moduleRows.map((row) => row.Records),
      ['#4a7c28', '#3b9eca', '#e8a838', '#7ab648', '#2d5016', '#e05252']);

    const projectSection = makeSection('Project Portfolio', {
      'Matching Filters': filteredProjects.length,
      'In Portfolio': scopedProjects.length,
      Completed: projectStatusCounts.completed || 0,
      Ongoing: projectStatusCounts.ongoing || 0,
      Delayed: projectStatusCounts.delayed || 0,
      'Not Started': projectStatusCounts['not-started'] || 0,
      'Portfolio Budget': formatCurrency(totalProjectBudget)
    }, projectRows, 'bar',
      ['Completed', 'Ongoing', 'Delayed', 'Not Started'],
      [projectStatusCounts.completed || 0, projectStatusCounts.ongoing || 0, projectStatusCounts.delayed || 0, projectStatusCounts['not-started'] || 0],
      ['#7ab648', '#3b9eca', '#e8a838', '#e05252']);

    const beneficiarySection = makeSection('Beneficiary Coverage', {
      'Total Beneficiaries': filteredBeneficiaries.length,
      Active: beneficiaryStatusCounts.active || 0,
      Pending: beneficiaryStatusCounts.pending || 0,
      Inactive: beneficiaryStatusCounts.inactive || 0
    }, beneficiaryRows, 'doughnut',
      ['Active', 'Pending', 'Inactive'],
      [beneficiaryStatusCounts.active || 0, beneficiaryStatusCounts.pending || 0, beneficiaryStatusCounts.inactive || 0],
      ['#7ab648', '#e8a838', '#e05252']);

    const planningSection = makeSection('Planning & Delivery', {
      'Monthly Plans': filteredPlans.monthly.length,
      'Quarterly Plans': filteredPlans.quarterly.length,
      'Annual Plans': filteredPlans.annual.length,
      'Evidence Items': ctx.evidenceList.length
    }, planningRows, 'bar',
      ['Monthly', 'Quarterly', 'Annual'],
      [filteredPlans.monthly.length, filteredPlans.quarterly.length, filteredPlans.annual.length],
      ['#4a7c28', '#3b9eca', '#7ab648']);

    const financeSection = makeSection('Finance Summary', {
      'Total Expenses': formatCurrency(totalExpense),
      'Budget Allocated': formatCurrency(totalBudget),
      Variance: formatCurrency(totalBudget - totalExpense),
      'Approved Expenses': expenseStatusCounts.approved || 0,
      'Approved Budgets': budgetStatusCounts.approved || 0
    }, [...expenseRows, ...budgetRows].slice(0, 12), 'bar',
      ['Expenses', 'Budget', 'Approved Exp.', 'Approved Budg.'],
      [totalExpense, totalBudget, expenseStatusCounts.approved || 0, budgetStatusCounts.approved || 0],
      ['#e05252', '#3b9eca', '#7ab648', '#4a7c28']);

    const userSection = makeSection('Users & Access', {
      'Total Users': filteredUsers.length,
      Administrators: roleCounts.admin || 0,
      Active: userStatusCounts.active || 0,
      Pending: userStatusCounts.pending || 0
    }, userRows, 'bar',
      Object.keys(roleCounts).slice(0, 5).map((role) => role.replace(/_/g, ' ')),
      Object.keys(roleCounts).slice(0, 5).map((role) => roleCounts[role]),
      ['#4a7c28', '#3b9eca', '#e8a838', '#7ab648', '#e05252']);

    const operationsSection = makeSection('Operations & Alerts', {
      Notifications: filteredNotifications.length,
      'Unread Alerts': filteredNotifications.filter((item) => !item.read).length,
      'Activity Logs': filteredLogs.length,
      Warnings: countBy(filteredLogs, 'severity').warning || 0
    }, [...notificationRows, ...activityRows].slice(0, 12), 'bar',
      ['Unread', 'Logs', 'Warnings', 'Info'],
      [
        filteredNotifications.filter((item) => !item.read).length,
        filteredLogs.length,
        countBy(filteredLogs, 'severity').warning || 0,
        countBy(filteredLogs, 'severity').info || 0
      ],
      ['#e05252', '#3b9eca', '#e8a838', '#7ab648']);

    switch (reportKey) {
      case 'executive':
        rd.sections = [systemOverview, projectSection, beneficiarySection, financeSection];
        break;
      case 'projects':
        rd.sections = [projectSection];
        break;
      case 'beneficiaries':
        rd.sections = [beneficiarySection];
        break;
      case 'planning':
        rd.sections = [planningSection];
        break;
      case 'finance':
        rd.sections = [financeSection];
        break;
      case 'users':
        rd.sections = [userSection];
        break;
      case 'operations':
        rd.sections = [operationsSection];
        break;
      case 'master':
        rd.sections = [systemOverview, projectSection, beneficiarySection, planningSection, financeSection, userSection, operationsSection];
        break;
      default:
        rd.sections = [systemOverview];
        break;
    }

    rd.recordCount = countFilteredRecords(reportKey, {
      projects: filteredProjects.length,
      beneficiaries: filteredBeneficiaries.length,
      users: filteredUsers.length,
      expenses: filteredExpenses.length,
      budgets: filteredBudgets.length,
      plans: filteredPlans.monthly.length + filteredPlans.quarterly.length + filteredPlans.annual.length,
      notifications: filteredNotifications.length,
      activities: filteredActivities.length
    });

    return rd;
  }

  function normalizeFormFilters(values) {
    const station = values.station && window.RDOMMSOrganizations
      ? RDOMMSOrganizations.getValue(values.station)
      : (values.station || '');
    const department = values.department && window.RDOMMSDepartments
      ? RDOMMSDepartments.getValue(values.department)
      : (values.department || '');
    return { ...values, station, department };
  }

  function getFormValues() {
    return normalizeFormFilters({
      reportType: document.getElementById('reportType')?.value || '',
      startDate: document.getElementById('startDate')?.value || '',
      endDate: document.getElementById('endDate')?.value || '',
      station: document.getElementById('stationFilter')?.value || '',
      department: document.getElementById('departmentName')?.value || ''
    });
  }

  function validateForm(values) {
    if (!values.reportType || !values.startDate || !values.endDate) {
      notify('Select a report type and date range.', 'warning');
      return false;
    }
    if (!isReportTypeAllowed(values.reportType)) {
      notify('You are not allowed to generate this report type.', 'warning');
      return false;
    }
    if (values.startDate > values.endDate) {
      notify('Start date cannot be after end date.', 'error');
      return false;
    }
    return true;
  }

  function setGenerating(isLoading) {
    const btn = document.getElementById('generateBtn');
    if (!btn) return;
    btn.disabled = isLoading;
    btn.classList.toggle('is-loading', isLoading);
  }

  async function generateReport() {
    const values = getFormValues();
    if (!validateForm(values)) return;

    setGenerating(true);
    setLoading(true);
    try {
      let rd;
      let usedFallback = false;
      if (apiReady) {
        try {
          rd = await apiRequest('generate', {
            cache: 'no-store',
            params: {
              report_type: values.reportType,
              start_date: values.startDate,
              end_date: values.endDate,
              station: values.station,
              department: values.department,
              _t: String(Date.now())
            }
          });
        } catch (apiError) {
          if (apiError.status === 401) {
            throw apiError;
          }
          rd = buildReportData(values.reportType, values.startDate, values.endDate, values.station, values.department);
          usedFallback = true;
        }
      } else {
        rd = buildReportData(values.reportType, values.startDate, values.endDate, values.station, values.department);
        usedFallback = true;
      }
      currentReport = rd;
      renderReport(rd);
      renderActiveFiltersPreview();
      try {
        await saveToHistory(rd, values.reportType);
      } catch (_) {
        /* history save is optional */
      }
      if (rd.recordCount === 0) {
        notify('No records match the selected filters. Try adjusting dates, station, or department.', 'warning');
      } else {
        notify(
          usedFallback
            ? 'Report generated from cached/local data.'
            : 'Report generated successfully.',
          usedFallback ? 'warning' : 'success'
        );
      }
    } catch (error) {
      if (error.status === 401) {
        notify('Sign in to generate database-backed reports.', 'warning');
      } else {
        notify(error.message || 'Failed to generate report.', 'error');
      }
    } finally {
      setGenerating(false);
      setLoading(false);
    }
  }

  function openReport(type) {
    const select = document.getElementById('reportType');
    if (select) select.value = type;
    generateReport();
  }

  async function downloadReport(type, format) {
    const select = document.getElementById('reportType');
    if (select) select.value = type;
    const values = getFormValues();
    if (!validateForm({ ...values, reportType: type })) return;
    setLoading(true);
    try {
      if (apiReady) {
        currentReport = await apiRequest('generate', {
          cache: 'no-store',
          params: {
            report_type: type,
            start_date: values.startDate,
            end_date: values.endDate,
            station: values.station,
            department: values.department,
            _t: String(Date.now())
          }
        });
      } else {
        currentReport = buildReportData(type, values.startDate, values.endDate, values.station, values.department);
      }
      renderReportCatalog();
      await saveToHistory(currentReport, type);
      exportByFormat(format);
    } catch (error) {
      notify(error.message || 'Failed to prepare export.', 'error');
    } finally {
      setLoading(false);
    }
  }

  function renderReportCatalog() {
    const host = document.getElementById('reportCatalog');
    if (!host) return;
    /* catalog removed from UI */
    const activeType = document.getElementById('reportType')?.value || '';

    host.innerHTML = REPORT_CATALOG
      .filter((item) => isReportTypeAllowed(item.type))
      .map((item, index) => `
      <article class="report-card ${activeType === item.type ? 'is-selected' : ''}">
        <div class="report-card-top">
          <span class="report-index">${String(index + 1).padStart(2, '0')}</span>
          <span class="report-pill">${item.pill}</span>
        </div>
        <div class="report-card-body">
          <h4>${item.label}</h4>
          <p>${item.description}</p>
        </div>
        <div class="report-actions">
          <button type="button" class="btn btn-primary btn-sm" data-preview="${item.type}">Preview</button>
          <button type="button" class="btn btn-ghost btn-sm" data-export="${item.type}" data-format="pdf">PDF</button>
          <button type="button" class="btn btn-ghost btn-sm" data-export="${item.type}" data-format="word">Word</button>
          <button type="button" class="btn btn-ghost btn-sm" data-export="${item.type}" data-format="excel">Excel</button>
        </div>
      </article>
    `).join('');

    host.querySelectorAll('[data-preview]').forEach((btn) => {
      btn.addEventListener('click', () => openReport(btn.getAttribute('data-preview')));
    });
    host.querySelectorAll('[data-export]').forEach((btn) => {
      btn.addEventListener('click', () => downloadReport(btn.getAttribute('data-export'), btn.getAttribute('data-format')));
    });
  }

  function getPlanLabel(plan, fallback) {
    return plan.title || plan.name || plan.objective || plan.month || plan.quarter || plan.year || fallback;
  }

  function renderSavedPlans(data) {
    const host = document.getElementById('savedPlansPanel');
    if (!host) return;
    const source = data || plansCache || {
      monthlyPlans: [],
      quarterlyPlans: [],
      annualPlans: [],
      counts: { monthly: 0, quarterly: 0, annual: 0 }
    };
    const groups = [
      { title: 'Monthly Plans', items: source.monthlyPlans || [], count: source.counts?.monthly },
      { title: 'Quarterly Plans', items: source.quarterlyPlans || [], count: source.counts?.quarterly },
      { title: 'Annual Plans', items: source.annualPlans || [], count: source.counts?.annual }
    ];

    host.innerHTML = groups.map((group) => {
      const items = (group.items || []).slice(0, 5);
      const count = group.count ?? (group.items || []).length;
      return `
        <section class="plan-card">
          <h4>${group.title}</h4>
          <div class="plan-count">${count}</div>
          <div class="plan-meta">From RDO|MMS database</div>
          <ul class="plan-list">
            ${items.length ? items.map((item) => `
              <li>
                <strong>${getPlanLabel(item, group.title)}</strong>
                <span>${item.updatedAt || item.savedAt || item.submittedAt || item.createdAt || 'recent'}</span>
              </li>
            `).join('') : '<li><strong>No records</strong><span>yet</span></li>'}
          </ul>
        </section>
      `;
    }).join('');
  }

  function updateStatsFromSummary(summary) {
    if (!summary) return;
    const totalEl = document.getElementById('kpi-total');
    const scheduledEl = document.getElementById('kpi-scheduled');
    const lastEl = document.getElementById('kpi-last');
    const projectsEl = document.getElementById('kpi-projects');
    const beneficiariesEl = document.getElementById('kpi-beneficiaries');

    if (totalEl) totalEl.textContent = formatNumber(summary.historyCount || 0);
    if (scheduledEl) scheduledEl.textContent = formatNumber(summary.scheduledCount || 0);
    if (projectsEl) projectsEl.textContent = formatNumber(summary.projects || 0);
    if (beneficiariesEl) beneficiariesEl.textContent = formatNumber(summary.beneficiaries || 0);

    if (lastEl) {
      if (summary.lastGeneratedAt) {
        const date = new Date(summary.lastGeneratedAt);
        lastEl.textContent = date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
      } else {
        lastEl.textContent = '—';
      }
    }
  }

  function updateStats() {
    if (summaryCache) {
      updateStatsFromSummary(summaryCache);
      return;
    }
    const ctx = loadReportContext();
    updateStatsFromSummary({
      projects: ctx.projects.length,
      beneficiaries: ctx.beneficiaries.length,
      historyCount: ctx.reportHistory.length,
      scheduledCount: ctx.scheduledReports.length,
      lastGeneratedAt: ctx.reportHistory[0]?.generatedAt || null
    });
  }

  function renderReportHistory(rows) {
    const host = document.getElementById('reportHistoryList');
    if (!host) return;
    const list = Array.isArray(rows) ? rows : [];

    if (!list.length) {
      host.innerHTML = '<p class="empty-note">No reports generated yet.</p>';
      return;
    }

    host.innerHTML = list.slice(0, 12).map((row) => {
      const date = new Date(row.generatedAt || row.generated_at || Date.now());
      const dateLabel = date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
      return `
        <div class="history-item">
          <i class="fas fa-file-lines" style="color:var(--leaf);"></i>
          <div>
            <h5>${row.title || getReportTitle(row.reportType)}</h5>
            <p>${row.period || '—'} · ${getReportTitle(row.reportType)}</p>
          </div>
          <span class="history-date">${dateLabel}</span>
        </div>
      `;
    }).join('');
  }

  async function loadReportHistory() {
    if (!apiReady) {
      renderReportHistory(loadArray(['reportHistory']));
      return;
    }
    const rows = await apiRequest('history');
    renderReportHistory(rows);
  }

  function destroyCharts() {
    if (mainChartInstance) {
      try { mainChartInstance.destroy(); } catch (_) {}
      mainChartInstance = null;
    }
  }

  function getChartSection(rd) {
    return (rd.sections || []).find((sec) => sec.chartLabels?.length) || rd.sections?.[0] || null;
  }

  function collectTableRows(rd) {
    const rows = [];
    const sections = rd.sections || [];
    sections.forEach((sec) => {
      (sec.data || []).forEach((row) => {
        rows.push(sections.length > 1 ? { Section: sec.title, ...row } : { ...row });
      });
    });
    if (rows.length) return rows;

    const summary = getChartSection(rd)?.summary || {};
    return Object.entries(summary).map(([Metric, Value]) => ({ Metric, Value }));
  }

  function formatReportMeta(rd) {
    const parts = [`Period: ${rd.period}`];
    const filters = rd.filters || {};
    if (filters.station) parts.push(`Station: ${filters.stationLabel || filters.station}`);
    if (filters.department) parts.push(`Department: ${filters.departmentLabel || filters.department}`);
    parts.push(`Generated: ${new Date(rd.generatedAt).toLocaleString('en-GB')}`);
    return parts.join(' · ');
  }

  function renderFilterChips(rd) {
    const host = document.getElementById('filterChips');
    if (!host) return;

    const filters = rd.filters || {};
    const chips = [];
    if (filters.reportType) {
      chips.push({ icon: 'fa-file-lines', label: getReportTitle(filters.reportType) });
    }
    if (filters.startDate && filters.endDate) {
      chips.push({ icon: 'fa-calendar', label: `${filters.startDate} → ${filters.endDate}` });
    }
    if (filters.station) {
      chips.push({ icon: 'fa-location-dot', label: filters.stationLabel || filters.station });
    }
    if (filters.department) {
      chips.push({ icon: 'fa-building', label: filters.departmentLabel || filters.department });
    }
    if (typeof rd.recordCount === 'number') {
      chips.push({ icon: 'fa-database', label: `${formatNumber(rd.recordCount)} records` });
    }

    if (!chips.length) {
      host.hidden = true;
      host.innerHTML = '';
      return;
    }

    host.hidden = false;
    host.innerHTML = chips.map((chip) => (
      `<span class="filter-chip"><i class="fas ${chip.icon}"></i>${chip.label}</span>`
    )).join('');
  }

  function renderSummaryChips(rd) {
    const host = document.getElementById('summaryChips');
    if (!host) return;
    const summary = getChartSection(rd)?.summary || {};
    const entries = Object.entries(summary);
    if (!entries.length) {
      host.innerHTML = '';
      host.style.display = 'none';
      return;
    }
    host.style.display = 'flex';
    host.innerHTML = entries.map(([key, value]) => (
      `<span class="summary-chip">${key}: <strong>${value}</strong></span>`
    )).join('');
  }

  function renderReportTable(rd) {
    const host = document.getElementById('reportTableWrap');
    if (!host) return;
    const rows = collectTableRows(rd);
    if (!rows.length) {
      const filters = rd.filters || {};
      const hints = [];
      if (filters.station) hints.push(`station: ${filters.stationLabel || filters.station}`);
      if (filters.department) hints.push(`department: ${filters.departmentLabel || filters.department}`);
      const hintText = hints.length ? ` Filters applied — ${hints.join(', ')}.` : '';
      host.innerHTML = `<p class="empty-note" style="padding:1.25rem;text-align:center;color:var(--ghost)">No records match the selected filters for this report.${hintText}</p>`;
      return;
    }
    const keys = Object.keys(rows[0]);
    host.innerHTML = `
      <table class="rpt-table">
        <thead><tr>${keys.map((k) => `<th>${k}</th>`).join('')}</tr></thead>
        <tbody>
          ${rows.map((row) => `<tr>${keys.map((k) => `<td>${row[k] ?? '—'}</td>`).join('')}</tr>`).join('')}
        </tbody>
      </table>
    `;
  }

  function buildLinearYScale(chartData) {
    const values = (chartData || []).map(Number).filter(Number.isFinite);
    const maxVal = values.length ? Math.max(...values, 0) : 0;
    const allIntegers = values.length > 0 && values.every((n) => Number.isInteger(n));
    const useIntegerScale = allIntegers && maxVal <= 100;

    if (!useIntegerScale) {
      return {
        beginAtZero: true,
        grace: '5%',
        grid: { color: 'rgba(0,0,0,.06)' },
        ticks: {
          maxTicksLimit: 8,
          callback: (value) => {
            const num = Number(value);
            if (!Number.isFinite(num)) return value;
            if (Math.abs(num) >= 1000) return formatNumber(num);
            return Number.isInteger(num) ? num : num.toFixed(1);
          }
        }
      };
    }

    const axisMax = Math.max(5, Math.ceil(maxVal));

    return {
      beginAtZero: true,
      max: axisMax,
      grace: 0,
      grid: { color: 'rgba(0,0,0,.06)' },
      ticks: {
        stepSize: 1,
        precision: 0,
        callback: (value) => (Number.isInteger(value) ? value : '')
      }
    };
  }

  function renderMainChart(sec) {
    const canvas = document.getElementById('mainChart');
    const titleEl = document.getElementById('chartTitle');
    if (!canvas || typeof Chart === 'undefined' || !sec?.chartLabels?.length) return;

    if (titleEl) titleEl.textContent = sec.title || 'Visualization';
    const isPie = sec.chartType === 'doughnut' || sec.chartType === 'pie';
    const chartType = isPie ? 'doughnut' : 'bar';
    const colors = sec.chartColors || ['#4a7c28', '#3b9eca', '#e8a838', '#7ab648', '#e05252', '#2d5016'];

    mainChartInstance = new Chart(canvas.getContext('2d'), {
      type: chartType,
      data: {
        labels: sec.chartLabels,
        datasets: [{
          label: sec.title,
          data: sec.chartData || [],
          backgroundColor: isPie ? colors : colors.map((c) => `${c}cc`),
          borderColor: colors,
          borderWidth: isPie ? 2 : 1,
          borderRadius: isPie ? 0 : 8
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: isPie || (sec.chartLabels?.length || 0) <= 6,
            position: 'bottom'
          }
        },
        scales: isPie ? {} : {
          y: buildLinearYScale(sec.chartData),
          x: { grid: { display: false } }
        }
      }
    });
  }

  function renderReport(rd) {
    destroyCharts();

    const output = document.getElementById('reportOutput');
    const empty = document.getElementById('reportEmptyState');
    const titleEl = document.getElementById('reportTitle');
    const metaEl = document.getElementById('reportMeta');
    const tableTitle = document.getElementById('tableTitle');

    if (!output) return;

    if (titleEl) titleEl.textContent = rd.title || 'Report';
    if (metaEl) metaEl.textContent = formatReportMeta(rd);
    if (tableTitle) tableTitle.textContent = `${rd.title || 'Report'} — data table`;

    renderFilterChips(rd);
    renderSummaryChips(rd);
    renderMainChart(getChartSection(rd));
    renderReportTable(rd);

    if (empty) empty.style.display = 'none';
    output.classList.add('is-visible');
    output.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function closePreview() {
    destroyCharts();
    const output = document.getElementById('reportOutput');
    const empty = document.getElementById('reportEmptyState');
    if (output) output.classList.remove('is-visible');
    if (empty) empty.style.display = '';
    currentReport = null;
  }

  function viewReport() {
    if (!currentReport) {
      notify('Generate a report first.', 'warning');
      return;
    }
    const win = window.open('', '_blank');
    if (!win) {
      notify('Pop-up blocked. Allow pop-ups to open the full report.', 'warning');
      return;
    }
    win.document.write(buildPrintHtml(currentReport, { includeCharts: false }));
    win.document.close();
  }

  function sanitizeWordText(value) {
    return String(value ?? '')
      .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, '')
      .replace(/\u2014/g, '-')
      .replace(/\u00B7/g, '-');
  }

  function escapeExportHtml(value) {
    return sanitizeWordText(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /** Microsoft Word HTML (.doc) — opens reliably; html-docx altChunk often fails in modern Word. */
  function buildMsWordHtml(rd) {
    let sectionsHtml = '';

    (rd.sections || []).forEach((sec) => {
      sectionsHtml += `<h3 style="color:#1a3a0a;margin:14pt 0 8pt;">${escapeExportHtml(sec.title)}</h3>`;

      if (sec.summary && Object.keys(sec.summary).length) {
        sectionsHtml += '<table border="1" cellspacing="0" cellpadding="6" style="border-collapse:collapse;width:100%;margin-bottom:10pt;">';
        sectionsHtml += '<tr><th style="background:#1a3a0a;color:#fff;">Metric</th><th style="background:#1a3a0a;color:#fff;">Value</th></tr>';
        Object.entries(sec.summary).forEach(([key, value]) => {
          sectionsHtml += `<tr><td>${escapeExportHtml(key)}</td><td>${escapeExportHtml(value)}</td></tr>`;
        });
        sectionsHtml += '</table>';
      }

      if (sec.data?.length) {
        const keys = Object.keys(sec.data[0]);
        sectionsHtml += '<table border="1" cellspacing="0" cellpadding="6" style="border-collapse:collapse;width:100%;margin-bottom:10pt;">';
        sectionsHtml += `<tr>${keys.map((k) => `<th style="background:#1a3a0a;color:#fff;">${escapeExportHtml(k)}</th>`).join('')}</tr>`;
        sec.data.forEach((row) => {
          sectionsHtml += `<tr>${keys.map((k) => `<td>${escapeExportHtml(row[k] ?? '-')}</td>`).join('')}</tr>`;
        });
        sectionsHtml += '</table>';
      }
    });

    const generated = new Date(rd.generatedAt).toLocaleString('en-GB');
    const filterLine = [];
    const filters = rd.filters || {};
    if (filters.station) filterLine.push(`Station: ${filters.stationLabel || filters.station}`);
    if (filters.department) filterLine.push(`Department: ${filters.departmentLabel || filters.department}`);
    const filterText = filterLine.length ? ` · ${filterLine.join(' · ')}` : '';

    return [
      '<html xmlns:o="urn:schemas-microsoft-com:office:office"',
      ' xmlns:w="urn:schemas-microsoft-com:office:word"',
      ' xmlns="http://www.w3.org/TR/REC-html40">',
      '<head><meta charset="utf-8">',
      '<!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View><w:Zoom>100</w:Zoom></w:WordDocument></xml><![endif]-->',
      `<title>${escapeExportHtml(rd.title)}</title>`,
      '<style>',
      '@page Section1 { size: 595.3pt 841.9pt; margin: 56.7pt; }',
      'div.Section1 { page: Section1; }',
      'body { font-family: Arial, Helvetica, sans-serif; font-size: 11pt; color: #111; }',
      'h1 { color: #1f5a36; font-size: 18pt; }',
      'table { border-collapse: collapse; width: 100%; }',
      'th, td { border: 1px solid #333333; padding: 6pt 8pt; vertical-align: top; }',
      '</style></head><body>',
      '<div class="Section1">',
      `<h1>${escapeExportHtml(rd.title)}</h1>`,
      `<p style="color:#666;font-size:10pt;">Period: ${escapeExportHtml(rd.period)} - Generated: ${escapeExportHtml(generated)}${escapeExportHtml(filterText)}</p>`,
      sectionsHtml,
      '<p style="margin-top:20pt;font-size:8pt;color:#888;text-align:center;">RDO|MMS Report Studio</p>',
      '</div></body></html>'
    ].join('');
  }

  function buildPrintHtml(rd, options = {}) {
    const filters = rd.filters || {};
    const filterParts = [];
    if (filters.station) filterParts.push(`Station: ${filters.stationLabel || filters.station}`);
    if (filters.department) filterParts.push(`Department: ${filters.departmentLabel || filters.department}`);
    const filterMeta = filterParts.length ? ` · ${filterParts.join(' · ')}` : '';

    let body = `
      <h1>${rd.title}</h1>
      <p class="meta">Period: ${rd.period}${filterMeta} · Generated: ${new Date(rd.generatedAt).toLocaleString('en-GB')}</p>
    `;

    rd.sections.forEach((sec) => {
      body += `<h2>${sec.title}</h2>`;
      if (sec.summary) {
        body += '<table><thead><tr><th>Metric</th><th>Value</th></tr></thead><tbody>';
        Object.entries(sec.summary).forEach(([key, value]) => {
          body += `<tr><td>${key}</td><td>${value}</td></tr>`;
        });
        body += '</tbody></table>';
      }
      if (sec.data?.length) {
        const keys = Object.keys(sec.data[0]);
        body += '<table><thead><tr>' + keys.map((k) => `<th>${k}</th>`).join('') + '</tr></thead><tbody>';
        sec.data.forEach((row) => {
          body += '<tr>' + keys.map((k) => `<td>${row[k] ?? '—'}</td>`).join('') + '</tr>';
        });
        body += '</tbody></table>';
      }
    });

    return `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>${rd.title}</title>
      <style>
        body{font-family:Arial,sans-serif;padding:24px;color:#111}
        h1{font-size:22px;margin:0 0 6px}
        .meta{color:#666;font-size:12px;margin-bottom:20px}
        h2{font-size:15px;margin:20px 0 8px;color:#1a3a0a}
        table{width:100%;border-collapse:collapse;margin-bottom:14px;font-size:11px}
        th,td{border:1px solid #d4e5c8;padding:6px 8px;text-align:left}
        th{background:#1a3a0a;color:#fff}
      </style></head><body>${body}
      <script>window.onload=function(){window.print();};<\/script></body></html>`;
  }

  function getFilename(suffix) {
    const base = (currentReport?.title || 'Report').replace(/\s+/g, '_');
    const date = new Date().toISOString().split('T')[0];
    return `${base}_${suffix}_${date}`;
  }

  function triggerDownload(blob, filename) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    setTimeout(() => URL.revokeObjectURL(url), 500);
  }

  function exportByFormat(format) {
    if (!currentReport) {
      notify('Generate a report first.', 'warning');
      return;
    }
    try {
      if (format === 'pdf') exportAllPDF();
      else if (format === 'excel') exportAllExcel();
      else if (format === 'csv') exportAllCSV();
      else if (format === 'word') exportAllWord();
      else notify('Unsupported export format.', 'warning');
    } catch (error) {
      notify(error.message || 'Export failed.', 'error');
    }
  }

  function exportAllPDF() {
    const win = window.open('', '_blank');
    if (!win) {
      notify('Pop-up blocked. Allow pop-ups for PDF export.', 'warning');
      return;
    }
    win.document.write(buildPrintHtml(currentReport));
    win.document.close();
    notify('PDF print view opened.', 'success');
  }

  function exportAllExcel() {
    if (typeof XLSX === 'undefined') {
      notify('Excel library not loaded.', 'error');
      return;
    }
    const workbook = XLSX.utils.book_new();
    currentReport.sections.forEach((sec, idx) => {
      const rows = [['Metric', 'Value'], ...Object.entries(sec.summary || {})];
      const sheet = XLSX.utils.aoa_to_sheet(rows);
      XLSX.utils.book_append_sheet(workbook, sheet, `${idx + 1}_Summary`.slice(0, 31));
      if (sec.data?.length) {
        const keys = Object.keys(sec.data[0]);
        const dataSheet = XLSX.utils.aoa_to_sheet([keys, ...sec.data.map((row) => keys.map((k) => row[k] ?? ''))]);
        XLSX.utils.book_append_sheet(workbook, dataSheet, `${idx + 1}_Data`.slice(0, 31));
      }
    });
    XLSX.writeFile(workbook, `${getFilename('Full')}.xlsx`);
    notify('Excel file downloaded.', 'success');
  }

  function exportAllCSV() {
    let csv = `"${currentReport.title}"\n"Period: ${currentReport.period}"\n\n`;
    currentReport.sections.forEach((sec) => {
      csv += `"${sec.title}"\n`;
      if (sec.summary) {
        csv += '"Metric","Value"\n';
        Object.entries(sec.summary).forEach(([key, value]) => {
          csv += `"${key}","${String(value).replace(/"/g, '""')}"\n`;
        });
        csv += '\n';
      }
      if (sec.data?.length) {
        const keys = Object.keys(sec.data[0]);
        csv += keys.map((k) => `"${k}"`).join(',') + '\n';
        sec.data.forEach((row) => {
          csv += keys.map((k) => `"${String(row[k] ?? '').replace(/"/g, '""')}"`).join(',') + '\n';
        });
      }
      csv += '\n';
    });
    triggerDownload(new Blob([csv], { type: 'text/csv;charset=utf-8' }), `${getFilename('Full')}.csv`);
    notify('CSV file downloaded.', 'success');
  }

  function exportAllWord() {
    const html = buildMsWordHtml(currentReport);
    triggerDownload(
      new Blob(['\uFEFF', html], { type: 'application/msword;charset=utf-8' }),
      `${getFilename('Full')}.doc`
    );
    notify('Word document downloaded (.doc). Open it with Microsoft Word.', 'success');
  }

  function exportSection(format, secId) {
    if (!currentReport) return;
    const idx = parseInt(secId.replace('sec_', ''), 10);
    const sec = currentReport.sections[idx];
    if (!sec) return;
    const partial = { ...currentReport, title: `${currentReport.title} — ${sec.title}`, sections: [sec] };
    const previous = currentReport;
    currentReport = partial;
    exportByFormat(format);
    currentReport = previous;
  }

  async function scheduleReport() {
    const values = getFormValues();
    if (!values.reportType) {
      notify('Select a report type first.', 'warning');
      return;
    }
    const freq = prompt('Frequency (daily / weekly / monthly):', 'monthly');
    if (!freq) return;

    try {
      if (apiReady) {
        await apiRequest('schedule', {
          method: 'POST',
          body: {
            reportType: values.reportType,
            startDate: values.startDate,
            endDate: values.endDate,
            frequency: freq
          }
        });
      } else {
        const list = loadArray(['scheduledReports']);
        list.unshift({
          id: Date.now(),
          reportType: values.reportType,
          startDate: values.startDate,
          endDate: values.endDate,
          frequency: freq,
          createdAt: new Date().toISOString(),
          active: true
        });
        localStorage.setItem('scheduledReports', JSON.stringify(list));
      }
      await loadScheduledReports();
      await refreshSummary();
      notify('Report scheduled.', 'success');
    } catch (error) {
      notify(error.message || 'Failed to schedule report.', 'error');
    }
  }

  function renderScheduledReports(list) {
    const host = document.getElementById('scheduledReportsList');
    if (!host) return;

    if (!list.length) {
      host.innerHTML = '<p class="empty-note">No scheduled reports yet.</p>';
      return;
    }

    host.innerHTML = list.map((row) => `
      <div class="sch-item">
        <span class="sch-dot ${row.active !== false ? '' : 'inactive'}"></span>
        <div class="sch-info">
          <h5>${getReportTitle(row.reportType)}</h5>
          <p>Every ${row.frequency || 'monthly'} · ${row.startDate || row.start_date || '—'} → ${row.endDate || row.end_date || '—'}</p>
        </div>
        <span class="badge ${row.active !== false ? 'badge-green' : 'badge-red'}">${row.active !== false ? 'Active' : 'Inactive'}</span>
        <button type="button" class="btn btn-danger btn-sm" data-delete-schedule="${row.id || row.schedule_id}">Delete</button>
      </div>
    `).join('');

    host.querySelectorAll('[data-delete-schedule]').forEach((btn) => {
      btn.addEventListener('click', () => deleteScheduledReport(btn.getAttribute('data-delete-schedule')));
    });
  }

  async function loadScheduledReports() {
    if (!apiReady) {
      renderScheduledReports(loadArray(['scheduledReports']));
      return;
    }
    const rows = await apiRequest('schedules');
    renderScheduledReports(rows);
  }

  async function deleteScheduledReport(id) {
    if (!confirm('Delete this scheduled report?')) return;
    try {
      if (apiReady) {
        await apiRequest('schedule', { method: 'DELETE', params: { id } });
      } else {
        const list = loadArray(['scheduledReports']).filter((row) => String(row.id) !== String(id));
        localStorage.setItem('scheduledReports', JSON.stringify(list));
      }
      await loadScheduledReports();
      await refreshSummary();
      notify('Scheduled report removed.', 'success');
    } catch (error) {
      notify(error.message || 'Failed to delete schedule.', 'error');
    }
  }

  async function saveToHistory(rd, type) {
    if (apiReady) {
      await apiRequest('history', {
        method: 'POST',
        body: {
          reportType: type,
          title: rd.title,
          period: rd.period
        }
      });
      await loadReportHistory();
      await refreshSummary();
      return;
    }
    const history = loadArray(['reportHistory']);
    history.unshift({
      id: Date.now(),
      reportType: type,
      title: rd.title,
      generatedAt: rd.generatedAt,
      period: rd.period
    });
    localStorage.setItem('reportHistory', JSON.stringify(history.slice(0, 100)));
    renderReportHistory(history);
    updateStats();
  }

  function initializeDates() {
    applyDatePreset('this-month');
    const start = document.getElementById('startDate');
    const end = document.getElementById('endDate');
    if (start && PAGE.monthStart && !start.value) start.value = PAGE.monthStart;
    if (end && PAGE.monthEnd && !end.value) end.value = PAGE.monthEnd;
  }

  async function refreshSummary() {
    if (!apiReady) return;
    summaryCache = await apiRequest('summary');
    updateStatsFromSummary(summaryCache);
  }

  async function refreshPlans() {
    if (!apiReady) return;
    plansCache = await apiRequest('plans');
    renderSavedPlans(plansCache);
  }

  async function bootstrapApi() {
    setLoading(true);
    try {
      if (!REPORT_ACCESS.allowedReportTypes?.length) {
        const typesPayload = await apiRequest('types');
        if (typesPayload && typeof typesPayload === 'object') {
          Object.assign(REPORT_ACCESS, typesPayload);
          applyReportTypeRestrictions();
        }
      }
      await refreshSummary();
      apiReady = true;
    } catch (error) {
      apiReady = false;
      if (error.status === 401) {
        notify('Sign in to sync Report Studio with the database.', 'warning');
      }
    } finally {
      setLoading(false);
    }
  }

  function renderActiveFiltersPreview() {
    const el = document.getElementById('activeFiltersPreview');
    if (!el) return;

    const values = getFormValues();
    const parts = [];

    if (values.reportType) {
      parts.push(`<strong>Type:</strong> ${escapeExportHtml(getReportTitle(values.reportType))}`);
    }
    if (values.startDate && values.endDate) {
      parts.push(`<strong>Period:</strong> ${escapeExportHtml(values.startDate)} → ${escapeExportHtml(values.endDate)}`);
    }
    if (values.station) {
      const label = window.RDOMMSOrganizations
        ? RDOMMSOrganizations.getLabel(values.station)
        : values.station;
      parts.push(`<strong>Station:</strong> ${escapeExportHtml(label)}`);
    }
    if (values.department) {
      const label = window.RDOMMSDepartments
        ? RDOMMSDepartments.getLabel(values.department)
        : values.department;
      parts.push(`<strong>Department:</strong> ${escapeExportHtml(label)}`);
    }

    if (!parts.length) {
      el.innerHTML = 'Select report type, period, and optional station/department — then click <strong>Generate report</strong>.';
      return;
    }

    el.innerHTML = `${parts.join(' · ')} — click <strong>Generate report</strong> to apply.`;
  }

  function bindUi() {
    document.querySelectorAll('[data-preset]').forEach((btn) => {
      btn.addEventListener('click', () => {
        applyDatePreset(btn.getAttribute('data-preset'));
        renderActiveFiltersPreview();
      });
    });
    document.getElementById('generateBtn')?.addEventListener('click', generateReport);
    document.getElementById('exportPdfBtn')?.addEventListener('click', () => exportByFormat('pdf'));
    document.getElementById('exportDocxBtn')?.addEventListener('click', () => exportByFormat('word'));

    ['reportType', 'startDate', 'endDate', 'stationFilter', 'departmentName'].forEach((id) => {
      const node = document.getElementById(id);
      if (!node) return;
      node.addEventListener('change', renderActiveFiltersPreview);
      if (node.tagName === 'INPUT') {
        node.addEventListener('input', renderActiveFiltersPreview);
      }
    });
  }

  function applyRoleScopeDefaults() {
    const defaults = REPORT_ACCESS.scopeDefaults || PAGE.reportAccess?.scopeDefaults || {};
    const stationEl = document.getElementById('stationFilter');
    const deptEl = document.getElementById('departmentName');

    if (stationEl && defaults.station) {
      stationEl.value = defaults.station;
      if (defaults.lockStation) {
        stationEl.disabled = true;
      }
    }

    if (deptEl && defaults.department) {
      deptEl.value = defaults.department;
      if (defaults.lockDepartment) {
        deptEl.disabled = true;
      }
    }
  }

  function boot() {
    if (window.RDOMMSOrganizations) RDOMMSOrganizations.fillAll();
    if (window.RDOMMSDepartments) RDOMMSDepartments.fillAll();
    applyRoleScopeDefaults();
    applyReportTypeRestrictions();
    hideUnavailableReportLinks();
    initializeDates();
    bindUi();
    renderActiveFiltersPreview();
    bootstrapApi();
  }

  function hideUnavailableReportLinks() {
    const access = window.RDOMMS_REPORTS_PAGE?.reportAccess || {};
    const role = String(access.role || '').toLowerCase();
    const canVariance = access.canAccessVarianceAnalysis || role === 'admin' || role === 'accountant';
    const link = document.getElementById('linkVarianceAnalysis');
    if (link && !canVariance) {
      link.style.display = 'none';
    }
  }

  document.addEventListener('DOMContentLoaded', boot);

  window.RDOMMSReportStudio = {
    generateReport,
    openReport,
    downloadReport,
    exportByFormat,
    closePreview,
    viewReport
  };
})();
