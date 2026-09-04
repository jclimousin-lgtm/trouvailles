<?php

declare(strict_types=1);

/**
 * TRV-002 — tests VintedAdapter/VintedClient. Entièrement hors réseau
 * (FixtureHttpClient) sauf le bloc "persistance", qui touche la base
 * locale dans une transaction annulée en fin de fichier.
 * Usage : php tests/run_vinted_adapter.php
 */

const ROOT = __DIR__ . '/..';

require __DIR__ . '/assertions.php';
require __DIR__ . '/TestRunner.php';
require ROOT . '/app/Core/autoload.php';
require __DIR__ . '/Support/FixtureHttpClient.php';

use Trouvailles\Core\Database;
use Trouvailles\Persistence\ListingPersister;
use Trouvailles\Sources\NormalizedListing;
use Trouvailles\Sources\Vinted\VintedAdapter;
use Trouvailles\Sources\Vinted\VintedClient;

$runner = new TestRunner();

$homeUrl = 'https://www.vinted.fr/';
$page1Fixture = file_get_contents(__DIR__ . '/fixtures/vinted_search_page1.json');

function vintedSearchUrl(array $query): string
{
    return 'https://www.vinted.fr/api/v2/catalog/items?' . http_build_query($query);
}

$runner->run('Vinted : établissement de session (cookie) puis recherche + parsing + normalisation', function () use ($homeUrl, $page1Fixture) {
    $http = new FixtureHttpClient();
    $http->respondTo($homeUrl, 200, '<html></html>', [
        'set-cookie' => ['access_token_web=abc123; Path=/; HttpOnly', 'v_sid=xyz; Path=/'],
    ]);
    $searchUrl = vintedSearchUrl(['search_text' => 'robe', 'order' => 'newest_first', 'page' => 1, 'per_page' => 20]);
    $http->respondTo($searchUrl, 200, $page1Fixture);

    $adapter = new VintedAdapter(new VintedClient($http));
    $listings = $adapter->search(['search_text' => 'robe']);

    assertEquals(2, count($listings), 'Deux articles attendus');
    $first = $listings[0];
    assertTrue($first instanceof NormalizedListing, 'Le résultat doit être un NormalizedListing');
    assertEquals('vinted', $first->source, 'source doit valoir vinted');
    assertEquals('4123456789', $first->externalId, 'externalId doit être id casté en string');
    assertEquals('Robe fleurie été', $first->title, 'title doit venir de title');
    assertEquals('https://www.vinted.fr/items/4123456789-robe-fleurie-ete', $first->url, 'url relative doit être préfixée du domaine');
    assertEquals('Zara', $first->brand, 'brand doit venir de brand_title');
    assertEquals(15.0, $first->askingPrice, 'askingPrice doit venir de price.amount');
    assertEquals('EUR', $first->askingCurrency, 'askingCurrency doit venir de price.currency_code');
    assertEquals('very_good', $first->condition, 'condition doit venir de status');
    assertEquals('Bordeaux', $first->location, 'location doit venir de user.city (best-effort)');
    assertEquals('private', $first->sellerType, 'user.business=false -> private');
    assertEquals(NormalizedListing::PRICE_MECHANISM_FIXED, $first->priceMechanism, 'Vinted est toujours à prix fixe');

    // La requête de recherche doit porter le cookie obtenu à l'étape précédente.
    $searchRequest = $http->requestedDetails[1];
    assertTrue(str_contains($searchRequest['headers']['Cookie'], 'access_token_web=abc123'), 'Le cookie de session doit être transmis à la recherche');
});

$runner->run('Vinted : champs non confirmés absents -> null, jamais inventés (business=true -> professional)', function () use ($homeUrl, $page1Fixture) {
    $http = new FixtureHttpClient();
    $http->respondTo($homeUrl, 200, '<html></html>', ['set-cookie' => ['access_token_web=abc123']]);
    $searchUrl = vintedSearchUrl(['search_text' => 'robe', 'order' => 'newest_first', 'page' => 1, 'per_page' => 20]);
    $http->respondTo($searchUrl, 200, $page1Fixture);

    $adapter = new VintedAdapter(new VintedClient($http));
    $listings = $adapter->search(['search_text' => 'robe']);

    $second = $listings[1];
    assertEquals('professional', $second->sellerType, 'user.business=true -> professional');
    assertNull($second->brand, 'brand_title absent -> null');
    assertNull($second->condition, 'status absent -> null');
    assertNull($second->location, 'user.city absent -> null');
});

$runner->run('Vinted : article sans id/url ignoré sans faire échouer les autres', function () use ($homeUrl) {
    $http = new FixtureHttpClient();
    $http->respondTo($homeUrl, 200, '<html></html>', ['set-cookie' => ['access_token_web=abc123']]);
    $searchUrl = vintedSearchUrl(['order' => 'newest_first', 'page' => 1, 'per_page' => 20]);
    $http->respondTo($searchUrl, 200, json_encode([
        'items' => [
            ['title' => 'Sans identifiant'],
            ['id' => 1, 'url' => '/items/1-x', 'title' => 'Valide'],
        ],
    ]));

    $adapter = new VintedAdapter(new VintedClient($http));
    $listings = $adapter->search([]);

    assertEquals(1, count($listings), 'Seul l\'article avec id+url doit être conservé');
    assertEquals('Valide', $listings[0]->title, 'Le titre de l\'article valide doit être conservé');
});

$runner->run('Vinted : absence de cookie de session levée explicitement', function () use ($homeUrl) {
    $http = new FixtureHttpClient();
    $http->respondTo($homeUrl, 200, '<html></html>'); // aucun set-cookie
    $adapter = new VintedAdapter(new VintedClient($http));

    assertThrows(function () use ($adapter) {
        $adapter->search(['search_text' => 'robe']);
    }, 'Aucun cookie reçu doit lever une exception explicite');
});

$runner->run('Vinted : réponse 403 (anti-bot) remontée explicitement, jamais contournée', function () use ($homeUrl) {
    $http = new FixtureHttpClient();
    $http->respondTo($homeUrl, 200, '<html></html>', ['set-cookie' => ['access_token_web=abc123']]);
    $searchUrl = vintedSearchUrl(['order' => 'newest_first', 'page' => 1, 'per_page' => 20]);
    $http->respondTo($searchUrl, 403, '');
    $adapter = new VintedAdapter(new VintedClient($http));

    assertThrows(function () use ($adapter) {
        $adapter->search([]);
    }, 'Un 403 doit lever une exception, jamais être contourné');
});

$runner->run('Vinted : URL relative utilise le domaine configuré, jamais vinted.fr en dur (correction TRV-002-B, Test G)', function () {
    $domain = 'vinted.de';
    $http = new FixtureHttpClient();
    $http->respondTo("https://www.{$domain}/", 200, '<html></html>', ['set-cookie' => ['access_token_web=abc123']]);
    $searchUrl = "https://www.{$domain}/api/v2/catalog/items?" . http_build_query(['order' => 'newest_first', 'page' => 1, 'per_page' => 20]);
    $http->respondTo($searchUrl, 200, json_encode([
        'items' => [
            ['id' => 999, 'url' => '/items/999-test', 'title' => 'Article domaine'],
        ],
    ]));

    $client = new VintedClient($http, $domain);
    $adapter = new VintedAdapter($client, $domain);
    $listings = $adapter->search([]);

    assertEquals(1, count($listings), 'Un article attendu');
    assertEquals(
        'https://www.vinted.de/items/999-test',
        $listings[0]->url,
        'Une URL relative doit être préfixée du domaine configuré (vinted.de), plus jamais vinted.fr en dur'
    );
});

// ---------------------------------------------------------------------
// Persistance (touche la base locale, transaction annulée à la fin).
// ---------------------------------------------------------------------

$pdo = Database::connection();
$pdo->beginTransaction();

try {
    $runner->run('Vinted : persistance via ListingPersister (listing + price_observation)', function () use ($pdo, $homeUrl, $page1Fixture) {
        $http = new FixtureHttpClient();
        $http->respondTo($homeUrl, 200, '<html></html>', ['set-cookie' => ['access_token_web=abc123']]);
        $searchUrl = vintedSearchUrl(['order' => 'newest_first', 'page' => 1, 'per_page' => 20]);
        $http->respondTo($searchUrl, 200, $page1Fixture);

        $adapter = new VintedAdapter(new VintedClient($http));
        $listings = $adapter->search([]);

        $persister = new ListingPersister($pdo);
        $result = $persister->persist($listings[0]);

        assertTrue($result['created'], 'Premier passage : la listing doit être créée');
        assertNotNull($result['price_observation_id'], 'Une observation de prix doit être créée');

        $stmt = $pdo->prepare('SELECT price_type, evidence_type, amount FROM price_observations WHERE id = :id');
        $stmt->execute(['id' => $result['price_observation_id']]);
        $row = $stmt->fetch();
        assertEquals('asking', $row['price_type'], 'price_type doit être asking');
        assertEquals('active_fixed_price', $row['evidence_type'], 'evidence_type doit être active_fixed_price');
        assertEquals('15.00', $row['amount'], 'le montant doit correspondre à askingPrice');
    });
} finally {
    $pdo->rollBack();
}

exit($runner->report());
