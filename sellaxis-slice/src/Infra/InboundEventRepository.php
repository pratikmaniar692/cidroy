<?php

declare(strict_types=1);

namespace Forgeline\Infra;

use PDO;

/**
 * The inbox. This single table/repository answers three of the brief's
 * hardest questions:
 *
 *   - Case 2 (duplicate delivery_id) / case 13 (retry of an already-
 *     completed event): dedup is a UNIQUE constraint on delivery_id at the
 *     database level, not a cache. See README for why this matters --
 *     a cache (Redis set) can be evicted; a unique constraint cannot
 *     silently disappear.
 *   - Case 3 (out-of-order events): events that fail the state machine's
 *     validity check are HELD, not dropped and not force-applied. Holding
 *     is itself just a status on this same row.
 *   - Case 10 (malformed item in a batch): unparseable events are
 *     quarantined here with the parse error recorded, so the rest of a
 *     batch can proceed independently.
 */
final class InboundEventRepository
{
    public function __construct(private PDO $db) {}

    /**
     * Attempt to durably record a delivery. Returns false if this exact
     * delivery_id has already been recorded (case 2/13's dedup guarantee),
     * true if this is the first time we've seen it.
     */
    public function recordDelivery(
        string $deliveryId,
        string $eventId,
        string $eventType,
        ?\DateTimeImmutable $occurredAt,
        ?string $orderRef,
        ?string $lineRef,
        array $rawPayload,
        string $status = 'received',
        ?string $error = null
    ): bool {
        $stmt = $this->db->prepare(
            'INSERT INTO inbound_events
                (delivery_id, event_id, event_type, occurred_at, order_ref, line_ref, raw_payload, status, error)
             VALUES (:delivery_id, :event_id, :event_type, :occurred_at, :order_ref, :line_ref, :raw_payload, :status, :error)
             ON CONFLICT (delivery_id) DO NOTHING'
        );
        $stmt->execute([
            'delivery_id' => $deliveryId,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'occurred_at' => $occurredAt?->format('c'),
            'order_ref' => $orderRef,
            'line_ref' => $lineRef,
            'raw_payload' => json_encode($rawPayload),
            'status' => $status,
            'error' => $error,
        ]);
        // ON CONFLICT DO NOTHING means rowCount() is 0 if it was a duplicate.
        return $stmt->rowCount() > 0;
    }

    /**
     * Has this logical event (by event_id, stable across redeliveries)
     * already been successfully processed? This is what makes case 13
     * (retry of an already-completed event) a clean no-op: a NEW
     * delivery_id for an event_id we've already marked 'processed' is
     * recorded (so we have a full audit trail of every delivery attempt)
     * but is never re-applied to order/line state.
     */
    public function alreadyProcessed(string $eventId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM inbound_events WHERE event_id = :event_id AND status = 'processed' LIMIT 1"
        );
        $stmt->execute(['event_id' => $eventId]);
        return (bool) $stmt->fetchColumn();
    }

    public function markProcessed(string $deliveryId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE inbound_events SET status = 'processed', processed_at = now(), attempts = attempts + 1
             WHERE delivery_id = :id"
        );
        $stmt->execute(['id' => $deliveryId]);
    }

    public function markHeld(string $deliveryId, string $reason): void
    {
        $stmt = $this->db->prepare(
            "UPDATE inbound_events SET status = 'held', error = :error, attempts = attempts + 1
             WHERE delivery_id = :id"
        );
        $stmt->execute(['id' => $deliveryId, 'error' => $reason]);
    }

    public function markQuarantined(string $deliveryId, string $reason): void
    {
        $stmt = $this->db->prepare(
            "UPDATE inbound_events SET status = 'quarantined', error = :error, attempts = attempts + 1
             WHERE delivery_id = :id"
        );
        $stmt->execute(['id' => $deliveryId, 'error' => $reason]);
    }

    /**
     * Fetch held events for a given order_ref, ordered by occurred_at so
     * re-checking happens in logical order if multiple events are held
     * simultaneously. This is called after every successful state
     * transition (see OrderProcessor) to give held events another chance.
     */
    public function findHeldForOrder(string $orderRef): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM inbound_events WHERE order_ref = :order_ref AND status = 'held'
             ORDER BY occurred_at ASC"
        );
        $stmt->execute(['order_ref' => $orderRef]);
        return $stmt->fetchAll();
    }

    public function findByDeliveryId(string $deliveryId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM inbound_events WHERE delivery_id = :id');
        $stmt->execute(['id' => $deliveryId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** For the reconciliation/exception API. */
    public function listByStatus(string $status, int $limit = 200): array
    {
        $stmt = $this->db->prepare(
            'SELECT delivery_id, event_id, event_type, occurred_at, order_ref, line_ref, error, received_at, attempts
             FROM inbound_events WHERE status = :status ORDER BY received_at DESC LIMIT :limit'
        );
        $stmt->bindValue('status', $status);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countsByStatus(): array
    {
        $stmt = $this->db->query('SELECT status, COUNT(*) AS n FROM inbound_events GROUP BY status');
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['status']] = (int) $row['n'];
        }
        return $out;
    }
}
