-- ============================================================================
-- Hệ thống Quản lý Học phí Sinh viên - Database Schema (DDL)
-- MariaDB / MySQL 8.0+, InnoDB, utf8mb4_unicode_ci
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Xóa các bảng theo thứ tự bảng con trước, bảng cha sau (Child-first order)
DROP VIEW IF EXISTS `v_fee_record_summaries`;
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

-- Tạo các bảng theo thứ tự bảng cha trước, bảng con sau (Parent-first order)

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
    `entry_type` ENUM('payment', 'reversal', 'transfer_out', 'transfer_in') NOT NULL,
    `amount` BIGINT NOT NULL,
    `method` ENUM('Tiền mặt', 'Chuyển khoản', 'Thẻ') NOT NULL,
    `entry_date` DATE NOT NULL,
    `receipt_no` VARCHAR(20) UNIQUE NULL,
    `reverses_entry_id` INT NULL UNIQUE,
    `transfer_group_id` CHAR(36) NULL,
    `reason` VARCHAR(200) NULL,
    `note` VARCHAR(255) NULL,
    `request_id` VARCHAR(50) NOT NULL UNIQUE,
    `created_by` INT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ledger_transfer_group_id` (`transfer_group_id`),
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

-- 11. Triggers chuẩn hóa mã sinh viên tự động thành chữ in hoa (Uppercase Normalization)
DROP TRIGGER IF EXISTS `trg_students_before_insert`;
CREATE TRIGGER `trg_students_before_insert` BEFORE INSERT ON `students` FOR EACH ROW SET NEW.student_code = UPPER(TRIM(NEW.student_code));

DROP TRIGGER IF EXISTS `trg_students_before_update`;
CREATE TRIGGER `trg_students_before_update` BEFORE UPDATE ON `students` FOR EACH ROW SET NEW.student_code = UPPER(TRIM(NEW.student_code));

SET FOREIGN_KEY_CHECKS = 1;
