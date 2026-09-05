# TRV-006 — Recherche multi-critères eBay (« Chasses »)

## Contexte

Question posée : un utilisateur peut-il faire une recherche multi-critères
dans Trouvailles ? Réponse avant cette mission : non — aucune interface de
recherche n'existait, `public/index.php` n'affichait que l'écran passif
« Mes Trouvailles », et le lien de nav « Chasses » pointait vers `#`
(non branché, documenté explicitement dans `docs/AV-UI-001.md`).

Décision actée avec l'utilisateur : construire ce formulaire, **scopé à
eBay uniquement** — seule source réellement active aujourd'hui (Vinted et
Leboncoin restent différés, bloqués anti-bot côté serveur, voir
`docs/TRV-003-A-poc-lbc-collector.md` et `docs/TRV-005-poc-vinted-collector.md`).

## Ce qui a été construit

```
app/Sources/Ebay/EbayPriceFilter.php   construit la syntaxe price:[min..max] de l'API eBay
public/_nav.php                        navigation partagée (extraite d'index.php)
public/chasses.php                     formulaire + résultats
public/css/app.css                     nouveaux styles formulaire/résultat (ajoutés seulement)
tests/run_ebay_price_filter.php        5 tests
```

`public/index.php` modifié uniquement pour utiliser `_nav.php` (déplacement
de markup existant, aucun changement de comportement) — le lien « Chasses »
pointe désormais vers `/chasses.php` au lieu de `#`.

## Contrainte réelle découverte et respectée

L'API Browse d'eBay refuse toute recherche sans au moins un de `q`,
`category_ids`, `charity_ids`, `epid` ou `gtin` (erreur HTTP 400, vérifiée
en conditions réelles plus tôt dans le projet — pas supposée). Le filtre
de prix seul ne suffit jamais. **Le mot-clé est donc obligatoire** dans le
formulaire ; la fourchette de prix est un raffinement optionnel dessus.

## Décisions de conception

- **Pas de catégorie dans le formulaire v1** : aucune liste de catégories
  eBay n'est vérifiée dans ce dépôt — en inventer une aurait violé la
  contrainte « jamais de donnée fabriquée ».
- **Résultats bruts jamais stylés comme des opportunités** : nouvelle
  classe `.tv-result`, visuellement distincte de `.tv-opportunity`. Ce
  sont des annonces non évaluées (aucun moteur de valorisation appliqué),
  pas des « Trouvailles ».
- **Aucune persistance, aucun déclenchement du moteur de pricing** depuis
  cette page — recherche en lecture seule contre l'API eBay uniquement.
  Prochaine étape naturelle si souhaité, hors périmètre ici.
- **Validation serveur, pas seulement HTML5** : `required` sur le champ
  mot-clé est un confort déclaratif, mais la validation réelle (mot-clé
  vide, `prix_min > prix_max`) est refaite côté PHP avant tout appel API,
  avec message dédié — jamais une erreur 400 brute renvoyée à l'utilisateur.
- **`public/_nav.php`** : extraction justifiée par l'apparition d'une
  deuxième page — évite la duplication manuelle du markup de nav (desktop
  + mobile) entre `index.php` et `chasses.php`. Pas un système de layout
  général, juste ce bloc précis.

## Tests

```
$ php tests/run_ebay_price_filter.php
# 5/5 : min seul, max seul, les deux, aucun (-> null), décimales sans zéro superflu
```

Suite complète rejouée (105 tests au total, tous fichiers confondus) —
**aucune régression**.

## Vérification manuelle (serveur local)

- `/chasses.php` sans paramètres → formulaire seul, pas de recherche
  déclenchée.
- `/chasses.php?q=` → message de validation « Indiquez au moins un
  mot-clé. », aucun appel API.
- `/chasses.php?q=test&prix_min=100&prix_max=10` → message de validation
  dédié, aucun appel API.
- `/chasses.php?q=canon+eos&prix_min=10` → **appel réel à l'API eBay
  sandbox exécuté** (OAuth + recherche), réponse honnête « Aucun résultat
  pour cette recherche » — attendu, catalogue sandbox factice.
- Nav : `aria-current="page"` correctement positionné sur « Accueil »
  (`/`) et « Chasses » (`/chasses.php`) selon la page.

## Limite connue

Tant qu'eBay reste en configuration **sandbox**, toute recherche
retournera très probablement 0 résultat (catalogue factice, déjà constaté
lors du POC TRV-004) — ce n'est pas un bug de cette page, c'est honnête et
attendu. Passer en production nécessiterait de vrais identifiants eBay
(non obtenus à ce jour).

## Hors périmètre (explicite)

Catégorie, pagination, recherche sauvegardée/alerte, persistance des
résultats, déclenchement du moteur de pricing (`ProductMatcher`/
`ValuationEngine`/`OpportunityDetector`) depuis cette page.
