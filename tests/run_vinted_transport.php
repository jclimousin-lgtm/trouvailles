<?php

declare(strict_types=1);

/**
 * TRV-002-B — tests de l'abstraction VintedTransportInterface : découplage
 * de VintedAdapter vis-à-vis de VintedClient, et VintedBrowserSessionTransport
 * (consomme une session déjà fournie, ne fabrique jamais de session elle-même).
 * Entièrement hors réseau (FixtureHttpClient / faux transport).
 * Usage : php tests/run_vinted_transport.php
 */

const ROOT = __DIR__ . '/..';

require __DIR__ . '/assertions.php';
require __DIR__ . '/TestRunner.php';
require ROOT . '/app/Core/autoload.php';
require __DIR__ . '/Support/FixtureHttpClient.php';

use Trouvailles\Sources\NormalizedListing;
use Trouvailles\Sources\Vinted\VintedAdapter;
use Trouvailles\Sources\Vinted\VintedBrowserSessionTransport;
use Trouvailles\Sources\Vinted\VintedClient;
use Trouvailles\Sources\Vinted\VintedTransportInterface;

$runner = new TestRunner();

$page1Fixture = file_get_contents(__DIR__ . '/fixtures/vinted_search_page1.json');

/**
 * Faux transport minimal pour le Test A — ne dépend d'aucune fixture HTTP,
 * prouve que VintedAdapter fonctionne avec N'IMPORTE QUELLE implémentation
 * de VintedTransportInterface, pas seulement VintedClient.
 */
final class FakeVintedTransport implements VintedTransportInterface
{
    /** @param array<string,mixed> $fixedResponse */
    public function __construct(private readonly array $fixedResponse)
    {
    }

    public function searchPage(array $criteria, int $page, int $perPage): array
    {
        return $this->fixedResponse;
    }
}

// ---------------------------------------------------------------------
// Test A — transport abstrait : VintedAdapter fonctionne avec n'importe
// quelle implémentation de VintedTransportInterface.
// ---------------------------------------------------------------------
$runner->run('Test A — VintedAdapter fonctionne avec un transport arbitraire (pas seulement VintedClient)', function () {
    $fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/vinted_search_page1.json'), true, 512, JSON_THROW_ON_ERROR);
    $transport = new FakeVintedTransport($fixture);

    $adapter = new VintedAdapter($transport);
    $listings = $adapter->search([]);

    assertEquals(2, count($listings), 'Le faux transport doit alimenter VintedAdapter comme n\'importe quel VintedTransportInterface');
    assertTrue($listings[0] instanceof NormalizedListing, 'Le résultat doit rester un NormalizedListing, quel que soit le transport');
    assertEquals('Robe fleurie été', $listings[0]->title, 'Le mapping doit fonctionner identiquement quel que soit le transport');
});

// ---------------------------------------------------------------------
// Test B — VintedClient continue de fonctionner comme avant (implémente
// désormais VintedTransportInterface, comportement HTTP inchangé).
// ---------------------------------------------------------------------
$runner->run('Test B — VintedClient implémente VintedTransportInterface, comportement HTTP inchangé', function () use ($page1Fixture) {
    $http = new FixtureHttpClient();
    $http->respondTo('https://www.vinted.fr/', 200, '<html></html>', ['set-cookie' => ['access_token_web=abc123']]);
    $searchUrl = 'https://www.vinted.fr/api/v2/catalog/items?' . http_build_query(['order' => 'newest_first', 'page' => 1, 'per_page' => 20]);
    $http->respondTo($searchUrl, 200, $page1Fixture);

    $client = new VintedClient($http);
    assertTrue($client instanceof VintedTransportInterface, 'VintedClient doit implémenter VintedTransportInterface');

    $adapter = new VintedAdapter($client);
    $listings = $adapter->search([]);

    assertEquals(2, count($listings), 'Le comportement HTTP historique de VintedClient doit être inchangé');
});

// ---------------------------------------------------------------------
// Test C — VintedBrowserSessionTransport : session fournie -> VintedAdapter
// -> NormalizedListing. Une seule requête (jamais de GET / automatique).
// ---------------------------------------------------------------------
$runner->run('Test C — VintedBrowserSessionTransport (session fournie) alimente correctement VintedAdapter', function () use ($page1Fixture) {
    $http = new FixtureHttpClient();
    $searchUrl = 'https://www.vinted.fr/api/v2/catalog/items?' . http_build_query(['order' => 'newest_first', 'page' => 1, 'per_page' => 20]);
    $http->respondTo($searchUrl, 200, $page1Fixture);

    $transport = new VintedBrowserSessionTransport('access_token_web=deja-fourni-par-une-session-legitime', $http);
    $adapter = new VintedAdapter($transport);
    $listings = $adapter->search([]);

    assertEquals(2, count($listings), 'La fixture catalogue doit produire les mêmes résultats que VintedClient');
    assertEquals('Robe fleurie été', $listings[0]->title, 'title doit venir de la fixture catalogue');
    assertEquals(15.0, $listings[0]->askingPrice, 'askingPrice doit venir de la fixture catalogue');

    // Preuve que la session est CONSOMMÉE, jamais fabriquée : une seule
    // requête part (le catalogue), aucun GET anonyme sur la page d'accueil.
    assertEquals(1, count($http->requestedUrls), 'Une seule requête doit partir : jamais de GET / automatique pour fabriquer une session');
    assertEquals($searchUrl, $http->requestedUrls[0], 'La seule requête doit être celle du catalogue, pas la page d\'accueil');

    $sentCookie = $http->requestedDetails[0]['headers']['Cookie'] ?? null;
    assertEquals('access_token_web=deja-fourni-par-une-session-legitime', $sentCookie, 'Le cookie fourni en injection doit être transmis tel quel, jamais régénéré');
});

// ---------------------------------------------------------------------
// Test D — session absente : erreur explicite, aucune requête réseau.
// ---------------------------------------------------------------------
$runner->run('Test D — session absente : erreur explicite, aucune requête envoyée', function () {
    $http = new FixtureHttpClient();
    $transport = new VintedBrowserSessionTransport(null, $http);

    assertThrows(function () use ($transport) {
        $transport->searchPage([], 1, 20);
    }, 'Une session absente (null) doit lever une exception explicite');

    assertEquals(0, count($http->requestedUrls), 'Aucune requête ne doit partir sans session fournie');

    // Chaîne vide == absente également (pas de session exploitable).
    $transportVide = new VintedBrowserSessionTransport('', $http);
    assertThrows(function () use ($transportVide) {
        $transportVide->searchPage([], 1, 20);
    }, 'Une session vide doit être traitée comme absente');
});

// ---------------------------------------------------------------------
// Test E — session refusée (401/403) : erreur explicite, jamais de
// tentative alternative.
// ---------------------------------------------------------------------
$runner->run('Test E — session refusée (401/403) remontée explicitement, aucune tentative alternative', function () {
    $http = new FixtureHttpClient();
    $searchUrl = 'https://www.vinted.fr/api/v2/catalog/items?' . http_build_query(['order' => 'newest_first', 'page' => 1, 'per_page' => 20]);
    $http->respondTo($searchUrl, 403, '');

    $transport = new VintedBrowserSessionTransport('access_token_web=session-expiree', $http);

    assertThrows(function () use ($transport) {
        $transport->searchPage([], 1, 20);
    }, 'Un 403 doit lever une exception explicite indiquant que la session n\'est plus utilisable');

    assertEquals(1, count($http->requestedUrls), 'Une seule tentative doit être faite, jamais de méthode alternative après un refus');
});

// ---------------------------------------------------------------------
// Test F — données catalogue invalides : rejetées proprement.
// ---------------------------------------------------------------------
$runner->run('Test F — réponse JSON malformée rejetée proprement', function () {
    $http = new FixtureHttpClient();
    $searchUrl = 'https://www.vinted.fr/api/v2/catalog/items?' . http_build_query(['order' => 'newest_first', 'page' => 1, 'per_page' => 20]);
    $http->respondTo($searchUrl, 200, '{not valid json');

    $transport = new VintedBrowserSessionTransport('access_token_web=abc123', $http);

    assertThrows(function () use ($transport) {
        $transport->searchPage([], 1, 20);
    }, 'Un JSON malformé doit lever une exception explicite');
});

$runner->run('Test F — données catalogue structurellement invalides (JSON valide mais pas un objet/tableau) rejetées', function () {
    $http = new FixtureHttpClient();
    $searchUrl = 'https://www.vinted.fr/api/v2/catalog/items?' . http_build_query(['order' => 'newest_first', 'page' => 1, 'per_page' => 20]);
    $http->respondTo($searchUrl, 200, json_encode('juste une chaîne, pas un objet catalogue'));

    $transport = new VintedBrowserSessionTransport('access_token_web=abc123', $http);

    assertThrows(function () use ($transport) {
        $transport->searchPage([], 1, 20);
    }, 'Une réponse JSON valide mais structurellement invalide (pas un tableau) doit être rejetée');
});

$runner->run('Test F — réponse vide rejetée proprement', function () {
    $http = new FixtureHttpClient();
    $searchUrl = 'https://www.vinted.fr/api/v2/catalog/items?' . http_build_query(['order' => 'newest_first', 'page' => 1, 'per_page' => 20]);
    $http->respondTo($searchUrl, 200, '');

    $transport = new VintedBrowserSessionTransport('access_token_web=abc123', $http);

    assertThrows(function () use ($transport) {
        $transport->searchPage([], 1, 20);
    }, 'Une réponse vide doit lever une exception explicite');
});

exit($runner->report());
