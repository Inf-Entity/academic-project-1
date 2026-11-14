<?php
/**
 * Module 02: Fee Calculation (Tính toán học phí & Làm tròn)
 */

declare(strict_types=1);

function run_ft_fee_calculation(ApiTestClient $client, PDO $pdo): void {
    suite_header("Functional Module 02: Fee Calculation (Tính toán học phí)");

    // FT-004: Gross calculation (credits * rate_snapshot)
    $gross1 = calc_gross(15, 850000);
    $ok1 = ($gross1 === 12750000);
    test_ft(
        'FT-004',
        'fee_calculation',
        'Tính học phí gốc: Số tín chỉ nhân đơn giá snapshot',
        'BR-002: gross_tuition = registered_credits * rate_snapshot',
        'Gọi calc_gross(15, 850000)',
        '12.750.000 VND',
        "{$gross1} VND",
        $ok1
    );

    // FT-005: Net due calculation (max(0, gross - exemption))
    $net1 = calc_net_due(12750000, 1275000);
    $net2 = calc_net_due(5000000, 6000000); // Exemption exceeds gross -> net due 0
    $ok2 = ($net1 === 11475000) && ($net2 === 0);
    test_ft(
        'FT-005',
        'fee_calculation',
        'Tính số tiền thực nộp (Net Due): gross trừ miễn giảm, chặn dưới tại 0',
        'BR-003: net_due = max(0, gross_tuition - exemption_amount)',
        'Gọi calc_net_due(12.75M, 1.275M) và calc_net_due(5M, 6M)',
        '11.475.000 VND và 0 VND',
        "Net1: {$net1}, Net2: {$net2}",
        $ok2
    );

    // FT-006: Rounding rule for currency amounts to exact whole VND
    $exRound = calc_exemption(1000000, 'percent', 33.33);
    $ok3 = ($exRound === 333300);
    test_ft(
        'FT-006',
        'fee_calculation',
        'Quy tắc làm tròn số tiền miễn giảm phần trăm đến số nguyên VND',
        'BR-004: Làm tròn số tiền VND đến hàng nguyên gần nhất bằng round()',
        'Tính miễn giảm 33.33% trên 1.000.000 VND',
        '333.300 VND',
        "{$exRound} VND",
        $ok3
    );
}
