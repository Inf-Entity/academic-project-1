/**
 * ============================================================================
 * MODULE TÍNH TOÁN & ĐỊNH DẠNG HỌC PHÍ (PREVIEW & UTILS)
 * Thuần túy logic tính toán hiển thị cho frontend, tương thích với calc.php backend.
 * ============================================================================
 */

(function () {
  'use strict';

  const PAYMENT_STATUS = {
    UNPAID: 'Chưa thanh toán',
    PARTIAL: 'Thanh toán một phần',
    PAID: 'Đã thanh toán đủ',
    OVERPAID: 'OVERPAID'
  };

  const TX_TYPES = {
    PAYMENT: 'payment',
    REFUND: 'refund',
    REVERSAL: 'reversal'
  };

  const TX_TYPE_LABELS = {
    payment: 'Thu học phí',
    refund: 'Hoàn tiền',
    reversal: 'Đảo mục (Hủy)',
    PAYMENT: 'Thu học phí',
    REFUND: 'Hoàn tiền',
    REVERSAL: 'Đảo mục (Hủy)'
  };

  const REFUND_STATUS = {
    PENDING: 'pending',
    COMPLETED: 'completed',
    CANCELLED: 'cancelled'
  };

  const REFUND_STATUS_LABELS = {
    pending: 'Chờ duyệt',
    completed: 'Đã hoàn tiền',
    cancelled: 'Đã hủy'
  };

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
    if (!type || value === undefined || value === null) return 0;

    const normType = (type === 'percent' || type === 'percentage') ? 'percentage' : 'fixed';

    if (normType === 'percentage') {
      const pct = Math.min(100, Math.max(1, parseFloat(value) || 0));
      return Math.min(g, Math.round((g * pct) / 100));
    }

    if (normType === 'fixed') {
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
   * Tính toán tổng nộp, công nợ còn lại, tiền thừa của một hồ sơ học phí
   */
  function calculateFeeBalances(feeRecord, allTransactions) {
    if (!feeRecord) {
      return { totalPaid: 0, outstandingBalance: 0, overpaidAmount: 0, pendingRefundAmount: 0, netDue: 0, grossTuition: 0 };
    }

    const txs = (allTransactions || []).filter(tx => (tx.feeRecordId === feeRecord.id || tx.fee_record_id === feeRecord.id));

    let paymentSum = 0;
    let paymentReversals = 0;
    let completedRefundSum = 0;
    let refundReversals = 0;
    let pendingRefundSum = 0;

    txs.forEach(tx => {
      const amt = Math.max(0, Math.round(tx.amount || 0));
      const type = (tx.type || tx.entry_type || '').toLowerCase();
      const status = (tx.status || '').toLowerCase();
      const targetType = (tx.targetType || '').toLowerCase();

      if (type === 'payment') {
        paymentSum += amt;
      } else if (type === 'refund') {
        if (status === 'completed' || status === 'đã hoàn tiền') {
          completedRefundSum += amt;
        } else if (status === 'pending' || status === 'chờ duyệt') {
          pendingRefundSum += amt;
        }
      } else if (type === 'reversal') {
        if (targetType === 'refund') {
          refundReversals += amt;
        } else {
          paymentReversals += amt;
        }
      }
    });

    const effectivePaid = Math.max(0, (paymentSum - paymentReversals) - (completedRefundSum - refundReversals));
    const netDue = Math.max(0, Math.round(feeRecord.netDue !== undefined ? feeRecord.netDue : (feeRecord.net_due || 0)));

    const outstandingBalance = Math.max(0, netDue - effectivePaid);
    const overpaidAmount = Math.max(0, effectivePaid - netDue);

    return {
      totalPaid: effectivePaid,
      outstandingBalance,
      overpaidAmount,
      pendingRefundAmount: pendingRefundSum,
      netDue,
      grossTuition: Math.max(0, Math.round(feeRecord.grossTuition !== undefined ? feeRecord.grossTuition : (feeRecord.gross_tuition || 0)))
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
   * Xử lý và chuẩn hóa chuỗi tiền tệ người dùng nhập/dán vào
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
   * Định dạng ngày dd/mm/yyyy từ chuỗi ISO
   */
  function formatDateVN(dateStr) {
    if (!dateStr) return '';
    const parts = dateStr.split(' ')[0].split('-');
    if (parts.length === 3) {
      return `${parts[2]}/${parts[1]}/${parts[0]}`;
    }
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;
    const day = String(d.getDate()).padStart(2, '0');
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const year = d.getFullYear();
    return `${day}/${month}/${year}`;
  }

  /**
   * Lấy ngày hiện tại theo chuẩn YYYY-MM-DD
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
    return words.charAt(0).toUpperCase() + words.slice(1) + ' đồng chẵn';
  }

  // Xuất ra toàn cục cho app và test suite
  window.TuitionCalculations = {
    PAYMENT_STATUS,
    TX_TYPES,
    TX_TYPE_LABELS,
    REFUND_STATUS,
    REFUND_STATUS_LABELS,
    calculateGross,
    calculateExemption,
    calculateNetDue,
    calculateFeeBalances,
    derivePaymentStatus,
    normalizeVietnamese,
    parseFormattedCurrency,
    formatVND,
    formatDateVN,
    getTodayISO,
    numberToVietnameseWords
  };

})();
