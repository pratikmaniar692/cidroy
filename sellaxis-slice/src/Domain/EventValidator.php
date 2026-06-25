<?php

declare(strict_types=1);

namespace Forgeline\Domain;

/**
 * Validates and normalises a single event envelope (or a single item
 * within a batch). This is deliberately a pure function over one item at a
 * time -- the caller (WebhookController) is responsible for iterating a
 * batch and calling this per-item, which is what makes case 10 (one
 * malformed item in an otherwise valid batch) a non-event: a malformed item
 * fails validation on its own, gets quarantined on its own, and never
 * touches or blocks any other item in the same batch.
 */
final class EventValidator
{
    /**
     * @return array{valid: bool, errors: array<string>}
     */
    public function validate(array $event): array
    {
        $errors = [];

        foreach (['delivery_id', 'event_id', 'event_type', 'data'] as $required) {
            if (!array_key_exists($required, $event)) {
                $errors[] = "missing required field '{$required}'";
            }
        }
        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        if (!is_array($event['data'])) {
            $errors[] = "'data' must be an object";
            return ['valid' => false, 'errors' => $errors];
        }

        $eventType = $event['event_type'];
        $data = $event['data'];

        if ($eventType === 'order.created') {
            if (empty($data['order_ref']) || !is_string($data['order_ref'])) {
                $errors[] = 'order_ref missing or not a string';
            }
            if (empty($data['lines']) || !is_array($data['lines'])) {
                $errors[] = 'lines missing or not an array';
            } else {
                foreach ($data['lines'] as $i => $line) {
                    $lineErrors = $this->validateLine($line);
                    foreach ($lineErrors as $le) {
                        $errors[] = "line[{$i}]: {$le}";
                    }
                }
            }
        }

        if ($eventType === 'inventory.changed') {
            if (empty($data['offer_id'])) {
                $errors[] = 'offer_id missing';
            }
            if (!isset($data['version']) || !is_numeric($data['version'])) {
                $errors[] = 'version missing or not numeric';
            }
            if (!isset($data['available_qty']) || !is_numeric($data['available_qty']) || (int) $data['available_qty'] < 0) {
                $errors[] = 'available_qty missing, not numeric, or negative';
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * Line-level validation -- this is what catches case 10's specific
     * garbage line: seller_sku null, qty "oops" (non-numeric), unit_price
     * "-3" (negative). Each check is independent so the error list names
     * everything wrong with the line, not just the first problem found.
     */
    private function validateLine(mixed $line): array
    {
        $errors = [];
        if (!is_array($line)) {
            return ['line is not an object'];
        }
        if (empty($line['line_ref'])) {
            $errors[] = 'line_ref missing';
        }
        if (empty($line['seller_sku']) || !is_string($line['seller_sku'])) {
            $errors[] = 'seller_sku missing or not a string';
        }
        if (!isset($line['qty']) || !is_numeric($line['qty']) || (int) $line['qty'] <= 0) {
            $errors[] = "qty missing, not numeric, or not positive (" . json_encode($line['qty'] ?? null) . ")";
        }
        if (!isset($line['unit_price']) || !preg_match('/^\d+(\.\d+)?$/', (string) $line['unit_price'])) {
            $errors[] = "unit_price missing or not a non-negative decimal string (" . json_encode($line['unit_price'] ?? null) . ")";
        }
        if (empty($line['seller_id'])) {
            $errors[] = 'seller_id missing';
        }
        return $errors;
    }
}
