<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/planning-access.php';
rdomms_require_planning_module_page();
require_once __DIR__ . '/../config/dates.php';

$currentYear = rdomms_current_year();
$years = rdomms_year_range();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>All Plans | RDO|MMS</title>
<meta name="description" content="Review submitted monthly, quarterly, and annual plans and take approval actions in RDO|MMS.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="../assets/css/rdomms-icons-offline.css">
<link rel="stylesheet" href="../assets/css/rdomms-session-countdown.css?v=4">
<link rel="stylesheet" href="../assets/css/project-nav.css?v=5">
<link rel="stylesheet" href="../assets/css/planning-pages.css">
<link rel="stylesheet" href="../assets/css/monthly-plans-workspace.css?v=1">
<link rel="stylesheet" href="../assets/css/all-plans-workspace.css?v=1">
<link rel="stylesheet" href="../assets/css/rdomms-toast.css">
</head>
<body class="planning-page ap-all-plans">

<nav id="rdomms-planning-nav" data-active="all-plans"></nav>

<div class="mp-shell">
  <header class="mp-head">
    <div>
      <h1>Planning Register</h1>
      <p>Review submitted monthly, quarterly, and annual plans for <?php echo (int) $currentYear; ?>. Approve, reject, or inspect details.</p>
    </div>
    <div class="mp-stats" aria-live="polite">
      <span class="mp-stat"><strong id="countAll">0</strong> total plans</span>
      <span class="mp-stat"><strong id="statPending">0</strong> pending review</span>
    </div>
  </header>

  <div class="ap-note" role="note">
    <i class="fas fa-circle-info" aria-hidden="true"></i>
    <div>
      <strong>Reviewer workspace</strong>
      Use filters to narrow plans by type, status, year, department, or center. Click a summary card for quick status filtering, or switch to table view for a compact register.
    </div>
  </div>

  <div class="ap-kpi-grid" aria-label="Plan status summary">
    <button type="button" class="ap-kpi is-active" data-kpi-status="">
      <div class="k">All plans</div>
      <div class="v" id="countAllKpi">0</div>
      <div class="s">submitted records</div>
    </button>
    <button type="button" class="ap-kpi ap-kpi--pending" data-kpi-status="pending">
      <div class="k">Pending</div>
      <div class="v" id="countPending">0</div>
      <div class="s">awaiting review</div>
    </button>
    <button type="button" class="ap-kpi ap-kpi--approved" data-kpi-status="approved">
      <div class="k">Approved</div>
      <div class="v" id="countApproved">0</div>
      <div class="s">accepted plans</div>
    </button>
    <button type="button" class="ap-kpi ap-kpi--rejected" data-kpi-status="rejected">
      <div class="k">Rejected</div>
      <div class="v" id="countRejected">0</div>
      <div class="s">returned plans</div>
    </button>
  </div>

  <section class="mp-card">
    <div class="mp-list-head">
      <h2><i class="fas fa-sliders"></i> Filters &amp; tools</h2>
      <div class="ap-view-toggle" role="group" aria-label="View mode">
        <button type="button" class="ap-view-btn is-active" data-view-mode="cards"><i class="fas fa-grip"></i> Cards</button>
        <button type="button" class="ap-view-btn" data-view-mode="table"><i class="fas fa-table"></i> Table</button>
      </div>
    </div>
    <div class="mp-card-body">
      <div class="ap-status-pills" role="group" aria-label="Quick status filter">
        <button type="button" class="ap-pill is-active" data-status-pill="">All</button>
        <button type="button" class="ap-pill" data-status-pill="pending">Pending</button>
        <button type="button" class="ap-pill" data-status-pill="approved">Approved</button>
        <button type="button" class="ap-pill" data-status-pill="rejected">Rejected</button>
      </div>

      <div class="ap-filters">
        <input id="searchInput" type="search" placeholder="Search staff, department, center, period…" aria-label="Search plans">
        <select id="filterType" aria-label="Filter by type">
          <option value="">All types</option>
          <option value="monthly">Monthly</option>
          <option value="quarterly">Quarterly</option>
          <option value="annual">Annual</option>
        </select>
        <select id="filterStatus" aria-label="Filter by status">
          <option value="">All statuses</option>
          <option value="pending">Pending</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
        </select>
        <select id="filterYear" aria-label="Filter by year">
          <option value="">All years</option>
          <?php foreach ($years as $year): ?>
            <option value="<?php echo (int) $year; ?>" <?php echo ((int) $year === $currentYear) ? 'selected' : ''; ?>>
              <?php echo (int) $year; ?>
            </option>
          <?php endforeach; ?>
        </select>
        <select id="filterDept" data-rdomms-department data-empty-label="All departments" aria-label="Filter by department"></select>
        <select id="filterStation" data-rdomms-organization data-empty-label="All centers" aria-label="Filter by center"></select>
        <select id="sortBy" aria-label="Sort plans">
          <option value="newest">Newest first</option>
          <option value="oldest">Oldest first</option>
          <option value="staff-asc">Staff A–Z</option>
          <option value="staff-desc">Staff Z–A</option>
          <option value="type">Plan type</option>
        </select>
      </div>

      <div class="ap-toolbar">
        <div class="mp-btn-row">
          <button class="mp-btn mp-btn--ghost" type="button" id="resetFiltersBtn"><i class="fas fa-rotate-left"></i> Reset</button>
          <button class="mp-btn mp-btn--ghost" type="button" id="refreshBtn"><i class="fas fa-sync-alt"></i> Refresh</button>
        </div>
        <div class="mp-btn-row">
          <select id="exportFormat" class="ap-export-select" title="Export format" aria-label="Export format">
            <option value="pdf" selected>PDF</option>
            <option value="word">Word</option>
            <option value="excel">Excel</option>
          </select>
          <button class="mp-btn mp-btn--primary" type="button" id="exportBtn"><i class="fas fa-file-export"></i> Export register</button>
        </div>
      </div>

      <div class="ap-active-filters" id="activeFiltersPreview" aria-live="polite">No filters applied — showing all submitted plans.</div>
      <p class="ap-results-count" id="resultsCount"></p>
    </div>
  </section>

  <section class="mp-card">
    <div class="mp-list-head">
      <h2><i class="fas fa-layer-group"></i> Submitted plans</h2>
    </div>
    <div class="mp-card-body" id="plansWorkspace" style="padding-top:0;">
      <div id="planList" class="ap-plan-list" aria-live="polite"></div>
      <div class="ap-table-wrap mp-table-wrap" id="planTableWrap" hidden>
        <table class="mp-table">
          <thead>
            <tr>
              <th>Plan</th>
              <th>Type</th>
              <th>Period</th>
              <th>Department</th>
              <th>Center</th>
              <th>Status</th>
              <th>Submitted</th>
              <th style="width:220px;">Actions</th>
            </tr>
          </thead>
          <tbody id="planTableBody"></tbody>
        </table>
      </div>
      <div id="emptyState" class="mp-plans-empty" style="display:none;">
        <i class="fas fa-inbox" style="font-size:1.4rem;margin-bottom:8px;display:block;color:#58a46a;"></i>
        No submitted plans match your filters.
      </div>
    </div>
  </section>
</div>

<div class="mp-modal" id="viewModal" aria-hidden="true">
  <div class="mp-modal-card" role="dialog" aria-modal="true">
    <div class="mp-modal-head">
      <div>
        <h3 id="viewTitle">Plan details</h3>
        <div class="mp-modal-meta" id="viewMeta"></div>
      </div>
      <button class="mp-btn mp-btn--ghost" type="button" id="closeViewBtn">Close</button>
    </div>
    <div class="mp-modal-body">
      <div class="mp-table-wrap">
        <table class="mp-table" aria-label="Plan activities table">
          <thead id="viewTableHead">
            <tr>
              <th style="width:120px">Period</th>
              <th>Details</th>
              <th style="width:180px">Resources</th>
              <th style="width:180px">Responsible</th>
              <th style="width:120px">Budget</th>
            </tr>
          </thead>
          <tbody id="viewBody"></tbody>
        </table>
      </div>
      <div class="ap-total-row">
        <span>Total budget:</span>
        <span id="viewTotal">0</span>
      </div>
    </div>
  </div>
</div>

<div class="mp-modal" id="rejectModal" aria-hidden="true">
  <div class="mp-modal-card" role="dialog" aria-modal="true" style="max-width:560px;">
    <div class="mp-modal-head">
      <div>
        <h3>Reject plan</h3>
        <div class="mp-modal-meta">Provide a reason for rejection.</div>
      </div>
      <button class="mp-btn mp-btn--ghost" type="button" id="closeRejectBtn">Close</button>
    </div>
    <div class="mp-modal-body">
      <div class="ap-field">
        <textarea id="rejectReason" placeholder="Enter rejection reason…"></textarea>
      </div>
      <div class="ap-modal-actions">
        <button class="mp-btn mp-btn--ghost" type="button" id="cancelRejectBtn">Cancel</button>
        <button class="mp-btn mp-btn--danger" type="button" id="confirmRejectBtn">Reject plan</button>
      </div>
    </div>
  </div>
</div>

<div class="ap-loading" id="loadingOverlay" aria-hidden="true">
  <div class="ap-loading-card"><i class="fas fa-spinner fa-spin"></i> Loading plans…</div>
</div>

<script src="../assets/js/rdomms-security.js"></script>
<script src="../assets/js/localStorage-service.js?v=2"></script>
<script src="../assets/js/planning-workspace-tools.js"></script>
<script src="../assets/js/rdomms-planning-api.js?v=2"></script>
<script src="../assets/js/rdomms-departments.js"></script>
<script src="../assets/js/rdomms-organizations.js"></script>
<script src="../assets/js/rdomms-toast.js"></script>
<script src="../assets/vendor/html2pdf.bundle.min.js"></script>
<script src="../assets/vendor/sheetjs/xlsx.full.min.js"></script>
<script src="../assets/js/rdomms-export.js"></script>
<script src="../assets/js/all-plans-register.js?v=1"></script>
<script src="../assets/js/rpn-nav-shared.js?v=2"></script>
<script src="../assets/js/planning-nav.js?v=12"></script>
</body>
</html>
