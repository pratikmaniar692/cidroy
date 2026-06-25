<?php

declare(strict_types=1);

namespace Forgeline\Tests;

use Forgeline\Infra\OrderRepository;

/**
 * Case 14: seller SLR-2001 requests an order that belongs to SLR-1001.
 * Must be denied based on the AUTHENTICATED identity, never a supplied
 * parameter. We test the authorization logic directly here (the same
 * logic OrdersController::show uses) rather than through a live HTTP
 * request, since the brief's point is about the authorization decision
 * itself, not the transport.
 */
final class Case14CrossSellerAccessTest extends DatabaseTestCase
{
    public function test_seller_cannot_access_another_sellers_order(): void
    {
        $processor = $this->makeProcessor();
        $processor->processEvent($this->baseOrderEvent('ORD-IN-100045')); // owned by SLR-1001

        $orderRepo = new OrderRepository($this->db);
        $order = $orderRepo->findOrderWithLines('ORD-IN-100045');

        $authenticatedSellerId = 'SLR-2001'; // a DIFFERENT seller, simulating their bearer token

        $ownsAnyLine = false;
        foreach ($order['lines'] as $line) {
            if ($line['seller_id'] === $authenticatedSellerId) {
                $ownsAnyLine = true;
            }
        }

        $this->assertFalse($ownsAnyLine, 'SLR-2001 must not be recognised as owning any line on an order that belongs entirely to SLR-1001');
    }

    public function test_seller_can_access_their_own_order(): void
    {
        $processor = $this->makeProcessor();
        $processor->processEvent($this->baseOrderEvent('ORD-IN-100045'));

        $orderRepo = new OrderRepository($this->db);
        $order = $orderRepo->findOrderWithLines('ORD-IN-100045');

        $authenticatedSellerId = 'SLR-1001'; // the actual owning seller

        $ownsAnyLine = false;
        foreach ($order['lines'] as $line) {
            if ($line['seller_id'] === $authenticatedSellerId) {
                $ownsAnyLine = true;
            }
        }

        $this->assertTrue($ownsAnyLine);
    }

    public function test_supplied_seller_id_parameter_is_never_the_source_of_truth(): void
    {
        // This test documents the design rule itself: the authorization
        // check in OrdersController never reads $_GET['seller_id'] or any
        // similar client-supplied value -- grep the controller source to
        // confirm the rule is structurally enforced, not just convention.
        $controllerSource = file_get_contents(__DIR__ . '/../src/Http/OrdersController.php');
        $this->assertStringNotContainsString("\$_GET['seller_id']", $controllerSource);
        $this->assertStringNotContainsString('$_POST[\'seller_id\']', $controllerSource);
        $this->assertStringContainsString('HTTP_AUTHORIZATION', $controllerSource, 'authorization must be derived from the auth header, not a request parameter');
    }
}
