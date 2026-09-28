/**
 * RDOMMS Register Beneficiary — database-backed intake form.
 */
(function () {
  const PAGE = window.RDOMMS_BENEFICIARY_PAGE || {};
  const API = PAGE.apiBeneficiaries || '../api/beneficiaries.php';

  let editingId = PAGE.editId || null;
  let pendingSavePayload = null;

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

  const GROUP_CATEGORIES = new Set([
    'kindergarten',
    'primary-school',
    'secondary-school',
    'college',
    'university',
    'vulnerable-households',
    'women',
    'elderly',
    'persons-with-disabilities',
    'households'
  ]);

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
        title: 'Register Beneficiary',
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

  function normalizeStatus(value) {
    const status = String(value || 'pending').toLowerCase();
    if (status === 'new' || status === 'review') return 'pending';
    if (status === 'active' || status === 'inactive' || status === 'pending') return status;
    return 'pending';
  }

  function isGroupCategory(category) {
    return GROUP_CATEGORIES.has(String(category || ''));
  }

  function toggleGroupField(category) {
    const wrap = $('groupCountField');
    const input = $('groupCount');
    if (!wrap || !input) return;
    const show = isGroupCategory(category);
    wrap.style.display = show ? '' : 'none';
    input.required = show;
    if (!show) input.value = '';
  }

  function setEditMode(active) {
    const banner = $('editingBanner');
    const cancelBtn = $('btnCancelEdit');
    const saveLabel = $('btnSaveLabel');
    const badge = $('formModeBadge');
    const title = $('formTitle');

    if (banner) banner.style.display = active ? '' : 'none';
    if (cancelBtn) cancelBtn.style.display = active ? '' : 'none';
    if (saveLabel) saveLabel.textContent = active ? 'Update Beneficiary' : 'Save Beneficiary';
    if (title) title.textContent = active ? 'Edit Beneficiary' : 'Register Beneficiary';
    if (badge) {
      badge.textContent = active ? 'Editing' : 'New';
      badge.className = active ? 'mode-badge editing' : 'mode-badge';
    }
  }

  function readFormPayload() {
    const category = $('category')?.value || '';
    const groupRaw = $('groupCount')?.value?.trim();
    return {
      fullname: $('fullName')?.value?.trim() || '',
      category,
      gender: $('gender')?.value || '',
      age: $('age')?.value ? parseInt($('age').value, 10) : null,
      phone: $('phone')?.value?.trim() || '',
      location: $('location')?.value?.trim() || '',
      district: $('district')?.value?.trim() || '',
      ward: $('ward')?.value?.trim() || '',
      groupCount: groupRaw ? parseInt(groupRaw, 10) : null,
      status: normalizeStatus($('status')?.value),
      registeredDate: $('registeredDate')?.value || PAGE.registeredDate || '',
      servicesNeeded: $('servicesNeeded')?.value?.trim() || '',
      notes: $('notes')?.value?.trim() || ''
    };
  }

  function fillForm(record) {
    $('fullName').value = record.fullname || record.fullName || '';
    $('category').value = record.category || '';
    $('gender').value = record.gender || '';
    $('age').value = record.age ?? '';
    $('phone').value = record.phone || '';
    $('location').value = record.location || '';
    $('district').value = record.district || '';
    $('ward').value = record.ward || '';
    $('groupCount').value = record.group_count ?? record.groupCount ?? '';
    $('status').value = normalizeStatus(record.status);
    $('registeredDate').value = String(record.registered_date || record.registeredDate || PAGE.registeredDate || '').slice(0, 10);
    $('servicesNeeded').value = record.services_needed || record.servicesNeeded || '';
    $('notes').value = record.notes || '';
    toggleGroupField(record.category || '');
  }

  function clearForm() {
    editingId = null;
    $('beneficiaryForm')?.reset();
    $('registeredDate').value = PAGE.registeredDate || new Date().toISOString().slice(0, 10);
    $('status').value = 'active';
    toggleGroupField('');
    setEditMode(false);
    if (window.history.replaceState) {
      const url = new URL(window.location.href);
      url.searchParams.delete('id');
      window.history.replaceState({}, '', url.pathname + url.search);
    }
  }

  async function fetchBeneficiary(id) {
    const response = await fetch(`${API}?id=${encodeURIComponent(id)}`, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Beneficiary not found');
    }
    return payload.data;
  }

  async function pingApi() {
    const response = await fetch(API, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      const err = new Error(payload?.message || 'Unable to reach beneficiaries API');
      err.status = response.status;
      throw err;
    }
    return Array.isArray(payload.data) ? payload.data : [];
  }

  async function createBeneficiary(data) {
    const response = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({
        fullname: data.fullname,
        category: data.category,
        gender: data.gender || 'other',
        age: data.age,
        groupCount: data.groupCount || 1,
        status: data.status,
        phone: data.phone || null,
        location: data.location || null,
        district: data.district || null,
        ward: data.ward || null,
        registeredDate: data.registeredDate,
        servicesNeeded: data.servicesNeeded || null,
        notes: data.notes || null
      })
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to save beneficiary');
    }
    return payload;
  }

  async function updateBeneficiary(id, data) {
    const response = await fetch(API, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({
        id,
        fullname: data.fullname,
        category: data.category,
        gender: data.gender || 'other',
        age: data.age,
        group_count: data.groupCount || 1,
        status: data.status,
        phone: data.phone || null,
        location: data.location || null,
        district: data.district || null,
        ward: data.ward || null,
        services_needed: data.servicesNeeded || null,
        notes: data.notes || null
      })
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to update beneficiary');
    }
    return payload;
  }

  function validate(data) {
    if (!data.fullname || !data.category || !data.gender || !data.location || !data.registeredDate) {
      notify('Please fill all required fields.', 'error');
      return false;
    }
    if (isGroupCategory(data.category) && (!data.groupCount || data.groupCount < 1)) {
      notify('Enter a valid group count for this category.', 'error');
      return false;
    }
    return true;
  }

  function buildPreviewHtml(data) {
    return `
      <dl class="preview-grid">
        <div><dt>Full name</dt><dd>${escapeHtml(data.fullname)}</dd></div>
        <div><dt>Category</dt><dd>${escapeHtml(categoryLabel(data.category))}</dd></div>
        <div><dt>Gender</dt><dd>${escapeHtml(data.gender)}</dd></div>
        <div><dt>Age</dt><dd>${escapeHtml(data.age ?? '—')}</dd></div>
        <div><dt>Phone</dt><dd>${escapeHtml(data.phone || '—')}</dd></div>
        <div><dt>Status</dt><dd>${escapeHtml(data.status)}</dd></div>
        <div><dt>Location</dt><dd>${escapeHtml(data.location)}</dd></div>
        <div><dt>District</dt><dd>${escapeHtml(data.district || '—')}</dd></div>
        <div><dt>Ward</dt><dd>${escapeHtml(data.ward || '—')}</dd></div>
        ${isGroupCategory(data.category) ? `<div><dt>Group count</dt><dd>${escapeHtml(data.groupCount)}</dd></div>` : ''}
        <div><dt>Registration date</dt><dd>${escapeHtml(data.registeredDate)}</dd></div>
        <div class="span-2"><dt>Services needed</dt><dd>${escapeHtml(data.servicesNeeded || '—')}</dd></div>
        <div class="span-2"><dt>Notes</dt><dd>${escapeHtml(data.notes || '—')}</dd></div>
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
    if (title) title.textContent = editingId ? 'Confirm update' : 'Confirm registration';
    $('previewModal')?.classList.add('open');
  }

  async function commitSave(data) {
    if (editingId) {
      await updateBeneficiary(editingId, data);
      notify('Beneficiary updated successfully.', 'success');
    } else {
      await createBeneficiary(data);
      notify('Beneficiary registered successfully.', 'success');
    }
    clearForm();
    await refreshStats();
  }

  function saveBeneficiary(e) {
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
      notify(error.message || 'Unable to save beneficiary.', 'error');
    } finally {
      setLoading(false);
    }
  }

  async function refreshStats() {
    try {
      const list = await pingApi();
      const statTotal = $('statTotal');
      const statActive = $('statActive');
      if (statTotal) statTotal.textContent = String(list.length);
      if (statActive) {
        statActive.textContent = String(list.filter((row) => normalizeStatus(row.status) === 'active').length);
      }
    } catch (error) {
      /* stats stay at last values if API unavailable */
    }
  }

  async function loadEditTarget() {
    const legacyId = localStorage.getItem('rdomms_beneficiary_edit_id');
    const targetId = editingId || legacyId;
    if (!targetId) return;

    setLoading(true);
    try {
      const record = await fetchBeneficiary(targetId);
      editingId = record.beneficiary_id || record.id || targetId;
      fillForm(record);
      setEditMode(true);
      localStorage.removeItem('rdomms_beneficiary_edit_id');
      notify('Loaded beneficiary for editing.', 'info');
    } catch (error) {
      localStorage.removeItem('rdomms_beneficiary_edit_id');
      notify(error.message || 'Could not load beneficiary.', 'warning');
    } finally {
      setLoading(false);
    }
  }

  function bindUi() {
    $('beneficiaryForm')?.addEventListener('submit', saveBeneficiary);
    $('btnReset')?.addEventListener('click', () => {
      clearForm();
      notify('Form cleared.', 'info');
    });
    $('btnCancelEdit')?.addEventListener('click', clearForm);
    $('btnPreviewCancel')?.addEventListener('click', closePreviewModal);
    $('btnPreviewConfirm')?.addEventListener('click', confirmPreviewSave);
    $('previewModal')?.addEventListener('click', (e) => {
      if (e.target.id === 'previewModal') closePreviewModal();
    });
    $('category')?.addEventListener('change', (e) => toggleGroupField(e.target.value));
  }

  function initDefaults() {
    if ($('registeredDate')) {
      $('registeredDate').value = PAGE.registeredDate || new Date().toISOString().slice(0, 10);
    }
    if ($('status')) $('status').value = 'active';
    toggleGroupField('');
  }

  async function boot() {
    initDefaults();
    bindUi();
    setLoading(true);
    try {
      await refreshStats();
      await loadEditTarget();
    } finally {
      setLoading(false);
    }
  }

  document.addEventListener('DOMContentLoaded', boot);
  window.RDOMMSRegisterBeneficiary = { refreshStats, clearForm };
})();
