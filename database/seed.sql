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

DELETE FROM `students`;
ALTER TABLE `students` AUTO_INCREMENT = 1;

DELETE FROM `semesters`;
ALTER TABLE `semesters` AUTO_INCREMENT = 1;

DELETE FROM `users`;
ALTER TABLE `users` AUTO_INCREMENT = 1;

-- 1. Học kỳ (Semesters)
-- ID 1: HK1 2025-2026
-- ID 2: HK2 2025-2026
-- ID 3: HK1 2026-2027
INSERT INTO `semesters` (`id`, `term_no`, `academic_year_start`, `created_at`) VALUES
(1, 1, 2025, '2025-08-15 09:00:00'),
(2, 2, 2025, '2026-01-10 09:00:00'),
(3, 1, 2026, '2026-08-15 09:00:00');

-- 2. Định mức Tín chỉ (Credit Rates)
-- HK1 2025-2026: 800.000 VND
-- HK2 2025-2026: 820.000 VND
-- HK1 2026-2027: 850.000 VND
INSERT INTO `credit_rates` (`id`, `semester_id`, `price_per_credit`, `updated_at`) VALUES
(1, 1, 800000, '2025-08-15 09:00:00'),
(2, 2, 820000, '2026-01-10 09:00:00'),
(3, 3, 850000, '2026-08-15 09:00:00');

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
(7, 'SV007', 'Đặng Quốc Huy', 'dang quoc huy', 'K20-CNTT1', '2025-08-20 08:30:00', '2025-08-20 08:30:00');

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
(12, 7, 3, 12, 850000, 10200000, '2026-09-20 08:50:00', '2026-09-20 08:50:00');

-- 5. Miễn giảm Học phí (Exemptions)
-- Record 1 (SV001 HK1 26-27): 10% (1.275.000)
-- Record 3 (SV003 HK1 26-27): fixed 2.000.000
-- Record 5 (SV001 HK2 25-26): fixed 1.000.000 (sau nộp -> overpaid)
-- Record 6 (SV002 HK2 25-26): 20% (2.624.000)
-- Record 8 (SV003 HK1 25-26): fixed 1.600.000
-- Record 9 (SV006 HK1 25-26): 100% (7.200.000 -> net 0)
-- Record 11 (SV007 HK2 25-26): fixed 700.000 (sau nộp -> overpaid 700.000)
INSERT INTO `exemptions` (`id`, `fee_record_id`, `type`, `value`, `reason`, `created_by`, `updated_at`) VALUES
(1, 1, 'percent', 10, 'Học bổng khuyến khích học tập', 1, '2026-09-22 09:00:00'),
(2, 3, 'fixed', 2000000, 'Diện hộ nghèo', 1, '2026-09-22 09:10:00'),
(3, 5, 'fixed', 1000000, 'Điều chỉnh miễn giảm sau thanh toán', 1, '2026-09-11 14:00:00'),
(4, 6, 'percent', 20, 'Học bổng doanh nghiệp', 1, '2026-02-18 09:00:00'),
(5, 8, 'fixed', 1600000, 'Hỗ trợ học phí kỳ 1', 1, '2025-09-02 10:00:00'),
(6, 9, 'percent', 100, 'Học bổng toàn phần 100%', 1, '2025-08-28 09:00:00'),
(7, 11, 'fixed', 700000, 'Điều chỉnh sau thanh toán', 1, '2026-09-16 10:00:00');

-- 6. Sổ cái giao dịch (Ledger Entries)
-- Bao gồm đúng 11 giao dịch trải dài 2 tháng (Tháng 9 & 10 năm 2026), 3 phương thức thanh toán
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
(11, 11, 'payment', 8200000, 'Thẻ', '2026-09-16', 'PT-20260916-0001', NULL, NULL, NULL, 'Thu học phí HK2 SV007', 'a1111111-1111-1111-1111-111111111111', 1, '2026-09-16 09:30:00');

-- 7. Yêu cầu Hoàn trả (Refund Requests)
-- Pending refund for record 5 (SV001 HK2): 1.000.000
-- Completed refund for record 8 (SV003 HK1): 1.600.000
-- Cancelled refund for record 10 (SV002 HK1): 500.000
INSERT INTO `refund_requests` (`id`, `fee_record_id`, `amount`, `reason`, `status`, `request_id`, `created_by`, `created_at`, `completed_by`, `completed_at`, `cancelled_by`, `cancelled_at`, `cancel_reason`) VALUES
(1, 5, 1000000, 'Điều chỉnh miễn giảm sau thanh toán', 'pending', 'b1111111-1111-1111-1111-111111111101', 1, '2026-09-11 14:30:00', NULL, NULL, NULL, NULL, NULL),
(2, 8, 1600000, 'Hoàn tiền miễn giảm bổ sung', 'completed', 'b1111111-1111-1111-1111-111111111102', 1, '2025-09-02 10:30:00', 1, '2025-09-05 10:00:00', NULL, NULL, NULL),
(3, 10, 500000, 'Tạo nhầm', 'cancelled', 'b1111111-1111-1111-1111-111111111103', 1, '2025-09-06 09:00:00', NULL, NULL, 1, '2025-09-06 09:30:00', 'Nhầm lẫn thao tác');

-- 8. Bộ đếm Số biên lai theo ngày (Receipt Counters)
-- Tương ứng với số biên lai lớn nhất đã phát hành trong mỗi ngày
INSERT INTO `receipt_counters` (`day`, `last_no`) VALUES
('2026-09-01', 1),
('2026-09-05', 2),
('2026-09-10', 1),
('2026-09-12', 1),
('2026-09-15', 1),
('2026-09-16', 1),
('2026-10-01', 1),
('2026-10-02', 1),
('2026-10-03', 1);

-- 9. Nhật ký Kiểm toán ban đầu (Audit Log)
INSERT INTO `audit_log` (`user_id`, `action`, `entity`, `entity_id`, `payload`, `ip`, `created_at`) VALUES
(1, 'seed_init', 'system', '0', '{"info": "Khởi tạo dữ liệu mẫu hệ thống"}', '127.0.0.1', '2026-08-01 08:00:00');

-- 10. Tài khoản Quản trị viên (Users)
INSERT INTO `users` (`id`, `username`, `password_hash`, `full_name`, `role`, `failed_logins`, `locked_until`, `created_at`) VALUES
(1, 'admin', '$2y$12$WDDv3BxKcciz9/102tWyPew3Qf6xlwna4PqzlcxODAzGGlaNOS5di', 'Quản trị viên Admin', 'admin', 0, NULL, '2026-08-15 08:00:00'),
(2, 'staff01', '$2y$12$6Bqyi/l2k1lGDHWX3a0LOeS2.kS58BNx2OYM1zLmZX90jtuXUToU2', 'Cán bộ Staff 01', 'admin', 0, NULL, '2026-08-15 08:00:00');

SET FOREIGN_KEY_CHECKS = 1;
