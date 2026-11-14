<?php
/**
 * Shared Payment Transaction Processing & Simulation Helpers
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/calc.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/response.php';

/**
 * Check whether payment simulation is enabled via config or global flag.
 */
function is_simulation_enabled(): bool {
    if (isset($GLOBALS['OVERRIDE_SIMULATION_ENABLED'])) {
        return (bool)$GLOBALS['OVERRIDE_SIMULATION_ENABLED'];
    }

    // Allow override via HTTP header in automated tests
    if (isset($_SERVER['HTTP_X_SIMULATION_ENABLED'])) {
        return $_SERVER['HTTP_X_SIMULATION_ENABLED'] === '1' || strtolower($_SERVER['HTTP_X_SIMULATION_ENABLED']) === 'true';
    }
    if (isset($_SERVER['REDIRECT_HTTP_X_SIMULATION_ENABLED'])) {
        return $_SERVER['REDIRECT_HTTP_X_SIMULATION_ENABLED'] === '1' || strtolower($_SERVER['REDIRECT_HTTP_X_SIMULATION_ENABLED']) === 'true';
    }
    if (function_exists('getallheaders')) {
        $all = getallheaders();
        if (is_array($all)) {
            foreach ($all as $k => $v) {
                if (strcasecmp((string)$k, 'X-Simulation-Enabled') === 0) {
                    return (string)$v === '1' || strtolower((string)$v) === 'true';
                }
            }
        }
    }

    if (getenv('SIMULATION_ENABLED') !== false && getenv('SIMULATION_ENABLED') !== '') {
        return filter_var(getenv('SIMULATION_ENABLED'), FILTER_VALIDATE_BOOLEAN);
    }
    static $config = null;
    if ($config === null) {
        $configPath = dirname(__DIR__, 2) . '/config/config.php';
        $config = file_exists($configPath) ? require $configPath : [];
    }
    if (isset($config['simulation_enabled'])) {
        return (bool)$config['simulation_enabled'];
    }
    if (isset($config['app']['simulation_enabled'])) {
        return (bool)$config['app']['simulation_enabled'];
    }
    return true;
}

/**
 * Executes a payment transaction with row-level locking, overpayment checks,
 * atomic receipt numbering, ledger writing, audit logging, and request_id idempotency.
 *
 * Used by:
 * - Admin payment handler (api/handlers/payments.php)
 * - Student QR session confirmation (api/handlers/student.php)
 * - Student Card payment (api/handlers/student.php)
 * - Legacy student payment route (api/handlers/student.php)
 *
 * @param PDO $pdo Active PDO transaction connection
 * @param int $feeRecordId
 * @param int $amount
 * @param string $method 'Tiền mặt', 'Chuyển khoản', 'Thẻ', 'Mã QR', or 'Thẻ Visa/Mastercard'
 * @param string|null $entryDate YYYY-MM-DD (defaults to today)
 * @param string|null $note
 * @param string $requestId
 * @param int $userId
 * @param int|null $studentId If provided, enforces ownership by the student
 * @param string|null $gatewayRef
 * @param string $auditAction 'student_payment' or 'create_payment'
 * @param string|null $cardBrand
 * @param string|null $cardLast4
 * @return array The created or existing ledger entry record
 */
function execute_payment_transaction(
    PDO $pdo,
    int $feeRecordId,
    int $amount,
    string $method,
    ?string $entryDate,
    ?string $note,
    string $requestId,
    int $userId,
    ?int $studentId = null,
    ?string $gatewayRef = null,
    string $auditAction = 'student_payment',
    ?string $cardBrand = null,
    ?string $cardLast4 = null
): array {
    // 1. Lock fee record row
    if ($studentId !== null) {
        $feeStmt = $pdo->prepare("SELECT id, student_id FROM fee_records WHERE id = :id AND student_id = :stId FOR UPDATE");
        $feeStmt->execute(['id' => $feeRecordId, 'stId' => $studentId]);
        if (!$feeStmt->fetch()) {
            json_error('Không tìm thấy hồ sơ học phí.', 'NOT_FOUND', 404);
        }
    } else {
        $feeStmt = $pdo->prepare("SELECT id, student_id FROM fee_records WHERE id = :id FOR UPDATE");
        $feeStmt->execute(['id' => $feeRecordId]);
        if (!$feeStmt->fetch()) {
            json_error('Không tìm thấy hồ sơ học phí.', 'NOT_FOUND', 404);
        }
    }

    // 2. Idempotency check inside transaction
    $chkReq = $pdo->prepare(
        "SELECT l.*, fr.student_id 
         FROM ledger_entries l 
         JOIN fee_records fr ON l.fee_record_id = fr.id 
         WHERE l.request_id = :reqId"
    );
    $chkReq->execute(['reqId' => $requestId]);
    $existing = $chkReq->fetch();
    if ($existing) {
        if ($studentId !== null && (int)$existing['student_id'] !== $studentId) {
            json_error('Không tìm thấy giao dịch.', 'NOT_FOUND', 404);
        }
        $bStmt = $pdo->prepare("SELECT bill_no FROM bills WHERE ledger_entry_id = :lId");
        $bStmt->execute(['lId' => $existing['id']]);
        $existing['bill_no'] = $bStmt->fetchColumn() ?: null;
        return $existing;
    }

    // 3. Real-time balance and overpayment verification
    $summary = fetch_fee_record_summary($pdo, $feeRecordId);
    $netDue = (int)($summary['net_due'] ?? 0);
    $totalPaid = (int)($summary['total_paid'] ?? 0);

    if ($totalPaid > $netDue) {
        json_error('Hồ sơ đang có số tiền thừa. Vui lòng liên hệ phòng đào tạo.', 'OVERPAID_BLOCKED', 422);
    }

    $outstanding = max(0, $netDue - $totalPaid);
    if ($amount > $outstanding) {
        json_error('Số tiền vượt quá số còn nợ. Vui lòng lập yêu cầu hoàn tiền trước.', 'OVERPAYMENT_REJECTED', 422);
    }

    // 4. Atomic receipt numbering: PT-YYYYMMDD-NNNN
    $today = $entryDate ?: date('Y-m-d');
    $pdo->prepare("INSERT INTO receipt_counters (day, last_no) VALUES (:today, 0) ON DUPLICATE KEY UPDATE day = day")
        ->execute(['today' => $today]);

    $cntStmt = $pdo->prepare("SELECT last_no FROM receipt_counters WHERE day = :today FOR UPDATE");
    $cntStmt->execute(['today' => $today]);
    $lastNo = (int)$cntStmt->fetchColumn();

    $nextNo = $lastNo + 1;
    $pdo->prepare("UPDATE receipt_counters SET last_no = :nextNo WHERE day = :today")
        ->execute(['nextNo' => $nextNo, 'today' => $today]);

    $receiptNo = sprintf('PT-%s-%04d', str_replace('-', '', $today), $nextNo);

    // 5. Insert ledger entry
    $insStmt = $pdo->prepare(
        "INSERT INTO ledger_entries (
            fee_record_id, entry_type, amount, method, entry_date, 
            receipt_no, reverses_entry_id, reason, note, request_id, gateway_ref, created_by, created_at
        ) VALUES (
            :frId, 'payment', :amount, :method, :entryDate,
            :receiptNo, NULL, NULL, :note, :reqId, :gwRef, :userId, NOW()
        )"
    );

    try {
        $insStmt->execute([
            'frId'      => $feeRecordId,
            'amount'    => $amount,
            'method'    => $method,
            'entryDate' => $today,
            'receiptNo' => $receiptNo,
            'note'      => $note,
            'reqId'     => $requestId,
            'gwRef'     => $gatewayRef,
            'userId'    => $userId
        ]);
        $entryId = (int)$pdo->lastInsertId();
    } catch (PDOException $pe) {
        if ($pe->getCode() === '23000') {
            $chk = $pdo->prepare("SELECT * FROM ledger_entries WHERE request_id = :reqId");
            $chk->execute(['reqId' => $requestId]);
            $existingRace = $chk->fetch();
            if ($existingRace) {
                $bStmt = $pdo->prepare("SELECT bill_no FROM bills WHERE ledger_entry_id = :lId");
                $bStmt->execute(['lId' => $existingRace['id']]);
                $existingRace['bill_no'] = $bStmt->fetchColumn() ?: null;
                return $existingRace;
            }
        }
        throw $pe;
    }

    // 5b. Generate student bill (Scope: student account payments only)
    $billNo = null;
    if ($auditAction === 'student_payment' || $studentId !== null) {
        $billNo = generate_student_bill(
            $pdo,
            $entryId,
            $feeRecordId,
            $amount,
            $totalPaid,
            $netDue,
            $method,
            $today,
            $receiptNo,
            $cardBrand,
            $cardLast4
        );
    }

    // 6. Audit log
    log_audit($userId, $auditAction, 'ledger_entries', (string)$entryId, [
        'fee_record_id' => $feeRecordId,
        'receipt_no'    => $receiptNo,
        'amount'        => $amount,
        'method'        => $method,
        'gateway_ref'   => $gatewayRef,
        'request_id'    => $requestId,
        'bill_no'       => $billNo
    ], $pdo);

    $stmt = $pdo->prepare("SELECT l.*, b.bill_no FROM ledger_entries l LEFT JOIN bills b ON l.id = b.ledger_entry_id WHERE l.id = :id");
    $stmt->execute(['id' => $entryId]);
    return $stmt->fetch();
}

/**
 * Generate a student bill record and snapshot atomically inside the payment transaction.
 */
function generate_student_bill(
    PDO $pdo,
    int $ledgerEntryId,
    int $feeRecordId,
    int $amount,
    int $totalPaidBefore,
    int $netDue,
    string $method,
    string $entryDate,
    string $receiptNo,
    ?string $cardBrand = null,
    ?string $cardLast4 = null
): string {
    $today = $entryDate ?: date('Y-m-d');

    // 1. Bill counter atomic generation: HD-YYYYMMDD-NNNN
    $pdo->prepare("INSERT INTO bill_counters (day, last_no) VALUES (:today, 0) ON DUPLICATE KEY UPDATE day = day")
        ->execute(['today' => $today]);

    $cntStmt = $pdo->prepare("SELECT last_no FROM bill_counters WHERE day = :today FOR UPDATE");
    $cntStmt->execute(['today' => $today]);
    $lastNo = (int)$cntStmt->fetchColumn();

    $nextNo = $lastNo + 1;
    $pdo->prepare("UPDATE bill_counters SET last_no = :nextNo WHERE day = :today")
        ->execute(['nextNo' => $nextNo, 'today' => $today]);

    $billNo = sprintf('HD-%s-%04d', str_replace('-', '', $today), $nextNo);

    // 2. Fetch student, semester, and exemption info for immutable snapshot
    $stmt = $pdo->prepare(
        "SELECT fr.id AS fee_record_id, fr.registered_credits, fr.rate_snapshot, fr.gross_tuition,
                s.id AS student_id, s.student_code, s.full_name, s.class_name,
                sem.id AS semester_id, sem.term_no, sem.academic_year_start,
                ex.type AS ex_type, ex.value AS ex_value, ex.reason AS ex_reason
         FROM fee_records fr
         JOIN students s ON fr.student_id = s.id
         JOIN semesters sem ON fr.semester_id = sem.id
         LEFT JOIN exemptions ex ON fr.id = ex.fee_record_id
         WHERE fr.id = :frId"
    );
    $stmt->execute(['frId' => $feeRecordId]);
    $info = $stmt->fetch();

    $gross = (int)($info['gross_tuition'] ?? 0);
    $exAmt = 0;
    if (!empty($info['ex_type'])) {
        $exAmt = calc_exemption($gross, $info['ex_type'], (float)$info['ex_value']);
    }

    $semesterLabel = sprintf(
        'Học kỳ %d - Năm học %d-%d',
        (int)$info['term_no'],
        (int)$info['academic_year_start'],
        (int)$info['academic_year_start'] + 1
    );

    $totalPaidAfter = $totalPaidBefore + $amount;
    $remainingBalance = max(0, $netDue - $totalPaidAfter);
    $nowStr = date('Y-m-d H:i:s');

    $snapshot = [
        'bill_no'             => $billNo,
        'created_at'          => $nowStr,
        'school_name'         => '[Tên trường]',
        'school_placeholder'  => '[Tên trường]',
        'student_code'        => $info['student_code'] ?? '',
        'full_name'           => $info['full_name'] ?? '',
        'class'               => $info['class_name'] ?? '',
        'class_name'          => $info['class_name'] ?? '',
        'semester_label'      => $semesterLabel,
        'registered_credits'  => (int)($info['registered_credits'] ?? 0),
        'gross_tuition'       => $gross,
        'exemption_amount'    => $exAmt,
        'exemption_reason'    => $info['ex_reason'] ?? null,
        'net_due'             => $netDue,
        'total_paid_before'   => $totalPaidBefore,
        'this_payment_amount' => $amount,
        'amount'              => $amount,
        'amount_paid'         => $amount,
        'total_paid_after'    => $totalPaidAfter,
        'remaining_balance'   => $remainingBalance,
        'balance_after'       => $remainingBalance,
        'method_label'        => $method,
        'method'              => $method,
        'gateway_brand'       => $cardBrand,
        'gateway_last4'       => $cardLast4,
        'receipt_number'      => $receiptNo,
        'receipt_no'          => $receiptNo,
        'payment_timestamp'   => $nowStr,
        'student' => [
            'id'           => (int)($info['student_id'] ?? 0),
            'student_code' => $info['student_code'] ?? '',
            'full_name'    => $info['full_name'] ?? '',
            'class_name'   => $info['class_name'] ?? ''
        ],
        'semester' => [
            'id'            => (int)($info['semester_id'] ?? 0),
            'semester_code' => sprintf('HK%d_%d_%d', (int)$info['term_no'], (int)$info['academic_year_start'], (int)$info['academic_year_start'] + 1),
            'semester_name' => sprintf('Học kỳ %d (%d-%d)', (int)$info['term_no'], (int)$info['academic_year_start'], (int)$info['academic_year_start'] + 1),
            'academic_year' => sprintf('%d-%d', (int)$info['academic_year_start'], (int)$info['academic_year_start'] + 1),
            'term_no'       => (int)($info['term_no'] ?? 0)
        ],
        'fee_record' => [
            'id'                 => $feeRecordId,
            'registered_credits' => (int)($info['registered_credits'] ?? 0),
            'tuition_rate'       => (int)($info['rate_snapshot'] ?? 0),
            'gross_amount'       => $gross,
            'exemption_amount'   => $exAmt,
            'net_amount'         => $netDue,
            'total_paid_before'  => $totalPaidBefore,
            'amount_paid'        => $amount,
            'total_paid_after'   => $totalPaidAfter,
            'balance_after'      => $remainingBalance
        ],
        'payment' => [
            'ledger_entry_id' => $ledgerEntryId,
            'method'          => $method,
            'reference_code'  => $receiptNo,
            'paid_at'         => $nowStr,
            'card_brand'      => $cardBrand,
            'card_last4'      => $cardLast4
        ]
    ];

    $insBill = $pdo->prepare(
        "INSERT INTO bills (ledger_entry_id, bill_no, snapshot, created_at)
         VALUES (:lId, :bNo, :snap, NOW())"
    );
    $insBill->execute([
        'lId'  => $ledgerEntryId,
        'bNo'  => $billNo,
        'snap' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)
    ]);

    return $billNo;
}
