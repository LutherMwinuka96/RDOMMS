/**
 * Shared finance filters — department, organization center, quarter, year
 * sync within a page and across Budget Allocation + Expense Tracking.
 */
(function (global) {
  const STORAGE_KEY = 'rdomms_finance_filters';

  const FIELD_SELECTORS = {
    organization: [
      '#quickOrganization',
      '#filterOrganizationTable',
      '#expenseOrganization',
      '#budgetFilterOrganization'
    ],
    department: [
      '#quickDepartment',
      '#filterDepartmentTable',
      '#expenseDepartment',
      '#budgetFilterDepartment'
    ],
    quarter: [
      '#quickQuarter',
      '#filterQuarterTable',
      '#expenseQuarter',
      '#budgetFilterQuarter'
    ],
    year: [
      '#quickYear',
      '#filterYearTable',
      '#expenseFiscalYear',
      '#budgetFilterYear'
    ]
  };

  let syncing = false;

  function readStored() {
    try {
      const raw = global.localStorage.getItem(STORAGE_KEY);
      return raw ? JSON.parse(raw) : {};
    } catch (error) {
      return {};
    }
  }

  function writeStored(filters) {
    try {
      global.localStorage.setItem(STORAGE_KEY, JSON.stringify(filters));
    } catch (error) {
      /* ignore quota errors */
    }
  }

  function collectFromPage() {
    const filters = {};
    Object.entries(FIELD_SELECTORS).forEach(([field, selectors]) => {
      for (let i = 0; i < selectors.length; i += 1) {
        const el = document.querySelector(selectors[i]);
        if (el && el.value) {
          filters[field] = el.value;
          break;
        }
      }
    });
    return filters;
  }

  function applyField(field, value, sourceEl) {
    FIELD_SELECTORS[field].forEach((selector) => {
      const el = document.querySelector(selector);
      if (!el || el === sourceEl) return;
      if (value && !Array.from(el.options).some((opt) => opt.value === value)) return;
      el.value = value || '';
    });
  }

  function applyFilters(filters, sourceEl) {
    syncing = true;
    Object.entries(filters).forEach(([field, value]) => {
      applyField(field, value, sourceEl);
    });
    syncing = false;
  }

  function loadDefaults() {
    const stored = readStored();
    const user = (() => {
      try {
        return JSON.parse(global.localStorage.getItem('currentUser') || '{}');
      } catch (error) {
        return {};
      }
    })();

    if (!stored.organization && user.organization && global.RDOMMSOrganizations) {
      stored.organization = RDOMMSOrganizations.getValue(user.organization);
    }
    if (!stored.department && user.department && global.RDOMMSDepartments) {
      stored.department = RDOMMSDepartments.getValue(user.department);
    }

    applyFilters(stored);
    return stored;
  }

  function saveFromElement(sourceEl) {
    if (syncing) return;
    const filters = { ...readStored(), ...collectFromPage() };
    writeStored(filters);
    applyFilters(filters, sourceEl);
    global.dispatchEvent(new CustomEvent('rdommsFinanceFiltersChanged', { detail: filters }));
  }

  function bind(root = document) {
    Object.values(FIELD_SELECTORS).flat().forEach((selector) => {
      const el = root.querySelector(selector);
      if (!el || el.dataset.financeFilterBound === '1') return;
      el.dataset.financeFilterBound = '1';
      el.addEventListener('change', () => saveFromElement(el));
    });
  }

  function clearFilters() {
    writeStored({});
    applyFilters({});
    global.dispatchEvent(new CustomEvent('rdommsFinanceFiltersChanged', { detail: {} }));
  }

  global.RDOMMSFinanceFilters = {
    loadDefaults,
    bind,
    clearFilters,
    collectFromPage,
    readStored
  };

  document.addEventListener('DOMContentLoaded', () => {
    loadDefaults();
    bind();
  });
})(window);
