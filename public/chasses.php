<?php

declare(strict_types=1);

/**
 * TRV-006/TRV-008 — page « Chasses » : formulaire de recherche
 * multi-critères (mot-clé + fourchette de prix + marge minimale) sur
 * eBay, seule source active aujourd'hui (Vinted/Leboncoin restent
 * différés, bloqués anti-bot côté serveur — voir
 * docs/TRV-003-A-poc-lbc-collector.md et docs/TRV-005-poc-vinted-collector.md).
 *
 * Deux modes :
 *   - Sans marge : résultats bruts, jamais présentés comme des
 *     « Trouvailles »/opportunités (annonces non évaluées) — carte
 *     .tv-result, aucune écriture en base. Comportement TRV-006 inchangé.
 *   - Avec marge (TRV-008) : persiste les résultats (ListingPersister),
 *     les fait matcher/valoriser (ProductMatcher/ValuationEngine), puis
 *     n'affiche QUE celles dont la décote réelle (valorisation `valid`
 *     uniquement) atteint le seuil — cartes .tv-opportunity, vraies
 *     bonnes affaires vetted. OpportunityDetector::detect() est aussi
 *     appelé (même seuil) pour que ces opportunités remontent aussi sur
 *     l'écran d'accueil, cohérence avec le reste du système.
 *
 * `q` est obligatoire : l'API Browse d'eBay renvoie une erreur 400 sans
 * au moins un de q/category_ids/charity_ids/epid/gtin (vérifié en
 * conditions réelles) — le filtre de prix seul ne suffit jamais.
 */

$racineOverride = __DIR__ . '/app-root.php';
$root = is_file($racineOverride) ? require $racineOverride : __DIR__ . '/..';
define('ROOT', $root);

require ROOT . '/app/Core/autoload.php';

use Trouvailles\Core\Database;
use Trouvailles\Persistence\ListingPersister;
use Trouvailles\Pricing\OpportunityDetector;
use Trouvailles\Pricing\ProductMatcher;
use Trouvailles\Pricing\ValuationEngine;
use Trouvailles\Sources\Ebay\EbayAdapter;
use Trouvailles\Sources\Ebay\EbayPriceFilter;

function e(?string $valeur): string
{
    return htmlspecialchars($valeur ?? '', ENT_QUOTES, 'UTF-8');
}

function lireFloatGet(string $cle): ?float
{
    $valeur = trim((string) ($_GET[$cle] ?? ''));
    if ($valeur === '' || !is_numeric($valeur)) {
        return null;
    }

    return (float) $valeur;
}

/**
 * Mêmes trois états de confiance que public/index.php (dupliqué
 * localement, comme e() l'est déjà — aucune couche de vues partagée
 * dans ce projet).
 */
function confianceAffichage(string $valuationStatus): array
{
    return match ($valuationStatus) {
        'valid' => ['label' => 'Confiance élevée', 'class' => 'tv-badge--high'],
        'thin_evidence' => ['label' => 'Confiance moyenne', 'class' => 'tv-badge--medium'],
        default => ['label' => 'Données insuffisantes', 'class' => 'tv-badge--insufficient'],
    };
}

$q = trim((string) ($_GET['q'] ?? ''));
$prixMin = lireFloatGet('prix_min');
$prixMax = lireFloatGet('prix_max');
$margeMin = lireFloatGet('marge_min');
if ($margeMin !== null && $margeMin < 0) {
    $margeMin = null; // une marge négative n'a pas de sens, traitée comme absente
}

$aRecherche = isset($_GET['q']);
$erreurValidation = null;
$erreurChargement = false;
$resultats = [];
$opportunitesTrouvees = [];

if ($aRecherche) {
    if ($q === '') {
        $erreurValidation = 'Indiquez au moins un mot-clé.';
    } elseif ($prixMin !== null && $prixMax !== null && $prixMin > $prixMax) {
        $erreurValidation = 'Le prix minimum doit être inférieur au prix maximum.';
    } else {
        $criteria = ['q' => $q, 'limit' => 20];
        $filtre = EbayPriceFilter::build($prixMin, $prixMax);
        if ($filtre !== null) {
            $criteria['filter'] = $filtre;
        }

        try {
            $config = require ROOT . '/config/ebay.php';
            $resultats = (new EbayAdapter($config))->search($criteria);
        } catch (\Throwable $exception) {
            $erreurChargement = true;
            error_log('[trouvailles][chasses] ' . $exception->getMessage());
        }

        if (!$erreurChargement && $margeMin !== null && $resultats !== []) {
            try {
                $pdo = Database::connection();
                $persister = new ListingPersister($pdo);
                $listingIdsParExternalId = [];
                foreach ($resultats as $annonce) {
                    $resultatPersistance = $persister->persist($annonce);
                    $listingIdsParExternalId[$annonce->externalId] = $resultatPersistance['listing_id'];
                }

                (new ProductMatcher($pdo))->matchPendingObservations();
                (new ValuationEngine($pdo))->valuateAllProducts();

                $detector = new OpportunityDetector($pdo);
                $detector->detect($margeMin); // enregistre aussi pour l'écran d'accueil « Mes Trouvailles »
                $decotes = $detector->previewForListings(array_values($listingIdsParExternalId));

                foreach ($resultats as $annonce) {
                    $listingId = $listingIdsParExternalId[$annonce->externalId];
                    if (isset($decotes[$listingId]) && $decotes[$listingId]['discount_percentage'] >= $margeMin) {
                        $opportunitesTrouvees[] = ['annonce' => $annonce, 'decote' => $decotes[$listingId]];
                    }
                }
            } catch (\Throwable $exception) {
                $erreurChargement = true;
                error_log('[trouvailles][chasses][marge] ' . $exception->getMessage());
            }
        }
    }
}

?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Trouvailles — Chasses</title>
<link rel="icon" type="image/svg+xml" href="/assets/logo/trouvailles-symbol.svg">
<link rel="stylesheet" href="/css/trouvailles.css">
<link rel="stylesheet" href="/css/app.css">
</head>
<body>

<?php $pageActive = 'chasses'; $navSection = 'header'; require __DIR__ . '/_nav.php'; ?>

<main class="tv-container">

  <section>
    <h2 class="tv-display tv-trouvailles__title">Chasses</h2>
    <p class="tv-hero__subtitle" style="margin-bottom:24px;">
      Recherchez directement sur eBay par mot-clé et fourchette de prix.
      Ces résultats sont des annonces brutes, pas encore évaluées par
      Trouvailles.
    </p>

    <form class="tv-search-form" method="get" action="/chasses.php">
      <div class="tv-field" style="flex:1; min-width:220px;">
        <label for="q">Mot-clé</label>
        <input class="tv-input" type="text" id="q" name="q" required value="<?= e($q) ?>" placeholder="Ex. Canon EOS 90D">
      </div>
      <div class="tv-field tv-field--narrow">
        <label for="prix_min">Prix min</label>
        <input class="tv-input" type="number" id="prix_min" name="prix_min" min="0" step="0.01" value="<?= e($_GET['prix_min'] ?? '') ?>">
      </div>
      <div class="tv-field tv-field--narrow">
        <label for="prix_max">Prix max</label>
        <input class="tv-input" type="number" id="prix_max" name="prix_max" min="0" step="0.01" value="<?= e($_GET['prix_max'] ?? '') ?>">
      </div>
      <div class="tv-field tv-field--narrow">
        <label for="marge_min">Marge min. (%)</label>
        <input class="tv-input" type="number" id="marge_min" name="marge_min" min="0" step="1" value="<?= e($_GET['marge_min'] ?? '') ?>">
      </div>
      <button class="tv-button" type="submit">Rechercher</button>
    </form>

    <?php if ($erreurValidation !== null): ?>
      <p class="tv-validation-error"><?= e($erreurValidation) ?></p>
    <?php endif; ?>

    <?php if ($aRecherche && $erreurValidation === null): ?>

      <?php if ($erreurChargement): ?>

        <div class="tv-card tv-state">
          <p>Impossible d'interroger eBay pour le moment.</p>
        </div>

      <?php elseif ($resultats === []): ?>

        <div class="tv-card tv-state">
          <p class="tv-state__title">Aucun résultat pour cette recherche</p>
          <p>Essayez un autre mot-clé ou élargissez la fourchette de prix.</p>
        </div>

      <?php elseif ($margeMin !== null): ?>

        <p class="tv-hero__subtitle" style="margin-bottom:16px;">
          <?= count($resultats) ?> annonce(s) analysée(s), <?= count($opportunitesTrouvees) ?> correspond(ent) à au moins <?= e((string) $margeMin) ?>&nbsp;% de décote.
        </p>

        <?php if ($opportunitesTrouvees === []): ?>

          <div class="tv-card tv-state">
            <p class="tv-state__title">Aucune opportunité à ce seuil</p>
            <p>Aucune de ces annonces n'atteint cette marge avec une valorisation suffisamment fiable pour l'instant.</p>
          </div>

        <?php else: ?>

          <div class="tv-grid tv-grid--opportunities">
            <?php foreach ($opportunitesTrouvees as $item): ?>
              <?php $annonce = $item['annonce']; $decote = $item['decote']; $confiance = confianceAffichage('valid'); ?>
              <article class="tv-card tv-opportunity">
                <img class="tv-opportunity__image" src="/assets/patterns/dots.svg" alt="">
                <div class="tv-opportunity__body">
                  <h3 class="tv-opportunity__title"><?= e($annonce->title ?? 'Produit') ?></h3>

                  <div class="tv-opportunity__metrics">
                    <div class="tv-opportunity__metric">
                      <div class="tv-opportunity__label">Prix demandé</div>
                      <div class="tv-price"><?= number_format($annonce->askingPrice, 0, ',', ' ') ?>&nbsp;€</div>
                    </div>
                    <div class="tv-opportunity__metric">
                      <div class="tv-opportunity__label">Valeur estimée</div>
                      <div class="tv-market-value">≈&nbsp;<?= number_format($decote['market_value'], 0, ',', ' ') ?>&nbsp;€</div>
                    </div>
                    <div class="tv-opportunity__metric">
                      <div class="tv-opportunity__label">Décote</div>
                      <div class="tv-discount">
                        <img class="tv-icon" src="/assets/icons/tag-percent.svg" alt="">
                        <?= round($decote['discount_percentage']) ?>&nbsp;%
                      </div>
                    </div>
                  </div>

                  <span class="tv-badge <?= $confiance['class'] ?>">
                    <img class="tv-icon" src="/assets/icons/confidence.svg" alt="">
                    <?= $confiance['label'] ?>
                  </span>

                  <p class="tv-opportunity__source">eBay</p>

                  <a class="tv-button" href="<?= e($annonce->url) ?>" target="_blank" rel="noopener">
                    Voir l'annonce
                    <img class="tv-icon" src="/assets/icons/external.svg" alt="">
                  </a>
                </div>
              </article>
            <?php endforeach; ?>
          </div>

        <?php endif; ?>

      <?php else: ?>

        <div class="tv-grid tv-grid--opportunities">
          <?php foreach ($resultats as $annonce): ?>
            <article class="tv-card tv-result">
              <h3 class="tv-result__title"><?= e($annonce->title ?? 'Annonce sans titre') ?></h3>

              <?php if ($annonce->askingPrice !== null): ?>
                <div class="tv-result__price">
                  <?= number_format($annonce->askingPrice, 2, ',', ' ') ?>&nbsp;<?= e($annonce->askingCurrency) ?>
                </div>
              <?php endif; ?>

              <?php if ($annonce->condition !== null): ?>
                <p class="tv-result__meta"><?= e($annonce->condition) ?></p>
              <?php endif; ?>

              <a class="tv-button tv-button--secondary" href="<?= e($annonce->url) ?>" target="_blank" rel="noopener">
                Voir l'annonce
                <img class="tv-icon" src="/assets/icons/external.svg" alt="">
              </a>
            </article>
          <?php endforeach; ?>
        </div>

      <?php endif; ?>

    <?php endif; ?>
  </section>

</main>

<?php $navSection = 'mobile'; require __DIR__ . '/_nav.php'; ?>

</body>
</html>
