<?php

declare(strict_types=1);

namespace Forgeline\Infra;

use PDO;

/**
 * The outbox. Every outbound call to the ERP (and, in a fuller build,
 * ShipBridge) is recorded here BEFORE it's attempted, with a caller-chosen
 * idempotency key. A separate relay (Console/ProcessOutbox.php) delivers
 * these on its own schedule, independent of order-creation throughput.
 *
 * This is the direct fix for stub case 4: the ERP can commit a PO and then
 * time out the HTTP response. If the caller retries WITHOUT reusing the
 * same Idempotency-Key, a duplicate PO is created -- the stub says this
 * explicitly. By keying every outbox row on a stable idempotency key (here,
 * deterministically derived from the order_ref, so a retry of the same
 * logical operation always reuses the same key) and reusing that key on
 * every delivery attempt, a 504-after-success is handled correctly: the
 * relay's next attempt with the SAME key returns the ERP's original result
 * instead of creating a second PO, because the ERP stub itself dedupes on
 * that key (see fake-sellaxis/server.php).
 */
final class OutboxRepository
{
    public function __construct(private PDO $db) {}

    public function enqueue(string $idempotencyKey, string $target, string $orderRef, array $payload): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO outbox_events (idempotency_key, target, order_ref, payload)
             VALUES (:key, :target, :ref, :payload)
             ON CONFLICT (idempotency_key) DO NOTHING'
        );
        $stmt->execute([
            'key' => $idempotencyKey, 'target' => $target, 'ref' => $orderRef,
            'payload' => json_encode($payload),
        ]);
        return $stmt->rowCount() > 0;
    }

    public function findPending(int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM outbox_events WHERE status IN ('pending', 'failed_retryable')
             ORDER BY created_at ASC LIMIT :limit"
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function markDelivered(string $idempotencyKey): void
    {
        $stmt = $this->db->prepare(
            "UPDATE outbox_events SET status = 'delivered', delivered_at = now(), attempts = attempts + 1
             WHERE idempotency_key = :key"
        );
        $stmt->execute(['key' => $idempotencyKey]);
    }

    public function markFailedRetryable(string $idempotencyKey, string $error): void
    {
        $stmt = $this->db->prepare(
            "UPDATE outbox_events SET status = 'failed_retryable', last_error = :err, attempts = attempts + 1
             WHERE idempotency_key = :key"
        );
        $stmt->execute(['key' => $idempotencyKey, 'err' => $error]);
    }

    public function findByOrderRef(string $orderRef): array
    {
        $stmt = $this->db->prepare('SELECT * FROM outbox_events WHERE order_ref = :ref');
        $stmt->execute(['ref' => $orderRef]);
        return $stmt->fetchAll();
    }
}
