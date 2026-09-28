/**
 * RDOMMS security helpers — CSRF token, secure fetch, and XMLHttpRequest patching.
 */
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
    return `${root}${String(relativePath || '').replace(/^\//, '')}`;
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
    if (!forceRefresh && csrfToken) {
      return csrfToken;
    }

    if (!forceRefresh && csrfPromise) {
      return csrfPromise;
    }

    csrfPromise = nativeFetch(resolveApiUrl('api/auth/csrf.php'), {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
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
      .finally(() => {
        csrfPromise = null;
      });

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
      const clone = response.clone();
      const payload = await clone.json().catch(() => null);
      if (payload?.message && String(payload.message).toLowerCase().includes('csrf')) {
        invalidateCsrfToken();
        const retryHeaders = new Headers(fetchOptions.headers);
        const freshToken = await ensureCsrfToken(true);
        if (freshToken) {
          retryHeaders.set('X-CSRF-Token', freshToken);
        }
        return nativeFetch(url, { ...fetchOptions, headers: retryHeaders });
      }
    }

    return response;
  }

  function patchFetch() {
    if (!global.fetch || global.fetch.__rdommsPatched) {
      return;
    }

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
              // Header must be set after open and before send.
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

  patchFetch();
  patchXmlHttpRequest();

  global.RDOMMSSecurity = {
    ensureCsrfToken,
    ensureCsrfTokenSync,
    getCsrfTokenSync,
    invalidateCsrfToken,
    rdommsFetch,
    resolveApiUrl,
    patchFetch,
    patchXmlHttpRequest
  };

  function preloadCsrfToken() {
    ensureCsrfToken().catch(() => {});
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', preloadCsrfToken);
  } else {
    preloadCsrfToken();
  }
})(window);
