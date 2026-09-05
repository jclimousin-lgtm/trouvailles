# TRV-005 — POC Collector Vinted local

## Contexte

Suite à TRV-003-A (collecteur LBC) et à la constatation que
`VintedClient`/`VintedBrowserSessionTransport` (appel direct à l'API
catalogue Vinted, TRV-002/TRV-002-B) sont bloqués par la protection
anti-bot (403, même avec une session anonyme fraîchement obtenue),
l'utilisateur a choisi de construire un collecteur navigateur pour Vinted,
sur le même principe que celui de Leboncoin, plutôt que de fournir un
cookie de session réel.

## Étape 1 — Vérification réelle préalable (avant d'écrire le code)

Avant d'écrire l'extracteur, un test réel a été effectué (navigateur
Chrome réel via Playwright, profil neuf, sans dissimulation
d'automatisation, aucune session/cookie fourni) sur
`https://www.vinted.fr/catalog?search_text=canon%20eos` :

- **La page se charge normalement**, sans CAPTCHA ni page de blocage
  (contrairement à Leboncoin, où même la page d'accueil était bloquée par
  DataDome dans les mêmes conditions — voir TRV-003-A-B). Le blocage
  Vinted ne concerne donc que l'appel direct à l'API catalogue
  (`/api/v2/catalog/items`), pas le chargement normal d'une page de
  résultats par un navigateur.
- Le HTML complet de la page a été sauvegardé localement pour analyse
  (`vinted_page.html`, ~7,8 Mo, 96 annonces réelles rendues côté serveur).
- Aucun `__NEXT_DATA__`, `__NUXT__` ou JSON-LD n'a été trouvé (grep
  exhaustif) — le frontend Vinted n'expose pas de bloc JSON structurel
  équivalent à celui de Leboncoin. L'extraction doit donc être
  intégralement DOM.

## Étape 2 — Structure DOM réelle identifiée

Chaque carte de résultat de la grille principale a un `data-testid` racine
`product-item-id-<id>` (distinct des `data-testid` `closet-item-*`/`item-*`
présents ailleurs sur la même page — carrousels "plus de cet utilisateur",
non liés à la grille de résultats principale, explicitement ignorés). 96
occurrences de `product-item-id-` trouvées sur la page testée, correspondant
exactement aux 96 annonces visibles.

Dans chaque carte :

| Donnée | Source DOM |
|---|---|
| identifiant | nombre capturé dans `data-testid="product-item-id-<id>"` |
| URL | `href` de `[data-testid$="--overlay-link"]` (`/items/<id>-<slug>?referrer=catalog`) |
| titre + marque + état + prix (concaténés) | attribut `title` de ce même lien, et `alt` de l'image (chaîne identique) : `"<Titre>, Marque: <Marque>, État: <État>, <Prix> €, <Prix avec frais> €"` |
| marque (isolée) | texte de `[data-testid$="--description-title"]` |
| état (isolé) | texte de `[data-testid$="--description-subtitle"]` |
| prix demandé (isolé) | texte de `[data-testid$="--price-text"]` |

Le titre complet n'existe nulle part comme nœud texte isolé : il est
extrait de la chaîne concaténée (`title`/`alt`) en coupant avant la
dernière occurrence de `", Marque:"` (ou `", État:"` à défaut) — jamais de
titre deviné si ni l'un ni l'autre motif n'est trouvé.

## Étape 3 — Composant livré

```
tools/vinted-local-collector/
├── manifest.json         MV3, matches "https://www.vinted.fr/catalog*"
├── content.js            détection page + extraction DOM + dédup + export + bouton
├── lib/normalize.js       logique pure de normalisation (partagée navigateur/tests)
├── tests/normalize.test.js  10 tests, node:test natif
└── README.md
```

Mapping vers `NormalizedListing` cohérent avec `VintedAdapter::mapItem()`
pour les champs qu'il connaît (`priceMechanism` toujours `'fixed'`,
identité minimale `id`+`url` obligatoire). Champs non visibles sur la
grille (`description`, `category`, `shippingPrice`, `location`,
`sellerType`, `publishedAt`) : toujours `null`, jamais inventés — même
limite documentée que pour le collecteur LBC.

## Étape 4 — Tests unitaires

```
$ node --test tools/vinted-local-collector/tests/normalize.test.js
# tests 10
# pass 10
# fail 0
```

Couverture : annonce complète, champs absents → `null`, parsing de prix
(virgule décimale, espace insécable, symbole € requis pour déduire `EUR`,
jamais de devise par défaut), identité minimale obligatoire, déduplication.

## Étape 5 — Validation de l'extraction contre du HTML réel (pas une fixture)

La logique d'extraction de `content.js` (sélection des cartes, parsing du
titre concaténé, lecture des nœuds isolés) a été rejouée **telle quelle**
dans un vrai moteur DOM (Chrome via Playwright) contre le fichier HTML
réel capturé à l'étape 1 (pas une fixture construite à la main) :

```
detected:   96
normalized: 96
rejetées:    0
```

Exemples réels obtenus (parmi les 96, aucune donnée fabriquée) :

```json
{
  "source": "vinted", "externalId": "9891707666",
  "url": "https://www.vinted.fr/items/9891707666-canon-eos1000d?referrer=catalog",
  "title": "Canon EOS1000d", "brand": "Canon", "condition": "Bon état",
  "askingPrice": 60, "askingCurrency": "EUR", "priceMechanism": "fixed"
}
```
```json
{
  "source": "vinted", "externalId": "9881901436",
  "url": "https://www.vinted.fr/items/9881901436-pack-canon-eos-10-complet-chargeur-3-batteries-bp-511-camera?referrer=catalog",
  "title": "Pack Canon Eos 10 Complet - Chargeur + 3 Batteries BP-511 + Caméra",
  "brand": "Canon", "condition": "Très bon état",
  "askingPrice": 85, "askingCurrency": "EUR", "priceMechanism": "fixed"
}
```

## Limites connues

- **Bouton/export en conditions de clic réel non testés** : je peux
  piloter un vrai Chrome pour vérifier qu'une page se charge et que la
  logique d'extraction fonctionne contre son HTML, mais je n'ai pas de
  moyen de charger une extension non empaquetée ni de simuler un vrai clic
  utilisateur dans cet environnement (même limite que pour LBC). La
  logique elle-même est validée ; son déclenchement via le bouton injecté
  reste à confirmer par un humain en conditions normales.
- **Marque/état "best-effort"** : `description-title`/`description-subtitle`
  affichent la marque et l'état sur toutes les cartes observées, mais rien
  ne garantit ce comportement pour un article sans marque connue (non
  vérifié faute d'exemple réel dans l'échantillon testé).
- **Champs indisponibles sur la grille** : description, catégorie, frais
  de port distincts, localisation, type de vendeur, date de publication —
  nécessiteraient de visiter chaque page de détail (hors périmètre).
- **Pas de pagination automatique** : une collecte = une page visible au
  moment du clic (identique à LBC).

## Conclusion

Le blocage Vinted ne touche que l'accès direct à l'API catalogue,
pas la navigation normale d'une page de résultats. Un collecteur navigateur
est donc une voie techniquement viable pour Vinted — plus robuste sur ce
point précis que pour Leboncoin, où même la page d'accueil est bloquée
pour un navigateur automatisé neuf. Reste à valider le déclenchement réel
du bouton par un humain avant de considérer le POC entièrement `PASS`
(même statut d'avancement que le collecteur LBC après sa première
implémentation, avant sa validation réelle en TRV-003-A-B).
