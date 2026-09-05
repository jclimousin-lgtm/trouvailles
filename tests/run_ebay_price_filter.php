<?php

declare(strict_types=1);

/**
 * TRV-006 — tests de EbayPriceFilter, hors base de données (calcul pur).
 * Usage : php tests/run_ebay_price_filter.php
 */

const ROOT = __DIR__ . '/..';

require __DIR__ . '/assertions.php';
require __DIR__ . '/TestRunner.php';
require ROOT . '/app/Core/autoload.php';

use Trouvailles\Sources\Ebay\EbayPriceFilter;

$runner = new TestRunner();

$runner->run('min seul -> borne ouverte à droite', function () {
    assertEquals('price:[10..]', EbayPriceFilter::build(10.0, null), 'min seul doit produire price:[10..]');
});

$runner->run('max seul -> borne ouverte à gauche', function () {
    assertEquals('price:[..500]', EbayPriceFilter::build(null, 500.0), 'max seul doit produire price:[..500]');
});

$runner->run('min et max -> intervalle fermé', function () {
    assertEquals('price:[10..500]', EbayPriceFilter::build(10.0, 500.0), 'les deux bornes doivent produire price:[10..500]');
});

$runner->run('aucune borne -> null, jamais de filtre fabriqué', function () {
    assertEquals(null, EbayPriceFilter::build(null, null), 'sans borne, aucun filtre ne doit être inventé');
});

$runner->run('valeurs décimales -> pas de zéros superflus', function () {
    assertEquals('price:[19.9..]', EbayPriceFilter::build(19.9, null), '19.9 ne doit pas devenir 19.90');
    assertEquals('price:[19.95..]', EbayPriceFilter::build(19.95, null), '19.95 doit être conservé tel quel');
    assertEquals('price:[20..]', EbayPriceFilter::build(20.0, null), '20.0 doit devenir 20, sans décimale superflue');
});

exit($runner->report());
