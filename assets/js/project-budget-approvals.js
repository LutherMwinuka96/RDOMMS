/**
 * Project budget approvals workspace for accountants and budget approvers.
 */
(function () {
  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function formatMoney(value) {
    if (typeof formatRdommsCurrencyHtml === 'function') {
      return formatRdommsCurrencyHtml(value);
    }
    return 'TSH ' + Number(value || 0).toLocaleString('en-US');
  }

  async function fetchPending() {
    const res = await fetch('../api/projects.php?action=pending-budgets', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const payload = await res.json().catch(() => null);
    if (!res.ok || payload?.status !== 'success') {
      throw new Error(payload?.message || `Unable to load pending budgets (${res.status})`);
    }
    return Array.isArray(payload.data) ? payload.data : [];
  }

  function renderRows(rows) {
    const host = document.getElementById('tableHost');
    const countEl = document.getElementById('pendingCount');
    if (!host) return;

    if (countEl) countEl.textContent = String(rows.length);

    if (!rows.length) {
      host.innerHTML = '<div class="empty">No project budgets waiting for approval right now.</div>';
      return;
    }

    const body = rows.map((project) => {
      const id = project.project_id || project.id;
      const name = escapeHtml(project.name || 'Project');
      const org = escapeHtml(project.organization || project.district || project.location || '—');
      const budget = formatMoney(project.budget);
      const submitted = escapeHtml(
        project.budget_submitted_at || project.budgetSubmittedAt || project.created_at || '—'
      );
      const safeId = escapeHtml(id);
      return `
        <tr data-project-id="${safeId}">
          <td><strong>${name}</strong><br><small style="color:#64748b;">${org}</small></td>
          <td>${budget}</td>
          <td>${submitted}</td>
          <td><span class="status-pending"><i class="fas fa-hourglass-half"></i> Pending</span></td>
          <td>
            <div class="actions">
              <button type="button" class="btn btn-approve" data-approve="${safeId}"><i class="fas fa-check"></i> Approve</button>
              <button type="button" class="btn btn-reject" data-reject="${safeId}"><i class="fas fa-times"></i> Reject</button>
            </div>
          </td>
        </tr>
      `;
    }).join('');

    host.innerHTML = `
      <table>
        <thead>
          <tr>
            <th>Project</th>
            <th>Budget</th>
            <th>Submitted</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>${body}</tbody>
      </table>
    `;

    host.querySelectorAll('[data-approve]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const projectId = btn.getAttribute('data-approve');
        if (!projectId || !confirm('Approve this project budget?')) return;
        btn.disabled = true;
        try {
          await window.RDOMMSProjectFinance.approveProjectBudget(projectId);
          alert('Project budget approved.');
          await load();
        } catch (error) {
          alert(error.message || 'Unable to approve budget.');
          btn.disabled = false;
        }
      });
    });

    host.querySelectorAll('[data-reject]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const projectId = btn.getAttribute('data-reject');
        if (!projectId) return;
        const reason = prompt('Reason for rejection (optional):', '');
        if (reason === null) return;
        btn.disabled = true;
        try {
          await window.RDOMMSProjectFinance.rejectProjectBudget(projectId, reason);
          alert('Project budget rejected.');
          await load();
        } catch (error) {
          alert(error.message || 'Unable to reject budget.');
          btn.disabled = false;
        }
      });
    });
  }

  async function load() {
    const host = document.getElementById('tableHost');
    if (host) {
      host.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i> Loading pending budgets…</div>';
    }
    try {
      const rows = await fetchPending();
      renderRows(rows);
    } catch (error) {
      if (host) {
        host.innerHTML = `<div class="error">${escapeHtml(error.message || 'Failed to load pending budgets.')}</div>`;
      }
      const countEl = document.getElementById('pendingCount');
      if (countEl) countEl.textContent = '0';
    }
  }

  document.getElementById('btnRefresh')?.addEventListener('click', load);
  window.addEventListener('rdommsAlreadyAuthenticated', load);
  document.addEventListener('DOMContentLoaded', load);
})();
