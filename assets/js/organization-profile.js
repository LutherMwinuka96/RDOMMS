/**
 * RDOMMS Organization Profile — database-backed organization details.
 */
(function () {
  const PAGE = window.RDOMMS_ORGANIZATION_PROFILE || {};
  const API = PAGE.apiOrganization || '../api/settings/organization-profile.php';

  let profile = {};
  let logoUpload = '';

  function $(id) {
    return document.getElementById(id);
  }

  function setLoading(active) {
    $('pageLoading')?.classList.toggle('hidden', !active);
  }

  function notify(msg, type) {
    if (window.RDOMMSToast) {
      RDOMMSToast.show(msg, type || 'info', {
        title: 'Organization Profile',
        duration: 4500,
        allowMultiple: true
      });
    } else {
      alert(msg);
    }
  }

  function resolveAssetPath(path) {
    if (!path) return '';
    if (/^(https?:|data:)/i.test(path)) return path;
    return `../${String(path).replace(/^\/+/, '')}`;
  }

  function updatePreview() {
    const name = $('orgName')?.value.trim() || 'Organization';
    const mission = $('orgMission')?.value.trim() || 'Mission statement preview.';
    const email = $('orgEmail')?.value.trim() || '';

    if ($('previewOrgName')) $('previewOrgName').textContent = name;
    if ($('previewOrgMission')) $('previewOrgMission').textContent = mission;
    if ($('previewOrgEmail')) $('previewOrgEmail').textContent = email || 'No email set';
  }

  function showLogo(path) {
    const preview = $('logoPreview');
    const placeholder = $('logoPlaceholder');
    if (!preview) return;

    if (path) {
      preview.src = resolveAssetPath(path);
      preview.style.display = 'block';
      if (placeholder) placeholder.style.display = 'none';
    } else {
      preview.removeAttribute('src');
      preview.style.display = 'none';
      if (placeholder) placeholder.style.display = '';
    }
  }

  function fillForm(data) {
    profile = data || {};
    $('orgName').value = profile.name || '';
    $('orgRegNo').value = profile.regNo || profile.registration_no || '';
    $('orgEmail').value = profile.email || '';
    $('orgPhone').value = profile.phone || '';
    $('orgWebsite').value = profile.website || '';
    $('orgAddress').value = profile.address || '';
    $('orgMission').value = profile.mission || '';
    $('orgLogoCurrent').value = profile.logo_url || profile.logo || '';
    logoUpload = '';
    showLogo($('orgLogoCurrent').value);
    updatePreview();
  }

  function readForm() {
    const payload = {
      name: $('orgName')?.value.trim(),
      regNo: $('orgRegNo')?.value.trim(),
      email: $('orgEmail')?.value.trim(),
      phone: $('orgPhone')?.value.trim(),
      website: $('orgWebsite')?.value.trim(),
      address: $('orgAddress')?.value.trim(),
      mission: $('orgMission')?.value.trim()
    };

    if (logoUpload) {
      payload.logo = logoUpload;
    } else if ($('orgLogoCurrent')?.value) {
      payload.logo = $('orgLogoCurrent').value;
    }

    return payload;
  }

  async function fetchProfile() {
    const response = await fetch(API, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to load organization profile');
    }
    return payload.data || {};
  }

  async function saveProfile(data) {
    const response = await fetch(API, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin',
      body: JSON.stringify(data)
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to save organization profile');
    }
    return payload;
  }

  function syncGlobalBranding(data) {
    if (!window.RDOMMSSettings) return;
    const current = RDOMMSSettings.all();
    RDOMMSSettings.set({
      ...current,
      organization: {
        name: data.name || '',
        email: data.email || '',
        phone: data.phone || '',
        website: data.website || '',
        address: data.address || '',
        logo_url: data.logo_url || data.logo || ''
      }
    });
  }

  async function init() {
    setLoading(true);
    try {
      const data = await fetchProfile();
      fillForm(data);
      syncGlobalBranding(data);
    } catch (error) {
      notify(error.message || 'Unable to load organization profile.', 'error');
    } finally {
      setLoading(false);
    }
  }

  ['orgName', 'orgMission', 'orgEmail'].forEach((id) => {
    $(id)?.addEventListener('input', updatePreview);
  });

  $('orgLogoFile')?.addEventListener('change', (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) {
      notify('Logo must be 2MB or smaller.', 'error');
      event.target.value = '';
      return;
    }
    const reader = new FileReader();
    reader.onload = (ev) => {
      logoUpload = ev.target.result || '';
      showLogo(logoUpload);
    };
    reader.readAsDataURL(file);
  });

  $('resetBtn')?.addEventListener('click', init);

  $('saveBtn')?.addEventListener('click', async () => {
    const payload = readForm();
    if (!payload.name) {
      notify('Organization name is required.', 'error');
      return;
    }

    const btn = $('saveBtn');
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    }

    try {
      const result = await saveProfile(payload);
      fillForm(result.data || {});
      syncGlobalBranding(result.data || {});
      notify(result.message || 'Organization profile saved successfully.', 'success');
    } catch (error) {
      notify(error.message || 'Save failed.', 'error');
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Profile';
      }
    }
  });

  init();
})();
