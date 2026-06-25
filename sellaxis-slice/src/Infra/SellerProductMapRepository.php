<?php

declare(strict_types=1);

namespace Forgeline\Infra;

use PDO;

/**
 * Resolves seller_sku -> magento_sku, and distinguishes the two failure
 * modes the stubs explicitly call out as different (case 5 vs case 6):
 *
 *   - "Missing mapping" (case 5): the seller_sku is plausible -- it might
 *     map to a real product -- but no mapping row exists yet for THIS
 *     seller+sku pair. This is an onboarding gap: Sellaxis doesn't supply
 *     the mapping (see stub field note 6), Forgeline's seller-onboarding
 *     process does, and it hasn't happened yet for this offer.
 *   - "Unknown SKU" (case 6): even if we imagine a mapping existed, there
 *     is no catalogue_products row it could point to. This is not an
 *     onboarding gap, it's a data problem -- the SKU doesn't correspond to
 *     anything Magento has ever heard of.
 *
 * Both resolve() outcomes are terminal-for-now exceptions, not retryable in
 * the way a 500 is -- they need a human (or an onboarding workflow) to add
 * a row, not a backoff timer.
 */
final class SellerProductMapRepository
{
    public function __construct(private PDO $db) {}

    public function resolve(string $sellerId, string $sellerSku): MappingResult
    {
        $stmt = $this->db->prepare(
            'SELECT magento_sku FROM seller_product_map WHERE seller_id = :sid AND seller_sku = :sku'
        );
        $stmt->execute(['sid' => $sellerId, 'sku' => $sellerSku]);
        $magentoSku = $stmt->fetchColumn();

        if ($magentoSku !== false) {
            return MappingResult::mapped((string) $magentoSku);
        }

        // No mapping row exists for this seller+sku. Case 5 vs case 6 is
        // genuinely ambiguous from the mapping table alone -- by definition
        // neither has a row there. The real-world signal that distinguishes
        // them is whether Sellaxis's own offer feed has ever told us this
        // seller_sku exists as a real listing (case 5: it exists, onboarding
        // just hasn't mapped it yet) versus the seller_sku appearing only in
        // an order line with no corresponding offer ever seen (case 6: it
        // doesn't correspond to anything real, "ghost" in the stub's own
        // naming). We treat "no offer on record for this seller+sku" as the
        // case-6 signal, since that mirrors how the actual stub names it
        // (GHOST-SKU-0 has no entry anywhere, not even an unmapped offer).
        $offerStmt = $this->db->prepare(
            'SELECT 1 FROM offers_inventory WHERE seller_id = :sid AND seller_sku = :sku LIMIT 1'
        );
        $offerStmt->execute(['sid' => $sellerId, 'sku' => $sellerSku]);

        if ($offerStmt->fetchColumn()) {
            // A real offer exists for this seller+sku, we just haven't
            // mapped it to a Magento product yet -- case 5.
            return MappingResult::missingMapping();
        }

        // No offer on record at all for this seller+sku -- case 6.
        return MappingResult::unknownSku();
    }
}

final class MappingResult
{
    private function __construct(
        public readonly string $outcome, // 'mapped' | 'missing_mapping' | 'unknown_sku'
        public readonly ?string $magentoSku
    ) {}

    public static function mapped(string $magentoSku): self
    {
        return new self('mapped', $magentoSku);
    }

    public static function missingMapping(): self
    {
        return new self('missing_mapping', null);
    }

    public static function unknownSku(): self
    {
        return new self('unknown_sku', null);
    }

    public function isMapped(): bool
    {
        return $this->outcome === 'mapped';
    }
}
