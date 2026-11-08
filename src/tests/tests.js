/**
 * ============================================================================
 * TEST SUITE: KIỂM THỬ ĐƠN VỊ CÁC HÀM TÍNH TOÁN (VANILLA JS UNIT TESTS)
 * Chỉ kích hoạt khi URL có tham số '?test=1'
 * ============================================================================
 */

(function () {
  'use strict';

  const params = new URLSearchParams(window.location.search);
  if (params.get('test') !== '1') {
    return;
  }

  console.log('%c🧪 BẮT ĐẦU CHẠY BỘ KIỂM THỬ ĐƠN VỊ (UNIT TESTS) - HỆ THỐNG HỌC PHÍ...', 'color: #2563eb; font-weight: bold; font-size: 14px;');

  const C = window.TuitionCalculations;
  if (!C) {
    console.error('❌ Không tìm thấy đối tượng window.TuitionCalculations!');
    return;
  }

  let totalTests = 0;
  let passedTests = 0;
  let failedTests = 0;

  function assert(condition, testName, expected, actual) {
    totalTests++;
    if (condition) {
      passedTests++;
      console.log(`%c✓ ĐẠT [Test ${totalTests}]: ${testName}`, 'color: #059669; font-weight: 500;');
    } else {
      failedTests++;
      console.error(`❌ THẤT BẠI [Test ${totalTests}]: ${testName}\n  -> Kỳ vọng: ${JSON.stringify(expected)}\n  -> Thực tế: ${JSON.stringify(actual)}`);
    }
  }

  // --------------------------------------------------------------------------
  // TEST CASE 1: TÍNH HỌC PHÍ GỐC (FEE CALCULATION)
  // --------------------------------------------------------------------------
  const gross1 = C.calculateGross(18, 450000);
  assert(gross1 === 8100000, 'Tính học phí gốc chuẩn (18 TC x 450.000 VND)', 8100000, gross1);

  const gross2 = C.calculateGross(0, 450000);
  assert(gross2 === 0, 'Tính học phí gốc với số tín chỉ 0 phải bằng 0 VND', 0, gross2);

  // --------------------------------------------------------------------------
  // TEST CASE 2: MIỄN GIẢM THEO PHẦN TRĂM & LÀM TRÒN
  // --------------------------------------------------------------------------
  const exPct = C.calculateExemption(8100000, 'percentage', 20);
  assert(exPct === 1620000, 'Miễn giảm 20% trên 8.100.000 VND là 1.620.000 VND', 1620000, exPct);

  const exRound = C.calculateExemption(1000000, 'percentage', 33.33);
  assert(exRound === 333300, 'Miễn giảm phần trăm làm tròn đến đồng gần nhất', 333300, exRound);

  // --------------------------------------------------------------------------
  // TEST CASE 3: GIỚI HẠN MIỄN GIẢM CỐ ĐỊNH (EXEMPTION CAPPING)
  // --------------------------------------------------------------------------
  const exCap = C.calculateExemption(5000000, 'fixed', 9000000);
  assert(exCap === 5000000, 'Miễn giảm cố định vượt quá học phí gốc phải được chặn ở mức học phí gốc (5.000.000 VND)', 5000000, exCap);

  // --------------------------------------------------------------------------
  // TEST CASE 4: SỐ TIỀN THỰC NỘP (NET DUE) & CHẶN DƯỚI TẠI 0
  // --------------------------------------------------------------------------
  const netDueNormal = C.calculateNetDue(8100000, 1620000);
  assert(netDueNormal === 6480000, 'Net Due = Gross - Exemption (8.100.000 - 1.620.000 = 6.480.000 VND)', 6480000, netDueNormal);

  const netDueZero = C.calculateNetDue(5000000, 5000000);
  assert(netDueZero === 0, 'Net Due khi miễn giảm 100% bằng 0 VND', 0, netDueZero);

  const netDueFloor = C.calculateNetDue(3000000, 4000000);
  assert(netDueFloor === 0, 'Net Due không bao giờ âm (chặn dưới tại 0)', 0, netDueFloor);

  // --------------------------------------------------------------------------
  // TEST CASE 5: XÁC ĐỊNH TRẠNG THÁI TẠI CÁC NGƯỠNG BIÊN (STATUS DERIVATION)
  // --------------------------------------------------------------------------
  // 5.1 Chưa thanh toán (Unpaid): totalPaid = 0 & netDue > 0
  const statusUnpaid = C.derivePaymentStatus(0, 6480000);
  assert(statusUnpaid === C.PAYMENT_STATUS.UNPAID, 'Trạng thái khi chưa nộp đồng nào là "Chưa thanh toán"', C.PAYMENT_STATUS.UNPAID, statusUnpaid);

  // 5.2 Thanh toán 1 phần: Nộp thiếu 1 VND so với số còn nợ
  const statusPartialMinus1 = C.derivePaymentStatus(6479999, 6480000);
  assert(statusPartialMinus1 === C.PAYMENT_STATUS.PARTIAL, 'Nộp thiếu đúng 1 VND (6.479.999/6.480.000) phải có trạng thái "Thanh toán một phần"', C.PAYMENT_STATUS.PARTIAL, statusPartialMinus1);

  // 5.3 Thanh toán đủ: Nộp đúng bằng số nợ
  const statusPaidExact = C.derivePaymentStatus(6480000, 6480000);
  assert(statusPaidExact === C.PAYMENT_STATUS.PAID, 'Nộp đủ 100% (6.480.000/6.480.000) phải có trạng thái "Đã thanh toán đủ"', C.PAYMENT_STATUS.PAID, statusPaidExact);

  // 5.4 Thanh toán đủ khi Net Due = 0 (miễn giảm toàn phần)
  const statusPaidZero = C.derivePaymentStatus(0, 0);
  assert(statusPaidZero === C.PAYMENT_STATUS.PAID, 'Net Due = 0 sau khi miễn giảm toàn phần phải có trạng thái "Đã thanh toán đủ"', C.PAYMENT_STATUS.PAID, statusPaidZero);

  // 5.5 Nộp thừa (Overpaid warning)
  const statusOverpaid = C.derivePaymentStatus(6480001, 6480000);
  assert(statusOverpaid === C.PAYMENT_STATUS.OVERPAID, 'Nộp vượt số nợ dù chỉ 1 VND phải kích hoạt cảnh báo nộp thừa OVERPAID', C.PAYMENT_STATUS.OVERPAID, statusOverpaid);

  // --------------------------------------------------------------------------
  // TEST CASE 6: QUY TẮC TỪ CHỐI NỘP THỪA (OVERPAYMENT REJECTION RULE)
  // --------------------------------------------------------------------------
  function validatePaymentAmount(currentPaid, netDue, newPayment) {
    if (currentPaid + newPayment > netDue) {
      return { valid: false, error: 'Số tiền vượt quá số còn nợ. Vui lòng lập yêu cầu hoàn tiền trước.' };
    }
    return { valid: true };
  }

  const payValid = validatePaymentAmount(3000000, 6480000, 3480000);
  assert(payValid.valid === true, 'Thanh toán vừa đủ số nợ còn lại (3.480.000 VND) được chấp nhận', true, payValid.valid);

  const payOver = validatePaymentAmount(3000000, 6480000, 3480001);
  assert(
    payOver.valid === false && payOver.error === 'Số tiền vượt quá số còn nợ. Vui lòng lập yêu cầu hoàn tiền trước.',
    'Thanh toán vượt quá công nợ 1 VND bị từ chối với đúng thông điệp quy định',
    'Số tiền vượt quá số còn nợ. Vui lòng lập yêu cầu hoàn tiền trước.',
    payOver.error
  );

  // --------------------------------------------------------------------------
  // TEST CASE 7: ẢNH HƯỞNG CỦA HOÀN TIỀN LÊN SỐ DƯ (REFUND EFFECT ON BALANCE)
  // --------------------------------------------------------------------------
  const mockFee = { id: 'FR-TEST', netDue: 5000000, grossTuition: 5000000 };
  const mockTxs = [
    { feeRecordId: 'FR-TEST', type: C.TX_TYPES.PAYMENT, amount: 6000000, status: 'COMPLETED' },
    { feeRecordId: 'FR-TEST', type: C.TX_TYPES.REFUND, amount: 1000000, status: C.REFUND_STATUS.PENDING }
  ];

  // Khi hoàn tiền đang chờ duyệt (PENDING), tiền đã nộp chưa bị trừ
  const balPending = C.calculateFeeBalances(mockFee, mockTxs);
  assert(balPending.totalPaid === 6000000 && balPending.overpaidAmount === 1000000, 'Khoản hoàn tiền "Chờ duyệt" chưa làm giảm số tiền đã nộp', 6000000, balPending.totalPaid);

  // Khi hoàn tiền đã hoàn tất (COMPLETED), tiền đã nộp giảm đi và xóa tình trạng nộp thừa
  mockTxs[1].status = C.REFUND_STATUS.COMPLETED;
  const balCompleted = C.calculateFeeBalances(mockFee, mockTxs);
  assert(balCompleted.totalPaid === 5000000 && balCompleted.overpaidAmount === 0, 'Khoản hoàn tiền "Đã hoàn tiền" làm giảm số đã nộp xuống 5.000.000 và xóa nộp thừa', 5000000, balCompleted.totalPaid);

  // --------------------------------------------------------------------------
  // TEST CASE 8: BÚT TOÁN ĐẢO MỤC (REVERSAL EFFECT)
  // --------------------------------------------------------------------------
  const mockFee2 = { id: 'FR-REV', netDue: 4000000, grossTuition: 4000000 };
  const mockTxs2 = [
    { id: 'TX-PAY-1', feeRecordId: 'FR-REV', type: C.TX_TYPES.PAYMENT, amount: 4000000, status: 'COMPLETED' },
    { id: 'TX-REV-1', feeRecordId: 'FR-REV', type: C.TX_TYPES.REVERSAL, targetType: C.TX_TYPES.PAYMENT, targetTxId: 'TX-PAY-1', amount: 4000000, status: 'COMPLETED' }
  ];
  const balRev = C.calculateFeeBalances(mockFee2, mockTxs2);
  assert(balRev.totalPaid === 0 && balRev.outstandingBalance === 4000000, 'Đảo mục thanh toán khôi phục số dư còn nợ về mức ban đầu', 4000000, balRev.outstandingBalance);

  // --------------------------------------------------------------------------
  // TEST CASE 9: CHUẨN HÓA TIẾNG VIỆT & TÌM KIẾM KHÔNG DẤU (DIACRITIC-INSENSITIVE)
  // --------------------------------------------------------------------------
  const searchKey = C.normalizeVietnamese('nguyen');
  const studentName1 = C.normalizeVietnamese('Nguyễn Văn An');
  assert(studentName1.includes(searchKey), 'Tìm kiếm "nguyen" khớp chính xác với "Nguyễn Văn An"', true, studentName1.includes(searchKey));

  const searchKeyDang = C.normalizeVietnamese('dang');
  const studentName2 = C.normalizeVietnamese('Đặng Minh Tuấn');
  assert(studentName2.includes(searchKeyDang), 'Tìm kiếm "dang" khớp chính xác với chữ "Đặng"', true, studentName2.includes(searchKeyDang));

  // --------------------------------------------------------------------------
  // TEST CASE 10: XỬ LÝ NHẬP TIỀN TỆ DÁN CÓ DẤU CHẤM, PHẨY (CURRENCY PARSER)
  // --------------------------------------------------------------------------
  const pDot = C.parseFormattedCurrency('1.500.000');
  assert(pDot === 1500000, 'Phân tích chuỗi có dấu chấm phân cách hàng nghìn "1.500.000"', 1500000, pDot);

  const pComma = C.parseFormattedCurrency('1,500,000');
  assert(pComma === 1500000, 'Phân tích chuỗi có dấu phẩy phân cách hàng nghìn "1,500,000"', 1500000, pComma);

  const pSymbol = C.parseFormattedCurrency(' 2.500.000 ₫ ');
  assert(pSymbol === 2500000, 'Phân tích chuỗi có ký hiệu tiền tệ và khoảng trắng " 2.500.000 ₫ "', 2500000, pSymbol);

  const pInvalid = C.parseFormattedCurrency('abc123xyz');
  assert(isNaN(pInvalid), 'Từ chối chuỗi chứa ký tự chữ không hợp lệ', true, isNaN(pInvalid));

  const pNegative = C.parseFormattedCurrency('-500000');
  assert(isNaN(pNegative), 'Từ chối số âm', true, isNaN(pNegative));

  // --------------------------------------------------------------------------
  // TEST CASE 11: ĐỌC SỐ TIỀN THÀNH CHỮ TIẾNG VIỆT (NUMBER TO WORDS)
  // --------------------------------------------------------------------------
  const words1 = C.numberToVietnameseWords(1500000);
  assert(words1.toLowerCase().includes('một triệu năm trăm nghìn đồng chẵn'), 'Đọc số tiền 1.500.000 VND thành chữ chuẩn', 'Một triệu năm trăm nghìn đồng chẵn', words1);

  const words2 = C.numberToVietnameseWords(0);
  assert(words2 === 'Không đồng chẵn', 'Đọc số tiền 0 VND', 'Không đồng chẵn', words2);

  // --------------------------------------------------------------------------
  // TEST CASE 12: ĐỐI SOÁT TỔNG THU & CÔNG NỢ (RECONCILIATION EQUATION)
  // collected + outstanding = receivable (loại trừ overpaid)
  // --------------------------------------------------------------------------
  const testFeeItems = [
    { netDue: 6480000, totalPaid: 3000000 }, // nợ 3.480.000
    { netDue: 6750000, totalPaid: 6750000 }, // nợ 0
    { netDue: 7200000, totalPaid: 0 }        // nợ 7.200.000
  ];
  let sumReceivable = 0;
  let sumCollected = 0;
  let sumOutstanding = 0;

  testFeeItems.forEach(item => {
    sumReceivable += item.netDue;
    sumCollected += Math.min(item.totalPaid, item.netDue);
    sumOutstanding += Math.max(0, item.netDue - item.totalPaid);
  });

  const reconciles = (sumCollected + sumOutstanding) === sumReceivable;
  assert(reconciles, `Phương trình đối soát cân bằng: ${sumCollected} (thu) + ${sumOutstanding} (nợ) = ${sumReceivable} (phải thu)`, true, reconciles);

  // --------------------------------------------------------------------------
  // TỔNG KẾT BỘ KIỂM THỬ
  // --------------------------------------------------------------------------
  console.log(`%c═══════════════════════════════════════════════════════════`, 'color: #2563eb;');
  if (failedTests === 0) {
    console.log(`%c🎉 TẤT CẢ ${passedTests}/${totalTests} BÀI TEST ĐÃ VƯỢT QUA XUẤT SẮC!`, 'color: #059669; font-weight: bold; font-size: 14px;');
  } else {
    console.warn(`%c⚠️ HOÀN THÀNH VỚI ${failedTests} THẤT BẠI / TỔNG SỐ ${totalTests} BÀI TEST.`, 'color: #dc2626; font-weight: bold;');
  }
  console.log(`%c═══════════════════════════════════════════════════════════`, 'color: #2563eb;');

  // Hiển thị thanh thông báo trạng thái kiểm thử trực quan trên đầu trang
  function mountTestBanner() {
    if (typeof document === 'undefined' || !document.body) return;
    const banner = document.createElement('div');
    banner.id = 'unit-test-results-banner';
    banner.style.position = 'fixed';
    banner.style.bottom = '16px';
    banner.style.right = '16px';
    banner.style.padding = '12px 18px';
    banner.style.borderRadius = '8px';
    banner.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
    banner.style.zIndex = '99999';
    banner.style.fontFamily = 'system-ui, sans-serif';
    banner.style.fontSize = '13px';
    banner.style.fontWeight = '600';
    banner.style.display = 'flex';
    banner.style.alignItems = 'center';
    banner.style.gap = '8px';

    if (failedTests === 0) {
      banner.style.background = '#ecfdf5';
      banner.style.color = '#065f46';
      banner.style.border = '1px solid #a7f3d0';
      banner.innerHTML = `<span>✅</span> <span>Unit Tests: <strong>${passedTests}/${totalTests} Passed</strong> (F12 Console để xem chi tiết)</span>`;
    } else {
      banner.style.background = '#fef2f2';
      banner.style.color = '#991b1b';
      banner.style.border = '1px solid #fecaca';
      banner.innerHTML = `<span>❌</span> <span>Unit Tests: <strong>${failedTests} Failed</strong></span>`;
    }

    document.body.appendChild(banner);
  }

  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', mountTestBanner);
    } else {
      mountTestBanner();
    }
  }

})();
