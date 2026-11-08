-- ============================================================================
-- Hệ thống Quản lý Học phí Sinh viên - CSDL Hoàn chỉnh (Schema & Seed Data)
-- Database: MariaDB / MySQL 8.0+, InnoDB, utf8mb4_unicode_ci
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- XÓA BẢNG CŨ (Child-first order)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `audit_log`;
DROP TABLE IF EXISTS `receipt_counters`;
DROP TABLE IF EXISTS `refund_requests`;
DROP TABLE IF EXISTS `ledger_entries`;
DROP TABLE IF EXISTS `exemptions`;
DROP TABLE IF EXISTS `fee_records`;
DROP TABLE IF EXISTS `credit_rates`;
DROP TABLE IF EXISTS `students`;
DROP TABLE IF EXISTS `semesters`;
DROP TABLE IF EXISTS `users`;

-- ----------------------------------------------------------------------------
-- TẠO CẤU TRÚC BẢNG (Parent-first order)
-- ----------------------------------------------------------------------------

-- 1. Bảng Học kỳ (Semesters)
CREATE TABLE `semesters` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `term_no` TINYINT NOT NULL,
    `academic_year_start` SMALLINT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_semester` (`term_no`, `academic_year_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Bảng Sinh viên (Students)
CREATE TABLE `students` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_code` VARCHAR(12) NOT NULL UNIQUE,
    `full_name` VARCHAR(100) NOT NULL,
    `name_search` VARCHAR(255) NOT NULL,
    `class_name` VARCHAR(50) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_students_name_search` (`name_search`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Bảng Quản trị viên & Phân quyền (Users)
CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `role` ENUM('admin') NOT NULL DEFAULT 'admin',
    `failed_logins` INT NOT NULL DEFAULT 0,
    `locked_until` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Bảng Định mức Tín chỉ (Credit Rates)
CREATE TABLE `credit_rates` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `semester_id` INT NOT NULL UNIQUE,
    `price_per_credit` BIGINT NOT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_credit_rates_semesters` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `chk_rate_price` CHECK (`price_per_credit` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Bảng Hồ sơ Học phí (Fee Records)
CREATE TABLE `fee_records` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `semester_id` INT NOT NULL,
    `registered_credits` SMALLINT NOT NULL,
    `rate_snapshot` BIGINT NOT NULL,
    `gross_tuition` BIGINT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_fee_student_semester` (`student_id`, `semester_id`),
    CONSTRAINT `fk_fee_records_students` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_fee_records_semesters` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `chk_fee_credits` CHECK (`registered_credits` > 0),
    CONSTRAINT `chk_fee_snapshot` CHECK (`rate_snapshot` > 0),
    CONSTRAINT `chk_fee_gross` CHECK (`gross_tuition` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Bảng Miễn giảm học phí (Exemptions)
CREATE TABLE `exemptions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `fee_record_id` INT NOT NULL UNIQUE,
    `type` ENUM('percent', 'fixed') NOT NULL,
    `value` BIGINT NOT NULL,
    `reason` VARCHAR(200) NOT NULL,
    `created_by` INT NOT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_exemptions_fee_records` FOREIGN KEY (`fee_record_id`) REFERENCES `fee_records` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_exemptions_users` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `chk_exemption_value` CHECK ((`type` = 'percent' AND `value` BETWEEN 1 AND 100) OR (`type` = 'fixed' AND `value` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Sổ cái giao dịch Thu / Đảo mục (Append-only Ledger Entries)
CREATE TABLE `ledger_entries` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `fee_record_id` INT NOT NULL,
    `entry_type` ENUM('payment', 'reversal') NOT NULL,
    `amount` BIGINT NOT NULL,
    `method` ENUM('Tiền mặt', 'Chuyển khoản', 'Thẻ') NOT NULL,
    `entry_date` DATE NOT NULL,
    `receipt_no` VARCHAR(20) UNIQUE NULL,
    `reverses_entry_id` INT NULL UNIQUE,
    `reason` VARCHAR(200) NULL,
    `note` VARCHAR(255) NULL,
    `request_id` CHAR(36) NOT NULL UNIQUE,
    `created_by` INT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_ledger_entries_fee_records` FOREIGN KEY (`fee_record_id`) REFERENCES `fee_records` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_ledger_entries_ledger_entries` FOREIGN KEY (`reverses_entry_id`) REFERENCES `ledger_entries` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_ledger_entries_users` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `chk_ledger_amount` CHECK (`amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Bảng Yêu cầu Hoàn trả tiền thừa (Refund Requests)
CREATE TABLE `refund_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `fee_record_id` INT NOT NULL,
    `amount` BIGINT NOT NULL,
    `reason` VARCHAR(200) NOT NULL,
    `status` ENUM('pending', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    `request_id` CHAR(36) NOT NULL UNIQUE,
    `created_by` INT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_by` INT NULL,
    `completed_at` DATETIME NULL,
    `cancelled_by` INT NULL,
    `cancelled_at` DATETIME NULL,
    `cancel_reason` VARCHAR(200) NULL,
    CONSTRAINT `fk_refund_requests_fee_records` FOREIGN KEY (`fee_record_id`) REFERENCES `fee_records` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_refund_requests_users_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_refund_requests_users_completed` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_refund_requests_users_cancelled` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `chk_refund_amount` CHECK (`amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Bảng Bộ đếm Số biên lai theo ngày (Receipt Counters: PT-YYYYMMDD-NNNN)
CREATE TABLE `receipt_counters` (
    `day` DATE NOT NULL PRIMARY KEY,
    `last_no` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Nhật ký Kiểm toán hệ thống (Audit Log - Append-only)
CREATE TABLE `audit_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `action` VARCHAR(50) NOT NULL,
    `entity` VARCHAR(50) NOT NULL,
    `entity_id` VARCHAR(50) NULL,
    `payload` JSON NULL,
    `ip` VARCHAR(45) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_audit_log_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- DỮ LIỆU KHỞI TẠO MẪU (SEED DATA)
-- LƯU Ý QUAN TRỌNG:
-- Không chèn tài khoản vào bảng `users` tại đây. Tài khoản quản trị viên được
-- tạo và băm mật khẩu Bcrypt an toàn duy nhất bởi script CLI: database/seed_users.php
-- (Admin accounts are created only by database/seed_users.php, which hashes passwords).
-- ----------------------------------------------------------------------------

-- 1. Học kỳ (Semesters)
INSERT INTO `semesters` (`id`, `term_no`, `academic_year_start`, `created_at`) VALUES
(1, 1, 2025, '2025-08-15 09:00:00'),
(2, 2, 2025, '2026-01-10 09:00:00'),
(3, 1, 2026, '2026-08-15 09:00:00');

-- 2. Định mức Tín chỉ (Credit Rates)
INSERT INTO `credit_rates` (`id`, `semester_id`, `price_per_credit`, `updated_at`) VALUES
(1, 1, 800000, '2025-08-15 09:00:00'),
(2, 2, 820000, '2026-01-10 09:00:00'),
(3, 3, 850000, '2026-08-15 09:00:00');

-- 3. Danh sách Sinh viên (Students)
INSERT INTO `students` (`id`, `student_code`, `full_name`, `name_search`, `class_name`, `created_at`, `updated_at`) VALUES
(1, 'SV001', 'Nguyễn Văn An', 'nguyen van an', 'K20-CNTT1', '2025-08-20 08:00:00', '2025-08-20 08:00:00'),
(2, 'SV002', 'Trần Thị Bình', 'tran thi binh', 'K20-CNTT1', '2025-08-20 08:05:00', '2025-08-20 08:05:00'),
(3, 'SV003', 'Lê Hoàng Cường', 'le hoang cuong', 'K21-KT2', '2025-08-20 08:10:00', '2025-08-20 08:10:00'),
(4, 'SV004', 'Phạm Thị Dung', 'pham thi dung', 'K21-KT2', '2025-08-20 08:15:00', '2025-08-20 08:15:00'),
(5, 'SV005', 'Nguyễn Thị Hòa', 'nguyen thi hoa', 'K20-CNTT2', '2025-08-20 08:20:00', '2025-08-20 08:20:00'),
(6, 'SV006', 'Võ Minh Đức', 'vo minh duc', NULL, '2025-08-20 08:25:00', '2025-08-25 08:25:00');

-- 4. Hồ sơ Học phí (Fee Records)
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
(10, 2, 1, 15, 800000, 12000000, '2025-08-25 08:40:00', '2025-08-25 08:40:00');

-- 5. Miễn giảm Học phí (Exemptions)
INSERT INTO `exemptions` (`id`, `fee_record_id`, `type`, `value`, `reason`, `created_by`, `updated_at`) VALUES
(1, 1, 'percent', 10, 'Học bổng khuyến khích học tập', 1, '2026-09-22 09:00:00'),
(2, 3, 'fixed', 2000000, 'Diện hộ nghèo', 1, '2026-09-22 09:10:00'),
(3, 5, 'fixed', 1000000, 'Điều chỉnh miễn giảm sau thanh toán', 1, '2026-09-11 14:00:00'),
(4, 6, 'percent', 20, 'Học bổng doanh nghiệp', 1, '2026-02-18 09:00:00'),
(5, 8, 'fixed', 1600000, 'Hỗ trợ học phí kỳ 1', 1, '2025-09-02 10:00:00'),
(6, 9, 'percent', 100, 'Học bổng toàn phần 100%', 1, '2025-08-28 09:00:00');

-- 6. Sổ cái giao dịch (Ledger Entries)
INSERT INTO `ledger_entries` (`id`, `fee_record_id`, `entry_type`, `amount`, `method`, `entry_date`, `receipt_no`, `reverses_entry_id`, `reason`, `note`, `request_id`, `created_by`, `created_at`) VALUES
(1, 8, 'payment', 9600000, 'Tiền mặt', '2026-09-01', 'PT-20260901-0001', NULL, NULL, 'Thu học phí HK1 2025-2026', 'a1111111-1111-1111-1111-111111111101', 1, '2026-09-01 08:30:00'),
(2, 10, 'payment', 6000000, 'Chuyển khoản', '2026-09-05', 'PT-20260905-0001', NULL, NULL, 'Đợt 1 HK1 2025-2026', 'a1111111-1111-1111-1111-111111111102', 2, '2026-09-05 09:15:00'),
(3, 10, 'payment', 6000000, 'Thẻ', '2026-09-05', 'PT-20260905-0002', NULL, NULL, 'Đợt 2 HK1 2025-2026', 'a1111111-1111-1111-1111-111111111103', 1, '2026-09-05 15:30:00'),
(4, 5, 'payment', 8200000, 'Chuyển khoản', '2026-09-10', 'PT-20260910-0001', NULL, NULL, 'Nộp học phí HK2', 'a1111111-1111-1111-1111-111111111104', 1, '2026-09-10 10:00:00'),
(5, 6, 'payment', 10496000, 'Tiền mặt', '2026-09-12', 'PT-20260912-0001', NULL, NULL, 'Nộp học phí HK2 đủ', 'a1111111-1111-1111-1111-111111111105', 2, '2026-09-12 11:20:00'),
(6, 7, 'payment', 5000000, 'Thẻ', '2026-09-15', 'PT-20260915-0001', NULL, NULL, 'Thu học phí SV005', 'a1111111-1111-1111-1111-111111111106', 1, '2026-09-15 14:00:00'),
(7, 7, 'reversal', 5000000, 'Thẻ', '2026-09-15', NULL, 6, 'Nhập nhầm sinh viên', 'Đảo mục phiếu PT-20260915-0001', 'a1111111-1111-1111-1111-111111111107', 1, '2026-09-15 14:30:00'),
(8, 1, 'payment', 11475000, 'Tiền mặt', '2026-10-01', 'PT-20261001-0001', NULL, NULL, 'Thanh toán học phí HK1 2026-2027', 'a1111111-1111-1111-1111-111111111108', 1, '2026-10-01 09:00:00'),
(9, 2, 'payment', 5000000, 'Chuyển khoản', '2026-10-02', 'PT-20261002-0001', NULL, NULL, 'Tạm ứng học phí HK1 2026-2027', 'a1111111-1111-1111-1111-111111111109', 2, '2026-10-02 10:15:00'),
(10, 4, 'payment', 17849999, 'Thẻ', '2026-10-03', 'PT-20261003-0001', NULL, NULL, 'Thanh toán học phí thiếu 1 đồng', 'a1111111-1111-1111-1111-111111111110', 1, '2026-10-03 16:45:00');

-- 7. Yêu cầu Hoàn trả (Refund Requests)
INSERT INTO `refund_requests` (`id`, `fee_record_id`, `amount`, `reason`, `status`, `request_id`, `created_by`, `created_at`, `completed_by`, `completed_at`, `cancelled_by`, `cancelled_at`, `cancel_reason`) VALUES
(1, 5, 1000000, 'Điều chỉnh miễn giảm sau thanh toán', 'pending', 'b1111111-1111-1111-1111-111111111101', 1, '2026-09-11 14:30:00', NULL, NULL, NULL, NULL, NULL),
(2, 8, 1600000, 'Hoàn tiền miễn giảm bổ sung', 'completed', 'b1111111-1111-1111-1111-111111111102', 1, '2025-09-02 10:30:00', 1, '2025-09-05 10:00:00', NULL, NULL, NULL),
(3, 10, 500000, 'Tạo nhầm', 'cancelled', 'b1111111-1111-1111-1111-111111111103', 1, '2025-09-06 09:00:00', NULL, NULL, 1, '2025-09-06 09:30:00', 'Nhầm lẫn thao tác');

-- 8. Bộ đếm Số biên lai theo ngày (Receipt Counters)
INSERT INTO `receipt_counters` (`day`, `last_no`) VALUES
('2026-09-01', 1),
('2026-09-05', 2),
('2026-09-10', 1),
('2026-09-12', 1),
('2026-09-15', 1),
('2026-10-01', 1),
('2026-10-02', 1),
('2026-10-03', 1);

-- 9. Nhật ký Kiểm toán ban đầu (Audit Log)
INSERT INTO `audit_log` (`user_id`, `action`, `entity`, `entity_id`, `payload`, `ip`, `created_at`) VALUES
(1, 'seed_init', 'system', '0', '{"info": "Khởi tạo dữ liệu mẫu hệ thống"}', '127.0.0.1', '2026-08-01 08:00:00');

-- ----------------------------------------------------------------------------
-- TẠO NGƯỜI DÙNG ỨNG DỤNG QUYỀN TỐI THIỂU (tuition_user)
-- ----------------------------------------------------------------------------
CREATE USER IF NOT EXISTS 'tuition_user'@'localhost' IDENTIFIED BY 'TuitionApp2026!Sec';
CREATE USER IF NOT EXISTS 'tuition_user'@'127.0.0.1' IDENTIFIED BY 'TuitionApp2026!Sec';

GRANT SELECT, INSERT, UPDATE, DELETE ON `tuition_system`.* TO 'tuition_user'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `tuition_system`.* TO 'tuition_user'@'127.0.0.1';

GRANT SELECT, INSERT, UPDATE, DELETE ON `tuition_test`.* TO 'tuition_user'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `tuition_test`.* TO 'tuition_user'@'127.0.0.1';

FLUSH PRIVILEGES;

SET FOREIGN_KEY_CHECKS = 1;
