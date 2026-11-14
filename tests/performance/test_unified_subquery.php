<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=tuition_perf;charset=utf8mb4', 'root', '');
require_once 'api/lib/calc.php';

// Old subquery timing
$oldSub = get_fee_summary_subquery();
$t0 = microtime(true);
$pdo->query("SELECT * FROM {$oldSub} v")->fetchAll();
$t1 = microtime(true);
echo "Old subquery 3k records: " . round(($t1 - $t0) * 1000, 2) . " ms\n";

// New unified subquery
$newSub = "(
    SELECT 
        base.*,
        CASE 
            WHEN base.total_paid > base.net_due THEN 'Overpaid'
            WHEN base.total_paid <= 0 AND base.net_due > 0 THEN 'Unpaid'
            WHEN base.total_paid > 0 AND base.total_paid < base.net_due THEN 'Partially Paid'
            ELSE 'Fully Paid'
        END AS derived_status,
        CASE 
            WHEN base.total_paid > base.net_due THEN 'Nộp thừa'
            WHEN base.total_paid <= 0 AND base.net_due > 0 THEN 'Chưa thanh toán'
            WHEN base.total_paid > 0 AND base.total_paid < base.net_due THEN 'Thanh toán một phần'
            ELSE 'Đã thanh toán đủ'
        END AS derived_status_label,
        CASE WHEN base.total_paid > base.net_due THEN 1 ELSE 0 END AS is_overpaid,
        CASE WHEN base.total_paid > base.net_due THEN (base.total_paid - base.net_due) ELSE 0 END AS overpaid_amount,
        GREATEST(0, base.net_due - base.total_paid) AS outstanding_amount
    FROM (
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
            COALESCE(led.payments_sum, 0) AS payments_sum,
            COALESCE(led.reversals_sum, 0) AS reversals_sum,
            COALESCE(rf.completed_refunds_sum, 0) AS completed_refunds_sum,
            COALESCE(rf.pending_refunds_sum, 0) AS pending_refunds_sum,
            COALESCE(rf.pending_refunds_count, 0) AS pending_refunds_count,
            COALESCE(led.transfer_out_sum, 0) AS transfer_out_sum,
            COALESCE(led.transfer_in_sum, 0) AS transfer_in_sum,
            (COALESCE(led.payments_sum, 0) - COALESCE(led.reversals_sum, 0) - COALESCE(rf.completed_refunds_sum, 0) - COALESCE(led.transfer_out_sum, 0) + COALESCE(led.transfer_in_sum, 0)) AS total_paid
        FROM fee_records fr
        LEFT JOIN exemptions ex ON fr.id = ex.fee_record_id
        LEFT JOIN (
            SELECT 
                fee_record_id,
                SUM(CASE WHEN entry_type = 'payment' THEN amount ELSE 0 END) AS payments_sum,
                SUM(CASE WHEN entry_type = 'reversal' THEN amount ELSE 0 END) AS reversals_sum,
                SUM(CASE WHEN entry_type = 'transfer_out' THEN amount ELSE 0 END) AS transfer_out_sum,
                SUM(CASE WHEN entry_type = 'transfer_in' THEN amount ELSE 0 END) AS transfer_in_sum
            FROM ledger_entries
            GROUP BY fee_record_id
        ) led ON fr.id = led.fee_record_id
        LEFT JOIN (
            SELECT 
                fee_record_id,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) AS completed_refunds_sum,
                SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) AS pending_refunds_sum,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending_refunds_count
            FROM refund_requests
            GROUP BY fee_record_id
        ) rf ON fr.id = rf.fee_record_id
    ) base
)";

$t2 = microtime(true);
$pdo->query("SELECT * FROM {$newSub} v")->fetchAll();
$t3 = microtime(true);
echo "New subquery 3k records: " . round(($t3 - $t2) * 1000, 2) . " ms\n";
