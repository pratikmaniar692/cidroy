<?php

declare(strict_types=1);

namespace Forgeline\Tests;

use Forgeline\Domain\InventoryVersionGuard;
use Forgeline\Infra\InventoryRepository;

/**
 * Case 11: an inventory update with a LOWER version than what's stored
 * must be discarded as stale, regardless of arrival order or observed_at.
 * Case 12: a nightly full snapshot carrying an OLDER version than an
 * incremental that already landed must not clobber the newer value -- and
 * critically, this is proven using the EXACT SAME mechanism as case 11,
 * not a special "snapshot vs incremental" rule. That's the whole point.
 */
final class Case11And12InventoryVersionTest extends DatabaseTestCase
{
    public function test_stale_update_with_lower_version_is_discarded(): void
    {
        // Fixture already has OFR-1001-FASTM8100 at version 47, qty 920.
        $inventory = new InventoryRepository($this->db, new InventoryVersionGuard());

        $outcome = $inventory->applyIfNewer(
            'OFR-1001-FASTM8100', 'SLR-1001', 'FAST-M8-100',
            availableQty: 50, version: 46, observedAt: new \DateTimeImmutable('2026-06-12T23:48:00+05:30'),
            source: 'webhook'
        );

        $this->assertSame('discarded_stale', $outcome);

        $stored = $inventory->find('OFR-1001-FASTM8100');
        $this->assertSame(920, (int) $stored['available_qty'], 'qty must remain unchanged -- the stale update must not apply');
        $this->assertSame(47, (int) $stored['version']);
    }

    public function test_full_snapshot_with_older_version_does_not_clobber_newer_incremental(): void
    {
        $inventory = new InventoryRepository($this->db, new InventoryVersionGuard());

        // An incremental update at version 47 has already landed (it's in
        // the fixture). Now a nightly full snapshot arrives carrying
        // version 45 for the SAME offer -- generated before the
        // incremental, but delivered after.
        $outcome = $inventory->applyIfNewer(
            'OFR-1001-FASTM8100', 'SLR-1001', 'FAST-M8-100',
            availableQty: 900, version: 45, observedAt: new \DateTimeImmutable('2026-06-12T20:00:00+05:30'),
            source: 'snapshot'
        );

        $this->assertSame('discarded_stale', $outcome, 'the snapshot is NOT given special override status -- it follows the same version rule as everything else');

        $stored = $inventory->find('OFR-1001-FASTM8100');
        $this->assertSame(920, (int) $stored['available_qty']);
        $this->assertSame(47, (int) $stored['version']);
    }

    public function test_newer_version_from_any_source_is_applied(): void
    {
        $inventory = new InventoryRepository($this->db, new InventoryVersionGuard());

        $outcome = $inventory->applyIfNewer(
            'OFR-1001-FASTM8100', 'SLR-1001', 'FAST-M8-100',
            availableQty: 870, version: 48, observedAt: new \DateTimeImmutable('2026-06-13T00:00:00+05:30'),
            source: 'poll'
        );

        $this->assertSame('applied', $outcome);
        $stored = $inventory->find('OFR-1001-FASTM8100');
        $this->assertSame(870, (int) $stored['available_qty']);
        $this->assertSame(48, (int) $stored['version']);
    }
}
