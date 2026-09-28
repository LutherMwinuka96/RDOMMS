(function () {
  async function fetchJSON(url) {
    const res = await fetch(url, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json().catch(() => null);
    if (!res.ok) {
      throw new Error(data?.message || `Request failed (${res.status})`);
    }
    return data;
  }

  function toOption(item) {
    const value = item?.value ?? item?.id ?? '';
    const label = item?.label ?? item?.name ?? value;
    return { value: String(value), label: String(label) };
  }

  async function populateSelect(select, lookupType, options = {}) {
    if (!select) return [];
    const placeholder = options.placeholder ?? select.getAttribute('data-placeholder') ?? select.querySelector('option')?.textContent?.trim() ?? 'Select';
    const endpoint = options.endpoint || `../api/lookups.php?type=${encodeURIComponent(lookupType)}`;
    const includeEmpty = options.includeEmpty !== false;

    try {
      const payload = await fetchJSON(endpoint);
      const rows = Array.isArray(payload?.data) ? payload.data : [];
      const items = rows.map(toOption).filter((item) => item.value !== '');
      const current = select.value;
      select.innerHTML = '';
      if (includeEmpty) {
        const empty = document.createElement('option');
        empty.value = '';
        empty.textContent = options.emptyLabel || placeholder;
        select.appendChild(empty);
      }
      items.forEach((item) => {
        const opt = document.createElement('option');
        opt.value = item.value;
        opt.textContent = item.label;
        select.appendChild(opt);
      });
      if (current && items.some((item) => item.value === current)) {
        select.value = current;
      }
      return items;
    } catch (error) {
      console.warn(`Failed to load lookup "${lookupType}"`, error);
      return [];
    }
  }

  async function populateSelects(root = document) {
    const tasks = [];
    root.querySelectorAll('select[data-lookup]').forEach((select) => {
      const lookupType = select.getAttribute('data-lookup');
      if (!lookupType) return;
      tasks.push(
        populateSelect(select, lookupType, {
          placeholder: select.getAttribute('data-placeholder'),
          emptyLabel: select.getAttribute('data-empty-label'),
          endpoint: select.getAttribute('data-endpoint') || undefined,
        }),
      );
    });
    return Promise.all(tasks);
  }

  window.RDOMMSSelects = {
    populateSelect,
    populateSelects,
  };

  document.addEventListener('DOMContentLoaded', () => {
    populateSelects().catch(() => {});
  });
})();
