/**
 * RDOMMS Notification Settings — database-backed system preferences.
 */
(function () {
  const PAGE = window.RDOMMS_NOTIFICATION_SETTINGS || {};
  const API = PAGE.apiSettings || '../api/settings/notification-preferences.php';

  const TOGGLE_IDS = [
    'emailToggle',
    'pushToggle',
    'smsToggle',
    'projectStatusToggle',
    'budgetToggle',
    'userRoleToggle',
    'maintenanceToggle',
    'approvalToggle',
    'systemAlertsToggle'
  ];

  const FIELD_MAP = {
    emailToggle: 'email',
    pushToggle: 'push',
    smsToggle: 'sms',
    projectStatusToggle: 'projectStatus',
    budgetToggle: 'budgetAlert',
    userRoleToggle: 'userRole',
    maintenanceToggle: 'systemMaintenance',
    approvalToggle: 'approvalRequests',
    systemAlertsToggle: 'systemAlerts'
  };

  function $(id) {
    return document.getElementById(id);
  }

  function setLoading(active) {
    $('pageLoading')?.classList.toggle('hidden', !active);
  }

  function notify(msg, type) {
    if (window.RDOMMSToast) {
      RDOMMSToast.show(msg, type || 'info', {
        title: 'Notification Settings',
        duration: 4500,
        allowMultiple: true
      });
    } else {
      alert(msg);
    }
  }

  function setToggle(id, active) {
    const el = $(id);
    if (!el) return;
    el.classList.toggle('active', !!active);
    el.setAttribute('aria-checked', active ? 'true' : 'false');
  }

  function getToggle(id) {
    return $(id)?.classList.contains('active') || false;
  }

  function fillForm(data) {
    TOGGLE_IDS.forEach((id) => {
      const key = FIELD_MAP[id];
      if (key) setToggle(id, !!data[key]);
    });

    if ($('digestEnabled')) {
      $('digestEnabled').checked = !!data.digestEnabled;
    }
    if ($('digestFrequency')) {
      $('digestFrequency').value = data.digestFrequency || 'daily';
    }
  }

  function readForm() {
    const payload = {
      digestEnabled: !!$('digestEnabled')?.checked,
      digestFrequency: $('digestFrequency')?.value || 'daily'
    };

    TOGGLE_IDS.forEach((id) => {
      const key = FIELD_MAP[id];
      if (key) payload[key] = getToggle(id);
    });

    return payload;
  }

  async function fetchSettings() {
    const response = await fetch(API, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      const fallback = response.status === 401
        ? 'Please sign in again to manage notification settings.'
        : response.status === 403
          ? 'You do not have permission to manage notification settings.'
          : 'Failed to load notification settings';
      throw new Error(payload?.message || fallback);
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
      throw new Error(payload?.message || 'Failed to save notification settings');
    }
    return payload;
  }

  function bindToggles() {
    TOGGLE_IDS.forEach((id) => {
      const el = $(id);
      if (!el || el.dataset.bound === '1') return;
      el.dataset.bound = '1';
      el.setAttribute('role', 'switch');
      el.setAttribute('tabindex', '0');
      el.addEventListener('click', () => {
        setToggle(id, !getToggle(id));
      });
      el.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          setToggle(id, !getToggle(id));
        }
      });
    });
  }

  async function init() {
    bindToggles();
    setLoading(true);
    try {
      const data = await fetchSettings();
      fillForm(data);
    } catch (error) {
      notify(error.message || 'Unable to load notification settings.', 'error');
    } finally {
      setLoading(false);
    }
  }

  $('saveNotificationBtn')?.addEventListener('click', async () => {
    const payload = readForm();
    const btn = $('saveNotificationBtn');
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    }

    try {
      const result = await saveSettings(payload);
      if (result.data) fillForm(result.data);
      notify(result.message || 'Notification settings saved successfully.', 'success');
    } catch (error) {
      notify(error.message || 'Save failed.', 'error');
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Notification Settings';
      }
    }
  });

  init();
})();
