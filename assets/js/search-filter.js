/**
 * RDOMMS Beneficiary Search & Filter — database-backed explorer.
 */
(function () {
  const PAGE = window.RDOMMS_SEARCH_FILTER || {};
  const API = PAGE.apiBeneficiaries || '../api/beneficiaries.php';

  let beneficiaries = [];

  const CATEGORY_LABELS = {
    kindergarten: 'Kindergarten Pupils',
    'primary-school': 'Primary School Pupils',
    'secondary-school': 'Secondary School Students',
    college: 'College / VET Students',
    university: 'University Students',
    'vulnerable-households': 'People in Vulnerable Conditions',
    children: 'Children',
    youth: 'Youth',
    women: 'Women',
    elderly: 'Elderly',
    'persons-with-disabilities': 'Persons with Disabilities',
    households: 'Households'
  };

  function $(id) {
    return document.getElementById(id);
  }

  function setLoading(active) {
    const el = $('pageLoading');
    if (el) el.classList.toggle('hidden', !active);
  }

  function notify(msg, type) {
    if (window.RDOMMSToast) {
      RDOMMSToast.show(msg, type || 'info', {
        title: 'Search & Filter',
        duration: 4000,
        allowMultiple: true
      });
    } else {
      alert(msg);
    }
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function categoryLabel(value) {
    return CATEGORY_LABELS[value] || value || '—';
  }

  function statusLabel(value) {
    const status = String(value || 'pending').toLowerCase();
    if (status === 'active') return 'Active';
    if (status === 'inactive') return 'Inactive';
    if (status === 'pending') return 'Pending';
    return status.charAt(0).toUpperCase() + status.slice(1);
  }

  function statusClass(value) {
    const status = String(value || 'pending').toLowerCase();
    if (status === 'active') return 'approved';
    if (status === 'inactive') return 'rejected';
    return 'pending';
  }

  function formatDate(value) {
    if (!value) return '—';
    const date = new Date(String(value).slice(0, 10) + 'T00:00:00');
    if (Number.isNaN(date.getTime())) return String(value);
    return new Intl.DateTimeFormat('en-GB', {
      day: '2-digit',
      month: 'short',
      year: 'numeric'
    }).format(date);
  }

  function recordType(item) {
    const count = Number(item.groupCount);
    return count > 1 ? 'Group' : 'Individual';
  }

  function peopleCovered(item) {
    const count = Number(item.groupCount);
    return count > 1 ? count : 1;
  }

  function normalizeRecord(row) {
    const groupCount = row.group_count ?? row.groupCount;
    return {
      id: row.beneficiary_id || row.id || '',
      fullName: row.fullname || row.fullName || 'Unnamed',
      beneficiaryCode: row.beneficiary_code || row.beneficiaryCode || '—',
      category: row.category || '',
      groupCount: groupCount === null || groupCount === '' ? null : Number(groupCount),
      status: row.status || 'pending',
      phone: row.phone || '',
      location: row.location || '',
      district: row.district || '',
      ward: row.ward || '',
      gender: row.gender || '',
      age: row.age ?? '',
      registeredDate: row.registered_date || row.registeredDate || '',
      servicesNeeded: row.services_needed || row.servicesNeeded || '',
      notes: row.notes || '',
      updatedAt: row.updated_at || row.updatedAt || ''
    };
  }

  async function fetchBeneficiaries() {
    const response = await fetch(API, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to load beneficiaries');
    }
    return (Array.isArray(payload.data) ? payload.data : []).map(normalizeRecord);
  }

  async function deleteBeneficiary(id) {
    const response = await fetch(`${API}?id=${encodeURIComponent(id)}`, {
      method: 'DELETE',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to delete beneficiary');
    }
    return payload;
  }

  function populateSelect(selectId, values, allLabel) {
    const select = $(selectId);
    if (!select) return;
    const current = select.value;
    const options = [`<option value="">${escapeHtml(allLabel)}</option>`];
    values.forEach((value) => {
      options.push(`<option value="${escapeHtml(value)}">${escapeHtml(value)}</option>`);
    });
    select.innerHTML = options.join('');
    if (current && values.includes(current)) {
      select.value = current;
    }
  }

  function refreshFilterOptions() {
    const districts = [...new Set(beneficiaries.map((item) => item.district).filter(Boolean))].sort();
    const locations = [...new Set(beneficiaries.map((item) => item.location).filter(Boolean))].sort();
    populateSelect('filterDistrict', districts, 'All districts');
    populateSelect('filterLocation', locations, 'All locations');
  }

  function getFiltered() {
    const search = ($('searchInput')?.value || '').trim().toLowerCase();
    const category = $('filterCategory')?.value || '';
    const status = $('filterStatus')?.value || '';
    const type = $('filterType')?.value || '';
    const district = $('filterDistrict')?.value || '';
    const location = $('filterLocation')?.value || '';
    const dateFrom = $('filterDateFrom')?.value || '';
    const dateTo = $('filterDateTo')?.value || '';

    return beneficiaries.filter((item) => {
      if (category && item.category !== category) return false;
      if (status && String(item.status).toLowerCase() !== status) return false;
      if (district && item.district !== district) return false;
      if (location && item.location !== location) return false;

      if (type === 'group' && Number(item.groupCount) <= 1) return false;
      if (type === 'individual' && Number(item.groupCount) > 1) return false;

      if (dateFrom && String(item.registeredDate || '').slice(0, 10) < dateFrom) return false;
      if (dateTo && String(item.registeredDate || '').slice(0, 10) > dateTo) return false;

      if (!search) return true;
      const haystack = [
        item.fullName,
        item.beneficiaryCode,
        categoryLabel(item.category),
        item.phone,
        item.location,
        item.district,
        item.ward,
        statusLabel(item.status)
      ].join(' ').toLowerCase();
      return haystack.includes(search);
    });
  }

  function updateStats(rows) {
    if ($('statMatches')) $('statMatches').textContent = String(rows.length);
    if ($('statActive')) {
      $('statActive').textContent = String(rows.filter((r) => String(r.status).toLowerCase() === 'active').length);
    }
    if ($('statGroups')) {
      $('statGroups').textContent = String(rows.filter((r) => Number(r.groupCount) > 1).length);
    }
    if ($('statPeople')) {
      $('statPeople').textContent = String(rows.reduce((sum, item) => sum + peopleCovered(item), 0));
    }
  }

  function renderTable() {
    const rows = getFiltered();
    const tbody = $('beneficiaryTableBody');
    const empty = $('emptyState');
    const meta = $('tableMeta');
    const count = $('resultsCount');

    updateStats(rows);

    if (count) count.textContent = `${rows.length} match${rows.length === 1 ? '' : 'es'}`;
    if (meta) {
      meta.textContent = rows.length
        ? `Showing ${rows.length} of ${beneficiaries.length} beneficiary record(s) from the database.`
        : beneficiaries.length
          ? 'No records match the current filters.'
          : 'No beneficiaries registered yet.';
    }
    if (!tbody) return;

    if (!rows.length) {
      tbody.innerHTML = '';
      if (empty) empty.style.display = '';
      return;
    }

    if (empty) empty.style.display = 'none';
    tbody.innerHTML = rows.map((item) => `
      <tr>
        <td>
          <div class="cell-title">${escapeHtml(item.fullName)}</div>
          <div class="cell-sub">${escapeHtml(item.beneficiaryCode)}</div>
        </td>
        <td>${escapeHtml(categoryLabel(item.category))}</td>
        <td>${escapeHtml(recordType(item))}</td>
        <td class="num">${item.groupCount && item.groupCount > 1 ? escapeHtml(item.groupCount) : '—'}</td>
        <td>${escapeHtml(item.location || '—')}</td>
        <td>${escapeHtml(item.district || '—')}</td>
        <td>${escapeHtml(item.phone || '—')}</td>
        <td><span class="badge ${statusClass(item.status)}">${escapeHtml(statusLabel(item.status))}</span></td>
        <td>${escapeHtml(formatDate(item.registeredDate))}</td>
        <td>
          <div class="actions">
            <button type="button" class="btn btn-sm btn-light" data-action="view" data-id="${escapeHtml(item.id)}" title="View"><i class="fas fa-eye"></i></button>
            <a class="btn btn-sm btn-light" href="register-beneficiary.php?id=${encodeURIComponent(item.id)}" title="Edit"><i class="fas fa-pen"></i></a>
            <button type="button" class="btn btn-sm btn-danger" data-action="delete" data-id="${escapeHtml(item.id)}" title="Delete"><i class="fas fa-trash"></i></button>
          </div>
        </td>
      </tr>
    `).join('');
  }

  function openViewModal(item) {
    const body = $('viewModalBody');
    if (!body) return;
    body.innerHTML = `
      <dl class="preview-grid">
        <div><dt>Full name</dt><dd>${escapeHtml(item.fullName)}</dd></div>
        <div><dt>Code</dt><dd>${escapeHtml(item.beneficiaryCode)}</dd></div>
        <div><dt>Category</dt><dd>${escapeHtml(categoryLabel(item.category))}</dd></div>
        <div><dt>Type</dt><dd>${escapeHtml(recordType(item))}</dd></div>
        <div><dt>Gender</dt><dd>${escapeHtml(item.gender || '—')}</dd></div>
        <div><dt>Age</dt><dd>${escapeHtml(item.age ?? '—')}</dd></div>
        <div><dt>Phone</dt><dd>${escapeHtml(item.phone || '—')}</dd></div>
        <div><dt>Status</dt><dd>${escapeHtml(statusLabel(item.status))}</dd></div>
        <div><dt>Location</dt><dd>${escapeHtml(item.location || '—')}</dd></div>
        <div><dt>District</dt><dd>${escapeHtml(item.district || '—')}</dd></div>
        <div><dt>Ward</dt><dd>${escapeHtml(item.ward || '—')}</dd></div>
        ${item.groupCount && item.groupCount > 1 ? `<div><dt>Group count</dt><dd>${escapeHtml(item.groupCount)}</dd></div>` : ''}
        <div><dt>Registered</dt><dd>${escapeHtml(formatDate(item.registeredDate))}</dd></div>
        <div class="span-2"><dt>Services needed</dt><dd>${escapeHtml(item.servicesNeeded || '—')}</dd></div>
        <div class="span-2"><dt>Notes</dt><dd>${escapeHtml(item.notes || '—')}</dd></div>
      </dl>`;
    $('viewModal')?.classList.add('open');
  }

  function closeViewModal() {
    $('viewModal')?.classList.remove('open');
  }

  async function handleDelete(id) {
    const item = beneficiaries.find((row) => String(row.id) === String(id));
    if (!item) return;
    if (!confirm(`Delete "${item.fullName}"?`)) return;
    setLoading(true);
    try {
      await deleteBeneficiary(id);
      notify('Beneficiary deleted.', 'success');
      await refresh();
    } catch (error) {
      notify(error.message || 'Unable to delete beneficiary.', 'error');
    } finally {
      setLoading(false);
    }
  }

  function buildExportRows(list) {
    const headers = ['Code', 'Name', 'Category', 'Type', 'Count', 'Location', 'District', 'Phone', 'Status', 'Registered'];
    const rows = list.map((item) => [
      item.beneficiaryCode,
      item.fullName,
      categoryLabel(item.category),
      recordType(item),
      item.groupCount && item.groupCount > 1 ? item.groupCount : '—',
      item.location || '—',
      item.district || '—',
      item.phone || '—',
      statusLabel(item.status),
      formatDate(item.registeredDate)
    ]);
    return { headers, rows };
  }

  async function exportData() {
    const format = $('exportFormat')?.value || 'pdf';
    const list = getFiltered();
    if (!list.length) {
      notify('No filtered records to export.', 'warning');
      return;
    }
    if (!window.RDOMMSExport) {
      notify('Export module not loaded.', 'error');
      return;
    }
    const { headers, rows } = buildExportRows(list);
    try {
      await RDOMMSExport.exportData({
        format,
        title: 'Beneficiary Search Results',
        subtitle: `Generated: ${new Date().toLocaleString()} · ${list.length} match(es)`,
        headers,
        rows,
        filename: `rdomms-beneficiary-search-${new Date().toISOString().slice(0, 10)}`,
        pdf: { orientation: 'landscape' }
      });
      notify(`Export complete (${format.toUpperCase()}).`, 'success');
    } catch (error) {
      notify(error.message || 'Export failed.', 'error');
    }
  }

  function resetFilters() {
    ['searchInput', 'filterCategory', 'filterStatus', 'filterType', 'filterDistrict', 'filterLocation', 'filterDateFrom', 'filterDateTo'].forEach((id) => {
      const el = $(id);
      if (el) el.value = '';
    });
    renderTable();
    notify('Filters cleared.', 'info');
  }

  async function refresh() {
    beneficiaries = await fetchBeneficiaries();
    refreshFilterOptions();
    renderTable();
  }

  function bindUi() {
    ['searchInput', 'filterCategory', 'filterStatus', 'filterType', 'filterDistrict', 'filterLocation', 'filterDateFrom', 'filterDateTo'].forEach((id) => {
      $(id)?.addEventListener('input', renderTable);
      $(id)?.addEventListener('change', renderTable);
    });
    $('btnRefresh')?.addEventListener('click', async () => {
      setLoading(true);
      try {
        await refresh();
        notify('Data refreshed.', 'info');
      } catch (error) {
        notify(error.message || 'Unable to refresh data.', 'error');
      } finally {
        setLoading(false);
      }
    });
    $('btnClearFilters')?.addEventListener('click', resetFilters);
    $('btnExport')?.addEventListener('click', exportData);
    $('btnCloseView')?.addEventListener('click', closeViewModal);
    $('viewModal')?.addEventListener('click', (e) => {
      if (e.target.id === 'viewModal') closeViewModal();
    });
    $('beneficiaryTableBody')?.addEventListener('click', (e) => {
      const button = e.target.closest('[data-action][data-id]');
      if (!button) return;
      const id = button.dataset.id;
      const action = button.dataset.action;
      const item = beneficiaries.find((row) => String(row.id) === String(id));
      if (!item) return;
      if (action === 'view') openViewModal(item);
      if (action === 'delete') handleDelete(id);
    });
  }

  async function boot() {
    bindUi();
    setLoading(true);
    try {
      await refresh();
    } catch (error) {
      notify(error.message || 'Unable to load beneficiaries.', 'error');
      beneficiaries = [];
      renderTable();
    } finally {
      setLoading(false);
    }
  }

  document.addEventListener('DOMContentLoaded', boot);
  window.RDOMMSBeneficiarySearch = { refresh, exportData, getFiltered };
})();
