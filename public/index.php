<?php

declare(strict_types=1);

/**
 * TRV-UI-002 — véritable écran d'accueil « Mes Trouvailles », habillé de
 * la charte V1 (AV-UI-001). Aucun moteur de valorisation, aucun second
 * système de confiance : les cartes affichent exclusivement des colonnes
 * déjà présentes dans le schéma TRV-001-C (opportunities.asking_price/
 * market_value/discount_percentage, market_valuations.valuation_status),
 * lues telles quelles par OpportunityRepository — jamais recalculées ici.
 *
 * Résolution de ROOT : par défaut, app/ est un dossier frère de public/
 * (disposition du dépôt en local). Si un fichier app-root.php existe à
 * côté de ce fichier (absent du dépôt Git, propre à un déploiement où
 * app/config sont placés hors du docroot), il prévaut et doit retourner
 * le chemin absolu vers ce dossier applicatif.
 */

$racineOverride = __DIR__ . '/app-root.php';
$root = is_file($racineOverride) ? require $racineOverride : __DIR__ . '/..';
define('ROOT', $root);

require ROOT . '/app/Core/autoload.php';

use Trouvailles\Persistence\OpportunityRepository;

/**
 * Mappe market_valuations.valuation_status (existant, TRV-001-C) vers les
 * trois états de confiance V1 — jamais un nouveau système de confiance,
 * simple présentation de la colonne existante (§4/§10 du mandat).
 *
 * @return array{label:string, class:string}
 */
function confianceAffichage(string $valuationStatus): array
{
    return match ($valuationStatus) {
        'valid' => ['label' => 'Confiance élevée', 'class' => 'tv-badge--high'],
        'thin_evidence' => ['label' => 'Confiance moyenne', 'class' => 'tv-badge--medium'],
        default => ['label' => 'Données insuffisantes', 'class' => 'tv-badge--insufficient'],
    };
}

/**
 * @param int $diffSecondes écart déjà calculé côté MySQL (TIMESTAMPDIFF,
 *     voir OpportunityRepository) — jamais recalculé depuis l'horloge PHP,
 *     qui peut être sur un fuseau différent de celui de MySQL.
 */
function fraicheur(int $diffSecondes): string
{
    if ($diffSecondes < 60) {
        return "à l'instant";
    }
    if ($diffSecondes < 3600) {
        return 'il y a ' . intdiv($diffSecondes, 60) . ' min';
    }
    if ($diffSecondes < 86400) {
        return 'il y a ' . intdiv($diffSecondes, 3600) . ' h';
    }

    return 'il y a ' . intdiv($diffSecondes, 86400) . ' j';
}

function e(?string $valeur): string
{
    return htmlspecialchars($valeur ?? '', ENT_QUOTES, 'UTF-8');
}

$opportunites = [];
$erreurChargement = false;

try {
    $opportunites = OpportunityRepository::default()->findRecent();
} catch (\Throwable $exception) {
    $erreurChargement = true;
    error_log('[trouvailles][opportunites] ' . $exception->getMessage());
}

?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Trouvailles — Ce qui vaut vraiment le coup.</title>
<link rel="icon" type="image/svg+xml" href="/assets/logo/trouvailles-symbol.svg">
<link rel="stylesheet" href="/css/trouvailles.css">
<link rel="stylesheet" href="/css/app.css">
</head>
<body>

<header class="tv-container">
  <nav class="tv-nav" aria-label="Navigation principale">
    <img src="/assets/logo/trouvailles-horizontal.svg" alt="Trouvailles" height="40">
    <div class="tv-nav__links">
      <a class="tv-nav__link" href="/" aria-current="page">Accueil</a>
      <a class="tv-nav__link" href="#">Chasses</a>
      <a class="tv-nav__link" href="#">Trouvailles</a>
      <a class="tv-nav__link" href="#">Réglages</a>
    </div>
  </nav>
</header>

<main class="tv-container">

  <section class="tv-hero">
    <div>
      <h1 class="tv-display">Quelles sont les bonnes affaires pour vous aujourd'hui&nbsp;?</h1>
      <p class="tv-hero__subtitle">
        Trouvailles détecte les annonces qui semblent réellement sous-évaluées
        et vous explique pourquoi.
      </p>
    </div>
    <img class="tv-hero__illustration" src="/assets/illustrations/chercheur-editorial.svg" alt="">
  </section>

  <section>
    <h2 class="tv-display tv-trouvailles__title">Mes Trouvailles</h2>

    <?php if ($erreurChargement): ?>

      <div class="tv-card tv-state">
        <p>Impossible de charger les Trouvailles pour le moment.</p>
      </div>

    <?php elseif ($opportunites === []): ?>

      <div class="tv-card tv-state">
        <p class="tv-state__title">Aucune Trouvaille pour le moment</p>
        <p>Nous n'avons pas encore détecté d'offre correspondant à vos critères.</p>
      </div>

    <?php else: ?>

      <div class="tv-grid tv-grid--opportunities">
        <?php foreach ($opportunites as $opportunite): ?>
          <?php $confiance = confianceAffichage($opportunite['valuation_status']); ?>
          <article class="tv-card tv-opportunity">
            <img class="tv-opportunity__image" src="/assets/patterns/dots.svg" alt="">
            <div class="tv-opportunity__body">
              <h3 class="tv-opportunity__title"><?= e($opportunite['title'] ?? 'Produit') ?></h3>

              <div class="tv-opportunity__metrics">
                <div class="tv-opportunity__metric">
                  <div class="tv-opportunity__label">Prix demandé</div>
                  <div class="tv-price"><?= number_format((float) $opportunite['asking_price'], 0, ',', ' ') ?>&nbsp;€</div>
                </div>
                <div class="tv-opportunity__metric">
                  <div class="tv-opportunity__label">Valeur estimée</div>
                  <div class="tv-market-value">≈&nbsp;<?= number_format((float) $opportunite['market_value'], 0, ',', ' ') ?>&nbsp;€</div>
                </div>
                <div class="tv-opportunity__metric">
                  <div class="tv-opportunity__label">Décote</div>
                  <div class="tv-discount">
                    <img class="tv-icon" src="/assets/icons/tag-percent.svg" alt="">
                    <?= round((float) $opportunite['discount_percentage']) ?>&nbsp;%
                  </div>
                </div>
              </div>

              <span class="tv-badge <?= $confiance['class'] ?>">
                <img class="tv-icon" src="/assets/icons/confidence.svg" alt="">
                <?= $confiance['label'] ?>
              </span>

              <p class="tv-opportunity__source">
                <?= e($opportunite['source_name']) ?> ·
                <img class="tv-icon" src="/assets/icons/clock.svg" alt="">
                détecté <?= fraicheur((int) $opportunite['secondes_ecoulees']) ?>
              </p>

              <a class="tv-button" href="<?= e($opportunite['url']) ?>" target="_blank" rel="noopener">
                Voir l'annonce
                <img class="tv-icon" src="/assets/icons/external.svg" alt="">
              </a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

    <?php endif; ?>
  </section>

</main>

<nav class="tv-mobile-nav" aria-label="Navigation mobile">
  <a href="/" aria-current="page">Accueil</a>
  <a href="#">Chasses</a>
  <a href="#">Trouvailles</a>
  <a href="#">Réglages</a>
</nav>

</body>
</html>
