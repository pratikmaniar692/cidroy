<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Forgeline\Infra\Db;
use Forgeline\Infra\ErpClient;
use Forgeline\Infra\Logger;
use Forgeline\Infra\OutboxRepository;

/**
 * The outbox relay. Run on a schedule (cron, or just `docker compose run`
 * for this exercise). Delivers every pending/failed_retryable outbox row to
 * the ERP, exactly reproducing the documented case-4 recovery: if the POST
 * times out, confirm via the GET endpoint using the SAME idempotency key
 * before deciding whether to mark delivered or retry -- never blind-retry
 * with a fresh key, which is precisely what would create a duplicate PO.
 */
$db = Db::connection();
$outbox = new OutboxRepository($db);
$erp = new ErpClient(getenv('ERP_BASE_URL') ?: 'http://fake-sellaxis');

$pending = $outbox->findPending(50);
Logger::info('outbox_relay_starting', ['pending_count' => count($pending)]);

foreach ($pending as $row) {
    $payload = json_decode($row['payload'], true);
    $result = $erp->postPurchaseOrder($row['idempotency_key'], $payload);

    if (in_array($result['status'], ['delivered', 'timed_out_but_confirmed'], true)) {
        $outbox->markDelivered($row['idempotency_key']);
        Logger::info('outbox_delivered', [
            'idempotency_key' => $row['idempotency_key'],
            'order_ref' => $row['order_ref'],
            'via' => $result['status'],
            'erp_ref' => $result['capture_ref'],
        ]);
    } else {
        $outbox->markFailedRetryable($row['idempotency_key'], $result['status']);
        Logger::warn('outbox_delivery_failed_will_retry', [
            'idempotency_key' => $row['idempotency_key'],
            'order_ref' => $row['order_ref'],
            'reason' => $result['status'],
        ]);
    }
}

Logger::info('outbox_relay_finished', ['processed' => count($pending)]);
