<?php
/**
 * Request Validation, Input Hygiene, and Data Normalization
 */

declare(strict_types=1);

require_once __DIR__ . '/response.php';

/**
 * Read and decode JSON request body with strict size limit (<= 64KB)
 */
function get_json_input(): array {
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 65536) {
        json_error('Kích thước yêu cầu vượt quá giới hạn cho phép (64KB).', 'PAYLOAD_TOO_LARGE', 413);
    }

    $raw = file_get_contents('php://input');
    if (strlen($raw) > 65536) {
        json_error('Kích thước yêu cầu vượt quá giới hạn cho phép (64KB).', 'PAYLOAD_TOO_LARGE', 413);
    }

    // Tự động loại bỏ UTF-8 BOM nếu có (thường xuất hiện từ các client trên Windows)
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }

    if (trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_error('Dữ liệu JSON không hợp lệ.', 'INVALID_JSON', 400);
    }

    return $data;
}

/**
 * Reject unknown JSON fields (whitelist validation)
 */
function validate_allowed_fields(array $data, array $allowedFields): void {
    $extraFields = array_diff(array_keys($data), $allowedFields);
    if (!empty($extraFields)) {
        $fields = [];
        foreach ($extraFields as $f) {
            $fields[$f] = "Trường '{$f}' không được phép gửi lên.";
        }
        json_error('Yêu cầu chứa trường dữ liệu không hợp lệ.', 'INVALID_FIELDS', 400, $fields);
    }
}

/**
 * Parse and validate VND currency amount
 * Accepts: integers, "1.500.000", "1,500,000", pure digits string
 * Rejects: non-digit remnants, zero, negatives, decimals, > 999,999,999,999, empty string
 * Returns: int | null (if invalid)
 */
function parse_currency($value): ?int {
    if ($value === null || $value === '') {
        return null;
    }

    if (is_int($value)) {
        if ($value <= 0 || $value > 999999999999) {
            return null;
        }
        return $value;
    }

    if (is_float($value)) {
        // Reject floating point decimals
        return null;
    }

    if (!is_string($value)) {
        return null;
    }

    $s = trim($value);
    if ($s === '' || strpos($s, '-') !== false) {
        return null;
    }

    // Optional strip currency symbols at the end
    $s = preg_replace('/\s*(₫|đ|VND|vnd)\s*$/u', '', $s);
    $s = trim($s);

    $clean = null;
    if (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
        // Thousand separator with dot: 1.500.000
        $clean = str_replace('.', '', $s);
    } elseif (preg_match('/^\d{1,3}(,\d{3})+$/', $s)) {
        // Thousand separator with comma: 1,500,000
        $clean = str_replace(',', '', $s);
    } elseif (preg_match('/^\d+$/', $s)) {
        // Pure digits: 1500000
        $clean = $s;
    } else {
        // Contains decimals (e.g. 1.5 or 1,5), non-digit remnants (1,5abc), etc.
        return null;
    }

    // Prevent numbers with non-normalized format or > 999,999,999,999
    if (strlen($clean) > 12) {
        return null;
    }

    $num = (int)$clean;
    if ((string)$num !== ltrim($clean, '0') && $clean !== '0') {
        return null;
    }

    if ($num <= 0 || $num > 999999999999) {
        return null;
    }

    return $num;
}

/**
 * Validate and normalize student code: 6-12 alphanumeric characters, uppercase
 */
function validate_student_code(?string $code): ?string {
    if ($code === null) {
        return null;
    }
    $c = strtoupper(trim($code));
    if (!preg_match('/^[A-Z0-9]{6,12}$/', $c)) {
        return null;
    }
    return $c;
}

/**
 * Normalize Vietnamese text for diacritic-insensitive search
 */
function normalize_vietnamese(string $str): string {
    $str = mb_strtolower(trim($str), 'UTF-8');
    
    // Combining diacritical marks removal if any
    $str = preg_replace('/[\x{0300}-\x{036f}]/u', '', $str);

    $map = [
        'a' => ['á','à','ả','ã','ạ','ă','ắ','ằ','ẳ','ẵ','ặ','â','ấ','ầ','ẩ','ẫ','ậ'],
        'd' => ['đ'],
        'e' => ['é','è','ẻ','ẽ','ẹ','ê','ế','ề','ể','ễ','ệ'],
        'i' => ['í','ì','ỉ','ĩ','ị'],
        'o' => ['ó','ò','ỏ','õ','ọ','ô','ố','ồ','ổ','ỗ','ộ','ơ','ớ','ờ','ở','ỡ','ợ'],
        'u' => ['ú','ù','ủ','ũ','ụ','ư','ứ','ừ','ử','ữ','ự'],
        'y' => ['ý','ỳ','ỷ','ỹ','ỵ'],
    ];

    foreach ($map as $replacement => $chars) {
        $str = str_replace($chars, $replacement, $str);
    }

    return trim($str);
}

/**
 * Validate UTF-8 string length
 */
function validate_string_length(?string $str, int $min, int $max): bool {
    if ($str === null) {
        return $min === 0;
    }
    $len = mb_strlen(trim($str), 'UTF-8');
    return ($len >= $min && $len <= $max);
}
