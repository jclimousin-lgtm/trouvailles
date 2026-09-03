<?php

declare(strict_types=1);

/**
 * TRV-UI-002 — tests OpportunityRepository. Touche la base locale, dans
 * une transaction annulée en fin de fichier (comme tests/run.php).
 * Usage : php tests/run_opportunity_repository.php
 */

const ROOT = __DIR__ . '/..';

require __DIR__ . '/assertions.php';
require __DIR__ . '/TestRunner.php';
require ROOT . '/app/Core/autoload.php';

use Trouvailles\Core\Database;
use Trouvailles\Persistence\OpportunityRepository;

$runner = new TestRunner();

$pdo = Database::connection();

$runner->run('Aucune opportunité en base -> liste vide (état réel actuel)', function () use ($pdo) {
    $repo = new OpportunityRepository($pdo);
    $result = $repo->findRecent();
    assertEquals([], $result, 'La base ne contient aujourd\'hui aucune opportunité réelle : la liste doit être vide, jamais fabriquée');
});

$pdo->beginTransaction();

try {
    $sourceId = (int) $pdo->query("SELECT id FROM sources WHERE code = 'leboncoin'")->fetch()['id'];

    $stmt = $pdo->prepare(
        "INSERT INTO listings (source_id, external_id, url, title, brand, status)
         VALUES (:sid, :ext, :url, :title, :brand, 'active')"
    );
    $stmt->execute(['sid' => $sourceId, 'ext' => 'EXT-OPP-1', 'url' => 'https://example.test/1', 'title' => 'Sony A7 III', 'brand' => 'Sony']);
    $listing1 = (int) $pdo->lastInsertId();

    $stmt->execute(['sid' => $sourceId, 'ext' => 'EXT-OPP-2', 'url' => 'https://example.test/2', 'title' => 'Vélo Decathlon', 'brand' => 'Decathlon']);
    $listing2 = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare(
        "INSERT INTO products (canonical_name) VALUES ('Produit test')"
    );
    $stmt->execute();
    $productId = (int) $pdo->lastInsertId();

    $insertValuation = function (string $status) use ($pdo, $productId): int {
        $stmt = $pdo->prepare(
            "INSERT INTO market_valuations (product_id, method_version, value_low, value_central, value_high, currency, valuation_status)
             VALUES (:pid, 'test-v1', 700, 850, 1000, 'EUR', :status)"
        );
        $stmt->execute(['pid' => $productId, 'status' => $status]);
        return (int) $pdo->lastInsertId();
    };

    $valuationValid = $insertValuation('valid');
    $valuationThin = $insertValuation('thin_evidence');

    $insertOpportunity = function (int $listingId, int $valuationId, string $detectedAt) use ($pdo): int {
        $stmt = $pdo->prepare(
            "INSERT INTO opportunities (listing_id, valuation_id, asking_price, market_value, discount_percentage, min_discount, status, detected_at)
             VALUES (:lid, :vid, 620, 850, 27.06, 20, 'detected', :detected_at)"
        );
        $stmt->execute(['lid' => $listingId, 'vid' => $valuationId, 'detected_at' => $detectedAt]);
        return (int) $pdo->lastInsertId();
    };

    $insertOpportunity($listing1, $valuationValid, '2026-09-04 10:00:00');
    $insertOpportunity($listing2, $valuationThin, '2026-09-04 11:00:00');

    $runner->run('Une opportunité réelle est correctement assemblée (annonce + prix + valeur + décote + confiance + source)', function () use ($pdo) {
        $repo = new OpportunityRepository($pdo);
        $rows = $repo->findRecent();

        assertEquals(2, count($rows), 'Les deux opportunités insérées doivent être retournées');

        $premiere = $rows[0]; // la plus récente en premier (detected_at DESC)
        assertEquals('Vélo Decathlon', $premiere['title'], 'title doit venir de listings.title');
        assertEquals('leboncoin', $premiere['source_code'], 'source_code doit venir de sources.code');
        assertEquals('Leboncoin', $premiere['source_name'], 'source_name doit venir de sources.name');
        assertEquals('thin_evidence', $premiere['valuation_status'], 'valuation_status doit venir de market_valuations, jamais recalculé');
        assertEquals('620.00', $premiere['asking_price'], 'asking_price doit venir de opportunities.asking_price');
        assertEquals('850.00', $premiere['market_value'], 'market_value doit venir de opportunities.market_value, jamais recalculé');
        assertEquals('27.06', $premiere['discount_percentage'], 'discount_percentage doit venir de opportunities.discount_percentage, jamais recalculé');
        assertTrue(is_numeric($premiere['secondes_ecoulees']), 'secondes_ecoulees doit être calculé par MySQL (TIMESTAMPDIFF), jamais par l\'horloge PHP');

        $seconde = $rows[1];
        assertEquals('Sony A7 III', $seconde['title'], 'La seconde ligne doit correspondre à la première opportunité insérée');
        assertEquals('valid', $seconde['valuation_status'], 'valuation_status doit rester celui de sa propre valorisation');
    });

    $runner->run('findRecent() respecte la limite demandée', function () use ($pdo) {
        $repo = new OpportunityRepository($pdo);
        $rows = $repo->findRecent(1);
        assertEquals(1, count($rows), 'Un seul résultat doit être retourné avec limit=1');
        assertEquals('Vélo Decathlon', $rows[0]['title'], 'Le résultat le plus récent doit être conservé');
    });
} finally {
    $pdo->rollBack();
}

exit($runner->report());
