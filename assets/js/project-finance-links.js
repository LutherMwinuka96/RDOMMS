/**
 * Cross-links between Projects and Finance modules (budget, expenses, validation).
 */
(function (global) {
  const TYPE_LABELS = {
    project: 'Project',
    monthly_plan: 'Monthly Plan',
    quarterly_plan: 'Quarterly Plan',
    annual_plan: 'Annual Plan'
  };

  const BUDGET_STATUS_LABELS = {
    draft: 'Draft',
    pending: 'Pending approval',
    approved: 'Budget approved',
    rejected: 'Budget rejected'
  };

  const DEREGISTRATION_STATUS_LABELS = {
    none: '',
    pending: 'Removal pending',
    approved: 'Removed',
    rejected: 'Removal rejected'
  };

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function getBudgetApprovalStatus(project) {
    return String(
      project?.budgetApprovalStatus
      || project?.budget_approval_status
      || (Number(project?.budget) > 0 ? 'pending' : 'draft')
    ).toLowerCase();
  }

  function canOperateProject(project) {
    if (project?.budgetCanOperate === true || project?.budget_can_operate === true) return true;
    if (project?.budgetCanOperate === false || project?.budget_can_operate === false) return false;
    const status = getBudgetApprovalStatus(project);
    if (Number(project?.budget) <= 0) return true;
    return status === 'approved';
  }

  function validateProjectCanOperate(project) {
    const errors = [];
    const warnings = [];
    const status = getBudgetApprovalStatus(project);

    if (Number(project?.budget) > 0 && !canOperateProject(project)) {
      if (status === 'pending') {
        errors.push('This project is waiting for budget approval. You cannot start it or record linked expenses yet.');
      } else if (status === 'rejected') {
        errors.push('This project budget was rejected. Update the budget and resubmit for approval.');
      } else {
        errors.push('Project budget must be approved before you can continue.');
      }
    }

    return { ok: errors.length === 0, warnings, errors, status };
  }

  function canUserApproveBudget() {
    try {
      const rbacRole = window.RDOMMSRBAC?.getCurrentRole?.();
      if (rbacRole) {
        return ['admin', 'accountant', 'head_of_organization_center', 'head_of_department'].includes(rbacRole);
      }
      const role = String(JSON.parse(localStorage.getItem('currentUser') || '{}').role || '').toLowerCase();
      return ['admin', 'accountant', 'head_of_organization_center', 'head_of_department'].includes(role);
    } catch (_) {
      return false;
    }
  }

  function renderBudgetApprovalBadge(project) {
    const status = getBudgetApprovalStatus(project);
    if (Number(project?.budget) <= 0 && status === 'draft') return '';
    const cls = status === 'approved' ? 'approved' : (status === 'rejected' ? 'rejected' : (status === 'pending' ? 'pending' : 'draft'));
    const label = BUDGET_STATUS_LABELS[status] || status;
    return `<span class="pf-budget-badge pf-budget-badge--${cls}"><i class="fas fa-wallet"></i> ${escapeHtml(label)}</span>`;
  }

  async function approveProjectBudget(projectId) {
    const res = await fetch(`../api/projects.php?action=approve-budget`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({ id: projectId })
    });
    const payload = await res.json().catch(() => null);
    if (!res.ok || payload?.status !== 'success') {
      throw new Error(payload?.message || 'Unable to approve project budget.');
    }
    return payload;
  }

  async function rejectProjectBudget(projectId, reason) {
    const res = await fetch(`../api/projects.php?action=reject-budget`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({ id: projectId, reject_reason: reason })
    });
    const payload = await res.json().catch(() => null);
    if (!res.ok || payload?.status !== 'success') {
      throw new Error(payload?.message || 'Unable to reject project budget.');
    }
    return payload;
  }

  async function resubmitProjectBudget(projectId, budget) {
    const res = await fetch(`../api/projects.php?action=resubmit-budget`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({ id: projectId, budget })
    });
    const payload = await res.json().catch(() => null);
    if (!res.ok || payload?.status !== 'success') {
      throw new Error(payload?.message || 'Unable to resubmit project budget.');
    }
    return payload;
  }

  function getDeregistrationStatus(project) {
    return String(
      project?.deregistrationStatus
      || project?.deregistration_status
      || 'none'
    ).toLowerCase();
  }

  function canUserApproveDeregistration() {
    return canUserApproveBudget();
  }

  function isAdminUser() {
    try {
      const role = String(JSON.parse(localStorage.getItem('currentUser') || '{}').role || '').toLowerCase();
      return role === 'admin';
    } catch (_) {
      return false;
    }
  }

  function renderDeregistrationBadge(project) {
    const status = getDeregistrationStatus(project);
    if (!status || status === 'none') return '';
    const cls = status === 'pending' ? 'pending' : (status === 'rejected' ? 'rejected' : 'approved');
    const label = DEREGISTRATION_STATUS_LABELS[status] || status;
    return `<span class="pf-budget-badge pf-budget-badge--${cls}"><i class="fas fa-user-slash"></i> ${escapeHtml(label)}</span>`;
  }

  async function fetchDeregistrationPreview(projectId) {
    const res = await fetch(`../api/projects.php?action=deregistration-preview&id=${encodeURIComponent(projectId)}`, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await res.json().catch(() => null);
    if (!res.ok || payload?.status !== 'success') {
      throw new Error(payload?.message || 'Unable to load removal preview.');
    }
    return payload.data;
  }

  async function requestProjectDeregistration(projectId, reason) {
    const res = await fetch('../api/projects.php?action=request-deregistration', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({ id: projectId, reason })
    });
    const payload = await res.json().catch(() => null);
    if (!res.ok || payload?.status !== 'success') {
      throw new Error(payload?.message || 'Unable to request project removal.');
    }
    return payload;
  }

  async function cancelProjectDeregistration(projectId) {
    const res = await fetch('../api/projects.php?action=cancel-deregistration', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({ id: projectId })
    });
    const payload = await res.json().catch(() => null);
    if (!res.ok || payload?.status !== 'success') {
      throw new Error(payload?.message || 'Unable to cancel removal request.');
    }
    return payload;
  }

  async function approveProjectDeregistration(projectId) {
    const res = await fetch('../api/projects.php?action=approve-deregistration', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({ id: projectId })
    });
    const payload = await res.json().catch(() => null);
    if (!res.ok || payload?.status !== 'success') {
      throw new Error(payload?.message || 'Unable to approve project removal.');
    }
    return payload;
  }

  async function rejectProjectDeregistration(projectId, reason) {
    const res = await fetch('../api/projects.php?action=reject-deregistration', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({ id: projectId, reject_reason: reason })
    });
    const payload = await res.json().catch(() => null);
    if (!res.ok || payload?.status !== 'success') {
      throw new Error(payload?.message || 'Unable to reject project removal.');
    }
    return payload;
  }

  async function adminDeregisterProject(projectId, reason) {
    const res = await fetch('../api/projects.php?action=deregister', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({ id: projectId, reason })
    });
    const payload = await res.json().catch(() => null);
    if (!res.ok || payload?.status !== 'success') {
      throw new Error(payload?.message || 'Unable to remove project.');
    }
    return payload;
  }

  function buildDeregistrationConfirmMessage(preview) {
    const linked = preview?.linked_records || {};
    return [
      'This will permanently remove:',
      `• Project: ${preview?.project?.name || preview?.project?.id || 'selected project'}`,
      `• Budget allocation(s): ${linked.allocations || 0}`,
      `• Expense record(s): ${linked.expenses || 0}`,
      `• Progress update(s): ${linked.progress_updates || 0}`,
      `• Document(s): ${linked.documents || 0}`,
      '',
      'This action cannot be undone after approval.'
    ].join('\n');
  }

  async function promptProjectDeregistration(projectId) {
    let preview;
    try {
      preview = await fetchDeregistrationPreview(projectId);
    } catch (error) {
      alert(error.message || 'Unable to prepare removal request.');
      return false;
    }

    if (!confirm(buildDeregistrationConfirmMessage(preview) + '\n\nContinue with removal request?')) {
      return false;
    }

    const reason = prompt('Enter reason for removing this project and its budget:');
    if (!reason || !reason.trim()) {
      alert('A reason is required.');
      return false;
    }

    if (isAdminUser() && confirm('Remove immediately as administrator (no approval wait)?')) {
      await adminDeregisterProject(projectId, reason.trim());
      alert('Project and linked records were removed.');
      return true;
    }

    await requestProjectDeregistration(projectId, reason.trim());
    alert('Removal request submitted for approval.');
    return true;
  }

  function parseProjectLinkFromUrl(search) {
    const params = new URLSearchParams(search || global.location?.search || '');
    const projectId = params.get('project_id') || params.get('linked_id') || '';
    const projectName = params.get('project_name') || params.get('linked_label') || '';
    const linkedType = params.get('linked_type') || (projectId ? 'project' : '');
    const linkedId = params.get('linked_id') || projectId;
    const linkedLabel = params.get('linked_label') || projectName;
    const requireLink = params.get('require_link') === '1';

    if (!linkedType || !linkedId) {
      return null;
    }

    return {
      linked_type: linkedType,
      linked_id: linkedId,
      linked_label: linkedLabel,
      require_link: requireLink
    };
  }

  function appendQueryParams(baseHref, params) {
    const qs = params.toString();
    return qs ? `${baseHref}?${qs}` : baseHref;
  }

  function joinPath(basePath, page) {
    const base = String(basePath || '../finance').replace(/\/+$/, '');
    const file = String(page || '').replace(/^\//, '');
    if (!base || base === '.') return file;
    return `${base}/${file}`;
  }

  function buildFinanceUrl(page, options = {}) {
    const {
      linkedType = 'project',
      linkedId = '',
      linkedLabel = '',
      requireLink = false,
      basePath = '../finance'
    } = options;

    const href = joinPath(basePath, page);
    const params = new URLSearchParams();

    if (linkedId) {
      params.set('linked_type', linkedType);
      params.set('linked_id', String(linkedId));
      if (linkedLabel) params.set('linked_label', linkedLabel);
      if (linkedType === 'project') {
        params.set('project_id', String(linkedId));
        if (linkedLabel) params.set('project_name', linkedLabel);
      }
      if (requireLink) params.set('require_link', '1');
    }

    return appendQueryParams(href, params);
  }

  function buildBudgetAllocationUrl(projectId, projectName, basePath) {
    return buildFinanceUrl('budget-allocation.php', {
      linkedType: 'project',
      linkedId: projectId,
      linkedLabel: projectName,
      requireLink: true,
      basePath
    });
  }

  function buildExpenseTrackingUrl(projectId, projectName, basePath) {
    return buildFinanceUrl('expense-tracking.php', {
      linkedType: 'project',
      linkedId: projectId,
      linkedLabel: projectName,
      requireLink: true,
      basePath
    });
  }

  function buildProjectHealthUrl(projectId, basePath) {
    const href = joinPath(basePath || '../projects', 'project-health.php');
    if (!projectId) return href;
    const params = new URLSearchParams();
    params.set('project_id', String(projectId));
    return appendQueryParams(href, params);
  }

  function applyDeepLinkToFunding(fundingLinks, options = {}) {
    if (!fundingLinks || typeof fundingLinks.setValues !== 'function') return null;
    const link = parseProjectLinkFromUrl();
    if (!link) return null;

    fundingLinks.setValues(link.linked_type, link.linked_id, link.linked_label);
    if (options.scrollToForm !== false) {
      const form = document.querySelector(options.formSelector || 'form');
      form?.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
    }
    return link;
  }

  function renderProjectContextBanner(link, options = {}) {
    if (!link?.linked_id) return;
    const mountId = options.mountId || 'projectFinanceContextBanner';
    let mount = document.getElementById(mountId);
    if (!mount) {
      mount = document.createElement('div');
      mount.id = mountId;
      mount.className = 'project-finance-context-banner';
      const anchor = document.querySelector(options.insertBefore || '.page-header, .hero, .module-grid, .filter-card, main, .page');
      if (anchor?.parentNode) {
        anchor.parentNode.insertBefore(mount, anchor);
      } else {
        document.body.prepend(mount);
      }
    }

    const label = link.linked_label || link.linked_id;
    const typeLabel = TYPE_LABELS[link.linked_type] || link.linked_type;
    const healthHref = buildProjectHealthUrl(
      link.linked_type === 'project' ? link.linked_id : '',
      options.projectsBase || '../projects'
    );
    const budgetStatus = options.budgetApprovalStatus || '';
    const budgetNote = budgetStatus === 'pending'
      ? '<div class="pf-budget-note">Budget approval is pending. Expenses cannot be saved until approval.</div>'
      : (budgetStatus === 'rejected'
        ? '<div class="pf-budget-note pf-budget-note--danger">Budget was rejected. Resubmit from the project page before recording expenses.</div>'
        : '');

    mount.innerHTML = `
      <div class="project-finance-context-inner">
        <div>
          <strong><i class="fas fa-link" aria-hidden="true"></i> ${escapeHtml(typeLabel)} context</strong>
          <span>${escapeHtml(label)}</span>
          ${budgetNote}
        </div>
        <div class="project-finance-context-actions">
          ${link.linked_type === 'project'
            ? `<a href="${escapeHtml(healthHref)}" class="pfcb-btn"><i class="fas fa-heart-pulse"></i> Project health</a>`
            : ''}
          <a href="${escapeHtml(buildProjectHealthUrl('', options.projectsBase || '../projects'))}" class="pfcb-btn pfcb-btn--ghost"><i class="fas fa-folder-tree"></i> All projects</a>
        </div>
      </div>
    `;
  }

  function injectContextBannerStyles() {
    if (document.getElementById('project-finance-context-styles')) return;
    const style = document.createElement('style');
    style.id = 'project-finance-context-styles';
    style.textContent = `
      .project-finance-context-banner { margin: 0 0 1rem; }
      .project-finance-context-inner {
        display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
        padding: 0.85rem 1rem; border-radius: 12px; border: 1.5px solid #c7e3c9;
        background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
        color: #14532d; font-size: 0.875rem;
      }
      .project-finance-context-inner strong { display: block; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.15rem; }
      .project-finance-context-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
      .pfcb-btn {
        display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.4rem 0.75rem;
        border-radius: 999px; background: #2d5016; color: #fff; font-size: 0.75rem; font-weight: 700; text-decoration: none;
      }
      .pfcb-btn--ghost { background: #fff; color: #2d5016; border: 1px solid #86efac; }
      .pf-budget-badge {
        display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.2rem 0.55rem;
        border-radius: 999px; font-size: 0.68rem; font-weight: 800; margin-top: 0.35rem;
      }
      .pf-budget-badge--pending { background: #fef3c7; color: #92400e; }
      .pf-budget-badge--approved { background: #dcfce7; color: #166534; }
      .pf-budget-badge--rejected { background: #fee2e2; color: #991b1b; }
      .pf-budget-badge--draft { background: #f1f5f9; color: #475569; }
      .pf-budget-note { margin-top: 0.35rem; font-size: 0.78rem; color: #92400e; }
      .pf-budget-note--danger { color: #991b1b; }
      .pf-approve-btn, .pf-reject-btn {
        border: none; border-radius: 999px; padding: 0.35rem 0.7rem; font-size: 0.72rem; font-weight: 700; cursor: pointer;
      }
      .pf-approve-btn { background: #166534; color: #fff; }
      .pf-reject-btn { background: #fff; color: #991b1b; border: 1px solid #fecaca; }
    `;
    document.head.appendChild(style);
  }

  function validateLinkedFinancePayload(payload, linkContext) {
    const warnings = [];
    const errors = [];

    if (!linkContext?.require_link) {
      return { ok: true, warnings, errors };
    }

    if (!payload?.linked_type || !payload?.linked_id) {
      errors.push('Select a project or plan to link this finance entry.');
      return { ok: false, warnings, errors };
    }

    if (payload.linked_type === 'project') {
      const budgetStatus = String(payload.budget_approval_status || payload.budgetApprovalStatus || '').toLowerCase();
      const canOperate = payload.budget_can_operate ?? payload.budgetCanOperate;
      if (canOperate === false || budgetStatus === 'pending' || budgetStatus === 'rejected') {
        errors.push(
          budgetStatus === 'rejected'
            ? 'This project budget was rejected. Resubmit it for approval before recording expenses.'
            : 'This project is waiting for budget approval. Expenses cannot be recorded yet.'
        );
        return { ok: false, warnings, errors };
      }
    }

    if (payload.linked_type === 'project' && Number(payload.amount) > 0 && Number(payload.project_budget) > 0) {
      const spent = Number(payload.project_spent || 0);
      const budget = Number(payload.project_budget);
      const nextTotal = spent + Number(payload.amount);
      if (nextTotal > budget) {
        warnings.push(`This amount exceeds the project budget remaining (${formatMoney(budget - spent)}).`);
      }
    }

    return { ok: errors.length === 0, warnings, errors };
  }

  function validateProgressUpdate(project, nextProgress) {
    const errors = [];
    const warnings = [];
    const progress = Number(nextProgress);

    if (!Number.isFinite(progress) || progress < 0 || progress > 100) {
      errors.push('Progress must be between 0 and 100.');
      return { ok: false, warnings, errors };
    }

    const operateCheck = validateProjectCanOperate(project);
    if (!operateCheck.ok && progress > Number(project?.progress || 0)) {
      errors.push(...operateCheck.errors);
      return { ok: false, warnings, errors };
    }

    if (progress > 0 && !canOperateProject(project) && Number(project?.progress || 0) === 0) {
      errors.push('Start the project only after budget approval.');
      return { ok: false, warnings, errors };
    }

    if (progress >= 50 && Number(project?.budget) > 0 && Number(project?.linkedExpenseCount || 0) === 0) {
      warnings.push('Project is past 50% progress but has no linked expenses. Record spending in Expense Tracking.');
    }

    if (progress >= 100 && project?.status !== 'completed') {
      warnings.push('Marking 100% will set status to completed.');
    }

    const endDate = project?.endDate || project?.dueDate || project?.end_date;
    if (endDate && progress < 100) {
      const due = new Date(endDate);
      if (!Number.isNaN(due.getTime()) && due < new Date()) {
        warnings.push('Project is past its due date. Consider updating status to delayed.');
      }
    }

    return { ok: errors.length === 0, warnings, errors };
  }

  function formatMoney(value) {
    if (typeof global.formatRdommsCurrency === 'function') {
      return global.formatRdommsCurrency(value || 0);
    }
    return `TSH ${Number(value || 0).toLocaleString('en-US')}`;
  }

  function getVisibleFinanceNavKeys() {
    try {
      const role = String(JSON.parse(localStorage.getItem('currentUser') || '{}').role || '').toLowerCase();
      if (role === 'accountant') {
        return ['budget-allocation', 'expense-tracking', 'financial-reports', 'variance-analysis'];
      }
      if (role === 'admin' || role === 'head_of_organization_center') {
        return ['financial-reports', 'variance-analysis', 'expense-tracking'];
      }
      return ['financial-reports'];
    } catch (_) {
      return ['financial-reports'];
    }
  }

  global.RDOMMSProjectFinance = {
    TYPE_LABELS,
    BUDGET_STATUS_LABELS,
    DEREGISTRATION_STATUS_LABELS,
    parseProjectLinkFromUrl,
    buildFinanceUrl,
    buildBudgetAllocationUrl,
    buildExpenseTrackingUrl,
    buildProjectHealthUrl,
    applyDeepLinkToFunding,
    renderProjectContextBanner,
    injectContextBannerStyles,
    validateLinkedFinancePayload,
    validateProgressUpdate,
    validateProjectCanOperate,
    getBudgetApprovalStatus,
    getDeregistrationStatus,
    canOperateProject,
    canUserApproveBudget,
    canUserApproveDeregistration,
    isAdminUser,
    renderBudgetApprovalBadge,
    renderDeregistrationBadge,
    approveProjectBudget,
    rejectProjectBudget,
    resubmitProjectBudget,
    fetchDeregistrationPreview,
    requestProjectDeregistration,
    cancelProjectDeregistration,
    approveProjectDeregistration,
    rejectProjectDeregistration,
    adminDeregisterProject,
    promptProjectDeregistration,
    getVisibleFinanceNavKeys
  };
})(window);
