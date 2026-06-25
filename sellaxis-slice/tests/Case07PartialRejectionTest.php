<?php

declare(strict_types=1);

namespace Forgeline\Tests;

/**
 * Case 7: a multi-seller order where one seller refuses their line while
 * another accepts theirs. The order must progress (not stall waiting for
 * a single combined decision), and the derived order state must represent
 * the mixed outcome honestly rather than forcing it into one status.
 */
final class Case07PartialRejectionTest extends DatabaseTestCase
{
    public function test_one_seller_refuses_while_another_accepts(): void
    {
        $processor = $this->makeProcessor();
        $orderRepo = new \Forgeline\Infra\OrderRepository($this->db);

        $event = $this->baseOrderEvent('ORD-IN-100050', [
            ['line_ref' => 'L1', 'seller_id' => 'SLR-1001', 'seller_sku' => 'FAST-M8-100', 'qty' => 50, 'unit_price' => '12.40', 'currency' => 'INR'],
            ['line_ref' => 'L2', 'seller_id' => 'SLR-1003', 'seller_sku' => 'LUBE-EP2-5KG', 'qty' => 4, 'unit_price' => '890.00', 'currency' => 'INR'],
        ]);
        $processor->processEvent($event);

        // SLR-1003 refuses L2.
        $refuse = [
            'delivery_id' => 'dlv_refuse_l2', 'event_id' => 'evt_refuse_l2',
            'event_type' => 'order.line.refused', 'occurred_at' => '2026-06-12T23:53:00+05:30',
            'data' => ['order_ref' => 'ORD-IN-100050', 'line_ref' => 'L2', 'refusal_reason' => 'out_of_stock'],
        ];
        $outcomeRefuse = $processor->processEvent($refuse);
        $this->assertSame('transitioned_to_refused', $outcomeRefuse);

        // SLR-1001 accepts L1.
        $accept = [
            'delivery_id' => 'dlv_accept_l1', 'event_id' => 'evt_accept_l1',
            'event_type' => 'order.line.accepted', 'occurred_at' => '2026-06-12T23:53:30+05:30',
            'data' => ['order_ref' => 'ORD-IN-100050', 'line_ref' => 'L1'],
        ];
        $outcomeAccept = $processor->processEvent($accept);
        $this->assertSame('transitioned_to_accepted', $outcomeAccept);

        $order = $orderRepo->findOrderWithLines('ORD-IN-100050');
        $this->assertSame('mixed', $order['derived_state'], 'an order with one accepted and one refused line must show a mixed derived state, not stall or collapse into one status');

        $states = array_column($order['lines'], 'state', 'line_ref');
        $this->assertSame('accepted', $states['L1']);
        $this->assertSame('refused', $states['L2']);
        $this->assertSame('out_of_stock', $orderRepo->findLine('ORD-IN-100050', 'L2')['refusal_reason']);
    }
}
