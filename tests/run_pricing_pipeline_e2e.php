<?php

declare(strict_types=1);

/**
 * TRV-004 — vérification de bout en bout du pipeline de pricing :
 * recherche eBay (fixtures existantes) -> persistance -> matching ->
 * valorisation -> détection d'opportunités -> visible via
 * OpportunityRepository::findRecent() SANS modification de ce fichier.
 *
 * Les trois classes de app/Pricing/ sont appelées DIRECTEMENT EN PROCESS
 * (jamais via exec() de tools/pricing_engine.php) : exec() ouvrirait une
 * connexion PDO dans un processus séparé, non couverte par le ROLLBACK de
 * ce fichier, ce qui laisserait des données résiduelles en base. Le CLI
 * n'étant qu'un fin enrobage délégant 100% de sa logique à ces classes,
 * l'appel direct offre une couverture équivalente sans effet de bord.
 *
 * Touche la base locale, dans une transaction annulée en fin de fichier
 * (comme tests/run_listing_persister.php).
 * Usage : php tests/run_pricing_pipeline_e2e.php
 */

const ROOT = __DIR__ . '/..';

require __DIR__ . '/assertions.php';
require __DIR__ . '/TestRunner.php';
require ROOT . '/app/Core/autoload.php';
require __DIR__ . '/Support/FixtureHttpClient.php';

use Trouvailles\Core\Database;
use Trouvailles\Persistence\ListingPersister;
use Trouvailles\Persistence\OpportunityRepository;
use Trouvailles\Pricing\OpportunityDetector;
use Trouvailles\Pricing\ProductMatcher;
use Trouvailles\Pricing\ValuationEngine;
use Trouvailles\Sources\Ebay\EbayAdapter;

$runner = new TestRunner();

$pdo = Database::connection();
$pdo->beginTransaction();

try {
    $tokenUrl = 'https://api.ebay.com/identity/v1/oauth2/token';
    $tokenResponse = json_encode(['access_token' => 'TESTTOKEN', 'expires_in' => 7200, 'token_type' => 'Application Access Token']);
    $page1Fixture = file_get_contents(__DIR__ . '/fixtures/ebay_search_page1.json');
    $config = ['client_id' => 'test-client', 'client_secret' => 'test-secret', 'marketplace_id' => 'EBAY_US'];

    $http = new FixtureHttpClient();
    $http->respondTo($tokenUrl, 200, $tokenResponse);
    $searchUrl = 'https://api.ebay.com/buy/browse/v1/item_summary/search?' . http_build_query(['q' => 'canon eos', 'limit' => 50, 'offset' => 0]);
    $http->respondTo($searchUrl, 200, $page1Fixture);

    $adapter = new EbayAdapter($config, $http);
    $listings = $adapter->search(['q' => 'canon eos']);
    assertEquals(2, count($listings), 'la fixture doit produire deux annonces (Canon fixe, Rolex enchère)');

    $persister = new ListingPersister($pdo);
    $canonListingId = null;
    foreach ($listings as $listing) {
        $result = $persister->persist($listing);
        if ($listing->title !== null && str_contains($listing->title, 'Canon')) {
            $canonListingId = $result['listing_id'];
        }
    }
    assertNotNull($canonListingId, 'le listing Canon doit avoir été persisté');

    $matcher = new ProductMatcher($pdo);
    $valuationEngine = new ValuationEngine($pdo);
    $detector = new OpportunityDetector($pdo);
    $repository = new OpportunityRepository($pdo);

    $runner->run('Sous-cas A — fixtures seules : 1 comparable par produit -> insufficient_evidence, aucune opportunité fabriquée', function () use ($pdo, $matcher, $valuationEngine, $detector, $repository) {
        $matcher->matchPendingObservations();
        $valuationResults = $valuationEngine->valuateAllProducts();

        foreach ($valuationResults as $r) {
            assertEquals('insufficient_evidence', $r['status'], 'un seul comparable par produit à ce stade -> insufficient_evidence pour chacun');
        }

        // Seuil quasi nul : si le système fabriquait la moindre opportunité
        // sur preuve insuffisante, ce test la révélerait.
        $counts = $detector->detect(0.0);
        assertEquals(0, $counts['created'], 'aucune opportunité ne doit être créée sur insufficient_evidence, quel que soit le seuil');
        assertEquals([], $repository->findRecent(), 'l\'écran d\'accueil doit rester honnêtement vide, aucune donnée fabriquée');
    });

    $runner->run('Sous-cas B — comparables supplémentaires -> valorisation valid -> opportunité visible sans modifier OpportunityRepository', function () use ($pdo, $canonListingId, $valuationEngine, $detector, $repository) {
        $stmt = $pdo->prepare('SELECT product_id FROM listing_products WHERE listing_id = :id');
        $stmt->execute(['id' => $canonListingId]);
        $canonProductId = (int) $stmt->fetch()['product_id'];

        $sourceId = (int) $pdo->query("SELECT id FROM sources WHERE code = 'ebay'")->fetch()['id'];
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        // Scaffolding de test explicite (comme tests/run_opportunity_repository.php) :
        // 4 comparables supplémentaires rattachés au MÊME produit que le listing
        // Canon (prix demandé réel : 650 USD), avec des prix plus élevés, pour
        // que la médiane dépasse le prix demandé d'une marge suffisante.
        foreach ([900.0, 950.0, 1000.0, 1050.0] as $i => $amount) {
            $stmt = $pdo->prepare(
                "INSERT INTO listings (source_id, external_id, url, title, status)
                 VALUES (:sid, :ext, :url, 'Canon EOS 90D DSLR Camera Body (comparable test)', 'active')"
            );
            $stmt->execute(['sid' => $sourceId, 'ext' => "EXT-E2E-COMP-{$i}", 'url' => "https://example.test/e2e-comp-{$i}"]);
            $compListingId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                "INSERT INTO listing_products (listing_id, product_id, match_method, match_confidence, is_variant_exact)
                 VALUES (:lid, :pid, 'fuzzy_match', 0.95, 0)"
            );
            $stmt->execute(['lid' => $compListingId, 'pid' => $canonProductId]);

            $stmt = $pdo->prepare(
                "INSERT INTO price_observations (listing_id, source_id, product_id, amount, currency, price_type, observed_at, evidence_type)
                 VALUES (:lid, :sid, :pid, :amount, 'USD', 'asking', :observed_at, 'active_fixed_price')"
            );
            $stmt->execute(['lid' => $compListingId, 'sid' => $sourceId, 'pid' => $canonProductId, 'amount' => $amount, 'observed_at' => $now]);
        }

        $result = $valuationEngine->valuateProduct($canonProductId);
        assertEquals('valid', $result['status'], '5 comparables au total (1 réel + 4 test) -> valid');
        assertEquals(5, $result['comparable_count'], '1 comparable réel + 4 de test = 5');

        $counts = $detector->detect(20.0);
        assertTrue($counts['created'] >= 1, 'au moins une opportunité doit être créée (Canon, 650 vs médiane ~950)');

        $rows = $repository->findRecent();
        $canon = null;
        foreach ($rows as $row) {
            if (str_contains((string) $row['title'], 'Canon') && $row['source_code'] === 'ebay') {
                $canon = $row;
                break;
            }
        }
        assertNotNull($canon, 'l\'opportunité Canon doit être visible via OpportunityRepository::findRecent(), sans aucune modification de ce fichier');
        assertEquals('valid', $canon['valuation_status'], 'valuation_status doit être valid');
    });
} finally {
    $pdo->rollBack();
}

exit($runner->report());
