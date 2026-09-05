<?php

declare(strict_types=1);

/**
 * TRV-006 — page « Chasses » : formulaire de recherche multi-critères
 * (mot-clé + fourchette de prix) sur eBay, seule source active
 * aujourd'hui (Vinted/Leboncoin restent différés, bloqués anti-bot côté
 * serveur — voir docs/TRV-003-A-poc-lbc-collector.md et
 * docs/TRV-005-poc-vinted-collector.md).
 *
 * Résultats bruts, jamais présentés comme des « Trouvailles »/opportunités
 * (ce sont des annonces non évaluées — aucun moteur de valorisation
 * appliqué ici) : carte .tv-result dédiée, visuellement distincte de
 * .tv-opportunity. Aucune persistance, aucun déclenchement du moteur de
 * pricing depuis cette page (hors périmètre de cette mission).
 *
 * `q` est obligatoire : l'API Browse d'eBay renvoie une erreur 400 sans
 * au moins un de q/category_ids/charity_ids/epid/gtin (vérifié en
 * conditions réelles) — le filtre de prix seul ne suffit jamais.
 */

$racineOverride = __DIR__ . '/app-root.php';
$root = is_file($racineOverride) ? require $racineOverride : __DIR__ . '/..';
define('ROOT', $root);

require ROOT . '/app/Core/autoload.php';

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

$q = trim((string) ($_GET['q'] ?? ''));
$prixMin = lireFloatGet('prix_min');
$prixMax = lireFloatGet('prix_max');

$aRecherche = isset($_GET['q']);
$erreurValidation = null;
$erreurChargement = false;
$resultats = [];

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
      <div class="tv-field tv-field--prix">
        <label for="prix_min">Prix min</label>
        <input class="tv-input" type="number" id="prix_min" name="prix_min" min="0" step="0.01" value="<?= e($_GET['prix_min'] ?? '') ?>">
      </div>
      <div class="tv-field tv-field--prix">
        <label for="prix_max">Prix max</label>
        <input class="tv-input" type="number" id="prix_max" name="prix_max" min="0" step="0.01" value="<?= e($_GET['prix_max'] ?? '') ?>">
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
