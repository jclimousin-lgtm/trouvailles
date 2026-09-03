<?php

declare(strict_types=1);

/**
 * Suite de tests TRV-001-C — schéma SQL du modèle de données Trouvailles.
 * Usage : php tests/run.php
 *
 * Les tests d'intégrité/contraintes (bloc "Données") s'exécutent dans une
 * transaction PDO ouverte avant le premier et annulée (ROLLBACK) après le
 * dernier, quel que soit leur résultat — aucune donnée de test ne doit
 * persister dans la base (locale ou autre) après exécution. Les sources
 * `test_src_a`/`test_src_b` sont des codes réservés aux tests, distincts
 * des sources réelles seedées (leboncoin/ebay/vinted).
 */

const ROOT = __DIR__ . '/..';

require __DIR__ . '/assertions.php';
require __DIR__ . '/TestRunner.php';
require ROOT . '/app/Core/autoload.php';

use Trouvailles\Core\Database;

$runner = new TestRunner();

$runner->run('Démarrage de l\'environnement (config/database.php + .env)', function () {
    $config = require ROOT . '/config/database.php';

    assertEquals('mysql', $config['driver'], 'Le driver attendu est mysql');
    assertTrue($config['database'] !== '', 'Le nom de la base ne doit pas être vide');
    assertTrue($config['username'] !== '', 'L\'utilisateur ne doit pas être vide');
});

$runner->run('Connexion MariaDB (PDO)', function () {
    $pdo = Database::connection();
    assertTrue($pdo instanceof PDO, 'Database::connection() doit retourner un PDO');

    $stmt = $pdo->query('SELECT 1 AS ok');
    $row = $stmt->fetch();
    assertEquals(1, (int) $row['ok'], 'SELECT 1 doit retourner 1');
});

$runner->run('Exécution des migrations (tools/migrate.php)', function () {
    exec('php ' . escapeshellarg(ROOT . '/tools/migrate.php') . ' 2>&1', $sortie, $code);
    assertEquals(0, $code, "tools/migrate.php doit sortir en 0.\n" . implode("\n", $sortie));

    $pdo = Database::connection();
    $tables = [
        'schema_migrations', 'sources', 'products', 'listings',
        'product_attributes', 'listing_products', 'price_observations',
        'market_valuations', 'valuation_comparables',
        'liquidity_observations', 'opportunities',
    ];
    foreach ($tables as $table) {
        $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
        assertTrue($stmt->fetch() !== false, "La table {$table} doit exister après migration");
    }
});

$runner->run('Migration rejouée sur état existant (idempotence)', function () {
    exec('php ' . escapeshellarg(ROOT . '/tools/migrate.php') . ' 2>&1', $sortie, $code);
    assertEquals(0, $code, "Un second passage de tools/migrate.php doit rester en 0.\n" . implode("\n", $sortie));
    $texte = implode("\n", $sortie);
    assertTrue(
        str_contains($texte, 'déjà appliquée'),
        'Le second passage doit signaler les migrations déjà appliquées, jamais les rejouer'
    );
});

$runner->run('Seed initial : leboncoin/ebay/vinted présents et actifs', function () {
    $pdo = Database::connection();
    foreach (['leboncoin', 'ebay', 'vinted'] as $code) {
        $stmt = $pdo->prepare('SELECT active FROM sources WHERE code = :c');
        $stmt->execute(['c' => $code]);
        $row = $stmt->fetch();
        assertNotNull($row, "La source seedée '{$code}' doit exister");
        assertEquals(1, (int) $row['active'], "La source '{$code}' doit être active par défaut");
    }
});

// ---------------------------------------------------------------------
// Bloc "Données" : contraintes d'intégrité, unicité, historique.
// Transaction ouverte ici, annulée dans le finally plus bas — aucune
// donnée de test ne doit survivre à l'exécution de ce fichier.
// ---------------------------------------------------------------------

$pdo = Database::connection();
$pdo->beginTransaction();

try {
    $insertSource = function (string $code) use ($pdo): int {
        $stmt = $pdo->prepare(
            "INSERT INTO sources (code, name, type, active) VALUES (:code, :name, 'marketplace', 1)"
        );
        $stmt->execute(['code' => $code, 'name' => $code]);
        return (int) $pdo->lastInsertId();
    };

    $srcA = $insertSource('test_src_a');
    $srcB = $insertSource('test_src_b');

    $runner->run('Sources : code unique', function () use ($pdo) {
        assertThrows(function () use ($pdo) {
            $stmt = $pdo->prepare(
                "INSERT INTO sources (code, name, type, active) VALUES ('leboncoin', 'Doublon', 'marketplace', 1)"
            );
            $stmt->execute();
        }, 'Un second code source identique (leboncoin) doit être rejeté (contrainte unique)');
    });

    $insertListing = function (int $sourceId, string $externalId) use ($pdo): int {
        $stmt = $pdo->prepare(
            "INSERT INTO listings (source_id, external_id, url, status)
             VALUES (:source_id, :external_id, 'https://example.test/x', 'active')"
        );
        $stmt->execute(['source_id' => $sourceId, 'external_id' => $externalId]);
        return (int) $pdo->lastInsertId();
    };

    $listingA = $insertListing($srcA, 'EXT-1');

    $runner->run('Listings : (source_id, external_id) unique par source', function () use ($pdo, $srcA) {
        assertThrows(function () use ($pdo, $srcA) {
            $stmt = $pdo->prepare(
                "INSERT INTO listings (source_id, external_id, url, status)
                 VALUES (:source_id, 'EXT-1', 'https://example.test/dup', 'active')"
            );
            $stmt->execute(['source_id' => $srcA]);
        }, 'Un doublon (source_id, external_id) doit être rejeté (contrainte unique)');
    });

    $runner->run('Listings : deux sources peuvent partager le même external_id', function () use ($pdo, $srcB) {
        $stmt = $pdo->prepare(
            "INSERT INTO listings (source_id, external_id, url, status)
             VALUES (:source_id, 'EXT-1', 'https://example.test/y', 'active')"
        );
        $ok = $stmt->execute(['source_id' => $srcB]);
        assertTrue($ok, "L'insertion doit réussir : même external_id, source différente");
    });

    $runner->run('Listings : FK source_id invalide rejetée', function () use ($pdo) {
        assertThrows(function () use ($pdo) {
            $stmt = $pdo->prepare(
                "INSERT INTO listings (source_id, external_id, url, status)
                 VALUES (999999, 'EXT-FK', 'https://example.test/z', 'active')"
            );
            $stmt->execute();
        }, 'Une source_id inexistante doit être rejetée (contrainte de clé étrangère)');
    });

    $stmt = $pdo->prepare(
        "INSERT INTO products (brand, model, canonical_name) VALUES ('TestBrand', 'TestModel', 'Produit de test')"
    );
    $stmt->execute();
    $productId = (int) $pdo->lastInsertId();

    $runner->run('Products : gtin/mpn nullable acceptés', function () use ($pdo) {
        $stmt = $pdo->query("SELECT gtin, mpn FROM products ORDER BY id DESC LIMIT 1");
        $row = $stmt->fetch();
        assertNull($row['gtin'], 'gtin doit accepter NULL quand la source ne le fournit pas');
        assertNull($row['mpn'], 'mpn doit accepter NULL quand la source ne le fournit pas');
    });

    $runner->run('Product_attributes : extensible sans colonne dédiée', function () use ($pdo, $productId) {
        $stmt = $pdo->prepare(
            "INSERT INTO product_attributes (product_id, `key`, value, normalized_value)
             VALUES (:pid, 'color', 'Rouge', 'rouge')"
        );
        $stmt->execute(['pid' => $productId]);

        $stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM product_attributes WHERE product_id = :pid AND `key` = :k');
        $stmt->execute(['pid' => $productId, 'k' => 'color']);
        assertEquals(1, (int) $stmt->fetch()['n'], 'L\'attribut color doit être présent pour ce produit');
    });

    $runner->run('Listing_product : relation listing -> produit fonctionnelle', function () use ($pdo, $listingA, $productId) {
        $stmt = $pdo->prepare(
            "INSERT INTO listing_products (listing_id, product_id, match_method, match_confidence, is_variant_exact)
             VALUES (:lid, :pid, 'manual', 1.0, 1)"
        );
        $stmt->execute(['lid' => $listingA, 'pid' => $productId]);

        $stmt = $pdo->prepare('SELECT match_method FROM listing_products WHERE listing_id = :lid AND product_id = :pid');
        $stmt->execute(['lid' => $listingA, 'pid' => $productId]);
        assertEquals('manual', $stmt->fetch()['match_method'], 'La relation listing_product doit être lisible');
    });

    $runner->run('Listing_product : match_confidence hors [0,1] rejeté', function () use ($pdo, $srcA, $productId) {
        // Couple (listing_id, product_id) distinct de celui déjà utilisé ci-dessus
        // (PK composite) : nouvelle annonce dédiée à ce test.
        $stmt = $pdo->prepare(
            "INSERT INTO listings (source_id, external_id, url, status)
             VALUES (:sid, 'EXT-CONF', 'https://example.test/conf', 'active')"
        );
        $stmt->execute(['sid' => $srcA]);
        $listingConf = (int) $pdo->lastInsertId();

        assertThrows(function () use ($pdo, $listingConf, $productId) {
            $stmt = $pdo->prepare(
                "INSERT INTO listing_products (listing_id, product_id, match_method, match_confidence, is_variant_exact)
                 VALUES (:lid, :pid, 'manual', 1.5, 0)"
            );
            $stmt->execute(['lid' => $listingConf, 'pid' => $productId]);
        }, 'match_confidence = 1.5 doit être rejeté (contrainte CHECK [0,1])');
    });

    $insertPriceObs = function (int $listingId, int $sourceId, ?int $productId, float $amount, string $observedAt, string $evidenceType, string $priceType = 'asking') use ($pdo): int {
        $stmt = $pdo->prepare(
            "INSERT INTO price_observations
                (listing_id, source_id, product_id, amount, currency, price_type, observed_at, evidence_type)
             VALUES (:lid, :sid, :pid, :amount, 'EUR', :price_type, :observed_at, :evidence_type)"
        );
        $stmt->execute([
            'lid' => $listingId,
            'sid' => $sourceId,
            'pid' => $productId,
            'amount' => $amount,
            'price_type' => $priceType,
            'observed_at' => $observedAt,
            'evidence_type' => $evidenceType,
        ]);
        return (int) $pdo->lastInsertId();
    };

    $obs1 = $insertPriceObs($listingA, $srcA, $productId, 800.00, '2026-09-01 10:00:00', 'active_fixed_price');
    $obs2 = $insertPriceObs($listingA, $srcA, $productId, 750.00, '2026-09-03 10:00:00', 'active_fixed_price');

    $runner->run('Price_observation : plusieurs observations pour une même annonce, historique conservé', function () use ($pdo, $listingA) {
        $stmt = $pdo->prepare('SELECT amount FROM price_observations WHERE listing_id = :lid ORDER BY observed_at');
        $stmt->execute(['lid' => $listingA]);
        $montants = array_map(static fn ($r) => (float) $r['amount'], $stmt->fetchAll());
        assertEquals([800.0, 750.0], $montants, 'Les deux observations (800 puis 750) doivent coexister, aucune écrasée');
    });

    $obsLikely = $insertPriceObs($listingA, $srcA, $productId, 700.00, '2026-09-05 10:00:00', 'likely_sale', 'sold');
    $obsCompleted = $insertPriceObs($listingA, $srcA, $productId, 690.00, '2026-09-06 10:00:00', 'completed_sale', 'sold');

    $runner->run('Price_observation : likely_sale distinct de completed_sale', function () use ($pdo, $obsLikely, $obsCompleted) {
        $stmt = $pdo->prepare('SELECT evidence_type FROM price_observations WHERE id = :id');
        $stmt->execute(['id' => $obsLikely]);
        assertEquals('likely_sale', $stmt->fetch()['evidence_type'], 'La ligne likely_sale ne doit jamais être requalifiée automatiquement');

        $stmt->execute(['id' => $obsCompleted]);
        assertEquals('completed_sale', $stmt->fetch()['evidence_type'], 'La ligne completed_sale reste une ligne distincte');
    });

    $runner->run('Price_observation : FK listing_id invalide rejetée', function () use ($pdo, $srcA, $productId) {
        assertThrows(function () use ($pdo, $srcA, $productId) {
            $stmt = $pdo->prepare(
                "INSERT INTO price_observations
                    (listing_id, source_id, product_id, amount, currency, price_type, observed_at, evidence_type)
                 VALUES (999999, :sid, :pid, 100, 'EUR', 'asking', NOW(), 'unknown')"
            );
            $stmt->execute(['sid' => $srcA, 'pid' => $productId]);
        }, 'Une listing_id inexistante doit être rejetée (contrainte de clé étrangère)');
    });

    $insertValuation = function (int $productId, string $status) use ($pdo): int {
        $stmt = $pdo->prepare(
            "INSERT INTO market_valuations
                (product_id, method_version, value_low, value_central, value_high, currency, valuation_status)
             VALUES (:pid, 'trv-test-v1', 100, 150, 200, 'EUR', :status)"
        );
        $stmt->execute(['pid' => $productId, 'status' => $status]);
        return (int) $pdo->lastInsertId();
    };

    $valuationValid = $insertValuation($productId, 'valid');
    $valuationInsufficient = $insertValuation($productId, 'insufficient_evidence');

    $runner->run('Market_valuation : insufficient_evidence accepté', function () use ($pdo, $valuationInsufficient) {
        $stmt = $pdo->prepare('SELECT valuation_status FROM market_valuations WHERE id = :id');
        $stmt->execute(['id' => $valuationInsufficient]);
        assertEquals('insufficient_evidence', $stmt->fetch()['valuation_status'], 'Le statut insufficient_evidence doit être stocké tel quel');
    });

    $runner->run('Market_valuation : value_low/central/high tous conservés (jamais réduits à un prix unique)', function () use ($pdo, $valuationValid) {
        $stmt = $pdo->prepare('SELECT value_low, value_central, value_high FROM market_valuations WHERE id = :id');
        $stmt->execute(['id' => $valuationValid]);
        $row = $stmt->fetch();
        assertEquals('100.00', $row['value_low'], 'value_low doit être conservé');
        assertEquals('150.00', $row['value_central'], 'value_central doit être conservé');
        assertEquals('200.00', $row['value_high'], 'value_high doit être conservé');
    });

    $stmt = $pdo->prepare(
        "INSERT INTO valuation_comparables (valuation_id, price_observation_id, similarity_score, acceptance_status)
         VALUES (:vid, :poid, 0.9, 'accepted')"
    );
    $stmt->execute(['vid' => $valuationValid, 'poid' => $obs1]);

    $stmt = $pdo->prepare(
        "INSERT INTO valuation_comparables (valuation_id, price_observation_id, similarity_score, acceptance_status, rejection_reason)
         VALUES (:vid, :poid, 0.2, 'rejected', 'condition_divergente')"
    );
    $stmt->execute(['vid' => $valuationValid, 'poid' => $obs2]);

    $runner->run('Valuation_comparable : comparables acceptés et rejetés tous deux conservés', function () use ($pdo, $valuationValid) {
        $stmt = $pdo->prepare(
            "SELECT acceptance_status, rejection_reason FROM valuation_comparables WHERE valuation_id = :vid ORDER BY id"
        );
        $stmt->execute(['vid' => $valuationValid]);
        $rows = $stmt->fetchAll();
        assertEquals(2, count($rows), 'Une valorisation doit pouvoir avoir plusieurs comparables');
        assertEquals('accepted', $rows[0]['acceptance_status'], 'Le premier comparable doit rester accepted');
        assertEquals('rejected', $rows[1]['acceptance_status'], 'Le second comparable rejeté ne doit pas être supprimé');
        assertNotNull($rows[1]['rejection_reason'], 'Un comparable rejeté doit conserver sa raison');
    });

    $insertOpportunity = function (int $listingId, int $valuationId, float $minDiscount) use ($pdo): int {
        $stmt = $pdo->prepare(
            "INSERT INTO opportunities
                (listing_id, valuation_id, asking_price, market_value, discount_percentage, min_discount, status, detected_at)
             VALUES (:lid, :vid, 800, 150, 81.25, :min_discount, 'detected', NOW())"
        );
        $stmt->execute(['lid' => $listingId, 'vid' => $valuationId, 'min_discount' => $minDiscount]);
        return (int) $pdo->lastInsertId();
    };

    $oppA = $insertOpportunity($listingA, $valuationValid, 12.50);
    $oppB = $insertOpportunity($listingA, $valuationValid, 30.00);

    $runner->run('Opportunity : référence bien une annonce et une valorisation', function () use ($pdo, $oppA, $listingA, $valuationValid) {
        $stmt = $pdo->prepare('SELECT listing_id, valuation_id FROM opportunities WHERE id = :id');
        $stmt->execute(['id' => $oppA]);
        $row = $stmt->fetch();
        assertEquals($listingA, (int) $row['listing_id'], 'opportunity.listing_id doit pointer vers l\'annonce');
        assertEquals($valuationValid, (int) $row['valuation_id'], 'opportunity.valuation_id doit pointer vers la valorisation');
    });

    $runner->run('Opportunity : min_discount est une valeur de décision, jamais une constante 25', function () use ($pdo, $oppA, $oppB) {
        $stmt = $pdo->prepare('SELECT min_discount FROM opportunities WHERE id = :id');
        $stmt->execute(['id' => $oppA]);
        $mdA = (string) $stmt->fetch()['min_discount'];
        $stmt->execute(['id' => $oppB]);
        $mdB = (string) $stmt->fetch()['min_discount'];

        assertEquals('12.50', $mdA, 'La première opportunité doit conserver son seuil propre (12.50)');
        assertEquals('30.00', $mdB, 'La seconde opportunité doit conserver un seuil différent (30.00), prouvant l\'absence de constante figée');
    });

    $runner->run('Opportunity : FK valuation_id invalide rejetée', function () use ($pdo, $listingA) {
        assertThrows(function () use ($pdo, $listingA) {
            $stmt = $pdo->prepare(
                "INSERT INTO opportunities
                    (listing_id, valuation_id, asking_price, market_value, discount_percentage, min_discount, status, detected_at)
                 VALUES (:lid, 999999, 800, 150, 81.25, 25, 'detected', NOW())"
            );
            $stmt->execute(['lid' => $listingA]);
        }, 'Une valuation_id inexistante doit être rejetée (contrainte de clé étrangère)');
    });

    $runner->run('Liquidity_observation : observation stockée sans calcul de score', function () use ($pdo, $productId, $srcA) {
        $stmt = $pdo->prepare(
            "INSERT INTO liquidity_observations
                (product_id, source_id, observed_at, active_count, recent_sale_count, median_price, currency)
             VALUES (:pid, :sid, NOW(), 5, 2, 720.00, 'EUR')"
        );
        $stmt->execute(['pid' => $productId, 'sid' => $srcA]);

        $stmt = $pdo->prepare('SELECT active_count, recent_sale_count FROM liquidity_observations WHERE product_id = :pid');
        $stmt->execute(['pid' => $productId]);
        $row = $stmt->fetch();
        assertEquals(5, (int) $row['active_count'], 'active_count doit être conservé tel quel');
        assertEquals(2, (int) $row['recent_sale_count'], 'recent_sale_count doit être conservé tel quel');
    });
} finally {
    $pdo->rollBack();
}

exit($runner->report());
