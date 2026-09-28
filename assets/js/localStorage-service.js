/**
 * DB-backed storage bridge for RDOMMS.
 *
 * Business data is cached in memory from the database and exposed through the
 * same Storage API so legacy pages can keep calling localStorage.getItem/setItem
 * without persisting business records in the browser.
 */

(function () {
    const path = String(window.location.pathname || '').replace(/\\/g, '/');
    const moduleNames = ['auth', 'beneficiaries', 'documents', 'finance', 'notifications', 'planning', 'projects', 'reports', 'settings', 'users'];
    for (let i = 0; i < moduleNames.length; i += 1) {
        const mod = moduleNames[i];
        const pattern = new RegExp('/' + mod + '/([a-z0-9_-]+)\\.html$', 'i');
        if (pattern.test(path)) {
            window.location.replace(path.replace(/\.html$/i, '.php') + window.location.search + window.location.hash);
            return;
        }
    }
})();

(function (global) {
  let csrfToken = null;
  let csrfPromise = null;
  const nativeFetch = global.fetch.bind(global);

  function getAppRootFromScript() {
    const el = document.querySelector('script[src*="localStorage-service.js"], script[src*="rdomms-security.js"]');
    if (!el) return '';
    const src = el.getAttribute('src') || '';
    const match = src.match(/^(.*)assets\/js\//);
    return match ? match[1] : '';
  }

  function resolveApiUrl(relativePath) {
    const root = getAppRootFromScript();
    return `${root}${relativePath.replace(/^\//, '')}`;
  }

  function isMutationMethod(method) {
    const value = String(method || 'GET').toUpperCase();
    return ['POST', 'PUT', 'DELETE', 'PATCH'].includes(value);
  }

  function isRdommsApiRequest(requestUrl) {
    const url = String(requestUrl || '');
    return /\/api\//i.test(url) || /(^|[/])api\.php([?&#]|$)/i.test(url);
  }

  function ensureCsrfTokenSync() {
    if (csrfToken) {
      return csrfToken;
    }

    const xhr = new XMLHttpRequest();
    xhr.open('GET', resolveApiUrl('api/auth/csrf.php'), false);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.withCredentials = true;

    try {
      xhr.send();
    } catch (error) {
      return null;
    }

    if (xhr.status < 200 || xhr.status >= 300) {
      return null;
    }

    try {
      const payload = JSON.parse(xhr.responseText || '{}');
      if (payload && payload.status === 'success' && payload.data?.token) {
        csrfToken = payload.data.token;
      }
    } catch (error) {
      return null;
    }

    return csrfToken;
  }

  function getCsrfTokenSync() {
    return csrfToken || ensureCsrfTokenSync();
  }

  function invalidateCsrfToken() {
    csrfToken = null;
    csrfPromise = null;
  }

  async function ensureCsrfToken(forceRefresh = false) {
    if (forceRefresh) {
      invalidateCsrfToken();
    }
    if (!forceRefresh && csrfToken) return csrfToken;
    if (!forceRefresh && csrfPromise) return csrfPromise;

    csrfPromise = nativeFetch(resolveApiUrl('api/auth/csrf.php'), {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(async (response) => {
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload || payload.status !== 'success') {
          throw new Error(payload?.message || 'Unable to fetch CSRF token');
        }
        csrfToken = payload.data?.token || null;
        if (!csrfToken) {
          throw new Error('Unable to fetch CSRF token');
        }
        return csrfToken;
      })
      .finally(() => { csrfPromise = null; });

    return csrfPromise;
  }

  async function rdommsFetch(url, options = {}) {
    const fetchOptions = { ...options };
    const method = fetchOptions.method || 'GET';
    const headers = new Headers(fetchOptions.headers || {});

    if (!headers.has('X-Requested-With')) {
      headers.set('X-Requested-With', 'XMLHttpRequest');
    }

    if (isMutationMethod(method)) {
      const token = await ensureCsrfToken();
      if (token) {
        headers.set('X-CSRF-Token', token);
      }
    }

    fetchOptions.headers = headers;
    fetchOptions.credentials = fetchOptions.credentials || 'same-origin';

    const response = await nativeFetch(url, fetchOptions);
    if (response.status === 403 && isMutationMethod(method)) {
      const payload = await response.clone().json().catch(() => null);
      if (payload?.message && String(payload.message).toLowerCase().includes('csrf')) {
        invalidateCsrfToken();
        const retryHeaders = new Headers(fetchOptions.headers);
        const freshToken = await ensureCsrfToken(true);
        retryHeaders.set('X-CSRF-Token', freshToken);
        return nativeFetch(url, { ...fetchOptions, headers: retryHeaders });
      }
    }

    return response;
  }

  if (!global.fetch || !global.fetch.__rdommsPatched) {
    const originalFetch = global.fetch.bind(global);
    global.fetch = function patchedFetch(url, options) {
      const requestUrl = typeof url === 'string' ? url : (url && url.url ? url.url : '');
      if (!requestUrl || !isRdommsApiRequest(requestUrl)) {
        return originalFetch(url, options);
      }
      return rdommsFetch(requestUrl, options);
    };
    global.fetch.__rdommsPatched = true;
  }

  function patchXmlHttpRequest() {
    if (!global.XMLHttpRequest || global.XMLHttpRequest.__rdommsPatched) {
      return;
    }

    const NativeXHR = global.XMLHttpRequest;
    function PatchedXHR() {
      const xhr = new NativeXHR();
      let method = 'GET';
      let requestUrl = '';
      const nativeOpen = xhr.open;
      const nativeSend = xhr.send;

      xhr.open = function open(methodArg, url, ...rest) {
        method = String(methodArg || 'GET').toUpperCase();
        requestUrl = String(url || '');
        return nativeOpen.call(xhr, methodArg, url, ...rest);
      };

      xhr.send = function send(body) {
        if (isMutationMethod(method) && isRdommsApiRequest(requestUrl)) {
          const token = getCsrfTokenSync();
          if (token) {
            try {
              xhr.setRequestHeader('X-CSRF-Token', token);
            } catch (error) {
              // ignore
            }
          }
        }
        return nativeSend.call(xhr, body);
      };

      return xhr;
    }

    PatchedXHR.__rdommsPatched = true;
    global.XMLHttpRequest = PatchedXHR;
  }

  patchXmlHttpRequest();

  if (!global.RDOMMSSecurity) {
    global.RDOMMSSecurity = {
      ensureCsrfToken,
      ensureCsrfTokenSync,
      getCsrfTokenSync,
      invalidateCsrfToken,
      rdommsFetch,
      resolveApiUrl,
      patchXmlHttpRequest
    };
  } else if (typeof global.RDOMMSSecurity.patchXmlHttpRequest === 'function') {
    global.RDOMMSSecurity.patchXmlHttpRequest();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => { ensureCsrfToken().catch(() => {}); });
  } else {
    ensureCsrfToken().catch(() => {});
  }
})(window);

(function (global) {
  let monitorStarted = false;
  let expiresAtMs = 0;
  let warnSeconds = 60;
  let idleSecondsTotal = 600;
  let warnShown = false;
  let sessionConfirmed = false;
  let countdownTimer = null;
  let statusTimer = null;
  let lastPingAt = 0;
  let countdownEl = null;
  let countdownValueEl = null;
  let countdownProgressEl = null;
  let countdownStylesLoaded = false;
  let activityHandler = null;
  let activityEventsBound = false;

  const SESSION_RING_R = 13;
  const SESSION_RING_C = 2 * Math.PI * SESSION_RING_R;

  const SESSION_COUNTDOWN_INLINE_CSS = [
    '.rdomms-session-countdown{display:inline-flex;align-items:center;gap:7px;height:34px;padding:0 10px 0 6px;border-radius:999px;border:1px solid rgba(21,128,61,.18);background:#fff;color:#14532d;cursor:pointer;user-select:none;flex-shrink:0;box-shadow:0 1px 4px rgba(15,23,42,.06)}',
    '.rdomms-session-countdown[hidden]{display:none!important}',
    '.rdomms-session-countdown--floating{position:fixed;top:12px;right:12px;z-index:1300;box-shadow:0 8px 24px rgba(15,23,42,.12)}',
    '.rdomms-session-countdown__ring{width:26px;height:26px;flex-shrink:0;display:block}',
    '.rdomms-session-countdown__ring svg{width:100%;height:100%;display:block;transform:rotate(-90deg)}',
    '.rdomms-session-countdown__track{fill:none;stroke:rgba(21,128,61,.14);stroke-width:2.5}',
    '.rdomms-session-countdown__progress{fill:none;stroke:#16a34a;stroke-width:2.5;stroke-linecap:round}',
    '.rdomms-session-countdown__time{font-size:12px;font-weight:700;font-variant-numeric:tabular-nums;letter-spacing:.04em;color:#0f172a;line-height:1;min-width:38px}',
    '.rdomms-session-countdown--warn{border-color:rgba(245,158,11,.35);background:#fffbeb}',
    '.rdomms-session-countdown--warn .rdomms-session-countdown__progress{stroke:#d97706}',
    '.rdomms-session-countdown--warn .rdomms-session-countdown__time{color:#92400e}',
    '.rdomms-session-countdown--critical{border-color:rgba(239,68,68,.4);background:#fef2f2}',
    '.rdomms-session-countdown--critical .rdomms-session-countdown__progress{stroke:#dc2626}',
    '.rdomms-session-countdown--critical .rdomms-session-countdown__time{color:#991b1b}',
    '.rdomms-session-countdown--on-dark{border-color:rgba(255,255,255,.18);background:rgba(255,255,255,.1)}',
    '.rdomms-session-countdown--on-dark .rdomms-session-countdown__progress{stroke:#86efac}',
    '.rdomms-session-countdown--on-dark .rdomms-session-countdown__time{color:#fff}'
  ].join('');

  function resolveAssetUrl(relativePath) {
    const el = document.querySelector('script[src*="localStorage-service.js"]');
    const src = el ? (el.getAttribute('src') || '') : '';
    const match = src.match(/^(.*)assets\/js\//);
    const root = match ? match[1] : '';
    const cleaned = String(relativePath || '').replace(/^assets\//, '');
    return `${root}assets/${cleaned}`;
  }

  function ensureSessionCountdownStyles() {
    if (!document.getElementById('rdomms-session-countdown-inline')) {
      const style = document.createElement('style');
      style.id = 'rdomms-session-countdown-inline';
      style.textContent = SESSION_COUNTDOWN_INLINE_CSS;
      document.head.appendChild(style);
    }
    if (countdownStylesLoaded || document.getElementById('rdomms-session-countdown-css')) {
      countdownStylesLoaded = true;
      return;
    }
    const link = document.createElement('link');
    link.id = 'rdomms-session-countdown-css';
    link.rel = 'stylesheet';
    link.href = resolveAssetUrl('css/rdomms-session-countdown.css?v=4');
    document.head.appendChild(link);
    countdownStylesLoaded = true;
  }

  function formatSessionCountdown(seconds) {
    const total = Math.max(0, Math.floor(Number(seconds) || 0));
    const mins = Math.floor(total / 60);
    const secs = total % 60;
    return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
  }

  function findSessionCountdownMount() {
    return document.querySelector('.topbar-right')
      || document.querySelector('.rpn-actions')
      || document.querySelector('.topbar');
  }

  function ensureSessionCountdownUi() {
    if (isSessionAuthPage()) return null;

    ensureSessionCountdownStyles();

    if (!countdownEl) {
      countdownEl = document.createElement('button');
      countdownEl.type = 'button';
      countdownEl.id = 'rdommsSessionCountdown';
      countdownEl.className = 'rdomms-session-countdown';
      countdownEl.setAttribute('role', 'status');
      countdownEl.setAttribute('aria-live', 'polite');
      countdownEl.title = 'Tap to stay signed in';
      countdownEl.innerHTML = `
        <span class="rdomms-session-countdown__ring" aria-hidden="true">
          <svg viewBox="0 0 32 32" focusable="false" aria-hidden="true">
            <circle class="rdomms-session-countdown__track" cx="16" cy="16" r="${SESSION_RING_R}"></circle>
            <circle class="rdomms-session-countdown__progress" cx="16" cy="16" r="${SESSION_RING_R}"></circle>
          </svg>
        </span>
        <span class="rdomms-session-countdown__time" id="rdommsSessionCountdownValue">--:--</span>
      `;
      countdownProgressEl = countdownEl.querySelector('.rdomms-session-countdown__progress');
      if (countdownProgressEl) {
        countdownProgressEl.style.strokeDasharray = String(SESSION_RING_C);
        countdownProgressEl.style.strokeDashoffset = '0';
      }
      countdownEl.addEventListener('click', () => {
        extendSession().then((ok) => {
          if (ok && global.RDOMMSToast) {
            global.RDOMMSToast.show('Session extended.', 'success', {
              title: 'Session',
              duration: 2000,
              allowMultiple: true
            });
          }
        });
      });
    }

    const mount = findSessionCountdownMount();
    if (mount) {
      countdownEl.classList.remove('rdomms-session-countdown--floating');
      countdownEl.classList.toggle('rdomms-session-countdown--on-dark', mount.classList.contains('rpn-actions'));
      if (!mount.contains(countdownEl)) {
        if (mount.classList.contains('topbar-right') || mount.classList.contains('rpn-actions')) {
          mount.insertBefore(countdownEl, mount.firstChild);
        } else {
          mount.appendChild(countdownEl);
        }
      }
    } else if (!countdownEl.parentElement) {
      countdownEl.classList.add('rdomms-session-countdown--floating');
      countdownEl.classList.remove('rdomms-session-countdown--on-dark');
      document.body.appendChild(countdownEl);
    }

    countdownValueEl = countdownEl.querySelector('.rdomms-session-countdown__time');
    if (!countdownProgressEl) {
      countdownProgressEl = countdownEl.querySelector('.rdomms-session-countdown__progress');
    }
    return countdownEl;
  }

  function hideSessionCountdownUi() {
    if (countdownEl) {
      countdownEl.hidden = true;
    }
  }

  function updateSessionCountdownUi() {
    if (isSessionAuthPage() || !global.__RDOMMS_SERVER_SESSION_VALID__) {
      hideSessionCountdownUi();
      return;
    }

    if (!expiresAtMs) {
      return;
    }

    const el = ensureSessionCountdownUi();
    if (!el || !countdownValueEl) return;

    const remaining = sessionSecondsRemaining();
    if (remaining === null) {
      el.hidden = true;
      return;
    }

    el.hidden = false;
    const display = formatSessionCountdown(remaining);
    countdownValueEl.textContent = display;
    el.setAttribute('aria-label', `${display} remaining. Tap to stay signed in.`);

    const total = idleSecondsTotal || 600;
    const ratio = Math.max(0, Math.min(1, remaining / total));
    if (countdownProgressEl) {
      countdownProgressEl.style.strokeDashoffset = String(SESSION_RING_C * (1 - ratio));
    }

    el.classList.toggle('rdomms-session-countdown--warn', remaining > 0 && remaining <= warnSeconds);
    el.classList.toggle('rdomms-session-countdown--critical', remaining > 0 && remaining <= 30);
  }

  function tickSessionMonitor() {
    if (countdownEl && countdownEl.classList.contains('rdomms-session-countdown--floating') && findSessionCountdownMount()) {
      ensureSessionCountdownUi();
    }

    const remaining = sessionSecondsRemaining();
    if (remaining !== null && remaining <= 0) {
      handleSessionExpired();
      return;
    }

    updateSessionCountdownUi();
    maybeWarnSessionExpiry();
  }

  function resolveSessionApiUrl(relativePath) {
    if (global.RDOMMSSecurity && typeof global.RDOMMSSecurity.resolveApiUrl === 'function') {
      return global.RDOMMSSecurity.resolveApiUrl(relativePath);
    }
    const el = document.querySelector('script[src*="localStorage-service.js"]');
    const src = el ? (el.getAttribute('src') || '') : '';
    const match = src.match(/^(.*)assets\/js\//);
    const root = match ? match[1] : '';
    return `${root}${String(relativePath).replace(/^\//, '')}`;
  }

  function getSessionLoginUrl() {
    if (global.RDOMMSRBAC && typeof global.RDOMMSRBAC.getLoginPage === 'function') {
      return global.RDOMMSRBAC.getLoginPage();
    }
    return resolveSessionApiUrl('auth/signin.php');
  }

  const SESSION_EXPIRY_LS_KEY = 'rdomms_session_expires_at_ms';
  const SESSION_ANCHOR_FLAG = 'rdomms_anchor_session_on_dashboard';

  function persistSessionExpiryMs(ms) {
    if (!Number.isFinite(ms) || ms <= 0) return;
    try { global.localStorage.setItem(SESSION_EXPIRY_LS_KEY, String(Math.floor(ms))); } catch (_) {}
  }

  function loadPersistedSessionExpiryMs() {
    try {
      const value = Number(global.localStorage.getItem(SESSION_EXPIRY_LS_KEY));
      return Number.isFinite(value) && value > 0 ? value : null;
    } catch (_) {
      return null;
    }
  }

  function clearPersistedSessionExpiry() {
    try { global.localStorage.removeItem(SESSION_EXPIRY_LS_KEY); } catch (_) {}
  }

  function isDashboardAppPage() {
    const path = String(global.location.pathname || '').replace(/\\/g, '/').toLowerCase();
    if (isSessionAuthPage()) return false;
    if (/(^|\/)index\.(html|php)$/i.test(path)) return false;
    return true;
  }

  async function maybeAnchorDashboardSession() {
    if (!isDashboardAppPage()) return;
    try {
      if (global.sessionStorage.getItem(SESSION_ANCHOR_FLAG) !== '1') return;
      global.sessionStorage.removeItem(SESSION_ANCHOR_FLAG);
      await extendSession();
    } catch (_) {}
  }

  function isSessionAuthPage() {
    const path = String(global.location.pathname || '').toLowerCase();
    return /\/auth\/(signin|signup|forgot_password|reset_password)\.(html|php)$/i.test(path)
      || global.RDOMMS_LANDING_PAGE === true;
  }

  function clearSessionAuth() {
    ['currentUser', 'isLoggedIn', 'loginTime', 'sessionToken'].forEach((key) => {
      try { global.localStorage.removeItem(key); } catch (_) {}
    });
    try { global.sessionStorage.removeItem('rdomms_session_meta'); } catch (_) {}
    try { global.sessionStorage.removeItem(SESSION_ANCHOR_FLAG); } catch (_) {}
    clearPersistedSessionExpiry();
  }

  function parseSessionExpiry(meta) {
    if (!meta || typeof meta !== 'object') return null;

    const unixExpiry = Number(meta.expires_at_unix);
    if (Number.isFinite(unixExpiry) && unixExpiry > 0) {
      return unixExpiry * 1000;
    }

    if (meta.expires_at_iso) {
      const isoParsed = Date.parse(meta.expires_at_iso);
      if (!Number.isNaN(isoParsed)) return isoParsed;
    }

    if (meta.expires_at) {
      const normalized = String(meta.expires_at).trim().replace(' ', 'T');
      const parsed = Date.parse(normalized);
      if (!Number.isNaN(parsed)) return parsed;
    }

    const seconds = Number(meta.seconds_remaining);
    const receivedAt = Number(meta._received_at || Date.now());
    if (Number.isFinite(seconds) && seconds >= 0) {
      return receivedAt + (seconds * 1000);
    }

    return null;
  }

  function applySessionMeta(meta, allowExtend = false) {
    if (!meta) return;
    warnSeconds = Number(meta.warn_seconds) || 60;
    idleSecondsTotal = Number(meta.idle_seconds)
      || (Number(meta.idle_minutes) || 10) * 60;

    const enriched = { ...meta, _received_at: Date.now() };
    const parsed = parseSessionExpiry(enriched);
    if (!parsed) return;

    // Server session expiry is authoritative (avoids premature logout from stale localStorage).
    const nextExpiry = parsed;

    expiresAtMs = nextExpiry;
    warnShown = false;
    sessionConfirmed = true;
    persistSessionExpiryMs(expiresAtMs);
    try { global.sessionStorage.setItem('rdomms_session_meta', JSON.stringify(enriched)); } catch (_) {}
  }

  function stopSessionMonitor() {
    if (statusTimer) global.clearInterval(statusTimer);
    if (countdownTimer) global.clearInterval(countdownTimer);
    statusTimer = null;
    countdownTimer = null;
    monitorStarted = false;
    sessionConfirmed = false;
    expiresAtMs = 0;
    warnShown = false;
    hideSessionCountdownUi();
    unbindSessionActivity();
  }

  function sessionSecondsRemaining() {
    if (!expiresAtMs) return null;
    return Math.max(0, Math.floor((expiresAtMs - Date.now()) / 1000));
  }

  function handleSessionExpired() {
    stopSessionMonitor();
    clearSessionAuth();

    if (isSessionAuthPage()) {
      return;
    }

    try { global.sessionStorage.setItem('rdomms_show_expired_toast', '1'); } catch (_) {}

    const loginUrl = getSessionLoginUrl();
    const separator = loginUrl.includes('?') ? '&' : '?';
    global.location.replace(`${loginUrl}${separator}expired=1`);
  }

  async function extendSession() {
    if (isSessionAuthPage() || !global.__RDOMMS_SERVER_SESSION_VALID__) {
      return false;
    }

    try {
      const response = await global.fetch(resolveSessionApiUrl('api/auth/session-ping.php'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload || payload.status !== 'success') {
        if (response.status === 401 || payload?.code === 'SESSION_EXPIRED') {
          if (!isSessionAuthPage()) {
            handleSessionExpired();
          }
        }
        return false;
      }
      sessionConfirmed = true;
      applySessionMeta(payload.data?.session || null, true);
      warnShown = false;
      lastPingAt = Date.now();
      updateSessionCountdownUi();
      return true;
    } catch (_) {
      return false;
    }
  }

  async function checkSessionStatus() {
    if (isSessionAuthPage() || !global.__RDOMMS_SERVER_SESSION_VALID__) {
      stopSessionMonitor();
      return false;
    }

    try {
      const response = await global.fetch(resolveSessionApiUrl('api/auth/session-status.php'), {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload || payload.status !== 'success') {
        if (response.status === 401 || payload?.code === 'SESSION_EXPIRED') {
          sessionConfirmed = false;
          if (!isSessionAuthPage()) {
            handleSessionExpired();
          }
        }
        return false;
      }
      sessionConfirmed = true;
      applySessionMeta(payload.data?.session || null);
      updateSessionCountdownUi();
      return true;
    } catch (_) {
      return false;
    }
  }

  function maybeWarnSessionExpiry() {
    if (!sessionConfirmed || isSessionAuthPage() || !global.__RDOMMS_SERVER_SESSION_VALID__) {
      return;
    }
    const remaining = sessionSecondsRemaining();
    if (remaining === null) {
      return;
    }
    if (remaining <= 0) {
      checkSessionStatus();
      return;
    }
    if (remaining <= warnSeconds && !warnShown) {
      warnShown = true;
      const text = `Session ends in ${formatSessionCountdown(remaining)}. Move the mouse or tap the timer to stay signed in.`;
      if (global.RDOMMSToast) {
        global.RDOMMSToast.show(text, 'warning', {
          title: 'Session expiring',
          duration: Math.min(remaining * 1000, 30000),
          actionLabel: 'Stay signed in',
          onAction: () => { extendSession(); }
        });
      }
    }
  }

  function scheduleSessionStatusChecks() {
    if (statusTimer) global.clearInterval(statusTimer);
    if (global.navigator && global.navigator.onLine === false) {
      return;
    }
    statusTimer = global.setInterval(() => { checkSessionStatus(); }, 10000);
  }

  function startSessionCountdownTicker() {
    if (!monitorStarted || !expiresAtMs || expiresAtMs <= Date.now()) {
      return;
    }
    sessionConfirmed = true;
    updateSessionCountdownUi();
    if (countdownTimer) global.clearInterval(countdownTimer);
    countdownTimer = global.setInterval(tickSessionMonitor, 1000);
  }

  function bindSessionActivity() {
    if (activityEventsBound) return;
    activityEventsBound = true;

    const events = ['mousedown', 'keydown', 'touchstart', 'scroll', 'click'];
    let scheduled = false;
    activityHandler = () => {
      if (!monitorStarted || isSessionAuthPage() || !global.__RDOMMS_SERVER_SESSION_VALID__) {
        return;
      }
      if (scheduled) return;
      scheduled = true;
      global.setTimeout(() => {
        scheduled = false;
        const idleMs = Math.max(60000, (idleSecondsTotal || 600) * 1000);
        const pingGapMs = Math.min(45000, Math.max(15000, Math.floor(idleMs / 4)));
        if (Date.now() - lastPingAt < pingGapMs) return;
        lastPingAt = Date.now();
        extendSession();
      }, 500);
    };

    events.forEach((name) => {
      document.addEventListener(name, activityHandler, { passive: true });
    });
  }

  function unbindSessionActivity() {
    if (!activityEventsBound || !activityHandler) return;
    ['mousedown', 'keydown', 'touchstart', 'scroll', 'click'].forEach((name) => {
      document.removeEventListener(name, activityHandler);
    });
    activityHandler = null;
    activityEventsBound = false;
  }

  function bindSessionConnectivity() {
    if (global.__rdommsSessionConnectivityBound) return;
    global.__rdommsSessionConnectivityBound = true;
    global.addEventListener('online', () => {
      if (!monitorStarted) return;
      scheduleSessionStatusChecks();
      checkSessionStatus();
    });
    global.addEventListener('offline', () => {
      if (statusTimer) global.clearInterval(statusTimer);
      statusTimer = null;
      if (expiresAtMs > Date.now()) {
        startSessionCountdownTicker();
      }
    });
  }

  function startSessionMonitor(meta) {
    if (monitorStarted || isSessionAuthPage() || !global.__RDOMMS_SERVER_SESSION_VALID__) {
      return;
    }
    const user = global.RDOMMSRBAC && global.RDOMMSRBAC.getCurrentUser
      ? global.RDOMMSRBAC.getCurrentUser()
      : null;
    if (!user) return;

    monitorStarted = true;
    sessionConfirmed = false;

    const persisted = loadPersistedSessionExpiryMs();
    if (persisted && persisted > Date.now()) {
      expiresAtMs = persisted;
      sessionConfirmed = true;
    }

    if (meta) {
      applySessionMeta(meta, false);
    } else {
      try {
        const raw = global.sessionStorage.getItem('rdomms_session_meta');
        if (raw) {
          applySessionMeta(JSON.parse(raw), false);
        }
      } catch (_) {}
    }

    if (expiresAtMs && expiresAtMs <= Date.now()) {
      handleSessionExpired();
      return;
    }

    bindSessionConnectivity();
    bindSessionActivity();
    scheduleSessionStatusChecks();

    if (!global.__rdommsSessionStorageBound) {
      global.__rdommsSessionStorageBound = true;
      global.addEventListener('storage', (event) => {
        if (event.key !== SESSION_EXPIRY_LS_KEY || !event.newValue) return;
        const ms = Number(event.newValue);
        if (!Number.isFinite(ms) || ms <= Date.now()) return;
        expiresAtMs = ms;
        updateSessionCountdownUi();
      });
    }

    const bootMonitor = async () => {
      await maybeAnchorDashboardSession();
      if (global.navigator && global.navigator.onLine !== false) {
        await checkSessionStatus();
      }

      if (!monitorStarted) return;

      if (expiresAtMs && expiresAtMs <= Date.now()) {
        handleSessionExpired();
        return;
      }

      startSessionCountdownTicker();
    };

    bootMonitor();
  }

  global.RDOMMSSession = {
    start: startSessionMonitor,
    stop: stopSessionMonitor,
    extendSession,
    handleExpired: handleSessionExpired,
    getSecondsRemaining: sessionSecondsRemaining,
    formatCountdown: formatSessionCountdown,
    getIdleSeconds: () => idleSecondsTotal
  };
})(window);

(function () {
    const originalGetItem = Storage.prototype.getItem;
    const originalSetItem = Storage.prototype.setItem;
    const originalRemoveItem = Storage.prototype.removeItem;
    const originalClear = Storage.prototype.clear;

    const PREFIX = 'rdomms_';
    const directTables = new Set([
        'users',
        'departments',
        'beneficiaries',
        'budgetAllocations',
        'monthlyPlans',
        'quarterlyPlans',
        'annualPlans',
        'scheduledReports',
        'reportHistory',
        'services',
        'notifications',
        'system_alerts',
        'user_activities',
        'expenses'
    ]);

    /** Project lists are managed only via api/projects.php — never bulk-sync from browser cache. */
    const projectCacheKeys = new Set(['projects', 'rd_projects']);

    const authKeys = new Set(['currentUser', 'isLoggedIn', 'sessionToken', 'loginTime']);
    const genericPrefixes = ['rdomms_', 'Planify_Template_', 'backup_', 'setting_'];
    const genericKeys = new Set([
        'activities',
        'weeklyPlans',
        'dashboardDateRange',
        'targets',
        'progressHistory',
        'evidenceList',
        'projectDraft',
        'quarterlyPlanDrafts',
        'annualPlanDrafts',
        'monthlyPlanDrafts',
        'weeklyPlans',
        'monthlyPlansDraft',
        'annualPlansDraft',
        'quarterlyPlansDraft'
    ]);

    const canonicalAliases = {
        rd_projects: 'projects',
        rdomms_users: 'users',
        rdomms_projects: 'projects',
        rdomms_rd_projects: 'projects',
        rdomms_notifications: 'notifications',
        rdomms_system_alerts: 'system_alerts',
        rdomms_activity_logs: 'user_activities',
        activity_logs: 'user_activities',
        rdomms_expenses: 'expenses',
        rdomms_budgetAllocations: 'budgetAllocations',
        rdomms_monthlyPlans: 'monthlyPlans',
        rdomms_quarterlyPlans: 'quarterlyPlans',
        rdomms_annualPlans: 'annualPlans',
        rdomms_beneficiaries: 'beneficiaries'
    };

    const dbCache = Object.create(null);
    const authCache = Object.create(null);
    let dbReady = false;
    let authReady = false;

    const PUBLIC_PATHS = [
        /(^|\/)index\.html$/i,
        /(^|\/)index\.php$/i,
        /(^|\/)auth\/signin\.(html|php)$/i,
        /(^|\/)auth\/signup\.(html|php)$/i,
        /(^|\/)auth\/forgot_password\.(html|php)$/i,
        /(^|\/)auth\/reset_password\.(html|php)$/i,
        /(^|\/)auth\/verify_code\.html$/i,
    ];

    const PATH_RULES = [
        { test: /\/finance\/project-budget-approvals/i, roles: ['admin', 'accountant', 'head_of_organization_center', 'head_of_department'] },
        { test: /\/finance\/budget-allocation/i, roles: ['accountant'] },
        { test: /\/finance\/expense-tracking/i, roles: ['accountant'] },
        { test: /\/finance\/financial-reports/i, roles: ['admin', 'accountant'] },
        { test: /\/finance\//i, roles: ['admin', 'accountant'] },
        { test: /\/users\/role-management/i, roles: ['admin'] },
        { test: /\/users\/add-user/i, roles: ['admin', 'human_resource_manager'] },
        { test: /\/users\/all-users/i, roles: ['admin', 'human_resource_manager', 'head_of_organization_center'] },
        { test: /\/users\/activity-log/i, roles: ['admin', 'head_of_organization_center', 'head_of_department', 'field_officer', 'staff', 'human_resource_manager', 'accountant'] },
        { test: /\/settings\/data-backup/i, roles: ['admin'] },
        { test: /\/settings\/system-logs/i, roles: ['admin', 'head_of_organization_center'] },
        { test: /\/settings\//i, roles: ['admin', 'head_of_organization_center'] },
        { test: /\/projects\/(all-projects|archived-projects)/i, roles: ['admin', 'head_of_organization_center', 'head_of_department', 'field_officer', 'staff', 'accountant'] },
        { test: /\/projects\/(status-tracking|project-health)/i, roles: ['head_of_organization_center', 'head_of_department', 'field_officer', 'staff', 'accountant'] },
        { test: /\/projects\//i, roles: ['head_of_organization_center', 'head_of_department', 'field_officer', 'staff'] },
        { test: /\/planning\/all-plans/i, roles: ['admin', 'head_of_organization_center', 'head_of_department', 'field_officer'] },
        { test: /\/planning\//i, roles: ['head_of_organization_center', 'head_of_department', 'field_officer'] },
        { test: /\/documents\/project-documents/i, roles: ['head_of_organization_center', 'head_of_department', 'field_officer', 'staff'] },
        { test: /\/beneficiaries\//i, roles: ['admin', 'head_of_organization_center', 'head_of_department', 'field_officer', 'staff'] },
        { test: /\/notifications\//i, roles: ['admin', 'head_of_organization_center', 'head_of_department', 'human_resource_manager', 'accountant', 'field_officer', 'staff'] },
        { test: /\/reports\/variance-analysis/i, roles: ['admin', 'accountant'] },
        { test: /\/reports\/generate-reports/i, roles: ['admin', 'head_of_organization_center', 'head_of_department', 'human_resource_manager', 'accountant'] },
        { test: /\/reports\//i, roles: ['admin', 'head_of_organization_center', 'head_of_department', 'human_resource_manager', 'accountant'] },
    ];

    const SECTION_RULES = {
        'nav-projects': ['admin', 'head_of_organization_center', 'head_of_department', 'field_officer', 'staff'],
        'nav-planning': ['admin', 'head_of_organization_center', 'head_of_department', 'field_officer'],
        'nav-reports': ['admin', 'head_of_organization_center', 'head_of_department', 'human_resource_manager', 'accountant'],
        'nav-finance': ['admin', 'accountant'],
        'nav-beneficiaries': ['admin', 'head_of_organization_center', 'head_of_department', 'field_officer', 'staff'],
        'nav-notifications': ['admin', 'head_of_organization_center', 'head_of_department', 'human_resource_manager', 'accountant', 'field_officer', 'staff'],
        'nav-users': ['admin', 'head_of_organization_center', 'human_resource_manager'],
        'nav-settings': ['admin', 'head_of_organization_center'],
    };

    const ROLE_ALIASES = {
        mkurugenzi_mtendaji: 'admin',
        administrator: 'admin',
        system_administrator: 'admin'
    };

    function normalizeRole(role) {
        const key = String(role || '').trim().toLowerCase();
        return ROLE_ALIASES[key] || key;
    }

    function isAdminRole(role) {
        return normalizeRole(role) === 'admin';
    }

    function getCurrentUser() {
        return authCache.currentUser || null;
    }

    function getCurrentRole() {
        const user = getCurrentUser();
        return normalizeRole(user && user.role ? user.role : '');
    }

    function isLandingPath(pathname = window.location.pathname) {
        if (window.RDOMMS_LANDING_PAGE === true) {
            return true;
        }

        if (document.documentElement?.dataset?.publicLanding === 'true') {
            return true;
        }

        const path = String(pathname || '').replace(/\\/g, '/');
        const lower = path.toLowerCase();

        if (/(^|\/)index\.(html|php)$/i.test(lower)) {
            return true;
        }

        const normalized = lower.replace(/\/+$/, '');
        if (normalized === '' || normalized === '/') {
            return true;
        }

        const segments = normalized.split('/').filter(Boolean);
        const lastSegment = segments[segments.length - 1] || '';
        if (segments.length === 1 && !['auth', 'api', 'assets', 'config', 'storage', 'uploads'].includes(lastSegment)) {
            return true;
        }

        return false;
    }

    function isPublicPath(pathname = window.location.pathname) {
        return isLandingPath(pathname) || PUBLIC_PATHS.some((pattern) => pattern.test(String(pathname || '').replace(/\\/g, '/')));
    }

    function getAccessRule(pathname = window.location.pathname) {
        return PATH_RULES.find((rule) => rule.test.test(pathname)) || null;
    }

    function isPathAllowed(pathname, role) {
        const normalizedPath = String(pathname || '').replace(/\\/g, '/').toLowerCase();
        if (!normalizedPath) {
            return true;
        }

        if (isPublicPath(normalizedPath)) {
            return true;
        }

        if (normalizedPath.endsWith('/rdomms.html') || normalizedPath.endsWith('/rdomms') || normalizedPath === '/rdomms.html' || normalizedPath === 'rdomms.html') {
            return true;
        }

        const normalizedRole = normalizeRole(role);
        const rule = getAccessRule(normalizedPath);

        if (isAdminRole(normalizedRole)) {
            if (/\/finance\/(budget-allocation|expense-tracking)/i.test(normalizedPath)) {
                return false;
            }

            if (!rule) {
                return true;
            }

            return rule.roles.some((allowedRole) => normalizeRole(allowedRole) === 'admin');
        }

        if (!rule) {
            return true;
        }

        return rule.roles.some((allowedRole) => normalizeRole(allowedRole) === normalizedRole);
    }

    function getLandingPageForRole() {
        return getAuthUrl('RDOMMS.html');
    }

    function getLandingPage() {
        return getAuthUrl('index.php');
    }

    function getLoginPage() {
        return getAuthUrl('auth/signin.php');
    }

    function getCurrentPathname() {
        return String(window.location.pathname || '').replace(/\\/g, '/').toLowerCase();
    }

    function shouldRedirectAwayFromAuthPage(pathname = getCurrentPathname()) {
        return /\/auth\/signin\.(html|php)$/i.test(pathname) || /\/auth\/signup\.(html|php)$/i.test(pathname);
    }

    function resolveHrefPath(href) {
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) {
            return '';
        }

        try {
            return new URL(href, window.location.href).pathname.toLowerCase();
        } catch (error) {
            return String(href).toLowerCase();
        }
    }

    function applyFinanceNavRestrictions(role) {
        const normalizedRole = normalizeRole(role);
        const approverRoles = ['admin', 'accountant', 'head_of_organization_center', 'head_of_department'];
        const canSeeFinance = approverRoles.includes(normalizedRole);
        const financeSection = document.getElementById('nav-finance');

        document.querySelectorAll('[data-finance-approvals="true"]').forEach((link) => {
            const showApprovals = approverRoles.includes(normalizedRole);
            link.style.display = showApprovals ? '' : 'none';
            if (showApprovals) {
                link.removeAttribute('aria-hidden');
            } else {
                link.setAttribute('aria-hidden', 'true');
            }
        });

        document.querySelectorAll('[data-finance-crud="true"]').forEach((link) => {
            const showCrud = normalizedRole === 'accountant';
            link.style.display = showCrud ? '' : 'none';
            if (showCrud) {
                link.removeAttribute('aria-hidden');
            } else {
                link.setAttribute('aria-hidden', 'true');
            }
        });

        document.querySelectorAll('[data-finance-reports="true"]').forEach((link) => {
            const showReports = ['admin', 'accountant', 'head_of_organization_center', 'head_of_department'].includes(normalizedRole);
            link.style.display = showReports ? '' : 'none';
            if (showReports) {
                link.removeAttribute('aria-hidden');
            } else {
                link.setAttribute('aria-hidden', 'true');
            }
        });

        document.querySelectorAll('[data-finance-nav="true"]').forEach((link) => {
            const showFinanceNav = canSeeFinance;
            link.style.display = showFinanceNav ? '' : 'none';
            if (showFinanceNav) {
                link.removeAttribute('aria-hidden');
            } else {
                link.setAttribute('aria-hidden', 'true');
            }
        });

        if (!financeSection) {
            return;
        }

        if (!canSeeFinance) {
            financeSection.style.display = 'none';
            financeSection.setAttribute('aria-hidden', 'true');
            return;
        }

        const visibleLinks = Array.from(financeSection.querySelectorAll('.dropdown a[href]'))
            .filter((link) => link.style.display !== 'none');
        financeSection.style.display = visibleLinks.length ? '' : 'none';
        if (visibleLinks.length) {
            financeSection.removeAttribute('aria-hidden');
        } else {
            financeSection.setAttribute('aria-hidden', 'true');
        }
    }

    function applyAdminModuleNavRestrictions(role) {
        if (!isAdminRole(normalizeRole(role))) {
            return;
        }

        const projectsNav = document.getElementById('nav-projects');
        if (projectsNav) {
            projectsNav.querySelectorAll('[data-finance-nav="true"]').forEach((link) => {
                link.style.display = 'none';
                link.setAttribute('aria-hidden', 'true');
            });
        }
    }

    function hideEmptyDropdownSectionLabels() {
        document.querySelectorAll('.sidebar .dropdown').forEach((dropdown) => {
            dropdown.querySelectorAll('.dropdown-section-label').forEach((label) => {
                let node = label.nextElementSibling;
                let hasVisibleLink = false;

                while (node && !node.classList.contains('dropdown-section-label')) {
                    if (node.matches('a[href]') && node.style.display !== 'none') {
                        hasVisibleLink = true;
                        break;
                    }
                    node = node.nextElementSibling;
                }

                label.style.display = hasVisibleLink ? '' : 'none';
                if (hasVisibleLink) {
                    label.removeAttribute('aria-hidden');
                } else {
                    label.setAttribute('aria-hidden', 'true');
                }
            });
        });
    }

    function syncNavVisibility(role) {
        const normalizedRole = normalizeRole(role);

        if (isAdminRole(normalizedRole)) {
            document.querySelectorAll('a[href]').forEach((link) => {
                const targetPath = resolveHrefPath(link.getAttribute('href'));
                if (!targetPath) {
                    return;
                }

                if (isPathAllowed(targetPath, normalizedRole)) {
                    if (link.dataset.rbacHidden === 'true') {
                        link.style.display = link.dataset.rbacDisplay || '';
                        delete link.dataset.rbacHidden;
                        delete link.dataset.rbacDisplay;
                    } else {
                        link.style.display = '';
                    }
                    link.removeAttribute('aria-hidden');
                    return;
                }

                if (link.dataset.rbacHidden !== 'true') {
                    link.dataset.rbacDisplay = link.style.display || '';
                }
                link.dataset.rbacHidden = 'true';
                link.style.display = 'none';
                link.setAttribute('aria-hidden', 'true');
            });

            Object.keys(SECTION_RULES).forEach((sectionId) => {
                const section = document.getElementById(sectionId);
                if (!section) {
                    return;
                }

                const dropdownLinks = Array.from(section.querySelectorAll('.dropdown a[href]'));
                const visibleDropdownLinks = dropdownLinks.filter((link) => link.style.display !== 'none');
                const hasVisibleChildren = visibleDropdownLinks.length > 0;

                section.style.display = hasVisibleChildren ? '' : 'none';
                if (hasVisibleChildren) {
                    section.removeAttribute('aria-hidden');
                } else {
                    section.setAttribute('aria-hidden', 'true');
                }
            });

            const dashboardItem = document.querySelector('.sidebar .nav > .nav-item:first-child');
            if (dashboardItem) {
                dashboardItem.style.display = '';
            }

            const logoutItem = document.querySelector('.sidebar-footer');
            if (logoutItem) {
                logoutItem.style.display = '';
            }

            hideEmptyDropdownSectionLabels();
            applyFinanceNavRestrictions(normalizedRole);
            applyAdminModuleNavRestrictions(normalizedRole);
            return;
        }

        document.querySelectorAll('a[href]').forEach((link) => {
            const targetPath = resolveHrefPath(link.getAttribute('href'));
            if (!targetPath) {
                return;
            }

            if (isPathAllowed(targetPath, normalizedRole)) {
                if (link.dataset.rbacHidden === 'true') {
                    link.style.display = link.dataset.rbacDisplay || '';
                    link.removeAttribute('aria-hidden');
                    delete link.dataset.rbacHidden;
                    delete link.dataset.rbacDisplay;
                }
                return;
            }

            if (link.dataset.rbacHidden !== 'true') {
                link.dataset.rbacDisplay = link.style.display || '';
            }
            link.dataset.rbacHidden = 'true';
            link.style.display = 'none';
            link.setAttribute('aria-hidden', 'true');
        });

        Object.entries(SECTION_RULES).forEach(([sectionId, allowedRoles]) => {
            const section = document.getElementById(sectionId);
            if (!section) {
                return;
            }

            const roleAllowed = allowedRoles.some((allowedRole) => normalizeRole(allowedRole) === normalizedRole);
            if (!roleAllowed) {
                section.style.display = 'none';
                section.setAttribute('aria-hidden', 'true');
                return;
            }

            const dropdownLinks = Array.from(section.querySelectorAll('.dropdown a[href]'));
            const visibleDropdownLinks = dropdownLinks.filter((link) => link.style.display !== 'none');
            const hasVisibleChildren = visibleDropdownLinks.length > 0;

            section.style.display = hasVisibleChildren ? '' : 'none';
            if (hasVisibleChildren) {
                section.removeAttribute('aria-hidden');
            } else {
                section.setAttribute('aria-hidden', 'true');
            }
        });

        const dashboardItem = document.querySelector('.sidebar .nav > .nav-item:first-child');
        if (dashboardItem) {
            dashboardItem.style.display = '';
        }

        const logoutItem = document.querySelector('.sidebar-footer');
        if (logoutItem) {
            logoutItem.style.display = '';
        }

        applyFinanceNavRestrictions(normalizedRole);
        hideEmptyDropdownSectionLabels();
    }

    function enforcePageAccess() {
        if (window.RDOMMS_BLOCK_SIGNIN_REDIRECT === true || window.RDOMMS_LANDING_PAGE === true) {
            return;
        }

        const currentPath = getCurrentPathname();
        const user = getCurrentUser();
        const role = getCurrentRole();

        if (isLandingPath(currentPath)) {
            return;
        }

        if (isPublicPath(currentPath)) {
            if (user && role) {
                if (shouldRedirectAwayFromAuthPage(currentPath)) {
                    const onSigninPage = /\/auth\/signin\.(html|php)$/i.test(currentPath);
                    if (onSigninPage) {
                        if (!window.__RDOMMS_SERVER_SESSION_VALID__) {
                            return;
                        }
                        window.dispatchEvent(new CustomEvent('rdommsAlreadyAuthenticated', {
                            detail: { user, role }
                        }));
                        return;
                    }
                    window.location.replace(getLandingPageForRole(role));
                    return;
                }
                syncNavVisibility(role);
            }
            return;
        }

        if (!user || !role) {
            if (!isPublicPath(currentPath)) {
                window.location.replace(getLoginPage());
                return;
            }
            window.location.replace(getLandingPage());
            return;
        }

        if (!isPathAllowed(currentPath, role)) {
            window.location.replace(getLandingPageForRole(role));
            return;
        }

        syncNavVisibility(role);
    }

    window.RDOMMSRBAC = {
        normalizeRole,
        isAdminRole,
        getCurrentUser,
        getCurrentRole,
        isPublicPath,
        isPathAllowed,
        getLandingPageForRole,
        getLandingPage,
        getLoginPage,
        enforcePageAccess,
        syncNavVisibility
    };

    function getApiUrl(action, extraQuery = '') {
        let prefix = '';
        const path = window.location.pathname;
        if (
            path.includes('/projects/') ||
            path.includes('/planning/') ||
            path.includes('/reports/') ||
            path.includes('/finance/') ||
            path.includes('/beneficiaries/') ||
            path.includes('/documents/') ||
            path.includes('/settings/') ||
            path.includes('/users/') ||
            path.includes('/notifications/') ||
            path.includes('/auth/')
        ) {
            prefix = '../';
        }
        return prefix + 'api.php?action=' + action + (extraQuery ? '&' + extraQuery : '');
    }

    function getAuthUrl(path) {
        let prefix = '';
        const currentPath = window.location.pathname;
        if (
            currentPath.includes('/projects/') ||
            currentPath.includes('/planning/') ||
            currentPath.includes('/reports/') ||
            currentPath.includes('/finance/') ||
            currentPath.includes('/beneficiaries/') ||
            currentPath.includes('/documents/') ||
            currentPath.includes('/settings/') ||
            currentPath.includes('/users/') ||
            currentPath.includes('/notifications/') ||
            currentPath.includes('/auth/')
        ) {
            prefix = '../';
        }
        return prefix + path;
    }

    function normalizeKey(key) {
        if (canonicalAliases[key]) {
            return canonicalAliases[key];
        }
        if (key.startsWith(PREFIX)) {
            const stripped = key.slice(PREFIX.length);
            if (canonicalAliases[stripped]) {
                return canonicalAliases[stripped];
            }
            if (directTables.has(stripped)) {
                return stripped;
            }
            return stripped;
        }
        return key;
    }

    function isProjectCacheKey(key) {
        const canonical = normalizeKey(key);
        return projectCacheKeys.has(canonical) || projectCacheKeys.has(key);
    }

    function writeProjectCache(value) {
        const parsed = toJsonValue(value);
        setCacheValue('projects', parsed);
        dbCache.rd_projects = parsed;
        const serialized = serializeForStorage(parsed);
        if (serialized !== null) {
            originalSetItem.call(localStorage, 'projects', serialized);
            originalSetItem.call(localStorage, 'rd_projects', serialized);
        }
        return true;
    }

    function shouldPersistToDb(key) {
        const canonical = normalizeKey(key);
        if (authKeys.has(canonical)) {
            return false;
        }
        if (directTables.has(canonical)) {
            return true;
        }
        if (genericKeys.has(canonical)) {
            return true;
        }
        return genericPrefixes.some((prefix) => canonical.startsWith(prefix) || key.startsWith(prefix));
    }

    function toJsonValue(value) {
        if (typeof value !== 'string') {
            return value;
        }

        try {
            return JSON.parse(value);
        } catch (error) {
            return value;
        }
    }

    function serializeForStorage(value) {
        if (value === undefined) {
            return null;
        }
        if (value === null) {
            return 'null';
        }
        if (typeof value === 'string') {
            return value;
        }
        if (typeof value === 'number' || typeof value === 'boolean') {
            return String(value);
        }
        try {
            return JSON.stringify(value);
        } catch (error) {
            return String(value);
        }
    }

    function directGet(canonical) {
        if (!dbReady) {
            return undefined;
        }
        return Object.prototype.hasOwnProperty.call(dbCache, canonical) ? dbCache[canonical] : undefined;
    }

    function authGet(canonical) {
        if (!authReady) {
            return undefined;
        }
        return Object.prototype.hasOwnProperty.call(authCache, canonical) ? authCache[canonical] : undefined;
    }

    function setCacheValue(canonical, value) {
        if (authKeys.has(canonical)) {
            authCache[canonical] = value;
            authReady = true;
            return;
        }

        dbCache[canonical] = value;
        dbReady = true;
    }

    function removeCacheValue(canonical) {
        if (authKeys.has(canonical)) {
            delete authCache[canonical];
            return;
        }

        delete dbCache[canonical];
    }

    function clearDbCache() {
        Object.keys(dbCache).forEach((key) => delete dbCache[key]);
        Object.keys(authCache).forEach((key) => delete authCache[key]);
        dbReady = true;
        authReady = true;
    }

    function syncDbKey(key, value) {
        const payload = {
            table: key,
            data: value
        };

        const xhr = new XMLHttpRequest();
        xhr.open('POST', getApiUrl('sync_table'), false);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.withCredentials = true;
        const csrfToken = window.RDOMMSSecurity && typeof window.RDOMMSSecurity.getCsrfTokenSync === 'function'
            ? window.RDOMMSSecurity.getCsrfTokenSync()
            : null;
        if (csrfToken) {
            xhr.setRequestHeader('X-CSRF-Token', csrfToken);
        }

        try {
            xhr.send(JSON.stringify(payload));
        } catch (error) {
            return false;
        }

        return xhr.status >= 200 && xhr.status < 300;
    }

    function bootDbCache() {
        if (window.location.pathname.includes('/auth/')) {
            dbReady = true;
            return;
        }

        const xhr = new XMLHttpRequest();
        xhr.open('GET', getApiUrl('get_data'), false);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.withCredentials = true;

        try {
            xhr.send();
        } catch (error) {
            dbReady = true;
            return;
        }

        if (xhr.status === 401 || xhr.status === 403) {
            dbReady = true;
            return;
        }

        if (xhr.status < 200 || xhr.status >= 300) {
            dbReady = true;
            return;
        }

        try {
            const result = JSON.parse(xhr.responseText || '{}');
            if (result && result.success && result.data) {
                Object.keys(result.data).forEach((key) => {
                    if (key === 'app_storage' && Array.isArray(result.data.app_storage)) {
                        result.data.app_storage.forEach((row) => {
                            if (!row || typeof row !== 'object') {
                                return;
                            }
                            const storageKey = String(row.storage_key || row.key || '');
                            if (!storageKey) {
                                return;
                            }
                            try {
                                dbCache[storageKey] = typeof row.payload === 'string'
                                    ? JSON.parse(row.payload)
                                    : row.payload;
                            } catch (error) {
                                dbCache[storageKey] = row.payload;
                            }
                        });
                        return;
                    }

                    if (key === 'plans' && result.data.plans && typeof result.data.plans === 'object') {
                        const nestedPlans = result.data.plans;
                        if (Array.isArray(nestedPlans.monthly_plans)) {
                            dbCache.monthlyPlans = nestedPlans.monthly_plans;
                        }
                        if (Array.isArray(nestedPlans.quarterly_plans)) {
                            dbCache.quarterlyPlans = nestedPlans.quarterly_plans;
                        }
                        if (Array.isArray(nestedPlans.annual_plans)) {
                            dbCache.annualPlans = nestedPlans.annual_plans;
                        }
                        return;
                    }

                    dbCache[key] = result.data[key];
                    if (key === 'notifications') {
                        dbCache.system_alerts = result.data[key];
                    }
                    if (key === 'projects') {
                        dbCache.rd_projects = result.data[key];
                    }
                });

                if (!Object.prototype.hasOwnProperty.call(dbCache, 'projects') && Object.prototype.hasOwnProperty.call(dbCache, 'rd_projects')) {
                    dbCache.projects = dbCache.rd_projects;
                }
                if (!Object.prototype.hasOwnProperty.call(dbCache, 'rd_projects') && Object.prototype.hasOwnProperty.call(dbCache, 'projects')) {
                    dbCache.rd_projects = dbCache.projects;
                }
                dbReady = true;
            } else {
                dbReady = true;
            }
        } catch (error) {
            dbReady = true;
        }
    }

    function hydrateAuthFromLocalStorage() {
        try {
            const loggedIn = originalGetItem.call(localStorage, 'isLoggedIn')
                || (authCache.isLoggedIn ? String(authCache.isLoggedIn) : null);
            const rawUser = originalGetItem.call(localStorage, 'currentUser')
                || (authCache.currentUser ? serializeForStorage(authCache.currentUser) : null);

            if (loggedIn !== 'true' || !rawUser) {
                return false;
            }

            const user = toJsonValue(rawUser);
            if (!user || typeof user !== 'object' || (!user.db_id && !user.id)) {
                return false;
            }

            authCache.currentUser = {
                id: user.id || user.user_id || null,
                db_id: user.db_id || null,
                fullname: user.fullname || '',
                email: user.email || '',
                role: user.role || 'user',
                organization: user.organization || '',
                department: user.department || '',
                profile_picture: user.profile_picture || null,
                session_token: originalGetItem.call(localStorage, 'sessionToken') || null
            };
            authCache.isLoggedIn = 'true';
            authCache.sessionToken = originalGetItem.call(localStorage, 'sessionToken') || '';
            authCache.loginTime = originalGetItem.call(localStorage, 'loginTime') || new Date().toISOString();
            try {
                const metaRaw = sessionStorage.getItem('rdomms_session_meta');
                if (metaRaw) {
                    authCache.sessionMeta = JSON.parse(metaRaw);
                }
            } catch (storageError) {}
            return true;
        } catch (error) {
            return false;
        }
    }

    function parseLocalSessionExpiryMs() {
        try {
            const stored = Number(originalGetItem.call(localStorage, 'rdomms_session_expires_at_ms'));
            if (Number.isFinite(stored) && stored > Date.now()) {
                return stored;
            }
        } catch (storageError) {}

        const meta = authCache.sessionMeta;
        if (meta && typeof meta === 'object') {
            const unixExpiry = Number(meta.expires_at_unix);
            if (Number.isFinite(unixExpiry) && unixExpiry > 0) {
                return unixExpiry * 1000;
            }
            if (meta.expires_at_iso) {
                const isoParsed = Date.parse(meta.expires_at_iso);
                if (!Number.isNaN(isoParsed) && isoParsed > Date.now()) {
                    return isoParsed;
                }
            }
            if (meta.expires_at) {
                const normalized = String(meta.expires_at).trim().replace(' ', 'T');
                const parsed = Date.parse(normalized);
                if (!Number.isNaN(parsed) && parsed > Date.now()) {
                    return parsed;
                }
            }
            const seconds = Number(meta.seconds_remaining);
            const receivedAt = Number(meta._received_at || Date.now());
            if (Number.isFinite(seconds) && seconds > 0) {
                const estimated = receivedAt + (seconds * 1000);
                if (estimated > Date.now()) {
                    return estimated;
                }
            }
        }

        if (authCache.loginTime) {
            const loginMs = Date.parse(String(authCache.loginTime));
            if (!Number.isNaN(loginMs)) {
                const idleMinutes = Number(authCache.sessionMeta?.idle_minutes) || 10;
                const estimated = loginMs + (idleMinutes * 60 * 1000);
                if (estimated > Date.now()) {
                    return estimated;
                }
            }
        }

        return null;
    }

    function tryActivateOfflineSession() {
        return false;
    }

    function clearStaleLocalAuth() {
        delete authCache.currentUser;
        delete authCache.isLoggedIn;
        delete authCache.sessionToken;
        delete authCache.loginTime;
        delete authCache.sessionMeta;

        authKeys.forEach((key) => {
            delete authCache[key];
            try { originalRemoveItem.call(localStorage, key); } catch (storageError) {}
        });

        try { sessionStorage.removeItem('rdomms_session_meta'); } catch (storageError) {}
        try { sessionStorage.removeItem('rdomms_anchor_session_on_dashboard'); } catch (storageError) {}
        try { localStorage.removeItem('rdomms_session_expires_at_ms'); } catch (storageError) {}
        window.__RDOMMS_SERVER_SESSION_VALID__ = false;
        window.__RDOMMS_OFFLINE_SESSION_MODE__ = false;
        if (window.RDOMMSSession && typeof window.RDOMMSSession.stop === 'function') {
            window.RDOMMSSession.stop();
        }
    }

    function applyAuthPayload(result) {
        const ok = result && (result.success === true || result.status === 'success');
        if (!ok || !result.data || !result.data.user) {
            return false;
        }

        const user = result.data.user;
        authCache.currentUser = {
            id: user.id || user.user_id || null,
            db_id: user.db_id || null,
            fullname: user.fullname || '',
            email: user.email || '',
            role: user.role || 'user',
            organization: user.organization || '',
            department: user.department || '',
            profile_picture: user.profile_picture || null,
            session_token: result.data.session_token || null
        };
        authCache.isLoggedIn = 'true';
        authCache.sessionToken = result.data.session_token || authCache.sessionToken || '';
        authCache.loginTime = result.data.login_time || authCache.loginTime || new Date().toISOString();
        authCache.sessionMeta = result.data.session || null;
        if (authCache.sessionToken) {
            try {
                originalSetItem.call(localStorage, 'sessionToken', authCache.sessionToken);
            } catch (storageError) {}
        }
        try {
            originalSetItem.call(localStorage, 'currentUser', serializeForStorage(authCache.currentUser));
            originalSetItem.call(localStorage, 'isLoggedIn', 'true');
            if (authCache.loginTime) {
                originalSetItem.call(localStorage, 'loginTime', String(authCache.loginTime));
            }
        } catch (storageError) {}
        if (authCache.sessionMeta) {
            try {
                sessionStorage.setItem('rdomms_session_meta', JSON.stringify(authCache.sessionMeta));
            } catch (storageError) {}
        }
        window.__RDOMMS_SERVER_SESSION_VALID__ = true;
        window.__RDOMMS_OFFLINE_SESSION_MODE__ = false;
        if (window.RDOMMSSecurity && typeof window.RDOMMSSecurity.invalidateCsrfToken === 'function') {
            window.RDOMMSSecurity.invalidateCsrfToken();
        }
        return true;
    }

    function tryRestoreServerSession() {
        const token = originalGetItem.call(localStorage, 'sessionToken')
            || authCache.sessionToken
            || '';
        if (!token) {
            return false;
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', getAuthUrl('api/auth/restore-session.php'), false);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-Session-Token', token);
        xhr.withCredentials = true;

        try {
            xhr.send('{}');
        } catch (error) {
            return false;
        }

        if (xhr.status < 200 || xhr.status >= 300) {
            return false;
        }

        try {
            return applyAuthPayload(JSON.parse(xhr.responseText || '{}'));
        } catch (error) {
            return false;
        }
    }

    function bootAuthCache() {
        window.__RDOMMS_SERVER_SESSION_VALID__ = false;
        window.__RDOMMS_OFFLINE_SESSION_MODE__ = false;
        hydrateAuthFromLocalStorage();

        let authenticated = false;
        const xhr = new XMLHttpRequest();
        xhr.open('GET', getAuthUrl('api/auth/me.php'), false);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.withCredentials = true;

        try {
            xhr.send();
        } catch (error) {
            authenticated = tryRestoreServerSession();
            if (!authenticated) {
                authenticated = tryActivateOfflineSession();
            }
            if (!authenticated) {
                clearStaleLocalAuth();
            }
            authReady = true;
            return;
        }

        if (xhr.status >= 200 && xhr.status < 300) {
            try {
                authenticated = applyAuthPayload(JSON.parse(xhr.responseText || '{}'));
            } catch (error) {
                // ignore malformed auth payload
            }
        }

        if (!authenticated) {
            authenticated = tryRestoreServerSession();
        }

        if (!authenticated) {
            authenticated = tryActivateOfflineSession();
        }

        if (!authenticated) {
            clearStaleLocalAuth();
        }

        authReady = true;
    }

    function seedDefaultAdminIfNeeded() {
        const users = directGet('users');
        if (Array.isArray(users) && users.length > 0) {
            return;
        }

        const defaultUsers = [
            {
                id: 'USR001',
                user_id: 'USR001',
                fullname: 'System Administrator',
                email: 'ffilipatali@yahoo.com',
                password: 'RDOMMS@2012',
                role: 'admin',
                organization: 'rdo_mafinga',
                department: 'administration',
                phone: '+255123456789',
                employee_id: 'ADM001',
                profile_picture: 'assets/photos/RdoLogo.png',
                status: 'active',
                created_at: new Date().toISOString()
            }
        ];

        setCacheValue('users', defaultUsers);
        syncDbKey('users', defaultUsers);
    }

    function resolveStoredValue(key) {
        const canonical = normalizeKey(key);

        if (authKeys.has(canonical)) {
            const value = authGet(canonical);
            return value === undefined ? null : serializeForStorage(value);
        }

        const direct = directGet(canonical);
        if (direct !== undefined) {
            return serializeForStorage(direct);
        }

        if (Object.prototype.hasOwnProperty.call(dbCache, canonical)) {
            return serializeForStorage(dbCache[canonical]);
        }

        return null;
    }

    Storage.prototype.getItem = function (key) {
        if (this !== localStorage) {
            return originalGetItem.apply(this, arguments);
        }

        const resolved = resolveStoredValue(key);
        if (resolved !== null) {
            return resolved;
        }

        return originalGetItem.apply(this, arguments);
    };

    Storage.prototype.setItem = function (key, value) {
        if (this !== localStorage) {
            return originalSetItem.apply(this, arguments);
        }

        const canonical = normalizeKey(key);
        if (authKeys.has(canonical)) {
            const parsed = toJsonValue(value);
            authCache[canonical] = parsed;
            authReady = true;
            const serialized = serializeForStorage(parsed);
            if (serialized !== null) {
                originalSetItem.call(localStorage, canonical, serialized);
                if (key !== canonical) {
                    originalSetItem.call(localStorage, key, serialized);
                }
            }
            return true;
        }

        if (isProjectCacheKey(key)) {
            return writeProjectCache(value);
        }

        if (!shouldPersistToDb(key)) {
            return originalSetItem.apply(this, arguments);
        }

        const parsed = toJsonValue(value);
        setCacheValue(canonical, parsed);

        // Keep legacy aliases in sync without persisting them locally.
        if (canonical === 'projects') {
            dbCache.rd_projects = parsed;
        } else if (canonical === 'rd_projects') {
            dbCache.projects = parsed;
        } else if (canonical === 'notifications') {
            dbCache.system_alerts = parsed;
        } else if (canonical === 'system_alerts') {
            dbCache.notifications = parsed;
        }

        syncDbKey(canonical, parsed);
        return true;
    };

    Storage.prototype.removeItem = function (key) {
        if (this !== localStorage) {
            return originalRemoveItem.apply(this, arguments);
        }

        const canonical = normalizeKey(key);
        if (authKeys.has(canonical)) {
            removeCacheValue(canonical);
            originalRemoveItem.call(localStorage, canonical);
            if (key !== canonical) {
                originalRemoveItem.call(localStorage, key);
            }
            return true;
        }

        if (!shouldPersistToDb(key)) {
            return originalRemoveItem.apply(this, arguments);
        }

        removeCacheValue(canonical);
        syncDbKey(canonical, []);
        originalRemoveItem.call(localStorage, key);
        if (key !== canonical) {
            originalRemoveItem.call(localStorage, canonical);
        }
        return true;
    };

    Storage.prototype.clear = function () {
        if (this !== localStorage) {
            return originalClear.apply(this, arguments);
        }

        clearDbCache();

        const keysToKeep = [];
        for (let i = 0; i < localStorage.length; i += 1) {
            const key = localStorage.key(i);
            if (key && !shouldPersistToDb(key)) {
                keysToKeep.push(key);
            }
        }

        Object.keys(localStorage).forEach((key) => {
            if (shouldPersistToDb(key)) {
                originalRemoveItem.call(localStorage, key);
            }
        });

        keysToKeep.forEach((key) => {
            const value = originalGetItem.call(localStorage, key);
            if (value !== null) {
                originalSetItem.call(localStorage, key, value);
            }
        });

        return true;
    };

    class LocalStorageService {
        constructor() {
            this.prefix = PREFIX;
            this.init();
        }

        init() {
            bootDbCache();
            bootAuthCache();

            if (window.__RDOMMS_SERVER_SESSION_VALID__ && window.RDOMMSSecurity) {
                if (typeof window.RDOMMSSecurity.ensureCsrfTokenSync === 'function') {
                    window.RDOMMSSecurity.ensureCsrfTokenSync();
                } else if (typeof window.RDOMMSSecurity.ensureCsrfToken === 'function') {
                    window.RDOMMSSecurity.ensureCsrfToken().catch(() => {});
                }
            }

            const runAccessCheck = () => enforcePageAccess();
            if (document.body) {
                runAccessCheck();
            } else if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', runAccessCheck, { once: true });
            } else {
                runAccessCheck();
            }

            window.addEventListener('rdommsDataLoaded', () => {
                const role = getCurrentRole();
                if (role) {
                    syncNavVisibility(role);
                }
            });

            if (window.RDOMMSSession && window.__RDOMMS_SERVER_SESSION_VALID__ && authCache.sessionMeta && !(/\/auth\//i.test(window.location.pathname || ''))) {
                window.RDOMMSSession.start(authCache.sessionMeta);
            } else if (window.RDOMMSSession && window.__RDOMMS_SERVER_SESSION_VALID__ && getCurrentUser() && !(/\/auth\//i.test(window.location.pathname || ''))) {
                window.RDOMMSSession.start();
            } else if (window.RDOMMSSession && /\/auth\//i.test(window.location.pathname || '')) {
                window.RDOMMSSession.stop();
                if (!window.__RDOMMS_SERVER_SESSION_VALID__) {
                    try { sessionStorage.removeItem('rdomms_session_meta'); } catch (storageError) {}
                }
            }

            // Remove any previously stored copies of business data from browser storage.
            Object.keys(localStorage).forEach((key) => {
                if (shouldPersistToDb(key)) {
                    originalRemoveItem.call(localStorage, key);
                }
            });

            window.dispatchEvent(new CustomEvent('rdommsDataLoaded'));
        }

        get(key) {
            const value = resolveStoredValue(key);
            if (value === null) {
                return null;
            }

            try {
                return JSON.parse(value);
            } catch (error) {
                return value;
            }
        }

        set(key, value) {
            localStorage.setItem(key, JSON.stringify(value));
            return true;
        }

        remove(key) {
            localStorage.removeItem(key);
            return true;
        }

        clear() {
            return localStorage.clear();
        }
    }

    window.localStorageService = new LocalStorageService();
})();

// Number formatting lives in assets/js/number-format.js (loaded before this file).
