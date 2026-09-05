<?php

declare(strict_types=1);

/**
 * TRV-006 — navigation partagée (desktop + mobile), extraite d'index.php
 * pour éviter la duplication maintenant qu'une deuxième page existe
 * (chasses.php). Pur déplacement de markup existant, variabilisé par
 * $pageActive ('accueil'|'chasses') défini par la page appelante avant
 * chaque require.
 *
 * Les deux blocs de nav vivent à des endroits différents du document
 * (header en haut, nav mobile fixe juste avant </body>) : ce fichier est
 * donc inclus DEUX FOIS par page, avec $navSection valant 'header' puis
 * 'mobile' juste avant chaque require. Aucune logique nouvelle au-delà de
 * cette variabilisation.
 */

if (!isset($pageActive)) {
    $pageActive = 'accueil';
}
if (!isset($navSection)) {
    $navSection = 'header';
}

$estAccueil = $pageActive === 'accueil';
$estChasses = $pageActive === 'chasses';

if ($navSection === 'header'):
?>
<header class="tv-container">
  <nav class="tv-nav" aria-label="Navigation principale">
    <img src="/assets/logo/trouvailles-horizontal.svg" alt="Trouvailles" height="40">
    <div class="tv-nav__links">
      <a class="tv-nav__link" href="/"<?= $estAccueil ? ' aria-current="page"' : '' ?>>Accueil</a>
      <a class="tv-nav__link" href="/chasses.php"<?= $estChasses ? ' aria-current="page"' : '' ?>>Chasses</a>
      <a class="tv-nav__link" href="#">Trouvailles</a>
      <a class="tv-nav__link" href="#">Réglages</a>
    </div>
  </nav>
</header>
<?php else: ?>
<nav class="tv-mobile-nav" aria-label="Navigation mobile">
  <a href="/"<?= $estAccueil ? ' aria-current="page"' : '' ?>>Accueil</a>
  <a href="/chasses.php"<?= $estChasses ? ' aria-current="page"' : '' ?>>Chasses</a>
  <a href="#">Trouvailles</a>
  <a href="#">Réglages</a>
</nav>
<?php endif; ?>
