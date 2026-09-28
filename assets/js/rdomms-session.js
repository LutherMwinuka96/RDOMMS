/**
 * RDOMMS session idle timeout — warn before expiry, redirect to login when expired.
 * Note: primary implementation lives inline in localStorage-service.js
 */
(function (global) {
  let monitorStarted = false;
  let expiresAtMs = 0;
  let warnSeconds = 60;
  let warnShown = false;
  let countdownTimer = null;
  let statusTimer = null;
  let lastPingAt = 0;

  function resolveApiUrl(relativePath) {
    if (global.RDOMMSSecurity && typeof global.RDOMMSSecurity.resolveApiUrl === 'function') {
      return global.RDOMMSSecurity.resolveApiUrl(relativePath);
    }
    const el = document.querySelector('script[src*="localStorage-service.js"], script[src*="rdomms-session.js"]');
    const src = el ? (el.getAttribute('src') || '') : '';
    const match = src.match(/^(.*)assets\/js\//);
    const root = match ? match[1] : '';
    return `${root}${String(relativePath).replace(/^\//, '')}`;
  }

  function getLoginUrl() {
    if (global.RDOMMSRBAC && typeof global.RDOMMSRBAC.getLoginPage === 'function') {
      return global.RDOMMSRBAC.getLoginPage();
    }
    return resolveApiUrl('auth/signin.php');
  }

  function isAuthPage() {
    const path = String(global.location.pathname || '').toLowerCase();
    return /\/auth\/(signin|signup|forgot_password|reset_password)\.(html|php)$/i.test(path)
      || global.RDOMMS_LANDING_PAGE === true;
  }

  function clearLocalAuth() {
    ['currentUser', 'isLoggedIn', 'loginTime', 'sessionToken'].forEach((key) => {
      try { global.localStorage.removeItem(key); } catch (_) {}
    });
    try { global.sessionStorage.removeItem('rdomms_session_meta'); } catch (_) {}
  }

  function parseSessionExpiry(meta) {
    if (!meta || typeof meta !== 'object') return null;
    if (meta.expires_at_iso) {
      const isoParsed = Date.parse(meta.expires_at_iso);
      if (!Number.isNaN(isoParsed)) return isoParsed;
    }
    if (meta.expires_at) {
      const parsed = Date.parse(String(meta.expires_at).trim().replace(' ', 'T'));
      if (!Number.isNaN(parsed)) return parsed;
    }
    const seconds = Number(meta.seconds_remaining);
    if (Number.isFinite(seconds) && seconds > 0) {
      return Date.now() + (seconds * 1000);
    }
    return null;
  }

  function applySessionMeta(meta) {
    if (!meta) return;
    warnSeconds = Number(meta.warn_seconds) || 60;
    const parsed = parseSessionExpiry(meta);
    if (parsed) {
      expiresAtMs = parsed;
      warnShown = false;
      try { global.sessionStorage.setItem('rdomms_session_meta', JSON.stringify(meta)); } catch (_) {}
    }
  }

  function secondsRemaining() {
    if (!expiresAtMs) return null;
    return Math.max(0, Math.ceil((expiresAtMs - Date.now()) / 1000));
  }

  function handleExpired() {
    stop();
    clearLocalAuth();
    if (isAuthPage()) return;
    const loginUrl = getLoginUrl();
    const separator = loginUrl.includes('?') ? '&' : '?';
    global.location.replace(`${loginUrl}${separator}expired=1`);
  }

  async function extendSession() {
    try {
      const response = await global.fetch(resolveApiUrl('api/auth/session-ping.php'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload || payload.status !== 'success') {
        if (response.status === 401 || payload?.code === 'SESSION_EXPIRED') {
          handleExpired();
        }
        return false;
      }
      applySessionMeta(payload.data?.session || null);
      return true;
    } catch (_) {
      return false;
    }
  }

  async function checkSessionStatus() {
    try {
      const response = await global.fetch(resolveApiUrl('api/auth/session-status.php'), {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload || payload.status !== 'success') {
        if (response.status === 401 || payload?.code === 'SESSION_EXPIRED') {
          handleExpired();
        }
        return;
      }
      applySessionMeta(payload.data?.session || null);
    } catch (_) {}
  }

  function maybeWarn() {
    const remaining = secondsRemaining();
    if (remaining === null) return;
    if (remaining <= 0) {
      handleExpired();
      return;
    }
    if (remaining <= warnSeconds && !warnShown) {
      warnShown = true;
      const text = remaining < 60
        ? `Your session will expire in ${remaining} seconds. Click "Stay signed in" or move the mouse to continue.`
        : `Your session will expire in ${Math.ceil(remaining / 60)} minute(s). Click "Stay signed in" or move the mouse to continue.`;
      if (global.RDOMMSToast) {
        global.RDOMMSToast.show(text, 'warning', {
          title: 'Session expiring soon',
          duration: Math.min(remaining * 1000, 30000),
          actionLabel: 'Stay signed in',
          onAction: () => { extendSession(); }
        });
      }
    }
  }

  function bindActivity() {
    const events = ['mousedown', 'keydown', 'touchstart', 'scroll', 'click'];
    let scheduled = false;
    const handler = () => {
      if (scheduled) return;
      scheduled = true;
      global.setTimeout(() => {
        scheduled = false;
        if (Date.now() - lastPingAt < 45000) return;
        lastPingAt = Date.now();
        extendSession();
      }, 500);
    };
    events.forEach((name) => document.addEventListener(name, handler, { passive: true }));
  }

  function stop() {
    monitorStarted = false;
    warnShown = false;
    expiresAtMs = 0;
    if (countdownTimer) global.clearInterval(countdownTimer);
    if (statusTimer) global.clearInterval(statusTimer);
    countdownTimer = null;
    statusTimer = null;
  }

  function start(meta) {
    if (monitorStarted || isAuthPage()) return;
    const user = global.RDOMMSRBAC && global.RDOMMSRBAC.getCurrentUser
      ? global.RDOMMSRBAC.getCurrentUser()
      : null;
    if (!user) return;

    monitorStarted = true;
    if (meta) applySessionMeta(meta);
    else {
      try {
        const raw = global.sessionStorage.getItem('rdomms_session_meta');
        if (raw) applySessionMeta(JSON.parse(raw));
      } catch (_) {}
    }

    if (!expiresAtMs) checkSessionStatus();
    bindActivity();
    countdownTimer = global.setInterval(maybeWarn, 1000);
    statusTimer = global.setInterval(checkSessionStatus, 20000);
  }

  global.RDOMMSSession = { start, stop, extendSession, handleExpired: handleExpired };
})(window);
