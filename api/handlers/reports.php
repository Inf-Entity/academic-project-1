<?php
/**
 * Reports and Reconciliation API Handler
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/validate.php';
require_once dirname(__DIR__) . '/lib/response.php';
require_once dirname(__DIR__) . '/lib/calc.php';

function handle_reports_routes(string $method, string $path): void {
    require_admin();
    $db = get_db_connection();

    if ($path === '/api/reports/summary' && $method === 'GET') {
        $semesterFilter = $_GET['semester'] ?? ($_GET['semester_id'] ?? null);

        $feeSub = get_fee_summary_subquery();

        // Quy tắc tính toán báo cáo (Report Derivation Rules):
        // 1. Số lượng sinh viên theo trạng thái thanh toán được tính trên từng hồ sơ học phí (fee_record).
        //    Sinh viên có 2 hồ sơ học phí ở trạng thái khác nhau được tính 1 lần cho mỗi hồ sơ theo trạng thái của hồ sơ đó.
        //    (A student with two fee records in different statuses is counted once per fee record, labeled by the record's status).
        // 2. Nộp thừa (Overpaid: total_paid > net_due) được báo cáo riêng biệt trên một dòng riêng,
        //    không tính gộp vào 3 trạng thái công nợ (Chưa thanh toán, Thanh toán một phần, Đã thanh toán đủ).
        // 3. Tổng số lượng của 3 trạng thái (unpaid + partial + paid) phải bằng tổng số hồ sơ trừ đi số hồ sơ nộp thừa
        //    (The per-status counts must sum to the number of fee records in the selected scope, excluding overpaid).
        // 4. Sử dụng COUNT trực tiếp trên tập phái sinh derived_status từ calc.php (COUNT over the derived set).
        $sql = "SELECT 
                    COUNT(*) AS total_records,
                    COALESCE(SUM(v.gross_tuition), 0) AS gross_tuition,
                    COALESCE(SUM(v.exemption_amount), 0) AS total_exemptions,
                    COALESCE(SUM(v.net_due), 0) AS receivable,
                    COALESCE(SUM(LEAST(v.total_paid, v.net_due)), 0) AS collected,
                    COALESCE(SUM(v.outstanding_amount), 0) AS outstanding,
                    COUNT(CASE WHEN v.derived_status = 'Unpaid' THEN 1 END) AS unpaid_count,
                    COUNT(CASE WHEN v.derived_status = 'Partially Paid' THEN 1 END) AS partial_count,
                    COUNT(CASE WHEN v.derived_status = 'Fully Paid' THEN 1 END) AS paid_count,
                    COUNT(CASE WHEN v.derived_status = 'Overpaid' THEN 1 END) AS overpaid_count,
                    COALESCE(SUM(v.overpaid_amount), 0) AS overpaid_amount
                FROM {$feeSub} v
                JOIN semesters sem ON v.semester_id = sem.id
                WHERE 1=1";
        $params = [];

        if (!empty($semesterFilter) && $semesterFilter !== 'ALL') {
            if (!is_numeric($semesterFilter) || (int)$semesterFilter <= 0) {
                json_error('Vui lòng chọn học kỳ hợp lệ.', 'VALIDATION_ERROR', 400, [
                    'semester' => 'Vui lòng chọn học kỳ.'
                ]);
            }
            $sql .= " AND v.semester_id = :semId";
            $params['semId'] = (int)$semesterFilter;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $summary = $stmt->fetch();

        $totalRecords = (int)($summary['total_records'] ?? 0);
        $gross = (int)($summary['gross_tuition'] ?? 0);
        $exemptions = (int)($summary['total_exemptions'] ?? 0);
        $receivable = (int)($summary['receivable'] ?? 0);
        $collected = (int)($summary['collected'] ?? 0);
        $outstanding = (int)($summary['outstanding'] ?? 0);
        $unpaidCount = (int)($summary['unpaid_count'] ?? 0);
        $partialCount = (int)($summary['partial_count'] ?? 0);
        $paidCount = (int)($summary['paid_count'] ?? 0);
        $overpaidCount = (int)($summary['overpaid_count'] ?? 0);
        $overpaidAmount = (int)($summary['overpaid_amount'] ?? 0);

        json_success([
            'semester_id'                 => (!empty($semesterFilter) && $semesterFilter !== 'ALL') ? (int)$semesterFilter : null,
            'total_students_with_records' => $totalRecords,
            'gross_tuition'               => $gross,
            'total_exemptions'            => $exemptions,
            'receivable'                  => $receivable,
            'collected'                   => $collected,
            'outstanding'                 => $outstanding,
            'reconciles'                  => (($collected + $outstanding) === $receivable),
            'status_counts'               => [
                'unpaid'  => $unpaidCount,
                'partial' => $partialCount,
                'paid'    => $paidCount
            ],
            'overpaid'                    => [
                'count'  => $overpaidCount,
                'amount' => $overpaidAmount
            ]
        ]);
    }
}
