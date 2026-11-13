/**
 * ============================================================================
 * HỆ THỐNG QUẢN LÝ HỌC PHÍ SINH VIÊN - CONTROLLER & GIAO DIỆN CHÍNH
 * Kết nối hoàn toàn với PHP Backend API, bảo mật Session & CSRF, không dùng LocalStorage
 * ============================================================================
 */

(function () {
  'use strict';

  const Api = window.TuitionApi;
  const Calc = window.TuitionCalculations;

  // Trạng thái ứng dụng
  const AppState = {
    currentUser: null,
    currentTab: 'reports',
    semesters: [],
    students: [],
    rates: [],
    feeRecords: [],
    selectedFeeForPayment: null,
    selectedFeeForExemption: null,
    selectedFeeForRefund: null,
    selectedTxForReversal: null,
  };

  /* ==========================================================================
     1. TIỆN ÍCH GIAO DIỆN (UI UTILITIES & DIALOGS)
     ========================================================================== */

  function escapeHTML(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function setElementText(id, text) {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
  }

  const MAX_TOASTS = 3;

  function showToast(message, isErrorOrType = false) {
    const isError = (isErrorOrType === true || isErrorOrType === 'error');
    let container = document.getElementById('toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'toast-container';
      container.className = 'toast-container';
      container.setAttribute('aria-live', 'polite');
      container.setAttribute('aria-atomic', 'true');
      document.body.appendChild(container);
    }

    // Limit maximum visible toasts to 3
    const existingToasts = container.querySelectorAll('.app-toast');
    if (existingToasts.length >= MAX_TOASTS) {
      existingToasts[0].remove();
    }

    const toast = document.createElement('div');
    toast.className = `app-toast ${isError ? 'toast-error' : 'toast-info'}`;
    toast.setAttribute('role', isError ? 'alert' : 'status');
    toast.tabIndex = 0;

    const bodyDiv = document.createElement('div');
    bodyDiv.className = 'toast-body';

    const iconSpan = document.createElement('span');
    iconSpan.className = 'toast-icon';
    iconSpan.setAttribute('aria-hidden', 'true');
    iconSpan.textContent = isError ? '⚠️' : '✓';

    const textSpan = document.createElement('span');
    textSpan.className = 'toast-text';
    textSpan.textContent = message;

    bodyDiv.appendChild(iconSpan);
    bodyDiv.appendChild(textSpan);

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'toast-close-btn';
    closeBtn.setAttribute('aria-label', 'Đóng thông báo');
    closeBtn.innerHTML = '&times;';

    toast.appendChild(bodyDiv);
    toast.appendChild(closeBtn);

    container.appendChild(toast);

    let dismissTimer = null;
    let isPaused = false;
    let remainingTime = isError ? 8000 : 5000;
    let startTime = Date.now();

    function dismissToast() {
      if (dismissTimer) clearTimeout(dismissTimer);
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(-10px)';
      setTimeout(() => {
        if (toast.parentNode) toast.parentNode.removeChild(toast);
      }, 300);
    }

    function startTimer() {
      if (dismissTimer) clearTimeout(dismissTimer);
      startTime = Date.now();
      dismissTimer = setTimeout(() => {
        if (!isPaused) dismissToast();
      }, remainingTime);
    }

    // Pause auto-dismiss when focused or hovered
    function pauseTimer() {
      isPaused = true;
      if (dismissTimer) {
        clearTimeout(dismissTimer);
        remainingTime -= (Date.now() - startTime);
        if (remainingTime < 1000) remainingTime = 1000;
      }
    }

    function resumeTimer() {
      isPaused = false;
      startTimer();
    }

    toast.addEventListener('focusin', pauseTimer);
    toast.addEventListener('mouseenter', pauseTimer);
    toast.addEventListener('focusout', resumeTimer);
    toast.addEventListener('mouseleave', resumeTimer);

    closeBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      dismissToast();
    });

    startTimer();
  }

  // Global ESC listener to close newest visible toast
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' || e.key === 'Esc') {
      const container = document.getElementById('toast-container');
      if (container) {
        const toasts = container.querySelectorAll('.app-toast');
        if (toasts.length > 0) {
          const lastToast = toasts[toasts.length - 1];
          lastToast.style.opacity = '0';
          setTimeout(() => {
            if (lastToast.parentNode) lastToast.parentNode.removeChild(lastToast);
          }, 200);
        }
      }
    }
  });

  window.showAppToast = showToast;

  /* ==========================================================================
     CLIENT-SIDE VALIDATION UTILITIES (PART 1)
     ========================================================================== */

  function parseClientCurrency(val) {
    if (val === null || val === undefined) return { valid: false, error: 'Số tiền phải là số nguyên dương.' };
    let str = String(val).trim();
    if (str === '') return { valid: false, error: 'Số tiền phải là số nguyên dương.' };

    // Reject non-ASCII digits (e.g. Eastern Arabic ٣٠٠)
    if (/[\u0660-\u0669\u06F0-\u06F9]/.test(str)) {
      return { valid: false, error: 'Số tiền phải là số nguyên dương.' };
    }

    if (str.includes('-') || /[eE]/.test(str)) {
      return { valid: false, error: 'Số tiền phải là số nguyên dương.' };
    }

    // Strip allowed currency symbols: ₫, đ, Đ, VND, vnd, Vnd
    let s = str.replace(/^(?:₫|đ|Đ|\bVND\b|\bvnd\b|\bVnd\b)\s*/i, '')
               .replace(/\s*(?:₫|đ|Đ|\bVND\b|\bvnd\b|\bVnd\b)$/i, '')
               .trim();

    if (s === '') return { valid: false, error: 'Số tiền phải là số nguyên dương.' };

    // Strict ASCII digits and separators check
    if (!/^[0-9\s.,]+$/.test(s)) {
      return { valid: false, error: 'Số tiền phải là số nguyên dương.' };
    }

    let clean = null;
    if (/^[0-9]{1,3}(\.[0-9]{3})+$/.test(s)) {
      // Thousand separator with dot: 1.500.000
      clean = s.replace(/\./g, '');
    } else if (/^[0-9]{1,3}(,[0-9]{3})+$/.test(s)) {
      // Thousand separator with comma: 1,500,000
      clean = s.replace(/,/g, '');
    } else if (/^[0-9]{1,3}( [0-9]{3})+$/.test(s)) {
      // Thousand separator with space: 1 500 000
      clean = s.replace(/ /g, '');
    } else if (/^[0-9]+$/.test(s)) {
      // Pure digits: 1500000
      clean = s;
    } else {
      // Contains decimals (e.g. 1.5 or 1,5), invalid groupings, non-digit remnants, etc.
      return { valid: false, error: 'Số tiền phải là số nguyên dương.' };
    }

    // Check leading zero (e.g. 0100)
    if (clean.length > 1 && clean[0] === '0') {
      return { valid: false, error: 'Số tiền phải là số nguyên dương.' };
    }

    const num = parseInt(clean, 10);
    if (!Number.isFinite(num) || num <= 0) {
      return { valid: false, error: 'Số tiền phải là số nguyên dương.' };
    }

    if (num > 999999999999) {
      return { valid: false, error: 'Số tiền không được vượt quá 999.999.999.999 ₫.' };
    }

    return { valid: true, value: num, formatted: Number(num).toLocaleString('vi-VN') };
  }

  function validateClientStudentCode(val) {
    if (!val) return { valid: false, error: 'Vui lòng nhập mã sinh viên.' };
    const str = String(val).trim().toUpperCase();
    if (!str) return { valid: false, error: 'Vui lòng nhập mã sinh viên.' };
    if (!/^[A-Z0-9]{6,12}$/.test(str)) {
      return { valid: false, error: 'Mã sinh viên phải gồm 6–12 ký tự chữ và số.' };
    }
    return { valid: true, value: str };
  }

  function validateClientFullName(val) {
    if (!val) return { valid: false, error: 'Vui lòng nhập họ và tên.' };
    let str = String(val).trim().normalize('NFC');
    if (!str) return { valid: false, error: 'Vui lòng nhập họ và tên.' };

    const len = Array.from(str).length;
    if (len < 1 || len > 100) {
      return { valid: false, error: 'Họ và tên phải từ 1 đến 100 ký tự.' };
    }

    if (/[\x00-\x1F\x7F]/.test(str)) {
      return { valid: false, error: 'Họ và tên chứa ký tự điều khiển không hợp lệ.' };
    }

    if (/^[\d\s\p{P}\p{S}]+$/u.test(str)) {
      return { valid: false, error: 'Họ và tên không được chỉ gồm chữ số hoặc ký hiệu.' };
    }

    return { valid: true, value: str };
  }

  function validateClientClassName(val) {
    if (!val) return { valid: true, value: null };
    const str = String(val).trim();
    if (!str) return { valid: true, value: null };
    if (Array.from(str).length > 50) {
      return { valid: false, error: 'Tên lớp không được vượt quá 50 ký tự.' };
    }
    return { valid: true, value: str };
  }

  function validateClientReason(val, { required = true, fieldName = 'Lý do' } = {}) {
    const str = val ? String(val).trim() : '';
    if (required && !str) {
      return { valid: false, error: `Vui lòng nhập ${fieldName.toLowerCase()}.` };
    }
    if (Array.from(str).length > 200) {
      return { valid: false, error: `${fieldName} không được vượt quá 200 ký tự.` };
    }
    return { valid: true, value: str };
  }

  function parseClientDate(val, { disallowFuture = false, fieldName = 'Ngày' } = {}) {
    if (!val) return { valid: false, error: `Vui lòng chọn ${fieldName.toLowerCase()}.` };
    const str = String(val).trim();
    if (!str) return { valid: false, error: `Vui lòng chọn ${fieldName.toLowerCase()}.` };

    let day, month, year;
    const dmyMatch = str.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/);
    const isoMatch = str.match(/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/);

    if (dmyMatch) {
      day = parseInt(dmyMatch[1], 10);
      month = parseInt(dmyMatch[2], 10);
      year = parseInt(dmyMatch[3], 10);
    } else if (isoMatch) {
      year = parseInt(isoMatch[1], 10);
      month = parseInt(isoMatch[2], 10);
      day = parseInt(isoMatch[3], 10);
    } else {
      return { valid: false, error: `${fieldName} không đúng định dạng (dd/mm/yyyy hoặc yyyy-mm-dd).` };
    }

    if (month < 1 || month > 12 || day < 1 || day > 31 || year < 1900 || year > 2100) {
      return { valid: false, error: `${fieldName} không hợp lệ trong lịch.` };
    }

    const daysInMonth = new Date(year, month, 0).getDate();
    if (day > daysInMonth) {
      return { valid: false, error: `${fieldName} không hợp lệ trong lịch.` };
    }

    const d = new Date(year, month - 1, day);
    if (disallowFuture) {
      const today = new Date();
      today.setHours(23, 59, 59, 999);
      if (d > today) {
        return { valid: false, error: 'Ngày thanh toán không được lớn hơn hôm nay.' };
      }
    }

    const iso = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    return { valid: true, iso, day, month, year };
  }

  function validateClientPercentExemption(val) {
    if (val === null || val === undefined) return { valid: false, error: 'Vui lòng nhập tỷ lệ miễn giảm.' };
    const str = String(val).trim();
    if (!str) return { valid: false, error: 'Vui lòng nhập tỷ lệ miễn giảm.' };

    if (!/^\d+$/.test(str)) {
      return { valid: false, error: 'Tỷ lệ miễn giảm phải là số nguyên từ 1 đến 100.' };
    }

    const num = parseInt(str, 10);
    if (num < 1 || num > 100) {
      return { valid: false, error: 'Tỷ lệ miễn giảm phải là số nguyên từ 1 đến 100.' };
    }

    return { valid: true, value: num };
  }

  function validateClientFixedExemption(val, grossTuition) {
    const p = parseClientCurrency(val);
    if (!p.valid) return p;
    if (p.value <= 0) {
      return { valid: false, error: 'Số tiền miễn giảm phải lớn hơn 0.' };
    }
    if (p.value > grossTuition) {
      const grossFmt = Number(grossTuition).toLocaleString('vi-VN') + ' ₫';
      return { valid: false, error: `Số tiền miễn giảm không được vượt quá học phí gốc (${grossFmt}).` };
    }
    return { valid: true, value: p.value, formatted: p.formatted };
  }

  function validateClientSemesterTerm(val) {
    const num = parseInt(val, 10);
    if (![1, 2, 3].includes(num)) {
      return { valid: false, error: 'Học kỳ phải là 1, 2 hoặc 3.' };
    }
    return { valid: true, value: num };
  }

  function validateClientSearchQuery(val) {
    if (!val) return { valid: true, value: '' };
    const str = String(val).trim();
    if (!str) return { valid: true, value: '' };
    if (Array.from(str).length > 100) {
      return { valid: false, error: 'Từ khóa tìm kiếm không được vượt quá 100 ký tự.' };
    }
    const stripped = str.replace(/[\s\u0300-\u036f\u02b0-\u02ff`~^'"]/g, '');
    if (stripped.length === 0) {
      return { valid: true, value: '' };
    }
    return { valid: true, value: str };
  }

  function validateClientDateRange(fromVal, toVal) {
    if (!fromVal || !toVal) return { valid: true };
    const f = parseClientDate(fromVal, { fieldName: 'Từ ngày' });
    const t = parseClientDate(toVal, { fieldName: 'Đến ngày' });
    if (!f.valid || !t.valid) return { valid: true };
    if (t.iso < f.iso) {
      return { valid: false, field: 'to', error: 'Đến ngày không được nhỏ hơn từ ngày.' };
    }
    return { valid: true };
  }

  function bindInlineValidation(formEl, inputEl, validator, fieldName = '', onValidUpdate = null) {
    if (!inputEl) return;

    inputEl.addEventListener('blur', () => {
      const res = validator(inputEl.value);
      const isValid = (res === null || res === undefined || res.valid === true);
      if (!isValid) {
        const errorMsg = (typeof res === 'string') ? res : (res.error || 'Dữ liệu không hợp lệ.');
        Api.setFieldError(formEl, inputEl, errorMsg, fieldName);
      } else {
        Api.clearFieldError(formEl, inputEl, fieldName);
        if (res && res.formatted !== undefined) {
          inputEl.value = res.formatted;
        }
        if (typeof onValidUpdate === 'function') {
          onValidUpdate(res);
        }
      }
    });

    inputEl.addEventListener('input', () => {
      if (inputEl.classList.contains('is-invalid') || inputEl.getAttribute('aria-invalid') === 'true') {
        const res = validator(inputEl.value);
        const isValid = (res === null || res === undefined || res.valid === true);
        if (isValid) {
          Api.clearFieldError(formEl, inputEl, fieldName);
          if (res && res.formatted !== undefined) {
            inputEl.value = res.formatted;
          }
          if (typeof onValidUpdate === 'function') {
            onValidUpdate(res);
          }
        }
      }
    });
  }

  function bindCharCounter(textareaEl, counterEl, maxLen = 200) {
    if (!textareaEl || !counterEl) return;
    const update = () => {
      const len = Array.from(textareaEl.value || '').length;
      counterEl.textContent = String(len);
    };
    textareaEl.addEventListener('input', update);
    textareaEl.addEventListener('change', update);
    update();
  }

  function showConfirm(title, desc, onConfirm) {
    const modal = document.getElementById('modal-confirm');
    const titleEl = document.getElementById('modal-confirm-title');
    const descEl = document.getElementById('modal-confirm-desc');
    const proceedBtn = document.getElementById('btn-confirm-proceed');
    const cancelBtn = document.getElementById('btn-confirm-cancel');
    const closeBtn = document.getElementById('btn-close-confirm-modal');

    if (titleEl) titleEl.textContent = title;
    if (descEl) descEl.textContent = desc;

    const cleanup = () => {
      proceedBtn.onclick = null;
      cancelBtn.onclick = null;
      if (closeBtn) closeBtn.onclick = null;
      modal.close();
    };

    proceedBtn.onclick = () => {
      cleanup();
      if (typeof onConfirm === 'function') onConfirm();
    };
    cancelBtn.onclick = cleanup;
    if (closeBtn) closeBtn.onclick = cleanup;

    modal.showModal();
  }

  /* ==========================================================================
     2. XÁC THỰC & ĐĂNG NHẬP / ĐĂNG XUẤT (AUTHENTICATION)
     ========================================================================== */

  async function checkAuthSession() {
    try {
      const res = await Api.get('/me');
      if (res && res.ok && res.data) {
        onLoginSuccess(res.data);
      } else {
        onLoggedOut();
      }
    } catch (e) {
      onLoggedOut();
    }
  }

  function onLoginSuccess(user) {
    AppState.currentUser = user;
    if (user.csrf_token) {
      Api.setCsrfToken(user.csrf_token);
    }

    const loginScreen = document.getElementById('login-screen');
    const appContainer = document.getElementById('app-container');
    const studentAppContainer = document.getElementById('student-app-container');
    const studentPwChangeScreen = document.getElementById('student-password-change-screen');

    if (user.role === 'student') {
      if (appContainer) appContainer.hidden = true;
      if (loginScreen) loginScreen.hidden = true;

      if (user.must_change_password) {
        if (studentAppContainer) studentAppContainer.hidden = true;
        if (studentPwChangeScreen) studentPwChangeScreen.hidden = false;
        const pwForm = document.getElementById('student-password-form');
        if (pwForm) {
          pwForm.reset();
          Api.clearFieldErrors(pwForm);
        }
      } else {
        if (studentPwChangeScreen) studentPwChangeScreen.hidden = true;
        if (studentAppContainer) studentAppContainer.hidden = false;
        const studentDisplay = document.getElementById('student-user-display');
        if (studentDisplay) {
          studentDisplay.innerHTML = `👤 Sinh viên: <strong>${escapeHTML(user.full_name || user.username)} (${escapeHTML(user.username.toUpperCase())})</strong>`;
        }
        // Khởi tạo tab công nợ cho sinh viên
        switchTab('student-debts');
      }
    } else if (user.role === 'admin') {
      if (studentAppContainer) studentAppContainer.hidden = true;
      if (studentPwChangeScreen) studentPwChangeScreen.hidden = true;
      if (loginScreen) loginScreen.hidden = true;
      if (appContainer) appContainer.hidden = false;

      const userDisplay = document.getElementById('current-user-display');
      if (userDisplay) {
        userDisplay.innerHTML = `👤 Quản trị viên: <strong>${escapeHTML(user.full_name || user.username)}</strong>`;
      }

      startBadgePolling();

      // Nạp dữ liệu khởi tạo
      loadInitialData();
    }
  }

  function onLoggedOut() {
    stopBadgePolling();

    AppState.currentUser = null;
    Api.setCsrfToken(null);

    const loginScreen = document.getElementById('login-screen');
    const appContainer = document.getElementById('app-container');
    const studentAppContainer = document.getElementById('student-app-container');
    const studentPwChangeScreen = document.getElementById('student-password-change-screen');
    const receiptArea = document.getElementById('receipt-print-area');

    if (loginScreen) loginScreen.hidden = false;
    if (appContainer) appContainer.hidden = true;
    if (studentAppContainer) studentAppContainer.hidden = true;
    if (studentPwChangeScreen) studentPwChangeScreen.hidden = true;
    if (receiptArea) receiptArea.hidden = true;

    // Xóa form đăng nhập
    const loginForm = document.getElementById('login-form');
    if (loginForm) {
      loginForm.reset();
      Api.clearFieldErrors(loginForm);
    }
  }

  async function handleLoginSubmit(e) {
    if (e && typeof e.preventDefault === 'function') {
      e.preventDefault();
    }
    const form = document.getElementById('login-form');
    const btn = document.getElementById('btn-login');
    const errBanner = document.getElementById('login-general-error');
    Api.clearFieldErrors(form);

    const username = form.username.value.trim();
    const password = form.password.value; // Preserve leading and trailing spaces

    const fields = {};
    if (!username) {
      fields.username = 'Vui lòng nhập tên đăng nhập.';
    } else if (Array.from(username).length > 50) {
      fields.username = 'Tên đăng nhập không được vượt quá 50 ký tự.';
    }
    if (!password) {
      fields.password = 'Vui lòng nhập mật khẩu.';
    }

    if (Object.keys(fields).length > 0) {
      Api.renderFieldErrors(form, fields);
      return;
    }

    try {
      const res = await Api.post('/login', { username, password }, btn);
      if (res && res.ok && res.data) {
        onLoginSuccess(res.data);
      }
    } catch (err) {
      if (err.fields && Object.keys(err.fields).length > 0) {
        Api.renderFieldErrors(form, err.fields);
      } else {
        showToast(err.message || 'Sai tên đăng nhập hoặc mật khẩu.', 'error');
      }
      if (errBanner) {
        errBanner.textContent = err.message || 'Sai tên đăng nhập hoặc mật khẩu.';
        errBanner.hidden = false;
        errBanner.style.display = 'block';
      }
    }
  }

  async function handleLogout() {
    try {
      await Api.post('/logout');
    } catch (e) {
      // Bỏ qua lỗi mạng khi logout
    }
    onLoggedOut();
    showToast('Đã đăng xuất khỏi hệ thống.');
  }

  /* ==========================================================================
     3. ĐIỀU HƯỚNG TAB & BADGES THÔNG BÁO (PART 1 & PART 2)
     ========================================================================== */

  let badgePollInterval = null;

  async function updateNavBadges() {
    if (!AppState.currentUser) return;
    try {
      const res = await Api.get('/nav/badges');
      if (res && res.ok && res.data) {
        applyBadgeCounts(res.data);
      }
    } catch (e) {
      console.warn('Failed to update nav badges:', e);
    }
  }

  function applyBadgeCounts(counts) {
    const refunds = counts.refunds || 0;
    const newTx = counts.new_transactions || 0;
    const pendingProfiles = counts.pending_profiles || 0;
    const overpaidProfiles = counts.overpaid_profiles || 0;

    renderBadgeElement('nav-badge-refunds', refunds, 'tab-refunds', 'Hoàn tiền', 'mục cần xử lý');
    renderBadgeElement('nav-badge-new-tx', newTx, 'tab-search', 'Tra cứu Lịch sử', 'giao dịch mới');
    renderBadgeElement('nav-badge-pending', pendingProfiles, 'tab-pending', 'Hồ sơ chờ duyệt', 'mục cần xử lý');
    renderBadgeElement('nav-badge-overpaid', overpaidProfiles, 'tab-fees', 'Hồ sơ Học phí', 'hồ sơ có số tiền thừa');
  }

  function renderBadgeElement(badgeId, count, tabBtnId, defaultName, itemUnit) {
    const badgeEl = document.getElementById(badgeId);
    const tabBtn = document.getElementById(tabBtnId);
    if (!badgeEl) return;

    if (count > 0) {
      badgeEl.textContent = count > 99 ? '99+' : String(count);
      badgeEl.hidden = false;
      if (tabBtn) {
        tabBtn.setAttribute('aria-label', `${defaultName}, ${count} ${itemUnit}`);
      }
    } else {
      badgeEl.textContent = '';
      badgeEl.hidden = true;
      if (tabBtn) {
        tabBtn.removeAttribute('aria-label');
      }
    }
  }

  function startBadgePolling() {
    stopBadgePolling();
    updateNavBadges();
    badgePollInterval = setInterval(() => {
      if (document.visibilityState === 'visible' && AppState.currentUser) {
        updateNavBadges();
      }
    }, 60000);
  }

  function stopBadgePolling() {
    if (badgePollInterval) {
      clearInterval(badgePollInterval);
      badgePollInterval = null;
    }
    ['nav-badge-refunds', 'nav-badge-new-tx', 'nav-badge-pending', 'nav-badge-overpaid'].forEach(id => {
      const el = document.getElementById(id);
      if (el) {
        el.textContent = '';
        el.hidden = true;
      }
    });
    ['tab-refunds', 'tab-search', 'tab-pending', 'tab-fees'].forEach(id => {
      const tabBtn = document.getElementById(id);
      if (tabBtn) {
        tabBtn.removeAttribute('aria-label');
      }
    });
  }

  async function markHistoryAsSeen() {
    if (!AppState.currentUser) return;
    try {
      const requestId = Api.generateUUID();
      await Api.post('/nav/seen', { nav_key: 'history', request_id: requestId });
      await updateNavBadges();
    } catch (e) {
      console.warn('Failed to mark history as seen:', e);
    }
  }

  function switchTab(tabName) {
    if (AppState.currentUser?.role === 'student') {
      const allowedStudentTabs = ['student-registration', 'student-debts', 'student-payments', 'student-history'];
      if (!allowedStudentTabs.includes(tabName)) {
        tabName = 'student-debts';
      }
    }
    AppState.currentTab = tabName;

    // Cập nhật nav tabs
    const tabButtons = document.querySelectorAll('.nav-tabs button[role="tab"]');
    tabButtons.forEach(btn => {
      const isSelected = btn.id === `tab-${tabName}`;
      btn.setAttribute('aria-selected', isSelected ? 'true' : 'false');
      btn.tabIndex = isSelected ? 0 : -1;
    });

    // Cập nhật panels
    const panels = document.querySelectorAll('.tab-panel');
    panels.forEach(panel => {
      const targetPanelId = (tabName === 'pending') ? 'panel-fees' : `panel-${tabName}`;
      const isActive = panel.id === targetPanelId;
      panel.hidden = !isActive;
      panel.classList.toggle('active', isActive);
    });

    // Nạp dữ liệu tương ứng cho tab
    if (tabName === 'reports') loadReportsData();
    else if (tabName === 'students') loadStudentsData();
    else if (tabName === 'rates') loadRatesData();
    else if (tabName === 'fees') {
      const statusSelect = document.getElementById('fee-filter-status');
      if (statusSelect && statusSelect.value === 'PENDING') {
        statusSelect.value = 'ALL';
      }
      loadFeesData();
    }
    else if (tabName === 'pending') {
      const statusSelect = document.getElementById('fee-filter-status');
      if (statusSelect) {
        statusSelect.value = 'PENDING';
      }
      loadFeesData();
    }
    else if (tabName === 'refunds') loadApprovalQueue();
    else if (tabName === 'payments') loadPaymentsData();
    else if (tabName === 'search') {
      loadHistoryData();
      markHistoryAsSeen();
    }
    // Phân hệ Cổng Sinh viên
    else if (tabName === 'student-registration') loadStudentSemesters();
    else if (tabName === 'student-debts') loadStudentDebts();
    else if (tabName === 'student-payments') loadStudentPaymentOptions();
    else if (tabName === 'student-history') loadStudentHistory();
  }

  /* ==========================================================================
     4. TẢI DỮ LIỆU BAN ĐẦU (INITIAL DATA)
     ========================================================================== */

  async function loadInitialData() {
    await Promise.all([
      loadSemestersList(),
      loadRatesData(),
      loadStudentsData(),
      loadPaymentsData(),
      loadApprovalQueue(),
      updateNavBadges()
    ]);
    switchTab(AppState.currentTab || 'reports');
  }

  async function loadSemestersList() {
    try {
      const res = await Api.get('/semesters');
      if (res.ok) {
        AppState.semesters = res.data || [];
        populateSemesterDropdowns();
      }
    } catch (err) {
      console.error('Lỗi nạp danh sách học kỳ:', err);
    }
  }

  function populateSemesterDropdowns() {
    const dropdownIds = [
      'report-semester-filter',
      'fee-filter-semester',
      'ledger-filter-semester',
      'fee-semester-select'
    ];

    dropdownIds.forEach(id => {
      const el = document.getElementById(id);
      if (!el) return;

      const currentVal = el.value;
      const isFilter = (id !== 'fee-semester-select');

      let html = isFilter ? '<option value="ALL">-- Tất cả học kỳ --</option>' : '<option value="">-- Chọn học kỳ có định mức --</option>';

      AppState.semesters.forEach(sem => {
        html += `<option value="${sem.id}">${escapeHTML(sem.label)}</option>`;
      });

      el.innerHTML = html;
      if (currentVal) el.value = currentVal;
    });
  }

  /* ==========================================================================
     5. PHÂN HỆ 1: TỔNG QUAN & BÁO CÁO (REPORTS & RECONCILIATION)
     ========================================================================== */

  async function loadReportsData() {
    const semSelect = document.getElementById('report-semester-filter');
    const semFilter = semSelect?.value || 'ALL';
    try {
      const summaryRes = await Api.get(`/reports/summary?semester=${encodeURIComponent(semFilter)}`);
      const feesRes = await Api.get(`/fee-records?semester=${encodeURIComponent(semFilter)}`);

      if (summaryRes.ok) {
        renderReportsSummary(summaryRes.data);
      }
      if (feesRes.ok) {
        renderReportsFeesTable(feesRes.data);
      }
      if (semSelect) Api.clearFieldError(null, semSelect, 'semester');
    } catch (err) {
      if (err.fields && err.fields.semester && semSelect) {
        Api.setFieldError(null, semSelect, err.fields.semester, 'semester');
      } else {
        showToast('Lỗi tải báo cáo thống kê: ' + err.message, 'error');
      }
    }
  }

  function renderReportsSummary(data) {
    setElementText('kpi-receivable', Calc.formatVND(data.receivable));
    setElementText('kpi-collected', Calc.formatVND(data.collected));
    setElementText('kpi-outstanding', Calc.formatVND(data.outstanding));

    const reconStatusEl = document.getElementById('kpi-reconciliation-status');
    const reconHintEl = document.getElementById('kpi-reconciliation-hint');
    if (reconStatusEl) {
      reconStatusEl.textContent = data.reconciles ? 'Khớp 100%' : 'Lệch công nợ';
      reconStatusEl.style.color = data.reconciles ? '#059669' : '#dc2626';
    }
    if (reconHintEl) {
      reconHintEl.textContent = `Đã thu (${Calc.formatVND(data.collected)}) + Còn nợ (${Calc.formatVND(data.outstanding)}) = Phải thu (${Calc.formatVND(data.receivable)})`;
    }

    // Cảnh báo nộp thừa
    const overpaidAlert = document.getElementById('report-overpaid-alert');
    if (overpaidAlert) {
      const hasOverpaid = data.overpaid && data.overpaid.count > 0;
      overpaidAlert.hidden = !hasOverpaid;
      if (hasOverpaid) {
        setElementText('report-overpaid-count', data.overpaid.count);
        setElementText('report-overpaid-amount', Calc.formatVND(data.overpaid.amount));
      }
    }

    // Bảng trạng thái
    const tbody = document.getElementById('report-status-breakdown-body');
    if (!tbody) return;

    const total = data.total_students_with_records || 1;
    const unpaidCount = data.status_counts.unpaid || 0;
    const partialCount = data.status_counts.partial || 0;
    const paidCount = data.status_counts.paid || 0;

    const pctUnpaid = ((unpaidCount / total) * 100).toFixed(1);
    const pctPartial = ((partialCount / total) * 100).toFixed(1);
    const pctPaid = ((paidCount / total) * 100).toFixed(1);

    tbody.innerHTML = `
      <tr>
        <td><span class="badge badge-danger">Chưa thanh toán</span></td>
        <td class="text-right"><strong>${unpaidCount}</strong></td>
        <td class="text-right">${pctUnpaid}%</td>
        <td class="text-right">-</td>
        <td class="text-right">0 ₫</td>
        <td class="text-right">-</td>
      </tr>
      <tr>
        <td><span class="badge badge-warning">Thanh toán một phần</span></td>
        <td class="text-right"><strong>${partialCount}</strong></td>
        <td class="text-right">${pctPartial}%</td>
        <td class="text-right">-</td>
        <td class="text-right">-</td>
        <td class="text-right">-</td>
      </tr>
      <tr>
        <td><span class="badge badge-success">Đã thanh toán đủ</span></td>
        <td class="text-right"><strong>${paidCount}</strong></td>
        <td class="text-right">${pctPaid}%</td>
        <td class="text-right">-</td>
        <td class="text-right">-</td>
        <td class="text-right">0 ₫</td>
      </tr>
      <tr class="table-total-row">
        <td><strong>TỔNG CỘNG</strong></td>
        <td class="text-right"><strong>${data.total_students_with_records}</strong></td>
        <td class="text-right"><strong>100%</strong></td>
        <td class="text-right"><strong>${Calc.formatVND(data.receivable)}</strong></td>
        <td class="text-right"><strong>${Calc.formatVND(data.collected)}</strong></td>
        <td class="text-right"><strong>${Calc.formatVND(data.outstanding)}</strong></td>
      </tr>
    `;
  }

  function renderReportsFeesTable(records) {
    const tbody = document.getElementById('report-fee-records-body');
    if (!tbody) return;

    if (!records || records.length === 0) {
      tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">Không có hồ sơ học phí nào trong học kỳ này.</td></tr>';
      return;
    }

    tbody.innerHTML = records.map(r => {
      let badgeClass = 'status-badge status-unpaid badge badge-danger';
      if (r.is_overpaid) badgeClass = 'status-badge status-overpaid badge badge-info';
      else if (r.status === 'Partially Paid') badgeClass = 'status-badge status-partial badge badge-warning';
      else if (r.status === 'Fully Paid') badgeClass = 'status-badge status-paid badge badge-success';

      return `
        <tr>
          <td><strong>${escapeHTML(r.student_code)}</strong></td>
          <td>${escapeHTML(r.full_name)}</td>
          <td>${escapeHTML(r.semester_label)}</td>
          <td class="text-right">${r.registered_credits}</td>
          <td class="text-right">${Calc.formatVND(r.gross_tuition)}</td>
          <td class="text-right">${Calc.formatVND(r.exemption_amount)}</td>
          <td class="text-right"><strong>${Calc.formatVND(r.net_due)}</strong></td>
          <td class="text-right text-success">${Calc.formatVND(r.total_paid)}</td>
          <td class="text-right text-danger">${Calc.formatVND(r.outstanding)}</td>
          <td><span class="${badgeClass}">${r.is_overpaid ? '⚠️ ' : ''}${escapeHTML(r.status_label)}</span></td>
        </tr>
      `;
    }).join('');
  }

  /* ==========================================================================
     6. PHÂN HỆ 2: QUẢN LÝ SINH VIÊN (STUDENTS)
     ========================================================================== */

  async function loadStudentsData() {
    const searchInput = document.getElementById('student-search-input');
    const rawQ = searchInput?.value || '';
    const qRes = validateClientSearchQuery(rawQ, 100);
    if (!qRes.valid) {
      if (searchInput) {
        Api.setFieldError(null, searchInput, qRes.error, 'q');
      }
      return;
    } else if (searchInput) {
      Api.clearFieldError(null, searchInput, 'q');
    }

    const q = qRes.value;
    try {
      const res = await Api.get(`/students${q ? `?q=${encodeURIComponent(q)}` : ''}`);
      if (res.ok) {
        AppState.students = res.data || [];
        renderStudentsTable(AppState.students);
        populateStudentDropdowns();
      }
    } catch (err) {
      if (err.fields && err.fields.q && searchInput) {
        Api.setFieldError(null, searchInput, err.fields.q, 'q');
      } else {
        showToast('Lỗi tải danh sách sinh viên: ' + err.message, 'error');
      }
    }
  }

  function populateStudentDropdowns() {
    const select = document.getElementById('fee-student-select');
    if (!select) return;
    const cur = select.value;
    select.innerHTML = '<option value="">-- Chọn sinh viên --</option>' + 
      AppState.students.map(s => `<option value="${s.id}">${escapeHTML(s.student_code)} - ${escapeHTML(s.full_name)}</option>`).join('');
    if (cur) select.value = cur;
  }

  function renderStudentsTable(students) {
    const tbody = document.getElementById('students-table-body');
    const counter = document.getElementById('student-count-display');
    if (counter) counter.textContent = students.length;
    if (!tbody) return;

    if (students.length === 0) {
      tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">Không tìm thấy sinh viên nào.</td></tr>';
      return;
    }

    tbody.innerHTML = students.map(s => `
      <tr>
        <td><strong>${escapeHTML(s.student_code)}</strong></td>
        <td>${escapeHTML(s.full_name)}</td>
        <td>${escapeHTML(s.class_name || '-')}</td>
        <td class="text-right">-</td>
        <td class="text-right">-</td>
        <td class="text-right">-</td>
        <td class="text-right">-</td>
        <td class="text-center">
          <button type="button" class="btn btn-sm btn-outline-primary btn-edit-student" data-id="${s.id}">✏️ Sửa</button>
          <button type="button" class="btn btn-sm btn-outline-danger btn-del-student" data-id="${s.id}" data-name="${escapeHTML(s.full_name)}">🗑️ Xóa</button>
          <button type="button" class="btn btn-sm btn-outline-secondary btn-student-account" data-id="${s.id}" data-name="${escapeHTML(s.full_name)}" data-code="${escapeHTML(s.student_code)}" title="Cấp tài khoản / Đặt lại mật khẩu">🔑 Tài khoản</button>
        </td>
      </tr>
    `).join('');

    // Gắn sự kiện nút
    tbody.querySelectorAll('.btn-edit-student').forEach(btn => {
      btn.addEventListener('click', () => openStudentModal(btn.dataset.id));
    });
    tbody.querySelectorAll('.btn-del-student').forEach(btn => {
      btn.addEventListener('click', () => confirmDeleteStudent(btn.dataset.id, btn.dataset.name));
    });
    tbody.querySelectorAll('.btn-student-account').forEach(btn => {
      btn.addEventListener('click', () => openStudentAccountModal(btn.dataset.id, btn.dataset.name, btn.dataset.code));
    });
  }

  function openStudentModal(studentId = null) {
    const modal = document.getElementById('modal-student');
    const form = document.getElementById('student-form');
    const title = document.getElementById('modal-student-title');
    Api.clearFieldErrors(form);
    form.reset();

    if (studentId) {
      const s = AppState.students.find(x => String(x.id) === String(studentId));
      if (s) {
        title.textContent = 'Chỉnh sửa sinh viên';
        form.dataset.editId = s.id;
        form.studentId.value = s.student_code;
        form.fullName.value = s.full_name;
        form.className.value = s.class_name || '';
      }
    } else {
      title.textContent = 'Thêm sinh viên mới';
      delete form.dataset.editId;
    }

    modal.showModal();
  }

  async function handleStudentSubmit(e) {
    e.preventDefault();
    const form = document.getElementById('student-form');
    const btn = document.getElementById('btn-save-student');
    Api.clearFieldErrors(form);

    const editId = form.dataset.editId;
    const fields = {};
    const codeRes = validateClientStudentCode(form.studentId.value);
    if (!codeRes.valid) fields.studentId = codeRes.error;

    const nameRes = validateClientFullName(form.fullName.value);
    if (!nameRes.valid) fields.fullName = nameRes.error;

    const classRes = validateClientClassName(form.className.value);
    if (!classRes.valid) fields.className = classRes.error;

    if (Object.keys(fields).length > 0) {
      Api.renderFieldErrors(form, fields);
      return;
    }

    const body = {
      student_code: codeRes.value,
      full_name: nameRes.value,
      class_name: classRes.value
    };

    try {
      if (editId) {
        await Api.put(`/students/${editId}`, body, btn);
        showToast('Cập nhật sinh viên thành công.');
      } else {
        await Api.post('/students', body, btn);
        showToast('Thêm sinh viên mới thành công.');
      }
      document.getElementById('modal-student').close();
      loadStudentsData();
    } catch (err) {
      if (err.fields) Api.renderFieldErrors(form, err.fields);
      else showToast(err.message, 'error');
    }
  }

  function confirmDeleteStudent(id, name) {
    showConfirm(
      'Xóa sinh viên',
      `Bạn có chắc chắn muốn xóa sinh viên "${name}" khỏi hệ thống?`,
      async () => {
        try {
          await Api.delete(`/students/${id}`);
          showToast(`Đã xóa sinh viên "${name}".`);
          loadStudentsData();
        } catch (err) {
          showToast(err.message, true);
        }
      }
    );
  }

  /* ==========================================================================
     7. PHÂN HỆ 3: ĐỊNH MỨC TÍN CHỈ (RATES)
     ========================================================================== */

  async function loadRatesData() {
    try {
      const res = await Api.get('/rates');
      if (res.ok) {
        AppState.rates = res.data || [];
        renderRatesTable(AppState.rates);
      }
    } catch (err) {
      showToast('Lỗi tải định mức tín chỉ: ' + err.message, true);
    }
  }

  function renderRatesTable(rates) {
    const tbody = document.getElementById('rates-table-body');
    if (!tbody) return;

    if (rates.length === 0) {
      tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Chưa có định mức học phí nào được thiết lập.</td></tr>';
      return;
    }

    tbody.innerHTML = rates.map(r => `
      <tr>
        <td><strong>${escapeHTML(r.semester_label)}</strong></td>
        <td class="text-right"><strong>${Calc.formatVND(r.price_per_credit)}</strong></td>
        <td>${Calc.formatDateVN(r.updated_at)}</td>
        <td class="text-center">
          <button type="button" class="btn btn-sm btn-outline-primary btn-edit-rate" data-sem="${r.semester_id}" data-price="${r.price_per_credit}">✏️ Sửa giá</button>
          <button type="button" class="btn btn-sm btn-outline-danger btn-del-rate" data-sem="${r.semester_id}" data-sem-label="${escapeHTML(r.semester_label)}">🗑️ Xóa</button>
        </td>
      </tr>
    `).join('');

    tbody.querySelectorAll('.btn-edit-rate').forEach(btn => {
      btn.addEventListener('click', () => openRateModal(btn.dataset.sem, btn.dataset.price));
    });
    tbody.querySelectorAll('.btn-del-rate').forEach(btn => {
      btn.addEventListener('click', () => confirmDeleteRate(btn.dataset.sem, btn.dataset.semLabel));
    });
  }

  function openRateModal(semesterId = null, currentPrice = '') {
    const modal = document.getElementById('modal-rate');
    const form = document.getElementById('rate-form');
    const title = document.getElementById('modal-rate-title');
    Api.clearFieldErrors(form);
    form.reset();

    if (semesterId) {
      title.textContent = 'Chỉnh sửa đơn giá tín chỉ';
      form.dataset.semesterId = semesterId;
      const sem = AppState.semesters.find(x => String(x.id) === String(semesterId));
      form.semester.value = sem ? sem.label : `Học kỳ ${semesterId}`;
      form.semester.readOnly = true;
      form.pricePerCredit.value = Calc.formatVND(currentPrice).replace('₫', '').trim();
    } else {
      title.textContent = 'Thêm định mức học kỳ';
      delete form.dataset.semesterId;
      form.semester.readOnly = false;
    }

    modal.showModal();
  }

  async function handleRateSubmit(e) {
    e.preventDefault();
    const form = document.getElementById('rate-form');
    const btn = document.getElementById('btn-save-rate');
    Api.clearFieldErrors(form);

    const semId = form.dataset.semesterId;
    const priceRes = parseClientCurrency(form.pricePerCredit.value);
    const fields = {};

    if (!semId && !form.semester.value.trim()) {
      fields.semester = 'Vui lòng chọn hoặc nhập học kỳ.';
    }
    if (!priceRes.valid) {
      fields.pricePerCredit = priceRes.error;
    }

    if (Object.keys(fields).length > 0) {
      Api.renderFieldErrors(form, fields);
      return;
    }

    try {
      if (semId) {
        await Api.put(`/rates/${semId}`, { price_per_credit: priceRes.value }, btn);
        showToast('Cập nhật đơn giá tín chỉ thành công.');
      } else {
        const semName = form.semester.value.trim();
        const existingSem = AppState.semesters.find(s => s.label.toLowerCase() === semName.toLowerCase());
        const targetSemId = existingSem ? existingSem.id : (AppState.semesters[0]?.id || 1);
        await Api.post('/rates', { semester_id: targetSemId, price_per_credit: priceRes.value }, btn);
        showToast('Thiết lập định mức thành công.');
      }
      document.getElementById('modal-rate').close();
      loadRatesData();
    } catch (err) {
      if (err.fields) Api.renderFieldErrors(form, err.fields);
      else showToast(err.message, 'error');
    }
  }

  function openSemesterModal() {
    const modal = document.getElementById('modal-semester');
    const form = document.getElementById('semester-form');
    if (!modal || !form) return;
    Api.clearFieldErrors(form);
    form.reset();
    form.academic_year_start.value = new Date().getFullYear();
    modal.showModal();
  }

  async function handleSemesterSubmit(e) {
    e.preventDefault();
    const form = document.getElementById('semester-form');
    const btn = document.getElementById('btn-save-semester');
    Api.clearFieldErrors(form);

    const termNo = form.term_no.value;
    const yearStart = form.academic_year_start.value;

    const fields = {};
    const termRes = validateClientSemesterTerm(termNo);
    if (!termRes.valid) fields.term_no = termRes.error;

    const yearVal = parseInt(yearStart, 10);
    if (isNaN(yearVal) || yearVal < 2000 || yearVal > 2100) {
      fields.academic_year_start = 'Năm học bắt đầu không hợp lệ (từ năm 2000 đến 2100).';
    }

    if (Object.keys(fields).length > 0) {
      Api.renderFieldErrors(form, fields);
      return;
    }

    try {
      await Api.post('/semesters', { term_no: termRes.value, academic_year_start: yearVal }, btn);
      showToast('Thêm học kỳ mới thành công.');
      document.getElementById('modal-semester').close();
      await loadSemestersList();
      await loadRatesData();
    } catch (err) {
      if (err.fields) Api.renderFieldErrors(form, err.fields);
      else showToast(err.message, 'error');
    }
  }

  function confirmDeleteRate(semId, label) {
    showConfirm(
      'Xóa định mức',
      `Bạn có chắc chắn muốn xóa định mức học phí của "${label}"?`,
      async () => {
        try {
          await Api.delete(`/rates/${semId}`);
          showToast('Đã xóa định mức học phí.');
          loadRatesData();
        } catch (err) {
          showToast(err.message, true);
        }
      }
    );
  }

  /* ==========================================================================
     8. PHÂN HỆ 4: HỒ SƠ HỌC PHÍ & MIỄN GIẢM (FEES & EXEMPTIONS)
     ========================================================================== */

  async function loadFeesData() {
    const semSelect = document.getElementById('fee-filter-semester');
    const statusSelect = document.getElementById('fee-filter-status');
    const searchInput = document.getElementById('fee-search-student');

    const sem = semSelect?.value || 'ALL';
    const status = statusSelect?.value || 'ALL';
    const rawSearch = searchInput?.value || '';

    const qRes = validateClientSearchQuery(rawSearch, 100);
    if (!qRes.valid) {
      if (searchInput) Api.setFieldError(null, searchInput, qRes.error, 'student');
      return;
    } else if (searchInput) {
      Api.clearFieldError(null, searchInput, 'student');
    }

    const search = qRes.value;

    try {
      let url = `/fee-records?semester=${encodeURIComponent(sem)}&status=${encodeURIComponent(status)}`;
      if (search) url += `&student=${encodeURIComponent(search)}`;

      const res = await Api.get(url);
      if (res.ok) {
        AppState.feeRecords = res.data || [];
        renderFeesTable(AppState.feeRecords);
        populatePaymentFeeSelect();
        if (semSelect) Api.clearFieldError(null, semSelect, 'semester');
        if (statusSelect) Api.clearFieldError(null, statusSelect, 'status');
      }
    } catch (err) {
      if (err.fields) {
        if (err.fields.student && searchInput) Api.setFieldError(null, searchInput, err.fields.student, 'student');
        if (err.fields.semester && semSelect) Api.setFieldError(null, semSelect, err.fields.semester, 'semester');
        if (err.fields.status && statusSelect) Api.setFieldError(null, statusSelect, err.fields.status, 'status');
        const unhandled = Object.entries(err.fields).filter(([k]) => !['student', 'semester', 'status'].includes(k));
        if (unhandled.length > 0) {
          showToast(unhandled.map(([, v]) => v).join('\n'), 'error');
        }
      } else {
        showToast('Lỗi tải hồ sơ học phí: ' + err.message, 'error');
      }
    }
  }

  async function refreshFeeRecord(feeRecordId) {
    if (!feeRecordId) return null;
    try {
      const res = await Api.get(`/fee-records/${feeRecordId}`);
      if (res && res.ok && res.data) {
        const updated = res.data;
        const idx = AppState.feeRecords.findIndex(r => String(r.fee_record_id || r.id) === String(feeRecordId));
        if (idx !== -1) {
          AppState.feeRecords[idx] = { ...AppState.feeRecords[idx], ...updated };
        }
        renderFeesTable(AppState.feeRecords);
        populatePaymentFeeSelect();
        return updated;
      }
    } catch (e) {
      console.warn('refreshFeeRecord error:', e);
    }
    return null;
  }

  function renderFeesTable(records) {
    const tbody = document.getElementById('fees-table-body');
    if (!tbody) return;

    if (records.length === 0) {
      tbody.innerHTML = '<tr><td colspan="12" class="text-center text-muted">Không tìm thấy hồ sơ học phí nào phù hợp.</td></tr>';
      return;
    }

    tbody.innerHTML = records.map(r => {
      let badgeClass = 'status-badge status-unpaid badge badge-danger';
      if (r.is_overpaid) {
        badgeClass = 'status-badge status-overpaid badge badge-info';
      } else if (r.status === 'Partially Paid') {
        badgeClass = 'status-badge status-partial badge badge-warning';
      } else if (r.status === 'Fully Paid') {
        badgeClass = 'status-badge status-paid badge badge-success';
      }

      const pendingBadge = r.is_pending ? `<br><span class="badge badge-pending">⏳ Chờ duyệt</span>` : '';
      const overpaidWarning = r.is_overpaid ? `<br><small class="text-danger font-weight-bold">Thừa: ${Calc.formatVND(r.overpaid_amount)}</small>` : '';

      let excessActionHtml = '';
      if (r.is_pending) {
        excessActionHtml = `
          <div class="pending-refund-action">
            <span class="text-warning-dark font-weight-bold">Đang chờ duyệt hoàn tiền: ${Calc.formatVND(r.pending_amount || r.pending_refunds_sum || 0)}</span>
            <br>
            <a href="javascript:void(0);" class="btn-link link-view-refund" data-id="${r.fee_record_id}">Xem yêu cầu</a>
          </div>
        `;
      } else if (r.is_overpaid && (r.overpaid_amount - (r.pending_refunds_sum || 0) > 0)) {
        excessActionHtml = `<button type="button" class="btn btn-sm btn-outline-warning btn-resolve-excess" data-id="${r.fee_record_id}">⚖️ Xử lý số tiền thừa</button>`;
      }

      return `
        <tr>
          <td><strong>${escapeHTML(r.student_code)}</strong></td>
          <td>${escapeHTML(r.full_name)}</td>
          <td>${escapeHTML(r.semester_label)}</td>
          <td class="text-right">${r.registered_credits}</td>
          <td class="text-right">${Calc.formatVND(r.rate_snapshot)}</td>
          <td class="text-right">${Calc.formatVND(r.gross_tuition)}</td>
          <td class="text-right text-success">${Calc.formatVND(r.exemption_amount)}</td>
          <td class="text-right"><strong>${Calc.formatVND(r.net_due)}</strong></td>
          <td class="text-right text-primary">${Calc.formatVND(r.total_paid)}</td>
          <td class="text-right text-danger">${Calc.formatVND(r.outstanding)}</td>
          <td>
            <span class="${badgeClass}">${r.is_overpaid ? '⚠️ ' : ''}${escapeHTML(r.status_label)}</span>
            ${overpaidWarning}
            ${pendingBadge}
          </td>
          <td class="text-center table-actions-cell">
            <button type="button" class="btn btn-sm btn-outline-info btn-exempt-fee" data-id="${r.fee_record_id}">🎁 Miễn giảm</button>
            <button type="button" class="btn btn-sm btn-outline-success btn-pay-fee" data-id="${r.fee_record_id}">💳 Thu tiền</button>
            ${excessActionHtml}
          </td>
        </tr>
      `;
    }).join('');

    tbody.querySelectorAll('.btn-exempt-fee').forEach(btn => {
      btn.addEventListener('click', () => openExemptionModal(btn.dataset.id));
    });
    tbody.querySelectorAll('.btn-pay-fee').forEach(btn => {
      btn.addEventListener('click', () => openPaymentModalWithFee(btn.dataset.id));
    });
    tbody.querySelectorAll('.btn-refund-fee').forEach(btn => {
      btn.addEventListener('click', () => openRefundModalWithFee(btn.dataset.id));
    });
    tbody.querySelectorAll('.btn-resolve-excess').forEach(btn => {
      btn.addEventListener('click', () => openOverpaidResolutionModal(btn.dataset.id));
    });
    tbody.querySelectorAll('.link-view-refund').forEach(link => {
      link.addEventListener('click', () => switchTab('refunds'));
    });
  }

  let _lastDuplicateExistingId = null;

  async function checkDuplicateFeeRecord() {
    const studentSelect = document.getElementById('fee-student-select');
    const semesterSelect = document.getElementById('fee-semester-select');
    const warningEl = document.getElementById('fee-duplicate-inline-warning');
    const linkEl = document.getElementById('link-existing-fee-record');

    if (!studentSelect || !semesterSelect || !warningEl) return;

    const studentId = studentSelect.value;
    const semesterId = semesterSelect.value;

    if (!studentId || !semesterId) {
      warningEl.style.display = 'none';
      return;
    }

    try {
      const res = await Api.get(`/fee-records?student=${encodeURIComponent(studentId)}&semester=${encodeURIComponent(semesterId)}`);
      if (res.ok && Array.isArray(res.data) && res.data.length > 0) {
        const existing = res.data[0];
        _lastDuplicateExistingId = existing.fee_record_id || existing.id;
        warningEl.style.display = 'block';
        if (linkEl) {
          linkEl.onclick = (e) => {
            e.preventDefault();
            openExistingFeeRecord(_lastDuplicateExistingId);
          };
        }
      } else {
        warningEl.style.display = 'none';
      }
    } catch (e) {
      warningEl.style.display = 'none';
    }
  }

  async function openExistingFeeRecord(feeRecordId) {
    if (!feeRecordId) return;
    document.getElementById('modal-fee')?.close();
    const tabBtn = document.getElementById('tab-fees');
    if (tabBtn) tabBtn.click();

    // Reset filters so the record is guaranteed to be rendered in the table
    const filterSem = document.getElementById('fee-filter-semester');
    if (filterSem) filterSem.value = 'ALL';
    const searchInput = document.getElementById('fee-search-student');
    if (searchInput) searchInput.value = '';

    await loadFeesData();
    const targetRow = document.querySelector(`button[data-id="${feeRecordId}"]`)?.closest('tr');
    if (targetRow) {
      targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
      targetRow.style.backgroundColor = '#fef08a';
      setTimeout(() => { targetRow.style.backgroundColor = ''; }, 3000);
    }
    showToast(`Đã mở hồ sơ học phí #${feeRecordId}`);
  }

  function openFeeModal() {
    _lastDuplicateExistingId = null;
    const modal = document.getElementById('modal-fee');
    const form = document.getElementById('fee-form');
    Api.clearFieldErrors(form);
    form.reset();
    delete form.dataset.editId;
    setElementText('fee-gross-preview', '0 ₫');
    document.getElementById('fee-rate-display').value = '';

    const alertEl = document.getElementById('fee-duplicate-top-alert');
    if (alertEl) alertEl.style.display = 'none';
    const warningEl = document.getElementById('fee-duplicate-inline-warning');
    if (warningEl) warningEl.style.display = 'none';

    modal.showModal();
  }

  function updateFeeGrossPreview() {
    const semSelect = document.getElementById('fee-semester-select');
    const creditsInput = document.getElementById('fee-credits-input');
    const rateDisplay = document.getElementById('fee-rate-display');
    const grossPreview = document.getElementById('fee-gross-preview');

    const semId = semSelect.value;
    const credits = parseInt(creditsInput.value, 10) || 0;

    const rate = AppState.rates.find(r => String(r.semester_id) === String(semId));
    const price = rate ? rate.price_per_credit : 0;

    rateDisplay.value = price ? Calc.formatVND(price) : 'Chưa có định mức';
    const gross = Calc.calculateGross(credits, price);
    grossPreview.textContent = Calc.formatVND(gross);

    checkDuplicateFeeRecord();
  }

  async function handleFeeSubmit(e) {
    e.preventDefault();
    const form = document.getElementById('fee-form');
    const btn = document.getElementById('btn-save-fee');
    Api.clearFieldErrors(form);

    const alertEl = document.getElementById('fee-duplicate-top-alert');
    if (alertEl) alertEl.style.display = 'none';

    const fields = {};
    const studentId = form.studentId.value;
    const semesterId = form.semester.value;
    const creditsStr = form.registeredCredits.value;

    if (!studentId) {
      fields.studentId = 'Vui lòng chọn sinh viên.';
    }
    if (!semesterId) {
      fields.semester = 'Vui lòng chọn học kỳ.';
    }
    const creditsNum = parseInt(creditsStr, 10);
    if (!creditsStr || isNaN(creditsNum) || creditsNum < 1 || creditsNum > 50) {
      fields.registeredCredits = 'Số tín chỉ phải là số nguyên từ 1 đến 50.';
    }

    if (Object.keys(fields).length > 0) {
      Api.renderFieldErrors(form, fields);
      return;
    }

    const body = {
      student_id: studentId,
      semester_id: semesterId,
      registered_credits: creditsNum
    };

    try {
      await Api.post('/fee-records', body, btn);
      showToast('Lập hồ sơ học phí thành công.');
      document.getElementById('modal-fee').close();
      loadFeesData();
    } catch (err) {
      if (err.code === 'DUPLICATE_FEE_RECORD' || err.status === 409) {
        if (alertEl) {
          const msgEl = document.getElementById('fee-duplicate-top-msg');
          if (msgEl) msgEl.textContent = err.message || 'Sinh viên này đã có hồ sơ học phí cho học kỳ này.';
          alertEl.style.display = 'block';
          const existingId = err.data?.data?.existing_id || err.data?.existing_id || _lastDuplicateExistingId;
          const openExistingBtn = document.getElementById('btn-open-existing-fee');
          if (openExistingBtn) {
            openExistingBtn.onclick = () => openExistingFeeRecord(existingId);
          }
        }
      } else if (err.fields) {
        Api.renderFieldErrors(form, err.fields);
      } else {
        showToast(err.message, 'error');
      }
    }
  }

  /* Miễn giảm (Exemptions) */

  async function openExemptionModal(feeRecordId) {
    const modal = document.getElementById('modal-exemption');
    const form = document.getElementById('exemption-form');
    Api.clearFieldErrors(form);
    form.reset();

    try {
      const res = await Api.get(`/fee-records/${feeRecordId}`);
      if (!res.ok) return;
      const fee = res.data;
      AppState.selectedFeeForExemption = fee;

      form.dataset.feeId = fee.fee_record_id;
      setElementText('exemption-student-name', `${fee.student_code} - ${fee.full_name}`);
      setElementText('exemption-semester', fee.semester_label);
      setElementText('exemption-gross-tuition', Calc.formatVND(fee.gross_tuition));
      setElementText('exemption-already-paid', Calc.formatVND(fee.total_paid));

      const removeBtn = document.getElementById('btn-remove-exemption');
      if (fee.exemption_id) {
        removeBtn.hidden = false;
        form.reason.value = fee.exemption_reason || '';
        if (fee.exemption_type === 'percent') {
          document.getElementById('radio-exempt-percent').checked = true;
          form.exemptionValue.value = fee.exemption_value;
        } else {
          document.getElementById('radio-exempt-fixed').checked = true;
          form.exemptionValue.value = Calc.formatVND(fee.exemption_value).replace('₫', '').trim();
        }
      } else {
        removeBtn.hidden = true;
        document.getElementById('radio-exempt-percent').checked = true;
      }

      updateExemptionPreview();
      modal.showModal();
    } catch (err) {
      showToast(err.message, true);
    }
  }

  function updateExemptionPreview() {
    const fee = AppState.selectedFeeForExemption;
    if (!fee) return;

    const isPercent = document.getElementById('radio-exempt-percent').checked;
    const label = document.getElementById('exemption-value-label');
    const valInput = document.getElementById('exemption-value-input');

    if (label) {
      label.innerHTML = isPercent ? 'Mức giảm (%): <span class="required">*</span>' : 'Số tiền giảm (VND): <span class="required">*</span>';
    }

    const val = valInput.value;
    const exemptAmount = Calc.calculateExemption(fee.gross_tuition, isPercent ? 'percentage' : 'fixed', val);
    const netDue = Calc.calculateNetDue(fee.gross_tuition, exemptAmount);

    setElementText('exempt-calc-amount', Calc.formatVND(exemptAmount));
    setElementText('exempt-calc-net', Calc.formatVND(netDue));

    const warnBox = document.getElementById('exempt-overpaid-warning');
    if (warnBox) {
      const willOverpay = (fee.total_paid > netDue);
      warnBox.hidden = !willOverpay;
      if (willOverpay) {
        setElementText('exempt-warning-paid', Calc.formatVND(fee.total_paid));
      }
    }
  }

  async function handleExemptionSubmit(e) {
    e.preventDefault();
    const form = document.getElementById('exemption-form');
    const btn = document.getElementById('btn-save-exemption');
    Api.clearFieldErrors(form);

    const feeId = form.dataset.feeId;
    const isPercent = document.getElementById('radio-exempt-percent').checked;
    const fee = AppState.selectedFeeForExemption;
    const gross = fee ? fee.gross_tuition : 0;
    const fields = {};

    let val = null;
    if (isPercent) {
      const pRes = validateClientPercentExemption(form.exemptionValue.value);
      if (!pRes.valid) fields.exemptionValue = pRes.error;
      else val = pRes.value;
    } else {
      const fRes = validateClientFixedExemption(form.exemptionValue.value, gross);
      if (!fRes.valid) fields.exemptionValue = fRes.error;
      else val = fRes.value;
    }

    const reasonRes = validateClientReason(form.reason.value, { required: true, fieldName: 'Lý do miễn giảm' });
    if (!reasonRes.valid) fields.reason = reasonRes.error;

    if (Object.keys(fields).length > 0) {
      Api.renderFieldErrors(form, fields);
      return;
    }

    const body = {
      type: isPercent ? 'percent' : 'fixed',
      value: val,
      reason: reasonRes.value
    };

    try {
      await Api.put(`/fee-records/${feeId}/exemption`, body, btn);
      showToast('Áp dụng miễn giảm học phí thành công.');
      document.getElementById('modal-exemption').close();
      await refreshFeeRecord(feeId);
      await loadFeesData();
      await loadPaymentsData();
      await loadReportsData();
    } catch (err) {
      if (err.fields) Api.renderFieldErrors(form, err.fields);
      else showToast(err.message, 'error');
    }
  }

  async function handleRemoveExemption() {
    const form = document.getElementById('exemption-form');
    const feeId = form.dataset.feeId;
    showConfirm(
      'Xóa miễn giảm',
      'Bạn có chắc chắn muốn hủy bỏ chính sách miễn giảm trên hồ sơ này?',
      async () => {
        try {
          await Api.delete(`/fee-records/${feeId}/exemption`);
          showToast('Đã xóa chính sách miễn giảm.');
          document.getElementById('modal-exemption').close();
          await refreshFeeRecord(feeId);
          await loadFeesData();
          await loadPaymentsData();
          await loadReportsData();
        } catch (err) {
          showToast(err.message, true);
        }
      }
    );
  }

  /* ==========================================================================
     9. PHÂN HỆ 5: THU HỌC PHÍ & HOÀN TIỀN (PAYMENTS & REFUNDS)
     ========================================================================== */

  function populatePaymentFeeSelect() {
    const sel = document.getElementById('payment-fee-select');
    if (!sel) return;
    const cur = sel.value;
    sel.innerHTML = '<option value="">-- Chọn sinh viên và học kỳ cần nộp --</option>' + 
      AppState.feeRecords.map(f => {
        const debt = f.outstanding > 0 ? `(Nợ: ${Calc.formatVND(f.outstanding)})` : `(${f.status_label})`;
        return `<option value="${f.fee_record_id}">${escapeHTML(f.student_code)} - ${escapeHTML(f.full_name)} [${escapeHTML(f.semester_label)}] ${debt}</option>`;
      }).join('');
    if (cur) sel.value = cur;
  }

  function openPaymentModalWithFee(feeRecordId = null) {
    const modal = document.getElementById('modal-payment');
    const form = document.getElementById('payment-form');
    Api.clearFieldErrors(form);
    form.reset();

    form.paymentDate.value = Calc.getTodayISO();

    if (feeRecordId) {
      form.feeRecordId.value = feeRecordId;
    }
    updatePaymentFeeDetails();
    modal.showModal();
  }

  function updatePaymentFeeDetails() {
    const select = document.getElementById('payment-fee-select');
    const detailBox = document.getElementById('payment-fee-detail-box');
    const fullBtn = document.getElementById('btn-pay-full');
    const fullText = document.getElementById('pay-full-text');

    const feeId = select.value;
    const fee = AppState.feeRecords.find(f => String(f.fee_record_id) === String(feeId));
    AppState.selectedFeeForPayment = fee;

    if (!fee) {
      if (detailBox) detailBox.hidden = true;
      if (fullBtn) fullBtn.style.display = 'none';
      return;
    }

    if (detailBox) detailBox.hidden = false;
    setElementText('pay-detail-student', `${fee.student_code} - ${fee.full_name}`);
    setElementText('pay-detail-semester', fee.semester_label);
    setElementText('pay-detail-net', Calc.formatVND(fee.net_due));
    setElementText('pay-detail-paid', Calc.formatVND(fee.total_paid));
    setElementText('pay-detail-balance', Calc.formatVND(fee.outstanding));

    if (fullBtn) {
      if (fee.outstanding > 0) {
        fullBtn.style.display = 'inline-block';
        if (fullText) fullText.textContent = Calc.formatVND(fee.outstanding);
      } else {
        fullBtn.style.display = 'none';
      }
    }
  }

  async function handlePaymentSubmit(e) {
    e.preventDefault();
    const form = document.getElementById('payment-form');
    const btn = document.getElementById('btn-save-payment');
    Api.clearFieldErrors(form);

    const feeId = form.feeRecordId.value;
    const amountVal = form.amount.value;
    const paymentDateVal = form.paymentDate.value;
    const methodVal = form.method.value;
    const noteVal = form.note.value.trim();

    const fields = {};
    if (!feeId) {
      fields.feeRecordId = 'Vui lòng chọn hồ sơ học phí.';
    }

    const amtRes = parseClientCurrency(amountVal);
    if (!amtRes.valid) {
      fields.amount = amtRes.error;
    }

    const dateRes = parseClientDate(paymentDateVal, { disallowFuture: true, fieldName: 'Ngày nộp' });
    if (!dateRes.valid) {
      fields.paymentDate = dateRes.error;
    }

    if (!methodVal) {
      fields.method = 'Vui lòng chọn phương thức thanh toán.';
    }

    if (noteVal && Array.from(noteVal).length > 200) {
      fields.note = 'Ghi chú không được vượt quá 200 ký tự.';
    }

    if (Object.keys(fields).length > 0) {
      Api.renderFieldErrors(form, fields);
      return;
    }

    const body = {
      amount: amtRes.value,
      method: methodVal,
      entry_date: dateRes.iso,
      note: noteVal || null,
      request_id: Api.generateUUID()
    };

    try {
      const res = await Api.post(`/fee-records/${feeId}/payments`, body, btn);
      showToast('Ghi nhận phiếu thu học phí thành công.');
      document.getElementById('modal-payment').close();
      await refreshFeeRecord(feeId);
      await loadFeesData();
      await loadPaymentsData();
      await loadReportsData();

      if (res && res.data && res.data.id) {
        openReceiptView(res.data.id);
      }
    } catch (err) {
      if (err.fields) Api.renderFieldErrors(form, err.fields);
      else showToast(err.message, 'error');
    }
  }

  /* Hoàn tiền (Refunds) */

  async function openRefundModalWithFee(feeRecordId) {
    const modal = document.getElementById('modal-refund');
    const form = document.getElementById('refund-form');
    Api.clearFieldErrors(form);
    form.reset();

    try {
      const res = await Api.get(`/fee-records/${feeRecordId}`);
      if (!res.ok) return;
      const fee = res.data;
      AppState.selectedFeeForRefund = fee;

      form.dataset.feeId = fee.fee_record_id;
      setElementText('refund-student-name', `${fee.student_code} - ${fee.full_name}`);
      setElementText('refund-semester', fee.semester_label);
      setElementText('refund-paid-amount', Calc.formatVND(fee.total_paid));
      setElementText('refund-net-amount', Calc.formatVND(fee.net_due));

      const excess = Math.max(0, fee.total_paid - fee.net_due);
      const pending = fee.pending_refunds_sum || 0;
      const available = Math.max(0, excess - pending);

      setElementText('refund-max-amount', Calc.formatVND(available));
      form.refundAmount.value = Calc.formatVND(available).replace('₫', '').trim();

      modal.showModal();
    } catch (err) {
      showToast(err.message, true);
    }
  }

  async function handleRefundSubmit(e) {
    e.preventDefault();
    const form = document.getElementById('refund-form');
    const btn = document.getElementById('btn-save-refund');
    Api.clearFieldErrors(form);

    const feeId = form.dataset.feeId;
    const amountVal = form.refundAmount.value;
    const reasonVal = form.refundReason.value;

    const fields = {};
    const amtRes = parseClientCurrency(amountVal);
    if (!amtRes.valid) {
      fields.refundAmount = amtRes.error;
    }

    const reasonRes = validateClientReason(reasonVal, { required: true, fieldName: 'Lý do hoàn tiền' });
    if (!reasonRes.valid) {
      fields.refundReason = reasonRes.error;
    }

    if (Object.keys(fields).length > 0) {
      Api.renderFieldErrors(form, fields);
      return;
    }

    const body = {
      amount: amtRes.value,
      reason: reasonRes.value,
      request_id: Api.generateUUID()
    };

    try {
      await Api.post(`/fee-records/${feeId}/refunds`, body, btn);
      showToast('Tạo yêu cầu hoàn tiền thành công.');
      document.getElementById('modal-refund').close();
      await refreshFeeRecord(feeId);
      await Promise.all([
        loadFeesData(),
        loadPaymentsData(),
        loadApprovalQueue(),
        loadReportsData(),
        updateNavBadges()
      ]);
    } catch (err) {
      if (err.fields) Api.renderFieldErrors(form, err.fields);
      else showToast(err.message, 'error');
    }
  }

  async function completeRefund(refundId, feeRecordId) {
    showConfirm(
      'Duyệt hoàn tiền',
      'Bạn có chắc chắn muốn xác nhận hoàn tiền cho sinh viên?',
      async () => {
        try {
          const res = await Api.post(`/refunds/${refundId}/complete`);
          showToast('Đã duyệt hoàn tất yêu cầu hoàn tiền.');
          const fId = feeRecordId || (res && res.data && res.data.fee_record_id);
          if (fId) {
            await refreshFeeRecord(fId);
          }
          await Promise.all([
            loadFeesData(),
            loadPaymentsData(),
            loadApprovalQueue(),
            loadReportsData(),
            updateNavBadges()
          ]);
        } catch (err) {
          showToast(err.message, 'error');
        }
      }
    );
  }

  function openCancelRefundModal(refundId, feeRecordId) {
    const modal = document.getElementById('modal-cancel-refund');
    const form = document.getElementById('cancel-refund-form');
    if (!modal || !form) return;
    Api.clearFieldErrors(form);
    form.reset();
    document.getElementById('cancel-refund-id').value = refundId;
    document.getElementById('cancel-refund-fee-id').value = feeRecordId || '';
    const counterEl = document.getElementById('cancel-refund-char-num');
    if (counterEl) counterEl.textContent = '0';
    modal.showModal();
  }

  async function handleCancelRefundSubmit(e) {
    e.preventDefault();
    const form = document.getElementById('cancel-refund-form');
    const btn = document.getElementById('btn-confirm-cancel-refund');
    Api.clearFieldErrors(form);

    const refundId = document.getElementById('cancel-refund-id').value;
    const feeRecordId = document.getElementById('cancel-refund-fee-id').value;
    const reason = document.getElementById('cancel-refund-reason').value.trim();

    const reasonRes = validateClientReason(reason, { required: true, fieldName: 'Lý do hủy yêu cầu hoàn tiền' });
    if (!reasonRes.valid) {
      Api.renderFieldErrors(form, { cancel_reason: reasonRes.error });
      return;
    }

    try {
      const res = await Api.post(`/refunds/${refundId}/cancel`, { cancel_reason: reason }, btn);
      showToast('Đã hủy yêu cầu hoàn tiền.');
      document.getElementById('modal-cancel-refund').close();
      const fId = feeRecordId || (res && res.data && res.data.fee_record_id);
      if (fId) {
        await refreshFeeRecord(fId);
      }
      await Promise.all([
        loadFeesData(),
        loadPaymentsData(),
        loadApprovalQueue(),
        loadReportsData(),
        updateNavBadges()
      ]);
    } catch (err) {
      if (err.fields) Api.renderFieldErrors(form, err.fields);
      else showToast(err.message, 'error');
    }
  }

  /* ==========================================================================
     XỬ LÝ SỐ TIỀN THỪA (OVERPAID RESOLUTION: REFUND OR TRANSFER)
     ========================================================================== */

  let currentResolutionFee = null;
  let currentResolutionTargets = [];

  async function openOverpaidResolutionModal(feeRecordId) {
    const modal = document.getElementById('modal-overpaid-resolution');
    const form = document.getElementById('overpaid-form');
    if (!modal || !form) return;

    Api.clearFieldErrors(form);
    form.reset();

    try {
      const res = await Api.get(`/fee-records/${feeRecordId}`);
      if (!res.ok || !res.data) return;
      const fee = res.data;
      currentResolutionFee = fee;

      const netDue = Math.max(0, parseInt(fee.net_due, 10) || 0);
      const totalPaid = Math.max(0, parseInt(fee.total_paid, 10) || 0);
      const excess = Math.max(0, totalPaid - netDue);
      const pendingRefund = Math.max(0, parseInt(fee.pending_refunds_sum, 10) || 0);
      const availableExcess = Math.max(0, excess - pendingRefund);

      if (availableExcess <= 0) {
        showToast('Hồ sơ không có số tiền thừa khả dụng để xử lý.', true);
        return;
      }

      document.getElementById('overpaid-fee-id').value = fee.fee_record_id;
      setElementText('overpaid-student-name', `${fee.student_code} - ${fee.full_name}`);
      setElementText('overpaid-semester', fee.semester_label);
      setElementText('overpaid-net-due', Calc.formatVND(netDue));
      setElementText('overpaid-total-paid', Calc.formatVND(totalPaid));
      setElementText('overpaid-excess', Calc.formatVND(excess));
      setElementText('overpaid-pending-refund', Calc.formatVND(pendingRefund));
      setElementText('overpaid-available-excess', Calc.formatVND(availableExcess));

      // Fetch student's other fee records
      const stRes = await Api.get(`/fee-records?student=${encodeURIComponent(fee.student_id)}`);
      let otherRecords = [];
      if (stRes.ok && Array.isArray(stRes.data)) {
        otherRecords = stRes.data.filter(r => String(r.fee_record_id) !== String(fee.fee_record_id));
      }

      // Sort targets: nearest later semester first by (academic_year_start, term_no)
      otherRecords.sort((a, b) => {
        const aLater = (a.academic_year_start > fee.academic_year_start) || (a.academic_year_start === fee.academic_year_start && a.term_no > fee.term_no);
        const bLater = (b.academic_year_start > fee.academic_year_start) || (b.academic_year_start === fee.academic_year_start && b.term_no > fee.term_no);

        if (aLater && !bLater) return -1;
        if (!aLater && bLater) return 1;

        if (aLater && bLater) {
          if (a.academic_year_start !== b.academic_year_start) return a.academic_year_start - b.academic_year_start;
          return a.term_no - b.term_no;
        }

        if (a.academic_year_start !== b.academic_year_start) return b.academic_year_start - a.academic_year_start;
        return b.term_no - a.term_no;
      });

      currentResolutionTargets = otherRecords;

      // Populate target select
      const targetSelect = document.getElementById('res-target-select');
      targetSelect.innerHTML = '<option value="">-- Chọn hồ sơ học phí của sinh viên --</option>';
      otherRecords.forEach(t => {
        const isLater = (t.academic_year_start > fee.academic_year_start) || (t.academic_year_start === fee.academic_year_start && t.term_no > fee.term_no);
        const tag = isLater ? ' [Kỳ kế tiếp]' : '';
        const opt = document.createElement('option');
        opt.value = t.fee_record_id;
        opt.textContent = `${t.semester_label}${tag} — Phải nộp: ${Calc.formatVND(t.net_due)}, Đã nộp: ${Calc.formatVND(t.total_paid)}, Còn nợ: ${Calc.formatVND(t.outstanding)}`;
        targetSelect.appendChild(opt);
      });

      // Reset options - neither option checked initially
      document.getElementById('radio-res-refund').checked = false;
      document.getElementById('radio-res-transfer').checked = false;
      document.getElementById('section-res-refund').style.display = 'none';
      document.getElementById('section-res-transfer').style.display = 'none';
      document.getElementById('res-target-info-box').style.display = 'none';
      document.getElementById('res-transfer-preview-box').style.display = 'none';
      document.getElementById('btn-submit-overpaid').disabled = true;

      // Defaults
      document.getElementById('res-refund-amount').value = Calc.formatVND(availableExcess).replace('₫', '').trim();
      document.getElementById('res-refund-reason').value = 'Hoàn trả số tiền học phí nộp thừa';
      document.getElementById('res-transfer-amount').value = '';
      document.getElementById('res-transfer-reason').value = 'Chuyển tiền thừa sang học phí học kỳ tiếp theo';

      modal.showModal();
    } catch (err) {
      showToast(err.message, true);
    }
  }

  function updateResolutionUI() {
    const isRefund = document.getElementById('radio-res-refund').checked;
    const isTransfer = document.getElementById('radio-res-transfer').checked;
    const sectionRefund = document.getElementById('section-res-refund');
    const sectionTransfer = document.getElementById('section-res-transfer');
    const submitBtn = document.getElementById('btn-submit-overpaid');

    if (sectionRefund) sectionRefund.style.display = isRefund ? 'block' : 'none';
    if (sectionTransfer) sectionTransfer.style.display = isTransfer ? 'block' : 'none';

    if (!isRefund && !isTransfer) {
      if (submitBtn) submitBtn.disabled = true;
      return;
    }

    if (isRefund) {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Tạo yêu cầu hoàn tiền';
      }
      return;
    }

    if (isTransfer) {
      if (submitBtn) {
        submitBtn.textContent = 'Xác nhận chuyển sang hồ sơ khác';
      }
      updateTransferPreview();
    }
  }

  function updateTransferPreview(isTargetChange = false) {
    const targetSelect = document.getElementById('res-target-select');
    const targetId = targetSelect?.value;
    const targetInfoBox = document.getElementById('res-target-info-box');
    const previewBox = document.getElementById('res-transfer-preview-box');
    const submitBtn = document.getElementById('btn-submit-overpaid');
    const amountInput = document.getElementById('res-transfer-amount');

    if (!targetId || !currentResolutionFee) {
      if (targetInfoBox) targetInfoBox.style.display = 'none';
      if (previewBox) previewBox.style.display = 'none';
      if (submitBtn) submitBtn.disabled = true;
      return;
    }

    const target = currentResolutionTargets.find(t => String(t.fee_record_id) === String(targetId));
    if (!target) {
      if (targetInfoBox) targetInfoBox.style.display = 'none';
      if (previewBox) previewBox.style.display = 'none';
      if (submitBtn) submitBtn.disabled = true;
      return;
    }

    if (targetInfoBox) targetInfoBox.style.display = 'block';
    setElementText('res-target-sem-label', target.semester_label);
    setElementText('res-target-net', Calc.formatVND(target.net_due));
    setElementText('res-target-paid', Calc.formatVND(target.total_paid));
    setElementText('res-target-outstanding', Calc.formatVND(target.outstanding));

    const sourceExcess = Math.max(0, currentResolutionFee.total_paid - currentResolutionFee.net_due);
    const sourcePending = currentResolutionFee.pending_refunds_sum || 0;
    const availableExcess = Math.max(0, sourceExcess - sourcePending);
    const targetOutstanding = Math.max(0, target.outstanding);

    if (isTargetChange || !amountInput.value || Calc.parseFormattedCurrency(amountInput.value) <= 0) {
      const defaultAmt = Math.min(availableExcess, targetOutstanding);
      amountInput.value = Calc.formatVND(defaultAmt).replace('₫', '').trim();
    }

    const transferAmt = Calc.parseFormattedCurrency(amountInput.value) || 0;

    const sourcePaidAfter = Math.max(0, currentResolutionFee.total_paid - transferAmt);
    const sourceExcessAfter = Math.max(0, sourcePaidAfter - currentResolutionFee.net_due);

    const targetPaidAfter = target.total_paid + transferAmt;
    const targetOutstandingAfter = Math.max(0, target.net_due - targetPaidAfter);

    setElementText('preview-source-sem', currentResolutionFee.semester_label);
    setElementText('preview-source-paid', Calc.formatVND(sourcePaidAfter));
    setElementText('preview-source-excess', Calc.formatVND(sourceExcessAfter));

    setElementText('preview-target-sem', target.semester_label);
    setElementText('preview-target-paid', Calc.formatVND(targetPaidAfter));
    setElementText('preview-target-outstanding', Calc.formatVND(targetOutstandingAfter));

    if (previewBox) previewBox.style.display = 'block';
    if (submitBtn) submitBtn.disabled = (targetOutstanding <= 0 || transferAmt <= 0 || transferAmt > availableExcess || transferAmt > targetOutstanding);
  }

  async function handleOverpaidSubmit(e) {
    e.preventDefault();
    if (!currentResolutionFee) return;

    const form = document.getElementById('overpaid-form');
    Api.clearFieldErrors(form);

    const isRefund = document.getElementById('radio-res-refund').checked;
    const isTransfer = document.getElementById('radio-res-transfer').checked;
    const feeId = currentResolutionFee.fee_record_id;

    if (!isRefund && !isTransfer) {
      showToast('Vui lòng chọn hình thức xử lý số tiền thừa (Hoàn tiền hoặc Chuyển tiền).', 'error');
      return;
    }

    const sourceExcess = Math.max(0, currentResolutionFee.total_paid - currentResolutionFee.net_due);
    const sourcePending = currentResolutionFee.pending_refunds_sum || 0;
    const availableExcess = Math.max(0, sourceExcess - sourcePending);

    if (isRefund) {
      const amountInput = document.getElementById('res-refund-amount');
      const reasonInput = document.getElementById('res-refund-reason');
      const fields = {};

      const amtRes = parseClientCurrency(amountInput.value);
      if (!amtRes.valid) {
        fields.refundAmount = amtRes.error;
      } else if (amtRes.value > availableExcess) {
        fields.refundAmount = `Số tiền hoàn không được vượt quá số dư thừa khả dụng (${Calc.formatVND(availableExcess)}).`;
      }

      const reasonRes = validateClientReason(reasonInput.value, { required: true, fieldName: 'Lý do hoàn tiền' });
      if (!reasonRes.valid) {
        fields.refundReason = reasonRes.error;
      }

      if (Object.keys(fields).length > 0) {
        Api.renderFieldErrors(form, fields);
        return;
      }

      const body = {
        amount: amtRes.value,
        reason: reasonRes.value,
        request_id: Api.generateUUID()
      };

      const btn = document.getElementById('btn-submit-overpaid');
      try {
        await Api.post(`/fee-records/${feeId}/refunds`, body, btn);
        showToast('Đã tạo yêu cầu hoàn tiền thành công.');
        document.getElementById('modal-overpaid-resolution').close();
        await refreshFeeRecord(feeId);
        await Promise.all([
          loadFeesData(),
          loadPaymentsData(),
          loadApprovalQueue(),
          loadReportsData(),
          updateNavBadges()
        ]);
      } catch (err) {
        if (err.fields) Api.renderFieldErrors(form, err.fields);
        else showToast(err.message, 'error');
      }
      return;
    }

    if (isTransfer) {
      const targetSelect = document.getElementById('res-target-select');
      const amountInput = document.getElementById('res-transfer-amount');
      const reasonInput = document.getElementById('res-transfer-reason');
      const fields = {};

      const targetId = parseInt(targetSelect.value, 10);
      if (!targetId) {
        fields.targetFeeRecordId = 'Vui lòng chọn hồ sơ đích tiếp nhận.';
      }

      const amtRes = parseClientCurrency(amountInput.value);
      if (!amtRes.valid) {
        fields.transferAmount = amtRes.error;
      }

      const target = currentResolutionTargets.find(t => String(t.fee_record_id) === String(targetId));
      if (target) {
        if (target.outstanding <= 0) {
          fields.targetFeeRecordId = 'Hồ sơ đích không còn số còn nợ.';
        }
        if (amtRes.valid && amtRes.value > availableExcess) {
          fields.transferAmount = `Số tiền chuyển không được vượt quá số dư thừa khả dụng (${Calc.formatVND(availableExcess)}).`;
        } else if (amtRes.valid && amtRes.value > target.outstanding) {
          fields.transferAmount = `Số tiền chuyển không được vượt quá số còn nợ của hồ sơ đích (${Calc.formatVND(target.outstanding)}).`;
        }
      }

      const reasonRes = validateClientReason(reasonInput.value, { required: true, fieldName: 'Lý do chuyển tiền' });
      if (!reasonRes.valid) {
        fields.transferReason = reasonRes.error;
      }

      if (Object.keys(fields).length > 0) {
        Api.renderFieldErrors(form, fields);
        return;
      }

      const targetLabel = target ? (target.semester_label || `Hồ sơ #${targetId}`) : `Hồ sơ #${targetId}`;

      showConfirm(
        'Xác nhận chuyển số tiền thừa',
        `Bạn có chắc chắn muốn chuyển ${Calc.formatVND(amtRes.value)} từ hồ sơ [${currentResolutionFee.semester_label}] sang hồ sơ [${targetLabel}] không? Thao tác này sẽ có hiệu lực ngay và không thể hoàn tác trên giao diện.`,
        async () => {
          const body = {
            target_fee_record_id: targetId,
            amount: amtRes.value,
            reason: reasonRes.value,
            request_id: Api.generateUUID()
          };

          const btn = document.getElementById('btn-submit-overpaid');
          try {
            await Api.post(`/fee-records/${feeId}/transfers`, body, btn);
            showToast('Chuyển tiền thừa sang hồ sơ khác thành công.');
            document.getElementById('modal-overpaid-resolution').close();
            await refreshFeeRecord(feeId);
            await refreshFeeRecord(targetId);
            await Promise.all([
              loadFeesData(),
              loadPaymentsData(),
              loadApprovalQueue(),
              loadReportsData(),
              updateNavBadges()
            ]);
          } catch (err) {
            if (err.fields) Api.renderFieldErrors(form, err.fields);
            else showToast(err.message, 'error');
          }
        }
      );
    }
  }

  /* Đảo mục (Reversal) */

  function openReversalModal(tx) {
    const modal = document.getElementById('modal-reversal');
    const form = document.getElementById('reversal-form');
    Api.clearFieldErrors(form);
    form.reset();

    AppState.selectedTxForReversal = tx;
    form.dataset.entryId = tx.id;

    setElementText('reversal-receipt-no', tx.receipt_no || `#${tx.id}`);
    setElementText('reversal-orig-type', tx.type === 'payment' ? 'Thu học phí' : tx.type);
    setElementText('reversal-amount', Calc.formatVND(tx.amount));

    const counterEl = document.getElementById('reversal-char-num');
    if (counterEl) counterEl.textContent = '0';

    modal.showModal();
  }

  async function handleReversalSubmit(e) {
    e.preventDefault();
    const form = document.getElementById('reversal-form');
    const btn = document.getElementById('btn-confirm-reversal');
    Api.clearFieldErrors(form);

    const entryId = form.dataset.entryId;
    const reason = form.reversalReason.value.trim();

    const reasonRes = validateClientReason(reason, { required: true, fieldName: 'Lý do đảo mục' });
    if (!reasonRes.valid) {
      Api.renderFieldErrors(form, { reversalReason: reasonRes.error });
      return;
    }

    const body = {
      reason: reasonRes.value,
      request_id: Api.generateUUID()
    };

    try {
      await Api.post(`/ledger/${entryId}/reversal`, body, btn);
      showToast('Đảo mục giao dịch thành công. Số dư nợ đã được khôi phục.');
      document.getElementById('modal-reversal').close();
      const feeId = AppState.selectedTxForReversal?.fee_record_id || AppState.selectedTxForReversal?.feeRecordId;
      if (feeId) {
        await refreshFeeRecord(feeId);
      }
      await loadFeesData();
      await loadPaymentsData();
      await loadReportsData();
    } catch (err) {
      if (err.fields) Api.renderFieldErrors(form, err.fields);
      else showToast(err.message, 'error');
    }
  }

  /* Tải bảng giao dịch & Yêu cầu hoàn tiền */

  async function loadPaymentsData() {
    try {
      const [txRes, rfRes] = await Promise.all([
        Api.get('/history'),
        Api.get('/refunds')
      ]);
      if (txRes && txRes.ok) {
        renderTransactionsTable(txRes.data || []);
      }
      if (rfRes && rfRes.ok) {
        renderRefundsTable(rfRes.data || []);
      }
    } catch (err) {
      showToast('Lỗi tải dữ liệu thu chi & hoàn trả: ' + err.message, true);
    }
  }

  function renderRefundsTable(refunds) {
    const countEl = document.getElementById('pending-refund-count');
    const pendingCount = Array.isArray(refunds) ? refunds.filter(r => r.status === 'pending').length : 0;
    if (countEl) {
      countEl.textContent = pendingCount;
    }

    const tbody = document.getElementById('refunds-table-body');
    if (!tbody) return;

    if (!refunds || refunds.length === 0) {
      tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">Chưa có yêu cầu hoàn tiền nào.</td></tr>';
      return;
    }

    const currentUserId = AppState.currentUser ? AppState.currentUser.id : null;

    tbody.innerHTML = refunds.map(r => {
      let statusBadge = 'badge-secondary';
      let statusLabel = r.status;
      if (r.status === 'pending') {
        statusBadge = 'badge-warning';
        statusLabel = 'Chờ duyệt';
      } else if (r.status === 'completed') {
        statusBadge = 'badge-success';
        statusLabel = 'Đã hoàn tất';
      } else if (r.status === 'cancelled') {
        statusBadge = 'badge-secondary';
        statusLabel = 'Đã hủy';
      }

      const isCreator = (Number(r.created_by) === Number(currentUserId));
      const canAction = (r.status === 'pending');
      let actionContent = '-';
      if (canAction) {
        actionContent = isCreator ? `
          <div class="action-cell-content">
            <button type="button" class="btn btn-sm btn-outline-success" disabled>✓ Duyệt</button>
            <button type="button" class="btn btn-sm btn-outline-danger btn-cancel-refund-item" data-id="${r.id}" data-fee-id="${r.fee_record_id}">✕ Hủy</button>
            <div class="maker-note">Không thể tự duyệt yêu cầu hoàn tiền do chính bạn tạo.</div>
          </div>
        ` : `
          <div class="action-cell-content">
            <button type="button" class="btn btn-sm btn-outline-success btn-complete-refund" data-id="${r.id}" data-fee-id="${r.fee_record_id}">✓ Duyệt</button>
            <button type="button" class="btn btn-sm btn-outline-danger btn-cancel-refund-item" data-id="${r.id}" data-fee-id="${r.fee_record_id}">✕ Hủy</button>
          </div>
        `;
      } else if (r.status === 'completed') {
        actionContent = `<small class="text-success font-weight-bold">Đã duyệt (${escapeHTML(r.completed_by_name || 'Admin')})</small>`;
      } else if (r.status === 'cancelled') {
        actionContent = `<small class="text-muted">Đã hủy${r.cancel_reason ? ': ' + escapeHTML(r.cancel_reason) : ''}</small>`;
      }

      return `
        <tr>
          <td><strong>YC-${String(r.id).padStart(4, '0')}</strong></td>
          <td>${Calc.formatDateVN(r.created_at)}</td>
          <td>${escapeHTML(r.student_code || '-')}</td>
          <td>${escapeHTML(r.student_name || r.full_name || '-')}</td>
          <td>${escapeHTML(r.semester_label || '-')}</td>
          <td class="text-right text-danger"><strong>${Calc.formatVND(r.amount)}</strong></td>
          <td>${escapeHTML(r.reason || '-')}</td>
          <td><span class="badge ${statusBadge}">${statusLabel}</span></td>
          <td class="text-center">${actionContent}</td>
        </tr>
      `;
    }).join('');

    tbody.querySelectorAll('.btn-complete-refund').forEach(btn => {
      btn.addEventListener('click', () => completeRefund(btn.dataset.id, btn.dataset.feeId));
    });
    tbody.querySelectorAll('.btn-cancel-refund-item').forEach(btn => {
      btn.addEventListener('click', () => openCancelRefundModal(btn.dataset.id, btn.dataset.feeId));
    });
  }

  /* ==========================================================================
     HÀNG ĐỢI DUYỆT HOÀN TIỀN (APPROVAL QUEUE - PART 2)
     ========================================================================== */

  async function loadApprovalQueue() {
    try {
      const res = await Api.get('/refunds?status=pending');
      if (res && res.ok) {
        renderApprovalQueue(res.data || []);
      }
    } catch (err) {
      showToast('Lỗi tải danh sách hoàn tiền chờ duyệt: ' + err.message, 'error');
    }
  }

  function renderApprovalQueue(refunds) {
    const tbody = document.getElementById('approval-queue-table-body');
    if (!tbody) return;

    if (!refunds || refunds.length === 0) {
      tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">Không có yêu cầu hoàn tiền nào đang chờ duyệt.</td></tr>';
      return;
    }

    const currentUserId = AppState.currentUser ? AppState.currentUser.id : null;

    tbody.innerHTML = refunds.map(r => {
      const isCreator = (Number(r.created_by) === Number(currentUserId));
      const actionHtml = isCreator ? `
        <div class="action-cell-content">
          <button type="button" class="btn btn-sm btn-outline-success" disabled>✓ Duyệt</button>
          <button type="button" class="btn btn-sm btn-outline-danger btn-cancel-refund-item" data-id="${r.id}" data-fee-id="${r.fee_record_id}">✕ Hủy</button>
          <div class="maker-note">Không thể tự duyệt yêu cầu hoàn tiền do chính bạn tạo.</div>
        </div>
      ` : `
        <div class="action-cell-content">
          <button type="button" class="btn btn-sm btn-outline-success btn-complete-refund" data-id="${r.id}" data-fee-id="${r.fee_record_id}">✓ Duyệt</button>
          <button type="button" class="btn btn-sm btn-outline-danger btn-cancel-refund-item" data-id="${r.id}" data-fee-id="${r.fee_record_id}">✕ Hủy</button>
        </div>
      `;

      return `
        <tr>
          <td><strong>YC-${String(r.id).padStart(4, '0')}</strong></td>
          <td>${Calc.formatDateVN(r.created_at)}</td>
          <td>${escapeHTML(r.student_code || '-')}</td>
          <td>${escapeHTML(r.student_name || r.full_name || '-')}</td>
          <td>${escapeHTML(r.semester_label || '-')}</td>
          <td class="text-right text-danger"><strong>${Calc.formatVND(r.amount)}</strong></td>
          <td>${escapeHTML(r.reason || '-')}</td>
          <td>${escapeHTML(r.created_by_name || 'Admin')}</td>
          <td class="text-center">${actionHtml}</td>
        </tr>
      `;
    }).join('');

    tbody.querySelectorAll('.btn-complete-refund').forEach(btn => {
      btn.addEventListener('click', () => completeRefund(btn.dataset.id, btn.dataset.feeId));
    });
    tbody.querySelectorAll('.btn-cancel-refund-item').forEach(btn => {
      btn.addEventListener('click', () => openCancelRefundModal(btn.dataset.id, btn.dataset.feeId));
    });
  }

  function renderTransactionsTable(txs) {
    const tbody = document.getElementById('transactions-table-body');
    if (!tbody) return;

    if (txs.length === 0) {
      tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted">Chưa có giao dịch sổ quỹ nào.</td></tr>';
      return;
    }

    tbody.innerHTML = txs.map(tx => {
      const isPayment = (tx.type === 'payment');
      const isRev = (tx.type === 'reversal');
      const isTransferOut = (tx.type === 'transfer_out');
      const isTransferIn = (tx.type === 'transfer_in');
      const typeLabel = (Calc.TX_TYPE_LABELS && Calc.TX_TYPE_LABELS[tx.type]) || (isPayment ? 'Thu học phí' : (isRev ? 'Đảo mục (Hủy)' : tx.type));
      let typeBadge = 'badge-secondary';
      if (isPayment) typeBadge = 'badge-success';
      else if (isRev) typeBadge = 'badge-danger';
      else if (isTransferOut) typeBadge = 'badge-warning';
      else if (isTransferIn) typeBadge = 'badge-info';

      const isNegative = isRev || isTransferOut;
      const isPositive = isPayment || isTransferIn;
      const amtColor = isPositive ? 'text-success' : (isNegative ? 'text-danger' : '');
      const amtSign = isNegative ? '-' : (isTransferIn ? '+' : '');

      return `
        <tr>
          <td><strong>${escapeHTML(tx.receipt_no || '-')}</strong></td>
          <td>${Calc.formatDateVN(tx.entry_date)}</td>
          <td>${escapeHTML(tx.student_code)}</td>
          <td>${escapeHTML(tx.student_name)}</td>
          <td>${escapeHTML(tx.semester_label)}</td>
          <td><span class="badge ${typeBadge}">${typeLabel}</span></td>
          <td class="text-right ${amtColor}"><strong>${amtSign}${Calc.formatVND(tx.amount)}</strong></td>
          <td>${escapeHTML(tx.method)}</td>
          <td><span class="badge badge-secondary">Hoàn tất</span></td>
          <td><small class="text-muted">${escapeHTML(tx.reason || tx.note || '-')}</small></td>
          <td class="text-center">
            ${isPayment ? `
              <button type="button" class="btn btn-sm btn-outline-primary btn-print-receipt" data-id="${tx.id}">🖨️ In</button>
              <button type="button" class="btn btn-sm btn-outline-danger btn-open-reversal" data-id="${tx.id}">↩️ Đảo mục</button>
            ` : '-'}
          </td>
        </tr>
      `;
    }).join('');

    tbody.querySelectorAll('.btn-print-receipt').forEach(btn => {
      btn.addEventListener('click', () => openReceiptView(btn.dataset.id));
    });
    tbody.querySelectorAll('.btn-open-reversal').forEach(btn => {
      btn.addEventListener('click', () => {
        const tx = txs.find(t => String(t.id) === String(btn.dataset.id));
        if (tx) openReversalModal(tx);
      });
    });
  }

  /* ==========================================================================
     10. IN BIÊN LAI (RECEIPT PRINTING)
     ========================================================================== */

  async function openReceiptView(ledgerEntryId) {
    try {
      const res = await Api.get(`/receipts/${ledgerEntryId}`);
      if (!res.ok) return;
      const data = res.data;

      setElementText('pr-receipt-no', data.receipt_no);
      setElementText('pr-date', Calc.formatDateVN(data.entry_date));
      setElementText('pr-student-name', data.student_name);
      setElementText('pr-student-id', data.student_code);
      setElementText('pr-student-class', data.class_name || '-');
      setElementText('pr-semester', data.semester_label);
      setElementText('pr-method', data.method);
      setElementText('pr-admin-name', data.signer_name || 'Admin');
      setElementText('pr-signer-name', data.signer_name || 'Admin');
      setElementText('pr-amount-vnd', Calc.formatVND(data.amount));
      setElementText('pr-amount-words', data.amount_words);
      setElementText('pr-remaining-balance', Calc.formatVND(data.remaining_balance));

      const printArea = document.getElementById('receipt-print-area');
      if (printArea) {
        printArea.hidden = false;
        printArea.scrollIntoView({ behavior: 'smooth' });
      }
    } catch (err) {
      showToast('Lỗi xem biên lai: ' + err.message, true);
    }
  }

  function closeReceiptView() {
    const printArea = document.getElementById('receipt-print-area');
    if (printArea) printArea.hidden = true;
  }

  async function handleReceiptLookupSubmit(e) {
    if (e && typeof e.preventDefault === 'function') {
      e.preventDefault();
    }
    const form = document.getElementById('receipt-lookup-form');
    const input = document.getElementById('receipt-lookup-input');
    const btn = document.getElementById('btn-lookup-receipt');
    if (!form || !input) return;

    Api.clearFieldErrors(form);

    const val = input.value.trim();
    if (!val) {
      Api.renderFieldErrors(form, { receiptNo: 'Vui lòng nhập số biên lai hoặc mã giao dịch.' });
      return;
    }
    if (Array.from(val).length > 100) {
      Api.renderFieldErrors(form, { receiptNo: 'Mã tra cứu không được vượt quá 100 ký tự.' });
      return;
    }

    try {
      const res = await Api.get('/receipts/' + encodeURIComponent(val), null, btn);
      if (res && res.ok && res.data) {
        openReceiptView(res.data.id || val);
      }
    } catch (err) {
      if (err.fields && Object.keys(err.fields).length > 0) {
        Api.renderFieldErrors(form, err.fields);
      } else {
        Api.renderFieldErrors(form, { receiptNo: err.message || 'Không tìm thấy biên lai phù hợp.' });
      }
    }
  }

  /* ==========================================================================
     11. PHÂN HỆ 6: TRA CỨU LỊCH SỬ (HISTORY SEARCH)
     ========================================================================== */

  async function loadHistoryData() {
    const form = document.getElementById('search-filter-form');
    const keywordInput = document.getElementById('ledger-search-keyword');
    const fromInput = document.getElementById('ledger-filter-from-date');
    const toInput = document.getElementById('ledger-filter-to-date');
    const semester = document.getElementById('ledger-filter-semester')?.value || 'ALL';
    const status = document.getElementById('ledger-filter-status')?.value || 'ALL';

    if (form) Api.clearFieldErrors(form);

    const keyword = keywordInput?.value || '';
    const qRes = validateClientSearchQuery(keyword, 100);
    if (!qRes.valid) {
      if (form) Api.renderFieldErrors(form, { q: qRes.error });
      return;
    }

    const fromVal = fromInput?.value || '';
    const toVal = toInput?.value || '';

    let fromISO = '';
    let toISO = '';

    if (fromVal) {
      const fromParsed = parseClientDate(fromVal, { fieldName: 'Từ ngày' });
      if (!fromParsed.valid) {
        if (form) Api.renderFieldErrors(form, { from: fromParsed.error });
        return;
      }
      fromISO = fromParsed.iso;
    }

    if (toVal) {
      const toParsed = parseClientDate(toVal, { fieldName: 'Đến ngày' });
      if (!toParsed.valid) {
        if (form) Api.renderFieldErrors(form, { to: toParsed.error });
        return;
      }
      toISO = toParsed.iso;
    }

    if (fromISO && toISO) {
      const rangeRes = validateClientDateRange(fromISO, toISO);
      if (!rangeRes.valid) {
        if (form) Api.renderFieldErrors(form, { to: rangeRes.error });
        return;
      }
    }

    let url = `/history?semester=${encodeURIComponent(semester)}`;
    if (qRes.value) url += `&q=${encodeURIComponent(qRes.value)}&name=${encodeURIComponent(qRes.value)}&student=${encodeURIComponent(qRes.value)}`;
    if (fromISO) url += `&from=${encodeURIComponent(fromISO)}`;
    if (toISO) url += `&to=${encodeURIComponent(toISO)}`;
    if (status !== 'ALL') url += `&status=${encodeURIComponent(status)}`;

    try {
      const res = await Api.get(url);
      if (res.ok) {
        renderHistoryTable(res.data || []);
      }
    } catch (err) {
      if (err.fields && form) {
        Api.renderFieldErrors(form, err.fields);
      } else {
        showToast('Lỗi tra cứu lịch sử: ' + err.message, 'error');
      }
    }
  }

  function renderHistoryTable(entries) {
    const tbody = document.getElementById('ledger-table-body');
    if (!tbody) return;

    if (entries.length === 0) {
      tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">Không tìm thấy giao dịch nào phù hợp tiêu chí tra cứu.</td></tr>';
      return;
    }

    tbody.innerHTML = entries.map(tx => {
      const isPayment = (tx.type === 'payment');
      const isRev = (tx.type === 'reversal');
      const isTransferOut = (tx.type === 'transfer_out');
      const isTransferIn = (tx.type === 'transfer_in');
      const typeLabel = (Calc.TX_TYPE_LABELS && Calc.TX_TYPE_LABELS[tx.type]) || (isPayment ? 'Thu học phí' : (isRev ? 'Đảo mục (Hủy)' : tx.type));
      let typeBadge = 'badge-secondary';
      if (isPayment) typeBadge = 'badge-success';
      else if (isRev) typeBadge = 'badge-danger';
      else if (isTransferOut) typeBadge = 'badge-warning';
      else if (isTransferIn) typeBadge = 'badge-info';

      const isNegative = isRev || isTransferOut;
      const isPositive = isPayment || isTransferIn;
      const amtColor = isPositive ? 'text-success' : (isNegative ? 'text-danger' : '');
      const amtSign = isNegative ? '-' : (isTransferIn ? '+' : '');
      const balanceDisplay = tx.remaining_balance !== undefined ? Calc.formatVND(tx.remaining_balance) : '-';

      return `
        <tr>
          <td>${Calc.formatDateVN(tx.entry_date)}</td>
          <td><strong>${escapeHTML(tx.receipt_no || '-')}</strong></td>
          <td>${escapeHTML(tx.student_code)}</td>
          <td>${escapeHTML(tx.student_name)}</td>
          <td>${escapeHTML(tx.semester_label)}</td>
          <td><span class="badge ${typeBadge}">${typeLabel}</span></td>
          <td class="text-right ${amtColor}"><strong>${amtSign}${Calc.formatVND(tx.amount)}</strong></td>
          <td>${escapeHTML(tx.method)}</td>
          <td class="text-right"><strong>${balanceDisplay}</strong></td>
          <td class="text-center">
            ${isPayment ? `<button type="button" class="btn btn-sm btn-outline-primary btn-print-receipt" data-id="${tx.id}">🖨️ In biên lai</button>` : '-'}
          </td>
        </tr>
      `;
    }).join('');

    tbody.querySelectorAll('.btn-print-receipt').forEach(btn => {
      btn.addEventListener('click', () => openReceiptView(btn.dataset.id));
    });
  }

  /* ==========================================================================
     11. PHÂN HỆ CỔNG SINH VIÊN (STUDENT PORTAL & ADMIN ACCOUNT MANAGEMENT)
     ========================================================================== */

  // Admin: Mở modal quản lý tài khoản sinh viên
  function openStudentAccountModal(studentId, fullName, studentCode) {
    const modal = document.getElementById('modal-student-account');
    if (!modal) return;
    AppState.selectedStudentAccount = { id: studentId, fullName, studentCode };

    setElementText('sa-student-name', fullName);
    setElementText('sa-student-code', studentCode);

    const actionBox = document.getElementById('sa-action-box');
    const resultBox = document.getElementById('sa-result-box');
    if (actionBox) actionBox.style.display = 'block';
    if (resultBox) resultBox.style.display = 'none';

    modal.showModal();
  }

  async function handleStudentAccountCreate() {
    const st = AppState.selectedStudentAccount;
    if (!st) return;
    const btn = document.getElementById('btn-sa-create');

    try {
      const res = await Api.post(`/students/${st.id}/account`, {}, btn);
      if (res && res.ok && res.data) {
        showStudentAccountResult(res.data);
        showToast('Cấp tài khoản sinh viên thành công!');
      }
    } catch (err) {
      if (err.status === 409) {
        showToast('Sinh viên này đã có tài khoản. Bạn có thể chọn "Đặt lại mật khẩu".', 'error');
      } else {
        showToast(err.message || 'Lỗi cấp tài khoản sinh viên.', 'error');
      }
    }
  }

  async function handleStudentAccountReset() {
    const st = AppState.selectedStudentAccount;
    if (!st) return;
    const btn = document.getElementById('btn-sa-reset');

    try {
      const res = await Api.post(`/students/${st.id}/account/reset`, {}, btn);
      if (res && res.ok && res.data) {
        showStudentAccountResult(res.data);
        showToast('Đặt lại mật khẩu sinh viên thành công!');
      }
    } catch (err) {
      showToast(err.message || 'Lỗi đặt lại mật khẩu.', 'error');
    }
  }

  function showStudentAccountResult(data) {
    const actionBox = document.getElementById('sa-action-box');
    const resultBox = document.getElementById('sa-result-box');
    if (actionBox) actionBox.style.display = 'none';
    if (resultBox) {
      setElementText('sa-res-username', data.username || '');
      setElementText('sa-res-password', data.temporary_password || '');
      resultBox.style.display = 'block';
    }
  }

  function copyStudentPasswordToClipboard() {
    const pwEl = document.getElementById('sa-res-password');
    const btn = document.getElementById('btn-sa-copy-pw');
    if (!pwEl || !pwEl.textContent) return;

    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      navigator.clipboard.writeText(pwEl.textContent).then(() => {
        if (btn) {
          const oldText = btn.textContent;
          btn.textContent = '✓ Đã sao chép!';
          setTimeout(() => { btn.textContent = oldText; }, 2000);
        }
      });
    } else {
      showToast(`Mật khẩu tạm: ${pwEl.textContent}`);
    }
  }

  // Đổi mật khẩu bắt buộc cho sinh viên
  async function handleStudentPasswordSubmit(e) {
    if (e && typeof e.preventDefault === 'function') e.preventDefault();
    const form = document.getElementById('student-password-form');
    const btn = document.getElementById('btn-student-change-pw');
    const errBanner = document.getElementById('change-pw-general-error');
    if (errBanner) { errBanner.hidden = true; errBanner.textContent = ''; }
    Api.clearFieldErrors(form);

    const currentPassword = form.current_password.value;
    const newPassword = form.new_password.value;
    const newPasswordConfirmation = form.new_password_confirmation.value;

    const fields = {};
    if (!currentPassword) {
      fields.current_password = 'Vui lòng nhập mật khẩu hiện tại.';
    }
    if (!newPassword) {
      fields.new_password = 'Vui lòng nhập mật khẩu mới.';
    } else if (newPassword.length < 8 || !/[a-zA-Z]/.test(newPassword) || !/[0-9]/.test(newPassword)) {
      fields.new_password = 'Mật khẩu mới phải có ít nhất 8 ký tự, bao gồm chữ cái và chữ số.';
    }
    if (!newPasswordConfirmation) {
      fields.new_password_confirmation = 'Vui lòng xác nhận mật khẩu mới.';
    } else if (newPassword !== newPasswordConfirmation) {
      fields.new_password_confirmation = 'Xác nhận mật khẩu mới không khớp.';
    }

    if (Object.keys(fields).length > 0) {
      Api.renderFieldErrors(form, fields);
      return;
    }

    try {
      const res = await Api.post('/student/password', {
        current_password: currentPassword,
        new_password: newPassword,
        new_password_confirmation: newPasswordConfirmation
      }, btn);

      if (res && res.ok) {
        showToast('Đổi mật khẩu thành công! Chào mừng bạn đến với cổng sinh viên.');
        if (AppState.currentUser) {
          AppState.currentUser.must_change_password = 0;
          onLoginSuccess(AppState.currentUser);
        } else {
          checkAuthSession();
        }
      }
    } catch (err) {
      if (err.fields && Object.keys(err.fields).length > 0) {
        Api.renderFieldErrors(form, err.fields);
      } else {
        showToast(err.message || 'Lỗi đổi mật khẩu.', 'error');
      }
      if (errBanner) {
        errBanner.textContent = err.message || 'Lỗi đổi mật khẩu.';
        errBanner.hidden = false;
        errBanner.style.display = 'block';
      }
    }
  }

  // 1. Sinh viên: Đăng ký tín chỉ
  async function loadStudentSemesters() {
    const listEl = document.getElementById('student-semesters-list');
    if (!listEl) return;
    listEl.innerHTML = '<div class="text-center text-muted" style="padding: 2rem;">Đang tải danh sách học kỳ mở...</div>';

    try {
      const res = await Api.get('/student/semesters');
      if (!res || !res.ok) throw new Error(res.error?.message || 'Không thể tải học kỳ.');
      const sems = res.data || [];
      AppState.studentSemesters = sems;

      if (sems.length === 0) {
        listEl.innerHTML = '<div class="empty-state" style="padding: 3rem 1rem; text-align: center; background: #fff; border-radius: 8px; border: 1px dashed #cbd5e1;"><p style="font-size: 1.1rem; color: #64748b;">Hiện không có học kỳ nào đang mở đăng ký tín chỉ.</p></div>';
        return;
      }

      listEl.innerHTML = sems.map(s => {
        const reg = s.current_registration;
        const isRegistered = !!reg;
        const creditsVal = isRegistered ? reg.registered_credits : (s.min_credits || 1);
        const rate = s.price_per_credit || 0;
        const hasPaid = isRegistered && (Number(reg.total_paid) > 0);
        const exemptionAmount = reg ? Number(reg.exemption_amount || 0) : 0;
        const initialGross = Calc.calculateGross(creditsVal, rate);
        const initialNet = Calc.calculateNetDue(initialGross, exemptionAmount);

        return `
          <div class="semester-card" id="sem-card-${s.id}">
            <div class="semester-card-header">
              <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                <h3>${escapeHTML(s.label)}</h3>
                <span class="badge badge-success">Đang mở đăng ký</span>
              </div>
              ${s.registration_close ? `<small class="text-muted" style="display: block; margin-top: 0.25rem;">Hạn chót đăng ký: <strong>${Calc.formatDateVN(s.registration_close)}</strong></small>` : ''}
            </div>

            <div class="semester-details">
              <p style="margin: 0.25rem 0;">Định mức học phí: <strong>${Calc.formatVND(rate)} / tín chỉ</strong></p>
              <p style="margin: 0.25rem 0;">Giới hạn tín chỉ: <strong>Từ ${s.min_credits} đến ${s.max_credits} tín chỉ</strong></p>
            </div>

            ${isRegistered ? `
              <div class="semester-reg-status">
                <strong style="color: #0369a1;">Trạng thái đăng ký hiện tại:</strong>
                <ul style="margin: 0.5rem 0 0; padding-left: 1.25rem; font-size: 0.875rem;">
                  <li>Số tín chỉ đã đăng ký: <strong>${reg.registered_credits} tín chỉ</strong></li>
                  <li>Học phí gốc (Gross): <strong>${Calc.formatVND(reg.gross_tuition)}</strong></li>
                  <li>Học phí thực nộp (Net): <strong>${Calc.formatVND(reg.net_due)}</strong></li>
                  <li>Đã thanh toán: <strong>${Calc.formatVND(reg.total_paid)}</strong></li>
                </ul>
              </div>
            ` : ''}

            ${hasPaid ? `
              <div class="alert-banner alert-warning" style="display: block; margin: 0; font-size: 0.875rem;">
                ⚠️ Đã có thanh toán, vui lòng liên hệ phòng đào tạo để điều chỉnh.
              </div>
            ` : `
              <form class="student-reg-form" data-semester-id="${s.id}" data-rate="${rate}" data-min="${s.min_credits}" data-max="${s.max_credits}" data-exempt="${exemptionAmount}" data-is-edit="${isRegistered ? '1' : '0'}" novalidate>
                <div class="form-error-summary" role="alert" hidden></div>
                <div class="form-group">
                  <label for="student-credits-${s.id}">Số tín chỉ đăng ký (Từ ${s.min_credits} đến ${s.max_credits} tín chỉ) <span class="required">*</span></label>
                  <input type="number" id="student-credits-${s.id}" name="credits" class="form-control student-credits-input" value="${creditsVal}" min="${s.min_credits}" max="${s.max_credits}" step="1" required aria-describedby="student-credits-${s.id}-error">
                  <span id="student-credits-${s.id}-error" class="error-msg" aria-live="polite"></span>
                </div>

                <div class="semester-calc-preview" style="margin-bottom: 1rem;">
                  <div style="margin-bottom: 0.25rem;"><span class="text-muted">Học phí gốc tạm tính (Gross):</span> <strong class="credits-preview-gross" style="color: #1d4ed8; font-size: 1.05rem;">${Calc.formatVND(initialGross)}</strong></div>
                  <div><span class="text-muted">Học phí thực nộp tạm tính (Net):</span> <strong class="credits-preview-net" style="color: #059669; font-size: 1.05rem;">${Calc.formatVND(initialNet)}</strong></div>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-submit-registration">
                  ${isRegistered ? '🔄 Cập nhật số tín chỉ' : '✍️ Xác nhận Đăng ký Tín chỉ'}
                </button>
              </form>
            `}
          </div>
        `;
      }).join('');

      listEl.querySelectorAll('.student-reg-form').forEach(form => {
        const semId = form.dataset.semesterId;
        const rate = Number(form.dataset.rate) || 0;
        const min = Number(form.dataset.min) || 1;
        const max = Number(form.dataset.max) || 30;
        const exemptAmt = Number(form.dataset.exempt) || 0;
        const input = form.querySelector('.student-credits-input');
        const previewGross = form.querySelector('.credits-preview-gross');
        const previewNet = form.querySelector('.credits-preview-net');
        const isEdit = form.dataset.isEdit === '1';

        input.addEventListener('input', () => {
          Api.clearFieldError(form, input, 'credits');
          const val = parseInt(input.value, 10);
          if (!isNaN(val) && val >= 0) {
            const g = Calc.calculateGross(val, rate);
            const n = Calc.calculateNetDue(g, exemptAmt);
            if (previewGross) previewGross.textContent = Calc.formatVND(g);
            if (previewNet) previewNet.textContent = Calc.formatVND(n);
          } else {
            if (previewGross) previewGross.textContent = '0 ₫';
            if (previewNet) previewNet.textContent = '0 ₫';
          }
        });

        form.addEventListener('submit', async (e) => {
          e.preventDefault();
          Api.clearFieldErrors(form);
          const val = parseInt(input.value, 10);
          if (isNaN(val) || val < min || val > max) {
            Api.setFieldError(form, input, `Số tín chỉ phải từ ${min} đến ${max}.`, 'credits');
            return;
          }

          const submitBtn = form.querySelector('.btn-submit-registration');
          const requestId = Api.generateUUID();

          try {
            let res;
            if (isEdit) {
              res = await Api.put(`/student/registrations/${semId}`, {
                credits: val,
                request_id: requestId
              }, submitBtn);
            } else {
              res = await Api.post('/student/registrations', {
                semester_id: Number(semId),
                credits: val,
                request_id: requestId
              }, submitBtn);
            }

            if (res && res.ok) {
              showToast(isEdit ? 'Điều chỉnh số tín chỉ thành công!' : 'Đăng ký tín chỉ thành công!');
              loadStudentSemesters();
            }
          } catch (err) {
            if (err.fields && Object.keys(err.fields).length > 0) {
              Api.renderFieldErrors(form, err.fields);
            } else {
              showToast(err.message || 'Lỗi đăng ký tín chỉ.', 'error');
            }
          }
        });
      });
    } catch (err) {
      listEl.innerHTML = `<div class="error-banner" role="alert" style="display:block;">${escapeHTML(err.message || 'Lỗi tải danh sách học kỳ.')}</div>`;
    }
  }

  // 2. Sinh viên: Công nợ học phí
  async function loadStudentDebts() {
    const totalOutstandingEl = document.getElementById('student-total-outstanding');
    const unpaidBody = document.getElementById('student-unpaid-debts-body');
    const allBody = document.getElementById('student-all-debts-body');
    if (!unpaidBody || !allBody) return;

    unpaidBody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">Đang tải công nợ...</td></tr>';
    allBody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">Đang tải hồ sơ học phí...</td></tr>';

    try {
      const res = await Api.get('/student/debts');
      if (!res || !res.ok) throw new Error(res.error?.message || 'Không thể tải công nợ.');
      const data = res.data || {};
      AppState.studentDebts = data;

      if (totalOutstandingEl) {
        totalOutstandingEl.textContent = Calc.formatVND(data.total_outstanding || 0);
      }

      const unpaidRecords = data.outstanding_records || [];
      if (unpaidRecords.length === 0) {
        unpaidBody.innerHTML = '<tr><td colspan="10" class="text-center text-success" style="padding: 2rem;">🎉 Bạn không có khoản nợ học phí nào cần thanh toán!</td></tr>';
      } else {
        unpaidBody.innerHTML = unpaidRecords.map(r => {
          let statusBadge = 'badge-warning';
          if (r.status === 'Chưa thanh toán') statusBadge = 'badge-danger';
          else if (r.status === 'Thanh toán một phần') statusBadge = 'badge-warning';

          const exemptionText = r.exemption_amount > 0 
            ? `<span title="${escapeHTML(r.exemption_reason || '')}">${Calc.formatVND(r.exemption_amount)}${r.exemption_reason ? ` (${escapeHTML(r.exemption_reason)})` : ''}</span>`
            : '-';

          return `
            <tr>
              <td><strong>${escapeHTML(r.semester_label)}</strong></td>
              <td class="text-right">${r.registered_credits}</td>
              <td class="text-right">${Calc.formatVND(r.rate_snapshot)}</td>
              <td class="text-right">${Calc.formatVND(r.gross_tuition)}</td>
              <td class="text-right">${exemptionText}</td>
              <td class="text-right"><strong>${Calc.formatVND(r.net_due)}</strong></td>
              <td class="text-right">${Calc.formatVND(r.total_paid)}</td>
              <td class="text-right" style="color: #ef4444; font-weight: bold;">${Calc.formatVND(r.outstanding)}</td>
              <td class="text-center"><span class="badge ${statusBadge}">${escapeHTML(r.status)}</span></td>
              <td class="text-center">
                <button type="button" class="btn btn-sm btn-success btn-pay-now" data-fee-id="${r.id}" data-balance="${r.outstanding}">
                  💳 Nộp ngay
                </button>
              </td>
            </tr>
          `;
        }).join('');

        unpaidBody.querySelectorAll('.btn-pay-now').forEach(btn => {
          btn.addEventListener('click', () => {
            const feeId = btn.dataset.feeId;
            const balance = btn.dataset.balance;
            switchTab('student-payments');
            setTimeout(() => {
              const select = document.getElementById('student-pay-fee-record');
              const amountInput = document.getElementById('student-pay-amount');
              if (select) {
                select.value = feeId;
                select.dispatchEvent(new Event('change'));
              }
              if (amountInput) {
                amountInput.value = balance;
              }
            }, 50);
          });
        });
      }

      const allRecords = data.all_records || [];
      if (allRecords.length === 0) {
        allBody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">Chưa có hồ sơ học phí nào.</td></tr>';
      } else {
        allBody.innerHTML = allRecords.map(r => {
          let statusBadge = 'badge-secondary';
          if (r.status === 'Đã thanh toán đủ') statusBadge = 'badge-success';
          else if (r.status === 'Thanh toán một phần') statusBadge = 'badge-warning';
          else if (r.status === 'Chưa thanh toán') statusBadge = 'badge-danger';

          const exemptionText = r.exemption_amount > 0 
            ? `<span title="${escapeHTML(r.exemption_reason || '')}">${Calc.formatVND(r.exemption_amount)}${r.exemption_reason ? ` (${escapeHTML(r.exemption_reason)})` : ''}</span>`
            : '-';

          return `
            <tr>
              <td><strong>${escapeHTML(r.semester_label)}</strong></td>
              <td class="text-right">${r.registered_credits}</td>
              <td class="text-right">${Calc.formatVND(r.rate_snapshot)}</td>
              <td class="text-right">${Calc.formatVND(r.gross_tuition)}</td>
              <td class="text-right">${exemptionText}</td>
              <td class="text-right"><strong>${Calc.formatVND(r.net_due)}</strong></td>
              <td class="text-right">${Calc.formatVND(r.total_paid)}</td>
              <td class="text-right">${Calc.formatVND(r.outstanding)}</td>
              <td class="text-center">
                <span class="badge ${statusBadge}">${escapeHTML(r.status)}</span>
                ${r.is_overpaid ? `<span class="badge badge-warning" style="display:block; margin-top:2px;">Nộp thừa ${Calc.formatVND(r.overpaid_amount)}</span>` : ''}
              </td>
            </tr>
          `;
        }).join('');
      }
    } catch (err) {
      showToast(err.message || 'Lỗi tải công nợ.', 'error');
    }
  }

  // 3. Sinh viên: Thanh toán học phí
  async function loadStudentPaymentOptions() {
    const select = document.getElementById('student-pay-fee-record');
    const amountInput = document.getElementById('student-pay-amount');
    const hint = document.getElementById('student-pay-max-hint');
    const successBox = document.getElementById('student-pay-success-box');
    const form = document.getElementById('student-payment-form');
    if (successBox) successBox.style.display = 'none';
    if (form) { form.reset(); Api.clearFieldErrors(form); }

    try {
      const res = await Api.get('/student/debts');
      if (!res || !res.ok) return;
      const outstanding = res.data?.outstanding_records || [];
      if (!select) return;

      select.innerHTML = '<option value="">-- Chọn học kỳ còn nợ --</option>' + 
        outstanding.map(r => `<option value="${r.id}" data-balance="${r.outstanding}">${escapeHTML(r.semester_label)} - Còn nợ: ${Calc.formatVND(r.outstanding)}</option>`).join('');

      select.onchange = () => {
        const opt = select.options[select.selectedIndex];
        const bal = opt ? parseInt(opt.dataset.balance, 10) : 0;
        if (amountInput && bal > 0) {
          amountInput.value = bal;
          amountInput.max = bal;
        }
        if (hint) {
          hint.textContent = bal > 0 ? `Số tiền tối đa có thể nộp: ${Calc.formatVND(bal)}` : 'Số tiền không được vượt quá số dư còn nợ.';
        }
      };
    } catch (e) {
      console.warn('Lỗi tải danh sách hồ sơ cần thanh toán:', e);
    }
  }

  async function handleStudentPaymentSubmit(e) {
    if (e && typeof e.preventDefault === 'function') e.preventDefault();
    const form = document.getElementById('student-payment-form');
    const btn = document.getElementById('btn-student-submit-pay');
    const errBanner = document.getElementById('student-pay-general-error');
    const successBox = document.getElementById('student-pay-success-box');
    if (errBanner) { errBanner.hidden = true; errBanner.textContent = ''; }
    if (successBox) successBox.style.display = 'none';
    Api.clearFieldErrors(form);

    const feeRecordId = form.fee_record_id.value;
    const rawAmount = form.amount.value.trim();
    const method = form.student_pay_method.value;

    const fields = {};
    if (!feeRecordId) {
      fields.fee_record_id = 'Vui lòng chọn khoản học phí cần thanh toán.';
    }
    const amount = parseInt(rawAmount, 10);
    if (!rawAmount || isNaN(amount) || amount <= 0) {
      fields.amount = 'Số tiền phải là số nguyên dương.';
    }

    if (Object.keys(fields).length > 0) {
      Api.renderFieldErrors(form, fields);
      return;
    }

    const requestId = Api.generateUUID();

    try {
      const res = await Api.post(`/student/fee-records/${feeRecordId}/payments`, {
        amount: amount,
        method: method,
        request_id: requestId
      }, btn);

      if (res && res.ok && res.data) {
        showToast('Thanh toán học phí thành công!');
        if (successBox) {
          document.getElementById('sp-gw-ref').textContent = res.data.gateway_ref || 'GW-N/A';
          document.getElementById('sp-receipt-no').textContent = res.data.receipt_no || '';
          document.getElementById('sp-amount').textContent = Calc.formatVND(res.data.amount || amount);
          successBox.style.display = 'block';

          const viewRcptBtn = document.getElementById('btn-student-view-receipt');
          if (viewRcptBtn) {
            viewRcptBtn.onclick = () => openReceiptView(res.data.receipt_no);
          }
        }
        loadStudentPaymentOptions();
      }
    } catch (err) {
      if (err.fields && Object.keys(err.fields).length > 0) {
        Api.renderFieldErrors(form, err.fields);
      } else {
        showToast(err.message || 'Lỗi xử lý thanh toán.', 'error');
      }
      if (errBanner) {
        errBanner.textContent = err.message || 'Lỗi xử lý thanh toán.';
        errBanner.hidden = false;
        errBanner.style.display = 'block';
      }
    }
  }

  // 4. Sinh viên: Lịch sử thanh toán
  async function loadStudentHistory() {
    const tbody = document.getElementById('student-history-table-body');
    const pendingAlert = document.getElementById('student-pending-refund-alert');
    const pendingContent = document.getElementById('student-pending-refund-content');
    const fromInput = document.getElementById('student-hist-from');
    const toInput = document.getElementById('student-hist-to');
    const typeSelect = document.getElementById('student-hist-type');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">Đang tải lịch sử thanh toán...</td></tr>';

    const from = fromInput ? fromInput.value : '';
    const to = toInput ? toInput.value : '';
    const type = typeSelect ? typeSelect.value : '';

    if (from && to && to < from) {
      tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">Đến ngày không được nhỏ hơn từ ngày.</td></tr>';
      if (toInput) Api.setFieldError(null, toInput, 'Đến ngày không được nhỏ hơn từ ngày.', 'to');
      return;
    }

    if (from && to) {
      const dFrom = new Date(from);
      const dTo = new Date(to);
      const diffYears = (dTo - dFrom) / (1000 * 60 * 60 * 24 * 365.25);
      if (diffYears > 5) {
        showToast('Khoảng thời gian tra cứu không được vượt quá 5 năm.', 'error');
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">Khoảng thời gian tra cứu không được vượt quá 5 năm.</td></tr>';
        return;
      }
    }

    let query = [];
    if (from) query.push(`from=${encodeURIComponent(from)}`);
    if (to) query.push(`to=${encodeURIComponent(to)}`);
    if (type) query.push(`type=${encodeURIComponent(type)}`);
    const qs = query.length > 0 ? '?' + query.join('&') : '';

    try {
      const res = await Api.get(`/student/history${qs}`);
      if (!res || !res.ok) throw new Error(res.error?.message || 'Không thể tải lịch sử.');
      const data = res.data || {};
      const entries = data.entries || [];
      const pendingRefunds = data.pending_refunds || [];

      if (pendingAlert && pendingContent) {
        if (pendingRefunds.length > 0) {
          pendingContent.innerHTML = pendingRefunds.map(p => 
            `<div style="font-size: 0.9rem;">• ${escapeHTML(p.status_label)}: <strong>${Calc.formatVND(p.amount)}</strong> (Học kỳ: ${escapeHTML(p.semester_label)}, Tạo lúc: ${Calc.formatDateVN(p.created_at)})</div>`
          ).join('');
          pendingAlert.style.display = 'block';
        } else {
          pendingAlert.style.display = 'none';
        }
      }

      if (entries.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">Không có giao dịch nào phù hợp với điều kiện tìm kiếm.</td></tr>';
        return;
      }

      tbody.innerHTML = entries.map(e => {
        const isPayment = (e.entry_type === 'payment');
        return `
          <tr>
            <td>${Calc.formatDateVN(e.entry_date)}</td>
            <td><strong>${escapeHTML(e.semester_label)}</strong></td>
            <td><code>${escapeHTML(e.receipt_no || e.gateway_ref || '-')}</code></td>
            <td class="text-right font-weight-bold">${Calc.formatVND(e.amount)}</td>
            <td>${escapeHTML(e.method || '-')}</td>
            <td class="text-center"><span class="badge badge-info">${escapeHTML(e.type_label || e.entry_type)}</span></td>
            <td class="text-right">${Calc.formatVND(e.running_balance)}</td>
            <td class="text-center">
              ${isPayment && e.receipt_no ? `
                <button type="button" class="btn btn-sm btn-outline-primary btn-print-receipt" data-receipt-no="${e.receipt_no}">
                  🖨️ In biên lai
                </button>
              ` : '-'}
            </td>
          </tr>
        `;
      }).join('');

      tbody.querySelectorAll('.btn-print-receipt').forEach(btn => {
        btn.addEventListener('click', () => {
          openReceiptView(btn.dataset.receiptNo);
        });
      });
    } catch (err) {
      tbody.innerHTML = `<tr><td colspan="8" class="text-center text-danger">${escapeHTML(err.message || 'Lỗi tải lịch sử.')}</td></tr>`;
    }
  }

  /* ==========================================================================
     12. KHỞI TẠO VÀ GẮN SỰ KIỆN (INITIALIZATION)
     ========================================================================== */

  function initEventListeners() {
    // Auth events
    document.getElementById('login-form')?.addEventListener('submit', handleLoginSubmit);
    document.getElementById('btn-logout')?.addEventListener('click', handleLogout);

    // Tab navigation events
    document.querySelectorAll('.nav-tabs button[role="tab"]').forEach(btn => {
      btn.addEventListener('click', () => {
        const tab = btn.id.replace('tab-', '');
        switchTab(tab);
      });
    });

    // Sub-tabs in payments panel
    document.getElementById('subtab-all-tx')?.addEventListener('click', () => {
      document.getElementById('subtab-all-tx').classList.add('active');
      document.getElementById('subtab-refunds').classList.remove('active');
      document.getElementById('tx-view-container').hidden = false;
      document.getElementById('refunds-view-container').hidden = true;
    });
    document.getElementById('subtab-refunds')?.addEventListener('click', () => {
      document.getElementById('subtab-all-tx').classList.remove('active');
      document.getElementById('subtab-refunds').classList.add('active');
      document.getElementById('tx-view-container').hidden = true;
      document.getElementById('refunds-view-container').hidden = false;
      loadPaymentsData();
    });

    // Report filters
    document.getElementById('report-semester-filter')?.addEventListener('change', loadReportsData);

    // Students events
    document.getElementById('btn-open-student-modal')?.addEventListener('click', () => openStudentModal());
    document.getElementById('btn-close-student-modal')?.addEventListener('click', () => document.getElementById('modal-student').close());
    document.getElementById('btn-cancel-student')?.addEventListener('click', () => document.getElementById('modal-student').close());
    document.getElementById('student-form')?.addEventListener('submit', handleStudentSubmit);
    document.getElementById('student-search-input')?.addEventListener('input', () => {
      clearTimeout(window._stSearchTimer);
      window._stSearchTimer = setTimeout(loadStudentsData, 300);
    });

    // Rates events
    document.getElementById('btn-open-rate-modal')?.addEventListener('click', () => openRateModal());
    document.getElementById('btn-close-rate-modal')?.addEventListener('click', () => document.getElementById('modal-rate').close());
    document.getElementById('btn-cancel-rate')?.addEventListener('click', () => document.getElementById('modal-rate').close());
    document.getElementById('rate-form')?.addEventListener('submit', handleRateSubmit);

    // Semester modal events
    document.getElementById('btn-open-semester-modal')?.addEventListener('click', openSemesterModal);
    document.getElementById('btn-close-semester-modal')?.addEventListener('click', () => document.getElementById('modal-semester')?.close());
    document.getElementById('btn-cancel-semester')?.addEventListener('click', () => document.getElementById('modal-semester')?.close());
    document.getElementById('semester-form')?.addEventListener('submit', handleSemesterSubmit);

    // Fee records events
    document.getElementById('btn-open-fee-modal')?.addEventListener('click', openFeeModal);
    document.getElementById('btn-close-fee-modal')?.addEventListener('click', () => document.getElementById('modal-fee').close());
    document.getElementById('btn-cancel-fee')?.addEventListener('click', () => document.getElementById('modal-fee').close());
    document.getElementById('fee-form')?.addEventListener('submit', handleFeeSubmit);
    document.getElementById('fee-student-select')?.addEventListener('change', checkDuplicateFeeRecord);
    document.getElementById('fee-semester-select')?.addEventListener('change', updateFeeGrossPreview);
    document.getElementById('fee-credits-input')?.addEventListener('input', updateFeeGrossPreview);
    document.getElementById('fee-filter-semester')?.addEventListener('change', loadFeesData);
    document.getElementById('fee-filter-status')?.addEventListener('change', loadFeesData);
    document.getElementById('fee-search-student')?.addEventListener('input', () => {
      clearTimeout(window._feeSearchTimer);
      window._feeSearchTimer = setTimeout(loadFeesData, 300);
    });
    document.getElementById('btn-close-fee-duplicate-alert')?.addEventListener('click', () => {
      const el = document.getElementById('fee-duplicate-top-alert');
      if (el) el.style.display = 'none';
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        const alertEl = document.getElementById('fee-duplicate-top-alert');
        if (alertEl && alertEl.style.display !== 'none') {
          alertEl.style.display = 'none';
          e.preventDefault();
          e.stopPropagation();
        }
      }
    }, true);

    // Overpaid resolution events
    document.getElementById('btn-close-overpaid-modal')?.addEventListener('click', () => document.getElementById('modal-overpaid-resolution').close());
    document.getElementById('btn-cancel-overpaid')?.addEventListener('click', () => document.getElementById('modal-overpaid-resolution').close());
    document.getElementById('radio-res-refund')?.addEventListener('change', updateResolutionUI);
    document.getElementById('radio-res-transfer')?.addEventListener('change', updateResolutionUI);
    document.getElementById('res-target-select')?.addEventListener('change', () => updateTransferPreview(true));
    document.getElementById('res-transfer-amount')?.addEventListener('input', () => updateTransferPreview(false));
    document.getElementById('overpaid-form')?.addEventListener('submit', handleOverpaidSubmit);

    // Exemption events
    document.getElementById('btn-close-exemption-modal')?.addEventListener('click', () => document.getElementById('modal-exemption').close());
    document.getElementById('btn-cancel-exemption')?.addEventListener('click', () => document.getElementById('modal-exemption').close());
    document.getElementById('exemption-form')?.addEventListener('submit', handleExemptionSubmit);
    document.getElementById('btn-remove-exemption')?.addEventListener('click', handleRemoveExemption);
    document.getElementById('radio-exempt-percent')?.addEventListener('change', updateExemptionPreview);
    document.getElementById('radio-exempt-fixed')?.addEventListener('change', updateExemptionPreview);
    document.getElementById('exemption-value-input')?.addEventListener('input', updateExemptionPreview);

    // Payment events
    document.getElementById('btn-open-payment-modal')?.addEventListener('click', () => openPaymentModalWithFee());
    document.getElementById('btn-close-payment-modal')?.addEventListener('click', () => document.getElementById('modal-payment').close());
    document.getElementById('btn-cancel-payment')?.addEventListener('click', () => document.getElementById('modal-payment').close());
    document.getElementById('payment-form')?.addEventListener('submit', handlePaymentSubmit);
    document.getElementById('payment-fee-select')?.addEventListener('change', updatePaymentFeeDetails);
    document.getElementById('btn-pay-full')?.addEventListener('click', () => {
      const fee = AppState.selectedFeeForPayment;
      if (fee && fee.outstanding > 0) {
        document.getElementById('payment-amount-input').value = Calc.formatVND(fee.outstanding).replace('₫', '').trim();
      }
    });

    // Refund events
    document.getElementById('btn-close-refund-modal')?.addEventListener('click', () => document.getElementById('modal-refund').close());
    document.getElementById('btn-cancel-refund')?.addEventListener('click', () => document.getElementById('modal-refund').close());
    document.getElementById('refund-form')?.addEventListener('submit', handleRefundSubmit);

    // Cancel refund modal events
    document.getElementById('btn-close-cancel-refund-modal')?.addEventListener('click', () => document.getElementById('modal-cancel-refund')?.close());
    document.getElementById('btn-cancel-refund-dialog-close')?.addEventListener('click', () => document.getElementById('modal-cancel-refund')?.close());
    document.getElementById('cancel-refund-form')?.addEventListener('submit', handleCancelRefundSubmit);

    // Reversal events
    document.getElementById('btn-close-reversal-modal')?.addEventListener('click', () => document.getElementById('modal-reversal').close());
    document.getElementById('btn-cancel-reversal')?.addEventListener('click', () => document.getElementById('modal-reversal').close());
    document.getElementById('reversal-form')?.addEventListener('submit', handleReversalSubmit);

    // Receipt print events
    document.getElementById('btn-trigger-print')?.addEventListener('click', () => window.print());
    document.getElementById('btn-close-receipt-view')?.addEventListener('click', closeReceiptView);
    document.getElementById('btn-close-receipt-top')?.addEventListener('click', closeReceiptView);

    // Receipt lookup event
    document.getElementById('receipt-lookup-form')?.addEventListener('submit', handleReceiptLookupSubmit);

    // History search events
    document.getElementById('ledger-search-keyword')?.addEventListener('input', () => {
      clearTimeout(window._histSearchTimer);
      window._histSearchTimer = setTimeout(loadHistoryData, 300);
    });
    document.getElementById('ledger-filter-semester')?.addEventListener('change', loadHistoryData);
    document.getElementById('ledger-filter-from-date')?.addEventListener('change', loadHistoryData);
    document.getElementById('ledger-filter-to-date')?.addEventListener('change', loadHistoryData);
    document.getElementById('ledger-filter-status')?.addEventListener('change', loadHistoryData);
    document.getElementById('btn-reset-search')?.addEventListener('click', () => {
      document.getElementById('search-filter-form').reset();
      loadHistoryData();
    });
    document.getElementById('search-filter-form')?.addEventListener('submit', (e) => {
      e.preventDefault();
      loadHistoryData();
    });

    // Approval queue refresh
    document.getElementById('btn-refresh-approval-queue')?.addEventListener('click', loadApprovalQueue);

    // Listen for write success to auto-refresh badges
    window.addEventListener('tuition:write-success', () => {
      updateNavBadges();
    });

    // Refresh badges when tab becomes visible
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible' && AppState.currentUser) {
        updateNavBadges();
      }
    });

    // Live character counters
    bindCharCounter(document.getElementById('exemption-reason-input'), document.getElementById('exemption-char-num'));
    bindCharCounter(document.getElementById('refund-reason-input'), document.getElementById('refund-char-num'));
    bindCharCounter(document.getElementById('cancel-refund-reason'), document.getElementById('cancel-refund-char-num'));
    bindCharCounter(document.getElementById('reversal-reason-input'), document.getElementById('reversal-char-num'));
    bindCharCounter(document.getElementById('res-refund-reason'), document.getElementById('res-refund-char-num'));
    bindCharCounter(document.getElementById('res-transfer-reason'), document.getElementById('res-transfer-char-num'));

    // Gắn xác thực inline tự động (Validate on blur, clear on input)
    // 1. Form Đăng nhập
    bindInlineValidation(document.getElementById('login-form'), document.getElementById('login-username'), (val) => {
      const v = (val || '').trim();
      if (!v) return 'Vui lòng nhập tên đăng nhập.';
      if (Array.from(v).length > 50) return 'Tên đăng nhập không được vượt quá 50 ký tự.';
      return null;
    }, 'username');
    bindInlineValidation(document.getElementById('login-form'), document.getElementById('login-password'), (val) => {
      if (!val) return 'Vui lòng nhập mật khẩu.';
      return null;
    }, 'password');

    // 2. Form Sinh viên
    bindInlineValidation(document.getElementById('student-form'), document.getElementById('student-id-input'), (val) => {
      const r = validateClientStudentCode(val);
      return r.valid ? null : r.error;
    }, 'student_code');
    bindInlineValidation(document.getElementById('student-form'), document.getElementById('student-name-input'), (val) => {
      const r = validateClientFullName(val);
      return r.valid ? null : r.error;
    }, 'full_name');
    bindInlineValidation(document.getElementById('student-form'), document.getElementById('student-class-input'), (val) => {
      const r = validateClientClassName(val);
      return r.valid ? null : r.error;
    }, 'class_name');

    // 3. Form Học kỳ
    bindInlineValidation(document.getElementById('semester-form'), document.getElementById('semester-term-select'), (val) => {
      const r = validateClientSemesterTerm(val);
      return r.valid ? null : r.error;
    }, 'term_no');
    bindInlineValidation(document.getElementById('semester-form'), document.getElementById('semester-year-input'), (val) => {
      const yr = parseInt(val, 10);
      if (!val || isNaN(yr) || yr < 2000 || yr > 2100) return 'Năm học bắt đầu phải từ 2000 đến 2100.';
      return null;
    }, 'academic_year_start');

    // 4. Form Định mức tín chỉ
    bindInlineValidation(document.getElementById('rate-form'), document.getElementById('rate-semester-input'), (val) => {
      if (!val || !val.trim()) return 'Vui lòng nhập tên học kỳ.';
      return null;
    }, 'semester');
    bindInlineValidation(document.getElementById('rate-form'), document.getElementById('rate-price-input'), (val) => {
      const r = parseClientCurrency(val);
      return r.valid ? null : r.error;
    }, 'price_per_credit');

    // 5. Form Hồ sơ học phí
    bindInlineValidation(document.getElementById('fee-form'), document.getElementById('fee-student-select'), (val) => {
      if (!val) return 'Vui lòng chọn sinh viên.';
      return null;
    }, 'student_id');
    bindInlineValidation(document.getElementById('fee-form'), document.getElementById('fee-semester-select'), (val) => {
      if (!val) return 'Vui lòng chọn học kỳ.';
      return null;
    }, 'semester');
    bindInlineValidation(document.getElementById('fee-form'), document.getElementById('fee-credits-input'), (val) => {
      const n = parseInt(val, 10);
      if (!val || isNaN(n) || n < 1 || n > 50) return 'Số tín chỉ phải từ 1 đến 50.';
      return null;
    }, 'registered_credits');

    // 6. Form Miễn giảm
    bindInlineValidation(document.getElementById('exemption-form'), document.getElementById('exemption-value-input'), (val) => {
      const isPct = document.getElementById('radio-exempt-percent')?.checked;
      if (isPct) {
        const r = validateClientPercentExemption(val);
        return r.valid ? null : r.error;
      } else {
        const gross = AppState.selectedFeeForExemption?.gross_tuition || 999999999;
        const r = validateClientFixedExemption(val, gross);
        return r.valid ? null : r.error;
      }
    }, 'value');
    bindInlineValidation(document.getElementById('exemption-form'), document.getElementById('exemption-reason-input'), (val) => {
      const r = validateClientReason(val, { required: true, fieldName: 'Lý do miễn giảm' });
      return r.valid ? null : r.error;
    }, 'reason');

    // 7. Form Thu học phí
    bindInlineValidation(document.getElementById('payment-form'), document.getElementById('payment-fee-select'), (val) => {
      if (!val) return 'Vui lòng chọn hồ sơ học phí.';
      return null;
    }, 'fee_record_id');
    bindInlineValidation(document.getElementById('payment-form'), document.getElementById('payment-amount-input'), (val) => {
      const r = parseClientCurrency(val);
      return r.valid ? null : r.error;
    }, 'amount');
    bindInlineValidation(document.getElementById('payment-form'), document.getElementById('payment-date-input'), (val) => {
      const r = parseClientDate(val, { disallowFuture: true, fieldName: 'Ngày nộp' });
      return r.valid ? null : r.error;
    }, 'payment_date');
    bindInlineValidation(document.getElementById('payment-form'), document.getElementById('payment-method-select'), (val) => {
      if (!val) return 'Vui lòng chọn hình thức thanh toán.';
      return null;
    }, 'method');
    bindInlineValidation(document.getElementById('payment-form'), document.getElementById('payment-note-input'), (val) => {
      if (val && Array.from(val).length > 200) return 'Ghi chú không được vượt quá 200 ký tự.';
      return null;
    }, 'note');

    // 8. Form Hoàn tiền
    bindInlineValidation(document.getElementById('refund-form'), document.getElementById('refund-amount-input'), (val) => {
      const r = parseClientCurrency(val);
      return r.valid ? null : r.error;
    }, 'refund_amount');
    bindInlineValidation(document.getElementById('refund-form'), document.getElementById('refund-reason-input'), (val) => {
      const r = validateClientReason(val, { required: true, fieldName: 'Lý do hoàn tiền' });
      return r.valid ? null : r.error;
    }, 'refund_reason');

    // 9. Form Hủy yêu cầu hoàn tiền
    bindInlineValidation(document.getElementById('cancel-refund-form'), document.getElementById('cancel-refund-reason'), (val) => {
      const r = validateClientReason(val, { required: true, fieldName: 'Lý do hủy yêu cầu' });
      return r.valid ? null : r.error;
    }, 'cancel_reason');

    // 10. Form Đảo mục
    bindInlineValidation(document.getElementById('reversal-form'), document.getElementById('reversal-reason-input'), (val) => {
      const r = validateClientReason(val, { required: true, fieldName: 'Lý do đảo mục' });
      return r.valid ? null : r.error;
    }, 'reversal_reason');

    // 11. Form Xử lý số tiền thừa
    bindInlineValidation(document.getElementById('overpaid-form'), document.getElementById('res-refund-amount'), (val) => {
      const r = parseClientCurrency(val);
      return r.valid ? null : r.error;
    }, 'refund_amount');
    bindInlineValidation(document.getElementById('overpaid-form'), document.getElementById('res-refund-reason'), (val) => {
      const r = validateClientReason(val, { required: true, fieldName: 'Lý do hoàn tiền' });
      return r.valid ? null : r.error;
    }, 'refund_reason');
    bindInlineValidation(document.getElementById('overpaid-form'), document.getElementById('res-target-select'), (val) => {
      if (!val) return 'Vui lòng chọn hồ sơ đích tiếp nhận.';
      return null;
    }, 'target_fee_record_id');
    bindInlineValidation(document.getElementById('overpaid-form'), document.getElementById('res-transfer-amount'), (val) => {
      const r = parseClientCurrency(val);
      return r.valid ? null : r.error;
    }, 'transfer_amount');
    bindInlineValidation(document.getElementById('overpaid-form'), document.getElementById('res-transfer-reason'), (val) => {
      const r = validateClientReason(val, { required: true, fieldName: 'Lý do chuyển tiền' });
      return r.valid ? null : r.error;
    }, 'transfer_reason');

    // 12. Form Tra cứu biên lai
    bindInlineValidation(document.getElementById('receipt-lookup-form'), document.getElementById('receipt-lookup-input'), (val) => {
      const v = (val || '').trim();
      if (!v) return 'Vui lòng nhập số biên lai hoặc mã giao dịch.';
      if (Array.from(v).length > 100) return 'Mã tra cứu không được vượt quá 100 ký tự.';
      return null;
    }, 'receipt_no');

    // 13. Form Bộ lọc Tra cứu lịch sử
    bindInlineValidation(document.getElementById('search-filter-form'), document.getElementById('ledger-search-keyword'), (val) => {
      const r = validateClientSearchQuery(val, 100);
      return r.valid ? null : r.error;
    }, 'q');
    bindInlineValidation(document.getElementById('search-filter-form'), document.getElementById('ledger-filter-from-date'), (val) => {
      if (!val) return null;
      const r = parseClientDate(val, { fieldName: 'Từ ngày' });
      return r.valid ? null : r.error;
    }, 'from');
    bindInlineValidation(document.getElementById('search-filter-form'), document.getElementById('ledger-filter-to-date'), (val) => {
      if (!val) return null;
      const r = parseClientDate(val, { fieldName: 'Đến ngày' });
      if (!r.valid) return r.error;
      const fromVal = document.getElementById('ledger-filter-from-date')?.value;
      if (fromVal) {
        const rng = validateClientDateRange(fromVal, val);
        if (!rng.valid) return rng.error;
      }
      return null;
    }, 'to');

    // 14. Bộ lọc tìm kiếm Sinh viên, Hồ sơ học phí, Sổ cái & Báo cáo
    bindInlineValidation(null, document.getElementById('student-search-input'), (val) => {
      const r = validateClientSearchQuery(val, 100);
      return r.valid ? null : r.error;
    }, 'q');
    bindInlineValidation(null, document.getElementById('fee-search-student'), (val) => {
      const r = validateClientSearchQuery(val, 100);
      return r.valid ? null : r.error;
    }, 'student');
    bindInlineValidation(null, document.getElementById('fee-filter-semester'), () => null, 'semester');
    bindInlineValidation(null, document.getElementById('fee-filter-status'), () => null, 'status');
    bindInlineValidation(document.getElementById('search-filter-form'), document.getElementById('ledger-filter-semester'), () => null, 'semester');
    bindInlineValidation(document.getElementById('search-filter-form'), document.getElementById('ledger-filter-status'), () => null, 'status');
    bindInlineValidation(null, document.getElementById('report-semester-filter'), () => null, 'semester');

    // Sự kiện Cổng sinh viên
    document.getElementById('btn-student-logout')?.addEventListener('click', handleLogout);
    document.getElementById('btn-change-pw-logout')?.addEventListener('click', handleLogout);
    document.getElementById('student-password-form')?.addEventListener('submit', handleStudentPasswordSubmit);
    document.getElementById('student-payment-form')?.addEventListener('submit', handleStudentPaymentSubmit);
    document.getElementById('btn-student-pay-another')?.addEventListener('click', () => {
      const successBox = document.getElementById('student-pay-success-box');
      if (successBox) successBox.style.display = 'none';
      loadStudentPaymentOptions();
    });
    document.getElementById('student-history-filter-form')?.addEventListener('submit', (e) => {
      e.preventDefault();
      loadStudentHistory();
    });
    document.getElementById('btn-reset-student-history')?.addEventListener('click', () => {
      document.getElementById('student-history-filter-form').reset();
      loadStudentHistory();
    });

    // Sự kiện Modal Quản lý Tài khoản Sinh viên (Admin)
    document.getElementById('btn-close-student-account-modal')?.addEventListener('click', () => document.getElementById('modal-student-account')?.close());
    document.getElementById('btn-cancel-student-account')?.addEventListener('click', () => document.getElementById('modal-student-account')?.close());
    document.getElementById('btn-sa-create')?.addEventListener('click', handleStudentAccountCreate);
    document.getElementById('btn-sa-reset')?.addEventListener('click', handleStudentAccountReset);
    document.getElementById('btn-sa-copy-pw')?.addEventListener('click', copyStudentPasswordToClipboard);

    // Chống quay lại trình duyệt (Back Button / Popstate / Pageshow Protection)
    window.addEventListener('popstate', () => {
      checkAuthSession();
    });
    window.addEventListener('pageshow', (event) => {
      if (event.persisted) {
        checkAuthSession();
      }
    });
  }

  // Khởi động ứng dụng
  function bootstrap() {
    if (window.location.search && window.history && window.history.replaceState) {
      const search = window.location.search;
      if (search.includes('username=') || search.includes('password=')) {
        window.history.replaceState(null, '', window.location.pathname);
      }
    }
    initEventListeners();
    checkAuthSession();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap);
  } else {
    bootstrap();
  }

})();
