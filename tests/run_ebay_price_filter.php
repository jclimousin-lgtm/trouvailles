<?php

declare(strict_types=1);

/**
 * TRV-006/TRV-008-bugfix — tests de EbayPriceFilter, hors base de données
 * (calcul pur). Usage : php tests/run_ebay_price_filter.php
 */

const ROOT = __DIR__ . '/..';

require __DIR__ . '/assertions.php';
require __DIR__ . '/TestRunner.php';
require ROOT . '/app/Core/autoload.php';

use Trouvailles\Sources\Ebay\EbayPriceFilter;

$runner = new TestRunner();

$runner->run('min seul -> borne ouverte à droite, priceCurrency accolé', function () {
    assertEquals('price:[10..],priceCurrency:EUR', EbayPriceFilter::build(10.0, null, 'EBAY_FR'), 'min seul doit produire price:[10..],priceCurrency:EUR');
});

$runner->run('max seul -> borne ouverte à gauche, priceCurrency accolé', function () {
    assertEquals('price:[..500],priceCurrency:EUR', EbayPriceFilter::build(null, 500.0, 'EBAY_FR'), 'max seul doit produire price:[..500],priceCurrency:EUR');
});

$runner->run('min et max -> intervalle fermé, priceCurrency accolé', function () {
    assertEquals('price:[10..500],priceCurrency:EUR', EbayPriceFilter::build(10.0, 500.0, 'EBAY_FR'), 'les deux bornes doivent produire price:[10..500],priceCurrency:EUR');
});

$runner->run('aucune borne -> null, jamais de filtre fabriqué', function () {
    assertEquals(null, EbayPriceFilter::build(null, null, 'EBAY_FR'), 'sans borne, aucun filtre ne doit être inventé');
});

$runner->run('valeurs décimales -> pas de zéros superflus', function () {
    assertEquals('price:[19.9..],priceCurrency:EUR', EbayPriceFilter::build(19.9, null, 'EBAY_FR'), '19.9 ne doit pas devenir 19.90');
    assertEquals('price:[19.95..],priceCurrency:EUR', EbayPriceFilter::build(19.95, null, 'EBAY_FR'), '19.95 doit être conservé tel quel');
    assertEquals('price:[20..],priceCurrency:EUR', EbayPriceFilter::build(20.0, null, 'EBAY_FR'), '20.0 doit devenir 20, sans décimale superflue');
});

$runner->run('marketplace_id détermine la devise (EBAY_US -> USD)', function () {
    assertEquals('price:[10..],priceCurrency:USD', EbayPriceFilter::build(10.0, null, 'EBAY_US'), 'EBAY_US doit produire priceCurrency:USD');
});

$runner->run('marketplace_id non mappé -> repli sur EUR, jamais une devise inventée au hasard', function () {
    assertEquals('price:[10..],priceCurrency:EUR', EbayPriceFilter::build(10.0, null, 'EBAY_DE'), 'un marketplace non mappé doit retomber sur EUR (cohérent avec le reste du projet)');
});

exit($runner->report());
