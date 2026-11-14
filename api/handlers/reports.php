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
        // 3. Tổng số lượng của 3 trạng thái (unpaid + partial + paid) cộng với nộp thừa (overpaid) bằng đúng tổng số hồ sơ học phí
        //    (The three statuses plus overpaid must sum to the fee-record count in the selected scope: unpaid + partial + paid + overpaid = total_records).
        // 4. Sử dụng COUNT trực tiếp trên tập phái sinh derived_status từ calc.php (COUNT over the derived set).
        //
        // Quy ước đặt tên khóa JSON (Key Contract Convention):
        // Toàn bộ các khóa trả về tuân theo chuẩn snake_case thống nhất giữa backend API và frontend JavaScript consumer:
        // - semester_id, total_students_with_records, gross_tuition, total_exemptions, receivable, collected, outstanding, reconciles
        // - status_counts: unpaid, partial, paid (số nguyên không âm biểu diễn số lượng hồ sơ theo từng trạng thái)
        // - overpaid: count, amount (số nguyên không âm biểu diễn số lượng hồ sơ nộp thừa và tổng số tiền nộp thừa)
        // - status_amounts: unpaid, partial, paid, overpaid mỗi trạng thái chứa { receivable, collected, outstanding } là số nguyên VND không âm
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
                    COALESCE(SUM(v.overpaid_amount), 0) AS overpaid_amount,
                    COALESCE(SUM(CASE WHEN v.derived_status = 'Unpaid' THEN v.net_due ELSE 0 END), 0) AS unpaid_receivable,
                    COALESCE(SUM(CASE WHEN v.derived_status = 'Unpaid' THEN LEAST(v.total_paid, v.net_due) ELSE 0 END), 0) AS unpaid_collected,
                    COALESCE(SUM(CASE WHEN v.derived_status = 'Unpaid' THEN v.outstanding_amount ELSE 0 END), 0) AS unpaid_outstanding,
                    COALESCE(SUM(CASE WHEN v.derived_status = 'Partially Paid' THEN v.net_due ELSE 0 END), 0) AS partial_receivable,
                    COALESCE(SUM(CASE WHEN v.derived_status = 'Partially Paid' THEN LEAST(v.total_paid, v.net_due) ELSE 0 END), 0) AS partial_collected,
                    COALESCE(SUM(CASE WHEN v.derived_status = 'Partially Paid' THEN v.outstanding_amount ELSE 0 END), 0) AS partial_outstanding,
                    COALESCE(SUM(CASE WHEN v.derived_status = 'Fully Paid' THEN v.net_due ELSE 0 END), 0) AS paid_receivable,
                    COALESCE(SUM(CASE WHEN v.derived_status = 'Fully Paid' THEN LEAST(v.total_paid, v.net_due) ELSE 0 END), 0) AS paid_collected,
                    COALESCE(SUM(CASE WHEN v.derived_status = 'Fully Paid' THEN v.outstanding_amount ELSE 0 END), 0) AS paid_outstanding,
                    COALESCE(SUM(CASE WHEN v.derived_status = 'Overpaid' THEN v.net_due ELSE 0 END), 0) AS overpaid_receivable,
                    COALESCE(SUM(CASE WHEN v.derived_status = 'Overpaid' THEN LEAST(v.total_paid, v.net_due) ELSE 0 END), 0) AS overpaid_collected,
                    COALESCE(SUM(CASE WHEN v.derived_status = 'Overpaid' THEN v.outstanding_amount ELSE 0 END), 0) AS overpaid_outstanding
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

        $unpaidReceivable = (int)($summary['unpaid_receivable'] ?? 0);
        $unpaidCollected = (int)($summary['unpaid_collected'] ?? 0);
        $unpaidOutstanding = (int)($summary['unpaid_outstanding'] ?? 0);

        $partialReceivable = (int)($summary['partial_receivable'] ?? 0);
        $partialCollected = (int)($summary['partial_collected'] ?? 0);
        $partialOutstanding = (int)($summary['partial_outstanding'] ?? 0);

        $paidReceivable = (int)($summary['paid_receivable'] ?? 0);
        $paidCollected = (int)($summary['paid_collected'] ?? 0);
        $paidOutstanding = (int)($summary['paid_outstanding'] ?? 0);

        $overpaidReceivable = (int)($summary['overpaid_receivable'] ?? 0);
        $overpaidCollected = (int)($summary['overpaid_collected'] ?? 0);
        $overpaidOutstanding = (int)($summary['overpaid_outstanding'] ?? 0);

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
            ],
            'status_amounts'              => [
                'unpaid'   => [
                    'receivable'  => $unpaidReceivable,
                    'collected'   => $unpaidCollected,
                    'outstanding' => $unpaidOutstanding,
                ],
                'partial'  => [
                    'receivable'  => $partialReceivable,
                    'collected'   => $partialCollected,
                    'outstanding' => $partialOutstanding,
                ],
                'paid'     => [
                    'receivable'  => $paidReceivable,
                    'collected'   => $paidCollected,
                    'outstanding' => $paidOutstanding,
                ],
                'overpaid' => [
                    'receivable'  => $overpaidReceivable,
                    'collected'   => $overpaidCollected,
                    'outstanding' => $overpaidOutstanding,
                ],
            ]
        ]);
    }
}
