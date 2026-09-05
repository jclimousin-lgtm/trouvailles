<?php

declare(strict_types=1);

/**
 * TRV-004 — tests de OpportunityDetector. Touche la base locale, dans une
 * transaction annulée en fin de fichier (comme tests/run_listing_persister.php).
 * Usage : php tests/run_opportunity_detector.php
 */

const ROOT = __DIR__ . '/..';

require __DIR__ . '/assertions.php';
require __DIR__ . '/TestRunner.php';
require ROOT . '/app/Core/autoload.php';

use Trouvailles\Core\Database;
use Trouvailles\Pricing\OpportunityDetector;

function makeOppListing(PDO $pdo, int $sourceId, string $externalId, ?float $askingPrice, ?string $currency, string $status = 'active'): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO listings (source_id, external_id, url, title, asking_price, asking_currency, status)
         VALUES (:sid, :ext, :url, 'Titre test', :price, :currency, :status)"
    );
    $stmt->execute([
        'sid' => $sourceId, 'ext' => $externalId, 'url' => "https://example.test/{$externalId}",
        'price' => $askingPrice, 'currency' => $currency, 'status' => $status,
    ]);
    return (int) $pdo->lastInsertId();
}

function makeOppProduct(PDO $pdo, string $name): int
{
    $stmt = $pdo->prepare('INSERT INTO products (canonical_name) VALUES (:name)');
    $stmt->execute(['name' => $name]);
    return (int) $pdo->lastInsertId();
}

function linkOppListingProduct(PDO $pdo, int $listingId, int $productId): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO listing_products (listing_id, product_id, match_method, match_confidence, is_variant_exact)
         VALUES (:lid, :pid, 'fuzzy_match', 0.9, 0)"
    );
    $stmt->execute(['lid' => $listingId, 'pid' => $productId]);
}

function makeOppValuation(PDO $pdo, int $productId, float $central, string $currency, string $status, ?float $confidence = 0.8): int
{
    // PDO::ATTR_EMULATE_PREPARES=false (voir config/database.php) : un
    // paramètre nommé ne peut pas être répété dans une même requête.
    $stmt = $pdo->prepare(
        "INSERT INTO market_valuations (product_id, method_version, value_low, value_central, value_high, currency, confidence_score, valuation_status)
         VALUES (:pid, 'test-v1', :low, :central, :high, :currency, :confidence, :status)"
    );
    $stmt->execute(['pid' => $productId, 'low' => $central, 'central' => $central, 'high' => $central, 'currency' => $currency, 'confidence' => $confidence, 'status' => $status]);
    return (int) $pdo->lastInsertId();
}

function fetchOpportunities(PDO $pdo, int $listingId): array
{
    $stmt = $pdo->prepare('SELECT * FROM opportunities WHERE listing_id = :lid');
    $stmt->execute(['lid' => $listingId]);
    return $stmt->fetchAll();
}

$runner = new TestRunner();
$pdo = Database::connection();
$pdo->beginTransaction();

try {
    $sourceId = (int) $pdo->query("SELECT id FROM sources WHERE code = 'ebay'")->fetch()['id'];

    $runner->run('Décote >= seuil + valorisation valid -> opportunité créée avec les bons champs', function () use ($pdo, $sourceId) {
        $productId = makeOppProduct($pdo, 'Produit decote suffisante');
        $listingId = makeOppListing($pdo, $sourceId, 'EXT-OPPD-1', 80.0, 'USD');
        linkOppListingProduct($pdo, $listingId, $productId);
        $valuationId = makeOppValuation($pdo, $productId, 100.0, 'USD', 'valid', 0.85);

        $detector = new OpportunityDetector($pdo);
        $counts = $detector->detect(15.0); // décote réelle = 20%

        assertEquals(1, $counts['created'], 'une opportunité doit être créée');
        $rows = fetchOpportunities($pdo, $listingId);
        assertEquals(1, count($rows), 'une seule opportunité attendue');
        assertEquals('80.00', $rows[0]['asking_price'], 'asking_price doit venir du listing');
        assertEquals('100.00', $rows[0]['market_value'], 'market_value doit venir de value_central');
        assertEquals('20.00', $rows[0]['discount_percentage'], 'discount_percentage doit être calculé correctement');
        assertEquals('detected', $rows[0]['status'], 'status doit être detected');
        assertEquals('15.00', $rows[0]['min_discount'], 'min_discount doit stocker exactement la valeur fournie, jamais une constante');
        assertEquals($valuationId, (int) $rows[0]['valuation_id'], 'valuation_id doit correspondre à la valorisation utilisée');
    });

    $runner->run('Décote < seuil -> rien créé', function () use ($pdo, $sourceId) {
        $productId = makeOppProduct($pdo, 'Produit decote insuffisante');
        $listingId = makeOppListing($pdo, $sourceId, 'EXT-OPPD-2', 95.0, 'USD');
        linkOppListingProduct($pdo, $listingId, $productId);
        makeOppValuation($pdo, $productId, 100.0, 'USD', 'valid');

        $detector = new OpportunityDetector($pdo);
        $detector->detect(20.0); // décote réelle = 5%, seuil 20%

        assertEquals([], fetchOpportunities($pdo, $listingId), 'aucune opportunité ne doit être créée sous le seuil');
    });

    $runner->run('valuation_status thin_evidence/insufficient_evidence -> jamais d\'opportunité, même décote énorme', function () use ($pdo, $sourceId) {
        foreach (['thin_evidence', 'insufficient_evidence'] as $status) {
            $productId = makeOppProduct($pdo, "Produit {$status}");
            $listingId = makeOppListing($pdo, $sourceId, "EXT-OPPD-STATUS-{$status}", 10.0, 'USD');
            linkOppListingProduct($pdo, $listingId, $productId);
            makeOppValuation($pdo, $productId, 100.0, 'USD', $status);

            $detector = new OpportunityDetector($pdo);
            $detector->detect(0.0); // seuil quasi nul, décote réelle = 90%

            assertEquals([], fetchOpportunities($pdo, $listingId), "aucune opportunité ne doit être créée pour valuation_status={$status}, quel que soit le rabais apparent");
        }
    });

    $runner->run('Devise différente listing/valorisation -> ignoré, jamais fabriqué', function () use ($pdo, $sourceId) {
        $productId = makeOppProduct($pdo, 'Produit devise differente');
        $listingId = makeOppListing($pdo, $sourceId, 'EXT-OPPD-CUR', 50.0, 'EUR');
        linkOppListingProduct($pdo, $listingId, $productId);
        makeOppValuation($pdo, $productId, 100.0, 'USD', 'valid');

        $detector = new OpportunityDetector($pdo);
        $counts = $detector->detect(0.0);

        assertEquals([], fetchOpportunities($pdo, $listingId), 'devise différente -> jamais de conversion inventée, ignoré');
        assertTrue($counts['skipped'] >= 1, 'ce listing doit compter dans skipped');
    });

    $runner->run('Deux exécutions successives (même listing, même valorisation) -> pas de doublon', function () use ($pdo, $sourceId) {
        $productId = makeOppProduct($pdo, 'Produit dedup');
        $listingId = makeOppListing($pdo, $sourceId, 'EXT-OPPD-DEDUP', 80.0, 'USD');
        linkOppListingProduct($pdo, $listingId, $productId);
        makeOppValuation($pdo, $productId, 100.0, 'USD', 'valid');

        $detector = new OpportunityDetector($pdo);
        $detector->detect(10.0);
        $detector->detect(10.0); // deuxième exécution, même valorisation

        assertEquals(1, count(fetchOpportunities($pdo, $listingId)), 'une seule opportunité doit exister pour ce couple (listing, valuation)');
    });

    $runner->run('Nouvelle market_valuations (append-only) -> nouvelle opportunité possible pour le même listing', function () use ($pdo, $sourceId) {
        $productId = makeOppProduct($pdo, 'Produit append only');
        $listingId = makeOppListing($pdo, $sourceId, 'EXT-OPPD-APPEND', 80.0, 'USD');
        linkOppListingProduct($pdo, $listingId, $productId);
        makeOppValuation($pdo, $productId, 100.0, 'USD', 'valid');

        $detector = new OpportunityDetector($pdo);
        $detector->detect(10.0);
        assertEquals(1, count(fetchOpportunities($pdo, $listingId)), 'une opportunité doit exister après la première détection');

        // Le moteur de valorisation tourne à nouveau : nouvelle ligne append-only, même produit.
        makeOppValuation($pdo, $productId, 100.0, 'USD', 'valid');
        $detector->detect(10.0);

        assertEquals(2, count(fetchOpportunities($pdo, $listingId)), 'une nouvelle valorisation doit pouvoir régénérer une opportunité pour le même listing (append-only respecté)');
    });

    $runner->run('Listing sans prix -> ignoré silencieusement', function () use ($pdo, $sourceId) {
        $productId = makeOppProduct($pdo, 'Produit sans prix');
        $listingId = makeOppListing($pdo, $sourceId, 'EXT-OPPD-NOPRICE', null, null);
        linkOppListingProduct($pdo, $listingId, $productId);
        makeOppValuation($pdo, $productId, 100.0, 'USD', 'valid');

        $detector = new OpportunityDetector($pdo);
        $detector->detect(0.0);

        assertEquals([], fetchOpportunities($pdo, $listingId), 'un listing sans prix ne doit jamais produire d\'opportunité');
    });

    $runner->run('Listing sans produit matché -> ignoré silencieusement (jamais dans le scan)', function () use ($pdo, $sourceId) {
        $listingId = makeOppListing($pdo, $sourceId, 'EXT-OPPD-NOPRODUCT', 10.0, 'USD');
        // Pas de listing_products : ce listing ne doit même pas apparaître dans le scan.

        $detector = new OpportunityDetector($pdo);
        $detector->detect(0.0);

        assertEquals([], fetchOpportunities($pdo, $listingId), 'un listing sans produit matché ne doit jamais produire d\'opportunité');
    });
} finally {
    $pdo->rollBack();
}

exit($runner->report());
