<?php

declare(strict_types=1);

namespace Forgeline\Http;

use Forgeline\Infra\Db;
use Forgeline\Infra\Logger;
use Forgeline\Infra\OrderRepository;

/**
 * Case 14: a seller attempting to access another seller's order "must be
 * denied server-side from the authenticated identity, not by a supplied
 * parameter." This controller is the demonstration of that rule: the
 * seller_id used for the authorization check comes ONLY from the
 * Authorization header's bearer token, resolved to a seller_id via the
 * `sellers.portal_token` column. Any seller_id in the URL or query string
 * is informational at best and is NEVER used for the authorization
 * decision -- see how authenticatedSellerId() is the only source the check
 * reads from.
 */
final class OrdersController
{
    public function __construct(private OrderRepository $orders) {}

    public function show(array $params): void
    {
        $orderRef = $params['order_ref'];
        $authenticatedSellerId = $this->authenticatedSellerId();

        if ($authenticatedSellerId === null) {
            http_response_code(401);
            echo json_encode(['error' => 'missing_or_invalid_bearer_token']);
            return;
        }

        $order = $this->orders->findOrderWithLines($orderRef);
        if ($order === null) {
            http_response_code(404);
            echo json_encode(['error' => 'order_not_found']);
            return;
        }

        // The authorization check: does ANY line on this order belong to
        // the authenticated seller? If not, deny -- regardless of what a
        // seller_id query parameter might claim. This is the entire fix
        // for case 14; everything above it is just plumbing to get here.
        $ownsAnyLine = false;
        foreach ($order['lines'] as $line) {
            if ($line['seller_id'] === $authenticatedSellerId) {
                $ownsAnyLine = true;
                break;
            }
        }

        if (!$ownsAnyLine) {
            Logger::warn('cross_seller_access_denied', [
                'order_ref' => $orderRef, 'authenticated_seller_id' => $authenticatedSellerId,
            ]);
            http_response_code(403);
            echo json_encode(['error' => 'forbidden', 'reason' => 'order does not belong to authenticated seller']);
            return;
        }

        // Scope the response itself to only the authenticated seller's own
        // lines on this order -- a multi-seller order's other lines belong
        // to other sellers and are not this seller's business either.
        $order['lines'] = array_values(array_filter(
            $order['lines'],
            fn($l) => $l['seller_id'] === $authenticatedSellerId
        ));

        header('Content-Type: application/json');
        echo json_encode($order, JSON_PRETTY_PRINT);
    }

    private function authenticatedSellerId(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }
        $token = substr($header, 7);
        $stmt = Db::connection()->prepare('SELECT seller_id FROM sellers WHERE portal_token = :token');
        $stmt->execute(['token' => $token]);
        $sellerId = $stmt->fetchColumn();
        return $sellerId === false ? null : (string) $sellerId;
    }
}
