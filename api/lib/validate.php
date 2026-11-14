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
 * Normalize text to Unicode NFC (canonical composition).
 * Converts combining diacritics to precomposed Vietnamese characters.
 */
function normalize_nfc(string $str): string {
    if (function_exists('normalizer_normalize')) {
        $norm = normalizer_normalize($str, Normalizer::FORM_C);
        if ($norm !== false) {
            return $norm;
        }
    }

    // Comprehensive Fallback: Two-stage combining mark composition
    // Stage 1: Base vowel + hat/horn/breve
    $hats = [
        "a\xCC\x82" => 'â', "A\xCC\x82" => 'Â',
        "a\xCC\x86" => 'ă', "A\xCC\x86" => 'Ă',
        "e\xCC\x82" => 'ê', "E\xCC\x82" => 'Ê',
        "o\xCC\x82" => 'ô', "O\xCC\x82" => 'Ô',
        "o\xCC\x9B" => 'ơ', "O\xCC\x9B" => 'Ơ',
        "u\xCC\x9B" => 'ư', "U\xCC\x9B" => 'Ư',
        "d\xCC\x90" => 'đ', "D\xCC\x90" => 'Đ',
        "d\xCC\xB5" => 'đ', "D\xCC\xB5" => 'Đ',
    ];
    $str = strtr($str, $hats);

    // Stage 2: Compose tone marks (Grave U+0300, Acute U+0301, Hook U+0309, Tilde U+0303, Dot Below U+0323)
    $tones = [
        // a
        "a\xCC\x80" => 'à', "a\xCC\x81" => 'á', "a\xCC\x89" => 'ả', "a\xCC\x83" => 'ã', "a\xCC\xA3" => 'ạ',
        "A\xCC\x80" => 'À', "A\xCC\x81" => 'Á', "A\xCC\x89" => 'Ả', "A\xCC\x83" => 'Ã', "A\xCC\xA3" => 'Ạ',
        // ă
        "ă\xCC\x80" => 'ằ', "ă\xCC\x81" => 'ắ', "ă\xCC\x89" => 'ẳ', "ă\xCC\x83" => 'ẵ', "ă\xCC\xA3" => 'ặ',
        "Ă\xCC\x80" => 'Ằ', "Ă\xCC\x81" => 'Ắ', "Ă\xCC\x89" => 'Ẳ', "Ă\xCC\x83" => 'Ẵ', "Ă\xCC\xA3" => 'Ặ',
        // â
        "â\xCC\x80" => 'ầ', "â\xCC\x81" => 'ấ', "â\xCC\x89" => 'ẩ', "â\xCC\x83" => 'ẫ', "â\xCC\xA3" => 'ậ',
        "Â\xCC\x80" => 'Ầ', "Â\xCC\x81" => 'Ấ', "Â\xCC\x89" => 'Ẩ', "Â\xCC\x83" => 'Ẫ', "Â\xCC\xA3" => 'Ậ',
        // e
        "e\xCC\x80" => 'è', "e\xCC\x81" => 'é', "e\xCC\x89" => 'ẻ', "e\xCC\x83" => 'ẽ', "e\xCC\xA3" => 'ẹ',
        "E\xCC\x80" => 'È', "E\xCC\x81" => 'É', "E\xCC\x89" => 'Ẻ', "E\xCC\x83" => 'Ẽ', "E\xCC\xA3" => 'Ẹ',
        // ê
        "ê\xCC\x80" => 'ề', "ê\xCC\x81" => 'ế', "ê\xCC\x89" => 'ể', "ê\xCC\x83" => 'ễ', "ê\xCC\xA3" => 'ệ',
        "Ê\xCC\x80" => 'Ề', "Ê\xCC\x81" => 'Ế', "Ê\xCC\x89" => 'Ể', "Ê\xCC\x83" => 'Ễ', "Ê\xCC\xA3" => 'Ệ',
        // i
        "i\xCC\x80" => 'ì', "i\xCC\x81" => 'í', "i\xCC\x89" => 'ỉ', "i\xCC\x83" => 'ĩ', "i\xCC\xA3" => 'ị',
        "I\xCC\x80" => 'Ì', "I\xCC\x81" => 'Í', "I\xCC\x89" => 'Ỉ', "I\xCC\x83" => 'Ĩ', "I\xCC\xA3" => 'Ị',
        // o
        "o\xCC\x80" => 'ò', "o\xCC\x81" => 'ó', "o\xCC\x89" => 'ỏ', "o\xCC\x83" => 'õ', "o\xCC\xA3" => 'ọ',
        "O\xCC\x80" => 'Ò', "O\xCC\x81" => 'Ó', "O\xCC\x89" => 'Ỏ', "O\xCC\x83" => 'Õ', "O\xCC\xA3" => 'Ọ',
        // ô
        "ô\xCC\x80" => 'ồ', "ô\xCC\x81" => 'ố', "ô\xCC\x89" => 'ổ', "ô\xCC\x83" => 'ỗ', "ô\xCC\xA3" => 'ộ',
        "Ô\xCC\x80" => 'Ồ', "Ô\xCC\x81" => 'Ố', "Ô\xCC\x89" => 'Ổ', "Ô\xCC\x83" => 'Ỗ', "Ô\xCC\xA3" => 'Ộ',
        // ơ
        "ơ\xCC\x80" => 'ờ', "ơ\xCC\x81" => 'ớ', "ơ\xCC\x89" => 'ở', "ơ\xCC\x83" => 'ỡ', "ơ\xCC\xA3" => 'ợ',
        "Ơ\xCC\x80" => 'Ờ', "Ơ\xCC\x81" => 'Ớ', "Ơ\xCC\x89" => 'Ở', "Ơ\xCC\x83" => 'Ỡ', "Ơ\xCC\xA3" => 'Ợ',
        // u
        "u\xCC\x80" => 'ù', "u\xCC\x81" => 'ú', "u\xCC\x89" => 'ủ', "u\xCC\x83" => 'ũ', "u\xCC\xA3" => 'ụ',
        "U\xCC\x80" => 'Ù', "U\xCC\x81" => 'Ú', "U\xCC\x89" => 'Ủ', "U\xCC\x83" => 'Ũ', "U\xCC\xA3" => 'Ụ',
        // ư
        "ư\xCC\x80" => 'ừ', "ư\xCC\x81" => 'ứ', "ư\xCC\x89" => 'ử', "ư\xCC\x83" => 'ữ', "ư\xCC\xA3" => 'ự',
        "Ư\xCC\x80" => 'Ừ', "Ư\xCC\x81" => 'Ứ', "Ư\xCC\x89" => 'Ử', "Ư\xCC\x83" => 'Ữ', "Ư\xCC\xA3" => 'Ự',
        // y
        "y\xCC\x80" => 'ỳ', "y\xCC\x81" => 'ý', "y\xCC\x89" => 'ỷ', "y\xCC\x83" => 'ỹ', "y\xCC\xA3" => 'ỵ',
        "Y\xCC\x80" => 'Ỳ', "Y\xCC\x81" => 'Ý', "Y\xCC\x89" => 'Ỷ', "Y\xCC\x83" => 'Ỹ', "Y\xCC\xA3" => 'Ỵ',
    ];

    return strtr($str, $tones);
}

/**
 * Parse and validate VND currency amount
 * Accepts: integers, "1.500.000", "1,500,000", "1 500 000", "1500000 đ", "₫1.500.000"
 * Rejects: non-digit remnants (1,5abc), zero, negatives, decimals (1.5), 1e6, non-ASCII digits (٣٠٠), > 999,999,999,999, empty input
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
    if ($s === '' || str_contains($s, '-') || stripos($s, 'e') !== false) {
        return null;
    }

    // Strip allowed currency symbol prefix (₫, đ, VND, vnd)
    $s = preg_replace('/^(₫|đ|VND|vnd)\s*/ui', '', $s);
    // Strip allowed currency symbol suffix (₫, đ, VND, vnd)
    $s = preg_replace('/\s*(₫|đ|VND|vnd)$/ui', '', $s);
    $s = trim($s);

    if ($s === '') {
        return null;
    }

    // Strict ASCII digits and separators check
    // Ensure string only consists of ASCII digits [0-9], dots, commas, spaces
    if (!preg_match('/^[0-9\s.,]+$/', $s)) {
        return null;
    }

    $clean = null;
    if (preg_match('/^[0-9]{1,3}(\.[0-9]{3})+$/', $s)) {
        // Thousand separator with dot: 1.500.000
        $clean = str_replace('.', '', $s);
    } elseif (preg_match('/^[0-9]{1,3}(,[0-9]{3})+$/', $s)) {
        // Thousand separator with comma: 1,500,000
        $clean = str_replace(',', '', $s);
    } elseif (preg_match('/^[0-9]{1,3}( [0-9]{3})+$/', $s)) {
        // Thousand separator with space: 1 500 000
        $clean = str_replace(' ', '', $s);
    } elseif (preg_match('/^[0-9]+$/', $s)) {
        // Pure digits: 1500000
        $clean = $s;
    } else {
        // Contains decimals (e.g. 1.5 or 1,5), invalid groupings, non-digit remnants, etc.
        return null;
    }

    // Prevent values > 12 digits (999,999,999,999)
    if (strlen($clean) > 12) {
        return null;
    }

    // Check for leading zeroes (e.g. 0100)
    if (strlen($clean) > 1 && $clean[0] === '0') {
        return null;
    }

    $num = (int)$clean;
    if ($num <= 0 || $num > 999999999999) {
        return null;
    }

    return $num;
}

/**
 * Validate and normalize student code: 6-12 alphanumeric characters, uppercase.
 * Rejects spaces, punctuation, symbols.
 */
function validate_student_code(?string $code): ?string {
    if ($code === null) {
        return null;
    }
    $c = trim($code);
    if (!preg_match('/^[A-Za-z0-9]{6,12}$/', $c)) {
        return null;
    }
    return strtoupper($c);
}

/**
 * Validate full name:
 * 1-100 characters counted with mb_strlen, Unicode allowed,
 * rejects control characters, rejects names made only of digits or symbols,
 * accepts names with combining diacritics (NFC-normalized).
 */
function validate_full_name(?string $name, ?string &$errorMsg = null): ?string {
    if ($name === null) {
        $errorMsg = 'Họ và tên không được để trống.';
        return null;
    }

    $n = trim($name);
    if ($n === '') {
        $errorMsg = 'Họ và tên không được để trống.';
        return null;
    }

    // Check for control characters (\x00-\x1F, \x7F-\x9F)
    if (preg_match('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', $n)) {
        $errorMsg = 'Họ và tên chứa ký tự điều khiển không hợp lệ.';
        return null;
    }

    $nfc = normalize_nfc($n);
    $len = mb_strlen($nfc, 'UTF-8');

    if ($len > 100) {
        $errorMsg = 'Họ và tên không được để trống và tối đa 100 ký tự.';
        return null;
    }

    // Reject names made only of digits or symbols (must contain at least one letter)
    if (!preg_match('/\p{L}/u', $nfc)) {
        $errorMsg = 'Họ và tên không hợp lệ (không được chỉ gồm chữ số hoặc ký hiệu).';
        return null;
    }

    return $nfc;
}

/**
 * Parse and validate calendar date (accepts dd/mm/yyyy and yyyy-mm-dd).
 * Returns ISO string 'YYYY-MM-DD' on success, false on invalid date, null if empty/null.
 */
function parse_and_validate_date(?string $str): string|false|null {
    if ($str === null) {
        return null;
    }
    $s = trim($str);
    if ($s === '') {
        return null;
    }

    // dd/mm/yyyy or d/m/yyyy
    if (preg_match('/^([0-9]{1,2})\/([0-9]{1,2})\/([0-9]{4})$/', $s, $m)) {
        $day = (int)$m[1];
        $month = (int)$m[2];
        $year = (int)$m[3];
        if (!checkdate($month, $day, $year)) {
            return false;
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    // yyyy-mm-dd or yyyy-m-d
    if (preg_match('/^([0-9]{4})-([0-9]{1,2})-([0-9]{1,2})$/', $s, $m)) {
        $year = (int)$m[1];
        $month = (int)$m[2];
        $day = (int)$m[3];
        if (!checkdate($month, $day, $year)) {
            return false;
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    return false;
}

/**
 * Check if a search query is empty or contains only diacritics / whitespace.
 */
function is_search_query_empty_or_diacritics_only(?string $q): bool {
    if ($q === null) {
        return true;
    }
    $trimmed = trim($q);
    if ($trimmed === '') {
        return true;
    }
    // Remove all whitespace, Unicode combining marks \p{M}, and common accent symbols
    $cleaned = preg_replace('/[\s\p{M}\^~`´¨ˊˋ˜]+/u', '', $trimmed);
    return ($cleaned === '');
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
 * Generate normalized name search string with spelling variations (e.g. oa -> o).
 */
function generate_name_search(string $name): string {
    $norm = normalize_vietnamese($name);
    $variants = [$norm];

    // Support oa -> o variant (e.g. doan -> don so "don" matches "Đoàn")
    $noOa = preg_replace('/oa/u', 'o', $norm);
    if ($noOa !== $norm) {
        $variants[] = $noOa;
    }

    return implode(' ', array_unique($variants));
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

/**
 * Generate standard RFC 4122 UUID v4
 */
function generate_uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Validate card number using Luhn algorithm (Mod 10).
 */
function validate_luhn(string $number): bool {
    $clean = preg_replace('/\D/', '', $number);
    if ($clean === '' || strlen($clean) < 13 || strlen($clean) > 19) {
        return false;
    }
    $sum = 0;
    $alt = false;
    for ($i = strlen($clean) - 1; $i >= 0; $i--) {
        $digit = (int)$clean[$i];
        if ($alt) {
            $digit *= 2;
            if ($digit > 9) {
                $digit -= 9;
            }
        }
        $sum += $digit;
        $alt = !$alt;
    }
    return ($sum % 10 === 0);
}

/**
 * Validate card expiration date (MM/YY or MM/YYYY) against server date.
 */
function validate_card_expiry(string $expiry): bool {
    $expiry = trim($expiry);
    if (!preg_match('#^(0[1-9]|1[0-2])\s*/\s*(\d{2}|\d{4})$#', $expiry, $m)) {
        return false;
    }
    $month = (int)$m[1];
    $yearRaw = (int)$m[2];
    $year = ($yearRaw < 100) ? 2000 + $yearRaw : $yearRaw;

    $currentYear = (int)date('Y');
    $currentMonth = (int)date('n');

    if ($year < $currentYear) {
        return false;
    }
    if ($year === $currentYear && $month < $currentMonth) {
        return false;
    }
    return true;
}

/**
 * Validate cardholder name: max 100 characters, letters and spaces only.
 */
function validate_cardholder_name(string $name): bool {
    $name = trim($name);
    $len = mb_strlen($name, 'UTF-8');
    if ($len < 1 || $len > 100) {
        return false;
    }
    // Letters (including accented Latin/Vietnamese) and spaces only
    return (bool)preg_match('/^[\p{L}\s]+$/u', $name);
}

/**
 * Validate card CVV / CVC: 3 or 4 digits.
 */
function validate_card_cvv(string $cvv): bool {
    $cvv = trim($cvv);
    return (bool)preg_match('/^\d{3,4}$/', $cvv);
}



