<?php

declare(strict_types=1);

namespace Forgeline\Tests;

/**
 * Case 3: a 'shipped' event (later occurred_at) is delivered BEFORE the
 * 'accepted' event (earlier occurred_at) for the same line. This is the
 * single most important test in the suite -- it's the direct proof that
 * LineStateMachine plus the hold/recheck mechanism in OrderProcessor
 * produce the CORRECT final state despite physically-out-of-order
 * delivery, exactly as the stub demands: "State machine must order by
 * occurred_at/state, not arrival."
 */
final class Case03OutOfOrderTest extends DatabaseTestCase
{
    public function test_shipped_before_accepted_is_held_then_applied_in_correct_order(): void
    {
        $processor = $this->makeProcessor();

        // First, the order itself must exist.
        $processor->processEvent($this->baseOrderEvent('ORD-IN-100045', [], 'dlv_order', 'evt_order'));

        $shippedEvent = [
            'delivery_id' => 'dlv_C1',
            'event_id' => 'evt_ship_1',
            'event_type' => 'order.line.shipped',
            'occurred_at' => '2026-06-12T23:55:00+05:30',
            'data' => ['order_ref' => 'ORD-IN-100045', 'line_ref' => 'L1', 'carrier' => 'BlueDart', 'tracking' => 'BD123'],
        ];
        $acceptedEvent = [
            'delivery_id' => 'dlv_C2',
            'event_id' => 'evt_acc_1',
            'event_type' => 'order.line.accepted',
            'occurred_at' => '2026-06-12T23:52:00+05:30',
            'data' => ['order_ref' => 'ORD-IN-100045', 'line_ref' => 'L1'],
        ];

        // Deliver 'shipped' FIRST, exactly as the stub's payload order
        // shows.
        $outcome1 = $processor->processEvent($shippedEvent);
        $this->assertSame('held_invalid_transition', $outcome1);

        $orderRepo = new \Forgeline\Infra\OrderRepository($this->db);
        $lineAfterShipped = $orderRepo->findLine('ORD-IN-100045', 'L1');
        $this->assertSame('pending_acceptance', $lineAfterShipped['state'], 'shipped must NOT be applied while still pending acceptance');

        // Now deliver 'accepted'.
        $outcome2 = $processor->processEvent($acceptedEvent);
        $this->assertSame('transitioned_to_accepted', $outcome2);

        // The held 'shipped' event must have been re-checked and applied
        // automatically as a side effect of 'accepted' landing.
        $lineFinal = $orderRepo->findLine('ORD-IN-100045', 'L1');
        $this->assertSame('shipped', $lineFinal['state'], 'shipped must apply automatically once accepted unblocks it');

        // And the previously-held event is no longer sitting in 'held'.
        $events = new \Forgeline\Infra\InboundEventRepository($this->db);
        $stillHeld = $events->findHeldForOrder('ORD-IN-100045');
        $this->assertCount(0, $stillHeld, 'no events should remain held once the predecessor has landed');
    }

    public function test_held_event_remains_held_if_predecessor_never_arrives(): void
    {
        $processor = $this->makeProcessor();
        $processor->processEvent($this->baseOrderEvent('ORD-IN-100047', [], 'dlv_order2', 'evt_order2'));

        $shippedEvent = [
            'delivery_id' => 'dlv_orphan_ship',
            'event_id' => 'evt_orphan_ship',
            'event_type' => 'order.line.shipped',
            'occurred_at' => '2026-06-12T23:55:00+05:30',
            'data' => ['order_ref' => 'ORD-IN-100047', 'line_ref' => 'L1'],
        ];

        $outcome = $processor->processEvent($shippedEvent);
        $this->assertSame('held_invalid_transition', $outcome);

        $events = new \Forgeline\Infra\InboundEventRepository($this->db);
        $held = $events->findHeldForOrder('ORD-IN-100047');
        $this->assertCount(1, $held, 'an orphaned shipped event with no accepted predecessor should remain visibly held, not silently dropped');
    }
}
