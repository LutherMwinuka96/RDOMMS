/**
 * RDOMMS Role Management — database-backed roles & permissions.
 */
(function () {
  const PAGE = window.RDOMMS_ROLE_MANAGEMENT || {};
  const API = PAGE.apiRoles || '../api/roles.php';

  const PERM_KEYS = ['projects', 'planning', 'finance', 'reports', 'users'];
  const PERM_FIELDS = {
    projects: 'permProjects',
    planning: 'permPlanning',
    finance: 'permFinance',
    reports: 'permReports',
    users: 'permUsers'
  };

  let roles = [];
  let deleteTargetId = null;

  function $(id) {
    return document.getElementById(id);
  }

  function setLoading(active) {
    $('pageLoading')?.classList.toggle('hidden', !active);
  }

  function notify(msg, type) {
    if (window.RDOMMSToast) {
      RDOMMSToast.show(msg, type || 'info', {
        title: 'Role Management',
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

  function normalizePermissions(raw) {
    const source = raw && typeof raw === 'object' ? raw : {};
    return {
      projects: !!source.projects,
      planning: !!source.planning,
      finance: !!source.finance,
      reports: !!source.reports,
      users: !!source.users
    };
  }

  function normalizeRole(row) {
    return {
      id: row.id || row._id || row.role_id || '',
      name: row.name || '',
      type: String(row.type || 'custom').toLowerCase(),
      desc: row.desc || row.description || '',
      permissions: normalizePermissions(row.permissions),
      createdAt: row.createdAt || row.created_at || ''
    };
  }

  function permChip(enabled) {
    return enabled
      ? '<span class="perm-chip perm-yes"><i class="fas fa-check"></i> Yes</span>'
      : '<span class="perm-chip perm-no"><i class="fas fa-times"></i> No</span>';
  }

  function typeBadge(type) {
    if (type === 'admin') {
      return '<span class="badge b-admin"><i class="fas fa-user-shield"></i> Admin</span>';
    }
    return '<span class="badge b-custom"><i class="fas fa-layer-group"></i> Custom</span>';
  }

  function openModal(id) {
    $(id)?.classList.add('open');
  }

  function closeModal(id) {
    $(id)?.classList.remove('open');
  }

  function findRole(id) {
    return roles.find((role) => role.id === id);
  }

  async function fetchRoles() {
    const response = await fetch(API, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to load roles');
    }
    return (Array.isArray(payload.data) ? payload.data : []).map(normalizeRole);
  }

  async function createRole(data) {
    const response = await fetch(API, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin',
      body: JSON.stringify(data)
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to create role');
    }
    return payload;
  }

  async function updateRole(id, data) {
    const response = await fetch(API, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin',
      body: JSON.stringify({ id, ...data })
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to update role');
    }
    return payload;
  }

  async function deleteRole(id) {
    const response = await fetch(`${API}?id=${encodeURIComponent(id)}`, {
      method: 'DELETE',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to delete role');
    }
    return payload;
  }

  function readPermissionInputs() {
    const permissions = {};
    PERM_KEYS.forEach((key) => {
      permissions[key] = !!$(PERM_FIELDS[key])?.checked;
    });
    return permissions;
  }

  function fillPermissionInputs(permissions) {
    PERM_KEYS.forEach((key) => {
      const input = $(PERM_FIELDS[key]);
      if (input) input.checked = !!permissions[key];
    });
  }

  function actionButtons(role) {
    return `
      <button type="button" class="icon-btn" data-action="edit" data-id="${escapeHtml(role.id)}" title="Edit"><i class="fas fa-pen"></i></button>
      <button type="button" class="icon-btn danger" data-action="delete" data-id="${escapeHtml(role.id)}" title="Delete"><i class="fas fa-trash"></i></button>
    `;
  }

  function updateStats() {
    $('statTotal').textContent = String(roles.length);
    $('statAdmin').textContent = String(roles.filter((r) => r.type === 'admin').length);
    $('statCustom').textContent = String(roles.filter((r) => r.type !== 'admin').length);
    if ($('matrixMeta')) {
      $('matrixMeta').textContent = `${roles.length} role${roles.length === 1 ? '' : 's'}`;
    }
  }

  function renderRolesList() {
    const list = $('rolesList');
    const empty = $('rolesEmpty');
    if (!list) return;

    if (!roles.length) {
      list.innerHTML = '';
      if (empty) empty.style.display = '';
      return;
    }

    if (empty) empty.style.display = 'none';
    list.innerHTML = roles.map((role) => `
      <article class="role-item">
        <div>
          <h3>${escapeHtml(role.name)}</h3>
          <p>${escapeHtml(role.desc || 'No description')}</p>
          ${typeBadge(role.type)}
        </div>
        <div class="actions">${actionButtons(role)}</div>
      </article>
    `).join('');
  }

  function renderMatrix() {
    const body = $('matrixBody');
    const empty = $('matrixEmpty');
    if (!body) return;

    if (!roles.length) {
      body.innerHTML = '';
      if (empty) empty.style.display = '';
      return;
    }

    if (empty) empty.style.display = 'none';
    body.innerHTML = roles.map((role) => `
      <tr>
        <td><strong>${escapeHtml(role.name)}</strong></td>
        <td>${permChip(role.permissions.projects)}</td>
        <td>${permChip(role.permissions.planning)}</td>
        <td>${permChip(role.permissions.finance)}</td>
        <td>${permChip(role.permissions.reports)}</td>
        <td>${permChip(role.permissions.users)}</td>
        <td><div class="actions">${actionButtons(role)}</div></td>
      </tr>
    `).join('');
  }

  function renderAll() {
    updateStats();
    renderRolesList();
    renderMatrix();
  }

  function openCreateModal() {
    $('roleForm')?.reset();
    $('roleId').value = '';
    $('roleModalTitle').innerHTML = '<i class="fas fa-plus"></i> New Role';
    fillPermissionInputs({});
    openModal('roleModal');
  }

  function openEditModal(role) {
    $('roleId').value = role.id;
    $('roleName').value = role.name || '';
    $('roleType').value = role.type || 'custom';
    $('roleDesc').value = role.desc || '';
    fillPermissionInputs(role.permissions);
    $('roleModalTitle').innerHTML = '<i class="fas fa-pen"></i> Edit Role';
    openModal('roleModal');
  }

  async function reloadRoles() {
    setLoading(true);
    try {
      roles = await fetchRoles();
      renderAll();
    } catch (error) {
      notify(error.message || 'Unable to load roles.', 'error');
      roles = [];
      renderAll();
    } finally {
      setLoading(false);
    }
  }

  function bindActionClicks(container) {
    container?.addEventListener('click', (event) => {
      const button = event.target.closest('[data-action]');
      if (!button) return;
      const id = button.getAttribute('data-id');
      const action = button.getAttribute('data-action');
      const role = findRole(id);
      if (!role) return;

      if (action === 'edit') {
        openEditModal(role);
        return;
      }

      if (action === 'delete') {
        deleteTargetId = id;
        $('deleteText').innerHTML = `Delete role <b>${escapeHtml(role.name)}</b>? This cannot be undone.`;
        openModal('deleteModal');
      }
    });
  }

  document.querySelectorAll('[data-close]').forEach((btn) => {
    btn.addEventListener('click', () => closeModal(btn.getAttribute('data-close')));
  });

  document.querySelectorAll('.modal-overlay').forEach((overlay) => {
    overlay.addEventListener('click', (event) => {
      if (event.target === overlay) overlay.classList.remove('open');
    });
  });

  $('newRoleBtn')?.addEventListener('click', openCreateModal);

  bindActionClicks($('rolesList'));
  bindActionClicks($('matrixBody'));

  $('roleForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const id = $('roleId')?.value || '';
    const payload = {
      name: $('roleName')?.value.trim(),
      type: $('roleType')?.value || 'custom',
      desc: $('roleDesc')?.value.trim(),
      permissions: readPermissionInputs()
    };

    if (!payload.name) {
      notify('Role name is required.', 'error');
      return;
    }

    try {
      if (id) {
        await updateRole(id, payload);
        notify('Role updated successfully.', 'success');
      } else {
        await createRole(payload);
        notify('Role created successfully.', 'success');
      }
      closeModal('roleModal');
      await reloadRoles();
    } catch (error) {
      notify(error.message || 'Save failed.', 'error');
    }
  });

  $('confirmDeleteBtn')?.addEventListener('click', async () => {
    if (!deleteTargetId) return;
    try {
      await deleteRole(deleteTargetId);
      notify('Role deleted successfully.', 'success');
      deleteTargetId = null;
      closeModal('deleteModal');
      await reloadRoles();
    } catch (error) {
      notify(error.message || 'Delete failed.', 'error');
    }
  });

  reloadRoles();
})();
