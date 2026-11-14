<?php
/**
 * Module 08: Students (Quản lý thông tin & Hồ sơ sinh viên)
 */

declare(strict_types=1);

function run_ft_students(ApiTestClient $client, PDO $pdo): void {
    suite_header("Functional Module 08: Students (Quản lý hồ sơ sinh viên)");
    $client->login('admin', 'Admin@123');

    $randCode = 'SVTEST' . mt_rand(100, 999);

    // FT-022: Creation of student profile with unique code and class
    $res1 = $client->request('POST', '/students', [
        'student_code' => $randCode,
        'full_name'    => 'Nguyễn Văn Kiểm Thử',
        'class_name'   => 'CNTT2026',
    ]);
    $ok1 = ($res1['status'] === 201) && !empty($res1['body']['ok']);
    $newStudentId = (int)($res1['body']['data']['id'] ?? 0);
    test_ft(
        'FT-022',
        'students',
        'Thêm mới hồ sơ sinh viên với mã sinh viên duy nhất và lớp học',
        'FR-009: Quản lý danh mục sinh viên',
        "Gửi POST /api/students với mã {$randCode}, họ tên 'Nguyễn Văn Kiểm Thử', lớp 'CNTT2026'",
        'HTTP 201 Created và trả về thông tin sinh viên mới',
        "HTTP {$res1['status']}, id: {$newStudentId}",
        $ok1
    );

    // FT-023: Student deletion restriction when fee records or payments exist
    $res2 = $client->request('DELETE', '/students/1');
    $ok2 = ($res2['status'] === 409);
    test_ft(
        'FT-023',
        'students',
        'Ràng buộc toàn vẹn: Chặn xóa sinh viên đã có dữ liệu học phí hoặc tài chính',
        'BR-014: Ràng buộc ON DELETE RESTRICT bảo vệ dữ liệu tài chính sinh viên',
        'Gửi DELETE /api/students/1 (SV001 đã có hồ sơ học phí)',
        'HTTP 409 Conflict kèm thông điệp giải thích',
        "HTTP {$res2['status']}, error: " . ($res2['body']['error']['message'] ?? 'N/A'),
        $ok2
    );

    // FT-024: Deletion of student with zero fee records succeeds
    $res3 = $client->request('DELETE', "/students/{$newStudentId}");
    $ok3 = ($res3['status'] === 200) && !empty($res3['body']['ok']);
    test_ft(
        'FT-024',
        'students',
        'Xóa thành công sinh viên chưa có phát sinh dữ liệu học phí',
        'FR-010: Xóa hồ sơ sinh viên khi chưa phát sinh giao dịch',
        "Gửi DELETE /api/students/{$newStudentId}",
        'HTTP 200 OK',
        "HTTP {$res3['status']}, ok: " . json_encode($res3['body']['ok'] ?? false),
        $ok3
    );
}
