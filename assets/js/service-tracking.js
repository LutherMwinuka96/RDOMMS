/**
 * RDOMMS Service Tracking — database-backed service delivery log.
 */
(function () {
  const PAGE = window.RDOMMS_SERVICE_PAGE || {};
  const API_SERVICES = PAGE.apiServices || '../api/service-tracking.php';
  const API_BENEFICIARIES = PAGE.apiBeneficiaries || '../api/beneficiaries.php';

  let services = [];
  let beneficiaries = [];
  let editingId = null;
  let pendingSavePayload = null;

  const SERVICE_LABELS = {
    'education-support': 'Education Support',
    'bursary-fees': 'Bursary / Fees',
    training: 'Training / Workshop',
    health: 'Health Support',
    'food-nfi': 'Food / NFI',
    'cash-support': 'Cash Support',
    other: 'Other'
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
        title: 'Service Tracking',
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

  function serviceLabel(value) {
    return SERVICE_LABELS[value] || value || '—';
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

  function beneficiaryDisplay(row) {
    const name = row.fullname || row.fullName || 'Unnamed';
    const code = row.beneficiary_code || row.beneficiaryCode || '';
    const count = Number(row.group_count ?? row.groupCount);
    const group = count > 1 ? ` · Group (${count})` : '';
    const codePart = code ? ` · ${code}` : '';
    return `${name}${codePart}${group}`;
  }

  function normalizeBeneficiary(row) {
    return {
      id: row.id,
      ref: row.beneficiary_id || row.beneficiaryId || '',
      fullName: row.fullname || row.fullName || 'Unnamed',
      beneficiaryCode: row.beneficiary_code || row.beneficiaryCode || '',
      groupCount: row.group_count ?? row.groupCount ?? null
    };
  }

  function normalizeService(row) {
    return {
      id: row.service_id || row.id || '',
      beneficiaryId: row.beneficiary_id ?? row.beneficiaryId ?? '',
      beneficiaryName: row.beneficiary_name || row.beneficiaryName || '',
      beneficiaryCode: row.beneficiary_code || row.beneficiaryCode || '',
      beneficiaryRef: row.beneficiary_ref || row.beneficiaryRef || '',
      serviceType: row.service_type || row.serviceType || 'other',
      serviceDate: row.service_date || row.serviceDate || '',
      quantity: row.quantity ?? '',
      unit: row.unit || '',
      provider: row.provider || '',
      location: row.location || '',
      reference: row.reference || '',
      description: row.description || '',
      createdAt: row.created_at || row.createdAt || ''
    };
  }

  async function fetchBeneficiaries() {
    const response = await fetch(API_BENEFICIARIES, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to load beneficiaries');
    }
    return (Array.isArray(payload.data) ? payload.data : []).map(normalizeBeneficiary);
  }

  async function fetchServices() {
    const response = await fetch(API_SERVICES, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to load service records');
    }
    return (Array.isArray(payload.data) ? payload.data : []).map(normalizeService);
  }

  async function createService(data) {
    const response = await fetch(API_SERVICES, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify(data)
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to save service');
    }
    return payload;
  }

  async function updateService(id, data) {
    const response = await fetch(API_SERVICES, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({ id, ...data })
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to update service');
    }
    return payload;
  }

  async function deleteService(id) {
    const response = await fetch(`${API_SERVICES}?id=${encodeURIComponent(id)}`, {
      method: 'DELETE',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to delete service');
    }
    return payload;
  }

  function populateBeneficiarySelect() {
    const select = $('beneficiaryId');
    if (!select) return;
    const options = ['<option value="">Select beneficiary</option>'];
    beneficiaries.forEach((item) => {
      options.push(`<option value="${escapeHtml(item.id)}">${escapeHtml(beneficiaryDisplay(item))}</option>`);
    });
    select.innerHTML = options.join('');
  }

  function beneficiaryNameFor(item) {
    if (item.beneficiaryName) {
      const code = item.beneficiaryCode ? ` · ${item.beneficiaryCode}` : '';
      return `${item.beneficiaryName}${code}`;
    }
    const match = beneficiaries.find((b) => String(b.id) === String(item.beneficiaryId));
    return match ? beneficiaryDisplay(match) : 'Unknown beneficiary';
  }

  function readFormPayload() {
    return {
      beneficiaryId: $('beneficiaryId')?.value || '',
      serviceType: $('serviceType')?.value || '',
      serviceDate: $('serviceDate')?.value || PAGE.serviceDate || '',
      quantity: $('quantity')?.value ? parseInt($('quantity').value, 10) : null,
      unit: $('unit')?.value?.trim() || '',
      provider: $('provider')?.value?.trim() || '',
      location: $('location')?.value?.trim() || '',
      reference: $('reference')?.value?.trim() || '',
      description: $('description')?.value?.trim() || ''
    };
  }

  function fillForm(item) {
    $('beneficiaryId').value = item.beneficiaryId || '';
    $('serviceType').value = item.serviceType || '';
    $('serviceDate').value = String(item.serviceDate || PAGE.serviceDate || '').slice(0, 10);
    $('quantity').value = item.quantity ?? '';
    $('unit').value = item.unit || '';
    $('provider').value = item.provider || '';
    $('location').value = item.location || '';
    $('reference').value = item.reference || '';
    $('description').value = item.description || '';
  }

  function clearForm() {
    editingId = null;
    $('serviceForm')?.reset();
    $('serviceDate').value = PAGE.serviceDate || new Date().toISOString().slice(0, 10);
    $('btnCancelEdit').style.display = 'none';
    $('editingBanner').style.display = 'none';
    $('btnSaveLabel').textContent = 'Save Service';
    $('formTitle').textContent = 'Record Service';
  }

  function setEditMode(item) {
    editingId = item.id;
    fillForm(item);
    $('btnCancelEdit').style.display = '';
    $('editingBanner').style.display = '';
    $('btnSaveLabel').textContent = 'Update Service';
    $('formTitle').textContent = 'Edit Service';
    $('serviceForm')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function getFiltered() {
    const search = ($('searchInput')?.value || '').trim().toLowerCase();
    const serviceType = $('filterServiceType')?.value || '';

    return services.filter((item) => {
      if (serviceType && item.serviceType !== serviceType) return false;
      if (!search) return true;
      const haystack = [
        serviceLabel(item.serviceType),
        beneficiaryNameFor(item),
        item.location,
        item.provider,
        item.reference,
        item.description
      ].join(' ').toLowerCase();
      return haystack.includes(search);
    });
  }

  function updateStats(list) {
    if ($('statTotal')) $('statTotal').textContent = String(list.length);
    const monthPrefix = new Date().toISOString().slice(0, 7);
    if ($('statMonth')) {
      $('statMonth').textContent = String(list.filter((row) => String(row.serviceDate || '').startsWith(monthPrefix)).length);
    }
    if ($('statBeneficiaries')) {
      const unique = new Set(list.map((row) => String(row.beneficiaryId)));
      $('statBeneficiaries').textContent = String(unique.size);
    }
  }

  function renderTable() {
    const rows = getFiltered();
    const tbody = $('tableBody');
    const empty = $('emptyState');
    const meta = $('tableMeta');
    const count = $('resultsCount');

    if (count) count.textContent = `${rows.length} record${rows.length === 1 ? '' : 's'}`;
    if (meta) {
      meta.textContent = rows.length
        ? `Showing ${rows.length} of ${services.length} service record(s).`
        : services.length
          ? 'No records match your filters.'
          : 'No services recorded yet.';
    }
    if (!tbody) return;

    if (!rows.length) {
      tbody.innerHTML = '';
      if (empty) empty.style.display = '';
      return;
    }

    if (empty) empty.style.display = 'none';
    tbody.innerHTML = rows.map((item) => {
      const qty = item.quantity ? `${item.quantity}${item.unit ? ` ${item.unit}` : ''}` : '—';
      return `
        <tr>
          <td>
            <div class="cell-title">${escapeHtml(serviceLabel(item.serviceType))}</div>
            <div class="cell-sub">Ref: ${escapeHtml(item.reference || '—')}</div>
          </td>
          <td>${escapeHtml(beneficiaryNameFor(item))}</td>
          <td>${escapeHtml(formatDate(item.serviceDate))}</td>
          <td class="num">${escapeHtml(qty)}</td>
          <td>${escapeHtml(item.location || '—')}</td>
          <td>${escapeHtml(item.provider || '—')}</td>
          <td>
            <div class="actions">
              <button type="button" class="btn btn-sm btn-light" data-action="edit" data-id="${escapeHtml(item.id)}" title="Edit"><i class="fas fa-pen"></i></button>
              <button type="button" class="btn btn-sm btn-danger" data-action="delete" data-id="${escapeHtml(item.id)}" title="Delete"><i class="fas fa-trash"></i></button>
            </div>
          </td>
        </tr>`;
    }).join('');
  }

  function validate(data) {
    if (!beneficiaries.length) {
      notify('Register beneficiaries first before recording services.', 'warning');
      return false;
    }
    if (!data.beneficiaryId || !data.serviceType || !data.serviceDate) {
      notify('Please fill beneficiary, service type, and service date.', 'error');
      return false;
    }
    return true;
  }

  function buildPreviewHtml(data) {
    const beneficiary = beneficiaries.find((b) => String(b.id) === String(data.beneficiaryId));
    return `
      <dl class="preview-grid">
        <div><dt>Beneficiary</dt><dd>${escapeHtml(beneficiary ? beneficiaryDisplay(beneficiary) : '—')}</dd></div>
        <div><dt>Service type</dt><dd>${escapeHtml(serviceLabel(data.serviceType))}</dd></div>
        <div><dt>Service date</dt><dd>${escapeHtml(data.serviceDate)}</dd></div>
        <div><dt>Quantity</dt><dd>${escapeHtml(data.quantity ?? '—')}${data.unit ? ` ${escapeHtml(data.unit)}` : ''}</dd></div>
        <div><dt>Provider</dt><dd>${escapeHtml(data.provider || '—')}</dd></div>
        <div><dt>Location</dt><dd>${escapeHtml(data.location || '—')}</dd></div>
        <div><dt>Reference</dt><dd>${escapeHtml(data.reference || '—')}</dd></div>
        <div class="span-2"><dt>Description</dt><dd>${escapeHtml(data.description || '—')}</dd></div>
      </dl>`;
  }

  function closePreviewModal() {
    $('previewModal')?.classList.remove('open');
    pendingSavePayload = null;
  }

  function openPreviewModal(data) {
    pendingSavePayload = data;
    const body = $('previewBody');
    const title = $('previewTitle');
    if (body) body.innerHTML = buildPreviewHtml(data);
    if (title) title.textContent = editingId ? 'Confirm update' : 'Confirm service record';
    $('previewModal')?.classList.add('open');
  }

  async function commitSave(data) {
    if (editingId) {
      await updateService(editingId, data);
      notify('Service updated successfully.', 'success');
    } else {
      await createService(data);
      notify('Service recorded successfully.', 'success');
    }
    clearForm();
    await refresh();
  }

  function saveService(e) {
    e.preventDefault();
    const data = readFormPayload();
    if (!validate(data)) return;
    openPreviewModal(data);
  }

  async function confirmPreviewSave() {
    if (!pendingSavePayload) return;
    const data = pendingSavePayload;
    closePreviewModal();
    setLoading(true);
    try {
      await commitSave(data);
    } catch (error) {
      notify(error.message || 'Unable to save service.', 'error');
    } finally {
      setLoading(false);
    }
  }

  async function handleDelete(id) {
    const item = services.find((row) => String(row.id) === String(id));
    if (!item) return;
    if (!confirm(`Delete service "${serviceLabel(item.serviceType)}" for ${beneficiaryNameFor(item)}?`)) return;
    setLoading(true);
    try {
      await deleteService(id);
      notify('Service deleted.', 'success');
      await refresh();
    } catch (error) {
      notify(error.message || 'Unable to delete service.', 'error');
    } finally {
      setLoading(false);
    }
  }

  function buildExportRows(list) {
    const headers = ['Service', 'Beneficiary', 'Date', 'Quantity', 'Unit', 'Location', 'Provider', 'Reference', 'Notes'];
    const rows = list.map((item) => [
      serviceLabel(item.serviceType),
      beneficiaryNameFor(item),
      formatDate(item.serviceDate),
      item.quantity ?? '—',
      item.unit || '—',
      item.location || '—',
      item.provider || '—',
      item.reference || '—',
      item.description || '—'
    ]);
    return { headers, rows };
  }

  async function exportData() {
    const format = $('exportFormat')?.value || 'pdf';
    const list = getFiltered();
    if (!list.length) {
      notify('No records to export.', 'warning');
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
        title: 'Service Tracking Report',
        subtitle: `Generated: ${new Date().toLocaleString()} · ${list.length} record(s)`,
        headers,
        rows,
        filename: `rdomms-service-tracking-${new Date().toISOString().slice(0, 10)}`,
        pdf: { orientation: 'landscape' }
      });
      notify(`Export complete (${format.toUpperCase()}).`, 'success');
    } catch (error) {
      notify(error.message || 'Export failed.', 'error');
    }
  }

  function resetFilters() {
    if ($('searchInput')) $('searchInput').value = '';
    if ($('filterServiceType')) $('filterServiceType').value = '';
    renderTable();
    notify('Filters cleared.', 'info');
  }

  async function refresh() {
    const [beneficiaryRows, serviceRows] = await Promise.all([
      fetchBeneficiaries(),
      fetchServices()
    ]);
    beneficiaries = beneficiaryRows;
    services = serviceRows;
    populateBeneficiarySelect();
    updateStats(services);
    renderTable();
  }

  function bindUi() {
    $('serviceForm')?.addEventListener('submit', saveService);
    $('btnReset')?.addEventListener('click', () => {
      clearForm();
      notify('Form cleared.', 'info');
    });
    $('btnCancelEdit')?.addEventListener('click', clearForm);
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
    $('btnPreviewCancel')?.addEventListener('click', closePreviewModal);
    $('btnPreviewConfirm')?.addEventListener('click', confirmPreviewSave);
    $('previewModal')?.addEventListener('click', (e) => {
      if (e.target.id === 'previewModal') closePreviewModal();
    });
    $('searchInput')?.addEventListener('input', renderTable);
    $('filterServiceType')?.addEventListener('change', renderTable);
    $('tableBody')?.addEventListener('click', (e) => {
      const button = e.target.closest('[data-action][data-id]');
      if (!button) return;
      const id = button.dataset.id;
      const action = button.dataset.action;
      const item = services.find((row) => String(row.id) === String(id));
      if (!item) return;
      if (action === 'edit') setEditMode(item);
      if (action === 'delete') handleDelete(id);
    });
  }

  function initDefaults() {
    if ($('serviceDate')) {
      $('serviceDate').value = PAGE.serviceDate || new Date().toISOString().slice(0, 10);
    }
  }

  async function boot() {
    initDefaults();
    bindUi();
    setLoading(true);
    try {
      await refresh();
    } catch (error) {
      notify(error.message || 'Unable to load service tracking data.', 'error');
      beneficiaries = [];
      services = [];
      populateBeneficiarySelect();
      updateStats([]);
      renderTable();
    } finally {
      setLoading(false);
    }
  }

  document.addEventListener('DOMContentLoaded', boot);
  window.RDOMMSServiceTracking = { refresh, exportData };
})();
