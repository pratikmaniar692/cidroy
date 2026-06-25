<?php

declare(strict_types=1);

namespace Forgeline\Tests;

/**
 * Case 5: seller_sku has no Forgeline mapping yet, but a real offer exists
 * for it -- an onboarding gap, not a data error.
 * Case 6: seller_sku corresponds to no offer on record at all -- unknown
 * system-wide, not just unmapped.
 */
final class Case05And06MappingTest extends DatabaseTestCase
{
    public function test_missing_mapping_creates_order_but_flags_the_line(): void
    {
        // Seed an offer for TOOL-RANDOM-X (case 5: the offer is real, the
        // mapping just hasn't happened) without a seller_product_map row.
        $this->db->exec(
            "INSERT INTO sellers (seller_id, name, market, currency, commission_rate, portal_token)
             VALUES ('SLR-1002', 'Deccan Tooling', 'IN', 'INR', 0.09, 'token-slr-1002')"
        );
        $this->db->exec(
            "INSERT INTO offers_inventory (offer_id, seller_id, seller_sku, available_qty, version, observed_at)
             VALUES ('OFR-1002-TOOLRANDOMX', 'SLR-1002', 'TOOL-RANDOM-X', 10, 1, now())"
        );

        $processor = $this->makeProcessor();
        $event = $this->baseOrderEvent('ORD-IN-100048', [
            ['line_ref' => 'L1', 'seller_id' => 'SLR-1002', 'seller_sku' => 'TOOL-RANDOM-X', 'qty' => 2, 'unit_price' => '3400.00', 'currency' => 'INR'],
        ]);

        $outcome = $processor->processEvent($event);
        $this->assertSame('order_created_with_mapping_exceptions', $outcome);

        $orderRepo = new \Forgeline\Infra\OrderRepository($this->db);
        $order = $orderRepo->findOrderWithLines('ORD-IN-100048');
        $this->assertNotNull($order, 'the order must still be created -- one unmapped line should not block the whole order');
        $this->assertNull($order['lines'][0]['magento_sku']);
    }

    public function test_unknown_sku_with_no_offer_on_record_is_also_created_but_distinctly_flagged(): void
    {
        $processor = $this->makeProcessor();
        $event = $this->baseOrderEvent('ORD-IN-100049', [
            ['line_ref' => 'L1', 'seller_id' => 'SLR-1001', 'seller_sku' => 'GHOST-SKU-0', 'qty' => 1, 'unit_price' => '100.00', 'currency' => 'INR'],
        ]);

        $mapping = new \Forgeline\Infra\SellerProductMapRepository($this->db);
        $result = $mapping->resolve('SLR-1001', 'GHOST-SKU-0');
        $this->assertSame('unknown_sku', $result->outcome, 'no offer on record at all must resolve as unknown_sku, distinct from missing_mapping');

        $outcome = $processor->processEvent($event);
        $this->assertSame('order_created_with_mapping_exceptions', $outcome);
    }
}
