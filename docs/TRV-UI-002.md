# TRV-UI-002 — Construction de l'accueil réel « Mes Trouvailles »

## A. Données utilisées

`OpportunityRepository::findRecent()` — une seule requête, jointe sur les
tables déjà existantes (TRV-001-C), aucun calcul ni recalcul :

- `opportunities` : `asking_price`, `market_value`, `discount_percentage`
  (colonnes déjà présentes, jamais recalculées ici — aucun moteur de
  valorisation introduit, conformément à §4).
- `listings` : `title`, `brand`, `url`.
- `sources` : `code`, `name`.
- `market_valuations.valuation_status` : mappé directement sur les trois
  états de confiance V1 (`valid` → Confiance élevée, `thin_evidence` →
  Confiance moyenne, `insufficient_evidence`/autre → Données insuffisantes)
  — réutilisation de la colonne existante, aucun second système de
  confiance créé.
- Fraîcheur : `TIMESTAMPDIFF(SECOND, detected_at, NOW())`, calculé côté
  MySQL (voir §E, bug corrigé).

**État réel constaté** (local et prod) : `opportunities`, `listings`,
`market_valuations` sont vides — aucune des tentatives de récupération
réelle (TRV-002) n'a encore pu écrire de donnée (Leboncoin/Vinted bloqués
par anti-bot, eBay sans identifiants). L'écran affiche donc aujourd'hui
l'état vide réel, et non des données fabriquées.

## B. Fichiers créés

- `app/Persistence/OpportunityRepository.php`
- `tests/run_opportunity_repository.php`
- `docs/TRV-UI-002.md` (ce rapport)

## C. Fichiers modifiés

- `public/index.php` — remplace l'ancien hero technique (tagline + pipeline
  + badge de statut DB) et la section « Aperçu des composants » d'AV-UI-001
  par le véritable écran : hero produit (§6), section « Mes Trouvailles »
  branchée sur les données réelles, états vide/erreur.
- `public/css/app.css` — nouvelles règles (`.tv-hero__subtitle`,
  `.tv-trouvailles__title`, `.tv-opportunity__title`, `.tv-state`), classes
  devenues inutiles supprimées (`.tv-hero__tagline`, `.tv-hero__pipeline`,
  `.tv-status`, `.tv-section-title`, `.tv-example-label`,
  `.tv-preview-row`). `css/trouvailles.css` (pack de charte) non modifié.

## D. Fonctionnalités

- Header + navigation (desktop et mobile basse) repris tels quels d'AV-UI-001.
- Hero produit : titre et sous-titre conformes au §6, sans KPI technique.
- Section « Mes Trouvailles » branchée sur `OpportunityRepository` :
  - **Données réelles présentes** → grille de cartes (`.tv-grid--opportunities`,
    3 colonnes desktop / 2 tablette / 1 mobile, déjà fournies par la charte) :
    produit, prix demandé, valeur estimée (`≈`), décote, badge de confiance,
    source + fraîcheur, bouton « Voir l'annonce » vers l'URL réelle
    (`target="_blank" rel="noopener"`).
  - **Aucune donnée** (état réel actuel) → état vide propre : « Aucune
    Trouvaille pour le moment / Nous n'avons pas encore détecté d'offre
    correspondant à vos critères. » Sans bouton « Créer une chasse » : cette
    fonctionnalité n'existe pas dans le code (§14 : ne pas la créer
    artificiellement).
  - **Échec de la requête** → « Impossible de charger les Trouvailles pour
    le moment. », jamais de message SQL brut (`try/catch` + `error_log`).
- Photo : aucune table du schéma actuel ne stocke d'URL d'image — un motif
  de la charte (`assets/patterns/dots.svg`) sert de placeholder graphique,
  jamais présenté comme une vraie photo (§12).
- Compteur « N nouvelles » (§7) : non affiché — aucune notion de
  lu/non-lu n'existe dans le schéma ou l'application (pas de compte
  utilisateur) ; l'afficher aurait nécessité de l'inventer.

## E. Limites

- **Aucune opportunité réelle à ce jour** : le moteur de valorisation et le
  pipeline d'ingestion réel restent hors périmètre (TRV-001-C/TRV-002) —
  l'écran est fonctionnellement complet mais n'a rien à afficher tant que
  ces briques ne produisent pas de lignes dans `opportunities`.
- **Pas de photo d'annonce** : absente du schéma de données actuel
  (`listings` n'a pas de colonne image) — placeholder graphique en attendant.
- **Bug réel trouvé et corrigé pendant cette mission** : le calcul initial
  de fraîcheur comparait `detected_at` (MySQL, fuseau `SYSTEM` du serveur,
  `Europe/Paris`/CEST) à l'horloge PHP (`UTC` par défaut) — une annonce
  vieille de 8 minutes s'affichait « à l'instant ». Corrigé en confiant le
  calcul d'écart à MySQL (`TIMESTAMPDIFF`), qui compare les deux valeurs
  dans le même moteur/fuseau — vérifié par insertion réelle de données
  (8 min / 2 h / 1 j), capture d'écran, puis suppression.
- **État « chargement »** : non implémenté au sens JS/asynchrone — la page
  est intégralement rendue côté serveur (PHP, sans JavaScript ajouté, §21),
  il n'existe donc pas de moment de chargement client distinct de la
  requête HTTP elle-même.

## F. Tests

```
PHP      : php -l public/index.php && php -l app/Persistence/OpportunityRepository.php → aucune erreur
Tests    : 56/56 (23 TRV-001-C + 30 TRV-002 + 3 TRV-UI-002) → tous au vert, aucune régression
Desktop  : 1280 px — vérifié (état vide + 3 cartes réelles temporaires, grille 3 colonnes)
Tablette : 900 px — vérifié (grille 2 colonnes)
Mobile   : 390 px — vérifié (1 carte par ligne, bouton pleine largeur, nav basse visible)
```

Vérification des données : trois opportunités réelles insérées
temporairement en base locale (couvrant les trois états de confiance et
trois échelles de fraîcheur), captures d'écran aux trois largeurs, données
supprimées immédiatement après. Aucune donnée de démonstration n'a été
laissée en base ni committée (§20).

## G. Commit

Voir `git log` — commit atomique unique pour cette mission, message
`feat(ui): build Trouvailles home experience`.
