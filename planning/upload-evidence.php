<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/planning-access.php';
rdomms_require_planning_module_page();
require_once __DIR__ . '/../config/dates.php';

$currentYear = rdomms_current_year();
$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Upload Evidence | RDO|MMS</title>
<meta name="description" content="Upload and manage planning evidence documents for RDO|MMS activities.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="../assets/css/rdomms-icons-offline.css">
<link rel="stylesheet" href="../assets/css/rdomms-session-countdown.css?v=4">
<link rel="stylesheet" href="../assets/css/project-nav.css?v=4">
<link rel="stylesheet" href="../assets/css/planning-pages.css">
<link rel="stylesheet" href="../assets/css/monthly-plans-workspace.css?v=1">
<link rel="stylesheet" href="../assets/css/upload-evidence-workspace.css?v=1">
<link rel="stylesheet" href="../assets/css/rdomms-toast.css">
</head>
<body class="planning-page ue-evidence">
<nav id="rdomms-planning-nav" data-active="upload-evidence"></nav>

<div class="mp-shell">
  <header class="mp-head">
    <div>
      <h1>Upload Evidence</h1>
      <p>Attach photos, documents, and media as proof of planning activities for <?php echo (int) $currentYear; ?>.</p>
    </div>
    <div class="mp-stats" aria-live="polite">
      <span class="mp-stat"><strong id="statTotal">0</strong> records</span>
      <span class="mp-stat"><strong id="statFiles">0</strong> files</span>
      <span class="mp-stat"><strong id="statMonth">0</strong> this month</span>
      <span class="mp-stat"><strong id="statQueue">0</strong> queued</span>
    </div>
  </header>

  <section class="mp-card" id="uploadPanel">
    <div class="mp-card-body">
      <h2 style="margin:0 0 12px;font-size:1rem;color:var(--pl-green-900,#163120);">
        <i class="fas fa-cloud-arrow-up"></i> New evidence
      </h2>
      <div class="mp-banner" id="planLinkBanner" role="status"></div>

      <form id="evidenceForm">
        <div class="ue-form-grid">
          <div class="mp-field">
            <label for="evidenceTitle">Title <span class="req">*</span></label>
            <input id="evidenceTitle" type="text" placeholder="Evidence title" required>
          </div>
          <div class="mp-field">
            <label for="evidenceType">Type <span class="req">*</span></label>
            <select id="evidenceType" required>
              <option value="">Select type</option>
              <option value="photo">Photo</option>
              <option value="video">Video</option>
              <option value="document">Document</option>
              <option value="audio">Audio</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="mp-field">
            <label for="evidenceDate">Date <span class="req">*</span></label>
            <input id="evidenceDate" type="date" value="<?php echo htmlspecialchars($today); ?>" required>
          </div>
          <div class="mp-field">
            <label for="relatedActivity">Related activity</label>
            <input id="relatedActivity" type="text" list="activityOptions" placeholder="Activity or plan reference">
            <datalist id="activityOptions"></datalist>
          </div>
          <div class="mp-field">
            <label for="stationSelect">Center</label>
            <select id="stationSelect" data-rdomms-organization data-empty-label="Select center"></select>
          </div>
          <div class="mp-field">
            <label for="departmentSelect">Department</label>
            <select id="departmentSelect" data-rdomms-department data-empty-label="Select department"></select>
          </div>
          <div class="mp-field">
            <label for="evidenceLocation">Location</label>
            <input id="evidenceLocation" type="text" placeholder="Village, site, or venue">
          </div>
          <div class="mp-field">
            <label for="uploadedBy">Uploaded by <span class="req">*</span></label>
            <input id="uploadedBy" type="text" placeholder="Your name" required>
          </div>
          <div class="mp-field mp-field--full">
            <label for="descriptionInput">Description</label>
            <textarea class="mp-input mp-textarea" id="descriptionInput" placeholder="What does this evidence show?"></textarea>
          </div>
          <div class="mp-field mp-field--full">
            <label>Files <span class="req">*</span></label>
            <div class="ue-upload-zone" id="uploadZone">
              <i class="fas fa-cloud-upload-alt"></i>
              <h4>Click or drag files here</h4>
              <p>PDF, JPG, PNG, MP4, DOC, DOCX · Max 1.5 MB per file</p>
              <input type="file" id="fileInput" multiple accept=".pdf,.jpg,.jpeg,.png,.mp4,.doc,.docx,.webp,.gif,.mp3,.wav">
            </div>
            <div class="ue-file-queue" id="fileQueue"></div>
          </div>
        </div>
        <div class="mp-btn-row">
          <button class="mp-btn mp-btn--primary" type="submit" id="saveBtn"><i class="fas fa-save"></i> Save evidence</button>
          <button class="mp-btn mp-btn--ghost" type="button" id="resetFormBtn"><i class="fas fa-rotate-left"></i> Reset</button>
        </div>
      </form>

      <div class="mp-status" id="statusMessage" role="status"></div>
    </div>
  </section>

  <section class="mp-card">
    <div class="mp-list-head">
      <h2><i class="fas fa-folder-open"></i> Evidence register</h2>
      <div class="ue-export-row">
        <select id="exportFormat" title="Export format" aria-label="Export format">
          <option value="pdf" selected>PDF</option>
          <option value="word">Word</option>
          <option value="excel">Excel</option>
        </select>
        <button class="mp-btn mp-btn--ghost" type="button" id="exportBtn"><i class="fas fa-file-export"></i> Export</button>
      </div>
    </div>
    <div class="mp-card-body" style="padding-top:0;">
      <div class="ue-list-toolbar">
        <div class="ue-filters">
          <input type="search" id="searchInput" placeholder="Search title, activity, uploader…" aria-label="Search evidence">
          <select id="filterType" aria-label="Filter by type">
            <option value="">All types</option>
            <option value="photo">Photo</option>
            <option value="video">Video</option>
            <option value="document">Document</option>
            <option value="audio">Audio</option>
            <option value="other">Other</option>
          </select>
          <select id="filterStation" data-rdomms-organization data-empty-label="All centers" aria-label="Filter by center"></select>
          <select id="filterDepartment" data-rdomms-department data-empty-label="All departments" aria-label="Filter by department"></select>
          <button class="mp-btn mp-btn--ghost mp-btn--sm" type="button" id="resetFiltersBtn"><i class="fas fa-filter-circle-xmark"></i> Clear</button>
        </div>
      </div>
      <div class="mp-table-wrap">
        <table class="mp-table">
          <thead>
            <tr>
              <th>Title</th>
              <th>Type</th>
              <th>Activity</th>
              <th>Center</th>
              <th>Date</th>
              <th>Files</th>
              <th style="width:120px;"></th>
            </tr>
          </thead>
          <tbody id="tableBody">
            <tr><td colspan="7" class="mp-plans-empty">Loading…</td></tr>
          </tbody>
        </table>
      </div>
      <p class="ue-cell-sub" id="resultsCount" style="margin:10px 0 0;"></p>
    </div>
  </section>
</div>

<div class="mp-modal" id="viewModal" aria-hidden="true">
  <div class="mp-modal-card" role="dialog" aria-modal="true" aria-labelledby="viewTitle">
    <div class="mp-modal-head">
      <div>
        <h3 id="viewTitle">Evidence details</h3>
        <div class="mp-modal-meta" id="viewMeta"></div>
      </div>
      <button class="mp-btn mp-btn--ghost mp-btn--sm" type="button" id="closeViewBtn">Close</button>
    </div>
    <div class="mp-modal-body">
      <div class="ue-detail-grid" id="viewDetails"></div>
      <h4 style="margin:0 0 10px;font-size:0.86rem;color:#163120;">Attached files</h4>
      <div class="ue-file-preview-list" id="viewFiles"></div>
    </div>
  </div>
</div>

<script src="../assets/js/rdomms-security.js"></script>
<script src="../assets/js/localStorage-service.js"></script>
<script src="../assets/js/rdomms-planning-api.js?v=2"></script>
<script src="../assets/js/rdomms-organizations.js"></script>
<script src="../assets/js/rdomms-departments.js"></script>
<script src="../assets/js/rdomms-export.js"></script>
<script src="../assets/js/rdomms-toast.js"></script>
<script src="../assets/vendor/html2pdf.bundle.min.js"></script>
<script src="../assets/vendor/sheetjs/xlsx.full.min.js"></script>
<script src="../assets/js/upload-evidence.js?v=1"></script>
<script src="../assets/js/rpn-nav-shared.js?v=2"></script>
<script src="../assets/js/planning-nav.js?v=10"></script>
</body>
</html>
