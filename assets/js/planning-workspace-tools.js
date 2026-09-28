/**
 * Shared helpers for monthly / quarterly / annual planning workspaces.
 */
(function (global) {
  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function getCurrentUser() {
    if (global.RDOMMSRBAC && typeof RDOMMSRBAC.getCurrentUser === 'function') {
      return RDOMMSRBAC.getCurrentUser() || {};
    }
    try {
      return JSON.parse(localStorage.getItem('currentUser') || '{}');
    } catch (error) {
      return {};
    }
  }

  function getCurrentUserId() {
    const user = getCurrentUser();
    return Number(user.id || user.user_id || 0) || null;
  }

  function getApprovalStatus(plan) {
    return String(plan?.approval_status || plan?.approvalStatus || plan?.status || 'draft').toLowerCase();
  }

  function isSubmittedStatus(status) {
    return ['pending', 'approved', 'rejected'].includes(String(status || '').toLowerCase());
  }

  function isSubmittedPlan(plan) {
    const status = getApprovalStatus(plan);
    if (status === 'draft') return false;
    if (isSubmittedStatus(status)) return true;
    return !!(plan?.submitted_at || plan?.submittedAt);
  }

  function planBelongsToUser(plan, userId) {
    if (!userId) return true;
    const owner = Number(plan?.created_by || plan?.createdBy || 0);
    if (!owner) return true;
    return owner === userId;
  }

  function canEditPlan(plan) {
    const status = getApprovalStatus(plan);
    return status === 'draft' || status === 'pending' || status === 'rejected';
  }

  function canAmendPlan(plan, userId) {
    if (getApprovalStatus(plan) !== 'approved') return false;
    return planBelongsToUser(plan, userId);
  }

  function canOwnerModifyPlan(plan, userId) {
    if (!planBelongsToUser(plan, userId)) return false;
    return canEditPlan(plan) || canAmendPlan(plan, userId);
  }

  function canOwnerDeletePlan(plan, userId) {
    return planBelongsToUser(plan, userId);
  }

  function formatMoney(value) {
    return Number(value || 0).toLocaleString('en-US');
  }

  function departmentLabel(value) {
    if (global.RDOMMSDepartments) {
      return RDOMMSDepartments.getLabel(value) || value || '—';
    }
    return value || '—';
  }

  function timeAgo(iso) {
    if (!iso) return '—';
    const diff = Date.now() - new Date(iso).getTime();
    const minutes = Math.floor(diff / 60000);
    if (minutes < 1) return 'Just now';
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    return `${Math.floor(hours / 24)}d ago`;
  }

  function statusBadge(status) {
    const normalized = String(status || 'draft').toLowerCase();
    return `<span class="plan-status-badge ${escapeHtml(normalized)}">${escapeHtml(normalized)}</span>`;
  }

  function buildPeriodPreviewRows(plan) {
    const rows = Array.isArray(plan?.activities) ? plan.activities : [];
    return rows.map((row) => ({
      period: row.month || '—',
      detail: row.activities || '',
      resources: row.equipment || '',
      responsible: row.participants || '',
      budget: Number(row.budget || 0) || 0
    }));
  }

  function buildMonthlyPreviewRows(plan) {
    const rows = Array.isArray(plan?.activities) ? plan.activities : [];
    return rows.map((row, index) => ({
      period: row.week || `Week ${index + 1}`,
      detail: row.activity || row.activities || '',
      resources: row.output || row.resources || '',
      responsible: row.responsible || row.participants || '',
      budget: Number(row.budget || 0) || 0
    }));
  }

  function openPreviewModal(options) {
    const {
      modalId = 'previewModal',
      title,
      meta,
      headers,
      rows,
      totalBudget,
      confirmLabel = '',
      onConfirm = null
    } = options;

    const modal = document.getElementById(modalId);
    if (!modal) return;

    const titleEl = document.getElementById(`${modalId}Title`);
    const metaEl = document.getElementById(`${modalId}Meta`);
    const headEl = document.getElementById(`${modalId}Head`);
    const bodyEl = document.getElementById(`${modalId}Body`);
    const totalEl = document.getElementById(`${modalId}Total`);
    const confirmBtn = document.getElementById(`${modalId}ConfirmBtn`);

    if (titleEl) titleEl.textContent = title || 'Plan preview';
    if (metaEl) metaEl.textContent = meta || '';

    if (headEl && Array.isArray(headers)) {
      headEl.innerHTML = `<tr>${headers.map((h) => `<th>${escapeHtml(h)}</th>`).join('')}</tr>`;
    }

    let total = 0;
    const formatCell = (value) => {
      if (!value) return '<em>—</em>';
      return escapeHtml(String(value)).replace(/\n/g, '<br>');
    };

    if (bodyEl) {
      bodyEl.innerHTML = (rows || []).map((row) => {
        total += Number(row.budget || 0) || 0;
        return `
          <tr>
            <td>${formatCell(row.period)}</td>
            <td>${formatCell(row.detail)}</td>
            <td>${formatCell(row.resources)}</td>
            <td>${formatCell(row.responsible)}</td>
            <td>${formatMoney(row.budget)}</td>
          </tr>
        `;
      }).join('') || '<tr><td colspan="5"><em>No activity rows yet.</em></td></tr>';
    }

    if (totalEl) {
      totalEl.textContent = formatMoney(totalBudget != null ? totalBudget : total);
    }

    if (confirmBtn) {
      if (onConfirm && confirmLabel) {
        confirmBtn.style.display = '';
        confirmBtn.textContent = confirmLabel;
        confirmBtn.onclick = () => {
          onConfirm();
        };
      } else {
        confirmBtn.style.display = 'none';
        confirmBtn.onclick = null;
      }
    }

    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
  }

  function closePreviewModal(modalId = 'previewModal') {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
  }

  function bindPreviewModal(modalId = 'previewModal') {
    const modal = document.getElementById(modalId);
    if (!modal || modal.dataset.bound === '1') return;
    modal.dataset.bound = '1';
    document.getElementById(`${modalId}CloseBtn`)?.addEventListener('click', () => closePreviewModal(modalId));
    document.getElementById(`${modalId}CloseBtn2`)?.addEventListener('click', () => closePreviewModal(modalId));
    modal.addEventListener('click', (event) => {
      if (event.target === modal) closePreviewModal(modalId);
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') closePreviewModal(modalId);
    });
  }

  function getEditPageUrl(planType, plan) {
    const year = encodeURIComponent(plan.year || '');
    if (planType === 'quarterly') {
      return `quarterly-plans.php?year=${year}&quarter=${encodeURIComponent(plan.quarter || '')}&edit=1`;
    }
    if (planType === 'monthly') {
      return `monthly-plans.php?year=${year}&month=${encodeURIComponent(plan.month || '')}&edit=1`;
    }
    return `annual-plans.php?year=${year}&edit=1`;
  }

  function getRegisterUrl(planType) {
    const type = encodeURIComponent(planType || '');
    return `all-plans.php?filterType=${type}`;
  }

  function renderSubmittedPlansPanel(options) {
    const {
      containerId,
      plans,
      planType,
      emptyMessage = 'No submitted plans yet.',
      onView,
      onEdit,
      onExport,
      onDuplicate,
      onDelete,
      onEvidence,
      showRegister = true,
      variant = 'default'
    } = options;

    const host = document.getElementById(containerId);
    if (!host) return;

    const userId = getCurrentUserId();
    const list = (plans || []).filter(isSubmittedPlan);
    const isMp = variant === 'mp';
    const rowClass = isMp ? 'mp-plan-item' : 'my-plan-row';
    const mainClass = isMp ? '' : 'my-plan-main';
    const titleClass = isMp ? 'mp-plan-title' : 'my-plan-title';
    const metaClass = isMp ? 'mp-plan-meta' : 'my-plan-meta';
    const actionsClass = isMp ? 'mp-plan-actions' : 'my-plan-actions';
    const emptyClass = isMp ? 'mp-plans-empty' : 'my-plans-empty';
    const btnClass = isMp ? 'mp-btn mp-btn--ghost' : 'mini-btn ghost';
    const btnPrimaryClass = isMp ? 'mp-btn mp-btn--ghost' : 'mini-btn secondary';
    const btnDangerClass = isMp ? 'mp-btn mp-btn--ghost mp-btn--danger' : 'mini-btn danger';

    if (!list.length) {
      host.innerHTML = `<div class="${emptyClass}">${escapeHtml(emptyMessage)}</div>`;
      return;
    }

    host.innerHTML = list.map((plan) => {
      const status = getApprovalStatus(plan);
      const period = planType === 'monthly'
        ? `${plan.month || ''} ${plan.year || ''}`.trim()
        : planType === 'quarterly'
          ? `${plan.quarter || ''} ${plan.year || ''}`.trim()
          : String(plan.year || '');
      const budget = formatMoney(plan.total_budget || plan.totalBudget || 0);
      const submitted = timeAgo(plan.submitted_at || plan.submittedAt);
      const editable = canEditPlan(plan);
      const amendable = canAmendPlan(plan, userId);
      const modifiable = editable || amendable;
      const deletable = canOwnerDeletePlan(plan, userId);
      const planId = escapeHtml(String(plan.id || plan.plan_id || ''));
      const rejectNote = plan.reject_reason || plan.rejectReason || '';
      const metaParts = [
        departmentLabel(plan.department),
        plan.station ? String(plan.station).replace(/^rdo_/i, 'RDO ').replace(/_/g, ' ') : '',
        `TSH ${budget}`,
        submitted !== '—' ? submitted : ''
      ].filter(Boolean);

      const editBtn = modifiable
        ? `<button type="button" class="${btnPrimaryClass}" data-my-action="edit" data-plan-id="${planId}"><i class="fas fa-pen"></i> ${amendable && !editable ? 'Amend' : 'Edit'}</button>`
        : '';

      const exportBtn = onExport
        ? `<button type="button" class="${btnClass}" data-my-action="export" data-plan-id="${planId}"><i class="fas fa-file-export"></i> Export</button>`
        : '';

      const duplicateBtn = onDuplicate
        ? `<button type="button" class="${btnClass}" data-my-action="duplicate" data-plan-id="${planId}"><i class="fas fa-copy"></i> Duplicate</button>`
        : '';

      const evidenceBtn = onEvidence
        ? `<button type="button" class="${btnClass}" data-my-action="evidence" data-plan-id="${planId}"><i class="fas fa-paperclip"></i> Evidence</button>`
        : '';

      const deleteBtn = deletable && onDelete
        ? `<button type="button" class="${btnDangerClass}" data-my-action="delete" data-plan-id="${planId}"><i class="fas fa-trash"></i> Delete</button>`
        : '';

      const registerBtn = showRegister
        ? `<button type="button" class="${btnClass}" data-my-action="register" data-plan-id="${planId}"><i class="fas fa-layer-group"></i> Register</button>`
        : '';

      const mainInner = isMp
        ? `
          <div>
            <div class="${titleClass}">${escapeHtml(period)} ${statusBadge(status)}</div>
            <div class="${metaClass}">${escapeHtml(metaParts.join(' · '))}</div>
            ${rejectNote ? `<div class="${metaClass}" style="color:#b45309;margin-top:4px;"><i class="fas fa-circle-exclamation"></i> ${escapeHtml(rejectNote)}</div>` : ''}
          </div>
        `
        : `
          <div class="${mainClass}">
            <div class="${titleClass}">${escapeHtml(period)} ${statusBadge(status)}</div>
            <div class="${metaClass}">${escapeHtml(metaParts.join(' · '))}</div>
            ${rejectNote ? `<div class="${metaClass}" style="color:#b45309;margin-top:4px;"><i class="fas fa-circle-exclamation"></i> ${escapeHtml(rejectNote)}</div>` : ''}
          </div>
        `;

      return `
        <article class="${rowClass}" data-plan-id="${planId}">
          ${mainInner}
          <div class="${actionsClass}">
            <button type="button" class="${btnClass}" data-my-action="view" data-plan-id="${planId}"><i class="fas fa-eye"></i> View</button>
            ${editBtn}
            ${exportBtn}
            ${duplicateBtn}
            ${evidenceBtn}
            ${registerBtn}
            ${deleteBtn}
          </div>
        </article>
      `;
    }).join('');

    host.querySelectorAll('[data-my-action]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const action = btn.getAttribute('data-my-action');
        const planId = btn.getAttribute('data-plan-id');
        const plan = list.find((item) => String(item.id || item.plan_id) === String(planId));
        if (!plan) return;
        if (action === 'view' && onView) onView(plan);
        if (action === 'edit' && onEdit) onEdit(plan);
        if (action === 'register') {
          global.location.href = getRegisterUrl(planType);
        }
        if (action === 'export' && onExport) onExport(plan);
        if (action === 'duplicate' && onDuplicate) onDuplicate(plan);
        if (action === 'delete' && onDelete) onDelete(plan);
        if (action === 'evidence' && onEvidence) onEvidence(plan);
      });
    });
  }

  global.RDOMMSPlanningWorkspace = {
    escapeHtml,
    getCurrentUser,
    getCurrentUserId,
    getApprovalStatus,
    isSubmittedStatus,
    isSubmittedPlan,
    planBelongsToUser,
    canEditPlan,
    canAmendPlan,
    canOwnerModifyPlan,
    canOwnerDeletePlan,
    formatMoney,
    departmentLabel,
    timeAgo,
    buildPeriodPreviewRows,
    buildMonthlyPreviewRows,
    openPreviewModal,
    closePreviewModal,
    bindPreviewModal,
    getEditPageUrl,
    getRegisterUrl,
    renderSubmittedPlansPanel
  };
})(window);
