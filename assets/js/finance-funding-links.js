/**
 * Shared funding-source picker for budget allocation and expense tracking.
 */
(function () {
  const TYPE_LABELS = {
    project: 'Project',
    monthly_plan: 'Monthly Plan',
    quarterly_plan: 'Quarterly Plan',
    annual_plan: 'Annual Plan'
  };

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function formatMoney(value) {
    if (window.RDOMMSNumberFormat && typeof RDOMMSNumberFormat.formatCurrency === 'function') {
      return RDOMMSNumberFormat.formatCurrency(value);
    }
    const amount = Number(value) || 0;
    return `TSH ${amount.toLocaleString()}`;
  }

  function create(config) {
    const apiBase = config.apiBase || '../api/finance/funding-sources.php';
    const typeSelect = document.getElementById(config.typeSelectId);
    const itemSelect = document.getElementById(config.itemSelectId);
    const hintEl = config.hintId ? document.getElementById(config.hintId) : null;
    const filterIds = config.filterFieldIds || {};
    const amountField = config.amountFieldId ? document.getElementById(config.amountFieldId) : null;

    let sources = { flat: [] };
    let loading = false;
    let pendingValues = null;

    function getFilterParams() {
      const params = new URLSearchParams();
      const organization = document.getElementById(filterIds.organization || '')?.value || '';
      const department = document.getElementById(filterIds.department || '')?.value || '';
      const quarter = document.getElementById(filterIds.quarter || '')?.value || '';
      const year = document.getElementById(filterIds.year || '')?.value || '';
      if (organization) params.set('organization', organization);
      if (department) params.set('department', department);
      if (quarter) params.set('quarter', quarter);
      if (year) params.set('fiscal_year', year);
      return params;
    }

    function getItemsForType(type) {
      if (!type) return [];
      const keyMap = {
        project: 'projects',
        monthly_plan: 'monthly_plans',
        quarterly_plan: 'quarterly_plans',
        annual_plan: 'annual_plans'
      };
      const key = keyMap[type];
      return key && Array.isArray(sources[key]) ? sources[key] : [];
    }

    function findItem(type, id) {
      if (!type || !id) return null;
      return getItemsForType(type).find((item) => String(item.id) === String(id)) || null;
    }

    function updateHint(item) {
      if (!hintEl) return;
      if (!item) {
        hintEl.textContent = 'Link this entry to a project or plan that needs funding (optional).';
        return;
      }
      const parts = [item.label];
      if (item.subtitle) parts.push(item.subtitle);
      if (item.budget > 0) parts.push(`Budget: ${formatMoney(item.budget)}`);
      if (Number(item.spent_committed ?? item.spent) > 0) {
        parts.push(`Spent: ${formatMoney(item.spent_committed ?? item.spent)}`);
      }
      hintEl.textContent = parts.join(' · ');
    }

    function populateItemSelect(type, selectedId) {
      if (!itemSelect) return;
      const items = getItemsForType(type);
      const options = ['<option value="">Select item</option>'];
      items.forEach((item) => {
        const budgetHint = item.budget > 0 ? ` — ${formatMoney(item.budget)}` : '';
        options.push(
          `<option value="${escapeHtml(item.id)}" data-label="${escapeHtml(item.label)}">${escapeHtml(item.label)}${escapeHtml(budgetHint)}</option>`
        );
      });
      itemSelect.innerHTML = options.join('');
      itemSelect.disabled = !type || loading;
      if (selectedId) {
        itemSelect.value = String(selectedId);
        if (itemSelect.value !== String(selectedId)) {
          const fallback = pendingValues?.linked_label || `Linked ${TYPE_LABELS[type] || type}`;
          itemSelect.innerHTML += `<option value="${escapeHtml(selectedId)}" selected>${escapeHtml(fallback)}</option>`;
          itemSelect.value = String(selectedId);
        }
      }
      updateHint(findItem(type, itemSelect.value));
    }

    function applySourceToForm(item, options) {
      if (!item || options?.skipApply) return;
      if (config.onApplySource) {
        config.onApplySource(item);
        return;
      }
      if (amountField && Number(item.budget) > 0 && !amountField.value) {
        amountField.value = String(item.budget);
      }
    }

    function handleTypeChange() {
      const type = typeSelect?.value || '';
      populateItemSelect(type, '');
      if (itemSelect) itemSelect.value = '';
      updateHint(null);
    }

    function handleItemChange() {
      const type = typeSelect?.value || '';
      const item = findItem(type, itemSelect?.value || '');
      updateHint(item);
      applySourceToForm(item);
    }

    async function refresh() {
      if (!typeSelect || !itemSelect) return;
      loading = true;
      itemSelect.disabled = true;
      try {
        const params = getFilterParams();
        const response = await fetch(`${apiBase}?${params.toString()}`, {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin'
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload || payload.status !== 'success') {
          throw new Error(payload?.message || 'Failed to load funding sources');
        }
        sources = payload.data || { flat: [] };
        const currentType = typeSelect.value || pendingValues?.linked_type || '';
        const currentId = itemSelect.value || pendingValues?.linked_id || '';
        populateItemSelect(currentType, currentId);
        if (pendingValues) {
          setValues(pendingValues.linked_type, pendingValues.linked_id, pendingValues.linked_label, { skipApply: true });
          pendingValues = null;
        }
      } catch (error) {
        if (hintEl) hintEl.textContent = error.message || 'Could not load projects and plans.';
      } finally {
        loading = false;
        itemSelect.disabled = !typeSelect.value;
      }
    }

    function getPayload() {
      const type = typeSelect?.value || '';
      const id = itemSelect?.value || '';
      if (!type || !id) {
        return { linked_type: null, linked_id: null, linked_label: null };
      }
      const option = itemSelect?.selectedOptions?.[0];
      const label = option?.getAttribute('data-label') || option?.textContent?.trim() || '';
      const item = findItem(type, id);
      const payload = {
        linked_type: type,
        linked_id: id,
        linked_label: label
      };
      if (type === 'project' && item) {
        payload.project_budget = Number(item.budget || 0);
        payload.project_spent = Number(item.spent_committed ?? item.spent ?? 0);
        payload.linked_expense_count = Number(item.linked_expense_count || 0);
        payload.budget_approval_status = item.budget_approval_status || item.budgetApprovalStatus || '';
        payload.budget_can_operate = item.budget_can_operate ?? item.budgetCanOperate;
      }
      return payload;
    }

    function setValues(type, id, label, options) {
      if (!typeSelect || !itemSelect) return;
      if (!sources.flat.length && !options?.force) {
        pendingValues = { linked_type: type, linked_id: id, linked_label: label };
        return;
      }
      typeSelect.value = type || '';
      populateItemSelect(type, id);
      if (id && itemSelect.value !== String(id) && label) {
        itemSelect.innerHTML += `<option value="${escapeHtml(id)}" data-label="${escapeHtml(label)}" selected>${escapeHtml(label)}</option>`;
        itemSelect.value = String(id);
      }
      updateHint(findItem(type, id) || (label ? { label, subtitle: '', budget: 0 } : null));
      if (!options?.skipApply) {
        applySourceToForm(findItem(type, id), options);
      }
    }

    function clear(options) {
      if (typeSelect) typeSelect.value = '';
      if (itemSelect) {
        itemSelect.innerHTML = '<option value="">Select item</option>';
        itemSelect.value = '';
        itemSelect.disabled = true;
      }
      pendingValues = null;
      updateHint(null);
      if (!options?.skipRefresh) refresh();
    }

    function formatLinkLabel(record) {
      if (record?.linked_label) return record.linked_label;
      if (!record?.linked_type) return 'General / Department';
      const typeLabel = TYPE_LABELS[record.linked_type] || record.linked_type;
      return record.linked_id ? `${typeLabel} (${record.linked_id})` : typeLabel;
    }

    function bind() {
      typeSelect?.addEventListener('change', handleTypeChange);
      itemSelect?.addEventListener('change', handleItemChange);
      Object.values(filterIds).forEach((id) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('change', () => refresh());
      });
    }

    bind();
    refresh();

    return {
      refresh,
      getPayload,
      setValues,
      clear,
      formatLinkLabel,
      TYPE_LABELS
    };
  }

  window.RDOMMSFinanceFundingLinks = { create, TYPE_LABELS };
})();
