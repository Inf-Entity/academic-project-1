<?php
/**
 * Payment Gateway Interface & Simulated Gateway Service
 *
 * NOTE: Real bank/card payment processing is not implemented.
 * This simulated gateway generates reference codes and returns success for valid requests.
 */

declare(strict_types=1);

interface PaymentGatewayInterface {
    /**
     * Process payment charge.
     *
     * @param int $amount Amount in integer VND (> 0)
     * @param string $method 'Chuyển khoản' or 'Thẻ'
     * @param array $metadata Additional context (fee_record_id, student_id, request_id, etc.)
     * @return array [
     *     'status'          => 'success' | 'failed',
     *     'transaction_ref' => ?string,
     *     'message'         => string
     * ]
     */
    public function charge(int $amount, string $method, array $metadata = []): array;
}

class SimulatedGateway implements PaymentGatewayInterface {
    private static bool $simulateFailure = false;

    /**
     * Programmatically force failure in tests.
     */
    public static function setSimulateFailure(bool $fail): void {
        self::$simulateFailure = $fail;
    }

    public static function isSimulateFailure(): bool {
        return self::$simulateFailure;
    }

    public function charge(int $amount, string $method, array $metadata = []): array {
        // Allow failure simulation via static flag, HTTP headers (including Apache mod_rewrite redirects), or metadata flag
        $headerFail = false;
        if (isset($_SERVER['HTTP_X_SIMULATE_GATEWAY_FAIL']) && $_SERVER['HTTP_X_SIMULATE_GATEWAY_FAIL'] === '1') {
            $headerFail = true;
        } elseif (isset($_SERVER['REDIRECT_HTTP_X_SIMULATE_GATEWAY_FAIL']) && $_SERVER['REDIRECT_HTTP_X_SIMULATE_GATEWAY_FAIL'] === '1') {
            $headerFail = true;
        } elseif (function_exists('getallheaders')) {
            $allHeaders = getallheaders();
            if (is_array($allHeaders)) {
                foreach ($allHeaders as $hKey => $hVal) {
                    if (strcasecmp((string)$hKey, 'X-Simulate-Gateway-Fail') === 0 && (string)$hVal === '1') {
                        $headerFail = true;
                        break;
                    }
                }
            }
        }
        $metaFail = !empty($metadata['simulate_failure']);

        if (self::$simulateFailure || $headerFail || $metaFail) {
            return [
                'status'          => 'failed',
                'transaction_ref' => null,
                'message'         => 'Giao dịch qua cổng thanh toán thất bại (giả lập).'
            ];
        }

        // Generate atomic transaction reference (max 40 chars)
        // Format: GW-YYYYMMDD-HEX (e.g. GW-20261112-A1B2C3D4E5F6)
        $ref = sprintf('GW-%s-%s', date('Ymd'), strtoupper(bin2hex(random_bytes(6))));
        if (strlen($ref) > 40) {
            $ref = substr($ref, 0, 40);
        }

        return [
            'status'          => 'success',
            'transaction_ref' => $ref,
            'message'         => 'Thanh toán thành công qua cổng thanh toán mô phỏng.'
        ];
    }
}
