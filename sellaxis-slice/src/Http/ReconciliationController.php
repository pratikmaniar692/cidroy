<?php

declare(strict_types=1);

namespace Forgeline\Http;

use Forgeline\Infra\InboundEventRepository;
use Forgeline\Infra\InventoryRepository;
use Forgeline\Infra\OrderRepository;
use Forgeline\Infra\OutboxRepository;

/**
 * The reconciliation / exception API the brief requires. Three views,
 * because "what's wrong" is not one question for this system:
 *
 *   /reconciliation/stuck-orders   -- orders received but not yet in a
 *                                      terminal state (the brief's own
 *                                      example phrasing)
 *   /reconciliation/exceptions     -- events that failed and why (the
 *                                      brief's other example phrasing):
 *                                      held (case 3), quarantined (case 10)
 *   /reconciliation/outbox         -- ERP delivery backlog (case 4), so a
 *                                      stuck ERP relay is visible before it
 *                                      compounds
 */
final class ReconciliationController
{
    public function __construct(
        private OrderRepository $orders,
        private InboundEventRepository $events,
        private InventoryRepository $inventory,
        private OutboxRepository $outbox,
    ) {}

    public function stuckOrders(array $params): void
    {
        $minutes = isset($_GET['older_than_minutes']) ? (int) $_GET['older_than_minutes'] : 0;
        $rows = $this->orders->findStuckOrders($minutes);
        $this->json(['older_than_minutes' => $minutes, 'count' => count($rows), 'orders' => $rows]);
    }

    public function exceptions(array $params): void
    {
        $held = $this->events->listByStatus('held');
        $quarantined = $this->events->listByStatus('quarantined');
        $this->json([
            'held' => ['count' => count($held), 'events' => $held],
            'quarantined' => ['count' => count($quarantined), 'events' => $quarantined],
        ]);
    }

    public function summary(array $params): void
    {
        $this->json([
            'inbound_events_by_status' => $this->events->countsByStatus(),
            'recent_inventory_activity' => $this->inventory->recentLog(20),
        ]);
    }

    public function outboxStatus(array $params): void
    {
        $pending = $this->outbox->findPending(100);
        $this->json(['pending_or_retryable' => count($pending), 'events' => $pending]);
    }

    private function json(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
    }
}
