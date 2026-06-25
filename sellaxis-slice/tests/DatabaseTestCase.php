<?php

declare(strict_types=1);

namespace Forgeline\Tests;

use Forgeline\Infra\Db;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected \PDO $db;

    protected function setUp(): void
    {
        Db::reset();
        $this->db = Db::connection();
        $this->truncateAll();
        $this->seedMinimalFixture();
    }

    private function truncateAll(): void
    {
        $this->db->exec(
            'TRUNCATE TABLE inventory_update_log, outbox_events, inbound_events, order_lines, orders,
             seller_product_map, offers_inventory, catalogue_products, sellers RESTART IDENTITY CASCADE'
        );
    }

    private function seedMinimalFixture(): void
    {
        $this->db->exec(
            "INSERT INTO sellers (seller_id, name, market, currency, commission_rate, portal_token) VALUES
                ('SLR-1001', 'Konkan Fasteners', 'IN', 'INR', 0.085, 'token-slr-1001'),
                ('SLR-1003', 'Sahyadri Lubricants', 'IN', 'INR', 0.070, 'token-slr-1003'),
                ('SLR-2001', 'Rheinland Elektrik', 'DE', 'EUR', 0.110, 'token-slr-2001')"
        );
        $this->db->exec(
            "INSERT INTO catalogue_products (magento_sku, name) VALUES
                ('frg-bolt-m8x100-zn', 'M8x100 Zinc Bolt'),
                ('frg-grease-ep2-5kg', 'EP2 Grease 5kg')"
        );
        $this->db->exec(
            "INSERT INTO seller_product_map (seller_id, seller_sku, magento_sku) VALUES
                ('SLR-1001', 'FAST-M8-100', 'frg-bolt-m8x100-zn'),
                ('SLR-1003', 'LUBE-EP2-5KG', 'frg-grease-ep2-5kg')"
        );
        $this->db->exec(
            "INSERT INTO offers_inventory (offer_id, seller_id, seller_sku, available_qty, version, observed_at) VALUES
                ('OFR-1001-FASTM8100', 'SLR-1001', 'FAST-M8-100', 920, 47, '2026-06-12T23:49:30+05:30')"
        );
    }

    protected function baseOrderEvent(string $orderRef, array $linesOverride = [], ?string $deliveryId = null, ?string $eventId = null): array
    {
        $lines = $linesOverride ?: [
            ['line_ref' => 'L1', 'seller_id' => 'SLR-1001', 'seller_sku' => 'FAST-M8-100', 'qty' => 50, 'unit_price' => '12.40', 'currency' => 'INR'],
        ];
        return [
            'delivery_id' => $deliveryId ?? ('dlv_' . bin2hex(random_bytes(4))),
            'event_id' => $eventId ?? ('evt_' . bin2hex(random_bytes(4))),
            'event_type' => 'order.created',
            'occurred_at' => '2026-06-12T23:50:11+05:30',
            'data' => [
                'order_ref' => $orderRef,
                'market' => 'IN',
                'currency' => 'INR',
                'buyer_ref' => 'BUY-IN-7781',
                'created_at' => '2026-06-12T23:50:11+05:30',
                'lines' => $lines,
            ],
        ];
    }

    protected function makeProcessor(): \Forgeline\Domain\OrderProcessor
    {
        return new \Forgeline\Domain\OrderProcessor(
            new \Forgeline\Infra\InboundEventRepository($this->db),
            new \Forgeline\Infra\OrderRepository($this->db),
            new \Forgeline\Infra\InventoryRepository($this->db, new \Forgeline\Domain\InventoryVersionGuard()),
            new \Forgeline\Infra\SellerProductMapRepository($this->db),
            new \Forgeline\Infra\OutboxRepository($this->db),
        );
    }
}
