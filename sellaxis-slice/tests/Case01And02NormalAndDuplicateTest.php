<?php

declare(strict_types=1);

namespace Forgeline\Tests;

/**
 * Case 1: normal marketplace order -- the baseline every other test is
 * implicitly checked against.
 * Case 2: duplicate webhook, different delivery_id, same event_id/order_ref
 * -- must not create two orders.
 */
final class Case01And02NormalAndDuplicateTest extends DatabaseTestCase
{
    public function test_normal_order_is_created_with_mapped_line(): void
    {
        $processor = $this->makeProcessor();
        $event = $this->baseOrderEvent('ORD-IN-100045');

        $outcome = $processor->processEvent($event);

        $this->assertSame('order_created', $outcome);

        $orderRepo = new \Forgeline\Infra\OrderRepository($this->db);
        $order = $orderRepo->findOrderWithLines('ORD-IN-100045');
        $this->assertNotNull($order);
        $this->assertCount(1, $order['lines']);
        $this->assertSame('frg-bolt-m8x100-zn', $order['lines'][0]['magento_sku']);
        $this->assertSame('pending_acceptance', $order['lines'][0]['state']);
        $this->assertSame('captured', $order['capture_status']);
    }

    public function test_duplicate_delivery_id_does_not_create_two_orders(): void
    {
        $processor = $this->makeProcessor();
        $eventId = 'evt_5b9d3e';

        // Same event_id, two different delivery_ids -- mirrors the stub's
        // case 2 payload exactly.
        $first = $this->baseOrderEvent('ORD-IN-100045', [], 'dlv_AA1', $eventId);
        $second = $this->baseOrderEvent('ORD-IN-100045', [], 'dlv_BB2', $eventId);

        $outcome1 = $processor->processEvent($first);
        $outcome2 = $processor->processEvent($second);

        $this->assertSame('order_created', $outcome1);
        // Second delivery has a DIFFERENT delivery_id, so it is durably
        // recorded as new at the delivery layer, but the event_id has
        // already been fully processed -- so it must be a clean no-op,
        // not a second order.
        $this->assertSame('already_processed_noop', $outcome2);

        $count = $this->db->query("SELECT COUNT(*) FROM orders WHERE order_ref = 'ORD-IN-100045'")->fetchColumn();
        $this->assertSame('1', (string) $count);

        // Both delivery attempts are on record for audit purposes.
        $deliveryCount = $this->db->query(
            "SELECT COUNT(*) FROM inbound_events WHERE event_id = 'evt_5b9d3e'"
        )->fetchColumn();
        $this->assertSame('2', (string) $deliveryCount);
    }

    public function test_exact_same_delivery_id_retried_is_a_pure_noop(): void
    {
        $processor = $this->makeProcessor();
        $event = $this->baseOrderEvent('ORD-IN-100046', [], 'dlv_SAME', 'evt_same');

        $outcome1 = $processor->processEvent($event);
        $outcome2 = $processor->processEvent($event); // exact same delivery_id, e.g. caller retried the HTTP call itself

        $this->assertSame('order_created', $outcome1);
        $this->assertSame('duplicate_delivery_ignored', $outcome2);

        $count = $this->db->query("SELECT COUNT(*) FROM orders WHERE order_ref = 'ORD-IN-100046'")->fetchColumn();
        $this->assertSame('1', (string) $count);
    }
}
