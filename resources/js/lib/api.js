// Thin fetch wrapper around the Watchtower JSON API. All mutating requests
// carry the CSRF token and JSON headers as required by the backend.

function cfg() {
  return window.Watchtower || {};
}

function base() {
  return (cfg().apiBase || '/watchtower/api').replace(/\/$/, '');
}

function buildUrl(path, params) {
  const url = new URL(base() + path, window.location.origin);
  if (params) {
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') {
        url.searchParams.set(k, v);
      }
    });
  }
  return url.toString();
}

async function request(method, path, { params, body } = {}) {
  const headers = {
    Accept: 'application/json',
  };
  const opts = { method, headers, credentials: 'same-origin' };

  if (method !== 'GET' && method !== 'HEAD') {
    headers['Content-Type'] = 'application/json';
    headers['X-CSRF-TOKEN'] = cfg().csrfToken || '';
    if (body !== undefined) {
      opts.body = JSON.stringify(body);
    }
  }

  const res = await fetch(buildUrl(path, params), opts);
  let data = null;
  const text = await res.text();
  if (text) {
    try {
      data = JSON.parse(text);
    } catch {
      data = { message: text };
    }
  }

  if (!res.ok) {
    const message =
      (data && (data.message || data.error)) ||
      `Request failed (${res.status})`;
    const err = new Error(message);
    err.status = res.status;
    err.data = data;
    throw err;
  }

  return data;
}

export const api = {
  get: (path, params) => request('GET', path, { params }),
  post: (path, body) => request('POST', path, { body }),
  del: (path) => request('DELETE', path),

  // Endpoints
  overview: () => api.get('/overview'),

  setupStatus: () => api.get('/setup/status'),
  setupMigrate: () => api.post('/setup/migrate'),

  schedule: () => api.get('/schedule'),
  scheduleHistory: (key) => api.get('/schedule/history', { key }),
  scheduleRun: (key) => api.post('/schedule/run', { key }),

  queueMetrics: (window) => api.get('/queues/metrics', { window }),
  queueFailed: (params) => api.get('/queues/failed', params),
  queueRetry: (id) => api.post(`/queues/failed/${id}/retry`),
  queueDelete: (id) => api.del(`/queues/failed/${id}`),
  queueRetryBulk: (body) => api.post('/queues/failed/retry-bulk', body),

  exceptions: (params) => api.get('/exceptions', params),
  exception: (id) => api.get(`/exceptions/${id}`),
  exceptionResolve: (id) => api.post(`/exceptions/${id}/resolve`),
  exceptionReopen: (id) => api.post(`/exceptions/${id}/reopen`),
};
