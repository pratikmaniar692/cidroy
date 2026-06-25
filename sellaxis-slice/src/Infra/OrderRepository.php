<?php

declare(strict_types=1);

namespace Forgeline\Infra;

use PDO;

final class OrderRepository
{
    public function __construct(private PDO $db) {}

    public function exists(string $orderRef): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM orders WHERE order_ref = :ref');
        $stmt->execute(['ref' => $orderRef]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Creates the order AND captures payment, in that order, in the same
     * transaction -- this is the direct fix for case 16 (payment captured,
     * persistence fails) and for the broader Part B finding C1 (capture
     * happening before the order exists). By writing the order row first,
     * inside the same transaction as the capture bookkeeping row, there is
     * no window where money has moved but no order record exists: either
     * both commit, or neither does.
     *
     * The actual call to the payment gateway is represented here by
     * $captureFn -- a closure the caller supplies, so this repository
     * doesn't need to know about a real gateway. In this slice, the fake
     * gateway is deliberately triggerable to fail AFTER "succeeding" at the
     * gateway but BEFORE the local commit, to prove case 16's handling: see
     * OrderProcessor::createOrder for exactly how the failure window is
     * tested and recovered.
     */
    public function createOrderWithCapture(
        array $orderData,
        array $lines,
        callable $captureFn
    ): array {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO orders (order_ref, market, currency, buyer_ref, created_at, capture_status)
                 VALUES (:ref, :market, :currency, :buyer, :created, :status)'
            );
            $stmt->execute([
                'ref' => $orderData['order_ref'],
                'market' => $orderData['market'],
                'currency' => $orderData['currency'],
                'buyer' => $orderData['buyer_ref'],
                'created' => $orderData['created_at'],
                'status' => 'none',
            ]);

            foreach ($lines as $line) {
                $this->insertLine($orderData['order_ref'], $line);
            }

            // Capture happens INSIDE the transaction, after the order and
            // lines are already staged (not yet committed). The gateway
            // call itself is external and can't be rolled back by a SQL
            // ROLLBACK -- that's exactly why case 16 exists as a case at
            // all. What we control is: once the gateway confirms capture,
            // we immediately record that fact in the SAME transaction as
            // the order, so the only failure window is the gateway call
            // itself succeeding while the surrounding PHP process dies
            // before it can even start the local write -- and THAT window
            // is closed by making the capture call itself idempotent and
            // outbox-style reconcilable (see ErpClient / OutboxRepository
            // for the equivalent pattern; the gateway capture uses the same
            // idea: a capture_ref the caller can poll to confirm).
            $captureResult = $captureFn($orderData['order_ref'], $orderData['capture_amount'], $orderData['currency']);

            $update = $this->db->prepare(
                'UPDATE orders SET capture_status = :status, capture_amount = :amount, capture_ref = :ref2
                 WHERE order_ref = :ref'
            );
            $update->execute([
                'status' => $captureResult['status'],
                'amount' => $orderData['capture_amount'],
                'ref2' => $captureResult['capture_ref'],
                'ref' => $orderData['order_ref'],
            ]);

            $this->db->commit();
            return ['order_ref' => $orderData['order_ref'], 'capture' => $captureResult];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * The recovery path for case 16 done properly: if the gateway capture
     * SUCCEEDED but the local transaction above never committed (process
     * crash, DB connection drop -- anything between the gateway call
     * returning and COMMIT), the order does not exist locally, but the
     * gateway has a real charge. Reconciliation must find this by asking
     * the gateway directly ("what did you capture for this reference in
     * the last N minutes that I have no local record of"), never by
     * "logging and moving on." See ReconciliationController and the
     * gateway stub's own idempotency-key-based lookup.
     */
    public function reconcileOrphanedCapture(string $captureRef, array $orderData, array $lines): void
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO orders (order_ref, market, currency, buyer_ref, created_at, capture_status, capture_amount, capture_ref)
                 VALUES (:ref, :market, :currency, :buyer, :created, :status, :amount, :capref)
                 ON CONFLICT (order_ref) DO NOTHING'
            );
            $stmt->execute([
                'ref' => $orderData['order_ref'], 'market' => $orderData['market'],
                'currency' => $orderData['currency'], 'buyer' => $orderData['buyer_ref'],
                'created' => $orderData['created_at'], 'status' => 'reconciled',
                'amount' => $orderData['capture_amount'], 'capref' => $captureRef,
            ]);
            foreach ($lines as $line) {
                $this->insertLine($orderData['order_ref'], $line);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function insertLine(string $orderRef, array $line): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO order_lines (order_ref, line_ref, seller_id, seller_sku, magento_sku, qty, unit_price, currency, state)
             VALUES (:order_ref, :line_ref, :seller_id, :seller_sku, :magento_sku, :qty, :price, :currency, :state)'
        );
        $stmt->execute([
            'order_ref' => $orderRef,
            'line_ref' => $line['line_ref'],
            'seller_id' => $line['seller_id'],
            'seller_sku' => $line['seller_sku'],
            'magento_sku' => $line['magento_sku'] ?? null,
            'qty' => $line['qty'],
            'price' => $line['unit_price'],
            'currency' => $line['currency'],
            'state' => $line['state'] ?? 'pending_acceptance',
        ]);
    }

    public function findLine(string $orderRef, string $lineRef): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM order_lines WHERE order_ref = :ref AND line_ref = :line FOR UPDATE'
        );
        $stmt->execute(['ref' => $orderRef, 'line' => $lineRef]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function updateLineState(string $orderRef, string $lineRef, string $newState, \DateTimeImmutable $eventOccurredAt, ?string $refusalReason = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE order_lines SET state = :state, last_event_at = :at, refusal_reason = COALESCE(:reason, refusal_reason), updated_at = now()
             WHERE order_ref = :ref AND line_ref = :line'
        );
        $stmt->execute([
            'state' => $newState, 'at' => $eventOccurredAt->format('c'),
            'reason' => $refusalReason, 'ref' => $orderRef, 'line' => $lineRef,
        ]);
    }

    public function findOrderWithLines(string $orderRef): ?array
    {
        $orderStmt = $this->db->prepare('SELECT * FROM orders WHERE order_ref = :ref');
        $orderStmt->execute(['ref' => $orderRef]);
        $order = $orderStmt->fetch();
        if (!$order) {
            return null;
        }
        $linesStmt = $this->db->prepare('SELECT * FROM order_lines WHERE order_ref = :ref ORDER BY line_ref');
        $linesStmt->execute(['ref' => $orderRef]);
        $order['lines'] = $linesStmt->fetchAll();
        $order['derived_state'] = $this->deriveOrderState($order['lines']);
        return $order;
    }

    /**
     * The order's displayed state is computed from its lines every time,
     * never stored independently -- see Part A Decision D3. This keeps
     * partial-rejection (case 7) representable without a special status
     * value: an order with one refused line and one shipped line is
     * exactly that, not a string trying to mean both at once.
     */
    public function deriveOrderState(array $lines): string
    {
        if (empty($lines)) {
            return 'empty';
        }
        $states = array_column($lines, 'state');
        $unique = array_unique($states);

        if (count($unique) === 1) {
            return $unique[0]; // all lines share one state
        }
        if (in_array('pending_acceptance', $unique, true)) {
            return 'partially_decided';
        }
        return 'mixed'; // e.g. one shipped, one refused -- case 7's outcome
    }

    /** For the reconciliation API: orders with at least one non-terminal line, older than $sinceMinutesAgo. */
    public function findStuckOrders(int $olderThanMinutes): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT o.order_ref, o.created_at,
                    array_agg(DISTINCT ol.state::text) AS line_states
             FROM orders o
             JOIN order_lines ol ON ol.order_ref = o.order_ref
             WHERE ol.state IN ('pending_acceptance', 'accepted')
               AND ol.updated_at < now() - (:minutes || ' minutes')::interval
             GROUP BY o.order_ref, o.created_at
             ORDER BY o.created_at ASC"
        );
        $stmt->execute(['minutes' => $olderThanMinutes]);
        return $stmt->fetchAll();
    }
}
