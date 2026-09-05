<?php

declare(strict_types=1);

/**
 * TRV-004 — tests de ValuationEngine. Touche la base locale, dans une
 * transaction annulée en fin de fichier (comme tests/run_listing_persister.php).
 * Usage : php tests/run_valuation_engine.php
 */

const ROOT = __DIR__ . '/..';

require __DIR__ . '/assertions.php';
require __DIR__ . '/TestRunner.php';
require ROOT . '/app/Core/autoload.php';

use Trouvailles\Core\Database;
use Trouvailles\Pricing\ValuationEngine;

function makeValuationProduct(PDO $pdo, string $name): int
{
    $stmt = $pdo->prepare('INSERT INTO products (canonical_name) VALUES (:name)');
    $stmt->execute(['name' => $name]);
    return (int) $pdo->lastInsertId();
}

function makeListingWithObservation(
    PDO $pdo,
    int $sourceId,
    int $productId,
    string $externalId,
    float $amount,
    string $currency,
    string $observedAt,
    string $evidenceType = 'active_fixed_price',
    float $matchConfidence = 0.9
): int {
    $stmt = $pdo->prepare(
        "INSERT INTO listings (source_id, external_id, url, title, status)
         VALUES (:sid, :ext, :url, 'Titre test', 'active')"
    );
    $stmt->execute(['sid' => $sourceId, 'ext' => $externalId, 'url' => "https://example.test/{$externalId}"]);
    $listingId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare(
        "INSERT INTO listing_products (listing_id, product_id, match_method, match_confidence, is_variant_exact)
         VALUES (:lid, :pid, 'fuzzy_match', :confidence, 0)"
    );
    $stmt->execute(['lid' => $listingId, 'pid' => $productId, 'confidence' => $matchConfidence]);

    $stmt = $pdo->prepare(
        "INSERT INTO price_observations (listing_id, source_id, product_id, amount, currency, price_type, observed_at, evidence_type)
         VALUES (:lid, :sid, :pid, :amount, :currency, 'asking', :observed_at, :evidence_type)"
    );
    $stmt->execute([
        'lid' => $listingId, 'sid' => $sourceId, 'pid' => $productId,
        'amount' => $amount, 'currency' => $currency, 'observed_at' => $observedAt, 'evidence_type' => $evidenceType,
    ]);

    return $listingId;
}

function addObservation(
    PDO $pdo,
    int $listingId,
    int $sourceId,
    int $productId,
    float $amount,
    string $currency,
    string $observedAt,
    string $evidenceType = 'active_fixed_price'
): void {
    $stmt = $pdo->prepare(
        "INSERT INTO price_observations (listing_id, source_id, product_id, amount, currency, price_type, observed_at, evidence_type)
         VALUES (:lid, :sid, :pid, :amount, :currency, 'asking', :observed_at, :evidence_type)"
    );
    $stmt->execute([
        'lid' => $listingId, 'sid' => $sourceId, 'pid' => $productId,
        'amount' => $amount, 'currency' => $currency, 'observed_at' => $observedAt, 'evidence_type' => $evidenceType,
    ]);
}

function fetchValuation(PDO $pdo, int $valuationId): array
{
    $stmt = $pdo->prepare('SELECT * FROM market_valuations WHERE id = :id');
    $stmt->execute(['id' => $valuationId]);
    return $stmt->fetch();
}

function fetchComparables(PDO $pdo, int $valuationId): array
{
    $stmt = $pdo->prepare('SELECT * FROM valuation_comparables WHERE valuation_id = :id');
    $stmt->execute(['id' => $valuationId]);
    return $stmt->fetchAll();
}

$runner = new TestRunner();
$pdo = Database::connection();
$pdo->beginTransaction();

try {
    $sourceId = (int) $pdo->query("SELECT id FROM sources WHERE code = 'ebay'")->fetch()['id'];
    $now = new DateTimeImmutable();

    $runner->run('0 comparable -> aucune ligne market_valuations créée, valuation_id null', function () use ($pdo) {
        $productId = makeValuationProduct($pdo, 'Produit sans observation');
        $engine = new ValuationEngine($pdo);
        $result = $engine->valuateProduct($productId);

        assertEquals(null, $result['valuation_id'], 'aucune observation -> aucune valorisation créée');
        assertEquals(0, $result['comparable_count'], 'comparable_count doit etre 0');
    });

    $runner->run('1 comparable -> insufficient_evidence, low=central=high', function () use ($pdo, $sourceId, $now) {
        $productId = makeValuationProduct($pdo, 'Produit un comparable');
        makeListingWithObservation($pdo, $sourceId, $productId, 'EXT-VAL-1A', 100.0, 'USD', $now->format('Y-m-d H:i:s'));

        $engine = new ValuationEngine($pdo);
        $result = $engine->valuateProduct($productId);

        assertEquals('insufficient_evidence', $result['status'], 'status attendu insufficient_evidence');
        assertEquals(1, $result['comparable_count'], 'comparable_count doit etre 1');

        $valuation = fetchValuation($pdo, $result['valuation_id']);
        assertEquals('100.00', $valuation['value_low'], 'value_low doit valoir 100');
        assertEquals('100.00', $valuation['value_central'], 'value_central doit valoir 100');
        assertEquals('100.00', $valuation['value_high'], 'value_high doit valoir 100');
        assertEquals(null, $valuation['liquidity_score'], 'liquidity_score toujours NULL en v1 (hors périmètre)');
    });

    $runner->run('3 comparables -> thin_evidence', function () use ($pdo, $sourceId, $now) {
        $productId = makeValuationProduct($pdo, 'Produit trois comparables');
        makeListingWithObservation($pdo, $sourceId, $productId, 'EXT-VAL-3A', 90.0, 'USD', $now->format('Y-m-d H:i:s'));
        makeListingWithObservation($pdo, $sourceId, $productId, 'EXT-VAL-3B', 100.0, 'USD', $now->format('Y-m-d H:i:s'));
        makeListingWithObservation($pdo, $sourceId, $productId, 'EXT-VAL-3C', 110.0, 'USD', $now->format('Y-m-d H:i:s'));

        $engine = new ValuationEngine($pdo);
        $result = $engine->valuateProduct($productId);

        assertEquals('thin_evidence', $result['status'], 'status attendu thin_evidence');
        assertEquals(3, $result['comparable_count'], 'comparable_count doit etre 3');
    });

    $runner->run('6 comparables variés -> valid, low <= central <= high', function () use ($pdo, $sourceId, $now) {
        $productId = makeValuationProduct($pdo, 'Produit six comparables');
        foreach ([80.0, 90.0, 100.0, 105.0, 110.0, 200.0] as $i => $amount) {
            makeListingWithObservation($pdo, $sourceId, $productId, "EXT-VAL-6-{$i}", $amount, 'USD', $now->format('Y-m-d H:i:s'));
        }

        $engine = new ValuationEngine($pdo);
        $result = $engine->valuateProduct($productId);

        assertEquals('valid', $result['status'], 'status attendu valid');
        assertEquals(6, $result['comparable_count'], 'comparable_count doit etre 6');

        $valuation = fetchValuation($pdo, $result['valuation_id']);
        assertTrue((float) $valuation['value_low'] <= (float) $valuation['value_central'], 'low <= central');
        assertTrue((float) $valuation['value_central'] <= (float) $valuation['value_high'], 'central <= high');
    });

    $runner->run('Observation hors fenêtre (>30j) -> rejetée outside_time_window, absente du calcul', function () use ($pdo, $sourceId, $now) {
        $productId = makeValuationProduct($pdo, 'Produit fenetre temporelle');
        makeListingWithObservation($pdo, $sourceId, $productId, 'EXT-VAL-WIN-RECENT', 100.0, 'USD', $now->format('Y-m-d H:i:s'));
        $old = $now->modify('-40 days')->format('Y-m-d H:i:s');
        makeListingWithObservation($pdo, $sourceId, $productId, 'EXT-VAL-WIN-OLD', 500.0, 'USD', $old);

        $engine = new ValuationEngine($pdo);
        $result = $engine->valuateProduct($productId);

        assertEquals(1, $result['comparable_count'], 'seule l\'observation récente doit compter');
        $valuation = fetchValuation($pdo, $result['valuation_id']);
        assertEquals('100.00', $valuation['value_central'], 'l\'observation hors fenêtre (500) ne doit jamais influencer le calcul');

        $comparables = fetchComparables($pdo, $result['valuation_id']);
        $rejected = array_values(array_filter($comparables, static fn ($c) => $c['acceptance_status'] === 'rejected'));
        assertEquals(1, count($rejected), 'une ligne rejetée attendue pour l\'observation hors fenêtre');
        assertEquals('outside_time_window', $rejected[0]['rejection_reason'], 'raison de rejet attendue outside_time_window');
    });

    $runner->run('Deux observations du même listing -> seule la plus récente comptée', function () use ($pdo, $sourceId, $now) {
        $productId = makeValuationProduct($pdo, 'Produit meme listing');
        $listingId = makeListingWithObservation($pdo, $sourceId, $productId, 'EXT-VAL-SUP-1', 100.0, 'USD', $now->modify('-2 days')->format('Y-m-d H:i:s'));
        addObservation($pdo, $listingId, $sourceId, $productId, 120.0, 'USD', $now->format('Y-m-d H:i:s'));

        $engine = new ValuationEngine($pdo);
        $result = $engine->valuateProduct($productId);

        assertEquals(1, $result['comparable_count'], 'un seul comparable indépendant malgré deux observations du même listing');
        $valuation = fetchValuation($pdo, $result['valuation_id']);
        assertEquals('120.00', $valuation['value_central'], 'la plus récente (120) doit être retenue, pas la plus ancienne (100)');

        $comparables = fetchComparables($pdo, $result['valuation_id']);
        $rejected = array_values(array_filter($comparables, static fn ($c) => $c['acceptance_status'] === 'rejected'));
        assertEquals(1, count($rejected), 'une seule ligne rejetee attendue');
        assertEquals('superseded_by_newer_observation_same_listing', $rejected[0]['rejection_reason'], 'raison de rejet attendue superseded_by_newer_observation_same_listing');
    });

    $runner->run('Devise minoritaire rejetée (currency_mismatch), devise majoritaire retenue', function () use ($pdo, $sourceId, $now) {
        $productId = makeValuationProduct($pdo, 'Produit devise mixte');
        makeListingWithObservation($pdo, $sourceId, $productId, 'EXT-VAL-CUR-1', 100.0, 'USD', $now->format('Y-m-d H:i:s'));
        makeListingWithObservation($pdo, $sourceId, $productId, 'EXT-VAL-CUR-2', 110.0, 'USD', $now->format('Y-m-d H:i:s'));
        makeListingWithObservation($pdo, $sourceId, $productId, 'EXT-VAL-CUR-3', 90.0, 'EUR', $now->format('Y-m-d H:i:s'));

        $engine = new ValuationEngine($pdo);
        $result = $engine->valuateProduct($productId);

        assertEquals(2, $result['comparable_count'], 'seuls les deux USD (majoritaire) doivent compter');
        $valuation = fetchValuation($pdo, $result['valuation_id']);
        assertEquals('USD', $valuation['currency'], 'la devise majoritaire doit être retenue, jamais convertie');

        $comparables = fetchComparables($pdo, $result['valuation_id']);
        $rejected = array_values(array_filter($comparables, static fn ($c) => $c['acceptance_status'] === 'rejected'));
        assertEquals(1, count($rejected), 'une seule ligne rejetee attendue');
        assertEquals('currency_mismatch', $rejected[0]['rejection_reason'], 'raison de rejet attendue currency_mismatch');
    });

    $runner->run('evidence_type non actif (unknown) -> invisible du tout, aucune ligne valuation_comparables', function () use ($pdo, $sourceId, $now) {
        $productId = makeValuationProduct($pdo, 'Produit evidence inactif');
        makeListingWithObservation($pdo, $sourceId, $productId, 'EXT-VAL-EVID-1', 100.0, 'USD', $now->format('Y-m-d H:i:s'), 'active_fixed_price');
        makeListingWithObservation($pdo, $sourceId, $productId, 'EXT-VAL-EVID-2', 999.0, 'USD', $now->format('Y-m-d H:i:s'), 'unknown');

        $engine = new ValuationEngine($pdo);
        $result = $engine->valuateProduct($productId);

        assertEquals(1, $result['comparable_count'], 'evidence_type=unknown ne doit jamais compter');
        $comparables = fetchComparables($pdo, $result['valuation_id']);
        assertEquals(1, count($comparables), 'aucune ligne (ni acceptée ni rejetée) pour l\'observation evidence_type=unknown');
    });

    $runner->run('similarity_score des comparables acceptés = match_confidence de listing_products', function () use ($pdo, $sourceId, $now) {
        $productId = makeValuationProduct($pdo, 'Produit similarity score');
        makeListingWithObservation($pdo, $sourceId, $productId, 'EXT-VAL-SIM-1', 100.0, 'USD', $now->format('Y-m-d H:i:s'), 'active_fixed_price', 0.73);

        $engine = new ValuationEngine($pdo);
        $result = $engine->valuateProduct($productId);

        $comparables = fetchComparables($pdo, $result['valuation_id']);
        assertEquals('0.730000', $comparables[0]['similarity_score'], 'similarity_score doit reprendre match_confidence, jamais un score inventé');
        assertEquals(null, $comparables[0]['weight'], 'weight doit rester NULL en v1 (calcul non pondéré)');
    });

    $runner->run('confidence_score/label cohérents avec le volume et la dispersion', function () use ($pdo, $sourceId, $now) {
        $productIdSerre = makeValuationProduct($pdo, 'Produit dispersion faible');
        foreach ([99.0, 100.0, 100.0, 101.0, 100.0] as $i => $amount) {
            makeListingWithObservation($pdo, $sourceId, $productIdSerre, "EXT-VAL-CONF-A-{$i}", $amount, 'USD', $now->format('Y-m-d H:i:s'));
        }
        $engine = new ValuationEngine($pdo);
        $resultSerre = $engine->valuateProduct($productIdSerre);
        $valuationSerre = fetchValuation($pdo, $resultSerre['valuation_id']);
        assertTrue((float) $valuationSerre['confidence_score'] >= 0.7, 'volume suffisant + faible dispersion -> confiance élevée');
        assertEquals('high', $valuationSerre['confidence_label'], 'confidence_label attendu high');

        $productIdDisperse = makeValuationProduct($pdo, 'Produit dispersion forte');
        foreach ([10.0, 50.0, 100.0, 150.0, 300.0] as $i => $amount) {
            makeListingWithObservation($pdo, $sourceId, $productIdDisperse, "EXT-VAL-CONF-B-{$i}", $amount, 'USD', $now->format('Y-m-d H:i:s'));
        }
        $resultDisperse = $engine->valuateProduct($productIdDisperse);
        $valuationDisperse = fetchValuation($pdo, $resultDisperse['valuation_id']);
        assertTrue((float) $valuationDisperse['confidence_score'] < (float) $valuationSerre['confidence_score'], 'une forte dispersion doit réduire la confiance');
    });

    $runner->run('valuateAllProducts() traite tous les produits ayant des observations', function () use ($pdo, $sourceId, $now) {
        $p1 = makeValuationProduct($pdo, 'Produit lot A');
        makeListingWithObservation($pdo, $sourceId, $p1, 'EXT-VAL-ALL-1', 100.0, 'USD', $now->format('Y-m-d H:i:s'));
        $p2 = makeValuationProduct($pdo, 'Produit lot B');
        makeListingWithObservation($pdo, $sourceId, $p2, 'EXT-VAL-ALL-2', 200.0, 'USD', $now->format('Y-m-d H:i:s'));

        $engine = new ValuationEngine($pdo);
        $results = $engine->valuateAllProducts();

        $productIds = array_column($results, 'product_id');
        assertTrue(in_array($p1, $productIds, true), 'produit A doit être traité');
        assertTrue(in_array($p2, $productIds, true), 'produit B doit être traité');
    });
} finally {
    $pdo->rollBack();
}

exit($runner->report());
