<?php

declare(strict_types=1);

/**
 * Point d'entrée HTTP unique du squelette initial, désormais habillé de la
 * charte graphique V1 (AV-UI-001 — pack `docs/brand-v1/`, CSS intact dans
 * public/css/trouvailles.css). Toujours aucune logique métier propre : la
 * seule donnée réelle affichée est le statut de connexion à la base,
 * inchangé depuis le squelette initial.
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

use Trouvailles\Core\Database;

$dbConnectee = false;
$dbErreur = null;

try {
    Database::connection();
    $dbConnectee = true;
} catch (\Throwable $e) {
    $dbErreur = $e->getMessage();
    error_log('[trouvailles][db] ' . $dbErreur);
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
      <h1 class="tv-display">Trouvailles</h1>
      <p class="tv-hero__tagline">Ce qui vaut vraiment le coup.</p>
      <p class="tv-hero__pipeline">
        Annonce → Prix → Valeur estimée → Décote → Preuves → Confiance → Action.
        Aucune logique métier n'est encore branchée à cette page — le squelette
        applicatif (sources, persistance) est décrit dans <code>docs/TRV-001-C.md</code>
        et <code>docs/TRV-002.md</code>.
      </p>
      <p class="tv-status">
        <?php if ($dbConnectee): ?>
          <span class="tv-badge tv-badge--high">
            <img class="tv-icon" src="/assets/icons/shield.svg" alt="">
            Base de données connectée
          </span>
        <?php else: ?>
          <span class="tv-badge tv-badge--insufficient">
            <img class="tv-icon" src="/assets/icons/shield.svg" alt="">
            Base de données non connectée
          </span>
        <?php endif; ?>
      </p>
    </div>
    <img class="tv-hero__illustration" src="/assets/illustrations/chercheur-editorial.svg" alt="">
  </section>

  <h2 class="tv-section-title">Aperçu des composants</h2>
  <p class="tv-example-label">
    Exemple d'intégration de la charte — aucune donnée réelle, à connecter aux
    annonces/valorisations réelles dans une mission produit ultérieure.
  </p>

  <div class="tv-preview-row">
    <button class="tv-button" type="button">Voir l'annonce <img class="tv-icon" src="/assets/icons/arrow.svg" alt=""></button>
    <button class="tv-button tv-button--secondary" type="button">En savoir plus</button>
    <span class="tv-badge tv-badge--high"><img class="tv-icon" src="/assets/icons/confidence.svg" alt="">Confiance élevée</span>
    <span class="tv-badge tv-badge--medium"><img class="tv-icon" src="/assets/icons/confidence.svg" alt="">Confiance moyenne</span>
    <span class="tv-badge tv-badge--insufficient"><img class="tv-icon" src="/assets/icons/confidence.svg" alt="">Données insuffisantes</span>
  </div>

  <div class="tv-card tv-opportunity">
    <img class="tv-opportunity__image" src="/assets/patterns/dots.svg" alt="">
    <div class="tv-opportunity__body">
      <span class="tv-badge tv-badge--medium">
        <img class="tv-icon" src="/assets/icons/confidence.svg" alt="">Confiance moyenne
      </span>
      <div class="tv-opportunity__metrics">
        <div class="tv-opportunity__metric">
          <div class="tv-opportunity__label">Prix demandé</div>
          <div class="tv-price">129 €</div>
        </div>
        <div class="tv-opportunity__metric">
          <div class="tv-opportunity__label">Valeur estimée</div>
          <div class="tv-market-value">189 €</div>
        </div>
        <div class="tv-opportunity__metric">
          <div class="tv-opportunity__label">Décote</div>
          <div class="tv-discount">
            <img class="tv-icon" src="/assets/icons/tag-percent.svg" alt="">−32&nbsp;%
          </div>
        </div>
      </div>
      <p class="tv-opportunity__source">
        <img class="tv-icon" src="/assets/icons/pin.svg" alt="">
        Exemple ·
        <img class="tv-icon" src="/assets/icons/clock.svg" alt="">
        il y a 2&nbsp;h
      </p>
      <button class="tv-button" type="button">Voir l'annonce <img class="tv-icon" src="/assets/icons/external.svg" alt=""></button>
    </div>
  </div>

</main>

<nav class="tv-mobile-nav" aria-label="Navigation mobile">
  <a href="/" aria-current="page">Accueil</a>
  <a href="#">Chasses</a>
  <a href="#">Trouvailles</a>
  <a href="#">Réglages</a>
</nav>

</body>
</html>
