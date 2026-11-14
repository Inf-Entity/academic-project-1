<?php
/**
 * Module 09: Semesters (Quản lý học kỳ & Trạng thái đóng/mở)
 */

declare(strict_types=1);

function run_ft_semesters(ApiTestClient $client, PDO $pdo): void {
    suite_header("Functional Module 09: Semesters (Quản lý học kỳ & Mở/Đóng)");
    $client->login('admin', 'Admin@123');

    // FT-025: Semester creation and open/closed state management
    $res1 = $client->request('GET', '/semesters');
    $sems = $res1['body']['data'] ?? [];
    $hasSems = ($res1['status'] === 200) && count($sems) >= 4;
    test_ft(
        'FT-025',
        'semesters',
        'Truy vấn danh sách học kỳ và trạng thái mở/đóng',
        'FR-011: Quản lý danh mục học kỳ niên khóa',
        'Gửi GET /api/semesters',
        'HTTP 200 và danh sách chứa ít nhất 4 học kỳ',
        "HTTP {$res1['status']}, count: " . count($sems),
        $hasSems
    );

    // FT-026: Late registration rejection on closed semesters
    // Học kỳ 1 (HK1 2024-2025) là học kỳ đã đóng (is_closed = 1)
    $client->login('sv001', 'Student@001');
    $uuidReg = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    $res2 = $client->request('POST', '/student/registrations', [
        'semester_id' => 1,  // Closed semester
        'credits'     => 15,
        'request_id'  => $uuidReg,
    ]);
    $ok2 = ($res2['status'] === 422) && in_array($res2['body']['error']['code'] ?? '', ['REGISTRATION_CLOSED', 'SEMESTER_CLOSED'], true);
    test_ft(
        'FT-026',
        'semesters',
        'Chặn sinh viên đăng ký học phần trên học kỳ đã đóng đăng ký (is_closed = 1)',
        'BR-015: Không cho phép sinh viên đăng ký học phần trên học kỳ có trạng thái đóng',
        'Sinh viên gửi POST /api/student/registrations vào học kỳ #1 đã đóng',
        'HTTP 422 Unprocessable Entity kèm mã REGISTRATION_CLOSED',
        "HTTP {$res2['status']}, code: " . ($res2['body']['error']['code'] ?? 'N/A'),
        $ok2
    );

    // FT-027: Student semester listing only shows open semesters
    $client->login('sv001', 'Student@001');
    $res3 = $client->request('GET', '/student/semesters');
    $stSems = $res3['body']['data'] ?? [];
    $allOpen = true;
    foreach ($stSems as $s) {
        if (!empty($s['is_closed'])) {
            $allOpen = false;
            break;
        }
    }
    test_ft(
        'FT-027',
        'semesters',
        'Endpoint học kỳ của sinh viên chỉ hiển thị các học kỳ đang mở',
        'FR-012: Phân quyền xem học kỳ cho sinh viên',
        'Sinh viên gọi GET /api/student/semesters',
        'Tất cả học kỳ trả về đều có is_closed = 0/false',
        "Số học kỳ hiển thị: " . count($stSems) . ", allOpen: " . ($allOpen ? 'true' : 'false'),
        $allOpen && !empty($stSems)
    );
}
