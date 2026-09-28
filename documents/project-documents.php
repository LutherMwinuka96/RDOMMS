<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/project-page-access.php';
rdomms_require_project_module_page();
require_once __DIR__ . '/../config/dates.php';
$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Project Documents | RDO|MMS</title>
<meta name="description" content="Manage project files, upload evidence, and keep records organized in RDO|MMS.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="../assets/css/rdomms-icons-offline.css">
<link rel="stylesheet" href="../assets/css/project-nav.css?v=3">
<link rel="stylesheet" href="../assets/css/planning-pages.css">
<link rel="stylesheet" href="../assets/css/rdomms-toast.css">
<script src="../assets/js/rdomms-security.js"></script>
<script src="../assets/js/localStorage-service.js"></script>
<style>
:root{
  --green-900:#163120;--green-800:#21482d;--green-500:#58a46a;
  --text-900:#16201a;--text-700:#3f5148;--text-500:#66786f;
  --line:rgba(22,48,33,0.12);--shadow-md:0 12px 28px rgba(13,39,23,0.12);
  --shadow-sm:0 6px 18px rgba(13,39,23,0.08);--radius-xl:30px;
  --font:"Inter","Segoe UI",Tahoma,sans-serif;--danger:#c74d4d;--info:#2563eb;
}
*{box-sizing:border-box}html,body{min-height:100%}
body{margin:0;font-family:var(--font);color:var(--text-900);
  background:radial-gradient(circle at top left,rgba(88,164,106,.20),transparent 26%),
  radial-gradient(circle at right 15%,rgba(231,221,201,.86),transparent 22%),
  linear-gradient(160deg,#f7faf7 0%,#edf4ef 100%);overflow-x:hidden}
.page-shell{position:relative;z-index:1;max-width:1500px;margin:0 auto;padding:24px}
.layout-grid{display:flex;flex-direction:column;gap:22px;margin-top:22px}
.panel{background:rgba(255,255,255,.92);border:1px solid rgba(22,48,33,.10);border-radius:var(--radius-xl);box-shadow:var(--shadow-md);backdrop-filter:blur(14px);overflow:hidden}
.panel-header{padding:20px 22px 0}.panel-title{margin:0;font-size:1.2rem;color:var(--green-900)}
.panel-subtitle{margin:8px 0 0;color:var(--text-500);line-height:1.6}.section-body{padding:22px}
.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}
.summary-card{background:linear-gradient(180deg,#fff 0%,#f8fbf8 100%);border:1px solid var(--line);border-radius:18px;padding:14px 16px;box-shadow:var(--shadow-sm)}
.summary-card .k{font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--text-500)}
.summary-card .v{font-size:1.55rem;font-weight:800;color:var(--green-900);margin-top:6px}
.summary-card .s{font-size:.78rem;color:var(--text-500);margin-top:4px}
.toolbar{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:18px;align-items:center}
.toolbar input,.toolbar select{border:1px solid var(--line);border-radius:14px;padding:10px 14px;background:#fff;min-width:160px}
.toolbar input{flex:1;min-width:220px}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.field{display:flex;flex-direction:column;gap:6px}.field.full{grid-column:1/-1}
.field label{font-size:.82rem;font-weight:700;color:var(--text-700)}
.field input,.field select,.field textarea{border:1px solid var(--line);border-radius:14px;padding:11px 14px;background:#fff}
.field textarea{min-height:90px;resize:vertical}
.field small{color:var(--text-500);font-size:.78rem}
.upload-zone{border:2px dashed rgba(33,72,45,.22);border-radius:18px;padding:18px;text-align:center;background:#f8fbf8;cursor:pointer}
.upload-zone.dragover{border-color:var(--green-500);background:#eef8f0}
.file-chip{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border:1px solid var(--line);border-radius:14px;background:#fff;margin-top:8px}
.category-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:18px 0}
.category-card{border:1px solid var(--line);border-radius:18px;padding:14px;background:#fff;box-shadow:var(--shadow-sm);cursor:pointer;transition:.2s}
.category-card:hover,.category-card.active{border-color:rgba(88,164,106,.45);transform:translateY(-1px)}
.category-card h4{margin:8px 0 4px;font-size:.95rem;color:var(--green-900)}
.category-card p{margin:0;font-size:.8rem;color:var(--text-500);line-height:1.5}
.category-count{font-size:.75rem;font-weight:800;color:var(--green-800);text-transform:uppercase}
.data-table{width:100%;border-collapse:collapse}
.data-table th,.data-table td{padding:12px 10px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}
.data-table th{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-500)}
.doc-title{font-weight:700;color:var(--green-900)}.doc-sub{font-size:.8rem;color:var(--text-500);margin-top:4px}
.badge{display:inline-flex;padding:4px 10px;border-radius:999px;font-size:.72rem;font-weight:800}
.badge-success{background:#ecfdf5;color:#166534}.badge-warning{background:#fff7e6;color:#92400e}
.badge-info{background:#eff6ff;color:#1d4ed8}.badge-secondary{background:#f3f4f6;color:#4b5563}
.btn-row{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
.action-btn,.icon-btn{border:none;border-radius:12px;padding:8px 14px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.action-btn.primary{background:linear-gradient(135deg,var(--green-800),var(--green-500));color:#fff}
.action-btn.secondary{background:#fff;border:1px solid var(--line);color:var(--text-700)}
.action-btn.danger{background:#fff1f1;color:var(--danger);border:1px solid #f3c0c0}
.icon-btn{width:34px;height:34px;padding:0;justify-content:center;background:#f8fbf8;border:1px solid var(--line);color:var(--text-700)}
.icon-btn.danger{color:var(--danger)}.icon-btn:hover{background:#eef8f0}
.empty-row{padding:28px;text-align:center;color:var(--text-500)}
.modal-overlay{position:fixed;inset:0;background:rgba(10,24,16,.45);display:none;align-items:center;justify-content:center;padding:20px;z-index:2000}
.modal-overlay.active{display:flex}
.modal-card{background:#fff;border-radius:24px;max-width:720px;width:100%;max-height:90vh;overflow:auto;box-shadow:var(--shadow-md)}
.modal-head{padding:20px 22px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center}
.modal-body{padding:22px}
.detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.detail-item{border:1px solid var(--line);border-radius:14px;padding:12px;background:#fafcfa}
.detail-item .k{font-size:.72rem;font-weight:800;text-transform:uppercase;color:var(--text-500)}
.detail-item .v{margin-top:6px;font-weight:600;color:var(--green-900);word-break:break-word}
.file-preview-list{display:flex;flex-direction:column;gap:10px;margin-top:14px}
.file-preview{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px;border:1px solid var(--line);border-radius:14px}
@media(max-width:900px){.summary-grid,.category-grid,.form-grid,.detail-grid{grid-template-columns:1fr}}
</style>
</head>
<body>

<nav id="rdomms-project-nav" data-active="project-documents"></nav>
<script src="../assets/js/rpn-nav-shared.js?v=2"></script>
<script src="../assets/js/project-nav.js?v=8"></script>

<div class="page-shell">
  <section class="panel">
    <div class="panel-header">
      <h2 class="panel-title">Project Documents</h2>
      <p class="panel-subtitle">Upload files, organize by category, and keep a searchable register synced with the database.</p>
    </div>
    <div class="section-body">
      <div class="summary-grid">
        <div class="summary-card"><div class="k">Total</div><div class="v" id="sumTotal">0</div><div class="s">All documents</div></div>
        <div class="summary-card"><div class="k">Recent</div><div class="v" id="sumRecent">0</div><div class="s">Updated last 7 days</div></div>
        <div class="summary-card"><div class="k">Pending</div><div class="v" id="sumPending">0</div><div class="s">Awaiting review</div></div>
        <div class="summary-card"><div class="k">Archived</div><div class="v" id="sumArchived">0</div><div class="s">Archived records</div></div>
      </div>

      <div class="toolbar">
        <input type="search" id="searchInput" placeholder="Search title, project, owner, notes…">
        <select id="categoryFilter">
          <option value="all">All categories</option>
          <option value="project-evidence">Project Evidence</option>
          <option value="department-plans">Department Plans</option>
          <option value="reports">Reports</option>
          <option value="contracts">Contracts</option>
          <option value="uploads">Supporting Uploads</option>
          <option value="archive">Archive Intake</option>
        </select>
        <select id="statusFilter">
          <option value="all">All statuses</option>
          <option value="draft">Draft</option>
          <option value="review">Pending Review</option>
          <option value="ready">Ready</option>
          <option value="archived">Archived</option>
        </select>
        <select id="sortFilter">
          <option value="recent">Sort: Recent</option>
          <option value="name">Sort: Name</option>
          <option value="project">Sort: Project</option>
        </select>
        <select id="exportFormat">
          <option value="pdf" selected>PDF</option>
          <option value="excel">Excel</option>
          <option value="word">Word</option>
        </select>
        <button type="button" class="action-btn secondary" id="exportBtn"><i class="fas fa-download"></i> Export</button>
      </div>

      <div class="section-divider" style="margin-top:0;padding-top:0;border-top:0">
        <h3 id="formTitle">Upload Document</h3>
        <p>Attach a file and save to the project documents register.</p>
      </div>

      <form id="documentForm">
        <input type="hidden" id="editingId" value="">
        <div class="form-grid">
          <div class="field full">
            <label for="docCategory">Category *</label>
            <select id="docCategory" required>
              <option value="project-evidence">Project Evidence</option>
              <option value="department-plans">Department Plans</option>
              <option value="reports">Reports</option>
              <option value="contracts">Contracts</option>
              <option value="uploads">Supporting Uploads</option>
              <option value="archive">Archive Intake</option>
            </select>
            <small id="categoryHint"></small>
          </div>
          <div class="field" id="projectFieldWrap">
            <label for="linkedProject">Project</label>
            <select id="linkedProject"><option value="">Select project</option></select>
          </div>
          <div class="field" id="departmentFieldWrap" style="display:none">
            <label for="linkedDepartment">Department</label>
            <select id="linkedDepartment" data-rdomms-department data-empty-label="Select department"></select>
          </div>
          <div class="field full">
            <label for="docTitle">Title *</label>
            <input type="text" id="docTitle" required placeholder="Document title">
          </div>
          <div class="field">
            <label for="docOwner">Owner</label>
            <input type="text" id="docOwner" placeholder="Document owner">
          </div>
          <div class="field">
            <label for="docDate">Date</label>
            <input type="date" id="docDate" value="<?php echo htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?>">
          </div>
          <div class="field">
            <label for="docStatus">Status</label>
            <select id="docStatus">
              <option value="draft">Draft</option>
              <option value="review">Pending Review</option>
              <option value="ready">Ready</option>
              <option value="archived">Archived</option>
            </select>
          </div>
          <div class="field full">
            <label>File *</label>
            <div class="upload-zone" id="uploadZone">
              <i class="fas fa-cloud-upload-alt"></i>
              <div style="margin-top:8px;font-weight:700">Drop file here or click to browse</div>
              <div style="font-size:.8rem;color:var(--text-500);margin-top:4px">Max 1.5 MB</div>
            </div>
            <input type="file" id="fileInput" hidden>
            <div id="fileQueue"></div>
          </div>
          <div class="field full">
            <label for="docNotes">Notes</label>
            <textarea id="docNotes" placeholder="Additional notes about this document…"></textarea>
          </div>
        </div>
        <div class="btn-row">
          <button type="submit" class="action-btn primary" id="saveBtn"><i class="fas fa-save"></i> Save Document</button>
          <button type="button" class="action-btn secondary" id="resetFormBtn"><i class="fas fa-rotate-left"></i> Clear</button>
        </div>
      </form>

      <div class="section-divider">
        <h3>Categories</h3>
        <p>Quick filter by document type.</p>
      </div>
      <div class="category-grid" id="categoryGrid"></div>

      <div class="section-divider">
        <h3>Document Register</h3>
        <p id="resultsCount">0 records</p>
      </div>
      <div style="overflow:auto">
        <table class="data-table">
          <thead>
            <tr>
              <th>Document</th>
              <th>Project</th>
              <th>Category</th>
              <th>Owner</th>
              <th>Updated</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="tableBody"></tbody>
        </table>
        <div class="empty-row" id="emptyState" style="display:none">No documents found. Upload your first document above.</div>
      </div>
    </div>
  </section>
</div>

<div class="modal-overlay" id="viewModal" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-head">
      <h3 id="viewTitle" style="margin:0">Document details</h3>
      <button type="button" class="icon-btn" id="closeViewBtn" title="Close"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <p id="viewMeta" style="color:var(--text-500);margin-bottom:14px"></p>
      <div class="detail-grid" id="viewDetails"></div>
      <h4 style="margin:18px 0 10px">Attached files</h4>
      <div class="file-preview-list" id="viewFiles"></div>
    </div>
  </div>
</div>

<script src="../assets/js/rdomms-departments.js"></script>
<script src="../assets/js/rdomms-toast.js"></script>
<script src="../assets/vendor/html2pdf.bundle.min.js"></script>
<script src="../assets/vendor/sheetjs/xlsx.full.min.js"></script>
<script src="../assets/js/rdomms-export.js"></script>
<script>
(function () {
  const API = '../api/project-documents.php';
  const STORAGE_KEY = 'rdomms_project_documents_v2';
  const MIGRATION_FLAG = 'rdomms_project_documents_migrated';
  const MAX_FILE_SIZE = 1.5 * 1024 * 1024;

  const CATEGORY_META = {
    'project-evidence': { label: 'Project Evidence', hint: 'Tie evidence to a project workspace.', projectRequired: true },
    'department-plans': { label: 'Department Plans', hint: 'Link plans to a department or office.', departmentRequired: true },
    reports: { label: 'Reports', hint: 'Reports are usually tied to a project.', projectRequired: true },
    contracts: { label: 'Contracts', hint: 'Contracts may reference a project or legal unit.', projectRequired: false },
    uploads: { label: 'Supporting Uploads', hint: 'Miscellaneous supporting attachments.', projectRequired: false },
    archive: { label: 'Archive Intake', hint: 'Archived records for compliance.', projectRequired: false }
  };

  const STATUS_BADGE = { draft: 'badge-info', review: 'badge-warning', ready: 'badge-success', archived: 'badge-secondary' };

  let documents = [];
  let pendingFiles = [];
  let editingId = '';

  const fields = {
    category: document.getElementById('docCategory'),
    project: document.getElementById('linkedProject'),
    department: document.getElementById('linkedDepartment'),
    title: document.getElementById('docTitle'),
    owner: document.getElementById('docOwner'),
    date: document.getElementById('docDate'),
    status: document.getElementById('docStatus'),
    notes: document.getElementById('docNotes')
  };

  function notify(msg, type = 'info') {
    if (window.RDOMMSToast) {
      RDOMMSToast.show(msg, type, { title: 'Project Documents', duration: type === 'error' ? 6800 : 4200, allowMultiple: true });
      return;
    }
    alert(msg);
  }

  function escapeHtml(v) {
    return String(v == null ? '' : v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  async function apiRequest(url, options = {}) {
    const method = String(options.method || 'GET').toUpperCase();
    const headers = {
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(options.headers || {})
    };

    if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method) && window.RDOMMSSecurity?.ensureCsrfToken) {
      try {
        const token = await window.RDOMMSSecurity.ensureCsrfToken();
        if (token) headers['X-CSRF-Token'] = token;
      } catch (error) {
        console.warn('CSRF token unavailable:', error);
      }
    }

    const response = await fetch(url, {
      credentials: 'same-origin',
      ...options,
      method,
      headers
    });
    const text = await response.text();
    let payload = {};
    try { payload = text ? JSON.parse(text) : {}; } catch (e) {
      throw new Error('Invalid server response. Sign in and ensure the database is installed.');
    }
    if (!response.ok || payload.status === 'error') {
      const err = new Error(payload.message || `Request failed (${response.status})`);
      err.status = response.status;
      throw err;
    }
    return payload;
  }

  async function loadDocuments() {
    const payload = await apiRequest(API);
    documents = Array.isArray(payload.data) ? payload.data : [];
  }

  async function migrateLocalData() {
    if (localStorage.getItem(MIGRATION_FLAG) === '1') return;
    let items = [];
    try { items = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]'); } catch (e) { items = []; }
    if (!items.length) { localStorage.setItem(MIGRATION_FLAG, '1'); return; }
    if (documents.length) { localStorage.setItem(MIGRATION_FLAG, '1'); return; }
    await apiRequest(`${API}?action=migrate`, { method: 'POST', body: JSON.stringify({ items }) });
    localStorage.setItem(MIGRATION_FLAG, '1');
    await loadDocuments();
  }

  async function loadProjects() {
    try {
      const payload = await apiRequest('../api/projects.php');
      const list = Array.isArray(payload.data) ? payload.data : [];
      fields.project.innerHTML = '<option value="">Select project</option>';
      list.forEach((p) => {
        const opt = document.createElement('option');
        opt.value = p.name || p.project_id || '';
        opt.textContent = p.name || p.project_id || 'Unnamed project';
        fields.project.appendChild(opt);
      });
    } catch (e) { console.warn(e); }
  }

  function ensureDepartments() {
    if (window.RDOMMSDepartments) {
      RDOMMSDepartments.fillSelect(fields.department, { emptyLabel: 'Select department', selectedValue: fields.department.value || '' });
    }
  }

  function initProfileDefaults() {
    try {
      const user = JSON.parse(localStorage.getItem('currentUser') || '{}');
      if (!fields.owner.value.trim() && user.fullname) fields.owner.value = user.fullname;
      if (!fields.department.value && user.department && window.RDOMMSDepartments) {
        fields.department.value = RDOMMSDepartments.getValue(user.department) || user.department;
      }
    } catch (e) {}
  }

  function updateCategoryUi() {
    const meta = CATEGORY_META[fields.category.value] || {};
    document.getElementById('categoryHint').textContent = meta.hint || '';
    document.getElementById('projectFieldWrap').style.display = meta.projectRequired ? 'flex' : 'none';
    document.getElementById('departmentFieldWrap').style.display = meta.departmentRequired ? 'flex' : 'none';
    fields.project.required = !!meta.projectRequired;
    fields.department.required = !!meta.departmentRequired;
  }

  function readFileAsDataUrl(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(String(reader.result || ''));
      reader.onerror = () => reject(new Error(`Failed to read ${file.name}`));
      reader.readAsDataURL(file);
    });
  }

  async function addFiles(fileList) {
    for (const file of Array.from(fileList || [])) {
      if (file.size > MAX_FILE_SIZE) { notify(`"${file.name}" exceeds 1.5 MB.`, 'warning'); continue; }
      try {
        pendingFiles = [{
          id: `file-${Date.now()}`,
          name: file.name,
          size: file.size,
          type: file.type || 'application/octet-stream',
          dataUrl: await readFileAsDataUrl(file)
        }];
        renderFileQueue();
      } catch (e) { notify(e.message, 'error'); }
    }
  }

  function renderFileQueue() {
    const el = document.getElementById('fileQueue');
    if (!pendingFiles.length) { el.innerHTML = ''; return; }
    const f = pendingFiles[0];
    el.innerHTML = `<div class="file-chip"><div><strong>${escapeHtml(f.name)}</strong><div style="font-size:.78rem;color:var(--text-500)">${Math.round(f.size/1024)} KB</div></div><button type="button" class="icon-btn danger" id="removeFileBtn"><i class="fas fa-trash"></i></button></div>`;
    document.getElementById('removeFileBtn').onclick = () => { pendingFiles = []; renderFileQueue(); };
  }

  function getFiltered() {
    const q = (document.getElementById('searchInput').value || '').toLowerCase().trim();
    const cat = document.getElementById('categoryFilter').value;
    const st = document.getElementById('statusFilter').value;
    const sort = document.getElementById('sortFilter').value;
    let list = documents.filter((d) => {
      if (cat !== 'all' && d.category !== cat) return false;
      if (st !== 'all' && d.status !== st) return false;
      if (!q) return true;
      const hay = `${d.title} ${d.project} ${d.owner} ${d.notes} ${d.categoryLabel}`.toLowerCase();
      return hay.includes(q);
    });
    if (sort === 'name') list.sort((a,b) => a.title.localeCompare(b.title));
    else if (sort === 'project') list.sort((a,b) => (a.project||'').localeCompare(b.project||''));
    else list.sort((a,b) => new Date(b.updatedAt || 0) - new Date(a.updatedAt || 0));
    return list;
  }

  function formatDate(v) {
    if (!v) return '—';
    const d = new Date(v.includes('T') ? v : `${v}T00:00:00`);
    return Number.isNaN(d.getTime()) ? v : d.toLocaleDateString('en-GB');
  }

  function updateSummary() {
    const weekAgo = Date.now() - 7 * 86400000;
    document.getElementById('sumTotal').textContent = documents.length;
    document.getElementById('sumRecent').textContent = documents.filter((d) => new Date(d.updatedAt || d.updated || 0).getTime() >= weekAgo).length;
    document.getElementById('sumPending').textContent = documents.filter((d) => d.status === 'review').length;
    document.getElementById('sumArchived').textContent = documents.filter((d) => d.status === 'archived').length;
  }

  function renderCategories() {
    const grid = document.getElementById('categoryGrid');
    const counts = {};
    Object.keys(CATEGORY_META).forEach((k) => { counts[k] = documents.filter((d) => d.category === k).length; });
    grid.innerHTML = Object.keys(CATEGORY_META).map((key) => {
      const meta = CATEGORY_META[key];
      const active = document.getElementById('categoryFilter').value === key ? ' active' : '';
      return `<div class="category-card${active}" data-cat="${key}"><div class="category-count">${counts[key]} files</div><h4>${meta.label}</h4><p>${escapeHtml(meta.hint)}</p></div>`;
    }).join('');
    grid.querySelectorAll('[data-cat]').forEach((card) => {
      card.addEventListener('click', () => {
        document.getElementById('categoryFilter').value = card.dataset.cat;
        renderTable();
        renderCategories();
      });
    });
  }

  function renderTable() {
    const list = getFiltered();
    const tbody = document.getElementById('tableBody');
    const empty = document.getElementById('emptyState');
    document.getElementById('resultsCount').textContent = `${list.length} record${list.length === 1 ? '' : 's'}`;
    if (!list.length) {
      tbody.innerHTML = '';
      empty.style.display = 'block';
      return;
    }
    empty.style.display = 'none';
    tbody.innerHTML = list.map((doc) => `
      <tr>
        <td><div class="doc-title">${escapeHtml(doc.title)}</div><div class="doc-sub">${escapeHtml(doc.notes || 'No notes')}</div></td>
        <td>${escapeHtml(doc.project || '—')}</td>
        <td>${escapeHtml(doc.categoryLabel || doc.category)}</td>
        <td>${escapeHtml(doc.owner || '—')}</td>
        <td>${formatDate(doc.updated || doc.updatedAt)}</td>
        <td><span class="badge ${STATUS_BADGE[doc.status] || 'badge-secondary'}">${escapeHtml(doc.statusLabel || doc.status)}</span></td>
        <td>
          <div style="display:flex;gap:6px">
            <button class="icon-btn" type="button" data-view="${escapeHtml(doc.id)}" title="View"><i class="fas fa-eye"></i></button>
            <button class="icon-btn" type="button" data-edit="${escapeHtml(doc.id)}" title="Edit"><i class="fas fa-pen"></i></button>
            <button class="icon-btn" type="button" data-download="${escapeHtml(doc.id)}" title="Download"><i class="fas fa-download"></i></button>
            <button class="icon-btn danger" type="button" data-delete="${escapeHtml(doc.id)}" title="Delete"><i class="fas fa-trash"></i></button>
          </div>
        </td>
      </tr>
    `).join('');
  }

  function resetForm() {
    editingId = '';
    document.getElementById('editingId').value = '';
    document.getElementById('formTitle').textContent = 'Upload Document';
    document.getElementById('saveBtn').innerHTML = '<i class="fas fa-save"></i> Save Document';
    document.getElementById('documentForm').reset();
    fields.date.value = '<?php echo htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?>';
    fields.category.value = 'project-evidence';
    fields.status.value = 'draft';
    pendingFiles = [];
    renderFileQueue();
    updateCategoryUi();
    initProfileDefaults();
  }

  function fillForm(doc) {
    editingId = doc.id;
    document.getElementById('editingId').value = doc.id;
    document.getElementById('formTitle').textContent = 'Edit Document';
    document.getElementById('saveBtn').innerHTML = '<i class="fas fa-save"></i> Update Document';
    fields.category.value = doc.category || 'project-evidence';
    fields.project.value = doc.project || '';
    fields.department.value = doc.department || '';
    fields.title.value = doc.title || '';
    fields.owner.value = doc.owner || '';
    fields.date.value = doc.updated || doc.document_date || '';
    fields.status.value = doc.status || 'draft';
    fields.notes.value = doc.notes || '';
    pendingFiles = Array.isArray(doc.files) ? doc.files.map((f) => ({ ...f })) : [];
    renderFileQueue();
    updateCategoryUi();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function viewDocument(id) {
    const doc = documents.find((d) => String(d.id) === String(id));
    if (!doc) return;
    document.getElementById('viewTitle').textContent = doc.title;
    document.getElementById('viewMeta').textContent = `Updated: ${formatDate(doc.updatedAt || doc.updated)} · Owner: ${doc.owner || '—'}`;
    document.getElementById('viewDetails').innerHTML = `
      <div class="detail-item"><div class="k">Project</div><div class="v">${escapeHtml(doc.project || '—')}</div></div>
      <div class="detail-item"><div class="k">Category</div><div class="v">${escapeHtml(doc.categoryLabel || doc.category)}</div></div>
      <div class="detail-item"><div class="k">Department</div><div class="v">${escapeHtml(doc.department || '—')}</div></div>
      <div class="detail-item"><div class="k">Status</div><div class="v">${escapeHtml(doc.statusLabel || doc.status)}</div></div>
      <div class="detail-item" style="grid-column:1/-1"><div class="k">Notes</div><div class="v">${escapeHtml(doc.notes || '—')}</div></div>
    `;
    const files = Array.isArray(doc.files) ? doc.files : [];
    document.getElementById('viewFiles').innerHTML = files.length ? files.map((f) => `
      <div class="file-preview">
        <div><strong>${escapeHtml(f.name)}</strong><div style="font-size:.78rem;color:var(--text-500)">${Math.round((f.size||0)/1024)} KB</div></div>
        ${f.dataUrl ? `<a class="action-btn secondary" href="${f.dataUrl}" download="${escapeHtml(f.name)}" target="_blank" rel="noopener">Open</a>` : ''}
      </div>
    `).join('') : '<div class="empty-row">No file attached.</div>';
    document.getElementById('viewModal').classList.add('active');
  }

  async function deleteDocument(id) {
    const doc = documents.find((d) => String(d.id) === String(id));
    if (!doc || !confirm(`Delete "${doc.title}"?`)) return;
    await apiRequest(`${API}?id=${encodeURIComponent(id)}`, { method: 'DELETE' });
    documents = documents.filter((d) => String(d.id) !== String(id));
    refreshUi();
    notify('Document deleted.', 'success');
  }

  function downloadDocument(id) {
    const doc = documents.find((d) => String(d.id) === String(id));
    if (!doc) return;
    const file = Array.isArray(doc.files) && doc.files[0];
    if (file?.dataUrl) {
      const a = document.createElement('a');
      a.href = file.dataUrl;
      a.download = file.name || `${doc.title}.bin`;
      a.click();
      notify('File download started.', 'success');
      return;
    }
    const blob = new Blob([`Title: ${doc.title}\nProject: ${doc.project}\nNotes:\n${doc.notes || ''}`], { type: 'text/plain' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `${doc.title.replace(/[^a-z0-9]+/gi,'_')}.txt`;
    a.click();
    URL.revokeObjectURL(a.href);
    notify('Metadata exported as text file.', 'info');
  }

  function refreshUi() {
    updateSummary();
    renderCategories();
    renderTable();
  }

  async function exportRegister() {
    const rows = getFiltered();
    if (!rows.length) { notify('No documents to export.', 'warning'); return; }
    if (!window.RDOMMSExport) { notify('Export module not loaded.', 'error'); return; }
    const format = document.getElementById('exportFormat').value || 'pdf';
    await RDOMMSExport.exportData({
      format,
      title: 'Project Documents Register',
      subtitle: `Generated: ${new Date().toLocaleString()} | Records: ${rows.length}`,
      headers: ['Title', 'Project', 'Category', 'Owner', 'Date', 'Status', 'Notes'],
      rows: rows.map((d) => [d.title, d.project, d.categoryLabel, d.owner, d.updated || '', d.statusLabel, d.notes]),
      filename: `project-documents-${new Date().toISOString().slice(0,10)}`
    });
    notify(`Exported as ${format.toUpperCase()}.`, 'success');
  }

  document.getElementById('documentForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const meta = CATEGORY_META[fields.category.value] || {};
    if (meta.projectRequired && !fields.project.value) { notify('Select a project.', 'error'); return; }
    if (meta.departmentRequired && !fields.department.value) { notify('Select a department.', 'error'); return; }
    if (!pendingFiles.length && !editingId) { notify('Attach a file.', 'warning'); return; }

    const payload = {
      id: editingId || `pdoc-${Date.now()}`,
      title: fields.title.value.trim(),
      category: fields.category.value,
      project: fields.project.value,
      department: fields.department.value,
      owner: fields.owner.value.trim(),
      updated: fields.date.value,
      status: fields.status.value,
      notes: fields.notes.value.trim(),
      files: pendingFiles
    };

    try {
      const saved = await apiRequest(API, { method: 'POST', body: JSON.stringify(payload) });
      const idx = documents.findIndex((d) => String(d.id) === String(saved.data.id));
      if (idx >= 0) documents[idx] = saved.data; else documents.unshift(saved.data);
      refreshUi();
      resetForm();
      notify(editingId ? 'Document updated.' : 'Document saved.', 'success');
    } catch (err) {
      notify(err.message, err.status === 401 ? 'warning' : 'error');
    }
  });

  document.getElementById('tableBody').addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-view],button[data-edit],button[data-download],button[data-delete]');
    if (!btn) return;
    const id = btn.dataset.view || btn.dataset.edit || btn.dataset.download || btn.dataset.delete;
    if (btn.dataset.view) viewDocument(id);
    if (btn.dataset.edit) fillForm(documents.find((d) => String(d.id) === String(id)));
    if (btn.dataset.download) downloadDocument(id);
    if (btn.dataset.delete) deleteDocument(id).catch((err) => notify(err.message, 'error'));
  });

  ['searchInput','categoryFilter','statusFilter','sortFilter'].forEach((id) => {
    document.getElementById(id).addEventListener('input', refreshUi);
    document.getElementById(id).addEventListener('change', refreshUi);
  });

  document.getElementById('exportBtn').addEventListener('click', () => exportRegister().catch((e) => notify(e.message, 'error')));
  document.getElementById('resetFormBtn').addEventListener('click', resetForm);
  document.getElementById('closeViewBtn').addEventListener('click', () => document.getElementById('viewModal').classList.remove('active'));
  document.getElementById('viewModal').addEventListener('click', (e) => { if (e.target.id === 'viewModal') e.target.classList.remove('active'); });
  fields.category.addEventListener('change', updateCategoryUi);

  const uploadZone = document.getElementById('uploadZone');
  const fileInput = document.getElementById('fileInput');
  uploadZone.addEventListener('click', () => fileInput.click());
  fileInput.addEventListener('change', (e) => { addFiles(e.target.files); fileInput.value = ''; });
  uploadZone.addEventListener('dragover', (e) => { e.preventDefault(); uploadZone.classList.add('dragover'); });
  uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('dragover'));
  uploadZone.addEventListener('drop', (e) => { e.preventDefault(); uploadZone.classList.remove('dragover'); addFiles(e.dataTransfer.files); });

  async function bootstrap() {
    ensureDepartments();
    initProfileDefaults();
    updateCategoryUi();
    try {
      await loadDocuments();
      await migrateLocalData();
    } catch (err) {
      console.warn(err);
      notify(err.status === 401 ? 'Sign in to sync documents with the database.' : err.message, 'warning');
    }
    await loadProjects();
    refreshUi();
    resetForm();
  }

  bootstrap();
})();
</script>
</body>
</html>
