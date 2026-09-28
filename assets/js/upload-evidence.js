/**
 * Planning evidence upload workspace
 */
(function () {
  const MAX_FILE_SIZE = 1.5 * 1024 * 1024;

  let evidenceRecords = [];
  let pendingFiles = [];
  let apiReady = false;

  const evidenceForm = document.getElementById('evidenceForm');
  const fileInput = document.getElementById('fileInput');
  const uploadZone = document.getElementById('uploadZone');
  const fileQueue = document.getElementById('fileQueue');
  const tableBody = document.getElementById('tableBody');
  const resultsCount = document.getElementById('resultsCount');
  const statusMessage = document.getElementById('statusMessage');
  const planLinkBanner = document.getElementById('planLinkBanner');
  const viewModal = document.getElementById('viewModal');

  const fields = {
    title: document.getElementById('evidenceTitle'),
    type: document.getElementById('evidenceType'),
    activity: document.getElementById('relatedActivity'),
    date: document.getElementById('evidenceDate'),
    location: document.getElementById('evidenceLocation'),
    station: document.getElementById('stationSelect'),
    uploadedBy: document.getElementById('uploadedBy'),
    department: document.getElementById('departmentSelect'),
    description: document.getElementById('descriptionInput')
  };

  const statTotal = document.getElementById('statTotal');
  const statFiles = document.getElementById('statFiles');
  const statMonth = document.getElementById('statMonth');
  const statQueue = document.getElementById('statQueue');

  function uid(prefix) {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return `${prefix}-${window.crypto.randomUUID()}`;
    }
    return `${prefix}-${Date.now()}-${Math.random().toString(16).slice(2, 8)}`;
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function notify(message, type = 'info') {
    if (window.RDOMMSToast) {
      RDOMMSToast.show(message, type, {
        title: 'Evidence',
        duration: type === 'error' ? 6800 : 4200,
        allowMultiple: true
      });
      return;
    }
    alert(message);
  }

  function showStatus(message, tone = 'ok') {
    if (!statusMessage) return;
    if (!message) {
      statusMessage.className = 'mp-status';
      statusMessage.textContent = '';
      return;
    }
    statusMessage.className = `mp-status is-show mp-status--${tone === 'error' ? 'err' : tone === 'warn' ? 'warn' : 'ok'}`;
    statusMessage.textContent = message;
  }

  function stationLabel(value) {
    if (window.RDOMMSOrganizations) {
      return RDOMMSOrganizations.getLabel(value) || value || '—';
    }
    return value || '—';
  }

  function departmentLabel(value) {
    if (window.RDOMMSDepartments) {
      return RDOMMSDepartments.getLabel(value) || value || '—';
    }
    return value || '—';
  }

  function typeBadgeClass(type) {
    const key = String(type || 'other').toLowerCase();
    return `ue-type-badge ue-type-badge--${key}`;
  }

  function formatFileSize(bytes) {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    return `${(bytes / Math.pow(1024, index)).toFixed(index === 0 ? 0 : 2)} ${units[index]}`;
  }

  function fileIcon(name, mime) {
    const lower = String(name || '').toLowerCase();
    if (mime && mime.startsWith('image/')) return 'fa-image';
    if (mime && mime.startsWith('video/')) return 'fa-video';
    if (mime && mime.startsWith('audio/')) return 'fa-music';
    if (lower.endsWith('.pdf')) return 'fa-file-pdf';
    if (lower.endsWith('.doc') || lower.endsWith('.docx')) return 'fa-file-word';
    return 'fa-file';
  }

  function getUrlContext() {
    const params = new URLSearchParams(window.location.search);
    return {
      planType: params.get('planType') || '',
      period: decodeURIComponent(params.get('period') || '').trim()
    };
  }

  function applyPlanLinkContext() {
    const ctx = getUrlContext();
    if (!ctx.planType || !ctx.period) return;

    const typeLabel = ctx.planType.charAt(0).toUpperCase() + ctx.planType.slice(1);
    const activityRef = `${typeLabel} plan — ${ctx.period}`;

    if (fields.activity && !fields.activity.value.trim()) {
      fields.activity.value = activityRef;
    }
    if (fields.title && !fields.title.value.trim()) {
      fields.title.value = `Evidence for ${activityRef}`;
    }
    if (planLinkBanner) {
      planLinkBanner.textContent = `Linked to ${activityRef}. Attach files and save.`;
      planLinkBanner.classList.add('is-active');
    }
  }

  async function loadRecords() {
    if (!window.RDOMMSPlanningAPI) {
      evidenceRecords = [];
      return;
    }
    evidenceRecords = await RDOMMSPlanningAPI.evidence.list();
  }

  function getCurrentUserProfile() {
    try {
      return JSON.parse(localStorage.getItem('currentUser') || '{}');
    } catch (error) {
      return {};
    }
  }

  function ensureOrganizationsReady() {
    if (!window.RDOMMSOrganizations) return;
    if (fields.station) {
      RDOMMSOrganizations.fillSelect(fields.station, {
        emptyLabel: 'Select center',
        selectedValue: fields.station.value || ''
      });
    }
    const filterStation = document.getElementById('filterStation');
    if (filterStation) {
      RDOMMSOrganizations.fillSelect(filterStation, {
        emptyLabel: 'All centers',
        selectedValue: filterStation.value || ''
      });
    }
  }

  function ensureDepartmentsReady() {
    if (!fields.department || !window.RDOMMSDepartments) return;
    RDOMMSDepartments.fillSelect(fields.department, {
      emptyLabel: 'Select department',
      selectedValue: fields.department.value || ''
    });
    const filterDept = document.getElementById('filterDepartment');
    if (filterDept) {
      RDOMMSDepartments.fillSelect(filterDept, {
        emptyLabel: 'All departments',
        selectedValue: filterDept.value || ''
      });
    }
  }

  function setDepartmentValue(value) {
    ensureDepartmentsReady();
    if (!value || !window.RDOMMSDepartments) return;
    fields.department.value = RDOMMSDepartments.getValue(value) || value;
  }

  function setStationValue(value) {
    ensureOrganizationsReady();
    if (!value || !window.RDOMMSOrganizations) return;
    fields.station.value = RDOMMSOrganizations.getValue(value) || value;
  }

  function initProfileDefaults() {
    const user = getCurrentUserProfile();
    if (!fields.uploadedBy.value.trim() && user.fullname) {
      fields.uploadedBy.value = user.fullname;
    }
    if (!fields.department.value && user.department) {
      setDepartmentValue(user.department);
    }
    if (!fields.station.value && user.organization) {
      setStationValue(user.organization);
    }
  }

  async function loadActivityOptions() {
    const datalist = document.getElementById('activityOptions');
    if (!datalist) return;
    const options = window.RDOMMSPlanningAPI
      ? await RDOMMSPlanningAPI.evidence.activityOptions()
      : [];

    datalist.innerHTML = '';
    options.forEach((value) => {
      const option = document.createElement('option');
      option.value = value;
      datalist.appendChild(option);
    });
  }

  function readFileAsDataUrl(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(String(reader.result || ''));
      reader.onerror = () => reject(new Error(`Failed to read ${file.name}`));
      reader.readAsDataURL(file);
    });
  }

  async function addPendingFiles(fileList) {
    const incoming = Array.from(fileList || []);
    for (const file of incoming) {
      if (file.size > MAX_FILE_SIZE) {
        notify(`"${file.name}" exceeds 1.5 MB and was skipped.`, 'warning');
        continue;
      }
      let dataUrl = '';
      try {
        dataUrl = await readFileAsDataUrl(file);
      } catch (error) {
        notify(error.message || `Could not read ${file.name}.`, 'error');
        continue;
      }
      pendingFiles.push({
        id: uid('file'),
        name: file.name,
        size: file.size,
        type: file.type || 'application/octet-stream',
        lastModified: file.lastModified,
        dataUrl
      });
    }
    renderPendingFiles();
    updateSummary();
  }

  function renderPendingFiles() {
    if (!fileQueue) return;
    fileQueue.innerHTML = '';
    pendingFiles.forEach((file, index) => {
      const item = document.createElement('div');
      item.className = 'ue-file-chip';
      item.innerHTML = `
        <div class="ue-file-chip-main">
          <i class="fas ${fileIcon(file.name, file.type)}"></i>
          <div style="min-width:0">
            <div class="ue-file-name">${escapeHtml(file.name)}</div>
            <div class="ue-file-meta">${formatFileSize(file.size)}</div>
          </div>
        </div>
        <button class="mp-row-del" type="button" data-remove-index="${index}" title="Remove file" aria-label="Remove file">
          <i class="fas fa-trash"></i>
        </button>
      `;
      fileQueue.appendChild(item);
    });
  }

  function getFilteredRecords() {
    const search = (document.getElementById('searchInput')?.value || '').toLowerCase().trim();
    const typeFilter = document.getElementById('filterType')?.value || '';
    const stationFilter = document.getElementById('filterStation')?.value || '';
    const departmentFilter = document.getElementById('filterDepartment')?.value || '';

    return evidenceRecords.filter((record) => {
      if (typeFilter && record.type !== typeFilter) return false;
      if (stationFilter && record.station !== stationFilter) return false;
      if (departmentFilter && record.department !== departmentFilter) return false;
      if (!search) return true;
      const haystack = [
        record.title,
        record.type,
        record.relatedActivity,
        record.location,
        record.uploadedBy,
        departmentLabel(record.department),
        stationLabel(record.station)
      ].join(' ').toLowerCase();
      return haystack.includes(search);
    }).sort((a, b) => new Date(b.createdAt || 0) - new Date(a.createdAt || 0));
  }

  function updateSummary() {
    const now = new Date();
    const month = now.getMonth();
    const year = now.getFullYear();
    const monthCount = evidenceRecords.filter((record) => {
      if (!record.date) return false;
      const date = new Date(`${record.date}T00:00:00`);
      return date.getMonth() === month && date.getFullYear() === year;
    }).length;
    const fileCount = evidenceRecords.reduce((sum, record) => sum + (Array.isArray(record.files) ? record.files.length : 0), 0);

    if (statTotal) statTotal.textContent = evidenceRecords.length;
    if (statFiles) statFiles.textContent = fileCount;
    if (statMonth) statMonth.textContent = monthCount;
    if (statQueue) statQueue.textContent = pendingFiles.length;
  }

  function renderTable() {
    const rows = getFilteredRecords();
    if (!tableBody) return;

    if (!rows.length) {
      tableBody.innerHTML = '<tr><td colspan="6" class="mp-plans-empty">No evidence records match your filters.</td></tr>';
    } else {
      tableBody.innerHTML = rows.map((record) => `
        <tr>
          <td>
            <div style="font-weight:800;color:#163120">${escapeHtml(record.title)}</div>
            <div class="ue-cell-sub">${escapeHtml(record.uploadedBy || '')}</div>
          </td>
          <td><span class="${typeBadgeClass(record.type)}">${escapeHtml(record.type || 'other')}</span></td>
          <td>${escapeHtml(record.relatedActivity || '—')}</td>
          <td>${escapeHtml(stationLabel(record.station))}</td>
          <td>${escapeHtml(record.date || '—')}</td>
          <td>${Array.isArray(record.files) ? record.files.length : 0}</td>
          <td>
            <div class="mp-plan-actions">
              <button class="mp-btn mp-btn--ghost mp-btn--sm" type="button" data-action="view" data-id="${escapeHtml(record.id)}" title="View">
                <i class="fas fa-eye"></i>
              </button>
              <button class="mp-btn mp-btn--ghost mp-btn--sm" type="button" data-action="delete" data-id="${escapeHtml(record.id)}" title="Delete" style="color:#b42318;">
                <i class="fas fa-trash"></i>
              </button>
            </div>
          </td>
        </tr>
      `).join('');
    }

    if (resultsCount) {
      resultsCount.textContent = rows.length
        ? `${rows.length} record${rows.length === 1 ? '' : 's'} shown`
        : '';
    }
    updateSummary();
  }

  function resetForm(options = {}) {
    const keepProfile = options.keepProfile === true;
    const savedUploader = keepProfile ? fields.uploadedBy.value : '';
    const savedDepartment = keepProfile ? fields.department.value : '';
    const savedStation = keepProfile ? fields.station.value : '';

    evidenceForm.reset();
    pendingFiles = [];
    renderPendingFiles();
    fields.date.value = new Date().toISOString().slice(0, 10);

    if (keepProfile) {
      fields.uploadedBy.value = savedUploader;
      fields.station.value = savedStation;
      if (savedDepartment) fields.department.value = savedDepartment;
    } else {
      initProfileDefaults();
    }

    applyPlanLinkContext();
    updateSummary();
    if (!options.silent) {
      showStatus('Form cleared.', 'ok');
      setTimeout(() => showStatus(''), 2400);
    }
  }

  function viewRecord(id) {
    const record = evidenceRecords.find((item) => item.id === id);
    if (!record) {
      notify('Evidence record not found.', 'error');
      return;
    }

    document.getElementById('viewTitle').textContent = record.title || 'Evidence details';
    document.getElementById('viewMeta').textContent = [
      record.date ? `Date: ${record.date}` : '',
      record.uploadedBy ? `Uploaded by: ${record.uploadedBy}` : '',
      record.createdAt ? `Saved: ${new Date(record.createdAt).toLocaleString()}` : ''
    ].filter(Boolean).join(' · ');

    document.getElementById('viewDetails').innerHTML = `
      <div class="ue-detail-item"><div class="k">Type</div><div class="v">${escapeHtml(record.type || '—')}</div></div>
      <div class="ue-detail-item"><div class="k">Activity</div><div class="v">${escapeHtml(record.relatedActivity || '—')}</div></div>
      <div class="ue-detail-item"><div class="k">Center</div><div class="v">${escapeHtml(stationLabel(record.station))}</div></div>
      <div class="ue-detail-item"><div class="k">Department</div><div class="v">${escapeHtml(departmentLabel(record.department))}</div></div>
      <div class="ue-detail-item"><div class="k">Location</div><div class="v">${escapeHtml(record.location || '—')}</div></div>
      <div class="ue-detail-item"><div class="k">Description</div><div class="v">${escapeHtml(record.description || '—')}</div></div>
    `;

    const files = Array.isArray(record.files) ? record.files : [];
    const filesEl = document.getElementById('viewFiles');
    filesEl.innerHTML = files.length ? files.map((file) => {
      const preview = file.dataUrl && file.type && file.type.startsWith('image/')
        ? `<img class="ue-preview-thumb" src="${file.dataUrl}" alt="${escapeHtml(file.name)}">`
        : `<div class="ue-preview-thumb"><i class="fas ${fileIcon(file.name, file.type)}"></i></div>`;
      const openBtn = file.dataUrl
        ? `<a class="mp-btn mp-btn--ghost mp-btn--sm" href="${file.dataUrl}" download="${escapeHtml(file.name)}" target="_blank" rel="noopener">Open</a>`
        : '';
      return `
        <div class="ue-file-preview">
          <div style="display:flex;align-items:center;gap:10px;min-width:0">
            ${preview}
            <div style="min-width:0">
              <div style="font-weight:700;font-size:0.86rem">${escapeHtml(file.name)}</div>
              <div class="ue-cell-sub">${formatFileSize(file.size)}</div>
            </div>
          </div>
          ${openBtn}
        </div>
      `;
    }).join('') : '<p class="mp-plans-empty" style="padding:16px 0">No files attached.</p>';

    viewModal.classList.add('active');
    viewModal.setAttribute('aria-hidden', 'false');
  }

  function closeView() {
    viewModal.classList.remove('active');
    viewModal.setAttribute('aria-hidden', 'true');
  }

  async function deleteRecord(id) {
    const record = evidenceRecords.find((item) => item.id === id);
    if (!record) return;
    if (!confirm(`Delete evidence "${record.title}"?`)) return;
    try {
      if (window.RDOMMSPlanningAPI) {
        await RDOMMSPlanningAPI.evidence.delete(id);
      }
      evidenceRecords = evidenceRecords.filter((item) => item.id !== id);
      renderTable();
      notify('Evidence deleted.', 'success');
    } catch (error) {
      notify(error.message || 'Failed to delete evidence.', 'error');
    }
  }

  function exportRecords() {
    const rows = getFilteredRecords();
    if (!rows.length) {
      notify('No evidence records to export.', 'warning');
      return;
    }
    if (!window.RDOMMSExport) {
      notify('Export module not loaded.', 'error');
      return;
    }
    const format = document.getElementById('exportFormat')?.value || 'pdf';
    const headers = ['Title', 'Type', 'Activity', 'Center', 'Department', 'Date', 'Uploader', 'Location', 'Files', 'Description'];
    const dataRows = rows.map((record) => [
      record.title || '',
      record.type || '',
      record.relatedActivity || '',
      stationLabel(record.station),
      departmentLabel(record.department),
      record.date || '',
      record.uploadedBy || '',
      record.location || '',
      Array.isArray(record.files) ? record.files.length : 0,
      record.description || ''
    ]);

    RDOMMSExport.exportData({
      format,
      title: 'Planning Evidence Register',
      subtitle: `Export date: ${new Date().toLocaleString()} | Records: ${rows.length}`,
      headers,
      rows: dataRows,
      filename: `planning-evidence-${new Date().toISOString().slice(0, 10)}`
    }).then(() => notify(`Evidence exported as ${format.toUpperCase()}.`, 'success'))
      .catch((error) => notify(error.message || 'Export failed.', 'error'));
  }

  function bindEvents() {
    fileQueue?.addEventListener('click', (event) => {
      const button = event.target.closest('[data-remove-index]');
      if (!button) return;
      pendingFiles.splice(Number(button.dataset.removeIndex), 1);
      renderPendingFiles();
      updateSummary();
    });

    uploadZone?.addEventListener('click', () => fileInput?.click());
    fileInput?.addEventListener('change', (event) => {
      addPendingFiles(event.target.files);
      fileInput.value = '';
    });

    uploadZone?.addEventListener('dragover', (event) => {
      event.preventDefault();
      uploadZone.classList.add('is-dragover');
    });
    uploadZone?.addEventListener('dragleave', (event) => {
      event.preventDefault();
      uploadZone.classList.remove('is-dragover');
    });
    uploadZone?.addEventListener('drop', (event) => {
      event.preventDefault();
      uploadZone.classList.remove('is-dragover');
      addPendingFiles(event.dataTransfer.files);
    });

    evidenceForm?.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!fields.title.value.trim()) {
        notify('Evidence title is required.', 'error');
        return;
      }
      if (!fields.type.value) {
        notify('Select an evidence type.', 'error');
        return;
      }
      if (!fields.uploadedBy.value.trim()) {
        notify('Uploader name is required.', 'error');
        return;
      }
      if (!pendingFiles.length) {
        notify('Attach at least one file.', 'warning');
        return;
      }

      const record = {
        id: uid('evidence'),
        title: fields.title.value.trim(),
        type: fields.type.value,
        relatedActivity: fields.activity.value.trim(),
        date: fields.date.value,
        location: fields.location.value.trim(),
        station: fields.station.value,
        uploadedBy: fields.uploadedBy.value.trim(),
        department: fields.department.value,
        description: fields.description.value.trim(),
        files: pendingFiles.map((file) => ({ ...file })),
        createdAt: new Date().toISOString()
      };

      try {
        if (!window.RDOMMSPlanningAPI) {
          notify('Planning API not loaded.', 'error');
          return;
        }
        showStatus('Saving evidence…', 'ok');
        const saved = await RDOMMSPlanningAPI.evidence.create(record);
        evidenceRecords = [saved, ...evidenceRecords];
        renderTable();
        resetForm({ keepProfile: true, silent: true });
        showStatus('Evidence saved successfully.', 'ok');
        notify('Evidence saved successfully.', 'success');
      } catch (error) {
        showStatus(error.message || 'Failed to save evidence.', 'error');
        notify(error.message || 'Failed to save evidence.', 'error');
      }
    });

    document.getElementById('resetFormBtn')?.addEventListener('click', () => resetForm());
    document.getElementById('searchInput')?.addEventListener('input', renderTable);
    document.getElementById('filterType')?.addEventListener('change', renderTable);
    document.getElementById('filterStation')?.addEventListener('change', renderTable);
    document.getElementById('filterDepartment')?.addEventListener('change', renderTable);
    document.getElementById('resetFiltersBtn')?.addEventListener('click', () => {
      const searchInput = document.getElementById('searchInput');
      const filterType = document.getElementById('filterType');
      const filterStation = document.getElementById('filterStation');
      const filterDepartment = document.getElementById('filterDepartment');
      if (searchInput) searchInput.value = '';
      if (filterType) filterType.value = '';
      if (filterStation) filterStation.value = '';
      if (filterDepartment) filterDepartment.value = '';
      renderTable();
    });
    document.getElementById('exportBtn')?.addEventListener('click', exportRecords);
    document.getElementById('closeViewBtn')?.addEventListener('click', closeView);
    viewModal?.addEventListener('click', (event) => {
      if (event.target === viewModal) closeView();
    });

    tableBody?.addEventListener('click', (event) => {
      const button = event.target.closest('[data-action][data-id]');
      if (!button) return;
      const id = button.dataset.id;
      if (button.dataset.action === 'view') viewRecord(id);
      if (button.dataset.action === 'delete') deleteRecord(id);
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') closeView();
    });
  }

  async function bootstrap() {
    ensureOrganizationsReady();
    ensureDepartmentsReady();
    initProfileDefaults();
    applyPlanLinkContext();
    bindEvents();

    if (window.RDOMMSPlanningAPI) {
      try {
        await RDOMMSPlanningAPI.evidence.migrate();
        await loadRecords();
        apiReady = true;
      } catch (error) {
        notify(RDOMMSPlanningAPI.formatLoadError(error), 'warning');
      }
    }

    await loadActivityOptions();
    renderPendingFiles();
    renderTable();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap);
  } else {
    bootstrap();
  }
})();
