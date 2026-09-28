/**
 * Canonical RDOMMS departments — keep in sync with config/departments.php and auth/signup.html
 */
(function (global) {
  const DEPARTMENTS = [
    { value: 'orphanage_support', label: 'Orphanage Support' },
    { value: 'water_transmission_association', label: 'Water Transmission Association' },
    { value: 'administration', label: 'Administration' },
    { value: 'agriculture', label: 'Agriculture' },
    { value: 'pottery', label: 'Pottery' },
    { value: 'vocational_training_center', label: 'Vocational Training Center' },
    { value: 'store', label: 'Store' },
    { value: 'finance', label: 'Department of Finance' },
    { value: 'marketing_procurement_supply', label: 'Department of Marketing, Procurement & Supply' }
  ];

  function list() {
    return DEPARTMENTS.slice();
  }

  function labels() {
    return DEPARTMENTS.map((item) => item.label);
  }

  function getLabel(value) {
    const key = String(value ?? '').trim();
    const found = DEPARTMENTS.find((item) => item.value === key || item.label === key);
    return found ? found.label : key;
  }

  function getValue(labelOrValue) {
    const key = String(labelOrValue ?? '').trim();
    const found = DEPARTMENTS.find((item) => item.value === key || item.label === key);
    return found ? found.value : key;
  }

  function fillSelect(select, options = {}) {
    if (!select) return;
    const {
      includeEmpty = true,
      emptyLabel = 'Select Department',
      selectedValue = select.value || ''
    } = options;

    select.innerHTML = '';
    if (includeEmpty) {
      const empty = document.createElement('option');
      empty.value = '';
      empty.textContent = emptyLabel;
      select.appendChild(empty);
    }

    DEPARTMENTS.forEach((item) => {
      const option = document.createElement('option');
      option.value = item.value;
      option.textContent = item.label;
      select.appendChild(option);
    });

    if (selectedValue) {
      const match = DEPARTMENTS.find((item) => item.value === selectedValue || item.label === selectedValue);
      if (match) select.value = match.value;
    }
  }

  function fillAll(root = document, options = {}) {
    root.querySelectorAll('[data-rdomms-department]').forEach((select) => {
      fillSelect(select, {
        includeEmpty: select.getAttribute('data-include-empty') !== 'false',
        emptyLabel: select.getAttribute('data-empty-label') || options.emptyLabel || 'Select Department',
        selectedValue: select.value
      });
    });
  }

  function isKnownDepartment(value) {
    const key = String(value ?? '').trim();
    return DEPARTMENTS.some((item) => item.value === key || item.label === key);
  }

  global.RDOMMS_DEPARTMENTS = DEPARTMENTS;
  global.RDOMMSDepartments = {
    list,
    labels,
    getLabel,
    getValue,
    fillSelect,
    fillAll,
    isKnownDepartment
  };

  document.addEventListener('DOMContentLoaded', () => fillAll());
})(window);
