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

  function showToast(message, isError = false) {
    let toast = document.getElementById('app-toast');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'app-toast';
      toast.style.position = 'fixed';
      toast.style.top = '20px';
      toast.style.right = '20px';
      toast.style.padding = '12px 20px';
      toast.style.borderRadius = '8px';
      toast.style.color = '#fff';
      toast.style.fontWeight = '600';
      toast.style.zIndex = '99999';
      toast.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
      toast.style.transition = 'opacity 0.3s ease';
      document.body.appendChild(toast);
    }
    toast.style.background = isError ? '#dc2626' : '#059669';
    toast.textContent = message;
    toast.style.display = 'block';
    toast.style.opacity = '1';

    setTimeout(() => {
      toast.style.opacity = '0';
      setTimeout(() => { toast.style.display = 'none'; }, 300);
    }, 4000);
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
    const userDisplay = document.getElementById('current-user-display');

    if (loginScreen) loginScreen.hidden = true;
    if (appContainer) appContainer.hidden = false;
    if (userDisplay) {
      userDisplay.innerHTML = `👤 Quản trị viên: <strong>${escapeHTML(user.full_name || user.username)}</strong>`;
    }

    // Nạp dữ liệu khởi tạo
    loadInitialData();
  }

  function onLoggedOut() {
    AppState.currentUser = null;
    Api.setCsrfToken(null);

    const loginScreen = document.getElementById('login-screen');
    const appContainer = document.getElementById('app-container');
    const receiptArea = document.getElementById('receipt-print-area');

    if (loginScreen) loginScreen.hidden = false;
    if (appContainer) appContainer.hidden = true;
    if (receiptArea) receiptArea.hidden = true;

    // Xóa form đăng nhập
    const loginForm = document.getElementById('login-form');
    if (loginForm) {
      loginForm.reset();
      Api.clearFieldErrors(loginForm);
    }
  }

  async function handleLoginSubmit(e) {
    e.preventDefault();
    const form = document.getElementById('login-form');
    const btn = document.getElementById('btn-login');
    const errBanner = document.getElementById('login-general-error');
    Api.clearFieldErrors(form);

    const username = form.username.value.trim();
    const password = form.password.value;

    const fields = {};
    if (!username) fields.username = 'Vui lòng nhập tên đăng nhập.';
    if (!password) fields.password = 'Vui lòng nhập mật khẩu.';

    if (Object.keys(fields).length > 0) {
      Api.renderFieldErrors(form, fields);
      return;
    }

    try {
      const res = await Api.post('/login', { username, password }, btn);
      if (res.ok) {
        onLoginSuccess(res.data);
      }
    } catch (err) {
      if (err.fields && Object.keys(err.fields).length > 0) {
        Api.renderFieldErrors(form, err.fields);
      }
      if (errBanner) {
        errBanner.textContent = err.message || 'Sai tên đăng nhập hoặc mật khẩu.';
        errBanner.hidden = false;
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
     3. ĐIỀU HƯỚNG TAB
     ========================================================================== */

  function switchTab(tabName) {
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
      const isActive = panel.id === `panel-${tabName}`;
      panel.hidden = !isActive;
      panel.classList.toggle('active', isActive);
    });

    // Nạp dữ liệu tương ứng cho tab
    if (tabName === 'reports') loadReportsData();
    else if (tabName === 'students') loadStudentsData();
    else if (tabName === 'rates') loadRatesData();
    else if (tabName === 'fees') loadFeesData();
    else if (tabName === 'payments') loadPaymentsData();
    else if (tabName === 'search') loadHistoryData();
  }

  /* ==========================================================================
     4. TẢI DỮ LIỆU BAN ĐẦU (INITIAL DATA)
     ========================================================================== */

  async function loadInitialData() {
    await Promise.all([
      loadSemestersList(),
      loadRatesData(),
      loadStudentsData()
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
    const semFilter = document.getElementById('report-semester-filter')?.value || 'ALL';
    try {
      const summaryRes = await Api.get(`/reports/summary?semester=${encodeURIComponent(semFilter)}`);
      const feesRes = await Api.get(`/fee-records?semester=${encodeURIComponent(semFilter)}`);

      if (summaryRes.ok) {
        renderReportsSummary(summaryRes.data);
      }
      if (feesRes.ok) {
        renderReportsFeesTable(feesRes.data);
      }
    } catch (err) {
      showToast('Lỗi tải báo cáo thống kê: ' + err.message, true);
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
      let badgeClass = 'badge-danger';
      if (r.status === 'Partially Paid') badgeClass = 'badge-warning';
      else if (r.status === 'Fully Paid') badgeClass = 'badge-success';
      else if (r.is_overpaid) badgeClass = 'badge-info';

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
          <td><span class="badge ${badgeClass}">${escapeHTML(r.status_label)}</span></td>
        </tr>
      `;
    }).join('');
  }

  /* ==========================================================================
     6. PHÂN HỆ 2: QUẢN LÝ SINH VIÊN (STUDENTS)
     ========================================================================== */

  async function loadStudentsData() {
    const q = document.getElementById('student-search-input')?.value.trim() || '';
    try {
      const res = await Api.get(`/students${q ? `?q=${encodeURIComponent(q)}` : ''}`);
      if (res.ok) {
        AppState.students = res.data || [];
        renderStudentsTable(AppState.students);
        populateStudentDropdowns();
      }
    } catch (err) {
      showToast('Lỗi tải danh sách sinh viên: ' + err.message, true);
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
    const body = {
      student_code: form.studentId.value.trim(),
      full_name: form.fullName.value.trim(),
      class_name: form.className.value.trim() || null
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
      else showToast(err.message, true);
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
    const price = form.pricePerCredit.value.trim();

    try {
      if (semId) {
        await Api.put(`/rates/${semId}`, { price_per_credit: price }, btn);
        showToast('Cập nhật đơn giá tín chỉ thành công.');
      } else {
        // Nếu tạo mới, tìm hoặc tạo semester trước
        const semName = form.semester.value.trim();
        // Giả lập match semester từ tên hoặc học kỳ đầu tiên chưa có rate
        const existingSem = AppState.semesters.find(s => s.label.toLowerCase() === semName.toLowerCase());
        const targetSemId = existingSem ? existingSem.id : (AppState.semesters[0]?.id || 1);
        await Api.post('/rates', { semester_id: targetSemId, price_per_credit: price }, btn);
        showToast('Thiết lập định mức thành công.');
      }
      document.getElementById('modal-rate').close();
      loadRatesData();
    } catch (err) {
      if (err.fields) Api.renderFieldErrors(form, err.fields);
      else showToast(err.message, true);
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
    const sem = document.getElementById('fee-filter-semester')?.value || 'ALL';
    const status = document.getElementById('fee-filter-status')?.value || 'ALL';
    const search = document.getElementById('fee-search-student')?.value.trim() || '';

    try {
      let url = `/fee-records?semester=${encodeURIComponent(sem)}&status=${encodeURIComponent(status)}`;
      if (search) url += `&student=${encodeURIComponent(search)}`;

      const res = await Api.get(url);
      if (res.ok) {
        AppState.feeRecords = res.data || [];
        renderFeesTable(AppState.feeRecords);
        populatePaymentFeeSelect();
      }
    } catch (err) {
      showToast('Lỗi tải hồ sơ học phí: ' + err.message, true);
    }
  }

  function renderFeesTable(records) {
    const tbody = document.getElementById('fees-table-body');
    if (!tbody) return;

    if (records.length === 0) {
      tbody.innerHTML = '<tr><td colspan="12" class="text-center text-muted">Không tìm thấy hồ sơ học phí nào phù hợp.</td></tr>';
      return;
    }

    tbody.innerHTML = records.map(r => {
      let badgeClass = 'badge-danger';
      if (r.status === 'Partially Paid') badgeClass = 'badge-warning';
      else if (r.status === 'Fully Paid') badgeClass = 'badge-success';
      else if (r.is_overpaid) badgeClass = 'badge-info';

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
            <span class="badge ${badgeClass}">${escapeHTML(r.status_label)}</span>
            ${r.is_overpaid ? `<br><small class="text-danger font-weight-bold">Thừa: ${Calc.formatVND(r.overpaid_amount)}</small>` : ''}
          </td>
          <td class="text-center table-actions-cell">
            <button type="button" class="btn btn-sm btn-outline-info btn-exempt-fee" data-id="${r.fee_record_id}">🎁 Miễn giảm</button>
            <button type="button" class="btn btn-sm btn-outline-success btn-pay-fee" data-id="${r.fee_record_id}">💳 Thu tiền</button>
            ${r.is_overpaid ? `<button type="button" class="btn btn-sm btn-outline-warning btn-refund-fee" data-id="${r.fee_record_id}">↩️ Hoàn tiền</button>` : ''}
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
  }

  function openFeeModal() {
    const modal = document.getElementById('modal-fee');
    const form = document.getElementById('fee-form');
    Api.clearFieldErrors(form);
    form.reset();
    delete form.dataset.editId;
    setElementText('fee-gross-preview', '0 ₫');
    document.getElementById('fee-rate-display').value = '';

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
  }

  async function handleFeeSubmit(e) {
    e.preventDefault();
    const form = document.getElementById('fee-form');
    const btn = document.getElementById('btn-save-fee');
    Api.clearFieldErrors(form);

    const body = {
      student_id: form.studentId.value,
      semester_id: form.semester.value,
      registered_credits: form.registeredCredits.value
    };

    try {
      await Api.post('/fee-records', body, btn);
      showToast('Lập hồ sơ học phí thành công.');
      document.getElementById('modal-fee').close();
      loadFeesData();
    } catch (err) {
      if (err.fields) Api.renderFieldErrors(form, err.fields);
      else showToast(err.message, true);
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

    const body = {
      type: isPercent ? 'percent' : 'fixed',
      value: form.exemptionValue.value.trim(),
      reason: form.reason.value.trim()
    };

    try {
      await Api.put(`/fee-records/${feeId}/exemption`, body, btn);
      showToast('Áp dụng miễn giảm học phí thành công.');
      document.getElementById('modal-exemption').close();
      loadFeesData();
    } catch (err) {
      if (err.fields) Api.renderFieldErrors(form, err.fields);
      else showToast(err.message, true);
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
          loadFeesData();
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
    const body = {
      amount: form.amount.value.trim(),
      method: form.method.value,
      entry_date: form.paymentDate.value,
      note: form.note.value.trim() || null,
      request_id: Api.generateUUID()
    };

    try {
      const res = await Api.post(`/fee-records/${feeId}/payments`, body, btn);
      showToast('Ghi nhận phiếu thu học phí thành công.');
      document.getElementById('modal-payment').close();
      loadFeesData();
      loadPaymentsData();

      // Mở luôn biên lai in
      if (res && res.data && res.data.id) {
        openReceiptView(res.data.id);
      }
    } catch (err) {
      if (err.fields) Api.renderFieldErrors(form, err.fields);
      else showToast(err.message, true);
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
    const body = {
      amount: form.refundAmount.value.trim(),
      reason: form.refundReason.value.trim(),
      request_id: Api.generateUUID()
    };

    try {
      await Api.post(`/fee-records/${feeId}/refunds`, body, btn);
      showToast('Tạo yêu cầu hoàn tiền thành công.');
      document.getElementById('modal-refund').close();
      loadFeesData();
      loadPaymentsData();
    } catch (err) {
      if (err.fields) Api.renderFieldErrors(form, err.fields);
      else showToast(err.message, true);
    }
  }

  async function completeRefund(refundId) {
    showConfirm(
      'Duyệt hoàn tiền',
      'Bạn có chắc chắn muốn xác nhận hoàn tiền cho sinh viên?',
      async () => {
        try {
          await Api.post(`/refunds/${refundId}/complete`);
          showToast('Đã duyệt hoàn tất yêu cầu hoàn tiền.');
          loadFeesData();
          loadPaymentsData();
        } catch (err) {
          showToast(err.message, true);
        }
      }
    );
  }

  async function cancelRefund(refundId) {
    showConfirm(
      'Hủy yêu cầu hoàn tiền',
      'Bạn có chắc chắn muốn hủy bỏ yêu cầu hoàn tiền này?',
      async () => {
        try {
          await Api.post(`/refunds/${refundId}/cancel`, { cancel_reason: 'Quản trị viên hủy bỏ' });
          showToast('Đã hủy yêu cầu hoàn tiền.');
          loadFeesData();
          loadPaymentsData();
        } catch (err) {
          showToast(err.message, true);
        }
      }
    );
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

    modal.showModal();
  }

  async function handleReversalSubmit(e) {
    e.preventDefault();
    const form = document.getElementById('reversal-form');
    const btn = document.getElementById('btn-confirm-reversal');
    Api.clearFieldErrors(form);

    const entryId = form.dataset.entryId;
    const body = {
      reason: form.reversalReason.value.trim(),
      request_id: Api.generateUUID()
    };

    try {
      await Api.post(`/ledger/${entryId}/reversal`, body, btn);
      showToast('Đảo mục giao dịch thành công. Số dư nợ đã được khôi phục.');
      document.getElementById('modal-reversal').close();
      loadFeesData();
      loadPaymentsData();
    } catch (err) {
      if (err.fields) Api.renderFieldErrors(form, err.fields);
      else showToast(err.message, true);
    }
  }

  /* Tải bảng giao dịch & Yêu cầu hoàn tiền */

  async function loadPaymentsData() {
    try {
      const txRes = await Api.get('/history');
      if (txRes.ok) {
        renderTransactionsTable(txRes.data || []);
      }
    } catch (err) {
      showToast('Lỗi tải lịch sử giao dịch: ' + err.message, true);
    }
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
      const typeLabel = isPayment ? 'Thu học phí' : (isRev ? 'Đảo mục (Hủy)' : tx.type);
      const typeBadge = isPayment ? 'badge-success' : 'badge-danger';

      return `
        <tr>
          <td><strong>${escapeHTML(tx.receipt_no || '-')}</strong></td>
          <td>${Calc.formatDateVN(tx.entry_date)}</td>
          <td>${escapeHTML(tx.student_code)}</td>
          <td>${escapeHTML(tx.student_name)}</td>
          <td>${escapeHTML(tx.semester_label)}</td>
          <td><span class="badge ${typeBadge}">${typeLabel}</span></td>
          <td class="text-right ${isPayment ? 'text-success' : 'text-danger'}"><strong>${isRev ? '-' : ''}${Calc.formatVND(tx.amount)}</strong></td>
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

  /* ==========================================================================
     11. PHÂN HỆ 6: TRA CỨU LỊCH SỬ (HISTORY SEARCH)
     ========================================================================== */

  async function loadHistoryData() {
    const keyword = document.getElementById('ledger-search-keyword')?.value.trim() || '';
    const semester = document.getElementById('ledger-filter-semester')?.value || 'ALL';
    const from = document.getElementById('ledger-filter-from-date')?.value || '';
    const to = document.getElementById('ledger-filter-to-date')?.value || '';
    const status = document.getElementById('ledger-filter-status')?.value || 'ALL';

    let url = `/history?semester=${encodeURIComponent(semester)}`;
    if (keyword) url += `&q=${encodeURIComponent(keyword)}&name=${encodeURIComponent(keyword)}&student=${encodeURIComponent(keyword)}`;
    if (from) url += `&from=${encodeURIComponent(from)}`;
    if (to) url += `&to=${encodeURIComponent(to)}`;
    if (status !== 'ALL') url += `&status=${encodeURIComponent(status)}`;

    try {
      const res = await Api.get(url);
      if (res.ok) {
        renderHistoryTable(res.data || []);
      }
    } catch (err) {
      showToast('Lỗi tra cứu lịch sử: ' + err.message, true);
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
      const typeLabel = isPayment ? 'Thu học phí' : (isRev ? 'Đảo mục (Hủy)' : tx.type);
      const typeBadge = isPayment ? 'badge-success' : 'badge-danger';
      const balanceDisplay = tx.remaining_balance !== undefined ? Calc.formatVND(tx.remaining_balance) : '-';

      return `
        <tr>
          <td>${Calc.formatDateVN(tx.entry_date)}</td>
          <td><strong>${escapeHTML(tx.receipt_no || '-')}</strong></td>
          <td>${escapeHTML(tx.student_code)}</td>
          <td>${escapeHTML(tx.student_name)}</td>
          <td>${escapeHTML(tx.semester_label)}</td>
          <td><span class="badge ${typeBadge}">${typeLabel}</span></td>
          <td class="text-right ${isPayment ? 'text-success' : 'text-danger'}"><strong>${isRev ? '-' : ''}${Calc.formatVND(tx.amount)}</strong></td>
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

    // Fee records events
    document.getElementById('btn-open-fee-modal')?.addEventListener('click', openFeeModal);
    document.getElementById('btn-close-fee-modal')?.addEventListener('click', () => document.getElementById('modal-fee').close());
    document.getElementById('btn-cancel-fee')?.addEventListener('click', () => document.getElementById('modal-fee').close());
    document.getElementById('fee-form')?.addEventListener('submit', handleFeeSubmit);
    document.getElementById('fee-semester-select')?.addEventListener('change', updateFeeGrossPreview);
    document.getElementById('fee-credits-input')?.addEventListener('input', updateFeeGrossPreview);
    document.getElementById('fee-filter-semester')?.addEventListener('change', loadFeesData);
    document.getElementById('fee-filter-status')?.addEventListener('change', loadFeesData);
    document.getElementById('fee-search-student')?.addEventListener('input', () => {
      clearTimeout(window._feeSearchTimer);
      window._feeSearchTimer = setTimeout(loadFeesData, 300);
    });

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

    // Reversal events
    document.getElementById('btn-close-reversal-modal')?.addEventListener('click', () => document.getElementById('modal-reversal').close());
    document.getElementById('btn-cancel-reversal')?.addEventListener('click', () => document.getElementById('modal-reversal').close());
    document.getElementById('reversal-form')?.addEventListener('submit', handleReversalSubmit);

    // Receipt print events
    document.getElementById('btn-trigger-print')?.addEventListener('click', () => window.print());
    document.getElementById('btn-close-receipt-view')?.addEventListener('click', closeReceiptView);
    document.getElementById('btn-close-receipt-top')?.addEventListener('click', closeReceiptView);

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
  }

  // Khởi động ứng dụng
  document.addEventListener('DOMContentLoaded', () => {
    initEventListeners();
    checkAuthSession();
  });

})();
