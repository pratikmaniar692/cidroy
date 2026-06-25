<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Forgeline\Domain\EventValidator;
use Forgeline\Domain\InventoryVersionGuard;
use Forgeline\Domain\OrderProcessor;
use Forgeline\Http\OrdersController;
use Forgeline\Http\ReconciliationController;
use Forgeline\Http\Router;
use Forgeline\Http\WebhookController;
use Forgeline\Infra\Db;
use Forgeline\Infra\InboundEventRepository;
use Forgeline\Infra\InventoryRepository;
use Forgeline\Infra\OrderRepository;
use Forgeline\Infra\OutboxRepository;
use Forgeline\Infra\SellerProductMapRepository;
use Forgeline\Infra\WebhookSignatureVerifier;

$db = Db::connection();

$events = new InboundEventRepository($db);
$orders = new OrderRepository($db);
$inventory = new InventoryRepository($db, new InventoryVersionGuard());
$mapping = new SellerProductMapRepository($db);
$outbox = new OutboxRepository($db);

$processor = new OrderProcessor($events, $orders, $inventory, $mapping, $outbox);
$verifier = new WebhookSignatureVerifier(getenv('WEBHOOK_SECRET') ?: 'dev-secret');
$validator = new EventValidator();

$webhookController = new WebhookController($verifier, $validator, $processor, $events);
$reconciliationController = new ReconciliationController($orders, $events, $inventory, $outbox);
$ordersController = new OrdersController($orders);

$router = new Router();
$router->add('POST', '/webhooks/sellaxis', fn($p) => $webhookController->handle());
$router->add('GET', '/api/orders/{order_ref}', fn($p) => $ordersController->show($p));
$router->add('GET', '/reconciliation/stuck-orders', fn($p) => $reconciliationController->stuckOrders($p));
$router->add('GET', '/reconciliation/exceptions', fn($p) => $reconciliationController->exceptions($p));
$router->add('GET', '/reconciliation/summary', fn($p) => $reconciliationController->summary($p));
$router->add('GET', '/reconciliation/outbox', fn($p) => $reconciliationController->outboxStatus($p));
$router->add('GET', '/health', function ($p) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
});

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$router->dispatch($_SERVER['REQUEST_METHOD'], $path);
