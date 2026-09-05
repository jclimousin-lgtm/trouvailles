<?php

declare(strict_types=1);

/**
 * TRV-009 — tests EtsyAdapter/EtsyClient (Etsy Open API v3, officielle).
 * Entièrement hors réseau (FixtureHttpClient) sauf le bloc "persistance",
 * qui touche la base locale dans une transaction annulée en fin de fichier.
 * Usage : php tests/run_etsy_adapter.php
 */

const ROOT = __DIR__ . '/..';

require __DIR__ . '/assertions.php';
require __DIR__ . '/TestRunner.php';
require ROOT . '/app/Core/autoload.php';
require __DIR__ . '/Support/FixtureHttpClient.php';

use Trouvailles\Core\Database;
use Trouvailles\Persistence\ListingPersister;
use Trouvailles\Sources\Etsy\EtsyAdapter;
use Trouvailles\Sources\NormalizedListing;

$runner = new TestRunner();

$page1Fixture = file_get_contents(__DIR__ . '/fixtures/etsy_search_page1.json');
$config = ['keystring' => 'test-keystring', 'shared_secret' => 'test-secret'];

function etsySearchUrl(array $query): string
{
    return 'https://openapi.etsy.com/v3/application/listings/active?' . http_build_query($query);
}

$runner->run('Etsy : récupération + parsing + normalisation (pas d\'OAuth, un seul appel)', function () use ($config, $page1Fixture) {
    $http = new FixtureHttpClient();
    $searchUrl = etsySearchUrl(['keywords' => 'vintage compass', 'limit' => 25, 'offset' => 0]);
    $http->respondTo($searchUrl, 200, $page1Fixture);

    $adapter = new EtsyAdapter($config, $http);
    $listings = $adapter->search(['keywords' => 'vintage compass']);

    assertEquals(2, count($listings), 'Deux items attendus');
    $first = $listings[0];
    assertTrue($first instanceof NormalizedListing, 'Le résultat doit être un NormalizedListing');
    assertEquals('etsy', $first->source, 'source doit valoir etsy');
    assertEquals('123456789', $first->externalId, 'externalId doit venir de listing_id');
    assertEquals('Vintage Brass Compass — Nautical Decor', $first->title, 'title doit venir de title');
    assertEquals(45.0, $first->askingPrice, 'askingPrice doit venir de price.amount/price.divisor (4500/100)');
    assertEquals('USD', $first->askingCurrency, 'askingCurrency doit venir de price.currency_code');
    assertEquals(NormalizedListing::PRICE_MECHANISM_FIXED, $first->priceMechanism, 'Etsy ne propose pas d\'enchères -> toujours fixed');
    assertNull($first->brand, 'brand : aucun champ équivalent, jamais inventé');
    assertNull($first->category, 'category : seul taxonomy_id numérique disponible, jamais inventé');
    assertNull($first->condition, 'condition : pas de notion neuf/occasion chez Etsy');
    assertNull($first->shippingPrice, 'shippingPrice : absent de cette réponse');

    // Un seul appel HTTP (pas d'échange OAuth préalable, contrairement à eBay).
    assertEquals(1, count($http->requestedUrls), 'Etsy ne doit nécessiter qu\'un seul appel HTTP (pas de jeton OAuth)');
    $request = $http->requestedDetails[0];
    assertEquals('test-keystring:test-secret', $request['headers']['x-api-key'], 'x-api-key doit être keystring:shared_secret');
});

$runner->run('Etsy : devise différente par annonce (chaque boutique fixe la sienne)', function () use ($config, $page1Fixture) {
    $http = new FixtureHttpClient();
    $searchUrl = etsySearchUrl(['keywords' => 'x', 'limit' => 25, 'offset' => 0]);
    $http->respondTo($searchUrl, 200, $page1Fixture);

    $adapter = new EtsyAdapter($config, $http);
    $listings = $adapter->search(['keywords' => 'x']);

    assertEquals('USD', $listings[0]->askingCurrency, 'première annonce en USD');
    assertEquals('EUR', $listings[1]->askingCurrency, 'deuxième annonce en EUR — devises mélangées, jamais uniformisées');
    assertEquals(32.0, $listings[1]->askingPrice, 'askingPrice doit venir de price.amount/price.divisor (3200/100)');
});

$runner->run('Etsy : sans identifiants configurés -> échec explicite, jamais silencieux', function () {
    $http = new FixtureHttpClient();
    $adapter = new EtsyAdapter(['keystring' => '', 'shared_secret' => ''], $http);

    assertThrows(function () use ($adapter) {
        $adapter->search(['keywords' => 'x']);
    }, 'Sans ETSY_KEYSTRING/ETSY_SHARED_SECRET, la recherche doit échouer explicitement');
});

$runner->run('Etsy : item sans listing_id/url ignoré sans faire échouer les autres', function () use ($config) {
    $http = new FixtureHttpClient();
    $searchUrl = etsySearchUrl(['keywords' => 'x', 'limit' => 25, 'offset' => 0]);
    $http->respondTo($searchUrl, 200, json_encode([
        'results' => [
            ['title' => 'Sans identifiant'],
            ['listing_id' => 1, 'url' => 'https://www.etsy.com/listing/1', 'title' => 'Valide'],
        ],
    ]));

    $adapter = new EtsyAdapter($config, $http);
    $listings = $adapter->search(['keywords' => 'x']);

    assertEquals(1, count($listings), 'Seul l\'item avec listing_id+url doit être conservé');
    assertEquals('Valide', $listings[0]->title, 'Le titre de l\'item valide doit être conservé');
});

$runner->run('Etsy : created_timestamp (epoch) converti au format DATETIME MySQL', function () use ($config) {
    $http = new FixtureHttpClient();
    $searchUrl = etsySearchUrl(['keywords' => 'x', 'limit' => 25, 'offset' => 0]);
    $http->respondTo($searchUrl, 200, json_encode([
        'results' => [
            ['listing_id' => 1, 'url' => 'https://www.etsy.com/listing/1', 'title' => 'Avec date', 'created_timestamp' => 1768478400],
            ['listing_id' => 2, 'url' => 'https://www.etsy.com/listing/2', 'title' => 'Sans date'],
        ],
    ]));

    $adapter = new EtsyAdapter($config, $http);
    $listings = $adapter->search(['keywords' => 'x']);

    assertEquals('2026-01-15 12:00:00', $listings[0]->publishedAt, 'epoch 1768478400 doit devenir 2026-01-15 12:00:00 UTC, jamais stocké tel quel');
    assertEquals(null, $listings[1]->publishedAt, 'created_timestamp absent -> null, jamais une date inventée');
});

$runner->run('Etsy : divisor absent ou nul -> askingPrice null, jamais une division par zéro', function () use ($config) {
    $http = new FixtureHttpClient();
    $searchUrl = etsySearchUrl(['keywords' => 'x', 'limit' => 25, 'offset' => 0]);
    $http->respondTo($searchUrl, 200, json_encode([
        'results' => [
            ['listing_id' => 1, 'url' => 'https://www.etsy.com/listing/1', 'title' => 'Divisor nul', 'price' => ['amount' => 100, 'divisor' => 0, 'currency_code' => 'USD']],
            ['listing_id' => 2, 'url' => 'https://www.etsy.com/listing/2', 'title' => 'Prix absent'],
        ],
    ]));

    $adapter = new EtsyAdapter($config, $http);
    $listings = $adapter->search(['keywords' => 'x']);

    assertEquals(null, $listings[0]->askingPrice, 'divisor=0 ne doit jamais provoquer de division par zéro ni de valeur inventée');
    assertEquals(null, $listings[0]->askingCurrency, 'sans prix exploitable, aucune devise ne doit être inventée');
    assertEquals(null, $listings[1]->askingPrice, 'price absent -> askingPrice null');
});

$runner->run('Etsy : clé API refusée (401) remontée explicitement', function () use ($config) {
    $http = new FixtureHttpClient();
    $searchUrl = etsySearchUrl(['keywords' => 'x', 'limit' => 25, 'offset' => 0]);
    $http->respondTo($searchUrl, 401, '{"error":"invalid api key"}');
    $adapter = new EtsyAdapter($config, $http);

    assertThrows(function () use ($adapter) {
        $adapter->search(['keywords' => 'x']);
    }, 'Une clé API refusée doit lever une exception explicite');
});

$runner->run('Etsy : limitation de requêtes (429) remontée explicitement', function () use ($config) {
    $http = new FixtureHttpClient();
    $searchUrl = etsySearchUrl(['keywords' => 'x', 'limit' => 25, 'offset' => 0]);
    $http->respondTo($searchUrl, 429, '');
    $adapter = new EtsyAdapter($config, $http);

    assertThrows(function () use ($adapter) {
        $adapter->search(['keywords' => 'x']);
    }, 'Un 429 doit lever une exception explicite');
});

// ---------------------------------------------------------------------
// Persistance (touche la base locale, transaction annulée à la fin).
// ---------------------------------------------------------------------

$pdo = Database::connection();
$pdo->beginTransaction();

try {
    $runner->run('Etsy : persistance via ListingPersister — published_at bien formé', function () use ($pdo, $config, $page1Fixture) {
        $http = new FixtureHttpClient();
        $searchUrl = etsySearchUrl(['keywords' => 'x', 'limit' => 25, 'offset' => 0]);
        $http->respondTo($searchUrl, 200, $page1Fixture);

        $adapter = new EtsyAdapter($config, $http);
        $listings = $adapter->search(['keywords' => 'x']);

        $persister = new ListingPersister($pdo);
        $result = $persister->persist($listings[0]);

        $stmt = $pdo->prepare('SELECT published_at, asking_price, asking_currency FROM listings WHERE id = :id');
        $stmt->execute(['id' => $result['listing_id']]);
        $row = $stmt->fetch();
        assertEquals('2026-01-15 12:00:00', $row['published_at'], 'published_at doit se persister sans erreur de format (leçon TRV-008)');
        assertEquals('45.00', $row['asking_price'], 'asking_price doit valoir 45.00');
        assertEquals('USD', $row['asking_currency'], 'asking_currency doit valoir USD');
    });
} finally {
    $pdo->rollBack();
}

exit($runner->report());
