<?php

declare(strict_types=1);

/**
 * TRV-004 — lanceur CLI du moteur de pricing : matching produit ->
 * valorisation -> détection d'opportunités, dans cet ordre, en une seule
 * exécution séquentielle.
 *
 * Usage :
 *   php tools/pricing_engine.php --min-discount=20
 *   php tools/pricing_engine.php --min-discount=20 --limit=200
 *
 * --min-discount est OBLIGATOIRE — voir opportunities.min_discount (§14 du
 * modèle : "aucun modèle utilisateur n'existe, min_discount n'est rattaché
 * à aucune préférence stockée") : jamais de valeur implicite, même ici.
 * --limit (optionnel, défaut 500) affecte uniquement la taille de lot du
 * matcher (ProductMatcher::matchPendingObservations), pas une règle métier.
 *
 * Usage exclusivement en CLI.
 */

const ROOT = __DIR__ . '/..';

require ROOT . '/app/Core/autoload.php';

use Trouvailles\Pricing\OpportunityDetector;
use Trouvailles\Pricing\ProductMatcher;
use Trouvailles\Pricing\ValuationEngine;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Ce script ne s'exécute qu'en ligne de commande (php-cli).\n";
    exit(1);
}

$minDiscount = null;
$limit = 500;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--min-discount=')) {
        $minDiscount = (float) substr($arg, strlen('--min-discount='));
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, strlen('--limit='));
    }
}

if ($minDiscount === null) {
    fwrite(STDERR, "Usage : php tools/pricing_engine.php --min-discount=<pourcentage> [--limit=<n>]\n");
    fwrite(STDERR, "--min-discount est obligatoire, aucune valeur par défaut n'est autorisée.\n");
    exit(1);
}

echo "1/3 — Matching produit (limite : {$limit})...\n";
$matcher = ProductMatcher::default();
$matchCounts = $matcher->matchPendingObservations($limit);
echo "  traités : {$matchCounts['processed']}, rattachés (existant) : {$matchCounts['matched_existing']}, "
    . "créés : {$matchCounts['created_new']}, sans signal : {$matchCounts['skipped_no_signal']}, "
    . "lien réutilisé : {$matchCounts['reused_listing_link']}\n";

echo "2/3 — Valorisation...\n";
$engine = ValuationEngine::default();
$valuationResults = $engine->valuateAllProducts();
$statusCounts = ['valid' => 0, 'thin_evidence' => 0, 'insufficient_evidence' => 0, 'aucune' => 0];
foreach ($valuationResults as $r) {
    $statusCounts[$r['status'] ?? 'aucune']++;
}
echo "  produits traités : " . count($valuationResults) . " — "
    . "valid : {$statusCounts['valid']}, thin_evidence : {$statusCounts['thin_evidence']}, "
    . "insufficient_evidence : {$statusCounts['insufficient_evidence']}, aucune ligne créée : {$statusCounts['aucune']}\n";

echo "3/3 — Détection d'opportunités (seuil : {$minDiscount}%)...\n";
$detector = OpportunityDetector::default();
$opportunityCounts = $detector->detect($minDiscount);
echo "  scannés : {$opportunityCounts['scanned']}, créés : {$opportunityCounts['created']}, "
    . "ignorés : {$opportunityCounts['skipped']}\n";

echo "\nTerminé sans erreur.\n";
exit(0);
