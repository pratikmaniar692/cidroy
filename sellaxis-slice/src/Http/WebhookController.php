<?php

declare(strict_types=1);

namespace Forgeline\Http;

use Forgeline\Domain\EventValidator;
use Forgeline\Domain\OrderProcessor;
use Forgeline\Infra\InboundEventRepository;
use Forgeline\Infra\Logger;
use Forgeline\Infra\WebhookSignatureVerifier;

final class WebhookController
{
    public function __construct(
        private WebhookSignatureVerifier $verifier,
        private EventValidator $validator,
        private OrderProcessor $processor,
        private InboundEventRepository $events,
    ) {}

    /**
     * Sellaxis webhooks may deliver a single event or (per field note 2,
     * "held and delivered in a batch") an array of events. We accept both
     * shapes. Field note 9: "Return 2xx only once you have durably
     * accepted the event" -- note "accepted", not "fully processed". We
     * acknowledge once every item has at least been durably recorded
     * (received or quarantined), even if some items are held pending a
     * predecessor event. A 2xx here means "Sellaxis never needs to redeliver
     * this delivery_id again", which is true the moment we've durably
     * stored it, whether or not we could immediately apply it.
     */
    public function handle(): void
    {
        $rawBody = file_get_contents('php://input');
        $signatureHeader = $_SERVER['HTTP_X_SELLAXIS_SIGNATURE'] ?? null;

        if (!$this->verifier->verify($rawBody, $signatureHeader)) {
            Logger::warn('webhook_signature_invalid', ['has_header' => $signatureHeader !== null]);
            http_response_code(401);
            echo json_encode(['error' => 'invalid_signature']);
            return;
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_json']);
            return;
        }

        // Normalise to a list of events regardless of single-vs-batch shape.
        $events = isset($decoded['delivery_id']) ? [$decoded] : $decoded;

        $results = [];
        foreach ($events as $i => $event) {
            $results[] = $this->handleOneEvent($event, $i);
        }

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['accepted' => count($results), 'results' => $results]);
    }

    private function handleOneEvent(mixed $event, int $index): array
    {
        if (!is_array($event)) {
            Logger::error('webhook_item_malformed', ['index' => $index, 'reason' => 'not_an_object']);
            return ['index' => $index, 'outcome' => 'quarantined', 'reason' => 'not_an_object'];
        }

        $validation = $this->validator->validate($event);
        if (!$validation['valid']) {
            // Case 10: this item is garbage, but it must not affect any
            // other item in the same batch. We still durably record it
            // (quarantined) so it's visible via the reconciliation API and
            // so a redelivery of the SAME delivery_id doesn't reprocess it
            // blindly.
            $deliveryId = $event['delivery_id'] ?? ('unknown_' . bin2hex(random_bytes(6)));
            $this->events->recordDelivery(
                $deliveryId,
                $event['event_id'] ?? $deliveryId,
                $event['event_type'] ?? 'unknown',
                null,
                $event['data']['order_ref'] ?? null,
                $event['data']['line_ref'] ?? null,
                $event,
                'quarantined',
                implode('; ', $validation['errors'])
            );
            Logger::warn('webhook_item_quarantined', ['index' => $index, 'errors' => $validation['errors']]);
            return ['index' => $index, 'outcome' => 'quarantined', 'errors' => $validation['errors']];
        }

        $outcome = $this->processor->processEvent($event);
        return ['index' => $index, 'outcome' => $outcome];
    }
}
