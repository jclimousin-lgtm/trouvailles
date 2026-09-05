<?php

declare(strict_types=1);

/**
 * TRV-004 — tests de TitleNormalizer, hors base de données (calcul pur).
 * Usage : php tests/run_title_normalizer.php
 */

const ROOT = __DIR__ . '/..';

require __DIR__ . '/assertions.php';
require __DIR__ . '/TestRunner.php';
require ROOT . '/app/Core/autoload.php';

use Trouvailles\Pricing\TitleNormalizer;

$runner = new TestRunner();

$runner->run('Jaccard élevé pour deux titres proches (mêmes tokens dominants, ordre différent)', function () {
    $a = TitleNormalizer::tokens('Canon EOS 90D DSLR Camera Body');
    $b = TitleNormalizer::tokens('Canon EOS 90D Body Only, DSLR Camera');

    $score = TitleNormalizer::jaccard($a, $b);
    assertTrue($score >= 0.7, "Jaccard attendu élevé, obtenu {$score}");
});

$runner->run('Jaccard proche de 0 pour deux titres sans rapport', function () {
    $a = TitleNormalizer::tokens('Canon EOS 90D DSLR Camera Body');
    $b = TitleNormalizer::tokens('Vintage Rolex Submariner watch parts only');

    $score = TitleNormalizer::jaccard($a, $b);
    assertTrue($score < 0.2, "Jaccard attendu faible, obtenu {$score}");
});

$runner->run('Mots vides filtrés (the, new, used, with...)', function () {
    $tokens = TitleNormalizer::tokens('The New Canon EOS 90D with Free Shipping');
    assertTrue(!in_array('the', $tokens, true), '"the" doit être filtré');
    assertTrue(!in_array('new', $tokens, true), '"new" doit être filtré');
    assertTrue(!in_array('with', $tokens, true), '"with" doit être filtré');
    assertTrue(in_array('canon', $tokens, true), '"canon" doit être conservé');
    assertTrue(in_array('90d', $tokens, true), '"90d" (token alphanumérique significatif) doit être conservé');
});

$runner->run('Accents repliés (table statique)', function () {
    $tokens = TitleNormalizer::tokens('Appareil Photo Numérique Réflex');
    assertTrue(in_array('numerique', $tokens, true), 'é doit être replié en e');
    assertTrue(in_array('reflex', $tokens, true), 'é doit être replié en e');
});

$runner->run('Titre vide/null -> tokens vides, jamais d\'erreur', function () {
    assertEquals([], TitleNormalizer::tokens(null), 'null -> []');
    assertEquals([], TitleNormalizer::tokens(''), 'chaîne vide -> []');
    assertEquals([], TitleNormalizer::tokens('   '), 'espaces seuls -> []');
});

$runner->run('Jaccard de deux ensembles vides -> 0.0, jamais 1.0 par défaut', function () {
    assertEquals(0.0, TitleNormalizer::jaccard([], []), 'deux ensembles vides ne doivent jamais ressembler à une correspondance parfaite');
    assertEquals(0.0, TitleNormalizer::jaccard(['canon'], []), 'un ensemble vide -> 0.0');
});

$runner->run('Tokens numériques courts (bruit) et lettres isolées filtrés', function () {
    $tokens = TitleNormalizer::tokens('Lot of 12 x screws lens a 90D');
    assertTrue(!in_array('12', $tokens, true), 'numérique pur court -> bruit filtré');
    assertTrue(!in_array('x', $tokens, true), 'lettre isolée -> bruit filtré');
    assertTrue(!in_array('a', $tokens, true), '"a" est un mot vide (et une lettre isolée) -> filtré');
    assertTrue(in_array('90d', $tokens, true), 'token alphanumérique significatif conservé même court');
});

exit($runner->report());
