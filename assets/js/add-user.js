/**
 * RDOMMS Add User — admin registration matching signup fields, saved via API.
 */
(function () {
  const PAGE = window.RDOMMS_ADD_USER_PAGE || {};
  const API = PAGE.apiUsers || '../api/users.php';
  const REDIRECT = PAGE.redirectUrl || 'all-users.php';

  const form = document.getElementById('addUserForm');
  if (!form) return;

  const steps = Array.from(document.querySelectorAll('.form-step'));
  const stepNodes = Array.from(document.querySelectorAll('.step-node'));
  const progressFill = document.getElementById('progressFill');
  const passwordInput = document.getElementById('password');
  const confirmInput = document.getElementById('confirm_password');
  const dropzone = document.getElementById('dropzone');
  const fileInput = document.getElementById('profile_image');
  const imagePreview = document.getElementById('imagePreview');
  const placeholderIcon = document.getElementById('placeholderIcon');
  const submitBtn = document.getElementById('submitBtn');
  const loading = document.getElementById('pageLoading');

  const reqLength = document.getElementById('req-length');
  const reqUpper = document.getElementById('req-upper');
  const reqNumber = document.getElementById('req-number');
  const reqMatch = document.getElementById('req-match');

  let currentStep = 0;
  let profilePicture = '';

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

  function canCreateUsers() {
    return ['admin', 'human_resource_manager', 'head_of_organization_center', 'head_of_department'].includes(getCurrentRole());
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

  function applyScopedCreateForm() {
    const user = getCurrentUser();
    const role = getCurrentRole();
    const roleSelect = document.getElementById('role');
    const orgSelect = document.getElementById('organization');
    const deptSelect = document.getElementById('department');

    if (roleSelect) {
      Array.from(roleSelect.options).forEach((option) => {
        option.hidden = option.value !== '' && !allowedRolesForManager().includes(option.value);
      });
    }

    if (role === 'head_of_organization_center' && orgSelect) {
      orgSelect.value = user.organization || '';
      orgSelect.disabled = true;
    }

    if (role === 'head_of_department') {
      if (orgSelect) {
        orgSelect.value = user.organization || '';
        orgSelect.disabled = true;
      }
      if (deptSelect) {
        if (window.RDOMMSDepartments) {
          RDOMMSDepartments.fillSelect(deptSelect, {
            emptyLabel: 'Select Department',
            selectedValue: user.department || ''
          });
        } else {
          deptSelect.value = user.department || '';
        }
        deptSelect.disabled = true;
      }
    }
  }

  if (!canCreateUsers()) {
    notify('You do not have permission to add users.', 'error');
    setTimeout(() => {
      window.location.href = REDIRECT;
    }, 1200);
    return;
  }

  function notify(message, type) {
    if (window.RDOMMSToast) {
      RDOMMSToast.show(message, type || 'info', {
        title: 'Add User',
        duration: 4500,
        allowMultiple: true
      });
    } else {
      alert(message);
    }
  }

  function setLoading(active) {
    loading?.classList.toggle('hidden', !active);
  }

  function updateStepper() {
    steps.forEach((step, idx) => step.classList.toggle('active', idx === currentStep));
    stepNodes.forEach((node, idx) => {
      node.classList.toggle('active', idx === currentStep);
      node.classList.toggle('completed', idx < currentStep);
    });
    if (progressFill) {
      progressFill.style.width = ((currentStep + 1) / steps.length) * 100 + '%';
    }
  }

  function validateStepInputs(stepIndex) {
    const inputs = steps[stepIndex]?.querySelectorAll('input, select') || [];
    let valid = true;
    inputs.forEach((input) => {
      if (!input.checkValidity()) {
        input.reportValidity();
        valid = false;
      }
    });
    if (stepIndex === 1 && !validatePasswords()) {
      valid = false;
      notify('Password must meet all requirements and match confirmation.', 'error');
    }
    return valid;
  }

  function markChecklist(el, ok, okText, failText) {
    if (!el) return;
    el.classList.toggle('valid', ok);
    el.innerHTML = `<i class="fas fa-${ok ? 'circle-check' : 'circle-xmark'}"></i> ${ok ? okText : failText}`;
  }

  function validatePasswords() {
    const pass = passwordInput?.value || '';
    const confirm = confirmInput?.value || '';
    const lenValid = pass.length >= 8;
    const upperValid = /[A-Z]/.test(pass);
    const numberValid = /[0-9]/.test(pass);
    const matchValid = lenValid && pass === confirm && pass !== '';

    markChecklist(reqLength, lenValid, '8 or more characters', '8 or more characters');
    markChecklist(reqUpper, upperValid, 'At least one uppercase letter', 'At least one uppercase letter');
    markChecklist(reqNumber, numberValid, 'At least one number', 'At least one number');
    markChecklist(reqMatch, matchValid, 'Passwords match', 'Passwords match');

    return lenValid && upperValid && numberValid && matchValid;
  }

  function goToStep(index) {
    currentStep = Math.max(0, Math.min(index, steps.length - 1));
    updateStepper();
  }

  document.querySelectorAll('.btn-next').forEach((btn) => {
    btn.addEventListener('click', () => {
      if (!validateStepInputs(currentStep)) return;
      if (currentStep < steps.length - 1) goToStep(currentStep + 1);
    });
  });

  document.querySelectorAll('.btn-prev').forEach((btn) => {
    btn.addEventListener('click', () => {
      if (currentStep > 0) goToStep(currentStep - 1);
    });
  });

  stepNodes.forEach((node, idx) => {
    node.addEventListener('click', () => {
      if (idx === currentStep) return;
      if (idx < currentStep) {
        goToStep(idx);
        return;
      }
      for (let s = currentStep; s < idx; s += 1) {
        if (!validateStepInputs(s)) {
          notify('Complete required fields before jumping ahead.', 'error');
          return;
        }
      }
      goToStep(idx);
    });
  });

  passwordInput?.addEventListener('input', validatePasswords);
  confirmInput?.addEventListener('input', validatePasswords);

  function handleAvatarPreview(file) {
    if (!file || !file.type.startsWith('image/')) return;
    const reader = new FileReader();
    reader.onload = (ev) => {
      profilePicture = ev.target.result || '';
      if (imagePreview) {
        imagePreview.src = profilePicture;
        imagePreview.style.display = 'block';
      }
      if (placeholderIcon) placeholderIcon.style.display = 'none';
    };
    reader.readAsDataURL(file);
  }

  if (dropzone && fileInput) {
    dropzone.addEventListener('click', () => fileInput.click());
    dropzone.addEventListener('dragover', (e) => {
      e.preventDefault();
      dropzone.classList.add('dragover');
    });
    ['dragleave', 'drop'].forEach((ev) => {
      dropzone.addEventListener(ev, () => dropzone.classList.remove('dragover'));
    });
    dropzone.addEventListener('drop', (e) => {
      e.preventDefault();
      const file = e.dataTransfer?.files?.[0];
      if (file) handleAvatarPreview(file);
    });
    fileInput.addEventListener('change', (e) => {
      const file = e.target.files?.[0];
      if (file) handleAvatarPreview(file);
    });
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    for (let s = 0; s < 3; s += 1) {
      if (!validateStepInputs(s)) {
        goToStep(s);
        return;
      }
    }
    if (!validatePasswords()) {
      goToStep(1);
      return;
    }

    const user = getCurrentUser();
    const role = getCurrentRole();
    const payload = {
      fullname: document.getElementById('fullname')?.value.trim(),
      email: document.getElementById('email')?.value.trim().toLowerCase(),
      password: passwordInput?.value,
      role: document.getElementById('role')?.value,
      department: role === 'head_of_department'
        ? (user.department || document.getElementById('department')?.value)
        : document.getElementById('department')?.value,
      organization: ['head_of_organization_center', 'head_of_department'].includes(role)
        ? (user.organization || document.getElementById('organization')?.value)
        : document.getElementById('organization')?.value,
      status: document.getElementById('status')?.value || 'active',
      profile_picture: profilePicture || null
    };

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating…';
    }
    setLoading(true);

    try {
      const response = await fetch(API, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
      });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data || data.status !== 'success') {
        throw new Error(data?.message || `Failed to create user (${response.status})`);
      }

      notify(data.message || 'User created successfully.', 'success');
      setTimeout(() => {
        window.location.href = REDIRECT;
      }, 1200);
    } catch (error) {
      notify(error.message || 'Unable to create user.', 'error');
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-user-plus"></i> Create User';
      }
      setLoading(false);
    }
  });

  updateStepper();
  validatePasswords();
  applyScopedCreateForm();
})();
