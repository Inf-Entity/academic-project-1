<?php
/**
 * Module 20: Search (Tìm kiếm sinh viên & Học kỳ tiếng Việt chuẩn hóa)
 */

declare(strict_types=1);

function run_ft_search(ApiTestClient $client, PDO $pdo): void {
    suite_header("Functional Module 20: Search (Tìm kiếm chuẩn hóa tiếng Việt)");
    $client->login('admin', 'Admin@123');

    // FT-056: Vietnamese diacritics-insensitive student search
    $resNoMark = $client->request('GET', '/students/search?q=nguyen');
    $resWithMark = $client->request('GET', '/students/search?q=Nguyễn');
    $count1 = count($resNoMark['body']['data'] ?? []);
    $count2 = count($resWithMark['body']['data'] ?? []);
    $okSearch = ($count1 > 0) && ($count1 === $count2);
    test_ft(
        'FT-056',
        'search',
        'Tìm kiếm sinh viên không phân biệt dấu tiếng Việt (có dấu và không dấu ra kết quả đồng nhất)',
        'FR-031: Tìm kiếm sinh viên theo họ tên chuẩn Unicode NFC',
        'So sánh kết quả tìm kiếm với từ khóa "nguyen" và "Nguyễn"',
        "Cả hai đều trả về cùng số lượng bản ghi (count: {$count1})",
        "Số lượng: nguyen ({$count1}) vs Nguyễn ({$count2})",
        $okSearch
    );

    // FT-057: Exact 100-character student search supported, >100 rejected
    $exact100 = str_repeat('a', 100);
    $res100 = $client->request('GET', '/students/search?q=' . urlencode($exact100));
    $over100 = str_repeat('a', 101);
    $resOver = $client->request('GET', '/students/search?q=' . urlencode($over100));
    $okLen = ($res100['status'] === 200) && ($resOver['status'] === 400);
    test_ft(
        'FT-057',
        'search',
        'Giới hạn độ dài từ khóa: Hỗ trợ chính xác 100 ký tự, từ chối khi vượt quá 100 ký tự',
        'BR-026: Giới hạn độ dài chuỗi tìm kiếm tối đa 100 ký tự',
        'Thử tìm kiếm với từ khóa đúng 100 ký tự (HTTP 200) và 101 ký tự (HTTP 400)',
        '100 ký tự: HTTP 200; 101 ký tự: HTTP 400 Bad Request',
        "HTTP 100 ký tự: {$res100['status']}, HTTP 101 ký tự: {$resOver['status']}",
        $okLen
    );

    // FT-058: Ambiguous duplicate names returned correctly
    $resDup = $client->request('GET', '/students/search?q=Long');
    $dataDup = $resDup['body']['data'] ?? [];
    $hasBoth = false;
    $codes = array_column($dataDup, 'student_code');
    if (in_array('SV014', $codes, true) && in_array('SV015', $codes, true)) {
        $hasBoth = true;
    }
    test_ft(
        'FT-058',
        'search',
        'Xử lý trường hợp trùng tên: Tìm kiếm trả về cả 2 sinh viên trùng họ tên (SV014 và SV015)',
        'FR-032: Tra cứu sinh viên theo họ tên có trùng lặp',
        'Tìm kiếm với từ khóa "Long"',
        'Trả về cả SV014 và SV015 (cùng tên Nguyễn Hoàng Long)',
        "Tìm thấy sinh viên: " . implode(', ', $codes),
        $hasBoth
    );
}
