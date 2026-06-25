<?php

declare(strict_types=1);

namespace Forgeline\Tests;

use Forgeline\Domain\EventValidator;

/**
 * Case 10: a batch contains one malformed line item (null seller_sku, a
 * non-numeric qty, a negative unit_price) alongside one perfectly valid
 * line. The malformed line must be quarantined without affecting the
 * valid one -- the whole point of validating per-item rather than
 * per-batch.
 */
final class Case10MalformedItemTest extends DatabaseTestCase
{
    public function test_malformed_line_is_individually_invalid_while_good_line_is_not(): void
    {
        $validator = new EventValidator();

        $goodLine = ['line_ref' => 'L1', 'seller_id' => 'SLR-2001', 'seller_sku' => 'ELEC-RELAY-24V', 'qty' => 10, 'unit_price' => '7.20'];
        $badLine = ['line_ref' => 'L2', 'seller_id' => 'SLR-2001', 'seller_sku' => null, 'qty' => 'oops', 'unit_price' => '-3'];

        $eventWithBothLines = [
            'delivery_id' => 'dlv_batch10', 'event_id' => 'evt_batch10', 'event_type' => 'order.created',
            'data' => [
                'order_ref' => 'ORD-DE-200012', 'market' => 'DE', 'currency' => 'EUR', 'buyer_ref' => 'BUY-DE-1',
                'created_at' => '2026-06-12T18:00:00+02:00', 'lines' => [$goodLine, $badLine],
            ],
        ];

        $result = $validator->validate($eventWithBothLines);

        // The event AS A WHOLE is invalid because it contains a bad line --
        // but the errors array names ONLY line[1], proving the validator
        // inspected each line independently rather than failing the first
        // line too.
        $this->assertFalse($result['valid']);
        $this->assertCount(3, $result['errors'], 'exactly 3 problems with line[1]: seller_sku, qty, unit_price');
        foreach ($result['errors'] as $err) {
            $this->assertStringStartsWith('line[1]:', $err, 'no error should reference line[0], the good line');
        }
    }

    public function test_event_with_only_a_good_line_validates_cleanly(): void
    {
        $validator = new EventValidator();
        $goodOnly = [
            'delivery_id' => 'dlv_good', 'event_id' => 'evt_good', 'event_type' => 'order.created',
            'data' => [
                'order_ref' => 'ORD-DE-200013', 'market' => 'DE', 'currency' => 'EUR', 'buyer_ref' => 'BUY-DE-2',
                'created_at' => '2026-06-12T18:00:00+02:00',
                'lines' => [['line_ref' => 'L1', 'seller_id' => 'SLR-2001', 'seller_sku' => 'ELEC-RELAY-24V', 'qty' => 10, 'unit_price' => '7.20']],
            ],
        ];
        $result = $validator->validate($goodOnly);
        $this->assertTrue($result['valid']);
    }
}
