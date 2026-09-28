/**
 * RDOMMS General Settings — database-backed preferences.
 */
(function () {
  const PAGE = window.RDOMMS_GENERAL_SETTINGS || {};
  const API = PAGE.apiSettings || '../api/settings/general.php';

  function $(id) {
    return document.getElementById(id);
  }

  function setLoading(active) {
    $('pageLoading')?.classList.toggle('hidden', !active);
  }

  function notify(msg, type) {
    if (window.RDOMMSToast) {
      RDOMMSToast.show(msg, type || 'info', {
        title: 'General Settings',
        duration: 4500,
        allowMultiple: true
      });
    } else {
      alert(msg);
    }
  }

  function fillForm(data) {
    if ($('appName')) $('appName').value = data.app_name || 'RDOMMS';
    if ($('appVersion')) $('appVersion').value = data.app_version || '2.0.0';
    if ($('appLanguage')) $('appLanguage').value = data.language || data.default_language || 'en';
    if ($('dateFormat')) $('dateFormat').value = data.date_format || data.dateFormat || 'DD/MM/YYYY';
    if ($('timezoneSelect')) $('timezoneSelect').value = data.timezone || 'EAT';
    if ($('itemsPerPage')) $('itemsPerPage').value = data.items_per_page || data.itemsPerPage || 15;
    if ($('maintenanceMode')) $('maintenanceMode').checked = !!data.maintenance_mode;
    if ($('allowRegistration')) $('allowRegistration').checked = data.allow_registration !== false;

    const theme = data.theme || 'light';
    document.querySelectorAll('input[name="theme"]').forEach((input) => {
      input.checked = input.value === theme;
    });
  }

  function readForm() {
    return {
      app_name: $('appName')?.value.trim() || 'RDOMMS',
      language: $('appLanguage')?.value || 'en',
      dateFormat: $('dateFormat')?.value || 'DD/MM/YYYY',
      timezone: $('timezoneSelect')?.value || 'EAT',
      itemsPerPage: parseInt($('itemsPerPage')?.value || '15', 10),
      theme: document.querySelector('input[name="theme"]:checked')?.value || 'light',
      maintenanceMode: !!$('maintenanceMode')?.checked,
      allowRegistration: !!$('allowRegistration')?.checked
    };
  }

  async function fetchSettings() {
    const response = await fetch(API, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to load settings');
    }
    return payload.data || {};
  }

  async function saveSettings(data) {
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
      throw new Error(payload?.message || 'Failed to save settings');
    }
    return payload;
  }

  async function init() {
    setLoading(true);
    try {
      const data = await fetchSettings();
      fillForm(data);
      if (window.RDOMMSSettings) {
        RDOMMSSettings.set(data);
      }
    } catch (error) {
      notify(error.message || 'Unable to load settings.', 'error');
    } finally {
      setLoading(false);
    }
  }

  $('saveGeneralBtn')?.addEventListener('click', async () => {
    const payload = readForm();
    const btn = $('saveGeneralBtn');
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    }

    try {
      const result = await saveSettings(payload);
      if (window.RDOMMSSettings && result.data) {
        RDOMMSSettings.set(result.data);
      }
      notify(result.message || 'General settings saved successfully.', 'success');
    } catch (error) {
      notify(error.message || 'Save failed.', 'error');
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save General Settings';
      }
    }
  });

  init();
})();
