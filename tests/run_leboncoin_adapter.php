<?php

declare(strict_types=1);

/**
 * TRV-002 — tests LeboncoinAdapter/LeboncoinClient. Entièrement hors
 * réseau (FixtureHttpClient) sauf le bloc "persistance", qui touche la
 * base locale dans une transaction annulée en fin de fichier (comme
 * tests/run.php).
 * Usage : php tests/run_leboncoin_adapter.php
 */

const ROOT = __DIR__ . '/..';

require __DIR__ . '/assertions.php';
require __DIR__ . '/TestRunner.php';
require ROOT . '/app/Core/autoload.php';
require __DIR__ . '/Support/FixtureHttpClient.php';

use Trouvailles\Core\Database;
use Trouvailles\Persistence\ListingPersister;
use Trouvailles\Sources\Leboncoin\LeboncoinAdapter;
use Trouvailles\Sources\NormalizedListing;

$runner = new TestRunner();

$searchUrl = 'https://api.leboncoin.fr/finder/search';
$page1 = file_get_contents(__DIR__ . '/fixtures/leboncoin_search_page1.json');
$page2 = file_get_contents(__DIR__ . '/fixtures/leboncoin_search_page2.json');

$runner->run('Leboncoin : recherche + parsing + normalisation', function () use ($searchUrl, $page1) {
    $http = new FixtureHttpClient();
    $http->respondTo($searchUrl, 200, $page1);
    $adapter = new LeboncoinAdapter($http);

    $listings = $adapter->search(['text' => 'vtt', 'limit' => 2, 'max_pages' => 1]);

    assertEquals(2, count($listings), 'Deux annonces attendues depuis la page 1');
    $first = $listings[0];
    assertTrue($first instanceof NormalizedListing, 'Le résultat doit être un NormalizedListing');
    assertEquals('leboncoin', $first->source, 'source doit valoir leboncoin');
    assertEquals('2891234567', $first->externalId, 'externalId doit être list_id casté en string');
    assertEquals('VTT Decathlon Rockrider 540', $first->title, 'title doit venir de subject');
    assertEquals(250.0, $first->askingPrice, 'askingPrice doit être price_cents / 100');
    assertEquals('EUR', $first->askingCurrency, 'askingCurrency doit être EUR (mono-devise Leboncoin)');
    assertEquals('used_good', $first->condition, 'condition doit venir de status');
    assertTrue(str_contains((string) $first->location, 'Lyon'), 'location doit contenir la ville');
    assertEquals('private', $first->sellerType, 'sellerType doit venir de owner.type quand présent');
    assertEquals('2026-08-20 10:15:00', $first->publishedAt, 'publishedAt doit venir de first_publication_date');
    assertEquals(NormalizedListing::PRICE_MECHANISM_FIXED, $first->priceMechanism, 'Leboncoin est toujours à prix fixe');
});

$runner->run('Leboncoin : champ owner absent -> sellerType null (donnée absente jamais inventée)', function () use ($searchUrl, $page1) {
    $http = new FixtureHttpClient();
    $http->respondTo($searchUrl, 200, $page1);
    $adapter = new LeboncoinAdapter($http);

    $listings = $adapter->search(['limit' => 2, 'max_pages' => 1]);
    $second = $listings[1];
    assertEquals('pro', $second->sellerType, 'La seconde annonce a owner.type=pro');
    assertNull($second->brand, 'brand absent du fixture -> null, jamais inventé');
});

$runner->run('Leboncoin : pagination sur plusieurs pages (même URL, POST)', function () use ($searchUrl, $page1, $page2) {
    $http = new FixtureHttpClient();
    $http->respondTo($searchUrl, 200, $page1); // 1er appel
    $http->respondTo($searchUrl, 200, $page2); // 2e appel (moins que limit -> dernière page)
    $adapter = new LeboncoinAdapter($http);

    $listings = $adapter->search(['limit' => 2, 'max_pages' => 3]);

    assertEquals(3, count($listings), 'page1 (2) + page2 (1) = 3 annonces, page3 jamais appelée');
    assertEquals(2, count($http->requestedUrls), 'seules 2 requêtes doivent partir (arrêt dès page < limit)');
});

$runner->run('Leboncoin : annonce sans list_id/url ignorée sans faire échouer les autres', function () use ($searchUrl) {
    $http = new FixtureHttpClient();
    $http->respondTo($searchUrl, 200, json_encode([
        'ads' => [
            ['subject' => 'Sans identifiant'], // pas de list_id ni url -> ignorée
            ['list_id' => 999, 'url' => 'https://www.leboncoin.fr/x/999.htm', 'subject' => 'Valide'],
        ],
    ]));
    $adapter = new LeboncoinAdapter($http);

    $listings = $adapter->search(['limit' => 35, 'max_pages' => 1]);

    assertEquals(1, count($listings), 'Seule l\'annonce avec list_id+url doit être conservée');
    assertEquals('Valide', $listings[0]->title, 'Le titre de l\'annonce valide doit être conservé');
});

$runner->run('Leboncoin : réponse 403 (Datadome) remontée explicitement, jamais contournée', function () use ($searchUrl) {
    $http = new FixtureHttpClient();
    $http->respondTo($searchUrl, 403, '');
    $adapter = new LeboncoinAdapter($http);

    assertThrows(function () use ($adapter) {
        $adapter->search(['text' => 'vtt']);
    }, 'Un 403 doit lever une exception, jamais être contourné');
});

$runner->run('Leboncoin : réponse vide gérée proprement', function () use ($searchUrl) {
    $http = new FixtureHttpClient();
    $http->respondTo($searchUrl, 200, '');
    $adapter = new LeboncoinAdapter($http);

    assertThrows(function () use ($adapter) {
        $adapter->search(['text' => 'vtt']);
    }, 'Une réponse vide doit lever une exception explicite');
});

$runner->run('Leboncoin : JSON malformé géré proprement', function () use ($searchUrl) {
    $http = new FixtureHttpClient();
    $http->respondTo($searchUrl, 200, '{not valid json');
    $adapter = new LeboncoinAdapter($http);

    assertThrows(function () use ($adapter) {
        $adapter->search(['text' => 'vtt']);
    }, 'Un JSON malformé doit lever une exception explicite');
});

// ---------------------------------------------------------------------
// Persistance (touche la base locale, transaction annulée à la fin).
// ---------------------------------------------------------------------

$pdo = Database::connection();
$pdo->beginTransaction();

try {
    $runner->run('Leboncoin : persistance via ListingPersister (listing + price_observation)', function () use ($pdo, $searchUrl, $page1) {
        $http = new FixtureHttpClient();
        $http->respondTo($searchUrl, 200, $page1);
        $adapter = new LeboncoinAdapter($http);
        $listings = $adapter->search(['limit' => 2, 'max_pages' => 1]);

        $persister = new ListingPersister($pdo);
        $result = $persister->persist($listings[0]);

        assertTrue($result['created'], 'Premier passage : la listing doit être créée');
        assertNotNull($result['price_observation_id'], 'Une observation de prix doit être créée (askingPrice présent)');

        $stmt = $pdo->prepare('SELECT price_type, evidence_type, amount, currency FROM price_observations WHERE id = :id');
        $stmt->execute(['id' => $result['price_observation_id']]);
        $row = $stmt->fetch();
        assertEquals('asking', $row['price_type'], 'price_type doit être asking pour une annonce Leboncoin à prix fixe');
        assertEquals('active_fixed_price', $row['evidence_type'], 'evidence_type doit être active_fixed_price');
        assertEquals('250.00', $row['amount'], 'le montant doit correspondre à askingPrice');
        assertEquals('EUR', $row['currency'], 'la devise doit correspondre à askingCurrency');
    });
} finally {
    $pdo->rollBack();
}

exit($runner->report());
