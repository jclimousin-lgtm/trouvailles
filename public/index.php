<?php

declare(strict_types=1);

/**
 * Point d'entrée HTTP unique du squelette initial. Aucune logique métier —
 * page d'accueil confirmant la connexion PDO/MariaDB, même socle que
 * juridico à son état initial (JUR-ARCH-012).
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
<title>Trouvailles</title>
</head>
<body>
<h1>Trouvailles</h1>
<p>Squelette initial — aucune logique métier.</p>
<p>Base de données : <?= $dbConnectee ? 'connectée' : 'NON connectée' ?></p>
</body>
</html>
