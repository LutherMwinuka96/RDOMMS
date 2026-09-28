/**
 * Canonical RDOMMS year/month ranges — keep in sync with config/dates.php
 */
(function (global) {
  const YEAR_MIN = 1980;
  const YEAR_MAX = 2070;
  const MONTH_NAMES = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'
  ];

  function yearRange(descending) {
    const list = [];
    for (let year = YEAR_MIN; year <= YEAR_MAX; year += 1) {
      list.push(year);
    }
    return descending ? list.reverse() : list;
  }

  function currentYear() {
    return new Date().getFullYear();
  }

  function currentMonthIndex() {
    return new Date().getMonth() + 1;
  }

  function currentMonthName() {
    return MONTH_NAMES[new Date().getMonth()];
  }

  function currentQuarterSlug() {
    return 'q' + Math.ceil(currentMonthIndex() / 3);
  }

  function currentQuarterLabel() {
    return 'Q' + Math.ceil(currentMonthIndex() / 3);
  }

  function clampYear(value) {
    const parsed = parseInt(String(value), 10);
    if (!Number.isFinite(parsed)) return currentYear();
    return Math.min(YEAR_MAX, Math.max(YEAR_MIN, parsed));
  }

  function fillYearSelect(select, options) {
    if (!select) return;

    const opts = options || {};
    const {
      includeEmpty = false,
      emptyLabel = 'Select year',
      emptyValue = '',
      allYearsLabel = null,
      allYearsValue = 'all',
      selectedValue = null,
      descending = true,
    } = opts;

    const years = yearRange(descending);
    const selected = selectedValue != null && selectedValue !== ''
      ? String(clampYear(selectedValue))
      : String(currentYear());

    select.innerHTML = '';

    if (allYearsLabel) {
      const allOption = document.createElement('option');
      allOption.value = allYearsValue;
      allOption.textContent = typeof allYearsLabel === 'string' ? allYearsLabel : 'All years';
      select.appendChild(allOption);
    }

    if (includeEmpty) {
      const emptyOption = document.createElement('option');
      emptyOption.value = emptyValue;
      emptyOption.textContent = emptyLabel;
      select.appendChild(emptyOption);
    }

    years.forEach((year) => {
      const option = document.createElement('option');
      option.value = String(year);
      option.textContent = String(year);
      select.appendChild(option);
    });

    if ([...select.options].some((option) => option.value === selected)) {
      select.value = selected;
    }
  }

  function fillMonthSelect(select, options) {
    if (!select) return;

    const opts = options || {};
    const {
      includeEmpty = false,
      emptyLabel = 'Select month',
      emptyValue = '',
      selectedValue = null,
    } = opts;

    const selected = selectedValue != null && selectedValue !== ''
      ? String(selectedValue)
      : currentMonthName();

    select.innerHTML = '';

    if (includeEmpty) {
      const emptyOption = document.createElement('option');
      emptyOption.value = emptyValue;
      emptyOption.textContent = emptyLabel;
      select.appendChild(emptyOption);
    }

    MONTH_NAMES.forEach((name) => {
      const option = document.createElement('option');
      option.value = name;
      option.textContent = name;
      select.appendChild(option);
    });

    if ([...select.options].some((option) => option.value === selected)) {
      select.value = selected;
    }
  }

  function initYearInput(input, options) {
    if (!input) return;

    const opts = options || {};
    if (input.tagName === 'SELECT') {
      fillYearSelect(input, opts);
      return;
    }

    input.min = String(YEAR_MIN);
    input.max = String(YEAR_MAX);
    input.value = String(clampYear(opts.selectedValue != null ? opts.selectedValue : currentYear()));
  }

  global.RDOMMSDates = {
    YEAR_MIN,
    YEAR_MAX,
    MONTH_NAMES,
    yearRange,
    currentYear,
    currentMonthIndex,
    currentMonthName,
    currentQuarterSlug,
    currentQuarterLabel,
    clampYear,
    fillYearSelect,
    fillMonthSelect,
    initYearInput,
  };
})(typeof window !== 'undefined' ? window : this);
