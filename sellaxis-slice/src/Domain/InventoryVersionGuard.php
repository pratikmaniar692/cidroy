<?php

declare(strict_types=1);

namespace Forgeline\Domain;

/**
 * Resolves stub cases 11 (stale inventory update) and 12 (full snapshot
 * overlapping incrementals) with a single rule, applied identically
 * regardless of which channel the update arrived through:
 *
 *   Apply the incoming (qty, version) only if incoming version > stored
 *   version. Otherwise discard as stale -- not an error, just a no-op.
 *
 * The reason one rule handles both cases: the nightly full snapshot is not
 * given special "authoritative override" status. It is just another
 * version-carrying update. If an incremental update with a higher version
 * landed after the snapshot was generated, the incremental correctly wins,
 * because the comparison doesn't know or care which channel either update
 * came from -- it only compares version numbers. This is what makes
 * "overlapping full and incremental updates" a non-issue instead of a race:
 * there is nothing to race, because there is only one writer rule and it's
 * symmetric in its inputs.
 */
final class InventoryVersionGuard
{
    /**
     * @return bool true if the incoming update should be applied
     */
    public function shouldApply(?int $storedVersion, int $incomingVersion): bool
    {
        if ($storedVersion === null) {
            return true; // first time we've seen this offer
        }
        return $incomingVersion > $storedVersion;
    }
}
