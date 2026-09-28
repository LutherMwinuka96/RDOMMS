/**
 * RDOMMS global system settings loader/applier.
 */
(function (global) {
  const CACHE_KEY = 'rdomms_system_settings_cache';

  const defaults = {
    app_name: 'RDOMMS',
    app_version: '2.0.0',
    language: 'en',
    default_language: 'en',
    date_format: 'DD/MM/YYYY',
    dateFormat: 'DD/MM/YYYY',
    timezone: 'EAT',
    items_per_page: 15,
    itemsPerPage: 15,
    theme: 'light',
    maintenance_mode: false,
    allow_registration: true
  };

  let settings = { ...defaults };
  let loaded = false;
  let loadingPromise = null;

  function resolveApiUrl(kind) {
    const path = String(global.location.pathname || '').replace(/\\/g, '/').toLowerCase();
    const prefix = path.includes('/settings/') || path.includes('/users/') || path.includes('/auth/')
      || path.includes('/beneficiaries/') || path.includes('/finance/') || path.includes('/projects/')
      || path.includes('/planning/') || path.includes('/reports/') || path.includes('/notifications/')
      || path.includes('/documents/')
      ? '../api/settings/'
      : 'api/settings/';
    return prefix + (kind === 'public' ? 'public.php' : 'general.php');
  }

  function normalizeSettings(raw) {
    if (!raw || typeof raw !== 'object') return { ...defaults };
    const organization = raw.organization && typeof raw.organization === 'object' ? raw.organization : {};
    return {
      ...defaults,
      ...raw,
      organization,
      items_per_page: Number(raw.items_per_page || raw.itemsPerPage || defaults.items_per_page),
      itemsPerPage: Number(raw.itemsPerPage || raw.items_per_page || defaults.itemsPerPage),
      maintenance_mode: !!(raw.maintenance_mode),
      allow_registration: raw.allow_registration !== false
    };
  }

  function resolveAssetPath(path) {
    if (!path) return '';
    if (/^(https?:|data:)/i.test(path)) return path;
    const pagePath = String(global.location.pathname || '').replace(/\\/g, '/').toLowerCase();
    const prefix = pagePath.includes('/settings/') || pagePath.includes('/users/') || pagePath.includes('/auth/')
      || pagePath.includes('/beneficiaries/') || pagePath.includes('/finance/') || pagePath.includes('/projects/')
      || pagePath.includes('/planning/') || pagePath.includes('/reports/') || pagePath.includes('/notifications/')
      || pagePath.includes('/documents/') ? '../' : '';
    return prefix + String(path).replace(/^\/+/, '');
  }

  function applyOrganization() {
    const org = settings.organization || {};
    global.document.querySelectorAll('[data-rdomms-org-name]').forEach((el) => {
      el.textContent = org.name || 'Rural Development Organization';
    });

    global.document.querySelectorAll('[data-rdomms-org-email]').forEach((el) => {
      if (org.email) {
        if (el.tagName === 'A') {
          el.href = `mailto:${org.email}`;
        }
        el.textContent = org.email;
      }
    });

    global.document.querySelectorAll('[data-rdomms-org-logo]').forEach((el) => {
      const logo = org.logo_url || org.logo;
      if (!logo) return;
      const src = resolveAssetPath(logo);
      if (el.tagName === 'IMG') {
        el.src = src;
        el.style.display = '';
      }
    });

    if (org.name && global.document.title.includes('RDO|MMS')) {
      // Keep product name but expose org in page metadata for exports/reports.
      global.document.documentElement.dataset.orgName = org.name;
    }
  }

  function readCache() {
    try {
      const cached = JSON.parse(global.localStorage.getItem(CACHE_KEY) || 'null');
      if (cached && typeof cached === 'object') {
        settings = normalizeSettings(cached);
        apply();
      }
    } catch (_) {}
  }

  function writeCache(next) {
    try {
      global.localStorage.setItem(CACHE_KEY, JSON.stringify(next));
    } catch (_) {}
  }

  function apply() {
    const root = global.document.documentElement;
    if (!root) return;

    root.lang = settings.language || settings.default_language || 'en';
    root.dataset.theme = settings.theme === 'dark' ? 'dark' : 'light';
    root.dataset.timezone = settings.timezone || 'EAT';
    root.dataset.dateFormat = settings.date_format || settings.dateFormat || 'DD/MM/YYYY';

    global.document.querySelectorAll('[data-rdomms-app-name]').forEach((el) => {
      el.textContent = settings.app_name || 'RDOMMS';
    });

    const banner = global.document.getElementById('maintenanceBanner');
    if (banner) {
      banner.classList.toggle('show', !!settings.maintenance_mode);
    }

    applyOrganization();

    global.dispatchEvent(new CustomEvent('rdommsSettingsApplied', { detail: { ...settings } }));
  }

  async function fetchSettings(kind) {
    const response = await fetch(resolveApiUrl(kind), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: kind === 'public' ? 'same-origin' : 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || (payload.status !== 'success' && payload.success !== true)) {
      throw new Error(payload?.message || 'Failed to load system settings');
    }
    return normalizeSettings(payload.data || {});
  }

  async function load(options) {
    if (loadingPromise && !options?.force) {
      return loadingPromise;
    }

    loadingPromise = (async () => {
      readCache();
      const kind = options?.public ? 'public' : 'authenticated';
      try {
        settings = await fetchSettings(kind);
        writeCache(settings);
        apply();
        loaded = true;
      } catch (_) {
        apply();
      }
      return settings;
    })();

    return loadingPromise;
  }

  function set(next) {
    settings = normalizeSettings(next);
    writeCache(settings);
    apply();
    loaded = true;
    return settings;
  }

  function get(key, fallback) {
    if (Object.prototype.hasOwnProperty.call(settings, key)) {
      return settings[key];
    }
    return fallback;
  }

  function getItemsPerPage() {
    const value = Number(settings.items_per_page || settings.itemsPerPage || 15);
    return Number.isFinite(value) ? Math.max(5, Math.min(100, value)) : 15;
  }

  function formatDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);

    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = String(date.getFullYear());
    const pattern = settings.date_format || settings.dateFormat || 'DD/MM/YYYY';

    if (pattern === 'MM/DD/YYYY') {
      return `${month}/${day}/${year}`;
    }
    if (pattern === 'YYYY-MM-DD') {
      return `${year}-${month}-${day}`;
    }
    return `${day}/${month}/${year}`;
  }

  function formatDateTime(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return `${formatDate(value)} ${date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
  }

  global.RDOMMSSettings = {
    load,
    loadPublic() {
      return load({ public: true });
    },
    loadAuthenticated() {
      return load({ public: false });
    },
    set,
    get,
    apply,
    formatDate,
    formatDateTime,
    getItemsPerPage,
    getOrganization() {
      return { ...(settings.organization || {}) };
    },
    getOrganizationName(fallback) {
      return settings.organization?.name || fallback || 'Rural Development Organization';
    },
    all() {
      return { ...settings };
    },
    isLoaded() {
      return loaded;
    }
  };

  readCache();

  global.document.addEventListener('DOMContentLoaded', () => {
    const path = String(global.location.pathname || '').replace(/\\/g, '/').toLowerCase();
    const isPublicAuth = /\/auth\/(signin|signup|forgot_password|reset_password)\.(html|php)$/.test(path);
    if (isPublicAuth) {
      load({ public: true });
      return;
    }
    if (!/\.html$|\.php$/.test(path) && path.endsWith('/rdomms')) {
      load({ public: false });
      return;
    }
    if (path.includes('rdomms.html') || path.endsWith('/rdomms') || path.includes('/settings/')
      || path.includes('/users/') || path.includes('/beneficiaries/') || path.includes('/finance/')
      || path.includes('/projects/') || path.includes('/planning/') || path.includes('/reports/')
      || path.includes('/notifications/') || path.includes('/documents/')) {
      load({ public: false });
    }
  });
})(window);
