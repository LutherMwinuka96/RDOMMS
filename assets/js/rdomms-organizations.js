/**
 * Canonical RDO organization centers — keep in sync with config/organization-centers.php and auth/signup.html
 */
(function (global) {
  const ORGANIZATIONS = [
    { value: 'rdo_mafinga', label: 'RDO Mafinga' },
    { value: 'rdo_mdabulo', label: 'RDO Mdabulo' },
    { value: 'rdo_ibwanzi', label: 'RDO Ibwanzi' },
    { value: 'rdo_kilolo', label: 'RDO Kilolo' },
    { value: 'rdo_malangali', label: 'RDO Malangali' }
  ];

  function list() {
    return ORGANIZATIONS.slice();
  }

  function getLabel(value) {
    const key = String(value ?? '').trim();
    const found = ORGANIZATIONS.find((item) => item.value === key || item.label === key);
    return found ? found.label : (key ? key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) : '');
  }

  function getValue(labelOrValue) {
    const key = String(labelOrValue ?? '').trim();
    const found = ORGANIZATIONS.find((item) => item.value === key || item.label === key);
    if (found) return found.value;
    const legacy = ORGANIZATIONS.find((item) => item.value === `rdo_${key}` || item.value.endsWith(`_${key}`));
    return legacy ? legacy.value : key;
  }

  function fillSelect(select, options = {}) {
    if (!select) return;
    const {
      includeEmpty = true,
      emptyLabel = 'Select organization center',
      selectedValue = select.value || ''
    } = options;

    select.innerHTML = '';
    if (includeEmpty) {
      const empty = document.createElement('option');
      empty.value = '';
      empty.textContent = emptyLabel;
      select.appendChild(empty);
    }

    ORGANIZATIONS.forEach((item) => {
      const option = document.createElement('option');
      option.value = item.value;
      option.textContent = item.label;
      select.appendChild(option);
    });

    if (selectedValue) {
      const match = ORGANIZATIONS.find((item) => item.value === selectedValue || item.label === selectedValue);
      if (match) select.value = match.value;
    }
  }

  function fillAll(root = document, options = {}) {
    root.querySelectorAll('[data-rdomms-organization]').forEach((select) => {
      fillSelect(select, {
        includeEmpty: select.getAttribute('data-include-empty') !== 'false',
        emptyLabel: select.getAttribute('data-empty-label') || options.emptyLabel || 'Select organization center',
        selectedValue: select.value
      });
    });
  }

  global.RDOMMS_ORGANIZATIONS = ORGANIZATIONS;
  global.RDOMMSOrganizations = {
    list,
    getLabel,
    getValue,
    fillSelect,
    fillAll
  };

  document.addEventListener('DOMContentLoaded', () => fillAll());
})(window);
