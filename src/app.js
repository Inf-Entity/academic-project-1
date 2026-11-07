/**
 * ============================================================================
 * HỆ THỐNG QUẢN LÝ HỌC PHÍ SINH VIÊN - VANILLA JAVASCRIPT (ES6+)
 * Không dùng Framework, Không Build Tool, Lưu trữ LocalStorage, Bảo mật Session
 * ============================================================================
 */

// Đóng gói ứng dụng trong IIFE để tránh xung đột biến toàn cục
(function () {
  'use strict';

  /* ==========================================================================
     1. HẰNG SỐ & CẤU HÌNH HỆ THỐNG
     ========================================================================== */
  const DEMO_ADMIN = {
    username: 'admin',
    password: 'admin123',
    displayName: 'Quản trị viên Admin'
  };

  const STORAGE_KEYS = {
    STUDENTS: 'TUITION_STUDENTS_V1',
    RATES: 'TUITION_RATES_V1',
    FEES: 'TUITION_FEES_V1',
    TRANSACTIONS: 'TUITION_TXS_V1',
    INITIALIZED: 'TUITION_INITIALIZED_V1'
  };

  const SESSION_KEY = 'TUITION_ADMIN_SESSION';

  const PAYMENT_STATUS = {
    UNPAID: 'Chưa thanh toán',
    PARTIAL: 'Thanh toán một phần',
    PAID: 'Đã thanh toán đủ',
    OVERPAID: 'OVERPAID' // Thừa tiền
  };

  const TX_TYPES = {
    PAYMENT: 'PAYMENT',
    REFUND: 'REFUND',
    REVERSAL: 'REVERSAL'
  };

  const TX_TYPE_LABELS = {
    PAYMENT: 'Thu học phí',
    REFUND: 'Hoàn tiền',
    REVERSAL: 'Đảo mục (Hủy)'
  };

  const REFUND_STATUS = {
    PENDING: 'Chờ duyệt',
    COMPLETED: 'Đã hoàn tiền'
  };

  /* ==========================================================================
     2. PURE CALCULATION & FORMATTING FUNCTIONS (TÁCH BIỆT, CÓ THỂ UNIT TEST)
     ========================================================================== */

  /**
   * Tính học phí gốc: Số tín chỉ × Đơn giá snapshot
   */
  function calculateGross(credits, pricePerCredit) {
    const c = Math.max(0, parseInt(credits, 10) || 0);
    const p = Math.max(0, parseInt(pricePerCredit, 10) || 0);
    return Math.round(c * p);
  }

  /**
   * Tính số tiền miễn giảm (phần trăm hoặc cố định, làm tròn VND, không vượt quá học phí gốc)
   */
  function calculateExemption(gross, type, value) {
    const g = Math.max(0, Math.round(gross || 0));
    if (!type || !value) return 0;

    if (type === 'percentage') {
      const pct = Math.min(100, Math.max(1, parseFloat(value) || 0));
      return Math.min(g, Math.round((g * pct) / 100));
    }

    if (type === 'fixed') {
      const fixed = Math.max(0, Math.round(parseFloat(value) || 0));
      return Math.min(g, fixed);
    }

    return 0;
  }

  /**
   * Tính số tiền thực phải nộp (Net Due): max(0, gross - exemption)
   */
  function calculateNetDue(gross, exemptionAmount) {
    const g = Math.max(0, Math.round(gross || 0));
    const e = Math.max(0, Math.round(exemptionAmount || 0));
    return Math.max(0, g - e);
  }

  /**
   * Tính toán tổng nộp, công nợ còn lại, tiền thừa của một hồ sơ học phí dựa trên danh sách giao dịch
   */
  function calculateFeeBalances(feeRecord, allTransactions) {
    if (!feeRecord) {
      return { totalPaid: 0, outstandingBalance: 0, overpaidAmount: 0, pendingRefundAmount: 0, netDue: 0, grossTuition: 0 };
    }

    const txs = (allTransactions || []).filter(tx => tx.feeRecordId === feeRecord.id);

    // Tính tổng tiền thu hợp lệ (PAYMENT trừ đi các REVERSAL tương ứng)
    let paymentSum = 0;
    let paymentReversals = 0;
    let completedRefundSum = 0;
    let refundReversals = 0;
    let pendingRefundSum = 0;

    txs.forEach(tx => {
      const amt = Math.max(0, Math.round(tx.amount || 0));

      if (tx.type === TX_TYPES.PAYMENT) {
        paymentSum += amt;
      } else if (tx.type === TX_TYPES.REFUND) {
        if (tx.status === REFUND_STATUS.COMPLETED) {
          completedRefundSum += amt;
        } else if (tx.status === REFUND_STATUS.PENDING) {
          pendingRefundSum += amt;
        }
      } else if (tx.type === TX_TYPES.REVERSAL) {
        if (tx.targetType === TX_TYPES.PAYMENT) {
          paymentReversals += amt;
        } else if (tx.targetType === TX_TYPES.REFUND) {
          refundReversals += amt;
        }
      }
    });

    // Thực nộp = (Tổng thu - Đảo mục thu) - (Tổng hoàn tiền đã hoàn - Đảo mục hoàn)
    const effectivePaid = Math.max(0, (paymentSum - paymentReversals) - (completedRefundSum - refundReversals));
    const netDue = Math.max(0, Math.round(feeRecord.netDue || 0));

    const outstandingBalance = Math.max(0, netDue - effectivePaid);
    const overpaidAmount = Math.max(0, effectivePaid - netDue);

    return {
      totalPaid: effectivePaid,
      outstandingBalance,
      overpaidAmount,
      pendingRefundAmount: pendingRefundSum,
      netDue,
      grossTuition: Math.max(0, Math.round(feeRecord.grossTuition || 0))
    };
  }

  /**
   * Xác định trạng thái thanh toán từ tổng đã nộp và số phải nộp
   */
  function derivePaymentStatus(totalPaid, netDue) {
    const p = Math.round(totalPaid || 0);
    const n = Math.round(netDue || 0);

    if (p > n) {
      return PAYMENT_STATUS.OVERPAID;
    }
    if (p === 0 && n > 0) {
      return PAYMENT_STATUS.UNPAID;
    }
    if (p > 0 && p < n) {
      return PAYMENT_STATUS.PARTIAL;
    }
    if (p === n) {
      return PAYMENT_STATUS.PAID;
    }
    return PAYMENT_STATUS.UNPAID;
  }

  /**
   * Chuẩn hóa chuỗi tiếng Việt để tìm kiếm không dấu, không phân biệt hoa thường
   */
  function normalizeVietnamese(str) {
    if (!str) return '';
    return str
      .toString()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .replace(/[đĐ]/g, 'd')
      .toLowerCase()
      .trim();
  }

  /**
   * Xử lý và chuẩn hóa chuỗi tiền tệ người dùng nhập/dán vào (chấp nhận chấm, phẩy, khoảng trắng)
   */
  function parseFormattedCurrency(input) {
    if (input === null || input === undefined) return NaN;
    if (typeof input === 'number') {
      if (isNaN(input) || input <= 0 || input > 999999999999) return NaN;
      return Math.round(input);
    }
    const cleanStr = input.toString().replace(/[\s.,₫đVND]/gi, '');
    if (!/^\d+$/.test(cleanStr)) return NaN;
    const num = parseInt(cleanStr, 10);
    if (isNaN(num) || num <= 0 || num > 999999999999) return NaN;
    return num;
  }

  /**
   * Định dạng tiền tệ VND: không có số thập phân
   */
  const vndFormatter = new Intl.NumberFormat('vi-VN', {
    style: 'currency',
    currency: 'VND',
    maximumFractionDigits: 0
  });

  function formatVND(amount) {
    return vndFormatter.format(Math.round(amount || 0));
  }

  /**
   * Định dạng ngày dd/mm/yyyy từ chuỗi ISO (YYYY-MM-DD hoặc ISO DateTime)
   */
  function formatDateVN(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;
    const day = String(d.getDate()).padStart(2, '0');
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const year = d.getFullYear();
    return `${day}/${month}/${year}`;
  }

  /**
   * Lấy ngày hiện tại theo chuẩn YYYY-MM-DD (giờ địa phương)
   */
  function getTodayISO() {
    const today = new Date();
    const y = today.getFullYear();
    const m = String(today.getMonth() + 1).padStart(2, '0');
    const d = String(today.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  }

  /**
   * Chuyển đổi số nguyên tiền VND thành chữ tiếng Việt chuẩn cho biên lai in
   */
  function numberToVietnameseWords(amount) {
    const num = Math.round(amount || 0);
    if (num === 0) return 'Không đồng chẵn';
    if (num < 0) return 'Âm ' + numberToVietnameseWords(Math.abs(num));

    const digits = ['không', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];
    const units = ['', 'nghìn', 'triệu', 'tỷ', 'nghìn tỷ', 'triệu tỷ'];

    function readTriple(n, showZeroHundreds) {
      const h = Math.floor(n / 100);
      const t = Math.floor((n % 100) / 10);
      const o = n % 10;
      let res = '';

      if (h > 0 || showZeroHundreds) {
        res += digits[h] + ' trăm ';
      }

      if (t > 1) {
        res += digits[t] + ' mươi ';
        if (o === 1) res += 'mốt ';
        else if (o === 5) res += 'lăm ';
        else if (o > 0) res += digits[o] + ' ';
      } else if (t === 1) {
        res += 'mười ';
        if (o === 5) res += 'lăm ';
        else if (o > 0) res += digits[o] + ' ';
      } else if (t === 0 && o > 0) {
        if (h > 0 || showZeroHundreds) res += 'lẻ ';
        res += digits[o] + ' ';
      }

      return res.trim();
    }

    let nStr = num.toString();
    const triples = [];
    while (nStr.length > 0) {
      triples.unshift(parseInt(nStr.slice(-3), 10));
      nStr = nStr.slice(0, -3);
    }

    let words = '';
    for (let i = 0; i < triples.length; i++) {
      const val = triples[i];
      if (val > 0) {
        const u = units[triples.length - 1 - i];
        const tripleWords = readTriple(val, i > 0);
        words += (tripleWords + ' ' + u).trim() + ' ';
      }
    }

    words = words.trim();
    // Viết hoa chữ cái đầu và thêm 'đồng chẵn'
    return words.charAt(0).toUpperCase() + words.slice(1) + ' đồng chẵn';
  }

  /* ==========================================================================
     3. TẦNG LƯU TRỮ (STORAGE LAYER) & KHỞI TẠO DỮ LIỆU MẪU (SEED DATA)
     ========================================================================== */

  function safeStorageGet(key, fallback) {
    try {
      const item = localStorage.getItem(key);
      if (item === null) return fallback;
      return JSON.parse(item);
    } catch (err) {
      console.error(`Lỗi đọc localStorage (${key}):`, err);
      showStorageAlert('Cảnh báo: Dữ liệu đã lưu bị lỗi hoặc không thể đọc. Hệ thống đang sử dụng dữ liệu mặc định.');
      return fallback;
    }
  }

  function safeStorageSet(key, value) {
    try {
      localStorage.setItem(key, JSON.stringify(value));
      return true;
    } catch (err) {
      console.error(`Lỗi ghi localStorage (${key}):`, err);
      showStorageAlert('Lỗi: Dung lượng lưu trữ trình duyệt (localStorage) đã đầy hoặc bị từ chối.');
      return false;
    }
  }

  function showStorageAlert(msg) {
    const alertEl = document.getElementById('storage-alert');
    if (alertEl) {
      alertEl.textContent = msg;
      alertEl.hidden = false;
    }
  }

  /**
   * Khởi tạo dữ liệu mẫu nếu đây là lần đầu chạy ứng dụng
   */
  function seedInitialDataIfFirstRun() {
    if (localStorage.getItem(STORAGE_KEYS.INITIALIZED)) {
      return;
    }

    // 1. Ít nhất 5 sinh viên
    const initialStudents = [
      { id: 'SV2026001', fullName: 'Nguyễn Văn An', className: 'CNTT-K24A', createdAt: '2026-10-15' },
      { id: 'SV2026002', fullName: 'Trần Thị Bích', className: 'QTKD-K24B', createdAt: '2026-10-15' },
      { id: 'SV2026003', fullName: 'Lê Hoàng Long', className: 'DTVT-K24A', createdAt: '2026-10-16' },
      { id: 'SV2026004', fullName: 'Phạm Thu Hà', className: 'KT-K24C', createdAt: '2026-10-16' },
      { id: 'SV2026005', fullName: 'Đặng Minh Tuấn', className: 'KHMT-K24A', createdAt: '2026-10-17' }
    ];

    // 2. Ít nhất 2 học kỳ, 3 định mức tín chỉ
    const initialRates = [
      { id: 'CR-001', semester: 'Học kỳ 1 - Năm học 2026-2027', pricePerCredit: 450000, updatedAt: '2026-10-20' },
      { id: 'CR-002', semester: 'Học kỳ 2 - Năm học 2026-2027', pricePerCredit: 470000, updatedAt: '2026-10-20' },
      { id: 'CR-003', semester: 'Học kỳ 1 - Năm học 2027-2028', pricePerCredit: 500000, updatedAt: '2026-10-20' }
    ];

    // 3. Hồ sơ học phí và 1 miễn giảm
    // SV 1: 18 TC x 450k = 8,100,000; Giảm 20% = 1,620,000; Phải nộp = 6,480,000
    // SV 2: 15 TC x 450k = 6,750,000; Không giảm; Phải nộp = 6,750,000
    // SV 3: 16 TC x 450k = 7,200,000; Không giảm; Phải nộp = 7,200,000
    // SV 4: 14 TC x 450k = 6,300,000; Không giảm; Phải nộp = 6,300,000
    // SV 5: 17 TC x 470k = 7,990,000; Không giảm; Phải nộp = 7,990,000
    const initialFees = [
      {
        id: 'FR-001',
        studentId: 'SV2026001',
        semester: 'Học kỳ 1 - Năm học 2026-2027',
        registeredCredits: 18,
        rateSnapshot: 450000,
        grossTuition: 8100000,
        exemption: {
          type: 'percentage',
          value: 20,
          amount: 1620000,
          reason: 'Học bổng khuyến khích loại Giỏi',
          appliedAt: '2026-10-25'
        },
        netDue: 6480000,
        createdAt: '2026-10-25'
      },
      {
        id: 'FR-002',
        studentId: 'SV2026002',
        semester: 'Học kỳ 1 - Năm học 2026-2027',
        registeredCredits: 15,
        rateSnapshot: 450000,
        grossTuition: 6750000,
        exemption: null,
        netDue: 6750000,
        createdAt: '2026-10-25'
      },
      {
        id: 'FR-003',
        studentId: 'SV2026003',
        semester: 'Học kỳ 1 - Năm học 2026-2027',
        registeredCredits: 16,
        rateSnapshot: 450000,
        grossTuition: 7200000,
        exemption: null,
        netDue: 7200000,
        createdAt: '2026-10-25'
      },
      {
        id: 'FR-004',
        studentId: 'SV2026004',
        semester: 'Học kỳ 1 - Năm học 2026-2027',
        registeredCredits: 14,
        rateSnapshot: 450000,
        grossTuition: 6300000,
        exemption: null,
        netDue: 6300000,
        createdAt: '2026-10-25'
      },
      {
        id: 'FR-005',
        studentId: 'SV2026005',
        semester: 'Học kỳ 2 - Năm học 2026-2027',
        registeredCredits: 17,
        rateSnapshot: 470000,
        grossTuition: 7990000,
        exemption: null,
        netDue: 7990000,
        createdAt: '2026-10-26'
      }
    ];

    // 4. Các phiếu thanh toán bao phủ cả 3 trạng thái:
    // - SV 1: Đã nộp 3,000,000 / 6,480,000 -> "Thanh toán một phần"
    // - SV 2: Đã nộp 4,000,000 + 2,750,000 = 6,750,000 -> "Đã thanh toán đủ"
    // - SV 3: Chưa nộp gì -> "Chưa thanh toán"
    // - SV 4: Đã nộp đủ 6,300,000 -> "Đã thanh toán đủ"
    // - SV 5: Chưa nộp gì -> "Chưa thanh toán"
    const initialTransactions = [
      {
        id: 'TX-20261026-0001',
        receiptNumber: 'PT-20261026-0001',
        feeRecordId: 'FR-001',
        studentId: 'SV2026001',
        semester: 'Học kỳ 1 - Năm học 2026-2027',
        type: TX_TYPES.PAYMENT,
        amount: 3000000,
        paymentDate: '2026-10-26',
        method: 'Chuyển khoản',
        note: 'Nộp học phí đợt 1',
        status: 'COMPLETED',
        adminName: 'Admin',
        createdAt: '2026-10-26T09:15:00Z'
      },
      {
        id: 'TX-20261027-0001',
        receiptNumber: 'PT-20261027-0001',
        feeRecordId: 'FR-002',
        studentId: 'SV2026002',
        semester: 'Học kỳ 1 - Năm học 2026-2027',
        type: TX_TYPES.PAYMENT,
        amount: 4000000,
        paymentDate: '2026-10-27',
        method: 'Tiền mặt',
        note: 'Nộp tạm ứng học phí',
        status: 'COMPLETED',
        adminName: 'Admin',
        createdAt: '2026-10-27T10:30:00Z'
      },
      {
        id: 'TX-20261028-0001',
        receiptNumber: 'PT-20261028-0001',
        feeRecordId: 'FR-002',
        studentId: 'SV2026002',
        semester: 'Học kỳ 1 - Năm học 2026-2027',
        type: TX_TYPES.PAYMENT,
        amount: 2750000,
        paymentDate: '2026-10-28',
        method: 'Thẻ',
        note: 'Nộp hết số dư học phí còn lại',
        status: 'COMPLETED',
        adminName: 'Admin',
        createdAt: '2026-10-28T14:20:00Z'
      },
      {
        id: 'TX-20261029-0001',
        receiptNumber: 'PT-20261029-0001',
        feeRecordId: 'FR-004',
        studentId: 'SV2026004',
        semester: 'Học kỳ 1 - Năm học 2026-2027',
        type: TX_TYPES.PAYMENT,
        amount: 6300000,
        paymentDate: '2026-10-29',
        method: 'Chuyển khoản',
        note: 'Thanh toán trọn gói học phí HK1',
        status: 'COMPLETED',
        adminName: 'Admin',
        createdAt: '2026-10-29T11:00:00Z'
      }
    ];

    safeStorageSet(STORAGE_KEYS.STUDENTS, initialStudents);
    safeStorageSet(STORAGE_KEYS.RATES, initialRates);
    safeStorageSet(STORAGE_KEYS.FEES, initialFees);
    safeStorageSet(STORAGE_KEYS.TRANSACTIONS, initialTransactions);
    safeStorageSet(STORAGE_KEYS.INITIALIZED, true);
  }

  /* ==========================================================================
     4. DATA REPOSITORIES (ĐỌC / GHI DỮ LIỆU ĐƯỢC BẢO VỆ)
     ========================================================================== */
  function getStudents() {
    return safeStorageGet(STORAGE_KEYS.STUDENTS, []);
  }
  function saveStudents(data) {
    return safeStorageSet(STORAGE_KEYS.STUDENTS, data);
  }

  function getRates() {
    return safeStorageGet(STORAGE_KEYS.RATES, []);
  }
  function saveRates(data) {
    return safeStorageSet(STORAGE_KEYS.RATES, data);
  }

  function getFees() {
    return safeStorageGet(STORAGE_KEYS.FEES, []);
  }
  function saveFees(data) {
    return safeStorageSet(STORAGE_KEYS.FEES, data);
  }

  function getTransactions() {
    return safeStorageGet(STORAGE_KEYS.TRANSACTIONS, []);
  }
  function saveTransactions(data) {
    return safeStorageSet(STORAGE_KEYS.TRANSACTIONS, data);
  }

  /**
   * Tạo số biên lai tuần tự theo ngày: PT-YYYYMMDD-0001
   */
  function generateNextReceiptNumber(dateStr) {
    const todayStr = dateStr ? dateStr.replace(/-/g, '') : getTodayISO().replace(/-/g, '');
    const prefix = `PT-${todayStr}-`;
    const txs = getTransactions();

    let maxSeq = 0;
    txs.forEach(t => {
      if (t.receiptNumber && t.receiptNumber.startsWith(prefix)) {
        const seqPart = parseInt(t.receiptNumber.slice(prefix.length), 10);
        if (!isNaN(seqPart) && seqPart > maxSeq) {
          maxSeq = seqPart;
        }
      }
    });

    const nextSeq = String(maxSeq + 1).padStart(4, '0');
    return `${prefix}${nextSeq}`;
  }

  /* ==========================================================================
     5. ACCESS CONTROL & SESSION MANAGEMENT
     ========================================================================== */
  function isAuthenticated() {
    return sessionStorage.getItem(SESSION_KEY) === 'true';
  }

  function setAuthenticated(status) {
    if (status) {
      sessionStorage.setItem(SESSION_KEY, 'true');
    } else {
      sessionStorage.removeItem(SESSION_KEY);
    }
    updateAuthUI();
  }

  function updateAuthUI() {
    const isAuth = isAuthenticated();
    const loginScreen = document.getElementById('login-screen');
    const appContainer = document.getElementById('app-container');

    if (isAuth) {
      loginScreen.hidden = true;
      appContainer.hidden = false;
      renderAllViews();
    } else {
      loginScreen.hidden = false;
      appContainer.hidden = true;
      // Dọn sạch form đăng nhập
      const userInp = document.getElementById('login-username');
      const passInp = document.getElementById('login-password');
      if (userInp) userInp.value = '';
      if (passInp) passInp.value = '';
      clearErrors(document.getElementById('login-form'));
    }
  }

  /* ==========================================================================
     6. UI NAVIGATION & TABS
     ========================================================================== */
  let activeTabId = 'panel-reports';

  function switchTab(targetPanelId) {
    activeTabId = targetPanelId;
    const tabButtons = document.querySelectorAll('.nav-tabs button[role="tab"]');
    const panels = document.querySelectorAll('.tab-panel');

    tabButtons.forEach(btn => {
      const isSelected = btn.getAttribute('aria-controls') === targetPanelId;
      btn.setAttribute('aria-selected', isSelected ? 'true' : 'false');
      btn.tabIndex = isSelected ? 0 : -1;
    });

    panels.forEach(panel => {
      if (panel.id === targetPanelId) {
        panel.classList.add('active');
        panel.hidden = false;
      } else {
        panel.classList.remove('active');
        panel.hidden = true;
      }
    });

    // Refresh view tương ứng
    if (targetPanelId === 'panel-reports') renderReportsView();
    if (targetPanelId === 'panel-students') renderStudentsView();
    if (targetPanelId === 'panel-rates') renderRatesView();
    if (targetPanelId === 'panel-fees') renderFeesView();
    if (targetPanelId === 'panel-payments') renderPaymentsView();
    if (targetPanelId === 'panel-search') renderSearchView();
  }

  /* ==========================================================================
     7. CONFIRMATION DIALOG SERVICE (HỘP THOẠI XÁC NHẬN TIẾNG VIỆT CHO HÀNH ĐỘNG NGUY HIỂM)
     ========================================================================== */
  let pendingConfirmCallback = null;

  function showConfirmDialog(title, message, onConfirm) {
    const dialog = document.getElementById('modal-confirm');
    const titleEl = document.getElementById('modal-confirm-title');
    const descEl = document.getElementById('modal-confirm-desc');
    const cancelBtn = document.getElementById('btn-confirm-cancel');

    titleEl.textContent = title || 'Xác nhận thao tác';
    descEl.textContent = message || 'Bạn có chắc chắn muốn thực hiện thao tác này?';
    pendingConfirmCallback = onConfirm;

    dialog.showModal();
    if (cancelBtn) cancelBtn.focus();
  }

  function setupConfirmDialogEvents() {
    const dialog = document.getElementById('modal-confirm');
    const cancelBtn = document.getElementById('btn-confirm-cancel');
    const closeBtn = document.getElementById('btn-close-confirm-modal');
    const proceedBtn = document.getElementById('btn-confirm-proceed');

    function close() {
      pendingConfirmCallback = null;
      dialog.close();
      dialog.returnValue = '';
    }

    cancelBtn.addEventListener('click', close);
    closeBtn.addEventListener('click', close);

    proceedBtn.addEventListener('click', () => {
      if (typeof pendingConfirmCallback === 'function') {
        const cb = pendingConfirmCallback;
        pendingConfirmCallback = null;
        dialog.close();
        cb();
      }
    });

    dialog.addEventListener('cancel', () => {
      pendingConfirmCallback = null;
    });
  }

  /* ==========================================================================
     8. MODULE 1: ĐỊNH MỨC TÍN CHỈ THEO HỌC KỲ (CREDIT RATES)
     ========================================================================== */
  function renderRatesView() {
    const tbody = document.getElementById('rates-table-body');
    const rates = getRates();

    if (!rates || rates.length === 0) {
      tbody.innerHTML = `<tr><td colspan="4" class="empty-state">Chưa có định mức học phí nào được thiết lập.</td></tr>`;
      return;
    }

    tbody.innerHTML = rates.map(rate => `
      <tr>
        <td><strong>${escapeHtml(rate.semester)}</strong></td>
        <td class="text-right"><strong>${formatVND(rate.pricePerCredit)}</strong> / tín chỉ</td>
        <td>${formatDateVN(rate.updatedAt)}</td>
        <td class="text-center">
          <div class="btn-group">
            <button type="button" class="btn btn-sm btn-outline-primary btn-edit-rate" data-id="${escapeHtml(rate.id)}">Sửa đơn giá</button>
            <button type="button" class="btn btn-sm btn-outline-danger btn-delete-rate" data-id="${escapeHtml(rate.id)}">Xóa</button>
          </div>
        </td>
      </tr>
    `).join('');
  }

  function setupRateModal() {
    const dialog = document.getElementById('modal-rate');
    const openBtn = document.getElementById('btn-open-rate-modal');
    const closeBtn = document.getElementById('btn-close-rate-modal');
    const cancelBtn = document.getElementById('btn-cancel-rate');
    const form = document.getElementById('rate-form');
    const titleEl = document.getElementById('modal-rate-title');
    const editIdInp = document.getElementById('rate-edit-id');
    const semInp = document.getElementById('rate-semester-input');
    const priceInp = document.getElementById('rate-price-input');
    const saveBtn = document.getElementById('btn-save-rate');

    function openModal(rateToEdit = null) {
      clearErrors(form);
      if (rateToEdit) {
        titleEl.textContent = 'Chỉnh sửa định mức tín chỉ';
        editIdInp.value = rateToEdit.id;
        semInp.value = rateToEdit.semester;
        semInp.readOnly = true; // Không đổi tên học kỳ để giữ snapshot logic
        priceInp.value = rateToEdit.pricePerCredit.toLocaleString('vi-VN');
      } else {
        titleEl.textContent = 'Thêm định mức tín chỉ mới';
        editIdInp.value = '';
        semInp.value = '';
        semInp.readOnly = false;
        priceInp.value = '';
      }
      dialog.showModal();
      (rateToEdit ? priceInp : semInp).focus();
    }

    function closeModal() {
      dialog.close();
      dialog.returnValue = '';
    }

    openBtn.addEventListener('click', () => openModal(null));
    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      clearErrors(form);

      const semVal = semInp.value.trim();
      const priceVal = parseFormattedCurrency(priceInp.value);
      const isEditing = !!editIdInp.value;
      const rates = getRates();

      let hasError = false;

      if (!semVal) {
        showFieldError('rate-semester-error', 'Vui lòng nhập tên học kỳ.');
        hasError = true;
      }

      // Kiểm tra trùng học kỳ
      const duplicate = rates.find(r => r.semester.toLowerCase() === semVal.toLowerCase() && r.id !== editIdInp.value);
      if (duplicate) {
        showFieldError('rate-semester-error', 'Học kỳ này đã có định mức học phí. Mỗi học kỳ chỉ có duy nhất một mức đơn giá.');
        hasError = true;
      }

      if (isNaN(priceVal) || priceVal <= 0) {
        showFieldError('rate-price-error', 'Đơn giá tín chỉ phải là số nguyên dương hợp lệ (> 0 VND).');
        hasError = true;
      }

      if (hasError) return;

      saveBtn.disabled = true;

      try {
        if (isEditing) {
          const index = rates.findIndex(r => r.id === editIdInp.value);
          if (index !== -1) {
            rates[index].pricePerCredit = priceVal;
            rates[index].updatedAt = getTodayISO();
          }
        } else {
          rates.push({
            id: 'CR-' + Date.now(),
            semester: semVal,
            pricePerCredit: priceVal,
            updatedAt: getTodayISO()
          });
        }

        saveRates(rates);
        closeModal();
        renderRatesView();
        populateSemesterDropdowns();
      } finally {
        saveBtn.disabled = false;
      }
    });

    // Bắt sự kiện Sửa/Xóa từ bảng
    document.getElementById('rates-table-body').addEventListener('click', (e) => {
      const editBtn = e.target.closest('.btn-edit-rate');
      const delBtn = e.target.closest('.btn-delete-rate');

      if (editBtn) {
        const id = editBtn.dataset.id;
        const rate = getRates().find(r => r.id === id);
        if (rate) openModal(rate);
      } else if (delBtn) {
        const id = delBtn.dataset.id;
        const rate = getRates().find(r => r.id === id);
        if (!rate) return;

        // Kiểm tra xem đã có hồ sơ học phí nào đang liên kết với học kỳ này chưa
        const fees = getFees();
        const feeUsing = fees.find(f => f.semester === rate.semester);

        if (feeUsing) {
          showConfirmDialog(
            'Cảnh báo xóa định mức',
            `Học kỳ "${rate.semester}" đã có ${fees.filter(f => f.semester === rate.semester).length} hồ sơ học phí được lập. Các hồ sơ này đã lưu bản chụp đơn giá (snapshot) nên sẽ không bị ảnh hưởng, nhưng bạn sẽ không thể tạo hồ sơ mới cho học kỳ này cho đến khi thêm lại định mức. Bạn có chắc chắn muốn xóa?`,
            () => {
              const newRates = getRates().filter(r => r.id !== id);
              saveRates(newRates);
              renderRatesView();
              populateSemesterDropdowns();
            }
          );
        } else {
          showConfirmDialog(
            'Xóa định mức tín chỉ',
            `Bạn có chắc chắn muốn xóa định mức học kỳ "${rate.semester}"?`,
            () => {
              const newRates = getRates().filter(r => r.id !== id);
              saveRates(newRates);
              renderRatesView();
              populateSemesterDropdowns();
            }
          );
        }
      }
    });
  }

  /* ==========================================================================
     9. MODULE 2: HỒ SƠ HỌC PHÍ (FEE RECORDS) & TỰ ĐỘNG TÍNH TOÁN
     ========================================================================== */
  function renderFeesView() {
    const tbody = document.getElementById('fees-table-body');
    const filterSem = document.getElementById('fee-filter-semester').value;
    const filterStatus = document.getElementById('fee-filter-status').value;
    const searchKeyword = normalizeVietnamese(document.getElementById('fee-search-student').value);

    const fees = getFees();
    const students = getStudents();
    const txs = getTransactions();

    const studentMap = {};
    students.forEach(s => { studentMap[s.id] = s; });

    let filtered = fees.map(f => {
      const student = studentMap[f.studentId] || { fullName: 'Không rõ', id: f.studentId };
      const balances = calculateFeeBalances(f, txs);
      const status = derivePaymentStatus(balances.totalPaid, balances.netDue);
      return { ...f, student, balances, status };
    });

    if (filterSem !== 'ALL') {
      filtered = filtered.filter(f => f.semester === filterSem);
    }

    if (filterStatus !== 'ALL') {
      filtered = filtered.filter(f => f.status === filterStatus);
    }

    if (searchKeyword) {
      filtered = filtered.filter(f => {
        const sidMatch = normalizeVietnamese(f.studentId).includes(searchKeyword);
        const nameMatch = normalizeVietnamese(f.student.fullName).includes(searchKeyword);
        return sidMatch || nameMatch;
      });
    }

    if (filtered.length === 0) {
      tbody.innerHTML = `<tr><td colspan="12" class="empty-state">Không tìm thấy hồ sơ học phí nào phù hợp điều kiện lọc.</td></tr>`;
      return;
    }

    tbody.innerHTML = filtered.map(item => {
      const b = item.balances;
      const hasExemption = !!item.exemption;
      const isOverpaid = item.status === PAYMENT_STATUS.OVERPAID;

      return `
        <tr>
          <td><code>${escapeHtml(item.studentId)}</code></td>
          <td><strong>${escapeHtml(item.student.fullName)}</strong></td>
          <td>${escapeHtml(item.semester)}</td>
          <td class="text-right">${item.registeredCredits} TC</td>
          <td class="text-right">${formatVND(item.rateSnapshot)}</td>
          <td class="text-right">${formatVND(item.grossTuition)}</td>
          <td class="text-right">
            ${hasExemption ? `
              <span class="badge-tag tag-exempt" title="${escapeHtml(item.exemption.reason)}">
                -${formatVND(item.exemption.amount)} (${item.exemption.type === 'percentage' ? item.exemption.value + '%' : 'Cố định'})
              </span>
            ` : '<span class="text-muted">0 ₫</span>'}
          </td>
          <td class="text-right"><strong>${formatVND(b.netDue)}</strong></td>
          <td class="text-right text-success"><strong>${formatVND(b.totalPaid)}</strong></td>
          <td class="text-right ${b.outstandingBalance > 0 ? 'text-danger' : ''}">
            <strong>${formatVND(b.outstandingBalance)}</strong>
          </td>
          <td>${renderStatusBadge(item.status, b.overpaidAmount)}</td>
          <td class="text-center">
            <div class="btn-group">
              <button type="button" class="btn btn-sm btn-outline-primary btn-edit-fee" data-id="${escapeHtml(item.id)}" title="Sửa số tín chỉ">Sửa TC</button>
              <button type="button" class="btn btn-sm btn-outline-secondary btn-exempt-fee" data-id="${escapeHtml(item.id)}" title="Thiết lập miễn giảm">Miễn giảm</button>
              ${isOverpaid ? `
                <button type="button" class="btn btn-sm btn-outline-danger btn-open-refund" data-id="${escapeHtml(item.id)}" title="Lập yêu cầu hoàn tiền thừa">Hoàn tiền</button>
              ` : `
                ${b.outstandingBalance > 0 ? `
                  <button type="button" class="btn btn-sm btn-outline-success btn-quick-pay" data-id="${escapeHtml(item.id)}" title="Thu tiền cho hồ sơ này">Thu tiền</button>
                ` : ''}
              `}
            </div>
          </td>
        </tr>
      `;
    }).join('');
  }

  function setupFeeModal() {
    const dialog = document.getElementById('modal-fee');
    const openBtn = document.getElementById('btn-open-fee-modal');
    const closeBtn = document.getElementById('btn-close-fee-modal');
    const cancelBtn = document.getElementById('btn-cancel-fee');
    const form = document.getElementById('fee-form');
    const titleEl = document.getElementById('modal-fee-title');
    const editIdInp = document.getElementById('fee-edit-id');
    const studentSelect = document.getElementById('fee-student-select');
    const semSelect = document.getElementById('fee-semester-select');
    const creditsInp = document.getElementById('fee-credits-input');
    const rateDisplay = document.getElementById('fee-rate-display');
    const grossPreview = document.getElementById('fee-gross-preview');
    const saveBtn = document.getElementById('btn-save-fee');

    function updateGrossPreview() {
      const sem = semSelect.value;
      const isEditing = !!editIdInp.value;
      let ratePrice = 0;

      if (isEditing) {
        const fee = getFees().find(f => f.id === editIdInp.value);
        ratePrice = fee ? fee.rateSnapshot : 0;
      } else {
        const rateObj = getRates().find(r => r.semester === sem);
        ratePrice = rateObj ? rateObj.pricePerCredit : 0;
      }

      rateDisplay.value = ratePrice ? `${formatVND(ratePrice)} / tín chỉ` : 'Chưa có định mức';
      const credits = parseInt(creditsInp.value, 10) || 0;
      const gross = calculateGross(credits, ratePrice);
      grossPreview.textContent = formatVND(gross);
    }

    semSelect.addEventListener('change', updateGrossPreview);
    creditsInp.addEventListener('input', updateGrossPreview);

    function openModal(feeToEdit = null) {
      clearErrors(form);
      populateStudentSelect(studentSelect);
      populateSemesterDropdowns();

      if (feeToEdit) {
        titleEl.textContent = 'Chỉnh sửa số tín chỉ hồ sơ học phí';
        editIdInp.value = feeToEdit.id;
        studentSelect.value = feeToEdit.studentId;
        studentSelect.disabled = true;
        semSelect.value = feeToEdit.semester;
        semSelect.disabled = true;
        creditsInp.value = feeToEdit.registeredCredits;
        rateDisplay.value = `${formatVND(feeToEdit.rateSnapshot)} / tín chỉ`;
        updateGrossPreview();
      } else {
        titleEl.textContent = 'Lập hồ sơ học phí mới';
        editIdInp.value = '';
        studentSelect.value = '';
        studentSelect.disabled = false;
        semSelect.value = '';
        semSelect.disabled = false;
        creditsInp.value = '15';
        rateDisplay.value = '';
        updateGrossPreview();
      }

      dialog.showModal();
      if (!feeToEdit) studentSelect.focus();
      else creditsInp.focus();
    }

    function closeModal() {
      dialog.close();
      dialog.returnValue = '';
    }

    openBtn.addEventListener('click', () => openModal(null));
    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      clearErrors(form);

      const isEditing = !!editIdInp.value;
      const sId = studentSelect.value;
      const sem = semSelect.value;
      const credits = parseInt(creditsInp.value, 10);
      const fees = getFees();

      let hasError = false;

      if (!sId) {
        showFieldError('fee-student-error', 'Vui lòng chọn sinh viên.');
        hasError = true;
      }

      if (!sem) {
        showFieldError('fee-semester-error', 'Vui lòng chọn học kỳ.');
        hasError = true;
      }

      if (isNaN(credits) || credits < 1 || credits > 50) {
        showFieldError('fee-credits-error', 'Số tín chỉ đăng ký phải từ 1 đến 50.');
        hasError = true;
      }

      // Kiểm tra trùng lặp: mỗi sinh viên chỉ có tối đa 1 hồ sơ mỗi học kỳ
      if (!isEditing) {
        const duplicate = fees.find(f => f.studentId === sId && f.semester === sem);
        if (duplicate) {
          showFieldError('fee-student-error', 'Sinh viên đã có hồ sơ học phí trong học kỳ này. Mỗi sinh viên chỉ có tối đa một hồ sơ mỗi học kỳ.');
          hasError = true;
        }
      }

      if (hasError) return;

      saveBtn.disabled = true;

      try {
        if (isEditing) {
          const index = fees.findIndex(f => f.id === editIdInp.value);
          if (index !== -1) {
            const fee = fees[index];
            fee.registeredCredits = credits;
            fee.grossTuition = calculateGross(credits, fee.rateSnapshot);

            // Tự động tính lại miễn giảm nếu có
            if (fee.exemption) {
              fee.exemption.amount = calculateExemption(fee.grossTuition, fee.exemption.type, fee.exemption.value);
            }
            const exAmt = fee.exemption ? fee.exemption.amount : 0;
            fee.netDue = calculateNetDue(fee.grossTuition, exAmt);
          }
        } else {
          const rateObj = getRates().find(r => r.semester === sem);
          if (!rateObj) {
            showFieldError('fee-semester-error', 'Học kỳ được chọn chưa có định mức học phí. Vui lòng tạo định mức trước.');
            saveBtn.disabled = false;
            return;
          }

          const gross = calculateGross(credits, rateObj.pricePerCredit);
          fees.push({
            id: 'FR-' + Date.now(),
            studentId: sId,
            semester: sem,
            registeredCredits: credits,
            rateSnapshot: rateObj.pricePerCredit, // Lưu bản chụp đơn giá
            grossTuition: gross,
            exemption: null,
            netDue: gross,
            createdAt: getTodayISO()
          });
        }

        saveFees(fees);
        closeModal();
        renderFeesView();
        renderStudentsView();
        renderReportsView();
      } finally {
        saveBtn.disabled = false;
      }
    });

    // Bắt sự kiện chỉnh sửa hồ sơ / miễn giảm từ bảng
    document.getElementById('fees-table-body').addEventListener('click', (e) => {
      const editBtn = e.target.closest('.btn-edit-fee');
      const exemptBtn = e.target.closest('.btn-exempt-fee');
      const payBtn = e.target.closest('.btn-quick-pay');
      const refundBtn = e.target.closest('.btn-open-refund');

      if (editBtn) {
        const id = editBtn.dataset.id;
        const fee = getFees().find(f => f.id === id);
        if (fee) openModal(fee);
      } else if (exemptBtn) {
        const id = exemptBtn.dataset.id;
        openExemptionModal(id);
      } else if (payBtn) {
        const id = payBtn.dataset.id;
        openPaymentModal(id);
      } else if (refundBtn) {
        const id = refundBtn.dataset.id;
        openRefundModal(id);
      }
    });

    // Lắng nghe bộ lọc học phí
    document.getElementById('fee-filter-semester').addEventListener('change', renderFeesView);
    document.getElementById('fee-filter-status').addEventListener('change', renderFeesView);
    document.getElementById('fee-search-student').addEventListener('input', renderFeesView);
  }

  /* ==========================================================================
     10. MODULE 3: MIỄN GIẢM HỌC PHÍ (EXEMPTIONS & REDUCTIONS)
     ========================================================================== */
  function openExemptionModal(feeRecordId) {
    const dialog = document.getElementById('modal-exemption');
    const feeIdInp = document.getElementById('exemption-fee-id');
    const studentNameEl = document.getElementById('exemption-student-name');
    const semEl = document.getElementById('exemption-semester');
    const grossEl = document.getElementById('exemption-gross-tuition');
    const paidEl = document.getElementById('exemption-already-paid');
    const radioPct = document.getElementById('radio-exempt-percent');
    const radioFixed = document.getElementById('radio-exempt-fixed');
    const valInp = document.getElementById('exemption-value-input');
    const valLabel = document.getElementById('exemption-value-label');
    const reasonInp = document.getElementById('exemption-reason-input');
    const charCountEl = document.getElementById('exemption-char-num');
    const calcAmtEl = document.getElementById('exempt-calc-amount');
    const calcNetEl = document.getElementById('exempt-calc-net');
    const overpaidWarnBox = document.getElementById('exempt-overpaid-warning');
    const warnPaidEl = document.getElementById('exempt-warning-paid');
    const removeBtn = document.getElementById('btn-remove-exemption');

    const fee = getFees().find(f => f.id === feeRecordId);
    if (!fee) return;

    const student = getStudents().find(s => s.id === fee.studentId) || { fullName: 'Không rõ' };
    const balances = calculateFeeBalances(fee, getTransactions());

    feeIdInp.value = fee.id;
    studentNameEl.textContent = `${student.fullName} (${fee.studentId})`;
    semEl.textContent = fee.semester;
    grossEl.textContent = formatVND(fee.grossTuition);
    paidEl.textContent = formatVND(balances.totalPaid);

    clearErrors(document.getElementById('exemption-form'));

    if (fee.exemption) {
      if (fee.exemption.type === 'percentage') {
        radioPct.checked = true;
        valLabel.innerHTML = 'Mức giảm (%): <span class="required">*</span>';
        valInp.value = fee.exemption.value;
      } else {
        radioFixed.checked = true;
        valLabel.innerHTML = 'Số tiền giảm (VND): <span class="required">*</span>';
        valInp.value = fee.exemption.value.toLocaleString('vi-VN');
      }
      reasonInp.value = fee.exemption.reason;
      removeBtn.hidden = false;
    } else {
      radioPct.checked = true;
      valLabel.innerHTML = 'Mức giảm (%): <span class="required">*</span>';
      valInp.value = '';
      reasonInp.value = '';
      removeBtn.hidden = true;
    }

    charCountEl.textContent = reasonInp.value.length;

    function updatePreview() {
      const type = radioPct.checked ? 'percentage' : 'fixed';
      let rawVal = 0;
      if (type === 'percentage') {
        rawVal = parseFloat(valInp.value) || 0;
      } else {
        rawVal = parseFormattedCurrency(valInp.value) || 0;
      }

      const exemptAmt = calculateExemption(fee.grossTuition, type, rawVal);
      const newNet = calculateNetDue(fee.grossTuition, exemptAmt);

      calcAmtEl.textContent = formatVND(exemptAmt);
      calcNetEl.textContent = formatVND(newNet);

      // Cảnh báo nộp thừa nếu newNet < totalPaid
      if (newNet < balances.totalPaid) {
        warnPaidEl.textContent = formatVND(balances.totalPaid);
        overpaidWarnBox.hidden = false;
      } else {
        overpaidWarnBox.hidden = true;
      }
    }

    valInp.oninput = updatePreview;
    reasonInp.oninput = () => {
      charCountEl.textContent = reasonInp.value.length;
    };

    radioPct.onchange = () => {
      valLabel.innerHTML = 'Mức giảm (%): <span class="required">*</span>';
      valInp.placeholder = 'VD: 20 (từ 1 đến 100%)';
      updatePreview();
    };

    radioFixed.onchange = () => {
      valLabel.innerHTML = 'Số tiền giảm (VND): <span class="required">*</span>';
      valInp.placeholder = 'VD: 1.000.000 hoặc 1000000';
      updatePreview();
    };

    updatePreview();
    dialog.showModal();
    valInp.focus();
  }

  function setupExemptionEvents() {
    const dialog = document.getElementById('modal-exemption');
    const form = document.getElementById('exemption-form');
    const closeBtn = document.getElementById('btn-close-exemption-modal');
    const cancelBtn = document.getElementById('btn-cancel-exemption');
    const removeBtn = document.getElementById('btn-remove-exemption');
    const saveBtn = document.getElementById('btn-save-exemption');

    function closeModal() {
      dialog.close();
      dialog.returnValue = '';
    }

    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);

    // Xóa miễn giảm
    removeBtn.addEventListener('click', () => {
      const feeId = document.getElementById('exemption-fee-id').value;
      showConfirmDialog(
        'Xóa miễn giảm học phí',
        'Bạn có chắc chắn muốn hủy bỏ chính sách miễn giảm cho hồ sơ này?',
        () => {
          const fees = getFees();
          const fee = fees.find(f => f.id === feeId);
          if (fee) {
            fee.exemption = null;
            fee.netDue = fee.grossTuition;
            saveFees(fees);
            closeModal();
            renderFeesView();
            renderStudentsView();
            renderReportsView();
          }
        }
      );
    });

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      clearErrors(form);

      const feeId = document.getElementById('exemption-fee-id').value;
      const type = document.getElementById('radio-exempt-percent').checked ? 'percentage' : 'fixed';
      const valStr = document.getElementById('exemption-value-input').value.trim();
      const reason = document.getElementById('exemption-reason-input').value.trim();

      const fees = getFees();
      const fee = fees.find(f => f.id === feeId);
      if (!fee) return;

      let hasError = false;
      let numericVal = 0;

      if (type === 'percentage') {
        const pct = parseFloat(valStr);
        if (isNaN(pct) || pct < 1 || pct > 100) {
          showFieldError('exemption-value-error', 'Tỷ lệ phần trăm giảm phải từ 1% đến 100%.');
          hasError = true;
        } else {
          numericVal = pct;
        }
      } else {
        const fixedAmt = parseFormattedCurrency(valStr);
        if (isNaN(fixedAmt) || fixedAmt <= 0) {
          showFieldError('exemption-value-error', 'Số tiền miễn giảm cố định phải là số nguyên dương (> 0 VND).');
          hasError = true;
        } else if (fixedAmt > fee.grossTuition) {
          showFieldError('exemption-value-error', `Số tiền miễn giảm không được vượt quá học phí gốc (${formatVND(fee.grossTuition)}).`);
          hasError = true;
        } else {
          numericVal = fixedAmt;
        }
      }

      if (!reason) {
        showFieldError('exemption-reason-error', 'Vui lòng nhập lý do miễn giảm học phí.');
        hasError = true;
      } else if (reason.length > 200) {
        showFieldError('exemption-reason-error', 'Lý do miễn giảm không được vượt quá 200 ký tự.');
        hasError = true;
      }

      if (hasError) return;

      saveBtn.disabled = true;

      try {
        const exemptAmt = calculateExemption(fee.grossTuition, type, numericVal);
        const newNet = calculateNetDue(fee.grossTuition, exemptAmt);

        fee.exemption = {
          type,
          value: numericVal,
          amount: exemptAmt,
          reason,
          appliedAt: getTodayISO()
        };
        fee.netDue = newNet;

        saveFees(fees);
        closeModal();
        renderFeesView();
        renderStudentsView();
        renderReportsView();
      } finally {
        saveBtn.disabled = false;
      }
    });
  }

  /* ==========================================================================
     11. MODULE 4: THU HỌC PHÍ, HOÀN TIỀN & ĐẢO MỤC (TRANSACTIONS)
     ========================================================================== */
  function renderPaymentsView() {
    renderTransactionsTable();
    renderRefundsTable();
  }

  function renderTransactionsTable() {
    const tbody = document.getElementById('transactions-table-body');
    const txs = getTransactions();
    const fees = getFees();
    const students = getStudents();

    const studentMap = {};
    students.forEach(s => { studentMap[s.id] = s; });

    const feeMap = {};
    fees.forEach(f => { feeMap[f.id] = f; });

    // Sắp xếp mới nhất lên đầu
    const sorted = [...txs].sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt));

    if (sorted.length === 0) {
      tbody.innerHTML = `<tr><td colspan="11" class="empty-state">Chưa có giao dịch sổ quỹ nào được ghi nhận.</td></tr>`;
      return;
    }

    tbody.innerHTML = sorted.map(tx => {
      const student = studentMap[tx.studentId] || { fullName: 'Không rõ' };
      const isReversed = txs.some(t => t.type === TX_TYPES.REVERSAL && t.targetTxId === tx.id);

      return `
        <tr>
          <td><strong><code>${escapeHtml(tx.receiptNumber || tx.id)}</code></strong></td>
          <td>${formatDateVN(tx.paymentDate)}</td>
          <td><code>${escapeHtml(tx.studentId)}</code></td>
          <td>${escapeHtml(student.fullName)}</td>
          <td>${escapeHtml(tx.semester)}</td>
          <td>
            <span class="badge-tag ${tx.type === TX_TYPES.PAYMENT ? 'kpi-green' : (tx.type === TX_TYPES.REFUND ? 'kpi-amber' : 'kpi-blue')}">
              ${TX_TYPE_LABELS[tx.type] || tx.type}
            </span>
          </td>
          <td class="text-right"><strong>${formatVND(tx.amount)}</strong></td>
          <td>${escapeHtml(tx.method || '-')}</td>
          <td>
            ${isReversed ? `
              <span class="status-badge status-unpaid">Đã đảo mục (Hủy)</span>
            ` : (tx.status === REFUND_STATUS.PENDING ? `
              <span class="status-badge status-partial">Chờ duyệt hoàn</span>
            ` : `
              <span class="status-badge status-paid">Hoàn thành</span>
            `)}
          </td>
          <td><small class="text-muted">${escapeHtml(tx.note || tx.refundReason || tx.reversalReason || '-')}</small></td>
          <td class="text-center">
            <div class="btn-group">
              ${tx.type === TX_TYPES.PAYMENT ? `
                <button type="button" class="btn btn-sm btn-outline-primary btn-print-receipt" data-id="${escapeHtml(tx.id)}">🖨️ In biên lai</button>
              ` : ''}
              ${!isReversed && tx.type !== TX_TYPES.REVERSAL && tx.status !== REFUND_STATUS.PENDING ? `
                <button type="button" class="btn btn-sm btn-outline-danger btn-reverse-tx" data-id="${escapeHtml(tx.id)}" title="Lập bút toán đảo mục sửa sai">Đảo mục</button>
              ` : ''}
            </div>
          </td>
        </tr>
      `;
    }).join('');
  }

  function renderRefundsTable() {
    const tbody = document.getElementById('refunds-table-body');
    const txs = getTransactions();
    const students = getStudents();
    const pendingCountEl = document.getElementById('pending-refund-count');

    const studentMap = {};
    students.forEach(s => { studentMap[s.id] = s; });

    const refunds = txs.filter(t => t.type === TX_TYPES.REFUND);
    const pendingRefunds = refunds.filter(r => r.status === REFUND_STATUS.PENDING);
    if (pendingCountEl) pendingCountEl.textContent = pendingRefunds.length;

    if (refunds.length === 0) {
      tbody.innerHTML = `<tr><td colspan="9" class="empty-state">Chưa có yêu cầu hoàn tiền nào.</td></tr>`;
      return;
    }

    tbody.innerHTML = refunds.map(r => {
      const student = studentMap[r.studentId] || { fullName: 'Không rõ' };
      const isPending = r.status === REFUND_STATUS.PENDING;

      return `
        <tr>
          <td><code>${escapeHtml(r.receiptNumber || r.id)}</code></td>
          <td>${formatDateVN(r.paymentDate)}</td>
          <td><code>${escapeHtml(r.studentId)}</code></td>
          <td><strong>${escapeHtml(student.fullName)}</strong></td>
          <td>${escapeHtml(r.semester)}</td>
          <td class="text-right text-danger"><strong>${formatVND(r.amount)}</strong></td>
          <td>${escapeHtml(r.refundReason || '-')}</td>
          <td>
            <span class="status-badge ${isPending ? 'status-partial' : 'status-paid'}">
              ${r.status}
            </span>
          </td>
          <td class="text-center">
            ${isPending ? `
              <button type="button" class="btn btn-sm btn-success btn-complete-refund" data-id="${escapeHtml(r.id)}">
                ✓ Duyệt &amp; Đã hoàn tiền
              </button>
            ` : `
              <span class="text-muted">Đã xử lý</span>
            `}
          </td>
        </tr>
      `;
    }).join('');
  }

  function setupPaymentModal() {
    const dialog = document.getElementById('modal-payment');
    const openBtn = document.getElementById('btn-open-payment-modal');
    const closeBtn = document.getElementById('btn-close-payment-modal');
    const cancelBtn = document.getElementById('btn-cancel-payment');
    const form = document.getElementById('payment-form');
    const feeSelect = document.getElementById('payment-fee-select');
    const detailBox = document.getElementById('payment-fee-detail-box');
    const stNameEl = document.getElementById('pay-detail-student');
    const semEl = document.getElementById('pay-detail-semester');
    const netEl = document.getElementById('pay-detail-net');
    const paidEl = document.getElementById('pay-detail-paid');
    const balEl = document.getElementById('pay-detail-balance');
    const amountInp = document.getElementById('payment-amount-input');
    const dateInp = document.getElementById('payment-date-input');
    const methodSelect = document.getElementById('payment-method-select');
    const noteInp = document.getElementById('payment-note-input');
    const btnPayFull = document.getElementById('btn-pay-full');
    const payFullText = document.getElementById('pay-full-text');
    const saveBtn = document.getElementById('btn-save-payment');

    dateInp.value = getTodayISO();
    dateInp.max = getTodayISO(); // Không được nộp trong tương lai

    function populateFeeSelect(preselectedFeeId = null) {
      const fees = getFees();
      const students = getStudents();
      const txs = getTransactions();

      const studentMap = {};
      students.forEach(s => { studentMap[s.id] = s; });

      feeSelect.innerHTML = '<option value="">-- Chọn hồ sơ học phí --</option>';

      fees.forEach(f => {
        const student = studentMap[f.studentId] || { fullName: 'Không rõ' };
        const balances = calculateFeeBalances(f, txs);
        // Chỉ ưu tiên hiển thị hồ sơ còn nợ hoặc chọn đích danh
        const isSelected = f.id === preselectedFeeId;
        const opt = document.createElement('option');
        opt.value = f.id;
        opt.textContent = `${student.fullName} (${f.studentId}) - ${f.semester} [Còn nợ: ${formatVND(balances.outstandingBalance)}]`;
        if (isSelected) opt.selected = true;
        feeSelect.appendChild(opt);
      });
    }

    function updateFeeDetails() {
      const feeId = feeSelect.value;
      if (!feeId) {
        detailBox.hidden = true;
        btnPayFull.style.display = 'none';
        return;
      }

      const fee = getFees().find(f => f.id === feeId);
      if (!fee) return;

      const student = getStudents().find(s => s.id === fee.studentId) || { fullName: 'Không rõ' };
      const balances = calculateFeeBalances(fee, getTransactions());

      stNameEl.textContent = `${student.fullName} (${fee.studentId})`;
      semEl.textContent = fee.semester;
      netEl.textContent = formatVND(balances.netDue);
      paidEl.textContent = formatVND(balances.totalPaid);
      balEl.textContent = formatVND(balances.outstandingBalance);

      detailBox.hidden = false;

      if (balances.outstandingBalance > 0) {
        payFullText.textContent = formatVND(balances.outstandingBalance);
        btnPayFull.style.display = 'inline-block';
        btnPayFull.onclick = () => {
          amountInp.value = balances.outstandingBalance.toLocaleString('vi-VN');
          clearErrors(form);
        };
      } else {
        btnPayFull.style.display = 'none';
      }
    }

    feeSelect.addEventListener('change', updateFeeDetails);

    window.openPaymentModal = function (feeRecordId) {
      clearErrors(form);
      populateFeeSelect(feeRecordId);
      amountInp.value = '';
      dateInp.value = getTodayISO();
      noteInp.value = '';
      updateFeeDetails();
      dialog.showModal();
      if (!feeRecordId) feeSelect.focus();
      else amountInp.focus();
    };

    function closeModal() {
      dialog.close();
      dialog.returnValue = '';
    }

    openBtn.addEventListener('click', () => window.openPaymentModal(null));
    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      clearErrors(form);

      const feeId = feeSelect.value;
      const amt = parseFormattedCurrency(amountInp.value);
      const payDate = dateInp.value;
      const method = methodSelect.value;
      const note = noteInp.value.trim();

      let hasError = false;

      if (!feeId) {
        showFieldError('payment-fee-error', 'Vui lòng chọn hồ sơ học phí cần thanh toán.');
        hasError = true;
      }

      if (isNaN(amt) || amt <= 0) {
        showFieldError('payment-amount-error', 'Số tiền thanh toán phải là số nguyên dương hợp lệ (> 0 VND).');
        hasError = true;
      }

      if (!payDate) {
        showFieldError('payment-date-error', 'Vui lòng chọn ngày thanh toán.');
        hasError = true;
      } else if (payDate > getTodayISO()) {
        showFieldError('payment-date-error', 'Ngày thanh toán không được lớn hơn ngày hiện tại.');
        hasError = true;
      }

      if (hasError) return;

      const fee = getFees().find(f => f.id === feeId);
      if (!fee) return;

      const balances = calculateFeeBalances(fee, getTransactions());

      // ==========================================
      // QUY TẮC NỘP THỪA (OVERPAYMENT RULE) BẮT BUỘC
      // ==========================================
      if (balances.totalPaid + amt > balances.netDue) {
        // Thông điệp chính xác theo yêu cầu:
        // "Số tiền vượt quá số còn nợ. Vui lòng lập yêu cầu hoàn tiền trước."
        showFieldError('payment-amount-error', 'Số tiền vượt quá số còn nợ. Vui lòng lập yêu cầu hoàn tiền trước.');
        return; // Không lưu bất cứ thứ gì!
      }

      saveBtn.disabled = true;

      try {
        const receiptNo = generateNextReceiptNumber(payDate);
        const txs = getTransactions();

        const newPayment = {
          id: 'TX-' + Date.now(),
          receiptNumber: receiptNo,
          feeRecordId: fee.id,
          studentId: fee.studentId,
          semester: fee.semester,
          type: TX_TYPES.PAYMENT,
          amount: amt,
          paymentDate: payDate,
          method,
          note,
          status: 'COMPLETED',
          adminName: DEMO_ADMIN.displayName,
          createdAt: new Date().toISOString()
        };

        txs.push(newPayment);
        saveTransactions(txs);

        closeModal();
        renderPaymentsView();
        renderFeesView();
        renderStudentsView();
        renderReportsView();
        renderSearchView();

        // Mở cửa sổ in biên lai tự động cho tiện ích người dùng
        showConfirmDialog(
          'Thanh toán thành công',
          `Đã ghi nhận phiếu thu số ${receiptNo} với số tiền ${formatVND(amt)}. Bạn có muốn in biên lai ngay bây giờ?`,
          () => {
            openReceiptPrintView(newPayment.id);
          }
        );
      } finally {
        saveBtn.disabled = false;
      }
    });
  }

  function setupRefundModal() {
    const dialog = document.getElementById('modal-refund');
    const form = document.getElementById('refund-form');
    const feeIdInp = document.getElementById('refund-fee-id');
    const studentNameEl = document.getElementById('refund-student-name');
    const semEl = document.getElementById('refund-semester');
    const paidEl = document.getElementById('refund-paid-amount');
    const netEl = document.getElementById('refund-net-amount');
    const maxEl = document.getElementById('refund-max-amount');
    const amountInp = document.getElementById('refund-amount-input');
    const reasonInp = document.getElementById('refund-reason-input');
    const closeBtn = document.getElementById('btn-close-refund-modal');
    const cancelBtn = document.getElementById('btn-cancel-refund');
    const saveBtn = document.getElementById('btn-save-refund');

    window.openRefundModal = function (feeRecordId) {
      clearErrors(form);
      const fee = getFees().find(f => f.id === feeRecordId);
      if (!fee) return;

      const student = getStudents().find(s => s.id === fee.studentId) || { fullName: 'Không rõ' };
      const balances = calculateFeeBalances(fee, getTransactions());

      feeIdInp.value = fee.id;
      studentNameEl.textContent = `${student.fullName} (${fee.studentId})`;
      semEl.textContent = fee.semester;
      paidEl.textContent = formatVND(balances.totalPaid);
      netEl.textContent = formatVND(balances.netDue);

      const maxRefundable = balances.overpaidAmount;
      maxEl.textContent = formatVND(maxRefundable);

      amountInp.value = maxRefundable > 0 ? maxRefundable.toLocaleString('vi-VN') : '';
      reasonInp.value = '';

      dialog.showModal();
      amountInp.focus();
    };

    function closeModal() {
      dialog.close();
      dialog.returnValue = '';
    }

    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      clearErrors(form);

      const feeId = feeIdInp.value;
      const amt = parseFormattedCurrency(amountInp.value);
      const reason = reasonInp.value.trim();

      const fee = getFees().find(f => f.id === feeId);
      if (!fee) return;

      const balances = calculateFeeBalances(fee, getTransactions());

      let hasError = false;

      if (isNaN(amt) || amt <= 0) {
        showFieldError('refund-amount-error', 'Số tiền hoàn phải là số nguyên dương (> 0 VND).');
        hasError = true;
      } else if (amt > balances.overpaidAmount) {
        showFieldError('refund-amount-error', `Số tiền hoàn không được vượt quá số tiền nộp thừa (${formatVND(balances.overpaidAmount)}).`);
        hasError = true;
      }

      if (!reason) {
        showFieldError('refund-reason-error', 'Vui lòng nhập lý do hoàn tiền (bắt buộc).');
        hasError = true;
      } else if (reason.length > 200) {
        showFieldError('refund-reason-error', 'Lý do không được vượt quá 200 ký tự.');
        hasError = true;
      }

      if (hasError) return;

      saveBtn.disabled = true;

      try {
        const txs = getTransactions();
        const refundNo = 'HT-' + Date.now().toString().slice(-6);

        txs.push({
          id: 'TX-' + Date.now(),
          receiptNumber: refundNo,
          feeRecordId: fee.id,
          studentId: fee.studentId,
          semester: fee.semester,
          type: TX_TYPES.REFUND,
          amount: amt,
          paymentDate: getTodayISO(),
          method: 'Chuyển khoản',
          refundReason: reason,
          status: REFUND_STATUS.PENDING, // Khởi tạo: "Chờ duyệt"
          adminName: DEMO_ADMIN.displayName,
          createdAt: new Date().toISOString()
        });

        saveTransactions(txs);
        closeModal();
        renderPaymentsView();
        renderFeesView();
        renderReportsView();
      } finally {
        saveBtn.disabled = false;
      }
    });

    // Duyệt hoàn tiền: Chuyển sang "Đã hoàn tiền"
    document.getElementById('refunds-table-body').addEventListener('click', (e) => {
      const compBtn = e.target.closest('.btn-complete-refund');
      if (compBtn) {
        const id = compBtn.dataset.id;
        const txs = getTransactions();
        const refund = txs.find(t => t.id === id);
        if (!refund) return;

        showConfirmDialog(
          'Duyệt hoàn tiền học phí',
          `Xác nhận đánh dấu "Đã hoàn tiền" cho khoản hoàn ${formatVND(refund.amount)} của sinh viên ${refund.studentId}? Thao tác này sẽ chính thức giảm số tiền đã nộp của hồ sơ học phí.`,
          () => {
            refund.status = REFUND_STATUS.COMPLETED;
            saveTransactions(txs);
            renderPaymentsView();
            renderFeesView();
            renderStudentsView();
            renderReportsView();
          }
        );
      }
    });
  }

  function setupReversalModal() {
    const dialog = document.getElementById('modal-reversal');
    const form = document.getElementById('reversal-form');
    const txIdInp = document.getElementById('reversal-tx-id');
    const rcNoEl = document.getElementById('reversal-receipt-no');
    const origTypeEl = document.getElementById('reversal-orig-type');
    const amtEl = document.getElementById('reversal-amount');
    const reasonInp = document.getElementById('reversal-reason-input');
    const closeBtn = document.getElementById('btn-close-reversal-modal');
    const cancelBtn = document.getElementById('btn-cancel-reversal');
    const confirmBtn = document.getElementById('btn-confirm-reversal');

    function openModal(txId) {
      clearErrors(form);
      const tx = getTransactions().find(t => t.id === txId);
      if (!tx) return;

      txIdInp.value = tx.id;
      rcNoEl.textContent = tx.receiptNumber || tx.id;
      origTypeEl.textContent = TX_TYPE_LABELS[tx.type] || tx.type;
      amtEl.textContent = formatVND(tx.amount);
      reasonInp.value = '';

      dialog.showModal();
      reasonInp.focus();
    }

    function closeModal() {
      dialog.close();
      dialog.returnValue = '';
    }

    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      clearErrors(form);

      const txId = txIdInp.value;
      const reason = reasonInp.value.trim();
      const txs = getTransactions();
      const origTx = txs.find(t => t.id === txId);
      if (!origTx) return;

      if (!reason) {
        showFieldError('reversal-reason-error', 'Vui lòng nhập lý do đảo mục.');
        return;
      }

      confirmBtn.disabled = true;

      try {
        const revNo = 'DAO-' + Date.now().toString().slice(-6);

        // Tạo bút toán đảo mục (Reversal Entry)
        txs.push({
          id: 'TX-' + Date.now(),
          receiptNumber: revNo,
          feeRecordId: origTx.feeRecordId,
          studentId: origTx.studentId,
          semester: origTx.semester,
          type: TX_TYPES.REVERSAL,
          amount: origTx.amount,
          paymentDate: getTodayISO(),
          method: origTx.method,
          targetTxId: origTx.id,
          targetType: origTx.type,
          reversalReason: reason,
          status: 'COMPLETED',
          adminName: DEMO_ADMIN.displayName,
          createdAt: new Date().toISOString()
        });

        saveTransactions(txs);
        closeModal();
        renderPaymentsView();
        renderFeesView();
        renderStudentsView();
        renderReportsView();
        renderSearchView();
      } finally {
        confirmBtn.disabled = false;
      }
    });

    // Lắng nghe nút Đảo mục từ bảng giao dịch
    document.getElementById('transactions-table-body').addEventListener('click', (e) => {
      const revBtn = e.target.closest('.btn-reverse-tx');
      const printBtn = e.target.closest('.btn-print-receipt');

      if (revBtn) {
        openModal(revBtn.dataset.id);
      } else if (printBtn) {
        openReceiptPrintView(printBtn.dataset.id);
      }
    });

    // Chuyển đổi tab con Giao dịch / Hoàn tiền
    const subtabTx = document.getElementById('subtab-all-tx');
    const subtabRef = document.getElementById('subtab-refunds');
    const txView = document.getElementById('tx-view-container');
    const refView = document.getElementById('refunds-view-container');

    subtabTx.addEventListener('click', () => {
      subtabTx.classList.add('active');
      subtabRef.classList.remove('active');
      txView.hidden = false;
      refView.hidden = true;
    });

    subtabRef.addEventListener('click', () => {
      subtabRef.classList.add('active');
      subtabTx.classList.remove('active');
      txView.hidden = true;
      refView.hidden = false;
    });
  }

  /* ==========================================================================
     12. MODULE 5: DANH BẠ SINH VIÊN (STUDENT DIRECTORY)
     ========================================================================== */
  function renderStudentsView() {
    const tbody = document.getElementById('students-table-body');
    const counterEl = document.getElementById('student-count-display');
    const searchVal = normalizeVietnamese(document.getElementById('student-search-input').value);

    const students = getStudents();
    const fees = getFees();
    const txs = getTransactions();

    let filtered = students;
    if (searchVal) {
      filtered = students.filter(s => {
        const idMatch = normalizeVietnamese(s.id).includes(searchVal);
        const nameMatch = normalizeVietnamese(s.fullName).includes(searchVal);
        const classMatch = s.className ? normalizeVietnamese(s.className).includes(searchVal) : false;
        return idMatch || nameMatch || classMatch;
      });
    }

    if (counterEl) counterEl.textContent = filtered.length;

    if (filtered.length === 0) {
      tbody.innerHTML = `<tr><td colspan="8" class="empty-state">Không tìm thấy sinh viên nào.</td></tr>`;
      return;
    }

    tbody.innerHTML = filtered.map(s => {
      // Tổng hợp công nợ xuyên học kỳ của sinh viên này
      const studentFees = fees.filter(f => f.studentId === s.id);
      let totalDue = 0;
      let totalPaid = 0;
      let totalOutstanding = 0;

      studentFees.forEach(f => {
        const b = calculateFeeBalances(f, txs);
        totalDue += b.netDue;
        totalPaid += b.totalPaid;
        totalOutstanding += b.outstandingBalance;
      });

      // Tổng hoàn lại
      const studentRefunds = txs.filter(t => t.studentId === s.id && t.type === TX_TYPES.REFUND && t.status === REFUND_STATUS.COMPLETED);
      const totalRefunded = studentRefunds.reduce((sum, r) => sum + r.amount, 0);

      return `
        <tr>
          <td><strong><code>${escapeHtml(s.id)}</code></strong></td>
          <td>${escapeHtml(s.fullName)}</td>
          <td>${escapeHtml(s.className || '-')}</td>
          <td class="text-right"><strong>${formatVND(totalDue)}</strong></td>
          <td class="text-right text-success"><strong>${formatVND(totalPaid)}</strong></td>
          <td class="text-right text-muted">${formatVND(totalRefunded)}</td>
          <td class="text-right ${totalOutstanding > 0 ? 'text-danger' : ''}">
            <strong>${formatVND(totalOutstanding)}</strong>
          </td>
          <td class="text-center">
            <div class="btn-group">
              <button type="button" class="btn btn-sm btn-outline-primary btn-edit-student" data-id="${escapeHtml(s.id)}">Sửa</button>
              <button type="button" class="btn btn-sm btn-outline-danger btn-delete-student" data-id="${escapeHtml(s.id)}">Xóa</button>
            </div>
          </td>
        </tr>
      `;
    }).join('');
  }

  function setupStudentModal() {
    const dialog = document.getElementById('modal-student');
    const openBtn = document.getElementById('btn-open-student-modal');
    const closeBtn = document.getElementById('btn-close-student-modal');
    const cancelBtn = document.getElementById('btn-cancel-student');
    const form = document.getElementById('student-form');
    const titleEl = document.getElementById('modal-student-title');
    const idInp = document.getElementById('student-id-input');
    const nameInp = document.getElementById('student-name-input');
    const classInp = document.getElementById('student-class-input');
    const saveBtn = document.getElementById('btn-save-student');
    let editingStudentId = null;

    function openModal(studentToEdit = null) {
      clearErrors(form);
      if (studentToEdit) {
        titleEl.textContent = 'Chỉnh sửa thông tin sinh viên';
        editingStudentId = studentToEdit.id;
        idInp.value = studentToEdit.id;
        idInp.readOnly = true;
        nameInp.value = studentToEdit.fullName;
        classInp.value = studentToEdit.className || '';
      } else {
        titleEl.textContent = 'Thêm sinh viên mới';
        editingStudentId = null;
        idInp.value = '';
        idInp.readOnly = false;
        nameInp.value = '';
        classInp.value = '';
      }
      dialog.showModal();
      (studentToEdit ? nameInp : idInp).focus();
    }

    function closeModal() {
      dialog.close();
      dialog.returnValue = '';
    }

    openBtn.addEventListener('click', () => openModal(null));
    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      clearErrors(form);

      const sId = idInp.value.trim().toUpperCase();
      const fName = nameInp.value.trim();
      const cName = classInp.value.trim();
      const isEditing = !!editingStudentId;
      const students = getStudents();

      let hasError = false;

      // Mã sinh viên: 6-12 ký tự chữ và số
      if (!sId) {
        showFieldError('student-id-error', 'Vui lòng nhập mã sinh viên.');
        hasError = true;
      } else if (!/^[A-Z0-9]{6,12}$/.test(sId)) {
        showFieldError('student-id-error', 'Mã sinh viên phải gồm 6–12 ký tự chữ và số (không dấu, không khoảng trắng).');
        hasError = true;
      } else if (!isEditing && students.some(s => s.id === sId)) {
        showFieldError('student-id-error', 'Mã sinh viên này đã tồn tại trong hệ thống.');
        hasError = true;
      }

      if (!fName) {
        showFieldError('student-name-error', 'Vui lòng nhập họ và tên sinh viên.');
        hasError = true;
      } else if (fName.length > 100) {
        showFieldError('student-name-error', 'Họ tên không được vượt quá 100 ký tự.');
        hasError = true;
      }

      if (hasError) return;

      saveBtn.disabled = true;

      try {
        if (isEditing) {
          const index = students.findIndex(s => s.id === editingStudentId);
          if (index !== -1) {
            students[index].fullName = fName;
            students[index].className = cName;
          }
        } else {
          students.push({
            id: sId,
            fullName: fName,
            className: cName,
            createdAt: getTodayISO()
          });
        }

        saveStudents(students);
        closeModal();
        renderStudentsView();
        populateStudentSelect(document.getElementById('fee-student-select'));
      } finally {
        saveBtn.disabled = false;
      }
    });

    // Bắt sự kiện Sửa/Xóa từ bảng sinh viên
    document.getElementById('students-table-body').addEventListener('click', (e) => {
      const editBtn = e.target.closest('.btn-edit-student');
      const delBtn = e.target.closest('.btn-delete-student');

      if (editBtn) {
        const student = getStudents().find(s => s.id === editBtn.dataset.id);
        if (student) openModal(student);
      } else if (delBtn) {
        const id = delBtn.dataset.id;
        const student = getStudents().find(s => s.id === id);
        if (!student) return;

        // ==========================================
        // QUY TẮC CHẶN XÓA SINH VIÊN BẮT BUỘC:
        // "Không thể xóa sinh viên đã có dữ liệu học phí."
        // ==========================================
        const hasFee = getFees().some(f => f.studentId === id);
        const hasTx = getTransactions().some(t => t.studentId === id);

        if (hasFee || hasTx) {
          showConfirmDialog(
            'Không thể xóa sinh viên',
            'Không thể xóa sinh viên đã có dữ liệu học phí.',
            () => {} // Chỉ thông báo, không xóa
          );
          return;
        }

        showConfirmDialog(
          'Xóa sinh viên',
          `Bạn có chắc chắn muốn xóa sinh viên ${student.fullName} (${student.id})?`,
          () => {
            const newStudents = getStudents().filter(s => s.id !== id);
            saveStudents(newStudents);
            renderStudentsView();
            populateStudentSelect(document.getElementById('fee-student-select'));
          }
        );
      }
    });

    document.getElementById('student-search-input').addEventListener('input', renderStudentsView);
  }

  /* ==========================================================================
     13. MODULE 6: TRA CỨU LỊCH SỬ GIAO DỊCH & SỔ CÁI (SEARCH & LEDGER)
     ========================================================================== */
  function renderSearchView() {
    const tbody = document.getElementById('ledger-table-body');
    const keyword = normalizeVietnamese(document.getElementById('ledger-search-keyword').value);
    const filterSem = document.getElementById('ledger-filter-semester').value;
    const fromDate = document.getElementById('ledger-filter-from-date').value;
    const toDate = document.getElementById('ledger-filter-to-date').value;
    const filterStatus = document.getElementById('ledger-filter-status').value;

    const txs = getTransactions();
    const fees = getFees();
    const students = getStudents();

    const studentMap = {};
    students.forEach(s => { studentMap[s.id] = s; });

    const feeMap = {};
    fees.forEach(f => { feeMap[f.id] = f; });

    // Sắp xếp theo ngày tăng dần để tính running balance (hoặc hiển thị đúng dòng thời gian)
    const sortedChronological = [...txs].sort((a, b) => new Date(a.paymentDate) - new Date(b.paymentDate));

    // Lọc theo điều kiện
    let results = sortedChronological.filter(tx => {
      const student = studentMap[tx.studentId] || { fullName: '' };
      const fee = feeMap[tx.feeRecordId];

      if (keyword) {
        const idMatch = normalizeVietnamese(tx.studentId).includes(keyword);
        const nameMatch = normalizeVietnamese(student.fullName).includes(keyword);
        const semMatch = normalizeVietnamese(tx.semester).includes(keyword);
        const rcMatch = normalizeVietnamese(tx.receiptNumber || '').includes(keyword);
        if (!idMatch && !nameMatch && !semMatch && !rcMatch) return false;
      }

      if (filterSem !== 'ALL' && tx.semester !== filterSem) return false;
      if (fromDate && tx.paymentDate < fromDate) return false;
      if (toDate && tx.paymentDate > toDate) return false;

      if (filterStatus !== 'ALL' && fee) {
        const b = calculateFeeBalances(fee, txs);
        const status = derivePaymentStatus(b.totalPaid, b.netDue);
        if (status !== filterStatus) return false;
      }

      return true;
    });

    if (results.length === 0) {
      tbody.innerHTML = `<tr><td colspan="10" class="empty-state">Không tìm thấy giao dịch nào phù hợp với điều kiện tra cứu.</td></tr>`;
      return;
    }

    // Hiển thị mới nhất trước
    results.reverse();

    tbody.innerHTML = results.map(tx => {
      const student = studentMap[tx.studentId] || { fullName: 'Không rõ' };
      const fee = feeMap[tx.feeRecordId];
      const balances = fee ? calculateFeeBalances(fee, txs) : { outstandingBalance: 0 };

      return `
        <tr>
          <td>${formatDateVN(tx.paymentDate)}</td>
          <td><strong><code>${escapeHtml(tx.receiptNumber || tx.id)}</code></strong></td>
          <td><code>${escapeHtml(tx.studentId)}</code></td>
          <td><strong>${escapeHtml(student.fullName)}</strong></td>
          <td>${escapeHtml(tx.semester)}</td>
          <td>
            <span class="badge-tag ${tx.type === TX_TYPES.PAYMENT ? 'kpi-green' : (tx.type === TX_TYPES.REFUND ? 'kpi-amber' : 'kpi-blue')}">
              ${TX_TYPE_LABELS[tx.type] || tx.type}
            </span>
          </td>
          <td class="text-right"><strong>${formatVND(tx.amount)}</strong></td>
          <td>${escapeHtml(tx.method || '-')}</td>
          <td class="text-right">
            <strong>${formatVND(balances.outstandingBalance)}</strong>
          </td>
          <td class="text-center">
            ${tx.type === TX_TYPES.PAYMENT ? `
              <button type="button" class="btn btn-sm btn-outline-primary btn-print-receipt" data-id="${escapeHtml(tx.id)}">🖨️ In</button>
            ` : '-'}
          </td>
        </tr>
      `;
    }).join('');
  }

  function setupSearchEvents() {
    const kwInp = document.getElementById('ledger-search-keyword');
    const semSelect = document.getElementById('ledger-filter-semester');
    const fromInp = document.getElementById('ledger-filter-from-date');
    const toInp = document.getElementById('ledger-filter-to-date');
    const stSelect = document.getElementById('ledger-filter-status');
    const resetBtn = document.getElementById('btn-reset-search');

    kwInp.addEventListener('input', renderSearchView);
    semSelect.addEventListener('change', renderSearchView);
    fromInp.addEventListener('change', renderSearchView);
    toInp.addEventListener('change', renderSearchView);
    stSelect.addEventListener('change', renderSearchView);

    resetBtn.addEventListener('click', () => {
      kwInp.value = '';
      semSelect.value = 'ALL';
      fromInp.value = '';
      toInp.value = '';
      stSelect.value = 'ALL';
      renderSearchView();
    });

    document.getElementById('ledger-table-body').addEventListener('click', (e) => {
      const printBtn = e.target.closest('.btn-print-receipt');
      if (printBtn) {
        openReceiptPrintView(printBtn.dataset.id);
      }
    });
  }

  /* ==========================================================================
     14. MODULE 7: BÁO CÁO THỐNG KÊ & ĐỐI SOÁT CÔNG NỢ (REPORTS & RECONCILIATION)
     ========================================================================== */
  function renderReportsView() {
    const semFilter = document.getElementById('report-semester-filter').value;
    const fees = getFees();
    const txs = getTransactions();
    const students = getStudents();

    const studentMap = {};
    students.forEach(s => { studentMap[s.id] = s; });

    let scopeFees = fees;
    if (semFilter !== 'ALL') {
      scopeFees = fees.filter(f => f.semester === semFilter);
    }

    let totalReceivable = 0; // Tổng phải thu (Net Due)
    let totalCollected = 0;  // Tổng thực thu (Thu - Hoàn tiền hoàn thành)
    let totalOutstanding = 0;// Tổng công nợ còn thiếu
    let totalOverpaid = 0;   // Tổng tiền nộp thừa
    let countOverpaid = 0;

    const statusCounts = {
      [PAYMENT_STATUS.UNPAID]: { count: 0, receivable: 0, collected: 0, outstanding: 0 },
      [PAYMENT_STATUS.PARTIAL]: { count: 0, receivable: 0, collected: 0, outstanding: 0 },
      [PAYMENT_STATUS.PAID]: { count: 0, receivable: 0, collected: 0, outstanding: 0 }
    };

    const feeDetailsList = [];

    scopeFees.forEach(fee => {
      const student = studentMap[fee.studentId] || { fullName: 'Không rõ' };
      const b = calculateFeeBalances(fee, txs);
      const status = derivePaymentStatus(b.totalPaid, b.netDue);

      totalReceivable += b.netDue;
      totalOutstanding += b.outstandingBalance;

      // Tiền thực thu được tính cho đối soát:
      // Trong phạm vi đối soát: collected (hợp lệ) = min(b.totalPaid, b.netDue)
      // Phần vượt b.netDue là overpaid, được tách riêng
      const feeCollected = Math.min(b.totalPaid, b.netDue);
      totalCollected += feeCollected;

      if (b.overpaidAmount > 0) {
        totalOverpaid += b.overpaidAmount;
        countOverpaid++;
      }

      // Thống kê theo 3 trạng thái chính
      const targetBucket = status === PAYMENT_STATUS.OVERPAID ? PAYMENT_STATUS.PAID : status;
      if (statusCounts[targetBucket]) {
        statusCounts[targetBucket].count++;
        statusCounts[targetBucket].receivable += b.netDue;
        statusCounts[targetBucket].collected += feeCollected;
        statusCounts[targetBucket].outstanding += b.outstandingBalance;
      }

      feeDetailsList.push({ fee, student, b, status });
    });

    // Cập nhật thẻ KPI
    document.getElementById('kpi-receivable').textContent = formatVND(totalReceivable);
    document.getElementById('kpi-collected').textContent = formatVND(totalCollected);
    document.getElementById('kpi-outstanding').textContent = formatVND(totalOutstanding);

    // ==========================================
    // ĐỐI SOÁT CÔNG NỢ BẮT BUỘC:
    // collected + outstanding = receivable (loại trừ phần overpaid)
    // ==========================================
    const isReconciled = (totalCollected + totalOutstanding) === totalReceivable;
    const reconStatusEl = document.getElementById('kpi-reconciliation-status');
    const reconHintEl = document.getElementById('kpi-reconciliation-hint');

    if (isReconciled) {
      reconStatusEl.textContent = 'Khớp 100%';
      reconStatusEl.style.color = 'var(--color-success)';
      reconHintEl.textContent = `${formatVND(totalCollected)} + ${formatVND(totalOutstanding)} = ${formatVND(totalReceivable)}`;
    } else {
      reconStatusEl.textContent = 'LỆCH ĐỐI SOÁT';
      reconStatusEl.style.color = 'var(--color-danger)';
      reconHintEl.textContent = `Chênh lệch: ${formatVND(Math.abs((totalCollected + totalOutstanding) - totalReceivable))}`;
    }

    // Hiển thị cảnh báo nộp thừa
    const overpaidAlert = document.getElementById('report-overpaid-alert');
    if (totalOverpaid > 0) {
      document.getElementById('report-overpaid-count').textContent = countOverpaid;
      document.getElementById('report-overpaid-amount').textContent = formatVND(totalOverpaid);
      overpaidAlert.hidden = false;
    } else {
      overpaidAlert.hidden = true;
    }

    // Bảng thống kê theo trạng thái sinh viên
    const breakdownBody = document.getElementById('report-status-breakdown-body');
    const totalStudentsInScope = scopeFees.length;

    breakdownBody.innerHTML = Object.keys(statusCounts).map(stKey => {
      const data = statusCounts[stKey];
      const pct = totalStudentsInScope > 0 ? ((data.count / totalStudentsInScope) * 100).toFixed(1) : '0.0';

      return `
        <tr>
          <td>${renderStatusBadge(stKey)}</td>
          <td class="text-right"><strong>${data.count}</strong> sinh viên</td>
          <td class="text-right">${pct}%</td>
          <td class="text-right">${formatVND(data.receivable)}</td>
          <td class="text-right text-success">${formatVND(data.collected)}</td>
          <td class="text-right ${data.outstanding > 0 ? 'text-danger' : ''}">${formatVND(data.outstanding)}</td>
        </tr>
      `;
    }).join('') + `
      <tr style="background:#f1f5f9; font-weight:700;">
        <td>TỔNG CỘNG ĐỐI SOÁT</td>
        <td class="text-right">${totalStudentsInScope} sinh viên</td>
        <td class="text-right">100.0%</td>
        <td class="text-right">${formatVND(totalReceivable)}</td>
        <td class="text-right text-success">${formatVND(totalCollected)}</td>
        <td class="text-right ${totalOutstanding > 0 ? 'text-danger' : ''}">${formatVND(totalOutstanding)}</td>
      </tr>
    `;

    // Bảng chi tiết sinh viên trong báo cáo
    const detailsBody = document.getElementById('report-fee-records-body');
    if (feeDetailsList.length === 0) {
      detailsBody.innerHTML = `<tr><td colspan="10" class="empty-state">Không có hồ sơ nào trong phạm vi báo cáo này.</td></tr>`;
      return;
    }

    detailsBody.innerHTML = feeDetailsList.map(item => `
      <tr>
        <td><code>${escapeHtml(item.fee.studentId)}</code></td>
        <td><strong>${escapeHtml(item.student.fullName)}</strong></td>
        <td>${escapeHtml(item.fee.semester)}</td>
        <td class="text-right">${item.fee.registeredCredits} TC</td>
        <td class="text-right">${formatVND(item.fee.grossTuition)}</td>
        <td class="text-right">${item.fee.exemption ? formatVND(item.fee.exemption.amount) : '0 ₫'}</td>
        <td class="text-right"><strong>${formatVND(item.b.netDue)}</strong></td>
        <td class="text-right text-success"><strong>${formatVND(item.b.totalPaid)}</strong></td>
        <td class="text-right ${item.b.outstandingBalance > 0 ? 'text-danger' : ''}">
          <strong>${formatVND(item.b.outstandingBalance)}</strong>
        </td>
        <td>${renderStatusBadge(item.status, item.b.overpaidAmount)}</td>
      </tr>
    `).join('');
  }

  /* ==========================================================================
     15. MODULE 8: BIÊN LAI THU TIỀN IN ẤN (PRINTABLE RECEIPT)
     ========================================================================== */
  function openReceiptPrintView(paymentTxId) {
    const tx = getTransactions().find(t => t.id === paymentTxId);
    if (!tx) return;

    const fee = getFees().find(f => f.id === tx.feeRecordId);
    const student = getStudents().find(s => s.id === tx.studentId) || { fullName: 'Không rõ', className: 'Chưa cập nhật' };
    const balances = fee ? calculateFeeBalances(fee, getTransactions()) : { outstandingBalance: 0 };

    document.getElementById('pr-receipt-no').textContent = tx.receiptNumber || tx.id;
    document.getElementById('pr-date').textContent = formatDateVN(tx.paymentDate);
    document.getElementById('pr-student-name').textContent = student.fullName;
    document.getElementById('pr-student-id').textContent = tx.studentId;
    document.getElementById('pr-student-class').textContent = student.className || 'Không có';
    document.getElementById('pr-semester').textContent = tx.semester;
    document.getElementById('pr-method').textContent = tx.method || 'Tiền mặt';
    document.getElementById('pr-admin-name').textContent = tx.adminName || DEMO_ADMIN.displayName;
    document.getElementById('pr-signer-name').textContent = tx.adminName || DEMO_ADMIN.displayName;

    document.getElementById('pr-amount-vnd').textContent = formatVND(tx.amount);
    document.getElementById('pr-amount-words').textContent = numberToVietnameseWords(tx.amount);
    document.getElementById('pr-remaining-balance').textContent = formatVND(balances.outstandingBalance);

    const receiptArea = document.getElementById('receipt-print-area');
    receiptArea.hidden = false;
  }

  function setupReceiptPrintControls() {
    const receiptArea = document.getElementById('receipt-print-area');
    const printBtn = document.getElementById('btn-trigger-print');
    const closeBtn = document.getElementById('btn-close-receipt-view');
    const closeTopBtn = document.getElementById('btn-close-receipt-top');

    function closeReceipt() {
      receiptArea.hidden = true;
    }

    if (printBtn) {
      printBtn.addEventListener('click', () => {
        window.print();
      });
    }

    if (closeBtn) closeBtn.addEventListener('click', closeReceipt);
    if (closeTopBtn) closeTopBtn.addEventListener('click', closeReceipt);

    // Cho phép đóng bằng phím Escape
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !receiptArea.hidden) {
        closeReceipt();
      }
    });

    // Cho phép đóng khi nhấp vào vùng mờ (backdrop) bên ngoài card biên lai
    receiptArea.addEventListener('click', (e) => {
      if (e.target === receiptArea) {
        closeReceipt();
      }
    });
  }

  /* ==========================================================================
     16. TIỆN ÍCH CHUNG (HELPERS & DROPDOWNS)
     ========================================================================== */
  function renderStatusBadge(status, overpaidAmount) {
    if (status === PAYMENT_STATUS.OVERPAID) {
      return `<span class="status-badge status-overpaid">⚠️ Thừa tiền (+${formatVND(overpaidAmount)})</span>`;
    }
    if (status === PAYMENT_STATUS.UNPAID) {
      return `<span class="status-badge status-unpaid">❌ Chưa thanh toán</span>`;
    }
    if (status === PAYMENT_STATUS.PARTIAL) {
      return `<span class="status-badge status-partial">⏳ Thanh toán một phần</span>`;
    }
    if (status === PAYMENT_STATUS.PAID) {
      return `<span class="status-badge status-paid">✅ Đã thanh toán đủ</span>`;
    }
    return `<span class="status-badge">${escapeHtml(status)}</span>`;
  }

  function escapeHtml(str) {
    if (!str) return '';
    return str
      .toString()
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function showFieldError(errorElId, message) {
    const el = document.getElementById(errorElId);
    if (el) el.textContent = message;
  }

  function clearErrors(formEl) {
    if (!formEl) return;
    const errors = formEl.querySelectorAll('.error-msg');
    errors.forEach(e => { e.textContent = ''; });
    const banner = formEl.querySelector('.error-banner');
    if (banner) banner.hidden = true;
  }

  function populateStudentSelect(selectEl) {
    if (!selectEl) return;
    const students = getStudents();
    const currVal = selectEl.value;
    selectEl.innerHTML = '<option value="">-- Chọn sinh viên --</option>';
    students.forEach(s => {
      const opt = document.createElement('option');
      opt.value = s.id;
      opt.textContent = `${s.fullName} (${s.id}) - Lớp: ${s.className || 'Không có'}`;
      if (s.id === currVal) opt.selected = true;
      selectEl.appendChild(opt);
    });
  }

  function populateSemesterDropdowns() {
    const rates = getRates();
    const semDropdowns = [
      document.getElementById('report-semester-filter'),
      document.getElementById('fee-filter-semester'),
      document.getElementById('fee-semester-select'),
      document.getElementById('ledger-filter-semester')
    ];

    semDropdowns.forEach(dd => {
      if (!dd) return;
      const isFeeForm = dd.id === 'fee-semester-select';
      const currVal = dd.value;

      dd.innerHTML = isFeeForm
        ? '<option value="">-- Chọn học kỳ có định mức --</option>'
        : '<option value="ALL">-- Tất cả học kỳ --</option>';

      rates.forEach(r => {
        const opt = document.createElement('option');
        opt.value = r.semester;
        opt.textContent = r.semester;
        if (r.semester === currVal) opt.selected = true;
        dd.appendChild(opt);
      });
    });
  }

  function renderAllViews() {
    populateSemesterDropdowns();
    renderReportsView();
    renderStudentsView();
    renderRatesView();
    renderFeesView();
    renderPaymentsView();
    renderSearchView();
  }

  /* ==========================================================================
     17. KHỞI TẠO SỰ KIỆN TOÀN BỘ ỨNG DỤNG (INITIALIZATION)
     ========================================================================== */
  function setupLoginAndAuth() {
    const loginForm = document.getElementById('login-form');
    const userInp = document.getElementById('login-username');
    const passInp = document.getElementById('login-password');
    const errBanner = document.getElementById('login-general-error');
    const logoutBtn = document.getElementById('btn-logout');

    loginForm.addEventListener('submit', (e) => {
      e.preventDefault();
      clearErrors(loginForm);

      const username = userInp.value.trim();
      const password = passInp.value.trim();

      let hasError = false;

      if (!username) {
        showFieldError('login-username-error', 'Vui lòng nhập tên đăng nhập.');
        hasError = true;
      }
      if (!password) {
        showFieldError('login-password-error', 'Vui lòng nhập mật khẩu.');
        hasError = true;
      }

      if (hasError) return;

      if (username === DEMO_ADMIN.username && password === DEMO_ADMIN.password) {
        setAuthenticated(true);
      } else {
        errBanner.textContent = 'Tên đăng nhập hoặc mật khẩu không chính xác. Vui lòng kiểm tra lại.';
        errBanner.hidden = false;
      }
    });

    logoutBtn.addEventListener('click', () => {
      showConfirmDialog(
        'Đăng xuất hệ thống',
        'Bạn có chắc chắn muốn kết thúc phiên làm việc quản trị?',
        () => {
          setAuthenticated(false);
        }
      );
    });
  }

  function setupTabNavigation() {
    const tabs = document.querySelectorAll('.nav-tabs button[role="tab"]');
    tabs.forEach(btn => {
      btn.addEventListener('click', () => {
        const target = btn.getAttribute('aria-controls');
        switchTab(target);
      });
    });
  }

  function initApp() {
    seedInitialDataIfFirstRun();
    setupConfirmDialogEvents();
    setupLoginAndAuth();
    setupTabNavigation();
    setupStudentModal();
    setupRateModal();
    setupFeeModal();
    setupExemptionEvents();
    setupPaymentModal();
    setupRefundModal();
    setupReversalModal();
    setupReceiptPrintControls();
    setupSearchEvents();

    document.getElementById('report-semester-filter').addEventListener('change', renderReportsView);

    // Kiểm tra trạng thái xác thực
    updateAuthUI();
  }

  // Khởi động khi DOM sẵn sàng
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initApp);
  } else {
    initApp();
  }

  /* ==========================================================================
     18. XUẤT CÁC HÀM TÍNH TOÁN RA WINDOW CHO BỘ KIỂM THỬ (TESTS.JS)
     ========================================================================== */
  window.TuitionCalculations = {
    calculateGross,
    calculateExemption,
    calculateNetDue,
    calculateFeeBalances,
    derivePaymentStatus,
    normalizeVietnamese,
    parseFormattedCurrency,
    numberToVietnameseWords,
    formatVND,
    formatDateVN,
    PAYMENT_STATUS,
    TX_TYPES,
    REFUND_STATUS
  };

})();
