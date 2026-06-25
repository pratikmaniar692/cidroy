<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Forgeline\Domain\InventoryVersionGuard;
use Forgeline\Domain\OrderProcessor;
use Forgeline\Infra\Db;
use Forgeline\Infra\InboundEventRepository;
use Forgeline\Infra\InventoryRepository;
use Forgeline\Infra\Logger;
use Forgeline\Infra\OrderRepository;
use Forgeline\Infra\OutboxRepository;
use Forgeline\Infra\SellaxisClient;
use Forgeline\Infra\SellerProductMapRepository;

/**
 * The poll-based reconciliation/backfill path. Per Part A's own reasoning
 * (and the stub's documented 60s floor), this is NOT meant to run as the
 * primary ingestion path -- it is the safety net for whatever the webhook
 * missed. Each invocation does one poll cycle; the docker-compose poller
 * service runs this on an interval comfortably above the 60s floor.
 */
$db = Db::connection();
$client = new SellaxisClient(getenv('SELLAXIS_BASE_URL') ?: 'http://fake-sellaxis', getenv('SELLAXIS_TOKEN') ?: 'dev-token');

$events = new InboundEventRepository($db);
$orderRepo = new OrderRepository($db);
$inventory = new InventoryRepository($db, new InventoryVersionGuard());
$mapping = new SellerProductMapRepository($db);
$outbox = new OutboxRepository($db);
$processor = new OrderProcessor($events, $orderRepo, $inventory, $mapping, $outbox);

$since = $argv[1] ?? '2026-01-01T00:00:00+00:00';
$forceMode = $argv[2] ?? null; // pass '429' | '500' | 'malformed' to test error handling deterministically

$result = $client->pollOrders($since, $forceMode);

if ($result['status'] === 'rate_limited') {
    Logger::warn('poll_skipped_rate_limited', ['retry_after' => $result['retry_after']]);
} elseif ($result['status'] === 'server_error_retryable') {
    Logger::warn('poll_failed_will_retry_next_cycle', []);
} elseif ($result['status'] === 'malformed_page') {
    Logger::error('poll_page_malformed_quarantined', ['since' => $since]);
} elseif ($result['status'] === 'ok') {
    $polledOrders = $result['body']['orders'] ?? [];
    Logger::info('poll_succeeded', ['orders_found' => count($polledOrders)]);
    foreach ($polledOrders as $order) {
        // Normalise a polled order into the same event envelope shape the
        // webhook path uses, so it goes through the identical
        // dedup/validation/processing logic -- one code path for "an order
        // arrived", regardless of which channel it arrived through.
        $event = [
            'delivery_id' => 'poll_' . $order['order_ref'] . '_' . substr(md5(json_encode($order)), 0, 8),
            'event_id' => 'poll_evt_' . $order['order_ref'],
            'event_type' => 'order.created',
            'occurred_at' => $order['created_at'],
            'data' => $order,
        ];
        $outcome = $processor->processEvent($event);
        Logger::info('poll_order_processed', ['order_ref' => $order['order_ref'], 'outcome' => $outcome]);
    }
} else {
    Logger::error('poll_unknown_result', $result);
}
