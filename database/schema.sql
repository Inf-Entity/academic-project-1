-- ============================================================================
-- Hệ thống Quản lý Học phí Sinh viên - Database Schema (DDL)
-- MariaDB / MySQL 8.0+, InnoDB, utf8mb4_unicode_ci
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `tuition_system` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `tuition_system`;

-- 1. Bảng Quản trị viên & Phân quyền
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `role` ENUM('admin') NOT NULL DEFAULT 'admin',
    `failed_logins` INT NOT NULL DEFAULT 0,
    `locked_until` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Bảng Sinh viên
CREATE TABLE IF NOT EXISTS `students` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_code` VARCHAR(12) NOT NULL UNIQUE,
    `full_name` VARCHAR(100) NOT NULL,
    `name_search` VARCHAR(255) NOT NULL,
    `class_name` VARCHAR(50) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_students_name_search` (`name_search`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Bảng Học kỳ
CREATE TABLE IF NOT EXISTS `semesters` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `term_no` TINYINT NOT NULL,
    `academic_year_start` SMALLINT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_semester` (`term_no`, `academic_year_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Bảng Định mức Tín chỉ (1 mức giá duy nhất trên mỗi học kỳ)
CREATE TABLE IF NOT EXISTS `credit_rates` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `semester_id` INT NOT NULL UNIQUE,
    `price_per_credit` BIGINT NOT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_rates_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_rate_price` CHECK (`price_per_credit` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Bảng Hồ sơ Học phí (1 hồ sơ duy nhất trên 1 sinh viên trong 1 học kỳ)
CREATE TABLE IF NOT EXISTS `fee_records` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `semester_id` INT NOT NULL,
    `registered_credits` SMALLINT NOT NULL,
    `rate_snapshot` BIGINT NOT NULL,
    `gross_tuition` BIGINT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_fee_student_semester` (`student_id`, `semester_id`),
    CONSTRAINT `fk_fee_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_fee_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_fee_credits` CHECK (`registered_credits` > 0),
    CONSTRAINT `chk_fee_snapshot` CHECK (`rate_snapshot` > 0),
    CONSTRAINT `chk_fee_gross` CHECK (`gross_tuition` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Bảng Miễn giảm học phí (tối đa 1 chính sách miễn giảm trên 1 hồ sơ học phí)
CREATE TABLE IF NOT EXISTS `exemptions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `fee_record_id` INT NOT NULL UNIQUE,
    `type` ENUM('percent', 'fixed') NOT NULL,
    `value` BIGINT NOT NULL,
    `reason` VARCHAR(200) NOT NULL,
    `created_by` INT NOT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_exemptions_fee_record` FOREIGN KEY (`fee_record_id`) REFERENCES `fee_records` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_exemptions_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_exemption_value` CHECK ((`type` = 'percent' AND `value` BETWEEN 1 AND 100) OR (`type` = 'fixed' AND `value` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Sổ cái giao dịch Thu / Đảo mục (Append-only Ledger)
CREATE TABLE IF NOT EXISTS `ledger_entries` (
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
    CONSTRAINT `fk_ledger_fee_record` FOREIGN KEY (`fee_record_id`) REFERENCES `fee_records` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ledger_reverses` FOREIGN KEY (`reverses_entry_id`) REFERENCES `ledger_entries` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ledger_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_ledger_amount` CHECK (`amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Bảng Yêu cầu Hoàn trả tiền thừa
CREATE TABLE IF NOT EXISTS `refund_requests` (
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
    CONSTRAINT `fk_refund_fee_record` FOREIGN KEY (`fee_record_id`) REFERENCES `fee_records` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_refund_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_refund_completed_by` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_refund_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_refund_amount` CHECK (`amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Bảng Bộ đếm Số biên lai theo ngày (PT-YYYYMMDD-NNNN)
CREATE TABLE IF NOT EXISTS `receipt_counters` (
    `day` DATE NOT NULL PRIMARY KEY,
    `last_no` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Nhật ký Kiểm toán hệ thống (Audit Log - Append-only)
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `action` VARCHAR(50) NOT NULL,
    `entity` VARCHAR(50) NOT NULL,
    `entity_id` VARCHAR(50) NULL,
    `payload` JSON NULL,
    `ip` VARCHAR(45) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. View Tổng hợp Số dư Học phí (Tính toán từ Gross, Miễn giảm, Sổ cái & Hoàn tiền)
CREATE OR REPLACE VIEW `v_fee_record_summaries` AS
SELECT 
    fr.id AS fee_record_id,
    fr.student_id,
    fr.semester_id,
    fr.registered_credits,
    fr.rate_snapshot,
    fr.gross_tuition,
    ex.id AS exemption_id,
    ex.type AS exemption_type,
    ex.value AS exemption_value,
    ex.reason AS exemption_reason,
    CASE 
        WHEN ex.type = 'percent' THEN ROUND(fr.gross_tuition * ex.value / 100)
        WHEN ex.type = 'fixed' THEN LEAST(fr.gross_tuition, ex.value)
        ELSE 0
    END AS exemption_amount,
    GREATEST(0, fr.gross_tuition - (
        CASE 
            WHEN ex.type = 'percent' THEN ROUND(fr.gross_tuition * ex.value / 100)
            WHEN ex.type = 'fixed' THEN LEAST(fr.gross_tuition, ex.value)
            ELSE 0
        END
    )) AS net_due,
    COALESCE(p.payments_sum, 0) AS payments_sum,
    COALESCE(r.reversals_sum, 0) AS reversals_sum,
    COALESCE(rf_comp.completed_refunds_sum, 0) AS completed_refunds_sum,
    COALESCE(rf_pend.pending_refunds_sum, 0) AS pending_refunds_sum,
    (COALESCE(p.payments_sum, 0) - COALESCE(r.reversals_sum, 0) - COALESCE(rf_comp.completed_refunds_sum, 0)) AS total_paid
FROM fee_records fr
LEFT JOIN exemptions ex ON fr.id = ex.fee_record_id
LEFT JOIN (
    SELECT fee_record_id, SUM(amount) AS payments_sum
    FROM ledger_entries
    WHERE entry_type = 'payment'
    GROUP BY fee_record_id
) p ON fr.id = p.fee_record_id
LEFT JOIN (
    SELECT fee_record_id, SUM(amount) AS reversals_sum
    FROM ledger_entries
    WHERE entry_type = 'reversal'
    GROUP BY fee_record_id
) r ON fr.id = r.fee_record_id
LEFT JOIN (
    SELECT fee_record_id, SUM(amount) AS completed_refunds_sum
    FROM refund_requests
    WHERE status = 'completed'
    GROUP BY fee_record_id
) rf_comp ON fr.id = rf_comp.fee_record_id
LEFT JOIN (
    SELECT fee_record_id, SUM(amount) AS pending_refunds_sum
    FROM refund_requests
    WHERE status = 'pending'
    GROUP BY fee_record_id
) rf_pend ON fr.id = rf_pend.fee_record_id;
