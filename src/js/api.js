/**
 * ============================================================================
 * API CLIENT WRAPPER (VANILLA JS)
 * Quản lý CSRF Token, Idempotency UUID, AbortController Timeout, Inline Errors
 * ============================================================================
 */

(function () {
  'use strict';

  let csrfToken = null;

  // UUID generator with fallback
  function generateUUID() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
      return crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      const r = (Math.random() * 16) | 0;
      const v = c === 'x' ? r : (r & 0x3) | 0x8;
      return v.toString(16);
    });
  }

  // Tự động tìm basePath dựa trên vị trí hiện tại của script/trang
  function getApiBaseUrl() {
    const loc = window.location.pathname;
    const idxSrc = loc.indexOf('/src');
    if (idxSrc !== -1) {
      return loc.substring(0, idxSrc) + '/api';
    }
    const idxFront = loc.indexOf('/frontend');
    if (idxFront !== -1) {
      return loc.substring(0, idxFront) + '/api';
    }
    const match = loc.match(/^(.*\/tuition-system)/i);
    if (match) {
      return match[1] + '/api';
    }
    const clean = loc.replace(/\/([^\/]+\.[^\/]+|\/)?$/, '');
    return clean ? clean + '/api' : '/api';
  }

  const API_BASE = getApiBaseUrl();

  async function fetchCsrfToken() {
    try {
      const res = await fetch(`${API_BASE}/csrf`, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json'
        }
      });
      if (res.ok) {
        const json = await res.json();
        if (json.ok && json.data && json.data.csrf_token) {
          csrfToken = json.data.csrf_token;
          return csrfToken;
        }
      }
    } catch (e) {
      console.warn('Không thể lấy mã CSRF token:', e);
    }
    return null;
  }

  /**
   * Core request handler với timeout 15s, CSRF auto-retry, và button disable
   */
  async function request(endpoint, options = {}, submitButtonEl = null) {
    const method = (options.method || 'GET').toUpperCase();
    const isWrite = ['POST', 'PUT', 'DELETE', 'PATCH'].includes(method);

    // Disable button if provided
    if (submitButtonEl && typeof submitButtonEl.setAttribute === 'function') {
      submitButtonEl.disabled = true;
      submitButtonEl.classList.add('btn-loading');
    }

    // Đảm bảo có CSRF token cho các thao tác ghi
    if (isWrite && !csrfToken) {
      await fetchCsrfToken();
    }

    const headers = Object.assign({
      'Accept': 'application/json'
    }, options.headers || {});

    if (isWrite && csrfToken) {
      headers['X-CSRF-Token'] = csrfToken;
    }

    if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }

    // 15s timeout qua AbortController
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 15000);

    const fullUrl = endpoint.startsWith('http') ? endpoint : (endpoint.startsWith('/api') ? endpoint : `${API_BASE}${endpoint.startsWith('/') ? '' : '/'}${endpoint}`);

    try {
      let res = await fetch(fullUrl, {
        method,
        headers,
        body: options.body,
        credentials: 'same-origin',
        signal: controller.signal
      });

      clearTimeout(timeoutId);

      // Nếu gặp 403 Forbidden do CSRF hết hạn, thử refresh token 1 lần
      if (res.status === 403 && isWrite && !options._isRetry) {
        const refreshedToken = await fetchCsrfToken();
        if (refreshedToken) {
          headers['X-CSRF-Token'] = refreshedToken;
          options._isRetry = true;
          return await request(endpoint, options, submitButtonEl);
        }
      }

      let data;
      const text = await res.text();
      try {
        data = JSON.parse(text);
      } catch (e) {
        data = { ok: false, error: { message: text || 'Phản hồi không hợp lệ từ máy chủ.' } };
      }

      if (!res.ok) {
        const error = new Error(data?.error?.message || `Yêu cầu thất bại (HTTP ${res.status}).`);
        error.status = res.status;
        error.code = data?.error?.code || 'HTTP_ERROR';
        error.fields = data?.error?.fields || {};
        error.data = data;
        throw error;
      }

      return data;
    } catch (err) {
      clearTimeout(timeoutId);

      if (err.name === 'AbortError') {
        const timeoutError = new Error('Quá thời gian kết nối (15 giây). Vui lòng thử lại sau.');
        timeoutError.status = 408;
        timeoutError.code = 'TIMEOUT';
        throw timeoutError;
      }

      if (!err.status) {
        const netError = new Error('Không thể kết nối đến máy chủ. Vui lòng kiểm tra lại kết nối mạng hoặc XAMPP.');
        netError.status = 0;
        netError.code = 'NETWORK_ERROR';
        throw netError;
      }

      throw err;
    } finally {
      if (submitButtonEl && typeof submitButtonEl.removeAttribute === 'function') {
        submitButtonEl.disabled = false;
        submitButtonEl.classList.remove('btn-loading');
      }
    }
  }

  // Tiện ích gắn lỗi trường dữ liệu inline vào giao diện
  function renderFieldErrors(formEl, fields = {}) {
    if (!formEl) return;
    // Xóa các thông báo lỗi cũ
    formEl.querySelectorAll('.error-msg').forEach(el => {
      el.textContent = '';
      el.classList.remove('visible');
    });
    formEl.querySelectorAll('.is-invalid').forEach(el => {
      el.classList.remove('is-invalid');
    });

    Object.keys(fields).forEach(fieldName => {
      const input = formEl.querySelector(`[name="${fieldName}"], #${fieldName}`);
      if (input) {
        input.classList.add('is-invalid');
        const errSpan = formEl.querySelector(`#${input.id}-error, .error-for-${fieldName}`);
        if (errSpan) {
          errSpan.textContent = fields[fieldName];
          errSpan.classList.add('visible');
        }
      }
    });
  }

  function clearFieldErrors(formEl) {
    if (!formEl) return;
    formEl.querySelectorAll('.error-msg').forEach(el => {
      el.textContent = '';
      el.classList.remove('visible');
    });
    formEl.querySelectorAll('.is-invalid').forEach(el => {
      el.classList.remove('is-invalid');
    });
    const banner = formEl.querySelector('.error-banner');
    if (banner) {
      banner.textContent = '';
      banner.hidden = true;
    }
  }

  window.TuitionApi = {
    get: (url) => request(url, { method: 'GET' }),
    post: (url, body, btn) => request(url, { method: 'POST', body }, btn),
    put: (url, body, btn) => request(url, { method: 'PUT', body }, btn),
    delete: (url, btn) => request(url, { method: 'DELETE' }, btn),
    generateUUID,
    fetchCsrfToken,
    setCsrfToken: (t) => { csrfToken = t; },
    getCsrfToken: () => csrfToken,
    renderFieldErrors,
    clearFieldErrors,
    getBaseUrl: () => API_BASE
  };

})();
