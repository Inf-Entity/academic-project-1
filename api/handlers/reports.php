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
        $sql = "SELECT 
                    v.*,
                    sem.term_no, sem.academic_year_start,
                    CONCAT('Học kỳ ', sem.term_no, ' - Năm học ', sem.academic_year_start, '-', sem.academic_year_start + 1) AS semester_label
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
        $records = $stmt->fetchAll();

        $gross = 0;
        $exemptions = 0;
        $receivable = 0;
        $collected = 0;
        $outstanding = 0;
        $overpaidAmount = 0;
        $overpaidCount = 0;

        $unpaidCount = 0;
        $partialCount = 0;
        $paidCount = 0;

        foreach ($records as $r) {
            $g = (int)$r['gross_tuition'];
            $e = (int)$r['exemption_amount'];
            $n = (int)$r['net_due'];
            $p = (int)$r['total_paid'];

            $gross += $g;
            $exemptions += $e;
            $receivable += $n;

            // Collected + Outstanding = Receivable giữ cân bằng tuyệt đối
            $c = min($p, $n);
            $o = max(0, $n - $p);
            $collected += $c;
            $outstanding += $o;

            if ($p > $n) {
                $overpaidCount++;
                $overpaidAmount += ($p - $n);
            }

            // 3 trạng thái phân loại bao phủ 100% số sinh viên có hồ sơ
            if ($p === 0 && $n > 0) {
                $unpaidCount++;
            } elseif ($p > 0 && $p < $n) {
                $partialCount++;
            } else {
                // p >= n (bao gồm n = 0 và nộp thừa)
                $paidCount++;
            }
        }

        $totalRecords = count($records);

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
