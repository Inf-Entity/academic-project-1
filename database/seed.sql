-- ============================================================================
-- Dữ liệu Mẫu Chuẩn (Deterministic Sample Seed Data)
-- Hệ thống Quản lý Học phí Sinh viên
--
-- Tài khoản quản trị viên mặc định:
--   - admin / Admin@123
--   - staff01 / Staff@123
-- (Mật khẩu đã băm Bcrypt, có thể khởi tạo lại bất kỳ lúc nào bằng: php database/seed_users.php)
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Xóa dữ liệu cũ theo thứ tự bảng con trước (Child-first order) và đặt lại AUTO_INCREMENT
DELETE FROM `user_nav_state`;

DELETE FROM `bills`;
ALTER TABLE `bills` AUTO_INCREMENT = 1;

DELETE FROM `audit_log`;
ALTER TABLE `audit_log` AUTO_INCREMENT = 1;

DELETE FROM `bill_counters`;
DELETE FROM `receipt_counters`;

DELETE FROM `payment_sessions`;
ALTER TABLE `payment_sessions` AUTO_INCREMENT = 1;

DELETE FROM `audit_log`;
ALTER TABLE `audit_log` AUTO_INCREMENT = 1;

DELETE FROM `receipt_counters`;

DELETE FROM `refund_requests`;
ALTER TABLE `refund_requests` AUTO_INCREMENT = 1;

DELETE FROM `ledger_entries`;
ALTER TABLE `ledger_entries` AUTO_INCREMENT = 1;

DELETE FROM `exemptions`;
ALTER TABLE `exemptions` AUTO_INCREMENT = 1;

DELETE FROM `fee_records`;
ALTER TABLE `fee_records` AUTO_INCREMENT = 1;

DELETE FROM `credit_rates`;
ALTER TABLE `credit_rates` AUTO_INCREMENT = 1;

DELETE FROM `student_settings`;

DELETE FROM `users`;
ALTER TABLE `users` AUTO_INCREMENT = 1;

DELETE FROM `students`;
ALTER TABLE `students` AUTO_INCREMENT = 1;

DELETE FROM `semesters`;
ALTER TABLE `semesters` AUTO_INCREMENT = 1;

-- 1. Học kỳ (Semesters)
-- ID 1: HK1 2025-2026 (closed)
-- ID 2: HK2 2025-2026 (closed)
-- ID 3: HK1 2026-2027 (registration_open = 1)
INSERT INTO `semesters` (`id`, `term_no`, `academic_year_start`, `registration_open`, `registration_close`, `created_at`) VALUES
(1, 1, 2025, 0, NULL, '2025-08-15 09:00:00'),
(2, 2, 2025, 0, NULL, '2026-01-10 09:00:00'),
(3, 1, 2026, 1, NULL, '2026-08-15 09:00:00'),
(4, 2, 2026, 0, '2027-02-15 17:00:00', '2026-11-01 08:00:00');

-- 1b. Cấu hình Đăng ký Tín chỉ (Student Settings)
INSERT INTO `student_settings` (`id`, `min_credits_per_semester`, `max_credits_per_semester`) VALUES
(1, 1, 30);

-- 2. Định mức Tín chỉ (Credit Rates)
-- HK1 2025-2026: 800.000 VND
-- HK2 2025-2026: 820.000 VND
-- HK1 2026-2027: 850.000 VND
INSERT INTO `credit_rates` (`id`, `semester_id`, `price_per_credit`, `updated_at`) VALUES
(1, 1, 800000, '2025-08-15 09:00:00'),
(2, 2, 820000, '2026-01-10 09:00:00'),
(3, 3, 850000, '2026-08-15 09:00:00'),
(4, 4, 900000, '2026-11-01 08:00:00');

-- 3. Danh sách Sinh viên (Students)
-- SV004 không có hồ sơ học phí (deletable)
-- SV005 để test tìm kiếm không dấu "nguyen" -> SV001 & SV005
-- SV006 có class NULL (test trường tùy chọn)
INSERT INTO `students` (`id`, `student_code`, `full_name`, `name_search`, `class_name`, `created_at`, `updated_at`) VALUES
(1, 'SV001', 'Nguyễn Văn An', 'nguyen van an', 'K20-CNTT1', '2025-08-20 08:00:00', '2025-08-20 08:00:00'),
(2, 'SV002', 'Trần Thị Bình', 'tran thi binh', 'K20-CNTT1', '2025-08-20 08:05:00', '2025-08-20 08:05:00'),
(3, 'SV003', 'Lê Hoàng Cường', 'le hoang cuong', 'K21-KT2', '2025-08-20 08:10:00', '2025-08-20 08:10:00'),
(4, 'SV004', 'Phạm Thị Dung', 'pham thi dung', 'K21-KT2', '2025-08-20 08:15:00', '2025-08-20 08:15:00'),
(5, 'SV005', 'Nguyễn Thị Hòa', 'nguyen thi hoa', 'K20-CNTT2', '2025-08-20 08:20:00', '2025-08-20 08:20:00'),
(6, 'SV006', 'Võ Minh Đức', 'vo minh duc', NULL, '2025-08-20 08:25:00', '2025-08-25 08:25:00'),
(7, 'SV007', 'Đặng Quốc Huy', 'dang quoc huy', 'K20-CNTT1', '2025-08-20 08:30:00', '2025-08-20 08:30:00'),
(8, 'SV008', 'Lý Thị Mỹ Linh', 'ly thi my linh', 'K22-QTKD1', '2025-08-20 08:35:00', '2025-08-20 08:35:00'),
(9, 'SV009', 'Đoàn Văn Ơn Ích', 'doan van on ich don van on ich', 'K22-QTKD1', '2025-08-20 08:40:00', '2025-08-20 08:40:00'),
(10, 'SV010', 'Trương Thị Ánh Tuyết Hoàng Thị Hồng Loan', 'truong thi anh tuyet hoang thi hong loan', 'K22-QTKD1', '2025-08-20 08:45:00', '2025-08-20 08:45:00'),
(11, 'SV011', 'Ngô Quang Khải', 'ngo quang khai', NULL, '2025-08-20 08:50:00', '2025-08-20 08:50:00'),
(12, 'SV012', 'Bùi Hữu Thắng', 'bui huu thang', 'K21-CNTT', '2025-08-20 08:55:00', '2025-08-20 08:55:00'),
(13, 'sv013', 'Hoàng Minh Tuấn', 'hoang minh tuan', 'K22-CNTT', '2025-08-20 09:00:00', '2025-08-20 09:00:00'),
(14, 'SV014', 'Nguyễn Hoàng Long', 'nguyen hoang long', 'K22-CNTT1', '2026-11-05 08:00:00', '2026-11-05 08:00:00'),
(15, 'SV015', 'Nguyễn Hoàng Long', 'nguyen hoang long', 'K22-QTKD2', '2026-11-05 08:05:00', '2026-11-05 08:05:00'),
(16, 'SV016', 'Tạ Thị Thuỷ Triều Ơn Ứng', 'ta thi thuy trieu on ung', 'K23-CNTT', '2026-11-05 08:10:00', '2026-11-05 08:10:00'),
(17, 'SV017', 'Nguyễn Trần Lê Hoàng Vũ Đặng Bùi Đỗ Hồ Ngô Dương Lý Phạm Phan Đặng Mai Lan Phương Thảo Ánh Tuyết Anh', 'nguyen tran le hoang vu dang bui do ho ngo duong ly pham phan dang mai lan phuong thao anh tuyet anh', 'K23-CNTT', '2026-11-05 08:15:00', '2026-11-05 08:15:00'),
(18, 'SV018', 'Đặng Quốc Tuấn', 'dang quoc tuan', 'K23-KT1', '2026-11-05 08:20:00', '2026-11-05 08:20:00'),
(19, 'SV019', 'Phan Văn Phú', 'phan van phu', 'K23-CNTT', '2026-11-05 08:25:00', '2026-11-05 08:25:00'),
(20, 'SV020', 'Trần Văn Hưng', 'tran van hung', 'K23-CNTT', '2026-11-05 08:30:00', '2026-11-05 08:30:00'),
(21, 'SV021', 'Lê Thị Thu', 'le thi thu', 'K23-QTKD1', '2026-11-05 08:35:00', '2026-11-05 08:35:00'),
(22, 'SV022', 'Vũ Đình Trọng', 'vu dinh trong', 'K23-KT2', '2026-11-05 08:40:00', '2026-11-05 08:40:00'),
(23, 'SV023', 'Đỗ Mai Trang', 'do mai trang', 'K23-QTKD2', '2026-11-05 08:45:00', '2026-11-05 08:45:00'),
(24, 'SV024', 'Bùi Đức Mạnh', 'bui duc manh', 'K23-CNTT2', '2026-11-05 08:50:00', '2026-11-05 08:50:00');

-- 4. Hồ sơ Học phí (Fee Records)
-- ID 1: SV001 HK1 2026-2027: 15 TC x 850.000 = 12.750.000
-- ID 2: SV002 HK1 2026-2027: 18 TC x 850.000 = 15.300.000
-- ID 3: SV003 HK1 2026-2027: 12 TC x 850.000 = 10.200.000
-- ID 4: SV005 HK1 2026-2027: 21 TC x 850.000 = 17.850.000
-- ID 5: SV001 HK2 2025-2026: 10 TC x 820.000 = 8.200.000
-- ID 6: SV002 HK2 2025-2026: 16 TC x 820.000 = 13.120.000
-- ID 7: SV005 HK2 2025-2026: 14 TC x 820.000 = 11.480.000
-- ID 8: SV003 HK1 2025-2026: 12 TC x 800.000 = 9.600.000
-- ID 9: SV006 HK1 2025-2026: 9 TC x 800.000 = 7.200.000
-- ID 10: SV002 HK1 2025-2026: 15 TC x 800.000 = 12.000.000
-- ID 11: SV007 HK2 2025-2026: 10 TC x 820.000 = 8.200.000 (Overpaid 700.000)
-- ID 12: SV007 HK1 2026-2027: 12 TC x 850.000 = 10.200.000 (Unpaid)
-- ID 13: SV012 HK1 2025-2026: 10 TC x 800.000 = 8.000.000 (Net Due 4.000.000, 50% exemption)
INSERT INTO `fee_records` (`id`, `student_id`, `semester_id`, `registered_credits`, `rate_snapshot`, `gross_tuition`, `created_at`, `updated_at`) VALUES
(1, 1, 3, 15, 850000, 12750000, '2026-09-20 08:30:00', '2026-09-20 08:30:00'),
(2, 2, 3, 18, 850000, 15300000, '2026-09-20 08:35:00', '2026-09-20 08:35:00'),
(3, 3, 3, 12, 850000, 10200000, '2026-09-20 08:40:00', '2026-09-20 08:40:00'),
(4, 5, 3, 21, 850000, 17850000, '2026-09-20 08:45:00', '2026-09-20 08:45:00'),
(5, 1, 2, 10, 820000, 8200000, '2026-02-15 08:30:00', '2026-02-15 08:30:00'),
(6, 2, 2, 16, 820000, 13120000, '2026-02-15 08:35:00', '2026-02-15 08:35:00'),
(7, 5, 2, 14, 820000, 11480000, '2026-02-15 08:40:00', '2026-02-15 08:40:00'),
(8, 3, 1, 12, 800000, 9600000, '2025-08-25 08:30:00', '2025-08-25 08:30:00'),
(9, 6, 1, 9, 800000, 7200000, '2025-08-25 08:35:00', '2025-08-25 08:35:00'),
(10, 2, 1, 15, 800000, 12000000, '2025-08-25 08:40:00', '2025-08-25 08:40:00'),
(11, 7, 2, 10, 820000, 8200000, '2026-02-15 08:45:00', '2026-02-15 08:45:00'),
(12, 7, 3, 12, 850000, 10200000, '2026-09-20 08:50:00', '2026-09-20 08:50:00'),
(13, 12, 1, 10, 800000, 8000000, '2025-08-25 08:55:00', '2025-08-25 08:55:00'),
(14, 18, 4, 15, 900000, 13500000, '2026-11-10 09:00:00', '2026-11-10 09:00:00'),
(15, 19, 4, 12, 900000, 10800000, '2026-11-10 09:05:00', '2026-11-10 09:05:00'),
(16, 20, 4, 10, 900000, 9000000, '2026-11-10 09:10:00', '2026-11-10 09:10:00'),
(17, 21, 4, 14, 900000, 12600000, '2026-11-10 09:15:00', '2026-11-10 09:15:00'),
(18, 22, 4, 10, 900000, 9000000, '2026-11-10 09:20:00', '2026-11-10 09:20:00'),
(19, 14, 4, 10, 900000, 9000000, '2026-11-10 09:25:00', '2026-11-10 09:25:00'),
(20, 15, 4, 10, 900000, 9000000, '2026-11-10 09:30:00', '2026-11-10 09:30:00'),
(21, 16, 4, 10, 900000, 9000000, '2026-11-10 09:35:00', '2026-11-10 09:35:00'),
(22, 17, 4, 10, 900000, 9000000, '2026-11-10 09:40:00', '2026-11-10 09:40:00');

-- 5. Miễn giảm Học phí (Exemptions)
-- Record 1 (SV001 HK1 26-27): 10% (1.275.000)
-- Record 3 (SV003 HK1 26-27): fixed 2.000.000
-- Record 5 (SV001 HK2 25-26): fixed 1.000.000 (sau nộp -> overpaid)
-- Record 6 (SV002 HK2 25-26): 20% (2.624.000)
-- Record 8 (SV003 HK1 25-26): fixed 1.600.000
-- Record 9 (SV006 HK1 25-26): 100% (7.200.000 -> net 0)
-- Record 11 (SV007 HK2 25-26): fixed 700.000 (sau nộp -> overpaid 700.000)
-- Record 13 (SV012 HK1 25-26): 50% (4.000.000)
INSERT INTO `exemptions` (`id`, `fee_record_id`, `type`, `value`, `reason`, `created_by`, `updated_at`) VALUES
(1, 1, 'percent', 10, 'Học bổng khuyến khích học tập', 1, '2026-09-22 09:00:00'),
(2, 3, 'fixed', 2000000, 'Diện hộ nghèo', 1, '2026-09-22 09:10:00'),
(3, 5, 'fixed', 1000000, 'Điều chỉnh miễn giảm sau thanh toán', 1, '2026-09-11 14:00:00'),
(4, 6, 'percent', 20, 'Học bổng doanh nghiệp', 1, '2026-02-18 09:00:00'),
(5, 8, 'fixed', 1600000, 'Hỗ trợ học phí kỳ 1', 1, '2025-09-02 10:00:00'),
(6, 9, 'percent', 100, 'Học bổng toàn phần 100%', 1, '2025-08-28 09:00:00'),
(7, 11, 'fixed', 700000, 'Điều chỉnh sau thanh toán', 1, '2026-09-16 10:00:00'),
(8, 13, 'percent', 50, 'Hỗ trợ học phí 50%', 1, '2025-08-28 09:30:00'),
(9, 15, 'percent', 100, 'Học bổng toàn phần 100%', 1, '2026-11-20 09:00:00'),
(10, 16, 'fixed', 5000000, 'Miễn giảm chính sách vượt một phần học phí', 1, '2026-11-20 09:30:00');

-- 6. Sổ cái giao dịch (Ledger Entries)
-- Số biên lai theo đúng thứ tự không khoảng cách, reset mỗi ngày
INSERT INTO `ledger_entries` (`id`, `fee_record_id`, `entry_type`, `amount`, `method`, `entry_date`, `receipt_no`, `reverses_entry_id`, `transfer_group_id`, `reason`, `note`, `request_id`, `created_by`, `created_at`) VALUES
(1, 8, 'payment', 9600000, 'Tiền mặt', '2026-09-01', 'PT-20260901-0001', NULL, NULL, NULL, 'Thu học phí HK1 2025-2026', 'a1111111-1111-1111-1111-111111111101', 1, '2026-09-01 08:30:00'),
(2, 10, 'payment', 6000000, 'Chuyển khoản', '2026-09-05', 'PT-20260905-0001', NULL, NULL, NULL, 'Đợt 1 HK1 2025-2026', 'a1111111-1111-1111-1111-111111111102', 2, '2026-09-05 09:15:00'),
(3, 10, 'payment', 6000000, 'Thẻ', '2026-09-05', 'PT-20260905-0002', NULL, NULL, NULL, 'Đợt 2 HK1 2025-2026', 'a1111111-1111-1111-1111-111111111103', 1, '2026-09-05 15:30:00'),
(4, 5, 'payment', 8200000, 'Chuyển khoản', '2026-09-10', 'PT-20260910-0001', NULL, NULL, NULL, 'Nộp học phí HK2', 'a1111111-1111-1111-1111-111111111104', 1, '2026-09-10 10:00:00'),
(5, 6, 'payment', 10496000, 'Tiền mặt', '2026-09-12', 'PT-20260912-0001', NULL, NULL, NULL, 'Nộp học phí HK2 đủ', 'a1111111-1111-1111-1111-111111111105', 2, '2026-09-12 11:20:00'),
(6, 7, 'payment', 5000000, 'Thẻ', '2026-09-15', 'PT-20260915-0001', NULL, NULL, NULL, 'Thu học phí SV005', 'a1111111-1111-1111-1111-111111111106', 1, '2026-09-15 14:00:00'),
(7, 7, 'reversal', 5000000, 'Thẻ', '2026-09-15', NULL, 6, NULL, 'Nhập nhầm sinh viên', 'Đảo mục phiếu PT-20260915-0001', 'a1111111-1111-1111-1111-111111111107', 1, '2026-09-15 14:30:00'),
(8, 1, 'payment', 11475000, 'Tiền mặt', '2026-10-01', 'PT-20261001-0001', NULL, NULL, NULL, 'Thanh toán học phí HK1 2026-2027', 'a1111111-1111-1111-1111-111111111108', 1, '2026-10-01 09:00:00'),
(9, 2, 'payment', 5000000, 'Chuyển khoản', '2026-10-02', 'PT-20261002-0001', NULL, NULL, NULL, 'Tạm ứng học phí HK1 2026-2027', 'a1111111-1111-1111-1111-111111111109', 2, '2026-10-02 10:15:00'),
(10, 4, 'payment', 17849999, 'Thẻ', '2026-10-03', 'PT-20261003-0001', NULL, NULL, NULL, 'Thanh toán học phí thiếu 1 đồng', 'a1111111-1111-1111-1111-111111111110', 1, '2026-10-03 16:45:00'),
(11, 11, 'payment', 8200000, 'Thẻ', '2026-09-16', 'PT-20260916-0001', NULL, NULL, NULL, 'Thu học phí HK2 SV007', 'a1111111-1111-1111-1111-111111111111', 1, '2026-09-16 09:30:00'),
(12, 13, 'payment', 3000000, 'Chuyển khoản', '2026-09-02', 'PT-20260902-0001', NULL, NULL, NULL, 'Thu đợt 1 SV012', 'a1111111-1111-1111-1111-111111111112', 1, '2026-09-02 09:00:00'),
(13, 13, 'payment', 1500000, 'Tiền mặt', '2026-09-25', 'PT-20260925-0001', NULL, NULL, NULL, 'Thu đợt 2 SV012', 'a1111111-1111-1111-1111-111111111113', 1, '2026-09-25 10:00:00'),
(14, 14, 'payment', 3000000, 'Tiền mặt', '2026-11-15', 'PT-20261115-0001', NULL, NULL, NULL, 'Nộp đợt 1 tiền mặt', 'a1111111-1111-1111-1111-111111111114', 1, '2026-11-15 09:00:00'),
(15, 14, 'payment', 4000000, 'Mã QR', '2026-12-05', 'PT-20261205-0001', NULL, NULL, NULL, 'Thanh toán đợt 2 qua QR', 'a1111111-1111-1111-1111-111111111115', NULL, '2026-12-05 10:00:00'),
(16, 14, 'payment', 3500000, 'Thẻ Visa/Mastercard', '2027-01-10', 'PT-20270110-0001', NULL, NULL, NULL, 'Thanh toán đợt 3 qua thẻ', 'a1111111-1111-1111-1111-111111111116', NULL, '2027-01-10 14:15:00'),
(17, 14, 'payment', 3000000, 'Chuyển khoản', '2027-01-25', 'PT-20270125-0001', NULL, NULL, NULL, 'Nộp đợt 4 chuyển khoản hoàn tất', 'a1111111-1111-1111-1111-111111111117', 1, '2027-01-25 11:00:00'),
(18, 16, 'payment', 4000000, 'Mã QR', '2026-12-15', 'PT-20261215-0001', NULL, NULL, NULL, 'Thanh toán học phí còn lại sau miễn giảm qua QR', 'a1111111-1111-1111-1111-111111111118', NULL, '2026-12-15 09:30:00'),
(19, 17, 'payment', 12600000, 'Tiền mặt', '2027-02-20', 'PT-20270220-0001', NULL, NULL, NULL, 'Nộp học phí muộn sau hạn đóng đăng ký', 'a1111111-1111-1111-1111-111111111119', 1, '2027-02-20 10:00:00'),
(20, 18, 'payment', 5000000, 'Tiền mặt', '2026-11-25', 'PT-20261125-0001', NULL, NULL, NULL, 'Thu đợt 1', 'a1111111-1111-1111-1111-111111111120', 1, '2026-11-25 09:00:00'),
(21, 18, 'payment', 2000000, 'Tiền mặt', '2026-11-25', 'PT-20261125-0002', NULL, NULL, NULL, 'Thu nhầm sinh viên', 'a1111111-1111-1111-1111-111111111121', 1, '2026-11-25 09:15:00'),
(22, 18, 'reversal', 2000000, 'Tiền mặt', '2026-11-25', NULL, 21, NULL, 'Nhập nhầm sinh viên', 'Đảo mục phiếu thu nhầm PT-20261125-0002', 'a1111111-1111-1111-1111-111111111122', 1, '2026-11-25 09:30:00'),
(23, 19, 'payment', 9000000, 'Thẻ Visa/Mastercard', '2027-01-15', 'PT-20270115-0001', NULL, NULL, NULL, 'Thanh toán học phí qua thẻ', 'a1111111-1111-1111-1111-111111111123', NULL, '2027-01-15 08:30:00'),
(24, 20, 'payment', 9000000, 'Mã QR', '2027-01-15', 'PT-20270115-0002', NULL, NULL, NULL, 'Thanh toán học phí qua QR', 'a1111111-1111-1111-1111-111111111124', NULL, '2027-01-15 11:00:00'),
(25, 21, 'payment', 9000000, 'Mã QR', '2027-01-20', 'PT-20270120-0001', NULL, NULL, NULL, 'Thanh toán học phí qua QR', 'a1111111-1111-1111-1111-111111111125', NULL, '2027-01-20 09:15:00'),
(26, 22, 'payment', 9000000, 'Mã QR', '2027-01-22', 'PT-20270122-0001', NULL, NULL, NULL, 'Thanh toán học phí qua QR', 'a1111111-1111-1111-1111-111111111126', NULL, '2027-01-22 15:30:00');

-- 7. Yêu cầu Hoàn trả (Refund Requests)
-- Pending refund for record 5 (SV001 HK2): 1.000.000
-- Completed refund for record 8 (SV003 HK1): 1.600.000
-- Cancelled refund for record 10 (SV002 HK1): 500.000
-- Completed refund for record 13 (SV012 HK1): 500.000
INSERT INTO `refund_requests` (`id`, `fee_record_id`, `amount`, `reason`, `status`, `request_id`, `created_by`, `created_at`, `completed_by`, `completed_at`, `cancelled_by`, `cancelled_at`, `cancel_reason`) VALUES
(1, 5, 1000000, 'Điều chỉnh miễn giảm sau thanh toán', 'pending', 'b1111111-1111-1111-1111-111111111101', 1, '2026-09-11 14:30:00', NULL, NULL, NULL, NULL, NULL),
(2, 8, 1600000, 'Hoàn tiền miễn giảm bổ sung', 'completed', 'b1111111-1111-1111-1111-111111111102', 1, '2025-09-02 10:30:00', 1, '2025-09-05 10:00:00', NULL, NULL, NULL),
(3, 10, 500000, 'Tạo nhầm', 'cancelled', 'b1111111-1111-1111-1111-111111111103', 1, '2025-09-06 09:00:00', NULL, NULL, 1, '2025-09-06 09:30:00', 'Nhầm lẫn thao tác'),
(4, 13, 500000, 'Hoàn tiền tạm ứng sau miễn giảm', 'completed', 'b1111111-1111-1111-1111-111111111104', 1, '2026-09-10 09:00:00', 1, '2026-09-12 10:00:00', NULL, NULL, NULL),
(5, 18, 5000000, 'Hoàn tiền học phí do sinh viên rút hồ sơ thôi học', 'completed', 'b1111111-1111-1111-1111-111111111105', 1, '2026-12-01 10:00:00', 1, '2026-12-02 14:00:00', NULL, NULL, NULL);

-- 8. Bộ đếm Số biên lai theo ngày (Receipt Counters)
-- Tương ứng với số biên lai lớn nhất đã phát hành trong mỗi ngày
INSERT INTO `receipt_counters` (`day`, `last_no`) VALUES
('2026-09-01', 1),
('2026-09-02', 1),
('2026-09-05', 2),
('2026-09-10', 1),
('2026-09-12', 1),
('2026-09-15', 1),
('2026-09-16', 1),
('2026-09-25', 1),
('2026-10-01', 1),
('2026-10-02', 1),
('2026-10-03', 1),
('2026-11-15', 1),
('2026-11-25', 2),
('2026-12-05', 1),
('2026-12-15', 1),
('2027-01-10', 1),
('2027-01-15', 2),
('2027-01-20', 1),
('2027-01-22', 1),
('2027-01-25', 1),
('2027-02-20', 1);


-- 8b. Bộ đếm Số hóa đơn theo ngày (Bill Counters)
INSERT INTO `bill_counters` (`day`, `last_no`) VALUES
('2026-12-05', 1),
('2026-12-15', 1),
('2027-01-10', 1),
('2027-01-15', 2),
('2027-01-20', 1),
('2027-01-22', 1);

-- 8c. Hóa đơn Thanh toán Sinh viên (Bills)
INSERT INTO `bills` (`id`, `ledger_entry_id`, `bill_no`, `snapshot`, `created_at`) VALUES
(1, 15, 'HD-20261205-0001', '{"school_name":"[Tên trường]","school_placeholder":"[Tên trường]","student_code":"SV018","full_name":"Đặng Quốc Tuấn","class":"K23-KT1","class_name":"K23-KT1","semester_label":"Học kỳ 2 - Năm học 2026-2027","registered_credits":15,"gross_tuition":13500000,"exemption_amount":0,"exemption_reason":null,"net_due":13500000,"total_paid_before":3000000,"this_payment_amount":4000000,"amount":4000000,"total_paid_after":7000000,"remaining_balance":6500000,"method_label":"Mã QR","method":"Mã QR","gateway_brand":null,"gateway_last4":null,"receipt_number":"PT-20261205-0001","receipt_no":"PT-20261205-0001","payment_timestamp":"2026-12-05 10:00:00"}', '2026-12-05 10:00:00'),
(2, 16, 'HD-20270110-0001', '{"school_name":"[Tên trường]","school_placeholder":"[Tên trường]","student_code":"SV018","full_name":"Đặng Quốc Tuấn","class":"K23-KT1","class_name":"K23-KT1","semester_label":"Học kỳ 2 - Năm học 2026-2027","registered_credits":15,"gross_tuition":13500000,"exemption_amount":0,"exemption_reason":null,"net_due":13500000,"total_paid_before":7000000,"this_payment_amount":3500000,"amount":3500000,"total_paid_after":10500000,"remaining_balance":3000000,"method_label":"Thẻ Visa\/Mastercard","method":"Thẻ Visa\/Mastercard","gateway_brand":"Visa","gateway_last4":"1111","receipt_number":"PT-20270110-0001","receipt_no":"PT-20270110-0001","payment_timestamp":"2027-01-10 14:15:00"}', '2027-01-10 14:15:00'),
(3, 18, 'HD-20261215-0001', '{"school_name":"[Tên trường]","school_placeholder":"[Tên trường]","student_code":"SV020","full_name":"Trần Văn Hưng","class":"K23-CNTT","class_name":"K23-CNTT","semester_label":"Học kỳ 2 - Năm học 2026-2027","registered_credits":10,"gross_tuition":9000000,"exemption_amount":5000000,"exemption_reason":"Miễn giảm chính sách vượt một phần học phí","net_due":4000000,"total_paid_before":0,"this_payment_amount":4000000,"amount":4000000,"total_paid_after":4000000,"remaining_balance":0,"method_label":"Mã QR","method":"Mã QR","gateway_brand":null,"gateway_last4":null,"receipt_number":"PT-20261215-0001","receipt_no":"PT-20261215-0001","payment_timestamp":"2026-12-15 09:30:00"}', '2026-12-15 09:30:00'),
(4, 23, 'HD-20270115-0001', '{"school_name":"[Tên trường]","school_placeholder":"[Tên trường]","student_code":"SV014","full_name":"Nguyễn Hoàng Long","class":"K22-CNTT1","class_name":"K22-CNTT1","semester_label":"Học kỳ 2 - Năm học 2026-2027","registered_credits":10,"gross_tuition":9000000,"exemption_amount":0,"exemption_reason":null,"net_due":9000000,"total_paid_before":0,"this_payment_amount":9000000,"amount":9000000,"total_paid_after":9000000,"remaining_balance":0,"method_label":"Thẻ Visa\/Mastercard","method":"Thẻ Visa\/Mastercard","gateway_brand":"Mastercard","gateway_last4":"5555","receipt_number":"PT-20270115-0001","receipt_no":"PT-20270115-0001","payment_timestamp":"2027-01-15 08:30:00"}', '2027-01-15 08:30:00'),
(5, 24, 'HD-20270115-0002', '{"school_name":"[Tên trường]","school_placeholder":"[Tên trường]","student_code":"SV015","full_name":"Nguyễn Hoàng Long","class":"K22-QTKD2","class_name":"K22-QTKD2","semester_label":"Học kỳ 2 - Năm học 2026-2027","registered_credits":10,"gross_tuition":9000000,"exemption_amount":0,"exemption_reason":null,"net_due":9000000,"total_paid_before":0,"this_payment_amount":9000000,"amount":9000000,"total_paid_after":9000000,"remaining_balance":0,"method_label":"Mã QR","method":"Mã QR","gateway_brand":null,"gateway_last4":null,"receipt_number":"PT-20270115-0002","receipt_no":"PT-20270115-0002","payment_timestamp":"2027-01-15 11:00:00"}', '2027-01-15 11:00:00'),
(6, 25, 'HD-20270120-0001', '{"school_name":"[Tên trường]","school_placeholder":"[Tên trường]","student_code":"SV016","full_name":"Tạ Thị Thuỷ Triều Ơn Ứng","class":"K23-CNTT","class_name":"K23-CNTT","semester_label":"Học kỳ 2 - Năm học 2026-2027","registered_credits":10,"gross_tuition":9000000,"exemption_amount":0,"exemption_reason":null,"net_due":9000000,"total_paid_before":0,"this_payment_amount":9000000,"amount":9000000,"total_paid_after":9000000,"remaining_balance":0,"method_label":"Mã QR","method":"Mã QR","gateway_brand":null,"gateway_last4":null,"receipt_number":"PT-20270120-0001","receipt_no":"PT-20270120-0001","payment_timestamp":"2027-01-20 09:15:00"}', '2027-01-20 09:15:00'),
(7, 26, 'HD-20270122-0001', '{"school_name":"[Tên trường]","school_placeholder":"[Tên trường]","student_code":"SV017","full_name":"Nguyễn Trần Lê Hoàng Vũ Đặng Bùi Đỗ Hồ Ngô Dương Lý Phạm Phan Đặng Mai Lan Phương Thảo Ánh Tuyết Anh","class":"K23-CNTT","class_name":"K23-CNTT","semester_label":"Học kỳ 2 - Năm học 2026-2027","registered_credits":10,"gross_tuition":9000000,"exemption_amount":0,"exemption_reason":null,"net_due":9000000,"total_paid_before":0,"this_payment_amount":9000000,"amount":9000000,"total_paid_after":9000000,"remaining_balance":0,"method_label":"Mã QR","method":"Mã QR","gateway_brand":null,"gateway_last4":null,"receipt_number":"PT-20270122-0001","receipt_no":"PT-20270122-0001","payment_timestamp":"2027-01-22 15:30:00"}', '2027-01-22 15:30:00');

-- 9. Nhật ký Kiểm toán ban đầu (Audit Log)
INSERT INTO `audit_log` (`user_id`, `action`, `entity`, `entity_id`, `payload`, `ip`, `created_at`) VALUES
(1, 'seed_init', 'system', '0', '{"info": "Khởi tạo dữ liệu mẫu hệ thống"}', '127.0.0.1', '2026-08-01 08:00:00');

-- 10. Tài khoản Quản trị viên & Sinh viên (Users)
INSERT INTO `users` (`id`, `username`, `password_hash`, `full_name`, `role`, `student_id`, `must_change_password`, `failed_logins`, `locked_until`, `created_at`) VALUES
(1, 'admin', '$2y$12$WDDv3BxKcciz9/102tWyPew3Qf6xlwna4PqzlcxODAzGGlaNOS5di', 'Quản trị viên Admin', 'admin', NULL, 0, 0, NULL, '2026-08-15 08:00:00'),
(2, 'staff01', '$2y$12$6Bqyi/l2k1lGDHWX3a0LOeS2.kS58BNx2OYM1zLmZX90jtuXUToU2', 'Cán bộ Staff 01', 'admin', NULL, 0, 0, NULL, '2026-08-15 08:00:00'),
(3, 'sv001', '$2y$12$fNm91FOriyPX8Vvsawiz/.s/2gzxjmW1UxXCZy04j6X6Syf/rOfHO', 'Nguyễn Văn An', 'student', 1, 0, 0, NULL, '2026-08-20 08:00:00'),
(4, 'sv002', '$2y$12$qgGMn2VxrgWnnF8hQ0eyH.AflhNZscPpLQdXO9lQxtx6VyOWu7lYO', 'Trần Thị Bình', 'student', 2, 0, 0, NULL, '2026-08-20 08:05:00'),
(5, 'sv003', '$2y$12$6VLK7jresNIqWi8tcL701OSMOr2Me83v.//YF.9GCXKzoC4.YgeGS', 'Lê Hoàng Cường', 'student', 3, 0, 0, NULL, '2026-08-20 08:10:00'),
(6, 'sv011', '$2y$12$PYbFLMuHGOY2XkjwLSamYO3WyHHw3UhTfP0x4Kpe7TfBIZgl/Cm3i', 'Ngô Quang Khải', 'student', 11, 0, 0, NULL, '2026-08-20 08:50:00'),
(7, 'sv006', '$2y$12$.p5yi2xNLlTb9nl9FFo60ejbU2TZgdBsC2njfTbOloVBeUHYljrEG', 'Võ Minh Đức', 'student', 6, 1, 0, NULL, '2026-08-20 08:25:00'),
(8, 'sv014', '$2y$12$CUi5ihZqWEF/FlnAk4WH4.IWeMpdq7dXjqsFE6JmKDbRPBs/tAeIS', 'Nguyễn Hoàng Long', 'student', 14, 0, 0, NULL, '2026-11-05 08:00:00'),
(9, 'sv015', '$2y$12$uM5ltAqJCk1YhuqcvZy22eOYjw1UAILTRzILh222dI5tr5T8dlNP.', 'Nguyễn Hoàng Long', 'student', 15, 0, 0, NULL, '2026-11-05 08:05:00'),
(10, 'sv018', '$2y$12$wMczMY/rn2uO5xaRqTvD..lZlYW6nSfaoqiZHJYulhmVM4GpUdkgy', 'Đặng Quốc Tuấn', 'student', 18, 0, 0, NULL, '2026-11-05 08:20:00'),
(11, 'sv020', '$2y$12$YBlrXxLIcmodvICprGcoW.WsOBaThJZ4SBydHhpWHOiap2USZ87Ea', 'Trần Văn Hưng', 'student', 20, 0, 0, NULL, '2026-11-05 08:30:00'),
(12, 'sv023', '$2y$12$6WdIi1sLxfrM7SBgUtlDVOZrwIWAUz4WACeVDceyfxP1atQv0vBrS', 'Đỗ Mai Trang', 'student', 23, 1, 0, NULL, '2026-11-05 08:45:00'),
(13, 'sv024', '$2y$12$TvdWGyxXukMzMK2J.zoZRed4hXJmeW2WEt0YcO9eTh7oK8kwLP9he', 'Bùi Đức Mạnh', 'student', 24, 0, 5, '2030-01-01 00:00:00', '2026-11-05 08:50:00');

SET FOREIGN_KEY_CHECKS = 1;
