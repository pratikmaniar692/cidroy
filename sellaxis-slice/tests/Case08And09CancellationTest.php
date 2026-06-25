<?php

declare(strict_types=1);

namespace Forgeline\Tests;

/**
 * Case 8: cancellation arrives after the line has reserved stock
 * (accepted, not yet shipped) -- must release/cancel cleanly.
 * Case 9: cancellation arrives after the line has ALREADY shipped --
 * cannot un-ship; must route to the refund flow, never silently mark the
 * line cancelled as if shipment never happened.
 */
final class Case08And09CancellationTest extends DatabaseTestCase
{
    public function test_cancellation_after_acceptance_before_shipment_cancels_cleanly(): void
    {
        $processor = $this->makeProcessor();
        $orderRepo = new \Forgeline\Infra\OrderRepository($this->db);

        $processor->processEvent($this->baseOrderEvent('ORD-IN-100045', [], 'dlv_o8', 'evt_o8'));
        $processor->processEvent([
            'delivery_id' => 'dlv_acc8', 'event_id' => 'evt_acc8',
            'event_type' => 'order.line.accepted', 'occurred_at' => '2026-06-12T23:52:00+05:30',
            'data' => ['order_ref' => 'ORD-IN-100045', 'line_ref' => 'L1'],
        ]);

        $this->assertSame('accepted', $orderRepo->findLine('ORD-IN-100045', 'L1')['state']);

        $cancel = [
            'delivery_id' => 'dlv_cancel8', 'event_id' => 'evt_cancel8',
            'event_type' => 'order.cancelled', 'occurred_at' => '2026-06-12T23:54:00+05:30',
            'data' => ['order_ref' => 'ORD-IN-100045', 'reason' => 'buyer_cancelled'],
        ];
        $outcome = $processor->processEvent($cancel);
        $this->assertSame('cancellation_processed', $outcome);
        $this->assertSame('cancelled', $orderRepo->findLine('ORD-IN-100045', 'L1')['state']);
    }

    public function test_cancellation_after_shipment_does_not_overwrite_shipped_state(): void
    {
        $processor = $this->makeProcessor();
        $orderRepo = new \Forgeline\Infra\OrderRepository($this->db);

        $processor->processEvent($this->baseOrderEvent('ORD-IN-100051', [], 'dlv_o9', 'evt_o9'));
        $processor->processEvent([
            'delivery_id' => 'dlv_acc9', 'event_id' => 'evt_acc9',
            'event_type' => 'order.line.accepted', 'occurred_at' => '2026-06-12T23:52:00+05:30',
            'data' => ['order_ref' => 'ORD-IN-100051', 'line_ref' => 'L1'],
        ]);
        $processor->processEvent([
            'delivery_id' => 'dlv_ship9', 'event_id' => 'evt_ship9',
            'event_type' => 'order.line.shipped', 'occurred_at' => '2026-06-12T23:56:00+05:30',
            'data' => ['order_ref' => 'ORD-IN-100051', 'line_ref' => 'L1'],
        ]);

        $this->assertSame('shipped', $orderRepo->findLine('ORD-IN-100051', 'L1')['state']);

        $cancel = [
            'delivery_id' => 'dlv_cancel9', 'event_id' => 'evt_cancel9',
            'event_type' => 'order.cancelled', 'occurred_at' => '2026-06-12T23:58:00+05:30',
            'data' => ['order_ref' => 'ORD-IN-100051', 'reason' => 'buyer_cancelled'],
        ];
        $outcome = $processor->processEvent($cancel);
        $this->assertSame('cancellation_processed', $outcome);

        // The critical assertion: the line MUST still say 'shipped', not
        // 'cancelled'. Cancelling a shipped line would falsify the record
        // -- the package is physically in motion. This is routed to refund
        // instead (proven by the line being eligible for order.refunded
        // next, asserted below).
        $lineAfter = $orderRepo->findLine('ORD-IN-100051', 'L1');
        $this->assertSame('shipped', $lineAfter['state'], 'a shipped line must never be silently overwritten to cancelled');

        $refund = [
            'delivery_id' => 'dlv_refund9', 'event_id' => 'evt_refund9',
            'event_type' => 'order.refunded', 'occurred_at' => '2026-06-13T00:01:00+05:30',
            'data' => ['order_ref' => 'ORD-IN-100051', 'line_ref' => 'L1', 'amount' => '620.00', 'currency' => 'INR'],
        ];
        $refundOutcome = $processor->processEvent($refund);
        $this->assertSame('refunded', $refundOutcome);
        $this->assertSame('refunded', $orderRepo->findLine('ORD-IN-100051', 'L1')['state']);
    }
}
