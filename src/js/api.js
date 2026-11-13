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

      if (isWrite && !endpoint.includes('/nav/seen') && !endpoint.includes('/login') && !endpoint.includes('/logout')) {
        try {
          window.dispatchEvent(new CustomEvent('tuition:write-success', { detail: { endpoint, method } }));
        } catch (e) {}
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

  // Map standard backend field names to client form element selectors
  const FIELD_SELECTORS = {
    'username': ['[name="username"]', '#login-username'],
    'password': ['[name="password"]', '#login-password'],
    'student_code': ['#student-id-input', '[name="studentId"]', '[name="student_code"]'],
    'studentId': ['#student-id-input', '[name="studentId"]'],
    'full_name': ['#student-name-input', '[name="fullName"]', '[name="full_name"]'],
    'fullName': ['#student-name-input', '[name="fullName"]'],
    'class_name': ['#student-class-input', '[name="className"]', '[name="class_name"]'],
    'className': ['#student-class-input', '[name="className"]'],
    'term_no': ['#semester-term-select', '[name="term_no"]'],
    'academic_year_start': ['#semester-year-input', '[name="academic_year_start"]'],
    'semester_id': ['#rate-semester-input', '#fee-semester-select', '#report-semester-filter', '#fee-filter-semester', '#ledger-filter-semester', '[name="semester_id"]', '[name="semester"]'],
    'semester': ['#rate-semester-input', '#fee-semester-select', '#report-semester-filter', '#fee-filter-semester', '#ledger-filter-semester', '[name="semester"]'],
    'price_per_credit': ['#rate-price-input', '[name="pricePerCredit"]', '[name="price_per_credit"]'],
    'pricePerCredit': ['#rate-price-input', '[name="pricePerCredit"]'],
    'student_id': ['#fee-student-select', '[name="studentId"]', '[name="student_id"]'],
    'registered_credits': ['#fee-credits-input', '[name="registeredCredits"]', '[name="registered_credits"]'],
    'registeredCredits': ['#fee-credits-input', '[name="registeredCredits"]'],
    'credits': ['#student-reg-credits', '[name="credits"]'],
    'current_password': ['#change-pw-current', '[name="current_password"]'],
    'new_password': ['#change-pw-new', '[name="new_password"]'],
    'new_password_confirmation': ['#change-pw-confirm', '[name="new_password_confirmation"]'],
    'confirm_password': ['#change-pw-confirm', '[name="confirm_password"]'],
    'from': ['#student-hist-from', '[name="from"]'],
    'to': ['#student-hist-to', '[name="to"]'],
    'type': ['[name="exemptionType"]:checked', '[name="exemptionType"]'],
    'value': ['#exemption-value-input', '[name="exemptionValue"]', '[name="value"]'],
    'exemptionValue': ['#exemption-value-input', '[name="exemptionValue"]'],
    'reason': ['#exemption-reason-input', '#refund-reason-input', '#cancel-refund-reason', '#reversal-reason-input', '#res-refund-reason', '#res-transfer-reason', '[name="reason"]', '[name="refundReason"]', '[name="reversalReason"]', '[name="cancel_reason"]', '[name="transferReason"]'],
    'fee_record_id': ['#payment-fee-select', '[name="feeRecordId"]', '[name="fee_record_id"]'],
    'feeRecordId': ['#payment-fee-select', '[name="feeRecordId"]'],
    'amount': ['#payment-amount-input', '#refund-amount-input', '#res-refund-amount', '#res-transfer-amount', '#rate-price-input', '[name="amount"]', '[name="refundAmount"]', '[name="transferAmount"]', '[name="pricePerCredit"]'],
    'payment_date': ['#payment-date-input', '[name="paymentDate"]', '[name="payment_date"]'],
    'paymentDate': ['#payment-date-input', '[name="paymentDate"]'],
    'entry_date': ['#payment-date-input', '[name="entry_date"]', '[name="paymentDate"]'],
    'method': ['#payment-method-select', '[name="method"]'],
    'note': ['#payment-note-input', '[name="note"]'],
    'refund_amount': ['#refund-amount-input', '#res-refund-amount', '[name="refundAmount"]'],
    'refundAmount': ['#refund-amount-input', '#res-refund-amount', '[name="refundAmount"]'],
    'refund_reason': ['#refund-reason-input', '#res-refund-reason', '[name="refundReason"]'],
    'refundReason': ['#refund-reason-input', '#res-refund-reason', '[name="refundReason"]'],
    'cancel_reason': ['#cancel-refund-reason', '[name="cancel_reason"]'],
    'reversal_reason': ['#reversal-reason-input', '[name="reversalReason"]'],
    'reversalReason': ['#reversal-reason-input', '[name="reversalReason"]'],
    'target_fee_record_id': ['#res-target-select', '[name="targetFeeRecordId"]'],
    'targetFeeRecordId': ['#res-target-select', '[name="targetFeeRecordId"]'],
    'transfer_amount': ['#res-transfer-amount', '[name="transferAmount"]'],
    'transferAmount': ['#res-transfer-amount', '[name="transferAmount"]'],
    'transfer_reason': ['#res-transfer-reason', '[name="transferReason"]'],
    'transferReason': ['#res-transfer-reason', '[name="transferReason"]'],
    'from': ['#ledger-filter-from-date', '[name="from"]'],
    'to': ['#ledger-filter-to-date', '[name="to"]'],
    'q': ['#ledger-search-keyword', '#student-search-input', '#fee-search-student', '#receipt-lookup-input', '[name="q"]'],
    'student': ['#fee-search-student', '#student-search-input', '#ledger-search-keyword', '[name="student"]'],
    'status': ['#fee-filter-status', '#ledger-filter-status', '[name="status"]'],
    'receipt_no': ['#receipt-lookup-input', '[name="receiptNo"]'],
    'receiptNo': ['#receipt-lookup-input', '[name="receiptNo"]']
  };

  function findInputElement(formEl, fieldName) {
    const scope = formEl || document;
    let input = scope.querySelector(`[name="${fieldName}"], #${fieldName}`);
    if (input) return input;
    const mapped = FIELD_SELECTORS[fieldName];
    if (mapped) {
      for (const sel of mapped) {
        input = scope.querySelector(sel);
        if (input) return input;
      }
    }
    return null;
  }

  function findErrorSpan(formEl, inputEl, fieldName) {
    if (inputEl) {
      const describedBy = inputEl.getAttribute('aria-describedby');
      if (describedBy) {
        const span = document.getElementById(describedBy);
        if (span) return span;
      }
      if (inputEl.id) {
        const span = (formEl || document).querySelector(`#${inputEl.id}-error`);
        if (span) return span;
      }
    }
    return (formEl || document).querySelector(`.error-for-${fieldName}`);
  }

  function setFieldError(formEl, inputEl, errorMsg, fieldName = '') {
    if (inputEl) {
      inputEl.classList.add('is-invalid');
      inputEl.setAttribute('aria-invalid', 'true');
      const errSpan = findErrorSpan(formEl, inputEl, fieldName);
      if (errSpan) {
        errSpan.textContent = errorMsg;
        errSpan.classList.add('visible');
      }
    }
  }

  function clearFieldError(formEl, inputEl, fieldName = '') {
    if (inputEl) {
      inputEl.classList.remove('is-invalid');
      inputEl.removeAttribute('aria-invalid');
      const errSpan = findErrorSpan(formEl, inputEl, fieldName);
      if (errSpan) {
        errSpan.textContent = '';
        errSpan.classList.remove('visible');
      }
    }
    const summaryEl = formEl ? formEl.querySelector('.form-error-summary') : null;
    if (summaryEl && formEl) {
      const remainingInvalid = formEl.querySelector('.is-invalid, [aria-invalid="true"]');
      if (!remainingInvalid) {
        summaryEl.textContent = '';
        summaryEl.hidden = true;
      } else {
        const firstSpan = findErrorSpan(formEl, remainingInvalid, '');
        if (firstSpan && firstSpan.textContent) {
          summaryEl.textContent = firstSpan.textContent;
          summaryEl.hidden = false;
        }
      }
    }
  }

  // Tiện ích gắn lỗi trường dữ liệu inline vào giao diện
  function renderFieldErrors(formEl, fields = {}, unmappedCallback = null) {
    if (!formEl) return;
    clearFieldErrors(formEl);

    let firstInvalidInput = null;
    let firstErrorMessage = '';
    const unmappedErrors = [];

    Object.entries(fields).forEach(([fieldName, errorMsg]) => {
      const input = findInputElement(formEl, fieldName);
      if (input) {
        setFieldError(formEl, input, errorMsg, fieldName);
        if (!firstInvalidInput) {
          firstInvalidInput = input;
          firstErrorMessage = errorMsg;
        }
      } else {
        unmappedErrors.push(errorMsg);
      }
    });

    const summaryEl = formEl.querySelector('.form-error-summary');
    if (summaryEl) {
      if (firstErrorMessage) {
        summaryEl.textContent = firstErrorMessage;
        summaryEl.hidden = false;
        summaryEl.setAttribute('role', 'alert');
      } else {
        summaryEl.textContent = '';
        summaryEl.hidden = true;
      }
    }

    if (firstInvalidInput && typeof firstInvalidInput.focus === 'function') {
      firstInvalidInput.focus();
    }

    if (unmappedErrors.length > 0) {
      unmappedErrors.forEach(msg => {
        if (typeof unmappedCallback === 'function') {
          unmappedCallback(msg);
        } else if (typeof window.showAppToast === 'function') {
          window.showAppToast(msg, 'error');
        } else {
          console.error('Server error:', msg);
        }
      });
    }
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
    formEl.querySelectorAll('[aria-invalid="true"]').forEach(el => {
      el.removeAttribute('aria-invalid');
    });
    const summaryEl = formEl.querySelector('.form-error-summary');
    if (summaryEl) {
      summaryEl.textContent = '';
      summaryEl.hidden = true;
    }
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
    setFieldError,
    clearFieldError,
    findInputElement,
    findErrorSpan,
    getBaseUrl: () => API_BASE
  };

})();
