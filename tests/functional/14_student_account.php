<?php
/**
 * Module 14: Student Account (Tài khoản sinh viên & Đổi mật khẩu bắt buộc)
 */

declare(strict_types=1);

function run_ft_student_account(ApiTestClient $client, PDO $pdo): void {
    suite_header("Functional Module 14: Student Account (Tài khoản & Đổi mật khẩu)");

    // FT-041: Student login with valid credentials and role validation
    $res1 = $client->login('sv001', 'Student@001');
    $ok1 = ($res1['status'] === 200) && (($res1['body']['data']['role'] ?? '') === 'student');
    test_ft(
        'FT-041',
        'student_account',
        'Sinh viên đăng nhập thành công với vai trò student',
        'FR-019: Đăng nhập phân hệ sinh viên',
        'Gửi POST /api/login với sv001 / Student@001',
        'HTTP 200, role = student',
        "HTTP {$res1['status']}, role: " . ($res1['body']['data']['role'] ?? 'N/A'),
        $ok1
    );

    // FT-042: Enforcement of must_change_password mandatory workflow
    // Tạo sinh viên có must_change_password = 1 hoặc kiểm tra sv023
    $res23 = $client->login('sv023', 'Student@023');
    $mustChange = (int)($res23['body']['data']['must_change_password'] ?? 0);
    $resBlocked = $client->request('GET', '/student/debts');
    $isBlocked = ($resBlocked['status'] === 403) && (($resBlocked['body']['error']['code'] ?? '') === 'PASSWORD_CHANGE_REQUIRED');
    test_ft(
        'FT-042',
        'student_account',
        'Bắt buộc đổi mật khẩu lần đầu: Chặn mọi thao tác ngoại trừ đổi mật khẩu',
        'BR-023: Khóa chức năng sinh viên khi cờ must_change_password = 1',
        'Đăng nhập sv023 và gọi GET /api/student/debts',
        'HTTP 403 Forbidden kèm mã PASSWORD_CHANGE_REQUIRED',
        "HTTP {$resBlocked['status']}, code: " . ($resBlocked['body']['error']['code'] ?? 'N/A'),
        ($res23['status'] === 200) && ($mustChange === 1) && $isBlocked
    );

    // FT-043: Successful password change clears flag and restores full access
    $resChange = $client->request('POST', '/student/password', [
        'current_password' => 'Student@023',
        'new_password'     => 'NewStudentPass@2026',
        'confirm_password' => 'NewStudentPass@2026',
    ]);
    $resAfter = $client->request('GET', '/student/debts');
    $ok3 = ($resChange['status'] === 200) && ($resAfter['status'] === 200);
    // Khôi phục lại mật khẩu cho sv023
    $client->request('POST', '/student/password', [
        'current_password' => 'NewStudentPass@2026',
        'new_password'     => 'Student@023',
        'confirm_password' => 'Student@023',
    ]);
    $pdo->prepare("UPDATE users SET must_change_password = 1 WHERE username = 'sv023'")->execute();
    test_ft(
        'FT-043',
        'student_account',
        'Đổi mật khẩu thành công xóa bỏ cờ must_change_password và phục hồi quyền truy cập',
        'FR-020: Đổi mật khẩu tài khoản sinh viên',
        'Gửi POST /api/student/password đổi mật khẩu mới',
        'HTTP 200 OK và sau đó truy cập được /api/student/debts',
        "HTTP đổi: {$resChange['status']}, HTTP debts sau đổi: {$resAfter['status']}",
        $ok3
    );
}
