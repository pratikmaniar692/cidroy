<?php

declare(strict_types=1);

namespace Forgeline\Domain;

use Forgeline\Infra\Db;
use Forgeline\Infra\InboundEventRepository;
use Forgeline\Infra\InventoryRepository;
use Forgeline\Infra\Logger;
use Forgeline\Infra\OrderRepository;
use Forgeline\Infra\OutboxRepository;
use Forgeline\Infra\SellerProductMapRepository;

/**
 * The single entry point that turns a validated, normalised inbound event
 * into a state change (or a deliberate non-change: held / quarantined /
 * discarded-stale / no-op). Every stub case maps to a specific branch here;
 * each branch's comment names the case it exists for.
 */
final class OrderProcessor
{
    private LineStateMachine $machine;

    public function __construct(
        private InboundEventRepository $events,
        private OrderRepository $orders,
        private InventoryRepository $inventory,
        private SellerProductMapRepository $mapping,
        private OutboxRepository $outbox,
    ) {
        $this->machine = new LineStateMachine();
    }

    /**
     * Processes one normalised webhook event envelope. Returns a short
     * outcome string for the caller (controller) to log/respond with.
     *
     * NOTE on transactions: dedup recording happens up front, in its own
     * statement, so that even if processing later throws, we have already
     * durably recorded that this delivery_id was received -- which is what
     * "acknowledge only once durably accepted" (field note 9) actually
     * means. We do NOT wrap dedup + processing in one big transaction,
     * because that would mean a processing failure rolls back the dedup
     * record too, reopening the door to reprocessing on the next retry.
     */
    public function processEvent(array $event): string
    {
        $deliveryId = $event['delivery_id'];
        $eventId = $event['event_id'];
        $eventType = $event['event_type'];
        $orderRef = $event['data']['order_ref'] ?? null;
        $lineRef = $event['data']['line_ref'] ?? null;
        $occurredAt = isset($event['occurred_at']) ? new \DateTimeImmutable($event['occurred_at']) : null;

        $isNew = $this->events->recordDelivery(
            $deliveryId, $eventId, $eventType, $occurredAt, $orderRef, $lineRef, $event
        );

        if (!$isNew) {
            // Case 2: duplicate webhook, different delivery_id pointing at
            // the same event_id we may or may not have already processed.
            // We already recorded THIS delivery_id before (this exact
            // function call is itself a retry of the same HTTP delivery),
            // so this is a true no-op at the delivery layer.
            Logger::info('duplicate_delivery_ignored', ['delivery_id' => $deliveryId, 'event_id' => $eventId]);
            return 'duplicate_delivery_ignored';
        }

        // Case 13: a different delivery_id, but an event_id we have
        // already fully processed. This is allowed by design (Sellaxis
        // redelivers with a new delivery_id) and must be a clean no-op
        // against order/line state, while still being recorded above for
        // audit purposes.
        if ($this->events->alreadyProcessed($eventId)) {
            $this->events->markProcessed($deliveryId);
            Logger::info('already_processed_event_noop', ['delivery_id' => $deliveryId, 'event_id' => $eventId]);
            return 'already_processed_noop';
        }

        try {
            $outcome = $this->dispatch($eventType, $event, $deliveryId, $occurredAt);
        } catch (\Throwable $e) {
            $this->events->markQuarantined($deliveryId, $e->getMessage());
            Logger::error('event_processing_failed', [
                'delivery_id' => $deliveryId, 'event_type' => $eventType, 'error' => $e->getMessage(),
            ]);
            return 'quarantined';
        }

        return $outcome;
    }

    private function dispatch(string $eventType, array $event, string $deliveryId, ?\DateTimeImmutable $occurredAt): string
    {
        return match ($eventType) {
            'order.created' => $this->handleOrderCreated($event, $deliveryId),
            'order.line.accepted', 'order.line.refused', 'order.line.shipped' =>
                $this->handleLineTransition($event, $deliveryId, $eventType, $occurredAt),
            'order.cancelled' => $this->handleCancellation($event, $deliveryId, $occurredAt),
            'order.refunded' => $this->handleRefund($event, $deliveryId, $occurredAt),
            'inventory.changed' => $this->handleInventoryChanged($event, $deliveryId, 'webhook'),
            default => throw new \RuntimeException("Unknown event_type '{$eventType}'"),
        };
    }

    private function handleOrderCreated(array $event, string $deliveryId): string
    {
        $data = $event['data'];
        $orderRef = $data['order_ref'];

        if ($this->orders->exists($orderRef)) {
            // Belt-and-suspenders for case 13 if it slips past the event_id
            // check above (e.g. a manually-crafted redelivery in tests).
            $this->events->markProcessed($deliveryId);
            return 'order_already_exists_noop';
        }

        $lines = [];
        $heldForMapping = [];
        foreach ($data['lines'] as $line) {
            $mappingResult = $this->mapping->resolve($line['seller_id'], $line['seller_sku']);
            if (!$mappingResult->isMapped()) {
                // Cases 5 & 6: record the line with a NULL magento_sku and
                // flag it -- the order/line still gets created (we don't
                // want one bad line to block an otherwise-fine order from
                // being acknowledged and tracked), but it's surfaced via
                // the reconciliation API as needing attention, distinctly
                // labelled by which of the two mapping failures it is.
                $heldForMapping[] = ['line_ref' => $line['line_ref'], 'outcome' => $mappingResult->outcome];
            }
            $lines[] = [
                'line_ref' => $line['line_ref'],
                'seller_id' => $line['seller_id'],
                'seller_sku' => $line['seller_sku'],
                'magento_sku' => $mappingResult->isMapped() ? $mappingResult->magentoSku : null,
                'qty' => $line['qty'],
                'unit_price' => $line['unit_price'],
                'currency' => $line['currency'] ?? $data['currency'],
            ];
        }

        $totalAmount = '0.00';
        foreach ($lines as $line) {
            $totalAmount = bcadd($totalAmount, bcmul($line['unit_price'], (string) $line['qty'], 2), 2);
        }

        // Case 16 is exercised here: the capture closure is supplied by the
        // controller (so tests can inject a gateway that "succeeds then we
        // crash before commit" deliberately). createOrderWithCapture keeps
        // order-creation and capture-bookkeeping in one local transaction;
        // see OrderRepository for the full reasoning.
        $captureFn = $event['__test_capture_fn'] ?? fn($ref, $amount, $currency) => [
            'status' => 'captured', 'capture_ref' => 'cap_' . substr(md5($ref), 0, 10),
        ];

        $this->orders->createOrderWithCapture(
            [
                'order_ref' => $orderRef,
                'market' => $data['market'],
                'currency' => $data['currency'],
                'buyer_ref' => $data['buyer_ref'],
                'created_at' => $data['created_at'] ?? (new \DateTimeImmutable())->format('c'),
                'capture_amount' => $totalAmount,
            ],
            $lines,
            $captureFn
        );

        // Enqueue the ERP purchase order via the outbox -- never call the
        // ERP synchronously from this path (see Part B finding C4 /
        // Part A Decision D4 for why; case 4 is the concrete failure this
        // avoids).
        $this->outbox->enqueue(
            idempotencyKey: 'erp-po-' . $orderRef,
            target: 'erp',
            orderRef: $orderRef,
            payload: ['order_ref' => $orderRef, 'lines' => $lines, 'total' => $totalAmount, 'currency' => $data['currency']]
        );

        $this->events->markProcessed($deliveryId);

        if (!empty($heldForMapping)) {
            Logger::warn('order_created_with_unmapped_lines', ['order_ref' => $orderRef, 'lines' => $heldForMapping]);
            return 'order_created_with_mapping_exceptions';
        }

        Logger::info('order_created', ['order_ref' => $orderRef, 'lines' => count($lines)]);
        return 'order_created';
    }

    /**
     * Handles order.line.accepted / refused / shipped uniformly through the
     * state machine. This is where case 3 (out-of-order) is actually
     * resolved: an invalid transition is held, not applied; every
     * successful transition re-checks held events for the same order.
     */
    private function handleLineTransition(array $event, string $deliveryId, string $eventType, ?\DateTimeImmutable $occurredAt): string
    {
        $data = $event['data'];
        $orderRef = $data['order_ref'];
        $lineRef = $data['line_ref'];

        $line = $this->orders->findLine($orderRef, $lineRef);
        if ($line === null) {
            throw new \RuntimeException("Line {$lineRef} on order {$orderRef} not found");
        }

        $check = $this->machine->check($line['state'], $eventType);

        if (!$check['valid']) {
            // Case 3: the textbook example is exactly this branch --
            // 'shipped' arriving while the line is still
            // 'pending_acceptance'. Hold, don't apply, don't discard.
            $this->events->markHeld($deliveryId, "invalid transition {$eventType} from state {$line['state']}");
            Logger::warn('event_held_invalid_transition', [
                'order_ref' => $orderRef, 'line_ref' => $lineRef,
                'event_type' => $eventType, 'current_state' => $line['state'],
            ]);
            return 'held_invalid_transition';
        }

        $refusalReason = $data['refusal_reason'] ?? null;
        $this->orders->updateLineState($orderRef, $lineRef, $check['resultingState'], $occurredAt ?? new \DateTimeImmutable(), $refusalReason);
        $this->events->markProcessed($deliveryId);

        Logger::info('line_transitioned', [
            'order_ref' => $orderRef, 'line_ref' => $lineRef,
            'from' => $line['state'], 'to' => $check['resultingState'],
        ]);

        $this->recheckHeldEvents($orderRef);

        return 'transitioned_to_' . $check['resultingState'];
    }

    /**
     * After ANY successful transition, re-check events that were
     * previously held for this order -- this is what lets case 3's
     * "shipped" event, held a moment ago, get applied once "accepted"
     * lands and makes it valid.
     */
    private function recheckHeldEvents(string $orderRef): void
    {
        $held = $this->events->findHeldForOrder($orderRef);
        foreach ($held as $heldEvent) {
            $payload = json_decode($heldEvent['raw_payload'], true);
            Logger::info('rechecking_held_event', ['delivery_id' => $heldEvent['delivery_id'], 'event_type' => $heldEvent['event_type']]);
            // Re-run through the normal dispatch path. If it's STILL
            // invalid it will simply be re-marked held (status unchanged
            // in effect); if now valid, it applies and may itself trigger
            // a further recheck cascade (e.g. shipped now makes a
            // subsequent refund event valid too).
            $this->dispatch($payload['event_type'], $payload, $heldEvent['delivery_id'], $heldEvent['occurred_at'] ? new \DateTimeImmutable($heldEvent['occurred_at']) : null);
        }
    }

    private function handleCancellation(array $event, string $deliveryId, ?\DateTimeImmutable $occurredAt): string
    {
        $data = $event['data'];
        $orderRef = $data['order_ref'];
        $orderRow = $this->orders->findOrderWithLines($orderRef);
        if ($orderRow === null) {
            throw new \RuntimeException("Order {$orderRef} not found for cancellation");
        }

        $results = [];
        foreach ($orderRow['lines'] as $line) {
            if ($this->machine->isTerminal($line['state'])) {
                continue; // already cancelled/refunded, nothing to do
            }
            if ($this->machine->cancellationRequiresReturnFlow($line['state'])) {
                // Case 9: cancellation after shipment. We do NOT silently
                // turn a shipped line into cancelled -- that would erase
                // the fact that it shipped, which is operationally false.
                // It's routed to the refund flow instead, leaving the
                // line's state as 'shipped' until an explicit refund event
                // resolves it.
                $results[] = ['line_ref' => $line['line_ref'], 'outcome' => 'routed_to_refund_flow'];
                Logger::warn('cancellation_after_shipment_routed_to_refund', [
                    'order_ref' => $orderRef, 'line_ref' => $line['line_ref'],
                ]);
                continue;
            }
            // Case 8: cancellation after reservation but before shipment --
            // straightforward, release/cancel cleanly.
            $this->orders->updateLineState($orderRef, $line['line_ref'], 'cancelled', $occurredAt ?? new \DateTimeImmutable());
            $results[] = ['line_ref' => $line['line_ref'], 'outcome' => 'cancelled'];
        }

        $this->events->markProcessed($deliveryId);
        Logger::info('cancellation_processed', ['order_ref' => $orderRef, 'results' => $results]);
        return 'cancellation_processed';
    }

    private function handleRefund(array $event, string $deliveryId, ?\DateTimeImmutable $occurredAt): string
    {
        $data = $event['data'];
        $orderRef = $data['order_ref'];
        $lineRef = $data['line_ref'];
        $line = $this->orders->findLine($orderRef, $lineRef);
        if ($line === null) {
            throw new \RuntimeException("Line {$lineRef} on order {$orderRef} not found for refund");
        }

        $check = $this->machine->check($line['state'], 'order.refunded');
        if (!$check['valid']) {
            $this->events->markHeld($deliveryId, "refund not valid from state {$line['state']}");
            return 'held_invalid_transition';
        }

        $this->orders->updateLineState($orderRef, $lineRef, 'refunded', $occurredAt ?? new \DateTimeImmutable());
        $this->events->markProcessed($deliveryId);
        Logger::info('line_refunded', ['order_ref' => $orderRef, 'line_ref' => $lineRef]);
        return 'refunded';
    }

    private function handleInventoryChanged(array $event, string $deliveryId, string $source): string
    {
        $data = $event['data'];
        $outcome = $this->inventory->applyIfNewer(
            offerId: $data['offer_id'],
            sellerId: $data['seller_id'],
            sellerSku: $data['seller_sku'],
            availableQty: (int) $data['available_qty'],
            version: (int) $data['version'],
            observedAt: new \DateTimeImmutable($data['observed_at']),
            source: $source,
        );
        $this->events->markProcessed($deliveryId);
        Logger::info('inventory_update_' . $outcome, ['offer_id' => $data['offer_id'], 'version' => $data['version']]);
        return 'inventory_' . $outcome;
    }
}
