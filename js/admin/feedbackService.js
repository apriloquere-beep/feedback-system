(function () {
  const API_ROOT = '/Feedback/api';
  const ALLOWED_STATUSES = new Set(['pending', 'in_review', 'resolved', 'archived']);

  async function requestJson(url, options = {}) {
    const method = String(options.method || 'GET').toUpperCase();
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const response = await fetch(url, {
      ...options,
      headers: {
        Accept: 'application/json',
        ...(method === 'GET' || method === 'HEAD' ? {} : { 'X-CSRF-Token': csrfToken }),
        ...(options.headers || {}),
      },
    });

    let payload = {};
    try {
      payload = await response.json();
    } catch (error) {
      payload = {};
    }

    if (!response.ok || payload.success === false) {
      throw new Error(payload.message || `Request failed (${response.status})`);
    }

    return payload;
  }

  async function loadDashboardData(filters = {}, options = {}) {
    const params = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
      if (value === undefined || value === null || value === '') return;
      params.set(key, String(value));
    });

    const query = params.toString() ? `?${params.toString()}` : '';
    const payload = await requestJson(`${API_ROOT}/feedback_json.php${query}`, options);
    if (!Array.isArray(payload.data)) {
      throw new Error('Invalid feedback response');
    }
    return {
      entries: payload.data,
      summary: payload.summary || {},
    };
  }

  async function searchFeedback(query, options = {}) {
    const params = new URLSearchParams({ search: String(query), globalSearch: '1' });
    const payload = await requestJson(`${API_ROOT}/feedback_json.php?${params.toString()}`, options);
    if (!Array.isArray(payload.data) || !Number.isInteger(payload.totalMatches) || payload.totalMatches < 0) {
      throw new Error('Invalid feedback search response');
    }
    return {
      entries: payload.data,
      totalMatches: payload.totalMatches,
    };
  }

  async function loadAdminModules(section = 'all') {
    const payload = await requestJson(`${API_ROOT}/admin_modules.php?section=${encodeURIComponent(section)}`);
    return payload.data || {};
  }

  async function loadAuditRecords(filters = {}) {
    const params = new URLSearchParams({ section: 'audit' });
    Object.entries(filters).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        params.set(key, String(value));
      }
    });
    const payload = await requestJson(`${API_ROOT}/admin_modules.php?${params.toString()}`);
    if (!Array.isArray(payload.data)) {
      throw new Error('Invalid audit response');
    }
    return payload.data;
  }

  async function updateFeedback(referenceId, status, updates = {}) {
    if (!ALLOWED_STATUSES.has(status)) {
      throw new Error('Invalid status');
    }

    return requestJson(`${API_ROOT}/feedback_update_status.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ referenceId, status, updates }),
    });
  }

  async function deleteFeedback(referenceId) {
    return requestJson(`${API_ROOT}/feedback_delete.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ referenceId }),
    });
  }

  async function mutateAdminModule(entity, method, body = {}) {
    return requestJson(`${API_ROOT}/admin_modules.php`, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ entity, ...body }),
    });
  }

  window.AdminDashboardService = {
    loadDashboardData,
    searchFeedback,
    loadAdminModules,
    loadAuditRecords,
    updateFeedback,
    deleteFeedback,
    mutateAdminModule,
  };
})();
