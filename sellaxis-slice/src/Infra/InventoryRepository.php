<?php

declare(strict_types=1);

namespace Forgeline\Infra;

use Forgeline\Domain\InventoryVersionGuard;
use PDO;

final class InventoryRepository
{
    public function __construct(private PDO $db, private InventoryVersionGuard $guard) {}

    /**
     * Applies an inventory update if and only if its version is newer than
     * what's stored, regardless of whether it arrived via webhook, poll, or
     * the nightly snapshot (the `source` parameter is for the audit log
     * only -- it never changes the rule). Returns the outcome so the caller
     * can log/respond appropriately. This single method is the entire fix
     * for stub cases 11 and 12.
     */
    public function applyIfNewer(
        string $offerId,
        string $sellerId,
        string $sellerSku,
        int $availableQty,
        int $version,
        \DateTimeImmutable $observedAt,
        string $source
    ): string {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'SELECT version FROM offers_inventory WHERE offer_id = :id FOR UPDATE'
            );
            $stmt->execute(['id' => $offerId]);
            $storedVersion = $stmt->fetchColumn();
            $storedVersion = $storedVersion === false ? null : (int) $storedVersion;

            $shouldApply = $this->guard->shouldApply($storedVersion, $version);

            if ($shouldApply) {
                $upsert = $this->db->prepare(
                    'INSERT INTO offers_inventory (offer_id, seller_id, seller_sku, available_qty, version, observed_at)
                     VALUES (:id, :sid, :sku, :qty, :ver, :obs)
                     ON CONFLICT (offer_id) DO UPDATE SET
                        available_qty = EXCLUDED.available_qty,
                        version = EXCLUDED.version,
                        observed_at = EXCLUDED.observed_at,
                        updated_at = now()'
                );
                $upsert->execute([
                    'id' => $offerId, 'sid' => $sellerId, 'sku' => $sellerSku,
                    'qty' => $availableQty, 'ver' => $version, 'obs' => $observedAt->format('c'),
                ]);
            }

            $log = $this->db->prepare(
                'INSERT INTO inventory_update_log (offer_id, incoming_version, stored_version_before, outcome, source)
                 VALUES (:id, :inc, :before, :outcome, :source)'
            );
            $log->execute([
                'id' => $offerId,
                'inc' => $version,
                'before' => $storedVersion,
                'outcome' => $shouldApply ? 'applied' : 'discarded_stale',
                'source' => $source,
            ]);

            $this->db->commit();
            return $shouldApply ? 'applied' : 'discarded_stale';
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function find(string $offerId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM offers_inventory WHERE offer_id = :id');
        $stmt->execute(['id' => $offerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function recentLog(int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM inventory_update_log ORDER BY logged_at DESC LIMIT :limit'
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
