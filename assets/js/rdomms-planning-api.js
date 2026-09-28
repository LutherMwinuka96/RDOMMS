/**
 * RDOMMS Planning API — database-backed planning module client.
 */
(function (global) {
  const API_BASE = '../api';
  const MIGRATION_PREFIX = 'rdomms_planning_migrated_';

  function safeParse(raw, fallback) {
    try {
      const parsed = JSON.parse(raw || '');
      return parsed == null ? fallback : parsed;
    } catch (error) {
      return fallback;
    }
  }

  function parseResponsePayload(response, text) {
    const body = String(text || '').trim();
    if (!body) {
      return {
        status: 'error',
        message: response.ok ? 'Empty server response' : `Request failed (${response.status})`
      };
    }

    try {
      return JSON.parse(body);
    } catch (error) {
      const snippet = body
        .replace(/<[^>]+>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, 180);
      return {
        status: 'error',
        message: snippet || 'Invalid server response'
      };
    }
  }

  function formatLoadError(error) {
    if (!error) return 'Could not load planning data.';
    if (error.status === 401) {
      return 'Please sign in to sync planning data with the database.';
    }
    if (error.status === 403) {
      return 'You do not have permission to access this planning data.';
    }
    if (error.message && error.message !== 'Invalid server response') {
      return error.message;
    }
    return 'Could not reach the planning API. Confirm you are logged in and the database is installed.';
  }

  async function request(url, options = {}) {
    const response = await fetch(url, {
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(options.headers || {})
      },
      ...options
    });

    const text = await response.text();
    const payload = parseResponsePayload(response, text);

    if (!response.ok || payload.status === 'error') {
      const message = payload.message || `Request failed (${response.status})`;
      const err = new Error(message);
      err.payload = payload;
      err.status = response.status;
      throw err;
    }

    return payload;
  }

  async function migratePlans(type, storageKey, draftKey) {
    const flag = MIGRATION_PREFIX + type;
    if (localStorage.getItem(flag) === '1') return;

    const plans = safeParse(localStorage.getItem(storageKey), []);
    const drafts = safeParse(localStorage.getItem(draftKey), {});
    const draftList = Object.values(drafts || {}).filter((item) => item && typeof item === 'object');
    const merged = [...plans, ...draftList];

    if (!merged.length) {
      localStorage.setItem(flag, '1');
      return;
    }

    try {
      const existing = await request(`${API_BASE}/plans.php?type=${encodeURIComponent(type)}`);
      if (Array.isArray(existing.data) && existing.data.length > 0) {
        localStorage.setItem(flag, '1');
        return;
      }
      await request(`${API_BASE}/plans.php?type=${encodeURIComponent(type)}&action=migrate`, {
        method: 'POST',
        body: JSON.stringify({ items: merged })
      });
      localStorage.setItem(flag, '1');
    } catch (error) {
      console.warn(`Plan migration (${type}) skipped:`, error.message);
    }
  }

  async function migrateTargets() {
    const flag = MIGRATION_PREFIX + 'targets';
    if (localStorage.getItem(flag) === '1') return;

    const targets = safeParse(localStorage.getItem('targets'), []);
    const history = safeParse(localStorage.getItem('progressHistory'), []);
    if (!targets.length) {
      localStorage.setItem(flag, '1');
      return;
    }

    try {
      const existing = await request(`${API_BASE}/targets.php`);
      if (Array.isArray(existing.data) && existing.data.length > 0) {
        localStorage.setItem(flag, '1');
        return;
      }
      await request(`${API_BASE}/targets.php?action=migrate`, {
        method: 'POST',
        body: JSON.stringify({ targets, history })
      });
      localStorage.setItem(flag, '1');
    } catch (error) {
      console.warn('Target migration skipped:', error.message);
    }
  }

  async function migrateProgress() {
    const flag = MIGRATION_PREFIX + 'progress';
    if (localStorage.getItem(flag) === '1') return;

    const items = safeParse(localStorage.getItem('rdomms_progress_monitoring_v2'), []);
    if (!items.length) {
      localStorage.setItem(flag, '1');
      return;
    }

    try {
      const existing = await request(`${API_BASE}/progress-monitoring.php`);
      if (Array.isArray(existing.data) && existing.data.length > 0) {
        localStorage.setItem(flag, '1');
        return;
      }
      await request(`${API_BASE}/progress-monitoring.php?action=migrate`, {
        method: 'POST',
        body: JSON.stringify({ items })
      });
      localStorage.setItem(flag, '1');
    } catch (error) {
      console.warn('Progress migration skipped:', error.message);
    }
  }

  async function migrateEvidence() {
    const flag = MIGRATION_PREFIX + 'evidence';
    if (localStorage.getItem(flag) === '1') return;

    const items = safeParse(localStorage.getItem('evidenceList'), []);
    if (!items.length) {
      localStorage.setItem(flag, '1');
      return;
    }

    try {
      const existing = await request(`${API_BASE}/planning-evidence.php`);
      if (Array.isArray(existing.data) && existing.data.length > 0) {
        localStorage.setItem(flag, '1');
        return;
      }
      await request(`${API_BASE}/planning-evidence.php?action=migrate`, {
        method: 'POST',
        body: JSON.stringify({ items })
      });
      localStorage.setItem(flag, '1');
    } catch (error) {
      console.warn('Evidence migration skipped:', error.message);
    }
  }

  const RDOMMSPlanningAPI = {
    request,
    formatLoadError,

    plans: {
      async migrate(type, storageKey, draftKey) {
        return migratePlans(type, storageKey, draftKey);
      },
      async list(type, params = {}) {
        const qs = new URLSearchParams({ type, ...params });
        const payload = await request(`${API_BASE}/plans.php?${qs}`);
        return payload.data || [];
      },
      async get(type, id) {
        const payload = await request(`${API_BASE}/plans.php?type=${encodeURIComponent(type)}&id=${encodeURIComponent(id)}`);
        return payload.data;
      },
      async save(type, data, options = {}) {
        const body = { ...data };
        if (options.submit) body.submit = true;
        const payload = await request(`${API_BASE}/plans.php?type=${encodeURIComponent(type)}`, {
          method: 'POST',
          body: JSON.stringify(body)
        });
        return payload.data;
      },
      async approve(type, id, reviewer) {
        const payload = await request(`${API_BASE}/plans.php?type=${encodeURIComponent(type)}&action=approve`, {
          method: 'PUT',
          body: JSON.stringify({ id, approved_by: reviewer })
        });
        return payload.data;
      },
      async reject(type, id, reason, reviewer) {
        const payload = await request(`${API_BASE}/plans.php?type=${encodeURIComponent(type)}&action=reject`, {
          method: 'PUT',
          body: JSON.stringify({ id, reject_reason: reason, rejected_by: reviewer })
        });
        return payload.data;
      },
      async listAllSubmitted(params = {}) {
        const qs = new URLSearchParams({ action: 'all', submitted: '1', ...params });
        const payload = await request(`${API_BASE}/plans.php?${qs}`);
        return payload.data || [];
      },
      async delete(type, id) {
        return request(`${API_BASE}/plans.php?type=${encodeURIComponent(type)}&id=${encodeURIComponent(id)}`, {
          method: 'DELETE'
        });
      }
    },

    targets: {
      async migrate() {
        return migrateTargets();
      },
      async list() {
        const payload = await request(`${API_BASE}/targets.php`);
        return payload.data || [];
      },
      async create(data) {
        const payload = await request(`${API_BASE}/targets.php`, {
          method: 'POST',
          body: JSON.stringify(data)
        });
        return payload.data;
      },
      async update(id, data) {
        const payload = await request(`${API_BASE}/targets.php`, {
          method: 'PUT',
          body: JSON.stringify({ ...data, id })
        });
        return payload.data;
      },
      async updateProgress(targetId, currentValue, notes) {
        const payload = await request(`${API_BASE}/targets.php?action=progress`, {
          method: 'POST',
          body: JSON.stringify({ targetId, currentValue, notes })
        });
        return payload.data;
      },
      async delete(id) {
        return request(`${API_BASE}/targets.php?id=${encodeURIComponent(id)}`, { method: 'DELETE' });
      }
    },

    progress: {
      async migrate() {
        return migrateProgress();
      },
      async list(params = {}) {
        const qs = new URLSearchParams(params);
        const payload = await request(`${API_BASE}/progress-monitoring.php?${qs}`);
        return payload.data || [];
      },
      async save(data) {
        const payload = await request(`${API_BASE}/progress-monitoring.php`, {
          method: 'POST',
          body: JSON.stringify(data)
        });
        return payload.data;
      },
      async delete(id) {
        return request(`${API_BASE}/progress-monitoring.php?id=${encodeURIComponent(id)}`, { method: 'DELETE' });
      }
    },

    evidence: {
      async migrate() {
        return migrateEvidence();
      },
      async list() {
        const payload = await request(`${API_BASE}/planning-evidence.php`);
        return payload.data || [];
      },
      async create(data) {
        const payload = await request(`${API_BASE}/planning-evidence.php`, {
          method: 'POST',
          body: JSON.stringify(data)
        });
        return payload.data;
      },
      async delete(id) {
        return request(`${API_BASE}/planning-evidence.php?id=${encodeURIComponent(id)}`, { method: 'DELETE' });
      },
      async activityOptions() {
        const options = new Set();
        try {
          const plans = await request(`${API_BASE}/plans.php?action=all&submitted=1`);
          (plans.data || []).forEach((plan) => {
            (plan.activities || []).forEach((row) => {
              const label = row.activity || row.activities;
              if (label) options.add(String(label).trim());
            });
          });
        } catch (error) {
          console.warn('Unable to load plan activities:', error.message);
        }
        try {
          const projects = await request(`${API_BASE}/projects.php`);
          (projects.data || []).forEach((project) => {
            const name = (project.name || '').trim();
            if (name) options.add(name);
          });
        } catch (error) {
          console.warn('Unable to load projects:', error.message);
        }
        return Array.from(options).sort((a, b) => a.localeCompare(b));
      }
    }
  };

  global.RDOMMSPlanningAPI = RDOMMSPlanningAPI;
})(window);
