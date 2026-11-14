<?php
/**
 * Audit Logging Module (Append-only)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function log_audit(
    ?int $userId,
    string $action,
    string $entity,
    ?string $entityId = null,
    ?array $payload = null,
    ?PDO $pdo = null
): void {
    if ($userId === null && isset($_SESSION['user_id'])) {
        $userId = (int)$_SESSION['user_id'];
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $payloadJson = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;

    try {
        $db = $pdo ?? get_db_connection();
        $stmt = $db->prepare(
            "INSERT INTO audit_log (user_id, action, entity, entity_id, payload, ip, created_at)
             VALUES (:user_id, :action, :entity, :entity_id, :payload, :ip, NOW())"
        );
        $stmt->execute([
            'user_id'   => $userId,
            'action'    => $action,
            'entity'    => $entity,
            'entity_id' => $entityId,
            'payload'   => $payloadJson,
            'ip'        => $ip,
        ]);
    } catch (Throwable $e) {
        // Log to system error log without breaking client operation
        error_log("Failed to write audit log: " . $e->getMessage());
    }
}
