<?php

declare(strict_types=1);

/**
 * TRV-004 — tests de ProductMatcher. Touche la base locale, dans une
 * transaction annulée en fin de fichier (comme tests/run_listing_persister.php).
 * Usage : php tests/run_product_matcher.php
 */

const ROOT = __DIR__ . '/..';

require __DIR__ . '/assertions.php';
require __DIR__ . '/TestRunner.php';
require ROOT . '/app/Core/autoload.php';

use Trouvailles\Core\Database;
use Trouvailles\Persistence\ListingPersister;
use Trouvailles\Pricing\ProductMatcher;
use Trouvailles\Sources\NormalizedListing;

$runner = new TestRunner();

function makeMatcherListing(array $overrides = []): NormalizedListing
{
    $defaults = [
        'source' => 'ebay',
        'externalId' => 'EXT-MATCH-1',
        'url' => 'https://example.test/1',
        'title' => 'Canon EOS 90D DSLR Camera Body',
        'description' => null,
        'brand' => null,
        'category' => null,
        'condition' => null,
        'askingPrice' => 500.0,
        'askingCurrency' => 'USD',
        'shippingPrice' => null,
        'location' => null,
        'sellerType' => null,
        'publishedAt' => null,
        'priceMechanism' => NormalizedListing::PRICE_MECHANISM_FIXED,
    ];
    $args = array_merge($defaults, $overrides);
    return new NormalizedListing(...$args);
}

/** @return array{listing_id:int, po_id:int} */
function persistAndFetchObservation(PDO $pdo, NormalizedListing $listing): array
{
    $persister = new ListingPersister($pdo);
    $result = $persister->persist($listing);
    assertNotNull($result['price_observation_id'], 'un prix a été fourni, une observation doit exister');

    return ['listing_id' => $result['listing_id'], 'po_id' => $result['price_observation_id']];
}

function fetchProductId(PDO $pdo, int $poId): ?int
{
    $stmt = $pdo->prepare('SELECT product_id FROM price_observations WHERE id = :id');
    $stmt->execute(['id' => $poId]);
    $value = $stmt->fetch()['product_id'];

    return $value === null ? null : (int) $value;
}

$pdo = Database::connection();
$pdo->beginTransaction();

try {
    $runner->run('Observation sans produit existant -> nouveau produit créé, match_confidence=1.0', function () use ($pdo) {
        $matcher = new ProductMatcher($pdo);
        $obs = persistAndFetchObservation($pdo, makeMatcherListing(['externalId' => 'EXT-MATCHER-NEW-1', 'title' => 'Sony A7III Mirrorless Camera Body']));

        $counts = $matcher->matchPendingObservations();
        assertEquals(1, $counts['created_new'], 'aucun produit existant ne peut correspondre -> création');

        $productId = fetchProductId($pdo, $obs['po_id']);
        assertNotNull($productId, 'product_id doit être renseigné après matching');

        $stmt = $pdo->prepare('SELECT match_method, match_confidence FROM listing_products WHERE listing_id = :id');
        $stmt->execute(['id' => $obs['listing_id']]);
        $row = $stmt->fetch();
        assertEquals('fuzzy_match', $row['match_method'], 'match_method doit être fuzzy_match');
        assertEquals('1.000000', $row['match_confidence'], 'création -> match_confidence=1.0 (identité définitionnelle, pas un score inventé)');
    });

    $runner->run('Annonce très similaire à un produit existant -> rattachée, match_confidence réel < 1.0', function () use ($pdo) {
        $matcher = new ProductMatcher($pdo);
        $obs1 = persistAndFetchObservation($pdo, makeMatcherListing(['externalId' => 'EXT-MATCHER-SIM-1', 'title' => 'Canon EOS 90D DSLR Camera Body']));
        $matcher->matchPendingObservations();
        $product1 = fetchProductId($pdo, $obs1['po_id']);

        $obs2 = persistAndFetchObservation($pdo, makeMatcherListing(['externalId' => 'EXT-MATCHER-SIM-2', 'title' => 'Canon EOS 90D Body Only, DSLR Camera']));
        $counts = $matcher->matchPendingObservations();

        assertEquals(1, $counts['matched_existing'], 'la seconde annonce doit être rattachée au produit existant, pas créer un nouveau');
        $product2 = fetchProductId($pdo, $obs2['po_id']);
        assertEquals($product1, $product2, 'les deux annonces doivent pointer vers le même produit');

        $stmt = $pdo->prepare('SELECT match_confidence FROM listing_products WHERE listing_id = :id');
        $stmt->execute(['id' => $obs2['listing_id']]);
        $confidence = (float) $stmt->fetch()['match_confidence'];
        assertTrue($confidence >= ProductMatcher::MIN_MATCH_CONFIDENCE && $confidence < 1.0, "match_confidence attendu dans [0.45, 1.0[, obtenu {$confidence}");
    });

    $runner->run('Annonce trop différente -> nouveau produit distinct, jamais forcé', function () use ($pdo) {
        $matcher = new ProductMatcher($pdo);
        $obs1 = persistAndFetchObservation($pdo, makeMatcherListing(['externalId' => 'EXT-MATCHER-DIFF-1', 'title' => 'Nikon D850 Camera Kit']));
        $matcher->matchPendingObservations();
        $product1 = fetchProductId($pdo, $obs1['po_id']);

        $obs2 = persistAndFetchObservation($pdo, makeMatcherListing(['externalId' => 'EXT-MATCHER-DIFF-2', 'title' => 'Vintage Rolex Submariner watch parts']));
        $matcher->matchPendingObservations();
        $product2 = fetchProductId($pdo, $obs2['po_id']);

        assertTrue($product1 !== $product2, 'deux annonces sans rapport ne doivent jamais partager le même produit');
    });

    $runner->run('Filtre dur catégorie : titres proches mais catégories différentes -> jamais matchés', function () use ($pdo) {
        // Titre volontairement très distinctif (aucun recouvrement de tokens
        // avec les autres tests de ce fichier, qui partagent la même
        // transaction/table `products`) pour isoler ce cas du filtre dur.
        $matcher = new ProductMatcher($pdo);
        $obs1 = persistAndFetchObservation($pdo, makeMatcherListing([
            'externalId' => 'EXT-MATCHER-CAT-1', 'title' => 'Bose QuietComfort Ultra Headphones', 'category' => 'Audio',
        ]));
        $matcher->matchPendingObservations();
        $product1 = fetchProductId($pdo, $obs1['po_id']);

        $obs2 = persistAndFetchObservation($pdo, makeMatcherListing([
            'externalId' => 'EXT-MATCHER-CAT-2', 'title' => 'Bose QuietComfort Ultra Headphones', 'category' => 'Audio Accessories',
        ]));
        $matcher->matchPendingObservations();
        $product2 = fetchProductId($pdo, $obs2['po_id']);

        assertTrue($product1 !== $product2, 'des catégories connues différentes ne doivent jamais être matchées, même titre identique');
    });

    $runner->run('Listing déjà matché (lien préexistant) -> product_id réutilisé sans recalcul', function () use ($pdo) {
        $matcher = new ProductMatcher($pdo);
        $obs = persistAndFetchObservation($pdo, makeMatcherListing(['externalId' => 'EXT-MATCHER-REUSE-1', 'title' => 'Panasonic Lumix GH5']));

        // Association manuelle volontairement incohérente (titre ne correspond à rien) :
        // si le matcher recalculait, il ne pourrait jamais aboutir à ce produit précis.
        $stmt = $pdo->prepare("INSERT INTO products (canonical_name) VALUES ('Produit factice sans rapport')");
        $stmt->execute();
        $manualProductId = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare(
            'INSERT INTO listing_products (listing_id, product_id, match_method, match_confidence, is_variant_exact)
             VALUES (:listing_id, :product_id, \'manual\', 1.0, 1)'
        );
        $stmt->execute(['listing_id' => $obs['listing_id'], 'product_id' => $manualProductId]);

        $counts = $matcher->matchPendingObservations();
        assertEquals(1, $counts['reused_listing_link'], 'un listing déjà lié doit réutiliser ce lien, jamais recalculer');

        $productId = fetchProductId($pdo, $obs['po_id']);
        assertEquals($manualProductId, $productId, 'le product_id manuel préexistant doit être respecté tel quel');
    });

    $runner->run('Titre absent -> product_id reste NULL, aucun produit créé, skipped_no_signal', function () use ($pdo) {
        $matcher = new ProductMatcher($pdo);
        $obs = persistAndFetchObservation($pdo, makeMatcherListing(['externalId' => 'EXT-MATCHER-NOTITLE', 'title' => null]));

        $countBefore = (int) $pdo->query('SELECT COUNT(*) AS n FROM products')->fetch()['n'];
        $counts = $matcher->matchPendingObservations();
        $countAfter = (int) $pdo->query('SELECT COUNT(*) AS n FROM products')->fetch()['n'];

        assertEquals(1, $counts['skipped_no_signal'], 'titre absent -> aucun signal, jamais de produit fabriqué');
        assertEquals($countBefore, $countAfter, 'aucun produit ne doit être créé sans titre');
        assertNull(fetchProductId($pdo, $obs['po_id']), 'product_id doit rester NULL, jamais fabriqué');
    });

    $runner->run('matchPendingObservations($limit) respecte la limite', function () use ($pdo) {
        $matcher = new ProductMatcher($pdo);
        persistAndFetchObservation($pdo, makeMatcherListing(['externalId' => 'EXT-MATCHER-LIMIT-1', 'title' => 'Fujifilm X-T4 Body']));
        persistAndFetchObservation($pdo, makeMatcherListing(['externalId' => 'EXT-MATCHER-LIMIT-2', 'title' => 'Olympus OM-D E-M1 Mark III']));

        $counts = $matcher->matchPendingObservations(1);
        assertEquals(1, $counts['processed'], 'la limite de 1 doit être respectée');

        // Le reste doit toujours être en attente.
        $remaining = (int) $pdo->query('SELECT COUNT(*) AS n FROM price_observations WHERE product_id IS NULL')->fetch()['n'];
        assertTrue($remaining >= 1, 'au moins une observation doit rester non appariée après une limite de 1');
    });
} finally {
    $pdo->rollBack();
}

exit($runner->report());
