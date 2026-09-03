# TRV-001-C — Implémentation SQL du modèle de données Trouvailles

## Périmètre

Schéma SQL uniquement (10 entités du modèle cible + `schema_migrations`),
migrations, seed des 3 sources initiales, tests d'intégrité. Aucune logique
de scraping, matching, valorisation, liquidité ou notification.

## Existant inspecté avant modification

Dépôt créé lors d'une mission précédente (squelette LAMP) : `app/Core/
{Database,Env,autoload}.php`, `config/database.php`, `public/index.php`.
Aucune migration, aucun modèle, aucune table métier n'existait. Convention
de migration/tests reprise à l'identique de `juridico` (même compte
o2switch, même stack) : `database/migrations/*.sql` horodatées, suivi via
`schema_migrations` (colonnes migration/checksum/kind/status), lanceur
`tools/migrate.php`, harness `tests/{TestRunner,assertions,run}.php.
Conventions de typage reprises : `INT UNSIGNED AUTO_INCREMENT`, `TIMESTAMP
... ON UPDATE CURRENT_TIMESTAMP`, `TINYINT(1)` pour les booléens, `DECIMAL`
pour les scores normalisés (précision `(8,6)`, alignée sur `score_d5` déjà
présent dans juridico), `ENUM` pour les vocabulaires fermés explicitement
énumérés par le modèle.

## Décisions et adaptations minimales (écarts avec le modèle littéral)

- **Noms de table au pluriel** (`sources`, `listings`, `price_observations`,
  etc.) — convention déjà en vigueur (`sources`, `documents` dans juridico),
  les noms singuliers du modèle cible sont les entités conceptuelles.
- **`listing_products` sans colonne `id`** : le modèle cible ne liste pas de
  champ `id` pour cette table (contrairement à toutes les autres) — clé
  primaire composite `(listing_id, product_id)`.
- **FK en `ON DELETE RESTRICT`** partout sauf `product_attributes` (en
  `CASCADE`) : le §14 du modèle impose de ne jamais perdre l'historique ;
  seuls les attributs produit n'ont aucune valeur de traçabilité indépendante
  de leur produit.
- **`price_observations.product_id` nullable** : le matching listing→produit
  (`listing_products`) peut ne pas encore exister au moment de la capture
  d'une observation de prix (asynchrone, hors périmètre) — non explicitement
  tranché par le modèle, nécessaire à la cohérence référentielle.
- **`liquidity_observations.currency` ajoutée** (absente de la liste minimale
  du modèle) : `median_price` est un montant, et §15 impose explicitement de
  toujours conserver la devise.
- **Contraintes `CHECK`** ajoutées sur les scores normalisés (bornes [0,1] :
  `match_confidence`, `confidence_score`, `similarity_score`,
  `evidence_confidence`, `liquidity_score`) et sur `market_valuations`
  (`value_low <= value_central <= value_high`) : garde-fous d'intégrité
  minimaux, non présents dans le reste du dépôt (jamais utilisés jusqu'ici
  dans juridico) mais supportés par MariaDB 10.11 (en place sur ce serveur)
  et directement justifiés par les propres définitions du modèle.
- **`seller_type`, `status` (opportunity), `condition`, `confidence_label`**
  en `VARCHAR` libre, jamais en `ENUM` : le modèle ne fournit aucune liste de
  valeurs fermée pour ces champs (contrairement à `listings.status`,
  `price_type`, `evidence_type`, `match_method`, `valuation_status`,
  `acceptance_status`, qui sont bien en `ENUM`).
- **`gtin`/`mpn`** indexés mais **non uniques** : le modèle demande
  explicitement l'indexation, jamais l'unicité.
- **Pas de table utilisateur créée** : aucun modèle utilisateur n'existe dans
  Trouvailles à ce jour ; `min_discount` est stocké comme valeur figée
  (snapshot) sur `opportunities` uniquement, sans FK vers une table de
  préférences — conforme à « à créer uniquement si nécessaire » (§2) et à
  l'exclusion explicite des comptes utilisateurs (§20).
- **2 assertions ajoutées** à `tests/assertions.php` (`assertNull`,
  `assertThrows`) — nécessaires pour tester la nullabilité et le rejet par
  contrainte, dans le style des 3 déjà présentes, aucun nouvel outil.

## Vérifications exécutées

- `php tools/migrate.php` sur base locale vierge : 12 migrations appliquées
  sans erreur.
- `php tools/migrate.php` rejoué : toutes signalées « déjà appliquée »,
  aucune ré-exécution.
- `php tests/run.php` : 23/23 tests passés, deux fois de suite (répétabilité
  vérifiée). Les tests de contraintes s'exécutent dans une transaction
  annulée (`ROLLBACK`) en fin de suite — vérifié par comptage direct
  (`SELECT COUNT(*)`) : aucune donnée de test ne persiste, seules les 3
  sources seedées restent en base après exécution.
- `git diff --cached --check` : aucun problème d'espaces/fin de ligne.

## Non fait (hors périmètre, volontairement)

Déploiement des migrations en production (`nare8592_trouvailles`) — non
demandé par cette mission, et pas d'accès MySQL distant configuré depuis
cette machine (host `localhost` côté serveur). À faire via un futur script
de déploiement dédié le moment venu, hors TRV-001-C.
