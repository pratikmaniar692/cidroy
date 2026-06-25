<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Forgeline\Infra\Db;

/**
 * Seeds the fixture described in stub Section 8: ~6 sellers, a sample of
 * offers, and the seller_product_map / catalogue_products rows needed for
 * mapping to resolve correctly for the happy-path SKUs, while deliberately
 * leaving TOOL-RANDOM-X (case 5) and GHOST-SKU-0 (case 6) unmapped, exactly
 * as the stub's mapping_note describes.
 */
$db = Db::connection();

$sellers = [
    ['SLR-1001', 'Konkan Fasteners', 'IN', 'INR', '0.085', 'token-slr-1001'],
    ['SLR-1002', 'Deccan Tooling', 'IN', 'INR', '0.090', 'token-slr-1002'],
    ['SLR-1003', 'Sahyadri Lubricants', 'IN', 'INR', '0.070', 'token-slr-1003'],
    ['SLR-2001', 'Rheinland Elektrik', 'DE', 'EUR', '0.110', 'token-slr-2001'],
    ['SLR-2002', 'Schwarzwald Safety', 'DE', 'EUR', '0.105', 'token-slr-2002'],
    ['SLR-2003', 'Bayern Werkzeug', 'DE', 'EUR', '0.095', 'token-slr-2003'],
];

$stmt = $db->prepare(
    'INSERT INTO sellers (seller_id, name, market, currency, commission_rate, portal_token)
     VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (seller_id) DO NOTHING'
);
foreach ($sellers as $s) {
    $stmt->execute($s);
}

$catalogueProducts = [
    ['frg-bolt-m8x100-zn', null, 'M8x100 Zinc Bolt'],
    ['frg-glove-nitrile', null, 'Nitrile Glove (configurable)'],
    ['frg-glove-nitrile-l', 'frg-glove-nitrile', 'Nitrile Glove - Large'],
    ['frg-grease-ep2-5kg', null, 'EP2 Grease 5kg'],
    ['frg-relay-24v', null, '24V Relay'],
];
$stmt = $db->prepare(
    'INSERT INTO catalogue_products (magento_sku, parent_sku, name) VALUES (?, ?, ?) ON CONFLICT (magento_sku) DO NOTHING'
);
foreach ($catalogueProducts as $p) {
    $stmt->execute($p);
}

$offers = [
    ['OFR-1001-FASTM8100', 'SLR-1001', 'FAST-M8-100', 920, 47, '2026-06-12T23:49:30+05:30'],
    ['OFR-2002-GLOVEL', 'SLR-2002', 'SAFETY-GLOVE-L', 300, 12, '2026-06-12T18:00:00+02:00'],
    ['OFR-1003-LUBEEP2', 'SLR-1003', 'LUBE-EP2-5KG', 40, 5, '2026-06-12T23:30:00+05:30'],
    ['OFR-2001-RELAY24V', 'SLR-2001', 'ELEC-RELAY-24V', 500, 8, '2026-06-12T18:00:00+02:00'],
    // Deliberately present for case 5 (offer exists, no mapping yet):
    ['OFR-1002-TOOLRANDOMX', 'SLR-1002', 'TOOL-RANDOM-X', 10, 1, '2026-06-12T23:30:00+05:30'],
    // Deliberately ABSENT for case 6: GHOST-SKU-0 has no offer row at all.
];
$stmt = $db->prepare(
    'INSERT INTO offers_inventory (offer_id, seller_id, seller_sku, available_qty, version, observed_at)
     VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (offer_id) DO NOTHING'
);
foreach ($offers as $o) {
    $stmt->execute($o);
}

$mappings = [
    ['SLR-1001', 'FAST-M8-100', 'frg-bolt-m8x100-zn'],
    ['SLR-2002', 'SAFETY-GLOVE-L', 'frg-glove-nitrile-l'],
    ['SLR-1003', 'LUBE-EP2-5KG', 'frg-grease-ep2-5kg'],
    ['SLR-2001', 'ELEC-RELAY-24V', 'frg-relay-24v'],
    // TOOL-RANDOM-X and GHOST-SKU-0 intentionally have NO row here.
];
$stmt = $db->prepare(
    'INSERT INTO seller_product_map (seller_id, seller_sku, magento_sku)
     VALUES (?, ?, ?) ON CONFLICT (seller_id, seller_sku) DO NOTHING'
);
foreach ($mappings as $m) {
    $stmt->execute($m);
}

echo "Seed complete: " . count($sellers) . " sellers, " . count($catalogueProducts) . " catalogue products, "
    . count($offers) . " offers, " . count($mappings) . " mappings.\n";
