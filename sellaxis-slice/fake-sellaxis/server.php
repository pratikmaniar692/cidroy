<?php

declare(strict_types=1);

/**
 * A trivial local fake standing in for Sellaxis's poll API and the
 * Operator ERP, exactly as the stubs file invites ("wrap these canned
 * payloads in a trivial local fake if it helps"). This is NOT meant to be
 * a faithful general-purpose mock -- it deliberately reproduces the
 * SPECIFIC documented failure behaviours so the main service's error
 * handling can be exercised against them:
 *
 *   GET /api/orders                  -- 60s-floor rate limiting -> 429
 *   GET /api/offers/inventory         -- same 60s floor -> 429
 *   GET /erp/v2/purchase-orders/{key} -- idempotent PO lookup/creation
 *   POST /erp/v2/purchase-orders      -- ~ definable to time out (504) AFTER
 *                                        committing, to reproduce case 4
 *                                        exactly as documented
 *
 * State is kept in a flat file under /tmp inside the container -- this is
 * a fake for local validation, not a system that needs its own database.
 */

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$stateFile = '/tmp/fake-sellaxis-state.json';

function loadState(string $file): array
{
    if (!file_exists($file)) {
        return ['last_poll' => [], 'erp_orders' => [], 'poll_count' => 0];
    }
    return json_decode(file_get_contents($file), true) ?: ['last_poll' => [], 'erp_orders' => [], 'poll_count' => 0];
}

function saveState(string $file, array $state): void
{
    file_put_contents($file, json_encode($state));
}

$state = loadState($stateFile);

// ---------------------------------------------------------------------
// GET /api/orders -- enforces the documented 60s-per-endpoint floor and
// occasionally (~5% configurable via FORCE_500 query param for
// deterministic tests) returns a 500.
// ---------------------------------------------------------------------
if ($method === 'GET' && $path === '/api/orders') {
    $endpoint = 'orders';
    $now = time();

    if (isset($_GET['force']) && $_GET['force'] === '429') {
        http_response_code(429);
        header('Retry-After: 30');
        echo json_encode(['error' => 'rate_limited', 'retry_after' => 30]);
        exit;
    }
    if (isset($_GET['force']) && $_GET['force'] === '500') {
        http_response_code(500);
        echo json_encode(['error' => 'internal_error']);
        exit;
    }
    if (isset($_GET['force']) && $_GET['force'] === 'malformed') {
        http_response_code(200);
        // Deliberately truncated/invalid JSON with next_page still implied.
        echo '{"orders": [ { "order_ref": "ORD-IN-BROKEN", "lines": [ {{{ INVALID';
        exit;
    }

    if (isset($state['last_poll'][$endpoint]) && ($now - $state['last_poll'][$endpoint]) < 60) {
        http_response_code(429);
        header('Retry-After: ' . (60 - ($now - $state['last_poll'][$endpoint])));
        echo json_encode(['error' => 'rate_limited']);
        exit;
    }
    $state['last_poll'][$endpoint] = $now;
    saveState($stateFile, $state);

    echo json_encode([
        'orders' => [
            [
                'order_ref' => 'ORD-IN-100045',
                'market' => 'IN',
                'currency' => 'INR',
                'buyer_ref' => 'BUY-IN-7781',
                'created_at' => '2026-06-12T23:50:11+05:30',
                'status' => 'pending_acceptance',
                'lines' => [
                    ['line_ref' => 'L1', 'seller_id' => 'SLR-1001', 'seller_sku' => 'FAST-M8-100', 'qty' => 50, 'unit_price' => '12.40', 'currency' => 'INR'],
                ],
            ],
        ],
        'next_page' => null,
        'next_since' => '2026-06-12T23:50:11+05:30',
    ]);
    exit;
}

// ---------------------------------------------------------------------
// GET /api/offers/inventory -- same 60s floor.
// ---------------------------------------------------------------------
if ($method === 'GET' && $path === '/api/offers/inventory') {
    $endpoint = 'inventory';
    $now = time();
    if (isset($state['last_poll'][$endpoint]) && ($now - $state['last_poll'][$endpoint]) < 60) {
        http_response_code(429);
        header('Retry-After: ' . (60 - ($now - $state['last_poll'][$endpoint])));
        echo json_encode(['error' => 'rate_limited']);
        exit;
    }
    $state['last_poll'][$endpoint] = $now;
    saveState($stateFile, $state);

    echo json_encode([
        'inventory' => [
            ['offer_id' => 'OFR-1001-FASTM8100', 'seller_id' => 'SLR-1001', 'seller_sku' => 'FAST-M8-100',
             'available_qty' => 920, 'version' => 47, 'observed_at' => '2026-06-12T23:49:30+05:30'],
        ],
        'next_page' => null,
        'next_since' => '2026-06-12T23:49:30+05:30',
    ]);
    exit;
}

// ---------------------------------------------------------------------
// POST /erp/v2/purchase-orders -- idempotency-key-aware. If
// ?simulate_timeout=1 is set, the PO IS committed server-side but the
// HTTP response is deliberately delayed past a client-side timeout so the
// CLIENT experiences a timeout/504 even though the write succeeded --
// reproducing case 4 exactly. The client is expected to recover via the
// GET confirm endpoint below, not by blind retry.
// ---------------------------------------------------------------------
if ($method === 'POST' && $path === '/erp/v2/purchase-orders') {
    $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
    $body = json_decode(file_get_contents('php://input'), true);

    if ($idempotencyKey === null) {
        http_response_code(400);
        echo json_encode(['error' => 'missing_idempotency_key']);
        exit;
    }

    if (isset($state['erp_orders'][$idempotencyKey])) {
        // Re-POST with the same key: return the ORIGINAL result, do not
        // create a second PO. This is the behaviour the stub documents as
        // "entirely avoidable -- if you send the key".
        http_response_code(200);
        echo json_encode($state['erp_orders'][$idempotencyKey]);
        exit;
    }

    $po = ['id' => 'po_' . substr(md5($idempotencyKey), 0, 12), 'order_ref' => $body['order_ref'] ?? null, 'status' => 'committed'];
    $state['erp_orders'][$idempotencyKey] = $po;
    saveState($stateFile, $state);

    if (isset($_GET['simulate_timeout'])) {
        // The write above already happened and was saved. Now simulate the
        // documented quirk: sleep past what a sane client timeout would be,
        // so the CLIENT sees a timeout, but the data is already committed.
        sleep(8);
        http_response_code(504);
        echo json_encode(['error' => 'gateway_timeout']);
        exit;
    }

    http_response_code(201);
    echo json_encode($po);
    exit;
}

// ---------------------------------------------------------------------
// GET /erp/v2/purchase-orders/{key} -- confirm what actually happened.
// ---------------------------------------------------------------------
if ($method === 'GET' && str_starts_with($path, '/erp/v2/purchase-orders/')) {
    $key = substr($path, strlen('/erp/v2/purchase-orders/'));
    if (isset($state['erp_orders'][$key])) {
        http_response_code(200);
        echo json_encode($state['erp_orders'][$key]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'not_found']);
    }
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'not_found', 'path' => $path]);
