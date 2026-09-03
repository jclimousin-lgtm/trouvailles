<?php

declare(strict_types=1);

/**
 * TRV-002 — tests EbayAdapter/EbayClient (Browse API officielle).
 * Entièrement hors réseau (FixtureHttpClient) sauf le bloc "persistance",
 * qui touche la base locale dans une transaction annulée en fin de fichier.
 * Usage : php tests/run_ebay_adapter.php
 */

const ROOT = __DIR__ . '/..';

require __DIR__ . '/assertions.php';
require __DIR__ . '/TestRunner.php';
require ROOT . '/app/Core/autoload.php';
require __DIR__ . '/Support/FixtureHttpClient.php';

use Trouvailles\Core\Database;
use Trouvailles\Persistence\ListingPersister;
use Trouvailles\Sources\Ebay\EbayAdapter;
use Trouvailles\Sources\NormalizedListing;

$runner = new TestRunner();

$tokenUrl = 'https://api.ebay.com/identity/v1/oauth2/token';
$tokenResponse = json_encode(['access_token' => 'TESTTOKEN', 'expires_in' => 7200, 'token_type' => 'Application Access Token']);
$page1Fixture = file_get_contents(__DIR__ . '/fixtures/ebay_search_page1.json');
$config = ['client_id' => 'test-client', 'client_secret' => 'test-secret', 'marketplace_id' => 'EBAY_US'];

function ebaySearchUrl(array $query): string
{
    return 'https://api.ebay.com/buy/browse/v1/item_summary/search?' . http_build_query($query);
}

$runner->run('eBay : authentification OAuth + récupération + parsing + normalisation', function () use ($config, $tokenUrl, $tokenResponse, $page1Fixture) {
    $http = new FixtureHttpClient();
    $http->respondTo($tokenUrl, 200, $tokenResponse);
    $searchUrl = ebaySearchUrl(['q' => 'canon eos', 'limit' => 50, 'offset' => 0]);
    $http->respondTo($searchUrl, 200, $page1Fixture);

    $adapter = new EbayAdapter($config, $http);
    $listings = $adapter->search(['q' => 'canon eos']);

    assertEquals(2, count($listings), 'Deux items attendus');
    $first = $listings[0];
    assertTrue($first instanceof NormalizedListing, 'Le résultat doit être un NormalizedListing');
    assertEquals('ebay', $first->source, 'source doit valoir ebay');
    assertEquals('v1|123456789012|0', $first->externalId, 'externalId doit venir de itemId');
    assertEquals('Canon EOS 90D DSLR Camera Body', $first->title, 'title doit venir de title');
    assertEquals(650.0, $first->askingPrice, 'askingPrice doit venir de price.value');
    assertEquals('USD', $first->askingCurrency, 'askingCurrency doit venir de price.currency');
    assertEquals('Used', $first->condition, 'condition doit venir de condition');
    assertEquals('Digital Cameras', $first->category, 'category doit venir de categories[0].categoryName');
    assertEquals('Austin, US', $first->location, 'location doit combiner city et country');
    assertEquals(12.5, $first->shippingPrice, 'shippingPrice doit venir de shippingOptions[0].shippingCost.value');
    assertEquals(NormalizedListing::PRICE_MECHANISM_FIXED, $first->priceMechanism, 'FIXED_PRICE -> fixed');

    // La requête de recherche doit porter le jeton Bearer et le marketplace.
    $searchRequest = $http->requestedDetails[1];
    assertEquals('Bearer TESTTOKEN', $searchRequest['headers']['Authorization'], 'le jeton OAuth doit être transmis en Bearer');
    assertEquals('EBAY_US', $searchRequest['headers']['X-EBAY-C-MARKETPLACE-ID'], 'le marketplace_id configuré doit être transmis');
});

$runner->run('eBay : buyingOptions AUCTION -> priceMechanism auction', function () use ($config, $tokenUrl, $tokenResponse, $page1Fixture) {
    $http = new FixtureHttpClient();
    $http->respondTo($tokenUrl, 200, $tokenResponse);
    $searchUrl = ebaySearchUrl(['q' => 'rolex', 'limit' => 50, 'offset' => 0]);
    $http->respondTo($searchUrl, 200, $page1Fixture);

    $adapter = new EbayAdapter($config, $http);
    $listings = $adapter->search(['q' => 'rolex']);

    $second = $listings[1];
    assertEquals(NormalizedListing::PRICE_MECHANISM_AUCTION, $second->priceMechanism, 'AUCTION -> auction');
    assertNull($second->category, 'categories absent -> null, jamais inventé');
    assertNull($second->shippingPrice, 'shippingOptions absent -> null');
});

$runner->run('eBay : sans identifiants configurés -> échec explicite, jamais silencieux', function () {
    $http = new FixtureHttpClient();
    $adapter = new EbayAdapter(['client_id' => '', 'client_secret' => ''], $http);

    assertThrows(function () use ($adapter) {
        $adapter->search(['q' => 'canon']);
    }, 'Sans EBAY_CLIENT_ID/SECRET, la recherche doit échouer explicitement (jamais de secret en dur, §14)');
});

$runner->run('eBay : item sans itemId/itemWebUrl ignoré sans faire échouer les autres', function () use ($config, $tokenUrl, $tokenResponse) {
    $http = new FixtureHttpClient();
    $http->respondTo($tokenUrl, 200, $tokenResponse);
    $searchUrl = ebaySearchUrl(['q' => 'x', 'limit' => 50, 'offset' => 0]);
    $http->respondTo($searchUrl, 200, json_encode([
        'itemSummaries' => [
            ['title' => 'Sans identifiant'],
            ['itemId' => 'v1|1|0', 'itemWebUrl' => 'https://www.ebay.com/itm/1', 'title' => 'Valide'],
        ],
    ]));

    $adapter = new EbayAdapter($config, $http);
    $listings = $adapter->search(['q' => 'x']);

    assertEquals(1, count($listings), 'Seul l\'item avec itemId+itemWebUrl doit être conservé');
    assertEquals('Valide', $listings[0]->title, 'Le titre de l\'item valide doit être conservé');
});

$runner->run('eBay : échec OAuth (401) remonté explicitement', function () use ($config, $tokenUrl) {
    $http = new FixtureHttpClient();
    $http->respondTo($tokenUrl, 401, '{"error":"invalid_client"}');
    $adapter = new EbayAdapter($config, $http);

    assertThrows(function () use ($adapter) {
        $adapter->search(['q' => 'canon']);
    }, 'Un échec OAuth doit lever une exception explicite');
});

$runner->run('eBay : limitation de requêtes (429) remontée explicitement', function () use ($config, $tokenUrl, $tokenResponse) {
    $http = new FixtureHttpClient();
    $http->respondTo($tokenUrl, 200, $tokenResponse);
    $searchUrl = ebaySearchUrl(['q' => 'canon', 'limit' => 50, 'offset' => 0]);
    $http->respondTo($searchUrl, 429, '');
    $adapter = new EbayAdapter($config, $http);

    assertThrows(function () use ($adapter) {
        $adapter->search(['q' => 'canon']);
    }, 'Un 429 doit lever une exception explicite');
});

// ---------------------------------------------------------------------
// Persistance (touche la base locale, transaction annulée à la fin).
// ---------------------------------------------------------------------

$pdo = Database::connection();
$pdo->beginTransaction();

try {
    $runner->run('eBay : persistance via ListingPersister — annonce à prix fixe', function () use ($pdo, $config, $tokenUrl, $tokenResponse, $page1Fixture) {
        $http = new FixtureHttpClient();
        $http->respondTo($tokenUrl, 200, $tokenResponse);
        $searchUrl = ebaySearchUrl(['q' => 'canon', 'limit' => 50, 'offset' => 0]);
        $http->respondTo($searchUrl, 200, $page1Fixture);

        $adapter = new EbayAdapter($config, $http);
        $listings = $adapter->search(['q' => 'canon']);

        $persister = new ListingPersister($pdo);
        $fixedResult = $persister->persist($listings[0]);
        $auctionResult = $persister->persist($listings[1]);

        $stmt = $pdo->prepare('SELECT price_type, evidence_type FROM price_observations WHERE id = :id');
        $stmt->execute(['id' => $fixedResult['price_observation_id']]);
        $fixedRow = $stmt->fetch();
        assertEquals('asking', $fixedRow['price_type'], 'annonce FIXED_PRICE -> price_type asking');
        assertEquals('active_fixed_price', $fixedRow['evidence_type'], 'evidence_type doit être active_fixed_price');

        $stmt->execute(['id' => $auctionResult['price_observation_id']]);
        $auctionRow = $stmt->fetch();
        assertEquals('auction', $auctionRow['price_type'], 'annonce AUCTION -> price_type auction');
        assertEquals('active_auction', $auctionRow['evidence_type'], 'evidence_type doit être active_auction');
    });
} finally {
    $pdo->rollBack();
}

exit($runner->report());
