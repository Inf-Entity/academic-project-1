-- ============================================================================
-- Tạo người dùng ứng dụng với đặc quyền tối thiểu (Least Privilege)
-- Chỉ cấp quyền: SELECT, INSERT, UPDATE, DELETE. Không cấp DROP, ALTER.
-- ============================================================================

CREATE USER IF NOT EXISTS 'tuition_user'@'localhost' IDENTIFIED BY 'TuitionApp2026!Sec';
CREATE USER IF NOT EXISTS 'tuition_user'@'127.0.0.1' IDENTIFIED BY 'TuitionApp2026!Sec';

GRANT SELECT, INSERT, UPDATE, DELETE ON `tuition_system`.* TO 'tuition_user'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `tuition_system`.* TO 'tuition_user'@'127.0.0.1';

GRANT SELECT, INSERT, UPDATE, DELETE ON `tuition_test`.* TO 'tuition_user'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `tuition_test`.* TO 'tuition_user'@'127.0.0.1';

FLUSH PRIVILEGES;
