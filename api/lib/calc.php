<?php
/**
 * Financial Calculation Logic and Business Rules
 */

declare(strict_types=1);

/**
 * Tính học phí gốc: Số tín chỉ × Đơn giá snapshot
 */
function calc_gross(int $credits, int $pricePerCredit): int {
    $c = max(0, $credits);
    $p = max(0, $pricePerCredit);
    return (int)round($c * $p);
}

/**
 * Tính số tiền miễn giảm (percent hoặc fixed, làm tròn VND, không vượt quá học phí gốc)
 */
function calc_exemption(int $gross, string $type, $value): int {
    $g = max(0, $gross);
    if ($type === 'percent') {
        $val = max(1, min(100, (float)$value));
        return min($g, (int)round(($g * $val) / 100));
    }
    if ($type === 'fixed') {
        $val = max(0, (int)$value);
        return min($g, $val);
    }
    return 0;
}

/**
 * Tính số tiền thực phải nộp (Net Due): max(0, gross - exemption)
 */
function calc_net_due(int $gross, int $exemption): int {
    return max(0, $gross - $exemption);
}

/**
 * Xác định trạng thái thanh toán và thông tin nộp thừa
 */
function derive_payment_status(int $totalPaid, int $netDue): array {
    $p = max(0, $totalPaid);
    $n = max(0, $netDue);

    $isOverpaid = ($p > $n);
    $overpaidAmount = $isOverpaid ? ($p - $n) : 0;
    $outstanding = max(0, $n - $p);

    if ($p <= 0 && $n > 0) {
        $status = 'Unpaid';
        $statusLabel = 'Chưa thanh toán';
    } elseif ($p > 0 && $p < $n) {
        $status = 'Partially Paid';
        $statusLabel = 'Thanh toán một phần';
    } else {
        // p >= n (including net = 0 and total_paid > net)
        $status = 'Fully Paid';
        $statusLabel = 'Đã thanh toán đủ';
    }

    return [
        'status'          => $status,
        'status_label'    => $statusLabel,
        'is_overpaid'     => $isOverpaid,
        'overpaid_amount' => $overpaidAmount,
        'outstanding'     => $outstanding,
        'net_due'         => $n,
        'total_paid'      => $p,
    ];
}

/**
 * Đọc số tiền VND thành chữ tiếng Việt chuẩn cho biên lai in
 */
function number_to_vietnamese_words(int $amount): string {
    $num = $amount;
    if ($num === 0) {
        return 'Không đồng chẵn';
    }
    if ($num < 0) {
        return 'Âm ' . number_to_vietnamese_words(abs($num));
    }

    $digits = ['không', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];
    $units = ['', 'nghìn', 'triệu', 'tỷ', 'nghìn tỷ', 'triệu tỷ'];

    $readTriple = function(int $n, bool $showZeroHundreds) use ($digits): string {
        $h = (int)floor($n / 100);
        $t = (int)floor(($n % 100) / 10);
        $o = $n % 10;
        $res = '';

        if ($h > 0 || $showZeroHundreds) {
            $res .= $digits[$h] . ' trăm ';
        }

        if ($t > 1) {
            $res .= $digits[$t] . ' mươi ';
            if ($o === 1) {
                $res .= 'mốt ';
            } elseif ($o === 5) {
                $res .= 'lăm ';
            } elseif ($o > 0) {
                $res .= $digits[$o] . ' ';
            }
        } elseif ($t === 1) {
            $res .= 'mười ';
            if ($o === 5) {
                $res .= 'lăm ';
            } elseif ($o > 0) {
                $res .= $digits[$o] . ' ';
            }
        } elseif ($t === 0 && $o > 0) {
            if ($h > 0 || $showZeroHundreds) {
                $res .= 'lẻ ';
            }
            $res .= $digits[$o] . ' ';
        }

        return trim($res);
    };

    $nStr = (string)$num;
    $triples = [];
    while (strlen($nStr) > 0) {
        $triples[] = (int)substr($nStr, -3);
        $nStr = substr($nStr, 0, -3);
    }

    $words = '';
    for ($i = count($triples) - 1; $i >= 0; $i--) {
        $val = $triples[$i];
        if ($val > 0) {
            $u = $units[$i] ?? '';
            $tripleWords = $readTriple($val, $i < (count($triples) - 1));
            $words .= trim($tripleWords . ' ' . $u) . ' ';
        }
    }

    $words = trim($words);
    // Viết hoa chữ cái đầu và thêm 'đồng chẵn'
    $firstChar = mb_substr($words, 0, 1, 'UTF-8');
    $restChars = mb_substr($words, 1, null, 'UTF-8');
    return mb_strtoupper($firstChar, 'UTF-8') . $restChars . ' đồng chẵn';
}

/**
 * Trả về câu truy vấn con (subquery) tổng hợp thông tin học phí, công nợ thay thế View v_fee_record_summaries
 */
function get_fee_summary_subquery(): string {
    return "(
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
            COALESCE(p.payments_sum, 0) AS payments_sum,
            COALESCE(r.reversals_sum, 0) AS reversals_sum,
            COALESCE(rf_comp.completed_refunds_sum, 0) AS completed_refunds_sum,
            COALESCE(rf_pend.pending_refunds_sum, 0) AS pending_refunds_sum,
            (COALESCE(p.payments_sum, 0) - COALESCE(r.reversals_sum, 0) - COALESCE(rf_comp.completed_refunds_sum, 0)) AS total_paid
        FROM fee_records fr
        LEFT JOIN exemptions ex ON fr.id = ex.fee_record_id
        LEFT JOIN (
            SELECT fee_record_id, SUM(amount) AS payments_sum
            FROM ledger_entries
            WHERE entry_type = 'payment'
            GROUP BY fee_record_id
        ) p ON fr.id = p.fee_record_id
        LEFT JOIN (
            SELECT fee_record_id, SUM(amount) AS reversals_sum
            FROM ledger_entries
            WHERE entry_type = 'reversal'
            GROUP BY fee_record_id
        ) r ON fr.id = r.fee_record_id
        LEFT JOIN (
            SELECT fee_record_id, SUM(amount) AS completed_refunds_sum
            FROM refund_requests
            WHERE status = 'completed'
            GROUP BY fee_record_id
        ) rf_comp ON fr.id = rf_comp.fee_record_id
        LEFT JOIN (
            SELECT fee_record_id, SUM(amount) AS pending_refunds_sum
            FROM refund_requests
            WHERE status = 'pending'
            GROUP BY fee_record_id
        ) rf_pend ON fr.id = rf_pend.fee_record_id
    )";
}

/**
 * Lấy bản ghi tổng hợp công nợ của 1 hồ sơ học phí theo fee_record_id
 */
function fetch_fee_record_summary(PDO $pdo, int $feeRecordId): ?array {
    $sub = get_fee_summary_subquery();
    $stmt = $pdo->prepare("SELECT * FROM {$sub} v WHERE v.fee_record_id = :id");
    $stmt->execute(['id' => $feeRecordId]);
    $res = $stmt->fetch();
    return $res ?: null;
}

