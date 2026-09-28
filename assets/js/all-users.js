/**
 * RDOMMS All Users — database-backed user directory.
 */
(function () {
  const PAGE = window.RDOMMS_ALL_USERS_PAGE || {};
  const API = PAGE.apiUsers || '../api/users.php';

  const ORG_LABELS = {
    rdo_mafinga: 'RDO Mafinga',
    rdo_mdabulo: 'RDO Mdabulo',
    rdo_ibwanzi: 'RDO Ibwanzi',
    rdo_kilolo: 'RDO Kilolo',
    rdo_malangali: 'RDO Malangali'
  };

  let users = [];
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
        title: 'All Users',
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

  function getCurrentUser() {
    if (window.RDOMMSRBAC?.getCurrentUser) {
      return window.RDOMMSRBAC.getCurrentUser() || {};
    }
    try {
      return JSON.parse(localStorage.getItem('currentUser') || '{}');
    } catch (_) {
      return {};
    }
  }

  function getCurrentRole() {
    return String(getCurrentUser()?.role || '').toLowerCase();
  }

  function canManageUsers() {
    return ['admin', 'human_resource_manager', 'head_of_organization_center', 'head_of_department'].includes(getCurrentRole());
  }

  function canDeleteUsers() {
    return ['admin', 'head_of_organization_center'].includes(getCurrentRole());
  }

  function getScopedManager() {
    const role = getCurrentRole();
    const user = getCurrentUser();
    return {
      role,
      organization: user.organization || '',
      department: user.department || ''
    };
  }

  function allowedRolesForManager() {
    const role = getCurrentRole();
    const all = [
      'admin',
      'head_of_department',
      'head_of_organization_center',
      'human_resource_manager',
      'accountant',
      'field_officer',
      'staff'
    ];

    if (role === 'admin' || role === 'human_resource_manager') {
      return all;
    }
    if (role === 'head_of_organization_center') {
      return all.filter((item) => item !== 'admin');
    }
    if (role === 'head_of_department') {
      return all.filter((item) => !['admin', 'head_of_organization_center', 'human_resource_manager'].includes(item));
    }
    return [];
  }

  function applyScopedEditForm() {
    const scope = getScopedManager();
    const orgSelect = $('editOrganization');
    const deptSelect = $('editDepartment');
    const roleSelect = $('editRole');

    if (roleSelect) {
      Array.from(roleSelect.options).forEach((option) => {
        option.hidden = option.value !== '' && !allowedRolesForManager().includes(option.value);
      });
    }

    if (scope.role === 'head_of_organization_center' && orgSelect) {
      orgSelect.value = scope.organization;
      orgSelect.disabled = true;
    } else if (orgSelect) {
      orgSelect.disabled = false;
    }

    if (scope.role === 'head_of_department') {
      if (orgSelect) {
        orgSelect.value = scope.organization;
        orgSelect.disabled = true;
      }
      if (deptSelect) {
        deptSelect.value = scope.department;
        deptSelect.disabled = true;
      }
    } else if (deptSelect && scope.role !== 'head_of_organization_center') {
      deptSelect.disabled = false;
    }
  }

  function titleCase(value) {
    return String(value || '')
      .replaceAll('_', ' ')
      .replace(/\b\w/g, (m) => m.toUpperCase());
  }

  function departmentLabel(value) {
    if (window.RDOMMSDepartments) {
      return RDOMMSDepartments.getLabel(value) || '—';
    }
    return titleCase(value || '—');
  }

  function organizationLabel(value) {
    return ORG_LABELS[value] || titleCase(value || '—');
  }

  function statusKey(value) {
    return String(value || 'pending').toLowerCase();
  }

  function statusBadge(status) {
    const key = statusKey(status);
    if (key === 'active') return '<span class="badge b-active"><i class="fas fa-circle"></i> Active</span>';
    if (key === 'pending') return '<span class="badge b-pending"><i class="fas fa-clock"></i> Pending</span>';
    return '<span class="badge b-suspended"><i class="fas fa-ban"></i> Suspended</span>';
  }

  function roleBadge(role) {
    const key = String(role || '').toLowerCase();
    if (key === 'admin') {
      return '<span class="badge b-admin"><i class="fas fa-user-shield"></i> Admin</span>';
    }
    return `<span class="badge b-role">${escapeHtml(titleCase(role))}</span>`;
  }

  function initials(name) {
    return String(name || 'U')
      .split(' ')
      .map((part) => part[0])
      .join('')
      .slice(0, 2)
      .toUpperCase();
  }

  function avatarHtml(user) {
    const pic = user.profilePicture;
    if (pic) {
      const src = pic.startsWith('http') || pic.startsWith('data:') || pic.startsWith('../')
        ? pic
        : `../${pic.replace(/^\/+/, '')}`;
      return `<div class="avatar"><img src="${escapeHtml(src)}" alt=""></div>`;
    }
    return `<div class="avatar">${escapeHtml(initials(user.fullname))}</div>`;
  }

  function formatDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return new Intl.DateTimeFormat('en-GB', {
      day: '2-digit',
      month: 'short',
      year: 'numeric'
    }).format(date);
  }

  function normalizeUser(row) {
    return {
      id: row.user_id || row.id || row._id || '',
      fullname: row.fullname || '',
      email: row.email || '',
      role: row.role || '',
      department: row.department || '',
      organization: row.organization || '',
      phone: row.phone || '',
      employeeId: row.employeeId || row.employee_id || '',
      status: statusKey(row.status),
      profilePicture: row.profile_picture || row.profileImage || '',
      createdAt: row.createdAt || row.created_at || ''
    };
  }

  async function fetchUsers() {
    const response = await fetch(API, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to load users');
    }
    return (Array.isArray(payload.data) ? payload.data : []).map(normalizeUser);
  }

  async function updateUser(id, data) {
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
      throw new Error(payload?.message || 'Failed to update user');
    }
    return payload;
  }

  async function deleteUser(id) {
    const response = await fetch(`${API}?id=${encodeURIComponent(id)}`, {
      method: 'DELETE',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to delete user');
    }
    return payload;
  }

  function getFiltered() {
    const search = ($('searchInput')?.value || '').trim().toLowerCase();
    const role = $('roleFilter')?.value || '';
    const status = $('statusFilter')?.value || '';

    return users.filter((user) => {
      if (role && user.role !== role) return false;
      if (status && statusKey(user.status) !== status) return false;
      if (!search) return true;
      const haystack = [
        user.fullname,
        user.email,
        user.role,
        departmentLabel(user.department),
        organizationLabel(user.organization),
        user.phone,
        user.employeeId
      ].join(' ').toLowerCase();
      return haystack.includes(search);
    });
  }

  function updateStats(list) {
    $('statTotal').textContent = String(list.length);
    $('statActive').textContent = String(list.filter((u) => statusKey(u.status) === 'active').length);
    $('statPending').textContent = String(list.filter((u) => statusKey(u.status) === 'pending').length);
    $('statSuspended').textContent = String(list.filter((u) => statusKey(u.status) === 'suspended').length);
  }

  function openModal(id) {
    $(id)?.classList.add('open');
  }

  function closeModal(id) {
    $(id)?.classList.remove('open');
  }

  function findUser(id) {
    return users.find((user) => user.id === id);
  }

  function renderViewModal(user) {
    $('viewBody').innerHTML = `
      <div class="modal-grid">
        <div class="kv"><b>Full Name:</b> ${escapeHtml(user.fullname || '—')}</div>
        <div class="kv"><b>Email:</b> ${escapeHtml(user.email || '—')}</div>
        <div class="kv"><b>Role:</b> ${escapeHtml(titleCase(user.role || '—'))}</div>
        <div class="kv"><b>Status:</b> ${escapeHtml(titleCase(user.status || '—'))}</div>
        <div class="kv"><b>Department:</b> ${escapeHtml(departmentLabel(user.department))}</div>
        <div class="kv"><b>Organization:</b> ${escapeHtml(organizationLabel(user.organization))}</div>
        <div class="kv"><b>Registered:</b> ${escapeHtml(formatDate(user.createdAt))}</div>
        ${user.phone ? `<div class="kv"><b>Phone:</b> ${escapeHtml(user.phone)}</div>` : ''}
        ${user.employeeId ? `<div class="kv"><b>Employee ID:</b> ${escapeHtml(user.employeeId)}</div>` : ''}
      </div>`;
    openModal('viewModal');
  }

  function renderEditModal(user) {
    $('editId').value = user.id;
    $('editFullname').value = user.fullname || '';
    $('editEmail').value = user.email || '';
    $('editRole').value = user.role || 'staff';
    $('editStatus').value = statusKey(user.status) || 'pending';
    if (window.RDOMMSDepartments) {
      RDOMMSDepartments.fillSelect($('editDepartment'), {
        emptyLabel: 'Select Department',
        selectedValue: user.department || ''
      });
    } else {
      $('editDepartment').value = user.department || '';
    }
    $('editOrganization').value = user.organization || '';
    applyScopedEditForm();
    openModal('editModal');
  }

  function canApprovePendingUsers() {
    return getCurrentRole() === 'admin';
  }

  function actionButtons(user) {
    const parts = [
      `<button type="button" class="icon-btn" data-action="view" data-id="${escapeHtml(user.id)}" title="View"><i class="fas fa-eye"></i></button>`
    ];
    if (canManageUsers()) {
      parts.push(`<button type="button" class="icon-btn" data-action="edit" data-id="${escapeHtml(user.id)}" title="Edit"><i class="fas fa-pen"></i></button>`);
      if (statusKey(user.status) === 'pending' && canApprovePendingUsers()) {
        parts.push(`<button type="button" class="icon-btn approve" data-action="approve" data-id="${escapeHtml(user.id)}" title="Approve"><i class="fas fa-check"></i></button>`);
      }
    }
    if (canDeleteUsers()) {
      parts.push(`<button type="button" class="icon-btn danger" data-action="delete" data-id="${escapeHtml(user.id)}" title="Delete"><i class="fas fa-trash"></i></button>`);
    }
    return parts.join('');
  }

  function renderTable() {
    const rows = getFiltered();
    const tbody = $('usersBody');
    const empty = $('emptyState');
    const meta = $('tableMeta');

    updateStats(users);

    if (meta) {
      meta.textContent = rows.length
        ? `Showing ${rows.length} of ${users.length} user account(s).`
        : users.length
          ? 'No users match your filters.'
          : 'No users found. Create one from Add User.';
    }

    if (!tbody) return;

    if (!rows.length) {
      tbody.innerHTML = '';
      empty?.classList.add('show');
      return;
    }

    empty?.classList.remove('show');
    tbody.innerHTML = rows.map((user) => `
      <tr>
        <td>
          <div class="user-cell">
            ${avatarHtml(user)}
            <div>
              <div class="u-name">${escapeHtml(user.fullname || '—')}</div>
              <div class="u-email">${escapeHtml(user.email || '—')}</div>
            </div>
          </div>
        </td>
        <td>${roleBadge(user.role)}</td>
        <td>${escapeHtml(departmentLabel(user.department))}</td>
        <td>${escapeHtml(organizationLabel(user.organization))}</td>
        <td>${statusBadge(user.status)}</td>
        <td>${escapeHtml(formatDate(user.createdAt))}</td>
        <td><div class="actions">${actionButtons(user)}</div></td>
      </tr>
    `).join('');
  }

  async function reloadUsers() {
    setLoading(true);
    try {
      users = await fetchUsers();
      renderTable();
    } catch (error) {
      notify(error.message || 'Unable to load users.', 'error');
      users = [];
      renderTable();
    } finally {
      setLoading(false);
    }
  }

  async function handleApprove(id) {
    try {
      await updateUser(id, { status: 'active' });
      notify('User approved and set to Active.', 'success');
      await reloadUsers();
    } catch (error) {
      notify(error.message || 'Approval failed.', 'error');
    }
  }

  document.querySelectorAll('[data-close]').forEach((btn) => {
    btn.addEventListener('click', () => closeModal(btn.getAttribute('data-close')));
  });

  document.querySelectorAll('.modal-overlay').forEach((overlay) => {
    overlay.addEventListener('click', (event) => {
      if (event.target === overlay) overlay.classList.remove('open');
    });
  });

  $('usersBody')?.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-action]');
    if (!button) return;
    const id = button.getAttribute('data-id');
    const action = button.getAttribute('data-action');
    const user = findUser(id);
    if (!user) return;

    if (action === 'view') {
      renderViewModal(user);
      return;
    }
    if (action === 'edit') {
      renderEditModal(user);
      return;
    }
    if (action === 'approve') {
      await handleApprove(id);
      return;
    }
    if (action === 'delete') {
      deleteTargetId = id;
      $('deleteText').innerHTML = `Delete <b>${escapeHtml(user.fullname || 'this user')}</b>? This cannot be undone.`;
      openModal('deleteModal');
    }
  });

  $('confirmDeleteBtn')?.addEventListener('click', async () => {
    if (!deleteTargetId) return;
    try {
      await deleteUser(deleteTargetId);
      notify('User deleted successfully.', 'success');
      deleteTargetId = null;
      closeModal('deleteModal');
      await reloadUsers();
    } catch (error) {
      notify(error.message || 'Delete failed.', 'error');
    }
  });

  $('editForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const id = $('editId')?.value;
    if (!id) return;

    const orgSelect = $('editOrganization');
    const deptSelect = $('editDepartment');
    const payload = {
      fullname: $('editFullname')?.value.trim(),
      email: $('editEmail')?.value.trim().toLowerCase(),
      role: $('editRole')?.value,
      status: $('editStatus')?.value,
      department: deptSelect?.disabled ? getScopedManager().department : $('editDepartment')?.value,
      organization: orgSelect?.disabled ? getScopedManager().organization : $('editOrganization')?.value
    };

    try {
      await updateUser(id, payload);
      notify('User updated successfully.', 'success');
      closeModal('editModal');
      await reloadUsers();
    } catch (error) {
      notify(error.message || 'Update failed.', 'error');
    }
  });

  ['searchInput', 'roleFilter', 'statusFilter'].forEach((id) => {
    $(id)?.addEventListener('input', renderTable);
    $(id)?.addEventListener('change', renderTable);
  });

  $('resetBtn')?.addEventListener('click', () => {
    if ($('searchInput')) $('searchInput').value = '';
    if ($('roleFilter')) $('roleFilter').value = '';
    if ($('statusFilter')) $('statusFilter').value = '';
    renderTable();
  });

  if (!canManageUsers()) {
    $('addUserLink')?.remove();
  }

  document.querySelectorAll('[data-close="editModal"]').forEach((btn) => {
    btn.addEventListener('click', () => {
      $('editOrganization')?.removeAttribute('disabled');
      $('editDepartment')?.removeAttribute('disabled');
    });
  });

  reloadUsers();
})();
