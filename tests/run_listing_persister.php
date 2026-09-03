<?php

declare(strict_types=1);

/**
 * TRV-002 §16 "Commun" — tests de ListingPersister indépendants de toute
 * marketplace : création, déduplication, nouvelle observation, changement
 * de prix, conservation de l'historique, gestion des champs absents,
 * erreurs. Touche la base locale, dans une transaction annulée en fin de
 * fichier (comme tests/run.php).
 * Usage : php tests/run_listing_persister.php
 */

const ROOT = __DIR__ . '/..';

require __DIR__ . '/assertions.php';
require __DIR__ . '/TestRunner.php';
require ROOT . '/app/Core/autoload.php';

use Trouvailles\Core\Database;
use Trouvailles\Persistence\ListingPersister;
use Trouvailles\Sources\NormalizedListing;

$runner = new TestRunner();

function makeListing(array $overrides = []): NormalizedListing
{
    $defaults = [
        'source' => 'leboncoin',
        'externalId' => 'EXT-PERSIST-1',
        'url' => 'https://example.test/1',
        'title' => 'Titre initial',
        'description' => null,
        'brand' => null,
        'category' => null,
        'condition' => null,
        'askingPrice' => 800.0,
        'askingCurrency' => 'EUR',
        'shippingPrice' => null,
        'location' => null,
        'sellerType' => null,
        'publishedAt' => null,
        'priceMechanism' => NormalizedListing::PRICE_MECHANISM_FIXED,
    ];
    $args = array_merge($defaults, $overrides);
    return new NormalizedListing(...$args);
}

$pdo = Database::connection();
$pdo->beginTransaction();

try {
    $runner->run('Création : une nouvelle annonce crée listing + price_observation', function () use ($pdo) {
        $persister = new ListingPersister($pdo);
        $result = $persister->persist(makeListing(['externalId' => 'EXT-CREATE']));

        assertTrue($result['created'], 'created doit être true à la première persistance');
        assertNotNull($result['price_observation_id'], 'une observation doit être créée (prix présent)');

        $stmt = $pdo->prepare('SELECT status, first_seen_at, last_seen_at FROM listings WHERE id = :id');
        $stmt->execute(['id' => $result['listing_id']]);
        $row = $stmt->fetch();
        assertEquals('active', $row['status'], 'une annonce que l\'on vient de récupérer avec succès est active par construction (jamais "sold"/"removed" déduit)');
        assertNotNull($row['first_seen_at'], 'first_seen_at doit être renseigné');
        assertNotNull($row['last_seen_at'], 'last_seen_at doit être renseigné');
    });

    $runner->run('Déduplication : la même (source, external_id) ne crée jamais une seconde listing', function () use ($pdo) {
        $persister = new ListingPersister($pdo);
        $first = $persister->persist(makeListing(['externalId' => 'EXT-DEDUP']));
        $second = $persister->persist(makeListing(['externalId' => 'EXT-DEDUP']));

        assertTrue($first['created'], 'premier appel : created=true');
        assertTrue(!$second['created'], 'second appel : created=false, mise à jour de la même ligne');
        assertEquals($first['listing_id'], $second['listing_id'], 'le listing_id doit être identique');

        $stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM listings WHERE source_id = (SELECT id FROM sources WHERE code = \'leboncoin\') AND external_id = :ext');
        $stmt->execute(['ext' => 'EXT-DEDUP']);
        assertEquals(1, (int) $stmt->fetch()['n'], 'une seule ligne listings doit exister pour ce couple');
    });

    $runner->run('Deux sources peuvent partager le même external_id sans être confondues', function () use ($pdo) {
        $persister = new ListingPersister($pdo);
        $lbc = $persister->persist(makeListing(['source' => 'leboncoin', 'externalId' => 'EXT-CROSS']));
        $ebay = $persister->persist(makeListing(['source' => 'ebay', 'externalId' => 'EXT-CROSS']));

        assertTrue($lbc['listing_id'] !== $ebay['listing_id'], 'des listings de sources différentes doivent rester distincts');
    });

    $runner->run('Nouvelle observation à chaque appel, même sans changement de prix', function () use ($pdo) {
        $persister = new ListingPersister($pdo);
        $listing = makeListing(['externalId' => 'EXT-REPEAT']);
        $persister->persist($listing);
        $persister->persist($listing); // même prix, doit quand même créer une 2e observation

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS n FROM price_observations po
             JOIN listings l ON l.id = po.listing_id
             WHERE l.external_id = :ext'
        );
        $stmt->execute(['ext' => 'EXT-REPEAT']);
        assertEquals(2, (int) $stmt->fetch()['n'], 'chaque récupération de prix doit être historisée, même identique (§9)');
    });

    $runner->run('Changement de prix : historique conservé, jamais écrasé (800 -> 750 -> 700)', function () use ($pdo) {
        $persister = new ListingPersister($pdo);
        $persister->persist(makeListing(['externalId' => 'EXT-HISTORY', 'askingPrice' => 800.0]));
        $persister->persist(makeListing(['externalId' => 'EXT-HISTORY', 'askingPrice' => 750.0]));
        $result = $persister->persist(makeListing(['externalId' => 'EXT-HISTORY', 'askingPrice' => 700.0]));

        $stmt = $pdo->prepare(
            'SELECT amount FROM price_observations po
             JOIN listings l ON l.id = po.listing_id
             WHERE l.external_id = :ext ORDER BY po.id'
        );
        $stmt->execute(['ext' => 'EXT-HISTORY']);
        $amounts = array_map(static fn ($r) => (float) $r['amount'], $stmt->fetchAll());
        assertEquals([800.0, 750.0, 700.0], $amounts, 'les trois observations doivent coexister, dans l\'ordre, aucune écrasée');

        $stmt = $pdo->prepare('SELECT asking_price FROM listings WHERE id = :id');
        $stmt->execute(['id' => $result['listing_id']]);
        assertEquals('700.00', $stmt->fetch()['asking_price'], 'listings.asking_price reflète le dernier prix observé (snapshot courant)');
    });

    $runner->run('Champs absents : acceptés à la création, jamais écrasés par un null ultérieur', function () use ($pdo) {
        $persister = new ListingPersister($pdo);
        $created = $persister->persist(makeListing(['externalId' => 'EXT-PARTIAL', 'title' => null, 'brand' => null]));

        $stmt = $pdo->prepare('SELECT title, brand FROM listings WHERE id = :id');
        $stmt->execute(['id' => $created['listing_id']]);
        $row = $stmt->fetch();
        assertNull($row['title'], 'title absent doit rester null, jamais inventé');
        assertNull($row['brand'], 'brand absent doit rester null, jamais inventé');

        // Une récupération ultérieure fournit enfin un titre.
        $persister->persist(makeListing(['externalId' => 'EXT-PARTIAL', 'title' => 'Titre découvert plus tard', 'brand' => null]));
        $stmt->execute(['id' => $created['listing_id']]);
        $row = $stmt->fetch();
        assertEquals('Titre découvert plus tard', $row['title'], 'un titre nouvellement fourni doit être enregistré');

        // Une récupération suivante sans titre (ex. page de résultats moins détaillée) ne doit pas l'effacer.
        $persister->persist(makeListing(['externalId' => 'EXT-PARTIAL', 'title' => null, 'brand' => null]));
        $stmt->execute(['id' => $created['listing_id']]);
        assertEquals('Titre découvert plus tard', $stmt->fetch()['title'], 'un titre déjà connu ne doit jamais être effacé par un null ultérieur');
    });

    $runner->run('Erreur : source inconnue rejetée explicitement (jamais silencieuse)', function () use ($pdo) {
        $persister = new ListingPersister($pdo);
        assertThrows(function () use ($persister) {
            $persister->persist(makeListing(['source' => 'source-inexistante', 'externalId' => 'EXT-UNKNOWN-SRC']));
        }, 'Une source non seedée doit lever une exception explicite');
    });

    $runner->run('Erreur : prix sans devise -> observation ignorée, listing quand même créée', function () use ($pdo) {
        $persister = new ListingPersister($pdo);
        $result = $persister->persist(makeListing(['externalId' => 'EXT-NO-CURRENCY', 'askingPrice' => 42.0, 'askingCurrency' => null]));

        assertTrue($result['created'], 'la listing doit tout de même être créée');
        assertNull($result['price_observation_id'], 'aucune observation ne doit être créée sans devise (donnée malformée, §15)');
    });

    $runner->run('Panne isolée : l\'échec d\'une annonce ne corrompt pas celles déjà persistées', function () use ($pdo) {
        $persister = new ListingPersister($pdo);
        $ok = $persister->persist(makeListing(['externalId' => 'EXT-BATCH-OK']));

        try {
            $persister->persist(makeListing(['source' => 'source-inexistante', 'externalId' => 'EXT-BATCH-FAIL']));
        } catch (\Throwable $e) {
            // attendu — l'échec de cette annonce ne doit pas affecter la précédente.
        }

        $stmt = $pdo->prepare('SELECT id FROM listings WHERE id = :id');
        $stmt->execute(['id' => $ok['listing_id']]);
        assertNotNull($stmt->fetch(), 'la listing persistée avant l\'échec doit toujours exister');
    });
} finally {
    $pdo->rollBack();
}

exit($runner->report());
